<?php
/**
 * Provider connections admin page.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Admin;

use MailPilot\Plugin;
use MailPilot\Providers\Contracts\Provider;
use MailPilot\Providers\ProviderConnection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connect and manage email/CRM providers. The connect form adapts to the chosen
 * provider — it renders only that provider's credential fields, links to its API
 * docs, and fetches selectable lists live (no save required) into a dropdown.
 * Credentials are encrypted at rest.
 */
final class ProvidersPage {

	/**
	 * Transient prefix for the per-connection list id => name cache.
	 */
	private const LISTS_CACHE = 'mailpilot_lists_';

	public function __construct( private Plugin $plugin ) {}

	/**
	 * Hook the handlers + assets.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_mailpilot_save_provider', [ $this, 'save' ] );
		add_action( 'admin_post_mailpilot_delete_provider', [ $this, 'delete' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Whether the current screen is the Providers page.
	 */
	private function is_providers_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return ( AdminMenu::SLUG . '-providers' ) === $page;
	}

	/**
	 * Enqueue the connection-form script + styles and the provider metadata.
	 */
	public function enqueue(): void {
		if ( ! $this->is_providers_screen() || ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		wp_enqueue_style( 'mailpilot-providers', MAILPILOT_URL . 'assets/css/providers.css', [], MAILPILOT_VERSION );
		wp_enqueue_script( 'mailpilot-providers', MAILPILOT_URL . 'assets/js/providers.js', [ 'wp-i18n' ], MAILPILOT_VERSION, true );

		wp_localize_script(
			'mailpilot-providers',
			'MailPilotProviders',
			[
				'restBase'  => esc_url_raw( rest_url( 'mailpilot/v1/providers' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'providers' => $this->provider_metadata(),
			]
		);
	}

	/**
	 * Per-provider metadata that drives the dynamic form.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function provider_metadata(): array {
		$meta = [];

		foreach ( $this->plugin->providers()->all() as $provider ) {
			if ( ! $provider instanceof Provider ) {
				continue;
			}

			$caps = $provider->capabilities();

			$meta[ $provider->id() ] = [
				'label'          => $provider->label(),
				'guide'          => $provider->guide_url(),
				'listSelection'  => $caps->list_selection,
				'listLabel'      => $provider->list_label(),
				'tagSelection'   => $caps->tag_selection,
				'fieldMapping'   => $caps->custom_field_mapping,
				'doubleOptIn'    => $caps->double_opt_in,
				'fields'         => array_values( $provider->credential_fields() ),
			];
		}

		return $meta;
	}

	/**
	 * Map of provider slug => display label.
	 *
	 * @return array<string, string>
	 */
	private function provider_labels(): array {
		$labels = [];

		foreach ( $this->plugin->providers()->all() as $provider ) {
			if ( $provider instanceof Provider ) {
				$labels[ $provider->id() ] = $provider->label();
			}
		}

		return $labels;
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'brainstudioz-mailpilot' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$edit_id = isset( $_GET['connection'] ) ? (int) $_GET['connection'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap mailpilot-providers"><h1>' . esc_html__( 'Providers', 'brainstudioz-mailpilot' ) . '</h1>';

		if ( 'edit' === $action && $edit_id > 0 ) {
			$existing = $this->plugin->provider_connections()->find( $edit_id );
			if ( null !== $existing ) {
				$this->render_connection_form( $existing );
				echo '</div>';

				return;
			}
		}

		echo '<p class="description">' . esc_html__( 'Connect the email and CRM platforms MailPilot routes subscribers to. API keys are stored encrypted.', 'brainstudioz-mailpilot' ) . '</p>';

		$this->render_connections();
		$this->render_connection_form();

		echo '</div>';
	}

	/**
	 * Render existing connections.
	 */
	private function render_connections(): void {
		$connections = $this->plugin->provider_connections()->all();
		$labels      = $this->provider_labels();

		echo '<table class="widefat striped mailpilot-connections" style="max-width:960px;margin:12px 0"><thead><tr>';
		echo '<th>' . esc_html__( 'Provider', 'brainstudioz-mailpilot' ) . '</th><th>' . esc_html__( 'Label', 'brainstudioz-mailpilot' ) . '</th><th>' . esc_html__( 'Connection ID', 'brainstudioz-mailpilot' ) . '</th><th>' . esc_html__( 'List', 'brainstudioz-mailpilot' ) . '</th><th>' . esc_html__( 'Status', 'brainstudioz-mailpilot' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';

		if ( ! $connections ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No providers connected yet.', 'brainstudioz-mailpilot' ) . '</td></tr>';
		}

		foreach ( $connections as $connection ) {
			$edit   = add_query_arg(
				[ 'page' => AdminMenu::SLUG . '-providers', 'action' => 'edit', 'connection' => (int) $connection->id ],
				admin_url( 'admin.php' )
			);
			$delete = wp_nonce_url(
				add_query_arg( [ 'action' => 'mailpilot_delete_provider', 'connection' => (int) $connection->id ], admin_url( 'admin-post.php' ) ),
				'mailpilot_delete_provider_' . (int) $connection->id
			);
			$lists  = $this->list_display( $connection );
			$active = $connection->is_active();

			printf(
				'<tr><td>%s</td><td>%s</td><td><code>%d</code></td><td>%s</td><td><span class="mailpilot-badge %s">%s</span></td><td><a href="%s">%s</a> | <a href="%s" onclick="return confirm(\'%s\')">%s</a></td></tr>',
				esc_html( $labels[ $connection->provider ] ?? $connection->provider ),
				esc_html( $connection->label ),
				(int) $connection->id,
				'' !== $lists ? esc_html( $lists ) : '<span style="color:#d63638">' . esc_html__( 'none — set one', 'brainstudioz-mailpilot' ) . '</span>',
				esc_attr( $active ? 'mailpilot-badge-on' : 'mailpilot-badge-off' ),
				esc_html( $active ? __( 'CONNECTED', 'brainstudioz-mailpilot' ) : __( 'INACTIVE', 'brainstudioz-mailpilot' ) ),
				esc_url( $edit ),
				esc_html__( 'Edit', 'brainstudioz-mailpilot' ),
				esc_url( $delete ),
				esc_attr__( 'Disconnect this provider?', 'brainstudioz-mailpilot' ),
				esc_html__( 'Disconnect', 'brainstudioz-mailpilot' )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Use a Connection ID in a form\'s "Send to providers" action or a routing rule.', 'brainstudioz-mailpilot' ) . '</p>';
	}

	/**
	 * Render the add/edit connection form. Credential fields, the guide link, and
	 * the list selector are populated/toggled by providers.js for the chosen
	 * provider; all inputs render server-side so the form still works without JS.
	 *
	 * When editing, the API key is never re-shown — leave a credential blank to
	 * keep the saved value — and "Fetch lists" uses the stored credentials by id.
	 *
	 * @param ProviderConnection|null $existing Connection being edited, or null to add.
	 */
	private function render_connection_form( ?ProviderConnection $existing = null ): void {
		$editing      = null !== $existing;
		$current_list = $editing ? ( $existing->lists()[0] ?? '' ) : '';
		$tags_val     = $editing ? implode( ', ', array_map( 'strval', (array) $existing->setting( 'default_tags', [] ) ) ) : '';
		$map_val      = $editing ? $this->field_map_to_text( $existing->field_map ) : '';
		$back         = add_query_arg( [ 'page' => AdminMenu::SLUG . '-providers' ], admin_url( 'admin.php' ) );

		if ( $editing ) {
			printf(
				'<h2>%s</h2><p><a href="%s">&larr; %s</a></p>',
				esc_html__( 'Edit connection', 'brainstudioz-mailpilot' ),
				esc_url( $back ),
				esc_html__( 'Back to providers', 'brainstudioz-mailpilot' )
			);
		} else {
			echo '<h2>' . esc_html__( 'Connect a provider', 'brainstudioz-mailpilot' ) . '</h2>';
		}

		printf(
			'<form method="post" action="%s" class="mailpilot-connect-form" data-editing="%s" data-connection="%d">',
			esc_url( admin_url( 'admin-post.php' ) ),
			$editing ? '1' : '0',
			$editing ? (int) $existing->id : 0
		);
		echo '<input type="hidden" name="action" value="mailpilot_save_provider" />';
		if ( $editing ) {
			printf( '<input type="hidden" name="connection_id" value="%d" />', (int) $existing->id );
		}
		wp_nonce_field( 'mailpilot_save_provider' );
		echo '<table class="form-table"><tbody>';

		// Provider selector — locked when editing (credentials are provider-specific).
		echo '<tr><th scope="row">' . esc_html__( 'Provider', 'brainstudioz-mailpilot' ) . '</th><td>';
		printf( '<select id="mp-provider"%s%s>', $editing ? ' disabled' : '', $editing ? '' : ' name="provider"' );
		foreach ( $this->plugin->providers()->all() as $provider ) {
			if ( $provider instanceof Provider ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $provider->id() ),
					$editing && $provider->id() === $existing->provider ? ' selected' : '',
					esc_html( $provider->label() )
				);
			}
		}
		echo '</select>';
		if ( $editing ) {
			printf( '<input type="hidden" name="provider" value="%s" />', esc_attr( $existing->provider ) );
		}
		echo ' <span id="mp-guide" class="mailpilot-guide" style="display:none"></span>';
		echo '</td></tr>';

		$this->text_row( 'label', __( 'Label', 'brainstudioz-mailpilot' ), __( 'e.g. Main list', 'brainstudioz-mailpilot' ), $editing ? $existing->label : '' );

		// Every possible credential field; providers.js shows only the relevant
		// ones for the selected provider and sets their labels/placeholders.
		foreach ( $this->all_credential_fields() as $key => $label ) {
			$this->credential_row( $key, $label );
		}

		// List / Group / Campaign selector with a live fetch.
		echo '<tr class="mp-list-row"><th scope="row"><label for="mp-list" id="mp-list-label">' . esc_html__( 'List', 'brainstudioz-mailpilot' ) . '</label></th><td>';
		printf( '<select name="list_id" id="mp-list" data-current="%s">', esc_attr( $current_list ) );
		echo '<option value="">' . esc_html__( '— Fetch lists, or enter an ID manually —', 'brainstudioz-mailpilot' ) . '</option>';
		if ( '' !== $current_list ) {
			printf(
				'<option value="%1$s" selected>%2$s</option>',
				esc_attr( $current_list ),
				/* translators: %s: the saved list/audience id. */
				esc_html( sprintf( __( 'Current: %s', 'brainstudioz-mailpilot' ), $current_list ) )
			);
		}
		echo '</select> ';
		echo '<button type="button" class="button" id="mp-fetch-lists">' . esc_html__( 'Fetch lists', 'brainstudioz-mailpilot' ) . '</button>';
		echo '<span id="mp-fetch-status" class="mailpilot-fetch-status"></span>';
		echo '<p class="mp-manual-wrap"><a href="#" id="mp-manual-toggle">' . esc_html__( 'Enter ID manually', 'brainstudioz-mailpilot' ) . '</a> ';
		echo '<input type="text" name="list_id_manual" id="mp-list-manual" class="regular-text" style="display:none" placeholder="' . esc_attr__( 'List / Group / Campaign ID', 'brainstudioz-mailpilot' ) . '" /></p>';
		echo '</td></tr>';

		// Default tags — applied to every contact synced through this connection.
		printf(
			'<tr class="mp-cap-row" data-cap="tagSelection"><th scope="row"><label for="mp-default-tags">%s</label></th><td><input type="text" id="mp-default-tags" name="default_tags" value="%s" class="regular-text" placeholder="%s" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Default tags', 'brainstudioz-mailpilot' ),
			esc_attr( $tags_val ),
			esc_attr__( 'vip, newsletter', 'brainstudioz-mailpilot' ),
			esc_html__( 'Comma-separated. Applied to every contact synced through this connection.', 'brainstudioz-mailpilot' )
		);

		// Custom field mapping — local field key => provider field key, one per line.
		printf(
			'<tr class="mp-cap-row" data-cap="fieldMapping"><th scope="row"><label for="mp-field-map">%s</label></th><td><textarea id="mp-field-map" name="field_map" rows="4" class="large-text code" placeholder="%s">%s</textarea><p class="description">%s</p></td></tr>',
			esc_html__( 'Custom field mapping', 'brainstudioz-mailpilot' ),
			esc_attr__( "company = COMPANY\nbirthday = BIRTHDAY", 'brainstudioz-mailpilot' ),
			esc_textarea( $map_val ),
			esc_html__( 'One mapping per line, as local_key = provider_field_key. Maps your form/custom fields to the provider\'s fields.', 'brainstudioz-mailpilot' )
		);

		// Double opt-in.
		printf(
			'<tr class="mp-cap-row" data-cap="doubleOptIn"><th scope="row">%s</th><td><label><input type="checkbox" name="double_opt_in" value="1"%s /> %s</label></td></tr>',
			esc_html__( 'Double opt-in', 'brainstudioz-mailpilot' ),
			$editing && $existing->double_opt_in() ? ' checked' : '',
			esc_html__( 'Require subscribers to confirm by email', 'brainstudioz-mailpilot' )
		);
		echo '</tbody></table>';
		submit_button( $editing ? __( 'Save Changes', 'brainstudioz-mailpilot' ) : __( 'Connect Provider', 'brainstudioz-mailpilot' ) );
		echo '</form>';
	}

	/**
	 * Human-readable display for a connection's configured list(s): the resolved
	 * name with the id, falling back to the bare id when the name is unknown.
	 *
	 * @param ProviderConnection $connection Connection.
	 */
	private function list_display( ProviderConnection $connection ): string {
		$ids = $connection->lists();
		if ( ! $ids ) {
			return '';
		}

		$names = $this->list_names( $connection );
		$out   = [];
		foreach ( $ids as $id ) {
			$out[] = isset( $names[ $id ] ) ? $names[ $id ] . ' (' . $id . ')' : $id;
		}

		return implode( ', ', $out );
	}

	/**
	 * Resolve a connection's list id => name map, cached for an hour so the
	 * providers screen does not call the remote API on every load. The cache is
	 * cleared whenever the connection is saved or deleted.
	 *
	 * @param ProviderConnection $connection Connection.
	 * @return array<string, string>
	 */
	private function list_names( ProviderConnection $connection ): array {
		$key    = self::LISTS_CACHE . (int) $connection->id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$provider = $this->plugin->providers()->get( $connection->provider );
		$map      = [];
		if ( $provider instanceof Provider ) {
			foreach ( $provider->get_lists( $connection ) as $list ) {
				$map[ (string) ( $list['id'] ?? '' ) ] = (string) ( $list['name'] ?? '' );
			}
		}

		set_transient( $key, $map, HOUR_IN_SECONDS );

		return $map;
	}

	/**
	 * Format a field map (local => provider) as editable "local = provider" lines.
	 *
	 * @param array<string, string> $map Field map.
	 */
	private function field_map_to_text( array $map ): string {
		$lines = [];
		foreach ( $map as $local => $provider_key ) {
			$lines[] = $local . ' = ' . $provider_key;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Parse "local = provider" lines back into a sanitized field map.
	 *
	 * @param string $text Raw textarea content.
	 * @return array<string, string>
	 */
	private function parse_field_map( string $text ): array {
		$map = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) ?: [] as $line ) {
			if ( ! str_contains( $line, '=' ) ) {
				continue;
			}
			[ $local, $provider_key ] = array_map( 'trim', explode( '=', $line, 2 ) );
			$local                    = sanitize_key( $local );
			if ( '' === $local || '' === $provider_key ) {
				continue;
			}
			$map[ $local ] = sanitize_text_field( $provider_key );
		}

		return $map;
	}

	/**
	 * The union of every credential field across all providers (key => label).
	 *
	 * @return array<string, string>
	 */
	private function all_credential_fields(): array {
		$fields = [];

		foreach ( $this->plugin->providers()->all() as $provider ) {
			if ( ! $provider instanceof Provider ) {
				continue;
			}
			foreach ( $provider->credential_fields() as $field ) {
				$fields[ (string) $field['key'] ] = (string) $field['label'];
			}
		}

		return $fields;
	}

	/**
	 * Persist a connection.
	 */
	public function save(): void {
		$this->guard( 'mailpilot_save_provider' );

		// The CSRF nonce and capability are verified in guard() above. PHPCS can't
		// see through the wrapper, so the $_POST reads below are safe.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().

		$connection_id = isset( $_POST['connection_id'] ) ? (int) $_POST['connection_id'] : 0;
		$existing      = $connection_id > 0 ? $this->plugin->provider_connections()->find( $connection_id ) : null;

		// On edit the provider is fixed to the saved one; otherwise use the choice.
		$slug = null !== $existing
			? $existing->provider
			: ( isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '' );

		$provider = $this->plugin->providers()->get( $slug );

		// Pull the credentials the provider declares. When editing, a blank field
		// keeps the previously-saved value (so the API key need not be re-entered).
		$credentials = null !== $existing ? $existing->credentials : [];
		if ( $provider instanceof Provider ) {
			foreach ( $provider->credential_fields() as $field ) {
				$key   = (string) $field['key'];
				$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$value = is_string( $value ) ? trim( $value ) : '';
				if ( '' === $value ) {
					continue; // Keep any existing value for this credential.
				}
				$credentials[ $key ] = 'api_url' === $key ? esc_url_raw( $value ) : sanitize_text_field( $value );
			}
		}

		// List id: the fetched dropdown wins; fall back to the manual input; then
		// to the previously-saved list when editing.
		$list = isset( $_POST['list_id'] ) ? sanitize_text_field( wp_unslash( $_POST['list_id'] ) ) : '';
		if ( '' === $list && isset( $_POST['list_id_manual'] ) ) {
			$list = sanitize_text_field( wp_unslash( $_POST['list_id_manual'] ) );
		}
		if ( '' === $list && null !== $existing ) {
			$list = $existing->lists()[0] ?? '';
		}

		// Default tags applied to every contact synced through this connection.
		$default_tags = [];
		if ( isset( $_POST['default_tags'] ) ) {
			$default_tags = array_values( array_filter( array_map(
				'sanitize_text_field',
				array_map( 'trim', explode( ',', wp_unslash( $_POST['default_tags'] ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			) ) );
		}

		// Custom field map: parse the textarea, else keep what was saved.
		$field_map = isset( $_POST['field_map'] )
			? $this->parse_field_map( wp_unslash( $_POST['field_map'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: ( null !== $existing ? $existing->field_map : [] );

		$connection = new ProviderConnection(
			id: null !== $existing ? (int) $existing->id : null,
			provider: $slug,
			label: isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : ( $existing->label ?? '' ),
			status: 'active',
			credentials: $credentials,
			settings: [
				'lists'         => '' !== $list ? [ $list ] : [],
				'double_opt_in' => ! empty( $_POST['double_opt_in'] ),
				'default_tags'  => $default_tags,
			],
			field_map: $field_map,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$saved_id = $this->plugin->provider_connections()->save( $connection );

		// Drop the cached list names so the table reflects the chosen audience.
		delete_transient( self::LISTS_CACHE . (int) $saved_id );

		wp_safe_redirect( add_query_arg( [ 'page' => AdminMenu::SLUG . '-providers', 'saved' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Delete a connection.
	 */
	public function delete(): void {
		$id = isset( $_GET['connection'] ) ? (int) $_GET['connection'] : 0;
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'mailpilot_delete_provider_' . $id ) ) {
			wp_die( esc_html__( 'Forbidden.', 'brainstudioz-mailpilot' ) );
		}

		$this->plugin->provider_connections()->delete( $id );
		delete_transient( self::LISTS_CACHE . $id );

		wp_safe_redirect( add_query_arg( [ 'page' => AdminMenu::SLUG . '-providers' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Capability + nonce guard.
	 *
	 * @param string $nonce Nonce action.
	 */
	private function guard( string $nonce ): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'brainstudioz-mailpilot' ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Render a plain text settings row.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Label.
	 * @param string $placeholder Placeholder/help.
	 * @param string $value       Prefilled value.
	 */
	private function text_row( string $name, string $label, string $placeholder, string $value = '' ): void {
		printf(
			'<tr><th scope="row"><label for="mp-%1$s">%2$s</label></th><td><input type="text" id="mp-%1$s" name="%1$s" value="%4$s" class="regular-text" placeholder="%3$s" /></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $placeholder ),
			esc_attr( $value )
		);
	}

	/**
	 * Render a credential row, hidden by default and revealed per provider by JS.
	 *
	 * @param string $key   Credential key.
	 * @param string $label Default label.
	 */
	private function credential_row( string $key, string $label ): void {
		printf(
			'<tr class="mp-cred-row" data-cred="%1$s" style="display:none"><th scope="row"><label for="mp-cred-%1$s" class="mp-cred-label">%2$s</label></th><td><input type="text" id="mp-cred-%1$s" name="%1$s" value="" class="regular-text mp-cred-input" autocomplete="off" placeholder="" /></td></tr>',
			esc_attr( $key ),
			esc_html( $label )
		);
	}
}
