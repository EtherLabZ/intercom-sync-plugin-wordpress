<?php
/**
 * Intercom REST API wrapper.
 *
 * Handles all communication with the Intercom v2.10 API.
 *
 * @package Etherlabz\Intercom_Woo_Sync\Modules
 */

declare( strict_types = 1 );

namespace Etherlabz\Intercom_Woo_Sync\Modules;

use Etherlabz\Intercom_Woo_Sync\Core\Encryption;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Intercom_API
 */
final class Intercom_API {

	/**
	 * Intercom REST API base URLs per hosting region.
	 */
	private const BASE_URLS = array(
		'us' => 'https://api.intercom.io',
		'eu' => 'https://api.eu.intercom.io',
		'au' => 'https://api.au.intercom.io',
	);

	/**
	 * REST API base for the configured workspace region.
	 */
	public static function get_base_url(): string {
		$region = (string) get_option( 'etherlabz_intercom_region', 'us' );
		return self::BASE_URLS[ $region ] ?? self::BASE_URLS['us'];
	}

	/**
	 * The bearer access token.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$raw         = (string) get_option( 'etherlabz_intercom_access_token', '' );
		$this->token = Encryption::decrypt( $raw );
	}

	/**
	 * Check whether the API token is configured.
	 */
	public function has_token(): bool {
		return '' !== $this->token;
	}

	/**
	 * Perform a generic request against the Intercom API.
	 *
	 * @param string               $method   HTTP method (GET, POST, PUT, DELETE).
	 * @param string               $endpoint API endpoint (e.g. /contacts).
	 * @param array<string, mixed> $body     Optional request body.
	 *
	 * @return array<string, mixed>|\WP_Error Decoded JSON response, or WP_Error on
	 *   transport failure or any HTTP 400+ response. The WP_Error data array contains:
	 *   'http_status' (int) and 'error_code' (string, from Intercom's errors[0].code).
	 */
	public function request( string $method, string $endpoint, array $body = array() ) {
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization'    => 'Bearer ' . $this->token,
				'Content-Type'     => 'application/json',
				'Accept'           => 'application/json',
				'Intercom-Version' => '2.10',
			),
		);

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::get_base_url() . $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			self::log( 'error', $endpoint, $response->get_error_message() );
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$error_msg  = $decoded['errors'][0]['message'] ?? '';
			$error_code = $decoded['errors'][0]['code'] ?? '';
			$msg        = "HTTP {$code}";
			if ( $error_code ) {
				$msg .= " [{$error_code}]";
			}
			if ( $error_msg ) {
				$msg .= ": {$error_msg}";
			}
			self::log( 'error', $endpoint, $msg );

			return new \WP_Error(
				'intercom_api_error',
				$msg,
				array(
					'http_status' => $code,
					'error_code'  => $error_code,
					'error_msg'   => $error_msg,
				)
			);
		}

		self::log( 'success', $endpoint, "HTTP {$code}" );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Upsert (create or update) a contact in Intercom.
	 *
	 * Searches for an existing contact by email first. If found, updates via
	 * PUT; otherwise creates via POST. This avoids 409 conflict errors.
	 *
	 * @param array<string, mixed> $data Contact payload.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function upsert_contact( array $data ) {
		$email = $data['email'] ?? '';

		if ( $email ) {
			$search = $this->find_contact_by_email( $email );

			if ( ! is_wp_error( $search ) && ! empty( $search['data'][0]['id'] ) ) {
				$intercom_id = $search['data'][0]['id'];
				return $this->flag_failure( $this->update_contact( $intercom_id, $data ), $email, 'Contact sync' );
			}
		}

		$result = $this->request( 'POST', '/contacts', $data );

		// Search excludes archived contacts and can lag the index, so a POST may
		// still 409 even though we looked first. Intercom returns the conflicting
		// id in the error message — recover it and update in place instead.
		if ( is_wp_error( $result ) && 409 === ( $result->get_error_data()['http_status'] ?? 0 ) ) {
			$message     = (string) ( $result->get_error_data()['error_msg'] ?? '' );
			$intercom_id = self::parse_conflict_id( $message );

			if ( '' !== $intercom_id ) {
				// Archived contacts must be unarchived before they accept updates.
				if ( false !== stripos( $message, 'archived' ) ) {
					$this->unarchive_contact( $intercom_id );
				}

				return $this->flag_failure( $this->update_contact( $intercom_id, $data ), $email, 'Contact sync' );
			}
		}

		return $this->flag_failure( $result, $email, 'Contact sync' );
	}

	/**
	 * Log a contact/event sync that failed completely, naming the affected email.
	 *
	 * The low-level request() already logs the raw HTTP error against the
	 * endpoint; this adds a second, human-readable line that ties the failure to
	 * a specific customer so admins can see *who* didn't sync.
	 *
	 * @param array<string, mixed>|\WP_Error $result  The final API result.
	 * @param string                         $email   The affected contact email.
	 * @param string                         $context Short label, e.g. 'Contact sync'.
	 *
	 * @return array<string, mixed>|\WP_Error The unchanged $result, for chaining.
	 */
	private function flag_failure( $result, string $email, string $context ) {
		if ( is_wp_error( $result ) ) {
			$who = '' !== $email ? $email : '(unknown email)';
			self::log( 'error', $context, sprintf( 'Failed for %s: %s', $who, $result->get_error_message() ) );
		}

		return $result;
	}

	/**
	 * Extract the conflicting contact id from a 409 error message.
	 *
	 * Intercom phrases these as "...already exists with id=<24-hex>".
	 *
	 * @param string $message The Intercom error message.
	 *
	 * @return string The contact id, or '' when none is present.
	 */
	private static function parse_conflict_id( string $message ): string {
		if ( preg_match( '/id=([a-f0-9]{24})/i', $message, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Unarchive a previously archived contact so it can be updated.
	 *
	 * @param string $intercom_id The Intercom contact ID.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function unarchive_contact( string $intercom_id ) {
		return $this->request( 'POST', '/contacts/' . $intercom_id . '/unarchive' );
	}

	/**
	 * Update an existing contact by Intercom ID.
	 *
	 * @param string               $intercom_id The Intercom contact ID.
	 * @param array<string, mixed> $data        Fields to update.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_contact( string $intercom_id, array $data ) {
		return $this->request( 'PUT', '/contacts/' . $intercom_id, $data );
	}

	/**
	 * Search for a contact by email address.
	 *
	 * @param string $email The email to search for.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function find_contact_by_email( string $email ) {
		return $this->request(
			'POST',
			'/contacts/search',
			array(
				'query' => array(
					'field'    => 'email',
					'operator' => '=',
					'value'    => $email,
				),
			)
		);
	}

	/**
	 * Submit an event to Intercom.
	 *
	 * @param string               $email      The contact's email.
	 * @param string               $event_name The event name.
	 * @param array<string, mixed> $metadata   Optional event metadata.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_event( string $email, string $event_name, array $metadata = array() ) {
		$payload = array(
			'event_name' => $event_name,
			'created_at' => time(),
			'email'      => $email,
			'metadata'   => $metadata,
		);

		$result = $this->request( 'POST', '/events', $payload );

		// Events for an email with no matching contact return 404 "User Not Found".
		// Create a minimal contact, then replay the event once so it isn't lost.
		if ( is_wp_error( $result ) && 404 === ( $result->get_error_data()['http_status'] ?? 0 ) && $email ) {
			$contact = $this->upsert_contact(
				array(
					'role'  => 'user',
					'email' => $email,
				)
			);

			if ( ! is_wp_error( $contact ) ) {
				$result = $this->request( 'POST', '/events', $payload );
			}
		}

		return $this->flag_failure( $result, $email, 'Event: ' . $event_name );
	}

	/**
	 * Create a custom data attribute on contacts.
	 *
	 * @param string $name        Attribute name (e.g. 'woo_customer_id').
	 * @param string $type        One of: string, integer, float, boolean, date.
	 * @param string $description Human-readable description.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_data_attribute( string $name, string $type = 'string', string $description = '' ) {
		return $this->request(
			'POST',
			'/data_attributes',
			array(
				'name'        => $name,
				'model'       => 'contact',
				'data_type'   => $type,
				'description' => $description,
			)
		);
	}

	/**
	 * List the names of existing contact data attributes in the workspace.
	 *
	 * @return string[]|\WP_Error Attribute names, or WP_Error on failure.
	 */
	public function get_contact_attribute_names() {
		$result = $this->request( 'GET', '/data_attributes?model=contact' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$names = array();
		foreach ( (array) ( $result['data'] ?? array() ) as $attribute ) {
			if ( isset( $attribute['name'] ) ) {
				$names[] = (string) $attribute['name'];
			}
		}

		return $names;
	}

	/**
	 * List all data attributes for contacts.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_data_attributes() {
		return $this->request( 'GET', '/data_attributes?model=contact' );
	}

	/**
	 * Find a tag by name. Returns null if not found.
	 *
	 * @param string $name Tag name.
	 *
	 * @return array<string, mixed>|null|\WP_Error Tag object, null if not found,
	 *   or WP_Error on transport / HTTP error.
	 */
	public function find_tag_by_name( string $name ) {
		$response = $this->request( 'GET', '/tags' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $response['data'] ?? array();

		foreach ( $data as $tag ) {
			if ( isset( $tag['name'] ) && $tag['name'] === $name ) {
				return $tag;
			}
		}

		return null;
	}

	/**
	 * Create or update a tag in Intercom.
	 *
	 * Posting to /tags with just {name} creates the tag if missing,
	 * or returns the existing tag (idempotent).
	 *
	 * @param string $name Tag name.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_tag( string $name ) {
		return $this->request( 'POST', '/tags', array( 'name' => $name ) );
	}

	/**
	 * Attach a tag to one or more contacts by Intercom contact ID.
	 *
	 * Per the Intercom v2.10 API, tags are attached via POST /contacts/{id}/tags
	 * with { id: "<tag-id>" } payload (one contact at a time).
	 *
	 * @param string $contact_id The Intercom contact ID.
	 * @param string $tag_id     The Intercom tag ID (returned by create_tag).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function tag_contact( string $contact_id, string $tag_id ) {
		return $this->request(
			'POST',
			'/contacts/' . $contact_id . '/tags',
			array( 'id' => $tag_id )
		);
	}

	/**
	 * Detach a tag from a contact.
	 *
	 * @param string $contact_id The Intercom contact ID.
	 * @param string $tag_id     The Intercom tag ID.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function untag_contact( string $contact_id, string $tag_id ) {
		return $this->request(
			'DELETE',
			'/contacts/' . $contact_id . '/tags/' . $tag_id
		);
	}

	/**
	 * Get the raw access token (for HMAC computation by the Messenger module).
	 *
	 * Intentionally package-internal; callers must hold a valid module reference.
	 */
	public function get_token(): string {
		return $this->token;
	}

	/**
	 * Verify the API connection by fetching the authenticated admin.
	 *
	 * @return array<string, mixed>|\WP_Error Decoded /me response, or WP_Error on failure.
	 */
	public function test_connection() {
		if ( ! $this->has_token() ) {
			self::log( 'error', '/me', 'No access token configured.' );
			return new \WP_Error( 'intercom_no_token', 'No access token configured.' );
		}

		return $this->request( 'GET', '/me' );
	}

	/**
	 * Normalise a phone number to E.164 (+<country><number>), or '' if it can't be.
	 *
	 * Intercom rejects anything that isn't valid E.164 with a 422. The previous
	 * approach of stripping non-digits and prepending '+' turned national-format
	 * numbers (no country code) into invalid values. This:
	 *   - treats a leading '+' or '00' as an explicit country code,
	 *   - otherwise derives the country code from the order/billing country,
	 *   - and returns '' (skip the field) when it still can't be sure.
	 *
	 * @param string $phone   The raw phone string.
	 * @param string $country ISO-3166-1 alpha-2 billing country (optional).
	 *
	 * @return string E.164 phone, or '' when it can't be normalised safely.
	 */
	public static function format_phone( string $phone, string $country = '' ): string {
		$phone = trim( $phone );

		if ( '' === $phone ) {
			return '';
		}

		// International call prefix "00" is equivalent to "+".
		if ( 0 === strpos( $phone, '00' ) ) {
			$phone = '+' . substr( $phone, 2 );
		}

		$has_country = ( '' !== $phone && '+' === $phone[0] );
		$digits      = preg_replace( '/\D/', '', $phone );

		if ( '' === (string) $digits ) {
			return '';
		}

		// Explicit country code present — accept if the length is plausible.
		if ( $has_country ) {
			return ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) ? '+' . $digits : '';
		}

		// No country code: prepend the one for the billing country, dropping a
		// single national trunk "0" if present.
		$code = self::dialing_code( $country );

		if ( '' !== $code ) {
			$national = ltrim( $digits, '0' );
			$combined = $code . $national;

			return ( strlen( $combined ) >= 8 && strlen( $combined ) <= 15 ) ? '+' . $combined : '';
		}

		// Unknown country and no explicit code — too risky to guess.
		return '';
	}

	/**
	 * Map an ISO-3166-1 alpha-2 country code to its primary calling code.
	 *
	 * Covers the most common WooCommerce storefront markets; unknown countries
	 * return '' so the phone is skipped rather than mis-formatted.
	 *
	 * @param string $country ISO-3166-1 alpha-2 code (case-insensitive).
	 *
	 * @return string The calling code (digits only) or ''.
	 */
	private static function dialing_code( string $country ): string {
		$map = array(
			'US' => '1',
			'CA' => '1',
			'GB' => '44',
			'IE' => '353',
			'AU' => '61',
			'NZ' => '64',
			'IN' => '91',
			'DE' => '49',
			'FR' => '33',
			'ES' => '34',
			'IT' => '39',
			'NL' => '31',
			'BE' => '32',
			'PT' => '351',
			'SE' => '46',
			'NO' => '47',
			'DK' => '45',
			'FI' => '358',
			'CH' => '41',
			'AT' => '43',
			'PL' => '48',
			'BR' => '55',
			'MX' => '52',
			'AR' => '54',
			'ZA' => '27',
			'AE' => '971',
			'SA' => '966',
			'SG' => '65',
			'MY' => '60',
			'JP' => '81',
			'CN' => '86',
			'HK' => '852',
		);

		return $map[ strtoupper( $country ) ] ?? '';
	}

	/**
	 * Append an entry to the sync log (stored in wp_options).
	 *
	 * @param string $status  'success' or 'error'.
	 * @param string $action  The action or endpoint.
	 * @param string $msg     A human-readable message.
	 */
	public static function log( string $status, string $action, string $msg ): void {
		$log = get_option( 'etherlabz_intercom_sync_log', array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'time'   => current_time( 'mysql' ),
				'status' => $status,
				'action' => $action,
				'msg'    => $msg,
			)
		);

		// Keep the last 100 entries. Not autoloaded — the log is only read
		// on the admin screen, not on every front-end request.
		update_option( 'etherlabz_intercom_sync_log', array_slice( $log, 0, 100 ), false );
	}
}
