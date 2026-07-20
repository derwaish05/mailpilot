<?php
/**
 * Global public API functions.
 *
 * Defined in the root namespace so themes and add-ons can call them without a
 * namespace prefix. Namespaced internal calls fall back to these globals when
 * no `MailPilot\…` function of the same name exists.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

use MailPilot\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'mailpilot' ) ) {
	/**
	 * Primary accessor for the plugin container.
	 *
	 * Developer API entry point, e.g.:
	 *   mailpilot()->subscribers()->create( [ 'email' => 'a@b.co' ] );
	 *
	 * @return Plugin
	 */
	function mailpilot(): Plugin {
		return Plugin::instance();
	}
}

if ( ! function_exists( 'mailpilot_form' ) ) {
	/**
	 * Render a MailPilot form by id.
	 *
	 * @param int  $id   Form id.
	 * @param bool $echo Whether to echo (true) or return (false) the markup.
	 * @return string The form HTML.
	 */
	function mailpilot_form( int $id, bool $echo = true ): string {
		$html = mailpilot()->forms()->render_form( $id );

		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes its own output.
		}

		return $html;
	}
}
