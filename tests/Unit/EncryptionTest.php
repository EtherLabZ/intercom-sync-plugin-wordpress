<?php
/**
 * Tests for the Encryption utility class.
 *
 * These tests are fully self-contained — no WordPress functions are called
 * so Brain\Monkey is not needed here.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests\Unit
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Tests\Unit;

use Etherlabz\Intercom_Woo_Sync\Core\Encryption;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Etherlabz\Intercom_Woo_Sync\Core\Encryption
 */
class EncryptionTest extends TestCase {

	// ------------------------------------------------------------------
	// encrypt()
	// ------------------------------------------------------------------

	public function test_encrypt_returns_empty_string_for_empty_input(): void {
		$this->assertSame( '', Encryption::encrypt( '' ) );
	}

	public function test_encrypt_returns_prefixed_string(): void {
		$result = Encryption::encrypt( 'my-secret-token' );
		$this->assertStringStartsWith( 'enc::', $result );
	}

	public function test_encrypt_output_differs_from_plaintext(): void {
		$plain  = 'my-secret-token';
		$result = Encryption::encrypt( $plain );
		$this->assertNotSame( $plain, $result );
	}

	public function test_encrypt_produces_consistent_results_for_same_input(): void {
		// Same key/IV → same ciphertext (AES-256-CBC is deterministic for fixed key+IV).
		$plain = 'consistent-input';
		$this->assertSame( Encryption::encrypt( $plain ), Encryption::encrypt( $plain ) );
	}

	// ------------------------------------------------------------------
	// decrypt()
	// ------------------------------------------------------------------

	public function test_decrypt_returns_empty_string_for_empty_input(): void {
		$this->assertSame( '', Encryption::decrypt( '' ) );
	}

	public function test_decrypt_returns_plaintext_passthrough_when_not_encrypted(): void {
		// Plain-text values (no enc:: prefix) are returned as-is for backward compat.
		$plain = 'plain-legacy-token';
		$this->assertSame( $plain, Encryption::decrypt( $plain ) );
	}

	public function test_encrypt_then_decrypt_roundtrip(): void {
		$original  = 'super-secret-intercom-token-123!@#';
		$encrypted = Encryption::encrypt( $original );
		$decrypted = Encryption::decrypt( $encrypted );

		$this->assertSame( $original, $decrypted );
	}

	public function test_decrypt_returns_empty_string_for_corrupted_ciphertext(): void {
		// Manually corrupt the ciphertext portion.
		$corrupted = 'enc::not-valid-base64!!!';
		$result    = Encryption::decrypt( $corrupted );
		// Should return '' (failed decode) rather than throwing.
		$this->assertSame( '', $result );
	}

	public function test_roundtrip_preserves_special_characters(): void {
		$original  = "Token with spaces, symbols: !@#$%^&*()_+-=[]{}|;':\",./<>?";
		$encrypted = Encryption::encrypt( $original );
		$decrypted = Encryption::decrypt( $encrypted );

		$this->assertSame( $original, $decrypted );
	}

	// ------------------------------------------------------------------
	// is_encrypted()
	// ------------------------------------------------------------------

	public function test_is_encrypted_returns_true_for_enc_prefixed_value(): void {
		$this->assertTrue( Encryption::is_encrypted( 'enc::somebase64data' ) );
	}

	public function test_is_encrypted_returns_false_for_plain_text(): void {
		$this->assertFalse( Encryption::is_encrypted( 'plain-token' ) );
	}

	public function test_is_encrypted_returns_false_for_empty_string(): void {
		$this->assertFalse( Encryption::is_encrypted( '' ) );
	}

	public function test_is_encrypted_returns_true_for_real_encrypted_value(): void {
		$encrypted = Encryption::encrypt( 'real-value' );
		$this->assertTrue( Encryption::is_encrypted( $encrypted ) );
	}

	public function test_is_encrypted_returns_false_for_plain_value(): void {
		$this->assertFalse( Encryption::is_encrypted( 'not-encrypted' ) );
	}
}
