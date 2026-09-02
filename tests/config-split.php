<?php

/**
 * Config-split harness (CLI) — the ADR-036 lookup order through every reader:
 *
 *   - ConfigLocator: config/vendor/ wins over config/client/ wins over the
 *     legacy flat config/ — the search order IS the authority order;
 *   - ConfigManager::getBaseConfig('config/X') resolves through the split,
 *     falls back to a flat legacy file, and still throws (with the searched
 *     locations named) when nothing exists;
 *   - FileFinder reads its own config from config/vendor/ (the error past
 *     that point proves the file was FOUND, not missing);
 *   - BackupService::fromProjectRoot prefers config/client/ over flat —
 *     path-based, because single-purpose binaries pass their own root.
 *
 * Run: php tests/config-split.php
 * Uses a throwaway base directory in the system temp; removed on success.
 */

$work = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-config-split-' . getmypid();
define('ABS_BASE_PATH', $work);

spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Core\\'        => __DIR__ . '/../packages/kernel/core/src/',
        'Z77\\Shared\\'      => __DIR__ . '/../packages/kernel/shared/src/',
        'Z77\\Persistence\\' => __DIR__ . '/../packages/kernel/persistence/src/',
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

use Z77\Core\Libraries\CacheManager;
use Z77\Core\Libraries\ConfigManager;
use Z77\Core\Libraries\FileFinder;
use Z77\Shared\Backup\BackupService;
use Z77\Shared\Libraries\ConfigLocator;

$failures = 0;
$check = function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? 'OK  ' : 'FAIL') . "  $label\n";
    if (!$ok) {
        $failures++;
    }
};

$write = function (string $rel, string $php) use ($work): void {
    $path = $work . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $php);
};

// ── A. ConfigLocator order ───────────────────────────────────────────────────

$check('A1 nothing anywhere → null', ConfigLocator::path('nothing.inc.php') === null);

$write('config/probe.inc.php', "<?php return ['tier' => 'flat'];");
$check('A2 flat legacy file is found', str_ends_with((string) ConfigLocator::path('probe.inc.php'), '/config/probe.inc.php'));

$write('config/client/probe.inc.php', "<?php return ['tier' => 'client'];");
$check('A3 client shadows flat', str_contains((string) ConfigLocator::path('probe.inc.php'), '/config/client/'));

$write('config/vendor/probe.inc.php', "<?php return ['tier' => 'vendor'];");
$check('A4 vendor shadows client', str_contains((string) ConfigLocator::path('probe.inc.php'), '/config/vendor/'));

// ── B. ConfigManager::getBaseConfig through the split ────────────────────────

$cacheManager  = new CacheManager();
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'tpl'], 'namespaces' => []];");
$configManager = new ConfigManager(new FileFinder($cacheManager), $cacheManager);

$check('B1 vendor tier resolves (generated shape)',
    $configManager->getBaseConfig('config/probe', cachePersist: false)->get('tier') === 'vendor');

$write('config/client/i18n.inc.php', "<?php return ['defaultLanguage' => 'de'];");
$check('B2 client tier resolves (seed-once shape)',
    $configManager->getBaseConfig('config/i18n', cachePersist: false)->get('defaultLanguage') === 'de');

$write('config/legacyOnly.inc.php', "<?php return ['ok' => true];");
$check('B3 flat legacy fallback resolves',
    $configManager->getBaseConfig('config/legacyOnly', cachePersist: false)->get('ok') === true);

$threw = '';
try {
    $configManager->getBaseConfig('config/absent', cachePersist: false);
} catch (RuntimeException $e) {
    $threw = $e->getMessage();
}
$check('B4 missing config still throws', $threw !== '');
$check('B5 … naming the searched tiers', str_contains($threw, 'config/vendor'));

$check('B6 throwError:false → empty config, no throw',
    $configManager->getBaseConfig('config/absent', throwError: false, cachePersist: false)->isEmpty());

// ── C. FileFinder reads its own config from config/vendor/ ───────────────────

$threw = '';
try {
    (new FileFinder(new CacheManager()))->getFirstSourceMatch('x.php', 'No\\Such\\Namespace\\');
} catch (RuntimeException $e) {
    $threw = $e->getMessage();
}
$check('C1 fileFinder.inc.php found in vendor tier (error is about the namespace, not a missing config)',
    $threw !== '' && !str_contains($threw, 'Missing config file'));

// ── D. BackupService::fromProjectRoot prefers client ─────────────────────────

$write('config/backup.inc.php', "<?php return ['dir' => 'flat-backup'];");
$svc = BackupService::fromProjectRoot($work);
$check('D1 flat legacy backup config read', (new ReflectionProperty($svc, 'config'))->getValue($svc)['dir'] === 'flat-backup');

$write('config/client/backup.inc.php', "<?php return ['dir' => 'client-backup'];");
$svc = BackupService::fromProjectRoot($work);
$check('D2 client tier shadows flat', (new ReflectionProperty($svc, 'config'))->getValue($svc)['dir'] === 'client-backup');

// ── cleanup + result ─────────────────────────────────────────────────────────

$rm = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/*') ?: [] as $f) {
        is_dir($f) ? $rm($f) : unlink($f);
    }
    @rmdir($dir);
};
if ($failures === 0) {
    $rm($work);
}

echo $failures === 0 ? "\nALL GREEN\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
