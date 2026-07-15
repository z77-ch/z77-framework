# ADR-010 — File-per-record storage strategy (document mode)

**Status:** `[APPROVED]`
**Date:** 2026-06-03

---

## Context

The file persistence driver stored every entity as **one JSON file holding the
whole collection** (`navigation.json`, `loginUsers.json`): `FileRepository::findAll()`
loads the entire file and hydrates every row, and `findBy()` filters that in memory.

This fits navigation and users — small, homogeneous, bounded record sets that are
usually needed *together* (the whole nav tree, the user list).

It does **not** fit page content. Content is the opposite access pattern: **few but
heavy records** (a document of many blocks), read **one at a time by a natural key**
(slug + language). A single `content.json` holding all content would force every page
render to load, JSON-decode and hydrate the entire site's content just to return one
document — an unbounded cost that grows with every page added.

Real flat-file CMSs (Statamic, Jekyll, Hugo) avoid this by storing **one file per
content item**, with the slug as the path. The whole `Content` design (see the
content discussion) is slug-addressed, self-contained documents — which maps directly
onto file-per-record.

## Decision

Add a second storage mode to the file driver, selected per entity via the `#[Entity]`
attribute:

```php
#[Entity('file', 'content', perRecord: true, keyBy: ['slug', 'language'])]
```

- `perRecord: true` → **document mode**: `path` is a directory, one file per record.
- `keyBy` → snake_case property names whose values build the filename
  (`<dir>/<slug>.<language>.json`), via the shared `DocumentPath` helper.
- Identity is the `keyBy` fields (user-supplied), **not** an auto-increment `id`.

The mode is realised by a **`RecordStore` strategy**, not by a second repository.
This is the corrected seam (the first cut split it at the repository level):

- `RecordStore` (interface) — `all()` / `byKey()` / `keyFields()` / `persistAll()` /
  `delete()`. Two implementations: `CollectionStore` (one file, array, auto-increment id)
  and `DocumentStore` (one file per record, keyed via `DocumentPath`).
- **One mode-agnostic `FileRepository`** owns query semantics + hydration: when a
  `findBy()` criteria covers `store->keyFields()` it fetches directly via `byKey()`
  (**O(1)**); otherwise it scans `all()` and filters. `DocumentRepository` was removed.
- `FileEntityManager` resolves the store per entity (`perRecord ? DocumentStore :
  CollectionStore`) and delegates: `flush()` groups pending by path and calls the store's
  `persistAll()` (a collection file is written **once** per flush — batching preserved);
  `remove()` calls `store->delete()`. No `perRecord` branching in the EntityManager.

Collection mode remains the default (`perRecord: false`) — navigation and users are
unchanged.

## Reasoning

- **Right access pattern per data type, not per project size.** The driver choice is
  already per-entity (`#[Entity(driver, …)]`); the storage *strategy* should be too.
  Content's read-one-by-key pattern wants direct file resolution, not collection scan.
- **Additive, not a rewrite.** Collection mode is the unchanged default path; document
  mode is a branch keyed on `perRecord`. Navigation/users carry zero risk.
- **Repository API stays driver- and mode-agnostic.** Consumers call
  `findBySlug(...)` / `findBy(...)`; whether that resolves to a file, a directory glob,
  or (later) a SQL `WHERE` is hidden. A future ORM migration changes only the driver.
- **The mode difference is a storage concern, so it lives in the store.** Repository
  and EntityManager are mode-agnostic; the only place that knows collection-vs-document
  is the `RecordStore` strategy, chosen once. No duplicated find/hydrate logic, no
  branching scattered across layers.
- **Single source of truth for filenames.** `DocumentPath` builds the path for both the
  write path (`DocumentStore::persistAll`) and the read path (`DocumentStore::byKey`) —
  they cannot diverge.

## Consequences

- New content-style entities opt in with `perRecord: true` + `keyBy`; no auto `id`.
- `FileRepository::find(int|string $id)` resolves by `store->keyFields()`: collection
  (`[]`) → `id`; single-key document → that key; multi-key (slug+language) → `null`,
  so such entities use a domain method (`ContentRepository::findBySlug`).
- **Rename caveat:** changing a key field value writes a *new* file; the old file is
  orphaned. Edit controllers MUST remove the old record before persisting the renamed
  one (to be enforced in the content editor, Phase 3).
- Backend list views enumerate via directory glob — fine for an admin screen; at very
  large counts a lightweight manifest (`slug → title`) can be added later.
- `FileStorage` gained `delete()`, `list()`, `exists()` and a directory-ensuring
  `save()`. Hydration lives in the `HydratesEntities` trait used by `FileRepository`
  (the prior inline duplication is gone).

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Keep single-collection file for content | Every page render loads + hydrates the whole site's content; grows unbounded — the original "das knallt irgendwann" problem |
| Composite-string key in one collection (`subject = "home-intro"`) | Re-introduces prefix/`LIKE` matching and string-splitting; a real key column/field is strictly better (see content discussion) |
| Separate `file-doc` driver name | Mode is a file-storage detail, not a different backend; a flag on `#[Entity]` is lower-ceremony and keeps one driver/EntityManager |
| Block-per-row table for content | Content blocks are never queried individually nor routed — only read as a whole document; row-orientation buys nothing here and complicates the model |
