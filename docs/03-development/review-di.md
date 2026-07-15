# Review — DI.php (Service Container)

**Date:** 2026-05-03
**File:** `packages/kernel/core/src/DI.php`
**Status:** `[DONE]` — all fixes applied 2026-05-03
**Reviewer:** Claude Code (senior-level analysis)

---

## Context

`DI` is the central service container of the z77 framework. It is a classic Singleton with
a service registry. Services register themselves via `set()` at bootstrap time; all framework
components retrieve them via `DI::getXxx()` magic static calls.

The container is reset once at startup: `DI::getInstance(true)` in `Bootstrap::boot()` ensures
no stale state from a previous request can bleed into the current one.

---

## Overall Assessment

| Dimension | Rating | Notes |
|---|---|---|
| Concept / Approach | 8/10 | Fit for purpose; clean Singleton with lazy instantiation |
| Code correctness | 9/10 | No runtime bugs; one semantically dead code path |
| PHP best practices | 6/10 | Missing type declarations, German comments, one PHP 8 anti-pattern |
| Maintainability | 7/10 | Small class, easy to follow; but API has one silent escape hatch |
| Testability | 5/10 | `getInstance(true)` reset works; static magic calls couple consumers to the Singleton |

**Verdict:** Production-ready at runtime. Six pre-publication issues — no blockers, but all
are straightforward fixes. The class is small enough to clean up in one pass.

---

## What Works Well

- **Singleton reset via `getInstance(true)`** — Bootstrap calls this once at startup. Correct
  pattern; covers both normal requests and test scenarios.
- **Fluent `set()` interface** — Returns `$this` for chaining. Used cleanly in Bootstrap.
- **Lazy instantiation** — Services are not created until first `get()`. No wasted overhead
  for services that are registered but never requested in a given request path.
- **Callable factory support** — `set('FileFinder', function($c) { ... })` receives the
  container as argument, enabling dependency wiring without constructor coupling.
- **RuntimeException on missing service** — `get()` throws with the service name, not a generic
  error. Good for diagnosing misconfigured bootstrap.
- **`__callStatic` magic** — `DI::getFileFinder()` is readable and consistent. The pattern is
  used uniformly throughout the codebase.

---

## Issues

### TYPE-001 — Missing property type declarations

**Severity:** Minor — code quality, no runtime effect
**File:** [DI.php:14-15](../../packages/kernel/core/src/DI.php#L14-L15)

```php
// Current
private static $instance = null;
private $container = [];

// Fix
private static ?self $instance = null;
private array $container = [];
```

PHP 7.4+ supports typed properties. Both are PHP 8.2 declarations and should have them.
`?self` for the Singleton instance, `array` for the container.

---

### LANG-001 — German comments and docblocks

**Severity:** Minor — violates project convention (all code/comments in English)
**File:** [DI.php:6-10](../../packages/kernel/core/src/DI.php#L6-L10), [DI.php:28-34](../../packages/kernel/core/src/DI.php#L28-L34)

Three locations:

```php
// 1. Class-level docblock (line 9) — also contains a typo ("verfügabe" → "verfügbare")
Dependency Injector,
Hier werden alle von der Anwendung global verfügabe Dienste hinterlegt

// 2. @param descriptions in set() — German
@param string                 $name            Name des Service.
@param string|object|callable $classDefinition Definition wie die Instanz zu erstellen ist. [...]
@param bool                   $shared          Gibt es nur eine Instanz für alle ...

// 3. Inline comment in get() (line 62, 67)
// Wird nicht gemeinsam verwendet, also immer eine neue Instanz erstellen.
// Service wurde bisher noch nicht angefordert, also neue Instanz erstellen.
```

Translate all to English.

---

### LANG-002 — External tutorial link in class docblock

**Severity:** Minor — unprofessional in published code
**File:** [DI.php:6](../../packages/kernel/core/src/DI.php#L6)

```php
/**
 * https://poe-php.de/oop/objektorientierung-oop-verwalten-der-dienste-dependency-injection
 * ...
 */
```

`poe-php.de` is a German PHP tutorial site. A link to an external tutorial has no place in
a published framework's source. Remove it. If the pattern should be referenced, link to the
framework's own ADR or docs instead.

---

### DEAD-001 — `$check` parameter on `get()` is a dead escape hatch

**Severity:** Medium — silent null return bypasses type safety; never used in the codebase

**File:** [DI.php:51-59](../../packages/kernel/core/src/DI.php#L51-L59)

```php
public function get(string $name, bool $check = true): mixed
{
    if ($check && !isset($this->container[$name])) {
        throw new \RuntimeException('Requested service "' . $name . '" not defined.');
    }

    $service = ($this->container[$name]) ?? null;

    if (!$service) { return null; }    // ← silent null return when $check = false
    ...
}
```

`$check = false` allows callers to bypass the RuntimeException and receive `null` instead.
No caller in the entire codebase passes `false`. The only effect of this parameter is
that `get()` returns `mixed` instead of `object`, which weakens the return type for all
callers.

**Fix:** Remove the `$check` parameter entirely. Tighten return type to `object`.

```php
public function get(string $name): object
{
    if (!isset($this->container[$name])) {
        throw new \RuntimeException('Requested service "' . $name . '" not defined.');
    }
    ...
}
```

---

### STYLE-001 — Truthiness check on a value that is always an object or null

**Severity:** Minor — misleading, should be strict null check
**File:** [DI.php:59](../../packages/kernel/core/src/DI.php#L59)

```php
// Current
if (!$service) { return null; }

// Fix
if ($service === null) { return null; }
```

`$service` is either a `stdClass` (always truthy) or `null`. Using `!$service` suggests
any falsy value is handled, but that is not possible here. Strict comparison communicates
the actual intent.

Note: Once DEAD-001 is fixed (remove `$check`), the `$service === null` branch becomes
unreachable and the entire `$service` variable can be collapsed:

```php
// After DEAD-001 fix — service is guaranteed to exist:
$service = $this->container[$name];
```

---

### STYLE-002 — `new $definition` for object instance is non-obvious

**Severity:** Minor — valid PHP, but surprising to most readers
**File:** [DI.php:100](../../packages/kernel/core/src/DI.php#L100)

```php
if (is_object($definition)) {
    if ($shared) {
        return $definition;        // return the existing object
    } else {
        return new $definition;    // PHP implicitly uses get_class($definition)
    }
}
```

`new $objectInstance` is valid PHP: it creates a new instance of the object's class by
implicitly calling `get_class()`. This is unexpected for most readers who know `new $string`
but not `new $object`. Use the explicit PHP 8 form:

```php
return new ($definition::class)();
```

This is self-documenting and avoids the implicit behavior.

---

## Architecture Notes (no fix required, document for successors)

### A — Service Locator, not Dependency Injection

The class is named `DI` but implements a **Service Locator** pattern. In true DI, the
container wires dependencies through constructors; services do not know the container exists.
Here, services call `DI::getFileFinder()` directly from anywhere in the code.

This is an intentional choice for a framework of this size: it reduces constructor
boilerplate and is easy to understand. Successors should know the distinction so they do not
misapply the pattern.

If true constructor injection is ever needed (e.g. for testability), this is the ADR to
revisit.

### B — `set()` silently ignores re-registration (first-registered wins)

```php
public function set(string $name, ...): self
{
    if (!isset($this->container[$name])) {   // ← no-op if already registered
        $this->container[$name] = ...;
    }
    return $this;
}
```

Calling `set('FileFinder', ...)` twice does nothing on the second call. This is intentional:
the first registration wins. Useful if e.g. a module registers a service that core also
tries to register. No warning or exception is emitted.

Consequence: registration order matters. Bootstrap registers all core services first, then
module-specific services later (e.g. `AuthService` in `AbstractSecurityController`). The
late-registered `AuthService` works because it has a unique name.

### C — `createInstance()` $shared parameter only affects the object branch

The `$shared` parameter is passed to `createInstance()` but only used in the `is_object`
branch (lines 96-101). Callable and string definitions ignore it — their shared/non-shared
behaviour is handled by `get()` (the `$service->instance` cache). The method signature
implies all three branches care about `$shared`, which is misleading.

This is low-risk (callable factories are always called fresh; string classes always `new`),
but worth knowing when reading the code.

### D — `__callStatic` prefix strip has no guard

`substr($name, 3)` strips the first 3 characters unconditionally. Any magic call works:
`DI::getXxx()` → `Xxx`, `DI::fooBar()` → `Bar`, `DI::xy()` → `` (empty string, throws on
`get('')`). No realistic misuse scenario, but the comment ("e.g. getEntityManager →
get('EntityManager')") implies `get` prefix only — the code does not enforce it.

---

## Pendenzliste

| ID | Severity | Issue | Status |
|---|---|---|---|
| TYPE-001 | Minor | Add type declarations to properties | `[x]` |
| LANG-001 | Minor | Translate German comments/docblocks | `[x]` |
| LANG-002 | Minor | Remove external tutorial link | `[x]` |
| DEAD-001 | Medium | Remove `$check` param from `get()`, tighten return type | `[x]` |
| STYLE-001 | Minor | `!$service` → removed (unreachable after DEAD-001) | `[x]` |
| STYLE-002 | Minor | `new $definition` → `new ($definition::class)()` | `[x]` |
