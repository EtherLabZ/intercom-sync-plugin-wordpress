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
	private const EVENT_MAP = [
		'pending'    => 'placed-order',
		'processing' => 'order-processing',
		'on-hold'    => 'order-on-hold',
		'completed'  => 'order-completed',
		'cancelled'  => 'order-cancelled',
		'refunded'   => 'order-refunded',
		'shipped'    => 'order-shipped',
	];

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		if ( 'yes' !== get_option( 'iws_sync_orders', 'yes' ) ) {
			return;
		}

		add_action( 'woocommerce_order_status_changed', [ $this, 'handle_status_change' ], 10, 3 );
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
		$metadata = apply_filters( 'iws_order_event_metadata', [
			'order_id'    => (string) $order_id,
			'order_total' => $order->get_total(),
			'item_count'  => $order->get_item_count(),
			'from_status' => $from,
			'to_status'   => $to,
			'currency'    => $order->get_currency(),
		], $order, $from, $to );

		// Fire the event.
		$api->create_event( $email, $event_name, $metadata );

		// Update the contact's last-order custom attributes.
		$customer_id = $order->get_customer_id();

		if ( $customer_id ) {
			$search = $api->find_contact_by_email( $email );

			if ( ! empty( $search['data'][0]['id'] ) ) {
				$api->update_contact( $search['data'][0]['id'], [
					'custom_attributes' => [
						'last_order_status' => $to,
						'last_order_id'     => (string) $order_id,
						'last_order_date'   => time(),
					],
				] );
			}
		}
	}
}
