# api

2026-11-12

## entry

1. `packages/module-api/src/Ui/Controllers/Main/GatewayController.php` — the one action behind `/api`: version/endpoint parsing, rate limit, dispatch, log
2. `packages/kernel/core/src/Services/ApiKeyGuard.php` — the stateless path's guard: bearer key → tenant, else device cookie → device (ADR-057); the anonymous door `devices/login|verify` (ADR-058)
3. `docs/03-development/api-envelope-v1-2026-09-02.md` — the transport contract (freeze target)

## file map

SOURCE=/packages/module-api/src/App/Config/apiConfig.inc.php
SOURCE=/packages/module-api/src/Ui/Controllers/Main/GatewayController.php
SOURCE=/packages/module-api/src/Services/ServiceRegistry.php
SOURCE=/packages/module-api/src/Services/HealthService.php
SOURCE=/packages/module-api/src/Services/DevicesService.php
SOURCE=/packages/module-api/src/Services/ApiDevices.php
SOURCE=/packages/module-api/src/Services/IdempotencyStore.php
SOURCE=/packages/module-api/src/Services/ApiAppLogin.php
SOURCE=/packages/module-api/src/Services/ApiAppUsers.php
SOURCE=/packages/module-api/src/Services/ApiAppUserDenied.php
SOURCE=/packages/module-api/src/Services/ApiAppUserAccountListener.php
SOURCE=/packages/module-api/src/Entities/ApiDevice.php
SOURCE=/packages/module-api/src/Entities/ApiAppUser.php
SOURCE=/packages/module-api/res/view/templates/emails/appLoginCode.tpl.php
SOURCE=/packages/module-api/res/view/templates/emails/appNewDevice.tpl.php
SOURCE=/packages/module-api/res/view/templates/emails/appEmailChanged.tpl.php
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
SOURCE=/packages/kernel/shared/src/Auth/LoginCodeStore.php
SOURCE=/packages/kernel/shared/src/Throttle/FileThrottle.php
SOURCE=/docs/02-decisions/adr-057-api-device-channel.md
SOURCE=/docs/02-decisions/adr-058-app-users-email-code-login.md
SOURCE=/docs/03-development/app-user-bauplan.md
SOURCE=/tests/api-stateless-guard.php
SOURCE=/tests/api-contract-throttle.php
SOURCE=/tests/api-device-channel.php
SOURCE=/tests/login-code-store.php
SOURCE=/packages/module-api/src/Services/ApiDeviceScopes.php
SOURCE=/packages/module-api/src/Ui/ApiAppUserControllerTrait.php
SOURCE=/packages/module-api/src/Ui/ApiAppUserWindow.php
SOURCE=/packages/module-api/src/Ui/ApiAppUserListing.php
SOURCE=/packages/module-api/src/Ui/ApiAppUserLayout.php
SOURCE=/packages/module-api/src/Ui/ApiDeviceAccess.php
SOURCE=/packages/module-api/res/view/templates/Backend/ApiAppUserController/listAction.tpl.php
SOURCE=/packages/module-api/res/view/templates/Backend/ApiAppUserController/_row.tpl.php
SOURCE=/packages/module-api/res/view/templates/Backend/ApiAppUserController/edit.tpl.php
SOURCE=/packages/module-api/res/view/templates/Backend/ApiAppUserController/confirmState.tpl.php
SOURCE=/packages/module-api/res/view/templates/Backend/ApiAppUserController/confirmRevoke.tpl.php
SOURCE=/packages/module-api/data/framework/routing/navigation.d/module-api.json
SOURCE=/packages/module-backend/src/Ui/Controllers/System/ApiAppUserController.php
SOURCE=/packages/module-backend/src/Ui/Config/System/apiAppUserControllerConfig.inc.php
SOURCE=/tests/module-api-app-users-ui.php
SOURCE=/packages/module-api/src/Services/AppScreens.php
SOURCE=/packages/module-api/src/Ui/Controllers/App/AppController.php
SOURCE=/packages/module-api/src/Ui/AppScriptResponse.php
SOURCE=/packages/module-api/src/Ui/Config/layoutConfig.inc.php
SOURCE=/packages/module-api/res/view/templates/html-app-skeleton.tpl.php
SOURCE=/packages/module-api/res/view/templates/App/AppController/startAction.tpl.php
SOURCE=/packages/module-api/res/view/templates/App/AppController/loginAction.tpl.php
SOURCE=/packages/module-api/res/assets/css/app.css
SOURCE=/packages/module-api/res/assets/js/app.js
SOURCE=/packages/module-api/res/assets/js/app-sw.js
SOURCE=/tests/app-shell.php
RUNTIME=/skeleton/data/framework/api/devices.json
RUNTIME=/skeleton/data/framework/api/app_users.json
RUNTIME=/skeleton/var/lib/api/login
RUNTIME=/skeleton/var/lib/api/login-cap
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
(`ApiPrincipal::device()`: `deviceId`, `deviceLabel`, `appUserId`, `linkedRealm`,
`linkedId`, `ownerName`, `scopes`; tenant column `device`, log `device:3`) is DATA
for the target — who captured — never an identity to act as. API change (ADR-058, no
CHANGELOG in this repo): ADR-057's `ownerId` is gone from `ApiPrincipal`, and the device
fields are set only through `ApiPrincipal::device()` or named constructor arguments — a caller
that built a device principal positionally must switch. The cookie path is a
CSRF surface: `X-Z77-App: 1` is mandatory, an `Origin` must be this host, a POST
must send one (403). Services open themselves to POST and to devices by
implementing `ScopedApiServiceInterface` (`scopeFor($method, $slugs)`: scope,
`NO_SCOPE`, or null = 405); a plain service stays GET/HEAD and machine-only. A
device POST needs `Idempotency-Key`; `IdempotencyStore` replays a resend instead of
running it twice. `ApiRequest` carries `method`, `body`, `files`, `principal`,
`idempotencyKey` (named, with neutral defaults — positional callers unchanged). A
refusal of VALUES is `ApiResult::invalidFields()`: 422 `invalid_fields` with
`error.fields` (field → German message), replayed with its fields by the
idempotency store (envelope addition 2026-11-12).

**App users — the e-mail-code sign-in ([ADR-058](../02-decisions/adr-058-app-users-email-code-login.md), 2026-11-12; pairing removed):**
a device is signed in as an APP USER (`ApiAppUser`, `framework/api/app_users.json`):
an account type of its own, no backend role, linked to exactly one person (a backend
user; members later), identified by its app e-mail, carrying the scopes it may use.
The only calls without a credential are `POST devices/login {email}` (always 202 +
the nonce cookie `z77_api_login`) and `POST devices/verify {code}` (the device
cookie) — the guard's `ANONYMOUS_SLUGS`. The code store is the kernel's
`LoginCodeStore` (one record per request, judged under `flock`). `resolve()` asks
on every request: device live, app user active, linked person there, and the
scopes filtered by the person's roles NOW (each scope declares a minimum role per
realm). P4: this login is one factor — capture-only scopes ride on it, nothing with
a binding or outward effect.

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
| `/api/v1/devices/login` | built-in (`DevicesService`) | POST `{email}`, anonymous: always 202 `{"status":"accepted"}` + `Set-Cookie: z77_api_login` (ADR-058); 429 past 10 per /64 and hour |
| `/api/v1/devices/verify` | built-in (`DevicesService`) | POST `{code}`, anonymous + the nonce cookie: device cookie + `{device}`, else one generic 403; 429 past 20 per /64 and hour |
| `/api/v1/devices/me` | built-in (`DevicesService`) | GET, any signed-in device: label, owner (the linked person), scopes (live) |
| `/api/v1/devices/logout` | built-in (`DevicesService`) | POST (device, `Idempotency-Key`): revokes THIS device, clears its cookie |
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

## app users and the code sign-in (ADR-058)

[ADR-058](../02-decisions/adr-058-app-users-email-code-login.md), build plan
[`app-user-bauplan.md`](../03-development/app-user-bauplan.md) (§4 = the binding security rules).

- **`ApiAppUser`** — `id, email` (lower case, unique), `linked_realm` (`backend` | `member`),
  `linked_id` (unique per realm), `scopes`, `active`, `created_at/by`, `changed_at/by`,
  `last_login_at`. No name of its own: the linked person's name is shown (`ownerName`).
- **`ApiAppUsers`** — reading (`find`, `findByEmail`, `findLinked`, `all`, `linkedPerson()` =
  name + roles FROM THE STORE, null for a member (A8) or a person gone, `isUsable`, `liveScopes`)
  and the administration: `create(actorId, email, realm, linkedId, scopes)`, `update(actorId, id,
  email, scopes)`, `deactivate`, `reactivate`, `deactivateLinked` (the listener). ADMIN only (A9);
  the actor rule `ApiDeviceAccess::mayActFor()` (never for a person above the actor); a scope
  ADDED must be declared, reached by the person's roles and by the actor's. Refusal =
  `ApiAppUserDenied`, bad data = `\InvalidArgumentException`. Deactivate revokes every device
  and spends pending codes; reactivate does both AGAIN before switching on (security review S3:
  a verify that asked «active?» just before the deactivation may issue its device just after the
  revoke); an e-mail change spends pending codes and mails the OLD address. The app e-mail is a
  plain address (`isAppEmail()`: length, `FILTER_VALIDATE_EMAIL`, none of `"` `,` `;` white
  space). Uniqueness (person, e-mail) is checked and written under `var/lib/api/app-users.lock`
  (non-blocking, ~2 s budget, then `\RuntimeException`) — create and update.
- **`ApiAppLogin`** — `request(email, net, userAgent)`: per /64 10/h → 429; per address 5/h and
  15/day, silent; a record for EVERY request (`subject` null unless a usable app user within its
  limits); the code mail only then, after the response (FPM/LiteSpeed) or inline with a 2 s
  floor for every request (a request over the floor is logged). The per-address counters, the
  cap marker and the alert exist ONLY for an app user's address (security review M1): a
  malformed or unknown address is never counted, never on disk, never in an operator mail — it
  counts against one shared bucket without a limit (the same file work). `verify(nonce, code,
  net, currentCookie, userAgent)`: 20/h per /64; the store judges; app user and person asked
  again; the device whose live cookie this browser holds is revoked — whichever app user's
  (S1: the cookie proves possession; the app keeps the queues apart); a device issued; «Neues
  Gerät angemeldet» mailed. The daily cap raises an alert (`AlertService`, Service › E-Mail
  entry `apiLoginCap`) AFTER the response like the mails, the next allowed request of the
  address recovers it. `devices/verify` and `devices/me` answer `device.appUser` (the app
  user's id) — the app's queue stamps its entries with it.
- **`LoginCodeStore`** (kernel, `Z77\Shared\Auth`, beside `TotpGuard`) — `issue(subject)` →
  nonce + code + stored; `verify(nonce, code)` → subject or null (5 tries, 15 min, spent on
  success, all under `flock`; a spent record is a tombstone first, unlinked after the lock — a
  Windows handle cannot delete an open file); `forgetSubject` (reads each record under its
  lock), `sweep` (by file time). Two quotas (security review S2): 5000 live records of a
  subject under `var/lib/api/login/sha256(nonce).json`, 5000 of nobody under
  `var/lib/api/login/nobody/` — a flood for unknown addresses no longer stops real sign-ins;
  issue and verify stat, sweep and count both directories whatever they touch (no timing
  difference); the over-cap log line comes once per 15 minutes and directory.
- **Scopes with a minimum role** — `apiDeviceScopes` entries carry `'realms' => ['backend' =>
  AuthRole::EDITOR]`; `ApiDeviceScopes::holdable()` / `filter()`. No `member` key = never for
  members; no `realms` = for nobody (a declaration from before ADR-058 stays harmless).
- **Devices** — `ApiDevice.app_user_id` (NEW); `owner_id` keeps «the backend user» (the linked
  one, 0 for a member) and is never read as an app user; `scopes` on the device is no longer
  read. A row without `app_user_id` (ADR-057 pairing) → 401. Cap 10 live devices per app user.
- **Lifecycle** — `accountRemovedListeners` → `ApiAppUserAccountListener`: a deleted backend
  user's app user is deactivated. Role reduction needs no event: the live filter.
- **Mails** (German, explicit `de`, sent at once): `emails/appLoginCode` (code, device, time,
  «Gib diesen Code niemandem weiter … z77 ruft nie an»), `emails/appNewDevice`,
  `emails/appEmailChanged` (to the old address, without the new one). No «no account» mail.
- **Administered** in the backend screen «App-Benutzer» (below; plan step 5) — through
  `ApiAppUsers`, an ADMIN as actor.
- Tested by `tests/login-code-store.php` (records, tries, expiry, four parallel processes → one
  subject, forget under the lock, sweep, the two quotas), `tests/api-device-channel.php` (B, E —
  M1, mails and alert after the response, the floor, S1 re-login; F — S3, the actor rule for
  update / deactivate / reactivate, the plain address, the create lock) and `tests/app-queue.mjs`
  (the queue stamps and sends per app user, S1).

## backend screen «App-Benutzer»

Stammdaten › System › App-Benutzer, next to «Benutzer» (board «App-Benutzer» approved 2026-11-12,
built the same day — plan step 5). It REPLACED the transitional «Geräte» (trait, listing, layout,
templates, host controller, layout config, the EDITOR role and the seed key `geraete` are gone; a
device is now seen and blocked in its app user's window). Logic and templates in module-api
(`ApiAppUserControllerTrait`, ADR-018 pattern), mounted by module-backend's
`System/ApiAppUserController` (which hands in `BackendUserController::roleLabels()`, the one German
role source); URL `/backend/system/api-app-user/…`; navigation seed key `app-benutzer`.

- **List** — a standard list (`ApiAppUserListing`): tabs «Aktiv» (default) · «Alle»; columns state
  icon (phone = active, eye = person above the actor → read-only, lock = deactivated) · ⋮ ·
  Verknüpfte Person · App-E-Mail (both searched) · Funktionen (labels; muted where the person's role
  no longer reaches them) · Geräte (devices that can still sign in — not blocked, not idle-expired)
  · Zuletzt angemeldet (default sort) · Status pill. Action cell «+ App-Benutzer». The ⋮ context
  menu «Öffnen» · «Deaktivieren …» / «Aktivieren …» (where the actor may).
- **Window** (`add`, `edit`, ADR-047; one template) — NEW: first the person, a `<select>` over the
  backend users in a GET form `data-window-form="change"` that re-renders the window (persons with an
  app user or above the actor disabled with the reason); then the app e-mail, proposed from the
  person's backend e-mail (editable), and the switches. EXISTING: the person shown (fixed), the app
  e-mail, the switches, the devices (Gerät / Browser from the sign-in label, zuletzt benutzt, Status
  aktiv · abgelaufen · gesperrt, «Sperren …» per device), the facts line. The POST form carries the
  action row first (ADR-049); the fields join it by `form=`. «Deaktivieren …» (danger) /
  «Aktivieren …» stand in the action row and load their question into the window.
- **Function switches** (`ApiAppUserWindow::functions()`): every declared scope, plus any held one
  nobody declares any more; a switch the person's role does not reach is DISABLED with «Braucht
  mindestens <Rolle> — <Name> ist <Rolle>.» (the actor's role: «— du bist <Rolle>.»); muted «Mitglieder
  folgen später». A save keeps a disabled switch as it was (`keep()`) — a forged post neither adds a
  locked scope nor drops a locked held one.
- **Confirms** — `confirm-deactivate` / `deactivate` (every device signed out for good, pending
  codes spent — lead decision 2026-11-12: a danger button with a question, not a switch),
  `confirm-activate` / `activate` (no device comes back; sign in anew by code), `confirm-revoke` /
  `revoke` (one device, final — E13). Questions without input: answers at the bottom (`end`).
  Fetched, each answer re-opens the app user's window (`open-window` replace) and reloads the list.
- **Rights** — module role ADMIN (A9, no own `backendConfig` entry); `ApiDeviceAccess::mayActFor()`
  per app user: a person above the actor → listed, window READ-ONLY (no save, no deactivate, no
  block; the reason on top); a person gone → read-only except deactivate / block. Every write goes
  through `ApiAppUsers` / `ApiDevices`, which ask the rule again. The service's English refusals are
  worded on their field by `ApiAppUserWindow::explain()` (e-mail, person, functions, or the window);
  an unknown text is logged and shown as a general sentence.
- Tested by `tests/module-api-app-users-ui.php` (devices, scopes with realms, access, listing,
  mails, the window helper incl. every refusal of the real service, templates incl. Rule 7, the
  wiring, and section I through the real stack: list, new window, create, taken e-mail on the
  field, read-only for a higher person, block, deactivate, activate, `api-device` gone).
  **Not seen in a browser.**

## app shell `/app`

The phone's side of the device channel (plan A6 / T6, board «z77 App» approved 2026-11-12,
[ADR-057 addendum](../02-decisions/adr-057-api-device-channel.md), sign-in [ADR-058](../02-decisions/adr-058-app-users-email-code-login.md)).
module-api owns a GENERIC shell; the module that owns a target contributes its screen
(`appScreens`, `AppScreens`).

- **Routes** (reserved, NOT stateless, module-api group `app`, `AppController`): `/app/` start ·
  `/app/login` the code sign-in (replaces `/app/pair`, which is a 404 now) ·
  `/app/manifest.webmanifest` (`scope` / `start_url` `/app/`, standalone, the installation's
  `/web-app-manifest-*.png`) · `/app/sw.js` (the worker, served under /app/ so its scope is /app/ —
  `AppScriptResponse`, because `bytes()`/`file()` turn every script into `text/plain` on purpose).
  `/app/<anything else>` → 404.
- **Sign-in page** (`/app/login`): e-mail step → `devices/login`, then the code step on the same
  page (`autocomplete="one-time-code"`, numeric) → `devices/verify`; the same texts for every
  address and every refused code («Code falsch oder abgelaufen — neuen Code anfordern»), no tries
  counter; nothing from the address bar is put into the page.
- **No device on the page.** The cookie is `Path=/api`, so the server cannot know the device
  here: the pages are frames; `_Z77.app.shell` asks `GET devices/me` (401 → `/app/login`), shows
  the screens whose scope the device holds (all are rendered `hidden`), and the screen scripts
  build their lists from the gateway with DOM nodes + `textContent`.
- **Sign-out** («Auf diesem Gerät abmelden» on the start page): `POST devices/logout` with an
  `Idempotency-Key`, then the kept answers (`kv`) are cleared. REFUSED while the queue holds any
  unsent entry, waiting or refused («Erst senden oder verwerfen …») — the queue belongs to the
  browser, nothing captured may go out under another person's sign-in.
- **Enforced CSP** on both pages (`AppController::CSP`: `default-src 'self'`, `script-src` /
  `style-src` / `img-src 'self'`, no `unsafe-*`, `frame-ancestors 'none'`), set in the action
  before the answer — `SecurityHeaders` keeps it; the global default stays observing. Hence: no
  inline script, no `style=`, icons as inline sprites, the photo preview a `<canvas>` (no
  `blob:` image).
- **`_Z77.app`** (`app.js`): `store` (IndexedDB `z77-app`: `kv` = last GET answers with ETag,
  `me`, screen preferences; `queue` = waiting submissions), `api` (`X-Z77-App: 1`, ETag + offline
  copy, UUID keys), `queue` (stored BEFORE sending, stamped with the app user signed in now
  (`appUser` of `me` / `verify`) and sent ONLY under that app user — an entry of another one, or
  one without a stamp, shows «Von einem anderen Zugang erfasst — verwerfen» and is never sent
  (security review S1); a successful sign-in clears the kept answers `kv` first; removed only on 2xx; no network / 429 / 5xx
  → waits; 401 → sign in again, entry kept; other refusal → `refused` with the server's errors,
  «Bearbeiten» / «Verwerfen»), `shell` (sign-in, `me`, banner «n Belege warten auf Netz», sign-out,
  worker). Nothing in the store is a secret (Rule 7).
- **Worker** (`app-sw.js`, cache `z77-app-v2` — the bump drops the v1 cache with its pairing page
  on installed phones): network-first for the two pages `/app/`, `/app/login` (last good answer =
  offline copy), cache-first for versioned `/assets/…_at-…` (older version dropped), never `/api`.
  The queue is NOT in the worker (no Background Sync on iOS): it flushes when the app is open.
- **Without JavaScript** nothing can be sent: the device channel demands the `X-Z77-App` header
  (ADR-057 decision 3), which a plain form cannot set. The pages say so (`<noscript>`); the
  forms then only reload themselves (GET), never post into a JSON 403.
- Tested by `tests/app-shell.php` (real stack: CSP once and enforced, no inline script/style/
  handler, the sign-in page's two steps, sign-out button, manifest, worker v2, `/app/pair` 404,
  the global default untouched). **Not run on a real phone** (API-008).

## consumer projects (ADR-058 upgrade)

- An override of `apiConfig` REPLACES the package file: replace the route `/app/pair` by
  `/app/login` (`'action' => 'login'`), `pairAction` by `loginAction` in the `app` controller
  config, and add `'accountRemovedListeners' => [\Z77\Module\Api\Services\ApiAppUserAccountListener::class]`.
- An override of `emailConfig` must copy the `apiLoginCap` entry (`recipientsOnly`), else nobody
  is told of a daily cap.
- A module or project declaring `apiDeviceScopes` must add `realms` (minimum role per realm), or
  the scope is offered to nobody and filtered away on every request.
- Navigation (step 5): the seed now ships «App-Benutzer» (key `app-benutzer`, `backend/system/
  api-app-user/list`) instead of «Geräte» (key `geraete`). The installer merges add-only, and on a
  release whose `data/` is shared it does not run against it at all (INST-NAV-SHARED-001): on a
  deployed project ADD the new entry to the shared `data/framework/routing/navigation.json` (the
  ad-hoc merge over `NavigationSeeds` as for the receipt entries, or Stammdaten › Navigation by hand)
  and DELETE the old «Geräte» entry there — nothing removes it, and its target
  `/backend/system/api-device/list` is a 404 now. An override of the backend layout or role config
  naming `ApiDeviceController` must drop it.
- Devices signed in under ADR-057 answer 401; the phone signs in again by code (an app user must
  exist for its person first).

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
- When creating or changing an app user → MUST go through `ApiAppUsers` with the acting backend user's id (ADMIN only, A9; the actor rule; scopes only as far as person and actor reach); MUST NOT write `app_users.json` or `ApiAppUser` rows anywhere else
- When a screen shows app users → MUST render read-only what `ApiAppUsers` would refuse (the actor rule) and take a disabled function switch's state from what is held (`ApiAppUserWindow::keep()`), never from the post; MUST word the service's refusals through `ApiAppUserWindow::explain()`, not show its English text
- When signing a device in → MUST go through `ApiAppLogin` (`devices/login`, `devices/verify`); MUST answer every address with the same 202 and write a record for every request; MUST NOT tell a caller whether an address exists, a mail failed or how many tries are left; MUST NOT store the cookie secret, the nonce or the code in plaintext
- When judging a one-time code → MUST use `LoginCodeStore::verify()` (count, compare and spend under the record's lock); MUST NOT check-then-count or use `FileThrottle` for the tries
- When a module offers a target to devices → MUST declare its scope under `apiDeviceScopes` in its own config with a German `label`, an optional `hint` and `realms` (minimum role per realm); MUST NOT hard-wire a scope list into module-api or a screen
- When a scope has a binding or outward effect (send, publish, post) → MUST NOT offer it to app users without a new owner decision (ADR-058 P4: the e-mail code is one factor)
- When resolving a device → MUST check device, app user active, linked person present and filter the scopes by the person's roles from the store on EVERY request; MUST NOT read `ApiDevice::scopes` or reinterpret `owner_id` as an app user
- When adding an anonymous API call → MUST add it to `ApiKeyGuard::ANONYMOUS_SLUGS` only after an owner decision; MUST keep such a call anonymous (a principal routes it through the idempotency store, which keeps response headers)
- When a client sends a writing request on the device channel → MUST send `Idempotency-Key` (a UUID per logical submission, reused on resend)
- When a service refuses the VALUES of a request → MUST return `ApiResult::invalidFields($fields)` (422 `invalid_fields`, field → message for the person, in `error.fields`); MUST NOT invent another error shape or put field messages into `message`
- When a module offers a screen to the phone → MUST declare it under `appScreens` in its own config (scope, title, template and scripts of that module) and build its lists with DOM nodes + `textContent`; MUST NOT add a screen to module-api, MUST NOT write inline script or `style=` (the app CSP is enforced)
- When the app shell stores something on the device → MUST store only what the screens show and the waiting submissions; MUST NOT store a credential, token or role flag (the device cookie is HttpOnly and stays so); MUST delete a queue entry only after the server confirmed it
- When signing a device out → MUST refuse while the queue holds unsent entries and MUST clear the kept answers afterwards
- When the app shell queues or sends a submission → MUST stamp it with the app user signed in now and send only the entries of that app user; MUST clear the kept answers on a successful sign-in (another person may have used the browser before)
- When the anonymous sign-in counts, marks or alerts per address → MUST do so only for the address of an app user; MUST NOT write a file per caller-chosen address or put a caller-chosen text into an operator mail, and MUST send every mail (code, new device, alert) through the after-response path

## see also

- [`bootstrap.md`](bootstrap.md) — the stateless branch: what is (not) registered on the API path
- [`routing.md`](routing.md) — stateless reserved routes, mode-independence, the D2 cache collision
- [`fetch.md`](fetch.md) — the browser-fetch envelope is a DIFFERENT contract; its "MUST NOT return raw JsonResponse" rule targets Fetch endpoints, not API actions
- [`security.md`](security.md) — the device cookie follows the session cookie's hardening (HttpOnly, Secure on HTTPS) plus SameSite=Strict and Path `/api`
- [`../03-development/gateway-app-kanal-bauplan.md`](../03-development/gateway-app-kanal-bauplan.md) — the plan behind the device channel and its first target (receipt capture)
- [`../03-development/app-user-bauplan.md`](../03-development/app-user-bauplan.md) — the app users and the code sign-in (ADR-058): owner decisions, security rules §4, open steps 2 and 5
- [`alert.md`](alert.md) — the daily-cap alert of the sign-in (`apiLoginCap`) rides on `AlertService`
- [`login.md`](login.md) — the member login's anti-oracle model the app sign-in follows; `TotpGuard` beside `LoginCodeStore`

## known issues

- **API-012 — the app's cookies lack `Secure` behind a TLS-terminating proxy** without the HTTPS environment: `z77_api_login` and `z77_api_device` take `Secure` from `Request::isHttps()`, the same detection as the session cookie (SEC-003) — the existing rule, not changed here. A proxy that terminates TLS and forwards plain HTTP must set `HTTPS=on` (or the installation's equivalent) for the backend; otherwise the cookies go out without `Secure` (still HttpOnly, SameSite=Strict, Path `/api`).
- **API-013 — the nonce cookie is not cleared after a successful verify**: `ApiResult::$headers` carries one value per header name and `JsonResponse::send()` replaces a header of the same name, so a second `Set-Cookie` beside the device cookie would need an envelope change. Harmless: the record behind the nonce is spent, the cookie inert and gone after 15 minutes (`DevicesService::verify()` notes it).

## pending

- **API-002 — operator alerting, AXO3 side**: framework `AlertService` built 2026-09-02 ([`alert.md`](alert.md)); the zihlundsee caller is wired and its client swapped (API-005 done project-side: `axo3/api-client` + `Axo3Factory::alertHook()` in the zihlundsee.ch project, 2364d83 there). Remaining: AXO3's `last_used_at` aging watch (AXO3 project, alert.md ALERT-002) — REQUIRED before go-live; state in AXO3 unverified.
- **API-003 — contract test for v1**: `tests/api-contract-throttle.php` pins the envelope's status codes, ETag/304 and the `{"error":{code,message}}` shape; the payload field list is not pinned yet (unverified whether elsewhere). Part of the freeze after the zihlundsee pilot.
- **API-004 — emonitor adapter service** (AXO3): `UnitsService` is PropBase-only; emonitor tenants need their own adapter (sequencing step 4), response stays upstream-neutral. AXO3 project — state unverified.
- **API-011 — app users, open** (ADR-058, plan §9; board and screen «App-Benutzer» built 2026-11-12): the screen is **not seen in a browser** (the person GET form re-rendering the window, the `form=`-joined fields, the disabled switches with their reason, the confirms in the window); the navigation of deployed projects needs the manual merge («consumer projects» above). Not tested on a real server: the deferred mail after `fastcgi_finish_request()`, the 2 s floor without it, a real code mail through SMTP. Left in the services, no longer used by a screen: `ApiDevices::update()` (device rename) and `ApiDevices::listFor()` (devices by `owner_id`) — remove or keep, owner's call.
- **API-010 — receipts per app user** (board item «Belege dieses App-Benutzers» in the ⋮ and «n Belege erfasst» in the window): NOT built — module-api must not know the receipt journal; needs a module-agnostic contribution (a target module declares a link or a count per app user, e.g. an `apiAppUserLinks` declaration) — owner decision. (b) of the old «Geräte» item is done: the device's own scopes are no longer shown anywhere.
- **API-008 — app shell `/app`** (built 3a8b8f6, sign-in ADR-058, section «app shell `/app`»). Open: (a) live test on an iPhone and an Android phone — camera via `capture="environment"`, `createImageBitmap` of an iPhone photo (HEIC) and its EXIF orientation, install to the home screen, offline start, the queue after a dead spot, the code sign-in incl. the iOS one-time-code suggestion from Mail; (b) iOS keeps a home-screen app's cookies apart from Safari's — with the code sign-in the installed app simply signs in itself; confirm on a device; (c) the app's name is the fixed «z77 App» — an installation name needs a source (owner); (d) «Fertig» returns to the tiles like «Nächster Beleg» (a web app cannot close itself); (e) Firefox (desktop, 2026-11-12) hung after a successful verify — the device cookie was set, the page waited forever on the IndexedDB store before the redirect. Since then `store.open()` gives up after 3 s (= no IndexedDB) and the redirects after verify and sign-out are capped at 2.5 s. The same Firefox showed the sign-in page without its stylesheet — the worker's cache-first lookup hung in the same origin storage (Cache API); a cache that does not answer within 2 s now counts as a miss (`app-sw.js` `CACHE_MS`). The missing stylesheet then showed in Chrome too and came back with a hard reload (past the worker): the worker had kept a non-stylesheet answer under the versioned CSS name (an HTML page with 200, presumably from a release whose assets were not yet published) and served it cache-first for good — the name only changes with the file. Since cache `z77-app-v3` an asset is kept only with its own content type (`isAssetType`), and v3 drops the v2 copies at activation. (f) A deactivated app user kept his tiles (owner, 2026-11-13): the server state was right (app user inactive, device revoked) but the phone had sent NO request to /api since — the shell had shown the last `me` kept on the device. Since then the shell asks `devices/me` again whenever the app comes back to the front (`visibilitychange`, `pageshow` from the back/forward cache, `online`), any 401 of any call leads to `/app/login`, and the offline copy is announced («Kein Netz — die App zeigt den letzten bekannten Stand.»). Whether the phone was offline at that moment is unverified. Why that Firefox's storage did not answer is not known — watch whether captures there run without the queue.
- **API-014 — A8 (members as app users): the «actor could hold» check is not realm-aware**: `ApiAppUsers::assignable()` asks whether the ACTOR could hold an added scope in `REALM_BACKEND` (he is a backend user). For a member-linked app user a scope declared for `member` only would be refused there. Decide with A8 what «the actor could hold it» means across realms (security review minor, 2026-11-12).
- **API-006 — packaging**: `z77/module-api` is not in the split matrix (`.github/workflows/split.yml`, checked 2026-11-12) — split repo, matrix entry and Packagist registration per [`packaging.md`](packaging.md) before any non-local deploy.
