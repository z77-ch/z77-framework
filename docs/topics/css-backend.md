# css-backend

2026-07-03

## entry

1. `packages/module-backend/res/scss/` — SCSS source files (tokens, base, components, layout)
2. `docs/01-handbook/css-conventions.md` — BEM naming, token rules, component patterns
3. `packages/module-backend/src/Ui/Config/layoutConfig.inc.php` — which CSS files are loaded and in what order

## file map

SOURCE=/packages/module-backend/res/scss/
SOURCE=/packages/module-backend/res/assets/css/
SOURCE=/packages/kernel/shared/res/scss/components/_split.scss
SOURCE=/packages/kernel/shared/res/assets/js/split.js
SOURCE=/packages/module-backend/res/scss/components/_split-host.scss
SOURCE=/packages/module-backend/res/assets/js/shell.js
SOURCE=/packages/module-backend/src/Ui/Config/layoutConfig.inc.php
SOURCE=/docs/01-handbook/css-conventions.md

## mental model

A **single `base.css`** carries tokens + all components + their responsive rules (`@media` blocks live inside the component partials — the shell and overview are self-contained). It is the only backend stylesheet (`layoutConfig.inc.php` `styleSheets` lists just `base`). The former per-breakpoint layout files (`mobile`/`tablet`/`desktop`/`nav-*`) were retired in the shell cleanup (their dead legacy-grid rules dropped; the live responsive bits moved into `_overview.scss` + `_shell.scss`). Design tokens (`--be-*` + generic `--color-*`/`--space-*`/…) are declared on the `.be` viewArea wrapper (`<html class="be">`), not `:root` (ADR-018); the six palettes × light/dark are layered on top via `[data-be-palette]` / `[data-be-theme]` attribute selectors on the same `<html>` element (same `(0,1,0)` specificity as `.be` → cascade unchanged).

- Backend JS is external assets only, registered in `layoutConfig.inc.php` (`core`, `panel-toggle`, `split`, `appearance`, `system/cache`, `shell`) — no inline `<script>` (the legacy `partials/footer.tpl.php` was removed in the shell cleanup). No JS build pipeline. `core`, `panel-toggle` and `split` come from `Z77\Shared`, the rest from the module.
- Watch / build workflow (incl. the start-of-session ask-for-watcher rule) is uniform across modules — see [`css-watch.md`](css-watch.md).

## scss source files

```text
packages/module-backend/res/scss/
├── tokens/
│   ├── _colors.scss        --be-* backend theme tokens (6 palettes × light/dark)
│   ├── _typography.scss    Inter (ui/display, OFL — fonts/inter/), mono; --be-font-scale (slider) × font-size-vars mixin; --be-font-scale-cap + font-size-capped mixin
│   ├── _spacing.scss
│   └── _effects.scss       --z-topbar:40, --z-dropdown:100, --z-overlay:300, shadows, transitions, radii
├── base/
│   ├── _normalize.scss
│   ├── _elements.scss      base html/body + slim backend scrollbars
│   ├── _utilities.scss     .be-font-cap — caps font growth past --be-font-scale-cap (apply to any region)
│   └── _index.scss
├── components/
│   ├── _buttons.scss       .be-btn (palette-aware) + .be-icon-btn — the single backend button system (font-capped by default)
│   ├── _icon.scss          .be-icon — presentation for <use> sprite icons (stroke/currentColor/caps)
│   ├── _forms.scss
│   ├── _switch.scss        .be-switch reusable on/off toggle slider
│   ├── _cards.scss
│   ├── _alerts.scss
│   ├── _badges.scss
│   ├── _tables.scss
│   ├── _list.scss          .be-list / .be-tree / .be-tabs — shared backend listing/tree (all list views)
│   ├── _modal.scss
│   ├── _pagination.scss
│   ├── _login.scss         Login box (Werkbank design)
│   ├── _guest.scss         .be-guest — chrome-less full-page GUEST wrapper (login/setup)
│   ├── _shell.scss         .be-shell* 3-column shell + .be-shell-add picker + topbar right cluster (.backend-topbar__env/__bell/__avatar) + body.backend base + own @media responsive
│   ├── _shell-banner.scss  .be-shell-banner — shared Störer band at the top of the shell; users: crawl block (SEO-NOINDEX-001) + missing installation identity (ADR-030)
│   ├── _subnav.scss        .backend-subnav + .backend-tree-*
│   ├── _service-panel.scss .backend-service-panel (avatar dropdown)
│   ├── _overview.scss      .be-overview + .be-module-card (+ own @media responsive)
│   ├── _dms-host.scss      host binding: maps --dms-* onto --be-* on `.be .dms` (ADR-018 rule 4)
│   └── _split-host.scss    host binding: maps --z77-split-* onto --be-* on `.be .z77-split`
└── base.scss               tokens + base + all components — the ONLY stylesheet
```

`base.scss` additionally `@use`s ONE partial from outside the module:

```text
packages/kernel/shared/res/scss/components/_split.scss   .z77-split — shared pane primitive
```

(No `layout/` dir and no per-breakpoint entry files any more — the shell cleanup
retired `mobile`/`tablet`/`desktop`/`nav-*`; `_topbar.scss` was split, its live parts
folded into `_shell.scss`.)

## compiled output

```text
packages/module-backend/res/assets/css/
└── base.css        the single backend stylesheet
```

## build commands (run from framework root)

```bash
npm run watch:backend    # watch + auto-compile on save (development)
npm run build:backend    # one-time build, compressed, no source maps (deploy)
```

The watch does **not** start on its own — without it, saved SCSS is simply not compiled
and the browser keeps showing the old CSS. When a session starts on backend SCSS, ask
whether to start `npm run watch:backend` and run it in the background if confirmed,
rather than leaving it to be remembered every time.

## what goes where

| Change | File |
|---|---|
| Color, spacing, shadow, radius, z-index, transition | `tokens/_*.scss` |
| Font-size scale + cap mechanism (`--be-font-scale`, `--be-font-scale-cap`, mixins) | `tokens/_typography.scss` |
| Cap font growth for a region (`max-font-size` behaviour) | add `.be-font-cap` in markup (`base/_utilities.scss`) |
| Body, html base styles, slim scrollbars | `base/_elements.scss` |
| CSS reset | `base/_normalize.scss` |
| Button, form, card, alert, badge, table, modal, pagination | `components/_*.scss` |
| Icon look (`.be-icon`) / add an icon (`<symbol>`) | `components/_icon.scss` / `res/view/templates/partials/icon-sprite.tpl.php` |
| Backend list rows with real columns (v2 — use this for new screens) | `components/_list.scss` (`.be-list__frame` / `__table` / `__head` / `__row` / `__cell` / `__detail`) |
| Legacy tree/hub rows (v1 — migration only, do not build new screens on it) | `components/_list.scss` (`.be-tree` / `.be-tree--hub`) |
| Group tabs, section headers, empty state, section hint | `components/_list.scss` (`.be-tabs`, `.be-list__section-*`, `.be-list__empty`) |
| Pager for a v2 list | `components/_pagination.scss` (`.be-pagination`) |
| Login page | `components/_login.scss` |
| GUEST full-page wrapper (login/setup, no chrome) | `components/_guest.scss` (`.be-guest`) |
| Shell (3-column grid, header slots, add-picker, columns/drawers, topbar right cluster env/bell/avatar, `body.backend` base, responsive) | `components/_shell.scss` |
| Shell Störer band (`.be-shell-banner`, white-on-danger, full width, non-dismissible) — shared by the crawl block (SEO-NOINDEX-001) and the missing canonical base URL (ADR-030) | `components/_shell-banner.scss` |
| Left sidebar (subnav tree) | `components/_subnav.scss` |
| Avatar dropdown panel | `components/_service-panel.scss` |
| Dashboard overview page (+ its responsive) | `components/_overview.scss` |
| Make an embedded foreign fragment (`.dms`, later others) follow the backend palette/theme | `components/_dms-host.scss` — bind that fragment's own tokens to `--be-*`; never edit the fragment |
| Resizable panes, pane scrolling, drag handle, narrow-screen detail overlay | `kernel/shared/res/scss/components/_split.scss` (`.z77-split`) — SHARED, host-neutral; edit only for geometry |
| Make `.z77-split` follow the backend palette | `components/_split-host.scss` (4 tokens) |

## backend theme tokens (--be-*)

Defined in `tokens/_colors.scss`, declared on the `.be` wrapper (`<html class="be">`), not `:root` (ADR-018) — default `werkbank`, light. Override with `[data-be-palette="citrus|coral|lagune|beere|sonne"]` and/or `[data-be-theme="dark"]` on the same `<html>` element (kept after the `.be` block in source → wins at equal specificity).

Active palette + theme are written by `BackendAbstractController::html()` as `data-be-palette` / `data-be-theme` attributes on the `<html>` element (see `html-default-skeleton.tpl.php`). `appearance.js` updates these attributes on user clicks for instant switching and POSTs the change to `/backend/system/system/save-preferences` for persistence. No JS-side token mirror — `_colors.scss` is the single source of truth.

| Token | Use |
|---|---|
| `--be-bg` | page background |
| `--be-surface` | card / panel surface |
| `--be-surface2` | slightly darker surface (panel headers, table header) |
| `--be-rail` | topbar background |
| `--be-rail-text` | topbar text color |
| `--be-text` | primary text |
| `--be-muted` | secondary / helper text |
| `--be-line` | borders and dividers |
| `--be-accent` | primary accent color (moss green default palette) |
| `--be-accent-soft` | accent at low opacity (hover backgrounds) |
| `--be-good` | success / green indicator |
| `--be-warn` | warning / orange indicator |

## JavaScript (no build)

Two sources, no build pipeline:

- **Inline `<script>` in `partials/footer.tpl.php`** — service panel toggle (click + click-outside), hamburger overlay. UI-only, no module state.
- **External assets registered in `layoutConfig.inc.php`** — served as static files via the asset pipeline:
  - `Z77\Shared` / `core` — shared utilities (`_Z77.core.fetch.post`, etc.)
  - `Z77\Module\Backend` / `appearance` — palette + dark-mode click handlers (sets `data-be-*` on `<html>`, POST to save endpoint)
  - `Z77\Module\Backend` / `system/cache` — service-panel cache-clear button

All entries default to `position: footer` + `defer: true`. To run a script before first paint, add `'position' => 'head'` — see commented example in `layoutConfig.inc.php`.

## templates that use these styles

```text
packages/module-backend/res/view/templates/
├── html-default-skeleton.tpl.php    <html class="be" data-be-*> (token wrapper) → body.backend, backend-main wrapper
├── html-guest-skeleton.tpl.php      chrome-less GUEST skeleton (login/setup) → <body class="be-guest">, only $main
├── partials/header.tpl.php          topbar + service panel HTML
├── partials/footer.tpl.php          backend-footer + inline JS
├── LoginController/loginAction.tpl.php
└── DashboardController/overviewAction.tpl.php
```

## rules

- When styling colors, spacing, or effects → MUST use `--be-*` token variables; values MUST NOT be hardcoded
- When declaring tokens (`--be-*`, `--color-*`, `--space-*`, …) → MUST place the default block on the `.be` wrapper selector (the four `tokens/_*.scss` files), keeping the `[data-be-palette]` / `[data-be-theme]` override blocks after it; MUST NOT declare design tokens on `:root` (ADR-018; only `@font-face` in `tokens/_fonts.scss` stays global)
- When adding component styles → MUST live in `components/_*.scss`; MUST NOT be added to layout files
- When an embedded foreign fragment (`.dms`, later others) must follow the backend palette / dark mode → MUST bind that fragment's OWN tokens to `--be-*` in `components/_dms-host.scss` using a two-class selector (`.be .dms`, specificity `(0,2,0)` — the fragment's bundle loads AFTER `base.css`, so a one-class binding loses); MUST NOT edit the fragment's SCSS to reference `--be-*` (that breaks it for every other host — ADR-018 rule 4, [`css-dms.md`](css-dms.md) DMS-HOST-BIND-001)
- When building a NEW backend list screen → MUST use `.be-list` v2 (`__frame` > `__table` > `__head` + `__item` > `__row` > `__cell`), declaring the columns once as `--be-list-cols` on `__table`; MUST NOT use `.be-tree--hub` (v1, migration only) and MUST NOT glue several fields into one cell with `·` — that is the defect v2 exists to remove
- When a v2 list needs a select box, state switch, ⋮ menu, disclosure or action column → MUST add the matching `--`modifier on `__table` (`--select` / `--state` / `--menu` / `--disclose` / `--actions`); an absent modifier contributes NO track, so MUST NOT render a placeholder cell to fill a slot the list does not use
- When a v2 column may be dropped on a narrow pane → MUST add `.be-list__table--drop`, a second track list `--be-list-cols-sm`, and the SAME `data-priority` on both the `__col` and its `__cell`; without the modifier the pane scrolls instead, which is the safe default
- When a v2 row needs an expandable detail (diff, subform, error list) → MUST use the `__disclosure-input` checkbox + `__detail` sibling; MUST NOT use `<details>/<summary>` (a `<summary>` swallows clicks on the switches and submit buttons inside the row) and MUST NOT add JS for it
- When sorting or paging a v2 list → MUST use server-side links (`?sort=` / `?dir=` / `?page=`) with `.be-list__col[data-sort]` / `.be-pagination`; MUST NOT sort or page in JavaScript
- When adding backend interactivity → MUST remain inline vanilla IIFE in `partials/footer.tpl.php`; MUST NOT introduce a JS build pipeline
- When a surface needs resizable side-by-side panes (workspace, list + detail, tree + list + preview) → MUST use the shared `.z77-split` primitive (`kernel/shared`) with the handle contract `data-z77-split-root` / `data-z77-split="--var"` / `-min` / `-max`; MUST NOT write a second drag implementation and MUST NOT rename its classes to `be-*` (it renders in frontend and member hosts too — ADR-018 R5–R7)
- When a `.z77-split` handle is NOT a DOM sibling of the pane it resizes (e.g. a grid overlay like the shell's) → MUST state `data-z77-split-dir="1|-1"`; sibling handles infer the direction and MUST NOT carry it
- When adding a colour/font/radius declaration to `_split.scss` → MUST stop: that breaks the geometry-only test (ADR-018 rule 5) and the component would no longer be shareable. Put the visual part in the consuming area's own component instead
- When running build commands → MUST run from framework root (`npm run watch:backend` / `npm run build:backend`)
- When building a radio/checkbox **selection** (select/choose one or many) → MUST use the shared `.be-choice` component (`__input` / `__label`, optional `--filled` for a tinted row); MUST NOT use `.be-switch` for that (the switch is on/off only)
- The header band renders ALWAYS (both slots, even when empty) — it is a property of the shell, not of the screen (HEADER-BAND-ALWAYS-001). When a screen has no global action → MUST leave the slot empty rather than reintroduce a conditional band; MUST NOT invent an add button for a screen whose actions are all per row (use `.be-shell-status` for its state instead).
- When a screen's health or queue state must be readable without reading the body (job runner, import plan, member queue) → MUST use `.be-shell-status` (`__dot` + `__text`, `--ok` / `--bad`) in hc2; MUST NOT use `.badge` for it — that component runs on the light-only `--color-*` set and is wrong in dark mode.
- When filling a shell header slot (`{Group}/{Controller}/{action}.hc1|hc2|hc3.tpl.php`) → MUST keep it to a SINGLE line. `.be-shell-band__slot` is a FIXED-height band (`height: 46px`, not `min-height`) so every slot stays exactly equal (empty or filled) and the band lines up across columns. Content that needs more room MUST go into a dropdown or popup — MUST NOT make the band taller (would break the cross-column alignment). An hc1 primary action MUST wrap its text in `<span class="be-btn__label">` — the mobile band collapses the button to its glyph, and a bare text node cannot be hidden by CSS (SHELL-BAND-ROW-001). A view with SEVERAL add kinds MUST use the `.be-shell-add` hc1 picker (a «＋ add» button that opens a panel to choose the type, via the panel-toggle contract) rather than stacking multiple add buttons in the band (e.g. translation: Text / Slug). The band scales in fixed px, not `em`/`rem`: it is chrome and matches the font-capped buttons (see FONT-CAP-001) — the font slider scales content, not chrome. If a slot's text grows too large at high font scale, cap it with `.be-font-cap` rather than making the height relative.

## see also

- [`css-watch.md`](css-watch.md) — uniform SCSS watch/build workflow across all modules + ask-for-watcher convention at session start
- [`stylesheet.md`](stylesheet.md) — how compiled CSS is loaded into pages (asset pipeline)
- [`../01-handbook/css-conventions.md`](../01-handbook/css-conventions.md) — BEM, tokens, component patterns

## known issues

- **LIST-V2-001** — added 2026-08-08. `.be-list` v2 exists alongside `.be-tree--hub`; **new screens
  use v2, v1 is migration-only** (LIST-ANATOMY-001 has the inventory that motivated it). Two
  mechanisms carry it: (a) the LIST declares its columns once as `--be-list-cols` on
  `.be-list__table` and every row is a `subgrid`, so column count is a property of the list rather
  than a constant of the component — a variable matrix (translation: key × n languages) is then just
  another value; (b) fixed lead slots are opt-in via `var(--x,)` with an EMPTY fallback, which
  contributes no track at all, so the reserved-but-empty columns and the placeholder `<span>`s are
  gone. Also new: real column headers (v1 had none anywhere, which is what made a truncated cell
  unrecoverable), a real action column (retiring the `style="grid-column:6"` hacks), a full-width
  row detail (retiring the `padding-left:2.1rem` rebuilds), and `.be-pagination`.
  **All of it is CSS** — sorting/paging are server links, the row detail is a checkbox disclosure.
  Two traps worth knowing: the container query lives on `.be-list__frame`, NOT on `__table` (an
  element cannot query its own container — the first draft did and silently did nothing); and
  `<details>/<summary>` was rejected for the disclosure because a `<summary>` swallows clicks on the
  switches and submit buttons the rows carry. `.be-tree__menu` (the ⋮) is deliberately reused from
  v1 and gets renamed when v1 is deleted. **Piloted on `service/backup/list`**: its five fields
  (file / created / size / trigger / count) were one `·`-glued string and are five columns now, with
  the last two dropping on a narrow pane instead of being cut off. **Not verified live.**

- **SHELL-BAND-ROW-001** — added 2026-08-09. **The header band is its own grid row now, not one
  child per column.** Found live: `hc1` carries the PRIMARY action on all seven screens that use
  it (add button, backup picker, upload) — and as a child of column 1 it went into the mobile
  drawer with the column below 767px. The main action of the screen ended up behind the burger,
  where one looks for navigation, not for "new". CSS could not fix it: column 1 carries a
  `transform` there, and a transformed ancestor is the containing block even for
  `position: fixed`, so no child can escape it. The shell grid is therefore
  `topbar / band / columns` (`--shell-band: 46px`), the band spans both columns and mirrors the
  split with the same `--shell-c1`. Consequences worth knowing: `.be-shell-col__head*` is gone,
  the slots are `.be-shell-band__slot--1|--2`; the six dark-island palette overrides had to take
  the hc1 slot along (it left column 1, so it no longer inherits those tokens); `sticky` is gone
  (a row does not scroll); the drawer and its backdrop now start BELOW the band, so the primary
  action stays usable while the drawer is open; and below 767px the band becomes
  `auto | 1fr` with `:empty` collapsing slot 1, so screens without a primary action look
  unchanged. The band already rendered unconditionally (HEADER-BAND-ALWAYS-001) — this is the
  second half of the same insight: **it belongs to the shell, so it must not live inside a part
  of the shell that can disappear.**
  **Below 767px the primary action moves to the END of the band and drops to its glyph**
  (added 2026-08-09, after seeing it live): keeping a full-width labelled button was still too
  much on a phone. The band becomes a flex row and `order` moves the SAME element — the button
  is never duplicated into a second slot, which would be two places to keep in sync. It then
  sits with the icon actions hc2 already carries (the Drive's new-folder / trash buttons are the
  model). This is what `.be-btn__label` is for: **a bare text node cannot be hidden by CSS**, so
  every hc1 primary action wraps its word in that span — all seven were updated, and any new one
  must follow. The add-picker panel anchors right with its own `min-width` there, since it can no
  longer stretch to a ~34px trigger. The dark-island tokens still apply to the slot, so on a phone
  it reads as a small dark cap at the right edge — deliberate, but the first thing to revisit if
  it looks accidental. **Not verified live.**

- **SHELL-CRUMB-ROW-001** — added 2026-08-15 (ADR-033). **The crumb line is the shell's fourth
  row.** The shell grid is `topbar / band / crumb / columns` (`--shell-crumb: 32px`), the crumb
  row mirrors the column split like the band does, its slots are `.be-shell-crumb__slot--1|--2`
  (slot 1 is a bare cell capping the dark island — it joined the six palette selector groups the
  way the band slot did). Slot 2 renders the screen's `hc3` template, or the navigation-derived
  default crumb (`partials/shell/crumb`: section › page, from the same UI cursor the subnav
  reads). It renders ALWAYS, for the same reason the band does (HEADER-BAND-ALWAYS-001) — screens
  must not start at different heights. Division of the chrome per ADR-033: hc1 decides, hc2
  operates (tabs or tools), hc3 says where one is. The Drive was the screen that mixed the two:
  its breadcrumb pane moved from hc2 to hc3, its folder edit/move/delete buttons left the pane
  and became static hc2 buttons reading their server-built URLs off the refreshed pane's data
  attributes (`data-folder-edit-url` etc., the upload-button mechanism); drive.js mirrors URL
  presence onto their `hidden`. Below 767px the crumb row is a flex row and the empty island
  cell disappears; the mobile drawer starts below band + crumb. **Not verified live.**

- **MODAL-CLIP-001** — added 2026-08-18 (found by Peter on axo3's ~2900px snippet form; any
  installation with a modal long enough to scroll is exposed). **`.be-modal` clips with `overflow: clip`, never `hidden`** — and
  **`.be-switch` is `position: relative`**. With `hidden` the dialog IS a scroll container:
  mouse scrolling is blocked, but programmatic scrolling is not, and a focus `scrollIntoView`
  scrolls EVERY ancestor scroll container, the dialog included. Since it shows no scrollbar it
  never comes back — the entire content sits above the box, clipped away: dark/white empty
  modal, content fully in the DOM, no request, no console output. The trigger in the wild was
  the switch itself: `.be-switch__input` is absolutely positioned, and with an unpositioned
  `.be-switch` it anchored to the nearest positioned ancestor (`.be-modal__inner`), ~2500px
  away from its visible track — clicking the label focused an input far outside the view and
  the browser scrolled the dialog to it. `clip` cuts off identically but creates no scroll
  container (the fix against ANY programmatic scroll source); `position: relative` on the
  label anchors the input where its track is (the fix for the focus target). `.be-modal__body`
  keeps its `overflow-y: auto` and scrolls unchanged. Deleting content in DevTools "bringing
  the modal back" is the same mechanism: the browser clamps `scrollTop` when `scrollHeight`
  shrinks. Verified live on axo3 (widget-edit form, the switch works and the modal survives
  scrolling); guarded by axo3's b10 harness against the shipped `base.css`.
  `.z77-popup__body` has `overflow: visible` today (no scroll container, not affected) — if it
  ever gains an overflow value, it must be `clip` for the same reason.

- **NORMALIZE-CHOICE-001** — added 2026-08-19 (found by Peter on axo3's stock list: the
  mark-a-row checkboxes were simply not there). **The normalize does not strip `appearance`
  from checkbox and radio.** `button, input, select, textarea { appearance: none }` is right
  for controls that get a box from a component class — a text field, a button, a select. A
  checkbox has no box of its own: stripped, it paints **nothing at all**, and the control is
  invisible while remaining fully clickable and fully in the DOM. Nothing errors, nothing
  logs; the author sees an empty cell. The workaround had already been written twice before
  the default was fixed — `.dms-file__select` restores `appearance: auto` by hand with a
  comment naming this exact cause, and `.be-form__field select` does the same for selects.
  A default that only works when every author remembers a workaround is broken, not strict.
  Now: `input[type="checkbox"], input[type="radio"] { appearance: auto; accent-color: var(--be-accent) }`
  — the native control, wearing the backend accent, following every palette and both themes
  without a rebuild. Components that deliberately rebuild the box (`.be-choice__input`) or
  hide the control (`.be-switch__input`, `.be-list__disclosure-input`) carry class rules and
  outrank the element selector — unaffected. ⚠️ Consequence to expect: any bare checkbox that
  was invisible **by accident** now shows (e.g. `.ce-field--bool` in the content editor). That
  is the bug surfacing, not a regression. **Not verified live.**

- **NORMALIZE-COLOR-001** — added 2026-08-19 (found by Peter in the snippet form's
  «Darstellung» tab, same day as NORMALIZE-CHOICE-001 and the same family). **A colour picker
  IS its swatch.** The reset takes `appearance`, `background`, `border` and `padding` from
  every control — for a text field that is right, the component class puts them back. A
  `<input type="color">` has nothing else: stripped, it is a box that paints nothing, and the
  chosen colour can only be guessed. Now: `appearance: auto`, the member's measurements
  (2.75 × 2rem) so the same choice is the same size on both surfaces, and the swatch shadow
  parts carry the padding (`::-webkit-color-swatch-wrapper`, `::-webkit-color-swatch`,
  `::-moz-color-swatch` — they are named differently per engine, so all of them). ⚠️ **The
  border is not decoration**: without it a light choice — white on `--be-surface` — disappears
  completely. **Not verified live.**

- **BE-INPUT-001** — added 2026-08-19, same finding. **`.be-input` is the standalone control**
  — a field that stands outside a form layout (a filter in a header slot, a select in a rail,
  a search box in a toolbar). It did not exist: `.be-form__field input|select` styles a control
  by its POSITION in the modal grid, and axo3's `tree.hc2` had been writing `class="be-input"`
  for a week against no rule at all. ⚠️ **A class name that matches no rule fails silently** —
  combined with NORMALIZE-CHOICE-001 the selects rendered as bare text on the band, with no
  border and no caret, and nothing said so. `.be-input` now carries border, radius, padding,
  focus ring, placeholder colour and a `--sm` modifier; `select.be-input` draws its own caret
  as a background image so it looks the same in every browser. ⚠️ The caret is **one mid
  grey**, not a per-theme pair: a `background-image` data-URI cannot read `currentColor`, so a
  token-coloured caret would mean twelve definitions (six palettes × two themes) that drift
  apart at the first new palette. Override `--be-select-caret` where a different one is
  wanted. The option list itself is drawn by the operating system — only its ground and text
  can be named, and only where the browser honours it. **Not verified live.**

- **LIST-DROP-STAGES-001** — added 2026-08-09. **One drop stage does not reach a phone.** Found
  live on the backup pilot: below ~495px the page pushed open instead of the list giving way.
  The arithmetic — `--be-list-cols-sm` `minmax(10rem,1fr) 9rem 6rem` = 400px, the `⋮` slot
  25.6px, `.be-list`'s 2rem side padding 64px — is a hard floor of ~490px, because `minmax`
  and fixed tracks stop shrinking at their minimum. Three changes, all in the component:
  (a) a **second stage at 28rem** dropping `data-priority="2"` with an optional
  `--be-list-cols-xs` track list (stage 1 at 40rem now drops priority **3** — highest number
  goes first, as the plan always specified; a list that declares no `-xs` simply keeps the
  stage-1 tracks);
  (b) `.be-list` padding drops to 1rem inside the 40rem query — 2rem per side is a quarter of a
  phone's width;
  (c) `overflow-x: auto` on `.be-list__frame` as the net UNDER the stages, so a list that
  declares too many fixed tracks or forgets a stage scrolls itself instead of pushing the page
  open. No vertical scrollbar comes with it — the frame has no height of its own.
  Backup now floors at ~300px (name + date). **When migrating a screen in step 3: two stages,
  and check the arithmetic against 320px, not against your monitor.** **Not verified live.**

- **TOPBAR-SEARCH-001** — added 2026-08-09. Below 600px the topbar search collapses to its
  magnifier (`__search-text` + `__search-key` hidden, button 30×30). The topbar is one row shared
  by burger, search and the right cluster, and the search is the only element whose content is
  expendable — the icon and `aria-label` still carry it. A `@media`, not a container query: the
  topbar spans the viewport and sits in no resizable pane.
  **Two open points found while doing this, neither fixed:**
  (a) The button is **not wired to anything** — no handler in `shell.js` or `core.js`, no command
  palette exists. It is a placeholder that looks like a control.
  (b) The `⌘K` badge shows the **macOS** command glyph on a Windows installation, and promises a
  shortcut nothing implements. Either drop the badge until the palette exists, or make it
  platform-aware — which needs JS and therefore a written reason (conventions rule 7).

- **SPLIT-NARROW-001** — added 2026-08-09. **The narrow-screen behaviour of a workspace is a
  contract of the primitive, not a per-screen decision.** Every workspace has the same three
  roles — orientation, list, detail — so a screen names its panes and inherits the rest:
  `--nav` (overlay from the LEFT below 40rem), `--grow` (the list, always visible),
  `--detail` (overlay from the RIGHT below 60rem). Below 40rem one surface remains and both
  neighbours are one tap away. The thresholds differ on purpose: a detail pane is the wider of
  the two and has to yield first. Orders and accounting get this without writing CSS.
  Two rules that are not optional:
  (a) **Every overlay brings its own trigger INSIDE the split** (`.z77-split__opener`, or any
  element with `data-z77-split-open`). A trigger in the host's chrome — the shell header band —
  cannot work: a container query cannot reach outside its container, and a frontend or member
  host has no band at all. That is precisely how the retired shell column 3 failed.
  (b) **The primitive owns the opener's `display`**, hence the two-class selector
  `.z77-split .z77-split__opener` — a host's button class carries a `display` of its own and
  would otherwise leave the trigger on screen permanently.
  Mechanics: one attribute `data-z77-split-overlay="nav|detail"` on the root (written by
  `split.js`, never by markup), so opening one closes the other. `data-z77-split-close` also
  belongs on anything that COMPLETES the overlay's job — picking a folder in the nav overlay
  shuts it. `split.js` does no width check at all: the attribute is set at any width and the
  CSS only acts on it inside its container query, so the threshold has exactly one owner.
  Replaces the old boolean `data-z77-split-detail`, and drops the unused selector value of
  `data-z77-split-open` (no markup ever used it). **Not verified live.**
  Widened 2026-08-12: **`data-z77-split-root` may sit on an ANCESTOR of `.z77-split`.** The root
  marks where the width variable and the overlay attribute are written, `.z77-split` marks where
  the panes are; usually one element, and both existing hosts keep it that way. The member shell
  needs them apart — it is ONE grid whose header cells must be exactly as wide as the pane below,
  so its handle writes the variable the GRID reads, which only works if the write lands on the
  body. The overlay rules therefore select the pane as a DESCENDANT of the attribute
  (`[data-z77-split-overlay="detail"] .z77-split__pane--detail`) instead of on the same element;
  a host that puts both on `.z77-split` matches that just as well. Verified live in the member
  area 2026-08-12.

- **SHELL-FILL-001** — added 2026-08-09. **A screen that fills the column says so itself; the shell
  does not decide it.** `.be-shell-col--2` is a flex column with a definite height and the action
  template is its direct child — so by default a screen has CONTENT height and the column is the
  scroll region. That is right for a form or a document, and wrong for a workspace: a pane's
  `overflow: auto` only ever fires if its ancestors hand down a definite height, otherwise the panes
  grow and the column scrolls (found live in the DMS Drive, see [`css-dms.md`](css-dms.md)
  DMS-FILL-001). `.z77-split` therefore carries `flex: 1 1 auto` **and** `height: 100%` — both,
  because a flex-column host and a plain block host with a definite height resolve differently — so
  a workspace placed straight into the column fills it without the screen doing anything. Removed in
  the same pass: `.be-shell-col__body` (`flex: 1; min-height: 0; overflow: auto; padding`), a class
  **no template ever rendered**. It would have solved this from the shell side, which is the wrong
  side — the screen cannot reach it. Do not revive it.

- **SPLIT-SHARED-001** — added 2026-08-08. The backend no longer owns pane resizing. New shared
  primitive `.z77-split` (`packages/kernel/shared/res/scss/components/_split.scss` +
  `res/assets/js/split.js`, both host-neutral per ADR-018 R5–R7): n panes, a handle on every
  divider, per-pane scrolling, per-pane fullscreen via the existing `[data-…-full]` attribute
  pattern, and a detail pane that becomes an **overlay** below a threshold instead of vanishing.
  The threshold is a **container query, not `@media`** — panes are drag-resizable, so a pane can be
  narrow inside a wide window and a viewport query would fire at exactly the wrong time.
  The SCSS is a partial `@use`d into `base.css` (relative cross-package path), NOT a second
  stylesheet — the backend keeps one sheet. Tokens bind in `components/_split-host.scss`.
  **`shell.js` lost its own 25-line resize block and became the primitive's first consumer**
  (`--shell-c1`, with an explicit `data-z77-split-dir` because the resizer is a grid overlay, not
  a flex sibling); its stale `shell.min.js` was rewritten to match — it still carried the old
  two-column logic and would have been served in production.
  **Pane size is a `flex-basis`, never a `width`** (corrected 2026-08-09): the positional rules
  need `.z77-split > .z77-split__pane:nth-child(n)` (0,3,0), which outranks every single-class
  modifier (0,1,0) — so `--detail` got the positional 22rem instead of its overlay
  `min(28rem, 88%)`, and `--grow` survived only because `flex-basis: 0` happens to beat a losing
  `width`. The positional rules now set nothing but `--z77-split-w`, which the ONE `flex`
  declaration on `.z77-split__pane` reads; a custom property cannot collide with a modifier, so
  `--grow` / `--detail` win on source order. **Do not reintroduce a `width` here** — any new
  modifier would silently lose to the position again.
  **The drag start is MEASURED off the pane, not parsed off the token** (corrected 2026-08-09):
  `getComputedStyle` does not resolve a custom property, so the DMS's markup-declared
  `--z77-split-1: 16rem` came back as the string `16rem` and `parseInt` made it 16 — the first
  drag jumped straight to the `min` bound and only the second one, working off the `…px` the
  drag itself had written, behaved. `split.js` now trusts a `…px` value and otherwise measures
  the target pane's box, so the variable may be declared in any unit and may also be left unset
  (the stylesheet default then applies and is measured like any other). The target pane follows
  the same neighbour rule as the drag direction, so the two cannot disagree. **Not verified live.**

- **SHELL-COL3-REMOVED-001** — 2026-08-08. Shell column 3 (preview) is **gone**: skeleton column +
  resizer, `data-col3`, the `be-shell-col3in` keyframes, `.be-shell-col--3`, the mobile right
  drawer, the `preview` body section in `layoutConfig`, and `partials/shell/preview.tpl.php`.
  It had never been in use — `data-col3` was hard-coded `"off"`, no `*.hc3.tpl.php` ever existed,
  and the mobile right drawer had **no trigger anywhere in the repo** (the only
  `[data-shell-drawer]` is the burger with `="l"`), so its close button closed something that
  could not open. Detail beside a list is the workspace's job now, so there is one mechanism for
  it instead of two. `.be-shell-preview__empty` survives renamed as **`.be-pane-empty`** (a pane
  waiting for a selection is still a real state — the DMS preview pane is its first consumer).
  **Consequence for the older SHELL-REBUILD notes below: `hc3` is retired.** The auto-loader still
  iterates `hc1|hc2|hc3`, but there is no column 3 to render it into — do not build a `*.hc3`
  partial expecting it to appear.

- **HEADER-BAND-ALWAYS-001** — resolved 2026-08-08. **Was:** `html-shell-skeleton.tpl.php` rendered
  the header band only when a slot was filled (`$hasHead = !empty($hc1) || !empty($hc2)`). The four
  screens without slots — `backup`, `job`, `import`, `member-accounts` — therefore got NO band, so
  their content started 46px higher than on every other screen: a visible jump when switching
  screens, and the reason those four read as "different" (inventory finding B6, see
  [`../03-development/arbeitsflaeche-bauplan.md`](../03-development/arbeitsflaeche-bauplan.md)).
  **Fix:** the band renders unconditionally — it belongs to the shell, not to the screen. Same pass
  filled the four empty bands with what each screen actually has, instead of inventing add buttons:
  `backup` → `.be-shell-add` picker with its three kinds (per the existing several-add-kinds rule;
  the db item stays visible but `disabled` with the reason in `title`, and the three per-section
  "Jetzt sichern" buttons were removed); `job` → runner heartbeat as `.be-shell-status` (the failure
  block stays in the body — it carries the cron line and would never fit 46px); `import` → the two
  GLOBAL plan actions, rendered only while a plan exists (per-record decisions stay per row);
  `member-accounts` → queue length ("N Konten warten auf Freischaltung"). New in this pass:
  `.be-shell-status` (`_shell.scss`), `.be-list__empty` + `.be-list__section-hint` (`_list.scss`,
  replacing the copy-pasted inline styles), `.be-shell-add__panel form { display: contents }` so a
  POSTing picker item needs no inline style. `php -l` clean on all eight touched templates, base.css
  rebuilt + deployed. **Not verified live** — wants a click-through of the four screens.

- **LIST-ACTIONS-HUB-001** — added 2026-07-04. Supersedes LIST-ACTIONS-SWITCH-001's inline row-action model. The per-row edit/trash cluster (`.be-tree__actions`) was replaced by a single **`⋮` row-menu** (`.be-tree__menu`) that fetches a per-row `actions` endpoint rendering a shared **`.be-actions`** hub modal (edit + delete as `.be-actions__item` buttons). Opt-in via `.be-tree--hub` on the `.be-tree` container: an EXPLICIT 6-column grid `[toggle | active-switch | ⋮ menu | name | url | route]` in `components/_list.scss`, so every row aligns even when a slot is empty (views without an active toggle: navigation-group, backend-user, translation — the switch column stays reserved). Modeled on the DMS Drive hub (DriveController `actionsAction`, see [`css-dms.md`](css-dms.md)). Rolled across all 7 backend list screens: content, navigation, navigation-group, navigation-alias, metadata, translation, backend-user — each now uses `.be-tree--hub` + `.be-tree__menu` + an `actions.tpl.php` partial + a controller `actionsAction`. Auth: the six backend `actionsAction`s (and the inline `toggle-active` switch endpoints) are NOT listed per-action in `backendConfig.inc.php` — they resolve to the controller-level `AuthRole::ADMIN` via `AuthService::resolveRoleForCurrentController` (`$actionRole ?? $controllerRole`); only the Drive lists `actionsAction` explicitly. **Value-column caveat:** the hub `.be-tree__url` is `nowrap` + ellipsis, so translation's multi-language value summary truncates at narrow widths (the full text is in the edit modal). Orphaned by this change: the `.be-tree__actions` rule + its flex `order` overrides (from LIST-ACTIONS-SWITCH-001) in `_list.scss` — no template references it anymore; removal is a pending cleanup (see below). **Visual acceptance across the 7 lists is still open** — the `⋮` hub, the reserved-but-empty switch column on no-switch views (group / login / translation), and the value-column ellipsis want a live pass.

- **GUEST-SKELETON-001** — added 2026-07-04. GUEST full-page screens (login, setup) got their own chrome-less skeleton (`html-guest-skeleton.tpl.php`, `<body class="be-guest">`) instead of rendering inside the authenticated 3-column shell. New self-contained component `components/_guest.scss`: `.be-guest` (full-height flex column, own bg/color/font — does NOT depend on the media-gated `layout/*.scss`) + `.be-guest__main` (flex column so the `flex:1` `.login`/setup card fills + centers). Added to `base.scss` `@use`. Same pass fixed a real login bug: the `.login .form__control` / `.login .btn--primary` overrides in `_login.scss` were **dead selectors** left over from CSS-LIST-CONSOLIDATION-001 (`.form`→`.be-form`, `.btn`→`.be-btn`) — the inputs sat on `.login__box` (`--be-surface`) with the base control's own `--be-surface` bg, so they visually merged into the card. Fixed: `.login .be-form__control { background: var(--be-bg) }` (recess) + `.login .be-form__label { color: var(--be-text) }` (on-card readability); the redundant button + focus-ring overrides were dropped (base `.be-btn--primary` + `.be-form__control:focus` are already palette/theme-aware — the old button override even set `color:var(--be-surface)`, which would break dark mode). Mechanism/controller side: see [`backend.md`](backend.md) LAYOUT-B001.

- **PALETTE-WERKBANK-001** — 2026-07-03. The default `werkbank` palette was recast from warm cream + moss-green to a **technical indigo**: accent `#4f46e5` (light) / `#9698f5` (dark). All werkbank neutrals (`bg`/`surface`/`rail`/`rail-text`/`text`/`muted`/`line`/`accent-soft`) were harmonized into the indigo hue family (~244°) so the palette reads as one system; `good` (`#1f9d57`/`#4ec98a`) and `warn` (`#c0851c`/`#dbaa4e`) stay as separate semantics; `danger`/`on-accent` unchanged (palette-independent). ONLY werkbank changed — the other 5 palettes (`citrus`/`coral`/`lagune`/`beere`/`sonne`) are untouched. The DMS fragment was pulled to match (see [`css-dms.md`](css-dms.md) DMS-PALETTE-001). **Deferred to a later semantics/contrast pass:** in dark mode a light accent + white `--be-on-accent` is low-contrast on primary fills — this affects ALL palettes, not just werkbank (citrus/lagune dark accents are even lighter).

- **LIST-ACTIONS-SWITCH-001** — added 2026-06-14. List-/tree-row layout reworked in `components/_list.scss`. The per-row action cluster (`.be-tree__actions`) is now **permanently visible and moved to the row start** — right after the toggle, ahead of the name (was `margin-left:auto` at the right + hover-only `opacity:0`). Positioning is done via flex `order` (`.be-tree__toggle { order:-2 }`, `.be-tree__actions { order:-1 }`) so the DOM/tab order stays unchanged and the three list templates need no markup juggling; `.be-tree__url` keeps `flex:1` and fills the middle, pushing route + the inline switch to the right edge. `.be-tree__switch` is an inline `.be-switch--sm` active toggle wired via `data-fetch-toggle` (see [`fetch.md`](fetch.md)); it stays the last row child (right side). The inactive marker no longer dims the whole row (row-level `opacity` cascaded onto the now-always-visible controls and can't be overridden per child) — it dims only the label spans (`.be-tree__name/__url/__route`), keeping actions + switch fully usable; the old hover `opacity:1` overrides were removed. New `.be-modal__switches` in `components/_modal.scss`: a top-of-body flex row that collects status switches in the edit modals (the `active` switch moved there as the first body element, ahead of the form fields). Section-header actions (`.be-list__section-actions`) keep their hover-reveal behaviour — out of scope.

- **ICON-SPRITE-001** — added 2026-06-11. Backend icons were inline `<svg>` duplicated across 11 templates (48 occurrences), several Content lists even carrying a local `$svg = [...]` array of identical glyphs. Replaced by a single SVG sprite: `res/view/templates/partials/icon-sprite.tpl.php` defines every icon once as `<symbol id="icon-NAME" viewBox="0 0 24 24">` (all Lucide 24×24), loaded once per page via the layout `iconSprite` body section ($iconSprite in the skeleton). Templates now reference `<svg class="be-icon"><use href="#icon-NAME"/></svg>`. Presentation (no fill, `stroke: currentColor`, uniform `stroke-width:2`, round caps) lives once in `components/_icon.scss` (`.be-icon`); symbols carry geometry only, size via the `<svg>` width/height. Naming convention documented in [`../01-handbook/conventions.md`](../01-handbook/conventions.md) (`icon-{name}`, semantic, kebab-case). Consolidated duplicate glyphs to one per concept: `edit` = square-pen, `trash` = trash-2 (the dominant list variant — the header's two slightly different glyphs now match). Stroke widths unified to 2 (some were 1.6–2.5). To add an icon: add one `<symbol>` to the sprite — nothing else.

- **FONT-CAP-001** — added 2026-06-11. The appearance font-size slider (`--be-font-scale`, 1.0–1.4×) scales every `--font-size-*` token, which blew up dense/technical content (e.g. the service-panel INFO mono paths) and button labels at high values. Added an opt-in cap: `tokens/_typography.scss` now defines the rem base values once in a `font-size-vars($scale)` mixin (used for the global `:root` scale), plus `--be-font-scale-cap` (currently `1.0` = frozen at base size) and a `font-size-capped` mixin (`min(--be-font-scale, --be-font-scale-cap)`). Two consumers: the `.be-font-cap` utility (`base/_utilities.scss`, applied in markup — currently the service-panel INFO `<dl>`) and `.be-btn` (capped by default, covers `.be-btn--sm`). **To cap another region:** add `class="be-font-cap"` — capped regions follow the slider only up to `--be-font-scale-cap`, then hold (at 1.0 they stay at base size, ignoring the slider). The cap is configured once via `--be-font-scale-cap`. Also in this pass: `.backend-service-panel` got `max-height: calc(100vh - 72px)` + `overflow: hidden auto` (expanded sections were unreachable, no scroll), and slim backend scrollbars were added globally in `base/_elements.scss` (`scrollbar-width: thin` + 8px `::-webkit-scrollbar`, thumb `--be-line`).

- **CSS-BACKEND-TOKENS-001** — resolved 2026-06-11. Hardcoded color values in components replaced by `--be-*` tokens. (1) Focus-rings were hardcoded to the werkbank accent `rgba(63, 90, 58, …)` / danger `rgba(220, 38, 38, …)` — they stayed green/red regardless of palette. Now `color-mix(in srgb, var(--be-accent|--be-danger) X%, transparent)` in `_forms.scss` (5×) and `_switch.scss` (1×), so they follow the active palette. (2) `_alerts.scss` `.be-modal__alert--error` rgba danger → `color-mix(var(--be-danger) …)`. (3) Service-panel logout `#a94434` unified to `var(--be-danger)` (single danger tone backend-wide). (4) New token `--be-on-accent: #ffffff` (`:root` only, palette/theme-independent) for the white switch-thumb + choice-checkmark on top of `--be-accent`. (5) Dead `var(--token, #fallback)` fallbacks removed in `_forms.scss` / `_switch.scss` (tokens are always loaded; two fallbacks were even stale). **Deliberately NOT changed:** the component-tuned inline shadows in `_modal.scss` (`0 20px 60px`), `_flash-messages.scss`, `_popup-messages.scss`, `_switch.scss` thumb — none match the 4-step `--shadow-*` scale and snapping them would visibly degrade the design (e.g. the heavy double-layer `--shadow-sm` on a 16px thumb). Do not "tokenize" these.

- **CSS-LIST-CONSOLIDATION-001** — resolved 2026-06-09. `res/assets/css/navigation/list.css` was a hand-written, non-SCSS stylesheet loaded separately by 8 controllers (`addCss('navigation/list')`). It carried the de-facto backend button (`.be-btn`, 89 uses) alongside a generic list/tree under the misleading `.be-nav-*` prefix, plus a second unused button system (`.btn`, frontend-style tokens) and dead code (`.be-tag`, `.be-children-table`). Consolidated: `.be-btn` + `.be-icon-btn` are now the single button system in `components/_buttons.scss` (old `.btn` removed, 4 login/setup uses migrated); the list/tree moved to `components/_list.scss` renamed to `.be-list` / `.be-tree` / `.be-tabs` (palette-aware `--be-*` tokens, werkbank-only `rgba()` replaced by `color-mix(--be-accent …)`). Both compile into `base.css` (always loaded), so all `addCss('navigation/list')` calls were removed and `list.css` deleted. The three list `*.js` + `*.min.js` were renamed to match. Spacing/font rem values were kept verbatim to preserve the exact list density. Visual acceptance passed 2026-06-11 (drag&drop, tree-toggle, filter/tabs, all lists + modals, buttons across palettes + dark mode, login/setup buttons). See [`../03-development/css-backend-list-review.md`](../03-development/css-backend-list-review.md).

- **APPEARANCE-PIPELINE-001** — resolved 2026-05-27. Per-User-CSS-Generierung aus `BackendAbstractController::postExecute()` entfernt; `user-preferences.css.tpl.php` gelöscht. Palette/Theme-Wechsel jetzt rein über `data-be-palette` / `data-be-theme` Attribute am `<html>` (Server-rendered initial, `appearance.js` setzt sie bei Klick um — sofortige CSS-Selektor-Aktivierung). Token-Werte einzig in `_colors.scss`. Entfernt: inline `<script>` im Skeleton zum localStorage-Sync, `TOKENS`-Hash + `_apply()` in `appearance.js` (187 → 77 Zeilen), `postExecute()`-Hook im Backend. `createCss()`-Mechanismus selbst bleibt für andere data-driven CSS verfügbar (z.B. Slider).

- **CSS-CHOICE-001** — resolved 2026-05-30. Selected radios/checkboxes were only weakly indicated (bare native control). Added the shared `.be-choice` component in `_forms.scss`: an `appearance:none` box with a `::after` checkmark on `:checked`, type-agnostic so radio and checkbox look identical; optional `.be-choice--filled` tints the whole row (`color-mix` on `--be-accent`). The `NavigationController/edit.tpl.php` group picker migrated from the old `.be-form__tag-label` chip to `.be-choice`.

## pending

- **LIST-ANATOMY-001 — the backend has no list component, it has a navigation-tree row.**
  `.be-tree--hub` is a fixed 6-column grid `[toggle | active switch | ⋮ | name | url | route]`;
  exactly 1 of the 12 list screens is actually a tree. Inventory (all 12 read in source,
  2026-08-08): rows carry **3–10 real fields** into 3 text slots, so 8 screens glue several
  values into one `·`-separated string that is `nowrap` + ellipsis — with no column headers
  anywhere, truncated fields are unrecoverable. Columns 1/2 are empty-but-reserved on 11/8 of 12
  screens (10 templates render an empty `<span class="be-tree__toggle">` purely to fill the
  grid). 4 screens need row actions the ⋮ cannot hold and hack `style="grid-column:6"` against
  the grid, twice with an identical explanatory comment. Two section-head variants exist and one
  (`.be-list__section__head`, used by translation/backup/job/import) has **zero CSS** — its look
  comes entirely from inline styles, which is why those four screens differ from the other four.
  Sorting, pagination and column headers do not exist at all; `_tables.scss` and
  `_pagination.scss` are fully defined and used by nothing. Blocking for the planned order-
  processing and accounting modules. Full inventory, findings B1–B11 and the derived
  requirements: [`../03-development/arbeitsflaeche-bauplan.md`](../03-development/arbeitsflaeche-bauplan.md).
  Depends on the ADR-018 revision 2026-08-08 (shared structural layout primitives) because the
  content layer must also render in a frontend host (DMS → customer invoices).

- **Remove orphaned `.be-tree__actions` (LIST-ACTIONS-HUB-001).** After the `⋮`-hub rollout no template references `.be-tree__actions` anymore (repo-wide grep: only `components/_list.scss` line ~164). Dead: the `.be-tree__actions` rule + the flex `order` overrides added by LIST-ACTIONS-SWITCH-001 (`.be-tree__toggle { order:-2 }`, `.be-tree__actions { order:-1 }`). The base `.be-tree__row` (padding/border/hover) stays — only the flex layout is superseded by `.be-tree--hub`'s grid. Removal needs a `base.css` rebuild + a quick visual pass across the 7 lists before it lands.

- **SHELL-REBUILD Phase 1 — DONE (2026-07-03).** The backend chrome is being rebuilt to the
  approved 3-column shell (prototype: artifact; topbar + column 1 orientation | 2 content | 3
  preview). **Phase 1 (structure) is built SAFELY IN PARALLEL** — the legacy `html-default-skeleton`
  + `partials/header` + `_topbar.scss`/`_desktop.scss` are UNTOUCHED; revert = point `layoutConfig`
  `documentTpl` back to `html-default-skeleton` (one line). New: `html-shell-skeleton.tpl.php`
  (grid), `partials/shell/topbar.tpl.php` (module switcher from nav top-groups + `ModuleIcons`
  config-map, search, env/bell/avatar reused; version moved into the service-panel footer),
  `partials/shell/preview.tpl.php` (column 3, optional — `data-col3="off"` default), the
  `components/_shell.scss` component (`.be-shell*`, self-contained grid + own responsive rules,
  independent of the legacy `layout/*.scss`), and `res/assets/js/shell.js` (drag-resize columns 1+3,
  mobile sandwich/preview drawers; the panels run on the shared `panel-toggle.js`). `layoutConfig`
  switched `documentTpl` + added the `shellTopbar`/`preview` body sections + registered `shell` js.
  New sprite icons: `x`, `grid`, `globe`, `database`, `hard-drive`, `eye`. Verified: `php -l` clean,
  SCSS compiled (`.be-shell*` in base.css), css/js deployed to `skeleton/public`. **To see it:
  clear cache + hard refresh.** **Phase-1 rough edges (by design):** column 2 still hosts each
  action template WITH its own inline `.backend-content-header` (the strict aligned header BAND
  across all columns is Phase 2 — needs a header-slot mechanism + per-template migration); column 3
  is off until a controller opts in (Phase 3); the GUEST login/setup pages render in the shell with
  an empty topbar/column 1 (tied to LAYOUT-B001 — they want their own skeleton). `shell.js` has no
  `.min.js` yet (dev/DEBUG serves the non-min).
  **Shell look refined (2026-07-03, from the prototype):** (a) **dark left band** — column 1 + the
  topbar module zone (`.be-shell-topbar__mod`, `height:100%`) form a "dark island": a LIGHT-MODE-only,
  PER-PALETTE local `--be-*` token override on those wrappers paints them a very dark variant of each
  palette's main colour (indigo/green/coral/turquoise/berry/amber), so the subnav tree + module
  switcher + header partial render dark-appropriate with no component CSS; `.be-shell-col--1`/`__mod`
  background is `var(--be-nav, --be-surface)` so DARK mode drops the override and the band blends into
  the normal dark UI. The values live in `_shell.scss` (component-scoped, `.be[data-be-palette=…]:not([data-be-theme="dark"])`);
  a 1px `box-shadow` on `__mod` hides the light topbar border under the band. (b) **module panel icon**
  (`.be-shell-mod__apps`) is now borderless/transparent with a 30px glyph (hover/open → accent).
  Open: fold this dark-band token set into the palette workbench + decide fixed-vs-per-palette for the
  real token file.
  **Phase 2 STARTED — header slot mechanism, piloted on `content/content/list` (2026-07-03):** the
  shell's header slots (hc1 over column 1, hc2 over column 2) are **controller/action partials**.
  Mechanism: an action registers a partial into a named body section via
  `LayoutManager::addPartials($file, $path, $ns, 'hc1'|'hc2')`; `HtmlView::renderPartials()` renders
  EVERY section with the same action context, so the partial gets the action's vars ($editLanguage …);
  each section surfaces as `$hc1` / `$hc2`. (**Both details below are superseded** — the slots are no
  longer sticky children of their column but one grid row of their own, `.be-shell-band__slot`, and the
  band renders unconditionally: see SHELL-BAND-ROW-001 and HEADER-BAND-ALWAYS-001 above.)
  As originally built: a sticky slot at the top of
  its column (`.be-shell-col__head--sticky`). **Aligned band:** the skeleton rendered BOTH slots
  whenever EITHER was set (`$hasHead`), both used `.be-shell-col__head` (min-height 46px) → same height,
  so the band lines up across the columns; per-column sticky bg (hc1 = `--be-nav` dark band, hc2 =
  `--be-bg`); the redundant `.backend-subnav__header` (section title) is hidden in the shell (the
  topbar module switcher shows it). As built for `content/content/list`: `hc1.tpl.php` = the add action
  («+ Inhalt», dark left slot per the prototype), `hc2.tpl.php` = the editing-language switcher;
  `ContentController::listAction` adds both; the inline `.backend-content-header` + lang switch were
  REMOVED from `listAction.tpl.php`.
  **Generalized — convention auto-loader + all list views migrated (2026-07-03):** the per-action
  `addPartials` boilerplate is GONE. `BackendAbstractController::html()` → `loadHeaderSlots()`
  auto-loads convention partials for the CURRENT controller/action into hc1/hc2/hc3 IF present:
  `{Group}/{Controller}/{action}.hc1|hc2|hc3.tpl.php` (dir = the namespace segment after
  `Ui\Controllers`; action = the method minus `Action`; existence via
  `FileFinder::getFirstTplMatch(throwError:false)`). A view just DROPS IN the files. Migrated (inline
  `.backend-content-header` removed from each `listAction.tpl.php`, complex sub-structures kept in the
  body): **content** (`list.hc1` add / `list.hc2` lang switch), **navigation** (`list.hc1` add /
  `list.hc2` live filter + print + aliases; group tabs stay in body), **metadata** (`list.hc2` lang
  switch, no add; env tabs stay in body), **users** (`list.hc1` add). **translation** migrated too
  (2026-07-04): two add kinds (UI-Texte / Routen-Slugs) → `list.hc1` is an add-PICKER panel («＋ Eintrag»
  opens a small dropdown to choose Text / Slug) built on the shared panel-toggle contract; `hc2` is left
  free for other controls (e.g. a future select-all). New reusable component `.be-shell-add` /
  `__panel` / `__item` in `_shell.scss`. The section heads keep only their titles. `php -l` clean; SCSS
  rebuilt this
  round (templates + controllers live via the vendor symlink). Verify live (cache clear + hard
  refresh) across all five areas. Next: hc3 (column-3 slot) when a view needs the preview column;
  optional shared partials/helpers for the repeated «+ add» button + language switcher.

  **Drive (DMS) migrated to the shell header band (2026-07-03):** the DMS Drive is special — a
  self-contained `.dms-drive` fragment with its OWN toolbar (breadcrumb PANE + upload/new-folder/trash),
  wired by `module-dms/res/assets/js/documents/drive.js`. The toolbar is now split into the shell header
  band like the other views: **hc1** = «Hochladen» (dark left slot, `.be-btn--primary` +
  `data-drive-upload`), **hc2** = the folder PATH left (the DMS `_breadcrumb` partial, unchanged) + «Neuer
  Ordner»/«Papierkorb» as `.be-icon-btn` icons right. The two partials live in **module-backend**
  (`Documents/DriveController/list.hc{1,2}.tpl.php`) so `loadHeaderSlots()` auto-loads them for
  `Z77\Module\Backend\Ui\Controllers\Documents\DriveController` (dir `Documents/DriveController`, action
  `list`); the fragment (`module-dms/.../listAction.tpl.php`) drops its `.dms-drive__toolbar` and is now a
  pure 3-pane grid (`_drive.scss`: toolbar row removed). Key mechanics: (a) the breadcrumb KEEPS its
  `.dms-drive__breadcrumb` class + server-built `data-*-url`s, so `DriveControllerTrait::panes` still
  refreshes it in place (`replace-html` = `outerHTML`, document-wide target) after every navigation — it
  just lives in hc2 now; hc2 wraps it in `.dms` to supply the `--dms-*` tokens. (b) drive.js's scope
  guards changed from `.closest('.dms-drive')` to `.closest('[data-drive-scope]')`, and the marker sits on
  BOTH the fragment AND the header slots (hc1 button, hc2 `.be-drive-head` wrapper), because the Drive's
  interactive surface now spans two DOM subtrees. New backend sprite icons: `upload`, `folder-plus`. New
  CSS: `.be-drive-head` (hc2 flex: path grows, icons right) in `_shell.scss`. `php -l` clean; dms.css +
  base.css + drive.js rebuilt & deployed to `skeleton/public`. **Verify live:** upload / new-folder /
  trash open their modals, folder navigation refreshes the breadcrumb path in the header, crumb links +
  folder edit/move/delete (in-crumb, when a folder is selected) still work — all from the header band.
  Next: hc3 (column-3 slot) when a view needs the preview column; optional shared partials/helpers for
  the repeated «+ add» button + language switcher.

  **Migration ABGESCHLOSSEN (2026-07-04):** the last two views on the legacy content-header —
  `content/navigation-group/list` + `content/navigation-alias/list` — were migrated to the header band
  (each a `list.hc1` add button; inline `.backend-content-header` removed). Repo-wide grep now shows
  **0** templates using `.backend-content-header` → every backend view renders through the shell. The
  rebuild is **functionally complete**; only the Legacy-Cleanup remains (delete the now-dead
  `partials/header` + `partials/footer`, split `_topbar.scss`, detach `body.backend`, retire the
  `html-default-skeleton` default). Full inventory + ordered cleanup plan + the pre-deletion
  verification checklist: [`../03-development/shell-rebuild-abschluss-analyse.md`](../03-development/shell-rebuild-abschluss-analyse.md).
  hc3 (preview column) and the dark-band token fold are deferred (dark-band → cleanup step 5).

- **CSS-WRAPPER-TOKENS (C2)** — done 2026-06-22: backend token blocks moved `:root` → `.be`, wrapper class added to `<html class="be">` (alongside the existing `data-be-*` attrs), recompiled. Palette/theme overrides unchanged (equal specificity, source order preserved). Part of [`../03-development/css-wrapper-token-bauplan.md`](../03-development/css-wrapper-token-bauplan.md); awaiting the PAUSE-1 visual test (all palettes × light/dark, modals).
