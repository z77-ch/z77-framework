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
check('A4 the mandator migration ran — the newest of all modules, last in timestamp order',
    str_contains($out, 'Migrating up to Z77\\Module\\Mandator\\Migrations\\Version20260923160948') && in_array('Z77\\Module\\Mandator\\Migrations\\Version20260923160948', $executed, true));
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
check('A8c the eight account columns are the eight keys of Mandator::ACCOUNT_KEYS, VARCHAR(10)',
    $accountColumns === array_map(fn($k) => Mandator::accountField($k), array_keys(Mandator::ACCOUNT_KEYS)) && count($accountColumns) === 8);
[$code, $out] = $run(['command' => 'migrate']);
check('A9 a second migrate is a no-op', $code === 0 && str_contains($out, 'Already at the latest version'));
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Mandator\\Migrations']);
check('A10 diff after migrate reports NO change — mapping and migration agree', $code !== 0 && str_contains($out, 'No changes detected') && count(glob($package . '/res/migrations/Version*.php')) === 1);
check('A11 the migration is expand-only: no DROP outside down()', (function () use ($package): bool {
    $s = file_get_contents(glob($package . '/res/migrations/Version*.php')[0]);
    return substr_count(substr($s, 0, strpos($s, 'function down')), 'DROP') === 0;
})());

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
check('C6 website without spaces; logo path relative, without «..»', $field(['name' => 'x', 'website' => 'www.firma.ch'], 'website') === '' && $field(['name' => 'x', 'website' => 'www firma'], 'website') !== ''
    && $field(['name' => 'x', 'logo_path' => 'assets/logo.png'], 'logo_path') === '' && $field(['name' => 'x', 'logo_path' => '../etc/passwd'], 'logo_path') !== ''
    && $field(['name' => 'x', 'logo_path' => '/abs/logo.png'], 'logo_path') !== '' && $field(['name' => 'x', 'logo_path' => 'C:\\logo.png'], 'logo_path') !== ''
    && (new Mandator(['logo_path' => 'assets\\img\\logo.png']))->getLogoPath() === 'assets/img/logo.png');
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
check('C10 liableToVat is a flag with no other effect yet; there is NO default tax code and no typed account getter (owner, review 2026-09-23 — no reader)', !(new Mandator())->isLiableToVat() && (new Mandator(['liable_to_vat' => true]))->isLiableToVat()
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
check('E1 the start values are the KMU numbers — rounding its OWN account 3809, dunning fee on 6950 (owner, 2026-09-22)', MandatorAccounts::DEFAULTS === [
    'receivable' => '1100', 'discount' => '3800', 'loss' => '3805', 'rounding' => '3809', 'dunning-fee' => '6950', 'vat-input-material' => '1170', 'vat-input-other' => '1171', 'vat-owed' => '2200',
] && array_keys(MandatorAccounts::DEFAULTS) === array_keys(Mandator::ACCOUNT_KEYS) && array_keys(MandatorAccounts::LABELS) === array_keys(Mandator::ACCOUNT_KEYS));
$kmu = array_column(json_decode(file_get_contents($packages['Financial'] . '/res/charts/kmu.json'), true), null, 'number');
check('E2 … every one of them is a postable account of the shipped KMU chart (verified against the FILE)',
    array_reduce(MandatorAccounts::DEFAULTS, fn($ok, $n) => $ok && ($kmu[$n]['postable'] ?? false) === true, true)
    && str_contains($kmu['1170']['name'], 'Vorsteuer') && str_contains($kmu['2200']['name'], 'Geschuldete MWST') && $kmu['3809']['name'] === 'Rundungsdifferenzen');
check('E3 prefilled() carries exactly the defaults and nothing else', array_map(fn($k) => MandatorAccounts::prefilled()->account($k), array_keys(Mandator::ACCOUNT_KEYS)) === array_values(MandatorAccounts::DEFAULTS)
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
$em = $wireDi();
(new MandatorService($em))->update((new CurrentMandator($em))->find(), ['account_dunning_fee' => '']);
$em = $wireDi();
check('E8 an EMPTY account field saves — «not set» is refused at the point of use, not here (an installation that never duns)', (new CurrentMandator($em))->find()->account('dunning-fee') === '');
$status = (new MandatorAccounts($em))->status((new CurrentMandator($em))->find());
check('E9 status(): the empty key is named, the seven others are ok', $status['dunning-fee']['error'] !== null && str_contains($status['dunning-fee']['error'], 'Mahngebühr')
    && array_reduce(array_diff_key($status, ['dunning-fee' => 1]), fn($ok, $r) => $ok && $r['error'] === null && $r['number'] !== '', true));
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

echo "E. … without a registered module-financial (a letterhead-only installation)\n";
$writeModules(false);
$rm($base . '/var/cache');
$em = $wireDi();
$check = new LedgerAccountCheck($em);
check('E12 the check cannot tell (null), the class still autoloads, the datalist is empty — no fatal', !$check->available() && $check->isPostable('1020') === null && $check->isPostable('9999999') === null
    && class_exists(LedgerAccountCheck::LEDGER_SERVICE) && $check->postableAccounts() === []);
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
check('F2 DebtorAccounts::number(): the five keys onto the record\'s first five accounts', array_map(fn($k) => $debtor->number($k), DebtorAccounts::KEYS) === ['1100', '3800', '3805', '3809', '6950']
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
        public ?string $redirectedTo = null;
        public function __construct()
        {
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
check('G5b the rule of thumb stands on the page: the mandator is the letterhead, the payment target is the payee', str_contains($html, 'Zahlungsziel') && str_contains($html, 'kein Fehler'));

// POST: the first save creates the record.
$postBody = ['csrf_token' => 'csrf-x', 'name' => 'Screen AG', 'street' => 'Weg', 'house_no' => '1', 'zip' => '3000', 'city' => 'Bern', 'country' => 'ch', 'email' => 'Mail@Screen.ch', 'uid' => 'che 109322551 MWST', 'liable_to_vat' => '1']
    + array_combine(array_map(fn($k) => Mandator::accountField($k), array_keys(MandatorAccounts::DEFAULTS)), array_values(MandatorAccounts::DEFAULTS));
$useRequest([], $postBody);
$h = $host();
$h->editAction();
$em = $wireDi();
check('G6 POST creates the one record, redirects back (303) with a success flash; the values normalised', $h->redirectedTo === '/backend/finance/mandator/edit' && ($h->messageService->flashes[0][0] ?? '') === 'success'
    && $count('mandator') === 1 && ($m = (new CurrentMandator($em))->find())->getName() === 'Screen AG' && $m->getCountry() === 'CH' && $m->getUid() === 'CHE-109.322.551' && $m->isLiableToVat() && $m->getEmail() === 'mail@screen.ch');
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
    && str_contains($html, '>Speichern<') && str_contains($html, 'badge--success') && !str_contains($html, 'default_tax_code') && $h->context['unavailable'] === null);
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
$reachingMandator = array_map('basename', array_filter(array_merge(glob($packages['Financial'] . '/src/*/*.php'), glob($packages['Debtor'] . '/src/*/*.php')), fn($f) => str_contains(file_get_contents($f), 'CurrentMandator')));
sort($reachingMandator);
check('H4 no caller asks the record for an account directly: in financial and debtor only the two access points reach CurrentMandator', $reachingMandator === ['DebtorAccounts.php', 'LedgerService.php']);
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
