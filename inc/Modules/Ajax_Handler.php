<?php
/**
 * AJAX request handler for admin actions.
 *
 * Handles test-connection, bulk-sync trigger, and log-clear requests.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;
use Etherlabz\Intercom_Woo_Sync\Core\Encryption;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Ajax_Handler
 */
final class Ajax_Handler implements Registrable {

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_iws_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_iws_bulk_sync', array( $this, 'bulk_sync' ) );
		add_action( 'wp_ajax_iws_clear_log', array( $this, 'clear_log' ) );
		add_action( 'wp_ajax_iws_get_log', array( $this, 'get_log' ) );
		add_action( 'wp_ajax_iws_bulk_sync_status', array( $this, 'bulk_sync_status' ) );
		add_action( 'wp_ajax_iws_register_attributes', array( $this, 'register_attributes' ) );
		add_action( 'wp_ajax_iws_generate_fin_key', array( $this, 'generate_fin_key' ) );
	}

	/**
	 * Test the Intercom API connection.
	 */
	public function test_connection(): void {
		$this->verify_request();

		$api    = new Intercom_API();
		$result = $api->test_connection();

		if ( false === $result ) {
			wp_send_json_error(
				array(
					'message' => __( 'Connection failed. Check your access token.', 'intercom-woo-sync' ),
				)
			);
		}

		$name = $result['name'] ?? $result['email'] ?? 'Unknown';

		wp_send_json_success(
			array(
				'message' => sprintf(
				/* translators: %s: Intercom admin name or email. */
					__( 'Connected successfully as %s.', 'intercom-woo-sync' ),
					esc_html( $name )
				),
			)
		);
	}

	/**
	 * Start a bulk sync.
	 */
	public function bulk_sync(): void {
		$this->verify_request();

		if ( Bulk_Sync::is_running() ) {
			wp_send_json_error(
				array(
					'message' => __( 'A bulk sync is already running.', 'intercom-woo-sync' ),
				)
			);
		}

		Bulk_Sync::start();

		wp_send_json_success(
			array(
				'message' => __( 'Bulk sync started. Customers will be synced in background batches.', 'intercom-woo-sync' ),
			)
		);
	}

	/**
	 * Return the current bulk-sync status.
	 */
	public function bulk_sync_status(): void {
		$this->verify_request();

		wp_send_json_success(
			array(
				'running' => Bulk_Sync::is_running(),
				'offset'  => (int) get_option( 'iws_bulk_sync_offset', 0 ),
			)
		);
	}

	/**
	 * Clear the sync log.
	 */
	public function clear_log(): void {
		$this->verify_request();

		update_option( 'iws_sync_log', array() );

		wp_send_json_success(
			array(
				'message' => __( 'Sync log cleared.', 'intercom-woo-sync' ),
			)
		);
	}

	/**
	 * Return the current sync log as JSON.
	 */
	public function get_log(): void {
		$this->verify_request();

		$log = get_option( 'iws_sync_log', array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		wp_send_json_success( array( 'log' => $log ) );
	}

	/**
	 * Register all required custom data attributes in Intercom.
	 */
	public function register_attributes(): void {
		$this->verify_request();

		$api = new Intercom_API();

		if ( ! $api->has_token() ) {
			wp_send_json_error( array( 'message' => __( 'No API token configured.', 'intercom-woo-sync' ) ) );
		}

		$attributes = array(
			array(
				'name' => 'woo_customer_id',
				'type' => 'integer',
				'desc' => 'WooCommerce customer/user ID',
			),
			array(
				'name' => 'total_orders',
				'type' => 'integer',
				'desc' => 'Total number of WooCommerce orders',
			),
			array(
				'name' => 'lifetime_value',
				'type' => 'float',
				'desc' => 'Customer lifetime spend in store currency',
			),
			array(
				'name' => 'billing_city',
				'type' => 'string',
				'desc' => 'Billing city from WooCommerce',
			),
			array(
				'name' => 'billing_country',
				'type' => 'string',
				'desc' => 'Billing country code from WooCommerce',
			),
			array(
				'name' => 'last_order_status',
				'type' => 'string',
				'desc' => 'Status of the most recent order',
			),
			array(
				'name' => 'last_order_id',
				'type' => 'string',
				'desc' => 'ID of the most recent order',
			),
			array(
				'name' => 'last_order_date',
				'type' => 'integer',
				'desc' => 'Unix timestamp of the most recent order',
			),
		);

		$created = 0;
		$skipped = 0;
		$errors  = 0;

		foreach ( $attributes as $attr ) {
			$result = $api->create_data_attribute( $attr['name'], $attr['type'], $attr['desc'] );

			if ( false === $result ) {
				// Check if it already exists (Intercom returns 409 or specific error).
				++$skipped;
			} else {
				++$created;
			}
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
				/* translators: %1$d: created count, %2$d: skipped count. */
					__( 'Done. %1$d attributes created, %2$d already existed.', 'intercom-woo-sync' ),
					$created,
					$skipped
				),
			)
		);
	}

	/**
	 * Generate (or regenerate) the Fin API key.
	 */
	public function generate_fin_key(): void {
		$this->verify_request();

		$key = Fin_Connector::generate_api_key();
		update_option( 'iws_fin_api_key', Encryption::encrypt( $key ) );

		wp_send_json_success(
			array(
				'message' => __( 'New API key generated. Copy it now — it won\'t be shown again in full.', 'intercom-woo-sync' ),
				'key'     => $key,
			)
		);
	}

	/**
	 * Verify nonce and capability.
	 */
	private function verify_request(): void {
		if ( ! check_ajax_referer( 'iws_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'intercom-woo-sync' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'intercom-woo-sync' ) ), 403 );
		}
	}
}
