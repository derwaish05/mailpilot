<?php
/**
 * Front-end auto-injection for fixed-position form types in Free.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Forms;

use MailPilot\Analytics\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-displays "free-positioned" forms (floating bar, slide in) site-wide via
 * `wp_footer`, honouring their "Show on" targeting rules.
 *
 * These display types need no trigger engine to make sense — they are "always
 * visible until dismissed" — so, unlike popup / full screen (which require
 * MailPilot Pro's Popups module for their trigger/frequency/A-B logic), Free
 * can inject them for real. When Pro's Popups module is active it owns the
 * injection for every popup type (including these two), so this injector stands
 * down to avoid rendering the same form twice. See {@see DisplayTypeMode}.
 */
final class PositionedFormInjector {

	/**
	 * Memoised per-request list of forms eligible to inject, so the display
	 * rules are evaluated once and shared between enqueue and render.
	 *
	 * @var array<int, Form>|null
	 */
	private ?array $cache = null;

	public function __construct(
		private FormRepository $repository,
		private FormRenderer $renderer,
		private Analytics $analytics
	) {}

	/**
	 * Hook enqueue (so styles print in the head) and footer output.
	 */
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
		add_action( 'wp_footer', [ $this, 'render' ] );
	}

	/**
	 * Enqueue the form CSS/JS in the head when at least one positioned form is
	 * eligible for this request (the renderer also enqueues, but doing it here
	 * guarantees the stylesheet lands in `wp_head` rather than the footer).
	 */
	public function maybe_enqueue(): void {
		if ( ! $this->eligible_forms() ) {
			return;
		}

		if ( function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style( 'mailpilot-forms' );
			wp_enqueue_script( 'mailpilot-forms' );
		}
	}

	/**
	 * Output each eligible positioned form in the footer.
	 */
	public function render(): void {
		foreach ( $this->eligible_forms() as $form ) {
			$this->analytics->increment( 'views', 1, $form->display_type, (int) $form->id );
			// Markup is escaped inside FormRenderer::render().
			echo $this->renderer->render( $form, $form->display_type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Published floating-bar / slide-in forms whose display rules pass for the
	 * current request. Empty in the admin, or when Pro's Popups module is the
	 * one injecting popup-type forms.
	 *
	 * @return array<int, Form>
	 */
	private function eligible_forms(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		if ( is_admin() || $this->pro_popups_active() ) {
			$this->cache = [];
			return $this->cache;
		}

		$this->cache = array_values(
			array_filter(
				$this->repository->all(),
				fn ( Form $form ): bool =>
					'published' === $form->status
					&& DisplayTypeMode::gets_free_fallback_position( $form->display_type )
					&& $this->passes_display_rules( $form )
			)
		);

		return $this->cache;
	}

	/**
	 * Whether Pro's Popups module is active — in which case it, not Free, owns
	 * injection of popup-type forms (floating bar and slide in included).
	 * Mirrors {@see FormRenderer::pro_popups_active()}.
	 */
	private function pro_popups_active(): bool {
		return did_action( 'mailpilot_pro_booted' ) > 0 && mailpilot()->license()->can( 'popups' );
	}

	/**
	 * Evaluate a positioned form's "Show on" targeting (stored under the same
	 * `popup` settings key the builder writes) against the current request.
	 * A Free-side mirror of Pro's `DisplayRules::passes()` so the two engines
	 * agree on scope/include/term/product filtering.
	 *
	 * @param Form $form Form to test.
	 */
	private function passes_display_rules( Form $form ): bool {
		$rules = (array) $form->setting( 'popup', [] );
		$scope = (string) ( $rules['display'] ?? 'all' );

		$ok = match ( $scope ) {
			'front'    => is_front_page(),
			'posts'    => is_singular( 'post' ),
			'pages'    => is_page(),
			'products' => function_exists( 'is_product' ) ? is_product() : is_singular( 'product' ),
			default    => true,
		};

		if ( $ok && ! empty( $rules['include'] ) ) {
			$ok = is_singular() && in_array( get_queried_object_id(), array_map( 'intval', (array) $rules['include'] ), true );
		}

		if ( $ok && ! empty( $rules['categories'] ) ) {
			$ok = has_category( array_map( 'intval', (array) $rules['categories'] ) );
		}

		if ( $ok && ! empty( $rules['tags'] ) ) {
			$ok = has_tag( array_map( 'intval', (array) $rules['tags'] ) );
		}

		if ( $ok && ! empty( $rules['product_ids'] ) ) {
			$ok = is_singular( 'product' ) && in_array( get_queried_object_id(), array_map( 'intval', (array) $rules['product_ids'] ), true );
		}

		/**
		 * Filter whether a positioned (floating bar / slide in) form may display
		 * on the current request.
		 *
		 * @param bool $ok   Whether the display rules pass.
		 * @param Form $form The positioned form.
		 */
		return (bool) apply_filters( 'mailpilot_positioned_form_display', $ok, $form );
	}
}
