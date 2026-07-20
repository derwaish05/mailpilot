<?php
/**
 * Base plugin integration.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Integrations;

use MailPilot\Integrations\Contracts\Integration;
use MailPilot\Subscribers\Subscriber;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared machinery for integrations: per-integration config (enabled, tags,
 * lists, field map, source) and a single `subscribe()` helper that funnels a
 * normalized payload into the Subscriber Engine.
 */
abstract class AbstractIntegration implements Integration {

	/**
	 * Per-integration settings option prefix.
	 */
	private const OPTION_PREFIX = 'mailpilot_integration_';

	/**
	 * Cached config for this request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $config = null;

	/**
	 * Whether the site owner has enabled this integration.
	 */
	public function is_enabled(): bool {
		return (bool) $this->config_value( 'enabled', false );
	}

	/**
	 * The subscriber source this integration records. Override per integration.
	 */
	abstract protected function source(): string;

	/**
	 * This integration's stored config, merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	protected function config(): array {
		if ( null === $this->config ) {
			$stored       = get_option( self::OPTION_PREFIX . $this->id(), [] );
			$this->config = array_merge(
				[
					'enabled'       => false,
					'tags'          => [],
					'lists'         => [],
					'providers'     => [],
					'field_map'     => [],
					'consent_field' => '', // If set, only subscribe when this exact field is granted.
					'consent_auto'  => false, // Respect any recognised consent checkbox on the form.
					'double_opt_in' => false, // Capture as Pending until confirmed.
				],
				is_array( $stored ) ? $stored : []
			);
		}

		return $this->config;
	}

	/**
	 * A single config value.
	 *
	 * @param string $key     Config key.
	 * @param mixed  $default Fallback.
	 */
	protected function config_value( string $key, mixed $default = null ): mixed {
		return $this->config()[ $key ] ?? $default;
	}

	/**
	 * Emit a normalized subscribe event into the engine.
	 *
	 * Applies the integration's configured tags, lists, providers, and field
	 * map. Never writes to a provider directly.
	 *
	 * @param string                $email  Subscriber email.
	 * @param array<string, mixed>  $fields Standard + custom fields (first_name, last_name, phone, company, country, meta…).
	 * @return Subscriber|null The captured subscriber, or null on failure.
	 */
	protected function subscribe( string $email, array $fields = [] ): ?Subscriber {
		if ( '' === trim( $email ) ) {
			return null;
		}

		$data = $this->apply_field_map( $fields );

		$data['email']  = $email;
		$data['source'] = $this->source();

		// Consent: if double opt-in is on, capture as Pending so the subscriber
		// must confirm before being emailed (GDPR-friendly default for the EU).
		if ( $this->config_value( 'double_opt_in', false ) ) {
			$data['status']     = 'pending';
			$data['consent_at'] = null;
		} else {
			$data['consent_at'] = current_time( 'mysql', true );
		}

		$options = [
			'tags'  => array_values( (array) $this->config_value( 'tags', [] ) ),
			'lists' => array_values( (array) $this->config_value( 'lists', [] ) ),
			'sync'  => ! empty( $this->config_value( 'providers', [] ) ),
		];

		try {
			$subscriber = mailpilot()->subscribers()->capture( $data, $options );
		} catch ( \Throwable $e ) {
			return null;
		}

		$providers = mailpilot()->sync()->signup_targets( (array) $this->config_value( 'providers', [] ) );
		if ( $providers && $subscriber->status->is_syncable() ) {
			mailpilot()->sync()->dispatch( $subscriber, $providers );
		}

		return $subscriber;
	}

	/**
	 * Capture a subscriber from a flat map of host field values.
	 *
	 * Detects the email (a configured `email_field`, else the first value that
	 * looks like an email), then funnels the remaining values through the field
	 * map into the engine. A convenience for form-plugin adapters whose payloads
	 * are heterogeneous.
	 *
	 * @param array<string, mixed> $values Host field key/label => value.
	 * @return Subscriber|null
	 */
	protected function capture_values( array $values ): ?Subscriber {
		$values = $this->flatten( $values );
		$email  = $this->detect_email( $values );

		if ( '' === $email ) {
			return null; // Graceful no-op: nothing to subscribe.
		}

		// Consent gate: works across every form plugin without per-field config.
		if ( ! $this->consent_granted( $values ) ) {
			return null;
		}

		// Drop the email value itself so it does not also land in meta.
		$values = array_filter( $values, static fn ( $v ): bool => $v !== $email );

		return $this->subscribe( $email, $values );
	}

	/**
	 * Whether consent has been granted for this submission.
	 *
	 * Precedence: an explicit `consent_field` (exact key) wins; otherwise, in
	 * auto mode, any recognised consent checkbox on the form is respected (and a
	 * form with no consent field is allowed). With neither set, capture is
	 * unconditional (auto-collection).
	 *
	 * @param array<string, string> $values Flattened submission values.
	 */
	protected function consent_granted( array $values ): bool {
		$field = trim( (string) $this->config_value( 'consent_field', '' ) );
		// A real form field key never contains whitespace; ignore descriptive or
		// autofilled junk so a bad value can't silently drop every submission.
		if ( '' !== $field && ! preg_match( '/\s/', $field ) ) {
			return ! empty( $values[ $field ] );
		}

		if ( $this->config_value( 'consent_auto', false ) ) {
			$pattern = '/gdpr|consent|privacy|terms|agree|accept|opt[\s_-]?in/i';
			foreach ( $values as $key => $value ) {
				if ( preg_match( $pattern, (string) $key ) ) {
					return ! empty( $value ); // Found a consent field — respect it.
				}
			}
			// No consent field on this form → nothing to gate on.
			return true;
		}

		return true; // No consent requirement → auto-collect.
	}

	/**
	 * Reduce a payload to scalar string values.
	 *
	 * @param array<string, mixed> $values Raw values.
	 * @return array<string, string>
	 */
	private function flatten( array $values ): array {
		$out = [];

		foreach ( $values as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$out[ (string) $key ] = sanitize_text_field( (string) $value );
			} elseif ( is_array( $value ) ) {
				$out[ (string) $key ] = sanitize_text_field( implode( ', ', array_filter( $value, 'is_scalar' ) ) );
			}
		}

		return $out;
	}

	/**
	 * Detect the email value in a flat payload.
	 *
	 * @param array<string, string> $values Flattened values.
	 */
	private function detect_email( array $values ): string {
		$field = (string) $this->config_value( 'email_field', '' );

		if ( '' !== $field && ! empty( $values[ $field ] ) && is_email( $values[ $field ] ) ) {
			return $values[ $field ];
		}

		foreach ( $values as $value ) {
			if ( is_email( $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * Apply the configured field map, routing unmapped values into meta.
	 *
	 * The map is `host_field => local_field`. Local keys matching a standard
	 * subscriber attribute are set directly; everything else goes to meta.
	 *
	 * @param array<string, mixed> $fields Raw host fields.
	 * @return array<string, mixed>
	 */
	protected function apply_field_map( array $fields ): array {
		$standard = [ 'first_name', 'last_name', 'phone', 'company', 'country' ];
		$map      = (array) $this->config_value( 'field_map', [] );
		$data     = [ 'meta' => [] ];

		foreach ( $fields as $key => $value ) {
			if ( 'meta' === $key && is_array( $value ) ) {
				$data['meta'] = array_merge( $data['meta'], $value );
				continue;
			}

			$local = $map[ $key ] ?? $key;

			if ( in_array( $local, $standard, true ) ) {
				$data[ $local ] = $value;
			} else {
				$data['meta'][ $local ] = $value;
			}
		}

		return $data;
	}
}
