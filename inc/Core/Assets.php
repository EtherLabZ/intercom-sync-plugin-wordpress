<?php
/**
 * Enqueue admin assets.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Core
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Core;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Assets
 */
final class Assets implements Registrable {

	/**
	 * Handle prefix.
	 */
	private const PREFIX = 'intercom-woo-sync-';

	/**
	 * Admin asset handles.
	 */
	public const ADMIN_CSS = self::PREFIX . 'admin-css';
	public const ADMIN_JS  = self::PREFIX . 'admin-js';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueue admin styles and scripts on our settings page only.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_intercom-woo-sync' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			self::ADMIN_CSS,
			INTERCOM_WOO_SYNC_URL . 'assets/css/admin.css',
			array(),
			INTERCOM_WOO_SYNC_VERSION
		);

		wp_enqueue_script(
			self::ADMIN_JS,
			INTERCOM_WOO_SYNC_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			INTERCOM_WOO_SYNC_VERSION,
			true
		);

		wp_localize_script(
			self::ADMIN_JS,
			'iwsAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'iws_admin_nonce' ),
				'i18n'    => array(
					'requestFailed'   => __( 'Request failed. Please try again.', 'intercom-woo-sync' ),
					'running'         => __( 'Running…', 'intercom-woo-sync' ),
					'idle'            => __( 'Idle', 'intercom-woo-sync' ),
					'bulkComplete'    => __( 'Bulk sync complete.', 'intercom-woo-sync' ),
					'customersCount'  => __( 'customers processed so far', 'intercom-woo-sync' ),
					'clearLogConfirm' => __( 'Clear the entire sync log?', 'intercom-woo-sync' ),
					'noLogEntries'    => __( 'No log entries yet.', 'intercom-woo-sync' ),
					'logColTime'      => __( 'Time', 'intercom-woo-sync' ),
					'logColStatus'    => __( 'Status', 'intercom-woo-sync' ),
					'logColAction'    => __( 'Action', 'intercom-woo-sync' ),
					'logColMessage'   => __( 'Message', 'intercom-woo-sync' ),
					'badgeOk'         => __( 'OK', 'intercom-woo-sync' ),
					'badgeError'      => __( 'Error', 'intercom-woo-sync' ),
					'keyCopied'       => __( 'API key copied to clipboard.', 'intercom-woo-sync' ),
					'keyGenerated'    => __( 'API key generated successfully.', 'intercom-woo-sync' ),
				),
			)
		);
	}
}
