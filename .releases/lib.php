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

/**
 * Loads and validates `target.json` from the `.releases/` directory. Exits
 * with a message on anything a script cannot work with — the same checks
 * check.php reports, but here they are fatal because a switch is about to
 * happen on their basis.
 *
 * @return array{host: string, root: string, release_name: string, shared: string[],
 *   release_dirs: string[], link_target: 'public'|'release', hosts: array<string,string>}
 */
function releases_target(string $releasesDir): array
{
    $file = $releasesDir . '/target.json';
    $t = json_decode((string) @file_get_contents($file), true);
    if (!is_array($t)) {
        fwrite(STDERR, "target.json missing or not valid JSON: $file\n");
        exit(1);
    }
    foreach (['host', 'root', 'release_name', 'shared', 'link_target'] as $key) {
        if (empty($t[$key])) {
            fwrite(STDERR, "target.json: key '$key' missing or empty\n");
            exit(1);
        }
    }
    $root = rtrim((string) $t['root'], '/');
    if ($root === '' || $root[0] !== '/' || str_contains($root, '<')) {
        fwrite(STDERR, "target.json: 'root' must be an absolute server path, got '$root'\n");
        exit(1);
    }
    if (!in_array($t['link_target'], ['public', 'release'], true)) {
        fwrite(STDERR, "target.json: 'link_target' must be 'public' or 'release', got '{$t['link_target']}'\n");
        exit(1);
    }
    $t['root']         = $root;
    $t['shared']       = array_map('strval', (array) $t['shared']);
    $t['release_dirs'] = array_map('strval', (array) ($t['release_dirs'] ?? []));
    $t['hosts']        = array_map('strval', (array) ($t['hosts'] ?? []));
    return $t;
}

/**
 * `<release> <next|current>` from argv, validated against target.json.
 *
 * @return array{0: string, 1: string}
 */
function releases_switchArgs(array $argv, array $target): array
{
    $release = (string) ($argv[1] ?? '');
    $door    = (string) ($argv[2] ?? '');
    if ($release === '' || $door === '') {
        fwrite(STDERR, "Usage: php .releases/switch.php <release> <next|current>\n");
        exit(1);
    }
    if (!in_array($door, ['next', 'current'], true)) {
        fwrite(STDERR, "door must be 'next' or 'current', got '$door'\n");
        exit(1);
    }
    if (!preg_match('~' . $target['release_name'] . '~', $release) || str_contains($release, '/') || str_contains($release, '..')) {
        fwrite(STDERR, "release name '$release' does not match target.release_name '{$target['release_name']}'\n");
        exit(1);
    }
    return [$release, $door];
}

/**
 * The directory name under shared/ for a `target.shared` entry:
 * `data` → `data`, `public/media` → `media`, `.propbase` → `.propbase`,
 * `var/lib` → `var/lib`.
 *
 * ONE level is stripped, and only `public/`: that prefix is a requirement of the
 * web root, not a property of the store — the media directory is shared state that
 * happens to have to be reachable under `public/`. Every other path is kept whole,
 * so `var/lib` (ADR-035) lands in `shared/var/lib` and stays distinguishable from a
 * store that merely ends in the same word.
 */
function releases_sharedStoreName(string $entry): string
{
    $entry = trim($entry, '/');

    return str_starts_with($entry, 'public/') ? substr($entry, strlen('public/')) : $entry;
}

/**
 * Runs a bash script on the server via `ssh -T <host> bash -s`, script on
 * stdin. No quoting of our own — the script carries its values as
 * single-quoted bash literals. Returns combined output; exits on failure.
 */
function releases_ssh(string $host, string $script): string
{
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['ssh', '-T', $host, 'bash', '-s'], $spec, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "cannot start ssh — is OpenSSH installed and '$host' in ~/.ssh/config?\n");
        exit(1);
    }
    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        fwrite(STDERR, "ssh/bash exited with $code\n$out$err");
        exit($code);
    }
    return $out . $err;
}

/**
 * HTTP status of a HEAD-like GET without following redirects; 0 when the
 * host does not answer at all. No curl dependency — PHP streams.
 */
function releases_httpStatus(string $url): int
{
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 15,
                   'header' => "User-Agent: z77-releases-switch\r\n"],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $headers = @get_headers($url, false, $ctx);
    if ($headers === false || !isset($headers[0]) || !preg_match('~HTTP/\S+\s+(\d{3})~', $headers[0], $m)) {
        return 0;
    }
    return (int) $m[1];
}

/**
 * Like releases_ssh(), but the remote command is passed as an argument and
 * stdin carries a FILE (the tar stream). `bash -c '<script>'` — the script
 * is single-quoted for the remote shell, its values are bash literals.
 */
function releases_sshStdin(string $host, string $script, string $stdinFile): string
{
    $remote = "bash -c '" . str_replace("'", "'\''", $script) . "'";
    $spec = [0 => ['file', $stdinFile, 'rb'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['ssh', '-T', $host, $remote], $spec, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "cannot start ssh — is OpenSSH installed and '$host' in ~/.ssh/config?\n");
        exit(1);
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $err = implode("\n", array_filter(explode("\n", $err), static fn($l) => !str_starts_with($l, '** ')));
    if ($code !== 0 && !str_contains($out, 'STOP:')) {
        fwrite(STDERR, "ssh/bash exited with $code\n$out$err");
        exit($code);
    }
    return $out . $err;
}

/**
 * Status and response headers (lower-cased names) of a GET without
 * following redirects. `[0, []]` when the host does not answer.
 *
 * @return array{0: int, 1: array<string, string>}
 */
function releases_httpHead(string $url): array
{
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 20,
                   'header' => "User-Agent: z77-releases-switch\r\nCache-Control: no-cache\r\n"],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @get_headers($url, false, $ctx);
    if ($raw === false || !isset($raw[0]) || !preg_match('~HTTP/\S+\s+(\d{3})~', $raw[0], $m)) {
        return [0, []];
    }
    $headers = [];
    foreach ($raw as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    return [(int) $m[1], $headers];
}
