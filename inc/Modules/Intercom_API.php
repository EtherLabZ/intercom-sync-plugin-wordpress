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

		$response = wp_remote_request( self::BASE_URL . $endpoint, $args );

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
