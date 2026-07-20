<?php
/**
 * Plugin integrations admin page (list + per-integration config).
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Admin;

use MailPilot\Integrations\Contracts\Integration;
use MailPilot\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists plugin integrations and provides a dedicated configuration page per
 * integration — setup instructions plus the settings the AbstractIntegration
 * base reads (`mailpilot_integration_{id}`): enabled, tags, lists, providers,
 * email field, and custom field mapping.
 */
final class IntegrationsPage {

	/**
	 * Option prefix mirroring AbstractIntegration.
	 */
	private const OPTION_PREFIX = 'mailpilot_integration_';

	public function __construct( private Plugin $plugin ) {}

	/**
	 * Hook the save handler.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_mailpilot_save_integration', [ $this, 'save' ] );
	}

	/**
	 * Render the list or a single integration's config page.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mailpilot' ) );
		}

		$id = isset( $_GET['integration'] ) ? sanitize_key( wp_unslash( $_GET['integration'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $id ) {
			$this->render_detail( $id );

			return;
		}

		$this->render_list();
	}

	/**
	 * The registered integrations, keyed by id.
	 *
	 * @return array<string, Integration>
	 */
	private function integrations(): array {
		$out = [];
		foreach ( $this->plugin->integrations()->all() as $integration ) {
			if ( $integration instanceof Integration ) {
				$out[ $integration->id() ] = $integration;
			}
		}

		return $out;
	}

	/**
	 * Render the integrations list with a Configure link each.
	 */
	private function render_list(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Integrations', 'mailpilot' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Capture subscribers from WordPress and other plugins. Open an integration to read its setup guide and configure it.', 'mailpilot' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Integration', 'mailpilot' ) . '</th><th>' . esc_html__( 'Host', 'mailpilot' ) . '</th><th>' . esc_html__( 'Status', 'mailpilot' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $this->integrations() as $id => $integration ) {
			$config = $this->config( $id );
			$url    = add_query_arg(
				[ 'page' => AdminMenu::SLUG . '-integrations', 'integration' => $id ],
				admin_url( 'admin.php' )
			);

			printf(
				'<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td><a href="%s" class="button">%s</a></td></tr>',
				esc_html( $integration->label() ),
				$integration->is_available()
					? '<span style="color:#00a32a">' . esc_html__( 'Active', 'mailpilot' ) . '</span>'
					: '<span style="color:#646970">' . esc_html__( 'Not installed', 'mailpilot' ) . '</span>',
				! empty( $config['enabled'] )
					? '<span class="dashicons dashicons-yes" style="color:#00a32a"></span> ' . esc_html__( 'Enabled', 'mailpilot' )
					: esc_html__( 'Disabled', 'mailpilot' ),
				esc_url( $url ),
				esc_html__( 'Configure', 'mailpilot' )
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Render one integration's config page with its setup guide.
	 *
	 * @param string $id Integration id.
	 */
	private function render_detail( string $id ): void {
		$integration = $this->integrations()[ $id ] ?? null;
		if ( null === $integration ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Integration not found', 'mailpilot' ) . '</h1></div>';

			return;
		}

		$config = $this->config( $id );
		$back   = add_query_arg( [ 'page' => AdminMenu::SLUG . '-integrations' ], admin_url( 'admin.php' ) );

		echo '<div class="wrap">';
		printf(
			'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end" />',
			esc_html( $integration->label() ),
			esc_url( $back ),
			esc_html__( 'All integrations', 'mailpilot' )
		);

		if ( ! $integration->is_available() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The host plugin for this integration is not active. Settings are saved, but capture only happens once the host is installed and active.', 'mailpilot' ) . '</p></div>';
		}

		// Setup guide.
		echo '<div class="postbox" style="max-width:780px"><h2 class="hndle" style="padding:8px 12px">' . esc_html__( 'How it works', 'mailpilot' ) . '</h2><div class="inside">';
		echo wp_kses_post( wpautop( $this->guide( $id, $integration->label() ) ) );
		echo '</div></div>';

		// Settings form.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:780px">';
		echo '<input type="hidden" name="action" value="mailpilot_save_integration" />';
		printf( '<input type="hidden" name="integration_id" value="%s" />', esc_attr( $id ) );
		wp_nonce_field( 'mailpilot_save_integration' );

		echo '<table class="form-table"><tbody>';

		printf(
			'<tr><th>%s</th><td><label><input type="checkbox" name="enabled" value="1" %s /> %s</label></td></tr>',
			esc_html__( 'Enable', 'mailpilot' ),
			checked( ! empty( $config['enabled'] ), true, false ),
			esc_html__( 'Capture subscribers from this integration', 'mailpilot' )
		);

		// Consent / GDPR controls.
		echo '<tr><th colspan="2"><h2 style="margin:8px 0 0">' . esc_html__( 'Consent (GDPR)', 'mailpilot' ) . '</h2></th></tr>';
		printf(
			'<tr><th>%s</th><td><label><input type="checkbox" name="consent_auto" value="1" %s /> %s</label><p class="description">%s</p></td></tr>',
			esc_html__( 'Respect consent checkbox', 'mailpilot' ),
			checked( ! empty( $config['consent_auto'] ), true, false ),
			esc_html__( 'Only subscribe when a consent checkbox on the form is ticked', 'mailpilot' ),
			esc_html__( 'Auto-detects common GDPR/consent/privacy/terms/opt-in checkboxes — works with any form plugin, no field name needed. Forms without a consent checkbox are still collected.', 'mailpilot' )
		);
		$this->text_row( 'consent_field', __( 'Exact consent field', 'mailpilot' ), (string) ( $config['consent_field'] ?? '' ), __( 'optional — a specific field name to require (overrides auto-detect)', 'mailpilot' ) );
		printf(
			'<tr><th>%s</th><td><label><input type="checkbox" name="double_opt_in" value="1" %s /> %s</label><p class="description">%s</p></td></tr>',
			esc_html__( 'Double opt-in', 'mailpilot' ),
			checked( ! empty( $config['double_opt_in'] ), true, false ),
			esc_html__( 'Capture as Pending until the subscriber confirms', 'mailpilot' ),
			esc_html__( 'Recommended in the EU/UK: subscribers are not emailed or synced until they confirm. Pending contacts are never sent to a provider.', 'mailpilot' )
		);
		echo '<tr><th colspan="2"><h2 style="margin:8px 0 0">' . esc_html__( 'Routing', 'mailpilot' ) . '</h2></th></tr>';

		$this->text_row( 'tags', __( 'Apply tags', 'mailpilot' ), implode( ', ', (array) ( $config['tags'] ?? [] ) ), __( 'comma-separated', 'mailpilot' ) );
		$this->text_row( 'lists', __( 'Apply lists', 'mailpilot' ), implode( ', ', (array) ( $config['lists'] ?? [] ) ), __( 'provider list IDs, comma-separated', 'mailpilot' ) );
		$this->text_row( 'providers', __( 'Sync to providers', 'mailpilot' ), implode( ', ', (array) ( $config['providers'] ?? [] ) ), __( 'provider connection IDs (see Providers)', 'mailpilot' ) );
		$this->text_row( 'email_field', __( 'Email field', 'mailpilot' ), (string) ( $config['email_field'] ?? '' ), __( 'leave blank to auto-detect', 'mailpilot' ) );

		// Field mapping.
		$map_lines = [];
		foreach ( (array) ( $config['field_map'] ?? [] ) as $host => $local ) {
			$map_lines[] = $host . '=' . $local;
		}
		printf(
			'<tr><th><label for="mp-field_map">%s</label></th><td><textarea id="mp-field_map" name="field_map" rows="4" class="large-text" placeholder="%s">%s</textarea><p class="description">%s</p></td></tr>',
			esc_html__( 'Field mapping', 'mailpilot' ),
			esc_attr__( "host_field=first_name\ncompany_name=company", 'mailpilot' ),
			esc_textarea( implode( "\n", $map_lines ) ),
			esc_html__( 'One per line: source field = MailPilot field (first_name, last_name, phone, company, country, or a custom key).', 'mailpilot' )
		);

		echo '</tbody></table>';
		submit_button( __( 'Save Integration', 'mailpilot' ) );
		echo '</form></div>';
	}

	/**
	 * Persist one integration's config.
	 */
	public function save(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'mailpilot' ) );
		}
		check_admin_referer( 'mailpilot_save_integration' );

		$id = isset( $_POST['integration_id'] ) ? sanitize_key( wp_unslash( $_POST['integration_id'] ) ) : '';
		if ( '' === $id ) {
			$this->redirect( '' );
		}

		update_option(
			self::OPTION_PREFIX . $id,
			[
				'enabled'       => ! empty( $_POST['enabled'] ),
				'tags'          => $this->csv( isset( $_POST['tags'] ) ? wp_unslash( $_POST['tags'] ) : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- csv() sanitises each value via sanitize_text_field().
				'lists'         => $this->csv( isset( $_POST['lists'] ) ? wp_unslash( $_POST['lists'] ) : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- csv() sanitises each value via sanitize_text_field().
				'providers'     => array_map( 'intval', $this->csv( isset( $_POST['providers'] ) ? wp_unslash( $_POST['providers'] ) : '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- csv() sanitises; intval() casts each item.
				'email_field'   => isset( $_POST['email_field'] ) ? sanitize_text_field( wp_unslash( $_POST['email_field'] ) ) : '',
				'consent_field' => isset( $_POST['consent_field'] ) ? sanitize_text_field( wp_unslash( $_POST['consent_field'] ) ) : '',
				'consent_auto'  => ! empty( $_POST['consent_auto'] ),
				'double_opt_in' => ! empty( $_POST['double_opt_in'] ),
				'field_map'     => $this->parse_field_map( isset( $_POST['field_map'] ) ? wp_unslash( $_POST['field_map'] ) : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			],
			false
		);

		$this->redirect( $id );
	}

	/**
	 * Setup guide text per integration (HTML allowed).
	 *
	 * @param string $id    Integration id.
	 * @param string $label Integration label.
	 */
	private function guide( string $id, string $label ): string {
		$form_plugins = [ 'cf7', 'wpforms', 'gravity_forms', 'ninja_forms', 'fluent_forms', 'formidable', 'jetformbuilder' ];

		if ( 'wp_comments' === $id ) {
			return __( 'When enabled, anyone who posts a comment is captured as a subscriber using their name and email. Use this to grow your list from blog engagement.', 'mailpilot' );
		}
		if ( 'wp_registration' === $id ) {
			return __( 'When enabled, every new WordPress user registration is captured as a subscriber. Their role is recorded so routing rules can target it.', 'mailpilot' );
		}
		if ( in_array( $id, $form_plugins, true ) ) {
			return sprintf(
				/* translators: %s: plugin label. */
				__( 'When enabled, any submission to a %s form is captured automatically — no shortcode required. The email field is auto-detected (the first valid email); set "Email field" to override it. <strong>For GDPR:</strong> enable "Respect consent checkbox" to only subscribe when a consent box on the form is ticked, and/or turn on Double opt-in so subscribers confirm first. Apply tags, push to provider connections, and map source fields below.', 'mailpilot' ),
				$label
			);
		}

		// Membership / LMS / events / donation / affiliate.
		$contextual = [
			'memberpress'         => __( 'Members are captured on signup, with their membership level recorded for routing.', 'mailpilot' ),
			'pmpro'               => __( 'Members are captured when their level changes (cancellations are ignored); the level id is recorded for routing.', 'mailpilot' ),
			'ultimate_member'     => __( 'Members are captured when registration completes.', 'mailpilot' ),
			'buddypress'          => __( 'Members are captured when their account is activated.', 'mailpilot' ),
			'learndash'           => __( 'Learners are captured on course enrollment; the course id is recorded for routing.', 'mailpilot' ),
			'tutor_lms'           => __( 'Learners are captured on course enrollment; the course id is recorded for routing.', 'mailpilot' ),
			'lifterlms'           => __( 'Students are captured on course enrollment; the course id is recorded for routing.', 'mailpilot' ),
			'events_manager'      => __( 'Attendees are captured when a booking is added; the event id is recorded.', 'mailpilot' ),
			'the_events_calendar' => __( 'RSVP attendees are captured; the event id is recorded.', 'mailpilot' ),
			'givewp'              => __( 'Donors are captured on a completed donation; the amount is recorded for routing.', 'mailpilot' ),
			'affiliatewp'         => __( 'Affiliates are captured when they register; the affiliate id is recorded.', 'mailpilot' ),
		];

		return $contextual[ $id ] ?? sprintf(
			/* translators: %s: integration label. */
			__( 'When enabled, %s events are captured into your subscriber list. Configure tags, providers, and field mapping below.', 'mailpilot' ),
			$label
		);
	}

	/**
	 * Read an integration's stored config.
	 *
	 * @param string $id Integration id.
	 * @return array<string, mixed>
	 */
	private function config( string $id ): array {
		$stored = get_option( self::OPTION_PREFIX . $id, [] );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Parse a field-map textarea into an array.
	 *
	 * @param string $raw Raw textarea value.
	 * @return array<string, string>
	 */
	private function parse_field_map( string $raw ): array {
		$map = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: [] as $line ) {
			if ( ! str_contains( $line, '=' ) ) {
				continue;
			}
			[ $host, $local ] = array_map( 'trim', explode( '=', $line, 2 ) );
			$host             = sanitize_text_field( $host );
			$local            = sanitize_key( $local );
			if ( '' !== $host && '' !== $local ) {
				$map[ $host ] = $local;
			}
		}

		return $map;
	}

	/**
	 * Split a comma-separated string.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	private function csv( mixed $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( (string) $value ) ) ) ) );
	}

	/**
	 * Render a text settings row.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Label.
	 * @param string $value       Current value.
	 * @param string $placeholder Placeholder/help.
	 */
	private function text_row( string $name, string $label, string $value, string $placeholder ): void {
		printf(
			'<tr><th scope="row"><label for="mp-%1$s">%2$s</label></th><td><input type="text" id="mp-%1$s" name="%1$s" value="%3$s" class="regular-text" placeholder="%4$s" /></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Redirect back (to detail when id given, else the list).
	 *
	 * @param string $id Integration id.
	 */
	private function redirect( string $id ): void {
		$args = [ 'page' => AdminMenu::SLUG . '-integrations', 'saved' => 1 ];
		if ( '' !== $id ) {
			$args['integration'] = $id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
