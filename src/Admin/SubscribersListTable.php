<?php
/**
 * Subscribers list table.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Admin;

use MailPilot\Subscribers\Source;
use MailPilot\Subscribers\Status;
use MailPilot\Subscribers\Subscriber;
use WP_List_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_List_Table for the local subscriber database, with search, filters, and
 * bulk actions. The parent class is loaded by SubscribersPage before this is
 * instantiated.
 */
final class SubscribersListTable extends WP_List_Table {

	/**
	 * Resolver for a subscriber's tags: fn(int $id): array<string>.
	 *
	 * @var callable
	 */
	private $tags_for;

	/**
	 * Provider slug => label, for the provider filter.
	 *
	 * @var array<string, string>
	 */
	private array $providers;

	/**
	 * All distinct tag names, for the tag filter.
	 *
	 * @var array<int, string>
	 */
	private array $tags;

	/**
	 * Repository result staged for prepare_items().
	 *
	 * @var array{items:array<int,Subscriber>,total:int}
	 */
	private array $query = [
		'items' => [],
		'total' => 0,
	];

	/**
	 * Items per page, staged for prepare_items().
	 *
	 * @var int
	 */
	private int $per_page = 20;

	/**
	 * @param callable                $tags_for  fn(int $id): array<string>.
	 * @param array<string, string>   $providers Provider slug => label.
	 * @param array<int, string>      $tags      Distinct tag names.
	 */
	public function __construct( callable $tags_for, array $providers = [], array $tags = [] ) {
		parent::__construct(
			[
				'singular' => 'subscriber',
				'plural'   => 'subscribers',
				'ajax'     => false,
			]
		);

		$this->tags_for  = $tags_for;
		$this->providers = $providers;
		$this->tags      = $tags;
	}

	/**
	 * Column definitions.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'cb'      => '<input type="checkbox" />',
			'email'   => __( 'Email', 'brainstudioz-mailpilot' ),
			'name'    => __( 'Name', 'brainstudioz-mailpilot' ),
			'status'  => __( 'Status', 'brainstudioz-mailpilot' ),
			'source'  => __( 'Source', 'brainstudioz-mailpilot' ),
			'country' => __( 'Country', 'brainstudioz-mailpilot' ),
			'tags'    => __( 'Tags', 'brainstudioz-mailpilot' ),
			'created' => __( 'Created', 'brainstudioz-mailpilot' ),
		];
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array{0:string,1:bool}>
	 */
	protected function get_sortable_columns(): array {
		return [
			'email'   => [ 'email', false ],
			'status'  => [ 'status', false ],
			'source'  => [ 'source', false ],
			'created' => [ 'created_at', true ],
		];
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return [
			'delete'      => __( 'Delete', 'brainstudioz-mailpilot' ),
			'export'      => __( 'Export', 'brainstudioz-mailpilot' ),
			'resync'      => __( 'Resync', 'brainstudioz-mailpilot' ),
			'add_tags'    => __( 'Add tags', 'brainstudioz-mailpilot' ),
			'remove_tags' => __( 'Remove tags', 'brainstudioz-mailpilot' ),
		];
	}

	/**
	 * Checkbox column.
	 *
	 * @param Subscriber $item Row.
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="subscriber[]" value="%d" />', (int) $item->id );
	}

	/**
	 * Email column with row actions.
	 *
	 * @param Subscriber $item Row.
	 */
	protected function column_email( Subscriber $item ): string {
		$view = add_query_arg(
			[
				'page'       => AdminMenu::SLUG . '-subscribers',
				'action'     => 'view',
				'subscriber' => (int) $item->id,
			],
			admin_url( 'admin.php' )
		);

		$actions = [
			'view' => sprintf( '<a href="%s">%s</a>', esc_url( $view ), esc_html__( 'View', 'brainstudioz-mailpilot' ) ),
		];

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s',
			esc_url( $view ),
			esc_html( $item->email ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Default column renderer.
	 *
	 * @param Subscriber $item        Row.
	 * @param string     $column_name Column key.
	 */
	protected function column_default( $item, $column_name ): string {
		return match ( $column_name ) {
			'name'    => esc_html( $item->display_name() ),
			'status'  => esc_html( $item->status->label() ),
			'source'  => esc_html( $item->source->label() ),
			'country' => esc_html( (string) $item->country ),
			'tags'    => esc_html( implode( ', ', (array) ( $this->tags_for )( (int) $item->id ) ) ),
			'created' => esc_html( (string) $item->created_at ),
			default   => '',
		};
	}

	/**
	 * Status/source filter dropdowns above the table.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status    = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$source    = isset( $_REQUEST['source'] ) ? sanitize_key( wp_unslash( $_REQUEST['source'] ) ) : '';
		$provider  = isset( $_REQUEST['provider'] ) ? sanitize_key( wp_unslash( $_REQUEST['provider'] ) ) : '';
		$tag       = isset( $_REQUEST['tag'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['tag'] ) ) : '';
		$date_from = isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : '';
		$date_to   = isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="alignleft actions">';

		echo '<select name="status"><option value="">' . esc_html__( 'All statuses', 'brainstudioz-mailpilot' ) . '</option>';
		foreach ( Status::cases() as $case ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $case->value ), selected( $status, $case->value, false ), esc_html( $case->label() ) );
		}
		echo '</select>';

		echo '<select name="source"><option value="">' . esc_html__( 'All sources', 'brainstudioz-mailpilot' ) . '</option>';
		foreach ( Source::cases() as $case ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $case->value ), selected( $source, $case->value, false ), esc_html( $case->label() ) );
		}
		echo '</select>';

		// Provider filter.
		echo '<select name="provider"><option value="">' . esc_html__( 'All providers', 'brainstudioz-mailpilot' ) . '</option>';
		foreach ( $this->providers as $slug => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $slug ), selected( $provider, $slug, false ), esc_html( $label ) );
		}
		echo '</select>';

		// Tag filter.
		echo '<select name="tag"><option value="">' . esc_html__( 'All tags', 'brainstudioz-mailpilot' ) . '</option>';
		foreach ( $this->tags as $tag_name ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $tag_name ), selected( $tag, $tag_name, false ), esc_html( $tag_name ) );
		}
		echo '</select>';

		// Date range.
		printf( '<input type="date" name="date_from" value="%s" aria-label="%s" />', esc_attr( $date_from ), esc_attr__( 'From date', 'brainstudioz-mailpilot' ) );
		printf( '<input type="date" name="date_to" value="%s" aria-label="%s" />', esc_attr( $date_to ), esc_attr__( 'To date', 'brainstudioz-mailpilot' ) );

		submit_button( __( 'Filter', 'brainstudioz-mailpilot' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Stage the page of items for prepare_items().
	 *
	 * @param array{items:array<int,Subscriber>,total:int} $query Result from the repository.
	 * @param int                                           $per_page Items per page.
	 */
	public function set_data( array $query, int $per_page ): void {
		$this->query    = $query;
		$this->per_page = $per_page;
	}

	/**
	 * Populate column headers, items, and pagination from the staged data.
	 *
	 * Overrides WP_List_Table::prepare_items(), which is otherwise an abstract
	 * stub that halts execution.
	 */
	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
		$this->items           = $this->query['items'];

		$this->set_pagination_args(
			[
				'total_items' => $this->query['total'],
				'per_page'    => $this->per_page,
				'total_pages' => (int) ceil( $this->query['total'] / max( 1, $this->per_page ) ),
			]
		);
	}
}
