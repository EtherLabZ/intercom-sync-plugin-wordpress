<?php
/**
 * Cart abandonment cron.
 *
 * Periodically scans the pending-cart snapshot stored by Cart_Events and
 * fires a `cart-abandoned` Intercom event for each cart that has been idle
 * longer than the configured threshold (default 60 minutes).
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Cart_Abandonment
 */
final class Cart_Abandonment implements Registrable {

	/**
	 * The cron hook name.
	 */
	public const CRON_HOOK = 'etherlabz_intercom_cart_abandonment_cron';

	/**
	 * Default abandonment threshold in minutes.
	 */
	public const DEFAULT_THRESHOLD_MINUTES = 60;

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( self::CRON_HOOK, array( $this, 'run' ) );

		// Self-schedule the cron when the feature is enabled; unschedule it
		// when the feature is turned off so no orphaned event keeps firing.
		if ( 'yes' === get_option( 'etherlabz_intercom_enable_cart_abandonment', 'no' ) ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
			}
		} elseif ( wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Iterate over pending carts and fire abandonment events for stale ones.
	 */
	public function run(): void {
		if ( 'yes' !== get_option( 'etherlabz_intercom_enable_cart_abandonment', 'no' ) ) {
			return;
		}

		$threshold_minutes = (int) get_option( 'etherlabz_intercom_cart_abandon_minutes', self::DEFAULT_THRESHOLD_MINUTES );

		if ( $threshold_minutes < 5 ) {
			$threshold_minutes = self::DEFAULT_THRESHOLD_MINUTES;
		}

		$threshold_seconds = $threshold_minutes * MINUTE_IN_SECONDS;
		$now               = time();
		$pending           = Cart_Events::get_pending_carts();

		if ( empty( $pending ) ) {
			return;
		}

		$api = new Intercom_API();

		if ( ! $api->has_token() ) {
			return;
		}

		$dirty = false;

		foreach ( $pending as $user_id => $entry ) {
			if ( ! is_array( $entry ) ) {
				unset( $pending[ $user_id ] );
				$dirty = true;
				continue;
			}

			// Strict bool check — guard against the classic "string 'false' is truthy"
			// trap if the option ever carries a non-boolean payload (e.g. data import).
			if ( true === ( $entry['fired'] ?? false ) ) {
				continue;
			}

			$updated_at = isset( $entry['updated_at'] ) ? (int) $entry['updated_at'] : 0;
			$email      = isset( $entry['email'] ) ? (string) $entry['email'] : '';

			if ( '' === $email || $updated_at <= 0 ) {
				continue;
			}

			$idle_for = $now - $updated_at;

			if ( $idle_for < $threshold_seconds ) {
				continue;
			}

			$metadata = array(
				'cart_total'      => (float) ( $entry['cart_total'] ?? 0.0 ),
				'item_count'      => (int) ( $entry['item_count'] ?? 0 ),
				'coupons'         => is_array( $entry['coupons'] ?? null )
					? implode( ',', array_map( 'strval', $entry['coupons'] ) )
					: '',
				'idle_minutes'    => (int) round( $idle_for / 60 ),
				'last_updated_at' => $updated_at,
			);

			$metadata = (array) apply_filters(
				'etherlabz_intercom_cart_abandoned_metadata',
				$metadata,
				$user_id,
				$entry
			);

			$result = $api->create_event( $email, 'cart-abandoned', $metadata );

			if ( ! is_wp_error( $result ) ) {
				$pending[ $user_id ]['fired']    = true;
				$pending[ $user_id ]['fired_at'] = $now;
				$dirty                           = true;
			}
		}

		if ( $dirty ) {
			update_option( 'etherlabz_intercom_pending_carts', $pending, false );
		}
	}

	/**
	 * Unschedule the cron — called on plugin deactivation.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
