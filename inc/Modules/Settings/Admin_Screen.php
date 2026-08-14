<?php
/**
 * Registers the admin settings screen.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules\Settings
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules\Settings;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Admin_Screen
 */
final class Admin_Screen implements Registrable {

	/**
	 * The menu page slug.
	 */
	public const SCREEN_ID = 'intercom-woo-sync';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_screen' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( ETHERLABZ_INTERCOM_FILE ),
			array( $this, 'add_action_links' )
		);
	}

	/**
	 * Add a "Settings" link on the Plugins page.
	 *
	 * @param string[] $links Existing action links.
	 *
	 * @return string[]
	 */
	public function add_action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SCREEN_ID ) ),
				__( 'Settings', 'etherlabz-intercom-sync' )
			)
		);

		return $links;
	}

	/**
	 * Register the top-level admin menu page.
	 */
	public function register_screen(): void {
		add_menu_page(
			__( 'Intercom Sync', 'etherlabz-intercom-sync' ),
			__( 'Intercom Sync', 'etherlabz-intercom-sync' ),
			'manage_options',
			self::SCREEN_ID,
			array( $this, 'render_screen' ),
			'dashicons-share',
			58
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require_once ETHERLABZ_INTERCOM_PATH . 'templates/admin-screen.php';
	}
}
