# ADR-057 — The device channel of the API gateway

**Status:** `[APPROVED]` — owner decision 2026-11-12 (technical proposals T1, T3, T5 of the
[gateway plan](../03-development/gateway-app-kanal-bauplan.md), section A5); built 2026-11-12.
**Date:** 2026-11-12
**Extends:** the API envelope v1 ([`api-envelope-v1-2026-09-02.md`](../03-development/api-envelope-v1-2026-09-02.md))
— additively; v1 stays valid for every machine client.
**Builds on:** [`topics/api.md`](../topics/api.md) (gateway, `ApiPrincipal`, `ApiResponder`),
the device-key pattern of module-member (`DeviceKeys` / `DeviceCookie`).

---

## Context

The API gateway (`module-api`) knew one caller: a server with a bearer key (machine channel,
AXO3 units). The owner wants a phone to capture receipts (first target, `module-receipt`)
and later other targets (`content.edit`, `mail.send`) — as a PWA, without an app store, paired
once and then without login, revocable per device (plan, Owner-Vorgaben 1–4, E0, E8).

A bearer key in a browser is the wrong credential: whatever `localStorage` or IndexedDB holds
is readable by every script on the page (Key Rule 7). A session is the wrong mechanism: the
API path is stateless by design (no session, no AuthService, no CSRF token — `bootstrap.md`).
Open questions 1, 2 and 7 of the plan asked how a target learns who captured, where devices
live, and what the envelope says about cookies.

## Decision

1. **A second credential in the same gateway.** `ApiKeyGuard` becomes a chain: `Authorization:
   Bearer` → the tenant resolver (unchanged; a bearer that fails is a 401, the cookie is not
   tried); else the device cookie → the device resolver; else 401. Both channels end in the
   same `GatewayController`, the same services, the same envelope and log.
2. **The device cookie** `z77_api_device`: HttpOnly, Secure (on HTTPS — the session cookie's
   rule; production is HTTPS-only), **SameSite=Strict, Path `/api`**, Max-Age 400 days (the
   browsers' cap). Value `id.secret` with 32 random bytes; the server stores only
   `sha256(secret)` and compares with `hash_equals`. A device unused for 180 days is dead
   (rolling validity); `last_used_at` is written at most once per hour.
3. **CSRF on the cookie path** (the API stays stateless): every cookie request — and the
   pairing call — must carry `X-Z77-App: 1`; an `Origin`, where sent, must name this host
   (scheme-less `host[:port]` against the `Host` header); a POST must send one. Otherwise 403
   `forbidden`. A foreign form cannot set a header; a foreign script that sets one triggers a
   CORS preflight the API never answers; SameSite=Strict keeps the cookie off cross-site
   requests in the first place. A same-origin GET carries no `Origin` and is accepted.
4. **Pairing — the one anonymous call.** In the backend the owner calls
   `ApiDevices::createPairingCode(owner, label, scopes)`: a one-time code (`ABCDE-FGHJK`,
   31 symbols × 10 ≈ 49 bits), shown as text/QR, stored only as its SHA-256 under
   `var/lib/api/pairing`, valid 10 minutes. The phone redeems it with `POST
   /api/v1/devices/pair {code}`; the guard lets exactly this call through without a
   credential (header + Origin rule still apply), the service throttles it (10 attempts per
   source /64 and hour), claims the code by an atomic rename (single use, also under a race)
   and answers with the device data and `Set-Cookie`. `GET /api/v1/devices/me` tells the app
   shell its label, owner and scopes.
5. **Devices live in `module-api`** (T3, open question 2): the gateway owns the credentials.
   File entity `ApiDevice`, `framework/api/devices.json` (collection mode — few per
   installation): `id, owner_id, label, scopes, key_hash, created_at, last_used_at,
   revoked_at`. Every device belongs to a backend user (E8); a device dies with its owner.
   Revoked, never deleted — the next request is a 401, the row stays for the list and for
   «captured by». At most 10 live devices per owner (the least recently used is revoked).
   The resolver is declared as config key `apiDeviceResolver` (module-api's config ships
   it); without it the channel is off and the cookie is ignored.
6. **Scopes per device, narrow, per method per target.** A device carries a list
   (`receipt.capture`, `content.edit`, `mail.send` — `area.verb`), independent of its owner's
   roles; no scope opens the backend. A target opens itself to devices and to POST by
   implementing `ScopedApiServiceInterface::scopeFor(string $method, array $slugs): ?string`
   — the scope a device needs, `NO_SCOPE` (`''`, any paired device), or null = method not
   offered (405). A plain `ApiServiceInterface` stays GET/HEAD and closed to devices (403).
   Machine principals keep today's rule (past the guard the service decides by tenant).
7. **The principal is data, not an identity to act as** (T1, open question 1).
   `ApiPrincipal` gains optional `deviceId`, `ownerId`, `ownerName`, `scopes`
   (`ApiPrincipal::device()`; tenant column `device`); `ApiRequest` gains `method`, `body`
   (form fields, else the JSON body), `files` (`UploadedFile` per field), `principal`,
   `idempotencyKey` — named arguments with neutral defaults, positional callers unchanged.
   No «run as user», no AuthService on the stateless path: the receipt target records who
   captured; booking runs later in the backend under the owner's session (E1).
8. **Idempotency for writing requests.** A device POST must send `Idempotency-Key`
   (8–128 of `[A-Za-z0-9_-]`, a UUID fits; 400 `bad_request` otherwise); a machine POST may.
   The gateway keeps key → fingerprint (method, path, body, each file's name/size/sha256) →
   result for 24 h under `var/lib/api/idempotency`, scoped to the principal, and locks the
   key while the service runs: a resend gets the first answer without a second execution; the
   same key with another request is 409 `idempotency_conflict`. A 5xx is not kept.
9. **Envelope v1, additively** (T5, open question 7): new error codes `bad_request` (400) and
   `idempotency_conflict` (409); POST on scoped endpoints; the device section in the envelope
   doc. Every POST that does not reach a scoped service still answers 405, as all POSTs did —
   also on an unknown version or endpoint — so nothing changes for a machine client.

## Reasoning

- **One gateway, two credentials** keeps one place for rate limit, method policy, envelope,
  log and error hiding (plan A1/A2, Owner-Vorgabe 2). A second gateway would duplicate all of
  it and drift.
- **HttpOnly cookie instead of a stored key** is the only browser credential no script can
  read — Key Rule 7. SameSite=Strict + Path `/api` keep it off every other request of the
  site; the mandatory header and the Origin check close what SameSite leaves to the browser.
- **Scopes declared by the service, per method**, keep the gateway free of endpoint knowledge
  (rule in `topics/api.md`) and make «what may a phone do» readable at the target. An optional
  interface instead of an attribute: the services are created with `new` and already typed by
  an interface; `instanceof` is the simplest check that exists, and a method can answer per
  sub-path (`devices/pair` vs `devices/me`), which a class attribute cannot.
- **Pairing anonymous but fenced**: a device without a cookie must get one somehow. Fencing
  it to one path, one method, the header/Origin rule, a short-lived single-use code and a
  per-/64 throttle keeps «first who, then what» true for everything else.
- **File storage** (`devices.json`, like `backendUsers.json`): a handful of rows per
  installation, read on every device request — the collection store's column merge
  (DATA-MERGE-001) keeps a revoke and a parallel `last_used_at` write from undoing each other.
- **`var/lib` for pairing codes and idempotency records**: both are disposable by definition
  (ADR-034/035) — wiping them costs a new code or a theoretically repeatable resend within a day.

## Consequences

- A project that overrides `apiConfig` must carry `apiDeviceResolver` to keep the device
  channel (the override replaces the package file); leaving it out switches the channel off.
- A project that declared its own endpoint `devices` must rename it (now built in).
- A target writing on behalf of a person checks `$request->device()` and refuses a machine
  caller itself — a bearer key passes the gateway's scope check by design.
- `DevicesService` is framework-internal and sets `Set-Cookie` through
  `ApiResult::payload(…, $headers)` — the one service header not named `X-<Project>-*`.
- Kernel `Request` gains `getCookie()`, `getOrigin()`, `isAppRequest()`,
  `getIdempotencyKey()`, `isHttps()`, `getAllUploadedFiles()` (Key Rule 4: nothing outside
  `Request` reads `$_COOKIE` / `$_SERVER` for this).
- Still to build: the backend screen «Geräte» (pair via QR/code, scopes, revoke — after the
  owner has seen a board), the app shell under `/app` with its enforced CSP (T6), cookie
  refresh before the 400 days run out.
- Tested by `tests/api-device-channel.php`; the machine channel's harnesses
  (`api-stateless-guard.php`, `api-contract-throttle.php`) stay green unchanged.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Bearer key in `localStorage` / IndexedDB | readable by every script on the page — Key Rule 7 |
| Mobile backend page with a session (no gateway) | E0: the gateway is the foundation; a session on the phone would need login, CSRF token and AuthService on a path built without them |
| Separate gateway / subdomain for devices | duplicates envelope, throttle, log; Owner-Vorgabe 2 and 6 |
| Device management in the kernel (`shared/Auth`) | the gateway owns its credentials; the kernel only knows the resolver interface |
| Reusing module-member's `DeviceKeys` class | bound to `MemberAccount`; the pattern is reused, the class is not |
| Scope as a PHP attribute on the service class | cannot differ per sub-path or method without a second mechanism; `instanceof` + one method is simpler |
| SameSite=Strict alone as CSRF protection | depends on the browser alone; the header + Origin check costs nothing and holds where SameSite does not |

---

## Addendum 2026-11-12 — the app shell `/app` and the first targets (wave 2, part E)

Built on the approved board «z77 App» (developer canvas, row «Belege per Handy») and plan T6.
Not a new owner decision — the placement below is a technical one, recorded here because it
fixes where every later phone screen goes.

1. **A generic shell in module-api, screens from the modules.** module-api owns `/app` (start,
   pairing, manifest, service worker, `_Z77.app`): pairing and «what may this device do» are the
   gateway's knowledge. A module that owns a target contributes its screen through config,
   `'appScreens' => ['receipt' => ['scope', 'title', 'template', 'js']]` (`AppScreens`, merged like
   `apiServices`), rendered inside the start page and shown when the device holds the scope.
   Rejected: everything in module-receipt (the next target — `content.edit`, `mail.send` — would
   need a second app, a second manifest and a second pairing page; Key Rule 9); a screen per
   route under `/app/<screen>` (a tile tap must open the camera in the same user gesture, so the
   receipt views share one page). module-receipt still never names module-api.
2. **The pages are not stateless and carry no device.** The cookie stays `Path=/api` (decision 2):
   widening it to `/app` would hand a credential to page requests that do not need it. The pages
   are server-rendered frames; the client asks `devices/me` and the targets, and builds the lists
   with `textContent`.
3. **Enforced CSP for `/app` only**, set by the action before the answer — the mechanism
   `SecurityHeaders::send()` already offers (a header the action set stays). The global page CSP
   keeps observing. The shell is built for it: no inline script or style, inline SVG sprites, the
   photo preview a `<canvas>`.
4. **The service worker is served by the controller** (`/app/sw.js`, `AppScriptResponse`): a
   worker's scope is the path it is served from, and `bytes()` / `file()` deliberately turn any
   script into `text/plain` (DeliveryPolicy). The response carries only the framework's own asset.
5. **The offline queue lives in the page** (IndexedDB), not in the worker: no Background Sync on
   iOS. An entry is stored before sending and removed on the server's 2xx; its `Idempotency-Key`
   is kept for every resend and becomes the receipt's capture key, so a resend after the
   gateway's 24 h still finds the first receipt (E7).
6. **Envelope v1, additively:** 422 `invalid_fields` with `error.fields` (field → German message)
   for a refusal of values (`ApiResult::invalidFields()`); the idempotency store replays it with
   its fields. `ApiPrincipal` gains `deviceLabel` (E8: the journal shows the device's name).
7. **Without JavaScript** the device channel cannot be used: decision 3 requires a header a plain
   form cannot send. The pages say so; this is accepted, not worked around (a session-backed form
   door would be the rejected «mobile backend page», E0).

Tested by `tests/app-shell.php` (real stack) and the D section of `tests/api-device-channel.php`;
the receipt targets by `tests/module-receipt.php` (O17–O21, MA1–MA6). Not yet run on a phone
(`topics/api.md` API-008).
