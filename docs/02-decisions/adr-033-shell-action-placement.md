# ADR-033 — Shell action placement: one rule for every shell

Date: 2026-08-15 · Status: accepted · Revised: 2026-09-28 (an action stands where the thing it acts on is shown; the phone drawer — see «Revision 2026-09-28» below)

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
follows ONE rule. (The names of ALL shell regions — top bar, rail, seam, work
area and the rest, with their backend / member classes and German words — are
in the glossary in [`css-backend.md` → shell regions](../topics/css-backend.md#shell-regions-glossary),
added 2026-09-28. The header slots also load as `{action}.act|toolbar|crumb.tpl.php`.)

| Place | backend | member | Carries |
|---|---|---|---|
| **Action cell** | `hc1` / `{action}.act` | `me-shell__act` (`shellActions`) | the DECISIVE action(s) on what the RAIL shows (the list there) — max two VISIBLE buttons; weight follows meaning (accent = forward, quiet = ends/leaves) (revised 2026-09-28) |
| **Toolbar** | `hc2` / `{action}.toolbar` | `me-shell__toolbar` (`shellTabs` / `shellTools`; `shellWorkActions`) | the page's TABS or its TOOLS on the left — never both. Tools include the shown thing's STATE SWITCHES and a list's FILTERS (revised 2026-08-15). After them, LEFT-aligned like everything in the toolbar: the actions on what the WORK AREA shows (revised 2026-09-28 — see below) |
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

## Revision 2026-09-28 — an action stands where the thing it acts on is shown; the phone drawer

Owner decision, found on the axo3 member «Mandant › Stammdaten» screen:
«Bearbeiten» sat in the action cell on the LEFT, over the list of sections,
while it edits the Stammdaten shown on the RIGHT. The button stood next to
the thing one chooses from, not next to the thing it changes.

**The rule (confirmed by the owner the same day, after two rounds): an action
stands on the side where the thing it acts on is SHOWN.** It is what the
owner's case-by-case decisions had in common:

- **Member «Widget» / «Onepager»**: the rail IS the list of snippets / pages.
  «Neues Snippet», «Neue Seite» add to the LEFT side → action cell. Likewise
  «Bestand aktualisieren» (refreshes the list) and the Bestand's «… erfassen».
- **Member «Mandant»**: the rail only lists the sections; «Bearbeiten»
  (Stammdaten) and «Einladen» (Zugänge) change what the RIGHT side shows →
  toolbar (`shellWorkActions`).
- **Backend**: the rail is the area's NAVIGATION (subnav — Navigation, Nav
  Alias, Benutzer …); every list lives in the work area. So every backend add
  action («+ Eintrag», «+ Kontakt», «Hochladen», «Sichern», «Konto», «Buchung
  erfassen» …) acts on the right side → **toolbar (hc2)**. All sixteen moved
  on 2026-09-28; the backend action cell is empty on every framework screen
  now, and stays the place for an action that works on the rail itself.
- **A selection is a choosing activity → action cell** (owner 2026-09-29):
  what the whole screen works on is picked on the left. First user: the
  fiscal-year selection of the journal and the reports (`financial.md`,
  `FiscalYearSelection` — default the current year, a deviation remembered per
  session). The report tabs, which switch the view on the right, went to the
  toolbar the same day.
- **Journal** (FIN-JOURNAL-CAPTURE-001): «Einzel | Sammel», «MwSt» and
  «Buchen» all act on the capture form on the right → toolbar.

In the toolbar everything is LEFT-aligned: tabs or tools first, the actions
after them (owner, 2026-09-28). Where a screen's tools push a group to the
right edge (the Drive), its action comes first instead. Tabs and actions share
the row. A form's Speichern + Abbrechen stays in the action cell where the
rail is the list being edited (member forms); where it is not, it follows the
rule like every other action.

**On a phone the whole left side is a drawer (Schublade).** Area switcher,
action cell and rail slide in together from a menu icon — the first thing in
the top bar; the crumb gap, an empty cell, is dropped. The work side keeps the
full width. Choosing happens in the drawer, working on the right. Backend
below 767px (`shell.js` toggles `is-drawer-l`), member below 60rem (CSS only:
the checkbox `#me-drawer`, the icon and the backdrop are its labels). The
member opens the drawer on load when nothing is selected (`$detailOpen`
empty) — otherwise the work side would be empty.

**Per screen the action cell may stay visible on a phone** (owner: «we do not
want to build ourselves in»). A backend screen whose hc1 must be reachable
without the drawer marks an element in its hc1 template with
`data-shell-act-inline`: that screen's action cell then moves to the end of
the band as a glyph (the shape from SHELL-BAND-ROW-001). Details and
mechanics: `css-backend.md` → SHELL-DRAWER-001.
