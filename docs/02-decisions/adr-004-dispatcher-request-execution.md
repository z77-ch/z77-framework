# ADR-004 — Dispatcher: Separating Route Resolution from Request Execution

**Status:** `APPROVED`
**Date:** 2026-04-20

---

## Context

`Router::dispatch()` was responsible for two distinct concerns:
1. Resolving which controller/action to invoke (routing — done via `ControllerHandler`)
2. Executing the controller and sending the response

Additionally, `AbstractBaseController::run()` called `$response->send()` internally,
making it impossible for any outer layer to intercept the response before it was sent.
This blocked future features like page caching, where the response must be inspectable
before output.

There was no page cache check in the request lifecycle at all.

---

## Decision

Introduce a `Dispatcher` class (`packages/kernel/core/src/Routing/Dispatcher.php`) as a dedicated
execution layer between the Router and the Controller.

**Responsibility split:**

| Class | Responsibility |
|---|---|
| `Router` | Route resolution — delegates to `ControllerHandler`, then calls `Dispatcher` |
| `Dispatcher` | Request execution — cache check, `controller->run()`, `response->send()`, flush |
| `AbstractBaseController` | Business logic execution — returns `ResponseInterface`, never calls `send()` |

`AbstractBaseController::run()` return type changes from `void` to `ResponseInterface`.
`send()` is removed from `run()` — the Dispatcher is the single place that calls it.

---

## Request Lifecycle (after this ADR)

```
index.php
  → Bootstrap::__construct()    infrastructure: config, DI, error handling
  → Bootstrap::pullUp()         routing: request parsing, session
  → Router::dispatch()          route is already resolved — delegates to Dispatcher
  → Dispatcher::execute()
      → [PAGE CACHE CHECK]      placeholder — implemented in a later ADR
      → controller->run()       returns ResponseInterface
      → [PAGE CACHE STORE]      placeholder — implemented in a later ADR
      → response->send()
      → cacheManager->flushToTarget()
```

---

## Page Cache Placeholders

Cache logic is **not implemented** in this ADR. Two clearly marked `TODO` blocks
are placed in `Dispatcher::execute()`:

```php
// TODO ADR-004 — Page cache: check before controller runs
// $cacheKey = $this->buildCacheKey();
// $cached = $this->cacheManager->getPageCache($cacheKey);
// if ($cached !== null) { $cached->send(); return; }

$response = $controller->run();

// TODO ADR-004 — Page cache: store HtmlResponse after controller runs
// if ($response instanceof HtmlResponse) {
//     $this->cacheManager->setPageCache($cacheKey, $response->getHtml());
// }
```

When the page cache ADR is ready, these placeholders are replaced with real logic.

---

## Reasoning

**Why not leave execution in the Router?**
The Router's job is routing — "where does this request go?" Executing the request is
a separate concern. Mixing them violates Single Responsibility and makes it impossible
to insert a cache layer between route resolution and controller execution.

**Why not put the cache check in the Router?**
The Router would need to know about `CacheManager`, `HtmlResponse`, `CachedHtmlResponse`.
These are execution concerns, not routing concerns.

**Why not put the cache check in AbstractBaseController?**
Controllers should not know about caching. A controller's job is to process a request
and return a response — the infrastructure layer decides whether that response comes
from cache or live execution.

**Why move exception handling to Dispatcher?**
`AbstractBaseController` should not handle its own dispatch errors. The Dispatcher is
the execution orchestrator — it is the right place to catch `NotFoundException` and
delegate to `ExceptionHandler`. This makes `run()` a clean, pure execution method.

---

## Consequences

**Easier:**
- Page cache can be added later without touching Router or AbstractBaseController
- `AbstractBaseController::run()` is testable — returns a value instead of sending output
- Single place (`Dispatcher`) where all responses are sent

**Harder / to keep in mind:**
- `AbstractBaseController::run()` must never call `send()` — Dispatcher owns that
- Exception handling for `NotFoundException` is now in Dispatcher, not in the controller

---

## Future Work (out of scope for this ADR)

- Autoload loading (`Functions.php`, `Helper.php`) currently happens in `Router::dispatch()`.
  This belongs in `Bootstrap` and should be moved in a future cleanup.
- Page cache implementation (key building, `CachedHtmlResponse`, `HtmlResponse::getHtml()`)
  will be a separate ADR.

---

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Cache check in Router | Router would need execution-layer dependencies — wrong responsibility |
| Cache check in AbstractBaseController | Controllers must not know about caching infrastructure |
| Keep `send()` in `run()`, intercept via output buffering | Fragile, implicit — interception via return value is explicit and testable |
