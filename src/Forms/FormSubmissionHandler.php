<?php
/**
 * Form submission handler.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Forms;

use MailPilot\Activity\Event;
use MailPilot\Analytics\Analytics;
use MailPilot\Plugin;
use MailPilot\Subscribers\Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receives front-end form posts, validates them, and emits a normalized event
 * into the Subscriber Engine — never writing to a provider directly. Executes
 * the form's post-submit actions (tags, providers, webhook, redirect).
 */
final class FormSubmissionHandler {

	public function __construct(
		private Plugin $plugin,
		private FormRepository $forms,
		private Analytics $analytics,
	) {}

	/**
	 * Hook the admin-post endpoints (logged-in and anonymous).
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_mailpilot_form_submit', [ $this, 'handle' ] );
		add_action( 'admin_post_nopriv_mailpilot_form_submit', [ $this, 'handle' ] );
		add_action( 'mailpilot_send_webhook', [ $this, 'send_webhook' ] );
	}

	/**
	 * Handle a submission.
	 */
	public function handle(): void {
		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Nonce verification.
		if ( ! isset( $_POST['mailpilot_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mailpilot_nonce'] ) ), 'mailpilot_form_' . $form_id ) ) {
			$this->respond( false, __( 'Security check failed. Please refresh and try again.', 'brainstudioz-mailpilot' ) );
		}

		// Honeypot: a filled hidden field means a bot — silently succeed.
		if ( ! empty( $_POST['mailpilot_hp'] ) ) {
			$this->respond( true, __( 'Thanks!', 'brainstudioz-mailpilot' ) );
		}

		$form = $this->forms->find( $form_id );
		if ( null === $form || 'published' !== $form->status ) {
			$this->respond( false, __( 'This form is not available.', 'brainstudioz-mailpilot' ) );
		}

		// Analytics attribution surface (e.g. `form`, `elementor`).
		$attribution = isset( $_POST['attribution'] ) ? sanitize_key( wp_unslash( $_POST['attribution'] ) ) : 'form'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attribution = '' !== $attribution ? $attribution : 'form';

		$this->analytics->increment( 'submissions', 1, $attribution, $form_id );

		$raw    = isset( $_POST['fields'] ) ? (array) wp_unslash( $_POST['fields'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$errors = $this->validate( $form, $raw );

		if ( $errors ) {
			$this->respond( false, implode( ' ', $errors ) );
		}

		[ $data, $consent ] = $this->map_fields( $form, $raw );

		// GDPR: if a consent field is present and required, it must be granted.
		if ( $form->has_gdpr_field() && ! $consent ) {
			$this->respond( false, __( 'Please provide consent to continue.', 'brainstudioz-mailpilot' ) );
		}

		$data['source']     = Source::NewsletterForm->value;
		$data['ip_address'] = $this->client_ip();
		if ( $consent ) {
			$data['consent_at'] = current_time( 'mysql', true );
		}

		if ( $form->setting( 'double_opt_in', false ) ) {
			$data['status'] = 'pending';
		}

		$options = [
			'tags'  => array_values( (array) $form->action( 'apply_tags', [] ) ),
			'sync'  => ! empty( $form->action( 'providers', [] ) ),
		];

		try {
			$subscriber = $this->plugin->subscribers()->capture( $data, $options );
		} catch ( \Throwable $e ) {
			$this->respond( false, __( 'We could not process your submission.', 'brainstudioz-mailpilot' ) );
		}

		$this->plugin->activity()->log( (int) $subscriber->id, Event::FormSubmission, sprintf( /* translators: %s: form title. */ __( 'Submitted form "%s"', 'brainstudioz-mailpilot' ), $form->title ), [ 'form_id' => $form_id ] );

		/**
		 * Fires after a form submission is captured. Pro modules (lead magnets,
		 * automation) hook this to act on the form + subscriber.
		 *
		 * @param Form                 $form       The submitted form.
		 * @param object               $subscriber The captured subscriber.
		 * @param array<string, mixed> $data       The mapped submission data.
		 */
		do_action( 'mailpilot_form_submitted', $form, $subscriber, $data );

		// Send to the form's selected providers, plus every active connection when
		// "Sync to all providers" is enabled.
		$providers = $this->plugin->sync()->signup_targets( (array) $form->action( 'providers', [] ) );
		if ( $providers && $subscriber->status->is_syncable() ) {
			$this->plugin->sync()->dispatch( $subscriber, $providers );
		}

		// Per-form outgoing webhook (queued).
		$webhook = (string) $form->action( 'webhook', '' );
		if ( '' !== $webhook ) {
			$this->plugin->queue()->push(
				'mailpilot_send_webhook',
				[
					'url'     => $webhook,
					'payload' => [
						'form_id' => $form_id,
						'email'   => $subscriber->email,
						'fields'  => $data,
					],
				]
			);
		}

		$this->analytics->increment( 'conversions', 1, $attribution, $form_id );

		$message = (string) $form->setting( 'success_message', __( 'Thanks for subscribing!', 'brainstudioz-mailpilot' ) );

		/**
		 * Filter the post-submit redirect URL. Pro lead magnets use this to send
		 * the user to a signed instant-download URL.
		 *
		 * @param string $redirect   Configured redirect.
		 * @param Form   $form       The form.
		 * @param object $subscriber The subscriber.
		 */
		$redirect = (string) apply_filters( 'mailpilot_form_redirect', (string) $form->action( 'redirect', '' ), $form, $subscriber );

		$this->respond( true, $message, $redirect );
	}

	/**
	 * Validate raw input against the form's fields.
	 *
	 * @param Form                  $form Form.
	 * @param array<string, mixed>  $raw  Raw field values.
	 * @return array<int, string> Error messages.
	 */
	private function validate( Form $form, array $raw ): array {
		$errors = [];

		foreach ( $form->fields as $field ) {
			// A split-mode Name field posts a nested array (first/last) instead
			// of one string; only "first" is ever required, matching the HTML
			// `required` attribute the renderer puts on that input alone.
			if ( FieldType::Name === $field->type && 'split' === $field->name_mode ) {
				$first = isset( $raw[ $field->key ]['first'] ) ? trim( (string) $raw[ $field->key ]['first'] ) : '';
				if ( $field->required && '' === $first ) {
					/* translators: %s: field label. */
					$errors[] = sprintf( __( '%s is required.', 'brainstudioz-mailpilot' ), $field->label ?: __( 'First name', 'brainstudioz-mailpilot' ) );
				}
				continue;
			}

			$value = isset( $raw[ $field->key ] ) ? trim( (string) ( is_array( $raw[ $field->key ] ) ? implode( ',', $raw[ $field->key ] ) : $raw[ $field->key ] ) ) : '';

			if ( $field->required && '' === $value && FieldType::Gdpr !== $field->type ) {
				/* translators: %s: field label. */
				$errors[] = sprintf( __( '%s is required.', 'brainstudioz-mailpilot' ), $field->label ?: $field->key );
				continue;
			}

			if ( '' === $value ) {
				continue;
			}

			$valid = match ( $field->validation ) {
				'email'  => (bool) is_email( $value ),
				'url'    => (bool) wp_http_validate_url( $value ),
				'number' => is_numeric( $value ),
				default  => FieldType::Email === $field->type ? (bool) is_email( $value ) : true,
			};

			if ( ! $valid ) {
				/* translators: %s: field label. */
				$errors[] = sprintf( __( '%s is not valid.', 'brainstudioz-mailpilot' ), $field->label ?: $field->key );
			}
		}

		return $errors;
	}

	/**
	 * Map sanitized field values to engine data + consent flag.
	 *
	 * @param Form                 $form Form.
	 * @param array<string, mixed> $raw  Raw values.
	 * @return array{0:array<string,mixed>,1:bool}
	 */
	private function map_fields( Form $form, array $raw ): array {
		$data    = [ 'meta' => [] ];
		$consent = false;

		foreach ( $form->fields as $field ) {
			$key = $field->key;

			// Split-mode Name: read the nested first/last keys directly rather
			// than splitting a single string on whitespace.
			if ( FieldType::Name === $field->type && 'split' === $field->name_mode ) {
				$raw_value  = $raw[ $key ] ?? [];
				$first_name = sanitize_text_field( (string) ( is_array( $raw_value ) ? ( $raw_value['first'] ?? '' ) : '' ) );
				$last_name  = sanitize_text_field( (string) ( is_array( $raw_value ) ? ( $raw_value['last'] ?? '' ) : '' ) );

				if ( '' !== $first_name ) {
					$data['first_name'] = $first_name;
				}
				if ( '' !== $last_name ) {
					$data['last_name'] = $last_name;
				}
				continue;
			}

			$value = $raw[ $key ] ?? '';

			if ( FieldType::Gdpr === $field->type ) {
				$consent = ! empty( $value );
				continue;
			}

			if ( FieldType::Checkbox === $field->type && is_array( $value ) ) {
				$value = implode( ', ', array_map( 'sanitize_text_field', $value ) );
			} else {
				$value = sanitize_text_field( (string) $value );
			}

			if ( '' === $value ) {
				continue;
			}

			$standard = $field->type->subscriber_field();

			if ( null !== $standard ) {
				$data[ $standard ] = $value;
			} elseif ( FieldType::Name === $field->type ) {
				$parts              = preg_split( '/\s+/', $value, 2 );
				$data['first_name'] = $parts[0] ?? $value;
				if ( isset( $parts[1] ) ) {
					$data['last_name'] = $parts[1];
				}
			} else {
				$data['meta'][ $key ] = $value;
			}
		}

		return [ $data, $consent ];
	}

	/**
	 * Queue worker: POST a form webhook payload.
	 *
	 * @param array<string, mixed> $payload Job payload (url, payload).
	 */
	public function send_webhook( array $payload ): void {
		$url = (string) ( $payload['url'] ?? '' );
		if ( '' === $url ) {
			return;
		}

		wp_remote_post(
			$url,
			[
				'timeout' => 15,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => (string) wp_json_encode( $payload['payload'] ?? [] ),
			]
		);
	}

	/**
	 * Best-effort client IP, respecting common proxy headers.
	 */
	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Send a response: JSON for AJAX, redirect otherwise.
	 *
	 * @param bool   $success  Whether the submission succeeded.
	 * @param string $message  Message text.
	 * @param string $redirect Optional redirect URL.
	 */
	private function respond( bool $success, string $message, string $redirect = '' ): void {
		if ( wp_doing_ajax() || ( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && 'xmlhttprequest' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) ) ) ) {
			$payload = [ 'message' => $message ];
			if ( '' !== $redirect ) {
				$payload['redirect'] = $redirect;
			}

			if ( $success ) {
				wp_send_json_success( $payload );
			}
			wp_send_json_error( $payload );
		}

		// Non-AJAX fallback.
		if ( $success && '' !== $redirect ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		$target = wp_get_referer() ?: home_url();
		$target = add_query_arg( 'mailpilot_status', $success ? 'success' : 'error', $target );
		wp_safe_redirect( $target );
		exit;
	}
}
