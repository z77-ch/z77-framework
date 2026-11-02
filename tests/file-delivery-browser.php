<?php

/**
 * Delivery policy in a real browser (CLI, headless Edge/Chrome) — DMS-ACTIVE-001.
 *
 * Headers are only half of it: this harness asks a browser whether the script in a stored file
 * actually runs. Each payload writes a marker (a request to `/marker`) and sets the title. What is
 * load-bearing:
 *
 *   (control) the same payloads WITHOUT the policy do write their marker, so a silent run below
 *       means something: the harness can see a script run;
 *   (A) HTML, XHTML, SVG, XML with script through `FileResponse` and `BytesResponse`: no marker;
 *   (B) «Öffnen» shows HTML as its source; an SVG still draws; a PDF inline still renders;
 *   (C) what the application consumes keeps working: `<img>` with raster and SVG, `fetch()` of
 *       JSON, `<audio>` with ranges, all behind nosniff and the sandbox.
 *
 * It also reports, without judging it, what the browser does with a PDF under `sandbox`: that is
 * why PDF has no sandbox in the table.
 *
 * Needs a browser (msedge, chrome; or Z77_BROWSER=path) and GD for the pixel checks. Exit 2 when no
 * browser is found: a run without one has verified nothing.
 * Run: php tests/file-delivery-browser.php
 */

$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$work = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-delivery-browser-' . getmypid();
mkdir($work . '/profile', 0777, true);

$browser = getenv('Z77_BROWSER') ?: null;
foreach (['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
          'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/microsoft-edge', '/usr/bin/google-chrome', '/usr/bin/chromium'] as $candidate) {
    $browser ??= is_file($candidate) ? $candidate : null;
}
if ($browser === null) {
    fwrite(STDERR, "file-delivery-browser: no browser found, set Z77_BROWSER.\n");
    exit(2);
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "file-delivery-browser: GD is needed for the pixel checks.\n");
    exit(2);
}
echo "browser: {$browser}\n";

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

// ── payloads ────────────────────────────────────────────────────────────────────────────────

$script = static fn (string $key): string => "document.title='SCRIPT-RAN';new Image().src='/marker?x={$key}';fetch('/marker?x={$key}-fetch');";

/** A one-page PDF with a red box. Offsets are computed, a viewer checks them. */
function minimalPdf(): string
{
    $stream  = "1 0 0 rg 72 300 450 400 re f\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << >> >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}endstream",
    ];
    $pdf     = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n{$object}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}

/** One second of silence as a WAV: the only playable media a harness can write itself. */
function minimalWav(): string
{
    $rate = 8000;
    $data = str_repeat("\x80", $rate);
    return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate, 1, 8) . 'data' . pack('V', strlen($data)) . $data;
}

$png = imagecreatetruecolor(20, 20);
imagefill($png, 0, 0, imagecolorallocate($png, 0, 0, 255));
ob_start();
imagepng($png);
$pngBytes = (string) ob_get_clean();

$payloads = [
    // key => [stored MIME, bytes]
    'html'    => ['text/html', "<!DOCTYPE html><html><head><title>clean</title></head><body><h1>ACTIVE-HTML</h1><script>" . $script('html') . "</script><img src=\"/none\" onerror=\"new Image().src='/marker?x=html-onerror'\"></body></html>"],
    'htmlcs'  => ['TEXT/HTML; charset=x', "<!DOCTYPE html><html><head><title>clean</title></head><body><script>" . $script('htmlcs') . "</script></body></html>"],
    'xhtml'   => ['application/xhtml+xml', "<?xml version=\"1.0\"?><html xmlns=\"http://www.w3.org/1999/xhtml\"><head><title>clean</title></head><body><script>" . $script('xhtml') . "</script></body></html>"],
    'xml'     => ['text/xml', "<?xml version=\"1.0\"?><root><html:script xmlns:html=\"http://www.w3.org/1999/xhtml\">" . $script('xml') . "</html:script></root>"],
    'svg'     => ['image/svg+xml', "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"400\" height=\"300\" onload=\"new Image().src='/marker?x=svg-onload'\"><rect width=\"400\" height=\"300\" fill=\"#ff0000\"/><script>" . $script('svg') . "</script></svg>"],
    'plain'   => ['text/plain', '<script>' . $script('plain') . '</script>'],
    'unknown' => ['application/x-unknown', '<script>' . $script('unknown') . '</script>'],
    'pdf'     => ['application/pdf', minimalPdf()],
    'png'     => ['image/png', $pngBytes],
    'json'    => ['application/json', '{"ok":true}'],
    'wav'     => ['audio/wav', minimalWav()],
];
foreach ($payloads as $key => [, $bytes]) {
    file_put_contents("{$work}/{$key}.bin", $bytes);
}
file_put_contents($work . '/payloads.json', json_encode(array_map(static fn ($p) => $p[0], $payloads)));

$consumers = <<<'HTML'
<!DOCTYPE html><html><head><title>consumers</title></head><body>
<img id="png" src="/file/png" onload="mark('img-png')">
<img id="svg" src="/file/svg" onload="mark('img-svg')">
<audio id="wav" src="/file/wav" preload="auto" oncanplay="mark('audio-wav')"></audio>
<script>
function mark(x) { new Image().src = '/marker?x=' + x; }
fetch('/file/json').then(function (r) { return r.json(); }).then(function (j) { if (j.ok) mark('fetch-json'); });
fetch('/file/plain').then(function (r) { return r.text(); }).then(function (t) { if (t.indexOf('<script>') === 0) mark('fetch-text'); });
</script></body></html>
HTML;
file_put_contents($work . '/consumers.html', $consumers);

$router = <<<'PHP'
<?php
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Z77\\Core\\')) {
        $file = getenv('Z77_ROOT') . '/packages/kernel/core/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($file)) { require $file; }
    }
});
$work = getenv('Z77_WORK');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/marker') {
    file_put_contents($work . '/markers.log', ($_GET['x'] ?? '?') . "\n", FILE_APPEND);
    http_response_code(204);
    return;
}
if ($path === '/consumers') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($work . '/consumers.html');
    return;
}
$parts = explode('/', trim($path, '/'));
$mode  = $parts[0] ?? '';
$key   = $parts[1] ?? '';
$mimes = json_decode((string) file_get_contents($work . '/payloads.json'), true);
if (!isset($mimes[$key])) {
    http_response_code(404);
    return;
}
$file = "{$work}/{$key}.bin";
switch ($mode) {
    case 'file':
        (new Z77\Core\Http\Response\FileResponse($file, "{$key}.bin", $mimes[$key], Z77\Core\Http\Response\FileResponse::INLINE, 'etag-' . $key))->send();
        return;
    case 'bytes':
        (new Z77\Core\Http\Response\BytesResponse((string) file_get_contents($file), "{$key}.bin", $mimes[$key]))->send();
        return;
    case 'raw': // the control: what delivery looked like before the policy
        header('Content-Type: ' . $mimes[$key]);
        readfile($file);
        return;
    case 'raw-sandbox': // PDF under `sandbox`: the case the table excepts
        header('Content-Type: ' . $mimes[$key]);
        header('Content-Security-Policy: sandbox');
        readfile($file);
        return;
}
http_response_code(404);
PHP;
file_put_contents($work . '/router.php', $router);

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port  = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env    = array_merge(getenv(), ['Z77_ROOT' => $root, 'Z77_WORK' => $work]);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $work . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $work . '/server.log', 'w'], 2 => ['file', $work . '/server.log', 'a']], $pipes, $work, $env);
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(100_000);
    $s = @fsockopen('127.0.0.1', $port);
    if ($s) { fclose($s); $up = true; }
}
check('built-in server started', $up);

// ── the browser ─────────────────────────────────────────────────────────────────────────────

/** Runs one headless navigation to its end. @return string the browser's stdout */
function browse(string $browser, string $work, array $args, string $url): string
{
    $out  = $work . '/browser.out';
    $proc = proc_open(array_merge([$browser, '--headless=new', '--no-first-run', '--no-default-browser-check',
        '--user-data-dir=' . $work . '/profile', '--window-size=800,1000'], $args, [$url]),
        [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $work . '/browser.err', 'w']], $pipes);
    $deadline = time() + 60;
    while (proc_get_status($proc)['running'] && time() < $deadline) {
        usleep(100_000);
    }
    $status = proc_get_status($proc);
    if ($status['running']) {
        DIRECTORY_SEPARATOR === '\\' ? exec('taskkill /T /F /PID ' . (int) $status['pid'] . ' 2>NUL') : proc_terminate($proc);
        echo "  (browser killed after 60 s: {$url})\n";
    }
    proc_close($proc);
    return (string) @file_get_contents($out);
}

/** The markers written while one URL was open; a late request still lands inside the budget. */
$visit = function (string $path, array $args = ['--disable-gpu', '--virtual-time-budget=5000', '--dump-dom']) use ($browser, $work, $port): array {
    @unlink($work . '/markers.log');
    $dom     = browse($browser, $work, $args, "http://127.0.0.1:{$port}{$path}");
    $markers = array_values(array_filter(explode("\n", (string) @file_get_contents($work . '/markers.log'))));
    return [$markers, $dom];
};

/** How many pixels of a screenshot are plainly red. */
$redPixels = static function (string $file): int {
    $img = @imagecreatefrompng($file);
    if ($img === false) {
        return -1;
    }
    $red = 0;
    for ($x = 0, $w = imagesx($img); $x < $w; $x += 2) {
        for ($y = 0, $h = imagesy($img); $y < $h; $y += 2) {
            $c = imagecolorat($img, $x, $y);
            if ((($c >> 16) & 255) > 200 && (($c >> 8) & 255) < 60 && ($c & 255) < 60) {
                $red++;
            }
        }
    }
    return $red;
};
// Without a GL the PDF viewer stays grey; with one the screenshot may still come first, so the
// first of a few tries that shows red counts (Edge 156: about 7 of 8 show it).
$shot = function (string $path, string $name) use ($browser, $work, $port, $redPixels): int {
    $file = "{$work}/{$name}.png";
    $red  = -1;
    for ($try = 0; $try < 8 && $red <= 1000; $try++) {
        @unlink($file);
        browse($browser, $work, ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--screenshot=' . $file], "http://127.0.0.1:{$port}{$path}");
        $red = $redPixels($file);
    }
    return $red;
};

if ($up) {
    echo "(control) without the policy the scripts run: the harness can see it\n";
    foreach (['html', 'xhtml', 'xml', 'svg'] as $key) {
        [$markers, $dom] = $visit("/raw/{$key}");
        check("{$key} delivered raw: its script writes a marker", in_array($key, $markers, true) || in_array("{$key}-fetch", $markers, true), json_encode($markers));
        echo '       raw markers: ' . json_encode($markers) . "\n";
    }
    [$markers, $dom] = $visit('/raw/html');
    check('html delivered raw: the title was changed by its script', str_contains($dom, 'SCRIPT-RAN'), substr($dom, 0, 120));

    echo "(A) through the policy nothing runs\n";
    foreach (['file', 'bytes'] as $mode) {
        foreach (['html', 'htmlcs', 'xhtml', 'xml', 'svg', 'plain'] as $key) {
            [$markers, $dom] = $visit("/{$mode}/{$key}");
            check("{$mode} {$key}: no marker", $markers === [], json_encode($markers));
            check("{$mode} {$key}: the title is not the script's", !str_contains($dom, '<title>SCRIPT-RAN'), substr($dom, 0, 200));
        }
    }
    @unlink($work . '/markers.log');

    echo "(B) what the user sees\n";
    [, $dom] = $visit('/file/html');
    check('«Öffnen» on HTML: the source as text', str_contains($dom, '&lt;script&gt;') && str_contains($dom, '<pre'), substr($dom, 0, 300));
    $svgRed = $shot('/file/svg', 'svg-policy');
    check('an SVG through the policy still draws', $svgRed > 1000, "red pixels: {$svgRed}");
    $pdfRed = $shot('/file/pdf', 'pdf-file');
    check('a PDF inline through FileResponse renders', $pdfRed > 1000, "red pixels: {$pdfRed}");
    $pdfBytes = $shot('/bytes/pdf', 'pdf-bytes');
    check('a PDF inline through BytesResponse renders', $pdfBytes > 1000, "red pixels: {$pdfBytes}");
    $pdfRaw = $shot('/raw/pdf', 'pdf-raw');
    echo "  info PDF red pixels: raw {$pdfRaw}, FileResponse {$pdfRed}, BytesResponse {$pdfBytes}\n";
    $pdfSandbox = $shot('/raw-sandbox/pdf', 'pdf-sandbox');
    echo "  info PDF under `Content-Security-Policy: sandbox`: red pixels {$pdfSandbox} ("
        . ($pdfSandbox > 1000 ? 'renders in this browser' : 'not seen in 8 tries') . ")\n";

    echo "(C) what the application consumes\n";
    [$markers] = $visit('/consumers');
    echo '       markers: ' . json_encode($markers) . "\n";
    check('<img> with a raster image', in_array('img-png', $markers, true));
    check('<img> with an SVG', in_array('img-svg', $markers, true));
    check('fetch() of JSON', in_array('fetch-json', $markers, true));
    check('fetch() of text', in_array('fetch-text', $markers, true));
    check('<audio> plays through ranges', in_array('audio-wav', $markers, true));
}

proc_terminate($server);
proc_close($server);
exec(DIRECTORY_SEPARATOR === '\\' ? 'rmdir /S /Q "' . str_replace('/', '\\', $work) . '" 2>NUL' : 'rm -rf ' . escapeshellarg($work));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
