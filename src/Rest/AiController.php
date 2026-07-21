<?php
/**
 * REST API: AI assistant.
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
 * Proxies prompts to the configured AI provider via the Pro AiClient.
 *
 * Route (namespace `mailpilot/v1`):
 *   POST /ai/complete   Complete a prompt.
 *
 * The AI feature lives in MailPilot Pro; this endpoint is a thin, capability-
 * gated proxy that returns a helpful error when Pro or a key is missing.
 */
final class AiController {

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
			'/ai/complete',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'complete' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);
	}

	/**
	 * Capability gate.
	 */
	public function authorize(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mailpilot_forbidden', __( 'You are not allowed to use the assistant.', 'brainstudioz-mailpilot' ), [ 'status' => rest_authorization_required_code() ] );
		}

		return true;
	}

	/**
	 * POST /ai/complete
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function complete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params() ?: $request->get_params();
		$prompt = trim( (string) ( $params['prompt'] ?? '' ) );

		if ( '' === $prompt ) {
			return new WP_Error( 'mailpilot_invalid', __( 'A prompt is required.', 'brainstudioz-mailpilot' ), [ 'status' => 422 ] );
		}

		$client_class = '\MailPilot\Pro\Modules\Ai\AiClient';
		if ( ! class_exists( $client_class ) ) {
			return new WP_Error( 'mailpilot_ai_unavailable', __( 'The AI assistant requires MailPilot Pro.', 'brainstudioz-mailpilot' ), [ 'status' => 400 ] );
		}

		$client = new $client_class();
		if ( ! $client->is_configured() ) {
			return new WP_Error( 'mailpilot_ai_unconfigured', __( 'Add an AI provider and API key in Settings first.', 'brainstudioz-mailpilot' ), [ 'status' => 400 ] );
		}

		$max_tokens = min( 1000, max( 64, (int) ( $params['max_tokens'] ?? 700 ) ) );

		try {
			$reply = (string) $client->complete( $prompt, $max_tokens );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'mailpilot_ai_error', $e->getMessage(), [ 'status' => 502 ] );
		}

		return new WP_REST_Response( [ 'reply' => $reply ], 200 );
	}
}
