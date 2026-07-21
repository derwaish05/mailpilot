<?php
/**
 * Elementor integration bootstrap.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Integrations\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires MailPilot into Elementor: a widget category, the form widget, and a
 * "MailPilot" submit action for Elementor Pro Forms.
 *
 * All hooks are Elementor's own, so they never fire when Elementor is inactive
 * — the integration is a graceful no-op with no errors in the editor.
 */
final class ElementorModule {

	/**
	 * Widget category slug.
	 */
	public const CATEGORY = 'mailpilot';

	/**
	 * Whether Elementor is active.
	 */
	public function is_available(): bool {
		return did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Register Elementor hooks. Cheap to add unconditionally; the callbacks run
	 * only when Elementor fires them.
	 */
	public function register_hooks(): void {
		add_action( 'elementor/elements/categories_registered', [ $this, 'register_category' ] );
		add_action( 'elementor/widgets/register', [ $this, 'register_widget' ] );
		add_action( 'elementor_pro/forms/actions/register', [ $this, 'register_form_action' ] );
	}

	/**
	 * Add the MailPilot widget category to the Elementor panel.
	 *
	 * @param object $elements_manager Elementor elements manager.
	 */
	public function register_category( $elements_manager ): void {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$elements_manager->add_category(
			self::CATEGORY,
			[
				'title' => __( 'MailPilot', 'brainstudioz-mailpilot' ),
				'icon'  => 'eicon-email-field',
			]
		);
	}

	/**
	 * Register the MailPilot form widget.
	 *
	 * @param object $widgets_manager Elementor widgets manager.
	 */
	public function register_widget( $widgets_manager ): void {
		// The widget class extends \Elementor\Widget_Base; only reference it
		// here, where Elementor is guaranteed to be loaded.
		if ( ! class_exists( '\Elementor\Widget_Base' ) || ! is_object( $widgets_manager ) ) {
			return;
		}

		if ( method_exists( $widgets_manager, 'register' ) ) {
			$widgets_manager->register( new FormWidget() );
		} elseif ( method_exists( $widgets_manager, 'register_widget_type' ) ) {
			$widgets_manager->register_widget_type( new FormWidget() );
		}
	}

	/**
	 * Register the "MailPilot" action for Elementor Pro Forms.
	 *
	 * @param object $registrar Elementor Pro form actions registrar.
	 */
	public function register_form_action( $registrar ): void {
		if ( ! class_exists( '\ElementorPro\Modules\Forms\Classes\Action_Base' ) || ! is_object( $registrar ) ) {
			return;
		}

		if ( method_exists( $registrar, 'register' ) ) {
			$registrar->register( new FormsAction() );
		}
	}
}
