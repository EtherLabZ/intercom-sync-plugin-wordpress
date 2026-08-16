<?php
/**
 * Registers the admin settings screen.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules\Settings
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules\Settings;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;
use Etherlabz\Intercom_Woo_Sync\Core\Encryption;

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
		add_action( 'admin_notices', array( $this, 'maybe_warn_undecryptable_secrets' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( ETHERLABZ_INTERCOM_FILE ),
			array( $this, 'add_action_links' )
		);
	}

	/**
	 * Warn admins when a stored secret can no longer be decrypted.
	 *
	 * This happens when the site's AUTH_KEY changes after a secret was saved
	 * (host migration, salt rotation, WordPress Playground regenerating keys
	 * on boot). Without the warning the plugin silently behaves as if no
	 * token were configured while the settings screen shows one is stored.
	 */
	public function maybe_warn_undecryptable_secrets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$secrets = array(
			'etherlabz_intercom_access_token' => __( 'Intercom Access Token', 'etherlabz-intercom-sync' ),
			'etherlabz_intercom_hmac_secret'  => __( 'Identity Verification Secret', 'etherlabz-intercom-sync' ),
			'etherlabz_intercom_fin_api_key'  => __( 'Fin API Key', 'etherlabz-intercom-sync' ),
		);

		$broken = array();
		foreach ( $secrets as $option => $label ) {
			if ( Encryption::is_undecryptable( (string) get_option( $option, '' ) ) ) {
				$broken[] = $label;
			}
		}

		if ( empty( $broken ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><a href="%s">%s</a></p></div>',
			esc_html__( 'Intercom Sync: stored credentials can no longer be decrypted.', 'etherlabz-intercom-sync' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of affected setting names. */
					__( 'Your site\'s security keys (AUTH_KEY) changed since these were saved, so syncing has stopped: %s. Re-enter them to resume.', 'etherlabz-intercom-sync' ),
					implode( ', ', $broken )
				)
			),
			esc_url( admin_url( 'admin.php?page=' . self::SCREEN_ID ) ),
			esc_html__( 'Open Intercom Sync settings', 'etherlabz-intercom-sync' )
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
