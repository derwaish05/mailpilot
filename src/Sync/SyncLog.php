<?php
/**
 * Provider sync log.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Sync;

use MailPilot\Providers\SyncResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records every provider sync attempt and its result in `wp_mailpilot_sync_log`, so
 * local ↔ provider state can be reconciled (treated as eventually consistent).
 */
final class SyncLog {

	/**
	 * Fully-qualified table name.
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . MAILPILOT_TABLE_PREFIX . 'sync_log';
	}

	/**
	 * Record a sync attempt.
	 *
	 * @param int                  $subscriber_id Subscriber id.
	 * @param string               $provider      Provider slug.
	 * @param string               $action        Sync action (e.g. `upsert`, `delete`).
	 * @param SyncResult           $result        Normalised result.
	 * @param int                  $attempt       Attempt number.
	 * @param array<string, mixed> $request       Request context (no secrets).
	 */
	public function record( int $subscriber_id, string $provider, string $action, SyncResult $result, int $attempt = 1, array $request = [] ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			[
				'subscriber_id' => $subscriber_id,
				'provider'      => $provider,
				'action'        => $action,
				'status'        => $result->success ? 'success' : ( $result->retryable ? 'retrying' : 'failed' ),
				'attempts'      => $attempt,
				'message'       => $result->message,
				'request'       => $request ? wp_json_encode( $request ) : null,
				'response'      => $result->data ? wp_json_encode( $result->data ) : null,
				'created_at'    => $now,
				'updated_at'    => $now,
			],
			[ '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Recent sync rows for a subscriber.
	 *
	 * @param int $subscriber_id Subscriber id.
	 * @param int $limit         Max rows.
	 * @return array<int, object>
	 */
	public function for_subscriber( int $subscriber_id, int $limit = 50 ): array {
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
}
