<?php
/**
 * Registrable contract.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anything that can be placed in a core service registry — providers, plugin
 * integrations, routing conditions/actions, and Pro modules.
 *
 * This is part of the core's public, versioned extension API (see
 * EXTENSION-POINTS.md). Changing it is a breaking change for the Pro add-on.
 */
interface Registrable {

	/**
	 * Unique, stable identifier within its registry (e.g. `mailchimp`,
	 * `amount_spent`, `woocommerce`). Used as the registry key.
	 */
	public function id(): string;

	/**
	 * Human-readable label for admin UI.
	 */
	public function label(): string;
}
