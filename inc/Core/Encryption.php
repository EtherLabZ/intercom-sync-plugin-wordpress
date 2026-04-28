<?php
/**
 * Encryption utility for secure storage of sensitive values.
 *
 * Uses AES-256-CBC with WordPress AUTH_KEY / AUTH_SALT as the
 * key and IV source. Falls back gracefully if OpenSSL is unavailable.
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
	 * Cipher method.
	 */
	private const METHOD = 'aes-256-cbc';

	/**
	 * Prefix added to encrypted values so we can detect them.
	 */
	private const PREFIX = 'enc::';

	/**
	 * Encrypt a plaintext value.
	 *
	 * @param string $value The plaintext value.
	 *
	 * @return string The encrypted, base64-encoded value (prefixed with enc::).
	 */
	public static function encrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( ! self::can_encrypt() ) {
			return $value;
		}

		$key = self::get_key();
		$iv  = self::get_iv();

		$encrypted = openssl_encrypt( $value, self::METHOD, $key, 0, $iv );

		if ( false === $encrypted ) {
			return $value;
		}

		return self::PREFIX . base64_encode( $encrypted );
	}

	/**
	 * Decrypt a previously encrypted value.
	 *
	 * @param string $value The encrypted value (or plaintext if not encrypted).
	 *
	 * @return string The decrypted plaintext.
	 */
	public static function decrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		// If it doesn't have our prefix, it's stored in plain text (pre-encryption migration).
		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			return $value;
		}

		if ( ! self::can_encrypt() ) {
			return $value;
		}

		$key     = self::get_key();
		$iv      = self::get_iv();
		$raw     = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );

		if ( false === $raw ) {
			return '';
		}

		$decrypted = openssl_decrypt( $raw, self::METHOD, $key, 0, $iv );

		return false !== $decrypted ? $decrypted : '';
	}

	/**
	 * Check whether the value is already encrypted.
	 *
	 * @param string $value The stored value.
	 */
	public static function is_encrypted( string $value ): bool {
		return 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * Whether we can use OpenSSL encryption.
	 */
	private static function can_encrypt(): bool {
		return function_exists( 'openssl_encrypt' )
			&& in_array( self::METHOD, openssl_get_cipher_methods(), true );
	}

	/**
	 * Derive the encryption key from WordPress AUTH_KEY.
	 */
	private static function get_key(): string {
		$source = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : 'iws-default-key';
		return hash( 'sha256', $source, true );
	}

	/**
	 * Derive the IV from WordPress AUTH_SALT (16 bytes for AES-256-CBC).
	 */
	private static function get_iv(): string {
		$source = defined( 'AUTH_SALT' ) && AUTH_SALT ? AUTH_SALT : 'iws-default-salt';
		return substr( hash( 'sha256', $source, true ), 0, 16 );
	}
}
