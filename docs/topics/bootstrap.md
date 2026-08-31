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
SOURCE=/packages/kernel/core/src/Config/systemConfig.default.inc.php

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
| `ABS_BASE_PATH` | filesystem root — resolved at RUNTIME via `realpath($_SERVER['DOCUMENT_ROOT'])`, fallback `__DIR__` (OPcache trampoline, see handbook `release-structure.md`) | before Bootstrap (index.php) |
| `ABS_INDEX_PATH` | path to index.php | before Bootstrap |
| `DEBUG` | `data/framework/debug.flag` (existence) | `__construct()` |
| `SEO_NOINDEX` | `data/framework/seo/noindex.flag` (existence) | `__construct()` |
| `CANONICAL_BASE_URL` | `config/systemConfig.inc.php` key `canonicalBaseUrl` (ADR-030) | `__construct()` |
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

## system config keys (`config/systemConfig.inc.php`, ADR-030)

Settings that describe THIS installation — seed-once, so a value set on the server survives
`composer install`, and deliberately NOT fed from `composer.json` (that file is committed, so
staging and production could not differ).

`canonicalBaseUrl` — the installation's absolute origin (`https://kunde.ch`). The source for
every URL generated to leave the request: mail links (magic login, registration confirmation,
activation) and the SEO canonical/hreflang set. `Bootstrap` publishes it as the constant
`CANONICAL_BASE_URL`, so a cron entry that boots the framework reads the same value a web
request does.

Empty does **not** abort the boot — a fatal there would take the backend down, i.e. the
surface needed to fix it. Instead the shell shows a Störer and `Request::getBaseUrl()` throws
when something actually tries to build an absolute URL (SEC-005, [`security.md`](security.md)).

## rules

- When initializing the DI container → MUST do it in `Bootstrap::__construct` exactly once; subsequent calls MUST NOT re-init
- When writing a controller that needs `DataSourceResolver` or `EntityManager` → MUST obtain via DI; controllers MUST NOT instantiate these directly
- When ordering pipeline steps in `pullUp()` → session start MUST happen after routing
- When editing config → MUST edit `bootstrap.default.inc.php` (source) — runtime `bootstrap.inc.php` MUST NOT be hand-edited as source
- When building an absolute URL that leaves the request (mail link, canonical, hreflang, anything rendered into a cached page) → MUST take the origin from `Request::getBaseUrl()`; MUST NOT read `$_SERVER['HTTP_HOST']`. The header is the client's to choose, and the page cache keys on path only, so one forged request would poison what every later visitor is served (SEC-005)
- When adding an installation-level setting (something that differs per installation and must survive an update) → MUST add it to `systemConfig.default.inc.php`, NOT to `bootstrap.inc.php` (regenerated) and NOT to composer `extra` (committed, so environments cannot differ). MUST decide its empty-value policy per ADR-030 point 4: throw at the point of use when no default is meaningful, take the default when one obviously is; MUST NOT abort the boot either way
- When a setting is per USER → MUST put it on `BackendUser` (ADR-022), not in `systemConfig`; when it is transient runtime state (a lock held while a job runs) → MUST NOT put it in `systemConfig` at all, or a restore resurrects it on a machine where nothing is running

## see also

- [`backend.md`](backend.md) — Debug-Toggle button is wired in `SystemController::toggleDebugAction()`
- [`cache.md`](cache.md) — DEBUG=true forces every page response to BYPASS (`PageCachePolicy::decide()` short-circuits before any cache lookup)
- [`installer.md`](installer.md) — `Install::writeDebugFlag()` maintains the flag based on `composer.json`

## known issues

_(none)_

## pending

_(none)_
