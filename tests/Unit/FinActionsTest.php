<?php
/**
 * Tests for Fin_Actions — the gated REST write endpoints for Fin AI.
 *
 * Focuses on:
 *   1. is_enabled() reads the right option key per action
 *   2. cancel_order returns errors for missing/terminal orders, success on valid
 *   3. refund_order rejects out-of-range amounts, accepts valid
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests\Unit
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Etherlabz\Intercom_Woo_Sync\Modules\Fin_Actions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Etherlabz\Intercom_Woo_Sync\Modules\Fin_Actions
 */
class FinActionsTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Stored options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();
		$opts          =& $this->options;

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) use ( &$opts ) {
				return $opts[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-07 12:00:00' );
		Functions\when( 'is_wp_error' )->alias( fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => trim( (string) $s ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( $s ) => trim( (string) $s ) );
		Functions\when( 'sanitize_email' )->alias( static fn( $s ) => trim( (string) $s ) );
		Functions\when( 'is_email' )->alias(
			static fn( $s ) => false !== filter_var( (string) $s, FILTER_VALIDATE_EMAIL )
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'rest_ensure_response' )->alias(
			static fn( $data ) => (object) array(
				'data' => $data,
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// is_enabled()
	// ------------------------------------------------------------------

	public function test_is_enabled_defaults_to_false(): void {
		$this->assertFalse( Fin_Actions::is_enabled( 'cancel' ) );
		$this->assertFalse( Fin_Actions::is_enabled( 'refund' ) );
		$this->assertFalse( Fin_Actions::is_enabled( 'note' ) );
	}

	public function test_is_enabled_reads_correct_option_per_action(): void {
		$this->options['iws_fin_action_cancel_enabled'] = 'yes';
		$this->options['iws_fin_action_refund_enabled'] = 'no';
		$this->options['iws_fin_action_note_enabled']   = 'yes';

		$this->assertTrue( Fin_Actions::is_enabled( 'cancel' ) );
		$this->assertFalse( Fin_Actions::is_enabled( 'refund' ) );
		$this->assertTrue( Fin_Actions::is_enabled( 'note' ) );
	}

	public function test_is_enabled_returns_false_for_unknown_action(): void {
		$this->assertFalse( Fin_Actions::is_enabled( 'destroy_universe' ) );
	}

	// ------------------------------------------------------------------
	// cancel_order
	// ------------------------------------------------------------------

	public function test_cancel_order_returns_404_when_order_missing(): void {
		Functions\when( 'wc_get_order' )->justReturn( false );

		$module = new Fin_Actions();
		$result = $module->cancel_order( $this->make_request( 99 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_cancel_order_requires_a_caller_email(): void {
		$order = $this->make_order( 'customer@example.com' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$module = new Fin_Actions();
		$result = $module->cancel_order( $this->make_request( 1, array(), array() ) ); // no email headers.

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_lookup_key', $result->get_error_code() );
	}

	public function test_cancel_order_returns_404_when_email_does_not_own_order(): void {
		$order = $this->make_order( 'owner@example.com' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$module = new Fin_Actions();
		$result = $module->cancel_order( $this->make_request( 1 ) ); // caller is customer@example.com.

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_cancel_order_rejects_terminal_states(): void {
		$order = $this->make_order( 'customer@example.com' );
		$order->shouldReceive( 'has_status' )->andReturn( true );
		$order->shouldReceive( 'get_status' )->andReturn( 'completed' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$module = new Fin_Actions();
		$result = $module->cancel_order( $this->make_request( 1 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cannot_cancel', $result->get_error_code() );
	}

	public function test_cancel_order_updates_status_when_valid(): void {
		$order = $this->make_order( 'customer@example.com' );
		$order->shouldReceive( 'has_status' )->andReturn( false );
		$order->shouldReceive( 'update_status' )
			->with( 'cancelled', Mockery::any() )
			->once();

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$module = new Fin_Actions();
		$result = $module->cancel_order( $this->make_request( 5, array( 'reason' => 'Customer requested' ) ) );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cancelled', $result->data['status'] );
		$this->assertSame( 'Customer requested', $result->data['reason'] );
	}

	// ------------------------------------------------------------------
	// refund_order — amount range guard
	// ------------------------------------------------------------------

	public function test_refund_order_rejects_amount_above_remaining(): void {
		$order = $this->make_order( 'customer@example.com' );
		$order->shouldReceive( 'get_total' )->andReturn( '100.00' );
		$order->shouldReceive( 'get_total_refunded' )->andReturn( '40.00' );
		// Max refundable: 60.

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$module  = new Fin_Actions();
		$request = $this->make_request( 1, array( 'amount' => 75 ) );

		$result = $module->refund_order( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_amount', $result->get_error_code() );
	}

	public function test_refund_order_rejects_zero_or_negative_amount(): void {
		$order = $this->make_order( 'customer@example.com' );
		$order->shouldReceive( 'get_total' )->andReturn( '100.00' );
		$order->shouldReceive( 'get_total_refunded' )->andReturn( '0.00' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$module = new Fin_Actions();

		$this->assertInstanceOf(
			\WP_Error::class,
			$module->refund_order( $this->make_request( 1, array( 'amount' => 0 ) ) )
		);
		$this->assertInstanceOf(
			\WP_Error::class,
			$module->refund_order( $this->make_request( 1, array( 'amount' => -5 ) ) )
		);
	}

	public function test_refund_order_returns_404_when_email_does_not_own_order(): void {
		$order = $this->make_order( 'owner@example.com' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$module = new Fin_Actions();
		$result = $module->refund_order( $this->make_request( 1, array( 'amount' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_refund_order_creates_refund_at_default_full_amount(): void {
		$order = $this->make_order( 'customer@example.com' );
		$order->shouldReceive( 'get_total' )->andReturn( '50.00' );
		$order->shouldReceive( 'get_total_refunded' )->andReturn( '0.00' );

		$refund = Mockery::mock( \WC_Order_Refund::class );
		$refund->shouldReceive( 'get_id' )->andReturn( 999 );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'wc_create_refund' )->justReturn( $refund );

		$module  = new Fin_Actions();
		$request = $this->make_request( 1, array() ); // no amount → defaults to full.

		$result = $module->refund_order( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 50.0, $result->data['amount'] );
		$this->assertSame( 999, $result->data['refund_id'] );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Build a minimal WP_REST_Request mock with the given id, params, and headers.
	 *
	 * Defaults to a verified-email header owning `customer@example.com`, which
	 * matches the order built by make_order() in the happy-path tests.
	 *
	 * @param int                   $id      Path parameter `id`.
	 * @param array<string, mixed>  $params  Body / query params.
	 * @param array<string, string>|null $headers Headers (name => value); null = verified customer@example.com.
	 *
	 * @return \WP_REST_Request
	 */
	private function make_request( int $id, array $params = array(), ?array $headers = null ) {
		if ( null === $headers ) {
			$headers = array( 'X-Intercom-Verified-Email' => 'customer@example.com' );
		}

		$request = Mockery::mock( '\WP_REST_Request' );
		$request->shouldReceive( 'offsetGet' )->with( 'id' )->andReturn( $id );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static fn( $k ) => $params[ $k ] ?? null
		);
		$request->shouldReceive( 'get_header' )->andReturnUsing(
			static fn( $name ) => $headers[ $name ] ?? ''
		);

		// PHPUnit's ArrayAccess: $request['id'] -> offsetGet('id').
		// Mockery handles offsetGet via the magic method declarations on WP_REST_Request.
		return $request;
	}

	/**
	 * Build an order mock with a billing email (for the ownership check).
	 *
	 * @param string $billing_email The order's billing email.
	 *
	 * @return \Mockery\MockInterface&\WC_Order
	 */
	private function make_order( string $billing_email ) {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_billing_email' )->andReturn( $billing_email );
		return $order;
	}
}
