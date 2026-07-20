<?php
/**
 * Front-end form asset registration.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers (but does not force-enqueue) the form CSS/JS, so they load only on
 * pages that actually render a form. Avoids global theme conflicts.
 */
final class FormAssets {

	/**
	 * Hook asset registration.
	 */
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'register' ] );
	}

	/**
	 * Register the handles.
	 */
	public function register(): void {
		wp_register_style(
			'mailpilot-forms',
			MAILPILOT_URL . 'assets/css/forms.css',
			[],
			MAILPILOT_VERSION
		);

		wp_register_script(
			'mailpilot-forms',
			MAILPILOT_URL . 'assets/js/forms.js',
			[],
			MAILPILOT_VERSION,
			true
		);
	}
}
