<?php

/**
 * Project checks harness (CLI) — project-health bauplan, «Ersteinrichtung» (first setup, owner
 * 2026-11-19). Targeted: the registry `projectChecks`, the bridge contributor
 * `ProjectHealthDashboard` and the setup checks of module-financial, module-mandator and
 * module-article.
 *
 * What is load-bearing here:
 *
 *   - (R, offline + DI) `ProjectChecks`: the `projectChecks` key of a module config AND an
 *     extension file `App/Config/projectChecksConfig.inc.php` are both collected; a bad entry
 *     (missing class, wrong interface, constructor arguments, not a list) fails naming the module;
 *     an id twice or off the pattern is refused naming the classes;
 *   - (C, offline) the CORE RULE of the bridge: setup steps in `order()` (not registration
 *     order), only the first N open ones, a step behind the limit is NOT asked, a done step
 *     disappears and the next moves up, a step whose target the user cannot reach is skipped
 *     without a slot and without being asked, a throwing check is ONE «nicht verfügbar» line in
 *     its slot, health checks are unlimited, `dashboardSetupSteps` (default 3, int ≥ 1);
 *   - (S, offline) the shipped registrations: ids, setup slots, targets that are real navigation
 *     pages, reachable for an admin and not for an editor over the REAL backendConfig;
 *   - (M, database) a fresh, migrated schema `z77test_*` shows the first steps «Kontenplan
 *     übernehmen», «Mandant erfassen», «Geschäftsjahr eröffnen»; after adopting the KMU chart the
 *     next step moves up; then every shipped check fires and goes silent on its own data; the
 *     old dashboard lines it replaced stay silent (no line twice).
 *
 * Run: php tests/project-health.php
 * R, C and S need the monorepo's vendor/; M needs a reachable MariaDB and the `z77test`
 * credentials (`%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*) — without them M is SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "project-health: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Z77\Core\Config\AuthRole;
use Z77\Module\Backend\Dashboard\ProjectHealthDashboard;
use Z77\Shared\Dashboard\DashboardEntry;
use Z77\Shared\Dashboard\Severity;
use Z77\Shared\Libraries\Convention\Naming;
use Z77\Shared\ProjectHealth\CheckCategory;
use Z77\Shared\ProjectHealth\ProjectCheck;
use Z77\Shared\ProjectHealth\ProjectChecks;
use Z77\Shared\ProjectHealth\SetupCheck;
use Z77\Shared\Services\AuthService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? " — got: {$got}" : '') . "\n"; }
}

/** True when $fn throws and the message contains every $needle. */
function throwsWith(callable $fn, string ...$needles): bool
{
    try {
        $fn();
    } catch (\Throwable $e) {
        foreach ($needles as $needle) {
            if (!str_contains($e->getMessage(), $needle)) {
                return false;
            }
        }
        return true;
    }
    return false;
}

/** @param list<DashboardEntry> $entries */
function labels(array $entries): array
{
    return array_map(static fn(DashboardEntry $e) => $e->label, $entries);
}

function j(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Checks of the harness (R, C). One static switchboard: which id is open, which ids were asked.
abstract class HarnessStep extends SetupCheck
{
    /** @var array<string, bool|string> id => open (true / false / 'throw') */
    public static array $open = [];
    /** @var list<string> */
    public static array $asked = [];

    public function id(): string { return static::ID; }
    public function order(): int { return static::ORDER; }
    public function target(): array { return ['backend', 'finance', static::CONTROLLER, 'list']; }
    public function label(): string { return 'Schritt ' . static::ID; }
    public function isOpen(): bool
    {
        self::$asked[] = static::ID;
        $open = self::$open[static::ID] ?? false;
        if ($open === 'throw') {
            throw new \RuntimeException('database gone');
        }
        return $open === true;
    }
}
final class StepChart extends HarnessStep { const ID = 'h.chart'; const ORDER = 10; const CONTROLLER = 'account'; }
final class StepMandator extends HarnessStep { const ID = 'h.mandator'; const ORDER = 20; const CONTROLLER = 'secret'; }
final class StepYear extends HarnessStep { const ID = 'h.year'; const ORDER = 30; const CONTROLLER = 'fiscal-year'; }
final class StepArticle extends HarnessStep { const ID = 'h.article'; const ORDER = 60; const CONTROLLER = 'article'; }
final class StepLogo extends HarnessStep { const ID = 'h.logo'; const ORDER = 110; const CONTROLLER = 'mandator'; }
final class StepLogoTwin extends HarnessStep { const ID = 'h.logo-twin'; const ORDER = 110; const CONTROLLER = 'mandator'; }
final class StepBadTarget extends HarnessStep
{
    const ID = 'h.bad-target'; const ORDER = 5; const CONTROLLER = 'x';
    public function target(): array { return ['backend', 'finance', 'x']; }
}
final class HarnessHealth implements ProjectCheck
{
    public static bool $open = true;
    public function id(): string { return 'h.health'; }
    public function category(): CheckCategory { return CheckCategory::Health; }
    public function order(): int { return 0; }
    public function target(): array { return ['backend', 'service', 'job', 'list']; }
    public function severity(): Severity { return Severity::Urgent; }
    public function label(): string { return 'Gesundheit offen'; }
    public function isOpen(): bool { HarnessStep::$asked[] = 'h.health'; return self::$open; }
}
final class HarnessHealthHidden implements ProjectCheck
{
    public function id(): string { return 'h.health-hidden'; }
    public function category(): CheckCategory { return CheckCategory::Health; }
    public function order(): int { return 0; }
    public function target(): array { return ['backend', 'finance', 'secret', 'list']; }
    public function severity(): Severity { return Severity::Todo; }
    public function label(): string { return 'nie sichtbar'; }
    public function isOpen(): bool { HarnessStep::$asked[] = 'h.health-hidden'; return true; }
}
final class StepSameIdAsChart extends HarnessStep { const ID = 'h.chart'; const ORDER = 99; const CONTROLLER = 'account'; }
final class StepBadId extends HarnessStep { const ID = 'Chart'; const ORDER = 1; const CONTROLLER = 'account'; }
final class StepNeedsArgs extends HarnessStep
{
    const ID = 'h.args'; const ORDER = 1; const CONTROLLER = 'account';
    public function __construct(string $x) {}
}
final class NotACheck {}

// ── R. the registry ──────────────────────────────────────────────────────

echo "R. ProjectChecks (offline + DI)\n";
$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-project-health-' . getmypid();
$rm = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (basename($f) === '.' || basename($f) === '..') { continue; }
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($dir);
};
register_shutdown_function(static function () use ($rm, $base): void { $rm($base); });
$write = function (string $rel, string $content) use ($base): void {
    $path = $base . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
};
if (!defined('ABS_BASE_PATH')) {
    define('ABS_BASE_PATH', $base);
}
if (!defined('DEBUG')) {
    define('DEBUG', false);
}
$write('demo-package/src/App/Config/demoConfig.inc.php', "<?php return ['projectChecks' => [\\StepYear::class, \\StepChart::class]];");
$write('demo-override/src/App/Config/projectChecksConfig.inc.php', "<?php return [\\StepArticle::class, \\StepChart::class];");
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n"
    . "'Z77\\\\Module\\\\Demo\\\\' => ['sourcePaths' => ['{$base}/demo-override', '{$base}/demo-package']],\n]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'demo', 'modules' => ['demo' => []]];");
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
@mkdir($base . '/var/cache', 0777, true);
\Z77\Core\DI::getInstance(true)
    ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
    ->set('FileFinder', fn($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
    ->set('ConfigManager', fn($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
    ->set('ModuleManager', fn($c) => new \Z77\Core\Services\ModuleManager($c->get('ConfigManager')), true);
\Z77\Core\DI::getCacheManager()->setCacheDir($base . '/var/cache');

$registry = ProjectChecks::fromModules(\Z77\Core\DI::getModuleManager());
check('R1 the module config key `projectChecks` AND the override extension file are collected, deduplicated, with their module',
    $registry->classes() === [StepYear::class => 'demo', StepChart::class => 'demo', StepArticle::class => 'demo'], j($registry->classes()));
check('R2 checks(): one instance per class, in registration order, built once',
    array_map(static fn(ProjectCheck $c) => $c->id(), $registry->checks()) === ['h.year', 'h.chart', 'h.article'] && $registry->checks()[0] === $registry->checks()[0]);
check('R3 bad entries fail loudly, naming the module: a missing class, a class without the interface, constructor arguments, not a list',
    throwsWith(fn() => new ProjectChecks(['financial' => ['NoSuchCheck']]), "module 'financial'", 'non-existent')
    && throwsWith(fn() => new ProjectChecks(['financial' => [NotACheck::class]]), "module 'financial'", 'does not implement')
    && throwsWith(fn() => new ProjectChecks(['financial' => [StepNeedsArgs::class]]), "module 'financial'", 'without arguments')
    && throwsWith(fn() => new ProjectChecks(['financial' => ['x' => StepChart::class]]), "module 'financial'", 'list'));
check('R4 an id taken twice is refused naming both classes; an id off the pattern `<module>.<what>` is refused',
    throwsWith(fn() => (new ProjectChecks(['a' => [StepChart::class], 'b' => [StepSameIdAsChart::class]]))->checks(), "'h.chart'", 'StepChart', 'StepSameIdAsChart')
    && throwsWith(fn() => (new ProjectChecks(['a' => [StepBadId::class]]))->checks(), "'Chart'", 'kebab-case'));
check('R5 SetupCheck: category Setup, weight todo (a setup step blocks nothing yet)',
    (new StepChart())->category() === CheckCategory::Setup && (new StepChart())->severity() === Severity::Todo);

// ── C. the core rule ─────────────────────────────────────────────────────

echo "C. ProjectHealthDashboard::entriesFrom (offline)\n";
$all       = static fn(string $m, string $g, string $c, string $a): bool => $c !== 'secret';
$everyone  = static fn(string $m, string $g, string $c, string $a): bool => true;
$run = static function (array $classes, callable $reachable, int $steps): array {
    HarnessStep::$asked = [];
    return ProjectHealthDashboard::entriesFrom(new ProjectChecks(['demo' => $classes]), $reachable, $steps);
};
$five = [StepLogo::class, StepArticle::class, StepChart::class, StepYear::class, StepMandator::class];   // registered out of order
HarnessStep::$open = ['h.chart' => true, 'h.mandator' => true, 'h.year' => true, 'h.article' => true, 'h.logo' => true];
$c1 = $run($five, $everyone, 3);
check('C1 all five open, limit 3: the FIRST THREE in setup order (not registration order), todo, each → its target',
    labels($c1) === ['Schritt h.chart', 'Schritt h.mandator', 'Schritt h.year'] && $c1[0]->severity === Severity::Todo
    && $c1[0]->href() === '/backend/finance/account/list', j(labels($c1)));
check('C2 a step behind the limit is NOT asked (cheap): only the three shown were asked, in order',
    HarnessStep::$asked === ['h.chart', 'h.mandator', 'h.year'], j(HarnessStep::$asked));
HarnessStep::$open['h.chart'] = false;
check('C3 the chart done → it disappears, the next step moves up: mandator, year, article',
    labels($run($five, $everyone, 3)) === ['Schritt h.mandator', 'Schritt h.year', 'Schritt h.article']);
check('C4 the limit is the number shown: 2 → two; 9 → every open step',
    labels($run($five, $everyone, 2)) === ['Schritt h.mandator', 'Schritt h.year']
    && labels($run($five, $everyone, 9)) === ['Schritt h.mandator', 'Schritt h.year', 'Schritt h.article', 'Schritt h.logo']);
$c5 = $run($five, $all, 3);
check('C5 canReach: a step whose target the user may not reach is skipped WITHOUT being asked and takes no slot — year, article, logo',
    labels($c5) === ['Schritt h.year', 'Schritt h.article', 'Schritt h.logo'] && !in_array('h.mandator', HarnessStep::$asked, true), j([labels($c5), HarnessStep::$asked]));
$logFile = $base . '/error.log';
ini_set('error_log', $logFile);
HarnessStep::$open['h.year'] = 'throw';
$c6 = $run($five, $everyone, 3);
$log = (string) @file_get_contents($logFile);
check('C6 a throwing check is ONE «Projekt-Prüfung «id» nicht verfügbar» line in its slot (no link), logged with id, class and reason',
    labels($c6) === ['Schritt h.mandator', 'Projekt-Prüfung «h.year» nicht verfügbar', 'Schritt h.article'] && !$c6[1]->hasTarget()
    && str_contains($log, 'h.year') && str_contains($log, 'StepYear') && str_contains($log, 'database gone'), j(labels($c6)));
HarnessStep::$open['h.year'] = true;
$c7 = $run([StepBadTarget::class, StepChart::class], $everyone, 3);
check('C7 a target that is not [module, group, controller, action] is a «nicht verfügbar» line, not a crash',
    labels($c7) === ['Projekt-Prüfung «h.bad-target» nicht verfügbar'], j(labels($c7)));
ini_restore('error_log');
HarnessStep::$open['h.logo-twin'] = true;
check('C8 equal order keeps registration order (stable)',
    labels($run([StepLogoTwin::class, StepLogo::class], $everyone, 3)) === ['Schritt h.logo-twin', 'Schritt h.logo']);
HarnessHealth::$open = true;
$c9 = $run([HarnessHealth::class, StepLogo::class, HarnessHealthHidden::class, StepArticle::class, StepYear::class, StepMandator::class], $all, 1);
check('C9 health checks: no limit, after the setup lines, every open one — an unreachable one is not asked; the setup limit 1 still applies to setup',
    labels($c9) === ['Schritt h.year', 'Gesundheit offen'] && $c9[1]->severity === Severity::Urgent && !in_array('h.health-hidden', HarnessStep::$asked, true), j(labels($c9)));
HarnessStep::$open = [];
HarnessHealth::$open = false;
check('C10 nothing open → no line at all (no «0 offen»)', $run($five, $everyone, 3) === [] && $run([HarnessHealth::class], $everyone, 3) === []);
check('C11 `dashboardSetupSteps`: absent → 3 (DEFAULT_SETUP_STEPS); 2 → 2; 0, -1, \'3\' refused naming the key',
    ProjectHealthDashboard::setupSteps(null) === 3 && ProjectHealthDashboard::DEFAULT_SETUP_STEPS === 3 && ProjectHealthDashboard::setupSteps(2) === 2
    && throwsWith(fn() => ProjectHealthDashboard::setupSteps(0), 'dashboardSetupSteps')
    && throwsWith(fn() => ProjectHealthDashboard::setupSteps(-1), 'dashboardSetupSteps')
    && throwsWith(fn() => ProjectHealthDashboard::setupSteps('3'), 'dashboardSetupSteps'));

// ── S. the shipped registrations ─────────────────────────────────────────

echo "S. Shipped checks (offline, real configs)\n";
$backendConfig = require $root . '/packages/module-backend/src/App/Config/backendConfig.inc.php';
$reachableAs = static fn(string $role): \Closure => static function (string $module, string $group, string $controller, string $action) use ($backendConfig, $role): bool {
    $action   = $action === '' ? 'list' : $action;
    $required = AuthService::requiredRole($backendConfig['moduleRole'], $backendConfig['controllers'], $group, Naming::toCamelCase($controller) . 'Controller', Naming::toActionMethod($action));
    return AuthRole::rolesSatisfy([$role], $required);
};
check('S1 backendConfig registers the bridge under `dashboard` and sets no `dashboardSetupSteps` (the default is the one place)',
    in_array(ProjectHealthDashboard::class, $backendConfig['dashboard'] ?? [], true) && !array_key_exists(ProjectHealthDashboard::CONFIG_SETUP_STEPS, $backendConfig));
$shipped = [];
foreach (['financial', 'mandator', 'article'] as $moduleKey) {
    $config = require $root . "/packages/module-{$moduleKey}/src/App/Config/{$moduleKey}Config.inc.php";
    $shipped[$moduleKey] = $config['projectChecks'] ?? [];
}
$shippedRegistry = new ProjectChecks($shipped);
$facts = array_map(static fn(ProjectCheck $c) => [$c->id(), $c->category()->value, $c->order()], $shippedRegistry->checks());
check('S2 the phase-A checks: ids, setup slots of the «Ersteinrichtung» table (10 chart, 20 mandator, 30 year, 60 article, 80/81 bank, 110 logo, 120 opening)',
    $facts === [
        ['financial.chart', 'setup', 10], ['financial.fiscal-year', 'setup', 30], ['financial.bank-ledger', 'setup', 80],
        ['financial.bank-mixed', 'setup', 81], ['financial.opening', 'setup', 120],
        ['mandator.accounts', 'setup', 20], ['mandator.logo', 'setup', 110],
        ['article.first', 'setup', 60],
    ], j($facts));
$seedRoutes = [];
foreach (glob($root . '/packages/*/data/framework/routing/navigation.d/*.json') as $seedFile) {
    foreach (json_decode((string) file_get_contents($seedFile), true) ?? [] as $row) {
        $seedRoutes[] = [$row['module'] ?? '', $row['group'] ?? '', $row['controller'] ?? '', $row['action'] ?? ''];
    }
}
$targets    = array_map(static fn(ProjectCheck $c) => $c->target(), $shippedRegistry->checks());
$notInSeeds = array_values(array_filter($targets, static fn(array $t) => !in_array($t, $seedRoutes, true)));
check('S3 every target is a page of a shipped navigation seed (the dashboard places it on that tile)', $notInSeeds === [], j($notInSeeds));
$admin  = $reachableAs(AuthRole::ADMIN);
$editor = $reachableAs(AuthRole::EDITOR);
check('S4 over the REAL backendConfig: an admin reaches every target, an editor none (no setup step for an editor)',
    array_filter($targets, static fn(array $t) => !$admin(...$t)) === [] && array_filter($targets, static fn(array $t) => $editor(...$t)) === []);

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
$dbName  = 'z77test_' . bin2hex(random_bytes(4));
$dbAdmin = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$dbAdmin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
register_shutdown_function(static function () use ($dbAdmin, $dbName): void {
    try { $dbAdmin->executeStatement("DROP DATABASE IF EXISTS `{$dbName}`"); } catch (\Throwable) {}
});

$packages = [
    'Vat'       => $root . '/packages/module-vat',
    'Contact'   => $root . '/packages/module-contact',
    'Mandator'  => $root . '/packages/module-mandator',
    'Debtor'    => $root . '/packages/module-debtor',
    'Article'   => $root . '/packages/module-article',
    'Order'     => $root . '/packages/module-order',
    'Financial' => $root . '/packages/module-financial',
];
$rm($base . '/var/cache');
@mkdir($base . '/var/cache', 0777, true);
$namespaces = "'Z77\\\\Shared\\\\' => ['sourcePaths' => ['{$root}/packages/kernel/shared']],\n";
foreach ($packages as $name => $path) {
    $namespaces .= "'Z77\\\\Module\\\\{$name}\\\\' => ['sourcePaths' => ['{$path}']],\n";
}
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$moduleList = "'vat' => [], 'contact' => [], 'mandator' => [], 'debtor' => [], 'article' => [], 'order' => [], 'financial' => []";
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'order', 'modules' => [{$moduleList}]];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
foreach (['Vat' => 'vat', 'Contact' => 'contact', 'Debtor' => 'debtor', 'Order' => 'order'] as $name => $dir) {
    @mkdir($base . '/data/framework/' . $dir, 0777, true);
    foreach (glob($packages[$name] . '/data/framework/' . $dir . '/*.default.json') as $seedPath) {
        copy($seedPath, $base . '/data/framework/' . $dir . '/' . str_replace('.default.json', '.json', basename($seedPath)));
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
$em = \Z77\Core\DI::getUnifiedEntityManager();

$app = \Z77\Persistence\Doctrine\Console\MigrationsApplication::create(
    \Z77\Persistence\Doctrine\Bootstrap::buildEntityManager(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::collect(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder()),
    \Z77\Core\DI::getCacheManager()->generatedPhp(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::missing(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder())
);
$app->setAutoExit(false);
$out  = new \Symfony\Component\Console\Output\BufferedOutput();
$code = $app->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'migrate', '--no-interaction' => true]), $out);
check('M1 migrate exits 0' . ($code !== 0 ? ' — got ' . $code . ': ' . trim($out->fetch()) : ''), $code === 0);

$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);
// The seeds below name only the columns a check reads; the harness's OWN connection is
// non-strict, so every other NOT NULL column takes its implicit default ('' / 0).
$db->executeStatement("SET SESSION sql_mode = ''");
/** Forgets every entity the Doctrine EntityManager holds — rows changed behind its back by SQL. */
$forget = static function () use ($em): void {
    $repo = $em->getRepository(\Z77\Module\Financial\Entities\Account::class);
    (new \ReflectionMethod($repo, 'em'))->invoke($repo)->clear();
};
/**
 * The checks of THIS harness's modules as the real module configs register them — only mandator,
 * article and financial (phase A): a check another module adds later (vat, debtor, receipt …, phase
 * B) is tested in that module's harness and must not move the steps asserted here.
 */
$phaseA = static function (): ProjectChecks {
    $byModule = [];
    foreach (ProjectChecks::fromModules(\Z77\Core\DI::getModuleManager())->classes() as $class => $moduleKey) {
        if (in_array($moduleKey, ['mandator', 'article', 'financial'], true)) {
            $byModule[$moduleKey][] = $class;
        }
    }
    return new ProjectChecks($byModule);
};
$modulesRegistry = $phaseA();
/** The bridge as the dashboard asks it, for a role (the real backendConfig decides reachability). */
$steps = static function (string $role = AuthRole::ADMIN, int $limit = 3) use ($forget, $reachableAs, $phaseA): array {
    $forget();
    return labels(ProjectHealthDashboard::entriesFrom($phaseA(), $reachableAs($role), $limit));
};
$admin = new \Z77\Shared\Auth\AuthUser(['id' => 1, 'user_name' => 'harness', 'roles' => [AuthRole::ADMIN]]);

check('M2 mandator, article and financial register their checks under `projectChecks` (module order)',
    array_keys($modulesRegistry->classes()) === [
        \Z77\Module\Mandator\ProjectHealth\MandatorAccountsCheck::class, \Z77\Module\Mandator\ProjectHealth\CompanyLogoCheck::class,
        \Z77\Module\Article\ProjectHealth\ArticleCheck::class, \Z77\Module\Article\ProjectHealth\ArticleTaxCodeCheck::class,
        \Z77\Module\Financial\ProjectHealth\ChartOfAccountsCheck::class, \Z77\Module\Financial\ProjectHealth\FiscalYearCheck::class,
        \Z77\Module\Financial\ProjectHealth\BankLedgerAccountCheck::class, \Z77\Module\Financial\ProjectHealth\MixedBankAccountsCheck::class,
        \Z77\Module\Financial\ProjectHealth\OpeningBalanceCheck::class,
    ], j(array_keys($modulesRegistry->classes())));

$fresh = $steps();
check('M3 a FRESH empty installation: the first three setup steps — «Kontenplan übernehmen», «Mandant erfassen», «Geschäftsjahr eröffnen»',
    $fresh === ['Kontenplan übernehmen', 'Mandant erfassen', 'Geschäftsjahr eröffnen'], j($fresh));
check('M4 … and the old dashboard lines they replaced say nothing (no line twice): FinancialDashboard, MandatorDashboard',
    \Z77\Module\Financial\Dashboard\FinancialDashboard::entriesOn(new \DateTimeImmutable('today')) === []
    && iterator_to_array((new \Z77\Module\Mandator\Dashboard\MandatorDashboard())->entries($admin), false) === []);
check('M5 an editor sees no setup step (every target is ADMIN)', $steps(AuthRole::EDITOR) === []);
check('M6 `dashboardSetupSteps` 2 → only the first two', $steps(AuthRole::ADMIN, 2) === ['Kontenplan übernehmen', 'Mandant erfassen']);

$adopted = (new \Z77\Module\Financial\Services\AccountService($em))->adoptKmuChart();
$afterChart = $steps();
check('M7 the KMU chart adopted → «Kontenplan übernehmen» disappears, «Artikel erfassen» moves up',
    $adopted > 100 && $afterChart === ['Mandant erfassen', 'Geschäftsjahr eröffnen', 'Artikel erfassen'], j([$adopted, $afterChart]));

// The mandator with the KMU start values — but «Skonto» empty.
$accounts = [];
foreach (\Z77\Module\Mandator\Services\MandatorAccounts::DEFAULTS as $key => $number) {
    $accounts['account_' . str_replace('-', '_', $key)] = $number;
}
$db->insert('mandator', ['id' => 1, 'name' => 'Muster GmbH', 'country' => 'CH', 'uid' => '', 'liable_to_vat' => 0] + array_merge($accounts, ['account_discount' => '']));
check('M8 mandator saved, one start-value account empty → «Mandant: Konten zuweisen» (same slot)',
    $steps()[0] === 'Mandant: Konten zuweisen', j($steps()));
$db->executeStatement("UPDATE mandator SET account_discount = '9999' WHERE id = 1");
check('M9 … an account the chart does not have → still «Mandant: Konten zuweisen»', $steps()[0] === 'Mandant: Konten zuweisen');
$db->executeStatement("UPDATE mandator SET account_discount = '3800' WHERE id = 1");
$afterMandator = $steps();
check('M10 every start-value account postable → the step is done; private / bank-transfer (no start value) are not asked here; the logo step moves up',
    $afterMandator === ['Geschäftsjahr eröffnen', 'Artikel erfassen', 'Firmenlogo für Belege hinterlegen'], j($afterMandator));
// vat.accounts (phase B1, module-vat) on its REAL path: financial's vatAccountFor() / accountExists().
$vatOpen = static function () use ($forget): bool {
    $forget();
    return (new \Z77\Module\Vat\ProjectHealth\VatAccountsCheck())->isOpen();
};
// The step only matters while the mandator is liable to VAT (MANDATOR-VAT-001, 2026-11-20): liable for the probe, back after.
$db->executeStatement('UPDATE mandator SET liable_to_vat = 1 WHERE id = 1');
$vatSilent = !$vatOpen();
$db->executeStatement("UPDATE mandator SET account_vat_owed = '9998' WHERE id = 1");
$vatWrong = $vatOpen();
$db->executeStatement("UPDATE mandator SET account_vat_owed = '' WHERE id = 1");
$vatEmpty = $vatOpen();
$db->executeStatement('UPDATE mandator SET liable_to_vat = 0 WHERE id = 1');
$vatNotLiable = !$vatOpen();
$db->executeStatement("UPDATE mandator SET account_vat_owed = '2200' WHERE id = 1");
check('M10b vat.accounts over the real bookkeeping (liable): the KMU accounts on the mandator → silent; «Geschuldete MWST» 9998 (not in the chart) or empty → open; NOT liable → silent although empty; 2200 again → silent',
    $vatSilent && $vatWrong && $vatEmpty && $vatNotLiable && !$vatOpen(), j([$vatSilent, $vatWrong, $vatEmpty, $vatNotLiable]));

$today = new \DateTimeImmutable('today');
$thisYear = (int) $today->format('Y');
$db->insert('fiscal_year', ['code' => (string) $thisYear, 'start_date' => $thisYear . '-01-01', 'end_date' => $thisYear . '-12-31']);
$yearId = (int) $db->lastInsertId();
$db->insert('fiscal_period', ['start_date' => $thisYear . '-01-01', 'end_date' => $thisYear . '-12-31', 'state' => 'open', 'fiscal_year_id' => $yearId]);
check('M11 a fiscal year exists → «Geschäftsjahr eröffnen» done (no predecessor: no opening step)',
    $steps() === ['Artikel erfassen', 'Firmenlogo für Belege hinterlegen'], j($steps()));

$db->insert('article_product_group', ['name' => 'Dienstleistungen', 'sort_key' => 1]);
$groupId = (int) $db->lastInsertId();
$db->insert('article_product', ['name' => 'Beratung', 'product_group_id' => $groupId, 'active' => 0, 'created_by' => 'harness', 'created_at' => $today->format('Y-m-d H:i:s'), 'version' => 1]);
$productId = (int) $db->lastInsertId();
check('M12 only an INACTIVE article → «Artikel erfassen» stays', in_array('Artikel erfassen', $steps(), true));
$db->executeStatement('UPDATE article_product SET active = 1 WHERE id = ?', [$productId]);
check('M13 an active article → done', $steps() === ['Firmenlogo für Belege hinterlegen'], j($steps()));
// article.tax-code (slot 61, owner 2026-11-20): the article has no tax code; silent above because the mandator is NOT liable.
$db->executeStatement('UPDATE mandator SET liable_to_vat = 1 WHERE id = 1');
$liableSteps = $steps();
$db->executeStatement("UPDATE article_product SET tax_code = 'UN' WHERE id = ?", [$productId]);
$codedSteps = $steps();
$db->executeStatement('UPDATE mandator SET liable_to_vat = 0 WHERE id = 1');
check('M13b liable to VAT + an active article without a tax code → «Artikel ohne MWST-Code» (before the logo); with a code → done',
    $liableSteps === ['Artikel ohne MWST-Code', 'Firmenlogo für Belege hinterlegen'] && $codedSteps === ['Firmenlogo für Belege hinterlegen'], j([$liableSteps, $codedSteps]));

$write('assets/logo.png', 'png');
$db->executeStatement("UPDATE mandator SET logo_path = 'assets/missing.png' WHERE id = 1");
check('M14 a logo path whose file is missing → still «Firmenlogo für Belege hinterlegen»', $steps() === ['Firmenlogo für Belege hinterlegen']);
$db->executeStatement("UPDATE mandator SET logo_path = 'assets/logo.png' WHERE id = 1");
check('M15 the print logo found → «Firmenlogo für E-Mails und Anmeldung hinterlegen»', $steps() === ['Firmenlogo für E-Mails und Anmeldung hinterlegen'], j($steps()));
$db->executeStatement("UPDATE mandator SET email_logo_path = 'assets/logo.png' WHERE id = 1");
check('M16 both logos found → nothing open', $steps() === [], j($steps()));

$db->insert('bank_account', ['code' => 'hausbank', 'label' => 'Hausbank', 'iban' => 'CH9300762011623852957', 'currency' => 'CHF', 'ledger_account' => '9999', 'statement_import' => 0, 'mixed_use' => 0, 'active' => 1]);
$bankId = (int) $db->lastInsertId();
check('M17 an active bank account whose ledger account is not in the chart → «Bankkonto «Hausbank»: Konto im Kontenplan fehlt» → Bankkonten',
    $steps() === ['Bankkonto «Hausbank»: Konto im Kontenplan fehlt'], j($steps()));
$db->executeStatement("UPDATE bank_account SET ledger_account = '1020' WHERE id = ?", [$bankId]);
check('M18 ledger account 1020 → done', $steps() === []);
$db->executeStatement('UPDATE bank_account SET mixed_use = 1 WHERE id = ?', [$bankId]);
check('M19 the account «gemischt geführt», mandator private / bank-transfer empty → «Mandant: Konten Privat und Übertrag zuweisen»',
    $steps() === ['Mandant: Konten Privat und Übertrag zuweisen'], j($steps()));
$two = $db->fetchFirstColumn("SELECT number FROM account WHERE postable = 1 AND active = 1 AND number NOT IN ('1020') ORDER BY number LIMIT 2");
$db->executeStatement('UPDATE mandator SET account_private = ?, account_bank_transfer = ? WHERE id = 1', [$two[0], $two[1]]);
check('M20 both accounts set and postable → done', $steps() === [], j($steps()));

// The opening: a CLOSED predecessor, no opening in the year of today.
$prev = $thisYear - 1;
$db->insert('fiscal_year', ['code' => (string) $prev, 'start_date' => $prev . '-01-01', 'end_date' => $prev . '-12-31']);
$prevId = (int) $db->lastInsertId();
$db->insert('fiscal_period', ['start_date' => $prev . '-01-01', 'end_date' => $prev . '-12-31', 'state' => 'open', 'fiscal_year_id' => $prevId]);
check('M21 a predecessor that is still OPEN → no opening step (a provisional opening is the bookkeeper\'s call, P5)', $steps() === [], j($steps()));
$db->executeStatement("UPDATE fiscal_period SET state = 'closed' WHERE fiscal_year_id = ?", [$prevId]);
check('M22 the predecessor CLOSED and no opening → «Eröffnung ' . $thisYear . ' nicht erstellt» → Geschäftsjahre',
    $steps() === ['Eröffnung ' . $thisYear . ' nicht erstellt'], j($steps()));
$bridge = ProjectHealthDashboard::entriesFrom($phaseA(), $reachableAs(AuthRole::ADMIN), 3);
check('M23 … its target is the fiscal-year list, todo', ($bridge[0] ?? null)?->href() === '/backend/finance/fiscal-year/list' && $bridge[0]->severity === Severity::Todo);
$db->executeStatement('UPDATE mandator SET logo_path = \'\' WHERE id = 1');
check('M24 a step done earlier and undone again comes back in its place (derived, never stored): logo (110) before opening (120)',
    $steps() === ['Firmenlogo für Belege hinterlegen', 'Eröffnung ' . $thisYear . ' nicht erstellt'], j($steps()));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
