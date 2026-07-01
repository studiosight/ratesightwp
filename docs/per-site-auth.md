# Plugin ↔ Worker authentication (OID-only)

Goal: let every plugin → Worker request be **attributed** to a specific site and
restricted to **known accounts**, with no secret shipped in the plugin, no
per-site key to paste, and no per-customer database on the Worker.

## The model

Each site is identified by its **OID** (the Ratesight ID, stored in the `code_id`
option). The plugin sends the `oid` on every Worker request. The Worker gates on
the OID — no secret exchange, no lookup DB:

- **`REVOKED_OIDS`** — comma-separated denylist. A revoked OID is always rejected.
- **`ALLOWED_OIDS`** — comma-separated allowlist. **Currently disabled** (the check
  is commented out in the Worker), so every non-revoked OID is trusted. Uncomment
  the allowlist lines in `rsVerify` / `rsParseStateAny` and populate `ALLOWED_OIDS`
  to restrict access to known accounts.

Cutting a site off = add its OID to `REVOKED_OIDS`. When you later enable the
allowlist, adding a client = append their OID to `ALLOWED_OIDS`.

### Security posture (be honest about it)

The OID is **public** — it's rendered into the review-widget embed (`?oid=...`).
With the allowlist disabled (current default), the Worker trusts any non-revoked
OID; enabling `ALLOWED_OIDS` narrows that to known accounts. Neither, by itself,
proves the caller *owns* the OID. That is an accepted trade-off here because the
OID alone grants very little:

- **Token refresh** additionally requires the site's `refresh_token`, which lives
  only in that site's WordPress database and is never public.
- **Connecting** GSC/GBP requires a real Google authorization; tokens are returned
  to the `site_url` carried in the OAuth `state`.
- Transport integrity is provided by **TLS**.

If a deployment wants cryptographic proof-of-ownership on top, it can opt into the
**optional shared secret** (below). Most installs don't need it.

## Optional HMAC signing

A site may define `RATESIGHT_STATE_SECRET` / `RATESIGHT_TOKEN_SECRET` in
`wp-config.php` (matching values set on the Worker as `STATE_SECRET` /
`TOKEN_SECRET`). When set, requests are also HMAC-signed and the Worker verifies
them. When unset — the normal case — requests are authenticated by OID only and
the plugin skips response-signature verification (relying on TLS).

There is **no bundled default** for these; nothing secret ships in the repo.

## What the plugin sends

Signing helpers live in `includes/class-ratesight-oauth-client.php`:

- `oid()` → the site's Ratesight ID (the identity presented to the Worker).
- `active_secret()` → the optional shared `TOKEN_SECRET`; `''` in OID-only mode.
- `auth_meta()` → `{ oid }` (always, when an OID is set).
- `sign_request($message)` → `{ hmac, oid }` to merge into a request body/query.
  In OID-only mode `hmac` is computed over an empty key and the Worker ignores it.

### Request shapes

| Endpoint | Method | Signed message | Body / query carries |
|---|---|---|---|
| `/ai-chat` | POST | the `payload` JSON string | `payload, hmac, oid` |
| `/insights` | POST | `JSON.stringify(posts)` | `posts, hmac, oid` |
| `/recommend` | POST | `JSON.stringify(keywords) + "\|recommend"` | `keywords, existing_titles, hmac, oid` |
| `/auto-submit` | POST | `host + "\|" + url` | `host, url, hmac, oid` |
| `/sitemap-status` | GET | `host + "\|check"` | `?host&hmac&oid` |
| `/refresh` | POST | the `refresh_token` | `refresh_token, token_secret_hmac, service, oid` |
| OAuth `state` | redirect | base64url(JSON payload incl. `oid`) | `state = data.sig`, OID inside `data` |

## Worker verification (reference)

The Worker checks the OID allowlist first, then falls back to the (legacy)
per-site derived key or the shared secret for installs that still sign:

```js
async function rsVerify(message, providedHmac, oid, env) {
  if (oid) {
    const revoked = (env.REVOKED_OIDS || "").split(",").map(s => s.trim()).filter(Boolean);
    if (revoked.includes(oid)) return { ok: false, reason: "revoked" };
    const allowed = (env.ALLOWED_OIDS || "").split(",").map(s => s.trim()).filter(Boolean);
    if (allowed.includes(oid)) return { ok: true, oid, mode: "oid" };
    if (env.MASTER_KEY) { /* legacy per-site key = HMAC(MASTER_KEY, oid) */ }
  }
  if (env.TOKEN_SECRET) { /* legacy shared-secret HMAC */ }
  return { ok: false, reason: "bad_hmac" };
}
```

OAuth `state` is verified the same way (`rsParseStateAny`): trust the `oid`
embedded in the state when it's allowlisted, else fall back to the signed paths.

## Worker configuration

| Binding | Required? | Purpose |
|---|---|---|
| `REVOKED_OIDS` | Optional | Comma-separated OIDs to reject. |
| `ALLOWED_OIDS` | Optional (disabled) | Comma-separated OIDs to trust. Only used once the allowlist check is uncommented in the Worker. |
| `STATE_SECRET` / `TOKEN_SECRET` | Optional | Enable the optional HMAC layer. |

## Rollout

1. Deploy the Worker (allowlist disabled — any non-revoked OID is trusted).
2. Deploy the plugin (OID-only; no per-site config on the site).
3. On each site, enter the **Ratesight ID** — it authenticates and GSC/GBP connect.
4. Revoke a site by adding its OID to `REVOKED_OIDS`.
5. Later, to restrict to known accounts: uncomment the allowlist check in the
   Worker and set `ALLOWED_OIDS`.
