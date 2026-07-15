# ADR-007 — Navigation Tree Model

**Status:** `APPROVED`
**Date:** 2026-05-29

---

## Context

The navigation subsystem grew in several steps (single-tag model, opener
convention, refs, `sortKey`/`parentId` migration) that were each captured in
working docs under `docs/03-development/` but never consolidated into one
binding decision. A late requirement then added a layer on top: a dynamic
**environment switcher** in the backend topbar (the former static `Umgebung`
badge) that lets a user move between UI environments — today `frontend` /
`backend`, later `+ member`.

The open question was where the environment concept belongs. A separate
`Environment`/`ViewArea` registry (a code-side data table with label, entry
URL, role) was considered and rejected: every field it would carry already
exists, or is derivable, in the navigation/tag structure. The cleaner model is
to let **tags form a tree** and treat the top-level tags as environments.

This ADR records the navigation tree model as a whole, including the layers
that predate the environment work, so there is one source of truth for the
rationale. The operational detail (APIs, fields, rules) lives in
[`../topics/navigation.md`](../topics/navigation.md) (SSOT).

---

## Decision

### 1. Three structural layers

```
Environment (top-level tag, parentId null)      ← bound to a module + layout
   └─ Render-slot (child tag)                   ← header / footer / topbar / auth …
        └─ Navigation tree (tree-root → children)
```

- **Tag tree groups, navigation tree navigates.** A `Tag` carries `parentId`
  + `sortKey` (the same tree primitives Navigation uses). A top-level tag is an
  environment; its child tags are render-slots. Navigation tree-roots carry a
  render-slot tag (level 1), never an environment tag (level 0).
- A navigation entry satisfies **`tag` XOR `parentId`**: a tree-root carries a
  render-slot tag and `parentId: null`; an inner/leaf node has `tag: null` and a
  `parentId`. Ref entries are exempt (validated separately).

### 2. Environment = module with a layout (allowlist)

A view area is a module that owns a layout and declares `'viewArea' => true`
in its `<module>Config.inc.php`. `ModuleManager::getViewAreaKeys()` returns
those keys. `TagValidator` enforces: a **top-level tag's name must be a view-area
module key** — a content editor cannot create a dead environment. Child tags
(render-slots) are free. The invariant **environment tag name === module key**
makes the current environment derivable from the current entry's module.

### 3. Environment entry URL and visibility are derived, not stored

- **Entry URL:** `NavigationService::resolveViewAreaUrl()` finds the first
  navigable entry by scanning the environment's render-slot tags in order, then
  the tree-roots within each (refs resolve to `target + ?via=`). No stored URL.
- **Visibility:** `getViewAreas()` returns only environments with at least one
  reachable entry — an environment with no page is a dead switch and is skipped.
  Role-based gating is deferred (the sole consumer, the backend topbar, is
  already auth-gated).

### 4. Opener convention (predates this ADR, formalized here)

A sidebar entry with all four routing fields empty and at least one child is an
**opener**: rendered as `<details>/<summary>`, it toggles its subtree instead of
navigating. Topbar tabs find a target via `resolveFirstNavigable()` and render
inert (`<span>`) when none exists.

### 5. Refs and the `$current` / `$uiCurrent` split (predates this ADR)

A ref entry (`ref: int`) is a UI-only pointer to another entry; it never matches
routes. Its href is `target.getUrl() . '?via=<refId>'`. The `?via=` param sets
`$uiCurrent` (UI cursor) independently of `$current` (routing target), so the
sidebar/section of the ref highlights instead of the target's. Controllers see
routing state; templates see UI state via `getUiCurrent()` / `isActive()`.

### 6. Ordering and tree links are server-controlled

Sibling order is driven by an explicit `sortKey` (id tie-break), never by
file/record position — stable across a future ORM migration. The tree link is
the child's own `parentId` (single FK; the old double-parent case is
structurally impossible). Both fields carry no `#[Clean]` and are forced
server-side so a crafted body cannot reparent or reorder.

---

## Reasoning

**Why a tag tree instead of an environment registry?**
The four fields a registry would hold all resolve into existing mechanics:
`label` is on the `Tag` entity; `entryUrl` is `resolveFirstNavigable` over the
subtree; visibility is "has a reachable entry"; the indicator colour is CSS per
id. A registry would duplicate data that the tag/navigation structure already
expresses, and would let environments drift out of sync with the modules that
actually back them.

**Why bind environments to modules (allowlist B) and not free-form tags?**
An environment is architecture (a module with a layout), not content. Letting a
content editor create an arbitrary top-level tag would allow a switch to a
non-existent environment. Binding the top-level tag name to a `viewArea` module
key keeps the wahrheit in code while the structure stays in data. The reachability
filter is a second safety net (a misconfigured environment simply does not render).

**Why is the tree primitive (`parentId`/`sortKey`) duplicated onto `Tag`
instead of extracted into a shared trait now?**
Navigation was the first tree entity; `Tag` is the second. The shared
ordering/tree foundation is worth extracting only once a third consumer appears
(see `navigation.md` `## pending`). Duplicating two small fields onto a second
entity is cheaper than a premature abstraction over two.

**Why derive the current environment from the entry's module rather than walking
the tag tree?**
The invariant *environment tag name === module key* holds by construction
(allowlist B). The current routing entry always has a real module, so a single
`getModule()` read is unambiguous and avoids a tree walk on every render.

---

## Consequences

**Easier:**

- Adding an environment = adding a `viewArea` module + a top-level tag + at least
  one routable entry. No new code path.
- The backend navigation list mirrors the tag tree (environment → render-slot →
  tree) with no extra data model.
- The switcher, the topbar tabs, and the sidebar all read the same
  navigation/tag structure — one mental model.

**Harder / to keep in mind:**

- A top-level tag is no longer free: its name must match a `viewArea` module.
  Creating a render-slot tag needs a parent — the tag-edit UI still lacks a
  parent field (tracked follow-up), so today only environment tags are
  creatable through the UI.
- Deleting an environment tag would orphan its render-slots; the list view
  offers rename only for environments. A dedicated env-delete guard is a
  follow-up.
- The entity gained fields (`Tag.parentId`, `Tag.sortKey`); cached serialized
  objects must be invalidated on deploy (APCu clear) — the entity is marked
  `invalidatesCache`.

---

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Separate `Environment` / `ViewArea` registry (code data table) | Every field (label, entryUrl, role, colour) already exists or is derivable in the tag/navigation structure; a registry duplicates data and lets environments drift from their backing modules. |
| Environment as a further level inside the **navigation** tree | The tag is already the grouping abstraction over tree-roots; the environment is a grouping over tags. The hierarchy belongs in the tag tree, not the navigation tree. |
| Free-form top-level tags (no module binding) | Allows a switch to a non-existent environment (dead UI). |
| `isEnvironment: bool` flag on the tag | Redundant with "parentId === null"; more migration, no gain. |
| Static `role` field per environment for visibility | Reachability ("has a navigable entry") plus the auth-gated topbar already covers today's need; a stored role is premature. |
| Extract a shared `Orderable`/`Tree` trait now | Only the second consumer; abstraction deferred until the third (see pending). |

---

## Implementation Summary

| Area | Files |
|---|---|
| Tag entity → tree | `packages/kernel/shared/src/Entities/Tag.php` (`parentId` + `sortKey`) |
| Allowlist | `module-backend/.../backendConfig.inc.php`, `module-frontend/.../frontendConfig.inc.php` (`viewArea`), `core/.../ModuleManager.php` (`getViewAreaKeys`) |
| Validation | `shared/.../Validators/TagValidator.php` (`validateParentId`) |
| Environment API | `core/.../Services/NavigationService.php` (`getViewAreas`, `getTopLevelTags`, `getTagChildren`, `getCurrentViewAreaName`, `resolveViewAreaUrl`, `firstNavigableInclusive`) |
| Switcher UI | `module-backend/.../partials/header.tpl.php` (dropdown), `partials/footer.tpl.php` (toggle JS), `res/scss/components/_topbar.scss` |
| List view | `module-backend/.../Content/NavigationController.php` (`listAction` `$areas`), `NavigationController/listAction.tpl.php`, `res/scss/components/_list.scss` (`.be-list`/`.be-tree`, compiled into `base.css`) |
| Data | `core/data/framework/routing/{navigation,tags}.default.json`, `skeleton/data/framework/routing/{navigation,tags}.json` |

Build-up working docs: `docs/03-development/navigation-entscheidungs.md`,
`navigation-opener-entscheidungs.md`, `navigation-umgebung-bauplan.md`,
`review-navigation.md`. Operational SSOT: `docs/topics/navigation.md`.
