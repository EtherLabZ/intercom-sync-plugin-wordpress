<?php
/**
 * Uninstall handler — runs when the plugin is deleted from WP Admin.
 *
 * @package Etherlabz\Intercom_Woo_Sync
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync;

// Only run if called by WordPress.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Plugin option prefix.
 */
const OPTION_PREFIX = 'iws_';

/**
 * Run uninstaller — handles multisite.
 */
function run_uninstaller(): void {
	if ( ! is_multisite() ) {
		uninstall();
		return;
	}

	$site_ids = get_sites( [
		'fields' => 'ids',
		'number' => 0,
	] ) ?: [];

	foreach ( $site_ids as $site_id ) {
		if ( ! switch_to_blog( (int) $site_id ) ) {
			continue;
		}

		uninstall();
		restore_current_blog();
	}
}

/**
 * Remove all plugin data for the current site.
 */
function uninstall(): void {
	$options = [
		OPTION_PREFIX . 'access_token',
		OPTION_PREFIX . 'sync_customers',
		OPTION_PREFIX . 'sync_orders',
		OPTION_PREFIX . 'sync_log',
		OPTION_PREFIX . 'version',
		OPTION_PREFIX . 'bulk_sync_offset',
		OPTION_PREFIX . 'bulk_sync_running',
		OPTION_PREFIX . 'hmac_secret',
		OPTION_PREFIX . 'fin_api_key',
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Clear any scheduled cron events.
	wp_clear_scheduled_hook( 'iws_bulk_sync_cron' );
	wp_clear_scheduled_hook( 'iws_bulk_sync_batch' );
}

run_uninstaller();
