<?php

/**
 * financial-vat-correction harness (CLI) — the correction entry «Korrekturbuchung zu Periode X» and
 * the block «Korrekturen früherer Perioden — separat im ePortal melden» (VAT return stage S3, the
 * services; ADR-042 addendum 2 of 2026-11-25, points 1–3; addendum 3, points 2, 4–6), through the
 * real ledger, the real return and the real «MWST buchen» / «Aufheben».
 *
 *   O. The rules: offline (the marker on the request and the entity, the lock's rate date, source
 *      guards) and through the ledger — (d) dated in a settled period, (e) after «Abrechnung bis»,
 *      (a) the corrected period not settled, (b) not before the date, (c) no coded line; a correction
 *      entry posted and edited; the German sentences.
 *   K. The block of 1st quarter 2027: the golden case of board S3-2 (3rd quarter 2026 corrected by
 *      499.54 / 40.46 — «neu» 708.30 rounded ONCE from 667.83367 + 40.46274), an input-tax
 *      correction and the reverse-charge pattern (2nd quarter 2026), the year end through 2209
 *      (C-E, and its input-tax mirror X3 — 4th quarter 2026); the form without the correction
 *      entries, the reconciliation (one item per entry, no «ohne MWST-Code», the block's tax expected
 *      on the VAT accounts), the warning «Code … gehörte damals …», the settlement lines per period
 *      and account, the stored rows, the protocol; a period corrected a second time («frühere
 *      Korrekturen»); returns posted before keep «Seit dem Buchen geändert» false (R25).
 *   L. Lifting: refused while a correction entry corrects the period (one / several), allowed once
 *      they are gone; the lift of a carrying return reverses its block lines and keeps its rows; the
 *      next revision carries the block again.
 *   R. Two processes: a correction entry in flight against the lift of the period it corrects, and
 *      a lift in flight against the correction entry.
 *   U. The screens (VAT return wave 3, W3-A): «Korrigiert Periode» in the Sammelbuchung (admin only,
 *      the posted periods before the date, the rate date, the five-year hint), the refusals at their
 *      field, the way out of an ordinary entry measured against the running period (C-B), the badge
 *      «Korrektur zu …» and «berechnet» in the journal, a correction entry edited in the Sammelbuchung
 *      only; the block on the return page (open and posted, exact values, «frühere Korrekturen»), in
 *      «Herkunft» / «Abstimmung», in the window «MWST buchen», on the PDF and the archived voucher, the
 *      protocol detail, the lift refusal with its link, the help and the access pin.
 *
 * Every name and amount here is synthetic.
 *
 * Run: php tests/financial-vat-correction.php (until registered: then php tests/run.php financial-vat-correction)
 * Needs the monorepo's vendor/ and a reachable MariaDB with the `z77test` credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*; without them the harness reports SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "financial-vat-correction: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(1);
}
require $autoload;

/** A fresh DI on the installation at $base — the main run's `$wire` and the workers of section R share it. */
function harnessDi(string $base): \Z77\Persistence\Resolver\UnifiedEntityManager
{
    \Z77\Core\DI::getInstance(true)
        ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
        ->set('FileFinder', fn($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn($c) => new \Z77\Core\Services\ModuleManager($c->get('ConfigManager')), true)
        ->set('I18n', fn($c) => new \Z77\Core\Services\I18n($c->get('ConfigManager')), true)
        ->set('Translator', fn() => new \Z77\Core\Services\Translator(), true)
        ->set('DataSourceResolver', fn() => new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new \Z77\Persistence\Resolver\UnifiedEntityManager($c->get('DataSourceResolver')), true);
    \Z77\Core\DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return \Z77\Core\DI::getUnifiedEntityManager();
}

/** Waits (at most $seconds) until ANOTHER session of this user executes one statement for 300 ms or more — a lock wait. */
function awaitWaiter(\Doctrine\DBAL\Connection $watcher, float $seconds = 20.0): ?string
{
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        $info = $watcher->fetchOne("SELECT INFO FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND COMMAND <> 'Sleep' AND INFO IS NOT NULL AND TIME_MS >= 300 LIMIT 1");
        if ($info !== false) {
            usleep(200000);
            return (string) $info;
        }
        usleep(100000);
    }

    return null;
}

/** Holds a worker's unit of work ONCE at Doctrine's first `postFlush` — every lock is taken by then. */
final class HarnessFlushHold
{
    public function __construct(private ?\Closure $hold)
    {
    }

    public function postFlush(): void
    {
        $hold = $this->hold;
        if ($hold !== null) {
            $this->hold = null;
            $hold();
        }
    }
}

// ── worker mode (section R) ─────────────────────────────────────────────────
// `--worker {base} liftreturn {id} {version}` lifts that return and holds at its first flush (after every lock and
// the reversal); `--worker {base} holdcorrection {date} {from} {to}` posts a correction entry of that quarter and holds
// at its flush. Each writes `{base}/{mode}.ready` when it holds and releases once another session waits ≥ 300 ms (or
// after 20 s); prints `ok waited|alone`, `refused {reason} waited|alone`, or the exception.
if (($argv[1] ?? '') === '--worker') {
    [$workerBase, $workerMode] = [$argv[2], $argv[3]];
    define('ABS_BASE_PATH', $workerBase);
    define('DEBUG', false);
    $cfg     = require $workerBase . '/config/client/database.inc.php';
    $watcher = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $cfg['host'], 'dbname' => $cfg['name'], 'user' => $cfg['user'], 'password' => $cfg['password']]);
    $em      = harnessDi($workerBase);
    $waited  = false;
    $hold    = static function () use ($workerBase, $workerMode, $watcher, &$waited): void {
        file_put_contents("{$workerBase}/{$workerMode}.ready", '1');
        $waiter = awaitWaiter($watcher);
        $waited = $waiter !== null;
        file_put_contents("{$workerBase}/{$workerMode}.waiter", (string) $waiter);
    };
    $how = static function () use (&$waited): string { return $waited ? 'waited' : 'alone'; };
    $em->getRepository(\Z77\Module\Financial\Entities\VatReturn::class);   // boots the Doctrine manager
    foreach ((new \ReflectionProperty($em, 'managerCache'))->getValue($em) as $manager) {
        if ($manager instanceof \Z77\Persistence\Doctrine\DoctrineEntityManager) {
            (new \ReflectionProperty($manager, 'holder'))->getValue($manager)->current()->getEventManager()->addEventListener([\Doctrine\ORM\Events::postFlush], new HarnessFlushHold($hold));
        }
    }
    $m = static fn(string $d): \Z77\Shared\Money\Money => \Z77\Shared\Money\Money::fromDecimal($d, 'CHF');
    try {
        if ($workerMode === 'liftreturn') {
            (new \Z77\Module\Financial\VatReturn\VatReturnPostingService($em, 'worker'))->lift((int) $argv[4], (int) $argv[5], 'Worker: in flight');
        } else {
            $corrects = new \Z77\Module\Financial\VatReturn\VatPeriod(new \DateTimeImmutable($argv[5]), new \DateTimeImmutable($argv[6]), \Z77\Module\Mandator\Entities\VatRhythm::Quarter);
            (new \Z77\Module\Financial\Services\ManualEntryService($em, 'worker'))->create(\Z77\Module\Financial\Ledger\PostingRequest::manual(new \DateTimeImmutable($argv[4]), 'R Korrektur in flight', [
                \Z77\Module\Financial\Ledger\PostingLine::debit('1000', $m('54.05')),
                \Z77\Module\Financial\Ledger\PostingLine::credit('3200', $m('50.00'), null, 'UN', 810, $m('50.00'), $m('4.05')),
                \Z77\Module\Financial\Ledger\PostingLine::credit('2200', $m('4.05')),
            ], $corrects));
        }
        echo 'ok ' . $how();
    } catch (\Z77\Module\Financial\VatReturn\VatReturnRefusedException $e) {
        echo 'refused ' . $e->refusal->reason . ' ' . $how();
    } catch (\Z77\Module\Financial\Services\PostingRefusedException $e) {
        echo 'refused ' . $e->reason . ' ' . $how();
    } catch (\Throwable $e) {
        echo get_class($e) . ': ' . $e->getMessage();
    }
    exit(0);
}

use Z77\Module\Financial\Entities\EntryKind;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\Entities\VatReturnCorrection;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Repositories\VatReturnCorrectionRepository;
use Z77\Module\Financial\Services\EntryNotEditableException;
use Z77\Module\Financial\Services\FiscalYearService;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\ManualEntryService;
use Z77\Module\Financial\Services\PostingRefusedException;
use Z77\Module\Financial\Services\PostingRules;
use Z77\Module\Financial\VatReturn\ExactAmount;
use Z77\Module\Financial\VatReturn\ReconciliationLine;
use Z77\Module\Financial\VatReturn\VatAccountReconciliation;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Module\Financial\VatReturn\VatReturnCorrectionBlock;
use Z77\Module\Financial\VatReturn\VatReturnFinding;
use Z77\Module\Financial\VatReturn\VatReturnPostingService;
use Z77\Module\Financial\VatReturn\VatReturnRefusal;
use Z77\Module\Financial\VatReturn\VatReturnRefusedException;
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Module\Financial\VatReturn\VatReturnSources;
use Z77\Module\Mandator\Entities\VatConsideration;
use Z77\Module\Mandator\Entities\VatMethod;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks;
use Z77\Shared\Money\Money;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

function thrown(callable $fn): ?\Throwable
{
    try { $fn(); } catch (\Throwable $e) { return $e; }
    return null;
}

function chf(string $decimal): Money
{
    return Money::fromDecimal($decimal, 'CHF');
}

/** The names of the parts of a check that are false — its detail. */
function falseOf(array $parts): string
{
    return implode(', ', array_keys(array_filter($parts, static fn(bool $ok): bool => !$ok)));
}

function day(string $ymd): \DateTimeImmutable
{
    return new \DateTimeImmutable($ymd);
}

function quarter(string $from): VatPeriod
{
    return new VatPeriod(day($from), day($from)->modify('+3 months -1 day'), VatRhythm::Quarter);
}

/** A posted return in memory (offline checks of the entity). */
function memoryReturn(string $from): VatReturn
{
    return new VatReturn(quarter($from), 1, VatMethod::Effective, VatConsideration::Agreed, 'CH', 'CH 2024', hash('sha256', 'rules'), [],
        chf('0.00'), chf('0.00'), chf('0.00'), null, 'harness', day('2027-01-10 09:30:00'), hash('sha256', 'figures'), null, [],
        'data/archive/financial/vat-return/x.pdf', hash('sha256', 'pdf'), 'x.pdf');
}

/** The reason of a refusal thrown by $fn — 'ok' when nothing was thrown, the class otherwise. */
function reasonOf(callable $fn): string
{
    $e = thrown($fn);

    return match (true) {
        $e === null                                  => 'ok',
        $e instanceof PostingRefusedException        => $e->reason,
        $e instanceof EntryNotEditableException      => 'edit:' . $e->reason,
        $e instanceof VatReturnRefusedException      => $e->refusal->reason,
        default                                      => get_class($e) . ': ' . $e->getMessage(),
    };
}

$packages = [
    'Vat'       => $root . '/packages/module-vat',
    'Mandator'  => $root . '/packages/module-mandator',
    'Financial' => $root . '/packages/module-financial',
];

// ─────────────────────────────────────────────────────────────────────────
echo "O. The rules — offline\n";

$q3 = quarter('2026-07-01');
$sale = static fn(string $net, string $tax, string $account = '3200'): array => [
    PostingLine::debit('1000', chf($net)->add(chf($tax))),
    PostingLine::credit($account, chf($net), null, 'UN', 810, chf($net), chf($tax)),
    PostingLine::credit('2200', chf($tax)),
];
$marked = PostingRequest::manual(day('2027-02-22'), 'Barverkauf 15.08.2026', $sale('499.54', '40.46'), $q3);
$plain  = PostingRequest::manual(day('2027-02-22'), 'Barverkauf', $sale('499.54', '40.46'));
check('O1 PostingRequest::manual(…, $corrects): the marker on the request, isCorrection(); the fingerprint names it («from/to»), an ordinary request null — so a marker-only change is no no-op',
    $marked->isCorrection() && $marked->corrects === $q3 && !$plain->isCorrection()
    && $marked->fingerprint()['corrects'] === '2026-07-01/2026-09-30' && $plain->fingerprint()['corrects'] === null && $marked->fingerprint() !== $plain->fingerprint());
$year = new FiscalYear('2027', day('2027-01-01'), day('2027-12-31'));
$entry = new JournalEntry($year, 1, day('2027-02-22'), 'x', EntryKind::Manual, null, null, null, 'harness', day('2027-02-22'), null, $q3);
check('O2 JournalEntry: a manual entry carries the marker — correctedVatPeriod() equals the period with its label, the snapshot adds vat_period_from / _to / _rhythm; fingerprintOf() reads them back; a GENERATED entry naming a period is refused',
    $entry->isCorrection() && $entry->correctedVatPeriod()?->equals($q3) && $entry->correctedVatPeriod()?->label() === '3. Quartal 2026'
    && ($entry->snapshot()['vat_period_from'] ?? null) === '2026-07-01' && ($entry->snapshot()['vat_period_rhythm'] ?? null) === 'quarter'
    && PostingRequest::fingerprintOf($entry->snapshot())['corrects'] === '2026-07-01/2026-09-30'
    && !array_key_exists('vat_period_from', (new JournalEntry($year, 2, day('2027-02-22'), 'x', EntryKind::Manual, null, null, null, 'harness', day('2027-02-22')))->snapshot())
    && thrown(fn() => new JournalEntry($year, 3, day('2027-02-22'), 'x', EntryKind::Generated, 'harness', 'r', 'k', 'harness', day('2027-02-22'), null, $q3)) instanceof \LogicException);
$seen = [];
$taxLines = static function (string $code, \DateTimeImmutable $date) use (&$seen): array {
    $seen[] = $date->format('Y-m-d');

    return [['key' => 'vat-owed', 'account' => '2200', 'sign' => -1]];
};
$refusal = PostingRules::vatLinesRefusal($marked, ['2200' => 'vat-owed'], $taxLines, static fn() => true);
PostingRules::vatLinesRefusal($plain, ['2200' => 'vat-owed'], $taxLines, static fn() => true);
check('O3 the lock reads a correction entry\'s codes at the RATE DATE of the corrected period (its last day, 30.09.2026), an ordinary entry at its own date; both pass',
    $refusal === null && $seen === ['2026-09-30', '2027-02-22'], json_encode($seen));
$rules = (string) file_get_contents($packages['Financial'] . '/src/Services/PostingRules.php');
preg_match('/\/\*\*((?:(?!\*\/).)*)\*\/\s*private static function vatFrozenDates/s', $rules, $frozenDoc);
check('O4 source guards: PostingRules names no input-tax rule (inputTaxDeferral); the docblock of vatFrozenDates() promises no exemption — «NO exemption»; the repository names lost «ordinary»',
    !str_contains($rules, 'inputTaxDeferral') && isset($frozenDoc[1]) && str_contains($frozenDoc[1], 'NO exemption') && !str_contains($frozenDoc[1], 'will contribute no date')
    && (static function () use ($root): bool {
        // The old names, assembled so this file does not name them itself.
        $old = '/' . implode('|', ['live' . 'Ordinary', 'ordinary' . 'EndingBetween', 'ordinary' . 'Revisions']) . '/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/packages', \FilesystemIterator::SKIP_DOTS));
        foreach ([...$files, ...array_map(static fn(string $f) => new \SplFileInfo($f), glob($root . '/tests/*.php') ?: [])] as $f) {
            if ($f->getExtension() === 'php' && !str_contains($f->getPathname(), '/vendor/') && preg_match($old, (string) file_get_contents($f->getPathname()))) {
                return false;
            }
        }

        return true;
    })());
$carrier = memoryReturn('2027-01-01');
$older   = memoryReturn('2026-07-01');
$row     = new VatReturnCorrection($carrier, $older, 1, '303', FieldKind::Rate, ExactAmount::fromDecimal('958.883670', 'CHF'), ExactAmount::zero('CHF'),
    ExactAmount::fromDecimal('40.462740', 'CHF'), ['original' => chf('11838.07'), 'earlier' => chf('0.00'), 'correction' => chf('499.54')], [['ref' => '2027/4', 'date' => day('2027-02-22')]]);
check('O5 VatReturnCorrection: «neu» = original + earlier + correction EXACT (999.346410), the bases add up (12 337.61); a base only on a rate / acquisition field; the corrected period must end before the carrying one begins; at least one entry',
    $row->newValue()->toDecimal() === '999.346410' && $row->newBase()?->equals(chf('12337.61')) && $row->correctedPeriod()->label() === '3. Quartal 2026' && $row->getEntries()[0]['ref'] === '2027/4'
    && thrown(fn() => new VatReturnCorrection($carrier, $older, 2, '399', FieldKind::TaxTotal, ExactAmount::zero('CHF'), ExactAmount::zero('CHF'), ExactAmount::zero('CHF'), ['original' => chf('1.00'), 'earlier' => chf('0.00'), 'correction' => chf('0.00')], [['ref' => '2027/4', 'date' => day('2027-02-22')]])) instanceof \LogicException
    && thrown(fn() => new VatReturnCorrection($older, $carrier, 3, '399', FieldKind::TaxTotal, ExactAmount::zero('CHF'), ExactAmount::zero('CHF'), ExactAmount::zero('CHF'), [], [['ref' => '2027/4', 'date' => day('2027-02-22')]])) instanceof \LogicException
    && thrown(fn() => new VatReturnCorrection($carrier, $older, 4, '399', FieldKind::TaxTotal, ExactAmount::zero('CHF'), ExactAmount::zero('CHF'), ExactAmount::zero('CHF'), [], [])) instanceof \LogicException);
check('O6 the 2209 key is a VAT-return account key of the ledger — not a VAT account (vatAccountKey() never answers it)',
    LedgerService::VAT_CORRECTION_PENDING_ACCOUNT === 'vat-correction-pending'
    && !in_array(LedgerService::VAT_CORRECTION_PENDING_ACCOUNT, array_map(static fn($c) => LedgerService::vatAccountKey($c->value), VatReturnService::vatAccountCategories()), true));

// ── database ─────────────────────────────────────────────────────────────

$credentials = [
    'host'     => getenv('Z77_TEST_DB_HOST') ?: 'localhost',
    'user'     => getenv('Z77_TEST_DB_USER') ?: 'z77test',
    'password' => getenv('Z77_TEST_DB_PASSWORD') ?: '',
];
if ($credentials['password'] === '') {
    $file = rtrim((string) (getenv('USERPROFILE') ?: getenv('HOME')), '/\\') . '/.z77/mariadb.txt';
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $credLine) {
        if (str_starts_with($credLine, $credentials['user'] . '=')) {
            $credentials['password'] = substr($credLine, strlen($credentials['user']) + 1);
        }
    }
}
if ($credentials['password'] === '' || !class_exists(\Doctrine\DBAL\DriverManager::class)) {
    echo "Database — SKIPPED (no z77test password: %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD)\n";
    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-vat-correction-' . getmypid());
define('DEBUG', false);
$base = ABS_BASE_PATH;

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

$namespaces = "'Z77\\\\Shared\\\\' => ['sourcePaths' => ['{$root}/packages/kernel/shared']],\n";
foreach ($packages as $name => $path) {
    $namespaces .= "'Z77\\\\Module\\\\{$name}\\\\' => ['sourcePaths' => ['{$path}']],\n";
}
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'financial', 'modules' => ['vat' => [], 'mandator' => [], 'financial' => []]];");
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
$write('data/framework/vat/tax_codes.json', (string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_codes.default.json'));
$write('data/framework/vat/tax_rates.json', (string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_rates.default.json'));

/** A fresh request: new DI, new UnifiedEntityManager, empty identity map. */
$wire = static function () use ($base): \Z77\Persistence\Resolver\UnifiedEntityManager {
    gc_collect_cycles();
    static $previous = null;
    if ($previous !== null) {
        foreach ((new \ReflectionProperty($previous, 'managerCache'))->getValue($previous) as $manager) {
            if ($manager instanceof \Z77\Persistence\Doctrine\DoctrineEntityManager) {
                (new \ReflectionProperty($manager, 'holder'))->getValue($manager)->current()->getConnection()->close();
            }
        }
    }

    return $previous = harnessDi($base);
};
$em = $wire();
$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);

$app = \Z77\Persistence\Doctrine\Console\MigrationsApplication::create(
    \Z77\Persistence\Doctrine\Bootstrap::buildEntityManager(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::collect(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder()),
    \Z77\Core\DI::getCacheManager()->generatedPhp(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::missing(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder())
);
$app->setAutoExit(false);
$out  = new \Symfony\Component\Console\Output\BufferedOutput();
$code = $app->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'migrate', '--no-interaction' => true]), $out);
echo "Setup\n";
check('S1 migrate exits 0 (vat, mandator, financial — Version20261127100000 among them)', $code === 0, trim($out->fetch()));
$columns = static fn(string $table): array => array_column($db->fetchAllAssociative("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [$table]), null, 'COLUMN_NAME');
$je = $columns('journal_entry');
$vc = $columns('vat_return_correction');
$out = new \Symfony\Component\Console\Output\BufferedOutput();
$diffCode = $app->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'diff', '--namespace' => 'Z77\\Module\\Financial\\Migrations']), $out);
$diffOut = $out->fetch();
check('S2 the schema: journal_entry.vat_period_from / _to DATE NULL, vat_period_rhythm NULL, index idx_journal_entry_vat_period; table vat_return_correction (exact values DECIMAL(21,6), bases, entries, both returns); `diff` reports NO change',
    ($je['vat_period_from']['COLUMN_TYPE'] ?? '') === 'date' && ($je['vat_period_from']['IS_NULLABLE'] ?? '') === 'YES' && ($je['vat_period_to']['COLUMN_TYPE'] ?? '') === 'date'
    && isset($je['vat_period_rhythm']) && (int) $db->fetchOne("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_entry' AND INDEX_NAME = 'idx_journal_entry_vat_period'") === 1
    && ($vc['original_exact']['COLUMN_TYPE'] ?? '') === 'decimal(21,6)' && ($vc['earlier_exact']['COLUMN_TYPE'] ?? '') === 'decimal(21,6)' && ($vc['correction_exact']['COLUMN_TYPE'] ?? '') === 'decimal(21,6)'
    && isset($vc['original_base'], $vc['entries'], $vc['vat_return_id'], $vc['corrected_return_id'], $vc['corrected_from'], $vc['corrected_to'])
    && $diffCode !== 0 && str_contains($diffOut, 'No changes detected'), trim($diffOut));

$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->adoptKmuChart();
foreach (['2025', '2026', '2027'] as $y) {
    $em = $wire();
    (new FiscalYearService($em))->open(new FiscalYear($y, day("{$y}-01-01"), day("{$y}-12-31")));
}
$em = $wire();
$mandator = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$mandator->mapFromArray([
    'name' => 'Harness AG', 'country' => 'CH', 'zip' => '8000', 'city' => 'Zürich', 'liable_to_vat' => true, 'uid' => 'CHE-116.281.710',
    'vat_method' => 'effective', 'vat_consideration' => 'agreed', 'vat_settlement_rhythm' => 'quarter', 'vat_settlement_from' => '2026-01-01',
]);
(new \Z77\Module\Mandator\Services\MandatorService($em))->save($mandator);

$declared = [VatReturnSources::CONFIG_KEY . ' of module-financial' => (require $packages['Financial'] . '/src/App/Config/financialConfig.inc.php')[VatReturnSources::CONFIG_KEY] ?? []];
$sources = new VatReturnSources($declared);
$service = static fn(): VatReturnService => new VatReturnService($wire(), null, new OpenWorkChecks([]), $sources);
$poster  = static fn(): VatReturnPostingService => new VatReturnPostingService($wire(), 'harness', new OpenWorkChecks([]), null, $sources);
$manual  = static fn(string $date, string $text, array $lines, ?VatPeriod $corrects = null) => (new ManualEntryService($wire(), 'harness'))->create(PostingRequest::manual(day($date), $text, $lines, $corrects));
$postReturn = static function (string $from, string $today) use ($poster) {
    $plan = $poster()->plan(quarter($from), day($today));
    $poster()->post(quarter($from), $plan->figuresFingerprint, $plan->warningsFingerprint, day($today));

    return $plan;
};
$liveOf  = static fn(string $from): ?array => ($r = $db->fetchAssociative('SELECT id, version FROM vat_return WHERE period_from = ? AND live = 1', [$from])) === false ? null : [(int) $r['id'], (int) $r['version']];
$entryId = static fn(string $text): array => array_map('intval', array_values((array) $db->fetchAssociative('SELECT id, version FROM journal_entry WHERE text = ? ORDER BY id DESC LIMIT 1', [$text])));
$moved   = static fn(string $number, string $from, string $to): string => (string) $db->fetchOne(
    'SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id JOIN account a ON a.id = l.account_id
      WHERE a.number = ? AND e.entry_date BETWEEN ? AND ?', [$number, $from, $to]);
$settlementLines = static fn(string $from, int $revision = 1): array => array_map(static fn(array $r) => $r['number'] . ' ' . ((float) $r['debit'] > 0 ? 'D ' . $r['debit'] : 'C ' . $r['credit']) . ' ' . $r['text'], $db->fetchAllAssociative(
    "SELECT a.number, l.debit, l.credit, l.text FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id JOIN account a ON a.id = l.account_id
      WHERE e.idempotency_key = ? ORDER BY l.position", [VatReturnPostingService::idempotencyKey(quarter($from), $revision)]));
$text = static fn(PostingRefusedException|EntryNotEditableException|null $e, \DateTimeImmutable $date): string
    => $e === null ? '' : (new LedgerService($wire()))->correctionRefusalText($e->reason, $date, $e->vatPeriod);
$catch = static function (callable $fn): ?\Throwable { try { $fn(); } catch (PostingRefusedException | EntryNotEditableException $e) { return $e; } return null; };

// Q3 2026: a sale 11 838.07 (exact tax 958.883670) and input tax 291.05 — the payable exact 667.833670, declared 667.83.
$manual('2026-08-15', 'Verkauf Q3', $sale('11838.07', '958.88'));
$manual('2026-09-10', 'Büromaterial Q3', [PostingLine::debit('6500', chf('3593.21'), null, 'VI', 810, chf('3593.21'), chf('291.05')), PostingLine::debit('1171', chf('291.05')), PostingLine::credit('1020', chf('3884.26'))]);
$postReturn('2026-01-01', '2026-11-01');   // nil
$postReturn('2026-04-01', '2026-11-01');   // nil
$q3Plan = $postReturn('2026-07-01', '2026-11-01');
check('S3 the setup: 1st and 2nd quarter 2026 nil, 3rd quarter 2026 posted — 399 = 958.883670, 479 = 291.05, payable 667.83 (exact 667.833670)',
    $q3Plan->preview->form->value('399')->toDecimal() === '958.883670' && $q3Plan->preview->form->value('479')->toDecimal() === '291.050000'
    && $q3Plan->payable()->equals(chf('667.83')) && $liveOf('2026-07-01') !== null);

// ─────────────────────────────────────────────────────────────────────────
echo "O. The rules — through the ledger\n";

$e = $catch(fn() => $manual('2026-09-30', 'O d coded', $sale('10.00', '0.81'), quarter('2026-04-01')));
$eUncoded = $catch(fn() => $manual('2026-08-31', 'O d uncoded', [PostingLine::debit('1000', chf('5.00')), PostingLine::credit('3200', chf('5.00'))], quarter('2026-04-01')));
check('O7 (d) a correction entry dated in a SETTLED period is refused `vat-period-settled` — with a tax line (the freeze) and also WITHOUT one (the rule: the freeze has no exemption); the sentence names the period and the way: «… Eine Korrekturbuchung wird in der laufenden Periode datiert»',
    $e?->reason === PostingRefusedException::VAT_PERIOD_SETTLED && $eUncoded?->reason === PostingRefusedException::VAT_PERIOD_SETTLED
    && $text($eUncoded, day('2026-08-31')) === 'Die MWST-Periode 3. Quartal 2026 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr. Eine Korrekturbuchung wird in der laufenden Periode datiert',
    $text($eUncoded, day('2026-08-31')));
$e  = $catch(fn() => $manual('2026-11-10', 'O a open', $sale('10.00', '0.81'), quarter('2026-10-01')));
$e2 = $catch(fn() => $manual('2026-11-10', 'O a days', $sale('10.00', '0.81'), new VatPeriod(day('2026-07-01'), day('2026-08-31'), VatRhythm::Quarter)));
check('O8 (a) the corrected period must be SETTLED with exactly these days: 4th quarter 2026 (open) and 1.7.–31.8.2026 (no return of these days) are refused `correction-not-settled` — «Die MWST-Periode 4. Quartal 2026 ist nicht abgerechnet — eine Korrekturbuchung korrigiert nur eine gebuchte Periode»',
    $e?->reason === PostingRules::CORRECTION_NOT_SETTLED && $e2?->reason === PostingRules::CORRECTION_NOT_SETTLED
    && $text($e, day('2026-11-10')) === 'Die MWST-Periode 4. Quartal 2026 ist nicht abgerechnet — eine Korrekturbuchung korrigiert nur eine gebuchte Periode', $text($e, day('2026-11-10')));
$e  = $catch(fn() => $manual('2025-12-20', 'O b', [PostingLine::debit('1000', chf('5.00')), PostingLine::credit('3200', chf('5.00'))], $q3));
$eb = $catch(fn() => (new PostingRules($wire()))->assertCorrectionAccepted(PostingRequest::manual(day('2025-12-20'), 'O b rule', $sale('10.00', '0.81'), $q3), false));
check('O9 (b) the corrected period must lie BEFORE the date: dated 20.12.2025 (before «Abrechnung ab») correcting 3rd quarter 2026 — refused `correction-not-before` through the ledger and by the rule alone; «Die korrigierte Periode muss vor dem Buchungsdatum liegen»',
    $e?->reason === PostingRules::CORRECTION_NOT_BEFORE && $eb?->reason === PostingRules::CORRECTION_NOT_BEFORE
    && $text($e, day('2025-12-20')) === 'Die korrigierte Periode muss vor dem Buchungsdatum liegen');
$e = $catch(fn() => $manual('2026-11-10', 'O c', [PostingLine::debit('1000', chf('540.00')), PostingLine::credit('3200', chf('540.00'))], $q3));
check('O10 (c) a correction entry without a coded line is refused `correction-no-tax-line` — «Eine Korrekturbuchung braucht mindestens eine Zeile mit MWST-Code»',
    $e?->reason === PostingRules::CORRECTION_NO_TAX_LINE && $text($e, day('2026-11-10')) === 'Eine Korrekturbuchung braucht mindestens eine Zeile mit MWST-Code');
$db->executeStatement("UPDATE mandator SET vat_settlement_until = '2026-10-31'");
$e = $catch(fn() => $manual('2026-11-10', 'O e', $sale('10.00', '0.81'), $q3));
$db->executeStatement('UPDATE mandator SET vat_settlement_until = NULL');
check('O11 (e) dated after «Abrechnung bis» (31.10.2026) — refused `correction-after-vat-end`: «Nach «Abrechnung bis» nimmt keine MWST-Abrechnung mehr eine Korrektur mit — die Korrektur direkt im ePortal melden»',
    $e?->reason === PostingRules::CORRECTION_AFTER_END && $text($e, day('2026-11-10')) === 'Nach «Abrechnung bis» nimmt keine MWST-Abrechnung mehr eine Korrektur mit — die Korrektur direkt im ePortal melden', $e?->reason ?? '');
$nothing = (int) $db->fetchOne("SELECT COUNT(*) FROM journal_entry WHERE text LIKE 'O %'");
$ok = reasonOf(fn() => $manual('2026-11-10', 'O ok', $sale('10.00', '0.81'), $q3));
[$okId, $okVersion] = $entryId('O ok');
$stored = $db->fetchAssociative('SELECT vat_period_from, vat_period_to, vat_period_rhythm FROM journal_entry WHERE id = ?', [$okId]);
check('O12 nothing of the refusals was posted; a valid correction entry (10.11.2026 correcting 3rd quarter 2026, coded) posts and stores its marker — 2026-07-01 / 2026-09-30 / quarter',
    $nothing === 0 && $ok === 'ok' && $stored === ['vat_period_from' => '2026-07-01', 'vat_period_to' => '2026-09-30', 'vat_period_rhythm' => 'quarter'], json_encode([$nothing, $ok, $stored]));
$editOpen  = reasonOf(fn() => (new ManualEntryService($wire(), 'harness'))->update($okId, $okVersion, PostingRequest::manual(day('2026-11-10'), 'O ok', $sale('10.00', '0.81'), quarter('2026-10-01'))));
$editClear = reasonOf(fn() => (new ManualEntryService($wire(), 'harness'))->update($okId, $okVersion, PostingRequest::manual(day('2026-11-10'), 'O ok', $sale('10.00', '0.81'))));
$cleared   = $db->fetchOne('SELECT vat_period_from FROM journal_entry WHERE id = ?', [$okId]);
$log       = json_decode((string) $db->fetchOne('SELECT after_snapshot FROM journal_entry_change WHERE entry_id = ? ORDER BY id DESC LIMIT 1', [$okId]) ?: 'null', true);
check('O13 an edit goes through the same rule: naming 4th quarter 2026 (open) is refused `correction-not-settled`; clearing the marker (same lines, date, text) is a CHANGE, not a no-op — the column is NULL and the change row\'s snapshot has no marker',
    $editOpen === PostingRules::CORRECTION_NOT_SETTLED && $editClear === 'ok' && $cleared === null && is_array($log) && !array_key_exists('vat_period_from', $log),
    json_encode([$editOpen, $editClear, $cleared]));
[$okId, $okVersion] = $entryId('O ok');
(new ManualEntryService($wire(), 'harness'))->delete($okId, $okVersion);

// ─────────────────────────────────────────────────────────────────────────
echo "K. The block of 1st quarter 2027\n";

// 4th quarter 2026: a sale, posted; then the year end of C-E and its input-tax mirror X3, uncoded, into the posted quarter.
$manual('2026-10-20', 'Verkauf Q4', $sale('500.00', '40.50'));
$postReturn('2026-10-01', '2027-01-20');
$manual('2026-12-31', 'Jahresende Barverkauf (C-E Schritt 1)', [PostingLine::debit('1000', chf('108.10')), PostingLine::credit('3200', chf('100.00')), PostingLine::credit('2209', chf('8.10'))]);
$manual('2026-12-31', 'Jahresende Material (X3 Schritt 1)', [PostingLine::debit('4000', chf('200.00')), PostingLine::debit('2209', chf('16.20')), PostingLine::credit('1000', chf('216.20'))]);

// 1st quarter 2027: an ordinary sale, and the correction entries.
$manual('2027-01-15', 'Verkauf Q1 2027', $sale('1000.00', '81.00'));
$manual('2027-02-22', 'Barverkauf 15.08.2026, Quittung 26-0412', $sale('499.54', '40.46'), $q3);
$manual('2027-02-25', 'Vergessene Materialrechnung Mai 2026', [PostingLine::debit('4000', chf('200.00'), null, 'VM', 810, chf('200.00'), chf('16.20')), PostingLine::debit('1170', chf('16.20')), PostingLine::credit('1020', chf('216.20'))], quarter('2026-04-01'));
$manual('2027-02-26', 'Bezugsteuer Beratung Mai 2026', [
    PostingLine::debit('6000', chf('300.00'), null, 'BM', 810, chf('300.00'), chf('24.30')), PostingLine::debit('1170', chf('24.30')),
    PostingLine::credit('2200', chf('24.30')), PostingLine::credit('6000', chf('300.00')),
], quarter('2026-04-01'));
$manual('2027-03-05', 'Jahresende MWST (C-E Schritt 2, X3 Schritt 2)', [
    PostingLine::credit('3200', chf('100.00'), null, 'UN', 810, chf('100.00'), chf('8.10')), PostingLine::credit('2200', chf('8.10')),
    PostingLine::debit('3200', chf('100.00')), PostingLine::debit('2209', chf('8.10')),
    PostingLine::debit('1170', chf('16.20'), null, 'VM', 810, chf('0.00'), chf('16.20')), PostingLine::credit('2209', chf('16.20')),
], quarter('2026-10-01'));
[$ceId] = $entryId('Jahresende MWST (C-E Schritt 2, X3 Schritt 2)');
[$goldId] = $entryId('Barverkauf 15.08.2026, Quittung 26-0412');
$ref = static fn(int $id): string => implode('/', array_values((array) $db->fetchAssociative('SELECT y.code, e.number FROM journal_entry e JOIN fiscal_year y ON y.id = e.fiscal_year_id WHERE e.id = ?', [$id])));

$q1 = quarter('2027-01-01');
$svc     = $service();
$preview = $svc->preview($q1);
$blocks  = $svc->correctionBlocks($q1);
$byField = static fn(VatReturnCorrectionBlock $b): array => array_combine(array_map(static fn(array $r) => $r['field'], $b->rows), $b->rows);
$dec     = static fn(?array $r, string $k) => $r === null ? null : ($r[$k] instanceof ExactAmount || $r[$k] instanceof Money ? $r[$k]->toDecimal() : $r[$k]);
check('K1 the ORDINARY form of 1st quarter 2027 leaves the correction entries out: 200 = 303 base = 1 000.00, 399 = 81.00, 400 = 0, payable 81.00; the line list of 303 holds the sale only',
    $preview->form->value('200')->toDecimal() === '1000.000000' && $preview->form->base('303')->equals(chf('1000.00')) && $preview->form->value('400')->isZero()
    && $preview->payable()->equals(chf('81.00')) && $svc->lineTotals($svc->lineSearch($q1, '303'))['lines'] === 1,
    json_encode(array_map(static fn($v) => $v->toDecimal(), $preview->form->values())));
check('K2 one block per corrected period, in period order: 2nd, 3rd, 4th quarter 2026 — each with its live return and its correction entries',
    array_map(static fn(VatReturnCorrectionBlock $b) => $b->period->label() . ':' . count($b->entries), $blocks) === ['2. Quartal 2026:2', '3. Quartal 2026:1', '4. Quartal 2026:1']
    && array_reduce($blocks, static fn(bool $ok, VatReturnCorrectionBlock $b) => $ok && $b->correctedReturn !== null, true)
    && $blocks[1]->entryRefs() === $ref($goldId), json_encode(array_map(static fn($b) => $b->period->label() . ':' . $b->entryRefs(), $blocks)));
$g = $byField($blocks[1]);
check('K3 golden (board S3-2): 3rd quarter 2026 — rows 200, 299, 303, 399, 500 only (the fields with a correction, plus the tax total and the payable amount); 200 / 299 / 303 turnover 11 838.07 + 499.54 = 12 337.61',
    array_map('strval', array_keys($g)) === ['200', '299', '303', '399', '500'] && $dec($g['200'], 'original') === '11838.070000' && $dec($g['200'], 'correction') === '499.540000' && $dec($g['200'], 'new') === '12337.610000'
    && $dec($g['303'], 'originalBase') === '11838.07' && $dec($g['303'], 'correctionBase') === '499.54' && $dec($g['303'], 'newBase') === '12337.61' && $g['303']['kind'] === FieldKind::Rate,
    json_encode(array_keys($g)));
check('K4 … the exact values (B-2): 303 tax 958.883670 + 40.462740 = 999.346410; 399 the same; 500 = 667.833670 + 40.462740 = 708.296410 — «neu» rounded ONCE: 708.30, while 667.83 + 40.46 would give 708.29',
    $dec($g['303'], 'original') === '958.883670' && $dec($g['303'], 'correction') === '40.462740' && $dec($g['399'], 'new') === '999.346410'
    && $dec($g['500'], 'original') === '667.833670' && $dec($g['500'], 'correction') === '40.462740' && $dec($g['500'], 'new') === '708.296410'
    && $g['500']['new']->rounded()->equals(chf('708.30')) && $g['500']['original']->rounded()->add($g['500']['correction']->rounded())->equals(chf('708.29'))
    && !$blocks[1]->hasEarlier(), json_encode([$dec($g['500'], 'original'), $dec($g['500'], 'new')]));
$g2 = $byField($blocks[0]);
check('K5 2nd quarter 2026 (a nil return): the forgotten input tax (VM 16.20) and the reverse-charge pattern of O-8 (BM 300.00 on the expense, the same without code against it — X15): 383 base 300.00 / 24.300000, 399 24.30, 400 16.20 + 24.30 = 40.50, 479 40.50, payable −16.20; no turnover row',
    array_map('strval', array_keys($g2)) === ['383', '399', '400', '479', '500'] && $dec($g2['383'], 'correctionBase') === '300.00' && $dec($g2['383'], 'correction') === '24.300000'
    && $dec($g2['400'], 'correction') === '40.500000' && $dec($g2['479'], 'new') === '40.500000' && $dec($g2['500'], 'correction') === '-16.200000' && $dec($g2['500'], 'original') === '0.000000',
    json_encode(array_map(static fn($r) => $r['correction']->toDecimal(), $g2)));
$g4 = $byField($blocks[2]);
check('K6 4th quarter 2026 (C-E step 2 with its mirror X3): 200 / 303 +100.00, tax 8.10, 400 +16.20 (the tax-only VM row on 1170 against 2209), payable 8.10 − 16.20 = −8.10',
    array_map('strval', array_keys($g4)) === ['200', '299', '303', '399', '400', '479', '500'] && $dec($g4['200'], 'correction') === '100.000000' && $dec($g4['303'], 'correction') === '8.100000'
    && $dec($g4['400'], 'correction') === '16.200000' && $dec($g4['500'], 'correction') === '-8.100000' && $dec($g4['500'], 'original') === '40.500000',
    json_encode(array_map(static fn($r) => $r['correction']->toDecimal(), $g4)));
$reco = $svc->reconciliation($q1);
$vatOf = static fn(array $accounts): array => array_map(static fn(VatAccountReconciliation $a) => "{$a->role}:{$a->accountNumber}:{$a->expected->toDecimal()}:{$a->movement->toDecimal()}", $accounts);
$corrLines = array_values(array_filter($reco->explained, static fn(ReconciliationLine $l) => $l->kind === ReconciliationLine::CORRECTION));
check('K7 «Abstimmung»: the VAT accounts expect the block as items of their own — 2200 81.00 + 24.30 + 40.46 + 8.10 = 153.86, 1170 40.50 + 16.20 = 56.70 — and agree with the movement; 2209 is no VAT account',
    $vatOf($reco->vatAccounts) === ['owed:2200:153.86:153.86', 'input:1170:56.70:56.70', 'input:1171:0.00:0.00'] && $reco->vatAccountsAgree(), json_encode($vatOf($reco->vatAccounts)));
check('K8 the turnover: revenue 1 499.54, declared 1 000.00 — the correction entries named ONE line each («Korrekturbuchung», the entry, «Korrektur zu …»: 499.54 and 0.00 for C-E\'s two lines), no «ohne MWST-Code» line, remainder 0.00',
    $reco->revenueTotal->equals(chf('1499.54')) && $reco->declared()->equals(chf('1000.00')) && $reco->remainder()->isZero()
    && array_map(static fn(ReconciliationLine $l) => "{$l->accountNumber}:{$l->accountName}:{$l->lines}:{$l->amount->toDecimal()}", $corrLines) === [$ref($goldId) . ':Korrektur zu 3. Quartal 2026:1:499.54', $ref($ceId) . ':Korrektur zu 4. Quartal 2026:2:0.00']
    && array_filter($reco->explained, static fn(ReconciliationLine $l) => $l->kind === ReconciliationLine::UNCODED) === [],
    json_encode(array_map(static fn($l) => "{$l->kind}:{$l->accountNumber}:{$l->amount->toDecimal()}", $reco->explained)));
$codes = array_map(static fn(VatReturnFinding $f) => ($f->blocking ? 'B:' : 'W:') . $f->code, $svc->findings($q1));
check('K9 nothing blocks; no `uncoded-revenue`, no `vat-account-remainder`, no `turnover-remainder`',
    array_filter($codes, static fn($c) => str_starts_with($c, 'B:')) === [] && !in_array('W:' . VatReturnFinding::UNCODED_REVENUE, $codes, true) && !in_array('W:' . VatReturnFinding::TURNOVER_REMAINDER, $codes, true),
    json_encode($codes));
$q3Row = $db->fetchAssociative("SELECT id, mapping_snapshot FROM vat_return WHERE period_from = '2026-07-01' AND live = 1");
$db->executeStatement('UPDATE vat_return SET mapping_snapshot = ? WHERE id = ?', [str_replace('"field":"303"', '"field":"302"', (string) $q3Row['mapping_snapshot']), $q3Row['id']]);
$warned = array_values(array_filter($service()->findings($q1), static fn(VatReturnFinding $f) => $f->code === VatReturnService::FINDING_CORRECTION_CATEGORY_CHANGED));
check('K10 a code mapped elsewhere today than in the corrected return\'s snapshot (UN «then» 302): a WARNING of the carrying return, never blocking — «Korrektur zu 3. Quartal 2026: Code UN gehörte damals zu Ziffer 302, heute zu Ziffer 303 — die Korrektur zählt nach heutiger Zuordnung.»',
    count($warned) === 1 && !$warned[0]->blocking && $warned[0]->message === 'Korrektur zu 3. Quartal 2026: Code UN gehörte damals zu Ziffer 302, heute zu Ziffer 303 — die Korrektur zählt nach heutiger Zuordnung.'
    && $warned[0]->reference === 'vat-return:2026-07-01', json_encode(array_map(static fn($f) => $f->message, $warned)));
$emK      = $wire();
$q3Return = $emK->getRepository(VatReturn::class)->find($liveOf('2026-07-01')[0]);
$q4Return = $emK->getRepository(VatReturn::class)->find($liveOf('2026-10-01')[0]);
$s = new VatReturnService($emK, null, new OpenWorkChecks([]), $sources);
check('K11 R25: the 3rd quarter 2026, posted before any correction entry, shows no «Seit dem Buchen geändert» (form and «Abstimmung»); the posted 4th quarter does on «Abstimmung» (C-E step 1, uncoded revenue dated into it after filing), not on «Herkunft»',
    !$s->changedSincePosting($q3Return, VatReturnService::VIEW_ORIGIN) && !$s->changedSincePosting($q3Return, VatReturnService::VIEW_RECONCILIATION)
    && $s->changedSincePosting($q4Return, VatReturnService::VIEW_RECONCILIATION) && !$s->changedSincePosting($q4Return, VatReturnService::VIEW_ORIGIN));

$plan = $poster()->plan($q1, day('2027-05-20'));
$lines = array_map(static fn(array $l) => $l['account'] . ' ' . ($l['debit']->isZero() ? 'C ' . $l['credit']->toDecimal() : 'D ' . $l['debit']->toDecimal()) . ' ' . $l['role'] . ' ' . $l['text'], $plan->lines);
check('K12 the settlement lines (board S3-2): the ordinary line per VAT account, beside it ONE line per corrected period and account «Korrektur {Periode}»; after the payable amount one line per corrected period on 2201 — Dr 2200 81.00 / 24.30 / 40.46 / 8.10, Cr 1170 40.50 / 16.20, Cr 2201 81.00, Dr 2201 16.20, Cr 2201 40.46, Dr 2201 8.10; no rounding line',
    $lines === [
        '2200 D 81.00 owed Geschuldete MWST', '2200 D 24.30 correction Korrektur 2. Quartal 2026', '2200 D 40.46 correction Korrektur 3. Quartal 2026', '2200 D 8.10 correction Korrektur 4. Quartal 2026',
        '1170 C 40.50 correction Korrektur 2. Quartal 2026', '1170 C 16.20 correction Korrektur 4. Quartal 2026',
        '2201 C 81.00 settlement MWST-Zahllast', '2201 D 16.20 correction Korrektur 2. Quartal 2026', '2201 C 40.46 correction Korrektur 3. Quartal 2026', '2201 D 8.10 correction Korrektur 4. Quartal 2026',
    ] && $plan->payable()->equals(chf('81.00')) && !$plan->isBlocked() && count($plan->corrections) === 3, json_encode($lines, JSON_UNESCAPED_UNICODE));
$manual('2027-03-20', 'Barverkauf nachgetragen', $sale('10.00', '0.81'), $q3);
$changed = reasonOf(fn() => $poster()->post($q1, $plan->figuresFingerprint, $plan->warningsFingerprint, day('2027-05-20')));
check('K13 the fingerprint covers the block: a correction entry added between the plan and the click — «MWST buchen» refuses `figures-changed`, nothing posted',
    $changed === VatReturnRefusal::FIGURES_CHANGED && $liveOf('2027-01-01') === null, $changed);
[$lateId, $lateVersion] = $entryId('Barverkauf nachgetragen');
(new ManualEntryService($wire(), 'harness'))->delete($lateId, $lateVersion);

$q1Plan = $postReturn('2027-01-01', '2027-05-20');
[$q1Id] = $liveOf('2027-01-01');
$db->executeStatement('UPDATE vat_return SET mapping_snapshot = ? WHERE id = ?', [$q3Row['mapping_snapshot'], $q3Row['id']]);
check('K14 «MWST buchen» posted it: the entry\'s lines as planned (texts on the lines); 2200, 1170 and 2201\'s correction part move to 0.00 over the quarter; 2209 at 0.00 since the year end (C-E and X3 cleared by the correction entry)',
    $settlementLines('2027-01-01') === array_map(static fn(string $l) => preg_replace('/ (owed|input|correction|settlement) /', ' ', $l), $lines)
    && $moved('2200', '2027-01-01', '2027-03-31') === '0.00' && $moved('1170', '2027-01-01', '2027-03-31') === '0.00' && $moved('2209', '2026-01-01', '2027-12-31') === '0.00'
    && $moved('2201', '2027-01-01', '2027-03-31') === '-97.16', json_encode([$settlementLines('2027-01-01'), $moved('2201', '2027-01-01', '2027-03-31')], JSON_UNESCAPED_UNICODE));

$corrRepo = static fn(): VatReturnCorrectionRepository => $wire()->getRepository(VatReturnCorrection::class);
$emK      = $wire();
$q1Return = $emK->getRepository(VatReturn::class)->find($q1Id);
$rows     = $emK->getRepository(VatReturnCorrection::class)->ofReturn($q1Return);
$declaredBlocks = (new VatReturnService($emK, null, new OpenWorkChecks([]), $sources))->declaredCorrectionBlocks($q1Return);
$flat = static fn(array $blocks): array => array_merge(...array_map(static fn(VatReturnCorrectionBlock $b) => array_map(static fn(array $r) => $b->period->label() . '|' . $b->entryRefs() . '|' . $r['field'] . '|' . $r['original']->toDecimal() . '|' . $r['earlier']->toDecimal() . '|' . $r['correction']->toDecimal() . '|' . $r['new']->toDecimal() . '|' . ($r['newBase']?->toDecimal() ?? '-'), $b->rows), $blocks));
check('K15 the block is STORED with the return — 17 rows (5 + 5 + 7) in block order, exact values, bases, entries — and read back by declaredCorrectionBlocks() exactly as it was computed (nothing recomputed)',
    count($rows) === 17 && $rows[0]->getField() === '383' && $rows[5]->getField() === '200' && $flat($declaredBlocks) === $flat($q1Plan->corrections)
    && $declaredBlocks[1]->correctedReturn?->getId() === $liveOf('2026-07-01')[0], json_encode(array_slice($flat($declaredBlocks), 0, 3), JSON_UNESCAPED_UNICODE));
$logDetail = json_decode((string) $db->fetchOne("SELECT detail FROM vat_return_log WHERE vat_return_id = ? AND action = 'post'", [$q1Id]), true);
check('K16 the protocol row «MWST gebucht» names the block: «Korrekturen früherer Perioden 16.16 (2. Quartal 2026, 3. Quartal 2026, 4. Quartal 2026)» (40.46 − 16.20 − 8.10)',
    ($logDetail['corrections'] ?? null) === 'Korrekturen früherer Perioden 16.16 (2. Quartal 2026, 3. Quartal 2026, 4. Quartal 2026)', json_encode($logDetail, JSON_UNESCAPED_UNICODE));

// The 3rd quarter 2026 corrected a second time, in 2nd quarter 2027 («frühere Korrekturen», C-C).
$manual('2027-05-10', 'Barverkauf 20.09.2026', $sale('100.00', '8.10'), $q3);
$q2b = $service()->correctionBlocks(quarter('2027-04-01'));
$e2  = $byField($q2b[0]);
check('K17 a period corrected a SECOND time: the block of 2nd quarter 2027 shows «ursprünglich» as originally posted, «frühere Korrekturen» = 1st quarter 2027\'s (499.54 / 40.462740), this correction 100.00 / 8.10, «neu» = 12 437.61 turnover, 500 = 667.833670 + 40.462740 + 8.10 = 716.396410 → 716.40; hasEarlier()',
    count($q2b) === 1 && $q2b[0]->hasEarlier() && $dec($e2['200'], 'original') === '11838.070000' && $dec($e2['200'], 'earlier') === '499.540000' && $dec($e2['200'], 'new') === '12437.610000'
    && $dec($e2['303'], 'earlier') === '40.462740' && $dec($e2['303'], 'earlierBase') === '499.54' && $dec($e2['500'], 'new') === '716.396410' && $e2['500']['new']->rounded()->equals(chf('716.40')),
    json_encode(array_map(static fn($r) => $r['earlier']->toDecimal() . '/' . $r['new']->toDecimal(), $e2)));
check('K18 correctingBetween(): the stored rows of LIVE carrying returns that correct a period ending in the range — 1st quarter 2027\'s five rows of 3rd quarter 2026',
    count(array_filter($corrRepo()->correctingBetween(day('2026-07-01'), day('2026-09-30')), static fn(VatReturnCorrection $c) => $c->getCorrectedFrom()->format('Y-m-d') === '2026-07-01')) === 5);

// ─────────────────────────────────────────────────────────────────────────
echo "L. Lifting\n";

$manual('2027-04-20', 'Korrektur Q1 eins', $sale('50.00', '4.05'), $q1);
[$q1Id, $q1Ver] = $liveOf('2027-01-01');
$liftOne = thrown(fn() => $poster()->lift($q1Id, $q1Ver, 'L eins'));
[$l1Id] = $entryId('Korrektur Q1 eins');
check('L1 «Aufheben» of 1st quarter 2027 is refused while a correction entry corrects ITS period — `corrected-by-entry`: «Die Korrekturbuchung {Nr} korrigiert 1. Quartal 2027 — zuerst diese Buchung ändern oder löschen, dann die Abrechnung aufheben.»',
    $liftOne instanceof VatReturnRefusedException && $liftOne->refusal->reason === VatReturnPostingService::REFUSAL_CORRECTED
    && $liftOne->refusal->message === 'Die Korrekturbuchung ' . $ref($l1Id) . ' korrigiert 1. Quartal 2027 — zuerst diese Buchung ändern oder löschen, dann die Abrechnung aufheben.'
    && $liveOf('2027-01-01') !== null, $liftOne?->getMessage() ?? 'none');
$manual('2027-04-21', 'Korrektur Q1 zwei', $sale('20.00', '1.62'), $q1);
$manual('2027-04-22', 'Korrektur Q1 drei', $sale('30.00', '2.43'), $q1);
$liftThree = thrown(fn() => $poster()->lift($q1Id, $q1Ver, 'L drei'));
check('L2 … with three: «Die Korrekturbuchung {Nr} und 2 weitere korrigieren 1. Quartal 2027 — zuerst diese Buchungen ändern oder löschen, dann die Abrechnung aufheben.»',
    $liftThree instanceof VatReturnRefusedException && $liftThree->refusal->message === 'Die Korrekturbuchung ' . $ref($l1Id) . ' und 2 weitere korrigieren 1. Quartal 2027 — zuerst diese Buchungen ändern oder löschen, dann die Abrechnung aufheben.',
    $liftThree?->getMessage() ?? 'none');
foreach (['Korrektur Q1 eins', 'Korrektur Q1 zwei', 'Korrektur Q1 drei'] as $t) {
    [$id, $version] = $entryId($t);
    (new ManualEntryService($wire(), 'harness'))->delete($id, $version);
}
$lifted = reasonOf(fn() => $poster()->lift($q1Id, $q1Ver, 'L: falsch gebucht'));
$reversal = array_map(static fn(array $r) => $r['number'] . ' ' . ((float) $r['debit'] > 0 ? 'D ' . $r['debit'] : 'C ' . $r['credit']), $db->fetchAllAssociative(
    'SELECT a.number, l.debit, l.credit FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id JOIN account a ON a.id = l.account_id
      WHERE e.idempotency_key = (SELECT CONCAT(\'reversal:\', y.code, \'/\', s.number) FROM vat_return r JOIN journal_entry s ON s.id = r.settlement_entry_id JOIN fiscal_year y ON y.id = s.fiscal_year_id WHERE r.id = ?) ORDER BY l.position', [$q1Id]));
check('L3 once the entries are gone the lift goes through; the reversal takes the block lines along (Cr 2200 24.30 / 40.46 / 8.10, Dr 1170 40.50 / 16.20, the 2201 lines swapped); the lifted return KEEPS its 17 block rows, correctingBetween() no longer counts them',
    $lifted === 'ok' && $reversal === ['2200 C 81.00', '2200 C 24.30', '2200 C 40.46', '2200 C 8.10', '1170 D 40.50', '1170 D 16.20', '2201 D 81.00', '2201 C 16.20', '2201 D 40.46', '2201 C 8.10']
    && (int) $db->fetchOne('SELECT COUNT(*) FROM vat_return_correction WHERE vat_return_id = ?', [$q1Id]) === 17
    && $corrRepo()->correctingBetween(day('2026-07-01'), day('2026-09-30')) === [] && $wire()->getRepository(VatReturnCorrection::class)->correctingBetween(day('2026-07-01'), day('2026-09-30')) === [],
    json_encode([$lifted, $reversal]));
$again = $service()->correctionBlocks($q1);
$r2Plan = $postReturn('2027-01-01', '2027-05-20');
[$q1r2] = $liveOf('2027-01-01');
check('L4 the correction entries return to the block of the NEXT revision: computed again as before, posted as revision 2 with its own 17 rows; the settlement entry r2 carries the block lines again',
    $flat($again) === $flat($q1Plan->corrections) && $flat($r2Plan->corrections) === $flat($q1Plan->corrections)
    && (int) $db->fetchOne('SELECT COUNT(*) FROM vat_return_correction WHERE vat_return_id = ?', [$q1r2]) === 17 && count($settlementLines('2027-01-01', 2)) === 10,
    json_encode($settlementLines('2027-01-01', 2), JSON_UNESCAPED_UNICODE));
$q2Plan = $postReturn('2027-04-01', '2027-07-20');
$emK    = $wire();
$q2Rows = $emK->getRepository(VatReturnCorrection::class)->ofReturn($emK->getRepository(VatReturn::class)->find($liveOf('2027-04-01')[0]));
check('L5 2nd quarter 2027 posted with the second correction of 3rd quarter 2026: its stored rows hold «frühere Korrekturen» 499.54 (the live revision 2 of 1st quarter 2027 — the lifted revision 1 counts no more)',
    $q2Rows !== [] && $q2Rows[0]->getField() === '200' && $q2Rows[0]->getEarlier()->toDecimal() === '499.540000' && $q2Rows[0]->getCorrection()->toDecimal() === '100.000000',
    json_encode(array_map(static fn(VatReturnCorrection $c) => $c->getField() . ':' . $c->getEarlier()->toDecimal(), $q2Rows)));

// ─────────────────────────────────────────────────────────────────────────
echo "R. Two processes — a correction entry against the lift of the period it corrects\n";

$postReturn('2027-07-01', '2028-01-20');
$postReturn('2027-10-01', '2028-01-20');
$em = $wire();
(new FiscalYearService($em))->open(new FiscalYear('2028', day('2028-01-01'), day('2028-12-31')));
$inFlight = static function (array $args, callable $write) use ($base): array {
    $mode = $args[0];
    @unlink("{$base}/{$mode}.ready");
    @unlink("{$base}/{$mode}.waiter");
    $worker = proc_open([PHP_BINARY, __FILE__, '--worker', $base, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    for ($t = 0; $t < 300 && !is_file("{$base}/{$mode}.ready"); $t++) { usleep(100000); }
    $held   = is_file("{$base}/{$mode}.ready");
    $result = $held ? reasonOf($write) : 'worker never held';
    $out    = trim(stream_get_contents($pipes[1])) . trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]); fclose($pipes[2]); proc_close($worker);

    return [$out, $result, $held, (string) @file_get_contents("{$base}/{$mode}.waiter")];
};
[$q4Id, $q4Ver] = $liveOf('2027-10-01');
$r1 = $inFlight(['holdcorrection', '2028-01-15', '2027-10-01', '2027-12-31'], fn() => $poster()->lift($q4Id, $q4Ver, 'R1: falsch gebucht'));
check('R1 a correction entry of 4th quarter 2027 in flight (dated 15.1.2028 — another fiscal year, so no journal range is shared), then the lift of 4th quarter 2027: the lift WAITED (worker: ' . $r1[0] . ') and was refused `corrected-by-entry` (' . $r1[1] . ') — the return stays live',
    $r1[0] === 'ok waited' && $r1[1] === VatReturnPostingService::REFUSAL_CORRECTED && $liveOf('2027-10-01') !== null, json_encode($r1));
[$rId, $rVersion] = $entryId('R Korrektur in flight');
(new ManualEntryService($wire(), 'harness'))->delete($rId, $rVersion);
[$q4Id, $q4Ver] = $liveOf('2027-10-01');
$r2 = $inFlight(['liftreturn', (string) $q4Id, (string) $q4Ver], fn() => $manual('2028-01-15', 'R2 Korrektur', $sale('50.00', '4.05'), quarter('2027-10-01')));
check('R2 the other order — the lift of 4th quarter 2027 in flight (held after its reversal), then the correction entry of that quarter: it WAITED (worker: ' . $r2[0] . ') and was refused `correction-not-settled` (' . $r2[1] . ') on the locking read of the corrected return; nothing posted',
    $r2[0] === 'ok waited' && $r2[1] === PostingRules::CORRECTION_NOT_SETTLED && $liveOf('2027-10-01') === null
    && (int) $db->fetchOne("SELECT COUNT(*) FROM journal_entry WHERE text = 'R2 Korrektur'") === 0, json_encode($r2));

// ─────────────────────────────────────────────────────────────────────────
echo "U. The screens — «Korrigiert Periode», the refusals, the badge, «berechnet», the block on page, window, PDF and voucher\n";
// The ledger R left: 1st quarter 2026 – 3rd quarter 2027 posted (1st quarter 2027 in revision 2 with its block of three
// periods, 2nd quarter 2027 with the second correction of 3rd quarter 2026), 4th quarter 2027 LIFTED — the running period;
// the years 2025–2028 open. The traits run through host doubles (as financial-vat-return-ledger F / P), the templates
// through the real TemplateRenderer.
require_once $root . '/packages/kernel/core/src/autoload/prod/php/Helper.php';
if (($stillLive = $liveOf('2027-10-01')) !== null) {
    $poster()->lift($stillLive[0], $stillLive[1], 'U: die laufende Periode');   // R did not leave it lifted — the fixture needs it open
}

$useU = static function (array $get, array $post = [], array $json = [], bool $fetch = false) use ($wire): void {
    $wire();
    $_SESSION = [];
    \Z77\Core\DI::getInstance()->set('Request', fn() => new class($get, $post, $json, $fetch) {
        public function __construct(private array $get, private array $post, private array $json, private bool $fetch) {}
        public function getGetParameter(string $p): mixed { return $this->get[$p] ?? null; }
        public function getPostParameters(): array { return $this->post; }
        public function getJsonBody(): array { return $this->json; }
        public function isPost(): bool { return $this->post !== [] || $this->json !== []; }
        public function getMode(): \Z77\Core\Http\RequestMode { return $this->fetch ? \Z77\Core\Http\RequestMode::Fetch : \Z77\Core\Http\RequestMode::Page; }
    }, true);
    \Z77\Core\DI::getInstance()->set('SessionManager', \Z77\Core\Session\SessionManager::class, true);
    \Z77\Core\DI::getInstance()->set('CsrfService', fn() => new class {
        public function generateEntityToken(string $context, int|string $id): string { return "tok-{$context}-{$id}"; }
        public function validateEntityToken(string $token, string $context, int|string $id): bool { return $token === "tok-{$context}-{$id}"; }
    }, true);
};
$layoutU = static fn(array $sections = []): object => new class($sections) {
    public function __construct(public array $sections) {}
    public function removeSection(string $s): void { unset($this->sections[$s]); }
    public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
};
$messagesU = static fn(): object => new class {
    public array $flashes = [];
    public function pushFlashAfterRedirect(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
    public function pushFlash(string $type, string $message): void { $this->flashes[] = [$type, $message, 'in-place']; }
};
/** The journal as the backend mounts it; $admin = may capture a correction entry (the access config's answer to `correction`). */
$journalU = static function (array $get, array $post = [], bool $admin = true) use ($useU, $layoutU, $messagesU) {
    $useU($get, $post);

    return new class($admin, $layoutU(), $messagesU()) {
        use \Z77\Module\Financial\Ui\JournalControllerTrait { listAction as public; addCompoundAction as public; detailAction as public; editAction as public; }
        public array $context = [];
        public object $help;
        public ?string $redirectedTo = null;
        public function __construct(private bool $admin, public object $layoutManager, public object $messageService) { $this->help = new \Z77\Core\Services\HelpService(); }
        protected function journalMayCorrect(): bool { return $this->admin; }
        private function manualEntryService(): ManualEntryService { return new ManualEntryService($this->em(), 'harness'); }   // no session: the actor named
        protected function em() { return \Z77\Core\DI::getUnifiedEntityManager(); }
        protected function fetch(): \Z77\Core\Http\Response\FetchResponse { return new \Z77\Core\Http\Response\FetchResponse(); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
        protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
    };
};
/** The VAT return screens as the backend mounts them (no open work, the real writer, actor «harness»). */
$returnU = static function (array $get, array $json = []) use ($useU, $layoutU, $messagesU, $sources) {
    $useU($get, [], $json);

    return new class($sources, $layoutU(['main' => ['Backend/VatReturnController/listAction']]), $messagesU()) {
        use \Z77\Module\Financial\Ui\VatReturnControllerTrait { listAction as public; formAction as public; originAction as public; reconciliationAction as public; pdfAction as public; liftAction as public; }
        public array $context = [];
        public object $help;
        public ?string $redirectedTo = null;
        public array $sent = [];
        private ?VatReturnService $svc = null;
        public function __construct(private VatReturnSources $sourcesIn, public object $layoutManager, public object $messageService) { $this->help = new \Z77\Core\Services\HelpService(); }
        protected function vatReturns(): VatReturnService { return $this->svc ??= new VatReturnService($this->em(), null, new OpenWorkChecks([]), $this->sourcesIn); }
        protected function vatReturnPoster(): VatReturnPostingService { return new VatReturnPostingService($this->em(), 'harness', new OpenWorkChecks([]), null, $this->sourcesIn); }
        protected function vatReturnCanReach(string $action): bool { return true; }
        protected function em() { return \Z77\Core\DI::getUnifiedEntityManager(); }
        protected function fetch(): \Z77\Core\Http\Response\FetchResponse { return new \Z77\Core\Http\Response\FetchResponse(); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
        protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
        protected function bytes(string $content, string $filename, string $mimeType, bool $inline = true): \Z77\Core\Http\Response\BytesResponse { $this->sent = [$content, $filename, $mimeType, $inline]; return new \Z77\Core\Http\Response\BytesResponse($content, $filename, $mimeType, $inline); }
    };
};
$tplU    = static fn(string $path, array $context): string => (new \Z77\Core\Services\TemplateRenderer('Z77\\Module\\Financial'))->partial($path, $context);
$pageU   = static fn(object $host): string => $tplU((string) end($host->layoutManager->sections['main']), $host->context);
$pdfText = static function (string $pdf): string {
    // The writer ends a stream with ONE "\n" — a compressed stream may itself end in "\r" (its checksum byte),
    // so the pattern must not take a "\r" off the data: the page text was lost in the minutes where it did
    // (the PDF carries its «Stand», so the checksum moves with the clock).
    preg_match_all('/stream\r?\n(.*?)\nendstream/s', $pdf, $m);

    return implode("\n", array_map(static function (string $s): string {
        $plain = @gzuncompress($s);
        if ($plain === false && str_ends_with($s, "\r")) {
            $plain = @gzuncompress(substr($s, 0, -1));
        }

        return $plain === false ? $s : $plain;
    }, $m[1]));
};
/** The Sammelbuchung as posted: the head and the rows (`account`, `debit`, `credit`, `tax_code`, `tax_amount`, `line_text`). */
$sammel = static function (string $date, string $text, string $token, array $rows): array {
    $post = ['date' => $date, 'text' => $text, \Z77\Module\Financial\Ui\ManualEntryForm::CORRECTS_FIELD => $token, 'op' => 'save'];
    foreach (['account', 'debit', 'credit', 'tax_code', 'tax_amount', 'line_text'] as $key) {
        $post[$key] = array_map(static fn(array $row): string => $row[$key] ?? '', $rows);
    }

    return $post;
};
$saleRows = [['account' => '1000', 'debit' => '540.00'], ['account' => '3200', 'credit' => '499.54', 'tax_code' => 'UN']];
$q3u      = quarter('2027-07-01');
$tok      = \Z77\Module\Financial\Ui\ManualEntryForm::periodToken($q3u);
$formOf   = static fn(object $host): \Z77\Module\Financial\Ui\ManualEntryForm => (fn() => $this->manualEntryForm())->call($host);

// ── the field ──
$host = $journalU(['mode' => 'sammel', 'year' => '2027', 'date' => '2027-11-10']);
$host->listAction();
$capture = $tplU('Backend/JournalController/form', $host->context);
$labels  = array_map(static fn(array $c): string => explode(' · gebucht ', $c['label'])[0], $host->context['form']->correctionChoices());
$hostNo  = $journalU(['mode' => 'sammel', 'year' => '2027', 'date' => '2027-11-10'], [], false);
$hostNo->listAction();
$hostOne = $journalU(['year' => '2027', 'date' => '2027-11-10']);
$hostOne->listAction();
check('U1 «Korrigiert Periode» in the Sammelbuchung (board S3-1): «— keine Korrektur —» the default, then the POSTED periods ending before the date, newest first, «… · gebucht dd.mm.yyyy» — 4th quarter 2027 (lifted, the running period) is not offered; without the right to `correction` the field is not rendered at all, and «Einzel» never has it',
    !in_array(false, $parts = [
        'choices' => $labels === ['— keine Korrektur —', '3. Quartal 2027', '2. Quartal 2027', '1. Quartal 2027', '4. Quartal 2026', '3. Quartal 2026', '2. Quartal 2026', '1. Quartal 2026']
            && str_contains($host->context['form']->correctionChoices()[1]['label'], ' · gebucht '),
        'field'   => str_contains($capture, '<label for="journal-c-vat-period">Korrigiert Periode</label>') && str_contains($capture, 'name="vat_period"')
            && str_contains($capture, '<option value="" selected>— keine Korrektur —</option>') && str_contains($capture, '<option value="' . $tok . '">3. Quartal 2027 · gebucht '),
        'right'   => !$hostNo->context['form']->offersCorrection() && !str_contains($tplU('Backend/JournalController/form', $hostNo->context), 'vat_period'),
        'einzel'  => !str_contains($tplU('Backend/JournalController/oneLine', $hostOne->context), 'vat_period'),
    ], true), falseOf($parts) . ' ' . json_encode($labels, JSON_UNESCAPED_UNICODE));

$f   = $formOf($journalU([]));
$req = $f->fromPost($sammel('2027-11-10', 'U Barverkauf 15.08.2027', $tok, $saleRows))->toRequest();
$fNo = $formOf($journalU([], [], false));
$reqNo = $fNo->fromPost($sammel('2027-11-10', 'U Barverkauf 15.08.2027', $tok, $saleRows))->toRequest();
$hint = static function (string $date) use ($formOf, $journalU, $sammel, $saleRows): string {
    $form = $formOf($journalU([]))->fromPost($sammel($date, 'U Hinweis', \Z77\Module\Financial\Ui\ManualEntryForm::periodToken(quarter('2026-07-01')), $saleRows));
    $form->preview();

    return $form->correctionHint();
};
check('U2 the form names the period on the request and reads the codes at its RATE DATE (S3-7): `corrects` = 3rd quarter 2027, the computed line «Satz der korrigierten Periode (30.09.2027)», lines 1000 D 540.00 · 3200 C 499.54 UN 40.46 · 2200 C 40.46; without the right a posted period is refused at the field («Eine Korrekturbuchung erfasst nur ein Admin.»); the hint «älter als fünf Jahre» from 2032 for 3rd quarter 2026 (2031: none) — a hint, the form still builds',
    !in_array(false, $parts = [
        'request' => $req !== null && $req->corrects?->equals($q3u) === true
            && array_map(static fn(PostingLine $l): string => $l->account . ' ' . ($l->debit->isZero() ? 'C ' . $l->credit->toDecimal() : 'D ' . $l->debit->toDecimal()) . ($l->taxCode === null ? '' : ' ' . $l->taxCode . ' ' . $l->taxAmount?->toDecimal()), $req->lines)
                === ['1000 D 540.00', '3200 C 499.54 UN 40.46', '2200 C 40.46'],
        'note'    => str_contains(implode(' ', array_column($f->computedLines(), 'note')), 'Satz der korrigierten Periode (30.09.2027)'),
        'right'   => $reqNo === null && $fNo->error(\Z77\Module\Financial\Ui\ManualEntryForm::CORRECTS_FIELD) === 'Eine Korrekturbuchung erfasst nur ein Admin.',
        'hint'    => $hint('2032-03-15') === 'Das Steuerjahr 2026 liegt mehr als fünf Jahre zurück — ob die Steuer noch geschuldet ist, entscheidet nicht z77.' && $hint('2031-12-31') === '',
    ], true), falseOf($parts));

// ── posting, the badge, «berechnet», the edit ──
$host = $journalU([], $sammel('2027-11-10', 'U Barverkauf 15.08.2027', $tok, $saleRows));
$host->addCompoundAction();
$uId     = $entryId('U Barverkauf 15.08.2027')[0] ?? 0;
$uMarker = $db->fetchAssociative('SELECT vat_period_from, vat_period_to, vat_period_rhythm FROM journal_entry WHERE id = ?', [$uId]);
$hostNo  = $journalU([], $sammel('2027-11-11', 'U ohne Recht', $tok, $saleRows), false);
$hostNo->addCompoundAction();
check('U3 «Buchen» of the Sammelbuchung with «Korrigiert Periode» (admin): posted, the flash names the number, the entry stores the marker 2027-07-01 / 2027-09-30 / quarter; the same POST without the right posts nothing and shows the refusal at the field',
    $uId > 0 && ($host->messageService->flashes[0][1] ?? '') === 'Buchung ' . $ref($uId) . ' erfasst'
    && $uMarker === ['vat_period_from' => '2027-07-01', 'vat_period_to' => '2027-09-30', 'vat_period_rhythm' => 'quarter']
    && (int) $db->fetchOne("SELECT COUNT(*) FROM journal_entry WHERE text = 'U ohne Recht'") === 0
    && str_contains($tplU('Backend/JournalController/form', $hostNo->context), 'Eine Korrekturbuchung erfasst nur ein Admin.'),
    json_encode([$host->messageService->flashes, $uMarker], JSON_UNESCAPED_UNICODE));

$host = $journalU(['id' => (string) $uId]);
$host->detailAction();
$detail = $tplU('Backend/JournalController/detail', $host->context);
$hostCe = $journalU(['id' => (string) $ceId]);
$hostCe->detailAction();
$detailCe = $tplU('Backend/JournalController/detail', $hostCe->context);
$host = $journalU(['year' => '2027', 'f_text' => 'U Barverkauf']);
$host->listAction();
$listU = $tplU('Backend/JournalController/listAction', $host->context);
check('U4 the journal (board S3-1 part 2): the entry window carries the badge «Korrektur zu 3. Quartal 2027» and marks the 2200 line «berechnet» (the tax its coded line computes, no column of its own); C-E step 2 marks only its 2200 line (the «nur Steuer» line on 1170 has its code, 2209 is no VAT account); the list row shows the same badge',
    str_contains($detail, 'data-journal-correction>Korrektur zu 3. Quartal 2027</span>') && substr_count($detail, 'data-journal-computed') === 1
    && preg_match('#data-journal-computed>\s*<span class="be-list__cell be-list__cell--mono">2200</span>#', $detail) === 1 && str_contains($detail, 'be-list__cell--muted">berechnet</span>')
    && substr_count($detailCe, 'data-journal-computed') === 1 && preg_match('#data-journal-computed>\s*<span class="be-list__cell be-list__cell--mono">2200</span>#', $detailCe) === 1
    && str_contains($detailCe, 'Korrektur zu 4. Quartal 2026')
    && str_contains($listU, 'data-journal-correction>Korrektur zu 3. Quartal 2027</span>'), $detail);

$host = $journalU(['id' => (string) $uId]);
$host->editAction();
$editForm = $tplU('Backend/JournalController/form', $host->context);
[$uId, $uVersion] = $entryId('U Barverkauf 15.08.2027');
$editPost = $sammel('2027-11-10', 'U Barverkauf 15.08.2027, Quittung 27-0815', '', $saleRows) + ['entity_csrf' => 'tok-journalEntry-' . $uId, 'version' => (string) $uVersion, 'form' => 'compound'];
unset($editPost[\Z77\Module\Financial\Ui\ManualEntryForm::CORRECTS_FIELD]);   // not rendered without the right
$hostNo = $journalU(['id' => (string) $uId], $editPost, false);
$hostNo->editAction();
$kept = $db->fetchAssociative('SELECT text, vat_period_from FROM journal_entry WHERE id = ?', [$uId]);
check('U5 a correction entry opens in the SAMMELBUCHUNG even in the one-line shape (the one-line form has no «Korrigiert Periode» and would drop it), its period selected; saved by someone without the right it KEEPS its marker (text changed)',
    $host->context['form'] instanceof \Z77\Module\Financial\Ui\ManualEntryForm && ($host->context['oneLineFits'] ?? null) === false
    && str_contains($editForm, '<option value="' . $tok . '" selected>3. Quartal 2027 · gebucht ')
    && $kept === ['text' => 'U Barverkauf 15.08.2027, Quittung 27-0815', 'vat_period_from' => '2027-07-01'], json_encode([$kept, $hostNo->messageService->flashes], JSON_UNESCAPED_UNICODE));

// ── the refusals at their field (board S3-1 part 3) and the way out of an ordinary entry (part 4, C-B) ──
$refusedU = static function (array $post) use ($journalU, $tplU): array {
    $host = $journalU([], $post);
    $host->addCompoundAction();
    $form = $host->context['form'];

    return [$form, $tplU('Backend/JournalController/form', $host->context)];
};
[$fa, $ha] = $refusedU($sammel('2027-11-12', 'U a', \Z77\Module\Financial\Ui\ManualEntryForm::periodToken(quarter('2027-10-01')), $saleRows));
[$fd]      = $refusedU($sammel('2027-08-15', 'U d', \Z77\Module\Financial\Ui\ManualEntryForm::periodToken(quarter('2027-04-01')), $saleRows));
[$fc]      = $refusedU($sammel('2027-11-12', 'U c', $tok, [['account' => '1000', 'debit' => '540.00'], ['account' => '3200', 'credit' => '540.00']]));
check('U6 a correction entry the ledger refuses — nothing posted, financial\'s sentence (`correctionRefusalText()`) at its FIELD, «Die Buchung wurde nicht gebucht — bitte die markierten Felder korrigieren.» on top: (a) «Korrigiert Periode» 4th quarter 2027 (open) — the period stays selected; (d) the date in the settled 3rd quarter 2027 at «Datum»; (c) no coded line on top',
    !in_array(false, $parts = [
        'a'     => $fa->error(\Z77\Module\Financial\Ui\ManualEntryForm::CORRECTS_FIELD) === 'Die MWST-Periode 4. Quartal 2027 ist nicht abgerechnet — eine Korrekturbuchung korrigiert nur eine gebuchte Periode.'
            && $fa->generalErrors() === ['Die Buchung wurde nicht gebucht — bitte die markierten Felder korrigieren.']
            && str_contains($ha, 'aria-invalid="true">') && str_contains($ha, '<option value="2027-10-01/2027-12-31/quarter" selected>4. Quartal 2027</option>')
            && str_contains($ha, 'eine Korrekturbuchung korrigiert nur eine gebuchte Periode.</small>'),
        'd'     => $fd->error('date') === 'Die MWST-Periode 3. Quartal 2027 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr. Eine Korrekturbuchung wird in der laufenden Periode datiert.',
        'c'     => $fc->generalErrors() === ['Eine Korrekturbuchung braucht mindestens eine Zeile mit MWST-Code.'],
        'none'  => (int) $db->fetchOne("SELECT COUNT(*) FROM journal_entry WHERE text IN ('U a', 'U d', 'U c')") === 0,
    ], true), falseOf($parts) . ' ' . json_encode([$fa->generalErrors(), $fd->error('date'), $fc->generalErrors()], JSON_UNESCAPED_UNICODE));

[$fSame] = $refusedU($sammel('2027-08-15', 'U normal Q3', '', $saleRows));
[$fOld]  = $refusedU($sammel('2026-11-15', 'U normal Q4 2026', '', $saleRows));
$wayOut  = static fn(\Throwable $e): string => (fn() => $this->journalRefusalMessage($e))->call($journalU([]));
$twoWays = 'Die MWST-Periode 3. Quartal 2027 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr. Zwei Wege: normal in der laufenden Periode buchen oder als Korrekturbuchung zu 3. Quartal 2027 (Admin).';
check('U7 C-B — an ORDINARY entry dated in a settled period gets the way out, measured against the year of the RUNNING period (4th quarter 2027, still open — not today\'s year): 3rd quarter 2027 → «Zwei Wege: …»; 4th quarter 2026 → «Ein Fehler aus dem Steuerjahr 2026 wird als Korrekturbuchung zu 4. Quartal 2026 erfasst (Admin) und im ePortal gemeldet.»; the one-line capture shares the sentence; an EXISTING entry frozen in a settled period keeps the S2 sentence',
    !in_array(false, $parts = [
        'same'    => $fSame->generalErrors() === [$twoWays],
        'earlier' => $fOld->generalErrors() === ['Die MWST-Periode 4. Quartal 2026 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr. Ein Fehler aus dem Steuerjahr 2026 wird als Korrekturbuchung zu 4. Quartal 2026 erfasst (Admin) und im ePortal gemeldet.'],
        'einzel'  => $wayOut(new PostingRefusedException(PostingRefusedException::VAT_PERIOD_SETTLED, 'x', null, $q3u)) === $twoWays,
        'frozen'  => $wayOut(new EntryNotEditableException(EntryNotEditableException::VAT_PERIOD_SETTLED, 'x', $q3u)) === 'Die MWST-Periode 3. Quartal 2027 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr; Buchungen ohne MWST-Code sind dort noch möglich.',
    ], true), falseOf($parts) . ' ' . json_encode([$fSame->generalErrors(), $fOld->generalErrors()], JSON_UNESCAPED_UNICODE));

// ── the open 4th quarter 2027: page, «Herkunft», «Abstimmung», the window, the PDF ──
$uRef = $ref($uId);
$uUrl = '/backend/finance/journal/detail?' . http_build_query(['ref' => $uRef]);
$host = $returnU(['from' => '2027-10-01']);
$host->formAction();
$q4Page = $pageU($host);
$host = $returnU(['from' => '2027-10-01']);
$host->originAction();
$q4Origin = $pageU($host);
$host = $returnU(['from' => '2027-10-01']);
$host->reconciliationAction();
$q4Reco = $pageU($host);
check('U8 the OPEN return of 4th quarter 2027 (board S3-2 part 1): under the form the block «Korrekturen früherer Perioden — separat im ePortal melden» — 3rd quarter 2027, its correction entry opening the journal window, rows 200 / 299 / 303 / 399 / 500, «+499.54», the exact tax small under the amount (40.46274), no column «frühere Korrekturen»; «Herkunft» names the entry «Korrektur zu 3. Quartal 2027» apart from the tree; «Abstimmung» links the entry\'s item to the journal',
    !in_array(false, $parts = [
        'block'  => str_contains($q4Page, 'data-vat-corrections') && str_contains($q4Page, 'Korrekturen früherer Perioden — separat im ePortal melden')
            && str_contains($q4Page, 'data-vat-correction-period="2027-07-01"') && !str_contains($q4Page, 'data-vat-correction-earlier') && !str_contains($q4Page, 'frühere Korrekturen'),
        'rows'   => preg_match_all('/data-vat-correction-field="(\d+)"/', $q4Page, $fm) === 5 && $fm[1] === ['200', '299', '303', '399', '500'],
        'values' => str_contains($q4Page, '+499.54') && str_contains($q4Page, 'data-vat-exact>40.46274</small>') && str_contains($q4Page, 'data-vat-tax="new">40.46<br>'),
        'entry'  => str_contains($q4Page, 'href="' . e($uUrl) . '" data-window-open="' . e($uUrl) . '" data-vat-correction-entry="' . $uRef . '">' . $uRef . '</a> vom 10.11.2027'),
        'origin' => str_contains($q4Origin, 'data-vat-origin-correction="' . $uRef . '"') && str_contains($q4Origin, 'Korrektur zu 3. Quartal 2027'),
        'reco'   => str_contains($q4Reco, 'href="' . e($uUrl) . '" data-window-open="' . e($uUrl) . '">' . $uRef . '</a> Korrektur zu 3. Quartal 2027'),
    ], true), falseOf($parts));

$planU = $poster()->plan(quarter('2027-10-01'), day('2028-01-20'));
$hostW = $returnU(['from' => '2027-10-01']);
$names = (fn(\Z77\Module\Financial\VatReturn\VatSettlementPlan $p): array => $this->vatReturnPlanAccounts($p))->call($hostW, $planU);
$snap  = (fn(\Z77\Module\Financial\VatReturn\VatSettlementPlan $p, array $n): array => self::vatReturnPlanSnapshot($p, $n))->call($hostW, $planU, $names);
$win   = $tplU('Backend/VatReturnController/confirmPost', ['period' => $planU->period, 'periodLabel' => $planU->period->label(), 'plan' => $planU, 'refusal' => null,
    'blocking' => $planU->blocking(), 'warnings' => $planU->warnings(), 'snapshot' => $snap, 'accounts' => $names, 'methodLabel' => $planU->preview->methodLabel,
    'entityCsrf' => 'tok', 'base' => '/backend/finance/vat-return', 'fmt' => static fn(?Money $m): string => \Z77\Shared\Money\AmountFormat::of($m)]);
$hostP = $returnU(['from' => '2027-10-01']);
$hostP->pdfAction();
$openPdf = $pdfText((string) ($hostP->sent[0] ?? ''));
check('U9 the window «MWST buchen» of 4th quarter 2027 (board S3-2 part 2, the plan as on 20.01.2028): the hint «Zuerst im ePortal einreichen und die Korrekturen früherer Perioden melden, dann hier buchen. …», one more line «Korrekturen früherer Perioden 40.46 an die ESTV, auf 2201 — 3. Quartal 2027» with the exact value small beneath, the entry\'s lines «Korrektur 3. Quartal 2027»; the computed PDF of the open period prints the block with its exact values',
    !in_array(false, $parts = [
        'plan'   => $planU->blocking() === [] && count($planU->corrections) === 1,
        'hint'   => str_contains($win, 'Zuerst im ePortal einreichen und die Korrekturen früherer Perioden melden, dann hier buchen. Gebucht heisst eingereicht.'),
        'line'   => str_contains($win, 'data-vat-post-corrections><strong>40.46</strong> an die ESTV, auf 2201 — 3. Quartal 2027<br><small class="be-list__cell--muted" data-vat-exact>40.46274</small>'),
        'entry'  => substr_count($win, 'Korrektur 3. Quartal 2027') === 2,
        'pdf'    => ($hostP->sent[2] ?? '') === 'application/pdf' && str_contains($openPdf, '40.46274') && str_contains($openPdf, 'Korrekturbuchung ' . $uRef . ' vom 10.11.2027') && str_contains($openPdf, '3. Quartal 2027'),
    ], true), falseOf($parts) . ' ' . $win);

// ── posted: the stored block, the head, the voucher, the protocol ──
$postReturn('2027-10-01', '2028-01-20');
[$q4uId] = $liveOf('2027-10-01');
$host = $returnU(['from' => '2027-10-01']);
$host->formAction();
$q4Posted = $pageU($host);
$voucherPath = (string) $db->fetchOne('SELECT voucher_path FROM vat_return WHERE id = ?', [$q4uId]);
$voucherText = $pdfText((string) @file_get_contents($base . '/' . $voucherPath));
$host = $returnU(['year' => '2027']);
$host->listAction();
$listPage = $pageU($host);
check('U10 4th quarter 2027 POSTED with its block: the page shows the STORED block (the same rows), the head «Korrekturen früherer Perioden 40.46 (3. Quartal 2027)» (the protocol row\'s detail); the archived voucher prints the block with its exact values and the settlement lines by their text «Korrektur 3. Quartal 2027» (FIN-VAT-VOUCHER-ROLE-001); the list\'s protocol names the block in the row «MWST gebucht»',
    !in_array(false, $parts = [
        'stored'  => str_contains($q4Posted, 'data-vat-return-stored="' . $q4uId . '"') && str_contains($q4Posted, 'data-vat-correction-period="2027-07-01"') && str_contains($q4Posted, 'data-vat-exact>40.46274</small>'),
        'head'    => str_contains($q4Posted, '<span data-vat-return-corrections>Korrekturen früherer Perioden 40.46 (3. Quartal 2027)</span>'),
        'voucher' => $voucherText !== '' && str_contains($voucherText, 'Korrektur 3. Quartal 2027') && str_contains($voucherText, '40.46274') && str_contains($voucherText, 'Korrekturbuchung ' . $uRef) && !str_contains($voucherText, '(correction)'),
        'log'     => str_contains($listPage, 'Korrekturen früherer Perioden 40.46 (3. Quartal 2027)'),
    ], true), falseOf($parts));

$host = $returnU(['from' => '2027-01-01']);
$host->formAction();
$q1Page = $pageU($host);
$host = $returnU(['from' => '2027-04-01']);
$host->formAction();
$q2Page = $pageU($host);
check('U11 B-2 / C-C on the posted returns of K: 1st quarter 2027 — three periods, 3rd quarter 2026 «ursprünglich» 667.83 with 667.83367, «neu» 708.30 with 708.29641 (rounded ONCE), no «frühere Korrekturen»; 2nd quarter 2027 — the column «frühere Korrekturen» (+499.54 / +40.46 with 40.46274), «neu» 716.40 with 716.39641',
    !in_array(false, $parts = [
        'q1'    => substr_count($q1Page, 'data-vat-correction-period=') === 3 && str_contains($q1Page, '667.83<br><small class="be-list__cell--muted" data-vat-exact>667.83367</small>')
            && str_contains($q1Page, '708.30<br><small class="be-list__cell--muted" data-vat-exact>708.29641</small>') && !str_contains($q1Page, 'frühere Korrekturen')
            && str_contains($q1Page, 'Korrekturen früherer Perioden 16.16 (2. Quartal 2026, 3. Quartal 2026, 4. Quartal 2026)'),
        'q2'    => str_contains($q2Page, 'data-vat-correction-earlier') && str_contains($q2Page, 'frühere Korrekturen') && str_contains($q2Page, 'data-vat-tax="earlier">+40.46<br><small class="be-list__cell--muted" data-vat-exact>40.46274</small>')
            && str_contains($q2Page, '716.40<br><small class="be-list__cell--muted" data-vat-exact>716.39641</small>') && str_contains($q2Page, '+499.54'),
    ], true), falseOf($parts));

// ── «Aufheben» refused by a correction entry: the window names it with its link; the running period moved on ──
$manual('2028-01-15', 'U Korrektur 4. Quartal 2027', $sale('10.00', '0.81'), quarter('2027-10-01'));
$liftRef = $ref($entryId('U Korrektur 4. Quartal 2027')[0] ?? 0);
[$q4uId, $q4uVersion] = $liveOf('2027-10-01');
$host = $returnU([], ['id' => (string) $q4uId, 'version' => (string) $q4uVersion, 'reason' => 'U: Probe', 'entity_csrf' => 'tok-vatReturn.lift-' . $q4uId]);
$env  = (fn() => $this->build())->call($host->liftAction());
$slot = implode('', array_map(static fn(array $c): string => $c['html'] ?? '', array_filter($env['commands'] ?? [], static fn(array $c): bool => ($c['action'] ?? '') === 'replace-html')));
$liftUrl = '/backend/finance/journal/detail?' . http_build_query(['ref' => $liftRef]);
check('U12 «Aufheben» of 4th quarter 2027 while a correction entry corrects it: refused in the window — the service\'s sentence and the link to that entry (the reference `journal-entry:{year}/{nr}`); after the posting the running period is 1st quarter 2028, so an error of 3rd quarter 2027 now gets «Ein Fehler aus dem Steuerjahr 2027 …»',
    ($env['status'] ?? '') === 'error' && str_contains($slot, 'Die Korrekturbuchung ' . $liftRef . ' korrigiert 4. Quartal 2027 — zuerst diese Buchung ändern oder löschen, dann die Abrechnung aufheben.')
    && str_contains($slot, '<a href="' . e($liftUrl) . '" data-vat-window-link>Buchung ' . $liftRef . '</a>') && $liveOf('2027-10-01') !== null
    && $wayOut(new PostingRefusedException(PostingRefusedException::VAT_PERIOD_SETTLED, 'x', null, $q3u)) === 'Die MWST-Periode 3. Quartal 2027 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr. Ein Fehler aus dem Steuerjahr 2027 wird als Korrekturbuchung zu 3. Quartal 2027 erfasst (Admin) und im ePortal gemeldet.',
    json_encode($env, JSON_UNESCAPED_UNICODE));

// ── help and the access pin ──
$tplDir   = $packages['Financial'] . '/res/view/templates/Backend';
$formHelp = (string) file_get_contents($tplDir . '/JournalController/form.help.tpl.php');
$retHelp  = (string) file_get_contents($tplDir . '/VatReturnController/return.help.tpl.php');
$listHelp = (string) file_get_contents($tplDir . '/VatReturnController/list.help.tpl.php');
$access   = require $root . '/packages/module-backend/src/App/Config/backendConfig.inc.php';
check('U13 help: `form.help` has the sections vat_period, two_ways, earlier_year, vat_rate, year_end (C-E through 2209 on the fiscal year\'s last day, its input-tax mirror), old_year, rappen and the reverse-charge pattern; `return.help` corrections and exact; «Noch nicht verfügbar» names no corrections any more; `correctionAction` of the journal pinned to ADMIN',
    !in_array(false, $parts = [
        'form'   => array_diff(['vat_period', 'two_ways', 'earlier_year', 'vat_rate', 'year_end', 'old_year', 'rappen'], (preg_match_all('/data-help-field="([a-z_]+)"/', $formHelp, $hm) ? $hm[1] : [])) === []
            && str_contains($formHelp, '2209 «MWST-Korrektur, noch nicht abgerechnet»') && str_contains($formHelp, 'am letzten Tag des Geschäftsjahres (meist 31.12.)')
            && str_contains($formHelp, 'Vorsteuer gleich, gespiegelt') && str_contains($formHelp, 'BM / BI (BM2 / BI2)'),
        'return' => str_contains($retHelp, 'data-help-field="corrections"') && str_contains($retHelp, 'data-help-field="exact"'),
        'later'  => !str_contains($retHelp, '<strong>Korrekturen</strong> einer schon eingereichten Periode') && !str_contains($listHelp, '<strong>Korrekturen</strong> einer schon eingereichten Periode'),
        'pin'    => ($access['controllers']['finance']['JournalController']['actions']['correctionAction'] ?? null) === \Z77\Core\Config\AuthRole::ADMIN,
    ], true), falseOf($parts));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
