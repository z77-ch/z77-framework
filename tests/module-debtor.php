<?php

/**
 * module-debtor harness (CLI) — P3 part 1: the receivables MASTER DATA
 * (plan §6.1) against a REAL MariaDB (ADR-039 decision 16), throwaway
 * schema per run, created by the modules' own MIGRATIONS through the
 * `z77-db` application (never `SchemaTool`): the harness proves the
 * migration as much as the module.
 *
 * What is load-bearing here:
 *
 *   - (A) `migrate` on an empty database creates `debtor_profile` in
 *     `utf8mb4_unicode_ci` / InnoDB next to contact's and financial's
 *     tables, a second `migrate` is a no-op and `diff` reports NO change
 *     afterwards — the mapping and the migration agree; the profile's only
 *     foreign key goes to `contact`, none to the file-based master data;
 *   - (B) the seeds of the two seeded file entities — «30 Tage netto» and
 *     «10 Tage 2 %, 30 Tage netto», three dunning levels — pass their
 *     validators, resolve by code and keep their umlauts (UTF-8 without
 *     BOM, DATA-JSON-001); payment targets are deliberately NOT seeded;
 *   - (C) payment terms: due days, the discount tiers (percent as an
 *     integer in hundredths, ascending days, falling percent, at most two)
 *     and the per-language document text with its default-language rule;
 *   - (D) the IBAN: MOD-97-10 check digits on REAL numbers, a transposed
 *     pair refused, the QR-IBAN IID range 30000–31999 at its edges, CH / LI
 *     only, and the payment target's ledger account checked softly against
 *     financial — refused when it is a group or inactive, unverified when
 *     module-financial is absent;
 *   - (E) dunning levels: unique level, the fee as `Money` from stored
 *     minor units, no VAT field anywhere in the entity (plan §6.5);
 *   - (F) the debtor profile: CRUD through the service, ONE profile per
 *     contact (validator and unique index), validate-before-mutate on an
 *     update, deactivate — never delete;
 *   - (G) the reference rule for EVERY file master-data type (ADR-043
 *     decision 19): a NEW reference needs an active row, an existing one
 *     keeps a deactivated row, the code is immutable, and no delete method
 *     exists on the write side or the screens;
 *   - (H) the `debtorAccounts` config accessor: the KMU defaults, a missing
 *     key, a malformed value and an account that is a group / inactive /
 *     unknown — each refused with a German message naming the key;
 *   - (I) the four backend fragments render through their traits with their
 *     own header slots, and carry no JavaScript of their own (Rule 7).
 *
 * Run: php tests/module-debtor.php
 * Needs what tests/module-contact.php needs (vendor/ with Doctrine, a
 * reachable MariaDB, credentials in `%USERPROFILE%\.z77\mariadb.txt` or
 * Z77_TEST_DB_*). Nothing is written into the repository; the schema
 * `z77test_<random>` and the temp installation are removed at the end.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "module-debtor: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
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
use Z77\Module\Contact\Entities\Address;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Entities\ContactAddress;
use Z77\Module\Contact\Services\ContactService;
use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Entities\DunningLevel;
use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Module\Debtor\Entities\PaymentTerms;
use Z77\Module\Debtor\Repositories\DebtorProfileRepository;
use Z77\Module\Debtor\Repositories\DunningLevelRepository;
use Z77\Module\Debtor\Repositories\PaymentTargetRepository;
use Z77\Module\Debtor\Repositories\PaymentTermsRepository;
use Z77\Module\Debtor\Services\AccountNotConfiguredException;
use Z77\Module\Debtor\Services\DebtorAccounts;
use Z77\Module\Debtor\Services\DebtorCurrency;
use Z77\Module\Debtor\Services\DebtorMasterData;
use Z77\Module\Debtor\Services\DebtorProfileService;
use Z77\Module\Debtor\Services\Iban;
use Z77\Module\Debtor\Services\InvalidDebtorProfileException;
use Z77\Module\Debtor\Services\InvalidMasterDataException;
use Z77\Module\Debtor\Services\LedgerAccountCheck;
use Z77\Module\Debtor\Services\MasterDataCodeChangedException;
use Z77\Module\Debtor\Ui\DebtorControllerTrait;
use Z77\Module\Debtor\Ui\DebtorLayout;
use Z77\Module\Debtor\Ui\DocumentTextForm;
use Z77\Module\Debtor\Ui\DunningLevelControllerTrait;
use Z77\Module\Debtor\Ui\DunningLevelLayout;
use Z77\Module\Debtor\Ui\PaymentTargetControllerTrait;
use Z77\Module\Debtor\Ui\PaymentTargetLayout;
use Z77\Module\Debtor\Ui\PaymentTermsControllerTrait;
use Z77\Module\Debtor\Ui\PaymentTermsLayout;
use Z77\Module\Debtor\Validators\DebtorProfileValidator;
use Z77\Module\Debtor\Validators\DunningLevelValidator;
use Z77\Module\Debtor\Validators\PaymentTargetValidator;
use Z77\Module\Debtor\Validators\PaymentTermsValidator;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Services\AccountService;
use Z77\Persistence\Doctrine\Bootstrap as DoctrineBootstrap;
use Z77\Persistence\Doctrine\Console\MigrationDirectories;
use Z77\Persistence\Doctrine\Console\MigrationsApplication;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

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
    fwrite(STDERR, "module-debtor: no database password — %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD.\n");
    exit(2);
}

// ── throwaway schema (deliberately NOT in our collation) and installation ─

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-module-debtor-' . getmypid();
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

// The REAL packages are the modules' source paths: their configs announce
// the entities, their res/migrations hold the migrations, their data/ the
// seeds. debtor needs contact (the party) and, for the SOFT account check,
// financial — which a project may leave out, and section H proves that too.
$packages = [
    'Contact'   => str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-contact')),
    'Financial' => str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-financial')),
    'Debtor'    => str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-debtor')),
];
$package      = $packages['Debtor'];
$overrideRoot = $base . '/override/module/debtor';
$namespaces   = '';
foreach ($packages as $name => $path) {
    // The Debtor namespace looks in an override root FIRST — the project layout
    // a `debtorConfig.inc.php` override lands in (BOOT-CONFIG-001, section H).
    $sources     = $name === 'Debtor' ? "'{$overrideRoot}', '{$path}'" : "'{$path}'";
    $namespaces .= "'Z77\\\\Module\\\\{$name}\\\\' => ['sourcePaths' => [{$sources}]],\n";
}
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$writeModules = function (bool $withFinancial) use ($write): void {
    $modules = $withFinancial ? "'contact' => [], 'financial' => [], 'debtor' => []" : "'contact' => [], 'debtor' => []";
    $write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'debtor', 'modules' => [{$modules}]];");
};
$writeModules(true);
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de', 'fr']];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
// Seed the way the installer does: `*.default.json` → `data/…` with the marker stripped.
@mkdir($base . '/data/framework/contact', 0777, true);
copy($packages['Contact'] . '/data/framework/contact/address_types.default.json', $base . '/data/framework/contact/address_types.json');
@mkdir($base . '/data/framework/debtor', 0777, true);
foreach (glob($package . '/data/framework/debtor/*.default.json') as $seed) {
    copy($seed, $base . '/data/framework/debtor/' . str_replace('.default.json', '.json', basename($seed)));
}

/**
 * The DI wiring Bootstrap::__construct() + pullUpServices() do, reduced to
 * what the drivers, the CLI and the master-data rules read (`I18n` — the
 * document texts are validated against the installation's languages).
 * Calling it again is the «fresh request»: a new UnifiedEntityManager, a
 * new Doctrine EntityManager, an empty Identity Map.
 */
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
$config = DI::getModuleManager()->getModuleConfig('debtor');
check('A0 the module config announces exactly the ONE Doctrine entity — the other three are file-based', $config?->get('doctrineEntities') === [DebtorProfile::class]);
$dirs = MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder());
check('A1 the module\'s res/migrations is collected under Z77\\Module\\Debtor\\Migrations', ($dirs['Z77\\Module\\Debtor\\Migrations'] ?? '') === $package . '/res/migrations');
check('A2 the database is empty', $tables() === []);
[$code, $out] = $run(['command' => 'migrate']);
check('A3 migrate exits 0' . ($code !== 0 ? " — got {$code}: " . trim($out) : ''), $code === 0);
check('A4 … the debtor migration ran (timestamp order across the modules)', str_contains($out, 'Z77\\Module\\Debtor\\Migrations\\Version20260922173918'));
check('A5 debtor_profile exists next to contact\'s and financial\'s tables', in_array('debtor_profile', $tables(), true));
$info = $tableInfo('debtor_profile');
check('A6 debtor_profile is utf8mb4_unicode_ci and InnoDB although the database default is general_ci',
    ($info['TABLE_COLLATION'] ?? '') === 'utf8mb4_unicode_ci' && ($info['ENGINE'] ?? '') === 'InnoDB');
$columns = $db->fetchAllKeyValue('SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL', [$dbName, 'debtor_profile']);
check('A7 … and so is every string column of it', $columns !== [] && count(array_unique($columns)) === 1 && reset($columns) === 'utf8mb4_unicode_ci');
$fks = $db->fetchFirstColumn('SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY 1', [$dbName, 'debtor_profile']);
check('A8 debtor_profile\'s only foreign key goes to contact — none to the file-based master data (ADR-043/19)', $fks === ['contact']);
$indexes = $db->fetchFirstColumn('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY 1', [$dbName, 'debtor_profile']);
check('A9 the unique contact index and the payment-terms index carry our names', in_array('uniq_debtor_profile_contact', $indexes, true) && in_array('idx_debtor_profile_terms', $indexes, true));
[$code, $out] = $run(['command' => 'migrate']);
check('A10 a second migrate is a no-op', $code === 0 && str_contains($out, 'Already at the latest version'));
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Debtor\\Migrations']);
check('A11 diff after migrate reports NO change — mapping and migration agree', $code !== 0 && str_contains($out, 'No changes detected') && count(glob($package . '/res/migrations/Version*.php')) === 1);
$migrationSource = file_get_contents(glob($package . '/res/migrations/Version*.php')[0]);
check('A12 the migration is expand-only: no DROP outside down()', substr_count(substr($migrationSource, 0, strpos($migrationSource, 'function down')), 'DROP') === 0);


// ── B. the seeds ─────────────────────────────────────────────────────────

echo "B. Seeded file master data (payment terms, dunning levels)\n";
$em    = $wireDi();
$terms = $em->getRepository(PaymentTerms::class);
$levels = $em->getRepository(DunningLevel::class);
$targets = $em->getRepository(PaymentTarget::class);
check('B1 convention repositories resolve on the File driver', $terms instanceof PaymentTermsRepository && $levels instanceof DunningLevelRepository && $targets instanceof PaymentTargetRepository);
check('B2 two seeded terms rows, in file order: net-30, disc-2-10', array_map(fn(PaymentTerms $t) => $t->getCode(), $terms->allInOrder()) === ['net-30', 'disc-2-10']);
check('B3 every seeded terms row is active and passes its validator', array_reduce($terms->allInOrder(), fn($ok, PaymentTerms $t) => $ok && $t->isActive() && (new PaymentTermsValidator($t, $terms))->isValid(), true));
$net30 = $terms->findByCode('net-30');
$disc  = $terms->findByCode('disc-2-10');
check('B4 net-30: 30 days, no discount tier', $net30?->getDueDays() === 30 && $net30->getDiscounts() === []);
check('B4b the seeded German labels keep their umlauts (UTF-8 without BOM, DATA-JSON-001)',
    $levels->findByCode('reminder')?->getLabel() === 'Zahlungserinnerung' && $terms->findByCode('disc-2-10')?->getLabel() === '10 Tage 2 %, 30 Tage netto');
check('B5 disc-2-10: 30 days with one tier «10 Tage 2 %» — percent in hundredths', $disc?->getDueDays() === 30 && $disc->getDiscounts() === [['days' => 10, 'percent' => 200]]);
check('B6 … formatted back it reads 2.00', PaymentTerms::formatPercent($disc->getDiscounts()[0]['percent']) === '2.00');
check('B7 the seeds ship WITHOUT a document text — an installation in any language starts with valid rows (owner, 2026-09-22)',
    $disc->getDocumentText() === [] && $net30->getDocumentText() === []
    && !str_contains(file_get_contents($package . '/data/framework/debtor/payment_terms.default.json'), 'document_text'));
check('B8 three seeded dunning levels, ascending by level: reminder, dunning-1, dunning-2',
    array_map(fn(DunningLevel $l) => $l->getCode(), $levels->allInOrder()) === ['reminder', 'dunning-1', 'dunning-2']);
check('B9 every seeded level is active and passes its validator', array_reduce($levels->allInOrder(), fn($ok, DunningLevel $l) => $ok && $l->isActive() && (new DunningLevelValidator($l, $levels))->isValid(), true));
check('B10 the ladder is 1/2/3 with growing distance from the due date', array_map(fn(DunningLevel $l) => [$l->getLevel(), $l->getDaysAfterDue()], $levels->allInOrder()) === [[1, 10], [2, 30], [3, 50]]);
check('B11 the reminder is free, the two Mahnungen cost 20.00 and 40.00 (minor units in the file)',
    array_map(fn(DunningLevel $l) => $l->fee('CHF')->toDecimal(), $levels->allInOrder()) === ['0.00', '20.00', '40.00']);
check('B12 the dunning seeds carry no text either — the office writes its own wording',
    array_reduce($levels->allInOrder(), fn($ok, DunningLevel $l) => $ok && $l->getDocumentText() === [], true)
    && !str_contains(file_get_contents($package . '/data/framework/debtor/dunning_levels.default.json'), 'document_text'));
check('B13 payment targets are NOT seeded — an IBAN cannot be guessed', $targets->allInOrder() === [] && !is_file($base . '/data/framework/debtor/payment_targets.json'));
check('B14 no seed file carries a BOM', array_reduce(glob($package . '/data/framework/debtor/*.json'), fn($ok, $f) => $ok && !str_starts_with(file_get_contents($f), "\xEF\xBB\xBF"), true));
check('B15 there is no delete on the file master data — deactivate only (ADR-043/19)',
    !method_exists(DebtorMasterData::class, 'removeTerms') && !method_exists(DebtorMasterData::class, 'removeTarget') && !method_exists(DebtorMasterData::class, 'removeLevel')
    && !method_exists(DebtorMasterData::class, 'delete'));


// ── C. payment terms: rules of the row ───────────────────────────────────

echo "C. Payment terms (tiers, texts, code)\n";
$masterData = new DebtorMasterData($em);
$invalid = fn(callable $fn): ?PaymentTermsValidator => ($e = caught($fn, InvalidMasterDataException::class)) instanceof InvalidMasterDataException ? $e->validator : null;

$v = $invalid(fn() => $masterData->saveTerms(new PaymentTerms(['code' => 'NET-30', 'label' => 'Doppelt', 'due_days' => 30])));
check('C1 a duplicate code is refused (the setter lower-cases, so NET-30 is net-30)', $v?->hasFieldError('code') === true);
$v = $invalid(fn() => $masterData->saveTerms(new PaymentTerms(['code' => '1st', 'label' => 'x', 'due_days' => 10])));
check('C2 a code starting with a digit is refused', $v?->hasFieldError('code') === true);
$v = $invalid(fn() => $masterData->saveTerms(new PaymentTerms(['code' => 'ok-code', 'label' => '', 'due_days' => 10])));
check('C3 an empty label is refused', $v?->hasFieldError('label') === true);
$v = $invalid(fn() => $masterData->saveTerms(new PaymentTerms(['code' => 'ok-code', 'label' => 'x', 'due_days' => 400])));
check('C4 a due-day count beyond a year is refused', $v?->hasFieldError('due_days') === true);

$threeTiers = new PaymentTerms(['code' => 'three', 'label' => 'Drei Stufen', 'due_days' => 30,
    'discounts' => [['days' => 5, 'percent' => 300], ['days' => 10, 'percent' => 200], ['days' => 20, 'percent' => 100]]]);
$v = $invalid(fn() => $masterData->saveTerms($threeTiers));
check('C5 a third discount tier is refused (MAX_TIERS = 2)', $v?->hasFieldError('discounts') === true);

$late = new PaymentTerms(['code' => 'late', 'label' => 'Skonto nach Verfall', 'due_days' => 30, 'discounts' => [['days' => 40, 'percent' => 200]]]);
$v = $invalid(fn() => $masterData->saveTerms($late));
check('C6 a tier after the due date is refused — it could never be taken', $v?->hasFieldError('discounts') === true);
$instant = new PaymentTerms(['code' => 'instant', 'label' => 'Zahlbar sofort mit Skonto', 'due_days' => 0, 'discounts' => [['days' => 10, 'percent' => 200]]]);
$v = $invalid(fn() => $masterData->saveTerms($instant));
check('C6b «zahlbar sofort» (0 Tage) with a tier is refused too — every tier lies after a due date of 0 (review 2026-09-22)', $v?->hasFieldError('discounts') === true);

$rising = new PaymentTerms(['code' => 'rising', 'label' => 'Steigend', 'due_days' => 30, 'discounts' => [['days' => 10, 'percent' => 100], ['days' => 20, 'percent' => 200]]]);
$v = $invalid(fn() => $masterData->saveTerms($rising));
check('C7 a LATER tier giving MORE percent is refused — the earlier one would never be chosen', $v?->hasFieldError('discounts') === true);

$empty = new PaymentTerms(['code' => 'empty-row', 'label' => 'Leere Zeile', 'due_days' => 30, 'discounts' => [['days' => 0, 'percent' => 0], ['days' => 10, 'percent' => 200]]]);
check('C8a an EMPTY tier row (no days, no percent) is dropped by the setter — a blank form row is no tier', $empty->getDiscounts() === [['days' => 10, 'percent' => 200]]);
$zero = new PaymentTerms(['code' => 'zero-pc', 'label' => 'Null Prozent', 'due_days' => 30, 'discounts' => [['days' => 10, 'percent' => 0]]]);
$v = $invalid(fn() => $masterData->saveTerms($zero));
check('C8b a tier with days but 0 % is REFUSED, not silently dropped', $v?->hasFieldError('discounts') === true);

$twoTiers = new PaymentTerms(['code' => 'two', 'label' => '10 Tage 3 %, 20 Tage 2 %, 30 Tage netto', 'due_days' => 30,
    'discounts' => [['days' => 20, 'percent' => 200], ['days' => 10, 'percent' => 300]],
    'document_text' => ['de' => 'Skonto gestaffelt.', 'fr' => 'Escompte échelonné.']]);
$masterData->saveTerms($twoTiers);
check('C9 two tiers save and come back ASCENDING by days, whatever order they were given in',
    $twoTiers->getId() !== null && $twoTiers->getDiscounts() === [['days' => 10, 'percent' => 300], ['days' => 20, 'percent' => 200]]);
$em2 = $wireDi();
$reread = $em2->getRepository(PaymentTerms::class)->findByCode('two');
check('C10 … and survive a fresh read of the collection file, texts and all',
    $reread?->getDiscounts() === [['days' => 10, 'percent' => 300], ['days' => 20, 'percent' => 200]]
    && $reread->getDocumentText() === ['de' => 'Skonto gestaffelt.', 'fr' => 'Escompte échelonné.']);
check('C11 percentToHundredths: 2 → 200, 2.5 → 250, 0.25 → 25', PaymentTerms::percentToHundredths('2') === 200 && PaymentTerms::percentToHundredths('2.5') === 250 && PaymentTerms::percentToHundredths('0.25') === 25);
check('C12 percentToHundredths refuses a third decimal', throws(fn() => PaymentTerms::percentToHundredths('2.555'), \InvalidArgumentException::class));

$em3        = $wireDi();
$masterData = new DebtorMasterData($em3);
$invalid    = fn(callable $fn): ?PaymentTermsValidator => ($e = caught($fn, InvalidMasterDataException::class)) instanceof InvalidMasterDataException ? $e->validator : null;
$frOnly = new PaymentTerms(['code' => 'fr-only', 'label' => 'Nur Französisch', 'due_days' => 30, 'document_text' => ['fr' => 'Payable à 30 jours.']]);
$v = $invalid(fn() => $masterData->saveTerms($frOnly));
check('C13 a text only in fr is refused — the default language is the fallback every other one needs', $v?->hasFieldError('document_text') === true);
$itText = new PaymentTerms(['code' => 'it-text', 'label' => 'Italienisch', 'due_days' => 30, 'document_text' => ['de' => 'Deutsch.', 'it' => 'Italiano.']]);
$v = $invalid(fn() => $masterData->saveTerms($itText));
check('C14 a text in a language the installation does not serve is refused (i18n.md whitelist)', $v?->hasFieldError('document_text') === true);
$noText = new PaymentTerms(['code' => 'no-text', 'label' => 'Ohne Belegtext', 'due_days' => 30]);
$masterData->saveTerms($noText);
check('C15 no text at all stays allowed — not every row needs a sentence on the document', $noText->getId() !== null);
check('C16 the entity drops an empty text instead of storing it', (new PaymentTerms(['document_text' => ['de' => '  ', 'fr' => 'x']]))->getDocumentText() === ['fr' => 'x']);

$stored = $em3->getRepository(PaymentTerms::class)->findByCode('two');
$stored->setCode('renamed');
$e = caught(fn() => $masterData->saveTerms($stored), MasterDataCodeChangedException::class);
check('C17 the code of an existing row is immutable — the write service refuses it', $e instanceof MasterDataCodeChangedException && $e->storedCode === 'two' && $e->submittedCode === 'renamed');


// ── D. IBAN, QR-IBAN and the payment target ──────────────────────────────

echo "D. IBAN / QR-IBAN and the payment target\n";
check('D1 a real CH IBAN passes the MOD-97-10 check digits', Iban::hasValidCheckDigits('CH9300762011623852957'));
check('D2 … spaced and lower-cased just the same (normalization)', Iban::hasValidCheckDigits('ch93 0076 2011 6238 5295 7'));
check('D3 a transposed pair of digits fails the check digits', !Iban::hasValidCheckDigits('CH9300762011623852975'));
check('D4 a wrong check-digit pair fails', !Iban::hasValidCheckDigits('CH9400762011623852957'));
check('D5 a German IBAN passes the check digits as well (the algorithm is not Swiss)', Iban::hasValidCheckDigits('DE89370400440532013000'));
check('D6 the official Liechtenstein example passes, and one wrong character does not',
    Iban::hasValidCheckDigits('LI21088100002324013AA') && Iban::isWellFormed('LI21088100002324013AA')
    && !Iban::hasValidCheckDigits('LI21088100002324013AB'));
check('D7 shape: a CH IBAN is exactly 21 characters', !Iban::isWellFormed('CH930076201162385295') && Iban::isWellFormed('CH9300762011623852957'));
check('D8 shape: letters where digits belong is refused', !Iban::isWellFormed('CHXX00762011623852957'));
check('D9 format() groups in fours', Iban::format('CH9300762011623852957') === 'CH93 0076 2011 6238 5295 7');

// The IID sits at positions 5–9. Built at the range edges, then given valid check digits.
$withIid = static function (string $iid): string {
    $bban  = $iid . '000000000000';                       // IID (5) + 12 → the 17-character Swiss BBAN
    $digits = '';
    foreach (str_split($bban . 'CH00') as $c) { $digits .= ctype_digit($c) ? $c : (string) (ord($c) - 55); }
    $remainder = 0;
    foreach (str_split($digits, 7) as $chunk) { $remainder = (int) (((string) $remainder . $chunk) % 97); }

    return 'CH' . str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT) . $bban;
};
$below = $withIid('29999');
$lower = $withIid('30000');
$upper = $withIid('31999');
$above = $withIid('32000');
check('D10 the built probes are valid IBANs', array_reduce([$below, $lower, $upper, $above], fn($ok, $i) => $ok && Iban::hasValidCheckDigits($i), true));
check('D11 IID 30000 and 31999 are QR-IBANs, 29999 and 32000 are not',
    Iban::isQrIban($lower) && Iban::isQrIban($upper) && !Iban::isQrIban($below) && !Iban::isQrIban($above));
check('D12 the IID is read from positions 5–9', Iban::iid($lower) === 30000 && Iban::iid($upper) === 31999);
check('D13 a non-Swiss IBAN has no IID and is never a QR-IBAN', Iban::iid('DE89370400440532013000') === null && !Iban::isQrIban('DE89370400440532013000'));
check('D14 the seeded CH example is a normal IBAN, not a QR one', !Iban::isQrIban('CH9300762011623852957'));

$em4        = $wireDi();
$masterData = new DebtorMasterData($em4);
$badTarget  = fn(callable $fn): ?PaymentTargetValidator => ($e = caught($fn, InvalidMasterDataException::class)) instanceof InvalidMasterDataException ? $e->validator : null;

// The chart a payment target's account is checked against (financial is installed here).
$accounts = new AccountService($em4);
$accounts->adoptKmuChart();
$em4 = $wireDi();
$masterData = new DebtorMasterData($em4);
$badTarget  = fn(callable $fn): ?PaymentTargetValidator => ($e = caught($fn, InvalidMasterDataException::class)) instanceof InvalidMasterDataException ? $e->validator : null;

$bank = new PaymentTarget(['code' => 'bank', 'label' => 'Bank', 'iban' => 'CH93 0076 2011 6238 5295 7', 'account_number' => '1020']);
$masterData->saveTarget($bank);
check('D15 a target saves; the IBAN is stored normalized', $bank->getId() !== null && $bank->getIban() === 'CH9300762011623852957');
check('D16 … and the collection file now exists', is_file($base . '/data/framework/debtor/payment_targets.json'));
$qr = new PaymentTarget(['code' => 'qr', 'label' => 'QR-Konto', 'iban' => $lower, 'account_number' => '1020']);
$masterData->saveTarget($qr);
check('D17 a QR-IBAN target says so on the entity', $qr->isQrIban() && !$bank->isQrIban());

$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'bad', 'label' => 'x', 'iban' => 'CH9300762011623852975', 'account_number' => '1020'])));
check('D18 a wrong check digit is refused with the check-digit message', $v?->hasFieldError('iban') === true && str_contains($v->getFieldError('iban'), 'Prüfziffern'));
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'de', 'label' => 'x', 'iban' => 'DE89370400440532013000', 'account_number' => '1020'])));
check('D19 a valid NON-Swiss IBAN is refused — a QR-bill names a CH / LI account', $v?->hasFieldError('iban') === true && str_contains($v->getFieldError('iban'), 'liechtensteinische'));
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'dup', 'label' => 'x', 'iban' => 'CH9300762011623852957', 'account_number' => '1020'])));
check('D20 the same IBAN twice is refused — a payment would be ambiguous', $v?->hasFieldError('iban') === true);
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'letters', 'label' => 'x', 'iban' => 'CH9300762011623852957', 'account_number' => '10A0'])));
check('D21 a non-numeric ledger account is refused', $v?->hasFieldError('account_number') === true);
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'group', 'label' => 'x', 'iban' => $upper, 'account_number' => '100'])));
check('D22 a GROUP account (100) is refused — soft check against module-financial', $v?->hasFieldError('account_number') === true);
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'ghost', 'label' => 'x', 'iban' => $upper, 'account_number' => '9999999'])));
check('D23 an unknown account is refused', $v?->hasFieldError('account_number') === true);

$kasse = $em4->getRepository(Account::class)->findOneBy(['number' => '1000']);
(new AccountService($em4))->setActive($kasse, false);
$em4 = $wireDi();
$masterData = new DebtorMasterData($em4);
$badTarget  = fn(callable $fn): ?PaymentTargetValidator => ($e = caught($fn, InvalidMasterDataException::class)) instanceof InvalidMasterDataException ? $e->validator : null;
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'inactive', 'label' => 'x', 'iban' => $upper, 'account_number' => '1000'])));
check('D24 a DEACTIVATED account is refused as a new payment target', $v?->hasFieldError('account_number') === true);
(new AccountService($em4))->setActive($em4->getRepository(Account::class)->findOneBy(['number' => '1000']), true);

$em5     = $wireDi();
$present = new LedgerAccountCheck($em5);
check('D25 with financial REGISTERED the check answers true / false', $present->available() && $present->isPostable('1020') === true && $present->isPostable('100') === false);
check('D26 an empty number is «cannot tell» as well (the validator\'s notEmpty says it first)', $present->isPostable('  ') === null);
// Not a constructor seam: the class autoloads either way — what decides is
// whether `financial` is a REGISTERED module (review 2026-09-22).
$writeModules(false);
$rm($base . '/var/cache');
$emUnregistered = $wireDi();
check('D27 the class still autoloads with financial UNREGISTERED — so class_exists alone would have lied',
    class_exists(LedgerAccountCheck::LEDGER_SERVICE) && DI::getModuleManager()->getModuleConfig('financial') === null);
$absent = new LedgerAccountCheck($emUnregistered);
check('D27b … and the check then cannot tell — null, never false, never a fatal',
    !$absent->available() && $absent->isPostable('1020') === null && $absent->isPostable('9999999') === null);
$unregisteredTarget = new PaymentTarget(['code' => 'unreg', 'label' => 'Ohne Buchhaltung', 'iban' => $upper, 'account_number' => '9999999']);
(new DebtorMasterData($emUnregistered))->saveTarget($unregisteredTarget);
check('D27c … and an unverifiable account number SAVES instead of being refused (financial is only suggested)', $unregisteredTarget->getId() !== null);
$writeModules(true);
$rm($base . '/var/cache');
$em5 = $wireDi();

$stored = $em5->getRepository(PaymentTarget::class)->findByCode('bank');
$stored->setCode('renamed');
check('D28 a payment target\'s code is immutable too', throws(fn() => (new DebtorMasterData($em5))->saveTarget($stored), MasterDataCodeChangedException::class));

echo "D. … deactivating a row that has BECOME invalid (review 2026-09-22)\n";
$em5b = $wireDi();
(new AccountService($em5b))->setActive($em5b->getRepository(Account::class)->findOneBy(['number' => '1020']), false);
$em5b = $wireDi();
$brokenTarget = $em5b->getRepository(PaymentTarget::class)->findByCode('bank');
check('D29 the target is now invalid — its ledger account went inactive in the bookkeeping',
    (new PaymentTargetValidator($brokenTarget, $em5b->getRepository(PaymentTarget::class), new LedgerAccountCheck($em5b)))->isValid() === false);
(new DebtorMasterData($em5b))->setTargetActive($brokenTarget, false);
$em5c = $wireDi();
check('D30 … and it can still be DEACTIVATED: setActive is a pure state change, the validator runs on a form save only',
    $em5c->getRepository(PaymentTarget::class)->findByCode('bank')?->isActive() === false);
check('D31 … while SAVING it through the form is still refused',
    throws(fn() => (new DebtorMasterData($em5c))->saveTarget($em5c->getRepository(PaymentTarget::class)->findByCode('bank')), InvalidMasterDataException::class));
(new AccountService($em5c))->setActive($em5c->getRepository(Account::class)->findOneBy(['number' => '1020']), true);
$em5d = $wireDi();
(new DebtorMasterData($em5d))->setTargetActive($em5d->getRepository(PaymentTarget::class)->findByCode('bank'), true);
check('D32 … and reactivating works again once the account is back', $em5d->getRepository(PaymentTarget::class)->findByCode('bank')?->isActive() === true);


// ── E. dunning levels ────────────────────────────────────────────────────

echo "E. Dunning levels (ladder, fee as Money, no VAT)\n";
$em6        = $wireDi();
$masterData = new DebtorMasterData($em6);
$badLevel   = fn(callable $fn): ?DunningLevelValidator => ($e = caught($fn, InvalidMasterDataException::class)) instanceof InvalidMasterDataException ? $e->validator : null;

$v = $badLevel(fn() => $masterData->saveLevel(new DunningLevel(['code' => 'dupe', 'label' => 'Doppelte Stufe', 'level' => 2, 'days_after_due' => 40])));
check('E1 a second row on level 2 is refused — the ladder must have one order', $v?->hasFieldError('level') === true);
$v = $badLevel(fn() => $masterData->saveLevel(new DunningLevel(['code' => 'zero', 'label' => 'x', 'level' => 0, 'days_after_due' => 5])));
check('E2 level 0 is refused (the ladder starts at 1)', $v?->hasFieldError('level') === true);
$v = $badLevel(fn() => $masterData->saveLevel(new DunningLevel(['code' => 'neg-fee', 'label' => 'x', 'level' => 4, 'days_after_due' => 60, 'fee' => -100])));
check('E3 a negative fee is refused', $v?->hasFieldError('fee') === true);
$v = $badLevel(fn() => $masterData->saveLevel(new DunningLevel(['code' => 'huge-fee', 'label' => 'x', 'level' => 4, 'days_after_due' => 60, 'fee' => 500000])));
check('E4 a fee beyond 1000.00 is refused — a dunning fee is a fee, not an invoice', $v?->hasFieldError('fee') === true);

$fourth = new DunningLevel(['code' => 'betreibung', 'label' => 'Betreibungsandrohung', 'level' => 4, 'days_after_due' => 70,
    'fee' => Money::fromDecimal('60.00', 'CHF')->minor, 'document_text' => ['de' => 'Letzte Frist.', 'fr' => 'Dernier délai.']]);
$masterData->saveLevel($fourth);
$em7   = $wireDi();
$stored = $em7->getRepository(DunningLevel::class)->findByCode('betreibung');
check('E5 a fourth level saves and reads back with its fee as Money', $stored?->fee('CHF')->toDecimal() === '60.00' && $stored->getFee() === 6000);
check('E6 the ladder now reads 1,2,3,4 in level order', array_map(fn(DunningLevel $l) => $l->getLevel(), $em7->getRepository(DunningLevel::class)->allInOrder()) === [1, 2, 3, 4]);
check('E7 the fee is Money in the installation\'s base currency (systemConfig → baseCurrency)', DebtorCurrency::base() === 'CHF' && $stored->fee(DebtorCurrency::base())->equals(Money::of(6000, 'CHF')));
$levelProperties = array_map(fn(\ReflectionProperty $p) => $p->getName(), (new \ReflectionClass(DunningLevel::class))->getProperties());
check('E8 a dunning level carries NO tax code and no VAT field — a dunning fee is not a supply (plan §6.5)',
    !in_array('taxCode', $levelProperties, true) && !in_array('vat', $levelProperties, true) && !in_array('taxRate', $levelProperties, true));
check('E9 the fee is stored as INTEGER minor units in the JSON row, never as a decimal string',
    is_int(json_decode(file_get_contents($base . '/data/framework/debtor/dunning_levels.json'), true)[1]['fee']));
$stored->setCode('renamed');
check('E10 a dunning level\'s code is immutable too', throws(fn() => (new DebtorMasterData($em7))->saveLevel($stored), MasterDataCodeChangedException::class));


// ── F. the debtor profile ────────────────────────────────────────────────

echo "F. Debtor profile (one per contact, CRUD, deactivate)\n";
$em8     = $wireDi();
$contacts = new ContactService($em8);
$mueller = new Contact(['kind' => 'organisation', 'company' => 'Müller & Söhne AG', 'language' => 'de', 'email' => 'info@mueller.ch']);
$mueller->addAddress(new ContactAddress($mueller, 'invoice', new Address(['name' => 'Müller & Söhne AG', 'street' => 'Bahnhofstrasse', 'house_no' => '1', 'zip' => '8001', 'city' => 'Zürich'])));
$contacts->save($mueller);
$anna = new Contact(['kind' => 'person', 'first_name' => 'Anna', 'last_name' => 'Ébauche', 'language' => 'fr']);
$contacts->save($anna);
check('F1 two contacts exist and no profile does', $count('contact') === 2 && $count('debtor_profile') === 0);

$profiles = new DebtorProfileService($em8);
$profile  = new DebtorProfile(['payment_terms_code' => 'disc-2-10']);
$profile->setContact($mueller);
$profiles->save($profile);
check('F2 a profile saves for a contact', $profile->getId() !== null && $count('debtor_profile') === 1);
$em9  = $wireDi();
$read = $em9->getRepository(DebtorProfile::class)->findByContact($mueller->getId());
check('F3 it reads back through a fresh EntityManager, keyed by contact id', $read?->getContact()?->getId() === $mueller->getId() && $read->getPaymentTermsCode() === 'disc-2-10' && !$read->hasDunningBlock() && $read->isActive());
check('F4 the profile knows its contact, the contact knows no profile (plan §2)',
    $read->getContact()?->displayName() === 'Müller & Söhne AG' && !method_exists(Contact::class, 'getDebtorProfile') && !method_exists(Contact::class, 'setDebtorProfile'));
$contactProperties = array_map(fn(\ReflectionProperty $p) => $p->getName(), (new \ReflectionClass(Contact::class))->getProperties());
check('F5 … and `contact` carries no debtor column', !in_array('paymentTermsCode', $contactProperties, true) && !in_array('dunningBlock', $contactProperties, true));

$profileProperties = array_map(fn(\ReflectionProperty $p) => $p->getName(), (new \ReflectionClass(DebtorProfile::class))->getProperties());
check('F6 the profile carries NO language — Contact::$language is the one truth (Rule 2)', !in_array('language', $profileProperties, true) && in_array('language', $contactProperties, true));
check('F7 the profile carries NO currency — Q6, and §6.2 puts it on the document', !in_array('currency', $profileProperties, true));

$second = new DebtorProfile(['payment_terms_code' => 'net-30']);
$second->setContact($em9->getRepository(Contact::class)->find($mueller->getId()));
$e = caught(fn() => (new DebtorProfileService($em9))->save($second), InvalidDebtorProfileException::class);
check('F8 a SECOND profile for the same contact is refused by the validator', $e instanceof InvalidDebtorProfileException && $e->validator->hasFieldError('contact_id'));
check('F9 … and nothing was written', $count('debtor_profile') === 1);

$em10 = $wireDi();
$raced = $db->executeStatement('INSERT INTO debtor_profile (payment_terms_code, dunning_block, active, contact_id) VALUES (?, 0, 1, ?)', ['net-30', $anna->getId()]);
check('F10 a profile for another contact is fine (the unique index is per contact, not global)', $raced === 1 && $count('debtor_profile') === 2);
$e = caught(fn() => $db->executeStatement('INSERT INTO debtor_profile (payment_terms_code, dunning_block, active, contact_id) VALUES (?, 0, 1, ?)', ['net-30', $anna->getId()]),
    \Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
check('F11 the unique index decides under a race — a second row for one contact is impossible in the schema', $e !== null && $count('debtor_profile') === 2);

$em11    = $wireDi();
$profiles = new DebtorProfileService($em11);
$managed  = $em11->getRepository(DebtorProfile::class)->findByContact($mueller->getId());
$profiles->update($managed, ['payment_terms_code' => 'net-30', 'dunning_block' => true]);
$em12 = $wireDi();
$read = $em12->getRepository(DebtorProfile::class)->findByContact($mueller->getId());
check('F12 an update writes terms and the dunning block', $read?->getPaymentTermsCode() === 'net-30' && $read->hasDunningBlock());

$em13    = $wireDi();
$profiles = new DebtorProfileService($em13);
$managed  = $em13->getRepository(DebtorProfile::class)->findByContact($mueller->getId());
$e = caught(fn() => $profiles->update($managed, ['payment_terms_code' => 'ghost-terms']), InvalidDebtorProfileException::class);
check('F13 an unknown payment-terms code is refused', $e instanceof InvalidDebtorProfileException && $e->validator->hasFieldError('payment_terms_code'));
check('F14 … and the MANAGED entity was not touched — a refused change never reaches the next flush (ADR-039/9)', $managed->getPaymentTermsCode() === 'net-30');
$em13->flush();
$em14 = $wireDi();
check('F15 … proven after a flush and a fresh read', $em14->getRepository(DebtorProfile::class)->findByContact($mueller->getId())?->getPaymentTermsCode() === 'net-30');

$em15    = $wireDi();
$profiles = new DebtorProfileService($em15);
$managed  = $em15->getRepository(DebtorProfile::class)->findByContact($mueller->getId());
$profiles->setActive($managed, false);
$em16 = $wireDi();
check('F16 a profile is DEACTIVATED, not deleted', $em16->getRepository(DebtorProfile::class)->findByContact($mueller->getId())?->isActive() === false && $count('debtor_profile') === 2);
check('F17 there is no delete method on the write side or the repository',
    !method_exists(DebtorProfileService::class, 'delete') && !method_exists(DebtorProfileService::class, 'remove')
    && !method_exists(DebtorProfileRepository::class, 'delete'));
check('F18 save() refuses an existing profile, update() a new one',
    throws(fn() => (new DebtorProfileService($em16))->save($em16->getRepository(DebtorProfile::class)->findByContact($mueller->getId())), \LogicException::class)
    && throws(fn() => (new DebtorProfileService($em16))->update(new DebtorProfile(), ['active' => true]), \LogicException::class));
$e = caught(fn() => (new DebtorProfileService($em16))->save(new DebtorProfile(['payment_terms_code' => 'net-30'])), InvalidDebtorProfileException::class);
check('F19 a profile without a contact is refused', $e instanceof InvalidDebtorProfileException && $e->validator->hasFieldError('contact_id'));
$noTerms = new DebtorProfile();
$noTerms->setContact($em16->getRepository(Contact::class)->find($mueller->getId()));
$e = caught(fn() => (new DebtorProfileService($em16))->save($noTerms), InvalidDebtorProfileException::class);
check('F20 a profile without payment terms is refused — there is no global default to fall back on', $e instanceof InvalidDebtorProfileException && $e->validator->hasFieldError('payment_terms_code'));

echo "F. … the reference rule applied to the PARTY (review 2026-09-22)\n";
$em16b   = $wireDi();
$retired = new Contact(['kind' => 'person', 'first_name' => 'Ruhe', 'last_name' => 'Stand', 'language' => 'de']);
(new ContactService($em16b))->save($retired);
(new ContactService($em16b))->setActive($retired, false);
$em16c   = $wireDi();
$onDead  = new DebtorProfile(['payment_terms_code' => 'disc-2-10']);
$onDead->setContact($em16c->getRepository(Contact::class)->find($retired->getId()));
$e = caught(fn() => (new DebtorProfileService($em16c))->save($onDead), InvalidDebtorProfileException::class);
check('F21 a NEW profile on an INACTIVE contact is refused — a new reference needs an active row',
    $e instanceof InvalidDebtorProfileException && $e->validator->hasFieldError('contact_id') && $onDead->getId() === null);

// Müller keeps its profile; deactivating the contact must not lock it.
$em16d = $wireDi();
(new ContactService($em16d))->setActive($em16d->getRepository(Contact::class)->find($mueller->getId()), false);
$em16e   = $wireDi();
$keeper  = $em16e->getRepository(DebtorProfile::class)->findByContact($mueller->getId());
(new DebtorProfileService($em16e))->update($keeper, ['dunning_block' => true]);
$em16f = $wireDi();
check('F22 an EXISTING profile whose contact was deactivated SINCE stays editable (history resolves)',
    $em16f->getRepository(DebtorProfile::class)->findByContact($mueller->getId())?->hasDunningBlock() === true);
(new ContactService($em16f))->setActive($em16f->getRepository(Contact::class)->find($mueller->getId()), true);
$em16g = $wireDi();
(new DebtorProfileService($em16g))->update($em16g->getRepository(DebtorProfile::class)->findByContact($mueller->getId()), ['dunning_block' => false]);


// ── G. the reference rule (ADR-043 decision 19) ──────────────────────────

echo "G. Reference rule for the file master data\n";
$em17       = $wireDi();
$masterData = new DebtorMasterData($em17);
$masterData->setTermsActive($em17->getRepository(PaymentTerms::class)->findByCode('net-30'), false);
$em18 = $wireDi();
check('G1 deactivating payment terms keeps the row — no delete anywhere', $em18->getRepository(PaymentTerms::class)->findByCode('net-30')?->isActive() === false && count($em18->getRepository(PaymentTerms::class)->allInOrder()) === 4);

$profiles = new DebtorProfileService($em18);
$managed  = $em18->getRepository(DebtorProfile::class)->findByContact($mueller->getId());
$profiles->update($managed, ['dunning_block' => false]);
check('G2 an EXISTING profile keeps its now-deactivated terms — history resolves', $managed->getPaymentTermsCode() === 'net-30' && !$managed->hasDunningBlock());

$em19    = $wireDi();
$profiles = new DebtorProfileService($em19);
$managed  = $em19->getRepository(DebtorProfile::class)->findByContact($anna->getId());
$profiles->update($managed, ['payment_terms_code' => 'disc-2-10']);   // an ACTIVE row: allowed
$e = caught(fn() => $profiles->update($managed, ['payment_terms_code' => 'net-30']), InvalidDebtorProfileException::class);
check('G3 … but SWITCHING to a deactivated row is refused — a new reference needs an active one',
    $e instanceof InvalidDebtorProfileException && $e->validator->hasFieldError('payment_terms_code') && $managed->getPaymentTermsCode() === 'disc-2-10');

$em20 = $wireDi();
$fresh = new DebtorProfile(['payment_terms_code' => 'net-30']);
$thirdContact = new Contact(['kind' => 'person', 'first_name' => 'Rita', 'last_name' => 'Neu', 'language' => 'de']);
(new ContactService($em20))->save($thirdContact);
$fresh->setContact($thirdContact);
$e = caught(fn() => (new DebtorProfileService($em20))->save($fresh), InvalidDebtorProfileException::class);
check('G4 a NEW profile cannot start on a deactivated row either', $e instanceof InvalidDebtorProfileException && $e->validator->hasFieldError('payment_terms_code'));
$fresh->setPaymentTermsCode('disc-2-10');
(new DebtorProfileService($em20))->save($fresh);
check('G5 … with an active row it saves', $fresh->getId() !== null);

$em21       = $wireDi();
$masterData = new DebtorMasterData($em21);
$masterData->setTermsActive($em21->getRepository(PaymentTerms::class)->findByCode('net-30'), true);
check('G6 reactivating is the counterpart of deactivating', $em21->getRepository(PaymentTerms::class)->findByCode('net-30')?->isActive() === true);
check('G7 countByPaymentTermsCode counts what references a row', $em21->getRepository(DebtorProfile::class)->countByPaymentTermsCode('disc-2-10') === 2 && $em21->getRepository(DebtorProfile::class)->countByPaymentTermsCode('ghost') === 0);

$masterData->setTargetActive($em21->getRepository(PaymentTarget::class)->findByCode('bank'), false);
$masterData->setLevelActive($em21->getRepository(DunningLevel::class)->findByCode('dunning-2'), false);
$em22 = $wireDi();
check('G9 a payment target and a dunning level deactivate the same way, rows intact',
    $em22->getRepository(PaymentTarget::class)->findByCode('bank')?->isActive() === false
    && $em22->getRepository(DunningLevel::class)->findByCode('dunning-2')?->isActive() === false
    && count($em22->getRepository(PaymentTarget::class)->allInOrder()) === 3
    && count($em22->getRepository(DunningLevel::class)->allInOrder()) === 4);
check('G10 every file entity normalizes its code the same way (lower, trimmed)',
    PaymentTerms::normalizeCode('  NET-30 ') === 'net-30' && PaymentTarget::normalizeCode(' BANK ') === 'bank' && DunningLevel::normalizeCode(' Reminder ') === 'reminder');
check('G11 a deactivated row still RESOLVES by code — that is what «never delete» buys',
    $em22->getRepository(PaymentTarget::class)->findByCode('bank') !== null && $em22->getRepository(DunningLevel::class)->findByCode('dunning-2') !== null);

echo "G. … a row that has become invalid can still be switched off (review 2026-09-22)
";
// The installation drops `fr`: every row whose document text stands there is
// now invalid — and that is exactly when the office wants to switch it off.
$em22b      = $wireDi();
$masterData = new DebtorMasterData($em22b);
$masterData->saveTerms(new PaymentTerms(['code' => 'fr-terms', 'label' => 'Mit Französisch', 'due_days' => 20,
    'document_text' => ['de' => 'Deutsch.', 'fr' => 'Français.']]));
$masterData->saveLevel(new DunningLevel(['code' => 'fr-level', 'label' => 'Mit Französisch', 'level' => 5, 'days_after_due' => 90,
    'document_text' => ['de' => 'Deutsch.', 'fr' => 'Français.']]));
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$rm($base . '/var/cache');
$em23a      = $wireDi();
$masterData = new DebtorMasterData($em23a);
$frTerms    = $em23a->getRepository(PaymentTerms::class)->findByCode('fr-terms');
$frLevel    = $em23a->getRepository(DunningLevel::class)->findByCode('fr-level');
check('G12 both rows are invalid now — their text stands in a language the installation no longer serves',
    (new PaymentTermsValidator($frTerms, $em23a->getRepository(PaymentTerms::class)))->isValid() === false
    && (new DunningLevelValidator($frLevel, $em23a->getRepository(DunningLevel::class)))->isValid() === false);
$masterData->setTermsActive($frTerms, false);
$masterData->setLevelActive($frLevel, false);
$em23b = $wireDi();
check('G13 … and both can still be DEACTIVATED — setActive is a pure state change (the VatMasterData model)',
    $em23b->getRepository(PaymentTerms::class)->findByCode('fr-terms')?->isActive() === false
    && $em23b->getRepository(DunningLevel::class)->findByCode('fr-level')?->isActive() === false);
check('G14 … while a form SAVE of either is still refused — new input keeps the full rules',
    throws(fn() => (new DebtorMasterData($em23b))->saveTerms($em23b->getRepository(PaymentTerms::class)->findByCode('fr-terms')), InvalidMasterDataException::class)
    && throws(fn() => (new DebtorMasterData($em23b))->saveLevel($em23b->getRepository(DunningLevel::class)->findByCode('fr-level')), InvalidMasterDataException::class));
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de', 'fr']];");
$rm($base . '/var/cache');
$em23c      = $wireDi();
$masterData = new DebtorMasterData($em23c);
$masterData->setTermsActive($em23c->getRepository(PaymentTerms::class)->findByCode('fr-terms'), true);
$masterData->setLevelActive($em23c->getRepository(DunningLevel::class)->findByCode('fr-level'), true);
$em23d = $wireDi();
check('G15 … and with the language back both reactivate and validate again',
    $em23d->getRepository(PaymentTerms::class)->findByCode('fr-terms')?->isActive() === true
    && (new PaymentTermsValidator($em23d->getRepository(PaymentTerms::class)->findByCode('fr-terms'), $em23d->getRepository(PaymentTerms::class)))->isValid()
    && $em23d->getRepository(DunningLevel::class)->findByCode('fr-level')?->isActive() === true);
$renamed = $em23d->getRepository(PaymentTerms::class)->findByCode('fr-terms');
$renamed->setCode('renamed-row');
check('G16 setActive still refuses a row whose stored code differs — the ONE rule it keeps',
    throws(fn() => (new DebtorMasterData($em23d))->setTermsActive($renamed, false), MasterDataCodeChangedException::class));


// ── H. the account settings ──────────────────────────────────────────────

echo "H. debtorAccounts (the config accessor and its refusals)\n";
$em23 = $wireDi();
$configured = array_combine(DebtorAccounts::KEYS, array_map(DebtorAccounts::number(...), DebtorAccounts::KEYS));
check('H1 the KMU defaults are what the package config names — rounding has its OWN account (owner, 2026-09-22)', $configured === [
    'receivable' => '1100', 'discount' => '3800', 'loss' => '3805', 'rounding' => '3809', 'dunningFee' => '6950',
]);
$chart = $em23->getRepository(Account::class);
check('H2 … and every one of them exists in the shipped KMU chart',
    array_reduce($configured, fn($ok, $n) => $ok && $chart->findOneBy(['number' => $n]) !== null, true));
check('H2b discount and rounding are DIFFERENT accounts — the reports must keep them apart',
    $configured['discount'] !== $configured['rounding']
    && $chart->findOneBy(['number' => '3809'])?->getName() === 'Rundungsdifferenzen'
    && $chart->findOneBy(['number' => '3809'])?->getParent()?->getNumber() === '38');
check('H3 1100 is «Forderungen aus Lieferungen und Leistungen (Debitoren)», 3805 the loss account, 6950 Finanzertrag',
    $chart->findOneBy(['number' => '1100'])?->getName() === 'Forderungen aus Lieferungen und Leistungen (Debitoren)'
    && str_starts_with($chart->findOneBy(['number' => '3805'])?->getName() ?? '', 'Verluste aus Forderungen')
    && $chart->findOneBy(['number' => '6950'])?->getName() === 'Finanzertrag');
$settings = new DebtorAccounts($em23);
check('H4 postableNumber() answers for every key while the chart is sound', array_reduce(DebtorAccounts::KEYS, fn($ok, $k) => $ok && $settings->postableNumber($k) !== '', true));
check('H5 status() reports no error for any key', array_reduce($settings->status(), fn($ok, $row) => $ok && $row['error'] === null, true));
check('H6 an unknown key is a programming error, not a configuration one', throws(fn() => DebtorAccounts::number('nope'), \InvalidArgumentException::class));

// A project override that dropped a key (BOOT-CONFIG-001) and a malformed value.
// FileFinder memoizes the resolved path, so the cache goes with the file.
$writeOverride = function (?string $php) use ($write, $wireDi, $base, $rm): UnifiedEntityManager {
    $file = $base . '/override/module/debtor/src/App/Config/debtorConfig.inc.php';
    if ($php === null) { @unlink($file); } else { $write('override/module/debtor/src/App/Config/debtorConfig.inc.php', $php); }
    $rm($base . '/var/cache');

    return $wireDi();
};
$emOverride = $writeOverride("<?php return ['viewArea' => false, 'doctrineEntities' => [\\Z77\\Module\\Debtor\\Entities\\DebtorProfile::class], 'debtorAccounts' => ['receivable' => '1100']];");
$overrideActive = throws(fn() => DebtorAccounts::number('loss'), AccountNotConfiguredException::class);
if ($overrideActive) {
    $e = caught(fn() => DebtorAccounts::number('loss'), AccountNotConfiguredException::class);
    check('H7 a key a project override dropped is refused AT THE POINT OF USE, naming the key (BOOT-CONFIG-001)',
        $e instanceof AccountNotConfiguredException && $e->key === 'loss' && str_contains($e->getMessage(), "debtorAccounts['loss']") && str_contains($e->getMessage(), 'Debitorenverlust'));
    check('H8 … status() reports it while the key that is there still answers', (new DebtorAccounts($emOverride))->status()['loss']['error'] !== null
        && DebtorAccounts::number('receivable') === '1100');

    $emOverride = $writeOverride("<?php return ['viewArea' => false, 'debtorAccounts' => ['receivable' => 1100]];");
    check('H9 a non-string account number fails loudly — a typo in a config file is reported, not skipped', throws(fn() => DebtorAccounts::number('receivable'), \UnexpectedValueException::class));

    $emOverride = $writeOverride("<?php return ['viewArea' => false, 'debtorAccounts' => 'nope'];");
    check('H10 a `debtorAccounts` that is not a map fails loudly as well', throws(fn() => DebtorAccounts::number('receivable'), \UnexpectedValueException::class));

    $emOverride = $writeOverride("<?php return ['viewArea' => false, 'doctrineEntities' => [\\Z77\\Module\\Debtor\\Entities\\DebtorProfile::class], 'debtorAccounts' => ['receivable' => '100', 'discount' => '9999999', 'loss' => '1000', 'rounding' => '3800', 'dunningFee' => '6950']];");
    $emOverride = $wireDi();
    (new AccountService($emOverride))->setActive($emOverride->getRepository(Account::class)->findOneBy(['number' => '1000']), false);
    $emOverride = $wireDi();
    $settings   = new DebtorAccounts($emOverride);
    $e = caught(fn() => $settings->postableNumber('receivable'), AccountNotConfiguredException::class);
    check('H11 a GROUP account is refused at the point of use, in German, naming the key',
        $e instanceof AccountNotConfiguredException && str_contains($e->getMessage(), 'Gruppe') && str_contains($e->getMessage(), "debtorAccounts['receivable']"));
    check('H12 an unknown and an inactive account are refused the same way',
        throws(fn() => $settings->postableNumber('discount'), AccountNotConfiguredException::class)
        && throws(fn() => $settings->postableNumber('loss'), AccountNotConfiguredException::class));
    check('H13 the two sound keys still answer', $settings->postableNumber('rounding') === '3800' && $settings->postableNumber('dunningFee') === '6950');
    $status = $settings->status();
    check('H14 status() marks exactly the three broken keys and keeps their configured numbers visible',
        $status['receivable']['error'] !== null && $status['discount']['error'] !== null && $status['loss']['error'] !== null
        && $status['rounding']['error'] === null && $status['dunningFee']['error'] === null
        && $status['receivable']['number'] === '100');
    (new AccountService($emOverride))->setActive($emOverride->getRepository(Account::class)->findOneBy(['number' => '1000']), true);
} else {
    check('H7 a project override replaces the package config (BOOT-CONFIG-001) — skipped, the override did not take effect', false);
}
$em24 = $writeOverride(null);
check('H15 without the override the package defaults are back', DebtorAccounts::number('loss') === '3805' && DebtorAccounts::number('rounding') === '3809');
$writeModules(false);
$rm($base . '/var/cache');
$emNoLedger = $wireDi();
check('H16 with financial UNREGISTERED nothing is refused — the check cannot tell (financial is only suggested)',
    (new DebtorAccounts($emNoLedger))->postableNumber('receivable') === '1100'
    && array_reduce((new DebtorAccounts($emNoLedger))->status(), fn($ok, $row) => $ok && $row['error'] === null, true));
$writeModules(true);
$rm($base . '/var/cache');
$em24 = $wireDi();


// ── I. the backend fragments ─────────────────────────────────────────────

echo "I. Backend fragments (ADR-018) — wiring, shape and source guards by reflection\n";
$em25 = $wireDi();
foreach ([
    ['Zahlungskonditionen', PaymentTermsControllerTrait::class, PaymentTermsLayout::class, 'Backend/PaymentTermsController', '/backend/finance/payment-terms'],
    ['Zahlungsziele',       PaymentTargetControllerTrait::class, PaymentTargetLayout::class, 'Backend/PaymentTargetController', '/backend/finance/payment-target'],
    ['Mahnstufen',          DunningLevelControllerTrait::class, DunningLevelLayout::class, 'Backend/DunningLevelController', '/backend/finance/dunning-level'],
    ['Debitoren',           DebtorControllerTrait::class, DebtorLayout::class, 'Backend/DebtorController', '/backend/finance/debtor'],
] as $i => [$title, $trait, $layout, $path, $url]) {
    $n      = $i + 1;
    $config = $layout::config();
    check("I{$n}a {$title}: the layout pins the body to the fragment's listAction in module-debtor",
        ($config['levelElements']['body']['main'][0]['nameSpace'] ?? '') === 'Z77\\Module\\Debtor'
        && ($config['levelElements']['body']['main'][0]['path'] ?? '') === $path
        && ($config['levelElements']['body']['main'][0]['name'] ?? '') === 'listAction');
    $methods = get_class_methods(new class ($trait) { public function __construct(public string $t) {} });
    $ref = new \ReflectionClass($trait);
    check("I{$n}b {$title}: the fragment offers list and no delete action",
        $ref->hasMethod('listAction') && !$ref->hasMethod('deleteAction') && !$ref->hasMethod('removeAction') && !$ref->hasMethod('confirmDeleteAction'));
    $source = file_get_contents($ref->getFileName());
    check("I{$n}c {$title}: the mount URL is built in ONE place", substr_count($source, "'" . $url . "'") === 1);
}
foreach ([
    ['Zahlungskonditionen', PaymentTermsControllerTrait::class],
    ['Zahlungsziele',       PaymentTargetControllerTrait::class],
    ['Mahnstufen',          DunningLevelControllerTrait::class],
] as $i => [$title, $trait]) {
    // The service-level behaviour is section D29–D32 / G12–G16; here: the
    // switch must ANSWER, never let the refusal become a 500.
    $source = file_get_contents((new \ReflectionClass($trait))->getFileName());
    $toggle = substr($source, strpos($source, 'function toggleActiveAction'));
    check('I' . ($i + 1) . 'd ' . $title . ': the active switch catches DebtorException and answers with a fetchError, never a 500',
        str_contains($toggle, 'catch (DebtorException') && str_contains($toggle, 'fetchError'));
}
check('I5 the three master-data fragments carry an add action and an active switch, the debtor one too',
    array_reduce([PaymentTermsControllerTrait::class, PaymentTargetControllerTrait::class, DunningLevelControllerTrait::class, DebtorControllerTrait::class],
        fn($ok, $t) => $ok && (new \ReflectionClass($t))->hasMethod('addAction') && (new \ReflectionClass($t))->hasMethod('toggleActiveAction'), true));

$templateDir = $package . '/res/view/templates/Backend';
$templates   = glob($templateDir . '/*/*.tpl.php');
check('I6 every screen has its list template, its edit template and its header slot', count($templates) === 12);
check('I7 no template carries a <script> tag or an inline handler (Rule 7)',
    array_reduce($templates, fn($ok, $f) => $ok && !preg_match('/<script|\son[a-z]+\s*=/i', file_get_contents($f)), true));
check('I8 the package ships no JavaScript at all', glob($package . '/res/**/*.js') === [] && glob($package . '/res/*.js') === []);
check('I9 the header slots are partials OF THE FRAGMENT (financial.md, «fragment slots»)',
    is_file($templateDir . '/PaymentTermsController/addButton.tpl.php')
    && is_file($templateDir . '/PaymentTargetController/addButton.tpl.php')
    && is_file($templateDir . '/DunningLevelController/addButton.tpl.php')
    && is_file($templateDir . '/DebtorController/search.tpl.php'));
$backend = str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-backend'));
check('I10 module-backend mounts all four under the finance group',
    is_file($backend . '/src/Ui/Controllers/Finance/PaymentTermsController.php')
    && is_file($backend . '/src/Ui/Controllers/Finance/PaymentTargetController.php')
    && is_file($backend . '/src/Ui/Controllers/Finance/DunningLevelController.php')
    && is_file($backend . '/src/Ui/Controllers/Finance/DebtorController.php')
    && is_file($backend . '/src/Ui/Config/Finance/paymentTermsControllerConfig.inc.php')
    && is_file($backend . '/src/Ui/Config/Finance/paymentTargetControllerConfig.inc.php')
    && is_file($backend . '/src/Ui/Config/Finance/dunningLevelControllerConfig.inc.php')
    && is_file($backend . '/src/Ui/Config/Finance/debtorControllerConfig.inc.php'));
check('I11 the debtor screen is its own — module-contact was not touched',
    !str_contains(file_get_contents($packages['Contact'] . '/src/Ui/ContactControllerTrait.php'), 'Debtor')
    && !array_filter(glob($packages['Contact'] . '/res/view/templates/Backend/*/*.tpl.php'), fn($f) => str_contains(file_get_contents($f), 'Debtor')));
check('I12 the document-text form offers the installation\'s languages, default first', DocumentTextForm::languages() === ['de', 'fr']);
check('I13 … and a posted text for a language the site does not serve is dropped',
    DocumentTextForm::posted(['document_text' => ['de' => 'A', 'it' => 'B']], DocumentTextForm::languages()) === ['de' => 'A', 'fr' => '']);

$sources = array_merge(
    glob($package . '/src/*/*.php'),
    glob($package . '/src/*/*/*.php'),
);
check('I14 no float anywhere in the module: no (float) cast, no floatval, no number_format on an amount',
    array_reduce($sources, fn($ok, $f) => $ok && !preg_match('/\(float\)|\(double\)|floatval\(|number_format\(/', file_get_contents($f)), true));
check('I15 no module-debtor class touches $_POST / $_GET / $_SERVER (Rule 4)',
    array_reduce($sources, fn($ok, $f) => $ok && !preg_match('/\$_(POST|GET|SERVER|REQUEST)\b/', file_get_contents($f)), true));
check('I16 only ONE class names module-financial — the soft boundary (ADR-040 decision 5)',
    count(array_filter($sources, fn($f) => str_contains(file_get_contents($f), 'Module\\\\Financial') || str_contains(file_get_contents($f), 'Module\\Financial'))) === 1);


// ── result ───────────────────────────────────────────────────────────────

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
