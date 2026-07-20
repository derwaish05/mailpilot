<?php
/**
 * Integration manager.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Integrations;

use MailPilot\Integrations\Contracts\Integration;
use MailPilot\Integrations\FormPlugins\ContactForm7;
use MailPilot\Integrations\FormPlugins\FluentForms;
use MailPilot\Integrations\FormPlugins\FormidableForms;
use MailPilot\Integrations\FormPlugins\GravityForms;
use MailPilot\Integrations\FormPlugins\JetFormBuilder;
use MailPilot\Integrations\FormPlugins\NinjaForms;
use MailPilot\Integrations\FormPlugins\WPForms;
use MailPilot\Integrations\WordPress\Comments;
use MailPilot\Integrations\WordPress\Registration;
use MailPilot\Registry\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the free-core integrations into the integration registry and boots
 * the ones that are both available (host active) and enabled. Pro adds its
 * integrations through the same `mailpilot_register_integrations` hook.
 */
final class IntegrationManager {

	/**
	 * Register the registration hook and the boot pass.
	 */
	public function register_hooks(): void {
		add_action( 'mailpilot_register_integrations', [ $this, 'register_core' ] );
		// Boot late so host plugins have loaded and declared their hooks.
		add_action( 'init', [ $this, 'boot' ], 20 );
	}

	/**
	 * Register the free-core integrations.
	 *
	 * @param Registry $registry Integration registry.
	 */
	public function register_core( Registry $registry ): void {
		$registry->add( new Comments() );
		$registry->add( new Registration() );
		$registry->add( new ContactForm7() );
		$registry->add( new WPForms() );
		$registry->add( new GravityForms() );
		$registry->add( new NinjaForms() );
		$registry->add( new FluentForms() );
		$registry->add( new FormidableForms() );
		$registry->add( new JetFormBuilder() );
	}

	/**
	 * Boot available, enabled integrations.
	 */
	public function boot(): void {
		foreach ( mailpilot()->integrations()->all() as $integration ) {
			if ( ! $integration instanceof Integration ) {
				continue;
			}

			if ( $integration->is_available() && $integration->is_enabled() ) {
				$integration->register();
			}
		}
	}
}
