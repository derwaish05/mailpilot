<?php
/**
 * Migration: activity and sync logging tables.
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
 * Creates `wp_mailpilot_activity_log` and `wp_mailpilot_sync_log`.
 */
final class CreateLoggingTables extends AbstractMigration {

	public function version(): int {
		return 3;
	}

	public function up(): void {
		$this->create_table(
			'activity_log',
			"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subscriber_id BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(60) NOT NULL,
			description TEXT NULL,
			context LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY subscriber_id (subscriber_id),
			KEY event_type (event_type),
			KEY created_at (created_at)"
		);

		$this->create_table(
			'sync_log',
			"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subscriber_id BIGINT UNSIGNED NOT NULL,
			provider VARCHAR(60) NOT NULL,
			action VARCHAR(40) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			message TEXT NULL,
			request LONGTEXT NULL,
			response LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY subscriber_id (subscriber_id),
			KEY provider (provider),
			KEY status (status),
			KEY created_at (created_at)"
		);
	}

	public function down(): void {
		$this->drop_table( 'sync_log' );
		$this->drop_table( 'activity_log' );
	}
}
