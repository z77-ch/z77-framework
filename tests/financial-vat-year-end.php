<?php

/**
 * The year end of the VAT return (CLI) — the transit account 2209 «MWST-Korrektur, noch nicht
 * abgerechnet» (ADR-042 addendum 3, point 4; financial.md, the year close):
 *
 *   O. (offline) source guards: the VAT accounts' one table (`VatAccountMap`), the return's
 *      reconciliation (`VatAccountExpectation`, `vatAccountCategories()`), the key map
 *      (`LedgerService::vatAccountKey()`) and the lock (`PostingRules`) never name the key; the reader
 *      reads through `LedgerService::vatReturnAccount()`; the check is a registry-ready WARNING class
 *      (no required constructor argument, refuses a scope without `from` / `to`);
 *   M. (database) a throwaway schema through the modules' migrations; the books: KMU chart (2209
 *      under 220, hand-postable), fiscal years 2025 and 2026, a liable mandator (quarter from
 *      1.1.2025, 2209 named), the 4th quarter 2025 POSTED (a live return row);
 *   YE. `VatCorrectionTransit` and `VatCorrectionPendingCheck` on the C-E pattern: nothing named →
 *      no finding; 0.00 → none; step 1 on 31.12.2025 (uncoded, through 2209, into the posted Q4 —
 *      the lock and the freeze let it pass) without step 2 → the warning with the open amount; the
 *      year opening of 2026 does not hide it (and is not counted twice); a REAL close of 2025: not
 *      without the tick, never blocked, with the tick closed and the sentence in the close protocol;
 *      step 2 dated 15.02.2026 (net WITH the code, the same net without, Dr 2209) → nothing open, the
 *      balance per 31.12.2025 still shown; the input-tax mirror (a DEBIT on 2209) → the signed amount;
 *      `VatAccountMap::lockedAccounts()` / `accounts()` never contain 2209.
 *
 * Every name and amount here is synthetic.
 *
 * Run: php tests/financial-vat-year-end.php (or through tests/run.php once registered)
 * O needs the monorepo's vendor/; M and YE a reachable MariaDB and the `z77test` credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*; without them M is reported SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "financial-vat-year-end: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;
require_once $root . '/packages/kernel/core/src/autoload/prod/php/Helper.php';

use Z77\Core\DI;
use Z77\Module\Financial\Close\VatCorrectionPendingCheck;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Services\FiscalYearCloseRefusedException;
use Z77\Module\Financial\Services\FiscalYearCloseService;
use Z77\Module\Financial\Services\FiscalYearService;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\PostingRefusedException;
use Z77\Module\Financial\Services\PostingRules;
use Z77\Module\Financial\Services\VatAccountMap;
use Z77\Module\Financial\Services\YearOpeningService;
use Z77\Module\Financial\VatReturn\VatCorrectionTransit;
use Z77\Persistence\Doctrine\OpenWork\Finding;
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

/** The exception thrown by $fn, or null. */
function thrown(callable $fn): ?\Throwable
{
    try { $fn(); } catch (\Throwable $e) { return $e; }
    return null;
}

function day(string $ymd): \DateTimeImmutable
{
    return new \DateTimeImmutable($ymd);
}

function chf(string $decimal): Money
{
    return Money::fromDecimal($decimal, 'CHF');
}

function dr(string $account, string $amount, ?string $code = null, ?int $rate = null, ?string $base = null, ?string $tax = null): PostingLine
{
    return PostingLine::debit($account, chf($amount), null, $code, $rate, $base === null ? null : chf($base), $tax === null ? null : chf($tax));
}

function cr(string $account, string $amount, ?string $code = null, ?int $rate = null, ?string $base = null, ?string $tax = null): PostingLine
{
    return PostingLine::credit($account, chf($amount), null, $code, $rate, $base === null ? null : chf($base), $tax === null ? null : chf($tax));
}

/** The source without its comments — a docblock may explain the account, code may not name it. */
$codeOf = static fn(string $src): string => implode('', array_map(static fn($t): string => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all($src)));
$fin    = $root . '/packages/module-financial';

// ─────────────────────────────────────────────────────────────────────────
echo "O. Who knows the key — source guards; the check's shape (offline)\n";

$named = static fn(string $file): bool => (bool) preg_match('/vat-correction-pending|VAT_CORRECTION_PENDING|2209/', $codeOf((string) file_get_contents($fin . '/' . $file)));
check('O1 the VAT accounts\' one table (VatAccountMap), the return\'s reconciliation (VatAccountExpectation, VatReturnService::vatAccountCategories()) and the lock (PostingRules) name neither the key nor 2209 in code — 2209 is no VAT account (X2)',
    !$named('src/Services/VatAccountMap.php') && !$named('src/VatReturn/VatAccountExpectation.php') && !$named('src/VatReturn/VatReturnService.php') && !$named('src/Services/PostingRules.php'));
$keyMap = [];
foreach (\Z77\Module\Vat\Entities\TaxCategory::cases() as $category) {
    $keyMap[] = LedgerService::vatAccountKey($category->value);
}
check('O2 LedgerService::vatAccountKey() maps no tax category onto the key — only the three VAT accounts (or nothing)',
    !in_array('vat-correction-pending', $keyMap, true) && array_values(array_unique(array_filter($keyMap))) !== [] && array_diff(array_filter($keyMap), ['vat-owed', 'vat-input-material', 'vat-input-other']) === []);
$transitSrc = $codeOf((string) file_get_contents($fin . '/src/VatReturn/VatCorrectionTransit.php'));
$checkSrc   = (string) file_get_contents($fin . '/src/Close/VatCorrectionPendingCheck.php');
check('O3 the reader asks the mandator through LedgerService::vatReturnAccount(VAT_CORRECTION_PENDING_ACCOUNT) only (mandator H4: no CurrentMandator, no ->account() off the record); the check reads through the reader and yields a WARNING only, never a blocking finding; no docblock cites docs/temp/',
    str_contains($transitSrc, 'vatReturnAccount(LedgerService::VAT_CORRECTION_PENDING_ACCOUNT)') && LedgerService::VAT_CORRECTION_PENDING_ACCOUNT === 'vat-correction-pending'
    && !str_contains($transitSrc . $checkSrc, 'CurrentMandator') && str_contains($checkSrc, 'Finding::warning(') && !str_contains($codeOf($checkSrc), 'Finding::blocking(')
    && str_contains($codeOf($checkSrc), 'openAfter($to)') && !str_contains($transitSrc . $checkSrc, 'docs/temp'));
$registry = thrown(fn() => new OpenWorkChecks(['period-close' => [VatCorrectionPendingCheck::class]]));
check('O4 the registry accepts the check (no required constructor argument — it reaches its services through DI); a scope without `from` / `to` as dates is refused loudly',
    $registry === null
    && thrown(fn() => iterator_to_array((new VatCorrectionPendingCheck())->check('period-close', ['fiscalYear' => '2025']))) instanceof \InvalidArgumentException
    && thrown(fn() => iterator_to_array((new VatCorrectionPendingCheck())->check('period-close', ['from' => '2025-01-01', 'to' => '2025-12-31']))) instanceof \InvalidArgumentException);

// ── M. database ──────────────────────────────────────────────────────────

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
    echo "M. Database — SKIPPED (no z77test password: %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD)\n";
    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}

echo "M. Database — the books (throwaway schema)\n";

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-vat-year-end-' . getmypid());
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

$packages = [
    'Vat'       => $root . '/packages/module-vat',
    'Mandator'  => $root . '/packages/module-mandator',
    'Financial' => $fin,
];
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

/**
 * A fresh request: new DI, new UnifiedEntityManager, empty identity map. The previous request's
 * connection is CLOSED — the finance group runs DB harnesses side by side (1040 «Too many connections»).
 */
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
    DI::getInstance(true)
        ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
        ->set('FileFinder', fn($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn($c) => new \Z77\Core\Services\ModuleManager($c->get('ConfigManager')), true)
        ->set('I18n', fn($c) => new \Z77\Core\Services\I18n($c->get('ConfigManager')), true)
        ->set('DataSourceResolver', fn() => new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new \Z77\Persistence\Resolver\UnifiedEntityManager($c->get('DataSourceResolver')), true);
    DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return $previous = DI::getUnifiedEntityManager();
};
$em = $wire();
$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);

$app = \Z77\Persistence\Doctrine\Console\MigrationsApplication::create(
    \Z77\Persistence\Doctrine\Bootstrap::buildEntityManager(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder()),
    DI::getCacheManager()->generatedPhp(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::missing(DI::getModuleManager(), DI::getFileFinder())
);
$app->setAutoExit(false);
$migrateOut  = new \Symfony\Component\Console\Output\BufferedOutput();
$migrateCode = $app->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'migrate', '--no-interaction' => true]), $migrateOut);
check('M1 migrate exits 0 (vat, mandator, financial)', $migrateCode === 0, trim($migrateOut->fetch()));

$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->adoptKmuChart();
$em = $wire();
(new FiscalYearService($em))->open(new FiscalYear('2025', day('2025-01-01'), day('2025-12-31')));
$em = $wire();
(new FiscalYearService($em))->open(new FiscalYear('2026', day('2026-01-01'), day('2026-12-31')));
$em = $wire();
$mandator = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$mandator->mapFromArray([
    'name' => 'Jahresende AG', 'country' => 'CH', 'zip' => '3000', 'city' => 'Bern', 'liable_to_vat' => true, 'uid' => 'CHE-116.281.710',
    'vat_method' => 'effective', 'vat_consideration' => 'agreed', 'vat_settlement_rhythm' => 'quarter', 'vat_settlement_from' => '2025-01-01',
]);
(new \Z77\Module\Mandator\Services\MandatorService($em))->save($mandator);
$yearId = static fn(string $code): int => (int) $db->fetchOne('SELECT id FROM fiscal_year WHERE code = ?', [$code]);
// The 4th quarter 2025 POSTED — a live return row, the way the posting harness inserts it (a nil return, no settlement entry).
$db->insert('vat_return', [
    'period_from' => '2025-10-01', 'period_to' => '2025-12-31', 'revision' => 1, 'state' => 'posted', 'live' => 1,
    'rhythm' => 'quarter', 'method' => 'effective', 'consideration' => 'agreed', 'country' => 'CH', 'form_edition' => 'harness',
    'mapping_hash' => str_repeat('a', 64), 'mapping_snapshot' => '[]', 'currency' => 'CHF', 'payable' => '0.00', 'posted_tax' => '0.00',
    'rounding_difference' => '0.00', 'posted_by' => 'harness', 'posted_at' => '2026-01-20 09:00:00', 'figures_fingerprint' => str_repeat('b', 64),
    'voucher_path' => 'data/archive/financial/vat-return/2025/harness.pdf', 'voucher_sha256' => str_repeat('c', 64), 'voucher_name' => 'harness.pdf',
]);
$em = $wire();
$q4Settled = (new \Z77\Module\Financial\VatReturn\VatSettlement($em))->periodCovering(day('2025-12-31'));
check('M2 the books: the KMU chart with 2209 (liability under 220, postable, NOT «nur durch Module»), fiscal years 2025 and 2026, a liable mandator naming 2209, the 4th quarter 2025 settled',
    $db->fetchAssociative("SELECT a.type, a.postable, a.module_posting_only, p.number AS parent FROM account a JOIN account p ON p.id = a.parent_id WHERE a.number = '2209'")
        == ['type' => 'liability', 'postable' => 1, 'module_posting_only' => 0, 'parent' => '220']
    && (int) $db->fetchOne('SELECT COUNT(*) FROM fiscal_year') === 2
    && $db->fetchOne('SELECT account_vat_correction_pending FROM mandator WHERE id = 1') === '2209'
    && $q4Settled !== null && $q4Settled->to->format('Y-m-d') === '2025-12-31');

// ─────────────────────────────────────────────────────────────────────────
echo "YE. The year-end transit account: the reader, the close warning, the C-E pattern through the lock\n";

$post = static function (PostingRequest $request) use ($wire): \Z77\Module\Financial\Ledger\EntryRef {
    $em = $wire();

    return $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'harness'))->post($request));
};
/** The real lock rule on a fresh EntityManager: «pass», or «{reason} {account}». */
$lock = static function (PostingRequest $r) use ($wire): string {
    try {
        (new PostingRules($wire()))->assertVatLinesComputed($r, null);
    } catch (PostingRefusedException $e) {
        return trim($e->reason . ' ' . ($e->account ?? ''));
    }

    return 'pass';
};
/** The findings of the check for a fiscal year (its days as the close passes them), on a fresh request. */
$findings = static function (string $code) use ($wire): array {
    $wire();
    $year = (int) substr($code, 0, 4);

    return iterator_to_array((new VatCorrectionPendingCheck())->check('period-close', ['fiscalYear' => $code, 'from' => day("{$year}-01-01"), 'to' => day("{$year}-12-31")]), false);
};
$texts   = static fn(array $found): array => array_map(static fn(Finding $f): string => ($f->severity === \Z77\Persistence\Doctrine\OpenWork\Severity::Warning ? 'warning ' : 'blocking ') . $f->message . ' [' . $f->reference . ']', $found);
$transit = static fn(): VatCorrectionTransit => new VatCorrectionTransit($wire());
$amounts = static fn(VatCorrectionTransit $t, string $day): array => [$t->balanceAt(day($day))->toDecimal(), $t->openAfter(day($day))->toDecimal()];
$warning = static fn(string $amount): string => 'warning Konto 2209 MWST-Korrektur, noch nicht abgerechnet: Saldo ' . $amount . ' — die Korrekturbuchung dazu steht noch aus. [account:2209]';

check('YE1 nothing posted on 2209: the reader names 2209, balance and open amount 0.00 → no finding for 2025',
    $transit()->account() === '2209' && $amounts($transit(), '2025-12-31') === ['0.00', '0.00'] && $findings('2025') === []);

// C-E step 1 on the LAST day of the fiscal year, the 4th quarter already posted: the revenue net WITHOUT a code, the VAT onto 2209.
$step1 = PostingRequest::manual(day('2025-12-31'), 'Nachtrag Rechnung 2025-88 (MWST über 2209)', [dr('1000', '1081.00'), cr('3200', '1000.00'), cr('2209', '81.00')]);
$step1Lock = $lock($step1);
$step1Ref  = thrown(fn() => $post($step1));
check('YE2 C-E step 1 on 31.12.2025 — Dr 1000 1081.00 / Cr 3200 1000.00 without code / Cr 2209 81.00 — passes the lock (2209 is no VAT account) and the freeze of the posted 4th quarter (no tax line), and is posted',
    $step1Lock === 'pass' && $step1Ref === null && (int) $db->fetchOne("SELECT COUNT(*) FROM journal_line l JOIN account a ON a.id = l.account_id WHERE a.number = '2209'") === 1,
    $step1Lock . ' ' . ($step1Ref?->getMessage() ?? ''));
$db->executeStatement("UPDATE mandator SET account_vat_correction_pending = '' WHERE id = 1");
$noKey = [$transit()->account(), $amounts($transit(), '2025-12-31'), $findings('2025')];
$db->executeStatement("UPDATE mandator SET account_vat_correction_pending = '2209' WHERE id = 1");
check('YE3 the key not named on the mandator → no account, nothing read (0.00), no finding — although 2209 carries a balance; nothing is assumed',
    $noKey === [null, ['0.00', '0.00'], []], json_encode($noKey, JSON_UNESCAPED_UNICODE));
$open1 = $findings('2025');
check('YE4 step 1 without step 2: balance per 31.12.2025 −81.00, open −81.00 → ONE warning (never blocking), the owner\'s sentence with number and name from the chart, the amount with the account statement\'s sign, reference account:2209',
    $amounts($transit(), '2025-12-31') === ['-81.00', '-81.00'] && $texts($open1) === [$warning('−81.00')],
    json_encode($texts($open1), JSON_UNESCAPED_UNICODE));
check('YE4b balanceAt() is the year of the day: per 30.12.2025 0.00 (step 1 is dated 31.12.), per 31.12. −81.00; a day in no fiscal year → 0.00',
    $transit()->balanceAt(day('2025-12-30'))->toDecimal() === '0.00' && $transit()->balanceAt(day('2025-12-31'))->toDecimal() === '-81.00'
    && $transit()->balanceAt(day('2024-12-31'))->toDecimal() === '0.00');

// The year opening of 2026 carries −81.00 onto 2209 (the predecessor is still open: a provisional opening).
$em = $wire();
$opening = thrown(fn() => (new YearOpeningService($em, 'harness'))->create($yearId('2026')));
$openingOn2209 = (string) $db->fetchOne("SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id JOIN account a ON a.id = l.account_id WHERE a.number = '2209' AND e.source_type = 'opening'");
check('YE5 a later year opening does not hide the open amount and is not counted twice: the opening of 2026 carries −81.00 onto 2209; balance per 01.01.2026 −81.00 (its opening included), open after 31.12.2025 still −81.00 (not −162.00), the warning for 2025 unchanged',
    $opening === null && $openingOn2209 === '-81.00'
    && $transit()->balanceAt(day('2026-01-01'))->toDecimal() === '-81.00' && $amounts($transit(), '2025-12-31') === ['-81.00', '-81.00']
    && $texts($findings('2025')) === [$warning('−81.00')],
    ($opening?->getMessage() ?? '') . ' ' . $openingOn2209);

// A REAL close of 2025 through the service, asking a registry that holds the check.
$em      = $wire();
$closer  = static fn() => new FiscalYearCloseService(DI::getUnifiedEntityManager(), 'harness', new OpenWorkChecks(['period-close' => [VatCorrectionPendingCheck::class]]));
$year25  = $em->getRepository(FiscalYear::class)->find($yearId('2025'));
$asked   = $closer()->closeCheck($year25);
$refused = thrown(fn() => $closer()->close($yearId('2025')));
$em      = $wire();
$asked   = $closer()->closeCheck($em->getRepository(FiscalYear::class)->find($yearId('2025')));
$closed  = thrown(fn() => $closer()->close($yearId('2025'), FiscalYearCloseService::warningsFingerprint($asked)));
$log     = $db->fetchAssociative("SELECT action, confirmed_warnings FROM fiscal_year_close_log WHERE fiscal_year_code = '2025' ORDER BY id DESC LIMIT 1");
check('YE6 the close of 2025: the close check lists the warning, nothing blocks; without the tick the close is REFUSED as «warnings unconfirmed» (never «blocked»); with the tick it closes and the close protocol holds the sentence',
    !$asked->isBlocked() && array_map(static fn(Finding $f) => $f->message, $asked->warnings()) === [substr($warning('−81.00'), 8, -15)]
    && $refused instanceof FiscalYearCloseRefusedException && $refused->reason === FiscalYearCloseRefusedException::WARNINGS_UNCONFIRMED
    && $closed === null && $log !== false && $log['action'] === 'close' && $log['confirmed_warnings'] === substr($warning('−81.00'), 8, -15)
    && (int) $db->fetchOne("SELECT COUNT(*) FROM fiscal_period p WHERE p.fiscal_year_id = ? AND p.state <> 'closed'", [$yearId('2025')]) === 0,
    json_encode([$refused?->getMessage(), $closed?->getMessage(), $log], JSON_UNESCAPED_UNICODE));

// C-E step 2 in the running period (1st quarter 2026): the net WITH the code (z77's computed Cr 2200), the same net WITHOUT a code, Dr 2209.
$step2 = PostingRequest::manual(day('2026-02-15'), 'Korrektur Rechnung 2025-88', [
    cr('3200', '1000.00', 'UN', 810, '1000.00', '81.00'), cr('2200', '81.00'), dr('3200', '1000.00'), dr('2209', '81.00'),
]);
$step2Lock = $lock($step2);
$step2Ref  = thrown(fn() => $post($step2));
check('YE7 C-E step 2 dated 15.02.2026 — Cr 3200 1000.00 UN 8.1 % with the computed Cr 2200 81.00, Dr 3200 1000.00 without code, Dr 2209 81.00 — passes the lock and is posted',
    $step2Lock === 'pass' && $step2Ref === null, $step2Lock . ' ' . ($step2Ref?->getMessage() ?? ''));
check('YE8 step 2 booked after the year end silences the warning: open after 31.12.2025 0.00, no finding for 2025 — the balance per 31.12.2025 (−81.00) stays what the yearly overview shows',
    $amounts($transit(), '2025-12-31') === ['-81.00', '0.00'] && $findings('2025') === []);

// The input-tax mirror (X3): an expense of the old year posted uncoded on its last day, the input tax as a DEBIT on 2209.
$mirror     = PostingRequest::manual(day('2026-12-31'), 'Nachtrag Lieferantenrechnung (Vorsteuer über 2209)', [dr('4000', '100.00'), dr('2209', '8.10'), cr('1000', '108.10')]);
$mirrorLock = $lock($mirror);
$mirrorRef  = thrown(fn() => $post($mirror));
$open26     = $findings('2026');
check('YE9 the input-tax mirror: Dr 4000 100.00 / Dr 2209 8.10 / Cr 1000 108.10 on 31.12.2026 passes the lock; for 2026 the balance is the opening −81.00 + step 2 81.00 + 8.10 = 8.10 — the warning shows the DEBIT signed «8.10»',
    $mirrorLock === 'pass' && $mirrorRef === null && $amounts($transit(), '2026-12-31') === ['8.10', '8.10'] && $texts($open26) === [$warning('8.10')],
    json_encode([$mirrorLock, $mirrorRef?->getMessage(), $texts($open26)], JSON_UNESCAPED_UNICODE));

$em  = $wire();
$map = new VatAccountMap($em);
$locked = array_merge(array_keys($map->lockedAccounts(day('2025-12-31'))), array_keys($map->lockedAccounts(day('2026-02-15'))), array_keys($map->lockedAccounts(day('2026-12-31'))));
check('YE10 VatAccountMap: lockedAccounts() on every day of the pattern and accounts() list 2200 / 1170 / 1171 — never 2209 (and never 1179)',
    $locked !== [] && !in_array('2209', array_map('strval', $locked), true) && !in_array('1179', array_map('strval', $locked), true)
    && !array_key_exists('2209', $map->accounts()) && !in_array('vat-correction-pending', $map->accounts(), true)
    && array_diff(array_map('strval', array_keys($map->accounts())), ['2200', '1170', '1171']) === [],
    json_encode([$locked, $map->accounts()]));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
