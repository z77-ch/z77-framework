# css-dms

2026-07-03

## entry

1. `packages/module-dms/res/scss/` — SCSS source for the embedded `.dms` fragment (tokens; base + components land in R6b)
2. `docs/02-decisions/adr-018-css-tokens-scoped-to-viewarea-wrapper.md` — binding decision: DMS is an embeddable fragment with a `.dms` wrapper + complete token set + `.dms-` component prefix
3. `docs/01-handbook/css-conventions.md` — BEM naming, token rules, component patterns (shared across modules)

## file map

SOURCE=/packages/module-dms/res/scss
SOURCE=/packages/module-dms/res/assets/css
SOURCE=/packages/module-dms/res/scss/tokens/_colors.scss
SOURCE=/packages/module-backend/res/scss/components/_dms-host.scss
SOURCE=/packages/module-dms/res/scss/components/_split-host.scss
SOURCE=/packages/kernel/shared/res/scss/components/_split.scss
SOURCE=/packages/module-dms/res/view/templates/Documents/DriveController/listAction.tpl.php
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
│   ├── _split-host.scss   binds --z77-split-* to --dms-* (the shared pane primitive)
│   ├── _drive.scss        .dms-drive — FRAME + pane look only; layout is .z77-split
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

`tokens/_colors.scss` holds **light values only, and no theme selector of its own** — they are
the fragment's standalone default for a host that binds nothing. Palette and dark mode come from
the HOST binding (ADR-018 rule 4): the backend's `components/_dms-host.scss` redeclares every
`--dms-*` colour in terms of `--be-*` on `.be .dms`, which covers all six palettes × light/dark
in one block. Do NOT add a `[data-be-theme="dark"] .dms` set back here — it would work for one
host only and, at equal specificity but later load order, would override the binding
(DMS-HOST-BIND-001).

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
| `.dms-drive` | FRAME of the 3-pane Drive (border, radius, background) + the per-pane look (`__tree` \| `__list` \| `__preview`). Layout, drag handles and the narrow-screen preview overlay come from the shared `.z77-split` (DMS-SPLIT-001) — the element and each pane carry both classes. The toolbar lives in the backend shell header band, not here. |
| `.dms-tree` | left folder hierarchy; node depth via inline `--dms-depth`; `--active` / `--inactive` / `--has-children.is-open` |
| `.dms-file` (in `.dms-filelist`) | one document row: `__select` (bulk checkbox, hover-revealed / always-on touch), `__thumb` (image thumbnail or kind-tinted icon), `__name` / `__meta`, `__badge--{public,protected,sealed}`, hover `__actions` |
| `.dms-filelist-bulkbar` | sticky bulk-action bar (counter, Alle/Keine, Verschieben, Löschen); revealed purely by `.dms-filelist:has(.dms-file__select:checked)` — no JS (2026-07-16, [`../03-development/dms-bulk-select-bauplan.md`](../03-development/dms-bulk-select-bauplan.md)) |
| `.dms-preview` | right pane: `__media` (image or large kind icon), `__name`, `__meta` grid, `__actions`; `__empty` state |
| `.dms-btn` | labelled action button (`--primary` / `--ghost` / `--muted`) |
| `.dms-icon` / `.dms-iconbtn` | inline SVG icon (sized by font, `currentColor`) / square borderless icon button |

## rules

- When styling any colour, font, spacing, radius, shadow, transition, or z-index in dms CSS → MUST reference a `--dms-*` token; values MUST NOT be hardcoded outside `tokens/_*.scss`.
- When declaring `--dms-*` tokens → MUST place them on the `.dms` wrapper selector in `tokens/_*.scss`; MUST NOT declare design tokens on `:root` (ADR-018).
- When adding a token a dms component needs → MUST add it to `.dms` (keep the set complete); MUST NOT rely on a host token leaking into the fragment.
- When a `--dms-*` colour must follow the host's palette or theme → MUST bind it HOST-side by redeclaring it on a two-class selector in the host's own bundle (backend: `.be .dms { --dms-x: var(--be-y) }` in `components/_dms-host.scss`); MUST NOT reference `--be-*` / `--fe-*` from dms SCSS and MUST NOT add a host-specific theme selector (`[data-be-theme="dark"] .dms`) to `tokens/_colors.scss` — both break every other host (DMS-HOST-BIND-001).
- When adding a `--dms-*` colour token → MUST also add its binding to every host that binds (currently only the backend); an unbound token silently falls back to the fragment's light default and will be wrong in dark mode.
- When touching the Drive layout → MUST change it in the shared `.z77-split` primitive, not in `_drive.scss`; `.dms-drive` MUST NOT declare `display`, `grid-template-*` or pane widths again (dms.css loads after the host bundle and would beat the primitive at equal specificity — DMS-SPLIT-001)
- When a pane needs a class for layout or JS → MUST put it in the pane PARTIAL (`_tree` / `_list` / `_preview`), never only in `listAction.tpl.php`: `panes()` replaces those roots via `replace-html` (outerHTML), so a class set on the page alone is lost after the first navigation
- When adding an element inside `.dms-drive` → MUST keep the order pane / handle / pane / handle / pane; the `nth-child` width rules in `_split.scss` read that order
- When writing a dms component (R6b) → MUST prefix its block class `.dms-…` (component-selector isolation, ADR-018 rule 3); MUST NOT reuse an unprefixed block name that a host also defines (`.btn`, `.card`, …).
- When the fragment must adapt to its container width → MUST use an internal `@media`/container query inside the component; MUST NOT add a page-level breakpoint or assume the host's layout.
- When embedding the fragment in a host → the HOST MUST load `dms.css` via its own `layoutConfig.inc.php`; the dms module MUST NOT carry a page skeleton or its own `layoutConfig`.
- When a dms component renders a NATIVE form control (checkbox, radio, select) → MUST explicitly restore `appearance: auto` (or style the control fully) in the component's SCSS. The backend host's normalize strips `appearance` from ALL form controls page-wide — a bare native control paints NOTHING inside the embedded fragment. Trapped twice: backend `<select>`s (2026-07-13, `_forms.scss`) and the bulk checkbox `.dms-file__select` (2026-07-16, `_filelist.scss`).
- When running build commands → MUST run from the framework root (`npm run build:dms` / `npm run watch:dms`); MUST follow the workflow in [`css-watch.md`](css-watch.md).

## see also

- [`documents.md`](documents.md) — the DMS engine + the R6 rebuild this CSS is part of
- [`css-watch.md`](css-watch.md) — uniform SCSS watch/build workflow + ask-for-watcher convention at session start
- [`css-frontend.md`](css-frontend.md) / [`css-backend.md`](css-backend.md) — mirror topics for the full-page viewAreas; the hosts that can embed the dms fragment
- [`stylesheet.md`](stylesheet.md) — how compiled CSS is loaded into pages (asset pipeline)
- [`../01-handbook/css-conventions.md`](../01-handbook/css-conventions.md) — BEM, tokens, component patterns
- [`../02-decisions/adr-018-css-tokens-scoped-to-viewarea-wrapper.md`](../02-decisions/adr-018-css-tokens-scoped-to-viewarea-wrapper.md) — the binding wrapper-token decision

## known issues

- **DMS-SPLIT-001** — added 2026-08-08. **The Drive no longer owns its layout.** `.dms-drive` is now
  the FRAME only (border, radius, background); pane widths, drag handles, per-pane scrolling and the
  narrow-screen overlay come from the shared `.z77-split` primitive (`kernel/shared`, ADR-018 R5–R7).
  Each pane carries two classes — `.dms-drive__*` for the look, `.z77-split__pane` for the geometry —
  and those classes MUST sit in the pane PARTIALS: `DriveControllerTrait::panes()` replaces
  `.dms-drive__tree|__list|__preview` via `replace-html` (outerHTML), so a class added only in
  `listAction.tpl.php` would vanish after the first folder click. **Do NOT reintroduce `display: grid`
  on `.dms-drive`** — `dms.css` loads after the host bundle, so a `display` declaration there beats
  `.z77-split`'s `flex` at equal specificity and silently breaks the panes. Markup order inside the
  split is pane / handle / pane / handle / pane (+ backdrop): the `nth-child` width rules in
  `_split.scss` read that order, so nothing else may be inserted between them. Token binding lives in
  the new `components/_split-host.scss` (`.dms .z77-split` → `--dms-*`), which deliberately outranks
  the backend's `.be .z77-split` by load order — the innermost area owns its look.

- **DMS-TREE-TOGGLE-001** — added 2026-08-09. **The folder caret was decoration.** Nothing was
  wired to it: `is-open` came from the server for the path to the selection and nowhere else, so
  the only way to see a folder's children was to NAVIGATE into it — one page load per level, and
  the tree refolded around the new selection. Expanding and selecting are now two actions: each
  node with children carries a visually hidden checkbox (`.dms-tree__switch`) that the caret
  labels, and the CSS reads it with `~`. No JS. Deliberately **not** `<details>/<summary>`: a
  summary is the click target for everything inside it and would swallow the row's own name link
  and `⋮` menu — the same reason `.be-list` v2 rejected it. The checkbox stays `checked`
  server-side when the node is on the path to the selection, so navigating still unfolds the way
  there. It is focusable (keyboard reaches the caret) and its node is `position: relative` so
  focusing it cannot make the pane jump. The caret glyph rotates, not the label — the hit area
  must hold still. **Known limit:** the tree pane is replaced wholesale by
  `DriveControllerTrait::panes()`, so manually expanded branches collapse on the next folder
  click, back to whatever is on the path. Fixing that means carrying the open set across the
  refresh — a server round-trip or JS state, not worth it until it actually annoys.
  **Not verified live.**

- **DMS-NARROW-001** — added 2026-08-09. The Drive now names its panes by ROLE and inherits the
  shared narrow-screen behaviour (see [`css-backend.md`](css-backend.md) SPLIT-NARROW-001):
  `.dms-drive__tree` is `--nav`, the file list is `--grow`, the preview is `--detail`. Below
  40rem the folder tree stops squeezing the list into a second narrow column and becomes an
  overlay from the left. Its trigger is `.dms-drive__nav-open` in the LIST pane — inside the
  split, not in the shell's header band next to the breadcrumb, because a container query
  cannot reach the band and the frontend host does not have one. The DMS supplies placement and
  look (`.dms-btn`); whether the button shows at all is the primitive's call. Folder links in
  the tree carry `data-z77-split-close` so picking one shuts the overlay — it has done its job.
  **Not verified live.**

- **DMS-FILL-001** — added 2026-08-09. **A workspace needs a DEFINITE height all the way down, and
  the `.dms` wrapper was breaking that chain.** The backend shell's content column is a flex column
  with a definite height and the action template is its direct child, so a plain `.dms` is a flex
  item on the main axis — content height. `.dms-drive { height: 100% }` then has no definite parent
  to resolve against, falls back to `auto`, the panes grow with the file list, their `overflow: auto`
  never has anything to do, and the shell COLUMN scrolls instead of each pane. Fixed with an opt-in
  `.dms--fill` on the fragment wrapper (`base/_base.scss`), set by `listAction.tpl.php`. It carries
  BOTH `flex: 1 1 auto` and `height: 100%` because the two host shapes resolve differently — flex
  column vs. plain block with a definite height. **Opt-in on purpose:** a `.dms` fragment embedded as
  a block in a page (upload box, small file list) must keep its content height, so this must never
  move onto `.dms` itself. `.z77-split` carries the same pair for the same reason, which covers a
  workspace placed directly in the column without a fragment wrapper.

- **DMS-PREVIEW-NARROW-001** — resolved 2026-08-08 (by DMS-SPLIT-001). **Was:** below 60rem the
  preview pane was hidden with the code comment "kept reachable via row click → modal in JS", but
  `res/assets/js/documents/drive.js` contained **no width-dependent logic at all** (no `matchMedia`,
  no `innerWidth`, no resize handler — verified by grep). The preview was simply gone with no way
  back. Not a DMS-only gap: the backend shell's column 3 had the same hole from the other side (its
  mobile right drawer had no trigger anywhere in the repo — see
  [`css-backend.md`](css-backend.md) SHELL-COL3-REMOVED-001). **Now:** the preview is
  `.z77-split__pane--detail` — it slides in as an overlay, opened by `data-z77-split-open` on the
  file row, closed by the backdrop, the ✕ button or `Esc`. The threshold is a **container query**,
  not `@media`: the Drive's own width decides, and its panes are drag-resizable, so a viewport query
  would fire at the wrong moment. **Not verified live.**

- **DMS-THUMB-TINT-001** — added 2026-08-08. **Don't assume the file-list thumbnails follow the
  theme — five tile backgrounds are hardcoded.** `components/_filelist.scss` (~lines 99–104) paints
  `.dms-file__thumb--image|document|text|archive|audio|video` with literal light hex values
  (`#eef2fb`, `#fdecec`, `#eef0f3`, `#fdf4e3`, `#e8f3ec`) while their icon colour IS a token. They
  therefore stay light tiles in dark mode and ignore the palette even now that DMS-HOST-BIND-001 is
  fixed. This violates this topic's own first rule (no hardcoded colour outside `tokens/_*.scss`).
  Fix belongs in the fragment, not the host: mix against `--dms-bg` from the same token the icon
  already uses, e.g. `background: color-mix(in srgb, var(--dms-accent) 12%, var(--dms-bg))`. Left out
  of the DMS-HOST-BIND-001 pass on purpose so the binding stayed verifiable on its own.

- **DMS-HOST-BIND-001** — resolved 2026-08-08. **Was:** the embedded `.dms` fragment followed the
  werkbank palette only. ADR-018 rule 4 (the host redeclares the embedded area's tokens on `.dms`)
  was implemented by no host — repo-wide there was no `--dms-*` declaration in
  `module-backend/res/scss/` or `module-frontend/res/scss/`, only a comment in `_shell.scss`.
  DMS-PALETTE-001 had mirrored the werkbank values into `--dms-*` as **literal copies**, not
  `var(--be-*)` references, so switching the backend palette to `citrus` / `coral` / `lagune` /
  `beere` / `sonne` repainted the shell while the Drive stayed indigo. **Second, less obvious half:**
  the fragment's own `[data-be-theme="dark"] .dms` dark set did not merely duplicate the host's job —
  at `(0,2,0)` it TIED with the natural binding selector `.be .dms` and loaded later (`dms.css` is
  added per action, after `base.css`), so it would have silently beaten any backend binding in dark
  mode. A binding alone would have looked correct in light and been dead in dark.
  **Fix (both halves):** new `module-backend/res/scss/components/_dms-host.scss` maps every
  `--dms-*` colour onto `--be-*` on `.be .dms` — direct where a twin exists, `color-mix()` where none
  does (`text-soft`, `line-strong`, the `*-bg` tints, `info`), and `accent-dark`/`accent-light` mixed
  toward `--be-text` / `--be-bg` so "more/less contrast" stays correct in dark mode instead of
  literally darkening. The `[data-be-theme="dark"] .dms` block was REMOVED from
  `module-dms/res/scss/tokens/_colors.scss`, which now carries light standalone defaults and no theme
  selector. Net effect: one block covers 6 palettes × 2 themes, and the fragment is genuinely
  host-neutral for the coming frontend embedding. Supersedes the dark-set part of DMS-PALETTE-001.
  **Not verified live yet** — wants a click-through of the Drive across the six palettes in both
  themes (badges `public`/`protected`/`sealed`, button + link hover, focus rings, tree active row).

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
