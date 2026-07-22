# view-layer

2026-07-17

## entry

1. `packages/kernel/core/src/Services/LayoutManager.php` — layout config application, asset registration
2. `packages/kernel/core/src/Services/HtmlView.php` — template rendering, partial assembly, `<link>` output
3. `packages/kernel/core/src/Controller/AbstractBaseController.php` — `html()`: context injection, returns `HtmlResponse`

## file map

SOURCE=/packages/kernel/core/src/Services/LayoutManager.php
SOURCE=/packages/kernel/core/src/Services/HtmlView.php
SOURCE=/packages/kernel/core/src/Services/TemplateRenderer.php
SOURCE=/packages/kernel/core/src/Services/PartialLabels.php
SOURCE=/packages/kernel/shared/res/assets/js/partial-labels.js
SOURCE=/packages/module-frontend/src/Ui/Controllers/Main/AdminPanelController.php
SOURCE=/packages/kernel/core/src/Http/Response/HtmlResponse.php
SOURCE=/packages/kernel/core/src/Controller/AbstractBaseController.php
SOURCE=/packages/module-frontend/src/Ui/Config/layoutConfig.inc.php
SOURCE=/packages/module-frontend/res/view/templates/
SOURCE=/packages/module-frontend/res/view/templates/html-default-skeleton.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/html-fetch-skeleton.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/partials/header.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/partials/footer.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/partials/head/meta.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/partials/head/seo.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/partials/head/favicon.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/partials/head/social.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/partials/head/structured-data.tpl.php
SOURCE=/packages/module-backend/src/Ui/Config/layoutConfig.inc.php
SOURCE=/packages/module-backend/res/view/templates/html-shell-skeleton.tpl.php
SOURCE=/packages/module-backend/res/view/templates/partials/flashMessages.tpl.php
SOURCE=/packages/module-backend/res/view/templates/partials/popupMessages.tpl.php
SOURCE=/docs/01-handbook/templates.md

## mental model

Rendering is config-driven. `layoutConfig.inc.php` declares the document skeleton, partials (head, header, footer), and CSS. `AbstractBaseController::html()` injects the standard context (`$navigationService`, `$navigation`, `$language`, `$metaData`) and returns an `HtmlResponse`. `HtmlView` assembles partials into the skeleton.

- Page mode → `html-default-skeleton.tpl.php` (full document).
- Fetch mode → `html-fetch-skeleton.tpl.php` (`$main` only).
- All template output uses `e()` for escaping — never `htmlspecialchars` directly.

The action template is resolved group-nested, mirroring the controller location:
`res/view/templates/{Group}/{Controller}/{action}.tpl.php`. `LayoutManager`
derives the group via `Naming::toControllerGroupSegment()` (namespace segment
between `\Controllers\` and the class base name) and builds the FileFinder
sub-path in `controllerTplDir()`. Module-wide partials (`partials/…`) stay flat.
See ADR-005 (revised 2026-06-02).

## three layout modes

| Mode | Where layout lives |
|---|---|
| Pure config | `layoutConfig.inc.php` only |
| Config + action overrides | controller calls `addPartials()` / `removeSection()` / `addCss()` |
| All in action template | layout assembled in the action `.tpl.php` |

## RequestMode → skeleton

| RequestMode | Skeleton |
|---|---|
| `Page` | `html-default-skeleton.tpl.php` (full document) |
| `Fetch` | `html-fetch-skeleton.tpl.php` (`$main` only) |

## layoutConfig structure

```php
'documentTpl' => ['name' => 'html-default-skeleton', 'nameSpace' => '...']
'styleSheets' => [['nameSpace','name','media']]
'levelElements' => [
    'head' => ['meta'=>'partials/head/meta', 'seo'=>'partials/head/seo', ...]
    'body' => [
        'header'   => 'partials/header',
        // 'main' is intentionally absent — resolved dynamically from controller/action
        'footer'   => 'partials/footer',
        'flash'    => 'partials/flashMessages',   // top-right user feedback
        'messages' => 'partials/popupMessages',   // bottom-left persistent notifications
    ]
]
```

The keys under `body` become template variables in the document skeleton. Backend skeleton renders `<?= $flash ?>` and `<?= $messages ?>` next to `$header` / `$footer`. Modules that omit these keys do not render feedback containers — see [`messages.md`](messages.md) FE-MSG-001 for the frontend gap.

## escaping

| Function | Use |
|---|---|
| `e($value)` | always for variable output |
| `raw($html)` | trusted HTML only — must be justified at call site |

## standard context (auto-injected)

`$navigationService` | `$navigation` | `$language` | `$metaData`

Reference: `docs/01-handbook/templates.md`.

## assets

CSS/JS versioned by filemtime → `{name}_at-{mtime}.css`. Bypasses CDN cache.
JS only: production expects `{name}.min.js`, debug uses `{name}.js`; a missing `.min.js`
falls back to the unminified source (error-logged). CSS has no `.min` variant — the sass
build compiles compressed into the same filename. Details: [`stylesheet.md`](stylesheet.md).

## partial labels (dev tool)

Built-in overlay showing which partial rendered each block — orientation for
developers/designers, zero project code. Built 2026-07-17 from the zihlundsee
prototype handoff (`{projekt}/work/docs/topics/partial-labels.md`).

- **Mechanics:** when active, `TemplateRenderer::partial()` and
  `HtmlView::renderPartials()` (body level) wrap every partial's output in
  comment markers `<!--z77p:partials/intro-->…<!--/z77p-->` (`PartialLabels::wrap`);
  the overlay script `partial-labels.js` (shared asset, auto-registered by
  `LayoutManager::initialize()`) pairs the markers, measures each partial via a
  DOM Range and floats a label at its top-left corner (nested partials indent by
  depth; debounced reposition on resize + body ResizeObserver).
- **Gate (`PartialLabels::active()`, all three required, PARTIAL-LABELS-002):**
  `DEBUG` **AND** session role >= admin **AND** the per-user preference
  `partial_labels[viewArea]` on the `LoginUser` (`UserPreferences`, view area =
  request module key, ADR-022). Each user toggles the overlay for themselves —
  two simultaneously logged-in admins can have opposite states. The DEBUG part
  is load-bearing: under DEBUG the page cache is bypassed, so markers/script can
  never be cached into visitor pages (second net: admin sessions never enter the
  shared PageCache at all — [`cache.md`](cache.md) CACHE-ADMIN-001). Inactive →
  output byte-identical (verified).
- **Toggle:** section «Entwicklung» in the frontend admin overlay
  (`adminOverlay` partial, rendered only under DEBUG) — a plain form POST to
  `AdminPanelController::togglePartialLabelsAction`
  (`/frontend/main/admin-panel/toggle-partial-labels`, role ADMIN via
  frontendConfig, CSRF validated in the action, 303 back to the originating
  page). No JS — the reload is what makes the labels appear/disappear. The
  backend has NO toggle (v1): backend-area labels stay off until a switch is
  added there; the preference structure already supports it.
- **Resilient:** a missing `partial-labels.js` deployment logs an error and the
  tool stays off — it never takes a page down.

## rules

- When outputting variables in templates → MUST use `e($value)`; MUST NOT call `htmlspecialchars` directly
- When using `raw()` → MUST be justified inline at the call site (trusted HTML only)
- When handling Fetch mode → MUST render only `$main`; MUST NOT render the full document skeleton
- When writing a template → MUST NOT fetch standard context yourself (`AbstractBaseController::html()` injects it)
- When placing an action template or controller-owned partial → MUST nest it under `res/view/templates/{Group}/{Controller}/`, mirroring the controller namespace; MUST NOT place it flat by controller base name
- When adding a controller-owned partial via `addPartials()` → MUST prefix the path with the group (`'Content/NavigationController'`); module-wide partials stay flat under `partials/`

## see also

- [`stylesheet.md`](stylesheet.md) — `LayoutManager::addCss` / asset pipeline details
- [`fetch.md`](fetch.md) — Fetch-mode skeleton + JS architecture
- [`messages.md`](messages.md) — `flashMessages` / `popupMessages` partials, container ids, BEM classes for both backend (`be-` prefix) and frontend (`fe-` prefix)
- [`documents.md`](documents.md) — `mediaUrl('root-slug/folder-slug/file.ext')` global helper for DMS-managed image URLs in templates (`e()`-escape + null-guard)
- [`forms.md`](forms.md) — `partials/publicForm` renders a whole form from its declaration; a project template that replaces it must keep the `data-*` attribute contract the framework JS binds to

## known issues

- **PARTIAL-LABELS-001** — built 2026-07-17 (see «partial labels» section). Verified: gate
  matrix (flag/DEBUG/roles) + marker wrapping via CLI harness against the real zihlundsee
  templates; live guest request with flag set stays marker- and script-free. Open: visual
  pass of the overlay itself as an admin (labels position/indent/resize) — one panel-toggle
  click away. Fetch-injected partials carry markers too, but the overlay repositions only on
  resize/body-size changes; usually the popup resize triggers it, no dedicated hook (v1).
  _The global-flag gate described here was replaced by PARTIAL-LABELS-002; the visual pass
  stays open._
- **PARTIAL-LABELS-002** — rebuilt 2026-07-18. The global flag
  (`data/framework/partial-labels.flag` + backend service-panel toggle) became a
  **per-user, per-viewArea preference** (`UserPreferences::partial_labels`, stored on the
  `LoginUser`): each admin decides for themselves, concurrent admins are independent.
  Toggle moved into the frontend admin overlay (form POST, no JS, only rendered under
  DEBUG); backend toggle, flag file, and `PartialLabels::flagFile()/flagSet()` removed —
  a leftover flag file is inert and can be deleted. Gate now DEBUG AND admin AND
  preference (see «partial labels» section). Verified via CLI gate-matrix harness.
  Bauplan: [`../03-development/partial-labels-preference-bauplan.md`](../03-development/partial-labels-preference-bauplan.md).

## pending

_(none)_
