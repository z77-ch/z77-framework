<?php

/**
 * module-financial harness (CLI) — P2 part 1: the chart of accounts and the
 * fiscal years with their monthly periods, against a REAL MariaDB (ADR-039
 * decision 16), throwaway schema per run, created by the module's own
 * MIGRATION through the `z77-db` application (never `SchemaTool`).
 *
 * What is load-bearing here:
 *
 *   - `migrate` on an empty database creates `account`, `fiscal_year` and
 *     `fiscal_period` in `utf8mb4_unicode_ci` / InnoDB, and `diff` reports
 *     NO change afterwards — the mapping and the migration agree;
 *   - «KMU-Kontenrahmen übernehmen» works only on an EMPTY chart (owner,
 *     2026-09-22) — one flush — and the shipped chart is complete and
 *     consistent: classes 1–9, every postable account under a chain of
 *     groups up to its class, types plausible per class;
 *   - account validation: digits-only unique number, name, one of the five
 *     types, the parent is a group, no cycle, a group with children stays a
 *     group, the number is immutable, deactivate — never delete; a refused
 *     change never reaches the next flush (ADR-039 decision 9);
 *   - fiscal years: contiguity, at most 24 months, code format and
 *     uniqueness; the monthly periods of a calendar year, a deviating year
 *     (1.7.–30.6.) and a partial first year (15.3.–31.12.), all `open`;
 *     the range `journal-entry.{code}` created at 0 by the opening, and a
 *     rolled-back opening leaves nothing behind — neither year, periods nor
 *     range; a race on the start date becomes a field error.
 *
 * Run: php tests/module-financial.php
 * Needs what tests/module-contact.php needs (vendor/ with Doctrine, a
 * reachable MariaDB, credentials in `%USERPROFILE%\.z77\mariadb.txt` or
 * Z77_TEST_DB_*). Nothing is written into the repository; the schema
 * `z77test_<random>` and the temp installation are removed at the end.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "module-financial: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

// ── worker mode: parallel processes for the concurrency checks ──────────────
// Re-invoked by the parent with the temp installation and a mode:
//   `post   {year} {count} {every} {name}` — posts generated entries, rolling back
//          every k-th, prints the kept numbers as JSON (section K, gapless);
//   `update {entryId} {version} {name}`   — one manual edit at that version,
//          prints `ok` / `conflict` / the exception class (section I, races).
if (($argv[1] ?? '') === '--worker') {
    $workerBase = $argv[2];
    $workerMode = $argv[3];
    define('ABS_BASE_PATH', $workerBase);
    define('DEBUG', false);
    $workerName = $argv[$workerMode === 'post' ? 7 : 6] ?? 'w';
    \Z77\Core\DI::getInstance(true)
        ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
        ->set('FileFinder', fn($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn($c) => new \Z77\Core\Services\ModuleManager($c->get('ConfigManager')), true)
        ->set('DataSourceResolver', fn() => new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new \Z77\Persistence\Resolver\UnifiedEntityManager($c->get('DataSourceResolver')), true);
    \Z77\Core\DI::getCacheManager()->setCacheDir($workerBase . '/var/cache/' . $workerName);
    $wem    = \Z77\Core\DI::getUnifiedEntityManager();
    $chf    = fn(string $d) => \Z77\Shared\Money\Money::fromDecimal($d, 'CHF');

    if ($workerMode === 'update') {
        [$entryId, $version] = [(int) $argv[4], (int) $argv[5]];
        $service = new \Z77\Module\Financial\Services\ManualEntryService($wem, 'worker-' . $workerName);
        $request = \Z77\Module\Financial\Ledger\PostingRequest::manual(new \DateTimeImmutable('2027-12-10'), "edited by {$workerName}", [
            \Z77\Module\Financial\Ledger\PostingLine::debit('1000', $chf('70.00'), "line by {$workerName}"),
            \Z77\Module\Financial\Ledger\PostingLine::credit('1020', $chf('70.00')),
        ]);
        try {
            $service->update($entryId, $version, $request);
            echo 'ok';
        } catch (\Z77\Module\Financial\Services\EntryConflictException) {
            echo 'conflict';
        } catch (\Throwable $e) {
            echo get_class($e) . ': ' . $e->getMessage();
        }
        exit(0);
    }

    [$workerYear, $workerCount, $workerEvery] = [$argv[4], $argv[5], $argv[6]];
    $ledger = new \Z77\Module\Financial\Services\LedgerService($wem, 'worker-' . $workerName);
    $kept   = [];
    $rolled = 0;
    for ($i = 1; $i <= (int) $workerCount; $i++) {
        $request = \Z77\Module\Financial\Ledger\PostingRequest::generated(
            new \DateTimeImmutable($workerYear === '2028-29' ? '2028-08-15' : '2027-08-15'), "worker {$workerName} #{$i}", 'probe', "{$workerName}-{$i}", "probe:{$workerName}:{$i}",
            [\Z77\Module\Financial\Ledger\PostingLine::debit('1020', $chf('10.00')), \Z77\Module\Financial\Ledger\PostingLine::credit('3200', $chf('10.00'))]
        );
        try {
            $kept[] = $wem->getTransaction(\Z77\Module\Financial\Entities\JournalEntry::class)->run(function () use ($ledger, $request, $i, $workerEvery): int {
                $ref = $ledger->post($request);
                usleep(random_int(1000, 8000));
                if ($i % (int) $workerEvery === 0) {
                    throw new \RuntimeException('rolled back on purpose');
                }
                return $ref->number;
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'rolled back on purpose') { throw $e; }
            $rolled++;
        }
    }
    echo json_encode(['kept' => $kept, 'rolledBack' => $rolled]);
    exit(0);
}

use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Libraries\ConfigManager;
use Z77\Core\Libraries\FileFinder;
use Z77\Core\Services\ModuleManager;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\AccountType;
use Z77\Module\Financial\Entities\ChangeAction;
use Z77\Module\Financial\Entities\EntryChange;
use Z77\Module\Financial\Entities\EntryKind;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Entities\Period;
use Z77\Module\Financial\Entities\PeriodState;
use Z77\Module\Financial\Ledger\EntryRef;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Financial\Repositories\EntryChangeRepository;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Module\Financial\Repositories\JournalEntryRepository;
use Z77\Module\Financial\Services\AccountNumberChangedException;
use Z77\Module\Financial\Services\AccountService;
use Z77\Module\Financial\Services\ChartNotEmptyException;
use Z77\Module\Financial\Services\EntryConflictException;
use Z77\Module\Financial\Services\EntryNotEditableException;
use Z77\Module\Financial\Services\FiscalYearService;
use Z77\Module\Financial\Services\IdempotencyConflictException;
use Z77\Module\Financial\Services\InvalidAccountException;
use Z77\Module\Financial\Services\InvalidFiscalYearException;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\ManualEntryService;
use Z77\Module\Financial\Services\PostingRefusedException;
use Z77\Module\Financial\Services\ReversalRefusedException;
use Z77\Module\Financial\Ui\AccountControllerTrait;
use Z77\Module\Financial\Ui\FiscalYearControllerTrait;
use Z77\Module\Financial\Ui\JournalControllerTrait;
use Z77\Module\Financial\Validators\AccountValidator;
use Z77\Module\Financial\Validators\FiscalYearValidator;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Shared\Money\Money;
use Z77\Persistence\Doctrine\Bootstrap as DoctrineBootstrap;
use Z77\Persistence\Doctrine\Console\MigrationDirectories;
use Z77\Persistence\Doctrine\Console\MigrationsApplication;
use Z77\Persistence\Doctrine\Entities\NumberRange;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

/** Runs $fn and returns the exception when it throws $class, null otherwise. */
function caught(callable $fn, string $class): ?\Throwable
{
    try { $fn(); } catch (\Throwable $e) { return $e instanceof $class ? $e : null; }
    return null;
}

function throws(callable $fn, string $class): bool
{
    return caught($fn, $class) !== null;
}

function day(string $ymd): \DateTimeImmutable
{
    return new \DateTimeImmutable($ymd);
}

function chf(string $decimal): Money
{
    return Money::fromDecimal($decimal, 'CHF');
}

// ── credentials: never in the repository ─────────────────────────────────

$credentials = [
    'host'     => getenv('Z77_TEST_DB_HOST') ?: 'localhost',
    'user'     => getenv('Z77_TEST_DB_USER') ?: 'z77test',
    'password' => getenv('Z77_TEST_DB_PASSWORD') ?: '',
];
if ($credentials['password'] === '') {
    $file = rtrim((string) (getenv('USERPROFILE') ?: getenv('HOME')), '/\\') . '/.z77/mariadb.txt';
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
        if (str_starts_with($line, $credentials['user'] . '=')) {
            $credentials['password'] = substr($line, strlen($credentials['user']) + 1);
        }
    }
}
if ($credentials['password'] === '') {
    fwrite(STDERR, "module-financial: no database password — %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD.\n");
    exit(2);
}

// ── throwaway schema (deliberately NOT in our collation) and installation ─

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-module-financial-' . getmypid();
define('ABS_BASE_PATH', $base);
define('DEBUG', false);

$write = function (string $rel, string $content) use ($base): void {
    $path = $base . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
};
$rm = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (basename($f) === '.' || basename($f) === '..') { continue; }
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($dir);
};
register_shutdown_function(static function () use ($admin, $dbName, $rm, $base): void {
    try { $admin->executeStatement("DROP DATABASE IF EXISTS `{$dbName}`"); } catch (\Throwable) {}
    $rm($base);
});

// The REAL package is the module's source path: its config announces the
// entities, its res/migrations holds the migration, its res/charts the chart.
$package = str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-financial'));
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n"
    . "'Z77\\\\Module\\\\Financial\\\\' => ['sourcePaths' => ['{$package}']],\n"
    . "]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'financial', 'modules' => ['financial' => []]];");
$write('config/client/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
// The tax codes the journal lines reference (module-vat, file-based): seeded
// the way the installer does, `*.default.json` → `data/…`.
$vatSeed = realpath(__DIR__ . '/../packages/module-vat/data/framework/vat');
foreach (['tax_codes', 'tax_rates'] as $name) {
    $write('data/framework/vat/' . $name . '.json', file_get_contents($vatSeed . '/' . $name . '.default.json'));
}

/**
 * The DI wiring Bootstrap::__construct() + pullUpServices() do, reduced to
 * what the drivers and the CLI read. Calling it again is the «fresh
 * request»: a new UnifiedEntityManager, a new Doctrine EntityManager, an
 * empty Identity Map.
 */
$wireDi = static function () use ($base): UnifiedEntityManager {
    DI::getInstance(true)
        ->set('CacheManager', CacheManager::class, true)
        ->set('FileFinder', fn($c) => new FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn($c) => new ModuleManager($c->get('ConfigManager')), true)
        ->set('DataSourceResolver', fn() => new DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new UnifiedEntityManager($c->get('DataSourceResolver')), true)
    ;
    DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return DI::getUnifiedEntityManager();
};
$wireDi();

$db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$tables    = fn() => array_map('strtolower', $db->createSchemaManager()->listTableNames());
$tableInfo = fn(string $table) => $db->fetchAssociative('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$dbName, $table]);
$count     = fn(string $table) => (int) $db->fetchOne("SELECT COUNT(*) FROM {$table}");
$range     = fn(string $name) => $db->fetchOne('SELECT last_number FROM number_range WHERE name = ?', [$name]);
/** Empties the chart (self-referencing FK — checks off for this one statement's session). */
$emptyChart = function () use ($db): void {
    $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
    $db->executeStatement('DELETE FROM account');
    $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
};

/** Runs one z77-db command in-process on a fresh application; returns [exit code, output]. */
$run = static function (array $input): array {
    $app = MigrationsApplication::create(
        DoctrineBootstrap::buildEntityManager(),
        MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder()),
        DI::getCacheManager()->generatedPhp(),
        MigrationDirectories::missing(DI::getModuleManager(), DI::getFileFinder())
    );
    $app->setAutoExit(false);
    $out  = new BufferedOutput();
    $code = $app->run(new ArrayInput($input + ['--no-interaction' => true]), $out);

    return [$code, $out->fetch()];
};

// ── A. the migration ─────────────────────────────────────────────────────

echo "A. Migration (z77-db migrate on an empty database)\n";
$config = DI::getModuleManager()->getModuleConfig('financial');
check('A0 the module config announces exactly the six Doctrine entities', $config?->get('doctrineEntities') === [Account::class, FiscalYear::class, Period::class, JournalEntry::class, JournalLine::class, EntryChange::class]);
$dirs = MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder());
check('A1 the module\'s res/migrations is collected under Z77\\Module\\Financial\\Migrations', ($dirs['Z77\\Module\\Financial\\Migrations'] ?? '') === $package . '/res/migrations');
check('A2 the database is empty', $tables() === []);
[$code, $out] = $run(['command' => 'migrate']);
check('A3 migrate exits 0' . ($code !== 0 ? " — got {$code}: " . trim($out) : ''), $code === 0);
check('A4 … migrated up to the module\'s part-2 migration (the package one runs first, by timestamp)', str_contains($out, 'Z77\\Module\\Financial\\Migrations\\Version20260922091711'));
check('A5 account, fiscal_period, fiscal_year, journal_entry, journal_entry_change, journal_line exist (plus number_range and the metadata table)',
    $tables() === ['account', 'fiscal_period', 'fiscal_year', 'journal_entry', 'journal_entry_change', 'journal_line', 'number_range', MigrationsApplication::STORAGE_TABLE]);
$allUnicode = true;
foreach (['account', 'fiscal_year', 'fiscal_period', 'journal_entry', 'journal_line', 'journal_entry_change'] as $table) {
    $info = $tableInfo($table);
    $allUnicode = $allUnicode && ($info['TABLE_COLLATION'] ?? '') === 'utf8mb4_unicode_ci' && ($info['ENGINE'] ?? '') === 'InnoDB';
}
check('A6 every module table is utf8mb4_unicode_ci and InnoDB although the database default is general_ci', $allUnicode);
$columns = $db->fetchAllKeyValue('SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL', [$dbName, 'account']);
check('A7 … and so is every string column of account', $columns !== [] && count(array_unique($columns)) === 1 && reset($columns) === 'utf8mb4_unicode_ci');
$fk = fn(string $table) => $db->fetchFirstColumn('SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY 1', [$dbName, $table]);
check('A8 foreign keys: account → account (parent), fiscal_period → fiscal_year, journal_entry → fiscal_year + journal_entry (reversal), journal_line → account + journal_entry; the change log has NONE',
    $fk('account') === ['account'] && $fk('fiscal_period') === ['fiscal_year'] && $fk('fiscal_year') === []
    && $fk('journal_entry') === ['fiscal_year', 'journal_entry'] && $fk('journal_line') === ['account', 'journal_entry'] && $fk('journal_entry_change') === []);
$uniques = $db->fetchFirstColumn('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND INDEX_NAME <> ? GROUP BY INDEX_NAME ORDER BY 1', [$dbName, 'journal_entry', 'PRIMARY']);
check('A8b journal_entry: unique number per year, unique idempotency key, unique reversal_of (reversed at most once — in the schema)', $uniques === ['uniq_journal_entry_idempotency', 'uniq_journal_entry_number', 'uniq_journal_entry_reversal_of']);
[$code, $out] = $run(['command' => 'migrate']);
check('A9 a second migrate is a no-op', $code === 0 && str_contains($out, 'Already at the latest version'));
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Financial\\Migrations']);
check('A10 diff after migrate reports NO change — mapping and migration agree (money and text snapshot columns included)', $code !== 0 && str_contains($out, 'No changes detected') && count(glob($package . '/res/migrations/Version*.php')) === 2);
[$code, $out] = $run(['command' => 'status']);
check('A11 status lists the module namespace and three executed migrations', $code === 0 && str_contains($out, 'Z77\\Module\\Financial\\Migrations') && preg_match('/\| Executed\s+\|\s+3\s+\|/', $out) === 1);

// ── B. the KMU chart: a button on an EMPTY chart ─────────────────────────

echo "B. KMU chart of accounts (adopt only when empty)\n";
$em   = $wireDi();
$repo = $em->getRepository(Account::class);
check('B0 convention repository resolves; the chart starts empty', $repo instanceof AccountRepository && $repo->isEmpty());
$raw = file_get_contents(AccountService::KMU_CHART_FILE);
check('B1 the resource file is UTF-8 without BOM', $raw !== false && !str_starts_with($raw, "\xEF\xBB\xBF") && mb_check_encoding($raw, 'UTF-8'));
$rows = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
$created = (new AccountService($em))->adoptKmuChart();
check('B2 adoptKmuChart() creates every row of the file', $created === count($rows) && $count('account') === count($rows) && $created > 150);

$em   = $wireDi();
$repo = $em->getRepository(Account::class);
$chart = $repo->allInOrder();
$byNumber = [];
foreach ($chart as $a) { $byNumber[$a->getNumber()] = $a; }
$numbers = array_map(fn(Account $a) => $a->getNumber(), $chart);
$sorted  = $numbers;
sort($sorted, SORT_STRING);
check('B3 allInOrder() returns the chart in string order (1, 10, 100, 1000, 1020, …)', array_slice($numbers, 0, 5) === ['1', '10', '100', '1000', '1020'] && $numbers === $sorted);
$roots = array_values(array_filter($chart, fn(Account $a) => $a->getParent() === null));
check('B4 the roots are exactly the classes 1–9, all groups', array_map(fn(Account $a) => $a->getNumber(), $roots) === ['1', '2', '3', '4', '5', '6', '7', '8', '9'] && array_filter($roots, fn(Account $a) => $a->isPostable()) === []);
$chainOk = true;
$classOk = true;
foreach ($chart as $a) {
    $depth = 0;
    $top   = $a;
    for ($p = $a->getParent(); $p !== null; $p = $p->getParent()) {
        $chainOk = $chainOk && !$p->isPostable() && strlen($p->getNumber()) < strlen($a->getNumber());
        $top     = $p;
        $depth++;
    }
    $classOk = $classOk && $a->getNumber()[0] === $top->getNumber();
    if ($a->isPostable()) {
        $chainOk = $chainOk && $depth >= 2 && strlen($a->getNumber()) === 4;
    }
}
check('B5 every postable account hangs under a chain of GROUPS (shorter numbers) up to its class, at least class → main group; four digits', $chainOk);
// The Sterchi groups are number RANGES, not prefixes (1020 sits in group 100,
// 1850 in 180): what must hold is that the chart's string order is exactly
// the tree read top-down, children in number order — the list indents by it.
$children = [];
foreach ($chart as $a) { $children[$a->getParent()?->getNumber() ?? ''][] = $a->getNumber(); }
$preorder = [];
$walk = function (string $n) use (&$walk, &$preorder, $children): void {
    $preorder[] = $n;
    foreach ($children[$n] ?? [] as $c) { $walk($c); }
};
foreach ($children[''] as $root) { $walk($root); }
check('B6 every account sits in its class (first digit), and the string order IS the tree read top-down', $classOk && $preorder === $numbers);
$typesOk = true;
foreach ($chart as $a) {
    if (!$a->isPostable()) { continue; }
    $class = $a->getNumber()[0];
    $expected = match ($class) {
        '1' => ['asset'],
        '2' => str_starts_with($a->getNumber(), '28') || str_starts_with($a->getNumber(), '29') ? ['equity'] : ['liability'],
        '3' => ['revenue'],
        '4', '5' => ['expense'],
        '6', '7', '8' => ['expense', 'revenue'],
        default => [],
    };
    $typesOk = $typesOk && in_array($a->getType(), $expected, true);
}
check('B7 types plausible per class: 1 asset, 2 liability (28/29 equity), 3 revenue, 4–5 expense, 6–8 expense or revenue, 9 nothing postable', $typesOk);
check('B8 all five types occur; every account active', count(array_unique(array_map(fn(Account $a) => $a->getType(), $chart))) === 5 && array_filter($chart, fn(Account $a) => !$a->isActive()) === []);
check('B9 German names with umlauts intact; the key accounts are where a Swiss bookkeeper expects them',
    ($byNumber['10'] ?? null)?->getName() === 'Umlaufvermögen' && ($byNumber['1020'] ?? null)?->getName() === 'Bankguthaben'
    && ($byNumber['1100'] ?? null)?->getType() === 'asset' && ($byNumber['2200'] ?? null)?->getType() === 'liability'
    && ($byNumber['2979'] ?? null)?->getType() === 'equity' && ($byNumber['3200'] ?? null)?->getType() === 'revenue'
    && ($byNumber['6950'] ?? null)?->getType() === 'revenue' && ($byNumber['1170'] ?? null)?->isPostable() === true);
check('B10 every row passes the validator as stored', array_reduce($chart, fn($ok, Account $a) => $ok && (new AccountValidator($a, $repo))->isValid(), true));
$e = caught(fn() => (new AccountService($em))->adoptKmuChart(), ChartNotEmptyException::class);
check('B11 a second adoption is refused — the chart is not empty — and writes nothing', $e !== null && $count('account') === count($rows));
$emptyChart();
$em = $wireDi();
(new AccountService($em))->save(new Account(['number' => '1000', 'name' => 'Kasse', 'type' => 'asset', 'active' => false]));
check('B12 one INACTIVE account is enough to refuse the adoption (a migrated chart, P5b)', throws(fn() => (new AccountService($em))->adoptKmuChart(), ChartNotEmptyException::class) && $count('account') === 1);
$emptyChart();
$em = $wireDi();
(new AccountService($em))->adoptKmuChart();
check('B13 … and after emptying, the adoption runs again (one flush, same count)', $count('account') === count($rows));

// ── C. accounts: validation and the write side ───────────────────────────

echo "C. Accounts (validation, write side)\n";
$em      = $wireDi();
$repo    = $em->getRepository(Account::class);
$service = new AccountService($em);
$group   = $repo->findOneBy(['number' => '100']);   // Flüssige Mittel — a group
$bank    = $repo->findOneBy(['number' => '1020']);  // Bankguthaben — postable
$refused = function (array $row, string $field) use ($service): bool {
    $e = caught(fn() => $service->save(new Account($row)), InvalidAccountException::class);
    return $e !== null && $e->validator->hasFieldError($field);
};
$valid = ['number' => '1021', 'name' => 'Bank UBS', 'type' => 'asset', 'postable' => true];
check('C1 empty number, letters, a dot, eleven digits → number', $refused(['number' => ''] + $valid, 'number') && $refused(['number' => '10a0'] + $valid, 'number')
    && $refused(['number' => '10.20'] + $valid, 'number') && $refused(['number' => '12345678901'] + $valid, 'number'));
check('C2 a number that exists (1020) → number, naming the existing account', (function () use ($service, $valid) {
    $e = caught(fn() => $service->save(new Account(['number' => '1020'] + $valid)), InvalidAccountException::class);
    return $e !== null && str_contains($e->validator->getFieldError('number'), 'Bankguthaben');
})());
check('C3 empty name → name; unknown type → type; empty type → type', $refused(['name' => ''] + $valid, 'name') && $refused(['type' => 'income'] + $valid, 'type') && $refused(['type' => ''] + $valid, 'type'));
check('C4 the five types are the model\'s', array_map(fn(AccountType $t) => $t->value, AccountType::cases()) === ['asset', 'liability', 'equity', 'expense', 'revenue']);
check('C5 a POSTABLE account as parent → parent', $refused(['parent' => $bank] + $valid, 'parent'));
check('C6 nothing was written by the refused saves', $count('account') === count($rows));
$ubs = new Account(['parent' => $group] + $valid);
$service->save($ubs);
check('C7 a valid account under a group saves; type normalized, active by default', $ubs->getId() !== null && $ubs->isActive() && $db->fetchOne('SELECT parent_id FROM account WHERE number = ?', ['1021']) == $group->getId());
check('C8 label() is «number name»', $ubs->label() === '1021 Bank UBS');

$em      = $wireDi();
$repo    = $em->getRepository(Account::class);
$service = new AccountService($em);
$class1  = $repo->findOneBy(['number' => '1']);
$main10  = $repo->findOneBy(['number' => '10']);
$group   = $repo->findOneBy(['number' => '100']);
$e = caught(fn() => $service->update($class1, ['parent' => $group]), InvalidAccountException::class);
check('C9 a cycle is refused: class 1 under its own grand-child group 100', $e !== null && $e->validator->hasFieldError('parent') && $class1->getParent() === null);
$e = caught(fn() => $service->update($main10, ['parent' => $main10]), InvalidAccountException::class);
check('C10 an account under itself is refused', $e !== null && $e->validator->hasFieldError('parent'));
$e = caught(fn() => $service->update($group, ['postable' => true]), InvalidAccountException::class);
check('C11 a group WITH children cannot become postable', $e !== null && $e->validator->hasFieldError('postable') && !$group->isPostable());
$e = caught(fn() => $service->update($group, ['name' => '']), InvalidAccountException::class);
check('C12 update() refused: the managed entity is untouched, the DRAFT carries the input', $e !== null && $group->getName() === 'Flüssige Mittel' && $e->draft !== $group && $e->draft->getName() === '' && $e->draft->getId() === $group->getId());
$ubs = $repo->findOneBy(['number' => '1021']);
$service->update($ubs, ['name' => 'Bank UBS AG']);   // an unrelated, valid write in the SAME EntityManager
check('C13 … and the next flush of something else writes none of the refused changes', $db->fetchOne('SELECT name FROM account WHERE number = ?', ['100']) === 'Flüssige Mittel'
    && $db->fetchOne('SELECT postable FROM account WHERE number = ?', ['100']) == 0 && $db->fetchOne('SELECT parent_id FROM account WHERE number = ?', ['1']) === null
    && $db->fetchOne('SELECT name FROM account WHERE number = ?', ['1021']) === 'Bank UBS AG');
$e = caught(fn() => $service->update($ubs, ['number' => '1022']), AccountNumberChangedException::class);
check('C14 the number is immutable (AccountNumberChangedException), passing it unchanged is fine', $e !== null && $ubs->getNumber() === '1021' && caught(fn() => $service->update($ubs, ['number' => '1021', 'name' => 'Bank UBS AG']), \Throwable::class) === null);
$leaf = new Account(['number' => '1029', 'name' => 'Leere Gruppe', 'type' => 'asset', 'postable' => false, 'parent' => $group]);
$service->save($leaf);
$service->update($leaf, ['postable' => true]);
check('C15 a group WITHOUT children may become postable; a postable one may become a group', $db->fetchOne('SELECT postable FROM account WHERE number = ?', ['1029']) == 1
    && caught(fn() => $service->update($leaf, ['postable' => false]), \Throwable::class) === null);
$service->update($ubs, ['parent' => $repo->findOneBy(['number' => '10'])]);
check('C16 moving an account to another group', $db->fetchOne('SELECT parent_id FROM account WHERE number = ?', ['1021']) == $main10->getId());
$service->setActive($ubs, false);
$read = $wireDi()->getRepository(Account::class)->findOneBy(['number' => '1021']);
check('C17 deactivation is persisted, the row stays', $read !== null && !$read->isActive());
check('C18 the LogicExceptions: save(existing), update(new)', throws(fn() => $service->save($ubs), \LogicException::class) && throws(fn() => $service->update(new Account(), []), \LogicException::class));
check('C19 the validator without a repository checks format and parent chain only', (new AccountValidator(new Account(['number' => '1020'] + $valid)))->isValid()
    && !(new AccountValidator(new Account(['number' => 'x'] + $valid)))->isValid());

// ── D. fiscal years and periods ──────────────────────────────────────────

echo "D. Fiscal years (validation, periods, number range)\n";
$em      = $wireDi();
$years   = $em->getRepository(FiscalYear::class);
$service = new FiscalYearService($em);
check('D0 convention repository resolves; no year yet', $years instanceof FiscalYearRepository && $years->latest() === null && $years->allWithPeriods() === []);
$invalid = function (string $code, string $start, string $end, string $field, bool $withRepo = true) use ($years): bool {
    $v = new FiscalYearValidator(new FiscalYear($code, day($start), day($end)), $withRepo ? $years : null);
    return !$v->isValid() && $v->hasFieldError($field);
};
check('D1 code: empty, blank inside, underscore, trailing hyphen, 17 characters → code', $invalid('', '2026-01-01', '2026-12-31', 'code') && $invalid('2026 27', '2026-01-01', '2026-12-31', 'code')
    && $invalid('2026_27', '2026-01-01', '2026-12-31', 'code') && $invalid('2026-', '2026-01-01', '2026-12-31', 'code') && $invalid(str_repeat('1', 17), '2026-01-01', '2026-12-31', 'code'));
check('D2 the setter lower-cases and trims the code', (new FiscalYear(' GJ-2026 '))->getCode() === 'gj-2026');
check('D3 end before or on the start → end_date', $invalid('x', '2026-01-01', '2025-12-31', 'end_date') && $invalid('x', '2026-01-01', '2026-01-01', 'end_date'));
check('D4 24 months is the limit: 15.3.2025–14.3.2027 passes, –15.3.2027 is refused', (new FiscalYearValidator(new FiscalYear('x', day('2025-03-15'), day('2027-03-14'))))->isValid()
    && $invalid('x', '2025-03-15', '2027-03-15', 'end_date', false));
$v = new FiscalYearValidator(new FiscalYear('x', null, null));
check('D5 missing dates → start_date and end_date', !$v->isValid() && $v->hasFieldError('start_date') && $v->hasFieldError('end_date'));
$v = new FiscalYearValidator(new FiscalYear('', null, day('2026-12-31')));
check('D5b empty code with a missing date: only the date is reported — the code would be proposed from the dates (review L5)', !$v->isValid() && $v->hasFieldError('start_date') && !$v->hasFieldError('code'));
check('D6 proposeCode(): 2026 inside a year, 2027-28 across, 2025-27 for an extended year', FiscalYearService::proposeCode(day('2026-01-01'), day('2026-12-31')) === '2026'
    && FiscalYearService::proposeCode(day('2027-07-01'), day('2028-06-30')) === '2027-28' && FiscalYearService::proposeCode(day('2025-03-15'), day('2027-03-14')) === '2025-27');
$proposal = $service->proposeNext();
check('D7 proposeNext() for the first year: 1 January of this year, twelve months, code from the dates', $proposal->getStartDate()->format('m-d') === '01-01'
    && $proposal->getStartDate()->format('Y') === date('Y') && $proposal->getEndDate()->format('m-d') === '12-31' && $proposal->getCode() === date('Y') && $proposal->getId() === null);

echo "D. … a partial first year 15.3.–31.12.2025\n";
$first = new FiscalYear('2025', day('2025-03-15'), day('2025-12-31'));
$service->open($first);
$periods = $first->getPeriods();
check('D8 opened: id, ten periods, the first 15.3.–31.3., the last December', $first->getId() !== null && count($periods) === 10
    && $periods[0]->getStartDate()->format('Y-m-d') === '2025-03-15' && $periods[0]->getEndDate()->format('Y-m-d') === '2025-03-31'
    && $periods[9]->getStartDate()->format('Y-m-d') === '2025-12-01' && $periods[9]->getEndDate()->format('Y-m-d') === '2025-12-31');
check('D9 the range journal-entry.2025 exists at 0 (created, nothing consumed)', $range('journal-entry.2025') === 0 || $range('journal-entry.2025') === '0');
check('D10 journalEntryRange() composes the name in one place', $first->journalEntryRange() === 'journal-entry.2025' && FiscalYear::JOURNAL_ENTRY_RANGE_PREFIX === 'journal-entry.');

echo "D. … contiguity\n";
$em      = $wireDi();
$years   = $em->getRepository(FiscalYear::class);
$service = new FiscalYearService($em);
check('D11 a gap is refused (2.1.2026), naming the expected start', (function () use ($years) {
    $v = new FiscalYearValidator(new FiscalYear('2026', day('2026-01-02'), day('2026-12-31')), $years);
    return !$v->isValid() && str_contains($v->getFieldError('start_date'), '01.01.2026');
})());
check('D12 an overlap is refused (1.12.2025)', $invalid('2026', '2025-12-01', '2026-12-31', 'start_date'));
check('D13 a second «first» year before the existing one is refused', $invalid('2024', '2024-01-01', '2024-12-31', 'start_date'));
$e = caught(fn() => $service->open(new FiscalYear('2026', day('2026-01-02'), day('2026-12-31'))), InvalidFiscalYearException::class);
check('D14 open() refuses it and writes nothing — no year, no periods, no range', $e !== null && $count('fiscal_year') === 1 && $count('fiscal_period') === 10 && $range('journal-entry.2026') === false);
$proposal = $service->proposeNext();
check('D15 proposeNext() follows the latest year: 1.1.2026–31.12.2026, code 2026', $proposal->getStartDate()->format('Y-m-d') === '2026-01-01' && $proposal->getEndDate()->format('Y-m-d') === '2026-12-31' && $proposal->getCode() === '2026');

echo "D. … a calendar year 2026\n";
$service->open($proposal);
$em    = $wireDi();
$years = $em->getRepository(FiscalYear::class);
$y2026 = $years->findOneBy(['code' => '2026']);
$ps    = $y2026->getPeriods();
$monthsOk = count($ps) === 12;
foreach ($ps as $i => $p) {
    $month    = sprintf('2026-%02d', $i + 1);
    $monthsOk = $monthsOk && $p->getStartDate()->format('Y-m-d') === $month . '-01' && $p->getEndDate()->format('Y-m-d') === day($month . '-01')->modify('last day of this month')->format('Y-m-d');
}
check('D16 twelve calendar-month periods, read back in date order (February ends 28.2.)', $monthsOk && $ps[1]->getEndDate()->format('Y-m-d') === '2026-02-28');
check('D17 every period is open', array_reduce($ps, fn($ok, Period $p) => $ok && $p->getState() === PeriodState::Open->value, true) && $db->fetchOne("SELECT COUNT(*) FROM fiscal_period WHERE state <> 'open'") == 0);
check('D18 the three states are the ADR\'s', array_map(fn(PeriodState $s) => $s->value, PeriodState::cases()) === ['open', 'vat-settled', 'closed']);
check('D19 the range journal-entry.2026 exists at 0', (string) $range('journal-entry.2026') === '0');
$draw = $em->getTransaction(FiscalYear::class)->run(fn() => $em->getRepository(NumberRange::class)->next('journal-entry.2026'));
check('D20 … the first draw from it returns 1 (the opening consumed nothing)', $draw === 1);

echo "D. … a short year and a deviating year 1.7.–30.6.\n";
$em      = $wireDi();
$service = new FiscalYearService($em);
$service->open(new FiscalYear('2027', day('2027-01-01'), day('2027-06-30')));
$deviating = $service->proposeNext();
check('D21 after a short year 1.1.–30.6.2027 the proposal is 1.7.2027–30.6.2028, code 2027-28 (two years start in 2027)', $deviating->getStartDate()->format('Y-m-d') === '2027-07-01' && $deviating->getEndDate()->format('Y-m-d') === '2028-06-30' && $deviating->getCode() === '2027-28');
$service->open($deviating);
$dp = $deviating->getPeriods();
check('D22 the deviating year has twelve periods July 2027 … June 2028', count($dp) === 12 && $dp[0]->getStartDate()->format('Y-m-d') === '2027-07-01' && $dp[5]->getStartDate()->format('Y-m-d') === '2027-12-01'
    && $dp[6]->getStartDate()->format('Y-m-d') === '2028-01-01' && $dp[7]->getEndDate()->format('Y-m-d') === '2028-02-29' && $dp[11]->getEndDate()->format('Y-m-d') === '2028-06-30');
check('D23 ranges journal-entry.2027 and journal-entry.2027-28 exist, both at 0', (string) $range('journal-entry.2027') === '0' && (string) $range('journal-entry.2027-28') === '0');
$e = caught(fn() => $service->open(new FiscalYear('2027', day('2028-07-01'), day('2029-06-30'))), InvalidFiscalYearException::class);
check('D24 a code already taken is refused (code) — the next year needs its own', $e !== null && $e->validator->hasFieldError('code') && !$e->validator->hasFieldError('start_date'));
$list = $em->getRepository(FiscalYear::class)->allWithPeriods();
check('D25 allWithPeriods(): newest first, periods loaded', array_map(fn(FiscalYear $y) => $y->getCode(), $list) === ['2027-28', '2027', '2026', '2025'] && count($list[3]->getPeriods()) === 10);

echo "D. … open() owns its unit of work (review L1)\n";
$em      = $wireDi();
$service = new FiscalYearService($em);
$nested  = null;
$outer   = function () use ($service, &$nested) {
    $nested = caught(fn() => $service->open(new FiscalYear('2028-29', day('2028-07-01'), day('2029-06-30'))), \LogicException::class);
    throw new \RuntimeException('outer aborts');
};
caught(fn() => $em->getTransaction(FiscalYear::class)->run($outer), \RuntimeException::class);
check('D26 open() inside an open unit of work is refused (LogicException) — the race mapping needs its own flush', $nested !== null && str_contains($nested->getMessage(), 'owns its unit of work'));
check('D27 … nothing was written, and the port is closed again', $count('fiscal_year') === 4 && $count('fiscal_period') === 10 + 12 + 6 + 12
    && $range('journal-entry.2028-29') === false && !$wireDi()->getTransaction(FiscalYear::class)->isOpen());

echo "D. … a race on the start date becomes a field error\n";
// Another request opened the same next year between our validation and our
// commit: simulated by a row the validator cannot see as «latest» (its end
// lies in the past), carrying the start date we are about to use.
$db->executeStatement("INSERT INTO fiscal_year (code, start_date, end_date) VALUES ('race', '2028-07-01', '2020-01-01')");
$em      = $wireDi();
$service = new FiscalYearService($em);
$e = caught(fn() => $service->open(new FiscalYear('2028-29', day('2028-07-01'), day('2029-06-30'))), InvalidFiscalYearException::class);
check('D28 the unique start date is reported as a start_date field error (no 500)', $e !== null && $e->validator->hasFieldError('start_date') && str_contains($e->validator->getFieldError('start_date'), 'soeben'));
check('D29 … and the loser left nothing behind — no periods, no range', $count('fiscal_year') === 5 && $count('fiscal_period') === 40 && $range('journal-entry.2028-29') === false);
$db->executeStatement("DELETE FROM fiscal_year WHERE code = 'race'");
$em = $wireDi();
(new FiscalYearService($em))->open(new FiscalYear('2028-29', day('2028-07-01'), day('2029-06-30')));
check('D30 after the other opening is gone, the year opens normally', $count('fiscal_year') === 5 && (string) $range('journal-entry.2028-29') === '0');

echo "D. … a leftover range is refused, not continued (review M1)\n";
// A range row that already exists (a removed year, an import) must not be
// adopted silently — the year would start its entries at 6.
$db->executeStatement("INSERT INTO number_range (name, last_number) VALUES ('journal-entry.2029-30', 5)");
$em = $wireDi();
$e  = caught(fn() => (new FiscalYearService($em))->open(new FiscalYear('2029-30', day('2029-07-01'), day('2030-06-30'))), InvalidFiscalYearException::class);
check('D32 open() refuses a year whose range already exists, as a code field error naming the range', $e !== null && $e->validator->hasFieldError('code') && str_contains($e->validator->getFieldError('code'), 'journal-entry.2029-30'));
check('D33 … the unit of work rolled back: no year, no periods, the old range untouched at 5, the port closed', $count('fiscal_year') === 5 && $count('fiscal_period') === 52
    && (string) $range('journal-entry.2029-30') === '5' && !$wireDi()->getTransaction(FiscalYear::class)->isOpen());
$em = $wireDi();
(new FiscalYearService($em))->open(new FiscalYear('2029-30b', day('2029-07-01'), day('2030-06-30')));
check('D34 … with another code the year opens, its own range at 0', $count('fiscal_year') === 6 && (string) $range('journal-entry.2029-30b') === '0');

echo "D. … guards\n";
$opened = $wireDi()->getRepository(FiscalYear::class)->findOneBy(['code' => '2026']);
check('D31 open() refuses an existing year; addPeriod() refuses an opened year; a period cannot end before it starts',
    throws(fn() => (new FiscalYearService(DI::getUnifiedEntityManager()))->open($opened), \LogicException::class)
    && throws(fn() => $opened->addPeriod(new Period($opened, day('2026-01-01'), day('2026-01-31'))), \LogicException::class)
    && throws(fn() => new Period($opened, day('2026-02-01'), day('2026-01-31')), \LogicException::class));

// ── E. no delete, no edit of a year, no transition ───────────────────────

echo "E. Deactivate / never delete; no year edit, no period transition (part 1)\n";
$methodsOf = fn(string $class) => array_map(fn(\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass($class))->getMethods());
$hasAny    = fn(array $methods, array $words) => array_filter($methods, fn($m) => array_filter($words, fn($w) => stripos($m, $w) !== false) !== []) !== [];
check('E1 AccountService has no delete; FiscalYearService has no delete, update or close', !$hasAny($methodsOf(AccountService::class), ['delete', 'remove'])
    && !$hasAny($methodsOf(FiscalYearService::class), ['delete', 'remove', 'update', 'close', 'settle']));
check('E2 Period has no state setter (P5 moves states); FiscalYear has no public setter — code and dates come with the constructor', !$hasAny($methodsOf(Period::class), ['setState', 'close', 'settle'])
    && array_filter((new \ReflectionClass(FiscalYear::class))->getMethods(\ReflectionMethod::IS_PUBLIC), fn(\ReflectionMethod $m) => str_starts_with($m->getName(), 'set')) === []);
$accountActions = $methodsOf(AccountControllerTrait::class);
check('E3 the account trait: no delete action; toggle and the KMU adoption exist', !$hasAny($accountActions, ['delete', 'remove']) && in_array('toggleActiveAction', $accountActions, true)
    && in_array('adoptKmuChartAction', $accountActions, true) && in_array('confirmAdoptKmuChartAction', $accountActions, true));
$yearActions = $methodsOf(FiscalYearControllerTrait::class);
check('E4 the fiscal-year trait: list and open only', array_values(array_filter($yearActions, fn($m) => str_ends_with($m, 'Action'))) === ['listAction', 'openAction']);
$traitSource = file_get_contents($package . '/src/Ui/AccountControllerTrait.php');
check('E5 the account trait maps a body only onto the NEW account of «add» (source guard, ADR-039 decision 9)', preg_match_all('/->mapFromArray\(/', $traitSource) === 1 && str_contains($traitSource, '$account->mapFromArray($values);')
    && strpos($traitSource, '$account->mapFromArray($values);') < strpos($traitSource, 'function editAction'));
$host = new class { use FiscalYearControllerTrait; };
$parse = new \ReflectionMethod($host, 'fiscalYearDate');
check('E6 the form\'s date parser takes real calendar dates only (29.2.2028 yes, 30.2.2026 / 2026-2-1 / garbage no)', $parse->invoke(null, '2028-02-29')?->format('Y-m-d') === '2028-02-29'
    && $parse->invoke(null, '2026-02-30') === null && $parse->invoke(null, '2026-2-1') === null && $parse->invoke(null, 'morgen') === null && $parse->invoke(null, ['x']) === null);

// ═════════════════════════════════════════════════════════════════════════
// Part 2 — the journal, LedgerService, manual entries with change log
// ═════════════════════════════════════════════════════════════════════════
//
// State inherited from above: years 2025 (15.3.–31.12.), 2026 (range at 1 —
// D20 drew and committed one number), 2027 (1.1.–30.6.), 2027-28, 2028-29,
// 2029-30b (all at 0); the KMU chart, account 1021 INACTIVE, 1029 a group.

/** A generated posting request: bank ← revenue with VAT (net method, tax data on the revenue line). */
$saleRequest = function (string $date, string $text, string $key, string $ref = 'INV-1') {
    return PostingRequest::generated(day($date), $text, 'invoice', $ref, $key, [
        PostingLine::debit('1020', chf('108.10')),
        PostingLine::credit('3200', chf('100.00'), 'Handelserlös', 'UN', 810, chf('100.00'), chf('8.10')),
        PostingLine::credit('2200', chf('8.10')),
    ]);
};
/** A manual expense with input tax. */
$expenseRequest = fn(string $date, string $text) => PostingRequest::manual(day($date), $text, [
    PostingLine::debit('6500', chf('100.00'), 'Papier', 'VM', 810, chf('100.00'), chf('8.10')),
    PostingLine::debit('1170', chf('8.10')),
    PostingLine::credit('1020', chf('108.10')),
]);
/** A manual transfer without tax. */
$transferRequest = fn(string $date, string $text) => PostingRequest::manual(day($date), $text, [
    PostingLine::debit('1000', chf('50.00')),
    PostingLine::credit('1020', chf('50.00')),
]);
/** Posts inside a unit of work the way a module does; returns the ref. */
$post = fn(UnifiedEntityManager $em, PostingRequest $request, string $actor = 'tester') => $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, $actor))->post($request));
/** Refusal reason of a post(), or null when it went through. */
$refused = function (UnifiedEntityManager $em, PostingRequest $request) use ($post): ?string {
    $e = caught(fn() => $post($em, $request), PostingRefusedException::class);
    return $e?->reason;
};
/** Period state, set directly — no transition code exists before P5. */
$setState = fn(string $yearCode, string $ymd, string $state) => $db->executeStatement(
    'UPDATE fiscal_period p JOIN fiscal_year y ON y.id = p.fiscal_year_id SET p.state = ? WHERE y.code = ? AND ? BETWEEN p.start_date AND p.end_date',
    [$state, $yearCode, $ymd]
);
$entryRow = fn(string $yearCode, int $number) => $db->fetchAssociative('SELECT e.* FROM journal_entry e JOIN fiscal_year y ON y.id = e.fiscal_year_id WHERE y.code = ? AND e.number = ?', [$yearCode, $number]);
$lineRows = fn(int $entryId) => $db->fetchAllAssociative('SELECT l.*, a.number AS account_number FROM journal_line l JOIN account a ON a.id = l.account_id WHERE l.entry_id = ? ORDER BY l.position', [$entryId]);

// ── F. the DTOs: what needs no database ──────────────────────────────────

echo "F. PostingRequest / PostingLine invariants (no database)\n";
$d = PostingLine::debit('1020', chf('10.00'));
$c = PostingLine::credit('3200', chf('10.00'));
check('F1 a balanced two-line manual request builds; currency, no tax, no key', (function () use ($d, $c) {
    $r = PostingRequest::manual(day('2027-08-01'), 'ok', [$d, $c]);
    return $r->kind === EntryKind::Manual && count($r->lines) === 2 && $r->currency() === 'CHF' && !$r->hasTaxLine() && $r->idempotencyKey === null;
})());
check('F2 unbalanced → refused (10.00 vs 9.99)', throws(fn() => PostingRequest::manual(day('2027-08-01'), 'x', [$d, PostingLine::credit('3200', chf('9.99'))]), \InvalidArgumentException::class));
check('F3 one line → refused; zero lines → refused', throws(fn() => PostingRequest::manual(day('2027-08-01'), 'x', [$d]), \InvalidArgumentException::class) && throws(fn() => PostingRequest::manual(day('2027-08-01'), 'x', []), \InvalidArgumentException::class));
check('F4 a line with both sides positive, both zero, or a negative amount → refused', throws(fn() => new PostingLine('1020', chf('1.00'), chf('1.00')), \InvalidArgumentException::class)
    && throws(fn() => new PostingLine('1020', chf('0.00'), chf('0.00')), \InvalidArgumentException::class) && throws(fn() => new PostingLine('1020', chf('-1.00'), chf('0.00')), \InvalidArgumentException::class));
check('F5 the account is a NUMBER (digits)', throws(fn() => PostingLine::debit('bank', chf('1.00')), \InvalidArgumentException::class) && throws(fn() => PostingLine::debit('', chf('1.00')), \InvalidArgumentException::class));
check('F6 tax data all-or-none: code without rate/base/amount, or base without code → refused; complete → fine', throws(fn() => PostingLine::credit('3200', chf('100.00'), null, 'UN'), \InvalidArgumentException::class)
    && throws(fn() => PostingLine::credit('3200', chf('100.00'), null, null, null, chf('100.00')), \InvalidArgumentException::class)
    && PostingLine::credit('3200', chf('100.00'), null, 'UN', 810, chf('100.00'), chf('8.10'))->hasTax());
check('F7 a generated request needs origin AND key; a manual one refuses a key', throws(fn() => PostingRequest::generated(day('2027-08-01'), 'x', '', 'r', 'k', [$d, $c]), \InvalidArgumentException::class)
    && throws(fn() => PostingRequest::generated(day('2027-08-01'), 'x', 'invoice', 'r', '', [$d, $c]), \InvalidArgumentException::class)
    && (new \ReflectionClass(PostingRequest::class))->getConstructor()->isPrivate()   // only the two named constructors build one
    && PostingRequest::generated(day('2027-08-01'), 'x', 'invoice', 'r', 'k', [$d, $c])->kind === EntryKind::Generated);
check('F8 a second currency in the same request → refused (one currency, ADR-042 decision 4)', throws(fn() => PostingRequest::manual(day('2027-08-01'), 'x', [$d, PostingLine::credit('3200', Money::fromDecimal('10.00', 'EUR'))]), \InvalidArgumentException::class));
check('F9 empty text → refused; 256 characters → refused', throws(fn() => PostingRequest::manual(day('2027-08-01'), ' ', [$d, $c]), \InvalidArgumentException::class) && throws(fn() => PostingRequest::manual(day('2027-08-01'), str_repeat('x', 256), [$d, $c]), \InvalidArgumentException::class));
$tax = PostingLine::credit('3200', chf('100.00'), 't', 'UN', 810, chf('100.00'), chf('8.10'));
check('F10 swapped(): sides swapped, tax base and amount negated, code and rate kept', $tax->swapped()->debit->equals(chf('100.00')) && $tax->swapped()->credit->isZero()
    && $tax->swapped()->taxBase->equals(chf('-100.00')) && $tax->swapped()->taxAmount->equals(chf('-8.10')) && $tax->swapped()->taxCode === 'UN' && $tax->swapped()->taxRate === 810);
check('F11 EntryRef needs a code and a number from 1', throws(fn() => new EntryRef('', 1), \InvalidArgumentException::class) && throws(fn() => new EntryRef('2026', 0), \InvalidArgumentException::class) && (new EntryRef('2026', 7))->number === 7);

// ── G. LedgerService::post() ─────────────────────────────────────────────

echo "G. LedgerService::post()\n";
$em = $wireDi();
check('G0 the convention repositories resolve', $em->getRepository(JournalEntry::class) instanceof JournalEntryRepository && $em->getRepository(EntryChange::class) instanceof EntryChangeRepository
    && $em->getRepository(TaxCode::class)->findByCode('UN') !== null);
check('G1 listLimit(): the default 200 while financialConfig carries no journalListLimit', LedgerService::listLimit() === 200);
$e = caught(fn() => (new LedgerService($em, 'tester'))->post($saleRequest('2027-08-15', 'outside', 'k-outside')), \LogicException::class);
check('G2 post() OUTSIDE a unit of work is refused (LogicException naming getTransaction) and writes nothing', $e !== null && str_contains($e->getMessage(), 'getTransaction') && $count('journal_entry') === 0);
check('G3 no fiscal year for 1.1.2020 → no-fiscal-year', $refused($em, $saleRequest('2020-01-01', 'x', 'k-2020')) === PostingRefusedException::NO_FISCAL_YEAR);
$bad = fn(PostingLine $line) => PostingRequest::manual(day('2027-08-15'), 'x', [$line, PostingLine::credit('3200', chf('1.00'))]);
check('G4 unknown account 9999 → account-unknown', $refused($em, $bad(PostingLine::debit('9999', chf('1.00')))) === PostingRefusedException::ACCOUNT_UNKNOWN);
check('G5 group 100 (not postable) → account-not-postable', $refused($em, $bad(PostingLine::debit('100', chf('1.00')))) === PostingRefusedException::ACCOUNT_NOT_POSTABLE);
check('G6 deactivated account 1021 → account-inactive', $refused($em, $bad(PostingLine::debit('1021', chf('1.00')))) === PostingRefusedException::ACCOUNT_INACTIVE);
check('G7 unknown tax code XX → tax-code-unknown; the vat-settled/closed checks come first but the period is open here', $refused($em, PostingRequest::manual(day('2027-08-15'), 'x', [
    PostingLine::debit('6500', chf('100.00'), null, 'XX', 810, chf('100.00'), chf('8.10')), PostingLine::credit('1020', chf('100.00')),
])) === PostingRefusedException::TAX_CODE_UNKNOWN);
check('G8 nothing was written by the refusals and no number was drawn (2027-28 still at 0)', $count('journal_entry') === 0 && $count('journal_line') === 0 && (string) $range('journal-entry.2027-28') === '0' && !$em->getTransaction(JournalEntry::class)->isOpen());
$e = caught(fn() => $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em))->post($saleRequest('2027-08-15', 'no actor', 'k-noactor'))), \LogicException::class);
check('G9 no actor and no AuthService in this context → LogicException BEFORE the number is drawn (still at 0)', $e !== null && str_contains($e->getMessage(), 'actor') && (string) $range('journal-entry.2027-28') === '0' && $count('journal_entry') === 0);

echo "G. … a generated sale with VAT\n";
$em  = $wireDi();
$ref = $post($em, $saleRequest('2027-08-15', 'Rechnung 1', 'invoice:1'), 'buchhalter');
$row = $entryRow('2027-28', 1);
check('G10 EntryRef 2027-28/1 — the first number of the year', $ref instanceof EntryRef && $ref->fiscalYear === '2027-28' && $ref->number === 1);
check('G11 the header row: date, text, kind generated, opaque origin, key, created_by = the actor, no changed_by, no reversal', $row !== false && $row['entry_date'] === '2027-08-15' && $row['text'] === 'Rechnung 1' && $row['kind'] === 'generated'
    && $row['source_type'] === 'invoice' && $row['source_ref'] === 'INV-1' && $row['idempotency_key'] === 'invoice:1' && $row['created_by'] === 'buchhalter' && $row['changed_by'] === null && $row['reversal_of_id'] === null && $row['created_at'] !== null);
$lines = $lineRows((int) $row['id']);
check('G12 three lines in position order, DECIMAL(15,2) as written, accounts by FK', count($lines) === 3 && array_column($lines, 'position') == [1, 2, 3] && array_column($lines, 'account_number') === ['1020', '3200', '2200']
    && $lines[0]['debit'] === '108.10' && $lines[0]['credit'] === '0.00' && $lines[1]['credit'] === '100.00' && $lines[1]['debit'] === '0.00' && $lines[2]['credit'] === '8.10');
check('G13 the tax data sits on the revenue line only: code, rate snapshot 810, base 100.00, amount 8.10, text; the other lines carry none', $lines[1]['tax_code'] === 'UN' && (int) $lines[1]['tax_rate'] === 810 && $lines[1]['tax_base'] === '100.00' && $lines[1]['tax_amount'] === '8.10' && $lines[1]['text'] === 'Handelserlös'
    && $lines[0]['tax_code'] === null && $lines[0]['tax_rate'] === null && $lines[0]['tax_base'] === null && $lines[2]['tax_code'] === null && $lines[0]['text'] === null);
check('G14 the range moved to 1', (string) $range('journal-entry.2027-28') === '1');
$em    = $wireDi();
$again = $post($em, $saleRequest('2027-08-15', 'Rechnung 1', 'invoice:1'), 'buchhalter');
check('G15 idempotency: the same request again → the same ref, no second entry, no number consumed', $again->fiscalYear === '2027-28' && $again->number === 1 && $count('journal_entry') === 1 && (string) $range('journal-entry.2027-28') === '1');
$e = caught(fn() => $post($em, $saleRequest('2027-08-15', 'Rechnung 1 — anders', 'invoice:1'), 'buchhalter'), IdempotencyConflictException::class);
check('G16 the same key with DIFFERENT content (text) → IdempotencyConflictException naming the existing entry; nothing written', $e !== null && $e->reason === PostingRefusedException::IDEMPOTENCY_CONFLICT && $e->existing->number === 1 && $e->idempotencyKey === 'invoice:1' && $count('journal_entry') === 1);
$differentLines = PostingRequest::generated(day('2027-08-15'), 'Rechnung 1', 'invoice', 'INV-1', 'invoice:1', [PostingLine::debit('1020', chf('108.10')), PostingLine::credit('3200', chf('108.10'))]);
check('G17 … the same key with different LINES → conflict as well', throws(fn() => $post($em, $differentLines, 'buchhalter'), IdempotencyConflictException::class));
$e = caught(fn() => $em->getTransaction(JournalEntry::class)->run(function () use ($em, $saleRequest): void {
    (new LedgerService($em, 'buchhalter'))->post($saleRequest('2027-08-16', 'Rechnung 2', 'invoice:2', 'INV-2'));
    throw new \RuntimeException('caller aborts after posting');
}), \RuntimeException::class);
check('G18 the caller rolls back after post(): no entry, and the number is NOT consumed (range still 1)', $e !== null && $count('journal_entry') === 1 && (string) $range('journal-entry.2027-28') === '1');
$em  = $wireDi();
$ref = $post($em, $saleRequest('2027-08-16', 'Rechnung 2', 'invoice:2', 'INV-2'), 'buchhalter');
check('G19 … the next posting gets number 2 — gapless', $ref->number === 2 && (string) $range('journal-entry.2027-28') === '2');
$ref = $post($em, $expenseRequest('2027-08-20', 'Papier manuell'), 'buchhalter');
$row = $entryRow('2027-28', 3);
check('G20 a MANUAL request through post(): number 3, kind manual, no origin, no key', $ref->number === 3 && $row['kind'] === 'manual' && $row['source_type'] === null && $row['source_ref'] === null && $row['idempotency_key'] === null);
$two = $em->getTransaction(JournalEntry::class)->run(function () use ($em, $transferRequest): array {
    $ledger = new LedgerService($em, 'buchhalter');
    return [$ledger->post($transferRequest('2027-08-21', 'Umbuchung A'))->number, $ledger->post($transferRequest('2027-08-21', 'Umbuchung B'))->number];
});
check('G21 two postings in ONE unit of work: 4 and 5, both committed', $two === [4, 5] && $count('journal_entry') === 5);
check('G22 the entries read back through the repository with lines and total', (function () use ($em) {
    $year  = $em->getRepository(FiscalYear::class)->findOneBy(['code' => '2027-28']);
    $list  = $em->getRepository(JournalEntry::class)->latestForYear($year, 200);
    $first = $em->getRepository(JournalEntry::class)->findByRef(new EntryRef('2027-28', 1));
    return array_map(fn(JournalEntry $e) => $e->getNumber(), $list) === [5, 4, 3, 2, 1] && $em->getRepository(JournalEntry::class)->countForYear($year) === 5
        && $first !== null && $first->total()->equals(chf('108.10')) && $first->hasTaxLine() && count($first->getLines()) === 3 && $first->getLines()[1]->getAccount()->getNumber() === '3200'
        && $em->getRepository(JournalEntry::class)->latestForYear($year, 2) !== [] && count($em->getRepository(JournalEntry::class)->latestForYear($year, 2)) === 2;
})());
check('G23 FiscalYear::periodOn() / covers() and FiscalYearRepository::findByDate()', (function () use ($em) {
    $year = $em->getRepository(FiscalYear::class)->findByDate(day('2027-08-15'));
    return $year?->getCode() === '2027-28' && $year->covers(day('2028-06-30')) && !$year->covers(day('2028-07-01'))
        && $year->periodOn(day('2027-08-15'))?->getStartDate()->format('Y-m-d') === '2027-08-01' && $year->periodOn(day('2028-07-01')) === null
        && $em->getRepository(FiscalYear::class)->findByDate(day('2019-01-01')) === null;
})());

echo "G. … period states (set directly — no transition exists before P5)\n";
$setState('2027-28', '2027-09-15', PeriodState::Closed->value);
$em = $wireDi();
check('G24 a closed period refuses everything, tax or not → period-closed', $refused($em, $saleRequest('2027-09-15', 'x', 'k-closed')) === PostingRefusedException::PERIOD_CLOSED
    && $refused($em, $transferRequest('2027-09-15', 'x')) === PostingRefusedException::PERIOD_CLOSED);
$setState('2027-28', '2027-09-15', PeriodState::VatSettled->value);
$em = $wireDi();
check('G25 a VAT-settled period refuses a TAX line → period-vat-settled …', $refused($em, $saleRequest('2027-09-15', 'x', 'k-settled')) === PostingRefusedException::PERIOD_VAT_SETTLED);
$ref = $post($em, $transferRequest('2027-09-15', 'Umbuchung im abgerechneten Monat'), 'buchhalter');
check('G26 … and accepts an entry without one (number 6)', $ref->number === 6);
check('G27 the neighbouring open period is unaffected', $refused($em, $saleRequest('2027-10-01', 'x', 'k-open')) === null && $count('journal_entry') === 7);
check('G28 nothing was drawn for the refusals: range at 7', (string) $range('journal-entry.2027-28') === '7');
$setState('2027-28', '2027-09-15', PeriodState::Open->value);

// ── H. LedgerService::reverse() ──────────────────────────────────────────

echo "H. LedgerService::reverse()\n";
$em      = $wireDi();
$reverse = fn(EntryRef $ref, string $date, string $reason, string $actor = 'buchhalter') => $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, $actor))->reverse($ref, day($date), $reason));
$sale    = new EntryRef('2027-28', 1);
check('H1 reverse() OUTSIDE a unit of work is refused', throws(fn() => (new LedgerService($em, 'x'))->reverse($sale, day('2027-09-01'), 'r'), \LogicException::class));
$e = caught(fn() => $reverse($sale, '2027-09-01', '  '), ReversalRefusedException::class);
check('H2 an empty reason → no-reason', $e?->reason === ReversalRefusedException::NO_REASON);
$e = caught(fn() => $reverse(new EntryRef('2027-28', 999), '2027-09-01', 'r'), ReversalRefusedException::class);
check('H3 an unknown entry → not-found (also for an unknown year code)', $e?->reason === ReversalRefusedException::NOT_FOUND && caught(fn() => $reverse(new EntryRef('nope', 1), '2027-09-01', 'r'), ReversalRefusedException::class)?->reason === ReversalRefusedException::NOT_FOUND);
$e = caught(fn() => $reverse(new EntryRef('2027-28', 3), '2027-09-01', 'r'), ReversalRefusedException::class);
check('H4 a MANUAL entry is not reversed → manual (edit or delete it)', $e?->reason === ReversalRefusedException::MANUAL);
check('H4b a reason longer than the text column → reason-too-long (validated here, not by the DTO)', caught(fn() => $reverse($sale, '2027-09-01', str_repeat('x', 256)), ReversalRefusedException::class)?->reason === ReversalRefusedException::REASON_TOO_LONG);
$setState('2027-28', '2027-09-15', PeriodState::Closed->value);
$em = $wireDi();
$e  = caught(fn() => $reverse($sale, '2027-09-15', 'r'), PostingRefusedException::class);
check('H5 a reversal dated into a closed period → period-closed (the same rules as any posting); nothing written', $e?->reason === PostingRefusedException::PERIOD_CLOSED && $count('journal_entry') === 7);
$setState('2027-28', '2027-09-15', PeriodState::Open->value);
$em = $wireDi();
$rev = $reverse($sale, '2027-09-01', 'Storno Rechnung 1: Betrag falsch');
$row = $entryRow('2027-28', $rev->number);
$lines = $lineRows((int) $row['id']);
check('H6 the reversal: number 8, generated, text = the reason, reversal_of = entry 1, origin inherited, key reversal:2027-28/1, created_by set', $rev->number === 8 && $row['kind'] === 'generated' && $row['text'] === 'Storno Rechnung 1: Betrag falsch'
    && (int) $row['reversal_of_id'] === (int) $entryRow('2027-28', 1)['id'] && $row['source_type'] === 'invoice' && $row['source_ref'] === 'INV-1' && $row['idempotency_key'] === 'reversal:2027-28/1' && $row['created_by'] === 'buchhalter');
check('H7 lines swapped: 1020 credit 108.10, 3200 debit 100.00 with tax base −100.00 / amount −8.10 (code and rate kept), 2200 debit 8.10', array_column($lines, 'account_number') === ['1020', '3200', '2200']
    && $lines[0]['credit'] === '108.10' && $lines[0]['debit'] === '0.00' && $lines[1]['debit'] === '100.00' && $lines[1]['tax_code'] === 'UN' && (int) $lines[1]['tax_rate'] === 810
    && $lines[1]['tax_base'] === '-100.00' && $lines[1]['tax_amount'] === '-8.10' && $lines[2]['debit'] === '8.10');
$sumUn = fn(string $column) => $db->fetchOne("SELECT SUM(l.{$column}) FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id WHERE l.tax_code = 'UN' AND e.number IN (1, 8) AND e.fiscal_year_id = (SELECT id FROM fiscal_year WHERE code = '2027-28')");
check('H8 Σ tax base and Σ tax amount over entry 1 and its reversal cancel out (what the VAT return will sum) — got ' . $sumUn('tax_base') . ' / ' . $sumUn('tax_amount'), (string) $sumUn('tax_base') === '0.00' && (string) $sumUn('tax_amount') === '0.00');
$em = $wireDi();
$e  = caught(fn() => $reverse($sale, '2027-09-02', 'nochmals'), ReversalRefusedException::class);
check('H9 reversing twice → already-reversed, naming the reversal', $e?->reason === ReversalRefusedException::ALREADY_REVERSED && str_contains($e->getMessage(), '2027-28/8'));
$e = caught(fn() => $reverse($rev, '2027-09-02', 'Storno des Stornos'), ReversalRefusedException::class);
check('H10 reversing a reversal → is-reversal', $e?->reason === ReversalRefusedException::IS_REVERSAL);
check('H11 the schema refuses a second reversal_of on its own (unique index) — the last line of defence under a race', throws(fn() => $db->executeStatement(
    "INSERT INTO journal_entry (fiscal_year_id, number, entry_date, text, kind, created_by, created_at, reversal_of_id) SELECT fiscal_year_id, 999, entry_date, 'race', 'generated', 'x', NOW(), reversal_of_id FROM journal_entry WHERE number = 8 AND fiscal_year_id = (SELECT id FROM fiscal_year WHERE code = '2027-28')"
), \Doctrine\DBAL\Exception\UniqueConstraintViolationException::class));
check('H12 the repository finds the reversal of entry 1, and entry 1 is not a reversal itself', (function () use ($em) {
    $repo  = $em->getRepository(JournalEntry::class);
    $first = $repo->findByRef(new EntryRef('2027-28', 1));
    $eight = $repo->findByRef(new EntryRef('2027-28', 8));
    return $repo->findReversalOf($first)?->getNumber() === 8 && !$first->isReversal() && $eight->isReversal() && $eight->getReversalOf()->getNumber() === 1 && $repo->findReversalOf($eight) === null
        && $repo->withLines((int) $eight->getId())?->getNumber() === 8;
})());
check('H13 nothing else was written: 8 entries, range at 8', $count('journal_entry') === 8 && (string) $range('journal-entry.2027-28') === '8');

// ── I. manual entries: edit and delete with change log ───────────────────

echo "I. Manual entries (ManualEntryService)\n";
$em      = $wireDi();
$manual  = new ManualEntryService($em, 'buchhalter');
$entries = $em->getRepository(JournalEntry::class);
$changes = $em->getRepository(EntryChange::class);
check('I1 create() inside an open unit of work is refused (it owns its own)', throws(fn() => $em->getTransaction(JournalEntry::class)->run(fn() => $manual->create($expenseRequest('2027-10-05', 'x'))), \LogicException::class) && !$em->getTransaction(JournalEntry::class)->isOpen());
check('I2 create() refuses a GENERATED request', throws(fn() => $manual->create($saleRequest('2027-10-05', 'x', 'k-manual')), \LogicException::class));
$mRef = $manual->create($expenseRequest('2027-10-05', 'Büromaterial Oktober'));
$m    = $entries->findByRef($mRef);
check('I3 create(): number 9, manual, three lines, actor stamped, no change row yet', $mRef->number === 9 && $m !== null && $m->isManual() && count($m->getLines()) === 3 && $m->getCreatedBy() === 'buchhalter' && $m->getChangedBy() === null && $count('journal_entry_change') === 0);
$oldLineIds = array_column($lineRows((int) $m->getId()), 'id');

$gen = $entries->findByRef(new EntryRef('2027-28', 2));
$mId = (int) $m->getId();
$e   = caught(fn() => $manual->update((int) $gen->getId(), $gen->getVersion(), $transferRequest('2027-08-16', 'x')), EntryNotEditableException::class);
check('I4 update() of a GENERATED entry → generated (in the domain); delete() the same', $e?->reason === EntryNotEditableException::GENERATED && caught(fn() => $manual->delete((int) $gen->getId(), $gen->getVersion()), EntryNotEditableException::class)?->reason === EntryNotEditableException::GENERATED);
$gen = $entries->findByRef(new EntryRef('2027-28', 2));   // re-read: the refused units of work above rolled back and replaced the EntityManager
check('I4b … and the entity refuses amend() itself', throws(fn() => $gen->amend(day('2027-08-16'), 'x', $gen->getLines(), 'x', new \DateTimeImmutable()), \LogicException::class));
$e = caught(fn() => $manual->update($mId, 1, $transferRequest('2028-07-01', 'ins nächste Jahr')), EntryNotEditableException::class);
check('I5 a date in ANOTHER fiscal year (1.7.2028 = 2028-29) → fiscal-year-changed; nothing touched', $e?->reason === EntryNotEditableException::FISCAL_YEAR_CHANGED && $entryRow('2027-28', 9)['text'] === 'Büromaterial Oktober' && $count('journal_entry_change') === 0);
check('I6 a date with no fiscal year at all → the posting refusal no-fiscal-year', caught(fn() => $manual->update($mId, 1, $transferRequest('2040-01-01', 'x')), PostingRefusedException::class)?->reason === PostingRefusedException::NO_FISCAL_YEAR);
check('I7 an unknown account in the new version → account-unknown; nothing touched', caught(fn() => $manual->update($mId, 1, PostingRequest::manual(day('2027-10-05'), 'x', [PostingLine::debit('9999', chf('1.00')), PostingLine::credit('1020', chf('1.00'))])), PostingRefusedException::class)?->reason === PostingRefusedException::ACCOUNT_UNKNOWN
    && $entryRow('2027-28', 9)['text'] === 'Büromaterial Oktober' && count($lineRows($mId)) === 3 && (int) $entryRow('2027-28', 9)['version'] === 1);
check('I8 update() inside an open unit of work is refused', throws(fn() => $em->getTransaction(JournalEntry::class)->run(fn() => $manual->update($mId, 1, $transferRequest('2027-10-06', 'x'))), \LogicException::class));
check('I8b a STALE version (0) → EntryConflictException, nothing touched, no change row', throws(fn() => $manual->update($mId, 0, $transferRequest('2027-10-06', 'x')), EntryConflictException::class)
    && throws(fn() => $manual->delete($mId, 7), EntryConflictException::class) && $entryRow('2027-28', 9)['text'] === 'Büromaterial Oktober' && $count('journal_entry_change') === 0);
check('I8c an unknown id → EntryConflictException as well (gone), not a 500', throws(fn() => $manual->update(999999, 1, $transferRequest('2027-10-06', 'x')), EntryConflictException::class) && throws(fn() => $manual->delete(999999, 1), EntryConflictException::class));
// A refused update must leave nothing for the next flush (ADR-039 decision 9).
$acc = new AccountService($em);
$acc->update($em->getRepository(Account::class)->findOneBy(['number' => '6570']), ['name' => 'Informatikaufwand (IT)']);
check('I9 … an unrelated flush afterwards writes none of the refused versions', $entryRow('2027-28', 9)['text'] === 'Büromaterial Oktober' && $entryRow('2027-28', 9)['entry_date'] === '2027-10-05' && count($lineRows((int) $m->getId())) === 3);

echo "I. … a valid edit\n";
// I8's rollback replaced the EntityManager — the entry loaded before it is
// detached (DOCTRINE-TX-004), so it is re-read before the write.
$em      = $wireDi();
$manual  = new ManualEntryService($em, 'buchhalter');
$entries = $em->getRepository(JournalEntry::class);
$changes = $em->getRepository(EntryChange::class);
$m       = $entries->findByRef($mRef);
$newVersion = PostingRequest::manual(day('2027-10-07'), 'Büromaterial Oktober (korrigiert)', [
    PostingLine::debit('6500', chf('200.00'), 'Papier und Toner', 'VM', 810, chf('200.00'), chf('16.20')),
    PostingLine::debit('1170', chf('16.20')),
    PostingLine::credit('1020', chf('216.20')),
]);
$manual->update($mId, 1, $newVersion);
$row   = $entryRow('2027-28', 9);
$lines = $lineRows((int) $row['id']);
$log   = $changes->forEntry((int) $row['id']);
check('I10 the entry: same number 9, new date and text, changed_by/at stamped, created_by kept, version 2', (int) $row['number'] === 9 && $row['entry_date'] === '2027-10-07' && $row['text'] === 'Büromaterial Oktober (korrigiert)' && $row['changed_by'] === 'buchhalter' && $row['changed_at'] !== null && $row['created_by'] === 'buchhalter' && (int) $row['version'] === 2);
check('I11 the lines were REPLACED: three new rows (old ids gone), 200.00 / 16.20 / 216.20', count($lines) === 3 && array_intersect(array_column($lines, 'id'), $oldLineIds) === [] && $lines[0]['debit'] === '200.00' && $lines[0]['tax_amount'] === '16.20' && $lines[2]['credit'] === '216.20');
check('I11b … and the total line count is what 9 entries hold (no orphans left behind)', $count('journal_line') === (int) $db->fetchOne('SELECT SUM(c) FROM (SELECT COUNT(*) c FROM journal_line GROUP BY entry_id) t') && (int) $db->fetchOne('SELECT COUNT(*) FROM journal_line WHERE entry_id NOT IN (SELECT id FROM journal_entry)') === 0);
check('I12 ONE change row: action update, who, when, before = the old version, after = the new one', count($log) === 1 && $log[0]->getAction() === ChangeAction::Update->value && $log[0]->getChangedBy() === 'buchhalter' && $log[0]->getEntryNumber() === 9
    && $log[0]->before()['text'] === 'Büromaterial Oktober' && $log[0]->before()['date'] === '2027-10-05' && $log[0]->before()['lines'][0]['debit'] === '100.00' && $log[0]->before()['lines'][0]['account'] === '6500'
    && $log[0]->after()['text'] === 'Büromaterial Oktober (korrigiert)' && $log[0]->after()['lines'][0]['debit'] === '200.00' && $log[0]->after()['lines'][0]['tax_amount'] === '16.20' && $log[0]->after()['fiscal_year'] === '2027-28');
check('I12b the snapshots carry the audit fields: before created_by, changed_by null; after changed_by set (review M6)', $log[0]->before()['created_by'] === 'buchhalter' && $log[0]->before()['changed_by'] === null && $log[0]->before()['created_at'] !== null
    && $log[0]->after()['changed_by'] === 'buchhalter' && $log[0]->after()['changed_at'] !== null);
check('I13 the snapshots are JSON text with umlauts intact', str_contains((string) $db->fetchOne('SELECT before_snapshot FROM journal_entry_change LIMIT 1'), '"text":"Büromaterial Oktober"'));
$linesOnly = PostingRequest::manual(day('2027-10-07'), 'Büromaterial Oktober (korrigiert)', [
    PostingLine::debit('6500', chf('200.00'), 'Papier und Toner', 'VM', 810, chf('200.00'), chf('16.20')),
    PostingLine::debit('1170', chf('16.20')),
    PostingLine::credit('1000', chf('216.20')),   // only the counter account changes
]);
$manual->update($mId, 2, $linesOnly);
check('I13b a LINES-ONLY change still bumps the version (2 → 3): the header row is always updated (changed_at)', (int) $entryRow('2027-28', 9)['version'] === 3 && $lineRows($mId)[2]['account_number'] === '1000' && count($changes->forEntry($mId)) === 2);

echo "I. … vat-settled and closed\n";
$noTaxRef = $manual->create($transferRequest('2027-11-10', 'Umbuchung November'));
$taxRef   = $manual->create($expenseRequest('2027-11-11', 'Spesen November'));
$setState('2027-28', '2027-11-15', PeriodState::VatSettled->value);
$em      = $wireDi();
$manual  = new ManualEntryService($em, 'buchhalter');
$entries = $em->getRepository(JournalEntry::class);
$noTax   = $entries->findByRef($noTaxRef);
$withTax = $entries->findByRef($taxRef);
/** id + current version of an entry, as the form would carry them. */
$at = fn(JournalEntry $e): array => [(int) $e->getId(), $e->getVersion()];
/** An edit of $e at the version it was read with — through whichever $manual is current. */
$edit = function (JournalEntry $e, PostingRequest $r) use (&$manual): void { $manual->update((int) $e->getId(), $e->getVersion(), $r); };
check('I14 vat-settled: an entry WITH a tax line cannot be edited → period-vat-settled', caught(fn() => $edit($withTax, $transferRequest('2027-11-11', 'x')), EntryNotEditableException::class)?->reason === EntryNotEditableException::PERIOD_VAT_SETTLED);
check('I15 vat-settled: … nor deleted', caught(fn() => $manual->delete(...$at($withTax)), EntryNotEditableException::class)?->reason === EntryNotEditableException::PERIOD_VAT_SETTLED);
check('I16 vat-settled: an entry WITHOUT a tax line cannot GET one → period-vat-settled', caught(fn() => $edit($noTax, $expenseRequest('2027-11-10', 'x')), EntryNotEditableException::class)?->reason === EntryNotEditableException::PERIOD_VAT_SETTLED);
$edit($noTax, $transferRequest('2027-11-12', 'Umbuchung November (Datum korrigiert)'));
check('I17 vat-settled: an entry without a tax line stays editable (old and new without tax)', $entryRow('2027-28', $noTaxRef->number)['entry_date'] === '2027-11-12');
check('I18 vat-settled: moving a no-tax entry INTO the settled period from an open one is allowed; moving a TAX entry into it is not', (function () use ($manual, $entries, $entryRow, $at, $edit) {
    $open = $entries->findByRef(new EntryRef('2027-28', 5));   // Umbuchung B, 21.8., open period, no tax
    $edit($open, PostingRequest::manual(day('2027-11-20'), 'Umbuchung B → November', [PostingLine::debit('1000', chf('50.00')), PostingLine::credit('1020', chf('50.00'))]));
    $tax  = $entries->findByRef(new EntryRef('2027-28', 9));    // Büromaterial, 7.10., open period, tax
    $e    = caught(fn() => $edit($tax, PostingRequest::manual(day('2027-11-20'), 'x', [
        PostingLine::debit('6500', chf('200.00'), null, 'VM', 810, chf('200.00'), chf('16.20')), PostingLine::debit('1170', chf('16.20')), PostingLine::credit('1020', chf('216.20')),
    ])), EntryNotEditableException::class);
    return $entryRow('2027-28', 5)['entry_date'] === '2027-11-20' && $e?->reason === EntryNotEditableException::PERIOD_VAT_SETTLED && $entryRow('2027-28', 9)['entry_date'] === '2027-10-07';
})());
$setState('2027-28', '2027-11-15', PeriodState::Closed->value);
$em      = $wireDi();
$manual  = new ManualEntryService($em, 'buchhalter');
$entries = $em->getRepository(JournalEntry::class);
$noTax   = $entries->findByRef($noTaxRef);
check('I19 closed: nothing — update and delete of a no-tax entry → period-closed', caught(fn() => $edit($noTax, $transferRequest('2027-11-12', 'x')), EntryNotEditableException::class)?->reason === EntryNotEditableException::PERIOD_CLOSED
    && caught(fn() => $manual->delete(...$at($noTax)), EntryNotEditableException::class)?->reason === EntryNotEditableException::PERIOD_CLOSED);
check('I20 closed: moving an entry from an open period INTO the closed one is refused as well', caught(fn() => $edit($entries->findByRef(new EntryRef('2027-28', 4)), $transferRequest('2027-11-21', 'x')), EntryNotEditableException::class)?->reason === EntryNotEditableException::PERIOD_CLOSED);
$setState('2027-28', '2027-11-15', PeriodState::Open->value);

echo "I. … inactive accounts: unchanged lines keep them, new or changed lines do not (review M5)\n";
$em      = $wireDi();
$manual  = new ManualEntryService($em, 'buchhalter');
$entries = $em->getRepository(JournalEntry::class);
$accSvc  = new AccountService($em);
$itRef   = $manual->create(PostingRequest::manual(day('2027-12-03'), 'Informatik Dezember', [PostingLine::debit('6570', chf('30.00'), 'Hosting'), PostingLine::credit('1020', chf('30.00'))]));
$itGen   = $post($em, PostingRequest::generated(day('2027-12-03'), 'Informatik generiert', 'invoice', 'INV-IT', 'invoice:it', [PostingLine::debit('6570', chf('40.00')), PostingLine::credit('1020', chf('40.00'))]), 'buchhalter');
$accSvc->setActive($em->getRepository(Account::class)->findOneBy(['number' => '6570']), false);
$em      = $wireDi();
$manual  = new ManualEntryService($em, 'buchhalter');
$entries = $em->getRepository(JournalEntry::class);
check('M5a post() to the now-inactive account 6570 → account-inactive', $refused($em, PostingRequest::manual(day('2027-12-04'), 'x', [PostingLine::debit('6570', chf('1.00')), PostingLine::credit('1020', chf('1.00'))])) === PostingRefusedException::ACCOUNT_INACTIVE);
$itRev = $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'buchhalter'))->reverse($itGen, day('2027-12-05'), 'Storno auf inaktives Konto'));
check('M5b reverse() MAY post to the inactive account — a counterpart of an existing entry is always possible', $itRev instanceof EntryRef && $lineRows((int) $entryRow('2027-28', $itRev->number)['id'])[0]['account_number'] === '6570');
$it = $entries->findByRef($itRef);
$edit($it, PostingRequest::manual(day('2027-12-03'), 'Informatik Dezember (Text geändert)', [PostingLine::debit('6570', chf('30.00'), 'Hosting'), PostingLine::credit('1020', chf('30.00'))]));
check('M5c a manual edit that KEEPS the 6570 line unchanged (text/date of the entry changed) is allowed', $entryRow('2027-28', $itRef->number)['text'] === 'Informatik Dezember (Text geändert)');
$it = $entries->findByRef($itRef);
$e  = caught(fn() => $edit($it, PostingRequest::manual(day('2027-12-03'), 'x', [PostingLine::debit('6570', chf('35.00'), 'Hosting'), PostingLine::credit('1020', chf('35.00'))])), PostingRefusedException::class);
check('M5d … but a CHANGED line on the inactive account → account-inactive', $e?->reason === PostingRefusedException::ACCOUNT_INACTIVE && $entryRow('2027-28', $itRef->number)['text'] === 'Informatik Dezember (Text geändert)');
$form = new \Z77\Module\Financial\Ui\ManualEntryForm('CHF', $em->getRepository(Account::class), $em->getRepository(TaxCode::class), \Z77\Module\Vat\Services\VatRates::from($em));
$form->keepFrom($entries->findByRef($itRef));
$postBody = fn(string $amount, string $text) => ['date' => '2027-12-03', 'text' => 'Informatik', 'account' => ['6570', '1020', ''], 'debit' => [$amount, '', ''], 'credit' => ['', $amount, ''], 'tax_code' => ['', '', ''], 'line_text' => [$text, '', '']];
check('M5e the form applies the same rule: the unchanged 6570 row passes, a changed one gets the row error', $form->fromPost($postBody('30.00', 'Hosting'))->toRequest() !== null
    && $form->fromPost($postBody('35.00', 'Hosting'))->toRequest() === null && str_contains($form->rowError(0, 'account'), 'inaktiv'));
check('M5f the form: blank rows skipped, a lone row is refused, an unbalanced entry names the difference', (function () use ($form) {
    $form->fromPost(['date' => '2027-12-03', 'text' => 'x', 'account' => ['1000', '', ''], 'debit' => ['10.00', '', ''], 'credit' => ['', '', ''], 'tax_code' => ['', '', ''], 'line_text' => ['', '', '']]);
    $none = $form->toRequest();
    return $none === null && array_filter($form->generalErrors(), fn($m) => str_contains($m, 'Differenz 10.00')) !== [] && array_filter($form->generalErrors(), fn($m) => str_contains($m, 'zwei Zeilen')) !== [];
})());

echo "I. … delete leaves a documented gap\n";
$em      = $wireDi();
$manual  = new ManualEntryService($em, 'buchhalter');
$entries = $em->getRepository(JournalEntry::class);
$changes = $em->getRepository(EntryChange::class);
$victim   = $entries->findByRef(new EntryRef('2027-28', 4));   // Umbuchung A
[$victimId, $victimVersion] = $at($victim);
$before   = $count('journal_entry');
check('I21 delete() inside an open unit of work is refused', throws(fn() => $em->getTransaction(JournalEntry::class)->run(fn() => $manual->delete($victimId, $victimVersion)), \LogicException::class));
$manual->delete($victimId, $victimVersion);
$log = $changes->forEntry($victimId);
check('I22 the entry and its lines are gone; number 4 is a gap', $count('journal_entry') === $before - 1 && $entryRow('2027-28', 4) === false && $db->fetchOne('SELECT COUNT(*) FROM journal_line WHERE entry_id = ?', [$victimId]) == 0);
check('I23 the change row survives the entry: action delete, before = the entry, after = null, number 4 recorded', count($log) === 1 && $log[0]->getAction() === ChangeAction::Delete->value && $log[0]->after() === null && $log[0]->before()['number'] === 4 && $log[0]->before()['text'] === 'Umbuchung A' && $log[0]->getEntryNumber() === 4
    && (int) $db->fetchOne('SELECT entry_id FROM journal_entry_change WHERE action = ?', ['delete']) === $victimId);
check('I23b a STALE delete after the delete (the review\'s M2) → EntryConflictException, still ONE change row', throws(fn() => $manual->delete($victimId, $victimVersion), EntryConflictException::class) && count($changes->forEntry($victimId)) === 1);
check('I23c a STALE edit after the delete (M3) → EntryConflictException, not a foreign-key error', throws(fn() => $manual->update($victimId, $victimVersion, $transferRequest('2027-08-21', 'zu spät')), EntryConflictException::class) && count($changes->forEntry($victimId)) === 1);
check('I24 deletionsForYear() lists the gap', (function () use ($changes, $em) {
    $year = $em->getRepository(FiscalYear::class)->findOneBy(['code' => '2027-28']);
    $del  = $changes->deletionsForYear($year);
    return count($del) === 1 && $del[0]->getEntryNumber() === 4;
})());
$lastBefore = (int) $range('journal-entry.2027-28');
$next = $manual->create($transferRequest('2027-12-01', 'nach der Löschung'));
check('I25 the range does not reuse the gap: the next entry continues the range (' . ($lastBefore + 1) . '), number 4 stays free', $next->number === $lastBefore + 1 && (int) $range('journal-entry.2027-28') === $lastBefore + 1 && $entryRow('2027-28', 4) === false);
check('I26 the change log does not reference the entry table by foreign key (a plain column), so the delete needed no cascade', $db->fetchOne('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$dbName, 'journal_entry_change']) == 0);

echo "I. … two parallel edits of ONE entry (the review's H1/M1)\n";
$raceRef = $manual->create($transferRequest('2027-12-08', 'Wettlauf'));
$race    = $entries->findByRef($raceRef);
[$raceId, $raceVersion] = $at($race);
$rp = []; $rpipes = []; $outcomes = [];
foreach (['a', 'b'] as $w) {
    $rp[$w] = proc_open([PHP_BINARY, __FILE__, '--worker', $base, 'update', (string) $raceId, (string) $raceVersion, $w], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rpipes[$w]);
}
foreach ($rp as $w => $proc) {
    $outcomes[$w] = trim(stream_get_contents($rpipes[$w][1])) . trim(stream_get_contents($rpipes[$w][2]));
    fclose($rpipes[$w][1]); fclose($rpipes[$w][2]); proc_close($proc);
}
sort($outcomes);
$raceLines = $lineRows($raceId);
check('I27 exactly one edit wins, the other gets the conflict — got ' . implode(' / ', $outcomes), $outcomes === ['conflict', 'ok']);
check('I28 the entry has TWO lines (not four), version 2, and exactly ONE update change row', count($raceLines) === 2 && (int) $entryRow('2027-28', $raceRef->number)['version'] === 2 && count($changes->forEntry($raceId)) === 1 && str_starts_with($entryRow('2027-28', $raceRef->number)['text'], 'edited by '));

// ── K. parallel posters stay gapless ─────────────────────────────────────

echo "K. Parallel posters (3 processes into 2028-29, every 4th rolled back)\n";
$workers = 3; $perWorker = 12; $every = 4;
$procs = []; $pipes = [];
for ($w = 0; $w < $workers; $w++) {
    $procs[$w] = proc_open([PHP_BINARY, __FILE__, '--worker', $base, 'post', '2028-29', (string) $perWorker, (string) $every, 'w' . $w], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$w]);
}
$union = []; $clean = true;
foreach ($procs as $w => $proc) {
    $out = stream_get_contents($pipes[$w][1]); $err = stream_get_contents($pipes[$w][2]);
    fclose($pipes[$w][1]); fclose($pipes[$w][2]);
    $code = proc_close($proc);
    $data = json_decode($out, true);
    if ($code !== 0 || !is_array($data)) {
        $clean = false;
        echo "       worker {$w}: exit {$code}\n" . ($err !== '' ? '       stderr: ' . trim($err) . "\n" : '') . ($out !== '' ? '       stdout: ' . trim(substr($out, 0, 600)) . "\n" : '');
        continue;
    }
    check("K1 worker {$w} kept " . count($data['kept']) . ', rolled back ' . $data['rolledBack'], count($data['kept']) === $perWorker - intdiv($perWorker, $every) && $data['rolledBack'] === intdiv($perWorker, $every));
    $union = array_merge($union, $data['kept']);
}
sort($union);
$expected = $workers * ($perWorker - intdiv($perWorker, $every));
check('K2 every worker exited clean', $clean);
check("K3 the union of kept numbers is exactly 1..{$expected} — gapless, no duplicate, under real concurrency with rollbacks", $union === range(1, $expected));
check('K4 the range row and the table agree', (int) $range('journal-entry.2028-29') === $expected && (int) $db->fetchOne("SELECT COUNT(*) FROM journal_entry WHERE fiscal_year_id = (SELECT id FROM fiscal_year WHERE code = '2028-29')") === $expected);

echo "K. … a reversal dated in the NEXT fiscal year (review P12)\n";
$em   = $wireDi();
$next = $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'buchhalter'))->reverse(new EntryRef('2027-28', 2), day('2028-07-15'), 'Storno im Folgejahr'));
check('K5 allowed: the reversal takes the next number of the year it is DATED in (2028-29/' . ($expected + 1) . '), links back across years', $next->fiscalYear === '2028-29' && $next->number === $expected + 1
    && (int) $entryRow('2028-29', $next->number)['reversal_of_id'] === (int) $entryRow('2027-28', 2)['id'] && $em->getRepository(JournalEntry::class)->findReversalOf($em->getRepository(JournalEntry::class)->findByRef(new EntryRef('2027-28', 2)))?->getFiscalYear()->getCode() === '2028-29');

// ── L. the journal screen: reflection guards ─────────────────────────────

echo "L. Journal trait (reflection)\n";
$journalActions = array_values(array_filter($methodsOf(JournalControllerTrait::class), fn($m) => str_ends_with($m, 'Action')));
sort($journalActions);
check('L1 the journal trait: list, detail, add, edit, confirm-delete, delete — no reverse action (the source module reverses)', $journalActions === ['addAction', 'confirmDeleteAction', 'deleteAction', 'detailAction', 'editAction', 'listAction']);
$journalSource = file_get_contents($package . '/src/Ui/JournalControllerTrait.php');
check('L2 the trait never persists or mutates an entry itself — every write goes through ManualEntryService with id + version (source guard)', !str_contains($journalSource, '->persist(') && !str_contains($journalSource, '->amend(') && !str_contains($journalSource, '->remove(')
    && str_contains($journalSource, '->update($id, $version, $posting)') && str_contains($journalSource, '->delete($id, $version)') && str_contains($journalSource, 'EntryConflictException'));
check('L3 add and edit are page-mode form posts guarded by #[Csrf]; delete is a Fetch POST', preg_match_all('/^\s+#\[Csrf\]\s*$/m', $journalSource) === 2 && str_contains($journalSource, "#[Fetch, HttpMethod('POST')]"));
check('L4 LedgerService has post, reverse and listLimit — and no accountExists yet (no production caller; see financial.md pending)', !in_array('accountExists', $methodsOf(LedgerService::class), true) && in_array('post', $methodsOf(LedgerService::class), true) && in_array('reverse', $methodsOf(LedgerService::class), true));
check('L5 JournalEntry has no public setter; JournalLine and EntryChange none at all', array_filter((new \ReflectionClass(JournalEntry::class))->getMethods(\ReflectionMethod::IS_PUBLIC), fn(\ReflectionMethod $m) => str_starts_with($m->getName(), 'set')) === []
    && !$hasAny($methodsOf(JournalLine::class), ['set']) && !$hasAny($methodsOf(EntryChange::class), ['set']));

echo "\n" . ($fail === 0 ? "PASS — {$pass} checks" : "FAIL — {$fail} of " . ($pass + $fail) . " checks") . "\n";
exit($fail === 0 ? 0 : 1);
