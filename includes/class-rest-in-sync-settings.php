<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Carbon Fields powered Settings page (REST API connection details).
 */
class Rest_In_Sync_Settings {

	const OPTION_IS_REMOTE_SERVER       = 'rest_in_sync_is_remote_server';
	const OPTION_SITE_URL              = 'rest_in_sync_site_url';
	const OPTION_USERNAME              = 'rest_in_sync_username';
	const OPTION_APP_PASSWORD          = 'rest_in_sync_app_password';
	const OPTION_ENABLE_LOGGING        = 'rest_in_sync_enable_logging';
	const OPTION_POST_TYPES            = 'rest_in_sync_post_types_to_sync';
	const OPTION_CRON_BATCH_SIZE       = 'rest_in_sync_cron_batch_size';
	const OPTION_CRON_INTERVAL         = 'rest_in_sync_cron_interval';
	const OPTION_RESYNC_THRESHOLD_HOURS = 'rest_in_sync_resync_threshold_hours';
	const OPTION_RECHECK_ON_VERSION_CHANGE = 'rest_in_sync_recheck_on_version_change';

	const DEFAULT_POST_TYPES               = array( 'post', 'page' );
	const DEFAULT_CRON_BATCH_SIZE          = 10;
	const DEFAULT_CRON_INTERVAL            = 'every_15_minutes';
	const DEFAULT_RESYNC_THRESHOLD_HOURS   = 24;

	public function __construct() {
		add_action( 'carbon_fields_register_fields', array( $this, 'register_fields' ) );

		// The Slack test button answers over admin-ajax, which never renders the
		// settings page, so its endpoint is registered on every admin request.
		add_action( 'admin_init', array( $this, 'boot_slack_tester' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/** Registers the Slack test endpoint, when the package provides one. */
	public function boot_slack_tester() {
		$tester = Rest_In_Sync_Logs::slack_tester();

		if ( $tester ) {
			$tester->boot();
		}
	}

	public function register_fields() {
		if ( ! class_exists( 'WpPackages_Registry' ) ) {
			return;
		}

		$settings = WpPackages_Registry::settings(
			'rest-in-sync',
			array(
				'title'           => __( 'Settings', 'rest-in-sync' ),
				'mode'            => 'flat',
				'page_parent'     => Rest_In_Sync_Admin_Menu::MENU_SLUG,
				'page_file'       => Rest_In_Sync_Admin_Menu::MENU_SLUG . '-settings',
				'page_menu_title' => __( 'Settings', 'rest-in-sync' ),
			)
		);

		$settings->callback( 'rest_in_sync_available_post_types', array( __CLASS__, 'get_available_post_types' ) );
		$settings->callback( 'rest_in_sync_interval_options', array( 'Rest_In_Sync_Cron', 'get_interval_options' ) );

		// Rendered lazily so the markup is produced when the page displays.
		$settings->callback(
			'rest_in_sync_test_connection_field',
			static function () {
				return '<button type="button" id="rest-in-sync-test-connection" class="button button-secondary">'
					. esc_html__( 'Test Connection', 'rest-in-sync' )
					. '</button>'
					. '<span id="rest-in-sync-test-connection-spinner" class="spinner" style="float:none;"></span>'
					. '<div id="rest-in-sync-test-connection-result" style="margin-top:10px;"></div>';
			}
		);

		// Drawn lazily too: the figures have to be read when the page displays,
		// not when fields are registered.
		$settings->callback( 'rest_in_sync_diff_cache_field', array( __CLASS__, 'render_diff_cache_field' ) );

		$settings->register(
			REST_IN_SYNC_DIR . 'config/settings.json',
			array(
				'prefix' => 'rest_in_sync_',
				'domain' => 'rest-in-sync',
			)
		);

		// Draws the "Send a test message" button under the Slack webhook field.
		// Without the callback the schema's html field renders nothing, so an
		// older bundled package simply has no button.
		$tester = Rest_In_Sync_Logs::slack_tester();

		if ( $tester ) {
			$settings->callback( 'logs_slack_test', array( $tester, 'render' ) );
		}

		// Logging comes from the shared package. This plugin's established key is
		// rest_in_sync_enable_logging rather than the component's own name, so it
		// is mapped rather than migrated. The condition hiding it on a remote
		// server is a rest-in-sync concern the component knows nothing about, so it
		// is supplied here too.
		$settings->register(
			WpPackages_Registry::schema( 'logs' ),
			array(
				'prefix'     => 'rest_in_sync_',
				'domain'     => 'wp-plugin-packages',
				'map'        => array( 'logs_enabled' => 'enable_logging' ),
				'conditions' => array(
					'logs_enabled'       => array(
						array( 'field' => 'is_remote_server', 'value' => 'yes', 'compare' => '!=' ),
					),
					// Hidden alongside the logging switch it belongs to: a
					// remote server is not where this plugin's logging is
					// configured.
					'logs_slack_webhook' => array(
						array( 'field' => 'is_remote_server', 'value' => 'yes', 'compare' => '!=' ),
					),
				),
			)
		);

		$settings->render();
	}

	public function enqueue_assets( $hook_suffix ) {
		if ( strpos( (string) $hook_suffix, Rest_In_Sync_Admin_Menu::MENU_SLUG . '-settings' ) === false ) {
			return;
		}

		wp_enqueue_script(
			'rest-in-sync-admin',
			REST_IN_SYNC_URL . 'assets/js/admin.js',
			array( 'jquery', 'rest-in-sync-toast' ),
			REST_IN_SYNC_VERSION,
			true
		);

		wp_localize_script( 'rest-in-sync-admin', 'restInSync', array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( Rest_In_Sync_Ajax::NONCE_ACTION ),
			'diffCacheNonce' => wp_create_nonce( Rest_In_Sync_Ajax::NONCE_ACTION_DIFF_CACHE ),
			'i18n'           => array(
				'testing'         => __( 'Testing connection…', 'rest-in-sync' ),
				'error'           => __( 'Something went wrong while testing the connection.', 'rest-in-sync' ),
				'clearing'        => __( 'Clearing…', 'rest-in-sync' ),
				'clearError'      => __( 'Something went wrong while clearing the cached diffs.', 'rest-in-sync' ),
				'confirmClearAll' => __( 'Delete every cached diff? Details links stop working until each post is checked again, which rebuilds them. No sync data is lost.', 'rest-in-sync' ),
			),
		) );
	}

	/**
	 * The cached-diff figures and the buttons that clear them.
	 *
	 * A diff is derived data, so this is a maintenance control rather than a
	 * setting: "unused" are files no post points at any more, which is pure
	 * leftovers, and "all" costs a re-check per post and nothing else.
	 *
	 * @return string
	 */
	public static function render_diff_cache_field() {
		$stats = Rest_In_Sync_Diff_Cache::stats();

		return '<p id="rest-in-sync-diff-cache-summary">' . Rest_In_Sync_Diff_Cache::summary( $stats ) . '</p>'
			. '<button type="button" id="rest-in-sync-clear-diff-cache-stale" class="button button-secondary"'
			. ( 0 === $stats['stale_files'] ? ' disabled' : '' ) . '>'
			. esc_html__( 'Clear unused', 'rest-in-sync' )
			. '</button> '
			. '<button type="button" id="rest-in-sync-clear-diff-cache-all" class="button button-secondary"'
			. ( 0 === $stats['files'] ? ' disabled' : '' ) . '>'
			. esc_html__( 'Clear all', 'rest-in-sync' )
			. '</button>'
			. '<span id="rest-in-sync-clear-diff-cache-spinner" class="spinner" style="float:none;"></span>';
	}

	/** Whether this site is configured as the remote/target side of a sync pair — see the field's help text for what that disables. */
	public static function is_remote_server() {
		return (bool) self::get_option( self::OPTION_IS_REMOTE_SERVER );
	}

	public static function get_site_url() {
		return self::get_option( self::OPTION_SITE_URL );
	}

	public static function get_username() {
		return self::get_option( self::OPTION_USERNAME );
	}

	public static function get_app_password() {
		return self::get_option( self::OPTION_APP_PASSWORD );
	}

	/** Whether enough connection details are filled in to attempt talking to the remote site at all. */
	public static function is_connection_configured() {
		return '' !== trim( (string) self::get_site_url() )
			&& '' !== trim( (string) self::get_username() )
			&& '' !== trim( (string) self::get_app_password() );
	}

	/**
	 * Whether a plugin update should invalidate previous sync checks.
	 *
	 * @return bool
	 */
	public static function recheck_on_version_change() {
		// Carbon Fields applies the field's own default (on) when nothing is
		// stored, so an install that has never opened Settings still re-checks
		// after an upgrade — a stale check is worse than an extra one.
		return (bool) self::get_option( self::OPTION_RECHECK_ON_VERSION_CHANGE );
	}

	public static function logging_enabled() {
		return (bool) self::get_option( self::OPTION_ENABLE_LOGGING );
	}

	public static function get_post_types_to_sync() {
		$post_types = self::get_option( self::OPTION_POST_TYPES );

		if ( empty( $post_types ) || ! is_array( $post_types ) ) {
			return self::DEFAULT_POST_TYPES;
		}

		return $post_types;
	}

	public static function get_cron_batch_size() {
		$batch_size = (int) self::get_option( self::OPTION_CRON_BATCH_SIZE );

		return $batch_size > 0 ? $batch_size : self::DEFAULT_CRON_BATCH_SIZE;
	}

	public static function get_cron_interval() {
		$interval  = self::get_option( self::OPTION_CRON_INTERVAL );
		$intervals = Rest_In_Sync_Cron::get_intervals();

		return isset( $intervals[ $interval ] ) ? $interval : self::DEFAULT_CRON_INTERVAL;
	}

	public static function get_resync_threshold_hours() {
		$hours = (int) self::get_option( self::OPTION_RESYNC_THRESHOLD_HOURS );

		return $hours > 0 ? $hours : self::DEFAULT_RESYNC_THRESHOLD_HOURS;
	}

	/**
	 * Returns the registered post types eligible for syncing: public, REST-exposed
	 * post types, keyed by post type name with their singular label as the value.
	 * 'show_in_rest' alone also matches internal block-editor/FSE post types
	 * (wp_block, wp_template, wp_navigation, etc.) that are registered
	 * 'public' => false — those aren't content anyone would sync as an article,
	 * so 'public' => true excludes them.
	 *
	 * @return array<string, string>
	 */
	public static function get_available_post_types() {
		$post_types = get_post_types( array( 'show_in_rest' => true, 'public' => true ), 'objects' );

		$options = array();
		foreach ( $post_types as $post_type ) {
			$options[ $post_type->name ] = $post_type->labels->singular_name;
		}

		return $options;
	}

	private static function get_option( $name ) {
		if ( function_exists( 'carbon_get_theme_option' ) ) {
			return carbon_get_theme_option( $name );
		}

		return '';
	}
}
