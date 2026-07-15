# Request-to-HTML Workflow

**Status:** `[IMPLEMENTED]`
**Date:** 2026-04-28

---

## End-to-End Flow

```
index.php
  define ABS_BASE_PATH, ABS_INDEX_PATH
  require vendor/autoload.php
  │
  ├─ Bootstrap::__construct()
  │    DI: CacheManager, FileFinder, ConfigManager
  │    load bootstrap.inc.php → define DEBUG, timezone, error_reporting
  │    load preBoot/Functions.php  (url_origin etc.)
  │
  ├─ Bootstrap::pullUp()
  │    DI: ModuleManager, ControllerHandler, Request,
  │        DataSourceResolver, UnifiedEntityManager,
  │        NavigationRepository, Router, Dispatcher
  │    │
  │    ├─ Request::__construct()
  │    │    setRawRequestUri, method, urlOrigin
  │    │    resolveRequestMode()   → Page | Fetch
  │    │    setPathSegments()
  │    │
  │    ├─ Request::runParsing()
  │    │    extractLanguage()      → sets $language, strips segment
  │    │    │
  │    │    ├─ [Fetch]  parsePathSegments()  ← convention directly, no nav-lookup
  │    │    │
  │    │    └─ [Page]
  │    │         segments present?
  │    │         ├─ YES → Router::match(url)
  │    │         │          NavigationRepository::findByUrl()
  │    │         │            CacheManager (local → APCu → FileStorage)
  │    │         │            FileStorage::load('framework/routing/navigation.json')
  │    │         │          HIT  → module/controller/action from Navigation entity
  │    │         │          MISS → parsePathSegments()  (convention fallback)
  │    │         └─ NO  → parsePathSegments()  (defaults)
  │    │
  │    ├─ ControllerHandler::lock()
  │    ├─ SessionManager start
  │    ├─ load prod/Functions.php, prod/Helper.php
  │    └─ DEBUG: load debug/Functions.php, setOwnExceptionHandler()
  │
  └─ Dispatcher::execute()
       ControllerHandler::getCurrentControllerInstance()
       │
       ├─ [TODO: Page Cache check]
       │
       ├─ AbstractBaseController::run()
       │    DI: EntityManager (UnifiedEntityManager)
       │    new LayoutManager(...)
       │    preExecute()  (optional hook)
       │    │
       │    └─ execute() → indexAction()
       │         $this->html([context])
       │           LayoutManager::initialize()
       │             [Fetch] → setSkeletonTemplate('html-fetch-skeleton')
       │             [Page]  → load layoutConfig.inc.php → partials, assets
       │           return HtmlResponse(layoutManager, context)
       │
       │    postExecute()  (optional hook)
       │    return ResponseInterface
       │
       ├─ [TODO: Page Cache store]
       │
       ├─ HtmlResponse::send()
       │    LayoutManager::render(context)
       │      HtmlView::render()
       │        foreach partials → ob_start → require → capture
       │        ob_start → require body template → capture
       │        require html-default-skeleton.tpl.php
       │
       └─ CacheManager::flushToTarget()
```

---

## Key Decision Points

### RequestMode (Page vs. Fetch)

Resolved once in `Request::__construct()` via `Sec-Fetch-Mode` header (browser-set).
Influences two independent stages:

1. **runParsing()** — Fetch skips navigation lookup entirely
2. **LayoutManager::initialize()** — Fetch uses `html-fetch-skeleton`, Page loads full `layoutConfig.inc.php`

See: [request-mode.md](request-mode.md)

### Navigation Lookup

Fires only on Page requests with URL segments present after language extraction.
Three-tier cache: local (in-request array) → APCu → JSON file.
Entity: `packages/kernel/shared/src/Entities/Navigation.php`

See: [navigation-router.md](navigation-router.md)

### LayoutManager::initialize()

On Page: loads `layoutConfig.inc.php` from the active module — this file defines which partials
and assets are registered for this request. On Fetch: sets the fetch skeleton directly, skips config.

---

## Files

| File | Role |
|---|---|
| `index.php` | Entry point — defines constants, boots framework |
| `packages/kernel/core/src/Bootstrap.php` | DI registration, pullUp sequence |
| `packages/kernel/core/src/Http/Request.php` | RequestMode, language extraction, routing |
| `packages/kernel/core/src/Routing/Router.php` | Navigation lookup |
| `packages/kernel/core/src/Routing/Dispatcher.php` | Controller execution, response dispatch |
| `packages/kernel/core/src/Controller/AbstractBaseController.php` | run(), pre/postExecute hooks |
| `packages/kernel/core/src/Services/LayoutManager.php` | Skeleton selection, partial/asset registration |
| `packages/kernel/core/src/Http/Response/HtmlResponse.php` | Triggers LayoutManager::render() |
| `packages/kernel/core/src/View/HtmlView.php` | Partial and body template rendering |
| `packages/module-frontend/res/view/templates/html-default-skeleton.tpl.php` | Full-page skeleton |
| `packages/module-frontend/res/view/templates/html-fetch-skeleton.tpl.php` | Fetch/partial skeleton (minimal stub) |

---

## Open Work

| Task | Status |
|---|---|
| `html-default-skeleton.tpl.php` | `[DONE 2026-04-29]` |
| `html-fetch-skeleton.tpl.php` | `[IN PROGRESS]` — minimal stub, may need expansion |
| Page Cache (check + store in Dispatcher) | `[OPEN]` — placeholder only |
| View Layer Bugs BUG-V001–V008 | `[DONE 2026-04-29]` — see review-view-layer.md |
