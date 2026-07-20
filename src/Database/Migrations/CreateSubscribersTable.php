<?php
/**
 * Migration: core subscribers table.
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
 * Creates `wp_mailpilot_subscribers`, the source-of-truth subscriber record.
 *
 * @see doc/06-technical-spec.md
 */
final class CreateSubscribersTable extends AbstractMigration {

	public function version(): int {
		return 1;
	}

	public function up(): void {
		$this->create_table(
			'subscribers',
			"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL,
			first_name VARCHAR(190) NULL,
			last_name VARCHAR(190) NULL,
			phone VARCHAR(64) NULL,
			company VARCHAR(190) NULL,
			country CHAR(2) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			source VARCHAR(40) NOT NULL DEFAULT 'manual',
			ip_address VARCHAR(45) NULL,
			consent_at DATETIME NULL,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY status (status),
			KEY source (source),
			KEY country (country),
			KEY created_at (created_at)"
		);
	}

	public function down(): void {
		$this->drop_table( 'subscribers' );
	}
}
