# Review — Install.php (Core Installer)

**Date:** 2026-04-06
**File:** `packages/kernel/core/src/Installer/Install.php`
**Status:** `[IN PROGRESS]` — bugs open, architectural questions pending
**Reviewer:** Claude Code (senior-level analysis)

---

## Context

The `Install` class is a Composer post-install/post-update script. It runs automatically after `composer install` or `composer update` and performs the following steps:

1. Read configuration from `composer.json` (`extra` section)
2. Copy public files (`public/`) from the framework package to the project root
3. Calculate path maps for FileFinder, Override, and Asset directories
4. Create the project directory structure (override tree, module tree, public asset tree, logs)
5. Generate three config files: `bootstrap.inc.php`, `moduleManager.inc.php`, `fileFinder.inc.php`

The installer is the first thing that runs when a developer creates a new z77 project. Its output determines how all other framework components find files at runtime.

---

## Overall Assessment

| Dimension | Rating | Notes |
|---|---|---|
| Concept / Approach | 8/10 | Right tool for the job, well thought out |
| Code correctness | 5/10 | 2 critical bugs, 3 medium bugs |
| Error handling | 3/10 | Most failures are silent |
| PHP best practices | 6/10 | PHP 8.2 types present, but several anti-patterns |
| Maintainability | 6/10 | Readable, but SRP violated, naming issues |
| Testability | 2/10 | Fully static, untestable without Composer |

**Verdict:** The installer concept is solid and the general approach is correct for Composer scripts. However, it is not publication-ready. Two critical bugs (CRIT-003, CRIT-004) will cause silent failures in real use. Error handling is insufficient for a tool that writes to disk and creates directories. Before v1.0 publication, at minimum all CRIT and MED issues must be resolved.

---

## What Works Well

- **Correct use of Composer API:** `getExtra()`, `getRepositoryManager()`, `getLocalRepository()`, `getIO()` — all used correctly.
- **Static reset at start of `run()`:** Prevents stale state when post-install and post-update both fire in the same process.
- **Package deduplication:** `$uniquePackages[$name] = $package` correctly eliminates duplicate package entries from the local repository.
- **`stripSourceDir()` helper:** Correctly handles the edge cases that `rtrim($path, '/src')` (character set, not suffix) would not.
- **`array_values(array_filter(...))` pattern:** Ensures safe sequential indexing after filter operations.
- **Interactive overwrite prompt in `copyFiles()`:** Good UX — does not silently overwrite existing files.
- **`composer/composer` as `require-dev`:** Correctly declared so the Composer API types are available for static analysis without being a production dependency.
- **Windows compatibility:** `getTrailingSlashPath()` normalizes backslashes to forward slashes.

---

## Critical Bugs

### CRIT-001 — `$io` set after first use of static state that depends on it

**File:** [Install.php:45-49](../../packages/kernel/core/src/Installer/Install.php#L45-L49)

```php
$config = self::getConfig($composer); // L45 — runs before $io is assigned
// ...
self::$io = $event->getIO();          // L49 — assigned here
```

`getConfig()` does not use `$io` today, so this does not crash. But it is a fragile ordering dependency: anyone who adds a warning or error output inside `getConfig()` will hit a null pointer error. `self::$io` must be set before any other method is called.

**Fix:** Move `self::$io = $event->getIO();` to before `self::getConfig($composer)`.

---

### CRIT-002 — `require_once` breaks on second call in same PHP process

**File:** [Install.php:298-310](../../packages/kernel/core/src/Installer/Install.php#L298-L310)

```php
$defaultBootstrapConfig    = require_once $dir.$name; // returns true on 2nd call
$defaultModuleManagerConfig = require_once $dir.$name; // returns true on 2nd call
```

Composer fires both `post-install-cmd` and `post-update-cmd` in the same PHP process during `composer update`. The static reset at the top of `run()` resets the class properties, but `require_once` is tracked at the PHP engine level — not at the class level. On the second call, `require_once` returns `true` (boolean) instead of the config array. The subsequent `array_merge(true, [...])` will throw a `TypeError` in PHP 8.

**Fix:** Replace `require_once` with `require`:

```php
$defaultBootstrapConfig    = require $dir.$name;
$defaultModuleManagerConfig = require $dir.$name;
```

---

### CRIT-003 — Missing null checks on mandatory config keys

**File:** [Install.php:46-47](../../packages/kernel/core/src/Installer/Install.php#L46-L47)

```php
self::$frameworkPrefix = self::$moduleManagerConfig['frameworkPrefix'];
self::$modulePrefix    = self::$moduleManagerConfig['modulePrefix'];
```

If the `core-module-manager` section is missing or incomplete in `composer.json`, these produce `Undefined array key` notices in PHP 8 and assign `null` to both properties. `null` then propagates silently through `str_starts_with()`, `strtolower()`, and string concatenation — causing wrong directory structures and broken config files without any error message.

**Fix:** Throw an explicit exception if mandatory keys are missing:

```php
self::$frameworkPrefix = self::$moduleManagerConfig['frameworkPrefix']
    ?? throw new \InvalidArgumentException('Missing required config key: core-module-manager.frameworkPrefix');
self::$modulePrefix = self::$moduleManagerConfig['modulePrefix']
    ?? throw new \InvalidArgumentException('Missing required config key: core-module-manager.modulePrefix');
```

---

## Medium Issues

### MED-001 — `scandir()` return value unchecked

**File:** [Install.php:328](../../packages/kernel/core/src/Installer/Install.php#L328)

```php
$items = scandir($source);
foreach ($items as $item) { // TypeError if scandir() returns false
```

`scandir()` returns `false` on failure (permission denied, race condition). `foreach` on `false` throws a `TypeError` in PHP 8 with no useful context.

**Fix:**
```php
$items = scandir($source) ?: [];
```

---

### MED-002 — `file_put_contents()` return value unchecked

**File:** [Install.php:521](../../packages/kernel/core/src/Installer/Install.php#L521)

```php
file_put_contents($dir.$fileName, $content);
```

Returns `false` on failure (disk full, permissions). The installer continues silently — the project is left with a missing or partially-written config file that will cause runtime failures.

**Fix:**
```php
if (file_put_contents($dir.$fileName, $content) === false) {
    throw new \RuntimeException("Failed to write config file: {$dir}{$fileName}");
}
```

---

### MED-003 — `mkdir()` return value unchecked

**File:** [Install.php:493](../../packages/kernel/core/src/Installer/Install.php#L493), also [L325](../../packages/kernel/core/src/Installer/Install.php#L325)

```php
mkdir($realPath, 0775, true);
```

`mkdir()` returns `false` on failure. The installer reports `Created: {path}` and continues even if the directory was never created.

**Fix:**
```php
if (!mkdir($realPath, 0775, true) && !is_dir($realPath)) {
    self::$io->writeError("Failed to create directory: {$realPath}");
}
```

Note: The double check `&& !is_dir()` handles the race condition where another process creates the directory between the `!is_dir()` check and `mkdir()`.

---

### MED-004 — `getConfig()` has misleading name and undeclared side effects

**File:** [Install.php:293](../../packages/kernel/core/src/Installer/Install.php#L293)

`getConfig()` is named as a getter, but it:
1. Sets `self::$bootstrapConfig`
2. Sets `self::$moduleManagerConfig`
3. Returns the raw `$config` array

A method named `get*` should return data without side effects (query, not command). This violates the Command-Query Separation principle and makes the call order in `run()` opaque.

**Fix:** Rename to `loadConfig()` or `initConfig()` to signal that it has side effects.

---

### MED-005 — `getExtra()` called three times; `$directories` should come from already-loaded config

**File:** [Install.php:405](../../packages/kernel/core/src/Installer/Install.php#L405)

```php
$directories = $composer->getPackage()->getExtra()['directories'] ?? [];
```

`getExtra()` is already called in `getConfig()` and the result is available. `createDirectories()` should receive `$directories` as a parameter instead of re-fetching from Composer:

```php
// In run():
$directories = $config['directories'] ?? [];
self::createDirectories($directories);

// Method signature:
private static function createDirectories(array $directories): void
```

This also removes the `$composer` dependency from `createDirectories()` entirely, which is a cleaner interface.

---

### MED-006 — Variable shadowing: inner `$path` hides outer `$path`

**File:** [Install.php:219-231](../../packages/kernel/core/src/Installer/Install.php#L219-L231)

```php
foreach ($psr4 as $namespace => $path) {     // outer: PSR-4 path value
    $paths = (is_array($path)) ? $path : [$path];
    foreach ($paths as $path) {               // inner: shadows outer $path
```

The inner `foreach` reuses `$path`, overwriting the outer loop variable. While not a bug (the outer `$path` is no longer needed at that point), it is a code smell that can cause confusion during maintenance.

**Fix:** Use `$singlePath` for the inner variable.

---

### MED-007 — `$vendorPaths` implicitly created as array

**File:** [Install.php:226](../../packages/kernel/core/src/Installer/Install.php#L226)

```php
$vendorPaths['sourcePaths'] = $vendorPaths['assetPaths'] = [];
```

`$vendorPaths` is never declared as an array before keys are assigned to it. PHP creates it implicitly, but this is not explicit and generates no IDE support.

**Fix:**
```php
$vendorPaths = ['sourcePaths' => [], 'assetPaths' => []];
```

---

## Minor Issues

### MIN-001 — `SOURCE_DIR_SUFFIX` constant is always trimmed — misleading name

**File:** [Install.php:24](../../packages/kernel/core/src/Installer/Install.php#L24), [L80](../../packages/kernel/core/src/Installer/Install.php#L80)

```php
private const SOURCE_DIR_SUFFIX = '/src/';
// ...
$sourceDir = trim(self::SOURCE_DIR_SUFFIX, '/'); // always produces 'src'
```

The constant always needs trimming before use. Either name it `SOURCE_DIR = 'src'` or use it with slashes and do not trim.

---

### MIN-002 — `getContentHeader()` has a trailing space

**File:** [Install.php:287](../../packages/kernel/core/src/Installer/Install.php#L287)

```php
$content .= "// Auto-generated by Z77 Core Installer \n"; // trailing space
```

Minor, but visible in the generated files.

---

### MIN-003 — Inconsistent indentation in generated PHP files

`writeBootstrapConfig()` uses 2-space indentation for array entries. `writeModuleManagerConfig()` mixes 2-space and 4-space. The generated PHP files should use consistent 4-space indentation to match PSR-12.

---

### MIN-004 — `getZ77Modules()` memoization hides ordering dependency

**File:** [Install.php:364-366](../../packages/kernel/core/src/Installer/Install.php#L364-L366)

```php
if (!empty(self::$z77Modules)) {
    return self::$z77Modules;
}
```

If `getZ77Modules()` were called before `setPsr4Paths()` (e.g., by a future refactor), it would cache an empty result and never recompute. The ordering dependency is implicit. A future developer could easily break this. Adding a guard or documenting the requirement would help.

---

## Architecture Observations (Design Decisions, Not Bugs)

### ARCH-A — Fully static class is untestable

The entire class uses static methods and static properties. This is the standard pattern for Composer scripts, but it means the installer cannot be unit-tested without actually running Composer. A more testable approach:

```php
public static function run(Event $event): void
{
    (new self())->execute($event);
}
```

All private static methods become private instance methods. Static state becomes instance state. The class becomes fully testable by instantiating it directly. This is a medium-effort refactor, not a quick fix — suitable for v1.1.

### ARCH-B — Code generation via string concatenation

`writeFileFinderConfig()`, `writeBootstrapConfig()`, and `writeModuleManagerConfig()` build PHP source code by concatenating strings. The generated files contain PHP expressions like `$baseDir.'path/to/dir'` which only work when `$baseDir` is defined in scope at `require` time.

This creates an invisible contract between the installer and the consumer. Alternative approaches (template files, `var_export()` for pure data, a dedicated code generator) would be more robust and easier to maintain. However, the current approach works correctly and is acceptable for v1.0.

### ARCH-C — Single class for 6+ concerns

The class handles: config loading, PSR-4 path analysis, package enumeration, directory creation, file copying, and config file generation. Each is a separate concern. For v1.0 this is pragmatic; for v1.1 these should be split into separate classes (e.g., `DirectoryBuilder`, `ConfigWriter`, `AssetCopier`).

---

## Open Questions

1. **`writeModuleManagerConfig()` filters `$key !== 'modules'`** — does the default `moduleManager.default.inc.php` ever contain a `modules` key? If not, the filter is unnecessary noise. If yes, it should be documented why.

2. **`publicAssetTree` — `type === 'vendor'`** — the condition only processes `vendor` type paths. The `public` type paths in `$publicAssetPaths` are built but never used in `createDirectories()`. Is the `public` type intentionally reserved for future use?

3. **`tplCachePaths` (INST-006)** — the TODO block generates entries in `fileFinder.inc.php`. What consumes them? The FileFinder review will clarify whether this structure is correct.

---

## Action Items Before Publication

| Priority | ID | Action |
|---|---|---|
| Critical | CRIT-001 | Move `$io` assignment before `getConfig()` |
| Critical | CRIT-002 | Replace `require_once` with `require` |
| Critical | CRIT-003 | Add null checks + exceptions for mandatory config keys |
| Medium | MED-001 | Guard `scandir()` against `false` |
| Medium | MED-002 | Check `file_put_contents()` return value |
| Medium | MED-003 | Check `mkdir()` return value |
| Medium | MED-004 | Rename `getConfig()` → `loadConfig()` |
| Medium | MED-005 | Pass `$directories` as parameter, remove `$composer` from `createDirectories()` |
| Medium | MED-006 | Rename inner `$path` → `$singlePath` |
| Medium | MED-007 | Declare `$vendorPaths = [...]` explicitly |
| Minor | MIN-001 | Rename `SOURCE_DIR_SUFFIX` → `SOURCE_DIR = 'src'` |
| Minor | MIN-002 | Remove trailing space in `getContentHeader()` |
| Minor | MIN-003 | Standardize generated PHP indentation to 4 spaces |
| Future | ARCH-A | Refactor static class → static entry + instance methods (v1.1) |
| Future | ARCH-C | Split into separate concern classes (v1.1) |
