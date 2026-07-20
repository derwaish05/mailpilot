<?php
/**
 * Contact Form 7 integration.
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
 * Captures Contact Form 7 submissions on successful mail send.
 */
final class ContactForm7 extends AbstractIntegration {

	public function id(): string {
		return 'cf7';
	}

	public function label(): string {
		return 'Contact Form 7';
	}

	public function is_available(): bool {
		return class_exists( '\WPCF7_ContactForm' );
	}

	protected function source(): string {
		return Source::NewsletterForm->value;
	}

	public function register(): void {
		add_action( 'wpcf7_mail_sent', [ $this, 'on_sent' ], 10, 1 );
	}

	/**
	 * Capture from the submission instance.
	 *
	 * @param mixed $contact_form The WPCF7_ContactForm (unused; data via submission).
	 */
	public function on_sent( $contact_form ): void {
		if ( ! class_exists( '\WPCF7_Submission' ) ) {
			return;
		}

		$submission = \WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}

		$this->capture_values( (array) $submission->get_posted_data() );
	}
}
