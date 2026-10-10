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
 * Part 2 (G–L): the journal, LedgerService, manual entries with change log.
 * Part 3 (R, P): the reports — a known data set in its own year (a
 * reversal, an edited and a deleted manual entry) with every figure checked
 * by hand: trial balance, balance sheet = income statement result, account
 * statement across a page boundary, the journal report, the date edges, the
 * screens rendered through the trait, the fragments' own header slots, a
 * no-float source guard; then 20'000 SQL-inserted lines for timing, EXPLAIN
 * and the page boundary at the production page size (P), and a chart cycle
 * written past the validator that must not hide a line (C).
 * FIN-FY-002 (Y): deleting a wrongly opened fiscal year — only the latest,
 * only while nothing was ever posted in it; year, periods and range in one
 * unit of work, re-openable with the same code.
 * FIN-TYPE-001 (T): an account's type is locked once a line of it lies in a
 * closed period, «becomes a group» once it carries any line; name and
 * active stay free; the edit form disables what is locked; two forced
 * two-process races (posting in flight vs. «becomes a group», and the
 * reverse) and the cost of the checks at the volume of P.
 *
 * The one-line entry (O, P2 exit check 3a/3b): the gross split with the tax
 * from VatCalculator's gross mode (input and output codes, 2023 vs 2024
 * rates, the wdv 28.60 case), the voucher correction within 1.00, zero /
 * exempt codes without a tax line, the mandator's VAT account refused (E2)
 * when missing, a group or malformed, editing an entry of the one-line shape,
 * and the add / add-compound / edit pages rendered through the trait (CSS
 * reveal, no script, no placeholder, the date kept after a save).
 *
 * Closing a fiscal year (YC, P5 part 1, owner 2026-09-30): only the whole
 * year, in order, the close check through the open-work registry (blocking
 * refuses, warnings need the confirmation), every ledger refusal in a
 * closed year, the share-locked re-check of the period after the number,
 * the admin reopen with a reason in reverse order, the protocol, the access
 * config, the list / modals / POSTs through a host double, the journal.
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
    $workerName = $argv[$workerMode === 'post' ? 7 : 6] ?? 'w';   // holdpost / post1: {account} {date} {name}
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

    if ($workerMode === 'holdclose') {
        // P5 part 1 race (section YC): the REAL FiscalYearCloseService closes year $argv[4]; its close
        // check (asked after every lock is held) writes `{base}/holdclose.ready` and returns only once
        // another session is seen WAITING on a fiscal-year share lock (a posting's re-check), or after
        // 20 s — so the close holds its locks while a posting runs into them. Prints `ok waited` /
        // `ok alone`, the refusal reason, or the exception.
        $GLOBALS['workerBase'] = $workerBase;
        $cfg = require $workerBase . '/config/client/database.inc.php';
        $GLOBALS['workerWatcher'] = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $cfg['host'], 'dbname' => $cfg['name'], 'user' => $cfg['user'], 'password' => $cfg['password']]);
        $GLOBALS['workerWaited']  = false;
        eval('final class WorkerPauseCheck implements \\Z77\\Persistence\\Doctrine\\OpenWork\\OpenWorkCheckInterface {
            public function check(string $scope, array $parameters): iterable {
                file_put_contents($GLOBALS["workerBase"] . "/holdclose.ready", "1");
                for ($t = 0; $t < 200; $t++) {
                    if ((int) $GLOBALS["workerWatcher"]->fetchOne("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND INFO LIKE \'%FROM fiscal_year WHERE id =%LOCK IN SHARE MODE%\'") > 0) {
                        usleep(200000);
                        $GLOBALS["workerWaited"] = true;
                        break;
                    }
                    usleep(100000);
                }
                return [];
            }
        }');
        $closer = new \Z77\Module\Financial\Services\FiscalYearCloseService($wem, 'worker-' . $workerName, new \Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks(['period-close' => ['WorkerPauseCheck']]));
        try {
            $closer->close((int) $argv[4]);
            echo $GLOBALS['workerWaited'] ? 'ok waited' : 'ok alone';
        } catch (\Z77\Module\Financial\Services\FiscalYearCloseRefusedException $e) {
            echo $e->reason;
        } catch (\Throwable $e) {
            echo get_class($e) . ': ' . $e->getMessage();
        }
        exit(0);
    }

    if ($workerMode === 'holdpost' || $workerMode === 'post1' || $workerMode === 'holdpostc') {
        // FIN-TYPE-001 races (section T). One manual posting on account $argv[4] dated $argv[5].
        // `holdpost` keeps its unit of work open after post() — the account rows share-locked,
        // the line not yet flushed — writes `{base}/holdpost.ready`, and commits only once
        // another session is seen RUNNING the account lock of AccountService::update() (the
        // statement is still executing = it waits; PROCESSLIST shows the same user's sessions
        // without the PROCESS privilege), or after 20 s: prints `ok waited` / `ok alone`.
        // `post1` just posts: prints `ok` or the refusal reason.
        $ledger  = new \Z77\Module\Financial\Services\LedgerService($wem, 'worker-' . $workerName);
        $request = \Z77\Module\Financial\Ledger\PostingRequest::manual(new \DateTimeImmutable($argv[5]), "race {$workerName}", [
            \Z77\Module\Financial\Ledger\PostingLine::debit($argv[4], $chf('5.00')),
            \Z77\Module\Financial\Ledger\PostingLine::credit('1020', $chf('5.00')),
        ]);
        $cfg     = require $workerBase . '/config/client/database.inc.php';
        $watcher = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $cfg['host'], 'dbname' => $cfg['name'], 'user' => $cfg['user'], 'password' => $cfg['password']]);
        try {
            $waited = $wem->getTransaction(\Z77\Module\Financial\Entities\JournalEntry::class)->run(function () use ($ledger, $request, $workerMode, $workerBase, $watcher): bool {
                $ledger->post($request);
                if ($workerMode === 'post1') {
                    return false;
                }
                file_put_contents($workerBase . '/holdpost.ready', '1');
                // `holdpostc` (P5 part 1): wait for a year CLOSE running into this posting's locks — its
                // lockAll() on the fiscal-year rows or its lockPeriodsOf() on the periods.
                $pattern = $workerMode === 'holdpostc'
                    ? "(INFO LIKE '%FROM fiscal_year ORDER BY start_date FOR UPDATE%' OR INFO LIKE '%FROM fiscal_period%FOR UPDATE%')"
                    : "INFO LIKE '%FROM account WHERE id =%FOR UPDATE%'";
                for ($t = 0; $t < 200; $t++) {
                    if ((int) $watcher->fetchOne("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND {$pattern}") > 0) {
                        usleep(200000);   // let the waiter settle in its wait, then commit
                        return true;
                    }
                    usleep(100000);
                }
                return false;
            });
            echo $workerMode === 'post1' ? 'ok' : ($waited ? 'ok waited' : 'ok alone');
        } catch (\Z77\Module\Financial\Services\PostingRefusedException $e) {
            echo $e->reason;
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
use Z77\Module\Financial\Entities\FiscalYearCloseLog;
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
use Z77\Shared\Paging\Paging;
use Z77\Module\Financial\Reports\ReportRange;
use Z77\Module\Financial\Services\LedgerReports;
use Z77\Module\Financial\Ui\ReportControllerTrait;
use Z77\Module\Financial\Ui\ReportLayout;
use Z77\Module\Financial\Services\AccountNumberChangedException;
use Z77\Module\Financial\Services\AccountService;
use Z77\Module\Financial\Services\ChartNotEmptyException;
use Z77\Module\Financial\Services\EntryConflictException;
use Z77\Module\Financial\Services\EntryNotEditableException;
use Z77\Module\Financial\Services\FiscalYearCloseRefusedException;
use Z77\Module\Financial\Services\FiscalYearCloseService;
use Z77\Module\Financial\Services\FiscalYearNotDeletableException;
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
use Z77\Module\Financial\Ui\RaceFailure;
use Z77\Module\Financial\Validators\AccountValidator;
use Z77\Module\Financial\Validators\FiscalYearValidator;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Shared\Money\Money;
use Z77\Persistence\Doctrine\Bootstrap as DoctrineBootstrap;
use Z77\Persistence\Doctrine\Console\MigrationDirectories;
use Z77\Persistence\Doctrine\Console\MigrationsApplication;
use Z77\Persistence\Doctrine\Entities\NumberRange;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks;
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
$package  = str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-financial'));
// module-mandator carries the VAT accounts since E2 (2026-09-23) — `LedgerService::vatAccountFor()`
// reads its record, so the module is registered next to financial and its migration runs with ours.
$mandatorPackage = str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-mandator'));
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n"
    . "'Z77\\\\Module\\\\Financial\\\\' => ['sourcePaths' => ['{$package}']],\n"
    . "'Z77\\\\Module\\\\Mandator\\\\' => ['sourcePaths' => ['{$mandatorPackage}']],\n"
    . "]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'financial', 'modules' => ['mandator' => [], 'financial' => []]];");
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
        // The fiscal-year selection (FiscalYearSelection) remembers per session: the REAL manager —
        // in the CLI it starts no session, $_SESSION is a plain array that outlives a re-wiring
        // (= one browser session over several requests).
        ->set('SessionManager', \Z77\Core\Session\SessionManager::class, true)
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
check('A0 the module config announces exactly the seven Doctrine entities (the close protocol since P5 part 1)', $config?->get('doctrineEntities') === [Account::class, FiscalYear::class, Period::class, JournalEntry::class, JournalLine::class, EntryChange::class, FiscalYearCloseLog::class]);
$dirs = MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder());
check('A1 the module\'s res/migrations is collected under Z77\\Module\\Financial\\Migrations', ($dirs['Z77\\Module\\Financial\\Migrations'] ?? '') === $package . '/res/migrations');
check('A2 the database is empty', $tables() === []);
[$code, $out] = $run(['command' => 'migrate']);
check('A3 migrate exits 0' . ($code !== 0 ? " — got {$code}: " . trim($out) : ''), $code === 0);
$executed = $db->fetchFirstColumn('SELECT version FROM schema_migration');
check('A4 … the three financial migrations ran; the run ends at the newest of all modules (timestamp order — financial\'s close protocol of P5 part 1)',
    str_contains($out, 'Migrating up to Z77\\Module\\Financial\\Migrations\\Version20260930120000')
    && in_array('Z77\\Module\\Financial\\Migrations\\Version20260922071232', $executed, true) && in_array('Z77\\Module\\Financial\\Migrations\\Version20260922091711', $executed, true)
    && in_array('Z77\\Module\\Financial\\Migrations\\Version20260930120000', $executed, true));
check('A5 account, fiscal_period, fiscal_year, fiscal_year_close_log, journal_entry, journal_entry_change, journal_line exist (plus mandator, number_range and the metadata table)',
    $tables() === ['account', 'fiscal_period', 'fiscal_year', 'fiscal_year_close_log', 'journal_entry', 'journal_entry_change', 'journal_line', 'mandator', 'number_range', MigrationsApplication::STORAGE_TABLE]);
$allUnicode = true;
foreach (['account', 'fiscal_year', 'fiscal_period', 'fiscal_year_close_log', 'journal_entry', 'journal_line', 'journal_entry_change'] as $table) {
    $info = $tableInfo($table);
    $allUnicode = $allUnicode && ($info['TABLE_COLLATION'] ?? '') === 'utf8mb4_unicode_ci' && ($info['ENGINE'] ?? '') === 'InnoDB';
}
check('A6 every module table is utf8mb4_unicode_ci and InnoDB although the database default is general_ci', $allUnicode);
$columns = $db->fetchAllKeyValue('SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL', [$dbName, 'account']);
check('A7 … and so is every string column of account', $columns !== [] && count(array_unique($columns)) === 1 && reset($columns) === 'utf8mb4_unicode_ci');
$fk = fn(string $table) => $db->fetchFirstColumn('SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY 1', [$dbName, $table]);
check('A8 foreign keys: account → account (parent), fiscal_period → fiscal_year, journal_entry → fiscal_year + journal_entry (reversal), journal_line → account + journal_entry; the change log has NONE',
    $fk('account') === ['account'] && $fk('fiscal_period') === ['fiscal_year'] && $fk('fiscal_year') === []
    && $fk('journal_entry') === ['fiscal_year', 'journal_entry'] && $fk('journal_line') === ['account', 'journal_entry'] && $fk('journal_entry_change') === [] && $fk('fiscal_year_close_log') === []);
$uniques = $db->fetchFirstColumn('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND INDEX_NAME <> ? GROUP BY INDEX_NAME ORDER BY 1', [$dbName, 'journal_entry', 'PRIMARY']);
check('A8b journal_entry: unique number per year, unique idempotency key, unique reversal_of (reversed at most once — in the schema)', $uniques === ['uniq_journal_entry_idempotency', 'uniq_journal_entry_number', 'uniq_journal_entry_reversal_of']);
[$code, $out] = $run(['command' => 'migrate']);
check('A9 a second migrate is a no-op', $code === 0 && str_contains($out, 'Already at the latest version'));
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Financial\\Migrations']);
check('A10 diff after migrate reports NO change — mapping and migration agree (money and text snapshot columns included)', $code !== 0 && str_contains($out, 'No changes detected') && count(glob($package . '/res/migrations/Version*.php')) === 3);
[$code, $out] = $run(['command' => 'status']);
check('A11 status lists the module namespace and five executed migrations (package, three of financial, one of mandator)', $code === 0 && str_contains($out, 'Z77\\Module\\Financial\\Migrations') && preg_match('/\| Executed\s+\|\s+5\s+\|/', $out) === 1);

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
// The accounts other modules' configuration names by number (Rule 2): the
// VAT accounts and debtor's account settings (on the MANDATOR record since E2) —
// `3809 Rundungsdifferenzen` was added to the chart for it (owner: rounding
// and discount must stay separable in the reports).
$namedByConfig = ['1170', '1171', '2200', '1100', '3800', '3805', '3809', '6950'];
check('B2b every account another module names by number is in the chart, postable and under a group',
    array_reduce($namedByConfig, fn($ok, $n) => $ok && isset($byNumber[$n]) && $byNumber[$n]->isPostable() && $byNumber[$n]->getParent() !== null, true));
check('B2c 3809 «Rundungsdifferenzen» sits in group 38 next to 3800 and 3805, and is a DIFFERENT account than the discount one',
    ($byNumber['3809'] ?? null)?->getName() === 'Rundungsdifferenzen' && $byNumber['3809']->getParent()?->getNumber() === '38'
    && $byNumber['3809']->getType() === 'revenue');
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

// ── E. accounts never deleted; no year edit, no period transition ────────

echo "E. Accounts: deactivate, never delete; no year edit (a year is deleted only through the guarded path, Y); no period transition\n";
$methodsOf = fn(string $class) => array_map(fn(\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass($class))->getMethods());
$hasAny    = fn(array $methods, array $words) => array_filter($methods, fn($m) => array_filter($words, fn($w) => stripos($m, $w) !== false) !== []) !== [];
check('E1 AccountService has no delete; FiscalYearService has no update or close — its one delete is the guarded one (FIN-FY-002, section Y)', !$hasAny($methodsOf(AccountService::class), ['delete', 'remove'])
    && !$hasAny($methodsOf(FiscalYearService::class), ['remove', 'update', 'close', 'settle']) && in_array('delete', $methodsOf(FiscalYearService::class), true));
check('E2 Period has no state setter — its ONE state change is transitionTo(), called only by FiscalYearCloseService (P5 part 1, source guard over src/); FiscalYear has no public setter — code and dates come with the constructor', !$hasAny($methodsOf(Period::class), ['setState', 'close', 'settle'])
    && in_array('transitionTo', $methodsOf(Period::class), true)
    && (function () use ($package): bool {
        $callers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($package . '/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_contains((string) file_get_contents((string) $file), '->transitionTo(')) { $callers[] = basename((string) $file); }
        }
        return $callers === ['FiscalYearCloseService.php'];
    })()
    && array_filter((new \ReflectionClass(FiscalYear::class))->getMethods(\ReflectionMethod::IS_PUBLIC), fn(\ReflectionMethod $m) => str_starts_with($m->getName(), 'set')) === []);
$accountActions = $methodsOf(AccountControllerTrait::class);
check('E3 the account trait: no delete action; toggle and the KMU adoption exist', !$hasAny($accountActions, ['delete', 'remove']) && in_array('toggleActiveAction', $accountActions, true)
    && in_array('adoptKmuChartAction', $accountActions, true) && in_array('confirmAdoptKmuChartAction', $accountActions, true));
$yearActions = $methodsOf(FiscalYearControllerTrait::class);
$yearActions = array_values(array_filter($yearActions, fn($m) => str_ends_with($m, 'Action')));
sort($yearActions);
check('E4 the fiscal-year trait: list, open, confirm-delete and delete, confirm-close / close and confirm-reopen / reopen (P5 part 1) — no edit', $yearActions === ['closeAction', 'confirmCloseAction', 'confirmDeleteAction', 'confirmReopenAction', 'deleteAction', 'listAction', 'openAction', 'reopenAction']);
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
check('I4b … the entity itself no longer refuses amend() for a generated entry (ADR-042 addendum 2026-10-06: the SOURCE amends it through LedgerService::amend(), K2) — the refusal for the bookkeeper lives in ManualEntryService (I4)',
    !str_contains(file_get_contents($package . '/src/Entities/JournalEntry.php'), 'A generated journal entry is never edited') && str_contains(file_get_contents($package . '/src/Services/ManualEntryService.php'), 'assertManual($entry, \'edited\')'));
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
check('I24 the change log search (the Änderungsprotokoll screen) finds the gap: number 4 of 2027-28, a delete, its entry id kept', (function () use ($changes, $em, $victimId) {
    $year = $em->getRepository(FiscalYear::class)->findOneBy(['code' => '2027-28']);
    $del  = $changes->search(new \Z77\Module\Financial\Repositories\EntryChangeSearch((int) $year->getId(), null, null, 4), 0, 10);
    return count($del) === 1 && $del[0]->getEntryNumber() === 4 && $del[0]->getAction() === ChangeAction::Delete->value && $del[0]->getEntryId() === $victimId
        && !method_exists($changes, 'deletionsForYear');
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

// ── K2. the source changes or removes ITS generated entry in place (ADR-042 addendum 2026-10-06) ──

echo "K2. LedgerService::amend() / retract(): a generated entry changed or removed by its source, logged like a manual edit\n";
$em   = $wireDi();
$aRef = $post($em, $saleRequest('2027-11-02', 'Zahlung Rechnung 7 · Muster', 'amend:a', 'PAY-1'));
$aId  = (int) $entryRow('2027-28', $aRef->number)['id'];
$entriesBefore = $count('journal_entry');
$amendedVersion = PostingRequest::generated(day('2027-11-03'), 'Zahlung Rechnung 7 · Muster (korrigiert)', 'invoice', 'PAY-1', 'amend:a', [
    PostingLine::debit('1020', chf('54.05')),
    PostingLine::credit('3200', chf('50.00'), 'Handelserlös', 'UN', 810, chf('50.00'), chf('4.05')),
    PostingLine::credit('2200', chf('4.05')),
]);
$em   = $wireDi();
$ref2 = $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'kassier'))->amend('amend:a', $amendedVersion));
$rowA = $entryRow('2027-28', $aRef->number);
$lnA  = $lineRows($aId);
$logA = $em->getRepository(EntryChange::class)->forEntry($aId);
check('K2-1 amend(): the same number and id, the new date, text and lines (54.05 / 50.00 / 4.05), changed_by stamped, version bumped, ONE change row update with before and after, no new entry',
    $ref2->fiscalYear === '2027-28' && $ref2->number === $aRef->number && (int) $rowA['id'] === $aId && $rowA['entry_date'] === '2027-11-03' && str_ends_with($rowA['text'], '(korrigiert)')
    && count($lnA) === 3 && $lnA[0]['debit'] === '54.05' && $lnA[1]['credit'] === '50.00' && $lnA[1]['tax_amount'] === '4.05' && $lnA[2]['credit'] === '4.05'
    && $rowA['changed_by'] === 'kassier' && (int) $rowA['version'] === 2 && $rowA['kind'] === 'generated' && $rowA['idempotency_key'] === 'amend:a'
    && count($logA) === 1 && $logA[0]->getAction() === ChangeAction::Update->value && $logA[0]->before()['text'] === 'Zahlung Rechnung 7 · Muster' && str_ends_with($logA[0]->after()['text'], '(korrigiert)')
    && $count('journal_entry') === $entriesBefore);
$em = $wireDi();
$em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'kassier'))->amend('amend:a', $amendedVersion));
check('K2-2 an amend with the same content is a no-op: no second change row, the version unchanged',
    count($em->getRepository(EntryChange::class)->forEntry($aId)) === 1 && (int) $entryRow('2027-28', $aRef->number)['version'] === 2);
$amendReason = function (string $key, PostingRequest $new) use ($wireDi): ?string {
    $em = $wireDi();
    return caught(fn() => $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'kassier'))->amend($key, $new)), EntryNotEditableException::class)?->reason;
};
$otherSource = PostingRequest::generated(day('2027-11-03'), 'x', 'payment', '9', 'amend:a', $amendedVersion->lines);
$otherYear   = PostingRequest::generated(day('2028-08-01'), 'x', 'invoice', 'PAY-1', 'amend:a', $amendedVersion->lines);
check('K2-3 refusals: outside a unit of work (LogicException), a manual request (LogicException), an unknown key (not-found), another source (source-mismatch), a date in another fiscal year (fiscal-year-changed) — the entry untouched',
    throws(fn() => (new LedgerService($wireDi(), 'kassier'))->amend('amend:a', $amendedVersion), \LogicException::class)
    && throws(fn() => ($e = $wireDi())->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($e, 'kassier'))->amend('amend:a', PostingRequest::manual(day('2027-11-03'), 'x', $amendedVersion->lines))), \LogicException::class)
    && $amendReason('amend:nope', $amendedVersion) === EntryNotEditableException::NOT_FOUND
    && $amendReason('amend:a', $otherSource) === EntryNotEditableException::SOURCE_MISMATCH
    && $amendReason('amend:a', $otherYear) === EntryNotEditableException::FISCAL_YEAR_CHANGED
    && (int) $entryRow('2027-28', $aRef->number)['version'] === 2 && count($em->getRepository(EntryChange::class)->forEntry($aId)) === 1);
$em   = $wireDi();
$rRef = $post($em, $saleRequest('2027-11-04', 'wird storniert', 'amend:r', 'PAY-2'));
$em   = $wireDi();
$em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'buchhalter'))->reverse($rRef, day('2027-11-05'), 'Storno'));
$retractReason = function (string $key, string $source = 'invoice') use ($wireDi): ?string {
    $em = $wireDi();
    return caught(fn() => $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'kassier'))->retract($key, $source)), EntryNotEditableException::class)?->reason;
};
check('K2-4 a reversed entry and its reversal are frozen for amend and retract (reversed)',
    $amendReason('amend:r', $saleRequest('2027-11-04', 'y', 'amend:r', 'PAY-2')) === EntryNotEditableException::REVERSED
    && $retractReason('amend:r') === EntryNotEditableException::REVERSED
    && $retractReason('reversal:2027-28/' . $rRef->number) === EntryNotEditableException::REVERSED);
$em  = $wireDi();
$entriesBefore = $count('journal_entry');
$gone = $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'kassier'))->retract('amend:a', 'invoice'));
$logA = $em->getRepository(EntryChange::class)->forEntry($aId);
check('K2-5 retract(): answers the ref; the entry and its lines are gone, its number a gap; a change row delete with the full snapshot (after = null) survives it',
    $gone !== null && $gone->number === $aRef->number && $count('journal_entry') === $entriesBefore - 1 && $entryRow('2027-28', $aRef->number) === false
    && $db->fetchOne('SELECT COUNT(*) FROM journal_line WHERE entry_id = ?', [$aId]) == 0
    && count($logA) === 2 && $logA[1]->getAction() === ChangeAction::Delete->value && $logA[1]->after() === null && (int) $logA[1]->before()['number'] === $aRef->number);
$em = $wireDi();
check('K2-6 retract() of a key nobody carries answers null (nothing to retract); of another source → source-mismatch; outside a unit of work → LogicException',
    $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'kassier'))->retract('amend:a', 'invoice')) === null
    && (function () use ($wireDi, $post, $saleRequest, $retractReason): bool { $e = $wireDi(); $post($e, $saleRequest('2027-11-06', 'bleibt', 'amend:keep', 'PAY-3')); return $retractReason('amend:keep', 'payment') === EntryNotEditableException::SOURCE_MISMATCH; })()
    && throws(fn() => (new LedgerService($wireDi(), 'kassier'))->retract('amend:keep', 'invoice'), \LogicException::class));

// ── L. the journal screen: reflection guards ─────────────────────────────

echo "L. Journal trait (reflection)\n";
$journalActions = array_values(array_filter($methodsOf(JournalControllerTrait::class), fn($m) => str_ends_with($m, 'Action')));
sort($journalActions);
check('L1 the journal trait: list, detail, add (one-line), add-compound (Sammelbuchung), edit, confirm-delete, delete — no reverse action (the source module reverses)', $journalActions === ['addAction', 'addCompoundAction', 'confirmDeleteAction', 'deleteAction', 'detailAction', 'editAction', 'listAction']);
$journalSource = file_get_contents($package . '/src/Ui/JournalControllerTrait.php');
check('L2 the trait never persists or mutates an entry itself — every write goes through ManualEntryService with id + version (source guard)', !str_contains($journalSource, '->persist(') && !str_contains($journalSource, '->amend(') && !str_contains($journalSource, '->remove(')
    && str_contains($journalSource, '->update($id, $version, $posting)') && str_contains($journalSource, '->delete($id, $version)') && str_contains($journalSource, 'EntryConflictException'));
check('L3 add, add-compound and edit are page-mode form posts guarded by #[Csrf]; delete is a Fetch POST', preg_match_all('/^\s+#\[Csrf\]\s*$/m', $journalSource) === 3 && str_contains($journalSource, "#[Fetch, HttpMethod('POST')]"));
check('L4 LedgerService has post, reverse, listLimit and accountExists — whose production caller is the one-line entry (the configured tax account)', in_array('accountExists', $methodsOf(LedgerService::class), true)
    && str_contains(file_get_contents($package . '/src/Ui/OneLineEntryForm.php'), '->accountExists(') && in_array('post', $methodsOf(LedgerService::class), true) && in_array('reverse', $methodsOf(LedgerService::class), true));
check('L5 JournalEntry has no public setter; JournalLine and EntryChange none at all', array_filter((new \ReflectionClass(JournalEntry::class))->getMethods(\ReflectionMethod::IS_PUBLIC), fn(\ReflectionMethod $m) => str_starts_with($m->getName(), 'set')) === []
    && !$hasAny($methodsOf(JournalLine::class), ['set']) && !$hasAny($methodsOf(EntryChange::class), ['set']));

// ═════════════════════════════════════════════════════════════════════════
// Part 3 — the ledger reports (plan §5.5)
// ═════════════════════════════════════════════════════════════════════════
//
// A KNOWN data set in a year of its own (2030-31, 1.7.2030–30.6.2031, the
// next contiguous year), so every expected figure below can be added up by
// hand: entries across accounts and months, a generated sale and its
// REVERSAL, a manual entry EDITED (1'500 → 1'600) and one DELETED.
//
//   1  01.07.2030  manual     1020 / 2800   10'000.00  Kapitaleinlage (first day)
//   2  15.07.2030  generated  1100 / 3200 1'000.00 (UN) + 2200 81.00
//   3  10.08.2030  manual     6500 200.00 (VM) + 1170 16.20 / 1020 216.20
//   4  31.08.2030  generated  1020 / 1100    1'081.00  Zahlung
//   5  05.09.2030  generated  1100 / 3200 500.00 (UN) + 2200 40.50
//   6  30.09.2030  reversal of 5
//   7  01.10.2030  manual     6000 / 1020    1'500.00 → edited to 1'600.00
//   8  15.10.2030  manual     6940 / 1020       20.00 → DELETED (gap)
//   9  20.10.2030  manual     1020 / 6950        5.00  Zins (revenue in class 6)
//  10  30.06.2031  manual     1000 / 1020      300.00  (last day)
//
// Balances: 1000 300.00, 1020 8'969.80, 1100 0.00 (moved), 1170 16.20 —
// Aktiven 9'286.00; 2200 81.00, 2800 10'000.00; revenue 3200 1'000.00 +
// 6950 5.00, expense 6000 1'600.00 + 6500 200.00 → result −795.00;
// Passiven 81.00 + 10'000.00 − 795.00 = 9'286.00.

echo "R. Reports — the known data set in 2030-31\n";
$em = $wireDi();
(new FiscalYearService($em))->open(new FiscalYear('2030-31', day('2030-07-01'), day('2031-06-30')));
$em     = $wireDi();
$manual = new ManualEntryService($em, 'buchhalter');
$r1 = $manual->create(PostingRequest::manual(day('2030-07-01'), 'Kapitaleinlage', [PostingLine::debit('1020', chf('10000.00')), PostingLine::credit('2800', chf('10000.00'))]));
$r2 = $post($em, PostingRequest::generated(day('2030-07-15'), 'Rechnung R-1', 'invoice', 'R-1', 'report:invoice:1', [
    PostingLine::debit('1100', chf('1081.00')), PostingLine::credit('3200', chf('1000.00'), null, 'UN', 810, chf('1000.00'), chf('81.00')), PostingLine::credit('2200', chf('81.00')),
]));
$r3 = $manual->create(PostingRequest::manual(day('2030-08-10'), 'Büromaterial', [
    PostingLine::debit('6500', chf('200.00'), null, 'VM', 810, chf('200.00'), chf('16.20')), PostingLine::debit('1170', chf('16.20')), PostingLine::credit('1020', chf('216.20')),
]));
$r4 = $post($em, PostingRequest::generated(day('2030-08-31'), 'Zahlung R-1', 'payment', 'P-1', 'report:payment:1', [PostingLine::debit('1020', chf('1081.00')), PostingLine::credit('1100', chf('1081.00'))]));
$r5 = $post($em, PostingRequest::generated(day('2030-09-05'), 'Rechnung R-2', 'invoice', 'R-2', 'report:invoice:2', [
    PostingLine::debit('1100', chf('540.50')), PostingLine::credit('3200', chf('500.00'), null, 'UN', 810, chf('500.00'), chf('40.50')), PostingLine::credit('2200', chf('40.50')),
]));
$r6 = $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'buchhalter'))->reverse($r5, day('2030-09-30'), 'Rechnung R-2 storniert'));
$r7 = $manual->create(PostingRequest::manual(day('2030-10-01'), 'Miete Oktober', [PostingLine::debit('6000', chf('1500.00')), PostingLine::credit('1020', chf('1500.00'))]));
$r8 = $manual->create(PostingRequest::manual(day('2030-10-15'), 'Bankspesen', [PostingLine::debit('6940', chf('20.00')), PostingLine::credit('1020', chf('20.00'))]));
$r9 = $manual->create(PostingRequest::manual(day('2030-10-20'), 'Zins', [PostingLine::debit('1020', chf('5.00')), PostingLine::credit('6950', chf('5.00'))]));
$r10 = $manual->create(PostingRequest::manual(day('2031-06-30'), 'Bargeldbezug', [PostingLine::debit('1000', chf('300.00')), PostingLine::credit('1020', chf('300.00'))]));
$entries = $em->getRepository(JournalEntry::class);
$rent    = $entries->findByRef($r7);
$manual->update((int) $rent->getId(), $rent->getVersion(), PostingRequest::manual(day('2030-10-01'), 'Miete Oktober (korrigiert)', [PostingLine::debit('6000', chf('1600.00')), PostingLine::credit('1020', chf('1600.00'))]));
$fee = $entries->findByRef($r8);
$manual->delete((int) $fee->getId(), $fee->getVersion());
check('R0 the data set: numbers 1–10 in 2030-31, 8 deleted, 6 reverses 5, 7 edited', [$r1->number, $r10->number, $r6->number] === [1, 10, 6] && $entryRow('2030-31', 8) === false
    && (int) $entryRow('2030-31', 6)['reversal_of_id'] === (int) $entryRow('2030-31', 5)['id'] && (int) $entryRow('2030-31', 7)['version'] === 2);

$em      = $wireDi();
$reports = new LedgerReports($em);
$fy      = $em->getRepository(FiscalYear::class)->findOneBy(['code' => '2030-31']);
$year    = ReportRange::wholeYear($fy);
$range   = fn(string $from, string $to) => new ReportRange($fy, day($from), day($to));
$dec     = fn(Money $m) => $m->toDecimal();
$byNo    = fn(array $rows) => array_combine(array_map(fn($r) => 'n' . $r->number, $rows), $rows);   // prefixed: a numeric-string key would turn into an int

echo "R. … trial balance\n";
$tb   = $reports->trialBalance($year);
$rows = $byNo($tb->rows);
check('R1 one row per account WITH lines, in chart order — the deleted entry\'s 6940 is absent', array_keys($rows) === ['n1000', 'n1020', 'n1100', 'n1170', 'n2200', 'n2800', 'n3200', 'n6000', 'n6500', 'n6950']);
check('R2 Σ Soll = Σ Haben = 15\'364.20 (nine entries), and the report says so', $dec($tb->totalDebit) === '15364.20' && $dec($tb->totalCredit) === '15364.20' && $tb->isBalanced());
check('R3 Σ Saldo Soll = Σ Saldo Haben = 11\'086.00', $dec($tb->totalDebitBalance) === '11086.00' && $dec($tb->totalCreditBalance) === '11086.00');
check('R4 1020: Soll 11\'086.00, Haben 2\'116.20 (the EDITED 1\'600, not 1\'500; the deleted 20 absent) → Saldo Soll 8\'969.80', $dec($rows['n1020']->debit) === '11086.00' && $dec($rows['n1020']->credit) === '2116.20'
    && $dec($rows['n1020']->debitBalance()) === '8969.80' && $rows['n1020']->creditBalance()->isZero());
check('R5 the reversal pair: 3200 Soll 500 / Haben 1\'500 → Saldo Haben 1\'000; 2200 81; 1100 moved but balances to zero (both saldo columns empty)', $dec($rows['n3200']->debit) === '500.00' && $dec($rows['n3200']->creditBalance()) === '1000.00'
    && $dec($rows['n2200']->creditBalance()) === '81.00' && $dec($rows['n1100']->debit) === '1621.50' && $rows['n1100']->debitBalance()->isZero() && $rows['n1100']->creditBalance()->isZero());
check('R6 natural side: 1020 +8\'969.80 (asset), 2200 +81.00 (liability), 3200 +1\'000.00 (revenue), 6000 +1\'600.00 (expense)', $dec($rows['n1020']->balance()) === '8969.80' && $dec($rows['n2200']->balance()) === '81.00'
    && $dec($rows['n3200']->balance()) === '1000.00' && $dec($rows['n6000']->balance()) === '1600.00');

echo "R. … date-range edges (both ends inclusive)\n";
check('R7 1.7.–1.7.: only the entry ON the first day (10\'000.00)', $dec($reports->trialBalance($range('2030-07-01', '2030-07-01'))->totalDebit) === '10000.00');
check('R8 2.7.2030–29.6.2031: without the first and the last day (15\'364.20 − 10\'000.00 − 300.00)', $dec($reports->trialBalance($range('2030-07-02', '2031-06-29'))->totalDebit) === '5064.20');
check('R9 30.6.2031–30.6.2031: only the entry ON the last day (300.00)', $dec($reports->trialBalance($range('2031-06-30', '2031-06-30'))->totalDebit) === '300.00');
check('R10 the reversal month alone (1.9.–30.9.): sale and reversal cancel on every account', (function () use ($reports, $range) {
    $tb = $reports->trialBalance($range('2030-09-01', '2030-09-30'));
    return $tb->totalDebit->toDecimal() === '1081.00' && array_filter($tb->rows, fn($r) => !$r->balance()->isZero()) === [];
})());
check('R11 a range reaches neither into another year nor backwards (ReportRange refuses)', throws(fn() => new ReportRange($fy, day('2030-06-30'), day('2030-07-31')), \InvalidArgumentException::class)
    && throws(fn() => new ReportRange($fy, day('2030-07-01'), day('2031-07-01')), \InvalidArgumentException::class) && throws(fn() => $range('2030-08-01', '2030-07-31'), \InvalidArgumentException::class));

echo "R. … balance sheet and income statement\n";
$bs = $reports->balanceSheet($year);
$is = $reports->incomeStatement($year);
check('R12 Aktiven 9\'286.00 = Passiven 9\'286.00 (Fremdkapital 81.00 + Eigenkapital 10\'000.00 + result −795.00)', $dec($bs->assets->total) === '9286.00' && $dec($bs->liabilities->total) === '81.00' && $dec($bs->equity->total) === '10000.00'
    && $dec($bs->result) === '-795.00' && $dec($bs->totalLiabilitiesAndEquity()) === '9286.00' && $bs->isBalanced() && $bs->difference()->isZero());
check('R13 the income statement result equals the balance sheet\'s result line (−795.00): Ertrag 1\'005.00, Aufwand 1\'800.00', $is->result()->equals($bs->result) && $dec($is->revenue->total) === '1005.00' && $dec($is->expense->total) === '1800.00');
$assets = array_map(fn($l) => [$l->number, $l->depth, $l->isGroup, $l->amount->toDecimal()], $bs->assets->lines);
check('R14 Aktiven: the group chain top-down with subtotals, accounts under their groups — 1 > 10 > 100 (9\'269.80) > 1000, 1020', $assets[0] === ['1', 0, true, '9286.00'] && $assets[1] === ['10', 1, true, '9286.00']
    && $assets[2] === ['100', 2, true, '9269.80'] && $assets[3] === ['1000', 3, false, '300.00'] && $assets[4] === ['1020', 3, false, '8969.80']);
$assetNumbers = array_column($assets, 0);
check('R15 an account that moved but balances to zero (1100) is shown; groups without a booked account (14 Anlagevermögen) are not', in_array('1100', $assetNumbers, true) && !in_array('14', $assetNumbers, true)
    && array_filter($bs->assets->lines, fn($l) => $l->number === '1100' && $l->amount->isZero()) !== []);
$revenue = array_column(array_map(fn($l) => [$l->number, $l->amount->toDecimal()], $is->revenue->lines), 1, 0);
$expense = array_column(array_map(fn($l) => [$l->number, $l->amount->toDecimal()], $is->expense->lines), 1, 0);
check('R16 the TYPE decides the side: 6950 Finanzertrag stands under Ertrag in its groups 6 > 69 (5.00), and NOT under Aufwand', ($revenue['6950'] ?? null) === '5.00' && ($revenue['69'] ?? null) === '5.00' && ($revenue['6'] ?? null) === '5.00'
    && !isset($expense['6950']) && ($expense['6'] ?? null) === '1800.00' && !isset($expense['69']) && ($revenue['3'] ?? null) === '1000.00');
check('R17 equity is its own block (2800 under 28), liabilities another (2200)', in_array('28', array_map(fn($l) => $l->number, $bs->equity->lines), true) && in_array('2800', array_map(fn($l) => $l->number, $bs->equity->lines), true)
    && in_array('2200', array_map(fn($l) => $l->number, $bs->liabilities->lines), true) && !in_array('2800', array_map(fn($l) => $l->number, $bs->liabilities->lines), true));
$october = $reports->incomeStatement($range('2030-10-01', '2030-10-31'));
check('R18 an income statement over October: Ertrag 5.00, Aufwand 1\'600.00 → −1\'595.00', $dec($october->result()) === '-1595.00');
$bsMid = $reports->balanceSheet($range('2030-10-01', '2030-10-31'));
check('R19 the balance sheet ignores «from» — at 31.10.2030 it reads 1.7.–31.10. (still balanced; the 30.6. cash withdrawal not yet in)', $bsMid->range->fromDay() === '2030-07-01' && $bsMid->isBalanced()
    && $dec($bsMid->assets->total) === '9286.00' && !in_array('1000', array_map(fn($l) => $l->number, $bsMid->assets->lines), true));
$emptyYear = $em->getRepository(FiscalYear::class)->findOneBy(['code' => '2029-30b']);
check('R20 a year without entries: empty trial balance (balanced at zero), empty statements, result 0', (function () use ($reports, $emptyYear) {
    $tb = $reports->trialBalance(ReportRange::wholeYear($emptyYear));
    $bs = $reports->balanceSheet(ReportRange::wholeYear($emptyYear));
    return $tb->rows === [] && $tb->isBalanced() && $tb->totalDebit->isZero() && $bs->assets->lines === [] && $bs->result->isZero() && $bs->isBalanced();
})());

echo "R. … account statement (the page boundary is proven at volume, section P)\n";
$bank  = $em->getRepository(Account::class)->findOneBy(['number' => '1020']);
$page1 = $reports->accountStatement($bank, $year, 1);
$bal   = fn($s) => array_map(fn($l) => $l->balance->toDecimal(), $s->lines);
check('R21 1020 whole year: 6 lines (the deleted entry\'s line gone), one page at the production page size', $page1->paging->total === 6 && $page1->paging->pageCount === 1 && count($page1->lines) === 6
    && $page1->paging->pageSize === LedgerReports::ACCOUNT_STATEMENT_PAGE_SIZE);
check('R22 opening = carry = 0.00; lines 1, 3, 4, 7, 9, 10 in date order; running 10\'000.00 / 9\'783.80 / 10\'864.80 / 9\'264.80 / 9\'269.80 / 8\'969.80', $page1->opening->isZero() && $page1->carry->isZero()
    && array_map(fn($l) => $l->entryNumber, $page1->lines) === [1, 3, 4, 7, 9, 10] && $bal($page1) === ['10000.00', '9783.80', '10864.80', '9264.80', '9269.80', '8969.80']);
check('R23 totals and closing cover the range: Soll 11\'086.00, Haben 2\'116.20, closing 8\'969.80 = the last running balance', $dec($page1->totalDebit) === '11086.00' && $dec($page1->totalCredit) === '2116.20'
    && $dec($page1->closing) === '8969.80');
check('R24 counter accounts: 1 → 2800 (one other account), 3 → «div.» (6500 and 1170), 4 → 1100, 7 → 6000', !$page1->lines[0]->hasSeveralCounterAccounts() && $page1->lines[0]->counterNumber === '2800'
    && $page1->lines[1]->hasSeveralCounterAccounts() && $page1->lines[2]->counterNumber === '1100' && $page1->lines[3]->counterNumber === '6000' && $page1->lines[3]->counterCount === 1);
check('R25 the lines carry the entry id for the link to the detail page, and the entry\'s text (edited text shown)', $page1->lines[3]->entryId === (int) $entryRow('2030-31', 7)['id'] && $page1->lines[3]->text === 'Miete Oktober (korrigiert)');
$mid = $reports->accountStatement($bank, $range('2030-08-11', '2030-10-20'), 1);
check('R26 from 11.8.: opening 9\'783.80 (entries 1 and 3 before «from»), lines 4, 7, 9, closing 9\'269.80', $dec($mid->opening) === '9783.80' && array_map(fn($l) => $l->entryNumber, $mid->lines) === [4, 7, 9] && $dec($mid->closing) === '9269.80');
check('R27 the edges are inclusive: from 10.8. takes entry 3 (dated 10.8.) into the lines, not into the opening', (function () use ($reports, $bank, $range) {
    $s = $reports->accountStatement($bank, $range('2030-08-10', '2030-10-20'), 1);
    return $s->opening->toDecimal() === '10000.00' && $s->lines[0]->entryNumber === 3;
})());
$vat = $reports->accountStatement($em->getRepository(Account::class)->findOneBy(['number' => '2200']), $year, 1);
check('R28 a liability on its natural side (credit positive): 2200 81.00 → 121.50 → 81.00 (the reversal takes it back)', $bal($vat) === ['81.00', '121.50', '81.00'] && $dec($vat->closing) === '81.00' && $vat->lines[2]->entryNumber === 6);
check('R29 a page beyond the last shows the last and reports it (isBeyondLast); an account without lines has one empty page and closing = opening', (function () use ($reports, $bank, $year, $em) {
    $beyond = $reports->accountStatement($bank, $year, 99)->paging;
    $s      = $reports->accountStatement($em->getRepository(Account::class)->findOneBy(['number' => '1021']), $year, 1);
    return $beyond->page === 1 && $beyond->isBeyondLast() && !$reports->accountStatement($bank, $year, 1)->paging->isBeyondLast()
        && $s->lines === [] && $s->paging->pageCount === 1 && $s->closing->isZero();
})());
check('R30 Paging arithmetic: 0 rows → 1 page; 6 rows of 4 → 2 pages, page 2 starts at 4; page 0 → 1 (not «beyond»); page 3 of 2 → beyond', (new Paging(1, 4, 0))->pageCount === 1 && (new Paging(2, 4, 6))->offset() === 4 && (new Paging(0, 4, 6))->page === 1
    && !(new Paging(0, 4, 6))->isBeyondLast() && (new Paging(3, 4, 6))->isBeyondLast() && throws(fn() => new Paging(1, 0, 6), \InvalidArgumentException::class));

echo "R. … journal report\n";
$journal = $reports->journal($year, 1);
check('R31 the year: 9 entries (8 deleted), date order 1 2 3 4 5 6 7 9 10, lines loaded', $journal->paging->total === 9 && array_map(fn($e) => $e->getNumber(), $journal->entries) === [1, 2, 3, 4, 5, 6, 7, 9, 10]
    && count($journal->entries[1]->getLines()) === 3);
check('R32 Σ Soll and Σ Haben are each their OWN sum (15\'364.20 both); the reversal is in with its link', $dec($journal->totalDebit) === '15364.20' && $dec($journal->totalCredit) === '15364.20'
    && $journal->entries[5]->isReversal() && $journal->entries[5]->getReversalOf()->getNumber() === 5);
check('R32b rangeSummary() sums credit from the credit column — on a real imbalance (a SQL-written orphan line) the two totals differ', (function () use ($db, $em, $fy) {
    $entryId = (int) $db->fetchOne("SELECT e.id FROM journal_entry e JOIN fiscal_year y ON y.id = e.fiscal_year_id WHERE y.code = '2030-31' AND e.number = 1");
    $db->executeStatement('INSERT INTO journal_line (position, debit, credit, entry_id, account_id) VALUES (9, 0.00, 0.01, ?, (SELECT id FROM account WHERE number = ?))', [$entryId, '1000']);
    $sum = $em->getRepository(JournalEntry::class)->rangeSummary($fy, '2030-07-01', '2031-06-30');
    $db->executeStatement('DELETE FROM journal_line WHERE entry_id = ? AND position = 9', [$entryId]);
    return $sum['debit'] === '15364.20' && $sum['credit'] === '15364.21';
})());
check('R33 October: 7 and 9 (the deleted 8 absent)', array_map(fn($e) => $e->getNumber(), $reports->journal($range('2030-10-01', '2030-10-31'), 1)->entries) === [7, 9]);
check('R34 the part-2 list keeps its order (newest number first) after the hydrate change', array_map(fn($e) => $e->getNumber(), $em->getRepository(JournalEntry::class)->latestForYear($fy, 3)) === [10, 9, 7]);
check('R34b a reversal dated in a LATER year (K5: 2028-29 reverses 2027-28/2): the reversed entry\'s year comes fetch-joined, no lazy proxy', (function () use ($wireDi) {
    $em   = $wireDi();
    $y    = $em->getRepository(FiscalYear::class)->findOneBy(['code' => '2028-29']);
    $rev  = array_values(array_filter((new LedgerReports($em))->journal(new ReportRange($y, day('2028-07-15'), day('2028-07-15')), 1)->entries, fn($e) => $e->isReversal()));
    $year = $rev[0]->getReversalOf()->getFiscalYear();
    $lazy = $year instanceof \Doctrine\Persistence\Proxy ? !$year->__isInitialized() : (new \ReflectionClass($year))->isUninitializedLazyObject($year);
    return count($rev) === 1 && $year->getCode() === '2027-28' && !$lazy;
})());

echo "R. … the report screens (trait + templates, rendered without a web server)\n";
/**
 * A request double for DI: the trait reads GET parameters only. Every call is a NEW session
 * (the remembered fiscal year cleared) unless $keepSession — the FY-SEL checks follow one session.
 */
$useGet = function (array $get, bool $keepSession = false): void {
    $_GET = $get;
    if (!$keepSession) { $_SESSION = []; }
    DI::getInstance()->set('Request', fn() => new class { public function getGetParameter(string $p): mixed { return $_GET[$p] ?? null; } }, true);
};
/** A host double for the trait: captures the context and the partials the page adds. */
$reportHost = function () {
    return new class {
        use ReportControllerTrait { trialBalanceAction as public; balanceSheetAction as public; incomeStatementAction as public; accountStatementAction as public; journalAction as public; }
        public array $context = [];
        public object $layoutManager;
        public function __construct()
        {
            $this->layoutManager = new class {
                public array $sections = [];
                public function removeSection(string $s): void { unset($this->sections[$s]); }
                public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
            };
        }
        protected function em() { return DI::getUnifiedEntityManager(); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
    };
};
/**
 * Renders a module-financial template with a partial() that resolves in the same
 * tree — and, for a partial of ANOTHER module (the mandator letterhead inside the
 * printed report header), in that module's tree. `TemplateRenderer::partial()` does
 * the same through the FileFinder; here the namespace maps to a directory.
 */
$renderer = new class($package . '/res/view/templates/', dirname($package) . '/module-mandator/res/view/templates/') {
    public function __construct(private string $dir, private string $mandatorDir) {}
    public function partial(string $path, array $context = [], ?string $ns = null): string
    {
        // Same scope rules as TemplateRenderer::renderIsolated(): prefixed locals, EXTR_SKIP.
        $dir = match ($ns) {
            'Z77\\Module\\Mandator' => $this->mandatorDir,
            'Z77\\Shared'           => dirname($this->dir, 4) . '/kernel/shared/res/view/templates/',
            default                 => $this->dir,
        };
        return (function (string $z77TplPath, array $z77TplContext) { extract($z77TplContext, EXTR_SKIP); ob_start(); require $z77TplPath; return ob_get_clean(); })->call($this, $dir . $path . '.tpl.php', $context);
    }
};
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';
$page = function (string $action, array $get, bool $keepSession = false) use ($useGet, $reportHost, $renderer): array {
    $useGet($get, $keepSession);
    $host = $reportHost();
    $host->{$action . 'Action'}();
    $html = '';
    foreach (['tabs', 'railSelect', 'hc1', 'hc2', 'main'] as $section) {
        foreach ($host->layoutManager->sections[$section] ?? [] as $partial) {
            $html .= $renderer->partial($partial, $host->context);
        }
    }
    return [$host->context, $html, $host->layoutManager->sections];
};
$em = $wireDi();
[$ctx, $html, $sections] = $page('trialBalance', ['year' => '2030-31']);
check('R35 trial balance page: the printed header, the report\'s own template and the printed footer in main, the year selection at the TOP OF THE RAIL (railSelect, owner 2026-10-08 — was hc1, before that hc2), the action cell (hc1) empty, the report tabs in the TOOLBAR (hc2, owner 2026-09-29 — was the `tabs` row, which stays empty)', $sections['main'] === ['Backend/ReportController/printHead', 'Backend/ReportController/trialBalance', 'Backend/ReportController/printFoot'] && empty($sections['tabs']) && $sections['railSelect'] === ['Backend/partials/fiscalYearSelect'] && empty($sections['hc1']) && $sections['hc2'] === ['Backend/ReportController/tabs']
    && str_contains(file_get_contents(__DIR__ . '/../packages/module-backend/res/assets/css/base.css'), '.be-shell-band__slot .be-viewtabs{margin-block:')
    && !file_exists($package . '/res/view/templates/Backend/ReportController/yearSwitch.tpl.php'));
check('R36 … renders the totals the Swiss way (15\'364.20), «Soll = Haben», a link to the account statement with the range', str_contains($html, "15&apos;364.20") && str_contains($html, 'Soll = Haben')
    && str_contains($html, '/backend/finance/report/account-statement?account=1020&amp;year=2030-31&amp;from=2030-07-01&amp;to=2031-06-30'));
[$ctx, $html] = $page('trialBalance', []);
check('R37 no parameters: the year containing today, else the latest (currentOrLatest()), whole year, no notice', $ctx['range']->year->getCode() === $em->getRepository(FiscalYear::class)->currentOrLatest()->getCode()
    && $ctx['range']->fromDay() === $ctx['range']->year->getStartDate()->format('Y-m-d') && $ctx['range']->toDay() === $ctx['range']->year->getEndDate()->format('Y-m-d') && $ctx['notices'] === []);
[$ctx] = $page('trialBalance', ['year' => '2030-31', 'from' => '2029-01-01', 'to' => 'gestern']);
check('R38 a date outside the year or not a date falls back to the year\'s bound and SAYS so (two notices); an unknown year too', $ctx['range']->fromDay() === '2030-07-01' && $ctx['range']->toDay() === '2031-06-30' && count($ctx['notices']) === 2
    && count($page('trialBalance', ['year' => 'nope'])[0]['notices']) === 1 && $page('trialBalance', ['from' => ['x']])[0]['notices'] === []);
[$ctx] = $page('trialBalance', ['year' => '2030-31', 'from' => '2030-10-01', 'to' => '2030-09-01']);
check('R39 «from» after «to» → the whole year, with a notice', $ctx['range']->fromDay() === '2030-07-01' && $ctx['range']->toDay() === '2031-06-30' && count($ctx['notices']) === 1);
[$ctx, $html] = $page('balanceSheet', ['year' => '2030-31']);
check('R40 balance sheet page: Aktiven = Passiven, the result line «Verlust laufendes Jahr» −795.00, «Stichtag» instead of from/to, and the missing-opening note (not the first year)', str_contains($html, 'Aktiven = Passiven')
    && str_contains($html, 'Verlust laufendes Jahr') && str_contains($html, '−795.00') && str_contains($html, 'Stichtag') && !str_contains($html, 'name="from"') && str_contains($html, 'Ohne Eröffnungsbuchung'));
[$ctx, $html] = $page('incomeStatement', ['year' => '2030-31']);
check('R41 income statement page: Ertrag 1\'005.00, Aufwand 1\'800.00, Verlust −795.00', str_contains($html, "1&apos;005.00") && str_contains($html, "1&apos;800.00") && str_contains($html, 'Verlust (Ertrag − Aufwand)'));
[$ctx, $html] = $page('accountStatement', ['year' => '2030-31']);
check('R42 account statement without an account: the form with the postable accounts, no report', $ctx['report'] === null && str_contains($html, 'Konto wählen') && str_contains($html, '<option value="1020"'));
[$ctx, $html] = $page('accountStatement', ['year' => '2030-31', 'account' => '1020']);
check('R43 … with 1020: «Anfangssaldo», «div.», the closing 8\'969.80, and the entry number linking to the journal detail', str_contains($html, 'Anfangssaldo') && str_contains($html, 'div.') && str_contains($html, "8&apos;969.80")
    && str_contains($html, '/backend/finance/journal/detail?id=' . $entryRow('2030-31', 7)['id']));
check('R44 … the year selection (hc1 dropdown) keeps the account and drops the dates; an unknown account says so', str_contains($html, '/backend/finance/report/account-statement?year=2030-31&amp;account=1020"')
    && str_contains($page('accountStatement', ['year' => '2030-31', 'account' => '4711'])[1], 'gibt es nicht'));
[$ctx, $html] = $page('journal', ['year' => '2030-31', 'from' => '2030-09-01', 'to' => '2030-09-30']);
check('R45 journal page: September — the sale and its reversal with «Storno von 2030-31/5», the total line', str_contains($html, 'Rechnung R-2') && str_contains($html, 'Storno von 2030-31/5') && str_contains($html, 'Total Zeitraum (2 Buchungen)'));
[$ctx, $html] = $page('accountStatement', ['year' => '2030-31', 'account' => '1020', 'page' => '5']);
check('R45b ?page=5 of 1: the last page is shown AND a notice says so (like the date fallbacks)', $ctx['report']->paging->page === 1 && count($ctx['notices']) === 1
    && str_contains($ctx['notices'][0], 'Seite 5 gibt es nicht') && str_contains($html, 'Seite 5 gibt es nicht'));
check('R45c the journal page shows Σ Haben from its own sum next to Σ Soll', $page('journal', ['year' => '2030-31'])[0]['report']->totalCredit->toDecimal() === '15364.20');

echo "R. … fragment slots: every financial screen adds its own header slots (review L5)\n";
/** Host doubles for the part-1/2 traits — the same layout double as the reports. */
$listHost = function (string $trait) {
    $layout = new class {
        public array $sections = [];
        public function removeSection(string $s): void { unset($this->sections[$s]); }
        public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
    };
    $host = match ($trait) {
        'journal' => new class { use JournalControllerTrait { listAction as public; } public array $context = []; public object $layoutManager;
            public object $help; public function __construct() { $this->help = new \Z77\Core\Services\HelpService(); }
            protected function em() { return DI::getUnifiedEntityManager(); }
            protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); } },
        'account' => new class { use AccountControllerTrait { listAction as public; } public array $context = []; public object $layoutManager;
            protected function em() { return DI::getUnifiedEntityManager(); }
            protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); } },
        'fiscal-year' => new class { use FiscalYearControllerTrait { listAction as public; } public array $context = []; public object $layoutManager;
            protected function fiscalYearCanReach(string $action): bool { return true; }
            protected function em() { return DI::getUnifiedEntityManager(); }
            protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); } },
    };
    $host->layoutManager = $layout;
    $host->listAction();
    return $host;
};
$slotHtml = fn($host, string $slot) => implode('', array_map(fn($p) => $renderer->partial($p, $host->context), $host->layoutManager->sections[$slot] ?? []));
$useGet(['year' => '2030-31']);
$journalHost = $listHost('journal');
check('R51 journal page (FIN-JOURNAL-CAPTURE-001): main = the capture form + the list, the action cell (hc1) = «Buchen» (green confirm, ADR-033 rev. 2026-10-08), the toolbar (hc2) = Einzel|Sammel tabs (MwSt is a switch in the capture row since 2026-10-08, not here), the crumb line (hc3) = the year of ?year=2030-31 — all from module-financial; the top of the rail holds the year selection (railSelect, owner 2026-10-08)',
    $journalHost->layoutManager->sections['railSelect'] === ['Backend/partials/fiscalYearSelect']
    && $journalHost->layoutManager->sections['main'] === ['Backend/JournalController/oneLine', 'Backend/JournalController/listAction']
    && $journalHost->layoutManager->sections['hc1'] === ['Backend/JournalController/captureAct']
    && $journalHost->layoutManager->sections['hc2'] === ['Backend/JournalController/captureTools']
    && $journalHost->layoutManager->sections['hc3'] === ['Backend/JournalController/crumb']
    && $journalHost->context['year']->getCode() === '2030-31'
    && str_contains($slotHtml($journalHost, 'hc1'), 'form="journal-capture"') && str_contains($slotHtml($journalHost, 'hc1'), 'be-btn--confirm') && str_contains($slotHtml($journalHost, 'hc1'), '#icon-check')
    && !str_contains($slotHtml($journalHost, 'hc2'), 'form="journal-capture"') && str_contains($slotHtml($journalHost, 'hc2'), '<nav class="be-viewtabs"')
    && !str_contains($slotHtml($journalHost, 'hc2'), 'journal-vat') && str_contains($slotHtml($journalHost, 'hc2'), 'mode=sammel')
    && str_contains($slotHtml($journalHost, 'hc3'), '2030-31') && !str_contains($slotHtml($journalHost, 'hc2'), 'add?year='));
$accountHost = $listHost('account');
$yearHost    = $listHost('fiscal-year');
check('R52 account list and fiscal-year list: the action cell (hc1, ADR-033 rev. 2026-10-08 — was the toolbar) from the fragment: «+ Konto» / «+ Geschäftsjahr», primary, plus glyph, the dialog fetch link; the toolbar stays empty',
    $accountHost->layoutManager->sections['hc1'] === ['Backend/AccountController/addButton'] && empty($accountHost->layoutManager->sections['hc2'])
    && str_contains($slotHtml($accountHost, 'hc1'), 'data-fetch-get="/backend/finance/account/add"') && str_contains($slotHtml($accountHost, 'hc1'), 'be-btn--primary') && str_contains($slotHtml($accountHost, 'hc1'), '<span class="be-btn__label">Konto</span>')
    && $yearHost->layoutManager->sections['hc1'] === ['Backend/FiscalYearController/openButton'] && empty($yearHost->layoutManager->sections['hc2'])
    && str_contains($slotHtml($yearHost, 'hc1'), 'data-fetch-get="/backend/finance/fiscal-year/open"') && str_contains($slotHtml($yearHost, 'hc1'), '#icon-plus') && str_contains($slotHtml($yearHost, 'hc1'), '<span class="be-btn__label">Geschäftsjahr</span>'));
check('R53 the host carries no slot template for a financial screen any more (module-backend Finance/{Account,FiscalYear,Journal,Report}Controller)',
    glob(__DIR__ . '/../packages/module-backend/res/view/templates/Finance/{Account,FiscalYear,Journal,Report}Controller/*', GLOB_BRACE) === []);
$useGet([]);
check('R54 one default-year rule: with no ?year the journal list and the reports open on currentOrLatest() — the year containing today, else the latest', (function () use ($listHost, $page, $em) {
    $expected = $em->getRepository(FiscalYear::class)->currentOrLatest()?->getCode();
    $today    = $em->getRepository(FiscalYear::class)->findByDate(new \DateTimeImmutable('today'))?->getCode();
    return $expected !== null && ($today === null || $today === $expected)
        && $listHost('journal')->context['year']->getCode() === $expected && $page('trialBalance', [])[0]['range']->year->getCode() === $expected;
})());

echo "R. … guards\n";
$reportSources = array_merge(glob($package . '/src/Reports/*.php'), [$package . '/src/Services/LedgerReports.php', $package . '/src/Repositories/JournalLineRepository.php', $package . '/src/Ui/ReportControllerTrait.php'], glob($package . '/res/view/templates/Backend/ReportController/*.php'));
$floaty = array_filter($reportSources, fn($f) => preg_match('/\(float\)|floatval\(|[?:(,]\s*float\b|\bfloat\s+\$|number_format\(|\bround\(|\/\s*100\b/',file_get_contents($f)) === 1);
check('R46 no float anywhere in the report path — no (float), floatval, float type, number_format, round(), «/ 100» (source guard over ' . count($reportSources) . ' files)' . ($floaty ? ' — found in ' . implode(', ', array_map('basename', $floaty)) : ''), $floaty === [] && count($reportSources) >= 20);
$sqlSource = file_get_contents($package . '/src/Repositories/JournalLineRepository.php');
check('R47 the report SQL binds every value — no interpolation or concatenation into a statement', !str_contains($sqlSource, '{$') && preg_match("/'\s*\.\s*\\$/", $sqlSource) === 0
    && !str_contains(file_get_contents($package . '/src/Repositories/JournalEntryRepository.php'), "BETWEEN ? AND ? ORDER BY entry_date, number LIMIT ' ."));
check('R48 the aggregates are SQL over journal_line: LedgerReports never hydrates a JournalLine (no findBy/findAll/getLines)', !preg_match('/->(findBy|findAll|getLines)\(/', file_get_contents($package . '/src/Services/LedgerReports.php')));
$reportActions = array_values(array_filter($methodsOf(ReportControllerTrait::class), fn($m) => str_ends_with($m, 'Action')));
sort($reportActions);
$traitSource = file_get_contents($package . '/src/Ui/ReportControllerTrait.php');
check('R49 the report trait: five read-only pages — no POST, no CSRF, no write, no JavaScript', $reportActions === ['accountStatementAction', 'balanceSheetAction', 'incomeStatementAction', 'journalAction', 'trialBalanceAction']
    && !preg_match('/#\[(Csrf|Fetch|HttpMethod)/', $traitSource) && !preg_match('/->(persist|remove|flush|run)\(/', $traitSource)
    && array_filter(glob($package . '/res/view/templates/Backend/ReportController/*.php'), fn($f) => preg_match('/<script|data-fetch|onclick/i', file_get_contents($f)) === 1) === []);
$backendConfig = require __DIR__ . '/../packages/module-backend/src/App/Config/backendConfig.inc.php';
check('R50 the host: backend Finance/ReportController uses the trait, its layout delegates to ReportLayout, default action trial-balance', in_array(ReportControllerTrait::class, class_uses(\Z77\Module\Backend\Ui\Controllers\Finance\ReportController::class), true)
    && (require __DIR__ . '/../packages/module-backend/src/Ui/Config/Finance/reportControllerConfig.inc.php') === ReportLayout::config()
    && ($backendConfig['controllers']['finance']['ReportController']['defaultAction'] ?? null) === 'trial-balance');

// ── P. volume: 20'000 lines ──────────────────────────────────────────────

echo "P. Volume (10'000 entries / 20'000 lines, SQL-inserted into 2031-32)\n";
$em = $wireDi();
(new FiscalYearService($em))->open(new FiscalYear('2031-32', day('2031-07-01'), day('2032-06-30')));
$fyId = (int) $db->fetchOne("SELECT id FROM fiscal_year WHERE code = '2031-32'");
$acc  = fn(string $n) => (int) $db->fetchOne('SELECT id FROM account WHERE number = ?', [$n]);
$t0   = microtime(true);
// Synthetic, straight into the tables (not through the ledger — that is not what is measured):
// entries 1…10'000 over the year, each debit one of three asset accounts, credit one of two revenue accounts.
$db->executeStatement("INSERT INTO journal_entry (number, entry_date, text, kind, created_by, created_at, fiscal_year_id)
    SELECT seq, DATE_ADD('2031-07-01', INTERVAL (seq % 366) DAY), CONCAT('Volumen ', seq), 'generated', 'volume', NOW(), ? FROM seq_1_to_10000", [$fyId]);
$db->executeStatement('INSERT INTO journal_line (position, debit, credit, entry_id, account_id)
    SELECT 1, 10.00 + (e.number % 100), 0.00, e.id, CASE e.number % 3 WHEN 0 THEN ? WHEN 1 THEN ? ELSE ? END FROM journal_entry e WHERE e.fiscal_year_id = ?', [$acc('1020'), $acc('1000'), $acc('1100'), $fyId]);
$db->executeStatement('INSERT INTO journal_line (position, debit, credit, entry_id, account_id)
    SELECT 2, 0.00, 10.00 + (e.number % 100), e.id, CASE e.number % 2 WHEN 0 THEN ? ELSE ? END FROM journal_entry e WHERE e.fiscal_year_id = ?', [$acc('3200'), $acc('3400'), $fyId]);
$db->fetchAllAssociative('ANALYZE TABLE journal_entry, journal_line');   // returns a result set — fetched, not executed
$insertSeconds = microtime(true) - $t0;
$em      = $wireDi();
$reports = new LedgerReports($em);
$big     = ReportRange::wholeYear($em->getRepository(FiscalYear::class)->findOneBy(['code' => '2031-32']));
$timed   = function (callable $fn) { $t = microtime(true); $r = $fn(); return [$r, microtime(true) - $t]; };
[$tb, $tTb]   = $timed(fn() => $reports->trialBalance($big));
[$bs, $tBs]   = $timed(fn() => $reports->balanceSheet($big));
$bankBig      = $em->getRepository(Account::class)->findOneBy(['number' => '1020']);
[$as1, $tAs1] = $timed(fn() => $reports->accountStatement($bankBig, $big, 1));
[$asN, $tAsN] = $timed(fn() => $reports->accountStatement($bankBig, $big, 7));
[$jr, $tJr]   = $timed(fn() => $reports->journal($big, 50));
printf("       insert %.2fs · trial balance %.3fs · balance sheet %.3fs · account statement p1 %.3fs, p7 %.3fs · journal p50 %.3fs\n", $insertSeconds, $tTb, $tBs, $tAs1, $tAsN, $tJr);
check('P1 20\'000 lines: trial balance balanced (Σ Soll = Σ Haben), balance sheet balanced', (int) $db->fetchOne('SELECT COUNT(*) FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id WHERE e.fiscal_year_id = ?', [$fyId]) === 20000
    && $tb->isBalanced() && $bs->isBalanced() && count($tb->rows) === 5);
check('P2 1020 carries 3\'333 lines → 7 pages of 500; the LAST page\'s last running balance is the closing, its carry the page-6 end', $as1->paging->total === 3333 && $asN->paging->pageCount === 7 && $asN->paging->page === 7
    && $asN->lines[count($asN->lines) - 1]->balance->equals($asN->closing) && $asN->carry->add($asN->lines[0]->debit)->subtract($asN->lines[0]->credit)->equals($asN->lines[0]->balance));
check('P3 the journal pages through 10\'000 entries (page 50 of 50 at 200 each)', $jr->paging->pageCount === 50 && count($jr->entries) === 200 && $jr->entries[199]->getDate()->format('Y-m-d') >= $jr->entries[0]->getDate()->format('Y-m-d'));
check(sprintf('P4 every report under 2 s at this volume (max %.3fs)', max($tTb, $tBs, $tAs1, $tAsN, $tJr)), max($tTb, $tBs, $tAs1, $tAsN, $tJr) < 2.0);
$explain = fn(string $sql, array $params) => $db->fetchAllAssociative('EXPLAIN ' . $sql, $params);
$planTb  = $explain('SELECT a.id, SUM(l.debit), SUM(l.credit) FROM journal_entry e JOIN journal_line l ON l.entry_id = e.id JOIN account a ON a.id = l.account_id WHERE e.fiscal_year_id = ? AND e.entry_date BETWEEN ? AND ? GROUP BY a.id', [$fyId, '2031-07-01', '2032-06-30']);
$planAs  = $explain('SELECT l.id FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id WHERE l.account_id = ? AND e.fiscal_year_id = ? AND e.entry_date BETWEEN ? AND ?', [$acc('1020'), $fyId, '2031-07-01', '2032-06-30']);
$keyOf   = fn(array $plan, string $table) => array_values(array_filter($plan, fn($r) => $r['table'] === $table))[0]['key'] ?? null;
echo '       EXPLAIN trial balance: ' . implode(' · ', array_map(fn($r) => $r['table'] . '=' . ($r['key'] ?? 'ALL') . '/' . $r['rows'], $planTb)) . "\n";
echo '       EXPLAIN account statement: ' . implode(' · ', array_map(fn($r) => $r['table'] . '=' . ($r['key'] ?? 'ALL') . '/' . $r['rows'], $planAs)) . "\n";
check('P5 EXPLAIN: the lines are reached through an index in both plans (entry_id / account_id FK), the entries by the primary key or (fiscal_year_id, entry_date) — no full scan of journal_line', $keyOf($planTb, 'l') !== null && $keyOf($planAs, 'l') !== null && $keyOf($planTb, 'e') !== null && $keyOf($planAs, 'e') !== null);

$as2 = $reports->accountStatement($bankBig, $big, 2);
check('P2b the page boundary at the production page size: page 2\'s «Übertrag» = page 1\'s last running balance; running balances chain across it', $as2->carry->equals($as1->lines[count($as1->lines) - 1]->balance)
    && $as2->carry->add($as2->lines[0]->debit)->subtract($as2->lines[0]->credit)->equals($as2->lines[0]->balance) && count($as1->lines) === 500 && $as2->opening->equals($as1->opening));
$useGet(['year' => '2031-32', 'account' => '1020', 'page' => '2']);
[$ctx, $html] = $page('accountStatement', ['year' => '2031-32', 'account' => '1020', 'page' => '2']);
check('P2c page 2 renders «Übertrag von Seite 1» without a date (not the range\'s «from»), and the pager', str_contains($html, 'Übertrag von Seite 1') && !str_contains($html, '<span class="be-list__cell">01.07.2031</span>')
    && str_contains($html, 'be-pagination') && str_contains($html, 'Seite 2 von 7'));

// ── C. a cycle in the chart must not hide a line (review L1) ─────────────

echo "C. Statement blocks never hide an account the tree does not reach (cycle written past the validator)\n";
// Two groups pointing at each other and a postable expense account under them —
// what a database edit or an import could leave; the validator refuses it.
$db->executeStatement("INSERT INTO account (number, name, type, postable, active, parent_id) VALUES ('9900', 'Zyklus A', 'expense', 0, 1, NULL), ('9901', 'Zyklus B', 'expense', 0, 1, NULL)");
$db->executeStatement("UPDATE account SET parent_id = (SELECT id FROM (SELECT id FROM account WHERE number = '9901') t) WHERE number = '9900'");
$db->executeStatement("UPDATE account SET parent_id = (SELECT id FROM (SELECT id FROM account WHERE number = '9900') t) WHERE number = '9901'");
$db->executeStatement("INSERT INTO account (number, name, type, postable, active, parent_id) VALUES ('9902', 'Unter dem Zyklus', 'expense', 1, 1, (SELECT id FROM (SELECT id FROM account WHERE number = '9900') t))");
$db->executeStatement("UPDATE number_range SET last_number = 10000 WHERE name = 'journal-entry.2031-32'");   // the volume entries were SQL-inserted with numbers 1…10'000
$em = $wireDi();
(new ManualEntryService($em, 'buchhalter'))->create(PostingRequest::manual(day('2031-08-01'), 'Aufwand im Zyklus', [PostingLine::debit('9902', chf('77.00')), PostingLine::credit('1020', chf('77.00'))]));
$em     = $wireDi();
$cycled = (new LedgerReports($em))->incomeStatement(ReportRange::wholeYear($em->getRepository(FiscalYear::class)->findOneBy(['code' => '2031-32'])));
$flat   = array_values(array_filter($cycled->expense->lines, fn($l) => $l->number === '9902'));
$top    = array_reduce(array_filter($cycled->expense->lines, fn($l) => $l->depth === 0), fn(?Money $s, $l) => $s === null ? $l->amount : $s->add($l->amount));
check('C1 the unreached account is appended flat (depth 0, 77.00) and named in `unplaced`; the cycle groups are not shown', $cycled->expense->unplaced === ['9902'] && count($flat) === 1 && $flat[0]->depth === 0 && $flat[0]->amount->toDecimal() === '77.00'
    && array_filter($cycled->expense->lines, fn($l) => in_array($l->number, ['9900', '9901'], true)) === []);
check('C2 Σ of the depth-0 rows equals the block total (77.00 included) — nothing is hidden', $top !== null && $top->equals($cycled->expense->total) && $cycled->expense->total->toDecimal() === '77.00');
$useGet(['year' => '2031-32']);
check('C3 the income statement page shows the note naming the account', str_contains($page('incomeStatement', ['year' => '2031-32'])[1], 'Nicht im Kontenbaum erreichbar'));

// ── Y. deleting a wrongly opened fiscal year (FIN-FY-002) ────────────────

echo "Y. Deleting a fiscal year: only the latest, only while nothing was ever posted (owner 2026-09-22)\n";
// State: 2031-32 is the latest year and carries the volume entries.
$yearSnapshot = fn(string $code) => array_map(fn($v) => $v === false ? false : (string) $v, [
    $db->fetchOne('SELECT COUNT(*) FROM fiscal_year WHERE code = ?', [$code]),
    $db->fetchOne('SELECT COUNT(*) FROM fiscal_period p JOIN fiscal_year y ON y.id = p.fiscal_year_id WHERE y.code = ?', [$code]),
    $db->fetchOne('SELECT last_number FROM number_range WHERE name = ?', ['journal-entry.' . $code]),
]);
$yearId  = fn(string $code) => (int) $db->fetchOne('SELECT id FROM fiscal_year WHERE code = ?', [$code]);
$refusal = function (string $code) use ($wireDi, $yearId): ?string {
    $e = caught(fn() => (new FiscalYearService($wireDi()))->delete($yearId($code)), FiscalYearNotDeletableException::class);
    return $e?->reason;
};
$before = $yearSnapshot('2031-32');
check('Y1 the latest year with entries is refused (has-entries), nothing touched', $refusal('2031-32') === FiscalYearNotDeletableException::HAS_ENTRIES && $yearSnapshot('2031-32') === $before);

$em = $wireDi();
(new FiscalYearService($em))->open(new FiscalYear('2032-33', day('2032-07-01'), day('2033-06-30')));
$fresh = $yearSnapshot('2032-33');
check('Y2 a year that is not the latest is refused (not-latest) — deleting it would break contiguity', $refusal('2031-32') === FiscalYearNotDeletableException::NOT_LATEST
    && $refusal('2030-31') === FiscalYearNotDeletableException::NOT_LATEST && $yearSnapshot('2031-32') === $before);
$em      = $wireDi();
$service = new FiscalYearService($em);
$years   = $em->getRepository(FiscalYear::class);
check('Y3 deletionRefusal(): null for the new empty latest year, not-latest for the one before', $service->deletionRefusal($years->findOneBy(['code' => '2032-33'])) === null
    && $service->deletionRefusal($years->findOneBy(['code' => '2031-32'])) === FiscalYearNotDeletableException::NOT_LATEST);
$yearListHtml = fn() => $renderer->partial('Backend/FiscalYearController/listAction', $listHost('fiscal-year')->context);
$html = $yearListHtml();
check('Y4 the list shows «Löschen …» for that year — never for one in the middle (the earliest may carry one too, since the prior years)', str_contains($html, '/backend/finance/fiscal-year/confirm-delete?id=' . $yearId('2032-33'))
    && !str_contains($html, '/confirm-delete?id=' . $yearId('2031-32') . '"') && !str_contains($html, '/confirm-delete?id=' . $yearId('2030-31') . '"'));

$nested = null;
$outer  = function () use ($service, $yearId, &$nested) {
    $nested = caught(fn() => $service->delete($yearId('2032-33')), \LogicException::class);
    throw new \RuntimeException('outer aborts');
};
caught(fn() => $em->getTransaction(FiscalYear::class)->run($outer), \RuntimeException::class);
check('Y5 delete() inside an open unit of work is refused (LogicException), nothing deleted, the port closed', $nested !== null && str_contains($nested->getMessage(), 'owns its unit of work')
    && $yearSnapshot('2032-33') === $fresh && !$wireDi()->getTransaction(FiscalYear::class)->isOpen());

// A failure at the flush, AFTER the range row was dropped and the periods removed: a trigger refuses the year's DELETE.
$db->executeStatement("CREATE TRIGGER fy_no_delete BEFORE DELETE ON fiscal_year FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'harness refuses'");
$failed = caught(fn() => (new FiscalYearService($wireDi()))->delete($yearId('2032-33')), \Throwable::class);
$db->executeStatement('DROP TRIGGER fy_no_delete');
check('Y6 a rollback at the flush leaves everything intact: year, 12 periods, range at 0; the port closed', $failed !== null && !$failed instanceof FiscalYearNotDeletableException
    && $yearSnapshot('2032-33') === $fresh && $fresh === ['1', '12', '0'] && !$wireDi()->getTransaction(FiscalYear::class)->isOpen());

$em = $wireDi();
(new FiscalYearService($em))->delete($yearId('2032-33'));
check('Y7 the latest empty year is deleted: year, periods and range gone', $yearSnapshot('2032-33') === ['0', '0', false]);
$em = $wireDi();
check('Y8 … the latest is 2031-32 again, and the list offers no delete (it has entries)', $em->getRepository(FiscalYear::class)->latest()->getCode() === '2031-32' && !str_contains($yearListHtml(), '/confirm-delete?id=' . $yearId('2031-32') . '"'));
(new FiscalYearService($em))->open(new FiscalYear('2032-33', day('2032-07-01'), day('2033-06-30')));
check('Y9 … and it re-opens with the same code: 12 periods, its range new at 0', $yearSnapshot('2032-33') === ['1', '12', '0']);

// An entry written past the ledger (range still at 0): the range row is dropped first, then the
// entry is found — the rollback must bring the row back.
$db->executeStatement("INSERT INTO journal_entry (number, entry_date, text, kind, created_by, created_at, fiscal_year_id) VALUES (1, '2032-07-01', 'past the ledger', 'manual', 'sql', NOW(), ?)", [$yearId('2032-33')]);
check('Y10 an entry refuses the delete (has-entries), and the range row dropped before the check is back at 0', $refusal('2032-33') === FiscalYearNotDeletableException::HAS_ENTRIES
    && $yearSnapshot('2032-33') === ['1', '12', '0']);
$db->executeStatement("DELETE FROM journal_entry WHERE text = 'past the ledger'");

$em  = $wireDi();
$ref = (new ManualEntryService($em, 'buchhalter'))->create($transferRequest('2032-07-15', 'Falsches Jahr'));
check('Y11 a manual entry through the ledger refuses the delete (has-entries)', $ref->fiscalYear === '2032-33' && $refusal('2032-33') === FiscalYearNotDeletableException::HAS_ENTRIES
    && $yearSnapshot('2032-33') === ['1', '12', '1']);
$em    = $wireDi();
$entry = $em->getRepository(JournalEntry::class)->findByRef($ref);
(new ManualEntryService($em, 'buchhalter'))->delete($entry->getId(), $entry->getVersion());
check('Y12 … deleted again, the year is still refused (had-entries): the change row documents the consumed number', (int) $db->fetchOne('SELECT COUNT(*) FROM journal_entry WHERE fiscal_year_id = ?', [$yearId('2032-33')]) === 0
    && $refusal('2032-33') === FiscalYearNotDeletableException::HAD_ENTRIES && $yearSnapshot('2032-33') === ['1', '12', '1']
    && (new FiscalYearService($wireDi()))->deletionRefusal(DI::getUnifiedEntityManager()->getRepository(FiscalYear::class)->findOneBy(['code' => '2032-33'])) === FiscalYearNotDeletableException::HAD_ENTRIES);

$em = $wireDi();
(new FiscalYearService($em))->open(new FiscalYear('2033-34', day('2033-07-01'), day('2034-06-30')));
$em = $wireDi();
$em->getTransaction(FiscalYear::class)->run(fn() => $em->getRepository(NumberRange::class)->next('journal-entry.2033-34'));
check('Y13 a range that has drawn a number refuses the delete (range-used) — no entry, no change row; everything kept', $refusal('2033-34') === FiscalYearNotDeletableException::RANGE_USED
    && $yearSnapshot('2033-34') === ['1', '12', '1']);
check('Y14 … and the year before it is no longer the latest (not-latest)', $refusal('2032-33') === FiscalYearNotDeletableException::NOT_LATEST);

$em   = $wireDi();
$y    = $em->getRepository(FiscalYear::class)->findOneBy(['code' => '2033-34']);
$form = $renderer->partial('Backend/FiscalYearController/confirmDelete', ['year' => $y, 'refusal' => null, 'entityCsrf' => 'tok', 'actionBase' => '/backend/finance/fiscal-year']);
$no   = $renderer->partial('Backend/FiscalYearController/confirmDelete', ['year' => $y, 'refusal' => 'Nein.', 'entityCsrf' => 'tok', 'actionBase' => '/backend/finance/fiscal-year']);
check('Y15 the modal posts id + entity token to /delete (Fetch, no script); a refusal shows the reason and no form', str_contains($form, 'data-fetch-post="/backend/finance/fiscal-year/delete"')
    && str_contains($form, 'name="entity_csrf" value="tok"') && str_contains($form, 'name="id"          value="' . $y->getId() . '"') && !str_contains($form, '<script')
    && str_contains($no, 'Löschen nicht möglich') && str_contains($no, 'Nein.') && !str_contains($no, '<form'));
$yearSource = file_get_contents($package . '/src/Ui/FiscalYearControllerTrait.php');
check('Y16 the delete action is a Fetch POST checking the entity token «fiscalYear» and going through the service', str_contains($yearSource, "#[Fetch, HttpMethod('POST')]")
    && str_contains($yearSource, "'fiscalYear', \$id)") && str_contains($yearSource, '->delete($id)') && !str_contains($yearSource, '->remove('));

echo "PY. A PRIOR year: the year before the earliest (owner 2026-09-29)\n";
$em       = $wireDi();
$service  = new FiscalYearService($em);
$years    = $em->getRepository(FiscalYear::class);
$earliest = $years->earliest();
$firstDay = $earliest->getStartDate();
$prior    = $service->proposePrior();
check('PY1 proposePrior(): twelve months ending the day before the earliest year starts, code from the dates',
    $prior->getEndDate()->format('Y-m-d') === $firstDay->modify('-1 day')->format('Y-m-d')
    && $prior->getStartDate()->format('Y-m-d') === $firstDay->modify('-1 year')->format('Y-m-d') && $prior->getCode() === FiscalYearService::proposeCode($prior->getStartDate(), $prior->getEndDate()));
$gap = new FiscalYearValidator(new FiscalYear('py-gap', $firstDay->modify('-1 year'), $firstDay->modify('-2 days')), $years);
check('PY2 a prior year that leaves a gap is refused — the message names both ways (after the latest, before the earliest)',
    !$gap->isValid() && str_contains($gap->getFieldError('start_date'), 'Vorjahr') && str_contains($gap->getFieldError('start_date'), $firstDay->modify('-1 day')->format('d.m.Y')));
$service->open($prior);
$priorCode = $prior->getCode();
$em    = $wireDi();
$years = $em->getRepository(FiscalYear::class);
check('PY3 open() takes the prior year: the earliest now, its monthly periods, its number range at 0',
    $years->earliest()->getCode() === $priorCode && count($years->earliest()->getPeriods()) >= 12 && (string) $db->fetchOne('SELECT last_number FROM number_range WHERE name = ?', ['journal-entry.' . $priorCode]) === '0');
check('PY4 the next year is still offered after the latest (both ends stay open)', (new FiscalYearService($em))->proposeNext()->getStartDate()->format('Y-m-d') === $years->latest()->getEndDate()->modify('+1 day')->format('Y-m-d'));
$html = $yearListHtml();
check('PY5 the empty prior year carries «Löschen …» (the earliest — a prior year opened by mistake), the year after it does not',
    str_contains($html, '/confirm-delete?id=' . $yearId($priorCode)) && $refusal($earliest->getCode()) === FiscalYearNotDeletableException::NOT_LATEST);
(new FiscalYearService($wireDi()))->delete($yearId($priorCode));
check('PY6 … and it is deleted: year, periods and range gone; the earliest is the old one again', $yearSnapshot($priorCode) === ['0', '0', false]
    && $wireDi()->getRepository(FiscalYear::class)->earliest()->getCode() === $earliest->getCode());
$openPrior = $renderer->partial('Backend/FiscalYearController/open', ['entry' => $prior, 'prior' => true, 'hasYears' => true, 'proposed' => $priorCode, 'validator' => new FiscalYearValidator($prior), 'actionBase' => '/backend/finance/fiscal-year']);
check('PY7 the opening modal switches «Nächstes Jahr | Vorjahr» by a fetched GET on BUTTONS (a link would load /open as a page — seen live 2026-09-29; the server proposes the dates), and says the prior year\'s balances need an opening entry by hand until P5',
    str_contains($openPrior, 'data-fetch-get="/backend/finance/fiscal-year/open?prior=1"') && str_contains($openPrior, 'be-lang-switch__option be-lang-switch__option--active" data-fetch-get="/backend/finance/fiscal-year/open?prior=1" aria-pressed="true"') && !str_contains($openPrior, '<a ')
    && str_contains($openPrior, 'Eröffnungsbuchung'));

echo "Y. … races answered with a sentence, not a 500 (review M1/L2)\n";
$driverError = fn(string $m) => \Doctrine\DBAL\Driver\PDO\Exception::new(new \PDOException($m));
$deadlock    = new \Doctrine\DBAL\Exception\DeadlockException($driverError('Deadlock found when trying to get lock'), null);
check('Y17 RaceFailure::isDeadlock() finds the deadlock down the getPrevious() chain (DOCTRINE-TX-005 shape); a plain error is no deadlock', RaceFailure::isDeadlock(new \RuntimeException('SAVEPOINT DOCTRINE_2 does not exist', 0, $deadlock))
    && RaceFailure::isDeadlock($deadlock) && !RaceFailure::isDeadlock(new \RuntimeException('other')) && !RaceFailure::isFiscalYearGone($deadlock));

// The year vanishes between the ledger's checks and the commit: a trigger points the insert at a year id that does not exist.
$db->executeStatement('CREATE TRIGGER je_year_gone BEFORE INSERT ON journal_entry FOR EACH ROW SET NEW.fiscal_year_id = 0');
$em   = $wireDi();
$gone = caught(fn() => (new ManualEntryService($em, 'buchhalter'))->create($transferRequest('2032-07-20', 'Jahr weg')), \Throwable::class);
$db->executeStatement('DROP TRIGGER je_year_gone');
check('Y18 a manual create whose year is gone at the commit fails on journal_entry.fiscal_year_id and is recognised by that key; no number consumed, nothing written', $gone !== null && RaceFailure::isFiscalYearGone($gone)
    && $yearSnapshot('2032-33') === ['1', '12', '1'] && (int) $db->fetchOne("SELECT COUNT(*) FROM journal_entry WHERE text = 'Jahr weg'") === 0);
$db->executeStatement('CREATE TRIGGER je_other_fk BEFORE INSERT ON journal_entry FOR EACH ROW SET NEW.reversal_of_id = 999999999');
$em    = $wireDi();
$other = caught(fn() => (new ManualEntryService($em, 'buchhalter'))->create($transferRequest('2032-07-20', 'Anderer FK')), \Throwable::class);
$db->executeStatement('DROP TRIGGER je_other_fk');
check('Y19 … any OTHER foreign-key violation is not taken for a deleted year (stays loud)', $other !== null && !RaceFailure::isFiscalYearGone($other)
    && $other instanceof \Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException);
check('Y20 the migration names the key exactly as JournalEntry::FK_FISCAL_YEAR', str_contains(file_get_contents($package . '/res/migrations/Version20260922091711.php'),
    'CONSTRAINT ' . JournalEntry::FK_FISCAL_YEAR . ' FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_year (id)'));
$journalSource = file_get_contents($package . '/src/Ui/JournalControllerTrait.php');
check('Y21 the screens answer both races and rethrow everything else (source guard): add → «Geschäftsjahr … gelöscht», open → «gleichzeitig eröffnet»',
    str_contains($journalSource, 'if (!RaceFailure::isFiscalYearGone($e)) {') && str_contains($journalSource, 'Das Geschäftsjahr wurde inzwischen gelöscht')
    && str_contains($yearSource, 'if (!RaceFailure::isDeadlock($e)) {') && str_contains($yearSource, 'Ein anderes Geschäftsjahr wurde gleichzeitig eröffnet — Liste neu laden'));

// ── T. the journal locks an account's type and postable (FIN-TYPE-001) ────

echo "T. Type and postable locked by the journal (owner 2026-09-22)\n";
// State: 2032-33 is open (Y). Own accounts under group 100, own entries in 2032-33;
// period states are set directly (no transition before P5) and reset at the end.
$em      = $wireDi();
$service = new AccountService($em);
$group   = $em->getRepository(Account::class)->findOneBy(['number' => '100']);
foreach (['1095' => 'Typ gesperrt', '1096' => 'Buchung offen', '1097' => 'Ohne Buchung', '1098' => 'Wettlauf A', '1099' => 'Wettlauf B'] as $n => $name) {
    $service->save(new Account(['number' => $n, 'name' => $name, 'type' => 'asset', 'postable' => true, 'parent' => $group]));
}
$tManual = new ManualEntryService($em, 'buchhalter');
$tPost   = fn(string $account, string $date) => $tManual->create(PostingRequest::manual(day($date), 'FIN-TYPE-001 ' . $account, [PostingLine::debit($account, chf('10.00')), PostingLine::credit('1020', chf('10.00'))]));
$tPost('1095', '2032-08-10');
$tPost('1095', '2032-09-10');
$tPost('1096', '2032-10-10');
$setState('2032-33', '2032-09-10', PeriodState::VatSettled->value);
$tType   = fn(string $n) => $db->fetchOne('SELECT type FROM account WHERE number = ?', [$n]);
$tFlag   = fn(string $n) => (int) $db->fetchOne('SELECT postable FROM account WHERE number = ?', [$n]);
$tFresh  = function () use (&$em, &$service, $wireDi): void { $em = $wireDi(); $service = new AccountService($em); };
$tAcc    = function (string $n) use (&$em): ?Account { return $em->getRepository(Account::class)->findOneBy(['number' => $n]); };   // by reference: $tFresh() swaps $em

$tFresh();
$service->update($tAcc('1095'), ['type' => 'liability']);
check('T1 type change ALLOWED while the lines lie only in open and vat-settled periods (08 open, 09 vat-settled)', $tType('1095') === 'liability');
$service->update($tAcc('1095'), ['type' => 'asset']);

$setState('2032-33', '2032-08-10', PeriodState::Closed->value);
$tFresh();
$a1095 = $tAcc('1095');
$e = caught(fn() => $service->update($a1095, ['type' => 'expense']), InvalidAccountException::class);
check('T2 type change REFUSED once one line lies in a closed period — field «type», German, naming the correction (new account + manual transfer)', $e !== null && $e->validator->hasFieldError('type')
    && str_contains($e->validator->getFieldError('type'), 'abgeschlossenen Periode') && str_contains($e->validator->getFieldError('type'), 'neues Konto') && str_contains($e->validator->getFieldError('type'), 'Umbuchung')
    && $e->draft->getType() === 'expense' && $tType('1095') === 'asset');
check('T3 … the managed entity is unmodified (validated on the draft, before any mutation)', $a1095->getType() === 'asset');
$service->update($tAcc('1097'), ['name' => 'Ohne Buchung (neu)']);   // an unrelated, valid write in the SAME EntityManager
check('T4 … and a later flush of something else writes none of the refused change', $tType('1095') === 'asset' && $db->fetchOne("SELECT name FROM account WHERE number = '1097'") === 'Ohne Buchung (neu)');
$service->update($a1095, ['name' => 'Typ gesperrt (umbenannt)']);
$service->setActive($a1095, false);
$service->setActive($a1095, true);
check('T5 name and active stay free on a type-locked account', $db->fetchOne("SELECT name FROM account WHERE number = '1095'") === 'Typ gesperrt (umbenannt)' && (int) $db->fetchOne("SELECT active FROM account WHERE number = '1095'") === 1);
$service->update($a1095, ['type' => 'asset', 'name' => 'Typ gesperrt']);
check('T6 passing the UNCHANGED type is no change (the form without a disabled select posts it)', $db->fetchOne("SELECT name FROM account WHERE number = '1095'") === 'Typ gesperrt');

$tFresh();
$e1 = caught(fn() => $service->update($tAcc('1096'), ['postable' => false]), InvalidAccountException::class);
$e2 = caught(fn() => $service->update($tAcc('1095'), ['postable' => false]), InvalidAccountException::class);
check('T7 postable → group REFUSED with any line: an open period (1096) and a closed one (1095) — field «postable», German', $e1 !== null && $e1->validator->hasFieldError('postable') && str_contains($e1->validator->getFieldError('postable'), 'keine Gruppe')
    && $e2 !== null && $e2->validator->hasFieldError('postable') && $tFlag('1096') === 1 && $tFlag('1095') === 1);
$service->update($tAcc('1096'), ['type' => 'liability']);
check('T8 … while 1096 (open period only) may still change its type', $tType('1096') === 'liability');

$service->update($tAcc('1097'), ['type' => 'expense']);
$service->update($tAcc('1097'), ['postable' => false]);
check('T9 no lines: type change and postable → group ALLOWED', $tType('1097') === 'expense' && $tFlag('1097') === 0);
$service->update($tAcc('1097'), ['postable' => true, 'type' => 'asset']);

$tFresh();
$nested = caught(fn() => $em->getTransaction(Account::class)->run(fn() => $service->update($tAcc('1097'), ['type' => 'revenue'])), \LogicException::class);
check('T10 the guarded path refuses to run inside an open unit of work (its re-check must read after the lock); nothing written', $nested !== null && str_contains($nested->getMessage(), 'owns its unit of work') && $tType('1097') === 'asset');

$tFresh();
check('T11 postingLocks() — what the form disables: 1095 type + postable, 1096 postable only, 1097 nothing', $service->postingLocks($tAcc('1095')) === ['type' => true, 'postable' => true]
    && $service->postingLocks($tAcc('1096')) === ['type' => false, 'postable' => true] && $service->postingLocks($tAcc('1097')) === ['type' => false, 'postable' => false]);
$tForm = fn(Account $a, array $locks, ?string $storedType = null) => $renderer->partial('Backend/AccountController/edit', ['entry' => $a, 'entityCsrf' => 'tok', 'validator' => new AccountValidator($a),
    'typeLabels' => ['asset' => 'Aktiven', 'liability' => 'Fremdkapital', 'equity' => 'Eigenkapital', 'expense' => 'Aufwand', 'revenue' => 'Ertrag'], 'groups' => [], 'locks' => $locks, 'storedType' => $storedType ?? $a->getType(), 'actionBase' => '/backend/finance/account']);
$locked = $tForm($tAcc('1095'), $service->postingLocks($tAcc('1095')));
$free   = $tForm($tAcc('1097'), $service->postingLocks($tAcc('1097')));
check('T12 the edit form: type and «Bebuchbar» DISABLED with the reasons, «Nein — Gruppe» not offered; a free account keeps both; no script', str_contains($locked, '<select name="type" required disabled')
    && str_contains($locked, '<select name="postable" disabled') && !str_contains($locked, 'Nein — Gruppe') && str_contains($locked, 'neues Konto anlegen') && str_contains($locked, 'es kann keine Gruppe werden')
    && str_contains($free, '<select name="type" required aria-invalid') && str_contains($free, 'Nein — Gruppe') && !str_contains($free, ' disabled') && !str_contains($locked . $free, '<script'));
$refusedDraft = clone $tAcc('1095');
$refusedDraft->setType('expense');
$afterRefusal = $tForm($refusedDraft, ['type' => true, 'postable' => true], 'asset');
check('T12b after a refusal the DISABLED type select shows the STORED type (asset), not the refused draft\'s (expense)', str_contains($afterRefusal, '<option value="asset" selected>') && !str_contains($afterRefusal, '<option value="expense" selected>'));
$accountSource = file_get_contents($package . '/src/Ui/AccountControllerTrait.php');
check('T13 the trait asks the service for the locks (one source for the rule)', str_contains($accountSource, '->postingLocks('));
$accountList = $renderer->partial('Backend/AccountController/listAction', $accountHost->context);
check('T14 ADR-049 rev. / ADR-047 addendum 2026-10-10: the edit dialog has its action row directly under the header («Speichern» first); the list renders every row through `_row` with data-entity="account:<id>"; edit and the switch answer with the row (replaceRow via accountRowAnswer), a new account with insertRow before the next number — reload only for a GROUP moved to another group (its subtree changes depth) and the KMU fill',
    preg_match('~be-modal__header.*?</div>\s*<div class="z77-form-actions">\s*<button type="submit"[^>]*>Speichern~s', $free) === 1 && !str_contains($free, 'be-modal__footer')
    && $accountHost->context['accounts'] !== [] && substr_count($accountList, 'data-entity="account:') === count($accountHost->context['accounts'])
    && substr_count($accountSource, '$this->accountRowAnswer(') === 2 && substr_count($accountSource, "addCommand('reload')") === 2 && substr_count($accountSource, '->replaceRow(') === 1 && substr_count($accountSource, '->insertRow(') === 1
    && str_contains($accountList, 'data-entity-list="account"'));
$fyTemplates = glob($package . '/res/view/templates/Backend/{AccountController,FiscalYearController}/*.tpl.php', GLOB_BRACE);
check('T15 no account or fiscal-year dialog keeps .be-modal__footer; the field-less confirms (KMU fill, delete a year) keep the bar at the end, the rest at the top',
    array_filter($fyTemplates, fn($f) => str_contains(file_get_contents($f), 'be-modal__footer')) === []
    && str_contains(file_get_contents($package . '/res/view/templates/Backend/FiscalYearController/confirmDelete.tpl.php'), "'end' => true")
    && str_contains(file_get_contents($package . '/res/view/templates/Backend/AccountController/confirmAdoptKmuChart.tpl.php'), "'end' => true")
    && !str_contains(file_get_contents($package . '/res/view/templates/Backend/FiscalYearController/confirmReopen.tpl.php'), "'end' => true"));

echo "T. … races between a posting and «becomes a group» (two processes, forced order)\n";
// Race 1: a posting on 1098 is IN FLIGHT (number drawn, rows share-locked, not committed) when the
// account is turned into a group. The lock-free check sees no line; the update's row lock must
// wait for the posting's commit, and the re-check under the lock must then see its line.
@unlink($base . '/holdpost.ready');
$hold = proc_open([PHP_BINARY, __FILE__, '--worker', $base, 'holdpost', '1098', '2032-11-10', 'th'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $holdPipes);
for ($t = 0; $t < 300 && !is_file($base . '/holdpost.ready'); $t++) { usleep(100000); }
$tFresh();
$t0    = microtime(true);
$race1 = caught(fn() => $service->update($tAcc('1098'), ['postable' => false]), InvalidAccountException::class);
$waitedFor = microtime(true) - $t0;
$holdOut = trim(stream_get_contents($holdPipes[1])) . trim(stream_get_contents($holdPipes[2]));
fclose($holdPipes[1]); fclose($holdPipes[2]); proc_close($hold);
check(sprintf('T14 the update WAITED for the in-flight posting (%.2fs; worker: %s) and was refused under the lock — the posting committed, the account stays postable', $waitedFor, $holdOut),
    $holdOut === 'ok waited' && $race1 !== null && $race1->validator->hasFieldError('postable') && $tFlag('1098') === 1
    && (int) $db->fetchOne("SELECT COUNT(*) FROM journal_line l JOIN account a ON a.id = l.account_id WHERE a.number = '1098'") === 1);
check('T15 … after the refusal under the lock the EntityManager was replaced; a fresh read serves the next request', (function () use ($tFresh, &$service, $tAcc) {
    $tFresh();
    return $service->postingLocks($tAcc('1098')) === ['type' => false, 'postable' => true];
})());

// Race 2: the account row is already locked by a type/postable change (here: raw SQL holding
// FOR UPDATE and postable = 0, uncommitted) when a posting starts. Its lock-free validation still
// sees the account postable (the change is not committed); it draws its number, then its share
// lock (after the range lock) must wait, read the account as the group it became and refuse —
// and the rollback gives the number back.
$rangeNow    = fn() => (string) $db->fetchOne('SELECT last_number FROM number_range WHERE name = ?', ['journal-entry.2032-33']);   // $range was re-bound in part 3
$rangeBefore = $rangeNow();
$db->beginTransaction();
$sawWait = false;
try {
    $db->fetchOne("SELECT id FROM account WHERE number = '1099' FOR UPDATE");
    $db->executeStatement("UPDATE account SET postable = 0 WHERE number = '1099'");
    $poster = proc_open([PHP_BINARY, __FILE__, '--worker', $base, 'post1', '1099', '2032-11-11', 'tp'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $posterPipes);
    for ($t = 0; $t < 200 && !$sawWait; $t++) {
        usleep(100000);
        // The poster's share lock still EXECUTING = waiting on our row lock (same-user sessions are visible).
        $sawWait = (int) $admin->fetchOne("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND INFO LIKE '%FROM account WHERE number IN%LOCK IN SHARE MODE%'") > 0;
    }
    usleep(200000);
} finally {
    $db->commit();
}
$posterOut = trim(stream_get_contents($posterPipes[1])) . trim(stream_get_contents($posterPipes[2]));
fclose($posterPipes[1]); fclose($posterPipes[2]); proc_close($poster);
check("T16 the posting WAITED for the account lock and was then refused as a group (worker: {$posterOut}); no line on the group, the drawn number went back ({$rangeBefore})", $sawWait && $posterOut === PostingRefusedException::ACCOUNT_NOT_POSTABLE
    && (int) $db->fetchOne("SELECT COUNT(*) FROM journal_line l JOIN account a ON a.id = l.account_id WHERE a.number = '1099'") === 0 && $rangeNow() === $rangeBefore);

echo "T. … cost of the checks at the volume of P\n";
$db->executeStatement("UPDATE fiscal_period SET state = 'open'");   // earlier sections left states behind
$lines = $wireDi()->getRepository(JournalLine::class);
$lines->accountHasLines($acc('1000'));   // warm-up: the first statement of a fresh wiring builds the EntityManager
[$missNone, $tNone]   = $timed(fn() => $lines->accountHasLinesInClosedPeriod($acc('1000')));     // no period closed at all: the journal is not touched
$db->executeStatement("UPDATE fiscal_period p JOIN fiscal_year y ON y.id = p.fiscal_year_id SET p.state = 'closed' WHERE y.code = '2031-32'");
[$hitClosed, $tHit]   = $timed(fn() => $lines->accountHasLinesInClosedPeriod($acc('1020')));     // 3'334 lines, all in the closed year
[$missClosed, $tMiss] = $timed(fn() => $lines->accountHasLinesInClosedPeriod($acc('6000')));     // a few lines, all in other years
[$any, $tAny]         = $timed(fn() => $lines->accountHasLines($acc('1020')));
$db->executeStatement("UPDATE fiscal_period SET state = 'open'");
$db->executeStatement("UPDATE fiscal_period p JOIN fiscal_year y ON y.id = p.fiscal_year_id SET p.state = 'closed' WHERE y.code = '2032-33'");
[$missMany, $tMany]   = $timed(fn() => $lines->accountHasLinesInClosedPeriod($acc('1000')));     // 3'333 lines, none in the closed year: every one is looked at
printf("       closed-period check: none closed %.4fs · hit %.4fs · miss (few lines) %.4fs · miss (3'333 lines, small closed year) %.4fs · any line %.4fs\n", $tNone, $tHit, $tMiss, $tMany, $tAny);
check(sprintf('T17 the checks are exact and cheap at this volume (max %.3fs)', max($tNone, $tHit, $tMiss, $tMany, $tAny)), !$missNone && $hitClosed && !$missClosed && !$missMany && $any && max($tNone, $tHit, $tMiss, $tMany, $tAny) < 0.5);
$db->executeStatement("UPDATE fiscal_period SET state = 'open'");

// ── O. the one-line entry (P2 exit check 3a/3b, owner 2026-09-22) ─────────

echo "O. One-line entry: Soll | Datum | Bu-Nr | Text | Haben | Betrag (gross), optional MwSt row\n";
// State: 2032-33 (1.7.2032–30.6.2033) is open, every period `open` (T reset them).
$em       = $wireDi();
// The MANDATOR record the tax accounts are read from (E2, 2026-09-23) — created once, the way the
// backend screen's first save does it: the KMU start values on the account fields.
$mandator = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$mandator->mapFromArray(['name' => 'Harness AG', 'country' => 'CH']);
(new \Z77\Module\Mandator\Services\MandatorService($em))->save($mandator);
$em       = $wireDi();
$entries  = $em->getRepository(JournalEntry::class);
$oneLine  = fn() => new \Z77\Module\Financial\Ui\OneLineEntryForm('CHF', DI::getUnifiedEntityManager()->getRepository(Account::class), DI::getUnifiedEntityManager()->getRepository(TaxCode::class),
    \Z77\Module\Vat\Services\VatRates::from(DI::getUnifiedEntityManager()), new LedgerService(DI::getUnifiedEntityManager()));
$rangeOf  = fn(string $name) => (string) $db->fetchOne('SELECT last_number FROM number_range WHERE name = ?', [$name]);   // $range was re-bound in part 3
/** The POST body of the one-line form. */
$row = fn(string $debit, string $credit, string $amount, string $date = '2032-11-15', string $code = '', string $tax = '', string $text = 'Beleg') => ['debit' => $debit, 'date' => $date, 'text' => $text, 'credit' => $credit, 'amount' => $amount]
    + ($code !== '' ? ['vat' => '1', 'tax_code' => $code, 'tax_amount' => $tax] : ['tax_code' => '', 'tax_amount' => '']);
/** A request's lines as «account D|C amount [code rate base tax]» — the shape to compare against. */
$shape = fn(?PostingRequest $r) => $r === null ? null : array_map(fn(PostingLine $l) => $l->account . ' ' . ($l->debit->isPositive() ? 'D ' . $l->debit->toDecimal() : 'C ' . $l->credit->toDecimal())
    . ($l->hasTax() ? " {$l->taxCode} {$l->taxRate} {$l->taxBase->toDecimal()} {$l->taxAmount->toDecimal()}" : ''), $r->lines);
$manual = new ManualEntryService($em, 'buchhalter');

$plain = $oneLine()->fromPost($row('6500', '1020', "1'250.50"))->toRequest();
check('O1 without VAT: two lines, Soll receives, Haben gives, the amount as typed («1\'250.50»)', $shape($plain) === ['6500 D 1250.50', '1020 C 1250.50'] && $plain->kind === EntryKind::Manual);
$plainRef = $manual->create($plain);
check('O1b … posted through ManualEntryService like every manual entry: a manual entry with two lines', count($lineRows((int) $entryRow('2032-33', $plainRef->number)['id'])) === 2 && $entryRow('2032-33', $plainRef->number)['kind'] === 'manual');

// 400.00 gross at 8.1 %: 400 × 810 / 10810 = 29.9722… → 29.97 (commercial rounding), net 370.03.
$input = $oneLine()->fromPost($row('6500', '1020', '400.00', '2032-11-15', 'VM'))->toRequest();
check('O2 input code VM 8.1 %: tax 29.97 out of 400.00 (400 × 810 / 10810 = 29.972…), net 370.03 on the Soll account with the tax data, 1170 in Soll, 1020 = gross in Haben',
    $shape($input) === ['6500 D 370.03 VM 810 370.03 29.97', '1170 D 29.97', '1020 C 400.00']);
check('O2b … the tax is VatCalculator\'s gross mode, not a second formula', \Z77\Module\Vat\Calculation\VatCalculator::taxIn(chf('400.00'), 810)->toDecimal() === '29.97');
$inputRef = $manual->create($input);
$inputRows = $lineRows((int) $entryRow('2032-33', $inputRef->number)['id']);
check('O2c … posted balanced: Σ Soll = Σ Haben = 400.00; tax base/amount/rate stored on the net line only', count($inputRows) === 3
    && $inputRows[0]['tax_code'] === 'VM' && (int) $inputRows[0]['tax_rate'] === 810 && $inputRows[0]['tax_base'] === '370.03' && $inputRows[0]['tax_amount'] === '29.97'
    && $inputRows[1]['tax_code'] === null && $inputRows[2]['tax_code'] === null
    && chf($inputRows[0]['debit'])->add(chf($inputRows[1]['debit']))->equals(chf($inputRows[2]['credit'])) && $inputRows[2]['credit'] === '400.00');

$wdv = $oneLine()->fromPost($row('6100', '1020', '400.00', '2023-06-30', 'VM'))->toRequest();
check('O3 the wdv case: 400.00 at 7.7 % (2023) → tax 28.60 CONTAINED in the gross, not 30.80 (7.7 % on top)', $shape($wdv) === ['6100 D 371.40 VM 770 371.40 28.60', '1170 D 28.60', '1020 C 400.00']);
$dec31 = $oneLine()->fromPost($row('6500', '1020', '400.00', '2023-12-31', 'VM'))->toRequest();
$jan1  = $oneLine()->fromPost($row('6500', '1020', '400.00', '2024-01-01', 'VM'))->toRequest();
check('O4 the rate by ENTRY date across the change: 31.12.2023 → 7.7 % / 28.60, 1.1.2024 → 8.1 % / 29.97', $shape($dec31)[0] === '6500 D 371.40 VM 770 371.40 28.60' && $shape($jan1)[0] === '6500 D 370.03 VM 810 370.03 29.97');

$output = $oneLine()->fromPost($row('1020', '3200', '400.00', '2032-11-16', 'UN'))->toRequest();
check('O5 output code UN: the Haben account gets net 370.03 with the tax data, 2200 gets 29.97 in Haben, the Soll account the gross', $shape($output) === ['1020 D 400.00', '3200 C 370.03 UN 810 370.03 29.97', '2200 C 29.97']);
$outputRef = $manual->create($output);
check('O5b … posted: three lines, the VAT line on 2200 without tax data', array_column($lineRows((int) $entryRow('2032-33', $outputRef->number)['id']), 'account_number') === ['1020', '3200', '2200']);

$voucher = $oneLine()->fromPost($row('6500', '1020', '400.00', '2032-11-15', 'VM', '30.00'))->toRequest();
check('O6 an entered tax (30.00, 0.03 off the computed 29.97) is the voucher value, taken as is: net 370.00', $shape($voucher) === ['6500 D 370.00 VM 810 370.00 30.00', '1170 D 30.00', '1020 C 400.00']);
// 400.00 VM: computed 29.97, limit min(1.00, max(0.05, 1 % of 29.97 → 0.30)) = 0.30.
$edge = $oneLine()->fromPost($row('6500', '1020', '400.00', '2032-11-15', 'VM', '29.67'))->toRequest();
check('O6b exactly the limit 0.30 below the computed 29.97 is still accepted (29.67)', ($shape($edge)[1] ?? null) === '1170 D 29.67');
$tooFar = $oneLine()->fromPost($row('6500', '1020', '400.00', '2032-11-15', 'VM', '31.00'));
check('O6c 31.00 (1.03 off) is refused with a German message naming the computed value and the limit', $tooFar->toRequest() === null
    && str_contains($tooFar->error('tax_amount'), 'berechneten 29.97') && str_contains($tooFar->error('tax_amount'), 'höchstens 0.30'));
// The case the tolerance was narrowed for (owner decision 2026-09-23, P2 exit check 7 in z77.ch):
// 30.80 is 400 × 7.7 % ON TOP at the pre-2024 rate — not a rounding difference. At the old 10 %
// the limit here was the flat 1.00 and 0.83 off passed in silence.
$wdvValue = $oneLine()->fromPost($row('6500', '1020', '400.00', '2032-11-15', 'VM', '30.80'));
check('O6d the wdv value 30.80 (0.83 off the computed 29.97) is REFUSED — a different rate is not voucher rounding', $wdvValue->toRequest() === null
    && str_contains($wdvValue->error('tax_amount'), 'berechneten 29.97') && str_contains($wdvValue->error('tax_amount'), 'höchstens 0.30'));

$zero = $oneLine()->fromPost($row('1100', '3200', '400.00', '2032-11-17', 'UE'))->toRequest();
check('O7 a zero-rated code (UE, 0 %): the code on the revenue line with base = gross, tax 0.00 — and NO tax line', $shape($zero) === ['1100 D 400.00', '3200 C 400.00 UE 0 400.00 0.00']);
$exempt = $oneLine()->fromPost($row('1100', '3200', '400.00', '2032-11-17', 'UA', '0.50'));
check('O7b an exempt code (UA) with a tax amount is refused — steuerfrei, kein Steuerbetrag', $exempt->toRequest() === null && str_contains($exempt->error('tax_amount'), 'steuerfrei'));
$zeroRef = $manual->create($zero);

// The tax accounts live on the MANDATOR record since E2 (2026-09-23) — read through
// `LedgerService::vatAccountFor()`, the same access point as before. The record was
// created above with the KMU start values; its fields are changed here through the
// service (an empty field) and past the validator in SQL (an account that BECAME a
// group after it was saved) — both must refuse at the point of use, naming the mandator.
$mandatorRow = fn() => DI::getUnifiedEntityManager()->getRepository(\Z77\Module\Mandator\Entities\Mandator::class)->theOne();
(new \Z77\Module\Mandator\Services\MandatorService(DI::getUnifiedEntityManager()))->update($mandatorRow(), ['account_vat_input_material' => '']);
$em = $wireDi();
$missing = $oneLine()->fromPost($row('6500', '1020', '400.00', '2032-11-15', 'VM'));
check('O8 no tax account set for the category on the mandator → refused, the message names the mandator', $missing->toRequest() === null
    && array_filter($missing->generalErrors(), fn($m) => str_contains($m, 'Mandant') && str_contains($m, 'input-material')) !== []
    && (new LedgerService($em))->vatAccountFor('input-material') === null);
check('O8b … a line WITHOUT VAT still posts on that record (the account is needed only for a tax line)', $oneLine()->fromPost($row('6500', '1020', '10.00'))->toRequest() !== null);
$db->executeStatement("UPDATE mandator SET account_vat_input_material = '100'");   // a group — past the validator, as a chart change after the save would leave it
$em = $wireDi();
$group = $oneLine()->fromPost($row('6500', '1020', '400.00', '2032-11-15', 'VM'));
check('O8c the stored account is a group → refused through accountExists(), naming account and mandator', $group->toRequest() === null
    && array_filter($group->generalErrors(), fn($m) => str_contains($m, 'Steuerkonto 100') && str_contains($m, 'Mandant')) !== []
    && !(new LedgerService(DI::getUnifiedEntityManager()))->accountExists('100') && !(new LedgerService(DI::getUnifiedEntityManager()))->accountExists('4711')
    && (new LedgerService(DI::getUnifiedEntityManager()))->accountExists('1170') && !(new LedgerService(DI::getUnifiedEntityManager()))->accountExists('6570'));   // 6570 was deactivated in M
$db->executeStatement("UPDATE mandator SET account_vat_input_material = '1170'");
$em = $wireDi();
$e = caught(fn() => (new \Z77\Module\Mandator\Services\MandatorService($em))->update($mandatorRow(), ['account_vat_input_material' => '100']), \Z77\Module\Mandator\Services\InvalidMandatorException::class);
check('O8c2 … and the SCREEN could not have stored the group: the mandator validator refuses it on save, naming the field', $e !== null
    && $e->validator->hasFieldError('account_vat_input_material') && str_contains($e->validator->getFieldError('account_vat_input_material'), 'Gruppe')
    && $mandatorRow()->account('vat-input-material') === '1170');

/**
 * A fresh DI whose financialConfig STILL carries `vatAccounts` — a project override copied
 * before the move (BOOT-CONFIG-001). Refused loudly: a second source is never read.
 */
$withLegacyVatAccounts = function (?array $vatAccounts) use ($base): void {
    // $wireDi() with the ModuleManager swapped — DI::set() never replaces a registered service.
    DI::getInstance(true)
        ->set('CacheManager', CacheManager::class, true)
        ->set('FileFinder', fn($c) => new FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', function ($c) use ($vatAccounts) {
            $mm = new class($c->get('ConfigManager')) extends ModuleManager {
                public ?array $vatAccounts = null;
                public function getModuleConfig(string $moduleKey): ?\Z77\Core\Config\Config
                {
                    $config = parent::getModuleConfig($moduleKey);
                    if ($moduleKey !== 'financial' || $config === null || $this->vatAccounts === null) {
                        return $config;
                    }
                    return new \Z77\Core\Config\Config($config->getAll() + ['vatAccounts' => $this->vatAccounts]);
                }
            };
            $mm->vatAccounts = $vatAccounts;
            return $mm;
        }, true)
        ->set('DataSourceResolver', fn() => new DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new UnifiedEntityManager($c->get('DataSourceResolver')), true)
        // The fiscal-year selection (FiscalYearSelection) remembers per session: the REAL manager —
        // in the CLI it starts no session, $_SESSION is a plain array that outlives a re-wiring
        // (= one browser session over several requests).
        ->set('SessionManager', \Z77\Core\Session\SessionManager::class, true)
    ;
    DI::getCacheManager()->setCacheDir($base . '/var/cache');
};
$withLegacyVatAccounts(['input-material' => '1170']);
$e = caught(fn() => (new LedgerService(DI::getUnifiedEntityManager()))->vatAccountFor('input-material'), \Z77\Module\Financial\Services\VatAccountUnavailableException::class);
check('O8d a leftover `vatAccounts` in financialConfig is refused with a GERMAN sentence naming the move to the mandator (E2) — never read as a second source', $e !== null && $e instanceof \UnexpectedValueException
    && str_contains($e->getMessage(), 'vatAccounts') && str_contains($e->getMessage(), 'Mandant') && $e->reason === 'legacy-config');
$legacy = $oneLine()->fromPost($row('6500', '1020', '400.00', '2032-11-15', 'VM'));
check('O8d2 … the one-line form REFUSES the MwSt row with that sentence (no exception, no silent posting on the old key), and the journal pages carry it as a band',
    $legacy->toRequest() === null && array_filter($legacy->generalErrors(), fn($m) => str_contains($m, 'vatAccounts')) !== []
    && (new LedgerService(DI::getUnifiedEntityManager()))->vatAccountNotice() !== null && str_contains((new LedgerService(DI::getUnifiedEntityManager()))->vatAccountNotice(), 'vatAccounts'));
check('O8d3 … a line WITHOUT VAT still posts under the leftover key, and startFrom() on a taxed one-line entry falls back to the Sammelbuchung (false) instead of throwing (P5c)',
    $oneLine()->fromPost($row('6500', '1020', '10.00'))->toRequest() !== null
    && $oneLine()->startFrom(DI::getUnifiedEntityManager()->getRepository(JournalEntry::class)->find((int) $entryRow('2032-33', $inputRef->number)['id'])) === false);
$em      = $wireDi();   // the package config again
$entries = $em->getRepository(JournalEntry::class);
$manual  = new ManualEntryService($em, 'buchhalter');
$ledger  = new LedgerService($em);
check('O8e the mandator\'s start values map input-material → 1170, input-other → 1171, output → 2200 (verified against res/charts/kmu.json); no account for zero / exempt / reverse-charge',
    $ledger->vatAccountFor('input-material') === '1170' && $ledger->vatAccountFor('input-other') === '1171'
    && $ledger->vatAccountFor('standard') === '2200' && $ledger->vatAccountFor('reduced') === '2200' && $ledger->vatAccountFor('special') === '2200'
    && $ledger->vatAccountFor('zero') === null && $ledger->vatAccountFor('exempt') === null && $ledger->vatAccountFor('reverse-charge') === null
    && !str_contains(file_get_contents($package . '/src/App/Config/financialConfig.inc.php'), "'vatAccounts' =>")
    && (function () use ($package) {
        $chart = array_column(json_decode(file_get_contents($package . '/res/charts/kmu.json'), true), null, 'number');
        return ($chart['1170']['postable'] ?? false) && str_contains($chart['1170']['name'], 'Vorsteuer') && ($chart['1171']['postable'] ?? false) && str_contains($chart['1171']['name'], 'Investitionen')
            && ($chart['2200']['postable'] ?? false) && str_contains($chart['2200']['name'], 'Geschuldete MWST');
    })());
$bad = $oneLine()->fromPost(['debit' => '1020', 'date' => '2032-11-15', 'text' => '', 'credit' => '1020 Bankguthaben', 'amount' => '-5', 'vat' => '1', 'tax_code' => '', 'tax_amount' => '']);
check('O9 field errors: text, the same account on both sides («1020 Bankguthaben» is read as 1020), a negative amount; the MwSt row on without a code', $bad->toRequest() === null
    && $bad->error('text') !== '' && str_contains($bad->error('credit'), 'dasselbe Konto') && str_contains($bad->error('amount'), 'grösser als 0') && str_contains($bad->error('tax_code'), 'MWST-Code wählen'));
check('O9b a group or an unknown account is refused on its field', (function () use ($oneLine, $row) {
    $f = $oneLine()->fromPost($row('100', '9999', '5.00'));
    return $f->toRequest() === null && str_contains($f->error('debit'), 'Gruppe') && str_contains($f->error('credit'), 'gibt es nicht');
})());
check('O9c a code without a rate on the date is a field error, never 0 %', (function () use ($oneLine, $row) {
    $f = $oneLine()->fromPost($row('6500', '1020', '400.00', '2017-06-30', 'VM'));
    return $f->toRequest() === null && str_contains($f->error('tax_code'), 'kein Satz');
})());

echo "O. … editing an entry of the one-line shape\n";
$form = $oneLine();
$posted = $entries->findByRef($inputRef);
check('O10 the 3-line VM entry has the one-line shape: Soll 6500, Haben 1020, Betrag 400.00, MwSt on, VM — the tax field EMPTY (the stored 29.97 is the computed value), the stored value in the hint', $form->startFrom($posted)
    && [$form->debit(), $form->credit(), $form->amount(), $form->vat(), $form->taxCode(), $form->taxAmount()] === ['6500', '1020', '400.00', true, 'VM', ''] && str_contains($form->vatHint(), 'Gespeichert') && str_contains($form->vatHint(), '29.97'));
check('O10b … and so do the plain 2-line entry and the zero-rated one; the output split too', $oneLine()->startFrom($entries->findByRef($plainRef)) && $oneLine()->startFrom($entries->findByRef($zeroRef)) && $oneLine()->startFrom($entries->findByRef($outputRef)));
$compoundRef = $manual->create($expenseRequest('2032-11-18', 'Sammelbuchung mit Zeilentext'));
check('O10c an entry with a line text (here the harness\'s expense) is NOT one-line — it is edited in the Sammelbuchung form', !$oneLine()->startFrom($entries->findByRef($compoundRef)));
check('O10d the unchanged form rebuilds exactly the stored lines (a save without a change changes nothing)', $shape($form->toRequest()) === ['6500 D 370.03 VM 810 370.03 29.97', '1170 D 29.97', '1020 C 400.00']);
[$editId, $editVersion] = [(int) $posted->getId(), $posted->getVersion()];
$manual->update($editId, $editVersion, $form->fromPost(['form' => 'one-line', 'version' => (string) $editVersion] + $row('6500', '1020', '500.00', '2032-11-15', 'VM'))->toRequest());
$editedRows = $lineRows($editId);
check('O11 edited one-line to 500.00 (tax recomputed: 37.47, net 462.53) through ManualEntryService::update(): three lines, version bumped, one change row',
    array_map(fn($r) => $r['account_number'] . ' ' . $r['debit'] . '/' . $r['credit'] . ' ' . $r['tax_amount'], $editedRows) === ['6500 462.53/0.00 37.47', '1170 37.47/0.00 ', '1020 0.00/500.00 ']
    && (int) $entryRow('2032-33', $inputRef->number)['version'] === $editVersion + 1 && count($em->getRepository(EntryChange::class)->forEntry($editId)) === 1);

echo "O. … the screen: trait + templates, rendered without a web server\n";
/**
 * A request double with GET and POST. DI::set() never replaces a registered service, so the
 * double is registered once per wiring and reads the superglobals the call sets.
 */
$useRequest = function (array $get, ?array $post = null, bool $keepSession = false): void {
    $_GET  = $get;
    if (!$keepSession) { $_SESSION = []; }   // a new session unless the check follows one (FY-SEL)
    $_POST = $post ?? [];
    $GLOBALS['z77TestIsPost'] = $post !== null;
    DI::getInstance()->set('Request', fn() => new class {
        public function getGetParameter(string $p): mixed { return $_GET[$p] ?? null; }
        public function isPost(): bool { return $GLOBALS['z77TestIsPost']; }
        public function getPostParameters(): array { return $_POST; }
        public function getMode(): \Z77\Core\Http\RequestMode { return !empty($GLOBALS['z77TestFetch']) ? \Z77\Core\Http\RequestMode::Fetch : \Z77\Core\Http\RequestMode::Page; }
    }, true);
};
$em = $wireDi();   // a fresh wiring: the report section registered a GET-only Request double
/** A host double of the journal trait for the add pages: captures context, sections, flashes and the redirect. */
$journalHost = function () {
    $host = new class {
        use JournalControllerTrait { listAction as public; addAction as public; addCompoundAction as public; editAction as public; }
        public array $context = [];
        public object $layoutManager;
        public object $messageService;
        public object $help;
        public ?string $redirectedTo = null;
        public function __construct()
        {
            $this->help = new \Z77\Core\Services\HelpService();
            $this->layoutManager = new class {
                public array $sections = [];
                public function removeSection(string $s): void { unset($this->sections[$s]); }
                public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
            };
            $this->messageService = new class {
                public array $flashes = [];
                public function pushFlashAfterRedirect(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
                public function pushFlash(string $type, string $message): void { $this->flashes[] = [$type, $message, 'in-place']; }
            };
        }
        protected function em() { return DI::getUnifiedEntityManager(); }
        protected function fetch(): \Z77\Core\Http\Response\FetchResponse { return new \Z77\Core\Http\Response\FetchResponse(); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
        protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
        // The session actor is not wired in the harness — name it, as a CLI caller must.
        private function manualEntryService(): ManualEntryService { return new ManualEntryService($this->em(), 'buchhalter'); }
    };
    return $host;
};
$render = fn($host) => implode('', array_map(fn($p) => $renderer->partial($p, $host->context), $host->layoutManager->sections['main'] ?? []));

$useRequest(['year' => '2032-33', 'date' => '2032-11-20']);
$host = $journalHost();
$host->addAction();
check('O12 GET add lands on the journal page — the capture form lives there now (FIN-JOURNAL-CAPTURE-001)', $host->redirectedTo === '/backend/finance/journal/list?date=2032-11-20');
$host = $journalHost();
$host->listAction();
$html = $render($host);
$tools = implode('', array_map(fn($p) => $renderer->partial($p, $host->context), $host->layoutManager->sections['hc2'] ?? []));
$act   = implode('', array_map(fn($p) => $renderer->partial($p, $host->context), $host->layoutManager->sections['hc1'] ?? []));
check('O12a the journal page: the one-line capture form ABOVE the list, the date from ?date= kept (it lies in the selected ?year=, the list\'s year), Bu-Nr «neu», the fields in the wdv order, the first field focused',
    $host->layoutManager->sections['main'] === ['Backend/JournalController/oneLine', 'Backend/JournalController/listAction'] && $host->context['form']->date() === '2032-11-20' && $host->context['year']->getCode() === '2032-33'
    && str_contains($html, '>neu<') && preg_match('/name="debit".*name="date".*Bu-Nr.*name="text".*name="credit".*name="amount"/s', $html) === 1
    && str_contains($html, 'id="journal-capture"') && preg_match('/name="debit"[^>]*autofocus/', $html) === 1 && strpos($html, 'id="journal-capture"') < strpos($html, 'id="journal-find"'));
check('O12b the MwSt row is a CSS reveal: a submitted checkbox (be-reveal__toggle) BEFORE the row and the panel, siblings; its control is a SWITCH «MwSt» in the row right after Betrag (`.be-switch--for`, a label of the checkbox — owner 2026-10-08), not in the toolbar; no <script>, no placeholder in the form, no inline handler',
    preg_match('/<div class="be-reveal">\s*<input class="be-reveal__toggle" type="checkbox" id="journal-vat" name="vat" value="1">\s*<div class="be-form__row"/', $html) === 1
    && preg_match('/name="amount".*<label class="be-switch be-switch--for" for="journal-vat"[^>]*>\s*<span class="be-switch__label">MwSt<\/span>\s*<span class="be-switch__track"><span class="be-switch__thumb"><\/span><\/span>\s*<\/label>/s', $html) === 1
    && !str_contains($tools, 'journal-vat') && substr_count($html, 'for="journal-vat"') === 1
    && str_contains($html, '<label for="journal-number">Bu-Nr</label>') && str_contains($html, '<div class="be-reveal__panel">')
    && stripos($html . $tools, '<script') === false && preg_match('/\son[a-z]+=/i', $html . $tools) === 0
    && !str_contains(substr($html, 0, strpos($html, 'id="journal-find"')), 'placeholder='));
check('O12c no explanatory paragraph, no buttons in the capture form — «Buchen» sits in the action cell (ADR-033 rev. 2026-10-08, green confirm + check glyph) and submits the form from outside (form="journal-capture"); not in the toolbar any more',
    !str_contains($html, 'Das Soll-Konto erhält') && !str_contains($html, 'Letzte Buchungen') && !str_contains(substr($html, 0, strpos($html, 'id="journal-find"')), 'type="submit"')
    && preg_match('/<button type="submit" form="journal-capture" class="be-btn be-btn--confirm"[^>]*>\s*<svg[^>]*><use href="#icon-check"\/><\/svg>\s*<span class="be-btn__label">Buchen<\/span>/', $act) === 1
    && !str_contains($tools, 'type="submit"'));
check('O12d below: the year\'s latest entries, newest first, each with its state icon, which opens it (edit or detail)',
    count($host->context['rows']) >= 5 && $host->context['rows'][0]['number'] > $host->context['rows'][1]['number']
    && preg_match('#/backend/finance/journal/(edit|detail)\?id=' . $entryRow('2032-33', $outputRef->number)['id'] . '"#', $html) === 1 && str_contains($html, 'be-list__state--editable'));
$scss = file_get_contents(__DIR__ . '/../packages/module-backend/res/scss/components/_forms.scss');
$css  = file_get_contents(__DIR__ . '/../packages/module-backend/res/assets/css/base.css');
$switchScss = file_get_contents(__DIR__ . '/../packages/module-backend/res/scss/components/_switch.scss');
check('O12e the reveal is CSS — the :checked sibling rule in the backend SCSS source AND in the compiled base.css; the remote switch (`.be-switch--for`) takes its on look and its focus ring from the same checkbox through `~` — no :has(), no script',
    str_contains($scss, '.be-reveal__toggle:checked ~ .be-reveal__panel { display: block; }')
    && str_contains($css, '.be-reveal__toggle:checked~.be-reveal__panel{display:block}')
    && str_contains($switchScss, '.be-reveal__toggle:checked ~ * .be-switch--for .be-switch__track') && str_contains($switchScss, '.be-reveal__toggle:focus-visible ~ * .be-switch--for .be-switch__track')
    && str_contains($css, '.be-reveal__toggle:checked~* .be-switch--for .be-switch__thumb{transform:translateX(1rem)}')
    && str_contains($css, '.be-reveal__toggle:focus-visible~* .be-switch--for .be-switch__track{') && !str_contains($switchScss . $scss, ':has(.be-reveal'));

$before = (int) $rangeOf('journal-entry.2032-33');
$useRequest(['year' => '2032-33'], $row('6500', '1020', '108.10', '2032-11-20', 'VM', '', 'Papeterie'));
$host = $journalHost();
$host->addAction();
$new = (int) $rangeOf('journal-entry.2032-33');
check("O13 POST add with MwSt: posted as 2032-33/{$new}, the flash names the number, back to the journal page with the same date",
    $new === $before + 1 && $host->messageService->flashes === [['success', "Buchung 2032-33/{$new} erfasst"]]
    && $host->redirectedTo === '/backend/finance/journal/list?date=2032-11-20'
    && array_map(fn($r) => $r['account_number'] . ' ' . $r['debit'] . '/' . $r['credit'], $lineRows((int) $entryRow('2032-33', $new)['id'])) === ['6500 100.00/0.00', '1170 8.10/0.00', '1020 0.00/108.10']);
$useRequest(['year' => '2032-33'], $row('6500', '1020', '400.00', '2032-11-20', 'VM', '31.00', 'zu viel Steuer'));
$host = $journalHost();
$host->addAction();
$html = $render($host);
check('O14 POST with a refused tax amount: no posting, the journal page again with the form, its error, the MwSt row still open (checked) and the values kept', $host->redirectedTo === null && (int) $rangeOf('journal-entry.2032-33') === $new
    && $host->layoutManager->sections['main'] === ['Backend/JournalController/oneLine', 'Backend/JournalController/listAction']
    && str_contains($html, 'erlaubt sind höchstens') && str_contains($html, 'name="vat" value="1" checked') && str_contains($html, 'value="31.00"') && str_contains($html, '<option value="VM" selected>'));
$useRequest(['year' => '2032-33']);
$host = $journalHost();
$host->addCompoundAction();
check('O15 GET add-compound lands on the journal page in the Sammelbuchung mode', str_starts_with((string) $host->redirectedTo, '/backend/finance/journal/list?mode=sammel&date=2032-'));
$useRequest(['mode' => 'sammel', 'year' => '2032-33', 'date' => '2032-11-20']);
$host = $journalHost();
$host->listAction();
$html = $render($host);
$tools = implode('', array_map(fn($p) => $renderer->partial($p, $host->context), $host->layoutManager->sections['hc2'] ?? []));
$act   = implode('', array_map(fn($p) => $renderer->partial($p, $host->context), $host->layoutManager->sections['hc1'] ?? []));
check('O15b ?mode=sammel: the Sammelbuchung as the capture form — no «1020» / «0.00» placeholder, no explanatory paragraph, no «ausgeglichen» at 0.00 / 0.00, «Weitere Zeilen» in the form, «Buchen» (op=save) in the action cell, «Sammel» the active tab in the toolbar, no MwSt trigger there',
    $host->layoutManager->sections['main'] === ['Backend/JournalController/form', 'Backend/JournalController/listAction'] && str_contains($html, 'id="journal-capture"') && !str_contains($html, 'placeholder="1020"') && !str_contains($html, 'placeholder="0.00"')
    && !str_contains($html, 'Eine Zeile pro Konto') && !str_contains($html, 'ausgeglichen') && str_contains($html, 'value="more">Weitere Zeilen')
    && str_contains($act, 'name="op" value="save"') && !str_contains($tools, 'journal-vat') && preg_match('/be-viewtabs__tab is-active" href="\/backend\/finance\/journal\/list\?mode=sammel/', $tools) === 1 && stripos($html, '<script') === false);

echo "S. the journal list: search in the database, sort, pages, state (FIN-JOURNAL-CAPTURE-001)\n";
$listOf = function (array $get) use ($useRequest, $journalHost, $render) {
    $useRequest($get);
    $host = $journalHost();
    $host->listAction();
    return [$host, $render($host)];
};
$numbersOf = fn($host) => array_map(fn($r) => $r['number'], $host->context['rows']);
[$h, $html] = $listOf(['year' => '2032-33', 'date' => '2032-11-20', 'f_text' => 'Papeterie']);
check('S1 the text magnifier finds «Papeterie» (contains), and only entries that carry it — a database query, the badge counts the matches',
    $h->context['rows'] !== [] && array_filter($h->context['rows'], fn($r) => !str_contains($r['entry']->getText(), 'Papeterie')) === [] && $h->context['paging']->total === count($h->context['rows'])
    && str_contains($html, 'value="Papeterie"') && str_contains($html, 'be-list__find--active'));
[$h] = $listOf(['year' => '2032-33', 'date' => '2032-11-20', 'f_debit' => '6500 Büromaterial']);
check('S2 «Soll 6500» (a leading number is enough) finds only entries with 6500 on the DEBIT side', $h->context['rows'] !== [] && array_filter($h->context['rows'], function ($r) {
    foreach ($r['entry']->getLines() as $l) { if ($l->getAccount()->getNumber() === '6500' && $l->getDebit()->isPositive()) { return false; } }
    return true;
}) === []);
[$h] = $listOf(['year' => '2032-33', 'date' => '2032-11-20', 'f_credit' => '6500']);
check('S2b … and «Haben 6500» does not find them', array_filter($h->context['rows'], function ($r) {
    foreach ($r['entry']->getLines() as $l) { if ($l->getAccount()->getNumber() === '6500' && $l->getCredit()->isPositive()) { return false; } }
    return true;
}) === []);
[$h] = $listOf(['year' => '2032-33', 'date' => '2032-11-20', 'f_amount' => '108,10']);
check('S3 the amount magnifier: «108,10» finds the entries whose total is 108.10', $h->context['rows'] !== [] && array_filter($h->context['rows'], fn($r) => $r['entry']->total()->toDecimal() !== '108.10') === []);
[$h] = $listOf(['year' => '2032-33', 'date' => '2032-11-20', 'f_date' => '20.11.2032']);
[$hm] = $listOf(['year' => '2032-33', 'date' => '2032-11-20', 'f_date' => '11.2032']);
check('S4 the date magnifier: a day, and a month (11.2032) that holds at least that day\'s entries', $h->context['rows'] !== [] && array_filter($h->context['rows'], fn($r) => $r['entry']->getDate()->format('Y-m-d') !== '2032-11-20') === []
    && count($hm->context['rows']) >= count($h->context['rows']) && array_filter($hm->context['rows'], fn($r) => $r['entry']->getDate()->format('Y-m') !== '2032-11') === []);
[$h, $html] = $listOf(['year' => '2032-33', 'date' => '2032-11-20', 'f_date' => '31.02.2032']);
[$hAll] = $listOf(['year' => '2032-33', 'date' => '2032-11-20']);
check('S5 an unreadable date is marked invalid and does NOT narrow — a typo must not look like «nothing found»', $h->context['filter']->isInvalid('f_date') && str_contains($html, 'aria-invalid="true"') && count($h->context['rows']) === count($hAll->context['rows']));
$nr = $hAll->context['rows'][2]['number'];
[$h] = $listOf(['year' => '2032-33', 'date' => '2032-11-20', 'f_nr' => (string) $nr]);
check('S6 the number magnifier: exactly that number', $numbersOf($h) === [$nr]);
[$h, $html] = $listOf(['year' => '2032-33', 'date' => '2032-11-20', 'f_text' => 'Umbuchung', 'all' => '1']);
$yearSlot = implode('', array_map(fn($p) => $renderer->partial($p, $h->context), $h->layoutManager->sections['railSelect'] ?? []));
check('S7 «Alle Jahre» (all=1): the search reaches the other years (Umbuchung is posted in 2027-28), the number shows its year; the state is the last entry of the year dropdown at the top of the rail (owner 2026-10-08), not a toggle in the list header',
    array_filter($h->context['rows'], fn($r) => $r['entry']->getFiscalYear()->getCode() === '2027-28') !== []
    && str_contains($html, '2027-28/') && !str_contains($html, 'alle Geschäftsjahre</a>') && str_contains($html, '· alle Geschäftsjahre')
    && preg_match('/<span class="be-btn__label"><span class="be-shell-select__value">Alle Jahre<\/span><\/span>/', $yearSlot) === 1 && str_contains($yearSlot, 'href="/backend/finance/journal/list?all=1" aria-current="true">Alle Jahre</a>')
    && substr_count($yearSlot, 'aria-current="true"') === 1);
[$h, $html] = $listOf(['year' => '2032-33', 'date' => '2032-11-20', 'sort' => 'amount', 'dir' => 'asc']);
$totals = array_map(fn($r) => $r['entry']->total()->toDecimal() * 100, $h->context['rows']);
$sorted = $totals; sort($sorted);
check('S8 sort by amount, ascending — on the server; the header marks the column (data-sort="asc") and its link flips to descending (the default, so no dir= in the URL)', $totals === $sorted && str_contains($html, 'sort=amount" data-sort="asc"'));
$entriesRepo = DI::getUnifiedEntityManager()->getRepository(JournalEntry::class);
$y3233 = DI::getUnifiedEntityManager()->getRepository(FiscalYear::class)->findOneBy(['code' => '2032-33']);
$all3233 = $entriesRepo->countSearch(new \Z77\Module\Financial\Reports\JournalSearch((int) $y3233->getId()));
$page2 = $entriesRepo->search(new \Z77\Module\Financial\Reports\JournalSearch((int) $y3233->getId()), 2, 2);
check('S9 paging in SQL: a page is LIMIT/OFFSET over the same order — page 2 of size 2 holds the 3rd and 4th newest', $all3233 === count($hAll->context['rows']) && array_map(fn($e) => $e->getNumber(), $page2) === array_slice($numbersOf($hAll), 2, 2));
check('S9b the date parser: day, ISO day, month, year; a wrong day or month is null', \Z77\Module\Financial\Ui\JournalFilter::dateRange('5.2.2032') === ['2032-02-05', '2032-02-05']
    && \Z77\Module\Financial\Ui\JournalFilter::dateRange('02.2032') === ['2032-02-01', '2032-02-29'] && \Z77\Module\Financial\Ui\JournalFilter::dateRange('2032') === ['2032-01-01', '2032-12-31']
    && \Z77\Module\Financial\Ui\JournalFilter::dateRange('13.2032') === null && \Z77\Module\Financial\Ui\JournalFilter::dateRange('30.02.2032') === null);
[$h, $html] = $listOf(['year' => '2027-28', 'date' => '2027-12-01', 'deleted' => '1']);
check('S10 no «gelöschte zeigen» any more (owner 2026-10-08 — the change log is a screen of its own): `deleted=1` is ignored, the deleted number 4 of 2027-28 is NOT in the list, no toggle, no gap row, no link carries it',
    !in_array(4, $numbersOf($h), true) && !str_contains($html, 'gelöschte zeigen') && !str_contains($html, 'be-list__state--deleted') && !str_contains($html, 'gelöscht: «')
    && !str_contains($html, 'deleted=') && !property_exists($h->context['filter'], 'showDeleted') && !array_key_exists('gapsApply', $h->context));
check('S11 generated entries carry the «generated» state, a closed period «closed» — the service rules, mirrored for the icon', (function () use ($listOf) {
    [$h] = $listOf(['year' => '2032-33', 'date' => '2032-11-20', 'all' => '1']);
    $states = array_unique(array_map(fn($r) => $r['state'], $h->context['rows']));
    return in_array('editable', $states, true) && array_diff($states, ['editable', 'generated', 'closed', 'vat-settled']) === [];
})());
[$h, $html] = $listOf(['year' => '2032-33', 'date' => '2032-11-20', 'f_text' => 'Papeterie', 'sort' => 'date']);
check('S12 every link keeps the state: the sort links carry the search and the capture date; «Suche zurücksetzen» drops only the search',
    str_contains($html, 'href="/backend/finance/journal/list?date=2032-11-20&amp;f_text=Papeterie&amp;sort=amount"') && str_contains($html, 'Suche zurücksetzen')
    && preg_match('/href="\/backend\/finance\/journal\/list\?date=2032-11-20&amp;sort=date"[^>]*>Suche zurücksetzen/', $html) === 1);

$GLOBALS['z77TestFetch'] = true;
[$h, $html] = $listOf(['year' => '2032-33', 'date' => '2032-11-20', 'f_text' => 'Papeterie']);
$GLOBALS['z77TestFetch'] = false;
check('S13 a FETCH of the journal (core.js «fetch regions»: sort, page, search) answers the list ALONE — no capture form, no header slots; the region, its links and its search form are marked',
    $h->layoutManager->sections['main'] === ['Backend/JournalController/listAction'] && empty($h->layoutManager->sections['hc2']) && empty($h->layoutManager->sections['hc3'])
    && str_contains($html, 'data-fetch-region="journal-list"') && str_contains($html, 'data-fetch-region-form') && str_contains($html, 'class="be-list__sort" data-fetch-region-link')
    && !str_contains($html, 'id="journal-capture"'));
[$h, $html] = $listOf(['year' => '2032-33', 'date' => '2032-11-20']);
$first = $h->context['rows'][0]['entry'];
check('S14 the state icon is a link: an editable entry opens its edit page', $h->context['rows'][0]['state'] === 'editable'
    && str_contains($html, 'class="be-list__cell be-list__state be-list__state--editable" href="/backend/finance/journal/edit?id=' . $first->getId() . '" data-window-open="/backend/finance/journal/edit?id=' . $first->getId() . '"'));
$coreJs = file_get_contents(__DIR__ . '/../packages/kernel/shared/res/assets/js/core.js');
check('S15 the fetch region is the shared core.js contract (module-agnostic): links and GET forms inside [data-fetch-region] reload it, the address follows',
    str_contains($coreJs, "a[data-fetch-region-link]") && str_contains($coreJs, "form[data-fetch-region-form]") && str_contains($coreJs, 'history.replaceState')
    && str_contains(file_get_contents(__DIR__ . '/../packages/kernel/shared/res/assets/js/core.min.js'), 'data-fetch-region'));

echo "CL. the change log screen: Finanzen › Änderungsprotokoll (owner 2026-10-08)\n";
$clDefinition = \Z77\Module\Financial\Ui\ChangeLogListing::definition();
check('CL1 the change log is a standard list: id change-log-find, region change-log-find-list, the state slot · Zeitpunkt · Nr. · Art · Text · Wer, four searched fields, newest change first, 50 per page, the extra «all»',
    $clDefinition->id === 'change-log-find' && $clDefinition->region() === 'change-log-find-list'
    && array_map(fn($c) => $c->label, $clDefinition->columns()) === ['', 'Zeitpunkt', 'Nr.', 'Art', 'Text', 'Wer']
    && $clDefinition->searchKeys() === ['f_at', 'f_nr', 'f_text', 'f_who'] && $clDefinition->sorts() === ['at', 'number', 'text', 'who']
    && $clDefinition->defaultSort === 'at' && $clDefinition->descendingFirst('at') && $clDefinition->pageSize === 50 && $clDefinition->extras() === ['all' => ['', '1']]);
$clState = $clDefinition->read(['f_at' => '10.2030', 'f_nr' => 'sieben', 'f_who' => 'buch', 'sort' => 'number']);
$clSearch = \Z77\Module\Financial\Ui\ChangeLogListing::search($clState, 42);
check('CL2 the search is parsed strictly: «10.2030» the month, a number that is no number is INVALID and does not narrow, the scope the given year — «Alle Jahre» none',
    $clSearch->from === '2030-10-01' && $clSearch->to === '2030-10-31' && $clSearch->number === null && $clState->isInvalid('f_nr') && $clSearch->who === 'buch'
    && $clSearch->fiscalYearId === 42 && $clSearch->sort === 'number' && $clSearch->descending
    && \Z77\Module\Financial\Ui\ChangeLogListing::search($clDefinition->read(['all' => '1']), 42)->fiscalYearId === null);
$clRepo = DI::getUnifiedEntityManager()->getRepository(EntryChange::class);
$clYear = DI::getUnifiedEntityManager()->getRepository(FiscalYear::class)->findOneBy(['code' => '2030-31']);
$clOf   = fn(array $args) => $clRepo->search(new \Z77\Module\Financial\Repositories\EntryChangeSearch(...$args), 0, 50);
$clAll  = $clOf(['fiscalYearId' => (int) $clYear->getId()]);
check('CL3 the repository searches the log of 2030-31 in SQL: the edit of 7 and the delete of 8, newest first, counted the same',
    array_map(fn($c) => $c->getEntryNumber() . ' ' . $c->getAction(), $clAll) === ['8 delete', '7 update']
    && $clRepo->countSearch(new \Z77\Module\Financial\Repositories\EntryChangeSearch((int) $clYear->getId())) === 2);
$today = (new \DateTimeImmutable('today'))->format('Y-m-d');
check('CL4 each criterion narrows with a bound value: the number, the text before («Bankspesen») OR after («korrigiert»), case-insensitive, who («BUCH»), the day of the change; a LIKE wildcard typed is a literal («%» finds nothing)',
    array_map(fn($c) => $c->getEntryNumber(), $clOf(['fiscalYearId' => (int) $clYear->getId(), 'number' => 7])) === [7]
    && array_map(fn($c) => $c->getEntryNumber(), $clOf(['fiscalYearId' => (int) $clYear->getId(), 'text' => 'bankspesen'])) === [8]
    && array_map(fn($c) => $c->getEntryNumber(), $clOf(['fiscalYearId' => (int) $clYear->getId(), 'text' => 'korrigiert'])) === [7]
    && count($clOf(['fiscalYearId' => (int) $clYear->getId(), 'who' => 'BUCH'])) === 2
    && count($clOf(['fiscalYearId' => (int) $clYear->getId(), 'from' => $today, 'to' => $today])) === 2
    && $clOf(['fiscalYearId' => (int) $clYear->getId(), 'from' => '2000-01-01', 'to' => '2000-12-31']) === []
    && $clOf(['fiscalYearId' => (int) $clYear->getId(), 'text' => '%']) === [] && $clOf(['fiscalYearId' => (int) $clYear->getId(), 'who' => '_']) === []);
check('CL5 every sort runs (fixed ORDER BY map, never the key interpolated), and over all years the log holds more than 2030-31',
    array_map(fn($c) => $c->getEntryNumber(), $clOf(['fiscalYearId' => (int) $clYear->getId(), 'sort' => 'number', 'descending' => false])) === [7, 8]
    && array_map(fn($c) => $c->getEntryNumber(), $clOf(['fiscalYearId' => (int) $clYear->getId(), 'sort' => 'text', 'descending' => false])) === [8, 7]
    && count($clOf(['sort' => 'who'])) > 2
    && throws(fn() => new \Z77\Module\Financial\Repositories\EntryChangeSearch(sort: 'id; DROP TABLE x'), \InvalidArgumentException::class)
    && preg_match('/\{\$search->(who|text|from|to)\}/', file_get_contents($package . '/src/Repositories/EntryChangeRepository.php')) === 0);
/** A host double of the change log trait: context, sections, flashes, the redirect. */
$clHost = function () {
    return new class {
        use \Z77\Module\Financial\Ui\ChangeLogControllerTrait { listAction as public; detailAction as public; }
        public array $context = [];
        public object $layoutManager;
        public object $messageService;
        public ?string $redirectedTo = null;
        public function __construct()
        {
            $this->layoutManager = new class {
                public array $sections = [];
                public function removeSection(string $s): void { unset($this->sections[$s]); }
                public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
            };
            $this->messageService = new class {
                public array $flashes = [];
                public function pushFlashAfterRedirect(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
            };
        }
        protected function em() { return DI::getUnifiedEntityManager(); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
        protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
    };
};
$clPage = function (array $get, string $action = 'list') use ($useRequest, $clHost, $renderer) {
    $useRequest($get);
    $host = $clHost();
    $host->{$action . 'Action'}();
    // `main` is pinned by the layout (ChangeLogLayout) to the list; the detail swaps it.
    $main = $host->layoutManager->sections['main'] ?? ($action === 'list' ? ['Backend/ChangeLogController/listAction'] : []);
    $html = implode('', array_map(fn($p) => $renderer->partial($p, $host->context), $main));
    $rail = implode('', array_map(fn($p) => $renderer->partial($p, $host->context), $host->layoutManager->sections['railSelect'] ?? []));
    return [$host, $html, $rail];
};
[$h, $html, $rail] = $clPage(['year' => '2030-31']);
check('CL6 the host renders the list of 2030-31: the region, the kernel head and find form, the rows (Zeitpunkt, Nr., Art badge, the text before, who), the state icon opens the change as a window; the year selection at the top of the rail with «Alle Jahre»; the action cell and the toolbar empty',
    str_contains($html, 'data-fetch-region="change-log-find-list"') && str_contains($html, '<form id="change-log-find" method="get" action="/backend/finance/change-log/list"')
    && str_contains($html, 'id="change-log-find-f_text"') && str_contains($html, 'Geschäftsjahr 2030-31') && str_contains($html, '>Bankspesen<')
    && str_contains($html, 'Miete Oktober <small class="be-list__cell--muted">→ Miete Oktober (korrigiert)</small>') && str_contains($html, '>gelöscht</span>') && str_contains($html, '>geändert</span>')
    && preg_match('#href="/backend/finance/change-log/detail\?id=(\d+)" data-window-open="/backend/finance/change-log/detail\?id=\1"#', $html) === 1
    && str_contains($html, 'for="change-log-find-f_who"') && $h->context['paging']->total === 2
    && $h->layoutManager->sections['railSelect'] === ['Backend/partials/fiscalYearSelect'] && empty($h->layoutManager->sections['hc1']) && empty($h->layoutManager->sections['hc2'])
    && str_contains($rail, 'href="/backend/finance/change-log/list?year=2030-31" aria-current="true"') && str_contains($rail, 'href="/backend/finance/change-log/list?all=1"')
    && stripos($html, '<script') === false);
[$h, $html, $rail] = $clPage(['year' => '2030-31', 'all' => '1', 'f_text' => 'Umbuchung']);
check('CL7 «Alle Jahre»: the search reaches 2027-28 (the deleted «Umbuchung A»), the number shows its year, «Suche zurücksetzen» keeps all=1',
    str_contains($html, '2027-28/4') && str_contains($html, 'alle Geschäftsjahre') && str_contains($rail, '?all=1" aria-current="true"')
    && str_contains($html, 'href="/backend/finance/change-log/list?all=1">Suche zurücksetzen'));
$GLOBALS['z77TestFetch'] = true;
[$h] = $clPage(['year' => '2030-31', 'f_nr' => '7']);
$GLOBALS['z77TestFetch'] = false;
check('CL8 a FETCH of the region (sort, page, search) answers the list alone — no rail selection added', empty($h->layoutManager->sections['railSelect']) && count($h->context['changes']) === 1);
$updateId = (int) $db->fetchOne("SELECT c.id FROM journal_entry_change c JOIN fiscal_year y ON y.id = c.fiscal_year_id WHERE y.code = '2030-31' AND c.entry_number = 7");
$deleteId = (int) $db->fetchOne("SELECT c.id FROM journal_entry_change c JOIN fiscal_year y ON y.id = c.fiscal_year_id WHERE y.code = '2030-31' AND c.entry_number = 8");
$GLOBALS['z77TestFetch'] = true;
[$h, $html] = $clPage(['id' => (string) $updateId], 'detail');
[$hd, $htmlD] = $clPage(['id' => (string) $deleteId], 'detail');
$GLOBALS['z77TestFetch'] = false;
check('CL9 the change as a WINDOW: before and after with their lines (1500.00 → 1600.00), who and when, a link to the entry (it exists); a delete shows «before» only, the gap, no entry link',
    $h->layoutManager->sections['main'] === ['Backend/ChangeLogController/detail'] && str_contains($html, 'data-window="journal-entry-change" data-window-entity="journal-entry-change:' . $updateId . '"')
    && str_contains($html, 'Vorher — 01.10.2030 · Miete Oktober</div>') && str_contains($html, 'Nachher — 01.10.2030 · Miete Oktober (korrigiert)') && str_contains($html, '1500.00') && str_contains($html, '1600.00')
    && str_contains($html, 'von buchhalter') && str_contains($html, '/backend/finance/journal/detail?id=' . $entryRow('2030-31', 7)['id'] . '" data-window-link')
    && str_contains($htmlD, 'Vorher — 15.10.2030 · Bankspesen') && !str_contains($htmlD, 'Nachher') && str_contains($htmlD, 'bleibt eine Lücke') && !str_contains($htmlD, '/backend/finance/journal/detail'));
[$h] = $clPage(['id' => '999999'], 'detail');
check('CL10 an unknown change → back to the list with a flash, no 500', $h->redirectedTo === '/backend/finance/change-log/list' && $h->messageService->flashes === [['error', 'Eintrag im Änderungsprotokoll nicht gefunden']]);
$clSeeds = json_decode((string) file_get_contents($package . '/data/framework/routing/navigation.d/module-financial.json'), true);
$clKeys  = array_column(array_filter($clSeeds, fn($e) => $e['parent_key'] === 'finanzen'), 'key');
$clSeed  = array_values(array_filter($clSeeds, fn($e) => $e['key'] === 'aenderungsprotokoll'))[0] ?? [];
check('CL11 the navigation seed: «Änderungsprotokoll» under Finanzen AFTER Auswertungen, route backend/finance/change-log/list (ADR-050, add-only); the host and its layout config exist in module-backend',
    $clKeys === ['journal', 'auswertungen', 'aenderungsprotokoll'] && ($clSeed['name'] ?? '') === 'Änderungsprotokoll' && ($clSeed['sort_key'] ?? null) === 2
    && ($clSeed['module'] ?? '') . '/' . ($clSeed['group'] ?? '') . '/' . ($clSeed['controller'] ?? '') . '/' . ($clSeed['action'] ?? '') === 'backend/finance/change-log/list'
    && str_contains((string) file_get_contents(dirname($package) . '/module-backend/src/Ui/Controllers/Finance/ChangeLogController.php'), 'use ChangeLogControllerTrait;')
    && str_contains((string) file_get_contents(dirname($package) . '/module-backend/src/Ui/Config/Finance/changeLogControllerConfig.inc.php'), 'ChangeLogLayout::config()'));
$clSource = file_get_contents($package . '/src/Ui/ChangeLogControllerTrait.php');
check('CL12 the screen is read-only: the trait writes nothing (no persist / remove / flush / service call)', !preg_match('/->(persist|remove|flush|update|delete|create)\(/', $clSource));

// The edit page checks the entity token: a CSRF double (register once per wiring, like the request).
DI::getInstance()->set('CsrfService', fn() => new class {
    public function generateEntityToken(string $context, int $id): string { return "tok-{$context}-{$id}"; }
    public function validateEntityToken(string $token, string $context, int $id): bool { return $token === "tok-{$context}-{$id}"; }
}, true);
$editId = (int) $entryRow('2032-33', $inputRef->number)['id'];
$useRequest(['id' => (string) $editId]);
$host = $journalHost();
$host->editAction();
$html = $render($host);
check('O17 GET edit of the one-line VM entry: the one-line form, filled (500.00, VM, 37.47), its number instead of «neu», the version, a way to the Sammelbuchung', $host->layoutManager->sections['main'] === ['Backend/JournalController/oneLine']
    && str_contains($html, 'name="form" value="one-line"') && str_contains($html, 'value="500.00"') && str_contains($html, 'name="tax_amount" value=""') && str_contains($host->help->render(), 'Gespeichert: MWST VM 8.1 % in 500.00: 37.47') && str_contains($html, 'name="vat" value="1" checked')
    && str_contains($html, '>' . $inputRef->number . '<') && str_contains($html, 'name="version" value="' . $entryRow('2032-33', $inputRef->number)['version'] . '"')
    && str_contains($html, 'edit?id=' . $editId . '&amp;form=compound') && !str_contains($html, 'Letzte Buchungen'));
$useRequest(['id' => (string) $editId, 'form' => 'compound']);
$host = $journalHost();
$host->editAction();
$html = $render($host);
$compoundId = (int) $entryRow('2032-33', $compoundRef->number)['id'];
check('O17b ?form=compound: the same entry in the Sammelbuchung form, with a link back to the one-line form', $host->layoutManager->sections['main'] === ['Backend/JournalController/form']
    && str_contains($html, 'Einzeilig bearbeiten') && count($host->context['form']->rows()) === 4);

echo "W. controller-led windows (ADR-047): the journal entry as a window\n";
$req = fn(array $get, array $post = []) => new class($get, $post) {
    public function __construct(private array $g, private array $p) {}
    public function getGetParameter(string $k): mixed { return $this->g[$k] ?? null; }
    public function getPostParameters(): array { return $this->p; }
};
check('W1 WindowOrigin: the three shapes pass, POST before GET, anything else is «page»', \Z77\Core\Http\WindowOrigin::of($req(['_origin' => 'region:journal-list'])) === 'region:journal-list'
    && \Z77\Core\Http\WindowOrigin::of($req(['_origin' => 'region:x'], ['_origin' => 'window:3'])) === 'window:3' && \Z77\Core\Http\WindowOrigin::of($req(['_origin' => 'page'])) === 'page'
    && \Z77\Core\Http\WindowOrigin::of($req(['_origin' => '"><script>'])) === 'page' && \Z77\Core\Http\WindowOrigin::of($req([])) === 'page' && \Z77\Core\Http\WindowOrigin::of($req(['_origin' => 'region:Journal List'])) === 'page');
$GLOBALS['z77TestFetch'] = true;
$useRequest(['id' => (string) $editId, '_origin' => 'region:journal-list']);
$host = $journalHost();
(fn() => $this->detailAction())->call($host);
$html = $render($host);
check('W2 the detail FETCHED is a window: the root declares mask + entity + title, «Bearbeiten» loads into the window, no way «back to the journal» (it is still there)',
    str_contains($html, 'data-window="journal-entry-detail" data-window-entity="journal-entry:' . $editId . '"') && str_contains($html, 'data-window-title="Buchung 2032-33/')
    && preg_match('/edit\?id=' . $editId . '"\s*data-window-link>Bearbeiten/', $html) === 1 && !str_contains($html, 'Zurück zum Journal'));
$host = $journalHost();
$host->editAction();
$html = $render($host);
check('W3 the edit form fetched as a window: its own mask on the same entity, the origin written into the form, «Abbrechen» stays in the window',
    str_contains($html, 'data-window="journal-entry-edit" data-window-entity="journal-entry:' . $editId . '"') && str_contains($html, 'name="_origin" value="region:journal-list"')
    && preg_match('/detail\?id=' . $editId . '"\s*data-window-link>Abbrechen/', $html) === 1);
// W4 saves on ANOTHER entry (the plain one-line one) — the O-series counts the change rows of $editId.
$wId = (int) $entryRow('2032-33', $plainRef->number)['id'];
$vW  = (int) $entryRow('2032-33', $plainRef->number)['version'];
$wDate = $entryRow('2032-33', $plainRef->number)['entry_date'];
$useRequest(['id' => (string) $wId], ['form' => 'one-line', 'version' => (string) $vW, 'entity_csrf' => 'tok-journalEntry-' . $wId, '_origin' => 'region:journal-list'] + $row('6500', '1020', "1'250.60", $wDate));
$host = $journalHost();
$answer = $host->editAction();
$commands = $answer instanceof \Z77\Core\Http\Response\FetchResponse ? (fn() => $this->commands)->call($answer) : [];
check('W4 a save in the window answers with INSTRUCTIONS for its origin: the read view back into the window, the journal list reloads — and a flash in place, no redirect',
    $answer instanceof \Z77\Core\Http\Response\FetchResponse && $host->redirectedTo === null
    && $commands === [['action' => 'open-window', 'url' => '/backend/finance/journal/detail?id=' . $wId, 'replace' => true], ['action' => 'refresh-region', 'name' => 'journal-list']]
    && ($host->messageService->flashes[0][2] ?? '') === 'in-place' && (int) $entryRow('2032-33', $plainRef->number)['version'] === $vW + 1);
$useRequest(['id' => (string) $wId], ['form' => 'one-line', 'version' => (string) ($vW + 1), 'entity_csrf' => 'tok-journalEntry-' . $wId, '_origin' => 'page'] + $row('6500', '1020', "1'250.50", $wDate));
$host = $journalHost();
$answer = $host->editAction();
$commands = $answer instanceof \Z77\Core\Http\Response\FetchResponse ? (fn() => $this->commands)->call($answer) : [];
check('W4b … from another origin the same action answers only for the window — the controller decides per origin', count($commands) === 1 && $commands[0]['action'] === 'open-window');
$GLOBALS['z77TestFetch'] = false;
[$h, $html] = $listOf(['year' => '2032-33', 'date' => '2032-11-20']);
check('W5 only the STATE ICON opens an entry, as a window (the href stays for ctrl-click / no script); every other cell is the label of its column\'s search field (owner 2026-09-29)',
    preg_match('/class="be-list__cell be-list__state [^"]+" href="\/backend\/finance\/journal\/(edit|detail)\?id=(\d+)" data-window-open="\/backend\/finance\/journal\/\1\?id=\2"/', $html) === 1
    && preg_match_all('/<a [^>]*href="\/backend\/finance\/journal\/(edit|detail)\?id=/', $html) === preg_match_all('/class="be-list__cell be-list__state /', $html)
    && str_contains($html, '<label class="be-list__cell" for="journal-find-f_text">') && str_contains($html, 'for="journal-find-f_nr"') && str_contains($html, 'for="journal-find-f_date"')
    && str_contains($html, 'for="journal-find-f_debit"') && str_contains($html, 'for="journal-find-f_credit"') && str_contains($html, 'for="journal-find-f_amount"'));
$coreJs = file_get_contents(__DIR__ . '/../packages/kernel/shared/res/assets/js/core.js');
check('W6 the window manager is the shared core.js contract: identity = mask + entity, the editable-field overlap check, the origin sent as _origin, forms as FormData, the commands close-window / open-window / refresh-region; the minified copy carries it',
    str_contains($coreJs, "mask + '|' + entity") && str_contains($coreJs, 'function _conflict') && str_contains($coreJs, "searchParams.set('_origin'") && str_contains($coreJs, 'new FormData(form')
    && str_contains($coreJs, "registerCommand('close-window'") && str_contains($coreJs, "registerCommand('open-window'") && str_contains($coreJs, "registerCommand('refresh-region'")
    && str_contains(file_get_contents(__DIR__ . '/../packages/kernel/shared/res/assets/js/core.min.js'), 'data-window-open'));

echo "H. help as a framework service (ADR-048): no text in the form, the help beside it\n";
$GLOBALS['z77TestFetch'] = true;
$useRequest(['id' => (string) $editId, '_origin' => 'region:journal-list']);
$host = $journalHost();
$host->editAction();
$html = $render($host);
$help = $host->help->render();
check('HP1 the edit form carries NO explanation any more (owner 2026-09-29) — the tolerance rule, the computed VAT line and the number note are gone from it',
    !str_contains($html, 'Leer = aus dem Betrag') && !str_contains($html, 'Die Nummer 2032-33/') && !str_contains($html, 'Gespeichert: MWST') && !str_contains($html, 'be-form__hint">MWST'));
check('HP2 … they are the entry\'s HELP: the controller attached `oneLine.help` with the live values, delivered as <template data-help> with its title',
    $host->help->has() && str_starts_with($help, '<template data-help data-help-title="Hilfe: Einzelbuchung">') && str_ends_with($help, '</template>')
    && str_contains($help, 'Steuerbetrag leer') && str_contains($help, '0.05') && str_contains($help, 'Die Nummer 2032-33/'));
check('HP3 the window width is the controller\'s: the one-line edit window asks for 64rem (a six-field row must not scroll sideways)', str_contains($html, 'data-window-width="64rem"'));
$useRequest(['id' => (string) $editId, 'form' => 'compound']);
$host = $journalHost();
$host->editAction();
check('HP4 the compound edit gets the compound help (the controller picks it — one action, two forms)', str_contains($host->help->render(), 'data-help-title="Hilfe: Sammelbuchung"'));
$fields = preg_match_all('/<section data-help-field="([a-z_]+)">/', $host->help->render(), $mHF) ? $mHF[1] : [];
check('HP4b the compound help has a section per row field (name without []): date, text, account, line_text, debit, credit, tax_code; the edit note stays in it',
    $fields === ['date', 'text', 'account', 'line_text', 'debit', 'credit', 'tax_code'] && str_contains($host->help->render(), 'Die Nummer 2032-33/'));
$host = $journalHost();
$host->listAction();
check('HP5 a FETCH of the list (sort, page, search) carries no help — the capture form it belongs to is not in the answer', !$host->help->has());
$GLOBALS['z77TestFetch'] = false;
$useRequest(['date' => '2032-11-20']);
$host = $journalHost();
$host->listAction();
check('HP6 the journal PAGE attaches the capture help (its «? Hilfe» stands in the top bar)', str_contains($host->help->render(), 'data-help-title="Hilfe: Einzelbuchung"') && !str_contains($host->help->render(), 'Die Nummer 2032'));
$help = $host->help->render();
$fields = preg_match_all('/<section data-help-field="([a-z_]+)">/', $help, $mHF) ? $mHF[1] : [];
check('HP6b the one-line help is a general part + one section per field (owner 2026-10-08): debit, date, text, credit, amount, vat, tax_code, tax_amount — keyed by the form\'s field names, the general part first',
    $fields === ['debit', 'date', 'text', 'credit', 'amount', 'vat', 'tax_code', 'tax_amount']
    && strpos($help, '<h3>Einzelbuchung</h3>') < strpos($help, 'data-help-field=') && str_contains($help, '1020 Bankguthaben') && str_contains($help, '«Buchen»')
    && str_contains($help, 'Steuerbetrag laut Beleg'));
$formTpl = $render($host);
$named = preg_match_all('/<(?:input|select)[^>]*\sname="(debit|credit|date|text|amount|vat|tax_code|tax_amount)"/', $formTpl, $mN) ? array_unique($mN[1]) : [];
check('HP6c every section key is a field of the capture form (the JS finds a section by the focused control\'s name)', count($named) === 8);
$skel = sys_get_temp_dir() . '/z77-help-skel-' . getmypid() . '.tpl.php';
file_put_contents($skel, '<main><?= $main ?? \'\' ?></main>');
$viewHtml = (new \Z77\Core\Services\HtmlView($skel, [], [], [], 'Z77\\Shared'))->assign(['helpBlock' => '<template data-help>x</template>'])->render();
@unlink($skel);
$beSkel = file_get_contents(__DIR__ . '/../packages/module-backend/res/view/templates/html-shell-skeleton.tpl.php');
$meSkel = file_get_contents(__DIR__ . '/../packages/module-member/res/view/templates/html-shell-skeleton.tpl.php');
$beTop   = file_get_contents(__DIR__ . '/../packages/module-backend/res/view/templates/partials/shell/topbar.tpl.php');
$trigger = file_get_contents(__DIR__ . '/../packages/kernel/shared/res/view/templates/partials/helpTrigger.tpl.php');
$meHead  = file_get_contents(__DIR__ . '/../packages/module-member/res/view/templates/partials/shell/userMenu.tpl.php');
check('HP7 the kernel closes `main` with the help (HtmlView — survives an action that rebuilds its main); the backend trigger «? Hilfe» is in the TOP BAR (only with help, before the bell), the crumb line carries none (owner 2026-10-08); the member shell likewise: «? Hilfe» in the HEAD (userMenu cluster, only with help, before the appearance switch), its crumb line carries none (owner 2026-10-09)',
    $viewHtml === '<main><template data-help>x</template></main>'
    && !str_contains($beSkel, 'helpOpen') && !str_contains($beSkel, 'helpTrigger')
    && str_contains($beTop, "!empty(\$helpBlock) ? \$this->partial('partials/helpTrigger'") && strpos($beTop, 'partials/helpTrigger') < strpos($beTop, 'backend-topbar__bell')
    && !str_contains($meSkel, 'helpOpen') && !str_contains($meSkel, "partial('partials/helpTrigger'")
    && str_contains($meSkel, "'showHelp'         => !empty(\$helpBlock)")
    && str_contains($meHead, "!empty(\$showHelp) ? \$this->partial('partials/helpTrigger'") && strpos($meHead, 'partials/helpTrigger') < strpos($meHead, 'data-member-theme')
    && preg_match('/<button type="button" class="z77-help-trigger" data-help-open data-help-trigger hidden aria-label="Hilfe \\(F1\\)"/', $trigger) === 1
    && str_contains($trigger, 'z77-help-trigger__label'));
$scssShell = file_get_contents(__DIR__ . '/../packages/module-backend/res/scss/components/_shell.scss');
check('HP7b the crumb line has ONE fixed height: grid track and slot both --shell-crumb, the slot clips — a page with help looks like one without',
    str_contains($scssShell, 'var(--shell-band) auto var(--shell-crumb) minmax(0, 1fr)')
    && preg_match('/\.be-shell-crumb__slot \{[^}]*height:\s+var\(--shell-crumb\);[^}]*overflow:\s+hidden;/', $scssShell) === 1);
$scssMember = file_get_contents(__DIR__ . '/../packages/module-member/res/scss/member.scss');
$cssMember  = file_get_contents(__DIR__ . '/../packages/module-member/res/assets/css/member.css');
check('HP7c the member crumb line has ONE fixed height too: track and cell both --crumb-h, the cell clips, the path stays on one line; the member CSS carries the trigger (hidden until the script reveals it, label off on a narrow screen) (owner 2026-10-09)',
    str_contains($scssMember, 'var(--row-h, 3.5rem) var(--row-h, 3.5rem) var(--crumb-h, 2.375rem) 1fr')
    && preg_match('/&__crumbs \{[^}]*height: var\(--crumb-h, 2\.375rem\);[^}]*overflow: hidden;/', $scssMember) === 1
    && preg_match('/\.me-crumb \{[^}]*flex-wrap: nowrap;/', $scssMember) === 1
    && str_contains($cssMember, '.me .z77-help-trigger[hidden]') && str_contains($cssMember, '.me .z77-help-trigger__label{display:none}'));
echo "AB. the form's action bar (ADR-049): «Speichern» within reach\n";
$GLOBALS['z77TestFetch'] = true;
$useRequest(['id' => (string) $editId, '_origin' => 'region:journal-list']);
$host = $journalHost();
$host->editAction();
$html = $render($host);
check('AB1 the one-line edit: the action bar comes FIRST in the form (sticky, Enter saves) with Speichern · Sammelbuchung · Abbrechen, nothing at the end; in a window no repeated heading',
    preg_match('/<form [^>]*>.*?<div class="z77-form-actions">\s*<button type="submit" class="be-btn be-btn--primary be-btn--sm">Speichern.*?Als Sammelbuchung bearbeiten.*?Abbrechen<\/a>\s*<\/div>/s', $html) === 1
    && strpos($html, 'z77-form-actions') < strpos($html, 'name="debit"') && !str_contains($html, 'be-list__section-title') && !str_contains($html, 'Fehler</label>'));
$vAB = (int) $entryRow('2032-33', $inputRef->number)['version'];
$useRequest(['id' => (string) $editId], ['form' => 'one-line', 'version' => (string) $vAB, 'entity_csrf' => 'tok-journalEntry-' . $editId, '_origin' => 'region:journal-list'] + $row('9999', '1020', 'x', '2032-11-15', ''));
$host = $journalHost();
$host->editAction();
$html = $render($host);
check('AB2 a refused save: «N Fehler» in the bar is the label of the FIRST invalid field (a click focuses it — no script), nothing written',
    preg_match('/<label class="z77-form-actions__errors" for="journal-debit">(\d+) Fehler<\/label>/', $html, $mAB) === 1 && (int) $mAB[1] >= 2
    && (int) $entryRow('2032-33', $inputRef->number)['version'] === $vAB);
$useRequest(['id' => (string) $editId, 'form' => 'compound']);
$host = $journalHost();
$host->editAction();
$html = $render($host);
check('AB3 the compound edit: Speichern in the bar before «Weitere Zeilen» (Enter saves), «Weitere Zeilen» under the rows it acts on; every row field has an id the error link can reach',
    strpos($html, 'value="save">Speichern') < strpos($html, 'value="more">Weitere Zeilen') && preg_match('/<div class="z77-form-actions">.*?value="save">Speichern/s', $html) === 1
    && str_contains($html, 'id="journal-row-0-account"') && str_contains($html, 'id="journal-row-0-tax_code"') && str_contains($html, 'id="journal-c-date"'));
$GLOBALS['z77TestFetch'] = false;
$scssFa = file_get_contents(__DIR__ . '/../packages/kernel/shared/res/scss/components/_form-actions.scss');
check('AB4 the bar is a shared primitive: sticky, top by default, --end at the bottom — CSS only; the backend and the member CSS carry it',
    str_contains($scssFa, 'position:    sticky') && str_contains($scssFa, '.z77-form-actions--end') && str_contains(file_get_contents(__DIR__ . '/../packages/module-backend/res/assets/css/base.css'), '.z77-form-actions--end')
    && str_contains(file_get_contents(__DIR__ . '/../packages/module-member/res/assets/css/member.css'), '.z77-form-actions'));
$coreJs = file_get_contents(__DIR__ . '/../packages/kernel/shared/res/assets/js/core.js');
check('HP8the help window is the shared core.js contract: its own module, never inert, bound at boot, the «i» in a window head, the width from data-window-width; the minified copy carries it',
    str_contains($coreJs, '_Z77.core.help = (function') && str_contains($coreJs, "c.hasAttribute('data-z77-help')") && str_contains($coreJs, '_Z77.core.help.bind()')
    && str_contains($coreJs, 'template[data-help]') && str_contains($coreJs, "getAttribute('data-window-width')")
    && str_contains(file_get_contents(__DIR__ . '/../packages/kernel/shared/res/assets/js/core.min.js'), 'data-z77-help'));
$coreMin = file_get_contents(__DIR__ . '/../packages/kernel/shared/res/assets/js/core.min.js');
check('HP9 context help (owner 2026-10-08): core.js remembers the last focused field (focusin, not in the help window), opens at its data-help-field section (data-help-key, else name without []), marks it is-help-hit, F1 opens it only where help exists, reveals the hidden trigger; no fetch, no innerHTML in the module; the minified copy carries it',
    str_contains($coreJs, "addEventListener('focusin'") && str_contains($coreJs, "closest('[data-z77-help]')")
    && str_contains($coreJs, "getAttribute('data-help-key')") && str_contains($coreJs, "querySelectorAll('[data-help-field]')")
    && str_contains($coreJs, "e.key !== 'F1'") && str_contains($coreJs, "classList.add('is-help-hit')")
    && str_contains($coreJs, "querySelectorAll('[data-help-trigger][hidden]')")
    && preg_match('/_Z77\.core\.help = \(function \(\) \{(.*?)\n\}\)\(\);/s', $coreJs, $mHelp) === 1
    && !str_contains(preg_replace('/_win\.innerHTML = .*?;\n/s', '', $mHelp[1]), 'innerHTML') && !str_contains($mHelp[1], 'fetch(')
    && str_contains($coreMin, 'data-help-field') && str_contains($coreMin, 'is-help-hit') && str_contains($coreMin, 'F1'));
$scssHelp = file_get_contents(__DIR__ . '/../packages/kernel/shared/res/scss/components/_help.scss');
check('HP10 the highlight is CSS: a short fade on .is-help-hit, none under prefers-reduced-motion; the backend CSS carries it and the trigger (hidden until the script reveals it)',
    str_contains($scssHelp, '@keyframes z77-help-hit') && preg_match('/prefers-reduced-motion: reduce\)\s*\{\s*\.z77-help__body \.is-help-hit\s*\{\s*animation:\s+none;/', $scssHelp) === 1
    && str_contains(file_get_contents(__DIR__ . '/../packages/module-backend/res/assets/css/base.css'), '.z77-help-trigger[hidden]')
    && str_contains(file_get_contents(__DIR__ . '/../packages/module-backend/res/assets/css/base.css'), 'is-help-hit'));
$useRequest(['id' => (string) $compoundId]);
$host = $journalHost();
$host->editAction();
check('O17c an entry that is not of the one-line shape opens in the Sammelbuchung form, without the one-line link', $host->layoutManager->sections['main'] === ['Backend/JournalController/form']
    && !str_contains($render($host), 'Einzeilig bearbeiten'));
$version = (int) $entryRow('2032-33', $inputRef->number)['version'];
$useRequest(['id' => (string) $editId], ['form' => 'one-line', 'version' => (string) $version, 'entity_csrf' => "tok-journalEntry-{$editId}"] + $row('6500', '1020', '450.00', '2032-11-15', 'VM', '', 'Beleg korrigiert'));
$host = $journalHost();
$host->editAction();
check('O18 POST edit one-line (450.00): saved through ManualEntryService::update() — to the detail, the change logged, the tax recomputed (33.72)', $host->redirectedTo === '/backend/finance/journal/detail?id=' . $editId
    && (int) $entryRow('2032-33', $inputRef->number)['version'] === $version + 1 && $entryRow('2032-33', $inputRef->number)['text'] === 'Beleg korrigiert'
    && array_column($lineRows($editId), 'tax_amount')[0] === '33.72' && count($em->getRepository(EntryChange::class)->forEntry($editId)) === 2);
$useRequest(['id' => (string) $editId], ['form' => 'one-line', 'version' => (string) $version, 'entity_csrf' => "tok-journalEntry-{$editId}"] + $row('6500', '1020', '460.00'));
$host = $journalHost();
$host->editAction();
check('O18b a STALE one-line edit (the version seen before O18) → the conflict message and a redirect, nothing written', $host->redirectedTo === '/backend/finance/journal/detail?id=' . $editId
    && str_contains($host->messageService->flashes[0][1] ?? '', 'inzwischen geändert') && array_column($lineRows($editId), 'credit')[2] === '450.00');

echo "O. … the net side by account type (owner, 2026-09-22): credit notes, refunds, fixed assets\n";
/** The Sammelbuchung's request for the same lines — the one-line form must write the tax data it writes (sign convention). */
$compoundOf = fn(array $rows) => (new \Z77\Module\Financial\Ui\ManualEntryForm('CHF', DI::getUnifiedEntityManager()->getRepository(Account::class), DI::getUnifiedEntityManager()->getRepository(TaxCode::class), \Z77\Module\Vat\Services\VatRates::from(DI::getUnifiedEntityManager())))
    ->fromPost(['date' => '2032-11-21', 'text' => 'x', 'account' => array_column($rows, 0), 'debit' => array_column($rows, 1), 'credit' => array_column($rows, 2), 'tax_code' => array_column($rows, 3), 'line_text' => array_fill(0, count($rows), '')])->toRequest();
$creditNote = $oneLine()->fromPost($row('3200', '1100', '108.10', '2032-11-21', 'UN'))->toRequest();
check('O19 a customer credit note 3200/1100 with UN, 108.10 gross: the revenue line is net (Soll, base and tax NEGATIVE), 8.10 output VAT in Soll on 2200, the debtor gross in Haben',
    $shape($creditNote) === ['3200 D 100.00 UN 810 -100.00 -8.10', '2200 D 8.10', '1100 C 108.10']);
check('O19b … the same tax data the Sammelbuchung writes for that line (ManualEntryForm::taxFor sign convention)', $shape($creditNote) === $shape($compoundOf([['3200', '100.00', '', 'UN'], ['2200', '8.10', '', ''], ['1100', '', '108.10', '']])));
$creditNoteRef = $manual->create($creditNote);
$cnForm = $oneLine();
check('O19c … posted, and it round-trips: it opens one-line (3200 / 1100 / 108.10 / UN, tax field empty) and rebuilds exactly the stored lines', $cnForm->startFrom($entries->findByRef($creditNoteRef))
    && [$cnForm->debit(), $cnForm->credit(), $cnForm->amount(), $cnForm->taxCode(), $cnForm->taxAmount()] === ['3200', '1100', '108.10', 'UN', ''] && $shape($cnForm->toRequest()) === $shape($creditNote));
$refund = $oneLine()->fromPost($row('1020', '4200', '108.10', '2032-11-21', 'VM'))->toRequest();
check('O20 a supplier refund 1020/4200 with VM: the expense line is net in Haben (negative base and tax), 8.10 input tax in Haben on 1170, the bank gross in Soll — as the Sammelbuchung writes it',
    $shape($refund) === ['1020 D 108.10', '4200 C 100.00 VM 810 -100.00 -8.10', '1170 C 8.10'] && $shape($refund) === $shape($compoundOf([['1020', '108.10', '', ''], ['4200', '', '100.00', 'VM'], ['1170', '', '8.10', '']])));
$refundRef = $manual->create($refund);
check('O20b … posted and round-trips one-line', $oneLine()->startFrom($entries->findByRef($refundRef)));
$asset = $oneLine()->fromPost($row('1500', '1020', '1081.00', '2032-11-21', 'VI'))->toRequest();
check('O21 a fixed asset 1500/1020 with VI (neither side P&L): the category decides — net 1000.00 on 1500 in Soll, 81.00 on 1171', $shape($asset) === ['1500 D 1000.00 VI 810 1000.00 81.00', '1171 D 81.00', '1020 C 1081.00']);
$bothPl = $oneLine()->fromPost($row('6500', '3200', '100.00', '2032-11-21', 'VM'));
check('O22 both sides P&L accounts (6500/3200) with MwSt → refused, pointing to the Sammelbuchung; without MwSt it posts', $bothPl->toRequest() === null
    && array_filter($bothPl->generalErrors(), fn($m) => str_contains($m, 'Sammelbuchung')) !== [] && $oneLine()->fromPost($row('6500', '3200', '100.00'))->toRequest() !== null);

echo "O. … the tolerance min(1.00, max(0.05, 1 % of the computed tax)), review 2026-09-22, narrowed 2026-09-23\n";
// 10.00 gross VM: computed 0.75 (10 × 810 / 10810 = 0.749…); 1 % of it is below the floor, so the
// limit is MIN = 0.05. The floor is what keeps a small purchase workable at all.
$small = fn(string $tax) => $oneLine()->fromPost($row('6500', '1020', '10.00', '2032-11-21', 'VM', $tax));
check('O23 10.00 with VM: 0.00 refused (only when the computed tax is 0.00), 0.80 accepted (0.05 off, the floor), 0.81 refused (0.06 off)',
    ($z = $small('0.00'))->toRequest() === null && str_contains($z->error('tax_amount'), '0.00 geht nur')
    && ($shape($small('0.80')->toRequest())[1] ?? null) === '1170 D 0.80'
    && ($f = $small('0.81'))->toRequest() === null && str_contains($f->error('tax_amount'), 'höchstens 0.05'));
// The MAX still binds, but only far above: 1 % of a four-figure tax exceeds a franc.
$big = $oneLine()->fromPost($row('6500', '1020', '25000.00', '2032-11-21', 'VM', '1872.00'));
check('O23c a large voucher keeps the flat ceiling: computed 1873.27, 1 % would be 18.73, allowed is MAX 1.00 → 1872.00 (1.27 off) refused',
    $big->toRequest() === null && str_contains($big->error('tax_amount'), 'höchstens 1.00'));
check('O23b a tiny amount whose computed tax is 0.00 (0.05 with VM): the code on the net line with tax 0.00, NO tax line, and the hint says «keine Steuerzeile»', (function () use ($oneLine, $row, $shape) {
    $f = $oneLine()->fromPost($row('6500', '1020', '0.05', '2032-11-21', 'VM'));
    return $shape($f->toRequest()) === ['6500 D 0.05 VM 810 0.05 0.00', '1020 C 0.05'] && str_contains($f->vatHint(), 'keine Steuerzeile');
})());
$huge = $oneLine()->fromPost($row('6500', '1020', '99999999999999.00'));
$hugeCompound = (new \Z77\Module\Financial\Ui\ManualEntryForm('CHF', $em->getRepository(Account::class), $em->getRepository(TaxCode::class), \Z77\Module\Vat\Services\VatRates::from($em)))
    ->fromPost(['date' => '2032-11-21', 'text' => 'x', 'account' => ['6500', '1020'], 'debit' => ['99999999999999.00', ''], 'credit' => ['', '99999999999999.00'], 'tax_code' => ['', ''], 'line_text' => ['', '']]);
check('O24 an amount beyond DECIMAL(15,2) (14 digits) is a field error in BOTH forms, not a database error; 13 digits still parse', $huge->toRequest() === null && str_contains($huge->error('amount'), '13 Stellen')
    && $hugeCompound->toRequest() === null && str_contains($hugeCompound->rowError(0, 'debit'), '13 Stellen') && $oneLine()->fromPost($row('6500', '1020', '9999999999999.99'))->toRequest() !== null);

echo "O. … canonical order, no-op saves, the rendered edit form\n";
$unordered = $manual->create(PostingRequest::manual(day('2032-11-22'), 'Beleg', [PostingLine::credit('1020', chf('400.00')), PostingLine::debit('6500', chf('370.03'), null, 'VM', 810, chf('370.03'), chf('29.97')), PostingLine::debit('1170', chf('29.97'))]));
check('O25 the same content in another line order is NOT one-line (it would be re-sorted) — it opens as Sammelbuchung', !$oneLine()->startFrom($entries->findByRef($unordered)));
$sameRef = $manual->create($oneLine()->fromPost($row('6500', '1020', '400.00', '2032-11-22', 'VM'))->toRequest());
$same    = $entries->findByRef($sameRef);
[$sameId, $sameVersion] = [(int) $same->getId(), $same->getVersion()];
$sameForm = $oneLine();
$sameForm->startFrom($same);
$changes = $em->getRepository(EntryChange::class);
check('O26 saving an UNCHANGED one-line entry is a no-op: update() answers false, no change row, the version stays', $manual->update($sameId, $sameVersion, $sameForm->toRequest()) === false
    && count($changes->forEntry($sameId)) === 0 && (int) $entryRow('2032-33', $sameRef->number)['version'] === $sameVersion);
$cmpEntry = $entries->findByRef($compoundRef);
$cmpForm  = (new \Z77\Module\Financial\Ui\ManualEntryForm('CHF', $em->getRepository(Account::class), $em->getRepository(TaxCode::class), \Z77\Module\Vat\Services\VatRates::from($em)))->keepFrom($cmpEntry)->startFrom($cmpEntry);
check('O26b … the same for the Sammelbuchung: its unchanged form saves nothing', $manual->update((int) $cmpEntry->getId(), $cmpEntry->getVersion(), $cmpForm->toRequest()) === false
    && count($changes->forEntry((int) $cmpEntry->getId())) === 0);
check('O26c a stale unchanged save is still a conflict (the version is checked first)', throws(fn() => $manual->update($sameId, $sameVersion + 5, $sameForm->toRequest()), EntryConflictException::class));
$useRequest(['id' => (string) $sameId]);
$host = $journalHost();
$host->editAction();
$rendered = $host->context['form'];
$asPosted = fn(string $amount) => ['form' => 'one-line', 'version' => (string) $sameVersion, 'entity_csrf' => "tok-journalEntry-{$sameId}", 'debit' => $rendered->debit(), 'date' => $rendered->date(), 'text' => $rendered->text(),
    'credit' => $rendered->credit(), 'amount' => $amount, 'vat' => $rendered->vat() ? '1' : '', 'tax_code' => $rendered->taxCode(), 'tax_amount' => $rendered->taxAmount()];
$useRequest(['id' => (string) $sameId], $asPosted('400.00'));
$host = $journalHost();
$host->editAction();
check('O27 POST edit of the form AS RENDERED, unchanged: «Keine Änderung», back to the detail, nothing written', $host->redirectedTo === '/backend/finance/journal/detail?id=' . $sameId
    && str_contains($host->messageService->flashes[0][1] ?? '', 'Keine Änderung') && count($changes->forEntry($sameId)) === 0);
$useRequest(['id' => (string) $sameId], $asPosted('500.00'));
$host = $journalHost();
$host->editAction();
check('O28 POST edit of the form as rendered with only the amount changed (400 → 500): it SAVES — the empty tax field recomputes 37.47', $host->redirectedTo === '/backend/finance/journal/detail?id=' . $sameId
    && str_contains($host->messageService->flashes[0][1] ?? '', 'gespeichert') && array_column($lineRows($sameId), 'tax_amount')[0] === '37.47' && count($changes->forEntry($sameId)) === 1);
$voucherRef = $manual->create($oneLine()->fromPost($row('6500', '1020', '400.00', '2032-11-22', 'VM', '30.00'))->toRequest());
$vForm = $oneLine();
check('O28b a stored VOUCHER value (30.00 ≠ computed 29.97) is pre-filled on edit — it is a real voucher value', $vForm->startFrom($entries->findByRef($voucherRef)) && $vForm->taxAmount() === '30.00');

echo "FY-SEL. the fiscal-year selection (owner 2026-09-29): action cell, default current year, remembered per session\n";
$fyRepo    = DI::getUnifiedEntityManager()->getRepository(FiscalYear::class);
$fyDefault = $fyRepo->currentOrLatest()->getCode();
$fyToday   = new \DateTimeImmutable('today');
$fyPast    = array_values(array_filter($fyRepo->allWithPeriods(), fn($y) => $y->getEndDate() < $fyToday))[0] ?? null;     // newest past year
$fyFuture  = array_values(array_filter($fyRepo->allWithPeriods(), fn($y) => $y->getStartDate() > $fyToday));
$fyFuture  = end($fyFuture) ?: null;                                                                                   // nearest future year
$fyList = function (array $get, bool $keepSession) use ($useRequest, $journalHost) {
    $useRequest($get, null, $keepSession);
    $host = $journalHost();
    $host->listAction();
    return $host;
};
$fyRemembered = fn() => $_SESSION[\Z77\Module\Financial\Ui\FiscalYearSelection::SESSION_KEY] ?? null;
$fySlot = fn($host) => implode('', array_map(fn($p) => $renderer->partial($p, $host->context), $host->layoutManager->sections['railSelect'] ?? []));

$h = $fyList([], false);
check('FYS1 default = the CURRENT fiscal year (containing today, else the latest): a fresh session opens the journal and the reports on it, nothing remembered',
    $h->context['year']->getCode() === $fyDefault && $page('trialBalance', [], true)[0]['range']->year->getCode() === $fyDefault && $fyRemembered() === null);
$h = $fyList(['year' => '2030-31'], true);
check('FYS2 ?year=2030-31 picks the year — the journal list shows it — and remembers it in the session', $h->context['year']->getCode() === '2030-31' && $fyRemembered() === '2030-31');
$h = $fyList([], true);
check('FYS3 a later request WITHOUT ?year (same session) still shows the remembered 2030-31 — the list, the crumb, the dropdown',
    $h->context['year']->getCode() === '2030-31' && str_contains($fySlot($h), 'Geschäftsjahr <span class="be-shell-select__value">2030-31</span>'));
[$ctx] = $page('trialBalance', [], true);
[$ctxNope] = $page('trialBalance', ['year' => 'nope'], true);
check('FYS4 the reports use the SAME selection: without ?year the remembered 2030-31, whole year; an unknown ?year says so and keeps the remembered one',
    $ctx['range']->year->getCode() === '2030-31' && $ctx['range']->fromDay() === '2030-07-01' && $ctx['notices'] === []
    && $ctxNope['range']->year->getCode() === '2030-31' && count($ctxNope['notices']) === 1 && $fyRemembered() === '2030-31');
[$ctx] = $page('balanceSheet', ['year' => '2031-32'], true);
$h = $fyList([], true);
check('FYS5 … and a year picked on a report is the journal\'s year too (one session key for both screens)', $ctx['range']->year->getCode() === '2031-32' && $h->context['year']->getCode() === '2031-32');
$h = $fyList(['year' => $fyDefault], true);
$h2 = $fyList([], true);
check('FYS6 picking the DEFAULT year clears the remembered choice — back to «follow today»', $h->context['year']->getCode() === $fyDefault && $fyRemembered() === null && $h2->context['year']->getCode() === $fyDefault);
$_SESSION[\Z77\Module\Financial\Ui\FiscalYearSelection::SESSION_KEY] = 'gone';
$h = $fyList([], true);
check('FYS7 a remembered year that no longer exists falls back to the default and is dropped from the session', $h->context['year']->getCode() === $fyDefault && $fyRemembered() === null);

$h = $fyList(['year' => '2030-31'], false);
$slot = $fySlot($h);
$codes = array_map(fn($y) => $y->getCode(), $fyRepo->allWithPeriods());
preg_match_all('/<a class="be-shell-add__item" href="([^"]+)"[^>]*>([^<]+)<\/a>/', $slot, $links);
check('FYS8 the top of the rail (railSelect, owner 2026-10-08) renders the CSS-only dropdown: <details>/<summary> «Geschäftsjahr 2030-31» (the year as the accented value) with a chevron, every year newest first as a plain ?year= link, then «Alle Jahre» (?all=1, the journal only), the current one aria-current, no script',
    $h->layoutManager->sections['railSelect'] === ['Backend/partials/fiscalYearSelect']
    && preg_match('/<details class="be-shell-add be-shell-add--select">\s*<summary[^>]*>\s*<span class="be-btn__label">Geschäftsjahr <span class="be-shell-select__value">2030-31<\/span><\/span>\s*<svg[^>]*be-shell-add__chevron/', $slot) === 1
    && $links[2] === [...$codes, 'Alle Jahre'] && $links[1][0] === '/backend/finance/journal/list?year=' . rawurlencode($codes[0]) && end($links[1]) === '/backend/finance/journal/list?all=1'
    && substr_count($slot, 'aria-current="true"') === 1 && preg_match('/href="\/backend\/finance\/journal\/list\?year=2030-31" aria-current="true">2030-31</', $slot) === 1
    && stripos($slot, '<script') === false && !str_contains($slot, 'data-panel'));
$h = $fyList(['year' => '2030-31', 'mode' => 'sammel', 'f_text' => 'x'], false);
check('FYS8b … in the Sammelbuchung a year link keeps the mode (and drops date and search)', str_contains($fySlot($h), 'href="/backend/finance/journal/list?mode=sammel&amp;year=2030-31" aria-current="true"') && !str_contains($fySlot($h), 'f_text'));
[, $html, $sections] = $page('accountStatement', ['year' => '2030-31', 'account' => '1020'], false);
check('FYS8c the reports put the same dropdown at the top of the rail (railSelect) — a year link stays on the report, keeps the account, drops from/to', $sections['railSelect'] === ['Backend/partials/fiscalYearSelect']
    && str_contains($html, '<details class="be-shell-add be-shell-add--select">') && str_contains($html, 'href="/backend/finance/report/account-statement?year=2030-31&amp;account=1020" aria-current="true"'));

$h = $fyList(['year' => $fyDefault], false);
$hPast = $fyPast === null ? null : $fyList(['year' => $fyPast->getCode()], false);
$hFut  = $fyFuture === null ? null : $fyList(['year' => $fyFuture->getCode(), 'date' => $fyPast?->getEndDate()->format('Y-m-d')], false);
check('FYS9 the capture date = today clamped into the SELECTED year: the current year → today, a past year → its last day, a future year → its first day (a ?date= outside the year is ignored)',
    $fyRepo->findByDate($fyToday) !== null && $h->context['form']->date() === $fyToday->format('Y-m-d')
    && $hPast !== null && $hPast->context['form']->date() === $fyPast->getEndDate()->format('Y-m-d') && $hPast->context['year']->getCode() === $fyPast->getCode()
    && $hFut !== null && $hFut->context['form']->date() === $fyFuture->getStartDate()->format('Y-m-d') && $hFut->context['year']->getCode() === $fyFuture->getCode());
$h = $fyList(['year' => '2032-33', 'date' => '2032-11-20'], false);
check('FYS9b … a ?date= inside the selected year is kept (the date after a posting)', $h->context['form']->date() === '2032-11-20' && $h->context['year']->getCode() === '2032-33');

$useRequest([], $row('6500', '1020', '12.00', '2032-11-20', '', '', 'FY-SEL'), false);
$host = $journalHost();
$host->addAction();
$afterPost = $fyRemembered();
$h = $fyList(['date' => '2032-11-20'], true);
check('FYS10 posting into ANOTHER year than the shown default: back to list?date=… — that year becomes the remembered selection and the list shows it, the date kept',
    $host->redirectedTo === '/backend/finance/journal/list?date=2032-11-20' && $afterPost === '2032-33'
    && $h->context['year']->getCode() === '2032-33' && $h->context['form']->date() === '2032-11-20'
    && array_filter($h->context['rows'], fn($r) => isset($r['entry']) && $r['entry']->getText() === 'FY-SEL') !== []);
$sel = \Z77\Module\Financial\Ui\FiscalYearSelection::of($fyRepo);
$sel->remember($fyRepo->findOneBy(['code' => $fyDefault]));
check('FYS11 a posting into the DEFAULT year clears the remembered choice (remember() of the default = clear) — both screens follow today again',
    $fyRemembered() === null && $fyList([], true)->context['year']->getCode() === $fyDefault);
$journalTrait = file_get_contents($package . '/src/Ui/JournalControllerTrait.php');
$reportTrait  = file_get_contents($package . '/src/Ui/ReportControllerTrait.php');
check('FYS12 one shared rule: both traits resolve through FiscalYearSelection, neither touches the session itself, the posting calls remember()',
    str_contains($journalTrait, 'FiscalYearSelection::of(') && str_contains($reportTrait, 'FiscalYearSelection::of(')
    && !preg_match('/SessionManager|\$_SESSION/', $journalTrait . $reportTrait) && substr_count($journalTrait, '$this->journalSelectPostedYear($ref);') === 2);
$_SESSION = [];

$source = file_get_contents($package . '/src/Ui/OneLineEntryForm.php');
check('O16the one-line form has no write path of its own: it builds a PostingRequest::manual() and never touches the ledger or an entity', str_contains($source, 'PostingRequest::manual(')
    && !preg_match('/->(post|reverse|persist|flush|remove|amend|create|update)\(/', $source));

// ── YC. closing and reopening a fiscal year (P5 part 1, owner 2026-09-30) ──

echo "YC. Closing a fiscal year: the whole year, in order, the close check, admin reopen with a reason, the protocol (owner 2026-09-30)\n";

/** A close check the harness controls — what a module registers under `period-close` (the debtor check is proven in tests/module-debtor.php). */
class YcCloseCheck implements \Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface
{
    /** @var list<\Z77\Persistence\Doctrine\OpenWork\Finding> */
    public static array $findings = [];
    /** @var list<array{0: string, 1: array}> */
    public static array $calls = [];

    public function check(string $scope, array $parameters): iterable
    {
        self::$calls[] = [$scope, $parameters];
        yield from self::$findings;
    }
}

$db->executeStatement("UPDATE fiscal_period SET state = 'open'");   // earlier sections set states in SQL
$em = $wireDi();
(new FiscalYearService($em))->open((new FiscalYearService($em))->proposePrior());
$em      = $wireDi();
$ycYears = $em->getRepository(FiscalYear::class);
$pYear   = $ycYears->earliest();
$pCode   = $pYear->getCode();
$nCode   = $ycYears->successorOf($pYear)->getCode();
$lCode   = $ycYears->latest()->getCode();
$pDay    = $pYear->getStartDate()->modify('+1 month')->format('Y-m-d');
$nDay    = $ycYears->successorOf($pYear)->getStartDate()->modify('+10 days')->format('Y-m-d');
$ycChecks   = new OpenWorkChecks(['period-close' => [YcCloseCheck::class]]);
$ycService  = fn(string $actor = 'buchhalter') => new FiscalYearCloseService($wireDi(), $actor, $ycChecks);
/** $confirm: confirm the warnings the close check shows NOW (their fingerprint, what the modal hands back). */
$ycClose    = function (string $code, bool $confirm = false) use ($ycService, $yearId, $wireDi): ?string {
    $seen = $confirm ? FiscalYearCloseService::warningsFingerprint($ycService()->closeCheck($wireDi()->getRepository(FiscalYear::class)->findOneBy(['code' => $code]))) : null;
    $e = caught(fn() => $ycService()->close($yearId($code), $seen), FiscalYearCloseRefusedException::class);
    return $e?->reason;
};
$ycReopen   = function (string $code, string $reason) use ($ycService, $yearId): ?string {
    $e = caught(fn() => $ycService('admin')->reopen($yearId($code), $reason), FiscalYearCloseRefusedException::class);
    return $e?->reason;
};
$ycStates   = fn(string $code) => $db->fetchFirstColumn('SELECT DISTINCT p.state FROM fiscal_period p JOIN fiscal_year y ON y.id = p.fiscal_year_id WHERE y.code = ? ORDER BY 1', [$code]);
$ycLog      = fn(string $code) => $db->fetchAllAssociative('SELECT * FROM fiscal_year_close_log WHERE fiscal_year_code = ? ORDER BY id', [$code]);
$ycRange    = fn(string $name) => $db->fetchOne('SELECT last_number FROM number_range WHERE name = ?', [$name]);
$ycFresh    = fn(string $code) => $wireDi()->getRepository(FiscalYear::class)->findOneBy(['code' => $code]);

// The prior year (it ended in the past) gets a manual and a generated entry while it is open.
$ycManual = (new ManualEntryService($wireDi(), 'buchhalter'))->create($transferRequest($pDay, 'YC manuell'));
$ycGen    = $post($wireDi(), $saleRequest($pDay, 'YC Rechnung', 'yc:invoice:1', 'YC-1'));
$ycManualRow = $entryRow($pCode, $ycManual->number);

/** The lock-free refusal the screen asks, on a FRESH wiring each time (an old identity map would hold stale period states). */
$ycRefusal = function (string $which, string $code) use ($wireDi, $ycChecks): ?string {
    $em   = $wireDi();
    $s    = new FiscalYearCloseService($em, 'buchhalter', $ycChecks);
    $year = $em->getRepository(FiscalYear::class)->findOneBy(['code' => $code]);
    return $which === 'close' ? $s->closeRefusal($year) : $s->reopenRefusal($year);
};
check('YC1 in order (lock-free, what the screen asks): the EARLIEST open year may be closed; the year after it and the latest are refused (predecessor-open)',
    $ycRefusal('close', $pCode) === null
    && $ycRefusal('close', $nCode) === FiscalYearCloseRefusedException::PREDECESSOR_OPEN && $ycRefusal('close', $lCode) === FiscalYearCloseRefusedException::PREDECESSOR_OPEN);
check('YC2 close() out of order is refused (predecessor-open): no period moves, no protocol row', $ycClose($nCode) === FiscalYearCloseRefusedException::PREDECESSOR_OPEN
    && $ycStates($nCode) === ['open'] && $ycLog($nCode) === []);
$nested = null;
$ycEm = $wireDi();
$ycOuter = function () use ($ycEm, $ycChecks, $yearId, $pCode, &$nested) {
    $nested = caught(fn() => (new FiscalYearCloseService($ycEm, 'buchhalter', $ycChecks))->close($yearId($pCode)), \LogicException::class);
    throw new \RuntimeException('outer aborts');
};
caught(fn() => $ycEm->getTransaction(FiscalYear::class)->run($ycOuter), \RuntimeException::class);
check('YC3 close() inside an open unit of work is refused (LogicException — it owns its unit of work), nothing closed', $nested !== null && str_contains($nested->getMessage(), 'owns its unit of work') && $ycStates($pCode) === ['open']);

YcCloseCheck::$findings = [Finding::blocking('Rechnung 7 vom 15.04.2024 ist noch in Fakturierung', 'invoice:7')];
YcCloseCheck::$calls    = [];
$e = caught(fn() => $ycService()->close($yearId($pCode), 'any'), FiscalYearCloseRefusedException::class);
[$scope, $params] = YcCloseCheck::$calls[0] ?? ['', []];
check('YC4 a BLOCKING finding refuses the close — also with the warnings confirmed; the exception carries the findings; nothing moved, no protocol row',
    $e?->reason === FiscalYearCloseRefusedException::BLOCKED && count($e->openWork?->blocking() ?? []) === 1 && $ycStates($pCode) === ['open'] && $ycLog($pCode) === []);
check('YC5 the close check is asked through the open-work registry: scope `period-close`, parameters fiscalYear (code), from and to (the year\'s bounds, DateTimeImmutable)',
    $scope === FiscalYearCloseService::SCOPE && $scope === 'period-close' && ($params['fiscalYear'] ?? null) === $pCode
    && ($params['from'] ?? null) instanceof \DateTimeImmutable && $params['from']->format('Y-m-d') === $pYear->getStartDate()->format('Y-m-d')
    && ($params['to'] ?? null) instanceof \DateTimeImmutable && $params['to']->format('Y-m-d') === $pYear->getEndDate()->format('Y-m-d'));
YcCloseCheck::$findings = [Finding::warning('Auftrag 4711 mit Leistung im Jahr ist nicht fakturiert', 'order:4711')];
check('YC6 a WARNING needs the explicit confirmation: without it refused (warnings-unconfirmed), nothing moved', $ycClose($pCode) === FiscalYearCloseRefusedException::WARNINGS_UNCONFIRMED
    && $ycStates($pCode) === ['open'] && $ycLog($pCode) === []);
$seenBefore = FiscalYearCloseService::warningsFingerprint($ycService()->closeCheck($ycFresh($pCode)));
YcCloseCheck::$findings = [Finding::warning('Auftrag 4712 mit Leistung im Jahr ist nicht fakturiert', 'order:4712')];
check('YC6b the confirmation names WHICH warnings: the fingerprint of the ones seen before, other warnings now → refused (warnings-changed), nothing moved',
    caught(fn() => $ycService()->close($yearId($pCode), $seenBefore), FiscalYearCloseRefusedException::class)?->reason === FiscalYearCloseRefusedException::WARNINGS_CHANGED
    && $ycStates($pCode) === ['open'] && $ycLog($pCode) === []);
YcCloseCheck::$findings = [Finding::warning('Auftrag 4711 mit Leistung im Jahr ist nicht fakturiert', 'order:4711')];
$db->executeStatement("INSERT INTO fiscal_year (code, start_date, end_date) VALUES ('yc-empty', '2199-01-01', '2199-12-31')");   // past the validator: no periods
check('YC6c a year WITHOUT periods is refused (no-periods) instead of closing nothing', $ycClose('yc-empty', true) === FiscalYearCloseRefusedException::NO_PERIODS && $ycLog('yc-empty') === []);
$db->executeStatement("DELETE FROM fiscal_year WHERE code = 'yc-empty'");
$rangeBefore = (string) $ycRange('journal-entry.' . $pCode);
check('YC7 … confirmed: the year closes — EVERY period `closed`, isClosed() on a fresh read', $ycClose($pCode, true) === null && $ycStates($pCode) === ['closed'] && $ycFresh($pCode)->isClosed()
    && count($ycFresh($pCode)->getPeriods()) >= 12);
$log = $ycLog($pCode);
check('YC8 one protocol row: close, the year id AND code (no foreign key), the actor, the time, no reason, the confirmed warning', count($log) === 1 && $log[0]['action'] === 'close'
    && (int) $log[0]['fiscal_year_id'] === $yearId($pCode) && $log[0]['actor'] === 'buchhalter' && $log[0]['reason'] === null && $log[0]['acted_at'] !== null
    && $log[0]['confirmed_warnings'] === 'Auftrag 4711 mit Leistung im Jahr ist nicht fakturiert');
YcCloseCheck::$findings = [];
check('YC9 closing again is refused (already-closed) — one protocol row still; the next year may be closed now (its predecessor is closed)', $ycClose($pCode, true) === FiscalYearCloseRefusedException::ALREADY_CLOSED
    && count($ycLog($pCode)) === 1 && $ycRefusal('close', $nCode) === null && $ycRefusal('close', $lCode) === FiscalYearCloseRefusedException::PREDECESSOR_OPEN);

// The ledger stays the last line of defence.
check('YC10 a closed year refuses a MANUAL posting (period-closed), nothing consumed', $refused($wireDi(), $transferRequest($pDay, 'YC nach Abschluss')) === PostingRefusedException::PERIOD_CLOSED
    && caught(fn() => (new ManualEntryService($wireDi(), 'buchhalter'))->create($transferRequest($pDay, 'YC nach Abschluss')), PostingRefusedException::class)?->reason === PostingRefusedException::PERIOD_CLOSED
    && (string) $ycRange('journal-entry.' . $pCode) === $rangeBefore);
check('YC11 … and a GENERATED posting (period-closed), nothing consumed', $refused($wireDi(), $saleRequest($pDay, 'YC Rechnung 2', 'yc:invoice:2', 'YC-2')) === PostingRefusedException::PERIOD_CLOSED
    && (string) $ycRange('journal-entry.' . $pCode) === $rangeBefore);
$ycEdit = caught(fn() => (new ManualEntryService($wireDi(), 'buchhalter'))->update((int) $ycManualRow['id'], (int) $ycManualRow['version'], $transferRequest($pDay, 'YC geändert')), EntryNotEditableException::class);
$ycDel  = caught(fn() => (new ManualEntryService($wireDi(), 'buchhalter'))->delete((int) $ycManualRow['id'], (int) $ycManualRow['version']), EntryNotEditableException::class);
check('YC12 … an EDIT and a DELETE of a manual entry in it (period-closed); the entry unchanged', $ycEdit?->reason === EntryNotEditableException::PERIOD_CLOSED && $ycDel?->reason === EntryNotEditableException::PERIOD_CLOSED
    && $entryRow($pCode, $ycManual->number)['text'] === 'YC manuell' && (int) $entryRow($pCode, $ycManual->number)['version'] === (int) $ycManualRow['version']);
check('YC13 a closed year is never deleted (closed) — also when it is the earliest; the list offers no «Löschen …» for it', (new FiscalYearService($wireDi()))->deletionRefusal($ycFresh($pCode)) === FiscalYearNotDeletableException::CLOSED
    && $refusal($pCode) === FiscalYearNotDeletableException::CLOSED);

echo "YC. … the re-check under the period lock: a close committed after the lock-free check (a stale identity map stands in for the race)\n";
$ycNext = (new ManualEntryService($wireDi(), 'buchhalter'))->create($transferRequest($nDay, 'YC Folgejahr'));
$ycNextRow = $entryRow($nCode, $ycNext->number);
$stale = $wireDi();
$stale->getRepository(FiscalYear::class)->allWithPeriods();                   // periods loaded: `open` in this EntityManager
$db->executeStatement("UPDATE fiscal_period p JOIN fiscal_year y ON y.id = p.fiscal_year_id SET p.state = 'closed' WHERE y.code = ?", [$nCode]);   // «another process closed the year»
$nRange = (string) $ycRange('journal-entry.' . $nCode);
$raced  = caught(fn() => $post($stale, $transferRequest($nDay, 'YC Race')), PostingRefusedException::class);
check('YC14 post(): the lock-free check read the stale `open` and passed; the share-locked re-check after the number reads `closed` → period-closed, the rollback gives the number back',
    $raced?->reason === PostingRefusedException::PERIOD_CLOSED && (string) $ycRange('journal-entry.' . $nCode) === $nRange && (int) $db->fetchOne("SELECT COUNT(*) FROM journal_entry WHERE text = 'YC Race'") === 0);
$db->executeStatement("UPDATE fiscal_period p JOIN fiscal_year y ON y.id = p.fiscal_year_id SET p.state = 'open' WHERE y.code = ?", [$nCode]);
$stale2 = $wireDi();
$stale2->getRepository(FiscalYear::class)->allWithPeriods();                 // loaded `open` …
$db->executeStatement("UPDATE fiscal_period p JOIN fiscal_year y ON y.id = p.fiscal_year_id SET p.state = 'closed' WHERE y.code = ?", [$nCode]);   // … closed since
$racedEdit = caught(fn() => (new ManualEntryService($stale2, 'buchhalter'))->update((int) $ycNextRow['id'], (int) $ycNextRow['version'], $transferRequest($nDay, 'YC Race Edit')), EntryNotEditableException::class);
check('YC15 a manual edit decides on the COMMITTED state as well (share-locked), not on what the request loaded before', $racedEdit?->reason === EntryNotEditableException::PERIOD_CLOSED
    && $entryRow($nCode, $ycNext->number)['text'] === 'YC Folgejahr');
$db->executeStatement("UPDATE fiscal_period p JOIN fiscal_year y ON y.id = p.fiscal_year_id SET p.state = 'open' WHERE y.code = ?", [$nCode]);
$ledgerSource = file_get_contents($package . '/src/Services/LedgerService.php');
$lineRepo     = file_get_contents($package . '/src/Repositories/JournalLineRepository.php');
check('YC16 source guards: post() re-checks the period AFTER the number and the accounts; the type lock reads the closed periods share-locked inside a unit of work',
    ($postAt = strpos($ledgerSource, 'function postInternal')) !== false
    && strpos($ledgerSource, '$ranges->next(', $postAt) < strpos($ledgerSource, 'lockedPeriodState($period)', $postAt) && strpos($ledgerSource, 'lockAccounts(', $postAt) < strpos($ledgerSource, 'lockedPeriodState($period)', $postAt)
    && str_contains($lineRepo, 'ORDER BY start_date LOCK IN SHARE MODE'));

echo "YC. … reopen: admin, with a reason, the latest closed year only (reverse order)\n";
check('YC17 reopen with an empty reason is refused (no-reason) — the year stays closed', $ycReopen($pCode, '   ') === FiscalYearCloseRefusedException::NO_REASON && $ycStates($pCode) === ['closed']);
check('YC18 reopen of an OPEN year is refused (not-closed); an unknown id (not-found)', $ycReopen($nCode, 'Test') === FiscalYearCloseRefusedException::NOT_CLOSED
    && caught(fn() => $ycService('admin')->reopen(999999, 'Test'), FiscalYearCloseRefusedException::class)?->reason === FiscalYearCloseRefusedException::NOT_FOUND);
check('YC19 order: close the next year too; now the EARLIER closed year cannot be reopened (successor-closed) — only the latest closed one', $ycClose($nCode, true) === null && $ycStates($nCode) === ['closed']
    && $ycRefusal('reopen', $pCode) === FiscalYearCloseRefusedException::SUCCESSOR_CLOSED && $ycRefusal('reopen', $nCode) === null
    && $ycReopen($pCode, 'Nachbuchung') === FiscalYearCloseRefusedException::SUCCESSOR_CLOSED && $ycStates($pCode) === ['closed']);
check('YC20 reopen the latest closed year with a reason: every period `open` again, a protocol row with actor and reason', $ycReopen($nCode, 'Beleg 17 fehlte') === null && $ycStates($nCode) === ['open']
    && ($r = $ycLog($nCode)) !== [] && count($r) === 2 && $r[1]['action'] === 'reopen' && $r[1]['actor'] === 'admin' && $r[1]['reason'] === 'Beleg 17 fehlte' && $r[1]['confirmed_warnings'] === null);
check('YC21 … then the earlier one; the closed year takes postings again after the reopen', $ycReopen($pCode, 'Nachbuchung Beleg 18') === null && $ycStates($pCode) === ['open'] && count($ycLog($pCode)) === 2
    && $refused($wireDi(), $transferRequest($pDay, 'YC nach Öffnen')) === null);
$closer = new FiscalYearCloseService($wireDi(), 'buchhalter', new OpenWorkChecks([]));
$future = $closer->closeCheck($ycFresh($lCode));
check('YC22 financial\'s own finding: a year that has not ended yet is a WARNING («endet erst am …»); a past year without registered checks has none',
    count($future->warnings()) === 1 && str_contains($future->warnings()[0]->message, 'endet erst am') && !$future->isBlocked() && $closer->closeCheck($ycFresh($pCode))->isEmpty());
check('YC23 the protocol entity refuses a reopen without a reason and a close with one (domain guard)', throws(fn() => new FiscalYearCloseLog($ycFresh($pCode), \Z77\Module\Financial\Entities\CloseAction::Reopen, 'x', new \DateTimeImmutable(), ' '), \LogicException::class)
    && throws(fn() => new FiscalYearCloseLog($ycFresh($pCode), \Z77\Module\Financial\Entities\CloseAction::Close, 'x', new \DateTimeImmutable(), 'Grund'), \LogicException::class));
$ycPeriod = $ycFresh($nCode)->getPeriods()[0];
check('YC24 Period::transitionTo(): close from any state, reopen only from closed, vat-settled not yet (P5 part 2)', throws(fn() => $ycPeriod->transitionTo(PeriodState::Open), \LogicException::class)
    && throws(fn() => $ycPeriod->transitionTo(PeriodState::VatSettled), \LogicException::class));

echo "YC. … access: reopen is ADMIN (the backend's module role), the button follows canReach\n";
$backendConfig = require __DIR__ . '/../packages/module-backend/src/App/Config/backendConfig.inc.php';
$roleOf = fn(string $action) => \Z77\Shared\Services\AuthService::requiredRole($backendConfig['moduleRole'] ?? null, $backendConfig['controllers'] ?? [], 'finance', 'FiscalYearController', $action);
check('YC25 reopen / confirm-reopen / close require ADMIN; reopen is PINNED to ADMIN in backendConfig (owner 2026-09-30, a deliberate AUTH-B003 exception — a lower controller role for a bookkeeper must not hand it out); an editor does not reach them, an admin does',
    preg_match("/'FiscalYearController'\s*=>\s*\[\s*'actions'\s*=>\s*\[\s*'confirmReopenAction'\s*=>\s*AuthRole::ADMIN,\s*'reopenAction'\s*=>\s*AuthRole::ADMIN/", (string) file_get_contents(__DIR__ . '/../packages/module-backend/src/App/Config/backendConfig.inc.php')) === 1
    && $roleOf('reopenAction') === \Z77\Core\Config\AuthRole::ADMIN && $roleOf('confirmReopenAction') === \Z77\Core\Config\AuthRole::ADMIN && $roleOf('closeAction') === \Z77\Core\Config\AuthRole::ADMIN
    && !\Z77\Core\Config\AuthRole::rolesSatisfy([\Z77\Core\Config\AuthRole::EDITOR], $roleOf('reopenAction')) && \Z77\Core\Config\AuthRole::rolesSatisfy([\Z77\Core\Config\AuthRole::ADMIN], $roleOf('reopenAction')));

echo "YC. … the screen: list, modals, POSTs (trait + templates, rendered without a web server)\n";
$GLOBALS['ycCanReach'] = true;
$GLOBALS['ycChecks']   = $ycChecks;
$GLOBALS['ycBody']     = [];
DI::getInstance()->set('Request', fn() => new class {
    public function getGetParameter(string $p): mixed { return $_GET[$p] ?? null; }
    public function isPost(): bool { return false; }
    public function getJsonBody(): array { return $GLOBALS['ycBody']; }
}, true);
DI::getInstance()->set('CsrfService', fn() => new class {
    public function generateEntityToken(string $context, int $id): string { return "tok-{$context}-{$id}"; }
    public function validateEntityToken(string $token, string $context, int $id): bool { return $token === "tok-{$context}-{$id}"; }
}, true);
$ycHost = function () {
    $host = new class {
        use FiscalYearControllerTrait { listAction as public; confirmCloseAction as public; closeAction as public; confirmReopenAction as public; reopenAction as public; }
        public array $context = [];
        public object $layoutManager;
        public object $messageService;
        public function __construct()
        {
            $this->layoutManager = new class {
                public array $sections = [];
                public function removeSection(string $s): void { unset($this->sections[$s]); }
                public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
            };
            $this->messageService = new class {
                public array $flashes = [];
                public function pushFlashAfterRedirect(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
                public function pushFlash(string $type, string $message): void { $this->flashes[] = [$type, $message, 'in-place']; }
            };
        }
        protected function em() { return DI::getUnifiedEntityManager(); }
        protected function fetch(): \Z77\Core\Http\Response\FetchResponse { return new \Z77\Core\Http\Response\FetchResponse(); }
        protected function fetchError(string $text): \Z77\Core\Http\Response\FetchResponse { $this->messageService->pushFlash('error', $text); return $this->fetch()->setStatus('error'); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
        protected function fiscalYearCanReach(string $action): bool { return $GLOBALS['ycCanReach']; }
        // No session actor in the harness, and the registry the harness controls.
        private function fiscalYearCloseService(): FiscalYearCloseService { return new FiscalYearCloseService($this->em(), 'admin', $GLOBALS['ycChecks']); }
    };
    return $host;
};
$envelope = fn(\Z77\Core\Http\Response\FetchResponse $r) => (fn() => $this->build())->call($r);
$ycRender = fn($host) => implode('', array_map(fn($p) => $renderer->partial($p, $host->context), $host->layoutManager->sections['main'] ?? []));
$ycList   = function () use ($ycHost, $renderer) { $_GET = []; $h = $ycHost(); $h->listAction(); return $renderer->partial('Backend/FiscalYearController/listAction', $h->context); };

$wireDiKeep = function () use ($wireDi) {   // a fresh wiring drops the doubles — register them again
    $em = $wireDi();
    DI::getInstance()->set('Request', fn() => new class {
        public function getGetParameter(string $p): mixed { return $_GET[$p] ?? null; }
        public function isPost(): bool { return false; }
        public function getJsonBody(): array { return $GLOBALS['ycBody']; }
    }, true);
    DI::getInstance()->set('CsrfService', fn() => new class {
        public function generateEntityToken(string $context, int $id): string { return "tok-{$context}-{$id}"; }
        public function validateEntityToken(string $token, string $context, int $id): bool { return $token === "tok-{$context}-{$id}"; }
    }, true);
    return $em;
};
$wireDiKeep();
$html = $ycList();
check('YC26 the list, all years open: each year a state badge «offen»; «Jahr abschliessen …» on the EARLIEST year only (in order), no «Wieder öffnen …»',
    substr_count($html, 'data-fiscal-year-state="open"') === (int) $db->fetchOne('SELECT COUNT(*) FROM fiscal_year') && !str_contains($html, 'data-fiscal-year-state="closed"')
    && str_contains($html, '/backend/finance/fiscal-year/confirm-close?id=' . $yearId($pCode) . '"') && substr_count($html, '/confirm-close?id=') === 1 && !str_contains($html, '/confirm-reopen?id='));
check('YC27 … and the protocol of the prior year in the list, newest first, small and muted: «wieder geöffnet … von admin — Grund: Nachbuchung Beleg 18», «abgeschlossen … (1 Warnung bestätigt)»',
    str_contains($html, 'data-fiscal-year-log><small>wieder geöffnet ') && str_contains($html, 'von admin — Grund: Nachbuchung Beleg 18')
    && str_contains($html, 'von buchhalter (1 Warnung bestätigt)') && strpos($html, 'Nachbuchung Beleg 18') < strpos($html, '(1 Warnung bestätigt)'));

// The close modal and the POST through the trait.
YcCloseCheck::$findings = [Finding::blocking('Rechnung 7 vom 15.04.2024 ist noch in Fakturierung', 'invoice:7')];
$wireDiKeep();
$_GET = ['id' => (string) $yearId($pCode)];
$h = $ycHost();
$h->confirmCloseAction();
$modal = $ycRender($h);
check('YC28 confirm-close with a BLOCKING finding: «Abschliessen nicht möglich», the finding listed, no form to submit', str_contains($modal, 'Abschliessen nicht möglich')
    && str_contains($modal, 'Rechnung 7 vom 15.04.2024 ist noch in Fakturierung') && !str_contains($modal, 'data-fetch-post'));
$GLOBALS['ycBody'] = ['id' => $yearId($pCode), 'entity_csrf' => 'tok-fiscalYear.close-' . $yearId($pCode), 'confirm_warnings' => true, 'warnings_hash' => $h->context['warningsHash']];
$h = $ycHost();
$env = $envelope($h->closeAction());
check('YC29 … and its POST is refused by the service with the finding named in the error, nothing closed', ($env['status'] ?? '') === 'error'
    && str_contains($h->messageService->flashes[0][1] ?? '', 'Es ist noch etwas offen') && str_contains($h->messageService->flashes[0][1] ?? '', 'Rechnung 7') && $ycStates($pCode) === ['open']);
YcCloseCheck::$findings = [Finding::warning('Auftrag 4711 mit Leistung im Jahr ist nicht fakturiert', 'order:4711')];
$wireDiKeep();
$h = $ycHost();
$h->confirmCloseAction();
$modal = $ycRender($h);
$shownHash = $h->context['warningsHash'];
check('YC30 confirm-close with a WARNING: the form (the close-scoped entity token, id, the fingerprint of the warnings SHOWN), the warning listed, the confirmation checkbox `confirm_warnings` required',
    str_contains($modal, 'data-fetch-post="/backend/finance/fiscal-year/close"') && str_contains($modal, 'value="tok-fiscalYear.close-' . $yearId($pCode) . '"')
    && strlen($shownHash) === 64 && str_contains($modal, 'name="warnings_hash" value="' . $shownHash . '"')
    && str_contains($modal, 'Auftrag 4711') && preg_match('/name="confirm_warnings"[^>]*required/', $modal) === 1);
$GLOBALS['ycBody'] = ['id' => $yearId($pCode), 'entity_csrf' => 'tok-fiscalYear.close-' . $yearId($pCode), 'confirm_warnings' => false, 'warnings_hash' => $shownHash];
$h   = $ycHost();
$env = $envelope($h->closeAction());
check('YC31 POST close without the confirmation: refused with «Warnungen … bestätigen», nothing closed', ($env['status'] ?? '') === 'error' && str_contains($h->messageService->flashes[0][1] ?? '', 'bestätigen') && $ycStates($pCode) === ['open']);
$GLOBALS['ycBody']['entity_csrf'] = 'forged';
$h   = $ycHost();
$env = $envelope($h->closeAction());
check('YC32 POST close with a wrong entity token: «Invalid token», nothing closed', ($env['status'] ?? '') === 'error' && ($h->messageService->flashes[0][1] ?? '') === 'Invalid token' && $ycStates($pCode) === ['open']);
YcCloseCheck::$findings = [Finding::warning('Auftrag 4713 kam eben dazu', 'order:4713')];
$GLOBALS['ycBody'] = ['id' => $yearId($pCode), 'entity_csrf' => 'tok-fiscalYear.close-' . $yearId($pCode), 'confirm_warnings' => true, 'warnings_hash' => $shownHash];
$h   = $ycHost();
$env = $envelope($h->closeAction());
check('YC32b POST close confirmed, but the warnings changed since the modal: «Die Hinweise haben sich geändert — bitte erneut prüfen», nothing closed',
    ($env['status'] ?? '') === 'error' && ($h->messageService->flashes[0][1] ?? '') === 'Die Hinweise haben sich geändert — bitte erneut prüfen.' && $ycStates($pCode) === ['open']);
YcCloseCheck::$findings = [Finding::warning('Auftrag 4711 mit Leistung im Jahr ist nicht fakturiert', 'order:4711')];
$GLOBALS['ycBody']['entity_csrf'] = 'tok-fiscalYear.reopen-' . $yearId($pCode);
$h   = $ycHost();
$env = $envelope($h->closeAction());
check('YC32c the entity token is scoped per action: a REOPEN token does not close (and the delete keeps `fiscalYear`)', ($env['status'] ?? '') === 'error' && ($h->messageService->flashes[0][1] ?? '') === 'Invalid token'
    && $ycStates($pCode) === ['open'] && str_contains($yearSource, "generateEntityToken('fiscalYear', \$id)"));
$GLOBALS['ycBody'] = ['id' => $yearId($pCode), 'entity_csrf' => 'tok-fiscalYear.close-' . $yearId($pCode), 'confirm_warnings' => true, 'warnings_hash' => $shownHash];
$h   = $ycHost();
$env = $envelope($h->closeAction());
check('YC33 POST close confirmed: success — flash after redirect, close the modal and reload; the year is closed',
    ($env['status'] ?? '') === 'success' && str_contains(json_encode($env), 'close-modal') && str_contains(json_encode($env), 'reload')
    && str_contains($h->messageService->flashes[0][1] ?? '', 'abgeschlossen') && $ycStates($pCode) === ['closed']);
YcCloseCheck::$findings = [];
$wireDiKeep();
$html = $ycList();
check('YC34 the list after the close: «abgeschlossen» on the prior year, «Wieder öffnen …» on it (latest closed, canReach yes), «Jahr abschliessen …» moved on to the next year',
    str_contains($html, 'data-fiscal-year-state="closed"') && str_contains($html, '/confirm-reopen?id=' . $yearId($pCode) . '"')
    && str_contains($html, '/confirm-close?id=' . $yearId($nCode) . '"') && !str_contains($html, '/confirm-close?id=' . $yearId($pCode) . '"')
    && !str_contains($html, '/confirm-delete?id=' . $yearId($pCode) . '"'));
$GLOBALS['ycCanReach'] = false;
check('YC35 … for a user the access config refuses (canReach no): no «Wieder öffnen …»', !str_contains($ycList(), '/confirm-reopen?id='));
$GLOBALS['ycCanReach'] = true;
$_GET = ['id' => (string) $yearId($pCode)];
$h = $ycHost();
$h->confirmReopenAction();
$modal = $ycRender($h);
check('YC36 confirm-reopen: the form posts to reopen with the entity token and a REQUIRED reason field (maxlength = the column)', str_contains($modal, 'data-fetch-post="/backend/finance/fiscal-year/reopen"')
    && preg_match('/<textarea[^>]*name="reason"[^>]*maxlength="' . FiscalYearCloseLog::REASON_LENGTH . '"[^>]*required/', $modal) === 1);
$GLOBALS['ycBody'] = ['id' => $yearId($pCode), 'entity_csrf' => 'tok-fiscalYear.reopen-' . $yearId($pCode), 'reason' => ''];
$h   = $ycHost();
$env = $envelope($h->reopenAction());
check('YC37 POST reopen without a reason: «Bitte einen Grund angeben» — also as a field error on `reason`; still closed', ($env['status'] ?? '') === 'error' && str_contains($h->messageService->flashes[0][1] ?? '', 'Grund')
    && str_contains(json_encode($env, JSON_UNESCAPED_UNICODE), '"reason"') && $ycStates($pCode) === ['closed']);
$GLOBALS['ycBody']['reason'] = str_repeat('x', FiscalYearCloseLog::REASON_LENGTH + 1);
$h   = $ycHost();
$env = $envelope($h->reopenAction());
check('YC37b a reason longer than the protocol keeps is REFUSED (field error «zu lang»), never cut; still closed, no reopen row', ($env['status'] ?? '') === 'error' && str_contains($h->messageService->flashes[0][1] ?? '', 'zu lang')
    && str_contains(json_encode($env, JSON_UNESCAPED_UNICODE), '"reason"') && $ycStates($pCode) === ['closed']
    && throws(fn() => new FiscalYearCloseLog(DI::getUnifiedEntityManager()->getRepository(FiscalYear::class)->findOneBy(["code" => $pCode]), \Z77\Module\Financial\Entities\CloseAction::Reopen, 'x', new \DateTimeImmutable(), str_repeat('x', FiscalYearCloseLog::REASON_LENGTH + 1)), \LogicException::class));
$GLOBALS['ycBody']['entity_csrf'] = 'tok-fiscalYear.close-' . $yearId($pCode);
$GLOBALS['ycBody']['reason'] = 'Korrektur Abgrenzung';
$h   = $ycHost();
$env = $envelope($h->reopenAction());
check('YC37c a CLOSE token does not reopen (Invalid token)', ($env['status'] ?? '') === 'error' && ($h->messageService->flashes[0][1] ?? '') === 'Invalid token' && $ycStates($pCode) === ['closed']);
$GLOBALS['ycBody']['entity_csrf'] = 'tok-fiscalYear.reopen-' . $yearId($pCode);
$GLOBALS['ycBody']['reason'] = 'Korrektur Abgrenzung';
$h   = $ycHost();
$env = $envelope($h->reopenAction());
check('YC38 POST reopen with a reason: success, reload; the year is open, the protocol names admin and the reason', ($env['status'] ?? '') === 'success' && str_contains(json_encode($env), 'reload')
    && $ycStates($pCode) === ['open'] && array_column($ycLog($pCode), 'reason') !== [] && array_reverse(array_column($ycLog($pCode), 'reason'))[0] === 'Korrektur Abgrenzung');
$_GET = ['id' => (string) $yearId($pCode)];
$h = $ycHost();
$h->confirmReopenAction();
check('YC39 confirm-reopen on an open year says why not (no form)', str_contains($ycRender($h), 'Öffnen nicht möglich') && !str_contains($ycRender($h), 'data-fetch-post'));

echo "YC. … the journal: a closed year's entries show the state «closed», the capture form says why it refuses\n";
$ycClose($pCode, true);
$useRequest(['year' => $pCode]);
$jh = $listHost('journal');
$states = [];
foreach ($jh->context['rows'] as $r) { if (isset($r['entry'])) { $states[$r['entry']->getText()] = $r['state']; } }
check('YC40 the journal list of the closed year: the manual entry carries the state `closed` (the generated one stays `generated`)', ($states['YC manuell'] ?? null) === 'closed' && ($states['YC Rechnung'] ?? null) === 'generated');
$useRequest([], $row('1000', '1020', '10.00', $pDay, '', '', 'YC Erfassung'));
$jHost = $journalHost();
$jHost->addAction();
check('YC41 the capture form refuses a posting into the closed year with a clear sentence (the year is closed, a correction goes into an open year, an admin can reopen)',
    str_contains(implode(' ', $jHost->context['form']->generalErrors()), 'Das Geschäftsjahr ist abgeschlossen') && str_contains(implode(' ', $jHost->context['form']->generalErrors()), 'wieder öffnen')
    && (int) $db->fetchOne("SELECT COUNT(*) FROM journal_entry WHERE text = 'YC Erfassung'") === 0);

echo "YC. … a close and a posting in flight (two processes, forced order — review 2026-09-30: no deadlock)\n";
$ycReopen($pCode, 'Race-Probe');
// Race A: a posting into the prior year is IN FLIGHT (number drawn, accounts, year and period share-locked,
// not committed) when the close starts. The close must WAIT at the fiscal-year rows and then close —
// the posting committed into the still open year. Before the fix the close took the years at once, waited
// for the period, and the posting's flush (journal_entry's foreign key share-locks the year) deadlocked.
@unlink($base . '/holdpost.ready');
$hold = proc_open([PHP_BINARY, __FILE__, '--worker', $base, 'holdpostc', '1000', $pDay, 'yc'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $holdPipes);
for ($t = 0; $t < 300 && !is_file($base . '/holdpost.ready'); $t++) { usleep(100000); }
$t0     = microtime(true);
$raceA  = caught(fn() => $ycService()->close($yearId($pCode)), \Throwable::class);
$waited = microtime(true) - $t0;
$holdOut = trim(stream_get_contents($holdPipes[1])) . trim(stream_get_contents($holdPipes[2]));
fclose($holdPipes[1]); fclose($holdPipes[2]); proc_close($hold);
check(sprintf('YC42 posting in flight, then the close: the close WAITED (%.2fs) and closed after the posting committed (worker: %s) — no deadlock, the entry is in the year, the year closed', $waited, $holdOut . ($raceA ? ' / close: ' . get_class($raceA) . ' ' . $raceA->getMessage() : '')),
    $holdOut === 'ok waited' && $raceA === null && $ycStates($pCode) === ['closed']
    && (int) $db->fetchOne("SELECT COUNT(*) FROM journal_entry WHERE text = 'race yc'") === 1);
// Race B: the close is IN FLIGHT (the real service, paused in its close check with every lock held) when a
// posting starts. The posting must WAIT at the year's share lock and then be refused (period-closed) —
// the number goes back.
$ycReopen($pCode, 'Race-Probe 2');
@unlink($base . '/holdclose.ready');
$closer = proc_open([PHP_BINARY, __FILE__, '--worker', $base, 'holdclose', (string) $yearId($pCode), 'ycc'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $closerPipes);
for ($t = 0; $t < 300 && !is_file($base . '/holdclose.ready'); $t++) { usleep(100000); }
$rangeB = (string) $ycRange('journal-entry.' . $pCode);
$raceB  = caught(fn() => $post($wireDi(), $transferRequest($pDay, 'YC Race B')), \Throwable::class);
$closerOut = trim(stream_get_contents($closerPipes[1])) . trim(stream_get_contents($closerPipes[2]));
fclose($closerPipes[1]); fclose($closerPipes[2]); proc_close($closer);
check('YC43 close in flight, then a posting: the posting WAITED and was refused period-closed (worker: ' . $closerOut . '; posting: ' . ($raceB ? get_class($raceB) . ' ' . ($raceB instanceof PostingRefusedException ? $raceB->reason : $raceB->getMessage()) : 'posted') . ') — no deadlock, nothing written, the number went back',
    $closerOut === 'ok waited' && $raceB instanceof PostingRefusedException && $raceB->reason === PostingRefusedException::PERIOD_CLOSED
    && $ycStates($pCode) === ['closed'] && (string) $ycRange('journal-entry.' . $pCode) === $rangeB && (int) $db->fetchOne("SELECT COUNT(*) FROM journal_entry WHERE text = 'YC Race B'") === 0);

echo "\n" . ($fail === 0 ? "PASS — {$pass} checks" : "FAIL — {$fail} of " . ($pass + $fail) . " checks") . "\n";
exit($fail === 0 ? 0 : 1);
