<?php

/**
 * Fresh-install harness for the dashboard «Ersteinrichtung» (CLI) — the end-to-end twin of
 * `tests/project-health.php`. That harness checks the bridge's RULE with a registry of its own,
 * a hand-written role resolver and only the phase-A modules; it stayed green while the owner's
 * fresh install on a second PC (2026-11-20, DASH-SETUP-FRESH-001 in docs/topics/backend.md) showed
 * «Mandant erfassen» without «Kontenplan übernehmen» in front of it. This one asks the way the
 * dashboard asks, with nothing of its own in between:
 *
 *   - a temp installation with EVERY module package of the monorepo registered — the module list
 *     derived from the packages' own `composer.json` `Z77\Module\X\` prefixes, as the installer
 *     registers a project that installs the whole suite;
 *   - a throwaway, migrated schema `z77test_*`: no chart, no fiscal year, no mandator; the
 *     packages' `*.default.json` seeds in `data/` as the installer writes them;
 *   - the registry `ProjectChecks::fromModules()` over the REAL module configs;
 *   - access through the REAL `AuthService::canReach()` (the backend module config read by the
 *     ModuleManager), for a user with exactly the roles the first admin gets — `SetupController`
 *     (setup token) and the installer's admin prompt both create `[superUser]` — and for a plain
 *     admin;
 *   - the lines from `ProjectHealthDashboard::entries()` itself.
 *
 * Asserted: every check a module package declares is registered; every target is a real
 * controller action and reachable for the fresh admin (and the plain admin), none for an editor;
 * no check fails on the empty schema; the first three lines are «Kontenplan übernehmen»,
 * «Mandant erfassen», «Geschäftsjahr eröffnen». Prints the table check → target → reachable.
 *
 * Run: php tests/project-health-fresh.php — needs the monorepo's vendor/, a reachable MariaDB and
 * the `z77test` credentials (`%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*); without them the
 * database part is SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "project-health-fresh: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Z77\Core\Config\AuthRole;
use Z77\Core\DI;
use Z77\Module\Backend\Dashboard\ProjectHealthDashboard;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Dashboard\DashboardEntry;
use Z77\Shared\Libraries\Convention\Naming;
use Z77\Shared\ProjectHealth\CheckCategory;
use Z77\Shared\ProjectHealth\ProjectCheck;
use Z77\Shared\ProjectHealth\ProjectChecks;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? " — got: {$got}" : '') . "\n"; }
}

function j(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ── credentials: never in the repository ─────────────────────────────────

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
    echo "F. Fresh install — SKIPPED (no z77test password: %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD)\n";
    echo "\n{$pass} passed, {$fail} failed\n";
    exit(0);
}

// ── S. the installation ──────────────────────────────────────────────────

echo "S. Set-up: every module package registered, throwaway schema, migrations\n";

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-project-health-fresh-' . getmypid();
define('ABS_BASE_PATH', $base);
define('DEBUG', false);
$rm = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (basename($f) === '.' || basename($f) === '..') { continue; }
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($dir);
};
$write = function (string $rel, string $content) use ($base): void {
    $path = $base . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
};

$dbName  = 'z77test_' . bin2hex(random_bytes(4));
$dbAdmin = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$dbAdmin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
register_shutdown_function(static function () use ($dbAdmin, $dbName, $rm, $base): void {
    try { $dbAdmin->executeStatement("DROP DATABASE IF EXISTS `{$dbName}`"); } catch (\Throwable) {}
    $rm($base);
});

// The module list the installer would write for a project that installs every module package:
// one entry per `Z77\Module\X\` prefix of a package's own composer.json (resolveModules()).
$modules    = [];   // module key => package dir
$namespaces = "'Z77\\\\Shared\\\\' => ['sourcePaths' => ['{$root}/packages/kernel/shared']],\n";
foreach (glob($root . '/packages/*/composer.json') as $composerFile) {
    $packageDir = str_replace('\\', '/', dirname($composerFile));
    $psr4       = json_decode((string) file_get_contents($composerFile), true)['autoload']['psr-4'] ?? [];
    foreach (array_keys($psr4) as $namespace) {
        $parts = array_values(array_filter(explode('\\', $namespace)));
        if (count($parts) === 3 && $parts[0] === 'Z77' && $parts[1] === 'Module') {
            $modules[strtolower($parts[2])] = $packageDir;
            $namespaces .= "'Z77\\\\Module\\\\{$parts[2]}\\\\' => ['sourcePaths' => ['{$packageDir}']],\n";
        }
    }
}
$moduleList = implode(', ', array_map(static fn(string $key) => var_export($key, true) . ' => []', array_keys($modules)));
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'backend', 'modules' => [{$moduleList}]];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
// The installer's data seeds: every `*.default.json` under a registered package's data/ → data/.
foreach ($modules as $packageDir) {
    if (!is_dir($packageDir . '/data')) {
        continue;
    }
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($packageDir . '/data', \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $seed) {
        if (!str_ends_with($seed->getFilename(), '.default.json')) {
            continue;
        }
        $rel = substr(str_replace('\\', '/', $seed->getPathname()), strlen($packageDir . '/data/'));
        $write('data/' . substr($rel, 0, -strlen('.default.json')) . '.json', (string) file_get_contents($seed->getPathname()));
    }
}
@mkdir($base . '/var/cache', 0777, true);

// The DI wiring of Bootstrap::pullUpServices() that the dashboard path reads, plus the REAL AuthService.
DI::getInstance(true)
    ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
    ->set('FileFinder', fn($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
    ->set('ConfigManager', fn($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
    ->set('ModuleManager', fn($c) => new \Z77\Core\Services\ModuleManager($c->get('ConfigManager')), true)
    ->set('I18n', fn($c) => new \Z77\Core\Services\I18n($c->get('ConfigManager')), true)
    ->set('DataSourceResolver', fn() => new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
    ->set('UnifiedEntityManager', fn($c) => new \Z77\Persistence\Resolver\UnifiedEntityManager($c->get('DataSourceResolver')), true)
    ->set('ControllerHandler', fn($c) => new \Z77\Core\Controller\ControllerHandler($c->get('ModuleManager')), true)
    ->set('AuthService', fn($c) => new \Z77\Shared\Services\AuthService(new \Z77\Core\Session\SessionManager(), $c->get('ControllerHandler')), true);
DI::getCacheManager()->setCacheDir($base . '/var/cache');
$moduleManager = DI::getModuleManager();

$migrations = \Z77\Persistence\Doctrine\Console\MigrationsApplication::create(
    \Z77\Persistence\Doctrine\Bootstrap::buildEntityManager(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::collect($moduleManager, DI::getFileFinder()),
    DI::getCacheManager()->generatedPhp(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::missing($moduleManager, DI::getFileFinder())
);
$migrations->setAutoExit(false);
$out  = new \Symfony\Component\Console\Output\BufferedOutput();
$code = $migrations->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'migrate', '--no-interaction' => true]), $out);
check('S1 migrate exits 0' . ($code !== 0 ? ' — got ' . $code . ': ' . trim($out->fetch()) : ''), $code === 0);
check('S2 every module package is registered (' . count($modules) . '), financial, mandator and backend among them',
    $moduleManager->getModuleKeys() === array_keys($modules) && array_diff(['backend', 'financial', 'mandator'], $moduleManager->getModuleKeys()) === [],
    j($moduleManager->getModuleKeys()));
$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);
check('S3 the schema is a fresh install: no account, no fiscal year, no mandator',
    (int) $db->fetchOne('SELECT COUNT(*) FROM account') === 0
    && (int) $db->fetchOne('SELECT COUNT(*) FROM fiscal_year') === 0
    && (int) $db->fetchOne('SELECT COUNT(*) FROM mandator') === 0);

// ── R. registry and access ───────────────────────────────────────────────

echo "R. Registry over the real module configs, access through the real AuthService\n";

// What the module packages declare, read from their own config files — independent of the registry.
$declared = [];
foreach ($modules as $moduleKey => $packageDir) {
    $configFile = $packageDir . '/src/App/Config/' . $moduleKey . 'Config.inc.php';
    foreach (is_file($configFile) ? ((require $configFile)[ProjectChecks::CONFIG_KEY] ?? []) : [] as $class) {
        $declared[$class] = $moduleKey;
    }
}
$registry = ProjectChecks::fromModules($moduleManager);
$missing  = array_diff_key($declared, $registry->classes());
check('R1 every check a module package declares under `projectChecks` is registered (' . count($declared) . ')',
    $declared !== [] && $missing === [], j(array_keys($missing)));
check('R2 the chart (financial.chart) and the fiscal year (financial.fiscal-year) are among them',
    isset($registry->classes()[\Z77\Module\Financial\ProjectHealth\ChartOfAccountsCheck::class], $registry->classes()[\Z77\Module\Financial\ProjectHealth\FiscalYearCheck::class]));

$auth = DI::getAuthService();
// The first admin as both provisioning paths create him: SetupController (setup token) and the
// installer's admin prompt set the roles [superUser] (ADR-021).
$freshAdmin = new AuthUser(['id' => 1, 'user_name' => 'admin', 'roles' => [AuthRole::SUPER_USER], 'realm' => AuthUser::REALM_BACKEND]);
$plainAdmin = new AuthUser(['id' => 2, 'user_name' => 'admin2', 'roles' => [AuthRole::ADMIN], 'realm' => AuthUser::REALM_BACKEND]);
$editor     = new AuthUser(['id' => 3, 'user_name' => 'editor', 'roles' => [AuthRole::EDITOR], 'realm' => AuthUser::REALM_BACKEND]);

$rows        = [];
$unreachable = [];
$noScreen    = [];
$editorSees  = [];
$throws      = [];
foreach ($registry->checks() as $check) {
    $target = $check->target();
    [$module, $group, $controller, $action] = $target;
    $class  = Naming::toControllerClassName($moduleManager->getNamespacePrefix($module), Naming::toCamelCase($group), $controller);
    $screen = class_exists($class) && method_exists($class, Naming::toActionMethod($action));
    $reach  = $auth->canReach($freshAdmin, ...$target);
    try {
        $open = $check->isOpen() ? 'open' : 'done';
    } catch (\Throwable $e) {
        $open     = 'THROWS ' . $e->getMessage();
        $throws[] = $check->id();
    }
    if (!$reach || !$auth->canReach($plainAdmin, ...$target)) {
        $unreachable[] = $check->id();
    }
    if (!$screen) {
        $noScreen[] = $check->id() . ' → ' . $class . '::' . Naming::toActionMethod($action);
    }
    if ($auth->canReach($editor, ...$target)) {
        $editorSees[] = $check->id();
    }
    $rows[] = sprintf('    %-24s %-6s %-4s %-40s %-5s %s',
        $check->id(), $check->category()->value, $check->category() === CheckCategory::Setup ? (string) $check->order() : '-',
        implode('/', $target), $reach ? 'yes' : 'NO', $open);
}
echo sprintf("    %-24s %-6s %-4s %-40s %-5s %s\n", 'check', 'kind', 'slot', 'target', 'reach', 'empty schema'), implode("\n", $rows), "\n";
check('R3 every target is a real controller action (class and method exist)', $noScreen === [], j($noScreen));
check('R4 every target is reachable for the fresh admin [superUser] and for a plain admin (real canReach)', $unreachable === [], j($unreachable));
check('R5 no target is reachable for an editor (no setup step for an editor)', $editorSees === [], j($editorSees));
check('R6 no check fails on the fresh schema (no «nicht verfügbar» line)', $throws === [], j($throws));

// ── D. the dashboard lines ───────────────────────────────────────────────

echo "D. ProjectHealthDashboard::entries() for the fresh admin\n";

$entries = iterator_to_array((new ProjectHealthDashboard())->entries($freshAdmin), false);
$labels  = array_map(static fn(DashboardEntry $e) => $e->label, $entries);
$targets = array_map(static fn(DashboardEntry $e) => $e->route === null ? '' : implode('/', $e->route), $entries);
echo '    ' . implode("\n    ", array_map(static fn(string $l, string $t) => "{$l} → {$t}", $labels, $targets)) . "\n";
check('D1 the first line is «Kontenplan übernehmen» → the chart of accounts (nothing can be set up before the chart)',
    ($labels[0] ?? null) === 'Kontenplan übernehmen' && ($targets[0] ?? null) === 'backend/finance/account/list', j($labels));
check('D2 the first three setup steps: «Kontenplan übernehmen», «Mandant erfassen», «Geschäftsjahr eröffnen»',
    array_slice($labels, 0, 3) === ['Kontenplan übernehmen', 'Mandant erfassen', 'Geschäftsjahr eröffnen'], j($labels));
check('D3 the plain admin sees the same lines', array_map(
    static fn(DashboardEntry $e) => $e->label,
    iterator_to_array((new ProjectHealthDashboard())->entries($plainAdmin), false)
) === $labels);
check('D4 the editor sees no setup step', iterator_to_array((new ProjectHealthDashboard())->entries($editor), false) === []);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
