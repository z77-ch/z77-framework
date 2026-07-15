# CSS Conventions

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
├── components/
│   ├── _buttons.scss
│   ├── _cards.scss
│   ├── _forms.scss           # input, select, textarea, form-group
│   ├── _nav.scss             # nav block (structure only, no layout)
│   ├── _slider.scss
│   ├── _modal.scss
│   ├── _alerts.scss
│   ├── _badges.scss
│   ├── _tables.scss
│   └── _pagination.scss
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
└── nav-desktop.css
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

All tokens are defined in `tokens/_colors.scss` etc. and forwarded into `base.scss`.

**Tokens are declared on the viewArea wrapper, not on `:root`** (ADR-018). Each viewArea
owns a wrapper class — `.fe` (frontend), `.be` (backend), `.dms` (DMS) — on its skeleton
root (`<html>` for full-page areas, the fragment root container for an embedded widget).
This lets a nested viewArea (e.g. a DMS fragment inside a frontend page) use its own tokens
for its subtree while the host keeps its own — no global collision. The examples below use
`.fe`; backend uses `.be`, the DMS bundle `.dms`, with the same structure.

Binding rules (ADR-018):

- Every wrapper declares a **complete** token set — a token a descendant reads but the
  wrapper omits inherits the host's value (inconsistent). Completeness is the isolation.
- Only genuinely global declarations stay on `:root`: `@font-face` (family names are global
  — use unique names per area), `color-scheme`, a last-resort `<html>` background.
- Wrapper scoping isolates token **values**, not component **selectors**. An *embeddable*
  widget (DMS in the individual frontend) additionally prefixes its component blocks
  (`.dms-…`); standalone areas keep their class names.
- A host overrides an embedded area's tokens by redeclaring them on that wrapper
  (`.dms { --color-primary: … }`) in CSS loaded after the embedded bundle.

**Never use hardcoded values in components.** Always reference a token.

### Colors

```css
.fe {
    /* Brand */
    --color-primary:        #005fcc;
    --color-primary-dark:   #004299;
    --color-primary-light:  #4d9aff;
    --color-secondary:      #ffcc00;
    --color-secondary-dark: #cc9900;

    /* Semantic */
    --color-success:        #1a7f4b;
    --color-success-bg:     #e6f4ec;
    --color-danger:         #c0392b;
    --color-danger-bg:      #fdf0ee;
    --color-warning:        #e67e22;
    --color-warning-bg:     #fef4e6;
    --color-info:           #0077b6;
    --color-info-bg:        #e0f0fb;

    /* Neutral (text + surface) */
    --color-text:           #111111;
    --color-text-muted:     #666666;
    --color-text-inverse:   #ffffff;
    --color-bg:             #ffffff;
    --color-bg-surface:     #f5f5f5;
    --color-bg-elevated:    #ffffff;
    --color-border:         #dddddd;
    --color-border-focus:   var(--color-primary);
}
```

### Typography

```css
.fe {
    --font-family-base:    system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    --font-family-mono:    ui-monospace, "Cascadia Code", monospace;

    --font-size-xs:        0.75rem;    /* 12px */
    --font-size-sm:        0.875rem;   /* 14px */
    --font-size-md:        1rem;       /* 16px */
    --font-size-lg:        1.125rem;   /* 18px */
    --font-size-xl:        1.25rem;    /* 20px */
    --font-size-2xl:       1.5rem;     /* 24px */
    --font-size-3xl:       1.875rem;   /* 30px */
    --font-size-4xl:       2.25rem;    /* 36px */

    --font-weight-normal:  400;
    --font-weight-medium:  500;
    --font-weight-semibold:600;
    --font-weight-bold:    700;

    --line-height-tight:   1.2;
    --line-height-base:    1.5;
    --line-height-loose:   1.75;
}
```

### Spacing

The scale follows powers of 4. Reference by number — the number is the multiplier of 4px.

```css
.fe {
    --space-1:  0.25rem;   /*  4px */
    --space-2:  0.5rem;    /*  8px */
    --space-3:  0.75rem;   /* 12px */
    --space-4:  1rem;      /* 16px */
    --space-5:  1.25rem;   /* 20px */
    --space-6:  1.5rem;    /* 24px */
    --space-8:  2rem;      /* 32px */
    --space-10: 2.5rem;    /* 40px */
    --space-12: 3rem;      /* 48px */
    --space-16: 4rem;      /* 64px */
    --space-20: 5rem;      /* 80px */
    --space-24: 6rem;      /* 96px */
}
```

### Effects

```css
.fe {
    /* Border radius */
    --radius-sm:   4px;
    --radius-md:   8px;
    --radius-lg:   16px;
    --radius-xl:   24px;
    --radius-pill: 9999px;

    /* Shadows */
    --shadow-sm:   0 1px 3px rgba(0, 0, 0, 0.10);
    --shadow-md:   0 4px 12px rgba(0, 0, 0, 0.12);
    --shadow-lg:   0 8px 24px rgba(0, 0, 0, 0.15);
    --shadow-xl:   0 16px 48px rgba(0, 0, 0, 0.18);

    /* Transitions */
    --transition-fast:  150ms ease;
    --transition-base:  250ms ease;
    --transition-slow:  400ms ease;

    /* Z-index layers */
    --z-base:       1;
    --z-dropdown:   100;
    --z-sticky:     200;
    --z-overlay:    300;
    --z-modal:      400;
    --z-toast:      500;
}
```

---

## 4. Naming Convention (BEM)

All CSS uses BEM: Block, Element, Modifier.

```
.block {}              /* component root */
.block__element {}     /* child inside block — double underscore */
.block--modifier {}    /* variant or state — double dash */
```

### Rules

- Block = one noun: `.card`, `.btn`, `.nav`, `.slider`, `.modal`
- Element = describes its role inside the block: `.card__title`, `.nav__link`
- Modifier = describes a variant or state: `.btn--primary`, `.nav__link--active`
- Never style a raw HTML element unless in `base/_elements.scss`
- Never nest BEM classes (`.card .card__title {}` is wrong — just `.card__title {}`)
- State modifiers go on the block: `.modal--open`, `.nav--open`
- JS hooks: use `data-` attributes, never CSS classes (`data-action="toggle-nav"`)
- IDs: only for fragment anchors or ARIA references — never for styling

### Examples

```css
/* Correct */
.btn {}
.btn--primary {}
.btn--lg {}
.card__title {}
.nav__link--active {}

/* Wrong — never do this */
#submit-button {}           /* ID for styling */
.card .title {}             /* element nesting */
.card > h2 {}               /* element tag styling inside component */
.isActive {}                /* camelCase */
.card_title {}              /* single underscore */
```

---

## 5. Components

### 5.1 Button `.btn`

```scss
.btn {
    display:         inline-flex;
    align-items:     center;
    justify-content: center;
    gap:             var(--space-2);
    padding:         var(--space-2) var(--space-4);
    border-radius:   var(--radius-md);
    font-weight:     var(--font-weight-semibold);
    font-size:       var(--font-size-md);
    line-height:     var(--line-height-tight);
    cursor:          pointer;
    transition:      background-color var(--transition-fast),
                     color            var(--transition-fast),
                     border-color     var(--transition-fast);
    white-space:     nowrap;
    text-decoration: none;
}

/* Variants */
.btn--primary   { background: var(--color-primary);   color: var(--color-text-inverse); }
.btn--secondary { background: var(--color-secondary); color: var(--color-text); }
.btn--ghost     { background: transparent;            color: var(--color-primary);    border: 1px solid var(--color-primary); }
.btn--danger    { background: var(--color-danger);    color: var(--color-text-inverse); }
.btn--muted     { background: var(--color-bg-surface);color: var(--color-text-muted); }

/* Sizes */
.btn--sm  { padding: var(--space-1) var(--space-3); font-size: var(--font-size-sm); }
.btn--lg  { padding: var(--space-3) var(--space-6); font-size: var(--font-size-lg); }
.btn--xl  { padding: var(--space-4) var(--space-8); font-size: var(--font-size-xl); }

/* Layout */
.btn--full  { width: 100%; }
.btn--icon  { padding: var(--space-2); aspect-ratio: 1; border-radius: var(--radius-md); }

/* States */
.btn[disabled],
.btn--disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
```

### 5.2 Card `.card`

```scss
.card {
    background:    var(--color-bg-elevated);
    border:        1px solid var(--color-border);
    border-radius: var(--radius-lg);
    overflow:      hidden;
}

.card__media   { width: 100%; aspect-ratio: 16/9; object-fit: cover; }
.card__body    { padding: var(--space-6); }
.card__title   { font-size: var(--font-size-xl); font-weight: var(--font-weight-semibold); margin-bottom: var(--space-2); }
.card__text    { color: var(--color-text-muted); line-height: var(--line-height-base); }
.card__footer  { padding: var(--space-4) var(--space-6); border-top: 1px solid var(--color-border); display: flex; gap: var(--space-2); }
.card__tag     { font-size: var(--font-size-xs); text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-muted); }
.card__meta    { font-size: var(--font-size-sm); color: var(--color-text-muted); }

/* Variants */
.card--shadow      { border: none; box-shadow: var(--shadow-md); }
.card--horizontal  { display: flex; flex-direction: row; }
.card--horizontal .card__media { width: 40%; aspect-ratio: auto; flex-shrink: 0; }
.card--featured    { border-color: var(--color-primary); box-shadow: var(--shadow-lg); }
.card--interactive { cursor: pointer; transition: box-shadow var(--transition-base), transform var(--transition-base); }
.card--interactive:hover { box-shadow: var(--shadow-lg); transform: translateY(-2px); }
```

### 5.3 Form `.form`

```scss
.form__group {
    display:        flex;
    flex-direction: column;
    gap:            var(--space-1);
    margin-bottom:  var(--space-4);
}

.form__label {
    font-size:   var(--font-size-sm);
    font-weight: var(--font-weight-medium);
    color:       var(--color-text);
}

.form__label--required::after {
    content: ' *';
    color:   var(--color-danger);
}

.form__control {
    width:         100%;
    padding:       var(--space-2) var(--space-3);
    border:        1px solid var(--color-border);
    border-radius: var(--radius-md);
    font-size:     var(--font-size-md);
    color:         var(--color-text);
    background:    var(--color-bg);
    transition:    border-color var(--transition-fast), box-shadow var(--transition-fast);
}

.form__control:focus {
    border-color: var(--color-border-focus);
    box-shadow:   0 0 0 3px rgba(0, 95, 204, 0.15);
    outline:      none;
}

.form__control--error   { border-color: var(--color-danger); }
.form__control--success { border-color: var(--color-success); }

.form__hint  { font-size: var(--font-size-sm); color: var(--color-text-muted); }
.form__error { font-size: var(--font-size-sm); color: var(--color-danger); }

/* Textarea */
textarea.form__control { resize: vertical; min-height: 100px; }

/* Select — custom arrow */
select.form__control {
    background-image:    url("data:image/svg+xml,..."); /* chevron SVG */
    background-repeat:   no-repeat;
    background-position: right var(--space-3) center;
    padding-right:       var(--space-8);
    cursor:              pointer;
}

/* Checkbox / Radio */
.form__check       { display: flex; align-items: center; gap: var(--space-2); cursor: pointer; }
.form__check-input { width: 1rem; height: 1rem; cursor: pointer; }
.form__check-label { font-size: var(--font-size-md); cursor: pointer; }
```

### 5.4 Navigation `.nav`

The `.nav` block defines structure only — layout positioning (sticky, fixed, horizontal, vertical)
is defined in the layout files (`layout/_nav-mobile.scss` etc.).

```scss
.nav              { display: flex; align-items: center; }
.nav__logo        { flex-shrink: 0; }
.nav__logo-img    { height: 2rem; width: auto; }
.nav__list        { display: flex; list-style: none; margin: 0; padding: 0; }
.nav__item        { }
.nav__link        { display: block; padding: var(--space-2) var(--space-3); color: var(--color-text); font-weight: var(--font-weight-medium); transition: color var(--transition-fast); }
.nav__link:hover  { color: var(--color-primary); }
.nav__link--active{ color: var(--color-primary); font-weight: var(--font-weight-semibold); }
.nav__toggle      { display: none; background: none; border: none; padding: var(--space-2); cursor: pointer; }
.nav__toggle-icon { display: block; width: 1.5rem; height: 2px; background: var(--color-text); position: relative; transition: background var(--transition-fast); }
.nav__toggle-icon::before,
.nav__toggle-icon::after { content: ''; display: block; width: 100%; height: 2px; background: var(--color-text); position: absolute; transition: transform var(--transition-base); }
.nav__toggle-icon::before { top: -6px; }
.nav__toggle-icon::after  { top:  6px; }

/* Open state (JS adds this to .nav) */
.nav--open .nav__list  { display: flex; }

/* Dropdown */
.nav__dropdown         { position: relative; }
.nav__dropdown-menu    { position: absolute; top: 100%; left: 0; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-md); min-width: 12rem; z-index: var(--z-dropdown); display: none; }
.nav__dropdown:hover .nav__dropdown-menu { display: block; }
.nav__dropdown-item    { display: block; padding: var(--space-2) var(--space-4); color: var(--color-text); white-space: nowrap; }
.nav__dropdown-item:hover { background: var(--color-bg-surface); }
```

### 5.5 Slider `.slider`

```scss
.slider            { position: relative; overflow: hidden; }
.slider__track     { display: flex; transition: transform var(--transition-slow); }
.slider__slide     { flex-shrink: 0; width: 100%; }
.slider__nav       { position: absolute; top: 50%; transform: translateY(-50%); display: flex; justify-content: space-between; width: 100%; padding: 0 var(--space-2); pointer-events: none; }
.slider__btn       { pointer-events: all; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: var(--radius-pill); width: 2.5rem; height: 2.5rem; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: var(--shadow-sm); transition: box-shadow var(--transition-fast); }
.slider__btn:hover { box-shadow: var(--shadow-md); }
.slider__btn--prev { }
.slider__btn--next { }
.slider__dots      { display: flex; justify-content: center; gap: var(--space-2); padding: var(--space-4) 0; }
.slider__dot       { width: 0.5rem; height: 0.5rem; border-radius: var(--radius-pill); background: var(--color-border); transition: background var(--transition-fast); cursor: pointer; }
.slider__dot--active { background: var(--color-primary); }

/* CSS-only slider (no JS — uses :target or checkbox hack) */
/* For data-driven sliders: use createCss() with .slider.css.tpl.php */
```

### 5.6 Alert `.alert`

```scss
.alert           { display: flex; align-items: flex-start; gap: var(--space-3); padding: var(--space-4); border-radius: var(--radius-md); border: 1px solid transparent; font-size: var(--font-size-md); }
.alert--success  { background: var(--color-success-bg); border-color: var(--color-success); color: var(--color-success); }
.alert--danger   { background: var(--color-danger-bg);  border-color: var(--color-danger);  color: var(--color-danger); }
.alert--warning  { background: var(--color-warning-bg); border-color: var(--color-warning); color: var(--color-warning); }
.alert--info     { background: var(--color-info-bg);    border-color: var(--color-info);    color: var(--color-info); }
.alert__icon     { flex-shrink: 0; width: 1.25rem; height: 1.25rem; }
.alert__message  { flex: 1; }
.alert__close    { flex-shrink: 0; background: none; border: none; cursor: pointer; color: inherit; padding: 0; }
```

### 5.7 Badge `.badge`

```scss
.badge           { display: inline-flex; align-items: center; padding: var(--space-1) var(--space-2); border-radius: var(--radius-pill); font-size: var(--font-size-xs); font-weight: var(--font-weight-semibold); line-height: 1; text-transform: uppercase; letter-spacing: 0.05em; }
.badge--primary  { background: var(--color-primary);      color: var(--color-text-inverse); }
.badge--success  { background: var(--color-success-bg);   color: var(--color-success); }
.badge--danger   { background: var(--color-danger-bg);    color: var(--color-danger); }
.badge--warning  { background: var(--color-warning-bg);   color: var(--color-warning); }
.badge--muted    { background: var(--color-bg-surface);   color: var(--color-text-muted); }
```

### 5.8 Modal `.modal`

```scss
.modal           { display: none; position: fixed; inset: 0; z-index: var(--z-modal); }
.modal--open     { display: flex; align-items: center; justify-content: center; }
.modal__overlay  { position: absolute; inset: 0; background: rgba(0, 0, 0, 0.5); }
.modal__dialog   { position: relative; background: var(--color-bg-elevated); border-radius: var(--radius-lg); box-shadow: var(--shadow-xl); width: min(90vw, 600px); max-height: 90vh; display: flex; flex-direction: column; }
.modal__header   { display: flex; align-items: center; justify-content: space-between; padding: var(--space-6); border-bottom: 1px solid var(--color-border); }
.modal__title    { font-size: var(--font-size-xl); font-weight: var(--font-weight-semibold); }
.modal__body     { padding: var(--space-6); overflow-y: auto; flex: 1; }
.modal__footer   { padding: var(--space-4) var(--space-6); border-top: 1px solid var(--color-border); display: flex; justify-content: flex-end; gap: var(--space-2); }
.modal__close    { background: none; border: none; cursor: pointer; padding: var(--space-1); color: var(--color-text-muted); border-radius: var(--radius-sm); }

/* Sizes */
.modal__dialog--sm  { width: min(90vw, 400px); }
.modal__dialog--lg  { width: min(90vw, 800px); }
.modal__dialog--xl  { width: min(90vw, 1100px); }
```

### 5.9 Table `.table`

```scss
.table           { width: 100%; border-collapse: collapse; font-size: var(--font-size-md); }
.table th        { text-align: left; padding: var(--space-3) var(--space-4); border-bottom: 2px solid var(--color-border); font-weight: var(--font-weight-semibold); color: var(--color-text-muted); font-size: var(--font-size-sm); text-transform: uppercase; letter-spacing: 0.05em; }
.table td        { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border); vertical-align: middle; }
.table--striped tbody tr:nth-child(odd) { background: var(--color-bg-surface); }
.table--hover   tbody tr:hover          { background: var(--color-bg-surface); }
.table--compact th,
.table--compact td { padding: var(--space-2) var(--space-3); }
```

### 5.10 Pagination `.pagination`

```scss
.pagination       { display: flex; align-items: center; gap: var(--space-1); }
.pagination__item { }
.pagination__link { display: flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border-radius: var(--radius-md); border: 1px solid var(--color-border); color: var(--color-text); font-size: var(--font-size-sm); transition: background var(--transition-fast), color var(--transition-fast); }
.pagination__link:hover      { background: var(--color-bg-surface); }
.pagination__link--active    { background: var(--color-primary); color: var(--color-text-inverse); border-color: var(--color-primary); }
.pagination__link--disabled  { opacity: 0.4; pointer-events: none; }
.pagination__ellipsis        { display: flex; align-items: center; justify-content: center; width: 2.25rem; color: var(--color-text-muted); }
```

---

## 6. Layout Conventions

Layout files define **positioning and structure** — where blocks appear on the page,
grid columns, page width, sticky headers, etc.

Components defined in Section 5 are always the same regardless of breakpoint.
Layout files only adjust the spatial arrangement.

### Page container

```scss
.container {
    width:     100%;
    max-width: 1400px;
    margin:    0 auto;
    padding:   0 var(--space-4);
}

/* in tablet.scss */
.container { padding: 0 var(--space-6); }

/* in desktop.scss */
.container { padding: 0 var(--space-8); }
```

### Grid system

Use CSS Grid for layouts. No float or flexbox hacks for page layout.

```scss
/* 12-column grid */
.grid         { display: grid; grid-template-columns: repeat(12, 1fr); gap: var(--space-4); }

/* Predefined spans */
.col-1  { grid-column: span 1; }
.col-2  { grid-column: span 2; }
.col-3  { grid-column: span 3; }
.col-4  { grid-column: span 4; }
.col-6  { grid-column: span 6; }
.col-8  { grid-column: span 8; }
.col-12 { grid-column: span 12; }

/* On mobile: everything full width unless overridden in mobile.scss */
/* On tablet/desktop: column spans apply as defined */
```

### Sticky header

```scss
/* in layout/_desktop.scss or _tablet.scss */
.site-header {
    position: sticky;
    top: 0;
    z-index: var(--z-sticky);
    background: var(--color-bg-elevated);
    border-bottom: 1px solid var(--color-border);
}
```

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
- Nest BEM classes in selectors (`.card .card__title {}` is wrong)
- Use `margin-top` on components — use `gap` in the parent layout instead
- Add `px` values — use `rem` for typography and spacing, `px` only for borders and shadows

### Generating CSS

When writing CSS for a new component or page section:
1. Check if a component pattern exists in Section 5 — extend it with a modifier, do not create a new block
2. If genuinely new: define block + elements + modifiers in `components/`
3. Place in the correct layout file if it's positional
4. Reference only tokens — if a token is missing, add it to the correct tokens file
