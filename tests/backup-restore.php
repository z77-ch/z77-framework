<?php

/**
 * Restore harness (CLI) — everything about `z77-restore` that can be decided
 * without a database: which source is accepted, which dump is taken out of an
 * archive, and what the service refuses.
 *
 * The database step itself is the one thing NOT covered here: it needs a
 * running MariaDB and a `mysql` binary, so {@see DbRestorerInterface} is faked
 * and the harness asserts WHAT it was handed (file contents, drop flag). The
 * safety backup is switched off for the same reason — it shells out to
 * mysqldump.
 *
 * Run: php tests/backup-restore.php
 */

spl_autoload_register(static function (string $class): void {
    $map = ['Z77\\Shared\\' => __DIR__ . '/../packages/kernel/shared/src/'];
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

use Z77\Shared\Backup\DbRestorerInterface;
use Z77\Shared\Backup\RestoreService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

/** Fails the check instead of the harness when a throw was expected. */
function throws(string $label, callable $fn, string $needle = ''): void
{
    try {
        $fn();
        check($label . ' (throws)', false);
    } catch (\RuntimeException $e) {
        check($label . ' (throws)', $needle === '' || str_contains($e->getMessage(), $needle));
    }
}

final class FakeRestorer implements DbRestorerInterface
{
    public ?string $sql  = null;
    public ?bool   $drop = null;
    public ?string $binary = null;

    public function restore(#[\SensitiveParameter] array $dbConfig, string $sqlFile, bool $dropExisting = true): void
    {
        $this->sql    = (string)file_get_contents($sqlFile);
        $this->drop   = $dropExisting;
        $this->binary = (string)($dbConfig['mysql'] ?? '');
    }
}

// ── a throw-away project root with a backup tree ───────────────────────────
$root = sys_get_temp_dir() . '/z77-restore-' . bin2hex(random_bytes(4));
mkdir($root . '/backup/db', 0777, true);
mkdir($root . '/backup/full', 0777, true);

$zip = static function (string $path, array $entries): void {
    $archive = new ZipArchive();
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $archive->addFromString($name, $content);
    }
    $archive->close();
};

$fullName = '2026-10-09_170846_full.zip';
$dbName   = '2026-10-09_170900_db.zip';

// A full archive as BackupService writes it since 2026-10-09: the tree plus the
// dump — and a second .sql somewhere in the tree, which must NOT win.
$zip($root . '/backup/full/' . $fullName, [
    'database/z77ch.sql'     => "-- the dump\nCREATE TABLE a (i INT);\n",
    'data/framework/x.json'  => '{}',
    'override/legacy/old.sql' => "-- not the dump\n",
]);
// A db archive: one .sql at the root, named after the archive.
$zip($root . '/backup/db/' . $dbName, ['2026-10-09_170900_db.sql' => "-- db type\nCREATE TABLE b (i INT);\n"]);

$service = static function (string $root, ?FakeRestorer $restorer, string $database = 'z77ch'): RestoreService {
    return new RestoreService($root, ['dump' => ['mysql' => 'mysql']], ['name' => $database], $restorer);
};

echo "\nsource resolution\n";
$plain = $service($root, null);
check('archive name resolves inside the backup root', $plain->resolveSource($fullName) === str_replace('\\', '/', $root) . '/backup/full/' . $fullName);
throws('an archive name that is not there', static fn() => $plain->resolveSource('2026-01-01_000000_full.zip'), 'not found in the backup root');
throws('a path outside the pattern that does not exist', static fn() => $plain->resolveSource($root . '/nope.zip'), 'existing file');

file_put_contents($root . '/loose.sql', "-- loose\nCREATE TABLE c (i INT);\n");
file_put_contents($root . '/notes.txt', 'no');
check('a .sql path is accepted as itself', $plain->resolveSource($root . '/loose.sql') === str_replace('\\', '/', $root) . '/loose.sql');
throws('a file that is neither .zip nor .sql', static fn() => $plain->resolveSource($root . '/notes.txt'), 'neither a .zip');
throws('an empty source', static fn() => $plain->resolveSource('  '), 'no source given');

echo "\nwhich dump is read\n";
$fake   = new FakeRestorer();
$result = $service($root, $fake)->restore($fullName, safetyBackup: false);
check('full archive: database/{name}.sql wins over a stray .sql', str_contains((string)$fake->sql, 'the dump'));
check('full archive: the label names the entry', $result['sql'] === 'database/z77ch.sql');
check('full archive: the dump size is reported', $result['bytes'] === strlen("-- the dump\nCREATE TABLE a (i INT);\n"));

$fake = new FakeRestorer();
$service($root, $fake)->restore($dbName, safetyBackup: false);
check('db archive: the single .sql entry is read', str_contains((string)$fake->sql, 'db type'));

$fake = new FakeRestorer();
$service($root, $fake)->restore($root . '/loose.sql', safetyBackup: false);
check('a .sql file is read as it is', str_contains((string)$fake->sql, 'loose'));

echo "\nflags and config\n";
$fake = new FakeRestorer();
$service($root, $fake)->restore($dbName, safetyBackup: false);
check('tables are dropped by default', $fake->drop === true);
$fake = new FakeRestorer();
$service($root, $fake)->restore($dbName, dropExisting: false, safetyBackup: false);
check('--keep-tables reaches the restorer', $fake->drop === false);
check('the client binary comes from the dump block', $fake->binary === 'mysql');

$fake = new FakeRestorer();
(new RestoreService($root, [], ['name' => 'z77ch'], $fake))->restore($dbName, safetyBackup: false);
check('a config without a dump block falls back to mysql', $fake->binary === 'mysql');

echo "\nrefusals\n";
throws(
    'no database configured',
    static fn() => $service($root, new FakeRestorer(), '')->restore($dbName, safetyBackup: false),
    'No database configured'
);

$zip($root . '/backup/full/2026-10-08_120000_full.zip', ['data/x.json' => '{}']);
throws(
    'an archive without any .sql',
    static fn() => $service($root, new FakeRestorer())->restore('2026-10-08_120000_full.zip', safetyBackup: false),
    'carries no .sql dump'
);

$zip($root . '/backup/full/2026-10-07_120000_full.zip', ['a/one.sql' => '-- 1', 'b/two.sql' => '-- 2']);
throws(
    'several .sql and none preferred',
    static fn() => $service($root, new FakeRestorer())->restore('2026-10-07_120000_full.zip', safetyBackup: false),
    'several .sql files'
);

$zip($root . '/backup/db/2026-10-06_120000_db.zip', ['2026-10-06_120000_db.sql' => '']);
throws(
    'an empty dump inside the archive',
    static fn() => $service($root, new FakeRestorer())->restore('2026-10-06_120000_db.zip', safetyBackup: false),
    'is empty'
);

echo "\nhousekeeping\n";
$before = glob(sys_get_temp_dir() . '/z77sql*') ?: [];
$service($root, new FakeRestorer())->restore($fullName, safetyBackup: false);
$after = glob(sys_get_temp_dir() . '/z77sql*') ?: [];
check('the extracted dump is removed again', count($after) <= count($before));

echo "\nlisting\n";
$rows = $plain->sources();
check('db and full archives are listed, newest first', $rows !== [] && $rows[0]['file'] === $dbName);
check('a data archive is not a restore source', !in_array('data', array_column($rows, 'type'), true));

// ── clean up ───────────────────────────────────────────────────────────────
$rm = static function (string $dir) use (&$rm): void {
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
        $path = $dir . '/' . $item;
        is_dir($path) ? $rm($path) : @unlink($path);
    }
    @rmdir($dir);
};
$rm($root);

echo "\n{$pass} ok, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
