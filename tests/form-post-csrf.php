<?php

/**
 * The three add forms that read a JSON body (CLI, real stack in PHP's built-in server, a real MariaDB,
 * throwaway schema) — security.md rule «page-mode POST». They had no CSRF check in page mode: the
 * global check covers Fetch POSTs only, and a body in JSON still arrives without one when a request
 * does not say it is a fetch. What is load-bearing: a POST without a token changes nothing, however
 * it comes; the real form, a fetch POST with the header as core.js sends it, still works; the GET
 * still shows the form.
 *
 * Run: php tests/form-post-csrf.php
 * Needs what tests/module-contact.php needs: vendor/ with Doctrine, a reachable MariaDB, credentials
 * in `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "form-post-csrf: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;
require_once __DIR__ . '/support/real-flow-site.php';
require_once __DIR__ . '/support/real-flow-server.php';

use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Libraries\ConfigManager;
use Z77\Core\Libraries\FileFinder;
use Z77\Core\Services\ModuleManager;
use Z77\Persistence\Doctrine\Console\MigrationDirectories;
use Z77\Persistence\Doctrine\Console\MigrationsApplication;
use Z77\Persistence\Doctrine\Bootstrap as DoctrineBootstrap;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

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
    fwrite(STDERR, "form-post-csrf: no database password — %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD.\n");
    exit(2);
}

$root   = str_replace('\\', '/', dirname(__DIR__));
$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password']]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-form-post-csrf-' . getmypid();
$site = $base . '/site';
mkdir($base, 0777, true);
define('ABS_BASE_PATH', $site);
define('DEBUG', false);
$removeTree = static function (string $dir) use (&$removeTree): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (basename($f) === '.' || basename($f) === '..') { continue; }
        is_dir($f) ? $removeTree($f) : @unlink($f);
    }
    @rmdir($dir);
};
register_shutdown_function(static function () use ($admin, $dbName, $removeTree, $base): void {
    try { $admin->executeStatement("DROP DATABASE IF EXISTS `{$dbName}`"); } catch (\Throwable) {}
    $removeTree($base);
});

$hash = password_hash('Passwort-Sehr-Lang-1', PASSWORD_BCRYPT, ['cost' => 4]);
realFlowSite($site, $root, [['id' => 1, 'username' => 'max', 'email' => 'max.muster@example.ch', 'password_hash' => $hash, 'roles' => ['superUser'], 'sort_key' => 0]]);

// The three modules on top of the minimal installation, and the database behind them.
$finder = (string) file_get_contents($site . '/config/fileFinder.inc.php');
file_put_contents($site . '/config/fileFinder.inc.php', str_replace(
    "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),",
    "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),\n"
    . "        'Z77\\\\Module\\\\Contact\\\\'  => \$entry('module-contact'),\n"
    . "        'Z77\\\\Module\\\\Mandator\\\\' => \$entry('module-mandator'),\n"
    . "        'Z77\\\\Module\\\\Vat\\\\'      => \$entry('module-vat'),\n"
    . "        'Z77\\\\Module\\\\Financial\\\\' => \$entry('module-financial'),",
    $finder
));
$modules = (string) file_get_contents($site . '/config/moduleManager.inc.php');
file_put_contents($site . '/config/moduleManager.inc.php', (string) preg_replace("/('backend' =>\s*array \(\s*\),)/", "$1\n    'contact' => array (),\n    'mandator' => array (),\n    'vat' => array (),\n    'financial' => array (),", $modules));
mkdir($site . '/config/client', 0777, true);
file_put_contents($site . '/config/client/database.inc.php', '<?php return ' . var_export(['host' => $credentials['host'], 'port' => null, 'name' => $dbName, 'user' => $credentials['user'], 'password' => $credentials['password']], true) . ';');
@mkdir($site . '/data/framework/contact', 0777, true);
copy($root . '/packages/module-contact/data/framework/contact/address_types.default.json', $site . '/data/framework/contact/address_types.json');
@mkdir($site . '/data/framework/vat', 0777, true);
foreach (['tax_codes', 'tax_rates'] as $name) {
    copy($root . "/packages/module-vat/data/framework/vat/{$name}.default.json", $site . "/data/framework/vat/{$name}.json");
}

// The schema, by the modules' own migrations.
DI::getInstance(true)
    ->set('CacheManager', CacheManager::class, true)
    ->set('FileFinder', fn($c) => new FileFinder($c->get('CacheManager')), true)
    ->set('ConfigManager', fn($c) => new ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
    ->set('ModuleManager', fn($c) => new ModuleManager($c->get('ConfigManager')), true)
    ->set('DataSourceResolver', fn() => new DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
    ->set('UnifiedEntityManager', fn($c) => new UnifiedEntityManager($c->get('DataSourceResolver')), true);
DI::getCacheManager()->setCacheDir($site . '/var/cache');
$app = MigrationsApplication::create(
    DoctrineBootstrap::buildEntityManager(),
    MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder()),
    DI::getCacheManager()->generatedPhp(),
    MigrationDirectories::missing(DI::getModuleManager(), DI::getFileFinder())
);
$app->setAutoExit(false);
$out  = new BufferedOutput();
$code = $app->run(new ArrayInput(['command' => 'migrate', '--no-interaction' => true]), $out);
check('the schema is migrated', $code === 0, substr($out->fetch(), 0, 400));

$db    = DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName, 'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password']]);
$count = static fn(string $table): int => (int) $db->fetchOne("SELECT COUNT(*) FROM {$table}");

$port = realFlowServer($site, $base . '/sessions', $base . '/server.log');
$b    = new RealFlowBrowser($port);
$r    = $b->go('/backend/system/login/login', ['csrf_token' => $b->pageToken('/login'), 'username' => 'max', 'password' => 'Passwort-Sehr-Lang-1']);
check('signed in', in_array($r[0], [302, 303], true), (string) $r[0]);
$token = $b->pageToken('/backend/system/dashboard/overview');
check('a token from the shell', strlen($token) >= 20);

$cases = [
    ['Kontakt anlegen', '/backend/contact/contact/add', 'contact', ['kind' => 'organisation', 'company' => 'Beispiel AG', 'language' => 'de']],
    ['Konto anlegen', '/backend/finance/account/add', 'account', ['number' => '1000', 'name' => 'Kasse', 'type' => 'asset']],
    ['Geschäftsjahr eröffnen', '/backend/finance/fiscal-year/open', 'fiscal_year', ['start_date' => '2031-01-01', 'end_date' => '2031-12-31', 'code' => '2031']],
];
foreach ($cases as [$label, $url, $table, $body]) {
    echo "{$label}\n";
    $json   = (string) json_encode($body);
    $before = $count($table);

    $form = $b->fetchGet($url);
    check('GET: the form', $form[0] === 200 && str_contains($form[2], 'data-fetch-post'), $form[0] . ' ' . substr($form[2], 0, 160));

    $r = $b->go($url, $json);
    check('a page POST with a JSON body, no token: refused', $count($table) === $before && $r[0] === 303, $r[0] . ' rows ' . $count($table) . ' ' . substr($r[2], 0, 120));
    $r = $b->go($url, ['csrf_token' => str_repeat('a', 64)] + $body);
    check('a page POST with a wrong token in the body: refused', $count($table) === $before && $r[0] === 303, $r[0] . ' rows ' . $count($table));
    $r = $b->go($url, $json, ['X-CSRF-Token: ' . str_repeat('a', 64)]);
    check('a page POST with a wrong token in the header: refused', $count($table) === $before && $r[0] === 303, $r[0] . ' rows ' . $count($table));
    $r = $b->fetchPost($url, $body, null);
    check('a fetch POST without the header: refused by the guard', str_contains($r[2], 'CSRF token invalid') && $count($table) === $before, substr($r[2], 0, 120));

    $r = $b->fetchPost($url, $body, $token);
    check('the real form, a fetch POST with the header: it works', $r[0] === 200 && $count($table) === $before + 1, $r[0] . ' rows ' . $count($table) . ' ' . substr($r[2], 0, 300));
}

$log = (string) @file_get_contents($site . '/logs/php-error.log') . (string) @file_get_contents($base . '/server.log');
check('no PHP fatal in the logs', !preg_match('/PHP Fatal/', $log), (string) (preg_match('/.*PHP Fatal.*/', $log, $m) ? $m[0] : ''));

echo "\nform-post-csrf: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
