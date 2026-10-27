<?php
/**
 * Standalone WordPress and API mocks for membership synchronization.
 *
 * Run: php tests/sync-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'OPENBB_ZEFFY_API_KEY', 'test-key' );

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

class OPENBB_Access {
	const STATUS_ACTIVE = 'active';
}

class OPENBB_Member {
	public static function get( $user_id ) {
		if ( get_current_blog_id() !== 1 ) {
			return $GLOBALS['members_by_blog'][ get_current_blog_id() ][ $user_id ] ?? null;
		}
		return isset( $GLOBALS['members'][ $user_id ] ) ? $GLOBALS['members'][ $user_id ] : null;
	}
}

class OPENBB_Profile_Fields {
	const TYPE_BOOLEAN = 'boolean';
	const VISIBILITY_MEMBER = 'member';
	const CONTEXT_STAFF = 'staff';

	public static function get_fields() {
		return ! empty( $GLOBALS['newsletter_field_configured'] )
			? array( 'newsletter' => array( 'type' => 'boolean', 'visibility' => 'member', 'archived' => false ) )
			: array();
	}

	public static function get_values( $user_id, $context ) {
		if ( get_current_blog_id() !== 1 ) {
			return array( 'newsletter' => ! empty( $GLOBALS['newsletter_consent_by_blog'][ get_current_blog_id() ][ $user_id ] ) );
		}
		return array( 'newsletter' => ! empty( $GLOBALS['newsletter_consent'][ $user_id ] ) );
	}
}

class OPENBB_Zeffy_API {
	public static $calls = array();
	public static $results = array();

	public function __construct( $api_key ) {
		sync_same( 'test-key', $api_key, 'Sync should use the configured API key.' );
	}

	private function result( $method, $args ) {
		self::$calls[] = array( 'method' => $method, 'args' => $args );
		if ( empty( self::$results[ $method ] ) ) {
			throw new RuntimeException( 'Unexpected Zeffy API call: ' . $method );
		}
		return array_shift( self::$results[ $method ] );
	}

	public function upsert_contact( $contact ) {
		return $this->result( 'upsert', array( $contact ) );
	}

	public function update_contact( $contact_id, $contact ) {
		return $this->result( 'update', array( $contact_id, $contact ) );
	}

	public function find_contact_by_email( $email ) {
		return $this->result( 'find', array( $email ) );
	}

	public function delete_contact( $contact_id ) {
		return $this->result( 'delete', array( $contact_id ) );
	}
}

$GLOBALS['users']   = array();
$GLOBALS['members'] = array();
$GLOBALS['meta']    = array();
$GLOBALS['options'] = array();
$GLOBALS['events']  = array();
$GLOBALS['hooks']   = array();
$GLOBALS['current_blog_id'] = 1;
$GLOBALS['blog_stack'] = array();
$GLOBALS['multisite'] = false;
$GLOBALS['site_ids'] = array( 1, 2 );

class Sync_Test_WPDB {
	public function get_blog_prefix( $blog_id = null ) {
		$blog_id = null === $blog_id ? get_current_blog_id() : (int) $blog_id;
		return 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_';
	}
}

$GLOBALS['wpdb'] = new Sync_Test_WPDB();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['hooks'][ $hook ][] = array( $callback, $priority, $accepted_args );
}

function get_userdata( $user_id ) {
	return isset( $GLOBALS['users'][ $user_id ] ) ? $GLOBALS['users'][ $user_id ] : false;
}

function get_users( $args ) {
	$result = array();
	foreach ( $GLOBALS['meta'] as $user_id => $values ) {
		if ( isset( $values[ $args['meta_key'] ] )
			&& ( ! array_key_exists( 'meta_value', $args ) || $values[ $args['meta_key'] ] === $args['meta_value'] ) ) {
			$result[] = (int) $user_id;
		}
	}
	return $result;
}

function get_sites( $args ) {
	return $GLOBALS['site_ids'];
}

function switch_to_blog( $blog_id ) {
	$GLOBALS['blog_stack'][] = $GLOBALS['current_blog_id'];
	$GLOBALS['current_blog_id'] = (int) $blog_id;
	return true;
}

function restore_current_blog() {
	$GLOBALS['current_blog_id'] = array_pop( $GLOBALS['blog_stack'] );
}

function is_user_member_of_blog( $user_id, $blog_id = null ) {
	$blog_id = null === $blog_id ? get_current_blog_id() : (int) $blog_id;
	return 1 === $blog_id ? isset( $GLOBALS['members'][ $user_id ] ) : isset( $GLOBALS['members_by_blog'][ $blog_id ][ $user_id ] );
}

function get_user_meta( $user_id, $key, $single = false ) {
	if ( ! isset( $GLOBALS['meta'][ $user_id ] ) || ! array_key_exists( $key, $GLOBALS['meta'][ $user_id ] ) ) {
		return $single ? '' : array();
	}
	return $single ? $GLOBALS['meta'][ $user_id ][ $key ] : array( $GLOBALS['meta'][ $user_id ][ $key ] );
}

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['meta'][ $user_id ][ $key ] = $value;
	return true;
}

function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['meta'][ $user_id ][ $key ] );
	return true;
}

function openbb_member_meta_key( $field ) {
	return 'wp_openbb_' . $field;
}

function is_multisite() {
	return $GLOBALS['multisite'];
}

function get_current_blog_id() {
	return $GLOBALS['current_blog_id'];
}

function is_email( $email ) {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}

function sanitize_email( $email ) {
	return trim( $email );
}

function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-z0-9_]/', '', $key ) );
}

function openbb_is_placeholder_email( $email ) {
	return 1 === preg_match( '/\.invalid$/i', $email );
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function get_option( $key ) {
	return isset( $GLOBALS['options'][ $key ] ) ? $GLOBALS['options'][ $key ] : false;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['options'][ $key ] );
	return true;
}

function wp_next_scheduled( $hook, $args ) {
	foreach ( $GLOBALS['events'] as $event ) {
		if ( $hook === $event['hook'] && $args === $event['args'] ) {
			return $event['timestamp'];
		}
	}
	return false;
}

function wp_schedule_single_event( $timestamp, $hook, $args, $wp_error = false ) {
	if ( ! empty( $GLOBALS['cron_failure'] ) ) {
		return $wp_error ? new WP_Error( 'cron_failed', 'Cron rejected the event.' ) : false;
	}
	$GLOBALS['events'][] = array( 'timestamp' => $timestamp, 'hook' => $hook, 'args' => $args );
	return true;
}

function sync_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function sync_same( $expected, $actual, $message ) {
	sync_assert( $expected === $actual, $message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

function sync_member( $email = 'jane@example.com', $status = 'active' ) {
	return (object) array(
		'email'      => $email,
		'first_name' => 'Jane',
		'last_name'  => 'Doe',
		'phone'      => '555-555-0100',
		'status'     => $status,
	);
}

require_once dirname( __DIR__ ) . '/includes/class-openbb-zeffy-sync.php';

$id = '9f8b7c6d-5e4f-3a2b-1c0d-9e8f7a6b5c4d';
$GLOBALS['users'][7]   = (object) array( 'ID' => 7, 'user_email' => 'jane@example.com' );
$GLOBALS['members'][7] = sync_member();
$GLOBALS['newsletter_field_configured'] = true;
$GLOBALS['newsletter_consent'][7] = false;

OPENBB_Zeffy_Sync::register_hooks();
sync_assert( isset( $GLOBALS['hooks']['openbb_member_created'] ), 'Member creation hook missing.' );
sync_assert( isset( $GLOBALS['hooks']['openbb_member_status_changed'] ), 'Status change hook missing.' );
sync_assert( isset( $GLOBALS['hooks']['openbb_profile_values_changed'] ), 'Newsletter profile change hook missing.' );
sync_assert( isset( $GLOBALS['hooks']['openbb_newsletter_consent_changed'] ), 'Direct signup consent hook missing.' );
sync_assert( isset( $GLOBALS['hooks']['delete_user_meta'] ), 'Bulk user meta deletion hook missing.' );
sync_assert( isset( $GLOBALS['hooks']['added_user_meta'] ), 'First-time profile meta additions must be observed.' );
sync_assert( isset( $GLOBALS['hooks']['delete_user'] ), 'User deletion hook missing.' );

OPENBB_Zeffy_Sync::queue_member( 7 );
sync_same( OPENBB_Zeffy_Sync::SYNC_HOOK, $GLOBALS['events'][0]['hook'], 'Member event should be queued.' );
sync_same( array( 7, 0 ), $GLOBALS['events'][0]['args'], 'Member event arguments mismatch.' );

OPENBB_Zeffy_Sync::sync_member( 7 );
sync_same( 0, count( OPENBB_Zeffy_API::$calls ), 'Unchecked newsletter consent must not create a contact.' );
$GLOBALS['newsletter_field_configured'] = false;
OPENBB_Zeffy_Sync::sync_member( 7 );
sync_same( 0, count( OPENBB_Zeffy_API::$calls ), 'A missing consent field must not create a contact.' );
sync_same( 'openbb_zeffy_consent_missing', $GLOBALS['options'][ OPENBB_Zeffy_Sync::ERROR_OPTION ]['code'], 'Missing consent source should be visible to administrators.' );

$GLOBALS['users'][10] = (object) array( 'ID' => 10, 'user_email' => 'lee@example.com' );
$GLOBALS['members'][10] = sync_member( 'lee@example.com' );
OPENBB_Zeffy_Sync::on_newsletter_consent_changed( 10, '1' );
sync_same( '', get_user_meta( 10, 'wp_openbb_zeffy_consent', true ), 'The direct consent hook must require a boolean.' );
OPENBB_Zeffy_Sync::on_newsletter_consent_changed( 10, false );
sync_same( '0', get_user_meta( 10, 'wp_openbb_zeffy_consent', true ), 'Unchecked direct consent should be saved explicitly.' );
OPENBB_Zeffy_Sync::sync_member( 10 );
sync_same( 0, count( OPENBB_Zeffy_API::$calls ), 'Unchecked direct consent must not create a contact.' );
OPENBB_Zeffy_Sync::on_newsletter_consent_changed( 10, true );
OPENBB_Zeffy_API::$results['upsert'][] = array( 'id' => $id, 'email' => 'lee@example.com' );
OPENBB_Zeffy_Sync::sync_member( 10 );
sync_same( 'upsert', end( OPENBB_Zeffy_API::$calls )['method'], 'Opted-in direct consent should create a contact without a profile field.' );
OPENBB_Zeffy_Sync::on_newsletter_consent_changed( 10, false );
$api_call_count = count( OPENBB_Zeffy_API::$calls );
OPENBB_Zeffy_Sync::sync_member( 10 );
sync_same( $api_call_count, count( OPENBB_Zeffy_API::$calls ), 'Withdrawing direct consent should pause updates.' );
sync_same( $id, get_user_meta( 10, 'wp_openbb_zeffy_contact_id', true ), 'Withdrawing direct consent should retain the contact mapping.' );
$GLOBALS['members'][10]->status = 'inactive';
OPENBB_Zeffy_API::$results['delete'][] = true;
OPENBB_Zeffy_Sync::sync_member( 10 );
OPENBB_Zeffy_API::$calls = array();

$GLOBALS['users'][11] = (object) array( 'ID' => 11, 'user_email' => 'pat@example.com' );
$GLOBALS['members'][11] = sync_member( 'pat@example.com', 'applicant' );
OPENBB_Zeffy_Sync::on_newsletter_consent_changed( 11, true );
OPENBB_Zeffy_Sync::sync_member( 11 );
sync_same( 0, count( OPENBB_Zeffy_API::$calls ), 'An opted-in applicant must wait for active membership.' );
sync_same( '1', get_user_meta( 11, 'wp_openbb_zeffy_consent', true ), 'Applicant consent must be retained for later approval.' );
$GLOBALS['members'][11]->status = 'active';
$applicant_id = '11111111-1111-1111-1111-111111111111';
OPENBB_Zeffy_API::$results['upsert'][] = array( 'id' => $applicant_id, 'email' => 'pat@example.com' );
OPENBB_Zeffy_Sync::sync_member( 11 );
sync_same( $applicant_id, get_user_meta( 11, 'wp_openbb_zeffy_contact_id', true ), 'Approval should create the opted-in applicant contact.' );
OPENBB_Zeffy_API::$calls = array();

$GLOBALS['newsletter_field_configured'] = true;
$GLOBALS['newsletter_consent'][7] = true;
OPENBB_Zeffy_Sync::on_profile_values_changed( 7, array( 'newsletter' => true ) );

OPENBB_Zeffy_API::$results['upsert'][] = array( 'id' => $id, 'email' => 'jane@example.com' );
OPENBB_Zeffy_Sync::sync_member( 7 );
sync_same( 'upsert', OPENBB_Zeffy_API::$calls[0]['method'], 'Active member should be upserted.' );
sync_same( $id, get_user_meta( 7, 'wp_openbb_zeffy_contact_id', true ), 'Zeffy ID should be saved.' );
sync_same( 'jane@example.com', get_user_meta( 7, 'wp_openbb_zeffy_email', true ), 'Synced email should be saved.' );
$api_call_count = count( OPENBB_Zeffy_API::$calls );
OPENBB_Zeffy_Sync::sync_member( 7 );
sync_same( $api_call_count, count( OPENBB_Zeffy_API::$calls ), 'Identical member data should not trigger another API request.' );

$GLOBALS['events'] = array();
OPENBB_Zeffy_Sync::on_user_meta_change( 1, 7, openbb_member_meta_key( 'phone' ), '555-555-0101' );
sync_same( OPENBB_Zeffy_Sync::SYNC_HOOK, $GLOBALS['events'][0]['hook'], 'Phone edit should queue sync.' );
$GLOBALS['members'][7]->phone = '';
OPENBB_Zeffy_API::$results['upsert'][] = array( 'id' => $id, 'email' => 'jane@example.com' );
OPENBB_Zeffy_Sync::sync_member( 7 );
sync_same( null, end( OPENBB_Zeffy_API::$calls )['args'][0]['phone_number'], 'Cleared member phone should be sent as null to Zeffy.' );

$GLOBALS['members'][7]->status = 'inactive';
OPENBB_Zeffy_Sync::on_member_status_changed( 7, 'inactive', 'active' );
OPENBB_Zeffy_API::$results['delete'][] = true;
OPENBB_Zeffy_Sync::sync_member( 7 );
sync_same( 'delete', end( OPENBB_Zeffy_API::$calls )['method'], 'Inactive member contact should be deleted.' );
sync_same( $id, end( OPENBB_Zeffy_API::$calls )['args'][0], 'Deletion should target the saved Zeffy ID.' );
sync_same( '', get_user_meta( 7, 'wp_openbb_zeffy_contact_id', true ), 'Deletion should clear the saved Zeffy ID.' );

$GLOBALS['members'][7] = sync_member( 'jane@example.com', 'active' );
OPENBB_Zeffy_Sync::on_member_status_changed( 7, 'active', 'inactive' );
$GLOBALS['members'][7]->status = 'inactive';
OPENBB_Zeffy_Sync::on_member_status_changed( 7, 'inactive', 'active' );
$api_call_count = count( OPENBB_Zeffy_API::$calls );
OPENBB_Zeffy_Sync::sync_member( 7 );
sync_same( $api_call_count, count( OPENBB_Zeffy_API::$calls ), 'Deactivation without a saved ID must not delete an unrelated Zeffy contact.' );

$GLOBALS['users'][8]   = (object) array( 'ID' => 8, 'user_email' => 'sam@example.com' );
$GLOBALS['members'][8] = sync_member( 'sam@example.com' );
$GLOBALS['newsletter_consent'][8] = true;
update_user_meta( 8, 'wp_openbb_zeffy_contact_id', $id );
$GLOBALS['newsletter_consent'][8] = false;
$api_call_count = count( OPENBB_Zeffy_API::$calls );
OPENBB_Zeffy_Sync::sync_member( 8 );
sync_same( $api_call_count, count( OPENBB_Zeffy_API::$calls ), 'Opting out should pause contact updates without deleting the contact.' );
sync_same( $id, get_user_meta( 8, 'wp_openbb_zeffy_contact_id', true ), 'Opting out should retain the Zeffy ID for later membership removal.' );
$GLOBALS['members'][8]->status = 'inactive';
OPENBB_Zeffy_API::$results['delete'][] = true;
OPENBB_Zeffy_Sync::sync_member( 8 );
sync_same( 'delete', end( OPENBB_Zeffy_API::$calls )['method'], 'Membership removal should delete a previously linked contact even after opt-out.' );
sync_same( '', get_user_meta( 8, 'wp_openbb_zeffy_contact_id', true ), 'Membership removal should clear the saved Zeffy ID.' );

$GLOBALS['events'] = array();
OPENBB_Zeffy_Sync::on_user_delete( 7 );
sync_same( 0, count( $GLOBALS['events'] ), 'Unmapped account deletion must not delete an unrelated donor contact.' );

update_user_meta( 7, 'wp_openbb_zeffy_contact_id', $id );
OPENBB_Zeffy_Sync::on_user_delete( 7 );
sync_same( OPENBB_Zeffy_Sync::DELETE_HOOK, $GLOBALS['events'][0]['hook'], 'Mapped account deletion should queue contact deletion.' );
sync_same( array( $id, 7, 1, 0 ), $GLOBALS['events'][0]['args'], 'Mapped deletion must capture the contact ID before WordPress erases meta.' );
unset( $GLOBALS['users'][7], $GLOBALS['members'][7] );
OPENBB_Zeffy_API::$results['delete'][] = true;
OPENBB_Zeffy_Sync::delete_removed_contact( $id, 7, 1 );
sync_same( 'delete', end( OPENBB_Zeffy_API::$calls )['method'], 'Removed account should delete its mapped contact.' );

$GLOBALS['users'][7]   = (object) array( 'ID' => 7, 'user_email' => 'jane@example.com' );
$GLOBALS['members'][7] = sync_member();
$GLOBALS['meta'][7]    = array();
$GLOBALS['events']     = array();
$GLOBALS['newsletter_consent'][7] = true;
OPENBB_Zeffy_API::$results['upsert'][] = new WP_Error( 'openbb_zeffy_rate_limited', 'Zeffy rate limit reached.', array( 'status' => 429, 'retry_after' => 120 ) );
OPENBB_Zeffy_Sync::sync_member( 7 );
sync_same( array( 7, 1 ), $GLOBALS['events'][0]['args'], 'Rate-limited sync should queue its first retry.' );
sync_assert( $GLOBALS['events'][0]['timestamp'] >= time() + 120, 'Retry must respect Zeffy Retry-After.' );

$GLOBALS['events'] = array();
OPENBB_Zeffy_API::$results['upsert'][] = new WP_Error( 'openbb_zeffy_api_error', 'Temporary Zeffy failure.', array( 'status' => 503 ) );
OPENBB_Zeffy_Sync::sync_member( 7, 5 );
sync_same( array( 7, 5 ), $GLOBALS['events'][0]['args'], 'Transient errors must keep retrying after the initial backoff sequence.' );
sync_assert( $GLOBALS['events'][0]['timestamp'] >= time() + 21600, 'Repeated transient errors should use capped six-hour backoff.' );

$GLOBALS['events'] = array();
$GLOBALS['cron_failure'] = true;
OPENBB_Zeffy_Sync::queue_member( 7 );
sync_same( 'openbb_zeffy_schedule_failed', $GLOBALS['options'][ OPENBB_Zeffy_Sync::ERROR_OPTION ]['code'], 'Cron scheduling failure must be visible to administrators.' );
$GLOBALS['cron_failure'] = false;

$GLOBALS['users'][9] = (object) array( 'ID' => 9, 'user_email' => 'alex@example.com' );
$GLOBALS['members'][9] = sync_member( 'alex@example.com' );
$GLOBALS['newsletter_consent'][9] = false;
update_user_meta( 7, 'wp_openbb_zeffy_contact_id', $id );
update_user_meta( 9, 'wp_openbb_zeffy_contact_id', $id );
$GLOBALS['members'][7]->status = 'inactive';
$api_call_count = count( OPENBB_Zeffy_API::$calls );
OPENBB_Zeffy_Sync::sync_member( 7 );
sync_same( $api_call_count, count( OPENBB_Zeffy_API::$calls ), 'An active member who paused updates should still retain a shared contact.' );
sync_same( '', get_user_meta( 7, 'wp_openbb_zeffy_contact_id', true ), 'Shared contact should clear only the departing member mapping.' );
sync_same( $id, get_user_meta( 9, 'wp_openbb_zeffy_contact_id', true ), 'The remaining member mapping must be preserved.' );

delete_user_meta( 9, 'wp_openbb_zeffy_contact_id' );
$GLOBALS['multisite'] = true;
$GLOBALS['members_by_blog'][2][9] = sync_member( 'alex@example.com' );
$GLOBALS['newsletter_consent_by_blog'][2][9] = true;
update_user_meta( 7, 'wp_openbb_zeffy_contact_id', $id );
update_user_meta( 9, 'wp_2_openbb_zeffy_contact_id', $id );
OPENBB_Zeffy_Sync::sync_member( 7 );
sync_same( $api_call_count, count( OPENBB_Zeffy_API::$calls ), 'An active member on another site should prevent deletion.' );
sync_same( 1, get_current_blog_id(), 'Site context must be restored after searching other memberships.' );
sync_same( $id, get_user_meta( 9, 'wp_2_openbb_zeffy_contact_id', true ), 'The other site mapping must be preserved.' );

switch_to_blog( 2 );
$GLOBALS['members_by_blog'][2][9]->status = 'inactive';
OPENBB_Zeffy_API::$results['delete'][] = true;
OPENBB_Zeffy_Sync::sync_member( 9 );
sync_same( 'delete', end( OPENBB_Zeffy_API::$calls )['method'], 'The last linked membership should delete the contact.' );
restore_current_blog();

$GLOBALS['multisite'] = false;
$GLOBALS['users'][12] = (object) array( 'ID' => 12, 'user_email' => 'new@example.com' );
$GLOBALS['members'][12] = sync_member( 'new@example.com' );
$GLOBALS['newsletter_consent'][12] = true;
update_user_meta( 12, 'wp_openbb_zeffy_contact_id', $id );
update_user_meta( 12, 'wp_openbb_zeffy_email', 'old@example.com' );
$replacement_id = '22222222-2222-2222-2222-222222222222';
OPENBB_Zeffy_API::$results['update'][] = new WP_Error( 'openbb_zeffy_api_error', 'Contact missing.', array( 'status' => 404 ) );
OPENBB_Zeffy_API::$results['upsert'][] = array( 'email' => 'new@example.com' );
OPENBB_Zeffy_API::$results['find'][] = array( 'id' => $replacement_id, 'email' => 'new@example.com' );
OPENBB_Zeffy_Sync::sync_member( 12 );
sync_same( $replacement_id, get_user_meta( 12, 'wp_openbb_zeffy_contact_id', true ), 'A recreated contact must replace the stale ID even when PUT omits its ID.' );

$GLOBALS['meta'] = array();
$GLOBALS['events'] = array();
$bulk_id = '33333333-3333-3333-3333-333333333333';
$GLOBALS['users'][13] = (object) array( 'ID' => 13, 'user_email' => 'bulk@example.com' );
$GLOBALS['members'][13] = sync_member( 'bulk@example.com' );
update_user_meta( 13, 'wp_openbb_zeffy_contact_id', $bulk_id );
OPENBB_Zeffy_Sync::on_bulk_contact_mapping_delete( array(), 0, 'wp_openbb_zeffy_contact_id', '' );
sync_same( OPENBB_Zeffy_Sync::REMOVED_MAPPING_HOOK, $GLOBALS['events'][0]['hook'], 'Bulk cleanup should capture linked contacts before erasing meta.' );
sync_same( array( $bulk_id, 13, 1, 0 ), $GLOBALS['events'][0]['args'], 'Bulk cleanup job should retain the contact ID and site.' );
delete_user_meta( 13, 'wp_openbb_zeffy_contact_id' );
unset( $GLOBALS['members'][13] );
OPENBB_Zeffy_API::$results['delete'][] = true;
OPENBB_Zeffy_Sync::reconcile_removed_mapping( $bulk_id, 13, 1 );
sync_same( $bulk_id, end( OPENBB_Zeffy_API::$calls )['args'][0], 'Bulk membership removal should delete the captured contact.' );

$GLOBALS['events'] = array();
$preserved_id = '44444444-4444-4444-4444-444444444444';
$GLOBALS['users'][14] = (object) array( 'ID' => 14, 'user_email' => 'preserved@example.com' );
$GLOBALS['members'][14] = sync_member( 'preserved@example.com' );
update_user_meta( 14, 'wp_openbb_zeffy_contact_id', $preserved_id );
OPENBB_Zeffy_Sync::on_bulk_contact_mapping_delete( array(), 0, 'wp_openbb_zeffy_contact_id', '' );
delete_user_meta( 14, 'wp_openbb_zeffy_contact_id' );
$api_call_count = count( OPENBB_Zeffy_API::$calls );
OPENBB_Zeffy_Sync::reconcile_removed_mapping( $preserved_id, 14, 1 );
sync_same( $api_call_count, count( OPENBB_Zeffy_API::$calls ), 'Mapping-only deletion should not delete an active member contact.' );
sync_same( $preserved_id, get_user_meta( 14, 'wp_openbb_zeffy_contact_id', true ), 'Mapping-only deletion should restore the active member mapping.' );

$GLOBALS['users'][15] = (object) array( 'ID' => 15, 'user_email' => 'replacement@example.com' );
$GLOBALS['members'][15] = sync_member( 'replacement@example.com' );
$old_id = '55555555-5555-5555-5555-555555555555';
$new_id = '66666666-6666-6666-6666-666666666666';
update_user_meta( 15, 'wp_openbb_zeffy_contact_id', $new_id );
OPENBB_Zeffy_API::$results['delete'][] = true;
OPENBB_Zeffy_Sync::reconcile_removed_mapping( $old_id, 15, 1 );
sync_same( $old_id, end( OPENBB_Zeffy_API::$calls )['args'][0], 'A replaced mapping should remove only the obsolete contact.' );
sync_same( $new_id, get_user_meta( 15, 'wp_openbb_zeffy_contact_id', true ), 'A replacement mapping should remain in place.' );

echo "Zeffy membership sync mock tests passed.\n";
