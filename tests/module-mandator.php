<?php

/**
 * module-mandator harness (CLI) — the installation's OWN company (owner
 * decisions E1 / E2 of 2026-09-23) against a REAL MariaDB (ADR-039
 * decision 16), throwaway schema per run, created by the modules' own
 * MIGRATIONS through the `z77-db` application (never `SchemaTool`).
 *
 * What is load-bearing here:
 *
 *   - (A) `migrate` creates `mandator` in `utf8mb4_unicode_ci` / InnoDB next
 *     to vat's, contact's, financial's and debtor's tables — with NO foreign
 *     key (tax code by code, accounts by number) and NO `mandator_id` on any
 *     other table (E1: one mandator, nothing prepared for more); a second
 *     `migrate` is a no-op and `diff` reports NO change afterwards;
 *   - (B) the UID, pure: normalisation of what a user types, the canonical
 *     shape, the modulo-11 check digit on real numbers, a transposed pair
 *     refused, the «check digit would be 10» class refused;
 *   - (C) the validator without a database: the name is required, country /
 *     zip / e-mail / website / logo path rules, the two UID messages, the
 *     account fields digits-only and optional;
 *   - (D) ONE record: `CurrentMandator::find()` is null before, the service
 *     creates it, a second `save()` is refused, `update()` validates a
 *     detached draft BEFORE touching the managed entity (a refused change is
 *     not written by the next flush), the lowest id wins when a hand edit
 *     left two rows;
 *   - (E) the account settings (E2): the KMU start values pre-filled and
 *     verified against `res/charts/kmu.json`, a GROUP / an unknown / an
 *     inactive account refused on save naming the field, an empty field
 *     allowed, the status rows; with financial UNREGISTERED the numbers are
 *     stored unverified and the datalist is empty;
 *   - (F) the two access points read the record: `LedgerService::vatAccountFor()`
 *     by category, debtor's `DebtorAccounts` by key; an empty field, a missing
 *     mandator and an account that became a group after the save are refused
 *     AT THE POINT OF USE naming the mandator; a leftover `vatAccounts` /
 *     `debtorAccounts` in a project override is refused loudly;
 *   - (G) the screen: the fragment renders through its trait with a host
 *     double — the pre-filled empty form, a valid first save, a refused save
 *     (field errors, the draft shown), the entity token on an existing record,
 *     no JavaScript (Rule 7); the host files and the `defaultAction`
 *     deviation in module-backend exist;
 *   - (H) source guards: no `use` of a financial class in module-mandator
 *     (the check names it as a string), no float, no superglobals, the two
 *     old config keys gone from the package configs.
 *
 * Run: php tests/module-mandator.php
 * Needs what tests/module-debtor.php needs (vendor/ with Doctrine, a
 * reachable MariaDB, credentials in `%USERPROFILE%\.z77\mariadb.txt` or
 * Z77_TEST_DB_*). Nothing is written into the repository; the schema
 * `z77test_<random>` and the temp installation are removed at the end.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "module-mandator: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Libraries\ConfigManager;
use Z77\Core\Libraries\FileFinder;
use Z77\Core\Services\I18n;
use Z77\Core\Services\ModuleManager;
use Z77\Module\Debtor\Services\AccountNotConfiguredException;
use Z77\Module\Debtor\Services\DebtorAccounts;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Services\AccountService;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Repositories\MandatorRepository;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\InvalidMandatorException;
use Z77\Module\Mandator\Services\LedgerAccountCheck;
use Z77\Module\Mandator\Services\MandatorAccounts;
use Z77\Module\Mandator\Services\MandatorAlreadyExistsException;
use Z77\Module\Mandator\Services\MandatorService;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Module\Mandator\Services\Uid;
use Z77\Module\Mandator\Ui\MandatorControllerTrait;
use Z77\Module\Mandator\Ui\MandatorLayout;
use Z77\Module\Mandator\Validators\MandatorValidator;
use Z77\Persistence\Doctrine\Bootstrap as DoctrineBootstrap;
use Z77\Persistence\Doctrine\Console\MigrationDirectories;
use Z77\Persistence\Doctrine\Console\MigrationsApplication;
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
    fwrite(STDERR, "module-mandator: no database password — %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD.\n");
    exit(2);
}

// ── throwaway schema (deliberately NOT in our collation) and installation ─

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-module-mandator-' . getmypid();
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

// The REAL packages are the modules' source paths. The mandator is read by
// financial (`vatAccountFor()`) and debtor (`DebtorAccounts`) — both are
// registered so section F can ask the real access points; financial is
// UNREGISTERED for a while in E, the way a project without bookkeeping runs.
$packages = [
    'Vat'       => str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-vat')),
    'Contact'   => str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-contact')),
    'Mandator'  => str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-mandator')),
    'Financial' => str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-financial')),
    'Debtor'    => str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-debtor')),
];
$package    = $packages['Mandator'];
$namespaces = '';
foreach ($packages as $name => $path) {
    // Financial and Debtor look in an override root FIRST — where a project's leftover
    // `vatAccounts` / `debtorAccounts` config copy lands (BOOT-CONFIG-001, section F).
    $override    = $base . '/override/module/' . strtolower($name);
    $sources     = in_array($name, ['Financial', 'Debtor'], true) ? "'{$override}', '{$path}'" : "'{$path}'";
    $namespaces .= "'Z77\\\\Module\\\\{$name}\\\\' => ['sourcePaths' => [{$sources}]],\n";
}
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$writeModules = function (bool $withFinancial, bool $withMandator = true) use ($write): void {
    $modules = "'vat' => [], 'contact' => []" . ($withMandator ? ", 'mandator' => []" : '') . ($withFinancial ? ", 'financial' => []" : '') . ", 'debtor' => []";
    $write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'vat', 'modules' => [{$modules}]];");
};
$writeModules(true);
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
// Seed the way the installer does: `*.default.json` → `data/…` with the marker stripped.
@mkdir($base . '/data/framework/contact', 0777, true);
copy($packages['Contact'] . '/data/framework/contact/address_types.default.json', $base . '/data/framework/contact/address_types.json');
@mkdir($base . '/data/framework/debtor', 0777, true);
foreach (glob($packages['Debtor'] . '/data/framework/debtor/*.default.json') as $seed) {
    copy($seed, $base . '/data/framework/debtor/' . str_replace('.default.json', '.json', basename($seed)));
}
@mkdir($base . '/data/framework/vat', 0777, true);
foreach (['tax_codes', 'tax_rates'] as $name) {
    copy($packages['Vat'] . '/data/framework/vat/' . $name . '.default.json', $base . '/data/framework/vat/' . $name . '.json');
}

/** The DI wiring Bootstrap::__construct() + pullUpServices() do, reduced to what the drivers and the CLI read. Calling it again is the «fresh request». */
$wireDi = static function () use ($base): UnifiedEntityManager {
    DI::getInstance(true)
        ->set('CacheManager', CacheManager::class, true)
        ->set('FileFinder', fn($c) => new FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn($c) => new ModuleManager($c->get('ConfigManager')), true)
        ->set('I18n', fn($c) => new I18n($c->get('ConfigManager')), true)
        ->set('DataSourceResolver', fn() => new DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new UnifiedEntityManager($c->get('DataSourceResolver')), true)
    ;
    DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return DI::getUnifiedEntityManager();
};
$em = $wireDi();

$db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$tables    = fn() => array_map('strtolower', $db->createSchemaManager()->listTableNames());
$tableInfo = fn(string $table) => $db->fetchAssociative('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$dbName, $table]);
$count     = fn(string $table) => (int) $db->fetchOne("SELECT COUNT(*) FROM {$table}");

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
$config = DI::getModuleManager()->getModuleConfig('mandator');
check('A0 the module config announces exactly ONE Doctrine entity and carries no account defaults (they are constants — the record is the setting)',
    $config?->get('doctrineEntities') === [Mandator::class] && !$config->has('accountDefaults') && !$config->has('defaultGroup'));
$dirs = MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder());
check('A1 the module\'s res/migrations is collected under Z77\\Module\\Mandator\\Migrations', ($dirs['Z77\\Module\\Mandator\\Migrations'] ?? '') === $package . '/res/migrations');
check('A2 the database is empty', $tables() === []);
[$code, $out] = $run(['command' => 'migrate']);
check('A3 migrate exits 0' . ($code !== 0 ? " — got {$code}: " . trim($out) : ''), $code === 0);
$executed = $db->fetchFirstColumn('SELECT version FROM schema_migration');
// The newest migration of every collected directory — derived, not pinned (the pin went stale with each new migration of any module).
$newest = (function () use ($dirs): string {
    $all = [];
    foreach ($dirs as $ns => $dir) {
        foreach (glob($dir . '/Version*.php') as $file) {
            // Keyed like ChronologicalComparator orders: the timestamp, the namespace breaks a tie (two
            // modules' migrations of the same second — the «Betreffname» of 2026-10-21).
            $all[substr(basename($file, '.php'), 7) . '|' . $ns . '\\' . basename($file, '.php')] = $ns . '\\' . basename($file, '.php');
        }
    }
    krsort($all, SORT_STRING);
    return (string) reset($all);
})();
check('A4 the mandator migration ran, in timestamp order among the modules (the run ends at the newest: ' . $newest . ')',
    str_contains($out, 'Migrating up to ' . $newest) && in_array('Z77\\Module\\Mandator\\Migrations\\Version20260923160948', $executed, true));
check('A5 mandator exists next to the other modules\' tables', in_array('mandator', $tables(), true) && in_array('account', $tables(), true) && in_array('invoice', $tables(), true));
$info = $tableInfo('mandator');
check('A6 mandator is utf8mb4_unicode_ci and InnoDB although the database default is general_ci',
    ($info['TABLE_COLLATION'] ?? '') === 'utf8mb4_unicode_ci' && ($info['ENGINE'] ?? '') === 'InnoDB');
$columns = $db->fetchAllKeyValue('SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL', [$dbName, 'mandator']);
check('A7 … and so is every string column of it', $columns !== [] && count(array_unique($columns)) === 1 && reset($columns) === 'utf8mb4_unicode_ci');
$fks = $db->fetchFirstColumn('SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$dbName, 'mandator']);
check('A8 NO foreign key: the tax code is referenced by code (ADR-043/19), the accounts by number into a chart that may be absent', $fks === []);
$mandatorIdColumns = $db->fetchFirstColumn('SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = ?', [$dbName, 'mandator_id']);
check('A8b NO other table carries a mandator_id — one mandator, nothing prepared for more (E1)', $mandatorIdColumns === []);
$accountColumns = $db->fetchFirstColumn('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME LIKE ? ORDER BY ORDINAL_POSITION', [$dbName, 'mandator', 'account\_%']);
check('A8c the fifteen account columns are the fifteen keys of Mandator::ACCOUNT_KEYS, VARCHAR(10) — the two year-opening accounts appended (Version20261022140000), then the two of bank-driven bookkeeping (Version20261117190000, ADR-061 stage C4), then the two of the VAT return (Version20261124120000), then the payment charges (Version20261124160000)',
    $accountColumns === array_map(fn($k) => Mandator::accountField($k), array_keys(Mandator::ACCOUNT_KEYS)) && count($accountColumns) === 15
    && array_slice($accountColumns, -5) === ['account_private', 'account_bank_transfer', 'account_vat_settlement', 'account_vat_rounding', 'account_payment_charges']);
$chargesColumn = $db->fetchAssociative('SELECT COLUMN_DEFAULT, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$dbName, 'mandator', 'account_payment_charges']);
check('A8f the payment charges (DEBTOR-FEE-KIND-001, 2026-11-24): NOT NULL with the KMU number 6940 «Bankspesen» as column DEFAULT — the year opening\'s model, an existing record names it at once',
    $chargesColumn !== false && trim((string) $chargesColumn['COLUMN_DEFAULT'], "'") === '6940' && $chargesColumn['IS_NULLABLE'] === 'NO' && (int) $chargesColumn['CHARACTER_MAXIMUM_LENGTH'] === 10);
$bankDefaults = $db->fetchAllKeyValue('SELECT COLUMN_NAME, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?) ORDER BY COLUMN_NAME', [$dbName, 'mandator', 'account_private', 'account_bank_transfer']);
check('A8e «Privat» and «Übertrag eigene Bankkonten» (ADR-061 decision 17): NOT NULL with an EMPTY default — no number is assumed for an existing record (a chart may use the usual ones for something else)',
    array_map(fn($d) => trim((string) $d, "'"), $bankDefaults) === ['account_bank_transfer' => '', 'account_private' => '']);
$openingDefaults = $db->fetchAllKeyValue('SELECT COLUMN_NAME, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?) ORDER BY COLUMN_NAME', [$dbName, 'mandator', 'account_annual_result', 'account_opening_balance']);
check('A8d the year-opening accounts (P5, owner 2026-10-22): NOT NULL with the KMU numbers as column DEFAULT — an existing record gets 9100 / 2979 at once',
    array_map(fn($d) => trim((string) $d, "'"), $openingDefaults) === ['account_annual_result' => '2979', 'account_opening_balance' => '9100']);
[$code, $out] = $run(['command' => 'migrate']);
check('A9 a second migrate is a no-op', $code === 0 && str_contains($out, 'Already at the latest version'));
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Mandator\\Migrations']);
check('A10 diff after migrate reports NO change — mapping and migration agree', $code !== 0 && str_contains($out, 'No changes detected') && count(glob($package . '/res/migrations/Version*.php')) === 9);   // 9 since Version20261124160000 (the payment charges)
// down() / up() of the newest mandator migration (2026-11-17, the two accounts of bank-driven bookkeeping): BY NAME first — a newer
// migration of another module (financial's of the same day) follows it, so `prev` from the top would not hit the mandator's.
$bankCols = static fn(): array => $db->fetchFirstColumn('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?) ORDER BY COLUMN_NAME', [$dbName, 'mandator', 'account_private', 'account_bank_transfer']);
[$toCode, $toOut]     = $run(['command' => 'migrate', 'version' => 'Z77\Module\Mandator\Migrations\Version20261117190000']);
[$downCode, $downOut] = $run(['command' => 'migrate', 'version' => 'prev']);
$bankColsDown         = $bankCols();
$otherColsDown        = count($db->fetchFirstColumn('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME LIKE ?', [$dbName, 'mandator', 'account\_%']));
[$upCode, $upOut]     = $run(['command' => 'migrate']);
check('A10b down() of Version20261117190000 drops account_private and account_bank_transfer only — the ten other account columns stay; up() brings both back',
    $toCode === 0 && $downCode === 0 && $bankColsDown === [] && $otherColsDown === 10 && $upCode === 0 && $bankCols() === ['account_bank_transfer', 'account_private'], trim($toOut . $downOut . $upOut));
check('A11 every migration is expand-only: no DROP outside down()', array_reduce(glob($package . '/res/migrations/Version*.php'), function (bool $ok, string $f): bool {
    $s = file_get_contents($f);
    return $ok && substr_count(substr($s, 0, strpos($s, 'function down')), 'DROP') === 0;
}, true));
$labelColumn = $db->fetchAssociative('SELECT COLUMN_DEFAULT, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$dbName, 'mandator', 'default_subject_label']);
check('A12 the default «Betreffname» of new documents (owner 2026-10-21, Version20261021100000): mandator.default_subject_label VARCHAR(80) NOT NULL DEFAULT \'\' — none, the behaviour before; the setter trims and strips the trailing colon; the validator refuses 81 characters; the field sits on the tab «Belege»',
    $labelColumn !== false && trim((string) $labelColumn['COLUMN_DEFAULT'], "'") === '' && $labelColumn['IS_NULLABLE'] === 'NO' && (int) $labelColumn['CHARACTER_MAXIMUM_LENGTH'] === 80
    && (new Mandator(['default_subject_label' => '  Projekt: ']))->getDefaultSubjectLabel() === 'Projekt'
    && !(new MandatorValidator(new Mandator(['name' => 'x', 'country' => 'CH', 'default_subject_label' => str_repeat('x', 81)])))->isValid(['default_subject_label'])
    && (new MandatorValidator(new Mandator(['name' => 'x', 'country' => 'CH', 'default_subject_label' => str_repeat('x', 80)])))->isValid(['default_subject_label'])
    && MandatorLayout::tabOf('default_subject_label') === 'belege' && array_key_last(MandatorLayout::TABS) === 'belege');
$noteColumn = $db->fetchAssociative('SELECT COLUMN_DEFAULT, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$dbName, 'mandator', 'vat_exempt_note']);
$noteOf = fn(bool $liable, string $note) => (new Mandator(['name' => 'x', 'liable_to_vat' => $liable, 'vat_exempt_note' => $note]))->documentVatNote();
check('A13 the note a document without VAT prints (owner 2026-10-22, Version20261022100000): mandator.vat_exempt_note VARCHAR(255) NOT NULL DEFAULT \'\'; the setter trims and collapses whitespace to one line; the validator refuses 256 characters and takes 255; the field sits on the tab «UID und MWST»; documentVatNote() = the text only while NOT liable and not empty',
    $noteColumn !== false && trim((string) $noteColumn['COLUMN_DEFAULT'], "'") === '' && $noteColumn['IS_NULLABLE'] === 'NO' && (int) $noteColumn['CHARACTER_MAXIMUM_LENGTH'] === 255
    && (new Mandator(['vat_exempt_note' => "  Keine MWST \n —  nicht mehrwertsteuerpflichtig. "]))->getVatExemptNote() === 'Keine MWST — nicht mehrwertsteuerpflichtig.'
    && !(new MandatorValidator(new Mandator(['name' => 'x', 'country' => 'CH', 'vat_exempt_note' => str_repeat('x', 256)])))->isValid(['vat_exempt_note'])
    && (new MandatorValidator(new Mandator(['name' => 'x', 'country' => 'CH', 'vat_exempt_note' => str_repeat('x', 255)])))->isValid(['vat_exempt_note'])
    && MandatorLayout::tabOf('vat_exempt_note') === 'uid'
    && $noteOf(false, 'Keine MWST.') === 'Keine MWST.' && $noteOf(true, 'Keine MWST.') === null && $noteOf(false, '') === null && (new Mandator())->documentVatNote() === null);

$logoColumn = $db->fetchAssociative('SELECT COLUMN_DEFAULT, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$dbName, 'mandator', 'email_logo_path']);
check('A14 the old logo path columns (owner 2026-11-20, «ja los»): mandator.email_logo_path still VARCHAR(255) NOT NULL DEFAULT \'\' (unused — the DROP is a later release\'s contract step), but no getter, no setter, no validator: a posted logo_path / email_logo_path does nothing; the module registers the inline image `logo`',
    $logoColumn !== false && trim((string) $logoColumn['COLUMN_DEFAULT'], "'") === '' && $logoColumn['IS_NULLABLE'] === 'NO' && (int) $logoColumn['CHARACTER_MAXIMUM_LENGTH'] === 255
    && !method_exists(Mandator::class, 'getLogoPath') && !method_exists(Mandator::class, 'getEmailLogoPath') && !method_exists(Mandator::class, 'setLogoPath') && !method_exists(Mandator::class, 'setEmailLogoPath')
    && !method_exists(MandatorValidator::class, 'validateLogoPath') && !method_exists(MandatorValidator::class, 'validateEmailLogoPath')
    && (new MandatorValidator(new Mandator(['name' => 'x', 'country' => 'CH', 'logo_path' => '../etc/passwd', 'email_logo_path' => 'a.svg'])))->isValid()
    && $config->get('mailInlineImages') === ['logo' => \Z77\Module\Mandator\Mail\EmailLogo::class]
    && \Z77\Shared\Mail\InlineImages::fromModules(DI::getModuleManager())->names() === ['logo']);
// The logo set-up window (2026-11-20, Version20261120150000) — added unrun (owner: no tests).
$logoDocColumns = $db->fetchAllKeyValue('SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME LIKE ? ORDER BY COLUMN_NAME', [$dbName, 'mandator', '%\_logo\_document\_id']);
$dismissColumn  = $db->fetchAssociative('SELECT COLUMN_DEFAULT, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$dbName, 'mandator', 'logo_step_dismissed']);
$posted         = new Mandator(['name' => 'x', 'print_logo_document_id' => 5, 'logo_step_dismissed' => true]);
check('A15 the logo window\'s columns (Version20261120150000): four nullable *_logo_document_id and logo_step_dismissed DEFAULT 0; a POSTED value never reaches them (no setter named after the property); brandLogo = LoginLogo, siteLogo = WebLogo, the «Firmenlogo» partition granted to admin',
    $logoDocColumns === ['email_logo_document_id' => 'YES', 'login_logo_document_id' => 'YES', 'print_logo_document_id' => 'YES', 'web_logo_document_id' => 'YES']
    && $dismissColumn !== false && trim((string) $dismissColumn['COLUMN_DEFAULT'], "'") === '0' && $dismissColumn['IS_NULLABLE'] === 'NO'
    && $posted->logoDocument('print') === null && !$posted->isLogoStepDismissed()
    && $config->get('brandLogo') === \Z77\Module\Mandator\Logo\LoginLogo::class && $config->get('siteLogo') === \Z77\Module\Mandator\Logo\WebLogo::class
    && $config->get('dmsPartitionGrants') === ['mandator' => [\Z77\Core\Config\AuthRole::ADMIN => 'read']]);

// ── B. the UID, pure ─────────────────────────────────────────────────────

echo "B. UID (normalisation, shape, modulo-11 check digit)\n";
check('B1 normalize(): what a user types becomes the canonical CHE-ddd.ddd.ddd',
    Uid::normalize('che-109322551') === 'CHE-109.322.551' && Uid::normalize(' CHE 109.322.551 MWST ') === 'CHE-109.322.551'
    && Uid::normalize('CHE109322551') === 'CHE-109.322.551' && Uid::normalize('CHE-109.322.551') === 'CHE-109.322.551' && Uid::normalize('') === '');
check('B2 … and what cannot be read stays as typed (upper-cased), so the validator can name the shape', Uid::normalize('CHE-109.322.55') === 'CHE-109.322.55' && Uid::normalize('nope') === 'NOPE');
check('B3 isWellFormed() accepts exactly the canonical shape', Uid::isWellFormed('CHE-109.322.551') && !Uid::isWellFormed('CHE109322551') && !Uid::isWellFormed('CHE-109.322.55') && !Uid::isWellFormed('DE-109.322.551'));
check('B4 the check digit holds on real numbers: CHE-109.322.551 (check 1) and CHE-116.281.710 (remainder 0 → check 11 → written 0)',
    Uid::hasValidCheckDigit('CHE-109.322.551') && Uid::hasValidCheckDigit('CHE-116.281.710'));
check('B5 a transposed pair and a wrong last digit are refused', !Uid::hasValidCheckDigit('CHE-109.232.551') && !Uid::hasValidCheckDigit('CHE-109.322.552') && !Uid::hasValidCheckDigit('CHE-109.322.550'));
check('B6 a number whose check digit would be 10 is invalid with ANY last digit (eCH-0097: such numbers are never assigned)',
    array_filter(range(0, 9), fn($d) => Uid::hasValidCheckDigit('CHE-000.000.03' . $d)) === []);
check('B7 a malformed UID has no valid check digit', !Uid::hasValidCheckDigit('CHE109322551') && !Uid::hasValidCheckDigit(''));
check('B8 CHE-000.000.000 is refused although Σ = 0 makes its check digit «valid» — nine zeros are a placeholder, not a number (review 2026-09-23, P9b)', !Uid::hasValidCheckDigit('CHE-000.000.000') && !Uid::hasValidCheckDigit('CHE-000.000.001'));

// ── C. the validator without a database ──────────────────────────────────

echo "C. Validator (format rules; no repositories)\n";
$valid = fn(array $data) => new MandatorValidator(new Mandator($data));
$field = fn(array $data, string $field): string => (function (MandatorValidator $v) use ($field) { $v->isValid(); return $v->getFieldError($field); })($valid($data));
check('C1 a bare name in CH is a valid mandator — everything else is optional', $valid(['name' => 'Harness AG'])->isValid());
check('C2 the name is required', $field([], 'name') !== '' && $field(['name' => str_repeat('x', 121)], 'name') !== '');
check('C3 country: two upper-case letters (the setter upper-cases); the default is CH', $field(['name' => 'x', 'country' => 'ch'], 'country') === '' && (new Mandator())->getCountry() === 'CH'
    && $field(['name' => 'x', 'country' => 'Schweiz'], 'country') !== '' && $field(['name' => 'x', 'country' => ''], 'country') !== '');
check('C4 a Swiss zip has four digits; abroad 3–10 characters; empty allowed', $field(['name' => 'x', 'zip' => '8000'], 'zip') === '' && $field(['name' => 'x', 'zip' => '800'], 'zip') !== ''
    && $field(['name' => 'x', 'country' => 'DE', 'zip' => '80331'], 'zip') === '' && $field(['name' => 'x', 'zip' => ''], 'zip') === '');
check('C5 e-mail optional but deliverable; lower-cased by the setter', $field(['name' => 'x', 'email' => 'Info@Firma.ch'], 'email') === '' && (new Mandator(['email' => 'Info@Firma.ch']))->getEmail() === 'info@firma.ch'
    && $field(['name' => 'x', 'email' => 'nope'], 'email') !== '' && $field(['name' => 'x', 'email' => ''], 'email') === '');
check('C6 website without spaces; the old logo path is no field any more (2026-11-20) — no error for it, whatever is posted', $field(['name' => 'x', 'website' => 'www.firma.ch'], 'website') === '' && $field(['name' => 'x', 'website' => 'www firma'], 'website') !== ''
    && $field(['name' => 'x', 'logo_path' => '../etc/passwd'], 'logo_path') === '');
check('C7 UID: the shape message and the check-digit message are two different sentences; empty allowed; the setter normalises',
    $field(['name' => 'x', 'uid' => 'CHE-109.322.55'], 'uid') !== '' && str_contains($field(['name' => 'x', 'uid' => 'CHE-109.322.55'], 'uid'), 'CHE-123.456.789')
    && str_contains($field(['name' => 'x', 'uid' => 'CHE-109.322.552'], 'uid'), 'Prüfziffer')
    && $field(['name' => 'x', 'uid' => 'che 109322551 MWST'], 'uid') === '' && $field(['name' => 'x', 'uid' => ''], 'uid') === '');
check('C8 an account field: digits only, at most 10, empty allowed — no ledger check without the check object',
    $field(['name' => 'x', 'account_receivable' => '1100'], 'account_receivable') === '' && $field(['name' => 'x', 'account_receivable' => ''], 'account_receivable') === ''
    && str_contains($field(['name' => 'x', 'account_receivable' => '11a0'], 'account_receivable'), 'Ziffern') && $field(['name' => 'x', 'account_vat_owed' => '12345678901'], 'account_vat_owed') !== '');
check('C9 the entity: account() by key, accountField() by key, an unknown key is a programming error',
    (new Mandator(['account_dunning_fee' => '6950']))->account('dunning-fee') === '6950' && Mandator::accountField('vat-input-material') === 'account_vat_input_material'
    && throws(fn() => (new Mandator())->account('nope'), \InvalidArgumentException::class) && throws(fn() => Mandator::accountField('nope'), \InvalidArgumentException::class));
check('C10 liableToVat is a flag (off by default; read by InvoicingService::chargesVat() of module-debtor since 2026-10-21 — the mandator module itself switches nothing); there is NO default tax code and no typed account getter (owner, review 2026-09-23 — no reader)', !(new Mandator())->isLiableToVat() && (new Mandator(['liable_to_vat' => true]))->isLiableToVat()
    && !method_exists(Mandator::class, 'getDefaultTaxCode') && !method_exists(Mandator::class, 'setDefaultTaxCode') && !method_exists(Mandator::class, 'getAccountReceivable') && !method_exists(Mandator::class, 'getAccountVatOwed')
    && (new Mandator(['account_loss' => '  3805 ']))->account('loss') === '3805');

// ── D. ONE record ────────────────────────────────────────────────────────

echo "D. One record: find, save once (the primary key is the guard), update validates a draft first\n";
// The chart the account fields are checked against (financial is registered here).
$em = $wireDi();
(new AccountService($em))->adoptKmuChart();
$em = $wireDi();
check('D1 before the first save: find() is null, exists() false — nothing is created on access', (new CurrentMandator($em))->find() === null && !(new CurrentMandator($em))->exists() && $count('mandator') === 0);
$first = MandatorAccounts::prefilled();
$first->mapFromArray(['name' => 'Harness AG', 'address_suffix_one' => 'Abteilung Test', 'street' => 'Musterweg', 'house_no' => '12a', 'zip' => '8000', 'city' => 'Zürich',
    'email' => 'info@harness.ch', 'phone' => '+41 44 000 00 00', 'website' => 'www.harness.ch', 'uid' => 'CHE-109.322.551', 'liable_to_vat' => true]);
(new MandatorService($em))->save($first);
check('D2 the first save creates the record with the FIXED id (Mandator::ID = 1, assigned — never generated)', $first->getId() === Mandator::ID && $count('mandator') === 1 && (int) $db->fetchOne('SELECT id FROM mandator') === 1);
$em    = $wireDi();
$found = (new CurrentMandator($em))->find();
check('D3 find() answers it — every field as stored, the UID canonical, the accounts pre-filled', $found instanceof Mandator && $found->getId() === Mandator::ID && $found->getName() === 'Harness AG'
    && $found->getUid() === 'CHE-109.322.551' && $found->isLiableToVat() && $found->account('vat-owed') === '2200' && $found->getHouseNo() === '12a');
$row = $db->fetchAssociative('SELECT * FROM mandator');
check('D3b the row: snake_case columns, the flag as 1, the accounts as strings, NO default_tax_code column (owner, review 2026-09-23)', $row['address_suffix_one'] === 'Abteilung Test' && (int) $row['liable_to_vat'] === 1 && $row['account_rounding'] === '3809' && $row['uid'] === 'CHE-109.322.551'
    && !array_key_exists('default_tax_code', $row));
check('D4 a SECOND save is refused before the write — one mandator (E1)', throws(fn() => (new MandatorService($em))->save(new Mandator(['name' => 'Zweite AG'])), MandatorAlreadyExistsException::class) && $count('mandator') === 1);
$secondRowSql = "INSERT INTO mandator (id, name, address_suffix_one, address_suffix_two, street, house_no, zip, city, country, email, phone, website, logo_path, uid, liable_to_vat, account_receivable, account_discount, account_loss, account_rounding, account_dunning_fee, account_vat_input_material, account_vat_input_other, account_vat_owed) VALUES (%d, '%s', '', '', '', '', '', '', 'CH', '', '', '', '', '', 0, '', '', '', '', '', '', '', '')";
check('D4b … and the DATABASE refuses it too: a raw INSERT of the fixed id fails on the primary key — the guard is not a PHP window (review 2026-09-23, P7)',
    throws(fn() => $db->executeStatement(sprintf($secondRowSql, 1, 'Zweite')), \Doctrine\DBAL\Exception\UniqueConstraintViolationException::class) && $count('mandator') === 1);
check('D4c … a persist() past the existence check (the race, simulated on a fresh EntityManager) ends in the same refusal from the primary key', (function () use ($wireDi): bool {
    $em     = $wireDi();
    $second = MandatorAccounts::prefilled();
    $second->mapFromArray(['name' => 'Race AG', 'country' => 'CH']);
    $em->persist($second);
    try { $em->flush(); return false; } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) { return true; }
})() && $count('mandator') === 1);
check('D4d the id column carries NO AUTO_INCREMENT (a multi-mandator build turns it on in its own migration)', $db->fetchOne('SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$dbName, 'mandator', 'id']) === '');
check('D5 no delete anywhere: neither the service nor the repository nor the trait offers one; no count either (two rows cannot exist)',
    array_filter(get_class_methods(MandatorService::class), fn($m) => preg_match('/delete|remove/i', $m)) === []
    && !method_exists(MandatorRepository::class, 'delete') && !method_exists(MandatorRepository::class, 'count') && !method_exists(MandatorControllerTrait::class, 'deleteAction') && !method_exists(MandatorControllerTrait::class, 'confirmDeleteAction'));

echo "D. … update(): validate before mutate (ADR-039 decision 9)\n";
$em      = $wireDi();
$managed = (new CurrentMandator($em))->find();
$e = caught(fn() => (new MandatorService($em))->update($managed, ['name' => '', 'city' => 'Bern']), InvalidMandatorException::class);
check('D6 a refused update carries the validator and the DRAFT — the managed entity is untouched', $e instanceof InvalidMandatorException && $e->validator->hasFieldError('name')
    && $e->mandator !== $managed && $e->mandator->getCity() === 'Bern' && $managed->getName() === 'Harness AG' && $managed->getCity() === 'Zürich');
$em->flush();
check('D6b … and the next flush of the same request writes nothing of it', $db->fetchOne('SELECT city FROM mandator') === 'Zürich');
(new MandatorService($em))->update($managed, ['city' => 'Bern', 'liable_to_vat' => false, 'uid' => 'che 116281710']);
$em = $wireDi();
check('D7 a valid update is written: city, the flag off, the UID normalised', ($m = (new CurrentMandator($em))->find())->getCity() === 'Bern' && !$m->isLiableToVat() && $m->getUid() === 'CHE-116.281.710' && $count('mandator') === 1);

echo "D. … a hand-inserted row with ANOTHER id is never read: the mandator is the primary-key read of id 1\n";
$db->executeStatement(sprintf($secondRowSql, 7, 'Fremde Zeile'));
$em = $wireDi();
check('D8 find() still answers id 1 — a row written past the framework under another id is dead data, not a second mandator', (new CurrentMandator($em))->find()->getName() === 'Harness AG' && (new CurrentMandator($em))->find()->getId() === 1);
$db->executeStatement('DELETE FROM mandator WHERE id <> 1');

// ── E. the account settings ──────────────────────────────────────────────

echo "E. Account settings (E2): start values, the soft check on save, status\n";
$em = $wireDi();
check('E1 the start values are the KMU numbers — rounding its OWN account 3809, dunning fee on 6950 (owner, 2026-09-22); the VAT return settlement account 2201 and rounding account 6945 (2026-11-24)', MandatorAccounts::DEFAULTS === [
    'receivable' => '1100', 'discount' => '3800', 'loss' => '3805', 'rounding' => '3809', 'dunning-fee' => '6950', 'vat-input-material' => '1170', 'vat-input-other' => '1171', 'vat-owed' => '2200',
    'opening-balance' => '9100', 'annual-result' => '2979',
    'vat-settlement' => '2201', 'vat-rounding' => '6945', 'payment-charges' => '6940',
] && array_keys(MandatorAccounts::DEFAULTS) === array_values(array_diff(array_keys(Mandator::ACCOUNT_KEYS), ['private', 'bank-transfer'])) && array_keys(MandatorAccounts::LABELS) === array_keys(Mandator::ACCOUNT_KEYS)
    && MandatorAccounts::VAT_RETURN_KEYS === ['vat-settlement', 'vat-rounding']
    // the two accounts of bank-driven bookkeeping have NO start value: the shipped chart holds none, nothing is assumed (ADR-061 stage C4)
    && array_values(array_diff(array_keys(Mandator::ACCOUNT_KEYS), array_keys(MandatorAccounts::DEFAULTS))) === ['private', 'bank-transfer']);
$kmu = array_column(json_decode(file_get_contents($packages['Financial'] . '/res/charts/kmu.json'), true), null, 'number');
check('E2 … every one of them is a postable account of the shipped KMU chart (verified against the FILE)',
    array_reduce(MandatorAccounts::DEFAULTS, fn($ok, $n) => $ok && ($kmu[$n]['postable'] ?? false) === true, true)
    && str_contains($kmu['1170']['name'], 'Vorsteuer') && str_contains($kmu['2200']['name'], 'Geschuldete MWST') && $kmu['3809']['name'] === 'Rundungsdifferenzen'
    && $kmu['2201']['name'] === 'Abrechnungskonto MWST');
$kmuRows  = json_decode(file_get_contents($packages['Financial'] . '/res/charts/kmu.json'), true);
$kmuOrder = array_column($kmuRows, 'number');
check('E2b the KMU chart carries 6945 «Rundungsdifferenzen MWST» (ADR-041 addendum 2026-11-24, point 13): expense, postable, parent 69, right after 6940 — the exact row shape of the file',
    ($kmu['6945'] ?? null) === ['number' => '6945', 'name' => 'Rundungsdifferenzen MWST', 'type' => 'expense', 'postable' => true, 'parent' => '69']
    && array_search('6945', $kmuOrder, true) === array_search('6940', $kmuOrder, true) + 1);
check('E3 prefilled() carries exactly the defaults and nothing else (the two bank accounts stay empty)', array_map(fn($k) => MandatorAccounts::prefilled()->account($k), array_keys(Mandator::ACCOUNT_KEYS)) === array_map(fn($k) => MandatorAccounts::DEFAULTS[$k] ?? '', array_keys(Mandator::ACCOUNT_KEYS))
    && MandatorAccounts::prefilled()->getName() === '' && MandatorAccounts::prefilled()->getId() === Mandator::ID);
$refused = fn(array $values): ?MandatorValidator => ($e = caught(fn() => (new MandatorService($wireDi()))->update((new CurrentMandator(DI::getUnifiedEntityManager()))->find(), $values), InvalidMandatorException::class)) instanceof InvalidMandatorException ? $e->validator : null;
$v = $refused(['account_receivable' => '100']);
check('E4 a GROUP is refused on save, naming the field\'s label', $v?->hasFieldError('account_receivable') === true && str_contains($v->getFieldError('account_receivable'), 'Debitoren-Sammelkonto') && str_contains($v->getFieldError('account_receivable'), 'Gruppe'));
$v = $refused(['account_vat_owed' => '9999999']);
check('E5 an unknown account is refused on save', $v?->hasFieldError('account_vat_owed') === true);
$em = $wireDi();
(new AccountService($em))->setActive($em->getRepository(Account::class)->findOneBy(['number' => '1000']), false);
$v = $refused(['account_discount' => '1000']);
check('E6 an INACTIVE account is refused on save', $v?->hasFieldError('account_discount') === true);
(new AccountService($wireDi()))->setActive(DI::getUnifiedEntityManager()->getRepository(Account::class)->findOneBy(['number' => '1000']), true);
$v = $refused(['account_discount' => '12a']);
check('E7 a non-numeric account is refused on save', $v?->hasFieldError('account_discount') === true);
// The two accounts of bank-driven bookkeeping (ADR-061 decision 17, stage C4): no start value, the same rule as every account field.
$vPrivate  = $refused(['account_private' => '28x0']);
$vTransfer = $refused(['account_bank_transfer' => '9999999']);
$em = $wireDi();
(new MandatorService($em))->update((new CurrentMandator($em))->find(), ['account_private' => '2800', 'account_bank_transfer' => '1020']);
$em = $wireDi();
$bankSet = [(new CurrentMandator($em))->find()->account('private'), (new CurrentMandator($em))->find()->account('bank-transfer'),
    (new \Z77\Module\Financial\Services\LedgerService($em))->bankPostingAccount('private'), (new \Z77\Module\Financial\Services\LedgerService($em))->bankPostingAccount('bank-transfer')];
(new MandatorService($em))->update((new CurrentMandator($em))->find(), ['account_private' => '', 'account_bank_transfer' => '']);
$em = $wireDi();
$helpTpl = (string) file_get_contents($package . '/res/view/templates/Backend/MandatorController/edit.help.tpl.php');
check('E7b «Privat» and «Übertrag eigene Bankkonten» (bank-driven bookkeeping): a non-numeric number and an account that is not in the chart are refused on the field with its label; a postable account saves and financial reads it through its ONE reader; emptied = not set — the reader answers null, nothing is assumed; the help explains both in everyday words',
    $vPrivate?->hasFieldError('account_private') === true && str_contains($vPrivate->getFieldError('account_private'), 'Privat') && $vTransfer?->hasFieldError('account_bank_transfer') === true
    && $bankSet === ['2800', '1020', '2800', '1020'] && (new \Z77\Module\Financial\Services\LedgerService($em))->bankPostingAccount('private') === null
    && (new \Z77\Module\Financial\Services\LedgerService($em))->bankPostingAccount('bank-transfer') === null
    && caught(fn() => (new \Z77\Module\Financial\Services\LedgerService($em))->bankPostingAccount('receivable'), \InvalidArgumentException::class) !== null
    && str_contains($helpTpl, 'data-help-field="account_private"') && str_contains($helpTpl, 'data-help-field="account_bank_transfer"'), json_encode($bankSet));
$em = $wireDi();
(new MandatorService($em))->update((new CurrentMandator($em))->find(), ['account_dunning_fee' => '']);
$em = $wireDi();
check('E8 an EMPTY account field saves — «not set» is refused at the point of use, not here (an installation that never duns)', (new CurrentMandator($em))->find()->account('dunning-fee') === '');
$status = (new MandatorAccounts($em))->status((new CurrentMandator($em))->find());
check('E9 status(): the empty key is named — so are the two bank accounts nobody typed yet —, the twelve others are ok (payment charges since 2026-11-24)', $status['dunning-fee']['error'] !== null && str_contains($status['dunning-fee']['error'], 'Mahngebühr')
    && $status['private']['error'] !== null && str_contains($status['private']['error'], 'Privat') && $status['bank-transfer']['error'] !== null
    && count(array_diff_key($status, ['dunning-fee' => 1, 'private' => 1, 'bank-transfer' => 1])) === 12
    && array_reduce(array_diff_key($status, ['dunning-fee' => 1, 'private' => 1, 'bank-transfer' => 1]), fn($ok, $r) => $ok && $r['error'] === null && $r['number'] !== '', true));
$db->executeStatement("UPDATE mandator SET account_loss = '100'");   // a chart change AFTER the save — past the validator
$em = $wireDi();
check('E10 status() and the screen\'s validator flag an account that BECAME a group after it was saved', (new MandatorAccounts($em))->status((new CurrentMandator($em))->find())['loss']['error'] !== null
    && (function () use ($em): bool { $v = (new MandatorService($em))->validator((new CurrentMandator($em))->find()); return !$v->isValid() && $v->hasFieldError('account_loss'); })());
echo "E. … an UNCHANGED account keeps its number — the letterhead saves although a bookkeeping field became invalid (review 2026-09-23, P3a3; ADR-043 decision 19)\n";
(new MandatorService($em))->update((new CurrentMandator($em))->find(), ['city' => 'Winterthur']);
$em = $wireDi();
check('E10b the city saves while account_loss = 100 (a group) stands UNCHANGED — the stored number is kept, not re-validated',
    (new CurrentMandator($em))->find()->getCity() === 'Winterthur' && (new CurrentMandator($em))->find()->account('loss') === '100');
$v = $refused(['account_discount' => '100', 'city' => 'Zug']);
check('E10c … but a CHANGED account must be postable: discount → 100 (a group) is refused, and nothing of that save is written',
    $v?->hasFieldError('account_discount') === true && $db->fetchOne('SELECT city FROM mandator') === 'Winterthur');
$v = $refused(['account_loss' => '110']);
check('E10d … and re-typing the invalid field to another group is a CHANGE and refused too', $v?->hasFieldError('account_loss') === true);
$db->executeStatement("UPDATE mandator SET account_loss = '3805', account_dunning_fee = '6950', city = 'Bern'");
$check = new LedgerAccountCheck($wireDi());
check('E11 LedgerAccountCheck with financial REGISTERED: true / false / null for empty; the postable list is the chart\'s postable active accounts in order',
    $check->available() && $check->isPostable('1020') === true && $check->isPostable('100') === false && $check->isPostable('') === null
    && count($check->postableAccounts()) > 100 && $check->postableAccounts()[0]['label'] === '1000 Kasse' && array_filter($check->postableAccounts(), fn($r) => $r['number'] === '100') === []);
check('E11b the document accounts (owner 2026-10-12) = postable active revenue accounts flagged «auf Belegen wählbar»: 3200 and 6950 in, 3805 (group 38, off in the chart file) and 1020 out; isDocumentAccount() true / false / null alike',
    in_array('3200', array_column($check->documentAccounts(), 'number'), true) && in_array('6950', array_column($check->documentAccounts(), 'number'), true)
    && !in_array('3805', array_column($check->documentAccounts(), 'number'), true) && !in_array('1020', array_column($check->documentAccounts(), 'number'), true)
    && $check->isDocumentAccount('3200') === true && $check->isDocumentAccount('3805') === false && $check->isDocumentAccount('1020') === false && $check->isDocumentAccount(' ') === null);

echo "E. … without a registered module-financial (a letterhead-only installation)\n";
$writeModules(false);
$rm($base . '/var/cache');
$em = $wireDi();
$check = new LedgerAccountCheck($em);
check('E12 the check cannot tell (null), the class still autoloads, the datalist is empty — no fatal', !$check->available() && $check->isPostable('1020') === null && $check->isPostable('9999999') === null
    && class_exists(LedgerAccountCheck::LEDGER_SERVICE) && $check->postableAccounts() === []
    && $check->documentAccounts() === [] && $check->isDocumentAccount('3200') === null);
(new MandatorService($em))->update((new CurrentMandator($em))->find(), ['account_loss' => '9999999']);
$em = $wireDi();
check('E13 an unverifiable account number SAVES (financial is only suggested) and the status says nothing against it', (new CurrentMandator($em))->find()->account('loss') === '9999999'
    && (new MandatorAccounts($em))->status((new CurrentMandator($em))->find())['loss']['error'] === null);
$writeModules(true);
$rm($base . '/var/cache');
$em = $wireDi();
(new MandatorService($em))->update((new CurrentMandator($em))->find(), ['account_loss' => '3805']);

// ── F. the two access points ─────────────────────────────────────────────

echo "F. The two access points read the record: LedgerService::vatAccountFor(), DebtorAccounts\n";
$em     = $wireDi();
$ledger = new LedgerService($em);
check('F1 vatAccountFor(): input-material → 1170, input-other → 1171, standard / reduced / special → 2200; zero, exempt, reverse-charge and an unknown category → null',
    $ledger->vatAccountFor('input-material') === '1170' && $ledger->vatAccountFor('input-other') === '1171' && $ledger->vatAccountFor('standard') === '2200'
    && $ledger->vatAccountFor('reduced') === '2200' && $ledger->vatAccountFor('special') === '2200'
    && $ledger->vatAccountFor('zero') === null && $ledger->vatAccountFor('exempt') === null && $ledger->vatAccountFor('reverse-charge') === null && $ledger->vatAccountFor('nope') === null);
$debtor = new DebtorAccounts($em);
check('F2 DebtorAccounts::number(): the six keys onto the record\'s accounts (charges → payment-charges, 2026-11-24)', array_map(fn($k) => $debtor->number($k), DebtorAccounts::KEYS) === ['1100', '3800', '3805', '6940', '3809', '6950']
    && $debtor->postableNumber('dunningFee') === '6950' && array_reduce($debtor->status(), fn($ok, $r) => $ok && $r['error'] === null, true));
(new MandatorService($em))->update((new CurrentMandator($em))->find(), ['account_vat_owed' => '', 'account_rounding' => '']);
$em     = $wireDi();
$ledger = new LedgerService($em);
$e      = caught(fn() => (new DebtorAccounts($em))->number('rounding'), AccountNotConfiguredException::class);
check('F3 an EMPTY field: vatAccountFor() answers null; DebtorAccounts refuses in German naming the key and the mandator', $ledger->vatAccountFor('standard') === null && $ledger->vatAccountFor('input-other') === '1171'
    && $e instanceof AccountNotConfiguredException && $e->key === 'rounding' && str_contains($e->getMessage(), 'Rundungsdifferenz') && str_contains($e->getMessage(), 'Mandant'));
$db->executeStatement("UPDATE mandator SET account_vat_owed = '2200', account_rounding = '3809', account_receivable = '100'");   // 100 is a group — past the validator
$em = $wireDi();
$e  = caught(fn() => (new DebtorAccounts($em))->postableNumber('receivable'), AccountNotConfiguredException::class);
check('F4 an account that became a GROUP after the save: refused at the point of use, naming key and mandator; vatAccountFor() hands the number, the caller asks accountExists()',
    $e instanceof AccountNotConfiguredException && str_contains($e->getMessage(), 'Gruppe') && str_contains($e->getMessage(), 'Mandant')
    && (new LedgerService($em))->vatAccountFor('standard') === '2200' && (new LedgerService($em))->accountExists('2200') && !(new LedgerService($em))->accountExists('100'));
$db->executeStatement("UPDATE mandator SET account_receivable = '1100'");
$backup = $db->fetchAssociative('SELECT * FROM mandator');
$db->executeStatement('DELETE FROM mandator');
$em = $wireDi();
$e  = caught(fn() => (new DebtorAccounts($em))->number('receivable'), AccountNotConfiguredException::class);
check('F5 NO mandator: vatAccountFor() answers null (the form refuses with its message), DebtorAccounts refuses naming the missing mandator — nothing fatals',
    (new LedgerService($em))->vatAccountFor('standard') === null && (new CurrentMandator($em))->find() === null
    && $e instanceof AccountNotConfiguredException && str_contains($e->getMessage(), 'kein Mandant')
    && array_reduce((new DebtorAccounts($em))->status(), fn($ok, $r) => $ok && $r['error'] !== null, true));
$db->insert('mandator', $backup);

echo "F. … the record CANNOT be read: module not registered, table missing — a German sentence, never a 500 (review 2026-09-23, P6)\n";
$writeModules(true, withMandator: false);
$rm($base . '/var/cache');
$em = $wireDi();
$e  = caught(fn() => (new LedgerService($em))->vatAccountFor('standard'), \Z77\Module\Financial\Services\VatAccountUnavailableException::class);
check('F5b mandator module NOT registered: vatAccountFor() refuses with the German sentence naming the psr-4 entry, composer update and the migration — not «not announced as a Doctrine entity»',
    $e !== null && $e->reason === 'mandator-unavailable' && str_contains($e->getMessage(), 'nicht registriert') && str_contains($e->getMessage(), 'Z77\\\\Module\\\\Mandator\\\\') && str_contains($e->getMessage(), 'z77-db migrate')
    && $e->getPrevious() instanceof MandatorUnavailableException && $e->getPrevious()->reason === MandatorUnavailableException::MODULE_NOT_REGISTERED);
$e2 = caught(fn() => (new DebtorAccounts($em))->number('receivable'), AccountNotConfiguredException::class);
check('F5c … DebtorAccounts refuses with the same sentence, status() carries it, notice() hands it to the screens, CurrentMandator names it without throwing',
    $e2 instanceof AccountNotConfiguredException && str_contains($e2->getMessage(), 'nicht registriert')
    && array_reduce((new DebtorAccounts($em))->status(), fn($ok, $r) => $ok && str_contains((string) $r['error'], 'nicht registriert'), true)
    && str_contains((string) (new DebtorAccounts($em))->notice(), 'nicht registriert') && str_contains((string) (new CurrentMandator($em))->unavailableReason(), 'nicht registriert')
    && str_contains((string) (new LedgerService($em))->vatAccountNotice(), 'nicht registriert'));
$writeModules(true);
$rm($base . '/var/cache');
$db->executeStatement('RENAME TABLE mandator TO mandator_gone');
$em = $wireDi();
$e  = caught(fn() => (new LedgerService($em))->vatAccountFor('standard'), \Z77\Module\Financial\Services\VatAccountUnavailableException::class);
check('F5d table MISSING (migration not run): the same channel, the sentence names the migration',
    $e !== null && str_contains($e->getMessage(), 'Tabelle fehlt') && str_contains($e->getMessage(), 'z77-db migrate')
    && $e->getPrevious() instanceof MandatorUnavailableException && $e->getPrevious()->reason === MandatorUnavailableException::TABLE_MISSING
    && throws(fn() => (new CurrentMandator($em))->find(), MandatorUnavailableException::class)
    && throws(fn() => (new MandatorService($em))->save(MandatorAccounts::prefilled()), MandatorUnavailableException::class)
    && str_contains((string) (new DebtorAccounts($em))->notice(), 'Tabelle fehlt'));
check('F5e … only THESE two states are translated: a different database failure stays an exception of its own kind', (function () use ($db): bool {
    try { $db->executeStatement('SELECT nope FROM mandator_gone'); return false; } catch (\Doctrine\DBAL\Exception\InvalidFieldNameException) { return true; } catch (\Throwable) { return false; }
})());
$db->executeStatement('RENAME TABLE mandator_gone TO mandator');
$em = $wireDi();
check('F5f restored: vatAccountFor() answers again, no notice anywhere', (new LedgerService($em))->vatAccountFor('standard') === '2200' && (new LedgerService($em))->vatAccountNotice() === null && (new DebtorAccounts($em))->notice() === null && (new CurrentMandator($em))->unavailableReason() === null);

echo "F. … a leftover config key from before E2 is refused loudly (BOOT-CONFIG-001)\n";
$writeOverride = function (string $module, ?string $php) use ($write, $wireDi, $base, $rm): UnifiedEntityManager {
    $file = $base . "/override/module/{$module}/src/App/Config/{$module}Config.inc.php";
    if ($php === null) { @unlink($file); } else { $write("override/module/{$module}/src/App/Config/{$module}Config.inc.php", $php); }
    $rm($base . '/var/cache');

    return $wireDi();
};
$financialEntities = "[\\Z77\\Module\\Financial\\Entities\\Account::class, \\Z77\\Module\\Financial\\Entities\\FiscalYear::class, \\Z77\\Module\\Financial\\Entities\\Period::class, \\Z77\\Module\\Financial\\Entities\\JournalEntry::class, \\Z77\\Module\\Financial\\Entities\\JournalLine::class, \\Z77\\Module\\Financial\\Entities\\EntryChange::class]";
$emOv = $writeOverride('financial', "<?php return ['viewArea' => false, 'doctrineEntities' => {$financialEntities}, 'vatAccounts' => ['input-material' => '1170']];");
if (DI::getModuleManager()->getModuleConfig('financial')?->has('vatAccounts')) {
    $e = caught(fn() => (new LedgerService($emOv))->vatAccountFor('input-material'), \UnexpectedValueException::class);
    check('F6 financialConfig still carrying `vatAccounts` → UnexpectedValueException with a GERMAN sentence naming the move; the record is NOT read past it; the journal band carries it', $e !== null && str_contains($e->getMessage(), 'vatAccounts') && str_contains($e->getMessage(), 'Mandant') && str_contains((string) (new LedgerService($emOv))->vatAccountNotice(), 'vatAccounts'));
} else {
    check('F6 the financial override did not take effect — skipped', false);
}
$writeOverride('financial', null);
$debtorEntities = "[\\Z77\\Module\\Debtor\\Entities\\DebtorProfile::class, \\Z77\\Module\\Debtor\\Entities\\Invoice::class, \\Z77\\Module\\Debtor\\Entities\\InvoiceLine::class, \\Z77\\Module\\Debtor\\Entities\\InvoiceTax::class]";
$emOv = $writeOverride('debtor', "<?php return ['viewArea' => false, 'doctrineEntities' => {$debtorEntities}, 'accountingGateway' => \\Z77\\Module\\Debtor\\Accounting\\LedgerAccountingGateway::class, 'debtorAccounts' => ['receivable' => '1100']];");
if (DI::getModuleManager()->getModuleConfig('debtor')?->has('debtorAccounts')) {
    $e = caught(fn() => (new DebtorAccounts($emOv))->number('receivable'), \UnexpectedValueException::class);
    check('F7 debtorConfig still carrying `debtorAccounts` → UnexpectedValueException with a GERMAN sentence naming the move; status() carries it on every row, notice() as one band', $e !== null && str_contains($e->getMessage(), 'debtorAccounts') && str_contains($e->getMessage(), 'Mandant') && str_contains((string) (new DebtorAccounts($emOv))->notice(), 'debtorAccounts')
        && array_reduce((new DebtorAccounts($emOv))->status(), fn($ok, $r) => $ok && $r['error'] !== null, true));
} else {
    check('F7 the debtor override did not take effect — skipped', false);
}
$em = $writeOverride('debtor', null);
check('F8 without the overrides both access points answer from the record again', (new LedgerService($em))->vatAccountFor('input-material') === '1170' && (new DebtorAccounts($em))->number('receivable') === '1100');

// ── G. the screen ────────────────────────────────────────────────────────

echo "G. The screen — the fragment rendered through its trait with a host double\n";
$useRequest = function (array $get, ?array $post = null): void {
    $_GET  = $get;
    $_POST = $post ?? [];
    $GLOBALS['z77TestIsPost'] = $post !== null;
    DI::getInstance()->set('Request', fn() => new class {
        public function getGetParameter(string $p): mixed { return $_GET[$p] ?? null; }
        public function isPost(): bool { return $GLOBALS['z77TestIsPost']; }
        public function getPostParameters(): array { return $_POST; }
    }, true);
    DI::getInstance()->set('CsrfService', fn() => new class {
        public function generateEntityToken(string $context, int $id): string { return "tok-{$context}-{$id}"; }
        public function validateEntityToken(string $token, string $context, int $id): bool { return $token === "tok-{$context}-{$id}"; }
    }, true);
};
$host = function () {
    return new class {
        use MandatorControllerTrait { editAction as public; }
        public array $context = [];
        public object $layoutManager;
        public object $messageService;
        public object $help;
        public ?string $redirectedTo = null;
        public function __construct()
        {
            $this->help = new \Z77\Core\Services\HelpService();
            $this->layoutManager  = new class { public array $sections = []; public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; } };
            $this->messageService = new class { public array $flashes = []; public function pushFlashAfterRedirect(string $type, string $message): void { $this->flashes[] = [$type, $message]; } };
        }
        protected function em() { return DI::getUnifiedEntityManager(); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context + ['csrfToken' => 'csrf-x']; return new \Z77\Core\Http\Response\HtmlResponse(null, $this->context); }
        protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
    };
};
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';
$renderer = new class($package . '/res/view/templates/') {
    public function __construct(private string $dir) {}
    public function partial(string $path, array $context = [], ?string $ns = null): string
    {
        return (function (string $z77TplPath, array $z77TplContext) { extract($z77TplContext, EXTR_SKIP); ob_start(); require $z77TplPath; return ob_get_clean(); })->call($this, $this->dir . $path . '.tpl.php', $context);
    }
};
$render = fn($h) => $renderer->partial('Backend/MandatorController/edit', $h->context);
$actSlot = fn($h) => implode('', array_map(fn($p) => $renderer->partial($p, $h->context), $h->layoutManager->sections['hc1'] ?? []));
$toolSlot = fn($h) => implode('', array_map(fn($p) => $renderer->partial($p, $h->context), $h->layoutManager->sections['hc2'] ?? []));
// The radio of a tab, and whether the server rendered it `checked`.
$tabChecked = fn(string $html, string $key): bool => preg_match('/<input type="radio" class="be-radiotabs__radio" name="mandator_tab" id="mandator-tab-' . $key . '"[^>]*\schecked>/', $html) === 1;
$layout = MandatorLayout::config();
check('G1 the layout pins the body to the fragment\'s EDIT template in module-mandator — one record, one page', ($layout['levelElements']['body']['main'][0]['nameSpace'] ?? '') === 'Z77\\Module\\Mandator'
    && ($layout['levelElements']['body']['main'][0]['path'] ?? '') === 'Backend/MandatorController' && ($layout['levelElements']['body']['main'][0]['name'] ?? '') === 'edit');
$ref = new \ReflectionClass(MandatorControllerTrait::class);
check('G2 the fragment offers edit only — no list, no add, no delete, no toggle; edit carries #[Csrf] (a page form)', $ref->hasMethod('editAction') && !$ref->hasMethod('listAction') && !$ref->hasMethod('addAction')
    && !$ref->hasMethod('deleteAction') && !$ref->hasMethod('toggleActiveAction') && $ref->getMethod('editAction')->getAttributes(\Z77\Shared\Attributes\Csrf::class) !== []);
check('G3 the mount URL is built in ONE place', substr_count(file_get_contents($ref->getFileName()), "'/backend/finance/mandator'") === 1);

// GET with NO record: the pre-filled empty form.
$db->executeStatement('DELETE FROM mandator');
$em = $wireDi();
$useRequest([]);
$h = $host();
$h->editAction();
$html = $render($h);
check('G4 GET without a record: isNew, the entry pre-filled with the KMU accounts, the datalist of postable accounts, «Mandant anlegen», no entity token', $h->context['isNew'] === true && $h->context['entry']->account('receivable') === '1100'
    && $h->context['entry']->getName() === '' && count($h->context['accounts']) > 100 && $h->context['ledgerKnown'] && $h->context['entityCsrf'] === ''
    && str_contains($html, 'Mandant anlegen') && str_contains($html, '<datalist id="mandator-accounts">') && str_contains($html, 'name="account_vat_owed" value="2200"') && !str_contains($html, 'name="entity_csrf"'));
check('G5 the template: a plain page form with csrf_token, the hidden 0 before the VAT checkbox, no <script>, no inline handler, no placeholder (P2 exit check 3a)',
    str_contains($html, '<form method="post" action="/backend/finance/mandator/edit"') && str_contains($html, 'name="csrf_token" value="csrf-x"')
    && strpos($html, '<input type="hidden" name="liable_to_vat" value="0">') < strpos($html, 'type="checkbox" id="mandator-liable"')
    && stripos($html, '<script') === false && !preg_match('/\son[a-z]+\s*=/i', $html) && !str_contains($html, 'placeholder='));
check('G4b the submit stands in the action cell (hc1, ADR-033 rev. 2026-10-08): a green confirm button «Mandant anlegen» with the check glyph, submitting the form from outside (form="mandator-edit") — no submit left at the end of the body',
    $h->layoutManager->sections['hc1'] === ['Backend/MandatorController/editAct'] && str_contains($html, 'id="mandator-edit"')
    && preg_match('/<button type="submit" form="mandator-edit" class="be-btn be-btn--confirm">\s*<svg[^>]*><use href="#icon-check"\/><\/svg>\s*<span class="be-btn__label">Mandant anlegen<\/span>/', $actSlot($h)) === 1
    && !str_contains($html, 'type="submit"'));
$tb = $toolSlot($h);
check('G4c the four sections are radio tabs (owner 2026-10-08; «Belege» 2026-10-21): ONE form (novalidate), a radio per tab BEFORE the four panels, the first tab open; the tab labels stand in the toolbar (hc2) as `<label for>` of the radios, numbered like radio and panel',
    $h->layoutManager->sections['hc2'] === ['Backend/MandatorController/editToolbar'] && $h->context['openTab'] === 'briefkopf'
    && substr_count($html, '<form') === 1 && preg_match('/<form [^>]*class="be-list__section be-radiotabs"[^>]* novalidate>/', $html) === 1
    && substr_count($html, 'class="be-radiotabs__radio"') === 4 && substr_count($html, '<section class="be-radiotabs__panel"') === 4
    && str_contains($html, '<section class="be-radiotabs__panel" data-tab="4" aria-label="Belege">') && strpos($html, 'id="mandator-default_subject_label"') > strpos($html, 'data-tab="4" aria-label')
    && strrpos($html, 'be-radiotabs__radio') < strpos($html, 'be-radiotabs__panel')
    && $tabChecked($html, 'briefkopf') && !$tabChecked($html, 'uid') && !$tabChecked($html, 'konten')
    && str_contains($html, '<section class="be-radiotabs__panel" data-tab="1" aria-label="Briefkopf">') && str_contains($html, '<section class="be-radiotabs__panel" data-tab="2" aria-label="UID und MWST">') && str_contains($html, '<section class="be-radiotabs__panel" data-tab="3" aria-label="Konten">')
    && strpos($html, 'id="mandator-name"') < strpos($html, 'data-tab="2" aria-label') && strpos($html, 'id="mandator-uid"') < strpos($html, 'data-tab="3" aria-label') && strpos($html, 'id="mandator-account_receivable"') > strpos($html, 'data-tab="3" aria-label')
    && str_contains($tb, '<label class="be-viewtabs__tab be-viewtabs__tab--for" for="mandator-tab-briefkopf" data-tab="1">Briefkopf</label>')
    && str_contains($tb, '<label class="be-viewtabs__tab be-viewtabs__tab--for" for="mandator-tab-uid" data-tab="2">UID und MWST</label>')
    && str_contains($tb, '<label class="be-viewtabs__tab be-viewtabs__tab--for" for="mandator-tab-konten" data-tab="3">Konten</label>')
    && str_contains($tb, '<label class="be-viewtabs__tab be-viewtabs__tab--for" for="mandator-tab-belege" data-tab="4">Belege</label>')
    && !str_contains($tb, 'is-active') && stripos($tb, '<script') === false);
check('G5b the rule of thumb stands on the page: the mandator is the letterhead, the payment target is the payee', str_contains($html, 'Zahlungsziel') && str_contains($html, 'kein Fehler'));
check('G5c «Hinweis ohne MWST» (owner 2026-10-22): a text field directly under the switch on the tab «UID und MWST» (after the switch, before tab 3), maxlength 255, its hint names where it prints, that the switch must be off and the usual Swiss wording — as hint text, not a placeholder (G5)',
    preg_match('/<span class="be-switch__label">Mehrwertsteuerpflichtig<\/span>.*?<label for="mandator-vat_exempt_note">Hinweis ohne MWST<\/label>\s*<input type="text" id="mandator-vat_exempt_note" name="vat_exempt_note" value="" maxlength="255"/s', $html) === 1
    && strpos($html, 'id="mandator-vat_exempt_note"') > strpos($html, 'data-tab="2" aria-label') && strpos($html, 'id="mandator-vat_exempt_note"') < strpos($html, 'data-tab="3" aria-label')
    && str_contains($html, 'solange der Schalter aus ist') && str_contains($html, 'unter dem Total gedruckt') && str_contains($html, 'Üblich: «Keine MWST — der Rechnungssteller ist nicht mehrwertsteuerpflichtig.»'));

// POST: the first save creates the record.
$postBody = ['csrf_token' => 'csrf-x', 'name' => 'Screen AG', 'street' => 'Weg', 'house_no' => '1', 'zip' => '3000', 'city' => 'Bern', 'country' => 'ch', 'email' => 'Mail@Screen.ch', 'uid' => 'che 109322551 MWST', 'liable_to_vat' => '1', 'vat_exempt_note' => '  Keine MWST — der  Rechnungssteller ist nicht mehrwertsteuerpflichtig. ']
    + array_combine(array_map(fn($k) => Mandator::accountField($k), array_keys(MandatorAccounts::DEFAULTS)), array_values(MandatorAccounts::DEFAULTS));
$useRequest([], $postBody);
$h = $host();
$h->editAction();
$em = $wireDi();
check('G6 POST creates the one record, redirects back (303) with a success flash; the values normalised', $h->redirectedTo === '/backend/finance/mandator/edit' && ($h->messageService->flashes[0][0] ?? '') === 'success'
    && $count('mandator') === 1 && ($m = (new CurrentMandator($em))->find())->getName() === 'Screen AG' && $m->getCountry() === 'CH' && $m->getUid() === 'CHE-109.322.551' && $m->isLiableToVat() && $m->getEmail() === 'mail@screen.ch');
check('G6b … the note saved one-lined and trimmed; stored while liable, documentVatNote() answers null until the switch is off', $m->getVatExemptNote() === 'Keine MWST — der Rechnungssteller ist nicht mehrwertsteuerpflichtig.'
    && $m->documentVatNote() === null && $db->fetchOne('SELECT vat_exempt_note FROM mandator') === 'Keine MWST — der Rechnungssteller ist nicht mehrwertsteuerpflichtig.');
// POST a refused change on the existing record: the draft is shown, the entity untouched.
$id = $m->getId();
$useRequest([], ['csrf_token' => 'csrf-x', 'entity_csrf' => "tok-mandator-{$id}", 'name' => 'Screen AG', 'country' => 'CH', 'uid' => 'CHE-109.322.552', 'account_receivable' => '100', 'city' => 'Thun'] + array_diff_key($postBody, ['uid' => 1, 'account_receivable' => 1, 'city' => 1, 'liable_to_vat' => 1]));
$h = $host();
$h->editAction();
$html = $render($h);
check('G7 a refused POST re-renders with the DRAFT (Thun, the bad UID) and its field errors — no redirect, the stored record unchanged (Bern)', $h->redirectedTo === null && $h->context['isNew'] === false
    && $h->context['validator']->hasFieldError('uid') && $h->context['validator']->hasFieldError('account_receivable') && $h->context['entry']->getCity() === 'Thun'
    && str_contains($html, 'Prüfziffer') && str_contains($html, 'Gruppe') && str_contains($html, 'aria-invalid="true"')
    && $db->fetchOne('SELECT city FROM mandator') === 'Bern' && $db->fetchOne('SELECT account_receivable FROM mandator') === '1100');
check('G7b … the unchecked checkbox arrived as 0 in the draft (the hidden field)', !$h->context['entry']->isLiableToVat());
check('G7c … the page opens the tab holding the FIRST field with an error: the UID (tab 2) before the account (tab 3)',
    $h->context['openTab'] === 'uid' && $tabChecked($html, 'uid') && !$tabChecked($html, 'briefkopf') && !$tabChecked($html, 'konten'));
// Only an account refused → the Konten tab; only the name missing → Briefkopf.
$useRequest([], ['csrf_token' => 'csrf-x', 'entity_csrf' => "tok-mandator-{$id}", 'account_receivable' => '100'] + array_diff_key($postBody, ['account_receivable' => 1]));
$h = $host();
$h->editAction();
$html = $render($h);
$kontenOnly = $h->redirectedTo === null && array_keys($h->context['validator']->getFieldErrors()) === ['account_receivable'] && $h->context['openTab'] === 'konten' && $tabChecked($html, 'konten') && !$tabChecked($html, 'briefkopf');
$useRequest([], ['csrf_token' => 'csrf-x', 'entity_csrf' => "tok-mandator-{$id}", 'name' => '', 'account_receivable' => '100'] + $postBody);
$h = $host();
$h->editAction();
$html = $render($h);
check('G7d … an account error alone opens «Konten»; a missing name opens «Briefkopf» even with an account error behind it (MandatorLayout::openTab)',
    $kontenOnly && $h->context['validator']->hasFieldError('name') && $h->context['openTab'] === 'briefkopf' && $tabChecked($html, 'briefkopf') && !$tabChecked($html, 'konten')
    && $db->fetchOne('SELECT name FROM mandator') === 'Screen AG');
// A wrong entity token on an existing record.
$useRequest([], ['csrf_token' => 'csrf-x', 'entity_csrf' => 'wrong', 'name' => 'Hijack AG', 'country' => 'CH']);
$h = $host();
$h->editAction();
check('G8 a wrong entity token: redirect with an error flash, nothing written', $h->redirectedTo === '/backend/finance/mandator/edit' && ($h->messageService->flashes[0][0] ?? '') === 'error' && $db->fetchOne('SELECT name FROM mandator') === 'Screen AG');
// GET the existing record.
$useRequest([]);
$h = $host();
$h->editAction();
$html = $render($h);
check('G9 GET with the record: not new, the entity token in the form, «Speichern», the status badges, no tax-code select', $h->context['isNew'] === false && str_contains($html, 'name="entity_csrf" value="tok-mandator-' . $id . '"')
    && str_contains($actSlot($h), '>Speichern<') && str_contains($html, 'badge--success') && !str_contains($html, 'default_tax_code') && $h->context['unavailable'] === null);
check('G9c … the stored note stands in its field', str_contains($html, 'name="vat_exempt_note" value="Keine MWST — der Rechnungssteller ist nicht mehrwertsteuerpflichtig."'));
// The record cannot be read (table missing): the page shows the sentence as a band and no form (review 2026-09-23, P6d).
$db->executeStatement('RENAME TABLE mandator TO mandator_gone');
$em = $wireDi();
$useRequest([]);
$h = $host();
$h->editAction();
$html = $render($h);
check('G9b GET with the table MISSING: the German band naming the migration, no form, no 500', str_contains((string) $h->context['unavailable'], 'Tabelle fehlt') && str_contains($html, 'be-modal__alert--error')
    && str_contains($html, 'z77-db migrate') && !str_contains($html, '<form') && $h->redirectedTo === null);
$db->executeStatement('RENAME TABLE mandator_gone TO mandator');
$em = $wireDi();
// Without financial: the datalist disappears, the status reads «ungeprüft».
$writeModules(false);
$rm($base . '/var/cache');
$em = $wireDi();
$useRequest([]);
$h = $host();
$h->editAction();
$html = $render($h);
check('G10 without a registered module-financial the page still renders: no datalist, «ungeprüft», the hint that nothing is checked', !$h->context['ledgerKnown'] && $h->context['accounts'] === []
    && !str_contains($html, '<datalist') && str_contains($html, 'ungeprüft') && str_contains($html, 'Ohne z77/module-financial'));
$writeModules(true);
$rm($base . '/var/cache');
$backend = str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-backend'));
check('G11 module-backend mounts the fragment under the finance group and names edit as the controller\'s defaultAction (one record, no list)',
    is_file($backend . '/src/Ui/Controllers/Finance/MandatorController.php') && is_file($backend . '/src/Ui/Config/Finance/mandatorControllerConfig.inc.php')
    && str_contains(file_get_contents($backend . '/src/Ui/Controllers/Finance/MandatorController.php'), 'use MandatorControllerTrait;')
    && preg_match("/'MandatorController'\s*=>\s*\[\s*'defaultAction'\s*=>\s*'edit'/", file_get_contents($backend . '/src/App/Config/backendConfig.inc.php')) === 1);
check('G12 the package ships no JavaScript and no CSS of its own (Rule 7)', glob($package . '/res/**/*.js') === [] && glob($package . '/res/*.js') === [] && !is_dir($package . '/res/scss'));

// ── J. the VAT return's settings (ADR-041 addendum 2026-11-24) and the UID rule ─

echo "J. VAT return settings: migration, persistence, validator, read API, setup check, screen\n";
$em = $wireDi();
$vatCols = $db->fetchAllAssociativeIndexed('SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?, ?, ?, ?, ?, ?)',
    [$dbName, 'mandator', 'vat_method', 'vat_consideration', 'vat_settlement_rhythm', 'vat_settlement_from', 'vat_settlement_until', 'account_vat_settlement', 'account_vat_rounding']);
check('J1 Version20261124120000 (expand only): vat_method / vat_consideration / vat_settlement_rhythm VARCHAR(16) NULL, vat_settlement_from / _until DATE NULL — NULL = «not set», no silent default; account_vat_settlement / account_vat_rounding VARCHAR(10) NOT NULL DEFAULT \'\' (an existing record names them)',
    count($vatCols) === 7
    && array_reduce(['vat_method', 'vat_consideration', 'vat_settlement_rhythm'], fn($ok, $c) => $ok && $vatCols[$c]['DATA_TYPE'] === 'varchar' && (int) $vatCols[$c]['CHARACTER_MAXIMUM_LENGTH'] === 16 && $vatCols[$c]['IS_NULLABLE'] === 'YES' && in_array($vatCols[$c]['COLUMN_DEFAULT'], [null, 'NULL'], true), true)
    && $vatCols['vat_settlement_from']['DATA_TYPE'] === 'date' && $vatCols['vat_settlement_from']['IS_NULLABLE'] === 'YES' && $vatCols['vat_settlement_until']['DATA_TYPE'] === 'date'
    && array_reduce(['account_vat_settlement', 'account_vat_rounding'], fn($ok, $c) => $ok && $vatCols[$c]['IS_NULLABLE'] === 'NO' && trim((string) $vatCols[$c]['COLUMN_DEFAULT'], "'") === '' && (int) $vatCols[$c]['CHARACTER_MAXIMUM_LENGTH'] === 10, true));
$vatColNames = static fn(): array => $db->fetchFirstColumn('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND (COLUMN_NAME LIKE ? OR COLUMN_NAME LIKE ?) ORDER BY COLUMN_NAME', [$dbName, 'mandator', 'vat\_%', 'account\_vat\_%']);
$vatColsBefore        = $vatColNames();
[$toCode, $toOut]     = $run(['command' => 'migrate', 'version' => 'Z77\Module\Mandator\Migrations\Version20261124120000']);
[$downCode, $downOut] = $run(['command' => 'migrate', 'version' => 'prev']);
$vatColsDown          = $vatColNames();
[$upCode, $upOut]     = $run(['command' => 'migrate']);
check('J1b down() of Version20261124120000 drops exactly its seven columns (vat_exempt_note and the three VAT accounts stay); up() brings them back',
    $toCode === 0 && $downCode === 0 && $upCode === 0 && $vatColNames() === $vatColsBefore
    && $vatColsDown === ['account_vat_input_material', 'account_vat_input_other', 'account_vat_owed', 'vat_exempt_note']);
if ($vatColsDown !== ['account_vat_input_material', 'account_vat_input_other', 'account_vat_owed', 'vat_exempt_note']) {
    echo '       ', json_encode($vatColsDown), ' ', trim($toOut . $downOut . $upOut), "\n";
}
// down() / up() emptied the two accounts G saved (the column default '') — put the KMU numbers back.
$db->executeStatement("UPDATE mandator SET account_vat_settlement = '2201', account_vat_rounding = '6945' WHERE id = 1");

// The state after G: «Screen AG», liable, a UID, the KMU accounts incl. 2201 / 6945; the VAT settings not set.
$em = $wireDi();
$current = (new CurrentMandator($em))->find();
$settingsNone = (new CurrentMandator($em))->vatSettings();
check('J2 a liable record saved without the VAT settings: every one null — vatSettings() reports liable and nothing set (no default)',
    $current !== null && $current->isLiableToVat() && $settingsNone instanceof \Z77\Module\Mandator\Services\VatSettings && $settingsNone->liable
    && $settingsNone->method === null && $settingsNone->consideration === null && $settingsNone->rhythm === null && $settingsNone->from === null && $settingsNone->until === null);
(new MandatorService($em))->update($current, ['vat_method' => 'effective', 'vat_consideration' => 'agreed', 'vat_settlement_rhythm' => 'quarter', 'vat_settlement_from' => '2027-02-17', 'vat_settlement_until' => '']);
$em  = $wireDi();
$vs  = (new CurrentMandator($em))->vatSettings();
$row = $db->fetchAssociative('SELECT vat_method, vat_consideration, vat_settlement_rhythm, vat_settlement_from, vat_settlement_until FROM mandator WHERE id = 1');
check('J3 the settings persist and load: the enums by value, «Abrechnung ab» ANY day (17.02. — a liability that began mid-period; the grid is the period calculator\'s), «bis» empty = open-ended; the row holds the strings',
    $vs->liable && $vs->method === \Z77\Module\Mandator\Entities\VatMethod::Effective && $vs->consideration === \Z77\Module\Mandator\Entities\VatConsideration::Agreed
    && $vs->rhythm === \Z77\Module\Mandator\Entities\VatRhythm::Quarter && $vs->from?->format('Y-m-d') === '2027-02-17' && $vs->until === null
    && $row === ['vat_method' => 'effective', 'vat_consideration' => 'agreed', 'vat_settlement_rhythm' => 'quarter', 'vat_settlement_from' => '2027-02-17', 'vat_settlement_until' => null]);
check('J3b VatSettings is immutable (readonly class), and a stored value the enum does not know reads as «not set»',
    (new \ReflectionClass(\Z77\Module\Mandator\Services\VatSettings::class))->isReadOnly()
    && (function () use ($db, $wireDi): bool {
        $db->executeStatement("UPDATE mandator SET vat_settlement_rhythm = 'semester' WHERE id = 1");
        $rhythm = (new CurrentMandator($wireDi()))->vatSettings()->rhythm;
        $db->executeStatement("UPDATE mandator SET vat_settlement_rhythm = 'quarter' WHERE id = 1");
        return $rhythm === null;
    })());
$vField = function (array $data, string $field): string {
    $v = new MandatorValidator(new Mandator(['name' => 'x', 'country' => 'CH'] + $data));
    $v->isValid();
    return $v->getFieldError($field);
};
check('J4 validator: an unknown method / consideration / rhythm is refused naming the field; the semester (net tax rate only) is no rhythm; the known values pass; all empty is valid, liable or not (NOT required on save — the return refuses, the setup check asks)',
    str_contains($vField(['vat_method' => 'flat-rate'], 'vat_method'), 'Abrechnungsmethode') && str_contains($vField(['vat_consideration' => 'later'], 'vat_consideration'), 'Abrechnungsart')
    && str_contains($vField(['vat_settlement_rhythm' => 'semester'], 'vat_settlement_rhythm'), 'Abrechnungsrhythmus')
    && $vField(['vat_method' => 'net-tax-rate', 'vat_consideration' => 'received', 'vat_settlement_rhythm' => 'year'], 'vat_method') === ''
    && $vField(['vat_settlement_rhythm' => 'month'], 'vat_settlement_rhythm') === ''
    && (new MandatorValidator(new Mandator(['name' => 'x', 'country' => 'CH', 'liable_to_vat' => true, 'uid' => 'CHE-109.322.551'])))->isValid()
    && (new MandatorValidator(new Mandator(['name' => 'x', 'country' => 'CH'])))->isValid());
check('J5 validator: «Abrechnung bis» before «Abrechnung ab» refused on «bis»; the same day and later pass; an unreadable date refused naming the input; d.m.Y typed by hand is read',
    str_contains($vField(['vat_settlement_from' => '2027-04-01', 'vat_settlement_until' => '2027-03-31'], 'vat_settlement_until'), 'vor «Abrechnung ab»')
    && $vField(['vat_settlement_from' => '2027-04-01', 'vat_settlement_until' => '2027-04-01'], 'vat_settlement_until') === ''
    && $vField(['vat_settlement_from' => '2027-04-01', 'vat_settlement_until' => '2029-12-31'], 'vat_settlement_until') === ''
    && str_contains($vField(['vat_settlement_from' => '31.02.2027'], 'vat_settlement_from'), '«31.02.2027» ist kein Datum')
    && str_contains($vField(['vat_settlement_until' => 'bald'], 'vat_settlement_until'), 'kein Datum')
    && (new Mandator(['vat_settlement_from' => '01.07.2027']))->getVatSettlementFrom()?->format('Y-m-d') === '2027-07-01'
    && MandatorLayout::tabOf('vat_settlement_until') === 'uid' && MandatorLayout::tabOf('vat_method') === 'uid');
check('J6 the UID is REQUIRED while liable (owner 2026-11-24, MANDATOR-PDF-UID-001): liable + empty → the field error; not liable + empty → valid; a set UID is shape- and check-digit-validated in both cases',
    str_contains($vField(['liable_to_vat' => true, 'uid' => ''], 'uid'), 'erforderlich') && $vField(['liable_to_vat' => false, 'uid' => ''], 'uid') === ''
    && str_contains($vField(['liable_to_vat' => true, 'uid' => 'CHE-109.322.552'], 'uid'), 'Prüfziffer') && str_contains($vField(['liable_to_vat' => false, 'uid' => 'CHE-109.322.552'], 'uid'), 'Prüfziffer')
    && $vField(['liable_to_vat' => true, 'uid' => 'CHE-109.322.551'], 'uid') === '');
$db->executeStatement("UPDATE mandator SET uid = '' WHERE id = 1");   // a liable record stored before the rule
$em = $wireDi();
$legacy = (new CurrentMandator($em))->find();
$v = $refused(['city' => 'Biel']);
$db->executeStatement("UPDATE mandator SET uid = 'CHE-109.322.551' WHERE id = 1");
check('J6b … an EXISTING liable record without a UID still loads; the rule bites on its next save (any change), naming the UID — the dashboard line «Mandant MWST-pflichtig ohne UID» stays the signal',
    $legacy !== null && $legacy->isLiableToVat() && $legacy->getUid() === '' && $v?->hasFieldError('uid') === true && $db->fetchOne('SELECT city FROM mandator') !== 'Biel');

echo "J. … the setup check `mandator.accounts` asks for the VAT settings of a LIABLE mandator only\n";
$step = static function () use ($wireDi): array {
    $wireDi();
    $check = new \Z77\Module\Mandator\ProjectHealth\MandatorAccountsCheck();
    $open  = $check->isOpen();
    return [$open, $open ? $check->label() : ''];
};
$complete = $step();
$db->executeStatement('UPDATE mandator SET vat_method = NULL, vat_consideration = NULL, vat_settlement_rhythm = NULL, vat_settlement_from = NULL WHERE id = 1');
$allMissing = $step();
$db->executeStatement("UPDATE mandator SET account_vat_settlement = '', account_vat_rounding = '' WHERE id = 1");
$withAccounts = $step();
$db->executeStatement('UPDATE mandator SET liable_to_vat = 0 WHERE id = 1');
$notLiable = $step();
$db->executeStatement("UPDATE mandator SET liable_to_vat = 1, vat_method = 'net-tax-rate', vat_consideration = 'received', vat_settlement_rhythm = 'month', vat_settlement_from = '2027-01-01', account_vat_settlement = '2201', account_vat_rounding = '6946' WHERE id = 1");
$roundingNotInChart = $step();
$db->executeStatement("UPDATE mandator SET account_vat_rounding = '6945', account_discount = '' WHERE id = 1");
$baseFirst = $step();
$db->executeStatement("UPDATE mandator SET account_discount = '3800', vat_method = 'effective', vat_consideration = 'agreed', vat_settlement_rhythm = 'quarter' WHERE id = 1");
check('J7 liable, everything set → silent; method, consideration, rhythm and «ab» missing → ONE line naming each («Mandant MWST: … festlegen», the logo step\'s style); the two accounts empty → named too',
    $complete === [false, ''] && $allMissing === [true, 'Mandant MWST: Abrechnungsmethode, Abrechnungsart, Abrechnungsrhythmus und Abrechnung ab festlegen']
    && $withAccounts === [true, 'Mandant MWST: Abrechnungsmethode, Abrechnungsart, Abrechnungsrhythmus, Abrechnung ab, Abrechnungskonto und Rundungskonto festlegen']);
if ($complete !== [false, '']) {
    echo '       ', json_encode([$complete, $allMissing, $withAccounts]), "\n";
}
check('J7b NOT liable → never nagged, although every VAT item is missing; a rounding account the chart lacks (an existing chart before 6945) is named; a supported-or-not method is no setup question (the return refuses net tax rate / received)',
    $notLiable === [false, ''] && $roundingNotInChart === [true, 'Mandant MWST: Rundungskonto festlegen']);
check('J7c the existing behaviour holds: a start-value account missing → «Mandant: Konten zuweisen» first (the VAT items follow); all set → silent; no record → «Mandant erfassen»',
    $baseFirst === [true, 'Mandant: Konten zuweisen'] && $step() === [false, '']
    && (function () use ($db, $step): bool {
        $backup = $db->fetchAssociative('SELECT * FROM mandator');
        $db->executeStatement('DELETE FROM mandator');
        $none = $step();
        $db->insert('mandator', $backup);
        return $none === [true, 'Mandant erfassen'];
    })());

echo "J. … the screen: the VAT settings under the switch, the accounts next to the VAT accounts, the help\n";
$em = $wireDi();
$useRequest([]);
$h = $host();
$h->editAction();
$html = $render($h);
$pos  = static fn(string $needle): int => (int) strpos($html, $needle);
check('J8 the switch drives a CSS reveal (no script): the hidden 0, the checkbox as `.be-reveal__toggle` (checked while liable), the switch a `<label for>`; the panel AFTER it holds Abrechnungsmethode, Abrechnungsart, Abrechnungsrhythmus (Quartal / Monat / Jahr), Abrechnung ab / bis — all on «UID und MWST», before «Konten»',
    str_contains($html, '<input type="checkbox" id="mandator-liable" class="be-reveal__toggle" name="liable_to_vat" value="1" checked>')
    && str_contains($html, '<label class="be-switch be-switch--for" for="mandator-liable">')
    && $pos('<div class="be-reveal__panel" data-mandator-vat-return>') > $pos('id="mandator-liable"')
    && $pos('id="mandator-vat_method"') > $pos('data-mandator-vat-return') && $pos('id="mandator-vat_settlement_until"') < $pos('data-tab="3" aria-label')
    && str_contains($html, '<label for="mandator-vat_method">Abrechnungsmethode</label>') && str_contains($html, '<label for="mandator-vat_consideration">Abrechnungsart</label>')
    && str_contains($html, '<label for="mandator-vat_settlement_rhythm">Abrechnungsrhythmus</label>') && str_contains($html, '<label for="mandator-vat_settlement_from">Abrechnung ab</label>')
    && str_contains($html, '<label for="mandator-vat_settlement_until">Abrechnung bis</label>')
    && str_contains($html, '<option value="effective" selected>Effektive Methode</option>') && str_contains($html, '<option value="agreed" selected>Vereinbartes Entgelt</option>')
    && str_contains($html, '<option value="quarter" selected>Quartal</option><option value="month">Monat</option><option value="year">Jahr</option>')
    && str_contains($html, 'name="vat_settlement_from" value="2027-01-01"') && preg_match('/<input type="date" id="mandator-vat_settlement_until" name="vat_settlement_until" value=""/', $html) === 1
    && stripos($html, '<script') === false && !str_contains($html, 'placeholder='));
check('J8b the select keys are the enum values (labels live in the UI layer)',
    array_keys(MandatorLayout::VAT_METHODS) === array_column(\Z77\Module\Mandator\Entities\VatMethod::cases(), 'value')
    && array_keys(MandatorLayout::VAT_CONSIDERATIONS) === array_column(\Z77\Module\Mandator\Entities\VatConsideration::cases(), 'value')
    && array_keys(MandatorLayout::VAT_RHYTHMS) === array_column(\Z77\Module\Mandator\Entities\VatRhythm::cases(), 'value'));
check('J8c the UID input is `required` while liable; the two accounts stand on «Konten» right after «Geschuldete MWST» (screen order), with their labels and the pre-fill',
    preg_match('/<input type="text" id="mandator-uid" name="uid" value="CHE-109.322.551" maxlength="20" autocomplete="off" required/', $html) === 1
    && MandatorLayout::accountKeys() === ['receivable', 'discount', 'loss', 'payment-charges', 'rounding', 'dunning-fee', 'vat-input-material', 'vat-input-other', 'vat-owed', 'vat-settlement', 'vat-rounding', 'opening-balance', 'annual-result', 'private', 'bank-transfer']
    && $pos('id="mandator-account_vat_owed"') < $pos('id="mandator-account_vat_settlement"') && $pos('id="mandator-account_vat_rounding"') < $pos('id="mandator-account_opening_balance"')
    && $pos('id="mandator-account_vat_settlement"') > $pos('data-tab="3" aria-label')
    && str_contains($html, '>Abrechnungskonto MWST</label>') && str_contains($html, '>Rundungsdifferenzen MWST</label>') && str_contains($html, 'name="account_vat_rounding" value="6945"'));
$db->executeStatement('UPDATE mandator SET liable_to_vat = 0 WHERE id = 1');
$em = $wireDi();
$useRequest([]);
$h = $host();
$h->editAction();
$htmlOff = $render($h);
$db->executeStatement('UPDATE mandator SET liable_to_vat = 1 WHERE id = 1');
check('J8d not liable: the checkbox unchecked (the panel hidden by CSS, its stored values still in the form so a save keeps them), the UID not required',
    str_contains($htmlOff, '<input type="checkbox" id="mandator-liable" class="be-reveal__toggle" name="liable_to_vat" value="1">')
    && preg_match('/id="mandator-uid"[^>]*required/', $htmlOff) === 0 && str_contains($htmlOff, '<option value="effective" selected>'));
$useRequest([], ['csrf_token' => 'csrf-x', 'entity_csrf' => 'tok-mandator-1', 'name' => 'Screen AG', 'country' => 'CH', 'uid' => 'CHE-109.322.551', 'liable_to_vat' => '1',
    'vat_method' => 'effective', 'vat_consideration' => 'agreed', 'vat_settlement_rhythm' => 'month', 'vat_settlement_from' => '2027-01-01', 'vat_settlement_until' => '2026-12-31']);
$h = $host();
$h->editAction();
$htmlRefused = $render($h);
check('J9 a POST with «bis» before «ab» is refused on «Abrechnung bis», the page opens «UID und MWST», nothing written',
    $h->redirectedTo === null && $h->context['validator']->hasFieldError('vat_settlement_until') && $h->context['openTab'] === 'uid'
    && $tabChecked($htmlRefused, 'uid') && $db->fetchOne('SELECT vat_settlement_rhythm FROM mandator') === 'quarter');
$useRequest([], ['csrf_token' => 'csrf-x', 'entity_csrf' => 'tok-mandator-1', 'name' => 'Screen AG', 'country' => 'CH', 'uid' => 'CHE-109.322.551', 'liable_to_vat' => '1',
    'vat_method' => '', 'vat_consideration' => 'agreed', 'vat_settlement_rhythm' => 'month', 'vat_settlement_from' => '2027-01-01', 'vat_settlement_until' => '2030-12-31']);
$h = $host();
$h->editAction();
$em = $wireDi();
$vs = (new CurrentMandator($em))->vatSettings();
check('J9b a valid POST saves through the screen: «nicht festgelegt» stores NULL, the month, «bis» 31.12.2030',
    $h->redirectedTo === '/backend/finance/mandator/edit' && $vs->method === null && $vs->rhythm === \Z77\Module\Mandator\Entities\VatRhythm::Month
    && $vs->until?->format('Y-m-d') === '2030-12-31' && $db->fetchOne('SELECT vat_method FROM mandator') === null);
$helpTpl = (string) file_get_contents($package . '/res/view/templates/Backend/MandatorController/edit.help.tpl.php');
check('J10 the help: one section per new field; it says only «effektiv + vereinbart» is supported, the rhythm conditions, what «Abrechnung ab» means; the UID required while liable',
    array_reduce(['vat_method', 'vat_consideration', 'vat_settlement_rhythm', 'vat_settlement_from', 'vat_settlement_until', 'account_vat_settlement', 'account_vat_rounding'],
        fn($ok, $f) => $ok && str_contains($helpTpl, 'data-help-field="' . $f . '"'), true)
    && str_contains($helpTpl, 'nur die effektive Methode zusammen mit dem vereinbarten Entgelt') && str_contains($helpTpl, 'nur auf Antrag')
    && str_contains($helpTpl, 'rechnet z77 nie nach') && str_contains($helpTpl, 'Ende einer Periode') && str_contains($helpTpl, 'Pflicht, solange «Mehrwertsteuerpflichtig» eingeschaltet ist'));
$db->executeStatement('RENAME TABLE mandator TO mandator_gone');
$em = $wireDi();
$unreadable = caught(fn() => (new CurrentMandator($em))->vatSettings(), MandatorUnavailableException::class);
$db->executeStatement('RENAME TABLE mandator_gone TO mandator');
$em = $wireDi();
check('J11 vatSettings() on an UNREADABLE record throws MandatorUnavailableException (a return refuses naming the reason, never runs on a guess); none() = liable, nothing set',
    $unreadable !== null && \Z77\Module\Mandator\Services\VatSettings::none()->liable && \Z77\Module\Mandator\Services\VatSettings::none()->method === null);

echo "J. … the PDF letterhead prints the UID (MANDATOR-PDF-UID-001, 2026-11-24)\n";
$letterhead = static function (array $data): string {
    return \Z77\Shared\Pdf\PdfDocument::create('Briefkopf')->withoutCompression()->addPage()
        ->partial('pdf/letterhead', ['mandator' => new Mandator(['name' => 'Harness AG', 'street' => 'Weg', 'house_no' => '1', 'zip' => '3000', 'city' => 'Bern', 'phone' => '031 000 00 00'] + $data), 'x' => 20, 'y' => 12], 'Z77\\Module\\Mandator')
        ->output();
};
$liablePdf    = $letterhead(['uid' => 'CHE-109.322.551', 'liable_to_vat' => true]);
$notLiablePdf = $letterhead(['uid' => 'CHE-109.322.551', 'liable_to_vat' => false]);
$noUidPdf     = $letterhead(['uid' => '', 'liable_to_vat' => true]);
$noUidOffPdf  = $letterhead(['uid' => '', 'liable_to_vat' => false]);
// The y of the contact line (PDF points): with the UID line it moves down by one line (4.2 mm), without it nothing is left blank.
$contactY = static function (string $pdf): ?float {
    return preg_match('/([\d.]+) Td \(031 000 00 00\)/', $pdf, $m) === 1 ? (float) $m[1] : null;
};
check('J12 liable with a UID → «CHE-109.322.551 MWST» on the PDF letterhead (the invoice and the dunning notice both draw `pdf/letterhead`); not liable → the UID WITHOUT « MWST»',
    str_contains($liablePdf, '(CHE-109.322.551 MWST)') && str_contains($notLiablePdf, '(CHE-109.322.551)') && !str_contains($notLiablePdf, 'CHE-109.322.551 MWST'));
check('J12b no UID (liable or not) → no line and no gap: the contact line one line (4.2 mm) higher than with the UID, the same for both',
    !str_contains($noUidPdf, 'CHE-') && !str_contains($noUidOffPdf, 'CHE-') && $contactY($noUidPdf) !== null && $contactY($noUidPdf) === $contactY($noUidOffPdf)
    && $contactY($liablePdf) !== null && abs(abs($contactY($liablePdf) - $contactY($noUidPdf)) - 4.2 * 72 / 25.4) < 0.05);
if ($contactY($liablePdf) === null || $contactY($noUidPdf) === null) {
    echo '       contact y: ', json_encode([$contactY($liablePdf), $contactY($noUidPdf)]), "\n";
}

// ── I. the e-mail logo at send time ─────────────────────────────────────

echo "I. The e-mail logo (inline image `logo`, owner 2026-10-23)\n";
require_once __DIR__ . '/support/TestPng.php';
$em = $wireDi();
$logoOf = function (string $path, ?int $document = null) use ($db, $wireDi): ?\Z77\Shared\Mail\InlineImage {
    $db->executeStatement('UPDATE mandator SET email_logo_path = ?, email_logo_document_id = ? WHERE id = 1', [$path, $document]);
    $wireDi();
    return (new \Z77\Module\Mandator\Mail\EmailLogo())->image();
};
TestPng::write($base . '/assets/mail/logo.png', 100, 40);
$mandatorName = (new CurrentMandator($em))->find()?->getName();
// Since 2026-11-20 (owner «ja los») only the logo window's DMS document counts — the mail mechanics
// with a real image are module-mailing's section O (a test source); here: what EmailLogo answers.
check('I1 the old «Logo (E-Mail)» path is no longer read: a PNG there, no DMS document → no logo (null)',
    $mandatorName !== null && $mandatorName !== '' && $logoOf('assets/mail/logo.png') === null);
check('I2 nothing stored, or a document id the DMS does not answer (999999): no logo (null) — the mail goes out without one, nothing thrown',
    $logoOf('') === null && $logoOf('', 999999) === null);
$logoOf('');
$sent = new class implements \Z77\Shared\Mail\MailTransport {
    public string $data = '';
    public function send(string $sender, array $recipients, string $data): void { $this->data = $data; }
};
$mailer = new \Z77\Shared\Mail\Mailer($sent, 'no-reply@example.ch');
$mailer->send((new \Z77\Shared\Mail\Message())->to('max.muster@example.ch')->subject('Logo')->text('Guten Tag')
    ->html('<p data-z77-inline="logo" style="margin:0 0 24px;"></p><p>Guten Tag</p>'));
check('I4 … and without a logo the slot is removed: no related part, no image, the mail is the text alone',
    !str_contains($sent->data, 'multipart/related') && !str_contains($sent->data, 'image/png') && !str_contains(quoted_printable_decode($sent->data), 'data-z77-inline')
    && str_contains(quoted_printable_decode($sent->data), '<p>Guten Tag</p>'));

// ── H. source guards ─────────────────────────────────────────────────────

echo "H. Source guards\n";
$sources = array_merge(glob($package . '/src/*/*.php'), glob($package . '/src/*/*/*.php'));
check('H1 no module-mandator class `use`s a financial OR a vat class — the check names financial as a STRING (financial is only suggested), and module-vat is no dependency at all since the default tax code went',
    array_filter($sources, fn($f) => preg_match('/^use Z77\\\\Module\\\\(Financial|Vat)\\\\/m', file_get_contents($f))) === []
    && !str_contains(file_get_contents($package . '/composer.json'), 'module-vat')
    && str_contains(file_get_contents($package . '/src/Services/LedgerAccountCheck.php'), "'Z77\\\\Module\\\\Financial\\\\Services\\\\LedgerService'"));
check('H2 no float, no superglobals anywhere in the module (Rule 4)',
    array_reduce($sources, fn($ok, $f) => $ok && !preg_match('/\(float\)|\(double\)|floatval\(|number_format\(/', file_get_contents($f)) && !preg_match('/\$_(POST|GET|SERVER|REQUEST)\b/', file_get_contents($f)), true));
check('H3 the two pre-E2 config keys are gone from the package configs; the readers refuse a leftover',
    !str_contains(file_get_contents($packages['Financial'] . '/src/App/Config/financialConfig.inc.php'), "'vatAccounts' =>")
    && !str_contains(file_get_contents($packages['Debtor'] . '/src/App/Config/debtorConfig.inc.php'), "'debtorAccounts' =>")
    && str_contains(file_get_contents($packages['Financial'] . '/src/Services/LedgerService.php'), 'refuseLegacyVatAccounts')
    && str_contains(file_get_contents($packages['Debtor'] . '/src/Services/DebtorAccounts.php'), 'refuseLegacyConfig'));
$mandatorFiles    = array_filter(array_merge(glob($packages['Financial'] . '/src/*/*.php'), glob($packages['Debtor'] . '/src/*/*.php')), fn($f) => str_contains(file_get_contents($f), 'CurrentMandator'));
$reachingMandator = array_map('basename', $mandatorFiles);
// Reading the RECORD is allowed anywhere (rule: through CurrentMandator, never
// the repository). Reading an ACCOUNT off it is not — that stays with the two
// access points, so a wrong number is refused in ONE place with ONE message.
$readingAccounts  = array_map('basename', array_filter($mandatorFiles, fn($f) => preg_match('/(?<!\$builder)->account\(/', file_get_contents($f)) === 1)); // GrossEntryBuilder::account() is not the mandator's
sort($reachingMandator);
sort($readingAccounts);
check('H4 only the two access points read an ACCOUNT off the record; another caller may read the RECORD itself — the report header prints the letterhead (2026-09-24), debtor\'s creditor block falls back to its address (P3 part 3)',
    $readingAccounts === ['DebtorAccounts.php', 'LedgerService.php']
    && $reachingMandator === ['BankPostingService.php', 'Creditor.php', 'DebtorAccounts.php', 'InvoicingService.php', 'LedgerService.php', 'PostingRules.php', 'ReportControllerTrait.php', 'VatReturnControllerTrait.php', 'VatReturnService.php']);   // InvoicingService: liableToVat() (2026-11-20); VatReturnService: vatSettings() (2026-11-24); VatReturnControllerTrait: the PDF's facts (2026-11-24, S1b); PostingRules: vatSettings() for «Abrechnung ab» (2026-11-25, S2 slice 4a)
check('H5 the mandator carries NO bank fields and NO currency — the payment target is the payee, systemConfig the currency (owner, 2026-09-23)',
    !method_exists(Mandator::class, 'getIban') && !method_exists(Mandator::class, 'getBankName') && !method_exists(Mandator::class, 'getCurrency')
    && !str_contains(strtolower(file_get_contents($package . '/src/Entities/Mandator.php')), 'private string $iban'));
check('H5b accountField() goes through Naming (Rule 5), and the abstract MandatorException nobody caught is gone',
    str_contains(file_get_contents($package . '/src/Entities/Mandator.php'), 'Naming::toSnakeCase') && !is_file($package . '/src/Services/MandatorException.php')
    && Mandator::accountField('dunning-fee') === 'account_dunning_fee' && Mandator::accountField('vat-input-other') === 'account_vat_input_other');
check('H6 nothing of wdv\'s numbering or fiscal-year state came along (NumberRange / FiscalYear are the truth)',
    !method_exists(Mandator::class, 'getLastInvoiceNo') && !method_exists(Mandator::class, 'getInvoiceNoPrefix') && !method_exists(Mandator::class, 'getCurrentFy') && !method_exists(Mandator::class, 'getFirstDayOfFy'));

// ── result ───────────────────────────────────────────────────────────────

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
