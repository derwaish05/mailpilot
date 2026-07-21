<?php
/**
 * Elementor Pro Forms "MailPilot" submit action.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Integrations\Elementor;

use Elementor\Controls_Manager;
use ElementorPro\Modules\Forms\Classes\Action_Base;
use MailPilot\Subscribers\Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds "MailPilot" as an after-submit action in Elementor Pro Forms.
 *
 * Maps the submitted fields to a subscriber and emits the event into the
 * Subscriber Engine — never writing to a provider directly. Missing fields are
 * handled gracefully (no email → no-op).
 */
final class FormsAction extends Action_Base {

	public function get_name(): string {
		return 'mailpilot';
	}

	public function get_label(): string {
		return __( 'MailPilot', 'brainstudioz-mailpilot' );
	}

	/**
	 * Register the action's settings on the Elementor form widget.
	 *
	 * @param object $widget Elementor form widget.
	 */
	public function register_settings_section( $widget ): void {
		$widget->start_controls_section(
			'section_mailpilot',
			[
				'label'     => __( 'MailPilot', 'brainstudioz-mailpilot' ),
				'condition' => [ 'submit_actions' => $this->get_name() ],
			]
		);

		$widget->add_control(
			'mailpilot_email_field',
			[
				'label'       => __( 'Email field ID', 'brainstudioz-mailpilot' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'The Elementor field ID holding the email. Leave blank to auto-detect.', 'brainstudioz-mailpilot' ),
			]
		);

		$widget->add_control(
			'mailpilot_tags',
			[
				'label'       => __( 'Apply tags', 'brainstudioz-mailpilot' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'Comma-separated.', 'brainstudioz-mailpilot' ),
			]
		);

		$widget->add_control(
			'mailpilot_providers',
			[
				'label'       => __( 'Provider connection IDs', 'brainstudioz-mailpilot' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'Comma-separated. Subscribers are queued for these connections.', 'brainstudioz-mailpilot' ),
			]
		);

		$widget->end_controls_section();
	}

	/**
	 * Remove our settings on export.
	 *
	 * @param array<string, mixed> $element Element data.
	 * @return array<string, mixed>
	 */
	public function on_export( $element ) {
		foreach ( [ 'mailpilot_email_field', 'mailpilot_tags', 'mailpilot_providers' ] as $key ) {
			unset( $element['settings'][ $key ] );
		}

		return $element;
	}

	/**
	 * Run the action after a successful submission.
	 *
	 * @param object $record       Form record.
	 * @param object $ajax_handler Ajax handler.
	 */
	public function run( $record, $ajax_handler ): void {
		$raw_fields = (array) $record->get( 'fields' );
		if ( ! $raw_fields ) {
			return; // Graceful: nothing submitted.
		}

		$settings    = (array) $record->get( 'form_settings' );
		$email_field = trim( (string) ( $settings['mailpilot_email_field'] ?? '' ) );

		$email  = '';
		$values = [];

		foreach ( $raw_fields as $id => $field ) {
			$value = (string) ( $field['value'] ?? '' );
			$type  = (string) ( $field['type'] ?? '' );
			$title = (string) ( $field['title'] ?? $id );

			if ( '' === $email ) {
				if ( '' !== $email_field && (string) $id === $email_field && is_email( $value ) ) {
					$email = $value;
				} elseif ( '' === $email_field && ( 'email' === $type || is_email( $value ) ) ) {
					$email = $value;
				}
			}

			$values[ (string) $id ] = [
				'type'  => $type,
				'title' => $title,
				'value' => $value,
			];
		}

		if ( '' === $email ) {
			return; // No email — graceful no-op.
		}

		$data = $this->map( $email, $values );

		try {
			$subscriber = mailpilot()->subscribers()->capture(
				$data,
				[ 'tags' => $this->csv( $settings['mailpilot_tags'] ?? '' ) ]
			);
		} catch ( \Throwable $e ) {
			return;
		}

		$providers = mailpilot()->sync()->signup_targets( $this->csv( $settings['mailpilot_providers'] ?? '' ) );
		if ( $providers && $subscriber->status->is_syncable() ) {
			mailpilot()->sync()->dispatch( $subscriber, $providers );
		}
	}

	/**
	 * Map Elementor fields to engine data.
	 *
	 * @param string                                         $email  Detected email.
	 * @param array<string, array{type:string,title:string,value:string}> $values Field values.
	 * @return array<string, mixed>
	 */
	private function map( string $email, array $values ): array {
		$data = [
			'email'  => $email,
			'source' => Source::NewsletterForm->value,
			'meta'   => [],
		];

		foreach ( $values as $field ) {
			$value = sanitize_text_field( $field['value'] );
			if ( '' === $value || $value === $email ) {
				continue;
			}

			$title = strtolower( $field['title'] );

			match ( true ) {
				'tel' === $field['type']                         => $data['phone'] = $value,
				str_contains( $title, 'first' )                   => $data['first_name'] = $value,
				str_contains( $title, 'last' )                    => $data['last_name'] = $value,
				str_contains( $title, 'company' )                 => $data['company'] = $value,
				'name' === $title                                 => $this->split_name( $data, $value ),
				default                                           => $data['meta'][ sanitize_key( $field['title'] ) ] = $value,
			};
		}

		return $data;
	}

	/**
	 * Split a single "name" value into first/last.
	 *
	 * @param array<string, mixed> $data  Data (by reference).
	 * @param string               $value Full name.
	 */
	private function split_name( array &$data, string $value ): void {
		$parts              = preg_split( '/\s+/', $value, 2 );
		$data['first_name'] = $parts[0] ?? $value;
		if ( isset( $parts[1] ) ) {
			$data['last_name'] = $parts[1];
		}
	}

	/**
	 * Split a comma-separated string.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	private function csv( mixed $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
	}
}
