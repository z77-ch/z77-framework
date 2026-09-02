# API envelope v1 — transport contract of the framework api module

**Date:** 2026-09-02
**Status:** draft for freeze. Once the zihlundsee pilot runs against it, this
page is FROZEN — additions allowed, changes and removals are v2.
**Scope:** the transport layer only (auth, status codes, headers, error shape,
versioning). Payload contracts (e.g. the units bundle) are per-service and
documented separately.

---

## Base

- All endpoints live under `/api/v1/…` — a reserved route, stateless: no
  session, no cookie, no locale/navigation logic, no page cache.
- Requests and responses are JSON, `Content-Type: application/json; charset=utf-8`.
- Every response carries `Cache-Control: no-store` (per-tenant payloads behind
  one URL must never be cached by an intermediary).
- `HEAD` is accepted wherever `GET` is and returns the same headers with no
  body (monitoring tools HEAD health endpoints).
- Query parameters (e.g. `lang`, dataset selectors) are passed through to the
  service untouched; the envelope does not interpret them.

## Authentication

- `Authorization: Bearer <key>` on every request, including `/api/v1/health`.
- Keys: random ≥32 bytes, issued per tenant, stored hashed (SHA-256),
  compared with `hash_equals`. Plaintext visible once at creation.
- **Rotation:** up to two active keys per tenant; the resolver accepts both;
  the backend UI shows last-used per key. Rotate = create second key, switch
  the site, revoke the first.
- Server prerequisite (axo3): Apache CGI/FastCGI strips `HTTP_AUTHORIZATION`;
  the pass-through rule ships with the module docs:
  `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`.
  A keyed `GET /api/v1/health` doubles as the transport self-test.

## Status codes

| Code | When | Body |
|---|---|---|
| 200 | success, payload delivered | service payload (JSON) |
| 304 | conditional GET, content unchanged (see revalidation) | empty |
| 401 | missing/unparseable/unknown/revoked key (no oracle which) | error body + `WWW-Authenticate: Bearer` |
| 403 | valid key, but endpoint/dataset not allowed for this tenant | error body |
| 404 | unknown version, endpoint, or dataset | error body |
| 405 | method not allowed on this endpoint | error body |
| 429 | rate limit exceeded | error body + `Retry-After: <seconds>` |
| 500 | service/upstream failure not covered above | error body (never a stack trace, never HTML) |
| 503 | axo3-side snapshot not yet built for this tenant (fresh setup) | error body + `Retry-After` |

## Revalidation (conditional GET)

Standard HTTP conditional requests, using the payload's `curatedHash` as ETag:

```text
GET /api/v1/units
If-None-Match: "<curatedHash the site currently holds>"

→ unchanged: 304, empty body
→ changed:   200, new bundle, ETag: "<new curatedHash>"
```

Note: this deviates from the handoff's «204 on unchanged». 304 + If-None-Match
is the HTTP-correct form for «your copy is still current», tooling understands
it natively, and `Request::getIfNoneMatch()` already exists framework-side.
The site-internal 204 convention (fetch.md) is about browser fetch endpoints
and is not this contract.

## Error body

Every non-2xx/304 response has exactly this shape:

```json
{"error": {"code": "throttled", "message": "Rate limit exceeded, retry later."}}
```

- `code` is machine-readable and FROZEN: `unauthorized`, `forbidden`,
  `unknown_version`, `unknown_endpoint`, `unknown_dataset`,
  `method_not_allowed`, `throttled`, `snapshot_pending`, `internal`.
  New codes may be added; existing codes never change meaning.
- `message` is human-readable English, not frozen, never parsed by clients.
- Services report failures through `ApiResult` error variants (or a typed
  `ApiException`); the api module maps them to code + status. Services never
  touch `header()` or emit output.

## Versioning

- `/api/v1` never breaks after freeze: fields may be ADDED to payloads,
  never renamed, retyped, or removed. Anything else is `/api/v2`.
- A version that does not exist → 404 `unknown_version`.
- Clients MUST ignore unknown fields (allows additive evolution).

## Endpoints (v1 initial)

| Endpoint | Method | Purpose |
|---|---|---|
| `/api/v1/health` | GET/HEAD | keyed liveness + transport self-test; `{"status": "ok"}` |
| `/api/v1/units` | GET/HEAD | tenant's curated units bundle (all languages in one bundle); conditional GET via ETag |

## Request log

One line per request, module-owned, `logs/api.log`:
timestamp, principal (or `-` on 401), method, path, status, duration ms.
The principal is the tenant, or `tenant:keyRef` when the installation's
resolver names the calling connection (`ApiPrincipal`) — with two keys on
one tenant, WHICH one called is exactly the question a rotation asks.
A stretch of failures must be visible, not silent (GeoGuard logging
philosophy). Log rotation follows the framework's existing log handling.

## see also

- [`api-module-review-2026-09-02.md`](api-module-review-2026-09-02.md) — design review + critical findings
- [`api-module-handoff-2026-09-02.md`](api-module-handoff-2026-09-02.md) — original handoff from the zihlundsee session
