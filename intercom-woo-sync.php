<?php
/**
 * Etherlabz Intercom Sync for WooCommerce
 *
 * @package           Etherlabz\Intercom_Woo_Sync
 * @author            Etherlabz
 * @copyright         2026 Etherlabz
 * @license           GPL-2.0-or-later
 *
 * Plugin Name:       Etherlabz Intercom Sync for WooCommerce
 * Plugin URI:        https://github.com/Evy04/intercom-sync
 * Description:       The complete Intercom integration for WooCommerce. Syncs customers, order events, cart funnel, abandoned carts, subscriptions and purchase tags — with HMAC-secure Messenger embed. Built by Etherlabz.
 * Version:           1.5.0-rc1
 * Author:            Etherlabz
 * Author URI:        https://etherlabz.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       intercom-woo-sync
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Tested up to:      6.7
 * Requires Plugins:  woocommerce
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Define the plugin constants.
 */
function constants(): void {
	/**
	 * File path to the plugin's main file.
	 */
	define( 'INTERCOM_WOO_SYNC_FILE', __FILE__ );

	/**
	 * Version of the plugin.
	 */
	define( 'INTERCOM_WOO_SYNC_VERSION', '1.5.0-rc1' );

	/**
	 * Root path to the plugin directory.
	 */
	define( 'INTERCOM_WOO_SYNC_PATH', plugin_dir_path( INTERCOM_WOO_SYNC_FILE ) );

	/**
	 * Root URL to the plugin directory.
	 */
	define( 'INTERCOM_WOO_SYNC_URL', plugin_dir_url( INTERCOM_WOO_SYNC_FILE ) );
}

constants();

// Load the autoloader.
require_once __DIR__ . '/inc/Autoloader.php';
Autoloader::register();

// Load the main plugin class.
Main::get_instance();
