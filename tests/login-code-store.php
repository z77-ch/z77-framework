<?php

/**
 * The one-time login code store of the kernel (`Z77\Shared\Auth\LoginCodeStore`, ADR-058) — CLI,
 * a temp directory, no framework boot. What is load-bearing:
 *
 *   (A) a record per REQUEST: nonce 64 hex, code 6 digits; the file is named by sha256(nonce) and
 *       holds neither nonce nor code; a request for nobody (unknown address) writes a record of
 *       the same shape; two requests of one subject live side by side (no request replaces
 *       another — nobody locks a known address out by requesting codes);
 *   (B) judging: the right code with its nonce → the subject, once; a wrong nonce counts against
 *       nobody; five tries, then even the right code is refused; expiry; spaces forgiven, a
 *       non-digit code refused; a record of nobody never signs in, not even with its code;
 *   (C) the lock: several PROCESSES verifying the same right code at once → exactly one subject;
 *   (D) forgetSubject spends a subject's records and leaves the others, reading each under its
 *       lock; sweep drops old files; the caps refuse a write (stored=false) with the same shape of
 *       answer — two quotas (review S2): a full nobody quota never stops a subject's record; the
 *       over-cap log line comes once per window and directory.
 *
 * Run: php tests/login-code-store.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "login-code-store: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Z77\Shared\Auth\LoginCodeStore;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-login-codes-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($base, 0777, true);
register_shutdown_function(static function () use ($base): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($base);
});

$dir   = $base . '/login';
$store = new LoginCodeStore($dir);
$now   = time();

// ── A. one record per request ────────────────────────────────────────────────
echo "A. records\n";
$a = $store->issue('7', $now);
$file = $dir . '/' . hash('sha256', $a['nonce']) . '.json';
$raw  = (string) @file_get_contents($file);
$rec  = json_decode($raw, true);
check('A1 nonce 64 hex, code 6 digits, stored', preg_match('/^[0-9a-f]{64}$/', $a['nonce']) === 1 && preg_match('/^\d{6}$/', $a['code']) === 1 && $a['stored'] === true);
check('A2 the file is sha256(nonce).json and holds neither nonce nor code, only sha256(nonce . code)',
    is_file($file) && !str_contains($raw, $a['nonce']) && !str_contains($raw, '"' . $a['code'] . '"')
    && ($rec['hash'] ?? '') === hash('sha256', $a['nonce'] . $a['code']) && ($rec['subject'] ?? null) === '7'
    && ($rec['tries'] ?? null) === 0 && ($rec['expires'] ?? 0) === $now + LoginCodeStore::TTL_SECONDS, $raw);
$nobody = $store->issue(null, $now);
$recN   = json_decode((string) @file_get_contents($dir . '/nobody/' . hash('sha256', $nobody['nonce']) . '.json'), true);
check('A3 a request for nobody (unknown address) writes a record of the same shape, subject null — in its own directory (quota, review S2)',
    $nobody['stored'] === true && is_array($recN) && array_keys($recN) === array_keys($rec) && $recN['subject'] === null
    && !is_file($dir . '/' . hash('sha256', $nobody['nonce']) . '.json'));
$b = $store->issue('7', $now);
check('A4 a second request of the same subject does not replace the first: both records live', is_file($file) && $store->liveCount() === 3);

// ── B. judging ───────────────────────────────────────────────────────────────
echo "B. verify\n";
$wrong = $a['code'] === '000000' ? '000001' : '000000';
check('B1 a guess with ANOTHER nonce counts against nobody', $store->verify($b['nonce'], $a['code'] === $b['code'] ? $wrong : $a['code'], $now) === null
    && (json_decode((string) file_get_contents($file), true)['tries'] ?? -1) === 0);
check('B2 the right code with its nonce (spaces forgiven) → the subject', $store->verify($a['nonce'], ' ' . substr($a['code'], 0, 3) . ' ' . substr($a['code'], 3), $now) === '7');
check('B3 … once: the record is spent', $store->verify($a['nonce'], $a['code'], $now) === null && !is_file($file));
check('B4 the other request of the same subject still works', $store->verify($b['nonce'], $b['code'], $now) === '7');

$c = $store->issue('8', $now);
$wrongC = $c['code'] === '000000' ? '000001' : '000000';
$results = [];
for ($i = 0; $i < LoginCodeStore::MAX_TRIES - 1; $i++) {
    $results[] = $store->verify($c['nonce'], $wrongC, $now);
}
check('B5 four wrong tries: refused, the record counts them', $results === array_fill(0, 4, null)
    && (json_decode((string) file_get_contents($dir . '/' . hash('sha256', $c['nonce']) . '.json'), true)['tries'] ?? -1) === 4);
$d = $store->issue('8', $now);
$wrongD = $d['code'] === '000000' ? '000001' : '000000';
for ($i = 0; $i < LoginCodeStore::MAX_TRIES; $i++) {
    $store->verify($d['nonce'], $wrongD, $now);
}
check('B6 after five wrong tries even the right code is refused (the record is spent)', $store->verify($d['nonce'], $d['code'], $now) === null);
check('B7 the fifth try may still be the right one', $store->verify($c['nonce'], $c['code'], $now) === '8');

$e = $store->issue('9', $now);
check('B8 expired → refused', $store->verify($e['nonce'], $e['code'], $now + LoginCodeStore::TTL_SECONDS + 1) === null);
$f = $store->issue('9', $now);
check('B9 letters, too short, a malformed nonce → refused', $store->verify($f['nonce'], 'abcdef', $now) === null
    && $store->verify($f['nonce'], substr($f['code'], 0, 5), $now) === null && $store->verify('nope', $f['code'], $now) === null);
check('B10 a record of NOBODY never signs in, not even with its own code', $store->verify($nobody['nonce'], $nobody['code'], $now) === null);

// ── C. the lock across processes ─────────────────────────────────────────────
echo "C. parallel verifies\n";
$child = $base . '/child.php';
file_put_contents($child, '<?php
require ' . var_export($autoload, true) . ';
[$dir, $nonce, $code, $at] = array_slice($argv, 1);
while (microtime(true) < (float) $at) { usleep(500); }
$subject = (new Z77\Shared\Auth\LoginCodeStore($dir))->verify($nonce, $code);
echo $subject === null ? "-" : $subject;
');
$g     = $store->issue('42', time());
$at    = (string) (microtime(true) + 1.5);
$procs = [];
for ($i = 0; $i < 4; $i++) {
    $procs[] = proc_open([PHP_BINARY, $child, $dir, $g['nonce'], $g['code'], $at], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$outs = [];
foreach ($procs as $i => $proc) {
    $outs[] = trim((string) stream_get_contents($pipes[$i][1])) . trim((string) stream_get_contents($pipes[$i][2]));
    proc_close($proc);
}
sort($outs);
check('C1 four processes verify the same right code at once → exactly ONE gets the subject (one device)', $outs === ['-', '-', '-', '42'], implode(',', $outs));

// ── D. forget, sweep, cap ────────────────────────────────────────────────────
echo "D. forget, sweep, cap\n";
$h1 = $store->issue('77', $now);
$h2 = $store->issue('77', $now);
$h3 = $store->issue('78', $now);
check('D1 forgetSubject spends every record of that subject and leaves the others',
    $store->forgetSubject('77') === 2 && $store->verify($h1['nonce'], $h1['code'], $now) === null
    && $store->verify($h2['nonce'], $h2['code'], $now) === null && $store->verify($h3['nonce'], $h3['code'], $now) === '78');

$old = $store->issue('5', $now);
touch($dir . '/' . hash('sha256', $old['nonce']) . '.json', $now - LoginCodeStore::TTL_SECONDS - 10);
$store->sweep($now);
check('D2 sweep drops a record older than a code may live', !is_file($dir . '/' . hash('sha256', $old['nonce']) . '.json'));

$logFile  = $base . '/php-error.log';
ini_set('error_log', $logFile);
$capLines = fn(): int => substr_count((string) @file_get_contents($logFile), 'live records in');
$capped   = new LoginCodeStore($base . '/capped', 2, 2);
$n1 = $capped->issue(null, $now);
$n2 = $capped->issue(null, $now);
$n3 = $capped->issue(null, $now);
$n4 = $capped->issue(null, $now);
check('D3 past the nobody cap no record is written (stored=false) — the answer keeps its shape (nonce + code)',
    $n1['stored'] && $n2['stored'] && !$n3['stored'] && !$n4['stored']
    && preg_match('/^[0-9a-f]{64}$/', $n3['nonce']) === 1 && preg_match('/^\d{6}$/', $n3['code']) === 1);
$k1 = $capped->issue('1', $now);
$k2 = $capped->issue('1', $now);
$k3 = $capped->issue('1', $now);
check('D4 S2: a full nobody quota does NOT stop a real sign-in — the records of a subject have their own quota',
    $k1['stored'] && $k2['stored'] && !$k3['stored'] && $capped->liveCount() === 4,
    json_encode([$k1['stored'], $k2['stored'], $k3['stored'], $capped->liveCount()]));
check('D5 … and the subject records still work', $capped->verify($k1['nonce'], $k1['code'], $now) === '1');
check('D6 the over-cap line is logged once per window and directory, not per request', $capLines() === 2, (string) $capLines());
$capped->issue(null, $now + LoginCodeStore::TTL_SECONDS + 1);
check('D7 … and again in the next window', $capLines() === 3, (string) $capLines());
ini_restore('error_log');

// forgetSubject reads under the lock: a record held mid-rewrite by a verifier is still spent.
$m     = $store->issue('91', $now);
$mFile = $dir . '/' . hash('sha256', $m['nonce']) . '.json';
$mRaw  = (string) file_get_contents($mFile);
$forgetChild = $base . '/forget.php';
file_put_contents($forgetChild, '<?php
require ' . var_export($autoload, true) . ';
echo (new Z77\Shared\Auth\LoginCodeStore($argv[1]))->forgetSubject($argv[2]);
');
$fh = fopen($mFile, 'r+');
flock($fh, LOCK_EX);
ftruncate($fh, 0); // a verifier between truncate and write
$proc = proc_open([PHP_BINARY, $forgetChild, $dir, '91'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $fp);
usleep(1_000_000);
rewind($fh);
fwrite($fh, $mRaw);
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);
$forgot = trim((string) stream_get_contents($fp[1])) . trim((string) stream_get_contents($fp[2]));
proc_close($proc);
check('D8 forgetSubject waits for the record\'s lock, then spends it (an unlocked read saw an empty file and skipped it)',
    $forgot === '1' && $store->verify($m['nonce'], $m['code'], $now) === null, $forgot);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
