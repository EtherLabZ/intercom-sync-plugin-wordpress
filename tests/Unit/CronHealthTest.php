<?php
/**
 * Tests for Cron_Health::check() — the pure-logic function that
 * returns the issue list rendered into the admin notice.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests\Unit
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Etherlabz\Intercom_Woo_Sync\Modules\Cron_Health;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Etherlabz\Intercom_Woo_Sync\Modules\Cron_Health
 */
class CronHealthTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Stored option backing for get_option/update_option stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Whether plugin crons appear scheduled (drives wp_next_scheduled stub).
	 */
	private bool $has_scheduled = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options       = array();
		$this->has_scheduled = true;

		$opts =& $this->options;

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) use ( &$opts ) {
				return $opts[ $key ] ?? $default;
			}
		);

		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$opts ): bool {
				$opts[ $key ] = $value;
				return true;
			}
		);

		// Non-static closure auto-captures $this so per-test toggling actually takes effect.
		Functions\when( 'wp_next_scheduled' )->alias(
			fn() => $this->has_scheduled ? ( time() + 3600 ) : false
		);

		// __() / sprintf-with-i18n calls — Brain\Monkey doesn't auto-proxy these
		// for namespaced callers, so stub them explicitly.
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_check_returns_empty_when_environment_is_healthy(): void {
		$this->options['iws_last_cron_run'] = time() - 60; // ran 1 min ago.

		$issues = Cron_Health::check();

		$this->assertSame( array(), $issues );
	}

	public function test_check_warns_when_disable_wp_cron_is_true(): void {
		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			define( 'DISABLE_WP_CRON', true ); // phpcs:ignore
		}

		$this->options['iws_last_cron_run'] = time() - 60;

		$issues = Cron_Health::check();

		$codes = array_column( $issues, 'code' );
		$this->assertContains( 'wp_cron_disabled', $codes );
	}

	public function test_check_errors_when_cron_is_stale(): void {
		$this->options['iws_last_cron_run'] = time() - ( 3 * HOUR_IN_SECONDS );

		$issues = Cron_Health::check();

		$codes = array_column( $issues, 'code' );
		$this->assertContains( 'cron_stale', $codes );

		// And severity must be 'error' for the stale case.
		foreach ( $issues as $issue ) {
			if ( 'cron_stale' === $issue['code'] ) {
				$this->assertSame( 'error', $issue['severity'] );
			}
		}
	}

	public function test_check_does_not_flag_stale_when_no_events_are_scheduled(): void {
		// If there are no scheduled events, "no run in an hour" is expected, not stale.
		$this->has_scheduled                = false;
		$this->options['iws_last_cron_run'] = time() - ( 3 * HOUR_IN_SECONDS );

		$issues = Cron_Health::check();

		$codes = array_column( $issues, 'code' );
		$this->assertNotContains( 'cron_stale', $codes );
	}

	public function test_check_warns_when_no_scheduled_events_but_token_is_set(): void {
		$this->has_scheduled                  = false;
		$this->options['iws_access_token']    = 'some-encrypted-token';
		$this->options['iws_last_cron_run']   = 0;

		$issues = Cron_Health::check();

		$codes = array_column( $issues, 'code' );
		$this->assertContains( 'no_scheduled_events', $codes );
	}

	public function test_stamp_run_persists_current_time(): void {
		$before = time();
		Cron_Health::stamp_run();

		$this->assertGreaterThanOrEqual( $before, (int) $this->options['iws_last_cron_run'] );
		$this->assertLessThanOrEqual( time(), (int) $this->options['iws_last_cron_run'] );
	}
}
