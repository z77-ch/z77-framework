<?php

/**
 * Installer starter-content harness (CLI) — owner decision 2026-10-22.
 *
 * What is load-bearing here:
 *
 *   - a FRESH installation (no data/content/) gets every starter document of
 *     the packages, German and English, as `<slug>.<lang>.json`;
 *   - once data/content/ exists, no starter document is written again: a page
 *     the project deleted does not come back on the next `composer install`,
 *     and an existing installation does not receive new starter pages (it is
 *     named once, not per file);
 *   - every other data seed keeps the per-file seed-once rule (a missing
 *     dictionary is still added, an existing one is never touched).
 *
 * Run: php tests/installer-starter-content.php
 */

// ── Composer stubs (see tests/installer-asset-publish.php) ─────────────────
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
    public function out(): string { return implode("\n", $this->lines); }
}

function rmTree(string $dir): void
{
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') { continue; }
        $path = $dir . '/' . $item;
        is_dir($path) ? rmTree($path) : @unlink($path);
    }
    @rmdir($dir);
}

/** Runs the data-seed step against the REAL kernel data root. */
function seed(string $project, string $dataRoot): FakeIo
{
    $io      = new FakeIo();
    $install = (new ReflectionClass(Install::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Install::class, 'io'))->setValue($install, $io);
    (new ReflectionProperty(Install::class, 'baseDir'))->setValue($install, $project);
    (new ReflectionMethod(Install::class, 'writeDataFilesFrom'))->invoke($install, [$dataRoot]);
    return $io;
}

$dataRoot = realpath($root . '/packages/kernel/core/data');
$project  = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-starter-content-' . getmypid();
$shipped  = array_map(
    static fn(string $f): string => substr(basename($f), 0, -strlen('.default.json')) . '.json',
    glob($dataRoot . '/content/*.default.json')
);
sort($shipped);

echo "fresh install\n";
rmTree($project);
mkdir($project, 0777, true);
seed($project, $dataRoot);
$written = array_map('basename', glob($project . '/data/content/*.json'));
sort($written);
check('every starter document is written', $written === $shipped && $shipped !== [], implode(',', $written));
check('German and English, no French', in_array('home.de.json', $written, true) && in_array('home.en.json', $written, true)
    && preg_grep('/\.fr\.json$/', $written) === []);
check('the copy is byte-identical to the seed', file_get_contents($project . '/data/content/home.de.json')
    === file_get_contents($dataRoot . '/content/home.de.default.json'));
check('other seeds too (navigation, dictionaries)', is_file($project . '/data/framework/routing/navigation.json')
    && is_file($project . '/data/framework/i18n/en.json'));

echo "the project deletes a page, then updates\n";
unlink($project . '/data/content/about.de.json');
unlink($project . '/data/content/about.en.json');
unlink($project . '/data/framework/i18n/en.json');
$io = seed($project, $dataRoot);
check('the deleted page does not come back', !is_file($project . '/data/content/about.de.json') && !is_file($project . '/data/content/about.en.json'));
check('named once', substr_count($io->out(), 'starter content data/content/') === 1, $io->out());
check('a missing dictionary is still added (per-file seed-once)', is_file($project . '/data/framework/i18n/en.json'));

echo "an existing installation with its own content\n";
rmTree($project);
mkdir($project . '/data/content', 0777, true);
file_put_contents($project . '/data/content/home.fr.json', '{"slug":"home","language":"fr"}');
seed($project, $dataRoot);
check('receives no starter document', array_map('basename', glob($project . '/data/content/*.json')) === ['home.fr.json']);
check('its own document is untouched', file_get_contents($project . '/data/content/home.fr.json') === '{"slug":"home","language":"fr"}');

rmTree($project);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

}
