<?php
/**
 * REST API: forms (used by the drag-and-drop builder).
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Rest;

use MailPilot\Forms\Field;
use MailPilot\Forms\FieldType;
use MailPilot\Forms\Form;
use MailPilot\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists forms for the builder UI.
 *
 * Routes (namespace `mailpilot/v1`):
 *   GET  /forms/{id}   Fetch a form definition.
 *   POST /forms        Create a form.
 *   POST /forms/{id}   Update a form.
 *
 * All routes require `manage_options` and the standard REST nonce.
 */
final class FormsController {

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
			'/forms',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'show' ],
					'permission_callback' => [ $this, 'authorize' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'save' ],
					'permission_callback' => [ $this, 'authorize' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => [ $this, 'authorize' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<id>\d+)/status',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'set_status' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);
	}

	/**
	 * POST /forms/{id}/status — toggle a form's published state without
	 * touching its fields (used by the Popups screen publish/unpublish).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function set_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form = $this->plugin->forms()->repository()->find( (int) $request['id'] );
		if ( null === $form ) {
			return new WP_Error( 'mailpilot_not_found', __( 'Form not found.', 'mailpilot' ), [ 'status' => 404 ] );
		}

		$params       = $request->get_json_params() ?: $request->get_params();
		$form->status = 'published' === ( $params['status'] ?? '' ) ? 'published' : 'draft';
		$this->plugin->forms()->repository()->save( $form );

		return new WP_REST_Response( [ 'id' => (int) $form->id, 'status' => $form->status ], 200 );
	}

	/**
	 * Capability gate.
	 */
	public function authorize(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mailpilot_forbidden', __( 'You are not allowed to manage forms.', 'mailpilot' ), [ 'status' => rest_authorization_required_code() ] );
		}

		return true;
	}

	/**
	 * GET /forms/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form = $this->plugin->forms()->repository()->find( (int) $request['id'] );

		if ( null === $form ) {
			return new WP_Error( 'mailpilot_not_found', __( 'Form not found.', 'mailpilot' ), [ 'status' => 404 ] );
		}

		return new WP_REST_Response( $this->serialize( $form ), 200 );
	}

	/**
	 * POST /forms or /forms/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params() ?: $request->get_params();
		$id     = isset( $request['id'] ) ? (int) $request['id'] : (int) ( $params['id'] ?? 0 );

		$form = $id > 0
			? ( $this->plugin->forms()->repository()->find( $id ) ?? new Form() )
			: new Form();

		// A previously-saved popup design must survive edits that don't carry it
		// (e.g. the core field-only builder), so preserve it when absent.
		$existing_design = is_array( $form->settings['design'] ?? null ) ? $form->settings['design'] : null;

		$form->title        = sanitize_text_field( (string) ( $params['title'] ?? $form->title ) );
		$form->status       = 'published' === ( $params['status'] ?? '' ) ? 'published' : 'draft';
		$form->display_type = sanitize_key( (string) ( $params['display_type'] ?? 'inline' ) );
		$form->fields       = $this->parse_fields( (array) ( $params['fields'] ?? [] ) );
		$form->actions      = $this->parse_actions( (array) ( $params['actions'] ?? [] ) );
		$form->settings     = $this->parse_settings( (array) ( $params['settings'] ?? [] ) );

		if ( ! isset( $form->settings['design'] ) && null !== $existing_design ) {
			$form->settings['design'] = $existing_design;
		}

		$saved = $this->plugin->forms()->repository()->save( $form );

		return new WP_REST_Response( $this->serialize( $this->plugin->forms()->repository()->find( $saved ) ), 200 );
	}

	/**
	 * DELETE /forms/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];

		if ( null === $this->plugin->forms()->repository()->find( $id ) ) {
			return new WP_Error( 'mailpilot_not_found', __( 'Form not found.', 'mailpilot' ), [ 'status' => 404 ] );
		}

		$this->plugin->forms()->repository()->delete( $id );

		return new WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	/**
	 * Serialize a form for the builder.
	 *
	 * @param Form $form Form.
	 * @return array<string, mixed>
	 */
	private function serialize( Form $form ): array {
		return [
			'id'           => (int) $form->id,
			'title'        => $form->title,
			'status'       => $form->status,
			'display_type' => $form->display_type,
			'fields'       => array_map( static fn ( Field $f ): array => $f->toArray(), $form->fields ),
			'actions'      => $form->actions,
			'settings'     => $form->settings,
			'shortcode'    => sprintf( '[mailpilot_form id="%d"]', (int) $form->id ),
		];
	}

	/**
	 * Sanitize and build Field objects from builder input.
	 *
	 * @param array<int, array<string, mixed>> $rows Field rows.
	 * @return array<int, Field>
	 */
	private function parse_fields( array $rows ): array {
		$fields = [];

		foreach ( $rows as $row ) {
			$type  = FieldType::fromString( sanitize_key( (string) ( $row['type'] ?? '' ) ) );
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$key   = sanitize_key( (string) ( $row['key'] ?? '' ) ) ?: sanitize_key( $label ?: $type->value );

			$options = [];
			foreach ( (array) ( $row['options'] ?? [] ) as $opt ) {
				$value = sanitize_text_field( (string) ( $opt['value'] ?? '' ) );
				if ( '' === $value ) {
					continue;
				}
				$options[] = [
					'value' => $value,
					'label' => sanitize_text_field( (string) ( $opt['label'] ?? $value ) ),
				];
			}

			$conditional = null;
			if ( ! empty( $row['conditional']['field'] ) ) {
				$conditional = [
					'field'    => sanitize_key( (string) $row['conditional']['field'] ),
					'operator' => sanitize_key( (string) ( $row['conditional']['operator'] ?? 'is' ) ),
					'value'    => sanitize_text_field( (string) ( $row['conditional']['value'] ?? '' ) ),
				];
			}

			$fields[] = new Field(
				key: $key,
				type: $type,
				label: $label,
				required: ! empty( $row['required'] ),
				placeholder: sanitize_text_field( (string) ( $row['placeholder'] ?? '' ) ),
				default: sanitize_text_field( (string) ( $row['default'] ?? '' ) ),
				options: $options,
				validation: sanitize_key( (string) ( $row['validation'] ?? 'none' ) ),
				conditional: $conditional,
				name_mode: 'split' === ( $row['name_mode'] ?? 'single' ) ? 'split' : 'single',
			);
		}

		return $fields;
	}

	/**
	 * Sanitize form actions.
	 *
	 * @param array<string, mixed> $actions Raw actions.
	 * @return array<string, mixed>
	 */
	private function parse_actions( array $actions ): array {
		return [
			'apply_tags' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $actions['apply_tags'] ?? [] ) ) ) ),
			'providers'  => array_values( array_filter( array_map( 'intval', (array) ( $actions['providers'] ?? [] ) ) ) ),
			'webhook'    => esc_url_raw( (string) ( $actions['webhook'] ?? '' ) ),
			'redirect'   => esc_url_raw( (string) ( $actions['redirect'] ?? '' ) ),
			'lead_magnet' => (int) ( $actions['lead_magnet'] ?? 0 ),
		];
	}

	/**
	 * Sanitize form settings.
	 *
	 * @param array<string, mixed> $settings Raw settings.
	 * @return array<string, mixed>
	 */
	private function parse_settings( array $settings ): array {
		$clean = [
			'button_text'     => sanitize_text_field( (string) ( $settings['button_text'] ?? 'Subscribe' ) ),
			'success_message' => sanitize_text_field( (string) ( $settings['success_message'] ?? '' ) ),
			'double_opt_in'   => ! empty( $settings['double_opt_in'] ),

			// Form tab — General.
			'description' => sanitize_textarea_field( (string) ( $settings['description'] ?? '' ) ),
			'tags'         => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $settings['tags'] ?? [] ) ) ) ),
			'audience'     => sanitize_text_field( (string) ( $settings['audience'] ?? '' ) ),

			// Form tab — Notifications & Integrations.
			'notify'       => ! empty( $settings['notify'] ),
			'notify_email' => sanitize_email( (string) ( $settings['notify_email'] ?? '' ) ),

			// Form tab — Privacy & Spam.
			'gdpr'            => ! empty( $settings['gdpr'] ),
			'spam_protection' => in_array( $settings['spam_protection'] ?? 'off', [ 'off', 'honeypot', 'recaptcha' ], true )
				? $settings['spam_protection']
				: 'off',

			// Form tab — Custom Code. Never rendered unescaped anywhere but the
			// builder's own editor and the (capability-gated) frontend output;
			// stored as submitted — CSS/JS are inherently "raw code" fields a
			// site owner with manage_options already has the run of the site
			// via, matching how Settings already handles similarly-trusted input.
			'custom_css' => (string) ( $settings['custom_css'] ?? '' ),
			'custom_js'  => (string) ( $settings['custom_js'] ?? '' ),
		];

		// Popup behaviour (consumed by the Pro popup module).
		if ( ! empty( $settings['popup'] ) && is_array( $settings['popup'] ) ) {
			$popup    = $settings['popup'];
			$to_ids   = static fn ( $v ): array => array_values( array_filter( array_map( 'intval', (array) $v ) ) );
			$ab       = (array) ( $popup['ab_test'] ?? [] );

			$clean['popup'] = [
				'trigger'       => sanitize_key( (string) ( $popup['trigger'] ?? 'time_delay' ) ),
				'trigger_value' => sanitize_text_field( (string) ( $popup['trigger_value'] ?? '5' ) ),
				'frequency'     => sanitize_key( (string) ( $popup['frequency'] ?? 'daily' ) ),
				'display'       => sanitize_key( (string) ( $popup['display'] ?? 'all' ) ),
				'include'       => $to_ids( $popup['include'] ?? [] ),
				'categories'    => $to_ids( $popup['categories'] ?? [] ),
				'tags'          => $to_ids( $popup['tags'] ?? [] ),
				'product_ids'   => $to_ids( $popup['product_ids'] ?? [] ),
				'ab_test'       => [
					'enabled'   => ! empty( $ab['enabled'] ),
					'variant_b' => (int) ( $ab['variant_b'] ?? 0 ),
				],
			];
		}

		// Popup design tree (Pro). Core can't sanitise the element-aware tree,
		// so it delegates to a filter; without a handler (Pro inactive) the
		// design is left out here and preserved separately by save().
		if ( isset( $settings['design'] ) && is_array( $settings['design'] ) ) {
			/**
			 * Sanitise a popup design tree. Pro returns the validated tree.
			 *
			 * @param array<string,mixed>|null $clean Sanitised design (null if unhandled).
			 * @param array<string,mixed>      $raw   Raw design tree.
			 */
			$design = apply_filters( 'mailpilot_sanitize_form_design', null, $settings['design'] );
			if ( is_array( $design ) ) {
				$clean['design'] = $design;
			}
		}

		return $clean;
	}
}
