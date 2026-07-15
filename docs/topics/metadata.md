# metadata

2026-07-14

## entry

1. `packages/kernel/shared/src/Entities/MetaData.php` — the per-page SEO entity (identity = navigationId + language)
2. `packages/kernel/core/src/Services/NavigationService.php` — `findMetaData()` is the read path (cached per id+lang)
3. `packages/module-backend/src/Ui/Controllers/Content/MetaDataController.php` — backend CRUD ("list by navigation point")

## file map

SOURCE=/packages/kernel/shared/src/Entities/MetaData.php
SOURCE=/packages/kernel/shared/src/Repositories/MetaDataRepository.php
SOURCE=/packages/kernel/shared/src/Validators/MetaDataValidator.php
SOURCE=/packages/kernel/core/src/Services/NavigationService.php
SOURCE=/packages/kernel/core/src/Services/ModuleManager.php
SOURCE=/packages/kernel/core/src/Controller/AbstractBaseController.php
SOURCE=/packages/module-frontend/src/App/Config/frontendConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Content/MetaDataController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/BackendAbstractController.php
SOURCE=/packages/module-backend/res/view/templates/Content/MetaDataController/listAction.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Content/MetaDataController/edit.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Content/MetaDataController/confirmDelete.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/partials/head/seo.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/partials/head/meta.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/partials/head/structured-data.tpl.php
SOURCE=/packages/kernel/core/src/Bootstrap.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/SystemController.php
SOURCE=/packages/module-backend/res/view/templates/partials/shell/noindex-banner.tpl.php
SOURCE=/packages/module-backend/res/view/templates/partials/head/seo.tpl.php
SOURCE=/packages/kernel/core/data/framework/seo/metadata.default.json
SOURCE=/packages/module-backend/src/App/Config/backendConfig.inc.php

RUNTIME=/skeleton/data/framework/seo/metadata.json
RUNTIME=/skeleton/data/framework/routing/navigation.json

## mental model

A `MetaData` record holds the SEO data (title, description, theme color, JSON-LD) for one navigation entry in one language. Identity is the pair (`navigationId`, `language`) — there is at most one record per page+language. The **read path** is fully automatic: `AbstractBaseController::html()` resolves the current `Navigation`, calls `NavigationService::findMetaData($navId, $lang)`, and injects the result as `$metaData` into every template; the frontend `head/*` partials render it (title, meta description, theme-color, `application/ld+json`). The **write path** is the backend `MetaDataController` (group `content`, URL `/backend/content/meta-data/{action}`), a standard clean→hydrate→validate CRUD (see `entity-data-handling.md`).

- Storage is a single JSON array file (`framework/seo/metadata.json`), int `id` auto-assigned by the FileRepository — same storage shape as `Navigation` (not per-record like `Content`).
- `id` is server-controlled (no setter, hydrated via reflection). `navigationId` + `language` are settable (chosen on add) but **immutable on edit** — forced back from the loaded record so a crafted body cannot re-point or duplicate.
- `application_ld` is edited as a raw JSON textarea (mirrors `Content` blocks): `setApplicationLd()` accepts an array (from file) or a JSON string (from form); invalid JSON → empty map, the validator reports the parse error from the raw string.
- The backend list is **"by navigation point"**, scoped to **public environments** and **grouped by level-0 environment**: it enumerates routable navigation entries (canonical URL non-empty, not a ref) only for pages in a public environment, and shows the metadata status (present / missing) per page. Missing → "Anlegen", present → "Bearbeiten" / "Löschen".
- **public vs. non-public is an environment-level property** steered by a `'public' => true` flag in the view-area module's config (next to `'viewArea'`). `ModuleManager::getPublicViewAreaKeys()` reads it (subset of `getViewAreaKeys()`). SEO metadata only applies to public environments — the admin backend (`'public' => false`) is excluded entirely. A page's environment === its `module` (view-area invariant: environment group name === module key), so grouping is by module.
- The list's **filter bar** lists public view areas (`ModuleManager::getPublicViewAreaKeys()`, labels via `getViewAreaLabel`, ADR-022); `?env=<name>` restricts to one. Server-side filter (plain links, full-page reload) — no JS asset.
- **Editing-language mode (CONTENT-LANG-001):** the list is scoped to the active editing language — session-sticky, shared with `ContentController` via `BackendAbstractController::contentEditLanguage()` / `setContentEditLanguage()` (key `backendContentLanguage`). The per-page status (`findByNavigationAndLanguage($id, $lang)`) is read for that language; a `?language=` request param only switches it. The `be-lang-switch` banner makes the active language prominent (and the `be-lang-tag` shows it in the editor modal). The **mode is the single source of a new record's language**: the "Anlegen" link carries only `navigation_id`, `addAction` seeds the mode language, the form shows `language` read-only, and the add POST forces it from the session — so a record is never created under the wrong language. The `?env=` filter (public environment) is orthogonal URL state and is preserved across language switches (the toggle links re-append it). Not hardcoded to a default anymore; the default applies only as the session fallback (`DI::getI18n()->getDefaultLanguage()`, ADR-013, see [`i18n.md`](i18n.md)).
- Lookups cached via `DataCache` per id+lang (`NavigationService` key `['meta', $id, $lang]`); the entity is `invalidatesCache: true`, so any backend write clears APCu.
- **Site-wide crawl block (SEO-NOINDEX-001)** is a SEPARATE axis from per-page `MetaData` — a single deployment-level switch (staging / pre-launch) that keeps the WHOLE site out of search engines. Source of truth is the flag file `data/framework/seo/noindex.flag` → the `SEO_NOINDEX` constant (defined in `Bootstrap::__construct()` next to `DEBUG`, see [`bootstrap.md`](bootstrap.md)). When active, the frontend `head/meta.tpl.php` emits `<meta name="robots" content="noindex, nofollow">` and the backend shows a persistent Störer (`.be-noindex-banner`). Toggled via the filesystem OR the backend service panel (`SystemController::toggleNoindexAction`, clears APCu + PageCache). There is NO per-page `noindex` field on `MetaData` yet.

## rules

- When reading metadata for the current request in a template → MUST use the injected `$metaData` (resolved once in `AbstractBaseController::html()`); MUST NOT call `NavigationService::findMetaData()` again from the template.
- When a navigation entry has no metadata or the route is a convention route (no entry) → `$metaData` is `null`; head partials MUST fall back to a default (`$metaData?->getTitle() ?: '...'`).
- When adding a `MetaData` record → MUST set `navigationId` to a routable navigation entry (non-empty canonical URL); `MetaDataValidator::validateNavigationId()` rejects containers/openers/refs.
- When editing a `MetaData` record → MUST treat `navigationId` + `language` as immutable: force them back from the loaded record after `mapFromArray()` (the controller does this); MUST NOT expose them as editable inputs on edit.
- When creating a second record for an existing (`navigationId`, `language`) pair → MUST be rejected; `validateNavigationId()` checks uniqueness via `MetaDataRepository::findByNavigationAndLanguage()` on add.
- When rendering `application_ld` into HTML → MUST `json_encode` the array into `<script type="application/ld+json">` (see `head/structured-data.tpl.php`); the editor stores/reads it as JSON text.
- When adding a new editable field to `MetaData` → MUST add a `#[Clean(...)]` attribute + dumb setter and a `validate{Field}()` method (see `entity-data-handling.md`); `id` stays setter-less.
- When deciding whether an environment's pages get SEO metadata → MUST steer it via the module config `'public'` flag (read through `ModuleManager::getPublicViewAreaKeys()`); MUST NOT hardcode environment/module names in the metadata controller or template.
- When a new public-facing view-area module is added → MUST set `'public' => true` in its config for its pages to appear in the metadata list; admin/internal view areas MUST set `'public' => false` (or omit it — defaults false).
- When reading the site-wide crawl-block state (frontend head, backend Störer, backend toggle) → MUST read the `SEO_NOINDEX` constant; MUST NOT re-derive it via `file_exists` in templates. When rendering the frontend `robots` meta → MUST put it in `head/meta.tpl.php` (charset/viewport/robots family), NOT `head/seo.tpl.php` (title/description/canonical).
- When scoping the metadata list / editor to a language → MUST use the shared editing-language mode (`BackendAbstractController::contentEditLanguage()`, session-sticky; `?language=` only switches via `setContentEditLanguage()`) exactly as `ContentController` does; a new record MUST inherit the mode language (force it on the add POST), MUST NOT expose a free language picker. The `?env=` filter MUST be preserved across language switches (re-append it in the toggle links).

## see also

- [`i18n.md`](i18n.md) — the central language policy (`defaultLanguage`, `languages`) this controller reads via `DI::getI18n()`; multi-language picker prerequisite
- [`navigation.md`](navigation.md) — `NavigationService::findMetaData()`, the `MetaData` entity field list, and the `$metaData` template-context contract live there
- [`entity-data-handling.md`](entity-data-handling.md) — the clean→hydrate→validate CRUD pattern `MetaDataController` follows
- [`content.md`](content.md) — closest CRUD sibling: identity = (slug, language), raw-JSON textarea editor, reload-on-save

## known issues

- **List showed the raw page identifier, not the localized name — resolved 2026-06-13.** The `be-tree__name` span rendered `$page->getName()` (the canonical/default identifier, e.g. `Home`), violating the navigation-display convention. It now uses `t('nav.' . $page->getAction(), [], $editLanguage)` — the same `nav.<action>` key the frontend header uses (see [`translation.md`](translation.md) rule "rendering a navigation entry's display name"), resolved to the **active editing language** so the name follows the DE/FR/EN switch (e.g. `Home` → `Accueil`). A page whose action has no `nav.<action>` key falls back to the default language, then to the action string — add the key via the backend translation tool.
- **Language switch in the list never moved off DE — resolved 2026-06-13.** The list passed the active editing language to the template under context key `language`, but `AbstractBaseController::html()` unconditionally injects `$context['language'] = DI::getRequest()->getLanguage()` (the render/request language) into every template — for backend routes always the default `de` (no `/fr/` prefix). So the template's `$language` was always `de` and the `be-lang-switch` highlighted DE regardless of `?language=`. Fixed by passing the editing language under key `editLanguage` (and using `$editLanguage` in the template), exactly as `ContentController` already does — `editLanguage` does not collide with the injected key. **Footgun:** `html()` silently overwrites the reserved context keys `language`, `navigation`, `metaData`, `seo`, `csrfToken`, `languageSwitch`, `clientI18n` — controllers must not use these as their own context keys.
- **Backend metadata per language — resolved 2026-06-06 (CONTENT-LANG-001).** The metadata list was hard-wired to `DEFAULT_LANGUAGE`. It now adopts the shared editing-language mode (see mental model "Editing-language mode" + the `MetaDataController` change): the list is scoped to the session-sticky editing language, the `be-lang-switch` banner switches it (`?language=`, preserving `?env=`), and a new record inherits the mode language (read-only field, forced server-side). Same mechanism as `ContentController` (see [`content.md`](content.md)). Entity + form already carried `language` — no data-model change.

- **SEO-NOINDEX-001 — site-wide crawl block — resolved 2026-07-14 (v1).** A deployment-level switch keeping the WHOLE site out of search engines (staging / pre-launch), distinct from per-page `MetaData`. Mirrors the `DEBUG` flag mechanism plus the weak-password nag philosophy (see [`security.md`](security.md) "allow but nag — usability without a forgotten-flag footgun"). Built: (1) flag file `data/framework/seo/noindex.flag` → constant `SEO_NOINDEX` in `Bootstrap::__construct()` ([`bootstrap.md`](bootstrap.md)); (2) frontend `head/meta.tpl.php` conditionally emits `<meta name="robots" content="noindex, nofollow">` (NOT `seo.tpl.php`); (3) backend toggle `SystemController::toggleNoindexAction` (POST `/backend/system/system/toggle-noindex`, clears APCu + PageCache) + a service-panel row + `system/cache.js` binding (live-updates the indicator AND the banner); (4) the **Störer** — a large white-on-danger, full-width, NON-dismissible banner (`partials/shell/noindex-banner.tpl.php` + `.be-noindex-banner`, [`css-backend.md`](css-backend.md)) rendered at the top of the shell via the `noindexBanner` body slot; always in the DOM (guest-guarded), `hidden` reflects the flag so the toggle shows/hides it live. The nag fires on the ACTIVE (blocked) state — a live site left on `noindex` is the dangerous-if-forgotten direction. Assets deployed to `skeleton/public` (ADR-024, seed-once). v2 (header + robots.txt + installer seeding) is pending above.

## pending

- **Structured JSON-LD editor** — `application_ld` is a raw JSON textarea (v1). A schema-aware editor (like the content block editor) is a possible later improvement.

- **SEO-NOINDEX-001 v2 — per-module / per-view-area scoping (decided direction).** v1 is a single site-wide switch (one flag → whole site). v2 makes the crawl block **view-area scoped**, steered per module exactly like the existing `'public' => true` flag (`ModuleManager::getPublicViewAreaKeys()`, see mental model + ADR-022): each public view area can be blocked individually, so a launched environment stays indexable while a still-private one is excluded. The `SEO_NOINDEX` constant stays the coarse global override; the per-area state layers on top of it. The frontend `head/meta.tpl.php` then decides from the CURRENT request's view area, not just the global constant; the backend Störer names which areas are blocked. Design open: where the per-area state lives (module config flag like `'public'` vs. a runtime toggle per area) — a config flag is deploy-controlled but invisible (the v1 forgotten-flag concern), a runtime toggle keeps the Störer safeguard.
- **SEO-NOINDEX-001 v2 — stronger staging riegel (later).** v1 ships a conditional `robots` meta tag only, which requires the page to be crawled first to be seen. Add `X-Robots-Tag: noindex` HTTP response header (applies to non-HTML too) + a `robots.txt` `Disallow: /` when the (v2: per-area) block is active. Also open: optionally seed the initial flag from `composer.json` `extra` on a fresh install (staging deploy → on) via the installer — convenience only; the Störer, not composer.json, is the forgotten-flag safeguard.
