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

	/**
	 * The log, as a panel for the settings page.
	 *
	 * The listing is the shared package's, because every plugin here writes the
	 * same log and would otherwise grow its own reader for it. What stays here
	 * is whether to show it and what happens when somebody clears it.
	 *
	 * @return string
	 */
	public static function panel() {
		$logger = self::logger();

		if ( ! $logger || ! class_exists( '\\Lauzis\\WpPackages\\Logs\\Viewer' ) ) {
			// An older copy of the shared package won the version race — see
			// WpPackages_Registry. The rest of the page still works, so this
			// says what is missing rather than fataling.
			return '<p class="description">'
				. esc_html__( 'The log reader needs a newer copy of the shared package than the one running.', 'rest-in-sync' )
				. '</p>';
		}

		$viewer = new \Lauzis\WpPackages\Logs\Viewer( $logger, array( 'clear' => 'rest_in_sync_clear_logs' ) );

		return $viewer->render();
	}

	/** Empties the log, from the button on that panel. */
	public static function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'rest-in-sync' ) );
		}

		check_admin_referer( 'rest_in_sync_clear_logs' );

		self::add_log( 'logs', 'Log cleared from the settings page.', array( 'user' => get_current_user_id() ) );

		// The logger directly rather than clear_logs(), which does nothing when
		// logging is switched off — the likeliest moment to want yesterday's
		// files gone is just after switching it off.
		$logger = self::logger();

		if ( $logger ) {
			$logger->clear();
		}

		// Back where the button was, whichever screen carried the panel.
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
