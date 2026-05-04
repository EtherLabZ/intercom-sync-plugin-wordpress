<?php
/**
 * Tests for the Messenger module — focused on pure logic
 * (HMAC generation, App ID retrieval). The footer rendering is
 * an integration concern and is not covered here.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests\Unit
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Etherlabz\Intercom_Woo_Sync\Core\Encryption;
use Etherlabz\Intercom_Woo_Sync\Modules\Messenger;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Etherlabz\Intercom_Woo_Sync\Modules\Messenger
 */
class MessengerTest extends TestCase {
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
	// generate_user_hash()
	// ------------------------------------------------------------------

	public function test_generate_user_hash_returns_empty_string_for_empty_identifier(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$this->assertSame( '', Messenger::generate_user_hash( '' ) );
	}

	public function test_generate_user_hash_returns_empty_string_when_secret_not_set(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$this->assertSame( '', Messenger::generate_user_hash( 'user@example.com' ) );
	}

	public function test_generate_user_hash_produces_correct_sha256_hmac(): void {
		$secret    = 'my-identity-verification-secret';
		$encrypted = Encryption::encrypt( $secret );

		Functions\when( 'get_option' )->alias(
			static function ( string $key ) use ( $encrypted ) {
				return 'iws_hmac_secret' === $key ? $encrypted : '';
			}
		);

		$expected = hash_hmac( 'sha256', 'user@example.com', $secret );

		$this->assertSame( $expected, Messenger::generate_user_hash( 'user@example.com' ) );
	}

	public function test_generate_user_hash_is_deterministic(): void {
		$secret    = 'my-secret';
		$encrypted = Encryption::encrypt( $secret );

		Functions\when( 'get_option' )->alias(
			static function ( string $key ) use ( $encrypted ) {
				return 'iws_hmac_secret' === $key ? $encrypted : '';
			}
		);

		$first  = Messenger::generate_user_hash( 'a@b.com' );
		$second = Messenger::generate_user_hash( 'a@b.com' );

		$this->assertSame( $first, $second );
		$this->assertNotEmpty( $first );
	}

	public function test_generate_user_hash_differs_per_identifier(): void {
		$secret    = 'my-secret';
		$encrypted = Encryption::encrypt( $secret );

		Functions\when( 'get_option' )->alias(
			static function ( string $key ) use ( $encrypted ) {
				return 'iws_hmac_secret' === $key ? $encrypted : '';
			}
		);

		$this->assertNotSame(
			Messenger::generate_user_hash( 'a@b.com' ),
			Messenger::generate_user_hash( 'c@d.com' )
		);
	}

	// ------------------------------------------------------------------
	// get_app_id()
	// ------------------------------------------------------------------

	public function test_get_app_id_returns_trimmed_value(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) {
				return 'iws_app_id' === $key ? '   abc123  ' : '';
			}
		);

		$this->assertSame( 'abc123', Messenger::get_app_id() );
	}

	public function test_get_app_id_returns_empty_string_when_not_set(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$this->assertSame( '', Messenger::get_app_id() );
	}

	// ------------------------------------------------------------------
	// get_secret() — round-trips through Encryption
	// ------------------------------------------------------------------

	public function test_get_secret_decrypts_stored_value(): void {
		$plain     = 'top-secret';
		$encrypted = Encryption::encrypt( $plain );

		Functions\when( 'get_option' )->alias(
			static function ( string $key ) use ( $encrypted ) {
				return 'iws_hmac_secret' === $key ? $encrypted : '';
			}
		);

		$this->assertSame( $plain, Messenger::get_secret() );
	}
}
