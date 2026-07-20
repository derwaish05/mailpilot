<?php
/**
 * JetFormBuilder integration.
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
 * Captures JetFormBuilder submissions after the form is sent.
 */
final class JetFormBuilder extends AbstractIntegration {

	public function id(): string {
		return 'jetformbuilder';
	}

	public function label(): string {
		return 'JetFormBuilder';
	}

	public function is_available(): bool {
		return function_exists( 'jet_form_builder' ) || class_exists( '\Jet_Form_Builder\Plugin' );
	}

	protected function source(): string {
		return Source::NewsletterForm->value;
	}

	public function register(): void {
		add_action( 'jet-form-builder/form-handler/after-send', [ $this, 'on_send' ], 10, 1 );
	}

	/**
	 * Capture from the request handler.
	 *
	 * @param mixed $handler The form handler (carries request data).
	 */
	public function on_send( $handler ): void {
		$values = [];

		if ( is_object( $handler ) && isset( $handler->request_data ) && is_array( $handler->request_data ) ) {
			$values = $handler->request_data;
		} elseif ( function_exists( 'jet_fb_request_handler' ) ) {
			$request = jet_fb_request_handler();
			if ( is_object( $request ) && method_exists( $request, 'get_request' ) ) {
				$values = (array) $request->get_request();
			}
		}

		$this->capture_values( $values );
	}
}
