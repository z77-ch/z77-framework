<?php
/**
 * Verifies the project's deploy setup against `.releases/RULES.md`.
 *
 * Reads `.releases/target.json` and `.vscode/sftp.json`, prints every
 * violation, exits 1 if there is one. Never touches the server, never
 * modifies a file — the developer maintains sftp.json by hand; this
 * script only warns.
 *
 * Usage: php .releases/check.php   (from the project root or anywhere)
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$targetFile  = __DIR__ . '/target.json';
$sftpFile    = $projectRoot . '/.vscode/sftp.json';

$errors = [];
$warn   = static function (string $msg) use (&$errors): void { $errors[] = $msg; };

$load = static function (string $file, string $label) use ($warn): ?array {
    if (!is_file($file)) {
        $warn("$label missing: $file");
        return null;
    }
    $json = json_decode((string) file_get_contents($file), true);
    if (!is_array($json)) {
        $warn("$label is not valid JSON: $file (" . json_last_error_msg() . ')');
        return null;
    }
    return $json;
};

$target = $load($targetFile, 'target.json');
$sftp   = $load($sftpFile, 'sftp.json');

if ($target === null) {
    report($errors);
}

// --- target.json itself ---------------------------------------------------
foreach (['host', 'root', 'release_name', 'shared'] as $key) {
    if (empty($target[$key])) {
        $warn("target.json: key '$key' missing or empty");
    }
}
$root = rtrim((string) ($target['root'] ?? ''), '/');
if ($root === '' || $root[0] !== '/') {
    $warn("target.json: 'root' must be an absolute server path, got '$root'");
}
if (str_contains($root, '<')) {
    $warn("target.json: 'root' still contains a placeholder — fill in the real path");
}
$pattern = (string) ($target['release_name'] ?? '');
if ($pattern !== '' && @preg_match('~' . $pattern . '~', '') === false) {
    $warn("target.json: 'release_name' is not a valid regex: $pattern");
    $pattern = '';
}
$shared = array_map('strval', (array) ($target['shared'] ?? []));

// --- rule 3: the link convention is recorded, and the root .htaccess fits it ---
$linkTarget = $target['link_target'] ?? null;
if (!in_array($linkTarget, ['public', 'release'], true)) {
    $warn("target.json: 'link_target' must be 'public' (door -> releases/<name>/public) or 'release' (door -> releases/<name>) — rule 3");
}
foreach (['next', 'current'] as $door) {
    if (empty($target['hosts'][$door])) {
        $warn("target.json: 'hosts.$door' missing — switch.php cannot probe the $door door from outside");
    }
}
$rootHtaccess = $projectRoot . '/.htaccess';
$rootDenies   = is_file($rootHtaccess) && str_contains((string) file_get_contents($rootHtaccess), 'Require all denied');
if ($linkTarget === 'public' && !$rootDenies) {
    $warn("<project>/.htaccess missing or not the deny file — copy .releases/htaccess-deny there; with link_target=public a link that forgets /public would otherwise serve the release root");
}
if ($linkTarget === 'release' && $rootDenies) {
    $warn("<project>/.htaccess denies everything, but link_target=release puts the release root INTO Apache's directory walk — the whole site would answer 403; remove the file or switch the convention");
}

if ($sftp === null) {
    report($errors);
}

// --- rule 8: uploadOnSave ---------------------------------------------------
if (($sftp['uploadOnSave'] ?? null) !== false) {
    $warn("sftp.json: 'uploadOnSave' must be exactly false (rule 8)");
}

// --- host must match ---------------------------------------------------------
if (($sftp['host'] ?? null) !== ($target['host'] ?? null)) {
    $warn(sprintf("sftp.json: host '%s' differs from target.json host '%s'",
        $sftp['host'] ?? '(none)', $target['host'] ?? '(none)'));
}

// --- rule 1 + 7: remotePath = <root>/releases/<name> --------------------------
$remote = rtrim((string) ($sftp['remotePath'] ?? ''), '/');
if ($remote === '') {
    $warn("sftp.json: 'remotePath' missing");
} elseif (str_contains($remote, '<')) {
    $warn("sftp.json: remotePath still contains a placeholder: '$remote'");
} elseif ($root !== '' && !str_starts_with($remote . '/', $root . '/')) {
    $warn("sftp.json: remotePath '$remote' lies OUTSIDE the project root '$root' (rule 1)");
} elseif ($root !== '') {
    $rel   = ltrim(substr($remote, strlen($root)), '/');
    $parts = $rel === '' ? [] : explode('/', $rel);
    if (count($parts) !== 2 || $parts[0] !== 'releases') {
        $warn("sftp.json: remotePath must be '<root>/releases/<name>', got '$remote' (rule 7)");
    } elseif ($pattern !== '' && !preg_match('~' . $pattern . '~', $parts[1])) {
        $warn("sftp.json: release name '{$parts[1]}' does not match target.release_name '$pattern' (rule 4)");
    } elseif (preg_match('~^\d{4}-\d{2}-\d{2}~', $parts[1], $m) && $m[0] < date('Y-m-d', strtotime('-7 days'))) {
        $warn("sftp.json: release '{$parts[1]}' is older than a week — a forgotten remotePath uploads into a RUNNING release");
    }
}

// --- rule 6: every shared name must be ignored ---------------------------------
// A shared name may be covered by an ANCESTOR pattern: `var/**` covers `var/lib`.
// That is the normal case since ADR-035 — the whole of `var/` stays out of the
// upload (cache and state are release-local runtime dirs, lib is a signpost),
// while only `var/lib` is listed as shared.
$ignore = array_map('strval', (array) ($sftp['ignore'] ?? []));
foreach ($shared as $name) {
    $name = trim($name, '/');
    $ok = false;
    foreach ($ignore as $ig) {
        $ig = trim($ig, '/');
        if ($ig === $name || $ig === "$name/**" || $ig === "$name/*") {
            $ok = true;
            break;
        }
        // ancestor with a recursive wildcard, e.g. 'var/**' for 'var/lib'
        if (str_ends_with($ig, '/**') && str_starts_with($name . '/', substr($ig, 0, -2))) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        $warn("sftp.json: ignore list lacks '$name/**' — an upload would replace the symlink with a real directory (rule 6)");
    }
}

// --- ADR-035: the release-local runtime dirs must never be shared ---------------
// This is the defect the layout was changed for: with `var/cache` behind a shared
// signpost, a page rendered on the `next` door is served by `current` for the whole
// TTL, and the DEBUG / SEO_NOINDEX switches in `var/state` apply to both doors at
// once. `var/lib` is the one branch that IS shared (throttle windows must survive
// a switch).
foreach ($shared as $name) {
    $name = trim($name, '/');
    if ($name === 'var' || $name === 'var/cache' || $name === 'var/state') {
        $warn("target.json: '$name' must NOT be shared — the page cache and the release switches are release-local (ADR-035); share 'var/lib' only");
    }
    if ($name === 'lib' || str_starts_with($name, 'lib/')) {
        $warn("target.json: '$name' is the pre-ADR-035 layout — the throttle counters moved to 'var/lib'; see docs/01-handbook/release-structure.md for the migration");
    }
}
if (!in_array('var/lib', array_map(static fn($n) => trim($n, '/'), $shared), true)) {
    $warn("target.json: 'var/lib' is not shared — throttle and lock-out windows would reset on every release switch (ADR-035)");
}

// --- ADR-035: the installed bootstrap config must not steer the cache anymore ---
// ADR-036 split location first, legacy flat fallback.
foreach (['/config/vendor/bootstrap.inc.php', '/config/bootstrap.inc.php'] as $rel) {
    $bootstrapInc = $projectRoot . $rel;
    if (is_file($bootstrapInc)) {
        if (str_contains((string) file_get_contents($bootstrapInc), "'cacheDir'")) {
            $warn(ltrim($rel, '/') . " still carries a 'cacheDir' key — it is ignored since ADR-035 (the path is fixed to var/cache); remove it so nobody reads it as the truth. Same file on the SERVER, under shared/config/.");
        }
        break;
    }
}

// --- vendor must reach the server, and as real copies -------------------------
foreach ($ignore as $ig) {
    if (preg_match('~^/?vendor(/\*\*?)?$~', $ig)) {
        $warn("sftp.json: 'vendor/**' is ignored — the release would ship without code; run vendor-deploy.php and upload vendor/");
    }
    if (preg_match('~^/?\.htaccess$|^\*\*/\.htaccess$|^\.\*$~', $ig)) {
        $warn("sftp.json: '$ig' keeps the .htaccess files out of the upload — the deny file in the release root and the rewrite rules in public/ would both be missing");
    }
}

require __DIR__ . '/lib.php';
if (is_file($projectRoot . '/composer.json')) {
    foreach (releases_pathRepos($projectRoot) as $repo) {
        $target = $projectRoot . '/vendor/' . $repo['name'];
        if (releases_isLink($target)) {
            $warn("vendor/{$repo['name']} is a link, not a copy — run vendor-deploy.php before uploading");
        } elseif (!is_dir($target)) {
            $warn("vendor/{$repo['name']} missing — run vendor-deploy.php");
        }
    }
    if (!is_file($projectRoot . '/vendor/z77/build.json')) {
        $warn("vendor/z77/build.json missing — run vendor-deploy.php (the backend would say 'Entwicklung')");
    }
    $installed = json_decode((string) @file_get_contents($projectRoot . '/vendor/composer/installed.json'), true);
    if (is_array($installed) && ($installed['dev'] ?? null) === true) {
        $warn("vendor/ was installed WITH dev dependencies — run vendor-deploy.php (composer install --no-dev)");
    }
}

report($errors);

function report(array $errors): never
{
    if ($errors === []) {
        fwrite(STDOUT, "OK: deploy setup conforms to .releases/RULES.md\n");
        exit(0);
    }
    fwrite(STDERR, count($errors) . " violation(s):\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - $e\n");
    }
    exit(1);
}
