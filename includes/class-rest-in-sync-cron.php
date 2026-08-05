<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the configurable cron schedules and keeps the sync status batch
 * job scheduled at the interval chosen in Settings.
 */
class Rest_In_Sync_Cron {

	const HOOK = 'rest_in_sync_batch_sync';

	public function __construct() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedules' ) );
		add_action( 'init', array( __CLASS__, 'maybe_reschedule' ) );
		add_action( self::HOOK, array( 'Rest_In_Sync_Sync_Checker', 'run' ) );
	}

	/**
	 * The configurable intervals offered in Settings, keyed by option value.
	 *
	 * @return array<string, array{seconds:int, label:string}>
	 */
	public static function get_intervals() {
		return array(
			'every_5_minutes'  => array(
				'seconds' => 5 * MINUTE_IN_SECONDS,
				'label'   => __( 'Every 5 minutes', 'rest-in-sync' ),
			),
			'every_10_minutes' => array(
				'seconds' => 10 * MINUTE_IN_SECONDS,
				'label'   => __( 'Every 10 minutes', 'rest-in-sync' ),
			),
			'every_15_minutes' => array(
				'seconds' => 15 * MINUTE_IN_SECONDS,
				'label'   => __( 'Every 15 minutes', 'rest-in-sync' ),
			),
			'every_30_minutes' => array(
				'seconds' => 30 * MINUTE_IN_SECONDS,
				'label'   => __( 'Every 30 minutes', 'rest-in-sync' ),
			),
			'every_1_hour'     => array(
				'seconds' => 1 * HOUR_IN_SECONDS,
				'label'   => __( 'Every hour', 'rest-in-sync' ),
			),
			'every_2_hours'    => array(
				'seconds' => 2 * HOUR_IN_SECONDS,
				'label'   => __( 'Every 2 hours', 'rest-in-sync' ),
			),
			'every_4_hours'    => array(
				'seconds' => 4 * HOUR_IN_SECONDS,
				'label'   => __( 'Every 4 hours', 'rest-in-sync' ),
			),
			'every_8_hours'    => array(
				'seconds' => 8 * HOUR_IN_SECONDS,
				'label'   => __( 'Every 8 hours', 'rest-in-sync' ),
			),
			'every_12_hours'   => array(
				'seconds' => 12 * HOUR_IN_SECONDS,
				'label'   => __( 'Every 12 hours', 'rest-in-sync' ),
			),
			'every_24_hours'   => array(
				'seconds' => 24 * HOUR_IN_SECONDS,
				'label'   => __( 'Every 24 hours', 'rest-in-sync' ),
			),
		);
	}

	/**
	 * Options list for the Settings "Sync Check Interval" select field.
	 *
	 * @return array<string, string>
	 */
	public static function get_interval_options() {
		$options = array();

		foreach ( self::get_intervals() as $key => $interval ) {
			$options[ $key ] = $interval['label'];
		}

		return $options;
	}

	/**
	 * Adds a WP cron schedule for each configurable interval.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public static function register_schedules( $schedules ) {
		foreach ( self::get_intervals() as $key => $interval ) {
			$schedules[ self::schedule_name( $key ) ] = array(
				'interval' => $interval['seconds'],
				'display'  => $interval['label'],
			);
		}

		return $schedules;
	}

	/**
	 * Reschedules the batch sync event if the configured interval has changed
	 * since it was last scheduled (e.g. after a Settings update). Clears (and
	 * never re-adds) the scheduled event on a site marked as the remote server
	 * of a sync pair — that site is only ever a destination, never the one
	 * initiating batch checks.
	 */
	public static function maybe_reschedule() {
		if ( Rest_In_Sync_Settings::is_remote_server() ) {
			wp_clear_scheduled_hook( self::HOOK );

			return;
		}

		$desired_schedule = self::schedule_name( Rest_In_Sync_Settings::get_cron_interval() );
		$scheduled_event  = wp_get_scheduled_event( self::HOOK );

		if ( $scheduled_event && $scheduled_event->schedule === $desired_schedule ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( time(), $desired_schedule, self::HOOK );
	}

	/**
	 * Runs on plugin activation. Registers the custom schedules directly since
	 * the instance hook in the constructor won't have run yet, then schedules
	 * the batch sync event if it isn't already scheduled.
	 */
	public static function activate() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedules' ) );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$schedule = self::schedule_name( Rest_In_Sync_Settings::get_cron_interval() );
			wp_schedule_event( time(), $schedule, self::HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	private static function schedule_name( $interval_key ) {
		return 'rest_in_sync_' . $interval_key;
	}
}
