<?php
/**
 * Activation lifecycle.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Activation;

use MailPilot\Database\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on plugin activation: migrations and default seeding.
 */
final class Activator {

	/**
	 * Activation entry point.
	 */
	public static function activate(): void {
		// Run pending migrations to bring the schema fully up to date.
		( new Migrator() )->migrate();

		// Seed defaults and record install metadata.
		self::seed_defaults();

		// Flush rewrite rules so any (future) REST/endpoint routes register.
		flush_rewrite_rules();
	}

	/**
	 * Seed first-run options without overwriting existing values.
	 */
	private static function seed_defaults(): void {
		if ( false === get_option( 'mailpilot_installed_at', false ) ) {
			add_option( 'mailpilot_installed_at', current_time( 'mysql', true ), '', false );
		}

		update_option( 'mailpilot_version', MAILPILOT_VERSION, false );
	}
}
