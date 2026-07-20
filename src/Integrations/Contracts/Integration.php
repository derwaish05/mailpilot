<?php
/**
 * Plugin integration contract.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Integrations\Contracts;

use MailPilot\Contracts\Registrable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A capture source — WordPress core events or a third-party plugin (forms,
 * ecommerce, membership, …). Every integration emits normalized events into
 * the Subscriber Engine (ADR-001).
 *
 * Part of the core's public, versioned extension API: Pro registers premium
 * integrations through `mailpilot_register_integrations`.
 */
interface Integration extends Registrable {

	/**
	 * Whether the host plugin/feature is present and active.
	 *
	 * Integrations must be a graceful no-op when their host is inactive.
	 */
	public function is_available(): bool;

	/**
	 * Whether the site owner has enabled this integration.
	 */
	public function is_enabled(): bool;

	/**
	 * Hook into the host so its events flow into the engine. Called only when
	 * the integration is available and enabled.
	 */
	public function register(): void;
}
