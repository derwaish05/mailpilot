<?php
/**
 * Fluent Forms integration.
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
 * Captures Fluent Forms submissions after insert.
 */
final class FluentForms extends AbstractIntegration {

	public function id(): string {
		return 'fluent_forms';
	}

	public function label(): string {
		return 'Fluent Forms';
	}

	public function is_available(): bool {
		return defined( 'FLUENTFORM' ) || function_exists( 'wpFluentForm' );
	}

	protected function source(): string {
		return Source::NewsletterForm->value;
	}

	public function register(): void {
		add_action( 'fluentform_submission_inserted', [ $this, 'on_submission' ], 10, 3 );
	}

	/**
	 * Capture from submitted data.
	 *
	 * @param int                  $insert_id Entry id.
	 * @param array<string, mixed> $form_data Submitted values keyed by field name.
	 * @param mixed                $form      Form object.
	 */
	public function on_submission( int $insert_id, array $form_data, $form ): void {
		$this->capture_values( $form_data );
	}
}
