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

Contract: `docs/03-development/api-envelope-v1-*.md` in the
[framework monorepo](https://github.com/z77-ch/z77-framework).
