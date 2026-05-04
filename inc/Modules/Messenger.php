<?php
/**
 * Intercom Messenger module.
 *
 * Injects the Intercom Messenger JavaScript bootloader into the public
 * site footer and supplies authenticated user data (with HMAC identity
 * verification when an Identity Verification Secret is configured).
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules;

use Etherlabz\Intercom_Woo_Sync\Contracts\Registrable;
use Etherlabz\Intercom_Woo_Sync\Core\Encryption;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Messenger
 */
final class Messenger implements Registrable {

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		if ( 'yes' !== get_option( 'iws_enable_messenger', 'no' ) ) {
			return;
		}

		if ( '' === self::get_app_id() ) {
			return;
		}

		add_action( 'wp_footer', array( $this, 'render_messenger' ), 99 );
	}

	/**
	 * Render the Intercom Messenger snippet in the site footer.
	 */
	public function render_messenger(): void {
		$app_id = self::get_app_id();

		if ( '' === $app_id ) {
			return;
		}

		$settings = $this->build_settings_payload( $app_id );

		// Encode safely for inline JS.
		$json = wp_json_encode( $settings, JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $json is JSON-encoded, $app_id is rawurlencoded; both safe for inline JS.
		?>
<script>
window.intercomSettings = <?php echo $json; ?>;
</script>
<script>
(function(){var w=window;var ic=w.Intercom;if(typeof ic==="function"){ic('reattach_activator');ic('update',w.intercomSettings);}else{var d=document;var i=function(){i.c(arguments);};i.q=[];i.c=function(args){i.q.push(args);};w.Intercom=i;var l=function(){var s=d.createElement('script');s.type='text/javascript';s.async=true;s.src='https://widget.intercom.io/widget/<?php echo rawurlencode( $app_id ); ?>';var x=d.getElementsByTagName('script')[0];x.parentNode.insertBefore(s,x);};if(document.readyState==='complete'){l();}else if(w.attachEvent){w.attachEvent('onload',l);}else{w.addEventListener('load',l,false);}}})();
</script>
		<?php
		// phpcs:enable
	}

	/**
	 * Build the `window.intercomSettings` payload.
	 *
	 * @param string $app_id Intercom workspace App ID.
	 *
	 * @return array<string, mixed>
	 */
	private function build_settings_payload( string $app_id ): array {
		$settings = array(
			'api_base' => 'https://api-iam.intercom.io',
			'app_id'   => $app_id,
		);

		$user = wp_get_current_user();

		if ( $user && $user->exists() && $user->user_email ) {
			$created_at = (int) strtotime( (string) $user->user_registered );
			if ( $created_at <= 0 ) {
				$created_at = time();
			}

			$settings['user_id']    = (string) $user->ID;
			$settings['email']      = $user->user_email;
			$settings['name']       = trim( $user->display_name );
			$settings['created_at'] = $created_at;

			$user_hash = self::generate_user_hash( $user->user_email );

			if ( '' !== $user_hash ) {
				$settings['user_hash'] = $user_hash;
			}
		}

		/**
		 * Filter the Intercom Messenger settings payload before it is rendered.
		 *
		 * @param array $settings The settings array passed to window.intercomSettings.
		 * @param \WP_User|null $user    The current logged-in user, or null if anonymous.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- iws_ is the documented public hook prefix.
		return (array) apply_filters( 'iws_messenger_settings', $settings, $user );
	}

	/**
	 * Generate the HMAC user_hash for a contact identifier.
	 *
	 * Intercom's Identity Verification spec: HMAC-SHA256 of the user identifier
	 * (email or external user_id) keyed by the workspace's verification secret.
	 *
	 * @param string $identifier Email address or user_id used in the Messenger boot.
	 *
	 * @return string Hex-encoded HMAC, or empty string when no secret is configured.
	 */
	public static function generate_user_hash( string $identifier ): string {
		if ( '' === $identifier ) {
			return '';
		}

		$secret = self::get_secret();

		if ( '' === $secret ) {
			return '';
		}

		return hash_hmac( 'sha256', $identifier, $secret );
	}

	/**
	 * Get the configured Intercom App ID (workspace ID).
	 */
	public static function get_app_id(): string {
		$value = (string) get_option( 'iws_app_id', '' );
		return trim( $value );
	}

	/**
	 * Get the decrypted Identity Verification Secret, or empty string if not set.
	 */
	public static function get_secret(): string {
		$raw = (string) get_option( 'iws_hmac_secret', '' );
		return Encryption::decrypt( $raw );
	}
}
