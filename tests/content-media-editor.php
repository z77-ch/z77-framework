<?php

/**
 * The content editor's `document` fields (CLI, temp installation, PHP's built-in server) —
 * media-picker bauplan P2b. What is load-bearing:
 *
 *   (A) the editor (backend popup, fetch): kind `document` is THE media field — its hidden input
 *       carries `data-bf` / `data-bk="document"` and no `name` (only `blocks` is posted), top
 *       level (`media-image`) AND inside the gallery row `<template>` (`data-bf-sub`); object rows
 *       carry ↑ / ↓; the answer loads `documents/media-field` with the editor; «Auswählen …» opens
 *       the picker at the content partition: module key `content`, created on first need with
 *       the name «Inhalte» and made public;
 *   (B) the save judges a CHANGED reference through `ContentExtensions::documents()` (module-dms
 *       `ContentDocuments`): a public image is taken; an unknown id, a PDF, a protected image,
 *       an image the user may not read and a protected gallery item are refused with the
 *       reason, nothing written;
 *   (C) a reference already STORED is not asked again: a document that turned inactive since
 *       does not block a save, a newly chosen inactive one is refused;
 *   (D) the slot editor (the bare page in the website's iframe): the script on the page, the
 *       field in the form, the same check on its save;
 *   (E) the content partition is made public ONLY when created: a person's later «geschützt»
 *       stays; a project remaps it with `dmsConfig['moduleFolders']` (`content` → an existing
 *       partition), which is used as it is — never switched public.
 *
 * Run: php tests/content-media-editor.php
 */

$repo = str_replace('\\', '/', dirname(__DIR__));
if (!is_file($repo . '/vendor/autoload.php')) {
    fwrite(STDERR, "content-media-editor: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $repo . '/vendor/autoload.php';
require_once $repo . '/packages/kernel/core/src/autoload/prod/php/Helper.php';
require __DIR__ . '/support/real-flow-site.php';
require_once __DIR__ . '/support/TestPng.php';

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

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-content-media-' . getmypid() . '-' . bin2hex(random_bytes(3));
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
    ['id' => 8, 'username' => 'reto', 'name' => 'Reto Muster', 'email' => 'reto.muster@example.ch', 'password_hash' => $hash, 'roles' => ['editor'], 'sort_key' => 2],
]);
// The DMS on the installation, with a project's override of its config (a blueprint, the remap).
$finder = (string) file_get_contents($site . '/config/fileFinder.inc.php');
file_put_contents($site . '/config/fileFinder.inc.php', str_replace(
    "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),",
    "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),\n        'Z77\\\\Module\\\\Dms\\\\' => array_merge_recursive(['sourcePaths' => [" . var_export($site . '/project-override', true) . "]], \$entry('module-dms')),",
    $finder
));
$modules = (string) file_get_contents($site . '/config/moduleManager.inc.php');
file_put_contents($site . '/config/moduleManager.inc.php', (string) preg_replace("/('backend' =>\s*array \(\s*\),)/", "$1\n    'dms' => array (),", $modules));
/** The project's dmsConfig: the framework's plus a blueprint and $extra; a fresh request reads it. */
$projectConfig = static function (array $extra = []) use ($site, $repo): void {
    @mkdir($site . '/project-override/src/App/Config', 0777, true);
    $extra += ['contentBlueprints' => ['galerie' => [
        ['key' => 'bild', 'type' => 'media-image', 'label' => 'Titelbild'],
        ['key' => 'fotos', 'type' => 'media-gallery', 'label' => 'Fotos'],
    ]]];
    file_put_contents($site . '/project-override/src/App/Config/dmsConfig.inc.php', "<?php\n\$c = require " . var_export($repo . '/packages/module-dms/src/App/Config/dmsConfig.inc.php', true) . ";\n"
        . 'return array_replace($c, ' . var_export($extra, true) . ");\n");
    removeTree($site . '/var/cache');
};
$projectConfig();
// The DMS's published assets (the installer's job on a real installation): the editor resolves
// `documents/media-field` from there.
$assets = $repo . '/packages/module-dms/res/assets';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assets, FilesystemIterator::SKIP_DOTS)) as $asset) {
    $to = $site . '/public/assets/dms' . str_replace('\\', '/', substr($asset->getPathname(), strlen($assets)));
    if ($asset->isFile()) {
        @mkdir(dirname($to), 0777, true);
        copy($asset->getPathname(), $to);
    }
}

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
$seed(10, 2, 'bild', 'png', 'image/png', 'image', $png);
$seed(11, 2, 'bericht', 'pdf', 'application/pdf', 'pdf', $pdf);
$seed(12, 3, 'intern', 'png', 'image/png', 'image', $png);
$seed(13, 2, 'zweites', 'png', 'image/png', 'image', $png);
$seed(14, 5, 'geheim', 'png', 'image/png', 'image', $png);
$seed(15, 2, 'drittes', 'png', 'image/png', 'image', $png);
mkdir($site . '/data/documents', 0777, true);
$docsFile    = $site . '/data/documents/documents.json';
$foldersFile = $site . '/data/documents/folders.json';
file_put_contents($docsFile, json_encode($rows, JSON_PRETTY_PRINT));
$folder = static fn (int $id, ?int $parent, string $name, ?string $mode = null, array $extra = []): array => $extra + ['id' => $id, 'name' => $name, 'active' => true, 'slug' => strtolower($name), 'delivery_mode' => $mode, 'sort_key' => $id, 'parent_id' => $parent];
file_put_contents($foldersFile, json_encode([
    $folder(1, null, 'Drive', null, ['key' => 'drive', 'system' => true]),
    $folder(2, 1, 'Webseite', 'public', ['key' => 'web', 'system' => true]),
    $folder(3, 2, 'Intern', 'protected'),
    $folder(5, 1, 'Geheim', null, ['key' => 'geheim', 'system' => true]),
], JSON_PRETTY_PRINT));
file_put_contents($site . '/data/documents/access_control.json', json_encode([
    ['id' => 1, 'resource_type' => 'folder', 'resource_id' => 2, 'subject_type' => 'user', 'subject_id' => '7', 'rights' => 'write'],
], JSON_PRETTY_PRINT));
$readJson = static fn (string $file): array => json_decode((string) file_get_contents($file), true) ?: [];
$patch    = static function (string $file, int $id, array $fields) use ($readJson, $site): void {
    file_put_contents($file, json_encode(array_map(static fn (array $r): array => (int) $r['id'] === $id ? $fields + $r : $r, $readJson($file)), JSON_PRETTY_PRINT));
    removeTree($site . '/var/cache');
};
$partition = static function (string $key) use ($readJson, $foldersFile): ?array {
    foreach ($readJson($foldersFile) as $row) {
        if (($row['key'] ?? null) === $key) {
            return $row;
        }
    }
    return null;
};

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

$browser = static function () use ($port): Closure {
    $cookies = [];
    return static function (string $path, array|string|null $post = null, array $headers = []) use (&$cookies, $port): array {
        $head = $headers;
        if ($cookies !== []) {
            $head[] = 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($cookies), $cookies));
        }
        $opts = ['ignore_errors' => true, 'timeout' => 60, 'follow_location' => 0, 'max_redirects' => 0, 'method' => $post === null ? 'GET' : 'POST'];
        if (is_string($post)) {
            $head[] = 'Content-Type: application/json';
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
[$chef, $chefToken, $st] = $login('chef');
check('a real login (chef)', $st === 200 && $chefToken !== '', (string) $st);
[$erna, $ernaToken] = $login('erna');
[$reto, $retoToken] = $login('reto');

$fetch  = static fn (string $t): array => ['Sec-Fetch-Mode: cors', 'X-Requested-With: XMLHttpRequest', 'X-CSRF-Token: ' . $t];
$get    = static fn (Closure $who, string $t, string $path): array => $who($path, null, $fetch($t));
$post   = static fn (Closure $who, string $t, string $path, array $body): array => $who($path, (string) json_encode($body), $fetch($t));
$json   = static fn (array $answer): array => (array) json_decode($answer[1], true);
/** A new document of $blocks, posted by $who: [answer, saved?]. */
$add = static function (Closure $who, string $t, string $slug, array $blocks) use ($post, $json, $site): array {
    $answer = $post($who, $t, '/backend/content/content/add', ['slug' => $slug, 'title' => 'Seite ' . $slug, 'active' => true, 'blocks' => json_encode($blocks)]);
    return [$answer, ($json($answer)['status'] ?? '') === 'success' && is_file("{$site}/data/content/{$slug}.de.json")];
};
$image   = static fn (string $id): array => ['type' => 'media-image', 'documentId' => $id, 'alt' => 'Ein Bild', 'caption' => '', 'link' => ''];
$gallery = static fn (array $ids): array => ['type' => 'media-gallery', 'items' => array_map(static fn (string $id): array => ['documentId' => $id, 'alt' => 'Bild ' . $id, 'caption' => ''], $ids)];
/** The `<template data-ce-tpl="$type">` of the editor, or ''. */
$template = static function (string $html, string $type): string {
    $at = strpos($html, '<template data-ce-tpl="' . $type . '">');
    if ($at === false) {
        return '';
    }
    $next = strpos($html, '<template data-ce-tpl="', $at + 1);
    return substr($html, $at, $next === false ? null : $next - $at);
};

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(A) the editor\n";
check('no content partition before the editor needs one', $partition('content') === null);
[$s, $html] = $get($chef, $chefToken, '/backend/content/content/add');
$content = $partition('content');
check('the editor opens', $s === 200 && str_contains($html, 'data-ce-editor'), "{$s} " . substr($html, 0, 200));
check('… the content partition: module key `content`, «Inhalte», below the drive root, public', $content !== null && ($content['name'] ?? '') === 'Inhalte'
    && (int) ($content['parent_id'] ?? 0) === 1 && ($content['delivery_mode'] ?? null) === 'public', json_encode($content));
$cid  = (int) ($content['id'] ?? 0);
$tpl  = $template($html, 'media-image');
check('media-image: THE media field, its input with data-bf + data-bk="document" and no name', str_contains($tpl, 'data-media-field ')
    && preg_match('#<input type="hidden" data-bf="documentId" data-bk="document" value="" data-media-field-input>#', $tpl) === 1, substr($tpl, 0, 600));
check('… «Auswählen …» opens the picker at the content partition, images, public', str_contains($tpl, 'data-window-open="/backend/documents/drive/pick?target=dms-mf-')
    && str_contains($tpl, '&amp;root=' . $cid . '&amp;accept=image&amp;public=1"'));
check('kind `url` (media-image › link) is a text input: the browser must not refuse `/contact` (CE-URL-001)', preg_match('#<input type="text" inputmode="url" spellcheck="false" data-bf="link"#', $tpl) === 1
    && !str_contains($html, 'type="url"'), substr($tpl, 0, 900));
$gtpl =$template($html, 'media-gallery');
check('media-gallery: the row template holds a media field with data-bf-sub', preg_match('#<template data-ce-row-tpl>.*data-bf-sub="documentId" data-bk="document".*data-media-field-input.*</template>#s', $gtpl) === 1, substr($gtpl, 0, 400));
check('… the object row carries ↑ / ↓', str_contains($gtpl, 'data-ce-row-up') && str_contains($gtpl, 'data-ce-row-down'));
$envelope = preg_match('#<script type="application/json" data-z77-envelope>(.*?)</script>#s', $html, $e) === 1 ? (array) json_decode($e[1], true) : [];
$scripts  = array_values(array_filter($envelope['commands'] ?? [], static fn (array $c): bool => ($c['action'] ?? '') === 'load-script'));
$media    = array_values(array_filter($scripts, static fn (array $c): bool => str_contains((string) ($c['src'] ?? ''), '/documents/media-field')))[0] ?? [];
check('the answer loads documents/media-field with the editor (init dms-media-field, the popup body)', ($media['init'] ?? '') === 'dms-media-field'
    && ($media['scope'] ?? '') === '[data-z77-popup-body]' && count($scripts) === 2, json_encode($scripts));

// The editor role (owner 2026-11-11): the new partition grants `editor` write and `admin`
// manage — role ACEs match by exact name, so each role is granted on its own.
$aces    = $readJson($site . '/data/documents/access_control.json');
$roleAce = static fn (string $role): ?string => (array_values(array_filter($aces, static fn (array $a): bool => ($a['resource_type'] ?? '') === 'folder'
    && (int) ($a['resource_id'] ?? 0) === $cid && ($a['subject_type'] ?? '') === 'role' && ($a['subject_id'] ?? '') === $role))[0] ?? [])['rights'] ?? null;
check('… granted: `editor` write, `admin` manage on the new partition', $roleAce('editor') === 'write' && $roleAce('admin') === 'manage', json_encode($aces));
$pickQ = ['target' => 'f1', 'root' => (string) $cid, 'accept' => 'image', 'public' => '1'];
[$s, $html] = $get($reto, $retoToken, '/backend/documents/drive/pick?' . http_build_query($pickQ));
check('an EDITOR opens the picker at the content partition', $s === 200 && str_contains($html, 'data-window="dms-pick"'), "{$s} " . substr($html, 0, 300));
check('… one folder, nothing below it: no tree column, the grid takes the width', !str_contains($html, 'dms-pick__tree') && str_contains($html, 'dms-pick__body--single'));
$boundary = 'z77' . bin2hex(random_bytes(8));
$body     = '';
foreach ($pickQ + ['folder_id' => (string) $cid] as $k => $v) {
    $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
}
$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"files[]\"; filename=\"Redaktor.png\"\r\nContent-Type: application/octet-stream\r\n\r\n" . TestPng::bytes(80, 50) . "\r\n--{$boundary}--\r\n";
$up    = $json($reto('/backend/documents/drive/pick-upload', $body, array_merge($fetch($retoToken), ["Content-Type: multipart/form-data; boundary={$boundary}"])));
$retoDoc = (int) ($up['data']['id'] ?? 0);
check('… uploads into it', ($up['status'] ?? '') === 'success' && $retoDoc > 0, json_encode($up));
[$s] = $get($reto, $retoToken, '/backend/documents/document/preview?id=' . $retoDoc . '&variant=s');
check('… and loads its thumbnail (document/preview is EDITOR)', $s === 200, (string) $s);
[$s] = $get($reto, $retoToken, '/backend/documents/drive/list');
check('… the Drive itself stays closed to him', $s !== 200, (string) $s);

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(B) the save judges a changed reference\n";
[$answer, $saved] = $add($erna, $ernaToken, 'mit-bild', [$image('10')]);
check('a public image: saved', $saved, substr($answer[1], 0, 300));
$stored = $readJson($site . '/data/content/mit-bild.de.json');
check('… the id stored as the editor sent it', ($stored['blocks'][0]['documentId'] ?? null) === '10', json_encode($stored['blocks'] ?? null));
foreach ([
    ['an unknown id', [$image('999')], 'nennt kein Dokument, das es gibt (Nr. 999)'],
    ['a PDF', [$image('11')], 'Nr. 11 ist kein Bild'],
    ['a protected image', [$image('12')], 'Nr. 12: Nicht öffentlich (geschützt) — hier sind nur öffentlich ausgelieferte Dokumente wählbar'],
    ['an image the user may not read (as if unknown)', [$image('14')], 'nennt kein Dokument, das es gibt (Nr. 14)'],
    ['a gallery with a protected item', [$gallery(['10', '12'])], 'Eintrag 2, «Bild» Nr. 12: Nicht öffentlich (geschützt)'],
] as $n => [$label, $blocks, $message]) {
    [$answer, $saved] = $add($erna, $ernaToken, 'falsch-' . $n, $blocks);
    check("refused: {$label}, with the reason, nothing written", !$saved && str_contains($answer[1], htmlspecialchars($message, ENT_QUOTES)) || (!$saved && str_contains($answer[1], $message)),
        substr(strip_tags($answer[1]), 0, 400));
}
[$answer, $saved] = $add($chef, $chefToken, 'chef-geheim', [$image('14')]);
check('the super user may read Geheim, but it is not public: refused for that', !$saved && str_contains($answer[1], 'Nr. 14: Nicht öffentlich'), substr(strip_tags($answer[1]), 0, 300));
[$answer, $saved] = $add($erna, $ernaToken, 'galerie-ok', [$gallery(['10', '13'])]);
check('a gallery of public images: saved', $saved, substr(strip_tags($answer[1]), 0, 300));

[, $html] = $get($erna, $ernaToken, '/backend/content/content/edit?slug=mit-bild&language=de&variant=');
check('the editor shows the chosen image (preview, variant s)', str_contains($html, 'src="/backend/documents/document/preview?id=10&amp;variant=s"')
    && preg_match('#data-bf="documentId" data-bk="document" value="10" data-media-field-input#', $html) === 1);

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(C) a stored reference is not asked again\n";
$patch($docsFile, 10, ['active' => false]);
$patch($docsFile, 15, ['active' => false]);
[, $html] = $get($erna, $ernaToken, '/backend/content/content/edit?slug=mit-bild&language=de&variant=');
preg_match('/name="entity_csrf" value="([^"]+)"/', $html, $c);
preg_match('/name="entity_hash" value="([^"]+)"/', $html, $h);
$edit = static fn (array $blocks) => $post($erna, $ernaToken, '/backend/content/content/edit?slug=mit-bild&language=de&variant=', [
    'entity_csrf' => $c[1] ?? '', 'entity_hash' => $h[1] ?? '', 'title' => 'Neu betitelt', 'active' => true, 'blocks' => json_encode($blocks)]);
$answer = $edit([$image('10')]);
check('the stored image turned inactive: another change still saves', ($json($answer)['status'] ?? '') === 'success', substr(strip_tags($answer[1]), 0, 300));
[, $html] = $get($erna, $ernaToken, '/backend/content/content/edit?slug=mit-bild&language=de&variant=');
preg_match('/name="entity_csrf" value="([^"]+)"/', $html, $c);
preg_match('/name="entity_hash" value="([^"]+)"/', $html, $h);
$answer = $edit([$image('10'), $image('15')]);
check('… a newly chosen inactive image is refused', ($json($answer)['status'] ?? '') !== 'success' && str_contains($answer[1], 'Nr. 15: Nicht öffentlich (inaktiv)'), substr(strip_tags($answer[1]), 0, 300));
$patch($docsFile, 10, ['active' => true]);

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(D) the slot editor (the bare page)\n";
[$answer, $saved] = $add($chef, $chefToken, 'galerie', [
    ['type' => 'media-image', 'key' => 'bild'] + $image('10'),
    ['type' => 'media-gallery', 'key' => 'fotos'] + $gallery(['10']),
]);
check('a blueprint document of media blocks: saved', $saved, substr(strip_tags($answer[1]), 0, 300));
[$s, $page] = $chef('/backend/content/content/slot?slug=galerie&language=de&variant=&slot=fotos');
check('the bare page: the media field script on the page', $s === 200 && preg_match('#<script[^>]+src="[^"]*/documents/media-field[^"]*\.js"#', $page) === 1, $s . ' ' . substr($page, 0, 300));
check('… the gallery item\'s field with its image, the row template with an empty field', str_contains($page, 'data-bf-sub="documentId" data-bk="document" value="10" data-media-field-input')
    && preg_match('#<template data-ce-row-tpl>.*data-bf-sub="documentId" data-bk="document" value="" data-media-field-input#s', $page) === 1);
preg_match('/name="entity_csrf" value="([^"]+)"/', $page, $c);
preg_match('/name="entity_hash" value="([^"]+)"/', $page, $h);
$slot = static fn (array $items) => $post($chef, $chefToken, '/backend/content/content/slot?slug=galerie&language=de&variant=&slot=fotos', [
    'entity_csrf' => $c[1] ?? '', 'entity_hash' => $h[1] ?? '', 'blocks' => json_encode([['type' => 'media-gallery', 'key' => 'fotos'] + $gallery($items)])]);
$answer = $slot(['10', '12']);
check('slot save with a protected image: refused, the form again, its script with it', ($json($answer)['status'] ?? '') !== 'success'
    && str_contains($answer[1], 'Nr. 12: Nicht öffentlich') && str_contains($answer[1], 'dms-media-field'), substr(strip_tags($answer[1]), 0, 300));
$answer = $slot(['13', '10']);
check('slot save with public images: saved, the page told', ($json($answer)['status'] ?? '') === 'success' && str_contains($answer[1], 'z77:content-saved'), substr($answer[1], 0, 300));
$fotos = array_values(array_filter($readJson($site . '/data/content/galerie.de.json')['blocks'] ?? [], static fn (array $b): bool => ($b['key'] ?? '') === 'fotos'))[0] ?? [];
check('… the gallery in the new order', array_column($fotos['items'] ?? [], 'documentId') === ['13', '10'], json_encode($fotos));

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(E) public only when created\n";
$patch($foldersFile, $cid, ['delivery_mode' => 'protected']);
$get($chef, $chefToken, '/backend/content/content/add');
check('a person set the content partition protected: the editor leaves it so', ($partition('content')['delivery_mode'] ?? null) === 'protected');
$count = count($readJson($foldersFile));
$projectConfig(['moduleFolders' => ['content' => 'geheim']]);
[, $html] = $get($chef, $chefToken, '/backend/content/content/add');
check('remapped (`moduleFolders`): the picker opens at that partition', str_contains($template($html, 'media-image'), '&amp;root=5&amp;'), substr($template($html, 'media-image'), 0, 500));
check('… which is used as it is: not switched public, nothing created', array_key_exists('delivery_mode', $partition('geheim') ?? []) && $partition('geheim')['delivery_mode'] === null && count($readJson($foldersFile)) === $count);

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "php log:\n" . substr((string) @file_get_contents($site . '/logs/php-error.log'), -3000) . "\n";
}
exit($fail > 0 ? 1 : 0);
