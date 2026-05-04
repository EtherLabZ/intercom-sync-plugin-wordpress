<?php
/**
 * PHPUnit bootstrap for Intercom WooCommerce Sync unit tests.
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

// Register the plugin's own PSR-4 autoloader so inc/ classes are available.
require_once dirname( __DIR__ ) . '/inc/Autoloader.php';
\Etherlabz\Intercom_Woo_Sync\Autoloader::register();
