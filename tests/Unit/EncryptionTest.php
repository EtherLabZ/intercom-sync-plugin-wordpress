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
		$this->assertStringStartsWith( 'enc2::', $result );
	}

	public function test_encrypt_output_differs_from_plaintext(): void {
		$plain  = 'my-secret-token';
		$result = Encryption::encrypt( $plain );
		$this->assertNotSame( $plain, $result );
	}

	public function test_encrypt_produces_different_ciphertexts_for_same_input(): void {
		// Random per-value IV → two encryptions of the same plaintext must differ.
		$plain = 'consistent-input';
		$this->assertNotSame( Encryption::encrypt( $plain ), Encryption::encrypt( $plain ) );
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
		// Manually corrupt the ciphertext portion of both formats.
		$this->assertSame( '', Encryption::decrypt( 'enc2::not-valid-base64!!!' ) );
		$this->assertSame( '', Encryption::decrypt( 'enc::not-valid-base64!!!' ) );
	}

	public function test_decrypt_returns_empty_string_for_tampered_ciphertext(): void {
		// GCM authenticates the ciphertext — flipping a byte must fail closed.
		$encrypted = Encryption::encrypt( 'tamper-me' );
		$raw       = base64_decode( substr( $encrypted, strlen( 'enc2::' ) ), true );
		$flipped   = $raw;
		$last      = strlen( $flipped ) - 1;

		$flipped[ $last ] = chr( ord( $flipped[ $last ] ) ^ 0xFF );

		$this->assertSame( '', Encryption::decrypt( 'enc2::' . base64_encode( $flipped ) ) );
	}

	public function test_decrypt_reads_legacy_cbc_format(): void {
		// Reproduce the pre-2.0 storage format: AES-256-CBC, static IV
		// derived from AUTH_SALT, enc:: prefix.
		$plain = 'legacy-stored-token';
		$key   = hash( 'sha256', AUTH_KEY, true );
		$iv    = substr( hash( 'sha256', AUTH_SALT, true ), 0, 16 );

		$legacy = 'enc::' . base64_encode( (string) openssl_encrypt( $plain, 'aes-256-cbc', $key, 0, $iv ) );

		$this->assertSame( $plain, Encryption::decrypt( $legacy ) );
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
		$this->assertTrue( Encryption::is_encrypted( 'enc2::somebase64data' ) );
		// Legacy format still counts as encrypted.
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

	// ------------------------------------------------------------------
	// is_undecryptable()
	// ------------------------------------------------------------------

	public function test_is_undecryptable_false_for_empty_and_plaintext(): void {
		$this->assertFalse( Encryption::is_undecryptable( '' ) );
		$this->assertFalse( Encryption::is_undecryptable( 'plain-token' ) );
	}

	public function test_is_undecryptable_false_for_healthy_ciphertext(): void {
		$this->assertFalse( Encryption::is_undecryptable( Encryption::encrypt( 'ok' ) ) );
	}

	public function test_is_undecryptable_true_for_wrong_key_ciphertext(): void {
		// Simulate a blob written under a different AUTH_KEY: valid enc2::
		// framing (12-byte IV + 16-byte tag + ciphertext) that this site's
		// key cannot authenticate.
		$foreign_key = hash( 'sha256', 'some-other-sites-auth-key', true );
		$iv          = random_bytes( 12 );
		$tag         = '';
		$cipher      = openssl_encrypt( 'secret', 'aes-256-gcm', $foreign_key, OPENSSL_RAW_DATA, $iv, $tag );

		$blob = 'enc2::' . base64_encode( $iv . $tag . $cipher );

		$this->assertTrue( Encryption::is_undecryptable( $blob ) );
	}

	public function test_is_undecryptable_true_for_corrupt_blob(): void {
		$this->assertTrue( Encryption::is_undecryptable( 'enc2::not-valid-base64!!!' ) );
		$this->assertTrue( Encryption::is_undecryptable( 'enc::not-valid-base64!!!' ) );
	}
}
