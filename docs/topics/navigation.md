# navigation

2026-07-02

## entry

1. `packages/kernel/core/src/Services/NavigationService.php` — DI singleton, cache layer, `$current` state, navigation-tree lookups
2. `packages/kernel/core/src/Services/NavigationUrlResolver.php` — owns the NavigationAlias layer: alias cache + inbound match + outbound URL (ADR-015)
3. `packages/kernel/shared/src/Entities/Navigation.php` — structural entity (4-tuple + tree); NO url/slug/SEO (ADR-015)
4. `packages/kernel/shared/src/Entities/NavigationAlias.php` — canonical entry URL bound to a navigation (ADR-015)
5. `skeleton/data/framework/routing/navigation.json` — runtime navigation data

## file map

SOURCE=/packages/kernel/core/src/Services/NavigationService.php
SOURCE=/packages/kernel/core/src/Services/NavigationUrlResolver.php
SOURCE=/packages/kernel/core/src/Services/ModuleManager.php
SOURCE=/packages/kernel/core/src/Routing/Router.php
SOURCE=/packages/kernel/shared/src/Entities/Navigation.php
SOURCE=/packages/kernel/shared/src/Entities/NavigationAlias.php
SOURCE=/packages/kernel/shared/src/Entities/MetaData.php
SOURCE=/packages/kernel/shared/src/Repositories/NavigationRepository.php
SOURCE=/packages/kernel/shared/src/Repositories/NavigationAliasRepository.php
SOURCE=/packages/kernel/shared/src/Repositories/MetaDataRepository.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Content/NavigationController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Content/NavigationAliasController.php
SOURCE=/packages/kernel/shared/src/Validators/NavigationValidator.php
SOURCE=/packages/kernel/shared/src/Validators/NavigationAliasValidator.php
SOURCE=/packages/module-frontend/src/App/Config/frontendConfig.inc.php
SOURCE=/packages/module-backend/src/App/Config/backendConfig.inc.php
SOURCE=/packages/kernel/core/data/framework/routing/navigation.default.json
SOURCE=/packages/kernel/core/data/framework/routing/navigation_aliases.default.json
SOURCE=/packages/kernel/core/data/framework/seo/metadata.default.json

RUNTIME=/skeleton/data/framework/routing/navigation.json
RUNTIME=/skeleton/data/framework/routing/navigation_aliases.json
RUNTIME=/skeleton/data/framework/seo/metadata.json

## mental model

`NavigationService` is a DI singleton. **Navigation is purely structural (ADR-015): it carries no URL, slug, language, or SEO data — only the routing target (4-tuple) + tree.** The public URL of an entry lives in a separate `NavigationAlias` (canonical entry path, bound by `navigationId`). The **alias layer itself is owned by `NavigationUrlResolver`** (the alias cache + `urlFor`/`getCanonicalAlias`/`findByAliasPath`/`matchAlias`), extracted from NavigationService so the structure service is not overloaded with URL concerns. The resolver is alias-only — it never reads the navigation tree, so it has no dependency on NavigationService (no cycle); resolving a matched alias's `navigationId` to its `Navigation` is the `Router`'s job. NavigationService keeps `urlFor()` as a thin delegator (the seam templates already use). Inbound routing matches the alias first (longest-prefix), captures the trailing path segments as content slugs, and resolves the alias's navigation. Matched entry becomes `$current`. Templates receive `$navigation` and `$metaData` via `AbstractBaseController::html()`.

- Entries carry a 4-tuple — `module`, `group`, `controller`, `action` (since 2026-05-19, see ADR-005). Old 3-tuple entries are no longer supported.
- The canonical (4-tuple) path is **derived**, not stored: `/{module}/{group}/{controller}/{action}` (`getCanonicalPath()`). It is the routing TARGET, not the public URL.
- **No `url` / `params` fields (removed in Phase 4, ADR-015).** The public URL is a `NavigationAlias.path`; dynamic discrimination is a **content slug** (the path remainder after the alias), passed to the action via `Request::getSlugs()`. Multiple navigation entries MAY share one 4-tuple — identity is the alias `path`, not the 4-tuple (NAV-DUP-001 superseded).
- The outbound public URL of an entry is `NavigationService::urlFor($entry)` — the canonical alias path if one exists, else the 4-tuple path as a fallback (e.g. backend convention routes). Templates wrap it in `localizedUrl()`.
- Entries form a tree via `parentId` (the **child** owns the link): tree-roots carry a render-slot **`slot`** slug (string, e.g. `backend-main`, `frontend-meta`) and `parentId: null`; inner / leaf nodes carry `slot: ''` and `parentId: <parent id>`. An entry is either a tree-root (slot set, no parent) OR a child of one parent (slot empty, parent set) — never both, never neither (orphan). Enforced by `NavigationValidator::validateSlot()`. A single FK makes the old double-parent case structurally impossible.
- A given slot may be carried by multiple tree-roots (e.g. several `backend-main` sections in the topbar) — each is an independent subtree.
- **View areas + render-slots are CONFIG (ADR-022), not an entity.** A **view area** (environment) is a module declaring `'viewArea' => true`; its **render-slots** are its config `navSlots` map (ordered `slotKey => label`). The full slug used in data + templates is `{moduleKey}-{slotKey}` (`frontend-meta`). `ModuleManager` aggregates the **slot registry** (`getAllNavSlots` / `isKnownSlot`) + labels (`getViewAreaLabel` / `getNavSlots`). Layered model: view area (config) → render-slot (config) → navigation tree (data). The former `NavigationGroup` entity + its dedicated controller were removed (ADR-022 supersedes ADR-007 §1–3 and voids ADR-009's controller split).
- The invariant **view-area name === module key** makes the current view area the module of the current entry. A render-slot only appears where a layout template calls `getBySlot('{slug}')` (fail-fast: an unknown slug throws) — adding a navigation area = a `navSlots` entry + one render call. The backend topbar renders a dynamic environment switcher from `NavigationService::getViewAreas()`.
- A view-area module may additionally carry `'public' => true` — a publicly reachable, indexable environment whose pages warrant SEO metadata. Read via `ModuleManager::getPublicViewAreaKeys()`; consumed by the backend metadata screen (see [`metadata.md`](metadata.md)). Independent of `viewArea` (frontend: public; backend: not).
- **Refs (since 2026-05-20):** an entry can carry `ref: int` instead of routing fields. It is a UI-only pointer to another entry. Refs never match routes (`findByPath` skips them); their rendered href is `urlFor(target) + ?via=<refId>`. The `?via=` query param sets `$uiCurrent` so the section/sidebar of the ref is highlighted instead of the target's. `$current` (routing) and `$uiCurrent` (UI cursor) are deliberately separate — controllers see routing state, templates see UI state via `getUiCurrent()` / `isActive()`.
- **Ordering (since 2026-05-29):** sibling order is driven by an explicit `sortKey: int` field, never by file/record position. `getBySlot()` (tree-roots within a slot) and `getChildren()` (entries whose `parentId` equals this entry's id) sort by `sortKey`, id as a stable tie-breaker. This keeps ordering stable across a future ORM migration (a SQL result set has no inherent order without `ORDER BY sort_key`). `sortKey` is scoped to its sibling group: two entries in different groups may share the same value.
- All lookups cached via `DataCache` per URL / tag / id+lang.
- `null` results (no match) are NEVER cached — indistinguishable from a miss.
- Navigation hits provide module/group/controller/action explicitly — no convention routing cascade.

## NavigationService API

```php
// ── Alias / URL resolution (ADR-015) ─────────────────────────────────────
urlFor(Navigation $entry): string                      // delegates to NavigationUrlResolver::urlFor() (templates' seam)
// ── Routing / lookup ─────────────────────────────────────────────────────
findByPath(string $path): ?Navigation                  // static fallback: matches the canonical 4-tuple path only; skips ref entries
findById(int $id): ?Navigation                         // lookup by id via getAll() cache
getBySlot(string $slot): array                         // tree-roots carrying $slot; validates $slot against the ModuleManager registry and THROWS UnknownNavigationSlotException on miss; active-filtered, ordered by sortKey (id tie-break)
iterateSections(string $slot): Generator               // yields {section, children[], active} per level-1 entry; active = uiCurrent is anywhere in section subtree
iterateTree(Navigation $root): Generator               // yields {entry, depth, active} depth-first for all descendants
getActiveSectionBySlot(string $slot): ?Navigation      // active level-1 section for uiCurrent (searches full subtree)
resolveFirstNavigable(Navigation $entry): ?Navigation  // first navigable descendant — regular entry with URL OR ref entry (caller resolves target + ?via=)
getChildren(Navigation $entry): Navigation[]           // resolves ID children → entities; ordered by sortKey (id tie-break)
getViewAreas(): array                                  // list of {key, label, url, active} — view-area modules (ModuleManager) with a reachable entry; url via resolveViewAreaUrl, active vs getCurrentViewAreaName
getCurrentViewAreaName(): ?string                      // module of the current/ui entry (invariant: view-area name === module key)
getCurrent(): ?Navigation                              // routing target (set by resolveCurrent — the entry the URL matched)
getUiCurrent(): ?Navigation                            // UI cursor (uiCurrent ?? current); drives active section + sidebar highlight
resolveCurrent(string $module, string $group, string $controller, string $action, array $query = []): void // sets $current by 4-tuple, first match (no params since Phase 4; $query kept for signature)
resolveUiCurrent(?int $refId): void                    // sets uiCurrent from ?via= query param; ignored if refId doesn't point to current target
isActive(Navigation $entry): bool                      // true when entry === uiCurrent (works for refs and regular entries)
findMetaData(int $id, string $lang): ?MetaData         // cached per id+lang
```

Cache keys:
- `DataCache::get('NavigationService', ['all'])` — full navigation array (single APCu entry, invalidated by `clearAllApcu()` on any write)
- `DataCache::get('NavigationService', ['meta', $id, $lang])` — MetaData per id+lang

(Render-slots + view areas are config, read via `ModuleManager` — no navigation-group cache.)
- `DataCache::get('NavigationUrlResolver', ['aliases-all'])` — full NavigationAlias array (owned by the resolver)

`NavigationRepository` contains no query methods — all lookup logic is in `NavigationService`.

## NavigationUrlResolver API

Owns the NavigationAlias layer (ADR-015). Constructor deps: `NavigationAliasRepository` + `CacheManager` only — no navigation tree, so no NavigationService dependency. Registered as a DI singleton (`NavigationUrlResolver`), injected into `Router`, `NavigationService` (for the `urlFor` delegator + internal `resolveViewAreaUrl`), and read directly in `AbstractBaseController`.

```php
urlFor(Navigation $entry): string                      // public canonical URL: canonical alias path, else 4-tuple fallback ('' = container)
getCanonicalAlias(int $navigationId): ?NavigationAlias // the entry's canonical (public) alias, or null
findByAliasPath(string $path): ?NavigationAlias        // exact active-alias lookup by path
matchAlias(array $segments): ?array                    // longest-prefix match → {navigationId, slugs[]}; trailing segments = content slugs
```

`matchAlias` returns the matched `navigationId` (not the entity) — `Router::matchAlias` resolves it to a `Navigation` via `NavigationService::findById` and returns `{navigation, slugs}` to `Request::runParsing`. A dangling alias (id resolves to no entry) yields `null`.

## template context (injected by `AbstractBaseController::html()`)

| Variable | Type | Note |
|---|---|---|
| `$navigationService` | `NavigationService` | always |
| `$navigation` | `?Navigation` | current matched entry; `null` on convention routes |
| `$language` | `string` | extracted from URL prefix |
| `$metaData` | `?MetaData` | resolved once in `html()`; `null` if no entry or convention route |
| `$navSlot` | `string` | render-slot slug for the topbar/sidebar — `'backend-main'` in `BackendAbstractController` |

## Navigation entity fields

| Method | Returns | Notes |
|---|---|---|
| `getId()` | `?int` | |
| `getName()` | `string` | |
| `getUrl()` | `string` | the 4-tuple canonical path (= `getCanonicalPath()`); `''` for containers. Since Phase 4 there is no own url/params — for the PUBLIC url use `NavigationService::urlFor($entry)` (alias-aware), not this directly |
| `getCanonicalUrl()` | `string` | alias of `getCanonicalPath()` (kept for routability checks `=== ''`) |
| `getCanonicalPath()` | `string` | `/{module}/{group}/{controller}/{action}`; `''` for container entries (empty module) |
| `getModule()` | `string` | |
| `getGroup()` | `string` | UI/navigation group inside the module (since 2026-05-19) |
| `getController()` | `string` | |
| `getAction()` | `string` | |
| `getSlot()` | `string` | render-slot slug this tree-root attaches to (e.g. `frontend-meta`); `''` for child nodes. The slot set is config (ModuleManager `navSlots`, ADR-022), so this is a slug string, not an FK. Serialized as `slot`. User-chosen in the edit form (the slot radio); forced `''` on reparent, inherited from the former tree-root on move-to-top |
| `getRef()` | `?int` | id of the target this entry points to (UI-only pointer); `null` for normal entries |
| `getParam()` | `string` | optional UI-state query fragment appended to the href by `urlFor` (e.g. `key=front` → `?key=front`); a SWITCH TRIGGER for target-side session state (like `?via=`), NOT routing — `findByPath`/`resolveCurrent` ignore it. `''` = none. Serialized as `param`. `setParam` strips a leading `?`/`&` + whitespace (NAV-PARAM-002) |
| `isActive()` | `bool` | when false, entry + its entire subtree are hidden from UI iterators (frontend header, backend topbar/sidebar). URL routing is unaffected — direct hits still resolve |
| `getSortKey()` | `int` | order among siblings (children of one parent, or tree-roots of one tag); lower comes first. Server-controlled — set by `NavigationController` move/add logic, never by the edit form |
| `getParentId()` | `?int` | id of the parent entry; `null` = top-level tree-root. Children resolved via `NavigationService::getChildren()` (filters `parentId`). Server-controlled — set by add/move logic, forced server-side in the POST path so a crafted body cannot reparent |

## NavigationAlias + content slugs (since 2026-06-08, ADR-015)

Navigation has **no own URL** — the public, canonical entry URL of an entry is a
`NavigationAlias` row bound by `navigationId`. Dynamic per-content parts are **content
slugs** (the path remainder after the matched alias), resolved at runtime by the action.

**Inbound (`Request::runParsing`, precedence NavigationAlias → static navigation → convention):**
1. `matchAlias(segments)` — longest-prefix match over alias paths. The longest alias that
   prefixes the (canonical) path wins; everything after it is content slugs (positional, unbounded).
2. The matched alias → `navigationId` → `Navigation` → its 4-tuple sets the routing target.
3. Content slugs are stored on the request; the action reads them via `Request::getSlugs()`.

```text
/schweiz/stadt/basel
  alias "/schweiz/stadt" → navId 30 (frontend/main/city/show)
  slugs = ['basel']  → CityController::showAction resolves the entity itself
```

**Outbound:** `urlFor($entry)` returns the canonical alias path (default-language); templates
localize via `localizedUrl()`. For canonical/hreflang + language-switch the controller uses
`AbstractBaseController::currentCanonicalPath()` (canonical alias of `$current` + content slugs),
NOT the as-requested path — a non-canonical alias still emits the canonical one.

**Action contract:** `{ navigation: NavigationService::getCurrent(), slugs: Request::getSlugs() }`.
The action is language-agnostic — slugs arrive in canonical form (the SlugTranslator normalizes
all segments inbound, see [`translation.md`](translation.md)).

The old `params` map and friendly `url` field were removed (Phase 4): dynamic discrimination is a
content slug; the "same action, statically different pages" case belongs in the content layer
(a `Page` entity keyed by navigationId), not in routing.

## NavigationAlias entity

Stored in `navigation_aliases.json`. A plain URL→navigation mapping — NOT a tree node.

| Field | Type | Notes |
|---|---|---|
| `id` | `?int` | auto-assigned |
| `navigationId` | `?int` | FK to the `Navigation` this alias is the entry URL for (`#[Clean('int')]`, required) |
| `path` | `string` | canonical (default-language) entry path, e.g. `/home`, `/schweiz/stadt`. Normalized to one leading slash, no trailing. `#[Clean('slug')]` |
| `isCanonical` | `bool` | the single public entry URL of a navigation is its canonical alias; further non-canonical aliases may exist |
| `active` | `bool` | inactive aliases are skipped by lookups |

`NavigationAliasRepository`: `findByPath(string): ?NavigationAlias`, `findByNavigationId(int): NavigationAlias[]`.

`NavigationAlias`-CRUD is a **dedicated** `NavigationAliasController` (group `content`, URL
`/backend/content/navigation-alias/{action}`, default `list`): list/add/edit/confirmDelete/remove,
entity-CSRF scope `navigationAlias`. `NavigationAliasValidator` enforces: `navigationId` required +
existing, `path` non-empty + URL-clean + **unique across all aliases** (this is the uniqueness
invariant that replaced NAV-DUP-001), at most one canonical alias per navigation. The navigation
list view links to the alias screen ("URL-Aliase" button); the edit popup no longer has url/params inputs.

## moveAction endpoint (since 2026-05-21)

`POST /backend/content/navigation/move` — atomic tree mutation. Used by the list view's drag & drop. Payload:

| Field | Type | Note |
|---|---|---|
| `entry_id` | `int` | The entry being moved (required, > 0) |
| `new_parent_id` | `int` | Target parent's id; `0` = move to top-level |
| `new_index` | `int` | Zero-based position among target siblings; clamped to range |

Rejected with `fetchError` when:
- entry or target parent not found
- target parent is a ref entry (refs cannot have children)
- target parent is the entry itself or one of its descendants (cycle protection)
- source and target tag-roots differ (cross-tag moves disallowed — see rules)

Structure is the moved entry's own `parentId` (the child owns the link): the move sets `entry.parentId = new_parent_id` (or `null` for top-level) — no parent record is touched. Ordering via `sortKey`: the target sibling group is read in `sortKey` order, the entry spliced in at `new_index`, then the whole group renumbered `0..n`. When the entry leaves its old group, that group is renumbered too (dense, gap-free).

On reparent: the moved entry's `tag` is set to `null` (becomes a child); on move to top-level: `parentId` becomes `null` and the tag is preserved (or inherited from the source tree-root if absent).

## MetaData entity fields

`getId()`, `getNavigationId()`, `getLanguage()`, `getTitle()`, `getDescription()`, `getThemeColor()`, `getApplicationLd()`
`getApplicationLd()` returns array → `json_encode` for `<script type="application/ld+json">`.

## view areas + render-slots (config, ADR-022)

View areas (environments) and their render-slots are **module config**, read through
`ModuleManager` — there is **no `NavigationGroup` entity**. A view-area module declares:

```php
'viewArea'      => true,
'viewAreaLabel' => 'Frontend',
'navSlots'      => ['main' => 'Hauptnavigation', 'meta' => 'Fusszeile'],
```

The full slot slug = `{moduleKey}-{slotKey}` (`frontend-meta`). `ModuleManager` reads it:

| Method | Returns |
|---|---|
| `getViewAreaKeys()` | view-area module keys (`viewArea: true`) |
| `getViewAreaLabel($key)` | env display label (config `viewAreaLabel`, ucfirst fallback) |
| `getNavSlots($key)` | ordered `fullSlug => label` for one env |
| `getAllNavSlots()` | the slot **registry** — every valid slug across all envs |
| `isKnownSlot($slug)` | membership check — `getBySlot` throws `UnknownNavigationSlotException` when false |

A navigation tree-root references its slot by the slug string `Navigation::slot` (not an
FK — the slot is a config constant). Adding a navigation area = a `navSlots` entry + one
`getBySlot('{slug}')` render call in the layout (where an area appears is a layout
decision). No slot CRUD, no group controller (ADR-022).

## navigation structure (parentId FK)

All records are flat in `navigation.json`. The tree is expressed by `parent_id` on each **child** (`null` = top-level tree-root) — no embedded objects, no `children` array. `NavigationService::getChildren($entry)` resolves children by filtering `parentId === entry.id`, sorted by `sortKey`.

Tree-roots (entries with a `slot`, `parent_id: null`) and inner/leaf nodes (`slot: ''`, `parent_id` set) form a single tree per slot. Container roots have empty `module/group/controller/action` (and no alias) — they are never matched by the router. The backend topbar finds a routable URL via `resolveFirstNavigable()` (depth-first first descendant with a URL).

```text
id:1  Webseiten   (slot:backend-main,  parent:null)      ← topbar section
id:2  Stammdaten  (slot:backend-main,  parent:null)      ← topbar section
id:3  Home        (slot:frontend-main, parent:null)      ← frontend header
id:6  Navigation  (slot:'',            parent:2)         ← child of id:2 (sidebar)
id:8  Login       (slot:backend-auth,  parent:null)      ← routing only
id:11 Legal       (slot:frontend-meta, parent:null)      ← frontend footer
id:12 Privacy     (slot:frontend-meta, parent:null)
```

## navigation.json slot (convention)

A navigation tree-root carries a single **render-slot** slug (`Navigation::slot`); its children carry `slot: ''`. The valid slots are exactly the config `navSlots` (the ModuleManager registry). A navigation entry never carries a bare view-area key — only a full render-slot slug.

| View area (module) | Render-slot slug | Use |
|---|---|---|
| `frontend` | `frontend-main` | frontend pages — flat in frontend header (`getBySlot('frontend-main')`) |
| `frontend` | `frontend-meta` | frontend footer navigation (`getBySlot('frontend-meta')`) |
| `backend` | `backend-main` | backend topbar sections (`iterateSections('backend-main')`, set as `navSlot`); subtree rendered in sidebar |
| `backend` | `backend-auth` | login/logout entries — routing only, not rendered in any UI |

Display labels of navigation entries come from `Navigation::getName()`; render-slot labels come from config (`navSlots`).

Project-specific tags are free. Tags stored in `tags.default.json` / `tags.json` are the "known" tags with labels.

## default navigation entries (`navigation.default.json`)

Entries carry **no `url` / `params`** (removed Phase 4). Every entry carries `sort_key: int`
(order within its sibling group). Public URLs live in `navigation_aliases.default.json` (see below).

| id | name | module | group | controller | action | tag | parent |
|---|---|---|---|---|---|---|---|
| 1 | Webseiten | — | — | — | — | `backend` | — |
| 2 | Stammdaten | — | — | — | — | `backend` | — |
| 3 | Home | `frontend` | `main` | `index` | `home` | `frontend` | — |
| 4 | About | `frontend` | `main` | `index` | `about` | `frontend` | — |
| 5 | Services | `frontend` | `main` | `index` | `services` | `frontend` | — |
| 6 | Navigation | `backend` | `content` | `navigation` | `list` | _(null)_ | 2 |
| 7 | Benutzer | `backend` | `users` | `user` | `list` | _(null)_ | 2 |
| 8 | Login | `backend` | `system` | `login` | `login` | `backend-auth` | — |
| 9 | Logout | `backend` | `system` | `login` | `logout` | `backend-auth` | — |
| 10 | Contact | `frontend` | `main` | `index` | `contact` | `frontend` | — |
| 11 | Legal | `frontend` | `main` | `index` | `legal` | `frontend-meta` | — |
| 12 | Privacy | `frontend` | `main` | `index` | `privacy` | `frontend-meta` | — |

## default aliases (`navigation_aliases.default.json`)

One canonical alias per public-facing entry; backend convention routes need none (reachable via
the 4-tuple). Seeded from the former friendly `url` values.

| navigation_id | path | is_canonical |
|---|---|---|
| 3 | `/home` | true |
| 4 | `/about` | true |
| 5 | `/services` | true |
| 8 | `/login` | true |
| 10 | `/contact` | true |
| 11 | `/legal` | true |
| 12 | `/privacy` | true |

(The runtime `navigation_aliases.json` may differ from this seed.)

## rules

- When rendering `href` in a template → MUST use `NavigationService::urlFor($entry)` (alias-aware) for regular entries, wrapped in `localizedUrl()`; for ref entries MUST resolve `urlFor(target) . '?via=' . refEntry.getId()`. MUST NOT emit `$entry->getUrl()` raw (that is the 4-tuple path, not the public URL)
- When a cache lookup returns `null` (no match) → MUST NOT cache the `null` (indistinguishable from miss)
- When resolving an entry's children → MUST use `NavigationService::getChildren($entry)` (filters `parentId === entry.id`, sorted by `sortKey`). The entity has no `children` accessor; the tree link lives on the child as `parentId`
- When adding a new navigation entry → MUST set `module/group/controller/action` for routable entries; MUST leave all four empty for tree-root containers and openers. A public friendly URL is a separate `NavigationAlias` row (managed in the alias screen), NOT a field on the entry
- When adding a new backend topbar section → MUST add a tree-root with empty routing fields, `tag: "backend"`, and at least one child (else the tab renders inert)
- Login/Logout routing entries MUST use tag `backend-auth` — no UI rendering, routing lookup only
- Opener entries (sidebar grouping with children but no own link) → MUST have all four routing fields empty, `slot: ''`, a `parentId` (they are children of a `backend` tree-root, not roots themselves), and at least one entry pointing at them via `parentId`
- When rendering a topbar tab → MUST use `NavigationService::resolveFirstNavigable($section)` for the `href`; if the result is `null`, render as inert `<span>` (no `<a href="">` — browsers reload the page)
- Every entry MUST satisfy `slot XOR parentId`: a tree-root carries a render-slot slug and `parentId: null`; an inner/leaf node has `slot: ''` and `parentId` set. The slot MUST be a **registered** render-slot (`ModuleManager::isKnownSlot`, ADR-022) — an unregistered slug is rejected. Enforced by `NavigationValidator::validateSlot()` (XOR + orphan + registry-membership inlined; the shared `ElementAnchorRules` was removed with `NavigationGroup`). Ref entries are exempt — they are validated by `validateRef()` (must have no routing fields, must not be a parent of any entry, no slot, target must exist and not be a ref itself)
- Active-state checks in templates MUST use `NavigationService::isActive($entry)` — it compares against `$uiCurrent ?? $current`, so refs highlight correctly when `?via=` is set. Do NOT compare URLs by hand.
- When an entry must carry a UI-state switch onto its link (e.g. open a target in a specific session view) → MAY set `Navigation::param` to a bare query fragment (`key=front`); `NavigationUrlResolver::urlFor` appends it (`?`/`&`-aware). MUST NOT use `param` for routing discrimination — `findByPath`/`resolveCurrent` ignore it (they match the 4-tuple only), so two entries differing only by `param` are the SAME route (ADR-015 stands: routing is param-free; `param` is outbound UI-state only, the menu-link analogue of `?via=`). To RESET a target's sticky session view, an entry MAY carry a blank `param` value that still emits the key (e.g. `key=`), so the switch-trigger fires with an empty value.
- When rendering a ref entry's href (target URL + `?via=<refId>`) → MUST use `NavigationService::urlForVia($target, $refId)`, never a manual `. '?via=' .` concat: a target carrying a `param` already has a `?`, so hand-concat would emit a double `?`. `urlForVia` joins with `?`/`&` correctly.
- In the backend subnav, ANY entry with children renders as an opener (`<details>`), regardless of whether it can produce a link of its own (render-level rule, distinct from the data-level "container" notion). The opener summary is a toggle, not a link — to keep an opener's own page reachable, add a ref-to-self child. Every rendered node MUST resolve its href the same way (ref → `urlFor(target).'?via='.refId`, else `urlFor(node)`); a node that resolves to an empty URL MUST render inert (`<span>`, never `<a href="">`). There is deliberately NO validation for dead links or childless openers.
- An entry MUST have at most one parent — guaranteed by construction via the single `parentId` FK (the old double-parent case is structurally impossible, no validator needed). `parentId` MUST stay server-controlled: set by `addAction` (from the `?parent` target) and `moveAction` (with cycle / ref-parent / cross-slot guards), and forced server-side in the edit POST path so a crafted body cannot reparent
- The **alias `path`** MUST be unique across all aliases — `NavigationAliasValidator` rejects duplicates (this replaced NAV-DUP-001's 4-tuple-uniqueness). Multiple navigation entries MAY share one 4-tuple; `NavigationValidator::validateModule()` only enforces the all-or-nothing routing-field structure. At most one canonical alias per navigation.
- `active: false` MUST NOT render the entry (or its subtree) in any UI iterator. NavigationService filters at the source (`iterateSections`, `iterateTree`, `getBySlot`, `resolveFirstNavigable`) — inactive entries do not enter the DOM. Routing (`findByPath`, `resolveCurrent`) ignores `active` — direct URL hits still resolve. The backend list view is the one exception: it calls `repo->findAll()` directly and renders inactive entries with the `.be-nav-node--inactive` modifier so they remain editable.
- Sibling order MUST be read from `sortKey` (id as tie-breaker), never from file/record order — `getBySlot()` and `getChildren()` enforce this. New entries get the next free `sortKey` at the end of their group (`NavigationController::nextSortKey`); `moveAction` renumbers affected groups densely. `sortKey` is server-controlled — no edit-form field maps to it.
- Cross-slot DnD moves MUST be rejected (`moveAction`). A frontend entry dropped into a backend subtree would silently change its slot; the constraint keeps slot scope explicit. To move across slots, edit the entry and reassign the slot manually.
- A view area (environment) is **config**, not a navigation entry: a module declares `'viewArea' => true` + `navSlots` in `<module>Config.inc.php` (ADR-022). `ModuleManager::isKnownSlot` is the registry `NavigationValidator::validateSlot` checks a tree-root's slot against. A navigation entry MUST carry a full render-slot slug (`{module}-{slot}`), never a bare view-area key.
- The environment switcher MUST read `NavigationService::getViewAreas()` (view-area modules with a reachable entry); entry URL is derived (`resolveViewAreaUrl`), never stored. The backend list view MUST group hierarchically (view area → render-slot → tree) via `ModuleManager::getViewAreaKeys()` / `getNavSlots()`.

## see also

- [`routing.md`](routing.md) — alias-precedence inbound flow, content-slug capture (`getSlugs`), 4-segment URL parsing, action constraints
- [`../02-decisions/adr-015-navigation-alias-and-content-slugs.md`](../02-decisions/adr-015-navigation-alias-and-content-slugs.md) — **binding decision**: Navigation purely structural, `NavigationAlias` owns the URL, content slugs, two-layer cache, metadata-from-entity (supersedes ROUTE-DYN-001 + NAV-URL/PARAMS/DUP)
- [`../02-decisions/adr-022-view-areas-and-nav-slots-in-module-config.md`](../02-decisions/adr-022-view-areas-and-nav-slots-in-module-config.md) — **binding decision**: view areas + render-slots are module config (ModuleManager), `NavigationGroup` entity/controller removed, `Navigation.slot` slug + fail-fast `getBySlot` (supersedes ADR-007 §1–3, voids ADR-009's controller split)
- [`../03-development/navigation-slots-config-bauplan.md`](../03-development/navigation-slots-config-bauplan.md) + [`review-navigation-areas.md`](../03-development/review-navigation-areas.md) — the ADR-022 build plan + the review it came from
- [`../02-decisions/adr-005-module-architecture-and-url-grouping.md`](../02-decisions/adr-005-module-architecture-and-url-grouping.md) — group semantics + URL schema rationale
- [`../03-development/navigation-entscheidungs.md`](../03-development/navigation-entscheidungs.md) — decision record for the `url` field semantics (2026-05-20)
- [`fetch.md`](fetch.md) — confirm-delete pattern (GET partial + entity CSRF + POST remove) and all `FetchResponse` commands used by `NavigationController` mutating actions
- [`entity-data-handling.md`](entity-data-handling.md) — clean→hydrate→validate pipeline; `NavigationController` is the prototype for this pattern
- [`../03-development/review-navigation.md`](../03-development/review-navigation.md) — Review (2026-05-29): Bereichs-Organisation (tag-Überladung, offen) + ORM-Tauglichkeit (`sortKey` + `parentId` umgesetzt; NAV-R002 erledigt)
- [`../02-decisions/adr-007-navigation-tree-model.md`](../02-decisions/adr-007-navigation-tree-model.md) — binding decision: layered model, opener/refs/`$uiCurrent`, server-controlled ordering (§1–3 the tag/group-tree layer is **superseded by ADR-022** — that layer is config now)
- [`tree.md`](tree.md) — the generic tree foundation (`TreeNode` / `TreeNodeTrait` / `TreeService`) Navigation sits on: sorting, children/roots, sibling grouping, move/reorder, cycle detection
- [`../02-decisions/adr-008-tree-foundation.md`](../02-decisions/adr-008-tree-foundation.md) — binding decision to extract the tree primitive into interface + trait + service (supersedes ADR-007's "defer until third consumer")
- [`../03-development/navigation-umgebung-bauplan.md`](../03-development/navigation-umgebung-bauplan.md) — build plan for the environment switcher (group tree + `viewArea` + dropdown + hierarchical list)
- [`../02-decisions/adr-009-tree-entity-naming-and-controller-split.md`](../02-decisions/adr-009-tree-entity-naming-and-controller-split.md) — `{Element}Group` naming convention + multi-word kebab controller URLs (the `NavigationGroup` split itself is **voided by ADR-022** — the group entity/controller were removed)
- [`metadata.md`](metadata.md) — per-page SEO `MetaData` (read path `findMetaData` + `$metaData` injection live here; backend CRUD + `'public'` environment flag live there)

## known issues

- **HDR-001** — resolved 2026-05-17. `$palettes` extracted from `header.tpl.php` into dedicated config class `Z77\Module\Backend\App\Config\Palettes::all()`; template now reads via `Palettes::all()`.
- **HDR-002** — resolved 2026-05-17. Hardcoded `admin · z77 Backend` label replaced with `$authUser->getHighestRole()`. Added `AuthUser::getHighestRole(): string` (returns highest role per `AuthRole` hierarchy, falls back to `GUEST`).
- **NAV-EDIT-001** — resolved 2026-05-19. Post-umbau follow-up: `edit.tpl.php` was missing a `group` input in the Routing section. New entries could not declare their group; existing entries preserved it only because `BodyCleaner` skips absent keys. Group field is now part of the form between Modul and Controller.
- **NAV-EDIT-002** — resolved 2026-05-19. URL-input placeholder in `edit.tpl.php` updated from `/modul/controller/action` (3-seg) to `/modul/group/controller/action` (4-seg).
- **NAV-URL-001** — resolved 2026-05-20. `alias_url` field removed; `url` field semantics changed from "stored canonical" to "optional friendly URL". Canonical is now derived from `module/group/controller/action`. See `../03-development/navigation-entscheidungs.md`.
- **NAV-DUP-001** — resolved 2026-05-21. Duplicate canonical URLs across entries used to be silently shadowed (`findByPath` returns first match). `NavigationValidator::validateModule()` now rejects duplicates with a FieldError that names the conflicting entry and suggests using a ref. Verweis-Dropdown in `edit.tpl.php` is now also visible when adding a child, so the suggested fix is one click away.
- **NAV-REF-UI-001** — resolved 2026-05-21. Ref entries had no visible URL in the list view (`be-nav-node__url` empty). Now renders `Verweis → #X · Name (target-url)` with `.be-nav-node--ref` modifier (left accent border) and a dedicated `.be-nav-node__ref-label` chip. Backend edit popup gained a JS toggle (`navigation/edit.js`) that hides + clears the routing section when a ref is selected, and shows it again when the ref is cleared. Script is lazy-loaded via the new `load-script` command (see `messages.md` HTML-FETCH-ENVELOPE-001).
- **NAV-PARAMS-001** — resolved 2026-05-21. Added `params: Map<string,string>` field to `Navigation`. `getCanonicalUrl()` and `getUrl()` now append `?key=value&...`. `NavigationService::findByPath()` and `resolveCurrent()` use strict subset match on params, with most-matches-wins tie-breaking; `Dispatcher::resolveNavigation()` passes `$_GET` through. `NavigationValidator::validateModule()` allows same-route siblings when params differ; new `validateParams()` enforces exclusivity with friendly `url` and rejects params on ref/container entries. Edit popup gained dynamic params editor (key/value rows synced into hidden JSON input); `Navigation::setParams()` accepts both array (from JSON file) and JSON-encoded string (from form). Default JSON updated to include `params: {}` on every entry; runtime navigation.json is forward-compatible (missing key → empty map).
- **NAV-ACTIVE-001** — resolved 2026-05-21. Added `active: bool` field (default true) to `Navigation`. NavigationService iterators (`iterateSections`, `iterateTree`, `getByTag`, `resolveFirstNavigable`) filter inactive entries — they are not rendered in any user-facing UI (frontend header, backend topbar/sidebar). Routing (`findByUrl`, `resolveCurrent`) intentionally ignores `active` so direct URL hits and bookmarks keep working. Backend list view renders inactive entries with `.be-nav-node--inactive` modifier (opacity + line-through) so they remain editable. Edit popup gained checkbox; controller emits `set-class` command on save to toggle the modifier in place (new generic command in `core.js`).
- **NAV-DND-001** — resolved 2026-05-21. Backend navigation list gained drag & drop: top-third of a row drops "before" (reorder sibling), bottom-third "after", middle "into" (reparent as child). New `NavigationController::moveAction` performs the tree mutation atomically — removes entry from old parent's `children`, inserts at new position in new parent's `children` (or in top-level JSON order when `new_parent_id=0`), nullifies the tag on reparent. Cross-tag moves, cycles, and ref-as-parent are rejected with `fetchError`. Front-end JS (`navigation/list.js`) handles drag state, drop indicators (`--drop-before`/`--drop-after`/`--drop-into` modifiers), and DOM relocation after successful POST. Visual indicators in `list.css`. _(Mechanics superseded: ordering by `sortKey` (NAV-SORT-001), structure by `parentId` (NAV-PARENTID-001) — no longer `children[]` / file order.)_

- **NAV-SORT-001** — resolved 2026-05-29. Added explicit `sortKey: int` field (named after the wdv framework's `sortKey`). Replaces the implicit ordering that lived in file/record order (top-level roots) and `children[]` array order (children). `NavigationService::getByTag()` / `getChildren()` now sort by `sortKey` (id tie-break); `children[]` is membership-only. `moveAction` rewritten: reads the target sibling group in `sortKey` order, splices the entry at `new_index`, renumbers the group `0..n`, and renumbers the old group when the entry leaves it — no longer uses `EntityManager::reorder()` (file-order rewrite). New entries get the next free `sortKey` via `nextSortKey()`. Dead `sortAction` (file-order reorder, never called by the DnD client) removed; its backend ACL entry swapped for the active `moveAction`. Both `navigation.default.json` and runtime `navigation.json` migrated with `sort_key`. Motivation: ORM-readiness — a SQL result set has no inherent order without `ORDER BY`, so the prior implicit ordering would have silently broken on the Doctrine migration. See [`../03-development/review-navigation.md`](../03-development/review-navigation.md) NAV-R002. Note: `EntityManager::reorder()` is now unused codebase-wide — removal from the persistence interface is a separate decision.
- **NAV-ENV-001** — resolved 2026-05-29. Tag wurde zur Baum-Entity (`parentId` + `sortKey`); Top-Level-Tags sind Umgebungen (view areas), Kind-Tags sind Render-Slots. Umgebung an Modul gebunden via `'viewArea' => true` + `ModuleManager::getViewAreaKeys()`; `TagValidator::validateParentId()` erzwingt Top-Level-Name ∈ View-Areas. `NavigationService` um Umgebungs-API erweitert (`getTopLevelTags`, `getTagChildren`, `getViewAreas`, `getCurrentViewAreaName`, `resolveViewAreaUrl`, `firstNavigableInclusive`). Backend-Topbar: statisches `Umgebung`-Badge → dynamischer Switcher (Dropdown). `navigation/list` jetzt hierarchisch (Umgebung → Render-Slot → Baum). Daten migriert: Tree-Roots `tag: frontend`→`frontend-main`, `backend`→`backend-main`; `tags.*.json` als Baum. Festgeschrieben in ADR-007. Folgepunkte offen: Parent-Feld in Tag-Anlage, Env-Delete-Schutz, Rollen-Gate im Switcher.

- **NAV-OPENER-CSS-001** — resolved 2026-05-29. Opener-CSS-Polish (Teil B aus `../03-development/navigation-opener-entscheidungs.md`) abgeschlossen in `module-backend/res/scss/components/_subnav.scss`: `<summary>`-Default-Marker versteckt (`list-style: none` + `::-webkit-details-marker`), Pfeil-Rotation bei `[open]`, Active-Subtree-„Trail"-Highlight (`--has-active-child` setzt Label medium-weight und tint Chevron + Count in `--be-accent` — erkennbar, ohne mit dem echten `--active`-Knoten zu konkurrieren), Ref-Styling (inset Accent-Border ohne Layout-Shift + dezentes `↗`-Glyph via `::after`, folgt `currentColor`). Entfernt: redundante `.backend-tree-node--ref.backend-tree-node--active`-Regel (setzte denselben `box-shadow` wie die Basis; `--active` überschreibt `box-shadow` nie).

- **NAV-PARENTID-001** — resolved 2026-05-29. Tree ownership flipped from parent-owns-`children: int[]` to child-owns-`parentId: ?int` (the relationally correct FK direction). `children` field + accessors removed from `Navigation`; added `parentId` + `getParentId()`/`setParentId()` (server-controlled, no `#[Clean]`). `NavigationService::getChildren()` now filters `parentId === entry.id` (sorted by `sortKey`). `moveAction` sets `entry.parentId` directly (no parent record touched); `addAction` sets it from the `?parent` target; the edit POST path forces `parentId` server-side so a crafted body cannot reparent and bypass the move guards. `NavigationValidator`: `validateTag` XOR now reads `parentId`; `validateChildren` deleted (single FK makes double-parent impossible); `validateRef` checks "is anyone's parent" via scan. Backend list `ungrouped` now means true orphans (`tag null && parentId null`), fixing the prior duplicate-render of children. Both data files migrated (`children` → `parent_id`). Obsolete `OBSOLETE-Navigation*.php` removed. Motivation: Navigation is the blueprint for upcoming tree entities (document, article, content, …) and a shared tree foundation must stand on the clean representation — see [`../03-development/review-navigation.md`](../03-development/review-navigation.md) NAV-R002. Maps to Doctrine `#[ORM\ManyToOne] $parent` + `#[ORM\OneToMany] $children` with `#[ORM\OrderBy(['sortKey' => 'ASC'])]` at the ORM migration with no consumer change.

- **NAV-TAGADD-001** — resolved 2026-05-30. Child-Tags (Render-Slots) lassen sich jetzt über die UI anlegen. `addTagAction` liest `?parent`; `editTag(Tag, ?Tag $parent)` setzt `parentId` server-seitig aus `parent_id` (Button-Kontext) + neuem `nextTagSortKey`, bei Bestand unverändert. `TagValidator::validateParentId` prüft jetzt auch Kind-Tags: ein gesetzter Parent muss existieren UND selbst Top-Level (Umgebung) sein — der Tag-Baum bleibt 2-stufig. `tagEdit.tpl.php` zeigt Kontext-Header („Neue Untergruppe in «…»") + hidden `parent_id`; `listAction.tpl.php` hat „+"-Buttons am Umgebungs- und Slot-Header (`add-tag?parent=<envId>`). Schliesst den Parent-Feld-Teil der Pendenz „Tag-Verwaltung".

- **NAV-SIB-001** — resolved 2026-05-30. Das Knoten-„+" in der Navigationsliste erzeugt jetzt ein **Geschwister** statt eines Kindes: bei einem Kind wird `parent` = `parentId` des geklickten Knotens übergeben, bei einem Tree-Root `?tag` = dessen Render-Slot-Tag. `addAction` nimmt optional `?tag` (Gruppe aus Kontext); `edit(..., ?string $lockedTag)` rendert beim Geschwister-Root ein verstecktes `tag`-Feld statt des Selektors (Gruppe ist bekannt, nicht wählbar). Kind-Geschwister blenden den Gruppen-Selektor weiterhin aus (parent gesetzt). Reparenting bleibt Sache von `moveAction` (DnD, nur innerhalb desselben Render-Slot-Tags).

- **NAV-TREE-001** — resolved 2026-05-30. Tree-Primitive (`parentId` + `sortKey`) aus `Navigation` und `Tag` in ein wiederverwendbares Fundament extrahiert: `Z77\Shared\Tree\TreeNode` (Interface = Vertrag, treiber-agnostisch), `TreeNodeTrait` (File-Convenience für `parentId`/`sortKey`), `TreeService` (stateless Algorithmen: `sort`/`children`/`siblingGroup`/`nextSortKey`/`renumber`/`reorderInto`/`descendants`/`isDescendantOf`/`rootOf`, Root-Forest partitioniert via `scopeOf`-Callback — Navigation: `getTag`, Tag: Default). `NavigationService` (Lese-Seite: `getChildren`/`getByTag`/`getTopLevelTags`/`getTagChildren`) und `NavigationController` (Schreib-Seite inkl. `moveAction`/Add) delegieren jetzt; die Duplikate `sortSiblings`/`sortTags` und `nextSortKey`/`nextTagSortKey` sowie `bySortKey`/`isDescendantOf`/`tagRootOf`/`siblingGroup` sind entfernt. Trennung Mechanik (splice/renumber im Service) vs. Policy (Tag-XOR / Guards / Tag-Erben bleibt navigation-spezifisch). Doctrine-Entities implementieren `TreeNode` mit ORM-gemappten Spalten ohne den Trait. SSOT [`tree.md`](tree.md), festgeschrieben in [`../02-decisions/adr-008-tree-foundation.md`](../02-decisions/adr-008-tree-foundation.md).

- **NAV-TREE-002** — resolved 2026-05-30. Leaf-Regel für Navigations-Tags scharf: ein Eintrag darf nur an einem **Leaf-Tag** (Render-Slot) hängen — ein Tag mit Kind-Tags (Umgebung wie `backend`) wird abgelehnt. Vorher konnte ein Tree-Root einen Umgebungs-Tag tragen und verschwand dann aus allen UIs. Neuer geteilter Kollaborator `Z77\Shared\Tree\ElementAnchorRules` (+ `AnchorViolation`-Codes) erzwingt die 3 Invarianten (Gruppen-FK XOR Element-Parent, Leaf-only, kein Orphan), pro Entity parametriert über Gruppen-Knotenset + `resolveGroup`-Callback (Navigation: Tag-Slug → Tag-Knoten). `NavigationValidator::validateTag` delegiert dorthin; Meldungen/Feldnamen bleiben domänenseitig. `TreeService::isLeaf` ergänzt. UI `edit.tpl.php`: Tag-Auswahl bietet nur noch Leaf-Tags (`slotTags`). Modell „Gruppen-Baum + Element-FK + Leaf-Regel" als framework-weite Richtung in [`tree.md`](tree.md) / ADR-008 festgehalten (nächster Element-Consumer: Artikel). Daten-Altlast (bereits angelegte Tree-Roots mit Umgebungs-/Nicht-Leaf-Tag) wird von `listAction` im Bereich «Nicht zugeordnet» angezeigt — dieser umfasst jetzt alle Tree-Roots ohne gültigen Render-Slot (Tag null ODER kein gültiger Slot-Slug), damit sie editier-/löschbar bleiben.

- **NAV-RENAME-001** — resolved 2026-06-01. `Tag` → `NavigationGroup` umbenannt (Entity + `NavigationGroupRepository` + `NavigationGroupValidator`), passend zur framework-weiten Namenskonvention `{Element}Group` + `implements TreeNode` (ADR-009). Das Verweis-Feld `Navigation::$tag` wurde umbenannt und im selben Zug von String-Slug auf int-FK umgestellt → `$navigationGroupId` (siehe NAV-FK-001). `NavigationService`-Gruppen-API umbenannt (`getByTag`→`getByGroupSlug`, `getTopLevelTags`→`getTopLevelGroups`, `getTagChildren`→`getGroupChildren`, `getActiveSectionByTag`→`getActiveSectionByGroupSlug`, `getTag`→`getNavigationGroup`); Cache-Keys `tags-all`/`tag-entity` → `groups-all`/`group-entity`; Kontext-Var `navTag` → `navGroupSlug`. Daten: `tags*.json` → `navigation_groups*.json`. Verifiziert: `php -l`, Mapping-/Naming-Smoke (alle grün), Frontend-Home HTTP 200.

- **NAV-SPLIT-001** — resolved 2026-06-01. Gruppen-CRUD aus `NavigationController` in einen dedizierten `NavigationGroupController` ausgelagert (ADR-009): `add`/`edit`/`confirmDelete`/`remove` (bereinigte Action-Namen, Entity-CSRF-Scope `navigationGroup`). Element-Controller behält `list` (gemeinsamer Screen, liest beide Entities), `add`/`edit`/`confirmDelete`/`remove`/`move`/`checkField`. Tag-Templates `tagEdit`/`confirmDeleteTag` → `NavigationGroupController/edit`+`confirmDelete`. List-Buttons zeigen auf `/backend/content/navigation-group/*`. Der mehrwortige Controller ist via Kebab-URL erreichbar (`navigation-group` → `NavigationGroupController`); `cleanAlphaNum` behält `-`, `toCamelCase` splittet darauf — kein ADR-005-Schema-Change. `Naming::toControllerUrlSegment` für Outbound auf Kebab korrigiert (single-word unverändert). Verifiziert: Route auflösbar (302 → /login statt 404). _(Der zunächst aufgeschobene generische Basis-Controller wurde mit NAV-GROUPLIST-001 dann doch extrahiert — siehe dort.)_

- **NAV-FK-001** — resolved 2026-06-01. Element→Gruppe-Referenz von String-Slug auf **int-FK** umgestellt: `Navigation::$groupSlug` (`group_slug`) → `$navigationGroupId` (`navigation_group_id`, `getNavigationGroupId`/`setNavigationGroupId`, `#[Clean('nullable','int')]`). Grund: Inkonsistenz — `parent_id` und `ref` waren int-FKs auf eine Entity-`id`, nur die Gruppe referenzierte per Slug (Überbleibsel aus „Tag = String"). `tree.md` sprach ohnehin von „FK", und `ElementAnchorRules` ist referenz-typ-agnostisch (Docblock: „a slug, an int id, …"). Konvention für künftige Tree-Entities: `{group_entity}_id` (z.B. `article_group_id`). `TreeService` scopeOf der Navigation gibt jetzt die Gruppen-id zurück; `NavigationValidator` `resolveGroup` matcht per id; Feldfehler-Key/Formfeld/`mapToArray`-Key alle `navigation_group_id`. **Storage = id, Lookups bleiben slug-freundlich**: `getByGroupSlug(slug)` löst Slug→Gruppe→id auf und delegiert an neues `getByGroupId(int)`; Frontend-/Backend-Chrome referenziert Render-Slots weiter über den stabilen Slug-Namen. Daten beider `navigation*.json` migriert (`group_slug` → `navigation_group_id`, Slug→id-Mapping). Delete-Cascade detacht jetzt per id. Verifiziert: `php -l` grün, JSON valide (0 Slug-Reste), Frontend-Home 200 mit korrekter Gruppierung (frontend-main=7, frontend-meta=2), Backend-Routen 302.

- **NAV-GROUPLIST-001** — resolved 2026-06-01. `NavigationGroupController` hat jetzt ein eigenes `listAction` = Gruppen-Verwaltungs- + **Sortier-Screen** (`/backend/content/navigation-group/list`, defaultAction `list`): 2-stufiger Baum (Umgebung → Render-Slot) mit DnD-Reorder/Reparent, `navigation-group/list.js` (eigenes Skript, DnD-Kern wie `navigation/list.js`, postet an `navigation-group/move`), CSS via `navigation/list.css` wiederverwendet. Die kombinierte Navigationsliste behält nur leichte Inline-Gruppen-Shortcuts. **Generischer Move extrahiert:** beide Controller erben jetzt von `AbstractTreeEntityController` (extends `BackendAbstractController`), das `moveAction` einmal liefert (resolve → cycle-guard → `TreeService::reorderInto` → alte Gruppe renummerieren → persist); Entity-Regeln in `applyMovePolicy(TreeNode, ?TreeNode, array): ?string` + `treeRepo()`/`treeService()`. Navigation-Policy = Cross-Group-/Ref-Parent-Guards + Gruppen-Erben; Gruppen-Policy = 2-Ebenen-Invariante (Umgebung bleibt top-level, Render-Slot nur unter Umgebung). Mechanik/Policy-Trennung wie bei `TreeService` selbst (siehe [`tree.md`](tree.md) / ADR-009). Asset deployed via `composer install`. Verifiziert: `method_exists` der geerbten `moveAction` = true, `navigation-group/list` Route 302, Navigation unverändert (302), `php -l`/JS-Parse grün. Offen: Umgebungs-Löschschutz (eine Umgebung mit Render-Slots löschen würde diese verwaisen — daher kein Env-Delete-Button im List).

- **NAV-SUBNAV-001** — resolved 2026-06-02. Backend subnav (`partials/subnav.tpl.php`) had three divergent child-render loops; only the opener branch resolved refs. A ref child under a *routable* parent (e.g. the test entry id 18 `ref→6` under id 6) fell into the regular branch which rendered raw `$child->getUrl()` — empty for a ref (no routing fields → `getCanonicalPath()` is `''`) → empty `href`. Replaced all loops with a single **recursive renderer**. New render rule (per developer decision): **any entry with children is an opener (`<details>`), regardless of whether it can produce a link of its own** — to keep an opener's own page reachable, add a ref-to-self child (the id-18 pattern). Every node resolves its href uniformly (ref → `target.getUrl().'?via='.refId`, else `getUrl()`); active state via `NavigationService::isActive()` (no hand-rolled URL compare); a leaf that resolves to no URL renders inert (`<span>`, no `href=""` → avoids page reload). Depth guard (20) against hand-edited parent cycles. By design there is NO validation that a navigation runs into nothing or that an opener has children — the admin sees that directly.

- **NAV-ALIAS-001** — resolved 2026-06-08 (ADR-015). Navigation made **purely structural**: `url` + `params` fields (and `getFriendlyUrl`/`getParams`/`setUrl`/`setParams`/`buildQueryString`) removed; `getUrl()`/`getCanonicalUrl()` now return the 4-tuple path. The public URL moved to a new **`NavigationAlias`** entity (`navigationId` FK + `path` + `isCanonical` + `active`) with its own repository, `NavigationAliasValidator` (path unique, ≤1 canonical/nav), and a dedicated `NavigationAliasController` (`/backend/content/navigation-alias/*`). Inbound routing gained alias precedence (`NavigationService::matchAlias`, longest-prefix) + **content-slug capture** (`Request::getSlugs()`); outbound goes through `NavigationService::urlFor()`. `findByPath`/`resolveCurrent` reduced to bare 4-tuple matching (`scoreParamMatch` gone). Data migrated: `url`/`params` stripped from both `navigation*.json`; `navigation_aliases.*.json` seeded. **Supersedes** NAV-URL-001, NAV-PARAMS-001, NAV-DUP-001 (uniqueness now on the alias `path`). Verified: `php -l`, frontend routes 200, `/home/alpha/beta` slug-capture 200, fr localized 301, backend alias routes 302. Follow-up (Phase 5): extract a `NavigationUrlResolver` from `NavigationService` (alias methods cohesive enough to own a class) — done, see NAV-RESOLVER-001.

- **NAV-RESOLVER-001** — resolved 2026-06-09 (Phase 5, ADR-015). The alias layer was extracted from `NavigationService` into a dedicated `NavigationUrlResolver` (the `//#test` marker the developer left in `NavigationService::urlFor`). The resolver owns the alias cache (`aliases-all`, key owner now `NavigationUrlResolver`) plus `getCanonicalAlias`/`findByAliasPath`/`urlFor`/`matchAlias`. It is **alias-only** (deps: `NavigationAliasRepository` + `CacheManager`) — it does not read the navigation tree, so it has no NavigationService dependency and no cycle. `matchAlias` now returns `{navigationId, slugs}`; `Router::matchAlias` resolves the id to a `Navigation` (the navigation cache stays in NavigationService) and returns `{navigation, slugs}`, so `Request::runParsing` is unchanged. `NavigationService` keeps `urlFor()` as a thin delegator (templates' seam + internal `resolveViewAreaUrl`); `getCanonicalAlias`/`findByAliasPath`/`matchAlias` are gone from it. `AbstractBaseController::currentCanonicalPath` reads the resolver directly. Wired in `Bootstrap` (`NavigationUrlResolver` singleton → injected into `NavigationService` + `Router`). Behavior-preserving refactor. Verified: `php -l` (5 files), frontend routes 200, `/home/alpha/beta` slug-capture 200, `/fr/accueil` 200 + alias-driven localized hrefs (`/fr/confidentialite`, `/fr/prestations`), `/fr/services` 301, `/login` 200, backend 302, garbage 404; no orphaned calls to the removed NavigationService methods.

- **NAV-PARAM-002** — added 2026-07-02. Re-introduced a single `param` string field on `Navigation` (the old `params` map was removed in NAV-ALIAS-001) — but strictly as **outbound UI-state**, NOT routing. `NavigationUrlResolver::urlFor` appends it to the generated href (`?key=front`, `?`/`&`-aware via `appendParam`); routing (`findByPath`/`resolveCurrent`) does **not** read it (unlike the old params, which did strict-subset route matching). So ADR-015's "routing is param-free; dynamic discrimination is a content slug" still holds — `param` is the menu-link analogue of the kept `?via=` UI-cursor param, a switch trigger for target-side session state. This **amends ADR-015's** param-removal in scope (UI-state allowed, routing still param-free); a formal ADR addendum is pending (see below). First consumer: the DMS Drive session scope (`?key=<root-key>` → `DriveControllerTrait::mountRoot` → session, [`documents.md`](documents.md)). Added `NavigationService::urlForVia($target, $refId)` and routed the 3 ref-href sites (service `firstNavigableInclusive`, `subnav.tpl.php`, `header.tpl.php`) through it so a param-carrying ref target no longer risks a double `?`. `Navigation::setParam` normalizes (strips leading `?`/`&` + whitespace); `#[Clean('text')]` preserves `=`/`&`. Edit form gained a `param` input. `mapToArray`/`mapFromArray` auto-carry it (missing key → `''`, forward/backward compatible). Verified: `php -l` on all 7 touched files. Live click-through + a formal `docs/02-decisions` ADR addendum for the ADR-015 scope amendment are open.

- **NAV-SLOTS-CONFIG-001** — resolved 2026-07-05 (ADR-022). View areas + render-slots moved out of the `NavigationGroup` entity into **module config** (`viewAreaLabel` + `navSlots` in `<module>Config.inc.php`, read via `ModuleManager::getViewAreaLabel`/`getNavSlots`/`getAllNavSlots`/`isKnownSlot`). `Navigation.navigationGroupId` (int FK) → **`slot`** (slug string, `#[Clean('ident')]`); `TreeService` scopeOf now `getSlot`. `NavigationService`: `getByGroupSlug`/`getByGroupId` → **`getBySlot`** (validates against the config registry, throws `UnknownNavigationSlotException` on an unknown slug — fail-fast for a template typo); `getActiveSectionByGroupSlug` → `getActiveSectionBySlot`; `getViewAreas` returns `{key,label,url,active}` from ModuleManager; the group methods + `groups-all`/`group-entity` caches are gone; ctor dep `NavigationGroupRepository` → `ModuleManager`. `NavigationValidator::validateSlot` inlines XOR + orphan + registry-membership (the shared `ElementAnchorRules`/`AnchorViolation` were **deleted** — Navigation was their only consumer). `MetaDataController` moved off `getTopLevelGroups` onto `getPublicViewAreaKeys` + `getViewAreaLabel`. **Removed:** `NavigationGroup` entity/repository/validator/`NavigationGroupController` + 5 templates + `navigation-group/list.js` + `navigation_groups*.json` + the backend ACL block. Data migrated (`navigation_group_id` → `slot`) + sandbox cleanup (orphan id 14/15/16 → non-existent parent 13; id 17 → deleted controller). Verified: `php -l` all green, JSON valid, dev-server `/`/`/home`/`/login` 200 + backend 302, CLI boot `getViewAreas`/`getBySlot`/`iterateSections`/fail-fast all correct. Build plan: [`../03-development/navigation-slots-config-bauplan.md`](../03-development/navigation-slots-config-bauplan.md). **Restpunkt:** the `AbstractTreeEntityController` base is kept (one consumer, `NavigationController`) as the reuse seam for future tree-entity controllers (ADR-008/009).

- **NAV-LIST-VM-001** — resolved 2026-07-07. `listAction.tpl.php` baute den Baum selbst rekursiv auf: ein `$renderNode`-Closure rief `navigationService->getChildren()` + `findById()` (Service-Zugriff + Ref-Auflösung im Template) und komponierte die URL-/Route-Zelle inline — Logik im Partial (Konventionsverstoss). Dieselbe URL-/Route-/Ref-Darstellung existierte ein zweites Mal im Controller (`edit`-Fetch-Update, eigener `htmlspecialchars`-Closure) → Duplikat mit Drift-Risiko. Fix: ein einziger Node-Display-Builder im Controller — `nodeDisplay(Navigation): array` (name/urlDisplay/route/isRef/active; `urlDisplay` intern escaped) + `nodeTree(Navigation, all): array` (rekursiv, `children`/`hasChildren` über `TreeService::children`). `listAction` liefert fertige verschachtelte Arrays; das Template ist reiner Renderer (kein Service-Call, keine Escaping-Entscheidung: `urlDisplay` = `raw()` (vor-escaped), Rest `e()`). Der `edit`-Fetch nutzt denselben `nodeDisplay` → eine Quelle für die Node-Darstellung. Verhaltensgleich: `TreeService::children` (parentId≠null → scope-irrelevant, sortiert) entspricht `getChildren`; Ref-Auflösung via `repo()->find()` wie im alten edit-Pfad. Verifiziert: `php -l` (Controller + Template) grün.

## pending

- **ADR-015 addendum for `Navigation::param` (NAV-PARAM-002)** — write a short `docs/02-decisions` ADR recording that navigation MAY carry an OUTBOUND UI-state query param (switch trigger, like `?via=`), while routing stays param-free (the ADR-015 core). Until then the decision lives in NAV-PARAM-002 above.

- **Umgebungs-Switcher — Rollen-Gate (offen)** — `getViewAreas()` filtert heute nur auf Erreichbarkeit (mind. ein navigierbarer Eintrag), nicht auf Rolle; der Backend-Topbar ist ohnehin auth-gated, daher aufgeschoben. (Der frühere Env-Delete-Schutz ist mit ADR-022 gegenstandslos — Umgebungen sind Config, es gibt keinen Delete-Pfad mehr.)

- **Subnav — Folgepunkte aus NAV-SUBNAV-001** — drei bewusst offen gelassene Punkte aus der Subnav-Refaktorierung (2026-06-02): (1) **inert-Styling** — der Modifier `backend-tree-node--inert` (Leaf ohne erreichbare URL) ist gesetzt, aber im SCSS noch ungestylt; rendert wie ein normaler Knoten. (2) **inaktive Einträge** — die Subnav nutzt `getChildren()` (ungefiltert), inaktive Einträge (`active: false`) werden weiterhin gerendert — Abweichung von der `active`-Regel (kein öffentliches active-gefiltertes `getChildren`; `getActiveChildren` ist privat im NavigationService). (3) **Opener-Summary klickbar** — ein Opener-Knoten mit eigenem Link ist aktuell nur Toggle, nicht klickbar (Erreichbarkeit via Ref-auf-sich-selbst-Kind, id-18-Muster); offen, ob Summary zusätzlich Link sein soll.