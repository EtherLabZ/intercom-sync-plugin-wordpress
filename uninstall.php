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
const OPTION_PREFIX = 'etherlabz_intercom_';

/**
 * Run uninstaller — handles multisite.
 */
function run_uninstaller(): void {
	if ( ! is_multisite() ) {
		uninstall();
		return;
	}

	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	if ( ! is_array( $site_ids ) ) {
		$site_ids = array();
	}

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
	$options = array(
		OPTION_PREFIX . 'access_token',
		OPTION_PREFIX . 'sync_customers',
		OPTION_PREFIX . 'sync_orders',
		OPTION_PREFIX . 'sync_log',
		OPTION_PREFIX . 'version',
		OPTION_PREFIX . 'bulk_sync_offset',
		OPTION_PREFIX . 'bulk_sync_running',
		OPTION_PREFIX . 'hmac_secret',
		OPTION_PREFIX . 'fin_api_key',
		OPTION_PREFIX . 'app_id',
		OPTION_PREFIX . 'region',
		OPTION_PREFIX . 'enable_messenger',
		OPTION_PREFIX . 'enable_cart_events',
		OPTION_PREFIX . 'enable_cart_abandonment',
		OPTION_PREFIX . 'cart_abandon_minutes',
		OPTION_PREFIX . 'enable_subscriptions',
		OPTION_PREFIX . 'enable_purchase_tags',
		OPTION_PREFIX . 'sync_guest_checkout',
		OPTION_PREFIX . 'pending_carts',
		OPTION_PREFIX . 'segment_rules',
		OPTION_PREFIX . 'fin_action_cancel_enabled',
		OPTION_PREFIX . 'fin_action_refund_enabled',
		OPTION_PREFIX . 'fin_action_note_enabled',
		OPTION_PREFIX . 'last_cron_run',
	);

	foreach ( $options as $option ) {
		delete_option( $option );

		// Pre-2.1 installs stored the same options under the iws_ prefix.
		delete_option( 'iws_' . substr( $option, strlen( OPTION_PREFIX ) ) );
	}

	// Clear any scheduled cron events (current and pre-2.1 hook names).
	wp_clear_scheduled_hook( 'etherlabz_intercom_bulk_sync_cron' );
	wp_clear_scheduled_hook( 'etherlabz_intercom_bulk_sync_batch' );
	wp_clear_scheduled_hook( 'etherlabz_intercom_cart_abandonment_cron' );
	wp_clear_scheduled_hook( 'iws_bulk_sync_cron' );
	wp_clear_scheduled_hook( 'iws_bulk_sync_batch' );
	wp_clear_scheduled_hook( 'iws_cart_abandonment_cron' );
}

run_uninstaller();
