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

		register_activation_hook( ETHERLABZ_INTERCOM_FILE, array( self::class, 'activate' ) );
		register_deactivation_hook( ETHERLABZ_INTERCOM_FILE, array( self::class, 'deactivate' ) );

		add_action( 'plugins_loaded', array( self::class, 'maybe_migrate_legacy_options' ) );
	}

	/**
	 * One-time migration from the pre-2.1 `iws_` option/cron prefix.
	 *
	 * Runs on plugins_loaded (activation hooks don't fire on plugin updates).
	 * Guarded by a single autoloaded-option read: once `iws_version` is gone,
	 * this is a no-op.
	 */
	public static function maybe_migrate_legacy_options(): void {
		if ( false === get_option( 'iws_version', false ) ) {
			return;
		}

		$suffixes = array(
			'access_token',
			'sync_customers',
			'sync_orders',
			'sync_log',
			'bulk_sync_offset',
			'bulk_sync_running',
			'hmac_secret',
			'fin_api_key',
			'app_id',
			'enable_messenger',
			'enable_cart_events',
			'enable_cart_abandonment',
			'cart_abandon_minutes',
			'enable_subscriptions',
			'enable_purchase_tags',
			'sync_guest_checkout',
			'pending_carts',
			'segment_rules',
			'fin_action_cancel_enabled',
			'fin_action_refund_enabled',
			'fin_action_note_enabled',
			'last_cron_run',
			'version',
		);

		foreach ( $suffixes as $suffix ) {
			$value = get_option( "iws_{$suffix}", null );

			if ( null !== $value && false === get_option( "etherlabz_intercom_{$suffix}", false ) ) {
				// The log and cart snapshot are bulky and admin-only — don't autoload.
				$autoload = ! in_array( $suffix, array( 'sync_log', 'pending_carts' ), true );
				add_option( "etherlabz_intercom_{$suffix}", $value, '', $autoload );
			}

			delete_option( "iws_{$suffix}" );
		}

		// The copied version option reflects the legacy install; stamp the
		// version that actually performed the migration.
		update_option( 'etherlabz_intercom_version', ETHERLABZ_INTERCOM_VERSION );

		// Retire the legacy cron hooks and make sure the renamed ones exist.
		wp_clear_scheduled_hook( 'iws_bulk_sync_cron' );
		wp_clear_scheduled_hook( 'iws_bulk_sync_batch' );
		wp_clear_scheduled_hook( 'iws_cart_abandonment_cron' );

		if ( ! wp_next_scheduled( 'etherlabz_intercom_bulk_sync_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'etherlabz_intercom_bulk_sync_cron' );
		}
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
						ETHERLABZ_INTERCOM_FILE,
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
		add_option( 'etherlabz_intercom_access_token', '' );
		add_option( 'etherlabz_intercom_sync_customers', 'yes' );
		add_option( 'etherlabz_intercom_sync_orders', 'yes' );
		add_option( 'etherlabz_intercom_sync_log', array(), '', false );
		add_option( 'etherlabz_intercom_hmac_secret', '' );
		add_option( 'etherlabz_intercom_fin_api_key', '' );

		// New feature toggles (default off so upgrades are non-disruptive).
		add_option( 'etherlabz_intercom_app_id', '' );
		add_option( 'etherlabz_intercom_region', 'us' );
		add_option( 'etherlabz_intercom_enable_messenger', 'no' );
		add_option( 'etherlabz_intercom_enable_cart_events', 'no' );
		add_option( 'etherlabz_intercom_enable_cart_abandonment', 'no' );
		add_option( 'etherlabz_intercom_cart_abandon_minutes', 60 );
		add_option( 'etherlabz_intercom_enable_subscriptions', 'no' );
		add_option( 'etherlabz_intercom_enable_purchase_tags', 'no' );
		add_option( 'etherlabz_intercom_sync_guest_checkout', 'yes' );
		add_option( 'etherlabz_intercom_pending_carts', array() );

		// New in 1.5: segments + Fin write-action toggles + cron-health stamp.
		add_option( 'etherlabz_intercom_segment_rules', array() );
		add_option( 'etherlabz_intercom_fin_action_cancel_enabled', 'no' );
		add_option( 'etherlabz_intercom_fin_action_refund_enabled', 'no' );
		add_option( 'etherlabz_intercom_fin_action_note_enabled', 'no' );
		add_option( 'etherlabz_intercom_last_cron_run', 0 );

		// Schedule the bulk-sync cron if it doesn't exist.
		if ( ! wp_next_scheduled( 'etherlabz_intercom_bulk_sync_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'etherlabz_intercom_bulk_sync_cron' );
		}

		update_option( 'etherlabz_intercom_version', ETHERLABZ_INTERCOM_VERSION );
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'etherlabz_intercom_bulk_sync_cron' );
		wp_clear_scheduled_hook( 'etherlabz_intercom_bulk_sync_batch' );
		Modules\Cart_Abandonment::unschedule();
	}
}
