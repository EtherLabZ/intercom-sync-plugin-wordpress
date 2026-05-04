<?php
/**
 * Customer sync module.
 *
 * Upserts WooCommerce customers to Intercom contacts on create / update.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;
use WC_Customer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Customer_Sync
 */
final class Customer_Sync implements Registrable {

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		if ( 'yes' !== get_option( 'iws_sync_customers', 'yes' ) ) {
			return;
		}

		add_action( 'woocommerce_new_customer', array( $this, 'sync' ) );
		add_action( 'woocommerce_update_customer', array( $this, 'sync' ) );
		// Also fires when an external system pushes via WC REST API.
		add_action( 'woocommerce_rest_insert_customer', array( $this, 'sync_rest' ), 10, 1 );
	}

	/**
	 * Sync a single customer to Intercom.
	 *
	 * @param int $customer_id WooCommerce customer (user) ID.
	 */
	public function sync( int $customer_id ): void {
		if ( ! class_exists( 'WC_Customer' ) ) {
			return;
		}

		$customer = new WC_Customer( $customer_id );

		if ( ! $customer->get_email() ) {
			return;
		}

		$api = new Intercom_API();

		if ( ! $api->has_token() ) {
			return;
		}

		/**
		 * Filter the contact payload before it is sent to Intercom.
		 *
		 * @param array       $data        The contact data array.
		 * @param WC_Customer $customer    The WooCommerce customer object.
		 */
		$phone = $customer->get_billing_phone();

		$payload = array(
			'role'              => 'user',
			'external_id'       => (string) $customer_id,
			'email'             => $customer->get_email(),
			'name'              => trim( $customer->get_first_name() . ' ' . $customer->get_last_name() ),
			'signed_up_at'      => $customer->get_date_created()
				? $customer->get_date_created()->getTimestamp()
				: time(),
			'custom_attributes' => array(
				'woo_customer_id' => $customer_id,
				'total_orders'    => $customer->get_order_count(),
				'lifetime_value'  => (float) $customer->get_total_spent(),
				'billing_city'    => $customer->get_billing_city(),
				'billing_country' => $customer->get_billing_country(),
			),
		);

		// Intercom requires E.164 format (+<country><number>, 7-15 digits).
		// Attempt to normalise, otherwise skip to avoid 422 errors.
		if ( $phone ) {
			$digits = preg_replace( '/[^\d]/', '', $phone );
			if ( strlen( $digits ) >= 7 && strlen( $digits ) <= 15 ) {
				$payload['phone'] = '+' . $digits;
			}
		}

		$data = apply_filters( 'iws_contact_payload', $payload, $customer ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- iws_ is the documented public hook prefix; renaming would break downstream integrations.

		$api->upsert_contact( $data );
	}

	/**
	 * Handle the REST API insert hook.
	 *
	 * @param WC_Customer $customer The customer object.
	 */
	public function sync_rest( $customer ): void {
		if ( method_exists( $customer, 'get_id' ) ) {
			$this->sync( (int) $customer->get_id() );
		}
	}
}
