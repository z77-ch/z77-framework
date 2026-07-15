# cache

2026-05-02

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
- `PageCache` is auto-skipped in DEBUG, for non-GET/HEAD, for query strings, and in Fetch mode.
- Storing `null` in `DataCache` is forbidden — indistinguishable from a miss.
- `cachePersist` is always `false` in bootstrap config.

## DataCache

```php
$cache->get('ClassName', ['k1','k2']);             // null = miss
$cache->set('ClassName', ['k1'], $val, cachePersist: true);
$cache->flush();                                    // writes APCu, called at request end
$cache->clear('ClassName');
```

Key format: `Z77-apcu-pool::{ClassName}::{k1}::{k2}`
Layers: local array → APCu

## PageCache

| Aspect | Detail |
|---|---|
| Skip conditions | DEBUG, non-GET/HEAD, query string, Fetch mode, module policy `enabled=false`, `ttl <= 0` |
| ETag | `filemtime` of cache file |
| Path | `cache/pages/{lang}/{module}/{controller}/{action}.html` |
| Failure | Dispatcher wraps `PageCache::set()` in try/catch — write failure must not kill request |
| Diagnostic header | `X-Z77-PageCache: HIT \| MISS \| BYPASS` on every 200 response (304 omits — status code is the signal) |

### decision flow

`PageCachePolicy::decide()` is the single source of truth — it returns one of three outcomes. The Dispatcher only executes them.

```text
PageCachePolicy::decide($request)
  │
  ├─ DEBUG=true                                              → NewPage  (BYPASS)
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
| `BYPASS` | PageCache skipped or write failed | `NewPage` (DEBUG, POST, query, fetch, policy disabled) OR `tryStore()` threw |

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

#[Entity('file', 'framework/auth/loginUsers.json')]                              // no Frontend impact
class LoginUser { ... }
```

Marked today: `Navigation`, `Tag`, `MetaData`. Controllers MUST NOT call `cacheManager->clearAllApcu()` after a save — the entity manager owns that.

## rules

- When calling `DataCache::set()` → MUST NOT pass `null` as value (indistinguishable from miss)
- When implementing a controller that writes via `PageCache::set()` → MUST be wrapped in try/catch by Dispatcher; write failure MUST NOT kill the request
- When configuring a backend module → MUST set `'cache' => ['enabled' => false]`
- When editing bootstrap config → `cachePersist` MUST be `false` (config changes must take effect without cache clear)
- When an entity's writes must invalidate frontend caches → MUST set `invalidatesCache: true` on its `#[Entity]` attribute; MUST NOT call `cacheManager->clearAllApcu()` from controllers
- When adding a new entity that is NOT rendered into frontend pages (logs, statistics, auth) → MUST leave `invalidatesCache` at its `false` default

## see also

- [`bootstrap.md`](bootstrap.md) — DEBUG flag mechanism (toggling DEBUG flips every page to BYPASS or back)
- [`backend.md`](backend.md) — `SystemController::clearCacheAction()` + `toggleDebugAction()` (both clear APCu + PageCache)
- [`persistence-file.md`](persistence-file.md) — `FileEntityManager` triggers auto-invalidation via `invalidatesCache`
- [`documents.md`](documents.md) — the DMS media-url resolve index (`DocumentService::folderSlugIndex`/`publicPathIndex`, template helper `mediaUrl()`) is a `DataCache` consumer dropped by the DMS `invalidatesCache` writes — no own invalidation

## known issues

- **CACHE-INV001** — resolved. Stale-content-after-write fixed via `#[Entity(..., invalidatesCache: true)]`. `FileEntityManager` auto-clears `DataCache` + `PageCache` on `flush()`/`remove()`/`reorder()`. Removed 5 duplicated `clearAllApcu()` calls from `NavigationController`. End-to-end verified 2026-05-16.
- **CACHE-INV-002** — resolved 2026-06-29. `DataCache::clearAllApcu()` cleared only APCu (`apcu_clear_cache()`), not the in-process tiers (`$localCache`/`$toCache`). Since `clearAllApcu()` runs on every `invalidatesCache` entity write (`FileEntityManager`) and `$localCache` is read **before** APCu, a read-after-write in the **same** request returned the stale value (surfaced in the DMS R5 smoke: `grant` an ACE → `canRead()` still `false`). `clearAllApcu()` now also drops both in-process tiers. Cross-request flows (write in request A, read in request B) were never affected; within-request grant-then-read (e.g. the DMS management surface, R6) would have been. See [`documents.md`](documents.md) R5.
- **CACHE-FILE-001** — resolved 2026-05-17. `DataCache::filePersistPath` removed. Was dead code (no call site used the JSON-file fallback). `DataCache` is now strictly two-tier (local → APCu). `set()` parameter `$filePersistPath`, `get()` parameter `$filePersistPath`, `setCacheDir()`, `$absCacheDir` and the file branch in `flush()` are all gone. `CacheManager::setCacheDir()` no longer propagates the path to `DataCache` (only to `PageCache`).

## pending

- FEAT-MON001: `CacheMonitorService` — log APCu hits/misses, gated by `cacheDebug=true` in bootstrap, writes to `lib/cache/cache-debug.log` (v1.1)
- FEAT-MON002: backend "Cache Monitor" view — show log + clear button (v1.1)
