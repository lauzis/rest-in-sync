<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the plugin's AJAX requests: "Test Connection" on the Settings page,
 * and the field-setting toggles and "Push to Remote"/"Pull from Remote" actions
 * on the Details page.
 */
class Rest_In_Sync_Ajax {

	const NONCE_ACTION = 'rest_in_sync_test_connection';
	const ACTION       = 'rest_in_sync_test_connection';

	/** Shared nonce action for the Details page's AJAX requests. */
	const NONCE_ACTION_DETAILS = 'rest_in_sync_details';

	const ACTION_PUSH                 = 'rest_in_sync_push_fields';
	const ACTION_PULL                 = 'rest_in_sync_pull_fields';
	const ACTION_UPDATE_FIELD_SETTING  = 'rest_in_sync_update_field_setting';
	const ACTION_CHECK_NOW             = 'rest_in_sync_check_now';
	const ACTION_IGNORE                = 'rest_in_sync_ignore_until_next_check';
	const ACTION_UPDATE_FIELD_PATTERN  = 'rest_in_sync_update_field_pattern';
	const ACTION_DELETE_FIELD_PATTERN  = 'rest_in_sync_delete_field_pattern';

	/** Nonce action for the Sync page's AJAX requests. */
	const NONCE_ACTION_SYNC = 'rest_in_sync_sync_page';

	/** Clearing cached diffs is destructive, so it carries its own nonce rather than the test-connection one. */
	const NONCE_ACTION_DIFF_CACHE = 'rest_in_sync_diff_cache';
	const ACTION_CLEAR_DIFF_CACHE = 'rest_in_sync_clear_diff_cache';

	public function __construct() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_test_connection' ) );
		add_action( 'wp_ajax_' . self::ACTION_PUSH, array( $this, 'handle_push_fields' ) );
		add_action( 'wp_ajax_' . self::ACTION_PULL, array( $this, 'handle_pull_fields' ) );
		add_action( 'wp_ajax_' . self::ACTION_UPDATE_FIELD_SETTING, array( $this, 'handle_update_field_setting' ) );
		add_action( 'wp_ajax_' . self::ACTION_CHECK_NOW, array( $this, 'handle_check_now' ) );
		add_action( 'wp_ajax_' . self::ACTION_IGNORE, array( $this, 'handle_ignore_until_next_check' ) );
		add_action( 'wp_ajax_' . self::ACTION_UPDATE_FIELD_PATTERN, array( $this, 'handle_update_field_pattern' ) );
		add_action( 'wp_ajax_' . self::ACTION_DELETE_FIELD_PATTERN, array( $this, 'handle_delete_field_pattern' ) );
		add_action( 'wp_ajax_' . self::ACTION_CLEAR_DIFF_CACHE, array( $this, 'handle_clear_diff_cache' ) );
	}

	/**
	 * Deletes cached diff files: the unused ones by default, everything when
	 * asked. Answers with the fresh figures so the page can redraw them without
	 * a reload.
	 */
	public function handle_clear_diff_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'rest-in-sync' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION_DIFF_CACHE, 'nonce' );

		$scope = isset( $_POST['scope'] ) && Rest_In_Sync_Diff_Cache::SCOPE_ALL === $_POST['scope']
			? Rest_In_Sync_Diff_Cache::SCOPE_ALL
			: Rest_In_Sync_Diff_Cache::SCOPE_STALE;

		$result = Rest_In_Sync_Diff_Cache::clear( $scope );

		if ( $result['failed'] > 0 ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: 1: number deleted, 2: number that could not be deleted */
					__( 'Cleared %1$s file(s), but %2$s could not be deleted — check the permissions on the uploads folder.', 'rest-in-sync' ),
					number_format_i18n( $result['deleted'] ),
					number_format_i18n( $result['failed'] )
				),
				'summary' => Rest_In_Sync_Diff_Cache::summary( $result['stats'] ),
				'stats'   => $result['stats'],
			) );
		}

		wp_send_json_success( array(
			'message' => 0 === $result['deleted']
				? __( 'There was nothing to clear.', 'rest-in-sync' )
				: sprintf(
					/* translators: 1: number of files deleted, 2: space reclaimed */
					__( 'Cleared %1$s file(s), %2$s reclaimed.', 'rest-in-sync' ),
					number_format_i18n( $result['deleted'] ),
					size_format( $result['bytes'] )
				),
			'summary' => Rest_In_Sync_Diff_Cache::summary( $result['stats'] ),
			'stats'   => $result['stats'],
		) );
	}

	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'rest-in-sync' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$tester = new Rest_In_Sync_Connection_Tester();
		$result = $tester->test();

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		wp_send_json_success( array(
			'message' => $result['message'],
			'items'   => $result['items'],
		) );
	}

	/** Pushes the checked fields on the Details page to the remote post. */
	public function handle_push_fields() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'rest-in-sync' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION_DETAILS, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$fields  = isset( $_POST['fields'] ) && is_array( $_POST['fields'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['fields'] ) )
			: array();

		$post = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found.', 'rest-in-sync' ) ) );
		}

		if ( empty( $fields ) ) {
			wp_send_json_error( array( 'message' => __( 'No fields were selected to push.', 'rest-in-sync' ) ) );
		}

		$result = ( new Rest_In_Sync_Sync_Checker() )->push_to_remote( $post, $fields );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Selected fields were pushed to the remote site.', 'rest-in-sync' ) ) );
	}

	/** Pulls the checked fields on the Details page from the remote post onto this one. */
	public function handle_pull_fields() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'rest-in-sync' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION_DETAILS, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$fields  = isset( $_POST['fields'] ) && is_array( $_POST['fields'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['fields'] ) )
			: array();

		$post = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found.', 'rest-in-sync' ) ) );
		}

		if ( empty( $fields ) ) {
			wp_send_json_error( array( 'message' => __( 'No fields were selected to pull.', 'rest-in-sync' ) ) );
		}

		$result = ( new Rest_In_Sync_Sync_Checker() )->pull_from_remote( $post, $fields );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Selected fields were pulled from the remote site.', 'rest-in-sync' ) ) );
	}

	/** Re-runs the sync check for a single post, e.g. from the "Check Now" button on the Sync page. */
	public function handle_check_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'rest-in-sync' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION_SYNC, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found.', 'rest-in-sync' ) ) );
		}

		if ( Rest_In_Sync_Settings::is_remote_server() ) {
			wp_send_json_error( array( 'message' => __( 'This site is configured as the remote server of a sync pair, so it never initiates its own sync checks.', 'rest-in-sync' ) ) );
		}

		( new Rest_In_Sync_Sync_Checker() )->check_single( $post );

		$status       = get_post_meta( $post->ID, Rest_In_Sync_Sync_Checker::META_STATUS, true );
		$diff_id      = get_post_meta( $post->ID, Rest_In_Sync_Sync_Checker::META_DIFF_ID, true );
		$last_checked = get_post_meta( $post->ID, Rest_In_Sync_Sync_Checker::META_LAST_CHECKED, true );

		$diff_url = $diff_id ? admin_url(
			'admin.php?page=' . Rest_In_Sync_Admin_Menu::MENU_SLUG . '-diff'
			. '&diff_id=' . rawurlencode( $diff_id )
			. '&_wpnonce=' . wp_create_nonce( Rest_In_Sync_Admin_Menu::DIFF_NONCE_ACTION . '_' . $diff_id )
		) : '';

		wp_send_json_success( array(
			'status'      => $status,
			'inSync'      => Rest_In_Sync_Sync_Checker::STATUS_OUT_OF_SYNC !== $status,
			'diffUrl'     => $diff_url,
			'lastChecked' => $last_checked ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_checked ) : '',
			'message'     => Rest_In_Sync_Sync_Checker::STATUS_OUT_OF_SYNC === $status
				? __( 'Check complete: still out of sync.', 'rest-in-sync' )
				: __( 'Check complete: now in sync.', 'rest-in-sync' ),
		) );
	}

	/**
	 * Snoozes a post's out-of-sync status until the next check runs for it,
	 * from the "Ignore Until Next Sync" button on the Sync or Details page.
	 */
	public function handle_ignore_until_next_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'rest-in-sync' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION_SYNC, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found.', 'rest-in-sync' ) ) );
		}

		( new Rest_In_Sync_Sync_Checker() )->ignore_until_next_check( $post );

		wp_send_json_success( array( 'message' => __( 'This post will be ignored until the next sync check.', 'rest-in-sync' ) ) );
	}

	/** Toggles a field's "don't sync by default" / "don't use in diff" setting. */
	public function handle_update_field_setting() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'rest-in-sync' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION_DETAILS, 'nonce' );

		$field   = isset( $_POST['field'] ) ? sanitize_text_field( wp_unslash( $_POST['field'] ) ) : '';
		$setting = isset( $_POST['setting'] ) ? sanitize_text_field( wp_unslash( $_POST['setting'] ) ) : '';
		$value   = ! empty( $_POST['value'] );

		$valid_settings = array(
			Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_SYNC,
			Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_DIFF,
		);

		if ( '' === $field || ! in_array( $setting, $valid_settings, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid field setting request.', 'rest-in-sync' ) ) );
		}

		$updated = Rest_In_Sync_Field_Settings::update_field( $field, $setting, $value );

		wp_send_json_success( array( 'setting' => $updated ) );
	}

	/** Adds or updates a wildcard pattern rule from the Field Settings page. */
	public function handle_update_field_pattern() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'rest-in-sync' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION_DETAILS, 'nonce' );

		$pattern           = isset( $_POST['pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern'] ) ) : '';
		$exclude_from_sync = ! empty( $_POST['exclude_from_sync'] );
		$exclude_from_diff = ! empty( $_POST['exclude_from_diff'] );

		if ( '' === $pattern ) {
			wp_send_json_error( array( 'message' => __( 'Enter a pattern first.', 'rest-in-sync' ) ) );
		}

		$rule = Rest_In_Sync_Field_Settings::save_pattern_rule( $pattern, $exclude_from_sync, $exclude_from_diff );

		wp_send_json_success( array( 'pattern' => $pattern, 'rule' => $rule ) );
	}

	/** Removes a wildcard pattern rule from the Field Settings page. */
	public function handle_delete_field_pattern() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'rest-in-sync' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION_DETAILS, 'nonce' );

		$pattern = isset( $_POST['pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern'] ) ) : '';

		if ( '' === $pattern ) {
			wp_send_json_error( array( 'message' => __( 'Invalid pattern.', 'rest-in-sync' ) ) );
		}

		Rest_In_Sync_Field_Settings::delete_pattern_rule( $pattern );

		wp_send_json_success();
	}
}
