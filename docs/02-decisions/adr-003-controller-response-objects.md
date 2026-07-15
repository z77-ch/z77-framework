# ADR-003 — Controller Response Objects

**Status:** `APPROVED`
**Date:** 2026-04-15
**Supersedes (partially):** ADR-002 — the response dispatch section

---

## Context

ADR-002 defined output channels (HTML, AJAX, JSON, PDF, Email) but left dispatch unresolved.
The old pattern used `exit` and direct `header()` calls from within actions, or a void default
where `$this->context` was implicitly passed to `LayoutManager`. Both approaches have problems:

- `exit` in an action bypasses controller cleanup and shutdown logic
- `header()` in an action creates hidden side effects — the action knows too much about HTTP
- void-default for HTML is implicit and inconsistent with every other output type

---

## Decision

**Every action returns a typed Response object. Always. No exceptions.**

No action ever calls `header()`, `exit`, or `echo` directly.
`AbstractBaseController::run()` dispatches the response after the action completes.
All HTTP output is concentrated in one place.

---

## Response Types

Located in `packages/kernel/core/src/Http/Response/`.

| Class | Purpose |
|---|---|
| `ResponseInterface` | Contract: `send(): void` |
| `HtmlResponse` | HTML page — full or partial. `LayoutManager` decides based on `IS_AJAX_HTTP_REQUEST` |
| `JsonResponse` | JSON data with HTTP status code |
| `FileResponse` | File download with headers (`Content-Disposition`, `Content-Type`) |
| `RedirectResponse` | HTTP redirect, default 302 |
| `VoidResponse` | No output, clean termination (background jobs, fire-and-forget actions) |
| `NoContentResponse` | HTTP 204 — success without content (fetch endpoints signalling "up to date / nothing to deliver"; added 2026-07-15) |

---

## Dispatch in AbstractBaseController

```php
protected function run(): void
{
    // ... DI setup ...

    if (method_exists($this, 'preExecute')) {
        $preResult = $this->preExecute();
        if ($preResult instanceof ResponseInterface) {
            $preResult->send();
            return;
        }
    }

    $response = $this->execute(); // returns ResponseInterface

    if ($response instanceof HtmlResponse && method_exists($this, 'preRender')) {
        $this->preRender($response);
    }

    $response->send();
}

private function execute(): ResponseInterface
{
    $actionMethod = $this->actionMethod;
    if (method_exists($this, $actionMethod)) {
        return $this->$actionMethod();
    }
    throw new NotFoundException("Action not found: $actionMethod");
}
```

---

## HtmlResponse and LayoutManager

`HtmlResponse` carries the context array. `LayoutManager` is only involved here.
It detects `IS_AJAX_HTTP_REQUEST` internally and renders full page or partial fragment accordingly.
The action has no knowledge of AJAX — it always returns `HtmlResponse`.

```php
// action
public function listAction(): HtmlResponse
{
    return new HtmlResponse([
        'items' => $this->service->getAll(),
        'title' => 'List',
    ]);
}

// HtmlResponse::send()
public function send(): void
{
    $this->layoutManager->render($this->context); // LayoutManager echoes
}
```

---

## Example: all response types in practice

```php
// HTML page (or AJAX fragment — LayoutManager decides)
return new HtmlResponse(['title' => 'Dashboard', 'items' => $items]);

// JSON (AJAX data / API)
return new JsonResponse(['success' => true, 'id' => $id]);
return new JsonResponse(['error' => 'Not found'], 404);

// File download
return new FileResponse('/var/data/invoice-42.pdf', 'invoice-42.pdf', 'application/pdf');

// Redirect
return new RedirectResponse('/login');
return new RedirectResponse('/dashboard', 301);

// No output (background job triggered, nothing to return to browser)
return new VoidResponse();
```

---

## preExecute and short-circuit

`preExecute()` can return a `ResponseInterface` to short-circuit the action.
Useful for authentication and authorisation checks.

```php
protected function preExecute(): ?ResponseInterface
{
    if (!$this->auth->isLoggedIn()) {
        return new RedirectResponse('/login');
    }
    return null;
}
```

---

## preRender — controller-wide HTML preparation

`preRender()` is called after the action, but only when the response is an `HtmlResponse`.
Use it to add assets or shared context data needed by all actions in a controller — without
repeating it in every action.

```php
protected function preRender(HtmlResponse $response): void
{
    $response->addContext('moduleJs', 'invoice-controller.js');
    $response->addContext('currentUser', $this->auth->getUser());
}
```

For `JsonResponse`, `FileResponse`, `RedirectResponse`, `VoidResponse`, `NoContentResponse` — `preRender()` is never called.
It is exclusively an HTML concern.

---

## What changes from ADR-002

| ADR-002 | ADR-003 |
|---|---|
| `renderJson()` method on base controller | `JsonResponse` object returned from action |
| void default → `$this->context` → LayoutManager | `HtmlResponse` with context → LayoutManager |
| `preRender()` modifies `$this->context` | `preRender(HtmlResponse $response)` — modifies response directly, only called for HTML |
| `preExecute()` void, no short-circuit | `preExecute(): ?ResponseInterface` — can abort with redirect or any response |
| `$this->context` / `addContext()` on controller | removed — context lives in `HtmlResponse` |
| `exit` after JSON/redirect | no `exit` in actions — dispatch ends cleanly |

---

## Consequences

**Easier:**
- Controller dispatch is explicit and testable — return value, not side effects
- Actions are unit-testable: call the method, assert the Response type and data
- HTTP output in one place — no hidden `header()` calls across the codebase
- `LayoutManager` is cleanly scoped: HTML only

**Harder / to keep in mind:**
- Every action must have a return type — forgetting it is a runtime error
- `preExecute()` must explicitly return `null` or a Response — no implicit fallthrough
- PDF and Email (v1.1) are Services called from within actions, not Response types.
  They produce a file or send a message — the HTTP response is separate (e.g. `HtmlResponse` confirming success, or `FileResponse` serving the PDF)

---

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| `void` default for HTML | Implicit — every other response type is explicit. Inconsistency is a maintenance trap |
| `renderJson()` on base controller | Not a return value — different mechanism than every other output type |
| Channels on LayoutManager (html/pdf/mail/json) | LayoutManager is not a router. Its job is HTML rendering only |
| PSR-7 `ResponseInterface` | Correct for APIs, overkill for a focused MVC framework. Own interface is simpler and sufficient |
