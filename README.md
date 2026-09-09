# Ratesight for WordPress

AI-generated SEO pages, review widgets, and local-search performance tracking — in a single WordPress plugin.

[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](LICENSE)
![WordPress](https://img.shields.io/badge/WordPress-5.9%2B-21759b.svg)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)
![Version](https://img.shields.io/badge/version-3.11.3-brightgreen.svg)

Ratesight unifies review generation and search visibility in one place. It publishes AI-generated, SEO-optimized pages through a secure webhook, embeds review widgets anywhere on your site, and surfaces local-search performance from Google Search Console and Business Profile — without loading any third-party dependencies in your admin.

## Overview

| | |
| --- | --- |
| **Reviews** | Embeddable review widgets and shortcodes, a live carousel of recent reviews, and configurable star ratings. |
| **AI SEO Pages** | A secure webhook that publishes AI-generated articles as native WordPress posts, with schema, titles, categories, and internal links applied automatically. |
| **Search performance** | Google Search Console integration and Business Profile insights, surfaced inside the WordPress dashboard. |
| **Technical SEO** | Structured data, XML sitemaps, IndexNow submission, internal-link building, and 404 / redirect-health monitoring. |

## Requirements

- WordPress 5.9 or later
- PHP 8.0 or later

## Installation

1. Upload the `ratesight` folder to `/wp-content/plugins/`, or install it via **Plugins → Add New → Upload Plugin**.
2. Activate it in **WP Admin → Plugins**.
3. Open the **Ratesight** menu item and complete the setup wizard.

## Configuration

The client-facing menu has six destinations:

- **Overview** — positive search, local visibility, and completed-work results.
- **SEO Content** — published search-focused service and location content.
- **Publishing** — exact site-specific webhook URLs and publishing defaults.
- **Activity Log** — webhook successes, warnings, failures, payload diagnostics, and retries.
- **Reviews & Widgets** — reviews-page selection, a live style preview, appearance controls, and copy-ready review shortcodes.
- **Support** — a plain-English service summary, connection status, and contact details.

> Deploying the Search Console / Business Profile integration requires a small
> number of environment secrets defined in `wp-config.php`. See
> [`.env.example`](.env.example) for the required variables.

## Shortcodes

| Shortcode | Description |
| --- | --- |
| `[rs_leave_reviews]` | A five-star link to your Ratesight review form and a carousel of recent reviews. Suited to thank-you and landing pages. |
| `[rs_all_reviews]` | Loads the full Ratesight reviews widget. Intended for your Reviews page. |

## Webhook

Publish an AI-generated page programmatically:

```
POST /wp-json/ratesight/v1/create-page
```

Required fields are `title` and `article`. Protected requests use the negotiated
`rs-hmac-v2` headers; shared credentials are not sent in URLs or displayed in the
client-facing plugin.

## Contributing

Issues and pull requests are welcome. Please keep changes consistent with the
existing conventions: PHP 8 typed properties and union types, a single options
schema, and no external CDN dependencies in the admin.

## License

Ratesight is licensed under the [GPL-2.0-or-later](LICENSE).
