# ADR-049 — The form's action bar: «Speichern» always within reach

**Status:** `[APPROVED]` — the owner's idea, approved 2026-09-29 («ja los»)
**Revised:** 2026-10-10 — the fixed row is the RULE for every form and dialog; the bottom bar survives only for the very short confirm («Wirklich löschen? Ja / Nein») — see Revision 2026-10-10
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

## Revision 2026-10-10 — one fixed row for every form and dialog; the exception is the very short confirm (owner decision)

**Trigger.** The owner, looking at the e-mail settings modal with «Abbrechen / Speichern» at its
bottom: «gleich wie bei Word, Excel oder anderen Tools werden die möglichen Aktionen immer in einer
fixierten Zeile angezeigt, niemals unten oder in der Mitte». The review of 2026-10-10
(`docs/03-development/forms-actions-review-2026-10-10.md`, 110 forms) found this ADR built in the
journal and reached by nothing else: 53 dialogs keep their actions in a bottom footer, no action
bar and no window exist outside finance / debtor.

**Point 2 is rewritten — the form no longer decides:**

- Every form and dialog shows its actions in ONE fixed row: on a page the toolbar (`form="<id>"`,
  point 3 unchanged), in a window directly under the title bar, in a popup modal directly under
  the modal's header — always `.z77-form-actions`, sticky at the TOP of the scroll area. Never at
  the end of the form, never in the middle (the DMS edit modal had its footer between the fields
  and the ACL section).
- `.z77-form-actions--end` stays for exactly ONE shape, by the owner's word (2026-10-10, «Ausnahme
  für ganz kurze Bestätigungen … z.B. wirklich löschen ja nein oder ähnlich»): a confirm that is
  one question and its answers — no input field, no section, nothing to scroll. The moment a
  dialog carries a field, it is a form and the bar goes to the top. «Short» is not the test;
  «no field» is.
- The popup modal's `.be-modal__footer` is retired as the home of actions: the shared row renders
  through one partial (`Z77\Shared` `partials/modalActions` → `.z77-form-actions`), so 53 dialogs
  cannot drift apart again. First aid before the partial lands: a CSS `order` in `_modal.scss`
  that moves every existing footer under the header at once (no template change; Enter still
  presses the first submit button in document order).

**Why the fixed row and not «the form decides».** The flexibility of 2026-09-29 produced no
second placement in fifteen screens — every new dialog copied the footer it saw. A placement that
is the same everywhere is the one the eye stops searching for; that is the owner's argument, and
it is the same argument ADR-033 makes for the action cell. The confirm exception costs nothing:
with no field there is nothing between the question and its answer.

**Built with this revision (2026-10-10, the same day):** the `order` first aid in `_modal.scss`;
the shared partial `Z77\Shared` `partials/modalActions` (`css-backend.md` FORM-ACTIONS-002); every
dialog of every module moved onto it — Content, Service, System, debtor, financial, VAT, contact,
DMS, member profile and member backend — so `tests/form-actions.php` finds no `.be-modal__footer`
left under `packages/`; the field-less confirms use `end`, everything with a field the top row;
the DMS edit modal's middle footer is one top row, the trash's «Papierkorb leeren …» stands in the
top row; the member Konto dialog's bar moved under its title (member buttons, same geometry).
Still open: the invoice «Definitiv stellen» confirm is a page (DEBTOR-WIN-002, needs a window
opener for a GET form), the member 2FA setup card, and the browser look at all of it.

## Rejected Alternatives

- **Buttons only at the end** — the state before; the owner's objection.
- **Buttons at the top AND the end** — two places for the same action; the sticky bar makes the
  second one pointless.
- **A JavaScript «floating save» that appears on change** — script for what CSS does.
