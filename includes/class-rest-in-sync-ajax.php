<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the "Test Connection" AJAX request from the Settings page.
 */
class Rest_In_Sync_Ajax {

	const NONCE_ACTION = 'rest_in_sync_test_connection';
	const ACTION       = 'rest_in_sync_test_connection';

	public function __construct() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_test_connection' ) );
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
}
