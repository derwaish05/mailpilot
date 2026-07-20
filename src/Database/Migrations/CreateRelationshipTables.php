<?php
/**
 * Migration: subscriber ↔ tag / list relationship tables.
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
 * Creates `wp_mailpilot_subscriber_tags` and `wp_mailpilot_subscriber_lists`.
 */
final class CreateRelationshipTables extends AbstractMigration {

	public function version(): int {
		return 2;
	}

	public function up(): void {
		$this->create_table(
			'subscriber_tags',
			"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subscriber_id BIGINT UNSIGNED NOT NULL,
			tag VARCHAR(190) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY subscriber_tag (subscriber_id, tag),
			KEY subscriber_id (subscriber_id),
			KEY tag (tag)"
		);

		$this->create_table(
			'subscriber_lists',
			"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subscriber_id BIGINT UNSIGNED NOT NULL,
			list_id VARCHAR(190) NOT NULL,
			provider VARCHAR(60) NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY subscriber_list (subscriber_id, list_id, provider),
			KEY subscriber_id (subscriber_id),
			KEY list_id (list_id)"
		);
	}

	public function down(): void {
		$this->drop_table( 'subscriber_lists' );
		$this->drop_table( 'subscriber_tags' );
	}
}
