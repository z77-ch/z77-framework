<?php

/**
 * Build stamp harness (CLI) — the provenance of a deployed vendor/.
 *
 * Two halves:
 *   1. `Z77\Shared\Build\BuildInfo` reading a stamp — including every way a
 *      stamp can be absent or broken, because this renders on EVERY backend
 *      page and must never throw.
 *   2. `tools/build-stamp.php` writing one against a real throwaway git
 *      repository, so the chain bat → writer → reader → panel is proven, not
 *      assumed. Skipped with a message when git is unavailable.
 *
 * The load-bearing case is DIRTY: a deploy build copies a working tree, not a
 * commit. A stamp that named a commit without saying "plus uncommitted
 * changes" would describe a state that was never shipped.
 *
 * Run: php tests/build-info.php
 * Uses a throwaway directory in the system temp; removed on success.
 */

require __DIR__ . '/../packages/kernel/shared/src/Build/BuildInfo.php';

use Z77\Shared\Build\BuildInfo;

$work = sys_get_temp_dir() . '/z77-build-info-' . getmypid();
@mkdir($work, 0777, true);

$checks = 0;
$failed = 0;

function check(string $label, bool $ok): void
{
    global $checks, $failed;
    $checks++;
    if (!$ok) {
        $failed++;
        echo "  FAIL  {$label}\n";
        return;
    }
    echo "  ok    {$label}\n";
}

function rmtree(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        rmtree($path . '/' . $entry);
    }
    @rmdir($path);
}

function writeStamp(string $file, array $data): void
{
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

echo "\n== Lesen ==\n";

$file = $work . '/build.json';

// --- absent / broken: all of these mean "no stamp", none of them may throw ---
check('fehlende Datei = kein Stempel', BuildInfo::read($file) === null);

file_put_contents($file, '');
check('leere Datei = kein Stempel', BuildInfo::read($file) === null);

file_put_contents($file, '{"framework": {"commit": "abc"');
check('abgeschnittenes JSON = kein Stempel', BuildInfo::read($file) === null);

file_put_contents($file, '"nur ein String"');
check('JSON ohne Objekt = kein Stempel', BuildInfo::read($file) === null);

writeStamp($file, ['built_at' => 1756200000]);
check('Stempel ohne Quelle = kein Stempel', BuildInfo::read($file) === null);

// --- the real thing ---
$committedAt = mktime(18, 9, 20, 8, 25, 2026);
writeStamp($file, [
    'built_at'  => 1756200000,
    'framework' => [
        'commit'       => '8b1b1c6aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'branch'       => 'feat/public-form-standard',
        'dirty'        => false,
        'committed_at' => $committedAt,
    ],
    'propbase'  => [
        'commit'       => '0baf9aabbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'branch'       => 'main',
        'dirty'        => false,
        'committed_at' => $committedAt,
    ],
]);

$stamp = BuildInfo::read($file);
check('gueltiger Stempel wird gelesen', $stamp !== null);
check('beide Quellen erkannt', $stamp->sources() === ['framework', 'propbase']);
check('has() kennt framework', $stamp->has(BuildInfo::FRAMEWORK));
check('has() kennt Unbekanntes nicht', !$stamp->has('nope'));
check('voller Hash bleibt voll', $stamp->commit(BuildInfo::FRAMEWORK) === '8b1b1c6aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
check('Branch wird gelesen', $stamp->branch(BuildInfo::FRAMEWORK) === 'feat/public-form-standard');
check('Label = 7 Zeichen ohne Plus', $stamp->label(BuildInfo::FRAMEWORK) === '8b1b1c6');
check('Datum = Commit-Datum', $stamp->date(BuildInfo::FRAMEWORK) === '25.08.2026');
check('builtAt() gelesen', $stamp->builtAt() === 1756200000);
check('unbekannte Quelle: commit null', $stamp->commit('nope') === null);
check('unbekannte Quelle: nicht dirty', !$stamp->isDirty('nope'));

// --- dirty: the flag this whole exercise exists for ---
writeStamp($file, [
    'built_at'  => 1756200000,
    'framework' => ['commit' => '8b1b1c6aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'branch' => 'main', 'dirty' => true, 'committed_at' => $committedAt],
]);
$stamp = BuildInfo::read($file);
check('dirty wird gelesen', $stamp->isDirty(BuildInfo::FRAMEWORK));
check('Label traegt das Plus', $stamp->label(BuildInfo::FRAMEWORK) === '8b1b1c6+');

// --- git could not be asked: "unbekannt", never an empty claim ---
writeStamp($file, [
    'built_at'  => 1756200000,
    'framework' => ['commit' => null, 'branch' => null, 'dirty' => false, 'committed_at' => null],
]);
$stamp = BuildInfo::read($file);
check('ohne Commit ist der Stempel trotzdem da', $stamp !== null);
check('Label sagt unbekannt', $stamp->label(BuildInfo::FRAMEWORK) === 'unbekannt');
check('Datum faellt auf die Bauzeit zurueck', $stamp->date(BuildInfo::FRAMEWORK) === date('d.m.Y', 1756200000));

// --- a hand-edited file may carry a BOM; json_decode() would refuse it ---
file_put_contents($file, "\xEF\xBB\xBF" . json_encode([
    'built_at'  => 1756200000,
    'framework' => ['commit' => '8b1b1c6aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'branch' => 'main', 'dirty' => false, 'committed_at' => $committedAt],
]));
check('BOM wird abgestreift', BuildInfo::read($file)?->label(BuildInfo::FRAMEWORK) === '8b1b1c6');

echo "\n== Schreiben (tools/build-stamp.php) ==\n";

exec('git --version 2>&1', $probe, $probeStatus);
if ($probeStatus !== 0) {
    echo "  SKIP  git nicht verfuegbar — der Schreib-Teil laeuft nicht.\n";
} else {
    $repo = $work . '/repo';
    @mkdir($repo, 0777, true);
    $q = static fn(string $p): string => escapeshellarg($p);

    exec('git -C ' . $q($repo) . ' init -q 2>&1');
    exec('git -C ' . $q($repo) . ' config user.email test@z77.ch 2>&1');
    exec('git -C ' . $q($repo) . ' config user.name Test 2>&1');
    file_put_contents($repo . '/a.txt', "eins\n");
    exec('git -C ' . $q($repo) . ' add -A 2>&1');
    exec('git -C ' . $q($repo) . ' commit -qm erst 2>&1');

    $head = trim(shell_exec('git -C ' . $q($repo) . ' rev-parse HEAD') ?? '');
    $out  = $work . '/written.json';
    $tool = __DIR__ . '/../tools/build-stamp.php';

    exec(escapeshellarg(PHP_BINARY) . ' ' . $q($tool) . ' ' . $q($out) . ' framework=' . $q($repo) . ' 2>&1', $lines, $status);

    check('Schreiber endet mit 0', $status === 0);
    check('Datei ist entstanden', is_file($out));
    check('Datei ohne BOM', substr(file_get_contents($out), 0, 3) !== "\xEF\xBB\xBF");

    $written = BuildInfo::read($out);
    check('Geschriebenes ist lesbar', $written !== null);
    check('Commit stimmt mit git ueberein', $written->commit(BuildInfo::FRAMEWORK) === $head);
    check('sauberer Baum ist nicht dirty', !$written->isDirty(BuildInfo::FRAMEWORK));
    check('Commit-Zeit gesetzt', is_int($written->committedAt(BuildInfo::FRAMEWORK)));
    check('Bauzeit gesetzt', is_int($written->builtAt()));

    // --- the case the flag exists for: build from a dirty tree ---
    file_put_contents($repo . '/a.txt', "zwei\n");
    exec(escapeshellarg(PHP_BINARY) . ' ' . $q($tool) . ' ' . $q($out) . ' framework=' . $q($repo) . ' 2>&1');
    $written = BuildInfo::read($out);
    check('schmutziger Baum wird als dirty gestempelt', $written->isDirty(BuildInfo::FRAMEWORK));
    check('Label traegt das Plus', str_ends_with($written->label(BuildInfo::FRAMEWORK), '+'));

    // --- a path that is no repository: "unknown", and the deploy still runs ---
    $noRepo = $work . '/kein-repo';
    @mkdir($noRepo, 0777, true);
    exec(escapeshellarg(PHP_BINARY) . ' ' . $q($tool) . ' ' . $q($out) . ' framework=' . $q($noRepo) . ' 2>&1', $l2, $s2);
    check('kein Repository: Schreiber endet trotzdem mit 0', $s2 === 0);
    $written = BuildInfo::read($out);
    check('kein Repository: Label sagt unbekannt', $written?->label(BuildInfo::FRAMEWORK) === 'unbekannt');

    // --- two sources in one stamp, the shape a real deploy writes ---
    exec(escapeshellarg(PHP_BINARY) . ' ' . $q($tool) . ' ' . $q($out) . ' framework=' . $q($repo) . ' propbase=' . $q($repo) . ' 2>&1');
    $written = BuildInfo::read($out);
    check('zwei Quellen landen im selben Stempel', $written->sources() === ['framework', 'propbase']);

    // --- no arguments: says how, exits 0, writes nothing ---
    $before = file_get_contents($out);
    exec(escapeshellarg(PHP_BINARY) . ' ' . $q($tool) . ' 2>&1', $l3, $s3);
    check('ohne Argumente: endet mit 0', $s3 === 0);
    check('ohne Argumente: nichts ueberschrieben', file_get_contents($out) === $before);
}

echo "\n";
if ($failed > 0) {
    echo "FEHLGESCHLAGEN: {$failed} von {$checks}\n\n";
    exit(1);
}

rmtree($work);
echo "OK: {$checks} Pruefungen\n\n";
exit(0);
