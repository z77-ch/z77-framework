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
  session, no locale/navigation logic, no page cache. No cookie on the machine
  channel; the device channel (below, added 2026-11-12) authenticates with one
  HttpOnly cookie instead of a bearer key.
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

## Device channel (added 2026-11-12, ADR-057; sign-in ADR-058)

Additive — nothing above changes for a machine client. Decision and reasoning:
[ADR-057](../02-decisions/adr-057-api-device-channel.md), the code sign-in of app users
[ADR-058](../02-decisions/adr-058-app-users-email-code-login.md).

- **Credential:** cookie `z77_api_device` (HttpOnly, Secure on HTTPS,
  SameSite=Strict, `Path=/api`). Only when no `Authorization: Bearer` is sent —
  a bearer always wins, and a bearer that fails is a 401 without trying the cookie.
- **Mandatory on every cookie request:** `X-Z77-App: 1`. An `Origin` header, when
  sent, must name the installation's own host (`host[:port]`); a POST must send
  one. Violations: 403 `forbidden`.
- **Sign-in (ADR-058)** — the only two calls without a credential; same header/Origin
  rule; a device cookie the browser still carries does not make them a device call:
  - `POST /api/v1/devices/login` with `{"email": "…"}` (JSON or form field): **always
    `202 Accepted`** with the body `{"status": "accepted"}` and `Set-Cookie:
    z77_api_login=<nonce>` (HttpOnly, SameSite=Strict, `Path=/api`, `Max-Age=900`) — for
    an unknown, an inactive and an active address alike. 202 means «taken»: whether a
    code goes out by mail is NOT said (no oracle; a mail failure is not answered
    either). A missing address is 400 `bad_request`; more than 10 requests per source
    /64 and hour 429 `throttled`.
  - `POST /api/v1/devices/verify` with `{"code": "123456"}` plus the nonce cookie: 200
    answers `{"device": {id, label, owner, scopes}}` and sets the device cookie; EVERY
    refusal (no nonce, wrong, expired, used up, unknown) is the same 403 `forbidden`
    («Code invalid or expired.») with no count of tries left; a missing code 400; more
    than 20 per source /64 and hour 429.
- **Pairing removed (2026-11-12, ADR-058):** `POST /api/v1/devices/pair` no longer
  exists (401 like any keyless call). Removed before any client depended on it — the
  device channel is not part of the frozen surface yet.
- **`GET /api/v1/devices/me`:** the calling device — `{"device": {id, label, owner, scopes}}`;
  `owner` is the linked person's name, `scopes` those the app user holds right now.
- **`POST /api/v1/devices/logout`:** a device call (with `Idempotency-Key`): revokes the
  calling device and answers 200 `{"device": null}` with a `Set-Cookie` that clears
  `z77_api_device` (`Max-Age=0`).
- **Scopes:** a device reaches only endpoints that declare a scope for the method
  and only with that scope (403 `forbidden` otherwise); endpoints without a
  declaration stay machine-only.
- **POST:** accepted only on endpoints that declare it — JSON body or multipart
  (form fields + files). Every other POST answers 405 as before.
- **Idempotency:** a device POST MUST send `Idempotency-Key` (8–128 of
  `[A-Za-z0-9_-]`, a UUID fits), else 400 `bad_request`; a machine POST MAY. Within
  24 h the same key + the same request returns the first answer without running
  again; the same key with a different request is 409 `idempotency_conflict`. A
  5xx is not kept — retrying it runs again.
- **Revocation:** a revoked device gets 401 `unauthorized` on its next request,
  like an unknown cookie (no oracle) — also when its app user was deactivated or the
  linked person deleted, and for a device row from the pairing era.
- **Module targets** (payload contracts per service, not part of this page):
  `receipt-templates`, `payment-methods` (GET) and `receipts` (POST, 422
  `invalid_fields`) of module-receipt — [`topics/receipt.md`](../topics/receipt.md).
  The app shell under `/app` that calls them: [`topics/api.md`](../topics/api.md).

## Status codes

| Code | When | Body |
|---|---|---|
| 200 | success, payload delivered | service payload (JSON) |
| 202 | accepted, the effect happens elsewhere (added 2026-11-12, ADR-058: `POST /api/v1/devices/login`, answered for every address alike) | service payload (JSON) |
| 304 | conditional GET, content unchanged (see revalidation) | empty |
| 400 | malformed request: missing/invalid `Idempotency-Key`, missing address or code of the sign-in (device channel, added 2026-11-12) | error body |
| 401 | missing/unparseable/unknown/revoked key (no oracle which) | error body + `WWW-Authenticate: Bearer` |
| 403 | valid key, but endpoint/dataset not allowed for this tenant; device channel: scope missing, endpoint not open to devices, `X-Z77-App`/`Origin` rule violated, sign-in code refused (one answer for every reason) | error body |
| 404 | unknown version, endpoint, or dataset | error body |
| 405 | method not allowed on this endpoint | error body |
| 409 | `Idempotency-Key` reused for a different request (added 2026-11-12) | error body |
| 422 | the request was well-formed but a service refused its VALUES (added 2026-11-12, device channel: `POST /api/v1/receipts`) | error body + `error.fields` |
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
  `method_not_allowed`, `throttled`, `snapshot_pending`, `internal`;
  added 2026-11-12 (device channel): `bad_request`, `idempotency_conflict`,
  `invalid_fields` (422).
  New codes may be added; existing codes never change meaning.
- `message` is human-readable English, not frozen, never parsed by clients.
- **`fields`** (added 2026-11-12) — only on 422 `invalid_fields`: an object
  field name → message for the person who typed the value (German in this
  framework; the client shows it under the field). Every other error body keeps
  exactly `code` and `message`; a resend under the same `Idempotency-Key` replays
  it with its `fields`:

  ```json
  {"error": {"code": "invalid_fields", "message": "Receipt refused, see fields.",
             "fields": {"amount": "Der Betrag fehlt.", "photo": "Das Foto fehlt."}}}
  ```
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
| `/api/v1/devices/login` | POST | device channel: request a sign-in code for an app e-mail — always 202 + nonce cookie (added 2026-11-12, ADR-058) |
| `/api/v1/devices/verify` | POST | device channel: the code + nonce → the device cookie (added 2026-11-12, ADR-058) |
| `/api/v1/devices/me` | GET/HEAD | device channel: the calling device's label, owner, scopes (added 2026-11-12) |
| `/api/v1/devices/logout` | POST | device channel: revoke the calling device, clear its cookie (added 2026-11-12, ADR-058) |
| ~~`/api/v1/devices/pair`~~ | — | removed 2026-11-12 (ADR-058) |

## Request log

One line per request, module-owned, `logs/api.log`:
timestamp, principal (or `-` on 401), method, path, status, duration ms.
The principal is the tenant, or `tenant:keyRef` when the installation's
resolver names the calling connection (`ApiPrincipal`), or `device:<id>` on the
device channel — with two keys on
one tenant, WHICH one called is exactly the question a rotation asks.
A stretch of failures must be visible, not silent (GeoGuard logging
philosophy). Log rotation follows the framework's existing log handling.

## see also

- [`api-module-review-2026-09-02.md`](api-module-review-2026-09-02.md) — design review + critical findings
- [`api-module-handoff-2026-09-02.md`](api-module-handoff-2026-09-02.md) — original handoff from the zihlundsee session
