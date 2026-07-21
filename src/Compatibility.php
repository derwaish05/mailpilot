<?php
/**
 * Environment compatibility guard.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies the host meets the minimum PHP / WordPress baseline.
 */
final class Compatibility {

	/**
	 * Whether the current environment satisfies the plugin baseline.
	 */
	public static function is_supported(): bool {
		return version_compare( PHP_VERSION, MAILPILOT_MIN_PHP, '>=' )
			&& version_compare( get_bloginfo( 'version' ), MAILPILOT_MIN_WP, '>=' );
	}

	/**
	 * Render an admin notice describing the unmet requirement.
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: required PHP version, 2: required WordPress version, 3: current PHP, 4: current WP. */
			esc_html__( 'MailPilot requires PHP %1$s+ and WordPress %2$s+. You are running PHP %3$s and WordPress %4$s.', 'brainstudioz-mailpilot' ),
			esc_html( MAILPILOT_MIN_PHP ),
			esc_html( MAILPILOT_MIN_WP ),
			esc_html( PHP_VERSION ),
			esc_html( get_bloginfo( 'version' ) )
		);

		printf( '<div class="notice notice-error"><p>%s</p></div>', $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- message escaped above.
	}
}
