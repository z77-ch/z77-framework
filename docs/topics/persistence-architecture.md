# persistence-architecture

2026-09-21

## entry

1. `packages/kernel/persistence/src/Interface/RepositoryInterface.php` — the contract every backend implements; start here to understand what the abstraction guarantees
2. `packages/kernel/persistence/src/Resolver/UnifiedEntityManager.php` — single consumer-facing entry point; routes `getRepository(Class)` and the writes to the correct driver
3. `packages/kernel/persistence/src/Resolver/DataSourceResolver.php` — reads `#[Entity]` attribute, resolves driver name to bootstrap class

## file map

SOURCE=/packages/kernel/persistence/src/Interface/RepositoryInterface.php
SOURCE=/packages/kernel/persistence/src/Interface/EntityManagerInterface.php
SOURCE=/packages/kernel/persistence/src/Resolver/UnifiedEntityManager.php
SOURCE=/packages/kernel/persistence/src/Resolver/DataSourceResolver.php
SOURCE=/packages/kernel/persistence/src/Resolver/RepositoryConvention.php
SOURCE=/packages/kernel/persistence/src/File/Bootstrap.php
SOURCE=/packages/kernel/persistence/src/File/FileEntityManager.php
SOURCE=/packages/kernel/persistence/src/File/Repository/FileRepository.php
SOURCE=/packages/kernel/persistence/src/File/Storage/FileStorage.php
SOURCE=/packages/persistence-doctrine/src/Bootstrap.php
SOURCE=/packages/persistence-doctrine/src/DoctrineEntityManager.php
SOURCE=/packages/persistence-doctrine/src/Repository/DoctrineRepository.php
SOURCE=/packages/kernel/persistence/src/Interface/TransactionInterface.php
SOURCE=/packages/kernel/persistence/src/Exception/TransactionRolledBackException.php
SOURCE=/packages/kernel/shared/src/Attributes/Entity.php
SOURCE=/packages/kernel/shared/src/Traits/ArrayMappable.php
SOURCE=/packages/kernel/shared/src/Libraries/Convention/Naming.php
SOURCE=/packages/kernel/core/src/Bootstrap.php
SOURCE=/packages/kernel/core/src/DI.php
SOURCE=/docs/02-decisions/adr-039-doctrine-driver-behind-unified-entity-manager.md

## mental model

A **driver-abstracted persistence layer with Repository Pattern and Port/Adapter structure**. Entities declare their backend via `#[Entity('driver', 'path')]`. `UnifiedEntityManager` is the sole consumer API: `getRepository(Class)` resolves the driver, boots it lazily and returns a `RepositoryInterface`; `persist()` / `flush()` / `remove()` write through the same entry point. Consumers never see a driver-specific type. Two drivers exist: **File** (kernel, JSON) and **Doctrine** (own package `z77/persistence-doctrine`, MariaDB — ADR-039); the backend is a property of `#[Entity]`, not of the calling code, and a module may mix both.

- Pattern classification: Data Mapper + Repository Pattern + Ports & Adapters (Hexagonal). Closest reference: Spring Data (Java).
- NOT in the shared interface, deliberately: Unit of Work semantics, Identity Map, Lazy Loading, Transactions. Each driver has its own behaviour there (Doctrine has all four, File none) and unifying them would lie to the consumer — see the known issues and ADR-039 decision 9.
- **The transaction port** (ADR-039 decision 10) sits NEXT to the shared interface, not in it: `UnifiedEntityManager::getTransaction(Entity::class)` returns the kernel's `TransactionInterface` — `run(callable): mixed` (atomic unit of work: commit on return, rollback and rethrow on exception, nesting joins) and `isOpen(): bool`. Resolved from an entity class like a repository, so the backend stays a property of `#[Entity]`; the Doctrine driver fulfils it, the File driver throws a `LogicException`. Consumers type against the kernel interface, never against Doctrine. Details and driver behaviour: [`persistence-doctrine.md`](persistence-doctrine.md).
- `RepositoryInterface` is intentionally minimal and **read-only**: `find`, `findAll`, `findBy`, `findOneBy`. Writes go through `UnifiedEntityManager::persist()` (stage), `flush()` (write every booted driver in turn), `remove()`, and the File-only `reorder()`.
- Entity-specific repositories are discovered by convention (`RepositoryConvention`: `…\Entities\X` → `…\Repositories\XRepository`) and **extend the driver's base repository** (`FileRepository` or `DoctrineRepository`). They add domain methods on top of the four reads.
- Switching a backend honestly means (ADR-039 decision 7): for an entity with the generic repository, a change to `#[Entity]`; for an entity with a specific repository, `#[Entity]` **plus** the repository's parent class. A repository with report SQL is Doctrine-only by design.
- Performance characteristics differ per backend behind the same API: `findBy` on File = PHP array filter O(n); on Doctrine = SQL WHERE O(log n). Known, accepted abstraction leak for small datasets.
- Use case fit: one-pagers and small sites → File driver, zero infrastructure. Order processing, accounting → Doctrine driver, full SQL power, same consumer API. The Doctrine package is optional: an installation without it runs as before, and the kernel names the driver in its map regardless (ADR-039 decision 2).
- The driver map lives in `core/src/Bootstrap.php` (`['file' => 'File', 'doctrine' => 'Doctrine']`); a name there is no dependency — the resolver looks for `Z77\Persistence\{Driver}\Bootstrap` at the first entity of that driver and fails with the package named when it is absent.

## flow

```text
╔══════════════════════════════════════════════════════════════════════════════╗
║  CONSUMER LAYER                                                              ║
╚══════════════════════════════════════════════════════════════════════════════╝

  Controller / Service
  └─ $uem->getRepository(Navigation::class)            ← sole consumer API
       │
       │  DI wiring:  core/src/Bootstrap.php
       │  Container:  core/src/DI.php


╔══════════════════════════════════════════════════════════════════════════════╗
║  RESOLVER LAYER                                                              ║
╚══════════════════════════════════════════════════════════════════════════════╝

  UnifiedEntityManager::getRepository(string $className)
  │  persistence/src/Resolver/UnifiedEntityManager.php
  │
  ├─ [1] DataSourceResolver::resolveEntity($className)
  │       persistence/src/Resolver/DataSourceResolver.php
  │       shared/src/Attributes/Entity.php
  │
  │       → ReflectionClass reads #[Entity('file', 'framework/routing/navigation.json')]
  │       → driverMap['file'] = 'File'  →  $attr->driver = 'File'
  │       → result cached per class
  │
  └─ [2] bootManager($driver)   ← lazy, once per driver per request
          persistence/src/Resolver/UnifiedEntityManager.php
          → class name: Z77\Persistence\{Driver}\Bootstrap
          → class missing → RuntimeException naming the package to require


╔══════════════════════════════════════════════════════════════════════════════╗
║  ▼▼▼  BRANCHING POINT — driver determined by #[Entity('driver', ...)]  ▼▼▼  ║
╚══════════════════════════════════════════════════════════════════════════════╝

         #[Entity('file', 'path.json')]          #[Entity('doctrine')]
                    │                                     │
             driver = 'File'                    driver = 'Doctrine'
                    │                                     │
                    ▼                                     ▼
         File\Bootstrap                    Doctrine\Bootstrap
         (kernel)                          (package z77/persistence-doctrine)
                    │                                     │
                    ▼                                     ▼
         FileEntityManager               DoctrineEntityManager
                    │                                     │
                    ▼                                     ▼
         FileRepository                  DoctrineRepository
         (generic reads, JSON)           (generic reads, Doctrine ORM)


╔══════════════════════════════════════════════════════════════════════════════╗
║  FILE BRANCH                                                                 ║
╚══════════════════════════════════════════════════════════════════════════════╝

  File\Bootstrap::getEntityManager()
  │  persistence/src/File/Bootstrap.php
  │  → new FileEntityManager(FileStorage, CacheManager)
  │
  FileEntityManager::getRepository(Navigation::class, $attr)
  │  persistence/src/File/FileEntityManager.php
  │
  ├─ RepositoryConvention::specificRepository(): convention discovery
  │       \Entities\Navigation  →  \Repositories\NavigationRepository
  │       │
  │       ├─ found  → new NavigationRepository($class, RecordStore)
  │       │              shared/src/Repositories/NavigationRepository.php
  │       │              (also: MetaDataRepository, BackendUserRepository)
  │       │
  │       └─ not found  → new FileRepository($class, RecordStore)
  │
  └─ SERIALIZATION (on every read/write):
          Entity::mapFromArray(array $row)   ← snake_case JSON keys → camelCase setters
          Entity::mapToArray()               ← camelCase properties → snake_case JSON keys
          shared/src/Traits/ArrayMappable.php


╔══════════════════════════════════════════════════════════════════════════════╗
║  DOCTRINE BRANCH — package z77/persistence-doctrine (ADR-039)               ║
╚══════════════════════════════════════════════════════════════════════════════╝

  Doctrine\Bootstrap::getEntityManager()
  │  packages/persistence-doctrine/src/Bootstrap.php
  │  → reads config/client/database.inc.php, systemConfig baseCurrency,
  │    ModuleManager::getDoctrineEntities()
  │  → EntityManagerFactory::create() → Doctrine\ORM\EntityManager
  │    (attribute metadata over the explicit class list, native lazy objects,
  │     utf8mb4 / utf8mb4_unicode_ci, MoneyType registered)
  │
  DoctrineEntityManager::getRepository(Invoice::class, $attr)
  │  → same RepositoryConvention discovery as the File branch
  │       found     → new InvoiceRepository($class, Doctrine EM)   extends DoctrineRepository
  │       not found → new DoctrineRepository($class, Doctrine EM)
  │
  DoctrineRepository implements RepositoryInterface
  │  → find / findAll / findBy / findOneBy on Doctrine's EntityRepository
  │  → protected connection(): DBAL for report SQL in a subclass (decision 8)
  │
  Entity requirements for Doctrine:
  │  - #[Entity('doctrine')]           ← z77 routing attribute, no path
  │  - #[ORM\Entity], #[ORM\Column]    ← Doctrine mapping attributes
  │  - listed in the module's `doctrineEntities`
  │    (a project's own entity: App/Config/doctrineEntitiesConfig.inc.php)
  │  - ArrayMappable NOT needed        ← Doctrine has own hydration
  │  - Money columns: type MoneyType::NAME → DECIMAL(15,2)

  Details: persistence-doctrine.md


╔══════════════════════════════════════════════════════════════════════════════╗
║  RETURN — all branches converge                                              ║
╚══════════════════════════════════════════════════════════════════════════════╝

  UnifiedEntityManager returns RepositoryInterface to consumer
  │  caches: EntityManager per driver (Bootstrap)
  │  caches: Repository per entity class (FileEntityManager / DoctrineEntityManager)
  │
  Consumer receives NavigationRepository (or the generic driver repository as fallback)
  Consumer calls: find() / findAll() / findBy() / findOneBy()
  Consumer writes: $uem->persist() / $uem->flush() / $uem->remove()
  Consumer calls domain methods: findByPath() / findByName() / findByNavigationAndLanguage()
  Consumer NEVER knows which driver is active
```

A Memory driver (tests without infrastructure) was named in earlier versions of this
document. It does not exist in code and is not promised (ADR-039 decision 16); driver tests
run against a throwaway MariaDB schema instead.

## driver contract

To add a new persistence driver `{Driver}` — in the kernel (`packages/kernel/persistence/src/{Driver}/`)
or, when it brings Composer dependencies, in its own package with the same namespace
(ADR-001, ADR-039 decision 1):

```text
1. Z77\Persistence\{Driver}\Bootstrap
   - constructor: boot driver infrastructure (connection, file path, etc.)
   - getEntityManager(): EntityManagerInterface

2. Z77\Persistence\{Driver}\{Driver}EntityManager implements EntityManagerInterface
   - getRepository(string $entityClass, Entity $attr): RepositoryInterface
   - convention discovery through RepositoryConvention::specificRepository()
   - caches repositories per class
   - persist / flush / remove; reorder only where the driver can honour it

3. Z77\Persistence\{Driver}\Repository\{Driver}Repository implements RepositoryInterface
   - find / findAll / findBy / findOneBy

4. Register in the driver map (core/src/Bootstrap.php):
   new DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine', '{driver}' => '{Driver}'])

5. On entity:
   change #[Entity('file', '...')] to #[Entity('{driver}')]
   add any driver-specific mapping attributes (e.g. #[ORM\Entity] for Doctrine)
```

## rules

- When implementing a new persistence driver → MUST create `Bootstrap`, `EntityManager`, and a generic `Repository` in `Z77\Persistence\{Driver}\`; MUST implement `RepositoryInterface` and `EntityManagerInterface`
- When adding a method to `RepositoryInterface` → MUST verify it is implementable by ALL drivers (File, Doctrine); MUST NOT add driver-specific semantics to the shared interface
- When writing entity-specific repository domain methods → MUST use only `RepositoryInterface` methods internally; MUST NOT call driver-specific APIs (no `EntityManager::createQuery`, no direct `FileStorage` access) — EXCEPT as a documented deviation: a report method that is SQL rather than a criteria lookup runs on `DoctrineRepository::connection()`, is marked Doctrine-only in its docblock, and lives outside `RepositoryInterface` (ADR-039 decision 8)
- When switching an entity from one backend to another → MUST change the `#[Entity]` attribute and, for an entity with a specific repository, that repository's parent class (`FileRepository` ↔ `DoctrineRepository`); MUST NOT change consumer code or repository method signatures (ADR-039 decision 7)
- When a complex query requires driver-specific features (DQL, QueryBuilder, SQL) → MUST NOT force it through `RepositoryInterface`; implement as a driver-specific method outside the interface and document the deviation
- When obtaining a repository in a service or controller → MUST use `UnifiedEntityManager::getRepository()`; MUST NOT instantiate repositories with `new`; MUST NOT hold a Doctrine `EntityManager` (ADR-039 decision 6)
- When writing an entity → MUST call `UnifiedEntityManager::persist()` for everything that is meant to be written and MUST NOT mutate a loaded entity that is not meant to be written — the Doctrine driver flushes every managed change, the File driver only what was persisted (ARCH-A004)
- When creating an entity-specific repository → MUST place it in `{RootNamespace}\Repositories\{EntityName}Repository` (the convention `RepositoryConvention` resolves for every driver); MUST extend the driver's base repository class (`FileRepository` / `DoctrineRepository`) — NOT compose it via constructor injection
- When declaring an entity for a non-file backend → MUST omit or leave empty `Entity::$path` (defaults to `''`); `$path` is only meaningful for the File driver and MUST NOT be used by other drivers
- When a use case must be atomic → MUST write to ONE driver inside it; file-based master data is read, not written, there (ARCH-A007)
- When a use case needs a transaction (several flushes, a gapless number plus a document, SQL and ORM writes together) → MUST obtain the port through `UnifiedEntityManager::getTransaction(Entity::class)` with a class of the driver it writes to and run the work inside `run()`; MUST NOT add `beginTransaction()` / `commit()` to `RepositoryInterface` or `EntityManagerInterface`'s shared semantics, and MUST NOT catch the File driver's refusal to «fall back» to non-atomic writes (ARCH-A003)

## known issues

- **ARCH-A001** — don't assume `findBy` is performant across all backends. File backend loads all records and filters in PHP; Doctrine generates SQL WHERE. Same interface, different complexity. Acceptable for small datasets (< ~5k records); becomes a problem at scale.
- **ARCH-A002** — don't assume either Identity Map behaviour. On the File driver `find(1)` called twice returns two distinct PHP objects and in-process state can diverge; on the Doctrine driver the same row is the same object within a request. Code that runs on both must not rely on either.
- **ARCH-A003** — don't attempt to abstract transactions through `RepositoryInterface` — no transaction in the *shared* interface. File storage has no rollback; `beginTransaction()` on the interface would be semantically dishonest for File. What exists instead is the separate transaction port of ADR-039 decision 10: `UnifiedEntityManager::getTransaction(Entity::class)` → `TransactionInterface`, fulfilled by the Doctrine driver only and refused by the File driver with a `LogicException`. Nesting joins (no savepoint semantics through the port), an exception anywhere rolls back the whole, and the Doctrine EntityManager is replaced after a rollback — see [`persistence-doctrine.md`](persistence-doctrine.md) DOCTRINE-TX-001…004.
- **ARCH-A004** — don't assume flush scope. Doctrine writes every *managed* entity that changed, even one never passed to `persist()`; File writes only what was `persist()`ed (ADR-039 decision 9). The transaction port's `run()` flushes the Doctrine EntityManager before its outermost commit — so it writes everything managed, including an entity loaded and mutated BEFORE `run()` — while a File entity persisted inside `run()` still needs an explicit `flush()` and is never rolled back (DOCTRINE-TX-007, ARCH-A007).
- **ARCH-A005** — don't assume `remove()` timing. File deletes at once; Doctrine at the next `flush()` (decision 9).
- **ARCH-A006** — don't call `reorder()` on a Doctrine entity: it is File-only (sort order in a JSON collection) and the Doctrine driver refuses it with a `LogicException` (decision 9).
- **ARCH-A007** — don't assume one `flush()` across drivers is atomic. It writes the File driver and the Doctrine transaction one after the other, in boot order; a failure in the second leaves the first written (decision 9).

## pending

- None documented.

## see also

- [`persistence-file.md`](persistence-file.md) — File driver: implementation details, entity-specific repo discovery, known file-driver issues and pending
- [`persistence-doctrine.md`](persistence-doctrine.md) — Doctrine driver: package, connection config, entity announcement, Money column type, driver-specific behaviour
