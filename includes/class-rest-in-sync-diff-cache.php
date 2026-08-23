<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The diff files a sync check writes, and the maintenance the Settings page
 * offers over them.
 *
 * A diff is derived data: the next check of that post rebuilds it, so deleting
 * one costs a re-check and never information. What clearing must not do is
 * leave a post pointing at a file that is gone -- that is precisely what makes
 * a Details link report itself expired -- so for a full clear the file and the
 * meta that points at it go together.
 */
class Rest_In_Sync_Diff_Cache {

	/** Files nothing points at: superseded by a later check, or left behind by an interrupted one. */
	const SCOPE_STALE = 'stale';

	/** Every diff file, plus the meta pointing at them. */
	const SCOPE_ALL = 'all';

	/**
	 * What is on disk right now.
	 *
	 * @return array{files:int,bytes:int,stale_files:int,stale_bytes:int}
	 */
	public static function stats() {
		$referenced = self::referenced_ids();
		$stats      = array(
			'files'       => 0,
			'bytes'       => 0,
			'stale_files' => 0,
			'stale_bytes' => 0,
		);

		foreach ( self::files() as $file ) {
			$size = (int) filesize( $file );

			++$stats['files'];
			$stats['bytes'] += $size;

			if ( ! isset( $referenced[ basename( $file, '.json' ) ] ) ) {
				++$stats['stale_files'];
				$stats['stale_bytes'] += $size;
			}
		}

		return $stats;
	}

	/**
	 * Deletes diff files.
	 *
	 * @param string $scope self::SCOPE_STALE or self::SCOPE_ALL.
	 * @return array{deleted:int,bytes:int,failed:int,stats:array}
	 */
	public static function clear( $scope = self::SCOPE_STALE ) {
		$all        = self::SCOPE_ALL === $scope;
		$referenced = self::referenced_ids();

		$deleted = 0;
		$bytes   = 0;
		$failed  = 0;

		foreach ( self::files() as $file ) {
			if ( ! $all && isset( $referenced[ basename( $file, '.json' ) ] ) ) {
				continue;
			}

			$size = (int) filesize( $file );

			if ( unlink( $file ) ) {
				++$deleted;
				$bytes += $size;
			} else {
				++$failed;
			}
		}

		if ( $all ) {
			// The pointers have to go with the files. A post left pointing at a
			// deleted diff is the "this details link is invalid or has expired"
			// case, and the Sync page would keep offering the link.
			delete_post_meta_by_key( Rest_In_Sync_Sync_Checker::META_DIFF_ID );
		}

		Rest_In_Sync_Logs::add_log(
			'diff_cache_cleared',
			sprintf( 'Cleared %d diff file(s)', $deleted ),
			array(
				'scope'   => $all ? self::SCOPE_ALL : self::SCOPE_STALE,
				'deleted' => $deleted,
				'bytes'   => $bytes,
				'failed'  => $failed,
			)
		);

		return array(
			'deleted' => $deleted,
			'bytes'   => $bytes,
			'failed'  => $failed,
			'stats'   => self::stats(),
		);
	}

	/**
	 * A one-line summary for the Settings page.
	 *
	 * @param array|null $stats
	 * @return string
	 */
	public static function summary( $stats = null ) {
		$stats = null === $stats ? self::stats() : $stats;

		if ( 0 === $stats['files'] ) {
			return esc_html__( 'No cached diffs.', 'rest-in-sync' );
		}

		$summary = sprintf(
			/* translators: 1: number of files, 2: total size, e.g. "1 MB" */
			esc_html( _n( '%1$s cached diff, %2$s', '%1$s cached diffs, %2$s', $stats['files'], 'rest-in-sync' ) ),
			number_format_i18n( $stats['files'] ),
			size_format( $stats['bytes'] )
		);

		if ( $stats['stale_files'] > 0 ) {
			$summary .= ' — ' . sprintf(
				/* translators: 1: number of unused files, 2: their size */
				esc_html__( '%1$s of them unused (%2$s)', 'rest-in-sync' ),
				number_format_i18n( $stats['stale_files'] ),
				size_format( $stats['stale_bytes'] )
			);
		}

		return $summary;
	}

	/**
	 * Diff files on disk.
	 *
	 * @return array<int, string>
	 */
	private static function files() {
		if ( ! is_dir( REST_IN_SYNC_DIFF_PATH ) ) {
			return array();
		}

		$files = glob( REST_IN_SYNC_DIFF_PATH . '*.json' );

		return is_array( $files ) ? $files : array();
	}

	/**
	 * Every diff id a post currently points at.
	 *
	 * This meta key is the only route to a diff file anywhere in the plugin, so
	 * a file whose id is absent here cannot be reached by anything.
	 *
	 * @return array<string, int>
	 */
	private static function referenced_ids() {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				Rest_In_Sync_Sync_Checker::META_DIFF_ID
			)
		);

		return array_flip( (array) $ids );
	}
}
