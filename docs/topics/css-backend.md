# css-backend

2026-07-03

## entry

1. `packages/module-backend/res/scss/` — SCSS source files (tokens, base, components, layout)
2. `docs/01-handbook/css-conventions.md` — BEM naming, token rules, component patterns
3. `packages/module-backend/src/Ui/Config/layoutConfig.inc.php` — which CSS files are loaded and in what order

## file map

SOURCE=/packages/module-backend/res/scss/
SOURCE=/packages/module-backend/res/assets/css/
SOURCE=/packages/module-backend/src/Ui/Config/layoutConfig.inc.php
SOURCE=/docs/01-handbook/css-conventions.md

## mental model

A **single `base.css`** carries tokens + all components + their responsive rules (`@media` blocks live inside the component partials — the shell and overview are self-contained). It is the only backend stylesheet (`layoutConfig.inc.php` `styleSheets` lists just `base`). The former per-breakpoint layout files (`mobile`/`tablet`/`desktop`/`nav-*`) were retired in the shell cleanup (their dead legacy-grid rules dropped; the live responsive bits moved into `_overview.scss` + `_shell.scss`). Design tokens (`--be-*` + generic `--color-*`/`--space-*`/…) are declared on the `.be` viewArea wrapper (`<html class="be">`), not `:root` (ADR-018); the six palettes × light/dark are layered on top via `[data-be-palette]` / `[data-be-theme]` attribute selectors on the same `<html>` element (same `(0,1,0)` specificity as `.be` → cascade unchanged).

- Backend JS is external assets only, registered in `layoutConfig.inc.php` (`core`, `panel-toggle`, `appearance`, `system/cache`, `shell`) — no inline `<script>` (the legacy `partials/footer.tpl.php` was removed in the shell cleanup). No JS build pipeline.
- Watch / build workflow (incl. the start-of-session ask-for-watcher rule) is uniform across modules — see [`css-watch.md`](css-watch.md).

## scss source files

```text
packages/module-backend/res/scss/
├── tokens/
│   ├── _colors.scss        --be-* backend theme tokens (6 palettes × light/dark)
│   ├── _typography.scss    GT Walsheim (ui/display), mono; --be-font-scale (slider) × font-size-vars mixin; --be-font-scale-cap + font-size-capped mixin
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
│   ├── _noindex-banner.scss .be-noindex-banner — site-wide crawl-block Störer (danger band, top of shell; SEO-NOINDEX-001)
│   ├── _subnav.scss        .backend-subnav + .backend-tree-*
│   ├── _service-panel.scss .backend-service-panel (avatar dropdown)
│   └── _overview.scss      .be-overview + .be-module-card (+ own @media responsive)
└── base.scss               tokens + base + all components — the ONLY stylesheet
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
| Backend list / tree / group tabs (all list views) | `components/_list.scss` (`.be-list` / `.be-tree` / `.be-tabs`) |
| Login page | `components/_login.scss` |
| GUEST full-page wrapper (login/setup, no chrome) | `components/_guest.scss` (`.be-guest`) |
| Shell (3-column grid, header slots, add-picker, columns/drawers, topbar right cluster env/bell/avatar, `body.backend` base, responsive) | `components/_shell.scss` |
| Site-wide crawl-block Störer (`.be-noindex-banner`, white-on-danger band; SEO-NOINDEX-001) | `components/_noindex-banner.scss` |
| Left sidebar (subnav tree) | `components/_subnav.scss` |
| Avatar dropdown panel | `components/_service-panel.scss` |
| Dashboard overview page (+ its responsive) | `components/_overview.scss` |

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
- When adding backend interactivity → MUST remain inline vanilla IIFE in `partials/footer.tpl.php`; MUST NOT introduce a JS build pipeline
- When running build commands → MUST run from framework root (`npm run watch:backend` / `npm run build:backend`)
- When building a radio/checkbox **selection** (select/choose one or many) → MUST use the shared `.be-choice` component (`__input` / `__label`, optional `--filled` for a tinted row); MUST NOT use `.be-switch` for that (the switch is on/off only)
- When filling a shell header slot (`{Group}/{Controller}/{action}.hc1|hc2|hc3.tpl.php`) → MUST keep it to a SINGLE line. `.be-shell-col__head` is a FIXED-height band (`height: 46px`, not `min-height`) so every column's head stays exactly equal (empty or filled) and the band lines up across columns. Content that needs more room MUST go into a dropdown or popup — MUST NOT make the band taller (would break the cross-column alignment). A view with SEVERAL add kinds MUST use the `.be-shell-add` hc1 picker (a «＋ add» button that opens a panel to choose the type, via the panel-toggle contract) rather than stacking multiple add buttons in the band (e.g. translation: Text / Slug). The band scales in fixed px, not `em`/`rem`: it is chrome and matches the font-capped buttons (see FONT-CAP-001) — the font slider scales content, not chrome. If a slot's text grows too large at high font scale, cap it with `.be-font-cap` rather than making the height relative.

## see also

- [`css-watch.md`](css-watch.md) — uniform SCSS watch/build workflow across all modules + ask-for-watcher convention at session start
- [`stylesheet.md`](stylesheet.md) — how compiled CSS is loaded into pages (asset pipeline)
- [`../01-handbook/css-conventions.md`](../01-handbook/css-conventions.md) — BEM, tokens, component patterns

## known issues

- **LIST-ACTIONS-HUB-001** — added 2026-07-04. Supersedes LIST-ACTIONS-SWITCH-001's inline row-action model. The per-row edit/trash cluster (`.be-tree__actions`) was replaced by a single **`⋮` row-menu** (`.be-tree__menu`) that fetches a per-row `actions` endpoint rendering a shared **`.be-actions`** hub modal (edit + delete as `.be-actions__item` buttons). Opt-in via `.be-tree--hub` on the `.be-tree` container: an EXPLICIT 6-column grid `[toggle | active-switch | ⋮ menu | name | url | route]` in `components/_list.scss`, so every row aligns even when a slot is empty (views without an active toggle: navigation-group, login-user, translation — the switch column stays reserved). Modeled on the DMS Drive hub (DriveController `actionsAction`, see [`css-dms.md`](css-dms.md)). Rolled across all 7 backend list screens: content, navigation, navigation-group, navigation-alias, metadata, translation, login-user — each now uses `.be-tree--hub` + `.be-tree__menu` + an `actions.tpl.php` partial + a controller `actionsAction`. Auth: the six backend `actionsAction`s (and the inline `toggle-active` switch endpoints) are NOT listed per-action in `backendConfig.inc.php` — they resolve to the controller-level `AuthRole::ADMIN` via `AuthService::resolveRoleForCurrentController` (`$actionRole ?? $controllerRole`); only the Drive lists `actionsAction` explicitly. **Value-column caveat:** the hub `.be-tree__url` is `nowrap` + ellipsis, so translation's multi-language value summary truncates at narrow widths (the full text is in the edit modal). Orphaned by this change: the `.be-tree__actions` rule + its flex `order` overrides (from LIST-ACTIONS-SWITCH-001) in `_list.scss` — no template references it anymore; removal is a pending cleanup (see below). **Visual acceptance across the 7 lists is still open** — the `⋮` hub, the reserved-but-empty switch column on no-switch views (group / login / translation), and the value-column ellipsis want a live pass.

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
  each section surfaces as `$hc1` / `$hc2`, which the skeleton renders in a sticky slot at the top of
  its column (`.be-shell-col__head--sticky`). **Aligned band:** the skeleton renders BOTH slots
  whenever EITHER is set (`$hasHead`), both use `.be-shell-col__head` (min-height 46px) → same height,
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
