<?php

/**
 * The test runner — ONE entry for every harness (docs/topics/testing.md).
 *
 *   php tests/run.php <group|harness …> [--jobs=N] [--idle=S] [--limit=S] [--from=SECTION]
 *   php tests/run.php --changed            the harnesses whose covered paths have changes
 *   php tests/run.php --list [selection]   the catalog (groups, coverage, orphans) or what WOULD run
 *   npm run test -- <same arguments>
 *
 * Each harness runs as its own process (`php` for *.php, `node` for *.mjs), up to `--jobs` side by
 * side, longest first (by the last run's durations). Output goes to one log per harness under
 * `<temp>/z77-test-runs/<run>/` — never into the repository — and is timestamped per line as it
 * arrives, which gives seconds per section without touching the harnesses. A watchdog kills a
 * harness tree that is silent for `--idle` seconds or runs longer than `--limit` (state `hung`).
 * The tree guard compares migrations folders and untracked files in packages/ + tests/ before and
 * after and names what appeared. At the end: one table, the failed checks below it, `result.json`
 * next to the logs, exit 0 only when nothing is red, hung or left behind.
 *
 * Groups and settings: tests/groups.php. `--root=DIR` runs against another tree (the runner's own
 * self-test, tests/run-selftest.php).
 */

require __DIR__ . '/support/runner/HarnessCatalog.php';
require __DIR__ . '/support/runner/HarnessProcess.php';
require __DIR__ . '/support/runner/TreeGuard.php';

use Z77\Tests\Runner\HarnessCatalog;
use Z77\Tests\Runner\HarnessProcess;
use Z77\Tests\Runner\TreeGuard;

$options = ['changed' => false, 'list' => false];
$words   = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(jobs|idle|limit|from|root)=(.+)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    } elseif ($arg === '--changed' || $arg === '--list') {
        $options[substr($arg, 2)] = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "usage: php tests/run.php <group|harness …> [--changed] [--list] [--jobs=N] [--idle=S] [--limit=S] [--from=SECTION]\n";
        exit(0);
    } elseif (str_starts_with($arg, '-')) {
        fwrite(STDERR, "run: unknown option {$arg} (--help)\n");
        exit(2);
    } else {
        $words[] = $arg;
    }
}

$root = str_replace('\\', '/', (string) realpath($options['root'] ?? __DIR__ . '/..'));
if ($root === '' || !is_file($root . '/tests/groups.php')) {
    fwrite(STDERR, "run: no tests/groups.php under «{$root}»\n");
    exit(2);
}
$map      = require $root . '/tests/groups.php';
$settings = ($map['settings'] ?? []) + ['jobs' => 4, 'idleSeconds' => 300, 'limitSeconds' => 3600];
$jobs     = max(1, (int) ($options['jobs'] ?? $settings['jobs']));
$idle     = max(1, (int) ($options['idle'] ?? $settings['idleSeconds']));
$limit    = max(1, (int) ($options['limit'] ?? $settings['limitSeconds']));
$catalog  = new HarnessCatalog($root, $map);

// ── --list without a selection: the catalog ─────────────────────────────

if ($options['list'] && $words === [] && !$options['changed']) {
    foreach ($catalog->groups() as $group => $members) {
        echo str_pad($group, 12) . ' ' . count($members) . ': ' . ($group === 'all' ? '(every harness)' : implode(' ', $members)) . "\n";
    }
    echo "\n";
    foreach (array_keys($catalog->harnesses()) as $name) {
        $groups = $catalog->groupsOf($name);
        $covers = array_filter($catalog->covers($name), fn(string $p) => !str_starts_with($p, 'tests/' . $name . '.'));
        echo str_pad($name, 34) . str_pad($groups === [] ? 'ORPHAN' : implode(',', $groups), 22) . ' '
            . implode(' ', array_map(fn(string $p) => preg_replace('#^packages/#', '', $p), $covers)) . "\n";
    }
    $orphans = $catalog->orphans();
    $unknown = $catalog->unknownMembers();
    echo "\n" . count($catalog->harnesses()) . ' harnesses, ' . count($orphans) . ' orphans' . ($orphans === [] ? '' : ': ' . implode(', ', $orphans))
        . ($unknown === [] ? '' : '; the map names missing harnesses: ' . implode(', ', $unknown)) . "\n";
    exit($orphans === [] && $unknown === [] ? 0 : 1);
}

// ── the selection ───────────────────────────────────────────────────────

try {
    $selected = $catalog->resolve($words);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, 'run: ' . $e->getMessage() . "\n");
    exit(2);
}
$why = array_fill_keys($selected, 'named');
if ($options['changed']) {
    [$picked, $uncovered] = $catalog->touchedBy(changedPaths($root));
    foreach ($picked as $name => $paths) {
        $why[$name] = ($why[$name] ?? '') === 'named' ? 'named' : 'changed: ' . shortList($paths);
    }
    $selected = array_values(array_unique(array_merge($selected, array_keys($picked))));
    if ($uncovered !== []) {
        echo 'changes no harness covers: ' . shortList($uncovered) . "\n";
    }
}
if ($selected === []) {
    if ($options['changed']) {
        echo "nothing to run — no harness covers the changed paths\n";
        exit(0);
    }
    fwrite(STDERR, "usage: php tests/run.php <group|harness …> [--changed] [--list] [--jobs=N] — groups: " . implode(', ', array_keys($catalog->groups())) . "\n");
    exit(2);
}
sort($selected);
if (isset($options['from']) && count($selected) !== 1) {
    fwrite(STDERR, "run: --from=SECTION needs exactly one harness, the selection has " . count($selected) . "\n");
    exit(2);
}
if ($options['list']) {
    foreach ($selected as $name) {
        echo str_pad($name, 34) . ($why[$name] ?? 'named') . "\n";
    }
    echo count($selected) . " harnesses would run\n";
    exit(0);
}

// ── the run ─────────────────────────────────────────────────────────────

$runsDir = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-test-runs';
@mkdir($runsDir, 0777, true);
foreach (glob($runsDir . '/run-*', GLOB_ONLYDIR) ?: [] as $old) {
    if (filemtime($old) < time() - 7 * 86400) {
        removeTree($old);
    }
}
$runDir = $runsDir . '/run-' . date('Ymd-His') . '-' . getmypid();
mkdir($runDir, 0777, true);
$durationsFile = $runsDir . '/durations.json';
$durations     = is_file($durationsFile) ? (array) json_decode((string) file_get_contents($durationsFile), true) : [];
usort($selected, fn(string $a, string $b) => ($durations[$b] ?? 0) <=> ($durations[$a] ?? 0) ?: strcmp($a, $b));

// vendor/z77/* are links onto packages/ by ABSOLUTE path. In a git worktree with a copied or linked
// vendor/ they point into ANOTHER tree: the harnesses would load that tree's code and test nothing
// of this one. Refuse instead of reporting green (TEST-WT-001).
$packagesReal = realpath($root . '/packages');
foreach (glob($root . '/vendor/z77/*') ?: [] as $link) {
    $target = realpath($link);
    if ($target !== false && $packagesReal !== false && !str_starts_with(strtolower($target), strtolower($packagesReal))) {
        fwrite(STDERR, "run: vendor/z77/" . basename($link) . " points to {$target} — outside this tree's packages/ ({$packagesReal}).\n"
            . "     Run `composer install` in this tree first (a worktree needs its own vendor/z77 links).\n");
        exit(2);
    }
}

$node   = findNode();
$noDb   = !hasDbPassword();
$extra  = isset($options['from']) ? ['--from=' . $options['from']] : [];
$queue  = [];
foreach ($selected as $name) {
    $file    = $catalog->file($name);
    $command = str_ends_with($file, '.mjs') ? ($node === null ? null : [$node, $file, ...$extra]) : [PHP_BINARY, $file, ...$extra];
    $queue[] = new HarnessProcess($name, $file, $runDir . '/' . $name . '.log', $command, $root);
}

printf("run: %d harnesses, %d at a time, watchdog %d s silent / %d s total — logs %s\n", count($queue), $jobs, $idle, $limit, $runDir);
if ($noDb) {
    echo "run: no z77test password (%USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD) — database sections report SKIPPED (state no-db)\n";
}

$treeBefore = TreeGuard::snapshot($root);
$t0         = microtime(true);
$all        = $queue;
$running    = [];
$lastStatus = $t0;
$stamp      = fn(float $now) => sprintf('[%5ds]', (int) round($now - $t0));

while ($queue !== [] || $running !== []) {
    $now = microtime(true);
    while (count($running) < $jobs && $queue !== []) {
        $p = array_shift($queue);
        if (str_ends_with($p->file, '.mjs') && $node === null) {
            $p->started = $now;
            $p->skip('node not found on PATH');
            echo $stamp($now) . " skip   {$p->name} — node not found\n";
            continue;
        }
        $p->start($now);
        if (!$p->isRunning()) {
            echo $stamp($now) . " skip   {$p->name} — could not start\n";
            continue;
        }
        $running[] = $p;
        echo $stamp($now) . " start  {$p->name}\n";
    }
    foreach ($running as $i => $p) {
        if (!$p->poll($now, $idle, $limit)) {
            $r = $p->result();
            printf("%s %-6s %s  %d passed, %d failed  %.1f s%s\n", $stamp($now), $r['state'], $p->name, $r['passed'], $r['failed'], $r['seconds'], $r['reason'] ? '  — ' . $r['reason'] : '');
            unset($running[$i]);
        }
    }
    $running = array_values($running);
    if ($running !== [] && $now - $lastStatus >= 60) {
        $lastStatus = $now;
        echo $stamp($now) . ' running: ' . implode(' · ', array_map(fn(HarnessProcess $p) => sprintf('%s (%s, silent %d s)',
            $p->name, mb_strimwidth($p->currentSection(), 0, 40, '…'), (int) ($now - $p->lastOutputAt())), $running)) . "\n";
    }
    usleep(200000);
}
$wall = microtime(true) - $t0;

// ── tree guard ──────────────────────────────────────────────────────────

$treeAfter = TreeGuard::snapshot($root);
$results   = array_map(fn(HarnessProcess $p) => $p->result(), $all);
usort($results, fn(array $a, array $b) => strcmp($a['name'], $b['name']));
$tree = [];
if ($treeBefore !== null && $treeAfter !== null) {
    foreach (TreeGuard::compare($treeBefore, $treeAfter, $root) as $change) {
        $suspects = [];
        foreach ($all as $p) {
            if ($change['mtime'] !== null && $change['mtime'] >= (int) $p->started - 1 && $change['mtime'] <= (int) ($p->ended ?? microtime(true)) + 1) {
                $suspects[] = $p->name;
            }
        }
        $tree[] = $change + ['running' => $suspects];
    }
}

// ── report ──────────────────────────────────────────────────────────────

echo "\n";
$rows = [['harness', 'passed', 'failed', 'sec', 'slowest section', 'state']];
foreach ($results as $r) {
    $slow   = $r['slowest'] === null ? '—' : mb_strimwidth($r['slowest']['label'], 0, 38, '…') . sprintf(' %.1f s', $r['slowest']['seconds']);
    $rows[] = [$r['name'], (string) $r['passed'], (string) $r['failed'], sprintf('%.1f', $r['seconds']), $slow, $r['state']];
}
$widths = [];
foreach ($rows as $row) {
    foreach ($row as $c => $cell) {
        $widths[$c] = max($widths[$c] ?? 0, mb_strwidth($cell));
    }
}
foreach ($rows as $row) {
    $line = '';
    foreach ($row as $c => $cell) {
        $pad   = str_repeat(' ', $widths[$c] - mb_strwidth($cell));
        $line .= (in_array($c, [1, 2, 3], true) ? $pad . $cell : $cell . $pad) . '  ';
    }
    echo rtrim($line) . "\n";
}

foreach ($results as $r) {
    if (!in_array($r['state'], ['red', 'hung', 'skipped'], true)) {
        continue;
    }
    echo "\n{$r['name']} — {$r['state']}" . ($r['reason'] ? " ({$r['reason']})" : '') . "\n";
    foreach (array_slice($r['failures'], 0, 25) as $failure) {
        echo "  {$failure}\n";
    }
    if (count($r['failures']) > 25) {
        echo '  … ' . (count($r['failures']) - 25) . " more\n";
    }
    if ($r['state'] === 'hung' || ($r['failures'] === [] && $r['state'] === 'red')) {
        echo "  last lines:\n";
        foreach ($r['last'] as $line) {
            echo '    ' . mb_strimwidth($line, 0, 160, '…') . "\n";
        }
    }
    echo "  log: {$r['log']}\n";
}

if ($treeBefore === null || $treeAfter === null) {
    echo "\ntree guard: not checked (git status failed)\n";
} elseif ($tree !== []) {
    echo "\ntree guard — RED: the working tree changed during the run (nothing was deleted):\n";
    foreach ($tree as $change) {
        echo "  {$change['change']}  {$change['path']}" . ($change['running'] !== [] ? '  — running then: ' . implode(', ', $change['running']) : '') . "\n";
    }
}

$count = array_count_values(array_column($results, 'state'));
$sum   = array_sum(array_column($results, 'seconds'));
echo "\n" . count($results) . ' harnesses: ' . implode(', ', array_map(fn($s, $n) => "{$n} {$s}", array_keys($count), $count))
    . sprintf(' · wall %.1f s (sum %.1f s) · %s/result.json', $wall, $sum, $runDir) . "\n";

// Re-read: another run may have written the file meanwhile.
$durations = is_file($durationsFile) ? (array) json_decode((string) file_get_contents($durationsFile), true) : [];
foreach ($results as $r) {
    if (in_array($r['state'], ['ok', 'red', 'no-db'], true)) {
        $durations[$r['name']] = $r['seconds'];
    }
}
file_put_contents($durationsFile, json_encode($durations, JSON_PRETTY_PRINT));
file_put_contents($runDir . '/result.json', json_encode([
    'root'      => $root,
    'arguments' => array_slice($argv, 1),
    'jobs'      => $jobs,
    'started'   => date('c', (int) $t0),
    'wall'      => round($wall, 1),
    'harnesses' => $results,
    'tree'      => $tree,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$bad = ($count['red'] ?? 0) + ($count['hung'] ?? 0) + count($tree);
exit($bad === 0 ? 0 : 1);

// ── helpers ─────────────────────────────────────────────────────────────

/** @return list<string> changed paths: the working tree (tracked + untracked) and the commits not on origin/main */
function changedPaths(string $root): array
{
    $git   = 'git -C ' . escapeshellarg($root) . ' ';
    $null  = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $paths = [];
    exec($git . 'status --porcelain=v1 -z --untracked-files=all 2>' . $null, $out);
    $entries = explode("\0", implode("\n", $out));
    for ($i = 0, $n = count($entries); $i < $n; $i++) {
        if (strlen($entries[$i]) < 4) {
            continue;
        }
        $paths[] = substr($entries[$i], 3);
        if (in_array($entries[$i][0], ['R', 'C'], true) && isset($entries[$i + 1])) {
            $paths[] = $entries[++$i]; // -z puts the rename's source as the next entry
        }
    }
    exec($git . 'rev-parse --verify -q origin/main 2>' . $null, $ref, $code);
    if ($code === 0) {
        $diff = [];
        exec($git . 'diff --name-only origin/main...HEAD 2>' . $null, $diff);
        $paths = array_merge($paths, $diff);
    }

    return array_values(array_unique(array_map(fn(string $p) => str_replace('\\', '/', trim($p, '"')), array_filter($paths))));
}

/** @param list<string> $paths */
function shortList(array $paths): string
{
    return implode(', ', array_slice($paths, 0, 3)) . (count($paths) > 3 ? ' (+' . (count($paths) - 3) . ')' : '');
}

function findNode(): ?string
{
    $out = [];
    exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null', $out, $code);

    return $code === 0 && ($out[0] ?? '') !== '' ? trim($out[0]) : null;
}

/** The harnesses' own rule: Z77_TEST_DB_PASSWORD, or a `z77test=` line in %USERPROFILE%\.z77\mariadb.txt. */
function hasDbPassword(): bool
{
    if ((string) getenv('Z77_TEST_DB_PASSWORD') !== '') {
        return true;
    }
    $file = rtrim((string) (getenv('USERPROFILE') ?: getenv('HOME')), '/\\') . '/.z77/mariadb.txt';
    $user = getenv('Z77_TEST_DB_USER') ?: 'z77test';

    return is_file($file) && preg_match('/^' . preg_quote($user, '/') . '=./m', (string) file_get_contents($file)) === 1;
}

function removeTree(string $dir): void
{
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) {
            continue;
        }
        is_dir($f) ? removeTree($f) : @unlink($f);
    }
    @rmdir($dir);
}
