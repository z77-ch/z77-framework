<?php

/**
 * DMS upload types harness (CLI, offline, no DI) — F4, z77-rudaz.ch 2026-10-31.
 *
 * `dmsConfig['extraUploadTypes']` lets an installation accept files `finfo` cannot name
 * (LAZ, LAS, E57, IFC, …) by extension. What is load-bearing:
 *
 *   (A) `UploadTypes` normalizes the config: list and map, case/dot, malformed MIME →
 *       octet-stream, and server-executable / page types are never listed;
 *   (B) WITHOUT an entry the allowlist is exactly `DocumentKind::fromMime()` — every
 *       installation without the key behaves as before (axo3, zihlundsee) — except a
 *       server-executable extension, refused everywhere since DMS-MEDIA-PHP-001;
 *   (C) WITH an entry a listed extension wins over the sniff → kind `file`, stored MIME
 *       from the list or octet-stream; an unlisted unknown type is still refused;
 *   (D) kind `file` is never previewable, has no variants, no poster, always downloads;
 *       no other kind changes its delivery;
 *   (E) `DocumentService::serve()` answers a `file` document as an attachment even when
 *       inline was asked for (the /media path), other kinds stay inline; `materialize()`
 *       never writes a static copy of a `file` document;
 *   (F) Upload/SaveService default to an EMPTY list when built without config;
 *   (G) DMS-MEDIA-PHP-001: executable extensions are refused whatever the sniff, a
 *       stored one is never copied into the docroot, and the media guard `.htaccess`
 *       takes the script handler away from exactly those extensions.
 *
 * Run: php tests/dms-upload-types.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "dms-upload-types: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Z77\Core\Http\Response\FileResponse;
use Z77\Module\Dms\Blob\BlobStorage;
use Z77\Module\Dms\Entities\Document;
use Z77\Module\Dms\Images\DocumentKind;
use Z77\Module\Dms\Images\UploadTypes;
use Z77\Module\Dms\Services\DocumentService;
use Z77\Module\Dms\Services\SaveService;
use Z77\Module\Dms\Services\UploadService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

echo "(A) normalize\n";
$types = new UploadTypes(['laz', '.LAS', 'e57', 'ifc' => 'Application/X-Step', 'xml', 'bad' => 'not a mime', 'php', 'html' => 'text/html', 'SVG', 'a b', '', 42, 'toolongextension17']);
check('list + map + mixed; case and dot folded; malformed MIME → octet-stream; refused and malformed dropped',
    $types->all() === ['laz' => 'application/octet-stream', 'las' => 'application/octet-stream', 'e57' => 'application/octet-stream',
                       'ifc' => 'application/x-step', 'xml' => 'application/octet-stream', 'bad' => 'application/octet-stream'],
    json_encode($types->all()));
check('empty config → empty list', (new UploadTypes())->all() === [] && (new UploadTypes([]))->all() === []);

echo "(B) no entry = exactly fromMime (executables aside, see G)\n";
$samples = [
    ['image/jpeg', 'jpg'], ['image/svg+xml', 'svg'], ['video/mp4', 'mp4'], ['audio/mpeg', 'mp3'], ['application/pdf', 'pdf'],
    ['application/msword', 'doc'], ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
    ['application/json', 'json'], ['text/plain', 'json'], ['text/plain', 'html'], ['text/html', 'html'], ['text/plain', 'ifc'],
    ['text/xml', 'xml'], ['application/xml', 'xml'], ['application/zip', 'zip'], ['application/octet-stream', 'laz'],
    ['application/octet-stream', 'e57'], ['application/x-dosexec', 'exe'], ['text/x-php', 'php'], ['', 'laz'], ['application/pdf', null],
];
$empty = new UploadTypes();
$same  = true;
foreach ($samples as [$mime, $ext]) {
    $expected = UploadTypes::isExecutable($ext) ? null : DocumentKind::fromMime($mime, $ext);
    if ($empty->kindFor($mime, $ext) !== $expected || $empty->storedMime($mime, $ext) !== $mime) {
        $same = false;
        echo "       differs: {$mime} / {$ext}\n";
    }
}
check('kind and stored MIME identical to before for ' . count($samples) . ' samples', $same);
check('LAZ without entry is still refused', $empty->kindFor('application/octet-stream', 'laz') === null);

echo "(C) with entry: the extension wins\n";
check('LAZ (octet-stream) → file, stored octet-stream',
    $types->kindFor('application/octet-stream', 'laz') === DocumentKind::File && $types->storedMime('application/octet-stream', 'laz') === 'application/octet-stream');
check('IFC sniffed text/plain → file (no longer Text), stored the listed MIME',
    $types->kindFor('text/plain', 'ifc') === DocumentKind::File && $types->storedMime('text/plain', 'ifc') === 'application/x-step');
check('XML sniffed text/xml → file, stored octet-stream (never the sniff)',
    $types->kindFor('text/xml', 'xml') === DocumentKind::File && $types->storedMime('text/xml', 'xml') === 'application/octet-stream');
check('extension case-insensitive (.LAZ)', $types->kindFor('application/octet-stream', 'LAZ') === DocumentKind::File);
check('unlisted unknown type still refused (exe)', $types->kindFor('application/x-dosexec', 'exe') === null);
check('unlisted known types unchanged (jpg, pdf, txt)',
    $types->kindFor('image/jpeg', 'jpg') === DocumentKind::Image && $types->kindFor('application/pdf', 'pdf') === DocumentKind::Pdf
    && $types->kindFor('text/plain', 'txt') === DocumentKind::Text && $types->storedMime('image/jpeg', 'jpg') === 'image/jpeg');
check('a page type on the list keeps its normal classification (html → html), an executable stays refused (php)',
    $types->kindFor('text/x-php', 'php') === null && $types->kindFor('text/html', 'html') === DocumentKind::Html);
check('no extension → fromMime', $types->kindFor('application/octet-stream', null) === null);

echo "(D) kind file\n";
$f = DocumentKind::File;
check('file: not previewable, no variants, no poster, always a download',
    !$f->previewable() && !$f->hasImageVariants() && !$f->acceptsPoster() && $f->alwaysDownload() && $f->value === 'file');
check('no other kind downloads by force',
    array_filter(DocumentKind::cases(), fn (DocumentKind $k) => $k !== DocumentKind::File && $k->alwaysDownload()) === []);
check('fromMime never yields file', DocumentKind::fromMime('application/octet-stream', 'laz') === null);

echo "(E) delivery\n";
$tmp = tempnam(sys_get_temp_dir(), 'z77ft');
file_put_contents($tmp, 'bytes');
$blob = new class ($tmp) implements BlobStorage {
    public int $pathCalls = 0;
    public function __construct(private string $file) {}
    public function put(int $id, string $variant, string $ext, string $bytes): void {}
    public function putFile(int $id, string $variant, string $ext, string $sourcePath, bool $isUpload = true): void {}
    public function get(int $id, string $variant, string $ext): ?string { return 'bytes'; }
    public function path(int $id, string $variant, string $ext): ?string { $this->pathCalls++; return $this->file; }
    public function exists(int $id, string $variant, string $ext): bool { return true; }
    public function size(int $id, string $variant, string $ext): ?int { return 5; }
    public function delete(int $id): void {}
};
$svc = (new ReflectionClass(DocumentService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(DocumentService::class, 'blob'))->setValue($svc, $blob);

$doc = static function (string $kind, string $ext, string $mime): Document {
    $d = new Document();
    (new ReflectionProperty(Document::class, 'id'))->setValue($d, 9);
    $d->setOriginalName("scan.{$ext}");
    $d->setExt($ext);
    $d->setMimeType($mime);
    $d->setKind($kind);
    $d->setChecksum(str_repeat('a', 64));
    return $d;
};
$disposition = static fn (FileResponse $r): string => (string) (new ReflectionProperty(FileResponse::class, 'disposition'))->getValue($r);

$laz = $doc('file', 'laz', 'application/octet-stream');
check('file document asked inline (the /media path) → attachment', $disposition($svc->serve($laz, null, false)) === FileResponse::ATTACHMENT);
check('pdf asked inline → still inline', $disposition($svc->serve($doc('pdf', 'pdf', 'application/pdf'), null, false)) === FileResponse::INLINE);
check('pdf download → attachment', $disposition($svc->serve($doc('pdf', 'pdf', 'application/pdf'), null, true)) === FileResponse::ATTACHMENT);
$blob->pathCalls = 0;
$svc->materialize($laz);
check('materialize() never writes a static copy of a file document', $blob->pathCalls === 0);
@unlink($tmp);

echo "(F) defaults without config\n";
$default = static function (string $class): ?array {
    foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $p) {
        if ($p->getName() === 'types') {
            $v = $p->getDefaultValue();
            return $v instanceof UploadTypes ? $v->all() : null;
        }
    }
    return null;
};
check('SaveService: types default to an empty list', $default(SaveService::class) === []);
check('UploadService: types default to an empty list', $default(UploadService::class) === []);

echo "(G) DMS-MEDIA-PHP-001\n";
$execRefused = true;
foreach (['php', 'PHP', '.phtml', 'phar', 'php8', 'shtml', 'cgi', 'htaccess'] as $ext) {
    $execRefused = $execRefused && $empty->kindFor('text/x-php', $ext) === null && $empty->kindFor('text/plain', $ext) === null;
}
check('executable extensions refused whatever the sniff (php, PHP, .phtml, phar, php8, shtml, cgi, htaccess)', $execRefused);
check('a double extension is judged by its last part (shell.php.txt → text)', $empty->kindFor('text/x-php', 'txt') === DocumentKind::Text);
check('ordinary types are not executable', !UploadTypes::isExecutable('jpg') && !UploadTypes::isExecutable('pdf') && !UploadTypes::isExecutable(null));

$blob->pathCalls = 0;
$svc->materialize($doc('text', 'php', 'text/x-php')); // stored before the refusal
check('materialize() never copies a stored .php into the docroot', $blob->pathCalls === 0);

$guardDir = sys_get_temp_dir() . '/z77-media-guard-' . getmypid();
@mkdir($guardDir);
(new ReflectionMethod(DocumentService::class, 'ensureMediaGuard'))->invoke(null, $guardDir);
$guard = (string) @file_get_contents($guardDir . '/.htaccess');
check('media guard written: handler off, plain text, no deny',
    str_contains($guard, 'SetHandler none') && str_contains($guard, 'ForceType text/plain') && !str_contains($guard, 'Require all denied'), $guard);
preg_match('/<FilesMatch "(.+)">/', $guard, $m);
$rx = isset($m[1]) ? '/' . $m[1] . '/' : '/^$/';
check('guard matches exactly the executable extensions',
    preg_match($rx, 'x.php') === 1 && preg_match($rx, 'x.phtml') === 1 && preg_match($rx, 'x.shtml') === 1
    && preg_match($rx, 'x.jpg') === 0 && preg_match($rx, 'x.php.jpg') === 0 && preg_match($rx, 'x.pdf') === 0, $m[1] ?? '');
file_put_contents($guardDir . '/.htaccess', 'custom');
(new ReflectionMethod(DocumentService::class, 'ensureMediaGuard'))->invoke(null, $guardDir);
check('an existing guard is left alone', file_get_contents($guardDir . '/.htaccess') === 'custom');
@unlink($guardDir . '/.htaccess');
@rmdir($guardDir);
(new ReflectionMethod(DocumentService::class, 'ensureMediaGuard'))->invoke(null, $guardDir);
check('no media directory → nothing written, no directory created', !is_dir($guardDir));
$src = (string) file_get_contents(__DIR__ . '/../packages/module-dms/src/Ui/Controllers/Media/OutputController.php');
check('every /media request reaching PHP calls guardMedia() before any 404',
    ($g = strpos($src, '->guardMedia()')) !== false && $g < strpos($src, "throw new NotFoundException"));

echo "\n{$pass} ok, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
