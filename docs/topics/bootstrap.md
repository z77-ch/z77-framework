# bootstrap

2026-11-14

## entry

1. `packages/kernel/core/src/Bootstrap.php` — two-phase startup: `__construct()` + `pullUp()` (which calls `pullUpServices()` first)
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
SOURCE=/packages/kernel/core/src/autoload/preBoot/php/Functions.php
SOURCE=/packages/kernel/core/src/autoload/prod/php/Functions.php
SOURCE=/packages/kernel/core/src/autoload/prod/php/Helper.php
SOURCE=/packages/kernel/core/src/autoload/debug/php/Functions.php
SOURCE=/packages/kernel/core/src/Libraries/Seo/SeoLinks.php
SOURCE=/tests/uncaught-error-status.php
SOURCE=/tests/fresh-install-setup.php

RUNTIME=/skeleton/config/vendor/bootstrap.inc.php

## mental model

Bootstrap runs in two phases. `__construct()` sets up infrastructure, defines runtime constants, and initializes the DI container exactly once. `pullUp()` first calls `pullUpServices()` (the HTTP-free service layer), then builds the request pipeline, runs routing, then registers the session-bound services — invalid requests therefore never touch session state. A CLI entry (job runner, ADR-031) calls `pullUpServices()` directly, plus `loadTemplateHelpers()` when it renders a template (a template mail from a cron job).

**Stateless branch (since 2026-09-02):** after routing succeeded (`ControllerHandler::lock()`), `pullUp()` branches on `Request::isStateless()` — true when the matched reserved route declares `'stateless' => true` (e.g. `/api`). The stateless branch wires `ApiKeyGuard` + `Dispatcher(policy: null, stateless: true)` and returns; SessionManager, MessageService, HelpService, CsrfService, AuthService, CurrentUserService and AccessGuard stay UNREGISTERED (`PageCachePolicy` is registered as a lazy factory but never resolved — it needs `AuthService`) — a stateless code path resolving them is a bug and fails fast. No session start, no cookie, no locale logic, no page cache, JSON errors (see the API envelope, `docs/03-development/api-envelope-v1-2026-09-02.md`).

- Runtime config (`config/vendor/bootstrap.inc.php`) exists only after `composer install` — it is NOT a source artifact.
- Source-of-truth: `bootstrap.default.inc.php`. The installer merges it with `composer.json` `extra.core-bootstrap` and writes the result to `config/vendor/`.
- `Bootstrap` reads the bootstrap config (and `systemConfig`) with `getBaseConfig(cachePersist: false)` — never APCu-cached, so config changes take effect without a manual cache clear.

## phases

| Phase | Responsibilities |
|---|---|
| `__construct()` | uncaught-error handlers (web SAPIs), DI init (CacheManager, FileFinder, ConfigManager), `ABS_VAR_PATH`/`ABS_STATE_PATH`, `DEBUG`/`SEO_NOINDEX`/`CANONICAL_BASE_URL`, cache dir `var/cache`, `ABS_PUBLIC_PATH`, error logging, `preBoot` helpers, timezone |
| `pullUpServices()` | HTTP-free services: modules, i18n, translation, persistence, navigation/alias read side, mail — also called by CLI entries |
| `pullUp()` | `REL_INDEX_PATH`, `pullUpServices()`, routing services, routing, session-bound services (after routing), template helpers (+ debug helpers and `setOwnExceptionHandler()` in DEBUG) |

## DI registration order (pullUp)

```text
[pullUpServices()]
ModuleManager → I18n → Translator → SlugTranslator → TranslationCatalog
→ DataSourceResolver → UnifiedEntityManager
→ NavigationUrlResolver(NavigationAliasRepository, CacheManager)
→ NavigationService(NavigationRepository, MetaDataRepository, ModuleManager, NavigationUrlResolver, CacheManager)
→ Router(NavigationService, NavigationUrlResolver)
→ AliasPathResolver(Router, SlugTranslator, I18n)
→ EmailService
[pullUp()]
→ ControllerHandler → Request
→ PageCachePolicy(ModuleManager, CacheManager::page(), AuthService, DEBUG)   (lazy — first resolved by the Dispatcher)
[routing parsed + ControllerHandler locked here]
[STATELESS BRANCH: Request::isStateless() → ApiKeyGuard(ModuleManager) + Dispatcher(CacheManager, null, ApiKeyGuard, stateless: true) + helpers, RETURN — nothing below is registered]
→ SessionManager
→ MessageService(SessionManager)
→ HelpService
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

The installer (`Install::writeDebugFlag()`) maintains the flag based on `composer.json` `extra.core-bootstrap.debug`:
- `debug: true` → creates flag if missing
- `debug: false` / not set → deletes flag if present

`Bootstrap` reads no `debug` key from `bootstrap.inc.php` (the installer copies `extra.core-bootstrap` into that file, so the key may appear there — it has no effect).

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
  the release layout `config/client` is a symlink into `shared/config/client`
  (the flat `shared/config` until 2026-10-22 — migration in
  `release-structure.md`).

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

`htmlRoot` | `timeZone` | `overrideDir` | `moduleDir` | `assetDir` | `tplDir` | `cacheDebug` — `cacheDir` is GONE since ADR-035: the cache path is fixed to `var/cache`, a leftover key in an installed config is ignored. `apcuCachePrefix` is GONE since 2026-11-15: it was written but never read — the APCu pool is namespaced per installation by `DataCache::poolPrefixFor()` (hash of `ABS_BASE_PATH`, [`cache.md`](cache.md)); a leftover key is ignored

## system config keys (`config/client/systemConfig.inc.php`, ADR-030)

Settings that describe THIS installation — seed-once, so a value set on the server survives
`composer install`, and deliberately NOT fed from `composer.json` (that file is committed, so
staging and production could not differ).

`canonicalBaseUrl` — the installation's absolute origin (`https://example.ch`). The source for
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

`installationName` — what this installation calls itself in the backend topbar (added
2026-09-24). Chrome, not identity: a backend tab looks the same in every project, and with
several open — parallel dev servers, a client's test and live door — nothing on the screen says
which one you are editing (the P2 exit check lost time to exactly that, finding S5). Free text.

Empty is the seed and the normal case: the shell then shows the HOST of `canonicalBaseUrl`
(`localhost:8077`, `example.ch`), which already differs per installation — a shipped default would
be the same string everywhere and would leave the confusion in place. A name is set only where it
reads better than the host. Deliberately NOT published as a constant like `CANONICAL_BASE_URL`:
only the backend shell reads it (`BackendAbstractController::installationLabel()`), and a cron
entry has no chrome. Neither value set → the topbar shows nothing there, and the missing
`canonicalBaseUrl` has its own Störer.

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
- When writing code that can run on the stateless path → MUST NOT resolve SessionManager, MessageService, HelpService, CsrfService, AuthService, CurrentUserService, AccessGuard, or PageCachePolicy (unregistered or unresolvable there — fail-fast by design)
- When editing config → MUST edit `bootstrap.default.inc.php` (source) or the project's `composer.json` `extra.core-bootstrap` — runtime `config/vendor/bootstrap.inc.php` MUST NOT be hand-edited (regenerated on every install)
- When adding a service that a CLI entry (job, mail) needs without a request → MUST register it in `pullUpServices()`; MUST NOT let anything registered there touch `Request`, session or routing state
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

- **BOOT-CONFIG-002 — undocumented consequences of BOOT-CONFIG-001** (found 2026-11-19 while writing the handbook page `customize-business-module.md`): (1) an override of `backendConfig` (the ACL) also REPLACES the whole file, and there is no additive ACL extension — not documented anywhere; (2) several registries read ONLY the module configs and have no extension file (`bankMovementClaims`, `bankAccountHints`, `paymentMethodUsage`, `jobs`, `apiServices` / `apiDeviceScopes` / `appScreens`, `mailCompany`): a project can contribute only through a project module of its own (derived from the code — every registry runs over all module keys), which no doc says. Document both here and in the handbook page, or give these registries an extension file (decide with BOOT-CONFIG-001).
- **BOOT-TEST-001 — no test runner for a project** (found 2026-11-19): `tests/run.php` exists only in the framework repository; the docs define no way for a client project to test its overrides. Open whether a project runner (or a documented pattern) is wanted.
- **BOOT-CONFIG-001 — module config override as deviation only** (recorded 2026-09-21, owner; not implemented): proposed direction — the override MERGES into the package config (the override file carries only the keys it changes) instead of replacing it. Open before building: the merge semantics (recursive per key vs. top-level keys; how a list is replaced vs. extended; how a key is removed), the relation to the additive extension files (`getConfigExtensions()` — keep, or subsume), cache-key impact in `ConfigManager`, and the migration of the existing full copies in installations (a full copy stays correct under a merge, but should be trimmed to its deviation). Affects every module config read through `getModuleConfig()`.
