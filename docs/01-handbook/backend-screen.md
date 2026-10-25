# Building a Backend Area — where every control goes

**Status:** `[CURRENT]`
**Date:** 2026-10-08

The recipe every new backend area (and every new screen in an existing one) follows. It
collects the owner decisions of 2026-10-07/08 in one place so a new module does not
re-derive them. Each rule links the doc that owns the detail.

**The developer canvas** — <https://claude.ai/artifact/4hTQySATC3KMhqxN7RZMGh> — shows the
rules as mockups: one board for the rules, one per area (desktop), one for the phone, the
action-cell colours and the help access. **A new area gets its board there BEFORE it is built**
(the owner looks at it, then it is built). The canvas is the working instrument, this file and
the topic docs are the binding text.

---

## 1. The regions — one job each

```
┌──────────────────┬───────────────────────────────────────────────┐
│ Bereichswähler   │ Kopfleiste     [✦ KI-Assistent] [🔔] [Profil] │
├──────────────────┼───────────────────────────────────────────────┤
│ Aktionszelle     │ Werkzeugzeile: Reiter, dann Werkzeuge          │  ← band, same height as the top bar
├──────────────────┼───────────────────────────────────────────────┤
│ (Krumenlücke)    │ Krumenzeile: nur «wo bin ich», feste Höhe     │
├──────────────────┼───────────────────────────────────────────────┤
│ Auswahl ▾        │ Arbeitsfläche: Standard-Liste oder Formular    │
│ Schiene          │                                               │
└──────────────────┴───────────────────────────────────────────────┘
```

| Region | Carries | Template / slot |
|---|---|---|
| **Aktionszelle** | the MOST FREQUENT action of the selected navigation entry — exactly one control, or empty | `{action}.act.tpl.php` (hc1); a fragment: `addPartials(…, 'hc1')` |
| **Auswahl** (top of the rail) | a choice that holds for the WHOLE area (the fiscal year) | `{action}.select.tpl.php` / section `railSelect` |
| **Werkzeugzeile** | first the tabs (language, mode, view), then tools in secondary form — no primary button; on a LIST with help «KI-Assistent» (or «Hilfe», §5) at its right end (HELP-003) | `{action}.toolbar.tpl.php` (hc2); the trigger written by the shell |
| **Krumenzeile** | the position only — fixed height, no buttons, no help icon | default crumb or `{action}.crumb.tpl.php` |
| **Kopfleiste** | «✦ KI-Assistent» (or «? Hilfe», §5) — only on pages that carry help and are not a list (a list's stands in its toolbar) | written by the shell |
| **Arbeitsfläche** | the content; buttons only when bound to a selection or inside a dialog | the action template |

Names and classes: [css-backend.md → shell regions](../topics/css-backend.md#shell-regions-glossary);
placement rule: [ADR-033](../02-decisions/adr-033-shell-action-placement.md) (revision 2026-10-08).

## 2. The action cell — decide it first

For every navigation entry of the new area, write down ONE line: *what does a user do here most
often?* That is the action cell. The target table of the existing areas is in
[css-backend.md → action cell per area](../topics/css-backend.md) — add the new area's rows there.

| The most frequent action … | Action cell |
|---|---|
| creates something | «+ Neu» — `be-btn be-btn--primary` with the plus icon: «+ Kontakt», «+ Rechnung» |
| saves a form | «Speichern» — `be-btn be-btn--primary` (accent), submitting through `form="…"` (ADR-033 addendum 2026-10-16) |
| books / finalises (a capture that writes into the books) | `be-btn be-btn--confirm` (green, check icon), submitting through `form="…"`: «Buchen», «Verbuchen» |
| has several kinds, one of them the everyday one | split picker `.be-shell-add--split` — the main part runs the default and its label NAMES it («↓ Daten sichern \| ▾», «+ Texteintrag \| ▾»), the menu lists the default first |
| has several equal kinds | picker `.be-shell-add` «+ Eintrag ▾» |
| does not exist (a report, a log, a settings list) | leave the cell EMPTY |

- Never two buttons in the cell; never own padding, radius or colours — the shell renders the
  control inset in the palette's island accent (create) or light green (confirm).
- On a phone the same control becomes a square icon at the right end of the toolbar (`+`, `✓`,
  `▾`) — automatic, nothing to build.

## 3. Controls — one look per kind

| Kind | Use | Never |
|---|---|---|
| Create | `be-btn--primary` + «+» — action cell only | in the toolbar or at the end of a list |
| Confirm | `be-btn--confirm` (green) — something is written | for navigation or «open» |
| Secondary | `be-btn--ghost` (surface + border): Drucken, Neuer Ordner | bare text buttons |
| Danger | `be-btn--danger`, red border, label ends with «…» (a confirmation follows) | filled red |
| Tabs | `.be-viewtabs` — text with accent underline: language, mode, view | boxes or pills |
| On/off | `be-switch` — the ONLY on/off form; a switch for a CSS reveal is a `<label for>` of the checkbox (`.be-switch--for`) | checkbox links, text toggles |
| Area choice | the rail-top select (`railSelect`) — a floating tinted card | a dropdown in the toolbar |
| List filter over a long tree | the tree picker in the toolbar (`Z77\Shared` `partials/treePicker`) — a field with the chosen path, search over all levels, one level at a time | the rail-top select (it is not an area choice) |
| Sections of a long form | radio tabs (`.be-radiotabs` + `.be-viewtabs__tab--for` in the toolbar), one form, one save; the first tab with an error opens | separate pages per section |

**An icon stands for a word only where there is no room for the word, or where the layout
prescribes one** (owner 2026-10-10, at the «? Hilfe» button). Next to its own label an icon
repeats it and adds a second thing to read: «Hilfe» is the button, the «?» badge is what a
phone falls back to when the label no longer fits. Where an icon does take over, the label
is hidden VISUALLY (`clip-path`, as the action cell does it) — never `display: none`, which
would leave a button without a name for a screen reader. The leading kind glyph of an action
(«+» create, «↑» upload, «✓» confirm) is not this case: it marks the KIND, it does not
replace the word.

Details and selectors: [css-backend.md → button / tab / switch vocabulary](../topics/css-backend.md).

## 4. The work area

- **Blocks are cards on the window ground** (ADR-051): a form section, a list, a totals block
  sits on its own `--be-surface2` card with a 1px line on `--be-bg`; inputs on `--be-field`;
  inputs inside a table borderless until hover / focus. What belongs together sits on one card.
- **Every table is a standard list** — `Z77\Shared\Listing`: columns declared once
  (`{X}Listing::definition()`), sort and magnifier search per column in the database, 50 per
  page, a fetch region. Write only the rows. → [listing.md](../topics/listing.md)
- The first cell of a row is a **state icon** that opens the row as a **window** (ADR-047); the
  other cells are `<label for>` of their column's search field.
- Per-row on/off: a `be-switch` in a column headed «Aktiv». Per-row actions: the ⋮, which
  ALWAYS opens the row's actions as a **context menu** hanging at the ⋮ — no dialog, no header,
  no «Schliessen», no ×; Esc or a click outside closes it (owner 2026-10-22). Markup
  `data-menu-open` on the ⋮, the `actions` action renders only `.be-menu` items →
  [fetch.md → context menu](../topics/fetch.md). An item opens its dialog as before.
- **Selection-bound actions** («Buchungsdruck …» of the invoices — «Verbuchen …» / «Definitiv stellen» until 2026-10-10 —, «Mahnlauf starten») stand in a bar ABOVE the
  list, green, never at its end.
- **A pending step must be visible**: when data waits for a follow-up step, the top of the page
  says so and offers the green button («5 zugeordnet · noch nicht verbucht — Verbuchen»).
- A log or history is its **own navigation entry** (read-only list), not a toggle on another list
  («Änderungsprotokoll», not «gelöschte zeigen»).
- A form's save is in the action cell, never at the end of the body.
- **One left edge.** The crumb, the toolbar's first control, a section title, a list's head
  and its first cell all start on `--be-edge`; nothing else indents, only a tree child by one
  step per level (owner 2026-10-10, css-backend.md EDGE-001). A list declares the lead slots it
  HAS (`be-tree--lead-switch` / `--lead-menu` / `--lead-none`); it never reserves an empty one.
- **A helper action in a row is an icon.** «Jetzt starten» (▶), download, retry — a glyph
  with its name in `aria-label` and `title`, in the row's trailing `.be-tree__tools`, never a
  labelled primary button per row (owner 2026-10-10, the jobs' «Jetzt einreihen»). A row has
  ONE line; a second line under it is a sign that something belongs in a dialog.
- **A dialog or window shows its actions in ONE fixed row directly under its title** — the
  shared partial `Z77\Shared` `partials/modalActions` (primary first, the record's actions, then
  «Schliessen» quiet and RIGHT-aligned behind a thin rule — owner 2026-10-22; a dialog also carries
  the × top right like a window's title bar), never
  at the bottom, never between two sections (ADR-049 revision 2026-10-10). The one exception,
  by the owner's word: a confirm that is a question and its answers, with no input field, may
  keep the row at the bottom (`end`). The dialog's save answers with what changed — the row,
  the pane, a field — not with a page reload (ADR-047 addendum, FETCH-ROW-001); the page
  behind stays where it was.

## 5. Help

- **The KI-Assistent stands where «Hilfe» stood** (owner 2026-10-25, [ADR-055](../02-decisions/adr-055-ki-assistent-instead-of-help-button.md)):
  with the assistant configured, the trigger reads «✦ KI-Assistent» and opens a chat panel
  that answers from ALL help texts, starting with the current screen's. Its discreet link
  «Hilfetext anzeigen» opens the classic help window; F1 in a field still does. Not
  configured (no key) → the trigger is «Hilfe» as described below. Every logged-in backend
  user may use it.
- **The help texts stay the single source** — the assistant only reads them (index generated
  from the templates, no hand maintenance). So everything below still applies, and a help is
  now also prompt material: a general heading (`<h3>`) before the first field section names
  the topic; buttons and fields are named exactly as on screen.
- A screen with help attaches it through the HelpService (ADR-048); the shell then shows
  the trigger in the top bar. No help → no button.
- **A list attaches its help with `toolbar: true`** (owner 2026-10-20, HELP-003): «Hilfe» then
  closes the list's toolbar at its right end and the top bar shows none — one help button per
  page. Attach it in the list action BEFORE `html()`, and only for the page (a fetch of the
  list region carries none). A window keeps «Hilfe» in its title bar. The text is a short
  `list.help.tpl.php`: what the list is for, the tabs, the main actions.
- Write the help as a general part plus one section per field: `data-help-field="debit"` (the
  field's `name`, or its `data-help-key`). «? Hilfe» or **F1** jumps to the section of the field
  the user is in; without a section the general part shows. The help is in the page — no fetch.
  First example: the journal. → [ADR-048](../02-decisions/adr-048-help-service.md), [fetch.md](../topics/fetch.md)

## 6. Navigation and the menu

- Ship the area's entries in the module's own seed `data/framework/routing/navigation.d/<package>.json`
  (ADR-050): a key on every area, group and leaf, the parent by `parent_key`. A key is never
  renamed. Master data of the area goes into a group under Stammdaten.
- The installer adds new entries on `composer update`, add-only.

## 7. Data behind the area

- Schema changes: change the entity, run `php vendor/bin/z77-db setup` — it writes the
  migration where the entity class lives, shows the SQL, refuses a DROP without `--allow-drop`,
  applies it. → [ADR-039 addendum 2026-10-08](../02-decisions/adr-039-doctrine-driver-behind-unified-entity-manager.md), [persistence-doctrine.md](../topics/persistence-doctrine.md)
- Master data a customer may extend: `#[ORM\MappedSuperclass] abstract class Abstract{X}` with
  every field and the logic + an EMPTY `{X}`; a project overrides only the empty class and
  migrates its columns in `override/z77/project/res/migrations/`. Built when a module needs it,
  never in advance. Variant OPTIONS (vintage, size, colour) are data, not code.

## Checklist for a new area

1. Board on the developer canvas: the area's screens, the action-cell row per entry → owner look.
2. Navigation seed (area, groups, leaves; master data under Stammdaten).
3. Per entry: the action cell (§2) — or deliberately empty; rail-top selection if a choice holds for the whole area.
4. Toolbar: tabs first, tools after, nothing primary.
5. Lists as standard lists; row icon → window; switches «Aktiv»; ⋮ → context menu (`data-menu-open`).
6. Selection-bound actions in a bar above the list; pending steps announced at the top.
7. Forms: save in the action cell; long forms as radio tabs.
8. Help with field sections where a field needs explaining — it is also the KI-Assistent's knowledge (ADR-055).
9. Rows added to [css-backend.md → action cell per area](../topics/css-backend.md); topic doc of the module updated; `npm run docs:check` green.
