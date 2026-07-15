# Overall Review — z77 Framework v1.0.0

**Date:** 2026-04-06 — **Updated:** 2026-05-03
**Status:** `[DONE]` — superseded by individual component reviews
**Scope:** Architecture, concepts, bugs, code quality — no module deep-dive yet

---

## Summary

The framework has a solid conceptual foundation. The core ideas (CE principle, installer, DI container, FileFinder) are well thought out and headed in the right direction. However, there are **several bugs**, some **architectural conflicts**, and **code quality issues** that must be resolved before publication.

---

## What Works Well

| Area | Assessment |
|---|---|
| CE/Override principle (PSR-4 override before vendor) | Elegant, clearly implemented |
| Composer Installer (post-install-cmd) | Right approach, well structured |
| CacheManager (local → APCu → File) | Solid 3-tier strategy |
| DI container (shared/transient) | Works correctly for the basic case |
| Module architecture | Good isolation, extensible |
| FileFinder path resolution | Clever, centralized, cacheable |

---

## Bugs — Status

### ✓ BUG-001 — CacheManager: wrong key in switch — FIXED 2026-04-06
**File:** `packages/kernel/core/src/Libraries/CacheManager.php`

`switch ($entry['type'])` → `switch ($entry['target'])`. APCu and file caching now work correctly.

---

### ✓ BUG-002 — FileFinder: echo statement in production code — FIXED 2026-04-06
**File:** `packages/kernel/core/src/Libraries/FileFinder.php`

`echo "FileFinder z95 -- : "...` removed.

---

### ✓ BUG-003 — CacheManager: typo in exception class name — FIXED 2026-04-06
**File:** `packages/kernel/core/src/Libraries/CacheManager.php`

`\RunteimException` → `\RuntimeException`. Error message translated to English.

---

### ✓ BUG-004 — ModuleManager: method does not exist — FIXED 2026-04-06
**File:** `packages/kernel/core/src/Services/ModuleManager.php`

`$this->getModule($moduleKey)` → `$this->getModuleConfig($moduleKey)`.

---

### ✓ BUG-005 — Installer: method signature mismatch — FIXED 2026-04-06
**File:** `packages/kernel/core/src/Installer/Install.php`

Extra `$composer` argument removed from `writeFileFinderConfig()` call (INST-007).

---

### ✓ BUG-006 — CacheManager: echo in production code — FIXED 2026-04-06
**File:** `packages/kernel/core/src/Libraries/CacheManager.php`

`echo 'CacheManager::z125 clear cache<br>'` removed.

---

## Architectural Issues

### ✓ ARCH-001 — `final` on classes that should be overridable — FIXED 2026-05-03
**Files:** `ControllerHandler.php`, `ModuleManager.php`

Both are declared `final`. This contradicts the CE principle: if a project needs to customize `ControllerHandler` or `ModuleManager`, it cannot do so via the override system because `final` prevents subclassing.

**Decision needed:** Remove `final` or document an override mechanism that works without subclassing.

---

### ✓ ARCH-002 — Core knows about Frontend — FIXED 2026-05-03
**File:** `packages/kernel/core/src/Services/ModuleManager.php`

```php
private const DEFAULT_MODULE_KEY = 'frontend';
```

Core must not know which modules exist. `frontend` is a concrete module — this dependency belongs in configuration (`moduleManager.inc.php`), not in core code.

---

### ✓ ARCH-003 — DI::__callStatic with silent failure — FIXED 2026-05-03
**File:** `packages/kernel/core/src/DI.php` — see `review-di.md`

```php
return self::getInstance()->get($objectName, $check = false);
```

`DI::getUnregisteredService()` returns `null` instead of throwing an exception. Errors are hidden and only surface later as null-pointer errors.

---

### ✓ ARCH-004 — Double CacheManager::flushToTarget() call — FIXED 2026-05-03
**File:** `packages/kernel/core/src/Routing/Router.php` — see `review-router.md`

In production, `flushToTarget()` is called twice: once synchronously, once in the shutdown function. This leads to double APCu writes and double file writes.

---

### ✓ ARCH-005 — Bootstrap comment is misleading — FIXED 2026-04-06
**File:** `packages/kernel/core/src/Bootstrap.php`

Docblock corrected. Constructor is correctly documented as `public`. Singleton reference removed.

---

### ~ ARCH-006 — Missing dependencies in package composer.json files
**Status:** `[WON'T FIX]` — packages are always installed together (monorepo). `skeleton/composer.json` is the single source of truth. Cross-dependencies in individual package `composer.json` files would be misleading.

---

## Code Quality — Status

| # | File | Issue | Status |
|---|---|---|---|
| Q-001 | Multiple files | Mixed German/English — decided: all English | ✓ Fixed throughout Installer + Bootstrap |
| Q-002 | `Bootstrap.php` | `define('DEBUG', ...)` — global constant, untestable | Open — v1.1 |
| Q-003 | `skeleton/composer.json` | type/name must be adapted for skeleton projects | Open |
| Q-004 | `Install.php` | Static properties — Installer not resettable | ✓ Fixed — static reset in `run()` |

---

## Open Questions for Module Reviews

1. ~~**Language:** German or English?~~ **Decided: English**
2. **`final` decision:** Which classes may be customized via override? (ARCH-001)
3. **DEFAULT_MODULE_KEY:** How does this become configurable? (ARCH-002)
4. ~~**Session timing:**~~ **Decided 2026-04-06:** Session starts after routing. Routing validates the request first — if it fails, no session is needed. See [ADR-001](../02-decisions/adr-001-bootstrap-minimal-dependencies.md) and Bootstrap review.

---

## Review Progress

| Component | Status | Document |
|---|---|---|
| Installer (`Install.php`) | ✓ Reviewed + Fixed | [review-installer.md](review-installer.md) |
| Bootstrap (`Bootstrap.php`) + `index.php` | ✓ Reviewed + Fixed | [review-bootstrap.md](review-bootstrap.md) |
| DI (`DI.php`) | ✓ Reviewed + Fixed | [review-di.md](review-di.md) |
| Router (`Router.php`) | ✓ Reviewed + Fixed | [review-router.md](review-router.md) |
| FileFinder (`FileFinder.php`) | ✓ Reviewed + Fixed | [review-filefinder.md](review-filefinder.md) |
| CacheManager (`CacheManager.php`) | Pending | — |
| `packages/kernel/shared/` | Pending | — |
| `packages/kernel/persistence/` | Pending | — |
| `packages/module-frontend/` | Pending | — |
