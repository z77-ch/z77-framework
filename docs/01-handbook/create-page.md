# Building a Page — Controller, Action, Config, Template

**Status:** `[CURRENT]`
**Date:** 2026-07-11

The end-to-end recipe for adding a page: how a URL becomes a rendered HTML response, and
the four things you write to add one. This is the **spine** — it cross-links the reference
topics (routing, navigation, cache, view-layer, content, i18n) at the step where each applies,
so you don't re-derive them.

---

## The four pillars

| # | Pillar | File(s) | Responsible for |
|---|---|---|---|
| 1 | **App config** | `src/App/Config/{module}Config.inc.php` | routing structure (groups/defaults) · ACL roles · cache policy |
| 2 | **Routing + PageCache** | framework | URL → `module/group/controller/action` 4-tuple → cache decision |
| 3 | **Controller / Action** | `src/Ui/Controllers/{Group}/{Name}Controller.php` | logic → a Response object |
| 4 | **UI layout** | `src/Ui/Config/layoutConfig.inc.php` + `res/view/templates/…` | skeleton + partials + the action's template |

In a **project** you write pillars 1, 3, 4 (and the nav entry) in the `override/` layer; the
framework packages provide the defaults you override.

---

## The request flow (reference)

```
Bootstrap::pullUp() → Request::runParsing()
  1. extractLanguage()            strip /de/ or /fr/ (absent = defaultLanguage)
  2. matchReserved()              reserved routes (longest-prefix)
  3. translateSlugsToCanonical()  localized → canonical segments (non-default language)
  4. resolve (Page mode):
       Router::matchAlias()       NavigationAlias, longest-prefix → 4-tuple + content slugs
       ↳ miss → Router::match()   static nav (findByPath) → canonical 4-tuple
       ↳ miss → parsePathSegments() convention: /module/group/controller/action (default cascade)
  → ControllerHandler::lock()     freeze the 4-tuple

Dispatcher::execute()
  1. enforceActionConstraints()   #[HttpMethod], #[Fetch] attributes
  2. AccessGuard::enforce()        ← ACL FIRST (starts session); denied → login redirect / 403
  3. applyLanguageSession()        language reconciliation / bare-root redirect (ADR-013)
  4. PageCachePolicy::decide()     BYPASS · 304 · HIT · MISS
  5. resolveResponse()
       HIT  → serve cache/pages/{lang}/{module}/{controller}/{action}.html  (action NOT run)
       MISS / BYPASS → run controller → {action}Action() → Response
                       LayoutManager assembles skeleton + partials + main-template
                       → HtmlView renders → (MISS) store to cache
  → send()
```

Detail: routing → [`../topics/routing.md`](../topics/routing.md); page cache →
[`../topics/cache.md`](../topics/cache.md).

---

## Recipe — add a page

### 1. Register it in the App config (structure + ACL + cache)

`override/z77/module/frontend/src/App/Config/frontendConfig.inc.php` (overrides the package
default — see [Config override](#config-override)):

```php
'groupDefaults' => ['main' => 'index'],          // group → default controller
'controllers'   => [
    'main' => [
        'IndexController' => [                     // or '*' wildcard within the group
            'defaultAction'  => 'home',
            'controllerRole' => AuthRole::GUEST,   // public
            'actions'        => ['*' => AuthRole::GUEST],
        ],
    ],
],
'cache' => ['enabled' => true, 'ttl' => 86400],   // per-controller/action override supported
```

- ACL cascade: `moduleRole` → `controllerRole` → per-action role (`AuthRole`, `'*'` wildcards);
  hierarchy `GUEST < VISITOR < MEMBER < ADMIN < SUPER_USER`. Enforced by `AccessGuard` **before**
  the action. Access-controlled areas → see [`../topics/security.md`](../topics/security.md),
  [`../topics/backend.md`](../topics/backend.md).
- A POST endpoint (e.g. a contact `sendAction`) must set its cache `enabled => false`.

### 2. Write the controller + action

`override/z77/module/frontend/src/Ui/Controllers/Main/IndexController.php`:

```php
namespace Z77\Module\Frontend\Ui\Controllers\Main;

use Z77\Module\Frontend\Ui\Controllers\AbstractFrontendController;
use Z77\Core\Http\Response\HtmlResponse;

class IndexController extends AbstractFrontendController
{
    protected function homeAction(): HtmlResponse
    {
        return $this->html(['pageTitle' => 'Home', /* … */]);
    }
}
```

- Naming ↔ URL: `Main` → group `main`, `IndexController` → controller `index`,
  `homeAction` → action `home` (`Naming::toControllerClassName`, ADR-005).
- Return a **Response object** via a helper — never instantiate directly (ADR-003):
  `$this->html($ctx)` · `->json($data)` · `->fetch()` · `->redirect($url)` · `->file(...)` · `->void()` · `->noContent()`
  (204 = success without content, e.g. revalidation "unchanged" — not for error cases).
- `$this->html()` auto-injects `navigation`, `language`, `languageSwitch`, `seo`, `metaData`,
  `csrfToken`, `clientI18n`, flashes — your `$context` layers on top.
- Optional hooks (do **not** call `parent`): `preExecute()` (auth/shared setup — throw to abort),
  `postExecute()` (controller-wide assets).

### 3. Write the template (the `main` slot)

`override/z77/module/frontend/res/view/templates/Main/IndexController/home.tpl.php`

Path convention = `res/view/templates/{Group}/{Controller}/{action}.tpl.php` (group-nested;
flat controllers drop the group). The LayoutManager resolves this as the `body.main` partial
automatically — everything else (head, header, footer, flash) comes from the skeleton. Template
syntax + view helpers → [`../topics/view-layer.md`](../topics/view-layer.md) + [`templates.md`](templates.md).

The **skeleton** and default **partials/stylesheets/js** come from
`src/Ui/Config/layoutConfig.inc.php` (`documentTpl`, `levelElements`, `styleSheets`,
`javascripts`). Override the whole module layout in
`override/.../Ui/Config/layoutConfig.inc.php`, or just one controller/action via
`Ui/Config/{Group}/{controller}Config.inc.php`.

### 4. Make it reachable — navigation + alias

- Convention URL works immediately: `/frontend/main/index/home`.
- For a friendly URL + menu item, add a **Navigation** entry (carries the 4-tuple + menu slot)
  and a **NavigationAlias** (`path` → navigation), e.g. `/home`. Content after the alias becomes
  content slugs (`Request::getSlugs()`). Localized per language + 301 to canonical. Full model →
  [`../topics/navigation.md`](../topics/navigation.md) (NavigationAlias, ADR-015).

### 5. Content, assets, i18n/SEO (as needed)

- **Content:** content-driven pages render blocks via `ContentService` + the module's
  `contentBlocks` renderers (ADR-012) → [`../topics/content.md`](../topics/content.md),
  [`../topics/block-types.md`](../topics/block-types.md).
- **Assets:** add SCSS→CSS in `layoutConfig.styleSheets` (module-wide) or per action via
  `$this->layoutManager->addCss(name, ns)` / `addJs(...)`; compile with `npm run watch/build`.
  Conventions → [`css-conventions.md`](css-conventions.md), [`../topics/stylesheet.md`](../topics/stylesheet.md),
  [`../topics/css-watch.md`](../topics/css-watch.md). NOTE for per-page JS in a project: a fresh
  project has no JS build step (only `build:css`) — the pattern pages show the worked example
  (build script + `addJs` + count-dependent `createCss`): [`patterns/slider.md`](patterns/slider.md) §3a/§5.
- **i18n + SEO:** offered `languages` in the module config; slug translation
  ([`../topics/translation.md`](../topics/translation.md), [`../topics/i18n.md`](../topics/i18n.md));
  per-page metadata via the navigation entry ([`../topics/metadata.md`](../topics/metadata.md)).

---

## Config override (project) {#config-override}

Both config files (`App/Config/*` and `Ui/Config/*`) and templates are resolved by
`ConfigManager` / FileFinder with the **project override path first, the package (vendor) path
second** — the CE (Customer Extension) lookup order set up by the installer's `buildPaths()`.
So a file at `override/z77/module/frontend/src/App/Config/frontendConfig.inc.php` wins over the
package default. Put project-specific structure/ACL/cache, layout, controllers, and templates
under `override/`.

## Caching notes

- **`DEBUG=true` → always BYPASS** — in dev you always see a fresh render.
- Per-controller / per-action cache overrides live under the module config `cache` key.
- Content that must reflect immediately: mark the writing entity `#[Entity(..., invalidatesCache: true)]`
  (e.g. `navigation.json`) so a write clears the frontend page cache. See [`../topics/cache.md`](../topics/cache.md).

## Verify

- Route returns `200` (or `302` when auth-gated); a wrong role → login redirect / 403.
- Response carries `X-Z77-PageCache: HIT | MISS | BYPASS` — confirm BYPASS in DEBUG, HIT on the
  second prod request.
- The page renders inside the skeleton with head/header/footer and your `main` template.

## see also

- Pillars in depth: [`../topics/routing.md`](../topics/routing.md) ·
  [`../topics/navigation.md`](../topics/navigation.md) · [`../topics/cache.md`](../topics/cache.md) ·
  [`../topics/view-layer.md`](../topics/view-layer.md) · [`templates.md`](templates.md)
- Decisions: [`../02-decisions/adr-003-controller-response-objects.md`](../02-decisions/adr-003-controller-response-objects.md) ·
  [`../02-decisions/adr-005-module-architecture-and-url-grouping.md`](../02-decisions/adr-005-module-architecture-and-url-grouping.md) ·
  [`../02-decisions/adr-013-i18n-language-configuration.md`](../02-decisions/adr-013-i18n-language-configuration.md) ·
  [`../02-decisions/adr-015-navigation-alias-and-content-slugs.md`](../02-decisions/adr-015-navigation-alias-and-content-slugs.md) ·
  [`../02-decisions/adr-022-view-areas-and-nav-slots-in-module-config.md`](../02-decisions/adr-022-view-areas-and-nav-slots-in-module-config.md)
