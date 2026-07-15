# Creating a New Module

**Status:** `[CURRENT]`
**Date:** 2026-07-15

A **module** bundles a bounded task under its own `Z77\Module\{Name}\` namespace and
`/{key}/` URL space. This recipe builds one. For adding a *page inside* an existing module,
use [`create-page.md`](create-page.md) instead — that is the far more common task.

---

## Two shapes of module

A module does **not** have to be a UI environment. There are two shapes, and the
framework ships an example of each:

| Shape | Example | Owns a layout / navigation? | Typical output |
|---|---|---|---|
| **View-area** | `frontend`, `backend` | Yes — `viewArea => true`, layout config, templates, nav slots | `HtmlResponse` pages |
| **Headless** | `dms` | No — no `viewArea`, no layout config, no templates | `FileResponse`, services, reserved routes |

`dms` is the proof that a module is about *organising a task*, not about having a screen:
it groups document management — byte delivery (`OutputController`, reserved route `/media`),
plus `Services`, `Entities`, `Repositories` — and renders no layout of its own. What HTML
surface it has borrows another module's view-area.

> **When do you need a new module?** Two reasons: (1) isolate a bounded domain with its own
> namespace, services, and routes — even headless, like `dms`; or (2) add a whole new UI
> environment with its own layout — a view-area, like a `shop`. Adding pages or controllers
> to an *existing* environment is a page-level task ([`create-page.md`](create-page.md)),
> not a module.

---

## How a module is discovered

There is no registry to edit. A module is **any PSR-4 namespace `Z77\Module\{Name}\`**.
On `composer install`/`update`, the installer scans the autoload map, finds every
`Z77\Module\*` root, and regenerates `config/moduleManager.inc.php` (do not edit that file
by hand — it is overwritten). The module key is the lower-cased `{Name}` (`Z77\Module\Shop`
→ `shop`), and its URLs live under `/{key}/…`.

**Placement — two options, independent of the shape:**
- **Framework package** (monorepo `packages/module-{name}/`, namespace mapped in its own
  `composer.json`) — a module that ships with the framework. See
  [`../topics/packaging.md`](../topics/packaging.md).
- **Project module** (project `override/z77/module/{name}/src/`, namespace added to the
  project `composer.json` autoload) — a module that exists only in one project.

---

## The anatomy

Only the **App config is mandatory**. The layout config, templates, and styles are needed
**only for a view-area module** — a headless module skips them.

| # | Piece | File | Needed by | Owns |
|---|---|---|---|---|
| 1 | **App config** | `src/App/Config/{name}Config.inc.php` | **all modules** | routing structure · ACL roles · cache · (view-area: identity + nav slots) |
| 2 | **Layout config** | `src/Ui/Config/layoutConfig.inc.php` | view-area only | document skeleton · stylesheets · head/body partials · scripts |
| 3 | **Controllers** | `src/Ui/Controllers/…` | all modules | request logic → Response objects |
| 4 | **Templates + styles** | `res/view/templates/…` · `res/scss/…` | view-area only | markup + appearance |
| — | **Services / Entities / Repositories** | `src/Services/…`, `src/Entities/…` | as needed | the module's actual domain work (headless modules live here) |

---

## Recipe

### 1. Claim the namespace

Add the PSR-4 root so the installer can discover the module.

Framework package — `packages/module-shop/composer.json` (copy an existing module's
manifest; it requires only `z77/kernel`):
```json
"autoload": { "psr-4": { "Z77\\Module\\Shop\\": "src/" } },
"require":  { "php": ">=8.2", "z77/kernel": "^1.0" }
```

Project module — add to the project `composer.json` autoload:
```json
"Z77\\Module\\Shop\\": ["override/z77/module/shop/src/"]
```

Run `composer install` — `config/moduleManager.inc.php` now lists the new key.

### 2. Write the App config (mandatory)

`src/App/Config/{name}Config.inc.php` returns the module's routing structure, ACL, and
cache policy. What you add on top depends on the shape.

**View-area module** — the keys that make it a top-level environment with navigation are
`viewArea`, `viewAreaLabel`, and `navSlots` ([ADR-022](../02-decisions/adr-022-view-areas-and-nav-slots-in-module-config.md)):

```php
<?php
namespace Z77\Module\Shop\App;

use Z77\Core\Config\AuthRole;

return [
    'defaultGroup'  => 'main',
    'groupDefaults' => ['main' => 'index'],   // group → default controller
    'defaultAction' => 'home',

    'viewArea'      => true,                   // owns a layout, is a top-level environment
    'viewAreaLabel' => 'Shop',
    'navSlots'      => ['main' => 'Hauptnavigation', 'meta' => 'Fusszeile'],
    'public'        => true,                   // publicly reachable + indexable (SEO scope)
    'languages'     => ['de', 'fr'],           // switch UI opt-in, subset of config/i18n (ADR-013)

    'moduleRole'    => AuthRole::GUEST,        // ACL cascade root
    'cache'         => ['enabled' => true, 'ttl' => 86400],

    'controllers'   => [                        // ACL nested by group; '*' = controller wildcard (ADR-005)
        'main' => ['*' => [
            'controllerRole' => AuthRole::GUEST,
            'actions'        => ['*' => AuthRole::GUEST],
        ]],
    ],
];
```

**Headless module** — no `viewArea`, no layout, often a reserved route and disabled page
cache. This is the real `dms` shape (abbreviated):

```php
return [
    'defaultGroup'  => 'media',
    'groupDefaults' => ['media' => 'output'],
    'defaultAction' => 'serve',
    'moduleRole'    => AuthRole::GUEST,
    'cache'         => ['enabled' => false],   // byte delivery is never page-cached

    // Structural, mode-independent delivery URL — highest routing precedence.
    'reservedRoutes' => [
        '/media' => ['module' => 'dms', 'group' => 'media',
                     'controller' => 'output', 'action' => 'serve'],
    ],
    'controllers' => [ /* group → controller → action roles */ ],
];
```

Detail: URL grouping + naming → [ADR-005](../02-decisions/adr-005-module-architecture-and-url-grouping.md);
ACL roles → [`../topics/security.md`](../topics/security.md); cache structure →
[`create-page.md`](create-page.md) §1 and [`../topics/cache.md`](../topics/cache.md);
reserved routes → [`../topics/routing.md`](../topics/routing.md).

### 3. (View-area only) Write the Layout config

`src/Ui/Config/layoutConfig.inc.php` — how the module's pages are assembled: the document
skeleton, stylesheets, head/body partials, scripts. `body.main` is deliberately absent — it
is resolved per request from the current controller/action. **A headless module skips this
step entirely.**

```php
<?php
namespace Z77\Module\Shop\Ui\Config;

return [
    'documentTpl' => ['name' => 'html-default-skeleton', 'nameSpace' => 'Z77\\Module\\Shop'],
    'styleSheets' => [
        ['nameSpace' => 'Z77\\Module\\Shop', 'name' => 'base', 'media' => ''],
    ],
    'levelElements' => [
        'head' => ['meta' => 'partials/head/meta', 'seo' => 'partials/head/seo'],
        'body' => [
            'header' => 'partials/header',
            // 'main' resolved dynamically
            'footer' => 'partials/footer',
            'flash'  => 'partials/flashMessages',
        ],
    ],
    'javascripts' => [
        ['name' => 'core', 'nameSpace' => 'Z77\\Shared', 'defer' => true],
    ],
];
```

Detail: view assembly → [`../topics/view-layer.md`](../topics/view-layer.md); template layer
→ [`templates.md`](templates.md); CSS/SCSS → [`css-conventions.md`](css-conventions.md).

### 4. Controllers (and the domain work)

Give the module a base controller (conventional — centralises per-module concerns), then
add actions. A view-area base mirrors `AbstractFrontendController` (sets the namespace
constant, may inject shared context); a headless module's controller just returns the
non-HTML response it produces.

```php
<?php
namespace Z77\Module\Shop\Ui\Controllers;

use Z77\Core\Controller\AbstractBaseController;

abstract class AbstractShopController extends AbstractBaseController
{
    protected const NAMESPACE = 'Z77\\Module\\Shop';
}
```

A headless module's real substance lives in `src/Services/`, `src/Entities/`,
`src/Repositories/` — the controller is a thin entry point returning `$this->file(...)`,
`$this->json(...)`, `$this->fetch()`, etc. (`dms` is the reference). For a view-area
module, adding the first HTML page (`Main/IndexController` + `homeAction` + template + nav
entry) is exactly the page recipe — **follow [`create-page.md`](create-page.md)**; this doc
does not repeat it.

### 5. (View-area only) Templates and styles

Provide the skeleton and partials the layout config references
(`html-default-skeleton.tpl.php`, `partials/header`, `partials/footer`, …) under
`res/view/templates/`, and the SCSS under `res/scss/` (compiled to `res/assets/css/` via
`npm run build`). The fastest start is to copy `module-frontend/res/` and adapt.

---

## Verification

- `composer install` → `config/moduleManager.inc.php` lists the new module key.
- **View-area:** `npm run build` compiles the module's CSS; hitting `/{key}/` resolves to
  its default group → controller → action and renders through its own layout skeleton.
- **Headless:** hitting the module's route (e.g. its reserved route) returns the expected
  non-HTML response.

## See also

- [`create-page.md`](create-page.md) — add a page inside a module (the common task)
- [`architecture.md`](architecture.md) — modules, URL grouping, and the request lifecycle
- [`../topics/documents.md`](../topics/documents.md) — `dms`, the reference headless module
- [`../topics/packaging.md`](../topics/packaging.md) — shipping a module as a framework package
- [ADR-022](../02-decisions/adr-022-view-areas-and-nav-slots-in-module-config.md) — view-areas and nav slots in module config
