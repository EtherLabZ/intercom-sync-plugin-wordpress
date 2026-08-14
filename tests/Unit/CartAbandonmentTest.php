<?php
/**
 * Tests for Cart_Abandonment::run() — focused on the pending-cart
 * iteration / threshold / fired-flag logic, with WP and Intercom_API
 * stubbed via Brain\Monkey.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests\Unit
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Etherlabz\Intercom_Woo_Sync\Core\Encryption;
use Etherlabz\Intercom_Woo_Sync\Modules\Cart_Abandonment;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Etherlabz\Intercom_Woo_Sync\Modules\Cart_Abandonment
 */
class CartAbandonmentTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Stored option backing — read/written by the get_option/update_option stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array(
			'etherlabz_intercom_enable_cart_abandonment' => 'yes',
			'etherlabz_intercom_cart_abandon_minutes'    => 60,
			'etherlabz_intercom_access_token'            => Encryption::encrypt( 'test-token' ),
			'etherlabz_intercom_pending_carts'           => array(),
			'etherlabz_intercom_sync_log'                => array(),
		);

		$opts =& $this->options;

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) use ( &$opts ) {
				return $opts[ $key ] ?? $default;
			}
		);

		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$opts ): bool {
				$opts[ $key ] = $value;
				return true;
			}
		);

		Functions\when( 'current_time' )->justReturn( '2026-05-04 12:00:00' );
		// apply_filters( $hook, $value, ...$rest ) — pass the value through unmodified.
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value = null, ...$rest ) => $value
		);
		Functions\when( 'wp_remote_request' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"event":"created"}',
			)
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $r ) => isset( $r['response']['code'] ) ? (int) $r['response']['code'] : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $r ) => $r['body'] ?? ''
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => $thing instanceof \WP_Error
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_run_does_nothing_when_feature_disabled(): void {
		$this->options['etherlabz_intercom_enable_cart_abandonment'] = 'no';
		$this->options['etherlabz_intercom_pending_carts']           = array(
			1 => array(
				'email'      => 'a@b.com',
				'cart_total' => 10.0,
				'item_count' => 1,
				'updated_at' => time() - ( 2 * 60 * 60 ),
				'fired'      => false,
			),
		);

		( new Cart_Abandonment() )->run();

		$this->assertFalse( $this->options['etherlabz_intercom_pending_carts'][1]['fired'] );
	}

	public function test_run_skips_carts_under_threshold(): void {
		$this->options['etherlabz_intercom_pending_carts'] = array(
			1 => array(
				'email'      => 'a@b.com',
				'cart_total' => 10.0,
				'item_count' => 1,
				'updated_at' => time() - ( 30 * 60 ), // 30 min — under default 60.
				'fired'      => false,
			),
		);

		( new Cart_Abandonment() )->run();

		$this->assertFalse( $this->options['etherlabz_intercom_pending_carts'][1]['fired'] );
	}

	public function test_run_fires_event_for_carts_over_threshold_and_marks_fired(): void {
		$this->options['etherlabz_intercom_pending_carts'] = array(
			42 => array(
				'email'      => 'late@example.com',
				'cart_total' => 99.99,
				'item_count' => 3,
				'coupons'    => array( 'SAVE10' ),
				'updated_at' => time() - ( 90 * 60 ), // 90 min — over default 60.
				'fired'      => false,
			),
		);

		( new Cart_Abandonment() )->run();

		$this->assertTrue( $this->options['etherlabz_intercom_pending_carts'][42]['fired'] );
		$this->assertArrayHasKey( 'fired_at', $this->options['etherlabz_intercom_pending_carts'][42] );
	}

	public function test_run_skips_already_fired_carts(): void {
		$this->options['etherlabz_intercom_pending_carts'] = array(
			1 => array(
				'email'      => 'a@b.com',
				'cart_total' => 10.0,
				'item_count' => 1,
				'updated_at' => time() - ( 90 * 60 ),
				'fired'      => true, // already fired previously.
				'fired_at'   => time() - ( 80 * 60 ),
			),
		);

		$before = $this->options['etherlabz_intercom_pending_carts'][1]['fired_at'];

		( new Cart_Abandonment() )->run();

		// fired_at unchanged means the event was not re-sent.
		$this->assertSame( $before, $this->options['etherlabz_intercom_pending_carts'][1]['fired_at'] );
	}

	public function test_run_uses_default_threshold_for_misconfigured_minutes(): void {
		$this->options['etherlabz_intercom_cart_abandon_minutes'] = 1; // below the 5-minute floor.
		$this->options['etherlabz_intercom_pending_carts']        = array(
			1 => array(
				'email'      => 'a@b.com',
				'cart_total' => 10.0,
				'item_count' => 1,
				'updated_at' => time() - ( 30 * 60 ), // 30 min — under default 60.
				'fired'      => false,
			),
		);

		( new Cart_Abandonment() )->run();

		// Should NOT fire — the misconfigured value falls back to the 60-min default.
		$this->assertFalse( $this->options['etherlabz_intercom_pending_carts'][1]['fired'] );
	}

	public function test_run_drops_corrupt_entries(): void {
		$this->options['etherlabz_intercom_pending_carts'] = array(
			1 => array(
				'email'      => 'a@b.com',
				'cart_total' => 10.0,
				'item_count' => 1,
				'updated_at' => time() - ( 90 * 60 ),
				'fired'      => false,
			),
			2 => 'not-an-array',
		);

		( new Cart_Abandonment() )->run();

		$this->assertArrayNotHasKey( 2, $this->options['etherlabz_intercom_pending_carts'] );
		$this->assertTrue( $this->options['etherlabz_intercom_pending_carts'][1]['fired'] );
	}
}
