# REST in Sync

A lightweight WordPress plugin to smoothly sync posts from local environments to live servers via the WordPress REST API.

## Admin menu

The plugin registers a top-level **REST in Sync** menu with the following pages:

| Page | Description |
| --- | --- |
| Sync | Lists posts flagged out of sync by the cron job, with a search box to filter the list by title, and "Details", "Check Now", and "Ignore Until Next Sync" buttons per row. |
| Help | Explains how to set up the connection to a live site (including remote-side requirements), the cron sync check, and logging. |
| Logs | Shows daily log files of sync and connection test activity, with a file selector and a "Clear all logs" button. |
| Field Settings | Lists every field/meta key currently flagged "Don't sync by default" or "Don't use in diff" in one place, with the same toggle buttons as the Details page, plus wildcard pattern rules for excluding a whole family of meta keys at once. |
| Settings | Carbon Fields powered form to configure the remote site connection, which post types to sync, with a "Test Connection" button and an "Enable logging" toggle. |

## Two-way installs and the "remote server" setting

Since REST in Sync's own REST routes (the UUID-linking meta and the all-meta route below) are served by whichever site runs this plugin, the usual setup is to install it on **both** sites in a sync pair. The Settings page's **"This is the remote server"** checkbox marks a site as the pair's live/target side only:

- Its own sync cron job never gets scheduled (`Rest_In_Sync_Cron::maybe_reschedule()` clears any existing scheduled event instead).
- Its Sync/Details pages' manual actions (Check Now, Resync Now, Push to Remote, Pull from Remote) are disabled with a clear message, and the Sync page shows an explanatory notice instead of an out-of-sync list.
- Its `/wp-json/rest-in-sync/v1/meta/{id}` route (see "Sync Details page" below, which covers what this route is for) keeps working regardless — that's what makes it useful as a remote target in the first place.

Leave it unchecked on the site that's actually driving the sync (the one with Site URL/Username/Application Password filled in and cron enabled). Checking it hides every other Settings field (connection details, logging, post types, cron) via Carbon Fields conditional logic, since none of them apply to a pure remote target, and shows a short note explaining why.

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

The **Post Types to Sync** field on the Settings page lists every *public* post type registered on this site that supports the REST API, so you can choose which ones get checked and validated. `show_in_rest` alone would also list internal block-editor/FSE post types (`wp_block`, `wp_template`, `wp_navigation`, etc.) that are registered `public => false` and aren't content anyone would sync as an article — `public => true` excludes those. Posts and Pages are checked by default.

## Sync status cron job

The Site URL, Username, and Application Password on the Settings page must all be filled in before any of this runs — the cron job, "Check Now"/"Resync Now", the Sync Details comparison, and "Push to Remote" all no-op (returning a clear "not configured" message for the manual actions) until the connection is fully configured, rather than attempting requests against a blank or partial URL.

A WP-Cron job periodically compares each syncable post against its counterpart on the remote site and records whether it's in sync. A post is matched to a remote post by trying the local post's own ID directly first, then falling back to slug, then a scan for a matching GUID or UUID. ID is tried first because slug alone can't be trusted — WPML, for example, can give multiple language translations of the same article the identical slug, distinguished only by a URL language prefix rather than the slug itself, so a slug-only lookup can resolve to the wrong translation. When this site shares history with the remote (e.g. a local dev environment cloned from the live database), the bulk of existing content shares identical post IDs, so trying that first sidesteps the ambiguity entirely; if no post exists at that ID on the remote, it's treated as genuinely new content and falls through to slug/GUID/UUID matching instead. The first time a match is found (by any method), the post's UUID is written back onto the matched remote post's `_rest_in_sync_uuid` meta over REST, so future lookups can match by that shared UUID even if the local slug later changes.

Writing that meta back to the remote post requires the remote site to accept it — which it will automatically if it's also running REST in Sync (any version with this UUID-linking feature registers the meta for REST access). Against a plain WordPress install without this plugin, the write is silently rejected as read-only protected meta, and matching just falls back to slug/GUID as before; the rest of the sync (comparisons, pushes) is unaffected either way.

### WPML sites

When WPML is active on this site (`ICL_SITEPRESS_VERSION` defined), each post's WPML language code is looked up via the `wpml_element_language_code` filter and sent as a `lang` query argument on every remote lookup request (slug search and the GUID/UUID scan). This matters because WPML's own REST API integration scopes collection queries to the site's default language unless told otherwise — without the `lang` argument, any post in a non-default language is invisible to those lookups on the remote site even though it exists there, and shows up as "No matching post was found on the remote site." On non-WPML sites, or for content WPML doesn't track a language for, no `lang` argument is sent and lookups behave exactly as before.

The comparison covers `post_title`, `post_content`, `post_excerpt`, `post_status`, and any post meta the remote site exposes via REST — excluding this plugin's own bookkeeping meta, so tracking the sync status itself can't cause a false "out of sync" result. Before comparing, every field/meta value has both sites' home URLs (any scheme, with or without "www.") replaced with a shared placeholder, so internal links, embedded image URLs, etc. — which always differ between the two domains — don't themselves cause a false "out of sync" result; JSON's optional `\/` slash-escaping is then normalized to a plain `/` (WordPress/block-editor versions disagree on whether to escape it inside a Gutenberg block's JSON attributes, though it decodes to the same character either way); and finally *all* whitespace is stripped entirely, not just collapsed, since a pretty-printed vs. minified JSON blob differs by single spaces around colons/commas, which collapsing repeated whitespace down to one space wouldn't catch. All three only affect the diff calculation; the Sync Details page still shows each field's real, unmodified saved value.

Each checked post gets these meta fields:

| Meta key | Description |
| --- | --- |
| `_rest_in_sync_uuid` | UUID identifying the post for sync linking. |
| `_rest_in_sync_remote_id` | The matched post's ID on the remote site. |
| `_rest_in_sync_status` | `never_synced`, `in_sync`, or `out_of_sync`. |
| `_rest_in_sync_last_checked` | Unix timestamp of the last comparison. |
| `_rest_in_sync_diff_id` | UUID of the diff JSON file, present only when out of sync. |

When a post is out of sync, the differing fields are written as JSON to `wp-content/uploads/rest-in-sync-diffs/{uuid}.json`. The Sync page lists every out-of-sync post (ID, title, post type, last checked); a search box above the list filters it by title (via WP's standard `s` search, matched against `post_title`/`post_content`/`post_excerpt`). Each row's "Details" link opens that post's Sync Details page in a new tab, and its "Check Now" button re-runs `check_single()` for that post over AJAX — updating its row in place, or removing it from the list if it's now in sync — without waiting for the next cron run.

The Settings page configures:
- **Cron Batch Size** — how many posts are checked per cron run (default 10).
- **Sync Check Interval** — how often the cron runs, from every 5 minutes up to every 24 hours (default every 15 minutes).
- **Resync Threshold (hours)** — how long to wait before re-checking a post that's already been checked; posts that have never been checked are always processed first (default 24 hours).

WP-Cron only fires on a page load, so on low-traffic sites checks can run later than scheduled. For reliable timing, set `define('DISABLE_WP_CRON', true);` in `wp-config.php` and trigger `wp-cron.php` from a real system cron job instead, e.g. `*/15 * * * * wget -q -O /dev/null "https://your-site.com/wp-cron.php?doing_wp_cron"`.

## Field settings

Every comparable field/meta key (the same set the cron job compares) has two global toggles, stored once per field/meta name in the `rest_in_sync_field_settings` option and applied across all post types:

- **Don't sync by default** — excludes the field from the default checkbox selection on the Sync Details page. It still shows up there; it's just unchecked unless selected manually.
- **Don't use in diff** — excludes the field from the cron job's out-of-sync comparison and from the stored diff, so a field that's expected to differ (e.g. a per-environment value) doesn't keep the post flagged out of sync. The field still appears on the Sync Details page for manual pushing.

Both toggles can be set from any post's Sync Details page, or managed all in one place on the **Field Settings** page, which lists every field/meta key with either toggle currently on.

That page also has **Pattern Rules**, stored separately in the `rest_in_sync_field_pattern_settings` option: a wildcard pattern — `*` matches any run of characters — applies the same two toggles to every field/meta key matching it, without having to flag each one individually. This is what makes a family of fields like per-day view-count trackers (`view_count`, `view_count_2026`, `view_count_2026-07-05`, …) manageable with one rule (`view_count*`) instead of one entry per key. A field's own explicit toggle always overrides a matching pattern for that one field — toggling either setting seeds the *other* setting from its current fully-resolved value (exact override or pattern match) rather than a blank default, so flipping one setting can never accidentally cancel a pattern's effect on the other.

## Sync Details page

The "Details" link on the Sync page opens a per-post Sync Details page with a live, field-by-field comparison against the remote post (including fields with equal values, hidden by default — toggle "Show fields with equal values" to reveal them). Each row has:

- A **Sync** checkbox, checked by default unless the field is set to "Don't sync by default" — with **Select All** / **Select None** / **Select Default** controls above the table.
- The two per-field toggle buttons described above.

Since the standard REST API only ever exposes/accepts meta explicitly registered with `show_in_rest` — which excludes most ACF fields and other custom fields by default — this plugin also registers its own route, `/wp-json/rest-in-sync/v1/meta/{id}` (`includes/class-rest-in-sync-rest-controller.php`), gated by the same `edit_post` capability check as the rest of the authenticated connection. A `GET` returns *every* meta key on a post directly; a `POST` writes a `{"meta": {...}}` body the same way, refusing protected (underscore-prefixed) keys so it can't be used to overwrite internal bookkeeping. Without this, pushing or pulling an unregistered meta key would silently have no effect at all through the standard endpoint alone — WordPress just drops it. When the remote site is also running a plugin version with this route: reads are merged in and become real, comparable data counted toward the cron's out-of-sync status; pushes/pulls of any meta key actually take effect, not just the handful that happen to be REST-registered. If the remote doesn't have the route yet (an older version, or a plain WordPress install), reads degrade gracefully to the standard REST-exposed meta only, and pushes/pulls are limited to that same handful.

Any local meta key still not covered by either source is listed in the Details view anyway, marked "not exposed on remote" with no remote value shown, since there's no way to know what the remote actually holds for it. These are always included there (not hidden behind "Show fields with equal values") but never counted toward the cron's out-of-sync status — a site can easily have hundreds of such fields (e.g. per-day view-count trackers), and there's no remote value to genuinely compare against. A **Filter by field name** box above the table helps narrow down large field/meta lists.

For the `title`, `content`, and `excerpt` fields, a differing Local/Remote pair is rendered with their differing words highlighted (`Rest_In_Sync_Diff_Renderer`, in `includes/class-rest-in-sync-diff-renderer.php`), reusing the same word-level diff engine WordPress uses for post revisions. If the two values are too dissimilar for word-level highlighting to be useful, it falls back to plain text automatically.

A **Resync Now** button next to the post title re-runs the sync check for just this post over AJAX (the same underlying action as the Sync page's "Check Now"), then reloads the page so the comparison, status, and diff link all reflect the fresh result. Below that, the page also shows this post's local/remote IDs and this post's own permalink and the matched remote post's URL side by side, so you can open both directly to compare them as a real reader would — useful for spotting things a field-by-field diff can't, like a translation that was never actually written.

Below the URLs, **Edit** and **Revisions** buttons link straight into each site's wp-admin for that post — the edit screen, and (via WordPress core's own `/revisions` REST route on the remote, the same authenticated connection as everything else) directly into the two-pane revision comparison screen for its most recent revision, or "No revisions yet" if it doesn't have one. This is the fastest way to see *when* and *by whom* a field actually changed on either site — something a REST-based diff alone can't tell you.

Next to it, **Ignore Until Next Sync** (also available per-row on the Sync page) sets a one-time `_rest_in_sync_ignored` flag that hides the post from the out-of-sync list without changing its actual status — unlike the permanent, global "Don't use in diff" field setting, this is a per-post, temporary snooze. The very next check (cron or manual) for that post clears the flag automatically regardless of outcome, so a post that's still genuinely out of sync reappears rather than staying hidden forever. From the Details page, since there's nothing else to see here once a post is ignored, a successful click redirects back to the Sync page so you can see it's actually gone from the list.

**Push to Remote** sends the checked fields (and any selected meta as a `meta` object) as an authenticated REST request to the remote post, then re-runs the sync check for that post so its status and diff reflect the new state.

**Pull from Remote** is the mirror image: it takes the same checked fields/meta but writes the remote's values onto this local post instead, via `wp_update_post()`/`update_post_meta()` — the home-URL rewrite (see below) runs in the opposite direction so remote links don't leak into local content. Both actions show a confirmation dialog before proceeding, since either one overwrites real content, and both reload the page on success so the comparison reflects the new state. The result is shown as a toast notification, consistent with Test Connection's UX.

Each row also has its own **Push**/**Pull** buttons in the Actions column, for syncing just that one field/meta key without touching anything else selected — same underlying AJAX actions as the bulk buttons (each sends a one-item `fields` array), same confirmation dialog (naming that specific field), same reload-on-success.

Before sending, every pushed field/meta value (except `post_status`) has this site's own home URL rewritten to the remote site's home URL — recursing into arrays, so structured fields like ACF values are covered too — and pulling rewrites in the opposite direction. Without this, any internal link, embedded image, or similar URL authored on one site would carry over verbatim and end up pointing at the wrong domain once on the other site. Only the site-of-origin's own URL is touched; unrelated external links are left alone.

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
