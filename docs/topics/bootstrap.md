# bootstrap

2026-05-17

## entry

1. `packages/kernel/core/src/Bootstrap.php` — two-phase startup: `__construct()` + `pullUp()`
2. `packages/kernel/core/src/Config/bootstrap.default.inc.php` — source-of-truth config template
3. `packages/kernel/core/src/DI.php` — dependency injection container

## file map

SOURCE=/packages/kernel/core/src/Bootstrap.php
SOURCE=/packages/kernel/core/src/DI.php
SOURCE=/packages/kernel/core/src/Config/bootstrap.default.inc.php

RUNTIME=/skeleton/config/bootstrap.inc.php

## mental model

Bootstrap runs in two phases. `__construct()` sets up infrastructure, defines runtime constants, and initializes the DI container exactly once. `pullUp()` builds the request pipeline, wires services via DI, runs routing, then starts the session — invalid requests therefore never touch session state.

- Runtime config (`bootstrap.inc.php`) exists only after `composer install` — it is NOT a source artifact.
- Source-of-truth: `bootstrap.default.inc.php`. Installer copies it to skeleton.
- `cachePersist` is always `false` — config changes must take effect without manual cache clear.

## phases

| Phase | Responsibilities |
|---|---|
| `__construct()` | CacheManager, FileFinder, ConfigManager, DEBUG/ABS_PUBLIC_PATH/timezone, DI init |
| `pullUp()` | Pipeline wiring, routing, session (after routing), helpers |

## DI registration order (pullUp)

```text
ModuleManager → ControllerHandler → Request
→ DataSourceResolver → UnifiedEntityManager
→ NavigationService(NavigationRepository, MetaDataRepository, CacheManager)
→ Router(NavigationService)
→ PageCachePolicy(ModuleManager, CacheManager::page(), DEBUG)
[routing parsed + ControllerHandler locked here]
→ SessionManager
→ MessageService(SessionManager)
→ CsrfService(SessionManager)
→ AuthService(SessionManager, ControllerHandler)
→ CurrentUserService(AuthService, UnifiedEntityManager)
→ AccessGuard(AuthService, SessionManager, ControllerHandler, ModuleManager, CsrfService, MessageService)
→ Dispatcher(CacheManager, PageCachePolicy, AccessGuard)
```

## constants

| Constant | Source | Set by |
|---|---|---|
| `ABS_BASE_PATH` | filesystem root | before Bootstrap (index.php) |
| `ABS_INDEX_PATH` | path to index.php | before Bootstrap |
| `DEBUG` | `data/framework/debug.flag` (existence) | `__construct()` |
| `SEO_NOINDEX` | `data/framework/seo/noindex.flag` (existence) | `__construct()` |
| `ABS_PUBLIC_PATH` | `ABS_BASE_PATH + htmlRoot` | `__construct()` |
| `REL_INDEX_PATH` | relative path to index.php | `pullUp()` |

## DEBUG flag mechanism (since 2026-05-05)

`DEBUG` is NOT read from `bootstrap.inc.php` — it is derived from the existence of a flag file:

```php
define('DEBUG', file_exists(ABS_BASE_PATH . '/data/framework/debug.flag'));
```

| State | DEBUG |
|---|---|
| `data/framework/debug.flag` exists | `true` |
| File missing | `false` |

Why: single source of truth for all subsystems (Doctrine, APCu, error_reporting). Toggleable via filesystem (touch/delete) AND via backend toggle button — no config edit needed.

The installer (`Install::writeDebugFlag()`) maintains the flag based on `composer.json` `extra.debug`:
- `debug: true` → creates flag if missing
- `debug: false` / not set → deletes flag if present

`bootstrap.inc.php` no longer has a `debug` field.

## SEO_NOINDEX flag mechanism (since 2026-07-14)

Same flag-file pattern as `DEBUG`, for a site-wide search-engine crawl block (staging / pre-launch). Defined next to `DEBUG` in `__construct()`:

```php
define('SEO_NOINDEX', file_exists(ABS_BASE_PATH . '/data/framework/seo/noindex.flag'));
```

| State | `SEO_NOINDEX` |
|---|---|
| `data/framework/seo/noindex.flag` exists | `true` |
| File missing | `false` |

When `true`: the frontend head partial `head/meta.tpl.php` emits `<meta name="robots" content="noindex, nofollow">` and the backend shell shows a persistent, non-dismissible Störer. Toggled via the filesystem OR the backend service panel (`SystemController::toggleNoindexAction`). Distinct from per-page SEO (see [`metadata.md`](metadata.md) SEO-NOINDEX-001). Read the constant — MUST NOT re-derive via `file_exists` in templates.

## bootstrap config keys

`debug` | `cacheDir` | `timezone` | `htmlRoot` | `cachePersist` (always `false`)

## rules

- When initializing the DI container → MUST do it in `Bootstrap::__construct` exactly once; subsequent calls MUST NOT re-init
- When writing a controller that needs `DataSourceResolver` or `EntityManager` → MUST obtain via DI; controllers MUST NOT instantiate these directly
- When ordering pipeline steps in `pullUp()` → session start MUST happen after routing
- When editing config → MUST edit `bootstrap.default.inc.php` (source) — runtime `bootstrap.inc.php` MUST NOT be hand-edited as source

## see also

- [`backend.md`](backend.md) — Debug-Toggle button is wired in `SystemController::toggleDebugAction()`
- [`cache.md`](cache.md) — DEBUG=true forces every page response to BYPASS (`PageCachePolicy::decide()` short-circuits before any cache lookup)
- [`installer.md`](installer.md) — `Install::writeDebugFlag()` maintains the flag based on `composer.json`

## known issues

_(none)_

## pending

_(none)_
