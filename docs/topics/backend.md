# backend

2026-06-03

## entry

1. `packages/module-backend/src/Ui/Controllers/BackendAbstractController.php` — base class for all backend controllers: auto-injects `userPreferences`, `bePalette`, `beTheme` into context
2. `packages/kernel/shared/src/Services/CurrentUserService.php` — per-request cache for `LoginUser` entity + preferences logic
3. `packages/module-backend/src/Ui/Controllers/System/SystemController.php` — clear-cache, debug-toggle, save-preferences
4. `packages/module-backend/src/App/Config/backendConfig.inc.php` — module config (default group, group defaults, role hierarchy)
5. `packages/module-backend/src/Ui/Controllers/System/LoginUserController.php` — user administration (flat list, add/edit/delete, drag&drop reorder)

## file map

SOURCE=/packages/module-backend/src/App/Config/backendConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Config/layoutConfig.inc.php
SOURCE=/packages/module-backend/res/view/templates/html-guest-skeleton.tpl.php
SOURCE=/packages/module-backend/src/Ui/Config/System/loginControllerConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Config/System/setupControllerConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Controllers/BackendAbstractController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/AbstractTreeEntityController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/DashboardController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/SystemController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/LoginController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/SetupController.php
SOURCE=/packages/module-backend/res/view/templates/System/SetupController/setupAction.tpl.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Content/NavigationController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Content/MetaDataController.php
SOURCE=/packages/module-backend/res/view/templates/Content/NavigationController/listAction.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Content/MetaDataController/listAction.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Content/MetaDataController/edit.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Content/MetaDataController/confirmDelete.tpl.php
SOURCE=/packages/module-backend/res/view/templates/System/DashboardController/overviewAction.tpl.php
SOURCE=/packages/module-backend/res/view/templates/partials/subnav.tpl.php
SOURCE=/packages/module-backend/res/view/templates/html-shell-skeleton.tpl.php
SOURCE=/packages/kernel/shared/res/assets/js/core.js
SOURCE=/packages/kernel/shared/res/assets/js/panel-toggle.js
SOURCE=/packages/kernel/shared/src/Controller/RouteInfoTrait.php
SOURCE=/packages/module-frontend/src/Ui/Controllers/AbstractFrontendController.php
SOURCE=/packages/module-frontend/res/view/templates/partials/adminOverlay.tpl.php
SOURCE=/packages/module-frontend/res/scss/admin-overlay.scss
SOURCE=/packages/module-backend/res/assets/js/appearance.js
SOURCE=/packages/module-backend/res/scss/components/_list.scss
SOURCE=/packages/module-backend/res/assets/js/navigation/list.js
SOURCE=/packages/module-backend/src/Ui/Controllers/System/LoginUserController.php
SOURCE=/packages/module-backend/res/view/templates/System/LoginUserController/listAction.tpl.php
SOURCE=/packages/module-backend/res/view/templates/System/LoginUserController/edit.tpl.php
SOURCE=/packages/module-backend/res/view/templates/System/LoginUserController/confirmDelete.tpl.php
SOURCE=/packages/module-backend/res/assets/js/login-user/list.js
SOURCE=/packages/module-backend/res/assets/js/password-meter.js
SOURCE=/packages/kernel/shared/src/Validators/LoginUserValidator.php
SOURCE=/packages/kernel/shared/src/Auth/PasswordPolicy.php
SOURCE=/packages/kernel/shared/src/ValueObjects/UserPreferences.php
SOURCE=/packages/module-backend/src/App/Config/Palettes.php

## mental model

The backend is the admin UI. URL schema is 4 segments — `/backend/{group}/{controller}/{action}` (see ADR-005). Default group is `system` (set in `backendConfig`); each declared group has a default controller in `groupDefaults`; each controller defines its own `defaultAction`. Layout follows the Werkbank design: topbar (module tabs) + subnav (left tree) + main + service panel (avatar dropdown). The service panel exposes Debug-Toggle, Noindex-Toggle (site-wide crawl block, see [`metadata.md`](metadata.md) SEO-NOINDEX-001), Clear-Cache, and Logout. The Noindex-Toggle live-updates its indicator AND the persistent shell Störer (`#js-noindex-banner`) via `system/cache.js`.

- All backend Fetch endpoints go through `SystemController` (clear-cache, toggle-debug, save-preferences) under group `system`.
- Controllers are organised into group subdirectories: `Ui/Controllers/System/` (`Dashboard`, `System`, `Login`), `Ui/Controllers/Content/` (`Navigation`). `BackendAbstractController` stays flat in `Ui/Controllers/`.
- User preferences (palette, dark-mode) are persisted server-side per `LoginUser`. `CurrentUserService` caches `LoginUser` per request.
- Palette + theme are applied via `data-be-palette` / `data-be-theme` attributes on the `<html>` element — written server-side by `BackendAbstractController::html()` from `userPreferences`. CSS selectors in `_colors.scss` activate the matching token set on first paint (no FOUC, no JS needed).
- `appearance.js` updates the same `<html>` data-attributes on user clicks (instant CSS switch via selectors), then POSTs to `/backend/system/system/save-preferences` for persistence (fire-and-forget). No JS-side token mirror — `_colors.scss` is single source of truth.
- Backend JS: shared `core.js` (fetch + flash/message + popup + field validation + envelope dispatch — see [`fetch.md`](fetch.md)) + shared `panel-toggle.js` (generic, data-attribute dropdown/collapse toggle — drives the topbar environment switcher, the avatar service panel, and the avatar "Info"/"Aussehen" collapsibles) + `appearance.js` + `system/cache.js`. `partials/footer.tpl.php` holds the remaining inline bits (hamburger overlay).
- Action-specific CSS/JS is registered in the controller via `addCss()`/`addJs()` (called after `$this->html()`). No inline `<style>` or `<script>` blocks in templates.
- **`activeSection`** — HTML-rendering controllers pass `'activeSection' => '<tag-slug>'` in the template context. `header.tpl.php` uses it to highlight the active topbar tab; `subnav.tpl.php` uses it to call `getByTag($activeSection)` and render the sidebar tree. Omitting it leaves both inactive.

## groups

Backend groups exist for UI organisation only — they are NOT business-domain boundaries (see ADR-005 §11).

| Group | Default controller | Purpose |
|---|---|---|
| `system` | `dashboard` | Technical/system UI: dashboard, login/logout, cache + debug ops |
| `content` | `navigation` | Content management: navigation CRUD, content documents, SEO metadata (see [`metadata.md`](metadata.md)), future stylesheet editor |
| `users` | `user` | User management — placeholder; controller not yet built (navigation entry `id:7` 404s on purpose) |

## controllers

| Controller | Group | Default action | Notes |
|---|---|---|---|
| `DashboardController` | `system` | `overview` | Entry page after login. 6 module cards in grid. Must put `userPreferences` in template context. URL: `/backend/system/dashboard/overview`. |
| `LoginController` | `system` | `login` | Extends `AbstractBaseController` (NOT a security controller); see [`login.md`](login.md). URL: `/backend/system/login/login`, alias `/login`. |
| `SystemController` | `system` | n/a (POST-only) | Fetch endpoints only (no HTML render). No `defaultAction` — `/backend/system/system` throws `NotFoundException` by design (see ADR-005 §SystemController Default Action). |
| `NavigationController` | `content` | `list` | Navigation ELEMENT CRUD: list (the shared navigation screen, grouped by config render-slots) / add / edit / confirmDelete / remove + `moveAction` (DnD tree mutation via `parentId` + `sortKey`) + `checkFieldAction` for blur-based field validation (see [`fetch.md`](fetch.md), [`entity-data-handling.md`](entity-data-handling.md)). URL: `/backend/content/navigation/list`. Render-slots + view areas are config (ADR-022) — there is no group controller. |
| `MetaDataController` | `content` | `list` | Per-page SEO `MetaData` CRUD. `list` is "by navigation point", scoped to **public** environments (module config `'public' => true`) and grouped by level-0 environment, with a `?env` filter bar. add / edit / confirmDelete / remove. URL: `/backend/content/meta-data/{action}` (multi-word kebab segment). Identity (navigation_id, language) immutable on edit. See [`metadata.md`](metadata.md). |
| `TranslationController` | `content` | `list` | i18n catalog editor: UI strings + route slugs (the two `data/framework/i18n/` families). `list` shows both as tables; a shared `?kind=ui\|slug` add / edit / confirmDelete / remove modal. Persistence + slug validation in the `TranslationCatalog` core service (NOT an `#[Entity]`). URL: `/backend/content/translation/{action}`. See [`translation.md`](translation.md) TRANS-TOOL-001. |

`NavigationController` extends **`AbstractTreeEntityController`** (which extends `BackendAbstractController`): it provides the generic `moveAction` (resolve → cycle-guard → `TreeService::reorderInto` → renumber old group → persist) once; the subclass supplies `treeRepo()`, `treeService()`, and the entity-specific `applyMovePolicy()` (cross-slot + ref-parent guards). `NavigationGroupController` was removed with `NavigationGroup` (ADR-022); the base is kept as the reuse seam for future tree-entity controllers. Mechanics vs. policy — see [`tree.md`](tree.md) / ADR-009.

## SystemController endpoints

| Action | Method | Purpose |
|---|---|---|
| `clearCacheAction` | POST | Clears APCu (`CacheManager::clearAllApcu`), `PageCache::clearAll`, versioned assets (`AssetCleaner::clearAll` with 30s grace). Returns `FetchResponse` with deleted-asset count. |
| `toggleDebugAction` | POST | Toggles DEBUG by creating/deleting `data/framework/debug.flag` (see [`bootstrap.md`](bootstrap.md)), then clears APCu + PageCache so stale entries from the previous DEBUG state cannot be served. |
| `toggleNoindexAction` | POST | Toggles the site-wide crawl block by creating/deleting `data/framework/seo/noindex.flag` (constant `SEO_NOINDEX`, see [`bootstrap.md`](bootstrap.md) / [`metadata.md`](metadata.md) SEO-NOINDEX-001), then clears APCu + PageCache so cached frontend pages re-render with/without the `robots` meta. Returns `data.noindex`. |
| `savePreferencesAction` | POST | Persists `UserPreferences` to `LoginUser` JSON. |

## user preferences

`UserPreferences` is a value object (`palette`, `dark_mode`). Stored in `LoginUser::$preferences` (serialized via `#[Entity]` to `loginUsers.json`). `CurrentUserService` provides per-request caching — `LoginUser` is loaded once and reused. Controllers MUST NOT load `LoginUser` directly for preferences.

```php
// BackendAbstractController::html() auto-injects three context vars:
$context['userPreferences'] = DI::getCurrentUserService()->getPreferences();
$context['bePalette']       = $context['userPreferences']->getPalette();             // e.g. 'werkbank'
$context['beTheme']         = $context['userPreferences']->isDarkMode() ? 'dark' : 'light';
```

```php
// html-shell-skeleton.tpl.php renders them as <html> attributes:
<html lang="de" data-be-palette="<?= e($bePalette) ?>" data-be-theme="<?= e($beTheme) ?>">
```

| Component | Role |
|---|---|
| `UserPreferences` (value object) | `palette` + `dark_mode` + `toArray()` |
| `LoginUser::$preferences` | array field, serialized via `#[Entity]` |
| `CurrentUserService` | per-request cache for `LoginUser`; owns `getPreferences()` + `savePreferences()` |
| `Palettes::all()` | catalog of selectable palettes (id + display name + accent for the picker buttons) |
| `BackendAbstractController::html()` | auto-injects `userPreferences`, `bePalette`, `beTheme` into every HTML context |
| `html-shell-skeleton.tpl.php` | renders `data-be-palette` / `data-be-theme` on `<html>` — CSS selectors in `_colors.scss` activate the matching token set |
| `_colors.scss` | single source of truth for palette + theme token values (`[data-be-palette="..."]` / `[data-be-theme="dark"]` selectors) |
| `appearance.js` | live palette/dark toggle; sets `<html>` data-attributes (instant switch) + POSTs to save endpoint |
| `SystemController::savePreferencesAction()` | persists `UserPreferences` via `CurrentUserService::savePreferences()` |

## clear-cache flow

```text
POST /backend/system/system/clear-cache
→ SystemController::clearCacheAction()
  → CacheManager::clearAllApcu()
  → PageCache::clearAll()
  → AssetCleaner::clearAll()  (30s grace, includes .map files)
  → MessageService::pushFlash('success', 'Cache geleert (...)')
  → $this->fetch()->setStatus('success')
```

Without cache-clear, a `PageCache` hit on a page that uses unchanged assets won't re-render → won't pick up new asset versions → old URL served. Functionally correct, but appears stale. Accepted by design.

## service panels (topbar)

The topbar's environment switcher and the avatar service panel are click-toggle
dropdowns driven by the shared, data-attribute `panel-toggle.js` (`Z77\Shared`) —
no per-panel JS.

- **Contract:** `[data-panel-root]` wraps a `[data-panel-trigger]` button and its
  `[data-panel]` element (markup carries the `hidden` attribute). Click toggles the
  panel (`hidden` + `aria-expanded`, optional ring via `data-panel-open-class`); a
  click outside any open panel or `Esc` closes; a click on a link / `[role=menuitem]`
  inside closes the panel and lets navigation proceed.
- **Collapsibles:** `[data-collapse-trigger]` (with `aria-controls`) toggles a
  `[data-collapse]` body — used by the avatar "Info" and "Aussehen" sections.
- Both panels start `hidden`. `.backend-topbar__env-menu` carries
  `&[hidden]{display:none}` so its class `display:flex` does not override the
  attribute (this was the "always open" bug).
- **Avatar "Info" section** shows the current routing context — module / controller
  / action / loaded `{Controller}/{action}.tpl.php` — from `routeInfo()` (shared
  `RouteInfoTrait`), injected by `BackendAbstractController::html()` as `routeInfo`.

## frontend admin overlay

Admins (role >= admin) get the environment switcher + routing info + logout on
every full frontend page, via a right-edge hover overlay.

- `AbstractFrontendController` (frontend base controller) injects it in `html()`
  ONLY when `AuthUser::hasAtLeast(AuthRole::ADMIN)` AND the request is `Page` mode:
  adds the `admin-overlay` CSS + the `partials/adminOverlay` partial into the body
  slot `adminOverlay` (rendered by the frontend `html-default-skeleton`). Guests get
  no markup, no CSS, no data.
- Reveal is pure CSS (`:hover` / `:focus-within`), no JS. The CSS
  (`admin-overlay.scss` → standalone `admin-overlay.css`) is **isolated**: it
  defines its own tokens + font on `.z77-admin-overlay` and does NOT `@use` the
  frontend tokens, so it overrides the frontend look completely.
- Routing info reuses the same shared `RouteInfoTrait`.

## user management (LoginUserController)

`/backend/system/login-user/list` — administers `LoginUser` accounts. Flat list
(no hierarchy), mirrors the navigation modal/fetch pattern WITHOUT the tree
machinery: it extends `BackendAbstractController` directly (not
`AbstractTreeEntityController`).

- `LoginUser` gained a `sortKey` (plain int, server-controlled — not via
  `TreeNodeTrait`, no `parentId`). New users get `nextSortKey()` (appended);
  the list orders by it. `moveAction` does a flat reorder: `{entry_id, new_index}`
  → renumber `sortKey` densely among the rest, persist changed rows.
- Edit form fields: `username` (`#[Clean('text')]`, validated unique on submit),
  `password` (plaintext, transient — hashed bcrypt cost 12; blank on edit = keep),
  `roles` (checkboxes `name="roles"` → array; validated against `AuthRole` keys),
  `initials` (`#[Clean('text')]`, optional avatar initials — see below).
  `passwordHash` / `roles` / `sortKey` are re-set server-side after
  `mapFromArray` — never trusted from the body.
- **Avatar initials**: `LoginUser::$initials` is optional user-entered display data
  (2–3 chars, `#[Clean('text')]`, `validateInitials` enforces the length only when
  non-empty). It maps straight from the form (NOT security-relevant, no server re-set).
  `BackendAbstractController::html()` fills `headerUser['initials']` from
  `CurrentUserService::getLoginUser()->getInitials()` when set, otherwise derives it
  from the username (first two letters, uppercased) — the previous behaviour. Existing
  users without the field keep the derived initials until one is entered. The topbar +
  service-panel avatars render this single `headerUser['initials']` string.
- `LoginUserValidator` overrides `executeValidation()` to also check the transient
  `password` (not an entity field). Live blur-check is format-only (no repo) —
  uniqueness runs on submit where the loaded entity excludes itself.
- **Password strength**: on add/edit the password is evaluated via
  `Z77\Shared\Auth\PasswordPolicy` (length + blocklist, NOT composition) using the
  installation-wide `PasswordTier` (resolved via `BackendAbstractController::passwordTier()`).
  Weak sets `LoginUser::passwordWeak` (drives the every-login nag). It is
  **allowed, never blocked — EXCEPT** under tier `veryStrong`, where
  `LoginUserValidator` adds a field error (hard block on save). See
  [`security.md`](security.md) (PWD-POLICY-001). The edit form shows a live strength
  meter (`password-meter.js`, hint only; min length passed via `data-z77-password-min`).
- **Delete protection** (`deleteBlockReason`, enforced in both `confirmDelete`
  and `remove`): the logged-in user may not delete their own account, and at
  least one **admin-capable** account must remain. Admin-capable = role LEVEL
  `>= AuthRole::ADMIN` in the hierarchy (`isAdminCapable`), NOT the literal
  `admin` role or a username — a `superUser` outranks an admin and counts too.
  (Mirrors `AuthService::hasSufficientRole`.)
- CSS/UI: uses the shared backend list hub — `.be-list` / `.be-tree--hub` in
  `base.css` (flat rows, no tree depth/toggle). The old per-page `navigation/list.css`
  + `.be-nav-*` were consolidated away (CSS-LIST-CONSOLIDATION-001). Row actions run
  through the `⋮` hub (`actionsAction` → `actions.tpl.php`), not inline buttons
  (LIST-ACTIONS-HUB-001, see [`css-backend.md`](css-backend.md)). `login-user/list.js`
  handles the flat DnD.
- The "Benutzer" navigation entry (`navigation.json` id 7) points here:
  `/backend/system/login-user/list` (was the placeholder `/backend/users/user/list`;
  the unused `users` group + its `groupDefaults` entry were removed).

## first-run setup (SetupController)

`/backend/system/setup/setup` — token-gated first-run admin creation for
**non-interactive** installs (the installer wrote a `SETUP_TOKEN` instead of
prompting). Registered with `AuthRole::GUEST` (no admin exists yet); self-locks
once a user exists; deletes the token after creating the admin. Full-page render
(like the login page) reusing the same `PasswordPolicy` + `password-meter.js`
(self-inits on DOM ready when statically included). No friendly `/setup` alias by
design. Owned by [`security.md`](security.md) — see it for the gating rules.

## rules

- When adding a new backend controller (HTML or Fetch) → MUST extend `BackendAbstractController`; MUST be placed inside the matching group subdirectory (`System/`, `Content/`, …) with the namespace `Z77\Module\Backend\Ui\Controllers\{Group}`; `userPreferences` is auto-injected; MUST NOT load `LoginUser` manually for preferences
- When registering a backend controller in `backendConfig.inc.php` → MUST declare its `group` matching the subdirectory; MUST list the group's default controller in `groupDefaults`
- When constructing a backend URL in templates or JS → MUST use the 4-segment form `/backend/{group}/{controller}/{action}` (e.g. `/backend/system/login/login`, `/backend/content/navigation/list`); MUST NOT use the old 3-segment form
- When adding action-specific CSS or JS → MUST register via `$this->layoutManager->addCss()` / `addJs()` in the controller action after calling `$this->html()`; MUST NOT use inline `<style>` or `<script>` blocks in templates
- When adding a backend Fetch endpoint → MUST return a `FetchResponse` envelope via `$this->fetch()` (see [`fetch.md`](fetch.md), [`messages.md`](messages.md))
- When defining a backend role-protected controller → MUST register in `backendConfig.inc.php` with the correct minimum role
- When adding interactive UI logic → MUST stay hand-written vanilla JS (MUST NOT introduce a JS build pipeline); reusable cross-page behaviour goes in a shared/module JS file (e.g. `panel-toggle.js`) loaded via `layoutConfig` `javascripts`, page-specific snippets stay inline in `partials/footer.tpl.php`
- When a topbar/panel needs a click-dropdown or a collapsible → MUST use the `panel-toggle.js` data-attribute contract (`data-panel-root` / `data-panel-trigger` / `data-panel`, or `data-collapse-trigger` / `data-collapse`); MUST NOT hand-write per-panel toggle JS
- When a panel element starts `hidden` but a class sets its `display` → MUST add `&[hidden]{display:none}` so the attribute wins (the UA `[hidden]` rule is overridden by any class `display` declaration)
- For popup modals (`be-modal`): the `.be-modal__body` is the ONLY scroll region — the flex chain (dialog → `.be-modal__inner` → `.z77-popup__body` → injected `<form>` → `.be-modal__body{flex:1;min-height:0;overflow-y:auto}`) MUST stay intact, every link a `min-height:0` flex column, or the body overflows the dialog's `overflow:hidden` (clipped, no scroll). A generic `[data-popup-fullscreen]` button in the skeleton dialog toggles `[data-fullscreen]` on the popup root (handled in the shared popup channel beside `[data-popup-close]`); applies to ALL popups. MUST NOT set an inline `max-width` on the `<dialog>` — CSS owns sizing so the `[data-fullscreen]` variant can override it.
- When a frontend controller renders pages → MUST extend `AbstractFrontendController` (not `AbstractBaseController`) so admins get the admin overlay; the overlay MUST stay gated by `AuthUser::hasAtLeast(AuthRole::ADMIN)` + `Page` mode
- When module chrome needs the current routing context (module/controller/action/template) → MUST `use Z77\Shared\Controller\RouteInfoTrait`; MUST NOT add it to the core `AbstractBaseController`
- When adding or changing a framework JS/CSS asset → MUST get it into `skeleton/public/assets/{ns}` before it resolves (the `FileFinder` reads `public/assets`, not `res/assets`). `public/` is **seed-once** (ADR-024): a plain `composer install` only seeds when `public/` is absent, so it will NOT update an already-deployed file — delete the file (or `skeleton/public`) and `composer install -d skeleton` to re-seed, or copy `res/assets/...` → `public/assets/{ns}/...` by hand

## see also

- [`routing.md`](routing.md) — 4-segment URL schema, convention fallback, group resolution
- [`../02-decisions/adr-005-module-architecture-and-url-grouping.md`](../02-decisions/adr-005-module-architecture-and-url-grouping.md) — rationale for groups + URL schema
- [`login.md`](login.md) — `AuthService`, role hierarchy, `AccessGuard`
- [`fetch.md`](fetch.md) — `FetchResponse` + CSRF
- [`bootstrap.md`](bootstrap.md) — DEBUG flag mechanism
- [`stylesheet.md`](stylesheet.md) — `AssetCleaner`, versioning, cache-clear
- [`css-backend.md`](css-backend.md) — Werkbank design tokens / SCSS sources
- [`../02-decisions/adr-009-tree-entity-naming-and-controller-split.md`](../02-decisions/adr-009-tree-entity-naming-and-controller-split.md) — why navigation element + group are two controllers; multi-word kebab controller URLs
- [`metadata.md`](metadata.md) — `MetaDataController` (SEO metadata CRUD), the `'public'` view-area flag, and the read path injected as `$metaData`

## known issues

- ARCH-P002 — resolved. `UserPreferences` moved to `Z77\Shared\ValueObjects\`.
- BUG-P001 — resolved. `Naming::toCamelCase` fixed; `LoginUser` save round-trip works again.
- **ARCH-B001** — resolved. `AuthService::savePreferences()` and `getPreferences()` removed. `login()` no longer accepts `UserPreferences`. Preference session writes/reads are now the controller's responsibility.
- User-preferences round-trip verified (Chrome + Firefox, 2026-05-15): palette + dark mode persist across logout/login and across browsers for the same user.
- **TOPBAR-ENV-001** — resolved 2026-05-30. Umgebungs-Switcher war immer offen: `.backend-topbar__env-menu{display:flex}` überschrieb das `hidden`-Attribut, sodass das (damals inline in `footer.tpl.php` vorhandene) Toggle die Sichtbarkeit nicht steuern konnte. Fix: `&[hidden]{display:none}` (Attribut gewinnt) + Avatar-/Umgebungs-Toggle in das shared `panel-toggle.js` konsolidiert (Klick-Toggle + Outside/Esc-Close, data-attribut-getrieben). **Wichtig:** das alte Inline-Toggle in `footer.tpl.php` musste entfernt werden — sonst banden beide Handler denselben Button und der Doppel-Klick-Toggle hob sich auf (Panel öffnete „nicht"). `footer.tpl.php` hält jetzt nur noch den Hamburger.
- **TOPBAR-INFO-001** — resolved 2026-05-30. Avatar-Panel hat eine aufklappbare „Info"-Sektion (Modul/Controller/Action/geladenes Template) via `RouteInfoTrait` + `data-collapse`.
- **FE-ADMIN-OVERLAY-001** — resolved 2026-05-30. Frontend-Admin-Overlay (Umgebung + Info + Logout) via neuem `AbstractFrontendController`, rollen-gated (`hasAtLeast(ADMIN)` + Page-Mode), isoliertes kompiliertes `admin-overlay.css` (eigene Tokens/Font, übersteuert Frontend). Hover-Reveal rein CSS.
- **FE-OVERLAY-LOGIC-001** — resolved 2026-07-07. `partials/adminOverlay.tpl.php` trug denselben Konventionsverstoss wie der alte Backend-Header: es griff auf das `AuthUser`-Security-Objekt zu und entschied die Sichtbarkeit im Template (`$authUser->hasAtLeast(ADMIN)`, `getUserName()`, `getHighestRole()`). Fix analog HEADER-AUTH-001: `AbstractFrontendController::html()` injiziert ein plaines `overlayUser` view-model (`initials`/`name`/`role`) NUR für Admins im Page-Mode (Auth-Entscheidung im Controller); das Template nutzt nur noch diese Strings + eine reine Daten-Präsenz-Zeile `if (empty($overlayUser)) return;` (kein Security-Objekt mehr, kein `hasAtLeast`-Gate). Das Partial wird weiter cross-module über `addPartials(..., self::NAMESPACE, 'adminOverlay')` geladen — die Namespace-Signatur trägt das. Gleichzeitig die verwaiste flache `partials/head.tpl.php` (nicht verdrahtet — `layoutConfig` nutzt `partials/head/*`; enthielt direktes `htmlspecialchars`) gelöscht.
- **AUTH-B002** — resolved 2026-06-02. Access-control `controllers` map was keyed by controller **base name** only, and `AuthService::resolveRoleForCurrentController()` looked it up without the group — two controllers sharing a base name across groups would have collided on one key (silent PHP array overwrite) and resolved the wrong role (security-relevant). Fixed by nesting `controllers` by group in `backendConfig` + `frontendConfig` (`controllers[$group][$controllerBaseName]`), removing the now-redundant per-entry `'group'` field, and making `AuthService` + `ModuleManager::getDefaultActionForController()` group-aware (callers `Request::setController`, `LoginController` redirect pass the group). Dead `ModuleManager::getRole()` (no callers, read the old flat map) deleted. Verified live: `/login` + frontend pages 200 for guests (nested GUEST/wildcard resolves), protected + convention-fallback backend routes 302 → `/login`. Same root cause as the ADR-005 template-nesting revision. Follow-up: CACHE-B001 (pending).
- **ROLE-DEF-001** — resolved 2026-06-03. `LoginUserController::ROLE_LABELS` no longer defines the role SET: the offered roles + order are derived from `AuthRole::getRoleHierarchy()` (SSOT) via the new `roleLabels()` method, which iterates the core hierarchy (highest first) and looks up a German label per *existing* role (fallback = the role key). `ROLE_LABELS` is now a presentation-only label dictionary — a stale entry for a removed role is simply never read, and a new core role appears automatically (with its key until labeled). Decision: labels stay in the module, role set from core. See [`login.md`](login.md) role system.
- **HEADER-AUTH-001** — resolved 2026-06-04. Topbar (Logo, Modul-Tabs, Umgebungs-Switcher, Avatar/Service-Panel) fehlte auf `/backend/content/content/list` komplett. Ursache: `header.tpl.php` brach via `if (empty($authUser) || !$authUser->isLoggedIn()) return;` ab, und `authUser` wurde NICHT zentral injiziert — jeder Controller musste es selbst übergeben; `ContentController` tat das nie. Doppelter Konventionsverstoss: Security-Entscheidung + Zugriff auf das `AuthUser`-Objekt im Template, obwohl die Auth bereits am Dispatch geregelt ist (`Dispatcher` → `AccessGuard::enforce()` VOR der Action; jeder Backend-Controller `controllerRole ADMIN`, nur login/setup GUEST). Fix: `BackendAbstractController::html()` injiziert ein plain `headerUser` view-model (`initials`/`name`/`role`) NUR wenn eingeloggt (Auth-Entscheidung im Controller); das Template nutzt nur noch diese Strings + eine reine Daten-Präsenz-Zeile `if (empty($headerUser)) return;` (kein Security-Objekt mehr). Redundante `authUser`-Zeile in `NavigationController`/`NavigationGroupController`/`LoginController` entfernt (Dashboard + LoginUser behalten `authUser`, deren Templates nutzen es für Begrüssung bzw. `$isSelf`). Restpunkt: login/setup-Skeleton (LAYOUT-B001), danach entfällt auch der Präsenz-Check.
- **MODAL-SCROLL-001** — resolved 2026-06-04. Popup-Edit-Fenster konnten nicht scrollen: `.be-modal__body` hatte zwar `overflow-y:auto; flex:1`, aber die Flex-Kette brach an `.z77-popup__body` + der injizierten `<form>` (normale Blocks) → der Body bekam keine begrenzte Höhe und wurde vom `overflow:hidden` des Dialogs abgeschnitten statt gescrollt. Fix: `.z77-popup__body` + `> form` als `min-height:0`-Flex-Spalten, `.be-modal__body{min-height:0}`. Gleichzeitig **Fullscreen-Toggle** für ALLE Popups: generischer `[data-popup-fullscreen]`-Button im Skeleton-Dialog → schaltet `[data-fullscreen]` am Popup-Root (im shared Popup-Channel neben `[data-popup-close]`; Reset beim Schliessen). Inline `max-width` am `<dialog>` entfernt → CSS steuert die Grösse (damit die Fullscreen-Variante übersteuern kann). `core.min.js` chirurgisch mitgepatcht (terser-Stil erhalten).
- **APPEARANCE-PIPELINE-001** — resolved 2026-05-27. Per-User-CSS-Generierung aus `BackendAbstractController::postExecute()` entfernt; `user-preferences.css.tpl.php` gelöscht. Palette/Theme-Wechsel jetzt über `data-be-palette` / `data-be-theme` Attribute am `<html>` (Server-rendered initial, `appearance.js` setzt sie bei Klick um — sofortige CSS-Selektor-Aktivierung). Token-Werte einzig in `_colors.scss`. Entfernt: inline `<script>` im Skeleton (localStorage-Sync), `TOKENS`-Hash + `_apply()` in `appearance.js` (187 → 77 Zeilen), `postExecute()`-Hook im Backend. Siehe auch [`css-backend.md`](css-backend.md).

- **LAYOUT-B001** — resolved 2026-07-04. login + setup (both GUEST, full-page) now render through a dedicated chrome-less skeleton instead of the authenticated shell. New `res/view/templates/html-guest-skeleton.tpl.php` (only `$main` + flash/messages, no topbar/subnav/preview/footer/header-slots); the self-contained `.be-guest` wrapper (`components/_guest.scss`) fills the viewport and centers the `.login`/setup card, independent of the media-gated `layout/*.scss`. Selected per controller via `documentTpl` override in `src/Ui/Config/System/loginControllerConfig.inc.php` + `setupControllerConfig.inc.php` (applied on top of the module `layoutConfig`, last `documentTpl` wins). Revert = delete the two config files. **Restpunkt (bewusst offen):** the module `layoutConfig` still registers the chrome partials for every backend controller (`applyLayoutConfig` is append-only — a controller config cannot *unset* a section), so for a GUEST the shell topbar/subnav still render (to empty output — topbar self-skips on absent `headerUser`) and are simply not echoed by the guest skeleton. Therefore the `if (empty($headerUser)) return;` guard in `partials/shell/topbar.tpl.php` **stays** — the target of removing it entirely would need `removeSection()` (decided against) or a bigger config-loader change. Also fixed in the same pass: the login/setup form-control overrides in `_login.scss` had been dead since the `.form`→`.be-form` / `.btn`→`.be-btn` migration (CSS-LIST-CONSOLIDATION-001) — they targeted `.form__control` / `.btn--primary`, so the inputs blended into the card (same `--be-surface`). Renamed to `.be-form__control` / dropped the now-redundant button + focus-ring overrides (base is palette/theme-aware). See [`css-backend.md`](css-backend.md) GUEST-SKELETON-001.

## pending

- **AUTH-B003** — slim the access-control `controllers` map in `backendConfig.inc.php`. Role resolution falls back Action → `controllerRole` → `moduleRole` → GUEST with `*` wildcards (`AuthService::resolveRoleForCurrentController`), so listing every action with the same role as its `controllerRole` is pure redundancy — backend does exactly that (everything `ADMIN`). An unlisted action inherits `controllerRole`, it is NOT denied, so enumeration is documentation + per-action override, not a security allowlist. Convention: `controllerRole` = the restrictive baseline; list an action only when it DEVIATES (loosen a public action, or tighten one). Mirror the lean `frontendConfig` form (`controllerRole` + `actions['*']`). Safe because backend's baseline stays `ADMIN` → a forgotten new action defaults to ADMIN. Document the convention in `login.md` role-system rules at the same time.
- **CACHE-B001** — `cache.controllers` map (page-cache TTL per controller) is still flat / not group-nested, and `ModuleManager::getCachePolicy()` looks it up by the URL-form controller segment while the access-control map uses PascalCase base names — two separate pre-existing inconsistencies in the same map. Currently empty in both module configs (no live data), so it is latent + performance-only (a collision yields a wrong cache TTL, not a security issue). Fix together with the key-form mismatch in a focused cache pass: nest `cache.controllers` by group and align the key form. Deliberately excluded from the AUTH-B002 fix to avoid mixing a security change with an unrelated cache key-form bug. See [`cache.md`](cache.md).
