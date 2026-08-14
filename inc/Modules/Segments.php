<?php
/**
 * Smart segment rules.
 *
 * Evaluates a small set of admin-defined rules against a WooCommerce
 * customer and applies matching tags in Intercom (e.g. "vip" when
 * total_orders >= 5 AND lifetime_value >= 200).
 *
 * Rule schema stored in the `etherlabz_intercom_segment_rules` option:
 *   array<string, array{
 *     id: string,
 *     name: string,
 *     tag: string,
 *     match: 'all'|'any',
 *     enabled: bool,
 *     conditions: array<int, array{field:string, operator:string, value:string}>
 *   }>
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;
use WC_Customer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Segments
 */
final class Segments implements Registrable {

	/**
	 * Supported customer fields, with their value type.
	 *
	 * @var array<string, string> Field => 'numeric'|'string'|'date'.
	 */
	public const FIELDS = array(
		'total_orders'        => 'numeric',
		'lifetime_value'      => 'numeric',
		'last_order_date'     => 'date',
		'last_order_status'   => 'string',
		'billing_country'     => 'string',
		'billing_city'        => 'string',
		'registered_days_ago' => 'numeric',
	);

	/**
	 * Supported operators per value type.
	 *
	 * @var array<string, string[]>
	 */
	public const OPERATORS = array(
		'numeric' => array( '=', '!=', '>', '>=', '<', '<=' ),
		'string'  => array( '=', '!=', 'contains', 'not_contains' ),
		'date'    => array( 'within_days', 'older_than_days' ),
	);

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		// Apply segments after each customer sync.
		add_action( 'etherlabz_intercom_after_customer_sync', array( $this, 'apply_for_customer' ), 10, 2 );
	}

	/**
	 * Hook target — apply matching segment tags to a customer's contact.
	 *
	 * @param string      $intercom_id Intercom contact ID.
	 * @param WC_Customer $customer    The customer whose data drove the sync.
	 */
	public function apply_for_customer( string $intercom_id, $customer ): void {
		if ( ! $customer instanceof WC_Customer ) {
			return;
		}

		if ( '' === $intercom_id ) {
			return;
		}

		$rules = self::get_rules();
		if ( empty( $rules ) ) {
			return;
		}

		$matching = self::evaluate( $rules, $customer );
		if ( empty( $matching ) ) {
			return;
		}

		$api = new Intercom_API();
		if ( ! $api->has_token() ) {
			return;
		}

		foreach ( $matching as $tag_name ) {
			$tag = $api->find_tag_by_name( $tag_name );

			if ( is_wp_error( $tag ) ) {
				continue;
			}

			if ( null === $tag ) {
				$created = $api->create_tag( $tag_name );
				if ( is_wp_error( $created ) ) {
					continue;
				}
				$tag = $created;
			}

			if ( ! is_array( $tag ) || empty( $tag['id'] ) ) {
				continue;
			}

			$api->tag_contact( $intercom_id, (string) $tag['id'] );
		}
	}

	/**
	 * Read rules from the etherlabz_intercom_segment_rules option, normalised to an array.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_rules(): array {
		$raw = get_option( 'etherlabz_intercom_segment_rules', array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Evaluate every rule against the customer and return the tag names that matched.
	 *
	 * @param array<string, array<string, mixed>> $rules    Rules array (from the option).
	 * @param WC_Customer                         $customer The customer to evaluate.
	 *
	 * @return string[] Unique matching tag names.
	 */
	public static function evaluate( array $rules, $customer ): array {
		$matched = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$enabled = $rule['enabled'] ?? true;
			if ( true !== $enabled && 'true' !== $enabled && 1 !== $enabled && '1' !== $enabled ) {
				continue;
			}

			$tag = trim( (string) ( $rule['tag'] ?? '' ) );
			if ( '' === $tag ) {
				continue;
			}

			if ( self::rule_matches( $rule, $customer ) ) {
				$matched[] = $tag;
			}
		}

		return array_values( array_unique( $matched ) );
	}

	/**
	 * Whether a single rule matches the given customer.
	 *
	 * @param array<string, mixed> $rule     Rule.
	 * @param WC_Customer          $customer Customer.
	 */
	public static function rule_matches( array $rule, $customer ): bool {
		$conditions = $rule['conditions'] ?? array();
		if ( ! is_array( $conditions ) || empty( $conditions ) ) {
			return false;
		}

		$mode = 'any' === ( $rule['match'] ?? 'all' ) ? 'any' : 'all';

		foreach ( $conditions as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}

			$ok = self::condition_matches( $condition, $customer );

			if ( 'all' === $mode && ! $ok ) {
				return false;
			}
			if ( 'any' === $mode && $ok ) {
				return true;
			}
		}

		// 'all' mode reaches here only if no condition failed → all passed.
		// 'any' mode reaches here only if no condition succeeded → none passed.
		return 'all' === $mode;
	}

	/**
	 * Whether a single condition matches the customer.
	 *
	 * @param array<string, mixed> $condition Condition row.
	 * @param WC_Customer          $customer  Customer.
	 */
	public static function condition_matches( array $condition, $customer ): bool {
		$field    = (string) ( $condition['field'] ?? '' );
		$operator = (string) ( $condition['operator'] ?? '' );
		$expected = (string) ( $condition['value'] ?? '' );

		if ( '' === $field || '' === $operator ) {
			return false;
		}

		$type = self::FIELDS[ $field ] ?? null;
		if ( null === $type ) {
			return false;
		}

		$actual = self::get_field_value( $customer, $field );

		return self::compare( $type, $actual, $operator, $expected );
	}

	/**
	 * Read a supported field off a customer.
	 *
	 * @param WC_Customer $customer Customer.
	 * @param string      $field    Field key from FIELDS.
	 *
	 * @return mixed
	 */
	public static function get_field_value( $customer, string $field ) {
		if ( ! $customer instanceof WC_Customer ) {
			return null;
		}

		switch ( $field ) {
			case 'total_orders':
				return (int) $customer->get_order_count();
			case 'lifetime_value':
				return (float) $customer->get_total_spent();
			case 'last_order_date':
				$order = $customer->get_last_order();
				if ( $order && method_exists( $order, 'get_date_created' ) && $order->get_date_created() ) {
					return (int) $order->get_date_created()->getTimestamp();
				}
				return 0;
			case 'last_order_status':
				$order = $customer->get_last_order();
				return $order && method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
			case 'billing_country':
				return (string) $customer->get_billing_country();
			case 'billing_city':
				return (string) $customer->get_billing_city();
			case 'registered_days_ago':
				$created = $customer->get_date_created();
				if ( $created ) {
					$diff = time() - $created->getTimestamp();
					return (int) floor( $diff / DAY_IN_SECONDS );
				}
				return 0;
		}

		return null;
	}

	/**
	 * Strict typed comparison for a single condition.
	 *
	 * @param string $type     'numeric' | 'string' | 'date'.
	 * @param mixed  $actual   Actual customer value.
	 * @param string $operator Operator from OPERATORS[$type].
	 * @param string $expected Expected value as posted (string).
	 */
	public static function compare( string $type, $actual, string $operator, string $expected ): bool {
		switch ( $type ) {
			case 'numeric':
				$a = is_numeric( $actual ) ? (float) $actual : 0.0;
				$b = is_numeric( $expected ) ? (float) $expected : 0.0;
				switch ( $operator ) {
					case '=':
						return $a === $b;
					case '!=':
						return $a !== $b;
					case '>':
						return $a > $b;
					case '>=':
						return $a >= $b;
					case '<':
						return $a < $b;
					case '<=':
						return $a <= $b;
				}
				return false;

			case 'string':
				$a = (string) $actual;
				$b = $expected;
				switch ( $operator ) {
					case '=':
						return strcasecmp( $a, $b ) === 0;
					case '!=':
						return strcasecmp( $a, $b ) !== 0;
					case 'contains':
						return '' !== $b && false !== stripos( $a, $b );
					case 'not_contains':
						return '' === $b || false === stripos( $a, $b );
				}
				return false;

			case 'date':
				$timestamp = is_numeric( $actual ) ? (int) $actual : 0;
				$days      = is_numeric( $expected ) ? (int) $expected : 0;
				if ( $timestamp <= 0 || $days <= 0 ) {
					return false;
				}
				$age_days = (int) floor( ( time() - $timestamp ) / DAY_IN_SECONDS );
				switch ( $operator ) {
					case 'within_days':
						return $age_days <= $days;
					case 'older_than_days':
						return $age_days > $days;
				}
				return false;
		}

		return false;
	}

	/**
	 * Sanitize an incoming rules payload before saving to the option.
	 *
	 * Public + static so the Settings sanitize callback and the AJAX
	 * handler share a single normalisation path.
	 *
	 * @param mixed $raw Raw input (expected: array of rule arrays).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function sanitize_rules( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$id   = (string) ( $rule['id'] ?? '' );
			$tag  = trim( (string) ( $rule['tag'] ?? '' ) );
			$name = trim( (string) ( $rule['name'] ?? '' ) );

			if ( '' === $tag ) {
				continue;
			}
			if ( '' === $id ) {
				$id = 'rule_' . substr( md5( $tag . microtime( true ) . wp_rand() ), 0, 12 );
			}

			$conditions_in  = is_array( $rule['conditions'] ?? null ) ? $rule['conditions'] : array();
			$conditions_out = array();

			foreach ( $conditions_in as $cond ) {
				if ( ! is_array( $cond ) ) {
					continue;
				}
				$field = (string) ( $cond['field'] ?? '' );
				$op    = (string) ( $cond['operator'] ?? '' );
				$value = (string) ( $cond['value'] ?? '' );

				if ( ! isset( self::FIELDS[ $field ] ) ) {
					continue;
				}
				$type = self::FIELDS[ $field ];
				if ( ! in_array( $op, self::OPERATORS[ $type ], true ) ) {
					continue;
				}

				$conditions_out[] = array(
					'field'    => $field,
					'operator' => $op,
					'value'    => $value,
				);
			}

			$enabled_raw = $rule['enabled'] ?? true;
			$enabled     = filter_var( $enabled_raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			if ( null === $enabled ) {
				$enabled = true;
			}

			$out[ $id ] = array(
				'id'         => $id,
				'name'       => '' !== $name ? $name : $tag,
				'tag'        => $tag,
				'match'      => 'any' === ( $rule['match'] ?? 'all' ) ? 'any' : 'all',
				'enabled'    => $enabled,
				'conditions' => $conditions_out,
			);
		}

		return $out;
	}
}
