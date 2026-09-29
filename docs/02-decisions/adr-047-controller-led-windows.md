# ADR-047 — Controller-led windows: open a record, save it, update what shows it — without a page load

**Status:** `[PROPOSED]` — drafted 2026-09-29 from the owner's specification; waits for approval
**Date:** 2026-09-29
**Builds on:** [ADR-003](adr-003-controller-response-objects.md) (typed responses),
`fetch.md` (envelope, commands, POPUP-CLOSE-001, FETCH-REGION-001)

---

## Context

A backend screen still reloads as a whole too often. The journal (FIN-JOURNAL-CAPTURE-001)
showed it first: a click on an entry's text opens its detail as a full page, and the capture
form above the list is gone. Order processing (P3/P4) will need the same thing everywhere: a
customer opened from an order, its address opened from the customer, a line edited in place,
and the order's totals recalculated after every save.

Pieces exist, each on its own:

- `data-fetch-get` opens ONE popup — there is exactly one `<dialog>`;
- a save answers with an envelope of commands (`replace-html`, `update-fields`, `close-modal`);
- a fetch region reloads one part of a page (FETCH-REGION-001).

Two things are missing:

- **more than one window at a time**: a customer and, next to it, that customer's address;
- **knowing where the window came from**: the same detail is opened from the journal, from an
  account statement and from an order, and each needs a different update after the save.

The owner's model (2026-09-29): **the UI controller is the master of the display** (MVC):

> I click a button → the controller assembles the data and delivers the partial → core.js shows
> it as a popup → the controller tells the form where to send it back → save → the data reach
> the controller action → validation → flush → a response with the changed data → core.js
> updates according to the controller's instructions and values.

## Decision

A framework standard, **WIN**, in five parts.

### 1. The controller leads

It renders the window's content (a partial, fetch mode — the fetch skeleton, `main` only), names
the form's target, and after the save it answers with **instructions and values**. `core.js`
carries them out and decides nothing about content. No page region subscribes to events on its
own; nothing updates unless a controller said so.

### 2. The origin travels with the request

A trigger says where it stands: `data-origin="<name>"` (the page region or the window it sits
in). `core.js` sends it with the GET; the controller writes it into the form it renders (hidden
`_origin`); the save action reads it back and answers for THAT origin — the journal list gets
its row replaced, an account statement its balance, an order its totals. One action serves
every place it is opened from, and it knows which one it is serving.

### 3. A window's identity is MASK + ENTITY + ID

A window declares what it edits: `data-window="<mask>"` and `data-window-entity="<entity>:<id>"`
(e.g. `customer-master` / `customer:42`).

- The same mask on the same record opens **once**: a second click brings the open window to the
  front.
- The same record may be open in **two different masks** at the same time (customer 42:
  master data and conditions) — **on one condition: a field that can be changed must not be in
  both.** Otherwise two windows overwrite the same value.
- The condition is **checked, not only documented**: on opening, `core.js` compares the new
  window's form field names with the open windows of the same entity. On an overlap the new
  window does not open, the open one comes to the front, and a message names the field. A
  developer's mistake shows at once, not as a lost input.

### 4. Several windows; placement is presentation

Any number of windows may be open. A window can open another (the customer's «Adresse»
button). **Where they appear — side by side, on top of each other, later draggable — is CSS
and a little presentation JavaScript; it is not part of this contract** and changes nothing in
the flow of parts 1–3. On a phone they lie on top of each other with a way back.

- The page behind is inert and dimmed while a window is open (as today). The windows among
  themselves stay usable.
- Closing a window closes the windows opened FROM it (the customer takes its address along);
  a child can be closed alone.
- Closing stays explicit — × or Esc, never a backdrop click (POPUP-CLOSE-001).

### 5. The answer vocabulary

The existing envelope commands, made origin-aware, plus a few:

| Command | Does |
|---|---|
| `replace-html` / `update-html` / `update-fields` | as today — the target is resolved INSIDE the origin (page region or window) when one is given |
| `refresh-region` | reloads a fetch region by its own URL (FETCH-REGION-001) |
| `close-window` | closes the window the save came from (and its children) |
| `open-window` | opens a further window (e.g. «saved — now the address») |

Plain values for recalculated fields (totals, VAT) travel as `update-fields`; the controller
computes, `core.js` writes them.

## Reasoning

- **The controller is the one place that knows the data AND the view** — MVC as the owner
  describes it. A subscription model (regions listening for «customer changed») was proposed
  and rejected: it moves decisions into markup, where nobody finds them.
- **The origin is what makes one action reusable.** Without it the save action either guesses
  or grows one branch per page that might open it.
- **Mask + entity + id is the real identity.** The owner allows two masks per record; the field
  rule keeps that safe, and checking it in `core.js` costs one comparison per open.
- **Placement outside the contract** keeps the door open (side by side today, draggable
  tomorrow) without touching a controller.

## Consequences

- `core.js`: a window manager replaces the single `<dialog>` — open / focus / close with
  children, the identity and field-overlap check, the origin on requests, origin-scoped command
  targets, the new commands. The contract is data attributes only (module-agnostic, Rule 8);
  JavaScript is justified by Rule 7: fetching and swapping content is not CSS.
- Controllers: a window action renders a partial, writes `_origin` into its form, and answers a
  save with commands for that origin. A thin helper on the fetch response keeps it one line
  per instruction.
- Existing popups keep working: a `data-fetch-get` without `data-window` is a window without an
  identity (no uniqueness check), the single-popup behaviour is the special case of one window.
- First users: the journal entry detail as a window (FIN-JOURNAL-CAPTURE-001, owner
  2026-09-29); then order processing.
- Tests: the identity and overlap rule, origin round trip, close-with-children — in the shared
  JS harness and per module in PHP (the controller's answer for an origin).

## Open points (for the owner)

- Whether a window's content may be a full edit form directly (edit in the window) or opens as a
  read view with «Bearbeiten» — per screen, or one rule?
- Unsaved changes when a window is closed: ask, or discard silently?

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Regions subscribe to change events, the controller only announces «X changed» | Owner, 2026-09-29: the UI controller is the master of the display; decisions belong to it, not to markup |
| One `<dialog>` with a stack inside | Cannot show two windows side by side; placement would leak into the contract |
| `<iframe>` per window | A full document per window — heavy, and every window loads the whole backend chrome again |
| Uniqueness per entity id only | Too strict: the owner wants two masks per record, with the field rule instead |
