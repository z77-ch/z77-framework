# ADR-033 — Shell action placement: one rule for every shell

Date: 2026-08-15 · Status: accepted · Revised: 2026-09-28 (an action stands where the thing it acts on is shown; the phone drawer — see «Revision 2026-09-28» below); 2026-10-08 (the action cell carries the most frequent action again; area selection at the top of the rail; the phone icon — see «Revision 2026-10-08» below)

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
| **Action cell** | `hc1` / `{action}.act` | `me-shell__act` (`shellActions`) | the DECISIVE action(s) on what the RAIL shows (the list there) — max two VISIBLE buttons; weight follows meaning (accent = forward, quiet = ends/leaves) (revised 2026-09-28). **Backend since 2026-10-08: the MOST FREQUENT action of the selected navigation entry, exactly one button, inset in the island accent** (see the revision of that date) |
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
- ~~**Backend**~~ (superseded 2026-10-08 — the backend action cell carries the entry's most
  frequent action again, see below): the rail is the area's NAVIGATION (subnav — Navigation, Nav
  Alias, Benutzer …); every list lives in the work area. So every backend add
  action («+ Eintrag», «+ Kontakt», «Hochladen», «Sichern», «Konto», «Buchung
  erfassen» …) acts on the right side → **toolbar (hc2)**. All sixteen moved
  on 2026-09-28; the backend action cell is empty on every framework screen
  now, and stays the place for an action that works on the rail itself.
- ~~**A selection is a choosing activity → action cell**~~ (superseded 2026-10-08 — a selection
  that holds for the whole area stands at the top of the rail, see below) (owner 2026-09-29):
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

**On a phone the whole left side is a drawer (Schublade).** (Backend: the action cell left
the drawer on 2026-10-08 — see below.) Area switcher,
action cell and rail slide in together from a menu icon — the first thing in
the top bar; the crumb gap, an empty cell, is dropped. The work side keeps the
full width. Choosing happens in the drawer, working on the right. Backend
below 767px (`shell.js` toggles `is-drawer-l`), member below 60rem (CSS only:
the checkbox `#me-drawer`, the icon and the backdrop are its labels). The
member opens the drawer on load when nothing is selected (`$detailOpen`
empty) — otherwise the work side would be empty.

**Per screen the action cell may stay visible on a phone** (owner: «we do not
want to build ourselves in»). (Superseded 2026-10-08: on a phone the backend action cell is
ALWAYS visible now, as a square icon; `data-shell-act-inline` is gone.) A backend screen whose hc1 must be reachable
without the drawer marks an element in its hc1 template with
`data-shell-act-inline`: that screen's action cell then moves to the end of
the band as a glyph (the shape from SHELL-BAND-ROW-001). Details and
mechanics: `css-backend.md` → SHELL-DRAWER-001.

## Revision 2026-10-08 — the action cell carries the most frequent action again; area selection at the top of the rail; the phone icon

Owner decision, worked out on the design canvas of the backend shell — the «developer canvas»,
<https://claude.ai/artifact/4hTQySATC3KMhqxN7RZMGh>, extended when new areas come. It revises
the BACKEND half of the 2026-09-28 revision; the member shell is not touched.

**Why.** The eye looks for an action ABOVE the menu entry it belongs to: the action cell sits
directly over the rail, and the selected rail entry is what the action is about. With the cell
empty and «+ Neu» somewhere in the toolbar, every screen put its main action at a different
place. And on a phone the drawer hid the action cell entirely — the action was behind the menu
icon, where nobody looks for it.

What changes against 2026-09-28:

1. **Action cell = the most frequent action of the selected navigation entry** — exactly ONE
   button, rendered INSET in the island accent (owner 2026-10-08 after the live look — first
   built flush, which read as a coloured block: now ~8px air, radius 7px, the island's light
   accent with dark ink; confirm a light green in every palette; label with text), fed by the
   existing `{action}.act.tpl.php` convention (hc1). Empty when the entry has no frequent action.
   Weight follows meaning: create («+ Neu») = accent fill (`.be-btn--primary`); confirm —
   something is WRITTEN («Buchen», «Speichern», «Definitiv stellen») = green fill
   (`.be-btn--confirm`); several kinds = the add-picker `.be-shell-add` («Eintrag ▾»); several
   kinds with a default = the split picker («↓ Sichern | ▾»: the main part runs the default, the
   chevron opens the menu) — inset as well, the menu a solid light card in the page colours. Replaces «the backend action cell is empty on every framework screen» and
   «every backend add action → toolbar».
2. **The band is as high as the top bar** (`--shell-band: var(--shell-bar)`) — area switcher and
   action cell are one column of equal cells.
3. **A selection that holds for the whole AREA stands at the TOP OF THE RAIL**, above the menu
   entries it applies to — not in the action cell. First user: the fiscal year of Finanzen
   (journal and reports). Convention: template `{action}.select.tpl.php`, or a fragment's
   `addPartials(…, 'railSelect')`; the skeleton renders the section `railSelect` at the top of
   column 1, only when it has content. Replaces «a selection is a choosing activity → action
   cell» (2026-09-29).
4. **Toolbar**: tabs first (left), then the tools in secondary form. No primary button there any
   more once the screens are migrated — their primary action moves to the action cell.
5. **Phone (below 767px)**: the drawer keeps area switcher, the rail-top selection and the rail.
   The action cell is NOT in the drawer: its button becomes a SQUARE ICON at the RIGHT end of the
   toolbar row, as high as the row, flush, in the PAGE colours (it is outside the island there:
   page accent, `--be-confirm`), a split picker as its chevron alone, the label kept as the
   accessible name (visually hidden). Glyphs: «+» create, «✓» confirm, the chevron of a picker.
   Pure CSS — the band turns into a flex row and `order` moves the same element. The per-screen
   switch `data-shell-act-inline` is gone: what it opted into is now the rule.
6. **One look per kind of control** (`css-backend.md` → «buttons, tabs, switch»): tabs are text
   with an accent underline (`.be-viewtabs`), never a box; secondary = surface with border;
   danger = red border, never filled; `.be-switch` is the only on/off form.

Unchanged: the crumb line carries the crumb only; dialogs carry their own buttons; actions bound
to a selection of rows stay with the selection («Definitiv stellen (3)», «Mahnlauf starten»).

**Consequences.** module-backend: `_shell.scss` (band height, inset island-accent cell, split picker, solid picker panel, `.be-shell-select`,
phone block), `_buttons.scss` (`--confirm`, the secondary look as the default), the skeleton and
`loadHeaderSlots()` (`railSelect`); module-financial: the fiscal-year dropdown moved to
`railSelect`. Reference migration: Webseiten › Inhalte («+ Inhalt», `list.act.tpl.php`). The other
screens move to the per-area target table in `css-backend.md` in a following step (pending
there).
