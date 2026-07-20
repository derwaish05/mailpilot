<?php
/**
 * Form shortcode.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the `[mailpilot_form id="N"]` shortcode, delegating rendering to
 * the shared FormsModule so it matches the block and PHP function exactly.
 */
final class Shortcode {

	public function __construct( private FormsModule $module ) {}

	/**
	 * Hook shortcode registration.
	 */
	public function register_hooks(): void {
		add_shortcode( 'mailpilot_form', [ $this, 'render' ] );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function render( array|string $atts ): string {
		$atts = shortcode_atts( [ 'id' => 0 ], (array) $atts, 'mailpilot_form' );

		return $this->module->render_form( (int) $atts['id'] );
	}
}
