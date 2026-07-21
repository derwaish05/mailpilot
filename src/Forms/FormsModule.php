<?php
/**
 * Forms module wiring.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Forms;

use MailPilot\Analytics\Analytics;
use MailPilot\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composition root for the Form Builder: owns the repository/renderer and
 * registers the capture surfaces (assets, shortcode, block, submission). Also
 * the entry point behind the global `mailpilot_form()` template function.
 */
final class FormsModule {

	private FormRepository $repository;
	private FormRenderer $renderer;
	private Analytics $analytics;

	public function __construct( private Plugin $plugin ) {
		$this->repository = new FormRepository();
		$this->renderer   = new FormRenderer();
		$this->analytics  = new Analytics();
	}

	/**
	 * The form repository.
	 */
	public function repository(): FormRepository {
		return $this->repository;
	}

	/**
	 * Register all form-related hooks.
	 */
	public function register_hooks(): void {
		( new FormAssets() )->register_hooks();
		( new Shortcode( $this ) )->register_hooks();
		( new Block( $this ) )->register_hooks();
		( new PositionedFormInjector( $this->repository, $this->renderer, $this->analytics ) )->register_hooks();
		( new FormSubmissionHandler( $this->plugin, $this->repository, $this->analytics ) )->register_hooks();
	}

	/**
	 * Render a published form by id, optionally counting a view.
	 *
	 * Shared by the shortcode, block, and PHP template function so all three
	 * produce identical markup.
	 *
	 * @param int    $id          Form id.
	 * @param bool   $count_view  Whether to record a view for analytics.
	 * @param string $attribution Source surface for analytics attribution.
	 */
	public function render_form( int $id, bool $count_view = true, string $attribution = 'form' ): string {
		$form = $this->repository->find( $id );

		if ( null === $form ) {
			return $this->editor_hint(
				$id > 0
					/* translators: %d: form id. */
					? sprintf( __( 'MailPilot: form #%d was not found.', 'brainstudioz-mailpilot' ), $id )
					: __( 'MailPilot: no form selected.', 'brainstudioz-mailpilot' )
			);
		}

		if ( 'published' !== $form->status ) {
			return $this->editor_hint(
				sprintf(
					/* translators: %s: form title. */
					__( 'MailPilot: the form “%s” is a draft. Set its status to Published in the form builder to display it here.', 'brainstudioz-mailpilot' ),
					$form->title ?: sprintf(
					/* translators: %d: form id. */
					__( 'Form #%d', 'brainstudioz-mailpilot' ),
					(int) $form->id
				)
				)
			);
		}

		if ( $count_view ) {
			$this->analytics->increment( 'views', 1, $attribution, $id );
		}

		return $this->renderer->render( $form, $attribution );
	}

	/**
	 * A notice shown only to users who can edit content, so an embedded form that
	 * cannot render (missing or unpublished) explains itself to the site builder
	 * while staying invisible to public visitors.
	 *
	 * @param string $message Human-readable explanation.
	 */
	private function editor_hint( string $message ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		return '<div class="mailpilot-form-notice" style="padding:12px 14px;border:1px dashed #c3c4c7;border-radius:6px;color:#646970;font-size:14px">'
			. esc_html( $message )
			. '</div>';
	}

	/**
	 * Render an inline-defined form (e.g. fields built in the Elementor widget).
	 *
	 * Inline fields are persisted to a managed `wp_mailpilot_forms` row so submissions
	 * flow through the normal pipeline. See `ensure_inline_form()` for how that
	 * row's identity is resolved and reused across renders/edits.
	 *
	 * @param array<int, Field>    $fields      Inline field definitions.
	 * @param array<string, mixed> $settings    Form settings/actions.
	 * @param bool                 $count_view  Whether to record a view.
	 * @param string               $attribution Analytics attribution surface.
	 */
	public function render_inline( array $fields, array $settings = [], bool $count_view = true, string $attribution = 'elementor' ): string {
		$form = $this->ensure_inline_form( $fields, $settings );

		if ( $count_view ) {
			$this->analytics->increment( 'views', 1, $attribution, (int) $form->id );
		}

		return $this->renderer->render( $form, $attribution );
	}

	/**
	 * Find or create the backing form for an inline field definition.
	 *
	 * Identity is keyed by the owning widget's stable id (`elementor_id`) when
	 * the caller provides one — e.g. the Elementor widget passes its own
	 * element id, which does not change between edits. That means every
	 * re-render of the same widget (including the many live-preview renders
	 * Elementor fires while a user is still editing content) updates the one
	 * row it owns in place, rather than inserting a new `wp_mailpilot_forms` row per
	 * edit. Callers with no stable id fall back to the legacy content-hash
	 * lookup, which is still recorded on every row for reference.
	 *
	 * @param array<int, Field>    $fields   Inline fields.
	 * @param array<string, mixed> $settings Settings/actions. `elementor_id`,
	 *                                       if present, is the stable owner id.
	 */
	private function ensure_inline_form( array $fields, array $settings ): Form {
		$definition = wp_json_encode( array_map( static fn ( Field $f ): array => $f->toArray(), $fields ) ) . wp_json_encode( $settings );
		$hash       = md5( (string) $definition );

		$elementor_id = (string) ( $settings['elementor_id'] ?? '' );

		$existing = '' !== $elementor_id
			? $this->repository->find_by_elementor_id( $elementor_id )
			: $this->repository->find_by_inline_hash( $hash );

		$title = (string) ( $settings['title'] ?? ( $existing?->title ?? __( 'Inline form', 'brainstudioz-mailpilot' ) ) );

		$managed_settings = array_merge(
			$settings,
			[
				'inline_hash' => $hash,
				'managed'     => true,
			]
		);
		if ( '' !== $elementor_id ) {
			$managed_settings['elementor_id'] = $elementor_id;
		}

		$form = new Form(
			id: $existing?->id,
			title: $title,
			status: 'published',
			display_type: (string) ( $settings['display_type'] ?? 'inline' ),
			fields: $fields,
			actions: (array) ( $settings['actions'] ?? [] ),
			settings: $managed_settings,
		);

		// Skip the write entirely when nothing actually changed since the
		// last save — keeps `updated_at` meaningful and avoids a DB write on
		// every no-op re-render.
		if ( null !== $existing && $existing->settings['inline_hash'] === $hash && $existing->title === $title ) {
			return $existing;
		}

		$this->repository->save( $form );

		return $form;
	}
}
