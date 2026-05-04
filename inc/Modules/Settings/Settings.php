<?php
/**
 * Registers the plugin's admin settings using the Settings API.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules\Settings
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules\Settings;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;
use Etherlabz\Intercom_Woo_Sync\Core\Encryption;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Settings
 */
final class Settings implements Registrable {

	/**
	 * Settings group name.
	 */
	public const GROUP = 'iws_settings_group';

	/**
	 * Settings section ID.
	 */
	public const SECTION = 'iws_main_section';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register all settings, sections, and fields.
	 */
	public function register_settings(): void {
		// -- Settings registration ----------------------------------------

		register_setting(
			self::GROUP,
			'iws_access_token',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_token' ),
				'default'           => '',
			)
		);

		register_setting(
			self::GROUP,
			'iws_sync_customers',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_yes_no' ),
				'default'           => 'yes',
			)
		);

		register_setting(
			self::GROUP,
			'iws_sync_orders',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_yes_no' ),
				'default'           => 'yes',
			)
		);

		register_setting(
			self::GROUP,
			'iws_hmac_secret',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_token' ),
				'default'           => '',
			)
		);

		// -- Section ------------------------------------------------------

		add_settings_section(
			self::SECTION,
			'',
			'__return_null',
			Admin_Screen::SCREEN_ID
		);

		// -- Fields -------------------------------------------------------

		add_settings_field(
			'iws_access_token',
			__( 'Intercom Access Token', 'intercom-woo-sync' ),
			array( $this, 'render_token_field' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);

		add_settings_field(
			'iws_sync_customers',
			__( 'Sync Customers', 'intercom-woo-sync' ),
			array( $this, 'render_customers_toggle' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);

		add_settings_field(
			'iws_sync_orders',
			__( 'Sync Order Events', 'intercom-woo-sync' ),
			array( $this, 'render_orders_toggle' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);

		add_settings_field(
			'iws_hmac_secret',
			__( 'Identity Verification Secret', 'intercom-woo-sync' ),
			array( $this, 'render_hmac_field' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);
	}

	// -- Field renderers --------------------------------------------------

	/**
	 * Render the access-token field.
	 */
	public function render_token_field(): void {
		$raw   = (string) get_option( 'iws_access_token', '' );
		$plain = Encryption::decrypt( $raw );

		// Show a masked placeholder if a token is stored; otherwise show empty.
		$display = '' !== $plain
			? str_repeat( '*', max( 0, strlen( $plain ) - 8 ) ) . substr( $plain, -8 )
			: '';

		printf(
			'<input type="password" id="iws_access_token" name="iws_access_token" value="%s" class="regular-text" autocomplete="off" placeholder="%s" />',
			esc_attr( $plain ),
			esc_attr__( 'Paste your Intercom access token', 'intercom-woo-sync' )
		);
		echo '<p class="description">';
		if ( '' !== $plain ) {
			echo '<span class="dashicons dashicons-lock iws-lock-icon"></span> ';
			echo esc_html__( 'Token is stored encrypted.', 'intercom-woo-sync' ) . ' ';
		}
		echo esc_html__( 'Found in Intercom → Settings → Developers → Access Token.', 'intercom-woo-sync' );
		echo '</p>';
	}

	/**
	 * Render the customer-sync toggle.
	 */
	public function render_customers_toggle(): void {
		$value = get_option( 'iws_sync_customers', 'yes' );
		printf(
			'<label class="iws-toggle"><input type="checkbox" name="iws_sync_customers" value="yes" %s /><span class="iws-toggle__slider"></span></label>',
			checked( $value, 'yes', false )
		);
		echo '<span class="description">' . esc_html__( 'Automatically sync new and updated customers to Intercom.', 'intercom-woo-sync' ) . '</span>';
	}

	/**
	 * Render the order-events toggle.
	 */
	public function render_orders_toggle(): void {
		$value = get_option( 'iws_sync_orders', 'yes' );
		printf(
			'<label class="iws-toggle"><input type="checkbox" name="iws_sync_orders" value="yes" %s /><span class="iws-toggle__slider"></span></label>',
			checked( $value, 'yes', false )
		);
		echo '<span class="description">' . esc_html__( 'Send order-status events to Intercom when orders change status.', 'intercom-woo-sync' ) . '</span>';
	}

	/**
	 * Render the HMAC identity verification secret field.
	 */
	public function render_hmac_field(): void {
		$raw   = (string) get_option( 'iws_hmac_secret', '' );
		$plain = Encryption::decrypt( $raw );

		printf(
			'<input type="password" id="iws_hmac_secret" name="iws_hmac_secret" value="%s" class="regular-text" autocomplete="off" placeholder="%s" />',
			esc_attr( $plain ),
			esc_attr__( 'Paste your Intercom identity verification secret', 'intercom-woo-sync' )
		);
		echo '<p class="description">';
		if ( '' !== $plain ) {
			echo '<span class="dashicons dashicons-lock iws-lock-icon"></span> ';
			echo esc_html__( 'Secret is stored encrypted.', 'intercom-woo-sync' ) . ' ';
		}
		echo esc_html__( 'Found in Intercom → Settings → Identity Verification. Used to generate HMAC for the chat widget.', 'intercom-woo-sync' );
		echo '</p>';
	}

	// -- Sanitizers -------------------------------------------------------

	/**
	 * Sanitize a yes/no checkbox value.
	 *
	 * @param mixed $value The raw value.
	 */
	public static function sanitize_yes_no( $value ): string {
		return 'yes' === $value ? 'yes' : 'no';
	}

	/**
	 * Sanitize and encrypt the access token before saving.
	 *
	 * Unlike sanitize_text_field, this only trims whitespace so we
	 * don't accidentally mangle valid token characters.
	 *
	 * @param mixed $value The raw input value.
	 */
	public static function sanitize_token( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		// If the submitted value is already encrypted (edge case), return as-is.
		if ( Encryption::is_encrypted( $value ) ) {
			return $value;
		}

		return Encryption::encrypt( $value );
	}
}
