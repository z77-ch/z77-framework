# bootstrap

2026-09-22

## entry

1. `packages/kernel/core/src/Bootstrap.php` — two-phase startup: `__construct()` + `pullUp()`
2. `packages/kernel/core/src/Config/bootstrap.default.inc.php` — source-of-truth config template
3. `packages/kernel/core/src/DI.php` — dependency injection container

## file map

SOURCE=/packages/kernel/core/src/Bootstrap.php
SOURCE=/packages/kernel/core/src/DI.php
SOURCE=/packages/kernel/core/src/Config/bootstrap.default.inc.php
SOURCE=/packages/kernel/core/src/Config/systemConfig.default.inc.php
SOURCE=/packages/kernel/core/src/Libraries/ConfigManager.php
SOURCE=/packages/kernel/core/src/Libraries/FileFinder.php
SOURCE=/packages/kernel/core/src/Services/ModuleManager.php
SOURCE=/packages/kernel/core/src/Exception/ExceptionHandler.php
SOURCE=/packages/kernel/core/src/autoload/debug/php/Functions.php
SOURCE=/packages/kernel/core/src/Libraries/Seo/SeoLinks.php
SOURCE=/tests/uncaught-error-status.php
SOURCE=/tests/fresh-install-setup.php

RUNTIME=/skeleton/config/bootstrap.inc.php

## mental model

Bootstrap runs in two phases. `__construct()` sets up infrastructure, defines runtime constants, and initializes the DI container exactly once. `pullUp()` builds the request pipeline, wires services via DI, runs routing, then starts the session — invalid requests therefore never touch session state.

**Stateless branch (since 2026-09-02):** after routing succeeded (`ControllerHandler::lock()`), `pullUp()` branches on `Request::isStateless()` — true when the matched reserved route declares `'stateless' => true` (e.g. `/api`). The stateless branch wires `ApiKeyGuard` + `Dispatcher(policy: null, stateless: true)` and returns; SessionManager, MessageService, CsrfService, AuthService, AccessGuard and PageCachePolicy stay UNREGISTERED — a stateless code path resolving them is a bug and fails fast. No session start, no cookie, no locale logic, no page cache, JSON errors (see the API envelope, `docs/03-development/api-envelope-v1-2026-09-02.md`).

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
[STATELESS BRANCH: Request::isStateless() → ApiKeyGuard + Dispatcher(CacheManager, null, ApiKeyGuard, stateless) + helpers, RETURN — nothing below is registered]
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
| `ABS_VAR_PATH` | `ABS_BASE_PATH . '/var'` — release-local runtime state (ADR-035) | `__construct()` |
| `ABS_STATE_PATH` | `ABS_VAR_PATH . '/state'` | `__construct()` |
| `DEBUG` | `var/state/debug.flag` (existence) | `__construct()` |
| `SEO_NOINDEX` | `var/state/noindex.flag` (existence) | `__construct()` |
| `CANONICAL_BASE_URL` | `config/client/systemConfig.inc.php` key `canonicalBaseUrl` (ADR-030) | `__construct()` |
| `ABS_PUBLIC_PATH` | `ABS_BASE_PATH + htmlRoot` | `__construct()` |
| `REL_INDEX_PATH` | relative path to index.php | `pullUp()` |

## DEBUG flag mechanism (since 2026-05-05)

`DEBUG` is NOT read from `bootstrap.inc.php` — it is derived from the existence of a flag file:

```php
define('DEBUG', file_exists(ABS_STATE_PATH . '/debug.flag'));
```

| State | DEBUG |
|---|---|
| `var/state/debug.flag` exists | `true` |
| File missing | `false` |

Why: single source of truth for all subsystems (Doctrine, APCu, error_reporting). Toggleable via filesystem (touch/delete) AND via backend toggle button — no config edit needed.

The installer (`Install::writeDebugFlag()`) maintains the flag based on `composer.json` `extra.debug`:
- `debug: true` → creates flag if missing
- `debug: false` / not set → deletes flag if present

`bootstrap.inc.php` no longer has a `debug` field.

## SEO_NOINDEX flag mechanism (since 2026-07-14)

Same flag-file pattern as `DEBUG`, for a site-wide search-engine crawl block (staging / pre-launch). Defined next to `DEBUG` in `__construct()`:

```php
define('SEO_NOINDEX', file_exists(ABS_STATE_PATH . '/noindex.flag'));
```

| State | `SEO_NOINDEX` |
|---|---|
| `var/state/noindex.flag` exists | `true` |
| File missing | `false` |

When `true`: the frontend head partial `head/meta.tpl.php` emits `<meta name="robots" content="noindex, nofollow">` and the backend shell shows a persistent, non-dismissible Störer. Toggled via the filesystem OR the backend service panel (`SystemController::toggleNoindexAction`). Distinct from per-page SEO (see [`metadata.md`](metadata.md) SEO-NOINDEX-001). Read the constant — MUST NOT re-derive via `file_exists` in templates.

## config layout (ADR-036 split)

`config/` has two tiers with distinct owners; every reader resolves
`config/X` as **`config/vendor/X` → `config/client/X` → `config/X`** (legacy
flat fallback, central helper `ConfigLocator`):

- `config/vendor/` — installer-GENERATED (`bootstrap`, `fileFinder`,
  `moduleManager`): a function of `composer.json` + `vendor/`, owned by the
  RELEASE (rides with the upload; makes module deploys testable on `next`).
- `config/client/` — hand-maintained machine/project facts (`systemConfig`,
  `mail`, `geoip`, `auth`, `backup`, `i18n`): owned by the INSTALLATION; in
  the release layout `config/client` is a symlink into `shared/config`.

The installer migrates a flat layout automatically (generated files
regenerated into vendor/, seed-once files renamed into client/).

## module config override

A module config (`{module}Config.inc.php`, e.g. `contactConfig`, `frontendConfig`) is read by
`ModuleManager::getModuleConfig()` → `ConfigManager::getArrayConfig()` → `FileFinder::getFirstSourceMatch()`:
the FIRST file found wins — `override/z77/module/{module}/src/App/Config/…` before the package in
`vendor/` — and there is no merge. A project override therefore REPLACES the package config as a
whole (BOOT-CONFIG-001).

The existing exception is additive: `ModuleManager::getConfigExtensions($moduleKey, $configKey)`
collects every `App/Config/{$configKey}Config.inc.php` under ANY of the module's source paths
(override and package), so a project adds to a registry key without copying the module config —
`doctrineEntitiesConfig`, `importEntitiesConfig`, `openWorkChecksConfig`
([`persistence-doctrine.md`](persistence-doctrine.md)).

## bootstrap config keys

`debug` | `timezone` | `htmlRoot` | `cachePersist` (always `false`) — `cacheDir` is GONE since ADR-035: the cache path is fixed to `var/cache`, a leftover key in an installed config is ignored

## system config keys (`config/client/systemConfig.inc.php`, ADR-030)

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

The page context's canonical/hreflang set (`$seo`) is therefore a `SeoLinks` object, built on
its first READ (since 2026-09-22, BOOT-SETUP-001): only the frontend head partials print it,
so the backend and the first-run setup render without the origin, while a frontend page still
fails loudly (500, message names `config/client/systemConfig.inc.php`) — never with a guessed
or empty canonical. `buildSiteIdentity()` stays eager: it only asks for the origin when the
module has a `site` block, which the backend has not.

## uncaught errors (since 2026-09-22, BOOT-ERR-001)

An error nobody caught answers **HTTP 500**. `Bootstrap::__construct()` registers, as its
first step (web SAPIs only — a CLI binary keeps PHP's own report and exit code 255):

| Handler | Does |
|---|---|
| `ExceptionHandler::handleUncaught()` (exception handler) | `markFailed()` → 500; logs; stateless route → JSON envelope (`handle()`); `display_errors` off → drops the half-rendered output, generic `500` body without internals; on → message + trace |
| `ExceptionHandler::handleShutdown()` (shutdown function) | a real fatal (E_ERROR, parse/compile) → `markFailed()`, best effort; `display_errors` off and headers still open → drops the half-rendered output, same generic `500` body as the exception path |
| DEBUG `setOwnExceptionHandler()` (replaces the exception handler after routing) | `markFailed()` first, then its box — except on a stateless route (/api), where it delegates to `handleUncaught()`: the JSON envelope does not change with DEBUG |

`TemplateRenderer` remembers `ob_get_level()` before its `ob_start()` and, when a template
throws, closes every buffer down to that level — its own and any the template opened — so
nested partials no longer leave half a page in open buffers.

## rules

- When initializing the DI container → MUST do it in `Bootstrap::__construct` exactly once; subsequent calls MUST NOT re-init
- When writing a controller that needs `DataSourceResolver` or `EntityManager` → MUST obtain via DI; controllers MUST NOT instantiate these directly
- When ordering pipeline steps in `pullUp()` → session start MUST happen after routing — or never, for stateless reserved routes (`Request::isStateless()`); the stateless branch MUST NOT register any session-bound service
- When writing code that can run on the stateless path → MUST NOT resolve SessionManager, MessageService, CsrfService, AuthService, AccessGuard, or PageCachePolicy (unregistered there — fail-fast by design)
- When editing config → MUST edit `bootstrap.default.inc.php` (source) — runtime `bootstrap.inc.php` MUST NOT be hand-edited as source
- When building an absolute URL that leaves the request (mail link, canonical, hreflang, anything rendered into a cached page) → MUST take the origin from `Request::getBaseUrl()`; MUST NOT read `$_SERVER['HTTP_HOST']`. The header is the client's to choose, and the page cache keys on path only, so one forged request would poison what every later visitor is served (SEC-005)
- When adding an installation-level setting (something that differs per installation and must survive an update) → MUST add it to `systemConfig.default.inc.php`, NOT to `bootstrap.inc.php` (regenerated) and NOT to composer `extra` (committed, so environments cannot differ). MUST decide its empty-value policy per ADR-030 point 4: throw at the point of use when no default is meaningful, take the default when one obviously is; MUST NOT abort the boot either way
- When adding or replacing an exception / shutdown handler (a project override, a debug tool) → MUST call `ExceptionHandler::markFailed()` before printing anything; MUST NOT print an error page without it — a user handler takes the error away from PHP, and with `display_errors` on PHP itself answers 200 (BOOT-ERR-001)
- When a value in the shared page context (`AbstractBaseController::html()`) needs `Request::getBaseUrl()` → MUST defer it to the first read (like `SeoLinks`) unless every layout prints it; MUST NOT build it eagerly (takes down the backend and the setup on an installation without `canonicalBaseUrl`) and MUST NOT catch the throw into an empty or Host-derived value (SEC-005)
- When a message, comment or living doc names a config file → MUST name its split location (`config/client/…` or `config/vendor/…`, ADR-036); `tests/fresh-install-setup.php` fails on a bare `config/X.inc.php` in `packages/` and `docs/` — ADRs (`docs/02-decisions/`) are excluded as historical records, and a dated plan/review that must keep the flat path MUST be added to the harness allowlist WITH its reason
- When a setting is per USER → MUST put it on `BackendUser` (ADR-022), not in `systemConfig`; when it is transient runtime state (a lock held while a job runs) → MUST NOT put it in `systemConfig` at all, or a restore resurrects it on a machine where nothing is running

## see also

- [`backend.md`](backend.md) — Debug-Toggle button is wired in `SystemController::toggleDebugAction()`
- [`cache.md`](cache.md) — DEBUG=true forces every page response to BYPASS (`PageCachePolicy::decide()` short-circuits before any cache lookup)
- [`installer.md`](installer.md) — `Install::writeDebugFlag()` maintains the flag based on `composer.json`
- [`persistence-doctrine.md`](persistence-doctrine.md) — the additive extension files (`doctrineEntitiesConfig`, `openWorkChecksConfig`) that work around BOOT-CONFIG-001 for registry keys
- [`contact.md`](contact.md) — `contactListLimit`, the one-key override that currently needs a full `contactConfig` copy (BOOT-CONFIG-001)

## known issues

- **BOOT-ERR-001** — resolved 2026-09-22 (P2 exit check, finding S1). Don't assume PHP answers an uncaught error with 500: it does so only while `display_errors` is off AND no user handler took the error. In DEBUG both were the other way round — `setOwnExceptionHandler()` printed its box and never set a status, and `display_errors` is on — so a fatal went out as **HTTP 200** (the first setup page of a fresh install). Output buffering was not the cause (nothing had been sent when `html()` threw), but open template buffers carried half a page ahead of the error; `TemplateRenderer` now closes them. Fix: `ExceptionHandler::handleUncaught()` / `handleShutdown()` registered first thing in `Bootstrap::__construct()`, `markFailed()` in the DEBUG handler. Verified: `php tests/uncaught-error-status.php` — 15 checks in PHP's built-in server (including the control case that reproduces the 200, DEBUG on a stateless route, and a real fatal with `display_errors` on and off) plus 3 CLI checks of the `TemplateRenderer` buffers. Status codes also verified live against the z77.ch installation (review 2026-09-22). Residual, not changed: a DEBUG warning printed before the response (headers then already sent) still leaves the status at whatever was sent — `markFailed()` cannot change sent headers.
- **BOOT-SETUP-001** — resolved 2026-09-22 (P2 exit check, finding S1). Don't assume «empty `canonicalBaseUrl` does not abort the boot» meant the backend was reachable: `AbstractBaseController::html()` built the SEO set eagerly for EVERY page, so the backend and `/backend/system/setup/setup` died with the SEC-005 exception on every fresh install — the Störer that should explain it could never render. Fix: `SeoLinks` (built on first read). SEC-005 is unchanged: no fallback, a frontend page still throws. Its message names `config/client/systemConfig.inc.php` only where `display_errors` is on (DEBUG); otherwise the client gets the generic 500 and the message goes to the log. The backend Störer naming the file shows only AFTER login — the setup page and `/login` carry none; the installer prints the same hint at the end of its run (INST-FRESH-001). Verified: `php tests/fresh-install-setup.php`. The message also named the flat pre-split path (`config/` without `client/`, S2) — all messages and comments in `packages/` now name `config/client/…` / `config/vendor/…`.
- **BOOT-CONFIG-001** — don't assume a module config override records only its deviation: `ConfigManager::getArrayConfig()` takes the first source match, so an override REPLACES the package config. A project changing one key (e.g. `contactListLimit` in `contactConfig`) must copy the ENTIRE config and from then on misses every key a package update adds or changes — against Rule 2 (a scope records only its deviation). Full `frontendConfig` copies exist in installations today (e.g. `override/z77/module/frontend/src/App/Config/frontendConfig.inc.php`). Only registry keys with an extension file (`getConfigExtensions()`) are additive.

## pending

- **BOOT-CONFIG-001 — module config override as deviation only** (recorded 2026-09-21, owner; not implemented): proposed direction — the override MERGES into the package config (the override file carries only the keys it changes) instead of replacing it. Open before building: the merge semantics (recursive per key vs. top-level keys; how a list is replaced vs. extended; how a key is removed), the relation to the additive extension files (`getConfigExtensions()` — keep, or subsume), cache-key impact in `ConfigManager`, and the migration of the existing full copies in installations (a full copy stays correct under a merge, but should be trimmed to its deviation). Affects every module config read through `getModuleConfig()`.
