<?php
/**
 * Singleton trait.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Contracts
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Contracts;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Trait - Singleton
 */
trait Singleton {

	/**
	 * The single instance of this class.
	 *
	 * @var static|null
	 */
	protected static $instance = null;

	/**
	 * Prevent direct instantiation.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Get the singleton instance.
	 *
	 * @return static
	 */
	public static function get_instance(): self {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}

		return static::$instance;
	}
}
