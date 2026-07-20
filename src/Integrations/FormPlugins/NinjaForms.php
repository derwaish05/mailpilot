<?php
/**
 * Ninja Forms integration.
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
 * Captures Ninja Forms submissions after submission.
 */
final class NinjaForms extends AbstractIntegration {

	public function id(): string {
		return 'ninja_forms';
	}

	public function label(): string {
		return 'Ninja Forms';
	}

	public function is_available(): bool {
		return class_exists( '\Ninja_Forms' );
	}

	protected function source(): string {
		return Source::NewsletterForm->value;
	}

	public function register(): void {
		add_action( 'ninja_forms_after_submission', [ $this, 'on_submission' ], 10, 1 );
	}

	/**
	 * Capture from form data.
	 *
	 * @param array<string, mixed> $form_data Submission payload.
	 */
	public function on_submission( array $form_data ): void {
		$values = [];

		foreach ( (array) ( $form_data['fields'] ?? [] ) as $field ) {
			$key            = (string) ( $field['key'] ?? $field['id'] ?? '' );
			$values[ $key ] = $field['value'] ?? '';
		}

		$this->capture_values( $values );
	}
}
