<?php

/**
 * Delivery policy harness (CLI) — DMS-ACTIVE-001.
 *
 * `FileResponse` and `BytesResponse` both answer through `DeliveryPolicy`, so a stored file with
 * active content cannot run on the application's domain, whatever the caller asked for. All of it
 * in PHP's built-in web server (real status codes and headers). What is load-bearing:
 *
 *   (A) the whole table: per type the Content-Type, Disposition, nosniff and CSP of an inline
 *       request, on 200, 206, 304 and 416;
 *   (B) a download: always attachment, the type kept, the sandbox on everything (no exception for
 *       PDF, video, audio);
 *   (C) hostile spellings: case, parameters, empty, unknown, a list, a header injection attempt in
 *       the type or the file name, `+xml` under `video/`;
 *   (D) the bytes are the file's bytes, whole and ranged, whatever the type;
 *   (E) `BytesResponse` follows the same table;
 *   (F) no type given: FileResponse sniffs, and the policy judges the sniff.
 *
 * Run: php tests/file-delivery-policy.php
 */

$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$work = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-delivery-policy-' . getmypid();
@mkdir($work, 0777, true);

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; if (getenv('Z77_VERBOSE')) { echo "  ok   {$label}\n"; } }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

$body = "<html><head><title>t</title></head><body><script>document.title='ran'</script>\0\xff\xfe</body></html>\r\n";
file_put_contents($work . '/payload.bin', $body);

$router = <<<'PHP'
<?php
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Z77\\Core\\')) {
        $file = getenv('Z77_ROOT') . '/packages/kernel/core/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($file)) { require $file; }
    }
});
use Z77\Core\Http\Response\BytesResponse;
use Z77\Core\Http\Response\FileResponse;
$payload = getenv('Z77_WORK') . '/payload.bin';
$mime    = array_key_exists('nomime', $_GET) ? null : (string) ($_GET['m'] ?? '');
$inline  = ($_GET['d'] ?? 'inline') === 'inline';
$name    = (string) ($_GET['n'] ?? 'datei.bin');
$response = ($_GET['r'] ?? 'file') === 'bytes'
    ? new BytesResponse((string) file_get_contents($payload), $name, (string) $mime, $inline)
    : new FileResponse(
        path: $payload,
        filename: $name,
        mimeType: $mime,
        disposition: $inline ? FileResponse::INLINE : FileResponse::ATTACHMENT,
        etag: 'abc-original',
    );
$response->send();
PHP;
file_put_contents($work . '/router.php', $router);

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port  = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);

$env    = array_merge(getenv(), ['Z77_ROOT' => $root, 'Z77_WORK' => $work]);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $work . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $work . '/server.log', 'w'], 2 => ['file', $work . '/server.log', 'a']], $pipes, $work, $env);

/** @return array{0: int, 1: array<string, string>, 2: string} status, headers (lower-case names), body */
$get = function (array $query, array $headers = []) use ($port): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20, 'header' => implode("\r\n", $headers)]]);
    $response = @file_get_contents("http://127.0.0.1:{$port}/?" . http_build_query($query), false, $ctx);
    $status = 0;
    $out    = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; $out = []; continue; }
        if (str_contains($h, ':')) {
            [$k, $v] = explode(':', $h, 2);
            $out[strtolower(trim($k))] = trim($v);
        }
    }
    return [$status, $out, (string) $response];
};

$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(100_000);
    $s = @fsockopen('127.0.0.1', $port);
    if ($s) { fclose($s); $up = true; }
}
check('built-in server started', $up);

const SVG_CSP = "sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'";
const SANDBOX = 'sandbox';
const AS_TEXT = 'text/plain; charset=utf-8';

/** PHP appends its default charset to a text type, so the type is compared without parameters. */
function essence(string $type): string
{
    return strtolower(trim(explode(';', $type, 2)[0]));
}

/** The four headers on one answer. `$type` null = the essence of `$mime`. */
$sees = function (string $label, array $h, ?string $type, string $disposition, ?string $csp) {
    check("{$label}: type {$type}", essence($h['content-type'] ?? '') === essence((string) $type), $h['content-type'] ?? '(none)');
    if ($type === AS_TEXT) {
        check("{$label}: exactly the text answer", strtolower($h['content-type'] ?? '') === AS_TEXT, $h['content-type'] ?? '(none)');
    }
    check("{$label}: {$disposition}", str_starts_with($h['content-disposition'] ?? '', $disposition . ';'), $h['content-disposition'] ?? '(none)');
    check("{$label}: nosniff", ($h['x-content-type-options'] ?? '') === 'nosniff', $h['x-content-type-options'] ?? '(none)');
    check("{$label}: CSP " . ($csp ?? 'none'), ($h['content-security-policy'] ?? null) === $csp, $h['content-security-policy'] ?? '(none)');
};

/** Every status the FileResponse can answer. */
$everyStatus = function (array $query, ?string $type, string $disposition, ?string $csp, string $label) use ($get, $sees, $body) {
    [$st, $h, $b] = $get($query);
    check("{$label}: 200", $st === 200, (string) $st);
    check("{$label}: 200 bytes identical", $b === $body, strlen($b) . ' bytes');
    $sees("{$label} (200)", $h, $type, $disposition, $csp);

    [$st, $h, $b] = $get($query, ['Range: bytes=6-25']);
    check("{$label}: 206", $st === 206, (string) $st);
    check("{$label}: 206 bytes identical", $b === substr($body, 6, 20), strlen($b) . ' bytes');
    $sees("{$label} (206)", $h, $type, $disposition, $csp);

    [$st, $h, $b] = $get($query, ['If-None-Match: "abc-original"']);
    check("{$label}: 304, no body", $st === 304 && $b === '', (string) $st);
    $sees("{$label} (304)", $h, $type, $disposition, $csp);

    [$st, $h] = $get($query, ['Range: bytes=99999-']);
    check("{$label}: 416", $st === 416, (string) $st);
    $sees("{$label} (416)", $h, $type, $disposition, $csp);
};

// mime as stored, the answer's type, disposition and CSP of an INLINE request
$table = [
    // raster images, one by one: as is, sandbox
    ['image/jpeg', 'image/jpeg', 'inline', SANDBOX],
    ['image/png', 'image/png', 'inline', SANDBOX],
    ['image/gif', 'image/gif', 'inline', SANDBOX],
    ['image/webp', 'image/webp', 'inline', SANDBOX],
    ['image/avif', 'image/avif', 'inline', SANDBOX],
    ['image/bmp', 'image/bmp', 'inline', SANDBOX],
    ['image/x-icon', 'image/x-icon', 'inline', SANDBOX],
    ['image/vnd.microsoft.icon', 'image/vnd.microsoft.icon', 'inline', SANDBOX],
    ['image/tiff', 'image/tiff', 'inline', SANDBOX],
    // media a browser plays or shows in its own viewer: as is, no CSP
    ['application/pdf', 'application/pdf', 'inline', null],
    ['video/mp4', 'video/mp4', 'inline', null],
    ['video/quicktime', 'video/quicktime', 'inline', null],
    ['audio/mpeg', 'audio/mpeg', 'inline', null],
    // inert text: as is, sandbox
    ['text/plain', 'text/plain', 'inline', SANDBOX],
    ['text/csv', 'text/csv', 'inline', SANDBOX],
    ['application/json', 'application/json', 'inline', SANDBOX],
    // SVG: as is, without script
    ['image/svg+xml', 'image/svg+xml', 'inline', SVG_CSP],
    ['image/x-svg', 'image/x-svg', 'inline', SVG_CSP],
    // textual active content: shown as its source
    ['text/html', AS_TEXT, 'inline', SANDBOX],
    ['application/xhtml+xml', AS_TEXT, 'inline', SANDBOX],
    ['text/xml', AS_TEXT, 'inline', SANDBOX],
    ['application/xml', AS_TEXT, 'inline', SANDBOX],
    ['application/rss+xml', AS_TEXT, 'inline', SANDBOX],
    ['application/javascript', AS_TEXT, 'inline', SANDBOX],
    ['application/ecmascript', AS_TEXT, 'inline', SANDBOX],
    ['application/x-javascript', AS_TEXT, 'inline', SANDBOX],
    ['text/javascript', AS_TEXT, 'inline', SANDBOX],
    ['text/css', AS_TEXT, 'inline', SANDBOX],
    ['text/x-php', AS_TEXT, 'inline', SANDBOX],
    // everything else: a download
    ['application/octet-stream', 'application/octet-stream', 'attachment', SANDBOX],
    ['application/zip', 'application/zip', 'attachment', SANDBOX],
    ['application/msword', 'application/msword', 'attachment', SANDBOX],
    ['application/x-unknown', 'application/x-unknown', 'attachment', SANDBOX],
    ['image/heic', 'image/heic', 'attachment', SANDBOX],
    ['font/woff2', 'font/woff2', 'attachment', SANDBOX],
];

if ($up) {
    echo "(A) the table, inline request, on 200 206 304 416\n";
    foreach ($table as [$mime, $type, $disposition, $csp]) {
        $everyStatus(['m' => $mime], $type, $disposition, $csp, $mime);
    }

    echo "(B) a download keeps its type and is sandboxed\n";
    foreach ($table as [$mime, , , ]) {
        $csp = str_contains($mime, 'svg') ? SVG_CSP : SANDBOX;
        $everyStatus(['m' => $mime, 'd' => 'attachment'], $mime, 'attachment', $csp, "{$mime} download");
    }

    echo "(C) hostile spellings\n";
    $hostile = [
        // spelling, type, disposition, CSP
        ['TEXT/HTML; charset=x', AS_TEXT, 'inline', SANDBOX],
        ['  Text/Html  ', AS_TEXT, 'inline', SANDBOX],
        ['text/html;', AS_TEXT, 'inline', SANDBOX],
        ['IMAGE/SVG+XML; charset=utf-8', 'image/svg+xml', 'inline', SVG_CSP],
        ['APPLICATION/XHTML+XML', AS_TEXT, 'inline', SANDBOX],
        ['IMAGE/PNG', 'image/png', 'inline', SANDBOX],
        ['VIDEO/MP4; codecs="avc1"', 'video/mp4', 'inline', null],
        // a browser renders any +xml as a document: it must not pass as video or audio
        ['video/x+xml', AS_TEXT, 'inline', SANDBOX],
        ['audio/foo+xml', AS_TEXT, 'inline', SANDBOX],
        ['video/xml', AS_TEXT, 'inline', SANDBOX],
        ['image/foo+xml', AS_TEXT, 'inline', SANDBOX],
        ['application/svg+xml', AS_TEXT, 'inline', SANDBOX],
        ['application/pdf+xml', AS_TEXT, 'inline', SANDBOX],
        // empty, unknown and malformed: the binary fallback, a download
        ['', 'application/octet-stream', 'attachment', SANDBOX],
        ['   ', 'application/octet-stream', 'attachment', SANDBOX],
        ['; charset=utf-8', 'application/octet-stream', 'attachment', SANDBOX],
        ['garbage', 'application/octet-stream', 'attachment', SANDBOX],
        ['image/png, text/html', 'application/octet-stream', 'attachment', SANDBOX],
        ['text/html text/plain', 'application/octet-stream', 'attachment', SANDBOX],
        ['/', 'application/octet-stream', 'attachment', SANDBOX],
        ["text/html\r\nX-Evil: 1", 'application/octet-stream', 'attachment', SANDBOX],
        // a parameter header() would refuse: the type survives, without it
        ["text/plain; a=b\r\nX-Evil: 1", 'text/plain', 'inline', SANDBOX],
        ["image/png; a=\x01", 'image/png', 'inline', SANDBOX],
    ];
    foreach ($hostile as [$mime, $type, $disposition, $csp]) {
        $label = json_encode($mime);
        $everyStatus(['m' => $mime], $type, $disposition, $csp, $label);
        [, $h] = $get(['m' => $mime]);
        check("{$label}: nothing injected", !isset($h['x-evil']));
    }

    [$st, $h] = $get(['m' => 'text/html', 'd' => 'attachment', 'n' => "a\r\nX-Evil: 1\x7f\".html"]);
    check('a file name with control characters still yields the attachment header',
        ($h['content-disposition'] ?? '') === 'attachment; filename="aX-Evil: 1\\".html"', $h['content-disposition'] ?? '(none)');
    check('... and injects nothing', !isset($h['x-evil']));
    [, $h] = $get(['m' => 'text/plain', 'n' => 'Müller Rechnung.txt']);
    check('a normal name stays as it was', ($h['content-disposition'] ?? '') === 'inline; filename="Müller Rechnung.txt"', $h['content-disposition'] ?? '(none)');

    echo "(D) a suffix range on a rewritten type\n";
    [$st, $h, $b] = $get(['m' => 'text/html'], ['Range: bytes=-10']);
    check('suffix range on html: the last bytes, as text', $st === 206 && $b === substr($body, -10) && essence($h['content-type'] ?? '') === 'text/plain', $st . ' ' . ($h['content-type'] ?? ''));

    echo "(E) BytesResponse\n";
    foreach ($table as [$mime, $type, $disposition, $csp]) {
        [$st, $h, $b] = $get(['r' => 'bytes', 'm' => $mime]);
        check("bytes {$mime}: 200, identical", $st === 200 && $b === $body, $st . ' / ' . strlen($b));
        check("bytes {$mime}: Content-Length", ($h['content-length'] ?? '') === (string) strlen($body), $h['content-length'] ?? '(none)');
        $sees("bytes {$mime}", $h, $type, $disposition, $csp);
        [$st, $h, $b] = $get(['r' => 'bytes', 'm' => $mime, 'd' => 'attachment']);
        check("bytes {$mime} download: identical", $b === $body, (string) strlen($b));
        $sees("bytes {$mime} download", $h, $mime, 'attachment', str_contains($mime, 'svg') ? SVG_CSP : SANDBOX);
    }
    foreach ($hostile as [$mime, $type, $disposition, $csp]) {
        [, $h, $b] = $get(['r' => 'bytes', 'm' => $mime]);
        $label = 'bytes ' . json_encode($mime);
        check("{$label}: identical", $b === $body, (string) strlen($b));
        $sees($label, $h, $type, $disposition, $csp);
    }
    // What the application actually sends: the invoice PDF and the CSV export.
    [, $h] = $get(['r' => 'bytes', 'm' => 'application/pdf', 'n' => 'rechnung-12.pdf']);
    $sees('invoice PDF, inline', $h, 'application/pdf', 'inline', null);
    [, $h] = $get(['r' => 'bytes', 'm' => 'text/csv; charset=utf-8', 'd' => 'attachment', 'n' => 'export.csv']);
    $sees('CSV export', $h, 'text/csv', 'attachment', SANDBOX);
    check('CSV export keeps its charset', strtolower($h['content-type'] ?? '') === 'text/csv; charset=utf-8', $h['content-type'] ?? '');

    echo "(F) no type given: the sniff is judged\n";
    [$st, $h, $b] = $get(['nomime' => 1, 'd' => 'inline']);
    check('sniffed file: delivered unchanged', $st === 200 && $b === $body, $st . ' / ' . strlen($b));
    check('sniffed file: never its own active type', !preg_match('#html|xml#i', essence($h['content-type'] ?? '')), $h['content-type'] ?? '');
    check('sniffed file: nosniff', ($h['x-content-type-options'] ?? '') === 'nosniff');
    file_put_contents($work . '/payload.bin', '<!DOCTYPE html><html><script>alert(1)</script></html>');
    [, $h] = $get(['nomime' => 1, 'd' => 'inline']);
    $sees('a .txt that sniffs as HTML', $h, AS_TEXT, 'inline', SANDBOX);
    file_put_contents($work . '/payload.bin', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    [, $h] = $get(['nomime' => 1, 'd' => 'inline']);
    $sees('an SVG, sniffed', $h, 'image/svg+xml', 'inline', SVG_CSP);
}

proc_terminate($server);
proc_close($server);
foreach (['payload.bin', 'router.php', 'server.log'] as $file) {
    @unlink($work . '/' . $file);
}
@rmdir($work);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
