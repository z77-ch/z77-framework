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
│ Bereichswähler   │ Kopfleiste            [? Hilfe] [🔔] [Profil] │
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
| **Werkzeugzeile** | first the tabs (language, mode, view), then tools in secondary form — no primary button | `{action}.toolbar.tpl.php` (hc2) |
| **Krumenzeile** | the position only — fixed height, no buttons, no help icon | default crumb or `{action}.crumb.tpl.php` |
| **Kopfleiste** | «? Hilfe» — only on pages that carry help | written by the shell |
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
| writes / confirms (the screen is a form or a capture) | `be-btn be-btn--confirm` (green, check icon), submitting through `form="…"`: «Buchen», «Speichern» |
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
| Sections of a long form | radio tabs (`.be-radiotabs` + `.be-viewtabs__tab--for` in the toolbar), one form, one save; the first tab with an error opens | separate pages per section |

Details and selectors: [css-backend.md → button / tab / switch vocabulary](../topics/css-backend.md).

## 4. The work area

- **Every table is a standard list** — `Z77\Shared\Listing`: columns declared once
  (`{X}Listing::definition()`), sort and magnifier search per column in the database, 50 per
  page, a fetch region. Write only the rows. → [listing.md](../topics/listing.md)
- The first cell of a row is a **state icon** that opens the row as a **window** (ADR-047); the
  other cells are `<label for>` of their column's search field.
- Per-row on/off: a `be-switch` in a column headed «Aktiv». Per-row actions: the ⋮ menu, which
  ALWAYS opens the row's action menu.
- **Selection-bound actions** («Definitiv stellen», «Mahnlauf starten») stand in a bar ABOVE the
  list, green, never at its end.
- **A pending step must be visible**: when data waits for a follow-up step, the top of the page
  says so and offers the green button («5 zugeordnet · noch nicht verbucht — Verbuchen»).
- A log or history is its **own navigation entry** (read-only list), not a toggle on another list
  («Änderungsprotokoll», not «gelöschte zeigen»).
- A form's save is in the action cell, never at the end of the body.

## 5. Help

- A screen with help attaches it through the HelpService (ADR-048); the shell then shows
  «? Hilfe» in the top bar. No help → no button.
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
5. Lists as standard lists; row icon → window; switches «Aktiv»; ⋮ → action menu.
6. Selection-bound actions in a bar above the list; pending steps announced at the top.
7. Forms: save in the action cell; long forms as radio tabs.
8. Help with field sections where a field needs explaining.
9. Rows added to [css-backend.md → action cell per area](../topics/css-backend.md); topic doc of the module updated; `npm run docs:check` green.
