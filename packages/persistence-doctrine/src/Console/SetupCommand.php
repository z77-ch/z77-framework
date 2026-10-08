<?php

namespace Z77\Persistence\Doctrine\Console;

use Doctrine\DBAL\Schema\Comparator,
    Doctrine\DBAL\Schema\ComparatorConfig,
    Doctrine\DBAL\Schema\Schema,
    Doctrine\DBAL\Schema\Table,
    Doctrine\Migrations\DependencyFactory,
    Doctrine\ORM\EntityManager,
    Doctrine\ORM\Mapping\ManyToManyOwningSideMapping,
    Symfony\Component\Console\Command\Command,
    Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Input\InputOption,
    Symfony\Component\Console\Output\OutputInterface,
    Z77\Core\Libraries\Cache\GeneratedPhpCache
;

/**
 * `z77-db setup` — «change the entity, run setup», locally and on a server
 * over SSH (ADR-039 addendum 2026-10-08). Non-interactive; the schema still
 * changes through migrations only (decisions 12–14), never through
 * `SchemaTool::updateSchema()` against an installation.
 *
 *   1. names the project root and the database (through `migrate`);
 *   2. applies every pending migration — exactly `z77-db migrate`, cache
 *      deletion included;
 *   3. compares the mapping (the EntityManager's announced entities) with the
 *      database, with Doctrine's own machinery — the schema provider, DBAL's
 *      comparator and platform, the same pieces `z77-db diff` runs;
 *   4. nothing left → «Schema up to date», exit 0;
 *   5. groups the statements by OWNER — per table, the entity class mapping
 *      it, and the file that class is loaded from: under `override/` → the
 *      project (`Z77\Project\Migrations`, `override/z77/project/res/migrations`);
 *      otherwise the module whose namespace the class is in (its
 *      `res/migrations`, `MigrationDirectories`). A table no announced entity
 *      maps has no owner — Doctrine proposes to DROP it (DOCTRINE-MIG-001):
 *      refused, nothing written;
 *   6. writes only inside the project root and never under `vendor/`
 *      (realpaths — in a project the framework packages are linked into
 *      vendor/; DOCTRINE-CLI-001). One foreign owner refuses the whole run;
 *   7. writes one migration per owner, `Version{YmdHis}` in UTC like
 *      Doctrine's `generate`, later than every migration already there;
 *   8. shows the SQL; a DROP TABLE or DROP COLUMN is applied only with
 *      `--allow-drop` (the file stays written for review); `--dry-run` writes
 *      and applies nothing;
 *   9. applies through `migrate` and compares again — it must be empty.
 *
 * Foreign keys and indexes belong to the table they are on. The metadata
 * table `schema_migration` is never part of the comparison.
 */
final class SetupCommand extends Command
{
    public const NAME = 'setup';

    /** Order of the groups written in one run: framework first, the project last (it may reference both). */
    private const PROJECT_LAST = PHP_INT_MAX;

    /**
     * @param array<string, string>                                   $directories       namespace → directory (`MigrationDirectories::collect()`)
     * @param array<string, string>                                   $missing           namespace → directory not created yet (`MigrationDirectories::missing()`)
     * @param \Closure(array<string, string>): DependencyFactory      $dependencyFactory a configuration for the given directories
     * @param \Closure(array<string, string>, OutputInterface): int   $migrate           runs `z77-db migrate` over the given directories
     */
    public function __construct(
        private readonly EntityManager $em,
        private readonly array $directories,
        private readonly array $missing,
        private readonly string $projectRoot,
        private readonly GeneratedPhpCache $generatedPhp,
        private readonly \Closure $dependencyFactory,
        private readonly \Closure $migrate
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Apply pending migrations, then write and apply the migrations the mapping still needs (one per owner).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be written; write nothing, apply nothing.')
            ->addOption('allow-drop', null, InputOption::VALUE_NONE, 'Apply a generated DROP TABLE / DROP COLUMN (data loss).')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun    = (bool)$input->getOption('dry-run');
        $allowDrop = (bool)$input->getOption('allow-drop');

        // The mapping must be read as it is NOW: a compiled metadata cache from
        // before the entity changed would hide the change (production mode keeps
        // it until «Cache leeren», the DEBUG toggle or a migrate).
        $this->generatedPhp->clearAll();

        // ── 1 + 2: pending migrations ────────────────────────────────────────
        if ($dryRun) {
            $output->writeln(MigrationsApplication::projectLine($this->em));
            $pending = ($this->dependencyFactory)($this->directories)->getMigrationStatusCalculator()->getNewMigrations()->getItems();
            if ($pending !== []) {
                $output->writeln(sprintf('Dry run: %d pending migration(s) NOT applied — the difference below does not include them:', count($pending)));
                foreach ($pending as $migration) {
                    $output->writeln('  ' . $migration->getVersion());
                }
            }
        } else {
            $code = ($this->migrate)($this->directories, $output);
            if ($code !== 0) {
                $output->writeln("<error>Applying the pending migrations failed (exit {$code}) — setup stops before comparing.</error>");

                return $code;
            }
        }

        // ── 3 + 4: mapping against database ──────────────────────────────────
        [$from, $to, $tables] = $this->difference();
        if ($tables === []) {
            $output->writeln('<info>Schema up to date — the mapping and the database agree.</info>');

            return Command::SUCCESS;
        }

        // ── 5: owner per table ───────────────────────────────────────────────
        $classOf  = $this->tableClasses();
        $groups   = [];
        $unowned  = [];
        foreach ($tables as $table) {
            $class = $classOf[$table] ?? null;
            if ($class === null) {
                $unowned[$table] = 'no announced entity maps it';
                continue;
            }
            $owner = $this->ownerOf($class);
            if ($owner === null) {
                $unowned[$table] = "its entity {$class} is in no module namespace with a migration directory";
                continue;
            }
            [$namespace, $directory, $order] = $owner;
            $groups[$namespace] ??= ['namespace' => $namespace, 'directory' => $directory, 'order' => $order, 'tables' => []];
            $groups[$namespace]['tables'][] = $table;
        }

        if ($unowned !== []) {
            $output->writeln('<error>Refused — nothing written: the database has changes no owner can take.</error>');
            foreach ($unowned as $table => $reason) {
                $output->writeln("  {$table}: {$reason}");
                foreach ($this->sql([$table], $from, $to)[0] as $statement) {
                    $output->writeln("      {$statement}");
                }
            }
            $output->writeln('If such a table is really gone, remove it with a hand-written migration (`z77-db generate '
                . '--namespace=…`) after review; otherwise announce its entity (`doctrineEntities`) — DOCTRINE-MIG-001.');

            return Command::FAILURE;
        }

        // ── 6: write only inside the project, never under vendor/ ────────────
        $foreign = array_filter($groups, fn(array $group): bool => !self::isWritableDirectory($group['directory'], $this->projectRoot));
        if ($foreign !== []) {
            $output->writeln('<error>Refused — nothing written for any owner: a migration would land outside this project or under vendor/.</error>');
            foreach ($foreign as $group) {
                $output->writeln(sprintf('  %s → %s (tables: %s)', $group['namespace'], $group['directory'], implode(', ', $group['tables'])));
            }
            $output->writeln('A framework entity changed but its migration is not shipped — generate it in the framework repo '
                . '(`z77-db setup` there), release the package, update it here (DOCTRINE-CLI-001).');

            return Command::FAILURE;
        }

        // ── 7 + 8: one migration per owner, shown; written unless dry run ────
        uasort($groups, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
        $versions   = $this->nextVersions(count($groups), array_column($groups, 'directory'));
        $files      = [];
        $dataLoss   = [];
        $sqlGen     = ($this->dependencyFactory)($this->directories)->getMigrationSqlGenerator();
        $output->writeln(sprintf('Schema difference — %d table(s), %d migration(s):', count($tables), count($groups)));
        foreach (array_values($groups) as $i => $group) {
            [$up, $down] = $this->sql($group['tables'], $from, $to);
            $version     = $versions[$i];
            $path        = rtrim($group['directory'], '/') . '/' . $version . '.php';
            $files[$path] = self::render($group['namespace'], $version, $group['tables'], $sqlGen->generate($up, false, null, 120, false), $sqlGen->generate($down, false, null, 120, false));
            $output->writeln('');
            $output->writeln(sprintf('  %s\\%s', $group['namespace'], $version));
            $output->writeln(($dryRun ? '  (dry run, not written) ' : '  → ') . $path);
            foreach ($up as $statement) {
                $loss = self::isDataLoss($statement);
                if ($loss) {
                    $dataLoss[] = $statement;
                }
                $output->writeln(($loss ? '    DATA LOSS ' : '      ') . $statement);
            }
        }
        $output->writeln('');

        if ($dryRun) {
            if ($dataLoss !== []) {
                $output->writeln(sprintf('%d statement(s) drop a table or column — a real run writes them and applies them only with --allow-drop.', count($dataLoss)));
            }
            $output->writeln('Dry run: nothing written, nothing applied.');

            return Command::SUCCESS;
        }

        $directories = $this->directories;
        foreach ($groups as $group) {
            if (!is_dir($group['directory']) && !mkdir($group['directory'], 0777, true) && !is_dir($group['directory'])) {
                throw new \RuntimeException("z77-db setup: cannot create {$group['directory']}");
            }
            $directories[$group['namespace']] = $group['directory'];
        }
        foreach ($files as $path => $content) {
            if (file_put_contents($path, $content) === false) {
                throw new \RuntimeException("z77-db setup: cannot write {$path}");
            }
        }

        if ($dataLoss !== [] && !$allowDrop) {
            $output->writeln(sprintf(
                '<error>Written, NOT applied: %d statement(s) drop a table or column (marked DATA LOSS).</error>',
                count($dataLoss)
            ));
            $output->writeln('Review the file(s) — expand/contract, ADR-039 decision 14: drop only what the running release no longer reads — '
                . 'then run `z77-db setup --allow-drop`. They are pending migrations now: delete a file if its drop is wrong, '
                . 'otherwise the next `z77-db migrate` or `setup` applies it.');

            return Command::FAILURE;
        }

        // ── 9: apply, then the difference must be gone ───────────────────────
        $output->writeln('Applying:');
        $code = ($this->migrate)($directories, $output);
        if ($code !== 0) {
            $output->writeln("<error>Applying the generated migration(s) failed (exit {$code}). The file(s) stay for review.</error>");

            return $code;
        }
        [$from, $to, $left] = $this->difference();
        if ($left !== []) {
            $output->writeln('<error>The schema still differs from the mapping after applying — check the generated migration(s):</error>');
            foreach ($this->sql($left, $from, $to)[0] as $statement) {
                $output->writeln("      {$statement}");
            }

            return Command::FAILURE;
        }
        $output->writeln('<info>Schema up to date — the mapping and the database agree.</info>');

        return Command::SUCCESS;
    }

    /**
     * Whether a statement destroys data: DROP TABLE, or an ALTER TABLE that
     * drops a column. Dropping an index, a foreign key or the primary key is
     * not — Doctrine renames those as drop + add.
     */
    public static function isDataLoss(string $sql): bool
    {
        if (preg_match('/^\s*DROP\s+TABLE\b/i', $sql) === 1) {
            return true;
        }
        if (preg_match('/^\s*ALTER\s+TABLE\s+\S+\s+(.*)$/is', $sql, $m) !== 1) {
            return false;
        }

        return preg_match('/(?:^|,)\s*DROP\s+(?!INDEX\b|KEY\b|FOREIGN\s+KEY\b|PRIMARY\s+KEY\b|CONSTRAINT\b|CHECK\b)/i', $m[1]) === 1;
    }

    /**
     * DOCTRINE-CLI-001: a migration may be written only inside the project root
     * and not under its `vendor/` — compared as real paths, so a framework
     * package linked into vendor/ counts as foreign, and in the framework repo
     * `packages/*` counts as its own.
     */
    public static function isWritableDirectory(string $directory, string $projectRoot): bool
    {
        $root = self::resolve($projectRoot);
        $dir  = self::resolve($directory);

        return self::isUnder($dir, $root) && $dir !== $root && !self::isUnder($dir, $root . '/vendor');
    }

    // ── comparison ───────────────────────────────────────────────────────────

    /** @return array{Schema, Schema, list<string>} database, mapping, the tables that differ (lower case) */
    private function difference(): array
    {
        $from   = $this->em->getConnection()->createSchemaManager()->introspectSchema();
        $to     = ($this->dependencyFactory)($this->directories)->getSchemaProvider()->createSchema();
        $diff   = $this->comparator()->compareSchemas($from, $to);
        $tables = [];
        foreach ($diff->getCreatedTables() as $table) {
            $tables[] = self::name($table);
        }
        foreach ($diff->getDroppedTables() as $table) {
            $tables[] = self::name($table);
        }
        foreach ($diff->getAlteredTables() as $tableDiff) {
            $tables[] = self::name($tableDiff->getOldTable());
        }
        $tables = array_values(array_unique(array_diff($tables, [MigrationsApplication::STORAGE_TABLE])));
        sort($tables);

        return [$from, $to, $tables];
    }

    /**
     * Up and down statements for the given tables only: both schemas narrowed
     * to them, compared like `diff` does. A foreign key to a table outside the
     * set is still created — it names the other table, it does not need it.
     *
     * @param list<string> $tables
     * @return array{list<string>, list<string>}
     */
    private function sql(array $tables, Schema $from, Schema $to): array
    {
        $narrow = static fn(Schema $schema): Schema => new Schema(array_values(array_filter(
            $schema->getTables(),
            static fn(Table $table): bool => in_array(self::name($table), $tables, true)
        )));
        $narrowFrom = $narrow($from);
        $narrowTo   = $narrow($to);
        $comparator = $this->comparator();
        $platform   = $this->em->getConnection()->getDatabasePlatform();

        return [
            array_values($platform->getAlterSchemaSQL($comparator->compareSchemas($narrowFrom, $narrowTo))),
            array_values($platform->getAlterSchemaSQL($comparator->compareSchemas($narrowTo, $narrowFrom))),
        ];
    }

    /** As `DiffGenerator` builds it: a modified index is a drop + add. */
    private function comparator(): Comparator
    {
        return $this->em->getConnection()->createSchemaManager()->createComparator(
            (new ComparatorConfig())->withReportModifiedIndexes(false)
        );
    }

    private static function name(Table $table): string
    {
        return strtolower(trim($table->getName(), '`'));
    }

    // ── owners ───────────────────────────────────────────────────────────────

    /** @return array<string, class-string> table (lower case) → the entity class that maps it */
    private function tableClasses(): array
    {
        $classes = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }
            $class = $metadata->isInheritanceTypeSingleTable() ? $metadata->rootEntityName : $metadata->getName();
            $classes[strtolower($metadata->getTableName())] ??= $class;
            foreach ($metadata->associationMappings as $association) {
                if ($association instanceof ManyToManyOwningSideMapping) {
                    $classes[strtolower($association->joinTable->name)] ??= $metadata->getName();
                }
            }
        }

        return $classes;
    }

    /**
     * The owner of an entity class: the project when its file lies under
     * `override/`, otherwise the module (or this package) whose namespace it is
     * in — the longest matching prefix.
     *
     * @return null|array{string, string, int} namespace, directory, write order
     */
    private function ownerOf(string $class): ?array
    {
        $file = (new \ReflectionClass($class))->getFileName();
        if ($file !== false && self::isUnder(self::resolve($file), self::resolve($this->projectRoot) . '/override')) {
            return [MigrationDirectories::PROJECT_NAMESPACE, MigrationDirectories::projectDirectory($this->projectRoot), self::PROJECT_LAST];
        }

        $best  = null;
        $order = 0;
        foreach (array_keys($this->directories + $this->missing) as $i => $namespace) {
            if ($namespace === MigrationDirectories::PROJECT_NAMESPACE || !str_ends_with($namespace, '\\Migrations')) {
                continue;
            }
            $prefix = substr($namespace, 0, -strlen('Migrations'));
            if (str_starts_with($class, $prefix) && ($best === null || strlen($prefix) > strlen($best))) {
                $best  = $prefix;
                $order = $i;
            }
        }
        if ($best === null) {
            return null;
        }
        $namespace = $best . 'Migrations';

        return [$namespace, $this->directories[$namespace] ?? $this->missing[$namespace], $order];
    }

    // ── files ────────────────────────────────────────────────────────────────

    /**
     * `Version{YmdHis}` in UTC (Doctrine's `generate` scheme), one second apart
     * per file in write order, and later than every migration already in the
     * configured directories and the target directories — a version sorting
     * before an applied one would be skipped (DOCTRINE-MIG-002).
     *
     * @param list<string> $targetDirectories
     * @return list<string>
     */
    private function nextVersions(int $count, array $targetDirectories): array
    {
        $utc    = new \DateTimeZone('UTC');
        $latest = null;
        foreach (array_unique(array_merge(array_values($this->directories), $targetDirectories)) as $directory) {
            foreach (glob(rtrim($directory, '/') . '/Version*.php') ?: [] as $file) {
                if (preg_match('/^Version(\d{14})$/', basename($file, '.php'), $m) === 1 && ($latest === null || $m[1] > $latest)) {
                    $latest = $m[1];
                }
            }
        }
        $next = new \DateTimeImmutable('now', $utc);
        if ($latest !== null) {
            $afterLatest = \DateTimeImmutable::createFromFormat('YmdHis', $latest, $utc);
            if ($afterLatest !== false && $afterLatest->modify('+1 second') > $next) {
                $next = $afterLatest->modify('+1 second');
            }
        }
        $versions = [];
        for ($i = 0; $i < $count; $i++) {
            $versions[] = 'Version' . $next->modify("+{$i} seconds")->format('YmdHis');
        }

        return $versions;
    }

    /** @param list<string> $tables */
    private static function render(string $namespace, string $version, array $tables, string $up, string $down): string
    {
        $owner  = $namespace === MigrationDirectories::PROJECT_NAMESPACE ? 'project' : substr($namespace, 0, -strlen('\\Migrations'));
        $indent = static fn(string $code): string => implode("\n", array_map(
            static fn(string $line): string => $line === '' ? '' : '        ' . $line,
            explode("\n", $code)
        ));
        $tableList = implode(', ', $tables);
        $when      = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $upBody    = $up === '' ? '        // nothing' : $indent($up);
        $downBody  = $down === ''
            ? '        // Doctrine proposes no reverse statement for this change — write one by hand if development needs it.'
            : $indent($down);
        $description = var_export("{$owner}: {$tableList} (generated by z77-db setup — review)", true);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Generated by `z77-db setup` on {$when} UTC — review before commit.
 *
 * {$owner}: the difference between the mapping and the database for
 * {$tableList}. Say here WHY the schema changes; keep it expand/contract
 * (ADR-039 decision 14 — nothing the running release still reads is dropped
 * or renamed) and in `utf8mb4_unicode_ci` (decision 18).
 */
final class {$version} extends AbstractMigration
{
    public function getDescription(): string
    {
        return {$description};
    }

    public function up(Schema \$schema): void
    {
{$upBody}
    }

    /** Development only — a release never rolls the schema back (decision 14). */
    public function down(Schema \$schema): void
    {
{$downBody}
    }
}

PHP;
    }

    // ── paths ────────────────────────────────────────────────────────────────

    /** The real path, also for a directory not created yet (its nearest existing ancestor resolved). */
    private static function resolve(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $rest = [];
        while ($path !== '' && !file_exists($path)) {
            $parent = dirname($path);
            if ($parent === $path || $parent === '.') {
                break;
            }
            array_unshift($rest, basename($path));
            $path = $parent;
        }
        $real = realpath($path);
        $real = $real === false ? $path : rtrim(str_replace('\\', '/', $real), '/');

        return $rest === [] ? $real : $real . '/' . implode('/', $rest);
    }

    private static function isUnder(string $path, string $base): bool
    {
        $base = rtrim($base, '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $base = strtolower($base);
        }

        return $path === $base || str_starts_with($path, $base . '/');
    }
}
