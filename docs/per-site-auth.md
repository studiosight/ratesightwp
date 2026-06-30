# Per-site authentication (Option C)

Goal: make every plugin → Worker request **attributable** to a specific site and
**un-spoofable**, without a per-customer database on the Worker.

## Why the OID alone isn't enough

The Ratesight ID (OID, the `code_id` option) is rendered into the public review
widget embed (`?oid=...`), so it is **public**. Anyone can claim any OID. To
prove a request really comes from the owner of an OID, the site must hold a
*secret* paired with that OID — the **Site Key**.

## The scheme: stateless derived keys

- The Worker holds one master secret, `RS_MASTER_KEY` (a Cloudflare secret;
  never shipped in the plugin).
- Each site's key is derived from its OID:

  ```
  siteKey = hex( HMAC_SHA256( RS_MASTER_KEY, oid ) )
  ```

- The customer's Ratesight dashboard shows them their `siteKey` (computed
  server-side from their OID). They paste it into **Widgets → Site Key** once.
- The Worker **re-derives** `siteKey` from the `oid` in each request and verifies
  the HMAC. No per-customer KV/DB lookup is required.

Revocation (optional): keep a small denylist of revoked OIDs in KV. Far smaller
than a full key store. Rotating `RS_MASTER_KEY` invalidates every key at once.

## What the plugin sends

Controlled by `Ratesight_OAuth_Client::PER_SITE_AUTH` (currently `false`). When
`true` **and** a Site Key is set, every request gains an `oid` field and is
signed with the Site Key instead of the shared `TOKEN_SECRET` / `STATE_SECRET`.
When `false`, requests are exactly as before (shared-secret HMAC, no `oid`).

Signing helpers live in `includes/class-ratesight-oauth-client.php`:

- `active_secret()` → Site Key when active, else the shared secret.
- `auth_meta()` → `{ oid }` when active, else `{}`.
- `sign_request($message)` → `{ hmac, oid? }` to merge into a request body/query.

### Request shapes (per-site mode)

| Endpoint | Method | Signed message | Body / query carries |
|---|---|---|---|
| `/ai-chat` | POST | the `payload` JSON string | `payload, hmac, oid` |
| `/insights` | POST | `JSON.stringify(posts)` | `posts, hmac, oid` |
| `/recommend` | POST | `JSON.stringify(keywords) + "\|recommend"` | `keywords, existing_titles, hmac, oid` |
| `/auto-submit` | POST | `host + "\|" + url` | `host, url, hmac, oid` |
| `/sitemap-status` | GET | `host + "\|check"` | `?host&hmac&oid` |
| `/validate` | POST | `ratesight_id + "\|" + site_url` | `ratesight_id, site_url, hmac` (OID = `ratesight_id`) |
| `/refresh` | POST | the `refresh_token` | `refresh_token, token_secret_hmac, service, oid` |
| OAuth `state` | redirect | base64url(JSON payload incl. `oid`) | `state = data.sig`, OID inside `data` |

The Worker also signs responses it sends back (`verify_worker_payload`) and the
inbound GSC sync trigger (`admin: |sync`) with the same derived `siteKey`; the
plugin verifies those with its own Site Key.

## Worker verification (reference)

```js
async function siteKeyFor(oid, env) {
  const mac = await hmacSha256(env.RS_MASTER_KEY, oid);   // raw bytes
  return toHex(mac);
}

async function verify(req, env) {
  const body = await req.json();
  const oid  = body.oid;                                  // for /validate use body.ratesight_id
  if (!oid) return reject('missing oid');
  if (await isRevoked(oid, env)) return reject('revoked');

  const key      = await siteKeyFor(oid, env);
  const expected = await hmacSha256(key, signedMessageFor(req, body));
  if (!timingSafeEqual(toHex(expected), body.hmac)) return reject('bad hmac');

  // authentic + attributed to `oid` → apply per-OID rate limits, then proceed
  return oid;
}
```

`signedMessageFor` must reproduce the exact bytes the plugin signed (see the
table). Note the JSON must match byte-for-byte: the plugin uses
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` to mirror JS `JSON.stringify`.

## Rollout

1. Ship the plugin with `PER_SITE_AUTH = false` (done) — no behavior change.
2. Update the Worker to accept **both** schemes: if `oid` + valid derived-key
   HMAC is present, use it; otherwise fall back to the shared-secret HMAC.
3. Surface each customer's Site Key in the dashboard; have sites paste it.
4. Flip `PER_SITE_AUTH = true` and release a plugin update.
5. Once installs have updated, drop the shared-secret path on the Worker.
