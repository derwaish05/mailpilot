<?php
/**
 * REST API: CSV import.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Rest;

use MailPilot\IO\Csv;
use MailPilot\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports subscribers from an uploaded CSV.
 *
 * Route (namespace `mailpilot/v1`):
 *   POST /import/csv   Import a multipart-uploaded CSV file.
 *
 * Runs the same {@see Csv::import()} the legacy Subscribers screen uses and
 * returns the imported/skipped counts. Requires `manage_options` + the nonce.
 */
final class ImportController {

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
			'/import/csv',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import_csv' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);
	}

	/**
	 * Capability gate.
	 */
	public function authorize(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mailpilot_forbidden', __( 'You are not allowed to import subscribers.', 'mailpilot' ), [ 'status' => rest_authorization_required_code() ] );
		}

		return true;
	}

	/**
	 * POST /import/csv
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function import_csv( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'mailpilot_no_file', __( 'No CSV file was uploaded.', 'mailpilot' ), [ 'status' => 422 ] );
		}

		$name = (string) ( $file['name'] ?? '' );
		if ( '' !== $name && ! preg_match( '/\.csv$/i', $name ) ) {
			return new WP_Error( 'mailpilot_bad_type', __( 'Please upload a .csv file.', 'mailpilot' ), [ 'status' => 422 ] );
		}

		$csv    = new Csv( $this->plugin->subscribers() );
		$result = $csv->import( (string) $file['tmp_name'] );

		return new WP_REST_Response(
			[
				'imported' => (int) ( $result['imported'] ?? 0 ),
				'skipped'  => (int) ( $result['skipped'] ?? 0 ),
			],
			200
		);
	}
}
