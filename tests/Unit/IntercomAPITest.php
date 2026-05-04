<?php
/**
 * Tests for Intercom_API — log() and has_token() logic.
 *
 * The request() / upsert_contact() methods make HTTP calls via
 * wp_remote_request; those are integration-level and are skipped here.
 * We test the pure logic: token detection, log append, log trim, and
 * that test_connection() returns false when no token is set.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests\Unit
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Etherlabz\Intercom_Woo_Sync\Core\Encryption;
use Etherlabz\Intercom_Woo_Sync\Modules\Intercom_API;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Etherlabz\Intercom_Woo_Sync\Modules\Intercom_API
 */
class IntercomAPITest extends TestCase {
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
	// has_token()
	// ------------------------------------------------------------------

	public function test_has_token_returns_false_when_option_is_empty(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$api = new Intercom_API();

		$this->assertFalse( $api->has_token() );
	}

	public function test_has_token_returns_true_when_token_is_set(): void {
		$encrypted = Encryption::encrypt( 'my-real-token' );

		Functions\when( 'get_option' )->justReturn( $encrypted );

		$api = new Intercom_API();

		$this->assertTrue( $api->has_token() );
	}

	// ------------------------------------------------------------------
	// test_connection() returns false when no token
	// ------------------------------------------------------------------

	public function test_test_connection_returns_false_when_no_token(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		// log() will call get_option + update_option.
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

		$api = new Intercom_API();

		$this->assertFalse( $api->test_connection() );
	}

	// ------------------------------------------------------------------
	// log()
	// ------------------------------------------------------------------

	public function test_log_appends_entry_to_front_of_log(): void {
		$stored = array();

		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = null ) use ( &$stored ) {
				return $stored[ $key ] ?? $default;
			}
		);

		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$stored ): bool {
				$stored[ $key ] = $value;
				return true;
			}
		);

		Functions\when( 'current_time' )->justReturn( '2026-05-04 12:00:00' );

		Intercom_API::log( 'success', '/me', 'HTTP 200' );

		$log = $stored['iws_sync_log'];

		$this->assertCount( 1, $log );
		$this->assertSame( 'success', $log[0]['status'] );
		$this->assertSame( '/me', $log[0]['action'] );
		$this->assertSame( 'HTTP 200', $log[0]['msg'] );
		$this->assertSame( '2026-05-04 12:00:00', $log[0]['time'] );
	}

	public function test_log_prepends_newest_entry_first(): void {
		$stored = array(
			'iws_sync_log' => array(
				array(
					'time'   => '2026-05-04 11:00:00',
					'status' => 'success',
					'action' => '/contacts',
					'msg'    => 'old entry',
				),
			),
		);

		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = null ) use ( &$stored ) {
				return $stored[ $key ] ?? $default;
			}
		);

		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$stored ): bool {
				$stored[ $key ] = $value;
				return true;
			}
		);

		Functions\when( 'current_time' )->justReturn( '2026-05-04 12:00:00' );

		Intercom_API::log( 'error', '/events', 'HTTP 500' );

		$log = $stored['iws_sync_log'];

		// Newest entry is first.
		$this->assertSame( 'HTTP 500', $log[0]['msg'] );
		$this->assertSame( 'old entry', $log[1]['msg'] );
	}

	public function test_log_trims_to_100_entries(): void {
		// Seed 100 existing entries.
		$existing = array_fill(
			0,
			100,
			array(
				'time'   => '2026-01-01 00:00:00',
				'status' => 'success',
				'action' => '/contacts',
				'msg'    => 'old',
			)
		);

		$stored = array( 'iws_sync_log' => $existing );

		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = null ) use ( &$stored ) {
				return $stored[ $key ] ?? $default;
			}
		);

		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$stored ): bool {
				$stored[ $key ] = $value;
				return true;
			}
		);

		Functions\when( 'current_time' )->justReturn( '2026-05-04 12:00:00' );

		Intercom_API::log( 'error', '/new', 'newest entry' );

		$log = $stored['iws_sync_log'];

		// Should still be 100 entries, not 101.
		$this->assertCount( 100, $log );
		// Newest should be first.
		$this->assertSame( 'newest entry', $log[0]['msg'] );
	}

	public function test_log_handles_corrupt_stored_option_gracefully(): void {
		$stored = array();

		Functions\when( 'get_option' )->justReturn( 'not-an-array' );

		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$stored ): bool {
				$stored[ $key ] = $value;
				return true;
			}
		);

		Functions\when( 'current_time' )->justReturn( '2026-05-04 12:00:00' );

		// Should not throw — should reset the log and append the entry.
		Intercom_API::log( 'success', '/me', 'HTTP 200' );

		$this->assertCount( 1, $stored['iws_sync_log'] );
	}
}
