<?php
/**
 * Intercom WooCommerce Sync
 *
 * @package           Etherlabz\Intercom_Woo_Sync
 * @author            Etherlabz
 * @copyright         2026 Etherlabz
 * @license           GPL-2.0-or-later
 *
 * Plugin Name:       Intercom WooCommerce Sync
 * Plugin URI:        https://github.com/Etherlabz/intercom-woo-sync
 * Description:       Syncs WooCommerce customers and order events to Intercom.
 * Version:           1.3.0
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
	define( 'INTERCOM_WOO_SYNC_VERSION', '1.2.2' );

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
