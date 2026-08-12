<?php
/**
 * Translation manifest — GENERATED, do not edit.
 *
 * Produced by bin/schema-i18n from the settings schema JSON. Exists so
 * `wp i18n make-pot` can see strings that live in JSON rather than in PHP.
 * Never loaded at runtime.
 *
 * Regenerate with:
 *   bin/schema-i18n --domain=rest-in-sync --out=languages/schema-strings.php config/settings.json config/logs.json
 */

return;

__( '<hr><p>The sync status cron job periodically compares local posts against the remote site and flags any that have drifted out of sync.</p>', 'rest-in-sync' );
__( '<p>Enter the connection details for the live WordPress site this plugin will sync to.</p>', 'rest-in-sync' );
__( '<p>The settings below only matter for a site that initiates its own sync checks, so they\'re hidden while "This is the remote server" is checked.</p>', 'rest-in-sync' );
__( '@callback:rest_in_sync_logs_view', 'rest-in-sync' );
__( '@callback:rest_in_sync_test_connection_field', 'rest-in-sync' );
__( 'Application Password', 'rest-in-sync' );
__( 'Check this on the live/target site of a sync pair. It disables this site\'s own sync cron job and the manual sync actions below (Check Now, Resync Now, Push to Remote) — this site is only ever the destination, never the one initiating checks. The "/rest-in-sync/v1/meta/{id}" REST route (used by the other site to fetch full meta data) keeps working regardless, since that\'s what makes this useful as a remote target in the first place.', 'rest-in-sync' );
__( 'Choose which post types should be checked and validated via the REST API.', 'rest-in-sync' );
__( 'Cron Batch Size', 'rest-in-sync' );
__( 'Generate this under the live site\'s Users → Profile → Application Passwords.', 'rest-in-sync' );
__( 'How many posts to check for changes on each cron run.', 'rest-in-sync' );
__( 'How often the cron job runs to check posts for out-of-sync changes.', 'rest-in-sync' );
__( 'Post Types to Sync', 'rest-in-sync' );
__( 'Posts checked more recently than this many hours ago are skipped until this many hours have passed. Posts that have never been checked are always processed.', 'rest-in-sync' );
__( 'Re-check after a plugin update', 'rest-in-sync' );
__( 'Resync Threshold (hours)', 'rest-in-sync' );
__( 'Site URL', 'rest-in-sync' );
__( 'Sync Check Interval', 'rest-in-sync' );
__( 'The full URL of the live WordPress site, including https://.', 'rest-in-sync' );
__( 'The username of a user on the live site with permission to manage content.', 'rest-in-sync' );
__( 'This is the remote server', 'rest-in-sync' );
__( 'Username', 'rest-in-sync' );
__( 'When the plugin is updated, treat every previous sync check as out of date and check the posts again. Which fields are compared, and what counts as a difference, can change between versions, so results from an older one may no longer hold.', 'rest-in-sync' );
