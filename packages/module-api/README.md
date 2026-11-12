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

Besides the bearer key (machine channel), the gateway accepts paired devices
(device channel, ADR-057): a phone or PC paired once from the backend carries
an HttpOnly cookie, holds narrow scopes, and reaches only services that
implement `ScopedApiServiceInterface`. Pairing (`POST /api/v1/devices/pair`),
`GET /api/v1/devices/me`, device storage (`framework/api/devices.json`) and the
idempotency of writing requests are part of this module. So is the backend
screen «Geräte» (Stammdaten › System: pair by QR or code, rename, scopes,
block) — its trait and templates live here, module-backend mounts it. A module
that offers a target to devices declares its scope with a label under
`apiDeviceScopes` in its own config.

Contract: `docs/03-development/api-envelope-v1-*.md` in the
[framework monorepo](https://github.com/z77-ch/z77-framework).
