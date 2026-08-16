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
		add_action( 'wp_ajax_etherlabz_intercom_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_etherlabz_intercom_bulk_sync', array( $this, 'bulk_sync' ) );
		add_action( 'wp_ajax_etherlabz_intercom_clear_log', array( $this, 'clear_log' ) );
		add_action( 'wp_ajax_etherlabz_intercom_get_log', array( $this, 'get_log' ) );
		add_action( 'wp_ajax_etherlabz_intercom_get_log_filtered', array( $this, 'get_log_filtered' ) );
		add_action( 'wp_ajax_etherlabz_intercom_bulk_sync_status', array( $this, 'bulk_sync_status' ) );
		add_action( 'wp_ajax_etherlabz_intercom_register_attributes', array( $this, 'register_attributes' ) );
		add_action( 'wp_ajax_etherlabz_intercom_generate_fin_key', array( $this, 'generate_fin_key' ) );
		add_action( 'wp_ajax_etherlabz_intercom_save_segments', array( $this, 'save_segments' ) );
		add_action( 'wp_ajax_etherlabz_intercom_run_cron_now', array( $this, 'run_cron_now' ) );
		add_action( 'wp_ajax_etherlabz_intercom_secret_save', array( $this, 'secret_save' ) );
		add_action( 'wp_ajax_etherlabz_intercom_secret_remove', array( $this, 'secret_remove' ) );
	}

	/**
	 * Options the secret save/remove endpoints may touch.
	 *
	 * @var string[]
	 */
	private const SECRET_OPTIONS = array(
		'etherlabz_intercom_access_token',
		'etherlabz_intercom_hmac_secret',
	);

	/**
	 * Save a replacement value for a secret option (applies immediately).
	 */
	public function secret_save(): void {
		$this->verify_request();

		$option = $this->resolve_secret_option();

		// Only trimmed, not sanitize_text_field'd — tokens may contain
		// characters that sanitizer would mangle.
		$value = trim( (string) wp_unslash( $_POST['value'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- encrypted below, never rendered.

		if ( '' === $value ) {
			wp_send_json_error( array( 'message' => __( 'Enter a value first.', 'etherlabz-intercom-sync' ) ) );
		}

		update_option( $option, Encryption::encrypt( $value ) );

		wp_send_json_success( array( 'message' => __( 'Saved.', 'etherlabz-intercom-sync' ) ) );
	}

	/**
	 * Remove a stored secret option (applies immediately).
	 *
	 * Uses delete_option, NOT update_option( ..., '' ): update_option runs the
	 * registered sanitize callback, whose blank-input semantics are "keep the
	 * stored value" — which would silently re-save the secret being removed.
	 */
	public function secret_remove(): void {
		$this->verify_request();

		$option = $this->resolve_secret_option();

		delete_option( $option );

		wp_send_json_success( array( 'message' => __( 'Removed.', 'etherlabz-intercom-sync' ) ) );
	}

	/**
	 * Read and whitelist the posted secret option name; exits on mismatch.
	 */
	private function resolve_secret_option(): string {
		$option = sanitize_key( (string) ( $_POST['option'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $option, self::SECRET_OPTIONS, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown setting.', 'etherlabz-intercom-sync' ) ) );
		}

		return $option;
	}

	/**
	 * Test the Intercom API connection.
	 */
	public function test_connection(): void {
		$this->verify_request();

		if ( Encryption::is_undecryptable( (string) get_option( 'etherlabz_intercom_access_token', '' ) ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Your stored token can no longer be decrypted — your site\'s security keys (AUTH_KEY) changed since it was saved. Re-enter the token and save.', 'etherlabz-intercom-sync' ),
				)
			);
		}

		$api    = new Intercom_API();
		$result = $api->test_connection();

		if ( is_wp_error( $result ) ) {
			$data    = $result->get_error_data();
			$status  = isset( $data['http_status'] ) ? (int) $data['http_status'] : 0;
			$message = 401 === $status || 403 === $status
				? __( 'Authentication failed. Your access token is invalid or expired.', 'etherlabz-intercom-sync' )
				: __( 'Connection failed. Check your access token.', 'etherlabz-intercom-sync' );

			wp_send_json_error( array( 'message' => $message ) );
		}

		$name = $result['name'] ?? $result['email'] ?? __( 'Unknown', 'etherlabz-intercom-sync' );

		wp_send_json_success(
			array(
				'message' => sprintf(
				/* translators: %s: Intercom admin name or email. */
					__( 'Connected successfully as %s.', 'etherlabz-intercom-sync' ),
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
					'message' => __( 'A bulk sync is already running.', 'etherlabz-intercom-sync' ),
				)
			);
		}

		Bulk_Sync::start();

		wp_send_json_success(
			array(
				'message' => __( 'Bulk sync started. Customers will be synced in background batches.', 'etherlabz-intercom-sync' ),
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
				'offset'  => (int) get_option( 'etherlabz_intercom_bulk_sync_offset', 0 ),
			)
		);
	}

	/**
	 * Clear the sync log.
	 */
	public function clear_log(): void {
		$this->verify_request();

		update_option( 'etherlabz_intercom_sync_log', array() );

		wp_send_json_success(
			array(
				'message' => __( 'Sync log cleared.', 'etherlabz-intercom-sync' ),
			)
		);
	}

	/**
	 * Return the current sync log as JSON.
	 */
	public function get_log(): void {
		$this->verify_request();

		$log = get_option( 'etherlabz_intercom_sync_log', array() );

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
			wp_send_json_error( array( 'message' => __( 'No API token configured.', 'etherlabz-intercom-sync' ) ) );
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

			if ( is_wp_error( $result ) ) {
				$data        = $result->get_error_data();
				$http_status = isset( $data['http_status'] ) ? (int) $data['http_status'] : 0;
				$error_code  = $data['error_code'] ?? '';

				// 401 / 403 — invalid or insufficient token; abort immediately.
				if ( 401 === $http_status || 403 === $http_status ) {
					wp_send_json_error(
						array(
							'message' => sprintf(
								/* translators: %d: HTTP status code (401 or 403). */
								__( 'Authentication error (HTTP %d). Please check your Intercom access token.', 'etherlabz-intercom-sync' ),
								$http_status
							),
						)
					);
					return; // wp_send_json_error exits, but return satisfies static analysis.
				}

				// 422 with "attribute_already_exists" — this attribute already exists; skip it.
				if ( 'attribute_already_exists' === $error_code || 409 === $http_status ) {
					++$skipped;
				} else {
					// Any other HTTP error (500, rate-limit 429, etc.) — count as real error.
					++$errors;
				}
			} else {
				++$created;
			}
		}

		if ( $errors > 0 ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
					/* translators: %1$d: created count, %2$d: skipped count, %3$d: error count. */
						__( '%1$d attributes created, %2$d already existed, %3$d failed. Check the sync log for details.', 'etherlabz-intercom-sync' ),
						$created,
						$skipped,
						$errors
					),
				)
			);
			return;
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
				/* translators: %1$d: created count, %2$d: skipped count. */
					__( 'Done. %1$d attributes created, %2$d already existed.', 'etherlabz-intercom-sync' ),
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
		update_option( 'etherlabz_intercom_fin_api_key', Encryption::encrypt( $key ) );

		wp_send_json_success(
			array(
				'message' => __( 'New API key generated. Copy it now — it won\'t be shown again in full.', 'etherlabz-intercom-sync' ),
				'key'     => $key,
			)
		);
	}

	/**
	 * Return the sync log filtered by status and/or action substring,
	 * with optional `since` timestamp for incremental polling.
	 *
	 * Posted params:
	 *   status — 'all' | 'success' | 'error'    (default 'all')
	 *   action — substring match against entry action  (default '')
	 *   since  — ISO-ish "Y-m-d H:i:s" — only entries newer than this  (default '')
	 */
	public function get_log_filtered(): void {
		$this->verify_request();

		$status = sanitize_key( (string) ( $_POST['status'] ?? 'all' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = sanitize_text_field( wp_unslash( (string) ( $_POST['action_q'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$since  = sanitize_text_field( wp_unslash( (string) ( $_POST['since'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$log = get_option( 'etherlabz_intercom_sync_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$filtered = array();
		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( 'all' !== $status && ( $entry['status'] ?? '' ) !== $status ) {
				continue;
			}

			if ( '' !== $action && false === stripos( (string) ( $entry['action'] ?? '' ), $action ) ) {
				continue;
			}

			if ( '' !== $since ) {
				$entry_ts = strtotime( (string) ( $entry['time'] ?? '' ) );
				$since_ts = strtotime( $since );
				if ( false !== $entry_ts && false !== $since_ts && $entry_ts <= $since_ts ) {
					continue;
				}
			}

			$filtered[] = $entry;
		}

		wp_send_json_success(
			array(
				'log'       => $filtered,
				'total'     => count( $log ),
				'displayed' => count( $filtered ),
				'now'       => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Persist the segment rules submitted from the admin UI.
	 *
	 * The full rules array is sent (not a delta) — the UI builds it client-side.
	 */
	public function save_segments(): void {
		$this->verify_request();

		$raw   = isset( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded JSON sanitized below.
		$rules = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		$sanitized = Segments::sanitize_rules( $rules );
		update_option( 'etherlabz_intercom_segment_rules', $sanitized, false );

		wp_send_json_success(
			array(
				'message' => __( 'Segment rules saved.', 'etherlabz-intercom-sync' ),
				'rules'   => array_values( $sanitized ),
			)
		);
	}

	/**
	 * Force-run all plugin crons immediately. Used by the cron-health banner
	 * "Run cron now" button when wp-cron looks stuck.
	 */
	public function run_cron_now(): void {
		$this->verify_request();

		// Spawn cron and dispatch our scheduled hooks if they're due.
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		do_action( 'etherlabz_intercom_bulk_sync_cron' );
		do_action( 'etherlabz_intercom_cart_abandonment_cron' );

		Cron_Health::stamp_run();

		wp_send_json_success(
			array(
				'message' => __( 'Plugin crons dispatched. Refresh the page to clear the warning.', 'etherlabz-intercom-sync' ),
			)
		);
	}

	/**
	 * Verify nonce and capability.
	 */
	private function verify_request(): void {
		if ( ! check_ajax_referer( 'etherlabz_intercom_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'etherlabz-intercom-sync' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'etherlabz-intercom-sync' ) ), 403 );
		}
	}
}
