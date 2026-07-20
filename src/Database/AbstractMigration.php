<?php
/**
 * Base migration with shared schema helpers.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convenience base class for migrations.
 *
 * Centralises table naming and charset/collation so concrete migrations only
 * describe the column definitions.
 */
abstract class AbstractMigration implements Migration {

	/**
	 * Fully-qualified table name, including the site prefix and the
	 * `mailpilot_` namespace (e.g. `wp_mailpilot_subscribers`).
	 *
	 * @param string $name Table name without any prefix (e.g. `subscribers`).
	 */
	protected function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . MAILPILOT_TABLE_PREFIX . $name;
	}

	/**
	 * Charset/collation clause for CREATE TABLE statements.
	 */
	protected function charset_collate(): string {
		global $wpdb;

		return $wpdb->get_charset_collate();
	}

	/**
	 * Create a table from a column/index body using dbDelta.
	 *
	 * @param string $name Unprefixed table name.
	 * @param string $body Column and index definitions (no surrounding parens).
	 */
	protected function create_table( string $name, string $body ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = $this->table( $name );
		$sql   = "CREATE TABLE {$table} (\n{$body}\n) {$this->charset_collate()};";

		dbDelta( $sql );
	}

	/**
	 * Drop a table if it exists.
	 *
	 * @param string $name Unprefixed table name.
	 */
	protected function drop_table( string $name ): void {
		global $wpdb;

		$table = $this->table( $name );
		// Table identifiers cannot be parameterised; the name is built from
		// internal constants, never user input.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant, never user input.
	}
}
