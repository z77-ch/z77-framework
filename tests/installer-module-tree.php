<?php

/**
 * Installer module-tree harness (CLI) — INST-MODULETREE-001.
 *
 * What is load-bearing here: the directories a NEW project gets per module
 * (`skeleton/composer.json` → `extra.directories.moduleTree`) follow the layout
 * the packages actually use — `src/Entities`, `src/Repositories`,
 * `src/Validators`, `src/Services`, `src/App/Config`, `src/Ui/Controllers`
 * (create-module.md). Until 2026-11-15 the skeleton created `src/Dom/…` and
 * `src/App/Services`, folders no package uses: an override placed there would
 * never map onto a framework class.
 *
 * The config is read from the real skeleton file, merged the way
 * Install::loadConfig() merges it, and fed to the real createModuleTree().
 *
 * Run: php tests/installer-module-tree.php
 */

// ── Composer stubs ─────────────────────────────────────────────────────────
// composer/composer is not a dependency of the framework (the installer runs
// INSIDE Composer), so the types Install.php declares are stubbed here.
namespace Composer {
    class Composer {}
}
namespace Composer\Script {
    class Event {}
}
namespace Composer\IO {
    interface IOInterface
    {
        public function write($messages, $newline = true, $verbosity = 0);
        public function writeError($messages, $newline = true, $verbosity = 0);
        public function isInteractive();
        public function askConfirmation($question, $default = true);
        public function askAndHideAnswer($question);
    }
}

namespace {

use Composer\IO\IOInterface;
use Z77\Core\Installer\Install;

$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));

require $root . '/packages/kernel/core/src/Installer/Install.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

final class FakeIo implements IOInterface
{
    public array $lines = [];
    public function write($messages, $newline = true, $verbosity = 0)
    {
        foreach ((array) $messages as $message) {
            $this->lines[] = (string) $message;
        }
    }
    public function writeError($messages, $newline = true, $verbosity = 0) { $this->write($messages); }
    public function isInteractive() { return false; }
    public function askConfirmation($question, $default = true) { return $default; }
    public function askAndHideAnswer($question) { return ''; }
}

function rmTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

$composer = json_decode((string) file_get_contents($root . '/skeleton/composer.json'), true);
check('skeleton/composer.json parses', is_array($composer));
$extra    = $composer['extra'] ?? [];
$tree     = $extra['directories']['moduleTree'] ?? [];
check('skeleton declares a moduleTree', $tree !== []);

$tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-module-tree-' . getmypid();
rmTree($tmp);
mkdir($tmp, 0777, true);

try {
    $install = (new ReflectionClass(Install::class))->newInstanceWithoutConstructor();
    $set = static function (string $name, mixed $value) use ($install): void {
        (new ReflectionProperty(Install::class, $name))->setValue($install, $value);
    };
    $defaults = require $root . '/packages/kernel/core/src/Config/bootstrap.default.inc.php';
    $set('io', new FakeIo());
    $set('baseDir', $tmp);
    $set('bootstrapConfig', array_merge($defaults, $extra['core-bootstrap'] ?? []));
    $set('frameworkPrefix', $extra['core-module-manager']['frameworkPrefix'] ?? 'Z77');
    $set('modulePrefix', $extra['core-module-manager']['modulePrefix'] ?? 'Module');
    $set('additionalPsr4Paths', $composer['autoload']['psr-4'] ?? []);

    $replacements = (new ReflectionMethod(Install::class, 'buildReplacements'))->invoke($install);
    (new ReflectionMethod(Install::class, 'createModuleTree'))->invoke($install, $tree, $replacements);

    echo "1. every skeleton module gets the package layout\n";
    $modules = ['frontend', 'backend', 'dms'];
    $wanted  = ['src/Ui/Controllers', 'src/App/Config', 'src/Entities', 'src/Repositories',
                'src/Validators', 'src/Services', 'res/view/templates'];
    foreach ($modules as $module) {
        foreach ($wanted as $sub) {
            $dir = "{$tmp}/override/z77/module/{$module}/{$sub}";
            check("{$module}: {$sub}", is_dir($dir));
        }
    }

    echo "2. no folder the packages do not use\n";
    foreach ($modules as $module) {
        $base = "{$tmp}/override/z77/module/{$module}/src";
        check("{$module}: no src/Dom", !is_dir("{$base}/Dom"));
        check("{$module}: no src/App/Services", !is_dir("{$base}/App/Services"));
    }

    echo "3. the tree mirrors directories that exist in the real packages\n";
    // Each domain dir of the tree must exist in at least one shipped package —
    // the guard against the skeleton drifting from the convention again.
    $flat = [];
    array_walk_recursive($tree, static function (string $path) use (&$flat): void { $flat[] = $path; });
    foreach ($flat as $path) {
        if (!str_contains($path, '/src/')) {
            continue;
        }
        $sub   = substr($path, strpos($path, '/src/') + 1);
        $found = glob($root . '/packages/module-*/' . $sub, GLOB_ONLYDIR) ?: [];
        check("{$sub} exists in a shipped module", $found !== []);
    }

    echo "4. an installed module without a psr-4 entry is warned about (INST-MODULE-UNREGISTERED-001)\n";
    $ns = static fn (string $m): string => 'Z77' . chr(92) . 'Module' . chr(92) . $m . chr(92);
    $io = new FakeIo();
    $set('io', $io);
    $set('z77Modules', []);
    $set('additionalPsr4Paths', [$ns('Dms') => ['override/z77/module/dms/src/']]);
    $report  = new ReflectionMethod(Install::class, 'reportUnregisteredModules');
    $missing = $report->invoke($install, [$ns('Dms'), $ns('Article'), $ns('Vat')]);
    $out     = implode("\n", $io->lines);
    check('reports exactly the two unregistered modules', $missing === [$ns('Article'), $ns('Vat')], json_encode($missing));
    check('output starts with WARNING', str_contains($out, 'WARNING'));
    $line = '"' . str_replace(chr(92), chr(92) . chr(92), $ns('Article')) . '": ["override/z77/module/article/src/"],';
    check('prints the pasteable psr-4 line', str_contains($out, $line), $out);
    check('registered module is not listed', !str_contains($out, 'Dms'));

    $io2 = new FakeIo();
    $set('io', $io2);
    $set('z77Modules', []);
    check('all registered: no warning, empty result',
        $report->invoke($install, [$ns('Dms')]) === [] && $io2->lines === []);

    echo "5. the dev server binds IPv4 explicitly (Windows php -S localhost listens on [::1] only)\n";
    $devScript = implode("\n", (array) ($composer['scripts']['dev'] ?? []));
    check('scripts.dev serves 127.0.0.1:8080', str_contains($devScript, 'php -S 127.0.0.1:8080 -t public'), $devScript);
    check('scripts.dev has no localhost bind', !str_contains($devScript, 'php -S localhost'));
} finally {
    rmTree($tmp);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

}
