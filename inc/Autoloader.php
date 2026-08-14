<?php
/**
 * PSR-4 Autoloader for the plugin — no Composer required.
 *
 * Maps the Etherlabz\Intercom_Woo_Sync namespace to the inc/ directory.
 *
 * @package Etherlabz\Intercom_Woo_Sync
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Autoloader
 */
final class Autoloader {

	/**
	 * Namespace prefix for this plugin.
	 */
	private const PREFIX = 'Etherlabz\\Intercom_Woo_Sync\\';

	/**
	 * Register the autoloader with the SPL autoload stack.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Autoload a class by its fully-qualified name.
	 *
	 * @param string $class_name The fully-qualified class name.
	 */
	public static function autoload( string $class_name ): void {
		// Bail if the class doesn't belong to our namespace.
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		// Strip the namespace prefix.
		$relative = substr( $class_name, strlen( self::PREFIX ) );

		// Convert namespace separators to directory separators.
		$file = ETHERLABZ_INTERCOM_PATH . 'inc/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
