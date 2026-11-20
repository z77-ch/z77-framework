# z77/module-api

The stateless, versioned JSON API gateway of z77 — and the **z77 App**, a small installable web
app (`/app`) through which people sign in a phone with a code by e-mail and use the functions
granted to them (today: capture receipts).

Developed in the [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-api`); published on Packagist since 2.0.0:
`composer require z77/module-api`.

## What it does

**The gateway (`/api`, reserved route).** Stateless — no session, no locale logic, no page
cache. It owns the transport: bearer-key authentication through the kernel's `ApiKeyGuard`,
per-tenant rate limiting, endpoint → service dispatch, conditional GET (ETag / 304), the frozen
error envelope `{"error":{code,message}}`, idempotency of writing requests
(`Idempotency-Key`, `IdempotencyStore`) and a one-line request log (`logs/api.log`).

A project provides the data: in its override of `apiConfig.inc.php` it declares an
`apiKeyResolver` (bearer key → tenant) and `apiServices` (endpoint → `ApiServiceInterface`). The
framework ships the mechanism, the project the payload.

**The device channel (ADR-057, ADR-058).** Besides the bearer key the gateway accepts signed-in
devices. An **app user** is an account of its own, linked to exactly one person (today a backend
user), with no backend role. It signs a phone in with a 6-digit code sent to its app e-mail
(`POST /api/v1/devices/login`, `POST /api/v1/devices/verify`, anonymous, throttled, always the
same answer); the device then carries an HttpOnly cookie and reaches only services that
implement `ScopedApiServiceInterface`, with the app user's **scopes** as far as the linked
person's roles reach *right now*. A module declares its scopes (label, minimum role per realm)
under `apiDeviceScopes` in its own config — e.g. `receipt.capture` of `module-receipt`.

| Endpoint | Notes |
|---|---|
| `GET /api/v1/health` | keyed; also the Authorization pass-through self-test |
| `POST /api/v1/devices/login`, `/verify` | the code sign-in |
| `GET /api/v1/devices/me`, `POST /api/v1/devices/logout` | the signed-in device |
| project / module endpoints | e.g. `module-receipt`: `receipt-templates`, `payment-methods`, `receipts` |

**The app shell (`/app`).** A PWA (manifest, service worker, no app store): sign-in by code, a
start page with the tiles the device's scopes allow, and the screens modules contribute
(`AppScreens`) — today the receipt capture of `module-receipt`. Captures are stored on the phone
first and sent when there is network (offline queue).

Storage: app users `data/framework/api/app_users.json`, devices `data/framework/api/devices.json`;
the one-time codes are the kernel's `LoginCodeStore`. Mails: login code, new device, app e-mail
changed.

## Screens and routes

| Where | What |
|---|---|
| Backend «Stammdaten › System › App-Benutzer» — `/backend/system/api-app-user/list` | ADMIN. Create app users (person, app e-mail, functions), deactivate / activate, see and block devices. Fragment `Ui\ApiAppUserControllerTrait`, host in `module-backend`. Help: «Hilfe» in the toolbar and in the window. |
| `/app`, `/app/login` | The z77 App on the phone. No help of its own (the backend guide «z77 App» explains it). |
| `/api/v1/…` | The gateway. |

## Requirements

PHP ≥ 8.2, `z77/kernel` ^2.0. HTTPS (the cookies are marked `Secure` on HTTPS), a working mail account for
the codes, and on Apache the `Authorization` header passed through to PHP.

## Enabling it in a project

`composer require z77/module-api`, register
`"Z77\\Module\\Api\\": ["override/z77/module/api/src/"]` in the project's `autoload.psr-4` (without it the
package is installed but not a module — see [Switch the modules on](https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/business-suite-setup.md#2-switch-the-modules-on)), then `composer
install` (registers the module and the navigation seed «App-Benutzer»). For the machine channel
override `apiConfig.inc.php` with the key resolver and the services; for the app, enable a
module that offers a scope (e.g. `module-receipt`) and create app users in the backend.

## Not built yet

- **Members as app users** (ADR-058 A8): only backend users can be linked today.
- Receipts per app user in the app-user window («Belege dieses App-Benutzers»).
- Live test of the app on iPhone and Android (camera, HEIC photos).

## Documentation

- Topic: [`docs/topics/api.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/api.md)
- Decisions: ADR-057 (device channel), ADR-058 (app users, sign-in by code) in
  [`docs/02-decisions/`](https://github.com/z77-ch/z77-framework/tree/main/docs/02-decisions);
  envelope contract `docs/03-development/api-envelope-v1-*.md`

## License

MIT — see [`LICENSE`](LICENSE). Author: Peter Ruepp, [z77.ch](https://z77.ch).
