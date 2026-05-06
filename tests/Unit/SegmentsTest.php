<?php
/**
 * Tests for the Segments module — rule evaluation, condition matching,
 * comparison operators, and rule sanitization.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests\Unit
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Etherlabz\Intercom_Woo_Sync\Modules\Segments;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Etherlabz\Intercom_Woo_Sync\Modules\Segments
 */
class SegmentsTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_rand' )->alias( fn() => 12345 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// compare() — typed comparison primitives
	// ------------------------------------------------------------------

	public function test_compare_numeric_operators(): void {
		$this->assertTrue( Segments::compare( 'numeric', 5, '>=', '5' ) );
		$this->assertTrue( Segments::compare( 'numeric', 6, '>', '5' ) );
		$this->assertFalse( Segments::compare( 'numeric', 5, '>', '5' ) );
		$this->assertTrue( Segments::compare( 'numeric', 5, '<=', '5' ) );
		$this->assertTrue( Segments::compare( 'numeric', 4, '<', '5' ) );
		$this->assertTrue( Segments::compare( 'numeric', 5, '=', '5' ) );
		$this->assertTrue( Segments::compare( 'numeric', 5, '!=', '6' ) );
	}

	public function test_compare_string_operators_are_case_insensitive(): void {
		$this->assertTrue( Segments::compare( 'string', 'GB', '=', 'gb' ) );
		$this->assertFalse( Segments::compare( 'string', 'GB', '!=', 'gb' ) );
		$this->assertTrue( Segments::compare( 'string', 'London', 'contains', 'lon' ) );
		$this->assertTrue( Segments::compare( 'string', 'London', 'not_contains', 'paris' ) );
	}

	public function test_compare_date_within_days(): void {
		$ts_30_days_ago = time() - ( 30 * DAY_IN_SECONDS );

		$this->assertTrue( Segments::compare( 'date', $ts_30_days_ago, 'within_days', '60' ) );
		$this->assertFalse( Segments::compare( 'date', $ts_30_days_ago, 'within_days', '15' ) );
		$this->assertTrue( Segments::compare( 'date', $ts_30_days_ago, 'older_than_days', '15' ) );
		$this->assertFalse( Segments::compare( 'date', $ts_30_days_ago, 'older_than_days', '60' ) );
	}

	public function test_compare_returns_false_for_invalid_types_and_inputs(): void {
		$this->assertFalse( Segments::compare( 'unknown_type', 1, '>', '0' ) );
		$this->assertFalse( Segments::compare( 'date', 0, 'within_days', '10' ) );
		$this->assertFalse( Segments::compare( 'numeric', 5, 'invalid_op', '5' ) );
	}

	// ------------------------------------------------------------------
	// rule_matches() — match-all vs match-any semantics
	// ------------------------------------------------------------------

	public function test_rule_match_all_requires_every_condition(): void {
		$customer = $this->customer_with(
			array(
				'order_count' => 7,
				'total_spent' => 250,
			)
		);

		$rule = array(
			'match'      => 'all',
			'conditions' => array(
				array(
					'field'    => 'total_orders',
					'operator' => '>=',
					'value'    => '5',
				),
				array(
					'field'    => 'lifetime_value',
					'operator' => '>=',
					'value'    => '200',
				),
			),
		);

		$this->assertTrue( Segments::rule_matches( $rule, $customer ) );
	}

	public function test_rule_match_all_fails_if_any_condition_false(): void {
		$customer = $this->customer_with(
			array(
				'order_count' => 7,
				'total_spent' => 50,
			)
		);

		$rule = array(
			'match'      => 'all',
			'conditions' => array(
				array(
					'field'    => 'total_orders',
					'operator' => '>=',
					'value'    => '5',
				),
				array(
					'field'    => 'lifetime_value',
					'operator' => '>=',
					'value'    => '200',
				),
			),
		);

		$this->assertFalse( Segments::rule_matches( $rule, $customer ) );
	}

	public function test_rule_match_any_passes_if_one_condition_passes(): void {
		$customer = $this->customer_with(
			array(
				'order_count' => 7,
				'total_spent' => 50,
			)
		);

		$rule = array(
			'match'      => 'any',
			'conditions' => array(
				array(
					'field'    => 'total_orders',
					'operator' => '>=',
					'value'    => '5',
				),
				array(
					'field'    => 'lifetime_value',
					'operator' => '>=',
					'value'    => '200',
				),
			),
		);

		$this->assertTrue( Segments::rule_matches( $rule, $customer ) );
	}

	public function test_rule_with_empty_conditions_is_never_a_match(): void {
		$customer = $this->customer_with( array() );

		$this->assertFalse( Segments::rule_matches( array( 'match' => 'all', 'conditions' => array() ), $customer ) );
		$this->assertFalse( Segments::rule_matches( array( 'match' => 'any', 'conditions' => array() ), $customer ) );
	}

	// ------------------------------------------------------------------
	// evaluate() — multi-rule + tag dedupe + enabled flag
	// ------------------------------------------------------------------

	public function test_evaluate_returns_only_tags_for_matching_enabled_rules(): void {
		$customer = $this->customer_with(
			array(
				'order_count' => 10,
				'total_spent' => 1500,
			)
		);

		$rules = array(
			'r1' => array(
				'tag'        => 'vip',
				'enabled'    => true,
				'match'      => 'all',
				'conditions' => array(
					array(
						'field'    => 'total_orders',
						'operator' => '>=',
						'value'    => '5',
					),
				),
			),
			'r2' => array(
				'tag'        => 'whale',
				'enabled'    => true,
				'match'      => 'all',
				'conditions' => array(
					array(
						'field'    => 'lifetime_value',
						'operator' => '>=',
						'value'    => '1000',
					),
				),
			),
			'r3' => array(
				'tag'        => 'newbie',
				'enabled'    => true,
				'match'      => 'all',
				'conditions' => array(
					array(
						'field'    => 'total_orders',
						'operator' => '<=',
						'value'    => '1',
					),
				),
			),
			'r4' => array(
				'tag'        => 'disabled-tag',
				'enabled'    => false, // Even though it would match, must be skipped.
				'match'      => 'all',
				'conditions' => array(
					array(
						'field'    => 'total_orders',
						'operator' => '>=',
						'value'    => '1',
					),
				),
			),
		);

		$tags = Segments::evaluate( $rules, $customer );

		$this->assertContains( 'vip', $tags );
		$this->assertContains( 'whale', $tags );
		$this->assertNotContains( 'newbie', $tags );
		$this->assertNotContains( 'disabled-tag', $tags );
	}

	public function test_evaluate_skips_rules_with_empty_tag(): void {
		$customer = $this->customer_with( array( 'order_count' => 10 ) );

		$rules = array(
			'r1' => array(
				'tag'        => '',
				'enabled'    => true,
				'match'      => 'all',
				'conditions' => array(
					array(
						'field'    => 'total_orders',
						'operator' => '>=',
						'value'    => '1',
					),
				),
			),
		);

		$this->assertSame( array(), Segments::evaluate( $rules, $customer ) );
	}

	// ------------------------------------------------------------------
	// sanitize_rules() — sanitisation invariants
	// ------------------------------------------------------------------

	public function test_sanitize_rules_drops_rule_with_empty_tag(): void {
		$raw = array(
			array(
				'id'         => 'a',
				'name'       => 'X',
				'tag'        => '',
				'conditions' => array(),
			),
		);
		$this->assertSame( array(), Segments::sanitize_rules( $raw ) );
	}

	public function test_sanitize_rules_drops_unsupported_field_or_operator(): void {
		$raw = array(
			array(
				'id'         => 'r1',
				'name'       => 'r',
				'tag'        => 'tagged',
				'match'      => 'all',
				'enabled'    => true,
				'conditions' => array(
					array(
						'field'    => 'evil_field',
						'operator' => '>',
						'value'    => '1',
					),
					array(
						'field'    => 'total_orders',
						'operator' => 'haxx',
						'value'    => '1',
					),
					array(
						'field'    => 'total_orders',
						'operator' => '>=',
						'value'    => '5',
					),
				),
			),
		);

		$out = Segments::sanitize_rules( $raw );

		$this->assertCount( 1, $out );
		$rule = reset( $out );
		$this->assertCount( 1, $rule['conditions'], 'Only the valid condition should survive.' );
		$this->assertSame( 'total_orders', $rule['conditions'][0]['field'] );
	}

	public function test_sanitize_rules_assigns_id_when_missing(): void {
		$raw = array(
			array(
				'tag'        => 'newbie',
				'name'       => '',
				'conditions' => array(
					array(
						'field'    => 'total_orders',
						'operator' => '<=',
						'value'    => '1',
					),
				),
			),
		);

		$out = Segments::sanitize_rules( $raw );

		$this->assertCount( 1, $out );
		$rule = reset( $out );
		$this->assertNotEmpty( $rule['id'] );
		$this->assertSame( 'newbie', $rule['name'] ); // falls back from tag when name empty.
	}

	public function test_sanitize_rules_normalises_match_to_all_or_any_only(): void {
		$raw = array(
			array(
				'id'         => 'r',
				'tag'        => 't',
				'match'      => 'invalid',
				'conditions' => array(
					array(
						'field'    => 'total_orders',
						'operator' => '=',
						'value'    => '1',
					),
				),
			),
		);

		$out = Segments::sanitize_rules( $raw );
		$rule = reset( $out );

		$this->assertSame( 'all', $rule['match'] );
	}

	public function test_sanitize_rules_handles_non_array_input(): void {
		$this->assertSame( array(), Segments::sanitize_rules( null ) );
		$this->assertSame( array(), Segments::sanitize_rules( 'string' ) );
		$this->assertSame( array(), Segments::sanitize_rules( 42 ) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Build a Mockery WC_Customer with the given field values.
	 *
	 * @param array<string, mixed> $values Override map keyed by getter suffix.
	 *
	 * @return \WC_Customer
	 */
	private function customer_with( array $values ): \WC_Customer {
		$customer = Mockery::mock( \WC_Customer::class );
		$customer->shouldReceive( 'get_order_count' )->andReturn( $values['order_count'] ?? 0 );
		$customer->shouldReceive( 'get_total_spent' )->andReturn( $values['total_spent'] ?? 0 );
		$customer->shouldReceive( 'get_billing_country' )->andReturn( $values['billing_country'] ?? '' );
		$customer->shouldReceive( 'get_billing_city' )->andReturn( $values['billing_city'] ?? '' );
		$customer->shouldReceive( 'get_last_order' )->andReturn( null );
		$customer->shouldReceive( 'get_date_created' )->andReturn( null );
		return $customer;
	}
}
