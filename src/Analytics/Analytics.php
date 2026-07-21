<?php
/**
 * Analytics aggregation and reporting.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records aggregate metrics into `wp_mailpilot_analytics` and answers the basic
 * dashboard queries that ship in the free core.
 *
 * Revenue metrics and over-time charts are part of the Pro add-on; the
 * recording API here is shared so Pro can build on the same table.
 */
final class Analytics {

	/**
	 * Analytics table.
	 */
	private function analytics_table(): string {
		global $wpdb;

		return $wpdb->prefix . MAILPILOT_TABLE_PREFIX . 'analytics';
	}

	/**
	 * Subscribers table.
	 */
	private function subscribers_table(): string {
		global $wpdb;

		return $wpdb->prefix . MAILPILOT_TABLE_PREFIX . 'subscribers';
	}

	/**
	 * Increment a metric for a given day (UTC). Upserts on the unique key.
	 *
	 * @param string      $metric      Metric slug (e.g. `views`, `submissions`).
	 * @param float       $by          Amount to add.
	 * @param string|null $object_type Optional object type (e.g. `form`).
	 * @param int|null    $object_id   Optional object id.
	 * @param string|null $date        Y-m-d (defaults to today, UTC).
	 */
	public function increment( string $metric, float $by = 1, ?string $object_type = null, ?int $object_id = null, ?string $date = null ): void {
		global $wpdb;

		$table = $this->analytics_table();
		$date  = $date ?? gmdate( 'Y-m-d' );

		// MySQL upsert: rely on the UNIQUE(metric, object_type, object_id, period_date) index.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from internal constant; values bound via prepare().
				"INSERT INTO {$table} (metric, object_type, object_id, value, period_date, created_at)
				 VALUES (%s, %s, %d, %f, %s, %s)
				 ON DUPLICATE KEY UPDATE value = value + VALUES(value)",
				$metric,
				(string) $object_type,
				(int) $object_id,
				$by,
				$date,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Total subscriber count.
	 */
	public function subscriber_count(): int {
		global $wpdb;
		$table = $this->subscribers_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
	}

	/**
	 * Subscriber counts grouped by status.
	 *
	 * @return array<string, int>
	 */
	public function status_breakdown(): array {
		global $wpdb;
		$table = $this->subscribers_table();

		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().

		$out = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * New subscribers created within the last $days.
	 *
	 * @param int $days Window length.
	 */
	public function new_in_last_days( int $days = 30 ): int {
		global $wpdb;
		$table = $this->subscribers_table();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $since ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/identifier from internal constant; values bound via prepare().
		);
	}

	/**
	 * Growth rate (%) over the trailing window versus the prior window.
	 *
	 * @param int $days Window length in days.
	 */
	public function growth_rate( int $days = 30 ): float {
		global $wpdb;
		$table = $this->subscribers_table();

		$now        = time();
		$this_start = gmdate( 'Y-m-d H:i:s', $now - ( $days * DAY_IN_SECONDS ) );
		$prev_start = gmdate( 'Y-m-d H:i:s', $now - ( 2 * $days * DAY_IN_SECONDS ) );

		$current  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $this_start ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
		$previous = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND created_at < %s", $prev_start, $this_start ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().

		if ( 0 === $previous ) {
			return $current > 0 ? 100.0 : 0.0;
		}

		return round( ( ( $current - $previous ) / $previous ) * 100, 1 );
	}

	/**
	 * Top sources by subscriber count.
	 *
	 * @param int $limit Max rows.
	 * @return array<string, int>
	 */
	public function top_sources( int $limit = 5 ): array {
		global $wpdb;
		$table = $this->subscribers_table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare( "SELECT source, COUNT(*) AS total FROM {$table} GROUP BY source ORDER BY total DESC LIMIT %d", max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/identifier from internal constant; values bound via prepare().
			ARRAY_A
		);

		$out = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['source'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Sum a metric over the trailing window.
	 *
	 * @param string $metric Metric slug.
	 * @param int    $days   Window length.
	 */
	public function metric_total( string $metric, int $days = 30 ): float {
		global $wpdb;
		$table = $this->analytics_table();
		$since = gmdate( 'Y-m-d', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		return (float) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare( "SELECT COALESCE(SUM(value),0) FROM {$table} WHERE metric = %s AND period_date >= %s", $metric, $since ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/identifier from internal constant; values bound via prepare().
		);
	}

	/**
	 * Conversion rate (%) over the trailing window: conversions ÷ views.
	 *
	 * @param int $days Window length.
	 */
	public function conversion_rate( int $days = 30 ): float {
		$views       = $this->metric_total( 'views', $days );
		$conversions = $this->metric_total( 'conversions', $days );

		return $views > 0 ? round( ( $conversions / $views ) * 100, 1 ) : 0.0;
	}

	/**
	 * Top forms by conversions over the window.
	 *
	 * @param int $days  Window length.
	 * @param int $limit Max rows.
	 * @return array<string, int> Form title => conversions.
	 */
	public function top_forms( int $days = 30, int $limit = 5 ): array {
		global $wpdb;
		$analytics = $this->analytics_table();
		$forms     = $wpdb->prefix . MAILPILOT_TABLE_PREFIX . 'forms';
		$since     = gmdate( 'Y-m-d', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare( // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifiers from internal constants; values bound via prepare().
				"SELECT a.object_id, COALESCE(f.title, CONCAT('Form #', a.object_id)) AS title, SUM(a.value) AS total
				 FROM {$analytics} a
				 LEFT JOIN {$forms} f ON f.id = a.object_id
				 WHERE a.metric = 'conversions' AND a.object_type = 'form' AND a.period_date >= %s
				 GROUP BY a.object_id, title
				 ORDER BY total DESC LIMIT %d", // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since,
				max( 1, $limit )
			),
			ARRAY_A
		);

		$out = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['title'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * New subscribers per day over the window (Subscribers Over Time).
	 *
	 * @param int $days Window length.
	 * @return array<string, int> Y-m-d => count.
	 */
	public function subscribers_over_time( int $days = 30 ): array {
		global $wpdb;
		$table = $this->subscribers_table();
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare(
				"SELECT DATE(created_at) AS d, COUNT(*) AS total FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY d ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/identifier from internal constant; values bound via prepare().
				$since
			),
			ARRAY_A
		);

		$out = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['d'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * New subscribers grouped by day, week, or month.
	 *
	 * @param string $period daily | weekly | monthly.
	 * @param int    $days   Window length.
	 * @return array<string, int> bucket => count.
	 */
	public function subscribers_series( string $period = 'daily', int $days = 30 ): array {
		global $wpdb;
		$table = $this->subscribers_table();
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		$bucket = match ( $period ) {
			'weekly'  => "DATE_FORMAT(created_at, '%x-W%v')",
			'monthly' => "DATE_FORMAT(created_at, '%Y-%m')",
			default   => 'DATE(created_at)',
		};

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare(
				"SELECT {$bucket} AS bucket, COUNT(*) AS total FROM {$table} WHERE created_at >= %s GROUP BY bucket ORDER BY bucket ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/identifier from internal constant; values bound via prepare().
				$since
			),
			ARRAY_A
		);

		$out = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['bucket'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Metric series grouped by day, week, or month (for reports/charts).
	 *
	 * @param array<int, string> $metrics Metrics to include.
	 * @param string             $period  daily | weekly | monthly.
	 * @param int                $days    Window length.
	 * @return array<int, array<string, mixed>> Rows keyed by period bucket + metric totals.
	 */
	public function series( array $metrics, string $period = 'daily', int $days = 30 ): array {
		global $wpdb;
		$table = $this->analytics_table();
		$since = gmdate( 'Y-m-d', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		// Bucket expression by period (week = ISO year-week, month = year-month).
		$bucket = match ( $period ) {
			'weekly'  => "DATE_FORMAT(period_date, '%x-W%v')",
			'monthly' => "DATE_FORMAT(period_date, '%Y-%m')",
			default   => 'period_date',
		};

		$placeholders = implode( ',', array_fill( 0, count( $metrics ), '%s' ) );
		$params       = array_merge( [ $since ], array_values( $metrics ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare( // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifiers from internal constants; values bound via prepare().
				"SELECT {$bucket} AS bucket, metric, SUM(value) AS total FROM {$table}
				 WHERE period_date >= %s AND metric IN ({$placeholders})
				 GROUP BY bucket, metric ORDER BY bucket ASC", // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			),
			ARRAY_A
		);

		$by_bucket = [];
		foreach ( $rows ?: [] as $row ) {
			$key                       = (string) $row['bucket'];
			$by_bucket[ $key ]         = $by_bucket[ $key ] ?? array_merge( [ 'bucket' => $key ], array_fill_keys( $metrics, 0.0 ) );
			$by_bucket[ $key ][ (string) $row['metric'] ] = (float) $row['total'];
		}

		return array_values( $by_bucket );
	}
}
