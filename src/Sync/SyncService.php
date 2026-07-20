<?php
/**
 * Provider sync orchestration.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Sync;

use MailPilot\Activity\ActivityLogger;
use MailPilot\Activity\Event;
use MailPilot\Providers\Contact;
use MailPilot\Providers\Contracts\Provider;
use MailPilot\Providers\ProviderConnectionRepository;
use MailPilot\Providers\SyncResult;
use MailPilot\Queue\Queue;
use MailPilot\Registry\Registry;
use MailPilot\Subscribers\RelationshipRepository;
use MailPilot\Subscribers\Subscriber;
use MailPilot\Subscribers\SubscriberRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hands subscribers to the background queue for provider sync — never inline
 * (ADR-004). Enforces status guards, the sync log, and queue retry on transient
 * failures.
 */
final class SyncService {

	/**
	 * Queue hook that runs a single contact sync.
	 */
	public const JOB_HOOK = 'mailpilot_sync_contact';

	public function __construct(
		private Queue $queue,
		private Registry $providers,
		private ProviderConnectionRepository $connections,
		private SubscriberRepository $subscribers,
		private RelationshipRepository $relationships,
		private SyncLog $log,
		private ActivityLogger $activity,
	) {}

	/**
	 * Register the dispatch and worker hooks.
	 */
	public function register_hooks(): void {
		add_action( 'mailpilot_dispatch_sync', [ $this, 'dispatch' ], 10, 2 );
		add_action( self::JOB_HOOK, [ $this, 'handle_job' ] );
	}

	/**
	 * Enqueue sync jobs for a subscriber against a set of connection ids.
	 *
	 * @param Subscriber      $subscriber Subscriber to sync.
	 * @param array<int, int> $targets    Provider connection ids.
	 */
	public function dispatch( Subscriber $subscriber, array $targets ): void {
		// Status guard: never sync Blocked/Unsubscribed/Bounced contacts.
		if ( ! $subscriber->status->is_syncable() ) {
			return;
		}

		foreach ( array_unique( array_map( 'intval', $targets ) ) as $connection_id ) {
			$this->queue->push(
				self::JOB_HOOK,
				[
					'subscriber_id' => (int) $subscriber->id,
					'connection_id' => $connection_id,
					'action'        => 'upsert',
				]
			);
		}
	}

	/**
	 * Resolve the connection ids a new signup should sync to: the explicitly
	 * chosen ones, plus every active connection when the global "Sync to all
	 * providers" setting is enabled.
	 *
	 * @param array<int, int|string> $explicit Explicitly selected connection ids.
	 * @return array<int, int>
	 */
	public function signup_targets( array $explicit ): array {
		$targets = array_map( 'intval', $explicit );

		if ( (bool) mailpilot()->settings()->get( 'sync_all_providers', false ) ) {
			foreach ( $this->connections->active() as $connection ) {
				$targets[] = (int) $connection->id;
			}
		}

		return array_values( array_unique( array_filter( $targets ) ) );
	}

	/**
	 * Worker: perform one contact sync.
	 *
	 * Throwing triggers the queue's retry/backoff; transient provider failures
	 * are rethrown, permanent ones are logged and swallowed.
	 *
	 * @param array<string, mixed> $payload Job payload.
	 *
	 * @throws \RuntimeException On transient failure, to request a retry.
	 */
	public function handle_job( array $payload ): void {
		$subscriber_id = (int) ( $payload['subscriber_id'] ?? 0 );
		$connection_id = (int) ( $payload['connection_id'] ?? 0 );
		$action        = (string) ( $payload['action'] ?? 'upsert' );

		$subscriber = $this->subscribers->find( $subscriber_id );
		$connection = $this->connections->find( $connection_id );

		if ( null === $subscriber || null === $connection || ! $connection->is_active() ) {
			return; // Nothing to do; not an error.
		}

		// Re-check the status guard at run time — state may have changed.
		if ( ! $subscriber->status->is_syncable() ) {
			return;
		}

		$provider = $this->providers->get( $connection->provider );
		if ( ! $provider instanceof Provider ) {
			$this->log->record( $subscriber_id, $connection->provider, $action, SyncResult::failure( 'Provider not registered.' ) );

			return;
		}

		// Free-tier monthly cap (ADR-006); Pro/Agency are unlimited. A capped
		// job is logged and dropped rather than retried — retrying can never
		// succeed until the period rolls over or the site upgrades, so
		// throwing here would just burn queue cycles.
		if ( ! mailpilot()->usage()->has_capacity() ) {
			$this->log->record(
				$subscriber_id,
				$connection->provider,
				$action,
				SyncResult::failure( __( 'Free plan monthly sync limit reached. Upgrade to Pro for unlimited syncs.', 'mailpilot' ) )
			);

			return;
		}

		$tags = $this->relationships->tags_for( $subscriber_id );

		// Apply the connection's default tags on top of the subscriber's own.
		$default_tags = $connection->setting( 'default_tags', [] );
		if ( is_array( $default_tags ) && $default_tags ) {
			$tags = array_values( array_unique( array_merge( $tags, array_map( 'strval', $default_tags ) ) ) );
		}

		$contact = Contact::fromSubscriber( $subscriber, $tags, $connection->lists() );

		$result = 'delete' === $action
			? $provider->delete_contact( $subscriber->email, $connection )
			: $provider->update_contact( $contact, $connection );

		// Count the attempt against the monthly quota regardless of outcome —
		// it was an operation against the provider either way.
		mailpilot()->usage()->increment();

		$this->log->record(
			$subscriber_id,
			$connection->provider,
			$action,
			$result,
			1,
			[ 'connection_id' => $connection_id ]
		);

		if ( $result->success ) {
			$this->activity->log(
				$subscriber_id,
				Event::ProviderSynced,
				/* translators: %s: provider label. */
				sprintf( __( 'Synced to %s', 'mailpilot' ), $provider->label() ),
				[ 'provider' => $connection->provider ]
			);

			return;
		}

		if ( $result->retryable ) {
			// Let the queue retry with backoff.
			throw new \RuntimeException( esc_html( 'Transient sync failure: ' . $result->message ) );
		}

		// Permanent failure: already logged; do not retry.
	}
}
