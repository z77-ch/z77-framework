# cache

2026-09-01

## entry

1. `packages/kernel/core/src/Libraries/CacheManager.php` — facade exposing `data()` + `page()`
2. `packages/kernel/core/src/Libraries/Cache/DataCache.php` — APCu-backed key-value store
3. `packages/kernel/core/src/Routing/PageCachePolicy.php` — decides if a response can be cached

## file map

SOURCE=/packages/kernel/core/src/Libraries/CacheManager.php
SOURCE=/packages/kernel/core/src/Libraries/Cache/DataCache.php
SOURCE=/packages/kernel/core/src/Libraries/Cache/PageCache.php
SOURCE=/packages/kernel/core/src/Routing/PageCachePolicy.php
SOURCE=/packages/kernel/core/src/Routing/PageCacheDecision.php
SOURCE=/packages/kernel/core/src/Routing/PageCachePolicyMode.php
SOURCE=/packages/kernel/core/src/Http/Response/CacheMode.php
SOURCE=/packages/kernel/core/src/Http/Response/PageCacheStatus.php

## mental model

Two independent subsystems behind one facade. `DataCache` is an APCu-backed key-value store for application data; `PageCache` is full-page HTML on disk. `CacheManager` exposes both via `data()` and `page()`.

- `DataCache` batches writes into a local array and flushes to APCu once at request end via `flush()`.
- `PageCache` is auto-skipped in DEBUG, for admin sessions (role >= ADMIN), for non-GET/HEAD, for query strings, and in Fetch mode.
- Storing `null` in `DataCache` is forbidden — indistinguishable from a miss.
- `cachePersist` is always `false` in bootstrap config.

## DataCache

```php
$cache->get('ClassName', ['k1','k2']);             // null = miss
$cache->set('ClassName', ['k1'], $val, cachePersist: true);
$cache->flush();                                    // writes APCu, called at request end
$cache->clear('ClassName');
```

Key format: `Z77-apcu-pool-{hash}::{ClassName}::{k1}::{k2}` — `{hash}` = first 12 hex of `md5(ABS_BASE_PATH)`, one pool per installation AND per release (`ABS_BASE_PATH` is the resolved `releases/<name>`)
Layers: local array → APCu

### cross-process invalidation (the stamp)

APCu is per process tree: PHP-FPM has one pool, every CLI run (cron, `z77-run`, installer, harness) has its own that dies with the process. `apcu_delete()` from the CLI never reaches the FPM pool. The bridge is a file both sides see: `var/cache/apcu.stamp` — **release-local** since ADR-035, and that is correct rather than a compromise: web and cron resolve the same `ABS_BASE_PATH` (both reach the release through a symlink, both end at `releases/<name>`), so they share the stamp that matters, while a cron on the staging release no longer wipes production's pool. It rests on the cron entry going through `current`, which `release-structure.md` makes binding for other reasons already.

- `clearAllApcu()` wipes the caller's pool **and** touches the stamp — strictly monotonic (`max(now, mtime+1)`), because `filemtime` has one-second resolution.
- `CacheManager::setCacheDir()` (boot) calls `DataCache::setStampPath()`, which compares the stamp's mtime with the mtime stored under `{pool}::__stamp`. Different → the pool is wiped and re-marked. One `filemtime()` per request.
- Reads before `setCacheDir()` (bootstrap config, `FileFinder::config`) are served from the old pool once; those are config files, not entity data, and are refreshed by the wipe on the same request for everything loaded afterwards.
- Missing stamp (fresh install, `lib/` wiped per ADR-034) = mtime 0 → one wipe, then normal.
- Harness: `php -d apc.enable_cli=1 tests/apcu-stamp.php` — the "cron" is a real child process with its own pool.

## PageCache

| Aspect | Detail |
|---|---|
| Skip conditions | DEBUG, session role >= ADMIN (CACHE-ADMIN-001), non-GET/HEAD, query string, Fetch mode, module policy `enabled=false`, `ttl <= 0` |
| ETag | `filemtime` of cache file |
| Path | `var/cache/pages/{lang}/{module}/{group}/{controller}/{action}.html` — inside the release (ADR-035), never shared |
| Failure | Dispatcher wraps `PageCache::set()` in try/catch — write failure must not kill request |
| Diagnostic header | `X-Z77-PageCache: HIT \| MISS \| BYPASS` on every 200 response (304 omits — status code is the signal) |

### decision flow

`PageCachePolicy::decide()` is the single source of truth — it returns one of three outcomes. The Dispatcher only executes them.

```text
PageCachePolicy::decide($request)
  │
  ├─ DEBUG=true                                              → NewPage  (BYPASS)
  ├─ session role >= ADMIN                                   → NewPage  (BYPASS)
  ├─ !GET && !HEAD                                           → NewPage  (BYPASS)
  ├─ hasQueryString()                                        → NewPage  (BYPASS)
  ├─ RequestMode::Fetch                                      → NewPage  (BYPASS)
  ├─ ModuleManager::getCachePolicy(): !enabled || ttl<=0     → NewPage  (BYPASS)
  │
  ├─ PageCache::getMtime() + If-None-Match matched           → PageFromClientCache  (304, no body)
  └─ otherwise                                               → PageFromCache        (HIT or MISS)

Dispatcher::resolveResponse(decision)
  │
  ├─ PageFromClientCache  → HtmlResponse::notModified($etag)      CacheMode: NotModified
  │                          (no X-Z77-PageCache header — 304 is the signal)
  │
  ├─ PageFromCache + PageCache::get() returns response → HIT       CacheMode: ServerCached
  │                          setCacheStatus(Hit)
  │
  ├─ PageFromCache + get() returns null → render + tryStore() → MISS  CacheMode: ServerCached
  │                          setCacheStatus(Miss)
  │                          tryStore() throws → fallback BYPASS
  │
  └─ NewPage              → render + setCacheMode(NoStore)         CacheMode: NoStore
                            setCacheStatus(Bypass)
```

### X-Z77-PageCache values

| Value | Meaning | Triggered by |
|---|---|---|
| `HIT` | Body loaded from PageCache file | `PageFromCache` + `PageCache::get()` returned a response |
| `MISS` | Cache miss → rendered fresh and stored | `PageFromCache` + `get()` was null, fallthrough to render + `tryStore()` |
| `BYPASS` | PageCache skipped or write failed | `NewPage` (DEBUG, admin session, POST, query, fetch, policy disabled) OR `tryStore()` threw |

## module config

Three-level cascade: module → controller → action. Each level overrides only the keys it sets. Resolution in `ModuleManager::getCachePolicy()`.

```php
'cache' => ['enabled' => true, 'ttl' => 86400,
    'controllers' => ['ContactController' => ['ttl' => 600,
        'actions' => ['sendAction' => ['enabled' => false]]]]]
```

| Config | Effect |
|---|---|
| missing `cache` key | `enabled=false`, `ttl=0` (default) → always BYPASS |
| `enabled=false` (anywhere in cascade) | BYPASS |
| `enabled=true`, `ttl<=0` | BYPASS (invalid TTL) |
| `enabled=true`, `ttl>0` | cacheable, subject to runtime skip checks |

Current state:
- Frontend module (`frontendConfig.inc.php`): `enabled=true, ttl=86400`
- Backend module (`backendConfig.inc.php`): `enabled=false` → backend pages are always BYPASS

## automatic invalidation on entity writes

Content-relevant entities opt in via the `#[Entity(..., invalidatesCache: true)]` attribute. `FileEntityManager` then clears both `DataCache` (APCu **and** its in-process local tier) and `PageCache` after a successful write (`flush()`, `remove()`, `reorder()`). Auth/log/statistics entities leave the flag at its `false` default and do **not** trigger invalidation.

```php
#[Entity('file', 'framework/routing/navigation.json', invalidatesCache: true)]  // Frontend content
class Navigation { ... }

#[Entity('file', 'framework/auth/backendUsers.json')]                              // no Frontend impact
class BackendUser { ... }
```

Marked today: `Navigation`, `Tag`, `MetaData`. Controllers MUST NOT call `cacheManager->clearAllApcu()` after a save — the entity manager owns that.

## controller-declared freshness (CACHE-FIX-001)

`HtmlResponse` owns the `Cache-Control` header: `sendHeaders()` writes it from
its own `CacheMode`, and the Dispatcher's NewPage branch sets that mode to
`NoStore` **after** the controller returned. Two consequences, both found the
hard way on 2026-08-16 in the AXO3 widget:

- A raw `header('Cache-Control: …')` inside a controller action is a **note to
  nobody**. It is overwritten before the response goes out. The widget carried
  `public, max-age=300` for months and delivered `no-store` the whole time,
  while code comment and spec claimed a five-minute cache.
- Some responses know their freshness better than the routing layer does. A
  server-rendered fragment that depends on stored data wants `public, no-cache`
  plus an ETag, so an unchanged state comes back as a 304 instead of 400 KB.

Therefore `HtmlResponse::fixCacheMode(CacheMode)` — same as `setCacheMode()`,
but it marks the mode as decided, and the Dispatcher then fills in nothing:

```php
return $response->fixCacheMode(CacheMode::ServerCached)->setEtag($stamp);
```

`HtmlResponse::notModified()` marks itself the same way. ⚠️ Without that the
304 turns into an empty **200**, and the browser takes the empty body for the
new content.

The Dispatcher only fills in what nobody claimed:

```php
if ($response instanceof HtmlResponse && !$response->hasFixedCacheMode()) {
    $response->setCacheMode(CacheMode::NoStore)->setCacheStatus(PageCacheStatus::Bypass);
}
```

Nothing changes for a page that says nothing — that is every existing page.

## rules

- When calling `DataCache::set()` → MUST NOT pass `null` as value (indistinguishable from miss)
- When implementing a controller that writes via `PageCache::set()` → MUST be wrapped in try/catch by Dispatcher; write failure MUST NOT kill the request
- When configuring a backend module → MUST set `'cache' => ['enabled' => false]`
- When editing bootstrap config → `cachePersist` MUST be `false` (config changes must take effect without cache clear)
- When an entity's writes must invalidate frontend caches → MUST set `invalidatesCache: true` on its `#[Entity]` attribute; MUST NOT call `cacheManager->clearAllApcu()` from controllers
- When invalidating APCu from anywhere → MUST go through `clearAllApcu()`; MUST NOT call `apcu_delete()`/`apcu_clear_cache()` directly — only `clearAllApcu()` advances the stamp that other process trees see (CACHE-CLI-001)
- When deciding where cache-like state lives → `var/cache` and `var/state` MUST stay release-local; MUST NOT appear in `target.shared`, and MUST NOT be re-introduced as a config value (CACHE-RELEASE-001, ADR-035). `check.php` refuses all three
- When adding a dimension the rendered output depends on (a user role, a release, a tenant) → it MUST be part of the cache key or of a bypass; a `PageIdentity` that does not name it serves one visitor's page to another (three occurrences: CACHE-ADMIN-001, CACHE-CLI-001, CACHE-RELEASE-001)
- When adding a new entity that is NOT rendered into frontend pages (logs, statistics, auth) → MUST leave `invalidatesCache` at its `false` default
- When a controller wants a Cache-Control other than the default → MUST use `fixCacheMode()`, MUST NOT call `header('Cache-Control: …')` (CACHE-FIX-001: the raw header is overwritten by `HtmlResponse::sendHeaders()`)
- When a response carries a session-granted view (an owner preview, a personalised fragment) → MUST stay `NoStore` and MUST NOT answer 304; a shared cache would hand it to the next caller
- When rendering session-dependent content for roles < ADMIN on a cacheable page → MUST NOT: only role >= ADMIN sessions bypass the PageCache (CACHE-ADMIN-001); guest and member renders MUST stay byte-identical. If member-specific markup ever lands on a cacheable page, the bypass MUST be widened to every logged-in session first

## see also

- [`bootstrap.md`](bootstrap.md) — DEBUG flag mechanism (toggling DEBUG flips every page to BYPASS or back)
- [`backend.md`](backend.md) — `SystemController::clearCacheAction()` + `toggleDebugAction()` (both clear APCu + PageCache)
- [`persistence-file.md`](persistence-file.md) — `FileEntityManager` triggers auto-invalidation via `invalidatesCache`
- [`documents.md`](documents.md) — the DMS media-url resolve index (`DocumentService::folderSlugIndex`/`publicPathIndex`, template helper `mediaUrl()`) is a `DataCache` consumer dropped by the DMS `invalidatesCache` writes — no own invalidation

## known issues

- **CACHE-ADMIN-001** — resolved 2026-07-18. `PageCachePolicy` had no user dimension (`PageIdentity` = language/module/group/controller/action), so an admin's cache-miss render was stored into the **shared** PageCache — including the frontend admin overlay (admin name, role, backend URL, route info), served to every visitor for up to TTL. Inverse symptom: with the guest version cached, a logged-in admin got the cached page (server hit or 304) **without** the overlay. Fix: `decide()` returns `NewPage` for session role >= ADMIN, right after the DEBUG check (`AuthService` injected via Bootstrap; session is started by `AccessGuard` before `decide()`). Guest/member caching unchanged — see the byte-identical rule above. Verified via CLI harness matrix (guest/member → `PageFromCache`, admin/superUser → `NewPage`, DEBUG unchanged). Bauplan: [`../03-development/pagecache-admin-bypass-bauplan.md`](../03-development/pagecache-admin-bypass-bauplan.md).
- **CACHE-INV001** — resolved. Stale-content-after-write fixed via `#[Entity(..., invalidatesCache: true)]`. `FileEntityManager` auto-clears `DataCache` + `PageCache` on `flush()`/`remove()`/`reorder()`. Removed 5 duplicated `clearAllApcu()` calls from `NavigationController`. End-to-end verified 2026-05-16.
- **CACHE-INV-002** — resolved 2026-06-29. `DataCache::clearAllApcu()` cleared only APCu (`apcu_clear_cache()`), not the in-process tiers (`$localCache`/`$toCache`). Since `clearAllApcu()` runs on every `invalidatesCache` entity write (`FileEntityManager`) and `$localCache` is read **before** APCu, a read-after-write in the **same** request returned the stale value (surfaced in the DMS R5 smoke: `grant` an ACE → `canRead()` still `false`). `clearAllApcu()` now also drops both in-process tiers. Cross-request flows (write in request A, read in request B) were never affected; within-request grant-then-read (e.g. the DMS management surface, R6) would have been. See [`documents.md`](documents.md) R5.
- **CACHE-CLI-001** — resolved 2026-08-29. Found in production (zihlundsee.ch cron): CLI and Web never share an APCu pool, so `ImportApplyJob` (CLI, writes `Navigation`/`NavigationAlias`/`MetaData`) ran `clearAllApcu()` against its own empty pool while the FPM pool kept `NavigationService::all`, `aliases-all`, `meta` etc. for up to `defaultTTL` (1 year). `PageCache::clearAll()` is disk-based and did work — the page was re-rendered from the stale APCu index. Never surfaced before because every content write came from the backend, i.e. the Web pool itself. Fix: the stamp file, see "cross-process invalidation" above. Verified by `tests/apcu-stamp.php` (child-process cron, same-second write, sibling installation untouched).
- **CACHE-RELEASE-001** — resolved 2026-09-01. `cacheDir` pointed at `lib/cache`, and `lib` was a `target.shared` entry, so `current` and `next` wrote into ONE page cache. `PageIdentity` keys on (language, module, group, controller, action) and has no release dimension: a page rendered on the staging door was a cache HIT for production for up to the TTL (86 400 s), and every test on `next` could read production's HTML instead of the release under test. The DEBUG bypass could not help — `debug.flag` sat in the shared `data/framework/`, so arming it on one door armed both (`display_errors` included); `SEO_NOINDEX` had the same defect. The admin bypass (CACHE-ADMIN-001) held, but only for the logged-in developer: any anonymous hit on the staging door (private window, crawler, preview link) filled the shared cache. Found by review before it produced an incident; `release-structure.md` had documented «clear the cache after bending current», which covers the switch but not the testing before it. Fix: `var/cache` + `var/state` are release-local and no longer configurable (ADR-035), `var/lib` (throttle) stays shared. Migration per installation — see [`../01-handbook/release-structure.md`](../01-handbook/release-structure.md).
- **CACHE-CLI-002** — decided 2026-08-29, no change. `DataCache` guards APCu calls with `function_exists('apcu_*')` only; with the extension loaded but `apc.enable_cli=0` the functions exist and emit `E_WARNING: apcu_store(): APC is not enabled` on every CLI run. Deliberately kept: the warning is the signal that a CLI process is running with a cache config nobody decided on. Silencing it (an `apcu_enabled()` guard) would hide exactly the state that surfaced CACHE-CLI-001. Route the noise, not the signal — cron output belongs in a log, not in a mail per minute.
- **CACHE-FILE-001** — resolved 2026-05-17. `DataCache::filePersistPath` removed. Was dead code (no call site used the JSON-file fallback). `DataCache` is now strictly two-tier (local → APCu). `set()` parameter `$filePersistPath`, `get()` parameter `$filePersistPath`, `setCacheDir()`, `$absCacheDir` and the file branch in `flush()` are all gone. `CacheManager::setCacheDir()` no longer propagates the path to `DataCache` (only to `PageCache`).

## pending

- FEAT-MON001: `CacheMonitorService` — log APCu hits/misses, gated by `cacheDebug=true` in bootstrap, writes to `var/cache/cache-debug.log` (v1.1)
- FEAT-MON002: backend "Cache Monitor" view — show log + clear button (v1.1)
