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
	public const GROUP = 'etherlabz_intercom_settings_group';

	/**
	 * Settings section ID.
	 */
	public const SECTION = 'etherlabz_intercom_main_section';

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
			'etherlabz_intercom_access_token',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_access_token' ),
				'default'           => '',
			)
		);

		register_setting(
			self::GROUP,
			'etherlabz_intercom_sync_customers',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_yes_no' ),
				'default'           => 'yes',
			)
		);

		register_setting(
			self::GROUP,
			'etherlabz_intercom_sync_orders',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_yes_no' ),
				'default'           => 'yes',
			)
		);

		register_setting(
			self::GROUP,
			'etherlabz_intercom_hmac_secret',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_hmac_secret' ),
				'default'           => '',
			)
		);

		register_setting(
			self::GROUP,
			'etherlabz_intercom_app_id',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_app_id' ),
				'default'           => '',
			)
		);

		register_setting(
			self::GROUP,
			'etherlabz_intercom_enable_messenger',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_yes_no' ),
				'default'           => 'no',
			)
		);

		register_setting(
			self::GROUP,
			'etherlabz_intercom_enable_cart_events',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_yes_no' ),
				'default'           => 'no',
			)
		);

		register_setting(
			self::GROUP,
			'etherlabz_intercom_enable_cart_abandonment',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_yes_no' ),
				'default'           => 'no',
			)
		);

		register_setting(
			self::GROUP,
			'etherlabz_intercom_cart_abandon_minutes',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'sanitize_minutes' ),
				'default'           => 60,
			)
		);

		register_setting(
			self::GROUP,
			'etherlabz_intercom_enable_subscriptions',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_yes_no' ),
				'default'           => 'no',
			)
		);

		register_setting(
			self::GROUP,
			'etherlabz_intercom_enable_purchase_tags',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_yes_no' ),
				'default'           => 'no',
			)
		);

		register_setting(
			self::GROUP,
			'etherlabz_intercom_sync_guest_checkout',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_yes_no' ),
				'default'           => 'yes',
			)
		);

		// Fin AI write actions — all default OFF (cancel/refund are dangerous).
		register_setting(
			self::GROUP,
			'etherlabz_intercom_fin_action_cancel_enabled',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_yes_no' ),
				'default'           => 'no',
			)
		);

		register_setting(
			self::GROUP,
			'etherlabz_intercom_fin_action_refund_enabled',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_yes_no' ),
				'default'           => 'no',
			)
		);

		register_setting(
			self::GROUP,
			'etherlabz_intercom_fin_action_note_enabled',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_yes_no' ),
				'default'           => 'no',
			)
		);

		// Segment rules — array of structured rule objects.
		register_setting(
			self::GROUP,
			'etherlabz_intercom_segment_rules',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( \Etherlabz\Intercom_Woo_Sync\Modules\Segments::class, 'sanitize_rules' ),
				'default'           => array(),
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
			'etherlabz_intercom_access_token',
			__( 'Intercom Access Token', 'etherlabz-intercom-sync' ),
			array( $this, 'render_token_field' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);

		add_settings_field(
			'etherlabz_intercom_sync_customers',
			__( 'Sync Customers', 'etherlabz-intercom-sync' ),
			array( $this, 'render_customers_toggle' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);

		add_settings_field(
			'etherlabz_intercom_sync_orders',
			__( 'Sync Order Events', 'etherlabz-intercom-sync' ),
			array( $this, 'render_orders_toggle' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);

		add_settings_field(
			'etherlabz_intercom_hmac_secret',
			__( 'Identity Verification Secret', 'etherlabz-intercom-sync' ),
			array( $this, 'render_hmac_field' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);

		add_settings_field(
			'etherlabz_intercom_app_id',
			__( 'Intercom App ID', 'etherlabz-intercom-sync' ),
			array( $this, 'render_app_id_field' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);

		add_settings_field(
			'etherlabz_intercom_enable_messenger',
			__( 'Embed Intercom Messenger', 'etherlabz-intercom-sync' ),
			array( $this, 'render_messenger_toggle' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);

		add_settings_field(
			'etherlabz_intercom_enable_cart_events',
			__( 'Track Cart & Funnel Events', 'etherlabz-intercom-sync' ),
			array( $this, 'render_cart_events_toggle' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);

		add_settings_field(
			'etherlabz_intercom_enable_cart_abandonment',
			__( 'Abandoned Cart Detection', 'etherlabz-intercom-sync' ),
			array( $this, 'render_cart_abandon_toggle' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);

		add_settings_field(
			'etherlabz_intercom_cart_abandon_minutes',
			__( 'Abandonment Threshold (minutes)', 'etherlabz-intercom-sync' ),
			array( $this, 'render_cart_abandon_minutes' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);

		add_settings_field(
			'etherlabz_intercom_enable_subscriptions',
			__( 'WooCommerce Subscriptions Events', 'etherlabz-intercom-sync' ),
			array( $this, 'render_subscriptions_toggle' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);

		add_settings_field(
			'etherlabz_intercom_enable_purchase_tags',
			__( 'Auto-Tag Customers by Purchase', 'etherlabz-intercom-sync' ),
			array( $this, 'render_purchase_tags_toggle' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);

		add_settings_field(
			'etherlabz_intercom_sync_guest_checkout',
			__( 'Sync Guest Checkout Customers', 'etherlabz-intercom-sync' ),
			array( $this, 'render_guest_checkout_toggle' ),
			Admin_Screen::SCREEN_ID,
			self::SECTION
		);
	}

	// -- Field renderers --------------------------------------------------

	/**
	 * Render the access-token field.
	 */
	public function render_token_field(): void {
		$this->render_secret_field(
			'etherlabz_intercom_access_token',
			__( 'Paste your Intercom access token', 'etherlabz-intercom-sync' ),
			__( 'Intercom → Settings → Developers → Access Token', 'etherlabz-intercom-sync' )
		);
	}

	/**
	 * Render a write-only secret field with an explicit lifecycle UI.
	 *
	 * Saved:  a locked chip (masked value + last 4) with a "Change" button;
	 *         the input stays hidden AND disabled, so nothing is submitted
	 *         and the stored value is kept.
	 * Broken: the chip flips to a warning ("Re-enter") when the stored blob
	 *         can no longer be decrypted (security keys changed).
	 * Empty:  a plain input.
	 *
	 * The decrypted secret is never echoed into the page — only its last
	 * four characters, to identify which credential is stored.
	 *
	 * @param string $option          Option name (also used as input id/name).
	 * @param string $add_placeholder Placeholder for the empty/replace input.
	 * @param string $hint            One-line hint under the field.
	 */
	private function render_secret_field( string $option, string $add_placeholder, string $hint ): void {
		$raw    = (string) get_option( $option, '' );
		$plain  = Encryption::decrypt( $raw );
		$broken = Encryption::is_undecryptable( $raw );
		$stored = '' !== $plain;

		echo '<div class="iws-secret">';

		if ( $stored || $broken ) {
			$chip_class = $broken ? 'iws-secret__chip iws-secret__chip--broken' : 'iws-secret__chip';
			$icon       = $broken ? 'dashicons-warning' : 'dashicons-lock';
			$mask       = $stored ? '•••••••• ' . substr( $plain, -4 ) : '••••••••';
			$badge      = $broken
				? '<span class="iws-badge iws-badge--error">' . esc_html__( 'Needs re-entry', 'etherlabz-intercom-sync' ) . '</span>'
				: '<span class="iws-badge iws-badge--success">' . esc_html__( 'Saved', 'etherlabz-intercom-sync' ) . '</span>';

			printf(
				'<span class="%1$s"><span class="dashicons %2$s"></span><code>%3$s</code>%4$s</span>
				<button type="button" class="button iws-secret__change">%5$s</button>',
				esc_attr( $chip_class ),
				esc_attr( $icon ),
				esc_html( $mask ),
				$badge, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
				$broken
					? esc_html__( 'Re-enter', 'etherlabz-intercom-sync' )
					: esc_html__( 'Change', 'etherlabz-intercom-sync' )
			);
		}

		printf(
			'<span class="iws-secret__editor%1$s"><input type="password" id="%2$s" name="%2$s" value="" class="regular-text" autocomplete="off" placeholder="%3$s"%4$s />%5$s</span>',
			( $stored || $broken ) ? ' iws-hidden' : '',
			esc_attr( $option ),
			esc_attr( $add_placeholder ),
			( $stored || $broken ) ? ' disabled' : '',
			( $stored || $broken )
				? '<button type="button" class="button-link iws-secret__cancel">' . esc_html__( 'Cancel', 'etherlabz-intercom-sync' ) . '</button>'
				: ''
		);

		echo '</div>';

		if ( $broken ) {
			echo '<p class="description iws-secret__hint">' . esc_html__( 'Your site’s security keys changed, so the saved value can’t be decrypted anymore.', 'etherlabz-intercom-sync' ) . '</p>';
		} else {
			echo '<p class="description iws-secret__hint">' . esc_html( $hint ) . '</p>';
		}
	}

	/**
	 * Render the customer-sync toggle.
	 */
	public function render_customers_toggle(): void {
		$value = get_option( 'etherlabz_intercom_sync_customers', 'yes' );
		printf(
			'<label class="iws-toggle"><input type="checkbox" name="etherlabz_intercom_sync_customers" value="yes" %s /><span class="iws-toggle__slider"></span></label>',
			checked( $value, 'yes', false )
		);
		echo '<span class="description">' . esc_html__( 'Sync new and updated customers automatically.', 'etherlabz-intercom-sync' ) . '</span>';
	}

	/**
	 * Render the order-events toggle.
	 */
	public function render_orders_toggle(): void {
		$value = get_option( 'etherlabz_intercom_sync_orders', 'yes' );
		printf(
			'<label class="iws-toggle"><input type="checkbox" name="etherlabz_intercom_sync_orders" value="yes" %s /><span class="iws-toggle__slider"></span></label>',
			checked( $value, 'yes', false )
		);
		echo '<span class="description">' . esc_html__( 'Send an event on every order status change.', 'etherlabz-intercom-sync' ) . '</span>';
	}

	/**
	 * Render the HMAC identity verification secret field.
	 */
	public function render_hmac_field(): void {
		$this->render_secret_field(
			'etherlabz_intercom_hmac_secret',
			__( 'Paste your identity verification secret', 'etherlabz-intercom-sync' ),
			__( 'Intercom → Settings → Identity Verification', 'etherlabz-intercom-sync' )
		);
	}

	/**
	 * Render the Intercom App ID field.
	 */
	public function render_app_id_field(): void {
		$value = (string) get_option( 'etherlabz_intercom_app_id', '' );
		printf(
			'<input type="text" id="etherlabz_intercom_app_id" name="etherlabz_intercom_app_id" value="%s" class="regular-text" autocomplete="off" placeholder="%s" />',
			esc_attr( $value ),
			esc_attr__( 'e.g. abc12def', 'etherlabz-intercom-sync' )
		);
		echo '<p class="description">';
		echo esc_html__( 'Workspace ID — required for the Messenger. Intercom → Settings → Workspace.', 'etherlabz-intercom-sync' );
		echo '</p>';
	}

	/**
	 * Render the Messenger embed toggle.
	 */
	public function render_messenger_toggle(): void {
		$this->render_yes_no_toggle(
			'etherlabz_intercom_enable_messenger',
			'no',
			__( 'Show the Intercom chat widget on your store.', 'etherlabz-intercom-sync' )
		);
	}

	/**
	 * Render the cart events toggle.
	 */
	public function render_cart_events_toggle(): void {
		$this->render_yes_no_toggle(
			'etherlabz_intercom_enable_cart_events',
			'no',
			__( 'Track product views, add-to-carts, coupons, and checkout starts.', 'etherlabz-intercom-sync' )
		);
	}

	/**
	 * Render the cart abandonment toggle.
	 */
	public function render_cart_abandon_toggle(): void {
		$this->render_yes_no_toggle(
			'etherlabz_intercom_enable_cart_abandonment',
			'no',
			__( 'Flag carts left idle past the threshold below.', 'etherlabz-intercom-sync' )
		);
	}

	/**
	 * Render the cart abandonment minutes field.
	 */
	public function render_cart_abandon_minutes(): void {
		$value = (int) get_option( 'etherlabz_intercom_cart_abandon_minutes', 60 );
		printf(
			'<input type="number" id="etherlabz_intercom_cart_abandon_minutes" name="etherlabz_intercom_cart_abandon_minutes" value="%s" min="5" max="10080" step="5" class="small-text" />',
			esc_attr( (string) $value )
		);
		echo '<span class="description"> ' . esc_html__( '5 min – 1 week.', 'etherlabz-intercom-sync' ) . '</span>';
	}

	/**
	 * Render the subscriptions events toggle.
	 */
	public function render_subscriptions_toggle(): void {
		$this->render_yes_no_toggle(
			'etherlabz_intercom_enable_subscriptions',
			'no',
			__( 'Subscription lifecycle events. Needs WooCommerce Subscriptions.', 'etherlabz-intercom-sync' )
		);
	}

	/**
	 * Render the auto-purchase-tags toggle.
	 */
	public function render_purchase_tags_toggle(): void {
		$this->render_yes_no_toggle(
			'etherlabz_intercom_enable_purchase_tags',
			'no',
			__( 'Tag customers by what they buy; tags come off on refund.', 'etherlabz-intercom-sync' )
		);
	}

	/**
	 * Render the guest checkout toggle.
	 */
	public function render_guest_checkout_toggle(): void {
		$this->render_yes_no_toggle(
			'etherlabz_intercom_sync_guest_checkout',
			'yes',
			__( 'Include guest checkouts (matched by billing email).', 'etherlabz-intercom-sync' )
		);
	}

	/**
	 * Render a generic yes/no toggle.
	 *
	 * @param string $option_name Option key.
	 * @param string $default_value Default value (yes / no).
	 * @param string $description Help text after the toggle.
	 */
	private function render_yes_no_toggle( string $option_name, string $default_value, string $description ): void {
		$value = get_option( $option_name, $default_value );
		printf(
			'<label class="iws-toggle"><input type="checkbox" name="%1$s" value="yes" %2$s /><span class="iws-toggle__slider"></span></label>',
			esc_attr( $option_name ),
			checked( $value, 'yes', false )
		);
		echo '<span class="description">' . esc_html( $description ) . '</span>';
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
	 * Sanitize the Intercom App ID — alphanumeric only.
	 *
	 * @param mixed $value The raw value.
	 */
	public static function sanitize_app_id( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return (string) preg_replace( '/[^a-zA-Z0-9_-]/', '', $value );
	}

	/**
	 * Sanitize a minutes-threshold integer (clamped 5..10080).
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_minutes( $value ): int {
		$value = (int) $value;
		if ( $value < 5 ) {
			$value = 5;
		}
		if ( $value > 10080 ) {
			$value = 10080;
		}
		return $value;
	}

	/**
	 * Sanitize and encrypt the access token before saving.
	 *
	 * @param mixed $value The raw input value.
	 */
	public static function sanitize_access_token( $value ): string {
		return self::sanitize_secret( $value, 'etherlabz_intercom_access_token' );
	}

	/**
	 * Sanitize and encrypt the HMAC identity verification secret before saving.
	 *
	 * @param mixed $value The raw input value.
	 */
	public static function sanitize_hmac_secret( $value ): string {
		return self::sanitize_secret( $value, 'etherlabz_intercom_hmac_secret' );
	}

	/**
	 * Shared sanitizer for write-only secret fields.
	 *
	 * The admin field always renders empty (the secret is never echoed back),
	 * so an empty submission means "keep what's stored" rather than "clear".
	 * Values are re-encrypted with the current format on every save, which
	 * also migrates legacy-format ciphertexts forward.
	 *
	 * Unlike sanitize_text_field, this only trims whitespace so we
	 * don't accidentally mangle valid token characters.
	 *
	 * @param mixed  $value  The raw input value.
	 * @param string $option Option name holding the currently stored value.
	 */
	private static function sanitize_secret( $value, string $option ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			$existing = (string) get_option( $option, '' );

			// Migrate legacy-format (or plaintext) stored values forward.
			$plain = Encryption::decrypt( $existing );
			return '' === $plain ? '' : Encryption::encrypt( $plain );
		}

		// If the submitted value is already encrypted (edge case), return as-is.
		if ( Encryption::is_encrypted( $value ) ) {
			return $value;
		}

		return Encryption::encrypt( $value );
	}
}
