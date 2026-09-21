# persistence-doctrine

2026-09-21

## entry

1. `packages/persistence-doctrine/src/Bootstrap.php` — what the driver reads at boot and where from; the lazy entry `UnifiedEntityManager::bootManager()` instantiates
2. `packages/persistence-doctrine/src/EntityManagerFactory.php` — how Doctrine's EntityManager is built: connection, charset, metadata driver, lazy objects, caches, types
3. `tests/persistence-doctrine.php` — the harness against a real MariaDB; every decision of ADR-039 that part 1 implements has a check there
4. `tests/entity-announcement.php` — how `doctrineEntities` is collected, including a project's extension file (no database needed)

## file map

SOURCE=/packages/persistence-doctrine/composer.json
SOURCE=/packages/persistence-doctrine/README.md
SOURCE=/packages/persistence-doctrine/src/Bootstrap.php
SOURCE=/packages/persistence-doctrine/src/EntityManagerFactory.php
SOURCE=/packages/persistence-doctrine/src/DoctrineEntityManager.php
SOURCE=/packages/persistence-doctrine/src/Repository/DoctrineRepository.php
SOURCE=/packages/persistence-doctrine/src/Type/MoneyType.php
SOURCE=/packages/kernel/persistence/src/Resolver/UnifiedEntityManager.php
SOURCE=/packages/kernel/persistence/src/Resolver/RepositoryConvention.php
SOURCE=/packages/kernel/core/src/Bootstrap.php
SOURCE=/packages/kernel/core/src/Services/ModuleManager.php
SOURCE=/packages/kernel/core/src/Libraries/FileFinder.php
SOURCE=/packages/kernel/core/src/Config/database.default.inc.php
SOURCE=/packages/kernel/core/src/Config/systemConfig.default.inc.php
SOURCE=/packages/kernel/core/src/Installer/Install.php
SOURCE=/packages/kernel/shared/src/Money/Money.php
SOURCE=/tests/persistence-doctrine.php
SOURCE=/tests/entity-announcement.php
SOURCE=/docs/02-decisions/adr-039-doctrine-driver-behind-unified-entity-manager.md
SOURCE=/docs/02-decisions/adr-042-ledger-and-money.md
RUNTIME=/skeleton/config/client/database.inc.php

## mental model

`z77/persistence-doctrine` is the second driver behind `UnifiedEntityManager` (ADR-039): Doctrine ORM 3 on DBAL 4 against MariaDB 10.6+, in its own Composer package because the kernel carries no dependencies (ADR-001). The namespace `Z77\Persistence\Doctrine` keeps the resolver's `Z77\Persistence\{Driver}\Bootstrap` convention, so nothing registers itself — the kernel's driver map names `doctrine`, and the first `#[Entity('doctrine')]` of a request boots the driver. A request that touches only file entities never loads the package; an installation without the package runs as before and fails with a message naming the package the moment a Doctrine entity is requested.

- **Connection**: `config/client/database.inc.php` — the one place (decision 4). Seed-once, not fed from `composer.json` (credentials are per installation, the ADR-030 argument). Keys `host`, `port`, `name`, `user`, `password`; empty `name` = no database. The `db` backup reads the same file; `backup.inc.php` keeps only its `dump` block (`mysqldump` binary, optional read-only backup user).
- **Charset / collation**: `utf8mb4` / `utf8mb4_unicode_ci` on every connection (`SET NAMES … COLLATE …` on connect — a bare charset would take the server's default collation) and as default table options for schema tooling and migrations (decision 18). Not configurable.
- **Entities**: a module lists its Doctrine entity classes under `doctrineEntities` in its module config; `ModuleManager::getDoctrineEntities()` collects the union exactly like `importEntities` (a non-existent class throws). The list feeds Doctrine's `AttributeDriver` through a `ClassNames` locator — no directory scanning (decision 5).
- **A project's own entity**: the module config is resolved first-match — an override config replaces the package config as a whole — so a project would have to copy the whole config just to add one class (Rule 2 violation). Instead it places an **extension file** `override/z77/module/{module}/src/App/Config/doctrineEntitiesConfig.inc.php` returning a plain list of classes. `getDoctrineEntities()` is the union of every module's `doctrineEntities` (from its possibly overridden config) and every `App/Config/doctrineEntitiesConfig.inc.php` found under ANY of the module's source paths (`FileFinder::getAllSourceMatches()` — override and package alike), deduplicated. Still an explicit list, still no scanning; a bad class names the file. The same mechanism serves `importEntities` (`importEntitiesConfig.inc.php`) — one code path, `collectEntityClasses()`.
- **Entity shape**: `#[Entity('doctrine')]` (z77, no path) plus Doctrine's `#[ORM\Entity]`, `#[ORM\Table]`, `#[ORM\Column]`. No `ArrayMappable`, no proxies: native lazy objects (PHP 8.4) are switched on, which is why the package requires PHP 8.4 while the kernel stays at 8.2.
- **Repositories**: `DoctrineEntityManager::getRepository()` returns the convention repository (`…\Repositories\XRepository extends DoctrineRepository`) or the generic `DoctrineRepository`; reads are Doctrine's `find` / `findAll` / `findBy` / `findOneBy` with field names as criteria keys, like the File driver. Report SQL runs on the protected `connection()` — the DBAL connection of the SAME EntityManager, so it sees uncommitted writes and uses the one set of credentials (decision 8).
- **Money**: `MoneyType` (`type: 'money'`) maps `Money` ↔ `DECIMAL(15,2)` as a decimal string in both directions; a float on either side is refused. The column carries no currency — every amount is read in the installation's base currency, `systemConfig.inc.php` → `baseCurrency` (default `CHF`), set on the type at boot (ADR-042 decisions 3 and 4) — so a `Money` in any other currency is refused on the way in, and so is an amount beyond ±9999999999999.99 (the column's range), before SQL.
- **Strict `sql_mode`** on every connection (`EntityManagerFactory::SQL_MODE`, MariaDB 10.6's own default pinned per session): an out-of-range DECIMAL or an over-long string is an error, never a silent clamp or truncation.
- **Announced entities only**: `DoctrineEntityManager` refuses `getRepository()`, `persist()` and `remove()` for a class missing from `doctrineEntities`, naming the key — Doctrine's attribute driver would map it on the spot, and the migrations would never see it.
- **Caches** in part 1: an in-memory `ArrayAdapter` for metadata and query cache, rebuilt per request. `EntityManagerFactory::createCache()` is the one method part 3 replaces (`PhpFilesAdapter` under `var/cache/doctrine/`, DEBUG, «Cache leeren», OPcache — decision 11).
- **Schema**: only through migrations from the CLI (decision 12, part 3). The harness creates its throwaway schema with `SchemaTool` — a test-only shortcut, never against an installation.
- **Where the harness runs**: `composer install` in the monorepo root installs Doctrine into the gitignored `vendor/` (the root manifest requires `z77/persistence-doctrine`; the `packages/*` path repository wins over Packagist, so the moving kernel is what gets tested). Credentials come from `%USERPROFILE%\.z77\mariadb.txt` or `Z77_TEST_DB_*` environment variables; the schema `z77test_<random>` is created with `utf8mb4_general_ci` on purpose and dropped at the end.

## rules

- When a module needs a relational entity → MUST mark it `#[Entity('doctrine')]`, MUST add Doctrine's mapping attributes, and MUST list the class under `doctrineEntities` in the module config; MUST NOT rely on a directory being scanned
- When a project adds a Doctrine entity of its own (a new class under `override/z77/module/{module}/src/…`) → MUST announce it in `override/z77/module/{module}/src/App/Config/doctrineEntitiesConfig.inc.php` (`<?php return [MyEntity::class];`); MUST NOT copy the module config into `override/` just to extend `doctrineEntities` (Rule 2 — the file records only the addition). Shadowing an existing entity (same FQCN) needs no announcement
- When reading or writing a Doctrine entity from a service or controller → MUST go through `UnifiedEntityManager` (`getRepository()`, `persist()`, `flush()`, `remove()`); MUST NOT obtain or hold Doctrine's `EntityManager` (ADR-039 decision 6)
- When a repository needs SQL (reports, aggregates) → MUST extend `DoctrineRepository`, run it on `$this->connection()` and mark the method Doctrine-only in its docblock; MUST NOT open a second connection or read the credentials elsewhere (decision 8, Rule 2)
- When mapping an amount → MUST use `#[ORM\Column(type: MoneyType::NAME)]` on a `Money` property; MUST NOT map money as `decimal` (string), `float` or `integer` (ADR-042 decision 3)
- When touching the connection parameters → MUST keep `utf8mb4` / `utf8mb4_unicode_ci` on the connection AND in `defaultTableOptions`; MUST NOT make charset or collation configurable (decision 18)
- When the `db` backup or any other reader needs the database → MUST read `config/client/database.inc.php` through the split lookup (`ConfigManager` → `config/database`, or `ConfigLocator::path()` with an explicit root like `BackupService::readConfig()`); MUST NOT copy host, name or credentials into another config (decision 4)
- When an entity carries an amount in a currency other than the base currency (a foreign-currency document) → MUST NOT map it with `MoneyType`; the type refuses it, and the mapping for such amounts is undecided (see pending)
- When calling `reorder()` → MUST NOT do so for a Doctrine entity; sort order is a mapped column there (decision 9)
- When writing a test that needs the driver → MUST run it against a throwaway schema per run in `tests/persistence-doctrine.php` style (decision 16); MUST NOT point a test at an installation's database, and MUST NOT write a password into any repository file

## known issues

- **DOCTRINE-CFG-001** — don't assume a `database` block in `config/backup.inc.php` still works: `BackupService::fromProjectRoot()` refuses it with a message pointing at `config/client/database.inc.php` (ADR-039 consequence). No installation had one when the block moved (2026-09-21); an existing seed-once file with `'database' => null` is fine.
- **DOCTRINE-CUR-001** — don't assume a `Money` column knows its currency: it is read in `systemConfig.baseCurrency` (default `CHF`) regardless of what wrote it, which is why `MoneyType` refuses to store any other currency. A document in a foreign currency keeps its own currency and rate fields; the ledger posts converted (ADR-042 decision 4).
- **DOCTRINE-TYPE-001** — don't assume a schema diff treats a `money` column as unchanged: DBAL 4 introspects it as `decimal`, and whether the migrations diff (part 3) reports a type change on every run is not yet verified.

## pending

- Mapping for foreign-currency document amounts (plan §6.2: a document keeps currency and rate) — `MoneyType` covers the base currency only; whether those columns become minor units + a currency column, or something else, is undecided and not designed here.
- Part 2: transaction port (decision 10), `NumberRange`, open-work check registry.
- Part 3: production caches (`PhpFilesAdapter` under `var/cache/doctrine/`, DEBUG, «Cache leeren» + `opcache_invalidate`), migrations command; verify DOCTRINE-TYPE-001 there.
- Publishing: the package is not a split target yet (`.github/workflows/split.yml`, repo `z77-ch/persistence-doctrine`, Packagist) — owner's step when the package is ready to publish.

## see also

- [`persistence-architecture.md`](persistence-architecture.md) — the shared API both drivers sit behind, the driver differences as known issues
- [`persistence-file.md`](persistence-file.md) — the File driver, whose conventions this driver mirrors
- [`money.md`](money.md) — the value object `MoneyType` maps
- [`backup.md`](backup.md) — the `db` backup reads the same connection config
- [`installer.md`](installer.md) — `database.inc.php` is seeded there (seed-once)
