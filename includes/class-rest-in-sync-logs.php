<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rest In Sync's logging entry point, gated by the "Enable logging" setting.
 *
 * The implementation lives in the shared lauzis/wp-plugin-packages package; this class is
 * a thin facade that keeps the plugin's own API, so the call sites throughout
 * the plugin are unchanged.
 */
class Rest_In_Sync_Logs {

	/** Log stream name — also the log filename prefix. */
	const SLUG = 'rest-in-sync';

	/**
	 * Returns the shared logger, or null when the wp-plugin-packages package is not
	 * installed (e.g. a build shipped without vendor/). Logging then becomes a
	 * silent no-op rather than a fatal.
	 *
	 * @return \Lauzis\WpPackages\Logs\Logger|null
	 */
	private static function logger() {
		if ( ! class_exists( 'WpPackages_Registry' ) ) {
			return null;
		}

		return WpPackages_Registry::logger(
			self::SLUG,
			array(
				'dir'     => REST_IN_SYNC_LOG_PATH,
				'enabled' => array( __CLASS__, 'enabled' ),
			)
		);
	}

	/** Returns true when file logging is enabled in Settings. */
	public static function enabled() {
		return Rest_In_Sync_Settings::logging_enabled();
	}

	/**
	 * Appends a log entry to today's log file if logging is enabled.
	 *
	 * @param string $action             Short label.
	 * @param string $message            Human-readable message.
	 * @param array  $additional_objects Key-value context to append as JSON.
	 * @return bool True on success, false if logging is disabled or write fails.
	 */
	/**
	 * The Slack test button, or null when the package is absent or older than
	 * the version that added it.
	 *
	 * @return \Lauzis\WpPackages\Logs\SlackTester|null
	 */
	public static function slack_tester() {
		static $tester = null;

		if ( null !== $tester ) {
			return $tester;
		}

		$logger = self::logger();

		if ( ! $logger || ! class_exists( '\Lauzis\WpPackages\Logs\SlackTester' ) ) {
			return null;
		}

		$tester = new \Lauzis\WpPackages\Logs\SlackTester( $logger );

		return $tester;
	}

	public static function add_log( $action, $message = '', $additional_objects = array() ) {
		$logger = self::logger();

		return $logger ? $logger->add( $action, $message, $additional_objects ) : false;
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
		$logger = self::logger();

		if ( $logger ) {
			$logger->error( $action, $message, $additional_objects );
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

		$logger = self::logger();
		if ( $logger ) {
			$logger->clear();
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

		$logger = self::logger();

		return $logger ? $logger->count() : 0;
	}

	/**
	 * Returns a list of available daily log files with their dates and entry counts.
	 *
	 * @return array[] Each item: ['file' => string, 'date' => string, 'count' => int]
	 */
	public static function get_log_files() {
		$logger = self::logger();
		if ( ! $logger ) {
			return array();
		}

		$result = array();

		foreach ( $logger->files() as $file ) {
			$result[] = array(
				'file'  => $file['file'],
				'date'  => $file['date'],
				'count' => $file['count'],
			);
		}

		return $result;
	}
}
