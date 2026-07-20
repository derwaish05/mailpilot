<?php
/**
 * Uninstall handler.
 *
 * Removes MailPilot tables and options, but only when the site owner has
 * explicitly enabled "delete data on uninstall" in settings. Runs in an
 * isolated context, so it cannot rely on the plugin being bootstrapped.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$mailpilot_settings = get_option( 'mailpilot_settings', [] );

if ( empty( $mailpilot_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Drop all MailPilot tables (in dependency-safe order).
$mailpilot_tables = [
	'jobs',
	'analytics',
	'webhooks',
	'automations',
	'provider_connections',
	'forms',
	'sync_log',
	'activity_log',
	'subscriber_lists',
	'subscriber_tags',
	'subscribers',
];

// Current `mailpilot_` prefix, plus the legacy `nh_` prefix in case the site is
// uninstalled before the rename migration ran.
$mailpilot_prefixes = [ 'mailpilot_', 'nh_' ];

foreach ( $mailpilot_tables as $mailpilot_table ) {
	foreach ( $mailpilot_prefixes as $mailpilot_prefix ) {
		$mailpilot_full_table = $wpdb->prefix . $mailpilot_prefix . $mailpilot_table;
		$wpdb->query( "DROP TABLE IF EXISTS {$mailpilot_full_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from internal constant/allow-list, never user input.
	}
}

// Remove standalone options.
$mailpilot_options = [
	'mailpilot_settings',
	'mailpilot_schema_version',
	'mailpilot_version',
	'mailpilot_installed_at',
];

foreach ( $mailpilot_options as $mailpilot_option ) {
	delete_option( $mailpilot_option );
}

// Remove monthly usage counters (mailpilot_usage_YYYY-MM).
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'mailpilot_usage_' ) . '%'
	)
);

// Clear any scheduled worker.
wp_clear_scheduled_hook( 'mailpilot_process_queue' );
