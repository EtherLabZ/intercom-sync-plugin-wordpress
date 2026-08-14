<?php
/**
 * Tests for Intercom_API — log() and has_token() logic.
 *
 * The request() / upsert_contact() methods make HTTP calls via
 * wp_remote_request; those are integration-level and are skipped here.
 * We test the pure logic: token detection, log append, log trim, and
 * that test_connection() returns WP_Error when no token is set.
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
	// test_connection() returns WP_Error when no token
	// ------------------------------------------------------------------

	public function test_test_connection_returns_wp_error_when_no_token(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		// log() will call get_option + update_option.
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

		$api    = new Intercom_API();
		$result = $api->test_connection();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'intercom_no_token', $result->get_error_code() );
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

		$log = $stored['etherlabz_intercom_sync_log'];

		$this->assertCount( 1, $log );
		$this->assertSame( 'success', $log[0]['status'] );
		$this->assertSame( '/me', $log[0]['action'] );
		$this->assertSame( 'HTTP 200', $log[0]['msg'] );
		$this->assertSame( '2026-05-04 12:00:00', $log[0]['time'] );
	}

	public function test_log_prepends_newest_entry_first(): void {
		$stored = array(
			'etherlabz_intercom_sync_log' => array(
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

		$log = $stored['etherlabz_intercom_sync_log'];

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

		$stored = array( 'etherlabz_intercom_sync_log' => $existing );

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

		$log = $stored['etherlabz_intercom_sync_log'];

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

		$this->assertCount( 1, $stored['etherlabz_intercom_sync_log'] );
	}

	// ------------------------------------------------------------------
	// find_tag_by_name() — tests the search-by-name logic against the /tags list.
	// ------------------------------------------------------------------

	public function test_find_tag_by_name_returns_match_when_present(): void {
		Functions\when( 'get_option' )->justReturn( Encryption::encrypt( 'tok' ) );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-04 12:00:00' );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'is_wp_error' )->alias( fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				array(
					'data' => array(
						array(
							'id'   => '101',
							'name' => 'vip',
						),
						array(
							'id'   => '102',
							'name' => 'newsletter',
						),
					),
				)
			)
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_remote_request' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
			)
		);

		$api = new Intercom_API();
		$tag = $api->find_tag_by_name( 'newsletter' );

		$this->assertIsArray( $tag );
		$this->assertSame( '102', $tag['id'] );
	}

	public function test_find_tag_by_name_returns_null_when_not_present(): void {
		Functions\when( 'get_option' )->justReturn( Encryption::encrypt( 'tok' ) );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-04 12:00:00' );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'is_wp_error' )->alias( fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( array( 'data' => array( array( 'id' => '101', 'name' => 'vip' ) ) ) )
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_remote_request' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
			)
		);

		$api = new Intercom_API();

		$this->assertNull( $api->find_tag_by_name( 'missing-tag' ) );
	}

	public function test_find_tag_by_name_propagates_wp_error(): void {
		Functions\when( 'get_option' )->justReturn( Encryption::encrypt( 'tok' ) );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-04 12:00:00' );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'is_wp_error' )->alias( fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				array(
					'errors' => array(
						array(
							'code'    => 'unauthorized',
							'message' => 'Bad token',
						),
					),
				)
			)
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_remote_request' )->justReturn(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => '',
			)
		);

		$api    = new Intercom_API();
		$result = $api->find_tag_by_name( 'whatever' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertSame( 401, $data['http_status'] );
	}

	// ------------------------------------------------------------------
	// format_phone() — E.164 normalisation (pure logic, no HTTP).
	// ------------------------------------------------------------------

	public function test_format_phone_returns_empty_for_blank(): void {
		$this->assertSame( '', Intercom_API::format_phone( '', 'US' ) );
		$this->assertSame( '', Intercom_API::format_phone( '   ', 'US' ) );
	}

	public function test_format_phone_keeps_explicit_plus_country_code(): void {
		$this->assertSame( '+14155552671', Intercom_API::format_phone( '+1 (415) 555-2671', '' ) );
		$this->assertSame( '+442071838750', Intercom_API::format_phone( '+44 20 7183 8750', 'US' ) );
	}

	public function test_format_phone_converts_double_zero_prefix_to_plus(): void {
		$this->assertSame( '+14155552671', Intercom_API::format_phone( '0014155552671', '' ) );
	}

	public function test_format_phone_prepends_country_code_for_national_number(): void {
		// US national number, no country code — derive +1.
		$this->assertSame( '+14155552671', Intercom_API::format_phone( '(415) 555-2671', 'US' ) );
		// UK national with trunk 0 — strip the 0, prepend +44.
		$this->assertSame( '+442071838750', Intercom_API::format_phone( '020 7183 8750', 'GB' ) );
		// India national with trunk 0.
		$this->assertSame( '+919876543210', Intercom_API::format_phone( '09876543210', 'IN' ) );
	}

	public function test_format_phone_returns_empty_when_country_unknown_and_no_code(): void {
		// No '+' and no resolvable country code — too risky to guess.
		$this->assertSame( '', Intercom_API::format_phone( '5551234', '' ) );
		$this->assertSame( '', Intercom_API::format_phone( '020 7183 8750', 'ZZ' ) );
	}

	public function test_format_phone_rejects_implausible_lengths(): void {
		// Too short even with a country code.
		$this->assertSame( '', Intercom_API::format_phone( '+1 234', '' ) );
		// Too long.
		$this->assertSame( '', Intercom_API::format_phone( '+1234567890123456', '' ) );
	}

	// ------------------------------------------------------------------
	// upsert_contact() logs the affected email when a sync fails completely.
	// ------------------------------------------------------------------

	public function test_upsert_contact_logs_email_on_total_failure(): void {
		$stored = array();

		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = null ) use ( &$stored ) {
				if ( 'etherlabz_intercom_access_token' === $key ) {
					return Encryption::encrypt( 'tok' );
				}
				return $stored[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$stored ): bool {
				$stored[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-06-17 00:00:00' );
		Functions\when( 'is_wp_error' )->alias( fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// Search returns no match (200); the POST /contacts then 422s on phone.
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url ) {
				$is_search = false !== strpos( $url, '/contacts/search' );
				return array(
					'response' => array( 'code' => $is_search ? 200 : 422 ),
					'body'     => $is_search
						? json_encode( array( 'data' => array() ) )
						: json_encode(
							array(
								'errors' => array(
									array(
										'code'    => 'parameter_invalid',
										'message' => 'phone is invalid',
									),
								),
							)
						),
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			fn( $r ) => $r['response']['code'] ?? 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			fn( $r ) => $r['body'] ?? ''
		);

		$api    = new Intercom_API();
		$result = $api->upsert_contact(
			array(
				'email' => 'jane@example.com',
				'phone' => '+0',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );

		$log      = $stored['etherlabz_intercom_sync_log'] ?? array();
		$messages = array_column( $log, 'msg' );

		// The named-email failure line is present.
		$matched = array_filter(
			$messages,
			fn( $m ) => false !== strpos( $m, 'Failed for jane@example.com' )
		);
		$this->assertNotEmpty( $matched, 'Expected a failure log naming the email.' );
	}
}
