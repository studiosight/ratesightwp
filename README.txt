=== Ratesight ===
Contributors: ratesight
Tags: seo, reviews, ai, local seo, content
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 3.15.3
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

CLIENT MENU
-----------
The client-facing menu has six destinations:

  Overview
    - Positive search, local visibility, and completed-work results

  SEO Content
    - Published search-focused service and location content

  Publishing
    - Exact site-specific webhook URLs and publishing defaults

  Activity Log
    - Webhook successes, warnings, failures, payload diagnostics, and retries

  Reviews & Widgets
    - Reviews-page selection, a live style preview, appearance controls,
      and copy-ready review shortcodes

  Support
    - Plain-English service summary, connection status, and contact details

SHORTCODES
----------
[rs_leave_reviews]
  Displays a 5-star link to your Ratesight review form plus a carousel
  of recent reviews. Ideal for thank-you pages or landing pages.

[rs_all_reviews]
  Loads the full Ratesight reviews widget. Place on your Reviews page.

WEBHOOK ENDPOINT
----------------
  POST /wp-json/ratesight/v1/create-page

Required payload fields: title, article
Protected requests require the negotiated rs-hmac-v2 headers. Shared credentials
are never sent in URLs or displayed in the client-facing plugin.


== Changelog ==

3.15.3 - Missing pages are handled automatically by default

  - The 404 Fuzzy Router default is now Automatic (same-city-or-hub) instead of Off: on a site with no stored mode, a missing page is redirected (301) to the closest matching page when the match is strong, never from one city's page to another city's, with the base service page as the fallback for commercial and office city pages. With no close match the visitor still gets the 404 page. No per-site setting is needed.
  - A mode an admin stored (Off, Automatic or Legacy) is still honored. A site whose AI SEO Pages tab was saved while the default was Off has Off stored and keeps it until the select is changed.
  - capabilities.fuzzy_404 reports the new default.

3.15.2 - Ratesight CRM posts need the site's CRM key as well as the CRM's address

  - With "Ratesight CRM posts" on, an unsigned POST /create-page now gets the Final Post Status only when it connects from the Ratesight CRM's address (REMOTE_ADDR only) AND carries the site's CRM key, as the rs_crm_key query parameter of the webhook URL or the X-Ratesight-CRM-Key header. With either missing, or a wrong key, the post is a new draft (audit note crm_key_missing, crm_key_invalid). A key sent from any other address is never accepted (note crm_key_wrong_source). The key is per site, random (240 bits), stored in its own option and compared in constant time; it is not the signing secret and cannot sign anything.
  - Publish limit lowered from 500 to 10 per site per 24 hours. Past it a CRM post is still created, as a draft (note crm_publish_limit), so nothing is lost.
  - Every auto-published CRM post is recorded (time, source ratesight_crm, address, request id, post id, slug, post type, status, key fingerprint) in the option ratesight_crm_publish_log (last 100).
  - New signed route /crm-publish: GET (signed read) returns the switch, key fingerprint, limits and recent auto-publishes; POST (signed mutation) { enabled, rotate_key, dry_run } turns the switch on or off, makes or rotates the key, and returns the CRM webhook URL while the switch is on. The key is never shown in the plugin admin (fingerprint only).
  - Content still goes through the same create-page path: wp_kses_post on the article, new post only, no external stylesheet, no update of an existing post.
  - capabilities.auth.trusted_publisher reports requires_key, key_configured and over_limit.

3.15.1 - Ratesight CRM posts can follow the Final Post Status; one decision per request

  - New setting, AI SEO Pages > Ratesight CRM posts (off by default). The Ratesight CRM does not sign its requests, so since 3.14.0 its posts were saved as drafts whatever the Final Post Status said. With the setting on, an unsigned POST /create-page that connects from the Ratesight CRM's address (REMOTE_ADDR only; forwarding headers are never read) creates the post with the requested status or the site's Final Post Status, up to 500 per site per 24 hours. It is still a new post only: an existing post is never updated (a unique slug is used), a payload naming a post id is refused, and no external stylesheet is attached. Audited as trusted_publisher_accepted; the Activity Log note reads "Ratesight CRM post: created as a new post".
  - With the setting off, or from any other address, nothing changes: an unsigned create-page is a new draft only. enforce_v2 still rejects every unsigned request.
  - Fix: a request is now authenticated once. WordPress calls the permission check again for each handler of the route when it builds the Allow header, and each repeat was treated as a new request. One unsigned create-page therefore used three of the 30 unsigned draft slots (a site was limited to 10 drafts per 24 hours, then 429), and every valid signed request left a second audit row reading rs_nonce_replayed. The limit is 30 again and the audit has one row per request.
  - Fix: the hourly retry of a stuck deferred publish now applies the status the request asked for (stored as post meta _rs_request_status) before the site setting. Before, an unsigned draft whose deferred publish never completed (cron or loopback failure, or a failing image download) was published by the retry.
  - Fix: Link Domain Rules (Links tab) has its own settings group. Saving it used to reset the Final Post Status and Reference Page Status to Published, clear the RS Page base slug and turn the AI SEO Pages checkboxes off; saving the AI SEO Pages tab used to empty the two domain lists.
  - capabilities.auth reports trusted_publisher (enabled, addresses, max, window_seconds).

3.15.0 - Page structured data through update-page

  - POST /update-page accepts `schema`: one JSON-LD object (schema.org @context, with @type or @graph, at most 32 KB) stored as the page's structured data block, or null / '' to remove it. It is validated before the dry run, so dry_run reports the same refusal a write would and lists `schema` in would_write. The previous block is kept in the pre-update snapshot.
  - GET /update-page reports `schema` (the stored block exactly as kept, '' when none), `schema_hash` (sha256) and `schema_write: true`; /capabilities reports `schema_write: true`.
  - The block is printed with class="ratesight-schema" and every HTML-significant character escaped inside the JSON. Before, it went through the post HTML filter, which changed a literal & in a value into &amp;.
  - Nothing else changes: an update-page call without `schema` leaves the block untouched.

3.14.3 - Paired sites can join managed plugin updates

  - A site that was paired with the dashboard before plugin enrollment existed now sends its enrollment request once, so an operator can approve it into managed plugin updates. Before, a paired site never sent one, so it could only be updated by hand.
  - A paired site whose enrollment was already accepted never sends it again, including after a plugin update. A paired site that is refused retries only with the daily report, not every 15 minutes, and never sends an installation report.
  - After any plugin update the site reports within about a minute instead of waiting up to a day.
  - Enrollment still carries no secret; the dashboard verifies the installation key and an operator approves every site.

3.14.2 - Ratesight REST responses are never cached

  - Every response in the ratesight/v1 REST namespace (capabilities, signed reads, writes, and every error including failed signature or permission checks and unknown routes) is now sent with Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private; Pragma: no-cache; Expires: 0; CDN-Cache-Control: no-store; Cloudflare-CDN-Cache-Control: no-store; Surrogate-Control: no-store. Earlier versions left caching to the site, so a CDN could keep the capabilities document (and report an old plugin_version) for hours after an update, or serve a stored signed read to a later request.
  - Vary lists Authorization, Origin and the X-Ratesight signature headers.
  - Caching headers already set on these responses by a theme or caching plugin (for example Cache-Control: public, max-age=86400) are replaced, and ETag and Last-Modified are removed. The headers are applied on rest_post_dispatch and sent again just before the body is written.
  - Page caches are told to skip these requests: DONOTCACHEPAGE (WP Super Cache, W3 Total Cache, WP Rocket and others), LiteSpeed Cache (LSCACHE_NO_CACHE, litespeed_control_set_nocache and X-LiteSpeed-Cache-Control: no-cache), WP Rocket (do_rocket_generate_caching_files) and nginx fastcgi caches (X-Accel-Expires: 0).
  - Other REST namespaces are not changed.

3.14.1 - SEO writes change only the fields they are sent

  - POST /update-page with only meta_description (or only meta_title) now leaves the other field untouched in every SEO plugin's store. Earlier versions rewrote the omitted field from the Yoast or Rank Math value, which blanked the Squirrly SEO and SEOPress title whenever only the description was sent.
  - Squirrly SEO: a field whose stored value already equals the value sent (compared as text, so Squirrly's HTML-escaped storage matches) is not rewritten, and the Squirrly table row is saved only when a field changes.
  - POST /create-page on an existing slug (content re-upsert) now writes only the SEO fields it is sent. Earlier versions set the SEO title to the post title and the description to the summary (empty when absent) on every SEO plugin, overwriting a hand-written title and blanking the description. New posts keep those defaults.
  - Squirrly SEO: a write whose values are already stored reports the Squirrly table as confirmed only when its row was read through Squirrly's own models.
  - GET /update-page reports every active SEO plugin (seo_plugins), a squirrly block with the stored value and the store of each field (Squirrly table row, _sq_* post meta, or none), Squirrly's output switches for this page, and seo_write_mode field_preserving.

3.14.0 - Signed requests only; fuzzy 404 redirects off by default

  - Security: protected REST routes (every page, redirect, related-links, media alt, IndexNow, performance snapshot and plugin-update write, plus the protected reads) now reject requests that carry an invalid signature, and requests that carry none (with the draft-only exception below), in every authentication mode. Earlier versions accepted unsigned requests in legacy mode, and in observe_v2 when no signature header was sent.
  - legacy mode accepts a valid legacy body signature; observe_v2 accepts a valid rs-hmac-v2 or legacy signature; enforce_v2 is unchanged (rs-hmac-v2 only).
  - One compatibility exception in legacy and observe_v2: an unsigned POST /create-page may create a NEW DRAFT only. Any requested status is downgraded to draft, an existing post is never updated (a unique slug is used instead), a payload naming an explicit post id is refused, no external stylesheet is attached, and at most 30 such drafts are accepted per site per 24 hours (then 429). Each one is audited as unsigned_draft_accepted.
  - The auth audit now records the connecting address (REMOTE_ADDR only).
  - capabilities.auth reports unsigned_accepted: false, unsigned_draft_create and unsigned_draft_limit.
  - The runtime 404 fuzzy router is Off unless a site has an explicitly stored mode: a missing page stays a 404 instead of being redirected to the most similar slug. Sites with a stored mode (legacy, same-city-or-hub or off) keep it.
  - Saving another settings form no longer pins an unset fuzzy mode to a value nobody chose.
  - capabilities.fuzzy_404 reports the effective mode, whether it was explicitly stored, and the default.
  - Settings: the 404 Fuzzy Router select lists Off (default) first.

3.13.0 - Sites appear in the dashboard without a client id

  - Sites without a Ratesight client id now report the installation to the dashboard on activation and once a day (site address, site name, plugin version and installation key; no secrets).
  - Adds a signed /claim route so the dashboard can set the client id for this installation; the enrollment is sent right after a successful claim.
  - A claim never replaces a different client id unless the signed request asks for it, and never on a site that is already paired.
  - Sites that already have a client id keep the existing enrollment behaviour.
  - Retires plugin-side Google Business Profile auto-posting of blog posts (moved to the dashboard); capabilities report gbp_auto_post false.

3.12.1 - Multiple pinned control-plane keys

  - Accepts a list of pinned control-plane public keys and accepts a signature that verifies against any of them, so a replacement signing key can reach the fleet before the current signing key is retired.
  - Keeps the existing primary key byte-identical: a site verifying today's signatures is unaffected.
  - Adds the Update URI header, so WordPress cannot treat "ratesight" as a wordpress.org slug.

3.12.0 - Approval-first dashboard enrollment

  - Announces eligible OID-bound installations to the Ratesight dashboard without provider credentials.
  - Proves site ownership through a stable installation key before an operator can approve pairing.

3.11.3 - Restore Activity Log layout

  - Keeps the original Activity Log as the primary view and moves technical request diagnostics into a collapsed section below it.

3.11.2 - Preserve CRM publishing during signed-auth observation

  - Keeps unsigned legacy CRM publishing operational while a paired site observes signed-auth adoption.
  - Continues rejecting invalid supplied signatures and keeps strict enforcement unchanged.
  - Adds sanitized request-arrival and authentication evidence to Activity Log.

3.11.1 - Restore publishing diagnostics

  - Restores Publishing and Activity Log to the WordPress admin menu.
  - Shows the exact site-specific webhook URL without exposing provider credentials.
  - Restores failed-request diagnostics, filtering, and retry controls.

3.11.0 - Dashboard-owned blog publication events

  - Sends signed publication events for manual, scheduled, restored, and Ratesight-created blog posts.
  - Retains a durable local outbox until the dashboard returns a typed acceptance receipt.
  - Shows a client-safe blog sync summary without storing GBP provider credentials in WordPress.

3.10.6 - End-to-end managed update proof

  - Advances the version to verify that the dashboard can update an active Ratesight installation without manual WordPress recovery.

3.10.5 - Active plugin update lifecycle

  - Keeps Ratesight active while a signed dashboard update replaces its files.
  - Scopes WordPress maintenance mode, fatal-error detection, and rollback behavior to the update request without leaving cron state behind.

3.10.4 - Dashboard-managed update proof

  - Advances the version to verify installation from the Ratesight dashboard through the corrected signed updater.

3.10.3 - Explicit managed-update execution

  - Runs a confirmed signed dashboard installation as an explicit update instead of applying WordPress background-update eligibility policy.
  - Retains WordPress filesystem validation, temporary backup, fatal-error detection, and rollback handling.

3.10.2 - Dashboard installation verification

  - Advances the release version so an installed 3.10.1 bridge can verify dashboard-managed updates without another manual upload.

3.10.1 - Managed update verification

  - Provides a version-only release to verify dashboard-managed plugin updates end to end.

3.10.0 - Dashboard-managed plugin updates

  - Adds a signed dashboard-only preflight and update route for future Ratesight releases.
  - Restricts packages to immutable Ratesight GitHub release assets and verifies their SHA-256 digest before installation.
  - Validates the extracted package root, plugin identity, embedded version, entry count, size, and absence of symbolic links before changing files.
  - Uses WordPress automatic-update maintenance mode, temporary backups, fatal-error checks, and rollback handling.
  - Refuses unpaired sites, downgrades, unsupported hosts, concurrent updates, and unconfirmed apply requests.

3.9.2 - Dashboard-managed identity bootstrap

  - Allows a valid signed dashboard pairing request to set the Ratesight ID when a new installation has no local identity yet.
  - Continues to refuse any attempt to replace a different existing Ratesight ID.
  - Stores the identity only after the signed request, site origin, expiry, replay protection, and authentication mode have passed validation.

3.9.1 - Client accessibility and interaction polish

  - Bases client connection status on dashboard pairing and configured request authentication rather than a legacy ID alone.
  - Labels older performance snapshots as the most recent verified results and shows the verified-through date.
  - Makes dashboard-managed SEO Content view-only inside WordPress and removes technical bulk actions.
  - Announces shortcode copy success and failure to assistive technology and restores keyboard focus after fallback copying.
  - Adds visible color values, explicit control descriptions, and strong focus indicators.
  - Reflows settings controls and shortcode rows cleanly on narrow screens.
  - Marks result collections as semantic lists and makes the widget preview clearly illustrative rather than clickable.
  - Moves the technical plugin version from the client Support page to the unlinked support route.

3.9.0 - Client-facing content and review controls

  - Renames WordPress-facing RS Pages and RS Categories labels to SEO Content and SEO Categories.
  - Adds a live review-widget color preview and clearer review-page and appearance controls.
  - Keeps only the two review shortcodes on the client page and removes the unrelated Jobs shortcode.
  - Moves legacy widget identifiers to an unlinked support route without changing stored values.
  - Separates identity and appearance settings so saving the client page cannot erase connection data.

3.8.0 - Positive performance storytelling

  - Leads with a plain-English 28-day growth result when verified improvement exists.
  - Shows customer outcomes and positive comparison badges without diagnostic states.
  - Groups search wins into Top 3, Page One, Close to Page One, and Biggest Movers.
  - Replaces implementation totals with confirmed gains and developing results.
  - Removes raw states, tracking mechanics, site IDs, credential copy, and duplicate ranking tables.

3.7.0 - Client-facing navigation

  - Makes performance outcomes the default Overview.
  - Reduces the primary menu to Overview, SEO Content, Reviews & Widgets, and Support.
  - Removes duplicate tabs, technical detection chips, raw version chrome, and Agency labeling.
  - Keeps legacy operational pages available only through direct support routes.

3.6.4 - Client-safe connection and notification cleanup

  - Removes obsolete WordPress prompts to connect dashboard-owned providers.
  - Retires client-facing operational failure emails and provider revocation notices.
  - Replaces connection internals with a calm, read-only setup status.

3.6.3 - Client-safe performance wins and search-growth highlights
3.6.2 - Cleaner tracked-ranking states and layout
3.6.1 - Expanded dashboard-owned performance

  - Adds Business Profile, tracked ranking, and completed SEO work summaries
    from the signed dashboard snapshot.
  - Removes the outbound Performance dashboard button.

3.6.0 - Dashboard-managed WordPress pairing

  - Pair or rotate the signed WordPress connection from the Ratesight Dashboard;
    shared credentials are no longer copied between two admin surfaces.
  - The public pairing endpoint accepts only a short-lived ECDSA-signed payload
    bound to the Ratesight ID, canonical HTTPS site origin, and one-time nonce.
  - WordPress no longer displays, generates, copies, or changes the app secret
    or request-auth mode. It reports sanitized pairing and readiness health only.
  - Pairing establishes Observe mode and signed readiness; Enforce remains a
    separate control-plane decision.

3.5.1 - Prevent a critical error before the first dashboard performance snapshot
3.5.0 - Display signed dashboard performance snapshots in WordPress
3.4.4 - Move WordPress Performance to the dashboard-owned Results workspace
3.4.3 - Correct Ratesight product-name casing

  - Use the canonical Ratesight spelling throughout the dashboard-owned
    connection surface.

3.4.2 - Dashboard-owned provider connections

  - WordPress now shows sanitized app/auth health, one dashboard destination,
    and value-free legacy-state presence instead of provider connection,
    disconnection, property/location, or API-key controls. Existing state is
    retained for rollback compatibility.

3.4.1 - Signed dashboard connection inventory

  - NEW GET /wp-json/ratesight/v1/connection-status reports the plugin release,
    request-auth readiness, and value-free booleans for legacy provider residue.
    The endpoint requires rs-hmac-v2 signed-read authorization and never returns
    tokens, keys, provider identities, property URLs, or location selections.

3.4.0 - Two fatal log calls fixed; update-page honours dry_run; media-alt and
        IndexNow REST routes

  - FATAL: `Ratesight_Logger::log()` does not exist and never has (the class
    exposes log_pending / log_update / log_error / get_recent_logs / prune_logs).
    Two call sites invoked it, so calling an undefined static method killed the
    request AFTER the work had already been done: every successful related-links
    write (class-ratesight-related-links.php) and every SEO-field write and DRY
    RUN on POST /page (class-ratesight-page-api.php) returned a 500 for changes
    that had in fact been saved. Both now use the log_pending + log_update pair
    every other write path in this plugin uses.
  - POST /update-page accepted a `dry_run` field and updated the post anyway --
    the same defect DELETE /create-page carried until 3.2.19. A caller that
    believed it was previewing an edit was writing one. It now returns the
    predicted `would_write` field list, the unchanged `content_hash` and the
    builder capabilities without touching the post. The branch sits above the
    pre-update snapshot, which is itself a write. Reported by `update_page_dry_run`
    in /capabilities.
  - NEW POST /wp-json/ratesight/v1/media-alt (signed) sets or clears the alt text
    on one attachment and returns alt_before / alt_after. Alt text was previously
    settable only implicitly, at image-upload time, so existing library images
    could never be corrected. Reported by `media_alt` in /capabilities.
  - NEW POST /wp-json/ratesight/v1/indexnow (signed) submits up to 10 of this
    site's own URLs through the existing Ratesight_IndexNow::submit(), with a
    per-URL result. The submitter has worked for a long time but was reachable
    only from the admin bulk-action UI, with no REST route.
  - /capabilities now reports `indexnow` at the TOP LEVEL as well as under
    provider_ownership. Callers gate on the top-level flag, so the nested-only
    field kept the capability permanently switched off for them.
  - update-page's post_status behaviour is UNCHANGED and is not a defect: it
    never changes post_status by design (Ratesight_Publisher::STATUS_PRESERVE,
    added in 3.2.19 after drafts self-published on a live install). create-page
    honours a requested status and remains the way to publish.

3.4.0 — Related-services links render on theme-builder blog posts
  - The render-time related-links block required `in_the_loop()` and
    `is_main_query()`. A Divi or Elementor Theme Builder POST TEMPLATE renders
    the body from inside the layout, where both are false, so the block bailed
    on every theme-builder blog post and the stored link lists never reached the
    HTML. Measured on a live install (2026-08-26): a post with 6 stored links
    served 0 blocks; 156 source pages holding 464 links were invisible.
  - The guard now decides from the request (`is_singular()` plus the queried
    object) instead of loop state, and still refuses admin, feeds and archives.
    Content belonging to a different post (related-post modules, blog-feed
    modules) is skipped, and the once-per-request flag keeps the block at one
    even when `the_content` runs several times.

3.3.1 — SEO meta writes actually render on Squirrly sites
  - Squirrly SEO support wrote a `_squirrly_seo` post-meta array that Squirrly
    has never read. Every title/description we "stored" on a Squirrly site was
    inert: the store read back clean and the served page never changed.
    Observed on a live install (Squirrly 14.2.3): 6 rewritten posts, 0 of 6
    served. Writes now go to the store Squirrly serves — its own `qss` table
    row for the page's URL hash — plus its documented `_sq_title` /
    `_sq_description` fallback, and the row is re-read to confirm.
  - Where Squirrly runs alongside Yoast or Rank Math, `seo_plugin` now reports
    squirrly. Squirrly output-buffers the finished page and replaces the title
    the other plugin printed, so it is the plugin that decides what is served.
    All active SEO plugins are still written, so nothing goes out of sync.
  - `_squirrly_seo` is still READ (so an older build's value stays visible) but
    is no longer written.

3.3.0 — Secure the WordPress request boundary
  - Add negotiated rs-hmac-v2 authentication with replay protection, secret
    rotation grace, and an explicit route-policy inventory.
  - Keep legacy mode as the upgrade default; enforcement requires a separately
    verified fleet rollout.
  - Redact inbound diagnostics and expose minimal auth/provider ownership
    capability metadata.

3.2.19 — Recoverable page removal + update-page no longer publishes drafts
  - New POST /wp-json/ratesight/v1/trash-page and /restore-page. These use the
    WordPress trash (wp_trash_post / wp_untrash_post), so a removal is always
    reversible. Both require "confirm": true, both honour dry_run, and both
    report status_before / status_after. Signed requests only (a configured
    webhook secret plus a valid X-Ratesight-Signature).
  - DELETE /create-page now honours dry_run instead of accepting the field and
    deleting anyway. It remains a PERMANENT delete — prefer trash-page.
  - POST /update-page NEVER changes post_status. It previously scheduled the
    deferred publish job with no status, so the cron fell back to the site's
    Final Post Status and published any draft it touched a minute or two later
    (writing SEO meta to a draft silently published it). The deferred job is now
    image-attach only on this path, and the response reports status_before /
    status_after / status_preserved. A "status" field in an update-page body is
    ignored and flagged as status_ignored.
  - Capabilities endpoint reports trash_page, restore_page, delete_page_dry_run
    and update_page_preserves_status so integrations can detect this build.

3.2.18 — Constrain the runtime 404 fuzzy router (no cross-city redirects)
  - New per-site "404 Fuzzy Router Mode" setting (Settings > SEO Pages):
    legacy (default, unchanged pre-3.2.18 behavior), same-city-or-hub, off.
  - same-city-or-hub blocks cross-city fuzzy matches (a San Bruno URL can no
    longer land on a San Ramon page), falls back to the base service hub
    (/commercial-movers/ or /office-movers/) for commercial/office city slugs
    when it exists, and serves the plain 404 when nothing safe matches (no
    catch-all). Blocked cross-city matches are logged as type fuzzy-refused.
  - Serve-log rows gain an optional context object (mode, source_city,
    target_city, fallback_reason) so every fuzzy decision is auditable.
  - Capabilities endpoint now reports runtime_404_mode.
  - Pure decision core with a standalone test suite (tests/, php tests/...).

3.2.16 — Make CORS allow-headers bulletproof
  - Also reflect whatever headers the browser's preflight requests into
    Access-Control-Allow-Headers, so no custom header the integration sends can
    block the cross-origin POST. Auth still applies; this only lets the browser
    send the headers.

3.2.15 — Fix: browser (CORS) requests with the signature header were blocked
  - A web app posting create-page cross-origin with X-Ratesight-Signature was
    blocked by the browser's CORS preflight: WordPress's Access-Control-Allow-
    Headers did not include X-Ratesight-Signature, so the browser never sent the
    POST — it never reached WordPress and never logged. Add X-Ratesight-Signature
    to the allowed CORS headers (rest_allowed_cors_headers) so signed browser
    requests are permitted. Server-to-server callers (curl/node) were unaffected.

3.2.14 — Restore blog-post default when post_type is omitted
  - create-page defaulted an omitted post_type to rs_page; the long-standing
    blog-post integration omits post_type and so was silently getting landing
    pages instead of posts. Omitted post_type now maps to a standard blog post
    again. RS landing pages must send post_type: "rs_page" explicitly. Both
    types remain fully supported.

3.2.13 — Add /inbound-log endpoint for self-service request diagnosis
  - GET /wp-json/ratesight/v1/inbound-log returns the last ~25 requests that
    reached WordPress on the ratesight routes, so whether an integration's
    request arrives can be confirmed over the API without server log access.

3.2.12 — Inbound request logging (diagnostic)
  - Log every inbound write (POST/PUT/PATCH/DELETE) to a ratesight/v1 route at
    the WordPress boundary via error_log: method, route, caller IP, content-type,
    body size, and whether the body was valid UTF-8. Makes a request that reaches
    WP always visible even if it fails before the activity log; if the line never
    appears when an integration sends, the request is being blocked before
    WordPress (nginx / WAF / wrong URL), not by the plugin.

3.2.11 — Encoding tolerance + endpoint consistency
  - Repair non-UTF-8 request bodies on all ratesight/v1 routes before WordPress
    rejects them with rest_invalid_json ("Malformed UTF-8 characters"). Windows-
    1252 "smart" quotes/dashes from integrations no longer 400 at the core layer
    (which happened before the plugin ran, so the request never logged).
  - /update-page now accepts content_html (the tool contract) as an alias for
    article, matching /create-page — a content_html update was silently ignored.

3.2.10 — Fix: no-status posts stuck as draft (ignored Final Post Status)
  - create-page defaulted the status to 'draft' when the payload omitted it.
    The deferred publisher only falls back to the Final Post Status / Reference
    Page Status setting when the status is empty, so that default overrode the
    setting and left every no-status post as a permanent draft. Default is now
    empty, so the configured Published status is applied as intended.

3.2.9 — Fix: create-page rejected content_html payloads
  - validate_payload() required "article", but do_handle_request() accepts
    "content_html" (the tool contract) as the body field. Every content_html
    post failed validation with 'Required field "article" is missing' before the
    handler could alias it. Validation now accepts content_html OR article.

3.2.8 — Fix: create-page silently rejected on signature mismatch
  - check_auth hard-failed create-page with 403 (before any activity-log entry)
    when an incoming X-Ratesight-Signature didn't match ratesight_webhook_secret.
    Content/read endpoints now accept the request when LICENSE_ENFORCEMENT is off
    (restoring the prior behaviour). Redirect mutations keep the strict signed
    check (check_auth_signed).

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
