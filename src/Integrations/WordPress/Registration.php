<?php
/**
 * WordPress user registration capture.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Integrations\WordPress;

use MailPilot\Integrations\AbstractIntegration;
use MailPilot\Subscribers\Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures newly-registered WordPress users as subscribers.
 */
final class Registration extends AbstractIntegration {

	public function id(): string {
		return 'wp_registration';
	}

	public function label(): string {
		return __( 'WordPress Registration', 'brainstudioz-mailpilot' );
	}

	public function is_available(): bool {
		return true;
	}

	protected function source(): string {
		return Source::Registration->value;
	}

	public function register(): void {
		add_action( 'user_register', [ $this, 'on_register' ], 10, 1 );
	}

	/**
	 * Capture a registered user.
	 *
	 * @param int $user_id New user id.
	 */
	public function on_register( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || '' === (string) $user->user_email ) {
			return;
		}

		$this->subscribe(
			(string) $user->user_email,
			[
				'first_name' => (string) get_user_meta( $user_id, 'first_name', true ),
				'last_name'  => (string) get_user_meta( $user_id, 'last_name', true ),
				'meta'       => [ 'wp_user_id' => $user_id ],
			]
		);
	}
}
