<?php

namespace Z77\Persistence\Doctrine\Console;

use Doctrine\DBAL\Connection,
    Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager,
    Doctrine\Migrations\Configuration\Migration\ConfigurationArray,
    Doctrine\Migrations\DependencyFactory,
    Doctrine\Migrations\Tools\Console\Command\DiffCommand,
    Doctrine\Migrations\Tools\Console\Command\GenerateCommand,
    Doctrine\Migrations\Tools\Console\Command\MigrateCommand,
    Doctrine\Migrations\Tools\Console\Command\StatusCommand,
    Doctrine\Migrations\Version\Comparator,
    Doctrine\ORM\EntityManager,
    Symfony\Component\Console\Application,
    Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Output\OutputInterface,
    Z77\Core\Libraries\Cache\GeneratedPhpCache,
    Z77\Persistence\Doctrine\EntityManagerFactory
;

/**
 * The migrations CLI (`bin/z77-db`) — ADR-039 decisions 12 to 14: the schema
 * changes ONLY here, from the command line, never from a web request.
 *
 *   php vendor/bin/z77-db migrate            apply every pending migration
 *   php vendor/bin/z77-db status             what is applied, what is pending, where the migrations are
 *   php vendor/bin/z77-db diff --namespace=… write a migration from the difference between mapping and database
 *   php vendor/bin/z77-db generate --namespace=…   write an empty migration to fill in by hand
 *
 * Doctrine's own commands do the work (`doctrine/migrations` 3.9); this
 * class only configures them the z77 way and adds what the ADR asks for:
 *
 *   - the migration directories come from `MigrationDirectories` — the
 *     package and every module that declares `doctrineEntities`;
 *   - the entity list for `diff` is the EntityManager's — the modules'
 *     `doctrineEntities` plus `EntityManagerFactory::PACKAGE_ENTITIES`, the
 *     same list the driver runs on (decision 5), so a table the driver
 *     writes is a table the diff knows;
 *   - the metadata table (`schema_migration`) is created in the framework's
 *     collation BEFORE Doctrine initialises it — Doctrine would take the
 *     database default, and a hoster's default is not necessarily ours
 *     (decision 18);
 *   - after a successful `migrate` the compiled metadata cache
 *     (`var/cache/doctrine/`) is deleted with OPcache invalidation, as part
 *     of the command (decision 11) — not a step someone must remember;
 *   - `diff` and `generate` always need `--namespace`: Doctrine would take
 *     the first (or only) configured one, which is this package's, and a
 *     module's first migration would land in vendor/; a module whose
 *     `res/migrations` does not exist yet is told to create it;
 *   - migrations run in timestamp order across all namespaces
 *     (`ChronologicalComparator`), not module by module.
 *
 * What Doctrine does on its own and needs no help with: the metadata table
 * is kept out of a generated diff (`SqlGenerator` drops every statement
 * naming it), and a `money` column diffs clean — DBAL 4 compares column
 * DECLARATIONS, and `MoneyType` declares the same `NUMERIC(15, 2)` the
 * database reports (DOCTRINE-TYPE-001, verified in the harness).
 *
 * The SAPI guard is the last line: `bin/z77-db` refuses a non-CLI SAPI
 * before it boots, and this factory refuses again — a web request that
 * somehow reached it must not run a migration.
 */
final class MigrationsApplication
{
    public const NAME = 'z77-db';

    /** The metadata storage — one row per executed migration. */
    public const STORAGE_TABLE = 'schema_migration';

    /**
     * @param array<string, string> $directories namespace → directory (`MigrationDirectories::collect()`)
     * @param array<string, string> $missing     namespace → directory a module with entities would have
     *                                           but has not created yet (`MigrationDirectories::missing()`)
     * @param string                $sapi        the running SAPI — a parameter so the refusal is testable
     */
    public static function create(
        EntityManager $em,
        array $directories,
        GeneratedPhpCache $generatedPhp,
        array $missing = [],
        string $sapi = PHP_SAPI
    ): Application {
        if ($sapi !== 'cli') {
            throw new \RuntimeException(
                self::NAME . " runs from the command line only (ADR-039 decision 12) — got SAPI '{$sapi}'. "
                . 'No schema change from a web request.'
            );
        }
        if ($directories === []) {
            throw new \InvalidArgumentException(self::NAME . ': no migration directory configured.');
        }

        $dependencyFactory = DependencyFactory::fromEntityManager(
            new ConfigurationArray([
                'migrations_paths' => $directories,
                'table_storage'    => ['table_name' => self::STORAGE_TABLE],
            ]),
            new ExistingEntityManager($em)
        );
        // One namespace per module, one order for all of them: by timestamp,
        // not by class name (which would run module by module, alphabetically).
        $dependencyFactory->setDefinition(Comparator::class, static fn(): Comparator => new ChronologicalComparator());

        // `diff` / `generate` never guess where a migration goes. Doctrine would
        // take the first (or only) configured namespace — this package's — and a
        // module's FIRST migration, written before its res/migrations exists,
        // would land in vendor/z77/persistence-doctrine. The package namespace
        // is allowed only when named explicitly.
        $requireNamespace = static function (InputInterface $input, OutputInterface $output) use ($directories, $missing): ?int {
            $namespace = (string)$input->getOption('namespace');
            if ($namespace !== '' && isset($directories[$namespace])) {
                return null;
            }
            if ($namespace === '') {
                $output->writeln('<error>Say where the migration goes: --namespace="{Module}\Migrations". Configured:</error>');
            } elseif (isset($missing[$namespace])) {
                $output->writeln("<error>{$namespace} has no migration directory yet — create {$missing[$namespace]} first.</error>");

                return 1;
            } else {
                $output->writeln("<error>Unknown migration namespace '{$namespace}'. Configured:</error>");
            }
            foreach ($directories as $ns => $directory) {
                $output->writeln("  {$ns}  ({$directory})");
            }
            foreach ($missing as $ns => $directory) {
                $output->writeln("  {$ns}  (create {$directory} first)");
            }

            return 1;
        };

        $storageTableCreated = false;
        $app = new Application(self::NAME);
        $app->addCommand(new WrappedCommand(
            'migrate',
            new MigrateCommand($dependencyFactory),
            before: static function (InputInterface $input, OutputInterface $output) use ($em, &$storageTableCreated): ?int {
                // Which installation is about to change — the guard against
                // migrating the wrong release (`current` instead of `next`).
                $output->writeln(sprintf(
                    'Project: %s — database: %s',
                    defined('ABS_BASE_PATH') ? ABS_BASE_PATH : '(not booted)',
                    (string)$em->getConnection()->getDatabase()
                ));
                $storageTableCreated = self::ensureStorageTable($em->getConnection());

                return null;
            },
            after: static function (InputInterface $input, OutputInterface $output, int $code) use ($em, $generatedPhp, &$storageTableCreated): void {
                if ($code !== 0) {
                    // Cancelled (3) or failed before the first version was recorded:
                    // the table this run created stays only if something used it.
                    if ($storageTableCreated) {
                        self::dropStorageTableIfEmpty($em->getConnection());
                    }

                    return;
                }
                // Doctrine's own reading (ConsoleInputMigratorConfigurationFactory):
                // only --dry-run makes it a dry run; --write-sql still executes.
                if ((bool)$input->getOption('dry-run')) {
                    return;
                }
                $deleted = $generatedPhp->clearAll();
                $output->writeln(sprintf(
                    'Compiled metadata cache deleted: %d file(s) under var/cache/doctrine. This process has no OPcache '
                    . 'of the web server\'s — with opcache.validate_timestamps = 0 run «Cache leeren» in the backend as well '
                    . '(DOCTRINE-CACHE-001).',
                    count($deleted)
                ));
            }
        ));
        $app->addCommand(new WrappedCommand('status', new StatusCommand($dependencyFactory)));
        $app->addCommand(new WrappedCommand('diff', new DiffCommand($dependencyFactory), before: $requireNamespace));
        $app->addCommand(new WrappedCommand('generate', new GenerateCommand($dependencyFactory), before: $requireNamespace));

        return $app;
    }

    /**
     * Creates `schema_migration` in utf8mb4 / utf8mb4_unicode_ci if it does not
     * exist. Doctrine creates it on first use through a bare `new Table()`,
     * which takes the DATABASE default collation; the columns here are exactly
     * what Doctrine expects (`TableMetadataStorageConfiguration` defaults), so
     * its own up-to-date check finds nothing to alter.
     */
    private static function ensureStorageTable(Connection $connection): bool
    {
        if ($connection->createSchemaManager()->tablesExist([self::STORAGE_TABLE])) {
            return false;
        }
        $connection->executeStatement(sprintf(
            'CREATE TABLE %s (version VARCHAR(191) NOT NULL, executed_at DATETIME DEFAULT NULL, execution_time INT DEFAULT NULL, '
            . 'PRIMARY KEY (version)) DEFAULT CHARACTER SET %s COLLATE `%s` ENGINE = InnoDB',
            self::STORAGE_TABLE,
            EntityManagerFactory::CHARSET,
            EntityManagerFactory::COLLATION
        ));

        return true;
    }

    /** The counterpart of {@see ensureStorageTable()} for a run that executed nothing after all. */
    private static function dropStorageTableIfEmpty(Connection $connection): void
    {
        if ((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . self::STORAGE_TABLE) === 0) {
            $connection->executeStatement('DROP TABLE ' . self::STORAGE_TABLE);
        }
    }
}
