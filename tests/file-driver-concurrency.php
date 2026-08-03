<?php

/**
 * File-driver concurrency harness (CLI, standalone — no framework bootstrap).
 *
 * Verifies FileStorage::withExclusiveLock(), the guard for every
 * read-merge-write cycle (CollectionStore::persistAll()/delete(),
 * FileEntityManager::reorder()):
 *   - no lost updates: N worker processes append rows through concurrent
 *     load → modify → save cycles under the lock; every row survives
 *   - a held lock times out loudly instead of hanging (RuntimeException)
 *   - the '<path>.lock' file is invisible to list() (globs *.json only)
 *
 * flock() itself is verified working on NTFS and the Z: NAS/SMB share.
 *
 * Run: php tests/file-driver-concurrency.php
 * Uses a throwaway data directory in the system temp; removed on success.
 * Re-invokes itself as `worker <base> <id> <cycles>` for the parallel writers.
 */

const DATA_FILE = 'concurrent/rows.json';

if (($argv[1] ?? '') === 'worker') {
    define('ABS_BASE_PATH', $argv[2]);
    require __DIR__.'/../packages/kernel/persistence/src/File/Storage/FileStorage.php';

    $storage = new Z77\Persistence\File\Storage\FileStorage();
    [, , , $workerId, $cycles] = $argv;

    for ($i = 0; $i < (int)$cycles; $i++) {
        $storage->withExclusiveLock(DATA_FILE, function () use ($storage, $workerId, $i): void {
            $data   = $storage->load(DATA_FILE);
            $data[] = ['worker' => $workerId, 'i' => $i];
            $storage->save(DATA_FILE, $data);
        });
    }
    exit(0);
}

$work = sys_get_temp_dir().'/z77-filedriver-concurrency-'.getmypid();
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

$storage = new FileStorage();
$dataDir = $work.'/data';

echo "1. no lost updates across processes\n";
$workers = 4;
$cycles  = 15;
$procs   = [];
for ($w = 0; $w < $workers; $w++) {
    $procs[] = proc_open(
        ['php', __FILE__, 'worker', $work, "w{$w}", (string)$cycles],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
}
$exitOk = true;
foreach ($procs as $proc) {
    $exitOk = proc_close($proc) === 0 && $exitOk;
}
check('all workers exited cleanly', $exitOk);

$rows = $storage->load(DATA_FILE);
$expected = $workers * $cycles;
check("every row survived ({$expected} concurrent read-merge-write cycles)", count($rows) === $expected);
$distinct = array_unique(array_map(fn($r) => $r['worker'].'#'.$r['i'], $rows));
check('no duplicate rows', count($distinct) === $expected);

echo "2. held lock → loud timeout, no hang\n";
$holder = fopen($dataDir.'/'.DATA_FILE.'.lock', 'c');
flock($holder, LOCK_EX);
$thrown  = false;
$started = microtime(true);
try {
    // second handle on the same lock file — flock is per-handle, so this
    // contends even in-process, exactly like a second PHP request would
    $storage->withExclusiveLock(DATA_FILE, fn() => null);
} catch (RuntimeException $e) {
    $thrown = true;
}
$waited = microtime(true) - $started;
flock($holder, LOCK_UN);
fclose($holder);
check('withExclusiveLock() throws while the lock is held', $thrown);
check('after polling the ~2s budget (no infinite block)', $waited > 1.0 && $waited < 10.0);

echo "3. lock file invisible to list()\n";
check('list() returns only the data file', $storage->list('concurrent') === [DATA_FILE]);
check('lock file exists on disk', is_file($dataDir.'/'.DATA_FILE.'.lock'));

echo "\n{$pass} passed, {$fail} failed\n";

if ($fail === 0) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($work);
}

exit($fail === 0 ? 0 : 1);
