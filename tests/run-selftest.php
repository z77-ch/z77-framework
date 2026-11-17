<?php

/**
 * Self-test of the test runner (tests/run.php, docs/topics/testing.md) — CLI, no database.
 *
 * It builds a throwaway git repository in the temp folder with fake harnesses and runs the REAL
 * runner on it with `--root`. What is load-bearing:
 *
 *   (A) a run of side-by-side harnesses: `ok` green with its two sections timed as they arrived (a
 *       1.2 s pause inside section A shows up as ≥ 1 s — the output is streamed, not read at the
 *       end), `red` red with its FAIL line repeated, `hang` killed by the watchdog together with
 *       the child process it started (`taskkill /T`), `stray` green but the stray migration it
 *       wrote reported by the tree guard and NOT deleted, `needs-tool` (exit 2, no check) skipped
 *       with its reason, a node harness run (or skipped when node is missing); the wall time below
 *       the sum; exit code 1; `result.json` next to the logs;
 *   (B) `--list` names an orphan (a harness in no group) and exits 1;
 *   (C) `--changed --list` picks the harness whose source names the changed package, and only it;
 *   (D) the REAL repository: `--list` exits 0 — every harness is in a group.
 *
 * Needs git on PATH (exit 2 without). Run: php tests/run-selftest.php
 */

$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

exec('git --version 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), $gitOut, $gitCode);
if ($gitCode !== 0) {
    fwrite(STDERR, "run-selftest: git not found on PATH.\n");
    exit(2);
}

$work = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-run-selftest-' . getmypid();
$rm   = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) { continue; }
        if (is_dir($f) && !is_link($f)) { @chmod($f, 0777); $rm($f); } else { @chmod($f, 0666); @unlink($f); }
    }
    @rmdir($dir);
};
register_shutdown_function(static fn() => $rm($work));

$write = function (string $rel, string $content) use ($work): void {
    @mkdir(dirname($work . '/' . $rel), 0777, true);
    file_put_contents($work . '/' . $rel, $content);
};

// The fake repository: one package, a map with an orphan, six fake harnesses; tmp/ = the runner's temp folder.
$write('.gitignore', "tmp/\n");
$write('packages/module-x/composer.json', '{"autoload": {"psr-4": {"Z77\\\\Module\\\\X\\\\": "src/"}}}');
$write('packages/module-x/src/Foo.php', "<?php\n// v1\n");
$write('packages/module-x/res/migrations/.keep', '');
$write('tests/groups.php', "<?php return ['settings' => ['jobs' => 6], 'groups' => ['fake' => ['ok', 'red', 'hang', 'stray', 'needs-tool', 'node-ok']], 'covers' => []];");
$write('tests/ok.php', <<<'PHP'
<?php
// covers Z77\Module\X\Foo
echo "A. First section\n";
echo "  ok   one\n";
usleep(1200000);
echo "B. Second section\n";
echo "B. … a sub-case stays in B\n";
usleep(300000);
echo "  ok   two\n";
echo "\n2 passed, 0 failed\n";
exit(0);
PHP);
$write('tests/red.php', "<?php\necho \"  ok   a\\n  FAIL b — the detail\\n\\n1 passed, 1 failed\\n\";\nexit(1);\n");
$write('tests/hang.php', <<<'PHP'
<?php
$child = proc_open([PHP_BINARY, '-r', 'sleep(120);'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
echo 'child: ' . proc_get_status($child)['pid'] . "\n";
echo "A. Waiting forever\n";
sleep(120);
PHP);
$write('tests/stray.php', "<?php\nfile_put_contents(__DIR__ . '/../packages/module-x/res/migrations/Version20990101000000.php', '<?php');\necho \"  ok   wrote it\\n\\n1 passed, 0 failed\\n\";\n");
$write('tests/needs-tool.php', "<?php\nfwrite(STDERR, \"needs-tool: no browser found, set Z77_BROWSER.\\n\");\nexit(2);\n");
$write('tests/node-ok.mjs', "console.log('  ok   from node');\nconsole.log('\\n1 passed, 0 failed');\n");
$write('tests/lonely.php', "<?php\necho \"  ok   alone\\n\\n1 passed, 0 failed\\n\";\n");

$git = 'git -C ' . escapeshellarg($work) . ' -c user.name=selftest -c user.email=selftest@runner.invalid -c core.autocrlf=false ';
exec($git . 'init -q && ' . $git . 'add -A && ' . $git . 'commit -q -m fake', $o, $code);
check('the fake repository is committed', $code === 0, implode(' ', $o));

/** Runs the real runner; its temp folder (logs, durations) inside the fake repository's work dir. */
$runner = function (array $args) use ($root, $work): array {
    @mkdir($work . '/tmp', 0777, true);
    $env  = getenv() + [];
    foreach (['TMP', 'TEMP', 'TMPDIR'] as $key) { $env[$key] = PHP_OS_FAMILY === 'Windows' ? str_replace('/', '\\', $work . '/tmp') : $work . '/tmp'; }
    $out  = $work . '/tmp/runner-' . uniqid() . '.txt';
    $proc = proc_open([PHP_BINARY, $root . '/tests/run.php', '--root=' . $work, ...$args], [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']], $pipes, $root, $env);
    fclose($pipes[0]);
    $code = proc_close($proc);

    return [$code, (string) file_get_contents($out)];
};

echo "A. A parallel run: ok, red, hung, stray file, missing tool, node\n";

$t = microtime(true);
[$code, $out] = $runner(['fake', '--idle=3', '--limit=30']);
$elapsed = microtime(true) - $t;
preg_match('#(\S+)/result\.json#', $out, $m);
$result  = isset($m[1]) && is_file($m[1] . '/result.json') ? json_decode((string) file_get_contents($m[1] . '/result.json'), true) : null;
$by      = [];
foreach ($result['harnesses'] ?? [] as $h) { $by[$h['name']] = $h; }
check('A1 the runner exits 1 (red + hung + a stray file) and writes result.json next to the logs', $code === 1 && $result !== null && count($by) === 6, "exit {$code}\n{$out}");

$ok = $by['ok'] ?? [];
$sections = array_column($ok['sections'] ?? [], 'seconds', 'label');
check('A2 ok: green, 2 passed, sections «(start)», A, B — the sub-case line stays in B; A timed ≥ 1 s (streamed), the slowest',
    ($ok['state'] ?? '') === 'ok' && $ok['passed'] === 2 && array_keys($sections) === ['(start)', 'A. First section', 'B. Second section']
    && $sections['A. First section'] >= 1.0 && $sections['B. Second section'] < 1.0 && ($ok['slowest']['label'] ?? '') === 'A. First section', json_encode($ok['sections'] ?? null));
check('A3 red: state red, 1 failed, its FAIL line kept and repeated below the table',
    ($by['red']['state'] ?? '') === 'red' && $by['red']['failed'] === 1 && $by['red']['failures'] === ['FAIL b — the detail'] && str_contains($out, "  FAIL b — the detail"), json_encode($by['red'] ?? null));

$hang = $by['hang'] ?? [];
preg_match('/child: (\d+)/', implode("\n", $hang['last'] ?? []), $cm);
$childAlive = null;
if (isset($cm[1])) {
    if (PHP_OS_FAMILY === 'Windows') {
        exec('tasklist /FI "PID eq ' . $cm[1] . '" /NH 2>NUL', $tl);
        $childAlive = str_contains(implode("\n", $tl), 'php');
    } else {
        $childAlive = @posix_kill((int) $cm[1], 0);
    }
}
check('A4 hang: killed by the watchdog after 3 s silent, reported hung with its reason and last lines; the child it started is gone too',
    ($hang['state'] ?? '') === 'hung' && str_contains((string) $hang['reason'], 'no output for 3 s') && $hang['seconds'] < 15
    && in_array('A. Waiting forever', $hang['last'], true) && $childAlive === false, json_encode($hang) . ' child alive: ' . var_export($childAlive, true));

$stray = array_values(array_filter($result['tree'] ?? [], fn(array $c) => $c['path'] === 'packages/module-x/res/migrations/Version20990101000000.php'));
check('A5 stray: its own checks green, the migration it wrote named by the tree guard (new, running then: stray) and NOT deleted',
    ($by['stray']['state'] ?? '') === 'ok' && count($stray) === 1 && $stray[0]['change'] === 'new' && in_array('stray', $stray[0]['running'], true)
    && is_file($work . '/packages/module-x/res/migrations/Version20990101000000.php') && str_contains($out, 'tree guard — RED'), json_encode($result['tree'] ?? null));
check('A6 needs-tool: exit 2 without a check is «skipped» with the harness\'s own reason',
    ($by['needs-tool']['state'] ?? '') === 'skipped' && str_contains((string) $by['needs-tool']['reason'], 'no browser found'), json_encode($by['needs-tool'] ?? null));
check('A7 node-ok (*.mjs): run with node — or skipped by the runner when node is missing',
    in_array($by['node-ok']['state'] ?? '', ['ok', 'skipped'], true) && (($by['node-ok']['state'] ?? '') === 'skipped' ? str_contains((string) $by['node-ok']['reason'], 'node') : $by['node-ok']['passed'] === 1), json_encode($by['node-ok'] ?? null));
$sum = array_sum(array_column($result['harnesses'] ?? [], 'seconds'));
check('A8 side by side: wall time below the sum of the harnesses\' seconds; the table has its header and a row per harness',
    ($result['wall'] ?? 999) < $sum && preg_match('/^harness\s+passed\s+failed\s+sec\s+slowest section\s+state$/m', $out) === 1
    && preg_match('/^hang\s+0\s+0\s+[\d.]+\s+A\. Waiting forever [\d.]+ s\s+hung$/mu', $out) === 1, sprintf('wall %.1f, sum %.1f', $result['wall'] ?? -1, $sum));

echo "B. --list: the catalog and its orphans\n";

[$code, $out] = $runner(['--list']);
check('B1 --list on a map that misses a harness exits 1 and names the orphan', $code === 1 && str_contains($out, '1 orphans: lonely') && preg_match('/^lonely\s+ORPHAN/m', $out) === 1, $out);
check('B2 the coverage read from the source: ok covers module-x (its namespace), red covers nothing outside itself',
    preg_match('/^ok\s+fake\s+module-x$/m', $out) === 1 && preg_match('/^red\s+fake\s*$/m', $out) === 1, $out);

echo "C. --changed: the harnesses whose coverage has changes\n";

exec($git . 'add -A && ' . $git . 'commit -q -m stray');
file_put_contents($work . '/packages/module-x/src/Foo.php', "<?php\n// v2\n");
[$code, $out] = $runner(['--changed', '--list']);
check('C1 a change in packages/module-x picks ok (namespace) and stray (path) — not red, which names neither', $code === 0 && preg_match('/^ok\s+changed: packages\/module-x\/src\/Foo\.php$/m', $out) === 1
    && preg_match('/^stray\s+changed: /m', $out) === 1 && !preg_match('/^red\s/m', $out) && str_contains($out, '2 harnesses would run'), $out);
exec($git . 'checkout -q -- .');
[$code, $out] = $runner(['--changed']);
check('C2 a clean tree: nothing to run, exit 0', $code === 0 && str_contains($out, 'nothing to run'), $out);

echo "D. The real repository: every harness in a group\n";

exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/run.php') . ' --list', $listOut, $code);
check('D1 php tests/run.php --list exits 0 — no orphan, no missing harness in tests/groups.php', $code === 0, implode("\n", array_slice($listOut, -1)));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
