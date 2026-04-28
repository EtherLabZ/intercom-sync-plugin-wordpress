<?php
/**
 * Fin Data Connector — WP REST API endpoints for Intercom Fin.
 *
 * Exposes order and customer data so Fin AI can look up details per customer.
 * Authenticated via a plugin-generated API key (Bearer token).
 *
 * Email resolution priority (from headers):
 *   1. X-Intercom-Verified-Email — trusted, set by Intercom from verified session
 *   2. X-Email                   — untrusted fallback
 *   3. Neither present           — 400 missing_lookup_key
 *
 * When an order ID is present, email ownership is always verified before
 * returning data — returning 404 (not 403) to avoid leaking order existence.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;
use Etherlabz\Intercom_Woo_Sync\Core\Encryption;
use WC_Customer;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Class Fin_Connector
 */
final class Fin_Connector implements Registrable {

	/**
	 * REST namespace.
	 */
	public const REST_NAMESPACE = 'iws/v1';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST routes.
	 *
	 * Email is now resolved from headers — not query params — so no 'email'
	 * arg is declared on routes. Order ID routes still declare 'id'.
	 */
	public function register_routes(): void {
		// GET /wp-json/iws/v1/orders
		register_rest_route( self::REST_NAMESPACE, '/orders', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_orders_by_email' ],
			'permission_callback' => [ $this, 'authenticate' ],
		] );

		// GET /wp-json/iws/v1/orders/details -> pass order ID in header X-Intercom-Verified-OrderId
		register_rest_route( self::REST_NAMESPACE, '/orders/details', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_order_by_id' ],
			'permission_callback' => [ $this, 'authenticate' ],
		] );

		// GET /wp-json/iws/v1/customer
		register_rest_route( self::REST_NAMESPACE, '/customer', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_customer_by_email' ],
			'permission_callback' => [ $this, 'authenticate' ],
		] );
	}

	// -------------------------------------------------------------------------
	// Auth
	// -------------------------------------------------------------------------

	/**
	 * Authenticate the request using the Fin API key (Bearer token).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return true|WP_Error
	 */
	public function authenticate( WP_REST_Request $request ): true|WP_Error {
		$header = $request->get_header( 'Authorization' );

		if ( ! $header || ! str_starts_with( $header, 'Bearer ' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Missing or invalid Authorization header.', 'intercom-woo-sync' ),
				[ 'status' => 401 ]
			);
		}

		$provided = substr( $header, 7 );
		$stored   = Encryption::decrypt( (string) get_option( 'iws_fin_api_key', '' ) );

		if ( '' === $stored || ! hash_equals( $stored, $provided ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Invalid API key.', 'intercom-woo-sync' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Email resolution
	// -------------------------------------------------------------------------

	/**
	 * Resolve the lookup email from request headers.
	 *
	 * Priority:
	 *   1. X-Intercom-Verified-Email (trusted — Intercom verified session)
	 *   2. X-Email                   (untrusted fallback)
	 *   3. Neither present           → WP_Error 400
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return string|WP_Error Sanitized email or error.
	 */
	private function resolve_email( WP_REST_Request $request ): string|WP_Error {
		$verified = $request->get_header( 'X-Intercom-Verified-Email' );
		if ( ! empty( $verified ) ) {
			$email = sanitize_email( $verified );
			if ( is_email( $email ) ) {
				return $email;
			}
		}

		$fallback = $request->get_header( 'X-Email' );
		if ( ! empty( $fallback ) ) {
			$email = sanitize_email( $fallback );
			if ( is_email( $email ) ) {
				return $email;
			}
		}

		return new WP_Error(
			'missing_lookup_key',
			__( 'No valid email provided. Send X-Intercom-Verified-Email or X-Email header.', 'intercom-woo-sync' ),
			[ 'status' => 400 ]
		);
	}
	
	/**
	 * Resolve the order ID from the X-Intercom-Verified-OrderId request header.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return int|WP_Error Positive order ID or error.
	 */
	private function resolve_orderId( WP_REST_Request $request ): int|WP_Error {
		$header = $request->get_header( 'X-Intercom-Verified-OrderId' );

		if ( ! empty( $header ) ) {
			$order_id = (int) $header;
			if ( $order_id > 0 ) {
				return $order_id;
			}
		}

		return new WP_Error(
			'missing_lookup_key',
			__( 'No valid order ID provided. Send X-Intercom-Verified-OrderId header.', 'intercom-woo-sync' ),
			[ 'status' => 400 ]
		);
	}
	
	 
	// -------------------------------------------------------------------------
	// Endpoint callbacks
	// -------------------------------------------------------------------------

	/**
	 * GET /orders
	 *
	 * Returns the 20 most recent orders for the resolved email.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_orders_by_email( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email = $this->resolve_email( $request );
		if ( is_wp_error( $email ) ) {
			return $email;
		}

		$orders = wc_get_orders( [
			'billing_email' => $email,
			'limit'         => 20,
			'orderby'       => 'date',
			'order'         => 'DESC',
		] );

		$data = array_map( [ $this, 'format_order' ], $orders );

		return new WP_REST_Response( [
			'email'       => $email,
			'order_count' => count( $data ),
			'orders'      => $data,
		] );
	}

	/**
	 * GET /orders/<id>
	 *
	 * Returns full order detail. Always verifies the resolved email matches
	 * the order's billing email — returns 404 on mismatch to avoid leaking
	 * that the order exists for a different customer.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_order_by_id( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email = $this->resolve_email( $request );
		if ( is_wp_error( $email ) ) {
			return $email;
		}

		$orderId = $this->resolve_orderId($request);
		if ( is_wp_error( $orderId ) ) {
			return $orderId;
		}

		$order = wc_get_order( (int) $orderId );

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'intercom-woo-sync' ),
				[ 'status' => 404 ]
			);
		}

		// Ownership check — intentionally returns 404 not 403.
		if ( ! $this->email_matches_order( $email, $order ) ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'intercom-woo-sync' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( $this->format_order_detail( $order ) );
	}

	/**
	 * GET /customer
	 *
	 * Returns customer profile and lifetime WooCommerce stats.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_customer_by_email( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email = $this->resolve_email( $request );
		if ( is_wp_error( $email ) ) {
			return $email;
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return new WP_Error(
				'customer_not_found',
				__( 'Customer not found.', 'intercom-woo-sync' ),
				[ 'status' => 404 ]
			);
		}

		$customer = new WC_Customer( $user->ID );

		return new WP_REST_Response( [
			'customer_id'     => $user->ID,
			'email'           => $customer->get_email(),
			'name'            => trim( $customer->get_first_name() . ' ' . $customer->get_last_name() ),
			'phone'           => $customer->get_billing_phone(),
			'billing_city'    => $customer->get_billing_city(),
			'billing_country' => $customer->get_billing_country(),
			'total_orders'    => $customer->get_order_count(),
			'total_spent'     => (float) $customer->get_total_spent(),
			'date_created'    => $customer->get_date_created()
				? $customer->get_date_created()->date( 'c' )
				: null,
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Case-insensitive comparison of an email against an order's billing email.
	 *
	 * @param string   $email Resolved lookup email.
	 * @param WC_Order $order WooCommerce order.
	 *
	 * @return bool
	 */
	private function email_matches_order( string $email, WC_Order $order ): bool {
		return strtolower( $email ) === strtolower( $order->get_billing_email() );
	}

	/**
	 * Format an order for the list response (summary fields only).
	 *
	 * @param WC_Order $order WooCommerce order.
	 *
	 * @return array<string, mixed>
	 */
	private function format_order( WC_Order $order ): array {
		return [
			'order_id'       => $order->get_id(),
			'status'         => $order->get_status(),
			'total'          => $order->get_total(),
			'currency'       => $order->get_currency(),
			'item_count'     => $order->get_item_count(),
			'date_created'   => $order->get_date_created()
				? $order->get_date_created()->date( 'c' )
				: null,
			'payment_method' => $order->get_payment_method_title(),
		];
	}

	/**
	 * Format an order for the detail response (all fields + line items + tracking).
	 *
	 * @param WC_Order $order WooCommerce order.
	 *
	 * @return array<string, mixed>
	 */
	private function format_order_detail( WC_Order $order ): array {
		$items = [];
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$items[] = [
				'name'     => $item->get_name(),
				'sku'      => $product ? $product->get_sku() : '',
				'quantity' => $item->get_quantity(),
				'total'    => $item->get_total(),
			];
		}

		$tracking       = [];
		$tracking_items = $order->get_meta( '_wc_shipment_tracking_items' );
		if ( is_array( $tracking_items ) ) {
			foreach ( $tracking_items as $t ) {
				$tracking[] = [
					'provider'        => $t['tracking_provider']    ?? '',
					'tracking_number' => $t['tracking_number']      ?? '',
					'date_shipped'    => $t['date_shipped']          ?? '',
					'tracking_link'   => $t['custom_tracking_link'] ?? '',
				];
			}
		}

		return [
			'order_id'         => $order->get_id(),
			'status'           => $order->get_status(),
			'total'            => $order->get_total(),
			'subtotal'         => $order->get_subtotal(),
			'shipping_total'   => $order->get_shipping_total(),
			'tax_total'        => $order->get_total_tax(),
			'discount_total'   => $order->get_discount_total(),
			'currency'         => $order->get_currency(),
			'payment_method'   => $order->get_payment_method_title(),
			'date_created'     => $order->get_date_created()
				? $order->get_date_created()->date( 'c' )
				: null,
			'date_modified'    => $order->get_date_modified()
				? $order->get_date_modified()->date( 'c' )
				: null,
			'billing_email'    => $order->get_billing_email(),
			'billing_name'     => $order->get_formatted_billing_full_name(),
			'shipping_name'    => $order->get_formatted_shipping_full_name(),
			'shipping_address' => $order->get_formatted_shipping_address(),
			'shipping_method'  => $order->get_shipping_method(),
			'customer_note'    => $order->get_customer_note(),
			'items'            => $items,
			'tracking'         => $tracking,
		];
	}

	// -------------------------------------------------------------------------
	// Static utilities
	// -------------------------------------------------------------------------

	/**
	 * Generate a cryptographically random API key.
	 *
	 * @return string
	 */
	public static function generate_api_key(): string {
		return wp_generate_password( 48, false, false );
	}

	/**
	 * Get the base REST URL for the orders endpoint.
	 *
	 * @return string
	 */
	public static function get_endpoint_url(): string {
		return rest_url( self::REST_NAMESPACE . '/orders' );
	}
}