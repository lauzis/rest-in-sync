<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File-based logging for sync and connection test activity, gated by the
 * "Enable logging" setting.
 */
class Rest_In_Sync_Logs {

	/** Returns true when file logging is enabled in Settings. */
	public static function enabled() {
		return Rest_In_Sync_Settings::logging_enabled();
	}

	/** Returns the absolute path to today's log file. */
	private static function file_path() {
		return REST_IN_SYNC_LOG_PATH . 'rest-in-sync-' . gmdate( 'Y-m-d' ) . '.log';
	}

	/**
	 * Appends a log entry to today's log file if logging is enabled.
	 *
	 * @param string $action             Short label.
	 * @param string $message            Human-readable message.
	 * @param array  $additional_objects Key-value context to append as JSON.
	 * @return bool True on success, false if logging is disabled or write fails.
	 */
	public static function add_log( $action, $message = '', $additional_objects = array() ) {
		if ( ! self::enabled() ) {
			return false;
		}

		if ( ! is_dir( REST_IN_SYNC_LOG_PATH ) ) {
			wp_mkdir_p( REST_IN_SYNC_LOG_PATH );
		}

		$line = self::format_line( $action, $message, $additional_objects );

		return (bool) file_put_contents( self::file_path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Logs an error unconditionally — always writes to PHP's error_log, and
	 * additionally to the plugin's log file when logging is enabled.
	 *
	 * @param string $action             Short label.
	 * @param string $message            Human-readable message.
	 * @param array  $additional_objects Key-value context to append as JSON.
	 */
	public static function add_error( $action, $message = '', $additional_objects = array() ) {
		$line = self::format_line( $action, $message, $additional_objects );

		error_log( 'rest-in-sync: ' . $line );

		if ( self::enabled() ) {
			if ( ! is_dir( REST_IN_SYNC_LOG_PATH ) ) {
				wp_mkdir_p( REST_IN_SYNC_LOG_PATH );
			}
			file_put_contents( self::file_path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX );
		}
	}

	/**
	 * Deletes all daily log files from the log directory.
	 *
	 * @return bool True on success, false if logging is disabled.
	 */
	public static function clear_logs() {
		if ( ! self::enabled() ) {
			return false;
		}

		$files = glob( REST_IN_SYNC_LOG_PATH . 'rest-in-sync-*.log' );
		if ( $files ) {
			array_map( 'unlink', $files );
		}

		return true;
	}

	/**
	 * Returns the total number of log entries across all daily log files.
	 *
	 * @return int Total log entry count.
	 */
	public static function get_log_count() {
		if ( ! self::enabled() ) {
			return 0;
		}

		$count = 0;
		$files = glob( REST_IN_SYNC_LOG_PATH . 'rest-in-sync-*.log' );

		if ( $files ) {
			foreach ( $files as $file ) {
				$count += count( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) );
			}
		}

		return $count;
	}

	/**
	 * Returns a list of available daily log files with their dates and entry counts.
	 *
	 * @return array[] Each item: ['file' => string, 'date' => string, 'count' => int]
	 */
	public static function get_log_files() {
		$files  = glob( REST_IN_SYNC_LOG_PATH . 'rest-in-sync-*.log' ) ?: array();
		$result = array();

		rsort( $files );

		foreach ( $files as $file ) {
			$date     = preg_replace( '/^.*rest-in-sync-(.+)\.log$/', '$1', $file );
			$lines    = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			$result[] = array(
				'file'  => $file,
				'date'  => $date,
				'count' => count( $lines ?: array() ),
			);
		}

		return $result;
	}

	private static function format_line( $action, $message, $additional_objects ) {
		$timestamp = gmdate( 'Y-m-d H:i:s' );
		$line      = "[{$timestamp}] [{$action}] {$message}";

		if ( ! empty( $additional_objects ) ) {
			$line .= ' | ' . wp_json_encode( $additional_objects );
		}

		return $line;
	}
}
