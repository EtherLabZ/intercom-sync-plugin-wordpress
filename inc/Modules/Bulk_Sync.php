<?php
/**
 * Bulk sync module.
 *
 * Provides a WP-Cron–powered batch sync that pushes all WooCommerce
 * customers to Intercom in manageable chunks.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Bulk_Sync
 */
final class Bulk_Sync implements Registrable {

	/**
	 * Number of customers to process per batch.
	 */
	private const BATCH_SIZE = 25;

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'iws_bulk_sync_cron', array( $this, 'run_batch' ) );
		add_action( 'iws_bulk_sync_batch', array( $this, 'run_batch' ) );
	}

	/**
	 * Process one batch of customers.
	 *
	 * Reads the current offset from an option, syncs the next BATCH_SIZE
	 * customers, then either schedules another batch or clears the offset.
	 */
	public function run_batch(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$api = new Intercom_API();

		if ( ! $api->has_token() ) {
			Intercom_API::log( 'error', 'bulk-sync', 'No API token configured.' );
			return;
		}

		$offset = (int) get_option( 'iws_bulk_sync_offset', 0 );

		$customers = get_users(
			array(
				'role'    => 'customer',
				'number'  => self::BATCH_SIZE,
				'offset'  => $offset,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		if ( empty( $customers ) ) {
			// All done — reset offset and log completion.
			delete_option( 'iws_bulk_sync_offset' );
			update_option( 'iws_bulk_sync_running', 'no' );
			Intercom_API::log( 'success', 'bulk-sync', "Completed. {$offset} customers processed." );
			return;
		}

		$syncer = new Customer_Sync();
		$synced = 0;

		foreach ( $customers as $user ) {
			$syncer->sync( (int) $user->ID );
			++$synced;
		}

		$new_offset = $offset + $synced;
		update_option( 'iws_bulk_sync_offset', $new_offset );

		Intercom_API::log(
			'success',
			'bulk-sync',
			"Batch done — synced {$synced} customers (offset now {$new_offset})."
		);

		// Schedule the next batch asynchronously.
		if ( ! wp_next_scheduled( 'iws_bulk_sync_batch' ) ) {
			wp_schedule_single_event( time() + 5, 'iws_bulk_sync_batch' );
		}
	}

	/**
	 * Kick off a full bulk sync (called from the admin UI).
	 */
	public static function start(): void {
		update_option( 'iws_bulk_sync_offset', 0 );
		update_option( 'iws_bulk_sync_running', 'yes' );
		Intercom_API::log( 'success', 'bulk-sync', 'Bulk sync started.' );

		// Fire the first batch immediately via a single cron event.
		if ( ! wp_next_scheduled( 'iws_bulk_sync_batch' ) ) {
			wp_schedule_single_event( time(), 'iws_bulk_sync_batch' );
		}

		// Attempt to spawn cron now so it doesn't wait for next page-load.
		spawn_cron();
	}

	/**
	 * Whether a bulk sync is currently running.
	 */
	public static function is_running(): bool {
		return 'yes' === get_option( 'iws_bulk_sync_running', 'no' );
	}
}
