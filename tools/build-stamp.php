<?php

/**
 * Stamp a deployable vendor/ with the provenance of the source trees it was
 * built from — the writer behind `Z77\Shared\Build\BuildInfo`.
 *
 * A deploy build copies working trees into `vendor/z77/*`. Without a stamp,
 * nothing on the server says WHICH state that was: composer.lock records a
 * content hash for path repos, not a commit. This writes that fact next to
 * the copies, so it can never travel separately from them.
 *
 * Run from each project's `vendor-deploy.bat`, AFTER the copies are in place:
 *
 *   php tools/build-stamp.php <target.json> framework=<path> [propbase=<path>]
 *
 * Exit code is always 0. A missing stamp must never block a deploy — but a
 * failure says so loudly, because a silent empty value would be read as a
 * statement.
 *
 * The dirty flag is the point of the exercise: we copy a working tree, not a
 * commit. A hash without it would name a state that was never shipped.
 */

$argvIn = $argv ?? [];
array_shift($argvIn);

$target  = array_shift($argvIn);
$sources = [];

foreach ($argvIn as $pair) {
    if (!str_contains($pair, '=')) {
        fwrite(STDERR, "  build-stamp: ignoriert '{$pair}' — erwartet name=pfad\n");
        continue;
    }
    [$name, $path] = explode('=', $pair, 2);
    $name = trim($name);
    $path = trim($path);
    if ($name !== '' && $path !== '') {
        $sources[$name] = $path;
    }
}

if ($target === null || $sources === []) {
    fwrite(STDERR, "  build-stamp: Aufruf: php tools/build-stamp.php <ziel.json> framework=<pfad> [propbase=<pfad>]\n");
    exit(0);
}

/** One `git` question against a working tree; null when git cannot answer. */
$git = static function (string $path, string $args): ?string {
    $command = 'git -C ' . escapeshellarg($path) . ' ' . $args . ' 2>&1';
    $output  = [];
    $status  = 0;
    exec($command, $output, $status);

    if ($status !== 0) {
        return null;
    }

    return trim(implode("\n", $output));
};

$stamp   = ['built_at' => time()];
$unknown = [];

foreach ($sources as $name => $path) {
    if (!is_dir($path)) {
        $unknown[] = "{$name} (Pfad fehlt: {$path})";
        $stamp[$name] = ['commit' => null, 'branch' => null, 'dirty' => false, 'committed_at' => null];
        continue;
    }

    $commit = $git($path, 'rev-parse HEAD');
    if ($commit === null || !preg_match('/^[0-9a-f]{40}$/', $commit)) {
        // No git, no repository, or a broken checkout. Say "unknown" rather
        // than writing an empty string that would render as a claim.
        $unknown[] = "{$name} (git antwortet nicht)";
        $stamp[$name] = ['commit' => null, 'branch' => null, 'dirty' => false, 'committed_at' => null];
        continue;
    }

    $branch = $git($path, 'rev-parse --abbrev-ref HEAD');
    $time   = $git($path, 'log -1 --format=%ct');
    $status = $git($path, 'status --porcelain');

    $stamp[$name] = [
        'commit'       => $commit,
        'branch'       => ($branch === null || $branch === '' || $branch === 'HEAD') ? null : $branch,
        'dirty'        => is_string($status) && trim($status) !== '',
        'committed_at' => ($time !== null && ctype_digit($time)) ? (int)$time : null,
    ];
}

$directory = dirname($target);
if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
    fwrite(STDERR, "  build-stamp: FEHLER — {$directory} nicht anlegbar, kein Stempel geschrieben.\n");
    exit(0);
}

// json_encode writes UTF-8 without BOM; a BOM would make json_decode() refuse
// the file on the server (BuildInfo strips one defensively, but not here).
$json    = json_encode($stamp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$written = @file_put_contents($target, $json);

if ($written === false) {
    fwrite(STDERR, "  build-stamp: FEHLER — {$target} nicht schreibbar, kein Stempel geschrieben.\n");
    exit(0);
}

foreach ($sources as $name => $path) {
    $entry = $stamp[$name];
    if ($entry['commit'] === null) {
        echo "  build-stamp: {$name} = unbekannt\n";
        continue;
    }
    $line = '  build-stamp: ' . $name . ' = ' . substr($entry['commit'], 0, 7)
        . ($entry['dirty'] ? '+ (LOKAL GEAENDERT)' : '')
        . ($entry['branch'] !== null ? ' auf ' . $entry['branch'] : '')
        . ($entry['committed_at'] !== null ? ' vom ' . date('d.m.Y', $entry['committed_at']) : '');
    echo $line . "\n";
}

if ($unknown !== []) {
    fwrite(STDERR, "  build-stamp: WARNUNG — ohne Herkunft: " . implode(', ', $unknown) . "\n");
}

echo "  build-stamp: geschrieben nach {$target}\n";
exit(0);
