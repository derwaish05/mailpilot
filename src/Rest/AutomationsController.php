<?php
/**
 * REST API: automations (webhooks + rules).
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Rest;

use MailPilot\Automations\AutomationsRepository;
use MailPilot\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists webhooks + automation rules and receives incoming webhooks.
 *
 * Routes (namespace `mailpilot/v1`):
 *   POST /automations/webhooks           Replace all webhooks (admin).
 *   POST /automations/rules              Replace all automation rules (admin).
 *   POST /automations/incoming/{secret}  Create a subscriber from an external
 *                                        system (public, secret-gated).
 */
final class AutomationsController {

	private const NAMESPACE = 'mailpilot/v1';

	public function __construct(
		private Plugin $plugin,
		private AutomationsRepository $repository
	) {}

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
			'/automations/webhooks',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save_webhooks' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/automations/rules',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save_rules' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/automations/incoming/(?P<secret>[a-zA-Z0-9_]+)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'incoming' ],
				'permission_callback' => '__return_true', // Gated by the URL secret.
			]
		);
	}

	/**
	 * Capability gate for admin routes.
	 */
	public function authorize(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mailpilot_forbidden', __( 'You are not allowed to manage automations.', 'brainstudioz-mailpilot' ), [ 'status' => rest_authorization_required_code() ] );
		}

		return true;
	}

	/**
	 * POST /automations/webhooks
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_webhooks( WP_REST_Request $request ): WP_REST_Response {
		$params   = $request->get_json_params() ?: $request->get_params();
		$webhooks = is_array( $params['webhooks'] ?? null ) ? $params['webhooks'] : [];
		$this->repository->save_webhooks( $webhooks );

		return new WP_REST_Response( [ 'saved' => true, 'webhooks' => $this->repository->webhooks() ], 200 );
	}

	/**
	 * POST /automations/rules
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_rules( WP_REST_Request $request ): WP_REST_Response {
		$params      = $request->get_json_params() ?: $request->get_params();
		$automations = is_array( $params['automations'] ?? null ) ? $params['automations'] : [];
		$this->repository->save_automations( $automations );

		return new WP_REST_Response( [ 'saved' => true, 'automations' => $this->repository->automations() ], 200 );
	}

	/**
	 * POST /automations/incoming/{secret}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function incoming( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$secret  = (string) $request['secret'];
		$webhook = $this->repository->incoming_by_secret( $secret );
		if ( null === $webhook ) {
			return new WP_Error( 'mailpilot_invalid_hook', __( 'Unknown webhook.', 'brainstudioz-mailpilot' ), [ 'status' => 404 ] );
		}

		$params = $request->get_json_params() ?: $request->get_params();
		$email  = sanitize_email( (string) ( $params['email'] ?? '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'mailpilot_invalid_email', __( 'A valid email is required.', 'brainstudioz-mailpilot' ), [ 'status' => 422 ] );
		}

		try {
			$subscriber = $this->plugin->subscribers()->create(
				[
					'email'      => $email,
					'first_name' => isset( $params['first_name'] ) ? sanitize_text_field( (string) $params['first_name'] ) : null,
					'last_name'  => isset( $params['last_name'] ) ? sanitize_text_field( (string) $params['last_name'] ) : null,
					'phone'      => isset( $params['phone'] ) ? sanitize_text_field( (string) $params['phone'] ) : null,
					'company'    => isset( $params['company'] ) ? sanitize_text_field( (string) $params['company'] ) : null,
					'country'    => isset( $params['country'] ) ? sanitize_text_field( (string) $params['country'] ) : null,
					'source'     => 'webhook',
				]
			);
		} catch ( \Throwable $e ) {
			return new WP_Error( 'mailpilot_invalid', $e->getMessage(), [ 'status' => 422 ] );
		}

		if ( ! empty( $params['tags'] ) && is_array( $params['tags'] ) ) {
			$this->plugin->subscribers()->apply_tags( $subscriber, array_map( 'sanitize_text_field', $params['tags'] ) );
		}

		return new WP_REST_Response( [ 'ok' => true, 'id' => (int) $subscriber->id ], 201 );
	}
}
