<?php
/**
 * REST API: integrations (used by the redesigned Integrations screen).
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Rest;

use MailPilot\Integrations\Contracts\Integration;
use MailPilot\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists per-integration capture configuration.
 *
 * Route (namespace `mailpilot/v1`):
 *   POST /integrations/{id}   Save an integration's config.
 *
 * Mirrors the legacy IntegrationsPage::save() option shape so both surfaces
 * read/write the same `mailpilot_integration_{id}` option. Requires
 * `manage_options` and the standard REST nonce.
 */
final class IntegrationsController {

	private const NAMESPACE      = 'mailpilot/v1';
	private const OPTION_PREFIX  = 'mailpilot_integration_';

	public function __construct( private Plugin $plugin ) {}

	/**
	 * Hook route registration.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/integrations/(?P<id>[a-z0-9_\-]+)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);
	}

	/**
	 * Capability gate.
	 */
	public function authorize(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mailpilot_forbidden', __( 'You are not allowed to manage integrations.', 'mailpilot' ), [ 'status' => rest_authorization_required_code() ] );
		}

		return true;
	}

	/**
	 * POST /integrations/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = sanitize_key( (string) $request['id'] );

		if ( ! $this->is_known( $id ) ) {
			return new WP_Error( 'mailpilot_not_found', __( 'Integration not found.', 'mailpilot' ), [ 'status' => 404 ] );
		}

		$params  = $request->get_json_params() ?: $request->get_params();
		// Can't enable an integration whose host plugin isn't installed/active.
		$enabled = ! empty( $params['enabled'] ) && $this->is_available( $id );

		update_option(
			self::OPTION_PREFIX . $id,
			[
				'enabled'       => $enabled,
				'tags'          => $this->csv( $params['tags'] ?? '' ),
				'lists'         => $this->csv( $params['lists'] ?? '' ),
				'providers'     => array_map( 'intval', $this->csv( $params['providers'] ?? '' ) ),
				'email_field'   => $this->field_key( $params['emailField'] ?? '' ),
				'consent_field' => $this->field_key( $params['consentField'] ?? '' ),
				'consent_auto'  => ! empty( $params['consent'] ),
				'double_opt_in' => ! empty( $params['optin'] ),
				'field_map'     => $this->parse_field_map( (string) ( $params['mapping'] ?? '' ) ),
			],
			false
		);

		return new WP_REST_Response( [ 'saved' => true, 'id' => $id, 'enabled' => $enabled ], 200 );
	}

	/**
	 * Whether an integration id is registered.
	 *
	 * @param string $id Integration id.
	 */
	private function is_known( string $id ): bool {
		foreach ( $this->plugin->integrations()->all() as $integration ) {
			if ( $integration instanceof Integration && $integration->id() === $id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an integration's host plugin is installed/active.
	 *
	 * @param string $id Integration id.
	 */
	private function is_available( string $id ): bool {
		foreach ( $this->plugin->integrations()->all() as $integration ) {
			if ( $integration instanceof Integration && $integration->id() === $id ) {
				return $integration->is_available();
			}
		}

		return false;
	}

	/**
	 * Sanitise a single form-field name. Real field keys have no whitespace, so
	 * descriptive/autofilled values are rejected (kept blank) rather than saved.
	 *
	 * @param mixed $value Raw value.
	 */
	private function field_key( mixed $value ): string {
		$value = sanitize_text_field( (string) $value );

		return preg_match( '/\s/', $value ) ? '' : $value;
	}

	/**
	 * Split a comma-separated string into a clean list.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	private function csv( mixed $value ): array {
		return array_values( array_filter( array_map(
			'sanitize_text_field',
			array_map( 'trim', explode( ',', (string) $value ) )
		) ) );
	}

	/**
	 * Parse a "source = mailpilot_field" textarea into a map.
	 *
	 * @param string $raw Raw textarea content.
	 * @return array<string, string>
	 */
	private function parse_field_map( string $raw ): array {
		$map = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: [] as $line ) {
			if ( ! str_contains( $line, '=' ) ) {
				continue;
			}
			[ $host, $local ] = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' !== $host && '' !== $local ) {
				$map[ sanitize_key( $host ) ] = sanitize_text_field( $local );
			}
		}

		return $map;
	}
}
