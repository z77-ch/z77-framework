# ADR-008 — Tree Foundation

**Status:** `APPROVED`
**Date:** 2026-05-30

---

## Context

Two entities now form self-referential trees with the same primitive
(`parentId` + `sortKey`): `Navigation` (n-level) and `Tag` (2-level, the
environment → render-slot tree from ADR-007). The tree logic was written twice —
once typed to `Navigation`, once to `Tag` — and split across layers:

- read side in `NavigationService` (`sortSiblings` **and** a literal duplicate
  `sortTags`; `getChildren` ≙ `getTagChildren`; `getByTag` / `getTopLevelTags`);
- write side in `NavigationController` (`siblingGroup`, `nextSortKey` **and** the
  duplicate `nextTagSortKey`, `bySortKey`, `isDescendantOf`, `tagRootOf`, and the
  splice/renumber mechanics inside `moveAction`).

The framework will need this pattern repeatedly: product groups → products,
contact groups → contacts, account groups → accounts. Each is "a group tree with
ordered siblings". Writing the hierarchy management once — and reusing it for any
entity that has such a hierarchy — is the goal. Tag and Navigation are the
prototype.

ADR-007 deliberately **deferred** this extraction ("worth extracting only once a
third consumer appears; duplicating two small fields onto a second entity is
cheaper than a premature abstraction over two"). This ADR revisits that call.

---

## Decision

Extract the tree primitive into a small, driver-agnostic foundation under
`Z77\Shared\Tree\`, and move both prototype consumers onto it.

### 1. `TreeNode` interface = the contract

`getId(): ?int`, `getParentId(): ?int`, `setParentId(?int)`,
`getSortKey(): int`, `setSortKey(int)`. Any entity that implements it can reuse
the generic operations. The interface — not the trait — is the abstraction.

### 2. `TreeNodeTrait` = File-entity convenience

Standard `parentId` / `sortKey` storage + accessors with the server-side
null-normalization both entities already used. `Tag` and `Navigation` `use` it.
A Doctrine/ORM entity does NOT use the trait — it implements `TreeNode` directly
with ORM-mapped columns (scalar self-FK, or an adapter over a
`#[ORM\ManyToOne] $parent`), so the shared trait never pulls in `doctrine/orm`.

### 3. `TreeService` = the algorithms, stateless and persistence-free

`sort`, `index`, `children`, `siblingGroup(Of)`, `nextSortKey`, `renumber`,
`reorderInto`, `descendants`, `isDescendantOf`, `rootOf`. Every method takes the
node collection it works on; the service never reads a repository or persists —
it mutates `parentId` / `sortKey` and returns the changed nodes for the caller to
persist via the entity manager. This is what keeps it driver-agnostic.

### 4. Scope partitions the root forest

Siblings = same `parentId` AND same scope, where scope is derived by a `scopeOf`
callback passed to `TreeService`. Navigation uses
`fn($n) => $n->getTag()` (roots grouped by render-slot tag); Tag uses the default
(one null-scope group of environments). One mechanism replaces `siblingGroup` +
`getByTag`/`getTopLevelTags` + `nextSortKey`/`nextTagSortKey`.

### 5. Mechanics vs. policy

`TreeService` performs only the mechanical move (compute group, splice at index,
renumber densely). Entity policy — cycle / ref-parent / cross-scope guards, tag
nulling on reparent, tag inheritance on move-to-top — stays in the controller and
is applied to the node BEFORE `reorderInto`.

### 6. Element membership = `ElementAnchorRules`

An element entity that hangs into a group tree references one group node by FK.
A shared, composition-based collaborator (`ElementAnchorRules`, parameterized per
entity with the group node set + a `resolveGroup` callback) enforces three
invariants in the element's validator: group-FK **XOR** element-parent; the group
FK points to a **leaf** group (`TreeService::isLeaf`); **no orphan**. The element
may itself be a tree (Navigation) or flat (Article, Contact). It returns
`AnchorViolation` reason codes only — messages, field names, and the
"is uncategorized allowed?" policy stay in the domain validator. Composition (not
an abstract base) keeps each validator's single inheritance slot free for other
shared concerns (e.g. localization).

---

## Reasoning

**Why now, against ADR-007's deferral?**
Three facts changed the trade-off: (a) the duplication is already real and was
literal (`sortTags` = `sortSiblings`, `nextTagSortKey` = `nextSortKey`), not
hypothetical; (b) the framework's explicit direction is broad reuse across many
future group/item domains, so the third+ consumers are a near-certainty, not a
maybe; (c) the foundation lands with **two real consumers immediately**, so this
is not a build-on-spec abstraction — it is extracting shared code that already
exists twice. ADR-007's reasoning was correct for its moment; the moment has
passed.

**Why interface-as-contract and a separate convenience trait?**
The Doctrine requirement forces the split: a trait carrying `#[ORM\Column]` would
couple the shared trait to `doctrine/orm` (not installed; would break File-only
setups). Keeping the trait Doctrine-free and making the interface the contract
lets a Doctrine entity map its own columns while reusing every algorithm.

**Why a stateless service over a tree-aware repository?**
A stateless service leaves the consumer's caching (`NavigationService`) and the
existing read/write split (read via repository, write via entity manager)
untouched, and it is trivially driver-agnostic. A tree-aware base repository
would bind the logic to the persistence layer and complicate the File→Doctrine
seam. Deferred until a dataset actually needs SQL-side tree queries.

**Why a `scopeOf` callback rather than a fixed field?**
Navigation partitions its root forest by `tag`; Tag does not partition at all.
A callback expresses both with one mechanism and lets future entities choose
their own partition (e.g. by mandant/client) without touching `TreeService`.

---

## Consequences

**Easier:**

- A new tree entity = implement `TreeNode` (`use TreeNodeTrait` for File) + pick a
  `scopeOf`. No re-implementation of sort / move / cycle logic.
- One place to fix a tree bug or change ordering semantics for all consumers.
- Doctrine-ready: the same algorithms serve an ORM entity that maps `parentId` /
  `sortKey` itself.

**Harder / to keep in mind:**

- `reorderInto` reads the node's CURRENT sibling group — the caller MUST set the
  target `parentId` / scope first (policy before mechanics).
- `TreeService` is O(n) per level over the loaded set (`ARCH-T001`); large Doctrine
  trees should use SQL instead of loading all rows.
- The contract is the interface — a Doctrine entity that forgets to map
  `parentId` / `sortKey` will satisfy PHP but not persist the tree.

---

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Keep duplicating per entity (ADR-007 status quo) | Duplication is already literal across two entities and the framework needs the pattern broadly; the deferral condition is effectively met. |
| ORM attributes in `TreeNodeTrait` so Doctrine entities can reuse it | Couples the shared trait to `doctrine/orm` (not installed); breaks File-only setups. Interface-as-contract avoids this. |
| Tree-aware base repository instead of a service | Binds tree logic to the persistence layer, complicates the File→Doctrine seam, and would fight `NavigationService`'s own caching. Deferred until SQL-side tree queries are needed. |
| Model the group→item case (two entities) now | Out of scope by decision — current need is self-referential single-entity trees. The foundation does not preclude it later. |
| Fixed partition field instead of `scopeOf` callback | Navigation partitions by `tag`, Tag not at all; a callback covers both and future partitions without changing `TreeService`. |

---

## Implementation Summary

| Area | Files |
|---|---|
| Foundation | `packages/kernel/shared/src/Tree/TreeNode.php`, `TreeNodeTrait.php`, `TreeService.php` (+ `isLeaf`) |
| Membership | `packages/kernel/shared/src/Tree/ElementAnchorRules.php`, `AnchorViolation.php` |
| Entities → `TreeNode` | `packages/kernel/shared/src/Entities/Tag.php`, `Navigation.php` (own `parentId`/`sortKey` removed, trait used) |
| Read side | `packages/kernel/core/src/Services/NavigationService.php` (`getChildren`, `getByTag`, `getTopLevelTags`, `getTagChildren` delegate; `sortSiblings`/`sortTags` removed) |
| Write side | `packages/module-backend/.../Content/NavigationController.php` (`moveAction`, add/sortKey delegate; `siblingGroup`/`nextSortKey`/`bySortKey`/`isDescendantOf`/`tagRootOf`/`nextTagSortKey` removed) |
| Element validation | `packages/kernel/shared/src/Validators/NavigationValidator.php` (`validateTag` delegates to `ElementAnchorRules`: XOR + orphan + leaf-only); UI `edit.tpl.php` offers leaf tags only |
| Docs | `docs/topics/tree.md` (SSOT) |

Operational SSOT: `docs/topics/tree.md`. Supersedes the deferral recorded in
[`adr-007-navigation-tree-model.md`](adr-007-navigation-tree-model.md) (§Reasoning,
Rejected Alternatives "Extract a shared Orderable/Tree trait now").
