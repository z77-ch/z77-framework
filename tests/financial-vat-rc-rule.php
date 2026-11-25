<?php

/**
 * Reverse-charge date rule harness (CLI) — `VatReturn\ReverseChargeTiming` and its answer
 * `VatReturn\ReverseChargeDate` (ADR-041 addendum 2026-11-25, point 10; addendum 2026-11-25 b,
 * point 3; ADR-059 amendment 2026-11-25 b). What is load-bearing here:
 *
 *   - (S, source — offline) the reverse-charge rule and the input-tax catch-up rule are TWO rules in
 *     two places (owner, 2026-11-25): the reverse-charge files name neither the input-tax method nor
 *     its answer nor the running-period method; `VatSettlement.php` and `InputTaxDeferral.php` name
 *     no `ReverseCharge`;
 *   - (R, rule — fixture returns in a throwaway schema, the settings passed in) the INVOICE date
 *     decides: an open period → the tax entry at the invoice date; a POSTED period of the same
 *     calendar year → refused, nothing caught up (where the input-tax rule WOULD catch up); a posted
 *     period of an earlier year → refused, the same sentence; before «Abrechnung ab» → before-start;
 *     not liable / no «Abrechnung ab» / no record / after «Abrechnung bis» → none; after a lift → the
 *     invoice date again. The
 *     refusal sentence is byte-equal to the owner's.
 *
 * Run: php tests/financial-vat-rc-rule.php
 * Section R needs the monorepo's vendor/ and a reachable MariaDB with the `z77test` credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_* — without them it is SKIPPED (S still runs).
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "financial-vat-rc-rule: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\VatReturn\ReverseChargeDate;
use Z77\Module\Financial\VatReturn\ReverseChargeTiming;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Module\Financial\VatReturn\VatSettlement;
use Z77\Module\Mandator\Entities\VatConsideration;
use Z77\Module\Mandator\Entities\VatMethod;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Shared\Money\Money;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

/** The owner's sentence (O-8) for one period, with the voucher noun — the expected text, typed out. */
function ownerSentence(string $period, string $voucher = 'die Rechnung'): string
{
    return "Die Bezugsteuer gehört in die Periode {$period}, sie ist abgerechnet — {$voucher} «ohne MWST» verbuchen und die Bezugsteuer als Korrekturbuchung zu {$period} erfassen.";
}

// ── S. source: two rules, not one ────────────────────────────────────────

echo "S. Source: the reverse-charge rule shares nothing with the input-tax catch-up rule\n";
$vatReturnDir = $root . '/packages/module-financial/src/VatReturn';
$rcFiles      = glob($vatReturnDir . '/ReverseCharge*.php') ?: [];
check('S1 the two files of the rule exist (ReverseChargeTiming.php, ReverseChargeDate.php)',
    in_array($vatReturnDir . '/ReverseChargeTiming.php', $rcFiles, true) && in_array($vatReturnDir . '/ReverseChargeDate.php', $rcFiles, true), implode(', ', array_map('basename', $rcFiles)));
$named = [];
foreach ($rcFiles as $file) {
    $source = (string) file_get_contents($file);
    foreach (['inputTaxDeferral', 'InputTaxDeferral', 'claimableFrom'] as $name) {
        if (str_contains($source, $name)) {
            $named[] = basename($file) . ' names ' . $name;
        }
    }
}
check('S2 ReverseCharge*.php name neither inputTaxDeferral nor InputTaxDeferral nor claimableFrom (no call, no wrap, no shared helper)', $rcFiles !== [] && $named === [], implode('; ', $named));
$inputSide = [];
foreach (['VatSettlement.php', 'InputTaxDeferral.php'] as $file) {
    if (str_contains((string) file_get_contents($vatReturnDir . '/' . $file), 'ReverseCharge')) {
        $inputSide[] = $file;
    }
}
check('S3 VatSettlement.php and InputTaxDeferral.php name no ReverseCharge', $inputSide === [], implode(', ', $inputSide));
$timing = (string) file_get_contents($vatReturnDir . '/ReverseChargeTiming.php');
check('S4 «posted» is read through VatSettlement::periodCovering() — the one reader of «settled», no date check of its own on the returns',
    str_contains($timing, '->periodCovering($invoiceDate)') && !str_contains($timing, 'VatReturnRepository') && !str_contains($timing, 'getRepository('));

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
    echo "R. Database — SKIPPED (no z77test password: %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD)\n";
    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}

echo "R. ReverseChargeTiming::forInvoiceDate() — the invoice date decides, a posted period is refused\n";

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-vat-rc-rule-' . getmypid());
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
check('R0 migrate exits 0 (vat, mandator, financial)', $code === 0, trim($out->fetch()));

$day      = static fn(string $d) => new \DateTimeImmutable($d);
$chf      = static fn(string $d) => Money::fromDecimal($d, 'CHF');
$settings = new VatSettings(true, VatMethod::Effective, VatConsideration::Agreed, VatRhythm::Quarter, $day('2025-10-01'), null);
$return   = static fn(string $from, string $to, int $revision = 1) => new VatReturn(VatPeriod::stored($day($from), $day($to), VatRhythm::Quarter), $revision, VatMethod::Effective,
    VatConsideration::Agreed, 'CH', 'CH 2024', hash('sha256', 'rules'), [], $chf('0.00'), $chf('0.00'), $chf('0.00'), null, 'harness', $day('2026-07-10 09:30:00'),
    hash('sha256', 'figures ' . $from . $revision), null, [], 'data/archive/financial/vat-return/' . $from . '-' . $revision . '.pdf', hash('sha256', 'pdf'), 'mwst-' . $from . '.pdf');
$persist = static function (array $returns) use (&$em): void {
    $em->getTransaction(VatReturn::class)->run(function () use ($em, $returns): void {
        foreach ($returns as $r) {
            $em->persist($r);
        }
        $em->flush();
    });
};

$em = $wire();
$a  = (new ReverseChargeTiming($em, $settings))->forInvoiceDate($day('2025-11-15 14:20:00'));
check('R1 no return posted: an invoice of 15.11.2025 → post-at, the tax entry dated AT the invoice date (midnight)',
    $a->isPostAt() && $a->kind === ReverseChargeDate::POST_AT && $a->taxDate?->format('Y-m-d H:i') === '2025-11-15 00:00' && $a->settledPeriod === null, $a->kind);

$persist([$return('2025-10-01', '2025-12-31'), $return('2026-01-01', '2026-03-31'), $return('2026-04-01', '2026-06-30')]);
$rc = new ReverseChargeTiming($em = $wire(), $settings);

$a = $rc->forInvoiceDate($day('2026-07-15'));
check('R2 Q4 2025 – Q2 2026 posted, invoice of 15.07.2026 (open Q3) → post-at 15.07.2026 — never moved', $a->isPostAt() && $a->taxDate?->format('Y-m-d') === '2026-07-15', $a->kind);

$a = $rc->forInvoiceDate($day('2026-05-20'));
check('R3 invoice of 20.05.2026 in the POSTED Q2, running period Q3 of the SAME year → refused, naming 2. Quartal 2026 (not caught up)',
    $a->isRefused() && $a->kind === ReverseChargeDate::REFUSED && $a->taxDate === null && $a->settledPeriod?->label() === '2. Quartal 2026', $a->kind);
check('R4 the sentence is byte-equal to the owner\'s (O-8) with «die Rechnung»',
    $a->isRefused() && $a->refusalText() === ownerSentence('2. Quartal 2026')
    && $a->refusalText() === 'Die Bezugsteuer gehört in die Periode 2. Quartal 2026, sie ist abgerechnet — die Rechnung «ohne MWST» verbuchen und die Bezugsteuer als Korrekturbuchung zu 2. Quartal 2026 erfassen.',
    $a->isRefused() ? $a->refusalText() : $a->kind);
check('R5 the voucher noun for a receipt: refusalText(\'den Beleg\')', $a->isRefused() && $a->refusalText('den Beleg') === ownerSentence('2. Quartal 2026', 'den Beleg'));
$input = (new VatSettlement($em, $settings))->inputTaxDeferral($day('2026-05-20'));
check('R6 the two rules apart on the same day: the INPUT-tax rule catches 20.05.2026 up (through 1179), the reverse-charge rule refuses it',
    $input->isCatchUp() && $a->isRefused(), $input->kind . ' / ' . $a->kind);

$a = $rc->forInvoiceDate($day('2025-11-10'));
check('R7 invoice of 10.11.2025 in the posted Q4 of an EARLIER year → refused, the same sentence, naming 4. Quartal 2025',
    $a->isRefused() && $a->settledPeriod?->label() === '4. Quartal 2025' && $a->refusalText() === ownerSentence('4. Quartal 2025'), $a->isRefused() ? $a->refusalText() : $a->kind);

$a = $rc->forInvoiceDate($day('2025-09-30'));
check('R8 invoice of 30.09.2025, the day before «Abrechnung ab» (01.10.2025) → before-start (the caller\'s «ohne MWST» sentence)',
    $a->isBeforeStart() && $a->kind === ReverseChargeDate::BEFORE_START && $a->taxDate === null && $a->settledPeriod === null, $a->kind);
$a = $rc->forInvoiceDate($day('2025-10-01'));
check('R9 invoice ON «Abrechnung ab» (01.10.2025, posted Q4 2025) → refused, not before-start', $a->isRefused() && $a->settledPeriod?->label() === '4. Quartal 2025', $a->kind);

$a = (new ReverseChargeTiming($em, new VatSettings(false, null, null, null, null, null)))->forInvoiceDate($day('2026-05-20'));
check('R10 a mandator not liable → none (the ordinary rules decide)', $a->isNone() && $a->kind === ReverseChargeDate::NONE, $a->kind);
$a = (new ReverseChargeTiming($em, new VatSettings(true, VatMethod::Effective, VatConsideration::Agreed, VatRhythm::Quarter, null, null)))->forInvoiceDate($day('2026-05-20'));
check('R11 liable without «Abrechnung ab» (VAT settled outside z77) → none', $a->isNone(), $a->kind);
$a = (new ReverseChargeTiming($em))->forInvoiceDate($day('2026-05-20'));
check('R12 no settings passed and no mandator record → read through LedgerService::vatSettings() (nothing set) → none', $a->isNone(), $a->kind);

$db->executeStatement("UPDATE vat_return SET live = NULL, state = 'lifted' WHERE period_from = '2026-04-01'");
$a = (new ReverseChargeTiming($em = $wire(), $settings))->forInvoiceDate($day('2026-05-20'));
check('R13 after Q2 is lifted: the invoice of 20.05.2026 → post-at 20.05.2026 again', $a->isPostAt() && $a->taxDate?->format('Y-m-d') === '2026-05-20', $a->kind);

$threw = null;
try { ReverseChargeDate::postAt($day('2026-05-20'))->refusalText(); } catch (\LogicException $x) { $threw = $x; }
check('R14 refusalText() of an answer that is not a refusal is a programming error (LogicException)', $threw !== null);

// «Abrechnung bis» 30.09.2026: after the end of the liability z77 settles nothing — the rule does not apply.
$ended = new ReverseChargeTiming($em = $wire(), new VatSettings(true, VatMethod::Effective, VatConsideration::Agreed, VatRhythm::Quarter, $day('2025-10-01'), $day('2026-09-30')));
$after = $ended->forInvoiceDate($day('2026-10-01'));
$last  = $ended->forInvoiceDate($day('2026-09-30'));
$still = $ended->forInvoiceDate($day('2026-02-10'));
check('R15 «Abrechnung bis» 30.09.2026: an invoice of 01.10.2026 → none (never post-at — no return would carry it); ON the last day 30.09.2026 (open Q3) → post-at; 10.02.2026 in the posted Q1 → still refused',
    $after->isNone() && $after->taxDate === null && $last->isPostAt() && $last->taxDate?->format('Y-m-d') === '2026-09-30' && $still->isRefused() && $still->settledPeriod?->label() === '1. Quartal 2026',
    $after->kind . ' / ' . $last->kind . ' / ' . $still->kind);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
