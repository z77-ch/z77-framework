# Concept: View Layer

**Status:** `[IMPLEMENTED]`
**Date:** 2026-04-29

---

## Overview

The view layer turns a controller's `HtmlResponse` into rendered HTML. It is the **only**
output pipeline for `RequestMode::Page` and `RequestMode::Fetch`. Other output types (JSON,
files, redirects, PDF, e-mail) have their own pipelines and do not use the view layer.

Pure PHP templates — no template engine, no compiler. `extract($context, EXTR_SKIP)` makes
context variables available, `require` includes the file, output buffering captures the
result.

---

## Pipeline

```
Action::indexAction()
  └─ return $this->html(['title' => 'Hi'])
       └─ HtmlResponse(layoutManager, context)

Dispatcher::execute()
  └─ HtmlResponse::send()
       └─ LayoutManager::render(context)
            └─ HtmlView::render()
                 ├─ Render head partials  → $head (concatenated)
                 ├─ Render body partials  → one variable per section
                 ├─ Render CSS asset tags → $css
                 ├─ Render JS asset tags  → $jsHead, $jsFooter
                 └─ Render skeleton with all variables
```

---

## Three Layout Modes

The same code supports three different "shapes" of how a page is composed.

### Mode 1 — Pure Config

Module config defines all partials. The action only returns context.

```php
// packages/module-X/src/Ui/Config/layoutConfig.inc.php
return [
    'documentTpl'   => ['name' => 'html-default-skeleton', 'nameSpace' => 'Z77\\Module\\X'],
    'levelElements' => [
        'head' => [
            'meta'    => 'partials/head/meta',
            'seo'     => 'partials/head/seo',
        ],
        'body' => [
            'header' => 'partials/header',
            'footer' => 'partials/footer',
        ],
    ],
];

// IndexController::indexAction()
public function indexAction(): HtmlResponse
{
    return $this->html(['title' => 'Welcome']);
}
```

### Mode 2 — Config + Action extension

Config defines defaults. The action adds, removes, or replaces situationally.

```php
public function profileAction(): HtmlResponse
{
    // Add a sidebar only on this page
    $this->layoutManager->addPartials(
        'sidebar', 'partials', $this->getNameSpace(),
        section: 'sidebar', level: 'body'
    );

    return $this->html(['user' => $user]);
}

public function impressumAction(): HtmlResponse
{
    // Drop the cookie banner provided by module config
    $this->layoutManager->removeSection('cookieBanner');

    return $this->html();
}
```

### Mode 3 — Everything in the action template

Markup lives entirely in `{Controller}/{action}.tpl.php`. Sections not registered in the
config simply remain empty (`?? ''` in the skeleton).

```php
// IndexController/landingAction.tpl.php
<section class="hero">...</section>
<section class="features">...</section>
<section class="cta">...</section>
```

The skeleton wraps it (`<!DOCTYPE html>`, `<html>`, `<head>`, `<body>`), the action template
fills `$main`, all other body section variables stay empty.

---

## Template Path Resolution

Paths in `layoutConfig` and `addPartials()` are resolved by **FileFinder** against the
module's `res/view/templates/` directory.

### How a path becomes a file

```
nameSpace: 'Z77\\Module\\Frontend'
path:      'partials/head'
name:      'meta'
                  ↓
packages/module-frontend/res/view/templates/partials/head/meta.tpl.php
```

### Three forms in `levelElements`

```php
'levelElements' => [
    'body' => [
        // Form A — string shortcut (single partial, current module)
        'header' => 'partials/header',

        // Form B — array of strings (multiple partials in one section)
        'main' => ['partials/intro', 'partials/cta'],

        // Form C — full form (foreign namespace, or explicit fields)
        'sidebar' => [
            ['nameSpace' => 'Z77\\Module\\Shared', 'path' => 'partials', 'name' => 'sidebar'],
        ],

        // Mix — strings and full form together in one section
        'footer' => [
            'partials/footer',
            ['nameSpace' => 'Z77\\Module\\Shared', 'path' => 'partials', 'name' => 'cookieBanner'],
        ],
    ],
],
```

In Form C, `nameSpace` is optional — defaults to current module.

---

## Variables Available in Templates

| Template type | Available variables |
|---|---|
| Skeleton | All from action context + body section variables (`$header`, `$main`, `$footer`, `$sidebar`, …) + reserved layout variables |
| Body partial | All from action context |
| Head partial | All from action context |
| Action template | All from action context |

### Reserved layout variables (skeleton only)

These are produced by `HtmlView` and override any colliding context key:

- `$head` — concatenated head partials
- `$css` — `<link>` tags for all CSS assets
- `$jsHead` — `<script>` tags for JS with `position: 'head'`
- `$jsFooter` — `<script>` tags for JS with `position: 'footer'`

**Reserved body section keys** (must NOT be used in `levelElements.body`):
`head`, `css`, `jsHead`, `jsFooter`.

---

## Template Helpers

Globally available in all templates and controllers (loaded by Bootstrap before dispatch).

### Escape & raw output

```php
<?= e($title) ?>           // HTML-escape — htmlspecialchars with safe defaults
<?= raw($trustedHtml) ?>   // pass-through — explicit "I trust this content"
```

`e()` does **not** strip tags. `htmlspecialchars` alone neutralizes them by escaping `<` to
`&lt;`. Stripping first would silently destroy user input.

### Placeholder replacement

```php
<?= replacePlaceholders('Hi {$name}', ['name' => $userName]) ?>
// → 'Hi Peter'   (escaped by default)

<?= replacePlaceholders('Mail: {$email}', ['email' => $htmlMailLink], escape: false) ?>
// → 'Mail: <a href="mailto:…">…</a>'
```

### Including other templates from inside a template

`$this` inside any template is the `TemplateRenderer` — exposes `partial()`:

```php
// Same module
<?= $this->partial('partials/userCard', ['user' => $user]) ?>

// Foreign module
<?= $this->partial('partials/cookieBanner', [], 'Z77\\Module\\Shared') ?>

// No context
<?= $this->partial('partials/footer') ?>
```

Context is **not** inherited from the parent template — pass explicitly. This keeps partials
isolated and reusable.

---

## Skeleton Templates

Two skeletons per module, selected by `RequestMode`:

| Skeleton | When used | Contents |
|---|---|---|
| `html-default-skeleton.tpl.php` | `Page` requests (browser navigation) | Full HTML document |
| `html-fetch-skeleton.tpl.php` | `Fetch` requests (JS `fetch()`/XHR) | Minimal — just `$main` |

The action template is the same in both modes. `LayoutManager::initialize()` chooses the
skeleton based on `Request::getMode()`.

→ See [request-mode.md](request-mode.md) for detection details.

---

## Asset Pipeline

CSS and JS assets are auto-versioned via `filemtime()` of the source file. A versioned copy
(`{name}_at-{mtime}.css`) is created on first request and reused until the source changes.
Old versioned copies are cleaned up automatically.

### Why filename versioning, not query strings

CDNs and proxies often ignore query strings in cache keys (`main.css?v=1234`). Filename
versioning produces a unique URL per version, guaranteed to bypass any cache layer.

### Add CSS / JS

```php
// In module config:
'styleSheets' => [
    ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'main', 'media' => ''],
],
'javascripts' => [
    ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'main', 'position' => 'footer', 'defer' => true],
],

// In action:
$this->layoutManager->addCss('extra', 'Z77\\Module\\Frontend');
$this->layoutManager->addJs('analytics', 'Z77\\Module\\Frontend', position: 'head', async: true);
```

### Remove

```php
$this->layoutManager->removeCss('desktop');         // by name (as passed to addCss)
$this->layoutManager->removeJs('analytics');
$this->layoutManager->removeSection('sidebar');     // entire body/head section
```

### Production: minified suffix

Source files in production are expected as `name.min.css` / `name.min.js`. In debug mode,
non-minified `name.js` is used. The `AssetVersionService::minSuffix()` returns the right
suffix automatically.

---

## Module Directory Structure

```
packages/module-X/
├── composer.json
├── src/
│   └── Ui/
│       ├── Config/
│       │   ├── layoutConfig.inc.php          ← Module-wide layout
│       │   └── {controller}Config.inc.php    ← Controller-wide (optional)
│       └── Controllers/
│           └── {Name}Controller.php
└── res/
    ├── view/
    │   └── templates/
    │       ├── html-default-skeleton.tpl.php
    │       ├── html-fetch-skeleton.tpl.php
    │       ├── {Controller}/
    │       │   └── {action}.tpl.php          ← Action template (auto-resolved)
    │       └── partials/
    │           ├── header.tpl.php
    │           ├── footer.tpl.php
    │           └── head/
    │               ├── meta.tpl.php
    │               └── seo.tpl.php
    └── assets/
        ├── css/
        ├── js/
        └── img/
```

The `{Controller}/{action}.tpl.php` convention means: an action template for `IndexController::indexAction()`
is found at `IndexController/indexAction.tpl.php`. No registration needed — `LayoutManager::initialize()`
auto-registers it as `body.main` if the section is empty.

---

## Files

| File | Role |
|---|---|
| `packages/kernel/core/src/Services/LayoutManager.php` | initialize(), addPartials/Css/Js, removeSection/Css/Js, render() |
| `packages/kernel/core/src/Services/HtmlView.php` | render() with dynamic body section variables |
| `packages/kernel/core/src/Services/TemplateRenderer.php` | render(), partial() — exposed as `$this` in templates |
| `packages/kernel/core/src/Services/StylesheetManager.php` | getVersionedCss/Js — copies source to `_at-{mtime}` filename |
| `packages/kernel/core/src/Services/AssetVersionService.php` | filemtime per source path, minSuffix |
| `packages/kernel/core/src/Http/Response/HtmlResponse.php` | Triggers LayoutManager::render() |
| `packages/kernel/core/src/autoload/prod/php/Helper.php` | e(), raw(), replacePlaceholders() globals |

---

## Related

- [request-mode.md](request-mode.md) — Page vs. Fetch detection and skeleton choice
- [layoutmanager-html-workflow.md](layoutmanager-html-workflow.md) — Full request-to-response flow
- [navigation-router.md](navigation-router.md) — Navigation lookup before view rendering
