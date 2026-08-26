# Page lifecycle endpoints (3.2.19+)

Two recoverable removal verbs, plus the rule that `update-page` never changes a post's status.

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

Note: WordPress renames a trashed post's slug to `<slug>__trashed`, so restore-by-slug usually
fails. Keep the `id` returned by `trash-page` and restore by id.

## Auth

Both endpoints use `check_auth_signed`: a configured webhook secret AND a valid
`X-Ratesight-Signature: sha256=<hmac_sha256(body, secret)>` header. A site with no secret refuses
them outright. This matches the redirect-mutation endpoints, not the IP-allowlisted create flow.

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
