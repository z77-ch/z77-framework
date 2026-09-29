# ADR-048 — Help as a framework service: an «i» where help exists, the text in its own window

**Status:** `[APPROVED]` — the owner's specification, approved 2026-09-29 («Freigeben, bauen»)
**Date:** 2026-09-29
**Builds on:** [ADR-047](adr-047-controller-led-windows.md) (controller-led windows),
`fetch.md` (fetch mode, the fetch skeleton renders only `main`)

---

## Context

Forms explain themselves in the form: the journal's edit form carries a paragraph about the
tax tolerance, the computed VAT line and a note on the number. It pushes the fields apart and
makes the window scroll sideways. The owner, 2026-09-29: «der ganze Bla-Bla-Text kommt raus,
die Validierung soll eine falsche Eingabe abfangen». Explanations still have a place — for the
one who needs them, on request.

There is no help concept in the framework (only one inline hint, `JobController`
`scheduleHelp`).

## Decision

### 1. A service, for every controller

`HelpService` is a kernel service (DI `HelpService`, on `AbstractBaseController` as
`$this->help` beside `$this->messageService`). Any controller of any module — backend,
member, frontend — attaches help to the response it is building:

```php
$this->help->attach('Backend/JournalController/oneLine.help', self::NS, ['form' => $form], 'Einzelbuchung');
```

- The text is a **template** (`*.help.tpl.php`) in the normal template tree, found by the
  FileFinder — a project overrides or adds it under `override/` (CE, rule 1).
- It is rendered with the controller's variables, so it may show live values (the journal's
  computed VAT line moves into its help).
- A form **may** have help, it need not. No attach → no «i», nothing rendered.
- The controller decides which help belongs to which answer (one-line or compound form,
  capture or edit) — no naming convention guesses it.

### 2. Delivery: inside the answer, no extra request

The rendered help travels in the same HTML as the content, as
`<template data-help data-help-title="…">…</template>` at the end of `main` — page and fetch
mode alike. The base controller hands it to the view as `helpBlock`; `HtmlView` appends it to
the `main` section (not a partial: an action that rebuilds `main` after `html()`, like the
journal, would drop it) (the fetch skeleton renders `main`, so a window gets it too). No help route, no
second request, and the text always matches the state of the form it belongs to.

### 3. The «i»

Where help exists, an «i» appears:

- in a **window**: in its title bar (core.js adds it when the content carries a help template);
- on a **page**: in the crumb line (the skeleton renders it when the controller attached help —
  backend `hc3` / default crumb, member crumb).

### 4. The help window

A click on the «i» opens the **help window**:

- **not modal** — the page and the windows stay usable; it is to be left open while working;
- by default **docked to the right**, full height;
- **movable** (drag the title bar), **resizable** (CSS `resize`), **full screen** (a button),
  **closable** (×);
- ONE help window: a second «i» replaces its content;
- it stays until it is closed, the page reloads or the page changes — closing the form's
  window does not close it.

Placement, dragging and resizing are presentation (ADR-047 part 4). The drag is JavaScript —
CSS cannot move an element by pointer (rule 7, the reason); resizing is CSS.

### 5. Window width is the controller's (ADR-047 addition)

The content root may declare `data-window-width="<length>"`; core.js sets it on the window
(`--z77-window-width`, default 46rem, a phone ignores it). The controller knows how wide its
form must be — a window never scrolls sideways because of a fixed guess in CSS.

## Reasoning

- A service, not a template helper: help is attached by the one who knows the situation (the
  controller, ADR-047 part 1), and every module needs it.
- In the answer instead of a route: live values without a second request, no URL scheme for
  help topics, no state to keep in sync.
- Its own non-modal window: help is read beside the form, not instead of it.

## Consequences

- New: `Z77\Core\Services\HelpService`, DI registration, `$this->help` on the base controller;
  `HtmlView` closes `main` with `helpBlock`; the «i» (`Z77\Shared` partial `partials/helpOpen`)
  in the backend and member crumb; core.js
  `_Z77.core.help` (open / dock / drag / full screen / close) and the «i» in the window head;
  SCSS: geometry in `kernel/shared` (`_help.scss`), the look in the hosts.
- The journal: the edit forms lose every hint (the tolerance rule, the computed VAT line, the
  number note) — they move into `oneLine.help` / `form.help`; the capture page gets the same
  help in its crumb.
- A project can write help for any vendor form under `override/` — only where a controller
  attaches it.

## Rejected Alternatives

- **Inline hints in forms** — the state before; the owner rejected it.
- **A `<details>` block that folds out in the form** — asked 2026-09-29, the owner chose its
  own window.
- **A help route fetched on click** — a second request, no live values, a topic URL scheme to
  maintain.
- **Auto-detecting `{action}.help.tpl.php`** — an action renders different forms (the journal's
  edit: one-line or compound); the controller knows which. Can be added later as a default.
