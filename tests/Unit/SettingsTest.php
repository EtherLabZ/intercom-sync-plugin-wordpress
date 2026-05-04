<?php
/**
 * Tests for Settings sanitizer methods.
 *
 * The sanitizers are pure static methods with no WordPress dependencies
 * (sanitize_token calls Encryption which is also pure PHP), so no
 * Brain\Monkey setup is required.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests\Unit
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Tests\Unit;

use Etherlabz\Intercom_Woo_Sync\Core\Encryption;
use Etherlabz\Intercom_Woo_Sync\Modules\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Etherlabz\Intercom_Woo_Sync\Modules\Settings\Settings
 */
class SettingsTest extends TestCase {

	// ------------------------------------------------------------------
	// sanitize_yes_no()
	// ------------------------------------------------------------------

	public function test_sanitize_yes_no_returns_yes_for_yes(): void {
		$this->assertSame( 'yes', Settings::sanitize_yes_no( 'yes' ) );
	}

	public function test_sanitize_yes_no_returns_no_for_any_other_value(): void {
		$this->assertSame( 'no', Settings::sanitize_yes_no( 'no' ) );
		$this->assertSame( 'no', Settings::sanitize_yes_no( '1' ) );
		$this->assertSame( 'no', Settings::sanitize_yes_no( 'true' ) );
		$this->assertSame( 'no', Settings::sanitize_yes_no( '' ) );
		$this->assertSame( 'no', Settings::sanitize_yes_no( null ) );
		$this->assertSame( 'no', Settings::sanitize_yes_no( false ) );
	}

	// ------------------------------------------------------------------
	// sanitize_token()
	// ------------------------------------------------------------------

	public function test_sanitize_token_returns_empty_string_for_empty_input(): void {
		$this->assertSame( '', Settings::sanitize_token( '' ) );
	}

	public function test_sanitize_token_returns_empty_string_for_non_string(): void {
		$this->assertSame( '', Settings::sanitize_token( null ) );
		$this->assertSame( '', Settings::sanitize_token( false ) );
		$this->assertSame( '', Settings::sanitize_token( 123 ) );
	}

	public function test_sanitize_token_trims_whitespace_before_encrypting(): void {
		$token  = '  my-token  ';
		$result = Settings::sanitize_token( $token );

		// Should be encrypted, not still have the spaces.
		$this->assertTrue( Encryption::is_encrypted( $result ) );
		$this->assertSame( 'my-token', Encryption::decrypt( $result ) );
	}

	public function test_sanitize_token_encrypts_plain_token(): void {
		$token  = 'intercom-access-token-abc123';
		$result = Settings::sanitize_token( $token );

		$this->assertTrue( Encryption::is_encrypted( $result ), 'Token should be stored encrypted.' );
		$this->assertSame( $token, Encryption::decrypt( $result ), 'Decrypting should recover the original token.' );
	}

	public function test_sanitize_token_passes_through_already_encrypted_value(): void {
		// Simulate a re-save where the form posts the stored encrypted value back.
		$token     = 'already-encrypted-token';
		$encrypted = Encryption::encrypt( $token );

		$result = Settings::sanitize_token( $encrypted );

		// Should be returned as-is, not double-encrypted.
		$this->assertSame( $encrypted, $result );
		$this->assertSame( $token, Encryption::decrypt( $result ) );
	}
}
