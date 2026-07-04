<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's top-level admin menu and its placeholder submenu pages.
 *
 * The Settings submenu itself is registered separately by Rest_In_Sync_Settings
 * via a Carbon Fields "theme_options" container attached to REST_IN_SYNC_MENU_SLUG,
 * which is why it is not added here.
 */
class Rest_In_Sync_Admin_Menu {

	const MENU_SLUG = 'rest-in-sync';

	public function __construct() {
		// Priority 5 so our submenus render before the Carbon Fields Settings page (added at default priority 10).
		add_action( 'admin_menu', array( $this, 'register_menu' ), 5 );
	}

	public function register_menu() {
		add_menu_page(
			__( 'REST in Sync', 'rest-in-sync' ),
			__( 'REST in Sync', 'rest-in-sync' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_sync_page' ),
			'dashicons-update',
			80
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Sync', 'rest-in-sync' ),
			__( 'Sync', 'rest-in-sync' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_sync_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Help', 'rest-in-sync' ),
			__( 'Help', 'rest-in-sync' ),
			'manage_options',
			self::MENU_SLUG . '-help',
			array( $this, 'render_help_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Logs', 'rest-in-sync' ),
			__( 'Logs', 'rest-in-sync' ),
			'manage_options',
			self::MENU_SLUG . '-logs',
			array( $this, 'render_logs_page' )
		);
	}

	public function render_sync_page() {
		require REST_IN_SYNC_DIR . 'includes/views/sync.php';
	}

	public function render_help_page() {
		require REST_IN_SYNC_DIR . 'includes/views/help.php';
	}

	public function render_logs_page() {
		require REST_IN_SYNC_DIR . 'includes/views/logs.php';
	}
}
