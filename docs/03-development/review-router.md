# Review — Routing Pipeline (Router / Request / ControllerHandler)

**Date:** 2026-05-03
**Files:**
- `packages/kernel/core/src/Routing/Router.php`
- `packages/kernel/core/src/Http/Request.php`
- `packages/kernel/core/src/Controller/ControllerHandler.php`
**Status:** `[DONE]` — all fixes applied 2026-05-03
**Reviewer:** Claude Code (senior-level analysis)

---

## Context

The routing pipeline resolves an incoming HTTP request to a concrete module/controller/action.
It runs once per request, before the session is started or any controller is instantiated.

**Flow:**
```
index.php
  → Bootstrap::pullUp()
    → Request::runParsing()
        → extractLanguage()          strip language prefix from path
        → Router::match($url)        navigation lookup (alias/canonical)
            → NavigationService::findByUrl()
        if miss → parsePathSegments()   convention fallback: /module/controller/action
    → ControllerHandler::lock()      freeze resolved state
  → Dispatcher::execute()
```

Two routing strategies:
1. **Navigation lookup** — URL found in `navigation.json` → module/controller/action from entry
2. **Convention fallback** — URL segments map positionally to module/controller/action

---

## Overall Assessment

| File | Rating | Notes |
|---|---|---|
| `Router.php` | 10/10 | Perfect thin wrapper — nothing to change |
| `Request.php` | 6/10 | Logic works; 1 URL-parsing bug, dead code, type/access issues |
| `ControllerHandler.php` | 7/10 | Works correctly; return type mismatches, one missing type hint |

**Verdict:** The routing concept is solid and the two-strategy approach (navigation lookup +
convention fallback) is clean and practical. Router.php itself is exemplary — the right level
of abstraction for this codebase. Request.php has one real bug (`urldecode` on path) and
substantial cleanup work. ControllerHandler.php needs type corrections.

---

## Router.php — No Issues

Router.php is 18 lines. It holds exactly one responsibility: delegate a URL to
NavigationService and return the result. The constructor injection is correct. No issues.

The class justifies its existence by decoupling Request from NavigationService (Request only
knows about Router, not about how navigation data is stored or cached). Swapping the routing
strategy later requires only a new Router, not touching Request.

---

## Request.php

### What Works Well

- **Two-phase separation** — `__construct()` reads/parses the request, `runParsing()` does
  routing. Correct: constructors should not throw, and routing can throw.
- **Language extraction before routing** — Clean pre-processing step. Language-prefix rejection
  (two-char invalid language → `InvalidRouteException`) is the right behavior.
- **`resolveRequestMode()`** — `Sec-Fetch-Mode` header is the correct signal for
  browser-vs-AJAX. The removed Accept-header fallback comment explains the deliberate
  non-API stance.
- **`routingLog`** — Lightweight structured log of routing decisions. Useful for debugging
  without polluting normal output.
- **`isReadMethod()`** — Correctly groups GET and HEAD as equivalent for cache/routing
  purposes, with a clear docblock explaining why.

---

### BUG-R001 — `urldecode()` on URI path decodes `+` as space

**Severity:** Bug — incorrect URL handling; `+` in a path segment becomes a space
**File:** [Request.php:285-290](../../packages/kernel/core/src/Http/Request.php#L285-L290)

```php
// Current
private function setRawRequestUri()
{
    $this->rawRequestUri = urldecode($_SERVER['REQUEST_URI'] ?? '');
    if (mb_detect_encoding($this->rawRequestUri) === 'ASCII') {
        $this->rawRequestUri = rawurldecode($this->rawRequestUri);
    }
}
```

`urldecode()` is the `application/x-www-form-urlencoded` decoder: it converts `+` to space.
This is correct for query strings but wrong for URI paths. A literal `+` in a path
(`/search+results`) becomes `/search results` — it can never be routed correctly.

The second `rawurldecode()` pass on already-decoded strings is a no-op, and the
`mb_detect_encoding === 'ASCII'` guard is meaningless here (UTF-8 content is decoded by
`urldecode()` on the first pass and the check is skipped).

**Fix:** Use `rawurldecode()` once, unconditionally.

```php
private function setRawRequestUri(): void
{
    $this->rawRequestUri = rawurldecode($_SERVER['REQUEST_URI'] ?? '/');
}
```

---

### BUG-R002 — `getClientEtag()` return type `?string` vs. `?int` property

**Severity:** Bug — type mismatch between declaration and actual value
**File:** [Request.php:42](../../packages/kernel/core/src/Http/Request.php#L42),
[Request.php:85-88](../../packages/kernel/core/src/Http/Request.php#L85-L88)

```php
private ?int $clientEtag;
// constructor:
$this->clientEtag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (int)$_SERVER['HTTP_IF_NONE_MATCH'] : null;

// getter:
public function getClientEtag(): ?string  // ← says string, returns int
{
    return $this->clientEtag;
}
```

The property is `?int` (cast on assignment). The getter declares `?string`. PHP will coerce
silently in some contexts, but this is a hidden type contract violation. Additionally,
`getIfNoneMatch()` (line 346) returns the raw string value for the same header — two methods
for the same header with different types creates confusion.

`If-None-Match` header values are ETags, which per RFC 7232 are quoted strings and can be
arbitrary byte sequences — not necessarily integers. Casting to `int` loses information
(e.g. a hash-based ETag like `"abc123"` becomes `0`).

**Fix:** Remove the `(int)` cast and the separate `getClientEtag()` method.
`getIfNoneMatch()` is the correct getter.

```php
// Remove:
private ?int $clientEtag;
$this->clientEtag = ...;
public function getClientEtag(): ?string { ... }

// Keep only:
public function getIfNoneMatch(): ?string  // already correct
```

---

### BUG-R003 — `removeBasePath()` missing `private` access modifier

**Severity:** Bug — accidentally public method
**File:** [Request.php:355](../../packages/kernel/core/src/Http/Request.php#L355)

```php
// Current — defaults to public in PHP
function removeBasePath(string $fullPath, string $basePath): string

// Fix
private function removeBasePath(string $fullPath, string $basePath): string
```

PHP methods without an access modifier default to `public`. This internal helper is part
of the public API of the Request class, which is unintentional.

---

### DEAD-R001 — `debug()` method must be removed

**Severity:** High — 113 lines of debug HTML/CSS with `exit` call; not for production
**File:** [Request.php:381-493](../../packages/kernel/core/src/Http/Request.php#L381-L493)

The `debug()` method renders a styled HTML table of all Request properties and calls
`exit`. It is never called by any framework code. It exists as a developer convenience
that was left in the class.

Issues:
- `exit` in a framework class is always wrong — it kills the PHP process with no way for
  callers to intercept or handle it
- 113 lines of inline CSS/HTML in a routing class
- `get_object_vars($this)` exposes all private properties

**Fix:** Delete the method entirely.

---

### DEAD-R002 — Commented-out translator code in `cleanAndTranslate()`

**Severity:** Minor — dead code, noise
**File:** [Request.php:204-212](../../packages/kernel/core/src/Http/Request.php#L204-L212)

```php
// Optional: Übersetzung für Codes
/*
$clean = $this->translator->translateAsCode(
    $cleaner($dirty, $replacement),
    $this->request->getLanguage(),
    MAIN_LANGUAGE
);
*/
```

A never-implemented translator stub. Remove entirely.

---

### DEAD-R003 — `addNavigationUrl()` is a never-implemented TODO stub

**Severity:** Minor — confusing no-op
**File:** [Request.php:371-374](../../packages/kernel/core/src/Http/Request.php#L371-L374)

```php
private function addNavigationUrl(string $segment)
{
    // TODO: Navigation-URL-Handling implementieren
}
```

Called by `parsePathSegments()` for path segments at index ≥ 3 (i.e. URLs with 4+ path
components in convention routing). Currently a no-op — the extra segment is silently ignored.

In z77's convention routing, the pattern is `/module/controller/action` (3 segments max).
Extra segments have no convention meaning; they would be registered as aliases in
`navigation.json` if needed. The silent-ignore behavior is acceptable.

**Fix:** Remove the TODO, clarify intent:

```php
private function addNavigationUrl(string $segment): void
{
    // Extra path segments beyond /module/controller/action are ignored in convention routing.
    // For custom multi-segment URLs, register them as navigation entries with alias_url.
}
```

---

### QUAL-R001 — German comments and docblocks

**Severity:** Minor — violates project convention
**File:** [Request.php](../../packages/kernel/core/src/Http/Request.php) — 10+ locations

```
Line 34:  "Konstruktor: Initialisiert die Request-Daten"
Line 146: "Controller setzen"
Line 163: "Modul setzen"
Line 181: "Prüft, ob ein String eine gültige 2-stellige Sprache ist"
Line 194: "Bereinigt und übersetzt String für Modul/Controller/Action"
Line 204: "Optional: Übersetzung für Codes" (also dead — see DEAD-R002)
Line 217: "Setzt alle Request-Werte auf Default"
Line 233: "Setzt die Pfad-Segmente aus der URL"
Line 270: "URL parsen"
Line 282: "Roh-Request-URI setzen"
Line 352: "Entfernt den vorderen Teil eines Pfads (BasePath)"
Line 368: "Platzhalter für Navigation-URL-Segmente (optional)"
Line 491: "Stoppt die weitere Ausführung, damit nur der Debug-Output angezeigt wird."
```

Translate all to English. Most can simply be removed (the method names already communicate
the intent).

---

### QUAL-R002 — `setModule/setController/setAction` return `bool` misleadingly

**Severity:** Minor — misleading API
**File:** [Request.php:134-179](../../packages/kernel/core/src/Http/Request.php#L134-L179)

All three methods always either return `true` or throw a `NotFoundException`. They never
return `false`. The callers in `parsePathSegments()` do not use the return values.
`bool` implies a non-throwing check; `void` communicates "this succeeds or throws".

**Fix:** Change return types to `void`, remove `return true`.

---

### QUAL-R003 — `cleanAndTranslate()` is public but should be private

**Severity:** Minor — over-exposed API
**File:** [Request.php:196](../../packages/kernel/core/src/Http/Request.php#L196)

The method is an internal helper for sanitizing path segments. It has no reason to be
`public`. No external caller uses it.

**Fix:** `public function cleanAndTranslate(...)` → `private function cleanAndTranslate(...)`

---

### QUAL-R004 — Missing return types on accessor methods

**Severity:** Minor — incomplete type declarations
**File:** [Request.php:224,302,306,326,237,272,284](../../packages/kernel/core/src/Http/Request.php)

```php
public function getModule()      // → string
public function getMethod()      // → string
public function isPost()         // → bool
public function isGet()          // → bool
private function setPathSegments()   // → void
private function parseUrl()      // → array
private function setRawRequestUri()  // → void (also: add after BUG-R001 fix)
```

---

## ControllerHandler.php

### What Works Well

- **`lock()` mechanism** — Freezing the resolved state after routing prevents accidental
  mutation during controller execution. Clean and explicit.
- **Lazy controller instantiation** — `getCurrentControllerInstance()` creates the controller
  only when first needed, not at routing time. Correct.
- **`resolveController/resolveAction` as private internals** — The public interface is
  `has*`, which keeps the resolution logic encapsulated.

---

### BUG-C001 — Getter return types `string` but properties are `?string`

**Severity:** Bug — TypeError in PHP 8 if called before routing completes
**File:** [ControllerHandler.php:27-40](../../packages/kernel/core/src/Controller/ControllerHandler.php#L27-L40)

```php
private ?string $currentActionMethod = null;
private ?string $currentModule = null;
private ?string $currentControllerClassName = null;

public function getCurrentActionMethod(): string       // ← null not allowed
public function getCurrentModule(): string             // ← null not allowed
public function getCurrentControllerClassName(): string // ← null not allowed
```

Each of these properties starts as `null`. If any getter is called before routing
completes (e.g. before `lock()`), PHP 8 throws `TypeError`. In current code this
doesn't happen because the callers only call these after successful routing — but the
type declarations make no guarantee, which is a latent trap for successors.

**Fix:** Assert non-null with a `LogicException` that explains the requirement:

```php
public function getCurrentModule(): string
{
    return $this->currentModule
        ?? throw new \LogicException('ControllerHandler has not resolved a module yet.');
}
```

Same pattern for `getCurrentActionMethod()` and `getCurrentControllerClassName()`.

---

### BUG-C002 — `hasAction()` missing type hint on `$action`

**Severity:** Minor — incomplete declaration
**File:** [ControllerHandler.php:22](../../packages/kernel/core/src/Controller/ControllerHandler.php#L22)

```php
// Current
public function hasAction($action): bool

// Fix
public function hasAction(string $action): bool
```

---

### QUAL-C001 — `has*` methods have state-changing side effects (by design)

**Severity:** Architecture note — no fix required, but document for successors

`hasController()` and `hasAction()` are named like query methods but they mutate state:
`hasController()` sets `$currentModule` and `$currentControllerClassName`;
`hasAction()` sets `$currentActionMethod`. This violates CQS (Command Query Separation).

The pattern works correctly for the single-pass routing flow: Request calls `hasController()`
then `hasAction()` in sequence, and each step both validates and sets. It is not safe to
call these methods as pure checks (e.g. in a loop or conditional) — they advance the
resolved state on each call.

This is the right trade-off for a framework of this size. It should be documented so
successors don't call `hasAction()` twice expecting the second call to be a no-op.

---

### QUAL-C002 — Truthiness check on object property

**Severity:** Minor
**File:** [ControllerHandler.php:44](../../packages/kernel/core/src/Controller/ControllerHandler.php#L44)

```php
// Current
if ($this->currentControllerInstance) {

// Fix
if ($this->currentControllerInstance !== null) {
```

Same pattern as DI.php STYLE-001 — objects should be compared with `!== null`.

---

## Pendenzliste

### Request.php

| ID | Severity | Issue | Status |
|---|---|---|---|
| BUG-R001 | Bug | `urldecode()` on path → use `rawurldecode()` only | `[x]` |
| BUG-R002 | Bug | `getClientEtag()` type mismatch — removed `$clientEtag` property and method | `[x]` |
| BUG-R003 | Bug | `removeBasePath()` missing `private` modifier | `[x]` |
| DEAD-R001 | High | Remove `debug()` method (113 lines, `exit`) | `[x]` |
| DEAD-R002 | Minor | Remove commented-out translator code | `[x]` |
| DEAD-R003 | Minor | `addNavigationUrl()` — removed TODO, documented silent-ignore | `[x]` |
| QUAL-R001 | Minor | Translate German comments/docblocks | `[x]` |
| QUAL-R002 | Minor | `setModule/setController/setAction` return `bool` → `void` | `[x]` |
| QUAL-R003 | Minor | `cleanAndTranslate()` public → private | `[x]` |
| QUAL-R004 | Minor | Add missing return types to accessors | `[x]` |

### ControllerHandler.php

| ID | Severity | Issue | Status |
|---|---|---|---|
| BUG-C001 | Bug | Getter return type `string` on `?string` properties → `LogicException` on null | `[x]` |
| BUG-C002 | Minor | `hasAction($action)` missing `string` type hint | `[x]` |
| QUAL-C001 | Note | `has*` methods mutate state — documented, no code change | `[n/a]` |
| QUAL-C002 | Minor | `if ($this->currentControllerInstance)` → `!== null` | `[x]` |
