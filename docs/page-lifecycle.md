# Page lifecycle endpoints (3.2.19+)

Two recoverable removal verbs, the rule that `update-page` never changes a post's status, and
(3.15.7) the rs_pages visibility hold for hosting-only clients.

## Why

Before 3.2.19 the only removal verb was `DELETE /wp-json/ratesight/v1/create-page`, which calls
`wp_delete_post( $id, true )` — a permanent delete that bypasses the WordPress trash and cannot be
undone. An integrator with 100+ dead zero-click pages had no reversible option, so the safe choice
was to do nothing and leave them live.

## POST /wp-json/ratesight/v1/trash-page

Moves a post to the WordPress trash (`wp_trash_post`). Always reversible.

```json
{ "id": 111421, "confirm": true }
{ "url": "https://example.com/dead-page/", "confirm": true }
{ "slug": "dead-page", "confirm": true, "dry_run": true }
```

- Selector precedence: `id` > `url` > `slug`.
- `confirm: true` is REQUIRED for a real write. Without it: `428`, nothing written.
- `dry_run: true` resolves the post and returns the predicted `status_after` without writing. A dry
  run does not require `confirm` — it cannot change anything.
- Already in the trash: `200` with `already_trash: true` (idempotent, no error).

Response:

```json
{ "ok": true, "trashed": true, "dry_run": false, "id": 111421,
  "title": "…", "status_before": "publish", "status_after": "trash", "recoverable": true }
```

## POST /wp-json/ratesight/v1/restore-page

Restores a trashed post to the status it held before trashing (`wp_untrash_post`). Same body, same
`confirm` / `dry_run` rules. A dry run reports the status it would be restored to, read from
`_wp_trash_meta_status`.

Since WordPress 5.6 `wp_untrash_post()` restores to `draft` unless the `wp_untrash_post_status`
filter says otherwise. Before 3.15.7 this route therefore brought a published page back as a draft
(the dry run predicted `publish`, the real call returned `draft`). From 3.15.7 the handler adds a
`wp_untrash_post_status` filter scoped to the one post and the one call, returning the recorded
`_wp_trash_meta_status`, so the post comes back as `publish`, `private` and so on. With no recorded
status the core default (`draft`) still applies.

Also from 3.15.7, `trash-page` and `restore-page` pin the stored title, content, excerpt and
content_filtered for their status write. Core re-saves the whole row on trash and untrash, and a
signed REST request has no user with `unfiltered_html`, so stored markup (iframes, inline JSON-LD
scripts) was passed through kses again and could be stripped, with a new revision written.

Note: WordPress renames a trashed post's slug to `<slug>__trashed`, so restore-by-slug usually
fails. Keep the `id` returned by `trash-page` and restore by id.

## POST /wp-json/ratesight/v1/unpublish-rs-pages (3.15.7)

Takes Ratesight-generated pages out of public display and out of `sitemap-rs-pages.xml` by setting
them to `draft`, for a client whose status is `hosting_only` (hosting kept, SEO and publishing off).
Content, meta, revisions and slugs are preserved; the change is reversible with
`republish-rs-pages`.

```json
{ "reason": "hosting_only", "dry_run": true }
{ "reason": "hosting_only", "confirm": true }
{ "reason": "hosting_only", "ids": [111421, 111422], "confirm": true }
```

- Exactly one of `dry_run: true` or `confirm: true`. Neither: `428`. Both: `422`. Nothing is written
  on a refusal or a dry run.
- `reason` is required and must be `hosting_only` (`422` otherwise).
- `ids` omitted means every `ratesight_page` with post_status `publish`, at most 200 per call:
  `has_more: true` means call again. When sent, `ids` must be a non-empty array of positive integer
  ids (at most 200); an empty array is a `422`, never "all".
- One real run at a time per site: a run lock (option `ratesight_rs_pages_visibility_lock`, taken
  with `add_option`, expires after 300 s) makes a concurrent unpublish or republish run get `409`
  with `code: "rs_pages_visibility_busy"`. Dry runs take no lock.
- Only post_type `ratesight_page` is ever changed. Any other id (a client page or post, an
  attachment, an id that does not exist) is refused per item with `not_ratesight_page`, and its slug
  and status are not reported. Only status `publish` is changed; a `ratesight_page` in any other
  status is reported as `skipped`.
- The write is `wp_update_post( { ID, post_status: "draft" } )`. For that one write the stored title,
  content, excerpt, content_filtered and slug are pinned to their database values, so kses and slug
  de-duplication cannot alter them. Only post_status and post_modified change. No new revision is
  written because the content is unchanged; existing revisions are kept.
- Before the status write the plugin adds post meta `_ratesight_visibility_hold`, a JSON string
  `{"prior_status":"publish","reason":"hosting_only","at":"2026-10-13T17:05:47Z"}` (ISO 8601 UTC).
  An existing hold is never overwritten, so repeating the call cannot lose the prior status.
- A page is never set to draft without a readable hold. If the hold cannot be written, cannot be
  read back, or an existing hold is unreadable, the item reports `failed` and its status is not
  touched. If the status write fails, a hold added by this call is removed and the item is `failed`.
- After each changed page the plugin runs the same page-cache purge set as `update-page`
  (`clean_post_cache`, WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, Elementor files,
  `flush_post_cache`).
- Each real change appends `{action:"unpublish", post_id, slug, from, to, reason, at}` to option
  `ratesight_rs_pages_visibility_log` (not autoloaded, append-only, newest 1000 kept), one entry per
  page as it is changed, so a timeout cannot lose the entries of pages already changed. Skipped and
  refused items are not logged.
- Idempotent: a page already unpublished is `skipped`, the hold and log are untouched.

Response (`200` whenever the request itself was valid):

```json
{ "ok": true, "dry_run": false, "reason": "hosting_only", "count": 1,
  "pages": [
    { "id": 111421, "slug": "botox-chula-vista", "from": "publish", "to": "draft", "result": "unpublished" },
    { "id": 42, "slug": null, "from": null, "to": null, "result": "not_ratesight_page" },
    { "id": 111430, "slug": "old-draft", "from": "draft", "to": "draft", "result": "skipped" }
  ] }
```

`result` is one of `unpublished` (confirm), `would_unpublish` (dry run), `skipped`,
`not_ratesight_page` or `failed`. `count` is the number of pages changed (or that would change on a
dry run). `ok` is false only when at least one item `failed`. Every response also carries
`has_more`, and unpublish responses carry `pending_publish_jobs`: the number of pending Ratesight
log rows (deferred publish jobs) for the affected pages.

## POST /wp-json/ratesight/v1/republish-rs-pages (3.15.7)

Reverses `unpublish-rs-pages`.

```json
{ "dry_run": true }
{ "confirm": true }
{ "ids": [111421], "confirm": true }
```

- Same `dry_run` / `confirm` and `ids` rules. `reason` is optional and only echoed.
- `ids` omitted means every `ratesight_page` that is `draft` AND carries `_ratesight_visibility_hold`.
- Only a `ratesight_page` that is currently `draft` and carries a valid hold is changed. It returns
  to the hold's `prior_status` (only `publish` is accepted), with the same pinned status-only write,
  and the hold meta is deleted. An ordinary draft with no hold is `skipped`, so this route can never
  publish a page that was a draft for any other reason. Other post types: `not_ratesight_page`.
- Each real change appends `{action:"republish", post_id, slug, from, to, reason, at}` to the same
  log, with the reason recorded in the hold.
- Same response shape, batching (200, `has_more`), lock and cache purge; `result` is
  `republished`, `would_republish`, `skipped`, `not_ratesight_page` or `failed`.

## A hold is bound to the draft these routes made

- `transition_post_status` (priority 5): when a held `ratesight_page` changes to any status other
  than `draft` outside these two routes (an admin publish, private, trash, another plugin), the hold
  is deleted and `{action:"hold_cleared", post_id, slug, from, to, reason, at}` is logged. The post
  itself is left as it is. So an admin who publishes a held page and later sets it back to draft
  has an ordinary draft, which `republish-rs-pages` will not publish.
- Other publish paths respect a hold: the hourly retry of stuck deferred publishes
  (`Ratesight::retry_pending_posts`) never publishes a held page and moves its log row out of
  `pending` (status `modified`), and the deferred publisher (`Ratesight_Publisher::run`) leaves a
  held page untouched (no image attach, no status change).

## What "out of public display" means

A held page is an ordinary WordPress draft, so:

- Sitemap: `sitemap-rs-pages.xml` only lists `post_status = publish`
  (`includes/class-ratesight-sitemap.php:75-81`, query at `:77`), and the sitemap is dropped from the
  SEO-plugin indexes and robots.txt when no published page is left (`has_published_pages()`,
  `:267-269`; also the queries at `:199` and `:275`).
- Routing: the plugin's own slug router and its 404 fallback resolve only published
  `ratesight_page` posts (`includes/class-ratesight-cpt.php:180`, `:184` and `:207`). WordPress core
  does not serve a draft to anonymous visitors, so the old URL is a 404.
- The runtime 404 router (`Ratesight_Runtime_404_Router::maybe_route`) returns early when the
  requested slug belongs to a held `ratesight_page`, so the old URL is a plain 404 and is never
  fuzzy-301'd to another page, including the client's own pages. Decision (integrator, 2026-10-13):
  a hosting-only client gets no SEO redirection from us, and a cached 301 would outlive a republish.
  The router's index holds published posts only and is rebuilt on every status change.

## Auth

All of these endpoints use the centralized request-auth mutation policy (`signed_mutation` in
`Ratesight_Request_Auth::ROUTE_POLICIES`): negotiated rs-hmac-v2 verification with replay
protection, or a valid legacy body HMAC while the site is not in `enforce_v2`. A site with no
webhook secret refuses them outright.

## DELETE /create-page

Still a PERMANENT delete. As of 3.2.19 it honours `dry_run: true` (it previously accepted the field
and deleted anyway). Prefer `trash-page`.

## update-page never publishes

`POST /update-page` is an EDIT verb. Before 3.2.19 it scheduled the deferred publish cron job with
no status, so the job fell back to the site's configured Final Post Status and flipped any DRAFT it
touched to `publish` a minute or two later — writing SEO meta to a draft silently published it.

The deferred job on this path is now image-attach only. Responses carry `status_before`,
`status_after` and `status_preserved: true`; a `status` field in the body is ignored and reported as
`status_ignored: true`. To change a status, use `create-page` (upsert by slug) or WP Admin.

## Capability detection

`GET /wp-json/ratesight/v1/capabilities` reports `trash_page`, `restore_page`,
`delete_page_dry_run` and `update_page_preserves_status`. Their absence means the site is on
3.2.18 or older and has neither the recoverable verbs nor the status-preservation fix.

From 3.15.7 `capabilities` also reports `rsPagesVisibility: true` (top level, and mirrored as
`features.rsPagesVisibility`), and the signed `GET /connection-status` reports
`features.rsPagesVisibility: true`. Absent means the site cannot hold rs_pages out of public display;
callers must not fall back to `trash-page` for that, because trashing renames the slug.
