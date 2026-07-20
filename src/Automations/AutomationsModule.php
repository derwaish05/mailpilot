<?php
/**
 * Automations runtime: fires outgoing webhooks and runs IF/THEN rules on
 * subscriber lifecycle events.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Automations;

use MailPilot\Plugin;
use MailPilot\Subscribers\Status;
use MailPilot\Subscribers\Subscriber;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens on `mailpilot_after_subscribe` (and form submissions) and, for the
 * matching event, dispatches configured outgoing webhooks and evaluates
 * automation rules, executing their action (tag / untag / sync / webhook).
 */
final class AutomationsModule {

	/**
	 * Re-entrancy guard so rule actions that touch the subscriber don't recurse.
	 */
	private bool $running = false;

	public function __construct(
		private Plugin $plugin,
		private AutomationsRepository $repository
	) {}

	/**
	 * Hook the lifecycle events.
	 */
	public function register_hooks(): void {
		add_action( 'mailpilot_after_subscribe', [ $this, 'on_after_subscribe' ], 20, 2 );
		add_action( 'mailpilot_form_submitted', [ $this, 'on_form_submitted' ], 20, 3 );
	}

	/**
	 * React to a create/update/unsubscribe.
	 *
	 * @param Subscriber $subscriber The subscriber.
	 * @param bool       $is_new     Whether it was just created.
	 */
	public function on_after_subscribe( Subscriber $subscriber, bool $is_new ): void {
		if ( $this->running ) {
			return;
		}

		if ( Status::Unsubscribed === $subscriber->status ) {
			$event   = 'subscriber_unsubscribed';
			$trigger = 'unsubscribed';
		} elseif ( $is_new ) {
			$event   = 'subscriber_created';
			$trigger = 'created';
		} else {
			$event   = 'subscriber_updated';
			$trigger = 'updated';
		}

		$this->dispatch_webhooks( $event, $subscriber );
		$this->run_automations( $trigger, $subscriber );
	}

	/**
	 * React to a form submission.
	 *
	 * @param mixed      $form       The form (unused).
	 * @param Subscriber $subscriber The subscriber.
	 * @param array      $data       Submission data (unused).
	 */
	public function on_form_submitted( $form, Subscriber $subscriber, array $data ): void {
		if ( $this->running ) {
			return;
		}
		$this->dispatch_webhooks( 'form_submitted', $subscriber );
		$this->run_automations( 'form', $subscriber );
	}

	/**
	 * Queue outgoing webhooks whose event matches.
	 *
	 * @param string     $event      Event key.
	 * @param Subscriber $subscriber Subscriber.
	 */
	private function dispatch_webhooks( string $event, Subscriber $subscriber ): void {
		foreach ( $this->repository->webhooks() as $webhook ) {
			if ( 'outgoing' !== ( $webhook['direction'] ?? '' ) || $event !== ( $webhook['event'] ?? '' ) ) {
				continue;
			}
			$this->fire_webhook( (string) $webhook['url'], $event, $subscriber );
		}
	}

	/**
	 * Queue a single webhook POST via the shared send-webhook job.
	 *
	 * @param string     $url        Target URL.
	 * @param string     $event      Event key.
	 * @param Subscriber $subscriber Subscriber.
	 */
	private function fire_webhook( string $url, string $event, Subscriber $subscriber ): void {
		if ( '' === $url ) {
			return;
		}
		$this->plugin->queue()->push(
			'mailpilot_send_webhook',
			[
				'url'     => $url,
				'payload' => [
					'event'      => $event,
					'subscriber' => $this->to_payload( $subscriber ),
				],
			]
		);
	}

	/**
	 * Evaluate and execute automation rules for a trigger.
	 *
	 * @param string     $trigger    Trigger key.
	 * @param Subscriber $subscriber Subscriber.
	 */
	private function run_automations( string $trigger, Subscriber $subscriber ): void {
		foreach ( $this->repository->automations() as $rule ) {
			if ( empty( $rule['active'] ) || $trigger !== ( $rule['trigger'] ?? '' ) ) {
				continue;
			}
			if ( ! $this->matches( $rule, $subscriber ) ) {
				continue;
			}
			$this->execute( $rule, $subscriber );
		}
	}

	/**
	 * Whether a rule's condition matches the subscriber.
	 *
	 * @param array<string, mixed> $rule       Rule.
	 * @param Subscriber           $subscriber Subscriber.
	 */
	private function matches( array $rule, Subscriber $subscriber ): bool {
		$field = (string) ( $rule['field'] ?? 'always' );
		$value = trim( (string) ( $rule['value'] ?? '' ) );

		switch ( $field ) {
			case 'always':
				return true;
			case 'source':
				return 0 === strcasecmp( $subscriber->source->value, $value ) || 0 === strcasecmp( $subscriber->source->label(), $value );
			case 'country':
				return 0 === strcasecmp( (string) $subscriber->country, $value );
			case 'tag':
				$tags = $this->plugin->relationships()->tags_for( (int) $subscriber->id );
				return in_array( $value, array_map( 'strval', $tags ), true );
			case 'meta':
				$key = (string) ( $rule['metaKey'] ?? '' );
				return isset( $subscriber->meta[ $key ] ) && 0 === strcasecmp( (string) $subscriber->meta[ $key ], $value );
			default:
				return false;
		}
	}

	/**
	 * Run a rule's action.
	 *
	 * @param array<string, mixed> $rule       Rule.
	 * @param Subscriber           $subscriber Subscriber.
	 */
	private function execute( array $rule, Subscriber $subscriber ): void {
		$action = (string) ( $rule['action'] ?? '' );
		$value  = trim( (string) ( $rule['actionValue'] ?? '' ) );

		$this->running = true;
		try {
			switch ( $action ) {
				case 'add_tag':
					if ( '' !== $value ) {
						$this->plugin->subscribers()->apply_tags( $subscriber, [ $value ] );
					}
					break;
				case 'remove_tag':
					if ( '' !== $value ) {
						$this->plugin->subscribers()->remove_tags( $subscriber, [ $value ] );
					}
					break;
				case 'sync':
					$targets = array_values( array_filter( array_map( 'intval', array_map( 'trim', explode( ',', $value ) ) ) ) );
					if ( $targets ) {
						$this->plugin->sync()->dispatch( $subscriber, $targets );
					}
					break;
				case 'webhook':
					$this->fire_webhook( esc_url_raw( $value ), 'automation', $subscriber );
					break;
			}
		} finally {
			$this->running = false;
		}
	}

	/**
	 * Serialise a subscriber for a webhook payload.
	 *
	 * @param Subscriber $subscriber Subscriber.
	 * @return array<string, mixed>
	 */
	private function to_payload( Subscriber $subscriber ): array {
		return [
			'id'         => (int) $subscriber->id,
			'email'      => $subscriber->email,
			'first_name' => $subscriber->first_name,
			'last_name'  => $subscriber->last_name,
			'status'     => $subscriber->status->value,
			'source'     => $subscriber->source->value,
			'country'    => $subscriber->country,
			'tags'       => array_values( $this->plugin->relationships()->tags_for( (int) $subscriber->id ) ),
		];
	}
}
