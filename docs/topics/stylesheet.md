# stylesheet

2026-05-06

## entry

1. `packages/kernel/core/src/Services/StylesheetManager.php` — versioning, snapshot copies (`_at-{mtime}`)
2. `packages/kernel/core/src/Services/LayoutManager.php` — asset registry: `addCss()` / `getCss()`
3. `packages/module-frontend/src/Ui/Config/layoutConfig.inc.php` — CSS registration per module

## file map

SOURCE=/packages/kernel/core/src/Services/StylesheetManager.php
SOURCE=/packages/kernel/core/src/Services/JavascriptManager.php
SOURCE=/packages/kernel/core/src/Services/AssetVersionService.php
SOURCE=/packages/kernel/core/src/Services/LayoutManager.php
SOURCE=/packages/kernel/core/src/Services/HtmlView.php
SOURCE=/packages/kernel/core/src/Services/AssetCleaner.php
SOURCE=/packages/module-frontend/src/Ui/Config/layoutConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Config/layoutConfig.inc.php
SOURCE=/packages/module-frontend/res/view/templates/html-default-skeleton.tpl.php
SOURCE=/packages/module-backend/res/view/templates/html-shell-skeleton.tpl.php

## mental model

CSS files are versioned by mtime. `StylesheetManager::getVersionedCss()` creates a snapshot copy `mobile_at-{mtime}.css` alongside the source and cleans up stale versions on each call. `LayoutManager` is the asset registry — `addCss()` registers files, `getCss()` returns the ordered list. `HtmlView::renderCss()` renders `<link>` tags into the `$css` skeleton variable in `<head>`.

- Production source files MUST be `.min.css` — framework never minifies.
- FileFinder resolves assets from a single tier `public/assets/{module}` (no public vendor tier — ADR-024); override precedence is at the source level (`override` before `vendor` in `sourcePaths`).
- AssetCleaner runs on cache-clear and removes stale `*_at-{stamp}.{css,js,map}` files (with 30s grace period).

## flow — how CSS gets into the page

```text
layoutConfig.inc.php
  → LayoutManager::applyLayoutConfig() → addCss(name, nameSpace, media)
    → StylesheetManager::getVersionedCss()
      → FileFinder::getFirstAssetMatch()   resolves source path
      → AssetVersionService::version()     filemtime → e.g. 1741606223
      → creates versioned copy:            mobile_at-1741606223.css
    → stores: ['path' => '/css/mobile_at-1741606223.css', 'mediaQueryOption' => '...']
  → HtmlView::renderCss()
    → <link rel="stylesheet" href="/css/mobile_at-1741606223.css" media="...">
  → skeleton $css variable
```

## layoutConfig — stylesheet registration

```php
'styleSheets' => [
    ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'mobile',  'media' => 'screen and (max-width: 767px) and (max-height: 450px)'],
    ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'desktop', 'media' => 'screen and (min-width: 768px) and (min-height: 451px)'],
],
```

`name` = filename without `.css`. `media` = empty string → no media attribute.

## dynamic CSS from a controller

```php
DI::getLayoutManager()->addCss('print', 'Z77\\Module\\Frontend', 'print');
```

## versioning

| Aspect | Detail |
|---|---|
| Source | `mobile.css` |
| Versioned copy | `mobile_at-{mtime}.css` (same dir) |
| Cleanup | Old versioned files removed on each `getVersionedCss()` call (30s grace) |
| Cache-busting | Without query strings — works with aggressive CDN caching |

## debug vs. production

| debug | suffix | example |
|---|---|---|
| `true` | _(none)_ | `mobile_at-1741606223.css` |
| `false` | `.min` | `mobile.min_at-1741606223.css` |

In production the source must be `mobile.min.css`. Minification is not done by the framework.

## asset path resolution

FileFinder searches `assetPaths` for the namespace. Assets are a **single tier** — there is no
`public/assets/vendor/*` (ADR-024):

| Namespace | Asset path |
|---|---|
| `Z77\Module\Frontend` | `public/assets/frontend` |
| `Z77\Module\Backend` | `public/assets/backend` |

Both framework and project assets live here: the installer seeds the framework baseline on the
first install (never afterwards — `public/` is developer-owned), and the project's build layers
its own files on top. The override happens at the **source** level (`override/z77/*` vs
`vendor/z77/*` in `sourcePaths`), not via a second asset directory. Config:
`skeleton/config/fileFinder.inc.php`.

## responsive approach

Seven CSS files — base always loaded, layout/nav files conditionally applied via media attribute:

| File | media attribute |
|---|---|
| `base.css` | _(none — always loaded)_ |
| `mobile.css` | `screen and (max-width: 767px)` |
| `tablet.css` | `screen and (min-width: 768px) and (max-width: 1199px)` |
| `desktop.css` | `screen and (min-width: 1200px)` |
| `nav-mobile.css` | `screen and (max-width: 767px)` |
| `nav-tablet.css` | `screen and (min-width: 768px) and (max-width: 1199px)` |
| `nav-desktop.css` | `screen and (min-width: 1200px)` |

## generated CSS (data-driven)

For CSS that depends on runtime data (e.g. CSS-only sliders where selectors and values are derived from DB entities).

```php
DI::getLayoutManager()->createCss(
    name:      'slider-home',
    nameSpace: 'Z77\\Module\\Frontend',
    template:  'css/slider',           // resolves via FileFinder to slider.css.tpl.php
    data:      ['sliders' => $sliders],
    version:   $maxUpdatedAt           // Unix timestamp from max(updatedAt) of entities
);
```

```text
createCss(name, nameSpace, template, data, version)
  → StylesheetManager::createCss()
    → FileFinder resolves template path
    → if file slider-home_at-{version}.css already exists → done (no regeneration)
    → else: render template with data → write to css output dir
    → clean up old versioned files (slider-home_at-*.css)
    → return web path: /css/slider-home_at-{version}.css
  → LayoutManager registers path in $assets['css']
  → HtmlView::renderCss() → <link rel="stylesheet" href="/css/slider-home_at-{version}.css">
```

- Version trigger: pass `max(updatedAt)` of relevant entities — not filemtime.
- If no entity changed, the versioned file already exists and is returned immediately (no disk write).
- Template location: `res/view/templates/{controller}/css/{template}.css.tpl.php` (via FileFinder).
- Template receives `$data` as extracted variables and renders plain CSS.

## rules

- When deploying production assets → MUST ensure source files are named `*.min.css` (framework does not minify)
- When emitting CSS to the page → MUST register via `LayoutManager` (rendered in `<head>`); MUST NOT use inline `<style>` blocks
- When defining base CSS → MUST register in `layoutConfig.inc.php`; `addCss()` from controllers is for page-specific additions only
- When resolving asset paths → MUST use the single tier `public/assets/{module}` (no public vendor tier — ADR-024); a project asset MUST override the framework one by sharing the filename there (the project build wins because the installer seeds `public/` once and never overwrites)
- When generating data-driven CSS → MUST output to a versioned file via `createCss()`; MUST NOT emit `<style>` blocks in body

## asset-pipeline architecture (refactored 2026-05-06)

```text
LayoutManager
  ├─ AssetVersionService(debug)        ← version stamp (filemtime / requestTime)
  ├─ AssetCleaner()                    ← single cleanup owner
  ├─ StylesheetManager(av, cleaner)    ← CSS-only
  └─ JavascriptManager(av, cleaner, debug)  ← JS + minSuffix

HtmlView (immutable, snapshot via LayoutManager::buildView())
HtmlResponse → buildView() → render() (lazy at send time)
```

| Decision | Reason |
|---|---|
| `version()` debug-aware (`time()` vs `filemtime()`) | aggressive cache-busting in debug; stable mtime in prod |
| `requestTime` cached once per request | every asset in the request gets the same stamp — deterministic |
| Service-split CSS/JS | semantic SRP; same versioning logic, different file types |
| Snapshot pattern for `HtmlView` | no circular Manager-View dependency; testable |
| `buildView()` lazy in `getHtml()` | postExecute can still register assets before snapshot |
| Module `layoutConfig.inc.php` is mandatory | typo in module name → hard exception, not half-broken page |
| Atomic copy: temp + rename | race protection for parallel versioning |
| Single cleanup owner: `AssetCleaner` | no duplication; consistent grace period |
| 30s grace period in cleanup | protects parallel browser requests still loading the old URL |
| Source maps versioned alongside | when `*.map` exists next to `*.min.js` / `*.css` → copied + cleaned together |

Race scenarios:

| Scenario | Behavior |
|---|---|
| Cache-clear during parallel browser request | freshly created files (<30s) preserved → browser can finish loading |
| Render creates new version, old is 5min old | old version safely deleted (>30s, no references) |
| Render creates new version, old is 2s old (parallel render) | old preserved → cleaned at next render |

## see also

- [`view-layer.md`](view-layer.md) — `HtmlView::renderCss` + skeleton variables
- [`css-backend.md`](css-backend.md) — backend SCSS sources + watch/build commands
- [`backend.md`](backend.md) — `clearCacheAction` triggers `AssetCleaner::clearAll()`
- [`../01-handbook/css-conventions.md`](../01-handbook/css-conventions.md) — BEM, tokens, component patterns

## known issues

- ARCH-009: `LayoutManager` is a God-Object (~430 lines, 5 responsibilities). Acceptable today; split when multiple parallel layout strategies appear.
- **STYLES-DEAD-001** — resolved 2026-05-16. Hardcoded `<link>` tags to `/css/reset.css` / `/css/styles.css` no longer exist (asset-pipeline refactor 2026-05-06). Dead `'styles' => 'partials/head/styles'` reference removed from `module-backend/layoutConfig.inc.php`; empty `partials/head/styles.tpl.php` deleted.
- **CSS-PREFIX-001** — resolved 2026-05-17. `.be-form__*`, `.be-modal__*` and `.be-modal__alert*` migrated from `list.css` into SCSS components (`_forms.scss`, `_modal.scss`, `_alerts.scss`) using `--be-*` tokens. `list.css` now contains only navigation-specific styles (`.be-nav-*`, `.be-icon-btn`, `.be-btn`, `.be-tag`, `.be-children-table`). Login template migrated from `.form__*` to `.be-form__*` (consistency). New token `--be-danger` added to `_colors.scss` (palette-independent).
- **CSS-SWITCH-001** — added 2026-05-21. New reusable `.be-switch` component in `components/_switch.scss` — on/off toggle slider wrapping a hidden checkbox. Modifiers: `--sm` (smaller), `--block` (full-row, label left). Tokens-based (`--be-accent`, `--be-line`, `--be-text`), keyboard-accessible (`:focus-visible`). Form submission via the hidden checkbox — no JS. HTML snippet documented as header comment in `_switch.scss`. First use: Aktiv-Flag in navigation edit popup.
- **INST-ASSET-002** — resolved 2026-07-14 (ADR-024, owner [`installer.md`](installer.md)). `composer install` no longer overwrites project-built CSS/JS: `public/` is developer-owned and seeded by the installer only on the first install (when absent), never afterwards. Asset resolution is single-tier `public/assets/{module}` — the dead public vendor tier was removed. `LayoutManager::toWebPath()` unchanged (web URL derived from the actual match by stripping `ABS_PUBLIC_PATH`).

## pending

- ARCH-009: split `LayoutManager` (deferred — only when needed)
