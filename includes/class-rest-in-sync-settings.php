<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Carbon Fields powered Settings page (REST API connection details).
 */
class Rest_In_Sync_Settings {

	const OPTION_SITE_URL       = 'rest_in_sync_site_url';
	const OPTION_USERNAME       = 'rest_in_sync_username';
	const OPTION_APP_PASSWORD   = 'rest_in_sync_app_password';
	const OPTION_ENABLE_LOGGING = 'rest_in_sync_enable_logging';
	const OPTION_POST_TYPES     = 'rest_in_sync_post_types_to_sync';

	const DEFAULT_POST_TYPES = array( 'post', 'page' );

	public function __construct() {
		add_action( 'carbon_fields_register_fields', array( $this, 'register_fields' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_fields() {
		if ( ! class_exists( '\\Carbon_Fields\\Container' ) ) {
			return;
		}

		\Carbon_Fields\Container::make( 'theme_options', __( 'Settings', 'rest-in-sync' ) )
			->set_page_parent( Rest_In_Sync_Admin_Menu::MENU_SLUG )
			->set_page_file( Rest_In_Sync_Admin_Menu::MENU_SLUG . '-settings' )
			->set_page_menu_title( __( 'Settings', 'rest-in-sync' ) )
			->add_fields( array(
				\Carbon_Fields\Field\Field::make( 'html', 'rest_in_sync_settings_intro' )
					->set_html( '<p>' . esc_html__( 'Enter the connection details for the live WordPress site this plugin will sync to.', 'rest-in-sync' ) . '</p>' ),

				\Carbon_Fields\Field\Field::make( 'text', self::OPTION_SITE_URL, __( 'Site URL', 'rest-in-sync' ) )
					->set_attribute( 'type', 'url' )
					->set_attribute( 'placeholder', 'https://example.com' )
					->set_help_text( __( 'The full URL of the live WordPress site, including https://.', 'rest-in-sync' ) )
					->set_required( true ),

				\Carbon_Fields\Field\Field::make( 'text', self::OPTION_USERNAME, __( 'Username', 'rest-in-sync' ) )
					->set_help_text( __( 'The username of a user on the live site with permission to manage content.', 'rest-in-sync' ) )
					->set_required( true ),

				\Carbon_Fields\Field\Field::make( 'text', self::OPTION_APP_PASSWORD, __( 'Application Password', 'rest-in-sync' ) )
					->set_attribute( 'type', 'password' )
					->set_help_text( __( 'Generate this under the live site\'s Users → Profile → Application Passwords.', 'rest-in-sync' ) )
					->set_required( true ),

				\Carbon_Fields\Field\Field::make( 'html', 'rest_in_sync_test_connection' )
					->set_html(
						'<button type="button" id="rest-in-sync-test-connection" class="button button-secondary">'
						. esc_html__( 'Test Connection', 'rest-in-sync' )
						. '</button>'
						. '<span id="rest-in-sync-test-connection-spinner" class="spinner" style="float:none;"></span>'
						. '<div id="rest-in-sync-test-connection-result" style="margin-top:10px;"></div>'
					),

				\Carbon_Fields\Field\Field::make( 'checkbox', self::OPTION_ENABLE_LOGGING, __( 'Enable logging', 'rest-in-sync' ) )
					->set_help_text( __( 'Write sync and connection test activity to daily log files, viewable on the Logs page.', 'rest-in-sync' ) ),

				\Carbon_Fields\Field\Field::make( 'set', self::OPTION_POST_TYPES, __( 'Post Types to Sync', 'rest-in-sync' ) )
					->set_options( array( __CLASS__, 'get_available_post_types' ) )
					->set_default_value( self::DEFAULT_POST_TYPES )
					->set_help_text( __( 'Choose which post types should be checked and validated via the REST API.', 'rest-in-sync' ) ),
			) );
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
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( Rest_In_Sync_Ajax::NONCE_ACTION ),
			'i18n'    => array(
				'testing' => __( 'Testing connection…', 'rest-in-sync' ),
				'error'   => __( 'Something went wrong while testing the connection.', 'rest-in-sync' ),
			),
		) );
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

	/**
	 * Returns the registered post types eligible for syncing (those exposed via the REST API),
	 * keyed by post type name with their singular label as the value.
	 *
	 * @return array<string, string>
	 */
	public static function get_available_post_types() {
		$post_types = get_post_types( array( 'show_in_rest' => true ), 'objects' );

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
