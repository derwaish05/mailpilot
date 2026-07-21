<?php
/**
 * Durable background job queue.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A database-backed queue processed by WP-Cron in bounded batches.
 *
 * Provider syncs and other long operations are enqueued here so user-facing
 * requests never block on third-party APIs (ADR-004). Failed jobs are retried
 * with exponential backoff and recorded for inspection.
 */
final class Queue {

	/**
	 * Cron hook that drives the worker.
	 */
	private const CRON_HOOK = 'mailpilot_process_queue';

	/**
	 * Custom cron schedule slug.
	 */
	private const SCHEDULE = 'mailpilot_every_minute';

	/**
	 * Jobs processed per worker tick.
	 */
	private const BATCH_SIZE = 20;

	/**
	 * Soft time budget per tick, in seconds, to avoid timeouts.
	 */
	private const TIME_BUDGET = 20;

	/**
	 * Register cron schedule, worker, and self-healing scheduling.
	 */
	public function register_hooks(): void {
		add_filter( 'cron_schedules', [ $this, 'add_schedule' ] );
		add_action( self::CRON_HOOK, [ $this, 'process' ] );
		add_action( 'init', [ $this, 'ensure_scheduled' ] );
	}

	/**
	 * Add a one-minute cron schedule.
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules Existing schedules.
	 * @return array<string, array{interval:int, display:string}>
	 */
	public function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = [
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every Minute (MailPilot)', 'brainstudioz-mailpilot' ),
		];

		return $schedules;
	}

	/**
	 * Ensure the worker is scheduled.
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Remove the scheduled worker (used on deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Enqueue a job.
	 *
	 * @param string               $hook         Action hook fired with the payload when the job runs.
	 * @param array<string, mixed> $payload      Arbitrary JSON-serialisable data.
	 * @param int                  $delay        Seconds to wait before the job is eligible.
	 * @param int                  $max_attempts Maximum attempts before the job is marked failed.
	 * @return int Inserted job id (0 on failure).
	 */
	public function push( string $hook, array $payload = [], int $delay = 0, int $max_attempts = 5 ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			[
				'hook'         => $hook,
				'payload'      => wp_json_encode( $payload ),
				'status'       => 'pending',
				'attempts'     => 0,
				'max_attempts' => max( 1, $max_attempts ),
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + max( 0, $delay ) ),
				'created_at'   => $now,
				'updated_at'   => $now,
			],
			[ '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Process a batch of due jobs.
	 */
	public function process(): void {
		$started = time();

		for ( $i = 0; $i < self::BATCH_SIZE; $i++ ) {
			if ( ( time() - $started ) >= self::TIME_BUDGET ) {
				break;
			}

			$job = $this->reserve();

			if ( null === $job ) {
				break;
			}

			$this->run( $job );
		}
	}

	/**
	 * Atomically reserve the next due job.
	 *
	 * @return object{id:int, hook:string, payload:string, attempts:int, max_attempts:int}|null
	 */
	private function reserve(): ?object {
		global $wpdb;

		$table = $this->table();
		$now   = current_time( 'mysql', true );

		// Find the oldest due, unreserved job.
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'pending' AND available_at <= %s ORDER BY available_at ASC, id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from internal constant; value bound below.
				$now
			)
		);

		if ( ! $row ) {
			return null;
		}

		// Claim it with a guarded update so concurrent workers cannot double-run.
		$claimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'reserved', reserved_at = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from internal constant; values bound below.
				$now,
				$now,
				$row->id
			)
		);

		if ( ! $claimed ) {
			return null;
		}

		$row->attempts = (int) $row->attempts + 1;

		return $row;
	}

	/**
	 * Run a reserved job, applying retry/backoff on failure.
	 *
	 * @param object $job Reserved job row.
	 */
	private function run( object $job ): void {
		$payload = json_decode( (string) $job->payload, true );
		$payload = is_array( $payload ) ? $payload : [];

		try {
			/**
			 * Fires when a queued job runs. Listeners do the actual work.
			 *
			 * @param array<string, mixed> $payload Job payload.
			 */
			do_action( (string) $job->hook, $payload ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- dispatches internally-enqueued job hooks, all mailpilot_-prefixed at push time.

			$this->complete( (int) $job->id );
		} catch ( \Throwable $e ) {
			$this->fail( $job, $e->getMessage() );
		}
	}

	/**
	 * Mark a job complete and remove it.
	 *
	 * @param int $id Job id.
	 */
	private function complete( int $id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), [ 'id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Handle a failed attempt: reschedule with backoff or mark permanently failed.
	 *
	 * @param object $job   Job row.
	 * @param string $error Error message.
	 */
	private function fail( object $job, string $error ): void {
		global $wpdb;

		$now      = current_time( 'mysql', true );
		$attempts = (int) $job->attempts;

		if ( $attempts >= (int) $job->max_attempts ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$this->table(),
				[
					'status'      => 'failed',
					'last_error'  => $error,
					'reserved_at' => null,
					'updated_at'  => $now,
				],
				[ 'id' => (int) $job->id ],
				[ '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);

			return;
		}

		// Exponential backoff: 2^attempts minutes, capped at 1 hour.
		$backoff = min( HOUR_IN_SECONDS, ( 2 ** $attempts ) * MINUTE_IN_SECONDS );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			[
				'status'       => 'pending',
				'last_error'   => $error,
				'reserved_at'  => null,
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + $backoff ),
				'updated_at'   => $now,
			],
			[ 'id' => (int) $job->id ],
			[ '%s', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Count jobs in a given status.
	 *
	 * @param string $status Status to count.
	 */
	public function count( string $status = 'pending' ): int {
		global $wpdb;

		$table = $this->table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from internal constant; value bound.
		);
	}

	/**
	 * Fully-qualified jobs table name.
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . MAILPILOT_TABLE_PREFIX . 'jobs';
	}
}
