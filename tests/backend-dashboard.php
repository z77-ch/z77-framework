<?php

/**
 * Backend dashboard harness (CLI) — ADR-052. Targeted and fast: only what the dashboard needs.
 *
 * What is load-bearing here:
 *
 *   - (E, offline) `DashboardEntry`: a pending entry needs a target, a route is
 *     [module, group, controller, action], the href carries the query; `Severity` has two
 *     weights only (owner 2026-10-22), urgent ranks first;
 *   - (R, offline + DI) `DashboardContributors`: the `dashboard` key of a module config AND an
 *     extension file `App/Config/dashboardConfig.inc.php` in an override path are both
 *     collected; a bad entry (missing class, wrong interface, constructor arguments, not a
 *     list) fails loudly naming the module; a contributor that throws or yields garbage is
 *     replaced by ONE «… nicht verfügbar» line and the others' entries stand;
 *   - (B, offline) `DashboardBoard::build()`: every entry is filtered by the reachability of
 *     its target — over the REAL backendConfig, an editor keeps no debtor number —, placed on
 *     the tile whose section holds its route (exact route, else the same controller), a
 *     create entry stands in the tile head, an unplaced pending entry still appears in the
 *     pending list, urgent before todo;
 *   - (S, offline) the service contributor: backup age against `maxAgeDays` (default 1), the
 *     failed job runs of the last 7 days;
 *   - (M, database) every module contributor's count against seeded rows in a throwaway
 *     schema `z77test_*`: debtor (overdue count + amount, dunning proposal, unbooked bank
 *     transactions, drafts, payment target IBAN), order (per bookable phase, quotes, the
 *     create entry), contact (create entry), financial (no open year today / next year not
 *     opened in the last month), mandator (none saved, liable to VAT without UID).
 *
 * Run: php tests/backend-dashboard.php
 * E, R, B and S need the monorepo's vendor/; M needs a reachable MariaDB and the `z77test`
 * credentials (`%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*) — without them M is SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "backend-dashboard: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(1);
}
require $autoload;

use Z77\Core\Config\AuthRole;
use Z77\Module\Backend\Dashboard\ServiceDashboard;
use Z77\Module\Backend\Ui\DashboardBoard;
use Z77\Module\Financial\Dashboard\FinancialDashboard;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Backup\BackupService;
use Z77\Shared\Dashboard\DashboardContributor;
use Z77\Shared\Dashboard\DashboardContributors;
use Z77\Shared\Dashboard\DashboardEntry;
use Z77\Shared\Dashboard\Severity;
use Z77\Shared\Entities\JobRun;
use Z77\Shared\Libraries\Convention\Naming;
use Z77\Shared\Money\Money;
use Z77\Shared\Services\AuthService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
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

// Contributors of the harness (R).
final class HarnessAlpha implements DashboardContributor
{
    public function entries(AuthUser $user): iterable
    {
        yield DashboardEntry::pending(Severity::Todo, 'Alpha offen', ['backend', 'content', 'content', 'list'], [], 2);
    }
}
final class HarnessBeta implements DashboardContributor
{
    public function entries(AuthUser $user): iterable
    {
        yield DashboardEntry::pending(Severity::Urgent, 'Beta dringend', ['backend', 'finance', 'debtor', 'list']);
    }
}
final class HarnessThrows implements DashboardContributor
{
    public function entries(AuthUser $user): iterable
    {
        yield DashboardEntry::pending(Severity::Todo, 'never shown', ['backend', 'content', 'content', 'list']);
        throw new \RuntimeException('database gone');
    }
}
final class HarnessGarbage implements DashboardContributor
{
    public function entries(AuthUser $user): iterable
    {
        yield 'not an entry';
    }
}
final class HarnessNeedsArgs implements DashboardContributor
{
    public function __construct(string $x) {}
    public function entries(AuthUser $user): iterable { return []; }
}
final class HarnessNotAContributor {}

$admin  = new AuthUser(['id' => 1, 'user_name' => 'harness', 'roles' => [AuthRole::ADMIN]]);

// ── E. the entry ─────────────────────────────────────────────────────────

echo "E. DashboardEntry / Severity (offline)\n";
$overdue = DashboardEntry::pending(Severity::Urgent, 'Rechnungen überfällig', ['backend', 'finance', 'debtor', 'list'], ['view' => 'overdue'], 4, Money::fromDecimal('3870.00', 'CHF'));
check('E1 a pending entry carries kind, weight, label, count, amount; the href is the 4-segment path with the query',
    $overdue->isPending() && $overdue->severity === Severity::Urgent && $overdue->count === 4 && $overdue->amount->toDecimal() === '3870.00'
    && $overdue->href() === '/backend/finance/debtor/list?view=overdue');
$create = DashboardEntry::create('Neuer Auftrag', ['backend', 'order', 'order', 'add'], DashboardEntry::OPEN_WINDOW);
check('E2 a create entry has no weight, opens as told; an empty action leaves the default to the route',
    !$create->isPending() && $create->severity === null && $create->opens === 'window'
    && DashboardEntry::pending(Severity::Todo, 'x', ['backend', 'finance', 'mandator', ''])->href() === '/backend/finance/mandator');
check('E3 refused: an empty label, a pending entry without target, a route of 3 segments, an empty controller, an unknown opening',
    throwsWith(fn() => DashboardEntry::pending(Severity::Todo, ' ', ['a', 'b', 'c', 'd']), 'label')
    && throwsWith(fn() => DashboardEntry::pending(Severity::Todo, 'x', []), 'target')
    && throwsWith(fn() => DashboardEntry::pending(Severity::Todo, 'x', ['a', 'b', 'c']), 'route')
    && throwsWith(fn() => DashboardEntry::pending(Severity::Todo, 'x', ['a', 'b', '', 'd']), 'route')
    && throwsWith(fn() => DashboardEntry::create('x', ['a', 'b', 'c', 'add'], 'popup'), 'opening'));
check('E4 two weights only (owner 2026-10-22), German labels, urgent ranks before todo',
    Severity::cases() === [Severity::Urgent, Severity::Todo] && Severity::Urgent->label() === 'dringend' && Severity::Todo->label() === 'zu erledigen'
    && Severity::Urgent->rank() < Severity::Todo->rank());
$unavailable = DashboardEntry::unavailable('Pendenzen aus Modul «x» nicht verfügbar');
check('E5 the unavailable line is a todo without target (no tile, no link)', $unavailable->isPending() && !$unavailable->hasTarget() && $unavailable->href() === '');

// ── R. the registry ──────────────────────────────────────────────────────

echo "R. DashboardContributors (offline + DI)\n";
$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-backend-dashboard-' . getmypid();
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
$write('demo-package/src/App/Config/demoConfig.inc.php', "<?php return ['dashboard' => [\\HarnessAlpha::class]];");
$write('demo-override/src/App/Config/dashboardConfig.inc.php', "<?php return [\\HarnessBeta::class, \\HarnessAlpha::class];");
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

$registry = DashboardContributors::fromModules(\Z77\Core\DI::getModuleManager());
check('R1 the module config key `dashboard` AND the override extension file are collected, deduplicated, with their module',
    $registry->contributors() === [HarnessAlpha::class => 'demo', HarnessBeta::class => 'demo'], json_encode($registry->contributors()));
check('R2 bad entries fail loudly, naming the module: a missing class, a class without the interface, constructor arguments, not a list',
    throwsWith(fn() => new DashboardContributors(['debtor' => ['NoSuchDashboard']]), "module 'debtor'", 'non-existent')
    && throwsWith(fn() => new DashboardContributors(['debtor' => [HarnessNotAContributor::class]]), "module 'debtor'", 'does not implement')
    && throwsWith(fn() => new DashboardContributors(['debtor' => [HarnessNeedsArgs::class]]), "module 'debtor'", 'without arguments')
    && throwsWith(fn() => new DashboardContributors(['debtor' => ['x' => HarnessAlpha::class]]), "module 'debtor'", 'list'));
$logFile = $base . '/error.log';
ini_set('error_log', $logFile);
$mixed = (new DashboardContributors(['a' => [HarnessAlpha::class], 'broken' => [HarnessThrows::class], 'junk' => [HarnessGarbage::class], 'b' => [HarnessBeta::class]]))->entriesFor($admin);
check('R3 a contributor that throws (or yields garbage) becomes ONE «… nicht verfügbar» line, nothing it yielded before stays; the others stand, in order',
    array_map(static fn(DashboardEntry $e) => $e->label, $mixed) === ['Alpha offen', 'Pendenzen aus Modul «broken» nicht verfügbar', 'Pendenzen aus Modul «junk» nicht verfügbar', 'Beta dringend'],
    json_encode(array_map(static fn(DashboardEntry $e) => $e->label, $mixed), JSON_UNESCAPED_UNICODE));
$log = (string) @file_get_contents($logFile);
ini_restore('error_log');
check('R4 the failure is logged with class, module and reason', str_contains($log, 'HarnessThrows') && str_contains($log, "module 'broken'") && str_contains($log, 'database gone'));

// ── B. the board ─────────────────────────────────────────────────────────

echo "B. DashboardBoard::build (offline, real backendConfig)\n";
$config    = require $root . '/packages/module-backend/src/App/Config/backendConfig.inc.php';
$reachable = static fn(string $role): \Closure => static function (string $module, string $group, string $controller, string $action) use ($config, $role): bool {
    $action   = $action === '' ? 'list' : $action;
    $required = AuthService::requiredRole($config['moduleRole'], $config['controllers'], $group, Naming::toCamelCase($controller) . 'Controller', Naming::toActionMethod($action));
    return AuthRole::rolesSatisfy([$role], $required);
};
$route = static fn(string $g, string $c, string $a, string $name): array => ['route' => ['backend', $g, $c, $a], 'name' => $name];
// The tiles as BackendMenu would give them for an admin (the shipped navigation, shortened).
$tiles = [
    ['name' => 'Webseiten', 'url' => '/backend/content/content/list', 'links' => [['name' => 'Inhalte', 'href' => '/backend/content/content/list']],
     'routes' => [$route('content', 'content', 'list', 'Inhalte'), $route('content', 'navigation', 'list', 'Navigation')]],
    ['name' => 'Aufträge', 'url' => '/backend/order/order/list', 'links' => [['name' => 'Aufträge', 'href' => '/backend/order/order/list'], ['name' => 'Debitoren', 'href' => '/backend/finance/debtor/list']],
     'routes' => [$route('finance', 'debtor', 'list', 'Debitoren'), $route('finance', 'invoice', 'list', 'Rechnungen'), $route('order', 'order', 'list', 'Aufträge')]],
    ['name' => 'Stammdaten', 'url' => '/backend/system/backend-user/list', 'links' => [['name' => 'Geschäftsjahre', 'href' => '/backend/finance/fiscal-year/list']],
     'routes' => [$route('finance', 'fiscal-year', 'list', 'Geschäftsjahre'), $route('finance', 'mandator', 'edit', 'Mandant')]],
    ['name' => 'Service', 'url' => '/backend/service/backup/list', 'links' => [['name' => 'Backup', 'href' => '/backend/service/backup/list']],
     'routes' => [$route('service', 'backup', 'list', 'Backup'), $route('service', 'job', 'list', 'Jobs')]],
];
$entries = [
    DashboardEntry::pending(Severity::Todo, 'Offerten nachfassen', ['backend', 'order', 'order', 'list'], ['view' => 'new'], 7),
    $overdue,
    DashboardEntry::pending(Severity::Urgent, 'Kein offenes Geschäftsjahr für heute', ['backend', 'finance', 'fiscal-year', 'list']),
    DashboardEntry::create('Neuer Auftrag', ['backend', 'order', 'order', 'add'], DashboardEntry::OPEN_WINDOW),
    DashboardEntry::create('Neues Irgendwas', ['backend', 'nowhere', 'thing', 'add']),
    DashboardEntry::pending(Severity::Todo, 'Letztes Backup vor 3 Tagen', ['backend', 'service', 'backup', 'list']),
    DashboardEntry::pending(Severity::Todo, 'Entwürfe Webseite', ['backend', 'content', 'content', 'list'], [], 1),
    DashboardEntry::pending(Severity::Todo, 'Projekt-Pendenz ohne Kachel', ['backend', 'content', 'translation', 'list'], [], 3),
    $unavailable,
];
$board = DashboardBoard::build($tiles, $entries, $reachable(AuthRole::SUPER_USER));
$byName = array_column($board['tiles'], null, 'name');
check('B1 placement by ROUTE: overdue (debtor list) and quotes (order list) on «Aufträge», the fiscal year on «Stammdaten» (where the menu hangs it), the backup on «Service»',
    array_column($byName['Aufträge']['badges'], 'label') === ['Rechnungen überfällig', 'Offerten nachfassen']
    && array_column($byName['Stammdaten']['badges'], 'label') === ['Kein offenes Geschäftsjahr für heute']
    && array_column($byName['Service']['badges'], 'label') === ['Letztes Backup vor 3 Tagen']);
check('B2 a create entry stands in the head of the tile holding its controller (the add action is not in the menu); one without a tile is dropped',
    $byName['Aufträge']['creates'] === [['label' => 'Neuer Auftrag', 'href' => '/backend/order/order/add', 'opens' => 'window']]
    && array_merge(...array_column($board['tiles'], 'creates')) === $byName['Aufträge']['creates']);
$pendingLabels = array_column($board['pending'], 'label');
check('B3 the pending list: every pending entry, urgent first, registration order within a weight; an unplaced entry and the unavailable line included',
    $pendingLabels === ['Rechnungen überfällig', 'Kein offenes Geschäftsjahr für heute', 'Offerten nachfassen', 'Letztes Backup vor 3 Tagen', 'Entwürfe Webseite', 'Projekt-Pendenz ohne Kachel', 'Pendenzen aus Modul «x» nicht verfügbar'],
    json_encode($pendingLabels, JSON_UNESCAPED_UNICODE));
$row = $board['pending'][0];
check('B4 a row: weight, «where» = tile › menu page, figure «count · currency amount», the filtered href',
    $row['severity'] === 'urgent' && $row['severityLabel'] === 'dringend' && $row['where'] === 'Aufträge › Debitoren'
    && $row['figure'] === "4 · CHF 3'870.00" && $row['href'] === '/backend/finance/debtor/list?view=overdue', json_encode($row, JSON_UNESCAPED_UNICODE));
$unplaced = $board['pending'][5];
check('B5 an unplaced entry has no «where»; the unavailable line has no link; a page named like its tile is not repeated',
    $unplaced['where'] === '' && end($board['pending'])['href'] === '' && $board['pending'][2]['where'] === 'Aufträge');
// The editor: BackendMenu gives him only the Webseiten tile (tests/backend-access.php); the
// entries are filtered by the SAME reachability here.
$editorBoard = DashboardBoard::build([$tiles[0]], $entries, $reachable(AuthRole::EDITOR));
check('B6 an editor: only «Webseiten», no debtor / order / backup / fiscal-year number anywhere, no create button — only the content entry and the unavailable line',
    array_column($editorBoard['tiles'], 'name') === ['Webseiten'] && $editorBoard['tiles'][0]['creates'] === []
    && array_column($editorBoard['pending'], 'label') === ['Entwürfe Webseite', 'Pendenzen aus Modul «x» nicht verfügbar'],
    json_encode(array_column($editorBoard['pending'], 'label'), JSON_UNESCAPED_UNICODE));
$adminBoard = DashboardBoard::build($tiles, $entries, $reachable(AuthRole::ADMIN));
check('B7 an admin does not see the backup entry (the backup screen is SUPER_USER) but sees the rest',
    !in_array('Letztes Backup vor 3 Tagen', array_column($adminBoard['pending'], 'label'), true)
    && in_array('Rechnungen überfällig', array_column($adminBoard['pending'], 'label'), true));
$emptyBoard = DashboardBoard::build($tiles, [], $reachable(AuthRole::ADMIN));
check('B8 no entries: tiles without badges, an empty pending list (the template says «Nichts offen»)',
    $emptyBoard['pending'] === [] && array_merge(...array_column($emptyBoard['tiles'], 'badges')) === [] && count($emptyBoard['tiles']) === 4);

// ── S. the service contributor ───────────────────────────────────────────

echo "S. ServiceDashboard (offline)\n";
$now = new \DateTimeImmutable('2026-10-22 12:00:00');
$backupRoot = $base . '/project';
@mkdir($backupRoot . '/backup/data', 0777, true);
$labels = static fn(array $entries): array => array_map(static fn(DashboardEntry $e) => $e->label, $entries);
check('S1 no backup at all → todo «Noch kein Backup» → Backup', $labels(ServiceDashboard::backupEntries(new BackupService($backupRoot), $now)) === ['Noch kein Backup']);
$zip = $backupRoot . '/backup/data/2026-10-19_120000_data.zip';
file_put_contents($zip, 'x');
touch($zip, $now->modify('-3 days')->getTimestamp());
$s2 = ServiceDashboard::backupEntries(new BackupService($backupRoot), $now);
check('S2 the newest backup is 3 days old, default threshold 1 day (owner 2026-10-22) → «Letztes Backup vor 3 Tagen», todo, → /backend/service/backup/list',
    $labels($s2) === ['Letztes Backup vor 3 Tagen'] && $s2[0]->severity === Severity::Todo && $s2[0]->href() === '/backend/service/backup/list'
    && (new BackupService($backupRoot))->maxAgeDays() === 1);
check('S3 the threshold is the policy key `maxAgeDays` (one place): 5 days → nothing; 0 is read as 1',
    ServiceDashboard::backupEntries(new BackupService($backupRoot, ['maxAgeDays' => 5]), $now) === []
    && (new BackupService($backupRoot, ['maxAgeDays' => 0]))->maxAgeDays() === 1);
$fresh = $backupRoot . '/backup/data/2026-10-22_080000_data.zip';
file_put_contents($fresh, 'x');
touch($fresh, $now->modify('-4 hours')->getTimestamp());
check('S4 a backup of today (another file, newer) → nothing; «1 Tag» in the singular', ServiceDashboard::backupEntries(new BackupService($backupRoot), $now) === []
    && (function () use ($backupRoot, $fresh, $now, $labels): bool {
        touch($fresh, $now->modify('-30 hours')->getTimestamp());
        return $labels(ServiceDashboard::backupEntries(new BackupService($backupRoot), $now)) === ['Letztes Backup vor 1 Tag'];
    })());
$run = static function (string $state, ?string $finished): JobRun {
    $r = new JobRun();
    $r->setState($state);
    $r->setFinishedAt($finished);
    return $r;
};
$jobs = ServiceDashboard::jobEntries([
    $run(JobRun::STATE_FAILED, $now->modify('-2 days')->format(DATE_ATOM)),
    $run(JobRun::STATE_FAILED, $now->modify('-6 days')->format(DATE_ATOM)),
    $run(JobRun::STATE_FAILED, $now->modify('-8 days')->format(DATE_ATOM)),
    $run(JobRun::STATE_DONE, $now->modify('-1 day')->format(DATE_ATOM)),
    $run(JobRun::STATE_QUEUED, null),
], $now);
check('S5 failed runs of the last 7 days count (2 of 3 failed; done / queued not) → urgent → Jobs',
    count($jobs) === 1 && $jobs[0]->count === 2 && $jobs[0]->severity === Severity::Urgent && $jobs[0]->href() === '/backend/service/job/list'
    && ServiceDashboard::jobEntries([$run(JobRun::STATE_DONE, $now->format(DATE_ATOM))], $now) === []);
check('S6 backendConfig registers the service contributor under `dashboard`', ($config['dashboard'] ?? null) === [ServiceDashboard::class]);

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
    echo "\n{$pass} passed, {$fail} failed (M skipped)\n";
    exit($fail === 0 ? 0 : 1);
}

echo "M. Database (throwaway schema)\n";
$dbName = 'z77test_' . bin2hex(random_bytes(4));
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
// The seeds below name only the columns a count reads; the harness's OWN connection is
// non-strict, so every other NOT NULL column takes its implicit default ('' / 0).
$db->executeStatement("SET SESSION sql_mode = ''");
/** Forgets every entity the Doctrine EntityManager holds — rows changed behind its back by SQL. */
$forget = static function () use ($em): void {
    $repo = $em->getRepository(\Z77\Module\Debtor\Entities\Invoice::class);
    (new \ReflectionMethod($repo, 'em'))->invoke($repo)->clear();
};
$labelsOf = static fn(iterable $entries): array => array_map(static fn(DashboardEntry $e) => $e->label, is_array($entries) ? $entries : iterator_to_array($entries, false));
$byLabel  = static function (iterable $entries): array {
    $out = [];
    foreach ($entries as $e) { $out[$e->label] = $e; }
    return $out;
};

$registry = DashboardContributors::fromModules(\Z77\Core\DI::getModuleManager());
check('M2 the real modules register their contributors under `dashboard`',
    array_keys($registry->contributors()) === [
        \Z77\Module\Contact\Dashboard\ContactDashboard::class, \Z77\Module\Mandator\Dashboard\MandatorDashboard::class,
        \Z77\Module\Debtor\Dashboard\DebtorDashboard::class, \Z77\Module\Order\Dashboard\OrderDashboard::class,
        \Z77\Module\Financial\Dashboard\FinancialDashboard::class,
    ], json_encode(array_keys($registry->contributors())));

// Contact + debtor profile (the one creation path).
$db->insert('contact', ['kind' => 'organisation', 'company' => 'Muster AG', 'first_name' => '', 'last_name' => '', 'language' => 'de', 'email' => '', 'phone' => '', 'active' => 1]);
$contactId = (int) $db->lastInsertId();
(new \Z77\Module\Debtor\Services\DebtorProfileService($em))->open($em->getRepository(\Z77\Module\Contact\Entities\Contact::class)->find($contactId), 'net-30');

$debtor = new \Z77\Module\Debtor\Dashboard\DebtorDashboard();
$empty  = $labelsOf($debtor->entries($admin));
check('M3 debtor, nothing seeded: only «Kein Zahlungsziel mit IBAN» (no payment target) — no zero counters',
    $empty === ['Kein Zahlungsziel mit IBAN'], json_encode($empty, JSON_UNESCAPED_UNICODE));

$today   = new \DateTimeImmutable('today');
$invoice = static function (string $state, ?int $number, string $gross, \DateTimeImmutable $due) use ($db, $contactId, $today): int {
    $db->insert('invoice', [
        'kind' => 'invoice', 'number' => $number, 'state' => $state, 'language' => 'de', 'currency' => 'CHF', 'price_mode' => 'net',
        'payment_terms_code' => 'net-30', 'invoice_date' => $due->modify('-30 days')->format('Y-m-d'), 'service_from' => $due->modify('-30 days')->format('Y-m-d'),
        'due_date' => $due->format('Y-m-d'), 'discount_tiers' => '[]', 'net_total' => $gross, 'tax_total' => '0.00', 'rounding' => '0.00', 'gross_total' => $gross,
        'created_by' => 'harness', 'created_at' => $today->format('Y-m-d H:i:s'), 'version' => 1, 'contact_id' => $contactId, 'addr_name' => 'Muster AG',
    ]);
    return (int) $db->lastInsertId();
};
$invoice('final', 1, '100.00', $today->modify('-60 days'));   // overdue, dunning due (reminder after 10 days)
$invoice('final', 2, '50.00', $today->modify('-5 days'));     // overdue, dunning not yet due
$invoice('final', 3, '70.00', $today->modify('+10 days'));    // open, not overdue
$invoice('invoicing', null, '30.00', $today);                  // two drafts
$invoice('invoicing', null, '40.00', $today);
$db->insert('bank_message', ['message_id' => 'MSG-1', 'created_on' => $today->format('Y-m-d H:i:s'), 'iban' => 'CH00', 'payment_target_code' => 'main', 'file_name' => 'a.xml', 'imported_by' => 'harness', 'imported_at' => $today->format('Y-m-d H:i:s')]);
$messageId = (int) $db->lastInsertId();
foreach (['unmatched', 'matched', 'booked', 'ignored', 'unmatched'] as $i => $state) {
    $db->insert('bank_transaction', ['position' => $i + 1, 'tx_ref' => 'TX' . $i, 'value_date' => $today->format('Y-m-d'), 'amount' => '10.00', 'currency' => 'CHF', 'state' => $state, 'remainder' => '0.00', 'message_id' => $messageId]);
}
file_put_contents($base . '/data/framework/debtor/payment_targets.json', json_encode([
    ['id' => 1, 'code' => 'main', 'label' => 'Hauptkonto', 'iban' => 'CH9300762011623852957', 'qr_iban' => '', 'active' => true],
], JSON_PRETTY_PRINT));
$forget();
$d = $byLabel((new \Z77\Module\Debtor\Dashboard\DebtorDashboard())->entries($admin));
check('M4 overdue: 2 final invoices past due, open CHF 150.00 — URGENT, → Debitoren view «überfällig»',
    isset($d['Rechnungen überfällig']) && $d['Rechnungen überfällig']->count === 2 && $d['Rechnungen überfällig']->amount?->toDecimal() === '150.00'
    && $d['Rechnungen überfällig']->severity === Severity::Urgent && $d['Rechnungen überfällig']->href() === '/backend/finance/debtor/list?view=overdue',
    json_encode(array_map(static fn($e) => [$e->count, $e->amount?->toDecimal()], $d), JSON_UNESCAPED_UNICODE));
check('M5 dunning proposal: 1 invoice due for its reminder (DunningService::dueList) — todo → Mahnungen',
    ($d['Mahnvorschlag bereit'] ?? null)?->count === 1 && $d['Mahnvorschlag bereit']->href() === '/backend/finance/dunning/list');
check('M6 bank transactions not booked: unmatched + matched = 3 (booked, ignored not) — todo → Zahlungseingänge',
    ($d['Zahlungseingänge nicht verbucht'] ?? null)?->count === 3 && $d['Zahlungseingänge nicht verbucht']->href() === '/backend/finance/bank-import/list');
check('M7 drafts in `invoicing`: 2 — todo → Rechnungen', ($d['Rechnungsentwürfe nicht fakturiert'] ?? null)?->count === 2 && $d['Rechnungsentwürfe nicht fakturiert']->severity === Severity::Todo);
check('M8 an active payment target with an IBAN: no «Kein Zahlungsziel mit IBAN»', !isset($d['Kein Zahlungsziel mit IBAN']), implode(', ', array_keys($d)));

// Orders: statuses of the seed — opened (new), scheduled (confirmed), delivered + to-invoice (delivered), booked (closed).
$order = static function (int $number, string $status) use ($db, $contactId, $today): void {
    $db->insert('sales_order', ['number' => $number, 'status_code' => $status, 'subject' => 'Test', 'order_date' => $today->format('Y-m-d'), 'price_mode' => 'net', 'currency' => 'CHF',
        'source_type' => 'backend', 'created_by' => 'harness', 'created_at' => $today->format('Y-m-d H:i:s'), 'version' => 1, 'contact_id' => $contactId]);
};
foreach ([[1, 'opened'], [2, 'opened'], [3, 'scheduled'], [4, 'delivered'], [5, 'to-invoice'], [6, 'to-invoice'], [7, 'booked'], [8, 'cancelled']] as [$n, $s]) {
    $order($n, $s);
}
$o = $byLabel((new \Z77\Module\Order\Dashboard\OrderDashboard())->entries($admin));
check('M9 orders ready to invoice per bookable phase: «Termin» 1, «Geliefert» 3 (delivered + to-invoice) — todo, each → its list tab',
    ($o['Aufträge bereit zur Verrechnung (Termin)'] ?? null)?->count === 1 && $o['Aufträge bereit zur Verrechnung (Termin)']->href() === '/backend/order/order/list?view=confirmed'
    && ($o['Aufträge bereit zur Verrechnung (Geliefert)'] ?? null)?->count === 3 && $o['Aufträge bereit zur Verrechnung (Geliefert)']->href() === '/backend/order/order/list?view=delivered',
    json_encode(array_map(static fn($e) => [$e->count, $e->href()], $o), JSON_UNESCAPED_UNICODE));
check('M10 open quotes: 2 «Offerten nachfassen» — todo (owner 2026-10-22) → tab «Eröffnet»',
    ($o['Offerten nachfassen'] ?? null)?->count === 2 && $o['Offerten nachfassen']->severity === Severity::Todo && $o['Offerten nachfassen']->href() === '/backend/order/order/list?view=new');
check('M11 «+ Neuer Auftrag» opens the order window like the list action cell (data-window-open on /backend/order/order/add)',
    isset($o['Neuer Auftrag']) && !$o['Neuer Auftrag']->isPending() && $o['Neuer Auftrag']->opens === DashboardEntry::OPEN_WINDOW && $o['Neuer Auftrag']->href() === '/backend/order/order/add');
$c = iterator_to_array((new \Z77\Module\Contact\Dashboard\ContactDashboard())->entries($admin), false);
check('M12 contact: only «+ Neuer Kontakt», the contact dialog (data-fetch-get on /backend/contact/contact/add) — no counter',
    count($c) === 1 && $c[0]->label === 'Neuer Kontakt' && $c[0]->opens === DashboardEntry::OPEN_DIALOG && $c[0]->href() === '/backend/contact/contact/add');

// Financial: the fiscal years.
check('M13 no fiscal year at all → URGENT «Kein offenes Geschäftsjahr für heute» → Geschäftsjahre',
    $labelsOf(FinancialDashboard::entriesOn(new \DateTimeImmutable('2026-10-22'))) === ['Kein offenes Geschäftsjahr für heute']
    && FinancialDashboard::entriesOn(new \DateTimeImmutable('2026-10-22'))[0]->severity === Severity::Urgent
    && FinancialDashboard::entriesOn(new \DateTimeImmutable('2026-10-22'))[0]->href() === '/backend/finance/fiscal-year/list');
$db->insert('fiscal_year', ['code' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
$fy2026 = (int) $db->lastInsertId();
$db->insert('fiscal_period', ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'state' => 'open', 'fiscal_year_id' => $fy2026]);
$forget();
check('M14 year 2026 open: in October nothing; in December todo «Geschäftsjahr ab 01.01.2027 nicht eröffnet»; on 01.02.2027 urgent',
    FinancialDashboard::entriesOn(new \DateTimeImmutable('2026-10-22')) === []
    && $labelsOf(FinancialDashboard::entriesOn(new \DateTimeImmutable('2026-12-15'))) === ['Geschäftsjahr ab 01.01.2027 nicht eröffnet']
    && FinancialDashboard::entriesOn(new \DateTimeImmutable('2026-12-15'))[0]->severity === Severity::Todo
    && $labelsOf(FinancialDashboard::entriesOn(new \DateTimeImmutable('2027-02-01'))) === ['Kein offenes Geschäftsjahr für heute']);
$db->insert('fiscal_year', ['code' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
$forget();
check('M15 year 2027 opened: December says nothing any more', FinancialDashboard::entriesOn(new \DateTimeImmutable('2026-12-15')) === []);
$db->executeStatement("UPDATE fiscal_period SET state = 'closed' WHERE fiscal_year_id = ?", [$fy2026]);
$forget();
check('M16 the year covering today is CLOSED → urgent (no posting dated today is possible)',
    $labelsOf(FinancialDashboard::entriesOn(new \DateTimeImmutable('2026-10-22'))) === ['Kein offenes Geschäftsjahr für heute']);

// Mandator.
$mandator = new \Z77\Module\Mandator\Dashboard\MandatorDashboard();
check('M17 no mandator saved → todo «Mandant nicht erfasst» → Mandant (edit)',
    $labelsOf($mandator->entries($admin)) === ['Mandant nicht erfasst'] && iterator_to_array($mandator->entries($admin), false)[0]->href() === '/backend/finance/mandator/edit');
$db->insert('mandator', ['id' => 1, 'name' => 'Muster GmbH', 'country' => 'CH', 'uid' => '', 'liable_to_vat' => 1]);
$forget();
check('M18 liable to VAT without UID → todo «Mandant MWST-pflichtig ohne UID»', $labelsOf($mandator->entries($admin)) === ['Mandant MWST-pflichtig ohne UID']);
$db->executeStatement("UPDATE mandator SET uid = 'CHE-123.456.788' WHERE id = 1");
$forget();
$withUid = $labelsOf($mandator->entries($admin));
$db->executeStatement("UPDATE mandator SET uid = '', liable_to_vat = 0 WHERE id = 1");
$forget();
check('M19 with a UID, or not liable to VAT: nothing', $withUid === [] && $labelsOf($mandator->entries($admin)) === []);

// The whole registry in one go, as the controller asks it.
$all = $labelsOf($registry->entriesFor($admin));
check('M20 entriesFor() over the real modules: every contributor answers, none «nicht verfügbar»',
    !array_filter($all, static fn(string $l) => str_contains($l, 'nicht verfügbar')) && in_array('Rechnungen überfällig', $all, true) && in_array('Neuer Kontakt', $all, true),
    json_encode($all, JSON_UNESCAPED_UNICODE));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
