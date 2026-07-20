<?php
/**
 * Activity logging service.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Activity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records the per-subscriber activity timeline in `wp_mailpilot_activity_log`.
 *
 * Entries are buffered in memory and flushed once on `shutdown`, after the
 * response is sent, so logging never adds latency to the main request.
 */
final class ActivityLogger {

	/**
	 * Pending log rows awaiting flush.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $buffer = [];

	/**
	 * Whether the shutdown flush has been registered.
	 *
	 * @var bool
	 */
	private bool $hooked = false;

	/**
	 * Record an activity event for a subscriber.
	 *
	 * @param int                  $subscriber_id Subscriber id.
	 * @param Event                $event         Event type.
	 * @param string               $description   Short human-readable description.
	 * @param array<string, mixed> $context       Structured context (JSON-encoded).
	 */
	public function log( int $subscriber_id, Event $event, string $description = '', array $context = [] ): void {
		$this->buffer[] = [
			'subscriber_id' => $subscriber_id,
			'event_type'    => $event->value,
			'description'   => $description,
			'context'       => $context ? wp_json_encode( $context ) : null,
			'created_at'    => current_time( 'mysql', true ),
		];

		$this->ensure_flush_scheduled();
	}

	/**
	 * Retrieve a subscriber's timeline, newest first.
	 *
	 * Flushes any buffered rows first so the timeline is complete.
	 *
	 * @param int $subscriber_id Subscriber id.
	 * @param int $limit         Max rows.
	 * @return array<int, object>
	 */
	public function timeline( int $subscriber_id, int $limit = 100 ): array {
		$this->flush();

		global $wpdb;
		$table = $this->table();

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE subscriber_id = %d ORDER BY created_at DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/identifier from internal constant; values bound via prepare().
				$subscriber_id,
				max( 1, $limit )
			)
		) ?: [];
	}

	/**
	 * Persist all buffered rows.
	 */
	public function flush(): void {
		if ( ! $this->buffer ) {
			return;
		}

		global $wpdb;
		$table  = $this->table();
		$buffer = $this->buffer;
		// Clear first so a failure cannot loop on shutdown.
		$this->buffer = [];

		foreach ( $buffer as $row ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				$row,
				[ '%d', '%s', '%s', '%s', '%s' ]
			);
		}
	}

	/**
	 * Register the shutdown flush exactly once.
	 */
	private function ensure_flush_scheduled(): void {
		if ( $this->hooked ) {
			return;
		}

		$this->hooked = true;

		if ( function_exists( 'add_action' ) ) {
			add_action( 'shutdown', [ $this, 'flush' ], 100 );
		}
	}

	/**
	 * Fully-qualified table name.
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . MAILPILOT_TABLE_PREFIX . 'activity_log';
	}
}
