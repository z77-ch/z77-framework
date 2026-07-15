# messages

2026-05-19

## entry

1. `packages/kernel/core/src/Services/MessageService.php` — central buffer for both channels and both delivery modes
2. `packages/kernel/core/src/Controller/AbstractBaseController.php` — `fetch()` helper and `html()` consume the service
3. `packages/kernel/shared/res/assets/js/core.js` — shared client-side handler; renders envelope and page-bootstrapped DOM via `_Z77.core.flash` / `_Z77.core.message`; used by every module

## file map

SOURCE=/packages/kernel/core/src/Services/MessageService.php
SOURCE=/packages/kernel/core/src/Controller/AbstractBaseController.php
SOURCE=/packages/kernel/core/src/Http/Response/FetchResponse.php
SOURCE=/packages/kernel/core/src/Http/Response/RedirectResponse.php
SOURCE=/packages/kernel/core/src/Http/Response/HtmlResponse.php
SOURCE=/packages/kernel/core/src/Http/RequestMode.php
SOURCE=/packages/kernel/core/src/Services/AccessGuard.php
SOURCE=/packages/kernel/shared/res/assets/js/core.js
SOURCE=/packages/module-backend/res/view/templates/partials/flashMessages.tpl.php
SOURCE=/packages/module-backend/res/view/templates/partials/popupMessages.tpl.php
SOURCE=/packages/module-backend/src/Ui/Config/layoutConfig.inc.php
SOURCE=/packages/module-backend/res/scss/components/_flash-messages.scss
SOURCE=/packages/module-backend/res/scss/components/_popup-messages.scss
SOURCE=/packages/module-backend/res/scss/tokens/_effects.scss
SOURCE=/packages/module-frontend/res/view/templates/partials/flashMessages.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/partials/popupMessages.tpl.php
SOURCE=/packages/module-frontend/src/Ui/Config/layoutConfig.inc.php
SOURCE=/packages/module-frontend/res/scss/components/_flash-messages.scss
SOURCE=/packages/module-frontend/res/scss/components/_popup-messages.scss
SOURCE=/packages/module-frontend/res/scss/messages.scss

## mental model

Two user-feedback channels share one server-side service and one envelope shape but render in different DOM containers with different lifetimes. `MessageService` is the single source of truth for both — controllers push into it, the `fetch()` helper drains in-place buffers into the envelope, and `AbstractBaseController::html()` drains session buffers into the next page render.

- Channels are orthogonal: a controller action may emit flashes, messages, or both.
- Delivery modes are explicit: `push*` vs `push*AfterRedirect` — never auto-detect.
- Status values for both channels: `success`, `info`, `error`. No `warning` — was removed to match the new triad.
- Lifetime is decided client-side from the CSS class — server only controls type and text.
- Bot reservation: see [`## bot reservation`](#bot-reservation).

## channels

| Property | flash | message |
|---|---|---|
| Position | top right | bottom left |
| Container id | `#flash-messages` | `#messages` |
| BEM root | `.flash-msg` | `.msg-popup` |
| JS module | `_Z77.core.flash` | `_Z77.core.message` |
| Lifetime | `success`/`info` auto-dismiss after 5s, `error` stays | always stays until closed |
| Session key | `_flash` | `_message` |
| Z-index (backend token) | `--z-flash` (1100) | `--z-message` (1000) |
| Z-index (frontend) | hardcoded `1100` | hardcoded `1000` |
| Envelope slot | `flashes[]` | `messages[]` |

Class names and JS namespace are module-agnostic (single shared `core.js`). Look is local — each module's SCSS styles `.flash-msg` / `.msg-popup` independently.

## delivery channel decision

```text
will the user see a full page load next?
   no  → MessageService::pushFlash(...)              / pushMessage(...)
   yes → MessageService::pushFlashAfterRedirect(...) / pushMessageAfterRedirect(...)
```

Concrete patterns:

| Controller path | API | Reason |
|---|---|---|
| In-place fetch update (edit + `update-fields`) | `pushFlash` | Same page, short-lived confirmation |
| Persistent notification within current page | `pushMessage` | Stays until user closes |
| Add via `reload` command | `pushFlashAfterRedirect` | Page reloads, new page reads session |
| Redirect after login / logout | `pushFlashAfterRedirect` | Redirected page is a fresh full GET |
| Validation error on fetch save | `pushFlash` | Popup stays open, flash next to form |
| CSRF rejection from AccessGuard | `pushFlash` | Per-request error, error class makes it stick |

## flow — in-place (no redirect)

```text
Controller                              Browser
----------                              -------
$this->messageService->pushFlash(...)
$this->messageService->pushMessage(...)
return $this->fetch()->setStatus(...)
  fetch() drains both buffers into
  envelope { flashes:[...], messages:[...] }
                                        fetch.post/get
                                        _parseResponse → _handleEnvelope
                                          env.flashes.forEach  → flash.show
                                          env.messages.forEach → message.show
                                            each appends DOM, wires close/auto-dismiss
```

## flow — after redirect

```text
Request 1 (Controller)                      Session
----------------------                      -------
$this->messageService
   ->pushFlashAfterRedirect(...)
   ->pushMessageAfterRedirect(...)   ─►  $_SESSION['_flash'][]
                                         $_SESSION['_message'][]
return $this->redirect('/x')
                                                        ──► GET /x
Request 2 (Controller)
AbstractBaseController::html()
  if Page-Mode:
    $context['_flashes']  = consumeFlashesForPage()    // unsets session key
    $context['_messages'] = consumeMessagesForPage()
HtmlResponse renders
  flashMessages.tpl.php  → initial DOM in #flash-messages
  popupMessages.tpl.php  → initial DOM in #messages
                                                        ──► HTML loads
                                          DOMContentLoaded
                                            flash.wireExisting()
                                            message.wireExisting()
```

## api

```php
// In-place (current FetchResponse)
$this->messageService->pushFlash('success', 'Eintrag gespeichert');
$this->messageService->pushMessage('info', 'Background job läuft');
return $this->fetch()
    ->setStatus('success')
    ->addCommand('update-fields', [...]);

// After redirect (next page render)
$this->messageService->pushFlashAfterRedirect('success', 'Eintrag angelegt');
return $this->redirect('/backend/content/navigation/list');

// AccessGuard pattern — outside controller flow, sets flashes directly
$this->messageService->pushFlash('error', 'CSRF token invalid');
return (new FetchResponse())
    ->setStatus('error')
    ->setFlashes($this->messageService->consumeFlashesForEnvelope());
```

## envelope structure

```json
{
  "status":   "success",
  "flashes":  [{"type": "success", "text": "..."}],
  "messages": [{"type": "info",    "text": "..."}],
  "fields":   { },
  "data":     { },
  "commands": [ ],
  "redirect": null,
  "html":     ""
}
```

## bot reservation

A future AI chat bot will live in the bottom-right corner — the convention for chat widgets. To avoid conflict:

- DOM: `#bot-chat` id is reserved, no other component may take it
- CSS: `.bot-chat__*` BEM root is reserved
- JS: `_Z77.core.bot` namespace is reserved
- Z-index token: `--z-bot: 900` defined in `_effects.scss`, below `--z-message: 1000` so messages stack above the bot toggle
- Service: bot will get its own `BotService` — `MessageService` stays system-only, no mixing

## rules

- When the next user-visible step is a full page load (redirect, `reload` command, regular GET) → MUST use `pushFlashAfterRedirect()` / `pushMessageAfterRedirect()`; MUST NOT use the in-place variants because the response is discarded
- When the response stays on the same page (`FetchResponse` without `reload` / `redirect`) → MUST use `pushFlash()` / `pushMessage()`; MUST NOT use the AfterRedirect variants because the next request is unrelated
- When constructing a `FetchResponse` in a controller → MUST use `$this->fetch()` helper; MUST NOT instantiate `new FetchResponse()` directly (the helper drains the buffers into the envelope)
- When emitting feedback from a service outside the controller flow (e.g. `AccessGuard`) → MUST push via `MessageService`, then call `consumeFlashesForEnvelope()` / `consumeMessagesForEnvelope()` and pass the result to `setFlashes()` / `setMessages()` on the `FetchResponse`
- When calling `push*AfterRedirect()` outside an active PHP session → silently no-ops; MUST NOT rely on it from session-less endpoints
- When a controller returns `HtmlResponse` in `RequestMode::Fetch` (popup partial) → session buffers MUST NOT be consumed; `AbstractBaseController::html()` already guards this with `getMode() === RequestMode::Page`. In-place buffers (`pushFlash` / `pushMessage` during the action) ARE drained into the response's embedded envelope block by `AbstractBaseController::html()` — they reach the client through `HtmlResponse`'s `<script type="application/json" data-z77-envelope>` tag, which `core.js` parses after popup-mount.
- When extending any module's `flashMessages.tpl.php` → MUST keep `#flash-messages` id and `.flash-msg` / `.flash-msg__text` / `.flash-msg__close` classes; both server-rendered and shared `core.js`-generated DOM depend on these
- When extending any module's `popupMessages.tpl.php` → MUST keep `#messages` id and `.msg-popup` / `.msg-popup__text` / `.msg-popup__close` classes for the same reason
- When a module needs to handle a custom envelope key (e.g. backend `fields`) → MUST register a handler via `_Z77.core.fetch.registerEnvelopeHandler(key, fn)`; MUST NOT modify shared `core.js`
- When a module needs a custom command action (e.g. backend `close-modal`, `update-fields`) → MUST register via `_Z77.core.fetch.registerCommand(action, fn)`; MUST NOT modify shared `core.js`
- When adding a new response class that should carry feedback → MUST NOT add a flash trait; push via `MessageService` and have the controller drain buffers into the response via the `fetch()` helper pattern
- When adding any UI component near the corners → MUST NOT use `#bot-chat`, `.bot-chat__*`, or `_Z77.core.bot` namespaces — they are reserved for the future bot

## known issues

- **FE-MSG-001** — resolved 2026-05-17. Frontend module is feedback-capable. Initially shipped with a `fe-` prefix and a local `messages.js`; consolidated the same day to use the shared `_Z77.core.flash` / `_Z77.core.message` namespace and unprefixed `.flash-msg` / `.msg-popup` classes (see [`MSG-SHARED-001`](#)). Local module SCSS still controls the look.
- **MSG-SHARED-001** — resolved 2026-05-17, expanded 2026-05-21. Single shared client handler at `packages/kernel/shared/res/assets/js/core.js`. Originally replaced separate backend/frontend message handlers; later (2026-05-21) absorbed `backendForm.js` entirely — popup, field validation, check-url binding and the `html`/`fields`/`close-modal`/`update-fields` hooks now live in `core.js` and address DOM via data-attributes (`[data-z77-popup]`, `[data-z77-field-wrapper]`, …) and `aria-invalid`. Both modules' templates and SCSS use unprefixed `.flash-msg*` / `.msg-popup*` classes for styling. **Generic DOM-helper commands** (`set-class` — toggles a class on a selector by boolean) live in `core.js` alongside `update-fields` / `close-modal`. The "no custom Commands in core.js" rule still applies for module-specific commands — only truly generic helpers belong here.
- **LOGIN-MSG-001** (mitigated): `LoginController::resolvePostLoginRedirect()` used to fall back to `/` (frontend) when no `access.origin` was stored, which hit FE-MSG-001 for the post-login greeting. Now falls back to the current module's default landing page (`module → defaultController → defaultAction`) so the redirect stays inside the backend layout.
- **HTML-FETCH-ENVELOPE-001** — resolved 2026-05-21. HTML fetch partials (popup edit/add forms) used to drop in-place flashes/messages: `core.js` only fired the `html` envelope handler and the JSON channel was unused. `HtmlResponse` now uses the `EnvelopeFields` trait (shared with `FetchResponse`) and appends an embedded JSON script block when flashes/messages/commands are populated. `core.js` `_parseResponse` extracts and dispatches it. `AbstractBaseController::html()` auto-drains the in-place buffers into the response in Fetch-Mode. Also enables the new `load-script` command for lazy-loading modal-specific JS via `_Z77.scriptInit` registry.

## pending

_(none)_

## see also

- [`fetch.md`](fetch.md) — `FetchResponse` envelope structure; `flashes[]` and `messages[]` are the in-place delivery slots
- [`login.md`](login.md) — primary consumer of redirect feedback for post-login greeting and post-logout notice
- [`view-layer.md`](view-layer.md) — partial inclusion order in the page skeleton (`flash` and `messages`)
