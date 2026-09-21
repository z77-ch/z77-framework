# ADR-039 — The Doctrine driver: own package, reached through `UnifiedEntityManager`

**Status:** `[PROPOSED]` — draft 2026-09-21, awaiting the owner's approval (P0 of
[`order-debtor-financial-bauplan.md`](../03-development/order-debtor-financial-bauplan.md), ADR 2 in §10)
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

## Decision

1. **Own package `z77/persistence-doctrine`, namespace `Z77\Persistence\Doctrine`.** The namespace
   keeps `UnifiedEntityManager::bootManager()`'s convention (`Z77\Persistence\{Driver}\Bootstrap`), so
   the kernel needs no knowledge of the package. The kernel stub `persistence/src/Doctrine/` is
   removed. The package requires `php >=8.4`; the kernel stays at `>=8.2`. Dependencies:
   `doctrine/orm ^3.7`, `doctrine/dbal ^4.4`, `doctrine/migrations ^3.9`, `symfony/cache ^8.1`.
2. **Lazy.** The EntityManager boots on the first `getRepository()` of a Doctrine entity, never on a
   request that touches only files (ADR-001). An installation without the package runs as today.
3. **One consumer API.** Business modules obtain every repository through
   `UnifiedEntityManager::getRepository()`, File or Doctrine alike. Entity-specific repositories live
   at `{Module}\Repositories\{Entity}Repository` and extend `DoctrineRepository`, as file repositories
   extend `FileRepository`. No consumer holds a Doctrine `EntityManager`.
4. **Reports on DBAL of the same driver.** Complex reads — balance sheet, income statement, VAT
   return, aged receivables — are SQL on the **DBAL connection of the same EntityManager**, exposed to
   the entity-specific repository only (a protected accessor on `DoctrineRepository`), never to a
   service or controller. They are driver-specific methods outside `RepositoryInterface`, marked as
   such, which is what the topic doc's deviation rule already provides for. **No second connection**:
   it would duplicate the credentials (Rule 2) and would not see the uncommitted writes of the first.
5. **A minimal transaction port**, outside `RepositoryInterface`:
   - Obtained like a repository — through `UnifiedEntityManager`, resolved from an entity class — so
     the backend stays a property of `#[Entity]` here as well.
   - One operation: run a unit of work atomically; commit on return, roll back and rethrow on any
     exception.
   - Fulfilled by the Doctrine driver only. The File driver **refuses** it with an exception instead
     of pretending — the honesty ARCH-A003 asks for.
   - **No retry, no sleep, no persister class.** A double trigger (a batch started twice) is solved by
     idempotency — a repeated call finds its work done and does nothing (plan §4b, §7) — not by
     repeating the transaction.
   - It is needed only where DBAL SQL and ORM writes must be atomic together, or where one use case
     flushes more than once. A single `flush()` is already one transaction in Doctrine and needs no
     port.
6. **Generated files and caches are disposable runtime state** (ADR-034/035):
   - **Native lazy objects** (PHP 8.4, `enableNativeLazyObjects(true)`): no proxy classes, no proxy
     directory. ORM 4 makes this the only way.
   - **Metadata and query cache** in production: `PhpFilesAdapter` under the release-local
     `var/cache/doctrine/`. Files, not APCu, because web and CLI (cron, migrations) do not share an
     APCu pool (CACHE-CLI-001).
   - In **DEBUG** (`var/state/debug.flag`): an in-memory pool, rebuilt on every request, so an entity
     change is visible without a manual step.
   - **«Cache leeren»** (`SystemController::clearCacheAction()`) and toggling DEBUG remove
     `var/cache/doctrine/`. The backend deletes a directory and needs no dependency on Doctrine.
   - Result cache, hydration cache and second-level cache are **not used** (the latter is marked
     experimental by Doctrine).
7. **Schema only through migrations**, run from the CLI. No schema update from a web request, no
   `orm:schema-tool:update` against a live installation.
8. **Shared building blocks live once in this package** (Rule 8, plan §2): `NumberRange` (gapless,
   row-locked, uses the transaction port) and the open-work check registry.

## Reasoning

- **One API keeps the mixed module simple.** A debtor service reads payment terms from a file and
  writes an open item to the database with the same two calls. Moving an entity between backends stays
  a change to `#[Entity]`, and the Memory driver (tests without a database) stays possible.
- **The deviation rule already existed.** The topic doc never claimed every query fits the interface;
  it says a complex query becomes a documented driver-specific method. Reports are exactly that case,
  so they are no argument for binding to Doctrine everywhere.
- **The transaction port extends the architecture instead of contradicting it.** ARCH-A003 forbids a
  transaction in the *shared* interface because the File driver cannot honour it. A separate port that
  only the Doctrine driver fulfils keeps that honesty and still gives the plan its atomic writes.
- **The retry loop of wdv is not a model.** wdv-6.2.2's `EntityManager::transactional($persister)`
  retried five times with `sleep(1)` on deadlocks and unique-key violations. It was written against a
  batch that fired twice — a unique-key violation there was the symptom of doing the work twice, and
  retrying hid it. It is not called anywhere in wdv today.
- **Caches follow the existing runtime-state model.** `var/cache` is release-local and may be deleted at
  any moment; DEBUG already bypasses every other cache. Doctrine's caches get no special treatment —
  which is what the developer had to do by hand in wdv (`setup.php` deleted them).
- **Own package for ADR-001, not for persistence.** The separation is about dependencies only; the
  driver itself follows the driver contract in the topic doc.

## Consequences

- `persistence-architecture.md` is **extended**, not corrected: the Doctrine branch in the flow, the
  transaction port, the DBAL accessor, the rule where report SQL may live. ARCH-A003 is reworded to
  «no transaction in the *shared* interface». ARCH-A002 gains «the Doctrine driver has an Identity Map;
  the File driver still has none — code MUST NOT rely on either behaviour». The «NOT implemented» line
  of the mental model is updated accordingly.
- `conventions.md` → «Database» gets its SQL conventions (schema, migrations, money columns) in P1,
  as the section announces.
- `DataSourceResolver`'s driver map gains `doctrine` without the kernel requiring the package; how the
  package registers itself is settled in P1.
- Doctrine needs the paths of all entity directories; how installed modules announce theirs is settled
  in P1 (convention over configuration).
- The connection's credentials live in exactly one place of the project's config (Rule 2); the exact
  key is settled in P1.
- `SystemController::clearCacheAction()` and `toggleDebugAction()` delete `var/cache/doctrine/`.
- Every machine and server running the package needs PHP 8.4+ and `pdo_mysql`. The maintainer machine
  runs 8.5 since 2026-09-21.
- Entity classes of the business modules carry Doctrine mapping attributes (`#[ORM\Entity]`,
  `#[ORM\Column]`) next to z77's `#[Entity('doctrine')]`; these modules depend on the package.

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
