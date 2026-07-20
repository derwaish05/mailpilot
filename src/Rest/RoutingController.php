<?php
/**
 * REST API: audience routing rules.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Rest;

use MailPilot\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists the IF / ELSE-IF / ELSE routing pipeline.
 *
 * Route (namespace `mailpilot/v1`):
 *   POST /routing/rules   Replace all routing branches.
 *
 * Writes the same `mailpilot_pro_routing_rules` option the Pro RulesRepository
 * reads, so saved rules are executed by the Pro routing engine when active.
 * The redesigned builder sends its full flat rule list; this controller
 * converts each to a branch. Requires `manage_options` + the REST nonce.
 */
final class RoutingController {

	private const NAMESPACE = 'mailpilot/v1';
	private const OPTION      = 'mailpilot_pro_routing_rules';

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
			'/routing/rules',
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
			return new WP_Error( 'mailpilot_forbidden', __( 'You are not allowed to manage routing.', 'mailpilot' ), [ 'status' => rest_authorization_required_code() ] );
		}

		return true;
	}

	/**
	 * POST /routing/rules — replace all branches with the submitted flat rules.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params() ?: $request->get_params();
		$rules  = is_array( $params['rules'] ?? null ) ? $params['rules'] : [];

		$branches = [];
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) ) {
				$branches[] = $this->to_branch( $rule );
			}
		}

		update_option( self::OPTION, array_values( $branches ), false );

		return new WP_REST_Response( [ 'saved' => true, 'count' => count( $branches ) ], 200 );
	}

	/**
	 * Convert a flat builder rule into a routing branch (inverse of
	 * AdminMenu::audience_routing_data()).
	 *
	 * @param array<string, mixed> $rule Flat rule.
	 * @return array<string, mixed>
	 */
	private function to_branch( array $rule ): array {
		$field    = sanitize_key( (string) ( $rule['field'] ?? 'always' ) );
		$operator = sanitize_key( (string) ( $rule['operator'] ?? 'is' ) );
		$value    = sanitize_text_field( (string) ( $rule['value'] ?? '' ) );
		$meta_key = sanitize_key( (string) ( $rule['metaKey'] ?? '' ) );

		$branch = [];
		if ( 'always' === $field ) {
			$branch['else'] = true;
		} else {
			$config = [ 'operator' => $operator, 'value' => $value ];
			if ( 'meta' === $field && '' !== $meta_key ) {
				$config['key'] = $meta_key;
			}
			$branch['match']      = 'all';
			$branch['conditions'] = [ [ 'type' => $field, 'config' => $config ] ];
		}

		$action       = sanitize_key( (string) ( $rule['action'] ?? '' ) );
		$action_value = sanitize_text_field( (string) ( $rule['actionValue'] ?? '' ) );
		$type         = 'sync' === $action ? 'sync_provider' : $action;

		$config = match ( $action ) {
			'add_tag', 'remove_tag' => [ 'tag' => $action_value ],
			'sync'                  => [ 'connections' => array_values( array_filter( array_map( 'intval', array_map( 'trim', explode( ',', $action_value ) ) ) ) ) ],
			'add_list'              => [ 'lists' => array_values( array_filter( array_map( 'trim', explode( ',', $action_value ) ) ) ) ],
			'skip'                  => [],
			default                 => '' !== $action_value ? [ 'value' => $action_value ] : [],
		};

		$branch['actions'] = '' !== $type ? [ [ 'type' => $type, 'config' => $config ] ] : [];

		return $branch;
	}
}
