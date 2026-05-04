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
	 * Intercom API base URL.
	 */
	private const BASE_URL = 'https://api.intercom.io';

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
		$raw         = (string) get_option( 'iws_access_token', '' );
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
	 * @return array<string, mixed>|false Decoded JSON response or false on failure.
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

		$response = wp_remote_request( self::BASE_URL . $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			self::log( 'error', $endpoint, $response->get_error_message() );
			return false;
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
			return false;
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
	 * @return array<string, mixed>|false
	 */
	public function upsert_contact( array $data ) {
		$email = $data['email'] ?? '';

		if ( $email ) {
			$search = $this->find_contact_by_email( $email );

			if ( $search && ! empty( $search['data'][0]['id'] ) ) {
				$intercom_id = $search['data'][0]['id'];
				return $this->update_contact( $intercom_id, $data );
			}
		}

		return $this->request( 'POST', '/contacts', $data );
	}

	/**
	 * Update an existing contact by Intercom ID.
	 *
	 * @param string               $intercom_id The Intercom contact ID.
	 * @param array<string, mixed> $data        Fields to update.
	 *
	 * @return array<string, mixed>|false
	 */
	public function update_contact( string $intercom_id, array $data ) {
		return $this->request( 'PUT', '/contacts/' . $intercom_id, $data );
	}

	/**
	 * Search for a contact by email address.
	 *
	 * @param string $email The email to search for.
	 *
	 * @return array<string, mixed>|false
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
	 * @return array<string, mixed>|false
	 */
	public function create_event( string $email, string $event_name, array $metadata = array() ) {
		return $this->request(
			'POST',
			'/events',
			array(
				'event_name' => $event_name,
				'created_at' => time(),
				'email'      => $email,
				'metadata'   => $metadata,
			)
		);
	}

	/**
	 * Create a custom data attribute on contacts.
	 *
	 * @param string $name        Attribute name (e.g. 'woo_customer_id').
	 * @param string $type        One of: string, integer, float, boolean, date.
	 * @param string $description Human-readable description.
	 *
	 * @return array<string, mixed>|false
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
	 * List all data attributes for contacts.
	 *
	 * @return array<string, mixed>|false
	 */
	public function list_data_attributes() {
		return $this->request( 'GET', '/data_attributes?model=contact' );
	}

	/**
	 * Verify the API connection by fetching the authenticated admin.
	 *
	 * @return array<string, mixed>|false
	 */
	public function test_connection() {
		if ( ! $this->has_token() ) {
			self::log( 'error', '/me', 'No access token configured.' );
			return false;
		}

		return $this->request( 'GET', '/me' );
	}

	/**
	 * Append an entry to the sync log (stored in wp_options).
	 *
	 * @param string $status  'success' or 'error'.
	 * @param string $action  The action or endpoint.
	 * @param string $msg     A human-readable message.
	 */
	public static function log( string $status, string $action, string $msg ): void {
		$log = get_option( 'iws_sync_log', array() );

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

		// Keep the last 100 entries.
		update_option( 'iws_sync_log', array_slice( $log, 0, 100 ) );
	}
}
