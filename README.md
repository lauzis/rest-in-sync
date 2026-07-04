# REST in Sync

A lightweight WordPress plugin to smoothly sync posts from local environments to live servers via the WordPress REST API.

## Admin menu

The plugin registers a top-level **REST in Sync** menu with the following pages:

| Page | Description |
| --- | --- |
| Sync | Lists posts flagged out of sync by the cron job, with a "Details" link per row that opens the field-by-field sync details page in a new tab. |
| Help | Explains how the plugin connects to the live site via the REST API. |
| Logs | Shows daily log files of sync and connection test activity, with a file selector and a "Clear all logs" button. |
| Settings | Carbon Fields powered form to configure the remote site connection, which post types to sync, with a "Test Connection" button and an "Enable logging" toggle. |

## Connecting to a live site

The Settings page collects the live site's URL, a WordPress username, and an [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) for that user. Requests to the remote site are authenticated with HTTP Basic Auth using those credentials.

The **Test Connection** button calls the remote site's REST API for each selected post type's REST base route (e.g. `/wp-json/wp/v2/posts`, `/wp-json/wp/v2/pages`) and displays the 10 most recently updated items, or an error if the site is unreachable or the credentials are invalid. The result is also surfaced as a toast notification.

## Post types to sync

The **Post Types to Sync** field on the Settings page lists every post type registered on this site that supports the REST API, so you can choose which ones get checked and validated. Posts and Pages are checked by default.

## Sync status cron job

A WP-Cron job periodically compares each syncable post against its counterpart on the remote site and records whether it's in sync. On first check, a post is matched to a remote post by slug (falling back to a GUID match), and a UUID is stored on the post to link it to the remote item going forward.

The comparison covers `post_title`, `post_content`, `post_excerpt`, `post_status`, and any post meta the remote site exposes via REST — excluding this plugin's own bookkeeping meta, so tracking the sync status itself can't cause a false "out of sync" result.

Each checked post gets these meta fields:

| Meta key | Description |
| --- | --- |
| `_rest_in_sync_uuid` | UUID identifying the post for sync linking. |
| `_rest_in_sync_remote_id` | The matched post's ID on the remote site. |
| `_rest_in_sync_status` | `never_synced`, `in_sync`, or `out_of_sync`. |
| `_rest_in_sync_last_checked` | Unix timestamp of the last comparison. |
| `_rest_in_sync_diff_id` | UUID of the diff JSON file, present only when out of sync. |

When a post is out of sync, the differing fields are written as JSON to `wp-content/uploads/rest-in-sync-diffs/{uuid}.json`. The Sync page lists every out-of-sync post (title, post type, last checked); its "Details" link opens that post's Sync Details page in a new tab.

The Settings page configures:
- **Cron Batch Size** — how many posts are checked per cron run (default 10).
- **Sync Check Interval** — how often the cron runs, from every 5 minutes up to every 24 hours (default every 15 minutes).
- **Resync Threshold (hours)** — how long to wait before re-checking a post that's already been checked; posts that have never been checked are always processed first (default 24 hours).

## Field settings

Every comparable field/meta key (the same set the cron job compares) has two global toggles, stored once per field/meta name in the `rest_in_sync_field_settings` option and applied across all post types:

- **Don't sync by default** — excludes the field from the default checkbox selection on the Sync Details page. It still shows up there; it's just unchecked unless selected manually.
- **Don't use in diff** — excludes the field from the cron job's out-of-sync comparison and from the stored diff, so a field that's expected to differ (e.g. a per-environment value) doesn't keep the post flagged out of sync. The field still appears on the Sync Details page for manual pushing.

## Sync Details page

The "Details" link on the Sync page opens a per-post Sync Details page with a live, field-by-field comparison against the remote post (including fields with equal values, hidden by default — toggle "Show fields with equal values" to reveal them). Each row has:

- A **Sync** checkbox, checked by default unless the field is set to "Don't sync by default" — with **Select All** / **Select None** / **Select Default** controls above the table.
- The two per-field toggle buttons described above.

**Push to Remote** sends the checked fields (and any selected meta as a `meta` object) as an authenticated REST request to the remote post, then re-runs the sync check for that post so its status and diff reflect the new state. The result is shown as a toast notification, consistent with Test Connection's UX.

This job only detects and records sync status — pushing content is a manual action from the Sync Details page.

## Logging

When "Enable logging" is checked on the Settings page, connection test activity (and any future sync operations) is written to daily log files under `wp-content/uploads/rest-in-sync-logs/`. Errors are always written to PHP's `error_log`, and additionally to these files when logging is enabled. The Logs page lets you pick a day's log file, view its entries, or clear all log files, confirming deletion with a toast notification.

## Notifications

Actions that need immediate feedback (Test Connection results, clearing logs) show a dismissible toast in the corner of the screen, in addition to the existing inline notices. The toast component (`assets/js/toast.js` and `assets/css/toast.css`) is loaded on every REST in Sync admin page and exposes `window.RestInSyncToast.show(message, type)`, where `type` is one of `success`, `error`, `warning`, or `info`.

## Development

Install PHP dependencies (Carbon Fields) with Composer:

```
composer install
```

## Requirements

- PHP 7.4+
- WordPress with the REST API enabled

## License

MIT

---

> This project is maintained with the assistance of [Claude Code](https://claude.ai/code) and [CodeRabbit](https://coderabbit.ai).
