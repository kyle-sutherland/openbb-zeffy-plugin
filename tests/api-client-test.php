<?php
/**
 * Standalone HTTP mock tests for the Zeffy API client.
 *
 * Run: php tests/api-client-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code, $message, $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function is_email( $email ) {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

$GLOBALS['zeffy_responses'] = array();
$GLOBALS['zeffy_requests']  = array();

function wp_remote_request( $url, $args ) {
	$GLOBALS['zeffy_requests'][] = array( $url, $args );
	if ( empty( $GLOBALS['zeffy_responses'] ) ) {
		throw new RuntimeException( 'Unexpected HTTP request.' );
	}

	return array_shift( $GLOBALS['zeffy_responses'] );
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function wp_remote_retrieve_header( $response, $name ) {
	foreach ( $response['headers'] as $key => $value ) {
		if ( 0 === strcasecmp( $key, $name ) ) {
			return $value;
		}
	}
	return '';
}

require_once dirname( __DIR__ ) . '/includes/class-openbb-zeffy-api.php';

function zeffy_response( $status, $body = '', $headers = array() ) {
	return array(
		'response' => array( 'code' => $status ),
		'headers'  => $headers,
		'body'     => $body,
	);
}

function zeffy_mock( $response ) {
	$GLOBALS['zeffy_responses'][] = $response;
}

function zeffy_last_request() {
	return end( $GLOBALS['zeffy_requests'] );
}

function zeffy_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function zeffy_same( $expected, $actual, $message ) {
	zeffy_assert( $expected === $actual, $message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

$key = 'test-secret-key';
$api = new OPENBB_Zeffy_API( $key );
$id  = '9f8b7c6d-5e4f-3a2b-1c0d-9e8f7a6b5c4d';

zeffy_mock( zeffy_response( 201, json_encode( array( 'id' => $id, 'email' => 'jane@example.com' ) ) ) );
$created = $api->upsert_contact(
	array(
		'email'        => ' jane@example.com ',
		'first_name'   => 'Jane',
		'last_name'    => 'Doe',
		'phone_number' => '555-555-0100',
		'admin_notes'  => 'must not reach Zeffy',
	)
);
zeffy_same( $id, $created['id'], 'PUT should return the contact object.' );
list( $url, $args ) = zeffy_last_request();
zeffy_same( 'https://api.zeffy.com/api/v1/contacts', $url, 'PUT endpoint mismatch.' );
zeffy_same( 'PUT', $args['method'], 'Upsert must use PUT.' );
zeffy_same( 'Bearer ' . $key, $args['headers']['Authorization'], 'Bearer header missing.' );
zeffy_same( 'application/json', $args['headers']['Content-Type'], 'JSON content type missing.' );
zeffy_same(
	array( 'email' => 'jane@example.com', 'first_name' => 'Jane', 'last_name' => 'Doe', 'phone_number' => '555-555-0100' ),
	json_decode( $args['body'], true ),
	'PUT must send only supported fields.'
);
zeffy_assert( false === strpos( $args['body'], $key ), 'API key leaked into body.' );

$request_count = count( $GLOBALS['zeffy_requests'] );
$invalid       = $api->upsert_contact( array( 'email' => 'bad address' ) );
zeffy_same( 'openbb_zeffy_invalid_email', $invalid->get_error_code(), 'Invalid email should be rejected.' );
zeffy_same( $request_count, count( $GLOBALS['zeffy_requests'] ), 'Invalid email made an HTTP request.' );

zeffy_mock( new WP_Error( 'http_error', 'Leaked ' . $key ) );
$transport = $api->upsert_contact( array( 'email' => 'jane@example.com' ) );
zeffy_same( 'openbb_zeffy_transport_error', $transport->get_error_code(), 'Transport error code mismatch.' );
zeffy_assert( false === strpos( $transport->get_error_message(), $key ), 'Transport error leaked the API key.' );

zeffy_mock( zeffy_response( 401, json_encode( array( 'error' => array( 'code' => 'invalid_api_key', 'message' => 'Rejected ' . $key ) ) ) ) );
$auth = $api->upsert_contact( array( 'email' => 'jane@example.com' ) );
zeffy_same( 401, $auth->get_error_data()['status'], 'HTTP error status missing.' );
zeffy_same( 'invalid_api_key', $auth->get_error_data()['api_code'], 'Safe API code missing.' );
zeffy_assert( false === strpos( $auth->get_error_message(), $key ), 'API error leaked the API key.' );
zeffy_assert( false === strpos( json_encode( $auth->get_error_data() ), $key ), 'API error data leaked the API key.' );

zeffy_mock( zeffy_response( 429, json_encode( array( 'error' => array( 'code' => 'rate_limit_exceeded' ) ) ), array( 'Retry-After' => '60' ) ) );
$limited = $api->upsert_contact( array( 'email' => 'jane@example.com' ) );
zeffy_same( 'openbb_zeffy_rate_limited', $limited->get_error_code(), 'Rate limit error code mismatch.' );
zeffy_same( 429, $limited->get_error_data()['status'], 'Rate limit status missing.' );
zeffy_same( 60, $limited->get_error_data()['retry_after'], 'Retry-After seconds missing.' );

zeffy_mock( zeffy_response( 200, 'not json' ) );
$malformed = $api->upsert_contact( array( 'email' => 'jane@example.com' ) );
zeffy_same( 'openbb_zeffy_invalid_response', $malformed->get_error_code(), 'Malformed success body should be rejected.' );

zeffy_mock( zeffy_response( 204 ) );
$deleted = $api->delete_contact( $id );
zeffy_same( true, $deleted, '204 should confirm deletion.' );
list( $url, $args ) = zeffy_last_request();
zeffy_same( 'https://api.zeffy.com/api/v1/contacts/' . $id, $url, 'DELETE endpoint mismatch.' );
zeffy_same( 'DELETE', $args['method'], 'Deletion must use DELETE.' );
zeffy_assert( ! array_key_exists( 'body', $args ), 'DELETE must have no body.' );

zeffy_mock( zeffy_response( 404, json_encode( array( 'error' => array( 'code' => 'resource_not_found' ) ) ) ) );
$missing = $api->delete_contact( $id );
zeffy_same( 404, $missing->get_error_data()['status'], '404 must remain an error.' );

zeffy_mock( zeffy_response( 429, '{}', array( 'Retry-After' => '1' ) ) );
$delete_limited = $api->delete_contact( $id );
zeffy_same( 429, $delete_limited->get_error_data()['status'], 'DELETE rate-limit status missing.' );
zeffy_same( 1, $delete_limited->get_error_data()['retry_after'], 'DELETE Retry-After missing.' );

$request_count = count( $GLOBALS['zeffy_requests'] );
$invalid_id    = $api->delete_contact( '../contacts' );
zeffy_same( 'openbb_zeffy_invalid_contact_id', $invalid_id->get_error_code(), 'Unsafe ID should be rejected.' );
zeffy_same( $request_count, count( $GLOBALS['zeffy_requests'] ), 'Unsafe ID made an HTTP request.' );

zeffy_mock( zeffy_response( 200, json_encode( array( 'data' => array( array( 'id' => 'other', 'email' => 'jane+other@example.com' ), array( 'id' => $id, 'email' => 'Jane@Example.com' ) ), 'has_more' => false ) ) ) );
$found = $api->find_contact_by_email( 'jane@example.com' );
zeffy_same( $id, $found['id'], 'Lookup should return only an exact email match.' );
list( $url, $args ) = zeffy_last_request();
zeffy_same( 'https://api.zeffy.com/api/v1/contacts?email=jane%40example.com', $url, 'GET email filter mismatch.' );
zeffy_same( 'GET', $args['method'], 'Lookup must use GET.' );
zeffy_assert( ! array_key_exists( 'body', $args ), 'GET must have no body.' );

zeffy_mock( zeffy_response( 200, json_encode( array( 'data' => array( array( 'email' => 'jane+other@example.com' ) ), 'has_more' => false ) ) ) );
zeffy_same( null, $api->find_contact_by_email( 'jane@example.com' ), 'Lookup should not accept a fuzzy match.' );

zeffy_mock( zeffy_response( 200, json_encode( array( 'data' => array( array( 'id' => $id, 'email' => 'primary@example.com', 'secondary_email' => 'jane@example.com' ) ), 'has_more' => false ) ) ) );
$found_secondary = $api->find_contact_by_email( 'jane@example.com' );
zeffy_same( $id, $found_secondary['id'], 'Lookup should accept an exact secondary email match.' );

zeffy_mock( zeffy_response( 200, json_encode( array( 'id' => $id, 'email' => 'new@example.com' ) ) ) );
$updated = $api->update_contact( $id, array( 'email' => 'new@example.com', 'first_name' => 'Jane', 'phone_number' => null, 'unknown' => 'drop' ) );
zeffy_same( 'new@example.com', $updated['email'], 'PATCH should return the contact object.' );
list( $url, $args ) = zeffy_last_request();
zeffy_same( 'https://api.zeffy.com/api/v1/contacts/' . $id, $url, 'PATCH endpoint mismatch.' );
zeffy_same( 'PATCH', $args['method'], 'Update must use PATCH.' );
zeffy_same( array( 'email' => 'new@example.com', 'first_name' => 'Jane', 'phone_number' => null ), json_decode( $args['body'], true ), 'PATCH must send only supported fields and use null to clear values.' );

echo "Zeffy API client HTTP mock tests passed.\n";
