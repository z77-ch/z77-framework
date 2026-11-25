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
 * Invoices are rows written by raw SQL (the check reads posted invoices, it posts nothing). Every
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

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
