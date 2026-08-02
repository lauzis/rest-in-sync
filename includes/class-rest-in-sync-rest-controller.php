<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a small REST route for reading and writing every meta key on a
 * post, bypassing register_meta()/show_in_rest entirely. The standard WP
 * REST API only ever exposes/accepts meta explicitly registered for REST,
 * which excludes most ACF fields and other custom fields by default — a GET
 * here returns everything, and a POST/PUT here writes everything, neither
 * silently dropping unregistered keys the way the standard 'meta' object
 * does. This route lets the sync checker compare, detect real drift in, and
 * push/pull meta the standard 'meta' field would never see or accept — as
 * long as the site on the other end is also running this plugin version,
 * since it's the one serving this route.
 */
class Rest_In_Sync_Rest_Controller {

	const NAMESPACE_NAME = 'rest-in-sync/v1';
	const ROUTE          = '/meta/(?P<post_id>\d+)';
	const ROUTE_VERSION  = '/version';

	/** Header carrying this site's plugin version on REST responses. */
	const VERSION_HEADER = 'X-Rest-In-Sync-Version';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'add_version_header' ), 10, 1 );
	}

	/**
	 * Stamps the plugin version on every REST response.
	 *
	 * The other end of a sync pair already makes plenty of authenticated
	 * requests here, so it can read the version off any of them instead of
	 * asking a dedicated route each time. Only added for logged-in requests, so
	 * this does not advertise the installed version to anonymous visitors.
	 *
	 * @param WP_HTTP_Response $response
	 * @return WP_HTTP_Response
	 */
	public function add_version_header( $response ) {
		if ( $response instanceof WP_HTTP_Response && is_user_logged_in() ) {
			$response->header( self::VERSION_HEADER, REST_IN_SYNC_VERSION );
		}

		return $response;
	}

	public function register_routes() {
		/*
		 * Lets the other end of a sync pair discover which version it is talking
		 * to. Both sites move meta through the route below, so they have to
		 * agree on what it accepts and returns; a mismatch is what blocks a push.
		 * Requires an authenticated user, so this does not advertise the
		 * installed version to anonymous visitors.
		 */
		register_rest_route( self::NAMESPACE_NAME, self::ROUTE_VERSION, array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_version' ),
				'permission_callback' => array( $this, 'check_version_permission' ),
			),
		) );

		register_rest_route( self::NAMESPACE_NAME, self::ROUTE, array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_all_meta' ),
				'permission_callback' => array( $this, 'check_permission' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_meta' ),
				'permission_callback' => array( $this, 'check_permission' ),
			),
		) );
	}

	/**
	 * Same trust boundary as the rest of the sync connection: the request must
	 * authenticate (e.g. via Application Passwords) as a user allowed to edit
	 * this specific post.
	 */
	/**
	 * Reports this site's plugin version.
	 *
	 * @return WP_REST_Response
	 */
	public function get_version() {
		return new WP_REST_Response(
			array(
				'version' => REST_IN_SYNC_VERSION,
				'plugin'  => 'rest-in-sync',
			),
			200
		);
	}

	/**
	 * Any authenticated user who can edit content may ask for the version — the
	 * same people who can use the sync features that depend on it.
	 *
	 * @return bool
	 */
	public function check_version_permission() {
		return current_user_can( 'edit_posts' );
	}

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

	/**
	 * Writes arbitrary meta directly via update_post_meta(), given a
	 * {"meta": {"key": "value", ...}} request body. Protected (underscore-
	 * prefixed) keys are refused — the same boundary get_all_meta() applies —
	 * so this can't be used to overwrite internal bookkeeping, including this
	 * plugin's own linking meta.
	 *
	 * @return WP_REST_Response {"updated": ["key", ...]}
	 */
	public function update_meta( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$meta    = $request->get_param( 'meta' );

		if ( ! is_array( $meta ) ) {
			return new WP_Error( 'rest_in_sync_invalid_meta', __( 'The "meta" parameter must be an object of meta_key => value.', 'rest-in-sync' ), array( 'status' => 400 ) );
		}

		$updated = array();

		foreach ( $meta as $meta_key => $meta_value ) {
			if ( ! is_string( $meta_key ) || '' === $meta_key || 0 === strpos( $meta_key, '_' ) ) {
				continue;
			}

			update_post_meta( $post_id, $meta_key, $meta_value );
			$updated[] = $meta_key;
		}

		return rest_ensure_response( array( 'updated' => $updated ) );
	}
}
