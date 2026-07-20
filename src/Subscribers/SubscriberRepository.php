<?php
/**
 * Subscriber data access.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Subscribers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes `wp_mailpilot_subscribers` with prepared statements.
 *
 * The repository is persistence only — business rules (status transitions,
 * dedupe, activity, sync) live in the SubscriberEngine. Not `final` so tests
 * can substitute an in-memory double.
 */
class SubscriberRepository {

	/**
	 * Fully-qualified table name.
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . MAILPILOT_TABLE_PREFIX . 'subscribers';
	}

	/**
	 * Normalise an email for storage and lookup.
	 *
	 * @param string $email Raw email.
	 */
	public static function normalize_email( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Find a subscriber by id.
	 *
	 * @param int $id Subscriber id.
	 */
	public function find( int $id ): ?Subscriber {
		global $wpdb;

		$table = $this->table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);

		return $row ? Subscriber::fromRow( $row ) : null;
	}

	/**
	 * Find a subscriber by email (case-insensitive).
	 *
	 * @param string $email Email address.
	 */
	public function find_by_email( string $email ): ?Subscriber {
		global $wpdb;

		$table = $this->table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", self::normalize_email( $email ) )
		);

		return $row ? Subscriber::fromRow( $row ) : null;
	}

	/**
	 * Insert a new subscriber, returning the entity with its assigned id.
	 *
	 * @param Subscriber $subscriber Entity to insert.
	 */
	public function insert( Subscriber $subscriber ): Subscriber {
		global $wpdb;

		$now                  = current_time( 'mysql', true );
		$subscriber->email    = self::normalize_email( $subscriber->email );
		$subscriber->created_at = $now;
		$subscriber->updated_at = $now;

		$data = $subscriber->toColumns() + [
			'created_at' => $now,
			'updated_at' => $now,
		];

		$wpdb->insert( $this->table(), $data, $this->formats() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$subscriber->id = (int) $wpdb->insert_id;

		return $subscriber;
	}

	/**
	 * Persist changes to an existing subscriber.
	 *
	 * @param Subscriber $subscriber Entity with a non-null id.
	 */
	public function update( Subscriber $subscriber ): Subscriber {
		global $wpdb;

		if ( null === $subscriber->id ) {
			return $this->insert( $subscriber );
		}

		$now                    = current_time( 'mysql', true );
		$subscriber->email      = self::normalize_email( $subscriber->email );
		$subscriber->updated_at = $now;

		$data = $subscriber->toColumns() + [ 'updated_at' => $now ];

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			$data,
			[ 'id' => $subscriber->id ],
			$this->formats() + [ '%s' ],
			[ '%d' ]
		);

		return $subscriber;
	}

	/**
	 * Delete a subscriber by id. Relationship/log rows are cleaned by the engine.
	 *
	 * @param int $id Subscriber id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( $this->table(), [ 'id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Query subscribers with filters and pagination.
	 *
	 * @param array{search?:string,status?:string,source?:string,country?:string,tag?:string,orderby?:string,order?:string,per_page?:int,page?:int} $args Query args.
	 * @return array{items:array<int,Subscriber>,total:int}
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		$table = $this->table();
		$tags  = $wpdb->prefix . MAILPILOT_TABLE_PREFIX . 'subscriber_tags';

		[ $where, $params ] = $this->build_where( $args, $table, $tags );

		$orderby  = $this->safe_orderby( $args['orderby'] ?? 'created_at' );
		$order    = strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		// Total count. Table identifiers come from internal constants and a
		// whitelisted ORDER BY; all user values are bound through prepare().
		$count_sql      = "SELECT COUNT(DISTINCT {$table}.id) FROM {$table} {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from internal constant; values bound below.
		$count_prepared = $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers are internal; values are bound.
		$total          = (int) $wpdb->get_var( $count_prepared ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- prepared above.

		// Page of results.
		$sql          = "SELECT DISTINCT {$table}.* FROM {$table} {$where} ORDER BY {$table}.{$orderby} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/orderby are internal + whitelisted; values bound below.
		$paged_params = array_merge( $params, [ $per_page, $offset ] );
		$paged_sql    = $wpdb->prepare( $sql, $paged_params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers are internal; values are bound.
		$rows         = $wpdb->get_results( $paged_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- prepared above.

		return [
			'items' => array_map( [ Subscriber::class, 'fromRow' ], $rows ?: [] ),
			'total' => $total,
		];
	}

	/**
	 * Total subscriber count.
	 */
	public function count(): int {
		global $wpdb;

		$table = $this->table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Build the WHERE clause and bound parameters for query().
	 *
	 * @param array<string, mixed> $args  Query args.
	 * @param string               $table Subscribers table.
	 * @param string               $tags  Tags table.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function build_where( array $args, string $table, string $tags ): array {
		global $wpdb;

		$clauses = [];
		$params  = [];
		$joins   = '';

		if ( ! empty( $args['search'] ) ) {
			$like      = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$clauses[] = "( {$table}.email LIKE %s OR {$table}.first_name LIKE %s OR {$table}.last_name LIKE %s OR {$table}.company LIKE %s )";
			array_push( $params, $like, $like, $like, $like );
		}

		foreach ( [ 'status', 'source', 'country' ] as $col ) {
			if ( ! empty( $args[ $col ] ) ) {
				$clauses[] = "{$table}.{$col} = %s";
				$params[]  = (string) $args[ $col ];
			}
		}

		if ( ! empty( $args['tag'] ) ) {
			$joins    .= " INNER JOIN {$tags} ON {$tags}.subscriber_id = {$table}.id";
			$clauses[] = "{$tags}.tag = %s";
			$params[]  = (string) $args['tag'];
		}

		// Provider filter: subscribers synced to a given provider (sync_log).
		if ( ! empty( $args['provider'] ) ) {
			$sync_log  = $wpdb->prefix . MAILPILOT_TABLE_PREFIX . 'sync_log';
			$clauses[] = "{$table}.id IN ( SELECT subscriber_id FROM {$sync_log} WHERE provider = %s )";
			$params[]  = (string) $args['provider'];
		}

		// Date range on created_at (inclusive).
		if ( ! empty( $args['date_from'] ) ) {
			$clauses[] = "{$table}.created_at >= %s";
			$params[]  = gmdate( 'Y-m-d 00:00:00', strtotime( (string) $args['date_from'] ) ?: time() );
		}
		if ( ! empty( $args['date_to'] ) ) {
			$clauses[] = "{$table}.created_at <= %s";
			$params[]  = gmdate( 'Y-m-d 23:59:59', strtotime( (string) $args['date_to'] ) ?: time() );
		}

		$where = $joins;
		if ( $clauses ) {
			$where .= ' WHERE ' . implode( ' AND ', $clauses );
		}

		return [ $where, $params ];
	}

	/**
	 * Whitelist orderable columns to prevent SQL injection via orderby.
	 *
	 * @param string $orderby Requested column.
	 */
	private function safe_orderby( string $orderby ): string {
		$allowed = [ 'id', 'email', 'first_name', 'last_name', 'status', 'source', 'country', 'created_at', 'updated_at' ];

		return in_array( $orderby, $allowed, true ) ? $orderby : 'created_at';
	}

	/**
	 * Column formats matching Subscriber::toColumns() order.
	 *
	 * @return array<int, string>
	 */
	private function formats(): array {
		// email, first_name, last_name, phone, company, country, status, source, ip_address, consent_at, meta.
		return [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];
	}
}
