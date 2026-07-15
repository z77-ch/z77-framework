# Review — index.php + Bootstrap.php

**Date:** 2026-04-06
**Files:** `packages/kernel/core/public/index.php`, `packages/kernel/core/src/Bootstrap.php`
**Status:** `[IN PROGRESS]`

---

## Context

`index.php` is the single entry point for every HTTP request. It loads the Composer autoloader, instantiates `Bootstrap`, and calls `dispatch()` on the returned Router. `Bootstrap` is responsible for the entire application startup: loading config, setting up the DI container, configuring error handling, and preparing the Router.

These two files are the first thing that executes on every request. Any bug here affects the entire application.

---

## Overall Assessment

| Dimension | Rating | Notes |
|---|---|---|
| Concept / Approach | 7/10 | Correct two-phase startup (construct + pullUp), DI-based |
| Code correctness | 5/10 | 2 bugs, 1 dead variable, 1 unnecessary pattern |
| Code quality | 4/10 | German comments throughout, misleading docblock, formatting |
| Error handling | 5/10 | Debug output in production, no top-level exception guard |
| Testability | 3/10 | Global constants, global functions, static DI |

---

## What Works Well

- **Two-phase startup is correct:** `__construct()` handles infrastructure (config, DI, error setup), `pullUp()` handles routing-specific setup. Clean separation.
- **DI container wired in constructor:** Services are registered early and available to any component that needs them.
- **Debug/production error handling split:** `display_errors` is tied to the `DEBUG` constant — correct approach.
- **`ini_set('log_errors', '1')` always on:** Errors are always logged regardless of debug mode — correct.
- **Session started after routing:** A deliberate decision (see open questions below).
- **`ControllerHandler::lock()` after request parsing:** Protects against overwriting of current module/action/controller — smart.

---

## Bugs

### BUG-B001 — `ltrim()` without second argument

**File:** [Bootstrap.php:72](../../packages/kernel/core/src/Bootstrap.php#L72)

```php
define('ABS_PUBLIC_PATH', ABS_BASE_PATH.'/'.ltrim($bootstrapConfig->getHtmlRoot()));
```

`ltrim()` without a second argument strips **whitespace**, not `/`. The intent is clearly to strip a leading slash from the path (in case `htmlRoot` is configured as `/public` instead of `public`). Without the second argument, a path like `/public` becomes `/public` (unchanged — no whitespace to strip) and the result is `ABS_BASE_PATH.'//public'` — a double slash.

**Fix:**
```php
define('ABS_PUBLIC_PATH', ABS_BASE_PATH.'/'.ltrim($bootstrapConfig->getHtmlRoot(), '/'));
```

---

### BUG-B002 — Debug `echo` in production code

**File:** [Bootstrap.php:69](../../packages/kernel/core/src/Bootstrap.php#L69)

```php
if (DEBUG) {
    echo 'Bootstrap::z69 DEBUG IS TRUE<br>';
    DI::getCacheManager()->clearAllApcu();
}
```

Same category as BUG-002 (FileFinder) and BUG-006 (CacheManager). The `echo` writes raw HTML to the response on every request in debug mode. Any JSON or API response is corrupted. Any template that's rendered before this runs will have garbage prepended.

**Fix:** Remove the `echo`. The `clearAllApcu()` call is intentional and correct — keep it.

```php
if (DEBUG) {
    DI::getCacheManager()->clearAllApcu();
}
```

---

## Medium Issues

### MED-B001 — Double autoloader loading

**Files:** [index.php:5](../../packages/kernel/core/public/index.php#L5), [Bootstrap.php:38-42](../../packages/kernel/core/src/Bootstrap.php#L38-L42)

`index.php` loads the autoloader:
```php
require_once ABS_BASE_PATH.'/vendor/autoload.php';
```

`Bootstrap::__construct()` loads it again:
```php
$vendorAutoload = ABS_BASE_PATH . '/vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    throw new \Exception("Vendor folder / Composer autoload missing.");
}
$loader = require_once $vendorAutoload;
```

The `require_once` prevents a double include, but the pattern is confusing. If `index.php` didn't load the autoloader, `Bootstrap` class itself could not be found. So `index.php` *must* load it — which means Bootstrap's internal check is always redundant in normal operation.

Additionally, `$loader` captures the ClassLoader object but is never used — dead variable.

**Decision needed:** Either remove the autoloader block from `Bootstrap` entirely (it's already loaded by `index.php` and nothing in Bootstrap needs the ClassLoader object), or remove it from `index.php` and replace with a proper bootstrap include. The current duplication is the worst of both worlds.

**Recommended fix:** Remove the block from `Bootstrap::__construct()`. The `file_exists` guard is unnecessary — if the autoloader is missing, Composer has not been run and the error message is clear enough from PHP itself.

---

### MED-B002 — `$routerClass` string variable for a hardcoded class

**File:** [Bootstrap.php:117-119](../../packages/kernel/core/src/Bootstrap.php#L117-L119)

```php
$routerClass = 'Z77\\Core\\Routing\\Router';
return new $routerClass($c->getRequest());
```

Using a variable to hold a class name is a dynamic instantiation pattern — it suggests the class might change at runtime. Here the class is hardcoded as a string constant, and `Router` is already imported at the top of the file with `use`. This should be:

```php
return new Router($c->getRequest());
```

---

### MED-B003 — German comments throughout (violates English-only rule)

**File:** [Bootstrap.php](../../packages/kernel/core/src/Bootstrap.php) — multiple lines

| Line | German text |
|---|---|
| 22–28 | Entire class docblock |
| 31–34 | Constructor docblock ("Privater Konstruktor / Kann nur über getInstance() aufgerufen werden") |
| 44 | `// --- 2. FileFinder und ConfigManager zu Diensten hinzufügen` |
| 56 | `// --- 3. Bootstap Config lesen ---` |
| 60 | `// no apcu_cache, sonst funktioniert Debuging nicht` |
| 74 | `// --- 4. Allfällige Functions bereitstellen` |
| 80 | `// --- 5. Timezone setzen  ---` |
| 83 | `// --- 6. Log-Verzeichnis finden/erstellen ---` |
| 86 | `// --- 7. Fehlerhandling (Debug / Production) ---` |
| 141 | `// Jetzt wird die Session gestartet` |

All must be translated to English per the project convention.

---

### MED-B004 — Misleading class docblock (ARCH-005)

**File:** [Bootstrap.php:31-35](../../packages/kernel/core/src/Bootstrap.php#L31-L35)

```php
/**
 * Privater Konstruktor
 * Kann nur über getInstance() aufgerufen werden
 */
public function __construct()
```

The constructor is `public`, not private. There is no `getInstance()`. This is a leftover from the old singleton-based framework. The docblock is actively misleading.

**Fix:** Replace with a correct description:
```php
/**
 * Wires the DI container, loads configuration, and configures error handling.
 * Called once per request by index.php.
 */
public function __construct()
```

---

### MED-B005 — Assignment-as-argument style

**File:** [Bootstrap.php:45](../../packages/kernel/core/src/Bootstrap.php#L45), [Bootstrap.php:84](../../packages/kernel/core/src/Bootstrap.php#L84)

```php
DI::getInstance($reset = true)        // line 45
$fileFinder->getAbsPath('/logs', $mkdir = true); // line 84
```

`$reset = true` is not a named argument — it is an assignment expression used as a positional argument. The value `true` is passed correctly, but the variable `$reset` is created in local scope without purpose. This is a misleading pattern that looks like named arguments but is not.

**Fix:** Use either plain positional arguments or proper PHP 8 named arguments:
```php
DI::getInstance(true)               // positional
DI::getInstance(reset: true)        // named — only if parameter is named $reset
$fileFinder->getAbsPath('/logs', true);
```

---

## Minor Issues

### MIN-B001 — Comment typos and formatting

**File:** [Bootstrap.php](../../packages/kernel/core/src/Bootstrap.php)

| Line | Issue |
|---|---|
| 56 | `Bootstap` — typo for `Bootstrap` |
| 80 | Double space before `---` |
| 103–106 | PHPDoc `@return Router` missing `\` — should reference full type |

---

### MIN-B002 — Indentation inconsistency

**File:** [Bootstrap.php:108-109](../../packages/kernel/core/src/Bootstrap.php#L108-L109)

```php
$di = DI::getInstance();
$di ->set('ModuleManager', function($c) {
```

Extra space between `$di` and `->set`. All other chained calls use `->` without a leading space. Should be `$di->set(...)`.

---

### MIN-B003 — `pullUp()` PHPDoc is incomplete

**File:** [Bootstrap.php:98-102](../../packages/kernel/core/src/Bootstrap.php#L98-L102)

The docblock only documents `@return Router`. The method also defines the `REL_INDEX_PATH` constant, registers additional DI services, parses the request, and starts the session. The docblock gives an incomplete picture.

---

## Architecture Observations

### ARCH-B001 — `define('DEBUG', ...)` as a global constant

**File:** [Bootstrap.php:64](../../packages/kernel/core/src/Bootstrap.php#L64)

```php
define('DEBUG', ($bootstrapConfig->getDebug() === true));
```

Global PHP constants (`define()`) cannot be reset between tests or requests. This makes unit testing any component that branches on `DEBUG` impossible without process-level tricks. Already noted as ARCH-002 in the overall review. The alternative — a Config value injected via DI — is more testable. This is a v1.1 concern.

---

### ARCH-B002 — `getRelativePath()` is a hidden global function dependency

**File:** [Bootstrap.php:105](../../packages/kernel/core/src/Bootstrap.php#L105)

```php
define('REL_INDEX_PATH', getRelativePath(ABS_INDEX_PATH));
```

`getRelativePath()` is a global function loaded in step 4 of `__construct()` via `FileFinder`. If `pullUp()` were called without first calling `__construct()` (which would be a misuse, but still), this crashes with "function not found". The dependency on the global function is invisible from `pullUp()`'s signature and docblock.

---

### ARCH-B003 — Session timing ✓ Decided (2026-04-06)

**File:** [Bootstrap.php](../../packages/kernel/core/src/Bootstrap.php)

The session is started in `pullUp()`, after request parsing and controller locking — not in `__construct()`.

**Decision:** Intentional. Routing first validates that the request is valid (correct module, controller, action exists). If routing throws, the request is rejected and no session is needed. Starting the session only after a successful route match avoids unnecessary overhead (cookie header, session file read/write) for invalid or malformed requests. Documented in the `pullUp()` PHPDoc.

---

## Action Items

| Priority | ID | Action |
|---|---|---|
| Bug | BUG-B001 | `ltrim($bootstrapConfig->getHtmlRoot())` → `ltrim(..., '/')` |
| Bug | BUG-B002 | Remove `echo 'Bootstrap::z69 DEBUG IS TRUE<br>'` |
| Medium | MED-B001 | Remove autoloader block from `Bootstrap::__construct()`, remove dead `$loader` variable |
| Medium | MED-B002 | `new $routerClass(...)` → `new Router(...)` |
| Medium | MED-B003 | Translate all German comments to English |
| Medium | MED-B004 | Fix misleading constructor docblock |
| Medium | MED-B005 | Replace `$reset = true` style with plain arguments |
| Minor | MIN-B001 | Fix typos and formatting |
| Minor | MIN-B002 | Remove extra space `$di ->set` → `$di->set` |
| Minor | MIN-B003 | Expand `pullUp()` PHPDoc |
| ✓ Done | ARCH-B003 | Session timing documented — intentional, routing validates first |
| Future | ARCH-B001 | Replace `define('DEBUG')` with injectable config (v1.1) |
