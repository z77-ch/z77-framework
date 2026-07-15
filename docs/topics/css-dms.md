# css-dms

2026-07-03

## entry

1. `packages/module-dms/res/scss/` — SCSS source for the embedded `.dms` fragment (tokens; base + components land in R6b)
2. `docs/02-decisions/adr-018-css-tokens-scoped-to-viewarea-wrapper.md` — binding decision: DMS is an embeddable fragment with a `.dms` wrapper + complete token set + `.dms-` component prefix
3. `docs/01-handbook/css-conventions.md` — BEM naming, token rules, component patterns (shared across modules)

## file map

SOURCE=/packages/module-dms/res/scss
SOURCE=/packages/module-dms/res/assets/css
SOURCE=/packages/module-dms/src/App/Config/dmsConfig.inc.php
SOURCE=/docs/02-decisions/adr-018-css-tokens-scoped-to-viewarea-wrapper.md
SOURCE=/docs/01-handbook/css-conventions.md

## mental model

The DMS is **not** a full-page viewArea with its own shell — it is an **embeddable fragment**
(ADR-018): a `.dms`-wrapped HTML component rendered INSIDE a host viewArea (frontend, backend,
member, …). The host decides via its own `layoutConfig.inc.php` whether to load the dms CSS/JS
bundle; the fragment never owns a page skeleton or breakpoints of its own.

- **One bundle, not the responsive split.** Frontend/backend are full pages and split into
  `base` + `mobile`/`tablet`/`desktop`. The dms fragment ships a SINGLE sheet `dms.css` (entry
  `res/scss/dms.scss`): the host owns page-level layout/breakpoints, the fragment styles itself
  within its container (internal `@media` inside a component where it must adapt).
- **Tokens on `.dms`, never `:root` (ADR-018).** All design tokens are `--dms-*` CSS custom
  properties declared on the `.dms` wrapper. Because token VALUES resolve to the nearest wrapper
  ancestor, a `.dms` fragment inside a `.fe` page uses its own tokens for its subtree while the
  host keeps its own — no global collision.
- **The token set MUST stay complete.** A token a dms component reads but `.dms` omits inherits
  the HOST's value → the fragment renders differently per host. Completeness is the isolation
  (ADR-018 rule 1).
- **`--dms-*` prefix is deliberate.** Following the real frontend (`--fe-*`) / backend (`--be-*`)
  pattern: because dms component CSS lives inside a host's DOM at runtime, the prefix makes it
  unambiguous which wrapper a value resolves to. (Decided 2026-06-29; the css-conventions §3
  generic `--color-*` examples predate the embedding reality — see known issues.)
- **Component-class isolation is a separate axis (R6b).** Wrapper scoping isolates token VALUES,
  not selectors. An embedded `.btn` would still collide with a host's `.btn`; dms therefore
  prefixes its component blocks `.dms-…` (ADR-018 rule 3). Those components do not exist yet.
- **Host override.** A host redeclares a token on the wrapper in CSS loaded after the dms bundle,
  e.g. `.fe .dms { --dms-accent: #0a0a0a; }`.

## scss source files

```text
packages/module-dms/res/scss/
├── tokens/
│   ├── _colors.scss       --dms-* surface / text / line / accent + semantic colours
│   ├── _typography.scss   --dms-* font family, size scale, weights, line-heights
│   ├── _spacing.scss      --dms-space-* (powers-of-4 scale)
│   └── _effects.scss      --dms-* radius, shadow, transition, z-index lanes
├── base/
│   └── _base.scss         `.dms`-scoped reset/font (does NOT touch the host page)
├── components/
│   ├── _icon.scss         .dms-icon (inline SVG) + .dms-iconbtn (square action button)
│   ├── _button.scss       .dms-btn (primary / ghost / muted)
│   ├── _drive.scss        .dms-drive — the 3-pane Drive grid (toolbar + tree | list | preview)
│   ├── _tree.scss         .dms-tree — left folder hierarchy (depth via --dms-depth)
│   ├── _filelist.scss     .dms-file — middle document rows (thumbnail / kind icon, badge, actions)
│   └── _preview.scss      .dms-preview — right pane (media + metadata + actions)
└── dms.scss               Entry: the single always-loaded bundle (tokens + base + components)
```

The **3-pane Drive** is the R6b surface (user-directed layout, 2026-06-29): left folder tree,
middle document list with thumbnails (images) / kind icons (everything else), right preview pane.
Markup contract = the preview `temp/dms-drive-preview.html` (verified via a headless screenshot,
`temp/dms-drive-preview.png`). The Drive is CSS-complete but **not yet wired into a host** — see pending.

## compiled output

```text
packages/module-dms/res/assets/css/
└── dms.css                always loaded by the host (its layoutConfig); the `.dms` token set
```

## what goes where

| Change | File |
|---|---|
| Colour, surface, text, accent, semantic | `tokens/_colors.scss` |
| Font family, size scale, weight, line-height | `tokens/_typography.scss` |
| Spacing scale | `tokens/_spacing.scss` |
| Radius, shadow, transition, z-index | `tokens/_effects.scss` |
| `.dms`-scoped base element styles (R6b) | `base/_*.scss` (not yet present) |
| `.dms-` prefixed components (R6b) | `components/_*.scss` (not yet present) |

## dms tokens (--dms-*)

Declared on the `.dms` wrapper. Spacing / typography / effects mirror the framework standard
scale (only the names carry the dms prefix); colours **mirror the backend Werkbank palette**
(technical indigo, 2026-07-03) as the `.dms` fragment's OWN separate copy — same values as
`--be-*`, mapped to the `--dms-*` names, kept independent so a host can still override (ADR-018).
A dark set (`[data-be-theme="dark"] .dms`) matches the backend dark theme (see known issues).

| Group | Tokens |
|---|---|
| Surface | `--dms-bg`, `--dms-surface`, `--dms-surface-alt`, `--dms-elevated` |
| Text | `--dms-text`, `--dms-text-soft`, `--dms-muted`, `--dms-text-inverse` |
| Lines | `--dms-line`, `--dms-line-strong` |
| Accent | `--dms-accent`, `--dms-accent-dark`, `--dms-accent-light`, `--dms-accent-soft`, `--dms-on-accent`, `--dms-focus-ring` |
| Semantic | `--dms-success(-bg)`, `--dms-danger(-bg)`, `--dms-warning(-bg)`, `--dms-info(-bg)` |
| Typography | `--dms-font-family-base`/`-mono`, `--dms-font-size-xs…3xl`, `--dms-font-weight-*`, `--dms-line-height-*` |
| Spacing | `--dms-space-1…16` |
| Effects | `--dms-radius-*`, `--dms-shadow-*`, `--dms-transition-*`, `--dms-z-base…modal` |

## components (--dms- prefixed, R6b)

| Block | Role |
|---|---|
| `.dms-drive` | the 3-pane shell: `__toolbar` (breadcrumb + actions) over `__tree` \| `__list` \| `__preview`; CSS grid, collapses the preview below 60rem |
| `.dms-tree` | left folder hierarchy; node depth via inline `--dms-depth`; `--active` / `--inactive` / `--has-children.is-open` |
| `.dms-file` (in `.dms-filelist`) | one document row: `__thumb` (image thumbnail or kind-tinted icon), `__name` / `__meta`, `__badge--{public,protected,sealed}`, hover `__actions` |
| `.dms-preview` | right pane: `__media` (image or large kind icon), `__name`, `__meta` grid, `__actions`; `__empty` state |
| `.dms-btn` | labelled action button (`--primary` / `--ghost` / `--muted`) |
| `.dms-icon` / `.dms-iconbtn` | inline SVG icon (sized by font, `currentColor`) / square borderless icon button |

## rules

- When styling any colour, font, spacing, radius, shadow, transition, or z-index in dms CSS → MUST reference a `--dms-*` token; values MUST NOT be hardcoded outside `tokens/_*.scss`.
- When declaring `--dms-*` tokens → MUST place them on the `.dms` wrapper selector in `tokens/_*.scss`; MUST NOT declare design tokens on `:root` (ADR-018).
- When adding a token a dms component needs → MUST add it to `.dms` (keep the set complete); MUST NOT rely on a host token leaking into the fragment.
- When writing a dms component (R6b) → MUST prefix its block class `.dms-…` (component-selector isolation, ADR-018 rule 3); MUST NOT reuse an unprefixed block name that a host also defines (`.btn`, `.card`, …).
- When the fragment must adapt to its container width → MUST use an internal `@media`/container query inside the component; MUST NOT add a page-level breakpoint or assume the host's layout.
- When embedding the fragment in a host → the HOST MUST load `dms.css` via its own `layoutConfig.inc.php`; the dms module MUST NOT carry a page skeleton or its own `layoutConfig`.
- When running build commands → MUST run from the framework root (`npm run build:dms` / `npm run watch:dms`); MUST follow the workflow in [`css-watch.md`](css-watch.md).

## see also

- [`documents.md`](documents.md) — the DMS engine + the R6 rebuild this CSS is part of
- [`css-watch.md`](css-watch.md) — uniform SCSS watch/build workflow + ask-for-watcher convention at session start
- [`css-frontend.md`](css-frontend.md) / [`css-backend.md`](css-backend.md) — mirror topics for the full-page viewAreas; the hosts that can embed the dms fragment
- [`stylesheet.md`](stylesheet.md) — how compiled CSS is loaded into pages (asset pipeline)
- [`../01-handbook/css-conventions.md`](../01-handbook/css-conventions.md) — BEM, tokens, component patterns
- [`../02-decisions/adr-018-css-tokens-scoped-to-viewarea-wrapper.md`](../02-decisions/adr-018-css-tokens-scoped-to-viewarea-wrapper.md) — the binding wrapper-token decision

## known issues

- **DMS-PALETTE-001** — 2026-07-03. The `.dms` colour tokens were pulled from the neutral blue
  default (`--dms-accent: #2563eb`) to **mirror the backend Werkbank palette** (technical indigo,
  `#4f46e5`): the `.dms` fragment keeps its OWN separate `--dms-*` copy (ADR-018 — complete +
  overridable per host), values mapped from `--be-*` (e.g. `be-bg`→`dms-surface`, `be-accent-soft`→
  `dms-surface-alt`/`dms-accent-soft`, `be-good`→`dms-success`; `dms-info` stays a distinct cyan-blue,
  no `--be-*` equivalent). A **dark set was added** as `[data-be-theme="dark"] .dms` — because the dms
  is embedded ONLY in the backend host today, its dark theme keys off the host's `<html class="be"
  data-be-theme>`; a different host would override `.dms` per ADR-018 instead of relying on that
  selector (deliberate coupling, revisit if a second host embeds the fragment). Spacing / typography /
  effects unchanged. Same deferred dark-accent contrast caveat as the backend ([`css-backend.md`](css-backend.md) PALETTE-WERKBANK-001).

- **CSS-CONV-DRIFT-001** — the authoritative `css-conventions.md` §3 shows generic token names
  (`--color-*`, `--space-*`), but the real frontend uses `--fe-*` and the backend mixes
  `--color-*` + `--be-*`. The convention doc and the implemented code have drifted. `.dms` follows
  the implemented prefix pattern (`--dms-*`), not the §3 examples. Reconciling §3 with the real
  code is a separate cleanup (not part of the DMS rebuild).

## pending

- **R6b done (2026-06-29).** `.dms`-base + the `.dms-` Drive components (icon, button, drive, tree,
  filelist, preview) are built and compiled into `dms.css`, and **wired into the backend host**: a
  backend `DriveController` (group `documents`, ADMIN) renders the `.dms` 3-pane fragment at
  `/backend/documents/drive/list`, loading `dms.css` page-scoped via `addCss('dms','Z77\Module\Dms')`;
  the bundle is published to `public/assets/dms`. Verified with seeded data (real template + controller
  VM + real `dms.css`, headless: `temp/dms-drive-live.png`). Open: full admin click-through (no dev
  login), live image thumbnails (GD not loaded). Detail: [`../03-development/dms-umbauplan.md`](../03-development/dms-umbauplan.md) R6b.
- Later R6: upload, ACL panel, delivery-mode control components; the public/share materialization.
- Visual PAUSE+TEST: verify the embedded fragment in a real host (backend first) + a host token
  override once the integration lands.
