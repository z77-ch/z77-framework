# ADR-039 — The Doctrine driver: own package, reached through `UnifiedEntityManager`

**Status:** `[APPROVED]` — approved by the owner 2026-09-21 (P0 of [`order-debtor-financial-bauplan.md`](../03-development/order-debtor-financial-bauplan.md))
**Date:** 2026-09-21

---

## Context

The business modules of the order/debtor/financial plan (order, debtor, financial, article, contact)
need a relational database: gapless numbering under concurrency, a journal that must balance, reports
that aggregate thousands of postings. The File driver cannot carry that. The framework has always
designed for a Doctrine driver —
[`persistence-architecture.md`](../topics/persistence-architecture.md) describes it, and an empty
`packages/kernel/persistence/src/Doctrine/` stub waits for it — but never built one.

The first draft of this ADR (plan §10, before 2026-09-21) let the business modules bind to Doctrine's
EntityManager directly, bypassing the unified API. That was reopened on 2026-09-20 and decided the
other way on 2026-09-21: the topic doc's promise of **one consumer API** was well considered, and the
plan bends to it.

Two facts shaped the decision:

- **The mixed case is the normal case.** `module-debtor` keeps `Invoice`, `OpenItem` and `Payment` in
  the database and `PaymentTerms`, `DunningLevel` and `PaymentTarget` as files — same module, often the
  same service. With one access path, the backend stays a property of `#[Entity]` and not of the
  calling code.
- **Some things cannot be unified**, and the topic doc already says so: transactions (ARCH-A003) and
  the Identity Map (ARCH-A002). The plan needs a transaction in a handful of places, one of them
  unavoidable — the `NumberRange` row lock, which holds only inside an open transaction.

The kernel carries no Composer dependency (ADR-001). Doctrine ORM, DBAL, migrations and a PSR-6 cache
bring about fifteen.

How the code works today, and what this ADR builds on: `RepositoryInterface` carries **reads only**
(`find`, `findAll`, `findBy`, `findOneBy`); writes go through `UnifiedEntityManager::persist()`,
`flush()`, `remove()` and `reorder()`, and `flush()` flushes **every booted driver in turn**
(`Resolver/UnifiedEntityManager.php`).

## Decision

### Package and boot

1. **Own package `z77/persistence-doctrine`, namespace `Z77\Persistence\Doctrine`.** The namespace
   keeps `UnifiedEntityManager::bootManager()`'s convention (`Z77\Persistence\{Driver}\Bootstrap`). The
   kernel stub `persistence/src/Doctrine/` is removed. The package requires `php >=8.4`; the kernel
   stays at `>=8.2`. Dependencies: `doctrine/orm ^3.7`, `doctrine/dbal ^4.4`,
   `doctrine/migrations ^3.9`, `symfony/cache ^8.1`.
2. **The kernel's driver map names `doctrine` from the start** (`core/src/Bootstrap.php`, today
   `['file' => 'File']`). A name in a map is no dependency: without the package, the first Doctrine
   entity fails at boot with a clear message; with it, nothing has to register itself.
3. **Lazy.** The EntityManager boots on the first access to a Doctrine entity, never on a request that
   touches only files (ADR-001). An installation without the package runs as today.
4. **One connection config, `config/client/database.inc.php`** (owner decision 2026-09-21). Doctrine
   and the `db` backup (`BackupService`, today `backup.inc.php` → `database`) both read it; the backup
   config keeps only what is its own (the `mysqldump` binary) and may record a separate, read-only
   backup user as a deviation — never a second copy of host and database name (Rule 2).
5. **Modules announce their Doctrine entities** under a module config key `doctrineEntities`, a list of
   classes, collected by `ModuleManager` exactly like `importEntities` (a non-existent class fails
   loudly). The list feeds Doctrine's metadata driver and the migrations; no directory scanning.
   *Note (P1, 2026-09-21):* a project adds an entity of its own without copying the module config
   through `override/…/App/Config/doctrineEntitiesConfig.inc.php`, an additive list — see
   [`persistence-doctrine.md`](../topics/persistence-doctrine.md).

### Access

6. **One consumer API.** Services and controllers obtain every repository through
   `UnifiedEntityManager::getRepository()` and write through its `persist()` / `flush()` /
   `remove()`, File or Doctrine alike. No consumer holds a Doctrine `EntityManager`. Entity-specific
   repositories live at `{Module}\Repositories\{Entity}Repository` and extend `DoctrineRepository`, as
   file repositories extend `FileRepository`.
7. **What «switching backend» honestly means.** For an entity with the generic repository, switching is
   a change to `#[Entity]`. For an entity with a specific repository, it is `#[Entity]` **plus** the
   repository's parent class — that was already true for the File driver. A repository with report
   methods (below) is Doctrine-only by design.
8. **Reports on DBAL of the same driver.** Complex reads — balance sheet, income statement, VAT
   return, aged receivables — are SQL on the **DBAL connection of the same EntityManager**, reachable
   only inside the entity-specific repository (a protected accessor on `DoctrineRepository`), never
   from a service or controller. They are driver-specific methods outside `RepositoryInterface`,
   marked as such — the topic doc's deviation rule. **No second connection**: it would duplicate the
   credentials (Rule 2) and would not see the uncommitted writes of the first.

### Behaviour the two drivers do not share

9. These differences are real and the consumer code MUST NOT depend on either side of them:
   - **Flush scope.** Doctrine writes every *managed* entity that changed, even one never passed to
     `persist()`; File writes only what was `persist()`ed. Rule: call `persist()` for everything that is
     meant to be written, and never mutate a loaded entity that is not.
   - **`remove()` timing.** File deletes at once; Doctrine at the next `flush()`.
   - **Identity Map.** Doctrine returns the same object for the same row within a request; File does
     not.
   - **`reorder()`** is File-only (sort order in a JSON file); the Doctrine driver refuses it.
   - **One `flush()` across drivers is not atomic.** It writes the File driver and then the Doctrine
     transaction (or the other way round, by boot order). A use case that must be atomic writes to one
     driver only; file-based master data is read, not written, inside such a use case.

### Transactions

10. **A minimal transaction port**, outside `RepositoryInterface`:
    - Obtained through `UnifiedEntityManager`, resolved from an entity class, so the backend stays a
      property of `#[Entity]`. The File driver **refuses** it with an exception instead of pretending
      (ARCH-A003).
    - One operation: run a unit of work atomically — commit on return, roll back and rethrow on any
      exception.
    - **Nesting joins.** A port call inside an open transaction runs in it; there is no inner commit,
      and an exception anywhere rolls back the whole. Whether DBAL 4.4 uses savepoints for this is
      verified in P1 before anything relies on it.
    - The port answers **whether a transaction is open**, so a service like `LedgerService::post()`
      can assert that its caller owns one (plan §5.4) instead of relying on a convention.
    - **After a rollback the driver replaces the closed EntityManager**, so the same request can still
      read (error page, flash message). Entities loaded before the rollback are detached and MUST NOT
      be written again.
    - **No retry, no sleep, no persister class.** A double trigger is solved by idempotency (plan §4b,
      §7). A deadlock surfaces as an error; the fix is lock order — `NumberRange` is always locked
      first.
    - Inside the unit of work, only Doctrine writes are atomic. A File write in it happens at once and
      is **not** rolled back (see 9).
    - A single `flush()` is one Doctrine transaction and needs no port. The port is for DBAL SQL and
      ORM writes that must be atomic together (`NumberRange`), and for a use case that flushes more than
      once.

### Caches and generated files

11. Disposable runtime state under the release-local `var/cache` (ADR-034/035):
    - **Native lazy objects** (PHP 8.4, `enableNativeLazyObjects(true)`): no proxy classes, no proxy
      directory. ORM 4 makes this the only way.
    - **Metadata and query cache** in production: `PhpFilesAdapter` under `var/cache/doctrine/`. Files,
      not APCu, because web and CLI do not share an APCu pool (CACHE-CLI-001).
    - In **DEBUG** (`var/state/debug.flag`): an in-memory pool, rebuilt on every request, so an entity
      change is visible without a manual step.
    - **«Cache leeren»** (`SystemController::clearCacheAction()`) and toggling DEBUG delete
      `var/cache/doctrine/` and call `opcache_invalidate($file, true)` for every deleted file — the
      cache files are `include`d and would otherwise stay in OPcache when `validate_timestamps` is off.
      Plain PHP; the backend needs no dependency on Doctrine. No `opcache_reset()` (shared hosting).
    - After `migrations:migrate` on the CLI, the same deletion runs — as part of the migrate command,
      not as a step someone must remember.
    - Result cache, hydration cache and second-level cache are **not used** (the latter is marked
      experimental by Doctrine).

### Schema

12. **Schema only through migrations, run from the CLI.** No schema update from a web request, no
    `orm:schema-tool:update` against a live installation.
13. **Each module owns its migrations** (`res/migrations/`, namespace `{Module}\Migrations`); the
    migrate command collects them from the modules that declare `doctrineEntities`.
14. **Every migration is expand/contract.** `current` and `next` are two releases on **one** database
    (ADR-035): a migration run for `next` must leave `current` working. Add first, switch, remove in a
    later release — never rename or drop a column the running release still reads.
15. **Shared building blocks live once in this package** (Rule 8, plan §2): `NumberRange` (gapless,
    row-locked, uses the transaction port) and the open-work check registry.

### Tests

16. Driver behaviour that needs MariaDB — the row lock, the transaction port, nesting, rollback — is
    tested against a **real database** in the `tests/*.php` harness, with a throwaway schema per run.
    The Memory driver named in the topic doc does not exist in code; it is not a prerequisite and is
    not promised here.

### Database engine (added 2026-09-21, plan Q8)

17. **MariaDB 10.6 is the minimum**, InnoDB, as on every installation's host. DBAL 4.4 and ORM 3.7
    are verified against it in P1 — the maintainer machine runs MariaDB 10.6.28 for that.
18. **One charset and one collation throughout: `utf8mb4` / `utf8mb4_unicode_ci`** (owner decision
    2026-09-21, the collation the existing databases are set up with). Every table and every string
    column of every module uses it; the connection sets it, and migrations never name another. A
    join across two collations fails outright, so a table with a different collation found during
    the wdv migration is converted, not joined as it is (plan §8).

## Reasoning

- **One API keeps the mixed module simple.** A debtor service reads payment terms from a file and
  writes an open item to the database through the same entry point.
- **The deviation rule already existed.** The topic doc never claimed every query fits the interface;
  a complex query becomes a documented driver-specific method. Reports are exactly that case.
- **The transaction port extends the architecture instead of contradicting it.** ARCH-A003 forbids a
  transaction in the *shared* interface because the File driver cannot honour it. A separate port that
  only the Doctrine driver fulfils keeps that honesty.
- **Naming the differences is cheaper than discovering them.** Flush scope, remove timing and
  cross-driver flush would each surface as data written on one driver and not the other — in
  production, not in a test.
- **The retry loop of wdv is not a model.** wdv-6.2.2's `EntityManager::transactional($persister)`
  retried five times with `sleep(1)` on deadlocks and unique-key violations. It was written against a
  batch that fired twice — the violation was the symptom of doing the work twice, and retrying hid it.
  It is not called anywhere in wdv today.
- **Caches follow the existing runtime-state model.** `var/cache` is release-local and may be deleted
  at any moment; DEBUG already bypasses every other cache. In wdv the developer deleted Doctrine's
  files by hand (`setup.php`); here the framework does it.
- **Deciding the driver map, the connection file and the entity announcement now** costs a line each;
  deferring them invites a second copy of the credentials and a directory scan nobody chose.

## Consequences

- `persistence-architecture.md` is **extended**, not corrected — and three existing statements are
  fixed on the way: line 32 still lists `persist`/`flush`/`delete` on `RepositoryInterface`; the rule
  «only change `#[Entity]`» contradicts the rule that specific repositories extend a driver base class
  (decision 7); the rule «domain methods MUST NOT call driver-specific APIs» gets «except as a
  documented deviation». ARCH-A003 becomes «no transaction in the *shared* interface», ARCH-A002 names
  both behaviours, and the driver differences of decision 9 become known issues.
- `backup.inc.php` loses its `database` block to `config/client/database.inc.php`; existing
  installations with a `db` backup are migrated by hand (none known today).
- `ModuleManager` gains `getDoctrineEntities()` next to `getImportEntities()`.
- `SystemController::clearCacheAction()` and `toggleDebugAction()` delete `var/cache/doctrine/` with
  `opcache_invalidate()`.
- Every machine and server running the package needs PHP 8.4+ and `pdo_mysql`. The maintainer machine
  runs 8.5 since 2026-09-21.
- Entity classes of the business modules carry Doctrine mapping attributes (`#[ORM\Entity]`,
  `#[ORM\Column]`) next to z77's `#[Entity('doctrine')]`; these modules depend on the package.
- Money columns and the `DECIMAL` ↔ minor-units mapping are decided in [ADR-042](adr-042-ledger-and-money.md)
  (decision 3), not here. `conventions.md` → «Database» gets the SQL conventions in P1.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Business modules bind to Doctrine's EntityManager directly (first draft) | Two access paths inside one module; the backend leaks into calling code; the topic doc's promise breaks for the very modules it was written for |
| Driver inside the kernel (`persistence/src/Doctrine/`) | The kernel would carry about fifteen Composer dependencies, against ADR-001 |
| A third connection (own PDO) for reports | Credentials twice (Rule 2); does not see uncommitted writes of the ORM connection; DBAL is already there |
| `beginTransaction()` / `commit()` on `RepositoryInterface` | Dishonest for the File driver (ARCH-A003) |
| Retry loop in the transaction (wdv `transactional()`) | Hides double work instead of preventing it; idempotency is the fix |
| APCu for Doctrine's caches | Web and CLI do not share an APCu pool (CACHE-CLI-001); a migration from the CLI would leave stale metadata in the web pool |
| Generated proxy classes | Not needed on PHP 8.4 with native lazy objects; deprecated since ORM 3.5, removed in ORM 4 |
| Connection config inside the Doctrine package or a second key next to `backup.inc.php` → `database` | Two copies of the credentials (Rule 2) |
| Entity directories scanned by convention | Picks up whatever lies in the directory; the `importEntities` precedent is explicit and fails loudly |
| Deleting only the cache directory, without OPcache invalidation | Stale metadata served until OPcache revalidates — never, with `validate_timestamps = 0` |
