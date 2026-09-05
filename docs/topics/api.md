# api

2026-09-02

## entry

1. `packages/module-api/src/Ui/Controllers/Main/GatewayController.php` — the one action behind `/api`: version/endpoint parsing, rate limit, dispatch, log
2. `packages/kernel/core/src/Services/ApiKeyGuard.php` — bearer key → tenant, the stateless path's guard
3. `docs/03-development/api-envelope-v1-2026-09-02.md` — the transport contract (freeze target)

## file map

SOURCE=/packages/module-api/src/App/Config/apiConfig.inc.php
SOURCE=/packages/module-api/src/Ui/Controllers/Main/GatewayController.php
SOURCE=/packages/module-api/src/Services/ServiceRegistry.php
SOURCE=/packages/module-api/src/Services/HealthService.php
SOURCE=/packages/kernel/core/src/Services/ApiKeyGuard.php
SOURCE=/packages/kernel/core/src/Services/RequestGuardInterface.php
SOURCE=/packages/kernel/core/src/Http/Response/ApiResponder.php
SOURCE=/packages/kernel/core/src/Http/Response/JsonResponse.php
SOURCE=/packages/kernel/shared/src/Api/ApiServiceInterface.php
SOURCE=/packages/kernel/shared/src/Api/ApiRequest.php
SOURCE=/packages/kernel/shared/src/Api/ApiResult.php
SOURCE=/packages/kernel/shared/src/Api/ApiLog.php
SOURCE=/packages/kernel/shared/src/Auth/TenantKeyResolverInterface.php
SOURCE=/packages/kernel/shared/src/Throttle/FileThrottle.php
SOURCE=/tests/api-stateless-guard.php
SOURCE=/tests/api-contract-throttle.php

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

## rules

- When adding an API endpoint → MUST implement `ApiServiceInterface` in project code and declare it under `apiServices` in the project's apiConfig override; MUST NOT add endpoint knowledge to the gateway
- When returning from an API service → MUST return `ApiResult` (payload+etag or typed error); MUST NOT touch `header()`, echo, or session-bound services (unregistered on the stateless path — fail-fast). A service header goes into `ApiResult::payload(…, $headers)`, named `X-<Project>-*`; MUST NOT name `Cache-Control`, `ETag` or `Retry-After` (envelope-owned; the first two are overwritten, the third belongs to errors)
- When mapping an ApiResult to a response → MUST go through `ApiResponder`; MUST NOT hand-build the error body or status mapping anywhere else
- When declaring the `/api` route → MUST set `stateless: true` and `cache.enabled: false` (all of `/api/*` shares ONE 4-tuple; the tenant is a header — D2 collision, [`routing.md`](routing.md))
- When writing an API action or service → MUST NOT carry `#[Fetch]`/`#[Page]` attributes (API requests are mode-agnostic)
- When storing API keys → MUST store SHA-256 hashes, compare via `hash_equals`, and support two active keys per connection (rotation window); plaintext is shown once at creation
- When assigning a `keyRef` → MUST NOT use the key or its hash (it lands in logs and throttle keys); a short stable slug (`[a-z0-9-]`) of the connection. The framework MUST NOT interpret it — pass-through only
- When changing the v1 contract → MUST only ADD fields; renames, retypes, and removals MUST go into v2 (envelope doc owns the frozen surface — error `code` values included)
- When overriding apiConfig in a project → the override REPLACES the package file: it MUST carry the full config, not only the added keys

## see also

- [`bootstrap.md`](bootstrap.md) — the stateless branch: what is (not) registered on the API path
- [`routing.md`](routing.md) — stateless reserved routes, mode-independence, the D2 cache collision
- [`fetch.md`](fetch.md) — the browser-fetch envelope is a DIFFERENT contract; its "MUST NOT return raw JsonResponse" rule targets Fetch endpoints, not API actions

## known issues

_(none)_

## pending

- **API-001 — backend UI for tenants/keys** (AXO3): **built project-side 2026-09-02** — backend mask exists; keys.json now carries one OBJECT per key (`hash`, `created_at`, `last_used_at`), not bare hash strings. The framework contract is untouched (`TenantKeyResolverInterface` never sees the store). Details: `work/docs/topics/daten-api.md` in the AXO3 project; live-test checklist: `work/docs/handoff-api-backend-2026-09-02.md` there.
- **API-002 — operator alerting**: redesigned per AXO3's alarm handoff (client-triggered, edge-based) and **built framework-side 2026-09-02**: `Z77\Shared\Alert\AlertService` + channels ([`alert.md`](alert.md)). Remaining: wire the zihlundsee revalidation caller into it (with API-005) and build AXO3's `last_used_at` aging watch (AXO3 project) — both REQUIRED before go-live.
- **API-003 — contract test for v1**: a test pinning the envelope (status codes, error codes, payload field list) so a deploy cannot change it silently; part of the freeze after the zihlundsee pilot.
- **API-004 — emonitor adapter service** (AXO3): `UnitsService` is PropBase-only; emonitor tenants need their own adapter (sequencing step 4), response stays upstream-neutral.
- **API-005 — site client swap** (z77/propbase → generic snapshot client): strip token/credentials handling, add the axo3 transport (Bearer + If-None-Match/304); store + TTL revalidation stay. Pilot: zihlundsee. Handoff: `work/docs/handoff-api-client-2026-09-02.md` in the zihlundsee project — including the runtime media/document-URL question that MUST be settled before stripping the client.
- **API-006 — packaging**: `z77/module-api` exists only in the monorepo + AXO3 path repo; split repo, workflow matrix entry, and Packagist registration per [`packaging.md`](packaging.md) before any non-local deploy.
