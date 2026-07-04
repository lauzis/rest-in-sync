<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tests the configured connection to the remote WordPress REST API by fetching
 * the most recently updated posts and pages.
 */
class Rest_In_Sync_Connection_Tester {

	const ITEMS_TO_FETCH = 10;

	/**
	 * @return array{success:bool, message:string, items?:array}
	 */
	public function test() {
		$site_url     = trim( (string) Rest_In_Sync_Settings::get_site_url() );
		$username     = trim( (string) Rest_In_Sync_Settings::get_username() );
		$app_password = trim( (string) Rest_In_Sync_Settings::get_app_password() );

		if ( '' === $site_url || '' === $username || '' === $app_password ) {
			return array(
				'success' => false,
				'message' => __( 'Please fill in the site URL, username and application password before testing the connection.', 'rest-in-sync' ),
			);
		}

		$items_or_error = $this->fetch_recently_updated( $site_url, $username, $app_password );

		if ( is_wp_error( $items_or_error ) ) {
			return array(
				'success' => false,
				'message' => $items_or_error->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of items fetched */
				_n( 'Connection successful. Fetched %d recently updated item.', 'Connection successful. Fetched %d recently updated items.', count( $items_or_error ), 'rest-in-sync' ),
				count( $items_or_error )
			),
			'items'   => $items_or_error,
		);
	}

	/**
	 * @return array|\WP_Error
	 */
	private function fetch_recently_updated( $site_url, $username, $app_password ) {
		$posts = $this->fetch_post_type( $site_url, $username, $app_password, 'posts' );
		if ( is_wp_error( $posts ) ) {
			return $posts;
		}

		$pages = $this->fetch_post_type( $site_url, $username, $app_password, 'pages' );
		if ( is_wp_error( $pages ) ) {
			return $pages;
		}

		$items = array_merge( $posts, $pages );

		usort( $items, function ( $a, $b ) {
			return strcmp( $b['modified'], $a['modified'] );
		} );

		return array_slice( $items, 0, self::ITEMS_TO_FETCH );
	}

	/**
	 * @return array|\WP_Error
	 */
	private function fetch_post_type( $site_url, $username, $app_password, $post_type ) {
		$endpoint = trailingslashit( $site_url ) . 'wp-json/wp/v2/' . $post_type;
		$endpoint = add_query_arg( array(
			'orderby'  => 'modified',
			'order'    => 'desc',
			'per_page' => self::ITEMS_TO_FETCH,
			'context'  => 'edit',
			'_fields'  => 'id,title,link,modified,type',
		), $endpoint );

		$response = wp_remote_get( $endpoint, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $username . ':' . $app_password ),
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = is_array( $body ) && isset( $body['message'] ) ? $body['message'] : wp_remote_retrieve_response_message( $response );

			return new WP_Error(
				'rest_in_sync_remote_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: error message from the remote site */
					__( 'Remote site returned an error (HTTP %1$d): %2$s', 'rest-in-sync' ),
					$status_code,
					$message
				)
			);
		}

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'rest_in_sync_invalid_response', __( 'Remote site returned an unexpected response.', 'rest-in-sync' ) );
		}

		$items = array();
		foreach ( $body as $entry ) {
			$items[] = array(
				'id'       => isset( $entry['id'] ) ? $entry['id'] : 0,
				'title'    => isset( $entry['title']['rendered'] ) ? $entry['title']['rendered'] : '',
				'link'     => isset( $entry['link'] ) ? $entry['link'] : '',
				'modified' => isset( $entry['modified'] ) ? $entry['modified'] : '',
				'type'     => isset( $entry['type'] ) ? $entry['type'] : $post_type,
			);
		}

		return $items;
	}
}
