<?php
/**
 * Admin menu and pages.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Admin;

use MailPilot\Plugin;
use MailPilot\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the top-level MailPilot admin menu and its pages.
 */
final class AdminMenu {

	/**
	 * Capability required to manage MailPilot.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Top-level menu slug.
	 */
	public const SLUG = 'mailpilot';

	public function __construct( private Plugin $plugin ) {}

	/**
	 * Hook menu registration and admin asset enqueue.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
		add_action( 'admin_init', [ $this, 'redirect_legacy_slugs' ] );
	}

	/**
	 * Redirect obsolete Pro admin page slugs (whose PHP screens were removed in
	 * favour of the React screens + REST) to their canonical routes, so old
	 * bookmarks keep working. The popup Design builder keeps its own hidden page,
	 * so its slug is intentionally not redirected.
	 */
	public function redirect_legacy_slugs(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		$map = [
			'mailpilot-pro-routing'      => self::SLUG . '-audience-routing',
			'mailpilot-pro-analytics'    => self::SLUG . '-analytics',
			'mailpilot-pro-automation'   => self::SLUG . '-automations',
			'mailpilot-pro-lead-magnets' => self::SLUG . '-lead-magnets',
			'mailpilot-pro-ai'           => self::SLUG . '-ai-assistant',
			'mailpilot-pro-popups'       => self::SLUG . '-popups',
			'mailpilot-pro-license'      => self::SLUG . '-license',
			'mailpilot-pro-migration'    => self::SLUG . '-import',
		];

		if ( isset( $map[ $page ] ) && current_user_can( self::CAPABILITY ) ) {
			wp_safe_redirect( add_query_arg( [ 'page' => $map[ $page ] ], admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	/**
	 * Whether the Pro add-on is active. Pro-only menu entries are hidden without
	 * it, so a Core-only install shows only Core modules and deactivating Pro
	 * removes its modules cleanly. Pro can also force this on via the filter.
	 */
	private function is_pro_active(): bool {
		return (bool) apply_filters( 'mailpilot_admin_is_pro', class_exists( '\\MailPilot\\Pro\\Plugin' ) );
	}

	/**
	 * The single, canonical admin module manifest — the one source of truth for
	 * both the WordPress menu (this class) and the React navigation (localized in
	 * {@see self::enqueue_admin()}). Every visible admin page is declared here
	 * exactly once. Third parties (and the Pro add-on) may add or adjust entries
	 * through the `mailpilot_admin_modules` filter; entries are then normalized
	 * by slug so a duplicate slug can never register twice.
	 *
	 * Each entry: slug, label, screen (React screen key, or null for a PHP page),
	 * cb (render callback), pro (whether it requires the Pro add-on).
	 *
	 * @return array<int, array{slug:string,label:string,screen:?string,cb:callable,pro:bool}>
	 */
	private function modules(): array {
		$screen = fn ( string $key ): callable => function () use ( $key ): void {
			$this->render_screen( $key );
		};

		$modules = [
			[ 'slug' => self::SLUG, 'label' => __( 'Dashboard', 'mailpilot' ), 'screen' => 'dashboard', 'cb' => [ $this, 'render_dashboard' ], 'pro' => false ],
			[ 'slug' => self::SLUG . '-subscribers', 'label' => __( 'Subscribers', 'mailpilot' ), 'screen' => 'subscribers', 'cb' => [ $this, 'render_subscribers' ], 'pro' => false ],
			[ 'slug' => self::SLUG . '-forms', 'label' => __( 'Forms', 'mailpilot' ), 'screen' => 'forms', 'cb' => [ $this, 'render_forms' ], 'pro' => false ],
			[ 'slug' => self::SLUG . '-providers', 'label' => __( 'Providers', 'mailpilot' ), 'screen' => 'providers', 'cb' => [ $this, 'render_providers' ], 'pro' => false ],
			[ 'slug' => self::SLUG . '-integrations', 'label' => __( 'Integrations', 'mailpilot' ), 'screen' => 'integrations', 'cb' => [ $this, 'render_integrations' ], 'pro' => false ],
			[ 'slug' => self::SLUG . '-analytics', 'label' => __( 'Analytics', 'mailpilot' ), 'screen' => 'analytics', 'cb' => $screen( 'analytics' ), 'pro' => false ],
			[ 'slug' => self::SLUG . '-automations', 'label' => __( 'Automations', 'mailpilot' ), 'screen' => 'automations', 'cb' => $screen( 'automations' ), 'pro' => true ],
			[ 'slug' => self::SLUG . '-audience-routing', 'label' => __( 'Audience Routing', 'mailpilot' ), 'screen' => 'audience-routing', 'cb' => $screen( 'audience-routing' ), 'pro' => true ],
			[ 'slug' => self::SLUG . '-popups', 'label' => __( 'Popups', 'mailpilot' ), 'screen' => 'popups', 'cb' => $screen( 'popups' ), 'pro' => true ],
			[ 'slug' => self::SLUG . '-lead-magnets', 'label' => __( 'Lead Magnets', 'mailpilot' ), 'screen' => 'lead-magnets', 'cb' => $screen( 'lead-magnets' ), 'pro' => true ],
			[ 'slug' => self::SLUG . '-ai-assistant', 'label' => __( 'AI Assistant', 'mailpilot' ), 'screen' => 'ai-assistant', 'cb' => $screen( 'ai-assistant' ), 'pro' => true ],
			[ 'slug' => self::SLUG . '-import', 'label' => __( 'Import & Migration', 'mailpilot' ), 'screen' => 'import-migration', 'cb' => $screen( 'import-migration' ), 'pro' => true ],
			[ 'slug' => self::SLUG . '-license', 'label' => __( 'License', 'mailpilot' ), 'screen' => 'license', 'cb' => $screen( 'license' ), 'pro' => true ],
			[ 'slug' => self::SLUG . '-settings', 'label' => __( 'Settings', 'mailpilot' ), 'screen' => null, 'cb' => [ $this, 'render_settings' ], 'pro' => false ],
		];

		/**
		 * Filter the admin module manifest. Add-ons should contribute their own
		 * modules here rather than calling add_submenu_page() directly, so the
		 * menu has a single owner and slugs cannot collide.
		 *
		 * @param array $modules The module manifest.
		 */
		$modules = (array) apply_filters( 'mailpilot_admin_modules', $modules );

		// Normalize: drop malformed/duplicate slugs, and Pro entries when Pro is
		// inactive, so nothing registers twice and Core-only stays clean.
		$pro_active = $this->is_pro_active();
		$seen       = [];
		$normalized = [];
		foreach ( $modules as $module ) {
			$slug = isset( $module['slug'] ) ? (string) $module['slug'] : '';
			if ( '' === $slug || isset( $seen[ $slug ] ) || ! is_callable( $module['cb'] ?? null ) ) {
				continue;
			}
			if ( ! empty( $module['pro'] ) && ! $pro_active ) {
				continue;
			}
			$seen[ $slug ] = true;
			$normalized[]  = $module;
		}

		return $normalized;
	}

	/**
	 * The React screen map (slug → screen key) derived from the manifest, for the
	 * asset enqueue to know which pages the `admin` bundle serves.
	 *
	 * @return array<string, string>
	 */
	private function screens(): array {
		$map = [];
		foreach ( $this->modules() as $module ) {
			if ( ! empty( $module['screen'] ) ) {
				$map[ $module['slug'] ] = (string) $module['screen'];
			}
		}

		return $map;
	}

	/**
	 * Register the top-level menu and each manifest submenu exactly once.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'MailPilot', 'mailpilot' ),
			__( 'MailPilot', 'mailpilot' ),
			self::CAPABILITY,
			self::SLUG,
			[ $this, 'render_dashboard' ],
			'dashicons-email-alt',
			58
		);

		foreach ( $this->modules() as $module ) {
			add_submenu_page(
				self::SLUG,
				$module['label'],
				$module['label'],
				self::CAPABILITY,
				$module['slug'],
				$module['cb']
			);
		}
	}

	/**
	 * Current admin page slug (`?page=`), sanitized.
	 */
	private function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}

	/**
	 * Whether the current request targets a functional sub-view that is still
	 * rendered by a legacy PHP page (builder, profile, edit/detail forms)
	 * rather than a redesigned React list screen.
	 *
	 * @param string $page Current page slug.
	 */
	private function is_functional_subview( string $page ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		switch ( $page ) {
			case self::SLUG . '-forms':
				return in_array( $action, [ 'edit', 'new' ], true );
			case self::SLUG . '-subscribers':
				return 'view' === $action && ! empty( $_REQUEST['subscriber'] );
			case self::SLUG . '-providers':
				return 'edit' === $action && ! empty( $_GET['connection'] );
			case self::SLUG . '-integrations':
				return ! empty( $_GET['integration'] );
			default:
				return false;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Enqueue the React `admin` bundle on any screen it serves, with the
	 * per-screen config the JS reads from `window.MailPilotAdmin`.
	 */
	public function enqueue_admin(): void {
		$page    = $this->current_page();
		$screens = $this->screens();
		if ( ! isset( $screens[ $page ] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// Functional sub-views (Forms builder, subscriber profile, provider
		// edit, integration detail) are still served by their PHP pages — the
		// redesigned React bundle only owns the top-level list screens.
		if ( $this->is_functional_subview( $page ) ) {
			return;
		}

		$asset_file = MAILPILOT_PATH . 'assets/build/admin/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [ 'dependencies' => [ 'wp-element' ], 'version' => MAILPILOT_VERSION ];

		wp_enqueue_style( 'mailpilot-admin', MAILPILOT_URL . 'assets/build/admin/index.css', [], $asset['version'] );
		wp_enqueue_script( 'mailpilot-admin', MAILPILOT_URL . 'assets/build/admin/index.js', $asset['dependencies'], $asset['version'], true );

		wp_enqueue_style(
			'mailpilot-admin-font',
			'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
			[],
			null
		);

		$screen = $screens[ $page ];

		wp_localize_script(
			'mailpilot-admin',
			'MailPilotAdmin',
			[
				'screen'       => $screen,
				'restBase'     => esc_url_raw( rest_url( 'mailpilot/v1' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'newFormUrl'   => esc_url_raw( add_query_arg( [ 'page' => self::SLUG . '-forms', 'action' => 'new' ], admin_url( 'admin.php' ) ) ),
				'providersUrl' => esc_url_raw( add_query_arg( [ 'page' => self::SLUG . '-providers' ], admin_url( 'admin.php' ) ) ),
				'dashboard'    => 'dashboard' === $screen ? $this->dashboard_data() : null,
				'forms'        => 'forms' === $screen ? $this->forms_data() : null,
				'subscribers'  => 'subscribers' === $screen ? $this->subscribers_data() : null,
				'providers'    => 'providers' === $screen ? $this->providers_data() : null,
				'integrations' => 'integrations' === $screen ? $this->integrations_data() : null,
				'license'      => 'license' === $screen ? $this->license_data() : null,
				'analytics'    => 'analytics' === $screen ? $this->analytics_data() : null,
				'audienceRouting' => 'audience-routing' === $screen ? $this->audience_routing_data() : null,
				'automations'  => 'automations' === $screen ? $this->automations_data() : null,
				'popups'        => 'popups' === $screen ? $this->popups_data() : null,
				'leadMagnets'   => 'lead-magnets' === $screen ? $this->lead_magnets_data() : null,
				'aiAssistant'   => 'ai-assistant' === $screen ? $this->ai_assistant_data() : null,
			]
		);
	}

	/**
	 * Render a React mount node for a redesigned screen.
	 *
	 * @param string $screen Screen key the JS router will render.
	 */
	private function render_screen( string $screen ): void {
		$this->guard();

		printf(
			'<div class="wrap mailpilot-admin-wrap"><div id="mailpilot-admin-root" class="mailpilot-admin-loading" data-screen="%s">%s</div></div>',
			esc_attr( $screen ),
			esc_html__( 'Loading…', 'mailpilot' )
		);
	}

	/**
	 * Build the Dashboard screen payload from live analytics.
	 *
	 * @return array<string, mixed>
	 */
	private function dashboard_data(): array {
		$analytics = new \MailPilot\Analytics\Analytics();
		$license   = $this->plugin->license();
		$usage     = $this->plugin->usage();
		$cap        = $usage->cap();
		$pending    = (int) $this->plugin->queue()->count( 'pending' );

		$growth     = $analytics->growth_rate( 30 );
		$growth_str = ( $growth >= 0 ? '+' : '' ) . $growth . '%';

		$to_rows = static function ( array $data ): array {
			$rows = [];
			foreach ( $data as $label => $count ) {
				$rows[] = [ 'name' => ucfirst( (string) $label ), 'count' => (int) $count ];
			}
			return $rows;
		};

		return [
			'kpis'  => [
				[ 'label' => __( 'Subscribers', 'mailpilot' ), 'value' => number_format_i18n( $analytics->subscriber_count() ), 'icon' => 'users', 'hue' => 'blue' ],
				[ 'label' => __( 'Growth rate', 'mailpilot' ), 'value' => $growth_str, 'icon' => 'trend', 'hue' => 'green', 'delta' => '30d' ],
				[ 'label' => __( 'Conversion rate', 'mailpilot' ), 'value' => $analytics->conversion_rate( 30 ) . '%', 'icon' => 'target', 'hue' => 'purple', 'delta' => '30d' ],
				[ 'label' => __( 'New subscribers', 'mailpilot' ), 'value' => number_format_i18n( $analytics->new_in_last_days( 30 ) ), 'icon' => 'userplus', 'hue' => 'pink', 'delta' => '30d' ],
				[ 'label' => __( 'Sync queue', 'mailpilot' ), 'value' => number_format_i18n( $pending ), 'icon' => 'refresh', 'hue' => 'teal' ],
			],
			'lists' => [
				[ 'title' => __( 'Top forms', 'mailpilot' ), 'meta' => __( 'signups', 'mailpilot' ), 'icon' => 'file', 'hue' => 'blue', 'rows' => $to_rows( $analytics->top_forms() ) ],
				[ 'title' => __( 'Top sources', 'mailpilot' ), 'meta' => __( 'subscribers', 'mailpilot' ), 'icon' => 'inbox', 'hue' => 'orange', 'rows' => $to_rows( $analytics->top_sources() ) ],
				[ 'title' => __( 'By status', 'mailpilot' ), 'meta' => __( 'subscribers', 'mailpilot' ), 'icon' => 'pie', 'hue' => 'green', 'rows' => $to_rows( $analytics->status_breakdown() ) ],
			],
			'account' => [
				'tier'    => ucfirst( $license->tier() ),
				'queue'   => 0 === $pending
					? __( '0 — all synced', 'mailpilot' )
					/* translators: %s: number of pending sync operations. */
					: sprintf( __( '%s pending', 'mailpilot' ), number_format_i18n( $pending ) ),
				'queueOk' => 0 === $pending,
				'usage'   => null === $cap
					? __( 'Unlimited', 'mailpilot' )
					/* translators: 1: syncs used, 2: monthly cap. */
					: sprintf( __( '%1$s / %2$s syncs', 'mailpilot' ), number_format_i18n( $usage->used() ), number_format_i18n( $cap ) ),
			],
		];
	}

	/**
	 * Build the Forms list payload from the real form repository.
	 *
	 * @return array<string, mixed>
	 */
	private function forms_data(): array {
		$items = [];
		foreach ( $this->plugin->forms()->repository()->all() as $form ) {
			$items[] = [
				'id'     => (int) $form->id,
				'title'  => $form->title ?: __( '(untitled)', 'mailpilot' ),
				'status' => ucfirst( (string) $form->status ),
				'fields' => count( $form->fields ),
			];
		}

		return [
			'items'       => $items,
			'newUrl'      => esc_url_raw( add_query_arg( [ 'page' => self::SLUG . '-forms', 'action' => 'new' ], admin_url( 'admin.php' ) ) ),
			'editUrlBase' => esc_url_raw( add_query_arg( [ 'page' => self::SLUG . '-forms', 'action' => 'edit' ], admin_url( 'admin.php' ) ) ),
		];
	}

	/**
	 * Build the Subscribers list payload from the real repository (most recent
	 * first, capped for a snappy initial paint — the screen paginates client
	 * side). Tags are resolved per row via the relationship repository.
	 *
	 * @return array<string, mixed>
	 */
	private function subscribers_data(): array {
		$repo   = $this->plugin->subscriber_repository();
		$result = $repo->query(
			[
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'per_page' => 200,
				'page'     => 1,
			]
		);

		$rows = [];
		foreach ( $result['items'] as $subscriber ) {
			$id     = (int) $subscriber->id;
			$rows[] = [
				'id'      => 's' . $id,
				'email'   => $subscriber->email,
				'name'    => $subscriber->display_name(),
				'source'  => $subscriber->source->label(),
				'status'  => $subscriber->status->label(),
				'tags'    => array_values( $this->plugin->relationships()->tags_for( $id ) ),
				'created' => (string) $subscriber->created_at,
			];
		}

		return [
			'subs'  => $rows,
			'total' => (int) $result['total'],
		];
	}

	/**
	 * Build the Providers screen payload: live connections mapped onto the
	 * catalog's display names. The catalog/features/sync-action metadata stays
	 * client-side (curated in the component) — only the connections are live.
	 *
	 * @return array<string, mixed>
	 */
	private function providers_data(): array {
		// slug → display label, and the reverse label → slug so the connect
		// drawer can resolve a provider's slug from its catalog name.
		$labels      = [];
		$slug_by_name = [];
		foreach ( $this->plugin->providers()->all() as $provider ) {
			if ( $provider instanceof \MailPilot\Providers\Contracts\Provider ) {
				$labels[ $provider->id() ]           = $provider->label();
				$slug_by_name[ $provider->label() ]  = $provider->id();
			}
		}

		$connections = [];
		foreach ( $this->plugin->provider_connections()->all() as $connection ) {
			$lists     = method_exists( $connection, 'lists' ) ? (array) $connection->lists() : [];
			$field_map = is_array( $connection->field_map ) ? $connection->field_map : [];
			$tags      = (array) $connection->setting( 'default_tags', [] );

			// Serialise the field map back to the drawer's `local = provider` text.
			$mapping_lines = [];
			foreach ( $field_map as $local => $remote ) {
				$mapping_lines[] = $local . ' = ' . $remote;
			}

			$connections[] = [
				'id'       => (int) $connection->id,
				'provider' => $labels[ $connection->provider ] ?? ucfirst( (string) $connection->provider ),
				'label'    => (string) $connection->label,
				'list'     => $lists ? implode( ', ', array_map( 'strval', $lists ) ) : __( 'none — set one', 'mailpilot' ),
				// Prefill values for the edit drawer so an update never wipes them.
				'list_id'  => (string) ( $lists[0] ?? '' ),
				'tags'     => implode( ', ', array_map( 'strval', $tags ) ),
				'mapping'  => implode( "\n", $mapping_lines ),
				'optin'    => (bool) $connection->double_opt_in(),
			];
		}

		return [
			'connections' => $connections,
			'slugByName'  => $slug_by_name,
			'proActive'   => $this->is_pro_active(),
		];
	}

	/**
	 * Build the Integrations screen payload from the integration registry.
	 * Category is derived from the integration's namespace and the chip hue
	 * from a stable hash of its label (the contract carries neither).
	 *
	 * @return array<string, mixed>
	 */
	private function integrations_data(): array {
		$items = [];
		foreach ( $this->plugin->integrations()->all() as $integration ) {
			if ( ! $integration instanceof \MailPilot\Integrations\Contracts\Integration ) {
				continue;
			}

			$name    = $integration->label();
			$config  = get_option( 'mailpilot_integration_' . $integration->id(), [] );
			$items[] = [
				'id'      => $integration->id(),
				'name'    => $name,
				'cat'     => $this->integration_category( $integration ),
				'host'    => $integration->is_available() ? 'active' : 'missing',
				'enabled' => $integration->is_enabled(),
				'hue'     => $this->stable_hue( $name ),
				'config'  => is_array( $config ) ? $config : [],
			];
		}

		return [
			'items'     => $items,
			'proActive' => $this->is_pro_active(),
		];
	}

	/**
	 * Map an integration to one of the redesign's four categories, using its
	 * class namespace (WordPress\ → core, FormPlugins\ → forms, …).
	 *
	 * @param object $integration Integration instance.
	 */
	private function integration_category( object $integration ): string {
		$class = get_class( $integration );

		if ( false !== strpos( $class, '\\WordPress\\' ) ) {
			return 'core';
		}
		if ( false !== strpos( $class, '\\Membership\\' ) ) {
			return 'membership';
		}
		if ( false !== stripos( $class, '\\Lms\\' ) || false !== stripos( $class, '\\LMS\\' ) || false !== strpos( $class, '\\Learning\\' ) ) {
			return 'lms';
		}
		if ( false !== strpos( $class, '\\Events\\' ) ) {
			return 'events';
		}
		if ( false !== strpos( $class, '\\Donation\\' ) ) {
			return 'donation';
		}
		if ( false !== strpos( $class, '\\Affiliate\\' ) ) {
			return 'affiliate';
		}

		return 'forms';
	}

	/**
	 * Deterministic hue (0–359) from a string, for the tinted initials chips.
	 */
	private function stable_hue( string $text ): int {
		return (int) ( crc32( $text ) % 360 );
	}

	/**
	 * Build the License screen payload from the Pro licensing state.
	 *
	 * The Pro plugin's {@see \MailPilot\Pro\Licensing\LicenseClient} persists
	 * everything to the `mailpilot_pro_license` option (and white-label to
	 * `mailpilot_pro_whitelabel`), so we read those directly — this works even
	 * if the Pro plugin classes aren't loaded. The effective tier still comes
	 * through the core's `mailpilot_license_tier` filter.
	 *
	 * @return array<string, mixed>
	 */
	private function license_data(): array {
		$tier = $this->plugin->license()->tier();

		// Neutral Free-tier default, used verbatim when the Pro add-on isn't
		// active. The Pro plugin supplies the live, SDK-backed payload through
		// the `mailpilot_pro_license_screen` filter.
		$default = [
			'licensed'   => false,
			'active'     => false,
			'status'     => 'none',
			'tier'       => ucfirst( $tier ),
			'plan'       => '',
			'sitesUsed'  => 0,
			'sitesLimit' => null,
			'expires'    => '',
			'lastCheck'  => '',
			'keyEnding'  => '',
			'message'    => '',
			'whiteLabel' => false,
			'brand'      => '',
			'icon'       => '',
			'facts'      => [
				[ 'label' => __( 'Tier', 'mailpilot' ), 'value' => ucfirst( $tier ) ],
				[ 'label' => __( 'Status', 'mailpilot' ), 'value' => __( 'Not activated', 'mailpilot' ) ],
			],
		];

		/**
		 * Filter the License screen payload. Pro fills it from its BrainStudioz
		 * SDK-backed license state; core provides only the neutral default.
		 *
		 * @param array<string, mixed> $default Neutral Free-tier payload.
		 */
		$data = apply_filters( 'mailpilot_pro_license_screen', $default );

		return is_array( $data ) ? $data : $default;
	}

	/**
	 * Build the Analytics screen payload: a real daily subscribers series over
	 * the last 30 days plus real conversion metrics. Per-day conversions and
	 * revenue aren't tracked, so those series stay flat and their KPIs read $0.
	 *
	 * @return array<string, mixed>
	 */
	private function analytics_data(): array {
		$analytics = new \MailPilot\Analytics\Analytics();
		$days      = 30;
		$over_time = $analytics->subscribers_over_time( $days );

		$dates = [];
		$subs  = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date    = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
			$dates[] = $date;
			$subs[]  = (int) ( $over_time[ $date ] ?? 0 );
		}
		$zero = array_fill( 0, count( $dates ), 0 );

		$conversions = (int) $analytics->metric_total( 'conversions', $days );

		return [
			'rangeLabel' => date_i18n( 'M j', strtotime( $dates[0] ) ) . ' – ' . date_i18n( 'M j, Y', strtotime( (string) end( $dates ) ) ),
			'days'       => $dates,
			'series'     => [
				'subs'    => $subs,
				'conv'    => $zero,
				'revenue' => $zero,
			],
			'kpis' => [
				[ 'label' => __( 'Revenue', 'mailpilot' ), 'value' => '$0.00', 'icon' => 'trend', 'hue' => 'green' ],
				[ 'label' => __( 'Conversions', 'mailpilot' ), 'value' => number_format_i18n( $conversions ), 'icon' => 'check', 'hue' => 'blue' ],
				[ 'label' => __( 'Conversion rate', 'mailpilot' ), 'value' => $analytics->conversion_rate( $days ) . '%', 'icon' => 'target', 'hue' => 'purple' ],
				[ 'label' => __( 'Revenue / subscriber', 'mailpilot' ), 'value' => '$0.00', 'icon' => 'users', 'hue' => 'pink' ],
			],
		];
	}

	/**
	 * Build the Audience Routing screen payload from the Pro routing rules.
	 *
	 * The Pro plugin's RulesRepository stores ordered branches (each with
	 * conditions + actions) in the `mailpilot_pro_routing_rules` option; we
	 * read it directly and flatten the first condition/action of each branch
	 * into the redesign's IF/ELSE-IF/ELSE row shape.
	 *
	 * @return array<string, mixed>
	 */
	private function audience_routing_data(): array {
		$branches = get_option( 'mailpilot_pro_routing_rules', [] );
		$branches = is_array( $branches ) ? $branches : [];

		$rules = [];
		foreach ( array_values( $branches ) as $index => $branch ) {
			if ( ! is_array( $branch ) ) {
				continue;
			}

			if ( ! empty( $branch['else'] ) ) {
				$field    = 'always';
				$operator = 'is';
				$value    = '';
				$meta_key = '';
			} else {
				$condition = ( $branch['conditions'][0] ?? [] );
				$config    = (array) ( $condition['config'] ?? [] );
				$field     = (string) ( $condition['type'] ?? 'always' );
				$operator  = (string) ( $config['operator'] ?? 'is' );
				$value     = (string) ( $config['value'] ?? '' );
				$meta_key  = (string) ( $config['key'] ?? '' );
			}

			$action       = ( $branch['actions'][0] ?? [] );
			$action_type  = (string) ( $action['type'] ?? '' );
			$action_cfg   = (array) ( $action['config'] ?? [] );
			$action_value = '';
			if ( in_array( $action_type, [ 'add_tag', 'remove_tag' ], true ) ) {
				$action_value = (string) ( $action_cfg['tag'] ?? '' );
			} elseif ( 'sync_provider' === $action_type ) {
				$action_value = implode( ', ', array_map( 'strval', (array) ( $action_cfg['connections'] ?? [] ) ) );
			}

			$rules[] = [
				'id'          => 'r' . $index,
				'field'       => $field,
				'operator'    => $operator,
				'value'       => $value,
				'metaKey'     => $meta_key,
				// Reuse the redesign's nicer "Sync to Provider" label.
				'action'      => 'sync_provider' === $action_type ? 'sync' : ( $action_type ?: 'skip' ),
				'actionValue' => $action_value,
			];
		}

		// Option lists so the builder's value field can be a dropdown of the
		// real, matchable values per condition field (instead of free text).
		$sources = [];
		foreach ( \MailPilot\Subscribers\Source::cases() as $case ) {
			$sources[] = [ 'value' => $case->value, 'label' => $case->label() ];
		}
		$statuses = [];
		foreach ( \MailPilot\Subscribers\Status::cases() as $case ) {
			$statuses[] = [ 'value' => $case->value, 'label' => $case->label() ];
		}
		$forms = [];
		foreach ( $this->plugin->forms()->repository()->all() as $form ) {
			$forms[] = [ 'value' => (string) $form->id, 'label' => $form->title ?: __( '(untitled)', 'mailpilot' ) ];
		}
		$tags = [];
		foreach ( $this->plugin->relationships()->all_tags() as $tag ) {
			$tags[] = [ 'value' => (string) $tag, 'label' => (string) $tag ];
		}

		return [
			'rules'   => $rules,
			'options' => [
				'source' => $sources,
				'status' => $statuses,
				'form'   => $forms,
				'tag'    => $tags,
			],
		];
	}

	/**
	 * Build the Automations screen payload from the saved webhooks + rules.
	 *
	 * @return array<string, mixed>
	 */
	private function automations_data(): array {
		$repo = new \MailPilot\Automations\AutomationsRepository();

		$sources = [];
		foreach ( \MailPilot\Subscribers\Source::cases() as $case ) {
			$sources[] = [ 'value' => $case->value, 'label' => $case->label() ];
		}
		$tags = [];
		foreach ( $this->plugin->relationships()->all_tags() as $tag ) {
			$tags[] = [ 'value' => (string) $tag, 'label' => (string) $tag ];
		}

		return [
			'webhooks'     => $repo->webhooks(),
			'automations'  => $repo->automations(),
			'incomingBase' => esc_url_raw( rest_url( 'mailpilot/v1/automations/incoming' ) ),
			'options'      => [
				'source' => $sources,
				'tag'    => $tags,
			],
		];
	}

	/**
	 * Build the Popups screen payload. Popups are forms whose display type is a
	 * popup variant (rendered by the Pro Popups module) — no parallel storage.
	 *
	 * @return array<string, mixed>
	 */
	private function popups_data(): array {
		$types = [ 'popup', 'floating_bar', 'slide_in', 'full_screen' ];
		$items = [];

		foreach ( $this->plugin->forms()->repository()->all() as $form ) {
			if ( ! in_array( $form->display_type, $types, true ) ) {
				continue;
			}
			$popup   = (array) $form->setting( 'popup', [] );
			$items[] = [
				'id'          => (int) $form->id,
				'name'        => $form->title ?: __( '(untitled)', 'mailpilot' ),
				'type'        => $form->display_type,
				'trigger'     => (string) ( $popup['trigger'] ?? 'time_delay' ),
				'freq'        => (string) ( $popup['frequency'] ?? 'daily' ),
				'status'      => ucfirst( (string) $form->status ),
				'views'       => 0,
				'conversions' => 0,
			];
		}

		return [
			'items'       => $items,
			'newUrl'      => esc_url_raw( add_query_arg( [ 'page' => self::SLUG . '-forms', 'action' => 'new' ], admin_url( 'admin.php' ) ) ),
			'editUrlBase' => esc_url_raw( add_query_arg( [ 'page' => self::SLUG . '-forms', 'action' => 'edit' ], admin_url( 'admin.php' ) ) ),
			/**
			 * The Pro popup Design builder URL base (with a trailing `form=`),
			 * or '' when the Pro design feature is unavailable. Filled by Pro via
			 * the `mailpilot_popup_design_url_base` filter; the React Popups screen
			 * shows a "Design" action per popup only when this is present.
			 */
			'designUrlBase' => esc_url_raw( (string) apply_filters( 'mailpilot_popup_design_url_base', '' ) ),
		];
	}

	/**
	 * Build the Lead Magnets screen payload from the shared magnet store
	 * (read by the Pro delivery module).
	 *
	 * @return array<string, mixed>
	 */
	private function lead_magnets_data(): array {
		$stored  = get_option( 'mailpilot_pro_lead_magnets', [] );
		$magnets = [];

		foreach ( is_array( $stored ) ? $stored : [] as $magnet ) {
			if ( ! is_array( $magnet ) ) {
				continue;
			}
			$magnets[] = [
				'id'          => (int) ( $magnet['id'] ?? 0 ),
				'title'       => (string) ( $magnet['title'] ?? '' ),
				'file'        => (string) ( $magnet['file'] ?? '' ),
				'delivery'    => 'email' === ( $magnet['delivery'] ?? '' ) ? 'email' : 'instant',
				'limit'       => (int) ( $magnet['max_downloads'] ?? 0 ),
				'downloads'   => 0,
				'conversions' => 0,
			];
		}

		return [ 'magnets' => $magnets ];
	}

	/**
	 * Build the AI Assistant screen payload — whether a key is configured and
	 * where to set one.
	 *
	 * @return array<string, mixed>
	 */
	private function ai_assistant_data(): array {
		$has_key = '' !== (string) $this->plugin->settings()->get( 'ai_api_key', '' );

		return [
			'hasKey'      => $has_key,
			'settingsUrl' => esc_url_raw( add_query_arg( [ 'page' => self::SLUG . '-settings' ], admin_url( 'admin.php' ) ) ),
		];
	}

	/**
	 * Subscribers: redesigned React list, or the legacy PHP profile view when
	 * viewing a single subscriber.
	 */
	public function render_subscribers(): void {
		if ( $this->is_functional_subview( self::SLUG . '-subscribers' ) ) {
			( new SubscribersPage( $this->plugin ) )->render();
			return;
		}

		$this->render_screen( 'subscribers' );
	}

	/**
	 * Forms: redesigned React list, or the React Form Builder for create/edit
	 * (served by FormsPage, which enqueues its own bundle).
	 */
	public function render_forms(): void {
		if ( $this->is_functional_subview( self::SLUG . '-forms' ) ) {
			( new FormsPage( $this->plugin ) )->render();
			return;
		}

		$this->render_screen( 'forms' );
	}

	/**
	 * Providers: redesigned React list, or the legacy PHP edit form.
	 */
	public function render_providers(): void {
		if ( $this->is_functional_subview( self::SLUG . '-providers' ) ) {
			( new ProvidersPage( $this->plugin ) )->render();
			return;
		}

		$this->render_screen( 'providers' );
	}

	/**
	 * Integrations: redesigned React list, or the legacy PHP detail form.
	 */
	public function render_integrations(): void {
		if ( $this->is_functional_subview( self::SLUG . '-integrations' ) ) {
			( new IntegrationsPage( $this->plugin ) )->render();
			return;
		}

		$this->render_screen( 'integrations' );
	}

	/**
	 * Render the redesigned dashboard (React `admin` bundle, `dashboard`
	 * screen). Data is injected via {@see self::dashboard_data()} in
	 * {@see self::enqueue_admin()}.
	 */
	public function render_dashboard(): void {
		$this->render_screen( 'dashboard' );
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings(): void {
		$this->guard();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'MailPilot Settings', 'mailpilot' ) . '</h1>';
		echo '<form method="post" action="options.php">';

		settings_fields( Settings::GROUP );

		$settings = $this->plugin->settings();

		echo '<table class="form-table" role="presentation"><tbody>';

		// Note: MailPilot Pro license activation lives on the dedicated License
		// page (added by the Pro add-on), not here — so the free-core Settings
		// page no longer shows a License Key field.

		/**
		 * Extension point for add-ons to render additional settings rows.
		 *
		 * The AI settings (provider + API key) are a Pro feature and are rendered
		 * by MailPilot Pro through this hook — they do not appear in the free core.
		 * The shared `mailpilot_settings` option still sanitises/encrypts those
		 * keys in the core ({@see \MailPilot\Settings\Settings::sanitize()}), so a
		 * single option remains the source of truth.
		 *
		 * @param Settings $settings The settings repository.
		 */
		do_action( 'mailpilot_render_settings_fields', $settings );

		// Sync new subscribers to every connected provider.
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s[sync_all_providers]" value="1" %s /> %s</label><p class="description">%s</p></td></tr>',
			esc_html__( 'Sync to all providers', 'mailpilot' ),
			esc_attr( Settings::OPTION ),
			checked( ! empty( $settings->get( 'sync_all_providers', false ) ), true, false ),
			esc_html__( 'Push every new subscriber to all connected providers', 'mailpilot' ),
			esc_html__( 'When on, form/registration/comment signups are sent to every active provider connection, in addition to any providers a form selects.', 'mailpilot' )
		);

		// Default country for internationalising local phone numbers.
		printf(
			'<tr><th scope="row"><label for="mailpilot_default_country">%s</label></th><td><input name="%s[default_country]" id="mailpilot_default_country" type="text" class="small-text" maxlength="2" style="text-transform:uppercase" value="%s" placeholder="%s" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Default phone country', 'mailpilot' ),
			esc_attr( Settings::OPTION ),
			esc_attr( (string) $settings->get( 'default_country', '' ) ),
			esc_attr__( 'e.g. PK', 'mailpilot' ),
			esc_html__( 'Two-letter country code (ISO-3166, e.g. PK, US, GB). Local phone numbers without their own country are treated as this country when syncing to providers that require an international (+…) number. Leave blank to disable.', 'mailpilot' )
		);

		echo '</tbody></table>';

		submit_button();

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Capability guard for page callbacks.
	 */
	private function guard(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mailpilot' ) );
		}
	}
}
