<?php
/**
 * Provider connection data access.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

use MailPilot\Security\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes `wp_mailpilot_provider_connections`, encrypting credentials at rest and
 * decrypting them only when a connection is hydrated for use.
 */
final class ProviderConnectionRepository {

	public function __construct( private Encryption $encryption ) {}

	/**
	 * Fully-qualified table name.
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . MAILPILOT_TABLE_PREFIX . 'provider_connections';
	}

	/**
	 * Find a connection by id, with decrypted credentials.
	 *
	 * @param int $id Connection id.
	 */
	public function find( int $id ): ?ProviderConnection {
		global $wpdb;

		$table = $this->table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/identifier from internal constant; values bound via prepare().
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * All active connections, optionally filtered by provider slug.
	 *
	 * @param string|null $provider Provider slug filter.
	 * @return array<int, ProviderConnection>
	 */
	public function active( ?string $provider = null ): array {
		global $wpdb;

		$table = $this->table();

		if ( null !== $provider ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'active' AND provider = %s", $provider ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/identifier from internal constant; values bound via prepare().
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
		}

		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	/**
	 * All connections (any status), with decrypted credentials.
	 *
	 * @return array<int, ProviderConnection>
	 */
	public function all(): array {
		global $wpdb;
		$table = $this->table();

		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().

		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	/**
	 * Delete a connection.
	 *
	 * @param int $id Connection id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), [ 'id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Insert or update a connection, encrypting credentials.
	 *
	 * @param ProviderConnection $connection Connection to persist.
	 * @return int Connection id.
	 */
	public function save( ProviderConnection $connection ): int {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = [
			'provider'    => $connection->provider,
			'label'       => $connection->label,
			'status'      => $connection->status,
			'credentials' => $this->encryption->encrypt( (string) wp_json_encode( $connection->credentials ) ),
			'settings'    => wp_json_encode( $connection->settings ),
			'field_map'   => wp_json_encode( $connection->field_map ),
			'updated_at'  => $now,
		];

		if ( null === $connection->id ) {
			$data['created_at'] = $now;
			$wpdb->insert( $this->table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			return (int) $wpdb->insert_id;
		}

		$wpdb->update( $this->table(), $data, [ 'id' => $connection->id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return (int) $connection->id;
	}

	/**
	 * Hydrate a row into a connection, decrypting credentials.
	 *
	 * @param array<string, mixed> $row Raw row.
	 */
	private function hydrate( array $row ): ProviderConnection {
		$credentials = json_decode( $this->encryption->decrypt( (string) ( $row['credentials'] ?? '' ) ), true );
		$settings    = json_decode( (string) ( $row['settings'] ?? '' ), true );
		$field_map   = json_decode( (string) ( $row['field_map'] ?? '' ), true );

		return new ProviderConnection(
			id: isset( $row['id'] ) ? (int) $row['id'] : null,
			provider: (string) ( $row['provider'] ?? '' ),
			label: (string) ( $row['label'] ?? '' ),
			status: (string) ( $row['status'] ?? 'active' ),
			credentials: is_array( $credentials ) ? $credentials : [],
			settings: is_array( $settings ) ? $settings : [],
			field_map: is_array( $field_map ) ? $field_map : [],
		);
	}
}
