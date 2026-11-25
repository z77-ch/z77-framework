<?php

/**
 * Creditor × VAT return harness (CLI) — what the Kreditorenjournal answers the VAT return.
 *
 *   O. (offline) `creditorConfig` registers `Close\ForeignWithoutVatCheck` under the open-work scope
 *      `vat-return` only; the check refuses parameters that are not dates;
 *   M. (database) a throwaway schema: vat, contact, mandator, financial, dms, receipt, creditor
 *      migrated; the tax codes of the seed plus a reverse-charge code;
 *   FW. the foreign-voucher warning (ADR-042 addendum 2 of 2026-11-25, point 9): a POSTED invoice paid
 *      in the period, foreign — an original currency, or a creditor with an address abroad and none in
 *      CH / LI — with a line «ohne MWST» and no reverse-charge line is a WARNING naming it; a Swiss,
 *      Liechtenstein, mixed, empty or missing address, a coded line only, a reverse-charge line, an
 *      unposted invoice, a payment date outside the period are not; the first ten are named, the rest
 *      counted; the registry of the real module configs passes it through as a warning.
 *
 * VAT return stage S5 on the creditor invoice (package W2-E; ADR-041 addendum 2026-11-25 points 6–9,
 * ADR-059 addendum 2026-11-25; owner S56-4 … S56-8, F-1, O-1, O-4, O-5). O also: `ImportVatPeriodCheck`
 * registered under `vat-return` only, the source check «one input-tax rule, no reverse-charge rule
 * named», the import-VAT sentence. On the database, through the real services (intake, save,
 * «Journal verbuchen»; KMU chart, a liable mandator):
 *
 *   R7. the rate of the INVOICE date (S56-5): 20.12.2023 / paid 10.01.2024 → 7.7 %, 7.70; without an
 *       invoice date the payment date's rate;
 *   LE. the line «nur Steuer»: account ignored, kind stored; the creditor learns the kind with the code
 *       (S56-4) — only when its first line is «nur Steuer»; only input codes;
 *   TO. case 9 — the forwarder's invoice (freight VM, customs «ohne MWST», import VAT «VM — nur
 *       Steuer»): one entry at the payment date, field 400 = 90.72;
 *   IW. import VAT across two VAT periods (O-5): the grey line of the run AND the warning at «MWST
 *       buchen» of the payment period; none for one period, a standard line, a date before «Abrechnung ab»;
 *   NH. «Vorsteuer nachholen»: within the calendar year the expense uncoded with the tax on 1179 and
 *       the tax entry `creditor:{id}:vat` on the first day of the running period (case 10 B, 1179 at
 *       0.00); across the year refused with the F-1 sentence, nothing posted, «ohne MWST» posts;
 *       before «Abrechnung ab» refused with the way out «ohne MWST»;
 *   VL. the lock of the VAT accounts is ON and passed every entry above; the line check asks the lock
 *       for the invoice's day.
 *
 * VAT return stage S6, reverse charge on the creditor invoice (package W3-B; ADR-041 addendum 2026-11-25
 * point 10, ADR-059 addendum 2026-11-25 and amendment b; owner S56-11 … S56-14, O-2, O-3, O-4, O-8, D-4).
 * O4 is the source check of the two date rules (input tax by the payment date, reverse charge by the
 * invoice date — never one shared check). RC, the decision table as corrected by the delta: same day →
 * one entry; two dates → the pair at the invoice date, the expense uncoded at «bezahlt am»; unpaid at the
 * run → the pair only, the invoice stays; changed → amended in place; deleted / «ohne MWST» → retracted;
 * paid later → the expense only; the invoice date in a posted period (same year or not) → refused with
 * the O-8 sentence, «ohne MWST» posts; before «Abrechnung ab» → «ohne MWST»; USD with ESTV-Kurs → base
 * 88.50 / tax 7.17; the blocking open point of the period being posted only; R6 (different days refused,
 * the same day one tax entry); after «Abrechnung bis» no tax and a grey line (D-4); the period settled,
 * then changed → refused; the recognition's note for a foreign supplier; the opt-in in source.
 *
 * FW's invoices are rows written by raw SQL (the check reads posted invoices, it posts nothing). Every
 * name and amount here is synthetic.
 *
 * Run: php tests/run.php creditor-vat (or php tests/creditor-vat.php)
 * O needs the monorepo's vendor/; M–FW a reachable MariaDB and the `z77test` credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*; without them M is reported SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "creditor-vat: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Z77\Module\Creditor\Close\ForeignWithoutVatCheck;
use Z77\Module\Creditor\Close\ImportVatPeriodCheck;
use Z77\Module\Creditor\Close\ImportVatPeriods;
use Z77\Module\Creditor\Repositories\CreditorInvoiceRepository;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks;
use Z77\Persistence\Doctrine\OpenWork\Severity;

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

// ─────────────────────────────────────────────────────────────────────────
echo "O. Registration and parameters (offline)\n";

$config = require $root . '/packages/module-creditor/src/App/Config/creditorConfig.inc.php';
$scopes = array_keys(array_filter($config['openWorkChecks'] ?? [], static fn(array $checks): bool => in_array(ForeignWithoutVatCheck::class, $checks, true)));
check('O1 `creditorConfig` registers ForeignWithoutVatCheck under `vat-return` only — beside UnpostedCreditorInvoicesCheck, not for the year close or the reports',
    $scopes === ['vat-return'] && in_array(\Z77\Module\Creditor\Close\UnpostedCreditorInvoicesCheck::class, $config['openWorkChecks']['vat-return'], true), json_encode($scopes));
$e = thrown(fn() => iterator_to_array((new ForeignWithoutVatCheck())->check('vat-return', ['from' => '2026-07-01', 'to' => day('2026-09-30')])));
check('O2 parameters that are not dates are refused, naming the scope', $e instanceof \InvalidArgumentException && str_contains($e->getMessage(), "'vat-return'"), $e?->getMessage() ?? 'nothing thrown');

// Stage S5 (package W2-E): the import-VAT warning registered beside the others; the two date rules kept apart (owner O-4).
$importScopes = array_keys(array_filter($config['openWorkChecks'] ?? [], static fn(array $checks): bool => in_array(ImportVatPeriodCheck::class, $checks, true)));
$e = thrown(fn() => iterator_to_array((new ImportVatPeriodCheck())->check('vat-return', ['from' => day('2026-07-01'), 'to' => '2026-09-30'])));
check('O3 `creditorConfig` registers ImportVatPeriodCheck under `vat-return` only (O-5 b); the check refuses parameters that are not dates, naming the scope',
    $importScopes === ['vat-return'] && $e instanceof \InvalidArgumentException && str_contains($e->getMessage(), "'vat-return'"), json_encode($importScopes));
$directSrc = (string) file_get_contents($root . '/packages/module-creditor/src/Posting/DirectPosting.php');
$creditorSrc = '';
$rcTimingIn  = [];   // the creditor files naming financial's reverse-charge rule
foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/packages/module-creditor/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
    $src          = (string) file_get_contents((string) $file);
    $creditorSrc .= $src;
    if (str_contains($src, 'ReverseChargeTiming')) {
        $rcTimingIn[] = basename((string) $file);
    }
}
/** The body of method $name in $src (up to the next method) — for the source checks of the two date rules. */
$methodBody = static function (string $src, string $name): string {
    $at = strpos($src, 'function ' . $name . '(');
    if ($at === false) {
        return '';
    }
    $next = preg_match('/\n    (?:public|private|protected) (?:static )?function /', $src, $m, PREG_OFFSET_CAPTURE, $at + 10) === 1 ? $m[0][1] : strlen($src);

    return substr($src, $at, $next - $at);
};
// Wave 3 (package W3-B, owner O-4): the reverse-charge rule is now called — ONLY by DirectPosting, and the two rules stay apart.
check('O4 the two date rules apart (owner O-4): input tax through financial\'s ONE rule — DirectPosting calls `inputTaxDeferral()` (and `buildSplit()`), never `claimableFrom()` (no creditor class names it), and asks it ONLY for input codes (a reverse-charge line is set aside before); reverse charge through `ReverseChargeTiming::forInvoiceDate()` — named by DirectPosting alone, whose reverse-charge methods name neither `inputTaxDeferral` nor `VatSettlement`; the rate date is passed (the invoice date, S56-5)',
    substr_count($directSrc, '->inputTaxDeferral(') === 1 && str_contains($directSrc, '->buildSplit(') && !str_contains($creditorSrc, 'claimableFrom')
    && preg_match('/if \(\$this->codes\(\)->isReverseCharge\(\$line->getTaxCode\(\)\)\) \{\s+\$reverse\[\] = \$i;\s+\} else \{\s+\$coded = \$coded \|\| \$line->getTaxCode\(\) !== null;/', $directSrc) === 1
    && str_contains($directSrc, '$deferral = $coded ? (new VatSettlement($this->em))->inputTaxDeferral($paidOn) : InputTaxDeferral::none();')
    && $rcTimingIn === ['DirectPosting.php'] && substr_count($directSrc, '->forInvoiceDate(') === 1
    && array_filter(['reverseChargeDate', 'reverseChargeEntry', 'afterEnd', 'bases'], static fn(string $m): bool => $methodBody($directSrc, $m) === '' || preg_match('/inputTaxDeferral|VatSettlement|InputTaxDeferral/', $methodBody($directSrc, $m)) === 1) === []
    && str_contains($directSrc, '$invoice->getInvoiceDate() ?? $paidOn'), json_encode($rcTimingIn));
$oQ3 = new \Z77\Module\Financial\VatReturn\VatPeriod(day('2026-07-01'), day('2026-09-30'), \Z77\Module\Mandator\Entities\VatRhythm::Quarter);
$oQ4 = new \Z77\Module\Financial\VatReturn\VatPeriod(day('2026-10-01'), day('2026-12-31'), \Z77\Module\Mandator\Entities\VatRhythm::Quarter);
check('O5 the import-VAT sentence (one text for the preview and the warning): «Einfuhrsteuer der Rechnung 26-0815 (Muster Spedition AG): Rechnungsdatum im 3. Quartal 2026, abgezogen im 4. Quartal 2026 — prüfen.»',
    ImportVatPeriods::text('Rechnung 26-0815 (Muster Spedition AG)', $oQ3, $oQ4) === 'Einfuhrsteuer der Rechnung 26-0815 (Muster Spedition AG): Rechnungsdatum im 3. Quartal 2026, abgezogen im 4. Quartal 2026 — prüfen.',
    ImportVatPeriods::text('Rechnung 26-0815 (Muster Spedition AG)', $oQ3, $oQ4));
$editTpl  = (string) file_get_contents($root . '/packages/module-creditor/res/view/templates/Backend/CreditorJournalController/edit.tpl.php');
$tplTrait = (string) file_get_contents($root . '/packages/module-creditor/src/Ui/CreditorJournalControllerTrait.php');
check('O6 the invoice window: the lines\' VAT select is module-receipt\'s ONE select (`taxChoices()`, option groups, «nur Steuer»); a row is its own fill scope whose select asks `tax-fill` (core.js «fill» locks «Konto» — no script of its own, S56-2); the account list asks the lock for the invoice\'s day; the amount label is neutral «Betrag CHF» (S56-3)',
    str_contains($editTpl, '$choices->taxChoices(') && str_contains($editTpl, "'/tax-fill'") && str_contains($editTpl, 'data-row data-fill-scope') && str_contains($editTpl, 'data-fill-key="row_account"')
    && str_contains($editTpl, '$choices->accounts($lockDate)') && !str_contains($editTpl, '(inkl. MWST)') && !str_contains($editTpl, '<script')
    && str_contains($tplTrait, 'protected function taxFillAction(): FetchResponse') && str_contains($tplTrait, "'lock' => ['line_account' => \$taxOnly, 'row_account' => \$taxOnly]"));

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

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-creditor-vat-' . getmypid());
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
    'Contact'   => $root . '/packages/module-contact',
    'Mandator'  => $root . '/packages/module-mandator',
    'Financial' => $root . '/packages/module-financial',
    'Dms'       => $root . '/packages/module-dms',
    'Receipt'   => $root . '/packages/module-receipt',
    'Creditor'  => $root . '/packages/module-creditor',
];
$namespaces = "'Z77\\\\Shared\\\\' => ['sourcePaths' => ['{$root}/packages/kernel/shared']],\n";
foreach ($packages as $name => $path) {
    $namespaces .= "'Z77\\\\Module\\\\{$name}\\\\' => ['sourcePaths' => ['{$path}']],\n";
}
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'creditor', 'modules' => ['vat' => [], 'contact' => [], 'mandator' => [], 'financial' => [], 'dms' => [], 'receipt' => [], 'creditor' => []]];");
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
// The seed codes plus a reverse-charge code `BI` (when the seed has none of its own) and `BX`, deactivated — a posted line keeps a code deactivated since.
$codes = json_decode((string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_codes.default.json'), true);
$rates = json_decode((string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_rates.default.json'), true);
$known = array_column($codes, 'code');
foreach ([['BI', true], ['BX', false]] as $i => [$code, $active]) {
    if (!in_array($code, $known, true)) {
        $codes[] = ['id' => 901 + $i, 'code' => $code, 'country' => 'CH', 'category' => 'reverse-charge', 'label' => "Bezugsteuer {$code}", 'active' => $active];
        $rates[] = ['id' => 901 + $i, 'code' => $code, 'valid_from' => '2024-01-01', 'rate' => 810];
    }
}
$write('data/framework/vat/tax_codes.json', json_encode($codes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$write('data/framework/vat/tax_rates.json', json_encode($rates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

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
check('M1 migrate exits 0 (vat, contact, mandator, financial, dms, receipt, creditor)', $code === 0, trim($migrateOut->fetch()));
if ($code !== 0) {
    echo "\n{$pass} passed, {$fail} failed\n";
    exit(1);
}

// ── fixtures as rows ─────────────────────────────────────────────────────

/** A creditor: a contact with a profile and its addresses (country codes; '' = an address without a country). */
$creditor = static function (string $company, array $countries) use ($db): int {
    $db->insert('contact', ['kind' => 'organisation', 'company' => $company, 'first_name' => '', 'last_name' => '', 'language' => 'de', 'email' => '', 'phone' => '', 'active' => 1]);
    $contactId = (int) $db->lastInsertId();
    foreach ($countries as $i => $country) {
        $db->insert('address', ['salutation' => '', 'title' => '', 'first_name' => '', 'name' => $company, 'address_row' => '', 'street' => 'Weg', 'house_no' => (string) ($i + 1), 'zip' => '1000', 'city' => 'Ort', 'country' => $country]);
        $db->insert('contact_address', ['type_code' => $i === 0 ? 'main' : 'delivery', 'title' => '', 'contact_id' => $contactId, 'address_id' => (int) $db->lastInsertId()]);
    }
    $db->insert('creditor_profile', ['active' => 1, 'contact_id' => $contactId]);

    return (int) $db->lastInsertId();
};
/**
 * An invoice with its lines — each line `wv` («ohne MWST») or a tax code. Posted unless $state says otherwise.
 *
 * @param list<string> $lines
 */
$invoice = static function (?int $profile, string $number, string $paidOn, array $lines, ?string $currency = null, string $state = 'posted') use ($db): int {
    static $document = 0;
    $db->insert('creditor_invoice', [
        'state' => $state, 'document_id' => ++$document, 'invoice_number' => $number === '' ? null : $number, 'invoice_date' => $paidOn, 'amount' => '100.00',
        'original_amount' => $currency === null ? null : '110.00', 'original_currency' => $currency, 'payment_state' => 'paid', 'paid_on' => $paidOn,
        'payment_method_code' => 'bank', 'credit_account' => '1020', 'ledger_entry_ref' => $state === 'posted' ? 'harness' : null,
        'received_by' => 'harness', 'received_at' => $paidOn . ' 08:00:00', 'creditor_profile_id' => $profile,
    ]);
    $id = (int) $db->lastInsertId();
    foreach ($lines as $position => $line) {
        $db->insert('creditor_invoice_line', ['position' => $position, 'amount' => '50.00', 'account' => '4400',
            'tax_code' => $line === 'wv' ? null : $line, 'without_vat' => $line === 'wv' ? 1 : 0, 'invoice_id' => $id]);
    }

    return $id;
};
/** The check's findings for Q3 2026 on a fresh request: severity + message. */
$q3 = static function () use ($wire): array {
    $wire();

    return array_map(static fn(Finding $f): string => ($f->severity === Severity::Warning ? 'W: ' : 'B: ') . $f->message,
        iterator_to_array((new ForeignWithoutVatCheck())->check('vat-return', ['from' => day('2026-07-01'), 'to' => day('2026-09-30')]), false));
};

// ─────────────────────────────────────────────────────────────────────────
echo "FW. The foreign-voucher warning (Q3 2026)\n";

if (!method_exists(CreditorInvoiceRepository::class, 'foreignWithoutVatBetween')) {
    check('FW0 the repository answers `CreditorInvoiceRepository::foreignWithoutVatBetween()`', false, 'method missing — the repository stitch of the foreign-voucher check is not in the tree');
    echo "\n{$pass} passed, {$fail} failed\n";
    exit(1);
}

check('FW1 no invoice: nothing', $q3() === []);

$abroad = $creditor('Global Tools Ltd', ['GB']);
$swiss  = $creditor('Schweizer AG', ['CH']);
$mixed  = $creditor('Doppel GmbH', ['DE', 'CH']);
$fl     = $creditor('Vaduz Anstalt', ['LI']);
$none   = $creditor('Ohne Adresse AG', []);
$blank  = $creditor('Ohne Land AG', ['']);
$lower  = $creditor('Kleinschrift GmbH', ['de', 'ch']);

$i1  = $invoice($abroad, '', '2026-07-05', ['wv']);
$i2  = $invoice($swiss, 'US-77', '2026-07-10', ['wv'], 'USD');
$i3  = $invoice($abroad, 'EU-1', '2026-08-01', ['VM', 'wv'], 'EUR');
$i4  = $invoice(null, 'GB-9', '2026-09-30', ['wv'], 'GBP');
$invoice($abroad, 'CODED', '2026-07-06', ['VM']);                         // a coded line only
$invoice($abroad, 'RC', '2026-07-07', ['wv', 'BI']);                      // a reverse-charge line beside «ohne MWST»
$invoice($abroad, 'RC-OLD', '2026-07-08', ['wv', 'BX']);                  // … a deactivated reverse-charge code counts too
$invoice($abroad, 'OPEN', '2026-07-09', ['wv'], null, 'open');            // in the journal, not posted
$invoice($abroad, 'Q2', '2026-06-30', ['wv']);                            // paid before the period
$invoice($abroad, 'Q4', '2026-10-01', ['wv'], 'USD');                     // paid after it
$invoice($swiss, 'CH', '2026-07-11', ['wv']);
$invoice($mixed, 'MIX', '2026-07-12', ['wv']);
$invoice($fl, 'FL', '2026-07-13', ['wv']);
$invoice($none, 'NONE', '2026-07-14', ['wv']);
$invoice($blank, 'BLANK', '2026-07-15', ['wv']);
$invoice($lower, 'LOWER', '2026-07-16', ['wv']);

$expected = [
    "W: Kreditorenrechnung (ohne Nr.) {$i1} · Global Tools Ltd, bezahlt am 05.07.2026: Adresse im Ausland, eine Zeile «ohne MWST» — prüfen, ob Bezugsteuer geschuldet ist.",
    'W: Kreditorenrechnung US-77 · Schweizer AG, bezahlt am 10.07.2026: in USD, eine Zeile «ohne MWST» — prüfen, ob Bezugsteuer geschuldet ist.',
    'W: Kreditorenrechnung EU-1 · Global Tools Ltd, bezahlt am 01.08.2026: in EUR, Adresse im Ausland, eine Zeile «ohne MWST» — prüfen, ob Bezugsteuer geschuldet ist.',
    'W: Kreditorenrechnung GB-9, bezahlt am 30.09.2026: in GBP, eine Zeile «ohne MWST» — prüfen, ob Bezugsteuer geschuldet ist.',
];
$got = $q3();
check('FW2 four WARNINGS, by payment date: an address abroad (no number: «(ohne Nr.) {id}»), a currency with a Swiss address, both (a coded line beside «ohne MWST»), a currency without a creditor (both period ends inclusive)',
    $got === $expected, json_encode($got, JSON_UNESCAPED_UNICODE));
check('FW3 not warned: a coded line only; a reverse-charge line beside «ohne MWST» (also a deactivated code); an unposted invoice; paid before / after the period; a Swiss, a Liechtenstein, a mixed DE + CH, no, an empty-country address; a lower-case «ch» beside «de»',
    count($got) === 4 && array_filter($got, static fn(string $m): bool => preg_match('/CODED|RC|OPEN|Q2|Q4| CH,|MIX|FL|NONE|BLANK|LOWER/', $m) === 1) === []);

for ($n = 1; $n <= 8; $n++) {
    $invoice($abroad, sprintf('X-%02d', $n), sprintf('2026-09-%02d', $n), ['wv']);
}
$got = $q3();
check('FW4 twelve such invoices: the first TEN named (by payment date and id), then ONE warning counting the rest',
    count($got) === 11 && array_slice($got, 0, 3) === array_slice($expected, 0, 3) && str_contains($got[3], 'X-01') && str_contains($got[9], 'X-07')
    && $got[10] === 'W: … und 2 weitere Kreditorenrechnungen aus dem Ausland mit einer Zeile «ohne MWST» in dieser Periode — Bezugsteuer prüfen (Kreditorenjournal, verbuchte Rechnungen).',
    json_encode($got, JSON_UNESCAPED_UNICODE));

$wire();
$asked = OpenWorkChecks::fromModules(\Z77\Core\DI::getModuleManager())->ask('vat-return', ['from' => day('2026-07-01'), 'to' => day('2026-09-30')]);
$foreign = array_filter($asked->warnings(), static fn(Finding $f): bool => str_contains($f->message, 'Bezugsteuer'));
check('FW5 the registry of the real module configs (scope `vat-return`) passes the eleven through as warnings — none blocking from this check',
    count($foreign) === 11 && array_filter($asked->blocking(), static fn(Finding $f): bool => str_contains($f->message, 'Bezugsteuer')) === [],
    count($foreign) . ' warnings');

// ─────────────────────────────────────────────────────────────────────────
// VAT return stage S5 on the creditor invoice (package W2-E): the books, then invoices through the
// real services — intake, save, «Journal verbuchen». The FW rows above are posted and stay out of
// every run. «Abrechnung ab» stays empty for R7 and LE (dates in 2024), then 1.10.2025 (lock ON).
echo "S5. The books for stage S5 (KMU chart, a liable mandator, «Bank», fiscal years 2024–2026)\n";
// FW's one unposted row (paid, incomplete) would block every run below — gone.
$db->executeStatement("DELETE l FROM creditor_invoice_line l JOIN creditor_invoice i ON i.id = l.invoice_id WHERE i.state <> 'posted'");
$db->executeStatement("DELETE FROM creditor_invoice WHERE state <> 'posted'");

$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->adoptKmuChart();
$mandator = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$mandator->mapFromArray(['name' => 'Harness AG', 'country' => 'CH', 'zip' => '8000', 'city' => 'Zürich', 'liable_to_vat' => true, 'uid' => 'CHE-116.281.710',
    'vat_method' => 'effective', 'vat_consideration' => 'agreed', 'vat_settlement_rhythm' => 'quarter']);
(new \Z77\Module\Mandator\Services\MandatorService($em))->save($mandator);
(new \Z77\Module\Receipt\Services\PaymentMethodService($em))->save(new \Z77\Module\Receipt\Entities\PaymentMethod(['code' => 'bank', 'label' => 'Bank', 'credit_account' => '1020', 'position' => 10]));
foreach (['2024', '2025', '2026'] as $fy) {
    (new \Z77\Module\Financial\Services\FiscalYearService($wire()))->open(new \Z77\Module\Financial\Entities\FiscalYear($fy, day($fy . '-01-01'), day($fy . '-12-31')));
}
check('S5-0 the books: KMU chart (1170, 1179, 4400), a liable mandator with 1179 as «Vorsteuer, noch nicht abgerechnet», «Bank» → 1020, three fiscal years',
    (int) $db->fetchOne("SELECT COUNT(*) FROM account WHERE number IN ('1170', '1179', '4200', '4400')") === 4 && $db->fetchOne('SELECT account_vat_input_pending FROM mandator') === '1179'
    && $db->fetchOne('SELECT vat_settlement_from FROM mandator') === null
    && (int) $db->fetchOne('SELECT COUNT(*) FROM fiscal_year') === 3);

/** A small valid PDF; $tag makes the bytes (and the hash) unique. */
$pdf = static fn(string $tag): string => "%PDF-1.4\n% {$tag}\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
$service  = static fn(): \Z77\Module\Creditor\Services\CreditorInvoiceService => new \Z77\Module\Creditor\Services\CreditorInvoiceService($wire(), 'Owner');
$posting  = static fn(): \Z77\Module\Creditor\Services\CreditorJournalPostingService => new \Z77\Module\Creditor\Services\CreditorJournalPostingService($wire(), 'Owner');
$profiles = static fn(): \Z77\Module\Creditor\Services\CreditorProfileService => new \Z77\Module\Creditor\Services\CreditorProfileService($wire(), 'Owner');
$head     = static fn(array $over = []): array => $over + ['creditor' => '', 'iban' => '', 'invoice_number' => '', 'invoice_date' => '', 'due_date' => '', 'reference' => '', 'message' => '', 'amount' => '', 'payment_method_code' => 'bank', 'credit_account' => '', 'paid_on' => ''];
$vOf      = static fn(int $id): int => (int) $db->fetchOne('SELECT version FROM creditor_invoice WHERE id = ?', [$id]);
$rowOf    = static fn(int $id): array => $db->fetchAssociative('SELECT * FROM creditor_invoice WHERE id = ?', [$id]) ?: [];
$linesOf  = static fn(int $id): array => $db->fetchAllAssociative('SELECT account, tax_code, without_vat, line_kind, amount FROM creditor_invoice_line WHERE invoice_id = ? ORDER BY position', [$id]);
/** A creditor as the owner opens one: a contact and its profile (no defaults). */
$newCreditor = static function (string $company) use ($wire): int {
    $em = $wire();
    $contact = new \Z77\Module\Contact\Entities\Contact(['kind' => 'organisation', 'company' => $company, 'language' => 'de']);
    (new \Z77\Module\Contact\Services\ContactService($em))->save($contact);
    $em = $wire();

    return (int) (new \Z77\Module\Creditor\Services\CreditorProfileService($em))->open($em->getRepository(\Z77\Module\Contact\Entities\Contact::class)->find($contact->getId()))->getId();
};
/** An invoice through the intake and the window's save; the learned defaults come back in $learned. */
$newInvoice = static function (string $tag, array $values, array $lines, ?array &$learned = null) use ($wire, $pdf, $service, $head): int {
    $received = (new \Z77\Module\Creditor\Services\CreditorIntakeService($wire(), null, 'Owner'))->receive($pdf($tag), $tag . '.pdf');
    $service()->update((int) $received->getId(), 1, $head($values), $lines, false, $learned);

    return (int) $received->getId();
};
/** The journal lines of one entry by its key: account, debit, credit, code, rate, base, tax. */
$entryLines = static fn(string $key): array => array_map(static fn(array $r): string => implode(' ', array_filter([$r['account'], $r['debit'] !== '0.00' ? 'D' . $r['debit'] : 'C' . $r['credit'], $r['tax_code'] !== null ? $r['tax_code'] . '/' . $r['tax_rate'] . '/' . $r['tax_base'] . '/' . $r['tax_amount'] : null], static fn($v) => $v !== null)),
    $db->fetchAllAssociative('SELECT a.number AS account, l.debit, l.credit, l.tax_code, l.tax_rate, l.tax_base, l.tax_amount FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id JOIN account a ON a.id = l.account_id WHERE e.idempotency_key = ? ORDER BY l.position', [$key]));
$entryDate = static fn(string $key): ?string => ($d = $db->fetchOne('SELECT entry_date FROM journal_entry WHERE idempotency_key = ?', [$key])) === false ? null : substr((string) $d, 0, 10);
$refusedInvoice = static function (callable $fn): array {
    $e = thrown($fn);
    return $e instanceof \Z77\Module\Creditor\Services\InvalidCreditorInvoiceException ? $e->errors : ($e === null ? ['none' => 'saved'] : ['thrown' => get_class($e) . ': ' . $e->getMessage()]);
};

// ── R7. the rate of the INVOICE date (S56-5, CREDITOR-VAT-RATE-001) ──
echo "R7. The input tax at the rate of the invoice date — the posting stays on the payment date\n";
$druckerei = $newCreditor('Druckerei R7 AG');
$r7 = $newInvoice('r7-old-rate', ['creditor' => (string) $druckerei, 'invoice_number' => 'R7-1', 'invoice_date' => '2023-12-20', 'amount' => '107.70', 'paid_on' => '2024-01-10'],
    [['text' => 'Druck', 'account' => '4400', 'tax' => 'VM', 'amount' => '']]);
$r7b = $newInvoice('r7-no-date', ['creditor' => (string) $druckerei, 'invoice_number' => 'R7-2', 'amount' => '107.70', 'paid_on' => '2024-01-10'],
    [['text' => 'Druck', 'account' => '4400', 'tax' => 'VM', 'amount' => '']]);
$r7Run = $posting()->preview(day('2024-01-10'));
$posting()->post(day('2024-01-10'), $r7Run['seen']);
check('R7 invoice of 20.12.2023 paid on 10.01.2024: posted on 10.01.2024 at 7.7 % — net 100.00, tax 7.70 (not 8.1 % = 99.63 / 8.07); without an invoice date the payment date\'s rate 8.1 %; the preview\'s VAT rows name both rates',
    $entryDate('creditor:' . $r7) === '2024-01-10' && $entryLines('creditor:' . $r7) === ['4400 D100.00 VM/770/100.00/7.70', '1170 D7.70', '1020 C107.70']
    && $entryLines('creditor:' . $r7b) === ['4400 D99.63 VM/810/99.63/8.07', '1170 D8.07', '1020 C107.70']
    && array_map(static fn(array $v): string => $v['code'] . '/' . $v['rate'] . '/' . $v['tax']->toDecimal(), $r7Run['vat']) === ['VM/770/7.70', 'VM/810/8.07'],
    json_encode([$entryLines('creditor:' . $r7), $entryLines('creditor:' . $r7b), $r7Run['blocked']], JSON_UNESCAPED_UNICODE));

// ── LE. the learned kind «nur Steuer» (S56-4, board S5-2 part 3) ──
echo "LE. A line «nur Steuer»: account ignored, the creditor learns the kind with the code\n";
$zoll  = $newCreditor('Zoll — Einfuhrsteuer');
$le1   = $newInvoice('le-first', ['creditor' => (string) $zoll, 'invoice_number' => 'EZV-1', 'invoice_date' => '2024-02-01', 'amount' => '81.00'],
    [['text' => 'Einfuhrsteuer', 'account' => '1170', 'tax' => 'VM:nur-steuer', 'amount' => '81.00']], $leLearned);
$leRow = $db->fetchAssociative('SELECT default_account, default_tax_code, default_without_vat, default_tax_only, default_payment_method_code FROM creditor_profile WHERE id = ?', [$zoll]) ?: [];
check('LE1 the first invoice of the customs: a line «VM — nur Steuer» with «1170» typed saves WITHOUT an account (z77 derives it), kind tax-only, complete; the creditor learns «VM» TOGETHER with the kind, no account, and «Bezahlt mit»',
    $linesOf($le1) === [['account' => null, 'tax_code' => 'VM', 'without_vat' => 0, 'line_kind' => 'tax-only', 'amount' => '81.00']]
    && $leLearned === ['taxCode' => 'VM', 'withoutVat' => false, 'taxOnly' => true, 'paymentMethod' => 'bank']
    && $leRow === ['default_account' => null, 'default_tax_code' => 'VM', 'default_without_vat' => 0, 'default_tax_only' => 1, 'default_payment_method_code' => 'bank']
    && $wire()->getRepository(\Z77\Module\Creditor\Entities\CreditorInvoice::class)->find($le1)->missing($wire()->getRepository(\Z77\Module\Creditor\Entities\CreditorInvoiceLine::class)->findBy(['invoice' => $le1])) === [],
    json_encode([$linesOf($le1), $leLearned, $leRow], JSON_UNESCAPED_UNICODE));
$le2 = $newInvoice('le-second', ['creditor' => (string) $zoll, 'invoice_number' => 'EZV-2', 'invoice_date' => '2024-03-01', 'amount' => '40.50'], [['text' => '', 'account' => '', 'tax' => '', 'amount' => '']]);
$leDefaults = $service()->lineDefaults($wire()->getRepository(\Z77\Module\Creditor\Entities\CreditorProfile::class)->find($zoll));
$spedition = $newCreditor('Muster Spedition AG');
$le3 = $newInvoice('le-forwarder', ['creditor' => (string) $spedition, 'invoice_number' => 'SP-1', 'invoice_date' => '2024-02-02', 'amount' => '210.72'],
    [['text' => 'Fracht', 'account' => '4400', 'tax' => 'VM', 'amount' => '129.72'], ['text' => 'Einfuhrsteuer', 'account' => '', 'tax' => 'VM:nur-steuer', 'amount' => '81.00']], $leLearned3);
check('LE2 the next invoice of the customs takes «VM — nur Steuer» from the defaults (no account); the forwarder, whose FIRST line is the freight, learns «4400 · VM» and not the kind',
    $linesOf($le2) === [['account' => null, 'tax_code' => 'VM', 'without_vat' => 0, 'line_kind' => 'tax-only', 'amount' => '40.50']]
    && $leDefaults === ['account' => null, 'taxCode' => 'VM', 'withoutVat' => false, 'taxOnly' => true]
    && $leLearned3 === ['account' => '4400', 'taxCode' => 'VM', 'withoutVat' => false, 'taxOnly' => false, 'paymentMethod' => 'bank']
    && (int) $db->fetchOne('SELECT default_tax_only FROM creditor_profile WHERE id = ?', [$spedition]) === 0,
    json_encode([$linesOf($le2), $leDefaults, $leLearned3], JSON_UNESCAPED_UNICODE));
$leOutput  = $refusedInvoice(fn() => $service()->update($le2, $vOf($le2), $head(['creditor' => (string) $zoll, 'invoice_number' => 'EZV-2', 'amount' => '40.50']), [['text' => '', 'account' => '', 'tax' => 'UN:nur-steuer', 'amount' => '40.50']]));
$leProfile = thrown(fn() => $profiles()->update($wire()->getRepository(\Z77\Module\Creditor\Entities\CreditorProfile::class)->find($spedition), (int) $db->fetchOne('SELECT version FROM creditor_profile WHERE id = ?', [$spedition]),
    ['default_tax_code' => 'UN', 'default_tax_only' => true, 'default_account' => ''], []));
check('LE3 «nur Steuer» only with an input code: on a line «UN — nur Steuer» is refused at the line, on the creditor too (the window\'s field «MWST»)',
    ($leOutput['line_0_tax'] ?? '') === '«Nur Steuer» geht nur mit einem Vorsteuer-Code (VM, VI).'
    && $leProfile instanceof \Z77\Module\Creditor\Services\InvalidCreditorProfileException && $leProfile->validator->getFieldError('default_tax') === '«Nur Steuer» geht nur mit einem Vorsteuer-Code (VM, VI).',
    json_encode([$leOutput, $leProfile?->getMessage()], JSON_UNESCAPED_UNICODE));
// the customs' invoices of 2024 stay unpaid — no run below holds them

// «Abrechnung ab» 1.10.2025 from here: the lock of the VAT accounts is ON for every date below.
$db->executeStatement("UPDATE mandator SET vat_settlement_from = '2025-10-01'");

// ── TO. case 9: the forwarder's invoice with three kinds of lines (board S5-2) ──
echo "TO. Case 9 — freight «VM», customs «ohne MWST», import VAT «VM — nur Steuer»: one entry at the payment date, 400 = 90.72\n";
$to = $newInvoice('to-forwarder', ['creditor' => (string) $spedition, 'invoice_number' => '26-0815', 'invoice_date' => '2026-10-02', 'amount' => '256.02', 'paid_on' => '2026-10-05'],
    [['text' => 'Fracht Hamburg – Zürich', 'account' => '4400', 'tax' => 'VM', 'amount' => '129.72'], ['text' => 'Zoll', 'account' => '4200', 'tax' => '-', 'amount' => '45.30'],
     ['text' => 'Einfuhrsteuer, Veranlagung 25.09.2026', 'account' => '1170', 'tax' => 'VM:nur-steuer', 'amount' => '81.00']]);
$toRun = $posting()->preview(day('2026-10-05'));
$posting()->post(day('2026-10-05'), $toRun['seen']);
$toQ4  = (new \Z77\Module\Financial\VatReturn\VatReturnService($wire()))->preview(new \Z77\Module\Financial\VatReturn\VatPeriod(day('2026-10-01'), day('2026-12-31'), \Z77\Module\Mandator\Entities\VatRhythm::Quarter));
check('TO1 posted on the payment date 05.10.2026 as ONE entry: 4400 net 120.00 [VM 8.1 % 120.00 / 9.72] + 1170 9.72; 4200 45.30 without code; 1170 81.00 [VM 8.1 % 0.00 / 81.00] (the tax-only line, account derived); Bank 256.02 — no tax entry, the invoice posted with one reference',
    $entryDate('creditor:' . $to) === '2026-10-05' && $entryLines('creditor:' . $to) === ['4400 D120.00 VM/810/120.00/9.72', '1170 D9.72', '4200 D45.30', '1170 D81.00 VM/810/0.00/81.00', '1020 C256.02']
    && $entryDate('creditor:' . $to . ':vat') === null && ($rowOf($to)['state'] ?? '') === 'posted' && $rowOf($to)['tax_entry_ref'] === null && $toRun['catchUps'] === 0,
    json_encode([$entryLines('creditor:' . $to), $toRun['blocked']], JSON_UNESCAPED_UNICODE));
check('TO2 the 4th quarter 2026: field 400 = 90.72 (9.72 + 81.00); the run\'s VAT rows: VM 120.00 / 9.72 and VM 0.00 / 81.00 summed — 120.00 / 90.72',
    $toQ4->field('400')->rounded->toDecimal() === '90.72' && array_map(static fn(array $v): string => $v['code'] . '/' . $v['base']->toDecimal() . '/' . $v['tax']->toDecimal(), $toRun['vat']) === ['VM/120.00/90.72'],
    json_encode([$toQ4->field('400')->rounded->toDecimal(), array_map(static fn(array $v): string => $v['code'] . '/' . $v['base']->toDecimal() . '/' . $v['tax']->toDecimal(), $toRun['vat'])]));

// ── IW. the import-VAT hint and warning (owner O-5) ──
echo "IW. Import VAT across two VAT periods: a grey line in «Journal verbuchen», the same warning at «MWST buchen» of the payment period\n";
$iw = $newInvoice('iw-across', ['creditor' => (string) $spedition, 'invoice_number' => '26-0816', 'invoice_date' => '2026-08-28', 'amount' => '81.00', 'paid_on' => '2026-10-06'],
    [['text' => 'Einfuhrsteuer', 'account' => '', 'tax' => 'VM:nur-steuer', 'amount' => '81.00']]);
$iwSame = $newInvoice('iw-same', ['creditor' => (string) $spedition, 'invoice_number' => '26-0817', 'invoice_date' => '2026-10-01', 'amount' => '20.00', 'paid_on' => '2026-10-06'],
    [['text' => 'Einfuhrsteuer', 'account' => '', 'tax' => 'VM:nur-steuer', 'amount' => '20.00']]);
$iwStd = $newInvoice('iw-standard', ['creditor' => (string) $spedition, 'invoice_number' => '26-0818', 'invoice_date' => '2026-08-28', 'amount' => '108.10', 'paid_on' => '2026-10-06'],
    [['text' => 'Fracht', 'account' => '4400', 'tax' => 'VM', 'amount' => '108.10']]);
$iwBefore = $newInvoice('iw-before', ['creditor' => (string) $spedition, 'invoice_number' => '25-0901', 'invoice_date' => '2025-09-20', 'amount' => '30.00', 'paid_on' => '2025-10-06'],
    [['text' => 'Einfuhrsteuer', 'account' => '', 'tax' => 'VM:nur-steuer', 'amount' => '30.00']]);
$iwSentence = 'Einfuhrsteuer der Rechnung 26-0816 (Muster Spedition AG): Rechnungsdatum im 3. Quartal 2026, abgezogen im 4. Quartal 2026 — prüfen.';
$iwRun = $posting()->preview(day('2026-10-06'));
check('IW1 the preview names the invoice whose import VAT lies across two VAT periods in a grey line (O-5 a) — not the one with both dates in one period, not a standard line, not one dated before «Abrechnung ab» (no VAT period); the run stays postable',
    $iwRun['postable'] && array_column($iwRun['hints'], 'text') === [$iwSentence] && array_column($iwRun['hints'], 'id') === [$iw],
    json_encode([$iwRun['hints'], $iwRun['blocked']], JSON_UNESCAPED_UNICODE));
$posting()->post(day('2026-10-06'), $iwRun['seen']);
$wire();
$iwQ4 = array_map(static fn(Finding $f): string => ($f->severity === Severity::Warning ? 'W: ' : 'B: ') . $f->message,
    iterator_to_array((new ImportVatPeriodCheck())->check('vat-return', ['from' => day('2026-10-01'), 'to' => day('2026-12-31')]), false));
$iwQ3 = iterator_to_array((new ImportVatPeriodCheck())->check('vat-return', ['from' => day('2026-07-01'), 'to' => day('2026-09-30')]), false);
$iwQ425 = iterator_to_array((new ImportVatPeriodCheck())->check('vat-return', ['from' => day('2025-10-01'), 'to' => day('2025-12-31')]), false);
$iwAsked = OpenWorkChecks::fromModules(\Z77\Core\DI::getModuleManager())->ask('vat-return', ['from' => day('2026-10-01'), 'to' => day('2026-12-31')]);
check('IW2 «MWST buchen» of the PAYMENT period (4th quarter 2026) names the same sentence again as a WARNING (O-5 b) — nothing in the 3rd quarter (the invoice date\'s), nothing for the invoice paid in 2025 before «Abrechnung ab»; the registry passes it as a warning, none blocking from it',
    $iwQ4 === ['W: ' . $iwSentence] && $iwQ3 === [] && $iwQ425 === []
    && count(array_filter($iwAsked->warnings(), static fn(Finding $f): bool => $f->message === $iwSentence)) === 1
    && array_filter($iwAsked->blocking(), static fn(Finding $f): bool => str_contains($f->message, 'Einfuhrsteuer')) === [],
    json_encode($iwQ4, JSON_UNESCAPED_UNICODE));

// ── NH. «Vorsteuer nachholen» (S56-7, S56-8, F-1, O-1; board S5-3 as decided) ──
echo "NH. Input tax paid in a settled period: caught up through 1179 within the calendar year, refused across it, nothing before «Abrechnung ab»\n";
// Settled: 4th quarter 2025 … 3rd quarter 2026 (live returns as rows — this harness posts no return). The running period starts 01.10.2026.
foreach ([['2025-10-01', '2025-12-31'], ['2026-01-01', '2026-03-31'], ['2026-04-01', '2026-06-30'], ['2026-07-01', '2026-09-30']] as $n => [$pFrom, $pTo]) {
    $db->insert('vat_return', [
        'period_from' => $pFrom, 'period_to' => $pTo, 'revision' => 1, 'state' => 'posted', 'live' => 1,
        'rhythm' => 'quarter', 'method' => 'effective', 'consideration' => 'agreed', 'country' => 'CH', 'form_edition' => 'harness',
        'mapping_hash' => str_repeat('a', 64), 'mapping_snapshot' => '[]', 'currency' => 'CHF', 'payable' => '0.00', 'posted_tax' => '0.00',
        'rounding_difference' => '0.00', 'posted_by' => 'harness', 'posted_at' => $pTo . ' 18:00:00', 'figures_fingerprint' => str_repeat('b', 64),
        'voucher_path' => 'data/archive/financial/vat-return/harness-' . $n . '.pdf', 'voucher_sha256' => str_repeat('c', 64), 'voucher_name' => 'harness-' . $n . '.pdf',
    ]);
}
$beispiel = $newCreditor('Druckerei Beispiel AG');
$nh1 = $newInvoice('nh-catch-up', ['creditor' => (string) $beispiel, 'invoice_number' => '2608-117', 'invoice_date' => '2026-08-14', 'amount' => '540.50', 'paid_on' => '2026-08-18'],
    [['text' => 'Prospekte', 'account' => '4400', 'tax' => 'VM', 'amount' => '']]);
$nh2 = $newInvoice('nh-tax-only', ['creditor' => (string) $zoll, 'invoice_number' => 'EZV-3', 'invoice_date' => '2026-08-30', 'amount' => '81.00', 'paid_on' => '2026-09-01'],
    [['text' => 'Einfuhrsteuer', 'account' => '', 'tax' => '', 'amount' => '']]);
$nhRun = $posting()->preview(day('2026-09-01'));
$nhLine1 = 'Rechnung 2608-117 · Druckerei Beispiel AG: 3. Quartal 2026 ist abgerechnet — Aufwand am 18.08.2026 ohne MWST, Vorsteuer 40.50 am 01.10.2026 nachgeholt.';
$nhLine2 = 'Rechnung EZV-3 · Zoll — Einfuhrsteuer: 3. Quartal 2026 ist abgerechnet — Aufwand am 01.09.2026 ohne MWST, Vorsteuer 81.00 am 01.10.2026 nachgeholt.';
check('NH1 the run up to 01.09.2026 (3rd quarter settled, running period from 01.10.2026): postable — no refusal any more; a grey line per invoice caught up (board S5-3); two of them get a second entry; the VAT rows show the input tax caught up (VM 0.00 / 121.50)',
    $nhRun['postable'] && $nhRun['blocked'] === [] && array_column($nhRun['hints'], 'text') === [$nhLine1, $nhLine2] && $nhRun['catchUps'] === 2
    && array_map(static fn(array $v): string => $v['code'] . '/' . $v['base']->toDecimal() . '/' . $v['tax']->toDecimal(), $nhRun['vat']) === ['VM/0.00/121.50'],
    json_encode([$nhRun['hints'], $nhRun['blocked'], array_map(static fn(array $v): string => $v['code'] . '/' . $v['base']->toDecimal() . '/' . $v['tax']->toDecimal(), $nhRun['vat'])], JSON_UNESCAPED_UNICODE));
$posting()->post(day('2026-09-01'), $nhRun['seen']);
$ref = static fn(string $key): string => (string) $db->fetchOne("SELECT CONCAT(f.code, '/', e.number) FROM journal_entry e JOIN fiscal_year f ON f.id = e.fiscal_year_id WHERE e.idempotency_key = ?", [$key]);
$balance1179 = (string) $db->fetchOne("SELECT COALESCE(SUM(l.debit) - SUM(l.credit), 0) FROM journal_line l JOIN account a ON a.id = l.account_id WHERE a.number = '1179'");
check('NH2 case 10 B: the expense on 18.08.2026 WITHOUT a code — 4400 500.00, 1179 40.50, Bank 540.50 (key creditor:{id}); on 01.10.2026 the tax entry — 1170 40.50 [VM 8.1 % 0.00 / 40.50] against 1179 (key creditor:{id}:vat), text «… · Vorsteuer nachgeholt»; the import VAT the same way (1179 81.00 / Bank, then 1170 / 1179); 1179 at 0.00 after the run; both references on the invoice',
    $entryDate('creditor:' . $nh1) === '2026-08-18' && $entryLines('creditor:' . $nh1) === ['4400 D500.00', '1179 D40.50', '1020 C540.50']
    && $entryDate('creditor:' . $nh1 . ':vat') === '2026-10-01' && $entryLines('creditor:' . $nh1 . ':vat') === ['1170 D40.50 VM/810/0.00/40.50', '1179 C40.50']
    && $entryLines('creditor:' . $nh2) === ['1179 D81.00', '1020 C81.00'] && $entryLines('creditor:' . $nh2 . ':vat') === ['1170 D81.00 VM/810/0.00/81.00', '1179 C81.00']
    && (string) $db->fetchOne('SELECT text FROM journal_entry WHERE idempotency_key = ?', ['creditor:' . $nh1 . ':vat']) === 'Rechnung 2608-117 · Druckerei Beispiel AG · Vorsteuer nachgeholt'
    && in_array($balance1179, ['0', '0.00'], true)
    && ($rowOf($nh1)['ledger_entry_ref'] ?? '') === $ref('creditor:' . $nh1) && ($rowOf($nh1)['tax_entry_ref'] ?? '') === $ref('creditor:' . $nh1 . ':vat') && ($rowOf($nh1)['state'] ?? '') === 'posted',
    json_encode([$entryLines('creditor:' . $nh1), $entryLines('creditor:' . $nh1 . ':vat'), $entryLines('creditor:' . $nh2), $entryLines('creditor:' . $nh2 . ':vat'), $balance1179, $rowOf($nh1)['tax_entry_ref'] ?? null], JSON_UNESCAPED_UNICODE));

$alt  = $newCreditor('Altlieferant AG');
$nh3  = $newInvoice('nh-other-year', ['creditor' => (string) $alt, 'invoice_number' => 'NH-3', 'invoice_date' => '2025-11-05', 'amount' => '108.10', 'paid_on' => '2025-11-10'],
    [['text' => 'Beratung', 'account' => '4400', 'tax' => 'VM', 'amount' => '']]);
$nh3Sentence = 'Rechnung NH-3 (Altlieferant AG): Vorsteuer aus 2025 wird nicht nachgeholt — die Rechnung «ohne MWST» verbuchen und die Vorsteuer als Korrekturbuchung zu 4. Quartal 2025 erfassen.';
$nh3Run     = $posting()->preview(day('2025-11-10'));
$nh3Entries = (int) $db->fetchOne('SELECT COUNT(*) FROM journal_entry');
$nh3Refused = thrown(fn() => $posting()->post(day('2025-11-10'), $nh3Run['seen']));
check('NH3 across the year (paid 10.11.2025 in the settled 4th quarter 2025, the running period starts 01.10.2026): the run refuses with the owner\'s sentence (F-1), «Vorsteuer aus 2025 …», nothing posted, no transit account touched',
    !$nh3Run['postable'] && $nh3Run['blocked'] === [['id' => $nh3, 'reason' => $nh3Sentence]] && $nh3Refused instanceof \Z77\Module\Creditor\Services\CreditorRefusedException
    && $nh3Refused->getMessage() === $nh3Sentence && (int) $db->fetchOne('SELECT COUNT(*) FROM journal_entry') === $nh3Entries && ($rowOf($nh3)['state'] ?? '') === 'open',
    json_encode([$nh3Run['blocked'], $nh3Refused?->getMessage()], JSON_UNESCAPED_UNICODE));
$service()->update($nh3, $vOf($nh3), $head(['creditor' => (string) $alt, 'invoice_number' => 'NH-3', 'invoice_date' => '2025-11-05', 'amount' => '108.10', 'paid_on' => '2025-11-10']),
    [['text' => 'Beratung', 'account' => '4400', 'tax' => '-', 'amount' => '108.10']]);
$nh3Plain = $posting()->preview(day('2025-11-10'));
$posting()->post(day('2025-11-10'), $nh3Plain['seen']);
check('NH4 the way out: the line switched to «ohne MWST», the same run posts the expense uncoded in the settled period (the freeze holds tax lines only) — one entry, no second',
    $nh3Plain['postable'] && $entryLines('creditor:' . $nh3) === ['4400 D108.10', '1020 C108.10'] && $entryDate('creditor:' . $nh3 . ':vat') === null
    && ($rowOf($nh3)['state'] ?? '') === 'posted' && array_key_exists('tax_entry_ref', $rowOf($nh3)) && $rowOf($nh3)['tax_entry_ref'] === null,
    json_encode([$nh3Plain['blocked'], $entryLines('creditor:' . $nh3)], JSON_UNESCAPED_UNICODE));

$nh5 = $newInvoice('nh-before-start', ['creditor' => (string) $alt, 'invoice_number' => 'NH-5', 'invoice_date' => '2025-09-15', 'amount' => '108.10', 'paid_on' => '2025-09-20'],
    [['text' => 'Gründung', 'account' => '4400', 'tax' => 'VM', 'amount' => '']]);
$nh5Sentence = 'Rechnung NH-5 (Altlieferant AG): vor dem 01.10.2025 wird in z77 keine MWST abgerechnet — eine Buchung mit MWST-Code geht dort nicht; die Rechnung «ohne MWST» verbuchen';
$nh5Run      = $posting()->preview(day('2025-09-20'));
$nh5Refused  = thrown(fn() => $posting()->post(day('2025-09-20'), $nh5Run['seen']));
check('NH5 before «Abrechnung ab» nothing is caught up (S56-9, O-7): the invoice with VAT paid on 20.09.2025 is blocked with financial\'s sentence and the way out «die Rechnung «ohne MWST» verbuchen»; posted anyway, the ledger refuses the same way, nothing posted',
    $nh5Run['blocked'] === [['id' => $nh5, 'reason' => $nh5Sentence . '.']] && $nh5Run['catchUps'] === 0
    && $nh5Refused instanceof \Z77\Module\Creditor\Services\CreditorRefusedException && $nh5Refused->getMessage() === $nh5Sentence . ' — nichts wurde verbucht.'
    && ($rowOf($nh5)['state'] ?? '') === 'open',
    json_encode([$nh5Run['blocked'], $nh5Refused?->getMessage()], JSON_UNESCAPED_UNICODE));
$service()->delete($nh5, $vOf($nh5));

// ── VL. the lock of the VAT accounts passes the creditor's generated entries (ADR-042 addendum 2 of 2026-11-25, point 7) ──
echo "VL. The lock is ON («Abrechnung ab» 01.10.2025) and every entry above passed it; the picker and the line check ask the invoice's day\n";
$locked = static fn(string $ymd): array => array_map('strval', array_keys((new \Z77\Module\Financial\Services\VatAccountMap($wire()))->lockedAccounts(day($ymd))));
check('VL1 1170 is locked on every posting date above (05.10.2026, 18.08.2026, 01.10.2026) — yet the single entry with the tax-only line on 1170, the expense entries on 1179 and the tax entries (a coded line on 1170 against 1179) were all posted: the lock takes them',
    in_array('1170', $locked('2026-10-05'), true) && in_array('1170', $locked('2026-08-18'), true) && in_array('1170', $locked('2026-10-01'), true) && !in_array('1179', $locked('2026-10-01'), true)
    && $entryLines('creditor:' . $to) !== [] && $entryLines('creditor:' . $nh1 . ':vat') !== [] && $entryLines('creditor:' . $nh2 . ':vat') !== [],
    json_encode([$locked('2026-10-05'), $locked('2026-08-18'), $locked('2026-10-01')]));
$vlLater  = $newInvoice('vl-later', ['creditor' => (string) $alt, 'invoice_number' => 'VL-1', 'invoice_date' => '2025-09-01', 'amount' => '50.00', 'paid_on' => '2025-09-05'],
    [['text' => 'Altbestand', 'account' => '1170', 'tax' => '-', 'amount' => '50.00']]);
$vlRefused = $refusedInvoice(fn() => $service()->update($vlLater, $vOf($vlLater), $head(['creditor' => (string) $alt, 'invoice_number' => 'VL-1', 'invoice_date' => '2025-09-01', 'amount' => '50.00', 'paid_on' => '2025-10-05']),
    [['text' => 'Altbestand', 'account' => '1170', 'tax' => '-', 'amount' => '50.00']]));
check('VL2 the line check asks the lock for the day the invoice is booked on («bezahlt am», else the invoice date — not today): 1170 on an invoice paid 05.09.2025 (before «Abrechnung ab») saves; moved to 05.10.2025 it is refused at the line with receipt\'s sentence',
    $linesOf($vlLater)[0]['account'] === '1170'
    && ($vlRefused['line_0_account'] ?? '') === 'Konto 1170 ist ein MWST-Konto — die Steuer schreibt z77 aus dem MWST-Code. Ein anderes Konto wählen und den MWST-Code setzen.'
    && \Z77\Module\Creditor\Services\CreditorInvoiceService::lockDate(null, day('2025-09-01'))->format('Y-m-d') === '2025-09-01'
    && \Z77\Module\Creditor\Services\CreditorInvoiceService::lockDate(day('2025-10-05'), day('2025-09-01'))->format('Y-m-d') === '2025-10-05',
    json_encode([$linesOf($vlLater), $vlRefused], JSON_UNESCAPED_UNICODE));
$service()->delete($vlLater, $vOf($vlLater));

// ── RC. Reverse charge on a creditor invoice (package W3-B; ADR-041 addendum 2026-11-25 point 10, ADR-059
//    addendum 2026-11-25 and amendment b; owner S56-11 … S56-14, O-2, O-3, O-4, O-8, D-4) — the decision table
//    of the master plan as corrected by its delta. Settled: 4th quarter 2025 … 3rd quarter 2026 (NH's rows);
//    the running period is the 4th quarter 2026. BI = 8.1 %, the pair on 1171 / 2200. ──
echo "RC. Reverse charge: the tax at the INVOICE date (also before the payment), the expense at «bezahlt am»\n";
$pixel   = $newCreditor('Pixelsoft Inc.');
$rcKey   = static fn(int $id): string => 'creditor:' . $id . ':vat';
$rcLine  = static fn(string $amount = '', string $tax = 'BI', string $text = 'Software-Abo'): array => ['text' => $text, 'account' => '6570', 'tax' => $tax, 'amount' => $amount];
$rcHead  = static fn(string $number, array $over = []): array => $over + ['creditor' => (string) $pixel, 'invoice_number' => $number];
$rcOf    = static fn(int $id): \Z77\Module\Creditor\Entities\CreditorInvoice => $wire()->getRepository(\Z77\Module\Creditor\Entities\CreditorInvoice::class)->find($id);
$rcCodes = (new \Z77\Module\Creditor\Posting\ReverseChargeCodes($wire()))->codes();
$rcQ4    = static function () use ($wire): array {
    $wire();

    return array_map(static fn(Finding $f): string => ($f->severity === Severity::Warning ? 'W: ' : 'B: ') . $f->message,
        iterator_to_array((new \Z77\Module\Creditor\Close\ReverseChargeEntryCheck())->check('vat-return', ['from' => day('2026-10-01'), 'to' => day('2026-12-31')]), false));
};
$rcNo = static fn(string $key): bool => $db->fetchOne('SELECT COUNT(*) FROM journal_entry WHERE idempotency_key = ?', [$key]) == 0;

check('RC0 the migration `cred Version20261128110000` added `creditor_invoice.estv_rate` DECIMAL(12,6) NULL; the reverse-charge codes are BM, BI, BM2, BI2 (and the harness\'s own BX)',
    $db->fetchOne("SHOW COLUMNS FROM creditor_invoice LIKE 'estv_rate'") !== false && array_diff(['BM', 'BI', 'BM2', 'BI2'], $rcCodes) === [],
    json_encode($rcCodes));

// Phase A — one run: same day, two dates, unpaid in USD with the ESTV rate, paid in a settled quarter (RC only).
$rc1  = $newInvoice('rc-same-day', $rcHead('RC-1', ['invoice_date' => '2026-11-02', 'amount' => '100.00', 'paid_on' => '2026-11-02']), [$rcLine()], $rcLearned);
$rc2  = $newInvoice('rc-two-dates', $rcHead('RC-2', ['invoice_date' => '2026-10-15', 'amount' => '100.00', 'paid_on' => '2026-11-10']), [$rcLine()]);
$rc3  = $newInvoice('rc-unpaid-usd', $rcHead('RC-3', ['currency' => 'USD', 'original_amount' => '100.00']), [$rcLine()]);
$rc15 = $newInvoice('rc-paid-settled', $rcHead('RC-15', ['invoice_date' => '2026-10-05', 'amount' => '50.00', 'paid_on' => '2026-09-15']), [$rcLine()]);
$rc3Missing = $rcOf($rc3)->missing($wire()->getRepository(\Z77\Module\Creditor\Entities\CreditorInvoiceLine::class)->findBy(['invoice' => $rc3]), $rcCodes);
$rcChf      = $refusedInvoice(fn() => $service()->update($rc1, $vOf($rc1), $head($rcHead('RC-1', ['invoice_date' => '2026-11-02', 'amount' => '100.00', 'paid_on' => '2026-11-02', 'estv_rate' => '0.9'])), [$rcLine()]));
check('RC1 a line «BI» saves on a creditor invoice (the select\'s group «Bezugsteuer», opt-in) and the creditor learns it as its default; a USD invoice with a reverse-charge line lacks «Rechnungsdatum» and «ESTV-Kurs» (missing()); an «ESTV-Kurs» on a CHF invoice is refused',
    $linesOf($rc1)[0]['tax_code'] === 'BI' && ($rcLearned['taxCode'] ?? null) === 'BI'
    && in_array('Rechnungsdatum', $rc3Missing, true) && in_array('ESTV-Kurs', $rc3Missing, true)
    && ($rcChf['estv_rate'] ?? '') === 'ESTV-Kurs: nur bei einer Rechnung in fremder Währung — das Feld leeren.',
    json_encode([$linesOf($rc1), $rcLearned, $rc3Missing, $rcChf], JSON_UNESCAPED_UNICODE));
$service()->update($rc3, $vOf($rc3), $head($rcHead('RC-3', ['invoice_date' => '2026-11-05'])), [$rcLine()]);
$rcA0 = $posting()->preview(day('2026-11-10'));
check('RC2 the run up to 10.11.2026 takes the UNPAID RC-3 for its tax entry (`taxSeen`) — without «ESTV-Kurs» it blocks the run: «… es fehlt: ESTV-Kurs.»',
    array_column($rcA0['taxSeen'], 'id') === [$rc3] && !$rcA0['postable'] && $rcA0['blocked'] === [['id' => $rc3, 'reason' => 'Rechnung RC-3 (Pixelsoft Inc.): es fehlt: ESTV-Kurs.']],
    json_encode([$rcA0['taxSeen'], $rcA0['blocked']], JSON_UNESCAPED_UNICODE));
$service()->update($rc3, $vOf($rc3), $head($rcHead('RC-3', ['invoice_date' => '2026-11-05', 'estv_rate' => '0.8850'])), [$rcLine()]);
$rcA  = $posting()->preview(day('2026-11-10'));
$rcAp = $posting()->post(day('2026-11-10'), $rcA['seen'], $rcA['taxSeen']);
$rcHints = array_column($rcA['hints'], 'text');
check('RC3 the run is postable: the paid RC-1, RC-2, RC-15 and the tax entry of the unpaid RC-3; the grey lines name the reverse charge at the invoice date (board S6-3), the unpaid one «Aufwand bei der Zahlung»; the protocol counts the paid ones',
    $rcA['postable'] && array_column($rcA['seen'], 'id') === [$rc15, $rc1, $rc2] && $rcA['reverseCharges'] === 3 && $rcAp?->getInvoiceCount() === 3
    && in_array('Rechnung RC-2 · Pixelsoft Inc.: Bezugsteuer 8.10 am Rechnungsdatum 15.10.2026, Aufwand 100.00 am Zahldatum 10.11.2026.', $rcHints, true)
    && in_array('Rechnung RC-3 · Pixelsoft Inc.: Bezugsteuer 7.17 am Rechnungsdatum 05.11.2026, Aufwand bei der Zahlung.', $rcHints, true)
    && in_array('Rechnung RC-15 · Pixelsoft Inc.: Bezugsteuer 4.05 am Rechnungsdatum 05.10.2026, Aufwand 50.00 am Zahldatum 15.09.2026.', $rcHints, true)
    && !in_array(true, array_map(static fn(string $h): bool => str_contains($h, 'RC-1 '), $rcHints), true),
    json_encode([$rcA['seen'], $rcA['blocked'], $rcHints], JSON_UNESCAPED_UNICODE));
check('RC4 same day (invoice = payment 02.11.2026): ONE entry in the ADR shape — 6570 100.00 [BI 8.1 % 100.00 / 8.10] · 1171 8.10 · 2200 8.10 · Bank 100.00; no tax entry',
    $entryDate('creditor:' . $rc1) === '2026-11-02' && $entryLines('creditor:' . $rc1) === ['6570 D100.00 BI/810/100.00/8.10', '1171 D8.10', '2200 C8.10', '1020 C100.00']
    && $rcNo($rcKey($rc1)) && ($rowOf($rc1)['state'] ?? '') === 'posted' && $rowOf($rc1)['tax_entry_ref'] === null,
    json_encode($entryLines('creditor:' . $rc1)));
check('RC5 invoice 15.10. / paid 10.11.2026: the pair ALONE on 15.10.2026 — 1171 8.10 [BI 8.1 % 100.00 / 8.10] against 2200 (key creditor:{id}:vat, text «… · Bezugsteuer») —, the expense WITHOUT a code on 10.11.2026; both references on the invoice',
    $entryDate($rcKey($rc2)) === '2026-10-15' && $entryLines($rcKey($rc2)) === ['1171 D8.10 BI/810/100.00/8.10', '2200 C8.10']
    && $entryDate('creditor:' . $rc2) === '2026-11-10' && $entryLines('creditor:' . $rc2) === ['6570 D100.00', '1020 C100.00']
    && (string) $db->fetchOne('SELECT text FROM journal_entry WHERE idempotency_key = ?', [$rcKey($rc2)]) === 'Rechnung RC-2 · Pixelsoft Inc. · Bezugsteuer'
    && ($rowOf($rc2)['ledger_entry_ref'] ?? '') === $ref('creditor:' . $rc2) && ($rowOf($rc2)['tax_entry_ref'] ?? '') === $ref($rcKey($rc2)),
    json_encode([$entryLines($rcKey($rc2)), $entryLines('creditor:' . $rc2)]));
check('RC6 UNPAID at the run (USD 100.00, ESTV-Kurs 0.8850): the pair only, on the invoice date 05.11.2026 — base 88.50, tax 7.17 —, the invoice stays in the journal, unpaid, with its tax reference; no expense entry',
    $entryDate($rcKey($rc3)) === '2026-11-05' && $entryLines($rcKey($rc3)) === ['1171 D7.17 BI/810/88.50/7.17', '2200 C7.17'] && $rcNo('creditor:' . $rc3)
    && ($rowOf($rc3)['state'] ?? '') === 'open' && $rowOf($rc3)['paid_on'] === null && ($rowOf($rc3)['tax_entry_ref'] ?? '') === $ref($rcKey($rc3)),
    json_encode([$entryLines($rcKey($rc3)), $rowOf($rc3)['tax_entry_ref'] ?? null]));
check('RC7 the input-tax rule never sees a reverse-charge line (O-4): RC-15, paid 15.09.2026 in the settled 3rd quarter, is no catch-up and no refusal — the expense uncoded on 15.09.2026, the pair on its invoice date 05.10.2026; 1179 untouched',
    $entryLines('creditor:' . $rc15) === ['6570 D50.00', '1020 C50.00'] && $entryDate('creditor:' . $rc15) === '2026-09-15'
    && $entryDate($rcKey($rc15)) === '2026-10-05' && $entryLines($rcKey($rc15)) === ['1171 D4.05 BI/810/50.00/4.05', '2200 C4.05']
    && in_array((string) $db->fetchOne("SELECT COALESCE(SUM(l.debit) - SUM(l.credit), 0) FROM journal_line l JOIN account a ON a.id = l.account_id WHERE a.number = '1179'"), ['0', '0.00'], true),
    json_encode([$entryLines('creditor:' . $rc15), $entryLines($rcKey($rc15))]));

// Phase B — the tax entry follows the invoice: amended, retracted on delete and on «ohne MWST», the expense at the payment.
$rc3Number = (string) $db->fetchOne('SELECT number FROM journal_entry WHERE idempotency_key = ?', [$rcKey($rc3)]);
$service()->update($rc3, $vOf($rc3), $head($rcHead('RC-3', ['invoice_date' => '2026-11-05', 'estv_rate' => '0.9000'])), [$rcLine()]);
check('RC8 changed afterwards (ESTV-Kurs 0.9000): the tax entry is re-posted IN PLACE — the same number, base 90.00, tax 7.29; the invoice keeps its reference',
    (string) $db->fetchOne('SELECT number FROM journal_entry WHERE idempotency_key = ?', [$rcKey($rc3)]) === $rc3Number
    && $entryLines($rcKey($rc3)) === ['1171 D7.29 BI/810/90.00/7.29', '2200 C7.29'] && ($rowOf($rc3)['tax_entry_ref'] ?? '') === $ref($rcKey($rc3)),
    json_encode($entryLines($rcKey($rc3))));
$rc6 = $newInvoice('rc-delete', $rcHead('RC-6', ['invoice_date' => '2026-11-06', 'amount' => '50.00']), [$rcLine()]);
$rc7 = $newInvoice('rc-switch', $rcHead('RC-7', ['invoice_date' => '2026-11-07', 'amount' => '50.00']), [$rcLine()]);
$rcB = $posting()->preview(day('2026-11-10'));
$rcBp = $posting()->post(day('2026-11-10'), $rcB['seen'], $rcB['taxSeen']);
$rcPosted67 = !$rcNo($rcKey($rc6)) && !$rcNo($rcKey($rc7));
$service()->delete($rc6, $vOf($rc6));
$service()->update($rc7, $vOf($rc7), $head($rcHead('RC-7', ['invoice_date' => '2026-11-07', 'amount' => '50.00'])), [$rcLine('50.00', '-')]);
check('RC9 two more unpaid invoices: a run with ONLY tax entries posts both and answers no protocol; deleted → its tax entry RETRACTED; the line switched to «ohne MWST» → retracted, the reference cleared, the invoice stays',
    $rcB['postable'] && $rcB['seen'] === [] && array_column($rcB['taxSeen'], 'id') === [$rc6, $rc7] && $rcBp === null && $rcPosted67
    && $rcNo($rcKey($rc6)) && $rowOf($rc6) === [] && $rcNo($rcKey($rc7)) && $rowOf($rc7)['tax_entry_ref'] === null && ($rowOf($rc7)['state'] ?? '') === 'open',
    json_encode([$rcB['taxSeen'], $rcB['blocked']], JSON_UNESCAPED_UNICODE));
$service()->update($rc3, $vOf($rc3), $head($rcHead('RC-3', ['invoice_date' => '2026-11-05', 'estv_rate' => '0.9000', 'amount' => '90.12', 'paid_on' => '2026-11-20'])), [$rcLine()]);
$service()->delete($rc7, $vOf($rc7));
$rcC = $posting()->preview(day('2026-11-20'));
$posting()->post(day('2026-11-20'), $rcC['seen'], $rcC['taxSeen']);
check('RC10 paid later (20.11.2026, CHF 90.12 from the card): the run posts the EXPENSE only, without a code — the tax entry of 05.11.2026 stands unchanged; the invoice posted with both references',
    $rcC['postable'] && array_column($rcC['seen'], 'id') === [$rc3] && $rcC['taxSeen'] === [] && $entryLines('creditor:' . $rc3) === ['6570 D90.12', '1020 C90.12']
    && $entryDate('creditor:' . $rc3) === '2026-11-20' && $entryLines($rcKey($rc3)) === ['1171 D7.29 BI/810/90.00/7.29', '2200 C7.29']
    && ($rowOf($rc3)['state'] ?? '') === 'posted' && ($rowOf($rc3)['tax_entry_ref'] ?? '') === $ref($rcKey($rc3)) && ($rowOf($rc3)['ledger_entry_ref'] ?? '') === $ref('creditor:' . $rc3),
    json_encode([$rcC['seen'], $rcC['blocked'], $entryLines('creditor:' . $rc3)], JSON_UNESCAPED_UNICODE));

// Phase C — the refusals and the blocking open point.
$rc9  = $newInvoice('rc-settled-same-year', $rcHead('RC-9', ['invoice_date' => '2026-08-20', 'amount' => '100.00', 'paid_on' => '2026-11-21']), [$rcLine()]);
$rc10 = $newInvoice('rc-settled-other-year', $rcHead('RC-10', ['invoice_date' => '2025-11-15', 'amount' => '100.00']), [$rcLine()]);
$rc11 = $newInvoice('rc-before-start', $rcHead('RC-11', ['invoice_date' => '2025-09-15', 'amount' => '100.00']), [$rcLine()]);
$rc12 = $newInvoice('rc-open-point', $rcHead('RC-12', ['invoice_date' => '2026-11-12', 'amount' => '40.00']), [$rcLine()]);
$rc13 = $newInvoice('rc-two-tax-days', $rcHead('RC-13', ['invoice_date' => '2026-10-15', 'amount' => '208.10', 'paid_on' => '2026-09-10']),
    [['text' => 'Druck', 'account' => '4400', 'tax' => 'VM', 'amount' => '108.10'], $rcLine('100.00')]);
$rcQ4Before = $rcQ4();
$rcD        = $posting()->preview(day('2026-11-21'));
$rcDEntries = (int) $db->fetchOne('SELECT COUNT(*) FROM journal_entry');
$rcDRefused = thrown(fn() => $posting()->post(day('2026-11-21'), $rcD['seen'], $rcD['taxSeen']));
$rcReasons  = array_column($rcD['blocked'], 'reason', 'id');
check('RC11 invoice date in a POSTED period — same calendar year (3rd quarter 2026) or not (4th quarter 2025) — refused with the owner\'s sentence (O-8), paid or not; nothing caught up, nothing of the run posted',
    ($rcReasons[$rc9] ?? '') === 'Rechnung RC-9 (Pixelsoft Inc.): Die Bezugsteuer gehört in die Periode 3. Quartal 2026, sie ist abgerechnet — die Rechnung «ohne MWST» verbuchen und die Bezugsteuer als Korrekturbuchung zu 3. Quartal 2026 erfassen.'
    && ($rcReasons[$rc10] ?? '') === 'Rechnung RC-10 (Pixelsoft Inc.): Die Bezugsteuer gehört in die Periode 4. Quartal 2025, sie ist abgerechnet — die Rechnung «ohne MWST» verbuchen und die Bezugsteuer als Korrekturbuchung zu 4. Quartal 2025 erfassen.'
    && !$rcD['postable'] && $rcDRefused instanceof \Z77\Module\Creditor\Services\CreditorRefusedException && (int) $db->fetchOne('SELECT COUNT(*) FROM journal_entry') === $rcDEntries,
    json_encode([$rcD['blocked'], $rcDRefused?->getMessage()], JSON_UNESCAPED_UNICODE));
check('RC12 before «Abrechnung ab» (15.09.2025): «ohne MWST» — financial\'s sentence and the way out; R6: input tax caught up on 01.10.2026 and the pair on 15.10.2026 → refused, «als zwei Rechnungen erfassen»',
    ($rcReasons[$rc11] ?? '') === 'Rechnung RC-11 (Pixelsoft Inc.): vor dem 01.10.2025 wird in z77 keine MWST abgerechnet — eine Buchung mit MWST-Code geht dort nicht; die Rechnung «ohne MWST» verbuchen.'
    && ($rcReasons[$rc13] ?? '') === 'Rechnung RC-13 (Pixelsoft Inc.): Vorsteuer nachholen am 01.10.2026 und Bezugsteuer am 15.10.2026 — eine Rechnung hat nur eine Steuerbuchung; als zwei Rechnungen erfassen (Bezugsteuer und Vorsteuer getrennt).'
    && !isset($rcReasons[$rc12]) && in_array($rc12, array_column($rcD['taxSeen'], 'id'), true),
    json_encode($rcD['blocked'], JSON_UNESCAPED_UNICODE));
check('RC13 the BLOCKING open point of «MWST buchen» looks only at the period being posted: the 4th quarter 2026 names RC-13 (refused by R6) and RC-12 (invoice dates 15.10. / 12.11.2026, no tax entry) — not RC-9 / RC-10 / RC-11, whose invoice dates lie in posted periods or before the start',
    $rcQ4Before === ['B: Rechnung RC-13 · Pixelsoft Inc., Rechnungsdatum 15.10.2026: Bezugsteuer noch nicht verbucht — im Kreditorenjournal «Journal verbuchen».',
        'B: Rechnung RC-12 · Pixelsoft Inc., Rechnungsdatum 12.11.2026: Bezugsteuer noch nicht verbucht — im Kreditorenjournal «Journal verbuchen».'],
    json_encode($rcQ4Before, JSON_UNESCAPED_UNICODE));
// The ways out: RC-9 «ohne MWST», RC-10 / RC-11 deleted, RC-13 split differently (its invoice date on the catch-up day).
$service()->update($rc9, $vOf($rc9), $head($rcHead('RC-9', ['invoice_date' => '2026-08-20', 'amount' => '100.00', 'paid_on' => '2026-11-21'])), [$rcLine('100.00', '-')]);
$service()->delete($rc10, $vOf($rc10));
$service()->delete($rc11, $vOf($rc11));
$service()->update($rc13, $vOf($rc13), $head($rcHead('RC-13', ['invoice_date' => '2026-10-01', 'amount' => '208.10', 'paid_on' => '2026-09-10'])),
    [['text' => 'Druck', 'account' => '4400', 'tax' => 'VM', 'amount' => '108.10'], $rcLine('100.00')]);
$rcE = $posting()->preview(day('2026-11-21'));
$posting()->post(day('2026-11-21'), $rcE['seen'], $rcE['taxSeen']);
check('RC14 the way out of O-8: the line switched to «ohne MWST», the next run posts the expense uncoded (no tax entry); the open point of the 4th quarter is gone once the run posted RC-12\'s tax entry',
    $rcE['postable'] && $entryLines('creditor:' . $rc9) === ['6570 D100.00', '1020 C100.00'] && $rcNo($rcKey($rc9))
    && $entryLines($rcKey($rc12)) === ['1171 D3.24 BI/810/40.00/3.24', '2200 C3.24'] && $rcQ4() === [],
    json_encode([$rcE['blocked'], $entryLines('creditor:' . $rc9), $rcQ4()], JSON_UNESCAPED_UNICODE));
check('RC15 input tax caught up AND the pair on the SAME day (01.10.2026): ONE tax entry with both — 1170 8.10 [VM 0.00 / 8.10] / 1179, 1171 8.10 [BI 100.00 / 8.10] / 2200; the expense on 10.09.2026 without codes',
    $entryLines('creditor:' . $rc13) === ['4400 D100.00', '1179 D8.10', '6570 D100.00', '1020 C208.10']
    && $entryDate($rcKey($rc13)) === '2026-10-01' && $entryLines($rcKey($rc13)) === ['1170 D8.10 VM/810/0.00/8.10', '1179 C8.10', '1171 D8.10 BI/810/100.00/8.10', '2200 C8.10'],
    json_encode([$entryLines('creditor:' . $rc13), $entryLines($rcKey($rc13))]));

// Phase D — after «Abrechnung bis» (owner D-4): no tax, a grey line, nothing required.
$db->executeStatement("UPDATE mandator SET vat_settlement_until = '2026-11-10'");
$rc14  = $newInvoice('rc-after-end', $rcHead('RC-14', ['invoice_date' => '2026-11-15', 'amount' => '100.00', 'paid_on' => '2026-11-16']), [$rcLine()]);
$rc14b = $newInvoice('rc-after-end-unpaid', $rcHead('RC-14B', ['invoice_date' => '2026-11-15', 'amount' => '30.00']), [$rcLine()]);
$rcF   = $posting()->preview(day('2026-11-16'));
$posting()->post(day('2026-11-16'), $rcF['seen'], $rcF['taxSeen']);
$db->executeStatement('UPDATE mandator SET vat_settlement_until = NULL');
check('RC16 after «Abrechnung bis» (10.11.2026): the invoice of 15.11.2026 is posted WITHOUT reverse-charge tax, a grey line says so (D-4); the unpaid one is not taken for a tax entry',
    in_array('Rechnung RC-14 · Pixelsoft Inc.: Rechnungsdatum nach «Abrechnung bis» — z77 bucht keine Bezugsteuer; allenfalls ausserhalb von z77 melden.', array_column($rcF['hints'], 'text'), true)
    && $entryLines('creditor:' . $rc14) === ['6570 D100.00', '1020 C100.00'] && $rcNo($rcKey($rc14)) && $rcF['taxSeen'] === [],
    json_encode([$rcF['hints'], $rcF['taxSeen'], $entryLines('creditor:' . $rc14)], JSON_UNESCAPED_UNICODE));
$service()->delete($rc14b, $vOf($rc14b));

// Phase E — the period of the tax entry settled, then changed → refused.
$rc8 = $newInvoice('rc-settled-later', $rcHead('RC-8', ['invoice_date' => '2026-11-03', 'amount' => '60.00']), [$rcLine()]);
$rcG = $posting()->preview(day('2026-11-21'));
$posting()->post(day('2026-11-21'), $rcG['seen'], $rcG['taxSeen']);
$rc8Ref = (string) ($rowOf($rc8)['tax_entry_ref'] ?? '');
$db->insert('vat_return', [
    'period_from' => '2026-10-01', 'period_to' => '2026-12-31', 'revision' => 1, 'state' => 'posted', 'live' => 1,
    'rhythm' => 'quarter', 'method' => 'effective', 'consideration' => 'agreed', 'country' => 'CH', 'form_edition' => 'harness',
    'mapping_hash' => str_repeat('a', 64), 'mapping_snapshot' => '[]', 'currency' => 'CHF', 'payable' => '0.00', 'posted_tax' => '0.00',
    'rounding_difference' => '0.00', 'posted_by' => 'harness', 'posted_at' => '2026-11-22 18:00:00', 'figures_fingerprint' => str_repeat('b', 64),
    'voucher_path' => 'data/archive/financial/vat-return/harness-rc.pdf', 'voucher_sha256' => str_repeat('c', 64), 'voucher_name' => 'harness-rc.pdf',
]);
$rcSettled  = 'Bezugsteuer schon verbucht (Buchung ' . $rc8Ref . '): Die MWST-Periode 4. Quartal 2026 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr.';
$rc8Change  = $refusedInvoice(fn() => $service()->update($rc8, $vOf($rc8), $head($rcHead('RC-8', ['invoice_date' => '2026-11-03', 'amount' => '70.00'])), [$rcLine()]));
$rc8Same    = $refusedInvoice(fn() => $service()->update($rc8, $vOf($rc8), $head($rcHead('RC-8', ['invoice_date' => '2026-11-03', 'amount' => '60.00', 'due_date' => '2026-12-03'])), [$rcLine()]));
$rc8Delete  = thrown(fn() => $service()->delete($rc8, $vOf($rc8)));
check('RC17 the 4th quarter 2026 posted after the tax entry: a change of the tax facts is REFUSED (nothing saved, the sentence under the lines); a save that leaves them unchanged passes; deleting is refused, the invoice stays',
    $rc8Ref !== '' && ($rc8Change['tax_entry'] ?? '') === $rcSettled && $rc8Same === ['none' => 'saved'] && ($rowOf($rc8)['due_date'] ?? '') === '2026-12-03'
    && $rc8Delete instanceof \Z77\Module\Creditor\Services\CreditorRefusedException && $rc8Delete->reason === \Z77\Module\Creditor\Services\CreditorRefusedException::TAX_ENTRY
    && $rc8Delete->getMessage() === 'Rechnung RC-8: ' . $rcSettled . ' Die Rechnung bleibt.' && !$rcNo($rcKey($rc8)) && ($rowOf($rc8)['amount'] ?? '') === '60.00',
    json_encode([$rc8Change, $rc8Same, $rc8Delete?->getMessage()], JSON_UNESCAPED_UNICODE));

// Phase F — the recognition names a foreign supplier (board S6-2), never proposes the code.
$rcAi = (int) (new \Z77\Module\Creditor\Services\CreditorIntakeService($wire(), null, 'Owner'))->receive($pdf('rc-ai'), 'rc-ai.pdf')->getId();
$rcFacts = static fn(?string $uid, array $proposal): \Z77\Module\Creditor\Recognition\InvoiceFacts => new \Z77\Module\Creditor\Recognition\InvoiceFacts(
    'text', null, false, 'USD', \Z77\Shared\Money\Money::fromDecimal('9.00', 'USD'), null, null, null, 'AI-1', null, null, $uid, null, null, $proposal, 'high', false, []);
$service()->applyRecognition($rcAi, 'text', ['state' => 'recognized'], $rcFacts(null, ['name' => 'Tinypix B.V.', 'country' => 'NL']));
$rcAiNotes = (array) ($rcOf($rcAi)->recognition()['notes'] ?? []);
$rcCh = (int) (new \Z77\Module\Creditor\Services\CreditorIntakeService($wire(), null, 'Owner'))->receive($pdf('rc-ch'), 'rc-ch.pdf')->getId();
$service()->applyRecognition($rcCh, 'text', ['state' => 'recognized'], new \Z77\Module\Creditor\Recognition\InvoiceFacts(
    'text', null, false, 'CHF', \Z77\Shared\Money\Money::fromDecimal('9.00', 'CHF'), null, null, null, 'CH-1', null, null, null, null, null, ['name' => 'Muster AG', 'country' => 'CH'], 'high', false, []));
check('RC18 the recognition proposes no reverse-charge code: a USD invoice of a supplier abroad without a Swiss UID gets the note «Ausländischer Lieferant ohne Schweizer MWST — Bezugsteuer prüfen»; a Swiss CHF invoice does not; the line keeps no code',
    in_array(\Z77\Module\Creditor\Services\CreditorInvoiceService::NOTE_REVERSE_CHARGE, $rcAiNotes, true) && \Z77\Module\Creditor\Services\CreditorInvoiceService::NOTE_REVERSE_CHARGE === 'Ausländischer Lieferant ohne Schweizer MWST — Bezugsteuer prüfen'
    && !in_array(\Z77\Module\Creditor\Services\CreditorInvoiceService::NOTE_REVERSE_CHARGE, (array) ($rcOf($rcCh)->recognition()['notes'] ?? []), true)
    && ($linesOf($rcAi)[0]['tax_code'] ?? null) === null,
    json_encode([$rcAiNotes, $rcOf($rcCh)->recognition()['notes'] ?? []], JSON_UNESCAPED_UNICODE));
$service()->delete($rcAi, $vOf($rcAi));
$service()->delete($rcCh, $vOf($rcCh));

// Source: the window, the creditor and the registry.
$profileTrait = (string) file_get_contents($root . '/packages/module-creditor/src/Ui/CreditorProfileControllerTrait.php');
$validatorSrc = (string) file_get_contents($root . '/packages/module-creditor/src/Validators/CreditorProfileValidator.php');
$serviceSrc   = (string) file_get_contents($root . '/packages/module-creditor/src/Services/CreditorInvoiceService.php');
$editTplNow   = (string) file_get_contents($root . '/packages/module-creditor/res/view/templates/Backend/CreditorJournalController/edit.tpl.php');
$rcScopes     = array_keys(array_filter($config['openWorkChecks'] ?? [], static fn(array $checks): bool => in_array(\Z77\Module\Creditor\Close\ReverseChargeEntryCheck::class, $checks, true)));
check('RC19 the opt-in is taken where a creditor line or default is chosen: the window\'s select and the line check (`reverseCharge: true`), the creditor window\'s select and its validator; the window has the field «ESTV-Kurs» (`estv_rate`); `ReverseChargeEntryCheck` is registered under `vat-return` only',
    str_contains($editTplNow, 'taxChoices($keptCodes, true, reverseCharge: true)') && str_contains($editTplNow, 'name="estv_rate"')
    && str_contains($serviceSrc, "vatChoice(\$row['tax'], \"line_{\$i}_tax\", \$errors, true, reverseCharge: true)")
    && str_contains($profileTrait, 'taxChoices([(string) $profile->getDefaultTaxCode()], reverseCharge: true)') && str_contains($validatorSrc, "taxCode(\$code, 'default_tax', \$errors, reverseCharge: true)")
    && $rcScopes === ['vat-return'], json_encode($rcScopes));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
