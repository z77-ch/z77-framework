# fetch

2026-05-30

## entry

1. `packages/kernel/core/src/Http/Response/FetchResponse.php` — standardized JSON envelope (status, flashes, messages, fields, redirect, data, commands)
2. `packages/kernel/core/src/Http/Response/EnvelopeFields.php` — trait shared between FetchResponse and HtmlResponse for flashes/messages/commands
3. `packages/kernel/shared/res/assets/js/core.js` — single client module: fetch + flash/message + popup + field validation + envelope/command dispatch (loaded by every module)
4. `packages/kernel/core/src/Http/Security/CsrfService.php` — CSRF token generation and validation

## file map

SOURCE=/packages/kernel/core/src/Http/Response/FetchResponse.php
SOURCE=/packages/kernel/core/src/Http/Response/HtmlResponse.php
SOURCE=/packages/kernel/core/src/Http/Response/EnvelopeFields.php
SOURCE=/packages/kernel/core/src/Http/Security/CsrfService.php
SOURCE=/packages/kernel/core/src/Http/RequestMode.php
SOURCE=/packages/kernel/core/src/Http/Request.php
SOURCE=/packages/kernel/core/src/Http/Response/JsonResponse.php
SOURCE=/packages/kernel/core/src/Services/AccessGuard.php
SOURCE=/packages/kernel/core/src/Services/MessageService.php
SOURCE=/packages/kernel/shared/res/assets/js/core.js
SOURCE=/packages/module-backend/res/assets/js/appearance.js
SOURCE=/packages/module-backend/res/view/templates/partials/flashMessages.tpl.php
SOURCE=/packages/module-backend/res/view/templates/partials/popupMessages.tpl.php

## mental model

All server–browser communication uses a standardized JSON envelope. Two response types deliver it:
- **`FetchResponse`** — JSON body, used for explicit fetch endpoints (`status`, `flashes`, `messages`, `fields`, `redirect`, `data`, `commands`).
- **`HtmlResponse`** — HTML body, appends a `<script type="application/json" data-z77-envelope>` block at the end when `flashes`/`messages`/`commands` are populated (via the `EnvelopeFields` trait, shared with `FetchResponse`). `core.js` extracts it before injecting the HTML and dispatches the envelope through the same machinery as the JSON path.

Browser-side `core.js` reads a CSRF token from a `<meta>` tag once at init and attaches it as `X-CSRF-Token` on every POST. `AccessGuard` validates CSRF centrally for all Fetch requests.

- **One JS module**: shared `core.js` contains the fetch communicator, flash/message channels, popup wiring, field validation, envelope/command dispatch, and `data-fetch-*` document-wide wiring. Action-scoped scripts (e.g. `navigation/edit.js`) are lazy-loaded via the `load-script` command and register init functions in `_Z77.scriptInit`.
- Fetch requests skip navigation lookup → use convention routing directly.
- Inspired by `JsonCommunicator` / `AjaxCommunicator` pattern from the WDV framework.

**Separation of concerns:**
- Client (JS): UI state only — filtering, sorting, tree-toggle, tab-switching, print. No server round-trips, no URL construction, no modal-fill logic.
- Server owns all data mutations and HTML rendering. Add/edit forms are server-rendered into the generic popup (any `[data-z77-popup]` `<dialog>` in the layout) via GET, posted back via auto-wired `data-fetch-post`.
- `core.js` is the only Fetch mediator: wires `data-fetch-get` / `data-fetch-post` document-wide on `DOMContentLoaded`, dispatches envelope keys / commands via extensible handler registries. All hooks use data-attributes / `aria-*` so the same code works in any module.
- Module-specific concerns are limited to CSS (e.g. backend's `.be-form__field` look) and the markup that triggers the conventions (e.g. providing the `<dialog data-z77-popup>` in the layout skeleton).

## envelope structure

```json
{
  "status": "success|error|validation",
  "flashes": [
    {"type": "success|info|error", "text": "Cache geleert"}
  ],
  "messages": [
    {"type": "success|info|error", "text": "Background job läuft"}
  ],
  "fields": {
    "email": {"valid": false, "message": "Ungültige E-Mail-Adresse"}
  },
  "redirect": {"url": "/backend/system/dashboard/overview", "delay": 0},
  "data": {},
  "html": "<p>Optional HTML content for generic popup</p>",
  "commands": [
    {"action": "replace-html",   "target": "[data-nav-id='202']", "html": "<div>...</div>"},
    {"action": "remove-element", "target": "[data-nav-id='202']"},
    {"action": "insert-html",    "target": "#section-tree", "position": "prepend", "html": "<div>...</div>"},
    {"action": "close-modal",    "target": "#js-nav-modal"},
    {"action": "scroll-to",      "target": "[data-nav-id='202']"},
    {"action": "reload",         "target": null}
  ]
}
```

`html` and `commands` are optional. `flashes` and `messages` are always present as arrays (possibly empty). Flashes are short-lived (top-right, success/info auto-dismiss), messages are persistent (bottom-left, stay until closed). See [`messages.md`](messages.md).

## controller API

```php
// User feedback goes through MessageService — see messages.md for full rules.
$this->messageService->pushFlash('success', 'Cache geleert');
// or for the persistent bottom-left channel:
$this->messageService->pushMessage('info', 'Background job läuft');

// Always build the response via $this->fetch() — it drains the buffers into the envelope.
$response = $this->fetch();
$response->setStatus('success');
$response->setField('email', false, 'Ungültige E-Mail');
$response->setRedirect('/backend/system/dashboard/overview');
$response->setData(['key' => 'value']);

// Commands (server-issued DOM instructions)
$response->addCommand('remove-element', ['target' => '[data-nav-id="202"]']);
$response->addCommand('close-modal',    ['target' => '#js-nav-modal']);
$response->addCommand('scroll-to',      ['target' => '[data-nav-id="202"]']);

// Update multiple fields from env.data (template spans need data-field="{key}")
$response->setData(array_merge($entity->mapToArray(), ['url_display' => $urlHtml, 'route' => '...']));
$response->addCommand('update-fields', [
    'target' => '[data-nav-id="202"]',
    'fields' => ['name' => 'text', 'url_display' => 'html', 'route' => 'text'],
]);

// HTML in popup
$response->setHtml($this->renderPartial('NavigationController/node', ['entry' => $nav]));

return $response;
```

## field validation handling

The envelope's `fields` key drives client-side validation feedback. `core.js` dispatches it to the default `fields` handler, which calls `_Z77.core.fields.mark()`. Server-side `FetchResponse::setField($name, $valid, $message)` writes the key.

| Step | Detail |
|---|---|
| Server returns `fields[name] = {valid: false, message: '...'}` | `core.js` fields-handler calls `_Z77.core.fields.mark(document, name, message)` |
| Server omits `fields` or returns `valid: true` | no client action — caller is responsible for prior `clear()` (see blur flow) |
| Markings on success | none — server stays silent on the success path |

`_Z77.core.fields` API:

```javascript
_Z77.core.fields.mark(scope, name, message)   // sets aria-invalid="true" + fills error slot
_Z77.core.fields.clear(scope, name)           // sets aria-invalid="false" + clears error slot
_Z77.core.fields.clearAll(scope)              // clears every input that has aria-invalid="true"
```

DOM convention (data-attributes only — module-agnostic):

```html
<div class="be-form__field" data-z77-field-wrapper>
    <label>Name</label>
    <input name="name" aria-invalid="true">
    <small class="be-form__field-error" data-z77-field-error>Name ist ein Pflichtfeld</small>
</div>
```

The `.be-form__field` / `.be-form__field-error` CSS classes are backend-styling only; the JS uses `[data-z77-field-wrapper]` and `[data-z77-field-error]` as anchors and `aria-invalid="true|false"` as state. Backend SCSS targets `.be-form__field input[aria-invalid="true"]` for the invalid look. The error-slot `<small data-z77-field-error>` is auto-created if missing; templates MAY render it pre-emptively (e.g. when the validator already failed on the server).

## blur-based field check

Opt-in per form via `data-check-url`. `core.js` wires a `blur` listener on every named input (skipping hidden / checkbox / radio) — both on initial page load and on every `popup.show()` re-render.

```html
<form data-fetch-post data-check-url="/backend/content/navigation/check-field">
    ...
</form>
```

Flow:

```text
input blur
  → _Z77.core.fields.clear(form, name)         preemptively clear any prior marking
  → POST data-check-url  {field: name, value: '...'}
  → server validates only this one field via Validator::isValid([$field])
    → error  → FetchResponse->setField(name, false, message)  → fields handler marks
    → valid  → empty FetchResponse  → handlers stay silent (already cleared)
```

POST save still runs the full validator. The blur-check is opportunistic UX, never authoritative.

Two guards keep the blur-check from firing spuriously (see FETCH-CHECK-001):
- **dirty-tracking** — a field is only checked after the user typed into it (`input` event). An untouched / autofocused field does not flag "required" merely by being left; submit catches it.
- **cancel guard** — when focus moves onto a `[data-popup-close]` control (Abbrechen / close), the pending blur-check is skipped (the form is being discarded). Implemented via a `mousedown` flag plus a `relatedTarget` check in `_bindCheckUrl`.

## response type handling (core.js)

`core.js` detects the response type and dispatches accordingly:

| Response | core.js behaviour |
|---|---|
| `Content-Type: text/html` | Extract embedded `[data-z77-envelope]` JSON block (if present), inject HTML into popup via `html` handler, then dispatch the embedded envelope (flashes, messages, commands incl. `load-script`) |
| `Content-Type: application/json` with `html` + `commands` | Inject `html` into popup AND execute `commands` |
| `Content-Type: application/json` with `commands` only | Execute `commands`, no popup |
| Command with `target` + `html` | Insert HTML at `target` CSS selector, skip popup |

A fetch endpoint that has nothing to deliver returns `$this->noContent()` — HTTP 204,
no body, no Content-Type (e.g. background revalidation: data unchanged → 204, changed →
fresh HTML). 204 means success without content, not an error. The core.js envelope
machinery is not involved — consumers are hand-rolled `fetch()` flows that branch on
`response.status`.

The generic popup is any `<dialog data-z77-popup>` in the page layout (backend skeleton renders one). `core.js` registers the default `html`-envelope handler that pipes the response into `[data-z77-popup-body]`. A module that doesn't want this behaviour can override the `html` handler via `_Z77.core.fetch.registerEnvelopeHandler('html', ...)`.

**HTML partial responses** use `html-fetch-skeleton.tpl.php` (renders `$main` only — no layout). `HtmlResponse::getHtml()` appends the embedded envelope block when `addFlash/addMessage/addCommand` were called. `LayoutManager` applies the fetch skeleton automatically when the request carries `X-Requested-With: XMLHttpRequest` — which `core.js` sends on every `.get()` and `.post()`. No manual skeleton override needed in the controller.

## commands

Commands are server-issued instructions dispatched by the shared `core.js` to whichever module registered a handler for the command action (`registerCommand(action, fn)`).

| Command | Required params | Optional params | Effect |
|---|---|---|---|
| `replace-html`   | `target`, `html` | — | Replace `outerHTML` of matching element |
| `remove-element` | `target` | — | Remove element from DOM |
| `insert-html`    | `target`, `html` | `position` (prepend\|append\|before\|after, default: append) | Insert HTML relative to target |
| `update-text`    | `target`, `text` | — | Set `textContent` of matching element |
| `update-html`    | `target`, `html` | — | Set `innerHTML` of matching element |
| `set-class`      | `target`, `class`, `on` | — | Toggle a CSS class on the matching element (`on:true` adds, `false` removes) |
| `scroll-to`      | `target` | — | `scrollIntoView` on target element |
| `reload`         | — | — | Full page reload (escape hatch) |
| `close-modal`    | — | — | Calls `_Z77.core.popup.close()` — closes the page's `[data-z77-popup]` `<dialog>` |
| `update-fields`  | `target`, `fields` | — | Update multiple `[data-field]` children from `env.data`; `fields` = `{key: "text"\|"html"}` |
| `load-script`    | `src` | `init`, `scope` | Lazy-loads JS once per `src`; on load and on subsequent calls runs `_Z77.scriptInit[init](scopeEl)` — see "lazy-loaded action scripts" below |

`target` is a CSS selector string (e.g. `[data-nav-id="202"]`, `#js-nav-modal`).

Commands are executed in array order.

## data-attribute wiring (document-wide auto-init)

`core.js` wires `data-fetch-post` / `data-fetch-get` on `DOMContentLoaded` for the initial document, and re-wires server-injected popup content on every `popup.show()`.

| Attribute | Element | Behaviour |
|---|---|---|
| `data-fetch-get="/url"` | any clickable | GET on click |
| `data-fetch-post="/url"` | `<form>` | POST on submit with collected form data (multi-value-aware) |
| `data-fetch-toggle="/url"` | `<input type=checkbox>` | POST `{value: checked}` on change; reverts the checkbox if the response status is not `success` |
| `data-fetch-post=""` (empty) | `<form>` | POST on submit; URL falls back to source URL of last GET that delivered the popup HTML |
| `data-popup-close` | any clickable | closes the popup (the only way to close — see POPUP-CLOSE-001) |
| `data-z77-popup` | `<dialog>` | marks the popup root (`core.js` finds it for show/close and binds the fullscreen toggle; a backdrop click does NOT close — POPUP-CLOSE-001) |
| `data-z77-popup-body` | any element inside the popup | injection target for popup HTML |
| `data-z77-field-wrapper` | any element wrapping a labelled input | anchor for `mark()`/`clear()` field-validation |
| `data-z77-field-error` | `<small>` (or similar) inside a wrapper | error-message slot (auto-created if absent) |
| `data-check-url="/url"` | `<form>` | blur-validates each input via POST to this URL |

**Default POST URL** — server-rendered popup forms can omit the value:

```html
<form data-fetch-post>
    <input type="hidden" name="entity_csrf" value="...">
    <input type="text" name="name" value="…">
    <button type="submit">Speichern</button>
</form>
```

If `data-fetch-post` is empty, the wire helper posts back to the same URL the popup HTML came from (e.g. `/backend/content/navigation/edit?id=3` returns the form → submit posts to `/backend/content/navigation/edit?id=3`). The controller's GET-branch renders, the POST-branch handles save. One endpoint, one URL, no duplication.

For forms outside the popup (or forms targeting a different endpoint), set `data-fetch-post="/explicit/url"` and the explicit value wins.

**Form data collection** — shared `core.js` builds the request body via `_z77CollectFormData(form)`, not `Object.fromEntries(new FormData(form))`:

- Multiple checkboxes sharing the same `name` are collected into an array
- Single checkbox is a boolean
- Radio: selected `value`
- Single-level bracket notation is nested: `name="value[de]"` → `data.value.de` (so a server reading an associative array, e.g. the translation editor's per-language fields, receives `value` as a map, not a flat `value[de]` key). One level only — no `name[]` arrays, no `name[a][b]`.
- Disabled inputs are skipped (use this to make a field read-only without changing entity state)
- Button elements are skipped

Confirm-delete partial example (explicit URL, target differs from source):

```html
<form data-fetch-post="/backend/content/navigation/remove">
    <input type="hidden" name="id"          value="202">
    <input type="hidden" name="entity_csrf" value="...">
    <div class="be-modal__footer">
        <button type="button" data-popup-close>Abbrechen</button>
        <button type="submit">Löschen</button>
    </div>
</form>
```

## CSRF — synchronizer token pattern

| Step | Detail |
|---|---|
| Generation | `CsrfService` generates token, stored in session (per-session, not per-request) |
| Backend layout | rendered as `<meta name="csrf-token" content="...">` |
| Forms | also rendered as hidden `<input name="csrf_token">` |
| Client read | `FetchCommunicator` reads meta tag once at init |
| Transport | sends `X-CSRF-Token` header on every POST |
| Validation | `AccessGuard` validates centrally for all Fetch requests |

## CSRF — entity-scoped token (destructive actions)

For destructive operations (delete), an additional entity-scoped token is used alongside the global CSRF token. It is bound to a specific entity type and ID — a token for `navigation:202` cannot be used for `navigation:203`.

```php
// Generation (in confirm-delete GET action)
$token = $csrfService->generateEntityToken('navigation', $id);

// Validation (in remove POST action)
$valid = $csrfService->validateEntityToken($body['entity_csrf'], 'navigation', $id);
```

Implementation: `hash_hmac('sha256', "{$context}:{$id}", $sessionCsrfToken)` — stateless, no extra session entries.

**Confirm-delete flow:**

```text
GET  /backend/content/navigation/confirm-delete?id=202
     → server generates entityToken for navigation:202
     → returns HTML partial (confirmation dialog with entityToken in hidden field)
     → core.js html-handler injects partial into popup, shows it

POST /backend/content/navigation/remove  { id: 202, entity_csrf: '...' }
     → server regenerates hash_hmac for navigation:202
     → hash_equals → valid → execute delete → return commands
```

**Edit flow (same pattern):**

```text
GET  /backend/content/navigation/edit?id=202
     → server generates entityToken for navigation:202
     → returns HTML partial (edit form with empty data-fetch-post, entityToken hidden)
     → core.js html-handler injects into popup, wires data-fetch-post with fallback URL = GET URL

POST /backend/content/navigation/edit?id=202  { entity_csrf, name, url, ... }
     → server validates entityToken for navigation:202
     → BodyCleaner → mapFromArray → validator
     → on success: update-fields + close-modal + scroll-to commands
     → on validation error: re-renders the form (validator errors visible in fields)
```

**Add flow (symmetric):**

```text
GET  /backend/content/navigation/add
     → server renders empty edit form (isNew=true, no CSRF token)
     → core.js html-handler injects into popup, fallback POST URL = /backend/content/navigation/add

POST /backend/content/navigation/add  { name, url, ... }
     → BodyCleaner → mapFromArray → validator
     → on success: FileEntityManager assigns id → reload + close-modal
```

## script loading — defer, no init.js

```html
<script src="/assets/shared/js/core.js"           defer></script>
<script src="/assets/backend/js/appearance.js"    defer></script>
<script src="/assets/backend/js/system/cache.js"  defer></script>
```

`defer` guarantees:
- Parallel download (no render blocking)
- Execution after full DOM parse
- Order within the page is preserved (so `core.js` always initialises before any module extends it)

No `init.js`, no `DOMContentLoaded` wrapper required.

## lazy-loaded action scripts

For action-scoped behaviour that's only needed when a specific modal opens (e.g. the ref-toggle in the navigation edit form), controllers emit a `load-script` command with the asset path:

```php
$response->addCommand('load-script', [
    'src'   => $this->layoutManager->resolveJsPath('navigation/edit', self::NAMESPACE),
    'init'  => 'navigation-edit',
    'scope' => '[data-z77-popup-body]',
]);
```

`LayoutManager::resolveJsPath()` returns a versioned web path without registering the asset in `$jsFooter` (the fetch skeleton doesn't render those anyway).

The script registers its initialiser:

```javascript
_Z77.scriptInit['navigation-edit'] = function (scope) {
    // scope = scopeEl from the command (e.g. the popup body)
    // run any DOM-binding for this specific modal here
};
```

First `load-script` call appends the `<script>` to `<head>` and runs `init` on load. Subsequent calls skip the network and re-run `init` against the new scope (every popup-open gets a fresh init).

## JS architecture (single module + lazy scripts)

| File | Content | Loaded |
|---|---|---|
| `core.js` (shared) | fetch + CSRF + flash/message channels + popup + field validation + check-url binding + envelope/command dispatch + `data-fetch-*` wiring + default commands incl. `load-script` | every module |
| `appearance.js`, `system/cache.js` (backend) | small, action-aware behaviours always loaded with the backend layout | backend module |
| `{module}/{controller}/{action}.js` | per-action behaviour, **lazy-loaded** via `load-script` command, registers `_Z77.scriptInit[name]` | on demand, deduplicated by src |

## controller declares scripts via layoutConfig

Scripts that should be present on every page of a module are declared via `layoutConfig` (config pattern), not via a `$scripts` property. `LayoutManager` reads the config and renders `<script defer>` tags in the order they appear. `core.js` is always declared first (so action scripts and module extensions can find `_Z77.core.fetch.registerEnvelopeHandler` etc. when they execute).

Action-scoped scripts skip layoutConfig entirely — they ride in via `load-script`.

## FetchCommunicator (core.js)

```javascript
_Z77.core.fetch.post('/backend/system/system/clear-cache', data)
_Z77.core.fetch.get('/backend/system/system/status')

// Extension points (used by modules to opt into custom envelope/command handling):
_Z77.core.fetch.registerEnvelopeHandler('html', function (html, _env, sourceUrl) { ... });
_Z77.core.fetch.registerCommand('my-custom-command', function (p) { ... });
```

Internal behavior:
- Reads `<meta name="csrf-token">` once at init.
- Sends `X-CSRF-Token` header on POST.
- For `text/html` responses: extracts embedded `[data-z77-envelope]` JSON block, runs the `html`-handler (popup.show), then dispatches the envelope (so the order is: HTML lands in popup → flashes/messages/commands fire, including `load-script` whose `scope` selector then resolves against the just-mounted popup body).
- For `application/json` responses: dispatches every envelope key through its registered handler.
- Defaults: envelope handlers (`flashes`, `messages`, `redirect`, `commands`, `html`, `fields`), commands (`replace-html`, `remove-element`, `insert-html`, `update-text`, `update-html`, `scroll-to`, `reload`, `close-modal`, `update-fields`, `load-script`).
- Modules opt in to more via the two `register*` methods.

## field validation flow (opt-in via data-check-url)

| Step | Detail |
|---|---|
| Trigger | `blur` event on form field |
| Request | POST to `data-check-url` (one endpoint per form) |
| Server | `FetchResponse` with `setField()` on error, empty body on success |
| Client | `_Z77.core.fields.clear` preemptively, `mark` on error response via the `fields`-envelope handler |
| Wiring | bound in `core.js` on `DOMContentLoaded` for the initial document and on every `popup.show()` for popup-injected forms |

## use cases

```text
[1] Simple action (clear-cache, debug-toggle)
User clicks button
→ JS: fetchCommunicator.post(url)
→ Server: MessageService->pushFlash + $this->fetch()->setStatus('success')
→ JS: render flashes[] via flash.show, no reload

[2] Field validation (blur)
User leaves field
→ JS: fetchCommunicator.post('/validate', {field: 'email', value: '...'})
→ Server: FetchResponse status=validation, fields={email: {valid: false, message: '...'}}
→ JS: mark field red, show inline message

[3] Form save
User submits
→ JS: fetchCommunicator.post(url, formData)
→ Server: validate all fields → FetchResponse
→ JS: on error mark fields / on success show flash + optional redirect
```

## rules

- When returning data from a Fetch endpoint → MUST construct a `FetchResponse` envelope; MUST NOT return raw `JsonResponse` from controllers
- When a Fetch endpoint has nothing to deliver (data unchanged / up to date) → MUST return `$this->noContent()` (HTTP 204); MUST NOT signal errors with 204 and MUST NOT set status codes via raw `http_response_code()`
- When validating CSRF for Fetch requests → MUST happen in `AccessGuard`; controllers MUST NOT validate CSRF themselves
- When declaring controller-specific JS → MUST use `layoutConfig` (config pattern); MUST NOT use a `$scripts` property
- When implementing field validation → MUST POST to a dedicated endpoint per form via `data-check-url` on the form; MUST NOT reuse the save endpoint
- When rendering a form template that should support inline error display → MUST wrap fields in an element marked `data-z77-field-wrapper` (backend uses `.be-form__field` for styling), MUST set `aria-invalid="true"/"false"` on the input, and MUST emit `<small data-z77-field-error>` from `$validator` on render (backend adds `.be-form__field-error` for styling)
- When a controller's checkField action is hit → MUST validate exactly the requested field via `Validator::isValid([$field])`; MUST return an empty `FetchResponse` on success (no message, no commands)
- When issuing a delete action → MUST use a confirm-delete GET endpoint that returns an HTML partial with an entity-scoped CSRF token; MUST NOT use client-side `confirm()`
- When a controller action returns `HtmlResponse` in fetch mode (e.g. confirm-delete partial) → MUST call `$this->layoutManager->addPartials(action, controller, namespace)` after `$this->html()` — `initialize()` skips auto-template-loading in fetch mode
- When using entity-scoped CSRF → MUST validate via `CsrfService::validateEntityToken()`; MUST NOT skip validation on the remove endpoint
- When controller needs to update a specific DOM element after save → MUST use `addCommand('replace-html', ...)` with a rendered partial; MUST NOT instruct JS to `location.reload()` unless structure change makes targeted update impractical
- When controller-specific JS calls the server → MUST use `_Z77.core.fetch.post()` / `.get()`; MUST NOT define a local `fetch` wrapper
- When an inline status field (e.g. `active`) is toggled directly from a list row → MUST use `data-fetch-toggle="/url"` on the checkbox (server-authoritative: POST persists and returns a `set-class` command for the row; the checkbox reverts on a non-success response); the endpoint is `#[Fetch, HttpMethod('POST')]`, relies on the global CSRF header, and MUST NOT require an entity-scoped token (non-destructive). MUST NOT wire the change handler in a per-controller JS file.
- When closing a popup → MUST be a deliberate discard: a `[data-popup-close]` control (Abbrechen / ×) or the ESC key (both accepted, POPUP-ESC-001); a backdrop click does NOT close it (POPUP-CLOSE-001) and MUST NOT be relied on as a discard path (it discarded edits on a stray click or a text-selection drag onto the backdrop)

## see also

- [`messages.md`](messages.md) — `MessageService` is the single source of truth for both feedback channels; `flashes[]` and `messages[]` envelope slots are drained by `$this->fetch()`
- [`login.md`](login.md) — `AccessGuard` is the same pipeline service that enforces auth + CSRF
- [`view-layer.md`](view-layer.md) — `html-fetch-skeleton` for Fetch-mode rendering

## known issues

- **FIELD-001** — resolved 2026-05-16, restructured 2026-05-17, consolidated 2026-05-21. Blur-based field validation. Originally backend-only inside `backendForm.js`; consolidated into shared `core.js` (see CORE-CONSOLIDATION-001) with data-attribute / `aria-invalid` markup. Opt-in via `data-check-url` on form. Reference implementation: `NavigationController::checkFieldAction` + `edit.tpl.php`. POST-only path: `tagEdit.tpl.php` (no `data-check-url`).
- **CORE-CONSOLIDATION-001** — resolved 2026-05-21. `backendForm.js` removed entirely. Its responsibilities (popup show/close, field mark/clear, check-url blur binding, html/fields envelope handlers, close-modal/update-fields commands) moved into shared `core.js`. All DOM coupling that used to live in CSS classes (`.be-form__field--invalid`) and IDs (`#z77-popup`) is now via data-attributes (`[data-z77-popup]`, `[data-z77-popup-body]`, `[data-z77-field-wrapper]`, `[data-z77-field-error]`) and native `aria-invalid`. Backend module renders the data-attributes in its skeleton + form templates; CSS targets `[aria-invalid="true"]` for the invalid look. Frontend or any other module gets the full fetch + popup + form-validation machinery for free as soon as it provides matching markup. `layoutConfig.inc.php` no longer references `backendForm`.

- **FETCH-CHECK-001** — resolved 2026-05-30. `_bindCheckUrl` fired the field-check on blur in two unwanted cases: (a) clicking Abbrechen/Close blurred the focused field → premature error right before the form was discarded; (b) leaving an untouched required field (e.g. the autofocused Name) → instant "Pflichtfeld". Fix in `core.js`: a cancel guard (`mousedown` on `[data-popup-close]` + `relatedTarget`) and dirty-tracking (blur-check runs only after an `input` event on the field). Submit still runs full server-side validation.

- **POPUP-CLOSE-001** — resolved 2026-06-05. The `<dialog>` backdrop-click handler closed the popup whenever the `click` target was the root element. Because `click` fires on the common ancestor of mousedown/mouseup, starting a text selection inside an input and releasing on the backdrop landed on the root → the popup closed and the edit was lost. Fix in `core.js` `popup.bindBackdrop()`: close-on-backdrop-click was removed entirely — an edit is discarded only via an explicit `[data-popup-close]` control. The fullscreen toggle (`[data-popup-fullscreen]`) stays bound on the root.

- **POPUP-ESC-001** — resolved 2026-06-07 (by decision, no code change). The native `<dialog>` (`showModal()`) closes on the ESC key. Decided to keep it: ESC is a deliberate keyboard action, not the accidental-discard path that POPUP-CLOSE-001 removed for backdrop clicks. Both `[data-popup-close]` (Abbrechen / ×) and ESC are accepted discard paths; only the backdrop click is excluded.

- **FORM-BRACKET-001** — resolved 2026-06-10. `_z77CollectFormData` flattened every input name verbatim, so the translation editor's per-language fields (`name="value[de]"`) arrived as literal keys `value[de]`/`value[fr]` instead of a nested `value` map. `TranslationController::readValues()` reads `$body['value']` as an array → got `null` → empty values → `saveUiEntry()` wrote the default language as `''` and dropped every non-default key. Symptom: editing any UI text (or slug), saving, all fields blank. Fix in `core.js`: a `put(name,value)` helper parses single-level bracket notation (`value[de]` → `data.value.de`) for the radio + scalar branches; checkbox-array/boolean behaviour unchanged. Applied to `core.js` + hand-maintained `core.min.js` (prod loads `.min.js`) in `packages/kernel/shared` and the installed `skeleton/public` copies. The translation editor was the first/only consumer of bracket-name inputs.

## pending

_(none)_
