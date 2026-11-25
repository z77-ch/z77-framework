<?php

/**
 * GrossEntryBuilder harness (CLI) — financial's public builder of the posting lines from GROSS
 * amounts (creditor plan `docs/03-development/creditor-module-bauplan.md` §2.5 / §9 stage 1,
 * RECEIPT-BUILD-001). What is load-bearing here:
 *
 *   - (G, golden) the builder took over the VAT split of the one-line entry: module-receipt's
 *     `ReceiptPostingBuilder` and financial's `OneLineEntryForm` produce EXACTLY the lines (and,
 *     for the form, the field errors and the VAT hint) they produced BEFORE the extraction. The
 *     expected values below were recorded from the pre-extraction code (2026-11-15) — never
 *     regenerate them from the current code, that would prove nothing;
 *   - (B, builder) several gross parts against one counter account: one counter line with the
 *     total, per part the net (with the tax data) and the tax line on the mandator's account;
 *     the rounding per part; sums balanced; the entry posts through `LedgerService::post()`;
 *     the strict mode for a mandator NOT liable to VAT refuses every tax code; a foreign
 *     currency, a non-positive amount, a bad account, a reverse-charge code (without the caller's
 *     opt-in) are refused;
 *   - (T, tax-only) «nur Steuer» (ADR-041 addendum 2026-11-25, point 6): case 9 — the forwarder
 *     invoice with three kinds of line — base 0.00, the code's rate, the account derived; refusals;
 *   - (RD, rate date) the rate of the RATE date, across 01.01.2024: 107.70 VM → 7.70 (point 7);
 *   - (RC, reverse charge) case 8 in the single-entry shape (base positive, the two tax lines from
 *     `VatAccountMap`), a tax base of its own, BM2; the split pair alone / expense without code;
 *   - (SP, split) case 10 B — «Vorsteuer nachholen» through the transit account 1179: the expense
 *     entry and the tax entry, posted, 1179 at 0.00 afterwards; refusals;
 *   - (L, lock) a VAT account the lock guards on the entry's date is refused as Soll and as Haben;
 *   - (D, input-tax deferral) `VatSettlement::claimableFrom()` / `::inputTaxDeferral()` — open,
 *     settled in the same year, settled in another year, before «Abrechnung ab», not liable, after
 *     «Abrechnung bis», after a lift; the rule is for INPUT tax only (point 9) — the reverse-charge
 *     date rule (point 10) is a separate one and not answered there.
 *
 * Run: php tests/financial-gross-entry.php
 * Needs the monorepo's vendor/ and a reachable MariaDB with the `z77test` credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_* — without them the harness is SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "financial-gross-entry: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(1);
}
require $autoload;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\Ledger\GrossPart;
use Z77\Module\Financial\Ledger\GrossPartKind;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Services\GrossEntryBuilder;
use Z77\Module\Financial\Services\GrossEntryRefusedException;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\VatAccountMap;
use Z77\Module\Financial\Ui\OneLineEntryForm;
use Z77\Module\Financial\VatReturn\InputTaxDeferral;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Module\Financial\VatReturn\VatSettlement;
use Z77\Module\Mandator\Entities\VatConsideration;
use Z77\Module\Mandator\Entities\VatMethod;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Module\Receipt\Services\ReceiptPostingBuilder;
use Z77\Module\Receipt\Services\ReceiptRefusedException;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Services\VatRates;
use Z77\Shared\Money\Money;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

function chf(string $decimal): Money
{
    return Money::fromDecimal($decimal, 'CHF');
}

/** Lines as «account D|C amount [code rate base tax] [| text]» — the shape compared. */
function shape(array $lines): array
{
    return array_map(static fn(PostingLine $l) => $l->account . ' ' . ($l->debit->isPositive() ? 'D ' . $l->debit->toDecimal() : 'C ' . $l->credit->toDecimal())
        . ($l->hasTax() ? " {$l->taxCode} {$l->taxRate} {$l->taxBase->toDecimal()} {$l->taxAmount->toDecimal()}" : '')
        . ($l->text !== null ? ' | ' . $l->text : ''), $lines);
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
    echo "financial-gross-entry — SKIPPED (no z77test password: %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD)\n";
    exit(0);
}

echo "Database (throwaway schema)\n";

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-gross-entry-' . getmypid());
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
// The shipped codes and rates, plus a reverse-charge code (BZ) and an INACTIVE input code (VX).
$codes = json_decode((string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_codes.default.json'), true);
$codes[] = ['id' => 101, 'code' => 'BZ', 'country' => 'CH', 'category' => 'reverse-charge', 'label' => 'Bezugsteuer', 'active' => true];
$codes[] = ['id' => 102, 'code' => 'VX', 'country' => 'CH', 'category' => 'input-other', 'label' => 'Vorsteuer alt', 'active' => false];
$rates = json_decode((string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_rates.default.json'), true);
$rates[] = ['id' => 101, 'code' => 'BZ', 'valid_from' => '2024-01-01', 'rate' => 810];
$rates[] = ['id' => 102, 'code' => 'VX', 'valid_from' => '2024-01-01', 'rate' => 810];
$write('data/framework/vat/tax_codes.json', json_encode($codes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$write('data/framework/vat/tax_rates.json', json_encode($rates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

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
check('D1 migrate exits 0 (vat, mandator, financial)', $code === 0, trim($out->fetch()));

// The books: the KMU chart, the mandator (VAT accounts, liable), the fiscal year 2026; 6570 inactive.
$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->adoptKmuChart();
$mandator = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$mandator->mapFromArray(['name' => 'Harness AG', 'country' => 'CH', 'zip' => '8000', 'city' => 'Zürich', 'liable_to_vat' => true, 'uid' => 'CHE-116.281.710']);   // a liable mandator needs its UID (2026-11-24)
(new \Z77\Module\Mandator\Services\MandatorService($em))->save($mandator);
(new \Z77\Module\Financial\Services\FiscalYearService($em))->open(new \Z77\Module\Financial\Entities\FiscalYear('2026', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));
$db->executeStatement("UPDATE account SET active = 0 WHERE number = '6570'");
$em = $wire();

// ── G. golden: receipt and the one-line entry post what they posted before ──

echo "G. Golden: the lines module-receipt and the one-line entry produced BEFORE the extraction\n";

/** [date, text, gross, debit, credit, tax code] — what `ReceiptPostingBuilder::lines()` takes. */
$receiptCases = [
    'vi'          => ['2026-11-03', 'Tanken', '108.10', '6200', '1000', 'VI'],
    'vm'          => ['2026-11-04', 'Kugelschreiber', '12.40', '6500', '1000', 'VM'],
    'none'        => ['2026-11-02', 'Miete', '1000.00', '6200', '1020', null],
    'r005'        => ['2026-11-05', 'Rappen', '0.05', '6500', '1000', 'VM'],
    'r007'        => ['2026-11-05', 'Rappen', '0.07', '6500', '1000', 'VM'],
    'r100'        => ['2026-11-05', 'Franken', '1.00', '6500', '1000', 'VM'],
    'r9999'       => ['2026-11-05', 'Fast hundert', '99.99', '6500', '1000', 'VM'],
    'rbig'        => ['2026-11-05', 'Gross', '123456.78', '6500', '1020', 'VI'],
    'rate77'      => ['2023-12-31', 'Alter Satz', '400.00', '6500', '1020', 'VM'],
    'zero'        => ['2026-11-05', 'Export', '50.00', '6500', '1000', 'UE'],
    'exempt'      => ['2026-11-05', 'Befreit', '50.00', '6500', '1000', 'UA'],
    'asset'       => ['2026-11-05', 'Maschine', '5000.00', '1500', '1020', 'VI'],
    'assetOutput' => ['2026-11-05', 'Sonderfall', '200.00', '1500', '1020', 'UN'],
    'refund'      => ['2026-11-05', 'Rückvergütung', '64.80', '1020', '4200', 'VM'],
    'outputCode'  => ['2026-11-05', 'Verkauf bar', '21.60', '1000', '3200', 'UN'],
    'reduced'     => ['2026-11-05', 'Buch', '25.90', '6500', '1000', 'UR'],
    'bothPl'      => ['2026-11-05', 'Unklar', '10.00', '6500', '4200', 'VM'],
    'bothPlNoVat' => ['2026-11-05', 'Umbuchung', '10.00', '6500', '4200', null],
    'reverse'     => ['2026-11-05', 'Bezug', '10.00', '6500', '1020', 'BZ'],
    'inactiveTax' => ['2026-11-05', 'Alt', '10.00', '6500', '1020', 'VX'],
    'unknownTax'  => ['2026-11-05', 'Weg', '10.00', '6500', '1020', 'QQ'],
    'noRate'      => ['2017-06-30', 'Zu alt', '10.00', '6500', '1020', 'VM'],
    'inactiveAcc' => ['2026-11-05', 'IT', '10.00', '6570', '1020', 'VI'],
    'groupAcc'    => ['2026-11-05', 'Gruppe', '10.00', '6500', '100', null],
    'unknownAcc'  => ['2026-11-05', 'Fehlt', '10.00', '9999', '1020', 'VM'],
    'sameAcc'     => ['2026-11-05', 'Selbst', '10.00', '1020', '1020', null],
];
$receiptResult = function (array $case): array {
    [$date, , $gross, $debit, $credit, $taxCode] = $case;
    try {
        $r = (new ReceiptPostingBuilder(\Z77\Core\DI::getUnifiedEntityManager()))->lines(new \DateTimeImmutable($date), chf($gross), $debit, $credit, $taxCode);
        return ['lines' => shape($r)];
    } catch (ReceiptRefusedException $e) {
        return ['refused' => $e->reason . ': ' . $e->getMessage()];
    }
};

/** One-line form posts — [debit, credit, amount, date, code, tax amount]. */
$formCases = [
    'plain'      => ['6500', '1020', "1'250.50", '2026-11-15', '', ''],
    'vm'         => ['6500', '1020', '400.00', '2026-11-15', 'VM', ''],
    'output'     => ['1020', '3200', '400.00', '2026-11-16', 'UN', ''],
    'voucher'    => ['6500', '1020', '400.00', '2026-11-15', 'VM', '30.00'],
    'edge'       => ['6500', '1020', '400.00', '2026-11-15', 'VM', '29.67'],
    'tooFar'     => ['6500', '1020', '400.00', '2026-11-15', 'VM', '31.00'],
    'zero'       => ['1020', '3200', '400.00', '2026-11-17', 'UE', ''],
    'zeroTax0'   => ['1020', '3200', '400.00', '2026-11-17', 'UE', '0.00'],
    'exemptTax'  => ['1020', '3200', '400.00', '2026-11-17', 'UA', '0.50'],
    'tax0'       => ['6500', '1020', '400.00', '2026-11-15', 'VM', '0'],
    'taxBad'     => ['6500', '1020', '400.00', '2026-11-15', 'VM', 'abc'],
    'taxNeg'     => ['6500', '1020', '400.00', '2026-11-15', 'VM', '-1.00'],
    'tinyTax0'   => ['6500', '1020', '0.05', '2026-11-15', 'VM', '0.00'],
    'taxGross'   => ['6500', '1020', '0.05', '2026-11-15', 'VM', '0.05'],
    'bothPl'     => ['6500', '4200', '10.00', '2026-11-15', 'VM', ''],
    'reverse'    => ['6500', '1020', '10.00', '2026-11-15', 'BZ', ''],
    'inactive'   => ['6500', '1020', '10.00', '2026-11-15', 'VX', ''],
    'noRate'     => ['6500', '1020', '400.00', '2017-06-30', 'VM', ''],
    'asset'      => ['1500', '1020', '5000.00', '2026-11-15', 'VI', ''],
    'assetOut'   => ['1500', '1020', '200.00', '2026-11-15', 'UN', ''],
    'noCode'     => ['6500', '1020', '10.00', '2026-11-15', '-', ''],
    'badFields'  => ['1020', '1020 Bankguthaben', '-5', '2026-11-15', '-', ''],
    'groupBoth'  => ['100', '9999', '5.00', '2026-11-15', '', ''],
];
$formResult = function (array $case): array {
    [$debit, $credit, $amount, $date, $code, $tax] = $case;
    $em   = \Z77\Core\DI::getUnifiedEntityManager();
    $form = new OneLineEntryForm('CHF', $em->getRepository(Account::class), $em->getRepository(TaxCode::class), VatRates::from($em), new LedgerService($em));
    $post = ['debit' => $debit, 'date' => $date, 'text' => 'Beleg', 'credit' => $credit, 'amount' => $amount]
        + ($code !== '' ? ['vat' => '1', 'tax_code' => $code === '-' ? '' : $code, 'tax_amount' => $tax] : ['tax_code' => '', 'tax_amount' => '']);
    $request = $form->fromPost($post)->toRequest();
    $errors  = [];
    foreach (['date', 'text', 'debit', 'credit', 'amount', 'tax_code', 'tax_amount'] as $field) {
        if ($form->error($field) !== '') {
            $errors[$field] = $form->error($field);
        }
    }

    return ['lines' => $request === null ? null : shape($request->lines), 'errors' => $errors, 'general' => $form->generalErrors(), 'hint' => $form->vatHint()];
};

/** Recorded from the PRE-extraction code (2026-11-15, `ReceiptPostingBuilder` driving `OneLineEntryForm`) — do not regenerate. */
$golden = [
    'receipt' => [
        'vi' => ['lines' => ['6200 D 100.00 VI 810 100.00 8.10', '1171 D 8.10', '1000 C 108.10']],
        'vm' => ['lines' => ['6500 D 11.47 VM 810 11.47 0.93', '1170 D 0.93', '1000 C 12.40']],
        'none' => ['lines' => ['6200 D 1000.00', '1020 C 1000.00']],
        'r005' => ['lines' => ['6500 D 0.05 VM 810 0.05 0.00', '1000 C 0.05']],
        'r007' => ['lines' => ['6500 D 0.06 VM 810 0.06 0.01', '1170 D 0.01', '1000 C 0.07']],
        'r100' => ['lines' => ['6500 D 0.93 VM 810 0.93 0.07', '1170 D 0.07', '1000 C 1.00']],
        'r9999' => ['lines' => ['6500 D 92.50 VM 810 92.50 7.49', '1170 D 7.49', '1000 C 99.99']],
        'rbig' => ['lines' => ['6500 D 114206.09 VI 810 114206.09 9250.69', '1171 D 9250.69', '1020 C 123456.78']],
        'rate77' => ['lines' => ['6500 D 371.40 VM 770 371.40 28.60', '1170 D 28.60', '1020 C 400.00']],
        'zero' => ['lines' => ['6500 D 50.00 UE 0 -50.00 0.00', '1000 C 50.00']],
        'exempt' => ['lines' => ['6500 D 50.00 UA 0 -50.00 0.00', '1000 C 50.00']],
        'asset' => ['lines' => ['1500 D 4625.35 VI 810 4625.35 374.65', '1171 D 374.65', '1020 C 5000.00']],
        'assetOutput' => ['lines' => ['1500 D 200.00', '1020 C 185.01 UN 810 185.01 14.99', '2200 C 14.99']],
        'refund' => ['lines' => ['1020 D 64.80', '4200 C 59.94 VM 810 -59.94 -4.86', '1170 C 4.86']],
        'outputCode' => ['lines' => ['1000 D 21.60', '3200 C 19.98 UN 810 19.98 1.62', '2200 C 1.62']],
        'reduced' => ['lines' => ['6500 D 25.24 UR 260 -25.24 -0.66', '2200 D 0.66', '1000 C 25.90']],
        'bothPl' => ['refused' => 'not-postable: Soll und Haben sind beides Aufwand- oder Ertragskonten — welche Seite netto ist, ist nicht eindeutig. Als Sammelbuchung erfassen.'],
        'bothPlNoVat' => ['lines' => ['6500 D 10.00', '4200 C 10.00']],
        'reverse' => ['refused' => 'not-postable: MWST: Bezugsteuer (BZ) wird auf beiden Seiten gebucht — als Sammelbuchung erfassen.'],
        'inactiveTax' => ['refused' => 'not-postable: MWST: MWST-Code VX ist inaktiv — nur bestehende Buchungen behalten ihn.'],
        'unknownTax' => ['refused' => 'not-postable: MWST: MWST-Code QQ gibt es nicht.'],
        'noRate' => ['refused' => 'not-postable: MWST: Für VM gilt am 30.06.2017 kein Satz.'],
        'inactiveAcc' => ['refused' => 'not-postable: Soll: «6570 Informatikaufwand» ist inaktiv.'],
        'groupAcc' => ['refused' => 'not-postable: Haben: «100 Flüssige Mittel» ist eine Gruppe — nicht bebuchbar.'],
        'unknownAcc' => ['refused' => 'not-postable: Soll: Konto 9999 gibt es nicht.'],
        'sameAcc' => ['refused' => 'not-postable: Haben: Soll und Haben sind dasselbe Konto.'],
    ],
    'form' => [
        'plain' => ['lines' => ['6500 D 1250.50', '1020 C 1250.50'], 'errors' => [], 'general' => [], 'hint' => ''],
        'vm' => ['lines' => ['6500 D 370.03 VM 810 370.03 29.97', '1170 D 29.97', '1020 C 400.00'], 'errors' => [], 'general' => [], 'hint' => 'MWST VM 8.1 % in 400.00: 29.97 (berechnet) — netto 370.03 im Soll, Steuerzeile auf 1170'],
        'output' => ['lines' => ['1020 D 400.00', '3200 C 370.03 UN 810 370.03 29.97', '2200 C 29.97'], 'errors' => [], 'general' => [], 'hint' => 'MWST UN 8.1 % in 400.00: 29.97 (berechnet) — netto 370.03 im Haben, Steuerzeile auf 2200'],
        'voucher' => ['lines' => ['6500 D 370.00 VM 810 370.00 30.00', '1170 D 30.00', '1020 C 400.00'], 'errors' => [], 'general' => [], 'hint' => 'MWST VM 8.1 % in 400.00: 30.00 (laut Beleg, berechnet 29.97) — netto 370.00 im Soll, Steuerzeile auf 1170'],
        'edge' => ['lines' => ['6500 D 370.33 VM 810 370.33 29.67', '1170 D 29.67', '1020 C 400.00'], 'errors' => [], 'general' => [], 'hint' => 'MWST VM 8.1 % in 400.00: 29.67 (laut Beleg, berechnet 29.97) — netto 370.33 im Soll, Steuerzeile auf 1170'],
        'tooFar' => ['lines' => null, 'errors' => ['tax_amount' => 'Steuerbetrag 31.00 weicht um 1.03 vom berechneten 29.97 ab — erlaubt sind höchstens 0.30 (Rundung laut Beleg). Andere Steuer als Sammelbuchung erfassen.'], 'general' => [], 'hint' => ''],
        'zero' => ['lines' => ['1020 D 400.00', '3200 C 400.00 UE 0 400.00 0.00'], 'errors' => [], 'general' => [], 'hint' => 'MWST UE 0 % in 400.00: 0.00 (berechnet) — netto 400.00 im Haben, keine Steuerzeile'],
        'zeroTax0' => ['lines' => ['1020 D 400.00', '3200 C 400.00 UE 0 400.00 0.00'], 'errors' => [], 'general' => [], 'hint' => 'MWST UE 0 % in 400.00: 0.00 (berechnet) — netto 400.00 im Haben, keine Steuerzeile'],
        'exemptTax' => ['lines' => null, 'errors' => ['tax_amount' => 'MWST-Code UA ist steuerfrei — kein Steuerbetrag.'], 'general' => [], 'hint' => ''],
        'tax0' => ['lines' => null, 'errors' => ['tax_amount' => 'Steuer 0.00 geht nur, wenn die berechnete Steuer 0.00 ist (hier 29.97) — sonst die MwSt-Zeile ausschalten.'], 'general' => [], 'hint' => ''],
        'taxBad' => ['lines' => null, 'errors' => ['tax_amount' => 'Kein Steuerbetrag (z.B. 29.97) — leer lassen, um ihn zu berechnen.'], 'general' => [], 'hint' => ''],
        'taxNeg' => ['lines' => null, 'errors' => ['tax_amount' => 'Kein Steuerbetrag (z.B. 29.97) — leer lassen, um ihn zu berechnen.'], 'general' => [], 'hint' => ''],
        'tinyTax0' => ['lines' => ['6500 D 0.05 VM 810 0.05 0.00', '1020 C 0.05'], 'errors' => [], 'general' => [], 'hint' => 'MWST VM 8.1 % in 0.05: 0.00 (berechnet) — netto 0.05 im Soll, keine Steuerzeile'],
        'taxGross' => ['lines' => null, 'errors' => ['tax_amount' => 'Die Steuer muss kleiner als der Betrag sein.'], 'general' => [], 'hint' => ''],
        'bothPl' => ['lines' => null, 'errors' => [], 'general' => ['Soll und Haben sind beides Aufwand- oder Ertragskonten — welche Seite netto ist, ist nicht eindeutig. Als Sammelbuchung erfassen.'], 'hint' => ''],
        // The ONE deliberate change since the recording: the decided wording of the S4 picker round (2026-11-25) names the two other places.
        'reverse' => ['lines' => null, 'errors' => ['tax_code' => 'Bezugsteuer (BZ) wird auf beiden Seiten gebucht — als Sammelbuchung, Beleg oder Kreditorenrechnung erfassen.'], 'general' => [], 'hint' => ''],
        'inactive' => ['lines' => null, 'errors' => ['tax_code' => 'MWST-Code VX ist inaktiv — nur bestehende Buchungen behalten ihn.'], 'general' => [], 'hint' => ''],
        'noRate' => ['lines' => null, 'errors' => ['tax_code' => 'Für VM gilt am 30.06.2017 kein Satz.'], 'general' => [], 'hint' => ''],
        'asset' => ['lines' => ['1500 D 4625.35 VI 810 4625.35 374.65', '1171 D 374.65', '1020 C 5000.00'], 'errors' => [], 'general' => [], 'hint' => 'MWST VI 8.1 % in 5\'000.00: 374.65 (berechnet) — netto 4\'625.35 im Soll, Steuerzeile auf 1171'],
        'assetOut' => ['lines' => ['1500 D 200.00', '1020 C 185.01 UN 810 185.01 14.99', '2200 C 14.99'], 'errors' => [], 'general' => [], 'hint' => 'MWST UN 8.1 % in 200.00: 14.99 (berechnet) — netto 185.01 im Haben, Steuerzeile auf 2200'],
        'noCode' => ['lines' => null, 'errors' => ['tax_code' => 'MWST-Code wählen — oder die MwSt-Zeile ausschalten.'], 'general' => [], 'hint' => ''],
        'badFields' => ['lines' => null, 'errors' => ['credit' => 'Soll und Haben sind dasselbe Konto.', 'amount' => 'Der Betrag muss grösser als 0 sein — für die Gegenrichtung Soll und Haben tauschen.', 'tax_code' => 'MWST-Code wählen — oder die MwSt-Zeile ausschalten.'], 'general' => [], 'hint' => ''],
        'groupBoth' => ['lines' => null, 'errors' => ['debit' => '«100 Flüssige Mittel» ist eine Gruppe — nicht bebuchbar.', 'credit' => 'Konto 9999 gibt es nicht.'], 'general' => [], 'hint' => ''],
    ],
];
$em = $wire();
foreach ($receiptCases as $name => $case) {
    $got = $receiptResult($case);
    check("G-receipt {$name}: " . implode(' ', array_map(static fn($v) => $v ?? 'ohne MWST', array_slice($case, 2))) . ' — as before', $got === $golden['receipt'][$name], json_encode($got, JSON_UNESCAPED_UNICODE));
}
foreach ($formCases as $name => $case) {
    $got = $formResult($case);
    check("G-form {$name}: " . implode(' ', array_filter($case, static fn($v) => $v !== '')) . ' — lines, field errors and hint as before', $got === $golden['form'][$name], json_encode($got, JSON_UNESCAPED_UNICODE));
}
$receiptSource = (string) file_get_contents($root . '/packages/module-receipt/src/Services/ReceiptPostingBuilder.php');
check('G1 module-receipt builds through GrossEntryBuilder and no longer drives the UI form OneLineEntryForm (RECEIPT-BUILD-001)',
    str_contains($receiptSource, 'GrossEntryBuilder::of(') && !str_contains($receiptSource, 'OneLineEntryForm'));

// ── B. the builder: several parts, rounding, strict liability, refusals, posting ──

echo "B. GrossEntryBuilder: several gross parts against one counter account\n";
$builder = GrossEntryBuilder::of($em = $wire());
$refusal = static function (callable $fn): ?GrossEntryRefusedException {
    try { $fn(); } catch (GrossEntryRefusedException $e) { return $e; }
    return null;
};
$sum = static function (array $lines, string $side): Money {
    $total = Money::zero('CHF');
    foreach ($lines as $l) { $total = $total->add($l->{$side}); }
    return $total;
};
$on = new \DateTimeImmutable('2026-11-10');

$invoice = [
    new GrossPart('6200', chf('108.10'), 'VI', 'Benzin'),
    new GrossPart('6500', chf('12.40'), 'VM', 'Papier'),
    new GrossPart('4200', chf('54.00'), null, 'Ware'),
];
$entry = $builder->build($on, '1020', $invoice);
check('B1 three parts → per part the net with the tax data (and its text) and the tax line on the mandator account, then ONE counter line with the gross total',
    shape($entry->lines) === ['6200 D 100.00 VI 810 100.00 8.10 | Benzin', '1171 D 8.10', '6500 D 11.47 VM 810 11.47 0.93 | Papier', '1170 D 0.93', '4200 D 54.00 | Ware', '1020 C 174.50']
    && $entry->splits[0]?->tax->toDecimal() === '8.10' && $entry->splits[1]?->account === '1170' && $entry->splits[2] === null, json_encode(shape($entry->lines)));
check('B2 balanced: Σ Soll = Σ Haben = the gross total 174.50', $sum($entry->lines, 'debit')->equals($sum($entry->lines, 'credit')) && $sum($entry->lines, 'credit')->toDecimal() === '174.50');
$rappen = $builder->build($on, '1000', [new GrossPart('6500', chf('0.07'), 'VM'), new GrossPart('6500', chf('0.07'), 'VM')]);
check('B3 rounding PER PART (each invoice line as on the voucher): 2 × 0.07 VM → 2 × 0.01 tax; balanced',
    shape($rappen->lines) === ['6500 D 0.06 VM 810 0.06 0.01', '1170 D 0.01', '6500 D 0.06 VM 810 0.06 0.01', '1170 D 0.01', '1000 C 0.14']
    && $sum($rappen->lines, 'debit')->equals($sum($rappen->lines, 'credit')), json_encode(shape($rappen->lines)));
$mixed = $builder->build($on, '1020', [new GrossPart('6500', chf('10.80'), 'VM'), new GrossPart('1500', chf('200.00'), 'UN')]);
check('B4 a part whose net falls on the counter side (asset 1500, output code) keeps the one-line layout; the counter line carries only the other parts; balanced',
    shape($mixed->lines) === ['6500 D 9.99 VM 810 9.99 0.81', '1170 D 0.81', '1500 D 200.00', '1020 C 185.01 UN 810 185.01 14.99', '2200 C 14.99', '1020 C 10.80']
    && $sum($mixed->lines, 'debit')->equals($sum($mixed->lines, 'credit')), json_encode(shape($mixed->lines)));
$e = $refusal(fn() => $builder->build($on, '1020', $invoice, false));
check('B5 strict mode (mandator NOT liable to VAT): a part with a tax code is refused — TAX_CODE, the first such part, a German sentence naming «ohne MWST»',
    $e?->field === GrossEntryRefusedException::TAX_CODE && $e->part === 0 && str_contains($e->getMessage(), 'nicht MWST-pflichtig') && str_contains($e->getMessage(), '«ohne MWST»'), (string) $e?->getMessage());
$plain = $builder->build($on, '1020', [new GrossPart('6200', chf('108.10')), new GrossPart('6500', chf('12.40'))], false);
check('B6 … «ohne MWST» on every part posts in strict mode: no tax line, no tax data, the gross on the accounts',
    shape($plain->lines) === ['6200 D 108.10', '6500 D 12.40', '1020 C 120.50'] && $plain->splits === [null, null]);
check('B7 liable (true) splits exactly as «not applied» (null)', shape($builder->build($on, '1020', $invoice, true)->lines) === shape($entry->lines));
$e = $refusal(fn() => $builder->build($on, '1020', [new GrossPart('6500', Money::fromDecimal('12.40', 'EUR'), 'VM')]));
check('B8 a foreign currency is refused (AMOUNT) — the ledger books the base currency only, as receipt today',
    $e?->field === GrossEntryRefusedException::AMOUNT && str_contains($e->getMessage(), 'EUR') && str_contains($e->getMessage(), 'Fremdwährung'), (string) $e?->getMessage());
$e = $refusal(fn() => $builder->build($on, '1020', [new GrossPart('6500', chf('12.40')), new GrossPart('6500', chf('0.00'))]));
check('B9 a part of 0.00 is refused (AMOUNT, its index)', $e?->field === GrossEntryRefusedException::AMOUNT && $e->part === 1, (string) $e?->getMessage());
check('B10 no part at all is refused (ENTRY)', $refusal(fn() => $builder->build($on, '1020', []))?->field === GrossEntryRefusedException::ENTRY);
$e  = $refusal(fn() => $builder->build($on, '100', [new GrossPart('6500', chf('1.00'))]));
$e2 = $refusal(fn() => $builder->build($on, '1020', [new GrossPart('6500', chf('1.00')), new GrossPart('6570', chf('1.00'))]));
check('B11 a group as counter (COUNTER) and an inactive account on part 2 (ACCOUNT, part 1) are refused with the one-line entry\'s sentences',
    $e?->field === GrossEntryRefusedException::COUNTER && str_contains($e->getMessage(), 'Gruppe')
    && $e2?->field === GrossEntryRefusedException::ACCOUNT && $e2->part === 1 && str_contains($e2->getMessage(), 'inaktiv'));
$e = $refusal(fn() => $builder->build($on, '1020', [new GrossPart('6500', chf('10.00'), 'BZ')]));
check('B12 a reverse-charge code is refused (TAX_CODE) while the caller does not opt in — receipt, one-line entry and bank keep their refusal', $e?->field === GrossEntryRefusedException::TAX_CODE && str_contains($e->getMessage(), 'Bezugsteuer'));
$voucher = $builder->build($on, '1020', [new GrossPart('6500', chf('400.00'), 'VM', null, chf('30.00'))]);
check('B13 a voucher tax within the limit is taken as is (30.00 against the computed 29.97)',
    shape($voucher->lines) === ['6500 D 370.00 VM 810 370.00 30.00', '1170 D 30.00', '1020 C 400.00'] && $voucher->splits[0]?->computed->toDecimal() === '29.97');

// The lines post as a GENERATED entry through the ledger.
$ref = $em->getTransaction(\Z77\Module\Financial\Entities\JournalEntry::class)->run(fn() => (new LedgerService($em, 'Harness'))->post(
    PostingRequest::generated($on, 'Rechnung Garage', 'harness', '1', 'harness:1', $entry->lines)));
$rows = $db->fetchAllAssociative('SELECT a.number, l.debit, l.credit, l.tax_code, l.tax_amount, l.text FROM journal_line l JOIN account a ON a.id = l.account_id JOIN journal_entry e ON e.id = l.entry_id WHERE e.idempotency_key = ? ORDER BY l.position', ['harness:1']);
check('B14 the lines post through LedgerService::post() as a generated entry: six lines, the tax data on the net lines, the part texts kept',
    count($rows) === 6 && $rows[0]['tax_code'] === 'VI' && $rows[0]['tax_amount'] === '8.10' && $rows[0]['text'] === 'Benzin' && $rows[5]['number'] === '1020' && $rows[5]['credit'] === '174.50' && $ref->number > 0,
    json_encode($rows, JSON_UNESCAPED_UNICODE));

/** Posts $lines as a generated entry on $date under $key; the rows of the entry, or the refusal. */
$post = static function (string $date, string $key, array $lines) use (&$em, $db): array {
    try {
        $em->getTransaction(\Z77\Module\Financial\Entities\JournalEntry::class)->run(fn() => (new LedgerService($em, 'Harness'))->post(
            PostingRequest::generated(new \DateTimeImmutable($date), 'Harness ' . $key, 'harness', $key, $key, $lines)));
    } catch (\Throwable $e) {
        return ['refused' => get_class($e) . ': ' . $e->getMessage()];
    }

    return $db->fetchAllAssociative('SELECT a.number, l.debit, l.credit, l.tax_code, l.tax_base, l.tax_amount FROM journal_line l JOIN account a ON a.id = l.account_id JOIN journal_entry e ON e.id = l.entry_id WHERE e.idempotency_key = ? ORDER BY l.position', [$key]);
};

// ── T. tax-only («nur Steuer») ──────────────────────────────────────────

echo "T. Tax-only parts («nur Steuer»): base 0.00, the code's rate, the account derived\n";
$builder = GrossEntryBuilder::of($em = $wire());
$on9     = new \DateTimeImmutable('2026-08-25');
$case9   = [
    new GrossPart('4400', chf('129.72'), 'VM', 'Fracht'),
    new GrossPart('4200', chf('45.30'), null, 'Zoll'),
    new GrossPart('', chf('81.00'), 'VM', 'Einfuhrsteuer', null, GrossPartKind::TaxOnly),
];
$nine = $builder->build($on9, '1020', $case9);
check('T1 case 9 (forwarder invoice: freight VM, duty «ohne MWST», import VAT «VM — nur Steuer»): Dr 4400 120.00 [VM 810 120.00 9.72], Dr 1170 9.72, Dr 4200 45.30, Dr 1170 81.00 [VM 810 0.00 81.00], Cr 1020 256.02',
    shape($nine->lines) === ['4400 D 120.00 VM 810 120.00 9.72 | Fracht', '1170 D 9.72', '4200 D 45.30 | Zoll', '1170 D 81.00 VM 810 0.00 81.00 | Einfuhrsteuer', '1020 C 256.02']
    && $sum($nine->lines, 'debit')->equals($sum($nine->lines, 'credit')), json_encode(shape($nine->lines)));
check('T2 the tax-only split: kind TaxOnly, the input account, base and net 0.00, tax = the amount',
    $nine->splits[2]?->kind === GrossPartKind::TaxOnly && $nine->splits[2]->account === '1170' && $nine->splits[2]->base->isZero() && $nine->splits[2]->net->isZero()
    && $nine->splits[2]->tax->toDecimal() === '81.00' && $nine->splits[0]?->kind === GrossPartKind::Standard);
$typed = $builder->build($on9, '1020', [new GrossPart('6500', chf('81.00'), 'VI', null, null, GrossPartKind::TaxOnly)]);
check('T3 a typed account on a tax-only part is ignored — the VAT account comes from the code (VI → 1171)', shape($typed->lines) === ['1171 D 81.00 VI 810 0.00 81.00', '1020 C 81.00'], json_encode(shape($typed->lines)));
$taxOnly = static fn(string $code, array $extra = []) => new GrossPart('', chf('81.00'), $code, null, $extra['voucher'] ?? null, GrossPartKind::TaxOnly);
$e1 = $refusal(fn() => $builder->build($on9, '1020', [$taxOnly('UN')]));
$e2 = $refusal(fn() => $builder->build($on9, '1020', [$taxOnly('BM')], reverseCharge: true));
$e3 = $refusal(fn() => $builder->build($on9, '1020', [$taxOnly('VK')]));
$e4 = $refusal(fn() => $builder->build($on9, '1020', [$taxOnly('VM', ['voucher' => chf('81.00')])]));
$e5 = $refusal(fn() => $builder->build($on9, '1020', [new GrossPart('', chf('81.00'), null, null, null, GrossPartKind::TaxOnly)]));
$e6 = $refusal(fn() => $builder->build($on9, '1020', [$taxOnly('VM')], false));
$e7 = $refusal(fn() => $builder->build($on9, '1170', [$taxOnly('VM')]));
check('T4 refused: an output code, a reverse-charge code, a correction code (VK), a voucher tax, no code at all, a mandator not liable, the counter = the derived VAT account',
    $e1?->field === GrossEntryRefusedException::TAX_CODE && str_contains($e1->getMessage(), '«Nur Steuer» geht nur mit einem Vorsteuer-Code')
    && $e2?->field === GrossEntryRefusedException::TAX_CODE && str_contains($e2->getMessage(), '«Nur Steuer» geht nur')
    && $e3?->field === GrossEntryRefusedException::TAX_CODE && str_contains($e3->getMessage(), 'Vorsteuerkorrektur')
    && $e4?->field === GrossEntryRefusedException::TAX_AMOUNT
    && $e5?->field === GrossEntryRefusedException::TAX_CODE && str_contains($e5->getMessage(), 'Vorsteuer-Code')
    && $e6?->field === GrossEntryRefusedException::TAX_CODE && str_contains($e6->getMessage(), 'nicht MWST-pflichtig')
    && $e7?->field === GrossEntryRefusedException::COUNTER,
    implode(' | ', array_map(static fn($e) => (string) $e?->getMessage(), [$e1, $e2, $e3, $e4, $e5, $e6, $e7])));
$e = $refusal(fn() => $builder->build($on9, '1020', [new GrossPart('6500', chf('100.00'), 'VK')]));
check('T5 a correction code (VE / VK / VN) on a standard part is refused too — manual entries only', $e?->field === GrossEntryRefusedException::TAX_CODE && str_contains($e->getMessage(), 'nur als Sammelbuchung'), (string) $e?->getMessage());
$rows = $post('2026-08-25', 'harness:case9', $nine->lines);
check('T6 case 9 posts through LedgerService::post(): the tax-only line on 1170 with base 0.00 and tax 81.00',
    count($rows) === 5 && $rows[3]['number'] === '1170' && $rows[3]['tax_code'] === 'VM' && $rows[3]['tax_base'] === '0.00' && $rows[3]['tax_amount'] === '81.00' && $rows[3]['debit'] === '81.00',
    json_encode($rows));

// ── RD. the rate date ──────────────────────────────────────────────────

echo "RD. The rate of the RATE date (creditor: the invoice date)\n";
$paid    = new \DateTimeImmutable('2024-01-10');
$invoice = new \DateTimeImmutable('2023-12-20');
$rd      = $builder->build($paid, '1020', [new GrossPart('6500', chf('107.70'), 'VM')], rateDate: $invoice);
check('RD1 invoice 20.12.2023, paid 10.01.2024, 107.70 VM → 7.70 at 7.7 % (the rate of the invoice date)',
    shape($rd->lines) === ['6500 D 100.00 VM 770 100.00 7.70', '1170 D 7.70', '1020 C 107.70'], json_encode(shape($rd->lines)));
check('RD2 … without a rate date the entry date rules: 8.07 at 8.1 %',
    shape($builder->build($paid, '1020', [new GrossPart('6500', chf('107.70'), 'VM')])->lines) === ['6500 D 99.63 VM 810 99.63 8.07', '1170 D 8.07', '1020 C 107.70']);
check('RD3 a tax-only part takes the code\'s rate at the rate date (770)',
    shape($builder->build($paid, '1020', [$taxOnly('VM')], rateDate: $invoice)->lines) === ['1170 D 81.00 VM 770 0.00 81.00', '1020 C 81.00']);
$e = $refusal(fn() => $builder->build($on9, '1020', [new GrossPart('6500', chf('10.00'), 'VM')], rateDate: new \DateTimeImmutable('2017-06-30')));
check('RD4 no rate on the rate date → refused naming that day', $e?->getMessage() === 'Für VM gilt am 30.06.2017 kein Satz.', (string) $e?->getMessage());

// ── RC. reverse charge ────────────────────────────────────────────────

echo "RC. Reverse charge (caller opts in): the single-entry shape and the split pair\n";
$on8   = new \DateTimeImmutable('2026-11-10');
$case8 = $builder->build($on8, '1020', [new GrossPart('6500', chf('1234.55'), 'BI', 'Software')], reverseCharge: true);
check('RC1 case 8: 1 234.55 BI → Dr 6500 1 234.55 [BI 810 base +1 234.55 tax 100.00], Dr 1171 100.00, Cr 2200 100.00, Cr 1020 1 234.55 — base POSITIVE, balanced',
    shape($case8->lines) === ['6500 D 1234.55 BI 810 1234.55 100.00 | Software', '1171 D 100.00', '2200 C 100.00', '1020 C 1234.55']
    && $sum($case8->lines, 'debit')->equals($sum($case8->lines, 'credit')) && $case8->splits[0]?->reverseCharge === true && $case8->splits[0]->owedAccount === '2200',
    json_encode(shape($case8->lines)));
check('RC2 BM → the input account of field 400 (1170); BM2 at the reduced rate 2.6 %',
    shape($builder->build($on8, '1020', [new GrossPart('6500', chf('100.00'), 'BM')], reverseCharge: true)->lines) === ['6500 D 100.00 BM 810 100.00 8.10', '1170 D 8.10', '2200 C 8.10', '1020 C 100.00']
    && shape($builder->build($on8, '1020', [new GrossPart('6500', chf('100.00'), 'BM2')], reverseCharge: true)->lines) === ['6500 D 100.00 BM2 260 100.00 2.60', '1170 D 2.60', '2200 C 2.60', '1020 C 100.00']);
$based = $builder->build($on8, '1020', [new GrossPart('6500', chf('95.00'), 'BM', null, null, GrossPartKind::Standard, chf('88.50'))], reverseCharge: true);
check('RC3 a tax base of its own (USD × ESTV-Kurs = 88.50): tax 7.17 on 88.50, the expense line carries the amount paid 95.00',
    shape($based->lines) === ['6500 D 95.00 BM 810 88.50 7.17', '1170 D 7.17', '2200 C 7.17', '1020 C 95.00'], json_encode(shape($based->lines)));
$e1 = $refusal(fn() => $builder->build($on8, '1020', [new GrossPart('6500', chf('100.00'), 'BI', null, chf('8.10'))], reverseCharge: true));
$e2 = $refusal(fn() => $builder->build($on8, '1020', [new GrossPart('6500', chf('10.00'), 'BZ')], reverseCharge: true));
$e3 = $refusal(fn() => $builder->build($on8, '1020', [new GrossPart('6500', chf('10.00'), 'VM', null, null, GrossPartKind::Standard, chf('9.00'))]));
$e4 = $refusal(fn() => $builder->build($on8, '1020', [new GrossPart('6500', chf('10.00'), 'BI')], false, reverseCharge: true));
check('RC4 refused: a voucher tax, a reverse-charge code without an input field in the form (BZ), a tax base on an input code, a mandator not liable',
    $e1?->field === GrossEntryRefusedException::TAX_AMOUNT && $e2?->field === GrossEntryRefusedException::TAX_CODE && str_contains($e2->getMessage(), 'kein Vorsteuerfeld')
    && $e3?->field === GrossEntryRefusedException::AMOUNT && $e4?->field === GrossEntryRefusedException::TAX_CODE,
    implode(' | ', array_map(static fn($e) => (string) $e?->getMessage(), [$e1, $e2, $e3, $e4])));
$pair = $builder->buildSplit(new \DateTimeImmutable('2026-05-10'), '1020', [new GrossPart('6500', chf('100.00'), 'BI', 'Lizenz')], rateDate: new \DateTimeImmutable('2026-03-15'), reverseCharge: true);
check('RC5 the split (invoice 15.03., paid 10.05.): expense entry Dr 6500 100.00 / Cr 1020 WITHOUT code; tax entry the pair alone — Dr 1171 8.10 [BI 810 100.00 8.10] / Cr 2200 8.10',
    shape($pair->lines) === ['6500 D 100.00 | Lizenz', '1020 C 100.00'] && shape($pair->taxLines) === ['1171 D 8.10 BI 810 100.00 8.10 | Lizenz', '2200 C 8.10'],
    json_encode([shape($pair->lines), shape($pair->taxLines)]));
$r1 = $post('2026-11-10', 'harness:case8', $case8->lines);
$r2 = $post('2026-03-15', 'harness:rc-pair', $pair->taxLines);
$r3 = $post('2026-05-10', 'harness:rc-expense', $pair->lines);
check('RC6 case 8 and both entries of the split post through LedgerService::post()',
    count($r1) === 4 && $r1[0]['tax_base'] === '1234.55' && count($r2) === 2 && $r2[0]['number'] === '1171' && $r2[0]['tax_base'] === '100.00' && count($r3) === 2 && $r3[0]['tax_code'] === null,
    json_encode([$r1, $r2, $r3]));
$e = $refusal(fn() => $builder->buildSplit($on8, '1020', [new GrossPart('6500', chf('100.00'), 'BI')]));
check('RC7 the split refuses a reverse-charge part without the opt-in, as build() does', $e?->field === GrossEntryRefusedException::TAX_CODE && str_contains($e->getMessage(), 'Bezugsteuer'));

// ── SP. split: catching up input tax through 1179 (case 10 B) ─────────────

echo "SP. buildSplit(): the expense entry and the tax entry of a deferred input tax (case 10 B)\n";
$paidOn = new \DateTimeImmutable('2026-05-20');
$ten    = $builder->buildSplit($paidOn, '1020', [new GrossPart('4400', chf('540.50'), 'VM', 'Beratung')]);
check('SP1 case 10 B: E1 Dr 4400 500.00, Dr 1179 40.50, Cr 1020 540.50 (no code); E2 Dr 1170 40.50 [VM 810 0.00 40.50], Cr 1179 40.50',
    shape($ten->lines) === ['4400 D 500.00 | Beratung', '1179 D 40.50', '1020 C 540.50'] && shape($ten->taxLines) === ['1170 D 40.50 VM 810 0.00 40.50 | Beratung', '1179 C 40.50'],
    json_encode([shape($ten->lines), shape($ten->taxLines)]));
$tenT = $builder->buildSplit($paidOn, '1020', [new GrossPart('', chf('40.50'), 'VM', null, null, GrossPartKind::TaxOnly)]);
check('SP2 a tax-only part deferred: E1 Dr 1179 40.50 / Cr 1020; E2 the tax-only line Dr 1170 40.50 [VM 810 0.00 40.50] / Cr 1179',
    shape($tenT->lines) === ['1179 D 40.50', '1020 C 40.50'] && shape($tenT->taxLines) === ['1170 D 40.50 VM 810 0.00 40.50', '1179 C 40.50'],
    json_encode([shape($tenT->lines), shape($tenT->taxLines)]));
$mixedSplit = $builder->buildSplit($paidOn, '1020', [new GrossPart('4400', chf('540.50'), 'VM'), new GrossPart('4200', chf('45.30')), new GrossPart('6500', chf('0.05'), 'VM')]);
check('SP3 a part «ohne MWST» and a part whose tax rounds to 0.00 stay uncoded in the expense entry; only the tax moves; both entries balanced',
    shape($mixedSplit->lines) === ['4400 D 500.00', '1179 D 40.50', '4200 D 45.30', '6500 D 0.05', '1020 C 585.85'] && shape($mixedSplit->taxLines) === ['1170 D 40.50 VM 810 0.00 40.50', '1179 C 40.50']
    && $sum($mixedSplit->lines, 'debit')->equals($sum($mixedSplit->lines, 'credit')) && $sum($mixedSplit->taxLines, 'debit')->equals($sum($mixedSplit->taxLines, 'credit')),
    json_encode([shape($mixedSplit->lines), shape($mixedSplit->taxLines)]));
check('SP4 the split uses the voucher\'s rate (rate date), not the tax date\'s: invoice 20.12.2023 → 7.70',
    shape($builder->buildSplit($paid, '1020', [new GrossPart('6500', chf('107.70'), 'VM')], rateDate: $invoice)->taxLines) === ['1170 D 7.70 VM 770 0.00 7.70', '1179 C 7.70']);
check('SP5 nothing to move (no coded part) → no tax entry', $builder->buildSplit($paidOn, '1020', [new GrossPart('4200', chf('45.30'))])->taxLines === []);
$e1 = $refusal(fn() => $builder->buildSplit($paidOn, '1020', [new GrossPart('6500', chf('50.00'), 'UE')]));
$e2 = $refusal(fn() => $builder->buildSplit($paidOn, '1000', [new GrossPart('1020', chf('21.60'), 'UN')]));
check('SP6 a zero-rated and an output code are not split (TAX_CODE)',
    $e1?->field === GrossEntryRefusedException::TAX_CODE && str_contains($e1->getMessage(), 'nicht getrennt buchen') && $e2?->field === GrossEntryRefusedException::TAX_CODE,
    (string) $e1?->getMessage() . ' | ' . (string) $e2?->getMessage());
$e1 = $post('2026-05-20', 'harness:10b', $ten->lines);
$e2 = $post('2026-07-01', 'harness:10b:vat', $ten->taxLines);
$transit = $db->fetchOne("SELECT COALESCE(SUM(l.debit) - SUM(l.credit), 0) FROM journal_line l JOIN account a ON a.id = l.account_id JOIN journal_entry e ON e.id = l.entry_id WHERE a.number = '1179' AND e.idempotency_key IN ('harness:10b', 'harness:10b:vat')");
check('SP7 both entries of case 10 B post (E1 20.05., E2 01.07.); 1179 stands at 0.00 after both; E2 carries the code on 1170 with base 0.00',
    count($e1) === 3 && count($e2) === 2 && (string) $transit === '0.00' && $e2[0]['number'] === '1170' && $e2[0]['tax_code'] === 'VM' && $e2[0]['tax_base'] === '0.00',
    json_encode([$e1, $e2, $transit]));
$db->executeStatement("UPDATE mandator SET account_vat_input_pending = ''");
$e = $refusal(fn() => GrossEntryBuilder::of($wire())->buildSplit($paidOn, '1020', [new GrossPart('4400', chf('540.50'), 'VM')]));
$db->executeStatement("UPDATE mandator SET account_vat_input_pending = '1179'");
$builder = GrossEntryBuilder::of($em = $wire());
check('SP8 no transit account on the mandator → refused (ENTRY) naming «Vorsteuer, noch nicht abgerechnet» and where to set it',
    $e?->field === GrossEntryRefusedException::ENTRY && str_contains($e->getMessage(), '«Vorsteuer, noch nicht abgerechnet»') && str_contains($e->getMessage(), 'Reiter «Konten»'), (string) $e?->getMessage());
check('SP9 build() needs no transit account (an ordinary entry is untouched by the split)', count($builder->build($paidOn, '1020', [new GrossPart('4400', chf('540.50'), 'VM')])->lines) === 3);

// ── L. the lock of the VAT accounts ────────────────────────────────────

echo "L. A VAT account the lock guards on the entry's date is refused as Soll and as Haben\n";
$em       = $wire();
$ledger   = new LedgerService($em);
$locked   = new VatSettings(true, VatMethod::Effective, VatConsideration::Agreed, VatRhythm::Quarter, new \DateTimeImmutable('2026-01-01'), null);
$guarded  = new GrossEntryBuilder('CHF', $em->getRepository(Account::class), $em->getRepository(TaxCode::class), VatRates::from($em), $ledger, new VatAccountMap($em, $ledger, $locked));
$e1 = $refusal(fn() => $guarded->build($on8, '1020', [new GrossPart('2200', chf('500.00'))]));
$e2 = $refusal(fn() => $guarded->build($on8, '1170', [new GrossPart('6500', chf('10.00'))]));
check('L1 2200 as Soll (ACCOUNT) and 1170 as Haben (COUNTER) are refused from «Abrechnung ab» on, with the sentence naming the settlement account',
    $e1?->field === GrossEntryRefusedException::ACCOUNT && str_contains($e1->getMessage(), 'ist ein MWST-Konto') && str_contains($e1->getMessage(), 'Abrechnungskonto MWST')
    && $e2?->field === GrossEntryRefusedException::COUNTER && str_contains($e2->getMessage(), 'ist ein MWST-Konto'),
    (string) $e1?->getMessage() . ' | ' . (string) $e2?->getMessage());
check('L2 before «Abrechnung ab» the same entry builds (the lock does not apply on that day)',
    shape($guarded->build(new \DateTimeImmutable('2025-12-31'), '1020', [new GrossPart('2200', chf('500.00'))])->lines) === ['2200 D 500.00', '1020 C 500.00']);
check('L3 under the lock: the tax-only line on the derived input account, the settlement account 2201 and the transit account 1179 pass',
    shape($guarded->build($on8, '1020', [$taxOnly('VM')])->lines) === ['1170 D 81.00 VM 810 0.00 81.00', '1020 C 81.00']
    && count($guarded->build($on8, '1020', [new GrossPart('2201', chf('500.00'))])->lines) === 2
    && count($guarded->build($on8, '1020', [new GrossPart('1179', chf('5.00'))])->lines) === 2);
check('L4 account() (no date) does not judge the lock — the bank\'s contra check goes through build() with its date', $guarded->account('2200')->getNumber() === '2200');

// ── D. the input-tax deferral (VatSettlement) ────────────────────────────

echo "D. VatSettlement::claimableFrom() / ::inputTaxDeferral() — input tax only, decided by the payment date\n";
$day      = static fn(string $d) => new \DateTimeImmutable($d);
$settings = new VatSettings(true, VatMethod::Effective, VatConsideration::Agreed, VatRhythm::Quarter, $day('2025-10-01'), null);
$return   = static fn(string $from, string $to, int $revision = 1) => new VatReturn(VatPeriod::stored($day($from), $day($to), VatRhythm::Quarter), $revision, VatMethod::Effective,
    VatConsideration::Agreed, 'CH', 'CH 2024', hash('sha256', 'rules'), [], chf('0.00'), chf('0.00'), chf('0.00'), null, 'harness', $day('2026-07-10 09:30:00'),
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
check('D0 no return posted yet → the running period starts on «Abrechnung ab» (01.10.2025)', (new VatSettlement($em, $settings))->claimableFrom()?->format('Y-m-d') === '2025-10-01');
$persist([$return('2025-10-01', '2025-12-31'), $return('2026-01-01', '2026-03-31'), $return('2026-04-01', '2026-06-30')]);
$s = new VatSettlement($em = $wire(), $settings);
check('D1 Q4 2025 – Q2 2026 posted → claimableFrom() = 01.07.2026, the first VAT period not yet posted', $s->claimableFrom()?->format('Y-m-d') === '2026-07-01');
check('D2 a payment date in an open period (15.07.2026) → none: the ordinary posting', $s->inputTaxDeferral($day('2026-07-15'))->isNone());
$d = $s->inputTaxDeferral($day('2026-05-20'));
check('D3 paid 20.05.2026 (Q2 settled), same calendar year → catch-up on 01.07.2026, naming 2. Quartal 2026',
    $d->isCatchUp() && $d->kind === InputTaxDeferral::CATCH_UP && $d->taxDate?->format('Y-m-d') === '2026-07-01' && $d->settledPeriod?->label() === '2. Quartal 2026');
$d = $s->inputTaxDeferral($day('2025-11-10'));
check('D4 paid 10.11.2025 (Q4 2025 settled), running period in 2026 → refused, the owner\'s sentence (F-1) with year and period',
    $d->isRefused() && $d->year === 2025 && $d->taxDate === null
    && $d->refusalText() === 'Vorsteuer aus 2025 wird nicht nachgeholt — die Rechnung «ohne MWST» verbuchen und die Vorsteuer als Korrekturbuchung zu 4. Quartal 2025 erfassen.'
    && str_starts_with(str_replace('Vorsteuer aus 2025 wird nicht nachgeholt — ', '', $d->refusalText('den Beleg')), 'den Beleg «ohne MWST»'), $d->isRefused() ? $d->refusalText() : $d->kind);
check('D5 a payment date before «Abrechnung ab» (01.06.2025) is not settled → none (the ordinary rules refuse a code there, as today)', $s->inputTaxDeferral($day('2025-06-01'))->isNone());
$notLiable = new VatSettlement($em, new VatSettings(false, null, null, null, null, null));
check('D6 a mandator not liable → claimableFrom() null, the deferral none', $notLiable->claimableFrom() === null && $notLiable->inputTaxDeferral($day('2026-05-20'))->isNone());
$ended = new VatSettlement($em, new VatSettings(true, VatMethod::Effective, VatConsideration::Agreed, VatRhythm::Quarter, $day('2025-10-01'), $day('2026-06-30')));
check('D7 «Abrechnung bis» 30.06.2026 with Q2 posted → no running period left: claimableFrom() null, a settled payment date refused',
    $ended->claimableFrom() === null && $ended->inputTaxDeferral($day('2026-05-20'))->isRefused());
$db->executeStatement("UPDATE vat_return SET live = NULL, state = 'lifted' WHERE period_from = '2026-04-01'");
$s = new VatSettlement($em = $wire(), $settings);
check('D8 after Q2 is lifted: 20.05.2026 is open again → none; claimableFrom() = 01.04.2026',
    $s->inputTaxDeferral($day('2026-05-20'))->isNone() && $s->claimableFrom()?->format('Y-m-d') === '2026-04-01');
$persist([$return('2026-04-01', '2026-06-30', 2), $return('2026-07-01', '2026-09-30'), $return('2026-10-01', '2026-12-31')]);
$s = new VatSettlement($em = $wire(), $settings);
$d = $s->inputTaxDeferral($day('2026-05-20'));
check('D9 the whole of 2026 posted → the running period starts 01.01.2027: a payment of 20.05.2026 is refused (across the year), naming 2. Quartal 2026',
    $s->claimableFrom()?->format('Y-m-d') === '2027-01-01' && $d->isRefused() && $d->year === 2026 && $d->settledPeriod?->label() === '2. Quartal 2026', $d->kind);
$settlementSource = (string) file_get_contents($root . '/packages/module-financial/src/VatReturn/VatSettlement.php');
$builderSource    = (string) file_get_contents($root . '/packages/module-financial/src/Services/GrossEntryBuilder.php');
check('D10 two rules, not one (owner 2026-11-25): VatSettlement has no general «deferral()» and no reverse-charge branch; the builder decides no date (it names no VatSettlement)',
    !preg_match('/function\s+deferral\s*\(/', $settlementSource) && !str_contains($settlementSource, 'use Z77\\Module\\Vat\\Entities\\TaxCategory;')
    && !str_contains($builderSource, 'use Z77\\Module\\Financial\\VatReturn\\VatSettlement;') && !str_contains($builderSource, 'new VatSettlement('));
$threw = null;
try { InputTaxDeferral::none()->refusalText(); } catch (\LogicException $x) { $threw = $x; }
check('D11 refusalText() of an answer that is not a refusal is a programming error (LogicException)', $threw !== null);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
