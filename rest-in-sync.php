<?php
/**
 * Plugin Name: REST in Sync
 * Plugin URI: https://github.com/lauzis/rest-in-sync
 * Description: A lightweight tool to smoothly sync posts from local environments to live servers via the REST API.
 * Version: 0.4.2
 * Author: Aivars Lauzis
 * License: MIT
 * Text Domain: rest-in-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'REST_IN_SYNC_VERSION', '0.4.2' );
define( 'REST_IN_SYNC_FILE', __FILE__ );
define( 'REST_IN_SYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'REST_IN_SYNC_URL', plugin_dir_url( __FILE__ ) );

$rest_in_sync_upload_dir = wp_upload_dir();
define( 'REST_IN_SYNC_LOG_PATH', str_replace( '\\', '/', $rest_in_sync_upload_dir['basedir'] . '/rest-in-sync-logs/' ) );
define( 'REST_IN_SYNC_DIFF_PATH', str_replace( '\\', '/', $rest_in_sync_upload_dir['basedir'] . '/rest-in-sync-diffs/' ) );
unset( $rest_in_sync_upload_dir );

$rest_in_sync_autoload = REST_IN_SYNC_DIR . 'vendor/autoload.php';
if ( file_exists( $rest_in_sync_autoload ) ) {
	require_once $rest_in_sync_autoload;
	// Required explicitly: Composer's files autoload runs only one copy of this
	// package per request, so the version gate would never see the others.
	require_once REST_IN_SYNC_DIR . 'vendor/lauzis/wp-plugin-packages/bootstrap.php';
}
unset( $rest_in_sync_autoload );

require_once REST_IN_SYNC_DIR . 'includes/class-rest-in-sync-plugin.php';

register_activation_hook( REST_IN_SYNC_FILE, array( 'Rest_In_Sync_Cron', 'activate' ) );
register_deactivation_hook( REST_IN_SYNC_FILE, array( 'Rest_In_Sync_Cron', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Rest_In_Sync_Plugin', 'instance' ) );

// The plugin's version in the admin footer, beside WordPress's own — the first
// thing worth knowing about a page misbehaving is which version drew it.
add_action( 'admin_init', static function () {
    if ( ! class_exists( '\\Lauzis\\WpPackages\\Admin\\Footer' ) ) {
        return;
    }

    \Lauzis\WpPackages\Admin\Footer::show(
        'rest-in-sync',
        array(
            'name'    => 'REST in Sync',
            'version' => defined( 'REST_IN_SYNC_VERSION' ) ? REST_IN_SYNC_VERSION : '',
        )
    );
} );
