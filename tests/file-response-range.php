<?php

/**
 * FileResponse range harness (CLI) — DMS-CHUNK-DL-001, z77-rudaz.ch 2026-10-31.
 *
 * The host caps a response body (cyon: 500 MB → 413), so ONE ranged answer carries at most
 * `FileResponse::RANGE_MAX_BYTES` (256 MiB, half the cap — owner 2026-10-31). What is
 * load-bearing:
 *
 *   (U) the cap applies to EVERY single range — open-ended `bytes=n-`, explicit `a-b`,
 *       suffix `-k` — and the answer is the FIRST slice of what was asked; below the cap a
 *       range is served as asked. Exercised on `byteRange()` through reflection with a
 *       small `$max` (a 256 MiB file per run is not an option); its default IS the constant;
 *   (A) no `Range` → unchanged: 200, the whole file, no Content-Range;
 *   (B) a range answers 206 and Content-Range names the slice AND the true total (a capped
 *       answer never looks like the whole file) — real headers, real SAPI;
 *   (C) explicit ranges are clamped at the last byte;
 *   (D) a suffix `bytes=-k` answers the last k bytes;
 *   (E) 416 with `bytes *\/size` for an unsatisfiable range, an empty file and `bytes=-`;
 *       an unparsable / multi-range header falls back to 200 (as before);
 *   (F) the ETag stays on every answer and If-None-Match still answers 304;
 *   (G) the bytes of every slice are the bytes of the file at that offset.
 *
 * (A)–(G) run in PHP's built-in web server (real status codes and headers).
 * Run: php tests/file-response-range.php
 */

$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$work = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-file-range-' . getmypid();
@mkdir($work, 0777, true);

require $root . '/packages/kernel/core/src/Http/Response/ResponseInterface.php';
require $root . '/packages/kernel/core/src/Http/Response/FileResponse.php';

use Z77\Core\Http\Response\FileResponse;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

echo "(U) the cap — every single range, first slice\n";
check('RANGE_MAX_BYTES is 256 MiB', FileResponse::RANGE_MAX_BYTES === 256 * 1024 * 1024, (string) FileResponse::RANGE_MAX_BYTES);
$byteRange = new ReflectionMethod(FileResponse::class, 'byteRange');
$maxParam  = $byteRange->getParameters()[2] ?? null;
check('byteRange() defaults its cap to RANGE_MAX_BYTES', $maxParam !== null && $maxParam->getDefaultValue() === FileResponse::RANGE_MAX_BYTES);
$r = static fn(string $h, int $size, int $max = 1000) => $byteRange->invoke(null, $h, $size, $max);
$cases = [
    // label, header, size, expected
    ['open-ended from 0 → first slice',         'bytes=0-',          10_000, [0, 999]],
    ['open-ended in the middle → capped',       'bytes=4321-',       10_000, [4321, 5320]],
    ['open-ended rest below the cap → as asked', 'bytes=9500-',      10_000, [9500, 9999]],
    ['explicit larger than the cap → capped',   'bytes=0-9999',      10_000, [0, 999]],
    ['explicit exactly the cap → as asked',     'bytes=1000-1999',   10_000, [1000, 1999]],
    ['explicit below the cap → as asked',       'bytes=100-199',     10_000, [100, 199]],
    ['explicit beyond the file → clamped',      'bytes=9990-20000',  10_000, [9990, 9999]],
    ['suffix below the cap → the last k bytes', 'bytes=-500',        10_000, [9500, 9999]],
    ['suffix larger than the cap → its first slice', 'bytes=-3000',  10_000, [7000, 7999]],
    ['suffix larger than the file → from 0, capped', 'bytes=-20000', 10_000, [0, 999]],
    ['start at the size → unsatisfiable',       'bytes=10000-',      10_000, false],
    ['bytes=- → unsatisfiable',                 'bytes=-',           10_000, false],
    ['empty file → unsatisfiable',              'bytes=0-',          0,      false],
    ['no header → whole file',                  '',                  10_000, null],
    ['multi-range → whole file (as before)',    'bytes=0-1,5-6',     10_000, null],
];
foreach ($cases as [$label, $header, $size, $want]) {
    $got = $r($header, $size);
    check($label, $got === $want, var_export($got, true));
}
check('production cap: bytes=0- on 1 GiB → 256 MiB', $byteRange->invoke(null, 'bytes=0-', 1 << 30) === [0, FileResponse::RANGE_MAX_BYTES - 1]);
check('production cap: 32 MiB client piece untouched', $byteRange->invoke(null, 'bytes=33554432-67108863', 1 << 30) === [33554432, 67108863]);

// ── the files for the real server: 2.5 MiB of deterministic bytes, and an empty one ──
$piece = 1024 * 1024;
$size  = (int) ($piece * 2.5) + 17;
$bytes = '';
for ($i = 0; strlen($bytes) < $size; $i++) {
    $bytes .= hash('sha256', (string) $i, true);
}
$bytes = substr($bytes, 0, $size);
file_put_contents($work . '/big.bin', $bytes);
file_put_contents($work . '/empty.bin', '');

$router = <<<'PHP'
<?php
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Z77\\Core\\')) {
        $file = getenv('Z77_ROOT') . '/packages/kernel/core/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($file)) { require $file; }
    }
});
$file = ($_GET['f'] ?? '') === 'empty' ? 'empty.bin' : 'big.bin';
(new Z77\Core\Http\Response\FileResponse(
    path: getenv('Z77_WORK') . '/' . $file,
    filename: 'Muster Datei.bin',
    mimeType: 'application/octet-stream',
    etag: 'abc123-original',
))->send();
PHP;
file_put_contents($work . '/router.php', $router);

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port  = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);

$env    = array_merge(getenv(), ['Z77_ROOT' => $root, 'Z77_WORK' => $work]);
$cmd    = [PHP_BINARY, '-S', "127.0.0.1:{$port}", $work . '/router.php'];
$server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $work . '/server.log', 'w'], 2 => ['file', $work . '/server.log', 'a']], $pipes, $work, $env);

/** @return array{0: int, 1: array<string, string>, 2: string} status, headers (lower-case names), body */
$get = function (array $headers = [], string $query = '') use ($port): array {
    $ctx = stream_context_create(['http' => [
        'ignore_errors' => true,
        'timeout'       => 20,
        'header'        => implode("\r\n", $headers),
    ]]);
    $body   = @file_get_contents("http://127.0.0.1:{$port}/{$query}", false, $ctx);
    $status = 0;
    $out    = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; $out = []; continue; }
        if (str_contains($h, ':')) {
            [$k, $v] = explode(':', $h, 2);
            $out[strtolower(trim($k))] = trim($v);
        }
    }
    return [$status, $out, (string) $body];
};

$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(100_000);
    $s = @fsockopen('127.0.0.1', $port);
    if ($s) { fclose($s); $up = true; }
}
check('built-in server started', $up);

/** One ranged request: status, Content-Range, Content-Length and the bytes at that offset. */
$range = function (string $label, string $header, int $start, int $end) use ($get, $bytes, $size): void {
    [$st, $h, $body] = $get(['Range: ' . $header]);
    $len = $end - $start + 1;
    check("{$label}: 206", $st === 206, (string) $st);
    check("{$label}: Content-Range bytes {$start}-{$end}/{$size}", ($h['content-range'] ?? '') === "bytes {$start}-{$end}/{$size}", $h['content-range'] ?? '(none)');
    check("{$label}: Content-Length {$len}", ($h['content-length'] ?? '') === (string) $len, $h['content-length'] ?? '(none)');
    check("{$label}: the file's bytes at that offset", $body === substr($bytes, $start, $len), strlen($body) . ' bytes');
};

if ($up) {
    echo "(A) no Range — unchanged\n";
    [$st, $h, $body] = $get();
    check('200', $st === 200, (string) $st);
    check('whole file', $body === $bytes, strlen($body) . ' bytes');
    check('Content-Length = size', ($h['content-length'] ?? '') === (string) $size, $h['content-length'] ?? '(none)');
    check('no Content-Range', !isset($h['content-range']), $h['content-range'] ?? '');
    check('Accept-Ranges: bytes', ($h['accept-ranges'] ?? '') === 'bytes');
    check('attachment with the filename', str_contains($h['content-disposition'] ?? '', 'attachment; filename="Muster Datei.bin"'), $h['content-disposition'] ?? '');

    echo "(B) open-ended — 206 with the true total\n";
    $range('bytes=0-', 'bytes=0-', 0, $size - 1);
    $range('bytes=n- (middle)', 'bytes=' . ($piece + 5) . '-', $piece + 5, $size - 1);

    echo "(C) explicit\n";
    $range('small explicit range', 'bytes=100-199', 100, 199);
    $range('one piece', 'bytes=' . $piece . '-' . (2 * $piece - 1), $piece, 2 * $piece - 1);
    $range('end beyond the file → clamped', 'bytes=' . ($size - 10) . '-' . ($size + 1000), $size - 10, $size - 1);

    echo "(D) suffix\n";
    $range('bytes=-500', 'bytes=-500', $size - 500, $size - 1);
    $range('suffix larger than the file → whole file', 'bytes=-' . ($size * 2), 0, $size - 1);

    echo "(E) unsatisfiable / unparsable\n";
    [$st, $h] = $get(['Range: bytes=' . $size . '-']);
    check('start at the size → 416', $st === 416, (string) $st);
    check('416 names the total', ($h['content-range'] ?? '') === "bytes */{$size}", $h['content-range'] ?? '(none)');
    [$st] = $get(['Range: bytes=-']);
    check('bytes=- → 416', $st === 416, (string) $st);
    [$st] = $get(['Range: bytes=-0']);
    check('bytes=-0 → 416', $st === 416, (string) $st);
    [$st] = $get(['Range: bytes=500-100']);
    check('start after end → 416', $st === 416, (string) $st);
    [$st, $h] = $get(['Range: bytes=0-8388607'], '?f=empty');
    check('empty file → 416 bytes */0', $st === 416 && ($h['content-range'] ?? '') === 'bytes */0', $st . ' ' . ($h['content-range'] ?? ''));
    [$st, $h, $body] = $get(['Range: bytes=0-1,5-6']);
    check('multi-range → 200 whole file (as before)', $st === 200 && strlen($body) === $size, $st . ' / ' . strlen($body));

    echo "(F) ETag\n";
    [$st, $h] = $get(['Range: bytes=0-']);
    check('ETag on a 206', ($h['etag'] ?? '') === '"abc123-original"', $h['etag'] ?? '(none)');
    [$st, $h, $body] = $get(['If-None-Match: "abc123-original"']);
    check('If-None-Match → 304, no body', $st === 304 && $body === '', (string) $st);
}

proc_terminate($server);
proc_close($server);
@unlink($work . '/big.bin');
@unlink($work . '/empty.bin');
@unlink($work . '/router.php');
@unlink($work . '/server.log');
@rmdir($work);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
