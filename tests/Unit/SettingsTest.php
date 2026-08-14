<?php
/**
 * Tests for Settings sanitizer methods.
 *
 * Most sanitizers are pure static methods; the secret sanitizers read the
 * currently stored option (to keep it when the field is submitted blank),
 * so Brain\Monkey stubs get_option for those.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests\Unit
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Etherlabz\Intercom_Woo_Sync\Core\Encryption;
use Etherlabz\Intercom_Woo_Sync\Modules\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Etherlabz\Intercom_Woo_Sync\Modules\Settings\Settings
 */
class SettingsTest extends TestCase {

	/**
	 * Stored options for the get_option stub.
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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

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
	// sanitize_access_token() / sanitize_hmac_secret()
	// ------------------------------------------------------------------

	public function test_empty_input_with_nothing_stored_returns_empty_string(): void {
		$this->assertSame( '', Settings::sanitize_access_token( '' ) );
		$this->assertSame( '', Settings::sanitize_hmac_secret( null ) );
	}

	public function test_empty_input_keeps_the_stored_token(): void {
		$this->options['iws_access_token'] = Encryption::encrypt( 'stored-token' );

		$result = Settings::sanitize_access_token( '' );

		$this->assertTrue( Encryption::is_encrypted( $result ) );
		$this->assertSame( 'stored-token', Encryption::decrypt( $result ) );
	}

	public function test_empty_input_migrates_stored_plaintext_to_encrypted(): void {
		// Pre-encryption installs stored the token as plain text.
		$this->options['iws_access_token'] = 'legacy-plaintext-token';

		$result = Settings::sanitize_access_token( '' );

		$this->assertTrue( Encryption::is_encrypted( $result ) );
		$this->assertSame( 'legacy-plaintext-token', Encryption::decrypt( $result ) );
	}

	public function test_token_trims_whitespace_before_encrypting(): void {
		$result = Settings::sanitize_access_token( '  my-token  ' );

		$this->assertTrue( Encryption::is_encrypted( $result ) );
		$this->assertSame( 'my-token', Encryption::decrypt( $result ) );
	}

	public function test_token_encrypts_plain_token(): void {
		$token  = 'intercom-access-token-abc123';
		$result = Settings::sanitize_access_token( $token );

		$this->assertTrue( Encryption::is_encrypted( $result ), 'Token should be stored encrypted.' );
		$this->assertSame( $token, Encryption::decrypt( $result ), 'Decrypting should recover the original token.' );
	}

	public function test_token_passes_through_already_encrypted_value(): void {
		// Simulate a re-save where the form posts the stored encrypted value back.
		$token     = 'already-encrypted-token';
		$encrypted = Encryption::encrypt( $token );

		$result = Settings::sanitize_access_token( $encrypted );

		// Should be returned as-is, not double-encrypted.
		$this->assertSame( $encrypted, $result );
		$this->assertSame( $token, Encryption::decrypt( $result ) );
	}

	public function test_hmac_secret_reads_its_own_option_when_blank(): void {
		$this->options['iws_hmac_secret'] = Encryption::encrypt( 'hmac-secret' );

		$result = Settings::sanitize_hmac_secret( '' );

		$this->assertSame( 'hmac-secret', Encryption::decrypt( $result ) );
	}

	// ------------------------------------------------------------------
	// sanitize_app_id()
	// ------------------------------------------------------------------

	public function test_sanitize_app_id_strips_special_characters(): void {
		$this->assertSame( 'abc123', Settings::sanitize_app_id( 'abc!@#123' ) );
		$this->assertSame( 'workspaceid', Settings::sanitize_app_id( 'workspace<>id' ) );
	}

	public function test_sanitize_app_id_keeps_alphanumeric_and_dashes(): void {
		$this->assertSame( 'abc-123_xyz', Settings::sanitize_app_id( 'abc-123_xyz' ) );
	}

	public function test_sanitize_app_id_trims_whitespace(): void {
		$this->assertSame( 'abc123', Settings::sanitize_app_id( '   abc123  ' ) );
	}

	public function test_sanitize_app_id_handles_non_string(): void {
		$this->assertSame( '', Settings::sanitize_app_id( null ) );
		$this->assertSame( '', Settings::sanitize_app_id( 12345 ) );
	}

	// ------------------------------------------------------------------
	// sanitize_minutes()
	// ------------------------------------------------------------------

	public function test_sanitize_minutes_clamps_to_floor_of_5(): void {
		$this->assertSame( 5, Settings::sanitize_minutes( 0 ) );
		$this->assertSame( 5, Settings::sanitize_minutes( 1 ) );
		$this->assertSame( 5, Settings::sanitize_minutes( -10 ) );
	}

	public function test_sanitize_minutes_clamps_to_ceiling_of_10080(): void {
		$this->assertSame( 10080, Settings::sanitize_minutes( 99999 ) );
	}

	public function test_sanitize_minutes_passes_through_valid_value(): void {
		$this->assertSame( 60, Settings::sanitize_minutes( 60 ) );
		$this->assertSame( 30, Settings::sanitize_minutes( 30 ) );
	}

	public function test_sanitize_minutes_casts_strings_to_int(): void {
		$this->assertSame( 45, Settings::sanitize_minutes( '45' ) );
		$this->assertSame( 5, Settings::sanitize_minutes( 'not-a-number' ) );
	}
}
