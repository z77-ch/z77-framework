# ADR-005 — Module Architecture and URL Grouping

**Status:** `APPROVED`
**Date:** 2026-05-19
**Revised:** 2026-06-02 — template resolution changed from flat to group-nested (see Revision History). The original "templates stay flat" sub-decision was based on a false premise and is superseded.

---

## Context

z77 had a 3-segment URL schema (`/module/controller/action`) and flat controller
directories per module:

```text
packages/module-backend/src/Ui/Controllers/
    BackendAbstractController.php
    DashboardController.php
    LoginController.php
    NavigationController.php
    SystemController.php

packages/module-frontend/src/Ui/Controllers/
    IndexController.php
```

This was workable while the framework had only five backend controllers and one
frontend controller, but four problems were visible:

1. **No UI grouping inside a module.** A `Users/` area, `Content/` area, and the
   technical `System/` area were all flat siblings. Larger modules
   (`module-order`, `module-member`, `module-mail`) would have made this
   unmaintainable.
2. **URL space could not express groups.** A future `/backend/users/user/list`
   and `/backend/content/navigation/list` could not be modeled — `users` and
   `content` had nowhere to live in the URL.
3. **`navigation.json` migration cost is combinatorial later.** Every business
   module added before grouping was introduced would need to migrate its URLs
   later. Today: 12 entries. Later: hundreds.
4. **Convention fallback was ambiguous.** `/backend` cascaded through
   `defaultModule → defaultController → defaultAction` — there was no
   intermediate `defaultGroup` layer to allow UI sections.

Two options were on the table:

| Option | Idea |
|---|---|
| A | Keep flat structure, introduce groups later when business modules arrive |
| B | Restructure now: 4-segment URLs, group-aware namespaces, group-aware navigation entries |

---

## Decision

Adopt Option B: introduce a `group` segment between `module` and `controller`,
both in the URL schema and in the PHP namespace, and migrate all existing code,
config, and navigation data to it in one sitting.

### URL Schema (4 deterministic segments)

```text
/module/group/controller/action
```

Examples:

```text
/backend/system/dashboard/overview
/backend/content/navigation/list
/frontend/main/index/home
```

### Namespace Convention

URL segments map directly to PHP namespace components:

```text
backend / system   / dashboard   →  Z77\Module\Backend\Ui\Controllers\System\DashboardController
backend / content  / navigation  →  Z77\Module\Backend\Ui\Controllers\Content\NavigationController
frontend / main    / index       →  Z77\Module\Frontend\Ui\Controllers\Main\IndexController
```

Resolution is deterministic — no runtime discovery, no magic auto-loading.

### Group Semantics

`group` is a **UI/navigation namespace inside a module**, NOT a business-domain
boundary. Groups exist for UI organization (sidebar sections, topbar tabs); they
do not imply ownership, persistence boundaries, or service contracts.

Good examples: `system`, `content`, `users`, `main`.

### Naming Conventions

| Element | Casing | Example |
|---|---|---|
| URL segment | kebab-case | `user-management` |
| PHP namespace component | PascalCase | `UserManagement` |
| Config key | matches URL | `'user-management'` |

`Naming::toCamelCase()` converts URL → namespace deterministically.

### Convention-Fallback Resolution Order

Convention routing follows a strict cascade through four named defaults:

```text
defaultModule
  → defaultGroup
  → groupDefaults[defaultGroup]   (default controller per group)
  → defaultAction                 (per-controller, then module-level fallback)
```

Cascade examples:

```text
/                                   → defaultModule + defaultGroup + groupDefaults[defaultGroup] + defaultAction
/backend                            → defaultGroup + groupDefaults[defaultGroup] + defaultAction
/backend/system                     → groupDefaults['system'] + defaultAction
/backend/system/dashboard           → defaultAction
/backend/system/dashboard/overview  → exact match
```

### Old 3-Segment URLs

Old 3-segment URLs throw `NotFoundException`. No redirect layer is built. z77 is
not in production; there are no bookmarks to preserve. The break is intentional.

### Template Resolution

> **Revised 2026-06-02.** Templates are **group-nested**, mirroring the
> controller's physical location:

```text
templates/{Group}/{ControllerBaseName}/{action}.tpl.php
```

Examples:

```text
templates/System/DashboardController/overviewAction.tpl.php
templates/Content/NavigationController/listAction.tpl.php
templates/Main/IndexController/homeAction.tpl.php
```

The group segment is the namespace component between `\Controllers\` and the
class base name, resolved by `Naming::toControllerGroupSegment()`. Group-less
(flat) controllers resolve to `templates/{ControllerBaseName}/{action}.tpl.php`.
`LayoutManager` builds the FileFinder sub-path via `controllerTplDir()`.

The same nesting applies to controller-owned partials (`addPartials('edit',
'Content/NavigationController', …)`) and to controller layout configs
(`src/Ui/Config/{Group}/{controller}Config.inc.php`). Module-wide partials
(`partials/header`, `partials/footer`, …) stay flat — they are not
controller-bound.

**The original premise was wrong.** The first revision of this ADR kept
templates flat on the claim that "controller basenames are unique inside a
module (enforced by namespace)." The namespace enforces uniqueness of the
*fully-qualified class*, not of the *base name*: `Content\NavigationController`
and `System\NavigationController` share the base name `NavigationController` and
would have collided in a flat `templates/NavigationController/` directory and in
a flat `Ui/Config/navigationController Config`. With dozens of backend
controllers across groups, base-name collisions (`ListController`,
`ExportController`, `SettingsController` per group) are realistic, not
hypothetical. Group nesting removes the collision and makes the view tree mirror
the controller tree — one mental model instead of the flat/nested asymmetry.

### Backend Target Structure

```text
packages/module-backend/src/Ui/Controllers/
    BackendAbstractController.php           (stays flat)
    System/
        DashboardController.php
        SystemController.php
        LoginController.php
    Content/
        NavigationController.php
```

`Users/` is NOT created as an empty placeholder. The `navigation.json` entry
`id:7` (Benutzer) points to `/backend/users/user/list` and will 404 until the
controller is built — this is the marker for "build me next."

### Frontend Target Structure

```text
packages/module-frontend/src/Ui/Controllers/
    Main/
        IndexController.php
```

### Login Redirect

`loginUrl` in `backendConfig` points to the alias `/login`, not to the canonical
`/backend/system/login/login`. This decouples the redirect from any future URL
restructuring.

### Module-Level `defaultAction`

Module-level `defaultAction` is **kept** as a fallback for controllers that do
not define their own. Resolution order:

1. `controllers[$Controller]['defaultAction']`
2. Module-level `defaultAction`
3. `NotFoundException`

The frontend wildcard pattern relies on this fallback.

### `SystemController` Default Action

`SystemController` has no GET action — all its endpoints are POST-only Fetch
actions. Its `defaultAction` is **removed**. Convention access to
`/backend/system/system` throws `NotFoundException`. The controller is
intentionally reachable only via explicit POST URLs (`/backend/system/system/clear-cache`,
`/backend/system/system/toggle-debug`, `/backend/system/system/save-preferences`).

> **Revised 2026-07-16 (AUTH-B003, deviation-only config):** `SystemController`
> no longer has a config entry at all. The module-level `defaultAction: 'list'`
> convention now resolves `/backend/system/system` to `listAction` — which does
> not exist, so `setAction()` throws the same `NotFoundException`. The outcome
> (404 by design, explicit POST URLs only) is unchanged; only the mechanism
> moved from "no defaultAction configured" to "convention action absent".

---

## Reasoning

**Why now and not later?**
z77 has five backend controllers and one frontend controller — the minimum
state at which this restructure is cheap. Every additional business module
(`module-contact`, `module-order`, `module-mail`, `module-member`) multiplies
the cost of a later URL/namespace migration and bloats the `navigation.json`
diff combinatorially.

**Why a strict 4-segment URL and not a flexible optional group?**
Determinism. Optional groups create two parsing paths (`/backend/foo` could be
"foo is a controller in default group" or "foo is a group with default
controller"). Two paths means subtle bugs, magic resolution, and a worse
mental model. Strict 4 segments gives a single resolution rule.

**Why is `group` a UI concept and not a domain concept?**
Modules already express domain boundaries. Adding a second domain-like concept
inside a module would invite duplication and confusion ("is this in
`module-order` or in `backend/orders` group?"). `group` is explicitly scoped to
UI navigation so the modular DAG (ADR-style dependency graph) is unaffected.

**Why drop old 3-segment URLs without a redirect?**
z77 has no production deployments. A redirect layer would carry its own
maintenance cost and obscure the URL space with two valid forms. Bookmarks
are not a constraint.

**Why is `Naming::toControllerClassName` a 3-arg signature with `$group` allowed
to be empty?**
The 3-arg call with empty `$group` returns the flat-namespace class name. This
was used during the migration window (before all controllers were moved) and
stays as a permitted call shape for genuinely flat single-group modules.
Removing the flat path would force every module to declare a synthetic group
even when it does not need one.

**Why nest templates by group?** _(revised 2026-06-02)_
The view tree mirrors the controller tree, so a controller at
`Ui/Controllers/Content/NavigationController.php` has its templates at
`res/view/templates/Content/NavigationController/` — one predictable mental
model. The same scaling reason that motivated nesting controllers into groups
(dozens of backend controllers expected) applies identically to templates: a
flat `templates/` directory with dozens of controller folders has the same
navigability problem. Critically, base names are **not** unique across groups,
so flat templates carry a latent collision (see Template Resolution). The earlier
"duplication for no benefit" argument was wrong: the benefit is collision-safety
plus structural consistency with the already-nested controllers.

---

## Consequences

**Easier:**
- New business modules (`module-contact`, `module-order`, `module-member`,
  `module-mail`) can declare groups from day one — no migration.
- `navigation.json` entries carry an explicit `group` — the router resolves
  4-tuples and produces unambiguous matches.
- `PageCache` keys include `group` — two controllers with the same basename in
  different groups can never collide in the cache.
- Sidebar/topbar UI tied to a group is straightforward — `getByTag('backend')`
  yields the section containers and the convention guarantees that all entries
  beneath belong to a single group.

**Harder / to keep in mind:**
- Every new controller MUST declare its group in module config. Forgetting it
  produces a routing failure, not a silent default.
- Templates and controller configs are group-nested, mirroring the controller
  namespace. Anyone adding a controller must place its templates under
  `res/view/templates/{Group}/{Controller}/` and its config under
  `src/Ui/Config/{Group}/`. Module-wide partials stay flat under
  `templates/partials/`.
- `Naming::toControllerClassName` is now 3-arg. Any future caller that uses the
  old 2-arg form will fail at compile time.
- `PageIdentity` and `PageCache` carry `group` — any external code reading
  cache paths must include the segment.

**Cache invalidation note:**
APCu + page cache are cleared on the next debug-flag toggle or
`SystemController::clearCacheAction`. Old 3-tuple cache keys are not migrated;
they age out naturally.

---

## Implementation Summary

Touched files (final list after the migration):

| Area | Files |
|---|---|
| Convention (shared) | `Naming.php`, `Navigation.php` (entity, `$group` field + getter/setter) |
| Routing (core) | `Request.php`, `ControllerHandler.php`, `Dispatcher.php`, `NavigationService.php`, `ModuleManager.php`, `PageIdentity.php`, `PageCache.php`, `PageCachePolicy.php`, `AccessGuard.php` |
| Backend module | `backendConfig.inc.php`, `LoginController.php` (4 moves into `System/` + `Content/`, namespace + `use BackendAbstractController`), all `*Controller/*.tpl.php` URL fixes, `appearance.js` + `appearance.min.js`, asset copies in `skeleton/` |
| Frontend module | `frontendConfig.inc.php`, `IndexController.php` (move into `Main/`, namespace) |
| Data | `packages/kernel/core/data/framework/routing/navigation.default.json`, `skeleton/data/framework/routing/navigation.json` |

Migration sequence (lauffähig nach jedem nummerierten Schritt, mit Ausnahme
zwischen B und C):

1. **A — Convention layer (additive).** `Naming` 3-arg with empty-group
   fallback; `Navigation` entity `$group` field.
2. **B — Data migration.** Both `navigation.json` files updated to 4-segment
   URLs + `group` field. (Not standalone bootable — chain with C.)
3. **C — Resolver.** `Request`, `ControllerHandler`, `ModuleManager`,
   `NavigationService`, `Dispatcher`, `PageIdentity`, `PageCachePolicy` all
   carry the group segment.
4. **D — Configs.** `backendConfig.inc.php` and `frontendConfig.inc.php`
   rewritten with `defaultGroup`, `groupDefaults`, per-controller `group`.
5. **E — Controller moves.** Five controllers physically moved into group
   subdirectories with namespace + `use` adjustments.
6. **F+G — URL fixes + smoke test.** All hardcoded backend URLs in templates,
   JS, and login-redirect logic updated; 14-URL smoke test (12 positive + 2
   negative) verified live against the PHP built-in server.
7. **H — Documentation.** This ADR + topic-doc updates + `npm run docs:check`.

---

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Keep flat structure, introduce groups later when business modules arrive | Migration cost grows combinatorially with each module added before the change — cheapest moment is now |
| Optional group segment (`/module/controller/action` still valid as shorthand) | Two parsing paths → ambiguous resolution, hidden defaults, harder mental model |
| Group as a domain boundary (each group = sub-module) | Duplicates the module concept inside modules; conflicts with the DAG-based module dependency rules from ADR module-arch §4 |
| ~~Mirror group structure in templates~~ | **Adopted on revision (2026-06-02).** Originally rejected on the false premise that controller base names are unique within a module — they are unique only as fully-qualified classes, so flat templates carried a latent collision. See Template Resolution + Revision History. |
| Redirect layer for old 3-segment URLs | No production deployments; carries permanent maintenance cost for a one-time break |
| Create empty `Users/` placeholder subdirectory | Empty subdir provides no value; navigation entry `id:7` with 404-target URL is a clearer marker for "build me next" |
| Move `BackendAbstractController` into an `Abstract/` subdirectory | Abstract base class has no group affiliation; flat location keeps the inheritance chain explicit |
| Keep `loginUrl` as canonical `/backend/system/login/login` | Couples login redirect to current URL shape; alias `/login` is resistant to future URL changes |

---

## Revision History

### 2026-06-02 — Templates and controller configs nested by group

**What changed.** The original sub-decision "templates remain flat, keyed by
controller base name" is superseded. Action templates, controller-owned
partials, and controller layout configs are now nested under their group,
mirroring the controller's namespace:

```text
src/Ui/Controllers/Content/NavigationController.php
res/view/templates/Content/NavigationController/{action}.tpl.php
src/Ui/Config/System/systemControllerConfig.inc.php
```

Module-wide partials (`templates/partials/…`) stay flat.

**Why.** The original premise — "controller base names are unique inside a
module (enforced by namespace)" — was false. The namespace enforces uniqueness
of the fully-qualified class, not the base name. `Content\NavigationController`
and `System\NavigationController` share base name `NavigationController` and
collide in a flat `templates/NavigationController/` directory and in a flat
`navigationControllerConfig`. The collision is realistic once a module has dozens
of controllers across groups. Group nesting also gives one mental model (view
tree mirrors controller tree) instead of the flat-vs-nested asymmetry the first
revision accepted as a cost.

**Touched files.**

| Area | Files |
|---|---|
| Convention (shared) | `Naming.php` — new `toControllerGroupSegment()` |
| View resolution (core) | `LayoutManager.php` — `$group` field, `controllerTplDir()`, `controllerResourcePath()`, group-aware action template + config resolution |
| Access control (core/shared) | Same group-mirroring applied to the `controllers` access-control map (AUTH-B002): nested by group in `backendConfig` + `frontendConfig`; `AuthService::resolveRoleForCurrentController()` and `ModuleManager::getDefaultActionForController()` made group-aware (callers `Request.php`, `LoginController.php` pass the group); dead `ModuleManager::getRole()` deleted. See `backend.md` AUTH-B002. |
| Backend module | `NavigationController.php`, `NavigationGroupController.php` — group-prefixed `addPartials()` paths; templates moved into `Content/` and `System/`; `systemControllerConfig.inc.php` moved into `Ui/Config/System/`; controller folder `FrontentContent/` renamed to `FrontendContent/` (PSR-4 fix, folder now matches namespace) |
| Frontend module | `IndexController` templates moved into `Main/` |
| Cleanup | Two orphan frontend templates deleted (`testInArbeitAction`, `TesteDenControllerController/`) |
| Docs | this ADR + `view-layer.md` + `templates.md` + `backend.md` |
