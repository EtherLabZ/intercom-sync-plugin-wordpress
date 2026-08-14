<?php
/**
 * Tests for Bulk_Sync state helpers.
 *
 * Uses Brain\Monkey to stub WordPress option functions.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests\Unit
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Etherlabz\Intercom_Woo_Sync\Modules\Bulk_Sync;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Etherlabz\Intercom_Woo_Sync\Modules\Bulk_Sync
 */
class BulkSyncTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// is_running()
	// ------------------------------------------------------------------

	public function test_is_running_returns_true_when_option_is_yes(): void {
		Functions\when( 'get_option' )
			->justReturn( 'yes' );

		$this->assertTrue( Bulk_Sync::is_running() );
	}

	public function test_is_running_returns_false_when_option_is_no(): void {
		Functions\when( 'get_option' )
			->justReturn( 'no' );

		$this->assertFalse( Bulk_Sync::is_running() );
	}

	public function test_is_running_returns_false_for_unexpected_option_value(): void {
		Functions\when( 'get_option' )
			->justReturn( '' );

		$this->assertFalse( Bulk_Sync::is_running() );
	}

	// ------------------------------------------------------------------
	// start() — verify it sets options and schedules cron
	// ------------------------------------------------------------------

	public function test_start_sets_offset_to_zero(): void {
		$updatedOptions = array();

		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$updatedOptions ): bool {
				$updatedOptions[ $key ] = $value;
				return true;
			}
		);

		// Stub everything else start() calls.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'spawn_cron' )->justReturn( null );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

		Bulk_Sync::start();

		$this->assertSame( 0, $updatedOptions['etherlabz_intercom_bulk_sync_offset'] );
	}

	public function test_start_sets_running_flag_to_yes(): void {
		$updatedOptions = array();

		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$updatedOptions ): bool {
				$updatedOptions[ $key ] = $value;
				return true;
			}
		);

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'spawn_cron' )->justReturn( null );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

		Bulk_Sync::start();

		$this->assertSame( 'yes', $updatedOptions['etherlabz_intercom_bulk_sync_running'] );
	}

	public function test_start_schedules_batch_cron_when_not_already_scheduled(): void {
		$scheduledEvent = null;
		$scheduledHook  = null;

		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'spawn_cron' )->justReturn( null );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( int $time, string $hook ) use ( &$scheduledEvent, &$scheduledHook ): bool {
				$scheduledEvent = $time;
				$scheduledHook  = $hook;
				return true;
			}
		);

		Bulk_Sync::start();

		$this->assertSame( 'etherlabz_intercom_bulk_sync_batch', $scheduledHook );
	}

	public function test_start_does_not_double_schedule_if_event_exists(): void {
		$scheduleCallCount = 0;

		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'spawn_cron' )->justReturn( null );
		// Already scheduled.
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 5 );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function () use ( &$scheduleCallCount ): bool {
				++$scheduleCallCount;
				return true;
			}
		);

		Bulk_Sync::start();

		$this->assertSame( 0, $scheduleCallCount, 'wp_schedule_single_event should not be called when event is already scheduled.' );
	}
}
