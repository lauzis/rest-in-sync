<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch-checks local posts against the remote site, records a diff JSON file
 * when they've drifted apart, and keeps each post's sync status meta current.
 */
class Rest_In_Sync_Sync_Checker {

	const META_UUID         = '_rest_in_sync_uuid';
	const META_REMOTE_ID    = '_rest_in_sync_remote_id';
	const META_STATUS       = '_rest_in_sync_status';
	const META_LAST_CHECKED = '_rest_in_sync_last_checked';
	const META_DIFF_ID      = '_rest_in_sync_diff_id';

	const STATUS_NEVER_SYNCED = 'never_synced';
	const STATUS_IN_SYNC      = 'in_sync';
	const STATUS_OUT_OF_SYNC  = 'out_of_sync';

	const ELIGIBLE_POST_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	const GUID_LOOKUP_MAX_PAGES = 5;

	/** Cron callback entry point. */
	public static function run() {
		( new self() )->run_batch();
	}

	/** Re-runs the sync check for a single post, e.g. right after a manual push. */
	public function check_single( WP_Post $post ) {
		$this->check_post( $post );
	}

	/**
	 * Builds a live, full field-by-field comparison against the remote post for
	 * the Details view, including fields with equal values and fields excluded
	 * from the diff calculation (which still stay visible there).
	 *
	 * @return array{remote_id:int, rows:array[]}|WP_Error
	 */
	public function get_comparison( WP_Post $post ) {
		$remote_id = $this->get_or_find_remote_id( $post );

		if ( ! $remote_id ) {
			return new WP_Error( 'rest_in_sync_no_remote_match', __( 'No matching post was found on the remote site.', 'rest-in-sync' ) );
		}

		$remote_post = $this->fetch_remote_post( $post->post_type, $remote_id );

		if ( is_wp_error( $remote_post ) ) {
			return $remote_post;
		}

		return array(
			'remote_id' => $remote_id,
			'rows'      => $this->compare_all_fields( $post, $remote_post ),
		);
	}

	/**
	 * Pushes the selected fields/meta to the remote post, then re-runs the sync
	 * check for this post so its status and diff file reflect the new state.
	 *
	 * @param string[] $selected_keys Field/meta keys to push, as returned by get_comparison().
	 * @return true|WP_Error
	 */
	public function push_to_remote( WP_Post $post, array $selected_keys ) {
		$remote_id = $this->get_or_find_remote_id( $post );

		if ( ! $remote_id ) {
			return new WP_Error( 'rest_in_sync_no_remote_match', __( 'No matching post was found on the remote site.', 'rest-in-sync' ) );
		}

		$standard_fields = array( 'title', 'content', 'excerpt', 'status' );
		$body            = array();
		$meta            = array();

		foreach ( $selected_keys as $key ) {
			if ( in_array( $key, $standard_fields, true ) ) {
				$body[ $key ] = 'title' === $key ? $post->post_title : ( 'content' === $key ? $post->post_content : ( 'excerpt' === $key ? $post->post_excerpt : $post->post_status ) );
			} else {
				$meta[ $key ] = get_post_meta( $post->ID, $key, true );
			}
		}

		if ( ! empty( $meta ) ) {
			$body['meta'] = $meta;
		}

		if ( empty( $body ) ) {
			return new WP_Error( 'rest_in_sync_no_fields', __( 'No fields were selected to push.', 'rest-in-sync' ) );
		}

		$endpoint = $this->rest_endpoint( $this->get_rest_base( $post->post_type ) ) . '/' . $remote_id;
		$response = $this->remote_post( $endpoint, $body );

		if ( is_wp_error( $response ) ) {
			Rest_In_Sync_Logs::add_error( 'push_to_remote', $response->get_error_message(), array( 'post_id' => $post->ID ) );

			return $response;
		}

		Rest_In_Sync_Logs::add_log(
			'push_to_remote',
			sprintf( 'Pushed %d field(s) for post #%d', count( $selected_keys ), $post->ID ),
			array( 'post_id' => $post->ID, 'fields' => $selected_keys )
		);

		$this->check_post( $post );

		return true;
	}

	private function get_or_find_remote_id( WP_Post $post ) {
		$remote_id = (int) get_post_meta( $post->ID, self::META_REMOTE_ID, true );

		if ( ! $remote_id ) {
			$remote_id = $this->find_remote_id( $post );

			if ( $remote_id ) {
				update_post_meta( $post->ID, self::META_REMOTE_ID, $remote_id );
			}
		}

		return $remote_id;
	}

	public function run_batch() {
		$post_types = Rest_In_Sync_Settings::get_post_types_to_sync();

		if ( empty( $post_types ) ) {
			return;
		}

		$posts = $this->get_batch( $post_types, Rest_In_Sync_Settings::get_cron_batch_size() );

		if ( empty( $posts ) ) {
			return;
		}

		Rest_In_Sync_Logs::add_log( 'sync_check_batch', 'Starting batch sync check', array( 'count' => count( $posts ) ) );

		foreach ( $posts as $post ) {
			$this->check_post( $post );
		}
	}

	/**
	 * Selects posts to check this run: posts never checked before, then (if
	 * there's room left in the batch) posts whose last check is older than
	 * the configured resync threshold, oldest first.
	 *
	 * @return WP_Post[]
	 */
	private function get_batch( $post_types, $batch_size ) {
		$never_checked = get_posts( array(
			'post_type'      => $post_types,
			'post_status'    => self::ELIGIBLE_POST_STATUSES,
			'posts_per_page' => $batch_size,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => self::META_LAST_CHECKED,
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		$post_ids  = $never_checked;
		$remaining = $batch_size - count( $never_checked );

		if ( $remaining > 0 ) {
			$threshold_cutoff = time() - ( Rest_In_Sync_Settings::get_resync_threshold_hours() * HOUR_IN_SECONDS );

			$due_for_resync = get_posts( array(
				'post_type'      => $post_types,
				'post_status'    => self::ELIGIBLE_POST_STATUSES,
				'posts_per_page' => $remaining,
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'meta_key'       => self::META_LAST_CHECKED,
				'meta_query'     => array(
					array(
						'key'     => self::META_LAST_CHECKED,
						'value'   => $threshold_cutoff,
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			) );

			$post_ids = array_merge( $post_ids, $due_for_resync );
		}

		return array_map( 'get_post', $post_ids );
	}

	private function check_post( WP_Post $post ) {
		if ( ! get_post_meta( $post->ID, self::META_UUID, true ) ) {
			update_post_meta( $post->ID, self::META_UUID, wp_generate_uuid4() );
			update_post_meta( $post->ID, self::META_STATUS, self::STATUS_NEVER_SYNCED );
		}

		$remote_id = $this->get_or_find_remote_id( $post );

		if ( ! $remote_id ) {
			$this->finish_check( $post, self::STATUS_OUT_OF_SYNC, array(
				'reason' => 'No matching post was found on the remote site.',
			) );

			return;
		}

		$remote_post = $this->fetch_remote_post( $post->post_type, $remote_id );

		if ( is_wp_error( $remote_post ) ) {
			Rest_In_Sync_Logs::add_error( 'sync_check', $remote_post->get_error_message(), array( 'post_id' => $post->ID ) );

			return;
		}

		$diff = $this->build_diff( $post, $remote_post );

		if ( empty( $diff ) ) {
			$this->finish_check( $post, self::STATUS_IN_SYNC );

			return;
		}

		$this->finish_check( $post, self::STATUS_OUT_OF_SYNC, $diff );
	}

	private function finish_check( WP_Post $post, $status, array $diff = array() ) {
		update_post_meta( $post->ID, self::META_STATUS, $status );
		update_post_meta( $post->ID, self::META_LAST_CHECKED, time() );

		if ( self::STATUS_OUT_OF_SYNC === $status ) {
			update_post_meta( $post->ID, self::META_DIFF_ID, $this->write_diff_file( $post, $diff ) );
		} else {
			$this->clear_diff_file( $post );
		}

		Rest_In_Sync_Logs::add_log(
			'sync_check',
			sprintf( 'Checked post #%d: %s', $post->ID, $status ),
			array( 'post_id' => $post->ID, 'status' => $status )
		);
	}

	private function write_diff_file( WP_Post $post, array $diff ) {
		if ( ! is_dir( REST_IN_SYNC_DIFF_PATH ) ) {
			wp_mkdir_p( REST_IN_SYNC_DIFF_PATH );
		}

		$this->clear_diff_file( $post );

		$diff_id = wp_generate_uuid4();
		$payload = array(
			'post_id'    => $post->ID,
			'uuid'       => get_post_meta( $post->ID, self::META_UUID, true ),
			'checked_at' => gmdate( 'c' ),
			'diff'       => $diff,
		);

		file_put_contents( REST_IN_SYNC_DIFF_PATH . $diff_id . '.json', wp_json_encode( $payload, JSON_PRETTY_PRINT ) );

		return $diff_id;
	}

	private function clear_diff_file( WP_Post $post ) {
		$diff_id = get_post_meta( $post->ID, self::META_DIFF_ID, true );

		if ( ! $diff_id ) {
			return;
		}

		$path = REST_IN_SYNC_DIFF_PATH . $diff_id . '.json';

		if ( file_exists( $path ) ) {
			unlink( $path );
		}

		delete_post_meta( $post->ID, self::META_DIFF_ID );
	}

	private function find_remote_id( WP_Post $post ) {
		$rest_base = $this->get_rest_base( $post->post_type );

		$remote_id = $this->find_remote_id_by_slug( $rest_base, $post->post_name );

		if ( $remote_id ) {
			return $remote_id;
		}

		return $this->find_remote_id_by_guid( $rest_base, $post->guid );
	}

	private function find_remote_id_by_slug( $rest_base, $slug ) {
		if ( '' === (string) $slug ) {
			return 0;
		}

		$response = $this->remote_get( $this->rest_endpoint( $rest_base ), array(
			'slug'    => $slug,
			'context' => 'edit',
			'_fields' => 'id,slug',
		) );

		if ( is_wp_error( $response ) || empty( $response[0]['id'] ) ) {
			return 0;
		}

		return (int) $response[0]['id'];
	}

	/**
	 * Falls back to scanning the remote post type's items for a matching GUID
	 * when no post shares the local slug. Capped to a handful of pages so a
	 * miss doesn't turn into an unbounded crawl of the remote site.
	 */
	private function find_remote_id_by_guid( $rest_base, $guid ) {
		if ( '' === (string) $guid ) {
			return 0;
		}

		for ( $page = 1; $page <= self::GUID_LOOKUP_MAX_PAGES; $page++ ) {
			$response = $this->remote_get( $this->rest_endpoint( $rest_base ), array(
				'per_page' => 100,
				'page'     => $page,
				'context'  => 'edit',
				'_fields'  => 'id,guid',
			) );

			if ( is_wp_error( $response ) || empty( $response ) ) {
				break;
			}

			foreach ( $response as $entry ) {
				$remote_guid = isset( $entry['guid']['rendered'] ) ? $entry['guid']['rendered'] : '';

				if ( $remote_guid === $guid ) {
					return (int) $entry['id'];
				}
			}

			if ( count( $response ) < 100 ) {
				break;
			}
		}

		return 0;
	}

	private function fetch_remote_post( $post_type, $remote_id ) {
		return $this->remote_get( $this->rest_endpoint( $this->get_rest_base( $post_type ) ) . '/' . $remote_id, array(
			'context' => 'edit',
		) );
	}

	/**
	 * Compares local post fields and meta against the remote post, returning
	 * only the fields that differ and aren't flagged exclude_from_diff.
	 *
	 * @return array
	 */
	private function build_diff( WP_Post $post, array $remote ) {
		$diff = array();

		foreach ( $this->compare_all_fields( $post, $remote ) as $row ) {
			if ( ! $row['differs'] || Rest_In_Sync_Field_Settings::is_excluded_from_diff( $row['key'] ) ) {
				continue;
			}

			$values = array( 'local' => $row['local'], 'remote' => $row['remote'] );

			if ( 'meta' === $row['type'] ) {
				$diff['meta'][ $row['key'] ] = $values;
			} else {
				$diff[ $row['key'] ] = $values;
			}
		}

		return $diff;
	}

	/**
	 * Builds a full field-by-field comparison of the local post against the
	 * remote post: standard fields plus any meta the remote site exposes via
	 * REST (excluding this plugin's own bookkeeping meta). Includes fields
	 * with equal values, unlike build_diff(), so callers like the Details
	 * view can show the complete field list.
	 *
	 * @return array[] Each row: type ('field'|'meta'), key, label, local, remote, differs.
	 */
	private function compare_all_fields( WP_Post $post, array $remote ) {
		$rows = array();

		$standard_fields = array(
			'title'   => array( $post->post_title, $this->remote_field_value( $remote, 'title' ), __( 'Title', 'rest-in-sync' ) ),
			'content' => array( $post->post_content, $this->remote_field_value( $remote, 'content' ), __( 'Content', 'rest-in-sync' ) ),
			'excerpt' => array( $post->post_excerpt, $this->remote_field_value( $remote, 'excerpt' ), __( 'Excerpt', 'rest-in-sync' ) ),
			'status'  => array( $post->post_status, isset( $remote['status'] ) ? $remote['status'] : '', __( 'Status', 'rest-in-sync' ) ),
		);

		foreach ( $standard_fields as $key => $values ) {
			list( $local_value, $remote_value, $label ) = $values;

			$rows[] = array(
				'type'    => 'field',
				'key'     => $key,
				'label'   => $label,
				'local'   => $local_value,
				'remote'  => $remote_value,
				'differs' => (string) $local_value !== (string) $remote_value,
			);
		}

		if ( ! empty( $remote['meta'] ) && is_array( $remote['meta'] ) ) {
			foreach ( $remote['meta'] as $meta_key => $remote_value ) {
				if ( 0 === strpos( $meta_key, '_rest_in_sync_' ) ) {
					continue;
				}

				$local_value = get_post_meta( $post->ID, $meta_key, true );

				$rows[] = array(
					'type'    => 'meta',
					'key'     => $meta_key,
					'label'   => $meta_key,
					'local'   => $local_value,
					'remote'  => $remote_value,
					'differs' => (string) $local_value !== (string) $remote_value,
				);
			}
		}

		return $rows;
	}

	private function remote_field_value( array $remote, $field ) {
		if ( isset( $remote[ $field ]['raw'] ) ) {
			return $remote[ $field ]['raw'];
		}

		return isset( $remote[ $field ]['rendered'] ) ? $remote[ $field ]['rendered'] : '';
	}

	private function rest_endpoint( $rest_base ) {
		return trailingslashit( trim( (string) Rest_In_Sync_Settings::get_site_url() ) ) . 'wp-json/wp/v2/' . $rest_base;
	}

	private function get_rest_base( $post_type ) {
		$post_type_object = get_post_type_object( $post_type );

		if ( $post_type_object && ! empty( $post_type_object->rest_base ) ) {
			return $post_type_object->rest_base;
		}

		return $post_type;
	}

	/**
	 * @return array|WP_Error Decoded JSON body, or WP_Error on failure.
	 */
	private function remote_get( $endpoint, array $query_args = array() ) {
		$username     = trim( (string) Rest_In_Sync_Settings::get_username() );
		$app_password = trim( (string) Rest_In_Sync_Settings::get_app_password() );

		$response = wp_remote_get( add_query_arg( $query_args, $endpoint ), array(
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

			return new WP_Error( 'rest_in_sync_remote_error', $message );
		}

		return is_array( $body ) ? $body : array();
	}

	/**
	 * @return array|WP_Error Decoded JSON body, or WP_Error on failure.
	 */
	private function remote_post( $endpoint, array $body ) {
		$username     = trim( (string) Rest_In_Sync_Settings::get_username() );
		$app_password = trim( (string) Rest_In_Sync_Settings::get_app_password() );

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $username . ':' . $app_password ),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$decoded     = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = is_array( $decoded ) && isset( $decoded['message'] ) ? $decoded['message'] : wp_remote_retrieve_response_message( $response );

			return new WP_Error( 'rest_in_sync_remote_error', $message );
		}

		return is_array( $decoded ) ? $decoded : array();
	}
}
