<?php
/**
 * Form data access.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes `wp_mailpilot_forms`. Hot reads (single form by id) are object-cached.
 */
class FormRepository {

	/**
	 * Cache group.
	 */
	private const CACHE_GROUP = 'mailpilot_forms';

	/**
	 * Fully-qualified table name.
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . MAILPILOT_TABLE_PREFIX . 'forms';
	}

	/**
	 * Find a form by id (object-cached).
	 *
	 * @param int $id Form id.
	 */
	public function find( int $id ): ?Form {
		$cached = wp_cache_get( (string) $id, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached instanceof Form ? $cached : null;
		}

		global $wpdb;
		$table = $this->table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/identifier from internal constant; values bound via prepare().
		);

		$form = $row ? Form::fromRow( $row ) : null;
		wp_cache_set( (string) $id, $form ?? 0, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $form;
	}

	/**
	 * Find a managed inline form by its definition hash.
	 *
	 * Kept for backward compatibility with callers that have no stable
	 * widget identity to key on (see `find_by_settings_pair()`).
	 *
	 * @param string $hash Inline-definition hash.
	 */
	public function find_by_inline_hash( string $hash ): ?Form {
		return $this->find_by_settings_pair( 'inline_hash', $hash );
	}

	/**
	 * Find a managed inline form by the id of the page-builder widget/element
	 * that owns it (e.g. an Elementor element id). This is the stable identity
	 * used to upsert in place instead of by content hash, so re-rendering the
	 * same widget with edited content updates its one row rather than creating
	 * a new one.
	 *
	 * @param string $elementor_id Owning widget/element id.
	 */
	public function find_by_elementor_id( string $elementor_id ): ?Form {
		return $this->find_by_settings_pair( 'elementor_id', $elementor_id );
	}

	/**
	 * Find a form whose JSON `settings` column contains a given key/value pair.
	 *
	 * @param string $key   Settings key.
	 * @param string $value Settings value.
	 */
	private function find_by_settings_pair( string $key, string $value ): ?Form {
		global $wpdb;
		$table = $this->table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE settings LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/identifier from internal constant; values bound via prepare().
				'%' . $wpdb->esc_like( '"' . $key . '":"' . $value . '"' ) . '%'
			)
		);

		return $row ? Form::fromRow( $row ) : null;
	}

	/**
	 * All forms, newest first.
	 *
	 * @return array<int, Form>
	 */
	public function all(): array {
		global $wpdb;
		$table = $this->table();

		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/identifier from internal constant; values bound via prepare().

		return array_map( [ Form::class, 'fromRow' ], $rows ?: [] );
	}

	/**
	 * Insert or update a form, returning its id.
	 *
	 * @param Form $form Form to persist.
	 */
	public function save( Form $form ): int {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = [
			'title'        => $form->title,
			'status'       => $form->status,
			'display_type' => $form->display_type,
			'fields'       => wp_json_encode( array_map( static fn ( Field $f ): array => $f->toArray(), $form->fields ) ),
			'actions'      => wp_json_encode( $form->actions ),
			'settings'     => wp_json_encode( $form->settings ),
			'updated_at'   => $now,
		];

		if ( null === $form->id ) {
			$data['created_at'] = $now;
			$wpdb->insert( $this->table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$form->id = (int) $wpdb->insert_id;
		} else {
			$wpdb->update( $this->table(), $data, [ 'id' => $form->id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_delete( (string) $form->id, self::CACHE_GROUP );
		}

		/**
		 * Fires after a form is saved. Add-ons (e.g. Pro's popup design cache)
		 * use this to invalidate derived output. Part of the extension API.
		 *
		 * @param int  $id   The saved form id.
		 * @param Form $form The saved form.
		 */
		do_action( 'mailpilot_form_saved', (int) $form->id, $form );

		return (int) $form->id;
	}

	/**
	 * Delete a form.
	 *
	 * @param int $id Form id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		wp_cache_delete( (string) $id, self::CACHE_GROUP );

		return (bool) $wpdb->delete( $this->table(), [ 'id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
