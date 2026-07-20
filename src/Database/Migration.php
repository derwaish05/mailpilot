<?php
/**
 * Migration contract.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single, reversible schema change.
 */
interface Migration {

	/**
	 * Monotonic version number. Migrations run in ascending order.
	 */
	public function version(): int;

	/**
	 * Apply the change.
	 */
	public function up(): void;

	/**
	 * Reverse the change.
	 */
	public function down(): void;
}
