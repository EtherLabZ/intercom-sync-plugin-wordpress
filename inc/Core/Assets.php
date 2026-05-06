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
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'iws_admin_nonce' ),
				'segments' => array(
					'fields'    => self::segment_fields(),
					'operators' => self::segment_operators(),
				),
				'i18n'     => array(
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
					'live'            => __( 'Live', 'intercom-woo-sync' ),
					'paused'          => __( 'Paused', 'intercom-woo-sync' ),
					'ruleName'        => __( 'Rule name', 'intercom-woo-sync' ),
					'ruleTag'         => __( 'Tag to apply', 'intercom-woo-sync' ),
					'ruleMatchAll'    => __( 'Match ALL conditions', 'intercom-woo-sync' ),
					'ruleMatchAny'    => __( 'Match ANY condition', 'intercom-woo-sync' ),
					'ruleEnabled'     => __( 'Enabled', 'intercom-woo-sync' ),
					'addCondition'    => __( '+ Add condition', 'intercom-woo-sync' ),
					'deleteRule'      => __( 'Delete', 'intercom-woo-sync' ),
					'noRules'         => __( 'No rules yet. Click "Add Rule" to create one.', 'intercom-woo-sync' ),
					'segmentsSaved'   => __( 'Segment rules saved.', 'intercom-woo-sync' ),
					'finCancelWarn'   => __( 'WARNING: Enabling this lets Intercom Fin CANCEL real customer orders. Only enable if you trust the AI to use this judiciously. Continue?', 'intercom-woo-sync' ),
					'finRefundWarn'   => __( 'WARNING: Enabling this lets Intercom Fin REFUND real money to customers. Refunds cannot be undone. Continue?', 'intercom-woo-sync' ),
					'finNoteWarn'     => __( 'Enabling this lets Intercom Fin attach notes to customer orders. Notes are visible to all order admins. Continue?', 'intercom-woo-sync' ),
				),
			)
		);
	}

	/**
	 * Field metadata for the segment rule builder.
	 *
	 * @return array<int, array{key:string,label:string,type:string}>
	 */
	private static function segment_fields(): array {
		return array(
			array(
				'key'   => 'total_orders',
				'label' => __( 'Total orders', 'intercom-woo-sync' ),
				'type'  => 'numeric',
			),
			array(
				'key'   => 'lifetime_value',
				'label' => __( 'Lifetime value', 'intercom-woo-sync' ),
				'type'  => 'numeric',
			),
			array(
				'key'   => 'last_order_date',
				'label' => __( 'Last order date', 'intercom-woo-sync' ),
				'type'  => 'date',
			),
			array(
				'key'   => 'last_order_status',
				'label' => __( 'Last order status', 'intercom-woo-sync' ),
				'type'  => 'string',
			),
			array(
				'key'   => 'billing_country',
				'label' => __( 'Billing country', 'intercom-woo-sync' ),
				'type'  => 'string',
			),
			array(
				'key'   => 'billing_city',
				'label' => __( 'Billing city', 'intercom-woo-sync' ),
				'type'  => 'string',
			),
			array(
				'key'   => 'registered_days_ago',
				'label' => __( 'Days since registration', 'intercom-woo-sync' ),
				'type'  => 'numeric',
			),
		);
	}

	/**
	 * Operator labels per value type for the rule builder.
	 *
	 * @return array<string, array<int, array{key:string,label:string}>>
	 */
	private static function segment_operators(): array {
		return array(
			'numeric' => array(
				array(
					'key'   => '=',
					'label' => __( '= (equals)', 'intercom-woo-sync' ),
				),
				array(
					'key'   => '!=',
					'label' => __( '≠ (not equals)', 'intercom-woo-sync' ),
				),
				array(
					'key'   => '>',
					'label' => __( '> (greater than)', 'intercom-woo-sync' ),
				),
				array(
					'key'   => '>=',
					'label' => __( '≥ (at least)', 'intercom-woo-sync' ),
				),
				array(
					'key'   => '<',
					'label' => __( '< (less than)', 'intercom-woo-sync' ),
				),
				array(
					'key'   => '<=',
					'label' => __( '≤ (at most)', 'intercom-woo-sync' ),
				),
			),
			'string'  => array(
				array(
					'key'   => '=',
					'label' => __( 'equals', 'intercom-woo-sync' ),
				),
				array(
					'key'   => '!=',
					'label' => __( 'not equals', 'intercom-woo-sync' ),
				),
				array(
					'key'   => 'contains',
					'label' => __( 'contains', 'intercom-woo-sync' ),
				),
				array(
					'key'   => 'not_contains',
					'label' => __( 'does not contain', 'intercom-woo-sync' ),
				),
			),
			'date'    => array(
				array(
					'key'   => 'within_days',
					'label' => __( 'within last N days', 'intercom-woo-sync' ),
				),
				array(
					'key'   => 'older_than_days',
					'label' => __( 'older than N days', 'intercom-woo-sync' ),
				),
			),
		);
	}
}
