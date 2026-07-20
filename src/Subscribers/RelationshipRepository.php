<?php
/**
 * Subscriber tag/list relationship data access.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Subscribers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages rows in `wp_mailpilot_subscriber_tags` and `wp_mailpilot_subscriber_lists`.
 *
 * Uniqueness is enforced both by table indexes and by INSERT IGNORE so
 * applying the same tag/list twice is a safe no-op. Not `final` so tests can
 * substitute an in-memory double.
 */
class RelationshipRepository {

	/**
	 * Tags table.
	 */
	private function tags_table(): string {
		global $wpdb;

		return $wpdb->prefix . MAILPILOT_TABLE_PREFIX . 'subscriber_tags';
	}

	/**
	 * Lists table.
	 */
	private function lists_table(): string {
		global $wpdb;

		return $wpdb->prefix . MAILPILOT_TABLE_PREFIX . 'subscriber_lists';
	}

	/**
	 * Add a tag to a subscriber. Returns true when a new row was created.
	 *
	 * @param int    $subscriber_id Subscriber id.
	 * @param string $tag           Tag name.
	 */
	public function add_tag( int $subscriber_id, string $tag ): bool {
		global $wpdb;

		$tag = trim( $tag );
		if ( '' === $tag ) {
			return false;
		}

		$table = $this->tags_table();

		// INSERT IGNORE keeps the unique (subscriber_id, tag) index authoritative.
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (subscriber_id, tag, created_at) VALUES (%d, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/identifier from internal constant; values bound via prepare().
				$subscriber_id,
				$tag,
				current_time( 'mysql', true )
			)
		);

		return (int) $result > 0;
	}

	/**
	 * Remove a tag from a subscriber. Returns true when a row was deleted.
	 *
	 * @param int    $subscriber_id Subscriber id.
	 * @param string $tag           Tag name.
	 */
	public function remove_tag( int $subscriber_id, string $tag ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->tags_table(),
			[
				'subscriber_id' => $subscriber_id,
				'tag'           => trim( $tag ),
			],
			[ '%d', '%s' ]
		);
	}

	/**
	 * All tags for a subscriber.
	 *
	 * @param int $subscriber_id Subscriber id.
	 * @return array<int, string>
	 */
	public function tags_for( int $subscriber_id ): array {
		global $wpdb;

		$table = $this->tags_table();

		$tags = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare( "SELECT tag FROM {$table} WHERE subscriber_id = %d ORDER BY tag ASC", $subscriber_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/identifier from internal constant; values bound via prepare().
		);

		return array_map( 'strval', $tags ?: [] );
	}

	/**
	 * Add a subscriber to a provider list. Returns true when a new row was created.
	 *
	 * @param int         $subscriber_id Subscriber id.
	 * @param string      $list_id       Provider list identifier.
	 * @param string|null $provider      Provider slug.
	 */
	public function add_list( int $subscriber_id, string $list_id, ?string $provider = null ): bool {
		global $wpdb;

		$list_id = trim( $list_id );
		if ( '' === $list_id ) {
			return false;
		}

		$table = $this->lists_table();

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (subscriber_id, list_id, provider, created_at) VALUES (%d, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/identifier from internal constant; values bound via prepare().
				$subscriber_id,
				$list_id,
				(string) $provider,
				current_time( 'mysql', true )
			)
		);

		return (int) $result > 0;
	}

	/**
	 * Remove a subscriber from a list.
	 *
	 * @param int         $subscriber_id Subscriber id.
	 * @param string      $list_id       Provider list identifier.
	 * @param string|null $provider      Provider slug.
	 */
	public function remove_list( int $subscriber_id, string $list_id, ?string $provider = null ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->lists_table(),
			[
				'subscriber_id' => $subscriber_id,
				'list_id'       => trim( $list_id ),
				'provider'      => (string) $provider,
			],
			[ '%d', '%s', '%s' ]
		);
	}

	/**
	 * All list identifiers for a subscriber.
	 *
	 * @param int $subscriber_id Subscriber id.
	 * @return array<int, string>
	 */
	public function lists_for( int $subscriber_id ): array {
		global $wpdb;

		$table = $this->lists_table();

		$lists = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare( "SELECT list_id FROM {$table} WHERE subscriber_id = %d ORDER BY list_id ASC", $subscriber_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/identifier from internal constant; values bound via prepare().
		);

		return array_map( 'strval', $lists ?: [] );
	}

	/**
	 * All distinct tag names across subscribers (for filter dropdowns).
	 *
	 * @param int $limit Max tags.
	 * @return array<int, string>
	 */
	public function all_tags( int $limit = 200 ): array {
		global $wpdb;
		$table = $this->tags_table();

		$tags = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare( "SELECT DISTINCT tag FROM {$table} ORDER BY tag ASC LIMIT %d", max( 1, $limit ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/identifier from internal constant; values bound via prepare().
		);

		return array_map( 'strval', $tags ?: [] );
	}

	/**
	 * Delete all relationship rows for a subscriber (used on subscriber delete).
	 *
	 * @param int $subscriber_id Subscriber id.
	 */
	public function delete_all_for( int $subscriber_id ): void {
		global $wpdb;

		$wpdb->delete( $this->tags_table(), [ 'subscriber_id' => $subscriber_id ], [ '%d' ] );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $this->lists_table(), [ 'subscriber_id' => $subscriber_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
