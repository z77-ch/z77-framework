<?php

/**
 * Rebuilds the KI-Assistent's knowledge — the help index generated from every
 * `*.help.tpl.php` of the installation (ADR-055, docs/topics/kibot.md). Not needed in
 * normal operation: the index rebuilds itself before the first question after a help
 * template changed. Use it after a deploy to build ahead, or to see which templates fall
 * back to their static text or are skipped.
 *
 * Run from the PROJECT root:
 *
 *   php vendor/z77/module-backend/bin/assistant-index.php [--check]
 *
 *   --check   build nothing; exit 0 when the index is fresh, 2 when it is stale
 *
 * Exit codes: 0 = built (or fresh), 1 = it could not run (assistant not configured, boot
 * failed, output directory not writable), 2 = --check found the index stale.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "assistant-index: CLI only.\n");
    exit(1);
}

$projectRoot = str_replace('\\', '/', (string) getcwd());
if (!is_file($projectRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "Run from the project root (vendor/autoload.php not found under {$projectRoot})\n");
    exit(1);
}

define('ABS_BASE_PATH', $projectRoot);
require $projectRoot . '/vendor/autoload.php';

use Z77\Core\Bootstrap;
use Z77\Module\Backend\Ui\Assistant\BackendAssistant;

$checkOnly = in_array('--check', $argv, true);

try {
    (new Bootstrap())->pullUpServices();
    $endpoint = BackendAssistant::fromConfig();
} catch (\Throwable $e) {
    fwrite(STDERR, 'assistant-index: boot failed — ' . $e->getMessage() . "\n");
    exit(1);
}
if ($endpoint === null) {
    fwrite(STDERR, "assistant-index: the KI-Assistent is not configured (z77/kibot missing or no config/client/kibot.inc.php).\n");
    exit(1);
}

$knowledge = BackendAssistant::knowledge($endpoint);
$found     = $knowledge->discover();

if ($checkOnly) {
    $stale = $knowledge->isStale($found);
    echo 'assistant-index: ' . count($found) . ' help templates, index ' . ($stale ? 'STALE' : 'fresh') . ' (' . $knowledge->outDir() . ")\n";
    exit($stale ? 2 : 0);
}

try {
    $report = $knowledge->build($found);
} catch (\Throwable $e) {
    fwrite(STDERR, 'assistant-index: FAILED — ' . $e->getMessage() . "\n");
    exit(1);
}
if ($report === null) {
    fwrite(STDERR, "assistant-index: another process is building the index right now — try again in a moment.\n");
    exit(1);
}

foreach ($report['static'] as $file) {
    echo "  static   {$file}\n";
}
foreach ($report['skipped'] as $file) {
    echo "  skipped  {$file}\n";
}
echo 'assistant-index: ' . $report['topics'] . ' topics from ' . count($found) . ' help templates → ' . $knowledge->outDir() . "\n";
exit(0);
