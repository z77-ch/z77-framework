# CSS Conventions

**Status:** `[CURRENT]`
**Date:** 2026-11-19

This document is the authoritative reference for all CSS/SCSS work in z77-based projects.
When writing CSS for any module, this document takes precedence.

---

## 1. File Structure

### SCSS source layout

```
packages/module-frontend/res/scss/
│
├── tokens/
│   ├── _colors.scss          # Color palette + semantic color variables
│   ├── _typography.scss      # Font families, sizes, weights, line-heights
│   ├── _spacing.scss         # Spacing scale
│   └── _effects.scss         # Shadows, border-radius, transitions, z-index
│
├── base/
│   ├── _normalize.scss       # CSS reset (box-sizing, margin, padding, media elements)
│   ├── _elements.scss        # Base element styles (body, a, img, button, input...)
│   └── _index.scss           # @forward all base files
│
├── components/                # one file per block (the module-frontend set today)
│   ├── _container.scss  _section.scss  _topbar.scss  _nav-overlay.scss  _footer.scss
│   ├── _hero.scss  _cta.scss  _image-text.scss  _media.scss  _lottie.scss
│   ├── _public-form.scss
│   └── _flash-messages.scss  _popup-messages.scss  _preview-bar.scss   # → messages.scss
│
├── layout/
│   ├── _mobile.scss          # Page layout for mobile
│   ├── _tablet.scss          # Page layout for tablet
│   ├── _desktop.scss         # Page layout for desktop
│   ├── _nav-mobile.scss      # Navigation layout for mobile (hamburger)
│   ├── _nav-tablet.scss      # Navigation layout for tablet
│   └── _nav-desktop.scss     # Navigation layout for desktop (horizontal)
│
├── base.scss                 # Entry: tokens + base + components (always loaded)
├── mobile.scss               # Entry: layout/mobile + nav-mobile
├── tablet.scss               # Entry: layout/tablet + nav-tablet
├── desktop.scss              # Entry: layout/desktop + nav-desktop
├── messages.scss             # Entry: flash + popup messages, preview notice (standalone)
├── admin-overlay.scss        # Entry: the admin overlay, own tokens (standalone)
├── nav-mobile.scss           # Entry: nav-mobile only (optional separate load)
├── nav-tablet.scss
└── nav-desktop.scss
```

### Compiled output

```
res/assets/css/
├── base.css
├── mobile.css
├── tablet.css
├── desktop.css
├── nav-mobile.css
├── nav-tablet.css
├── nav-desktop.css
├── messages.css
└── admin-overlay.css
```

### layoutConfig.inc.php registration

```php
'styleSheets' => [
    ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'base',        'media' => ''],
    ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'mobile',      'media' => 'screen and (max-width: 767px)'],
    ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'tablet',      'media' => 'screen and (min-width: 768px) and (max-width: 1199px)'],
    ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'desktop',     'media' => 'screen and (min-width: 1200px)'],
    ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'nav-mobile',  'media' => 'screen and (max-width: 767px)'],
    ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'nav-tablet',  'media' => 'screen and (min-width: 768px) and (max-width: 1199px)'],
    ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'nav-desktop', 'media' => 'screen and (min-width: 1200px)'],
    ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'messages',    'media' => ''],
],
```

`base` has no media attribute — always loaded. Layout and nav files are conditionally applied.

### Embedded fragment exception (DMS)

The above multi-file responsive split is for **full-page viewAreas** (frontend, backend). An
**embedded fragment** like the DMS (`module-dms`, wrapper `.dms`) is rendered inside a host page
and does NOT own page layout or breakpoints. It therefore ships a **single bundle**
(`res/scss/dms.scss` → `dms.css`); the host loads it via its own `layoutConfig` and the fragment
adapts within its container (internal `@media`/container query in a component). Its tokens are
`--dms-*` prefixed on the `.dms` wrapper. See [`../topics/css-dms.md`](../topics/css-dms.md).

---

## 2. Breakpoints

| Name    | Range             | Target devices                 |
|---------|-------------------|--------------------------------|
| mobile  | < 768px           | Phones, small screens          |
| tablet  | 768px – 1199px    | Tablets, landscape phones      |
| desktop | ≥ 1200px          | Laptops, desktops, wide screens|

These breakpoints are fixed across all projects. Do not invent intermediate breakpoints.
If a component needs a minor adjustment between these ranges, use a scoped `@media` inside
the relevant layout file — but do not add new global breakpoints.

---

## 3. Design Tokens (CSS Custom Properties)

**Tokens are declared on the viewArea wrapper, not on `:root`** (ADR-018). Each viewArea
owns a wrapper class — `.fe` (frontend), `.be` (backend), `.dms` (DMS) — on its skeleton
root (`<html>` for full-page areas, the fragment root container for an embedded widget).
This lets a nested viewArea (e.g. a DMS fragment inside a frontend page) use its own tokens
for its subtree while the host keeps its own — no global collision.

Binding rules (ADR-018):

- Every wrapper declares a **complete** token set — a token a descendant reads but the
  wrapper omits inherits the host's value (inconsistent). Completeness is the isolation.
- Only genuinely global declarations stay on `:root`: `@font-face` (family names are global
  — use unique names per area), `color-scheme`, a last-resort `<html>` background.
- Wrapper scoping isolates token **values**, not component **selectors**. An *embeddable*
  widget (DMS in the individual frontend) additionally prefixes its component blocks
  (`.dms-…`); standalone areas keep their class names.
- A host overrides an embedded area's tokens by redeclaring them on that wrapper
  (`.dms { --dms-… }`) in CSS loaded after the embedded bundle.

**Never use hardcoded values in components.** Always reference a token.

### The token sets as shipped

| Area | Wrapper | Token names | Source | Topic |
|---|---|---|---|---|
| Frontend | `.fe` | every token `--fe-*` | `module-frontend/res/scss/tokens/_*.scss` | [`css-frontend.md`](../topics/css-frontend.md) |
| Backend | `.be` | theme roles `--be-*` (palette × light/dark) **plus** an unprefixed scale (`--space-*`, `--radius-*`, `--shadow-*`, `--transition-*`, `--z-*`, `--font-*`, `--line-height-*`, `--color-*`) | `module-backend/res/scss/tokens/_*.scss` | [`css-backend.md`](../topics/css-backend.md) |
| DMS bundle | `.dms` | `--dms-*` | the DMS SCSS | [`css-dms.md`](../topics/css-dms.md) |
| Admin overlay (editor on a frontend page) | `.z77-admin-overlay` | `--ao-*` | `module-frontend/res/scss/admin-overlay.scss` | [`css-frontend.md`](../topics/css-frontend.md) |

The listings below are excerpts of the real files (values as of 2026-11-15); the
`tokens/_*.scss` files stay the authority.

### Frontend (`--fe-*`)

Monochrome by design (Swiss-Modern): hierarchy comes from weight and size, not from colour.

```scss
.fe {
    /* _colors.scss */
    --fe-bg:           #ffffff;
    --fe-surface:      #ffffff;
    --fe-surface-alt:  #f6f6f6;   /* striped sections, hover surfaces */
    --fe-text:         #0a0a0a;
    --fe-text-soft:    #2a2a2a;
    --fe-muted:        #6a6a6a;   /* labels, metadata, captions */
    --fe-line:         #e5e5e5;
    --fe-line-strong:  #0a0a0a;
    --fe-accent:       #0a0a0a;   /* weight is the accent, not colour */
    --fe-danger:       #b3261e;   /* the one colour with a meaning: form errors */
    --fe-on-dark-text: #ffffff;
    --fe-bg-dark:      #0a0a0a;

    /* _typography.scss — system fonts only */
    --fe-font-sans: 'Helvetica Neue', Helvetica, Arial, system-ui, sans-serif;
    --fe-font-mono: ui-monospace, 'SF Mono', 'Cascadia Code', Menlo, Consolas, monospace;
    --fe-fs-xs: 0.75rem;  --fe-fs-sm: 0.875rem;  --fe-fs-md: 1rem;  --fe-fs-lg: 1.125rem;
    --fe-fs-h3: 1.5rem;   --fe-fs-h2: 2.25rem;   --fe-fs-h1: 3rem;  --fe-fs-display: 4.5rem;
    --fe-fw-regular: 400; --fe-fw-medium: 500;   --fe-fw-bold: 700;
    --fe-lh-tight: 1.05;  --fe-lh-snug: 1.25;    --fe-lh-base: 1.55;
    --fe-track-tight: -0.02em; --fe-track-base: 0; --fe-track-wide: 0.08em;

    /* _spacing.scss — numbered steps, NOT a ×4px rule */
    --fe-space-1: 0.25rem;  --fe-space-2: 0.5rem;  --fe-space-3: 0.75rem; --fe-space-4: 1rem;
    --fe-space-5: 1.5rem;   --fe-space-6: 2rem;    --fe-space-7: 3rem;    --fe-space-8: 4rem;
    --fe-space-9: 6rem;     --fe-space-10: 8rem;
    --fe-gutter-mobile: 1.25rem;  --fe-gutter-tablet: 2rem;  --fe-gutter-desktop: 3rem;
    --fe-container-max: 1280px;

    /* _effects.scss */
    --fe-transition-fast: 120ms ease;  --fe-transition-base: 200ms ease;
    --fe-topbar-height: 64px;
    --fe-z-base: 1;  --fe-z-nav-mobile: 300;  --fe-z-topbar: 400;   /* below messages 1000, flash 1100 */
}
```

There are no radius or shadow tokens in the frontend today — the starter design has square
corners and no shadows. A project that needs them adds them to the matching `tokens/` file
under the same prefix (`--fe-…`), never as a value inside a component.

### Backend (`--be-*` + unprefixed scale)

Two layers on the same `.be` wrapper:

- **Theme roles `--be-*`** — what changes with the palette and light/dark:
  `--be-bg`, `--be-surface`, `--be-surface2` (the card), `--be-topbar`, `--be-topbar-text`,
  `--be-text`, `--be-muted`, `--be-line`, `--be-accent`, `--be-accent-soft`, `--be-good`,
  `--be-warn`, `--be-danger`, `--be-on-accent`, the confirm set (`--be-confirm`,
  `--be-confirm-ink`, `--be-confirm-island`, `--be-on-confirm`), the derived roles
  (`--be-field`, `--be-line-strong`, `--be-row-tint`), the island-proof `--be-*-page` set and
  `--be-font-scale` / `--be-font-scale-cap`. Every palette overrides them under
  `[data-be-palette="…"]` / `[data-be-theme="dark"]`. Meaning of each:
  [`css-backend.md` → backend theme tokens](../topics/css-backend.md#backend-theme-tokens---be-).
- **The scale, unprefixed** — what stays the same in every palette:

```scss
.be {
    /* _spacing.scss — the number × 4px */
    --space-1: 0.25rem; --space-2: 0.5rem; --space-3: 0.75rem; --space-4: 1rem; --space-5: 1.25rem;
    --space-6: 1.5rem;  --space-8: 2rem;   --space-10: 2.5rem; --space-12: 3rem; --space-16: 4rem;

    /* _effects.scss */
    --radius-sm: 4px; --radius-md: 6px; --radius-lg: 12px; --radius-xl: 20px; --radius-pill: 9999px;
    --radius-card: 8px; --radius-field: 5px;
    --shadow-sm … --shadow-xl;
    --transition-fast: 150ms ease; --transition-base: 250ms ease; --transition-slow: 400ms ease;
    --z-base: 1; --z-topbar: 40; --z-dropdown: 100; --z-sticky: 200; --z-overlay: 300;
    --z-modal: 400; --z-toast: 500; --z-bot: 900; --z-message: 1000; --z-flash: 1100;

    /* _typography.scss — font sizes follow the user's font slider */
    --font-family-base / -ui / -display: 'Instrument Sans', 'Segoe UI', system-ui, sans-serif;
    --font-family-mono: 'IBM Plex Mono', ui-monospace, Consolas, monospace;
    --font-size-xs … --font-size-2xl;          /* 12 … 20px at slider 1.0, rem of the root (14px × slider) */
    --font-weight-normal: 400 … --font-weight-bold: 700;
    --line-height-tight: 1.2; --line-height-base: 1.5; --line-height-loose: 1.75;

    /* _colors.scss — generic, palette-independent */
    --color-primary, --color-success(-bg), --color-danger(-bg|-border), --color-warning(-bg),
    --color-info(-bg), --color-text(-muted|-inverse), --color-bg(-surface|-elevated),
    --color-border(-focus);
}
```

A colour that must follow the user's palette or dark mode is a `--be-*` role — the
`--color-*` set does not change with the palette.

---

## 4. Naming Convention (BEM)

All CSS uses BEM: Block, Element, Modifier.

```
.block {}              /* component root */
.block__element {}     /* child inside block — double underscore */
.block--modifier {}    /* variant or state — double dash */
```

### Rules

- Block = one noun, prefixed by the area: `.fe-section`, `.fe-hero`, `.be-btn`, `.be-list`.
  Cross-module blocks (flash and popup messages, the preview bar, the DMS image blocks) stay
  unprefixed — [`css-frontend.md`](../topics/css-frontend.md)
- Element = describes its role inside the block: `.fe-hero__title`, `.fe-topbar__link`
- Modifier = describes a variant or state: `.fe-section--dark`, `.fe-topbar__link--active`,
  `.be-btn--primary`
- Never style a raw HTML element unless in `base/_elements.scss`
- Never nest BEM classes (`.fe-hero .fe-hero__title {}` is wrong — just `.fe-hero__title {}`).
  A modifier may reach its own elements (`.fe-section--dark .fe-section__title`)
- State modifiers go on the block: `.flash-msg--error`, `.fe-section--dark`
- JS hooks: use `data-` attributes, never CSS classes (`data-menu-open`, `data-z77-split`)
- IDs: only for fragment anchors or ARIA references — never for styling

### Examples

```css
/* Correct — real classes */
.fe-hero {}
.fe-hero__title {}
.fe-section--alt {}
.be-btn--confirm {}
.be-list__cell--num {}

/* Wrong — never do this (counterexamples, not shipped) */
#submit-button {}           /* ID for styling */
.fe-hero .title {}          /* element nesting */
.fe-hero > h1 {}            /* element tag styling inside component */
.isActive {}                /* camelCase */
.fe-hero_title {}           /* single underscore */
```

---

## 5. Components

The areas ship their components; a project extends them with a modifier or builds a new
block the same way. Check here and in the topic doc first — §7 «Generating CSS».

### 5.1 Frontend blocks (`module-frontend/res/scss/components/`)

| File | Block(s) | Elements / modifiers (selection) |
|---|---|---|
| `_container.scss` | `.fe-container` | max-width `--fe-container-max`; the gutter comes from the layout files |
| `_section.scss` | `.fe-section`, `.fe-grid`, `.fe-item`, `.fe-prose`, `.fe-dl` | `.fe-section--alt` / `--dark` / `--form`, `.fe-section__title`, `.fe-grid--thirds`, `.fe-item--half`, `.fe-item__title` |
| `_hero.scss` | `.fe-hero` | `__eyebrow`, `__title`, `__subline` |
| `_cta.scss` | `.fe-cta`, `.fe-button` | `.fe-button--inverse` (on a dark section) |
| `_image-text.scss` | `.fe-image-text` | `--left`, `--text-only`, `__media` |
| `_topbar.scss` | `.fe-topbar`, `.fe-nav-toggle` | `__inner`, `__brand`, `__nav`, `__link`, `__link--active`, `__link--lang`, `__hamburger` |
| `_nav-overlay.scss` | `.fe-nav-overlay` | `__list`, `__link`, `__link--active`, `__list--lang` |
| `_footer.scss` | `.fe-footer` | `__grid`, `__brand`, `__col-title`, `__list`, `__link`, `__bottom`, `__copy` |
| `_public-form.scss` | `.fe-form` | `__title`, `__row`, `__row--error` / `--check` / `--radio`, `__hint`, `__error`, `__submit`, `__hp` (honeypot) |
| `_media.scss` | `.media-image`, `.media-gallery` (unprefixed — the DMS renderers' names) | `__img`, `__caption`, `__item` |
| `_flash-messages.scss`, `_popup-messages.scss`, `_preview-bar.scss` | `.flash-msg`, `.msg-popup`, `.preview-bar` (cross-module, own `messages` bundle) | → [`messages.md`](../topics/messages.md) |

A shipped block, exactly as in `_hero.scss` (excerpt):

```scss
.fe-hero {
    padding-top:    var(--fe-space-9);
    padding-bottom: var(--fe-space-9);
    border-bottom:  1px solid var(--fe-line);
}
.fe-hero__title {
    font-size:      var(--fe-fs-h1);
    font-weight:    var(--fe-fw-bold);
    line-height:    var(--fe-lh-tight);
    letter-spacing: var(--fe-track-tight);
    color:          var(--fe-text);
    max-width:      18ch;
}
```

### 5.2 Backend vocabulary (`module-backend/res/scss/components/`)

One look per KIND of control — pick by kind, never by taste. The full table with the looks is
[`css-backend.md` → buttons, tabs, switch](../topics/css-backend.md#buttons-tabs-switch); the
screen recipe is [`backend-screen.md`](backend-screen.md).

| Need | Class | File |
|---|---|---|
| Button | `.be-btn` (secondary) + `--primary` (create, save) / `--confirm` (book) / `--confirm-open` / `--danger` / `--add` / `--quiet` / `--sm`; label in `.be-btn__label` | `_buttons.scss` |
| List | `.be-list` v2: `__frame` > `__table` > `__head` + `__item` > `__row` > `__cell` (`--num`, `--mono`, `--muted`, …) | `_list.scss` |
| Tabs | `.be-viewtabs`; radio tabs `.be-radiotabs` | `_shell.scss`, `_radiotabs.scss` |
| On/off | `.be-switch` | `_switch.scss` |
| Selection (radio/checkbox) | `.be-choice` (`__input`, `__label`, `--filled`) | `_forms.scss` |
| Form blocks | `.be-form__blocks`, `.be-form__block`, `.be-form__block-head` | `_forms.scss` |
| Dialog | `.be-modal` (`__inner`, `__header`, `__title`, `__body`, `__close`) | `_modal.scss` |
| Paging | `.be-pagination` (`__link`, `__link--active`, `__link--disabled`, `__ellipsis`, `__count`) | `_pagination.scss` |
| Row state pill | `.badge` (`--primary` / `--success` / `--danger` / `--warning` / `--info` / `--muted`) — unprefixed | `_badges.scss` |
| Card, plain table | `.card` (`__body`, `__title`, …), `.table` (`--striped`, `--hover`, `--compact`) — unprefixed | `_cards.scss`, `_tables.scss` |

### 5.3 A new block in a project (pattern example)

The shape a project follows — `.fe-teaser` is a **made-up name** for illustration, not a
shipped block; every token it reads exists:

```scss
// override/z77/module/frontend/res/scss/components/_teaser.scss (pattern example)
.fe-teaser            { padding: var(--fe-space-6) 0; border-top: 1px solid var(--fe-line); }
.fe-teaser__eyebrow   { font-size: var(--fe-fs-xs); color: var(--fe-muted);
                        text-transform: uppercase; letter-spacing: var(--fe-track-wide); }
.fe-teaser__title     { font-size: var(--fe-fs-h3); font-weight: var(--fe-fw-bold);
                        line-height: var(--fe-lh-snug); }
.fe-teaser--dark      { background: var(--fe-bg-dark); color: var(--fe-on-dark-text); }
```

Its column span goes into the layout files (§6), not into the block. A data-driven component
(count-dependent selectors) uses generated CSS — [`patterns/slider.md`](patterns/slider.md).

---

## 6. Layout Conventions

Layout files define **positioning and structure** — where blocks appear on the page,
grid columns, page width, gutters. Components (§5) are the same at every breakpoint; the
layout files (`layout/_mobile|_tablet|_desktop.scss`, `_nav-*.scss`) only adjust the spatial
arrangement. The backend has no layout files — its responsive rules live in `@media` blocks
inside the component partials ([`css-backend.md`](../topics/css-backend.md)).

### Page container

```scss
/* components/_container.scss — width only */
.fe-container { width: 100%; max-width: var(--fe-container-max); margin-left: auto; margin-right: auto; }

/* layout/_mobile.scss — the gutter per breakpoint (tablet / desktop: --fe-gutter-tablet / -desktop) */
.fe-container { padding-left: var(--fe-gutter-mobile); padding-right: var(--fe-gutter-mobile); }
```

### Grid

CSS Grid for layouts — no float or flexbox hacks for page layout. The shipped grid is
`.fe-grid` (12 columns, `components/_section.scss`); the spans are set per breakpoint:

```scss
.fe-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: var(--fe-space-5); }

/* layout/_mobile.scss */  .fe-grid { grid-template-columns: 1fr; gap: var(--fe-space-4); }
/* layout/_tablet.scss */  .fe-grid > .fe-item { grid-column: span 6; }
/* layout/_desktop.scss */ .fe-grid > .fe-item { grid-column: span 3; }
                           .fe-grid--thirds > .fe-item { grid-column: span 4; }
```

### Fixed top bar

`.fe-topbar` is `position: fixed` at `z-index: var(--fe-z-topbar)`; the page body is offset
by `--fe-topbar-height`. A project's own sticky or fixed element takes its layer from the
`--fe-z-*` lanes, never a free number.

---

## 7. Rules

### Always

- Reference tokens for every color, size, shadow, transition
- Declare tokens on the viewArea wrapper (`.fe` / `.be` / `.dms`), with a complete set per wrapper (ADR-018)
- Use BEM for every class name
- Write components in `components/` — one file per component
- Write layout in `layout/` — one file per breakpoint (mobile/tablet/desktop + nav variants)
- Use CSS Custom Properties — not SCSS variables — for token values
- Write `display: none` with a breakpoint modifier if toggling visibility per device

### Never

- Declare design tokens on `:root` — they belong on the viewArea wrapper (only `@font-face` / `color-scheme` stay global, ADR-018)
- Hardcode color values (`#333`, `rgba(...)`) outside of `tokens/_colors.scss`
- Use `!important` — if needed, the specificity is wrong
- Style raw HTML elements outside of `base/_elements.scss` and `base/_normalize.scss`
- Add breakpoints other than mobile/tablet/desktop
- Use IDs for styling
- Nest BEM classes in selectors (`.fe-hero .fe-hero__title {}` is wrong)
- Use `margin-top` on components — use `gap` in the parent layout instead
- Add `px` values — use `rem` for typography and spacing, `px` only for borders and shadows

### Generating CSS

When writing CSS for a new component or page section:
1. Check whether the area already ships the block (§5, the topic doc) — extend with a modifier, do not create a new block
2. If genuinely new: define block + elements + modifiers in `components/`
3. Place in the correct layout file if it's positional
4. Reference only tokens — if a token is missing, add it to the correct tokens file
