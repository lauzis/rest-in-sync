<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once REST_IN_SYNC_DIR . 'includes/class-rest-in-sync-admin-menu.php';
require_once REST_IN_SYNC_DIR . 'includes/class-rest-in-sync-settings.php';
require_once REST_IN_SYNC_DIR . 'includes/class-rest-in-sync-logs.php';
require_once REST_IN_SYNC_DIR . 'includes/class-rest-in-sync-connection-tester.php';
require_once REST_IN_SYNC_DIR . 'includes/class-rest-in-sync-cron.php';
require_once REST_IN_SYNC_DIR . 'includes/class-rest-in-sync-field-settings.php';
require_once REST_IN_SYNC_DIR . 'includes/class-rest-in-sync-sync-checker.php';
require_once REST_IN_SYNC_DIR . 'includes/class-rest-in-sync-diff-renderer.php';
require_once REST_IN_SYNC_DIR . 'includes/class-rest-in-sync-diff-cache.php';
require_once REST_IN_SYNC_DIR . 'includes/class-rest-in-sync-version.php';
require_once REST_IN_SYNC_DIR . 'includes/class-rest-in-sync-rest-controller.php';
require_once REST_IN_SYNC_DIR . 'includes/class-rest-in-sync-ajax.php';

/**
 * Boots the plugin: Carbon Fields, admin menu, settings and AJAX handlers.
 */
class Rest_In_Sync_Plugin {

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'after_setup_theme', array( $this, 'boot_carbon_fields' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_missing_dependency_notice' ) );
		add_action( 'init', array( 'Rest_In_Sync_Sync_Checker', 'register_meta_fields' ) );

		new Rest_In_Sync_Admin_Menu();
		new Rest_In_Sync_Settings();
		new Rest_In_Sync_Cron();
		new Rest_In_Sync_Ajax();
		new Rest_In_Sync_Rest_Controller();
	}

	public function boot_carbon_fields() {
		if ( class_exists( '\\Carbon_Fields\\Carbon_Fields' ) ) {
			\Carbon_Fields\Carbon_Fields::boot();
		}
	}

	public function maybe_render_missing_dependency_notice() {
		if ( class_exists( '\\Carbon_Fields\\Carbon_Fields' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'REST in Sync requires its Composer dependencies to be installed. Please run "composer install" in the plugin directory.', 'rest-in-sync' )
		);
	}
}
