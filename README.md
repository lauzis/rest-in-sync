# REST in Sync

A lightweight WordPress plugin to smoothly sync posts from local environments to live servers via the WordPress REST API.

## Admin menu

The plugin registers a top-level **REST in Sync** menu with the following pages:

| Page | Description |
| --- | --- |
| Sync | Lists posts flagged out of sync by the cron job, with a search box to filter the list by title, and a "Details" link and a "Check Now" button per row — the latter re-runs the sync check for that post immediately, without waiting for cron. |
| Help | Explains how to set up the connection to a live site (including remote-side requirements), the cron sync check, and logging. |
| Logs | Shows daily log files of sync and connection test activity, with a file selector and a "Clear all logs" button. |
| Settings | Carbon Fields powered form to configure the remote site connection, which post types to sync, with a "Test Connection" button and an "Enable logging" toggle. |

## Connecting to a live site

The Settings page collects the live site's URL, a WordPress username, and an [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) for that user. Requests to the remote site are authenticated with HTTP Basic Auth using those credentials.

The **Test Connection** button calls the remote site's REST API for each selected post type's REST base route (e.g. `/wp-json/wp/v2/posts`, `/wp-json/wp/v2/pages`) and displays the 10 most recently updated items, or an error if the site is unreachable or the credentials are invalid. The result is also surfaced as a toast notification.

### Remote site requirements

For the connection to work, the remote (live) site needs:

- The WordPress REST API enabled and reachable — some security plugins or server configs block `wp-json` routes for external requests.
- An Application Password for the user entered in Settings, created under Users → Profile → Application Passwords on the remote site.
- That user must have sufficient capabilities to read and edit the post types being synced.
- Each post type selected in **Post Types to Sync** must be registered with `show_in_rest` on the remote site too, using the same REST base.

## Post types to sync

The **Post Types to Sync** field on the Settings page lists every post type registered on this site that supports the REST API, so you can choose which ones get checked and validated. Posts and Pages are checked by default.

## Sync status cron job

A WP-Cron job periodically compares each syncable post against its counterpart on the remote site and records whether it's in sync. A post is matched to a remote post by slug (falling back to a scan for a matching GUID or UUID), and the first time a match is found, the post's UUID is written back onto the matched remote post's `_rest_in_sync_uuid` meta over REST. From then on, lookups can match by that shared UUID even if the local slug later changes — slug/GUID alone can't recover from that drift, since neither is guaranteed to stay identical between the two sites.

Writing that meta back to the remote post requires the remote site to accept it — which it will automatically if it's also running REST in Sync (any version with this UUID-linking feature registers the meta for REST access). Against a plain WordPress install without this plugin, the write is silently rejected as read-only protected meta, and matching just falls back to slug/GUID as before; the rest of the sync (comparisons, pushes) is unaffected either way.

### WPML sites

When WPML is active on this site (`ICL_SITEPRESS_VERSION` defined), each post's WPML language code is looked up via the `wpml_element_language_code` filter and sent as a `lang` query argument on every remote lookup request (slug search and the GUID/UUID scan). This matters because WPML's own REST API integration scopes collection queries to the site's default language unless told otherwise — without the `lang` argument, any post in a non-default language is invisible to those lookups on the remote site even though it exists there, and shows up as "No matching post was found on the remote site." On non-WPML sites, or for content WPML doesn't track a language for, no `lang` argument is sent and lookups behave exactly as before.

The comparison covers `post_title`, `post_content`, `post_excerpt`, `post_status`, and any post meta the remote site exposes via REST — excluding this plugin's own bookkeeping meta, so tracking the sync status itself can't cause a false "out of sync" result. Before comparing, every field/meta value has both sites' home URLs (any scheme, with or without "www.") replaced with a shared placeholder, so internal links, embedded image URLs, etc. — which always differ between the two domains — don't themselves cause a false "out of sync" result. This only affects the diff calculation; the Sync Details page still shows each field's real, un-normalized value.

Each checked post gets these meta fields:

| Meta key | Description |
| --- | --- |
| `_rest_in_sync_uuid` | UUID identifying the post for sync linking. |
| `_rest_in_sync_remote_id` | The matched post's ID on the remote site. |
| `_rest_in_sync_status` | `never_synced`, `in_sync`, or `out_of_sync`. |
| `_rest_in_sync_last_checked` | Unix timestamp of the last comparison. |
| `_rest_in_sync_diff_id` | UUID of the diff JSON file, present only when out of sync. |

When a post is out of sync, the differing fields are written as JSON to `wp-content/uploads/rest-in-sync-diffs/{uuid}.json`. The Sync page lists every out-of-sync post (title, post type, last checked); a search box above the list filters it by title (via WP's standard `s` search, matched against `post_title`/`post_content`/`post_excerpt`). Each row's "Details" link opens that post's Sync Details page in a new tab, and its "Check Now" button re-runs `check_single()` for that post over AJAX — updating its row in place, or removing it from the list if it's now in sync — without waiting for the next cron run.

The Settings page configures:
- **Cron Batch Size** — how many posts are checked per cron run (default 10).
- **Sync Check Interval** — how often the cron runs, from every 5 minutes up to every 24 hours (default every 15 minutes).
- **Resync Threshold (hours)** — how long to wait before re-checking a post that's already been checked; posts that have never been checked are always processed first (default 24 hours).

WP-Cron only fires on a page load, so on low-traffic sites checks can run later than scheduled. For reliable timing, set `define('DISABLE_WP_CRON', true);` in `wp-config.php` and trigger `wp-cron.php` from a real system cron job instead, e.g. `*/15 * * * * wget -q -O /dev/null "https://your-site.com/wp-cron.php?doing_wp_cron"`.

## Field settings

Every comparable field/meta key (the same set the cron job compares) has two global toggles, stored once per field/meta name in the `rest_in_sync_field_settings` option and applied across all post types:

- **Don't sync by default** — excludes the field from the default checkbox selection on the Sync Details page. It still shows up there; it's just unchecked unless selected manually.
- **Don't use in diff** — excludes the field from the cron job's out-of-sync comparison and from the stored diff, so a field that's expected to differ (e.g. a per-environment value) doesn't keep the post flagged out of sync. The field still appears on the Sync Details page for manual pushing.

## Sync Details page

The "Details" link on the Sync page opens a per-post Sync Details page with a live, field-by-field comparison against the remote post (including fields with equal values, hidden by default — toggle "Show fields with equal values" to reveal them). Each row has:

- A **Sync** checkbox, checked by default unless the field is set to "Don't sync by default" — with **Select All** / **Select None** / **Select Default** controls above the table.
- The two per-field toggle buttons described above.

Since the standard REST API only ever exposes meta explicitly registered with `show_in_rest` — which excludes most ACF fields and other custom fields by default — this plugin also registers its own route, `GET /wp-json/rest-in-sync/v1/meta/{id}`, that returns *every* meta key on a post directly (`includes/class-rest-in-sync-rest-controller.php`), gated by the same `edit_post` capability check as the rest of the authenticated connection. When the remote site is also running a plugin version with this route, its response is merged in and those fields become real, comparable data — counted toward the cron's out-of-sync status like any other field. If the remote doesn't have the route yet (an older version, or a plain WordPress install), this degrades gracefully to the standard REST-exposed meta only.

Any local meta key still not covered by either source is listed in the Details view anyway, marked "not exposed on remote" with no remote value shown, since there's no way to know what the remote actually holds for it. These are always included there (not hidden behind "Show fields with equal values") but never counted toward the cron's out-of-sync status — a site can easily have hundreds of such fields (e.g. per-day view-count trackers), and there's no remote value to genuinely compare against. A **Filter by field name** box above the table helps narrow down large field/meta lists.

For the `title`, `content`, and `excerpt` fields, a differing Local/Remote pair is rendered with their differing words highlighted (`Rest_In_Sync_Diff_Renderer`, in `includes/class-rest-in-sync-diff-renderer.php`), reusing the same word-level diff engine WordPress uses for post revisions. If the two values are too dissimilar for word-level highlighting to be useful, it falls back to plain text automatically.

A **Resync Now** button next to the post title re-runs the sync check for just this post over AJAX (the same underlying action as the Sync page's "Check Now"), then reloads the page so the comparison, status, and diff link all reflect the fresh result.

**Push to Remote** sends the checked fields (and any selected meta as a `meta` object) as an authenticated REST request to the remote post, then re-runs the sync check for that post so its status and diff reflect the new state. The result is shown as a toast notification, consistent with Test Connection's UX.

Before sending, every pushed field/meta value (except `post_status`) has this site's own home URL rewritten to the remote site's home URL — recursing into arrays, so structured fields like ACF values are covered too. Without this, any internal link, embedded image, or similar URL authored on this site would be pushed verbatim and end up pointing back at this site once live on the remote. Only this site's own URL is touched; unrelated external links are left alone.

This job only detects and records sync status — pushing content is a manual action from the Sync Details page.

## Logging

When "Enable logging" is checked on the Settings page, connection tests, cron sync checks, and remote post lookups are written to daily log files under `wp-content/uploads/rest-in-sync-logs/`. Errors are always written to PHP's `error_log`, and additionally to these files when logging is enabled. Remote lookups log both the attempt (slug, GUID, UUID, and detected WPML language, if any) and, on a miss, how many remote entries were actually scanned — useful for telling apart a genuinely unmatched post from one hidden by a remote-side filter (e.g. WPML's default-language scoping). The Logs page lets you pick a day's log file, view its entries, or clear all log files, confirming deletion with a toast notification.

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
