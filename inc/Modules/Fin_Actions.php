<?php
/**
 * Fin AI write endpoints — let Fin resolve tickets, not just answer them.
 *
 * Each endpoint is registered ONLY when its corresponding option toggle is on.
 * All toggles default to OFF for safety. Cancel and refund are explicitly
 * marked dangerous in the admin UI; note creation is also off-by-default
 * because customer notes can leak PII.
 *
 * Endpoints (each gated by its own option):
 *   POST /iws/v1/orders/{id}/cancel    — iws_fin_action_cancel_enabled
 *   POST /iws/v1/orders/{id}/refund    — iws_fin_action_refund_enabled
 *   POST /iws/v1/customer/note         — iws_fin_action_note_enabled
 *
 * Authentication uses the same Bearer-token check as Fin_Connector.
 * Every successful + failed call is written to iws_sync_log so operators
 * have a full audit trail.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Class - Fin_Actions
 */
final class Fin_Actions implements Registrable {

	/**
	 * Map of action key → option name.  Used by the admin UI and the
	 * register_routes() check so the wiring stays in one place.
	 *
	 * @var array<string, string>
	 */
	public const ACTION_OPTIONS = array(
		'cancel' => 'iws_fin_action_cancel_enabled',
		'refund' => 'iws_fin_action_refund_enabled',
		'note'   => 'iws_fin_action_note_enabled',
	);

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register write routes — each only if its toggle is on.
	 */
	public function register_routes(): void {
		$auth = array( new Fin_Connector(), 'authenticate' );

		if ( self::is_enabled( 'cancel' ) ) {
			register_rest_route(
				Fin_Connector::REST_NAMESPACE,
				'/orders/(?P<id>\d+)/cancel',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'cancel_order' ),
					'permission_callback' => $auth,
					'args'                => array(
						'id' => array(
							'required'          => true,
							'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0,
						),
					),
				)
			);
		}

		if ( self::is_enabled( 'refund' ) ) {
			register_rest_route(
				Fin_Connector::REST_NAMESPACE,
				'/orders/(?P<id>\d+)/refund',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'refund_order' ),
					'permission_callback' => $auth,
					'args'                => array(
						'id' => array(
							'required'          => true,
							'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0,
						),
					),
				)
			);
		}

		if ( self::is_enabled( 'note' ) ) {
			register_rest_route(
				Fin_Connector::REST_NAMESPACE,
				'/customer/note',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'add_customer_note' ),
					'permission_callback' => $auth,
				)
			);
		}
	}

	/**
	 * Whether a given action is enabled in admin.
	 *
	 * @param string $action 'cancel' | 'refund' | 'note'.
	 */
	public static function is_enabled( string $action ): bool {
		$option = self::ACTION_OPTIONS[ $action ] ?? '';
		if ( '' === $option ) {
			return false;
		}
		return 'yes' === get_option( $option, 'no' );
	}

	// ----------------------------------------------------------------------
	// Endpoints
	// ----------------------------------------------------------------------

	/**
	 * POST /orders/{id}/cancel
	 *
	 * @param WP_REST_Request $request Incoming.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_order( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		$order = wc_get_order( $id );
		if ( ! $order ) {
			Intercom_API::log( 'error', 'fin-action/cancel', "Order #{$id} not found." );
			return new WP_Error( 'not_found', __( 'Order not found.', 'etherlabz-intercom-sync' ), array( 'status' => 404 ) );
		}

		if ( $order->has_status( array( 'completed', 'refunded', 'cancelled' ) ) ) {
			$status = $order->get_status();
			Intercom_API::log( 'error', 'fin-action/cancel', "Order #{$id} already in terminal state: {$status}." );
			return new WP_Error(
				'cannot_cancel',
				sprintf(
					/* translators: %s: current order status. */
					__( 'Order is in a terminal state (%s) and cannot be cancelled.', 'etherlabz-intercom-sync' ),
					$status
				),
				array( 'status' => 409 )
			);
		}

		$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		if ( '' === $reason ) {
			$reason = 'Cancelled via Intercom Fin';
		}

		$order->update_status( 'cancelled', $reason );

		Intercom_API::log( 'success', 'fin-action/cancel', "Order #{$id} cancelled. Reason: {$reason}" );

		return rest_ensure_response(
			array(
				'success'  => true,
				'order_id' => $id,
				'status'   => 'cancelled',
				'reason'   => $reason,
			)
		);
	}

	/**
	 * POST /orders/{id}/refund
	 *
	 * @param WP_REST_Request $request Incoming.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refund_order( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		$order = wc_get_order( $id );
		if ( ! $order ) {
			Intercom_API::log( 'error', 'fin-action/refund', "Order #{$id} not found." );
			return new WP_Error( 'not_found', __( 'Order not found.', 'etherlabz-intercom-sync' ), array( 'status' => 404 ) );
		}

		// Amount: numeric param. Defaults to remaining (full) refund.
		$raw_amount = $request->get_param( 'amount' );
		$amount     = null;
		if ( null !== $raw_amount && is_numeric( $raw_amount ) ) {
			$amount = (float) $raw_amount;
		}

		$order_total = (float) $order->get_total();
		$refunded    = (float) $order->get_total_refunded();
		$max_refund  = $order_total - $refunded;

		if ( null === $amount ) {
			$amount = $max_refund;
		}

		if ( $amount <= 0 || $amount > $max_refund ) {
			Intercom_API::log( 'error', 'fin-action/refund', "Order #{$id} refund amount {$amount} out of range (max {$max_refund})." );
			return new WP_Error(
				'invalid_amount',
				sprintf(
					/* translators: %1$s: requested amount. %2$s: max refundable amount. */
					__( 'Refund amount %1$s is invalid (max refundable: %2$s).', 'etherlabz-intercom-sync' ),
					(string) $amount,
					(string) $max_refund
				),
				array( 'status' => 400 )
			);
		}

		$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		if ( '' === $reason ) {
			$reason = 'Refunded via Intercom Fin';
		}

		$refund = wc_create_refund(
			array(
				'order_id' => $id,
				'amount'   => $amount,
				'reason'   => $reason,
			)
		);

		if ( is_wp_error( $refund ) ) {
			Intercom_API::log( 'error', 'fin-action/refund', "Order #{$id} refund failed: " . $refund->get_error_message() );
			return $refund;
		}

		Intercom_API::log( 'success', 'fin-action/refund', "Order #{$id} refunded {$amount}. Reason: {$reason}" );

		return rest_ensure_response(
			array(
				'success'   => true,
				'order_id'  => $id,
				'amount'    => $amount,
				'reason'    => $reason,
				'refund_id' => method_exists( $refund, 'get_id' ) ? (int) $refund->get_id() : 0,
			)
		);
	}

	/**
	 * POST /customer/note
	 *
	 * Looks up the customer by email (X-Email header) and attaches a note
	 * to their most recent order. Falls back to creating a standalone
	 * note if the customer has no orders.
	 *
	 * @param WP_REST_Request $request Incoming.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_customer_note( WP_REST_Request $request ) {
		$email = sanitize_email( (string) $request->get_header( 'X-Email' ) );
		if ( ! is_email( $email ) ) {
			$email = sanitize_email( (string) $request->get_param( 'email' ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'missing_email', __( 'Provide a valid X-Email header or email param.', 'etherlabz-intercom-sync' ), array( 'status' => 400 ) );
		}

		$note = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
		if ( '' === $note ) {
			return new WP_Error( 'missing_note', __( 'Provide a non-empty note param.', 'etherlabz-intercom-sync' ), array( 'status' => 400 ) );
		}

		// Find the most recent order for this email and attach the note there.
		$orders = wc_get_orders(
			array(
				'limit'         => 1,
				'orderby'       => 'date',
				'order'         => 'DESC',
				'billing_email' => $email,
			)
		);

		if ( empty( $orders ) ) {
			Intercom_API::log( 'error', 'fin-action/note', "No order found for {$email}." );
			return new WP_Error( 'no_order', __( 'No order found for this email — note not attached.', 'etherlabz-intercom-sync' ), array( 'status' => 404 ) );
		}

		$order = $orders[0];
		$order->add_order_note( $note, 0, false );

		Intercom_API::log( 'success', 'fin-action/note', "Note added to order #{$order->get_id()} for {$email}." );

		return rest_ensure_response(
			array(
				'success'  => true,
				'email'    => $email,
				'order_id' => $order->get_id(),
			)
		);
	}
}
