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
	const META_IGNORED      = '_rest_in_sync_ignored';
	const META_CHECKED_VERSION = '_rest_in_sync_checked_version';

	const STATUS_NEVER_SYNCED = 'never_synced';
	const STATUS_IN_SYNC      = 'in_sync';
	const STATUS_OUT_OF_SYNC  = 'out_of_sync';

	const ELIGIBLE_POST_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	const GUID_LOOKUP_MAX_PAGES = 5;

	/**
	 * Exposes the UUID meta via REST (both for reading it back from the remote
	 * site during lookups, and so wp_remote_post() writes to it are accepted).
	 * Runs on both ends since the same plugin is installed on the remote site.
	 */
	public static function register_meta_fields() {
		register_meta( 'post', self::META_UUID, array(
			'show_in_rest'  => true,
			'single'        => true,
			'type'          => 'string',
			// Protected (underscore-prefixed) meta is REST-read-only by default;
			// this is written by the sync connection's own authenticated user.
			'auth_callback' => '__return_true',
		) );
	}

	/** Cron callback entry point. */
	public static function run() {
		( new self() )->run_batch();
	}

	/** Re-runs the sync check for a single post, e.g. right after a manual push. */
	public function check_single( WP_Post $post ) {
		$this->check_post( $post );
	}

	/**
	 * Snoozes this post's out-of-sync status until the next check (cron or
	 * manual) runs for it — that check clears the flag again regardless of
	 * its outcome, so this is a one-time "stop bothering me about this until
	 * you've actually looked again" rather than a permanent dismissal.
	 */
	public function ignore_until_next_check( WP_Post $post ) {
		update_post_meta( $post->ID, self::META_IGNORED, '1' );
	}

	/**
	 * Builds a live, full field-by-field comparison against the remote post for
	 * the Details view, including fields with equal values and fields excluded
	 * from the diff calculation (which still stay visible there).
	 *
	 * @return array{remote_id:int, remote_url:string, local_edit_url:string, remote_edit_url:string, local_revision_url:string, remote_revision_url:string, rows:array[]}|WP_Error
	 */
	public function get_comparison( WP_Post $post ) {
		if ( Rest_In_Sync_Settings::is_remote_server() ) {
			return $this->remote_server_error();
		}

		if ( ! Rest_In_Sync_Settings::is_connection_configured() ) {
			return $this->not_configured_error();
		}

		$remote_id = $this->get_or_find_remote_id( $post );

		if ( ! $remote_id ) {
			Rest_In_Sync_Logs::add_error( 'get_comparison', 'No matching post was found on the remote site.', array( 'post_id' => $post->ID ) );

			return new WP_Error( 'rest_in_sync_no_remote_match', __( 'No matching post was found on the remote site.', 'rest-in-sync' ) );
		}

		$remote_post = $this->fetch_remote_post( $post->post_type, $remote_id );

		if ( is_wp_error( $remote_post ) ) {
			Rest_In_Sync_Logs::add_error( 'get_comparison', $remote_post->get_error_message(), array( 'post_id' => $post->ID, 'remote_id' => $remote_id ) );

			return $remote_post;
		}

		$remote_home        = untrailingslashit( trim( (string) Rest_In_Sync_Settings::get_site_url() ) );
		$local_revision_id  = $this->get_local_latest_revision_id( $post );
		$remote_revision_id = $this->fetch_remote_latest_revision_id( $post->post_type, $remote_id );

		return array(
			'remote_id'           => $remote_id,
			'remote_url'          => isset( $remote_post['link'] ) ? $remote_post['link'] : '',
			'local_edit_url'      => (string) get_edit_post_link( $post->ID, 'raw' ),
			'remote_edit_url'     => $remote_home . '/wp-admin/post.php?post=' . $remote_id . '&action=edit',
			'local_revision_url'  => $local_revision_id ? admin_url( 'revision.php?revision=' . $local_revision_id ) : '',
			'remote_revision_url' => $remote_revision_id ? $remote_home . '/wp-admin/revision.php?revision=' . $remote_revision_id : '',
			'rows'                => $this->compare_all_fields( $post, $remote_post, $remote_id ),
		);
	}

	/**
	 * @return int The local post's most recent revision ID, or 0 if it has none yet.
	 */
	private function get_local_latest_revision_id( WP_Post $post ) {
		$revisions = wp_get_post_revisions( $post->ID, array( 'numberposts' => 1, 'fields' => 'ids' ) );

		return $revisions ? (int) reset( $revisions ) : 0;
	}

	/**
	 * Pushes the selected fields/meta to the remote post, then re-runs the sync
	 * check for this post so its status and diff file reflect the new state.
	 *
	 * @param string[] $selected_keys Field/meta keys to push, as returned by get_comparison().
	 * @return true|WP_Error
	 */
	public function push_to_remote( WP_Post $post, array $selected_keys ) {
		// Both ends move meta through this plugin's own REST route, so a version
		// mismatch means the other end may store or accept fields differently.
		// Refusing here covers the AJAX handler, the cron and any future caller,
		// not just the button.
		$versions = Rest_In_Sync_Version::check();

		if ( is_wp_error( $versions ) ) {
			Rest_In_Sync_Logs::add_error( 'push', $versions->get_error_message(), array( 'post_id' => $post->ID ) );

			return $versions;
		}

		if ( Rest_In_Sync_Settings::is_remote_server() ) {
			return $this->remote_server_error();
		}

		if ( ! Rest_In_Sync_Settings::is_connection_configured() ) {
			return $this->not_configured_error();
		}

		$remote_id = $this->get_or_find_remote_id( $post );

		if ( ! $remote_id ) {
			Rest_In_Sync_Logs::add_error( 'push_to_remote', 'No matching post was found on the remote site.', array( 'post_id' => $post->ID ) );

			return new WP_Error( 'rest_in_sync_no_remote_match', __( 'No matching post was found on the remote site.', 'rest-in-sync' ) );
		}

		$local_home  = home_url();
		$remote_home = Rest_In_Sync_Settings::get_site_url();

		$standard_fields = array( 'title', 'content', 'excerpt', 'status' );
		$body            = array();
		$meta            = array();

		foreach ( $selected_keys as $key ) {
			if ( in_array( $key, $standard_fields, true ) ) {
				$value = 'title' === $key ? $post->post_title : ( 'content' === $key ? $post->post_content : ( 'excerpt' === $key ? $post->post_excerpt : $post->post_status ) );
				$body[ $key ] = 'status' === $key ? $value : $this->rewrite_home_url( $value, $local_home, $remote_home );
			} else {
				$meta[ $key ] = $this->rewrite_home_url( get_post_meta( $post->ID, $key, true ), $local_home, $remote_home );
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

		if ( ! empty( $meta ) ) {
			$this->push_remote_meta( $post->ID, $remote_id, $meta );
		}

		Rest_In_Sync_Logs::add_log(
			'push_to_remote',
			sprintf( 'Pushed %d field(s) for post #%d', count( $selected_keys ), $post->ID ),
			array( 'post_id' => $post->ID, 'fields' => $selected_keys )
		);

		$this->check_post( $post );

		return true;
	}

	/**
	 * Pulls the selected fields/meta from the remote post onto this local
	 * post, then re-runs the sync check so its status and diff reflect the
	 * new state. The mirror image of push_to_remote(): same field selection,
	 * same home-URL rewrite (in the opposite direction), but writing to this
	 * site via wp_update_post()/update_post_meta() instead of a remote REST call.
	 *
	 * @param string[] $selected_keys Field/meta keys to pull, as returned by get_comparison().
	 * @return true|WP_Error
	 */
	public function pull_from_remote( WP_Post $post, array $selected_keys ) {
		// Both ends move meta through this plugin's own REST route, so a version
		// mismatch means the other end may store or accept fields differently.
		// Refusing here covers the AJAX handler, the cron and any future caller,
		// not just the button.
		$versions = Rest_In_Sync_Version::check();

		if ( is_wp_error( $versions ) ) {
			Rest_In_Sync_Logs::add_error( 'pull', $versions->get_error_message(), array( 'post_id' => $post->ID ) );

			return $versions;
		}

		if ( Rest_In_Sync_Settings::is_remote_server() ) {
			return $this->remote_server_error();
		}

		if ( ! Rest_In_Sync_Settings::is_connection_configured() ) {
			return $this->not_configured_error();
		}

		$remote_id = $this->get_or_find_remote_id( $post );

		if ( ! $remote_id ) {
			Rest_In_Sync_Logs::add_error( 'pull_from_remote', 'No matching post was found on the remote site.', array( 'post_id' => $post->ID ) );

			return new WP_Error( 'rest_in_sync_no_remote_match', __( 'No matching post was found on the remote site.', 'rest-in-sync' ) );
		}

		$remote_post = $this->fetch_remote_post( $post->post_type, $remote_id );

		if ( is_wp_error( $remote_post ) ) {
			Rest_In_Sync_Logs::add_error( 'pull_from_remote', $remote_post->get_error_message(), array( 'post_id' => $post->ID, 'remote_id' => $remote_id ) );

			return $remote_post;
		}

		$local_home  = home_url();
		$remote_home = Rest_In_Sync_Settings::get_site_url();

		$standard_field_map = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'status'  => 'post_status',
		);

		$postarr      = array( 'ID' => $post->ID );
		$meta_updates = array();
		$remote_meta  = null;

		foreach ( $selected_keys as $key ) {
			if ( isset( $standard_field_map[ $key ] ) ) {
				$value = 'status' === $key
					? ( isset( $remote_post['status'] ) ? $remote_post['status'] : $post->post_status )
					: $this->remote_field_value( $remote_post, $key );

				$postarr[ $standard_field_map[ $key ] ] = 'status' === $key ? $value : $this->rewrite_home_url( $value, $remote_home, $local_home );
			} else {
				if ( null === $remote_meta ) {
					$remote_meta = $this->get_remote_meta_map( $remote_post, $remote_id );
				}

				if ( array_key_exists( $key, $remote_meta ) ) {
					$meta_updates[ $key ] = $this->rewrite_home_url( $remote_meta[ $key ], $remote_home, $local_home );
				}
			}
		}

		if ( count( $postarr ) <= 1 && empty( $meta_updates ) ) {
			return new WP_Error( 'rest_in_sync_no_fields', __( 'No fields were selected to pull.', 'rest-in-sync' ) );
		}

		if ( count( $postarr ) > 1 ) {
			$updated = wp_update_post( wp_slash( $postarr ), true );

			if ( is_wp_error( $updated ) ) {
				Rest_In_Sync_Logs::add_error( 'pull_from_remote', $updated->get_error_message(), array( 'post_id' => $post->ID ) );

				return $updated;
			}
		}

		foreach ( $meta_updates as $meta_key => $meta_value ) {
			update_post_meta( $post->ID, $meta_key, $meta_value );
		}

		Rest_In_Sync_Logs::add_log(
			'pull_from_remote',
			sprintf( 'Pulled %d field(s) for post #%d', count( $selected_keys ), $post->ID ),
			array( 'post_id' => $post->ID, 'fields' => $selected_keys )
		);

		$this->check_post( $post );

		return true;
	}

	private function not_configured_error() {
		return new WP_Error(
			'rest_in_sync_not_configured',
			__( 'The remote site connection has not been configured yet. Please fill in the Site URL, Username, and Application Password on the Settings page.', 'rest-in-sync' )
		);
	}

	private function remote_server_error() {
		return new WP_Error(
			'rest_in_sync_is_remote_server',
			__( 'This site is configured as the remote server of a sync pair, so it never initiates its own sync checks or pushes.', 'rest-in-sync' )
		);
	}

	private function get_or_find_remote_id( WP_Post $post ) {
		$remote_id = (int) get_post_meta( $post->ID, self::META_REMOTE_ID, true );

		if ( ! $remote_id ) {
			$remote_id = $this->find_remote_id( $post );

			if ( $remote_id ) {
				update_post_meta( $post->ID, self::META_REMOTE_ID, $remote_id );
				$this->stamp_remote_uuid( $post, $remote_id );
			}
		}

		return $remote_id;
	}

	/**
	 * Writes this post's UUID onto the newly-matched remote post so future
	 * lookups can match on UUID instead of relying on slug/guid, which can
	 * drift (e.g. after a title change) or never have coincided at all.
	 */
	private function stamp_remote_uuid( WP_Post $post, $remote_id ) {
		$uuid = get_post_meta( $post->ID, self::META_UUID, true );

		if ( ! $uuid ) {
			return;
		}

		$endpoint = $this->rest_endpoint( $this->get_rest_base( $post->post_type ) ) . '/' . $remote_id;
		$response = $this->remote_post( $endpoint, array( 'meta' => array( self::META_UUID => $uuid ) ) );

		if ( is_wp_error( $response ) ) {
			Rest_In_Sync_Logs::add_error( 'stamp_remote_uuid', $response->get_error_message(), array( 'post_id' => $post->ID, 'remote_id' => $remote_id ) );
		}
	}

	/**
	 * Writes meta onto the remote post via this plugin's own
	 * '/rest-in-sync/v1/meta/{id}' route, bypassing register_meta()/
	 * show_in_rest — the standard REST API silently drops any meta key in a
	 * post update's 'meta' object that isn't registered for REST, which is
	 * most of them (most ACF fields, etc.), so pushing an unregistered meta
	 * key via the normal endpoint alone has no effect at all. A failure here
	 * is logged but doesn't fail the overall push, since the standard fields
	 * (if any were selected) already succeeded, and the remote might simply
	 * be running an older plugin version without this route.
	 */
	private function push_remote_meta( $post_id, $remote_id, array $meta ) {
		$endpoint = trailingslashit( trim( (string) Rest_In_Sync_Settings::get_site_url() ) ) . 'wp-json/rest-in-sync/v1/meta/' . $remote_id;
		$response = $this->remote_post( $endpoint, array( 'meta' => $meta ) );

		if ( is_wp_error( $response ) ) {
			Rest_In_Sync_Logs::add_error( 'push_remote_meta', $response->get_error_message(), array( 'post_id' => $post_id, 'remote_id' => $remote_id ) );
		}
	}

	public function run_batch() {
		if ( Rest_In_Sync_Settings::is_remote_server() || ! Rest_In_Sync_Settings::is_connection_configured() ) {
			return;
		}

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
		global $wpdb;

		$types    = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$statuses = implode( ',', array_fill( 0, count( self::ELIGIBLE_POST_STATUSES ), '%s' ) );

		/*
		 * Posts with no slug are excluded in SQL rather than filtered out in PHP
		 * afterwards. A post with no slug can never be matched to a remote post by
		 * slug, and its guid is just a local "?p=ID" placeholder that cannot match
		 * a real remote guid either -- so it would only ever be reported as a false
		 * "out of sync" with no way to resolve it.
		 *
		 * Filtering them after selection stalled the cron permanently: slug-less
		 * posts have low IDs, so they filled the never-checked batch, consumed the
		 * whole budget, were then all discarded, and nothing was ever stamped --
		 * so the next run selected exactly the same posts and did nothing again.
		 * Posts genuinely due for a re-check were never reached.
		 */
		$never_checked = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 WHERE p.post_type IN ($types)
				   AND p.post_status IN ($statuses)
				   AND p.post_name != ''
				   AND NOT EXISTS (
				       SELECT 1 FROM {$wpdb->postmeta} m
				       WHERE m.post_id = p.ID AND m.meta_key = %s
				   )
				 ORDER BY p.ID ASC
				 LIMIT %d",
				...array_merge(
					$post_types,
					self::ELIGIBLE_POST_STATUSES,
					array( self::META_LAST_CHECKED, $batch_size )
				)
			)
		);

		$post_ids  = array_map( 'intval', $never_checked );
		$remaining = $batch_size - count( $post_ids );

		if ( $remaining > 0 ) {
			$threshold_cutoff = time() - ( Rest_In_Sync_Settings::get_resync_threshold_hours() * HOUR_IN_SECONDS );

			/*
			 * A post is due either because its check has aged past the
			 * threshold, or because it was checked by a different plugin
			 * version — an upgrade can change which fields are compared and
			 * what counts as a difference, so those results are no longer
			 * trustworthy. Version-stale posts sort first, since a fresh
			 * upgrade should re-check everything rather than trickle.
			 */
			$recheck_on_upgrade = Rest_In_Sync_Settings::recheck_on_version_change();

			$due_for_resync = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} m
					     ON m.post_id = p.ID AND m.meta_key = %s
					 LEFT JOIN {$wpdb->postmeta} v
					     ON v.post_id = p.ID AND v.meta_key = %s
					 WHERE p.post_type IN ($types)
					   AND p.post_status IN ($statuses)
					   AND p.post_name != ''
					   AND (
					       CAST( m.meta_value AS UNSIGNED ) <= %d
					       OR ( %d = 1 AND ( v.meta_value IS NULL OR v.meta_value != %s ) )
					   )
					 ORDER BY
					   CASE WHEN v.meta_value IS NULL OR v.meta_value != %s THEN 0 ELSE 1 END ASC,
					   CAST( m.meta_value AS UNSIGNED ) ASC
					 LIMIT %d",
					...array_merge(
						array( self::META_LAST_CHECKED, self::META_CHECKED_VERSION ),
						$post_types,
						self::ELIGIBLE_POST_STATUSES,
						array(
							$threshold_cutoff,
							$recheck_on_upgrade ? 1 : 0,
							REST_IN_SYNC_VERSION,
							REST_IN_SYNC_VERSION,
							$remaining,
						)
					)
				)
			);

			$post_ids = array_merge( $post_ids, array_map( 'intval', $due_for_resync ) );
		}

		return array_values( array_filter( array_map( 'get_post', $post_ids ) ) );
	}

	private function check_post( WP_Post $post ) {
		if ( Rest_In_Sync_Settings::is_remote_server() || ! Rest_In_Sync_Settings::is_connection_configured() ) {
			return;
		}

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

		$diff = $this->build_diff( $post, $remote_post, $remote_id );

		if ( empty( $diff ) ) {
			$this->finish_check( $post, self::STATUS_IN_SYNC );

			return;
		}

		$this->finish_check( $post, self::STATUS_OUT_OF_SYNC, $diff );
	}

	private function finish_check( WP_Post $post, $status, array $diff = array() ) {
		update_post_meta( $post->ID, self::META_STATUS, $status );
		update_post_meta( $post->ID, self::META_LAST_CHECKED, time() );

		// Recorded so an upgrade can invalidate the result: what counts as a
		// difference, and which fields are compared, can change between
		// versions, so a check made by an older one may no longer be true.
		update_post_meta( $post->ID, self::META_CHECKED_VERSION, REST_IN_SYNC_VERSION );

		// "Ignore until next sync" is a one-time snooze: every completed check
		// consumes it, regardless of outcome, so a post that's still out of
		// sync next time reappears rather than staying hidden indefinitely.
		delete_post_meta( $post->ID, self::META_IGNORED );

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
		$uuid      = get_post_meta( $post->ID, self::META_UUID, true );
		$lang      = $this->get_post_language_code( $post );

		Rest_In_Sync_Logs::add_log( 'find_remote_id', 'Looking up remote post', array(
			'post_id'   => $post->ID,
			'post_type' => $post->post_type,
			'rest_base' => $rest_base,
			'slug'      => $post->post_name,
			'guid'      => $post->guid,
			'uuid'      => $uuid,
			'lang'      => $lang,
		) );

		$remote_id = $this->find_remote_id_by_exact_id( $rest_base, $post->ID );

		if ( $remote_id ) {
			return $remote_id;
		}

		$remote_id = $this->find_remote_id_by_slug( $rest_base, $post->post_name, $lang );

		if ( $remote_id ) {
			return $remote_id;
		}

		$remote_id = $this->find_remote_id_by_guid_or_uuid( $rest_base, $post->guid, $uuid, $lang );

		if ( ! $remote_id ) {
			Rest_In_Sync_Logs::add_log( 'find_remote_id', 'No remote post matched ID, slug, GUID, or UUID', array(
				'post_id'   => $post->ID,
				'rest_base' => $rest_base,
				'slug'      => $post->post_name,
				'guid'      => $post->guid,
				'uuid'      => $uuid,
				'lang'      => $lang,
			) );
		}

		return $remote_id;
	}

	/**
	 * Tries the local post's own ID against the remote first, before slug or
	 * GUID/UUID. Slug alone can't be trusted: WPML, for example, can give
	 * multiple language translations of the same article the exact same slug,
	 * distinguishing them only by a URL language prefix rather than the slug
	 * itself, so a slug lookup can match the wrong translation. When this
	 * site's install was cloned from (or otherwise shares history with) the
	 * remote's database, the vast majority of existing content shares
	 * identical post IDs, which sidesteps that ambiguity entirely. If no post
	 * exists at this ID on the remote, it's treated as genuinely new content
	 * and falls through to the slug/GUID/UUID lookups instead.
	 */
	private function find_remote_id_by_exact_id( $rest_base, $post_id ) {
		if ( ! $post_id ) {
			return 0;
		}

		$endpoint = $this->rest_endpoint( $rest_base ) . '/' . (int) $post_id;
		$response = $this->remote_get( $endpoint, array(
			'context' => 'edit',
			'_fields' => 'id',
		) );

		if ( is_wp_error( $response ) || empty( $response['id'] ) ) {
			return 0;
		}

		return (int) $response['id'];
	}

	/**
	 * Returns this post's WPML language code (e.g. "en", "lv"), or '' if WPML
	 * isn't active or the post isn't managed by it. WPML's REST API integration
	 * scopes collection queries (search-by-slug, list scans) to the site's
	 * default language unless a "lang" query arg says otherwise, so without this
	 * a translated post is invisible to those lookups even though it exists.
	 */
	private function get_post_language_code( WP_Post $post ) {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return '';
		}

		$lang = apply_filters( 'wpml_element_language_code', null, array(
			'element_id'   => $post->ID,
			'element_type' => $post->post_type,
		) );

		return is_string( $lang ) ? $lang : '';
	}

	private function find_remote_id_by_slug( $rest_base, $slug, $lang = '' ) {
		if ( '' === (string) $slug ) {
			return 0;
		}

		$args = array(
			'slug'    => $slug,
			'context' => 'edit',
			'_fields' => 'id,slug',
		);

		if ( '' !== (string) $lang ) {
			$args['lang'] = $lang;
		}

		$endpoint = $this->rest_endpoint( $rest_base );
		$response = $this->remote_get( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			Rest_In_Sync_Logs::add_error( 'find_remote_id_by_slug', $response->get_error_message(), array(
				'endpoint' => $endpoint,
				'slug'     => $slug,
				'lang'     => $lang,
			) );

			return 0;
		}

		if ( empty( $response[0]['id'] ) ) {
			Rest_In_Sync_Logs::add_log( 'find_remote_id_by_slug', 'No remote post found with matching slug', array(
				'endpoint'      => $endpoint,
				'slug'          => $slug,
				'lang'          => $lang,
				'results_count' => count( $response ),
			) );

			return 0;
		}

		return (int) $response[0]['id'];
	}

	/**
	 * Falls back to scanning the remote post type's items for a matching UUID
	 * or GUID when no post shares the local slug. Capped to a handful of pages
	 * so a miss doesn't turn into an unbounded crawl of the remote site.
	 */
	private function find_remote_id_by_guid_or_uuid( $rest_base, $guid, $uuid, $lang = '' ) {
		if ( '' === (string) $guid && '' === (string) $uuid ) {
			return 0;
		}

		$endpoint      = $this->rest_endpoint( $rest_base );
		$pages_scanned = 0;
		$entries_seen  = 0;

		for ( $page = 1; $page <= self::GUID_LOOKUP_MAX_PAGES; $page++ ) {
			$args = array(
				'per_page' => 100,
				'page'     => $page,
				'context'  => 'edit',
				'_fields'  => 'id,guid,meta',
			);

			if ( '' !== (string) $lang ) {
				$args['lang'] = $lang;
			}

			$response = $this->remote_get( $endpoint, $args );

			if ( is_wp_error( $response ) ) {
				Rest_In_Sync_Logs::add_error( 'find_remote_id_by_guid_or_uuid', $response->get_error_message(), array(
					'endpoint' => $endpoint,
					'page'     => $page,
				) );

				break;
			}

			if ( empty( $response ) ) {
				break;
			}

			$pages_scanned++;
			$entries_seen += count( $response );

			foreach ( $response as $entry ) {
				$remote_uuid = isset( $entry['meta'][ self::META_UUID ] ) ? $entry['meta'][ self::META_UUID ] : '';

				if ( '' !== (string) $uuid && $remote_uuid === $uuid ) {
					return (int) $entry['id'];
				}

				$remote_guid = isset( $entry['guid']['rendered'] ) ? $entry['guid']['rendered'] : '';

				if ( $remote_guid === $guid ) {
					return (int) $entry['id'];
				}
			}

			if ( count( $response ) < 100 ) {
				break;
			}
		}

		Rest_In_Sync_Logs::add_log( 'find_remote_id_by_guid_or_uuid', 'No remote post matched GUID or UUID', array(
			'endpoint'      => $endpoint,
			'guid'          => $guid,
			'uuid'          => $uuid,
			'lang'          => $lang,
			'pages_scanned' => $pages_scanned,
			'entries_seen'  => $entries_seen,
		) );

		return 0;
	}

	private function fetch_remote_post( $post_type, $remote_id ) {
		return $this->remote_get( $this->rest_endpoint( $this->get_rest_base( $post_type ) ) . '/' . $remote_id, array(
			'context' => 'edit',
		) );
	}

	/**
	 * The remote post's most recent revision ID, via WordPress core's own
	 * '/revisions' REST route (same auth as everything else here). Used to
	 * deep-link the Details page straight into the remote's revision
	 * comparison screen instead of just the plain edit screen. Returns 0 if
	 * the post has no revisions yet, or the lookup fails for any reason.
	 */
	private function fetch_remote_latest_revision_id( $post_type, $remote_id ) {
		$endpoint = $this->rest_endpoint( $this->get_rest_base( $post_type ) ) . '/' . $remote_id . '/revisions';
		$response = $this->remote_get( $endpoint, array(
			'context'  => 'edit',
			'per_page' => 1,
			'_fields'  => 'id',
		) );

		if ( is_wp_error( $response ) || empty( $response[0]['id'] ) ) {
			return 0;
		}

		return (int) $response[0]['id'];
	}

	/**
	 * Fetches every meta key on the matched remote post via this plugin's own
	 * '/rest-in-sync/v1/meta/{id}' route, bypassing the standard REST API's
	 * show_in_rest restriction entirely. Requires the remote to be running a
	 * plugin version that registers this route; on a 404 (older remote, or a
	 * genuinely plain WordPress install) this degrades gracefully to an empty
	 * array, same as if the route never existed.
	 *
	 * @return array meta_key => value
	 */
	private function fetch_remote_all_meta( $remote_id ) {
		$endpoint = trailingslashit( trim( (string) Rest_In_Sync_Settings::get_site_url() ) ) . 'wp-json/rest-in-sync/v1/meta/' . $remote_id;
		$response = $this->remote_get( $endpoint );

		if ( is_wp_error( $response ) ) {
			Rest_In_Sync_Logs::add_log( 'fetch_remote_all_meta', $response->get_error_message(), array( 'remote_id' => $remote_id ) );

			return array();
		}

		return is_array( $response ) ? $response : array();
	}

	/**
	 * Merges the standard REST 'meta' field with the all-meta route's response
	 * (when available), giving the fullest possible view of the remote post's
	 * meta. Shared by compare_all_fields() and pull_from_remote() so both see
	 * exactly the same remote meta values.
	 *
	 * @return array meta_key => value
	 */
	private function get_remote_meta_map( array $remote, $remote_id ) {
		$remote_meta = ( ! empty( $remote['meta'] ) && is_array( $remote['meta'] ) ) ? $remote['meta'] : array();

		if ( $remote_id ) {
			$remote_meta = array_merge( $remote_meta, $this->fetch_remote_all_meta( $remote_id ) );
		}

		return $remote_meta;
	}

	/**
	 * Compares local post fields and meta against the remote post, returning
	 * only the fields that differ and aren't flagged exclude_from_diff.
	 *
	 * Meta keys the remote doesn't expose at all ('remote_exposed' => false)
	 * are never counted here — compare_all_fields() includes them for the
	 * Details view's benefit, but we have no actual remote value to compare
	 * against, so treating them as "out of sync" would flag nearly every post
	 * on a site where most custom fields aren't REST-registered and the
	 * remote isn't running a plugin version with the all-meta route.
	 *
	 * @return array
	 */
	private function build_diff( WP_Post $post, array $remote, $remote_id = 0 ) {
		$diff = array();

		foreach ( $this->compare_all_fields( $post, $remote, $remote_id ) as $row ) {
			if ( ! $row['differs'] || ! $row['remote_exposed'] || Rest_In_Sync_Field_Settings::is_excluded_from_diff( $row['key'] ) ) {
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
	 * remote post: standard fields, then meta — preferring the remote's
	 * '/rest-in-sync/v1/meta/{id}' route (every meta key, regardless of REST
	 * registration) when available, falling back to whatever the standard
	 * REST API's 'meta' field exposes. Any local meta key still not covered by
	 * either source is included too, flagged 'remote_exposed' => false since
	 * we have no way to know what, if anything, the remote holds for it.
	 * Includes fields with equal values, unlike build_diff(), so callers like
	 * the Details view can show the complete field list.
	 *
	 * @return array[] Each row: type ('field'|'meta'), key, label, local, remote, differs, remote_exposed.
	 */
	private function compare_all_fields( WP_Post $post, array $remote, $remote_id = 0 ) {
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
				'type'           => 'field',
				'key'            => $key,
				'label'          => $label,
				'local'          => $local_value,
				'remote'         => $remote_value,
				'differs'        => $this->values_differ( $local_value, $remote_value ),
				'remote_exposed' => true,
			);
		}

		$remote_meta      = $this->get_remote_meta_map( $remote, $remote_id );
		$remote_meta_keys = array();

		foreach ( $remote_meta as $meta_key => $remote_value ) {
			if ( 0 === strpos( $meta_key, '_rest_in_sync_' ) ) {
				continue;
			}

			$remote_meta_keys[ $meta_key ] = true;
			$local_value                   = get_post_meta( $post->ID, $meta_key, true );

			$rows[] = array(
				'type'           => 'meta',
				'key'            => $meta_key,
				'label'          => $meta_key,
				'local'          => $local_value,
				'remote'         => $remote_value,
				'differs'        => $this->values_differ( $local_value, $remote_value ),
				'remote_exposed' => true,
			);
		}

		foreach ( $this->local_only_meta_keys( $post, $remote_meta_keys ) as $meta_key ) {
			$rows[] = array(
				'type'           => 'meta',
				'key'            => $meta_key,
				'label'          => $meta_key,
				'local'          => get_post_meta( $post->ID, $meta_key, true ),
				'remote'         => '',
				'differs'        => true,
				'remote_exposed' => false,
			);
		}

		return $rows;
	}

	/**
	 * Meta keys that exist on the local post but weren't covered by either
	 * remote meta source (the standard REST 'meta' field or the all-meta
	 * route) — almost always because neither the remote's REST registration
	 * nor its rest-in-sync plugin version knows about them, not because the
	 * remote post genuinely lacks them. Protected (underscore-prefixed) keys
	 * are skipped: they're overwhelmingly internal bookkeeping (this plugin's
	 * own meta, ACF's field-key shadow entries, edit locks, SEO plugin
	 * internals, etc.) rather than content anyone would want to compare or sync.
	 *
	 * @return string[]
	 */
	private function local_only_meta_keys( WP_Post $post, array $remote_meta_keys ) {
		global $wpdb;

		$meta_keys = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT LIKE %s",
			$post->ID,
			$wpdb->esc_like( '_' ) . '%'
		) );

		return array_values( array_diff( $meta_keys, array_keys( $remote_meta_keys ) ) );
	}

	/**
	 * Compares two field/meta values for the diff, treating the local and
	 * remote home URLs as interchangeable first (internal links, image src's,
	 * etc. always embed the site's own domain), then un-escaping JSON's
	 * optional slash-escaping, then stripping whitespace entirely. Comparison-
	 * only — the Details view always shows the real, unmodified saved value,
	 * via $row['local']/$row['remote'].
	 */
	private function values_differ( $local_value, $remote_value ) {
		return $this->normalize_whitespace( $this->normalize_json_slashes( $this->normalize_home_urls( $this->stringify_for_diff( $local_value ) ) ) )
			!== $this->normalize_whitespace( $this->normalize_json_slashes( $this->normalize_home_urls( $this->stringify_for_diff( $remote_value ) ) ) );
	}

	/**
	 * Strips every whitespace character entirely — not just collapsing runs
	 * to one space. A pretty-printed vs. minified JSON blob (e.g. a Gutenberg
	 * block comment's attributes) differs by single spaces around colons and
	 * commas, which collapsing runs down to one space wouldn't fix.
	 */
	private function normalize_whitespace( $value ) {
		return preg_replace( '/\s+/', '', $value );
	}

	/**
	 * Un-escapes JSON's optional "\/" slash-escaping to a plain "/" — "\/" and
	 * "/" decode to the identical character, it's purely a serializer choice,
	 * but different WordPress/block-editor versions disagree on it. Shows up
	 * as a diff in any field/meta value that embeds JSON, e.g. a Gutenberg
	 * block's attributes (`"acf\/some-block"` vs `"acf/some-block"`).
	 */
	private function normalize_json_slashes( $value ) {
		return str_replace( '\\/', '/', $value );
	}

	/**
	 * Casting an array/object value with (string) yields the literal string
	 * "Array" (plus a PHP warning) — meaning any two array-valued meta fields
	 * (ACF repeaters, etc.) would always compare as identical regardless of
	 * their actual content. JSON-encoding instead keeps the comparison — and
	 * the diff shown on the Details page — meaningful for structured values too.
	 */
	private function stringify_for_diff( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return (string) wp_json_encode( $value );
		}

		return (string) $value;
	}

	/**
	 * Replaces every occurrence of either site's home URL (any scheme, with or
	 * without "www.") with a shared placeholder, so comparisons focus on the
	 * path/content rather than which domain it's hosted on.
	 */
	private function normalize_home_urls( $value ) {
		if ( '' === $value ) {
			return $value;
		}

		foreach ( array( home_url(), Rest_In_Sync_Settings::get_site_url() ) as $url ) {
			$host = wp_parse_url( (string) $url, PHP_URL_HOST );

			if ( ! $host ) {
				continue;
			}

			$value = preg_replace( '#https?://(?:www\.)?' . preg_quote( $host, '#' ) . '#i', '{{HOME_URL}}', $value );
		}

		return $value;
	}

	/**
	 * Rewrites every occurrence of $from_home's host (any scheme, with or
	 * without "www.") to $to_home, recursing into arrays. Used when pushing so
	 * this site's own URLs (in links, image src's, ACF fields, etc.) don't leak
	 * into the other site's content — and, symmetrically, would be the way to
	 * handle it if a "pull from remote" direction is ever added.
	 */
	private function rewrite_home_url( $value, $from_home, $to_home ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->rewrite_home_url( $item, $from_home, $to_home );
			}

			return $value;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		$from_host = wp_parse_url( (string) $from_home, PHP_URL_HOST );

		if ( ! $from_host ) {
			return $value;
		}

		return preg_replace( '#https?://(?:www\.)?' . preg_quote( $from_host, '#' ) . '#i', untrailingslashit( (string) $to_home ), $value );
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

		// The remote stamps its plugin version on every response, so ordinary
		// sync traffic keeps the version cache current at no extra cost.
		Rest_In_Sync_Version::observe( $response );

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
				// Lets the receiving site refuse a write from a version it does
				// not match, rather than trusting the sender to have checked.
				Rest_In_Sync_Rest_Controller::VERSION_HEADER => REST_IN_SYNC_VERSION,
			),
			'body'    => wp_json_encode( $body ),
		) );

		Rest_In_Sync_Version::observe( $response );

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
