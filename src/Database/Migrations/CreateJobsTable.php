<?php
/**
 * Migration: background jobs queue table.
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
 * Creates `wp_mailpilot_jobs`, the durable backing store for the background queue.
 */
final class CreateJobsTable extends AbstractMigration {

	public function version(): int {
		return 5;
	}

	public function up(): void {
		$this->create_table(
			'jobs',
			"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			hook VARCHAR(120) NOT NULL,
			payload LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
			available_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			reserved_at DATETIME NULL,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status_available (status, available_at),
			KEY hook (hook)"
		);
	}

	public function down(): void {
		$this->drop_table( 'jobs' );
	}
}
