# persistence-file

2026-08-03

## entry

1. `packages/kernel/persistence/src/Resolver/UnifiedEntityManager.php` — main entry: `getRepository(Class)`, `persist($entity)`, `flush()`, `remove($entity)`
2. `packages/kernel/persistence/src/File/FileEntityManager.php` — implements `EntityManagerInterface`: Unit of Work (persist/flush/remove) + repo resolution
3. `packages/kernel/persistence/src/File/Repository/FileRepository.php` — read-only CRUD for JSON-backed entities (find*)
4. `packages/kernel/shared/src/Attributes/Entity.php` — `#[Entity('file', 'path.json')]` attribute

## file map

SOURCE=/packages/kernel/persistence/src/Resolver/UnifiedEntityManager.php
SOURCE=/packages/kernel/persistence/src/Resolver/DataSourceResolver.php
SOURCE=/packages/kernel/persistence/src/Interface/RepositoryInterface.php
SOURCE=/packages/kernel/persistence/src/Interface/EntityManagerInterface.php
SOURCE=/packages/kernel/persistence/src/File/Bootstrap.php
SOURCE=/packages/kernel/persistence/src/File/FileEntityManager.php
SOURCE=/packages/kernel/persistence/src/File/Repository/FileRepository.php
SOURCE=/packages/kernel/persistence/src/File/Repository/HydratesEntities.php
SOURCE=/packages/kernel/persistence/src/File/Storage/FileStorage.php
SOURCE=/packages/kernel/persistence/src/File/Storage/RecordStore.php
SOURCE=/packages/kernel/persistence/src/File/Storage/CollectionStore.php
SOURCE=/packages/kernel/persistence/src/File/Storage/DocumentStore.php
SOURCE=/packages/kernel/persistence/src/File/Storage/DocumentPath.php
SOURCE=/packages/kernel/shared/src/Libraries/Convention/Naming.php
SOURCE=/packages/kernel/shared/src/Traits/ArrayMappable.php
SOURCE=/packages/kernel/shared/src/Attributes/Entity.php
SOURCE=/packages/kernel/shared/src/Entities/Navigation.php
SOURCE=/packages/kernel/shared/src/Entities/MetaData.php
SOURCE=/packages/kernel/shared/src/Entities/BackendUser.php
SOURCE=/packages/kernel/shared/src/Repositories/NavigationRepository.php
SOURCE=/packages/kernel/shared/src/Repositories/MetaDataRepository.php
SOURCE=/packages/kernel/shared/src/Repositories/BackendUserRepository.php

## mental model

Entities declare their storage via `#[Entity('file', 'path.json')]`. `UnifiedEntityManager` is the single entry point for consumers — it exposes `getRepository()`, `persist()`, `flush()`, and `remove()`. Internally it resolves the driver via `DataSourceResolver` (`'file'` → `'File'`) and boots the driver's `EntityManager` lazily (once per driver per request).

**Separation of concerns (three layers below the EntityManager):**
- `RepositoryInterface` / `FileRepository` — read-only query semantics (`find*` + criteria matching) + hydration. **One mode-agnostic class** — it asks a `RecordStore` for rows and does not know whether they came from a collection file or a per-record directory.
- `RecordStore` (strategy) — *where and how* records live and are written: `CollectionStore` (one file, array of rows, auto-increment id) vs. `DocumentStore` (one file per record, keyed by `keyBy`). Owns `all()` / `byKey()` / `keyFields()` / `persistAll()` / `delete()`.
- `FileStorage` — raw file I/O (JSON load/save/delete/list); path-based, mode-unaware. `save()` is atomic: it writes to a `*.tmp` file in the target directory and `rename()`s onto the target, so readers always see the old or the new state, never a half-written file (DATA-ATOMIC-001). It is the single write funnel — `CollectionStore`, `DocumentStore`, `FileEntityManager::reorder()`, and `TranslationCatalog` all write through it. `withExclusiveLock($path, $fn)` additionally guards a whole read-merge-write CYCLE with a cross-process `flock` on a companion `<path>.lock` file (~2 s acquisition budget, then throws) — `CollectionStore::persistAll()`/`delete()` and `FileEntityManager::reorder()` run under it, otherwise two near-simultaneous writers would merge into the same base state and the second would silently drop the first's changes (DATA-LOCK-001).
- `EntityManagerInterface` / `FileEntityManager` — Unit of Work: `persist` queues, `flush` groups pending by path and delegates to the store's `persistAll` (so a collection file is written once per flush), `remove` delegates to the store, `reorder` rewrites a collection file in a given order. It resolves the store per entity (`perRecord ? DocumentStore : CollectionStore`).

- All paths are relative to `ABS_BASE_PATH/data/`.
- Repositories are NOT registered in DI as named services — they are instantiated by `FileEntityManager` and returned via `UEM::getRepository()`.
- JSON keys are snake_case; PHP properties are camelCase. `ArrayMappable` (trait on every entity) handles the mapping: `mapFromArray` on read, `mapToArray` on write.
- `FileEntityManager` resolves entity-specific repos by convention: `\Entities\{Name}` → `\Repositories\{Name}Repository`. If the class exists it is instantiated with the shared constructor args (`$entityClass`, `$store`) — it extends `FileRepository` and inherits all read methods. Otherwise the generic `FileRepository` is returned. The mode lives entirely in the injected store, so entity-specific repos are mode-agnostic too.

## storage modes (collection vs document)

The file driver has two modes, selected per entity via the `#[Entity]` attribute. The mode is realised by a `RecordStore` strategy; the repository and EntityManager stay mode-agnostic.

| | Collection mode (default) — `CollectionStore` | Document mode (`perRecord: true`) — `DocumentStore` |
|---|---|---|
| Attribute | `#[Entity('file', 'framework/routing/navigation.json')]` | `#[Entity('file', 'content', perRecord: true, keyBy: ['slug', 'language'])]` |
| `path` means | one JSON **file** holding an array of all records | a **directory** holding one file per record |
| Filename | n/a (single file) | `keyBy` values joined by `.` → `<dir>/<slug>.<language>.json` (built by `DocumentPath`) |
| Identity | auto-increment int `id` (`CollectionStore::nextId` = max+1) | the `keyBy` fields (user-supplied, e.g. slug+language) — **no auto `id`** |
| `keyFields()` | `[]` → every lookup scans | `keyBy` → `findBy()` with all key fields present loads **one file directly** (O(1)) |
| `all()` | loads the whole file | globs the directory + loads each file |
| `persistAll()` | upserts the array, writes the file **once** per flush (batched) | writes one file per entity |
| `delete()` | loads, filters out by `id`, rewrites the file | `delete()`s the one file |

**When to use which:** collection mode fits **many small, homogeneous records read together** (navigation tree, user list) — read-all is the normal access. Document mode fits **few heavy records read one at a time by key** (page content) — loading the whole collection per request would not scale. See [`../02-decisions/adr-010-file-per-record-storage.md`](../02-decisions/adr-010-file-per-record-storage.md).

Both modes share hydration via the `HydratesEntities` trait (setters via `mapFromArray`, server-controlled setterless props via reflection). The `RecordStore` returns raw rows; the repository hydrates.

## flow

```text
#[Entity('file', 'path.json')]
→ UnifiedEntityManager::getRepository(Class)
→ DataSourceResolver reads attr, maps 'file' → 'File'
→ bootManager('File') → Z77\Persistence\File\Bootstrap  (lazy, once per driver)
→ FileEntityManager::getRepository(Class, $attr)        (cached per class)
→ resolveStore($attr) → CollectionStore | DocumentStore  (per perRecord)
→ FileRepository(store) → store->all() / store->byKey() → FileStorage
```

## DI (Bootstrap::pullUp)

```php
DataSourceResolver(['file' => 'File'])
UnifiedEntityManager(DataSourceResolver)
NavigationService(
    UEM::getRepository(Navigation::class),   // returns NavigationRepository
    UEM::getRepository(MetaData::class),     // returns MetaDataRepository
    CacheManager
)
```

Repositories are NOT registered in DI as named services. `NavigationService` receives them via `UEM::getRepository()` in the DI factory callable.

## shared/Repositories API

Entity-specific repos extend `FileRepository` (inheritance, not composition). Domain methods call `$this->find*()` directly — no delegation boilerplate. When switching to a different driver, repos must be rewritten to extend that driver's base repository.

| Repository | Domain methods (beyond RepositoryInterface) |
|---|---|
| `NavigationRepository` | _(none — all navigation query logic lives in `NavigationService`)_ |
| `NavigationAliasRepository` | `findByPath(string): ?NavigationAlias` / `findByNavigationId(int): NavigationAlias[]` |
| `MetaDataRepository` | `findByNavigationAndLanguage(int, string): ?MetaData` |
| `BackendUserRepository` | `findByUsername(string): ?BackendUser` |
| `ContentRepository` | `findBySlug(string, string): ?Content` |

The `Navigation` tree is expressed by a `parentId` FK on each child (no embedded `children` array); children are resolved by `NavigationService::getChildren()` (ADR-008 tree foundation).

## data/ structure

```text
data/
  framework/
    routing/navigation.json          ← Navigation entity
    routing/tags.json                ← Tag entity
    seo/metadata.json                ← MetaData entity
    auth/backendUsers.json             ← BackendUser entity
  content/{slug}.{lang}.json         ← Content entity (document mode, one file per record)
  project/{module}/*.json            ← project-specific entities
```

Default templates live anywhere under `core/data/` as `*.default.json` (e.g.
`core/data/framework/routing/navigation.default.json`, `core/data/content/home.de.default.json`).
The Installer (`Install::writeDataFiles`) deploys each to the same relative path under
`data/` with the `.default` marker stripped — created on first install, never overwritten.

**Encoding: all `data/**/*.json` (and `core/data/**/*.default.json`) are UTF-8 WITHOUT
BOM.** `FileStorage::save()` writes exactly that (`json_encode` with
`JSON_UNESCAPED_UNICODE`, no BOM) — atomically via tmp write + `rename()`; on Windows a
target briefly held open by a reader is covered by a short retry loop, and if the target
stays locked beyond it `save()` throws while the old file remains valid (DATA-ATOMIC-001). Edit them through the backend UI (writes via
`FileStorage`) or a UTF-8-safe editor — **never** round-trip through Windows PowerShell
(`Get-Content` → `Set-Content` / `Out-File` / `>`, even `-Encoding utf8`), which reads
no-BOM UTF-8 as CP1252 and double-encodes umlauts / adds a BOM (see DATA-JSON-001).
`load()` tolerates a stray BOM and throws on a corrupt (non-empty, unparseable) file —
it never silently returns `[]` for corruption.

## rules

- When constructing a `FileStorage` path → MUST be relative to `ABS_BASE_PATH/data/`; MUST NOT be absolute
- When using `findBy` → MUST use snake_case keys mapped to getters via `Naming::toGetter` (e.g. `navigation_id` → `getNavigationId()`)
- When obtaining a repository in a controller → MUST use `UnifiedEntityManager::getRepository()`; MUST NOT instantiate repositories with `new`
- When persisting/removing in a controller → MUST use `UnifiedEntityManager::persist()` / `remove()` / `flush()`; MUST NOT call these on the repository
- When a service depends on a repository → MUST receive it via constructor injection; MUST NOT pass repositories as method parameters
- When working with `Entity::$driver` → MUST NOT guard against external mutation (`DataSourceResolver` mutates it intentionally after attribute instantiation)
- When an entity is read one record at a time by a natural key (not as a whole collection) and records are heavy → MUST use document mode (`#[Entity('file', '<dir>', perRecord: true, keyBy: [...])]`); MUST NOT pile such records into a single collection file (every read would load + hydrate the whole site). `keyBy` MUST be snake_case property names with non-empty values (they build the filename via `DocumentPath`). Renaming a key field (e.g. slug) writes a new file — the controller MUST remove the old record first (orphan-file avoidance)
- When an entity's writes affect frontend rendering → MUST set `invalidatesCache: true` on its `#[Entity]` attribute. `FileEntityManager` then auto-clears `DataCache` + `PageCache` after `flush()` / `remove()` / `reorder()`. Controllers MUST NOT clear caches manually after writes.
- When editing any `data/**/*.json` or `core/data/**/*.default.json` by hand/tooling → MUST keep it UTF-8 WITHOUT BOM (use the Read+Edit/Write tools or a UTF-8-safe editor); MUST NOT round-trip it through Windows PowerShell (`Get-Content` → `Set-Content`/`Out-File`/`>`, even `-Encoding utf8`), which corrupts umlauts / adds a BOM (DATA-JSON-001).
- When writing a data file from framework/project code → MUST go through `FileStorage::save()` (atomic tmp+rename, DATA-ATOMIC-001); MUST NOT `file_put_contents` into `data/` directly — a direct write reintroduces the torn-read window. When touching the write path → the tmp suffix MUST NOT be `.json` (`list()` globs `*.json`; a crash leftover must never be read as data) and `tests/file-storage-atomicity.php` MUST stay green.
- When a write path reads a data file, modifies the data, and writes it back (load → merge → save) → MUST run the WHOLE cycle inside `FileStorage::withExclusiveLock()` (DATA-LOCK-001) — `save()` alone only makes the single write atomic, not the cycle; MUST NOT flock the target file itself (rename-replace leaves the lock on a stale inode — the lock lives in `<path>.lock`). `tests/file-driver-concurrency.php` MUST stay green.
- When encountering a `*.json.lock` companion file under `data/` → MUST leave it in place; deleting it races a process already blocked on its handle. Lock files are not data (`list()` ignores them) and are created once per collection file.

## see also

- [`login.md`](login.md) — `BackendUserRepository` consumer; `passwordHash` round-trip fixed (BUG-P001 resolved)
- [`cache.md`](cache.md) — automatic cache invalidation via `#[Entity(..., invalidatesCache: true)]`
- [`../03-development/review-persistence-repository.md`](../03-development/review-persistence-repository.md) — full review with reasoning

## known issues
- **ARCH-P001** — resolved. `FileEntityManager` discovers entity-specific repos by convention (`\Entities\{Name}` → `\Repositories\{Name}Repository`) and passes the generic `FileRepository` via constructor. Entity-specific repos implement `RepositoryInterface` via composition — driver-agnostic.
- **ARCH-P002** — resolved. `UserPreferences` moved to `shared/src/ValueObjects/`; all imports updated.
- **DEAD-P001** — resolved. `packages/kernel/core/src/Routing/NavigationRepository.php` deleted.
- **MED-P001** — resolved (fell out with DEAD-P001).
- **MED-P002** — resolved framing: `AuthService::savePreferences` does not belong in `AuthService` at all. Auth service is responsible for auth only; preference persistence is a controller concern. Architecture pending — see `backend.md`.
- **MED-P003** — resolved. `LoginController` and `SystemController` use `UEM::getRepository(BackendUser::class)` instead of `new BackendUserRepository(...)`.
- **CLEAN-P001** — resolved. `decamelize()` + `camelize()` removed from `core/src/autoload/prod/php/Functions.php`.
- **DATA-LOCK-001** — resolved 2026-08-03. Every read-merge-write cycle of the file driver (`CollectionStore::persistAll()`/`delete()`, `FileEntityManager::reorder()`) ran unguarded — two near-simultaneous requests both loaded the same base state and the later write silently dropped the earlier one (lost update; `persistAll` could additionally hand out duplicate auto-increment ids). `LOCK_EX` inside `save()` (and since DATA-ATOMIC-001 the tmp+rename) only makes the single WRITE atomic, never the cycle. Raised by the AXO3 B1 review. Fix: `FileStorage::withExclusiveLock($path, $fn)` — exclusive cross-process `flock` on a companion `<path>.lock` file (the target itself cannot carry the lock: rename-replace would leave the flock on a stale inode), acquired via non-blocking polling with a ~2 s budget, then `RuntimeException` (a plain blocking flock has no timeout and would hang on a stuck holder). The lock file is never unlinked (unlink races a blocked waiter) and is invisible to `list()` (globs `*.json`). `flock()` verified empirically on NTFS and the Z: NAS/SMB share (cross-process `LOCK_NB` contention + blocking acquisition after release). `DocumentStore` needs no cycle lock — it replaces whole records, no merge (write-write races there are the optimistic-locking concern, see [`entity-data-handling.md` → optimistic locking](entity-data-handling.md#optimistic-locking-entity_hash)). Verified: `tests/file-driver-concurrency.php` (7 checks — 60 concurrent cycles from 4 processes without a lost update or duplicate row, loud lock timeout, lock file invisible to `list()`).
- **DATA-ATOMIC-001** — resolved 2026-08-03. `FileStorage::save()` wrote directly into the target with `file_put_contents(..., LOCK_EX)` — `LOCK_EX` only guards against other *locking writers*; `load()` does not lock, so a parallel reader could catch a half-written file (reachable corruption for every file-driven entity: `navigation.json`, `backendUsers.json`, content documents, AXO3 mirrors). Raised by the AXO3 B1 spec (§6: mirror must be replaced atomically on import). Fix: `save()` writes to a unique `<target>.<pid>.<rand>.tmp` in the same directory, then `rename()`s onto the target — readers see old or new, never a torn state. Windows verified empirically (NTFS + Z: NAS share): `rename()` over an existing target works (MoveFileEx semantics) but fails with a sharing violation while a reader holds the target open — covered by a retry loop (10 attempts, growing backoff, ~275 ms budget; readers hold handles only for the duration of `file_get_contents`). On exhaustion `save()` unlinks the tmp and throws while the old file stays valid (fail-loud, like DATA-JSON-001). `json_encode` failure now also throws instead of blanking the target. Encoding invariant unchanged (UTF-8 without BOM, `JSON_UNESCAPED_UNICODE`); `save()` signature unchanged. Verified: `tests/file-storage-atomicity.php` (14 checks incl. crash simulation between tmp write and rename, tmp leftovers invisible to `list()`, locked-target fail-loud) + AXO3 `work/harness/b1-estate-harness.php` (39 checks, via vendor symlink) — both green.
- **DATA-JSON-001** — resolved 2026-07-18. `FileStorage::load()` did `json_decode($json) ?? []`, so ANY parse failure (a stray UTF-8 BOM, truncation, corruption) silently returned `[]` — a BOM'd `navigation.json` would blank the whole navigation with no error. Trigger: a Claude Code session edited `navigation.json` via Windows PowerShell 5.1 (`Get-Content` defaults to CP1252 for a no-BOM file; `Set-Content -Encoding utf8` re-adds a BOM), producing `Übersetzungen` → `Ãœbersetzungen` (`C3 9C` → `C3 83 C5 93`) and BOMs. Fix: `load()` strips a leading BOM, treats an empty/whitespace file as `[]` (legitimate), and THROWS `RuntimeException` on a non-empty unparseable file (fail-loud, never silent `[]`). Does NOT catch mojibake double-encoding (valid UTF-8, undetectable on read) — prevention is the UTF-8-no-BOM convention above + the CLAUDE.md rule (never PowerShell round-trip data JSON) + an optional project dev hook (`.claude/hooks/check-data-encoding.sh` scanning for `Ã`/BOM). Verified via CLI harness (valid/missing/empty/whitespace → correct; BOM+valid loads with umlaut intact; literal `null`/scalar → `[]`; corrupt and BOM+corrupt → throw). Handoff origin: `{projekt}/work/docs/topics/data-json-encoding.md`.

## pending

_(none)_
