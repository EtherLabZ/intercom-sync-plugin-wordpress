<?php
/**
 * Purchase-based tag manager.
 *
 * Applies Intercom tags to contacts based on their order activity (e.g.
 * `purchased-{slug}`, `purchased-category-{slug}`) on order completion,
 * and removes those tags on refund.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;
use WC_Order;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Tag_Manager
 */
final class Tag_Manager implements Registrable {

	/**
	 * Tag prefix for product purchases.
	 */
	public const PRODUCT_TAG_PREFIX = 'purchased-';

	/**
	 * Tag prefix for category purchases.
	 */
	public const CATEGORY_TAG_PREFIX = 'purchased-category-';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		if ( 'yes' !== get_option( 'iws_enable_purchase_tags', 'no' ) ) {
			return;
		}

		add_action( 'woocommerce_order_status_completed', array( $this, 'apply_purchase_tags' ), 20, 1 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'remove_purchase_tags' ), 20, 1 );
	}

	/**
	 * Apply purchase tags to the order's contact on order completion.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function apply_purchase_tags( int $order_id ): void {
		$this->process_tags( $order_id, 'apply' );
	}

	/**
	 * Remove purchase tags from the order's contact on refund.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function remove_purchase_tags( int $order_id ): void {
		$this->process_tags( $order_id, 'remove' );
	}

	/**
	 * Compute the tag list for an order.
	 *
	 * Returns slugs like:
	 *   purchased-{product-slug}
	 *   purchased-category-{category-slug}
	 *
	 * Made public + static so tests can exercise it without a live API.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return string[] Unique list of tag names.
	 */
	public static function tags_for_order( WC_Order $order ): array {
		$tags = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product' ) ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product ) {
				continue;
			}

			$slug = $product->get_slug();

			if ( $slug ) {
				$tags[] = self::PRODUCT_TAG_PREFIX . $slug;
			}

			$category_terms = wp_get_post_terms( (int) $product->get_id(), 'product_cat', array( 'fields' => 'slugs' ) );

			if ( ! is_wp_error( $category_terms ) && is_array( $category_terms ) ) {
				foreach ( $category_terms as $cat_slug ) {
					if ( $cat_slug ) {
						$tags[] = self::CATEGORY_TAG_PREFIX . $cat_slug;
					}
				}
			}
		}

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- iws_ is the documented public hook prefix.
		$tags = (array) apply_filters( 'iws_purchase_tags', array_values( array_unique( $tags ) ), $order );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return array_values( array_unique( array_filter( array_map( 'strval', $tags ) ) ) );
	}

	/**
	 * Apply or remove tags for an order's contact.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $mode     'apply' or 'remove'.
	 */
	private function process_tags( int $order_id, string $mode ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$email = (string) $order->get_billing_email();

		if ( '' === $email ) {
			return;
		}

		$api = new Intercom_API();

		if ( ! $api->has_token() ) {
			return;
		}

		$tags = self::tags_for_order( $order );

		if ( empty( $tags ) ) {
			return;
		}

		// Resolve the contact ID by email.
		$search = $api->find_contact_by_email( $email );

		if ( is_wp_error( $search ) || empty( $search['data'][0]['id'] ) ) {
			return;
		}

		$contact_id = (string) $search['data'][0]['id'];

		foreach ( $tags as $tag_name ) {
			$tag = $api->find_tag_by_name( $tag_name );

			if ( is_wp_error( $tag ) ) {
				continue;
			}

			// Create the tag lazily on first apply.
			if ( null === $tag && 'apply' === $mode ) {
				$created = $api->create_tag( $tag_name );
				if ( is_wp_error( $created ) ) {
					continue;
				}
				$tag = $created;
			}

			if ( ! is_array( $tag ) || empty( $tag['id'] ) ) {
				continue;
			}

			$tag_id = (string) $tag['id'];

			if ( 'apply' === $mode ) {
				$api->tag_contact( $contact_id, $tag_id );
			} else {
				$api->untag_contact( $contact_id, $tag_id );
			}
		}
	}
}
