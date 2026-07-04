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

	<h2><?php esc_html_e( 'Setting up the connection', 'rest-in-sync' ); ?></h2>

	<h3><?php esc_html_e( 'On this site', 'rest-in-sync' ); ?></h3>
	<p>
		<?php esc_html_e( 'Open the Settings page and fill in:', 'rest-in-sync' ); ?>
	</p>
	<ul style="list-style: disc; margin-left: 2em;">
		<li><?php esc_html_e( 'Site URL — the full URL of the live site, including https://.', 'rest-in-sync' ); ?></li>
		<li><?php esc_html_e( 'Username — a user on the live site with permission to manage the content you want to sync.', 'rest-in-sync' ); ?></li>
		<li><?php esc_html_e( 'Application Password — generated for that user on the live site.', 'rest-in-sync' ); ?></li>
	</ul>
	<p>
		<?php esc_html_e( 'Every request to the live site is sent over HTTPS and authenticated with HTTP Basic Auth using that username and Application Password.', 'rest-in-sync' ); ?>
	</p>

	<h3><?php esc_html_e( 'On the remote (live) site', 'rest-in-sync' ); ?></h3>
	<p>
		<?php esc_html_e( 'For the connection to work, the live site needs:', 'rest-in-sync' ); ?>
	</p>
	<ul style="list-style: disc; margin-left: 2em;">
		<li><?php esc_html_e( 'The WordPress REST API enabled and reachable — it is on by default, but some security plugins or server configurations block wp-json routes for unauthenticated or external requests.', 'rest-in-sync' ); ?></li>
		<li><?php esc_html_e( 'An Application Password for the user entered in Settings, created under Users → Profile → Application Passwords on the live site.', 'rest-in-sync' ); ?></li>
		<li><?php esc_html_e( 'That user must have sufficient capabilities to read and edit the post types being synced (an Editor or Administrator account is usually simplest).', 'rest-in-sync' ); ?></li>
		<li><?php esc_html_e( 'Each post type selected in "Post Types to Sync" must also be registered with show_in_rest on the live site, using the same REST base (e.g. posts and pages are exposed by default; custom post types need show_in_rest => true).', 'rest-in-sync' ); ?></li>
	</ul>

	<h3><?php esc_html_e( 'Testing the connection', 'rest-in-sync' ); ?></h3>
	<p>
		<?php esc_html_e( 'The "Test Connection" button on the Settings page sends a request to the live site\'s REST API and retrieves the 10 most recently updated items across the post types selected in "Post Types to Sync". A successful response confirms that the site URL is reachable and the credentials are valid. Any failure (unreachable site, invalid credentials, blocked REST API) is reported inline with the error returned by the site.', 'rest-in-sync' ); ?>
	</p>

	<h3><?php esc_html_e( 'Post types to sync', 'rest-in-sync' ); ?></h3>
	<p>
		<?php esc_html_e( 'The "Post Types to Sync" field on the Settings page lists every post type registered on this site that supports the REST API. Posts and Pages are checked by default; select or deselect post types to control which ones are checked and validated.', 'rest-in-sync' ); ?>
	</p>

	<h2><?php esc_html_e( 'Cron sync checks', 'rest-in-sync' ); ?></h2>
	<p>
		<?php esc_html_e( 'A WP-Cron job runs in the background and periodically compares each syncable post against its counterpart on the live site, so out-of-sync content is flagged automatically instead of only being noticed when you manually check. On first check, a post is matched to a remote post by slug (falling back to a GUID match) and linked to it going forward. Posts flagged out of sync appear on the Sync page, where the "Details" link shows exactly which fields differ.', 'rest-in-sync' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'This job only detects and records sync status — it never changes content on either site. Pushing content to the live site is always a manual action from the Sync Details page.', 'rest-in-sync' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'The cron job is configured on the Settings page:', 'rest-in-sync' ); ?>
	</p>
	<ul style="list-style: disc; margin-left: 2em;">
		<li><?php esc_html_e( 'Cron Batch Size — how many posts are checked per cron run (default 10). Keep this modest on sites with many posts, since each post checked means a request to the live site.', 'rest-in-sync' ); ?></li>
		<li><?php esc_html_e( 'Sync Check Interval — how often the cron runs, from every 5 minutes up to every 24 hours (default every 15 minutes).', 'rest-in-sync' ); ?></li>
		<li><?php esc_html_e( 'Resync Threshold (hours) — how long to wait before re-checking a post that has already been checked; posts that have never been checked are always processed first (default 24 hours).', 'rest-in-sync' ); ?></li>
	</ul>
	<p>
		<?php esc_html_e( 'WP-Cron only runs when your site receives a visit, so on low-traffic sites the checks may run later than scheduled. If you need reliable timing, disable WP-Cron\'s page-load trigger (define(\'DISABLE_WP_CRON\', true) in wp-config.php) and trigger it with a real system cron job instead, e.g.:', 'rest-in-sync' ); ?>
	</p>
	<p>
		<code>*/15 * * * * wget -q -O /dev/null "https://your-site.com/wp-cron.php?doing_wp_cron"</code>
	</p>

	<h2><?php esc_html_e( 'Logs', 'rest-in-sync' ); ?></h2>
	<p>
		<?php esc_html_e( 'When "Enable logging" is checked on the Settings page, connection tests, cron sync checks, and manual pushes to the live site are written to daily log files under wp-content/uploads/rest-in-sync-logs/. Errors are always written to PHP\'s error_log, and additionally to these files when logging is enabled.', 'rest-in-sync' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'The Logs page lets you pick which day\'s log file to view, and shows each entry with its timestamp and any relevant context (e.g. the post ID and fields involved). Use "Clear all logs" to delete every log file; you\'ll be asked to confirm first since this cannot be undone.', 'rest-in-sync' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'Logging is useful while setting up the connection or diagnosing an unexpected out-of-sync result, but consider disabling it again afterwards on a site with many posts, since a busy cron schedule will keep writing entries.', 'rest-in-sync' ); ?>
	</p>
</div>
