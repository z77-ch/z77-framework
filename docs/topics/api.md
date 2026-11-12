# api

2026-11-12

## entry

1. `packages/module-api/src/Ui/Controllers/Main/GatewayController.php` — the one action behind `/api`: version/endpoint parsing, rate limit, dispatch, log
2. `packages/kernel/core/src/Services/ApiKeyGuard.php` — the stateless path's guard: bearer key → tenant, else device cookie → device (ADR-057)
3. `docs/03-development/api-envelope-v1-2026-09-02.md` — the transport contract (freeze target)

## file map

SOURCE=/packages/module-api/src/App/Config/apiConfig.inc.php
SOURCE=/packages/module-api/src/Ui/Controllers/Main/GatewayController.php
SOURCE=/packages/module-api/src/Services/ServiceRegistry.php
SOURCE=/packages/module-api/src/Services/HealthService.php
SOURCE=/packages/module-api/src/Services/DevicesService.php
SOURCE=/packages/module-api/src/Services/ApiDevices.php
SOURCE=/packages/module-api/src/Services/IdempotencyStore.php
SOURCE=/packages/module-api/src/Entities/ApiDevice.php
SOURCE=/packages/kernel/core/src/Services/ApiKeyGuard.php
SOURCE=/packages/kernel/core/src/Services/RequestGuardInterface.php
SOURCE=/packages/kernel/core/src/Http/Response/ApiResponder.php
SOURCE=/packages/kernel/core/src/Http/Response/JsonResponse.php
SOURCE=/packages/kernel/shared/src/Api/ApiServiceInterface.php
SOURCE=/packages/kernel/shared/src/Api/ScopedApiServiceInterface.php
SOURCE=/packages/kernel/shared/src/Api/ApiRequest.php
SOURCE=/packages/kernel/shared/src/Api/ApiResult.php
SOURCE=/packages/kernel/shared/src/Api/ApiLog.php
SOURCE=/packages/kernel/shared/src/Auth/TenantKeyResolverInterface.php
SOURCE=/packages/kernel/shared/src/Auth/DeviceKeyResolverInterface.php
SOURCE=/packages/kernel/shared/src/Auth/ApiPrincipal.php
SOURCE=/packages/kernel/shared/src/Throttle/FileThrottle.php
SOURCE=/docs/02-decisions/adr-057-api-device-channel.md
SOURCE=/tests/api-stateless-guard.php
SOURCE=/tests/api-contract-throttle.php
SOURCE=/tests/api-device-channel.php
SOURCE=/packages/module-api/src/Services/ApiDeviceScopes.php
SOURCE=/packages/module-api/src/Ui/ApiDeviceControllerTrait.php
SOURCE=/packages/module-api/src/Ui/ApiDeviceAccess.php
SOURCE=/packages/module-api/src/Ui/ApiDeviceListing.php
SOURCE=/packages/module-api/src/Ui/ApiDevicePairing.php
SOURCE=/packages/module-api/src/Ui/ApiDeviceLayout.php
SOURCE=/packages/module-api/res/view/templates/Backend/ApiDeviceController/listAction.tpl.php
SOURCE=/packages/module-api/res/view/templates/Backend/ApiDeviceController/_row.tpl.php
SOURCE=/packages/module-api/res/view/templates/Backend/ApiDeviceController/pair.tpl.php
SOURCE=/packages/module-api/res/view/templates/Backend/ApiDeviceController/_pairStatus.tpl.php
SOURCE=/packages/module-api/res/view/templates/Backend/ApiDeviceController/edit.tpl.php
SOURCE=/packages/module-api/res/view/templates/Backend/ApiDeviceController/confirmRevoke.tpl.php
SOURCE=/packages/module-api/data/framework/routing/navigation.d/module-api.json
SOURCE=/packages/module-backend/src/Ui/Controllers/System/ApiDeviceController.php
SOURCE=/packages/module-backend/src/Ui/Config/System/apiDeviceControllerConfig.inc.php
SOURCE=/tests/module-api-devices-ui.php
SOURCE=/packages/module-api/src/Services/AppScreens.php
SOURCE=/packages/module-api/src/Ui/Controllers/App/AppController.php
SOURCE=/packages/module-api/src/Ui/AppScriptResponse.php
SOURCE=/packages/module-api/src/Ui/Config/layoutConfig.inc.php
SOURCE=/packages/module-api/res/view/templates/html-app-skeleton.tpl.php
SOURCE=/packages/module-api/res/view/templates/App/AppController/startAction.tpl.php
SOURCE=/packages/module-api/res/view/templates/App/AppController/pairAction.tpl.php
SOURCE=/packages/module-api/res/assets/css/app.css
SOURCE=/packages/module-api/res/assets/js/app.js
SOURCE=/packages/module-api/res/assets/js/app-sw.js
SOURCE=/tests/app-shell.php
RUNTIME=/skeleton/data/framework/api/devices.json
RUNTIME=/skeleton/var/lib/api/pairing
RUNTIME=/skeleton/var/lib/api/idempotency

## mental model

The API is a **stateless reserved route** (`/api`, `stateless: true`) plus a
generic gateway module plus **project-declared services**. The framework owns
the transport — routing, key auth, rate limit, status codes, headers, error
envelope, request log; a project owns exactly the payload: its override
apiConfig declares `apiKeyResolver` (bearer key → tenant) and `apiServices`
(endpoint → `ApiServiceInterface` FQCN). Any z77 installation can offer an API
by declaring services; the broker role (AXO3) is configuration, not framework
code.

Request path: `Bootstrap::pullUp()` routes, sees `Request::isStateless()`,
and wires the stateless branch — no session, no locale logic, no page cache,
`ApiKeyGuard` instead of `AccessGuard`, JSON errors always
([`bootstrap.md`](bootstrap.md)). The Dispatcher runs guard → controller;
`GatewayController` (NOT an AbstractBaseController — the base resolves
session-bound services) parses `/api/v1/units` → slugs `[v1, units]`,
rate-limits the caller (`FileThrottle`, `var/lib/throttle/api`), resolves the
service (`health` built in), and maps the `ApiResult` onto the wire via
`ApiResponder`.

**Who called — `ApiPrincipal` (api-principal handoff, 2026-09-02):** a tenant
can hold several CONNECTIONS, each delivering a different selection, each the
unit of revocation (the widget established the pattern). The resolver
therefore answers with an `ApiPrincipal` — tenant plus optional `keyRef`, the
project-assigned identity of the connection. `keyRef` is opaque to the
framework (passed into `ApiRequest`, the throttle key `key:{tenant}:{ref}` —
a chatty connection must not eat a sibling's quota — and the `api.log`
principal column `8:widget-a`, which is what answers a rotation's "which key
called?"). A project resolving tenants only returns `keyRef: null` and runs
unchanged (throttle falls back to `tenant:{id}`).

**Two channels — machine and device ([ADR-057](../02-decisions/adr-057-api-device-channel.md), 2026-11-12):**
the guard is a chain — bearer → `apiKeyResolver` (machine), else the HttpOnly
cookie `z77_api_device` → `apiDeviceResolver` (device; module-api's
`ApiDevices` ships it), else 401. A device principal
(`ApiPrincipal::device()`: `deviceId`, `ownerId`, `ownerName`, `scopes`; tenant
column `device`, log `device:3`) is DATA for the target — who captured — never
an identity to act as. The cookie path is a CSRF surface: `X-Z77-App: 1` is
mandatory, an `Origin` must be this host, a POST must send one (403). The only
call without a credential is `POST /api/v1/devices/pair` (one-time code,
created in the backend by `ApiDevices::createPairingCode()`). Services open
themselves to POST and to devices by implementing `ScopedApiServiceInterface`
(`scopeFor($method, $slugs)`: scope, `NO_SCOPE`, or null = 405); a plain
service stays GET/HEAD and machine-only. A device POST needs `Idempotency-Key`;
`IdempotencyStore` replays a resend instead of running it twice. `ApiRequest`
carries `method`, `body`, `files`, `principal`, `idempotencyKey` (named, with
neutral defaults — positional callers unchanged). The device principal also
names the device (`deviceLabel`, 2026-11-12 — «Handy <Benutzer> · <Gerät>», E8).
A refusal of VALUES is `ApiResult::invalidFields()`: 422 `invalid_fields` with
`error.fields` (field → German message), replayed with its fields by the
idempotency store (envelope addition 2026-11-12).

Conditional GET: a service returns its payload with the content hash as ETag;
the responder answers 304 when the client's `If-None-Match` matches — services
never compare. A service may hand the responder its own wire headers
(`ApiResult::payload($data, $etag, $headers)`, 2026-09-05): they ride on 200
AND 304 — a hint that only lived in the body would be blind exactly when the
client is current — and the responder sets `Cache-Control`/`ETag` after them,
so the envelope's two can never be overridden. The framework does not read
them (AXO3: `X-Axo3-Retry-After` while another process rebuilds the stock). Contract details (status codes, error codes, key rotation,
`.htaccess` pass-through): the envelope doc, frozen after the zihlundsee pilot.

- Consumers keep the snapshot pattern: SSR from the local snapshot, TTL
  background revalidation — **never an API call in the hot path**. API downtime
  costs freshness only.
- Decision history: `docs/03-development/api-module-handoff-2026-09-02.md`
  (broker decision) and `api-module-review-2026-09-02.md` (design review +
  adversarial findings).

## endpoints (v1)

| Endpoint | Service | Notes |
|---|---|---|
| `/api/v1/health` | built-in (`HealthService`) | keyed; doubles as the Authorization pass-through self-test |
| `/api/v1/units` | project-declared | AXO3: passthrough of the per-tenant snapshot (`SnapshotData` shape), `meta.hash` as ETag |
| `/api/v1/devices/pair` | built-in (`DevicesService`) | POST, anonymous: pairing code → device cookie (ADR-057) |
| `/api/v1/devices/me` | built-in (`DevicesService`) | GET, any paired device: label, owner name, scopes |
| `/api/v1/receipt-templates` | module-receipt (`Api\ReceiptTemplatesService`) | GET, device with `receipt.capture`: the active templates `{code, label, icon, position}`, ETag ([`receipt.md`](receipt.md)) |
| `/api/v1/payment-methods` | module-receipt (`Api\PaymentMethodsService`) | GET, device with `receipt.capture`: `{code, label}`, ETag |
| `/api/v1/receipts` | module-receipt (`Api\ReceiptsService`) | POST multipart, device with `receipt.capture`: capture into the receipt journal; 422 `invalid_fields` with German `error.fields` |

## request log

One line per request, `logs/api.log`, written by `ApiLog` — guard denials
(principal `-`, 401) and everything past the guard (principal, method, URI,
status, duration). The principal column is `tenant` or `tenant:keyRef` when
the resolver names the connection. A failing log write falls back to
error_log, never fails the request. Rotation is built in: past ~5 MB the file
rolls to `api.log.1` (one generation — an operator log, not an archive).

Unauthenticated floods are capped BEFORE they reach the log: the ApiKeyGuard
throttles failed auth per source /64 (30/h) — past the limit the answer is a
429 with `Retry-After` and NO log line. Not a guessing risk (32 random bytes),
a disk risk on shared hosting: the flood itself must stop reaching the disk.

## backend screen «Geräte»

Stammdaten › System › Geräte, next to «Benutzer» (board «Geräte» on the developer canvas,
approved 2026-11-12; built the same day). Logic and templates in module-api
(`ApiDeviceControllerTrait`, ADR-018 pattern), mounted by module-backend's
`System/ApiDeviceController`; URL `/backend/system/api-device/…`; navigation seed key
`geraete` in `module-api.json`.

- **List** — a standard list (`ApiDeviceListing`): tabs «Aktiv» (default) · «Alle» (blocked
  ones stay readable: who captured what); columns state icon (phone = live, lock = blocked;
  opens the device window) · ⋮ · Name · Besitzer (both searched) · Berechtigungen · Gerät (the
  browser label of the pairing, `ApiDevice::agent`) · Gekoppelt · Zuletzt benutzt (to the hour,
  «heute 08:14») · Status pill. Rows from the file collection, searched and sorted in PHP over
  the whole set (fixed `match`). Action cell «+ Gerät koppeln»; the ⋮ context menu «Öffnen» ·
  «Sperren …».
- **Pairing window** (`pair`) — owner, device name, one switch per offered scope; a code is
  created as the window opens and again on «Neuer Code» (the code shown before is withdrawn,
  `cancelPairingCode()` — owner and scopes are IN the code). Shows a real QR (SVG, kernel
  `QrCode` facade) of `<origin>/app/pair?code=<code>` (the app shell's page, API-008; origin =
  `canonicalBaseUrl`, on a development box without one the address in use), the code as text,
  `<host>/app` for typing, valid-until with a `<progress>` and «noch m:ss», «Wartet auf das
  Handy …». The status box is a LIVE LISTENER (fetch.md FETCH-LIVE-001, every 5 s, no module
  script): `pair-status?ticket=` asks `ApiDevices::pairingState()` about a code of THIS session
  (the ticket is a random session key — the code never travels in a URL) and answers the box
  anew, or — paired — `open-window` (replace) with the new device's window plus
  `refresh-region` of the list.
- **Device window** (`edit`) — only name and scopes change (`ApiDevices::update()`); the facts
  in one muted line; «Sperren …» loads the confirm into the window (`confirm-revoke`, answers at
  the bottom — a confirm without input); `revoke` blocks, the window shows the blocked device,
  the list reloads. Blocked = read-only, no «aktiv» switch (E13: final, re-pair instead).
- **Permissions** (`ApiDeviceAccess`, the existing model) — controller role EDITOR: every
  backend user pairs, renames and blocks HIS OWN devices (a credential of his, like his second
  factor). Whoever governs the users (ADMIN and up, the role of Stammdaten › Benutzer) sees all
  devices and acts for another owner — never for one whose roles lie above his own (the user
  list's rule); the actor's roles from the store. Scopes stay independent of the owner's roles.
- **Scopes offered** (`ApiDeviceScopes`) — `scopeFor()` cannot list what it would answer, so
  the module that owns a target DECLARES its scope with words in its config:
  `'apiDeviceScopes' => ['receipt.capture' => ['label' => 'Belege erfassen', 'hint' => '…']]`
  (collected across modules like `apiServices`; bad name, missing label, double declaration
  throw). Undeclared scopes are not offered; one a device still holds shows by its key.
- Tested by `tests/module-api-devices-ui.php` (service additions, scopes, access, listing,
  pairing URL/QR, templates incl. Rule 7). **Not seen in a browser.**

## app shell `/app`

The phone's side of the device channel (plan A6 / T6, board «z77 App» approved 2026-11-12,
[ADR-057 addendum](../02-decisions/adr-057-api-device-channel.md)). module-api owns a GENERIC
shell; the module that owns a target contributes its screen (`appScreens`, `AppScreens`).

- **Routes** (reserved, NOT stateless, module-api group `app`, `AppController`): `/app/` start ·
  `/app/pair?code=…` pairing (the QR of «Geräte»; `Referrer-Policy: no-referrer`, the script
  drops the code from the address) · `/app/manifest.webmanifest` (`scope` / `start_url` `/app/`,
  standalone, the installation's `/web-app-manifest-*.png`) · `/app/sw.js` (the worker, served
  under /app/ so its scope is /app/ — `AppScriptResponse`, because `bytes()`/`file()` turn every
  script into `text/plain` on purpose). `/app/<anything else>` → 404.
- **No device on the page.** The cookie is `Path=/api`, so the server cannot know the device
  here: the pages are frames; `_Z77.app.shell` asks `GET devices/me` (401 → `/app/pair`), shows
  the screens whose scope the device holds (all are rendered `hidden`), and the screen scripts
  build their lists from the gateway with DOM nodes + `textContent`.
- **Enforced CSP** on both pages (`AppController::CSP`: `default-src 'self'`, `script-src` /
  `style-src` / `img-src 'self'`, no `unsafe-*`, `frame-ancestors 'none'`), set in the action
  before the answer — `SecurityHeaders` keeps it; the global default stays observing. Hence: no
  inline script, no `style=`, icons as inline sprites, the photo preview a `<canvas>` (no
  `blob:` image).
- **`_Z77.app`** (`app.js`): `store` (IndexedDB `z77-app`: `kv` = last GET answers with ETag,
  `me`, screen preferences; `queue` = waiting submissions), `api` (`X-Z77-App: 1`, ETag + offline
  copy, UUID keys), `queue` (stored BEFORE sending, removed only on 2xx; no network / 429 / 5xx
  → waits; 401 → re-pair, entry kept; other refusal → `refused` with the server's errors, «Bearbeiten»
  / «Verwerfen»), `shell` (pairing, `me`, banner «n Belege warten auf Netz», worker). Nothing in
  the store is a secret (Rule 7) — the photo and values of a waiting receipt are the device
  owner's own data.
- **Worker** (`app-sw.js`): network-first for the two pages (last good answer = offline copy),
  cache-first for versioned `/assets/…_at-…` (older version dropped), never `/api`. The queue
  is NOT in the worker (no Background Sync on iOS): it flushes when the app is open — on start,
  on `online`, every 30 s while visible.
- **Without JavaScript** nothing can be sent: the device channel demands the `X-Z77-App` header
  (ADR-057 decision 3), which a plain form cannot set. The pages say so (`<noscript>`); the
  pairing form then only reloads itself (GET), never posts into a JSON 403.
- Tested by `tests/app-shell.php` (real stack: CSP once and enforced, no inline script/style/
  handler, manifest, worker type and CSP, 404s, the global default untouched). **Not run on a
  real phone** (API-008).

## rules

- When adding an API endpoint → MUST implement `ApiServiceInterface` in project code and declare it under `apiServices` in the project's apiConfig override; MUST NOT add endpoint knowledge to the gateway
- When returning from an API service → MUST return `ApiResult` (payload+etag or typed error); MUST NOT touch `header()`, echo, or session-bound services (unregistered on the stateless path — fail-fast). A service header goes into `ApiResult::payload(…, $headers)`, named `X-<Project>-*`; MUST NOT name `Cache-Control`, `ETag` or `Retry-After` (envelope-owned; the first two are overwritten, the third belongs to errors)
- When mapping an ApiResult to a response → MUST go through `ApiResponder`; MUST NOT hand-build the error body or status mapping anywhere else
- When declaring the `/api` route → MUST set `stateless: true` and `cache.enabled: false` (all of `/api/*` shares ONE 4-tuple; the tenant is a header — D2 collision, [`routing.md`](routing.md))
- When writing an API action or service → MUST NOT carry `#[Fetch]`/`#[Page]` attributes (API requests are mode-agnostic)
- When storing API keys → MUST store SHA-256 hashes, compare via `hash_equals`, and support two active keys per connection (rotation window); plaintext is shown once at creation
- When assigning a `keyRef` → MUST NOT use the key or its hash (it lands in logs and throttle keys); a short stable slug (`[a-z0-9-]`) of the connection. The framework MUST NOT interpret it — pass-through only
- When changing the v1 contract → MUST only ADD fields; renames, retypes, and removals MUST go into v2 (envelope doc owns the frozen surface — error `code` values included)
- When overriding apiConfig in a project → the override REPLACES the package file: it MUST carry the full config, not only the added keys — including `apiDeviceResolver` when the installation offers the device channel
- When a service accepts POST or devices → MUST implement `ScopedApiServiceInterface` and name the scope per method (`area.verb`, e.g. `receipt.capture`); MUST NOT check scopes, the X-Z77-App header or the Origin itself — the gateway and guard do
- When a service writes on behalf of a person → MUST read the device from `$request->device()` and refuse a machine caller (a bearer key passes the scope check by design); MUST treat the device principal as data, MUST NOT act as its owner (no AuthService on the stateless path)
- When reading request data in a service → MUST use `ApiRequest` (`body`, `file()`, `files`, `principal`); MUST NOT reach for `$_POST` / `$_FILES` / the kernel `Request`
- When pairing a device or changing its scopes → MUST go through `ApiDevices` (`createPairingCode`, `cancelPairingCode`, `update`, `revoke`); MUST grant only the scopes the device needs, independent of the owner's roles; MUST NOT store the cookie secret or the plain pairing code; MUST NOT reactivate a revoked device (E13 — `update()` refuses it, re-pair instead)
- When a module offers a target to devices → MUST declare its scope under `apiDeviceScopes` in its own config with a German `label` (and an optional `hint`) — the pairing window offers only declared scopes; MUST NOT hard-wire a scope list into module-api or the screen
- When the backend shows a pairing code's state → MUST ask `ApiDevices::pairingState()` with a code kept in the session (ticket); MUST NOT put the code into a URL or a client-side poll of its own (live listener only)
- When a client sends a writing request on the device channel → MUST send `Idempotency-Key` (a UUID per logical submission, reused on resend)
- When a service refuses the VALUES of a request → MUST return `ApiResult::invalidFields($fields)` (422 `invalid_fields`, field → message for the person, in `error.fields`); MUST NOT invent another error shape or put field messages into `message`
- When a module offers a screen to the phone → MUST declare it under `appScreens` in its own config (scope, title, template and scripts of that module) and build its lists with DOM nodes + `textContent`; MUST NOT add a screen to module-api, MUST NOT write inline script or `style=` (the app CSP is enforced)
- When the app shell stores something on the device → MUST store only what the screens show and the waiting submissions; MUST NOT store a credential, token or role flag (the device cookie is HttpOnly and stays so); MUST delete a queue entry only after the server confirmed it

## see also

- [`bootstrap.md`](bootstrap.md) — the stateless branch: what is (not) registered on the API path
- [`routing.md`](routing.md) — stateless reserved routes, mode-independence, the D2 cache collision
- [`fetch.md`](fetch.md) — the browser-fetch envelope is a DIFFERENT contract; its "MUST NOT return raw JsonResponse" rule targets Fetch endpoints, not API actions
- [`security.md`](security.md) — the device cookie follows the session cookie's hardening (HttpOnly, Secure on HTTPS) plus SameSite=Strict and Path `/api`
- [`../03-development/gateway-app-kanal-bauplan.md`](../03-development/gateway-app-kanal-bauplan.md) — the plan behind the device channel and its first target (receipt capture)

## known issues

_(none)_

## pending

- **API-002 — operator alerting, AXO3 side**: framework `AlertService` built 2026-09-02 ([`alert.md`](alert.md)); the zihlundsee caller is wired and its client swapped (API-005 done project-side: `axo3/api-client` + `Axo3Factory::alertHook()` in the zihlundsee.ch project, 2364d83 there). Remaining: AXO3's `last_used_at` aging watch (AXO3 project, alert.md ALERT-002) — REQUIRED before go-live; state in AXO3 unverified.
- **API-003 — contract test for v1**: `tests/api-contract-throttle.php` pins the envelope's status codes, ETag/304 and the `{"error":{code,message}}` shape; the payload field list is not pinned yet (unverified whether elsewhere). Part of the freeze after the zihlundsee pilot.
- **API-004 — emonitor adapter service** (AXO3): `UnitsService` is PropBase-only; emonitor tenants need their own adapter (sequencing step 4), response stays upstream-neutral. AXO3 project — state unverified.
- **API-010 — «Geräte» follow-ups** (screen built 3a8b8f6, section «backend screen «Geräte»»): (a) the board's ⋮ item «Belege dieses Geräts» and the window's «n Belege erfasst» are NOT built — module-api must not know the receipt journal; needs a module-agnostic contribution (a target module declares a link per device) — owner decision; (b) look in a browser (QR scan, live switch to the device window). (6 vs. 10-character code settled: E15, 2b295e8.)
- **API-008 — app shell `/app`** (built 3a8b8f6, section «app shell `/app`»). Open: (a) live test on an iPhone and an Android phone — camera via `capture="environment"`, `createImageBitmap` of an iPhone photo (HEIC) and its EXIF orientation, install to the home screen, offline start, the queue after a dead spot; (b) iOS may keep a home-screen app's cookies apart from Safari's — a phone paired in Safari may have to pair again inside the installed app; confirm on a device and say so in the «Geräte» window if true; (c) the app's name is the fixed «z77 App» — an installation name needs a source (owner); (d) «Fertig» returns to the tiles like «Nächster Beleg» (a web app cannot close itself).
- **API-009 — device cookie refresh**: the cookie lives 400 days (browser cap) and is not re-issued yet; a device in daily use must re-pair after that. Re-issue on `devices/me` needs the plaintext, which only the request carries — design before the first device reaches a year.
- **API-006 — packaging**: `z77/module-api` is not in the split matrix (`.github/workflows/split.yml`, checked 2026-11-12) — split repo, matrix entry and Packagist registration per [`packaging.md`](packaging.md) before any non-local deploy.
