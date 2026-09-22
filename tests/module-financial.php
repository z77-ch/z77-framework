<?php

/**
 * module-financial harness (CLI) — P2 part 1: the chart of accounts and the
 * fiscal years with their monthly periods, against a REAL MariaDB (ADR-039
 * decision 16), throwaway schema per run, created by the module's own
 * MIGRATION through the `z77-db` application (never `SchemaTool`).
 *
 * What is load-bearing here:
 *
 *   - `migrate` on an empty database creates `account`, `fiscal_year` and
 *     `fiscal_period` in `utf8mb4_unicode_ci` / InnoDB, and `diff` reports
 *     NO change afterwards — the mapping and the migration agree;
 *   - «KMU-Kontenrahmen übernehmen» works only on an EMPTY chart (owner,
 *     2026-09-22) — one flush — and the shipped chart is complete and
 *     consistent: classes 1–9, every postable account under a chain of
 *     groups up to its class, types plausible per class;
 *   - account validation: digits-only unique number, name, one of the five
 *     types, the parent is a group, no cycle, a group with children stays a
 *     group, the number is immutable, deactivate — never delete; a refused
 *     change never reaches the next flush (ADR-039 decision 9);
 *   - fiscal years: contiguity, at most 24 months, code format and
 *     uniqueness; the monthly periods of a calendar year, a deviating year
 *     (1.7.–30.6.) and a partial first year (15.3.–31.12.), all `open`;
 *     the range `journal-entry.{code}` created at 0 by the opening, and a
 *     rolled-back opening leaves nothing behind — neither year, periods nor
 *     range; a race on the start date becomes a field error.
 *
 * Run: php tests/module-financial.php
 * Needs what tests/module-contact.php needs (vendor/ with Doctrine, a
 * reachable MariaDB, credentials in `%USERPROFILE%\.z77\mariadb.txt` or
 * Z77_TEST_DB_*). Nothing is written into the repository; the schema
 * `z77test_<random>` and the temp installation are removed at the end.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "module-financial: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
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
use Z77\Core\Services\ModuleManager;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\AccountType;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\Period;
use Z77\Module\Financial\Entities\PeriodState;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Module\Financial\Services\AccountNumberChangedException;
use Z77\Module\Financial\Services\AccountService;
use Z77\Module\Financial\Services\ChartNotEmptyException;
use Z77\Module\Financial\Services\FiscalYearService;
use Z77\Module\Financial\Services\InvalidAccountException;
use Z77\Module\Financial\Services\InvalidFiscalYearException;
use Z77\Module\Financial\Ui\AccountControllerTrait;
use Z77\Module\Financial\Ui\FiscalYearControllerTrait;
use Z77\Module\Financial\Validators\AccountValidator;
use Z77\Module\Financial\Validators\FiscalYearValidator;
use Z77\Persistence\Doctrine\Bootstrap as DoctrineBootstrap;
use Z77\Persistence\Doctrine\Console\MigrationDirectories;
use Z77\Persistence\Doctrine\Console\MigrationsApplication;
use Z77\Persistence\Doctrine\Entities\NumberRange;
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

function day(string $ymd): \DateTimeImmutable
{
    return new \DateTimeImmutable($ymd);
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
    fwrite(STDERR, "module-financial: no database password — %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD.\n");
    exit(2);
}

// ── throwaway schema (deliberately NOT in our collation) and installation ─

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-module-financial-' . getmypid();
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

// The REAL package is the module's source path: its config announces the
// entities, its res/migrations holds the migration, its res/charts the chart.
$package = str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-financial'));
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n"
    . "'Z77\\\\Module\\\\Financial\\\\' => ['sourcePaths' => ['{$package}']],\n"
    . "]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'financial', 'modules' => ['financial' => []]];");
$write('config/client/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');

/**
 * The DI wiring Bootstrap::__construct() + pullUpServices() do, reduced to
 * what the drivers and the CLI read. Calling it again is the «fresh
 * request»: a new UnifiedEntityManager, a new Doctrine EntityManager, an
 * empty Identity Map.
 */
$wireDi = static function () use ($base): UnifiedEntityManager {
    DI::getInstance(true)
        ->set('CacheManager', CacheManager::class, true)
        ->set('FileFinder', fn($c) => new FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn($c) => new ModuleManager($c->get('ConfigManager')), true)
        ->set('DataSourceResolver', fn() => new DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new UnifiedEntityManager($c->get('DataSourceResolver')), true)
    ;
    DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return DI::getUnifiedEntityManager();
};
$wireDi();

$db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$tables    = fn() => array_map('strtolower', $db->createSchemaManager()->listTableNames());
$tableInfo = fn(string $table) => $db->fetchAssociative('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$dbName, $table]);
$count     = fn(string $table) => (int) $db->fetchOne("SELECT COUNT(*) FROM {$table}");
$range     = fn(string $name) => $db->fetchOne('SELECT last_number FROM number_range WHERE name = ?', [$name]);
/** Empties the chart (self-referencing FK — checks off for this one statement's session). */
$emptyChart = function () use ($db): void {
    $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
    $db->executeStatement('DELETE FROM account');
    $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
};

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
$config = DI::getModuleManager()->getModuleConfig('financial');
check('A0 the module config announces exactly the three Doctrine entities', $config?->get('doctrineEntities') === [Account::class, FiscalYear::class, Period::class]);
$dirs = MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder());
check('A1 the module\'s res/migrations is collected under Z77\\Module\\Financial\\Migrations', ($dirs['Z77\\Module\\Financial\\Migrations'] ?? '') === $package . '/res/migrations');
check('A2 the database is empty', $tables() === []);
[$code, $out] = $run(['command' => 'migrate']);
check('A3 migrate exits 0' . ($code !== 0 ? " — got {$code}: " . trim($out) : ''), $code === 0);
check('A4 … migrated up to the module migration (the package one runs first, by timestamp)', str_contains($out, 'Z77\\Module\\Financial\\Migrations\\Version20260922071232'));
check('A5 account, fiscal_period, fiscal_year exist (plus number_range and the metadata table)', $tables() === ['account', 'fiscal_period', 'fiscal_year', 'number_range', MigrationsApplication::STORAGE_TABLE]);
$allUnicode = true;
foreach (['account', 'fiscal_year', 'fiscal_period'] as $table) {
    $info = $tableInfo($table);
    $allUnicode = $allUnicode && ($info['TABLE_COLLATION'] ?? '') === 'utf8mb4_unicode_ci' && ($info['ENGINE'] ?? '') === 'InnoDB';
}
check('A6 every module table is utf8mb4_unicode_ci and InnoDB although the database default is general_ci', $allUnicode);
$columns = $db->fetchAllKeyValue('SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL', [$dbName, 'account']);
check('A7 … and so is every string column of account', $columns !== [] && count(array_unique($columns)) === 1 && reset($columns) === 'utf8mb4_unicode_ci');
$fk = fn(string $table) => $db->fetchFirstColumn('SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY 1', [$dbName, $table]);
check('A8 foreign keys: account → account (parent), fiscal_period → fiscal_year', $fk('account') === ['account'] && $fk('fiscal_period') === ['fiscal_year'] && $fk('fiscal_year') === []);
[$code, $out] = $run(['command' => 'migrate']);
check('A9 a second migrate is a no-op', $code === 0 && str_contains($out, 'Already at the latest version'));
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Financial\\Migrations']);
check('A10 diff after migrate reports NO change — mapping and migration agree', $code !== 0 && str_contains($out, 'No changes detected') && count(glob($package . '/res/migrations/Version*.php')) === 1);
[$code, $out] = $run(['command' => 'status']);
check('A11 status lists the module namespace and two executed migrations', $code === 0 && str_contains($out, 'Z77\\Module\\Financial\\Migrations') && preg_match('/\| Executed\s+\|\s+2\s+\|/', $out) === 1);

// ── B. the KMU chart: a button on an EMPTY chart ─────────────────────────

echo "B. KMU chart of accounts (adopt only when empty)\n";
$em   = $wireDi();
$repo = $em->getRepository(Account::class);
check('B0 convention repository resolves; the chart starts empty', $repo instanceof AccountRepository && $repo->isEmpty());
$raw = file_get_contents(AccountService::KMU_CHART_FILE);
check('B1 the resource file is UTF-8 without BOM', $raw !== false && !str_starts_with($raw, "\xEF\xBB\xBF") && mb_check_encoding($raw, 'UTF-8'));
$rows = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
$created = (new AccountService($em))->adoptKmuChart();
check('B2 adoptKmuChart() creates every row of the file', $created === count($rows) && $count('account') === count($rows) && $created > 150);

$em   = $wireDi();
$repo = $em->getRepository(Account::class);
$chart = $repo->allInOrder();
$byNumber = [];
foreach ($chart as $a) { $byNumber[$a->getNumber()] = $a; }
$numbers = array_map(fn(Account $a) => $a->getNumber(), $chart);
$sorted  = $numbers;
sort($sorted, SORT_STRING);
check('B3 allInOrder() returns the chart in string order (1, 10, 100, 1000, 1020, …)', array_slice($numbers, 0, 5) === ['1', '10', '100', '1000', '1020'] && $numbers === $sorted);
$roots = array_values(array_filter($chart, fn(Account $a) => $a->getParent() === null));
check('B4 the roots are exactly the classes 1–9, all groups', array_map(fn(Account $a) => $a->getNumber(), $roots) === ['1', '2', '3', '4', '5', '6', '7', '8', '9'] && array_filter($roots, fn(Account $a) => $a->isPostable()) === []);
$chainOk = true;
$classOk = true;
foreach ($chart as $a) {
    $depth = 0;
    $top   = $a;
    for ($p = $a->getParent(); $p !== null; $p = $p->getParent()) {
        $chainOk = $chainOk && !$p->isPostable() && strlen($p->getNumber()) < strlen($a->getNumber());
        $top     = $p;
        $depth++;
    }
    $classOk = $classOk && $a->getNumber()[0] === $top->getNumber();
    if ($a->isPostable()) {
        $chainOk = $chainOk && $depth >= 2 && strlen($a->getNumber()) === 4;
    }
}
check('B5 every postable account hangs under a chain of GROUPS (shorter numbers) up to its class, at least class → main group; four digits', $chainOk);
// The Sterchi groups are number RANGES, not prefixes (1020 sits in group 100,
// 1850 in 180): what must hold is that the chart's string order is exactly
// the tree read top-down, children in number order — the list indents by it.
$children = [];
foreach ($chart as $a) { $children[$a->getParent()?->getNumber() ?? ''][] = $a->getNumber(); }
$preorder = [];
$walk = function (string $n) use (&$walk, &$preorder, $children): void {
    $preorder[] = $n;
    foreach ($children[$n] ?? [] as $c) { $walk($c); }
};
foreach ($children[''] as $root) { $walk($root); }
check('B6 every account sits in its class (first digit), and the string order IS the tree read top-down', $classOk && $preorder === $numbers);
$typesOk = true;
foreach ($chart as $a) {
    if (!$a->isPostable()) { continue; }
    $class = $a->getNumber()[0];
    $expected = match ($class) {
        '1' => ['asset'],
        '2' => str_starts_with($a->getNumber(), '28') || str_starts_with($a->getNumber(), '29') ? ['equity'] : ['liability'],
        '3' => ['revenue'],
        '4', '5' => ['expense'],
        '6', '7', '8' => ['expense', 'revenue'],
        default => [],
    };
    $typesOk = $typesOk && in_array($a->getType(), $expected, true);
}
check('B7 types plausible per class: 1 asset, 2 liability (28/29 equity), 3 revenue, 4–5 expense, 6–8 expense or revenue, 9 nothing postable', $typesOk);
check('B8 all five types occur; every account active', count(array_unique(array_map(fn(Account $a) => $a->getType(), $chart))) === 5 && array_filter($chart, fn(Account $a) => !$a->isActive()) === []);
check('B9 German names with umlauts intact; the key accounts are where a Swiss bookkeeper expects them',
    ($byNumber['10'] ?? null)?->getName() === 'Umlaufvermögen' && ($byNumber['1020'] ?? null)?->getName() === 'Bankguthaben'
    && ($byNumber['1100'] ?? null)?->getType() === 'asset' && ($byNumber['2200'] ?? null)?->getType() === 'liability'
    && ($byNumber['2979'] ?? null)?->getType() === 'equity' && ($byNumber['3200'] ?? null)?->getType() === 'revenue'
    && ($byNumber['6950'] ?? null)?->getType() === 'revenue' && ($byNumber['1170'] ?? null)?->isPostable() === true);
check('B10 every row passes the validator as stored', array_reduce($chart, fn($ok, Account $a) => $ok && (new AccountValidator($a, $repo))->isValid(), true));
$e = caught(fn() => (new AccountService($em))->adoptKmuChart(), ChartNotEmptyException::class);
check('B11 a second adoption is refused — the chart is not empty — and writes nothing', $e !== null && $count('account') === count($rows));
$emptyChart();
$em = $wireDi();
(new AccountService($em))->save(new Account(['number' => '1000', 'name' => 'Kasse', 'type' => 'asset', 'active' => false]));
check('B12 one INACTIVE account is enough to refuse the adoption (a migrated chart, P5b)', throws(fn() => (new AccountService($em))->adoptKmuChart(), ChartNotEmptyException::class) && $count('account') === 1);
$emptyChart();
$em = $wireDi();
(new AccountService($em))->adoptKmuChart();
check('B13 … and after emptying, the adoption runs again (one flush, same count)', $count('account') === count($rows));

// ── C. accounts: validation and the write side ───────────────────────────

echo "C. Accounts (validation, write side)\n";
$em      = $wireDi();
$repo    = $em->getRepository(Account::class);
$service = new AccountService($em);
$group   = $repo->findOneBy(['number' => '100']);   // Flüssige Mittel — a group
$bank    = $repo->findOneBy(['number' => '1020']);  // Bankguthaben — postable
$refused = function (array $row, string $field) use ($service): bool {
    $e = caught(fn() => $service->save(new Account($row)), InvalidAccountException::class);
    return $e !== null && $e->validator->hasFieldError($field);
};
$valid = ['number' => '1021', 'name' => 'Bank UBS', 'type' => 'asset', 'postable' => true];
check('C1 empty number, letters, a dot, eleven digits → number', $refused(['number' => ''] + $valid, 'number') && $refused(['number' => '10a0'] + $valid, 'number')
    && $refused(['number' => '10.20'] + $valid, 'number') && $refused(['number' => '12345678901'] + $valid, 'number'));
check('C2 a number that exists (1020) → number, naming the existing account', (function () use ($service, $valid) {
    $e = caught(fn() => $service->save(new Account(['number' => '1020'] + $valid)), InvalidAccountException::class);
    return $e !== null && str_contains($e->validator->getFieldError('number'), 'Bankguthaben');
})());
check('C3 empty name → name; unknown type → type; empty type → type', $refused(['name' => ''] + $valid, 'name') && $refused(['type' => 'income'] + $valid, 'type') && $refused(['type' => ''] + $valid, 'type'));
check('C4 the five types are the model\'s', array_map(fn(AccountType $t) => $t->value, AccountType::cases()) === ['asset', 'liability', 'equity', 'expense', 'revenue']);
check('C5 a POSTABLE account as parent → parent', $refused(['parent' => $bank] + $valid, 'parent'));
check('C6 nothing was written by the refused saves', $count('account') === count($rows));
$ubs = new Account(['parent' => $group] + $valid);
$service->save($ubs);
check('C7 a valid account under a group saves; type normalized, active by default', $ubs->getId() !== null && $ubs->isActive() && $db->fetchOne('SELECT parent_id FROM account WHERE number = ?', ['1021']) == $group->getId());
check('C8 label() is «number name»', $ubs->label() === '1021 Bank UBS');

$em      = $wireDi();
$repo    = $em->getRepository(Account::class);
$service = new AccountService($em);
$class1  = $repo->findOneBy(['number' => '1']);
$main10  = $repo->findOneBy(['number' => '10']);
$group   = $repo->findOneBy(['number' => '100']);
$e = caught(fn() => $service->update($class1, ['parent' => $group]), InvalidAccountException::class);
check('C9 a cycle is refused: class 1 under its own grand-child group 100', $e !== null && $e->validator->hasFieldError('parent') && $class1->getParent() === null);
$e = caught(fn() => $service->update($main10, ['parent' => $main10]), InvalidAccountException::class);
check('C10 an account under itself is refused', $e !== null && $e->validator->hasFieldError('parent'));
$e = caught(fn() => $service->update($group, ['postable' => true]), InvalidAccountException::class);
check('C11 a group WITH children cannot become postable', $e !== null && $e->validator->hasFieldError('postable') && !$group->isPostable());
$e = caught(fn() => $service->update($group, ['name' => '']), InvalidAccountException::class);
check('C12 update() refused: the managed entity is untouched, the DRAFT carries the input', $e !== null && $group->getName() === 'Flüssige Mittel' && $e->draft !== $group && $e->draft->getName() === '' && $e->draft->getId() === $group->getId());
$ubs = $repo->findOneBy(['number' => '1021']);
$service->update($ubs, ['name' => 'Bank UBS AG']);   // an unrelated, valid write in the SAME EntityManager
check('C13 … and the next flush of something else writes none of the refused changes', $db->fetchOne('SELECT name FROM account WHERE number = ?', ['100']) === 'Flüssige Mittel'
    && $db->fetchOne('SELECT postable FROM account WHERE number = ?', ['100']) == 0 && $db->fetchOne('SELECT parent_id FROM account WHERE number = ?', ['1']) === null
    && $db->fetchOne('SELECT name FROM account WHERE number = ?', ['1021']) === 'Bank UBS AG');
$e = caught(fn() => $service->update($ubs, ['number' => '1022']), AccountNumberChangedException::class);
check('C14 the number is immutable (AccountNumberChangedException), passing it unchanged is fine', $e !== null && $ubs->getNumber() === '1021' && caught(fn() => $service->update($ubs, ['number' => '1021', 'name' => 'Bank UBS AG']), \Throwable::class) === null);
$leaf = new Account(['number' => '1029', 'name' => 'Leere Gruppe', 'type' => 'asset', 'postable' => false, 'parent' => $group]);
$service->save($leaf);
$service->update($leaf, ['postable' => true]);
check('C15 a group WITHOUT children may become postable; a postable one may become a group', $db->fetchOne('SELECT postable FROM account WHERE number = ?', ['1029']) == 1
    && caught(fn() => $service->update($leaf, ['postable' => false]), \Throwable::class) === null);
$service->update($ubs, ['parent' => $repo->findOneBy(['number' => '10'])]);
check('C16 moving an account to another group', $db->fetchOne('SELECT parent_id FROM account WHERE number = ?', ['1021']) == $main10->getId());
$service->setActive($ubs, false);
$read = $wireDi()->getRepository(Account::class)->findOneBy(['number' => '1021']);
check('C17 deactivation is persisted, the row stays', $read !== null && !$read->isActive());
check('C18 the LogicExceptions: save(existing), update(new)', throws(fn() => $service->save($ubs), \LogicException::class) && throws(fn() => $service->update(new Account(), []), \LogicException::class));
check('C19 the validator without a repository checks format and parent chain only', (new AccountValidator(new Account(['number' => '1020'] + $valid)))->isValid()
    && !(new AccountValidator(new Account(['number' => 'x'] + $valid)))->isValid());

// ── D. fiscal years and periods ──────────────────────────────────────────

echo "D. Fiscal years (validation, periods, number range)\n";
$em      = $wireDi();
$years   = $em->getRepository(FiscalYear::class);
$service = new FiscalYearService($em);
check('D0 convention repository resolves; no year yet', $years instanceof FiscalYearRepository && $years->latest() === null && $years->allWithPeriods() === []);
$invalid = function (string $code, string $start, string $end, string $field, bool $withRepo = true) use ($years): bool {
    $v = new FiscalYearValidator(new FiscalYear($code, day($start), day($end)), $withRepo ? $years : null);
    return !$v->isValid() && $v->hasFieldError($field);
};
check('D1 code: empty, blank inside, underscore, trailing hyphen, 17 characters → code', $invalid('', '2026-01-01', '2026-12-31', 'code') && $invalid('2026 27', '2026-01-01', '2026-12-31', 'code')
    && $invalid('2026_27', '2026-01-01', '2026-12-31', 'code') && $invalid('2026-', '2026-01-01', '2026-12-31', 'code') && $invalid(str_repeat('1', 17), '2026-01-01', '2026-12-31', 'code'));
check('D2 the setter lower-cases and trims the code', (new FiscalYear(' GJ-2026 '))->getCode() === 'gj-2026');
check('D3 end before or on the start → end_date', $invalid('x', '2026-01-01', '2025-12-31', 'end_date') && $invalid('x', '2026-01-01', '2026-01-01', 'end_date'));
check('D4 24 months is the limit: 15.3.2025–14.3.2027 passes, –15.3.2027 is refused', (new FiscalYearValidator(new FiscalYear('x', day('2025-03-15'), day('2027-03-14'))))->isValid()
    && $invalid('x', '2025-03-15', '2027-03-15', 'end_date', false));
$v = new FiscalYearValidator(new FiscalYear('x', null, null));
check('D5 missing dates → start_date and end_date', !$v->isValid() && $v->hasFieldError('start_date') && $v->hasFieldError('end_date'));
$v = new FiscalYearValidator(new FiscalYear('', null, day('2026-12-31')));
check('D5b empty code with a missing date: only the date is reported — the code would be proposed from the dates (review L5)', !$v->isValid() && $v->hasFieldError('start_date') && !$v->hasFieldError('code'));
check('D6 proposeCode(): 2026 inside a year, 2027-28 across, 2025-27 for an extended year', FiscalYearService::proposeCode(day('2026-01-01'), day('2026-12-31')) === '2026'
    && FiscalYearService::proposeCode(day('2027-07-01'), day('2028-06-30')) === '2027-28' && FiscalYearService::proposeCode(day('2025-03-15'), day('2027-03-14')) === '2025-27');
$proposal = $service->proposeNext();
check('D7 proposeNext() for the first year: 1 January of this year, twelve months, code from the dates', $proposal->getStartDate()->format('m-d') === '01-01'
    && $proposal->getStartDate()->format('Y') === date('Y') && $proposal->getEndDate()->format('m-d') === '12-31' && $proposal->getCode() === date('Y') && $proposal->getId() === null);

echo "D. … a partial first year 15.3.–31.12.2025\n";
$first = new FiscalYear('2025', day('2025-03-15'), day('2025-12-31'));
$service->open($first);
$periods = $first->getPeriods();
check('D8 opened: id, ten periods, the first 15.3.–31.3., the last December', $first->getId() !== null && count($periods) === 10
    && $periods[0]->getStartDate()->format('Y-m-d') === '2025-03-15' && $periods[0]->getEndDate()->format('Y-m-d') === '2025-03-31'
    && $periods[9]->getStartDate()->format('Y-m-d') === '2025-12-01' && $periods[9]->getEndDate()->format('Y-m-d') === '2025-12-31');
check('D9 the range journal-entry.2025 exists at 0 (created, nothing consumed)', $range('journal-entry.2025') === 0 || $range('journal-entry.2025') === '0');
check('D10 journalEntryRange() composes the name in one place', $first->journalEntryRange() === 'journal-entry.2025' && FiscalYear::JOURNAL_ENTRY_RANGE_PREFIX === 'journal-entry.');

echo "D. … contiguity\n";
$em      = $wireDi();
$years   = $em->getRepository(FiscalYear::class);
$service = new FiscalYearService($em);
check('D11 a gap is refused (2.1.2026), naming the expected start', (function () use ($years) {
    $v = new FiscalYearValidator(new FiscalYear('2026', day('2026-01-02'), day('2026-12-31')), $years);
    return !$v->isValid() && str_contains($v->getFieldError('start_date'), '01.01.2026');
})());
check('D12 an overlap is refused (1.12.2025)', $invalid('2026', '2025-12-01', '2026-12-31', 'start_date'));
check('D13 a second «first» year before the existing one is refused', $invalid('2024', '2024-01-01', '2024-12-31', 'start_date'));
$e = caught(fn() => $service->open(new FiscalYear('2026', day('2026-01-02'), day('2026-12-31'))), InvalidFiscalYearException::class);
check('D14 open() refuses it and writes nothing — no year, no periods, no range', $e !== null && $count('fiscal_year') === 1 && $count('fiscal_period') === 10 && $range('journal-entry.2026') === false);
$proposal = $service->proposeNext();
check('D15 proposeNext() follows the latest year: 1.1.2026–31.12.2026, code 2026', $proposal->getStartDate()->format('Y-m-d') === '2026-01-01' && $proposal->getEndDate()->format('Y-m-d') === '2026-12-31' && $proposal->getCode() === '2026');

echo "D. … a calendar year 2026\n";
$service->open($proposal);
$em    = $wireDi();
$years = $em->getRepository(FiscalYear::class);
$y2026 = $years->findOneBy(['code' => '2026']);
$ps    = $y2026->getPeriods();
$monthsOk = count($ps) === 12;
foreach ($ps as $i => $p) {
    $month    = sprintf('2026-%02d', $i + 1);
    $monthsOk = $monthsOk && $p->getStartDate()->format('Y-m-d') === $month . '-01' && $p->getEndDate()->format('Y-m-d') === day($month . '-01')->modify('last day of this month')->format('Y-m-d');
}
check('D16 twelve calendar-month periods, read back in date order (February ends 28.2.)', $monthsOk && $ps[1]->getEndDate()->format('Y-m-d') === '2026-02-28');
check('D17 every period is open', array_reduce($ps, fn($ok, Period $p) => $ok && $p->getState() === PeriodState::Open->value, true) && $db->fetchOne("SELECT COUNT(*) FROM fiscal_period WHERE state <> 'open'") == 0);
check('D18 the three states are the ADR\'s', array_map(fn(PeriodState $s) => $s->value, PeriodState::cases()) === ['open', 'vat-settled', 'closed']);
check('D19 the range journal-entry.2026 exists at 0', (string) $range('journal-entry.2026') === '0');
$draw = $em->getTransaction(FiscalYear::class)->run(fn() => $em->getRepository(NumberRange::class)->next('journal-entry.2026'));
check('D20 … the first draw from it returns 1 (the opening consumed nothing)', $draw === 1);

echo "D. … a short year and a deviating year 1.7.–30.6.\n";
$em      = $wireDi();
$service = new FiscalYearService($em);
$service->open(new FiscalYear('2027', day('2027-01-01'), day('2027-06-30')));
$deviating = $service->proposeNext();
check('D21 after a short year 1.1.–30.6.2027 the proposal is 1.7.2027–30.6.2028, code 2027-28 (two years start in 2027)', $deviating->getStartDate()->format('Y-m-d') === '2027-07-01' && $deviating->getEndDate()->format('Y-m-d') === '2028-06-30' && $deviating->getCode() === '2027-28');
$service->open($deviating);
$dp = $deviating->getPeriods();
check('D22 the deviating year has twelve periods July 2027 … June 2028', count($dp) === 12 && $dp[0]->getStartDate()->format('Y-m-d') === '2027-07-01' && $dp[5]->getStartDate()->format('Y-m-d') === '2027-12-01'
    && $dp[6]->getStartDate()->format('Y-m-d') === '2028-01-01' && $dp[7]->getEndDate()->format('Y-m-d') === '2028-02-29' && $dp[11]->getEndDate()->format('Y-m-d') === '2028-06-30');
check('D23 ranges journal-entry.2027 and journal-entry.2027-28 exist, both at 0', (string) $range('journal-entry.2027') === '0' && (string) $range('journal-entry.2027-28') === '0');
$e = caught(fn() => $service->open(new FiscalYear('2027', day('2028-07-01'), day('2029-06-30'))), InvalidFiscalYearException::class);
check('D24 a code already taken is refused (code) — the next year needs its own', $e !== null && $e->validator->hasFieldError('code') && !$e->validator->hasFieldError('start_date'));
$list = $em->getRepository(FiscalYear::class)->allWithPeriods();
check('D25 allWithPeriods(): newest first, periods loaded', array_map(fn(FiscalYear $y) => $y->getCode(), $list) === ['2027-28', '2027', '2026', '2025'] && count($list[3]->getPeriods()) === 10);

echo "D. … open() owns its unit of work (review L1)\n";
$em      = $wireDi();
$service = new FiscalYearService($em);
$nested  = null;
$outer   = function () use ($service, &$nested) {
    $nested = caught(fn() => $service->open(new FiscalYear('2028-29', day('2028-07-01'), day('2029-06-30'))), \LogicException::class);
    throw new \RuntimeException('outer aborts');
};
caught(fn() => $em->getTransaction(FiscalYear::class)->run($outer), \RuntimeException::class);
check('D26 open() inside an open unit of work is refused (LogicException) — the race mapping needs its own flush', $nested !== null && str_contains($nested->getMessage(), 'owns its unit of work'));
check('D27 … nothing was written, and the port is closed again', $count('fiscal_year') === 4 && $count('fiscal_period') === 10 + 12 + 6 + 12
    && $range('journal-entry.2028-29') === false && !$wireDi()->getTransaction(FiscalYear::class)->isOpen());

echo "D. … a race on the start date becomes a field error\n";
// Another request opened the same next year between our validation and our
// commit: simulated by a row the validator cannot see as «latest» (its end
// lies in the past), carrying the start date we are about to use.
$db->executeStatement("INSERT INTO fiscal_year (code, start_date, end_date) VALUES ('race', '2028-07-01', '2020-01-01')");
$em      = $wireDi();
$service = new FiscalYearService($em);
$e = caught(fn() => $service->open(new FiscalYear('2028-29', day('2028-07-01'), day('2029-06-30'))), InvalidFiscalYearException::class);
check('D28 the unique start date is reported as a start_date field error (no 500)', $e !== null && $e->validator->hasFieldError('start_date') && str_contains($e->validator->getFieldError('start_date'), 'soeben'));
check('D29 … and the loser left nothing behind — no periods, no range', $count('fiscal_year') === 5 && $count('fiscal_period') === 40 && $range('journal-entry.2028-29') === false);
$db->executeStatement("DELETE FROM fiscal_year WHERE code = 'race'");
$em = $wireDi();
(new FiscalYearService($em))->open(new FiscalYear('2028-29', day('2028-07-01'), day('2029-06-30')));
check('D30 after the other opening is gone, the year opens normally', $count('fiscal_year') === 5 && (string) $range('journal-entry.2028-29') === '0');

echo "D. … a leftover range is refused, not continued (review M1)\n";
// A range row that already exists (a removed year, an import) must not be
// adopted silently — the year would start its entries at 6.
$db->executeStatement("INSERT INTO number_range (name, last_number) VALUES ('journal-entry.2029-30', 5)");
$em = $wireDi();
$e  = caught(fn() => (new FiscalYearService($em))->open(new FiscalYear('2029-30', day('2029-07-01'), day('2030-06-30'))), InvalidFiscalYearException::class);
check('D32 open() refuses a year whose range already exists, as a code field error naming the range', $e !== null && $e->validator->hasFieldError('code') && str_contains($e->validator->getFieldError('code'), 'journal-entry.2029-30'));
check('D33 … the unit of work rolled back: no year, no periods, the old range untouched at 5, the port closed', $count('fiscal_year') === 5 && $count('fiscal_period') === 52
    && (string) $range('journal-entry.2029-30') === '5' && !$wireDi()->getTransaction(FiscalYear::class)->isOpen());
$em = $wireDi();
(new FiscalYearService($em))->open(new FiscalYear('2029-30b', day('2029-07-01'), day('2030-06-30')));
check('D34 … with another code the year opens, its own range at 0', $count('fiscal_year') === 6 && (string) $range('journal-entry.2029-30b') === '0');

echo "D. … guards\n";
$opened = $wireDi()->getRepository(FiscalYear::class)->findOneBy(['code' => '2026']);
check('D31 open() refuses an existing year; addPeriod() refuses an opened year; a period cannot end before it starts',
    throws(fn() => (new FiscalYearService(DI::getUnifiedEntityManager()))->open($opened), \LogicException::class)
    && throws(fn() => $opened->addPeriod(new Period($opened, day('2026-01-01'), day('2026-01-31'))), \LogicException::class)
    && throws(fn() => new Period($opened, day('2026-02-01'), day('2026-01-31')), \LogicException::class));

// ── E. no delete, no edit of a year, no transition ───────────────────────

echo "E. Deactivate / never delete; no year edit, no period transition (part 1)\n";
$methodsOf = fn(string $class) => array_map(fn(\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass($class))->getMethods());
$hasAny    = fn(array $methods, array $words) => array_filter($methods, fn($m) => array_filter($words, fn($w) => stripos($m, $w) !== false) !== []) !== [];
check('E1 AccountService has no delete; FiscalYearService has no delete, update or close', !$hasAny($methodsOf(AccountService::class), ['delete', 'remove'])
    && !$hasAny($methodsOf(FiscalYearService::class), ['delete', 'remove', 'update', 'close', 'settle']));
check('E2 Period has no state setter (P5 moves states); FiscalYear has no public setter — code and dates come with the constructor', !$hasAny($methodsOf(Period::class), ['setState', 'close', 'settle'])
    && array_filter((new \ReflectionClass(FiscalYear::class))->getMethods(\ReflectionMethod::IS_PUBLIC), fn(\ReflectionMethod $m) => str_starts_with($m->getName(), 'set')) === []);
$accountActions = $methodsOf(AccountControllerTrait::class);
check('E3 the account trait: no delete action; toggle and the KMU adoption exist', !$hasAny($accountActions, ['delete', 'remove']) && in_array('toggleActiveAction', $accountActions, true)
    && in_array('adoptKmuChartAction', $accountActions, true) && in_array('confirmAdoptKmuChartAction', $accountActions, true));
$yearActions = $methodsOf(FiscalYearControllerTrait::class);
check('E4 the fiscal-year trait: list and open only', array_values(array_filter($yearActions, fn($m) => str_ends_with($m, 'Action'))) === ['listAction', 'openAction']);
$traitSource = file_get_contents($package . '/src/Ui/AccountControllerTrait.php');
check('E5 the account trait maps a body only onto the NEW account of «add» (source guard, ADR-039 decision 9)', preg_match_all('/->mapFromArray\(/', $traitSource) === 1 && str_contains($traitSource, '$account->mapFromArray($values);')
    && strpos($traitSource, '$account->mapFromArray($values);') < strpos($traitSource, 'function editAction'));
$host = new class { use FiscalYearControllerTrait; };
$parse = new \ReflectionMethod($host, 'fiscalYearDate');
check('E6 the form\'s date parser takes real calendar dates only (29.2.2028 yes, 30.2.2026 / 2026-2-1 / garbage no)', $parse->invoke(null, '2028-02-29')?->format('Y-m-d') === '2028-02-29'
    && $parse->invoke(null, '2026-02-30') === null && $parse->invoke(null, '2026-2-1') === null && $parse->invoke(null, 'morgen') === null && $parse->invoke(null, ['x']) === null);

echo "\n" . ($fail === 0 ? "PASS — {$pass} checks" : "FAIL — {$fail} of " . ($pass + $fail) . " checks") . "\n";
exit($fail === 0 ? 0 : 1);
