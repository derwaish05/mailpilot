<?php
/**
 * Formidable Forms integration.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Integrations\FormPlugins;

use MailPilot\Integrations\AbstractIntegration;
use MailPilot\Subscribers\Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures Formidable Forms submissions after an entry is created.
 */
final class FormidableForms extends AbstractIntegration {

	public function id(): string {
		return 'formidable';
	}

	public function label(): string {
		return 'Formidable Forms';
	}

	public function is_available(): bool {
		return class_exists( '\FrmEntryMeta' );
	}

	protected function source(): string {
		return Source::NewsletterForm->value;
	}

	public function register(): void {
		add_action( 'frm_after_create_entry', [ $this, 'on_create' ], 30, 2 );
	}

	/**
	 * Capture from a created entry's posted item meta.
	 *
	 * @param int $entry_id Entry id.
	 * @param int $form_id  Form id.
	 */
	public function on_create( int $entry_id, int $form_id ): void {
		// Formidable posts field values under item_meta[field_id].
		$meta = isset( $_POST['item_meta'] ) && is_array( $_POST['item_meta'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['item_meta'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: [];

		$this->capture_values( $meta );
	}
}
