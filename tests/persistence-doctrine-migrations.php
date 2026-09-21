<?php

/**
 * Caches and migrations harness (CLI) — z77/persistence-doctrine part 3
 * against a REAL MariaDB (ADR-039 decision 16), throwaway schema per run.
 *
 * What is load-bearing here (ADR-039 decisions 11 to 14):
 *
 *   - production: the driver compiles metadata to PHP files under the
 *     release-local `var/cache/doctrine/` and a second boot reads them; DEBUG
 *     (a child process with the flag) writes no file at all;
 *   - «Cache leeren» / DEBUG toggle: the kernel's `GeneratedPhpCache` deletes
 *     the directory and reports every file it invalidated, works as a no-op
 *     when nothing is there, and refuses a directory it would never clear;
 *   - `z77-db migrate` on an empty database creates `number_range` EXACTLY as
 *     `SchemaTool` would (columns, collation — compared against a twin
 *     database) plus the metadata table in OUR collation, although the
 *     database default says otherwise; then deletes the compiled cache;
 *   - `status` reports it, a second `migrate` is a no-op;
 *   - the migration directories are collected from the package and from
 *     every module that declares `doctrineEntities` — and from no other
 *     module, even one that ships a migration;
 *   - `diff` writes a module's migration into the module's `res/migrations`
 *     (namespace `{Module}\Migrations`), refuses to guess the namespace, and
 *     after `migrate` reports NO change — including a `money` column
 *     (DOCTRINE-TYPE-001);
 *   - a non-CLI SAPI is refused.
 *
 * Run: php tests/persistence-doctrine-migrations.php
 * Needs what tests/persistence-doctrine.php needs (vendor/, MariaDB,
 * credentials in `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*). The
 * script re-invokes ITSELF with `--debug-boot` for the DEBUG check.
 */

namespace {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        fwrite(STDERR, "persistence-doctrine-migrations: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
        exit(2);
    }
    require $autoload;

    use Doctrine\DBAL\DriverManager;
    use Doctrine\ORM\Tools\SchemaTool;
    use Symfony\Component\Cache\Adapter\ArrayAdapter;
    use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
    use Symfony\Component\Console\Input\ArrayInput;
    use Symfony\Component\Console\Output\BufferedOutput;
    use Z77\Core\DI;
    use Z77\Core\Libraries\Cache\GeneratedPhpCache;
    use Z77\Core\Libraries\CacheManager;
    use Z77\Core\Libraries\ConfigManager;
    use Z77\Core\Libraries\FileFinder;
    use Z77\Core\Services\ModuleManager;
    use Z77\Persistence\Doctrine\Bootstrap as DoctrineBootstrap;
    use Z77\Persistence\Doctrine\Console\MigrationDirectories;
    use Z77\Persistence\Doctrine\Console\MigrationsApplication;
    use Z77\Persistence\Doctrine\Entities\NumberRange;
    use Z77\Persistence\Doctrine\EntityManagerFactory;
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

    /** Runs $fn and returns the exception's message when it throws $class, null otherwise. */
    function thrown(callable $fn, string $class): ?string
    {
        try { $fn(); } catch (\Throwable $e) { return $e instanceof $class ? $e->getMessage() : null; }
        return null;
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
        fwrite(STDERR, "persistence-doctrine-migrations: no database password — %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD.\n");
        exit(2);
    }

    // ── the throwaway installation: real ConfigManager / FileFinder / ModuleManager over temp files ─
    //
    // Two module packages: `probe` declares a Doctrine entity with a money
    // column and has an (initially empty) res/migrations; `silent` declares NO
    // entity but ships a migration that must never run.

    $moduleAutoload = static function (string $base): void {
        spl_autoload_register(static function (string $class) use ($base): void {
            foreach (['Z77\\Module\\Probe\\' => 'module-probe', 'Z77\\Module\\Silent\\' => 'module-silent'] as $prefix => $dir) {
                if (str_starts_with($class, $prefix)) {
                    $file = "{$base}/vendor/z77/{$dir}/src/" . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) { require $file; }
                    return;
                }
            }
        });
    };

    /** The DI wiring Bootstrap::__construct() + pullUpServices() do, reduced to what the driver and the CLI read. */
    $wireDi = static function (string $installationBase): void {
        DI::getInstance(true)
            ->set('CacheManager', CacheManager::class, true)
            ->set('FileFinder', fn($c) => new FileFinder($c->get('CacheManager')), true)
            ->set('ConfigManager', fn($c) => new ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
            ->set('ModuleManager', fn($c) => new ModuleManager($c->get('ConfigManager')), true)
            ->set('DataSourceResolver', fn() => new DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
            ->set('UnifiedEntityManager', fn($c) => new UnifiedEntityManager($c->get('DataSourceResolver')), true)
        ;
        DI::getCacheManager()->setCacheDir($installationBase . '/var/cache');
    };

    // ── debug-boot mode: the DEBUG half of decision 11, in a process where DEBUG is true ─
    //
    // php tests/persistence-doctrine-migrations.php --debug-boot <base>
    // Boots the driver with DEBUG defined true, loads the package entity's
    // metadata and prints JSON: the metadata cache's class and whether the
    // cache directory got any file.

    if (($argv[1] ?? '') === '--debug-boot') {
        $workerBase = $argv[2];
        define('ABS_BASE_PATH', $workerBase);
        define('DEBUG', true);
        $moduleAutoload($workerBase);
        $wireDi($workerBase);
        $em = DoctrineBootstrap::buildEntityManager();
        $em->getClassMetadata(NumberRange::class);
        $dir = $workerBase . '/var/cache/doctrine';
        echo json_encode([
            'cache'    => get_class($em->getConfiguration()->getMetadataCache()),
            'dirFiles' => is_dir($dir) ? count(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)))) : -1,
        ]);
        exit(0);
    }

    // ── opcache-probe mode: the reason the pool invalidates BEFORE it deletes ─
    //
    // php -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 tests/persistence-doctrine-migrations.php --opcache-probe <base>
    // Compiles a cache file, includes it (OPcache keeps it), deletes it one
    // way or another, writes a DIFFERENT file at the same path and includes
    // again: with timestamps off, only an invalidated path yields the new
    // content. Prints JSON.

    if (($argv[1] ?? '') === '--opcache-probe') {
        $probeBase = $argv[2] . '/opcache-probe';
        $n         = 0;
        // Every scenario gets its own directory: OPcache keys by resolved path, so a
        // path reused across scenarios would carry the previous scenario's compiled copy.
        $scenario  = static function (string $sep, callable $clear) use ($probeBase, &$n): string {
            $cache = $probeBase . '/s' . (++$n) . '/var/cache';
            @mkdir($cache . '/doctrine/a/b', 0777, true);
            $file    = $cache . '/doctrine/a/b/x.php';
            $include = $cache . '/doctrine' . $sep . 'a' . $sep . 'b' . $sep . 'x.php';   // Symfony builds the path with DIRECTORY_SEPARATOR
            file_put_contents($file, '<?php return 1;');
            touch($file, time() - 100);
            $first = include $include;
            $clear($cache, $file);
            @mkdir($cache . '/doctrine/a/b', 0777, true);
            file_put_contents($file, '<?php return 2;');
            touch($file, time() - 100);
            $second = include $include;
            @unlink($file);
            return $first === 1 && $second === 2 ? 'fresh' : 'STALE';
        };
        $viaPool = static function (string $cache): void {
            $pool = new GeneratedPhpCache();
            $pool->setCacheDir($cache);
            $pool->clearAll();
        };
        echo json_encode([
            'opcache'          => function_exists('opcache_get_status') && @opcache_get_status(false) !== false,
            'unlinkOnly'       => $scenario(DIRECTORY_SEPARATOR, static fn(string $cache, string $file) => unlink($file)),
            'clearAll'         => $scenario('/', $viaPool),
            'clearAllBackslash' => $scenario('\\', $viaPool),
        ]);
        exit(0);
    }

    // ── throwaway schemas, deliberately NOT in our collation ─────────────────

    $dbName    = 'z77test_' . bin2hex(random_bytes(4));
    $twinName  = $dbName . '_twin';   // SchemaTool's version of number_range, for the comparison
    $admin     = DriverManager::getConnection([
        'driver' => 'pdo_mysql', 'host' => $credentials['host'],
        'user' => $credentials['user'], 'password' => $credentials['password'],
    ]);
    $admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $admin->executeStatement("CREATE DATABASE `{$twinName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

    $base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-doctrine-mig-' . getmypid();
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
    register_shutdown_function(static function () use ($admin, $dbName, $twinName, $rm, $base): void {
        foreach ([$dbName, $twinName] as $name) {
            try { $admin->executeStatement("DROP DATABASE IF EXISTS `{$name}`"); } catch (\Throwable) {}
        }
        $rm($base);
    });

    $baseDirPhp = "<?php \$baseDir = ABS_BASE_PATH.'/';\n";
    $write('config/vendor/fileFinder.inc.php', $baseDirPhp . "return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n"
        . "'Z77\\\\Module\\\\Probe\\\\'  => ['sourcePaths' => [\$baseDir.'override/z77/module/probe', \$baseDir.'vendor/z77/module-probe']],\n"
        . "'Z77\\\\Module\\\\Silent\\\\' => ['sourcePaths' => [\$baseDir.'vendor/z77/module-silent']],\n"
        . "]];");
    $write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'probe', 'modules' => ['probe' => [], 'silent' => []]];");
    $write('config/client/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
    $write('config/client/database.inc.php', '<?php return ' . var_export([
        'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
        'user' => $credentials['user'], 'password' => $credentials['password'],
    ], true) . ';');

    $write('vendor/z77/module-probe/src/App/Config/probeConfig.inc.php', "<?php return ['doctrineEntities' => ['Z77\\\\Module\\\\Probe\\\\Entities\\\\Posting']];");
    $write('vendor/z77/module-probe/src/Entities/Posting.php', <<<'PHP'
<?php
namespace Z77\Module\Probe\Entities;

use Doctrine\ORM\Mapping as ORM;
use Z77\Persistence\Doctrine\Type\MoneyType;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Money\Money;

#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'probe_posting')]
class Posting
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $text = '';

    #[ORM\Column(type: MoneyType::NAME)]
    private Money $amount;
}
PHP);
    @mkdir($base . '/vendor/z77/module-probe/res/migrations', 0777, true);
    $probeMigrations = $base . '/vendor/z77/module-probe/res/migrations';

    $write('vendor/z77/module-silent/src/App/Config/silentConfig.inc.php', "<?php return [];");
    $write('vendor/z77/module-silent/res/migrations/Version20260921000001.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Z77\Module\Silent\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921000001 extends AbstractMigration
{
    public function up(Schema $schema): void   { $this->addSql('CREATE TABLE should_not_exist (id INT NOT NULL, PRIMARY KEY (id))'); }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE should_not_exist'); }
}
PHP);

    $moduleAutoload($base);
    $wireDi($base);

    $connection = [
        'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
        'user' => $credentials['user'], 'password' => $credentials['password'],
    ];
    $db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
        'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
    ]);
    $tables   = fn() => array_map('strtolower', $db->createSchemaManager()->listTableNames());
    $tableCollation = fn(string $table) => $db->fetchOne('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$dbName, $table]);
    $cacheDir = $base . '/var/cache/doctrine';
    $filesIn  = static fn(string $dir): array => is_dir($dir)
        ? array_values(array_map(
            static fn(SplFileInfo $f) => str_replace('\\', '/', $f->getPathname()),
            iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)))
        ))
        : [];

    /** Runs one z77-db command in-process on a fresh application; returns [exit code, output]. */
    $run = static function (array $input, ?string $sapi = null, ?string $answer = null): array {
        $app = MigrationsApplication::create(
            DoctrineBootstrap::buildEntityManager(),
            MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder()),
            DI::getCacheManager()->generatedPhp(),
            MigrationDirectories::missing(DI::getModuleManager(), DI::getFileFinder()),
            sapi: $sapi ?? PHP_SAPI
        );
        $app->setAutoExit(false);
        $out = new BufferedOutput();
        if ($answer === null) {
            $in = new ArrayInput($input + ['--no-interaction' => true]);
        } else {
            // An interactive run: the operator's answer to Doctrine's «Are you sure?» comes from this stream.
            $in     = new ArrayInput($input);
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $answer . "\n");
            rewind($stream);
            $in->setStream($stream);
            $in->setInteractive(true);
        }
        $code = $app->run($in, $out);
        return [$code, $out->fetch()];
    };

    // ── A. production cache: PHP files under var/cache/doctrine, read on the next boot ─

    echo "A. Production metadata cache\n";
    check('A0 nothing compiled before the first boot', !is_dir($cacheDir));
    $em1 = DoctrineBootstrap::buildEntityManager();
    check('A1 the driver boots with a PhpFilesAdapter (DEBUG is false)', $em1->getConfiguration()->getMetadataCache() instanceof PhpFilesAdapter);
    $em1->getClassMetadata(NumberRange::class);
    $compiled = $filesIn($cacheDir);
    check('A2 loading metadata writes PHP files under var/cache/doctrine (' . count($compiled) . ')', count($compiled) > 0 && str_starts_with((string) file_get_contents($compiled[0]), '<?php'));
    $key = str_replace('\\', '__', NumberRange::class) . '__CLASSMETADATA__';
    $em2 = DoctrineBootstrap::buildEntityManager();
    check('A3 a second boot finds the entry in the file cache before touching the mapping', $em2->getConfiguration()->getMetadataCache()->hasItem($key));
    check('A4 … and the query cache is the same pool', $em2->getConfiguration()->getQueryCache() === $em2->getConfiguration()->getMetadataCache());
    check('A5 GeneratedPhpCache::dir() is where the driver compiles to (one path, Rule 2)', DI::getCacheManager()->generatedPhp()->dir('doctrine') === $cacheDir);
    $em1->getConnection()->close();
    $em2->getConnection()->close();

    echo "A. … DEBUG: in memory, no file (child process with DEBUG = true)\n";
    $before = count($filesIn($cacheDir));
    $proc   = proc_open([PHP_BINARY, __FILE__, '--debug-boot', $base], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out    = stream_get_contents($pipes[1]);
    $err    = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code   = proc_close($proc);
    $debug  = json_decode($out, true);
    check('A6 the DEBUG boot exited clean' . ($code !== 0 ? " — exit {$code}: " . trim($err) : ''), $code === 0 && is_array($debug));
    check('A7 DEBUG uses an in-memory pool', ($debug['cache'] ?? '') === ArrayAdapter::class);
    check('A8 … and wrote no file', ($debug['dirFiles'] ?? -1) === $before);
    check('A9 the factory alone: a null cache directory is the in-memory pool', EntityManagerFactory::create($connection, [], 'CHF', null)->getConfiguration()->getMetadataCache() instanceof ArrayAdapter);

    // ── B. «Cache leeren»: the kernel pool deletes and reports every file ────

    echo "B. GeneratedPhpCache (the kernel's clear helper)\n";
    $pool = DI::getCacheManager()->generatedPhp();
    @mkdir($cacheDir . '/nested/deeper', 0777, true);
    file_put_contents($cacheDir . '/nested/deeper/extra.php', '<?php return 1;');
    $expected = $filesIn($cacheDir);
    sort($expected);
    $deleted = $pool->clearAll();
    sort($deleted);
    check('B1 every file is reported as deleted, nested ones included (' . count($deleted) . ')', $deleted === $expected && count($deleted) > 1);
    check('B2 the directory is gone', !is_dir($cacheDir));
    check('B3 clearing again is a no-op with nothing to report', $pool->clearAll() === []);
    check('B4 nothing moved aside is left behind', glob($base . '/var/cache/doctrine.clearing-*') === []);
    check('B5 a directory the pool would never clear is refused by dir()', thrown(fn() => $pool->dir('elsewhere'), \InvalidArgumentException::class) !== null);
    check('B6 dir() before the cache directory is configured is refused', thrown(fn() => (new GeneratedPhpCache())->dir('doctrine'), \RuntimeException::class) !== null);
    check('B7 clearAll() before configuration clears nothing and does not throw', (new GeneratedPhpCache())->clearAll() === []);
    $controller = file_get_contents(__DIR__ . '/../packages/module-backend/src/Ui/Controllers/System/SystemController.php');
    check('B8 SystemController: clearCacheAction and toggleDebugAction both clear the pool (source guard)', substr_count($controller, 'generatedPhp()->clearAll()') === 2);

    $outside = $base . '/outside';
    @mkdir($outside, 0777, true);
    file_put_contents($outside . '/keep.php', '<?php return 1;');
    $linked = @symlink($outside, $cacheDir);   // needs a privilege on Windows; skipped honestly when refused
    if ($linked) {
        $msg = thrown(fn() => $pool->clearAll(), \RuntimeException::class);
        check('B9 a symlinked var/cache/doctrine is refused, nothing outside var/cache is touched', $msg !== null && str_contains($msg, 'symlink') && is_file($outside . '/keep.php'));
        @unlink($cacheDir) || @rmdir($cacheDir);
    } else {
        check('B9 (symlink refusal not testable here: symlink() refused by the OS)', true);
    }
    $rm($outside);

    echo "B. … OPcache: invalidation BEFORE deletion (child process with OPcache on, validate_timestamps off)\n";
    $proc = proc_open(
        [PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=0', __FILE__, '--opcache-probe', $base],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $out  = stream_get_contents($pipes[1]);
    $err  = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code  = proc_close($proc);
    $probe = json_decode($out, true);
    check('B10 the probe exited clean' . ($code !== 0 ? " — exit {$code}: " . trim($err) : ''), $code === 0 && is_array($probe));
    if (($probe['opcache'] ?? false) !== true) {
        check('B11 (OPcache could not be enabled in the child — invalidation not observable on this machine)', true);
    } else {
        check('B11 a file deleted by plain unlink() is still served from OPcache (the defect the pool exists for)', ($probe['unlinkOnly'] ?? null) === 'STALE');
        check('B12 clearAll() invalidates before it deletes: the recompiled file is fresh — also via the "\\"-separated path Symfony includes on Windows', ($probe['clearAll'] ?? null) === 'fresh' && ($probe['clearAllBackslash'] ?? null) === 'fresh');
    }

    // ── C. where the migrations are ──────────────────────────────────────────

    echo "C. Migration directories\n";
    $dirs = MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder());
    check('C1 the package first, then the probe module — nothing from silent', array_keys($dirs) === [MigrationDirectories::PACKAGE_NAMESPACE, 'Z77\\Module\\Probe\\Migrations']);
    check('C2 the package directory holds the number_range migration', is_file($dirs[MigrationDirectories::PACKAGE_NAMESPACE] . '/Version20260921000000.php'));
    check('C3 the module directory is res/migrations next to src/', $dirs['Z77\\Module\\Probe\\Migrations'] === $probeMigrations);
    @mkdir($base . '/override/z77/module/probe/src', 0777, true);
    @mkdir($base . '/override/z77/module/probe/res/migrations', 0777, true);
    $wireDi($base);   // FileFinder resolves its paths once per instance
    $msg = thrown(fn() => MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder()), \RuntimeException::class);
    check('C4 the same module with res/migrations under two source paths is refused, naming both', $msg !== null && str_contains($msg, 'override/z77/module/probe/res/migrations') && str_contains($msg, 'vendor/z77/module-probe/res/migrations'));
    $rm($base . '/override');
    $wireDi($base);

    // ── D. refusals ──────────────────────────────────────────────────────────

    echo "D. Refusals\n";
    $msg = thrown(fn() => $run(['command' => 'status'], 'fpm-fcgi'), \RuntimeException::class);
    check('D1 a web SAPI is refused before anything runs', $msg !== null && str_contains($msg, 'command line only'));
    check('D2 no directories at all is refused', thrown(fn() => MigrationsApplication::create(DoctrineBootstrap::buildEntityManager(), [], $pool), \InvalidArgumentException::class) !== null);
    [$code, $out] = $run(['command' => 'migrate'], null, 'no');
    check('D4 an interactive migrate answered «no» is cancelled (exit 3)', $code === 3 && str_contains($out, 'Migration cancelled'));
    check('D5 … and leaves no metadata table behind', $tables() === []);
    $proc = proc_open([PHP_BINARY, __DIR__ . '/../packages/persistence-doctrine/bin/z77-db', '--project=' . $base . '/nowhere', 'status'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $err  = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check('D3 the binary without a project root fails with exit 1 and says so', proc_close($proc) === 1 && str_contains($err, 'project root not found'));

    // ── E. migrate on an empty database ──────────────────────────────────────

    echo "E. First migrate\n";
    check('E0 the database is empty', $tables() === []);
    $em = DoctrineBootstrap::buildEntityManager();
    $em->getClassMetadata(NumberRange::class);   // compile something, so the deletion after migrate is observable
    $em->getConnection()->close();
    check('E1 compiled cache present before migrate', count($filesIn($cacheDir)) > 0);
    [$code, $out] = $run(['command' => 'migrate']);
    check('E2 exit 0' . ($code !== 0 ? " — got {$code}: " . trim($out) : ''), $code === 0);
    check('E3 reports the migrated version', str_contains($out, 'Successfully migrated') && str_contains($out, 'Version20260921000000'));
    check('E4 number_range and the metadata table exist, nothing else', $tables() === ['number_range', MigrationsApplication::STORAGE_TABLE]);
    check('E5 silent\'s migration never ran (module declares no doctrineEntities)', !in_array('should_not_exist', $tables(), true));
    check('E6 the metadata table is utf8mb4_unicode_ci although the database default is general_ci', $tableCollation(MigrationsApplication::STORAGE_TABLE) === 'utf8mb4_unicode_ci');
    check('E7 one executed migration recorded', (int) $db->fetchOne('SELECT COUNT(*) FROM ' . MigrationsApplication::STORAGE_TABLE) === 1);
    check('E8 the compiled cache was deleted as part of the command', !is_dir($cacheDir) && str_contains($out, 'Compiled metadata cache deleted'));
    check('E8b the run names the project root and the database before executing', str_contains($out, 'Project: ' . $base) && str_contains($out, 'database: ' . $dbName));
    $engine = $db->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$dbName, MigrationsApplication::STORAGE_TABLE]);
    check('E8c the metadata table is InnoDB', $engine === 'InnoDB');

    echo "E. … number_range is what SchemaTool would create\n";
    $twinEm = EntityManagerFactory::create(['name' => $twinName] + $connection, [], 'CHF');
    (new SchemaTool($twinEm))->createSchema($twinEm->getMetadataFactory()->getAllMetadata());
    $columnsOf = fn(string $schema) => $db->fetchAllAssociative(
        'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME, COLUMN_KEY, EXTRA '
        . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
        [$schema, NumberRange::TABLE]
    );
    $tableOf = fn(string $schema) => $db->fetchAssociative('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$schema, NumberRange::TABLE]);
    $migrated = $columnsOf($dbName);
    check('E9 same columns, types, nullability, collation and keys as the SchemaTool twin', $migrated === $columnsOf($twinName) && count($migrated) === 2);
    check('E10 same engine and table collation (utf8mb4_unicode_ci)', $tableOf($dbName) === $tableOf($twinName) && $tableOf($dbName)['TABLE_COLLATION'] === 'utf8mb4_unicode_ci');
    $twinEm->getConnection()->close();

    // ── F. status, and a second migrate is a no-op ───────────────────────────

    echo "F. Status / second migrate\n";
    [$code, $out] = $run(['command' => 'status']);
    check('F1 status exits 0', $code === 0);
    check('F2 … names the executed version and both namespaces', str_contains($out, 'Version20260921000000') && str_contains($out, MigrationDirectories::PACKAGE_NAMESPACE) && str_contains($out, 'Z77\\Module\\Probe\\Migrations'));
    [$code, $out] = $run(['command' => 'migrate']);
    check('F3 a second migrate exits 0', $code === 0);
    check('F4 … and did nothing', str_contains($out, 'Already at the latest version') && (int) $db->fetchOne('SELECT COUNT(*) FROM ' . MigrationsApplication::STORAGE_TABLE) === 1);
    [$code, $out] = $run(['command' => 'migrate', '--dry-run' => true]);
    check('F5 a dry run leaves the cache alone', $code === 0 && !str_contains($out, 'Compiled metadata cache deleted'));
    $sqlDir = $base . '/sql-out';
    @mkdir($sqlDir, 0777, true);
    [$code, $out] = $run(['command' => 'migrate', '--write-sql' => $sqlDir]);
    check('F6 --write-sql is NOT a dry run in doctrine/migrations 3.9 — the cache is cleared like after any real migrate', $code === 0 && str_contains($out, 'Compiled metadata cache deleted'));

    // ── G. diff: a module migration, and no diff afterwards (money column) ───

    echo "G. Diff for the probe module\n";
    [$code, $out] = $run(['command' => 'diff']);
    check('G1 diff without --namespace is refused, listing the configured namespaces', $code === 1 && str_contains($out, '--namespace') && str_contains($out, 'Z77\\Module\\Probe\\Migrations') && str_contains($out, MigrationDirectories::PACKAGE_NAMESPACE));
    check('G2 … and wrote nothing', glob($probeMigrations . '/Version*.php') === [] && glob(MigrationDirectories::packageDirectory() . '/Version2026092[2-9]*.php') === []);
    [$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Nope\\Migrations']);
    check('G2b an unknown namespace is refused', $code === 1 && str_contains($out, 'Unknown migration namespace'));
    rmdir($probeMigrations);   // the module has entities but no directory yet — the first-migration situation
    $wireDi($base);
    check('G2c missing(): the module without res/migrations is reported with the directory to create', MigrationDirectories::missing(DI::getModuleManager(), DI::getFileFinder()) === ['Z77\\Module\\Probe\\Migrations' => $probeMigrations]);
    [$code, $out] = $run(['command' => 'diff']);
    check('G2d diff without --namespace names the directory to create for that module', $code === 1 && str_contains($out, 'create ' . $probeMigrations . ' first'));
    [$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Probe\\Migrations']);
    check('G2e diff for that module says «create … first» instead of Doctrine\'s «Path not defined»', $code === 1 && str_contains($out, 'create ' . $probeMigrations . ' first') && !str_contains($out, 'Path not defined'));
    mkdir($probeMigrations, 0777, true);
    $wireDi($base);
    [$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Probe\\Migrations']);
    $generated = glob($probeMigrations . '/Version*.php');
    check('G3 diff for the module namespace exits 0' . ($code !== 0 ? " — got {$code}: " . trim($out) : ''), $code === 0);
    check('G4 … wrote one migration into the module\'s res/migrations', count($generated) === 1);
    $source = $generated === [] ? '' : file_get_contents($generated[0]);
    check('G5 … namespace Z77\\Module\\Probe\\Migrations', str_contains($source, 'namespace Z77\\Module\\Probe\\Migrations;'));
    check('G6 … creates probe_posting with the money column as NUMERIC(15, 2) in our collation', str_contains($source, 'CREATE TABLE probe_posting') && str_contains($source, 'amount NUMERIC(15, 2) NOT NULL') && str_contains($source, 'utf8mb4_unicode_ci'));
    check('G7 … proposes nothing for number_range and nothing for the metadata table', !str_contains($source, 'number_range') && !str_contains($source, MigrationsApplication::STORAGE_TABLE));
    [$code, $out] = $run(['command' => 'migrate']);
    check('G8 migrate applies the module migration', $code === 0 && in_array('probe_posting', $tables(), true));
    $col = $db->fetchAssociative('SELECT COLUMN_TYPE, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$dbName, 'probe_posting', 'amount']);
    check('G9 the money column is decimal(15,2)', ($col['COLUMN_TYPE'] ?? '') === 'decimal(15,2)');
    check('G10 two migrations recorded', (int) $db->fetchOne('SELECT COUNT(*) FROM ' . MigrationsApplication::STORAGE_TABLE) === 2);
    [$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Probe\\Migrations']);
    check('G11 DOCTRINE-TYPE-001: diff after migrate reports NO change — the money column included', $code !== 0 && str_contains($out, 'No changes detected') && count(glob($probeMigrations . '/Version*.php')) === 1);
    $db->executeStatement('CREATE TABLE stray (id INT NOT NULL, PRIMARY KEY (id))');
    [$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Probe\\Migrations']);
    $strayDraft = array_values(array_diff(glob($probeMigrations . '/Version*.php'), $generated));
    check('G11b DOCTRINE-MIG-001: a table no entity maps is proposed for DROP (review the draft)', $code === 0 && count($strayDraft) === 1 && str_contains(file_get_contents($strayDraft[0]), "DROP TABLE stray") && !str_contains(file_get_contents($strayDraft[0]), MigrationsApplication::STORAGE_TABLE));
    foreach ($strayDraft as $draft) { unlink($draft); }
    $db->executeStatement('DROP TABLE stray');
    [$code, $out] = $run(['command' => 'status']);
    check('G12 status: two executed, none new', $code === 0 && preg_match('/\| Executed\s+\|\s+2\s+\|/', $out) === 1 && preg_match('/\| New\s+\|\s+0\s+\|/', $out) === 1);
    $moduleVersion = 'Z77\\Module\\Probe\\Migrations\\' . basename($generated[0], '.php');
    check('G13 order is chronological across namespaces: the module migration (newer timestamp) is current and latest, the package one previous',
        preg_match('/\| Current\s+\|\s+' . preg_quote($moduleVersion, '/') . '\s+\|/', $out) === 1
        && preg_match('/\| Latest\s+\|\s+' . preg_quote($moduleVersion, '/') . '\s+\|/', $out) === 1
        && preg_match('/\| Previous\s+\|\s+' . preg_quote(MigrationDirectories::PACKAGE_NAMESPACE . '\\Version20260921000000', '/') . '\s+\|/', $out) === 1);

    // ── H. generate: an empty migration for hand-written DDL ─────────────────

    echo "H. Generate\n";
    [$code, $out] = $run(['command' => 'generate']);
    check('H1 generate without --namespace is refused as well', $code === 1 && count(glob($probeMigrations . '/Version*.php')) === 1 && glob(MigrationDirectories::packageDirectory() . '/Version2026092[2-9]*.php') === []);
    [$code, $out] = $run(['command' => 'generate', '--namespace' => 'Z77\\Module\\Probe\\Migrations']);
    $all = glob($probeMigrations . '/Version*.php');
    check('H2 generate writes an empty migration into the module directory', $code === 0 && count($all) === 2);

    echo "\n" . ($fail === 0 ? "PASS — {$pass} checks" : "FAIL — {$fail} of " . ($pass + $fail) . " checks") . "\n";
    exit($fail === 0 ? 0 : 1);
}
