<?php
/**
 * Subscriber status enum and transition rules.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Subscribers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The lifecycle states a subscriber can be in.
 *
 * @see doc/06-technical-spec.md
 */
enum Status: string {

	case Pending      = 'pending';
	case Subscribed   = 'subscribed';
	case Unsubscribed = 'unsubscribed';
	case Bounced      = 'bounced';
	case Blocked      = 'blocked';

	/**
	 * Resolve a status from arbitrary input, defaulting to Pending.
	 *
	 * @param string|null $value Raw status string.
	 */
	public static function fromString( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Pending;
	}

	/**
	 * Human-readable label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Pending      => __( 'Pending', 'brainstudioz-mailpilot' ),
			self::Subscribed   => __( 'Subscribed', 'brainstudioz-mailpilot' ),
			self::Unsubscribed => __( 'Unsubscribed', 'brainstudioz-mailpilot' ),
			self::Bounced      => __( 'Bounced', 'brainstudioz-mailpilot' ),
			self::Blocked      => __( 'Blocked', 'brainstudioz-mailpilot' ),
		};
	}

	/**
	 * Whether a contact in this status may be synced to a provider.
	 *
	 * Blocked, Unsubscribed, and Bounced contacts are never synced
	 * (see doc/05-ai-development-rules.md, GDPR & status enforcement).
	 */
	public function is_syncable(): bool {
		return match ( $this ) {
			self::Pending, self::Subscribed => true,
			default                         => false,
		};
	}

	/**
	 * Allowed target statuses from the current state.
	 *
	 * @return array<int, self>
	 */
	public function allowedTransitions(): array {
		return match ( $this ) {
			self::Pending      => [ self::Subscribed, self::Unsubscribed, self::Bounced, self::Blocked ],
			self::Subscribed   => [ self::Unsubscribed, self::Bounced, self::Blocked ],
			self::Unsubscribed => [ self::Subscribed, self::Blocked ],
			self::Bounced      => [ self::Subscribed, self::Unsubscribed, self::Blocked ],
			self::Blocked      => [ self::Subscribed, self::Unsubscribed ],
		};
	}

	/**
	 * Whether transitioning to $target is permitted. Staying in the same
	 * status is always allowed (idempotent updates).
	 *
	 * @param self $target Desired status.
	 */
	public function canTransitionTo( self $target ): bool {
		if ( $this === $target ) {
			return true;
		}

		return in_array( $target, $this->allowedTransitions(), true );
	}
}
