# ADR-006 — Action Constraints via PHP Attributes

**Status:** `APPROVED`
**Date:** 2026-05-20

---

## Context

Before this decision, every action that accepted only a specific HTTP method
(typically `POST` for state-changing operations) carried the check inside the
action body:

```php
protected function clearCacheAction(): FetchResponse
{
    if (!DI::getRequest()->isPost()) {
        $this->messageService->pushFlash('error', 'Method not allowed');
        return $this->fetch()->setStatus('error');
    }
    // ... real work ...
}
```

Two concrete problems:

1. **Response format hardcoded to JSON.** `$this->fetch()->setStatus('error')`
   produces a `FetchResponse`. When a user typed
   `/backend/system/system/clear-cache` into the browser address bar
   (`RequestMode::Page`), the server returned raw JSON — visible in the
   browser, not an HTML error page. Symptom-of-bug, not catastrophic, but
   inconsistent with the rest of the 404 handling.

2. **Boilerplate in every POST-only action.** Seven actions across two
   controllers (`SystemController` × 3, `NavigationController` × 4) carried
   identical four-line guards. The check is not action logic — it is a
   routing concern that happens to live in the wrong layer.

A third dimension is `RequestMode`: some actions only make sense as Fetch
(AJAX) requests, others only as Page (browser) navigation. There was no
declarative way to express that — every action that cared had to compose
`isPost()` with `getMode()` checks ad hoc.

---

## Decision

Introduce three PHP attributes on action methods. The `Dispatcher` reads them
via Reflection BEFORE invoking the action and throws `NotFoundException` on
violation. The `ExceptionHandler` renders the error in a format that matches
the `RequestMode` — HTML for Page, JSON for Fetch.

### Attributes

```php
namespace Z77\Shared\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Fetch {}                              // RequestMode constraint

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Page {}                               // RequestMode constraint

#[\Attribute(\Attribute::TARGET_METHOD)]
final class HttpMethod {                          // HTTP method whitelist
    public readonly array $methods;
    public function __construct(string ...$methods) {
        if ($methods === []) {
            throw new \InvalidArgumentException('#[HttpMethod] requires at least one method name');
        }
        $this->methods = array_map('strtoupper', $methods);
    }
}
```

### Enforcement point: `Dispatcher::enforceActionConstraints()`

```php
private function enforceActionConstraints(object $controller, Request $request): void
{
    $ref = new \ReflectionMethod($controller, DI::getControllerHandler()->getCurrentActionMethod());

    if ($ref->getAttributes(Fetch::class) !== [] && $request->getMode() !== RequestMode::Fetch) {
        throw new NotFoundException('Action requires Fetch mode');
    }
    if ($ref->getAttributes(Page::class) !== [] && $request->getMode() !== RequestMode::Page) {
        throw new NotFoundException('Action requires Page mode');
    }

    $methodAttrs = $ref->getAttributes(HttpMethod::class);
    if ($methodAttrs !== []) {
        $allowed = $methodAttrs[0]->newInstance()->methods;
        if (!in_array(strtoupper($request->getMethod()), $allowed, true)) {
            throw new NotFoundException('Action does not accept method: ' . strtoupper($request->getMethod()));
        }
    }
}
```

Called from `Dispatcher::execute()` BEFORE `AccessGuard::enforce()` and BEFORE
`controller->run()`. Result: the action body never runs on a constraint
violation.

### Format-aware error rendering: `ExceptionHandler`

```php
public static function handle(\Throwable $e, string $format = 'auto'): void
{
    // ... status code mapping ...
    if ($format === 'auto') {
        $format = self::resolveFormatFromRequest();   // Fetch → json, Page → html
    }
    // ... render in chosen format ...
}
```

Default `'auto'` means callers no longer pass a format — the handler asks
the `Request` for its `RequestMode`. Explicit `'html'` / `'json'` overrides
remain available for edge cases.

### Opt-in semantics

No attribute = no constraint = action handles dispatch itself. Example:

```php
protected function loginAction(): HtmlResponse|RedirectResponse
{
    if (DI::getRequest()->isPost()) { return $this->handlePost(); }
    return $this->renderForm();
}
```

`loginAction` accepts both GET and POST in Page mode. It branches internally
and remains attribute-free. This is the **declared default behavior** of any
action without constraint attributes.

### Migration

Removed `if (!isPost()) { ... return fetchError(...); }` blocks from seven
actions and replaced them with `#[Fetch, HttpMethod('POST')]`:

| Controller | Action |
|---|---|
| `SystemController` | `clearCacheAction`, `savePreferencesAction`, `toggleDebugAction` |
| `NavigationController` | `removeAction`, `checkFieldAction`, `moveAction`, `removeTagAction` |

---

## Reasoning

**Why attributes and not interface/trait/base-method?**
Attributes are **deparated metadata**. An action with `#[Fetch, HttpMethod('POST')]`
declares its constraints in its signature — visible at first glance, no
indirection through inheritance. They compose cleanly: a method can carry
multiple, unrelated attributes. A base method like `requirePostOnly()` would
require imperative code at the start of every action and offer no protection
against being forgotten.

**Why check in `Dispatcher` and not in the controller's `run()` method?**
Centralization. The `Dispatcher` is the single chokepoint between routing and
action execution — every action goes through it. Putting the check in
`BackendAbstractController::run()` would duplicate the logic in
`FrontendAbstractController` and any future controller base. The `Dispatcher`
also already throws `NotFoundException` for other routing failures, so the
new check fits the existing exception flow.

**Why `NotFoundException` and not a new `MethodNotAllowedException` (HTTP 405)?**
z77 is not an API backend (per ADR-005). The HTTP-correct 405 with `Allow:`
header is more meaningful to API clients than to a browser user or AJAX
caller. Reusing `NotFoundException` keeps the exception hierarchy small and
the error response consistent with other routing failures. If z77 ever needs
to serve a public API, a `MethodNotAllowedException` can be added then
without breaking the attribute contract.

**Why opt-in instead of strict (e.g. default GET-only unless declared)?**
Strict defaults would require every existing action to carry an attribute,
breaking the action signatures without functional gain. The migration cost
would be high and the win small. Opt-in lets each module adopt the attributes
where they help.

**Why three attributes and not one combined `#[Endpoint(mode: ..., methods: ...)]`?**
The two dimensions (mode and method) are orthogonal — many actions need only
one of them, never both. Three small attributes read more naturally
(`#[Fetch, HttpMethod('POST')]`) than one parameterized attribute
(`#[Endpoint(mode: 'fetch', methods: ['POST'])]`). PHP attribute syntax
favors the small composition.

**Why is `ExceptionHandler` mode-aware now and not at the call site?**
Two call sites currently pass exceptions: `Bootstrap::pullUp()` and
`Dispatcher::execute()`. Both would need to pass a format. Pushing the
detection into `ExceptionHandler::resolveFormatFromRequest()` removes that
duplication and makes the handler the single source of truth for error
rendering.

---

## Consequences

**Easier:**

- New POST-only actions are one attribute away from being correctly guarded.
- Browser GETs of POST-only URLs return HTML 404 — consistent with
  `/backend/nonexistent` and other routing failures.
- AJAX clients of Fetch-only actions called with wrong method get a JSON 404
  — easy to handle in `core.js` error pipeline.
- Action bodies focus on action logic, not on dispatch concerns.

**Harder / to keep in mind:**

- Adding a new constraint type (e.g. `#[RequiresAuth]`, `#[RoleAtLeast('admin')]`)
  must be registered in `enforceActionConstraints()` — there is no plugin
  registry. Adding a constraint should always be a code change there.
- Reflection cost runs once per request. Negligible (microseconds), but
  measurable in a tight microbenchmark. No caching layer added — premature.
- An attribute on a method the dispatcher never invokes (e.g. private helper)
  is silently ignored. This is harmless but means linting cannot catch
  misplaced attributes.

---

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Throw `NotFoundException` in each action's `if (!isPost())` block | Fixes the JSON-output bug but keeps the boilerplate. Doesn't address the `RequestMode` dimension. |
| One combined `#[Endpoint(mode: ..., methods: ...)]` attribute | More verbose at the call site for the common single-axis case; PHP attribute syntax composes small attributes more naturally than named arguments in a single one. |
| Trait `PostOnly` mixed into actions / controllers | PHP traits cannot constrain a single method without runtime branching; would degenerate to a base method like `requirePostOnly()`. |
| Configuration in module config (`'postOnly' => ['clearCache', ...]`) | Constraint detached from action signature — high coupling, easy to forget when adding/renaming actions. |
| `MethodNotAllowedException` → 405 with `Allow:` header | Correct HTTP semantics but z77 is not an API backend; the symbolic difference between 404 and 405 has no real consumer here. Adds an exception type for no functional gain. |
| `format` parameter passed by every caller of `ExceptionHandler::handle()` | Duplicated logic at every call site; the handler already has access to `DI::getRequest()`. |

---

## Implementation Summary

Touched files:

| Area | Files |
|---|---|
| Attributes | `packages/kernel/shared/src/Attributes/Fetch.php`, `Page.php`, `HttpMethod.php` (new) |
| Dispatcher | `packages/kernel/core/src/Routing/Dispatcher.php` — `enforceActionConstraints()` added, called from `execute()` |
| Exception rendering | `packages/kernel/core/src/Exception/ExceptionHandler.php` — default `format='auto'`, `resolveFormatFromRequest()` added |
| Actions migrated | `module-backend/.../System/SystemController.php`, `module-backend/.../Content/NavigationController.php` |

Documentation:

- `docs/topics/routing.md` — new "action constraints (attributes)" section + rule + known issues ROUTE-001/ROUTE-002.
