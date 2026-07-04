<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Help', 'rest-in-sync' ); ?></h1>

	<h2><?php esc_html_e( 'How REST in Sync connects to your live site', 'rest-in-sync' ); ?></h2>
	<p>
		<?php esc_html_e( 'REST in Sync talks to your live WordPress site using the built-in WordPress REST API (wp-json). It does not require a companion plugin on the remote site — any modern WordPress install with the REST API enabled will work.', 'rest-in-sync' ); ?>
	</p>

	<h3><?php esc_html_e( 'Authentication', 'rest-in-sync' ); ?></h3>
	<p>
		<?php esc_html_e( 'Requests are authenticated with WordPress Application Passwords. Create an Application Password for a user on the live site (Users → Profile → Application Passwords), then enter that site\'s URL, the username, and the generated password on the Settings page. Every request is sent over HTTPS using HTTP Basic Auth with those credentials.', 'rest-in-sync' ); ?>
	</p>

	<h3><?php esc_html_e( 'Testing the connection', 'rest-in-sync' ); ?></h3>
	<p>
		<?php esc_html_e( 'The "Test Connection" button on the Settings page sends a request to the live site\'s REST API and retrieves the 10 most recently updated posts and pages. A successful response confirms that the site URL is reachable and the credentials are valid. Any failure (unreachable site, invalid credentials, blocked REST API) is reported inline with the error returned by the site.', 'rest-in-sync' ); ?>
	</p>

	<h3><?php esc_html_e( 'What gets synced', 'rest-in-sync' ); ?></h3>
	<p>
		<?php esc_html_e( 'Once connected, the Sync page (coming soon) will use the same REST API connection to push content from this environment to the live site.', 'rest-in-sync' ); ?>
	</p>
</div>
