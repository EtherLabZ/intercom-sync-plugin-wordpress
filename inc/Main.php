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
	private const REGISTRABLE_CLASSES = array(
		Core\Assets::class,
		Modules\Customer_Sync::class,
		Modules\Order_Events::class,
		Modules\Bulk_Sync::class,
		Modules\Settings\Admin_Screen::class,
		Modules\Settings\Settings::class,
		Modules\Ajax_Handler::class,
		Modules\Fin_Connector::class,
		Modules\Messenger::class,
		Modules\Cart_Events::class,
		Modules\Cart_Abandonment::class,
		Modules\Subscription_Events::class,
		Modules\Tag_Manager::class,
		Modules\Segments::class,
		Modules\Cron_Health::class,
		Modules\Fin_Actions::class,
	);

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
		$this->declare_compatibility();

		register_activation_hook( INTERCOM_WOO_SYNC_FILE, array( self::class, 'activate' ) );
		register_deactivation_hook( INTERCOM_WOO_SYNC_FILE, array( self::class, 'deactivate' ) );
	}

	/**
	 * Declare WooCommerce feature compatibility (HPOS, etc.).
	 */
	private function declare_compatibility(): void {
		add_action(
			'before_woocommerce_init',
			static function (): void {
				if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
						'custom_order_tables',
						INTERCOM_WOO_SYNC_FILE,
						true
					);
				}
			}
		);
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
		add_option( 'iws_sync_log', array() );
		add_option( 'iws_hmac_secret', '' );
		add_option( 'iws_fin_api_key', '' );

		// New feature toggles (default off so upgrades are non-disruptive).
		add_option( 'iws_app_id', '' );
		add_option( 'iws_enable_messenger', 'no' );
		add_option( 'iws_enable_cart_events', 'no' );
		add_option( 'iws_enable_cart_abandonment', 'no' );
		add_option( 'iws_cart_abandon_minutes', 60 );
		add_option( 'iws_enable_subscriptions', 'no' );
		add_option( 'iws_enable_purchase_tags', 'no' );
		add_option( 'iws_sync_guest_checkout', 'yes' );
		add_option( 'iws_pending_carts', array() );

		// New in 1.5: segments + Fin write-action toggles + cron-health stamp.
		add_option( 'iws_segment_rules', array() );
		add_option( 'iws_fin_action_cancel_enabled', 'no' );
		add_option( 'iws_fin_action_refund_enabled', 'no' );
		add_option( 'iws_fin_action_note_enabled', 'no' );
		add_option( 'iws_last_cron_run', 0 );

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
		Modules\Cart_Abandonment::unschedule();
	}
}
