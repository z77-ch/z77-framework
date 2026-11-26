<?php

/**
 * financial-vat-categories harness (CLI) — the VAT return with the categories of stages S4 / S6
 * (ADR-041 addendum 2026-11-25, points 3, 10; ADR-042 addendum 2 of 2026-11-25, point 7): the
 * three input-tax corrections and reverse charge, through the real ledger, the real return and
 * the real «MWST buchen» — plus the one table both the return and the lock read, `VatAccountMap`.
 *
 *   M. `VatAccountMap` over the real mandator record: the tax lines and signs per category (owed
 *      −1, input +1, 410 +1 / 415 / 420 −1 on `vat-input-other`, reverse charge owed −1 AND the
 *      input account its code names +1, zero / exempt none), the predicate «a coded line may sit
 *      on a VAT account» (only the input-side account its code maps to), `lockedAccounts()` (before
 *      «Abrechnung ab», after «Abrechnung bis», not liable, unreadable → empty), `accounts()`;
 *      `LedgerService::vatAccountFor()` for the new categories, the key `vat-input-pending`.
 *   C. The corrections through the ledger (Q3 2026): VE as a tax-only line ON 1171 (debit), VK and
 *      VN as tax-only lines ON 1171 (credit) — 410 adds, 415 / 420 subtract; 1171 reconciles with
 *      the right sign; the settlement entry clears 1171 by the net expectation; posted.
 *   B. Reverse charge through the ledger: case 8 (BI 1 234.55, posted tax 100.00 — one entry of the
 *      ADR shape, Q1 2026) and case 8b (BI 300.15, the pair alone at the invoice date: the coded
 *      line ON 1171 against 2200 — S56-12, Q2 2026): 383 = 405 exact, payable 0.00, settlement
 *      Dr 2200 24.30 / Cr 1171 24.30, 6945 untouched, the tree «Herkunft» = the form; the warning
 *      «Bezugsteuer mit vollem Vorsteuerabzug gerechnet»; a reduced-rate pair (BM2 → 400 / 1170).
 *
 * Every name and amount here is synthetic.
 *
 * Run: php tests/financial-vat-categories.php (until registered: then php tests/run.php financial-vat-categories)
 * Needs the monorepo's vendor/ and a reachable MariaDB with the `z77test` credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*; without them the harness reports SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "financial-vat-categories: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(1);
}
require $autoload;

use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Services\FiscalYearService;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\ManualEntryService;
use Z77\Module\Financial\Services\VatAccountMap;
use Z77\Module\Financial\VatReturn\VatAccountReconciliation;
use Z77\Module\Financial\VatReturn\VatReturnBreakdown;
use Z77\Module\Financial\VatReturn\VatReturnPostingService;
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Module\Financial\VatReturn\VatReturnSources;
use Z77\Module\Mandator\Entities\VatConsideration;
use Z77\Module\Mandator\Entities\VatMethod;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Module\Vat\Services\UnknownTaxCodeException;
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

function day(string $ymd): \DateTimeImmutable
{
    return new \DateTimeImmutable($ymd);
}

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
    echo "M. Database — SKIPPED (no z77test password: %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD)\n";
    echo "\n{$pass} passed, {$fail} failed\n";
    exit(0);
}

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-vat-categories-' . getmypid());
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
    'Financial' => $root . '/packages/module-financial',
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
// The CH seed as the installer deploys it — VE / VK / VN, BM / BI / BM2 / BI2 and UL among it since 2026-11-25.
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

    return $previous = \Z77\Core\DI::getUnifiedEntityManager();
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
check('S1 migrate exits 0 (vat, mandator, financial)', $code === 0, trim($out->fetch()));

$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->adoptKmuChart();
$em = $wire();
(new FiscalYearService($em))->open(new FiscalYear('2026', day('2026-01-01'), day('2026-12-31')));
$em = $wire();
$mandator = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$mandator->mapFromArray([
    'name' => 'Harness AG', 'country' => 'CH', 'zip' => '8000', 'city' => 'Zürich', 'liable_to_vat' => true, 'uid' => 'CHE-116.281.710',
    'vat_method' => 'effective', 'vat_consideration' => 'agreed', 'vat_settlement_rhythm' => 'quarter', 'vat_settlement_from' => '2026-01-01',
]);
(new \Z77\Module\Mandator\Services\MandatorService($em))->save($mandator);

$declared = [];
foreach (['module-financial' => $packages['Financial'] . '/src/App/Config/financialConfig.inc.php'] as $package => $configFile) {
    $declared[VatReturnSources::CONFIG_KEY . " of {$package}"] = (require $configFile)[VatReturnSources::CONFIG_KEY] ?? [];
}
$sources  = new VatReturnSources($declared);
$settings = static fn(?string $from = '2026-01-01', bool $liable = true, ?string $until = null): VatSettings
    => new VatSettings($liable, VatMethod::Effective, VatConsideration::Agreed, VatRhythm::Quarter, $from === null ? null : day($from), $until === null ? null : day($until));
$service  = static fn(): VatReturnService => new VatReturnService($wire(), null, new OpenWorkChecks([]), $sources);
$poster   = static fn(): VatReturnPostingService => new VatReturnPostingService($wire(), 'harness', new OpenWorkChecks([]), null, $sources);
$manual   = static fn(string $date, string $text, array $lines) => (new ManualEntryService($wire(), 'harness'))->create(PostingRequest::manual(day($date), $text, $lines));
$q        = static fn(VatReturnService $svc, string $d) => $svc->periodOn(day($d));
$vatOf    = static fn(array $accounts): array => array_map(static fn(VatAccountReconciliation $a) => "{$a->role}:{$a->accountNumber}:" . implode('+', $a->fields) . ":{$a->expected->toDecimal()}:{$a->movement->toDecimal()}", $accounts);
$linesOf  = static fn(string $from): array => array_map(static fn(array $r) => $r['number'] . ' ' . ((float) $r['debit'] > 0 ? 'D ' . $r['debit'] : 'C ' . $r['credit']), $db->fetchAllAssociative(
    "SELECT a.number, l.debit, l.credit FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id JOIN account a ON a.id = l.account_id
      WHERE e.source_type = 'vat-return' AND e.entry_date = LAST_DAY(?) ORDER BY l.position", [$from]));
$moved    = static fn(string $number, string $from, string $to): string => (string) $db->fetchOne(
    'SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id JOIN account a ON a.id = l.account_id
      WHERE a.number = ? AND e.entry_date BETWEEN ? AND ?', [$number, $from, $to]);
$adds = static function (VatReturnBreakdown $node) use (&$adds): bool {
    if ($node->children === []) {
        return true;
    }
    $sum = array_reduce($node->children, static fn(Money $s, VatReturnBreakdown $c) => $s->add($c->amount), Money::zero($node->amount->currency));

    return $sum->equals($node->amount) && array_reduce($node->children, static fn(bool $ok, VatReturnBreakdown $c) => $ok && $adds($c), true);
};
/** «MWST buchen» of the period of $day: the plan, then the post with the plan's fingerprints (warnings confirmed). */
$postReturn = static function (string $d) use ($poster, $q, $service) {
    $period = $q($service(), $d);
    $plan   = $poster()->plan($period, day('2026-12-01'));
    $poster()->post($period, $plan->figuresFingerprint, $plan->warningsFingerprint, day('2026-12-01'));

    return $plan;
};

// ─────────────────────────────────────────────────────────────────────────
echo "M. VatAccountMap — the one table of signs, the predicate, the lock window\n";

$map = new VatAccountMap($wire());
$tl  = static fn(string $code, string $d = '2026-06-01') => array_map(static fn(array $l) => "{$l['key']}:{$l['account']}:{$l['sign']}", $map->taxLines($code, day($d)));
check('M1 output codes (UN, UR, US): owed −1 on 2200; input codes: VM +1 on 1170, VI +1 on 1171',
    $tl('UN') === ['vat-owed:2200:-1'] && $tl('UR') === ['vat-owed:2200:-1'] && $tl('US') === ['vat-owed:2200:-1']
    && $tl('VM') === ['vat-input-material:1170:1'] && $tl('VI') === ['vat-input-other:1171:1'], json_encode([$tl('UN'), $tl('VM'), $tl('VI')]));
check('M2 the corrections always on vat-input-other (S4-8): VE (410) +1, VK (415) −1, VN (420) −1',
    $tl('VE') === ['vat-input-other:1171:1'] && $tl('VK') === ['vat-input-other:1171:-1'] && $tl('VN') === ['vat-input-other:1171:-1']);
check('M3 reverse charge: owed −1 AND the input account its code names +1 — BM / BM2 → 1170, BI / BI2 → 1171 (the input field of the rule file, at the rate date)',
    $tl('BM') === ['vat-owed:2200:-1', 'vat-input-material:1170:1'] && $tl('BM2') === ['vat-owed:2200:-1', 'vat-input-material:1170:1']
    && $tl('BI') === ['vat-owed:2200:-1', 'vat-input-other:1171:1'] && $tl('BI2') === ['vat-owed:2200:-1', 'vat-input-other:1171:1'], json_encode([$tl('BM'), $tl('BI2')]));
check('M4 zero-rated, exempt, abroad: no tax line; an unknown code is refused loudly; reverse charge on a day no form edition covers is refused (no guess)',
    $tl('UE') === [] && $tl('UA') === [] && $tl('UL') === [] && thrown(fn() => $map->taxLines('QQ', day('2026-06-01'))) instanceof UnknownTaxCodeException
    && thrown(fn() => $map->taxLines('BM', day('2023-06-01'))) instanceof \Z77\Module\Vat\CountryPack\FormMappingException && $tl('VM', '2023-06-01') === ['vat-input-material:1170:1']);
$may = static fn(string $code, string $account) => $map->mayCarryCode($code, $account, day('2026-06-01'));
check('M5 the predicate: a coded line may stand on a VAT account only when it is the input-side account its code maps to — VM on 1170, VI / VE / VK / VN on 1171, BM on 1170, BI on 1171',
    $may('VM', '1170') && $may('VI', '1171') && $may('VE', '1171') && $may('VK', '1171') && $may('VN', '1171') && $may('BM', '1170') && $may('BI', '1171'));
check('M6 … never an output, zero or exempt code on a VAT account, an input code on the owed or on the other input account, reverse charge on the owed account or on the wrong input account, any code on 2201 or an empty account',
    !$may('UN', '2200') && !$may('UN', '1170') && !$may('UE', '2200') && !$may('UA', '1171') && !$may('VM', '1171') && !$may('VM', '2200')
    && !$may('VK', '1170') && !$may('BM', '2200') && !$may('BM', '1171') && !$may('BI', '1170') && !$may('VI', '2201') && !$may('VI', ''));
$lockedOn = static fn(VatSettings $s, string $d) => (new VatAccountMap($wire(), null, $s))->lockedAccounts(day($d));
$three    = ['2200' => 'vat-owed', '1170' => 'vat-input-material', '1171' => 'vat-input-other'];
$asStrings = static fn(array $a): array => array_combine(array_map('strval', array_keys($a)), array_values($a));
check('M7 lockedAccounts(): the three VAT accounts from «Abrechnung ab» on (the day itself included), never 2201 or 1179',
    $asStrings($lockedOn($settings('2026-04-01'), '2026-04-01')) == $three && $asStrings($lockedOn($settings(), '2026-12-31')) == $three
    && !isset($lockedOn($settings(), '2026-06-01')['2201']) && !isset($lockedOn($settings(), '2026-06-01')['1179']), json_encode($lockedOn($settings(), '2026-06-01')));
check('M8 … empty before «Abrechnung ab», after «Abrechnung bis» (the day itself still locked), for a mandator not liable, without «Abrechnung ab»',
    $lockedOn($settings('2026-04-01'), '2026-03-31') === [] && $lockedOn($settings('2026-01-01', true, '2026-06-30'), '2026-07-01') === []
    && $asStrings($lockedOn($settings('2026-01-01', true, '2026-06-30'), '2026-06-30')) == $three
    && $lockedOn($settings('2026-01-01', false), '2026-06-01') === [] && $lockedOn($settings(null), '2026-06-01') === []);
check('M9 … read from the real mandator record (no settings passed): «Abrechnung ab» 1.1.2026 → locked on 1.6.2026, not on 31.12.2025; accounts() names the three without a date',
    $asStrings((new VatAccountMap($wire()))->lockedAccounts(day('2026-06-01'))) == $three && (new VatAccountMap($wire()))->lockedAccounts(day('2025-12-31')) === []
    && $asStrings((new VatAccountMap($wire()))->accounts()) == $three);
$ledger = new LedgerService($wire());
check('M10 LedgerService: vatAccountFor() names 1171 for the three corrections, none for reverse charge (two lines — the map); vatAccountKey() is the one table; vat-input-pending is a VAT-return key',
    $ledger->vatAccountFor('input-subsequent') === '1171' && $ledger->vatAccountFor('input-correction') === '1171' && $ledger->vatAccountFor('input-reduction') === '1171'
    && $ledger->vatAccountFor('reverse-charge') === null && LedgerService::vatAccountKey('input-reduction') === 'vat-input-other'
    && LedgerService::VAT_INPUT_PENDING_ACCOUNT === 'vat-input-pending'
    && !(thrown(fn() => $ledger->vatReturnAccount('vat-input-pending')) instanceof \InvalidArgumentException && str_contains((string) thrown(fn() => $ledger->vatReturnAccount('vat-input-pending'))?->getMessage(), 'Not a VAT-return account key'))
    && thrown(fn() => $ledger->vatReturnAccount('vat-owed')) instanceof \InvalidArgumentException);
$guards = (string) file_get_contents($packages['Financial'] . '/src/Services/VatAccountMap.php') . (string) file_get_contents($packages['Financial'] . '/src/VatReturn/VatAccountExpectation.php');
check('M11 the map and the expectation read accounts only through LedgerService (no CurrentMandator, no ->account() — module-mandator H4); the return names no second category → account table',
    !str_contains($guards, 'CurrentMandator') && !preg_match('/->account\(/', $guards)
    && !preg_match("/'vat-input-(material|other)'|'vat-owed'/", (string) file_get_contents($packages['Financial'] . '/src/VatReturn/VatReturnService.php')));

// ─────────────────────────────────────────────────────────────────────────
echo "B. Reverse charge through the ledger (cases 8 and 8b)\n";

// Case 8, Q1 2026: the single entry of the ADR shape (invoice and payment on one day).
$manual('2026-02-10', 'Software-Lizenz aus dem Ausland', [
    PostingLine::debit('6500', chf('1234.55'), null, 'BI', 810, chf('1234.55'), chf('100.00')),
    PostingLine::debit('1171', chf('100.00')),
    PostingLine::credit('1020', chf('1234.55')),
    PostingLine::credit('2200', chf('100.00')),
]);
// Case 8b, Q2 2026: the pair alone at the invoice date — the coded line ON 1171 against 2200 (S56-12); the expense
// follows at the payment date without a code.
$manual('2026-05-15', 'Bezugsteuer Beratung aus dem Ausland', [
    PostingLine::debit('1171', chf('24.30'), null, 'BI', 810, chf('300.15'), chf('24.30')),
    PostingLine::credit('2200', chf('24.30')),
]);
$manual('2026-06-20', 'Beratung aus dem Ausland, bezahlt', [PostingLine::debit('6500', chf('300.15')), PostingLine::credit('1020', chf('300.15'))]);

$svc = $service();
$p8  = $svc->preview($q($svc, '2026-02-01'));
$r8  = $svc->reconciliation($q($svc, '2026-02-01'));
check('B1 case 8: 383 base 1 234.55, tax 99.998550 (base × 8.1 %, exact); 405 the same 99.998550; 399 = 479; payable 0.00, plausible, no rounding difference',
    $p8->form->base('383')->equals(chf('1234.55')) && $p8->form->value('383')->toDecimal() === '99.998550' && $p8->form->value('405')->toDecimal() === '99.998550'
    && $p8->form->value('399')->equals($p8->form->value('479')) && $p8->payable()->isZero() && $p8->form->isPlausible() && $p8->form->roundingDifference()->isZero(),
    $p8->form->value('405')->toDecimal());
check('B1b case 8: «gebucht» beside field 399 counts the reverse-charge tax as the field itself does — 100.00 posted, no difference shown for a period whose only owed tax is reverse charge',
    $p8->postedTaxOwed()->equals(chf('100.00')) && $p8->form->value('399')->toDecimal() === '99.998550',
    (string) $p8->postedTaxOwed()->toDecimal());
check('B2 case 8: the tree «Herkunft» IS the form — 383 the base 1 234.55, 405 the declared share rounded 100.00, every level adds up',
    $p8->breakdownOf('383')?->amount->equals(chf('1234.55')) && $p8->breakdownOf('405')?->amount->equals(chf('100.00'))
    && $adds($p8->breakdownOf('383')) && $adds($p8->breakdownOf('405')));
check('B3 case 8: 2200 and 1171 reconcile 100.00 each (the posted tax, field 383 on the owed account, 405 on the input account); 1170, named on the mandator, shows with 0.00',
    $vatOf($r8->vatAccounts) === ['owed:2200:383:100.00:100.00', 'input:1170::0.00:0.00', 'input:1171:405:100.00:100.00'] && $r8->vatAccountsAgree(), json_encode($vatOf($r8->vatAccounts)));
$f8 = array_map(static fn($f) => ($f->blocking ? 'B:' : 'W:') . $f->code, $svc->findings($q($svc, '2026-02-01')));
check('B4 the warning «Bezugsteuer … mit vollem Vorsteuerabzug gerechnet» (partial deduction is not built), linked to field 383; nothing blocks',
    in_array('W:' . VatReturnService::FINDING_REVERSE_CHARGE_FULL_DEDUCTION, $f8, true) && array_filter($f8, static fn($c) => str_starts_with($c, 'B:')) === []
    && str_contains(array_values(array_filter($svc->findings($q($svc, '2026-02-01')), static fn($f) => $f->code === VatReturnService::FINDING_REVERSE_CHARGE_FULL_DEDUCTION))[0]->message, 'mit vollem Vorsteuerabzug gerechnet'),
    json_encode($f8));
$plan8 = $postReturn('2026-02-01');
check('B5 case 8 posted: the settlement entry Dr 2200 100.00 / Cr 1171 100.00 — payable 0.00, no line on 2201 or 6945',
    $linesOf('2026-03-01') === ['2200 D 100.00', '1171 C 100.00'] && $plan8->payable()->isZero(), json_encode($linesOf('2026-03-01')));

$svc  = $service();
$p8b  = $svc->preview($q($svc, '2026-05-01'));
$r8b  = $svc->reconciliation($q($svc, '2026-05-01'));
check('B6 case 8b (the pair alone, the coded line ON 1171): 383 = 405 = 24.312150 exact, payable 0.00; the tree 405 = 24.31; 2200 and 1171 reconcile 24.30 (the line\'s own amount is the movement, its tax the expectation)',
    $p8b->form->value('383')->toDecimal() === '24.312150' && $p8b->form->value('405')->toDecimal() === '24.312150' && $p8b->payable()->isZero()
    && $p8b->breakdownOf('405')?->amount->equals(chf('24.31')) && $vatOf($r8b->vatAccounts) === ['owed:2200:383:24.30:24.30', 'input:1170::0.00:0.00', 'input:1171:405:24.30:24.30'] && $r8b->vatAccountsAgree(),
    json_encode($vatOf($r8b->vatAccounts)));
$postReturn('2026-05-01');
check('B7 case 8b posted: Dr 2200 24.30 / Cr 1171 24.30; 6945 unmoved over Q2; 2200 and 1171 at 0.00 over the half year',
    $linesOf('2026-06-01') === ['2200 D 24.30', '1171 C 24.30'] && $moved('6945', '2026-04-01', '2026-06-30') === '0.00'
    && $moved('2200', '2026-01-01', '2026-06-30') === '0.00' && $moved('1171', '2026-01-01', '2026-06-30') === '0.00', json_encode($linesOf('2026-06-01')));

// ─────────────────────────────────────────────────────────────────────────
echo "C. The input-tax corrections through the ledger (Q3 2026)\n";

$manual('2026-07-10', 'Verkauf', [PostingLine::debit('1020', chf('1081.00')), PostingLine::credit('3200', chf('1000.00'), null, 'UN', 810, chf('1000.00'), chf('81.00')), PostingLine::credit('2200', chf('81.00'))]);
$manual('2026-07-12', 'Service Fahrzeug', [PostingLine::debit('6200', chf('200.00'), null, 'VI', 810, chf('200.00'), chf('16.20')), PostingLine::debit('1171', chf('16.20')), PostingLine::credit('1020', chf('216.20'))]);
$manual('2026-07-20', 'Einlageentsteuerung Maschine', [PostingLine::debit('1171', chf('30.00'), null, 'VE', 0, chf('0.00'), chf('30.00')), PostingLine::credit('1500', chf('30.00'))]);
$manual('2026-08-31', 'Privatanteil Fahrzeug', [PostingLine::debit('6200', chf('12.00')), PostingLine::credit('1171', chf('12.00'), null, 'VK', 0, chf('0.00'), chf('12.00'))]);
$manual('2026-09-15', 'Vorsteuerkürzung Subvention', [PostingLine::debit('6500', chf('5.00')), PostingLine::credit('1171', chf('5.00'), null, 'VN', 0, chf('0.00'), chf('5.00'))]);
// A reduced-rate reverse charge in the same quarter: BM2 → 383 (one line for every rate), deducted in 400 on 1170.
$manual('2026-09-20', 'Bezugsteuer Fachbücher Ausland', [PostingLine::debit('1170', chf('2.60'), null, 'BM2', 260, chf('100.00'), chf('2.60')), PostingLine::credit('2200', chf('2.60'))]);

$svc = $service();
$p3  = $svc->preview($q($svc, '2026-08-01'));
$r3  = $svc->reconciliation($q($svc, '2026-08-01'));
check('C1 the form: 410 (VE) 30.00 adds, 415 (VK) 12.00 and 420 (VN) 5.00 subtract — 479 = 2.60 + 16.20 + 30.00 − 12.00 − 5.00 = 31.80; 383 the BM2 pair 2.60 (one line for every rate); payable 81.00 + 2.60 − 31.80 = 51.80',
    $p3->form->value('410')->toDecimal() === '30.000000' && $p3->form->value('415')->toDecimal() === '12.000000' && $p3->form->value('420')->toDecimal() === '5.000000'
    && $p3->form->value('400')->toDecimal() === '2.600000' && $p3->form->value('383')->toDecimal() === '2.600000' && $p3->form->base('383')->equals(chf('100.00'))
    && $p3->form->value('479')->toDecimal() === '31.800000' && $p3->payable()->equals(chf('51.80')) && $p3->form->isPlausible(),
    json_encode(array_map(static fn($v) => $v->toDecimal(), $p3->form->values())));
check('C2 the tree «Herkunft» of 410 / 415 / 420 is the posted tax on 1171; 400 the BM2 share on 1170',
    $p3->breakdownOf('410')?->amount->equals(chf('30.00')) && $p3->breakdownOf('415')?->amount->equals(chf('12.00')) && $p3->breakdownOf('420')?->amount->equals(chf('5.00'))
    && $p3->breakdownOf('400')?->amount->equals(chf('2.60')) && $p3->breakdownOf('415')?->child('manual')?->child('1171') !== null);
check('C3 the VAT accounts reconcile WITH THE RIGHT SIGN: 2200 83.60 (303 + 383), 1170 2.60 (400), 1171 29.20 = 16.20 + 30.00 − 12.00 − 5.00 (405, 410, 415, 420)',
    $vatOf($r3->vatAccounts) === ['owed:2200:303+383:83.60:83.60', 'input:1170:400:2.60:2.60', 'input:1171:405+410+415+420:29.20:29.20'] && $r3->vatAccountsAgree(),
    json_encode($vatOf($r3->vatAccounts)));
$plan3 = $postReturn('2026-08-01');
check('C4 «MWST buchen»: Dr 2200 83.60, Cr 1170 2.60, Cr 1171 29.20, Cr 2201 51.80 — no rounding line; afterwards 2200, 1170 and 1171 at 0.00 over the year',
    $linesOf('2026-09-01') === ['2200 D 83.60', '1170 C 2.60', '1171 C 29.20', '2201 C 51.80'] && $plan3->roundingDifference()->isZero()
    && $moved('2200', '2026-01-01', '2026-09-30') === '0.00' && $moved('1170', '2026-01-01', '2026-09-30') === '0.00' && $moved('1171', '2026-01-01', '2026-09-30') === '0.00',
    json_encode($linesOf('2026-09-01')));
check('C5 the writer locks the corrections\' account with the others (one list — VatReturnService::vatAccountCategories() carries the three)',
    array_slice(array_map(static fn($c) => $c->value, VatReturnService::vatAccountCategories()), -3) === ['input-subsequent', 'input-correction', 'input-reduction']);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
