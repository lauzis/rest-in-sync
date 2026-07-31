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

	/** Nonce action prefix for the diff details page; suffixed with the diff_id being viewed. */
	const DIFF_NONCE_ACTION = 'rest_in_sync_view_diff';

	public function __construct() {
		// Priority 5 so our submenus render before the Carbon Fields Settings page (added at default priority 10).
		add_action( 'admin_menu', array( $this, 'register_menu' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_toast_assets' ) );
	}

	/**
	 * Loads the toast notification component on every REST in Sync admin page.
	 */
	public function enqueue_toast_assets( $hook_suffix ) {
		if ( strpos( (string) $hook_suffix, self::MENU_SLUG ) === false ) {
			return;
		}

		// Toast styling and behaviour come from the shared lauzis/wp-notices
		// package. The local script stays as a thin alias so the scripts that
		// call RestInSyncToast.show() and depend on this handle are unchanged.
		$toast_dependencies = array();

		if ( class_exists( 'WpNotices_Registry' ) ) {
			WpNotices_Registry::toasts( 'rest-in-sync' )->enqueue();
			$toast_dependencies[] = \Lauzis\WpNotices\Toasts::HANDLE;
		}

		wp_enqueue_script(
			'rest-in-sync-toast',
			REST_IN_SYNC_URL . 'assets/js/toast.js',
			$toast_dependencies,
			REST_IN_SYNC_VERSION,
			true
		);

		if ( strpos( (string) $hook_suffix, self::MENU_SLUG . '-diff' ) !== false ) {
			wp_enqueue_script(
				'rest-in-sync-details',
				REST_IN_SYNC_URL . 'assets/js/details.js',
				array( 'jquery', 'rest-in-sync-toast' ),
				REST_IN_SYNC_VERSION,
				true
			);
		}

		if ( 'toplevel_page_' . self::MENU_SLUG === $hook_suffix ) {
			wp_enqueue_script(
				'rest-in-sync-sync',
				REST_IN_SYNC_URL . 'assets/js/sync.js',
				array( 'jquery', 'rest-in-sync-toast' ),
				REST_IN_SYNC_VERSION,
				true
			);
		}

		if ( strpos( (string) $hook_suffix, self::MENU_SLUG . '-field-settings' ) !== false ) {
			wp_enqueue_script(
				'rest-in-sync-field-settings',
				REST_IN_SYNC_URL . 'assets/js/field-settings.js',
				array( 'jquery', 'rest-in-sync-toast' ),
				REST_IN_SYNC_VERSION,
				true
			);
		}
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

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Field Settings', 'rest-in-sync' ),
			__( 'Field Settings', 'rest-in-sync' ),
			'manage_options',
			self::MENU_SLUG . '-field-settings',
			array( $this, 'render_field_settings_page' )
		);

		// Parent slug null keeps the diff details page out of the menu; it's only ever reached via the "Details" link on the Sync page.
		add_submenu_page(
			null,
			__( 'Sync Diff', 'rest-in-sync' ),
			__( 'Sync Diff', 'rest-in-sync' ),
			'manage_options',
			self::MENU_SLUG . '-diff',
			array( $this, 'render_diff_page' )
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

	public function render_field_settings_page() {
		require REST_IN_SYNC_DIR . 'includes/views/field-settings.php';
	}

	public function render_diff_page() {
		require REST_IN_SYNC_DIR . 'includes/views/diff.php';
	}
}
