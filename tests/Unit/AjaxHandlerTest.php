<?php
/**
 * Tests for Ajax_Handler helpers.
 *
 * Only the pure static helpers are unit-tested here; the AJAX endpoints
 * themselves depend on wp_send_json_* and are covered by integration testing.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests\Unit
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Tests\Unit;

use Etherlabz\Intercom_Woo_Sync\Modules\Ajax_Handler;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Etherlabz\Intercom_Woo_Sync\Modules\Ajax_Handler
 */
class AjaxHandlerTest extends TestCase {

	public function test_explicit_already_exists_code_is_recognised(): void {
		$this->assertTrue( Ajax_Handler::is_attribute_exists_error( 422, 'attribute_already_exists', 'whatever' ) );
	}

	public function test_conflict_status_is_recognised(): void {
		$this->assertTrue( Ajax_Handler::is_attribute_exists_error( 409, '', '' ) );
	}

	public function test_people_data_variant_is_recognised(): void {
		// Intercom returns this shape for attributes that already exist as
		// "people data": HTTP 400, parameter_invalid, "You already have …".
		$this->assertTrue(
			Ajax_Handler::is_attribute_exists_error(
				400,
				'parameter_invalid',
				"HTTP 400 [parameter_invalid]: You already have 'last_order_date' in your people data. To save this as new people data, use a different name."
			)
		);
	}

	public function test_other_parameter_invalid_errors_are_not_swallowed(): void {
		$this->assertFalse(
			Ajax_Handler::is_attribute_exists_error( 400, 'parameter_invalid', 'Name contains invalid characters.' )
		);
	}

	public function test_server_errors_are_not_treated_as_existing(): void {
		$this->assertFalse( Ajax_Handler::is_attribute_exists_error( 500, '', 'Internal error' ) );
		$this->assertFalse( Ajax_Handler::is_attribute_exists_error( 429, '', 'Rate limited' ) );
	}
}
