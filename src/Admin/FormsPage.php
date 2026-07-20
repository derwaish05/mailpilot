<?php
/**
 * Forms admin page (list + drag-and-drop builder).
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Admin;

use MailPilot\Forms\DisplayTypeMode;
use MailPilot\Forms\FieldType;
use MailPilot\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists forms and mounts the drag-and-drop builder for create/edit. The builder
 * is a no-build-step JavaScript app that reads/writes through the Forms REST
 * endpoints; this controller renders its mount point, enqueues its assets, and
 * still handles list deletes server-side.
 */
final class FormsPage {

	public function __construct( private Plugin $plugin ) {}

	/**
	 * Hook delete handler and builder assets.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_mailpilot_delete_form', [ $this, 'delete' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_builder' ] );
	}

	/**
	 * Render the list or the builder.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mailpilot' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'edit' === $action || 'new' === $action ) {
			$this->render_builder();

			return;
		}

		$this->render_list();
	}

	/**
	 * Whether the current admin screen is the builder.
	 */
	private function is_builder_screen(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return ( AdminMenu::SLUG . '-forms' ) === $page && in_array( $action, [ 'edit', 'new' ], true );
	}

	/**
	 * Register + enqueue the builder assets and config on the builder screen.
	 */
	public function enqueue_builder(): void {
		if ( ! $this->is_builder_screen() || ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		// React Form Builder (replaces the legacy vanilla-JS builder in
		// assets/js/builder.js + assets/css/builder.css, which are left in
		// place but no longer enqueued here).
		$asset_file = MAILPILOT_PATH . 'assets/build/form-builder/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [ 'dependencies' => [ 'wp-element', 'wp-api-fetch' ], 'version' => MAILPILOT_VERSION ];

		wp_enqueue_style( 'mailpilot-builder', MAILPILOT_URL . 'assets/build/form-builder/index.css', [], $asset['version'] );
		wp_enqueue_script( 'mailpilot-builder', MAILPILOT_URL . 'assets/build/form-builder/index.js', $asset['dependencies'], $asset['version'], true );

		wp_enqueue_style(
			'mailpilot-builder-font',
			'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
			[],
			null
		);

		$form_id = isset( $_GET['form'] ) ? (int) $_GET['form'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_localize_script(
			'mailpilot-builder',
			'MailPilotBuilder',
			[
				'restBase'     => esc_url_raw( rest_url( 'mailpilot/v1/forms' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'formId'       => $form_id,
				'listUrl'      => esc_url_raw( add_query_arg( [ 'page' => AdminMenu::SLUG . '-forms' ], admin_url( 'admin.php' ) ) ),
				'editUrlBase'  => esc_url_raw( add_query_arg( [ 'page' => AdminMenu::SLUG . '-forms', 'action' => 'edit' ], admin_url( 'admin.php' ) ) ),
				'fieldTypes'   => $this->field_types(),
				'displayTypes' => $this->display_type_options(),
				'providers'    => $this->provider_options(),
			]
		);
	}

	/**
	 * Render the builder mount point.
	 */
	private function render_builder(): void {
		echo '<div class="wrap"><div id="mailpilot-builder-root" class="mailpilot-builder-loading">'
			. esc_html__( 'Loading builder…', 'mailpilot' )
			. '</div></div>';
	}

	/**
	 * Render the forms list.
	 */
	private function render_list(): void {
		$forms = $this->plugin->forms()->repository()->all();
		$new   = add_query_arg( [ 'page' => AdminMenu::SLUG . '-forms', 'action' => 'new' ], admin_url( 'admin.php' ) );

		echo '<div class="wrap">';
		printf(
			'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end" />',
			esc_html__( 'Forms', 'mailpilot' ),
			esc_url( $new ),
			esc_html__( 'Add New', 'mailpilot' )
		);

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Title', 'mailpilot' ) . '</th><th>' . esc_html__( 'Status', 'mailpilot' ) . '</th><th>' . esc_html__( 'Shortcode', 'mailpilot' ) . '</th><th>' . esc_html__( 'Fields', 'mailpilot' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';

		if ( ! $forms ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No forms yet.', 'mailpilot' ) . '</td></tr>';
		}

		foreach ( $forms as $form ) {
			$edit   = add_query_arg( [ 'page' => AdminMenu::SLUG . '-forms', 'action' => 'edit', 'form' => (int) $form->id ], admin_url( 'admin.php' ) );
			$delete = wp_nonce_url(
				add_query_arg( [ 'action' => 'mailpilot_delete_form', 'form' => (int) $form->id ], admin_url( 'admin-post.php' ) ),
				'mailpilot_delete_form_' . $form->id
			);

			printf(
				'<tr><td><strong><a href="%s">%s</a></strong></td><td>%s</td><td><code>[mailpilot_form id="%d"]</code></td><td>%d</td><td><a href="%s">%s</a> | <a href="%s" onclick="return confirm(\'%s\')">%s</a></td></tr>',
				esc_url( $edit ),
				esc_html( $form->title ?: __( '(untitled)', 'mailpilot' ) ),
				esc_html( ucfirst( $form->status ) ),
				(int) $form->id,
				count( $form->fields ),
				esc_url( $edit ),
				esc_html__( 'Edit', 'mailpilot' ),
				esc_url( $delete ),
				esc_attr__( 'Delete this form?', 'mailpilot' ),
				esc_html__( 'Delete', 'mailpilot' )
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Delete a form (list action).
	 */
	public function delete(): void {
		$id = isset( $_GET['form'] ) ? (int) $_GET['form'] : 0;
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'mailpilot_delete_form_' . $id ) ) {
			wp_die( esc_html__( 'Forbidden.', 'mailpilot' ) );
		}

		$this->plugin->forms()->repository()->delete( $id );
		wp_safe_redirect( add_query_arg( [ 'page' => AdminMenu::SLUG . '-forms' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Display-type options for the builder's "Display as" menu.
	 *
	 * Popup and Full Screen need MailPilot Pro's Popups module (trigger,
	 * frequency, A/B) to be deliverable, so they are hidden unless Pro is
	 * active — leaving the Free plugin to offer only what it can actually
	 * render: Inline, Floating Bar, and Slide In. See {@see DisplayTypeMode}.
	 *
	 * @return array<int, array{value:string,label:string}>
	 */
	private function display_type_options(): array {
		$options = [
			[ 'value' => 'inline', 'label' => __( 'Inline', 'mailpilot' ) ],
			[ 'value' => 'popup', 'label' => __( 'Popup', 'mailpilot' ) ],
			[ 'value' => 'floating_bar', 'label' => __( 'Floating Bar', 'mailpilot' ) ],
			[ 'value' => 'slide_in', 'label' => __( 'Slide In', 'mailpilot' ) ],
			[ 'value' => 'full_screen', 'label' => __( 'Full Screen', 'mailpilot' ) ],
		];

		if ( $this->pro_popups_active() ) {
			return $options;
		}

		return array_values(
			array_filter(
				$options,
				static fn ( array $opt ): bool => ! DisplayTypeMode::needs_pro_trigger( $opt['value'] )
			)
		);
	}

	/**
	 * Whether MailPilot Pro's Popups module is active and its feature unlocked.
	 * Mirrors {@see \MailPilot\Forms\FormRenderer::pro_popups_active()}.
	 */
	private function pro_popups_active(): bool {
		return did_action( 'mailpilot_pro_booted' ) > 0 && mailpilot()->license()->can( 'popups' );
	}

	/**
	 * Field-type palette data for the builder.
	 *
	 * @return array<int, array{type:string,label:string,hasOptions:bool}>
	 */
	private function field_types(): array {
		$labels = [
			'email'    => __( 'Email', 'mailpilot' ),
			'name'     => __( 'Name', 'mailpilot' ),
			'phone'    => __( 'Phone', 'mailpilot' ),
			'company'  => __( 'Company', 'mailpilot' ),
			'website'  => __( 'Website', 'mailpilot' ),
			'textarea' => __( 'Textarea', 'mailpilot' ),
			'dropdown' => __( 'Dropdown', 'mailpilot' ),
			'radio'    => __( 'Radio', 'mailpilot' ),
			'checkbox' => __( 'Checkbox', 'mailpilot' ),
			'date'     => __( 'Date', 'mailpilot' ),
			'number'   => __( 'Number', 'mailpilot' ),
			'hidden'   => __( 'Hidden', 'mailpilot' ),
			'gdpr'     => __( 'GDPR Checkbox', 'mailpilot' ),
		];

		$types = [];
		foreach ( FieldType::cases() as $case ) {
			$types[] = [
				'type'       => $case->value,
				'label'      => $labels[ $case->value ] ?? ucfirst( $case->value ),
				'hasOptions' => $case->has_options(),
			];
		}

		return $types;
	}

	/**
	 * Provider connections for the actions UI.
	 *
	 * @return array<int, array{id:int,label:string}>
	 */
	private function provider_options(): array {
		$options = [];
		foreach ( $this->plugin->provider_connections()->active() as $connection ) {
			$options[] = [
				'id'    => (int) $connection->id,
				'label' => ( $connection->label ?: $connection->provider ) . ' (#' . (int) $connection->id . ')',
			];
		}

		return $options;
	}
}
