# fetch

2026-10-22

## entry

1. `packages/kernel/core/src/Http/Response/FetchResponse.php` — standardized JSON envelope (status, flashes, messages, fields, redirect, data, commands)
2. `packages/kernel/core/src/Http/Response/EnvelopeFields.php` — trait shared between FetchResponse and HtmlResponse for flashes/messages/commands
3. `packages/kernel/shared/res/assets/js/core.js` — single client module: fetch + flash/message + popup + field validation + clipboard + envelope/command dispatch (loaded by every module)
4. `packages/kernel/core/src/Http/Security/CsrfService.php` — CSRF token generation and validation

## file map

SOURCE=/packages/kernel/core/src/Http/Response/FetchResponse.php
SOURCE=/packages/kernel/core/src/Services/LayoutManager.php
SOURCE=/packages/kernel/core/src/Controller/AbstractBaseController.php
SOURCE=/packages/kernel/core/src/autoload/debug/php/Functions.php
SOURCE=/tests/core-js.mjs
SOURCE=/tests/modal-page.php
SOURCE=/tests/js-min-markers.php
SOURCE=/packages/kernel/core/src/Http/WindowOrigin.php
SOURCE=/packages/kernel/shared/res/scss/components/_windows.scss
SOURCE=/packages/module-backend/res/scss/components/_windows-host.scss
SOURCE=/packages/module-backend/res/scss/components/_menu.scss
SOURCE=/packages/kernel/core/src/Http/Response/HtmlResponse.php
SOURCE=/packages/kernel/core/src/Http/Response/EnvelopeFields.php
SOURCE=/packages/kernel/core/src/Http/Security/CsrfService.php
SOURCE=/packages/kernel/core/src/Http/RequestMode.php
SOURCE=/packages/kernel/core/src/Http/Request.php
SOURCE=/packages/kernel/core/src/Http/Response/JsonResponse.php
SOURCE=/packages/kernel/core/src/Services/AccessGuard.php
SOURCE=/packages/kernel/core/src/Services/MessageService.php
SOURCE=/packages/kernel/shared/res/assets/js/core.js
SOURCE=/packages/kernel/shared/res/assets/js/upload.js
SOURCE=/packages/kernel/shared/src/Upload/UploadPolicy.php
SOURCE=/packages/kernel/shared/res/view/templates/partials/upload.tpl.php
SOURCE=/packages/kernel/shared/res/scss/components/_upload.scss
SOURCE=/packages/module-backend/res/scss/components/_upload-host.scss
SOURCE=/tests/upload-component.php
SOURCE=/packages/module-backend/res/assets/js/appearance.js
SOURCE=/packages/module-backend/res/view/templates/partials/flashMessages.tpl.php
SOURCE=/packages/module-backend/res/view/templates/partials/popupMessages.tpl.php

## mental model

All server–browser communication uses a standardized JSON envelope. Two response types deliver it:
- **`FetchResponse`** — JSON body, used for explicit fetch endpoints (`status`, `flashes`, `messages`, `fields`, `redirect`, `data`, `commands`).
- **`HtmlResponse`** — HTML body, appends a `<script type="application/json" data-z77-envelope>` block at the end when `flashes`/`messages`/`commands` are populated (via the `EnvelopeFields` trait, shared with `FetchResponse`). `core.js` extracts it before injecting the HTML and dispatches the envelope through the same machinery as the JSON path.

Browser-side `core.js` reads a CSRF token from a `<meta>` tag once at init and attaches it as `X-CSRF-Token` on every POST. `AccessGuard` validates CSRF centrally for all Fetch requests.

⚠️ **A hand-written `fetch()` must send that header too — a token in the body does NOT satisfy the guard** (FETCH-CSRF-001, found 2026-08-12 in AXO3's member area). `AccessGuard::enforce()` runs before any controller and reads the token from `X-CSRF-Token` ONLY (`Request::getCsrfToken()`); the answer to a missing header is an envelope carrying `CSRF token invalid` — as a **flash**, not a message, so a client that only reads `messages` shows nothing and the switch merely flickers back.

The trap is that this is invisible to a script test: the mode comes from `Sec-Fetch-Mode` (`resolveRequestMode()`), which only a real browser sends. curl/PowerShell/Postman land in Page mode, walk past the guard, and prove nothing about the browser. **Verify anything fetch-driven in a browser, or send `Sec-Fetch-Mode: cors` explicitly.** Keep the body field as well when the action validates in-action (both AXO3 member switches do).

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
| `set-value`      | `target`, `value` | `origin` | Sets a field's `.value` and fires a bubbling `change` when it changed — whatever follows the field runs as on a choice by hand (2026-10-18; first user: debtor's customer picker sets the editor's `price_mode` from the debtor, the order's rows re-convert — core.js «price mode») |
| `scroll-to`      | `target` | — | `scrollIntoView` on target element |
| `reload`         | — | — | Full page reload (escape hatch) |
| `close-modal`    | — | — | From a window: closes that window (ADR-047). With the shared popup on the page: `_Z77.core.popup.close()`. On a host without it (the member shell's native `<dialog>`s): closes the open `dialog[open]` — one vocabulary for both shells (2026-10-10) |
| `update-fields`  | `target`, `fields` | — | Update multiple `[data-field]` children from `env.data`; `fields` = `{key: "text"\|"html"}` |
| `post-message`   | `message` | — | Page shown in an iframe: `window.parent.postMessage(message, location.origin)` — same origin only; nothing when not framed. The receiver checks `event.origin`, `event.source` and `message.type`. First user: the page editor (`z77:content-saved`, [`content.md`](content.md) «Editing on the page») |
| `load-script`    | `src` | `init`, `scope` | Lazy-loads JS once per `src`; on load and on subsequent calls runs `_Z77.scriptInit[init](scopeEl)` — see "lazy-loaded action scripts" below |

`target` is a CSS selector string (e.g. `[data-nav-id="202"]`, `#js-nav-modal`).

Commands are executed in array order.

**The row vocabulary (FETCH-ROW-001, ADR-047 addendum 2026-10-10).** A one-row save answers
with what changed, not with `reload`. A list row carries `data-entity="<entity>:<id>"` (the
identity the windows use), a list that receives new rows `data-entity-list="<entity>"`.
`FetchResponse` turns that into one line each — `replaceRow($entity, $id, $html)`,
`removeRow($entity, $id)`, `insertRow($entity, $html, $position = 'append')` (plus
`rowTarget()` / `listTarget()` for a hand-written command); all take an optional `$origin`
and emit the commands above. What `core.js` gained: the HTML that `replace-html`, `insert-html`
and `update-html` bring in is WIRED before it enters the document (`_Z77.core.wire` +
`_bindCheckUrl` on the fragment; the window context when the target lives in a window) — a
replaced row keeps its ⋮, its switch and its forms alive, which it did not before (rows
arrived dead, the reason every controller answered `reload`). The row's HTML comes from the
same row partial the list renders, so list and answer cannot drift. `reload` is reserved for a page
that genuinely has to be rebuilt (the navigation tree → the shell's rail, a setting that
redraws the shell, a bulk operation whose result IS a new list).

## data-attribute wiring (document-wide auto-init)

`core.js` wires `data-fetch-post` / `data-fetch-get` on `DOMContentLoaded` for the initial document, and wires every piece of server HTML it inserts: popup content on `popup.show()`, a window's content on `windows.fill()`, the HTML of `replace-html` / `insert-html` / `update-html` / `update-fields` (`html`) before it enters the document, and a fetch region's new content after the swap. Every request a form or trigger sends goes through the **busy guard** (below).

| Attribute | Element | Behaviour |
|---|---|---|
| `data-fetch-get="/url"` | any clickable | GET on click; a second click while the first GET runs is ignored (busy guard) |
| `data-fetch-post="/url"` | `<form>` | POST on submit with collected form data (multi-value-aware); while it runs the form is `aria-busy="true"`, the submitter disabled, a second submit ignored (busy guard, FETCH-BUSY-001) |
| `data-fetch-toggle="/url"` | `<input type=checkbox>` | POST `{value: checked}` on change; disabled while its request runs; reverts the checkbox if the response status is not `success` |
| `data-window-open="/url"` | any clickable (keep an `href` on a link) | opens a controller-led WINDOW (ADR-047, WIN-001): GET in fetch mode with `_origin`, the answer's root declares `data-window` (mask) + `data-window-entity` (`<type>:<id>`) + `data-window-title`; mask + entity open once; an editable field in two open masks of one entity is refused with a message |
| `data-menu-open="/url"` | the ⋮ `<button type="button">` of a list row (with `aria-haspopup="menu" aria-expanded="false"`) | opens the row's actions as a CONTEXT MENU at the ⋮ (MENU-001, owner 2026-10-22): GET in fetch mode, the answer's fragment (`.be-menu`, `role="menu"`) is wired like popup content and shown in ONE fixed box `[data-z77-menu]` below the ⋮; Esc, a click outside, Tab or a click on an item closes it; the same ⋮ again closes it, another ⋮ replaces it. Delegated on `document` — a replaced row needs no wiring. Without the script it does nothing (see «context menu») |
| `data-window-width="<length>"` | a window content's root | the width the CONTROLLER wants (rem/ch/px/%/vw; default 46rem, a phone ignores it) — a form row must not scroll sideways (ADR-048 part 5) |
| `<template data-help data-help-title="…">` | end of `main` — written by `HtmlView` from `HelpService`, never by hand | the help the controller attached (ADR-048, HELP-001); a window carrying one gets a «Hilfe» button in its title bar (the word, not an «i» — owner 2026-10-10) |
| `[data-help-open]` | «? Hilfe» in the top bar (`Z77\Shared` partial `partials/helpTrigger` — backend top bar, member head `partials/shell/userMenu` since 2026-10-09; also `[data-help-trigger]`, rendered `hidden`, core.js reveals it — no JS, no button); the «Hilfe» button in a window head (core.js) | opens the HELP WINDOW with the nearest help: not modal, docked right, movable, resizable, full screen, × — one at a time, stays until closed or the page changes. Opens at the `data-help-field` section of the form control focused last (same page / same window), else at the top |
| `data-help-field="<key>"` | a section INSIDE a help template | the help about one field; `<key>` = the control's `name` without `[…]` (`debit[]` → `debit`). Opening scrolls to it and marks it `is-help-hit` for 1.6 s (CSS fade, none under `prefers-reduced-motion`) |
| `data-help-key="<key>"` | a form control | its section key when that is not its `name` |
| `form[data-upload="drop\|cell\|field"]` | the upload component — written by `partials/upload` out of an `UploadPolicy`, never by hand | `upload.js` takes the submit: a click opens the file dialog, a drop queues the files, each file gets its own request, its own progress bar and its own outcome. `drop` = the zone in the work area, `cell` = one button for the action cell (the whole work area becomes the drop target, or `[data-upload-area]` where one is marked), `field` = one file inside a form. Without the script the form posts normally (UPLOAD-001) |
| `data-upload-max` / `-accept` / `-multiple` / `-conflict` | on that form | the policy's values — the client gate reads them, `UploadPolicy::check()` decides. `-conflict` (`ask\|overwrite\|skip\|error`) is the ENDPOINT's rule per endpoint (owner 2026-10-09): only `ask` puts the question on the row, and only that row waits |
| `[data-upload-area]` | the work area of a page whose action cell carries a `cell` upload | the drop target for it; without the marker the shell's content column is taken |
| F1 | in a form control | opens the help of its page / window at its section; `preventDefault` only when there is help |
| `data-window-link` | `<a>` inside a window | loads its href into THAT window (read view ↔ edit form), the origin kept |
| `data-window-close` / `data-popup-close` | any clickable inside a window | closes the window and the windows opened from it (× and Esc too) |
| `data-window-confirm-close="…"` | a window's content | the controller wants a question before closing |
| `<form method="post">` | inside a window | sent by fetch as FormData (`$_POST` on the server) with the submitter (its `formaction` / `formmethod` honoured), busy guard as for `data-fetch-post`; HTML answer with `data-window` → the window's new content, any other HTML → popup, an envelope → its commands with the window as context. A form or submitter with `target` / `formtarget` other than `_self` (a PDF into a new tab) is left to the browser |
| `data-fetch-region="<name>"` | any container | a part of the page that reloads ALONE (FETCH-REGION-001): its `a[data-fetch-region-link]` and GET `form[data-fetch-region-form]` fetch their own URL, the region with the same name is taken from the answer's `main` (in fetch mode a list action that adds nothing gets its page's `main`, LISTING-FETCH-001) and swapped in, the address bar follows (`history.replaceState`) — NOT for a region inside a window (the customer picker, 2026-10-15): there the address stays the page's, and an answer without the region or a failed load shows an error message instead of navigating away from the half-filled form behind —, focus returns to the same id inside the new content. The region's own links and forms are delegated on `document`; the swapped content's `data-fetch-*` controls, copy buttons and blur checks are wired after the swap (inside a window with that window as context). Loads are numbered per region name: an older answer arriving after a newer one is dropped (FETCH-REGION-002). Without the script the links and forms are plain page loads |
| `data-fetch-post=""` (empty) | `<form>` | POST on submit; URL falls back to source URL of last GET that delivered the popup HTML |
| `data-popup-close` | any clickable | closes the popup (the only way to close — see POPUP-CLOSE-001) |
| `data-z77-popup` | `<dialog>` | marks the popup root (`core.js` finds it for show/close and binds the fullscreen toggle; a backdrop click does NOT close — POPUP-CLOSE-001) |
| `data-z77-popup-body` | any element inside the popup | injection target for popup HTML |
| `data-z77-field-wrapper` | any element wrapping a labelled input | anchor for `mark()`/`clear()` field-validation |
| `data-z77-field-error` | `<small>` (or similar) inside a wrapper | error-message slot (auto-created if absent) |
| `data-check-url="/url"` | `<form>` | blur-validates each input via POST to this URL |
| `data-copy="<selector>"` | any clickable | copies the named element's text to the clipboard (`_Z77.core.clipboard`); selects it first, reports via flash |
| `data-select-on-focus` | a readonly `<input>` / `<textarea>` | selects its whole text on focus or click (a link to copy by hand) — replaces `onclick="this.select()"` (`_Z77.core.triggers`, delegated) |
| `data-range-presets` (+ `data-range-from` / `data-range-to` = element ids) | a `<select>` whose options carry `data-from` / `data-to` (`Y-m-d`) | picking an option fills the named date fields; editing such a date selects the option that agrees with the dates, else the option without dates («Eigene Daten») — the financial reports' range select, owner 2026-10-22, financial.md FIN-UI-013 (`_Z77.core.triggers`, delegated). Progressive: the server resolves the choice itself, so without the script the form only reads true after the submit |
| `data-print` | a `<button>`, rendered `hidden` | prints the page; core.js reveals it, so a page without the script shows no dead button — replaces `onclick="window.print()"` |
| `data-fill-url="<url>"` (+ `data-fill-with="a b"`) | an `<input>` / `<select>` inside a `[data-fill-scope]` | on `change` GETs `<url>?value=<its value>` plus the form fields named in `data-fill-with`; the envelope's `data.fill` `{key: value}` sets `.value` of each `[data-fill-key="key"]` in the scope, `data.lock` `{key: bool}` locks a field (select `disabled`, input `readOnly`) or frees it; an error status fills nothing, flashes go the usual way (`_Z77.core.fill`, delegated, 2026-10-15). The server decides every value; no markup is inserted (Rule 7). First user: the order line's article (`order.md`). Without the script the fields stay as typed and the save decides |
| `data-submit-navigate="<url>"` | `<form target="_blank">` | after the NATIVE submit into the new tab, THIS tab goes to `<url>` (same origin only, 400 ms later, not when another handler prevented the submit) — the page that sent a form whose answer opens elsewhere does not stay stale (`_Z77.core.submitNavigate`, SUBMIT-NAV-001). Without the script the page simply stays |

**Copy to clipboard** — `data-copy` names the element that HOLDS the text; the trigger is any clickable:

```html
<textarea id="snippet-a1b2" readonly>…</textarea>
<button type="button" data-copy="#snippet-a1b2">Kopieren</button>
```

The source may be an `<input>`/`<textarea>` (its `value`) or any plain element (its `textContent`). `_Z77.core.clipboard.copy(el)` selects the source first — that shows the user what was copied AND gives the legacy path a selection: `navigator.clipboard` exists only in a secure context, so over plain http (a dev host) `document.execCommand('copy')` is the only way. Success and failure are reported through the flash channel (`js.copied` / `js.copyFailed`), so no module needs its own feedback. Inside a popup the selector is resolved within the popup body first, then document-wide.

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
- Single checkbox is a boolean — under its bracket key too: a single `name="roles[debtor]"` → `data.roles.debtor = true|false` (fixed 2026-10-12: it stayed a flat `roles[debtor]` key, so the contact's «Debitor» switch on «Kontakt anlegen» never reached the server)
- Radio: selected `value`
- Single-level bracket notation is nested: `name="value[de]"` → `data.value.de` (so a server reading an associative array, e.g. the translation editor's per-language fields, receives `value` as a map, not a flat `value[de]` key). One level only — no `name[]` arrays, no `name[a][b]`.
- Disabled inputs are skipped (use this to make a field read-only without changing entity state)
- Button elements are skipped

Confirm-delete partial example (explicit URL, target differs from source):

```php
<form data-fetch-post="/backend/content/navigation/remove">
    <input type="hidden" name="id"          value="202">
    <input type="hidden" name="entity_csrf" value="...">
    <div class="be-modal__header"><h2 class="be-modal__title">Eintrag löschen</h2></div>
    <div class="be-modal__body"><p>«…» wirklich löschen?</p></div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Löschen', 'kind' => 'danger', 'end' => true], 'Z77\\Shared') ?>
</form>
```

The action row is the shared partial (ADR-049) — never a hand-written `.be-modal__footer` (retired, its CSS first aid removed 2026-10-13).

## context menu (row ⋮)

A row's ⋮ is a context menu, not a dialog (owner 2026-10-22: «es ist eigentlich ein Context-Menu … direkt am betroffenen Element neben dem Öffnungszeichen … Esc oder ein Klick ausserhalb schliesst es. Kein ×, kein Schliessen, kein Header, auch kein Fullscreen.»). `core.js` «context menu» (`_Z77.core.menu`) carries it; the controller's `actions` action stays as it was and decides what the menu offers.

| Step | Detail |
|---|---|
| Trigger | `[data-menu-open="<url>"]` on the ⋮, delegated on `document`; `aria-expanded` follows the menu |
| Request | GET `<url>` with `X-Requested-With` (fetch mode), through the busy guard; HTML with a success status → the menu; an error status or an envelope (`fetchError()`, session expired) → the envelope dispatch / one error message, no menu |
| Answer | ONLY the items: `<div class="be-menu" role="menu" aria-label="Aktionen — «…»">` with `.be-menu__item` `<button>` / `<a>` (`role="menuitem"`), a `<form class="be-menu__form" role="none" data-fetch-post>` around a submit item, `.be-menu__note` for a line that is no action. No `be-modal__header`, no `partials/modalActions`, no `be-modal__body` |
| Wiring | `wire(menu, url, ctx)` like popup content — a `data-fetch-get` item opens its dialog through the popup channel, a `data-fetch-post` form posts; from a ⋮ inside a window with that window as context |
| Place | `position: fixed` from the ⋮'s `getBoundingClientRect()`: «eher unten rechts» (owner 2026-10-22): below it, opening to the right (left edges aligned); above it when there is no room below; to the left (right edges aligned) when it would leave the viewport on the right; clamped with a 4px margin. Appended to the ⋮'s `<dialog>` when it stands in one, else to `<body>` (`.z77-menu`, `z-index: var(--z-overlay)` — above windows and help) |
| Scroll / resize | a fixed box does not follow its row, so a scroll OUTSIDE the menu (any scroll container, capture) and a resize close it; the menu's own scroll (`max-height`) keeps it |
| Close | Esc (capture, prevented — the window or dialog under it keeps open), a click outside, Tab, the same ⋮ again, another ⋮; a click on an item closes it AFTER the item's own behaviour ran (`setTimeout 0`: its listener, the native submit, the link) |
| Switch row | a real checkbox in the menu (the Drive's «Aktiv», `.be-menu__switch` + `data-fetch-toggle`) is in the ↑/↓ round and toggles server-side as before — it does NOT close the menu (only a click on an `a[href]` / `button` does) |
| Keys | the first item takes the focus; ↑/↓ wrap, Home/End; Enter/Space activate (Space on a link too); the focus returns to the ⋮ on close — not after a click outside, a scroll or a resize |
| One at a time | opening another closes the first at once; a slow answer for a replaced or closed request is dropped |

Generic and opt-in (Rule 9): any module renders a `data-menu-open` trigger and an `actions` fragment of `.be-menu` items; the look is `components/_menu.scss` (css-backend.md). The page request of the `actions` URL (no fetch header) stays MODAL-PAGE-001: 303 to the list. Without JavaScript the ⋮ does nothing — as before (a `type="button"`); the actions are reachable through the row's window / the list's own controls.

Still dialogs (not menus, owner to decide): module-contact's `ContactController` `actions` (the addresses are listed inside), module-vat's `TaxCodeController` `actions` (the rates inside). The Drive's ⋮ (module-dms, folders and documents) is a menu too since 2026-10-22 — its `data-modal` path stays for the preview pane's buttons only (documents.md).

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
- An error status with an HTML body (a proxy's 502, a fatal) never becomes popup or window content: `fetch.unusable(r)` turns it into ONE error message with the status (`js.requestFailed`); a JSON body with an error status is dispatched as an envelope as always.
- Defaults: envelope handlers (`flashes`, `messages`, `redirect`, `commands`, `html`, `fields` — marks inside the window the request came from, else the document), commands (`replace-html`, `remove-element`, `insert-html`, `update-text`, `update-html`, `update-fields`, `scroll-to`, `reload`, `post-message`, `set-class`, `load-script`, `close-modal`, `close-window`, `open-window`, `refresh-region`).
- Busy guard: `_Z77.core.busy.guard(owner, control, send)` — what `wire()` and the window forms use; a module script that sends from a form of its own uses it too.
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
- When a controller action returns `HtmlResponse` in fetch mode (e.g. confirm-delete partial) → MUST call `$this->layoutManager->addPartials(action, controller, namespace)` after `$this->html()` — `initialize()` skips auto-template-loading in fetch mode; an action that leaves `main` EMPTY gets its page's `main` after the action (the fragment pin = the list, else `{action}.tpl.php` — `LayoutManager::completeFetchMain()`, LISTING-FETCH-001), so a dialog that forgets its partial answers the list, not nothing
- When a list is a fetch region → its `listAction()` MUST NOT add a partial for the fetch case; the kernel fills the empty `main` with the list (LISTING-FETCH-001). Only an action whose page holds MORE than the list (the journal: capture form + list) puts the list alone into `main` itself
- When using entity-scoped CSRF → MUST validate via `CsrfService::validateEntityToken()`; MUST NOT skip validation on the remove endpoint
- When controller needs to update a specific DOM element after save → MUST use `addCommand('replace-html', ...)` with a rendered partial; MUST NOT instruct JS to `location.reload()` unless structure change makes targeted update impractical
- When controller-specific JS calls the server → MUST use `_Z77.core.fetch.post()` / `.get()`; MUST NOT define a local `fetch` wrapper
- When a form answers into a new tab (`target="_blank"` — a PDF, a print) and the page it was sent from would be stale afterwards → MUST add `data-submit-navigate="<url>"` on the form and keep a plain link onwards for the case without script; MUST NOT reload or redirect the origin from a per-module script, and MUST NOT let the navigation decide anything — the server acts on the POST alone (Rule 7, SUBMIT-NAV-001)
- When a page offers a copy-to-clipboard control → MUST use `data-copy="<selector>"` on the trigger; MUST NOT wire `navigator.clipboard` in a per-module script or an inline `<script>` (COPY-001)
- When a form or trigger sends a request → MUST go through the busy guard (`data-fetch-post`, `data-fetch-get`, window forms do it; a module script calls `_Z77.core.busy.guard()`); MUST NOT rely on it on the server side — a request can be repeated by hand, the server keeps its own checks (Rule 7, FETCH-BUSY-001)
- When a template needs a small behaviour on click or focus (print, select a field's text) → MUST use a delegated data trigger (`data-print`, `data-select-on-focus`, `data-copy`); MUST NOT write an inline handler (`onclick="…"`) — a CSP limited to the own origin blocks it (Rule 7)
- When an action renders a dialog (edit, add, confirm*, an actions hub) → MUST add its partial to `main` after `html()` and nothing else; MUST NOT build a page template for it or catch the page case per controller — the page request is answered once, generically, with 303 to the controller's `list` (MODAL-PAGE-001)
- When a list row offers its actions behind a ⋮ → MUST render the trigger as `<button type="button" … aria-haspopup="menu" aria-expanded="false" data-menu-open="<…/actions?…>">` and let the `actions` action render ONLY the `.be-menu` items (`role="menu"` / `menuitem`); MUST NOT open a dialog of buttons for it (no `be-modal__header`, no `partials/modalActions`, no «Schliessen») and MUST NOT wire the menu per module (MENU-001, owner 2026-10-22). A hub that holds MORE than actions (contact addresses, VAT rates) is a dialog until the owner moves that content
- When an inline status field (e.g. `active`) is toggled directly from a list row → MUST use `data-fetch-toggle="/url"` on the checkbox (server-authoritative: POST persists and returns a `set-class` command for the row; the checkbox reverts on a non-success response); the endpoint is `#[Fetch, HttpMethod('POST')]`, relies on the global CSRF header, and MUST NOT require an entity-scoped token (non-destructive). MUST NOT wire the change handler in a per-controller JS file.
- When closing a popup → MUST be a deliberate discard: a `[data-popup-close]` control («Schliessen» right in the action row, or the × top right of the dialog — ADR-049 addendum 2026-10-22) or the ESC key (both accepted, POPUP-ESC-001; a dialog closes without a question, only a window with entered data asks); a backdrop click does NOT close it (POPUP-CLOSE-001) and MUST NOT be relied on as a discard path (it discarded edits on a stray click or a text-selection drag onto the backdrop)

## see also

- [`messages.md`](messages.md) — `MessageService` is the single source of truth for both feedback channels; `flashes[]` and `messages[]` envelope slots are drained by `$this->fetch()`
- [`login.md`](login.md) — `AccessGuard` is the same pipeline service that enforces auth + CSRF
- [`view-layer.md`](view-layer.md) — `html-fetch-skeleton` for Fetch-mode rendering

## known issues

- **MENU-001** — built 2026-10-22 (owner decision the same day). The row ⋮ opened the shared dialog «Aktionen — «…»» with header, action row («Schliessen»), × and full screen around 2–7 buttons; it is now a context menu at the ⋮ (`data-menu-open`, `_Z77.core.menu`, «context menu» above). Converted: the ten pure action hubs — module-backend Content (Content, MetaData, Navigation, NavigationAlias, Translation ui + slug), Service (Backup, EmailSettings, Job), System (BackendUser), module-financial FiscalYear — and the Drive's ⋮ (module-dms, folder tree + document list: items, the «Aktiv» switch row, the delivery hints as notes). Placement «eher unten rechts» (owner 2026-10-22, second word of the day). Controllers unchanged. Don't assume a scrolled list moves the menu along — it closes on a scroll outside it (fixed box, by choice: simpler than following every scroll container, and a menu that slides away from its row is worse). Don't assume the windows' «click outside closes a chooser» (`data-window-dismiss`) knows the menu: a ⋮ menu inside a chooser window would close that window on an item click — no chooser has a ⋮ today. Verified: `tests/core-js.mjs` M1–M15 against `core.js` AND the hand-patched `core.min.js`, `tests/form-actions.php` (the hub / trigger lint), `tests/js-min-markers.php` (`data-menu-open`, `data-z77-menu`). **Not verified in a browser.**
- **MODAL-PAGE-001** — found 2026-10-12, **resolved 2026-10-13** (review H1, owner go). Every dialog action (edit, add, confirm*, the ⋮ hubs) opened as a PAGE (no fetch header — a direct URL, a reload, a bookmark) ended in HTTP 500, on every screen: fragment layouts pin `body.main` to the list template for EVERY action, so the dialog's partial was appended to a list rendered without its variables (`count(null)`); a non-fragment controller has no `{action}Action.tpl.php` (`Partial 'editAction' not found`). **Fix, once, in the kernel:** `LayoutManager::hasPage()` answers after the action whether `main` holds a page of THIS action — false when no config set `main` and the action template is missing, or when a pin is still first in `main` and the action only appended to it (an action that called `removeSection('main')` built its own page and stays one; the list, and the mandator form that keeps its pin alone, stay pages). `AbstractBaseController::run()` asks it for every `HtmlResponse` and answers **303 to the controller's `list`** (`dialogPageFallback()`: the requested path with its action segment replaced, query dropped; the session flashes `html()` took for the page are put back for the list). An alias spelling or the list itself without a page stays the error it is — no redirect loop. Chosen over «render the list with the dialog open»: that needs the list's variables in every dialog action, i.e. per-screen code. Verified: `tests/modal-page.php` (17 checks, offline) and live on 8077 — page mode → 303 `…/list`, fetch mode → 200 for `payment-terms` edit/add, `payment-target`, `dunning-level`, `tax-code`, `contact` edit/add/actions, `navigation` edit/actions/confirm-delete/add, `journal` confirm-delete, `drive` add; every backend list, the journal edit page, mandator, report, invoice, bank-import pages unchanged (200); the frontend unchanged.
- **FETCH-BUSY-001** — resolved 2026-10-13 (review H2). No double-submit guard: a double click on «Speichern» created two contacts or two journal entries. `_Z77.core.busy.guard(owner, control, send)`: per form (or trigger) a busy flag, `aria-busy="true"` and the submitter disabled while the request runs, released in `finally()`; a second submit while busy is ignored (still `preventDefault`, so no native page post either). Used by the `data-fetch-post` wire, the window forms, `data-fetch-get` and `data-fetch-toggle`. Not a server guarantee (Rule 7). Verified: `tests/core-js.mjs` B1–B7, W1–W5. **Not verified in a browser.**
- **WIN-STATUS-001** — resolved 2026-10-13 (review §2). A window took any answer as its content: an expired session (the login redirect envelope) or a 403/500 landed as raw text in the window. `windows` now accepts only HTML with a success status; anything else goes through `fetch.unusable()` (envelope → dispatched, e.g. the redirect to the login; an error page → one message «Anfrage fehlgeschlagen (HTTP n)»), and no window opens. The same rule for every fetch answer with an error status and an HTML body. The window's conflict probe is a `<template>` (nothing in it runs or loads). Verified: `tests/core-js.mjs` X1–X4.
- **FETCH-REGION-002** — resolved 2026-10-13 (review §2). Two region loads in flight (paging fast, typing a search) could finish in the wrong order and show the older page; the swapped content arrived unwired (its ⋮ and switches dead). Loads are numbered per region name, only the latest answer is applied; the new content is wired (`wire()` + `_bindCheckUrl()`, window context when inside a window); focus is found again inside the new content only. Verified: `tests/core-js.mjs` R1–R3.
- **DEBUG-FETCH-001** — resolved 2026-10-13 (review §2). The DEBUG error handler printed its HTML box into every answer — in fetch mode into the JSON, which then failed to parse («Verbindungsfehler»; the real cause of the PNG upload error). In fetch mode a notice / warning / deprecation is now logged (`error_log`) and pushed as an error MESSAGE into the envelope while the answer is still being built; an uncaught exception goes to `ExceptionHandler::handleUncaught()` (JSON with the message in DEBUG); a fatal is logged only. Page mode unchanged (`kernel/core/src/autoload/debug/php/Functions.php`).
- **Don't make a destructive switch a plain toggle.** Toggles stay non-destructive; a switch whose OFF deletes something answers OFF with a question instead of acting — the contact's «Debitor» role (`toggle-role` with OFF returns the roles section with the question, status `confirm`, core.js puts the switch back; «Debitor entfernen» POSTs `remove-role` with the entity token `contactRole.<key>`; owner decision 2026-10-13, review 2026-10-13 §2). Switching ON stays immediate.
- **Don't assume production runs what debug shows.** Production serves `core.min.js`, debug `core.js`. After every change to `core.js` the owner re-saves it in VS Code (minify on save, js-build.md); `php tests/js-min-markers.php` fails while the min lacks a marker of the source. `core.min.js` was stale from 2026-10-08 to (at least) 2026-10-13 (review K1).

- **SUBMIT-NAV-001** — added 2026-10-10 (owner decision of the day, debtor's booking print: «a printed invoice with a number IS a document»). A confirmation POSTs into a NEW TAB (`target="_blank"`) whose answer is the PDF of the documents it just booked; the confirmation tab would keep showing drafts that are booked by now. `form[target="_blank"][data-submit-navigate="<url>"]` (core.js `_Z77.core.submitNavigate`, delegated on `document`) sends the origin tab to `<url>` after the native submit — chosen over a redirect (the POST's answer must be the PDF in the new tab) and over a reload (the confirmation reloaded would show a stale selection). Non-authoritative: it moves a tab, nothing else; the server books on the POST alone and makes a repeated submit idempotent itself (`debtor.md`). Ordering: the navigation waits 400 ms so the browser has sent the POST, and the backend holds the PHP session for the whole request — the GET of `<url>` is served after the POST finished (a session-less backend would need another signal). **Not in `core.min.js` yet** — production serves the minified file and lacks the hook until the owner re-saves `core.js` in VS Code (js-build.md; `tests/js-min-markers.php` checks `data-submit-navigate`); the booking print itself works without it. Verified: the served `core.js` on 8077 carries it; the click itself was not exercised in a browser (the owner's drafts were not booked over HTTP).
- **UPLOAD-001 — owner's first live look, 2026-10-10 (Zahlungseingänge).** Three corrections, each general: (1) **No upload box in the middle of a page whose upload is everyday work.** The `cell` button is the whole surface; the page keeps only the drop AREA (`[data-upload-area]`) and the hint sentence. A `drop` zone is for a screen where receiving files IS the task. (2) **The QUEUE moves out of the cell**: on bind, a `cell` component appends its list + summary to `[data-upload-progress]` and marks that container `.z77-upload-queue` (the `--z77-up-*` tokens are defined for both roots, or the rows would land outside every variable). A 210px action cell cannot show a progress bar, and the cell must not grow while files upload. (3) **The cell label is the shell's `be-btn__label`**, so a phone hides it the way it hides every other action label — what is left in the toolbar square is the ↑, which is the whole upload on a phone. Keep the label SHORT («camt.054 Upload», not «camt.054-Dateien hierher ziehen»): it stands next to an arrow in a narrow cell.
- **UPLOAD-001 — extended 2026-10-10: the Drive gave up its own uploader.** `documents/upload.js` (396 lines), its `.min.js` and its `components/_upload.scss` are DELETED; the Drive's modal renders the component. Three things the component learned for it, and each is general: (1) **two caps** — `UploadPolicy::$maxBytesPer` (`['image/*' => 8 MB]`, patterns in the `accept` vocabulary) next to the general one, because every file is bounded by what PHP takes over the wire while an `image/*` must also fit in the memory GD decodes it into; the map travels as `data-upload-max-per` (JSON) and `maxBytesFor()` / `limitFor()` resolve it the same way on both sides. (2) **The consumer's own fields ride INSIDE the component's form** (`$fields` in the partial) — the Drive's target folder and «Original ausliefern» — because `upload.js` collects the form's fields per request, so a select standing beside the component would reach nothing. (3) **A per-file field provider** (`data-upload-extra="<name>"`, `_Z77.upload.provider(name, fn)`) for what only a module can produce: the Drive's video poster frame, now `documents/upload-poster.js` (the proven canvas grab, moved unchanged — a rejected provider is not the upload's failure, the file goes without it). The endpoint answers the house envelope, so its `duplicate` (identical bytes — a row marked «schon vorhanden», not an error) and `conflict` (the `ask` prompt) needed no new protocol, and `paneRefresh()` now rides along: **commands run once, after the last file, and only when NO file failed** — a run with a refusal keeps its modal and its rows, which is what the Drive always did and is the general rule now. Verified: `tests/upload-component.php`, 95 checks.
- **UPLOAD-001 — extended 2026-10-31: a TRANSPORT hook (DMS chunked upload, F3).** Don't assume every file of the component goes as one multipart POST. `_Z77.upload.transport(name, {accepts(file, config), max(config), send(file, ctx)})` lets a module carry a file over another wire; the form picks it with `data-upload-transport` and gets the server's numbers as `data-upload-transport-config` (JSON) — both written by `partials/upload` from `$transport` / `$transportConfig`. A file the transport `accepts` is bounded by the transport's `max` (0 = none) instead of `data-upload-max`; a `data-upload-max-per` entry still lowers it. `send` resolves the same per-file envelope (`settle()` is shared with the XHR path), so rows, the conflict question and the end-of-run commands are unchanged; cancelling aborts through `ctx.signal`. First user: the Drive's `dms-chunk` (`documents/chunk-upload.js`, [`documents.md`](documents.md) DMS-CHUNK-001) for files bigger than one chunk. `upload.min.js` was patched by hand (no terser here) — re-save `upload.js` in VS Code. Verified ad hoc against a stub DOM (source and min); not in a browser.
- **FETCH-ROW-001** — built 2026-10-10 (ADR-047 addendum 2026-10-10; owner: «wenn immer
  möglich ein Fetch-Modal, nur wo ein Reload zwingend nötig ist eine eigene Seite»). **A one-row
  save answers in place.** The review of the day found 12 backend controllers (33 call sites)
  answering a one-row change with `close-modal` + `reload`, which throws away scroll, tree and
  filter; the in-place answer existed in the same codebase (`NavigationController` edit/delete,
  the DMS panes). Now `FetchResponse` carries the row helpers (table above: `replaceRow`,
  `removeRow`, `insertRow`, `data-entity` / `data-entity-list`), and the controllers of every
  module switched the same day where one row changes — each topic's entry of 2026-10-10 names
  which answers went in place and which `reload` stayed with its reason. The flash of an
  in-place answer travels in the envelope (`addFlash`), not through `pushFlashAfterRedirect`,
  which only a page load shows. Verified: `tests/form-actions.php` (helpers → commands) and the
  module harnesses. **Not verified live.**
- **UPLOAD-001** — built 2026-10-09 (owner 2026-10-08/09; design on the developer canvas board «Upload — Entwurf»). **One upload for the whole installation.** `Z77\Shared\Upload\UploadPolicy` is the single source: it writes the client's attributes AND performs the server's check (`check()` — size, `accept` by extension or by the SNIFFED type, never the client's claimed one, plus PHP's transport cap; the client's own gate only spares a round trip). `partials/upload` writes a real multipart form around a real `<input type=file>` inside a `<label>`; `upload.js` takes the submit and adds drag & drop, THREE parallel XHR uploads (one request per file — progress needs XHR, and three leaves the page its connections while finishing ten files in a third of the time), a row per file with its own bar, the outcome AT the file name (✓ / !) and ONE summary flash per run. Commands from the per-file answers are deduplicated and run ONCE, after the last file (ten files, one reload). A conflict is the endpoint's decision (`data-upload-conflict`), and in `ask` mode only the asking row waits — the rest keep uploading. Geometry: `components/_upload.scss` (host-neutral); the backend's surface `components/_upload-host.scss` binds `--be-*-page`, NOT the island accent (the ACTION-CELL-ISLAND-001 trap). ⚠️ Both are compiled into the BACKEND bundle only (`base.css`) — deliberately, every upload so far lives in the backend shell, the Drive's modal included (verified on z77.ch 2026-10-10: `dms.css` carries no upload rule any more). A fragment embedded outside that shell (a member or frontend page) needs its own bundle to `@use` the two partials first; the component itself is host-neutral and does not care. First user: Zahlungseingänge (camt.054) in both shapes — the action cell opens the dialog, the list carries the zone and is the drop area, the hand-written file field is gone, and `uploadAction` answers the envelope in fetch mode and the old flash + redirect on a page. Rule 7 justification: a plain file field cannot report progress, accept a drop, or say per file what became of it; without the script the form still works, only without those three. Verified: `tests/upload-component.php`, 63 checks (the policy incl. refusals and `withShape`, the attribute names on BOTH sides, the no-JS fallback, the styled states, the queue's rules, the backend binding, the first user's wiring). **Not verified live** — the drag states, the bars and the parallel queue need a browser.
- **HELP-003** — built 2026-10-20 (owner decision, ADR-048 addendum 2026-10-20). **A list's help stands in its toolbar.** `HelpService::attach(…, toolbar: true)` (`inToolbar()`; the base controller passes `helpInToolbar` beside `helpBlock`) → the backend skeleton renders `partials/helpTrigger` LAST in the toolbar slot hc2 (`margin-left: auto` in `_help-host.scss` pushes it to the right end; on a phone the tabs scroll beside its square), `partials/shell/topbar` renders it only without `helpInToolbar` — one button per page, same `[data-help-open]` trigger, no JS change. A list attaches before `html()` and only for the page (a region fetch carries none). Users: order list, invoice list, open items (Debitoren), bank import, dunning — each a short `list.help.tpl.php`. Backend shell only: the member shell ignores the flag (its trigger stays in the head). Verified: `tests/module-financial.php` HP11–HP13 (flag, skeleton rendered, top bar and CSS), `tests/module-order.php` H1–H2, `tests/module-debtor.php` P3C24b, R13b, T11b, U7b. **Not verified live** (position at the toolbar's end, phone square).
- **HELP-002** — built 2026-10-08 (ADR-048 addendum, owner). **Context help from the top bar.** The backend trigger «? Hilfe» (`partials/helpTrigger`) stands in the top bar before the bell, only with `helpBlock`, `hidden` until core.js reveals it; the crumb «i» is gone (the crumb line keeps one height). Help templates carry `data-help-field` sections; `_Z77.core.help` remembers the last focused control (`focusin`, not inside `[data-z77-help]`), key = `data-help-key` or `name` without `[…]`; a click on any `[data-help-open]` opens at the matching section of the same scope (page / that window), marks it `is-help-hit`; F1 in a control does the same. Section lookup compares `getAttribute` values — no selector built from data. First user: the journal (`financial.md`). Verified: `tests/module-financial.php` HP4b, HP6b/c, HP7/7b, HP9, HP10 (static: markup, scss, core.js contract). **Not verified live** (focus, F1, scroll and fade need a browser). **Member too** (owner 2026-10-09): «? Hilfe» moved from the member crumb line into the member head (`partials/shell/userMenu`, before the appearance switch; the skeleton passes `showHelp` = `!empty($helpBlock)`, work shape only — the plain shape never had page help), label hidden below 60rem; the member crumb line keeps one height (`--crumb-h` on track and cell, the cell clips, the path does not wrap). Field sections and F1 need nothing member-specific — `_Z77.core.help` is shell-neutral, `_help.scss` is in `member.css`. Verified: HP7, HP7c (static).
- **HELP-001** — built 2026-09-29 (ADR-048, approved the same day). **Help as a framework service**: any controller attaches help to its answer — `$this->help->attach('<tpl path>.help', <ns>, $vars, '<title>')` (`Z77\Core\Services\HelpService`, DI `HelpService`, on `AbstractBaseController`). The text is a `*.help.tpl.php` template (CE-overridable), rendered with live values; the base controller hands it to the view as `helpBlock`, `HtmlView` appends it to `main` as `<template data-help>` — page and fetch mode, so a window carries its own help. The «i»: the backend and member skeletons render `partials/helpOpen` in the crumb line when `helpBlock` is set; core.js adds it to a window's title bar. The help window (`_Z77.core.help`, geometry `kernel/shared/res/scss/components/_help.scss`) is not modal and never `inert`. First user: the journal forms (`financial.md` FIN-JOURNAL-CAPTURE-001) — the forms lost every explanation. Verified: `tests/module-financial.php` HP1–HP8, and in Edge headless against a static test page (the crumb «i» and a window «i» open the docked help, a real pointer drag floats it and clamps it to the viewport, full screen, Esc closes the window but not the help). **Not verified live.**

- **WIN-001** — built 2026-09-29 (ADR-047, approved the same day). **Controller-led windows**: a click opens a record in a window, a save answers with instructions, nothing else reloads. `core.js` → `_Z77.core.windows` (contract in the table above); geometry in `kernel/shared/res/scss/components/_windows.scss` (host-neutral), the backend's surface in `components/_windows-host.scss`. The fetch channel carries a CONTEXT now (`{window: id}`) from `wire()` through `get` / `post` / the new `postForm` into every envelope and command handler — existing handlers ignore the extra argument. The generic DOM commands take an optional `origin` (`page` / `region:<name>` / `window:<id>`) and resolve their target inside it; new commands `close-window`, `open-window` (`replace: true` = into the window the save came from), `refresh-region`; `close-modal` from a window closes that window. Server side: `Z77\Core\Http\WindowOrigin::of($request)` reads `_origin` (POST before GET, three shapes, else `page`). Rules the controller keeps: render the content with the window attributes on its root, write `_origin` into the form, answer a save with commands for that origin. **Placement is CSS only** — side by side on a wide screen, on top of each other below 767px; a window opened FROM a window (`data-z77-window-parent`: the customer picker, «+ Kontakt») lies OVER its parent, out of the flow (owner 2026-10-15 — beside the wide order window it wrapped and pushed the parent up); nothing in the contract depends on it. The page behind is `inert` (the message and flash channels are not). First user: the journal (`financial.md` FIN-JOURNAL-CAPTURE-001). Verified: `tests/module-financial.php` W1–W6, and in Edge headless against a static test page (two windows side by side, a second click brings the open one to the front, a mask that shares an editable field is refused with the message above the windows, phone: the newest on top). **Not verified live in a backend.**

- **FETCH-REGION-001** — added 2026-09-28 (owner request on the journal page, FIN-JOURNAL-CAPTURE-001). **A fetch region reloads one part of a page and leaves the rest alone.** First user: the journal list below its capture form — sorting, paging, searching and the scope toggles must not throw away a half-typed entry or its focus, which a page load cannot keep (the Rule 7 justification; CSS cannot fetch). Contract in `core.js` → `_Z77.core.region`, table above. The server needs nothing special: a fetch arrives in fetch mode (`Sec-Fetch-Mode`, `Request::getMode()`), the answer is the fetch skeleton = `main` only; the action decides what `main` holds (the journal puts only its list there in fetch mode); an action that adds nothing gets its page's `main` — the fragment pin, i.e. the list (LISTING-FETCH-001, listing.md, 2026-10-14). The client looks the region up BY NAME in the answer, so an answer without it (an error page, a redirect to the login) makes the browser navigate to the URL instead of swapping in something wrong. Progressive: the markup is the same with and without the script. `aria-busy` marks the region while it loads. **Not verified live.**

- **FIELD-001** — resolved 2026-05-16, restructured 2026-05-17, consolidated 2026-05-21. Blur-based field validation. Originally backend-only inside `backendForm.js`; consolidated into shared `core.js` (see CORE-CONSOLIDATION-001) with data-attribute / `aria-invalid` markup. Opt-in via `data-check-url` on form. Reference implementation: `NavigationController::checkFieldAction` + `edit.tpl.php`. POST-only path: `tagEdit.tpl.php` (no `data-check-url`).
- **CORE-CONSOLIDATION-001** — resolved 2026-05-21. `backendForm.js` removed entirely. Its responsibilities (popup show/close, field mark/clear, check-url blur binding, html/fields envelope handlers, close-modal/update-fields commands) moved into shared `core.js`. All DOM coupling that used to live in CSS classes (`.be-form__field--invalid`) and IDs (`#z77-popup`) is now via data-attributes (`[data-z77-popup]`, `[data-z77-popup-body]`, `[data-z77-field-wrapper]`, `[data-z77-field-error]`) and native `aria-invalid`. Backend module renders the data-attributes in its skeleton + form templates; CSS targets `[aria-invalid="true"]` for the invalid look. Frontend or any other module gets the full fetch + popup + form-validation machinery for free as soon as it provides matching markup. `layoutConfig.inc.php` no longer references `backendForm`.

- **FETCH-CHECK-001** — resolved 2026-05-30. `_bindCheckUrl` fired the field-check on blur in two unwanted cases: (a) clicking Abbrechen/Close blurred the focused field → premature error right before the form was discarded; (b) leaving an untouched required field (e.g. the autofocused Name) → instant "Pflichtfeld". Fix in `core.js`: a cancel guard (`mousedown` on `[data-popup-close]` + `relatedTarget`) and dirty-tracking (blur-check runs only after an `input` event on the field). Submit still runs full server-side validation.

- **POPUP-CLOSE-001** — resolved 2026-06-05. The `<dialog>` backdrop-click handler closed the popup whenever the `click` target was the root element. Because `click` fires on the common ancestor of mousedown/mouseup, starting a text selection inside an input and releasing on the backdrop landed on the root → the popup closed and the edit was lost. Fix in `core.js` `popup.bindBackdrop()`: close-on-backdrop-click was removed entirely — an edit is discarded only via an explicit `[data-popup-close]` control. The fullscreen toggle (`[data-popup-fullscreen]`) stays bound on the root.

- **POPUP-ESC-001** — resolved 2026-06-07 (by decision, no code change). The native `<dialog>` (`showModal()`) closes on the ESC key. Decided to keep it: ESC is a deliberate keyboard action, not the accidental-discard path that POPUP-CLOSE-001 removed for backdrop clicks. Both `[data-popup-close]` (Abbrechen / ×) and ESC are accepted discard paths; only the backdrop click is excluded.

- **FORM-BRACKET-001** — resolved 2026-06-10. `_z77CollectFormData` flattened every input name verbatim, so the translation editor's per-language fields (`name="value[de]"`) arrived as literal keys `value[de]`/`value[fr]` instead of a nested `value` map. `TranslationController::readValues()` reads `$body['value']` as an array → got `null` → empty values → `saveUiEntry()` wrote the default language as `''` and dropped every non-default key. Symptom: editing any UI text (or slug), saving, all fields blank. Fix in `core.js`: a `put(name,value)` helper parses single-level bracket notation (`value[de]` → `data.value.de`) for the radio + scalar branches; checkbox-array/boolean behaviour unchanged. Applied to `core.js` + hand-maintained `core.min.js` (prod loads `.min.js`) in `packages/kernel/shared` and the installed `skeleton/public` copies. The translation editor was the first/only consumer of bracket-name inputs.

- **COPY-001** — added 2026-08-08. Copy to clipboard is a shared channel (`_Z77.core.clipboard`, markup `data-copy="<selector>"`), not a per-module script: its first consumer (AXO3's widget-snippet lists, backend and member area) would otherwise have carried an inline `<script>` per page, and the next consumer another one. Selection-first with an `execCommand('copy')` fallback, because `navigator.clipboard` is undefined outside a secure context — over plain http (a dev host) the legacy path is the only one. Feedback rides the flash channel (`js.copied` / `js.copyFailed`), so a module needs markup and nothing else. Applied to `core.js` + hand-maintained `core.min.js` in `packages/kernel/shared` and the installed `skeleton/public` copies.

## pending

- **Look at the row ⋮ menus live** (MENU-001, 8079, Ctrl+F5): Inhalte › Navigation / Inhalte / Metadaten / Aliase / Übersetzungen (ui + slug), Service › Backups / Formular-Mails / Jobs («Jetzt starten» from the menu), System › Backend-Benutzer, Finanzen › Geschäftsjahre (the muted «keine Aktion» line), Dokumente (a folder's and a document's ⋮: «Aktiv» toggles and the menu stays, the delivery note, upload / move / delete open their dialogs). Position: below and to the right of the ⋮, above it near the bottom, to the left near the right edge; Esc, a click outside, ↑/↓, the dialog an item opens and its saved row, dark mode.
- **Owner: the two hubs that are still dialogs** (MENU-001): contact (addresses inside — move them into the edit mask?), VAT tax code (rates inside — into the edit mask?). Until then they keep the dialog.
- **Look at the list help live** (HELP-003, 8077 → Aufträge › Aufträge, Rechnungen, Debitoren, Zahlungseingänge, Mahnungen): «Hilfe» at the right end of the toolbar and none in the top bar; it opens the docked help window; on a phone the square stays beside the scrolling tabs; with an order window open, the toolbar button still opens the help of the window last focused (the same `_templateFor()` rule as the top bar) — confirm that is wanted.
- **Look at the upload live, second round** (UPLOAD-001, 8077 → Aufträge › Zahlungseingänge): the first round brought the three corrections above. Still to see: a file dropped anywhere on the work area is taken (there is no box any more), the queue appears in `[data-upload-progress]` and not in the cell, a `.jpg` is refused with its reason, and the phone square in the toolbar opens the picker.
- **The remaining consumers of the component** (UPLOAD-001): a logo/image field uses the `field` shape (not built — the shape is styled and harness-checked, it has no user yet), and every later import uses the component instead of a form of its own. The Drive is done (2026-10-10).
- **Look at the Drive's upload live** (UPLOAD-001, 8077 → Dokumente): drag several files into the modal, a `.jpg` over the image cap (the row must name 8 MB, not the transport cap), the same name twice (the «überschreiben?» prompt must appear on that row while the others go on), a video (the poster frame must arrive — the preview shows it), and a run with one refusal (the modal must STAY open with its rows).
- **`.min.js` for `core.js`, `upload.js` (and dms `upload-poster.js`)** — the owner re-saves each in VS Code (minify on save, js-build.md); until then `core.min.js` lacks the 2026-10-08…13 changes and `upload.js` runs unminified in production with a warning per request. `php tests/js-min-markers.php` must be green afterwards.
- **Look at the 2026-10-13 core.js changes in a browser** — double click on «Speichern» (one record, button disabled meanwhile), a window opened after the session expired (→ login, no text in the window), fast paging in the journal region, the stats page's link field and «Bericht drucken». The behaviour harness `tests/core-js.mjs` runs against a stub DOM only.

