# Cache Manager — Concept & Refactor Plan

Status: implemented (2026-05-01) — facade refactor + page cache integration + response-agnostic refactor + ETag/304 + production hardening (atomic write, HEAD, RFC 7232 multi-tag, Last-Modified, path-traversal validation) all done

## Why this refactor

The current `CacheManager` is named "Manager" but actually implements a single, fixed
caching strategy (3-tier: local → APCu → JSON file). It does not coordinate multiple
storages — it *is* one. Adding HTML page caching to the same class would mix two very
different concerns (structured data vs. raw HTML output) and overload the class further.

Goal: turn `CacheManager` into a real facade that holds one or more *cache pools*, each
with its own storage strategy and use case.

## Current state — what the existing CacheManager does

Pool: implicit, single, hardcoded.

| Capability | Where | Notes |
|---|---|---|
| In-request memoization | `$localCache` array | Per-request only |
| APCu read/write | `apcu_fetch`, `apcu_store` | Default TTL 1 year |
| JSON file fallback | `file_get_contents` + `json_decode` | Read on miss, written on flush |
| Deferred persist | `set(..., cachePersist: true)` | Written to APCu in `flush()` at shutdown |
| Key generation | `generateKey($className, $components)` | Class-based namespacing |
| Class-prefixed clear | `clear($classPrefix)` | APCu only |
| Full APCu wipe | `clearAllApcu()` | Used in DEBUG mode at boot |
| Debug stats | `debugApcu()`, `incrementDebug()` | Per-key local/apcu/file/miss counters |
| Cache directory setup | `setCacheDir($dir)` | Called once at boot |

Consumers today: `NavigationRepository`, `FileFinder`, `ConfigManager`, `Dispatcher`
(only TODO references), and `Bootstrap` (DI registration + boot lifecycle).

## Why the current design is wrong for HTML pages

1. **JSON encoding destroys raw HTML** — escapes quotes/newlines/UTF-8, ~30-50 % overhead, file is no longer human-readable.
2. **APCu is too small for pages** — 32-128 MB pool would be evicted by a handful of pages, breaking navigation/config caches.
3. **Per-request `localCache` is useless for pages** — only one page rendered per request.
4. **No expiry check on file reads** — TTL is APCu-only, JSON files never expire.
5. **No skip policy** — POST requests, authenticated users, query strings would all wrongly hit the cache.
6. **No replay mechanism** — Dispatcher TODO assumes a `getPageCache()` API that does not exist; on hit, content type and status code would be lost.

## Target architecture

```
CacheManager (facade)
├── data() → DataCache    — local → APCu → JSON file (existing logic, extracted)
└── page() → PageCache    — raw HTML file with per-entry TTL (new)
```

### Responsibility split

| Concern | CacheManager (facade) | DataCache | PageCache |
|---|---|---|---|
| Pool accessor (`data()`, `page()`) | yes | — | — |
| Lifecycle (`flush()`, `setCacheDir()`) | yes (delegates) | yes (deferred writes) | no (writes are immediate) |
| APCu maintenance (`clearAllApcu()`) | yes (delegates to data) | yes | — |
| Debug output (`debug()`) | yes (delegates) | yes | (later) |
| Key strategy | — | class+components | url-hash-based |
| Storage | — | local + APCu + JSON | file only |
| TTL check on read | — | APCu only (engine-level) | yes (header in file) |

### File layout

```
packages/kernel/core/src/Libraries/
  CacheManager.php           — facade (refactored)
  Cache/
    DataCache.php            — extracted from current CacheManager
    PageCache.php            — new
```

`CacheManager` stays at its current path so existing `use` statements in consumers
keep working. Only the *call sites* change (`->set(...)` → `->data()->set(...)`).

### Public API (final)

```php
$cm = DI::getCacheManager();

// Data pool — two-tier (local → APCu); JSON-file fallback removed 2026-05-17 (CACHE-FILE-001)
$cm->data()->get($className, $components);
$cm->data()->set($className, $components, $value, cachePersist: true);
$cm->data()->clear($classPrefix);
$cm->data()->generateKey($className, $components);

// Page pool — value-object based, response-shaped
$cm->page()->getMtime(PageIdentity $id): ?int;                       // for If-None-Match validation
$cm->page()->get(PageIdentity $id): ?HtmlResponse;                   // ready-to-send response or null
$cm->page()->set(PageIdentity $id, HtmlResponse $r, int $ttl): int;  // returns mtime (= ETag)
$cm->page()->invalidate(PageIdentity $id): void;
$cm->page()->invalidateController(string $module, string $controller): void;
$cm->page()->invalidateModule(string $module): void;
$cm->page()->clearAll(): void;

// Lifecycle / shared
$cm->setCacheDir(string $dir): void;       // configures both pools
$cm->flush(): void;                         // delegates to data (page writes immediately)
$cm->clearAllApcu(): void;                  // delegates to data
$cm->debug(string $msg = '', bool $limited = true): void;  // delegates to data
```

### PageCache file format

Each cached page is a raw file on disk. The first line carries the expiry timestamp,
followed by the HTML body:

```
<!--z77-cache: expires=1714398520-->
<!DOCTYPE html>
<html>
…
```

On read: parse first line, compare to `time()`, return body or null. Stale files are
deleted on the next miss (lazy cleanup). Path scheme: `cache/pages/{hash[0:2]}/{hash}.html`
(2-char prefix avoids huge flat directories on disk).

### Key strategy for pages

The Dispatcher (or a small `PageCachePolicy` helper later) builds the key from:

- request URL (path + relevant query whitelist)
- language
- request mode (Page / Fetch)

Out of scope for this refactor: per-user variants, auth-aware caching, Vary headers.
Skip policy (no cache for POST, authenticated users, etc.) is the Dispatcher's job —
PageCache itself is a dumb store.

## Tasks

### Done — 2026-04-29

#### Preparatory refactor (HtmlResponse capture)
- [x] `Services/HtmlView.php` — `render(): string` (returns instead of echoing)
- [x] `Services/LayoutManager.php` — `render(array): string` (passes string through)
- [x] `Http/Response/HtmlResponse.php` — `getHtml()` with memoization, `send()` echoes the captured string
- [x] `Routing/Dispatcher.php` — old bug comment removed, cache TODO clarified

#### CacheManager facade refactor
- [x] Documentation — this concept doc
- [x] `Libraries/Cache/DataCache.php` — extracted from old CacheManager (1:1 logic)
- [x] `Libraries/Cache/PageCache.php` — new, file-only with TTL header
- [x] `Libraries/CacheManager.php` — facade with `data()`, `page()`, `setCacheDir()`, `flush()`, `clearAllApcu()`, `debug()`
- [x] `Routing/NavigationRepository.php` — call sites updated to `data()->get/set`
- [x] `Libraries/FileFinder.php` — same (4 call sites)
- [x] `Libraries/ConfigManager.php` — same
- [x] `Routing/Dispatcher.php` — `flushToTarget()` → `flush()`, `debugApcu()` → `debug()`
- [x] `Bootstrap.php` — no changes needed, facade keeps `setCacheDir()` and `clearAllApcu()` signatures
- [x] End-to-end verification — request flow runs through

### Done — 2026-04-30 — Page cache integration

#### Key strategy
- [x] Cache key = routing outcome, not incoming URL — different URLs that resolve to the same `(language, module, controller, action)` share one cache entry
- [x] Structured filesystem path instead of sha1 hash: `cache/pages/{lang}/{module}/{controller}/{action}.html` — enables targeted invalidation by module or controller without scanning every file
- [x] `PageIdentity` value object carries the four components; `PageCachePolicy` builds it from the resolved request

#### Skip policy (in `Routing/PageCachePolicy::decide`)
- [x] DEBUG mode → skip (full APCu wipe at boot; we want fresh renders)
- [x] HTTP method ≠ GET → skip
- [x] `$_GET` not empty → skip (query whitelist deferred)
- [x] `RequestMode::Fetch` → skip (separate caching deferred)
- [x] Module config disables for the resolved controller/action → skip
- [x] Authenticated users — deferred until auth system exists; today no auth tracking. CMS strategy A (server has authority + browser revalidation) makes per-user caching unnecessary for now
- [x] 404s — never reach cache code: `NotFoundException` is caught before `set()` runs

#### Cache policy resolution — three-level cascade
Module config → controller override → action override. Each level overrides only the keys it specifies. `ModuleManager::getCachePolicy(module, controller, action)` returns `['enabled' => bool, 'ttl' => int]`.

```php
'cache' => [
    'enabled' => true,
    'ttl'     => 86400,
    'controllers' => [
        'ContactController' => [
            'enabled' => true,
            'ttl'     => 600,
            'actions' => [
                'sendAction' => ['enabled' => false],
            ],
        ],
    ],
],
```

#### Dispatcher integration
- [x] Single decision point — `PageCachePolicy::decide(Request)` returns `?array{identity, ttl}` once per request
- [x] Cache hit → `PageCache::get(): ?HtmlResponse` returns a ready-to-send response; render path skipped entirely
- [x] Cache miss → controller runs, response stored if `HtmlResponse`
- [x] One send path for both — `$response->send()` is the only place that emits headers and body

#### Response-agnostic refactor (after first verification)
The first integration left a code smell: Dispatcher had its own `echo $cached` for the cache-hit path, separate from `$response->send()` for the render path. That meant any future `send()` logic (headers, cookies, SEO tags) would silently skip the cache-hit path. Refactored to:

- [x] `Http/Response/CacheMode.php` — domain-level enum (`ServerCached`, `NoStore`). Dispatcher speaks the enum, never the HTTP cache string. Closed-world set fits the CMS use case; new modes are explicit cases, not free strings.
- [x] `HtmlResponse::fromCache(string $html): self` — named constructor that bypasses `LayoutManager` (`__construct(?LayoutManager, array)` — layout manager nullable). Memoization in `getHtml()` works the same way for cached and rendered HTML, no branching needed.
- [x] `HtmlResponse::setCacheMode(CacheMode)` — Dispatcher tells the response which mode to advertise; `send()` maps it via `match` to the concrete `Cache-Control` header value.
- [x] `PageCache::get(PageIdentity): ?HtmlResponse` and `set(PageIdentity, HtmlResponse, int)` — store talks to caller in response objects, not raw strings. Dispatcher never sees the cached string.
- [x] `Routing/Dispatcher::execute()` — orchestrates only. No `echo`, no `header()`. Same response type whether body came from cache or controller.

#### Browser cache strategy — A: server has authority
- [x] `Cache-Control: public, no-cache` for cacheable pages → browser must revalidate every request, server answers fast from PageCache
- [x] `Cache-Control: no-store` for skipped requests (POST, query strings, fetch mode, debug)
- Editor-driven invalidation takes effect on the next request because the browser cannot serve from its own cache without asking the server. Matches the wdv framework's pattern; aligns with CMS expectations.

#### Verification
- [x] First test round (with DevTools "Disable cache"): cache miss / hit / hold-on-code-change / miss-on-delete / hit-on-new-version — all green
- [x] Second test round (without "Disable cache", post header refactor): `Cache-Control` headers appear correctly per path; the prior browser-cache heuristic bug is structurally gone

### Done — 2026-05-01 — ETag / 304 + production hardening

#### Three-state policy (Variant A)
The policy now decides all three outcomes itself; the dispatcher just executes.

- [x] `Routing/PageCachePolicyMode` enum — `NewPage`, `PageFromCache`, `PageFromClientCache`
- [x] `Routing/PageCacheDecision` value object — private constructor + named factories (`newPage()`, `fromCache()`, `fromClientCache()`) so unsound combinations (e.g. `PageFromCache` without identity) are unreachable by construction
- [x] `PageCachePolicy::decide()` reads `If-None-Match`, calls `PageCache::getMtime()`, and returns `PageFromClientCache` when the browser holds a fresh copy

#### ETag mechanic
- [x] ETag value = file mtime — already known to the filesystem, automatically rotates on every write, no separate hash needed
- [x] `HtmlResponse::setEtag(int)` + `send()` emits RFC-7232-quoted `ETag: "1234567890"` and a matching `Last-Modified: ...GMT` validator
- [x] `HtmlResponse::notModified(int $etag)` factory builds the empty 304 reply (status, validators, no body)
- [x] `CacheMode::NotModified` case added — `send()` switches body emission off via match

#### Production hardening (post-review)
- [x] **Atomic write** in `PageCache::set()` — `tmp + rename` so concurrent readers never see a half-written cache file
- [x] **HEAD support** — `Request::isReadMethod()` (GET ∪ HEAD), policy treats both equivalently; dispatcher calls `HtmlResponse::omitBody()` for HEAD so headers ship without body
- [x] **RFC 7232 multi-tag** — `If-None-Match` may be `"a", "b", W/"c"` or `*`; policy walks the list, normalises weak prefixes, recognises wildcard
- [x] **Explicit `Content-Type: text/html; charset=utf-8`** — no longer depending on `php.ini default_mimetype`/`default_charset`
- [x] **`Last-Modified` header** alongside ETag — backup validator for clients that prefer it
- [x] **`headers_sent()` guard** in `send()` — DEBUG: throws so misconfigured request lifecycles fail loud; production: silent fallback (body still ships)
- [x] **Path-traversal defense in depth** in `PageIdentity::__construct()` — rejects `/`, `\`, `..`, `\0`, empty strings even though Routing already cleans these
- [x] **Cache-write fault tolerance** — `Dispatcher::tryStore()` catches `Throwable` from `PageCache::set()`; the response still ships fresh, only its caching is skipped, and the failure is `error_log`'d

### Deferred

- Cache invalidation hooks for editorial workflows — see `cache-invalidation.md` (planned)
- Deploy hook script `bin/cache-clear-pages.php` for asset / template / code deploys
- Eviction policy when page cache grows large (file count / total size limit) — relevant once the site goes public
- Page cache debug output (analogous to `DataCache::debug()`)
- Custom response headers per route (X-Robots-Tag, custom Cache-Control overrides) — would require extending the cache file format with a header section and replaying on hit. ETag and Last-Modified do **not** need this because they are derived from the file mtime at send time.
- `Vary: Accept-Language` once a CDN or reverse proxy is in front of the application
- Skip-reason telemetry (which check sent us into `NewPage`) — relevant once a logger exists
- Clock injection in policy/cache (instead of `time()`) — relevant once unit tests exist

## Resolved questions

- **Synchronous write vs deferred?** Synchronous chosen and verified — pages are large, no batching benefit, and shutdown writes could lose data on a late crash. Hardened to atomic write via `tmp + rename`.
- **Page cache directory configurable per pool?** Hardcoded subdirectories (`cache/pages` for pages, `cache/<other>` for future pools) under the single configured cache dir.
- **TTL source?** Module config, three-level cascade (module → controller → action). No global default — modules opt in by setting `cache.enabled = true`.
- **Status code / Content-Type replay?** Content-Type now set explicitly. Status codes: `200` for body responses, `304` for `NotModified` cache hits. Custom per-page headers still deferred.
- **Browser cache strategy?** Strategy A — `Cache-Control: public, no-cache` plus ETag/Last-Modified. Browser must revalidate every request; revalidation is cheap (304 with no body when nothing changed). Editor invalidation takes effect on the next request.
