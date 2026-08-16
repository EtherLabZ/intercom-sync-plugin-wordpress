<?php
/**
 * Encryption utility for secure storage of sensitive values.
 *
 * Current format (enc2::): AES-256-GCM with a random per-value IV and
 * authentication tag, keyed off WordPress AUTH_KEY. Legacy values
 * (enc:: prefix, AES-256-CBC with a static IV) remain readable and are
 * re-encrypted in the new format the next time they are saved.
 * Falls back gracefully if OpenSSL is unavailable.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Core
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Core;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Encryption
 */
final class Encryption {

	/**
	 * Cipher method for newly encrypted values.
	 */
	private const METHOD = 'aes-256-gcm';

	/**
	 * Prefix added to encrypted values so we can detect them.
	 */
	private const PREFIX = 'enc2::';

	/**
	 * IV length (bytes) for AES-256-GCM.
	 */
	private const IV_LENGTH = 12;

	/**
	 * Authentication tag length (bytes) for AES-256-GCM.
	 */
	private const TAG_LENGTH = 16;

	/**
	 * Legacy cipher method (values stored before the enc2:: format).
	 */
	private const LEGACY_METHOD = 'aes-256-cbc';

	/**
	 * Legacy prefix — AES-256-CBC with a static IV derived from AUTH_SALT.
	 */
	private const LEGACY_PREFIX = 'enc::';

	/**
	 * Encrypt a plaintext value.
	 *
	 * @param string $value The plaintext value.
	 *
	 * @return string The encrypted, base64-encoded value (prefixed with enc2::).
	 */
	public static function encrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( ! self::can_encrypt() ) {
			return $value;
		}

		$key = self::get_key();

		try {
			$iv = random_bytes( self::IV_LENGTH );
		} catch ( \Exception $e ) {
			return $value;
		}

		$tag       = '';
		$encrypted = openssl_encrypt( $value, self::METHOD, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH );

		if ( false === $encrypted || self::TAG_LENGTH !== strlen( $tag ) ) {
			return $value;
		}

		// Encoding ciphertext for safe storage in wp_options, not obfuscation.
		return self::PREFIX . base64_encode( $iv . $tag . $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a previously encrypted value.
	 *
	 * Handles both the current enc2:: (AES-256-GCM) and the legacy
	 * enc:: (AES-256-CBC, static IV) formats.
	 *
	 * @param string $value The encrypted value (or plaintext if not encrypted).
	 *
	 * @return string The decrypted plaintext.
	 */
	public static function decrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( str_starts_with( $value, self::PREFIX ) ) {
			return self::decrypt_current( $value );
		}

		if ( str_starts_with( $value, self::LEGACY_PREFIX ) ) {
			return self::decrypt_legacy( $value );
		}

		// No prefix: stored in plain text (pre-encryption migration).
		return $value;
	}

	/**
	 * Check whether the value is already encrypted (either format).
	 *
	 * @param string $value The stored value.
	 */
	public static function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX )
			|| str_starts_with( $value, self::LEGACY_PREFIX );
	}

	/**
	 * Whether a stored value is an encrypted blob that can no longer be
	 * decrypted — the classic symptom of AUTH_KEY having changed since the
	 * value was saved (host migration, salt rotation, WordPress Playground
	 * regenerating keys per boot).
	 *
	 * @param string $value The stored value.
	 */
	public static function is_undecryptable( string $value ): bool {
		return '' !== $value
			&& self::is_encrypted( $value )
			&& '' === self::decrypt( $value );
	}

	/**
	 * Decrypt a value in the current enc2:: (AES-256-GCM) format.
	 *
	 * @param string $value The prefixed, base64-encoded value.
	 */
	private static function decrypt_current( string $value ): string {
		if ( ! self::can_encrypt() ) {
			return '';
		}

		// Decoding ciphertext stored by self::encrypt(); not obfuscation.
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw || strlen( $raw ) <= self::IV_LENGTH + self::TAG_LENGTH ) {
			return '';
		}

		$iv         = substr( $raw, 0, self::IV_LENGTH );
		$tag        = substr( $raw, self::IV_LENGTH, self::TAG_LENGTH );
		$ciphertext = substr( $raw, self::IV_LENGTH + self::TAG_LENGTH );

		$decrypted = openssl_decrypt( $ciphertext, self::METHOD, self::get_key(), OPENSSL_RAW_DATA, $iv, $tag );

		return false !== $decrypted ? $decrypted : '';
	}

	/**
	 * Decrypt a value in the legacy enc:: (AES-256-CBC, static IV) format.
	 *
	 * Kept only so values stored by older plugin versions stay readable;
	 * they are re-encrypted with the current format on next save.
	 *
	 * @param string $value The prefixed, base64-encoded value.
	 */
	private static function decrypt_legacy( string $value ): string {
		if ( ! function_exists( 'openssl_decrypt' )
			|| ! in_array( self::LEGACY_METHOD, openssl_get_cipher_methods(), true ) ) {
			return '';
		}

		// Decoding ciphertext stored by older plugin versions; not obfuscation.
		$raw = base64_decode( substr( $value, strlen( self::LEGACY_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw ) {
			return '';
		}

		// The legacy format used these exact fallback strings when the WP
		// security keys were undefined — retained verbatim for decryption only.
		$key_source  = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : 'iws-default-key';
		$salt_source = defined( 'AUTH_SALT' ) && AUTH_SALT ? AUTH_SALT : 'iws-default-salt';

		$key = hash( 'sha256', $key_source, true );
		$iv  = substr( hash( 'sha256', $salt_source, true ), 0, 16 );

		$decrypted = openssl_decrypt( $raw, self::LEGACY_METHOD, $key, 0, $iv );

		return false !== $decrypted ? $decrypted : '';
	}

	/**
	 * Whether we can encrypt: OpenSSL with GCM support plus a real AUTH_KEY.
	 *
	 * Without AUTH_KEY there is no secret worth deriving a key from — a
	 * hardcoded fallback key would only pretend to protect the value — so
	 * the value is stored as-is instead.
	 */
	private static function can_encrypt(): bool {
		return defined( 'AUTH_KEY' )
			&& AUTH_KEY
			&& function_exists( 'openssl_encrypt' )
			&& in_array( self::METHOD, openssl_get_cipher_methods(), true );
	}

	/**
	 * Derive the encryption key from WordPress AUTH_KEY.
	 */
	private static function get_key(): string {
		return hash( 'sha256', AUTH_KEY, true );
	}
}
