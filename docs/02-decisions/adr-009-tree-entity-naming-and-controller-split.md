# ADR-009 — Tree-Entity Naming Convention & Per-Entity Management Controllers

**Status:** `APPROVED`
**Date:** 2026-06-01

---

## Context

ADR-008 extracted the tree primitive (`TreeNode` / `TreeNodeTrait` / `TreeService`
+ `ElementAnchorRules`) so any "group tree + element" pair reuses the mechanics
once. The next tree entity (article groups → articles, …) should "simply extend"
that foundation. Before adding a second element consumer we hardened the prototype
(Navigation element + its group tree) on three points the extension exposed:

1. **Naming.** `Tag` was a misleading name — it is not a flat label but a 2-level
   structural tree (environment → render-slot). Nothing in the names made it
   visible *that* it is a tree, nor *which* element it pairs with.
2. **Controller responsibility.** A single `NavigationController` carried 12
   actions; 5 were group (tag) CRUD. Two entities, two lifecycles, one class.
3. **Routing for multi-word controllers.** A `NavigationGroupController` needs a
   URL — but the convention had only ever seen single-word controllers.

---

## Decision

### 1. Naming convention

- **Element** = the bare domain noun: `Navigation`, later `Article`, `Contact`.
- **Group** = element stem + `Group`: `NavigationGroup`, later `ArticleGroup`.
  The shared stem signals the pairing.
- **Tree-ness is declared, not named.** Any entity that is itself a tree
  `implements TreeNode` (+ `use TreeNodeTrait` when File-backed). The interface at
  the class declaration is the tree marker — names never repeat it. The group is
  always a tree; an element is a tree only when its domain needs it (Navigation
  yes, Article/Contact no). Putting `Tree` in a name was rejected: it conflates
  "group" with "tree" and would churn when an element gains/loses its own tree.

### 2. Element → group link field (int FK)

The element references its group by an **int FK** to the group entity's `id` —
`Navigation::$navigationGroupId` (`getNavigationGroupId()` /
`setNavigationGroupId()`), serialized as `navigation_group_id`. This is consistent
with the other entity references (`parent_id`, `ref` are int FKs to an entity `id`)
and with ORM-readiness (a real FK to `navigation_groups.id`, not a denormalized
natural-key string). The convention for future tree entities is `{group_entity}_id`
(e.g. `article_group_id`).

`group` was unavailable as a field name (taken by the URL routing group), hence the
explicit `navigation_group_id`. The shared `ElementAnchorRules` is reference-TYPE
agnostic (its docblock: "a slug, an int id, …"), so this is a consumer-side choice;
only Navigation's `resolveGroup` callback changed (slug-match → id-match).

**Storage is the id; lookups stay slug-friendly.** UI chrome (frontend
header/footer, backend topbar/sidebar) references render-slots by their stable slug
name, so `NavigationService::getByGroupSlug(slug)` resolves slug → group → id and
delegates to `getByGroupId(int)`. Storage is relationally correct; templates stay
readable.

(Initially mis-modelled as a string slug `group_slug`, a holdover from when the
group was a flat `Tag` string; corrected the same day — see navigation.md
NAV-FK-001.)

### 3. Per-entity management controllers

Each entity gets its own controller owning its mutations:

- `NavigationController` — element: `list` (shared), `add` / `edit` /
  `confirmDelete` / `remove` / `move` / `checkField`.
- `NavigationGroupController` — group: `add` / `edit` / `confirmDelete` / `remove`
  (+ `move` once group DnD lands).

The **shared list view stays in the element controller** — it is the "Navigation"
screen and reads both entities. Reading may couple; **mutations are separated**.
The list's group-column buttons target `navigation-group/*` endpoints.

### 4. Multi-word controller URLs (no ADR-005 change)

`NavigationGroupController` is reachable at `/backend/content/navigation-group/…`.
This already works **inbound**: `StringCleaner::cleanAlphaNum` keeps `-`, and
`Naming::toCamelCase('navigation-group')` → `NavigationGroup`. Only the **outbound**
`Naming::toControllerUrlSegment` was lossy (lower-cased without re-inserting
separators); it now emits kebab-case. Single-word controllers are unaffected (no
interior uppercase to split). No change to the URL schema or ADR-005.

### 5. Prototype applied

`Tag` → `NavigationGroup` (entity, `NavigationGroupRepository`,
`NavigationGroupValidator`); data files `tags*.json` → `navigation_groups*.json`;
entity-CSRF scope `tag` → `navigationGroup`; `NavigationService` group API renamed
(`getByTag`→`getByGroupSlug`, `getTopLevelTags`→`getTopLevelGroups`,
`getTagChildren`→`getGroupChildren`, `getActiveSectionByTag`→
`getActiveSectionByGroupSlug`, `getTag`→`getNavigationGroup`).

---

## Consequences

- A clean, mechanical convention that scales: each future tree entity adds
  `{X}` + `{X}Group` + `{X}Controller` + `{X}GroupController`.
- The rename touched ~14 source files + both navigation data files (migrated
  `tag` → `navigation_group_id`, slug→id) + the group data files (renamed). Verified end-to-end:
  frontend renders, the new kebab controller route resolves (302 to login, not
  404), entity mapping + `BodyCleaner` round-trip green.
- **`AbstractTreeEntityController` extracted (2026-06-01).** Adding the
  group-sort list gave `NavigationGroupController` its own `moveAction` — a second
  real consumer of the move mechanic next to `NavigationController`. At that point
  the generic skeleton (resolve → cycle-guard → `TreeService::reorderInto` →
  renumber old group → persist) was extracted into
  `AbstractTreeEntityController extends BackendAbstractController`, with entity
  rules behind a single `applyMovePolicy(TreeNode, ?TreeNode, array): ?string`
  hook (+ `treeRepo()` / `treeService()`). Navigation policy = cross-group +
  ref-parent guards + group inheritance; group policy = the 2-level depth
  invariant. The mechanics/policy split mirrors `TreeService` itself, so the base
  stays free of entity specifics (no leaky abstraction). _(This supersedes the
  earlier same-day stance of deferring the extraction until a third consumer.)_
- Refines the terminology of ADR-007 / ADR-008 (their `Tag` is today's
  `NavigationGroup`); those ADRs stand as the historical record.

## See also

- [`../topics/tree.md`](../topics/tree.md) — the foundation + the naming convention in the rules
- [`../topics/navigation.md`](../topics/navigation.md) — the prototype consumer (element + group)
- [`../topics/backend.md`](../topics/backend.md) — the two controllers in the backend controller table
- [`adr-008-tree-foundation.md`](adr-008-tree-foundation.md) — the tree primitive this builds on
