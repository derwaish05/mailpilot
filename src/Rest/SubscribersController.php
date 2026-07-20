<?php
/**
 * REST API: subscribers.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Rest;

use MailPilot\Plugin;
use MailPilot\Subscribers\Subscriber;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes subscribers over the REST API.
 *
 * Routes (namespace `mailpilot/v1`):
 *   GET    /subscribers           List subscribers (filterable, paginated).
 *   POST   /subscribers           Create/upsert a subscriber.
 *   GET    /subscribers/{id}      Fetch a single subscriber.
 *   DELETE /subscribers/{id}      Delete a subscriber.
 *
 * Every route requires the `manage_options` capability and standard REST auth.
 */
final class SubscribersController {

	private const NAMESPACE = 'mailpilot/v1';
	private const BASE       = 'subscribers';

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
			'/' . self::BASE,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'index' ],
					'permission_callback' => [ $this, 'authorize' ],
					'args'                => [
						'search'   => [ 'sanitize_callback' => 'sanitize_text_field' ],
						'status'   => [ 'sanitize_callback' => 'sanitize_key' ],
						'source'   => [ 'sanitize_callback' => 'sanitize_key' ],
						'page'     => [
							'sanitize_callback' => 'absint',
							'default'           => 1,
						],
						'per_page' => [
							'sanitize_callback' => 'absint',
							'default'           => 20,
						],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create' ],
					'permission_callback' => [ $this, 'authorize' ],
					'args'                => [
						'email' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/bulk',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'bulk' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/sync',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'sync' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'show' ],
					'permission_callback' => [ $this, 'authorize' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => [ $this, 'authorize' ],
				],
			]
		);
	}

	/**
	 * POST /subscribers/bulk — apply a bulk action to many subscribers.
	 *
	 * Body: `{ action: delete|unsubscribe|add_tag|remove_tag, ids: number[], tag?: string }`.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function bulk( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params() ?: $request->get_params();
		$action = sanitize_key( (string) ( $params['action'] ?? '' ) );
		$ids    = array_values( array_filter( array_map( 'intval', (array) ( $params['ids'] ?? [] ) ) ) );
		$tag    = sanitize_text_field( (string) ( $params['tag'] ?? '' ) );

		if ( [] === $ids ) {
			return new WP_Error( 'mailpilot_invalid', __( 'No subscribers selected.', 'mailpilot' ), [ 'status' => 422 ] );
		}
		if ( in_array( $action, [ 'add_tag', 'remove_tag' ], true ) && '' === $tag ) {
			return new WP_Error( 'mailpilot_invalid', __( 'A tag is required.', 'mailpilot' ), [ 'status' => 422 ] );
		}

		$engine    = $this->plugin->subscribers();
		$repository = $this->plugin->subscriber_repository();
		$affected   = 0;

		foreach ( $ids as $id ) {
			$subscriber = $repository->find( $id );
			if ( null === $subscriber ) {
				continue;
			}

			switch ( $action ) {
				case 'delete':
					$engine->delete( $id );
					break;
				case 'unsubscribe':
					$engine->update( $id, [ 'status' => 'unsubscribed' ] );
					break;
				case 'add_tag':
					$engine->apply_tags( $subscriber, [ $tag ] );
					break;
				case 'remove_tag':
					$engine->remove_tags( $subscriber, [ $tag ] );
					break;
				default:
					return new WP_Error( 'mailpilot_invalid', __( 'Unknown bulk action.', 'mailpilot' ), [ 'status' => 422 ] );
			}

			++$affected;
		}

		return new WP_REST_Response( [ 'action' => $action, 'affected' => $affected ], 200 );
	}

	/**
	 * POST /subscribers/sync — queue provider syncs for existing subscribers.
	 *
	 * Body: `{ connection: int, ids?: number[], all?: bool, filters?: { search,
	 * status, source } }`. With `all`, every subscriber matching the (server-side)
	 * filters is synced, paging through the whole list; otherwise the given ids.
	 * Each contact is queued as an upsert to the chosen connection (unsyncable
	 * statuses — unsubscribed/blocked/bounced — are skipped by the sync service).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function sync( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params     = $request->get_json_params() ?: $request->get_params();
		$connection = (int) ( $params['connection'] ?? 0 );

		if ( null === $this->plugin->provider_connections()->find( $connection ) ) {
			return new WP_Error( 'mailpilot_invalid', __( 'Choose a provider connection to sync to.', 'mailpilot' ), [ 'status' => 422 ] );
		}

		$sync   = $this->plugin->sync();
		$repo   = $this->plugin->subscriber_repository();
		$queued = 0;

		if ( ! empty( $params['all'] ) ) {
			$filters = (array) ( $params['filters'] ?? [] );
			$page    = 1;

			do {
				$result = $repo->query(
					[
						'search'   => sanitize_text_field( (string) ( $filters['search'] ?? '' ) ),
						'status'   => sanitize_key( (string) ( $filters['status'] ?? '' ) ),
						'source'   => sanitize_key( (string) ( $filters['source'] ?? '' ) ),
						'page'     => $page,
						'per_page' => 100,
					]
				);

				foreach ( $result['items'] as $subscriber ) {
					$sync->dispatch( $subscriber, [ $connection ] );
					++$queued;
				}

				++$page;
				$more = ( ( $page - 1 ) * 100 ) < (int) $result['total'];
			} while ( $more && $page < 1000 ); // Hard cap: up to 100k subscribers per call.
		} else {
			$ids = array_values( array_filter( array_map( 'intval', (array) ( $params['ids'] ?? [] ) ) ) );
			if ( [] === $ids ) {
				return new WP_Error( 'mailpilot_invalid', __( 'No subscribers selected.', 'mailpilot' ), [ 'status' => 422 ] );
			}

			foreach ( $ids as $id ) {
				$subscriber = $repo->find( $id );
				if ( null !== $subscriber ) {
					$sync->dispatch( $subscriber, [ $connection ] );
					++$queued;
				}
			}
		}

		return new WP_REST_Response( [ 'connection' => $connection, 'queued' => $queued ], 200 );
	}

	/**
	 * Capability + auth gate for all routes.
	 */
	public function authorize(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mailpilot_forbidden', __( 'You are not allowed to manage subscribers.', 'mailpilot' ), [ 'status' => rest_authorization_required_code() ] );
		}

		return true;
	}

	/**
	 * GET /subscribers
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->plugin->subscriber_repository()->query(
			[
				'search'   => (string) $request->get_param( 'search' ),
				'status'   => (string) $request->get_param( 'status' ),
				'source'   => (string) $request->get_param( 'source' ),
				'page'     => max( 1, (int) $request->get_param( 'page' ) ),
				'per_page' => min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) ),
			]
		);

		$data = array_map( [ $this, 'to_array' ], $result['items'] );

		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'X-WP-Total', (string) $result['total'] );

		return $response;
	}

	/**
	 * POST /subscribers
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params() ?: $request->get_params();

		try {
			$subscriber = $this->plugin->subscribers()->create(
				[
					'email'      => sanitize_email( (string) ( $params['email'] ?? '' ) ),
					'first_name' => isset( $params['first_name'] ) ? sanitize_text_field( (string) $params['first_name'] ) : null,
					'last_name'  => isset( $params['last_name'] ) ? sanitize_text_field( (string) $params['last_name'] ) : null,
					'phone'      => isset( $params['phone'] ) ? sanitize_text_field( (string) $params['phone'] ) : null,
					'company'    => isset( $params['company'] ) ? sanitize_text_field( (string) $params['company'] ) : null,
					'country'    => isset( $params['country'] ) ? sanitize_text_field( (string) $params['country'] ) : null,
					'status'     => isset( $params['status'] ) ? sanitize_key( (string) $params['status'] ) : null,
					'source'     => 'api',
				]
			);
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'mailpilot_invalid', $e->getMessage(), [ 'status' => 422 ] );
		}

		// Apply tags if provided.
		if ( ! empty( $params['tags'] ) && is_array( $params['tags'] ) ) {
			$this->plugin->subscribers()->apply_tags( $subscriber, array_map( 'sanitize_text_field', $params['tags'] ) );
		}

		return new WP_REST_Response( $this->to_array( $subscriber ), 201 );
	}

	/**
	 * GET /subscribers/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$subscriber = $this->plugin->subscriber_repository()->find( (int) $request['id'] );

		if ( null === $subscriber ) {
			return new WP_Error( 'mailpilot_not_found', __( 'Subscriber not found.', 'mailpilot' ), [ 'status' => 404 ] );
		}

		return new WP_REST_Response( $this->to_array( $subscriber ), 200 );
	}

	/**
	 * DELETE /subscribers/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];

		if ( null === $this->plugin->subscriber_repository()->find( $id ) ) {
			return new WP_Error( 'mailpilot_not_found', __( 'Subscriber not found.', 'mailpilot' ), [ 'status' => 404 ] );
		}

		$this->plugin->subscribers()->delete( $id );

		return new WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	/**
	 * Serialise a subscriber for API output.
	 *
	 * @param Subscriber $subscriber Subscriber.
	 * @return array<string, mixed>
	 */
	private function to_array( Subscriber $subscriber ): array {
		return [
			'id'         => (int) $subscriber->id,
			'email'      => $subscriber->email,
			'first_name' => $subscriber->first_name,
			'last_name'  => $subscriber->last_name,
			'phone'      => $subscriber->phone,
			'company'    => $subscriber->company,
			'country'    => $subscriber->country,
			'status'     => $subscriber->status->value,
			'source'     => $subscriber->source->value,
			'tags'       => $this->plugin->relationships()->tags_for( (int) $subscriber->id ),
			'created_at' => $subscriber->created_at,
			'updated_at' => $subscriber->updated_at,
		];
	}
}
