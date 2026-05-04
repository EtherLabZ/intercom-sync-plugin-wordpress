<?php
/**
 * Tests for Subscription_Events — the WC Subscriptions lifecycle module.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests\Unit
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Etherlabz\Intercom_Woo_Sync\Core\Encryption;
use Etherlabz\Intercom_Woo_Sync\Modules\Subscription_Events;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Etherlabz\Intercom_Woo_Sync\Modules\Subscription_Events
 */
class SubscriptionEventsTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Captured remote-request payloads for assertion.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $captured = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->captured = array();

		Functions\when( 'get_option' )->alias(
			fn( string $key, $default = null ) => 'iws_access_token' === $key
				? Encryption::encrypt( 'test-token' )
				: ( $default ?? '' )
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-04 12:00:00' );
		// apply_filters( $hook, $value, ...$rest ) — pass the value through unmodified.
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value = null, ...$rest ) => $value
		);
		Functions\when( 'is_wp_error' )->alias( fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		// Capture every wp_remote_request call so tests can assert on the payload.
		$captured =& $this->captured;
		Functions\when( 'wp_remote_request' )->alias(
			static function ( string $url, array $args ) use ( &$captured ) {
				$captured[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
				);
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_subscription( string $email = 'sub@example.com' ): object {
		$item = Mockery::mock( \WC_Order_Item_Product::class );
		$item->shouldReceive( 'get_name' )->andReturn( 'Premium Plan' );

		$sub = Mockery::mock( \WC_Subscription::class );
		$sub->shouldReceive( 'get_billing_email' )->andReturn( $email );
		$sub->shouldReceive( 'get_id' )->andReturn( 7 );
		$sub->shouldReceive( 'get_total' )->andReturn( '19.99' );
		$sub->shouldReceive( 'get_currency' )->andReturn( 'USD' );
		$sub->shouldReceive( 'get_items' )->andReturn( array( $item ) );
		$sub->shouldReceive( 'get_date' )->andReturn( '2026-06-04 12:00:00' );

		return $sub;
	}

	public function test_status_change_to_cancelled_fires_cancelled_event(): void {
		$sub = $this->make_subscription();

		( new Subscription_Events() )->on_status_changed( $sub, 'active', 'cancelled' );

		$this->assertCount( 1, $this->captured );
		$payload = json_decode( $this->captured[0]['args']['body'], true );

		$this->assertSame( '/events', parse_url( $this->captured[0]['url'], PHP_URL_PATH ) );
		$this->assertSame( 'subscription-cancelled', $payload['event_name'] );
		$this->assertSame( 'sub@example.com', $payload['email'] );
		$this->assertSame( '7', $payload['metadata']['subscription_id'] );
		$this->assertSame( 'Premium Plan', $payload['metadata']['plan_name'] );
		$this->assertSame( 'active', $payload['metadata']['from_status'] );
	}

	public function test_status_change_to_unknown_status_uses_generic_event(): void {
		$sub = $this->make_subscription();

		( new Subscription_Events() )->on_status_changed( $sub, 'active', 'something-weird' );

		$payload = json_decode( $this->captured[0]['args']['body'], true );
		$this->assertSame( 'subscription-status-changed', $payload['event_name'] );
	}

	public function test_renewal_complete_fires_renewed_event(): void {
		$sub = $this->make_subscription();

		( new Subscription_Events() )->on_renewal_complete( $sub );

		$payload = json_decode( $this->captured[0]['args']['body'], true );
		$this->assertSame( 'subscription-renewed', $payload['event_name'] );
	}

	public function test_renewal_failed_fires_payment_failed_event(): void {
		$sub = $this->make_subscription();

		( new Subscription_Events() )->on_renewal_failed( $sub );

		$payload = json_decode( $this->captured[0]['args']['body'], true );
		$this->assertSame( 'subscription-payment-failed', $payload['event_name'] );
	}

	public function test_event_is_skipped_when_subscription_has_no_email(): void {
		$sub = $this->make_subscription( '' );

		( new Subscription_Events() )->on_renewal_complete( $sub );

		$this->assertCount( 0, $this->captured );
	}

	public function test_event_is_skipped_for_non_subscription_object(): void {
		( new Subscription_Events() )->on_renewal_complete( 'not-an-object' );

		$this->assertCount( 0, $this->captured );
	}
}
