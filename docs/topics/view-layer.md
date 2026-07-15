# view-layer

2026-05-17

## entry

1. `packages/kernel/core/src/Services/LayoutManager.php` — layout config application, asset registration
2. `packages/kernel/core/src/Services/HtmlView.php` — template rendering, partial assembly, `<link>` output
3. `packages/kernel/core/src/Controller/AbstractBaseController.php` — `html()`: context injection, returns `HtmlResponse`

## file map

SOURCE=/packages/kernel/core/src/Services/LayoutManager.php
SOURCE=/packages/kernel/core/src/Services/HtmlView.php
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
Production expects `.min.css` / `.min.js`; debug uses non-minified.

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

## known issues

_(none)_

## pending

_(none)_
