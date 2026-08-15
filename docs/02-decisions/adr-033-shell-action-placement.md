# ADR-033 — Shell action placement: one rule for every shell

Date: 2026-08-15 · Status: accepted

## Context

The framework now carries two work-area shells — the backend (`be-shell`:
topbar, header band `hc1|hc2`, columns) and the member work area
(`me-shell`: header, action cell + toolbar, crumb line, split panes). Both
grew their own habits for WHERE a button lives: the backend documented
"hc1 carries the primary action" but mixed the Drive breadcrumb WITH its
folder tools in `hc2`; the member put a form's save button at the end of a
long scrolling body while «Abbrechen» sat in the action cell — with the tab
split (member v1.7.0) the save even changed its distance from the eye
depending on which tab was open.

A human is guided by place and habit, not by search. A button that moves
does not build a habit.

## Decision

Every shell offers the same four places, and what a screen puts where
follows ONE rule:

| Place | backend | member | Carries |
|---|---|---|---|
| **Action cell** | `hc1` | `me-shell__act` | the context's DECISIVE action(s) — max two VISIBLE buttons; weight follows meaning (accent = forward, quiet = ends/leaves) |
| **Toolbar** | `hc2` | `me-shell__toolbar` | the page's TABS or its TOOLS — never both. Tools include the shown thing's STATE SWITCHES and a list's FILTERS (revised 2026-08-15) |
| **Crumb line** | `hc3` (own slim row) | `me-shell__crumbs` (own slim row) | POSITION only — the breadcrumb, nothing else |
| **Content** | column 2 | detail pane | only what is bound to an in-content selection, and dialog-internal buttons |

Concretely:

- **A form's submit belongs in the action cell**, not at the end of the
  body. Outside the `<form>` it submits through the HTML `form` attribute —
  no script involved. Cancel sits beside it, quiet. **One word per button**
  («Speichern», «Abbrechen») — the cell is narrow, and what is being saved
  is what the page says.
- **More than two choices collapse into ONE button with a panel** — the
  backup screen's add-picker (`.be-shell-add__panel`, hc1) is the model: a
  single primary button opens the small list of kinds. The cell never grows
  a button row; a cell one has to scan is a menu, not a decision.
- **Per-target tools belong in the toolbar** (member widget entry:
  Kopieren · Vorschau · Bearbeiten · Löschen; Drive folder: edit · move ·
  delete · new folder · trash) — labelled buttons where space allows, the
  eye reads words faster than it guesses icons.
- **The crumb line carries the crumb, full stop** (revised the same day, on
  the second look: the first version kept a level's switch in the crumb —
  but a switch is an OPERATION, and operations live in the toolbar). A
  state switch renders as a LABELLED tool («Liegenschaft sichtbar»), since
  it no longer sits next to the name it toggles; a cascade lock travels
  with it (child switch disabled while the parent is off). A list's filter
  is a tool row too — the backend's navigation list has always done it
  that way in hc2. The member's short-lived `crumbActions` slot (born
  2026-08-15, never used) is removed — tools have ONE place, the toolbar.
- **The backend gets the same slim crumb row the member has** (`hc3`, own
  grid row under the band). Screens without an own `hc3` template get a
  navigation-derived default crumb (section › page) so the row says where
  one is on EVERY screen; Drive overrides it with its live breadcrumb pane.

## Exceptions — each with its reason

1. **Dialogs carry their own buttons.** A modal makes the page inert; a
   save in the action cell would be dead (learned 2026-08-12 on the member
   account dialog).
2. **Selection-bound actions stay with the selection.** A mass switch
   («Ausblenden (12)») references marked rows and carries their count — it
   belongs next to the list it acts on.
3. **A view-shape choice is not a tab.** Baum|Liste in the member Objekte
   area stays in the left column (decided 2026-08-13); tabs mean sections
   of ONE surface, never a second place to choose the data's shape.

## Consequences

- module-member: the skeleton takes `shellActions` (list, submit-capable)
  and `shellTools`; `crumbActions` is gone before anyone used it.
- module-backend: the shell grid gains the crumb row (`--shell-crumb`);
  `hc3` is its slot (the auto-loader already knew the name); Drive's
  folder tools move from the crumb pane into `hc2`.
- Projects stop building their own action rows in content templates — the
  override shrinks to handing the shell its data.
