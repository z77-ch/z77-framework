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

## Current scope (part 1 of 3)

- **`Bootstrap`** — found by the kernel's `Z77\Persistence\{Driver}\Bootstrap` convention; boots on
  the first Doctrine entity of a request, never on a file-only request.
- **`DoctrineEntityManager`** — the kernel's `EntityManagerInterface` over Doctrine's EntityManager.
  `reorder()` is refused: it is a File-driver concept.
- **`Repository\DoctrineRepository`** — `find` / `findAll` / `findBy` / `findOneBy`. An entity-specific
  repository `{Module}\Repositories\{Entity}Repository` extends it and may run report SQL on the
  protected DBAL `connection()` of the same EntityManager (a documented, driver-specific deviation).
- **`Type\MoneyType`** — `Money` ↔ `DECIMAL(15,2)` as a decimal string, never through float
  (ADR-042). Read in the installation's base currency.
- **`EntityManagerFactory`** — connection (`utf8mb4` / `utf8mb4_unicode_ci` throughout), attribute
  metadata over the modules' explicit `doctrineEntities` lists, native lazy objects, caches.

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

## Planned (parts 2 and 3)

- Transaction port, `NumberRange` (gapless numbering under a row lock), open-work check registry.
- Production caches under `var/cache/doctrine/` (DEBUG switch, «Cache leeren», OPcache), the
  migrations command.

## Getting started

Don't start a project with `composer require`. Use the
[z77-skeleton](https://github.com/z77-ch/z77-skeleton) template — **Use this template** →
`composer install`. `composer require z77/persistence-doctrine` then adds this driver to an
**existing** z77 project; the business modules that need it require it themselves.
