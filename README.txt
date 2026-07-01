=== Ratesight ===
Contributors: ratesight
Tags: seo, reviews, ai, local seo, content
Requires at least: 5.9
Tested up to: 7.0
Stable tag: 3.2.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI SEO, review widgets, and local performance tracking for WordPress.

== Description ==

Combines two Ratesight products into a single, unified plugin:

  1. Review Widgets  — Embeds Ratesight widgets and shortcodes on your site.
  2. AI SEO Pages    — Receives webhook requests to automatically publish
                       AI-generated, SEO-optimized posts.


== Installation ==
1. Upload the `ratesight` folder to /wp-content/plugins/
   (or install via Plugins → Add New → Upload Plugin)
2. Activate in WP Admin → Plugins
3. Navigate to the new "Ratesight" menu item in the sidebar

SETTINGS
--------
The settings page has four tabs:

  Widgets
    - Ratesight ID, Campaign ID, Domain ID
    - Reviews page (for "See All Reviews" link)
    - Star color, dark text toggle + color
    - Sidebar widget, analytics script, message widget toggles

  AI SEO Pages
    - Webhook secret key (generate server-side with the Generate button)
    - Your full webhook URL with the secret embedded (copy with one click)
    - Default post status, author, parent category
    - Log retention period (days)

  Activity Log
    - Last 100 webhook requests with status, title, category, post link, error

  Payload Reference
    - Full JSON payload schema with required/optional field reference
    - Response format documentation

SHORTCODES
----------
[rs_leave_reviews]
  Displays a 5-star link to your Ratesight review form plus a carousel
  of recent reviews. Ideal for thank-you pages or landing pages.

[rs_all_reviews]
  Loads the full Ratesight reviews widget. Place on your Reviews page.

WEBHOOK ENDPOINT
----------------
  POST /wp-json/ratesight/v1/create-page?secret=YOUR_SECRET_KEY

Required payload fields: title, article
See the Payload Reference tab in the plugin settings for full documentation.


== Changelog ==
3.2.7 — Simplify to OID-only authentication
  - Removed the Site Key field, the auto-provision exchange, and the per-site /
    shared-secret setup steps. The site now authenticates to the Worker by its
    OID (Ratesight ID) alone — enter the Ratesight ID and it connects. The Worker
    trusts known OIDs (ALLOWED_OIDS) and revokes via REVOKED_OIDS.
  - RATESIGHT_STATE_SECRET / RATESIGHT_TOKEN_SECRET remain optional HMAC signing.
  - Setup checklist collapses to a single "Ratesight ID entered" auth step.

3.2.6 — De-duplicate the setup checklist
  - In per-site mode the "OAuth credentials configured" checklist item duplicated
    the "Site Key entered" item and still showed the old wp-config guidance.
    Removed it in per-site mode so the checklist shows one clear auth step.

3.2.5 — Auto-provision the per-site Site Key
  - The Site Key is now fetched automatically from the Worker (POST /site-key
    with the site's OID + URL, license-validated server-side) once the Ratesight
    ID is set — no manual paste, no dashboard lookup. The manual field remains as
    a support/override fallback. Attempts are rate-limited via a transient.

3.2.4 — Enable per-site auth; add live redirect-list endpoint
  - Turn on per-site (OID-bound) auth: each site authenticates to the Worker
    with its own Site Key (pasted in the admin) instead of a shared secret.
    No wp-config needed. credentials_configured() now treats a valid Site Key
    as sufficient, and the setup copy points to the Site Key field.
  - Add GET /wp-json/ratesight/v1/redirects returning the current redirect map
    (capability: list_redirects) so external audits can read live state and
    stay idempotent instead of replaying a local set-only log.

3.2.3 — Correct the OAuth-credentials setup copy
  - The setup checklist and Connections tab still told admins the secrets were
    "bundled by default" / to edit REPLACE_WITH_ placeholders in the plugin
    source. Since 3.x requires RATESIGHT_STATE_SECRET / RATESIGHT_TOKEN_SECRET
    in wp-config.php, both messages now say so.

3.2.2 — Fix GBP performance metrics request
  - fetchMultiDailyMetricsTimeSeries was missing the dailyMetrics= key on the
    first metric, so every request 400'd ("Cannot bind query parameter") and
    no Business Profile performance data was ever stored. Prepend the key.
  - Normalise the dailyRange date query params to canonical camelCase
    (startDate/endDate) field names.

3.2.1 — Redirect self-heal, delete, and hardening
  - handle_redirects() no longer fires when a published post resolves at the
    request path, so recreating a page at a redirected slug self-heals (the
    redirect goes inert with no manual cleanup)
  - POST /redirect accepts { from, delete:true } (delegates to the DELETE
    handler) for callers that can't send an HTTP DELETE
  - /capabilities now reports delete_redirect: true
  - Redirect set/delete require a configured webhook secret AND a valid HMAC
    signature (fail closed) — no more unsigned redirect changes

3.0.0 — Merged & rebuilt
  - Merged Ratesight Widgets and Ratesight AI SEO Pages into one plugin
    (two separate plugins both defined RATESIGHT_VERSION, causing a fatal
    error when both were active)
  - Single options schema in class-ratesight-options.php — no duplication
  - Single admin menu with four tabs
  - Secret generation moved server-side (wp_generate_password backed by
    random_bytes) — original used Math.random() which is not cryptographically
    secure for authentication tokens
  - WP-Cron job wired to prune_logs() — original plugin defined the method
    but never scheduled it, so logs grew indefinitely
  - Activator adds indexes on received_at and status columns for log queries
  - Deactivator clears the cron event on plugin deactivation
  - uninstall.php drops the log table and deletes all options on deletion
  - Shortcodes use wp_enqueue_script() — no raw <script> tags in output
  - Public CSS only enqueued when a shortcode is actually used
  - All options sanitised through a single sanitise() method
  - PHP 8 union types and typed properties throughout
  - No external CDN dependencies in the admin (removed Bootstrap 3 + Switchery)
