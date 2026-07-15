# persistence-architecture

2026-05-10

## entry

1. `packages/kernel/persistence/src/Interface/RepositoryInterface.php` — the contract every backend implements; start here to understand what the abstraction guarantees
2. `packages/kernel/persistence/src/Resolver/UnifiedEntityManager.php` — single consumer-facing entry point; routes `getRepository(Class)` to the correct driver
3. `packages/kernel/persistence/src/Resolver/DataSourceResolver.php` — reads `#[Entity]` attribute, resolves driver name to bootstrap class

## file map

SOURCE=/packages/kernel/persistence/src/Interface/RepositoryInterface.php
SOURCE=/packages/kernel/persistence/src/Resolver/UnifiedEntityManager.php
SOURCE=/packages/kernel/persistence/src/Resolver/DataSourceResolver.php
SOURCE=/packages/kernel/persistence/src/File/Bootstrap.php
SOURCE=/packages/kernel/persistence/src/File/FileEntityManager.php
SOURCE=/packages/kernel/persistence/src/File/Repository/FileRepository.php
SOURCE=/packages/kernel/persistence/src/File/Storage/FileStorage.php
SOURCE=/packages/kernel/shared/src/Attributes/Entity.php
SOURCE=/packages/kernel/shared/src/Traits/ArrayMappable.php
SOURCE=/packages/kernel/shared/src/Libraries/Convention/Naming.php
SOURCE=/packages/kernel/core/src/Bootstrap.php
SOURCE=/packages/kernel/core/src/DI.php

## mental model

A **driver-abstracted persistence layer with Repository Pattern and Port/Adapter structure**. Entities declare their backend via `#[Entity('driver', 'path')]`. `UnifiedEntityManager::getRepository(Class)` is the sole consumer API — it resolves the driver, boots it lazily, and returns a `RepositoryInterface`. Consumers never see a driver-specific type. Switching a backend requires only changing the `#[Entity]` attribute on the entity class.

- Pattern classification: Data Mapper + Repository Pattern + Ports & Adapters (Hexagonal). Closest reference: Spring Data (Java).
- NOT implemented: Unit of Work, Identity Map, Lazy Loading, Transaction abstraction — deliberate; these are driver-specific and cannot be unified without lying to the consumer.
- `RepositoryInterface` is intentionally minimal: `find`, `findAll`, `findBy`, `findOneBy`, `persist`, `flush`, `delete`. Lowest common denominator across all realistic backends. `persist` stages an entity; `flush` writes all staged entities at once — mirrors Doctrine's pattern.
- Entity-specific repos implement `RepositoryInterface` via composition — receive the generic driver repo and add domain methods using only `RepositoryInterface` calls. Driver-agnostic by design.
- Performance characteristics differ per backend behind the same API: `findBy` on File = PHP array filter O(n); on Doctrine = SQL WHERE O(log n). Known, accepted abstraction leak for small datasets.
- Use case fit: One-pagers and small sites → File driver, zero infrastructure. Complex applications (order processing, accounting) → Doctrine driver, full SQL power. Same consumer API for both.

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
          shared/src/Libraries/Convention/Naming.php
          → builds class name: Z77\Persistence\{Driver}\Bootstrap


╔══════════════════════════════════════════════════════════════════════════════╗
║  ▼▼▼  BRANCHING POINT — driver determined by #[Entity('driver', ...)]  ▼▼▼  ║
╚══════════════════════════════════════════════════════════════════════════════╝

         #[Entity('file', 'path.json')]          #[Entity('doctrine')]          #[Entity('memory')]
                    │                                     │                           │
             driver = 'File'                    driver = 'Doctrine'          driver = 'Memory'
                    │                                     │                           │
                    ▼                                     ▼                           ▼
         File\Bootstrap                    Doctrine\Bootstrap               Memory\Bootstrap
         (implemented)                     (future)                         (future)
                    │                                     │                           │
                    ▼                                     ▼                           ▼
         FileEntityManager               DoctrineEntityManager             MemoryEntityManager
                    │                                     │                           │
                    ▼                                     ▼                           ▼
         FileRepository                  DoctrineRepository                MemoryRepository
         (generic CRUD)                  (wraps Doctrine EM)               (array in memory)


╔══════════════════════════════════════════════════════════════════════════════╗
║  FILE BRANCH — implemented                                                   ║
╚══════════════════════════════════════════════════════════════════════════════╝

  File\Bootstrap::getEntityManager()
  │  persistence/src/File/Bootstrap.php
  │  → new FileEntityManager(FileStorage)
  │
  FileEntityManager::getRepository(Navigation::class, $attr)
  │  persistence/src/File/FileEntityManager.php
  │
  ├─ new FileRepository(Navigation::class, $attr, $storage)
  │       persistence/src/File/Repository/FileRepository.php
  │       persistence/src/File/Storage/FileStorage.php
  │       → reads/writes: data/framework/routing/navigation.json
  │
  ├─ resolveSpecific(): convention discovery
  │       \Entities\Navigation  →  \Repositories\NavigationRepository
  │       class_exists() check
  │       │
  │       ├─ found  → new NavigationRepository(FileRepository)
  │       │              shared/src/Repositories/NavigationRepository.php
  │       │              (also: MetaDataRepository, LoginUserRepository)
  │       │
  │       └─ not found  → returns generic FileRepository directly
  │
  └─ SERIALIZATION (on every read/write):
          Entity::mapFromArray(array $row)   ← snake_case JSON keys → camelCase setters
          Entity::mapToArray()               ← camelCase properties → snake_case JSON keys
          shared/src/Traits/ArrayMappable.php
          shared/src/Libraries/Convention/Naming.php (toSnakeCase / toCamelCase)


╔══════════════════════════════════════════════════════════════════════════════╗
║  DOCTRINE BRANCH — future                                                    ║
╚══════════════════════════════════════════════════════════════════════════════╝

  Doctrine\Bootstrap::getEntityManager()
  │  persistence/src/Doctrine/Bootstrap.php  (to be created)
  │  → wraps Doctrine\ORM\EntityManager
  │
  DoctrineEntityManager::getRepository(Entity::class, $attr)
  │  persistence/src/Doctrine/DoctrineEntityManager.php  (to be created)
  │  → same convention discovery as FileEntityManager
  │
  DoctrineRepository implements RepositoryInterface
  │  persistence/src/Doctrine/Repository/DoctrineRepository.php  (to be created)
  │  → delegates find/findAll/findBy/findOneBy to Doctrine\ORM\EntityRepository
  │  → save() = persist() + flush()
  │  → delete() = remove() + flush()
  │
  Entity requirements for Doctrine:
  │  - #[Entity('doctrine')]           ← z77 routing attribute
  │  - #[ORM\Entity]                   ← Doctrine mapping attribute
  │  - #[ORM\Column] per property      ← Doctrine column mapping
  │  - ArrayMappable NOT needed        ← Doctrine has own hydration
  │  - Getters/Setters unchanged       ← consumer code identical


╔══════════════════════════════════════════════════════════════════════════════╗
║  MEMORY BRANCH — future (testing / prototype)                                ║
╚══════════════════════════════════════════════════════════════════════════════╝

  Memory\Bootstrap → MemoryEntityManager → MemoryRepository
  - stores entities in PHP array per request
  - no filesystem, no database
  - identical RepositoryInterface
  - primary use: unit tests without infrastructure


╔══════════════════════════════════════════════════════════════════════════════╗
║  RETURN — all branches converge                                              ║
╚══════════════════════════════════════════════════════════════════════════════╝

  UnifiedEntityManager returns RepositoryInterface to consumer
  │  caches: EntityManager per driver (Bootstrap)
  │  caches: Repository per entity class (FileEntityManager / DoctrineEntityManager)
  │
  Consumer receives NavigationRepository (or generic FileRepository as fallback)
  Consumer calls: find() / findAll() / findBy() / findOneBy() / save() / delete()
  Consumer calls domain methods: findByPath() / findByName() / findByNavigationAndLanguage()
  Consumer NEVER knows which driver is active
```

## driver contract

To add a new persistence driver `{Driver}`:

```text
1. packages/kernel/persistence/src/{Driver}/Bootstrap.php
   - constructor: boot driver infrastructure (connection, file path, etc.)
   - getEntityManager(): {Driver}EntityManager

2. packages/kernel/persistence/src/{Driver}/{Driver}EntityManager.php
   - getRepository(string $entityClass, Entity $attr): RepositoryInterface
   - same convention discovery as FileEntityManager (\Entities\{Name} → \Repositories\{Name}Repository)
   - caches repositories per class

3. packages/kernel/persistence/src/{Driver}/Repository/{Driver}Repository.php
   - implements RepositoryInterface
   - find / findAll / findBy / findOneBy / save / delete

4. Register in DI (core/src/Bootstrap.php):
   new DataSourceResolver(['file' => 'File', '{driver}' => '{Driver}'])

5. On entity:
   change #[Entity('file', '...')] to #[Entity('{driver}')]
   add any driver-specific mapping attributes (e.g. #[ORM\Entity] for Doctrine)
```

## rules

- When implementing a new persistence driver → MUST create `Bootstrap`, `EntityManager`, and a generic `Repository` in `Z77\Persistence\{Driver}\`; MUST implement `RepositoryInterface`
- When adding a method to `RepositoryInterface` → MUST verify it is implementable by ALL planned backends (File, Doctrine, Memory); MUST NOT add driver-specific semantics to the shared interface
- When writing entity-specific repository domain methods → MUST use only `RepositoryInterface` methods internally; MUST NOT call driver-specific APIs (no `EntityManager::createQuery`, no direct `FileStorage` access)
- When switching an entity from one backend to another → MUST only change the `#[Entity]` attribute; MUST NOT change consumer code or repository method signatures
- When a complex query requires driver-specific features (DQL, QueryBuilder) → MUST NOT force it through `RepositoryInterface`; implement as a driver-specific method outside the interface and document the deviation
- When obtaining a repository in a service or controller → MUST use `UnifiedEntityManager::getRepository()`; MUST NOT instantiate repositories with `new`
- When creating an entity-specific repository → MUST place it in `{RootNamespace}\Repositories\{EntityName}Repository` (convention used by `FileEntityManager::resolveSpecific()` and all future driver EntityManagers); MUST extend the driver's base repository class (e.g. `FileRepository` for the File driver) — NOT compose it via constructor injection
- When declaring an entity for a non-file backend → MUST omit or leave empty `Entity::$path` (defaults to `''`); `$path` is only meaningful for the File driver and MUST NOT be used by other drivers

## known issues

- **ARCH-A001** — don't assume `findBy` is performant across all backends. File backend loads all records and filters in PHP; Doctrine generates SQL WHERE. Same interface, different complexity. Acceptable for small datasets (< ~5k records); becomes a problem at scale.
- **ARCH-A002** — don't assume Identity Map behaviour. `find(1)` called twice returns two distinct PHP objects on the File backend. In-process state can diverge. Doctrine's Identity Map prevents this; z77 does not implement one.
- **ARCH-A003** — don't attempt to abstract transactions through `RepositoryInterface`. File storage has no rollback; adding `beginTransaction()` to the interface would be semantically dishonest for File backends.
## pending

_(none)_

## see also

- [`persistence-file.md`](persistence-file.md) — File driver: implementation details, entity-specific repo discovery, known file-driver issues and pending
