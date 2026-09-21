<?php

/**
 * Entity announcement harness (CLI) — `ModuleManager::getDoctrineEntities()` /
 * `getImportEntities()` against a REAL ConfigManager + FileFinder on a
 * throwaway installation (package dirs under vendor/, a project override/).
 *
 * What is load-bearing here:
 *
 *   - a project announces a Doctrine entity of its OWN through
 *     `override/z77/module/{module}/src/App/Config/doctrineEntitiesConfig.inc.php`
 *     — a plain list, recorded as the deviation only, no copy of the module
 *     config (Rule 2, ADR-039 decision 5 stays: an explicit list, no scanning);
 *   - the result is the UNION of the module config's `doctrineEntities` and every
 *     extension file under the module's source paths, deduplicated;
 *   - a non-existent class in an extension file fails loudly, naming the file;
 *     a file that returns something other than a list fails the same way;
 *   - a project that copied the whole module config into override/ keeps
 *     working (the first-match rule for the module config is unchanged);
 *   - `importEntities` takes the same path (`importEntitiesConfig.inc.php`);
 *   - so does the open-work registry (`OpenWorkChecks::fromModules()`), through
 *     `openWorkChecksConfig.inc.php` returning scope => list of check classes:
 *     unioned with the module config's `openWorkChecks`, deduplicated per
 *     scope; a bad shape or a bad class fails naming module and file.
 *
 * Run: php tests/entity-announcement.php
 * Needs nothing but PHP: no database, no vendor/. The throwaway base directory
 * in the system temp is removed at the end.
 */

namespace {
    // Registered before the fixtures: the check classes below implement an
    // interface that has to be autoloaded when they are declared.
    spl_autoload_register(static function (string $class): void {
        $map = [
            'Z77\\Core\\'   => __DIR__ . '/../packages/kernel/core/src/',
            'Z77\\Shared\\' => __DIR__ . '/../packages/kernel/shared/src/',
            // only the open-work registry is loaded from this package — it needs no Doctrine
            'Z77\\Persistence\\Doctrine\\OpenWork\\' => __DIR__ . '/../packages/persistence-doctrine/src/OpenWork/',
        ];
        foreach ($map as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        }
    });
}

namespace Z77Test\Announce {
    use Z77\Persistence\Doctrine\OpenWork\Finding;
    use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;

    class PackageEntity {}
    class ProjectEntity {}
    class CopiedEntity {}
    class ImportableEntity {}

    class PackageCheck implements OpenWorkCheckInterface
    {
        public function check(string $scope, array $parameters): iterable { yield Finding::blocking('package'); }
    }
    class ProjectCheck implements OpenWorkCheckInterface
    {
        public function check(string $scope, array $parameters): iterable { yield Finding::warning('project'); }
    }
    class NotACheck {}
}

namespace {

    use Z77\Core\DI;
    use Z77\Core\Libraries\CacheManager;
    use Z77\Core\Libraries\ConfigManager;
    use Z77\Core\Libraries\FileFinder;
    use Z77\Core\Services\ModuleManager;
    use Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks;
    use Z77Test\Announce\CopiedEntity;
    use Z77Test\Announce\ImportableEntity;
    use Z77Test\Announce\NotACheck;
    use Z77Test\Announce\PackageCheck;
    use Z77Test\Announce\PackageEntity;
    use Z77Test\Announce\ProjectCheck;
    use Z77Test\Announce\ProjectEntity;

    $base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-entity-announcement-' . getmypid();
    define('ABS_BASE_PATH', $base);
    define('DEBUG', false);

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

    $write = function (string $rel, string $php) use ($base): void {
        $path = $base . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $php);
    };
    $rm = function (string $dir) use (&$rm): void {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (basename($f) === '.' || basename($f) === '..') { continue; }
            is_dir($f) ? $rm($f) : @unlink($f);
        }
        @rmdir($dir);
    };
    register_shutdown_function(static fn() => $rm($base));

    /** A module config file returning $config (package or override). */
    $moduleConfig = fn(array $config): string => '<?php return ' . var_export($config, true) . ';';
    /** An extension file: a plain list of classes. */
    $extension = fn(array $classes): string => '<?php return ' . var_export($classes, true) . ';';

    /**
     * The installer-generated configs for $modules (key → package dir), with the
     * project's override dir searched first — exactly the shape Install writes.
     */
    $installation = function (array $modules) use ($write): void {
        $namespaces = '';
        $entries    = '';
        foreach ($modules as $key) {
            $ns = 'Z77\\\\Module\\\\' . ucfirst($key) . '\\\\';
            $namespaces .= "'{$ns}' => ['sourcePaths' => [\$baseDir.'override/z77/module/{$key}', \$baseDir.'vendor/z77/module-{$key}']],\n";
            $entries    .= "'{$key}' => [],\n";
        }
        $write('config/vendor/fileFinder.inc.php', "<?php \$baseDir = ABS_BASE_PATH.'/';\nreturn ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
        $write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => '{$modules[0]}', 'modules' => [\n{$entries}]];");
    };

    /** A fresh DI + ModuleManager: nothing survives from the previous scenario (no APCu flush happens). */
    $boot = function (): ModuleManager {
        DI::getInstance(true)
            ->set('CacheManager', CacheManager::class, true)
            ->set('FileFinder', fn($c) => new FileFinder($c->get('CacheManager')), true)
            ->set('ConfigManager', fn($c) => new ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
            ->set('ModuleManager', fn($c) => new ModuleManager($c->get('ConfigManager')), true)
        ;
        return DI::getModuleManager();
    };

    // ── package side: module "ledger" announces one entity, module "shop" too ─

    $write('vendor/z77/module-ledger/src/App/Config/ledgerConfig.inc.php', $moduleConfig([
        'doctrineEntities' => [PackageEntity::class],
        'importEntities'   => [],
    ]));
    $write('vendor/z77/module-shop/src/App/Config/shopConfig.inc.php', $moduleConfig([
        'doctrineEntities' => [],
    ]));
    $installation(['ledger', 'shop']);

    // ── A. no extension anywhere: the module config alone, as before ─────────

    echo "A. Module config only\n";
    check('A1 package list', $boot()->getDoctrineEntities() === [PackageEntity::class]);

    // ── B. the project adds an entity of its own through the extension file ──

    echo "B. Project extension file\n";
    $write('override/z77/module/ledger/src/App/Config/doctrineEntitiesConfig.inc.php', $extension([
        ProjectEntity::class,
        PackageEntity::class,   // repeated on purpose: must be deduplicated
    ]));
    $mm = $boot();
    check('B1 union of package list and extension, package first', $mm->getDoctrineEntities() === [PackageEntity::class, ProjectEntity::class]);
    check('B2 the duplicate appears once', count($mm->getDoctrineEntities()) === count(array_unique($mm->getDoctrineEntities())));
    check('B3 the module config itself is untouched (no copy needed)', $mm->getModuleConfig('ledger')->get('doctrineEntities') === [PackageEntity::class]);

    // ── C. a package may ship an extension file as well; all files count ────

    echo "C. Every source path contributes\n";
    $write('vendor/z77/module-shop/src/App/Config/doctrineEntitiesConfig.inc.php', $extension([CopiedEntity::class]));
    check('C1 the shop package\'s own extension file is read too', $boot()->getDoctrineEntities() === [PackageEntity::class, ProjectEntity::class, CopiedEntity::class]);
    @unlink($base . '/vendor/z77/module-shop/src/App/Config/doctrineEntitiesConfig.inc.php');

    // ── D. a project that copied the whole module config keeps working ───────

    echo "D. Full-copy override config\n";
    $write('override/z77/module/shop/src/App/Config/shopConfig.inc.php', $moduleConfig([
        'doctrineEntities' => [CopiedEntity::class],
    ]));
    $mm = $boot();
    check('D1 the override config replaces the package config (first match)', $mm->getModuleConfig('shop')->get('doctrineEntities') === [CopiedEntity::class]);
    check('D2 … and its list is collected next to the ledger extension', $mm->getDoctrineEntities() === [PackageEntity::class, ProjectEntity::class, CopiedEntity::class]);

    // ── E. a bad extension fails loudly, naming module and file ──────────────

    echo "E. Bad extension\n";
    $badFile = 'override/z77/module/ledger/src/App/Config/doctrineEntitiesConfig.inc.php';
    $write($badFile, $extension([ProjectEntity::class, 'No\\Such\\Entity']));
    $msg = thrown(fn() => $boot()->getDoctrineEntities(), \RuntimeException::class);
    check('E1 a non-existent class throws', $msg !== null);
    check('E2 … naming the key and the module', str_contains((string) $msg, "doctrineEntities extension of module 'ledger'"));
    check('E3 … the file', str_contains((string) $msg, $badFile));
    check('E4 … and the class', str_contains((string) $msg, 'No\\\\Such\\\\Entity'));

    $write($badFile, "<?php return ['entity' => " . var_export(ProjectEntity::class, true) . "];");
    $msg = thrown(fn() => $boot()->getDoctrineEntities(), \RuntimeException::class);
    check('E5 a map instead of a list is refused, naming the file', $msg !== null && str_contains($msg, 'must return a list') && str_contains($msg, $badFile));

    $write($badFile, '<?php return null;');
    check('E6 a file returning no array is refused', thrown(fn() => $boot()->getDoctrineEntities(), \RuntimeException::class) !== null);

    $write('vendor/z77/module-ledger/src/App/Config/ledgerConfig.inc.php', $moduleConfig(['doctrineEntities' => ['No\\Such\\Package']]));
    @unlink($base . '/' . $badFile);
    $msg = thrown(fn() => $boot()->getDoctrineEntities(), \RuntimeException::class);
    check('E7 a bad class in the module config still names the module config', $msg !== null && str_contains($msg, "doctrineEntities of module 'ledger'"));
    $write('vendor/z77/module-ledger/src/App/Config/ledgerConfig.inc.php', $moduleConfig([
        'doctrineEntities' => [PackageEntity::class],
        'importEntities'   => [],
    ]));

    // ── F. importEntities shares the code path; the two keys stay apart ──────

    echo "F. importEntities extension\n";
    $write('override/z77/module/ledger/src/App/Config/importEntitiesConfig.inc.php', $extension([ImportableEntity::class]));
    $mm = $boot();
    check('F1 importEntities extension read', $mm->getImportEntities() === [ImportableEntity::class]);
    check('F2 … and not mixed into doctrineEntities', !in_array(ImportableEntity::class, $mm->getDoctrineEntities(), true));

    // ── G. openWorkChecks: the same extension mechanism, scope => list ───────

    echo "G. openWorkChecks extension\n";
    $ledgerConfig = fn(array $openWork): string => $moduleConfig([
        'doctrineEntities' => [PackageEntity::class],
        'importEntities'   => [],
        'openWorkChecks'   => $openWork,
    ]);
    $write('vendor/z77/module-ledger/src/App/Config/ledgerConfig.inc.php', $ledgerConfig(['period-close' => [PackageCheck::class]]));
    $closing = OpenWorkChecks::fromModules($boot())->ask('period-close');
    check('G1 module config only: the package check answers', count($closing->blocking()) === 1 && $closing->warnings() === []);

    $owFile = 'override/z77/module/ledger/src/App/Config/openWorkChecksConfig.inc.php';
    $write($owFile, $moduleConfig([
        'period-close' => [ProjectCheck::class, PackageCheck::class],   // PackageCheck repeated on purpose: deduplicated per scope
        'stocktake'    => [ProjectCheck::class],
    ]));
    $mm       = $boot();
    $registry = OpenWorkChecks::fromModules($mm);
    $closing  = $registry->ask('period-close');
    check('G2 union per scope, the repeated check asked once', count($closing->blocking()) === 1 && count($closing->warnings()) === 1);
    check('G3 module config first, then the extension', $closing->blocking()[0]->message === 'package' && $closing->warnings()[0]->message === 'project');
    check('G4 a scope only the extension names', count($registry->ask('stocktake')->warnings()) === 1);
    check('G5 the module config itself is untouched (no copy needed)', $mm->getModuleConfig('ledger')->get('openWorkChecks') === ['period-close' => [PackageCheck::class]]);
    check('G6 the openWorkChecks extension does not leak into doctrineEntities', !in_array(ProjectCheck::class, $mm->getDoctrineEntities(), true));

    $write('vendor/z77/module-shop/src/App/Config/openWorkChecksConfig.inc.php', $moduleConfig(['stocktake' => [PackageCheck::class]]));
    check('G7 a package extension file of another module is read too', OpenWorkChecks::fromModules($boot())->ask('stocktake')->isBlocked());
    @unlink($base . '/vendor/z77/module-shop/src/App/Config/openWorkChecksConfig.inc.php');

    echo "G. … a bad extension fails loudly, naming module and file\n";
    $write($owFile, $moduleConfig(['period-close' => ['No\\Such\\Check']]));
    $msg = (string) thrown(fn() => OpenWorkChecks::fromModules($boot()), \RuntimeException::class);
    check('G8 non-existent class: names key, module, file and scope', str_contains($msg, "openWorkChecks extension of module 'ledger'")
        && str_contains($msg, $owFile) && str_contains($msg, "scope 'period-close'"));
    $write($owFile, $moduleConfig(['period-close' => [NotACheck::class]]));
    $msg = (string) thrown(fn() => OpenWorkChecks::fromModules($boot()), \RuntimeException::class);
    check('G9 a class that is not a check, naming the file', str_contains($msg, 'does not implement') && str_contains($msg, $owFile));
    $write($owFile, $moduleConfig([ProjectCheck::class]));
    $msg = (string) thrown(fn() => OpenWorkChecks::fromModules($boot()), \RuntimeException::class);
    check('G10 a flat list instead of scope => classes, naming the file', str_contains($msg, 'scope => [check classes]') && str_contains($msg, $owFile));
    $write($owFile, $moduleConfig(['period-close' => ProjectCheck::class]));
    $msg = (string) thrown(fn() => OpenWorkChecks::fromModules($boot()), \RuntimeException::class);
    check('G11 a class instead of a list, naming the file', str_contains($msg, 'list of check classes') && str_contains($msg, $owFile));
    $write($owFile, '<?php return null;');
    $msg = (string) thrown(fn() => OpenWorkChecks::fromModules($boot()), \RuntimeException::class);
    check('G12 a file returning no array, naming the file', str_contains($msg, 'must return an array') && str_contains($msg, $owFile));
    @unlink($base . '/' . $owFile);

    $write('vendor/z77/module-ledger/src/App/Config/ledgerConfig.inc.php', $ledgerConfig(['period-close' => [NotACheck::class]]));
    $msg = (string) thrown(fn() => OpenWorkChecks::fromModules($boot()), \RuntimeException::class);
    check('G13 a bad module-config entry still names the module config, not a file', str_contains($msg, "openWorkChecks of module 'ledger'")
        && !str_contains($msg, 'extension'));

    echo "\n" . ($fail === 0 ? "PASS — {$pass} checks" : "FAIL — {$fail} of " . ($pass + $fail) . " checks") . "\n";
    exit($fail === 0 ? 0 : 1);
}
