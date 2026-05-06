<?php
/**
 * PHPUnit bootstrap for Etherlabz Intercom Sync for WooCommerce unit tests.
 *
 * Sets up the minimum constants the plugin needs and registers the
 * PSR-4 autoloader so test classes can require plugin classes directly.
 * Brain\Monkey is used (in individual tests) to stub WordPress functions.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Tests
 */

// WordPress core constants needed by plugin files.
define( 'ABSPATH', sys_get_temp_dir() . '/' );

// WordPress security keys — required by Encryption class.
define( 'AUTH_KEY', 'unit-test-auth-key-not-used-in-production' );
define( 'AUTH_SALT', 'unit-test-auth-salt-not-used-in-production' );

// Plugin constants normally set by intercom-woo-sync.php.
define( 'INTERCOM_WOO_SYNC_FILE', dirname( __DIR__ ) . '/intercom-woo-sync.php' );
define( 'INTERCOM_WOO_SYNC_PATH', dirname( __DIR__ ) . '/' );
define( 'INTERCOM_WOO_SYNC_URL', 'https://example.com/wp-content/plugins/intercom-woo-sync/' );
define( 'INTERCOM_WOO_SYNC_VERSION', '1.3.0' );

// Load Composer's autoloader (PHPUnit, Brain\Monkey, Mockery).
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Minimal WP_Error stub — replaces the real class when running without WordPress.
if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:disable -- stub class intentionally mimics WP core without full WP standards.
	class WP_Error {
		private string $code;
		private string $message;
		private mixed $data;

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
	// phpcs:enable
}

// Constants normally provided by WordPress core that some modules use.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// phpcs:disable -- minimal WooCommerce stub classes for the unit-test environment.
// They declare the methods so method_exists() works. Mockery overrides behavior at runtime.

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		public function get_items( $type = 'line_item' ) { return array(); }
		public function get_billing_email(): string { return ''; }
		public function get_billing_first_name(): string { return ''; }
		public function get_billing_last_name(): string { return ''; }
		public function get_billing_phone(): string { return ''; }
		public function get_billing_city(): string { return ''; }
		public function get_billing_country(): string { return ''; }
		public function get_total() { return 0; }
		public function get_currency(): string { return ''; }
		public function get_item_count(): int { return 0; }
		public function get_customer_id(): int { return 0; }
		public function get_date_created() { return null; }
	}
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	class WC_Order_Item_Product {
		public function get_product() { return null; }
		public function get_name(): string { return ''; }
		public function get_quantity() { return 0; }
		public function get_subtotal() { return 0; }
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		public function get_id(): int { return 0; }
		public function get_name(): string { return ''; }
		public function get_slug(): string { return ''; }
		public function get_sku(): string { return ''; }
		public function get_price() { return 0; }
	}
}

if ( ! class_exists( 'WC_Subscription' ) ) {
	class WC_Subscription {
		public function get_billing_email(): string { return ''; }
		public function get_id(): int { return 0; }
		public function get_total() { return 0; }
		public function get_currency(): string { return ''; }
		public function get_items( $type = 'line_item' ) { return array(); }
		public function get_date( $type ): string { return ''; }
	}
}

if ( ! class_exists( 'WC_Coupon' ) ) {
	class WC_Coupon {
		public function __construct( $code = '' ) {}
		public function get_discount_type(): string { return ''; }
		public function get_amount() { return 0; }
	}
}

if ( ! class_exists( 'WC_Customer' ) ) {
	class WC_Customer {
		public function __construct( $id = 0 ) {}
		public function get_id(): int { return 0; }
		public function get_email(): string { return ''; }
		public function get_first_name(): string { return ''; }
		public function get_last_name(): string { return ''; }
		public function get_billing_phone(): string { return ''; }
		public function get_billing_city(): string { return ''; }
		public function get_billing_country(): string { return ''; }
		public function get_order_count(): int { return 0; }
		public function get_total_spent() { return 0; }
		public function get_date_created() { return null; }
		public function get_last_order() { return null; }
	}
}

if ( ! class_exists( 'WC_Order_Refund' ) ) {
	class WC_Order_Refund {
		public function get_id(): int { return 0; }
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	// Implements ArrayAccess so $request['id'] works in the production code.
	class WP_REST_Request implements ArrayAccess {
		public function get_param( $key ) { return null; }
		public function get_header( $key ) { return ''; }
		public function get_body_params() { return array(); }
		public function get_query_params() { return array(); }
		public function offsetExists( $offset ): bool { return false; }
		#[\ReturnTypeWillChange]
		public function offsetGet( $offset ) { return null; }
		public function offsetSet( $offset, $value ): void {}
		public function offsetUnset( $offset ): void {}
	}
}
// phpcs:enable

// Register the plugin's own PSR-4 autoloader so inc/ classes are available.
require_once dirname( __DIR__ ) . '/inc/Autoloader.php';
\Etherlabz\Intercom_Woo_Sync\Autoloader::register();
