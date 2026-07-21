<?php
/**
 * Subscribers admin page controller.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Admin;

use MailPilot\IO\Csv;
use MailPilot\Plugin;
use MailPilot\Subscribers\Subscriber;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the subscriber list, dispatches bulk actions, and handles CSV
 * import/export. All write paths verify capability + nonce.
 */
final class SubscribersPage {

	private const PER_PAGE = 20;

	public function __construct( private Plugin $plugin ) {}

	/**
	 * Page entry point.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'brainstudioz-mailpilot' ) );
		}

		$action     = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$subscriber = isset( $_REQUEST['subscriber'] ) ? (int) $_REQUEST['subscriber'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'view' === $action && $subscriber > 0 ) {
			( new SubscriberProfilePage( $this->plugin ) )->render( $subscriber );

			return;
		}

		$this->handle_actions();
		$this->render_list();
	}

	/**
	 * Build query args from the request (read-only filters need no nonce).
	 *
	 * @return array<string, mixed>
	 */
	private function query_args(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged   = isset( $_REQUEST['paged'] ) ? max( 1, (int) $_REQUEST['paged'] ) : 1;
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc';

		$args = [
			'per_page' => self::PER_PAGE,
			'page'     => $paged,
			'orderby'  => $orderby,
			'order'    => $order,
		];

		$map = [
			's'         => 'search',
			'status'    => 'status',
			'source'    => 'source',
			'country'   => 'country',
			'tag'       => 'tag',
			'provider'  => 'provider',
			'date_from' => 'date_from',
			'date_to'   => 'date_to',
		];
		foreach ( $map as $req => $key ) {
			if ( ! empty( $_REQUEST[ $req ] ) ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $_REQUEST[ $req ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $args;
	}

	/**
	 * Dispatch bulk actions and CSV import (POST, nonce-verified).
	 */
	private function handle_actions(): void {
		// CSV import upload.
		if ( isset( $_POST['mailpilot_import'] ) ) {
			check_admin_referer( 'mailpilot_import_csv' );
			$this->import_csv();

			return;
		}

		$action = $this->current_bulk_action();
		if ( '' === $action ) {
			return;
		}

		check_admin_referer( 'bulk-subscribers' );

		$ids = isset( $_REQUEST['subscriber'] ) ? array_map( 'intval', (array) wp_unslash( $_REQUEST['subscriber'] ) ) : [];
		$ids = array_filter( $ids );

		if ( ! $ids ) {
			return;
		}

		match ( $action ) {
			'delete'      => $this->bulk_delete( $ids ),
			'export'      => $this->bulk_export( $ids ),
			'resync'      => $this->bulk_resync( $ids ),
			'add_tags'    => $this->bulk_tags( $ids, true ),
			'remove_tags' => $this->bulk_tags( $ids, false ),
			default       => null,
		};
	}

	/**
	 * The selected bulk action from either nav dropdown.
	 */
	private function current_bulk_action(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( [ 'action', 'action2' ] as $field ) {
			if ( isset( $_REQUEST[ $field ] ) ) {
				$value = sanitize_key( wp_unslash( $_REQUEST[ $field ] ) );
				if ( in_array( $value, [ 'delete', 'export', 'resync', 'add_tags', 'remove_tags' ], true ) ) {
					return $value;
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return '';
	}

	/**
	 * Delete subscribers and their relationship rows.
	 *
	 * @param array<int, int> $ids Subscriber ids.
	 */
	private function bulk_delete( array $ids ): void {
		foreach ( $ids as $id ) {
			$this->plugin->subscribers()->delete( $id );
		}

		$this->notice( sprintf( /* translators: %d: count. */ _n( '%d subscriber deleted.', '%d subscribers deleted.', count( $ids ), 'brainstudioz-mailpilot' ), count( $ids ) ) );
	}

	/**
	 * Export selected subscribers as CSV (streams and exits).
	 *
	 * @param array<int, int> $ids Subscriber ids.
	 */
	private function bulk_export( array $ids ): void {
		$repo        = $this->plugin->subscriber_repository();
		$subscribers = array_filter( array_map( static fn ( int $id ): ?Subscriber => $repo->find( $id ), $ids ) );

		$csv = new Csv( $this->plugin->subscribers() );
		$csv->export( $subscribers, fn ( int $id ): array => $this->plugin->relationships()->tags_for( $id ) );
	}

	/**
	 * Queue a resync of selected subscribers to all active connections.
	 *
	 * @param array<int, int> $ids Subscriber ids.
	 */
	private function bulk_resync( array $ids ): void {
		$connections = $this->plugin->provider_connections()->active();
		$targets     = array_map( static fn ( $c ): int => (int) $c->id, $connections );

		if ( ! $targets ) {
			$this->notice( __( 'No active provider connections to resync to.', 'brainstudioz-mailpilot' ), 'warning' );

			return;
		}

		$repo = $this->plugin->subscriber_repository();
		foreach ( $ids as $id ) {
			$subscriber = $repo->find( $id );
			if ( $subscriber ) {
				$this->plugin->sync()->dispatch( $subscriber, $targets );
			}
		}

		$this->notice( sprintf( /* translators: %d: count. */ _n( 'Queued resync for %d subscriber.', 'Queued resync for %d subscribers.', count( $ids ), 'brainstudioz-mailpilot' ), count( $ids ) ) );
	}

	/**
	 * Add or remove a tag across selected subscribers.
	 *
	 * @param array<int, int> $ids Subscriber ids.
	 * @param bool            $add Whether to add (true) or remove (false).
	 */
	private function bulk_tags( array $ids, bool $add ): void {
		$tag = isset( $_REQUEST['bulk_tag'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['bulk_tag'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $tag ) {
			$this->notice( __( 'Enter a tag name in the "Bulk tag" box before applying tag actions.', 'brainstudioz-mailpilot' ), 'warning' );

			return;
		}

		$repo = $this->plugin->subscriber_repository();
		foreach ( $ids as $id ) {
			$subscriber = $repo->find( $id );
			if ( ! $subscriber ) {
				continue;
			}

			if ( $add ) {
				$this->plugin->subscribers()->apply_tags( $subscriber, [ $tag ] );
			} else {
				$this->plugin->subscribers()->remove_tags( $subscriber, [ $tag ] );
			}
		}

		$this->notice( __( 'Tag updated for selected subscribers.', 'brainstudioz-mailpilot' ) );
	}

	/**
	 * Handle a CSV import upload.
	 */
	private function import_csv(): void {
		// The CSRF nonce and capability are verified in handle_actions() before
		// this dispatches; PHPCS scopes the check per function, so mark the block.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified upstream.
		if ( empty( $_FILES['mailpilot_csv']['tmp_name'] ) ) {
			$this->notice( __( 'No file uploaded.', 'brainstudioz-mailpilot' ), 'error' );

			return;
		}

		$file = wp_unslash( $_FILES['mailpilot_csv'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) $file['tmp_name'];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$csv    = new Csv( $this->plugin->subscribers() );
		$result = $csv->import( $path );

		$this->notice(
			sprintf(
				/* translators: 1: imported count, 2: skipped count. */
				__( 'Import complete: %1$d imported, %2$d skipped.', 'brainstudioz-mailpilot' ),
				$result['imported'],
				$result['skipped']
			)
		);
	}

	/**
	 * Render the list screen.
	 */
	private function render_list(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		// Provider slugs => labels for the provider filter.
		$providers = [];
		foreach ( $this->plugin->providers()->all() as $provider ) {
			$providers[ $provider->id() ] = $provider->label();
		}

		$table = new SubscribersListTable(
			fn ( int $id ): array => $this->plugin->relationships()->tags_for( $id ),
			$providers,
			$this->plugin->relationships()->all_tags()
		);
		$query = $this->plugin->subscriber_repository()->query( $this->query_args() );
		$table->set_data( $query, self::PER_PAGE );
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Subscribers', 'brainstudioz-mailpilot' ) . '</h1>';
		echo '<hr class="wp-header-end" />';

		// Import form.
		echo '<form method="post" enctype="multipart/form-data" style="margin:12px 0">';
		wp_nonce_field( 'mailpilot_import_csv' );
		echo '<input type="file" name="mailpilot_csv" accept=".csv" /> ';
		submit_button( __( 'Import CSV', 'brainstudioz-mailpilot' ), 'secondary', 'mailpilot_import', false );
		echo '</form>';

		// List + filters + bulk actions.
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( AdminMenu::SLUG . '-subscribers' ) . '" />';
		$table->search_box( __( 'Search subscribers', 'brainstudioz-mailpilot' ), 'mailpilot-subscriber-search' );
		echo '<p><input type="text" name="bulk_tag" placeholder="' . esc_attr__( 'Bulk tag (for add/remove tag actions)', 'brainstudioz-mailpilot' ) . '" class="regular-text" /></p>';
		$table->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Render an admin notice on the next page load.
	 *
	 * @param string $message Message text.
	 * @param string $type    Notice type (success|warning|error).
	 */
	private function notice( string $message, string $type = 'success' ): void {
		add_action(
			'admin_notices',
			static function () use ( $message, $type ): void {
				printf(
					'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
					esc_attr( $type ),
					esc_html( $message )
				);
			}
		);
	}
}
