# Review — View Layer (OLD)

**Date:** 2026-04-14 — **Superseded:** 2026-05-03
**Status:** `[SUPERSEDED]`

> **Note:** This review describes the old View Layer architecture (`View.php`, `RenderedCss.php`).
> The view layer was completely refactored 2026-04-29. The files reviewed here no longer exist.
> Current architecture: `docs/03-development/concepts/view-layer.md`

---

**Files reviewed (all since replaced or removed):**
`View.php`, `LayoutManager.php` (old), `AbstractBaseController.php`, `RenderedCss.php`, `StylesheetManager.php` (old)

---

---

## Overview — Architecture Intent

The intended rendering pipeline is:

```
AbstractBaseController::run()
  └─ LayoutManager::initialize()        ← loads layoutConfig.inc.php (module + controller)
       ├─ addPartials()                  ← resolves partial tpl paths via FileFinder
       ├─ addCss() / addJs()             ← resolves asset paths via StylesheetManager / FileFinder
       └─ getView()
            └─ View::render()
                 ├─ foreach partials → ob_start → require → capture
                 ├─ ob_start → require body template → capture
                 ├─ renderCss() → <link> tags
                 ├─ renderJs() → <script> tags
                 └─ require layout skeleton (html-default-skeleton.tpl.php)
```

Template hierarchy in `layoutConfig.inc.php`:
```
level: head
  section: meta, seo, favicon, styles, scripts, social
level: body
  section: header, main, footer
```

**The concept is solid.** The architecture makes sense. The problem: implementation has multiple bugs and inconsistencies that prevent it from running.

---

## Bug Inventory

### BUG-V001 — `LayoutManager::addPartials()` result never assigned

```php
// LayoutManager.php:408
$partialFilePahts[$level][$section][] = $filePath;  // ← local variable!
```

Two problems in one line:
1. Typo: `$partialFilePahts` instead of `$partialFilePaths`
2. No `$this->` prefix — creates a local variable inside the method, discarded on return

`$this->partialFilePaths` is **never populated**. `getPartialPaths()` always returns `[]`.

---

### BUG-V002 — `LayoutManager::initialize()` checks wrong variable

```php
// LayoutManager.php:105
if (empty($this->partialFilePahts)) {   // ← typo, checks non-existent property
```

Due to the typo this condition is always true (PHP returns empty for undefined properties).
Consequence: the action template is always appended, even when `layoutConfig.inc.php` already defined partials.

---

### BUG-V003 — `LayoutManager::getView()` wrong constructor call

```php
// LayoutManager.php:468-479
if ($this->view === null) {   // ← typed property, never null — throws on access if uninitialized
    $this->view = new View(
        $this->module,          // ← View::__construct expects (LayoutManager, bool)
        $this->controller,      //   5 args passed, 2 expected
        $this->action,
        $this,
        $this->debug
    );
    $this->view->setDocumentTplPath($this->documentTpl);  // ← $this->documentTpl doesn't exist
}                                                          //   should be $this->documentTplPath
```

Three bugs:
1. Typed `View $view` — PHP will throw on `=== null` comparison before assignment
2. Wrong constructor arguments — `View::__construct(LayoutManager $layoutManager, bool $debug)`
3. `$this->documentTpl` undefined, correct property is `$this->documentTplPath`

---

### BUG-V004 — `View.php` wrong namespace

```php
// View.php:3
namespace Z77\Core\Service;   // ← missing 's'
```

Rest of framework uses `Z77\Core\Services`. The `LayoutManager` imports `use Z77\Core\Services\View` — class won't be found at runtime.

---

### BUG-V005 — `View` expects methods that don't exist in `LayoutManager`

```php
// View.php:22-26
$this->layoutTpl    = $layoutManager->getHtmlSkeletonPath();  // ← doesn't exist
$this->partials     = $layoutManager->getPartialPaths();      // ← exists, returns []
$this->bodyTemplate = $layoutManager->getBody();              // ← doesn't exist
```

And in `render()`:
```php
$this->layoutManager->getCss()  // ← doesn't exist (assets['css'] is private)
$this->layoutManager->getJs()   // ← doesn't exist (assets['js'] is private)
```

**Missing public methods in LayoutManager:**
- `getHtmlSkeletonPath()` — should return `$this->documentTplPath`
- `getBody()` — should return the resolved action template path (module/controller/action)
- `getCss()` — should return `$this->assets['css']`
- `getJs()` — should return `$this->assets['js']`

---

### BUG-V006 — `AbstractBaseController` — render commented out, `$LM` not assigned

```php
// AbstractBaseController.php:57-81
$LM = new LayoutManager(...);   // ← local variable, never assigned to $this->layoutManager
$LM->initialize();

// ...

/*$view = $this->layoutManager->getView()    // ← commented out, uses $this->layoutManager (null)
    ->assign($this->context)
    ->render()
;*/
```

Two bugs:
1. `$LM` is a local variable — `$this->layoutManager` stays uninitialized
2. Render call is commented out — **nothing renders**

---

### BUG-V007 — `AbstractBaseController` — undefined constant and missing import

```php
// AbstractBaseController.php:49
$entityManagerClass = Naming::toNamespaceString(...);  // ← Naming not in use-block

// AbstractBaseController.php:62
if (IS_AJAX_HTTP_REQUEST) {    // ← constant never defined
```

`Naming` is not in the `use` imports. `IS_AJAX_HTTP_REQUEST` is defined nowhere in the codebase.

---

### BUG-V008 — `LayoutManager::getModuleDirs()` uses undefined static property

```php
// LayoutManager.php:218
if (isset(self::$pathCache[$cacheKey])) {   // ← $pathCache not declared
```

`$pathCache` is not declared as a static property anywhere in LayoutManager.

---

### BUG-V009 — `LayoutManager::getViewdocumentSettings()` uses undefined variable

```php
// LayoutManager.php:502-505
$viewConfig = DI::getConfigManager()->getArrayConfig(...);
$viewDocument = $module['viewDocument'] ?? null;   // ← $module undefined, should be $viewConfig
```

This method is currently not called by `initialize()`, so no crash — but it's broken.

---

### BUG-V010 — `RenderedCss` unfinished

```php
// RenderedCss.php:13
$this->versionTimestamp = $versionTimestamp ?? time();   // ← property not declared

// RenderedCss.php:22-24
$needed = (
    ($this->items[$key]['timestamp'] === 0) ||
    ($this->currentTimestamp > $this->items[$key]['timestamp'])
);
// ← no return statement
```

Class is not integrated anywhere. Early draft, can be left for now.

---

### DESIGN-V001 — `styles.tpl.php` bypasses asset pipeline

```php
// partials/head/styles.tpl.php
<link href="https://fonts.googleapis.com/css2?family=Inter...">
<link rel="stylesheet" href="/css/reset.css">
<link rel="stylesheet" href="/css/styles.css">
```

Static HTML, no PHP. Hardcoded paths. `LayoutManager::addCss()` builds versioned paths — but this partial ignores all of it and references `/css/styles.css` (doesn't exist at that path). This partial should either use the context variables passed by View, or be removed in favour of the LayoutManager CSS pipeline.

---

### DESIGN-V002 — `layoutConfig.inc.php` hardcodes action template

```php
// module-frontend/src/Ui/Config/layoutConfig.inc.php:72-76
'main' => [
    ['nameSpace' => 'Z77\\Module\\Frontend', 'path' => 'IndexController', 'name' => 'indexAction']
]
```

The action template is hardcoded in the module config. The intention in `initialize()` is that if no `main` partial is set, it falls back to the current `controller/action`. But because of BUG-V001/V002 the fallback fires incorrectly. The module config should not hardcode a specific action — that defeats the purpose of the dynamic fallback.

---

## What Actually Works

| Component | Status |
|---|---|
| `LayoutManager::__construct()` | Works — module/controller/action resolved, StylesheetManager created |
| `LayoutManager::setGlobalVersion()` | Works — defines `MIN` and `VERSION` constants |
| `LayoutManager::initialize()` — config loading | Works — reads `layoutConfig.inc.php` correctly |
| `LayoutManager::addCss()` | Works — StylesheetManager versioning + dedup |
| `StylesheetManager::getVersionedCss()` | Works — timestamp versioning, old file cleanup |
| `LayoutManager::setDocumentTplPath()` | Works — FileFinder resolves skeleton |
| `View::render()` logic | Correct logic — `ob_start/require/extract` approach is right |
| `layoutConfig.inc.php` structure | Good — level/section/partial hierarchy is clean |
| Template files (skeleton, partials) | Exist and look correct (except `styles.tpl.php`) |

---

## What Needs to Be Fixed to Render

Minimum required to get end-to-end rendering:

1. **BUG-V001** — `addPartials()`: `$partialFilePahts` → `$this->partialFilePaths`
2. **BUG-V002** — `initialize()`: `$this->partialFilePahts` → `$this->partialFilePaths`
3. **BUG-V003** — `getView()`: fix constructor call + `$this->documentTpl` → `$this->documentTplPath` + fix null check
4. **BUG-V004** — `View.php` namespace: `Z77\Core\Service` → `Z77\Core\Services`
5. **BUG-V005** — add missing LayoutManager getters: `getHtmlSkeletonPath()`, `getBody()`, `getCss()`, `getJs()`
6. **BUG-V006** — AbstractBaseController: `$LM` → `$this->layoutManager`, uncomment render
7. **BUG-V007** — AbstractBaseController: add `Naming` to use-block, define or remove `IS_AJAX_HTTP_REQUEST`
8. **BUG-V008** — declare `private static array $pathCache = []` in LayoutManager
9. **DESIGN-V001** — `styles.tpl.php`: decide — use CSS pipeline or keep static

---

## Open Questions

1. **Body template**: where does the action template come from? The `layoutConfig.inc.php` hardcodes `IndexController/indexAction` as a partial in `main`. Is the action template intended to be a partial (in the levelElements structure) or resolved separately in `getBody()`?

2. **`IS_AJAX_HTTP_REQUEST`**: was this supposed to be defined in `preBoot/php/Functions.php` or in `Request`?

3. **`RenderedCss`**: what was the intent? CSS re-rendering cache? Can be left for v1.1 if not needed now.

4. **`getViewdocumentSettings()`**: this method is never called. Dead code or unfinished integration?

---

## Action Items

| Priority | ID | Action |
|---|---|---|
| Bug | BUG-V001 | `addPartials()`: local variable → `$this->partialFilePaths` |
| Bug | BUG-V002 | `initialize()`: typo in property name |
| Bug | BUG-V003 | `getView()`: fix constructor + property name + null check |
| Bug | BUG-V004 | `View.php` namespace fix |
| Bug | BUG-V005 | Add `getHtmlSkeletonPath()`, `getBody()`, `getCss()`, `getJs()` to LayoutManager |
| Bug | BUG-V006 | AbstractBaseController: assign `$this->layoutManager`, uncomment render |
| Bug | BUG-V007 | AbstractBaseController: add `Naming` use, resolve `IS_AJAX_HTTP_REQUEST` |
| Bug | BUG-V008 | Declare `private static array $pathCache = []` in LayoutManager |
| Design | DESIGN-V001 | `styles.tpl.php`: align with asset pipeline |
| Design | DESIGN-V002 | `layoutConfig.inc.php`: remove hardcoded action partial from module config |
| Won't fix now | BUG-V009 | `getViewdocumentSettings()` broken — not called, skip for now |
| Won't fix now | BUG-V010 | `RenderedCss` unfinished — not integrated, skip for now |
