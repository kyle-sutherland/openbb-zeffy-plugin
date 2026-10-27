<?php
/**
 * Small WordPress HTTP client for Zeffy's contact endpoints.
 *
 * @package OpenBB_Zeffy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends contact changes to Zeffy without exposing the API key in errors.
 */
class OPENBB_Zeffy_API {
	private const CONTACTS_URL = 'https://api.zeffy.com/api/v1/contacts';

	/** @var string */
	private $api_key;

	/**
	 * @param string $api_key Zeffy organization API key.
	 */
	public function __construct( string $api_key ) {
		$this->api_key = trim( $api_key );
	}

	/**
	 * Create or update a Zeffy contact by email.
	 *
	 * Only the supported contact fields are sent. A successful response is the
	 * decoded Zeffy JSON object, including its top-level id when provided.
	 * Failures return WP_Error; its data includes status for HTTP failures and
	 * retry_after (seconds) when Zeffy supplies a valid Retry-After header.
	 *
	 * @param array $contact Contact fields from the membership integration.
	 * @return array|WP_Error
	 */
	public function upsert_contact( array $contact ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'openbb_zeffy_missing_api_key', 'Zeffy API key is not configured.' );
		}

		$payload = $this->contact_payload( $contact, true );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$body = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			return new WP_Error( 'openbb_zeffy_invalid_contact', 'Contact could not be encoded as JSON.' );
		}

		$response = $this->request( self::CONTACTS_URL, 'PUT', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status       = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status || 201 === $status ) {
			return $this->contact_response( $response );
		}

		return $this->response_error( $response );
	}

	/**
	 * Find one Zeffy contact by exact email address.
	 *
	 * @param string $email Email address to find.
	 * @return array|null|WP_Error The matching contact, null, or an error.
	 */
	public function find_contact_by_email( string $email ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'openbb_zeffy_missing_api_key', 'Zeffy API key is not configured.' );
		}

		$email = trim( $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'openbb_zeffy_invalid_email', 'A valid contact email is required.' );
		}

		$response = $this->request( self::CONTACTS_URL . '?email=' . rawurlencode( $email ), 'GET' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $this->response_error( $response );
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = is_string( $body ) ? json_decode( $body, true ) : null;
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			return new WP_Error( 'openbb_zeffy_invalid_response', 'Zeffy returned an invalid contact list.', array( 'status' => 200 ) );
		}

		foreach ( $decoded['data'] as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$emails = array();
			foreach ( array( 'email', 'secondary_email', 'secondaryEmail' ) as $field ) {
				if ( isset( $candidate[ $field ] ) && is_string( $candidate[ $field ] ) ) {
					$emails[] = $candidate[ $field ];
				}
			}
			foreach ( array( 'secondary_emails', 'secondaryEmails' ) as $field ) {
				if ( isset( $candidate[ $field ] ) && is_array( $candidate[ $field ] ) ) {
					$emails = array_merge( $emails, $candidate[ $field ] );
				}
			}

			foreach ( $emails as $candidate_email ) {
				if ( is_string( $candidate_email ) && 0 === strcasecmp( trim( $candidate_email ), $email ) ) {
					return $candidate;
				}
			}
		}

		return null;
	}

	/**
	 * Update an existing Zeffy contact, including an email address change.
	 *
	 * @param string $contact_id Zeffy contact UUID.
	 * @param array  $contact    Supported fields to update.
	 * @return array|WP_Error Decoded contact object or an error.
	 */
	public function update_contact( string $contact_id, array $contact ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'openbb_zeffy_missing_api_key', 'Zeffy API key is not configured.' );
		}

		$contact_id = trim( $contact_id );
		if ( ! $this->valid_contact_id( $contact_id ) ) {
			return new WP_Error( 'openbb_zeffy_invalid_contact_id', 'A valid Zeffy contact ID is required.' );
		}

		$payload = $this->contact_payload( $contact, false );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$body = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			return new WP_Error( 'openbb_zeffy_invalid_contact', 'Contact could not be encoded as JSON.' );
		}

		$response = $this->request( self::CONTACTS_URL . '/' . rawurlencode( $contact_id ), 'PATCH', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			return $this->contact_response( $response );
		}

		return $this->response_error( $response );
	}

	/**
	 * Permanently delete a Zeffy contact by its UUID.
	 *
	 * A 204 response confirms deletion. A 404 remains an
	 * error so the caller can decide whether an already-absent contact is safe to
	 * treat as synchronized.
	 *
	 * @param string $contact_id Zeffy contact UUID.
	 * @return true|WP_Error
	 */
	public function delete_contact( string $contact_id ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'openbb_zeffy_missing_api_key', 'Zeffy API key is not configured.' );
		}

		$contact_id = trim( $contact_id );
		if ( ! $this->valid_contact_id( $contact_id ) ) {
			return new WP_Error( 'openbb_zeffy_invalid_contact_id', 'A valid Zeffy contact ID is required.' );
		}

		$response = $this->request( self::CONTACTS_URL . '/' . rawurlencode( $contact_id ), 'DELETE' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 204 === (int) wp_remote_retrieve_response_code( $response ) ) {
			return true;
		}

		return $this->response_error( $response );
	}

	/**
	 * Keep request bodies to Zeffy's supported contact fields.
	 *
	 * @param array $contact       Contact values.
	 * @param bool  $require_email Whether email must be supplied.
	 * @return array|WP_Error
	 */
	private function contact_payload( array $contact, $require_email ) {
		$payload = array();
		if ( array_key_exists( 'email', $contact ) ) {
			if ( ! is_string( $contact['email'] ) ) {
				return new WP_Error( 'openbb_zeffy_invalid_email', 'A valid contact email is required.' );
			}

			$email = trim( $contact['email'] );
			if ( '' === $email || ! is_email( $email ) ) {
				return new WP_Error( 'openbb_zeffy_invalid_email', 'A valid contact email is required.' );
			}

			$payload['email'] = $email;
		} elseif ( $require_email ) {
			return new WP_Error( 'openbb_zeffy_invalid_email', 'A valid contact email is required.' );
		}

		foreach ( array( 'first_name', 'last_name', 'phone_number' ) as $field ) {
			if ( ! array_key_exists( $field, $contact ) ) {
				continue;
			}
			if ( null === $contact[ $field ] ) {
				$payload[ $field ] = null;
				continue;
			}

			if ( ! is_string( $contact[ $field ] ) ) {
				return new WP_Error( 'openbb_zeffy_invalid_contact', 'Contact fields must be text.', array( 'field' => $field ) );
			}

			$payload[ $field ] = trim( $contact[ $field ] );
		}

		if ( empty( $payload ) ) {
			return new WP_Error( 'openbb_zeffy_invalid_contact', 'At least one supported contact field is required.' );
		}

		return $payload;
	}

	/**
	 * Check an ID before placing it in an API URL path.
	 *
	 * @param string $contact_id Zeffy contact UUID.
	 * @return bool
	 */
	private function valid_contact_id( $contact_id ) {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $contact_id );
	}

	/**
	 * Decode a successful single-contact JSON response.
	 *
	 * @param array $response WordPress HTTP response.
	 * @return array|WP_Error
	 */
	private function contact_response( array $response ) {
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$decoded = is_string( $body ) ? json_decode( $body, true ) : null;
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() || '{' !== substr( ltrim( $body ), 0, 1 ) ) {
			return new WP_Error( 'openbb_zeffy_invalid_response', 'Zeffy returned an invalid contact response.', array( 'status' => $status ) );
		}

		return $decoded;
	}

	/**
	 * Make a contact request with credentials confined to the request header.
	 *
	 * @param string      $url    Zeffy API URL.
	 * @param string      $method HTTP method.
	 * @param string|null $body   Optional JSON body.
	 * @return array|WP_Error
	 */
	private function request( $url, $method, $body = null ) {
		$args = array(
			'method'      => $method,
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $body;
		}

		$response = wp_remote_request( $url, $args );

		// Transport errors may include implementation details, so do not return
		// the raw WP_Error message or request arguments to callers or logs.
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return new WP_Error( 'openbb_zeffy_transport_error', 'Could not connect to Zeffy.' );
		}

		return $response;
	}

	/**
	 * Convert an unsuccessful HTTP response into a safe, structured error.
	 *
	 * @param array $response WordPress HTTP response.
	 * @return WP_Error
	 */
	private function response_error( array $response ) {
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = array( 'status' => $status );
		if ( 429 === $status ) {
			$retry_after = $this->parse_retry_after( wp_remote_retrieve_header( $response, 'retry-after' ) );
			if ( null !== $retry_after ) {
				$data['retry_after'] = $retry_after;
			}
		}

		// Zeffy documents a stable error envelope. Keep only known error codes;
		// an unexpected response must never echo the API key to a caller.
		$response_body = wp_remote_retrieve_body( $response );
		$decoded       = is_string( $response_body ) ? json_decode( $response_body, true ) : null;
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			$known_codes = array(
				'invalid_parameters',
				'invalid_request',
				'invalid_api_key',
				'organization_not_verified',
				'resource_not_found',
				'rate_limit_exceeded',
				'internal_error',
			);
			$api_code = isset( $decoded['error']['code'] ) ? $decoded['error']['code'] : null;
			if ( is_string( $api_code ) && in_array( $api_code, $known_codes, true ) ) {
				$data['api_code'] = $api_code;
			}
		}

		if ( 429 === $status ) {
			return new WP_Error( 'openbb_zeffy_rate_limited', 'Zeffy rate limit reached.', $data );
		}

		if ( 401 === $status ) {
			return new WP_Error( 'openbb_zeffy_api_error', 'Zeffy authentication failed.', $data );
		}

		return new WP_Error( 'openbb_zeffy_api_error', 'Zeffy rejected the contact request.', $data );
	}

	/**
	 * Convert a Retry-After header to nonnegative seconds.
	 *
	 * @param mixed $header Header value from the WordPress HTTP API.
	 * @return int|null
	 */
	private function parse_retry_after( $header ) {
		if ( ! is_string( $header ) ) {
			return null;
		}

		$header = trim( $header );
		if ( '' === $header ) {
			return null;
		}

		if ( ctype_digit( $header ) ) {
			return (int) $header;
		}

		$timestamp = strtotime( $header );
		return false === $timestamp ? null : max( 0, $timestamp - time() );
	}
}
