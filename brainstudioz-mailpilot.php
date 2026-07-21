<?php
/**
 * Plugin Name:       BrainStudioz MailPilot
 * Plugin URI:        https://github.com/derwaish05/mailpilot
 * Description:       Build newsletter signup forms, manage subscribers, and sync contacts with Mailchimp, Brevo, MailerLite, Kit, ActiveCampaign, and more.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            BrainStudioz
 * Author URI:        https://brainstudioz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       brainstudioz-mailpilot
 * Domain Path:       /languages
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot;

// Abort if WordPress is not bootstrapping this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Constants.
// ---------------------------------------------------------------------------

define( 'MAILPILOT_VERSION', '1.0.0' );
define( 'MAILPILOT_FILE', __FILE__ );
define( 'MAILPILOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'MAILPILOT_URL', plugin_dir_url( __FILE__ ) );
define( 'MAILPILOT_BASENAME', plugin_basename( __FILE__ ) );

// Database table prefix (appended to the site's $wpdb->prefix).
define( 'MAILPILOT_TABLE_PREFIX', 'mailpilot_' );

// Minimum supported environment.
define( 'MAILPILOT_MIN_PHP', '8.1' );
define( 'MAILPILOT_MIN_WP', '6.8' );

// ---------------------------------------------------------------------------
// Autoloading.
// ---------------------------------------------------------------------------

// Prefer the Composer autoloader when present; fall back to a PSR-4 loader so
// the plugin runs from a clean checkout without `composer install`.
if ( is_readable( MAILPILOT_PATH . 'vendor/autoload.php' ) ) {
	require_once MAILPILOT_PATH . 'vendor/autoload.php';
} else {
	require_once MAILPILOT_PATH . 'src/Autoloader.php';
	( new Autoloader( 'MailPilot\\', MAILPILOT_PATH . 'src/' ) )->register();
}

// ---------------------------------------------------------------------------
// Environment guard.
// ---------------------------------------------------------------------------

if ( ! Compatibility::is_supported() ) {
	add_action( 'admin_notices', [ Compatibility::class, 'render_notice' ] );
	return;
}

// ---------------------------------------------------------------------------
// Lifecycle hooks.
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, [ Activation\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Activation\Deactivator::class, 'deactivate' ] );

// ---------------------------------------------------------------------------
// Public API (global namespace) + boot.
// ---------------------------------------------------------------------------

// Global helper functions: mailpilot(), mailpilot_form(). Defined in the root
// namespace so themes/add-ons call `mailpilot()` without a namespace prefix;
// namespaced internal calls fall back to these globals.
require_once MAILPILOT_PATH . 'functions.php';

add_action( 'plugins_loaded', static function (): void {
	mailpilot()->boot();
}, 5 );
