<?php
/**
 * Plugin Name: REST in Sync
 * Plugin URI: https://github.com/lauzis/rest-in-sync
 * Description: A lightweight tool to smoothly sync posts from local environments to live servers via the REST API.
 * Version: 0.1.0
 * Author: Aivars Lauzis
 * License: MIT
 * Text Domain: rest-in-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'REST_IN_SYNC_VERSION', '0.1.0' );
define( 'REST_IN_SYNC_FILE', __FILE__ );
define( 'REST_IN_SYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'REST_IN_SYNC_URL', plugin_dir_url( __FILE__ ) );

$rest_in_sync_autoload = REST_IN_SYNC_DIR . 'vendor/autoload.php';
if ( file_exists( $rest_in_sync_autoload ) ) {
	require_once $rest_in_sync_autoload;
}
unset( $rest_in_sync_autoload );

require_once REST_IN_SYNC_DIR . 'includes/class-rest-in-sync-plugin.php';

add_action( 'plugins_loaded', array( 'Rest_In_Sync_Plugin', 'instance' ) );
