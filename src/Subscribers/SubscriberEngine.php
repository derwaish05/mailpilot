<?php
/**
 * Subscriber Engine — the central processing layer.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Subscribers;

use MailPilot\Activity\ActivityLogger;
use MailPilot\Activity\Event;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every audience event flows through this engine (ADR-001). It owns the
 * source-of-truth writes, status-transition rules, tag/list assignment,
 * activity logging, and the seam where routing + provider sync are invoked.
 *
 * No method here writes to a provider directly; sync is handed downstream.
 */
final class SubscriberEngine {

	public function __construct(
		private SubscriberRepository $repository,
		private RelationshipRepository $relationships,
		private ActivityLogger $activity,
	) {}

	/**
	 * Capture an audience event — the primary pipeline entry point.
	 *
	 * Idempotent upsert by email: an existing subscriber is updated, a new one
	 * is created. Tags/lists are applied, activity is logged, and the routing
	 * seam fires so downstream sync can run.
	 *
	 * @param array<string, mixed> $data    Subscriber fields (email required).
	 * @param array{tags?:array<int,string>,lists?:array<int,array{list_id:string,provider?:string}>,sync?:bool} $options Processing options.
	 * @return Subscriber
	 *
	 * @throws \InvalidArgumentException When email is missing or invalid.
	 */
	public function capture( array $data, array $options = [] ): Subscriber {
		$email = SubscriberRepository::normalize_email( (string) ( $data['email'] ?? '' ) );

		if ( ! is_email( $email ) ) {
			throw new \InvalidArgumentException( 'A valid email address is required.' );
		}

		$existing = $this->repository->find_by_email( $email );
		$is_new   = null === $existing;

		$subscriber = $existing ?? new Subscriber( email: $email );

		$this->fill( $subscriber, $data, $is_new );

		/**
		 * Fires before a subscriber is persisted.
		 *
		 * @param Subscriber $subscriber The subscriber about to be saved.
		 * @param bool       $is_new     Whether this is a new record.
		 */
		do_action( 'mailpilot_before_subscribe', $subscriber, $is_new );

		$subscriber = $is_new
			? $this->repository->insert( $subscriber )
			: $this->repository->update( $subscriber );

		$this->activity->log(
			(int) $subscriber->id,
			$is_new ? Event::SubscriberCreated : Event::SubscriberUpdated,
			$is_new ? __( 'Subscriber created', 'mailpilot' ) : __( 'Subscriber updated', 'mailpilot' ),
			[ 'source' => $subscriber->source->value ]
		);

		$this->apply_tags( $subscriber, $options['tags'] ?? [] );
		$this->apply_lists( $subscriber, $options['lists'] ?? [] );

		/**
		 * Fires after a subscriber is persisted and tags/lists applied.
		 *
		 * @param Subscriber $subscriber The saved subscriber.
		 * @param bool       $is_new     Whether this was a new record.
		 */
		do_action( 'mailpilot_after_subscribe', $subscriber, $is_new );

		$this->route( $subscriber, $options );

		return $subscriber;
	}

	/**
	 * Developer API: create (idempotent upsert by email).
	 *
	 * @param array<string, mixed> $data Subscriber fields.
	 */
	public function create( array $data ): Subscriber {
		return $this->capture( $data );
	}

	/**
	 * Developer API: update an existing subscriber by id or email.
	 *
	 * @param int|string           $id_or_email Subscriber id or email.
	 * @param array<string, mixed> $data        Fields to change.
	 *
	 * @throws \RuntimeException When the subscriber is not found.
	 */
	public function update( int|string $id_or_email, array $data ): Subscriber {
		$subscriber = is_int( $id_or_email )
			? $this->repository->find( $id_or_email )
			: $this->repository->find_by_email( (string) $id_or_email );

		if ( null === $subscriber ) {
			throw new \RuntimeException( 'Subscriber not found.' );
		}

		$data['email'] = $data['email'] ?? $subscriber->email;

		return $this->capture( $data );
	}

	/**
	 * Developer API: delete a subscriber and its relationship rows.
	 *
	 * @param int $id Subscriber id.
	 */
	public function delete( int $id ): bool {
		$this->relationships->delete_all_for( $id );

		return $this->repository->delete( $id );
	}

	/**
	 * Apply tags, logging each newly-added tag.
	 *
	 * @param Subscriber         $subscriber Target subscriber.
	 * @param array<int, string> $tags       Tag names.
	 */
	public function apply_tags( Subscriber $subscriber, array $tags ): void {
		foreach ( $tags as $tag ) {
			if ( $this->relationships->add_tag( (int) $subscriber->id, (string) $tag ) ) {
				$this->activity->log(
					(int) $subscriber->id,
					Event::TagAdded,
					/* translators: %s: tag name. */
					sprintf( __( 'Tag "%s" added', 'mailpilot' ), (string) $tag ),
					[ 'tag' => (string) $tag ]
				);
			}
		}
	}

	/**
	 * Remove tags, logging each removal.
	 *
	 * @param Subscriber         $subscriber Target subscriber.
	 * @param array<int, string> $tags       Tag names.
	 */
	public function remove_tags( Subscriber $subscriber, array $tags ): void {
		foreach ( $tags as $tag ) {
			if ( $this->relationships->remove_tag( (int) $subscriber->id, (string) $tag ) ) {
				$this->activity->log(
					(int) $subscriber->id,
					Event::TagRemoved,
					/* translators: %s: tag name. */
					sprintf( __( 'Tag "%s" removed', 'mailpilot' ), (string) $tag ),
					[ 'tag' => (string) $tag ]
				);
			}
		}
	}

	/**
	 * Apply list memberships.
	 *
	 * @param Subscriber                                                  $subscriber Target subscriber.
	 * @param array<int, array{list_id:string,provider?:string}|string>   $lists      List specs.
	 */
	public function apply_lists( Subscriber $subscriber, array $lists ): void {
		foreach ( $lists as $list ) {
			$list_id  = is_array( $list ) ? (string) ( $list['list_id'] ?? '' ) : (string) $list;
			$provider = is_array( $list ) ? ( $list['provider'] ?? null ) : null;

			$this->relationships->add_list( (int) $subscriber->id, $list_id, $provider );
		}
	}

	/**
	 * Populate an entity from input, enforcing status transitions.
	 *
	 * On update, fields absent from $data are left untouched. An invalid status
	 * transition is ignored (the current status is preserved) rather than fatal.
	 *
	 * @param Subscriber           $subscriber Entity to fill.
	 * @param array<string, mixed> $data       Input.
	 * @param bool                 $is_new     Whether the entity is new.
	 */
	private function fill( Subscriber $subscriber, array $data, bool $is_new ): void {
		$map = [
			'first_name' => 'first_name',
			'last_name'  => 'last_name',
			'phone'      => 'phone',
			'company'    => 'company',
			'country'    => 'country',
			'ip_address' => 'ip_address',
			'consent_at' => 'consent_at',
		];

		foreach ( $map as $key => $prop ) {
			if ( array_key_exists( $key, $data ) ) {
				$value               = $data[ $key ];
				$subscriber->$prop   = ( null === $value || '' === $value ) ? null : (string) $value;
			}
		}

		if ( isset( $data['country'] ) && is_string( $data['country'] ) ) {
			$subscriber->country = strtoupper( substr( trim( $data['country'] ), 0, 2 ) ) ?: null;
		}

		if ( array_key_exists( 'meta', $data ) && is_array( $data['meta'] ) ) {
			$subscriber->meta = array_merge( $subscriber->meta, $data['meta'] );
		}

		if ( $is_new && isset( $data['source'] ) ) {
			$subscriber->source = Source::fromString( (string) $data['source'] );
		}

		if ( isset( $data['status'] ) ) {
			$this->apply_status( $subscriber, Status::fromString( (string) $data['status'] ), $is_new );
		} elseif ( $is_new ) {
			// New subscribers default to Subscribed unless double opt-in is on.
			$default = $this->double_opt_in() ? Status::Pending : Status::Subscribed;
			$subscriber->status = $default;
		}
	}

	/**
	 * Apply a status change, enforcing allowed transitions.
	 *
	 * @param Subscriber $subscriber Entity.
	 * @param Status     $target     Desired status.
	 * @param bool       $is_new     Whether the entity is new.
	 */
	private function apply_status( Subscriber $subscriber, Status $target, bool $is_new ): void {
		if ( $is_new ) {
			$subscriber->status = $target;

			return;
		}

		$current = $subscriber->status;

		if ( $current === $target ) {
			return;
		}

		/**
		 * Filter whether a status transition is allowed.
		 *
		 * @param bool       $allowed    Whether the transition is permitted.
		 * @param Status     $current    Current status.
		 * @param Status     $target     Target status.
		 * @param Subscriber $subscriber Subscriber.
		 */
		$allowed = (bool) apply_filters(
			'mailpilot_allow_status_transition',
			$current->canTransitionTo( $target ),
			$current,
			$target,
			$subscriber
		);

		if ( ! $allowed ) {
			return; // Enforce: ignore invalid transition, keep current status.
		}

		$subscriber->status = $target;

		$this->activity->log(
			(int) $subscriber->id,
			Event::StatusChanged,
			/* translators: 1: from status, 2: to status. */
			sprintf( __( 'Status changed from %1$s to %2$s', 'mailpilot' ), $current->value, $target->value ),
			[
				'from' => $current->value,
				'to'   => $target->value,
			]
		);
	}

	/**
	 * Fire the routing seam and resolve sync targets.
	 *
	 * The free core has no rules engine; Pro hooks `mailpilot_route_subscriber`
	 * and contributes targets via `mailpilot_sync_targets`. Resolved targets are
	 * handed to the sync service (queued, never inline).
	 *
	 * @param Subscriber           $subscriber Saved subscriber.
	 * @param array<string, mixed> $options    Capture options.
	 */
	private function route( Subscriber $subscriber, array $options ): void {
		/**
		 * Fires after a subscriber is saved, before provider sync — the point
		 * where the Pro Rules Engine evaluates routing rules.
		 *
		 * @param Subscriber           $subscriber The subscriber.
		 * @param array<string, mixed> $options    Capture options.
		 */
		do_action( 'mailpilot_route_subscriber', $subscriber, $options );

		if ( empty( $options['sync'] ) ) {
			return;
		}

		/**
		 * Filter the provider connection ids a subscriber should sync to.
		 *
		 * @param array<int, int>      $targets    Provider connection ids.
		 * @param Subscriber           $subscriber The subscriber.
		 * @param array<string, mixed> $options    Capture options.
		 */
		$targets = (array) apply_filters( 'mailpilot_sync_targets', [], $subscriber, $options );

		if ( $targets ) {
			do_action( 'mailpilot_dispatch_sync', $subscriber, $targets );
		}
	}

	/**
	 * Whether double opt-in is enabled in settings.
	 */
	private function double_opt_in(): bool {
		return (bool) mailpilot()->settings()->get( 'double_opt_in', false );
	}
}
