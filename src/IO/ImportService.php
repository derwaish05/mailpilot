<?php
/**
 * Batched background CSV importer.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\IO;

use MailPilot\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports large subscriber CSVs in bounded batches through the background
 * queue, so the operation never times out (ADR-004). Progress and a final
 * summary are persisted per import id.
 *
 * Provider migration (Mailchimp/Brevo/etc.) extends this in the Pro add-on; the
 * free core ships the CSV path.
 */
final class ImportService {

	/**
	 * Queue hook for a single batch.
	 */
	public const BATCH_HOOK = 'mailpilot_import_csv_batch';

	/**
	 * Rows processed per batch.
	 */
	private const BATCH_SIZE = 200;

	public function __construct( private Plugin $plugin ) {}

	/**
	 * Register the batch worker.
	 */
	public function register_hooks(): void {
		add_action( self::BATCH_HOOK, [ $this, 'handle_batch' ] );
	}

	/**
	 * Begin an import from an uploaded CSV.
	 *
	 * Copies the file into uploads (the temp file is gone by the time the queue
	 * runs), reads the header, and enqueues the first batch.
	 *
	 * @param string $tmp_path Uploaded temp file path.
	 * @return string Import id.
	 */
	public function start( string $tmp_path ): string {
		$import_id = 'imp_' . wp_generate_password( 12, false );
		$dest      = $this->storage_path( $import_id );

		if ( ! is_readable( $tmp_path ) || ! @copy( $tmp_path, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->set_progress( $import_id, [ 'status' => 'failed', 'error' => 'Could not read upload.' ] );

			return $import_id;
		}

		// Streaming reads (fgetcsv/ftell) power the resumable, batched importer;
		// WP_Filesystem has no streaming/seek API, so PHP streams are required.
		$handle = fopen( $dest, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$header = $handle ? fgetcsv( $handle ) : false;
		$offset = $handle ? ftell( $handle ) : 0;
		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		if ( ! is_array( $header ) ) {
			$this->set_progress( $import_id, [ 'status' => 'failed', 'error' => 'Empty or invalid CSV.' ] );

			return $import_id;
		}

		$header = array_map( static fn ( $h ): string => strtolower( trim( (string) $h ) ), $header );

		$this->set_progress(
			$import_id,
			[
				'status'   => 'processing',
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => [],
			]
		);

		$this->plugin->queue()->push(
			self::BATCH_HOOK,
			[
				'import_id' => $import_id,
				'offset'    => (int) $offset,
				'header'    => $header,
			]
		);

		return $import_id;
	}

	/**
	 * Process one batch, then enqueue the next until EOF.
	 *
	 * @param array<string, mixed> $payload Batch payload.
	 */
	public function handle_batch( array $payload ): void {
		$import_id = (string) ( $payload['import_id'] ?? '' );
		$offset    = (int) ( $payload['offset'] ?? 0 );
		$header    = (array) ( $payload['header'] ?? [] );
		$path      = $this->storage_path( $import_id );

		if ( '' === $import_id || ! is_readable( $path ) ) {
			return;
		}

		// Streaming read from the saved offset for this batch (see note above).
		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return;
		}

		fseek( $handle, $offset );

		$progress = $this->get_progress( $import_id );
		$count    = 0;

		while ( $count < self::BATCH_SIZE && ( $row = fgetcsv( $handle ) ) !== false ) {
			++$count;
			$data = Csv::map_row( $header, $row );

			if ( empty( $data['email'] ) ) {
				++$progress['skipped'];
				continue;
			}

			try {
				$tags = isset( $data['tags'] ) && '' !== $data['tags']
					? array_filter( array_map( 'trim', explode( '|', (string) $data['tags'] ) ) )
					: [];
				unset( $data['tags'] );
				$data['source'] = $data['source'] ?? 'import';

				$this->plugin->subscribers()->capture( $data, [ 'tags' => $tags ] );
				++$progress['imported'];
			} catch ( \Throwable $e ) {
				++$progress['skipped'];
			}
		}

		$new_offset = ftell( $handle );
		$eof        = feof( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$this->set_progress( $import_id, $progress );

		if ( ! $eof ) {
			$this->plugin->queue()->push(
				self::BATCH_HOOK,
				[
					'import_id' => $import_id,
					'offset'    => (int) $new_offset,
					'header'    => $header,
				]
			);

			return;
		}

		// Done — finalise and clean up the working file.
		$progress['status'] = 'complete';
		$this->set_progress( $import_id, $progress );
		wp_delete_file( $path );
	}

	/**
	 * Import progress/summary.
	 *
	 * @param string $import_id Import id.
	 * @return array<string, mixed>
	 */
	public function get_progress( string $import_id ): array {
		$progress = get_option( $this->option_key( $import_id ), [] );

		return is_array( $progress ) ? $progress + [ 'imported' => 0, 'skipped' => 0 ] : [ 'imported' => 0, 'skipped' => 0 ];
	}

	/**
	 * Persist import progress.
	 *
	 * @param string               $import_id Import id.
	 * @param array<string, mixed> $progress  Progress data.
	 */
	private function set_progress( string $import_id, array $progress ): void {
		update_option( $this->option_key( $import_id ), $progress, false );
	}

	/**
	 * Working-file path for an import.
	 *
	 * @param string $import_id Import id.
	 */
	private function storage_path( string $import_id ): string {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . 'mailpilot-' . sanitize_file_name( $import_id ) . '.csv';
	}

	/**
	 * Option key for an import's progress.
	 *
	 * @param string $import_id Import id.
	 */
	private function option_key( string $import_id ): string {
		return 'mailpilot_import_' . $import_id;
	}
}
