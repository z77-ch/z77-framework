<?php

/**
 * Minified-JS freshness harness (CLI, offline, no dependency) — does every
 * `*.min.js` carry what its source carries?
 *
 * Production serves `name.min.js`, debug serves `name.js` (js-build.md). A
 * stale `.min.js` is invisible in debug mode and silent in production: the
 * page works, only the newer behaviour is missing (review 2026-10-13, K1 —
 * `core.min.js` lacked `data-submit-navigate`, the row wiring and the focus
 * keeping for five days).
 *
 * The `.min.js` is written by the owner's editor (open the source in VS Code,
 * save → the minify-on-save extension writes the `.min.js` beside it; owner
 * decision 2026-10-13, js-build.md). This harness is the check that the save
 * happened. What it does:
 *
 *   - MARKERS (fail): for the sources listed below, every marker must stand in
 *     the source (else the list is stale — update it here) AND in the
 *     `.min.js` (else the min is stale — re-save the source in VS Code).
 *     Markers are strings a minifier keeps: string literals, property names,
 *     top-level names. A local function name (`_wiredFragment`) is NOT one —
 *     it is mangled away in every correct build.
 *   - VENDOR (fail): a file with `@z77-js: vendor` in its first line is a
 *     third-party build shipped minified; its `.min.js` must be a byte copy —
 *     never minified again (lottie stringifies its own functions).
 *   - NO MIN (warn): a source without a `.min.js` runs on the unminified
 *     fallback in production (JavascriptManager logs a warning per request).
 *   - OLDER (warn): a `.min.js` with an older mtime than its source. A hint
 *     only — a checkout or a copy sets mtimes at will.
 *
 * Exit 1 on any failure. Run: php tests/js-min-markers.php
 */

$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));

/*
 * The marker list — small on purpose: one or two per feature whose absence in
 * production was (or would be) silent. Add a marker when such a feature is
 * added; remove it when the feature goes.
 */
const MARKERS = [
    'packages/kernel/shared/res/assets/js/core.js' => [
        '_z77CollectFormData',      // top-level name the templates call — mangled = broken pages
        'data-submit-navigate',     // SUBMIT-NAV-001 (2026-10-10)
        'submitNavigate',
        '_z77Busy',                 // busy guard against double submits (H2, 2026-10-13)
        'unusable',                 // windows / error pages through the envelope (2026-10-13)
        'js.requestFailed',
        'data-select-on-focus',     // small triggers instead of inline handlers (2026-10-13)
        'data-print',
        'data-fill-url',            // a choice fills its row — the order line's article (2026-10-15)
        'data-price-mode-shown',    // a price-mode switch re-converts the rows (2026-10-18)
        'set-value',                // the debtor's price mode set on a customer choice (2026-10-18)
        'data-rate-keep-label',     // the invoice editor's MWST codes keep their labels under «dated rates» (2026-10-19)
        'data-tree-picker-data',    // the tree picker's JSON island — the article list's group filter (2026-10-18)
        'data-pick-search',         // «Artikel wählen …»: the pick list's keys (2026-10-19)
        'data-window-with',         // … the order's date and price mode ride along to the picker
        'add-row',                  // … «+ Artikel»: the chosen article lands in a new row
        'data-help-key',            // HELP-002
        'refresh-region',           // ADR-047 windows
    ],
];

const VENDOR_MARKER = '@z77-js: vendor';

$pass = 0;
$fail = 0;
$warn = 0;
function check(string $label, bool $ok, string $hint = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; return; }
    $fail++;
    echo "  FAIL {$label}" . ($hint !== '' ? "\n       → {$hint}" : '') . "\n";
}
function warn(string $label): void
{
    global $warn;
    $warn++;
    echo "  warn {$label}\n";
}

/** Every `*.js` (not `*.min.js`) under `packages/**\/res/assets/js`. @return list<string> relative paths */
function sources(string $root): array
{
    $found = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/packages', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (!str_contains($path, '/res/assets/js/') || !str_ends_with($path, '.js') || str_ends_with($path, '.min.js')) {
            continue;
        }
        if (str_contains($path, '/node_modules/')) {
            continue;
        }
        $found[] = substr($path, strlen($root) + 1);
    }
    sort($found);
    return $found;
}

echo "markers\n";
foreach (MARKERS as $source => $markers) {
    $src = @file_get_contents($root . '/' . $source);
    $min = @file_get_contents($root . '/' . substr($source, 0, -3) . '.min.js');
    check("{$source} exists", $src !== false, 'the marker list names a file that is gone — update tests/js-min-markers.php');
    if ($src === false) continue;
    check("{$source} has a .min.js", $min !== false, "open {$source} in VS Code and save it");
    if ($min === false) continue;
    foreach ($markers as $marker) {
        if (!str_contains($src, $marker)) {
            check("marker «{$marker}» in the source", false, "the source no longer has it — update the marker list in tests/js-min-markers.php");
            continue;
        }
        check("«{$marker}» in " . basename($source, '.js') . '.min.js', str_contains($min, $marker),
            "stale .min.js — re-save {$source} in VS Code (minify on save)");
    }
}

echo "\nall sources\n";
foreach (sources($root) as $source) {
    $srcPath = $root . '/' . $source;
    $minPath = substr($srcPath, 0, -3) . '.min.js';
    $src     = (string) file_get_contents($srcPath);

    if (!is_file($minPath)) {
        warn("{$source}: no .min.js — production serves the source (open it in VS Code and save)");
        continue;
    }

    if (str_contains(substr($src, 0, 400), VENDOR_MARKER)) {
        check("{$source}: vendor build, .min.js is a byte copy", $src === file_get_contents($minPath),
            'copy the source over its .min.js — never minify a vendor build again (js-build.md)');
        continue;
    }

    if (filemtime($minPath) < filemtime($srcPath)) {
        warn("{$source}: .min.js is older than the source — re-save it in VS Code if the source changed");
    } else {
        $pass++;
    }
}

echo "\n{$pass} passed, {$fail} failed, {$warn} warnings\n";
exit($fail > 0 ? 1 : 0);
