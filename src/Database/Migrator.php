<?php
/**
 * Versioned migration runner.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Database;

use MailPilot\Database\Migrations\CreateSubscribersTable;
use MailPilot\Database\Migrations\CreateRelationshipTables;
use MailPilot\Database\Migrations\CreateLoggingTables;
use MailPilot\Database\Migrations\CreateConfigTables;
use MailPilot\Database\Migrations\CreateJobsTable;
use MailPilot\Database\Migrations\RenameTablesToMailpilotPrefix;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies and rolls back schema migrations idempotently.
 *
 * The applied schema version is stored in an option and compared on load, so
 * upgrading the plugin files is enough to bring the schema current.
 */
final class Migrator {

	/**
	 * Option key holding the currently-applied schema version.
	 */
	private const VERSION_OPTION = 'mailpilot_schema_version';

	/**
	 * Ordered list of migration class names.
	 *
	 * @var array<int, class-string<Migration>>
	 */
	private const MIGRATIONS = [
		CreateSubscribersTable::class,
		CreateRelationshipTables::class,
		CreateLoggingTables::class,
		CreateConfigTables::class,
		CreateJobsTable::class,
		RenameTablesToMailpilotPrefix::class,
	];

	/**
	 * The schema version applied so far (0 if never run).
	 */
	public function current_version(): int {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * The highest version available in code.
	 */
	public function latest_version(): int {
		$versions = array_map( static fn ( string $class ): int => ( new $class() )->version(), self::MIGRATIONS );

		return $versions ? max( $versions ) : 0;
	}

	/**
	 * Run any migrations newer than the applied version.
	 *
	 * Idempotent: migrations already at or below the stored version are skipped.
	 */
	public function migrate(): void {
		$applied = $this->current_version();

		foreach ( $this->ordered() as $migration ) {
			if ( $migration->version() <= $applied ) {
				continue;
			}

			$migration->up();
			update_option( self::VERSION_OPTION, $migration->version(), false );
		}
	}

	/**
	 * Run migrations only when the stored version is behind code.
	 */
	public function maybe_upgrade(): void {
		if ( $this->current_version() < $this->latest_version() ) {
			$this->migrate();
		}
	}

	/**
	 * Roll back down to (but not including) a target version.
	 *
	 * @param int $target Version to roll back to. Default 0 (everything).
	 */
	public function rollback( int $target = 0 ): void {
		$applied = $this->current_version();

		foreach ( array_reverse( $this->ordered() ) as $migration ) {
			if ( $migration->version() <= $target || $migration->version() > $applied ) {
				continue;
			}

			$migration->down();
		}

		update_option( self::VERSION_OPTION, $target, false );
	}

	/**
	 * Instantiate migrations sorted by ascending version.
	 *
	 * @return array<int, Migration>
	 */
	private function ordered(): array {
		$migrations = array_map( static fn ( string $class ): Migration => new $class(), self::MIGRATIONS );

		usort( $migrations, static fn ( Migration $a, Migration $b ): int => $a->version() <=> $b->version() );

		return $migrations;
	}
}
