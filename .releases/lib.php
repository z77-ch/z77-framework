<?php
/**
 * Shared helpers for the `.releases/` scripts. Not a framework file —
 * plain PHP, no autoloader, no dependency, so it runs before `vendor/`
 * exists and on any developer machine.
 */

declare(strict_types=1);

/**
 * Every `path` repository of the project's composer.json, resolved.
 *
 * @return array<string, array{name: string, path: string}> keyed by package
 *   name (`z77/kernel`) → absolute source path.
 */
function releases_pathRepos(string $projectRoot): array
{
    $composer = json_decode((string) file_get_contents($projectRoot . '/composer.json'), true);
    if (!is_array($composer)) {
        fwrite(STDERR, "composer.json unreadable in $projectRoot\n");
        exit(1);
    }
    $out = [];
    foreach ((array) ($composer['repositories'] ?? []) as $repo) {
        if (($repo['type'] ?? null) !== 'path') {
            continue;
        }
        $path = realpath($projectRoot . '/' . $repo['url']) ?: realpath((string) $repo['url']);
        if ($path === false) {
            fwrite(STDERR, "path repository not found: {$repo['url']}\n");
            exit(1);
        }
        $pkg = json_decode((string) file_get_contents($path . '/composer.json'), true);
        $name = (string) ($pkg['name'] ?? '');
        if ($name === '') {
            fwrite(STDERR, "no package name in $path/composer.json\n");
            exit(1);
        }
        $out[$name] = ['name' => $name, 'path' => $path];
    }
    return $out;
}

/**
 * Source trees for `tools/build-stamp.php`: packages under `<fw>/packages/*`
 * collapse into one `framework=<fw>` entry; every other path repo is its own
 * entry named after its directory. Returns `[label => path]` and the
 * framework root (needed to locate the stamp writer).
 *
 * @param array<string, array{name: string, path: string}> $repos
 * @return array{0: array<string, string>, 1: ?string}
 */
function releases_stampSources(array $repos): array
{
    $sources = [];
    $framework = null;
    foreach ($repos as $repo) {
        $path = str_replace('\\', '/', $repo['path']);
        if (preg_match('~^(.*)/packages/[^/]+$~', $path, $m)) {
            $framework = $m[1];
            $sources['framework'] = $m[1];
            continue;
        }
        $label = preg_replace('~^z77-|-\d+(\.\d+)*$~', '', basename($path)) ?: basename($path);
        $sources[$label] = $path;
    }
    return [$sources, $framework];
}

/** True for a symlink AND for a Windows junction. */
function releases_isLink(string $path): bool
{
    if (is_link($path)) {
        return true;
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        return false;
    }
    // PHP on Windows: is_link()/is_dir() are false for a junction, but
    // realpath() resolves it — so a junction is a name whose realpath differs
    // from the realpath of its parent + its own name.
    $real = realpath($path);
    if ($real === false) {
        return false;
    }
    $self = realpath(dirname($path));
    return $self !== false && strcasecmp($real, $self . DIRECTORY_SEPARATOR . basename($path)) !== 0;
}

/** Removes a symlink/junction WITHOUT touching its target. */
function releases_removeLink(string $path): void
{
    // rmdir on a junction/directory symlink removes the reparse point only;
    // a file symlink needs unlink. Try both, never recurse.
    if (@rmdir($path)) {
        return;
    }
    if (!@unlink($path)) {
        fwrite(STDERR, "cannot remove link $path\n");
        exit(1);
    }
}

/** Removes a real directory tree. Refuses to follow links inside it. */
function releases_removeTree(string $path): void
{
    if (releases_isLink($path)) {
        releases_removeLink($path);
        return;
    }
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        $p = $entry->getPathname();
        if (releases_isLink($p)) {
            releases_removeLink($p);
        } elseif ($entry->isDir()) {
            releases_removeTree($p);
        } else {
            unlink($p);
        }
    }
    rmdir($path);
}

/**
 * Copies a source tree into $dest, skipping the top-level names in $skip
 * (`.git`, `vendor`, …). Preserves modification times, like robocopy /COPY:DAT.
 *
 * @param string[] $skip
 */
function releases_copyTree(string $src, string $dest, array $skip): void
{
    if (!is_dir($dest) && !mkdir($dest, 0777, true)) {
        fwrite(STDERR, "cannot create $dest\n");
        exit(1);
    }
    foreach (new DirectoryIterator($src) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        $name = $entry->getFilename();
        if (in_array($name, $skip, true)) {
            continue;
        }
        $from = $entry->getPathname();
        $to   = $dest . DIRECTORY_SEPARATOR . $name;
        if ($entry->isDir()) {
            releases_copyTree($from, $to, []);
        } else {
            if (!copy($from, $to)) {
                fwrite(STDERR, "copy failed: $from\n");
                exit(1);
            }
            touch($to, $entry->getMTime());
        }
    }
}

/** Runs a command in $cwd, streams its output, exits on failure. */
function releases_run(string $cmd, string $cwd): void
{
    echo "\n> $cmd\n";
    $prev = getcwd();
    chdir($cwd);
    passthru($cmd, $code);
    chdir($prev ?: $cwd);
    if ($code !== 0) {
        fwrite(STDERR, "command failed ($code): $cmd\n");
        exit($code);
    }
}
