# routing

2026-06-23

## entry

1. `packages/kernel/core/src/Http/Request.php` — URL parsing, language extraction, `runParsing()`
2. `packages/kernel/core/src/Routing/Router.php` — thin wrapper for navigation lookup
3. `packages/kernel/core/src/Controller/ControllerHandler.php` — controller/action resolution + `lock()`

## file map

SOURCE=/packages/kernel/core/src/Routing/Router.php
SOURCE=/packages/kernel/core/src/Http/Request.php
SOURCE=/packages/kernel/core/src/Http/RequestMode.php
SOURCE=/packages/kernel/core/src/Controller/ControllerHandler.php

## mental model

Routing runs once in `Bootstrap::pullUp()`. `Request::runParsing()` extracts the language prefix, then resolves the route with precedence **Reserved Routes → NavigationAlias → static navigation → convention** (ADR-015 + ADR-017 R3). Reserved routes are matched FIRST (before the localized-slug translation and before the Fetch short-circuit); the rest run after translation, Page mode only. On an alias or reserved hit the trailing path segments are captured as content slugs (`getSlugs()`). On hit: module/group/controller/action come from the matched route — no cascade. On miss or in Fetch mode: convention fallback maps URL segments positionally over 4 segments. `ControllerHandler` receives the resolved state and MUST be locked via `lock()` before `Dispatcher` runs.

- **Reserved Routes (highest precedence, ADR-017 R3)** are structural, framework-owned URL prefixes declared per module (`reservedRoutes` config key) and aggregated by `ModuleManager::getReservedRoutes()`. A prefix maps straight to a routing target (4-tuple); the trailing path becomes content slugs. They are matched **mode-independently** — before the Fetch short-circuit — because a reserved URL like `/media` is reached via `<img src>` (`Sec-Fetch-Mode: no-cors` → Fetch mode), not only by browser navigation; routing them only in Page mode would 404 embedded media. They are matched on raw post-language segments (folder/file slugs are structural, never language-translated). Used for delivery URLs that must NOT depend on a navigation/alias seed. A prefix declared by two modules is a fail-fast config error.

- URL schema is 4 segments: `/module/group/controller/action` (since 2026-05-19, see ADR-005).
- Fetch mode always skips navigation lookup → uses convention routing directly.
- Per-controller `defaultAction` (since 2026-05-04): each controller defines its own default in module config.
- Per-group `defaultController` (since 2026-05-19): each group declares its default controller in `groupDefaults`.
- `ControllerHandler::has*()` methods both validate AND set state (CQS trade-off by design).
- Old 3-segment URLs throw `NotFoundException` — strict, no redirect layer (see ADR-005).
- **NavigationAlias is the public URL layer (ADR-015).** A `NavigationAlias.path` maps a clean URL to a navigation; the path remainder after the matched alias is captured as **content slugs** and passed to the action via `Request::getSlugs()`. The old per-entry `params`/friendly-`url` mechanism was removed (Phase 4). See [`navigation.md`](navigation.md#navigationalias--content-slugs-since-2026-06-08-adr-015).

## flow

```text
Bootstrap::pullUp()
  → Request::runParsing()
      1. extractLanguage()             strip /de/ or /fr/ prefix; throw InvalidRouteException if 2-char invalid lang
      2. matchReserved(segments)       longest-prefix reserved route (ModuleManager::getReservedRoutes)
              HIT  → assign 4-tuple from the reserved target; remainder → content slugs; RETURN
              (runs before translation AND before the Fetch short-circuit — mode-independent)
      3. translateSlugsToCanonical()   localized → canonical segments (non-default language only)
      4a. Fetch mode                   → parsePathSegments() directly (no nav lookup)
      4b. Page mode + segments         → Router::matchAlias(segments)   (longest-prefix NavigationAlias)
              HIT  → assign 4-tuple from alias's navigation; remainder → content slugs
              MISS → Router::match(path) → NavigationService::findByPath()  (static nav, canonical 4-tuple)
                       HIT  → assign 4-tuple from entry
                       MISS → parsePathSegments() (convention fallback)
      4c. Page mode + no segments      → parsePathSegments() (convention fallback → default module)
  → ControllerHandler::lock()          freeze resolved state
```

(Language-session reconciliation runs later, in `Dispatcher::execute()` — see [`i18n.md`](i18n.md).)

## routing strategies

| Strategy | Source | When |
|---|---|---|
| Reserved Route | `reservedRoutes` module config (`path prefix` → 4-tuple, longest-prefix; remainder = content slugs), aggregated by `ModuleManager::getReservedRoutes()` | structural framework-owned delivery URLs, mode-independent (`/media/{area}/…`); resolved before everything else |
| NavigationAlias | `navigation_aliases.json` (`path` → navigation, longest-prefix; remainder = content slugs) | friendly/clean URLs (`/home`, `/login`), dynamic detail URLs (`/schweiz/stadt/basel`) |
| Static navigation | `navigation.json` (canonical 4-tuple path) | direct canonical hit (`/frontend/main/index/home`) |
| Convention fallback | URL segments positional (4 segments) | alias + nav miss, Fetch mode, default routes |

Nav-Hits use `assignModule` + `assignGroup` + `assignController` + `setAction` — no cascade. All four values come from the matched navigation. The canonical path is built on-the-fly in `Navigation::getCanonicalPath()`; the public URL is `NavigationService::urlFor()` (alias-aware).

## convention fallback (4 segments)

```text
/module/group/controller/action  → 4 segments, exact (assign + setAction, no cascade)
/module/group/controller         → controller's defaultAction (cascade)
/module/group                    → groupDefaults[group] + that controller's defaultAction (cascade)
/module                          → defaultGroup → groupDefaults[defaultGroup] + defaultAction (cascade)
/                                → defaultModule + defaultGroup + groupDefaults[defaultGroup] + defaultAction (cascade)
```

`parsePathSegments` has two branches (since 2026-05-20):

- **4 or more segments** → `assignModule` / `assignGroup` / `assignController` / `setAction`. No default cascade — explicit values from the URL win. Extra segments beyond index 3 are silently ignored.
- **0–3 segments** → `setDefaults()` then positional `setModule` / `setGroup` / `setController`. Each `set*` cascades into its next level's default (e.g. `setController` resolves `defaultAction` if no segment 3 follows).

Cascade order for partial URLs (positional segments 0–2):

```text
0 → setModule         module key      (cascades → setGroup(defaultGroup))
1 → setGroup          group key       (cascades → setController(defaultController))
2 → setController     controller key  (cascades → setAction(defaultAction))
```

- Segment containing `.` (e.g. `/favicon.ico`): throws `FileNotFoundException`.
- Old 3-segment URLs (`/backend/login/login`, `/backend/navigation/list`): `NotFoundException` — no fallback to "treat segment 1 as controller in default group".
- Before 2026-05-20 the 4-segment branch also cascaded — `setController` would resolve a default action even when segment 3 was about to set an explicit one, causing premature `NotFoundException` for POST-only controllers (e.g. `SystemController::clearCacheAction`).

## per-group defaultController

`setGroup()` resolves the default controller in this order:
1. `groupDefaults[$group]` in module config — required for every declared group
2. `NotFoundException` if the group is not declared

```php
'defaultGroup'   => 'system',
'groupDefaults'  => [
    'system'  => 'dashboard',
    'content' => 'navigation',
    'users'   => 'user',
],
```

## per-controller defaultAction

`setController()` resolves default action in this order:
1. `controllers[$group][$Controller]['defaultAction']` in module config (controller-level, group-nested)
2. Module-level `defaultAction` (legacy fallback, used by frontend module's wildcard pattern)
3. `NotFoundException` if neither defined

The `controllers` map is nested by group (mirrors the controller namespace) so
base names only need to be unique within a group — see `backend.md` AUTH-B002 +
ADR-005 (revised 2026-06-02).

```php
'controllers' => [
    'system' => [
        'LoginController'     => ['defaultAction' => 'login',    ...],
        'DashboardController' => ['defaultAction' => 'overview', ...],
        'SystemController'    => [                                ...],   // no defaultAction — POST-only
    ],
    'content' => [
        'NavigationController' => ['defaultAction' => 'list', ...],
    ],
]
```

## language extraction

- First path segment, exactly 2 alpha chars and a valid language → extracted, removed from segments.
- Exactly 2 chars but not valid → `InvalidRouteException` (prevents `/de` silently matching module "de").
- Default language: `de`.

## Request public API

```php
runParsing(): void          main entry — call once in Bootstrap::pullUp()
getModule(): string
getGroup(): string
getController(): string
getAction(): string
getSlugs(): list<string>    content slugs captured after a NavigationAlias match (ADR-015); [] otherwise / Fetch / convention
getLanguage(): string
getMethod(): string         lowercase: 'get', 'post', 'head', ...
isGet(): bool
isPost(): bool
isHead(): bool
isReadMethod(): bool        true for GET and HEAD
getMode(): RequestMode
hasQueryString(): bool
getGetParameter(string $key): mixed
getPostParameter(string $key): mixed
getIfNoneMatch(): ?string   raw If-None-Match header value
getRoutingLog(): array      ordered log of routing decisions (debug)
```

## ControllerHandler

```php
hasController(string $module, string $group, string $controller): bool   // validates AND sets currentModule + currentGroup + currentControllerClassName
hasAction(string $action): bool                                          // validates AND sets currentActionMethod
lock(): void                                                             // freeze after routing — must call before Dispatcher
getCurrentModule(): string                                               // throws LogicException if not yet resolved
getCurrentGroup(): string                                                // throws LogicException if not yet resolved
getCurrentControllerClassName(): string                                  // throws LogicException if not yet resolved
getCurrentActionMethod(): string                                         // throws LogicException if not yet resolved
getCurrentControllerInstance(): object                                   // lazy — creates instance on first call
```

Safe to call `get*` only after `lock()`. The `group` argument feeds `Naming::toControllerClassName($prefix, $group, $controller)` — empty `$group` returns the flat-namespace form (used by genuinely flat modules; see ADR-005).

## RequestMode (enum)

| Mode | Trigger |
|---|---|
| `Page` | regular browser navigation (`Sec-Fetch-Mode: navigate` or absent) |
| `Fetch` | AJAX/partial request (any other `Sec-Fetch-Mode` value) |

`Fetch` mode skips navigation lookup → straight to convention routing. Accept-header fallback intentionally removed (z77 is not an API backend).

## action constraints (attributes)

Actions can declare their expected `RequestMode` and HTTP method via PHP attributes on the action method. The `Dispatcher` reads them via Reflection BEFORE invoking the action and throws `NotFoundException` on violation.

| Attribute | Effect |
|---|---|
| `#[Fetch]` | Action requires `RequestMode::Fetch`. Page-mode (browser navigation) → 404. |
| `#[Page]` | Action requires `RequestMode::Page`. Fetch-mode (AJAX call) → 404. |
| `#[HttpMethod('POST', ...)]` | Action accepts only listed methods (variadic). Other methods → 404. |

Attributes are **opt-in**. Without any attribute the action handles dispatch itself (e.g. `LoginController::loginAction` branches on `isPost()` internally).

```php
use Z77\Shared\Attributes\Fetch;
use Z77\Shared\Attributes\HttpMethod;

#[Fetch, HttpMethod('POST')]
protected function clearCacheAction(): FetchResponse { ... }
```

Enforcement point: `Dispatcher::enforceActionConstraints()`. Error rendering: `ExceptionHandler::handle()` reads the `RequestMode` and serves HTML 404 for Page or JSON 404 for Fetch (`format='auto'` default).

## exceptions thrown during routing

| Exception | When |
|---|---|
| `NotFoundException` | module, controller, or action not found |
| `FileNotFoundException` | path segment contains `.` (static file hit) |
| `InvalidRouteException` | 2-char segment that is not a valid language code |

All caught in `Bootstrap::pullUp()` → forwarded to `ExceptionHandler::handle()`.

## rules

- When calling any `ControllerHandler::get*()` method → MUST call `lock()` first (LogicException otherwise)
- When registering a controller in module config → MUST set `group` to the URL-group key and MUST define `defaultAction` (NotFoundException on convention URL otherwise; frontend wildcard MAY use module-level `defaultAction` instead)
- When declaring a group in module config → MUST list it in `groupDefaults` with its default controller (NotFoundException on convention URL otherwise)
- When constructing a backend URL in templates or JS → MUST use the 4-segment form `/{module}/{group}/{controller}/{action}` (e.g. `/backend/system/login/login`); MUST NOT use the old 3-segment form
- When registering a framework-owned structural delivery URL (e.g. `/media`) → MUST declare it as a reserved route (`reservedRoutes` in the module config, prefix → 4-tuple); MUST NOT seed a phantom navigation node + alias for it. The prefix MUST be unique across all modules (fail-fast otherwise)
- When handling a Fetch request → MUST skip navigation lookup; MUST use convention routing. Reserved routes are the exception — they are matched before the Fetch short-circuit (a reserved URL reached via `<img src>` is Fetch mode), so a reserved delivery URL MUST NOT rely on Page mode
- When a path segment contains `.` → MUST throw `FileNotFoundException` (static file detection)
- When an action accepts only a specific HTTP method or mode → SHOULD declare it via `#[Fetch]` / `#[Page]` / `#[HttpMethod(...)]`; MUST NOT duplicate the check with `if (!isPost())` inside the action

## see also

- [`../02-decisions/adr-005-module-architecture-and-url-grouping.md`](../02-decisions/adr-005-module-architecture-and-url-grouping.md) — full rationale for the 4-segment URL schema and group-aware namespace mapping
- [`../02-decisions/adr-006-action-constraints.md`](../02-decisions/adr-006-action-constraints.md) — attribute-based mode/method constraints for actions (2026-05-20)
- [`../02-decisions/adr-015-navigation-alias-and-content-slugs.md`](../02-decisions/adr-015-navigation-alias-and-content-slugs.md) — NavigationAlias precedence, content slugs, the model behind the alias-first inbound flow
- [`navigation.md`](navigation.md) — NavigationAlias + content-slug details, `matchAlias`/`urlFor`

## known issues

- **ROUTE-001** — resolved 2026-05-20. `parsePathSegments` always cascaded through `set*` methods even with 4 explicit segments, causing premature `defaultAction` resolution. Symptom: `/backend/system/system/clear-cache` (Fetch POST) threw `NotFoundException: No default action defined for controller: system`. Fixed by branching at 4-segment boundary — `assign*` + `setAction` without cascade.
- **ROUTE-002** — resolved 2026-05-20. POST-only actions checked `isPost()` internally and returned a `FetchResponse` with error status — on browser GET this rendered raw JSON. Replaced by declarative `#[Fetch, HttpMethod('POST')]` attributes, enforced in `Dispatcher::enforceActionConstraints()` BEFORE the action runs; `ExceptionHandler` renders HTML 404 in Page mode or JSON 404 in Fetch mode.
- **ROUTE-003** — resolved 2026-05-21, _superseded 2026-06-08 (ADR-015)_. Added `params` subset matching for same-route siblings. **Removed in Phase 4:** `params` is gone; same-route discrimination is now a content slug + alias path. `findByPath`/`resolveCurrent` match the bare 4-tuple; `$_GET` is no longer appended for routing.
- **ROUTE-RESERVED-001** — resolved 2026-06-23 (ADR-017 R3). Added the **Reserved-Route tier** as the highest routing precedence (`Request::matchReserved`, longest-prefix over `ModuleManager::getReservedRoutes()`), matched before NavigationAlias / static nav / convention AND before the Fetch short-circuit. `/media` moved from a phantom navigation node (id 26) + NavigationAlias (id 8) to a `reservedRoutes` entry; node 26 + alias 8 removed from the seeds (`packages/kernel/core/data/framework/routing/navigation*.default.json`) and the live skeleton. Since R4c the route is declared in `module-dms` (`dmsConfig`, `/media` → `dms/media/output/serve`) and served by `OutputController` (it was briefly `frontend/media/media/serve` + `MediaController` in R3, both now removed). The mode-independence is the point: an `<img src="/media/…">` request is Fetch mode, which previously skipped alias/nav lookup and 404'd via convention. Verified via throwaway CLI smoke (14 checks: `/media/foo/bar.pdf` resolves identically in Page + Fetch mode with slugs `[foo, bar.pdf]`, bare `/media` resolves with empty slugs, non-reserved `/nope/x` falls through to a `NotFoundException`, aggregated map exposes `/media`). **After deploying this change the APCu/page cache must be cleared** (the nav/alias JSON was edited directly, not through the entity manager, so auto-invalidation does not fire).
- **ROUTE-ALIAS-001** — resolved 2026-06-08 (ADR-015). Inbound routing gained **NavigationAlias precedence** (`Router::matchAlias`, longest-prefix) before static-navigation `findByPath` and convention fallback. The matched alias supplies the 4-tuple; trailing segments are captured as content slugs (`Request::getSlugs()`). Verified: `/home/alpha/beta` → home action with slugs `[alpha,beta]` (was 404 via convention). See `navigation.md` NAV-ALIAS-001. _(Since 2026-06-09 the longest-prefix matcher lives in `NavigationUrlResolver`; `Router::matchAlias` resolves its `navigationId` to a `Navigation` — see `navigation.md` NAV-RESOLVER-001.)_

## pending

- **ROUTE-DYN-001** — dynamic friendly detail routes (`/schweiz/stadt/basel` → one action that resolves the entity). **Design + core implemented via ADR-015 (NavigationAlias + content slugs), NOT the earlier `UrlAlias` sketch** (that direction was rejected — see ADR-015 Rejected Alternatives). The matcher (`matchAlias`, longest-prefix), content-slug capture (`getSlugs`), and outbound (`urlFor`) shipped in Phases 1–4 (navigation.md NAV-ALIAS-001). The remaining pieces below are **decided** (forks D2–D4, recorded in ADR-015 §D2–D4) but **deferred until the first real detail-page controller exists** (decision 2026-06-09 — no cache/metadata infrastructure on spec without a consumer). They will be built ad-hoc alongside that controller, on the decided designs:
  - **PageCache collision (D2):** `PageIdentity` keys HTML by the 4-tuple, not the URL → detail URLs sharing one action collide. Decided: cache key becomes `(language, localized URL path)`; invalidation per-URL + `clearAll()` on deploy.
  - **Entity-slug translation scaling (D3):** `bale↔basel` must come from an **indexed** store (fed by entities), not flat `route-slugs.{lang}.json`; the `SlugTranslator` normalizes all segments to canonical, the action stays language-agnostic.
  - **Metadata from entity (D4):** detail pages set title/description at runtime from the entity (override hook), not navId.

  (The **NavigationUrlResolver extraction** — Phase 5, point 4 — is done; see `navigation.md` NAV-RESOLVER-001.)

  See [`navigation.md`](navigation.md) (alias model), [`translation.md`](translation.md) (segment translation), [`metadata.md`](metadata.md) (navId-keyed metadata + the entity path).
