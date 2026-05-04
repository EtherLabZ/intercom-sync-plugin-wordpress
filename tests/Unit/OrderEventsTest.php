<?php
/**
 * Tests for Order_Events::extract_line_items() — the pure static
 * that flattens a WC_Order's line items into a JSON-friendly array.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests\Unit
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Etherlabz\Intercom_Woo_Sync\Modules\Order_Events;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Etherlabz\Intercom_Woo_Sync\Modules\Order_Events
 */
class OrderEventsTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => $thing instanceof \WP_Error
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_extract_line_items_returns_empty_array_for_empty_order(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_items' )->andReturn( array() );

		$result = Order_Events::extract_line_items( $order );

		$this->assertSame( array(), $result );
	}

	public function test_extract_line_items_serializes_each_item(): void {
		$product = Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'get_id' )->andReturn( 99 );
		$product->shouldReceive( 'get_sku' )->andReturn( 'SKU-99' );
		$product->shouldReceive( 'get_price' )->andReturn( '12.50' );

		$item = Mockery::mock( \WC_Order_Item_Product::class );
		$item->shouldReceive( 'get_product' )->andReturn( $product );
		$item->shouldReceive( 'get_name' )->andReturn( 'Cool T-Shirt' );
		$item->shouldReceive( 'get_quantity' )->andReturn( 2 );
		$item->shouldReceive( 'get_subtotal' )->andReturn( '25.00' );

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item ) );

		Functions\when( 'wp_get_post_terms' )->justReturn( array( 'apparel' ) );

		$result = Order_Events::extract_line_items( $order );

		$this->assertCount( 1, $result );
		$this->assertSame(
			array(
				'product_id' => '99',
				'name'       => 'Cool T-Shirt',
				'sku'        => 'SKU-99',
				'quantity'   => 2,
				'unit_price' => 12.5,
				'subtotal'   => 25.0,
				'categories' => array( 'apparel' ),
			),
			$result[0]
		);
	}

	public function test_extract_line_items_handles_missing_product_gracefully(): void {
		$item = Mockery::mock( \WC_Order_Item_Product::class );
		$item->shouldReceive( 'get_product' )->andReturn( null );
		$item->shouldReceive( 'get_name' )->andReturn( 'Removed product' );
		$item->shouldReceive( 'get_quantity' )->andReturn( 1 );
		$item->shouldReceive( 'get_subtotal' )->andReturn( '0.00' );

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item ) );

		$result = Order_Events::extract_line_items( $order );

		$this->assertCount( 1, $result );
		$this->assertSame( '', $result[0]['product_id'] );
		$this->assertSame( '', $result[0]['sku'] );
		$this->assertSame( 0.0, $result[0]['unit_price'] );
		$this->assertSame( array(), $result[0]['categories'] );
	}

	public function test_extract_line_items_handles_wp_error_categories(): void {
		$product = Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'get_id' )->andReturn( 1 );
		$product->shouldReceive( 'get_sku' )->andReturn( '' );
		$product->shouldReceive( 'get_price' )->andReturn( '5.00' );

		$item = Mockery::mock( \WC_Order_Item_Product::class );
		$item->shouldReceive( 'get_product' )->andReturn( $product );
		$item->shouldReceive( 'get_name' )->andReturn( 'X' );
		$item->shouldReceive( 'get_quantity' )->andReturn( 1 );
		$item->shouldReceive( 'get_subtotal' )->andReturn( '5.00' );

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item ) );

		Functions\when( 'wp_get_post_terms' )->justReturn( new \WP_Error( 'x', 'x' ) );

		$result = Order_Events::extract_line_items( $order );

		$this->assertSame( array(), $result[0]['categories'] );
	}
}
