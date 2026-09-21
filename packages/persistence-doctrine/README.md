# z77/persistence-doctrine

The Doctrine driver for the z77 persistence layer (ADR-039). Relational entities on
MariaDB, reached through the **same** `UnifiedEntityManager::getRepository()` / `persist()` /
`flush()` / `remove()` as file-backed ones — the backend is a property of `#[Entity]`, not of
the calling code. Depends on `z77/kernel`, Doctrine ORM 3 / DBAL 4 / Migrations 3 and
`symfony/cache`; requires PHP 8.4 (native lazy objects) and `pdo_mysql`.
Read-only split from [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework). Do not commit here.

Why a separate package: the kernel carries no Composer dependency (ADR-001); Doctrine brings
about fifteen. An installation without this package runs exactly as before — the kernel's driver
map names `doctrine`, and only the first Doctrine entity boots the driver.

## Scope

- **`Bootstrap`** — found by the kernel's `Z77\Persistence\{Driver}\Bootstrap` convention; boots on
  the first Doctrine entity of a request, never on a file-only request.
- **`DoctrineEntityManager`** — the kernel's `EntityManagerInterface` over Doctrine's EntityManager.
  `reorder()` is refused: it is a File-driver concept. After a rollback the Doctrine EntityManager is
  replaced (`EntityManagerHolder`), so the request can still read; entities loaded before are detached.
- **`Transaction\DoctrineTransaction`** — the kernel's `TransactionInterface`
  (`UnifiedEntityManager::getTransaction(Entity::class)`): `run(callable)` commits on return, rolls
  back and rethrows on exception; nesting joins, an exception anywhere rolls back the whole (a
  swallowed inner failure ends in `TransactionRolledBackException`); `isOpen()`. The File driver
  refuses the port.
- **`Entities\NumberRange` / `Repositories\NumberRangeRepository`** — gapless numbering:
  `getRepository(NumberRange::class)->next('invoice')` under `SELECT … FOR UPDATE`, inside the caller's
  transaction only, one bare integer sequence per name; a rolled-back unit of work consumes no number.
  `create('journal-entry.2027')` creates a range ahead of concurrent use without consuming a number.
- **`OpenWork\OpenWorkChecks`** — the open-work check registry: modules declare checks under
  `openWorkChecks` → `{scope}` in their config, a caller asks `ask($scope, $parameters)` and gets
  blocking and warning findings (period close, stocktake block). A project adds its own checks in
  `override/z77/module/{module}/src/App/Config/openWorkChecksConfig.inc.php` (same shape), without
  copying the module config.
- **`Repository\DoctrineRepository`** — `find` / `findAll` / `findBy` / `findOneBy`. An entity-specific
  repository `{Module}\Repositories\{Entity}Repository` extends it and may run report SQL on the
  protected DBAL `connection()` of the same EntityManager (a documented, driver-specific deviation).
- **`Type\MoneyType`** — `Money` ↔ `DECIMAL(15,2)` as a decimal string, never through float
  (ADR-042). Read in the installation's base currency.
- **`EntityManagerFactory`** — connection (`utf8mb4` / `utf8mb4_unicode_ci` throughout), attribute
  metadata over the modules' explicit `doctrineEntities` lists, native lazy objects, caches:
  metadata and query cache compiled to PHP files under the release-local `var/cache/doctrine/`
  (in memory when DEBUG is on). «Cache leeren» and the DEBUG toggle in the backend delete the
  directory and invalidate every file in OPcache — through the kernel's `GeneratedPhpCache`, so
  the backend needs no Doctrine.
- **`bin/z77-db`** — the migrations CLI (`doctrine/migrations`): `migrate`, `status`, `diff`,
  `generate`. Migrations are collected from this package (`res/migrations`, first one:
  `number_range`) and from every module that declares `doctrineEntities` (`res/migrations/`,
  namespace `{Module}\Migrations`); a successful `migrate` clears the compiled cache. The schema
  changes here and nowhere else — never from a web request.

Connection: `config/client/database.inc.php` — the one place, shared with the `db` backup.
Entities: a module lists them under `doctrineEntities` in its config, exactly like `importEntities`.

```php
#[Entity('doctrine')]                 // z77 routing attribute — which driver
#[ORM\Entity, ORM\Table(name: 'journal_line')]
class JournalLine
{
    #[ORM\Column(type: MoneyType::NAME)]
    private Money $debit;
}
```

```text
cd /path/to/project
php vendor/bin/z77-db status
php vendor/bin/z77-db diff --namespace="Z77\Module\Financial\Migrations"   # developer: write the migration
php vendor/bin/z77-db migrate                                              # on the release about to go live
```

## Getting started

Don't start a project with `composer require`. Use the
[z77-skeleton](https://github.com/z77-ch/z77-skeleton) template — **Use this template** →
`composer install`. `composer require z77/persistence-doctrine` then adds this driver to an
**existing** z77 project; the business modules that need it require it themselves.
