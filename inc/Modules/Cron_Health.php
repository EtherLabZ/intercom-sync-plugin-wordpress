<?php
/**
 * Cron health monitor.
 *
 * Surfaces an admin warning banner when WordPress cron is disabled or
 * appears to have stalled — both common silent-failure modes that take
 * out cart abandonment, bulk sync, and other scheduled background work.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Cron_Health
 */
final class Cron_Health implements Registrable {

	/**
	 * Time after a cron's last successful run before we consider it stale.
	 */
	public const STALENESS_THRESHOLD = HOUR_IN_SECONDS;

	/**
	 * Cron hooks the plugin owns — used both for stamping last-run times
	 * and for evaluating staleness.
	 *
	 * @var string[]
	 */
	private const TRACKED_CRONS = array(
		'iws_bulk_sync_cron',
		'iws_bulk_sync_batch',
		'iws_cart_abandonment_cron',
	);

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		// Stamp the last-run time on every plugin cron, very early.
		foreach ( self::TRACKED_CRONS as $hook ) {
			add_action( $hook, array( __CLASS__, 'stamp_run' ), 1 );
		}

		// Surface warnings on the plugin admin screen.
		add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
	}

	/**
	 * Record the most recent run time for any plugin cron.
	 */
	public static function stamp_run(): void {
		update_option( 'iws_last_cron_run', time(), false );
	}

	/**
	 * Render the cron-health notice on the plugin admin page only.
	 */
	public function maybe_render_notice(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_intercom-woo-sync' !== $screen->id ) {
			return;
		}

		$issues = self::check();
		if ( empty( $issues ) ) {
			return;
		}

		// Pick the highest-severity class once.
		$severity = 'warning';
		foreach ( $issues as $issue ) {
			if ( 'error' === ( $issue['severity'] ?? '' ) ) {
				$severity = 'error';
				break;
			}
		}

		$class = 'error' === $severity ? 'notice notice-error' : 'notice notice-warning';

		echo '<div class="' . esc_attr( $class ) . '">';
		echo '<p><strong>' . esc_html__( 'Intercom Sync — cron health warning', 'etherlabz-intercom-sync' ) . '</strong></p>';
		echo '<ul style="list-style:disc;margin-left:20px;">';
		foreach ( $issues as $issue ) {
			echo '<li>' . esc_html( (string) ( $issue['message'] ?? '' ) ) . '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	/**
	 * Inspect the WP cron environment and report any issues found.
	 *
	 * Pure function — no side effects, easy to unit-test.
	 *
	 * @return array<int, array{severity:string,code:string,message:string}>
	 */
	public static function check(): array {
		$issues = array();
		$now    = time();

		// 1. WP cron explicitly disabled.
		if ( defined( 'DISABLE_WP_CRON' ) && true === constant( 'DISABLE_WP_CRON' ) ) {
			$issues[] = array(
				'severity' => 'warning',
				'code'     => 'wp_cron_disabled',
				'message'  => __(
					'DISABLE_WP_CRON is set to true. Background tasks (bulk sync, abandoned cart detection) only run if you have a server-level cron hitting wp-cron.php.',
					'etherlabz-intercom-sync'
				),
			);
		}

		// 2. ALTERNATE_WP_CRON is fragile; warn but do not error.
		if ( defined( 'ALTERNATE_WP_CRON' ) && true === constant( 'ALTERNATE_WP_CRON' ) ) {
			$issues[] = array(
				'severity' => 'warning',
				'code'     => 'alternate_wp_cron',
				'message'  => __(
					'ALTERNATE_WP_CRON is enabled. It works but is less reliable than a server cron.',
					'etherlabz-intercom-sync'
				),
			);
		}

		// 3. No cron run in the last hour even though we have plugin events scheduled.
		$last_run    = (int) get_option( 'iws_last_cron_run', 0 );
		$has_events  = self::has_scheduled_plugin_events();
		$cron_active = $last_run > 0;

		if ( $has_events && $cron_active && ( $now - $last_run ) > self::STALENESS_THRESHOLD ) {
			$minutes  = (int) floor( ( $now - $last_run ) / MINUTE_IN_SECONDS );
			$issues[] = array(
				'severity' => 'error',
				'code'     => 'cron_stale',
				'message'  => sprintf(
					/* translators: %d: number of minutes since the last successful cron run. */
					__( 'No plugin cron has run in the last %d minutes — background sync may be stuck. Check that wp-cron.php is reachable.', 'etherlabz-intercom-sync' ),
					$minutes
				),
			);
		}

		// 4. Plugin scheduled events are entirely missing — activation may have failed.
		if ( ! $has_events && '' !== (string) get_option( 'iws_access_token', '' ) ) {
			$issues[] = array(
				'severity' => 'warning',
				'code'     => 'no_scheduled_events',
				'message'  => __(
					'No plugin cron events are scheduled. Try deactivating and reactivating the plugin to restore them.',
					'etherlabz-intercom-sync'
				),
			);
		}

		return $issues;
	}

	/**
	 * Whether any plugin cron is currently scheduled.
	 */
	private static function has_scheduled_plugin_events(): bool {
		foreach ( self::TRACKED_CRONS as $hook ) {
			if ( false !== wp_next_scheduled( $hook ) ) {
				return true;
			}
		}
		return false;
	}
}
