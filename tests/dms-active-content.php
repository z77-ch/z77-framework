<?php

/**
 * DMS active content harness (CLI, offline, no DI) — DMS-ACTIVE-001.
 *
 * A static copy under `public/media` is served by the web server ITSELF, by extension, with no
 * PHP and no login, so `DeliveryPolicy` cannot protect it. And `public/media` is not only the
 * DMS's: a project may keep hand-placed files there, beside the partitions. What is load-bearing:
 *
 *   (A) the safe extension list is one constant, shared with the guard, and holds nothing active;
 *   (B) `materialize()` refuses every unsafe combination: an active extension, an active MIME, a
 *       mismatch in either direction, a variant judged by its OWN extension and MIME;
 *   (C) `materialize()` still writes the safe ones, byte-identical, in a public active folder only;
 *   (D) the bodies: the base guard is the verified handler block and nothing else, the partition
 *       guard adds plain text at the top level and the safe list (read by content: no Apache here);
 *   (E) an old or changed guard is replaced, an identical one is left alone and costs no sweep;
 *   (F) a hand-placed tree beside a partition survives untouched and gets no guard; inside a
 *       partition only a provable copy goes, the rest stays and is counted; a second run does
 *       nothing; two partitions are guarded independently;
 *   (G) a link out of the tree is not followed; `public/media` itself may be a link;
 *   (H) an unwritable base logs and does not break delivery;
 *   (I) the triggers: `materialize()` for its partition, `guardMedia()` for all, `OutputController`
 *       before any 404, the Drive's list action.
 *
 * Run: php tests/dms-active-content.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "dms-active-content: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Z77\Core\Http\Response\DeliveryPolicy;
use Z77\Core\Http\Response\FileResponse;
use Z77\Module\Dms\Blob\BlobStorage;
use Z77\Module\Dms\Entities\Document;
use Z77\Module\Dms\Entities\Folder;
use Z77\Module\Dms\Images\UploadTypes;
use Z77\Module\Dms\Repositories\DocumentRepository;
use Z77\Module\Dms\Repositories\FolderRepository;
use Z77\Module\Dms\Services\DocumentService;

$work = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-active-content-' . getmypid();
define('ABS_BASE_PATH', $work . '/site');
mkdir(ABS_BASE_PATH . '/public', 0777, true);
ini_set('error_log', $work . '/php.log');
ini_set('display_errors', '0'); // a refused copy() warns, as it does in production

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

/** Removes a tree below $work; every link made here was removed before, and stays inside $work. */
function rrm(string $path): void
{
    clearstatcache(true);
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            rrm($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

/** Directory link: symlink where allowed, NTFS junction on Windows. */
function makeDirLink(string $target, string $link): bool
{
    if (@symlink($target, $link)) {
        clearstatcache(true);
        return true;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        exec('cmd /c mklink /J "' . str_replace('/', '\\', $link) . '" "' . str_replace('/', '\\', $target) . '" 2>NUL', $out, $rc);
        clearstatcache(true);
        return $rc === 0;
    }
    return false;
}

function removeDirLink(string $link): void
{
    @rmdir($link);
    @unlink($link);
    clearstatcache(true);
}

/** The files below a directory, relative, sorted — real directories only. */
function tree(string $dir, string $prefix = ''): array
{
    $out = [];
    foreach (@scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            $out = array_merge($out, tree($path, $prefix . $entry . '/'));
        } else {
            $out[] = $prefix . $entry;
        }
    }
    sort($out);
    return $out;
}

function sorted(array $list): array
{
    sort($list);
    return $list;
}

function place(string $base, array $files): void
{
    foreach ($files as $file) {
        @mkdir(dirname($base . '/' . $file), 0777, true);
        file_put_contents($base . '/' . $file, 'content of ' . $file);
    }
}

$baseFn      = new ReflectionMethod(DocumentService::class, 'ensureMediaGuard');
$baseGuard   = static fn (string $base) => $baseFn->invoke(null, $base);
$baseBody    = (string) (new ReflectionMethod(DocumentService::class, 'mediaGuardBody'))->invoke(null);
$partBody    = (string) (new ReflectionMethod(DocumentService::class, 'partitionGuardBody'))->invoke(null);
$logged      = static fn (): string => (string) @file_get_contents($work . '/php.log');
$execBlock   = '<FilesMatch "\\.(' . implode('|', UploadTypes::EXECUTABLE) . ")\$\">\n    SetHandler none\n    ForceType text/plain\n</FilesMatch>\n";
$oldBase     = "# z77 DMS (DMS-MEDIA-PHP-001): static copies only — nothing here is ever run.\n"
             . "# Written by DocumentService::materialize() when missing; edit the framework, not this file.\n"
             . $execBlock;

// ── partitions, a nested folder and a blob store with one file per variant ──────────────────

$source = $work . '/blob.bin';
file_put_contents($source, "the document's bytes \0\xff");

$blob = new class ($source) implements BlobStorage {
    public int $pathCalls = 0;
    public function __construct(private string $file) {}
    public function put(int $id, string $variant, string $ext, string $bytes): void {}
    public function putFile(int $id, string $variant, string $ext, string $sourcePath, bool $isUpload = true): void {}
    public function get(int $id, string $variant, string $ext): ?string { return null; }
    public function path(int $id, string $variant, string $ext): ?string { $this->pathCalls++; return $this->file; }
    public function exists(int $id, string $variant, string $ext): bool { return true; }
    public function size(int $id, string $variant, string $ext): ?int { return 1; }
    public function delete(int $id): void {}
};

$folder = static function (int $id, ?int $parent, string $slug, ?string $mode, bool $active = true): Folder {
    $f = new Folder();
    (new ReflectionProperty(Folder::class, 'id'))->setValue($f, $id);
    $f->setParentId($parent);
    $f->setSlug($slug);
    $f->setDeliveryMode($mode);
    $f->setActive($active);
    return $f;
};
$folders = new class ([$folder(1, null, 'drive', null), $folder(2, 1, 'oeffentlich', 'public'), $folder(3, 1, 'intern', 'protected'),
                       $folder(4, 1, 'aus', 'public', false), $folder(5, 1, 'zweite', 'public'), $folder(6, 2, 'unter', null), $folder(7, 2, 'out', null)]) extends FolderRepository {
    public function __construct(private array $rows) {}
    public function findAll(): array { return $this->rows; }
    public function findDriveRoot(): ?Folder { return $this->rows[0]; }
    public function findRoots(): array { return array_values(array_filter($this->rows, static fn (Folder $f) => $f->getParentId() === 1)); }
};
$documents = new class extends DocumentRepository {
    public array $stored = [];
    public function __construct() {}
    public function findByFolder(?int $folderId): array { return array_values(array_filter($this->stored, static fn (Document $d) => $d->getFolderId() === $folderId)); }
};

$svc = (new ReflectionClass(DocumentService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(DocumentService::class, 'blob'))->setValue($svc, $blob);
(new ReflectionProperty(DocumentService::class, 'folders'))->setValue($svc, $folders);
(new ReflectionProperty(DocumentService::class, 'documents'))->setValue($svc, $documents);

$doc = static function (string $kind, string $ext, string $mime, int $folderId = 2, array $variants = [], string $slug = 'datei'): Document {
    $d = new Document();
    (new ReflectionProperty(Document::class, 'id'))->setValue($d, 9);
    $d->setFolderId($folderId);
    $d->setSlug($slug);
    $d->setOriginalName("{$slug}.{$ext}");
    $d->setExt($ext);
    $d->setMimeType($mime);
    $d->setKind($kind);
    $d->setChecksum(str_repeat('a', 64));
    $d->setVariants($variants);
    return $d;
};

$media = ABS_BASE_PATH . '/public/media';

echo "(A) the safe list\n";
foreach (['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'pdf', 'mp4', 'm4v', 'webm', 'mov', 'ogv', 'mp3', 'm4a', 'wav', 'ogg', 'oga', 'flac', 'aac'] as $ext) {
    check("{$ext} is on the list", UploadTypes::isStaticSafe($ext));
}
check('the list is exactly those 21', count(UploadTypes::STATIC_SAFE) === 21, (string) count(UploadTypes::STATIC_SAFE));
foreach (['html', 'htm', 'xhtml', 'svg', 'svgz', 'xml', 'js', 'mjs', 'css', 'txt', 'csv', 'json', 'php', 'tif', 'tiff', 'laz', 'doc', ''] as $ext) {
    check("'{$ext}' is not", !UploadTypes::isStaticSafe($ext));
}
check('case and dot are folded', UploadTypes::isStaticSafe('JPG') && UploadTypes::isStaticSafe('.Pdf'));
check('null is not safe', !UploadTypes::isStaticSafe(null));
check('nothing executable on the list', array_intersect(UploadTypes::STATIC_SAFE, UploadTypes::EXECUTABLE) === []);

echo "(B) materialize() refuses\n";
$refused = [
    ['html document', 'html', 'html', 'text/html'],
    ['html named .htm', 'html', 'htm', 'text/html'],
    ['XHTML', 'html', 'xhtml', 'application/xhtml+xml'],
    ['SVG', 'image', 'svg', 'image/svg+xml'],
    ['XML', 'text', 'xml', 'text/xml'],
    ['XML, other type', 'text', 'xml', 'application/xml'],
    ['text', 'text', 'txt', 'text/plain'],
    ['a .txt with <script>, sniffed', 'html', 'txt', 'text/html'],
    ['CSV', 'text', 'csv', 'text/csv'],
    ['JSON', 'json', 'json', 'application/json'],
    ['JavaScript', 'text', 'js', 'application/javascript'],
    ['CSS', 'text', 'css', 'text/css'],
    ['a JPEG MIME with .html (polyglot)', 'image', 'html', 'image/jpeg'],
    ['a JPEG MIME with .svg', 'image', 'svg', 'image/jpeg'],
    ['a PDF MIME with .xml', 'pdf', 'xml', 'application/pdf'],
    ['an allowed extension with an HTML MIME', 'html', 'jpg', 'text/html'],
    ['an allowed extension with an SVG MIME', 'image', 'png', 'image/svg+xml'],
    ['an allowed extension with an XHTML MIME', 'html', 'pdf', 'application/xhtml+xml'],
    ['an allowed extension with text', 'text', 'mp4', 'text/plain'],
    ['an allowed extension with JSON', 'json', 'png', 'application/json'],
    ['an allowed extension with a hostile spelling', 'html', 'jpg', 'TEXT/HTML; charset=x'],
    ['an allowed extension with +xml under video', 'video', 'mp4', 'video/x+xml'],
    ['an allowed extension with an unknown type', 'image', 'jpg', 'image/heic'],
    ['an allowed extension with an empty type', 'image', 'jpg', ''],
    ['a safe type with an extension off the list (TIFF)', 'image', 'tif', 'image/tiff'],
    ['an extension-only type (alwaysDownload)', 'file', 'jpg', 'image/jpeg'],
    ['the executable check still stands', 'image', 'php', 'image/jpeg'],
];
foreach ($refused as [$label, $kind, $ext, $mime]) {
    $blob->pathCalls = 0;
    $svc->materialize($doc($kind, $ext, $mime));
    check("{$label} → no copy", $blob->pathCalls === 0 && tree($media) === [], json_encode(tree($media)));
}
$blob->pathCalls = 0;
$svc->materialize($doc('video', 'mp4', 'video/mp4', 2, ['s' => ['ext' => 'html']]), 's');
check('a variant is judged by its own extension (safe original, .html variant)', $blob->pathCalls === 0 && tree($media) === [], json_encode(tree($media)));
$svc->materialize($doc('image', 'svg', 'image/svg+xml', 2, ['s' => ['ext' => 'svg']]), 's');
check('a variant inherits an active type (SVG variant of an SVG)', tree($media) === [], json_encode(tree($media)));

echo "(C) materialize() writes the safe ones\n";
$withGuards = static fn (string $file): array => sorted(['.htaccess', 'oeffentlich/.htaccess', $file]);
$allowed = [
    ['image', 'jpg', 'image/jpeg'], ['image', 'jpeg', 'image/jpeg'], ['image', 'png', 'image/png'], ['image', 'gif', 'image/gif'],
    ['image', 'webp', 'image/webp'], ['image', 'avif', 'image/avif'], ['image', 'bmp', 'image/bmp'], ['image', 'ico', 'image/x-icon'],
    ['image', 'ico', 'image/vnd.microsoft.icon'], ['pdf', 'pdf', 'application/pdf'], ['video', 'mp4', 'video/mp4'], ['video', 'm4v', 'video/x-m4v'],
    ['video', 'webm', 'video/webm'], ['video', 'mov', 'video/quicktime'], ['video', 'ogv', 'video/ogg'], ['audio', 'mp3', 'audio/mpeg'],
    ['audio', 'm4a', 'audio/x-m4a'], ['audio', 'wav', 'audio/x-wav'], ['audio', 'ogg', 'audio/ogg'], ['audio', 'oga', 'audio/ogg'],
    ['audio', 'flac', 'audio/flac'], ['audio', 'aac', 'audio/aac'], ['image', 'jpg', 'IMAGE/JPEG; x=y'],
];
foreach ($allowed as [$kind, $ext, $mime]) {
    rrm($media);
    $svc->materialize($doc($kind, $ext, $mime));
    $file = "oeffentlich/datei.{$ext}";
    check("{$ext} as {$mime} → copy and both guards", tree($media) === $withGuards($file), json_encode(tree($media)));
    check("{$ext}: the copy is the blob's bytes", @file_get_contents("{$media}/{$file}") === file_get_contents($source));
}
rrm($media);
$svc->materialize($doc('video', 'mp4', 'video/mp4', 2, ['s' => ['ext' => 'jpg', 'width' => 10]]), 's');
check("a video's poster: copied under its own extension and MIME", tree($media) === $withGuards('oeffentlich/datei.s.jpg'), json_encode(tree($media)));
rrm($media);
$svc->materialize($doc('image', 'JPG', 'image/jpeg'));
check('an upper-case extension is folded', tree($media) === $withGuards('oeffentlich/datei.jpg'), json_encode(tree($media)));
rrm($media);
$svc->materialize($doc('image', 'jpg', 'image/jpeg', 6));
check('a copy in a nested folder: the guard of its partition, not of the folder',
    tree($media) === sorted(['.htaccess', 'oeffentlich/.htaccess', 'oeffentlich/unter/datei.jpg']), json_encode(tree($media)));
rrm($media);
$svc->materialize($doc('image', 'jpg', 'image/jpeg', 3));
check('a protected folder: no copy', tree($media) === [], json_encode(tree($media)));
$svc->materialize($doc('image', 'jpg', 'image/jpeg', 4));
check('an inactive folder: no copy', tree($media) === [], json_encode(tree($media)));
$inactive = $doc('image', 'jpg', 'image/jpeg');
$inactive->setActive(false);
$svc->materialize($inactive);
check('an inactive document: no copy', tree($media) === [], json_encode(tree($media)));

echo "(D) the bodies\n";
$topLevel = static fn (string $body): array => array_values(array_filter(explode("\n", trim($body)),
    static fn (string $l) => $l !== '' && $l[0] !== ' ' && $l[0] !== '#' && $l[0] !== '<'));
check('base guard: nothing at the top level, no ForceType there', $topLevel($baseBody) === [], json_encode($topLevel($baseBody)));
check('base guard: exactly the verified block under its comments', str_ends_with($baseBody, "\n" . $execBlock)
    && substr_count($baseBody, '<FilesMatch') === 1 && str_starts_with($baseBody, '#') && strlen($baseBody) < strlen($execBlock) + 250, $baseBody);
check('base guard: no safe list, no nosniff, no type outside the block',
    !str_contains($baseBody, 'ForceType None') && !str_contains($baseBody, 'nosniff') && substr_count($baseBody, 'ForceType') === 1 && substr_count($baseBody, 'SetHandler') === 1, $baseBody);
check('the block is byte-identical to the old guard\'s', str_ends_with($oldBase, $execBlock) && str_ends_with($baseBody, $execBlock));
check('partition guard: plain text at the top level, no SetHandler there', $topLevel($partBody) === ['ForceType text/plain'], json_encode($topLevel($partBody)));
$safeList = implode('|', UploadTypes::STATIC_SAFE);
check('partition guard: the safe list comes from the constant', str_contains($partBody, "<FilesMatch \"(?i)\\.({$safeList})\$\">\n    ForceType None\n</FilesMatch>"), $partBody);
check('partition guard: the verified block on the executable extensions', str_contains($partBody, $execBlock), $partBody);
check('partition guard: nosniff, guarded by the module', str_contains($partBody, "<IfModule mod_headers.c>\n    Header always set X-Content-Type-Options \"nosniff\"\n</IfModule>"), $partBody);
foreach (['base' => [$baseBody, ['FilesMatch', 'ForceType', 'SetHandler']], 'partition' => [$partBody, ['FilesMatch', 'ForceType', 'Header', 'IfModule', 'SetHandler']]] as $name => [$body, $want]) {
    $directives = [];
    foreach (explode("\n", $body) as $line) {
        if (preg_match('/^\s*<?\/?([A-Za-z]+)/', $line, $m) && !str_starts_with(trim($line), '#')) {
            $directives[$m[1]] = true;
        }
    }
    ksort($directives);
    check("{$name} guard: FileInfo-level directives only (no deny, no rewrite, no Options)", array_keys($directives) === $want, implode(',', array_keys($directives)));
}
preg_match_all('/<FilesMatch "(.+)">/', $partBody, $m);
$safeRx = '/' . str_replace('/', '\\/', $m[1][0]) . '/';
foreach (['x.jpg', 'x.JPG', 'a.b.s.webp', 'x.pdf', 'x.mp4', 'x.aac'] as $name) {
    check("safe block matches {$name}", preg_match($safeRx, $name) === 1);
}
foreach (['x.html', 'x.svg', 'x.xml', 'x.txt', 'x.js', 'x.jpg.html', 'jpg', 'xjpg', 'x.jpgx', 'x.php'] as $name) {
    check("safe block does not match {$name}", preg_match($safeRx, $name) === 0);
}

echo "(E) replacing\n";
$base = $work . '/e';
$baseGuard($base);
check('no media directory → nothing written, no directory created', !is_dir($base));
rrm($media);
$svc->guardMedia();
check('guardMedia() without a media directory: nothing', !is_dir($media));
mkdir($base);
$baseGuard($base);
check('base guard missing → written', @file_get_contents($base . '/.htaccess') === $baseBody);
file_put_contents($base . '/.htaccess', $oldBase);
$baseGuard($base);
check('the old base guard is replaced', file_get_contents($base . '/.htaccess') === $baseBody);
file_put_contents($base . '/.htaccess', $baseBody . "# hand edit\n");
$baseGuard($base);
check('a changed base guard is replaced', file_get_contents($base . '/.htaccess') === $baseBody);
file_put_contents($base . '/.htaccess', str_replace("\n", "\r\n", $baseBody));
$baseGuard($base);
check('line endings count as a change', file_get_contents($base . '/.htaccess') === $baseBody);
mkdir($media);
place($media, ['oeffentlich/x.jpg']);
$svc->guardMedia();
check('partition guard missing → written', @file_get_contents($media . '/oeffentlich/.htaccess') === $partBody);
file_put_contents($media . '/oeffentlich/.htaccess', $partBody . "# hand edit\n");
$svc->guardMedia();
check('a changed partition guard is replaced', file_get_contents($media . '/oeffentlich/.htaccess') === $partBody);
$before = $logged();
$svc->guardMedia();
check('identical guards: no write, no sweep, no log', $logged() === $before && tree($media) === sorted(['.htaccess', 'oeffentlich/.htaccess', 'oeffentlich/x.jpg']), json_encode(tree($media)));

echo "(F) hand-placed files and the sweep\n";
rrm($media);
mkdir($media);
file_put_contents($media . '/.htaccess', $oldBase);
$documents->stored = [
    $doc('html', 'html', 'text/html', 2, [], 'seite'),
    $doc('image', 'svg', 'image/svg+xml', 2, [], 'zeichnung'),
    $doc('text', 'xml', 'text/xml', 2, [], 'daten'),
    $doc('html', 'html', 'text/html', 6, [], 'tief'),
    $doc('video', 'mp4', 'video/mp4', 2, ['s' => ['ext' => 'html']], 'film'),
    $doc('html', 'html', 'text/html', 5, [], 'zweiter'),
];
$deleted = $doc('html', 'html', 'text/html', 2, [], 'geloescht');
$deleted->setDeletedAt('2026-01-01 00:00:00');
$documents->stored[] = $deleted;

$handPlaced = ['demo/iso/a.js', 'demo/iso/a.html', 'demo/iso/a.css', 'demo/iso/a.svg', 'demo/iso/b.json', 'hilfe/index.html', 'lose.html'];
$copies     = ['oeffentlich/seite.html', 'oeffentlich/zeichnung.svg', 'oeffentlich/daten.xml', 'oeffentlich/unter/tief.html', 'oeffentlich/film.s.html', 'zweite/zweiter.html'];
$keptFiles  = ['oeffentlich/hand.js', 'oeffentlich/ohne/dokument.html', 'oeffentlich/geloescht.html', 'oeffentlich/a.b.c.html', 'oeffentlich/unter/.htaccess', 'oeffentlich/unter/noextension',
               'zweite/lose.html'];
$safeFiles  = ['oeffentlich/foto.jpg', 'oeffentlich/unter/y.pdf', 'oeffentlich/a.s.webp', 'zweite/foto.png'];
$bytes      = [];
place($media, array_merge($handPlaced, $copies, $keptFiles, $safeFiles));
foreach ($handPlaced as $file) {
    $bytes[$file] = [filemtime("{$media}/{$file}"), file_get_contents("{$media}/{$file}")];
}
$before = $logged();
$svc->guardMedia();
$log = substr($logged(), strlen($before));
$want = sorted(array_merge($handPlaced, $keptFiles, $safeFiles, ['.htaccess', 'oeffentlich/.htaccess', 'zweite/.htaccess']));
check('real copies are removed; the hand-placed tree, kept files and safe files stay', tree($media) === $want,
    json_encode(['extra' => array_values(array_diff(tree($media), $want)), 'missing' => array_values(array_diff($want, tree($media)))]));
foreach ($copies as $file) {
    check("copy {$file} removed", !is_file("{$media}/{$file}"));
}
foreach ($handPlaced as $file) {
    check("hand-placed {$file} untouched", is_file("{$media}/{$file}") && [filemtime("{$media}/{$file}"), file_get_contents("{$media}/{$file}")] === $bytes[$file]);
}
check('the hand-placed tree gets no guard', !is_file($media . '/demo/.htaccess') && !is_file($media . '/demo/iso/.htaccess') && !is_file($media . '/hilfe/.htaccess'));
check('the base guard has no ForceType at the top level', $topLevel((string) file_get_contents($media . '/.htaccess')) === []);
check('a nested .htaccess stays', is_file($media . '/oeffentlich/unter/.htaccess') && file_get_contents($media . '/oeffentlich/unter/.htaccess') === 'content of oeffentlich/unter/.htaccess');
check('the log counts copies and other files apart, per partition',
    preg_match('#oeffentlich/\.htaccess\': 5 copies .*removed, 5 other file\(s\)#', $log) === 1 && preg_match('#zweite/\.htaccess\': 1 copies .*removed, 1 other file\(s\)#', $log) === 1, $log);
check('a partition whose folder slug has no document: the file stays and is logged', is_file($media . '/oeffentlich/ohne/dokument.html') && str_contains($log, 'left in place'));
check('the partition guards are there', file_get_contents($media . '/oeffentlich/.htaccess') === $partBody && file_get_contents($media . '/zweite/.htaccess') === $partBody);
check('a partition without a directory gets none, and no directory', !is_dir($media . '/intern') && !is_dir($media . '/aus'));

$before = $logged();
$treeBefore = tree($media);
$svc->guardMedia();
check('a second run does nothing', tree($media) === $treeBefore && $logged() === $before, substr($logged(), strlen($before)));
place($media, ['oeffentlich/seite.html']);
$svc->guardMedia();
check('... not even with a new copy: the sweep is tied to a written guard', is_file($media . '/oeffentlich/seite.html'));
unlink($media . '/oeffentlich/seite.html');

// Two partitions are independent: only the changed guard is written, only that partition swept.
place($media, ['oeffentlich/seite.html', 'zweite/zweiter.html']);
file_put_contents($media . '/zweite/.htaccess', 'old');
$before = $logged();
$svc->guardMedia();
check('one changed guard: only that partition is swept', !is_file($media . '/zweite/zweiter.html') && is_file($media . '/oeffentlich/seite.html'), json_encode(tree($media)));
check('... and only it is logged', !str_contains(substr($logged(), strlen($before)), 'oeffentlich') && str_contains(substr($logged(), strlen($before)), 'zweite'));
unlink($media . '/oeffentlich/seite.html');

// materialize() looks after its own partition only.
unlink($media . '/zweite/.htaccess');
unlink($media . '/oeffentlich/.htaccess');
place($media, ['oeffentlich/seite.html', 'zweite/zweiter.html']);
$svc->materialize($doc('image', 'jpg', 'image/jpeg', 6));
check('materialize(): writes and sweeps its own partition', is_file($media . '/oeffentlich/.htaccess') && !is_file($media . '/oeffentlich/seite.html'));
check('materialize(): leaves the other one alone', !is_file($media . '/zweite/.htaccess') && is_file($media . '/zweite/zweiter.html'));
check('a new partition gets its guard at its first copy', (static function () use ($svc, $doc, $media): bool {
    $svc->materialize($doc('image', 'png', 'image/png', 5));
    return is_file($media . '/zweite/.htaccess');
})());
$documents->stored = [];

echo "(G) links\n";
rrm($media);
$outside = $work . '/g/outside';
place($outside, ['secret.html', 'sub/deep.svg', 'keep.jpg']);
$documents->stored = [$doc('html', 'html', 'text/html', 2, [], 'seite'), $doc('image', 'svg', 'image/svg+xml', 6, [], 'tief'),
    $doc('html', 'html', 'text/html', 7, [], 'secret')]; // behind the link, a path that WOULD resolve
place($media, ['oeffentlich/seite.html', 'oeffentlich/unter/tief.svg', 'demo/a.js']);
$linked = makeDirLink($outside, $media . '/oeffentlich/out');
check('a directory link exists for the test', $linked);
$loop = makeDirLink($media . '/oeffentlich/unter', $media . '/oeffentlich/unter/back');
check('a link back up the tree exists for the test', $loop);
$svc->guardMedia();
check('a directory link out of the tree is not followed', tree($outside) === ['keep.jpg', 'secret.html', 'sub/deep.svg'], json_encode(tree($outside)));
check('the link itself stays', is_dir($media . '/oeffentlich/out'));
check('the real copies around it are removed, a loop does not hang', !is_file($media . '/oeffentlich/seite.html') && !is_file($media . '/oeffentlich/unter/tief.svg'));
if ($linked) { removeDirLink($media . '/oeffentlich/out'); }
if ($loop)   { removeDirLink($media . '/oeffentlich/unter/back'); }

if (@symlink($outside . '/secret.html', $media . '/oeffentlich/seite.html')) {
    unlink($media . '/oeffentlich/.htaccess');
    $svc->guardMedia();
    clearstatcache(true);
    check('a file link at a document\'s path: the link goes, the target stays', !is_link($media . '/oeffentlich/seite.html') && is_file($outside . '/secret.html'));
} else {
    echo "  skip a file link out of the tree: symlink() is not allowed on this machine (needs privileges)\n";
}

// A partition directory that is itself a link leads out of the tree: no guard, no scan.
rrm($media);
mkdir($media);
place($outside, ['seite.html']);
if (makeDirLink($outside, $media . '/oeffentlich')) {
    $svc->guardMedia();
    check('a partition that is a link: no guard written through it, nothing removed', !is_file($outside . '/.htaccess') && is_file($outside . '/seite.html'), json_encode(tree($outside)));
    removeDirLink($media . '/oeffentlich');
} else {
    check('a partition that is a link: could not create the link', false);
}

// public/media itself as a link into shared/: the deploy layout.
rrm($media);
$shared = $work . '/g/shared/media';
place($shared, ['oeffentlich/seite.html', 'oeffentlich/a.jpg', 'demo/a.html']);
if (makeDirLink($shared, $media)) {
    $svc->guardMedia();
    check('public/media as a link into shared/: guards written there, the copy removed, the hand-placed file kept',
        tree($shared) === sorted(['.htaccess', 'demo/a.html', 'oeffentlich/.htaccess', 'oeffentlich/a.jpg']), json_encode(tree($shared)));
    removeDirLink($media);
} else {
    check('public/media as a link: could not create the link', false);
}
$documents->stored = [];

echo "(H) an unwritable base\n";
rrm($media);
mkdir($media . '/.htaccess', 0777, true); // rename() over a directory fails on every platform
mkdir($media . '/oeffentlich/.htaccess', 0777, true);
$documents->stored = [$doc('html', 'html', 'text/html', 2, [], 'seite')];
place($media, ['oeffentlich/seite.html']);
$before = $logged();
$threw  = null;
try {
    $svc->guardMedia();
} catch (\Throwable $e) {
    $threw = $e;
}
check('guards that cannot be replaced do not throw', $threw === null, (string) $threw);
check('it is logged', substr_count(substr($logged(), strlen($before)), 'cannot write the media guard') === 2, substr($logged(), strlen($before)));
check('no sweep without a guard, no temp file left', tree($media) === ['oeffentlich/seite.html'], json_encode(tree($media)));

// The real thing where the platform lets us: the base refuses writes.
rrm($media);
mkdir($media);
place($media, ['oeffentlich/x.html']);
$locked = DIRECTORY_SEPARATOR === '\\'
    ? (exec('icacls "' . str_replace('/', '\\', $media) . '" /deny "' . getenv('USERNAME') . ':(OI)(CI)(WD,AD)" 2>NUL', $o, $rc) !== false && $rc === 0)
    : chmod($media, 0555) && chmod($media . '/oeffentlich', 0555);
clearstatcache(true);
$canWrite = @file_put_contents($media . '/.probe', 'x') !== false;
if ($canWrite) { @unlink($media . '/.probe'); }
if ($locked && !$canWrite) {
    $threw = null;
    try {
        $svc->guardMedia();
    } catch (\Throwable $e) {
        $threw = $e;
    }
    check('guardMedia() on a base that refuses writes does not throw', $threw === null, (string) $threw);
    check('... and writes nothing', !is_file($media . '/.htaccess') && !is_file($media . '/oeffentlich/.htaccess'));
    // What OutputController does on a public document: copy best-effort, then answer from PHP.
    $pdf = $doc('pdf', 'pdf', 'application/pdf');
    try {
        $svc->materialize($pdf);
    } catch (\RuntimeException $e) {
        error_log('[dms] ' . $e->getMessage());
    }
    check('delivery still answers', $svc->serve($pdf) instanceof FileResponse);
} else {
    echo "  skip a base that really refuses writes: could not lock the directory on this machine\n";
}
if (DIRECTORY_SEPARATOR === '\\') {
    exec('icacls "' . str_replace('/', '\\', $media) . '" /remove:d "' . getenv('USERNAME') . '" 2>NUL');
} else {
    chmod($media, 0777);
    chmod($media . '/oeffentlich', 0777);
}
clearstatcache(true);
$documents->stored = [];

echo "(I) the triggers\n";
rrm($media);
$svc->materialize($doc('image', 'jpg', 'image/jpeg'));
check('materialize() writes both', @file_get_contents($media . '/.htaccess') === $baseBody && @file_get_contents($media . '/oeffentlich/.htaccess') === $partBody);
file_put_contents($media . '/.htaccess', $oldBase);
$svc->materialize($doc('image', 'jpg', 'image/jpeg'));
check('materialize() replaces an old base guard', file_get_contents($media . '/.htaccess') === $baseBody);
$ui     = __DIR__ . '/../packages/module-dms/src/Ui/';
$output = (string) file_get_contents($ui . 'Controllers/Media/OutputController.php');
check('OutputController calls guardMedia() before any 404', ($g = strpos($output, '->guardMedia()')) !== false && $g < strpos($output, 'throw new NotFoundException'));
$drive = (string) file_get_contents($ui . 'DriveControllerTrait.php');
preg_match('/function listAction\(\): HtmlResponse\s*\{(.*?)\n    \}/s', $drive, $m);
check("the Drive's list action calls guardMedia()", str_contains($m[1] ?? '', '->guardMedia()'));
check('the stored MIME and the kind stay: serve() still reports the document', (new ReflectionProperty(FileResponse::class, 'mimeType'))->getValue($svc->serve($doc('html', 'html', 'text/html'))) === 'text/html');
check('and the policy agrees: an inline binary is what the copy gate asks for', DeliveryPolicy::isInlineBinary('image/jpeg') && !DeliveryPolicy::isInlineBinary('text/plain') && !DeliveryPolicy::isInlineBinary('application/json') && !DeliveryPolicy::isInlineBinary('image/svg+xml'));

rrm($work);

echo "\n{$pass} ok, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
