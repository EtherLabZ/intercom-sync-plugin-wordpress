<?php
/**
 * Tests for Fin_Connector static helpers and auth logic.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests\Unit
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Etherlabz\Intercom_Woo_Sync\Modules\Fin_Connector;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Etherlabz\Intercom_Woo_Sync\Modules\Fin_Connector
 */
class FinConnectorTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// generate_api_key()
	// ------------------------------------------------------------------

	public function test_generate_api_key_returns_non_empty_string(): void {
		Functions\when( 'wp_generate_password' )->alias(
			function ( int $length, bool $special_chars, bool $extra_special_chars ): string {
				return bin2hex( random_bytes( (int) ( $length / 2 ) ) );
			}
		);

		$key = Fin_Connector::generate_api_key();

		$this->assertIsString( $key );
		$this->assertNotEmpty( $key );
	}

	public function test_generate_api_key_returns_different_value_each_call(): void {
		$call = 0;
		Functions\when( 'wp_generate_password' )->alias(
			function () use ( &$call ): string {
				return 'key-' . ( ++$call );
			}
		);

		$key1 = Fin_Connector::generate_api_key();
		$key2 = Fin_Connector::generate_api_key();

		$this->assertNotSame( $key1, $key2 );
	}

	// ------------------------------------------------------------------
	// get_endpoint_url()
	// ------------------------------------------------------------------

	public function test_get_endpoint_url_returns_url_containing_namespace(): void {
		Functions\when( 'rest_url' )->alias(
			function ( string $path ): string {
				return 'https://example.com/wp-json/' . ltrim( $path, '/' );
			}
		);

		$url = Fin_Connector::get_endpoint_url();

		$this->assertStringContainsString( 'etherlabz-intercom/v1/orders', $url );
	}

	// ------------------------------------------------------------------
	// email_matches_order() — tested via authenticate() indirectly
	// using reflection on the private method
	// ------------------------------------------------------------------

	public function test_email_matches_order_is_case_insensitive(): void {
		$connector = new Fin_Connector();
		$method = new \ReflectionMethod( Fin_Connector::class, 'email_matches_order' );

		// Create a minimal WC_Order stub.
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_billing_email' )->andReturn( 'User@Example.COM' );

		$this->assertTrue( $method->invoke( $connector, 'user@example.com', $order ) );
		$this->assertTrue( $method->invoke( $connector, 'USER@EXAMPLE.COM', $order ) );
		$this->assertFalse( $method->invoke( $connector, 'other@example.com', $order ) );
	}

	// ------------------------------------------------------------------
	// REST_NAMESPACE constant
	// ------------------------------------------------------------------

	public function test_rest_namespace_is_correct(): void {
		$this->assertSame( 'etherlabz-intercom/v1', Fin_Connector::REST_NAMESPACE );
	}
}
