# z77/module-api

Stateless, versioned JSON API gateway for z77 installations.

The module owns the transport: the `/api` reserved route (stateless — no
session, no locale logic, no page cache), bearer-key authentication via the
kernel's `ApiKeyGuard`, per-tenant rate limiting, endpoint→service dispatch,
conditional GET (ETag/304), the frozen error envelope, and the one-line
request log (`logs/api.log`).

A project provides the data: it overrides `apiConfig.inc.php` to declare its
`apiKeyResolver` (bearer key → tenant) and its `apiServices` (endpoint →
`ApiServiceInterface` implementation). The framework ships the mechanism, the
project the payload.

Besides the bearer key (machine channel), the gateway accepts signed-in devices
(device channel, ADR-057, ADR-058): an APP USER — an account of its own, linked to
one person, without a backend role — signs a phone in with a 6-digit code sent to
its app e-mail (`POST /api/v1/devices/login`, `POST /api/v1/devices/verify`); the
device then carries an HttpOnly cookie and reaches only services that implement
`ScopedApiServiceInterface`, with the app user's scopes as far as the linked
person's roles reach right now. App users (`framework/api/app_users.json`), devices
(`framework/api/devices.json`), `GET devices/me`, `POST devices/logout`, the
idempotency of writing requests and the app shell `/app` are part of this module;
the one-time code store is the kernel's `LoginCodeStore`. A module that offers a
target declares its scope with a label and a minimum role per realm under
`apiDeviceScopes` in its own config. The backend screen «App-Benutzer»
(Stammdaten › System, ADMIN; module-backend mounts it) creates app users, assigns
their functions and blocks their devices.

Contract: `docs/03-development/api-envelope-v1-*.md` in the
[framework monorepo](https://github.com/z77-ch/z77-framework).
