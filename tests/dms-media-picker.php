<?php

/**
 * Media picker + media field harness (CLI, temp installation, PHP's built-in server) — bauplan
 * `docs/03-development/media-picker-bauplan.md` P1. What is load-bearing:
 *
 *   (A) `Ui\MediaField` alone: the target is a plain id or nothing, the root a folder id or a
 *       partition key, `accept` only what the picker knows, the address leaves out empty state;
 *   (B) the picker window through the real Bootstrap, Dispatcher, login and CSRF, as an admin
 *       with grants (no super-user bypass): the window attributes, the tree below the root
 *       without the folder the user may not read, the shown folder falls back to the root for an
 *       unreadable one, `accept=image` drops a PDF, `public=1` disables a protected, an inactive
 *       and a document with its own protected mode — with the reason, `public=0` does not, a
 *       root that is not there is refused (find-only: nothing is created);
 *   (C) the choice: the answer closes the window and fills `#<target>` — `set-value` with the id,
 *       `replace-html` of the thumbnail (the preview URL, variant s), `focus` — in the ORIGIN it
 *       was opened from; refused for a protected, an inactive, a PDF, an unreadable, an
 *       out-of-root and an unknown document;
 *   (D) the upload in the picker: through `UploadService::save()` into the shown folder, with
 *       the session's user as `createdBy`, the grid of that folder in the answer with the new
 *       image marked; refused into a non-public folder (`public=1`), for a PDF (`accept=image`),
 *       into an unreadable folder, a folder outside the root, without `write` — nothing stored;
 *   (E) the field partial (`partials/mediaField`): the hidden input, the address of «Auswählen»,
 *       the thumbnail of a chosen image, a missing one, the empty frame and its template;
 *   (F) `ModuleDrive::ensurePublic()`: makes the partition public, a second call writes nothing,
 *       a sealed partition is refused and stays sealed.
 *
 * Run: php tests/dms-media-picker.php
 */

$repo = str_replace('\\', '/', dirname(__DIR__));
if (!is_file($repo . '/vendor/autoload.php')) {
    fwrite(STDERR, "dms-media-picker: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "dms-media-picker: GD is needed (an uploaded image gets its `s` thumbnail).\n");
    exit(2);
}
require $repo . '/vendor/autoload.php';
require_once $repo . '/packages/kernel/core/src/autoload/prod/php/Helper.php';
require __DIR__ . '/support/real-flow-site.php';
require_once __DIR__ . '/support/TestPng.php';

use Z77\Core\DI;
use Z77\Core\Services\TemplateRenderer;
use Z77\Module\Dms\Services\DocumentService;
use Z77\Module\Dms\Ui\MediaField;
use Z77\Shared\Auth\AuthUser;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removeTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(A) MediaField alone\n";
check('target: a plain id', MediaField::target('fld-1_a') === 'fld-1_a');
check('target: a selector or a leading digit is dropped', MediaField::target('#x') === '' && MediaField::target('a b') === '' && MediaField::target('1a') === '' && MediaField::target(['x']) === '');
check('root: a folder id or a partition key', MediaField::root('12') === '12' && MediaField::root('web') === 'web' && MediaField::root(' projekte ') === 'projekte');
check('root: anything else is the whole tree', MediaField::root('../x') === '' && MediaField::root('Web') === '' && MediaField::root(null) === '');
check('accept: image and nothing are known, the rest is refused', MediaField::accept('image') === 'image' && MediaField::accept('') === '' && MediaField::accept(null) === '' && MediaField::accept('video') === null);
check('accepts: image only takes images, nothing takes all', MediaField::accepts('image', 'image') && !MediaField::accepts('image', 'pdf') && MediaField::accepts('', 'pdf'));
check('url: empty state left out, public as 1', MediaField::url('/b/drive/pick', ['target' => 'f', 'root' => '', 'accept' => 'image', 'public' => true]) === '/b/drive/pick?target=f&accept=image&public=1');
check('newId: a valid target', MediaField::target(MediaField::newId()) !== '');

// ═══════════════════════════════════════════════════════════════════════════════════
$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-media-picker-' . getmypid() . '-' . bin2hex(random_bytes(3));
$site = $base . '/site';
mkdir($base, 0777, true);
$server = null;
register_shutdown_function(static function () use ($base, &$server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        for ($i = 0; $i < 20 && proc_get_status($server)['running']; $i++) {
            usleep(100_000);
        }
    }
    removeTree($base);
});

$hash = password_hash('Passwort-Sehr-Lang-1', PASSWORD_BCRYPT, ['cost' => 4]);
realFlowSite($site, $repo, [
    ['id' => 9, 'username' => 'chef', 'name' => 'Max Muster', 'email' => 'max.muster@example.ch', 'password_hash' => $hash, 'roles' => ['superUser'], 'sort_key' => 0],
    ['id' => 7, 'username' => 'erna', 'name' => 'Erna Muster', 'email' => 'erna.muster@example.ch', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 1],
    ['id' => 8, 'username' => 'leo', 'name' => 'Leo Muster', 'email' => 'leo.muster@example.ch', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 2],
]);
// The DMS on the installation.
$finder = (string) file_get_contents($site . '/config/fileFinder.inc.php');
file_put_contents($site . '/config/fileFinder.inc.php', str_replace(
    "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),",
    "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),\n        'Z77\\\\Module\\\\Dms\\\\' => \$entry('module-dms'),",
    $finder
));
$modules = (string) file_get_contents($site . '/config/moduleManager.inc.php');
file_put_contents($site . '/config/moduleManager.inc.php', (string) preg_replace("/('backend' =>\s*array \(\s*\),)/", "$1\n    'dms' => array (),", $modules));

// The store. Webseite (key `web`) is public; Projekte inherits it; Intern is protected below it;
// Geheim is a partition erna may not read; Andere is a partition erna may write but outside `web`.
$png  = TestPng::bytes(60, 40);
$pdf  = "%PDF-1.4\n" . str_repeat('x', 91);
$rows = [];
$seed = static function (int $id, int $folder, string $slug, string $ext, string $mime, string $kind, string $bytes, array $extra = []) use ($site, &$rows, $png): void {
    mkdir("{$site}/data/blobs/0/{$id}", 0777, true);
    file_put_contents("{$site}/data/blobs/0/{$id}/orig.{$ext}", $bytes);
    $variants = [];
    if ($kind === 'image') {
        file_put_contents("{$site}/data/blobs/0/{$id}/s.png", $png);
        $variants = ['s' => ['w' => 60, 'h' => 40, 'bytes' => strlen($png), 'ext' => 'png']];
    }
    $rows[] = $extra + ['id' => $id, 'folder_id' => $folder, 'display_name' => "{$slug}.{$ext}", 'original_name' => "{$slug}.{$ext}", 'ext' => $ext, 'mime_type' => $mime,
        'kind' => $kind, 'size_bytes' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'active' => true, 'slug' => $slug, 'sort_key' => $id, 'variants' => $variants,
        'width' => $kind === 'image' ? 60 : null, 'height' => $kind === 'image' ? 40 : null];
};
$seed(10, 3, 'bild', 'png', 'image/png', 'image', $png);
$seed(11, 3, 'bericht', 'pdf', 'application/pdf', 'pdf', $pdf);
$seed(12, 4, 'intern', 'png', 'image/png', 'image', $png);
$seed(13, 3, 'aus', 'png', 'image/png', 'image', $png, ['active' => false]);
$seed(14, 5, 'geheim', 'png', 'image/png', 'image', $png);
$seed(15, 3, 'privat', 'png', 'image/png', 'image', $png, ['delivery_mode' => 'protected']);
$seed(16, 6, 'anders', 'png', 'image/png', 'image', $png);
mkdir($site . '/data/documents', 0777, true);
file_put_contents($site . '/data/documents/documents.json', json_encode($rows, JSON_PRETTY_PRINT));
$folder = static fn (int $id, ?int $parent, string $name, ?string $mode = null, array $extra = []): array => $extra + ['id' => $id, 'name' => $name, 'active' => true, 'slug' => strtolower($name), 'delivery_mode' => $mode, 'sort_key' => $id, 'parent_id' => $parent];
file_put_contents($site . '/data/documents/folders.json', json_encode([
    $folder(1, null, 'Drive', null, ['key' => 'drive', 'system' => true]),
    $folder(2, 1, 'Webseite', 'public', ['key' => 'web', 'system' => true]),
    $folder(3, 2, 'Projekte'),
    $folder(4, 2, 'Intern', 'protected'),
    $folder(5, 1, 'Geheim', null, ['key' => 'geheim', 'system' => true]),
    $folder(6, 1, 'Andere', 'public'),
], JSON_PRETTY_PRINT));
$ace = static fn (int $id, int $folderId, string $user, string $rights): array => ['id' => $id, 'resource_type' => 'folder', 'resource_id' => $folderId, 'subject_type' => 'user', 'subject_id' => $user, 'rights' => $rights];
file_put_contents($site . '/data/documents/access_control.json', json_encode([
    $ace(1, 2, '7', 'write'),
    $ace(2, 6, '7', 'write'),
    $ace(3, 2, '8', 'read'),
], JSON_PRETTY_PRINT));
$storeDocs    = static fn (): array => json_decode((string) file_get_contents($site . '/data/documents/documents.json'), true) ?: [];
$storeFolders = static fn (): array => json_decode((string) file_get_contents($site . '/data/documents/folders.json'), true) ?: [];

// ── the server ─────────────────────────────────────────────────────────────────────
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port  = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
mkdir($base . '/sessions');
$server = proc_open(
    [PHP_BINARY, '-d', 'apc.enabled=0', '-d', 'opcache.enable=0', '-d', 'session.save_path=' . $base . '/sessions', '-d', 'display_errors=0', '-d', 'log_errors=1',
     '-S', "127.0.0.1:{$port}", '-t', $site . '/public', $site . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $base . '/server.log', 'w'], 2 => ['file', $base . '/server.log', 'a']],
    $serverPipes,
    $site
);
$deadline = microtime(true) + 10;
do {
    usleep(100_000);
    $sock = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
} while ($sock === false && microtime(true) < $deadline);
if ($sock !== false) {
    fclose($sock);
}
check('built-in server started', $sock !== false);

/** One browser: a cookie jar, redirects not followed. */
$browser = static function () use ($port): Closure {
    $cookies = [];
    return static function (string $path, array|string|null $post = null, array $headers = []) use (&$cookies, $port): array {
        $head = $headers;
        if ($cookies !== []) {
            $head[] = 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($cookies), $cookies));
        }
        $opts = ['ignore_errors' => true, 'timeout' => 60, 'follow_location' => 0, 'max_redirects' => 0, 'method' => $post === null ? 'GET' : 'POST'];
        if (is_string($post)) {
            $opts['content'] = $post;
        } elseif ($post !== null) {
            $head[] = 'Content-Type: application/x-www-form-urlencoded';
            $opts['content'] = http_build_query($post);
        }
        $opts['header'] = implode("\r\n", $head);
        $body   = (string) @file_get_contents("http://127.0.0.1:{$port}{$path}", false, stream_context_create(['http' => $opts]));
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int) $m[1]; continue; }
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            if (strtolower(trim($name)) === 'set-cookie' && preg_match('/^([^=]+)=([^;]*)/', trim($value), $c)) {
                $cookies[$c[1]] = $c[2];
            }
        }
        return [$status, $body];
    };
};
$login = static function (string $user) use ($browser): array {
    $b = $browser();
    [, $html] = $b('/login');
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
    $b('/backend/system/login/login', ['csrf_token' => $m[1] ?? '', 'username' => $user, 'password' => 'Passwort-Sehr-Lang-1']);
    [$s, $page] = $b('/backend/system/dashboard/overview');
    preg_match('/name="csrf-token" content="([^"]+)"/', $page, $t);
    return [$b, $t[1] ?? '', $s];
};
[$erna, $token, $st] = $login('erna');
check('a real login (erna, admin with grants)', $st === 200 && $token !== '', "{$st}");
[$chef, $chefToken] = $login('chef');
[$leo, $leoToken]   = $login('leo');

$fetchHeaders = static fn (string $t): array => ['Sec-Fetch-Mode: cors', 'X-Requested-With: XMLHttpRequest', 'X-CSRF-Token: ' . $t];
$pick = static fn (Closure $who, string $t, array $q): array => $who('/backend/documents/drive/pick?' . http_build_query($q), null, $fetchHeaders($t));
$choose = static fn (Closure $who, string $t, array $fields): array => $who('/backend/documents/drive/pick', $fields, $fetchHeaders($t));
$multipart = static function (Closure $who, string $t, array $fields, string $filename, string $bytes) use ($fetchHeaders): array {
    $boundary = 'z77' . bin2hex(random_bytes(8));
    $body     = '';
    foreach ($fields as $name => $value) {
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
    }
    $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"files[]\"; filename=\"{$filename}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$bytes}\r\n";
    $body .= "--{$boundary}--\r\n";
    return $who('/backend/documents/drive/pick-upload', $body, array_merge($fetchHeaders($t), ["Content-Type: multipart/form-data; boundary={$boundary}"]));
};
$json = static fn (array $answer): array => (array) json_decode($answer[1], true);
/** The grid item of document $id, or ''. */
$item = static function (string $html, int $id): string {
    return preg_match('#<button type="submit" form="[^"]+" name="doc" value="' . $id . '"[^>]*>.*?</button>#s', $html, $m) === 1 ? $m[0] : '';
};
$web = ['target' => 'fld1', 'root' => 'web', 'accept' => 'image', 'public' => '1'];

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(B) the picker window\n";
[$s, $html] = $pick($erna, $token, $web);
check('fetched: 200, the window root with mask, entity and title', $s === 200
    && str_contains($html, 'data-window="dms-pick"') && str_contains($html, 'data-window-entity="media-field:fld1"') && str_contains($html, 'data-window-title="Bild auswählen"'), "{$s} " . substr($html, 0, 300));
check('… only main (the fetch skeleton), no shell', !str_contains($html, '<html') && !str_contains($html, 'be-shell'));
check('… the tree below the root: Webseite, Projekte, Intern', str_contains($html, '>Webseite</a>') && str_contains($html, '>Projekte</a>') && str_contains($html, '>Intern</a>'));
check('… not Geheim, not Andere (outside the root)', !str_contains($html, '>Geheim</a>') && !str_contains($html, '>Andere</a>'));
check('… the shown folder is the root, with its delivery', str_contains($html, 'dms-pick__title">Webseite<') && str_contains($html, '>öffentlich<'));
check('… the upload component into it, bound by the envelope', str_contains($html, 'data-upload="drop"') && str_contains($html, 'action="/backend/documents/drive/pick-upload"')
    && str_contains($html, 'name="folder_id" value="2"') && str_contains($html, '"bind-upload"') && str_contains($html, 'data-upload-accept="image/*"'));
check('… the folder links load into the window, the state kept', str_contains($html, 'data-window-link') && str_contains($html, 'href="/backend/documents/drive/pick?target=fld1&amp;root=web&amp;accept=image&amp;public=1&amp;folder=3"'));

[, $html] = $pick($erna, $token, $web + ['folder' => 3]);
check('folder Projekte: the public image is a choice', ($b = $item($html, 10)) !== '' && !str_contains($b, 'disabled') && str_contains($b, '/backend/documents/document/preview?id=10&amp;variant=s'), $b);
check('… accept=image: the PDF is not listed', $item($html, 11) === '' && !str_contains($html, 'bericht.pdf'));
check('… an inactive image: disabled, «inaktiv»', str_contains($b = $item($html, 13), 'disabled') && str_contains($b, 'Nicht öffentlich (inaktiv)'), $b);
check('… an image with its own protected mode: disabled, «geschützt»', str_contains($b = $item($html, 15), 'disabled') && str_contains($b, 'Nicht öffentlich (geschützt)'), $b);
check('… the choose form carries the state and the CSRF field', str_contains($html, 'id="dms-pick-fld1-choose"') && str_contains($html, 'name="csrf_token" value="') && str_contains($html, 'name="target" value="fld1"'));

[, $html] = $pick($erna, $token, $web + ['folder' => 4]);
check('folder Intern (protected): its image disabled with the reason', str_contains($b = $item($html, 12), 'disabled') && str_contains($b, 'Nicht öffentlich (geschützt)'), $b);
check('… no upload there, the reason instead', !str_contains($html, 'data-upload=') && str_contains($html, 'liefert nicht öffentlich aus'));
[, $html] = $pick($erna, $token, ['public' => '0'] + $web + ['folder' => 4]);
check('public=0: the protected image is a choice, the upload is there', ($b = $item($html, 12)) !== '' && !str_contains($b, 'disabled') && str_contains($html, 'data-upload="drop"'), $b);

[, $html] = $pick($erna, $token, $web + ['folder' => 5]);
check('an unreadable folder asked: the root is shown, nothing of it', str_contains($html, 'dms-pick__title">Webseite<') && !str_contains($html, 'geheim'));
[, $html] = $pick($erna, $token, ['target' => 'fld1', 'accept' => 'image', 'public' => '1']);
check('no root: every readable partition, not Geheim', str_contains($html, '>Webseite</a>') && str_contains($html, '>Andere</a>') && !str_contains($html, '>Geheim</a>'));
[, $html] = $pick($chef, $chefToken, ['target' => 'fld1', 'accept' => 'image']);
check('… the super user sees Geheim', str_contains($html, '>Geheim</a>'));
[, $html] = $pick($erna, $token, ['root' => 'geheim'] + $web);
check('a root the user may not read: no folder, a note', !str_contains($html, '>Geheim</a>') && str_contains($html, 'Kein Ordner, den du sehen darfst'));
[, $html] = $pick($erna, $token, ['accept' => ''] + $web + ['folder' => 3]);
check('accept empty: the PDF is listed', $item($html, 11) !== '');

$before = count($storeFolders());
$answer = $pick($erna, $token, ['root' => 'nichtda'] + $web);
check('a root that is not there: refused', ($json($answer)['status'] ?? '') === 'error', $answer[1]);
check('… find-only: no partition created', count($storeFolders()) === $before);
$answer = $pick($erna, $token, ['accept' => 'video'] + $web);
check('an accept the picker does not know: refused', ($json($answer)['status'] ?? '') === 'error', $answer[1]);

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(C) the choice\n";
$env = $json($choose($erna, $token, $web + ['doc' => 10, '_origin' => 'page']));
$cmd = array_column($env['commands'] ?? [], null, 'action');
check('success with id and name', ($env['status'] ?? '') === 'success' && ($env['data']['id'] ?? 0) === 10 && ($env['data']['name'] ?? '') === 'bild.png', json_encode($env));
check('… first close-modal (this window), then the field', array_column($env['commands'] ?? [], 'action') === ['close-modal', 'set-value', 'replace-html', 'focus'], json_encode(array_column($env['commands'] ?? [], 'action')));
check('… set-value: the hidden input of #fld1, the id', ($cmd['set-value']['target'] ?? '') === '#fld1 [data-media-field-input]' && ($cmd['set-value']['value'] ?? '') === '10' && ($cmd['set-value']['origin'] ?? '') === 'page');
check('… replace-html: the thumbnail, preview variant s and the name', ($cmd['replace-html']['target'] ?? '') === '#fld1 [data-media-field-thumb]'
    && str_contains($cmd['replace-html']['html'] ?? '', '/backend/documents/document/preview?id=10&amp;variant=s') && str_contains($cmd['replace-html']['html'] ?? '', 'bild.png')
    && str_contains($cmd['replace-html']['html'] ?? '', 'data-media-field-thumb') && !str_contains($cmd['replace-html']['html'] ?? '', 'data-empty'));
check('… focus back on «Auswählen …»', ($cmd['focus']['target'] ?? '') === '#fld1 [data-media-field-pick]');
$env = $json($choose($erna, $token, $web + ['doc' => 10, '_origin' => 'window:3']));
check('opened from a window: every field command in that window', array_values(array_unique(array_filter(array_column($env['commands'] ?? [], 'origin')))) === ['window:3'], json_encode($env['commands'] ?? []));
foreach ([12 => 'protected', 13 => 'inactive', 15 => 'own protected mode', 11 => 'a PDF with accept=image', 14 => 'unreadable', 16 => 'outside the root', 999 => 'unknown'] as $id => $label) {
    $env = $json($choose($erna, $token, $web + ['doc' => $id]));
    check("refused: {$label}", ($env['status'] ?? '') === 'error' && ($env['commands'] ?? []) === [], json_encode($env));
}
$env = $json($choose($erna, $token, ['public' => '0'] + $web + ['doc' => 12]));
check('public=0: the protected image is taken', ($env['status'] ?? '') === 'success' && ($env['data']['id'] ?? 0) === 12, json_encode($env));
$env = $json($choose($erna, $token, ['target' => ''] + $web + ['doc' => 10]));
check('no target: nothing to fill, refused', ($env['status'] ?? '') === 'error');
$answer = $erna('/backend/documents/drive/pick', $web + ['doc' => 10], ['Sec-Fetch-Mode: cors', 'X-Requested-With: XMLHttpRequest']);
check('a POST without the CSRF header is refused before the action', ($json($answer)['status'] ?? '') !== 'success' && !str_contains($answer[1], 'set-value'), $answer[0] . ' ' . substr($answer[1], 0, 200));

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(D) the upload in the picker\n";
$count  = count($storeDocs());
$upload = static fn (Closure $who, string $t, array $fields, string $name, string $bytes): array => $multipart($who, $t, $fields + ['target' => 'fld1', 'root' => 'web', 'accept' => 'image', 'public' => '1'], $name, $bytes);
$env    = $json($upload($erna, $token, ['folder_id' => '3'], 'Neues Bild.png', TestPng::bytes(80, 50)));
$newId  = (int) ($env['data']['id'] ?? 0);
$row    = array_values(array_filter($storeDocs(), static fn (array $r): bool => (int) $r['id'] === $newId))[0] ?? [];
check('saved: success with the new id', ($env['status'] ?? '') === 'success' && $newId > 0, json_encode($env));
check('… in the shown folder, the user as createdBy, an image with its s thumbnail', (int) ($row['folder_id'] ?? 0) === 3 && (int) ($row['created_by'] ?? 0) === 7
    && ($row['kind'] ?? '') === 'image' && isset($row['variants']['s']), json_encode($row));
check('… an upload, not a generated file', ($row['source'] ?? 'upload') !== 'generated', (string) ($row['source'] ?? ''));
$grid = array_column($env['commands'] ?? [], null, 'action')['replace-html'] ?? [];
check('… the answer replaces the picker\'s grid, the new image marked', ($grid['target'] ?? '') === '#dms-pick-fld1 [data-dms-pick-grid]'
    && str_contains($grid['html'] ?? '', 'value="' . $newId . '"') && str_contains($grid['html'] ?? '', 'is-fresh') && str_contains($grid['html'] ?? '', 'form="dms-pick-fld1-choose"'));
check('… and scrolls to it', (array_column($env['commands'] ?? [], null, 'action')['scroll-to']['target'] ?? '') === '#dms-pick-fld1 [data-dms-pick-fresh]');
$env = $json($choose($erna, $token, $web + ['doc' => $newId]));
check('… and is a choice at once (the folder is public)', ($env['status'] ?? '') === 'success', json_encode($env));

$count = count($storeDocs());
foreach ([
    ['into a non-public folder (public=1)', $erna, $token, ['folder_id' => '4'], 'x.png', TestPng::bytes(10, 10), 'nicht öffentlich'],
    ['a PDF with accept=image', $erna, $token, ['folder_id' => '3'], 'x.pdf', $pdf, 'nicht erlaubt'],
    ['into an unreadable folder', $erna, $token, ['folder_id' => '5'], 'y.png', TestPng::bytes(11, 10), 'nicht gefunden'],
    ['into a folder outside the root', $erna, $token, ['folder_id' => '6'], 'z.png', TestPng::bytes(12, 10), 'nicht gefunden'],
    ['without write (leo reads only)', $leo, $leoToken, ['folder_id' => '3'], 'w.png', TestPng::bytes(13, 10), 'darfst du nichts hochladen'],
    ['into the drive root', $chef, $chefToken, ['folder_id' => '1', 'root' => ''], 'r.png', TestPng::bytes(14, 10), 'nicht'],
] as [$label, $who, $t, $fields, $name, $bytes, $word]) {
    $env = $json($upload($who, $t, $fields, $name, $bytes));
    check("refused {$label}: the row's error", ($env['status'] ?? '') === 'error' && str_contains((string) ($env['data']['message'] ?? ''), $word), json_encode($env));
}
check('… nothing stored by any of them', count($storeDocs()) === $count);
[, $html] = $pick($leo, $leoToken, $web + ['folder' => 3]);
check('a reader without write: no upload in the window, the reason', !str_contains($html, 'data-upload=') && str_contains($html, 'darfst du nichts hochladen'));

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(E) the field partial\n";
/** The session as AuthService gives it. */
final class PickerFakeAuth
{
    public function __construct(public AuthUser $user) {}
    public function getCurrentUser(): AuthUser { return $this->user; }
}
define('ABS_BASE_PATH', $site);
$auth = new PickerFakeAuth(new AuthUser(['id' => 7, 'user_name' => 'erna', 'roles' => ['admin'], 'realm' => AuthUser::REALM_BACKEND]));
DI::getInstance(true)
    ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
    ->set('FileFinder', fn ($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
    ->set('ConfigManager', fn ($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
    ->set('ModuleManager', fn ($c) => new \Z77\Core\Services\ModuleManager($c->get('ConfigManager')), true)
    ->set('I18n', fn ($c) => new \Z77\Core\Services\I18n($c->get('ConfigManager')), true)
    ->set('DataSourceResolver', fn () => new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File']), true)
    ->set('UnifiedEntityManager', fn ($c) => new \Z77\Persistence\Resolver\UnifiedEntityManager($c->get('DataSourceResolver')), true)
    ->set('AuthService', fn () => $auth, true);
DI::getCacheManager()->setCacheDir($site . '/var/cache');

$render = static fn (array $vars): string => (new TemplateRenderer(MediaField::NS))->partial('partials/mediaField', $vars);
$html = $render(['name' => 'project[image_id]', 'value' => 10, 'id' => 'proj-img', 'root' => 'web']);
check('the root carries the id and the field marker', str_contains($html, 'id="proj-img"') && str_contains($html, 'data-media-field '));
check('… the hidden input: the host\'s name, the id', preg_match('#<input type="hidden" name="project\[image_id\]" value="10" data-media-field-input>#', $html) === 1, $html);
check('… «Auswählen …» opens the picker for THIS field', str_contains($html, 'data-window-open="/backend/documents/drive/pick?target=proj-img&amp;root=web&amp;accept=image&amp;public=1"')
    && str_contains($html, 'data-media-field-pick'));
check('… the thumbnail of the chosen image (preview, variant s) and its name', str_contains($html, 'src="/backend/documents/document/preview?id=10&amp;variant=s"') && str_contains($html, '>bild.png<'));
check('… «Entfernen» and the empty frame as a template', str_contains($html, 'data-media-field-clear') && preg_match('#<template data-media-field-empty>\s*<span class="dms-media-field__thumb is-empty" data-media-field-thumb data-empty>#', $html) === 1, $html);
$html = $render(['name' => 'image_id', 'value' => null]);
check('no value: empty, a generated id in the address', preg_match('#id="(dms-mf-[0-9a-f]{8})"#', $html, $m) === 1 && str_contains($html, 'target=' . $m[1] . '&amp;')
    && str_contains($html, 'value="" data-media-field-input') && preg_match('#<div class="dms-media-field__body"[^>]*>\s*<span class="dms-media-field__thumb is-empty" data-media-field-thumb data-empty>#', $html) === 1, $html);
$html = $render(['name' => 'image_id', 'value' => 14, 'id' => 'x']);
check('an unreadable id: «nicht verfügbar», no name, the id kept', str_contains($html, 'is-missing') && !str_contains($html, 'geheim') && str_contains($html, 'value="14" data-media-field-input'));
$html = $render(['name' => 'image_id', 'value' => 5000, 'id' => 'x', 'public' => false, 'accept' => '']);
check('a deleted / unknown id: «nicht verfügbar»; public and accept left out of the address', str_contains($html, 'is-missing') && str_contains($html, 'pick?target=x"'));
$html = $render(['name' => 'image_id', 'id' => '"><script>', 'label' => '<b>Titelbild</b>']);
check('a hostile id is replaced, the label escaped', !str_contains($html, '<script>') && str_contains($html, '&lt;b&gt;Titelbild'));

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(F) ModuleDrive::ensurePublic\n";
$auth->user = new AuthUser(['id' => 0, 'user_name' => 'guest', 'roles' => [\Z77\Core\Config\AuthRole::GUEST]]);
$docs   = DocumentService::create();
$handle = $docs->forModule('projekte');
$rootId = (int) $handle->root()->getId();
$mode   = static fn (int $id): ?string => (array_values(array_filter($storeFolders(), static fn (array $f): bool => (int) $f['id'] === $id))[0] ?? [])['delivery_mode'] ?? null;
check('a new module partition delivers protected (inherited)', $mode($rootId) === null && $docs->effectiveFolderDeliveryMode($handle->root()) === 'protected');
$root = $handle->ensurePublic();
check('ensurePublic: the partition is public, in the store too', $root->getDeliveryMode() === 'public' && $mode($rootId) === 'public');
check('… a folder below it inherits public', $docs->effectiveFolderDeliveryMode($handle->folder('2026')) === 'public');
clearstatcache();
$file   = $site . '/data/documents/folders.json';
$bytes  = (string) file_get_contents($file);
touch($file, time() - 100);
clearstatcache();
$mtime  = filemtime($file);
$again  = DocumentService::create()->forModule('projekte')->ensurePublic();
clearstatcache();
check('… a second call: public, nothing written', $again->getDeliveryMode() === 'public' && filemtime($file) === $mtime && file_get_contents($file) === $bytes);

$sealed = array_map(static fn (array $f): array => (int) $f['id'] === $rootId ? ['delivery_mode' => 'sealed'] + $f : $f, $storeFolders());
file_put_contents($file, json_encode($sealed, JSON_PRETTY_PRINT));
removeTree($site . '/var/cache');
$threw = false;
try {
    DocumentService::create()->forModule('projekte')->ensurePublic();
} catch (\RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'sealed');
}
check('a sealed partition: refused, stays sealed', $threw && $mode($rootId) === 'sealed');

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "server log:\n" . substr((string) @file_get_contents($base . '/server.log'), -3000) . "\n";
    echo "php log:\n" . substr((string) @file_get_contents($site . '/logs/php-error.log'), -3000) . "\n";
}
exit($fail > 0 ? 1 : 0);
