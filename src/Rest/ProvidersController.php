<?php
/**
 * REST API: providers (used by the connection UI).
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Rest;

use MailPilot\Plugin;
use MailPilot\Providers\Contracts\Provider;
use MailPilot\Providers\ProviderConnection;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backs the provider connection screen.
 *
 * Routes (namespace `mailpilot/v1`):
 *   POST /providers/lists   Fetch a provider's lists for the given credentials.
 *
 * Credentials are accepted in the request body (never the URL) and used only to
 * make the live lookup; nothing is persisted here. Requires `manage_options` and
 * the standard REST nonce.
 */
final class ProvidersController {

	private const NAMESPACE = 'mailpilot/v1';

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
			'/providers/lists',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'lists' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/providers',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/providers/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'disconnect' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);
	}

	/**
	 * POST /providers — create (connect) a provider connection.
	 *
	 * Body: `{ provider, label, credentials:{<key>:val}, list_id, list_manual,
	 * tags, field_map, double_opt_in }`. Credentials are persisted encrypted by
	 * the repository. Mirrors the legacy ProvidersPage::save() flow.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params() ?: $request->get_params();

		// Editing an existing connection: load it so we update in place (rather
		// than inserting a duplicate) and keep the provider fixed to the saved one.
		$conn_id  = (int) ( $params['id'] ?? 0 );
		$existing = $conn_id > 0 ? $this->plugin->provider_connections()->find( $conn_id ) : null;

		$slug = null !== $existing ? $existing->provider : sanitize_key( (string) ( $params['provider'] ?? '' ) );

		$provider = $this->plugin->providers()->get( $slug );
		if ( ! $provider instanceof Provider ) {
			return new WP_Error( 'mailpilot_unknown_provider', __( 'Unknown provider.', 'brainstudioz-mailpilot' ), [ 'status' => 400 ] );
		}

		// Map the submitted credentials onto the provider's declared fields. The
		// redesigned drawer sends a single value under `api_key`; fall back to it
		// when a provider's sole credential uses a different key.
		$raw    = (array) ( $params['credentials'] ?? [] );
		$fields = $provider->credential_fields();
		$creds  = [];
		foreach ( $fields as $field ) {
			$key   = (string) $field['key'];
			$value = $raw[ $key ] ?? ( 1 === count( $fields ) ? ( $raw['api_key'] ?? '' ) : '' );
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' === $value ) {
				continue;
			}
			$creds[ $key ] = 'api_url' === $key ? esc_url_raw( $value ) : sanitize_text_field( $value );
		}

		// When editing without re-entering the key, keep the saved credentials.
		if ( [] === array_filter( $creds ) && null !== $existing ) {
			$creds = $existing->credentials;
		}

		if ( $provider->capabilities()->api_key_auth && [] === array_filter( $creds ) ) {
			return new WP_Error( 'mailpilot_missing_key', __( 'Enter your API key.', 'brainstudioz-mailpilot' ), [ 'status' => 422 ] );
		}

		$list = sanitize_text_field( (string) ( $params['list_id'] ?? '' ) );
		if ( '' === $list ) {
			$list = sanitize_text_field( (string) ( $params['list_manual'] ?? '' ) );
		}

		$tags = [];
		if ( ! empty( $params['tags'] ) ) {
			$tags = array_values( array_filter( array_map(
				'sanitize_text_field',
				array_map( 'trim', explode( ',', (string) $params['tags'] ) )
			) ) );
		}

		$connection = new ProviderConnection(
			id: null !== $existing ? (int) $existing->id : null,
			provider: $slug,
			label: sanitize_text_field( (string) ( $params['label'] ?? '' ) ) ?: $slug,
			status: 'active',
			credentials: $creds,
			settings: [
				'lists'         => '' !== $list ? [ $list ] : [],
				'double_opt_in' => ! empty( $params['double_opt_in'] ),
				'default_tags'  => $tags,
			],
			field_map: $this->parse_field_map( (string) ( $params['field_map'] ?? '' ) ),
		);

		$id = (int) $this->plugin->provider_connections()->save( $connection );
		delete_transient( 'mailpilot_lists_' . $id );

		return new WP_REST_Response(
			[
				'id'       => $id,
				'provider' => $provider->label(),
				'label'    => $connection->label,
				'list'     => '' !== $list ? $list : __( 'none — set one', 'brainstudioz-mailpilot' ),
			],
			201
		);
	}

	/**
	 * Parse a "local = remote" field-map textarea into an array.
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
			[ $local, $remote ] = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' !== $local && '' !== $remote ) {
				$map[ sanitize_key( $local ) ] = sanitize_text_field( $remote );
			}
		}
		return $map;
	}

	/**
	 * DELETE /providers/{id} — disconnect (delete) a provider connection.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function disconnect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];

		if ( null === $this->plugin->provider_connections()->find( $id ) ) {
			return new WP_Error( 'mailpilot_not_found', __( 'Connection not found.', 'brainstudioz-mailpilot' ), [ 'status' => 404 ] );
		}

		$this->plugin->provider_connections()->delete( $id );
		delete_transient( 'mailpilot_lists_' . $id );

		return new WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	/**
	 * Capability gate.
	 */
	public function authorize(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mailpilot_forbidden', __( 'You are not allowed to manage providers.', 'brainstudioz-mailpilot' ), [ 'status' => rest_authorization_required_code() ] );
		}

		return true;
	}

	/**
	 * POST /providers/lists — fetch lists for a provider + credentials.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function lists( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params  = $request->get_json_params() ?: $request->get_params();
		$conn_id = (int) ( $params['connection'] ?? 0 );

		// Existing connection: use its stored (decrypted) credentials — no need to
		// re-enter the API key just to list audiences.
		if ( $conn_id > 0 ) {
			$connection = $this->plugin->provider_connections()->find( $conn_id );
			if ( null === $connection ) {
				return new WP_Error( 'mailpilot_not_found', __( 'Connection not found.', 'brainstudioz-mailpilot' ), [ 'status' => 404 ] );
			}
			$provider = $this->plugin->providers()->get( $connection->provider );
		} else {
			// New connection: build an ephemeral connection from submitted credentials.
			$slug     = sanitize_key( (string) ( $params['provider'] ?? '' ) );
			$provider = $this->plugin->providers()->get( $slug );

			if ( ! $provider instanceof Provider ) {
				return new WP_Error( 'mailpilot_unknown_provider', __( 'Unknown provider.', 'brainstudioz-mailpilot' ), [ 'status' => 400 ] );
			}

			$raw         = (array) ( $params['credentials'] ?? [] );
			$credentials = [];
			foreach ( $provider->credential_fields() as $field ) {
				$key   = (string) $field['key'];
				$value = isset( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
				if ( '' === $value ) {
					continue;
				}
				$credentials[ $key ] = 'api_url' === $key ? esc_url_raw( $value ) : sanitize_text_field( $value );
			}

			$connection = new ProviderConnection(
				id: null,
				provider: $slug,
				status: 'active',
				credentials: $credentials,
			);
		}

		if ( ! $provider instanceof Provider ) {
			return new WP_Error( 'mailpilot_unknown_provider', __( 'Unknown provider.', 'brainstudioz-mailpilot' ), [ 'status' => 400 ] );
		}

		// Help the common case: credentials required but none present.
		if ( $provider->capabilities()->api_key_auth && [] === array_filter( $connection->credentials ) ) {
			return new WP_REST_Response(
				[
					'lists'      => [],
					'list_label' => $provider->list_label(),
					'error'      => __( 'No API key saved for this connection — enter your key and save first, or paste it above.', 'brainstudioz-mailpilot' ),
				],
				200
			);
		}

		$lists = $provider->get_lists( $connection );
		$error = method_exists( $provider, 'last_list_error' ) ? $provider->last_list_error() : null;

		return new WP_REST_Response(
			[
				'lists'      => array_values( $lists ),
				'list_label' => $provider->list_label(),
				'error'      => $error,
			],
			200
		);
	}
}
