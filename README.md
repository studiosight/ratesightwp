<div align="center">

# Ratesight for WordPress

### Turn reviews into rankings.

**AI-written SEO pages, live review widgets, and local-search performance tracking — in one WordPress plugin.**

[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](LICENSE)
![WordPress](https://img.shields.io/badge/WordPress-5.9%2B-21759b.svg)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)
![Version](https://img.shields.io/badge/version-3.2.0-brightgreen.svg)

</div>

---

Ratesight brings your **review generation** and your **search visibility** into the same place. Publish AI-generated, SEO-optimized pages automatically, embed social-proof review widgets anywhere on your site, and track how your local search performance moves — all from a single plugin, with no external admin dependencies.

## ✨ Why Ratesight

- **📝 Publish SEO pages on autopilot.** A secure webhook receives AI-generated, fully-optimized articles and publishes them as native WordPress posts — with schema, titles, categories, and internal links handled for you.
- **⭐ Social proof that converts.** Drop a live star rating and a carousel of your latest reviews onto any page with a single shortcode.
- **📈 See what's actually working.** Local-search performance tracking with Google Search Console and Business Profile insights, right inside wp-admin.
- **🔍 Built to be found.** Automatic structured data, XML sitemaps, instant IndexNow submission, internal-link building, and 404 / redirect health monitoring.
- **🔓 Yours to own.** GPL-licensed, PHP 8 typed codebase, zero third-party CDNs loaded in your admin.

## What's inside

**Reviews & social proof**
- Embeddable Ratesight review widgets and shortcodes
- Live carousel of your most recent reviews
- Star ratings with customizable color and dark-text support
- Sidebar, message, and analytics widgets — toggle what you need

**AI SEO Pages**
- Secure webhook endpoint that publishes AI-generated articles as WordPress posts
- Server-side generated secret keys (cryptographically secure — never `Math.random()`)
- Configurable default status, author, and parent category
- Full activity log of the last 100 requests, with per-request status and error detail

**Search performance**
- Google Search Console integration
- Google Business Profile insights
- Local-search performance tracking surfaced in the dashboard

**Technical SEO, automated**
- Structured data (schema) and XML sitemap generation
- Instant search-engine notification via IndexNow
- Automatic internal / related-link building
- 404 routing and redirect-health monitoring

## Requirements

- WordPress 5.9 or later
- PHP 8.0 or later

## Installation

1. Upload the `ratesight` folder to `/wp-content/plugins/`
   (or install via **Plugins → Add New → Upload Plugin**).
2. Activate it in **WP Admin → Plugins**.
3. Open the new **Ratesight** menu item and follow the setup wizard.

## Shortcodes

| Shortcode | What it does |
| --- | --- |
| `[rs_leave_reviews]` | A 5-star link to your Ratesight review form plus a carousel of recent reviews — perfect for thank-you and landing pages. |
| `[rs_all_reviews]` | Loads the full Ratesight reviews widget. Drop it on your Reviews page. |

## Webhook endpoint

Publish an AI-generated page programmatically:

```
POST /wp-json/ratesight/v1/create-page?secret=YOUR_SECRET_KEY
```

Required fields: `title`, `article`. The full payload schema and response format
live in the **Payload Reference** tab inside the plugin settings.

> **Deploying the Search Console / Business Profile integration?** A couple of
> environment secrets must be defined in `wp-config.php`. See
> [`.env.example`](.env.example) for the required variables.

## Contributing

Issues and pull requests are welcome. Please keep changes consistent with the
existing style — PHP 8 typed properties and union types, a single options schema,
and no external CDN dependencies in the admin.

## License

Ratesight is licensed under the **GPL-2.0-or-later**. See [LICENSE](LICENSE).

<div align="center">
<sub>Built by <a href="https://ratesight.com">Ratesight</a> · Reviews, SEO, and local performance for WordPress.</sub>
</div>
