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

## Addendum 2026-10-08 — context help from the top bar (owner decision)

**Trigger in the top bar.** A page's help is opened by «? Hilfe» in the top bar's right
cluster (backend: before the bell; `Z77\Shared` partial `partials/helpTrigger`), shown only
when the controller attached help (`$helpBlock`). On a phone only the «?» (the accessible name
stays). The «i» left the backend crumb line, and the crumb line has ONE fixed height
(`--shell-crumb`) — a page with help looks like one without. A window keeps its «i» in the
title bar: while a window is open the page behind is `inert`, the top bar included.

**Sections per field.** A help template may mark sections `data-help-field="<field name>"`
(the form control's `name` without `[…]`) beside its general part. A control whose section
key is not its name declares `data-help-key="<key>"`.

**The script.** core.js remembers the last form control focused outside the help window
(`focusin`). «? Hilfe» opens the help as before and, when the help has a section for that
field, scrolls to it and marks it briefly (`is-help-hit`, a CSS fade, none under
`prefers-reduced-motion`); otherwise it opens at the top. **F1** in a form control opens the
same help at that control's section — `preventDefault` only where there is help, so F1 stays
the browser's elsewhere.

**No fetch, no new attack surface.** The help is already in the answer; the script clones the
template as before and compares attribute values (no selector built from data, no
`innerHTML` with data). Without JavaScript there is no help window — so the trigger is
rendered `hidden` and core.js reveals it (before: the crumb «i» showed but did nothing).

**Member head too (owner 2026-10-09):** the member shell renders the same trigger in its head (`partials/shell/userMenu`); its crumb line carries the position only, at one fixed height (`--crumb-h`).

## Addendum 2026-10-20 — a list's help stands in its toolbar (owner decision)

On a LIST screen the help trigger is not in the top bar but at the RIGHT end of the list's
toolbar (backend slot hc2), beside the tabs and tools it explains. The controller decides:
`$this->help->attach(…, toolbar: true)` → `HelpService::inToolbar()` → the view gets
`helpInToolbar`; the backend shell skeleton renders the same `partials/helpTrigger` last in the
toolbar slot (whatever the list's toolbar template holds, or none), and the top bar renders it
only without `helpInToolbar` — exactly one help button per page. Same trigger, same help window
(`_Z77.core.help`); windows keep «Hilfe» in their title bar. First users: Aufträge, Rechnungen,
Debitoren, Zahlungseingänge, Mahnungen (fetch.md HELP-003).

## Addendum 2026-11-15 — «Hilfe ergänzen»: the installation's own text to a help (owner decision)

An admin adds his own text to any help; it is shown in the help window and feeds the
KI-Assistent (ADR-055 addendum of the same day).

- **Data, never code.** The help templates live in the packages (vendor in a project): writing
  one would be code injection, and a deploy would overwrite it. The addition is plain text in
  `data/help/<topic id>.txt` (`Z77\Core\Services\HelpAddition`; `data/` is shared across
  releases). The key is the help's topic id (`HelpService::topicIdFor()`, the `data-help-topic`
  the block already carries) — a slug, so it never names a path; the edit action accepts only
  an id of an existing help template (template discovery). At most 20'000 characters; blank
  line = new paragraph; an empty save removes the file.
- **Shown below the help.** `HelpService::render()` appends `partials/helpAddition`
  (`<section data-help-addition>`, heading «Ergänzung» = `t('common.helpAddition')`) as the
  last part of the block — every character through `e()`, no HTML from the user.
- **Contract change of the block:** with `HelpService::editAt($url)` the block also carries
  `data-help-edit="<url>?topic=<id>"`. The backend shell sets it for an admin who can reach
  `/backend/system/help/edit` (`HelpController::isEditor()` + `canReach`); member and frontend
  never do. core.js then puts a pencil (✎, `[data-help-edit-open]`) LEFT of ⤢ in the help
  window's head — a plain `[data-window-open]`, the edit opens as a controller-led window
  (ADR-047). The action checks the rights again (ADMIN, backend realm) — the attribute is a hint.
- **Save without reload.** The answer is `close-window` + the new command `help-addition`
  `{topic, title, paragraphs}`: core.js builds the section node by node from the texts (no
  markup from data, Rule 7) and swaps `[data-help-addition]` in every help template of that
  topic and in the open help window (no paragraphs = removed). While the edit window is open the windows
  layer stands above the help window (`_help-host.scss`), which otherwise covers it.
