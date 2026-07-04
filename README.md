# REST in Sync

A lightweight WordPress plugin to smoothly sync posts from local environments to live servers via the WordPress REST API.

## Admin menu

The plugin registers a top-level **REST in Sync** menu with the following pages:

| Page | Description |
| --- | --- |
| Sync | Placeholder — sync tooling is not implemented yet. |
| Help | Explains how the plugin connects to the live site via the REST API. |
| Logs | Placeholder — sync logging is not implemented yet. |
| Settings | Carbon Fields powered form to configure the remote site connection, with a "Test Connection" button. |

## Connecting to a live site

The Settings page collects the live site's URL, a WordPress username, and an [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) for that user. Requests to the remote site are authenticated with HTTP Basic Auth using those credentials.

The **Test Connection** button calls the remote site's REST API (`/wp-json/wp/v2/posts` and `/wp-json/wp/v2/pages`) and displays the 10 most recently updated posts/pages, or an error if the site is unreachable or the credentials are invalid.

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
