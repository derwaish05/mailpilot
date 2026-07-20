<?php
/**
 * REST API: lead magnets.
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
 * Create/delete lead magnets, persisted to the same `mailpilot_pro_lead_magnets`
 * option the Pro LeadMagnet module reads for gated, signed-URL delivery.
 *
 * Routes (namespace `mailpilot/v1`):
 *   POST   /lead-magnets        Create a magnet.
 *   DELETE /lead-magnets/{id}   Delete a magnet.
 *
 * The magnet's file is a server path or media URL (not an upload); delivery is
 * handled by the Pro module. Requires `manage_options` + the REST nonce.
 */
final class LeadMagnetsController {

	private const NAMESPACE = 'mailpilot/v1';
	private const OPTION      = 'mailpilot_pro_lead_magnets';

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
			'/lead-magnets',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/lead-magnets/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);
	}

	/**
	 * Capability gate.
	 */
	public function authorize(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mailpilot_forbidden', __( 'You are not allowed to manage lead magnets.', 'mailpilot' ), [ 'status' => rest_authorization_required_code() ] );
		}

		return true;
	}

	/**
	 * POST /lead-magnets
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params() ?: $request->get_params();
		$title  = sanitize_text_field( (string) ( $params['title'] ?? '' ) );
		$file   = sanitize_text_field( (string) ( $params['file'] ?? '' ) );

		if ( '' === $title || '' === $file ) {
			return new WP_Error( 'mailpilot_invalid', __( 'A title and file are required.', 'mailpilot' ), [ 'status' => 422 ] );
		}

		$all = $this->all();
		$id  = $all ? ( (int) max( array_keys( $all ) ) + 1 ) : 1;

		$magnet = [
			'id'            => $id,
			'title'         => $title,
			'file'          => $file,
			'delivery'      => 'email' === ( $params['delivery'] ?? '' ) ? 'email' : 'instant',
			'max_downloads' => max( 0, (int) ( $params['limit'] ?? 0 ) ),
		];

		$all[ $id ] = $magnet;
		update_option( self::OPTION, $all, false );

		return new WP_REST_Response( $this->to_item( $magnet ), 201 );
	}

	/**
	 * DELETE /lead-magnets/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id  = (int) $request['id'];
		$all = $this->all();

		if ( ! isset( $all[ $id ] ) ) {
			return new WP_Error( 'mailpilot_not_found', __( 'Lead magnet not found.', 'mailpilot' ), [ 'status' => 404 ] );
		}

		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );

		return new WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	/**
	 * The stored magnets, keyed by id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function all(): array {
		$all = get_option( self::OPTION, [] );

		return is_array( $all ) ? $all : [];
	}

	/**
	 * Map a stored magnet to the screen's item shape.
	 *
	 * @param array<string, mixed> $magnet Stored magnet.
	 * @return array<string, mixed>
	 */
	private function to_item( array $magnet ): array {
		return [
			'id'          => (int) ( $magnet['id'] ?? 0 ),
			'title'       => (string) ( $magnet['title'] ?? '' ),
			'file'        => (string) ( $magnet['file'] ?? '' ),
			'delivery'    => 'email' === ( $magnet['delivery'] ?? '' ) ? 'email' : 'instant',
			'limit'       => (int) ( $magnet['max_downloads'] ?? 0 ),
			'downloads'   => 0,
			'conversions' => 0,
		];
	}
}
