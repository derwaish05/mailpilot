<?php
/**
 * Migration: rename legacy `wp_nh_*` tables to the `wp_mailpilot_*` prefix.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Database\Migrations;

use MailPilot\Database\AbstractMigration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renames tables created under the old `nh_` namespace to the MailPilot-specific
 * `mailpilot_` prefix (e.g. `wp_nh_subscribers` → `wp_mailpilot_subscribers`).
 *
 * Runs after the create migrations (versions 1–5). On a fresh install the new
 * tables already exist and no `wp_nh_*` tables are present, so every rename is
 * skipped — the migration is a no-op. On an upgrade from a pre-rename install,
 * each legacy table is renamed in place, preserving all data with no drop/copy.
 */
final class RenameTablesToMailpilotPrefix extends AbstractMigration {

	/**
	 * The old, pre-MailPilot table prefix.
	 */
	private const LEGACY_PREFIX = 'nh_';

	/**
	 * Unprefixed table names to migrate.
	 *
	 * @var array<int, string>
	 */
	private const TABLES = [
		'subscribers',
		'subscriber_tags',
		'subscriber_lists',
		'activity_log',
		'sync_log',
		'forms',
		'provider_connections',
		'automations',
		'webhooks',
		'analytics',
		'jobs',
	];

	public function version(): int {
		return 6;
	}

	public function up(): void {
		$this->rename( self::LEGACY_PREFIX, MAILPILOT_TABLE_PREFIX );
	}

	public function down(): void {
		$this->rename( MAILPILOT_TABLE_PREFIX, self::LEGACY_PREFIX );
	}

	/**
	 * Rename every known table from one prefix to another, skipping any whose
	 * source is missing or whose destination already exists (never clobbers).
	 *
	 * @param string $from Source namespace prefix (e.g. `nh_`).
	 * @param string $to   Destination namespace prefix (e.g. `mailpilot_`).
	 */
	private function rename( string $from, string $to ): void {
		global $wpdb;

		if ( $from === $to ) {
			return;
		}

		foreach ( self::TABLES as $name ) {
			$src = $wpdb->prefix . $from . $name;
			$dst = $wpdb->prefix . $to . $name;

			if ( ! $this->table_exists( $src ) || $this->table_exists( $dst ) ) {
				continue;
			}

			// Identifiers cannot be parameterised; both names are built from
			// internal constants and a fixed allow-list, never user input.
			$wpdb->query( "RENAME TABLE `{$src}` TO `{$dst}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers built from internal constants and a fixed allow-list, never user input.
		}
	}

	/**
	 * Whether a fully-qualified table currently exists.
	 *
	 * @param string $table Fully-qualified table name.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $found === $table;
	}
}
