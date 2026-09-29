# ADR-049 — The form's action bar: «Speichern» always within reach

**Status:** `[APPROVED]` — the owner's idea, approved 2026-09-29 («ja los»)
**Date:** 2026-09-29
**Builds on:** [ADR-033](adr-033-shell-action-placement.md) (an action stands where the thing it
acts on is shown), [ADR-047](adr-047-controller-led-windows.md) (windows)

---

## Context

A form's buttons stand at its end. On a long form a change at the top (a name) means scrolling
down to «Speichern» — the owner, 2026-09-29: «wenn der Button oben fixiert wäre, dann wäre er
immer sofort klickbar». Some forms read better with the buttons at the end (a short dialog, a
form that is filled top to bottom once). So: flexible, top as the default.

The journal already shows the page case: «Buchen» stands in the toolbar and submits the
capture form through `form="journal-capture"`.

## Decision

### 1. One shared building block: the action bar

A form's actions (Speichern, Abbrechen, switches like «Als Sammelbuchung bearbeiten …») sit in
ONE element, `.z77-form-actions` (kernel/shared geometry, the look from the host). It is
**sticky** (`position: sticky`) — it stays visible while the form scrolls. CSS only, no
JavaScript (rule 7).

### 2. Where it stands — the form decides, top is the default

- `.z77-form-actions` (default): sticky at the TOP of the form's scroll area — in a window
  directly under the title bar.
- `.z77-form-actions--end`: sticky at the BOTTOM — for forms that are filled once from top to
  bottom, and short dialogs.

The template picks; the controller may hand the choice in when one form serves two situations.

### 3. On a page the toolbar carries it

On a full page «Speichern» belongs in the toolbar (`hc2` / member toolbar) — always visible
already, ADR-033. The button submits by `form="<id>"`. The in-form bar is for windows and for
pages without a toolbar.

### 4. Errors stay visible

A save from the top with an error further down must not look like nothing happened: the
refused form shows, in the action bar, the count of errors («2 Fehler») as a `<label for>` of
the first invalid field — a click focuses it and the browser scrolls it into view, no script
(shared partial `Z77\Shared` `partials/formErrorsLink`; the form lists its invalid field ids
in document order, so every field needs an id).

### 5. Enter keeps working

The first submit button in the document is the one Enter presses. With the bar at the top,
that is «Speichern» — correct by default. A form with other submit buttons («Weitere Zeilen»)
keeps «Speichern» first in the document (the journal already does, see FIN-JOURNAL-CAPTURE-001).

## Reasoning

- Sticky instead of «fixed»: the bar stays with its form (in a window, in a scrolled page
  section) and takes no space away from other forms.
- A shared class, not per-form CSS: every module gets the same behaviour (rule 8).
- Top as the default: the owner's case (change one field at the top, save) is the frequent one
  in master data.

## Consequences

- New: `.z77-form-actions` / `--end` in kernel/shared SCSS, host looks in backend and member.
- The error count in the bar: the shared partial `partials/formErrorsLink` (count + first id).
- In a window the form drops its own heading — the window's title bar says it already.
- Existing forms move over when they are touched. First user (built 2026-09-29): the journal's
  edit forms, one-line and compound (`financial.md` FIN-JOURNAL-CAPTURE-001). Other forms move
  over as needed (owner 2026-09-29).

## Rejected Alternatives

- **Buttons only at the end** — the state before; the owner's objection.
- **Buttons at the top AND the end** — two places for the same action; the sticky bar makes the
  second one pointless.
- **A JavaScript «floating save» that appears on change** — script for what CSS does.
