<?php
/**
 * Migration: configuration tables (forms, providers, automations, webhooks, analytics).
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
 * Creates the configuration and reporting tables.
 */
final class CreateConfigTables extends AbstractMigration {

	public function version(): int {
		return 4;
	}

	public function up(): void {
		$this->create_table(
			'forms',
			"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(190) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			display_type VARCHAR(40) NOT NULL DEFAULT 'inline',
			fields LONGTEXT NULL,
			actions LONGTEXT NULL,
			settings LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status (status)"
		);

		$this->create_table(
			'provider_connections',
			"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider VARCHAR(60) NOT NULL,
			label VARCHAR(190) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			credentials LONGTEXT NULL,
			settings LONGTEXT NULL,
			field_map LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY provider (provider),
			KEY status (status)"
		);

		$this->create_table(
			'automations',
			"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(190) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'inactive',
			trigger_type VARCHAR(60) NOT NULL,
			conditions LONGTEXT NULL,
			actions LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status (status),
			KEY trigger_type (trigger_type)"
		);

		$this->create_table(
			'webhooks',
			"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			direction VARCHAR(10) NOT NULL DEFAULT 'outgoing',
			event VARCHAR(60) NOT NULL,
			target_url TEXT NULL,
			secret VARCHAR(255) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			settings LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY direction (direction),
			KEY event (event),
			KEY status (status)"
		);

		$this->create_table(
			'analytics',
			"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			metric VARCHAR(60) NOT NULL,
			object_type VARCHAR(40) NULL,
			object_id BIGINT UNSIGNED NULL,
			value DECIMAL(18,4) NOT NULL DEFAULT 0,
			period_date DATE NOT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY metric_object_period (metric, object_type, object_id, period_date),
			KEY metric (metric),
			KEY period_date (period_date)"
		);
	}

	public function down(): void {
		$this->drop_table( 'analytics' );
		$this->drop_table( 'webhooks' );
		$this->drop_table( 'automations' );
		$this->drop_table( 'provider_connections' );
		$this->drop_table( 'forms' );
	}
}
