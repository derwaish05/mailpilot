<?php
/**
 * Deactivation lifecycle.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Activation;

use MailPilot\Queue\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on plugin deactivation: clear scheduled work.
 *
 * Deactivation is non-destructive — data and schema are preserved. Removal is
 * handled by uninstall.php only when the user opts in.
 */
final class Deactivator {

	/**
	 * Deactivation entry point.
	 */
	public static function deactivate(): void {
		// Stop the background worker.
		Queue::unschedule();

		flush_rewrite_rules();
	}
}
