<?php
/**
 * Order events module.
 *
 * Fires Intercom events when WooCommerce order statuses change.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Order_Events
 */
final class Order_Events implements Registrable {

	/**
	 * Map WooCommerce statuses → Intercom event names.
	 *
	 * @var array<string, string>
	 */
	private const EVENT_MAP = array(
		'pending'    => 'placed-order',
		'processing' => 'order-processing',
		'on-hold'    => 'order-on-hold',
		'completed'  => 'order-completed',
		'cancelled'  => 'order-cancelled',
		'refunded'   => 'order-refunded',
		'shipped'    => 'order-shipped',
	);

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		if ( 'yes' !== get_option( 'etherlabz_intercom_sync_orders', 'yes' ) ) {
			return;
		}

		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_change' ), 10, 3 );
	}

	/**
	 * Handle a WooCommerce order status transition.
	 *
	 * @param int    $order_id The order ID.
	 * @param string $from     The previous status slug.
	 * @param string $to       The new status slug.
	 */
	public function handle_status_change( int $order_id, string $from, string $to ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$email = $order->get_billing_email();

		if ( ! $email ) {
			return;
		}

		$api = new Intercom_API();

		if ( ! $api->has_token() ) {
			return;
		}

		$event_name = self::EVENT_MAP[ $to ] ?? 'order-status-changed';

		/**
		 * Filter the event metadata before it is sent to Intercom.
		 *
		 * @param array    $metadata The event metadata.
		 * @param \WC_Order $order   The WooCommerce order object.
		 * @param string   $from     Previous status.
		 * @param string   $to       New status.
		 */
		$line_items = self::extract_line_items( $order );

		$metadata = apply_filters(
			'etherlabz_intercom_order_event_metadata',
			array(
				'order_id'    => (string) $order_id,
				'order_total' => $order->get_total(),
				'item_count'  => $order->get_item_count(),
				'from_status' => $from,
				'to_status'   => $to,
				'currency'    => $order->get_currency(),
				'line_items'  => wp_json_encode( $line_items ),
			),
			$order,
			$from,
			$to
		);

		// Fire the event.
		$api->create_event( $email, $event_name, $metadata );

		// Resolve the contact (creating it for guest checkouts) and update last-order attributes.
		$customer_id = (int) $order->get_customer_id();

		if ( 0 === $customer_id && 'yes' === get_option( 'etherlabz_intercom_sync_guest_checkout', 'yes' ) ) {
			// Guest checkout: upsert the contact directly from the order.
			$api->upsert_contact( self::guest_contact_payload( $order ) );
		}

		$search = $api->find_contact_by_email( $email );

		if ( ! is_wp_error( $search ) && ! empty( $search['data'][0]['id'] ) ) {
			$api->update_contact(
				$search['data'][0]['id'],
				array(
					'custom_attributes' => array(
						'last_order_status' => $to,
						'last_order_id'     => (string) $order_id,
						'last_order_date'   => time(),
					),
				)
			);
		}
	}

	/**
	 * Extract a compact, JSON-friendly per-line-item array from an order.
	 *
	 * Public + static so it can be unit-tested without live WooCommerce.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function extract_line_items( \WC_Order $order ): array {
		$out = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}

			$product    = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			$categories = array();

			if ( $product ) {
				$terms = wp_get_post_terms( (int) $product->get_id(), 'product_cat', array( 'fields' => 'slugs' ) );
				if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
					$categories = array_values( array_map( 'strval', $terms ) );
				}
			}

			$out[] = array(
				'product_id' => $product ? (string) $product->get_id() : '',
				'name'       => method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '',
				'sku'        => $product ? (string) $product->get_sku() : '',
				'quantity'   => method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 0,
				'unit_price' => $product ? (float) $product->get_price() : 0.0,
				'subtotal'   => method_exists( $item, 'get_subtotal' ) ? (float) $item->get_subtotal() : 0.0,
				'categories' => $categories,
			);
		}

		return $out;
	}

	/**
	 * Build a contact payload for a guest-checkout order.
	 *
	 * Guests have no WP user account, so we identify the contact purely
	 * by email and use the billing details for name/location.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return array<string, mixed>
	 */
	private static function guest_contact_payload( \WC_Order $order ): array {
		$payload = array(
			'role'              => 'user',
			'email'             => (string) $order->get_billing_email(),
			'name'              => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'signed_up_at'      => $order->get_date_created()
				? $order->get_date_created()->getTimestamp()
				: time(),
			'custom_attributes' => array(
				'billing_city'    => (string) $order->get_billing_city(),
				'billing_country' => (string) $order->get_billing_country(),
				'guest_checkout'  => true,
			),
		);

		$e164 = Intercom_API::format_phone(
			(string) $order->get_billing_phone(),
			(string) $order->get_billing_country()
		);
		if ( '' !== $e164 ) {
			$payload['phone'] = $e164;
		}

		return $payload;
	}
}
