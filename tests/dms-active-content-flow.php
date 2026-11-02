<?php

/**
 * DMS active content, the real flow (CLI, temp installation, PHP's built-in server) — DMS-ACTIVE-001.
 *
 * `tests/dms-active-content.php` proves the parts; this one proves they are wired: the kernel, the
 * routing, `OutputController`, the backend's Drive mount. The built-in server plays a web server
 * that serves an existing file itself, like Apache, but it does not read `.htaccess`. What is
 * load-bearing:
 *
 *   (A) `/media`, public, an HTML, SVG and XML document and a JPEG polyglot named `.html`: answered
 *       safely by PHP, every hit, and no copy is written;
 *   (B) `/media`, public, a JPEG and a PDF: the copy is written, the next hit is static;
 *   (C) one request to a non-existent `/media/...` path repairs an old installation: guards replaced,
 *       `.html`/`.svg`/`.xml` copies gone, `.jpg`/`.pdf` and hand-placed files kept, a hand-placed
 *       tree beside the partition untouched and served, and the swept path falls through to PHP;
 *   (D) the backend's preview (the «Öffnen» link) and download, signed in; no session, no bytes;
 *   (E) opening the Drive repairs an installation too.
 *
 * Run: php tests/dms-active-content-flow.php
 */

$root = str_replace('\\', '/', dirname(__DIR__));
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "dms-active-content-flow: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require __DIR__ . '/support/real-flow-site.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-active-flow-' . getmypid();
$site = $base . '/site';
mkdir($base . '/sessions', 0777, true);
$hash = password_hash('Passwort-Sehr-Lang-1', PASSWORD_BCRYPT, ['cost' => 4]);
realFlowSite($site, $root, [['id' => 1, 'username' => 'max', 'name' => 'Max Muster', 'email' => 'max.muster@example.ch',
    'password_hash' => $hash, 'roles' => ['superUser'], 'sort_key' => 0]]);

// The DMS on top of the minimal installation: its module, its namespace, its data.
$file = $site . '/config/moduleManager.inc.php';
file_put_contents($file, str_replace("'backend' => \n", "'dms' => [], 'backend' => \n", (string) file_get_contents($file)));
$file = $site . '/config/fileFinder.inc.php';
$finder = (string) file_get_contents($file);
file_put_contents($file, str_replace("        'Z77\\\\Module\\\\Backend\\\\'", "        'Z77\\\\Module\\\\Dms\\\\'      => \$entry('module-dms'),\n        'Z77\\\\Module\\\\Backend\\\\'", $finder));

$assets = $root . '/packages/module-dms/res/assets';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assets, FilesystemIterator::SKIP_DOTS)) as $asset) {
    $to = $site . '/public/assets/dms' . str_replace('\\', '/', substr($asset->getPathname(), strlen($assets)));
    if ($asset->isFile()) {
        @mkdir(dirname($to), 0777, true);
        copy($asset->getPathname(), $to);
    }
}

$jpeg = "\xFF\xD8\xFF\xE0 jpeg bytes \0\xff";
$docs = [
    // slug, ext, mime, kind, bytes
    ['seite', 'html', 'text/html', 'html', '<!DOCTYPE html><script>document.title="ran"</script>'],
    ['zeichnung', 'svg', 'image/svg+xml', 'image', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    ['daten', 'xml', 'text/xml', 'text', '<?xml version="1.0"?><a/>'],
    ['notiz', 'txt', 'text/plain', 'text', 'just text'],
    ['tarnung', 'html', 'image/jpeg', 'image', $jpeg . '<script>alert(1)</script>'],
    ['foto', 'jpg', 'image/jpeg', 'image', $jpeg],
    ['schrift', 'pdf', 'application/pdf', 'pdf', "%PDF-1.4\n"],
];
$rows = [];
foreach ($docs as $i => [$slug, $ext, $mime, $kind, $bytes]) {
    $id = $i + 1;
    $rows[] = ['id' => $id, 'folder_id' => 2, 'display_name' => $slug, 'original_name' => "{$slug}.{$ext}", 'ext' => $ext, 'mime_type' => $mime,
        'kind' => $kind, 'size_bytes' => strlen($bytes), 'checksum' => str_repeat(dechex($id), 64), 'active' => true, 'slug' => $slug, 'sort_key' => $id];
    mkdir("{$site}/data/blobs/0/{$id}", 0777, true);
    file_put_contents("{$site}/data/blobs/0/{$id}/orig.{$ext}", $bytes);
}
mkdir($site . '/data/documents', 0777, true);
file_put_contents($site . '/data/documents/documents.json', json_encode($rows, JSON_PRETTY_PRINT));
file_put_contents($site . '/data/documents/folders.json', json_encode([
    ['id' => 1, 'name' => 'Drive', 'key' => 'drive', 'system' => true, 'active' => true, 'slug' => 'drive', 'delivery_mode' => null, 'sort_key' => 0, 'parent_id' => null],
    ['id' => 2, 'name' => 'Offen', 'active' => true, 'slug' => 'offen', 'delivery_mode' => 'public', 'sort_key' => 0, 'parent_id' => 1],
], JSON_PRETTY_PRINT));

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port  = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $base . '/sessions', '-d', 'display_errors=0', '-S', "127.0.0.1:{$port}", '-t', $site . '/public', $site . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $base . '/server.log', 'w'], 2 => ['file', $base . '/server.log', 'a']], $serverPipes, $site);
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(100_000);
    $s = @fsockopen('127.0.0.1', $port);
    if ($s) { fclose($s); $up = true; }
}
check('built-in server started', $up);

$cookies = [];
/** @return array{0: int, 1: array<string, string>, 2: string} */
$go = function (string $path, ?array $post = null) use ($port, &$cookies): array {
    $head = [];
    if ($cookies !== []) {
        $head[] = 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($cookies), $cookies));
    }
    $opts = ['ignore_errors' => true, 'timeout' => 60, 'follow_location' => 0, 'max_redirects' => 0, 'method' => $post === null ? 'GET' : 'POST'];
    if ($post !== null) {
        $head[] = 'Content-Type: application/x-www-form-urlencoded';
        $opts['content'] = http_build_query($post);
    }
    $opts['header'] = implode("\r\n", $head);
    $body   = (string) @file_get_contents("http://127.0.0.1:{$port}{$path}", false, stream_context_create(['http' => $opts]));
    $status = 0;
    $out    = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int) $m[1]; continue; }
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $name = strtolower(trim($name));
        $out[$name] = trim($value);
        if ($name === 'set-cookie' && preg_match('/^([^=]+)=([^;]*)/', trim($value), $c)) {
            $cookies[$c[1]] = $c[2];
        }
    }
    return [$status, $out, $body];
};

$media  = $site . '/public/media';
$sandbox = 'sandbox';
$typeOf = static fn (array $h): string => strtolower(trim(explode(';', $h['content-type'] ?? '', 2)[0]));

if ($up) {
    echo "(A) /media, public, active content: PHP answers, no copy\n";
    foreach ([['seite.html', 'text/plain', 'inline', $sandbox], ['daten.xml', 'text/plain', 'inline', $sandbox],
              ['notiz.txt', 'text/plain', 'inline', $sandbox], ['tarnung.html', 'image/jpeg', 'inline', $sandbox]] as [$name, $type, $disposition, $csp]) {
        foreach ([1, 2] as $hit) {
            [$st, $h, $b] = $go("/media/offen/{$name}");
            check("{$name}, hit {$hit}: 200 {$type}", $st === 200 && $typeOf($h) === $type, $st . ' ' . ($h['content-type'] ?? '(none)') . ' ' . substr($b, 0, 200));
            check("{$name}, hit {$hit}: {$disposition}, nosniff, sandbox", str_starts_with($h['content-disposition'] ?? '', $disposition . ';')
                && ($h['x-content-type-options'] ?? '') === 'nosniff' && ($h['content-security-policy'] ?? '') === $csp, json_encode($h));
        }
        check("{$name}: no static copy", !is_file("{$media}/offen/{$name}"));
    }
    [$st, $h, $b] = $go('/media/offen/zeichnung.svg');
    check('zeichnung.svg: stays image/svg+xml without script', $st === 200 && $typeOf($h) === 'image/svg+xml' && str_starts_with($h['content-security-policy'] ?? '', 'sandbox; default-src'), json_encode($h));
    check('zeichnung.svg: the bytes are the file', str_contains($b, '<script>alert(1)</script>'));
    check('zeichnung.svg: no static copy', !is_file("{$media}/offen/zeichnung.svg"));
    check('public cache headers stay', ($h['cache-control'] ?? '') === 'public, max-age=31536000, immutable', $h['cache-control'] ?? '');

    echo "(B) /media, public, a safe type: copied, the next hit is static\n";
    foreach ([['foto.jpg', 'image/jpeg', null], ['schrift.pdf', 'application/pdf', null]] as [$name, $type]) {
        [$st, $h] = $go("/media/offen/{$name}");
        check("{$name}: first hit through PHP", $st === 200 && $typeOf($h) === $type && isset($h['content-disposition']), json_encode($h));
        check("{$name}: copy written", is_file("{$media}/offen/{$name}"));
        [$st, $h] = $go("/media/offen/{$name}");
        check("{$name}: next hit static (no PHP headers)", $st === 200 && !isset($h['content-disposition']) && !isset($h['x-content-type-options']), json_encode($h));
    }

    check('the base guard came with the first copy, handler level only', str_contains((string) @file_get_contents($media . '/.htaccess'), 'SetHandler none') && !str_contains((string) @file_get_contents($media . '/.htaccess'), 'DMS-ACTIVE-001'));
    check('the partition guard too', str_contains((string) @file_get_contents($media . '/offen/.htaccess'), 'DMS-ACTIVE-001'));

    echo "(C) one request to a non-existent path repairs an old installation\n";
    $old = "# z77 DMS (DMS-MEDIA-PHP-001): static copies only — nothing here is ever run.\n<FilesMatch \"\\.(php)\$\">\n    SetHandler none\n</FilesMatch>\n";
    file_put_contents($media . '/.htaccess', $old);
    unlink($media . '/offen/.htaccess'); // an old installation has no partition guard
    @mkdir($media . '/demo/iso', 0777, true);
    foreach (['a.js', 'a.html', 'a.css', 'a.svg'] as $hand) {
        file_put_contents($media . '/demo/iso/' . $hand, 'hand-placed ' . $hand);
    }
    file_put_contents($media . '/offen/hand.js', 'hand-placed in the partition');
    file_put_contents($media . '/offen/seite.html', 'old copy, active');
    file_put_contents($media . '/offen/zeichnung.svg', 'old copy, active');
    file_put_contents($media . '/offen/daten.xml', 'old copy, active');
    [$st, , $b] = $go('/media/offen/seite.html');
    check('before the repair the web server hands out the old copy', $st === 200 && $b === 'old copy, active', $st . ' ' . substr($b, 0, 80));
    [$st] = $go('/media/gibt/es/nicht.jpg');
    check('a non-existent path: 404', $st === 404, (string) $st);
    $guard = (string) @file_get_contents($media . '/.htaccess');
    check('... the base guard is replaced, handler level only', $guard !== $old && str_contains($guard, 'SetHandler none') && !str_contains($guard, 'DMS-ACTIVE-001'));
    check('... the partition has its guard', str_contains((string) @file_get_contents($media . '/offen/.htaccess'), 'DMS-ACTIVE-001'));
    check('... .html, .svg and .xml copies are gone', !is_file($media . '/offen/seite.html') && !is_file($media . '/offen/zeichnung.svg') && !is_file($media . '/offen/daten.xml'));
    check('... .jpg and .pdf stay', is_file($media . '/offen/foto.jpg') && is_file($media . '/offen/schrift.pdf'));
    check('... a hand-placed file in the partition stays', is_file($media . '/offen/hand.js'));
    check('... the hand-placed tree beside it is untouched, without a guard', file_get_contents($media . '/demo/iso/a.js') === 'hand-placed a.js' && file_get_contents($media . '/demo/iso/a.html') === 'hand-placed a.html'
        && file_get_contents($media . '/demo/iso/a.css') === 'hand-placed a.css' && file_get_contents($media . '/demo/iso/a.svg') === 'hand-placed a.svg' && !is_file($media . '/demo/.htaccess') && !is_file($media . '/demo/iso/.htaccess'));
    [$st, , $b] = $go('/media/demo/iso/a.js');
    check('... and still served by the web server', $st === 200 && $b === 'hand-placed a.js', $st . ' ' . substr($b, 0, 80));
    [$st, $h] = $go('/media/offen/seite.html');
    check('the swept path falls through to PHP and is safe', $st === 200 && $typeOf($h) === 'text/plain' && ($h['content-security-policy'] ?? '') === $sandbox, json_encode($h));

    echo "(D) the backend's preview and download\n";
    [$st, $h, $b] = $go('/backend/documents/document/preview?id=1');
    check('no session: no bytes', !str_contains($b, '<script>document.title') && !isset($h['x-content-type-options']), $st . ' ' . json_encode($h));
    [, , $html] = $go('/login');
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) || preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m);
    [$st] = $go('/backend/system/login/login', ['csrf_token' => $m[1] ?? '', 'username' => 'max', 'password' => 'Passwort-Sehr-Lang-1']);
    check('signed in', $st === 302 || $st === 303, (string) $st);
    [$st, $h, $b] = $go('/backend/documents/document/preview?id=1');
    check('«Öffnen» on HTML: its source, as text, sandboxed', $st === 200 && $typeOf($h) === 'text/plain' && str_starts_with($h['content-disposition'] ?? '', 'inline;')
        && ($h['content-security-policy'] ?? '') === $sandbox && $b === $docs[0][4], $st . ' ' . json_encode($h) . ' ' . substr($b, 0, 200));
    [$st, $h, $b] = $go('/backend/documents/document/download?id=1');
    check('Download on HTML: attachment, its own type, identical bytes', $st === 200 && $typeOf($h) === 'text/html' && str_starts_with($h['content-disposition'] ?? '', 'attachment;') && $b === $docs[0][4], $st . ' ' . json_encode($h));
    [$st, $h] = $go('/backend/documents/document/preview?id=2');
    check('«Öffnen» on SVG: image/svg+xml without script', $st === 200 && $typeOf($h) === 'image/svg+xml' && str_starts_with($h['content-security-policy'] ?? '', 'sandbox; default-src'), $st . ' ' . json_encode($h));
    [$st, $h] = $go('/backend/documents/document/preview?id=7');
    check('«Öffnen» on PDF: inline, no CSP', $st === 200 && $typeOf($h) === 'application/pdf' && str_starts_with($h['content-disposition'] ?? '', 'inline;') && !isset($h['content-security-policy']), $st . ' ' . json_encode($h));

    echo "(E) opening the Drive repairs\n";
    file_put_contents($media . '/.htaccess', $old);
    unlink($media . '/offen/.htaccess');
    file_put_contents($media . '/offen/seite.html', 'old copy, active');
    [$st, , $b] = $go('/backend/documents/drive/list?folder=2');
    check('the Drive opens and lists the documents', $st === 200 && str_contains($b, 'dms-filelist'), $st . ' ' . substr(strip_tags($b), 0, 300));
    check('... both guards are back, the copy gone, the hand-placed tree there', (string) @file_get_contents($media . '/.htaccess') !== $old && str_contains((string) @file_get_contents($media . '/offen/.htaccess'), 'DMS-ACTIVE-001')
        && !is_file($media . '/offen/seite.html') && is_file($media . '/demo/iso/a.js'));
}

proc_terminate($server);
proc_close($server);
exec(DIRECTORY_SEPARATOR === '\\' ? 'rmdir /S /Q "' . str_replace('/', '\\', $base) . '" 2>NUL' : 'rm -rf ' . escapeshellarg($base));

echo "\n{$pass} ok, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
