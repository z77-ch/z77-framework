<?php

/**
 * The deploy build's export (CLI, a throwaway git repository) — `.releases/lib.php`
 * `releases_exportCommit`, packaging.md PKG-EXPORT-001. What is load-bearing: a release holds the
 * last commit and nothing else. No gitignored file, no untracked file, no uncommitted change, a
 * deleted-but-committed file is there, a subfolder exports alone, an unchanged file keeps its
 * mtime (asset versions), and the build stamp is not dirty for a committed build.
 *
 * Run: php tests/releases-export.php
 */

require __DIR__ . '/../.releases/lib.php';

$failures = 0;
$check = static function (string $label, bool $ok, string $got = '') use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $got === '' ? '' : "\n       got: {$got}") . "\n";
    if (!$ok) {
        $failures++;
    }
};

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-export-' . bin2hex(random_bytes(4));
$repo = "$base/repo";
mkdir("$repo/pkg/deep/a-rather-long-directory-name-to-pass-the-old-tar-limit-of-one-hundred-characters-for-a-path", 0777, true);

$write = static fn(string $file, string $body) => file_put_contents("$repo/$file", $body);
$git   = static function (string $args) use ($repo): string {
    return (string) shell_exec('git -C ' . escapeshellarg($repo) . ' -c user.name=Test -c user.email=test@example.ch ' . $args . ' 2>&1');
};

$git('init -q');
$write('.gitignore', "/pkg/_local/\n");
mkdir("$repo/pkg/internal");
$write('pkg/.gitattributes', "/.gitattributes export-ignore\n/internal/** export-ignore\n");
$write('pkg/internal/notes.md', "committed, but not for a dist\n");
$write('pkg/kept.php', "<?php // committed\n");
$write('pkg/changed.php', "<?php // committed version\n");
$write('pkg/deleted.php', "<?php // committed, then deleted locally\n");
$write('pkg/Äpfel.txt', "umlaut name\n");
$write('pkg/deep/a-rather-long-directory-name-to-pass-the-old-tar-limit-of-one-hundred-characters-for-a-path/long-file-name.php', "long\n");
mkdir("$repo/pkg/.claude");
$write('pkg/.claude/settings.json', "{}\n");
$write('outside.txt', "not in the package\n");
$git('add -A');
$git('commit -q -m first');

mkdir("$repo/pkg/_local");
$write('pkg/_local/runbook.md', "private\n");
$write('pkg/untracked.php', "<?php // never added\n");
$write('pkg/changed.php', "<?php // UNCOMMITTED version\n");
unlink("$repo/pkg/deleted.php");
touch("$repo/pkg/kept.php", 1000000000);

$dest = "$base/out";
ob_start();
$ok = releases_exportCommit("$repo/pkg", $dest, ['.git', 'vendor', '.claude']);
$out = (string) ob_get_clean();

$check('exports a git tree', $ok === true);
$check('a committed file is there', is_file("$dest/kept.php"));
$check('a gitignored file is NOT there', !file_exists("$dest/_local/runbook.md") && !is_dir("$dest/_local"));
$check('an untracked file is NOT there', !file_exists("$dest/untracked.php"));
$check('a changed file comes in its COMMITTED version', str_contains((string) file_get_contents("$dest/changed.php"), 'committed version'));
$check('a file deleted only locally is there', is_file("$dest/deleted.php"));
$check('a skipped top-level name is NOT there', !file_exists("$dest/.claude"));
$check('a path past the old tar limit arrives', is_file("$dest/deep/a-rather-long-directory-name-to-pass-the-old-tar-limit-of-one-hundred-characters-for-a-path/long-file-name.php"));
$check('a non-ASCII name arrives', is_file("$dest/Äpfel.txt"));
$check('export-ignore: a committed file marked so stays out, and does not fail the export', !file_exists("$dest/internal/notes.md") && !file_exists("$dest/.gitattributes"));
$check('only the subfolder, with its own paths', !file_exists("$dest/outside.txt") && !file_exists("$dest/pkg"));
$check('stored bytes: no CR added', !str_contains((string) file_get_contents("$dest/kept.php"), "\r"));
$check('an unchanged file keeps its working mtime', filemtime("$dest/kept.php") === 1000000000, (string) filemtime("$dest/kept.php"));
$check('it names what is not in the build', str_contains($out, 'NOT in this build') && str_contains($out, 'changed.php'), $out);

$plain = "$base/plain";
mkdir($plain);
file_put_contents("$plain/x.txt", 'x');
putenv('GIT_CEILING_DIRECTORIES=' . $base);
$check('no git tree: false, nothing written', releases_exportCommit($plain, "$base/out2", []) === false && !is_dir("$base/out2"));
putenv('GIT_CEILING_DIRECTORIES');

$php   = escapeshellarg(PHP_BINARY);
$stamp = escapeshellarg(__DIR__ . '/../tools/build-stamp.php');
shell_exec("$php $stamp --committed " . escapeshellarg("$base/a.json") . ' ' . escapeshellarg("framework=$repo"));
shell_exec("$php $stamp " . escapeshellarg("$base/b.json") . ' ' . escapeshellarg("framework=$repo"));
$a = json_decode((string) file_get_contents("$base/a.json"), true);
$b = json_decode((string) file_get_contents("$base/b.json"), true);
$check('stamp --committed: a commit, not dirty', ($a['framework']['dirty'] ?? null) === false && preg_match('/^[0-9a-f]{40}$/', (string) ($a['framework']['commit'] ?? '')) === 1);
$check('stamp without it: still reports the tree as dirty', ($b['framework']['dirty'] ?? null) === true);

// git writes its objects read-only, and Windows refuses to delete those.
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
    chmod($file->getPathname(), 0666);
}
releases_removeTree($base);

echo $failures === 0 ? "\nALL GREEN\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
