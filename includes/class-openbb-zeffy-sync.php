<?php
/**
 * Connects Open Bulk Buying membership events to the Zeffy contacts API.
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Zeffy_Sync {
	const SYNC_HOOK   = 'openbb_zeffy_sync_member';
	const DELETE_HOOK = 'openbb_zeffy_delete_contact';
	const REMOVED_MAPPING_HOOK = 'openbb_zeffy_reconcile_removed_mapping';
	const ERROR_OPTION = 'openbb_zeffy_last_error';
	const RETRY_DELAYS = array( 60, 300, 900, 3600, 21600 );

	public static function register_hooks(): void {
		add_action( 'openbb_member_created', array( __CLASS__, 'queue_member' ), 10, 1 );
		add_action( 'openbb_member_status_changed', array( __CLASS__, 'on_member_status_changed' ), 10, 3 );
		add_action( 'openbb_profile_values_changed', array( __CLASS__, 'on_profile_values_changed' ), 10, 2 );
		add_action( 'openbb_newsletter_consent_changed', array( __CLASS__, 'on_newsletter_consent_changed' ), 10, 2 );
		add_action( 'profile_update', array( __CLASS__, 'queue_member' ), 10, 1 );
		add_action( 'added_user_meta', array( __CLASS__, 'on_user_meta_change' ), 10, 4 );
		add_action( 'updated_user_meta', array( __CLASS__, 'on_user_meta_change' ), 10, 4 );
		add_action( 'delete_user_meta', array( __CLASS__, 'on_bulk_contact_mapping_delete' ), 10, 4 );
		add_action( 'deleted_user_meta', array( __CLASS__, 'on_user_meta_change' ), 10, 4 );
		add_action( 'delete_user', array( __CLASS__, 'on_user_delete' ), 10, 1 );
		add_action( 'remove_user_from_blog', array( __CLASS__, 'on_user_removed_from_site' ), 10, 2 );
		add_action( self::SYNC_HOOK, array( __CLASS__, 'sync_member' ), 10, 2 );
		add_action( self::DELETE_HOOK, array( __CLASS__, 'delete_removed_contact' ), 10, 4 );
		add_action( self::REMOVED_MAPPING_HOOK, array( __CLASS__, 'reconcile_removed_mapping' ), 10, 4 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/**
	 * A delayed read sees the completed signup or status change, including any rollback.
	 */
	public static function queue_member( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		if ( ! self::schedule( self::SYNC_HOOK, array( $user_id, 0 ), 30 ) ) {
			self::record_error( $user_id, new WP_Error( 'openbb_zeffy_schedule_failed', 'Could not schedule Zeffy contact sync.' ) );
		}
	}

	public static function on_member_status_changed( $user_id, $status, $old_status ): void {
		self::queue_member( $user_id );
	}

	public static function on_profile_values_changed( $user_id, $values ): void {
		if ( is_array( $values ) && array_key_exists( self::newsletter_field_key(), $values ) ) {
			// Once a stored OpenBB profile field is used, read that field as the
			// source of truth instead of any earlier one-time signup hook value.
			delete_user_meta( (int) $user_id, self::meta_key( 'consent' ) );
			self::queue_member( $user_id );
		}
	}

	/**
	 * Lets a signup form pass its sanitized checkbox value without depending on
	 * a profile-field schema. The companion stores the value for queued jobs.
	 */
	public static function on_newsletter_consent_changed( $user_id, $consent ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! get_userdata( $user_id ) || ! is_bool( $consent ) ) {
			return;
		}

		update_user_meta( $user_id, self::meta_key( 'consent' ), $consent ? '1' : '0' );
		self::queue_member( $user_id );
	}

	public static function on_user_meta_change( $meta_id, $user_id, $meta_key, $meta_value ): void {
		if ( in_array( $meta_key, array( 'first_name', 'last_name', openbb_member_meta_key( 'phone' ) ), true ) ) {
			self::queue_member( $user_id );
		}
	}

	/**
	 * OpenBB's site-wide data cleanup deletes all openbb_* user meta in bulk.
	 * Capture linked IDs before WordPress erases that key for every user.
	 */
	public static function on_bulk_contact_mapping_delete( $meta_ids, $user_id, $meta_key, $meta_value ): void {
		if ( 0 !== (int) $user_id || self::meta_key( 'contact_id' ) !== $meta_key ) {
			return;
		}

		$blog_id = get_current_blog_id();
		$users   = get_users( array( 'blog_id' => 0, 'fields' => 'ID', 'number' => -1, 'meta_key' => $meta_key ) );
		foreach ( $users as $mapped_user_id ) {
			$mapped_user_id = (int) $mapped_user_id;
			$contact_id     = (string) get_user_meta( $mapped_user_id, $meta_key, true );
			if ( $mapped_user_id <= 0 || ! self::valid_contact_id( $contact_id ) ) {
				continue;
			}
			if ( ! self::schedule( self::REMOVED_MAPPING_HOOK, array( $contact_id, $mapped_user_id, $blog_id, 0 ), 30 ) ) {
				self::record_error( $mapped_user_id, new WP_Error( 'openbb_zeffy_schedule_failed', 'Could not schedule Zeffy contact reconciliation after user data removal.' ) );
			}
		}
	}

	/**
	 * A WordPress user deletion erases user meta. Preserve the Zeffy ID in the cron
	 * event, then verify that the deletion really completed before touching Zeffy.
	 */
	public static function on_user_delete( $user_id ): void {
		if ( ! is_multisite() ) {
			self::queue_removed_contact( (int) $user_id, get_current_blog_id() );
		}
	}

	public static function on_user_removed_from_site( $user_id, $blog_id ): void {
		self::queue_removed_contact( (int) $user_id, (int) $blog_id );
	}

	private static function queue_removed_contact( int $user_id, int $blog_id ): void {
		$contact_id = (string) get_user_meta( $user_id, self::meta_key( 'contact_id' ), true );
		if ( '' === $contact_id ) {
			return;
		}

		if ( ! self::schedule( self::DELETE_HOOK, array( $contact_id, $user_id, $blog_id, 0 ), 30 ) ) {
			self::record_error( $user_id, new WP_Error( 'openbb_zeffy_schedule_failed', 'Could not schedule Zeffy contact deletion.' ) );
		}
	}

	/**
	 * Reconciles the member's current state. Only active memberships are sent.
	 */
	public static function sync_member( $user_id, $attempt = 0 ): void {
		$user_id = (int) $user_id;
		$attempt = (int) $attempt;
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return;
		}
		if ( is_multisite() && ! is_user_member_of_blog( $user_id ) ) {
			return;
		}

		$member     = OPENBB_Member::get( $user_id );
		$contact_id = (string) get_user_meta( $user_id, self::meta_key( 'contact_id' ), true );
		if ( ! $member || OPENBB_Access::STATUS_ACTIVE !== $member->status ) {
			if ( '' !== $contact_id ) {
				self::delete_member_contact( $user_id, $contact_id, $attempt );
			} else {
				self::clear_error( $user_id );
			}
			return;
		}

		$subscribed = self::newsletter_consent( $user_id );
		if ( null === $subscribed ) {
			self::record_error( $user_id, new WP_Error( 'openbb_zeffy_consent_missing', 'Newsletter consent has not been captured for this member.' ) );
			return;
		}
		if ( ! $subscribed ) {
			// A withdrawn newsletter opt-in pauses updates; membership removal
			// still deletes a contact that was previously linked by this plugin.
			self::clear_error( $user_id );
			return;
		}

		$email = sanitize_email( (string) $member->email );
		if ( ! is_email( $email ) || openbb_is_placeholder_email( $email ) ) {
			self::record_error( $user_id, new WP_Error( 'openbb_zeffy_invalid_email', 'Member has no deliverable email address.' ) );
			return;
		}

		$contact = array( 'email' => $email );
		foreach ( array( 'first_name' => 'first_name', 'last_name' => 'last_name', 'phone' => 'phone_number' ) as $source => $target ) {
			$value = trim( (string) $member->$source );
			$contact[ $target ] = '' === $value ? null : $value;
		}

		$fingerprint = hash( 'sha256', wp_json_encode( $contact ) );
		if ( '' !== $contact_id && $fingerprint === get_user_meta( $user_id, self::meta_key( 'fingerprint' ), true ) ) {
			return;
		}

		$api = self::api();
		if ( is_wp_error( $api ) ) {
			self::fail( self::SYNC_HOOK, array( $user_id ), $attempt, $user_id, $api );
			return;
		}

		$previous_email = (string) get_user_meta( $user_id, self::meta_key( 'email' ), true );
		$old_id_missing = false;
		if ( '' !== $contact_id && $previous_email !== $email ) {
			$result = $api->update_contact( $contact_id, $contact );
			if ( is_wp_error( $result ) && 404 === self::error_status( $result ) ) {
				$old_id_missing = true;
				$result = $api->upsert_contact( $contact );
			}
		} else {
			$result = $api->upsert_contact( $contact );
		}

		if ( is_wp_error( $result ) ) {
			self::fail( self::SYNC_HOOK, array( $user_id ), $attempt, $user_id, $result );
			return;
		}

		$returned_id = isset( $result['id'] ) && is_string( $result['id'] ) ? $result['id'] : ( $old_id_missing ? '' : $contact_id );
		if ( ! self::valid_contact_id( $returned_id ) ) {
			$found = $api->find_contact_by_email( $email );
			if ( is_wp_error( $found ) ) {
				self::fail( self::SYNC_HOOK, array( $user_id ), $attempt, $user_id, $found );
				return;
			}
			$returned_id = is_array( $found ) && isset( $found['id'] ) && is_string( $found['id'] ) ? $found['id'] : '';
			if ( ! self::valid_contact_id( $returned_id ) ) {
				self::record_error( $user_id, new WP_Error( 'openbb_zeffy_missing_contact_id', 'Zeffy did not return a contact ID.' ) );
				return;
			}
		}

		update_user_meta( $user_id, self::meta_key( 'contact_id' ), $returned_id );
		update_user_meta( $user_id, self::meta_key( 'email' ), $email );
		update_user_meta( $user_id, self::meta_key( 'fingerprint' ), $fingerprint );
		self::clear_error( $user_id );
	}

	private static function delete_member_contact( int $user_id, string $contact_id, int $attempt ): void {
		if ( self::has_other_active_contact( $contact_id, $user_id, get_current_blog_id() ) ) {
			self::clear_contact_meta( $user_id );
			self::clear_error( $user_id );
			return;
		}

		$api = self::api();
		if ( is_wp_error( $api ) ) {
			self::fail( self::SYNC_HOOK, array( $user_id ), $attempt, $user_id, $api );
			return;
		}

		$result = $api->delete_contact( $contact_id );
		if ( is_wp_error( $result ) && 404 !== self::error_status( $result ) ) {
			self::fail( self::SYNC_HOOK, array( $user_id ), $attempt, $user_id, $result );
			return;
		}

		self::clear_contact_meta( $user_id );
		self::clear_error( $user_id );
	}

	/**
	 * Finishes a WordPress account/site removal using the captured Zeffy ID.
	 */
	public static function delete_removed_contact( $contact_id, $user_id, $blog_id, $attempt = 0 ): void {
		$user_id    = (int) $user_id;
		$blog_id    = (int) $blog_id;
		$attempt    = (int) $attempt;
		$contact_id = (string) $contact_id;
		if ( $user_id <= 0 || $blog_id !== get_current_blog_id() || '' === $contact_id ) {
			return;
		}
		if ( is_multisite() ? is_user_member_of_blog( $user_id, $blog_id ) : (bool) get_userdata( $user_id ) ) {
			return;
		}

		if ( self::has_other_active_contact( $contact_id, $user_id, $blog_id ) ) {
			if ( get_userdata( $user_id ) ) {
				self::clear_contact_meta( $user_id );
			}
			self::clear_error( $user_id );
			return;
		}

		$api = self::api();
		if ( is_wp_error( $api ) ) {
			self::fail( self::DELETE_HOOK, array( $contact_id, $user_id, $blog_id ), $attempt, $user_id, $api );
			return;
		}
		$result = $api->delete_contact( $contact_id );
		if ( is_wp_error( $result ) && 404 !== self::error_status( $result ) ) {
			self::fail( self::DELETE_HOOK, array( $contact_id, $user_id, $blog_id ), $attempt, $user_id, $result );
			return;
		}

		if ( get_userdata( $user_id ) ) {
			self::clear_contact_meta( $user_id );
		}
		self::clear_error( $user_id );
	}

	/** Reconcile IDs captured before a site-wide bulk deletion of OpenBB meta. */
	public static function reconcile_removed_mapping( $contact_id, $user_id, $blog_id, $attempt = 0 ): void {
		$user_id    = (int) $user_id;
		$blog_id    = (int) $blog_id;
		$attempt    = (int) $attempt;
		$contact_id = (string) $contact_id;
		if ( $user_id <= 0 || $blog_id !== get_current_blog_id() || ! self::valid_contact_id( $contact_id ) ) {
			return;
		}

		$member = OPENBB_Member::get( $user_id );
		$current_id = (string) get_user_meta( $user_id, self::meta_key( 'contact_id' ), true );
		if ( $member && OPENBB_Access::STATUS_ACTIVE === $member->status && ( '' === $current_id || $contact_id === $current_id ) ) {
			// A mapping-only deletion should not destroy an active member's contact.
			if ( '' === $current_id ) {
				update_user_meta( $user_id, self::meta_key( 'contact_id' ), $contact_id );
			}
			self::clear_error( $user_id );
			return;
		}

		if ( self::has_other_active_contact( $contact_id, $user_id, $blog_id ) ) {
			self::clear_error( $user_id );
			return;
		}

		$api = self::api();
		if ( is_wp_error( $api ) ) {
			self::fail( self::REMOVED_MAPPING_HOOK, array( $contact_id, $user_id, $blog_id ), $attempt, $user_id, $api );
			return;
		}
		$result = $api->delete_contact( $contact_id );
		if ( is_wp_error( $result ) && 404 !== self::error_status( $result ) ) {
			self::fail( self::REMOVED_MAPPING_HOOK, array( $contact_id, $user_id, $blog_id ), $attempt, $user_id, $result );
			return;
		}
		self::clear_error( $user_id );
	}

	/**
	 * A Zeffy contact can be shared by members on this site or another site.
	 * Keep it while another active member still has a mapping. A member who
	 * withdrew consent keeps their contact until membership removal.
	 */
	private static function has_other_active_contact( string $contact_id, int $source_user_id, int $source_blog_id ): bool {
		$site_ids = is_multisite()
			? get_sites( array( 'fields' => 'ids', 'number' => 0 ) )
			: array( get_current_blog_id() );

		foreach ( $site_ids as $site_id ) {
			$site_id  = (int) $site_id;
			$switched = $site_id !== get_current_blog_id();
			if ( $switched ) {
				switch_to_blog( $site_id );
			}

			try {
				$users = get_users(
					array(
						'blog_id'    => $site_id,
						'fields'     => 'ID',
						'meta_key'   => self::meta_key( 'contact_id' ),
						'meta_value' => $contact_id,
					)
				);
				foreach ( $users as $other_user_id ) {
					$other_user_id = (int) $other_user_id;
					if ( $source_blog_id === $site_id && $source_user_id === $other_user_id ) {
						continue;
					}
					$member = OPENBB_Member::get( $other_user_id );
					if ( $member && OPENBB_Access::STATUS_ACTIVE === $member->status ) {
						return true;
					}
				}
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}

		return false;
	}

	private static function api() {
		$key = defined( 'OPENBB_ZEFFY_API_KEY' ) ? OPENBB_ZEFFY_API_KEY : '';
		if ( ! is_string( $key ) || '' === trim( $key ) ) {
			return new WP_Error( 'openbb_zeffy_api_key_missing', 'Configure OPENBB_ZEFFY_API_KEY before syncing contacts.' );
		}

		return new OPENBB_Zeffy_API( trim( $key ) );
	}

	private static function schedule( string $hook, array $args, int $delay ): bool {
		if ( wp_next_scheduled( $hook, $args ) ) {
			return true;
		}

		return true === wp_schedule_single_event( time() + $delay, $hook, $args, true );
	}

	private static function fail( string $hook, array $base_args, int $attempt, int $user_id, WP_Error $error ): void {
		self::record_error( $user_id, $error );
		$status = self::error_status( $error );
		$non_retryable = array( 'openbb_zeffy_api_key_missing', 'openbb_zeffy_invalid_contact_id', 'openbb_zeffy_invalid_email', 'openbb_zeffy_invalid_contact' );
		if ( ( 0 !== $status && 429 !== $status && $status < 500 ) || in_array( $error->get_error_code(), $non_retryable, true ) ) {
			return;
		}

		$data        = $error->get_error_data();
		$retry_after = is_array( $data ) ? (int) ( $data['retry_after'] ?? 0 ) : 0;
		$delay_index = min( max( $attempt, 0 ), count( self::RETRY_DELAYS ) - 1 );
		$delay       = max( self::RETRY_DELAYS[ $delay_index ], $retry_after );
		$next_attempt = min( $delay_index + 1, count( self::RETRY_DELAYS ) );
		if ( ! self::schedule( $hook, array_merge( $base_args, array( $next_attempt ) ), $delay ) ) {
			self::record_error( $user_id, new WP_Error( 'openbb_zeffy_schedule_failed', 'Could not schedule a Zeffy retry.' ) );
		}
	}

	private static function error_status( WP_Error $error ): int {
		$data = $error->get_error_data();
		return is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
	}

	private static function record_error( int $user_id, WP_Error $error ): void {
		$status = self::error_status( $error );
		$entry  = array( 'user_id' => $user_id, 'code' => $error->get_error_code(), 'status' => $status, 'at' => time() );
		update_option( self::ERROR_OPTION, $entry, false );
		if ( get_userdata( $user_id ) ) {
			update_user_meta( $user_id, self::meta_key( 'last_error' ), $entry );
		}
	}

	private static function clear_error( int $user_id ): void {
		if ( get_userdata( $user_id ) ) {
			delete_user_meta( $user_id, self::meta_key( 'last_error' ) );
		}
		$last = get_option( self::ERROR_OPTION );
		if ( is_array( $last ) && (int) ( $last['user_id'] ?? 0 ) === $user_id ) {
			delete_option( self::ERROR_OPTION );
		}
	}

	private static function clear_contact_meta( int $user_id ): void {
		foreach ( array( 'contact_id', 'email', 'fingerprint' ) as $field ) {
			delete_user_meta( $user_id, self::meta_key( $field ) );
		}
	}

	private static function meta_key( string $field ): string {
		global $wpdb;
		return $wpdb->get_blog_prefix() . 'openbb_zeffy_' . $field;
	}

	private static function valid_contact_id( string $contact_id ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $contact_id );
	}

	private static function newsletter_field_key(): string {
		$key = defined( 'OPENBB_ZEFFY_NEWSLETTER_FIELD_KEY' ) ? OPENBB_ZEFFY_NEWSLETTER_FIELD_KEY : 'newsletter';
		return is_string( $key ) ? sanitize_key( $key ) : '';
	}

	/** A saved direct-hook choice wins; otherwise use an OpenBB profile field. */
	private static function newsletter_consent( int $user_id ): ?bool {
		$captured = get_user_meta( $user_id, self::meta_key( 'consent' ), true );
		if ( '1' === $captured || '0' === $captured ) {
			return '1' === $captured;
		}
		if ( ! class_exists( 'OPENBB_Profile_Fields' ) ) {
			return null;
		}

		$key    = self::newsletter_field_key();
		$fields = OPENBB_Profile_Fields::get_fields();
		$field  = $fields[ $key ] ?? null;
		if ( ! is_array( $field ) || OPENBB_Profile_Fields::TYPE_BOOLEAN !== ( $field['type'] ?? '' )
			|| ! empty( $field['archived'] ) || OPENBB_Profile_Fields::VISIBILITY_MEMBER !== ( $field['visibility'] ?? '' ) ) {
			return null;
		}

		$values = OPENBB_Profile_Fields::get_values( $user_id, OPENBB_Profile_Fields::CONTEXT_STAFF );
		return true === ( $values[ $key ] ?? false );
	}

	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( is_wp_error( self::api() ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'OpenBB Zeffy Contact Sync needs OPENBB_ZEFFY_API_KEY in wp-config.php.', 'openbb-zeffy-plugin' ) . '</p></div>';
		}
		$last = get_option( self::ERROR_OPTION );
		if ( ! is_array( $last ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( sprintf( 'OpenBB Zeffy contact sync failed for WordPress user #%d (%s, HTTP %d).', (int) $last['user_id'], (string) $last['code'], (int) $last['status'] ) )
		);
	}
}
