# Review — framework api module + axo3 as data broker

**Date:** 2026-09-02
**Basis:** [`api-module-handoff-2026-09-02.md`](api-module-handoff-2026-09-02.md) + discussion with Peter (this session)
**Status:** design review before build. Nothing implemented yet.

---

## Goal (agreed flow)

1. A client site wants to display unit data (snapshot pattern, SSR).
2. The client site calls axo3 — `GET /api/v1/units` with a bearer key — **never in
   the hot path**: it renders from its local snapshot and revalidates in the
   background (TTL + `curatedHash`, 204 on unchanged). This is the outage
   protection; it stays exactly as it is today.
3. On axo3 the request lands in the **framework's api module**: key auth →
   tenant, then dispatch to a **declared service**.
4. The service (project-side, in `override/` or a library) fetches the data —
   from PropBase, emonitor, or any other upstream — served from axo3's own
   per-tenant snapshot, not live.
5. The service returns a defined response object; the api module serializes and
   delivers it as JSON.

## Architectural refinement vs. the handoff

The handoff said "the api module is a z77 module **on axo3**". This session
sharpened that:

- **The api module lives in the framework** (a kernel package, like
  module-backend). It is generic and slim: versioned routing under `/api`,
  key auth, rate limiting, dispatch, JSON delivery. It knows nothing about
  PropBase or emonitor.
- **Services live outside the framework.** The project (axo3) declares in its
  `override/` config which service class answers which endpoint. The service
  implementations (PropBase adapter, emonitor adapter) are project code /
  framework-agnostic libraries.
- Consequence: **any** z77 project can offer an API by declaring services —
  the broker role of axo3 is configuration, not framework code.

This is CE-first as designed: framework = mechanism, `override/` = declaration
and implementation.

### The service contract

The framework must define the interface the dispatch is typed against.
Sketch (names to be settled during build):

```php
interface ApiService
{
    /** @param ApiRequest $request  tenant, endpoint params, client hash */
    public function handle(ApiRequest $request): ApiResult;
}
```

- `ApiResult` carries either a payload (array, becomes the JSON body) or
  "unchanged" (becomes 204). The api module owns status codes, headers,
  and error rendering — a service never touches `header()`.
- **The freeze applies per service payload, not to the module.** The api
  module's envelope (auth, versioning, 204 semantics) is trivial and frozen
  from day one; the units payload is frozen once the zihlundsee pilot runs
  against it. With exactly one consumer today, that freeze is cheap.

## What the framework already has (handoff corrections)

The handoff lists three extensions. Checked against the current codebase:

1. **API-key guard — still needed.** `AccessGuard` is session-based; a bearer
   key → tenant lookup with no session start does not exist. New guard,
   framework-side. Key storage/administration is axo3 project code (backend
   UI), the guard only verifies.
2. **`JsonResponse` — already exists** (`packages/kernel/core/src/Http/Response/JsonResponse.php`,
   helper `$this->json()`). What is actually missing is not the class but the
   **context**: the fetch.md rule "MUST NOT return raw JsonResponse from
   controllers" targets browser fetch endpoints (envelope contract) and must
   be scoped so API actions are exempt — the API returns plain JSON, no
   envelope, no flashes.
3. **"No layout, no locale/navigation redirect" flag — needed, but the
   mechanism likely already exists: a reserved route.** `/api` as a
   `reservedRoutes` prefix is matched before language extraction issues,
   before NavigationAlias, before the Fetch short-circuit — mode-independent
   (routing.md, ADR-017 R3). That matters because a server-to-server request
   sends no `Sec-Fetch-Mode` header and would otherwise land in **Page mode**
   and walk through navigation lookup and locale logic. Open sub-questions
   for the build: session start suppression, locale-redirect suppression, and
   whether reserved-route targets can carry a "stateless" marker — or whether
   the key guard replacing the session guard is already sufficient.

Also already present and reusable: `var/lib` throttle counters (rate
limiting), GeoGuard's one-line-per-request logging philosophy, the
site-side snapshot client with hash revalidation.

## Decisions from this session

| Point | Decision |
|---|---|
| Outage alerting | Signal (email/push) to an operator — **on axo3** (where upstream failures are observed), not on the client sites. Part of the module-api build, defined before go-live. |
| Contract freeze | The api module envelope is frozen immediately (trivial). The units payload contract is frozen after the zihlundsee pilot validates it. Only 1 consumer exists today — low risk. |
| Key rotation | To be designed together with the key guard (before it is built). Candidate: two active keys per tenant during rotation. |
| Key transport (decided 2026-09-02) | `Authorization: Bearer <key>` — the standard. Shared hosting strips `HTTP_AUTHORIZATION` under CGI/FastCGI, so the api module docs MUST ship the pass-through rule (`SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` / `CGIPassAuth on`) and `/api/v1/health` doubles as the transport self-test (reachable key-auth proves the header arrives). No custom header. |
| Key storage (decided 2026-09-02) | Framework defines a `TenantKeyResolver` interface, declared via config (same pattern as `authBridges`); implementation is project code. Keys are random ≥32 bytes, stored **hashed** (SHA-256 — keys are high-entropy, no KDF needed), compared via `hash_equals`. Plaintext is shown once at creation in the backend UI. |
| Guard errors (decided 2026-09-02) | Missing/unparseable header → 401 + `WWW-Authenticate: Bearer`; unknown/revoked key → 401 (no oracle distinguishing "unknown" from "revoked"); valid key but endpoint/dataset not allowed → 403; throttled → 429 + `Retry-After`. Every guard response is JSON `{"error": {"code": "...", "message": "..."}}` with `Cache-Control: no-store`. Machine-readable `code` is frozen envelope surface; `message` is not. |
| Module placement | api module = framework package. Services = project/`override` + libraries. |
| Language handling | Framework locale logic (URL prefix, session, redirects) is skipped on the API path. Language is an explicit contract parameter (`lang`) passed through to the service — required for widget delivery. `/units` delivers ALL languages in ONE bundle (one snapshot, one hash, one revalidation; site renders de/fr from the same stand). Note: today's `UnitData` is single-language plain strings — multilingual payload shape is part of the v1 contract work. |

## Sequencing (updated)

1. Framework: key guard (incl. rotation design), `/api` reserved route +
   statelessness, `ApiService` interface + dispatch, scoped JsonResponse rule.
   **Built 2026-09-02** — stateless branch in `pullUp()`,
   `RequestGuardInterface`, `ApiKeyGuard`, `TenantKeyResolverInterface`,
   `Request::isStateless()`/`getBearerToken()`, reserved-route `stateless`
   flag, ExceptionHandler JSON envelope on stateless routes
   (`tests/api-stateless-guard.php`, 27 checks); service contract
   `ApiServiceInterface`/`ApiRequest`/`ApiResult` (kernel/shared),
   `ApiResponder` (ApiResult → wire: 200/304/error, ETag, no-store,
   Retry-After), `JsonResponse` 304 + `omitBody()` (HEAD), and the throttle
   extraction `FileThrottle` (kernel/shared; atomic increment, `retryAfter()`;
   `MemberThrottle` delegates, API unchanged)
   (`tests/api-contract-throttle.php`, 28 checks). The endpoint→service
   dispatch itself is module-api code (step 2) — the framework now carries
   everything it needs.
2. axo3 `override/`: service declaration + PropBase adapter service +
   per-tenant snapshot store + backend UI (tenants/keys/log) + operator alert.
   **Largely built 2026-09-02:** `z77/module-api` package (gateway, service
   registry, built-in health, request log) + AXO3 override (`ApiKeyResolver`
   reading hashed keys from `data/project/axo3/api/keys.json`, `UnitsService`
   serving the per-tenant snapshot passthrough with `meta.hash` as ETag).
   End-to-end verified locally against the real tenant-8 snapshot (141 units):
   401/200/304/404/405/HEAD all per envelope, no Set-Cookie on the API path,
   stateful site untouched. Still open: backend UI (API-001), operator alert
   (API-002), contract test (API-003) — tracked in `docs/topics/api.md`.
3. Site client: strip `z77/propbase` to the generic snapshot client, pilot
   zihlundsee. Freeze v1 units payload.
4. emonitor adapter service; migrate the 7 emonitor sites step by step.

## Open points

- Exact v1 units payload (start from propbase `SnapshotData`); freeze after
  pilot. Multilingual shape needed (today's `UnitData` is single-language).
- ~~Key format + rotation procedure~~ → decided, see
  [`api-envelope-v1-2026-09-02.md`](api-envelope-v1-2026-09-02.md) (transport
  contract: auth, rotation, status codes, error shape, versioning, 304
  revalidation, request log).
- One dataset per tenant vs. named datasets.
- Where the generic site client lives (rename `z77/propbase` vs. new package).
- Reserved-route statelessness: flag on the route target vs. guard-level
  decision (decide in step 1, whichever is smaller).

## Residual risks (unchanged from handoff)

- Fresh site install needs axo3 online for the first snapshot. Accepted.
- axo3 becomes production-critical for ~8+ sites over time: contract test for
  the v1 payload + uptime monitoring are part of step 2, not an afterthought.

---

## Critical review findings (adversarial agent pass, 2026-09-02)

An independent review agent checked this document against the handoff and the
actual kernel sources. Verdict: the architecture split (framework api module +
services declared in `override/`, precedent: `authBridges`) is sound, but two
structural gaps were underestimated. Condensed findings; severity in brackets.

### Blockers — statelessness is the hardest part, not a sub-question

1. **The session starts unconditionally for every routed request.** Resolving
   the Dispatcher factory at the end of `Bootstrap::pullUp()` constructs
   `AccessGuard` → `SessionManager`, whose constructor calls `session_start()`
   immediately — before any guard logic. `PageCachePolicy` and
   `applyLanguageSession()` also touch the session. "Key guard replaces the
   session guard" is NOT sufficient; step 1 needs a **stateless branch** in
   `pullUp()`/DI wiring, decided by the matched reserved-route target (known
   before the session block). bootstrap.md's rule "session start after
   routing" becomes "after routing, or never for stateless targets".
   **Decided 2026-09-02:** the branch point is in `pullUp()` between
   `ControllerHandler::lock()` and the SessionManager registration block —
   route fully known, nothing session-bound constructed yet. The existing
   path stays byte-identical; `/api` takes the stateless wiring.
2. **No guard seam exists.** `Dispatcher::execute()` hard-codes
   `accessGuard->enforce()`, and its global Fetch+POST CSRF check runs first —
   a browser-side API POST with a valid bearer key would die at the session
   CSRF check. Step 1 must design the pipeline branch point and a per-route
   enforcement matrix (reserved+stateless → key guard only, no CSRF, no
   locale redirect).

### Should-fix — parts of the frozen envelope do not exist on paper yet

3. **Error rendering:** API requests land in Page mode (no `Sec-Fetch-Mode`
   header) → `ExceptionHandler` renders **HTML** error pages to JSON clients,
   and its JSON branch leaks stack traces when `display_errors` is on.
   Reserved/api routes need forced `format='json'` and a defined error body.
4. **Bearer transport:** `Request` has no `Authorization` accessor, and shared
   hosting (Apache CGI/FastCGI — cyon, hostpoint) strips `HTTP_AUTHORIZATION`
   without explicit pass-through config. Decide: `Authorization: Bearer` +
   documented `.htaccess`, or a custom `X-Api-Key` header.
5. **Throttle counters are NOT "already present":** the only implementation is
   `MemberThrottle` in module-member (modules depend downward on kernel only)
   — reuse means extraction into kernel/shared, plus fixing its non-atomic
   read→write and defining 429/`Retry-After` semantics.
6. **Key-guard ↔ tenant-store seam undefined:** how does a framework guard
   read project-owned keys? Define an `ApiKeyResolver` interface declared via
   config (same pattern as `authBridges`); keys hashed at rest, compared with
   `hash_equals`.
7. **"Envelope frozen from day one" is contradictory:** 401/403 bodies, the
   rate-limit response, the error shape, unknown-version behavior, and key
   rotation are all part of the envelope and all undesigned. Freeze after
   they are specified on one page — still before the pilot.
8. **`ApiResult` has no error channel** ("dataset unknown", "snapshot not yet
   built") — add explicit error variants or a typed `ApiException` hierarchy
   the module renders as JSON; part of the frozen envelope.

### Notes — corrections and smaller decisions for step 1

9. Packaging: the api module is a **new package `z77/module-api`** (kernel is
   Core/Shared/Persistence only, ADR-023) — new split repo, workflow matrix,
   Packagist entry; generic-mechanism fixes then ride the framework release
   train, not an axo3 deploy (partial re-import of the deploy coupling the
   handoff argued against — accepted, since services stay project code).
10. The plan reverses the documented stance "z77 is not an API backend"
    (`Request.php` comment, routing.md) — revise both when building; api
    actions must carry no `#[Fetch]`/`#[Page]` mode attribute (mode-agnostic).
11. Page cache: the reserved route maps all of `/api/*` to ONE 4-tuple while
    the tenant is a header — with caching enabled all tenants would share one
    entry (the D2 tuple collision). Declare page caching disabled explicitly
    in the api module config; do not rely on the response-type check.
12. `JsonResponse` always echoes — HEAD on `/api/v1/health` is unhandled;
    decide HEAD semantics (monitoring tools HEAD health endpoints).
13. Mandate `Cache-Control: no-store` on every API response (per-tenant
    payloads behind one URL must never be intermediary-cached).
14. Precision corrections to this document: language extraction runs BEFORE
    `matchReserved` (harmless for `/api` — 3 chars ≠ language segment), and
    `Dispatcher::resolveResponse()` still runs `resolveNavigation()` on every
    fresh render — dead overhead the stateless branch should skip.
15. Open items neither doc carried: auth for `/api/v1/health` (keyed or
    public), where the one-line request log writes, one owner for the error
    shape (`ExceptionHandler` JSON differs from the envelope today).
