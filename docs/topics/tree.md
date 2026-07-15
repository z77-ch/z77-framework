# tree

2026-05-30

## entry

1. `packages/kernel/shared/src/Tree/TreeService.php` — all generic tree algorithms live here; start to understand what a consumer delegates
2. `packages/kernel/shared/src/Tree/TreeNode.php` — the contract any tree entity implements (the real, driver-agnostic abstraction)
3. `packages/kernel/core/src/Services/NavigationService.php` — reference consumer (read side); the write side is `NavigationController`

## file map

SOURCE=/packages/kernel/shared/src/Tree/TreeNode.php
SOURCE=/packages/kernel/shared/src/Tree/TreeNodeTrait.php
SOURCE=/packages/kernel/shared/src/Tree/TreeService.php
SOURCE=/packages/kernel/shared/src/Entities/Navigation.php
SOURCE=/packages/kernel/core/src/Services/NavigationService.php
SOURCE=/packages/kernel/shared/src/Validators/NavigationValidator.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Content/NavigationController.php

## mental model

A reusable foundation for **self-referential trees** (parent → children →
grandchildren) with ordered siblings. An entity becomes a tree node by
implementing `TreeNode` (id + `parentId` + `sortKey`); `TreeService` then provides
every tree operation once — sorting, children/roots resolution, sibling grouping,
next-free-sortKey, depth-first traversal, cycle detection, and the mechanical part
of a move (splice into the target group, renumber densely). Navigation is the
prototype consumer; future self-referential tree entities (product groups, contact
groups, account groups, …) plug in the same way.

- **Naming convention.** An entity that is itself a tree declares it via
  `implements TreeNode` (+ `use TreeNodeTrait` when File-backed) — the interface,
  not the name, signals tree-ness. When an element type needs an *editable* group
  tree, that group entity is named `{Element}Group` (e.g. `Article` → `ArticleGroup`)
  with its own management controller (ADR-009). Navigation is NOT such a case any
  more: its render-slots became module config (ADR-022), so there is no
  `NavigationGroup` entity — a future editable-group element would be the first real
  `{Element}Group`.

- **Contract = the `TreeNode` interface**, not the trait. `TreeService` never
  touches persistence — it mutates `parentId`/`sortKey` on objects and hands the
  changed nodes back to the caller to persist. That keeps it driver-agnostic: the
  same algorithms serve the File driver and a future Doctrine/ORM entity.
- `TreeNodeTrait` is a convenience for plain/File-backed entities (standard
  `parentId`/`sortKey` storage + accessors with server-side null-normalization).
- **Scope** partitions the top-level forest into independent root groups.
  Siblings = same `parentId` AND same scope. The scope of a node is derived by the
  `scopeOf` callback passed to `TreeService`. Inner nodes are partitioned by
  `parentId` alone (scope is irrelevant there).
  - Navigation: `new TreeService(fn(Navigation $n) => $n->getSlot())` — roots grouped
    by render-slot slug (config, ADR-022; child nodes carry `slot: ''`).
- **Mechanics vs. policy.** `TreeService` does only the mechanical move (group +
  splice + renumber). Entity-specific policy — cycle/ref/cross-scope guards, what
  scope/tag a node inherits on reparent or move-to-top — stays with the caller and
  is applied to the node BEFORE `reorderInto`.
- Stateless: every method takes the node collection it operates on, so a
  consumer's own caching (e.g. `NavigationService`) and persistence stay untouched.
- **The reusable concern is the tree itself** — "sets and subsets plus members" (a
  taxonomy / containment hierarchy): a group tree OR an element's own hierarchy, via
  `TreeService`. Navigation is an element that is itself a tree (own parent/children)
  whose tree-roots additionally attach to a render-slot.
- **Element ∈ group/slot.** An element that attaches to a group carries the group
  reference on its (sub)tree root, XOR its element-parent on descendants — never both,
  never neither. For Navigation this is `slot` (a config slug) XOR `parentId`, checked
  inline in `NavigationValidator::validateSlot` (registry-membership replaced the old
  leaf-group rule). The former shared `ElementAnchorRules` / `AnchorViolation` helper
  was **removed with `NavigationGroup`** (ADR-022) — Navigation was its only consumer.
  If a second editable-group element appears, extract a shared anchor helper THEN, not
  on spec.

## api

`TreeService` (construct with optional `scopeOf` callback):

```php
sort(array $nodes): array                                   // sortKey, id tie-break
index(array $nodes): array                                  // id => node lookup
children(array $nodes, ?int $parentId, mixed $scope = null) // sorted; null parentId = roots in scope
siblingGroup(array $nodes, ?int $parentId, mixed $scope, ?int $excludeId = null)
siblingGroupOf(array $nodes, TreeNode $node, ?int $excludeId = null)
nextSortKey(array $nodes, TreeNode $node): int              // max+1 in node's sibling group
renumber(array $group): array                               // assign 0..n in sorted order; returns group
reorderInto(array $nodes, TreeNode $node, int $newIndex)    // splice node into its (already set) group; returns changed nodes
descendants(array $nodes, TreeNode $root): \Generator       // depth-first {node, depth}
isDescendantOf(array $index, TreeNode $candidate, TreeNode $root): bool   // cycle guard
rootOf(array $index, TreeNode $node): TreeNode              // walk up to the tree-root
isLeaf(array $nodes, TreeNode $node): bool                  // no node has this as parent
```

`TreeNode` interface: `getId(): ?int`, `getParentId(): ?int`,
`setParentId(?int)`, `getSortKey(): int`, `setSortKey(int)`.

## rules

- When an entity needs a parent/children hierarchy with ordered siblings → MUST implement `TreeNode`; a File-backed entity MUST `use TreeNodeTrait`; MUST NOT re-implement sorting / move / cycle logic per entity.
- When a Doctrine/ORM entity becomes a tree node → MUST implement `TreeNode` directly with ORM-mapped `parentId` / `sortKey` columns (scalar self-FK, or an adapter over a `#[ORM\ManyToOne] $parent`); MUST NOT `use TreeNodeTrait` (it would couple the shared trait to `doctrine/orm`).
- When top-level roots form independent forests (e.g. Navigation grouped by render-slot slug) → MUST construct `TreeService` with a `scopeOf` callback; an entity whose roots form a single forest MUST use the default `new TreeService()`.
- When the `scopeOf` callback reads a field → it MUST tolerate the entire node set (inner nodes legitimately carry a null scope, e.g. Navigation children have `tag: null`).
- When moving a node → MUST apply entity policy (parent/scope assignment, cycle / ref / cross-scope guards) BEFORE calling `reorderInto`; MUST NOT push policy into `TreeService`.
- When a BACKEND controller manages a tree forest (move/reorder via DnD) → MUST extend `Z77\Module\Backend\Ui\Controllers\AbstractTreeEntityController`, which provides the generic `moveAction` once (resolve → cycle-guard → `reorderInto` → renumber old group → persist) and delegates entity rules to `applyMovePolicy(TreeNode, ?TreeNode, array): ?string` + `treeRepo()` / `treeService()`. `NavigationController` is the consumer (`NavigationGroupController` was removed with `NavigationGroup`, ADR-022) — see [`backend.md`](backend.md) / ADR-009.
- When the node changed its sibling group on a move → MUST renumber the OLD group too (`siblingGroup` + `renumber`), because only the caller knows the old scope after mutating the node.
- When persisting after a tree mutation → MUST persist every node returned by `reorderInto` / `renumber` (they carry changed `sortKey`s), then `flush`.
- `parentId` and `sortKey` MUST stay server-controlled (no `#[Clean]`) and MUST be forced server-side in the POST path so a crafted body cannot reparent or reorder.
- When resolving children or roots for UI → MUST use `TreeService::children` (sorted by `sortKey`), never raw array / file order.
- When an element attaches to a group/slot → its validator MUST enforce group-ref XOR element-parent (+ no orphan) itself; there is no shared anchor helper any more (`ElementAnchorRules` was removed with `NavigationGroup`, ADR-022). Navigation checks `slot` XOR `parentId` + registry-membership inline in `NavigationValidator::validateSlot`. A shared helper MUST NOT be extracted on spec — do it only when a second editable-group element appears.

## known issues

- **ARCH-T001** — don't assume `TreeService` is performant on large sets. `children` / `descendants` filter the full node array O(n) per level (same abstraction leak as persistence `ARCH-A001`). Fine for small File-backed trees; a large Doctrine tree should use SQL (`ORDER BY sort_key`, recursive CTE) instead of loading all rows.
- **ARCH-T002** — don't expect `TreeService` to persist anything. It mutates objects in memory only; the caller persists the returned nodes via the entity manager.
- **ARCH-T003** — don't call `reorderInto` before the node carries its target `parentId` and scope. It reads the node's CURRENT group; setting the target first is the caller's policy step.

## pending

- A tree-aware base repository (or Doctrine recursive-query helper) is intentionally NOT built yet — `TreeService` over `findAll()` covers both current consumers. Extract one only when a driver/dataset needs SQL-side tree queries (see `ARCH-T001`).

## see also

- [`navigation.md`](navigation.md) — reference consumer; full navigation model (slot-XOR-parent, config render-slots, refs, active, view areas) that sits on top of this foundation
- [`persistence-architecture.md`](persistence-architecture.md) — why `TreeService` is persistence-free: reads via `RepositoryInterface`, writes via the entity manager; same contract across File / Doctrine drivers
- [`../02-decisions/adr-008-tree-foundation.md`](../02-decisions/adr-008-tree-foundation.md) — binding decision: extract the tree primitive into interface + trait + service, supersedes ADR-007's "defer until third consumer"
- [`../02-decisions/adr-009-tree-entity-naming-and-controller-split.md`](../02-decisions/adr-009-tree-entity-naming-and-controller-split.md) — naming convention (`{Element}Group` + `TreeNode` interface), per-entity management controllers, multi-word kebab controller URLs
