<?php
/**
 * Plugin container and bootstrapper.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot;

use MailPilot\Activity\ActivityLogger;
use MailPilot\Admin\AdminMenu;
use MailPilot\Analytics\Analytics;
use MailPilot\Database\Migrator;
use MailPilot\Licensing\License;
use MailPilot\Licensing\UsageMeter;
use MailPilot\Forms\FormsModule;
use MailPilot\Integrations\Elementor\ElementorModule;
use MailPilot\Integrations\IntegrationManager;
use MailPilot\Providers\ActiveCampaign;
use MailPilot\Providers\AWeber;
use MailPilot\Providers\Brevo;
use MailPilot\Providers\CampaignMonitor;
use MailPilot\Providers\ConstantContact;
use MailPilot\Providers\Drip;
use MailPilot\Providers\GetResponse;
use MailPilot\Providers\Kit;
use MailPilot\Providers\Mailchimp;
use MailPilot\Providers\MailerLite;
use MailPilot\Providers\ProviderConnectionRepository;
use MailPilot\Queue\Queue;
use MailPilot\Registry\Registry;
use MailPilot\Security\Encryption;
use MailPilot\Settings\Settings;
use MailPilot\Subscribers\RelationshipRepository;
use MailPilot\Subscribers\SubscriberEngine;
use MailPilot\Subscribers\SubscriberRepository;
use MailPilot\Sync\SyncLog;
use MailPilot\Sync\SyncService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service container and lifecycle coordinator.
 *
 * Services are lazily instantiated and cached. Each maps to a layer in the
 * architecture (see doc/03-architecture.md).
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Resolved service instances, keyed by id.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * Resolved registries, keyed by their registration hook.
	 *
	 * @var array<string, Registry>
	 */
	private array $registries = [];

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Retrieve the singleton instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire up runtime hooks. Safe to call once per request.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		// Load translations on `init` (not here at plugins_loaded): WordPress 6.7+
		// warns if a domain's translations are triggered before `init`.
		add_action(
			'init',
			static function (): void {
				load_plugin_textdomain( 'mailpilot', false, dirname( MAILPILOT_BASENAME ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- loads bundled /languages translations for non-.org distribution.
			}
		);

		// Ensure the schema is current even if the user updated files without
		// re-activating (e.g. via Git or WP-CLI).
		$this->database()->maybe_upgrade();

		// Register the free-core provider adapters into the provider registry.
		add_action( 'mailpilot_register_providers', static function ( Registry $registry ): void {
			$registry->add( new Mailchimp() );
			$registry->add( new Brevo() );
			$registry->add( new MailerLite() );
			$registry->add( new Kit() );
			$registry->add( new ActiveCampaign() );
			$registry->add( new GetResponse() );
			$registry->add( new AWeber() );
			$registry->add( new CampaignMonitor() );
			$registry->add( new Drip() );
			$registry->add( new ConstantContact() );
		} );

		// Back the phone default-country filter with the saved setting (applies
		// on every request: form submits, cron sync, admin).
		add_filter(
			'mailpilot_phone_default_country',
			fn ( $country ): string => (string) ( $this->settings()->get( 'default_country', '' ) ?: $country )
		);

		// Register runtime services.
		$this->queue()->register_hooks();
		$this->sync()->register_hooks();
		$this->forms()->register_hooks();
		$this->integration_manager()->register_hooks();
		$this->imports()->register_hooks();
		$this->elementor()->register_hooks();
		( new \MailPilot\Rest\SubscribersController( $this ) )->register_hooks();
		( new \MailPilot\Rest\FormsController( $this ) )->register_hooks();
		( new \MailPilot\Rest\ProvidersController( $this ) )->register_hooks();
		( new \MailPilot\Rest\IntegrationsController( $this ) )->register_hooks();
		( new \MailPilot\Rest\RoutingController( $this ) )->register_hooks();

		// Automations: runtime (webhooks + rules) and its REST surface.
		$automations_repo = new \MailPilot\Automations\AutomationsRepository();
		( new \MailPilot\Automations\AutomationsModule( $this, $automations_repo ) )->register_hooks();
		( new \MailPilot\Rest\AutomationsController( $this, $automations_repo ) )->register_hooks();
		( new \MailPilot\Rest\LeadMagnetsController( $this ) )->register_hooks();
		( new \MailPilot\Rest\AiController( $this ) )->register_hooks();
		( new \MailPilot\Rest\ImportController( $this ) )->register_hooks();

		// Core-provided routing conditions (source, status) for the Pro engine.
		( new \MailPilot\Routing\RoutingConditions() )->register_hooks();

		if ( is_admin() ) {
			$this->admin()->register_hooks();
			$this->settings()->register_hooks();
			( new \MailPilot\Admin\FormsPage( $this ) )->register_hooks();
			( new \MailPilot\Admin\ProvidersPage( $this ) )->register_hooks();
			( new \MailPilot\Admin\IntegrationsPage( $this ) )->register_hooks();
		}

		/**
		 * Fires once MailPilot core services are wired up.
		 *
		 * @param Plugin $plugin The plugin container.
		 */
		do_action( 'mailpilot_booted', $this );
	}

	// -----------------------------------------------------------------------
	// Service accessors.
	// -----------------------------------------------------------------------

	/**
	 * Database migration runner.
	 */
	public function database(): Migrator {
		return $this->resolve( 'database', static fn (): Migrator => new Migrator() );
	}

	/**
	 * Settings repository.
	 */
	public function settings(): Settings {
		return $this->resolve( 'settings', static fn (): Settings => new Settings() );
	}

	/**
	 * Admin menu/pages.
	 */
	public function admin(): AdminMenu {
		return $this->resolve( 'admin', fn (): AdminMenu => new AdminMenu( $this ) );
	}

	/**
	 * Credential encryption helper.
	 */
	public function encryption(): Encryption {
		return $this->resolve( 'encryption', static fn (): Encryption => new Encryption() );
	}

	/**
	 * License/tier reporter.
	 */
	public function license(): License {
		return $this->resolve( 'license', fn (): License => new License( $this->settings() ) );
	}

	/**
	 * Sync-operation usage meter (enforces the Free-tier monthly cap, ADR-006).
	 */
	public function usage(): UsageMeter {
		return $this->resolve( 'usage', fn (): UsageMeter => new UsageMeter( $this->license() ) );
	}

	/**
	 * Background job queue.
	 */
	public function queue(): Queue {
		return $this->resolve( 'queue', static fn (): Queue => new Queue() );
	}

	// -----------------------------------------------------------------------
	// Core domain services.
	// -----------------------------------------------------------------------

	/**
	 * Activity logging service.
	 */
	public function activity(): ActivityLogger {
		return $this->resolve( 'activity', static fn (): ActivityLogger => new ActivityLogger() );
	}

	/**
	 * Analytics aggregation/reporting service.
	 */
	public function analytics(): Analytics {
		return $this->resolve( 'analytics', static fn (): Analytics => new Analytics() );
	}

	/**
	 * Subscriber data repository.
	 */
	public function subscriber_repository(): SubscriberRepository {
		return $this->resolve( 'subscriber_repository', static fn (): SubscriberRepository => new SubscriberRepository() );
	}

	/**
	 * Subscriber tag/list relationship repository.
	 */
	public function relationships(): RelationshipRepository {
		return $this->resolve( 'relationships', static fn (): RelationshipRepository => new RelationshipRepository() );
	}

	/**
	 * Subscriber Engine — the central processing layer and developer API.
	 */
	public function subscribers(): SubscriberEngine {
		return $this->resolve(
			'subscribers',
			fn (): SubscriberEngine => new SubscriberEngine(
				$this->subscriber_repository(),
				$this->relationships(),
				$this->activity()
			)
		);
	}

	/**
	 * Provider connection repository (decrypts credentials on read).
	 */
	public function provider_connections(): ProviderConnectionRepository {
		return $this->resolve(
			'provider_connections',
			fn (): ProviderConnectionRepository => new ProviderConnectionRepository( $this->encryption() )
		);
	}

	/**
	 * Form Builder module (repository, renderer, capture surfaces).
	 */
	public function forms(): FormsModule {
		return $this->resolve( 'forms', fn (): FormsModule => new FormsModule( $this ) );
	}

	/**
	 * Capture-integration manager (WP core + form plugins).
	 */
	public function integration_manager(): IntegrationManager {
		return $this->resolve( 'integration_manager', static fn (): IntegrationManager => new IntegrationManager() );
	}

	/**
	 * Background CSV import service.
	 */
	public function imports(): \MailPilot\IO\ImportService {
		return $this->resolve( 'imports', fn (): \MailPilot\IO\ImportService => new \MailPilot\IO\ImportService( $this ) );
	}

	/**
	 * Elementor integration module (widget, form action).
	 */
	public function elementor(): ElementorModule {
		return $this->resolve( 'elementor', static fn (): ElementorModule => new ElementorModule() );
	}

	/**
	 * Provider sync orchestration (queued).
	 */
	public function sync(): SyncService {
		return $this->resolve(
			'sync',
			fn (): SyncService => new SyncService(
				$this->queue(),
				$this->providers(),
				$this->provider_connections(),
				$this->subscriber_repository(),
				$this->relationships(),
				new SyncLog(),
				$this->activity()
			)
		);
	}

	// -----------------------------------------------------------------------
	// Extension-point registries (public, versioned API — see EXTENSION-POINTS.md).
	// -----------------------------------------------------------------------

	/**
	 * Provider adapter registry. Pro registers premium providers here.
	 */
	public function providers(): Registry {
		return $this->registry( 'providers', 'mailpilot_register_providers' );
	}

	/**
	 * Plugin integration registry.
	 */
	public function integrations(): Registry {
		return $this->registry( 'integrations', 'mailpilot_register_integrations' );
	}

	/**
	 * Rules Engine condition registry.
	 */
	public function routing_conditions(): Registry {
		return $this->registry( 'routing_conditions', 'mailpilot_register_routing_conditions' );
	}

	/**
	 * Rules Engine action registry.
	 */
	public function routing_actions(): Registry {
		return $this->registry( 'routing_actions', 'mailpilot_register_routing_actions' );
	}

	/**
	 * Pro module registry (popups, lead magnets, automation, AI, …).
	 */
	public function modules(): Registry {
		return $this->registry( 'modules', 'mailpilot_register_modules' );
	}

	/**
	 * Lazily build a registry and fire its registration hook exactly once.
	 *
	 * Firing lazily (rather than during boot at priority 5) guarantees add-ons
	 * that register on `plugins_loaded` at a later priority are already in place
	 * before the registry is first read.
	 *
	 * @param string $id   Internal cache key.
	 * @param string $hook Action hook add-ons listen on, receiving the registry.
	 */
	private function registry( string $id, string $hook ): Registry {
		if ( ! isset( $this->registries[ $id ] ) ) {
			$registry = new Registry( $id );

			/**
			 * Fires once so add-ons can register items into a core registry.
			 *
			 * @param Registry $registry The registry to add items to.
			 */
			do_action( $hook, $registry ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $hook is a fixed internal registry action (e.g. mailpilot_register_providers).

			$this->registries[ $id ] = $registry;
		}

		return $this->registries[ $id ];
	}

	/**
	 * Resolve a service, caching the instance.
	 *
	 * @template T of object
	 * @param string          $id      Service id.
	 * @param callable():T    $factory Factory closure.
	 * @return T
	 */
	private function resolve( string $id, callable $factory ): object {
		if ( ! isset( $this->services[ $id ] ) ) {
			$this->services[ $id ] = $factory();
		}

		return $this->services[ $id ];
	}
}
