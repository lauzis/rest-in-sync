<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a small REST route that returns every meta key on a post,
 * bypassing register_meta()/show_in_rest entirely. The standard WP REST API
 * only ever exposes meta explicitly registered for REST, which excludes most
 * ACF fields and other custom fields by default. This route lets the sync
 * checker compare (and detect real drift in) meta the standard 'meta' field
 * would never see — as long as the site on the other end is also running
 * this plugin version, since it's the one serving this route.
 */
class Rest_In_Sync_Rest_Controller {

	const NAMESPACE_NAME = 'rest-in-sync/v1';
	const ROUTE          = '/meta/(?P<post_id>\d+)';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route( self::NAMESPACE_NAME, self::ROUTE, array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_all_meta' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );
	}

	/**
	 * Same trust boundary as the rest of the sync connection: the request must
	 * authenticate (e.g. via Application Passwords) as a user allowed to edit
	 * this specific post.
	 */
	public function check_permission( WP_REST_Request $request ) {
		$post = get_post( (int) $request['post_id'] );

		if ( ! $post ) {
			return new WP_Error( 'rest_in_sync_not_found', __( 'Post not found.', 'rest-in-sync' ), array( 'status' => 404 ) );
		}

		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * @return WP_REST_Response meta_key => value (single value, like get_post_meta($id, $key, true)),
	 *                           excluding protected (underscore-prefixed) meta — internal bookkeeping
	 *                           (edit locks, ACF's field-key shadow entries, this plugin's own meta,
	 *                           etc.) rather than content anyone would want to compare or sync.
	 */
	public function get_all_meta( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];

		global $wpdb;

		$meta_keys = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT LIKE %s",
			$post_id,
			$wpdb->esc_like( '_' ) . '%'
		) );

		$meta = array();

		foreach ( $meta_keys as $meta_key ) {
			$meta[ $meta_key ] = get_post_meta( $post_id, $meta_key, true );
		}

		return rest_ensure_response( $meta );
	}
}
