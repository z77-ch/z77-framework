<?php
/**
 * Build a DEPLOYABLE vendor/ — real copies instead of path-repo links.
 *
 * composer.json forces `options.symlink: true` on the path repositories, so
 * `composer install` always links `vendor/z77/*` at the working trees. A
 * link cannot be uploaded. This script lets composer install normally
 * (production autoload, no dev deps), then swaps EVERY path-repo link for a
 * real copy and stamps the result with `vendor/z77/build.json`.
 *
 * The package list is read from composer.json `repositories` — nothing is
 * hard-coded, a new path repo is picked up automatically (the 2026-07-19
 * "Class not found on the server" incident was a forgotten hard-coded entry).
 *
 * composer.json is never modified. Run `vendor-dev.php` afterwards to get
 * the development links back.
 *
 * Usage: php .releases/vendor-deploy.php   (any cwd)
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$projectRoot = dirname(__DIR__);
$repos       = releases_pathRepos($projectRoot);
[$sources, $framework] = releases_stampSources($repos);

echo "Deploy build in $projectRoot\n";
echo 'Path-repo packages: ' . implode(', ', array_keys($repos)) . "\n";

// 1) Production install: links (per composer.json) + autoload, no dev deps.
//    No --optimize-autoloader on purpose: the override pattern has the same
//    class in override/ and vendor/; a classmap would warn "Ambiguous class
//    resolution", PSR-4 resolves silently by path order (override wins).
putenv('COMPOSER_MIRROR_PATH_REPOS');
releases_run('composer install --no-dev', $projectRoot);

// 2) Replace each link with a real copy of its source tree.
foreach ($repos as $repo) {
    $target = $projectRoot . '/vendor/' . str_replace('/', DIRECTORY_SEPARATOR, $repo['name']);
    echo "  copying {$repo['name']} ...\n";
    if (releases_isLink($target)) {
        releases_removeLink($target);
    } elseif (is_dir($target)) {
        releases_removeTree($target);
    }
    releases_copyTree($repo['path'], $target, ['.git', 'vendor', 'node_modules', '.claude', '.vscode']);
}

// 3) Stamp the copies with where they came from (see packaging.md → build stamp).
//    Must run AFTER the copies; never fails the build.
if ($framework === null || !is_file("$framework/tools/build-stamp.php")) {
    fwrite(STDERR, "WARNING: framework tools/build-stamp.php not found - no build.json, the backend will say 'unbekannt'\n");
} else {
    $args = [escapeshellarg($projectRoot . '/vendor/z77/build.json')];
    foreach ($sources as $label => $path) {
        $args[] = escapeshellarg("$label=$path");
    }
    releases_run('php ' . escapeshellarg("$framework/tools/build-stamp.php") . ' ' . implode(' ', $args), $projectRoot);
}

// 4) Verify.
$bad = [];
foreach ($repos as $repo) {
    $target = $projectRoot . '/vendor/' . $repo['name'];
    if (!is_dir($target) || releases_isLink($target)) {
        $bad[] = $repo['name'];
    }
}
echo "\n";
if ($bad !== []) {
    fwrite(STDERR, '  WARNING: still links or missing: ' . implode(', ', $bad) . "\n");
    exit(1);
}
if (!is_file($projectRoot . '/vendor/z77/build.json')) {
    fwrite(STDERR, "  WARNING: vendor/z77/build.json missing - the backend will say 'Entwicklung'.\n");
    exit(1);
}
echo "  OK: vendor/ holds real copies, stamped, ready to upload.\n";
echo "  Next: php .releases/check.php, then upload, then php .releases/vendor-dev.php\n";
