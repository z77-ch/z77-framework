<?php

/**
 * ZipArchiver symlink harness (CLI) — the full-backup walk with and without
 * links in the tree.
 *
 * What is load-bearing here is the PAIR in ZipArchiver: the PATH-BASED
 * scandir()/is_dir() descent (which follows real dirs, symlinks and NTFS
 * junctions identically — the old RecursiveDirectoryIterator did not, and
 * FOLLOW_SYMLINKS does not help on Windows junctions) plus the realpath
 * visited set. Following alone loops forever on a cycle; the set alone
 * changes nothing. The release layout
 * (docs/01-handbook/release-structure.md) is the reason this exists: before,
 * a full backup of a release archived ONE code file and silently dropped the
 * whole linked data/ tree — no error, no hint.
 *
 * Links are created as symlink() where the platform allows it, and as an NTFS
 * junction (`mklink /J`) on Windows, where plain symlinks need privileges —
 * both are followed by path resolution the same way.
 *
 * Run: php tests/zip-archiver-symlinks.php
 * Uses a throwaway directory in the system temp; removed on success.
 */

$work = sys_get_temp_dir() . '/z77-zip-symlinks-' . getmypid();

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

use Z77\Shared\Backup\ZipArchiver;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

/** Directory link: symlink where allowed, NTFS junction on Windows. */
function makeDirLink(string $target, string $link): bool
{
    if (@symlink($target, $link)) {
        clearstatcache(true);
        return true;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        exec('cmd /c mklink /J "' . str_replace('/', '\\', $link) . '" "'
            . str_replace('/', '\\', $target) . '" 2>NUL', $out, $rc);
        clearstatcache(true);
        return $rc === 0;
    }
    return false;
}

/** @return list<string> entry names inside the archive, sorted */
function zipNames(string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['<unreadable>'];
    }
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();
    sort($names);

    return $names;
}

/**
 * True when the path is a link of ANY kind — symlink or NTFS junction.
 * ⚠️ Not is_link(): on Windows that answers false for junctions (and poisons
 * the stat cache for the next is_dir on the same path). realpath() differing
 * from the path itself is the reliable tell for both kinds.
 */
function isDirLink(string $p): bool
{
    $real = realpath($p);
    if ($real === false) {
        return true; // dangling link
    }
    $norm = static fn(string $s): string => strtolower(str_replace('\\', '/', $s));

    return $norm($real) !== $norm($p);
}

/** Removes a dir link itself (symlink: unlink, junction: rmdir) — never its target. */
function removeLink(string $p): void
{
    @unlink($p) || @rmdir($p);
    clearstatcache(true);
}

function rrm(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . '/' . $e;
        if (!is_dir($p) || isDirLink($p)) {
            // A file, or a link — remove the entry, never descend into a target.
            @unlink($p) || @rmdir($p);
            clearstatcache(true);
            continue;
        }
        rrm($p);
    }
    @rmdir($dir);
}

// ── fixture: a mini release layout plus a flat control tree ────────────────
//
//   shared/data/framework/member/accounts.json
//   shared/data/framework/jobs/queue.json        (excluded via data/framework/jobs)
//   shared/media/img/logo.webp
//   rel/override/Code.php
//   rel/data          -> shared/data
//   rel/public/media  -> shared/media
//   rel/lib/cache/x   (excluded via lib)
//   flat/…            same content, no links

@mkdir($work . '/shared/data/framework/member', 0777, true);
@mkdir($work . '/shared/data/framework/jobs', 0777, true);
@mkdir($work . '/shared/media/img', 0777, true);
@mkdir($work . '/rel/override', 0777, true);
@mkdir($work . '/rel/public', 0777, true);
@mkdir($work . '/rel/lib/cache', 0777, true);
file_put_contents($work . '/shared/data/framework/member/accounts.json', '{"a":1}');
file_put_contents($work . '/shared/data/framework/jobs/queue.json', '{}');
file_put_contents($work . '/shared/media/img/logo.webp', 'RIFF');
file_put_contents($work . '/rel/override/Code.php', '<?php');
file_put_contents($work . '/rel/lib/cache/x', 'scratch');

if (!makeDirLink($work . '/shared/data', $work . '/rel/data')
    || !makeDirLink($work . '/shared/media', $work . '/rel/public/media')) {
    echo "SKIP — cannot create directory links on this system\n";
    exit(0);
}

$fullExcludes = ['vendor', 'node_modules', 'backup', 'lib', 'data/framework/jobs'];
$expected     = [
    'data/framework/member/accounts.json',
    'override/Code.php',
    'public/media/img/logo.webp',
];

$a = new ZipArchiver();

echo "1. a flat tree behaves as before (no links anywhere)\n";
@mkdir($work . '/flat/data/framework/member', 0777, true);
@mkdir($work . '/flat/data/framework/jobs', 0777, true);
@mkdir($work . '/flat/public/media/img', 0777, true);
@mkdir($work . '/flat/override', 0777, true);
@mkdir($work . '/flat/lib/cache', 0777, true);
file_put_contents($work . '/flat/data/framework/member/accounts.json', '{"a":1}');
file_put_contents($work . '/flat/data/framework/jobs/queue.json', '{}');
file_put_contents($work . '/flat/public/media/img/logo.webp', 'RIFF');
file_put_contents($work . '/flat/override/Code.php', '<?php');
file_put_contents($work . '/flat/lib/cache/x', 'scratch');

$n = $a->zipDirectory($work . '/flat', $work . '/flat.zip', $fullExcludes);
check('three files packed',              $n === 3);
check('names as expected',               zipNames($work . '/flat.zip') === $expected);

echo "2. the release layout packs the SAME archive — links are followed\n";
$n = $a->zipDirectory($work . '/rel', $work . '/rel.zip', $fullExcludes);
check('three files packed',              $n === 3);
check('identical name set to the flat tree', zipNames($work . '/rel.zip') === $expected);
check('the excluded subtree behind the link stayed out',
    !in_array('data/framework/jobs/queue.json', zipNames($work . '/rel.zip'), true));

echo "3. two names for one tree pack it once\n";
makeDirLink($work . '/shared/data', $work . '/rel/data2');
$n = $a->zipDirectory($work . '/rel', $work . '/rel2.zip', $fullExcludes);
check('still three files',               $n === 3);
check('no second copy under the second name',
    count(array_filter(zipNames($work . '/rel2.zip'), fn($p) => str_starts_with($p, 'data2/'))) === 0
    || count(array_filter(zipNames($work . '/rel2.zip'), fn($p) => str_starts_with($p, 'data/'))) === 0);
removeLink($work . '/rel/data2');

echo "4. a cycle terminates instead of recursing forever\n";
makeDirLink($work . '/rel', $work . '/rel/override/loop');
$n = $a->zipDirectory($work . '/rel', $work . '/cycle.zip', $fullExcludes);
check('archive still holds exactly the three files', $n === 3);
removeLink($work . '/rel/override/loop');

echo "5. a dangling link is skipped silently\n";
@mkdir($work . '/gone', 0777, true);
makeDirLink($work . '/gone', $work . '/rel/dangling');
@rmdir($work . '/gone');
clearstatcache(true);
$n = $a->zipDirectory($work . '/rel', $work . '/dangling.zip', $fullExcludes);
check('archive unchanged',               $n === 3);
removeLink($work . '/rel/dangling');

echo "\n";
echo $fail === 0
    ? "PASS — {$pass} checks\n"
    : "FAIL — {$fail} of " . ($pass + $fail) . " checks failed\n";

if ($fail === 0) {
    rrm($work);
} else {
    echo "Arbeitsverzeichnis bleibt stehen: {$work}\n";
}

exit($fail === 0 ? 0 : 1);
