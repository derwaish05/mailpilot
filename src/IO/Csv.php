<?php
/**
 * CSV export/import for subscribers.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\IO;

use MailPilot\Subscribers\Subscriber;
use MailPilot\Subscribers\SubscriberEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams subscriber exports and feeds CSV rows back through the engine.
 *
 * Large migrations from other providers run as batched background jobs in
 * Milestone 3 (Import & Migration); this is the direct CSV path for the
 * local database UI.
 */
final class Csv {

	/**
	 * Columns exported / expected on import.
	 *
	 * @var array<int, string>
	 */
	public const COLUMNS = [ 'email', 'first_name', 'last_name', 'phone', 'company', 'country', 'status', 'source', 'tags' ];

	public function __construct( private SubscriberEngine $engine ) {}

	/**
	 * Stream a CSV of the given subscribers to the browser and exit.
	 *
	 * @param array<int, Subscriber> $subscribers          Subscribers to export.
	 * @param callable|null          $tags_for             fn(int $id): array<string> to resolve tags.
	 * @param string                 $filename             Download filename.
	 */
	public function export( array $subscribers, ?callable $tags_for = null, string $filename = 'mailpilot-subscribers.csv' ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $filename ) );

		// Streaming the CSV directly to the download response; WP_Filesystem has
		// no output-stream API, so a PHP stream is the correct tool here.
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, self::COLUMNS );

		foreach ( $subscribers as $sub ) {
			$tags = $tags_for ? (array) $tags_for( (int) $sub->id ) : [];

			fputcsv(
				$out,
				[
					$sub->email,
					(string) $sub->first_name,
					(string) $sub->last_name,
					(string) $sub->phone,
					(string) $sub->company,
					(string) $sub->country,
					$sub->status->value,
					$sub->source->value,
					implode( '|', $tags ),
				]
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Import subscribers from a CSV file via the engine.
	 *
	 * @param string $path     Absolute path to the uploaded CSV.
	 * @param int    $max_rows Safety cap for the synchronous admin path.
	 * @return array{imported:int,skipped:int,errors:array<int,string>}
	 */
	public function import( string $path, int $max_rows = 5000 ): array {
		$imported = 0;
		$skipped  = 0;
		$errors   = [];

		if ( ! is_readable( $path ) ) {
			return [
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => [ 'File is not readable.' ],
			];
		}

		// Streaming the CSV row-by-row (fgetcsv) to avoid loading large imports
		// wholesale into memory; WP_Filesystem has no streaming reader.
		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return [
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => [ 'Could not open file.' ],
			];
		}

		$header = fgetcsv( $handle );
		$header = is_array( $header ) ? array_map( static fn ( $h ): string => strtolower( trim( (string) $h ) ), $header ) : [];
		$row_no = 1;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			++$row_no;

			if ( $imported + $skipped >= $max_rows ) {
				$errors[] = sprintf( 'Stopped at the %d-row limit; use the background importer for larger files.', $max_rows );
				break;
			}

			$data = self::map_row( $header, $row );

			if ( empty( $data['email'] ) ) {
				++$skipped;
				continue;
			}

			try {
				$tags = isset( $data['tags'] ) && '' !== $data['tags']
					? array_filter( array_map( 'trim', explode( '|', (string) $data['tags'] ) ) )
					: [];
				unset( $data['tags'] );

				$data['source'] = $data['source'] ?? 'import';

				$this->engine->capture( $data, [ 'tags' => $tags ] );
				++$imported;
			} catch ( \Throwable $e ) {
				++$skipped;
				$errors[] = sprintf( 'Row %d: %s', $row_no, $e->getMessage() );
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return [
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		];
	}

	/**
	 * Map a CSV row to an engine data array using the header.
	 *
	 * @param array<int, string> $header Lower-cased header columns.
	 * @param array<int, string> $row    Row values.
	 * @return array<string, string>
	 */
	public static function map_row( array $header, array $row ): array {
		$data = [];

		foreach ( $header as $i => $col ) {
			if ( in_array( $col, self::COLUMNS, true ) ) {
				$data[ $col ] = isset( $row[ $i ] ) ? trim( (string) $row[ $i ] ) : '';
			}
		}

		return $data;
	}
}
