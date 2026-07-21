<?php
/**
 * Gutenberg block for embedding forms.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a server-rendered `mailpilot/form` block. Rendering goes through
 * the shared FormsModule so the block, shortcode, and PHP function emit
 * identical markup.
 */
final class Block {

	public function __construct( private FormsModule $module ) {}

	/**
	 * Hook block + editor-script registration.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register' ] );
	}

	/**
	 * Register the block type and its editor script.
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'mailpilot-block',
			MAILPILOT_URL . 'assets/js/block.js',
			[ 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ],
			MAILPILOT_VERSION,
			true
		);

		// Provide the published form list to the editor dropdown.
		$options = [];
		foreach ( $this->module->repository()->all() as $form ) {
			$label = $form->title ?: sprintf( 'Form #%d', (int) $form->id );

			if ( 'published' !== $form->status ) {
				/* translators: %s: form title. */
				$label = sprintf( __( '%s (draft)', 'brainstudioz-mailpilot' ), $label );
			}

			$options[] = [
				'value' => (int) $form->id,
				'label' => $label,
			];
		}
		wp_localize_script( 'mailpilot-block', 'MailPilotBlock', [ 'forms' => $options ] );

		register_block_type(
			'mailpilot/form',
			[
				'api_version'     => 2,
				'editor_script'   => 'mailpilot-block',
				'attributes'      => [
					'formId' => [
						'type'    => 'integer',
						'default' => 0,
					],
				],
				'render_callback' => [ $this, 'render' ],
			]
		);
	}

	/**
	 * Server-side render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes ): string {
		$id = (int) ( $attributes['formId'] ?? 0 );

		// Do not count views for editor-side previews.
		$html = $this->module->render_form( $id, ! is_admin() );

		if ( '' === $html && is_admin() ) {
			return '<p>' . esc_html__( 'Select a published MailPilot form.', 'brainstudioz-mailpilot' ) . '</p>';
		}

		return $html;
	}
}
