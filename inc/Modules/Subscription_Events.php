<?php
/**
 * WooCommerce Subscriptions lifecycle events.
 *
 * Sends Intercom events for subscription activations, renewals, cancellations,
 * payment failures, and switches. No-op when WooCommerce Subscriptions is not
 * active.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Subscription_Events
 */
final class Subscription_Events implements Registrable {

	/**
	 * Map subscription status transitions → Intercom event names.
	 *
	 * @var array<string, string>
	 */
	private const EVENT_MAP = array(
		'active'         => 'subscription-activated',
		'on-hold'        => 'subscription-on-hold',
		'cancelled'      => 'subscription-cancelled',
		'expired'        => 'subscription-expired',
		'pending-cancel' => 'subscription-pending-cancel',
	);

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		if ( 'yes' !== get_option( 'etherlabz_intercom_enable_subscriptions', 'no' ) ) {
			return;
		}

		// Subscription status changes (covers all transitions including activated).
		add_action( 'woocommerce_subscription_status_changed', array( $this, 'on_status_changed' ), 10, 3 );

		// Renewal payment completed.
		add_action( 'woocommerce_subscription_renewal_payment_complete', array( $this, 'on_renewal_complete' ), 10, 1 );

		// Renewal payment failed.
		add_action( 'woocommerce_subscription_renewal_payment_failed', array( $this, 'on_renewal_failed' ), 10, 1 );
	}

	/**
	 * Fire event on subscription status change.
	 *
	 * @param mixed  $subscription WC_Subscription instance.
	 * @param string $old_status   Previous status slug.
	 * @param string $new_status   New status slug.
	 */
	public function on_status_changed( $subscription, $old_status, $new_status ): void {
		$event_name = self::EVENT_MAP[ (string) $new_status ] ?? 'subscription-status-changed';
		$this->fire_subscription_event( $subscription, $event_name, array( 'from_status' => (string) $old_status ) );
	}

	/**
	 * Fire event on successful renewal.
	 *
	 * @param mixed $subscription WC_Subscription instance.
	 */
	public function on_renewal_complete( $subscription ): void {
		$this->fire_subscription_event( $subscription, 'subscription-renewed', array() );
	}

	/**
	 * Fire event on failed renewal.
	 *
	 * @param mixed $subscription WC_Subscription instance.
	 */
	public function on_renewal_failed( $subscription ): void {
		$this->fire_subscription_event( $subscription, 'subscription-payment-failed', array() );
	}

	/**
	 * Build metadata and dispatch the event.
	 *
	 * @param mixed                $subscription WC_Subscription instance.
	 * @param string               $event_name   Intercom event name.
	 * @param array<string, mixed> $extra        Extra metadata to merge.
	 */
	private function fire_subscription_event( $subscription, string $event_name, array $extra ): void {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_billing_email' ) ) {
			return;
		}

		$email = (string) $subscription->get_billing_email();

		if ( '' === $email ) {
			return;
		}

		$api = new Intercom_API();

		if ( ! $api->has_token() ) {
			return;
		}

		$plan_name       = '';
		$next_payment    = 0;
		$total           = 0.0;
		$subscription_id = 0;
		$currency        = '';

		if ( method_exists( $subscription, 'get_id' ) ) {
			$subscription_id = (int) $subscription->get_id();
		}
		if ( method_exists( $subscription, 'get_total' ) ) {
			$total = (float) $subscription->get_total();
		}
		if ( method_exists( $subscription, 'get_currency' ) ) {
			$currency = (string) $subscription->get_currency();
		}
		if ( method_exists( $subscription, 'get_items' ) ) {
			$names = array();
			foreach ( $subscription->get_items() as $item ) {
				if ( is_object( $item ) && method_exists( $item, 'get_name' ) ) {
					$names[] = (string) $item->get_name();
				}
			}
			$plan_name = implode( ', ', $names );
		}
		if ( method_exists( $subscription, 'get_date' ) ) {
			$next_str = (string) $subscription->get_date( 'next_payment' );
			if ( '' !== $next_str ) {
				$next_payment = (int) strtotime( $next_str );
			}
		}

		$metadata = array_merge(
			array(
				'subscription_id'    => (string) $subscription_id,
				'plan_name'          => $plan_name,
				'subscription_total' => $total,
				'currency'           => $currency,
				'next_payment_date'  => $next_payment,
			),
			$extra
		);

		$metadata = (array) apply_filters(
			'etherlabz_intercom_subscription_event_metadata',
			$metadata,
			$subscription,
			$event_name
		);

		$api->create_event( $email, $event_name, $metadata );
	}
}
