<?php
/**
 * Restore the DEVELOPMENT vendor/ after a deploy build.
 *
 * `vendor-deploy.php` turned every path-repo package into a real copy.
 * This script removes those copies (or links) so composer re-links them at
 * the live working trees, deletes the deploy stamp, and reinstalls the dev
 * dependencies. The package list comes from composer.json `repositories`.
 *
 * Usage: php .releases/vendor-dev.php   (any cwd)
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$projectRoot = dirname(__DIR__);
$repos       = releases_pathRepos($projectRoot);

echo "Restoring dev vendor in $projectRoot\n";

// 1) Remove every path-repo entry, copy or link, so composer re-links it.
foreach ($repos as $repo) {
    $target = $projectRoot . '/vendor/' . str_replace('/', DIRECTORY_SEPARATOR, $repo['name']);
    if (releases_isLink($target)) {
        releases_removeLink($target);
    } elseif (is_dir($target)) {
        echo "  removing copy {$repo['name']} ...\n";
        releases_removeTree($target);
    }
}

// 2) Drop the deploy stamp: from here on the links point at a working tree
//    that changes between two requests; the last deploy's date would be a lie.
$stamp = $projectRoot . '/vendor/z77/build.json';
if (is_file($stamp)) {
    unlink($stamp);
}

// 3) Normal install: links (composer.json options.symlink = true) + dev deps.
putenv('COMPOSER_MIRROR_PATH_REPOS');
releases_run('composer install', $projectRoot);

// 4) Verify.
$bad = [];
foreach ($repos as $repo) {
    if (!releases_isLink($projectRoot . '/vendor/' . $repo['name'])) {
        $bad[] = $repo['name'];
    }
}
echo "\n";
if ($bad !== []) {
    fwrite(STDERR, '  WARNING: not links: ' . implode(', ', $bad) . " - check composer output.\n");
    exit(1);
}
echo "  OK: dev links restored.\n";
