<?php

/**
 * FileStorage atomicity harness (CLI, standalone — no framework bootstrap).
 *
 * Verifies the atomic write path of FileStorage::save() (tmp write + rename):
 *   - round-trip keeps the encoding invariant (UTF-8 without BOM, unescaped unicode)
 *   - rename() replaces an existing target (Windows MoveFileEx semantics)
 *   - a crash between tmp write and rename leaves the old, valid target intact
 *   - tmp leftovers are never read as data (list() globs *.json only)
 *   - a target held open by a reader beyond the retry window → fail-loud throw,
 *     old file stays valid, no tmp leftover
 *
 * Run: php tests/file-storage-atomicity.php
 * Uses a throwaway data directory in the system temp; removed on success.
 */

$work = sys_get_temp_dir().'/z77-filestorage-atomicity-'.getmypid();
define('ABS_BASE_PATH', $work);

require __DIR__.'/../packages/kernel/persistence/src/File/Storage/FileStorage.php';

use Z77\Persistence\File\Storage\FileStorage;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

function tmpLeftovers(string $dir): array
{
    return glob($dir.'/*.tmp') ?: [];
}

$storage = new FileStorage();
$dataDir = $work.'/data';

echo "1. round-trip + encoding invariant\n";
$storage->save('docs/first.json', ['title' => 'Übersetzungen', 'ok' => true]);
$raw = file_get_contents($dataDir.'/docs/first.json');
check('load() round-trips the data', $storage->load('docs/first.json') === ['title' => 'Übersetzungen', 'ok' => true]);
check('file has no UTF-8 BOM', substr($raw, 0, 3) !== "\xEF\xBB\xBF");
check('unicode is unescaped in the file', str_contains($raw, 'Übersetzungen'));
check('no tmp leftover after save', tmpLeftovers($dataDir.'/docs') === []);

echo "2. rename over an existing target (Windows replace semantics)\n";
$storage->save('docs/first.json', ['title' => 'Neu']);
check('second save replaces the content', $storage->load('docs/first.json') === ['title' => 'Neu']);
check('no tmp leftover after replace', tmpLeftovers($dataDir.'/docs') === []);

echo "3. crash between tmp write and rename\n";
// Simulate the crash by planting the half-written tmp file save() would leave behind.
file_put_contents($dataDir.'/docs/first.json.4711.deadbeef.tmp', '{"title": "half-writ');
check('target still loads the old, valid state', $storage->load('docs/first.json') === ['title' => 'Neu']);
check('list() ignores the tmp leftover', $storage->list('docs') === ['docs/first.json']);
unlink($dataDir.'/docs/first.json.4711.deadbeef.tmp');

echo "4. unencodable data → throw, target untouched\n";
$thrown = false;
try {
    $storage->save('docs/first.json', ['bad' => "\xB1\x31"]);   // invalid UTF-8
} catch (RuntimeException $e) {
    $thrown = true;
}
check('save() throws on unencodable data', $thrown);
check('target keeps the old state', $storage->load('docs/first.json') === ['title' => 'Neu']);
check('no tmp leftover after encode failure', tmpLeftovers($dataDir.'/docs') === []);

echo "5. target locked beyond the retry window → fail-loud\n";
// A same-process read handle holds the target for the whole retry loop
// (single-threaded), forcing the Windows sharing violation to persist.
$handle = fopen($dataDir.'/docs/first.json', 'rb');
$thrown = false;
try {
    $storage->save('docs/first.json', ['title' => 'Blockiert']);
} catch (RuntimeException $e) {
    $thrown = true;
}
fclose($handle);
if (PHP_OS_FAMILY === 'Windows') {
    check('save() throws while the target is held open', $thrown);
    check('target keeps the old, valid state', $storage->load('docs/first.json') === ['title' => 'Neu']);
} else {
    // POSIX rename replaces regardless of open handles — no sharing violation.
    check('save() succeeds despite the open handle', !$thrown);
    check('target holds the new state', $storage->load('docs/first.json') === ['title' => 'Blockiert']);
}
check('no tmp leftover either way', tmpLeftovers($dataDir.'/docs') === []);

echo "\n{$pass} passed, {$fail} failed\n";

if ($fail === 0) {
    // clean up the throwaway directory only on success — keep evidence on failure
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($work);
}

exit($fail === 0 ? 0 : 1);
