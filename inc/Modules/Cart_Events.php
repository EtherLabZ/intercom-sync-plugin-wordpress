<?php
/**
 * Cart and front-end funnel events.
 *
 * Fires Intercom events for product views, cart adds, coupon applies,
 * and checkout starts. Also keeps a lightweight per-user "pending cart"
 * record that the Cart_Abandonment module consumes.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;
use WC_Product;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Cart_Events
 */
final class Cart_Events implements Registrable {

	/**
	 * Throttle key for product-viewed events (one event per product per user per hour).
	 */
	private const VIEW_THROTTLE_TRANSIENT = 'etherlabz_intercom_pv_';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		if ( 'yes' !== get_option( 'etherlabz_intercom_enable_cart_events', 'no' ) ) {
			return;
		}

		// Front-end product page view.
		add_action( 'template_redirect', array( $this, 'maybe_track_product_view' ) );

		// Cart adds and coupon applies.
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 20, 6 );
		add_action( 'woocommerce_applied_coupon', array( $this, 'on_coupon_applied' ), 20, 1 );

		// Checkout started — the form is rendered (not yet submitted).
		add_action( 'woocommerce_before_checkout_form', array( $this, 'on_checkout_started' ), 20 );

		// Update / clear the pending-cart snapshot used by Cart_Abandonment.
		add_action( 'woocommerce_cart_updated', array( $this, 'snapshot_cart' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'clear_pending_cart' ), 5, 1 );
	}

	/**
	 * Fire a `product-viewed` event when a logged-in customer lands on a product page.
	 */
	public function maybe_track_product_view(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! $user || ! $user->exists() || ! $user->user_email ) {
			return;
		}

		$product = wc_get_product( get_queried_object_id() );

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id = (int) $product->get_id();

		// Throttle: don't re-fire within an hour for the same user/product.
		$throttle_key = self::VIEW_THROTTLE_TRANSIENT . $user->ID . '_' . $product_id;

		if ( false !== get_transient( $throttle_key ) ) {
			return;
		}

		set_transient( $throttle_key, 1, HOUR_IN_SECONDS );

		$this->fire_event(
			$user->user_email,
			'product-viewed',
			array(
				'product_id'   => (string) $product_id,
				'product_name' => $product->get_name(),
				'product_sku'  => (string) $product->get_sku(),
				'price'        => (float) $product->get_price(),
				'category'     => $this->primary_category_slug( $product_id ),
				'permalink'    => get_permalink( $product_id ),
			)
		);
	}

	/**
	 * Fire `cart-added` event on `woocommerce_add_to_cart`.
	 *
	 * @param string $cart_item_key   The cart item hash.
	 * @param int    $product_id      The product ID added.
	 * @param int    $quantity        Quantity added.
	 * @param int    $variation_id    Variation ID, if any.
	 * @param array  $variation       Variation attributes.
	 * @param array  $cart_item_data  Cart item data array.
	 */
	public function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ): void {
		unset( $cart_item_key, $variation, $cart_item_data ); // Unused but required by signature.

		$email = $this->resolve_email_for_session();

		if ( '' === $email ) {
			return;
		}

		$resolved_id = (int) ( $variation_id > 0 ? $variation_id : $product_id );
		$product     = wc_get_product( $resolved_id );

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$this->fire_event(
			$email,
			'cart-added',
			array(
				'product_id'   => (string) $resolved_id,
				'product_name' => $product->get_name(),
				'product_sku'  => (string) $product->get_sku(),
				'price'        => (float) $product->get_price(),
				'quantity'     => (int) $quantity,
				'cart_total'   => $this->cart_total(),
				'item_count'   => $this->cart_item_count(),
			)
		);
	}

	/**
	 * Fire `coupon-applied` event when a coupon is applied to the cart.
	 *
	 * @param string $coupon_code The coupon code applied.
	 */
	public function on_coupon_applied( $coupon_code ): void {
		$email = $this->resolve_email_for_session();

		if ( '' === $email ) {
			return;
		}

		$coupon = new \WC_Coupon( $coupon_code );

		$metadata = array(
			'coupon_code'     => $coupon_code,
			'discount_type'   => (string) $coupon->get_discount_type(),
			'discount_amount' => (float) $coupon->get_amount(),
			'cart_total'      => $this->cart_total(),
		);

		$this->fire_event( $email, 'coupon-applied', $metadata );

		// Persist last-coupon attribute on the contact.
		$api = new Intercom_API();

		if ( $api->has_token() ) {
			$search = $api->find_contact_by_email( $email );

			if ( ! is_wp_error( $search ) && ! empty( $search['data'][0]['id'] ) ) {
				$api->update_contact(
					$search['data'][0]['id'],
					array(
						'custom_attributes' => array(
							'last_coupon_used' => $coupon_code,
						),
					)
				);
			}
		}
	}

	/**
	 * Fire `checkout-started` event when the checkout form is rendered.
	 */
	public function on_checkout_started(): void {
		$email = $this->resolve_email_for_session();

		if ( '' === $email ) {
			return;
		}

		// Throttle to once per session per cart contents to avoid duplicate fires.
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$cart_hash = $this->cart_hash();
		$last_hash = (string) WC()->session->get( 'etherlabz_intercom_checkout_started_hash', '' );

		if ( '' !== $cart_hash && $cart_hash === $last_hash ) {
			return;
		}

		WC()->session->set( 'etherlabz_intercom_checkout_started_hash', $cart_hash );

		$this->fire_event(
			$email,
			'checkout-started',
			array(
				'cart_total' => $this->cart_total(),
				'item_count' => $this->cart_item_count(),
				'coupons'    => implode( ',', $this->applied_coupons() ),
			)
		);
	}

	/**
	 * Persist a snapshot of the current cart for the abandonment cron.
	 */
	public function snapshot_cart(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! $user || ! $user->exists() || ! $user->user_email ) {
			return;
		}

		if ( WC()->cart->is_empty() ) {
			$this->clear_pending_cart_for_user( (int) $user->ID );
			return;
		}

		$pending                    = self::get_pending_carts();
		$pending[ (int) $user->ID ] = array(
			'email'      => $user->user_email,
			'name'       => $user->display_name,
			'cart_total' => $this->cart_total(),
			'item_count' => $this->cart_item_count(),
			'coupons'    => $this->applied_coupons(),
			'updated_at' => time(),
			'fired'      => false,
		);

		// Cap at 500 most-recent pending carts to keep the option row bounded.
		if ( count( $pending ) > 500 ) {
			uasort(
				$pending,
				static function ( array $a, array $b ): int {
					return ( $b['updated_at'] ?? 0 ) <=> ( $a['updated_at'] ?? 0 );
				}
			);
			$pending = array_slice( $pending, 0, 500, true );
		}

		update_option( 'etherlabz_intercom_pending_carts', $pending, false );
	}

	/**
	 * Clear the pending-cart record after a successful checkout.
	 *
	 * @param int $order_id Order ID from `woocommerce_thankyou`.
	 */
	public function clear_pending_cart( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();

		if ( $customer_id > 0 ) {
			$this->clear_pending_cart_for_user( $customer_id );
		}
	}

	/**
	 * Remove the pending-cart entry for a given user.
	 *
	 * @param int $user_id User ID.
	 */
	private function clear_pending_cart_for_user( int $user_id ): void {
		$pending = self::get_pending_carts();

		if ( isset( $pending[ $user_id ] ) ) {
			unset( $pending[ $user_id ] );
			update_option( 'etherlabz_intercom_pending_carts', $pending, false );
		}
	}

	/**
	 * Return the pending-carts option as an array.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_pending_carts(): array {
		$pending = get_option( 'etherlabz_intercom_pending_carts', array() );
		return is_array( $pending ) ? $pending : array();
	}

	// ----------------------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------------------

	/**
	 * Resolve the email to attribute the event to.
	 *
	 * Logged-in user → user_email. Otherwise → checkout posted billing_email
	 * (only available during the checkout flow). Returns '' if none available.
	 */
	private function resolve_email_for_session(): string {
		$user = wp_get_current_user();

		if ( $user && $user->exists() && $user->user_email ) {
			return (string) $user->user_email;
		}

		// During checkout, WC populates posted billing_email into the session.
		if ( function_exists( 'WC' ) && WC()->session ) {
			$customer = WC()->session->get( 'customer' );
			if ( is_array( $customer ) && ! empty( $customer['email'] ) && is_email( $customer['email'] ) ) {
				return (string) $customer['email'];
			}
		}

		return '';
	}

	/**
	 * Get the cart total as a float.
	 */
	private function cart_total(): float {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		return (float) WC()->cart->get_total( 'edit' );
	}

	/**
	 * Get the cart item count.
	 */
	private function cart_item_count(): int {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}

		return (int) WC()->cart->get_cart_contents_count();
	}

	/**
	 * Get the applied coupon codes as an array.
	 *
	 * @return string[]
	 */
	private function applied_coupons(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		$coupons = WC()->cart->get_applied_coupons();
		return is_array( $coupons ) ? array_values( array_map( 'strval', $coupons ) ) : array();
	}

	/**
	 * Compute a stable hash of the current cart contents for throttling purposes.
	 */
	private function cart_hash(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}

		$contents = WC()->cart->get_cart_contents();
		$payload  = array();

		foreach ( $contents as $key => $item ) {
			$payload[ (string) $key ] = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
		}

		ksort( $payload );

		return md5( (string) wp_json_encode( $payload ) );
	}

	/**
	 * Resolve the primary category slug for a product, or empty string.
	 *
	 * @param int $product_id Product post ID.
	 */
	private function primary_category_slug( int $product_id ): string {
		$terms = get_the_terms( $product_id, 'product_cat' );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		$first = reset( $terms );

		return isset( $first->slug ) ? (string) $first->slug : '';
	}

	/**
	 * Send an event via Intercom_API, applying the public metadata filter.
	 *
	 * @param string               $email      Contact email.
	 * @param string               $event_name Event name.
	 * @param array<string, mixed> $metadata   Event metadata.
	 */
	private function fire_event( string $email, string $event_name, array $metadata ): void {
		$api = new Intercom_API();

		if ( ! $api->has_token() ) {
			return;
		}

		$metadata = (array) apply_filters( 'etherlabz_intercom_cart_event_metadata', $metadata, $event_name, $email );

		$api->create_event( $email, $event_name, $metadata );
	}
}
