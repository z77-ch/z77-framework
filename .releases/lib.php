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

/**
 * Exports the last COMMIT of the git tree at $src into $dest: what is in git and nothing else.
 * No gitignored file (`docs/_local` went to every server with the old working-tree copy, found
 * 2026-11-01) and no uncommitted change, so a release is exactly the commit its stamp names.
 * Skips the top-level names in $skip, like {@see releases_copyTree()}.
 *
 * A file identical to its working copy keeps that copy's modification time: the asset URLs are
 * versioned by mtime, and a commit must not re-version what it did not change. Exits on any
 * failure; a release with files missing must not get as far as an upload.
 *
 * @param string[] $skip
 * @return bool false when $src is no git tree (nothing was written)
 */
function releases_exportCommit(string $src, string $dest, array $skip): bool
{
    $git = 'git -C ' . escapeshellarg($src);
    exec("$git rev-parse --verify HEAD 2>&1", $ignored, $status);
    if ($status !== 0) {
        return false;
    }

    $files = array_values(array_filter(
        explode("\0", (string) shell_exec("$git ls-tree -r -z --name-only HEAD")),
        static fn(string $file) => $file !== '' && !in_array(explode('/', $file)[0], $skip, true)
    ));
    if ($files === []) {
        fwrite(STDERR, "nothing committed under $src\n");
        exit(1);
    }

    $changed = trim((string) shell_exec("$git status --porcelain -- ."));
    if ($changed !== '') {
        $lines = explode("\n", $changed);
        echo '    NOT in this build, uncommitted: ' . count($lines) . " file(s)\n";
        foreach (array_slice($lines, 0, 10) as $line) {
            echo "      $line\n";
        }
    }

    if (!is_dir($dest) && !mkdir($dest, 0777, true)) {
        fwrite(STDERR, "cannot create $dest\n");
        exit(1);
    }

    // autocrlf off, eol lf: the bytes as stored, the same from every machine.
    $tar = tempnam(sys_get_temp_dir(), 'z77-export') . '.tar';
    exec("$git -c core.autocrlf=false -c core.eol=lf archive --format=tar -o " . escapeshellarg($tar) . ' HEAD 2>&1', $output, $status);
    if ($status !== 0) {
        fwrite(STDERR, "git archive failed in $src: " . implode(' ', $output) . "\n");
        exit(1);
    }
    try {
        // What the archive holds, not what ls-tree lists: `export-ignore` (.gitattributes,
        // docs/internal in the provider packages) is left out on purpose, and asking PharData
        // for a file it does not hold fails the whole export.
        $archive = new PharData($tar);
        $prefix  = 'phar://' . str_replace('\\', '/', (string) realpath($tar)) . '/';
        $held    = [];
        foreach (new RecursiveIteratorIterator($archive) as $entry) {
            $held[] = substr(str_replace('\\', '/', $entry->getPathname()), strlen($prefix));
        }
        $files = array_values(array_intersect($files, $held));
        $archive->extractTo($dest, $files, true);
    } catch (Exception $e) {
        fwrite(STDERR, "export failed for $src: " . $e->getMessage() . "\n");
        exit(1);
    } finally {
        @unlink($tar);
        @unlink(substr($tar, 0, -4));
    }

    foreach ($files as $file) {
        $exported = "$dest/$file";
        if (!is_file($exported)) {
            fwrite(STDERR, "export incomplete, missing: $exported\n");
            exit(1);
        }
        $working = "$src/$file";
        if (is_file($working)
            && str_replace("\r\n", "\n", (string) file_get_contents($working)) === str_replace("\r\n", "\n", (string) file_get_contents($exported))) {
            touch($exported, (int) filemtime($working));
        }
    }

    return true;
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
 * `var/lib` → `var/lib`, `config/client` → `config/client`.
 *
 * ONE level is stripped, and only `public/`: that prefix is a requirement of the
 * web root, not a property of the store — the media directory is shared state that
 * happens to have to be reachable under `public/`. Every other path is kept whole,
 * so `var/lib` (ADR-035) lands in `shared/var/lib` and stays distinguishable from a
 * store that merely ends in the same word.
 *
 * `config/client` (ADR-036) is kept whole like any other path — the same tree as
 * locally. Until 2026-10-22 it was mapped onto the flat `shared/config`; a server
 * still in that layout is migrated by hand (release-structure.md), and deploy.php
 * refuses an empty `shared/config/client` so the old layout cannot ship a 500.
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

/**
 * What a project's whole-file config override leaves out (packaging.md PKG-OVERRIDE-001). A config
 * under `override/` replaces the framework's file whole, first match wins, no merging: a key the
 * framework gained later is silently absent there, and so is a mail form or a job. Compares
 * `override/z77/**` with the framework's file of the same path: top-level keys, for `emailConfig`
 * also `forms` entries, for a module config (`App/Config`) also `jobs` entries.
 *
 * Only reads and only warns. Local files, no server. Neither side is executed: a project's config
 * may do anything and names classes no autoloader knows here, so both are read with the tokenizer.
 * A file whose `return` is not a plain array cannot be judged and says so.
 *
 * @return list<string> one sentence per finding, paths relative to the project
 */
function releases_overrideWarnings(string $projectRoot): array
{
    $root = rtrim(str_replace('\\', '/', $projectRoot), '/');
    $base = $root . '/override/z77';
    if (!is_dir($base)) {
        return [];
    }

    $warnings = [];
    $files    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS));
    $list     = [];
    foreach ($files as $file) {
        $path = str_replace('\\', '/', (string) $file);
        if (str_ends_with($path, '.inc.php') && basename(dirname($path)) === 'Config') {
            $list[] = $path;
        }
    }
    sort($list);

    foreach ($list as $path) {
        $rel       = substr($path, strlen($base) + 1);
        $framework = releases_frameworkFileFor($root, $rel);
        if ($framework === null) {
            continue; // the project's own config: nothing to compare it with
        }
        $name    = 'override/z77/' . $rel;
        $ours    = releases_configKeys((string) file_get_contents($path));
        $theirs  = releases_configKeys((string) file_get_contents($framework));
        if ($ours === null || $theirs === null) {
            $warnings[] = $name . ': ' . ($ours === null ? 'its' : "the framework file's") . ' return is not a plain array, not compared';
            continue;
        }

        $missing = array_values(array_diff(array_keys($theirs), array_keys($ours)));
        if ($missing !== []) {
            $warnings[] = $name . ' replaces the framework file whole and lacks: ' . implode(', ', $missing);
        }
        $nested = [];
        if (basename($path) === 'emailConfig.inc.php') {
            $nested[] = 'forms';
        }
        if (str_contains($rel, '/App/Config/')) {
            $nested[] = 'jobs';
        }
        foreach ($nested as $key) {
            $gone = array_values(array_diff($theirs[$key] ?? [], $ours[$key] ?? []));
            if ($gone !== []) {
                $warnings[] = $name . " lacks these '{$key}' entries: " . implode(', ', $gone);
            }
        }
    }

    return $warnings;
}

/**
 * The framework's file behind an override path (`module/member/src/…`, `shared/src/…`), from the
 * project's vendor/, else from a path repository of its composer.json; null when there is none.
 */
function releases_frameworkFileFor(string $root, string $rel): ?string
{
    if (preg_match('#^module/([a-z0-9-]+)/(.+)$#', $rel, $m) === 1) {
        $package = 'module-' . $m[1];
        $inside  = $m[2];
    } elseif (preg_match('#^(core|shared|persistence)/(.+)$#', $rel, $m) === 1) {
        $package = 'kernel';
        $inside  = $m[1] . '/' . $m[2];
    } else {
        return null;
    }

    $candidate = $root . '/vendor/z77/' . $package . '/' . $inside;
    if (is_file($candidate)) {
        return $candidate;
    }

    $composer = json_decode((string) @file_get_contents($root . '/composer.json'), true);
    foreach ((array) ($composer['repositories'] ?? []) as $repo) {
        if (!is_array($repo) || ($repo['type'] ?? null) !== 'path') {
            continue;
        }
        $url = (string) ($repo['url'] ?? '');
        foreach (array_merge(glob($root . '/' . $url) ?: [], glob($url) ?: []) as $dir) {
            $pkg = json_decode((string) @file_get_contents($dir . '/composer.json'), true);
            if (($pkg['name'] ?? null) === 'z77/' . $package && is_file($dir . '/' . $inside)) {
                return $dir . '/' . $inside;
            }
        }
    }

    return null;
}

/**
 * The string keys of the array a config file returns, each with the string keys of its own value:
 * `['forms' => ['contactForm', …], 'loginUrl' => []]`. Read from the tokens, nothing runs. Null when
 * the first top-level `return` is not a plain array literal, or the literal spreads something in.
 *
 * @return array<string, list<string>>|null
 */
function releases_configKeys(string $php): ?array
{
    $tokens = array_values(array_filter(
        token_get_all($php),
        static fn($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
    ));

    $depth = 0;
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if ($token === '{' || $token === '(' || $token === '[' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true))) {
            $depth++;
        } elseif ($token === '}' || $token === ')' || $token === ']') {
            $depth--;
        } elseif ($depth === 0 && is_array($token) && $token[0] === T_RETURN) {
            $opensAt = releases_arrayOpensAt($tokens, $i + 1);
            if ($opensAt === null) {
                return null;
            }
            $keys = [];
            $end  = releases_readArrayKeys($tokens, $opensAt, 1, $keys);

            // Anything but the closing `;` behind the literal (`[…] + $more`): not plain.
            return $end === null || ($tokens[$end + 1] ?? ';') !== ';' ? null : $keys;
        }
    }

    return null;
}

/** The index of the first token inside an array literal that starts at $i, or null when none starts there. */
function releases_arrayOpensAt(array $tokens, int $i): ?int
{
    if (($tokens[$i] ?? null) === '[') {
        return $i + 1;
    }
    if (is_array($tokens[$i] ?? null) && $tokens[$i][0] === T_ARRAY && ($tokens[$i + 1] ?? null) === '(') {
        return $i + 2;
    }

    return null;
}

/**
 * Walks one array literal from its first inner token. Level 1 collects the keys and the keys of
 * their values, level 2 only the keys. Returns the index of the closing token; null on a spread at
 * level 1, which makes the key list unknowable.
 *
 * @param array<string|int, mixed> $keys
 */
function releases_readArrayKeys(array $tokens, int $i, int $level, array &$keys): ?int
{
    $depth = 0; // brackets of a value inside this literal
    $count = count($tokens);
    for (; $i < $count; $i++) {
        $token = $tokens[$i];
        if ($token === '{' || $token === '(' || $token === '[' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true))) {
            $depth++;
            continue;
        }
        if ($token === '}' || $token === ')' || $token === ']') {
            if ($depth === 0) {
                return $i;
            }
            $depth--;
            continue;
        }
        if ($depth !== 0) {
            continue;
        }
        if (is_array($token) && $token[0] === T_ELLIPSIS && $level === 1) {
            return null;
        }
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_ARROW) {
            $key = stripcslashes(substr($token[1], 1, -1));
            if ($level === 2) {
                $keys[] = $key;
                continue;
            }
            $keys[$key] = [];
            $opensAt    = releases_arrayOpensAt($tokens, $i + 2);
            if ($opensAt !== null) {
                $inner = [];
                $end   = releases_readArrayKeys($tokens, $opensAt, 2, $inner);
                $keys[$key] = $inner;
                $i = (int) $end;
            }
        }
    }

    return null;
}
