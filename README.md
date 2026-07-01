# Ratesight

**AI SEO, review widgets, and local performance tracking for WordPress.**

[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](LICENSE)
![WordPress](https://img.shields.io/badge/WordPress-5.9%2B-21759b.svg)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

Ratesight combines two products into a single, unified WordPress plugin:

1. **Review Widgets** — embeds Ratesight widgets and shortcodes on your site.
2. **AI SEO Pages** — receives webhook requests to automatically publish
   AI-generated, SEO-optimized posts.

---

## Requirements

- WordPress 5.9 or later
- PHP 8.0 or later

## Installation

1. Upload the `ratesight` folder to `/wp-content/plugins/`
   (or install via **Plugins → Add New → Upload Plugin**).
2. Activate it in **WP Admin → Plugins**.
3. Open the new **Ratesight** menu item in the sidebar.

## Configuration

The settings page has four tabs:

- **Widgets** — Ratesight ID, Campaign ID, Domain ID, reviews page, star color,
  dark-text toggle, and sidebar/analytics/message widget toggles.
- **AI SEO Pages** — webhook secret key (generated server-side), your full
  webhook URL, default post status/author/parent category, and log retention.
- **Activity Log** — the last 100 webhook requests with status, title, category,
  post link, and any error.
- **Payload Reference** — the full JSON payload schema and response format.

### Required secrets (OAuth features)

The GSC / GBP OAuth features require two shared secrets that must match the
values configured on the Cloudflare Worker (`oauth.ratesight.com`). **No secrets
are bundled with the plugin** — define them in `wp-config.php`:

```php
define( 'RATESIGHT_STATE_SECRET', 'your-state-secret-here' );
define( 'RATESIGHT_TOKEN_SECRET', 'your-token-secret-here' );
```

Generate strong random values (for example, `openssl rand -hex 20`). Until both
are defined, the OAuth features stay disabled. See [`.env.example`](.env.example).

## Shortcodes

| Shortcode | Description |
| --- | --- |
| `[rs_leave_reviews]` | A 5-star link to your Ratesight review form plus a carousel of recent reviews. Ideal for thank-you or landing pages. |
| `[rs_all_reviews]` | Loads the full Ratesight reviews widget. Place it on your Reviews page. |

## Webhook endpoint

```
POST /wp-json/ratesight/v1/create-page?secret=YOUR_SECRET_KEY
```

Required payload fields: `title`, `article`. See the **Payload Reference** tab in
the plugin settings for full documentation.

## Contributing

Issues and pull requests are welcome. Please keep changes consistent with the
existing code style (PHP 8 typed properties/union types, single options schema,
no external CDN dependencies in the admin).

## License

Ratesight is licensed under the **GPL-2.0-or-later**. See [LICENSE](LICENSE).
