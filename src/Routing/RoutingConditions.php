<?php
/**
 * Core-provided routing conditions.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Routing;

use MailPilot\Subscribers\Subscriber;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers `source` and `status` routing conditions into the Pro routing
 * engine's condition registry, so the redesigned Audience Routing builder's
 * Source/Status options actually match at runtime.
 *
 * The Pro `mailpilot_register_routing_conditions` action only fires when Pro is
 * active, so the anonymous classes implementing the Pro `Condition` interface
 * are never instantiated (or resolved) without Pro present.
 */
final class RoutingConditions {

	/**
	 * Hook the condition registration.
	 */
	public function register_hooks(): void {
		add_action( 'mailpilot_register_routing_conditions', [ $this, 'register' ] );
	}

	/**
	 * Add the core conditions to the registry.
	 *
	 * @param mixed $registry Routing-condition registry (Pro).
	 */
	public function register( $registry ): void {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'add' ) ) {
			return;
		}

		// Source condition: match the subscriber's source (value or label).
		$registry->add(
			new class() implements \MailPilot\Pro\Routing\Contracts\Condition {
				public function id(): string {
					return 'source';
				}
				public function label(): string {
					return __( 'Source', 'brainstudioz-mailpilot' );
				}
				public function matches( array $config, Subscriber $subscriber, array $context ): bool {
					$operator = (string) ( $config['operator'] ?? 'is' );
					$value    = strtolower( trim( (string) ( $config['value'] ?? '' ) ) );
					$match    = strtolower( $subscriber->source->value ) === $value
						|| strtolower( $subscriber->source->label() ) === $value;

					return 'is_not' === $operator ? ! $match : $match;
				}
			}
		);

		// Status condition: match the subscriber's status (value or label).
		$registry->add(
			new class() implements \MailPilot\Pro\Routing\Contracts\Condition {
				public function id(): string {
					return 'status';
				}
				public function label(): string {
					return __( 'Status', 'brainstudioz-mailpilot' );
				}
				public function matches( array $config, Subscriber $subscriber, array $context ): bool {
					$operator = (string) ( $config['operator'] ?? 'is' );
					$value    = strtolower( trim( (string) ( $config['value'] ?? '' ) ) );
					$match    = strtolower( $subscriber->status->value ) === $value
						|| strtolower( $subscriber->status->label() ) === $value;

					return 'is_not' === $operator ? ! $match : $match;
				}
			}
		);
	}
}
