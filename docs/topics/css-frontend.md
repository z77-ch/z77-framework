# css-frontend

2026-07-12

## entry

1. `packages/module-frontend/res/scss/` — SCSS source files (tokens, base, components, layout)
2. `docs/01-handbook/css-conventions.md` — BEM naming, token rules, component patterns
3. `packages/module-frontend/src/Ui/Config/layoutConfig.inc.php` — which CSS / JS files load and in what order

## file map

SOURCE=/packages/module-frontend/res/scss
SOURCE=/packages/module-frontend/res/assets/css
SOURCE=/packages/module-frontend/res/assets/js
SOURCE=/packages/module-frontend/src/Ui/Config/layoutConfig.inc.php
SOURCE=/packages/module-frontend/res/view/templates/html-default-skeleton.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/partials/header.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/partials/footer.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/Main/IndexController
SOURCE=/docs/01-handbook/css-conventions.md

## mental model

Seven SCSS source files compile to seven CSS output files (`base`, `mobile`, `tablet`, `desktop`, `nav-mobile`, `nav-tablet`, `nav-desktop`) — identical pipeline as the backend module. `base.css` always loads (tokens + base + all components, NO layout rules); layout files load conditionally via media-bound `<link>` attributes set in `layoutConfig.inc.php`. Design tokens use `--fe-*` CSS custom properties, declared on the `.fe` viewArea wrapper (on `<html class="fe">`), not on `:root` (ADR-018) — monochrome (Swiss-Modern aesthetic): hierarchy comes from weight and size, not from colour.

- Watch / build pipeline is uniform across all modules — see [`css-watch.md`](css-watch.md).
- Frontend JS is intentionally minimal: only the shared `core.js` (from `packages/kernel/shared/res/assets/js/`) for flash / message / fetch envelope. The mobile/tablet navigation overlay is **CSS-only** — driven by a hidden `<input type="checkbox" id="fe-nav-toggle">` plus `:has()` / sibling selectors (see [`conventions.md` → JavaScript-Grundsatz](../01-handbook/conventions.md#javascript)). No JS build pipeline.
- BEM prefix is `fe-` for module-specific components (hero, section, topbar, footer). Cross-module components (flash, message) use unprefixed class names — see [`messages.md`](messages.md).

## scss source files

```text
packages/module-frontend/res/scss/
├── tokens/
│   ├── _colors.scss        --fe-* monochrome palette (black/white + grey scale)
│   ├── _typography.scss    Helvetica system stack, scales for body + display
│   ├── _spacing.scss       --fe-space-* + page gutter tokens
│   └── _effects.scss       z-index, transitions, topbar height
├── base/
│   ├── _normalize.scss
│   ├── _elements.scss      html/body/heading/link/hr defaults
│   └── _index.scss         @forward all base files
├── components/
│   ├── _container.scss     .fe-container — max-width wrapper
│   ├── _topbar.scss        .fe-topbar (fixed) + brand + inline nav + hamburger
│   ├── _nav-overlay.scss   .fe-nav-overlay — full-screen mobile/tablet nav
│   ├── _hero.scss          .fe-hero — page intro block
│   ├── _section.scss       .fe-section + .fe-grid + .fe-item + .fe-dl + .fe-prose
│   └── _footer.scss        .fe-footer — dark block with 3-col link list
├── layout/
│   ├── _mobile.scss        < 768px overrides (single column, tight gutter)
│   ├── _tablet.scss        768–1199px overrides (2-col grids, mid gutter)
│   ├── _desktop.scss       ≥ 1200px overrides (4-col grids, full hero scale)
│   ├── _nav-mobile.scss    Hamburger visible, inline nav hidden
│   ├── _nav-tablet.scss    Hamburger visible, inline nav hidden
│   └── _nav-desktop.scss   Inline nav visible, hamburger hidden
├── base.scss               Entry: tokens + base + all components (always loaded)
├── mobile.scss             Entry: layout/mobile only
├── tablet.scss             Entry: layout/tablet only
├── desktop.scss            Entry: layout/desktop only
├── nav-mobile.scss         Entry: layout/nav-mobile only
├── nav-tablet.scss         Entry: layout/nav-tablet only
├── nav-desktop.scss        Entry: layout/nav-desktop only
└── messages.scss           Entry: own feedback channel (see messages.md)
```

## compiled output

```text
packages/module-frontend/res/assets/css/
├── base.css         always loaded
├── mobile.css       media: screen and (max-width: 767px)
├── tablet.css       media: screen and (min-width: 768px) and (max-width: 1199px)
├── desktop.css      media: screen and (min-width: 1200px)
├── nav-mobile.css   media: screen and (max-width: 767px)
├── nav-tablet.css   media: screen and (min-width: 768px) and (max-width: 1199px)
├── nav-desktop.css  media: screen and (min-width: 1200px)
└── messages.css     always loaded
```

## what goes where

| Change | File |
|---|---|
| Colour, font, spacing, transition, z-index | `tokens/_*.scss` |
| Body / html / heading / link defaults | `base/_elements.scss` |
| CSS reset | `base/_normalize.scss` |
| Page max-width wrapper | `components/_container.scss` |
| Topbar (brand + inline nav + hamburger button) | `components/_topbar.scss` |
| Mobile / tablet full-screen nav overlay | `components/_nav-overlay.scss` |
| Page hero (per-page intro block) | `components/_hero.scss` |
| Section header + grid + item + definition list + prose | `components/_section.scss` |
| Footer (dark block, columns, copyright row) | `components/_footer.scss` |
| Mobile-only layout (≤ 767px) | `layout/_mobile.scss` |
| Tablet-only layout (768–1199px) | `layout/_tablet.scss` |
| Desktop-only layout (≥ 1200px) | `layout/_desktop.scss` |
| Mobile nav visibility (hamburger on / inline off) | `layout/_nav-mobile.scss` |
| Tablet nav visibility | `layout/_nav-tablet.scss` |
| Desktop nav visibility (inline on / hamburger off) | `layout/_nav-desktop.scss` |
| Responsive text break visibility (`.z77-br--m` / `.z77-br--d`) | `layout/_mobile.scss` + `_tablet.scss` + `_desktop.scss` |

## frontend tokens (--fe-*)

Defined in `tokens/`, declared on the `.fe` viewArea wrapper (`<html class="fe">`), not `:root` (ADR-018) — so a nested viewArea fragment (e.g. an embedded `.dms` widget) does not collide with these. Monochrome by design — palettes / dark mode are not part of the frontend (backend has its own multi-palette system, see `css-backend.md`).

| Token | Use |
|---|---|
| `--fe-bg` | page background (white) |
| `--fe-bg-dark` | dark blocks (footer, dark sections) |
| `--fe-surface-alt` | alternating section background |
| `--fe-text` | primary text / headlines (near-black) |
| `--fe-text-soft` | secondary body text |
| `--fe-muted` | labels, eyebrows, captions |
| `--fe-line` | hairlines, dividers |
| `--fe-line-strong` | heavy dividers |
| `--fe-accent` | accent — same as `--fe-text` (Swiss: weight, not colour) |
| `--fe-on-dark-text` | text on dark blocks |
| `--fe-topbar-height` | fixed value used to offset body padding |
| `--fe-container-max` | page max-width |

## responsive text breaks (z77-br)

The kernel helper `brText()` (next to `e()`, see
[`../01-handbook/templates.md` → Output escaping](../01-handbook/templates.md#output-escaping))
emits `<br class="z77-br--m">` / `<br class="z77-br--d">` for hand-placed,
viewport-aware line breaks in flow text. The `z77-` prefix is deliberate: the
helper is kernel-level and module-agnostic (like `--z77-scrollbar-width`), so the
class is not owned by the `fe-` frontend namespace.

Visibility is a CSS contract, driven by which media-scoped sheet the rule sits in
— no `@media` queries, consistent with the frontend's `<link>`-scoped split. A
`<br>` with `display: none` produces no break. The binary mobile/desktop intent
maps onto the three breakpoints with **tablet on the desktop side** (≥ 768px):

| Rule | Sheet | Effect |
|---|---|---|
| `.z77-br--d { display: none; }` | `layout/_mobile.scss` | desktop-only break collapses ≤ 767px |
| `.z77-br--m { display: none; }` | `layout/_tablet.scss` | mobile-only break collapses 768–1199px |
| `.z77-br--m { display: none; }` | `layout/_desktop.scss` | mobile-only break collapses ≥ 1200px |

These three rules ship in this starter module as the reference. A consuming
project owns the same contract: it replicates the three lines in its own
media-scoped layout sheets (their breakpoints, their `.z77-br--*` classes). The
project decides whether to use `brText()` at all — nothing breaks until a
template opts in.

## page architecture

Each page lives as an action template under `IndexController/`:

```text
res/view/templates/IndexController/
├── homeAction.tpl.php       /home (default)
├── aboutAction.tpl.php      /about
├── servicesAction.tpl.php   /services
├── contactAction.tpl.php    /contact
├── legalAction.tpl.php      /legal
└── privacyAction.tpl.php    /privacy
```

Standard page composition: `<section class="fe-hero">` + 1–3 `<section class="fe-section">`. Each section wraps content in `<div class="fe-container">` (single source of horizontal gutter).

## JavaScript (vanilla, no build)

Frontend ships only the shared core script — no module-local JS. The nav overlay runs on the CSS-only `#fe-nav-toggle` pattern (see `## nav overlay — CSS-only toggle`).

| File | Purpose |
|---|---|
| shared `core.js` (loaded via layoutConfig) | Flash + popup message channel + fetch envelope dispatch (see [`messages.md`](messages.md), [`fetch.md`](fetch.md)) |

## nav overlay — CSS-only toggle

The mobile/tablet hamburger overlay uses no JavaScript. A hidden checkbox at the top of the header markup drives the state; `:has()` and sibling selectors handle every visible change.

```html
<input type="checkbox" id="fe-nav-toggle" class="fe-nav-toggle" aria-label="Navigation">
<header class="fe-topbar">
    ...
    <label class="fe-topbar__hamburger" for="fe-nav-toggle"> ... </label>
</header>
<div class="fe-nav-overlay"> ... </div>
```

| Mechanism | Driven by |
|---|---|
| Overlay shown / hidden | `.fe-nav-toggle:checked ~ .fe-nav-overlay { display: flex; }` |
| Hamburger morphs to × | `.fe-nav-toggle:checked ~ .fe-topbar .fe-topbar__hamburger-icon ...` |
| Page scroll lock (no shift) | `html:has(.fe-nav-toggle:checked) { overflow: hidden; padding-right: var(--z77-scrollbar-width) }` |
| Close on link click | Native page reload resets the checkbox |
| Keyboard focus ring | `.fe-nav-toggle:focus-visible ~ .fe-topbar .fe-topbar__hamburger { outline: ...; }` |

Trade-off: ESC-to-close is not possible in pure CSS. Acceptable because on mobile / tablet (where the hamburger is visible) there is no ESC key; on a desktop DevTools simulation the toggle button is one click away.

The scroll lock is **no-jump**: it adds `padding-right` equal to the removed scrollbar's width so the page does not shift horizontally when `overflow: hidden` hides the scrollbar. The width comes from `--z77-scrollbar-width`, published by `core.js` (`innerWidth - clientWidth`, measured on load/resize while unlocked, skipped while `overflow:hidden` so the last good value is kept) — a module-agnostic variable, no module-specific markup.

This pattern is the canonical example for the JS-Grundsatz in [`conventions.md`](../01-handbook/conventions.md#javascript) — CSS or server-rendered CSS first, JS only when neither suffices.

## templates that use these styles

```text
packages/module-frontend/res/view/templates/
├── html-default-skeleton.tpl.php    <html class="fe"> (token wrapper) → body wraps header / main / footer / flash / messages
├── partials/header.tpl.php          .fe-topbar + .fe-nav-overlay
├── partials/footer.tpl.php          .fe-footer (3-col link grid + copyright)
└── IndexController/*.tpl.php        6 page actions, each: .fe-hero + .fe-section[s]
```

## rules

- When styling colours, fonts, or spacing → MUST use `--fe-*` token variables; values MUST NOT be hardcoded outside the tokens
- When declaring `--fe-*` tokens → MUST place them on the `.fe` wrapper selector (the four `tokens/_*.scss` files); MUST NOT declare design tokens on `:root` (ADR-018; only `@font-face` / `color-scheme` stay global, of which the frontend has none — system fonts)
- When adding component styles → MUST live in `components/_*.scss`; MUST NOT be added to layout files (layout = breakpoint-bound only)
- When adding layout rules → MUST live in the matching `layout/_*.scss` file, scoped to its media query (no base-style leak)
- When adding a new page → MUST add as an action in `IndexController` + navigation entry in `navigation.json`; MUST NOT create a new controller unless the page needs its own behaviour
- When adding frontend interactivity → MUST remain vanilla IIFE in `res/assets/js/<name>.js` + register in `layoutConfig.inc.php` `javascripts`; MUST NOT introduce a JS build pipeline
- When running build commands → MUST run from framework root (`npm run watch:frontend` / `npm run build:frontend`); MUST follow the watch/build workflow in [`css-watch.md`](css-watch.md)

## see also

- [`css-watch.md`](css-watch.md) — uniform SCSS watch/build workflow + ask-for-watcher convention at session start
- [`css-backend.md`](css-backend.md) — mirror topic for the backend module
- [`stylesheet.md`](stylesheet.md) — how compiled CSS is loaded into pages (asset pipeline, FileFinder, AssetCleaner)
- [`messages.md`](messages.md) — flash + popup message channel (`flash-msg`, `msg-popup`) — shared across modules
- [`navigation.md`](navigation.md) — navigation tags used by the frontend header (`frontend`) and footer (`frontend-meta`)
- [`../01-handbook/css-conventions.md`](../01-handbook/css-conventions.md) — BEM, tokens, component patterns

## known issues

- **CSS-FRONTEND-LEGACY-001** — resolved 2026-05-17. Legacy `packages/module-frontend/src/scss/` deleted. `res/scss/` is the only source.

## pending

- Verify the design end-to-end in a browser across all six pages and three breakpoints before treating this starter as shippable.
- **CSS-WRAPPER-TOKENS (C1)** — done 2026-06-22: frontend tokens moved `:root` → `.fe`, wrapper on `<html class="fe">`, recompiled. Part of [`../03-development/css-wrapper-token-bauplan.md`](../03-development/css-wrapper-token-bauplan.md); awaiting the PAUSE-1 visual test together with the backend (C2).
