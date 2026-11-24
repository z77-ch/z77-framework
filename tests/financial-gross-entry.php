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
 *     currency, a non-positive amount, a bad account, a reverse-charge code are refused.
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
use Z77\Module\Financial\Ledger\GrossPart;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Services\GrossEntryBuilder;
use Z77\Module\Financial\Services\GrossEntryRefusedException;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Ui\OneLineEntryForm;
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
        'reverse' => ['lines' => null, 'errors' => ['tax_code' => 'Bezugsteuer (BZ) wird auf beiden Seiten gebucht — als Sammelbuchung erfassen.'], 'general' => [], 'hint' => ''],
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
check('B12 a reverse-charge code is refused (TAX_CODE) — tax on top to both sides is not a gross split', $e?->field === GrossEntryRefusedException::TAX_CODE && str_contains($e->getMessage(), 'Bezugsteuer'));
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

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
