# Related-services links (`ratesight/v1/related-links`)

A **render-time** internal-link block. The plugin stores a per-URL link list in
post meta and appends a `<section data-rs-block="related-services">` to
`the_content` at display time — **the stored builder content is never edited.**

Why render-time and not a `content_append` that mutates `post_content`:

| property | how |
|---|---|
| safe | builder content untouched → no layout damage; safe across re-saves |
| idempotent | upsert by URL; block re-rendered fresh on every page load |
| verifiable | re-fetch the page → the `data-rs-block` section is in the HTML |
| reversible | clear the list (POST `[]` or DELETE) → block disappears |

The block is appended via `the_content` at **priority 20** — after `wpautop`
(10) and most builders/shortcodes — so it lands after the builder output and
isn't wrapped/stripped.

## Auth

Optional `X-Ratesight-Signature: sha256=<hmac>` over the raw request body,
keyed by the `ratesight_webhook_secret` option (same scheme as the webhook
handler). Sent when configured; verified when present.

## Endpoints

### `POST /wp-json/ratesight/v1/related-links`

```jsonc
{
  "url": "https://example.com/services/roof-repair/",
  "links": [
    { "url": "https://example.com/services/gutter-cleaning/", "anchor": "Gutter cleaning" }
  ],
  "confirm": true        // false (default) = dry run, stores nothing
}
```

- Resolves `url` → published post (404 if none).
- Upserts the list onto that post (`confirm: true`). An empty `links` array
  clears it. Max 50 links; entries missing `url` or `anchor` are dropped.
- `confirm: false` returns `{ dry_run: true, would_store: [...] }` and changes
  nothing.

Response: `{ ok, dry_run, post_id, url, stored|would_store, count }`.

### `GET /wp-json/ratesight/v1/related-links?url=…`

Echoes the stored list plus a capability marker:

```json
{ "post_id": 42, "url": "…", "links": [ … ], "count": 1,
  "capabilities": { "related_links": true } }
```

### `DELETE /wp-json/ratesight/v1/related-links?url=…`

Clears the list for that URL's post. Response: `{ ok, post_id, url, cleared: true }`.

## Discovery

`GET /wp-json/ratesight/v1/capabilities` includes `"related_links": true`.

## Storage / rendering

- Post meta key: `_ratesight_related_links` — `array` of `{ url, anchor }`.
- Rendered only on the front-end singular view of the post
  (`is_singular() && in_the_loop() && is_main_query()`), once per request.
- Output is fully escaped (`esc_url`, `esc_html`). Style via the
  `.rs-related-services` / `__title` / `__list` / `__item` classes.
