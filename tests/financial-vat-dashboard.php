<?php

/**
 * VAT return dashboard harness (CLI) — the one line for the VAT return that is due on financial's
 * tile (owner decision S7-3 of 2026-11-25, `Dashboard\FinancialDashboard`):
 *
 *   O. (offline) the target of the line is the return page of the mount `VatReturnLayout::BASE`;
 *      the dashboard reads «due» from `VatReturnService` only (no calendar, no settlement of its own);
 *   M. (database) a throwaway schema: vat, mandator, financial migrated; fiscal years 2026 and 2027
 *      open (their own dashboard lines stay silent on the days asked);
 *   D. the line on FIXED days: no mandator / not liable / a setting missing / a method z77 does not
 *      build → no line; a quarter not yet ended → none; ended → todo «MWST-Abrechnung 1. Quartal 2026
 *      fällig bis 30.05.2026» → the return page of the period; the due day itself still todo, the day
 *      after URGENT; the OLDEST ended unsettled period is named; a posted return settles its period,
 *      a lifted one does not; month and year rhythm; «Abrechnung bis» with a short last period; the
 *      VAT line beside the fiscal-year line; a service passed by the caller.
 *
 * Posted returns are rows written by raw SQL (this harness reads «settled», it posts nothing — the
 * writer is `tests/financial-vat-return-posting.php`'s). Every name and amount here is synthetic.
 *
 * Run: php tests/run.php financial-vat-dashboard (or php tests/financial-vat-dashboard.php)
 * O needs the monorepo's vendor/; M–D a reachable MariaDB and the `z77test` credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*; without them M is reported SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "financial-vat-dashboard: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Z77\Module\Financial\Dashboard\FinancialDashboard;
use Z77\Module\Financial\Ui\VatReturnLayout;
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Module\Mandator\Entities\VatConsideration;
use Z77\Module\Mandator\Entities\VatMethod;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks;
use Z77\Shared\Dashboard\DashboardEntry;
use Z77\Shared\Dashboard\Severity;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

function day(string $ymd): \DateTimeImmutable
{
    return new \DateTimeImmutable($ymd);
}

/** «todo|MWST-Abrechnung … |/backend/…» per entry — what a check compares. */
function linesOf(array $entries): array
{
    return array_map(static fn(DashboardEntry $e): string => $e->severity?->value . '|' . $e->label . '|' . $e->href(), $entries);
}

// ─────────────────────────────────────────────────────────────────────────
echo "O. Target and source (offline)\n";

$target = (new \ReflectionClassConstant(FinancialDashboard::class, 'VAT_RETURN_TARGET'))->getValue();
check('O1 the line opens the return page of the mount: `VatReturnLayout::BASE` + `/form` (where «Neue Abrechnung» leads)',
    '/' . implode('/', $target) === VatReturnLayout::BASE . '/form', json_encode($target));
$source = (string) file_get_contents($root . '/packages/module-financial/src/Dashboard/FinancialDashboard.php');
check('O2 «due» is read from the return\'s own answers (settingsRefusal, nextReturn) — no calendar, no settlement, no mandator read of its own',
    str_contains($source, '->settingsRefusal()') && str_contains($source, '->nextReturn(')
    && !preg_match('/VatPeriodCalendar|VatSettlement|CurrentMandator|VatReturnRepository/', $source));

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

echo "M. Database (throwaway schema)\n";

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-vat-dashboard-' . getmypid());
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

/** A fresh request: new DI, new UnifiedEntityManager, empty identity map (the mandator record is read anew). */
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
$migrateOut = new \Symfony\Component\Console\Output\BufferedOutput();
$code       = $app->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'migrate', '--no-interaction' => true]), $migrateOut);
check('M1 migrate exits 0 (vat, mandator, financial)', $code === 0, trim($migrateOut->fetch()));
if ($code !== 0) {
    echo "\n{$pass} passed, {$fail} failed\n";
    exit(1);
}

foreach (['2026', '2027'] as $year) {
    $db->insert('fiscal_year', ['code' => $year, 'start_date' => "{$year}-01-01", 'end_date' => "{$year}-12-31"]);
    $db->insert('fiscal_period', ['start_date' => "{$year}-01-01", 'end_date' => "{$year}-12-31", 'state' => 'open', 'fiscal_year_id' => (int) $db->lastInsertId()]);
}

/** The entries of financial's tile on $ymd, on a fresh request (the record and the returns read anew). */
$on = static function (string $ymd) use ($wire): array {
    $wire();

    return linesOf(FinancialDashboard::entriesOn(day($ymd)));
};
/** The mandator's VAT columns, written as the record stores them. */
$vat = static function (array $columns) use ($db): void {
    $db->executeStatement('UPDATE mandator SET ' . implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($columns))), array_values($columns));
};
/** A stored return of $from..$to — live (posted) or lifted. */
$stored  = static function (string $from, string $to, string $rhythm = 'quarter', bool $live = true, int $revision = 1) use ($db): void {
    $row = [
        'period_from' => $from, 'period_to' => $to, 'revision' => $revision, 'state' => $live ? 'posted' : 'lifted', 'live' => $live ? 1 : null,
        'rhythm' => $rhythm, 'method' => 'effective', 'consideration' => 'agreed', 'country' => 'CH', 'form_edition' => 'ESTV 0535, edition 01.24',
        'mapping_hash' => str_repeat('0', 64), 'mapping_snapshot' => '{}', 'currency' => 'CHF', 'payable' => '0.00', 'posted_tax' => '0.00',
        'rounding_difference' => '0.00', 'posted_by' => 'harness', 'posted_at' => '2026-01-01 00:00:00', 'figures_fingerprint' => str_repeat('0', 64),
        'voucher_path' => 'harness.pdf', 'voucher_sha256' => str_repeat('0', 64), 'voucher_name' => 'harness.pdf',
    ];
    if (!$live) {
        $row += ['lifted_by' => 'harness', 'lifted_at' => '2026-01-02 00:00:00', 'lift_reason' => 'Harness'];
    }
    $db->insert('vat_return', $row);
};
$page = static fn(string $from): string => VatReturnLayout::BASE . '/form?from=' . $from;

check('M2 fiscal years 2026 and 2027 open, no mandator saved: financial\'s tile says nothing on a day in 2026', $on('2026-10-05') === [], json_encode($on('2026-10-05'), JSON_UNESCAPED_UNICODE));

// ─────────────────────────────────────────────────────────────────────────
echo "D. The due line on fixed days\n";

(new \Z77\Module\Financial\Services\AccountService($wire()))->adoptKmuChart();   // the record names the chart's accounts
$record = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$record->mapFromArray(['name' => 'Harness AG', 'country' => 'CH', 'zip' => '8000', 'city' => 'Zürich', 'liable_to_vat' => true, 'uid' => 'CHE-116.281.710']);
(new \Z77\Module\Mandator\Services\MandatorService($wire()))->save($record);
check('D1 liable, nothing of the VAT return set (no method, no «Abrechnung ab»): no line — the return\'s gate refuses, z77 does not settle', $on('2026-10-05') === []);
$vat(['vat_method' => 'effective', 'vat_consideration' => 'agreed', 'vat_settlement_rhythm' => 'quarter']);
check('D2 everything but «Abrechnung ab»: no line (a liable mandator settling outside z77)', $on('2026-10-05') === []);
$vat(['vat_settlement_from' => '2026-01-01']);
check('D3 quarter from 1.1.2026 — on 31.03.2026 no period has ended: no line', $on('2026-03-31') === [], json_encode($on('2026-03-31'), JSON_UNESCAPED_UNICODE));
check('D4 on 01.04.2026: todo «MWST-Abrechnung 1. Quartal 2026 fällig bis 30.05.2026» (60 days after 31.03.) → the return page of Q1',
    $on('2026-04-01') === ['todo|MWST-Abrechnung 1. Quartal 2026 fällig bis 30.05.2026|' . $page('2026-01-01')], json_encode($on('2026-04-01'), JSON_UNESCAPED_UNICODE));
check('D5 on the due day 30.05.2026 still todo; on 31.05.2026 URGENT, same words',
    $on('2026-05-30') === ['todo|MWST-Abrechnung 1. Quartal 2026 fällig bis 30.05.2026|' . $page('2026-01-01')]
    && $on('2026-05-31') === ['urgent|MWST-Abrechnung 1. Quartal 2026 fällig bis 30.05.2026|' . $page('2026-01-01')], json_encode($on('2026-05-31'), JSON_UNESCAPED_UNICODE));
check('D6 on 05.10.2026 with Q1–Q3 unsettled: ONE line, the OLDEST period (Q1, urgent) — no line per period',
    $on('2026-10-05') === ['urgent|MWST-Abrechnung 1. Quartal 2026 fällig bis 30.05.2026|' . $page('2026-01-01')], json_encode($on('2026-10-05'), JSON_UNESCAPED_UNICODE));

$stored('2026-01-01', '2026-03-31');
$stored('2026-04-01', '2026-06-30');
check('D7 Q1 and Q2 posted: on 05.10.2026 todo «3. Quartal 2026 fällig bis 29.11.2026» (the owner\'s example) → the return page of Q3',
    $on('2026-10-05') === ['todo|MWST-Abrechnung 3. Quartal 2026 fällig bis 29.11.2026|' . $page('2026-07-01')], json_encode($on('2026-10-05'), JSON_UNESCAPED_UNICODE));
check('D8 Q3: 29.11.2026 todo, 30.11.2026 URGENT',
    $on('2026-11-29') === ['todo|MWST-Abrechnung 3. Quartal 2026 fällig bis 29.11.2026|' . $page('2026-07-01')]
    && $on('2026-11-30') === ['urgent|MWST-Abrechnung 3. Quartal 2026 fällig bis 29.11.2026|' . $page('2026-07-01')]);
$stored('2026-07-01', '2026-09-30', live: false);
check('D9 a LIFTED return of Q3 settles nothing: the Q3 line stays', $on('2026-11-30') === ['urgent|MWST-Abrechnung 3. Quartal 2026 fällig bis 29.11.2026|' . $page('2026-07-01')]);
$stored('2026-07-01', '2026-09-30', revision: 2);
check('D10 Q3 posted (revision 2): no line until Q4 has ended; on 01.01.2027 todo «4. Quartal 2026 fällig bis 01.03.2027»',
    $on('2026-11-30') === [] && $on('2026-12-31') === []
    && $on('2027-01-01') === ['todo|MWST-Abrechnung 4. Quartal 2026 fällig bis 01.03.2027|' . $page('2026-10-01')], json_encode($on('2027-01-01'), JSON_UNESCAPED_UNICODE));

$db->executeStatement("DELETE FROM fiscal_period WHERE fiscal_year_id = (SELECT id FROM fiscal_year WHERE code = '2027')");
$db->executeStatement("DELETE FROM fiscal_year WHERE code = '2027'");
check('D11 beside the fiscal-year lines, without a year 2027: on 15.12.2026 only «Geschäftsjahr ab 01.01.2027 nicht eröffnet» (Q4 has not ended) — on 05.02.2027 «Kein offenes Geschäftsjahr» (urgent) AND the Q4 todo, in that order',
    $on('2026-12-15') === ['todo|Geschäftsjahr ab 01.01.2027 nicht eröffnet|/backend/finance/fiscal-year/list']
    && $on('2027-02-05') === ['urgent|Kein offenes Geschäftsjahr für heute|/backend/finance/fiscal-year/list', 'todo|MWST-Abrechnung 4. Quartal 2026 fällig bis 01.03.2027|' . $page('2026-10-01')],
    json_encode($on('2027-02-05'), JSON_UNESCAPED_UNICODE));
$db->insert('fiscal_year', ['code' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
$db->insert('fiscal_period', ['start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'state' => 'open', 'fiscal_year_id' => (int) $db->lastInsertId()]);

$vat(['vat_method' => 'net-tax-rate']);
check('D12 a method z77 does not build (Saldosteuersatz): no line', $on('2027-01-05') === [], json_encode($on('2027-01-05'), JSON_UNESCAPED_UNICODE));
$vat(['vat_method' => 'effective', 'liable_to_vat' => 0]);
check('D13 not liable and no «Abrechnung bis»: no line', $on('2027-01-05') === []);
$vat(['vat_settlement_until' => '2026-11-15']);
check('D14 no longer liable, «Abrechnung bis» 15.11.2026: the short last period 01.10.–15.11. is due 14.01.2027 — todo on 05.01., urgent on 15.01.; no line after it is posted',
    $on('2027-01-05') === ['todo|MWST-Abrechnung 4. Quartal 2026 fällig bis 14.01.2027|' . $page('2026-10-01')]
    && $on('2027-01-15') === ['urgent|MWST-Abrechnung 4. Quartal 2026 fällig bis 14.01.2027|' . $page('2026-10-01')],
    json_encode($on('2027-01-05'), JSON_UNESCAPED_UNICODE));
$stored('2026-10-01', '2026-11-15');
check('D14a … the short period posted: no line any more, never one after «Abrechnung bis»', $on('2027-01-15') === [] && $on('2027-06-01') === []);

$db->executeStatement('DELETE FROM vat_return');
$vat(['liable_to_vat' => 1, 'vat_settlement_until' => null, 'vat_settlement_rhythm' => 'month']);
check('D15 month rhythm: on 01.02.2026 todo «Januar 2026 fällig bis 01.04.2026»; on 02.04.2026 urgent, still January (the oldest)',
    $on('2026-02-01') === ['todo|MWST-Abrechnung Januar 2026 fällig bis 01.04.2026|' . $page('2026-01-01')]
    && $on('2026-04-02') === ['urgent|MWST-Abrechnung Januar 2026 fällig bis 01.04.2026|' . $page('2026-01-01')], json_encode($on('2026-02-01'), JSON_UNESCAPED_UNICODE));
$vat(['vat_settlement_rhythm' => 'year']);
check('D16 year rhythm: nothing in 2026; on 01.01.2027 todo «Jahr 2026 fällig bis 01.03.2027»',
    $on('2026-12-31') === [] && $on('2027-01-01') === ['todo|MWST-Abrechnung Jahr 2026 fällig bis 01.03.2027|' . $page('2026-01-01')], json_encode($on('2027-01-01'), JSON_UNESCAPED_UNICODE));

$wire();
$given = new VatReturnService(\Z77\Core\DI::getUnifiedEntityManager(), new VatSettings(
    liable: true, method: VatMethod::Effective, consideration: VatConsideration::Agreed, rhythm: VatRhythm::Quarter, from: day('2026-07-01'), until: null,
), new OpenWorkChecks([]));
$entries = FinancialDashboard::entriesOn(day('2026-10-05'), $given);
check('D17 the caller\'s service is the one asked (a harness passes its own settings): quarter from 1.7.2026 → Q3 todo, a pending entry with severity todo',
    linesOf($entries) === ['todo|MWST-Abrechnung 3. Quartal 2026 fällig bis 29.11.2026|' . $page('2026-07-01')]
    && $entries[0]->isPending() && $entries[0]->severity === Severity::Todo && $entries[0]->query === ['from' => '2026-07-01'],
    json_encode(linesOf($entries), JSON_UNESCAPED_UNICODE));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
