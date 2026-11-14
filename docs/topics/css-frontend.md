# css-frontend

2026-11-14

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
SOURCE=/packages/module-frontend/res/view/templates/partials/sections
SOURCE=/packages/module-frontend/res/view/templates/Main/IndexController
SOURCE=/packages/module-frontend/src/Ui/Controllers/AbstractFrontendController.php
SOURCE=/docs/01-handbook/css-conventions.md

## mental model

Nine SCSS entry files compile to nine CSS files: the eight `layoutConfig.inc.php` lists (`base`, `mobile`, `tablet`, `desktop`, `nav-mobile`, `nav-tablet`, `nav-desktop`, `messages`) plus `admin-overlay`, which `AbstractFrontendController` adds only for a logged-in editor. `base.css` always loads (tokens + base + all components, NO layout rules); the layout files load conditionally via media-bound `<link>` attributes set in `layoutConfig.inc.php` (the backend, by contrast, has ONE `base.css` with `@media` inside — the watch/build commands are the same). Design tokens use `--fe-*` CSS custom properties, declared on the `.fe` viewArea wrapper (on `<html class="fe">`), not on `:root` (ADR-018) — monochrome (Swiss-Modern aesthetic): hierarchy comes from weight and size, not from colour.

- Watch / build pipeline is uniform across all modules — see [`css-watch.md`](css-watch.md).
- Frontend JS is minimal: on every page only the shared `core.js` (from `packages/kernel/shared/res/assets/js/`) for flash / message / fetch envelope; page-bound scripts come per action (see `## JavaScript`). The mobile/tablet navigation overlay is **CSS-only** — driven by a hidden `<input type="checkbox" id="fe-nav-toggle">` plus `:has()` / sibling selectors (see [`conventions.md` → JavaScript](../01-handbook/conventions.md#javascript)). No bundler.
- BEM prefix is `fe-` for module-specific components (hero, section, topbar, footer, form). Cross-module components (flash, message, preview bar) use unprefixed class names and carry their own hard values in the `messages` bundle — see [`messages.md`](messages.md). The DMS image blocks (`.media-image`, `.media-gallery`) keep the DMS renderers' unprefixed names but are styled with `--fe-*` in `base`. The admin overlay (`.z77-admin-overlay`) is isolated with its own `--ao-*` tokens so it looks the same on any site ([`backend.md`](backend.md)).

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
│   ├── _image-text.scss    .fe-image-text — image beside text (section partial imageText)
│   ├── _media.scss         .media-image / .media-gallery — DMS image blocks, default look
│   ├── _cta.scss           .fe-cta + .fe-button (+ --inverse)
│   ├── _public-form.scss   .fe-form — reference look of partials/publicForm (contact form)
│   ├── _footer.scss        .fe-footer — dark block: brand + 2 link columns + copyright row
│   ├── _lottie.scss        lottie-figure pre-upgrade box (lottie.md)
│   ├── _flash-messages.scss   .flash-msg — in messages.css, not base
│   ├── _popup-messages.scss   .msg-popup — in messages.css, not base
│   └── _preview-bar.scss      .preview-bar — content preview notice, in messages.css
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
├── messages.scss           Entry: flash + popup messages + preview bar (see messages.md)
└── admin-overlay.scss      Entry: the editor's admin overlay, own --ao-* tokens (backend.md)
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
├── messages.css     always loaded
└── admin-overlay.css  only for a logged-in editor (AbstractFrontendController)
```

`desktop.css.map` / `mobile.css.map` in that folder are leftovers of the deleted legacy
`src/scss/` (their `sources` point there, CSS-FRONTEND-LEGACY-001) — not produced by the
current build.

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
| Image beside text | `components/_image-text.scss` |
| Call to action + button | `components/_cta.scss` |
| Public (contact) form | `components/_public-form.scss` |
| DMS image / gallery blocks | `components/_media.scss` |
| Footer (dark block, columns, copyright row) | `components/_footer.scss` |
| Flash / popup messages, content preview notice | `components/_flash-messages.scss` / `_popup-messages.scss` / `_preview-bar.scss` (entry `messages.scss`) |
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
| `--fe-surface` | surface (white) |
| `--fe-surface-alt` | alternating section background |
| `--fe-text` | primary text / headlines (near-black) |
| `--fe-text-soft` | secondary body text |
| `--fe-muted` | labels, eyebrows, captions |
| `--fe-line` | hairlines, dividers |
| `--fe-line-strong` | heavy dividers |
| `--fe-accent` | accent — same as `--fe-text` (Swiss: weight, not colour) |
| `--fe-danger` | the one colour with a meaning: form errors |
| `--fe-on-dark-text` | text on dark blocks |
| `--fe-font-sans` / `--fe-font-mono` | Helvetica / system stacks (no web font) |
| `--fe-fs-*` / `--fe-fw-*` / `--fe-lh-*` / `--fe-track-*` | type scale, weights, line heights, tracking |
| `--fe-space-1` … `--fe-space-10`, `--fe-gutter-mobile` / `-tablet` / `-desktop` | spacing + page gutters |
| `--fe-z-*`, `--fe-transition-*` | stacking, transitions |
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

Each page lives as an action template under `Main/IndexController/` that only places section partials (since 2026-10-22, [`content.md`](content.md) «Starter pages»):

```text
res/view/templates/Main/IndexController/
├── homeAction.tpl.php       /home (default)
├── aboutAction.tpl.php      /about
├── servicesAction.tpl.php   /services
├── contactAction.tpl.php    /contact
├── thanksAction.tpl.php     /thanks (PRG target of the contact form)
├── legalAction.tpl.php      /legal
└── privacyAction.tpl.php    /privacy
res/view/templates/partials/sections/
    hero · textSection · cards · imageText · cta · form · legalText
```

Standard page composition: `<section class="fe-hero">` + 1–3 `<section class="fe-section">`. Each section wraps content in `<div class="fe-container">` (single source of horizontal gutter). Classes added 2026-10-22: `fe-section__body` (paragraphs under a heading), `fe-section--form` (text above a form, no rule), `fe-prose__block` + `.fe-prose h2` (legal text), `fe-grid--thirds` (three / six cards on desktop), `fe-image-text` (+ `__text`, `__media`, `--left`, `--text-only`; stacked on mobile), `fe-cta`, `fe-button` (+ `--inverse` on a dark section).

## JavaScript (vanilla, no build)

Every page loads only the shared core script; the module's own scripts come with the page or the feature that needs them (`addJs()`), and each is minified to its `*.min.js` with `npm run build:js` ([`js-build.md`](js-build.md)). The nav overlay runs on the CSS-only `#fe-nav-toggle` pattern (see `## nav overlay — CSS-only toggle`).

| File | Loaded |
|---|---|
| shared `core.js` | every page (`layoutConfig` `javascripts`) — flash + popup message channel, fetch envelope dispatch, `--z77-scrollbar-width` ([`messages.md`](messages.md), [`fetch.md`](fetch.md)) |
| `public-form.js` | the contact page (`IndexController::contactAction`) — per-field server validation on blur; the form works without it |
| `content-edit.js` | the page editor, only for an editor with «Seite bearbeiten» on (`AbstractFrontendController`, ADR-045 §4) |
| `lottie.js` + `lottie-figure.js` | opt-in — a project lists both in its `javascripts` ([`lottie.md`](lottie.md)) |

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
├── html-default-skeleton.tpl.php    <html class="fe"> (token wrapper) → body wraps header / main / footer / flash / messages / preview
├── partials/header.tpl.php          #fe-nav-toggle + .fe-topbar + .fe-nav-overlay (slot frontend-main)
├── partials/footer.tpl.php          .fe-footer (brand + «pages» frontend-main + «legal» frontend-meta + copyright)
├── partials/sections/*.tpl.php      hero · textSection · cards · imageText · cta · form · legalText
├── partials/publicForm.tpl.php      .fe-form (contact form)
├── partials/adminOverlay.tpl.php    .z77-admin-overlay (editor only)
└── Main/IndexController/*.tpl.php   7 page actions, each placing section partials
```

## rules

- When styling colours, fonts, or spacing → MUST use `--fe-*` token variables; values MUST NOT be hardcoded outside the tokens
- When declaring `--fe-*` tokens → MUST place them on the `.fe` wrapper selector (the four `tokens/_*.scss` files); MUST NOT declare design tokens on `:root` (ADR-018; only `@font-face` / `color-scheme` stay global, of which the frontend SCSS has none — system fonts)
- When adding component styles → MUST live in `components/_*.scss`; MUST NOT be added to layout files (layout = breakpoint-bound only)
- When adding layout rules → MUST live in the matching `layout/_*.scss` file, scoped to its media query (no base-style leak)
- When adding a new page → MUST add as an action in `IndexController` + navigation entry in `navigation.json`; MUST NOT create a new controller unless the page needs its own behaviour
- When adding frontend interactivity → MUST remain vanilla IIFE in `res/assets/js/<name>.js`, registered in `layoutConfig.inc.php` `javascripts` only when every page needs it (else `addJs()` in the action), and minified with `npm run build:js`; MUST NOT introduce a bundler
- When running build commands → MUST run from framework root (`npm run watch:frontend` / `npm run build:frontend`); MUST follow the watch/build workflow in [`css-watch.md`](css-watch.md)

## see also

- [`css-watch.md`](css-watch.md) — uniform SCSS watch/build workflow + ask-for-watcher convention at session start
- [`css-backend.md`](css-backend.md) — mirror topic for the backend module
- [`stylesheet.md`](stylesheet.md) — how compiled CSS is loaded into pages (asset pipeline, FileFinder, AssetCleaner)
- [`messages.md`](messages.md) — flash + popup message channel (`flash-msg`, `msg-popup`) — shared across modules
- [`navigation.md`](navigation.md) — render-slots used by the frontend header and footer (`frontend-main`, `frontend-meta`)
- [`js-build.md`](js-build.md) — minifying the module's scripts
- [`../01-handbook/css-conventions.md`](../01-handbook/css-conventions.md) — BEM, tokens, component patterns

## known issues

- **CSS-FRONTEND-LEGACY-001** — resolved 2026-05-17. Legacy `packages/module-frontend/src/scss/` deleted. `res/scss/` is the only source. (Its two source maps `res/assets/css/desktop.css.map` / `mobile.css.map` are still tracked — see `## pending`.)
- **CSS-FRONTEND-FONTS-001** — found 2026-11-14 (doc audit). Don't assume the frontend loads no web font: `tokens/_typography.scss` says «System fonts only — no web-font loading» and `--fe-font-sans` names no web font, yet the head partial `partials/head/fonts.tpl.php` (layoutConfig `head` → `fonts`) writes a `<link>` to `fonts.googleapis.com` (Inter 400/600) on every page. Nothing in the SCSS uses Inter, so the request is dead weight; it is a foreign origin against the report-only page CSP (`style-src 'self'`, `SecurityHeaders` — the backend self-hosts its faces for that reason), and it sends every visitor's IP to Google. The same link is the one CSP finding of [`security.md`](security.md) (SEC-HEAD-001, pending «Enforcing the page CSP»). Code not changed — owner to decide (see `## pending`).

## pending

- **Frontend web-font link (CSS-FRONTEND-FONTS-001)** — owner to decide: empty `partials/head/fonts.tpl.php` (system fonts, as the tokens say) or self-host a face like the backend does; the same decision unblocks enforcing the page CSP (`security.md`). Size XS.
- **Stale source maps** — delete the tracked `res/assets/css/desktop.css.map` / `mobile.css.map` (they map the deleted `src/scss/`). Size XS.
- **PAUSE-1 visual test** — check the starter design in a browser across all seven pages and three breakpoints before treating it as shippable, together with the backend (C2) after the wrapper-token move (CSS-WRAPPER-TOKENS C1, done 2026-06-22; [`../03-development/css-wrapper-token-bauplan.md`](../03-development/css-wrapper-token-bauplan.md) still reads «PAUSE 1: visueller Test ausstehend»).
