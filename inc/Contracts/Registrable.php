<?php
/**
 * Registrable interface.
 *
 * Any class that hooks into WordPress should implement this contract.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Contracts
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Contracts;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Interface - Registrable
 */
interface Registrable {

	/**
	 * Register WordPress hooks for this module.
	 */
	public function register_hooks(): void;
}
