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
			ETHERLABZ_INTERCOM_URL . 'assets/css/admin.css',
			array(),
			ETHERLABZ_INTERCOM_VERSION
		);

		wp_enqueue_script(
			self::ADMIN_JS,
			ETHERLABZ_INTERCOM_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			ETHERLABZ_INTERCOM_VERSION,
			true
		);

		wp_localize_script(
			self::ADMIN_JS,
			'etherlabzIntercomAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'etherlabz_intercom_admin_nonce' ),
				'segments' => array(
					'fields'    => self::segment_fields(),
					'operators' => self::segment_operators(),
				),
				'i18n'     => array(
					'requestFailed'     => __( 'Request failed. Please try again.', 'etherlabz-intercom-sync' ),
					'running'           => __( 'Running…', 'etherlabz-intercom-sync' ),
					'idle'              => __( 'Idle', 'etherlabz-intercom-sync' ),
					'bulkComplete'      => __( 'Bulk sync complete.', 'etherlabz-intercom-sync' ),
					'customersCount'    => __( 'customers processed so far', 'etherlabz-intercom-sync' ),
					'clearLogConfirm'   => __( 'Clear the entire sync log?', 'etherlabz-intercom-sync' ),
					'noLogEntries'      => __( 'No log entries yet.', 'etherlabz-intercom-sync' ),
					'logColTime'        => __( 'Time', 'etherlabz-intercom-sync' ),
					'logColStatus'      => __( 'Status', 'etherlabz-intercom-sync' ),
					'logColAction'      => __( 'Action', 'etherlabz-intercom-sync' ),
					'logColMessage'     => __( 'Message', 'etherlabz-intercom-sync' ),
					'badgeOk'           => __( 'OK', 'etherlabz-intercom-sync' ),
					'badgeError'        => __( 'Error', 'etherlabz-intercom-sync' ),
					'keyCopied'         => __( 'API key copied to clipboard.', 'etherlabz-intercom-sync' ),
					'keyGenerated'      => __( 'API key generated successfully.', 'etherlabz-intercom-sync' ),
					'regenKeyConfirm'   => __( 'This will invalidate the current key. Continue?', 'etherlabz-intercom-sync' ),
					'removeSecretConfirm' => __( 'Remove this saved value? Related features stop working until a new one is saved.', 'etherlabz-intercom-sync' ),
					'live'              => __( 'Live', 'etherlabz-intercom-sync' ),
					'paused'            => __( 'Paused', 'etherlabz-intercom-sync' ),
					'ruleName'          => __( 'Rule name', 'etherlabz-intercom-sync' ),
					'ruleTag'           => __( 'Tag to apply', 'etherlabz-intercom-sync' ),
					'ruleMatchAll'      => __( 'Match ALL conditions', 'etherlabz-intercom-sync' ),
					'ruleMatchAny'      => __( 'Match ANY condition', 'etherlabz-intercom-sync' ),
					'ruleEnabled'       => __( 'Enabled', 'etherlabz-intercom-sync' ),
					'addCondition'      => __( '+ Add condition', 'etherlabz-intercom-sync' ),
					'removeCondition'   => __( 'Remove', 'etherlabz-intercom-sync' ),
					'deleteRule'        => __( 'Delete', 'etherlabz-intercom-sync' ),
					'deleteRuleConfirm' => __( 'Delete this rule?', 'etherlabz-intercom-sync' ),
					'noRules'           => __( 'No rules yet. Click "Add Rule" to create one.', 'etherlabz-intercom-sync' ),
					'segmentsSaved'     => __( 'Segment rules saved.', 'etherlabz-intercom-sync' ),
					'finCancelWarn'     => __( 'WARNING: Enabling this lets Intercom Fin CANCEL real customer orders. Only enable if you trust the AI to use this judiciously. Continue?', 'etherlabz-intercom-sync' ),
					'finRefundWarn'     => __( 'WARNING: Enabling this lets Intercom Fin REFUND real money to customers. Refunds cannot be undone. Continue?', 'etherlabz-intercom-sync' ),
					'finNoteWarn'       => __( 'Enabling this lets Intercom Fin attach notes to customer orders. Notes are visible to all order admins. Continue?', 'etherlabz-intercom-sync' ),
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
				'label' => __( 'Total orders', 'etherlabz-intercom-sync' ),
				'type'  => 'numeric',
			),
			array(
				'key'   => 'lifetime_value',
				'label' => __( 'Lifetime value', 'etherlabz-intercom-sync' ),
				'type'  => 'numeric',
			),
			array(
				'key'   => 'last_order_date',
				'label' => __( 'Last order date', 'etherlabz-intercom-sync' ),
				'type'  => 'date',
			),
			array(
				'key'   => 'last_order_status',
				'label' => __( 'Last order status', 'etherlabz-intercom-sync' ),
				'type'  => 'string',
			),
			array(
				'key'   => 'billing_country',
				'label' => __( 'Billing country', 'etherlabz-intercom-sync' ),
				'type'  => 'string',
			),
			array(
				'key'   => 'billing_city',
				'label' => __( 'Billing city', 'etherlabz-intercom-sync' ),
				'type'  => 'string',
			),
			array(
				'key'   => 'registered_days_ago',
				'label' => __( 'Days since registration', 'etherlabz-intercom-sync' ),
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
					'label' => __( '= (equals)', 'etherlabz-intercom-sync' ),
				),
				array(
					'key'   => '!=',
					'label' => __( '≠ (not equals)', 'etherlabz-intercom-sync' ),
				),
				array(
					'key'   => '>',
					'label' => __( '> (greater than)', 'etherlabz-intercom-sync' ),
				),
				array(
					'key'   => '>=',
					'label' => __( '≥ (at least)', 'etherlabz-intercom-sync' ),
				),
				array(
					'key'   => '<',
					'label' => __( '< (less than)', 'etherlabz-intercom-sync' ),
				),
				array(
					'key'   => '<=',
					'label' => __( '≤ (at most)', 'etherlabz-intercom-sync' ),
				),
			),
			'string'  => array(
				array(
					'key'   => '=',
					'label' => __( 'equals', 'etherlabz-intercom-sync' ),
				),
				array(
					'key'   => '!=',
					'label' => __( 'not equals', 'etherlabz-intercom-sync' ),
				),
				array(
					'key'   => 'contains',
					'label' => __( 'contains', 'etherlabz-intercom-sync' ),
				),
				array(
					'key'   => 'not_contains',
					'label' => __( 'does not contain', 'etherlabz-intercom-sync' ),
				),
			),
			'date'    => array(
				array(
					'key'   => 'within_days',
					'label' => __( 'within last N days', 'etherlabz-intercom-sync' ),
				),
				array(
					'key'   => 'older_than_days',
					'label' => __( 'older than N days', 'etherlabz-intercom-sync' ),
				),
			),
		);
	}
}
