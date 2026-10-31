<?php

/**
 * DMS chunked upload harness (CLI, offline, no DI) — F3, z77-rudaz.ch 2026-10-31.
 *
 * A file of several GB goes up in chunks (`ChunkedUploadService`, endpoints
 * `ChunkedUploadTrait`, client `documents/chunk-upload.js`). What is load-bearing:
 *
 *   (A) the chunk size fits through one request of THIS server (clamp), names are cleaned;
 *   (B) begin / append (any order) / status / finish assemble EXACTLY the sent bytes and
 *       hand them to the store as one file; the upload directory is gone afterwards;
 *   (C) every chunk has its exact size (the last the rest), the index is in range,
 *       a re-sent index is idempotent;
 *   (D) finish refuses a missing chunk and a size mismatch — and keeps the upload;
 *   (E) only the identity that began it reaches it; the folder gate runs at begin AND
 *       again at append / finish; nobody logged in cannot begin;
 *   (F) begin refuses what is certain from the name/size: executable extension, empty,
 *       over `maxUploadBytes`, unusable name;
 *   (G) finish runs THE pipeline's classification (UploadService::classify, real code):
 *       an `extraUploadTypes` LAZ becomes kind `file`; without the entry it is refused and
 *       the upload removed;
 *   (H) a name conflict keeps the bytes for «überschreiben» (finish again, overwrite) —
 *       a duplicate removes them; abort removes them (owner only);
 *   (I) cleanup removes only abandoned uploads (and only upload directories), begin
 *       sweeps them too; the job is registered without a schedule;
 *   (J) wiring: one pipeline in UploadService (save + saveAssembled), the assembled file
 *       is RENAMED into the blob (LocalBlobStorage, real), endpoints are #[Fetch], the
 *       Drive mounts the trait, the client sends the CSRF header and builds no HTML.
 *
 * Run: php tests/dms-chunked-upload.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "dms-chunked-upload: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Z77\Core\Exception\NotFoundException;
use Z77\Module\Dms\Blob\BlobStorage;
use Z77\Module\Dms\Blob\LocalBlobStorage;
use Z77\Module\Dms\Entities\Document;
use Z77\Module\Dms\Images\DocumentKind;
use Z77\Module\Dms\Images\UploadTypes;
use Z77\Module\Dms\Services\ChunkedUploadService;
use Z77\Module\Dms\Services\ChunkUploadException;
use Z77\Module\Dms\Services\DuplicateUploadException;
use Z77\Module\Dms\Services\NameConflictException;
use Z77\Module\Dms\Services\UploadService;
use Z77\Shared\ValueObjects\UploadedFile;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

/** The exception a call throws (class + code), or null. */
function thrown(callable $fn): ?Throwable
{
    try { $fn(); } catch (Throwable $e) { return $e; }
    return null;
}

function refused(callable $fn, int $code): bool
{
    $e = thrown($fn);
    return $e instanceof ChunkUploadException && $e->getCode() === $code;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-chunk-' . getmypid();
rrmdir($root);
mkdir($root . '/chunks', 0777, true);
mkdir($root . '/in', 0777, true);
mkdir($root . '/sink', 0777, true);
define('ABS_BASE_PATH', $root); // LocalBlobStorage (J) — nothing else reads it here

// ── fakes: gate, owner, store ──────────────────────────────────────────────────
$writable = [7];          // folders the session principal may write
$who      = 'backend:1';  // the session identity
$stored   = [];           // what the store received
$storeMode = 'ok';        // ok | conflict | duplicate

// The REAL classification of the upload pipeline (private UploadService::classify).
$pipeline = static function (UploadTypes $types): Closure {
    $svc = (new ReflectionClass(UploadService::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(UploadService::class, 'types'))->setValue($svc, $types);
    $classify = new ReflectionMethod(UploadService::class, 'classify');
    return static fn (UploadedFile $f): array => $classify->invoke($svc, $f);
};

$makeService = static function (UploadTypes $types = new UploadTypes(), int $chunk = 1000, ?int $max = null) use ($root, &$writable, &$who, &$stored, &$storeMode, $pipeline): ChunkedUploadService {
    $classify = $pipeline($types);
    return new ChunkedUploadService(
        $root . '/chunks',
        $chunk,
        $max,
        $types,
        static function (int $folderId) use (&$writable): void {
            if (!in_array($folderId, $writable, true)) {
                throw new NotFoundException('Nicht gefunden.');
            }
        },
        static function () use (&$who): string { return $who; },
        static function (UploadedFile $file, int $folderId, bool $overwrite, bool $showOriginal, ?UploadedFile $poster) use (&$stored, &$storeMode, $classify, $root): Document {
            [$kind, $mime] = $classify($file); // throws for a refused type — like the pipeline
            if ($storeMode === 'duplicate') {
                throw new DuplicateUploadException('identisch');
            }
            if ($storeMode === 'conflict' && !$overwrite) {
                throw new NameConflictException('Name vergeben');
            }
            $stored[] = [
                'name' => $file->originalName, 'size' => $file->size, 'sha' => $file->sha256(),
                'folder' => $folderId, 'overwrite' => $overwrite, 'showOriginal' => $showOriginal,
                'kind' => $kind, 'mime' => $mime,
            ];
            rename($file->tmpPath, $root . '/sink/' . count($stored)); // the blob move
            $doc = new Document();
            (new ReflectionProperty(Document::class, 'id'))->setValue($doc, count($stored));
            $doc->setOriginalName($file->originalName);
            return $doc;
        },
    );
};

/** A chunk temp file with these bytes. */
$chunkOf = static function (string $bytes) use ($root): UploadedFile {
    $path = tempnam($root . '/in', 'c');
    file_put_contents($path, $bytes);
    return new UploadedFile('blob', $path, strlen($bytes));
};
/** [chunk, sha256] as the browser sends them — spread into append(). */
$chunk = static fn (string $bytes): array => [$chunkOf($bytes), hash('sha256', $bytes)];
$dirOf = static fn (string $id): string => $root . '/chunks/' . $id;

echo "(A) chunk size and names\n";
check('configured chunk fits → as configured (8 MB under 500 MB)', ChunkedUploadService::effectiveChunkBytes(8 << 20, 500 << 20) === 8 << 20);
check('unlimited PHP cap → as configured', ChunkedUploadService::effectiveChunkBytes(8 << 20, PHP_INT_MAX) === 8 << 20);
check('chunk above the cap → cap minus 1 MB head-room (600 MB under 500 MB)', ChunkedUploadService::effectiveChunkBytes(600 << 20, 500 << 20) === 499 << 20);
check('small cap → a quarter head-room (2 MB → 1.5 MB)', ChunkedUploadService::effectiveChunkBytes(8 << 20, 2 << 20) === (3 << 19));
check('never below 64 KB', ChunkedUploadService::effectiveChunkBytes(8 << 20, 10_000) === 65536);
check('0 configured → the default', ChunkedUploadService::effectiveChunkBytes(0, PHP_INT_MAX) === ChunkedUploadService::DEFAULT_CHUNK_BYTES);
check('name: path and control characters stripped',
    ChunkedUploadService::cleanName("C:\\Users\\x\\Scan\x07 1.laz") === 'Scan 1.laz' && ChunkedUploadService::cleanName('../../etc/passwd') === 'passwd');
check('name: empty, dot, dot-dot, too long → unusable',
    ChunkedUploadService::cleanName('  ') === '' && ChunkedUploadService::cleanName('a/..') === '' && ChunkedUploadService::cleanName(str_repeat('a', 256)) === '');

echo "(B) happy path, any order\n";
$svc   = $makeService();
$bytes = "%PDF-1.4\n" . random_bytes(2491);      // sniffs application/pdf; chunks 1000, 1000, 500
$id    = $svc->begin(7, 'Plan Max Muster.pdf', strlen($bytes), false, true);
check('begin → 32 hex id, directory outside any web root', preg_match('/^[a-f0-9]{32}$/', $id) === 1 && is_dir($dirOf($id)));
$manifest = (string) file_get_contents($dirOf($id) . '/manifest.json');
check('manifest holds the owner as a hash, not the id', !str_contains($manifest, 'backend:1') && str_contains($manifest, hash('sha256', 'backend:1')));
check('append index 2 (last, 500 bytes) first', $svc->append($id, 2, ...$chunk(substr($bytes, 2000))) === 1);
check('append index 0', $svc->append($id, 0, ...$chunk(substr($bytes, 0, 1000))) === 2);
$st = $svc->status($id);
check('status: received [0, 2], chunks 3, chunk size 1000', $st['received'] === [0, 2] && $st['chunks'] === 3 && $st['chunkBytes'] === 1000 && $st['size'] === 2500, json_encode($st));
check('resume: the missing index 1 goes', $svc->append($id, 1, ...$chunk(substr($bytes, 1000, 1000))) === 3);
$doc = $svc->finish($id);
$got = end($stored);
check('finish → the store got exactly the bytes (sha256), size, name, folder, flags',
    $got['sha'] === hash('sha256', $bytes) && $got['size'] === 2500 && $got['name'] === 'Plan Max Muster.pdf'
    && $got['folder'] === 7 && $got['overwrite'] === false && $got['showOriginal'] === true, json_encode($got));
check('finish returns the document', $doc instanceof Document && $doc->getId() === count($stored));
check('the upload directory is gone', !is_dir($dirOf($id)));
check('a second finish → not found', refused(fn () => $svc->finish($id), 404));

echo "(C) chunk sizes and indices\n";
$id = $svc->begin(7, 'x.pdf', 2500);
check('a short middle chunk is refused', refused(fn () => $svc->append($id, 0, ...$chunk(str_repeat('a', 999))), 400));
check('a long last chunk is refused',   refused(fn () => $svc->append($id, 2, ...$chunk(str_repeat('a', 501))), 400));
check('index out of range is refused',  refused(fn () => $svc->append($id, 3, ...$chunk(str_repeat('a', 500))), 400) && refused(fn () => $svc->append($id, -1, ...$chunk('a')), 400));
check('nothing was recorded', $svc->status($id)['received'] === []);
$svc->append($id, 1, ...$chunk(str_repeat('b', 1000)));
check('a re-sent index is idempotent', $svc->append($id, 1, ...$chunk(str_repeat('c', 1000))) === 1 && $svc->status($id)['received'] === [1]);
check('integrity: bytes that differ from the browser\'s hash → refused with retry (422), nothing recorded',
    ($e = thrown(fn () => $svc->append($id, 0, $chunkOf(str_repeat('x', 1000)), hash('sha256', str_repeat('y', 1000)))))
        instanceof ChunkUploadException && $e->getCode() === 422 && $e->retry === true && $svc->status($id)['received'] === [1]);
check('integrity: a missing or malformed hash → refused, no retry',
    ($e = thrown(fn () => $svc->append($id, 0, $chunkOf(str_repeat('x', 1000)), ''))) instanceof ChunkUploadException && $e->getCode() === 400 && $e->retry === false
    && refused(fn () => $svc->append($id, 0, $chunkOf(str_repeat('x', 1000)), 'zz'), 400));
check('integrity: upper-case hex is accepted', $svc->append($id, 0, $chunkOf(str_repeat('x', 1000)), strtoupper(hash('sha256', str_repeat('x', 1000)))) === 2);
check('a malformed upload id never reaches a path', refused(fn () => $svc->status('../../etc'), 404) && refused(fn () => $svc->status(str_repeat('g', 32)), 404));

echo "(D) incomplete and mismatched\n";
check('finish with a missing chunk → refused (409), upload kept', refused(fn () => $svc->finish($id), 409) && is_dir($dirOf($id)));
$svc->append($id, 0, ...$chunk(str_repeat('a', 1000)));
$svc->append($id, 2, ...$chunk(str_repeat('d', 500)));
file_put_contents($dirOf($id) . '/data.bin', 'xx', FILE_APPEND); // tampered: longer than declared
check('finish with a size mismatch → refused (409), upload kept', refused(fn () => $svc->finish($id), 409) && is_dir($dirOf($id)));
$svc->abort($id);

echo "(E) owner and gate\n";
$id = $svc->begin(7, 'y.pdf', 1500);
$who = 'member:m-1';
check('another identity: append / status / finish / abort → not found',
    refused(fn () => $svc->append($id, 0, ...$chunk(str_repeat('a', 1000))), 404) && refused(fn () => $svc->status($id), 404)
    && refused(fn () => $svc->finish($id), 404) && refused(fn () => $svc->abort($id), 404) && is_dir($dirOf($id)));
$who = 'member:1';
check('a member with the same id as a backend user is another identity', refused(fn () => $svc->status($id), 404));
$who = 'backend:1';
$writable = [];
check('write revoked midway → append stops at the gate', thrown(fn () => $svc->append($id, 0, ...$chunk(str_repeat('a', 1000)))) instanceof NotFoundException);
check('… and finish too', thrown(fn () => $svc->finish($id)) instanceof NotFoundException);
check('begin without write → the gate, before anything is written', thrown(fn () => $svc->begin(7, 'z.pdf', 10)) instanceof NotFoundException);
$writable = [7];
check('begin into another folder → the gate', thrown(fn () => $svc->begin(8, 'z.pdf', 10)) instanceof NotFoundException);
$who = '';
check('nobody logged in → begin refused (403)', refused(fn () => $svc->begin(7, 'z.pdf', 10), 403));
$who = 'backend:1';
$svc->abort($id);

echo "(F) certain refusals at begin\n";
check('executable extension refused (shell.php, X.PHTML, .htaccess)',
    refused(fn () => $svc->begin(7, 'shell.php', 10), 415) && refused(fn () => $svc->begin(7, 'X.PHTML', 10), 415) && refused(fn () => $svc->begin(7, '.htaccess', 10), 415));
check('empty file refused', refused(fn () => $svc->begin(7, 'a.pdf', 0), 400));
check('unusable name refused', refused(fn () => $svc->begin(7, '../', 10), 400));
$capped = $makeService(new UploadTypes(), 1000, 5000);
check('over maxUploadBytes refused (413), at the cap accepted',
    refused(fn () => $capped->begin(7, 'a.pdf', 5001), 413) && is_string($capped->begin(7, 'a.pdf', 5000)));
check('no maxUploadBytes = no cap of its own', $svc->maxBytes() === null && $capped->maxBytes() === 5000);

echo "(G) the pipeline's classification at finish\n";
$laz     = random_bytes(1800);
$lazSvc  = $makeService(new UploadTypes(['laz']));
$id      = $lazSvc->begin(7, 'Punktwolke.laz', 1800);
$lazSvc->append($id, 0, ...$chunk(substr($laz, 0, 1000)));
$lazSvc->append($id, 1, ...$chunk(substr($laz, 1000)));
$lazSvc->finish($id);
$got = end($stored);
check('extraUploadTypes laz → kind file, stored octet-stream, exact bytes',
    $got['kind'] === DocumentKind::File && $got['mime'] === 'application/octet-stream' && $got['sha'] === hash('sha256', $laz), json_encode([$got['kind']->value ?? null, $got['mime']]));
$id = $svc->begin(7, 'Punktwolke.laz', 1800);
$svc->append($id, 0, ...$chunk(substr($laz, 0, 1000)));
$svc->append($id, 1, ...$chunk(substr($laz, 1000)));
$e = thrown(fn () => $svc->finish($id));
check('without the entry the same file is refused by the pipeline', $e instanceof RuntimeException && str_contains($e->getMessage(), 'is not allowed'), $e ? $e->getMessage() : 'nothing thrown');
check('… and the refused upload is removed', !is_dir($dirOf($id)));

echo "(H) conflict, duplicate, abort\n";
$doc1 = str_repeat('Max Muster, example.ch. ', 50); // 1200 bytes of text/plain
$id   = $svc->begin(7, 'Bericht.txt', 1200);
$svc->append($id, 0, ...$chunk(substr($doc1, 0, 1000)));
$svc->append($id, 1, ...$chunk(substr($doc1, 1000)));
$storeMode = 'conflict';
$before    = count($stored);
check('name conflict → NameConflictException, bytes kept', thrown(fn () => $svc->finish($id)) instanceof NameConflictException && is_file($dirOf($id) . '/data.bin'));
$svc->finish($id, true);
check('finish again with overwrite → stored with overwrite, no chunk re-sent, upload gone',
    count($stored) === $before + 1 && end($stored)['overwrite'] === true && end($stored)['sha'] === hash('sha256', $doc1) && !is_dir($dirOf($id)));
$storeMode = 'duplicate';
$id = $svc->begin(7, 'Bericht.txt', 1200);
$svc->append($id, 0, ...$chunk(substr($doc1, 0, 1000)));
$svc->append($id, 1, ...$chunk(substr($doc1, 1000)));
check('duplicate → DuplicateUploadException, upload removed', thrown(fn () => $svc->finish($id)) instanceof DuplicateUploadException && !is_dir($dirOf($id)));
$storeMode = 'ok';
$id = $svc->begin(7, 'weg.pdf', 10);
$svc->abort($id);
check('abort removes the upload', !is_dir($dirOf($id)));

echo "(I) cleanup and job\n";
$old  = $svc->begin(7, 'alt.pdf', 10);
$new  = $svc->begin(7, 'neu.pdf', 10);
touch($dirOf($old) . '/manifest.json', time() - 90000);
mkdir($root . '/chunks/keep-me');
check('cleanup removes only the abandoned upload', $svc->cleanup(86400) === 1 && !is_dir($dirOf($old)) && is_dir($dirOf($new)));
check('… and never a directory that is not an upload', is_dir($root . '/chunks/keep-me'));
touch($dirOf($new) . '/manifest.json', time() - 90000);
$svc->begin(7, 'next.pdf', 10);
check('begin sweeps abandoned uploads too', !is_dir($dirOf($new)));
$cfg = require __DIR__ . '/../packages/module-dms/src/App/Config/dmsConfig.inc.php';
$job = $cfg['jobs']['dms-chunk-cleanup'] ?? [];
check('job registered: class, label, no defaultSchedule (it deletes — jobs.md rule)',
    ($job['class'] ?? '') === \Z77\Module\Dms\Jobs\ChunkCleanupJob::class && ($job['label'] ?? '') === 'DMS-Upload-Reste'
    && !isset($job['defaultSchedule']) && class_exists($job['class']));
check('config: chunkBytes 8 MB, maxUploadBytes null', ($cfg['chunkBytes'] ?? 0) === 8 * 1024 * 1024 && array_key_exists('maxUploadBytes', $cfg) && $cfg['maxUploadBytes'] === null);

echo "(J) wiring\n";
$src = static fn (string $rel): string => (string) file_get_contents(__DIR__ . '/../' . $rel);
$upload = $src('packages/module-dms/src/Services/UploadService.php');
check('UploadService: save and saveAssembled share ONE pipeline (store), the assembled file is not an HTTP upload',
    substr_count($upload, 'return $this->store(') === 2 && str_contains($upload, '$overwrite, $poster, true);') && str_contains($upload, '$overwrite, $poster, false);')
    && substr_count($upload, '->kindFor(') === 1);
$save = $src('packages/module-dms/src/Services/SaveService.php');
check('SaveService passes the flag to the blob move (both paths)', substr_count($save, '$file->tmpPath, $isHttpUpload)') === 2);

$tmp = $root . '/in/assembled.bin';
file_put_contents($tmp, 'assembled bytes');
$blob = new LocalBlobStorage('blobs-test');
$blob->putFile(42, BlobStorage::ORIGINAL, 'laz', $tmp, false);
check('LocalBlobStorage renames a non-upload file into the blob', !is_file($tmp) && $blob->get(42, BlobStorage::ORIGINAL, 'laz') === 'assembled bytes');

$trait = $src('packages/module-dms/src/Ui/ChunkedUploadTrait.php');
$fetchActions = preg_match_all('/#\[Fetch[^\]]*\]\s+protected function chunk\w+Action\(\): JsonResponse/', $trait);
check('five endpoints, all #[Fetch], all JSON', $fetchActions === 5, (string) $fetchActions);
check('input only through the Request (no superglobals)', !preg_match('/\$_(POST|GET|FILES|SERVER)/', $trait));
check('the Drive mounts the trait and refreshes its panes', str_contains($src('packages/module-dms/src/Ui/DriveControllerTrait.php'), 'use ChunkedUploadTrait;')
    && str_contains($src('packages/module-dms/src/Ui/DriveControllerTrait.php'), 'function chunkUploadCommands('));

$js = $src('packages/module-dms/res/assets/js/documents/chunk-upload.js');
check('client: CSRF header, no HTML insertion, no storage, no eval',
    str_contains($js, "'X-CSRF-Token'") && !preg_match('/innerHTML|outerHTML|insertAdjacentHTML|localStorage|sessionStorage|eval\(/', $js));
check('client: registers the dms-chunk transport, the upload component offers transports',
    str_contains($js, "transport('dms-chunk'") && str_contains($src('packages/kernel/shared/res/assets/js/upload.js'), 'function transport(name, impl)'));
check('client: .min.js present', is_file(__DIR__ . '/../packages/module-dms/res/assets/js/documents/chunk-upload.min.js'));

rrmdir($root);

echo "\n{$pass} ok, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
