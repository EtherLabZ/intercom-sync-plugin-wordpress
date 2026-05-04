<?php
/**
 * Tests for Tag_Manager::tags_for_order() — the pure static that
 * computes the tag list for an order from its items + categories.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests\Unit
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Etherlabz\Intercom_Woo_Sync\Modules\Tag_Manager;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Etherlabz\Intercom_Woo_Sync\Modules\Tag_Manager
 */
class TagManagerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// apply_filters( $hook, $value, ...$rest ) — pass the value through unmodified.
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value = null, ...$rest ) => $value
		);
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => $thing instanceof \WP_Error
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_tags_for_order_returns_empty_array_for_empty_order(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_items' )->andReturn( array() );

		Functions\when( 'wp_get_post_terms' )->justReturn( array() );

		$tags = Tag_Manager::tags_for_order( $order );

		$this->assertSame( array(), $tags );
	}

	public function test_tags_for_order_builds_product_and_category_tags(): void {
		$product = Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'get_slug' )->andReturn( 'blue-hat' );
		$product->shouldReceive( 'get_id' )->andReturn( 101 );

		$item = Mockery::mock( \WC_Order_Item_Product::class );
		$item->shouldReceive( 'get_product' )->andReturn( $product );

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item ) );

		Functions\when( 'wp_get_post_terms' )->justReturn( array( 'apparel', 'hats' ) );

		$tags = Tag_Manager::tags_for_order( $order );

		$this->assertContains( 'purchased-blue-hat', $tags );
		$this->assertContains( 'purchased-category-apparel', $tags );
		$this->assertContains( 'purchased-category-hats', $tags );
		$this->assertCount( 3, $tags );
	}

	public function test_tags_for_order_dedupes_tags(): void {
		$product = Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'get_slug' )->andReturn( 'shirt' );
		$product->shouldReceive( 'get_id' )->andReturn( 50 );

		// Same product appears twice (e.g. via two line items).
		$item_a = Mockery::mock( \WC_Order_Item_Product::class );
		$item_a->shouldReceive( 'get_product' )->andReturn( $product );
		$item_b = Mockery::mock( \WC_Order_Item_Product::class );
		$item_b->shouldReceive( 'get_product' )->andReturn( $product );

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item_a, $item_b ) );

		Functions\when( 'wp_get_post_terms' )->justReturn( array( 'tops' ) );

		$tags = Tag_Manager::tags_for_order( $order );

		$this->assertSame(
			array(
				'purchased-shirt',
				'purchased-category-tops',
			),
			$tags
		);
	}

	public function test_tags_for_order_skips_items_with_no_product(): void {
		$item = Mockery::mock( \WC_Order_Item_Product::class );
		$item->shouldReceive( 'get_product' )->andReturn( null );

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item ) );

		Functions\when( 'wp_get_post_terms' )->justReturn( array() );

		$tags = Tag_Manager::tags_for_order( $order );

		$this->assertSame( array(), $tags );
	}

	public function test_tags_for_order_handles_wp_error_terms(): void {
		$product = Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'get_slug' )->andReturn( 'mug' );
		$product->shouldReceive( 'get_id' )->andReturn( 7 );

		$item = Mockery::mock( \WC_Order_Item_Product::class );
		$item->shouldReceive( 'get_product' )->andReturn( $product );

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item ) );

		Functions\when( 'wp_get_post_terms' )->justReturn( new \WP_Error( 'fail', 'fail' ) );

		$tags = Tag_Manager::tags_for_order( $order );

		$this->assertSame( array( 'purchased-mug' ), $tags );
	}
}
