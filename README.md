# REST in Sync

A lightweight WordPress plugin to smoothly sync posts from local environments to live servers via the WordPress REST API.

## Admin menu

The plugin registers a top-level **REST in Sync** menu with the following pages:

| Page | Description |
| --- | --- |
| Sync | Placeholder — sync tooling is not implemented yet. |
| Help | Explains how the plugin connects to the live site via the REST API. |
| Logs | Shows daily log files of sync and connection test activity, with a file selector and a "Clear all logs" button. |
| Settings | Carbon Fields powered form to configure the remote site connection, which post types to sync, with a "Test Connection" button and an "Enable logging" toggle. |

## Connecting to a live site

The Settings page collects the live site's URL, a WordPress username, and an [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) for that user. Requests to the remote site are authenticated with HTTP Basic Auth using those credentials.

The **Test Connection** button calls the remote site's REST API for each selected post type's REST base route (e.g. `/wp-json/wp/v2/posts`, `/wp-json/wp/v2/pages`) and displays the 10 most recently updated items, or an error if the site is unreachable or the credentials are invalid.

## Post types to sync

The **Post Types to Sync** field on the Settings page lists every post type registered on this site that supports the REST API, so you can choose which ones get checked and validated. Posts and Pages are checked by default.

## Logging

When "Enable logging" is checked on the Settings page, connection test activity (and any future sync operations) is written to daily log files under `wp-content/uploads/rest-in-sync-logs/`. Errors are always written to PHP's `error_log`, and additionally to these files when logging is enabled. The Logs page lets you pick a day's log file, view its entries, or clear all log files.

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
