<?php
/**
 * Gravity Forms integration.
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
 * Captures Gravity Forms submissions after submission.
 */
final class GravityForms extends AbstractIntegration {

	public function id(): string {
		return 'gravity_forms';
	}

	public function label(): string {
		return 'Gravity Forms';
	}

	public function is_available(): bool {
		return class_exists( '\GFForms' );
	}

	protected function source(): string {
		return Source::NewsletterForm->value;
	}

	public function register(): void {
		add_action( 'gform_after_submission', [ $this, 'on_submission' ], 10, 2 );
	}

	/**
	 * Capture from an entry.
	 *
	 * @param array<string, mixed> $entry Entry values keyed by field id.
	 * @param array<string, mixed> $form  Form definition.
	 */
	public function on_submission( array $entry, array $form ): void {
		$values = [];

		foreach ( (array) ( $form['fields'] ?? [] ) as $field ) {
			$id    = $field->id ?? null;
			$label = $field->label ?? (string) $id;
			if ( null !== $id && isset( $entry[ (string) $id ] ) ) {
				$values[ (string) $label ] = $entry[ (string) $id ];
			}
		}

		$this->capture_values( $values );
	}
}
