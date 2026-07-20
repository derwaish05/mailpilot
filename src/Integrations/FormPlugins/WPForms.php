<?php
/**
 * WPForms integration.
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
 * Captures WPForms submissions on process-complete.
 */
final class WPForms extends AbstractIntegration {

	public function id(): string {
		return 'wpforms';
	}

	public function label(): string {
		return 'WPForms';
	}

	public function is_available(): bool {
		return function_exists( 'wpforms' );
	}

	protected function source(): string {
		return Source::NewsletterForm->value;
	}

	public function register(): void {
		add_action( 'wpforms_process_complete', [ $this, 'on_complete' ], 10, 4 );
	}

	/**
	 * Capture from completed fields.
	 *
	 * @param array<int, array<string, mixed>> $fields    Submitted fields.
	 * @param array<string, mixed>             $entry     Raw entry.
	 * @param array<string, mixed>             $form_data Form config.
	 * @param int                              $entry_id  Entry id.
	 */
	public function on_complete( array $fields, array $entry, array $form_data, int $entry_id ): void {
		$values = [];

		foreach ( $fields as $field ) {
			$name            = (string) ( $field['name'] ?? $field['id'] ?? '' );
			$values[ $name ] = $field['value'] ?? '';
		}

		$this->capture_values( $values );
	}
}
