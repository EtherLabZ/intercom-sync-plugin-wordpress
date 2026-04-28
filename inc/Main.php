<?php
/**
 * The main plugin bootstrap file.
 *
 * @package Etherlabz\Intercom_Woo_Sync
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync;

use Etherlabz\Intercom_Woo_Sync\Contracts\Singleton;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Main
 */
final class Main {
	use Singleton;

	/**
	 * Registrable classes — each one hooks itself into WordPress.
	 *
	 * @var class-string<\Etherlabz\Intercom_Woo_Sync\Contracts\Registrable>[]
	 */
	private const REGISTRABLE_CLASSES = [
		Core\Assets::class,
		Modules\Customer_Sync::class,
		Modules\Order_Events::class,
		Modules\Bulk_Sync::class,
		Modules\Settings\Admin_Screen::class,
		Modules\Settings\Settings::class,
		Modules\Ajax_Handler::class,
		Modules\Fin_Connector::class,
	];

	/**
	 * {@inheritDoc}
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->setup();
		}

		return self::$instance;
	}

	/**
	 * Setup the plugin.
	 */
	private function setup(): void {
		$this->load();

		register_activation_hook( INTERCOM_WOO_SYNC_FILE, [ self::class, 'activate' ] );
		register_deactivation_hook( INTERCOM_WOO_SYNC_FILE, [ self::class, 'deactivate' ] );
	}

	/**
	 * Instantiate and register all modules.
	 */
	private function load(): void {
		foreach ( self::REGISTRABLE_CLASSES as $class_name ) {
			( new $class_name() )->register_hooks();
		}
	}

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		add_option( 'iws_access_token', '' );
		add_option( 'iws_sync_customers', 'yes' );
		add_option( 'iws_sync_orders', 'yes' );
		add_option( 'iws_sync_log', [] );
		add_option( 'iws_hmac_secret', '' );
		add_option( 'iws_fin_api_key', '' );

		// Schedule the bulk-sync cron if it doesn't exist.
		if ( ! wp_next_scheduled( 'iws_bulk_sync_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'iws_bulk_sync_cron' );
		}

		update_option( 'iws_version', INTERCOM_WOO_SYNC_VERSION );
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'iws_bulk_sync_cron' );
	}
}
