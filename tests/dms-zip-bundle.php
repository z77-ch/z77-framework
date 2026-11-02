<?php

/**
 * ZIP bundle harness (CLI, offline, no database) — DMS-ZIP-001, phases Z2 to Z4.
 *
 * A bundle is a personal manifest; a job computes the CRCs and each byte range is answered from
 * the original blobs (`docs/03-development/dms-zip-download-bauplan.md`). What is load-bearing:
 *
 *   (A) opening: `read` per document, what is denied or unknown is counted and never named, a
 *       readable document without its original is skipped and named, the id is random, nothing
 *       but the manifest lands on disk, the owner and the address are not readable in the view;
 *   (B) limits: BULK_MAX (the Drive's own value), three unfinished per person, an instant or a
 *       ready bundle does not count, another person's neither;
 *   (C) owner binding: another identity gets a 404 on everything, a super-user too;
 *   (D) job slices: resume mid-file through the manifest, in ANOTHER PROCESS, the result equals
 *       `hash_file`; the job answers `again` and `done`; a file changed or gone during the count
 *       ends the bundle as failed, a bundle deleted during the count just stops;
 *   (E) a damaged hash context restarts that file only, and is never anything but a HashContext;
 *   (F) instant path: below the threshold ready on return, no job; at the threshold the job;
 *   (G) mail: on request, once, the address leaves the manifest, a failure is recorded and the
 *       bundle stays usable, an interrupted send is not repeated; the message names no document
 *       and its link carries no id;
 *   (H) the gate per piece: right withdrawn, document deleted, document replaced; the piece that
 *       does not touch it passes, the first piece and the central directory check everything;
 *   (I) expiry by the gate, whether a job runs or not; delete; sweep (locked, foreign, broken);
 *       every new bundle sweeps; the jobs are registered, no schedule; the config;
 *   (J) the backup excludes `dms/zips` for real (ZipArchiver) and in the full archive;
 *   (K) a REAL ranged answer through PHP's built-in server, fetched the way chunk-download.js
 *       fetches it (explicit ranges, 206, ETag, Content-Range), the assembled file opened by
 *       libzip; 200 / 304 / 416 / HEAD; a piece refused mid-download; a source failing while
 *       streaming ends the body short, with no error page inside the promised length; the cap;
 *   (L) the production wiring on a temp installation: `create()` with the real Authz, repository,
 *       JobQueue, module registry, template lookup and mail transport; the real jobs;
 *   (M) the whole stack: a real login, the Drive's endpoints through the real Bootstrap,
 *       Dispatcher, role and CSRF gates; the file assembled from there opened by libzip;
 *   (N) source guards: the trait is thin, input through Request, the response through the service;
 *   (O) host pages: the extra gate at creation and on a piece, the started hook once across
 *       retries and real concurrent processes, a failing hook; the purges with no trace left;
 *       `list()`; the failed mail; mail settings; neutral wording.
 *   (P) concurrency and limits, with real processes: a job never touches what a request prepares
 *       or a bundle that got ready after its scan; a request that dies is adopted; the caps hold
 *       at the same moment; a pile of 1500 full manifests under a small memory limit; atomic
 *       writes, a failing write stops only its own bundle; no mail for an instant bundle.
 *   (Q) recovery and clean-up: leftovers count for nobody and go, a bundle without a live holder
 *       gets its job back whatever asks, a refused opening included; the started hook cannot break
 *       a download; a clock that was ahead; the daily clean-up and what it leaves.
 * Section (L) also runs the same hooks from a real config, the trait in a host controller without
 * the Drive, and the owner key with a real member identity against a backend one of the same id.
 *
 * Run: php tests/dms-zip-bundle.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "dms-zip-bundle: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';

// The monorepo autoloader does not map module-member (same loader as tests/member-operator-invite.php).
spl_autoload_register(static function (string $class): void {
    $prefix = 'Z77\\Module\\Member\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../packages/module-member/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Z77\Core\Http\Response\ByteRange;
use Z77\Core\Http\Response\ByteSource;
use Z77\Core\Http\Response\ByteSourceResponse;
use Z77\Core\Http\Response\FileResponse;
use Z77\Module\Dms\Blob\BlobStorage;
use Z77\Module\Dms\Blob\LocalBlobStorage;
use Z77\Module\Dms\Entities\Document;
use Z77\Module\Dms\Jobs\ZipCleanupJob;
use Z77\Module\Dms\Jobs\ZipPrepareJob;
use Z77\Module\Dms\Services\ZipBundleException;
use Z77\Module\Dms\Services\ZipBundleService;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Backup\ZipArchiver;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Mail\EmailService;
use Z77\Shared\Mail\HtmlToText;

// ── hooks as a project writes them: invokable, no constructor argument ────────────
final class ZipHarnessAddress
{
    public static string $address = '';
    public static ?string $seen = null;
    public function __invoke(AuthUser $user): string
    {
        self::$seen = ZipBundleService::ownerKey($user);
        if (self::$address === 'boom') {
            throw new RuntimeException('the project\'s store is away');
        }
        return self::$address;
    }
}

final class ZipHarnessGate
{
    public static array $refused = [];
    public static array $asked = [];
    public static bool $throw = false;
    public function __invoke(int $documentId): bool
    {
        self::$asked[] = $documentId;
        if (self::$throw) {
            throw new RuntimeException('the project\'s store is away');
        }
        return !in_array($documentId, self::$refused, true);
    }
}

final class ZipHarnessStarted
{
    public static array $calls = [];
    public function __invoke(string $bundleId, array $documentIds): void
    {
        self::$calls[] = [$bundleId, $documentIds];
    }
}

final class ZipHarnessRemovedThrows implements \Z77\Shared\Auth\AccountRemovedListener
{
    public function accountRemoved(AuthUser $account): void
    {
        throw new RuntimeException('a project\'s clean-up is away');
    }
}

final class ZipHarnessRemovedSpy implements \Z77\Shared\Auth\AccountRemovedListener
{
    public static array $seen = [];
    public function accountRemoved(AuthUser $account): void
    {
        self::$seen[] = ZipBundleService::ownerKey($account);
    }
}

/** A project's controller as far as the trait cares: `json()` and nothing of the Drive. */
final class ZipHarnessHost
{
    use \Z77\Module\Dms\Ui\ZipBundleTrait;

    public array $data = [];
    public int $status = 0;

    public function call(string $action, mixed ...$arguments): mixed
    {
        return $this->{$action}(...$arguments);
    }

    protected function zipMailProfile(): string
    {
        return 'host';
    }

    protected function json(array $data, int $status = 200): \Z77\Core\Http\Response\JsonResponse
    {
        $this->data   = $data;
        $this->status = $status;
        return new \Z77\Core\Http\Response\JsonResponse($data, $status);
    }
}

// ── the shared fakes: everything the service asks lives in one control file ───────
// so the harness, a child process and the built-in server see the same world.

/** @return array{who: string, denied: list<int>, docs: array<int, array<string, mixed>>, email: string} */
function ctl(string $work): array
{
    return json_decode((string) file_get_contents($work . '/ctl.json'), true);
}

function saveCtl(string $work, array $ctl): void
{
    file_put_contents($work . '/ctl.json', json_encode($ctl, JSON_UNESCAPED_UNICODE));
}

function docOf(int $id, array $row): Document
{
    $doc = new Document();
    (new ReflectionProperty(Document::class, 'id'))->setValue($doc, $id);
    $doc->setOriginalName($row['name']);
    $doc->setExt($row['ext']);
    $doc->setChecksum($row['checksum']);
    return $doc;
}

/**
 * The service over the control file. `$mail` and `$enqueue` are the only fakes with memory, the
 * harness records them; a child process and the server need neither.
 */
function lookup(string $work, int $id): ?Document
{
    $row = ctl($work)['docs'][$id] ?? null;
    return $row === null || !empty($row['deleted']) ? null : docOf($id, $row);
}

/** Wait for another process to reach a point; false after the timeout, so a bug cannot hang the run. */
function waitFor(string $path, float $seconds = 30.0): bool
{
    $until = microtime(true) + $seconds;
    while (!is_file($path)) {
        if (microtime(true) > $until) {
            return false;
        }
        clearstatcache(true, $path);
        usleep(5_000);
    }
    return true;
}

/** A manifest as data, whichever way the service lays it out on disk. */
function manifestAt(string $path): array
{
    $lines = explode("\n", (string) file_get_contents($path), 2);
    return (array) json_decode($lines[1] ?? '[]', true) + (array) json_decode($lines[0], true);
}

function serviceOver(string $work, array $options = []): ZipBundleService
{
    return new ZipBundleService(
        $work . '/data/dms/zips',
        new LocalBlobStorage('data/blobs'),
        $options['keepDays'] ?? 7,
        $options['instant'] ?? 0,
        static fn (): string => ctl($work)['who'],
        static fn (int $id): bool => !in_array($id, ctl($work)['denied'], true),
        $options['document'] ?? static fn (int $id): ?Document => lookup($work, $id),
        static fn (): string => ctl($work)['email'],
        $options['enqueue'] ?? static function (): void {},
        $options['mail'] ?? static fn (): bool => true,
        $options['step'] ?? 64 * 1024,
        $options['now'] ?? null,
        $options['gate'] ?? null,
        $options['started'] ?? null,
        $options['perPerson'] ?? 1_000_000,
        $options['installation'] ?? 1_000_000,
        $options['each'] ?? null,
    );
}

// ── the built-in server: this very file is the router ─────────────────────────────
if (PHP_SAPI === 'cli-server') {
    $work = (string) getenv('Z77_WORK');
    define('ABS_BASE_PATH', $work);

    if (($_GET['refuse'] ?? '') !== '') {
        // What a client meets besides the service's own refusals.
        http_response_code(409);
        if ($_GET['refuse'] === 'text') {
            header('Content-Type: text/plain');
            echo 'kein JSON';
        } else {
            header('Content-Type: application/json; charset=utf-8');
            echo $_GET['refuse'] === 'noerror' ? '{"ok":false}' : json_encode(['error' => "<b>fett</b>\x07 " . str_repeat('x', 400)]);
        }
        return;
    }

    if (($_GET['source'] ?? '') !== '') {
        // ByteSourceResponse alone: a source that fails, a source above the cap.
        $source = new class ((string) $_GET['source']) implements ByteSource {
            public function __construct(private string $kind) {}
            public function size(): int { return $this->kind === 'huge' ? 1024 * 1024 * 1024 : 3 * 1024 * 1024; }
            public function emit(int $start, int $end, $stream): void
            {
                if ($this->kind === 'fails' && $start >= 1024 * 1024) {
                    throw new RuntimeException('the disk went away');
                }
                fwrite($stream, str_repeat('z', $end - $start + 1));
            }
        };
        (new ByteSourceResponse($source, 'Prüfung.zip', 'application/zip', 'tag', $_SERVER['HTTP_RANGE'] ?? ''))->send();
        return;
    }

    try {
        serviceOver($work)->serve(
            (string) ($_GET['id'] ?? ''),
            (string) ($_SERVER['HTTP_RANGE'] ?? ''),
            $_SERVER['HTTP_IF_NONE_MATCH'] ?? null,
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD',
        )->send();
    } catch (ZipBundleException $e) {
        http_response_code((int) $e->getCode());
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    return;
}

// ── child processes of section (P): a request and a job that really run beside each other ──
if (($argv[1] ?? '') === '--child-holds') { // something else has a file of the bundle open for a moment
    $h = fopen((string) $argv[2], 'rb');
    touch((string) $argv[3]);
    usleep(120_000);
    fclose($h);
    exit(0);
}
if (in_array($argv[1] ?? '', ['--child-instant', '--child-job', '--child-open', '--child-dies', '--child-pile'], true)) {
    $work = (string) $argv[2];
    define('ABS_BASE_PATH', $work);
    $mailLog = static function (string $to, array $info) use ($work): bool {
        file_put_contents($work . '/mail.log', $info['kind'] . "\n", FILE_APPEND | LOCK_EX);
        return true;
    };
    switch ($argv[1]) {
        case '--child-instant': // a POST: opens an instant bundle and stops inside its count until told to go on
            $seen = 0;
            $service = serviceOver($work, ['instant' => 100 * 1024 * 1024, 'mail' => $mailLog, 'document' => static function (int $id) use ($work, &$seen): ?Document {
                if (++$seen === 3) { // two lookups in the gate of the opening, then the count begins
                    touch($work . '/r.hashing');
                    waitFor($work . '/r.go');
                }
                return lookup($work, $id);
            }]);
            echo json_encode($service->open([201, 202]));
            exit(0);
        case '--child-job': // a job pass: stops after its scan until told to go on, optionally after some steps too
            $asked = 0;
            $stopAt = (int) $argv[3];
            $service = serviceOver($work, ['step' => (int) $argv[4], 'mail' => $mailLog]);
            echo json_encode($service->prepare(static function () use ($work, &$asked, $stopAt): bool {
                if (++$asked === $stopAt) {
                    touch($work . '/j.scanned');
                    waitFor($work . '/j.go');
                }
                return true;
            }));
            exit(0);
        case '--child-open': // a POST at the same moment as its siblings
            $service = serviceOver($work, ['instant' => (int) $argv[4], 'perPerson' => (int) $argv[5], 'installation' => (int) $argv[6]]);
            while (microtime(true) < (float) $argv[3]) {
                usleep(200);
            }
            try {
                echo $service->open([203])['id'] !== '' ? 'opened' : 'no';
            } catch (ZipBundleException $e) {
                echo 'refused' . $e->getCode();
            }
            exit(0);
        case '--child-dies': // a POST that dies in the middle of its count (a fatal, a killed worker)
            $seen = 0;
            $now  = (int) $argv[3];
            serviceOver($work, ['instant' => 100 * 1024 * 1024, 'now' => static fn (): int => $now, 'document' => static function (int $id) use ($work, &$seen): ?Document {
                if (++$seen === 3) {
                    exit(0);
                }
                return lookup($work, $id);
            }])->open([201, 202]);
            exit(1);
        case '--child-pile': // what a runner pass and a list do with a pile, under a small memory limit
            $service = serviceOver($work);
            $counts  = $service->prepare(static fn (): bool => true);
            $list    = count($service->list());
            $swept   = $service->sweep();
            $purged  = $service->deleteForDocuments([987654]);
            echo json_encode(['counts' => $counts, 'list' => $list, 'swept' => $swept, 'purged' => $purged, 'peak' => memory_get_peak_usage(true)]);
            exit(0);
    }
}

// ── a child process: the first piece, at the same moment as its siblings ──────────
if (($argv[1] ?? '') === '--child-first-piece') {
    $work = (string) $argv[2];
    define('ABS_BASE_PATH', $work);
    $service = serviceOver($work, ['started' => static function (string $id, array $documents) use ($work): void {
        file_put_contents($work . '/started.log', json_encode([$id, $documents]) . "\n", FILE_APPEND | LOCK_EX);
    }]);
    while (microtime(true) < (float) $argv[4]) {
        usleep(200);
    }
    echo $service->serve((string) $argv[3], 'bytes=0-99') instanceof ByteSourceResponse ? 'ok' : 'no';
    exit(0);
}

// ── a child process: one budget of the job over the same manifest ─────────────────
if (($argv[1] ?? '') === '--child-prepare') {
    $work = (string) $argv[2];
    define('ABS_BASE_PATH', $work);
    $steps = (int) $argv[3];
    $counts = serviceOver($work, ['step' => (int) $argv[4]])->prepare(static function () use (&$steps): bool {
        return $steps-- > 0;
    });
    echo json_encode($counts);
    exit(0);
}

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

function refused(callable $fn, int $code): bool
{
    try {
        $fn();
    } catch (ZipBundleException $e) {
        return $e->getCode() === $code;
    }
    return false;
}

function refusal(callable $fn): string
{
    try {
        $fn();
    } catch (ZipBundleException $e) {
        return $e->getCode() . ' ' . $e->getMessage();
    }
    return 'nothing thrown';
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

function bytesOf(int $length, string $seed): string
{
    $out = '';
    for ($i = 0; strlen($out) < $length; $i++) {
        $out .= hash('sha256', $seed . $i, true);
    }
    return substr($out, 0, $length);
}

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-zipbundle-' . getmypid();
rrmdir($root);
mkdir($root . '/data/dms', 0777, true);
define('ABS_BASE_PATH', $root);
define('CANONICAL_BASE_URL', 'https://example.ch');
register_shutdown_function(static fn () => rrmdir($root));

// The service logs on purpose in several sections; a harness keeps its screen for the checks.
$errorLog = $root . '/php-error.log';
ini_set('log_errors', '1');
ini_set('error_log', $errorLog);
ini_set('display_errors', '0');

$blob = new LocalBlobStorage('data/blobs');
$originals = [];

/** Store a document's bytes and its row in the control file. */
$addDoc = static function (int $id, string $name, string $bytes) use ($root, $blob, &$originals): void {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $blob->put($id, BlobStorage::ORIGINAL, $ext, $bytes);
    $originals[$id] = $bytes;
    $ctl = ctl($root);
    $ctl['docs'][$id] = ['name' => $name, 'ext' => $ext, 'checksum' => hash('sha256', $bytes), 'deleted' => false];
    saveCtl($root, $ctl);
};
$setCtl = static function (string $key, mixed $value) use ($root): void {
    $ctl = ctl($root);
    $ctl[$key] = $value;
    saveCtl($root, $ctl);
};
$setDoc = static function (int $id, string $key, mixed $value) use ($root): void {
    $ctl = ctl($root);
    $ctl['docs'][$id][$key] = $value;
    saveCtl($root, $ctl);
};
$manifest = static fn (string $id): array => manifestAt("{$root}/data/dms/zips/{$id}/manifest.json");
$encodeManifest = static fn (array $m): string => (new ReflectionMethod(ZipBundleService::class, 'encode'))->invoke(null, $m);
$saveManifest = static fn (string $id, array $m) => file_put_contents("{$root}/data/dms/zips/{$id}/manifest.json", $encodeManifest($m));
$dirOf = static fn (string $id): string => "{$root}/data/dms/zips/{$id}";

saveCtl($root, ['who' => 'backend:1', 'denied' => [], 'docs' => [], 'email' => 'max.muster@example.ch']);
$addDoc(1, 'Plan Übersicht.pdf', bytesOf(300_000, 'one'));
$addDoc(2, 'Bericht.pdf', bytesOf(100_000, 'two'));
$addDoc(3, 'leer.txt', '');
$addDoc(4, 'bericht.PDF', bytesOf(5_000, 'four'));
$addDoc(5, 'gross.bin', bytesOf(2_500_000, 'five'));
// 6: a readable document without its original; 7: not readable; 8: never existed.
$ctl = ctl($root);
$ctl['docs'][6] = ['name' => 'Ohne Original.pdf', 'ext' => 'pdf', 'checksum' => 'x', 'deleted' => false];
$ctl['docs'][7] = ['name' => 'Geheim Max Muster.pdf', 'ext' => 'pdf', 'checksum' => 'y', 'deleted' => false];
$ctl['denied'] = [7];
saveCtl($root, $ctl);
$blob->put(7, BlobStorage::ORIGINAL, 'pdf', 'secret');

$enqueued = 0;
$mails = [];
$mailMode = 'ok';
$clock = time();
$options = [
    'enqueue' => static function () use (&$enqueued): void { $enqueued++; },
    'mail'    => static function (string $to, array $info) use (&$mails, &$mailMode): bool {
        if ($mailMode === 'throw') {
            throw new RuntimeException('the mail server is away');
        }
        if ($mailMode === 'false') {
            return false;
        }
        $mails[] = [$to, $info];
        return true;
    },
    'now'     => static function () use (&$clock): int { return $clock; },
];
$svc = serviceOver($root, $options);
$all = static fn (): Closure => static fn (): bool => true;

echo "(A) opening a bundle\n";
$open = $svc->open([1, 2, 6, 7, 8, 2, 0, -3], 'Mein Paket');
$id   = $open['id'];
check('a random 32-hex id', preg_match('/^[a-f0-9]{32}$/', $id) === 1);
check('state preparing, the job queued once', $open['state'] === ZipBundleService::STATE_PREPARING && $enqueued === 1, $open['state'] . ' / ' . $enqueued);
check('two documents went in', $open['documents'] === 2 && $open['bytes'] === 400_000, json_encode($open));
check('without its original: skipped and named', $open['skipped'] === [['document' => 6, 'name' => 'Ohne Original.pdf']], json_encode($open['skipped']));
check('denied or unknown: counted only', $open['denied'] === 2, (string) $open['denied']);
$raw = (string) file_get_contents($dirOf($id) . '/manifest.json');
check('a denied document leaves no trace of its name', !str_contains($raw, 'Geheim') && !str_contains(json_encode($open), 'Geheim'));
check('nothing on disk but the manifest and its lock (no second copy)', array_values(array_diff(scandir($dirOf($id)), ['.', '..'])) === ['lock', 'manifest.json']);
check('the owner is stored as a hash', !str_contains($raw, 'backend:1') && str_contains($raw, hash('sha256', 'backend:1')));
check('the view shows no owner, no cursor, no entries', !isset($open['owner']) && !isset($open['cursor']) && !isset($open['entries']));
check('name: the person\'s, cleaned, .zip', $open['name'] === 'Mein Paket.zip', $open['name']);
check('a name cleaned of its path', $svc->open([3], '../../böse/Paket.zip')['name'] === 'Paket.zip');
check('no name: today\'s', str_starts_with($svc->open([3])['name'], 'dokumente-' . date('Y', $clock)));
check('nobody chose anything → 400', refused(fn () => $svc->open([]), 400) && refused(fn () => $svc->open([0, -1]), 400));
check('nothing readable → 422', refused(fn () => $svc->open([7, 8]), 422));
check('only without originals → 422', refused(fn () => $svc->open([6]), 422));
$setCtl('who', '');
check('nobody logged in → 403', refused(fn () => $svc->open([1]), 403));
$setCtl('who', 'backend:1');
check('a state request before anything is hashed: nothing done yet', $svc->state($id)['done'] === 0);
$svc->delete($id);
foreach (array_diff(scandir($root . '/data/dms/zips'), ['.', '..']) as $d) {
    rrmdir($root . '/data/dms/zips/' . $d);
}
$enqueued = 0;

echo "(B) limits\n";
$source = (string) file_get_contents(__DIR__ . '/../packages/module-dms/src/Ui/DriveControllerTrait.php');
preg_match('/private const BULK_MAX = (\d+);/', $source, $m);
check('MAX_DOCUMENTS is the Drive\'s BULK_MAX (500)', ZipBundleService::MAX_DOCUMENTS === 500 && (int) ($m[1] ?? 0) === 500);
check('501 documents are refused, not silently cut', refused(fn () => $svc->open(range(1, 501)), 400));
$five = array_merge(range(1, 500), range(1, 20));
check('duplicates do not count against the cap', $svc->open($five)['documents'] === 5);
$kept = $svc->open([1]);
check('three unfinished per person: the third still goes', $kept['state'] === ZipBundleService::STATE_PREPARING);
$third = $svc->open([2]);
check('… a fourth is refused (429), naming the limit', refused(fn () => $svc->open([4]), 429) && str_contains(refusal(fn () => $svc->open([4])), '3 ZIP'), refusal(fn () => $svc->open([4])));
$setCtl('who', 'backend:2');
check('another person has slots of its own', $svc->open([4])['state'] === ZipBundleService::STATE_PREPARING);
$setCtl('who', 'backend:1');
$svc->prepare($all());
check('ready bundles do not count: a new one goes', $svc->open([1])['state'] === ZipBundleService::STATE_PREPARING);
$instantSvc = serviceOver($root, ['instant' => 10 * 1024 * 1024] + $options);
$svc->open([1]);
$svc->open([1]);
check('three unfinished again', refused(fn () => $svc->open([4]), 429));
check('… the instant path needs no slot', $instantSvc->open([4])['state'] === ZipBundleService::STATE_READY);
$svc->prepare($all());
$enqueued = 0;

echo "(C) owner binding\n";
$b = $svc->open([1, 2, 3]);
$svc->prepare($all());
$id = $b['id'];
check('the owner reads the state, ready', $svc->state($id)['state'] === ZipBundleService::STATE_READY);
check('the owner gets the answer', $svc->serve($id) instanceof ByteSourceResponse);
foreach (['backend:2' => 'another person', 'member:1' => 'a member with the same id', '' => 'nobody'] as $who => $label) {
    $setCtl('who', $who);
    check("{$label}: state, serve and delete → 404, the bundle stays",
        refused(fn () => $svc->state($id), 404) && refused(fn () => $svc->serve($id), 404) && refused(fn () => $svc->delete($id), 404) && is_dir($dirOf($id)));
}
// A super-user passes every `read` (the fake lets everyone read here), and still is not the owner.
$setCtl('who', 'backend:99');
$setCtl('denied', []);
check('a super-user is no exception: read everywhere, the bundle is still somebody else\'s', refused(fn () => $svc->serve($id), 404));
$setCtl('denied', [7]);
$setCtl('who', 'backend:1');
check('a malformed id never reaches a path', refused(fn () => $svc->state('../../etc'), 404) && refused(fn () => $svc->state(str_repeat('g', 32)), 404) && refused(fn () => $svc->state(''), 404));

echo "(D) job slices, resume mid-file\n";
$slices = serviceOver($root, ['step' => 512 * 1024] + $options);
$b  = $slices->open([1, 5, 3]);
$id = $b['id'];
$steps = 3; // the pass asks once before the bundle, then once per step
$budget = static function () use (&$steps): bool { return $steps-- > 0; };
$counts = $slices->prepare($budget);
$m = $manifest($id);
check('the budget ends mid-file: still preparing, counted as open', $counts['open'] >= 1 && $m['state'] === 'preparing', json_encode($counts));
check('the cursor stands in the manifest: file 1, offset inside it, a hash state',
    $m['cursor']['file'] === 1 && $m['cursor']['offset'] > 0 && $m['cursor']['offset'] < 2_500_000 && $m['cursor']['hash'] !== null, json_encode(array_intersect_key($m['cursor'], ['file' => 1, 'offset' => 1])));
check('the first file already has its CRC', $m['entries'][0]['crc'] === (int) hexdec(hash('crc32b', $originals[1])));
check('the state shows the progress', $slices->state($id)['done'] === 300_000 + $m['cursor']['offset'], (string) $slices->state($id)['done']);
$offsetBefore = $m['cursor']['offset'];
$out = [];
$code = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child-prepare ' . escapeshellarg($root) . ' 2 ' . (512 * 1024), $out, $code);
$m = $manifest($id);
check('another process goes on from that cursor (one step)', $code === 0 && $m['cursor']['offset'] === $offsetBefore + 512 * 1024, $code . ' / ' . json_encode($m['cursor']['offset']) . ' / ' . implode(' ', $out));
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child-prepare ' . escapeshellarg($root) . ' 100 ' . (512 * 1024), $out, $code);
$m = $manifest($id);
check('a third process finishes it: ready', $code === 0 && $m['state'] === 'ready', $m['state']);
$expected = (int) hexdec((string) hash_file('crc32b', $blob->path(5, BlobStorage::ORIGINAL, 'bin')));
check('the CRC resumed across processes equals hash_file()', $m['entries'][1]['crc'] === $expected, $m['entries'][1]['crc'] . ' vs ' . $expected);
check('an empty file has CRC 0', $m['entries'][2]['crc'] === 0);
check('ready: the hash state is gone from the manifest', $m['cursor']['hash'] === null);

$actor = new AuthUser(['id' => 'cron', 'user_name' => 'cron', 'realm' => AuthUser::REALM_CRON]);
$job = new ZipPrepareJob($slices);
$b   = $slices->open([1]);
$r   = $job->run(new JobContext('dms-zip-prepare', [], null, 1, time() + 1, $actor));
check('the job with no time left: again, nothing hashed', $r->wantsMore() && !$r->isDone() && $manifest($b['id'])['cursor']['offset'] === 0, $r->getNote());
$r = $job->run(new JobContext('dms-zip-prepare', [], null, 1, time() + 3600, $actor));
check('the job with time: done, the bundle ready', $r->isDone() && $manifest($b['id'])['state'] === 'ready', $r->getNote());
$r = $job->run(new JobContext('dms-zip-prepare', [], null, 1, time() + 3600, $actor));
check('nothing to do: done', $r->isDone(), $r->getNote());

$b = $svc->open([1, 2]);
$setDoc(2, 'checksum', 'replaced');
$svc->prepare($all());
$state = $svc->state($b['id']);
check('a document replaced during the count: failed, a German reason', $state['state'] === 'failed' && str_contains((string) $state['error'], 'geändert'), json_encode($state));
check('… a failed bundle is refused (409), it does not count as unfinished',
    refused(fn () => $svc->serve($b['id']), 409) && str_contains(refusal(fn () => $svc->serve($b['id'])), 'geändert'));
$setDoc(2, 'checksum', hash('sha256', $originals[2]));
$b = $svc->open([1, 2]);
$setDoc(2, 'deleted', true);
$svc->prepare($all());
check('a document deleted during the count: failed', str_contains((string) $svc->state($b['id'])['error'], 'gelöscht'));
$setDoc(2, 'deleted', false);
$b = $svc->open([2]);
$blob->put(2, BlobStorage::ORIGINAL, 'pdf', 'kurz');
$svc->prepare($all());
check('a blob that shrank during the count: failed', $svc->state($b['id'])['state'] === 'failed');
$blob->put(2, BlobStorage::ORIGINAL, 'pdf', $originals[2]);

$b = $slices->open([5]);
$slices->prepare(static function () use ($b): bool {
    if (is_dir(ABS_BASE_PATH . '/data/dms/zips/' . $b['id'])) {
        rrmdir(ABS_BASE_PATH . '/data/dms/zips/' . $b['id']);
    }
    return true;
});
check('a bundle deleted during the count: no error, nothing written back', !is_dir($dirOf($b['id'])));

echo "(E) a damaged hash context restarts that file\n";
class Bomb
{
    public static int $woken = 0;
    public function __wakeup(): void { self::$woken++; }
    public function __destruct() { self::$woken++; }
}
$valid = static function () use ($slices, &$steps): array {
    $b = $slices->open([1, 5]);
    $steps = 3;
    $slices->prepare(static function () use (&$steps): bool { return $steps-- > 0; });
    return [$b['id'], manifestAt(ABS_BASE_PATH . "/data/dms/zips/{$b['id']}/manifest.json")];
};
$crc5 = (int) hexdec(hash('crc32b', $originals[5]));
$crc1 = (int) hexdec(hash('crc32b', $originals[1]));
foreach ([
    'not base64'              => '%%% no base64 %%%',
    'base64 of garbage'       => base64_encode('garbage'),
    'a truncated context'     => null, // filled below from the real one
    'another class'           => base64_encode(serialize(new ArrayObject([1]))),
    'an object with a wakeup' => base64_encode('O:4:"Bomb":0:{}'),
    'a plain array'           => base64_encode(serialize(['a' => 1])),
    'a context with a bad algo' => base64_encode('O:11:"HashContext":5:{i:0;s:7:"crc666x";i:1;i:0;i:2;a:0:{}i:3;i:0;i:4;a:0:{}}'),
] as $label => $hash) {
    [$bid, $m] = $valid();
    check("{$label}: mid-file before the damage", $m['cursor']['file'] === 1 && $m['cursor']['offset'] > 0);
    $m['cursor']['hash'] = $hash ?? substr((string) $m['cursor']['hash'], 0, 40);
    $saveManifest($bid, $m);
    $slices->prepare($all());
    $after = $manifest($bid);
    check("{$label}: restarted and right", $after['state'] === 'ready' && $after['entries'][1]['crc'] === $crc5, $after['state'] . ' ' . json_encode($after['entries'][1]['crc']));
    check("{$label}: the file before it keeps its CRC", $after['entries'][0]['crc'] === $crc1);
}
check('no foreign object was ever woken', Bomb::$woken === 0, (string) Bomb::$woken);
[$bid, $m] = $valid();
$m['cursor']['offset'] = 99_999_999;
$saveManifest($bid, $m);
$slices->prepare($all());
check('an offset beyond the file: restarted and right', $manifest($bid)['entries'][1]['crc'] === $crc5);
[$bid, $m] = $valid();
$m['cursor']['file'] = 99;
$saveManifest($bid, $m);
$slices->prepare($all());
$after = $manifest($bid);
check('a file index out of range: everything again, and right', $after['state'] === 'ready' && $after['entries'][0]['crc'] === $crc1 && $after['entries'][1]['crc'] === $crc5);

echo "(F) instant path\n";
$enqueued = 0;
$instant = serviceOver($root, ['instant' => 400_000] + $options);
$b = $instant->open([1, 3]);
check('below the threshold: ready on return, no job', $b['state'] === 'ready' && $enqueued === 0, $b['state'] . ' / ' . $enqueued);
check('… and the CRC is right', $manifest($b['id'])['entries'][0]['crc'] === $crc1);
check('… the first piece is served', $instant->serve($b['id'], 'bytes=0-99') instanceof ByteSourceResponse);
check('exactly at the threshold: the job', $instant->open([1, 2])['state'] === 'preparing' && $enqueued === 1, (string) $enqueued);
check('0 means always the job', serviceOver($root, ['instant' => 0] + $options)->open([3])['state'] === 'preparing');
$svc->prepare($all());
$enqueued = 0;

echo "(G) mail\n";
$mails = [];
$mailMode = 'ok';
$b = $svc->open([1, 2], '', true);
check('requested: pending, the address kept only for the job', $manifest($b['id'])['mail'] === ['state' => 'pending', 'to' => 'max.muster@example.ch'], json_encode($manifest($b['id'])['mail']));
check('the view does not show the address', !str_contains(json_encode($b), 'max.muster'));
$svc->prepare($all());
check('ready: one mail, to the address, with numbers only, no name',
    count($mails) === 1 && $mails[0][0] === 'max.muster@example.ch' && array_keys($mails[0][1]) === ['kind', 'documents', 'bytes', 'expires', 'reason', 'profile']
    && $mails[0][1]['kind'] === 'ready' && $mails[0][1]['documents'] === 2 && $mails[0][1]['bytes'] === 400_000 && $mails[0][1]['reason'] === '' && $mails[0][1]['profile'] === '', json_encode($mails));
$m = $manifest($b['id']);
check('recorded as sent, the address gone from the manifest', $m['mail']['state'] === 'sent' && !isset($m['mail']['to']) && !str_contains(json_encode($m), 'max.muster'), json_encode($m['mail']));
$svc->prepare($all());
$svc->prepare($all());
check('further passes send nothing', count($mails) === 1);
$b = $svc->open([1]);
$svc->prepare($all());
check('not requested: no mail', count($mails) === 1 && $manifest($b['id'])['mail']['state'] === 'none');
$setCtl('email', '');
$b = $svc->open([1], '', true);
$svc->prepare($all());
check('requested, no address on the account: no mail, no failure', count($mails) === 1 && $manifest($b['id'])['mail']['state'] === 'none');
$setCtl('email', 'max.muster@example.ch');

foreach (['false' => 'the service answers false', 'throw' => 'the transport throws'] as $mode => $label) {
    $mails = [];
    $mailMode = $mode;
    $b = $svc->open([1, 3], '', true);
    $svc->prepare($all());
    $state = $svc->state($b['id']);
    check("{$label}: recorded on the bundle, the bundle stays usable",
        $state['state'] === 'ready' && $state['mail']['state'] === 'failed' && str_contains((string) $state['mail']['error'], 'E-Mail') && $svc->serve($b['id'], 'bytes=0-9') instanceof ByteSourceResponse, json_encode($state['mail']));
    $svc->prepare($all());
    check("{$label}: not retried on its own", $svc->state($b['id'])['mail']['state'] === 'failed' && $mails === []);
}
check('a failed mail is logged', str_contains((string) @file_get_contents($errorLog), 'the mail failed'));
check('… the log names no address and no document', !str_contains((string) file_get_contents($errorLog), 'max.muster') && !str_contains((string) file_get_contents($errorLog), 'Übersicht'));
$mailMode = 'ok';
$mails = [];
$b = $svc->open([1, 3], '', true);
$svc->prepare($all());
$m = $manifest($b['id']);
$m['mail'] = ['state' => 'sending', 'to' => 'max.muster@example.ch'];
$saveManifest($b['id'], $m);
$mails = [];
$svc->prepare($all());
check('a send interrupted by a crash is not repeated: recorded as failed', $mails === [] && $manifest($b['id'])['mail']['state'] === 'failed', json_encode($manifest($b['id'])['mail']));

$message = ZipBundleService::mailMessage('max.muster@example.ch', 'https://example.ch/', ['documents' => 12, 'bytes' => 1_500_000_000, 'expires' => mktime(0, 0, 0, 12, 24, 2026)]);
check('the message: the address, a German subject, the module\'s template',
    $message->getTo() === 'max.muster@example.ch' && str_contains($message->getSubject(), 'bereit') && $message->getTemplate() === 'emails/zipReady' && $message->getTemplateNameSpace() === 'Z77\\Module\\Dms');
$context = $message->getContext();
check('the link: the canonical origin + the one route constant, no id, no token', $context['link'] === 'https://example.ch' . ZipBundleService::DRIVE_VIEW_ROUTE && !str_contains($context['link'], '?'), $context['link']);
$withSender = ZipBundleService::mailMessage('a@example.ch', 'https://example.ch/', ['documents' => 1, 'bytes' => 1, 'expires' => 1, 'sender' => "Muster\r\nBcc: x@example.ch AG"]);
check('the sender stands in the subject, on one line', $withSender->getSubject() === 'ZIP-Download bereit — Muster Bcc: x@example.ch AG', $withSender->getSubject());
$withOwn = ZipBundleService::mailMessage('a@example.ch', 'https://example.ch/', ['documents' => 1, 'bytes' => 1, 'expires' => 1, 'sender' => 'Muster AG'], ['ready' => ['subject' => 'Abholbereit']]);
check('a project\'s own subject without the placeholder stays as written', $withOwn->getSubject() === 'Abholbereit', $withOwn->getSubject());
foreach (['zipReady', 'zipFailed'] as $mailTpl) {
    check("{$mailTpl}: the logo slot at the top, filled at send time, gone without a source", str_contains((string) file_get_contents(dirname(__DIR__) . "/packages/module-dms/res/view/templates/emails/{$mailTpl}.tpl.php"), '<p data-z77-inline="logo"'));
}
check('the default subject without a sender is neutral, neither du nor Sie', $message->getSubject() === 'ZIP-Download bereit', $message->getSubject());
$thrown = false;
try { ZipBundleService::mailMessage('a@example.ch', '  ', ['documents' => 1, 'bytes' => 1, 'expires' => 1]); } catch (RuntimeException) { $thrown = true; }
check('no canonical base URL: it throws instead of mailing a dead link', $thrown);
extract($context);
ob_start();
include __DIR__ . '/../packages/module-dms/res/view/templates/emails/zipReady.tpl.php';
$html = (string) ob_get_clean();
check('the template: count, size, expiry, link', str_contains($html, '12') && str_contains($html, $context['size']) && str_contains($html, '24.12.2026') && str_contains($html, 'href="' . $context['link'] . '"'), $html);
$text = (new HtmlToText())->convert($html);
check('the text half carries the link as well', str_contains($text, $context['link']), $text);

echo "(H) the gate per piece\n";
$b  = $svc->open([1, 2, 4], 'Gate');
$svc->prepare($all());
$id = $b['id'];
$names = array_column($manifest($id)['entries'], 'name');
check('a name that differs only by case got its suffix in the manifest', in_array('bericht (2).PDF', $names, true), json_encode($names, JSON_UNESCAPED_UNICODE));
$first  = 30 + strlen($names[0]) + 300_000;              // doc 2's header starts here
$second = $first + 30 + strlen($names[1]) + 100_000;     // doc 4's header starts here
$third  = $second + 30 + strlen($names[2]) + 5_000;      // the central directory starts here
$in1 = 'bytes=100-200';
$in2 = 'bytes=' . ($first + 1000) . '-' . ($first + 2000);
$in3 = 'bytes=' . ($second + 100) . '-' . ($second + 200);
$clip2 = 'bytes=' . ($first - 5) . '-' . ($first + 3);     // ends inside doc 2's header
$central = 'bytes=' . $third . '-' . ($third + 10);
$passes = static fn (string $range): bool => $svc->serve($id, $range) instanceof ByteSourceResponse;

check('all allowed: every piece passes', $passes('bytes=0-99') && $passes($in1) && $passes($in2) && $passes($in3) && $passes($central) && $passes(''));

$setCtl('denied', [7, 2]);
check('right on doc 2 withdrawn: the piece inside it → 403', refused(fn () => $svc->serve($id, $in2), 403));
check('… the message names no document', !str_contains(refusal(fn () => $svc->serve($id, $in2)), 'Bericht'), refusal(fn () => $svc->serve($id, $in2)));
check('… a piece that only clips its header → 403 (the name is in there)', refused(fn () => $svc->serve($id, $clip2), 403));
check('… the first piece checks all → 403', refused(fn () => $svc->serve($id, 'bytes=0-99'), 403));
check('… a piece reaching the central directory → 403 (every name is in there)', refused(fn () => $svc->serve($id, $central), 403));
check('… a request without Range and a HEAD → 403', refused(fn () => $svc->serve($id), 403) && refused(fn () => $svc->serve($id, '', null, true), 403));
check('… a piece inside another document passes', $passes($in3) && $passes($in1 ));
$setCtl('denied', [7]);

$setDoc(2, 'deleted', true);
check('doc 2 deleted: its piece → 409, the first piece → 409, another piece passes',
    refused(fn () => $svc->serve($id, $in2), 409) && refused(fn () => $svc->serve($id, 'bytes=0-99'), 409) && $passes($in3));
$setDoc(2, 'deleted', false);

$setDoc(2, 'checksum', 'replaced');
check('doc 2 replaced (checksum): its piece → 409, named in the message',
    refused(fn () => $svc->serve($id, $in2), 409) && str_contains(refusal(fn () => $svc->serve($id, $in2)), 'Bericht.pdf') && $passes($in3), refusal(fn () => $svc->serve($id, $in2)));
$setDoc(2, 'checksum', hash('sha256', $originals[2]));

$blob->put(2, BlobStorage::ORIGINAL, 'pdf', substr($originals[2], 0, 99_000));
check('doc 2 with another size on disk (same checksum in the record): 409', refused(fn () => $svc->serve($id, $in2), 409));
$blob->put(2, BlobStorage::ORIGINAL, 'pdf', $originals[2]);
check('everything back: passes', $passes($in2));

$setCtl('denied', [7, 1, 2, 4]);
check('an unsatisfiable range answers no byte: it clears owner and expiry only (416 follows)', $passes('bytes=999999999-'));
$setCtl('who', 'backend:2');
check('… and still not for somebody else', refused(fn () => $svc->serve($id, 'bytes=999999999-'), 404));
$setCtl('who', 'backend:1');
$setCtl('denied', [7]);

$p = $svc->open([1]);
check('not ready yet → 409', refused(fn () => $svc->serve($p['id'], 'bytes=0-9'), 409));
$svc->prepare($all());

echo "(I) expiry, delete, sweep\n";
$created = $clock;
$p = $svc->open([1]);
check('expires after zipKeepDays, from creation', $p['expires'] === $created + 7 * 86400, (string) $p['expires']);
$clock += 3600;
$svc->prepare($all());
$ready = $svc->state($p['id']);
check('the clock starts again when it is ready', $ready['expires'] === $clock + 7 * 86400, (string) $ready['expires']);
$pid = $p['id'];
$clock = $ready['expires'];
check('on the last second it still answers', $svc->serve($pid, 'bytes=1-2') instanceof ByteSourceResponse);
$clock++;
check('one second later: 404 everywhere, with no job having run', refused(fn () => $svc->state($pid), 404) && refused(fn () => $svc->serve($pid, 'bytes=1-2'), 404) && refused(fn () => $svc->delete($pid), 404));
check('… the manifest is still there, the gate alone refused', is_dir($dirOf($pid)));
$unprepared = serviceOver($root, $options)->open([1]);
$clock += 8 * 86400;
$svc->prepare($all());
check('a bundle expired before the job got to it: not hashed', $manifest($unprepared['id'])['entries'][0]['crc'] === null);

mkdir($root . '/data/dms/zips/keep-me');
mkdir($root . '/data/dms/zips/' . str_repeat('c', 32));
file_put_contents($root . '/data/dms/zips/' . str_repeat('c', 32) . '/manifest.json', '{broken');
touch($root . '/data/dms/zips/' . str_repeat('c', 32) . '/manifest.json', $clock - 8 * 86400);
mkdir($root . '/data/dms/zips/' . str_repeat('d', 32));
file_put_contents($root . '/data/dms/zips/' . str_repeat('d', 32) . '/manifest.json', '{broken and young');
touch($root . '/data/dms/zips/' . str_repeat('d', 32) . '/manifest.json', $clock - 60);
$fresh = $svc->open([1]);
$busyId = str_repeat('e', 32);
mkdir($dirOf($busyId));
$busy = ['version' => 1, 'id' => $busyId, 'owner' => 'x', 'state' => 'ready', 'name' => 'x.zip', 'created' => 1, 'expires' => 1, 'entries' => []];
file_put_contents($dirOf($busyId) . '/manifest.json', json_encode($busy));
$lockHandle = fopen($dirOf($busyId) . '/manifest.json', 'rb');
flock($lockHandle, LOCK_SH);
$svc->sweep();
check('expired manifests are gone, the fresh one stays', !is_dir($dirOf($pid)) && !is_dir($dirOf($unprepared['id'])) && is_dir($dirOf($fresh['id'])));
check('a directory that is not a bundle stays', is_dir($root . '/data/dms/zips/keep-me'));
check('a broken manifest of some age goes, a young one stays', !is_dir($dirOf(str_repeat('c', 32))) && is_dir($dirOf(str_repeat('d', 32))));
check('a locked manifest is left for the next sweep', is_dir($dirOf($busyId)));
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
$swept = (new ZipCleanupJob($svc))->run(new JobContext('dms-zip-cleanup', [], null, 1, time() + 60, $actor));
check('the cleanup job removes it now, and says so', $swept->isDone() && !is_dir($dirOf($busyId)) && str_contains($swept->getNote(), '1 expired'), $swept->getNote());
$gone = serviceOver($root, $options)->open([3]);
$m = $manifest($gone['id']);
$m['expires'] = $clock - 1;
$saveManifest($gone['id'], $m);
$svc->open([3]);
check('every new bundle sweeps', !is_dir($dirOf($gone['id'])));
$svc->delete($fresh['id']);
check('delete removes it; a second delete is a 404', !is_dir($dirOf($fresh['id'])) && refused(fn () => $svc->delete($fresh['id']), 404));

$cfg = require __DIR__ . '/../packages/module-dms/src/App/Config/dmsConfig.inc.php';
check('config: zipKeepDays 7, zipInstantBytes 256 MB', ($cfg['zipKeepDays'] ?? 0) === 7 && ($cfg['zipInstantBytes'] ?? 0) === 256 * 1024 * 1024);
$jp = $cfg['jobs']['dms-zip-prepare'] ?? [];
$jc = $cfg['jobs']['dms-zip-cleanup'] ?? [];
check('the job key of the service is the one registered', ZipBundleService::JOB_KEY === 'dms-zip-prepare' && ($jp['class'] ?? '') === ZipPrepareJob::class && class_exists($jp['class']));
check('dms-zip-prepare: no schedule, a new bundle queues it', !isset($jp['defaultSchedule']));
check('dms-zip-cleanup: daily, off-peak, the exception jobs.md names', ($jc['defaultSchedule'] ?? '') === 'daily@03:50' && ($jc['class'] ?? '') === ZipCleanupJob::class, json_encode($jc));

echo "(J) backup excludes\n";
$excludes = (new ReflectionClassConstant(\Z77\Shared\Backup\BackupService::class, 'DATA_EXCLUDES'))->getValue();
check('DATA_EXCLUDES names dms/zips', in_array('dms/zips', $excludes, true), json_encode($excludes));
$tree = $root . '/backup-src';
mkdir($tree . '/dms/zips/' . str_repeat('a', 32), 0777, true);
mkdir($tree . '/dms/chunks', 0777, true);
mkdir($tree . '/blobs', 0777, true);
file_put_contents($tree . '/dms/zips/' . str_repeat('a', 32) . '/manifest.json', '{}');
file_put_contents($tree . '/dms/chunks/keep.txt', 'x');
file_put_contents($tree . '/blobs/keep.txt', 'x');
$archive = $root . '/data-backup.zip';
(new ZipArchiver())->zipDirectory($tree, $archive, $excludes);
$zip = new ZipArchive();
$zip->open($archive);
$in = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $in[] = $zip->getNameIndex($i);
}
$zip->close();
check('a real data archive holds no bundle, everything else stays', !array_filter($in, static fn ($n) => str_contains($n, 'dms/zips')) && in_array('dms/chunks/keep.txt', $in, true) && in_array('blobs/keep.txt', $in, true), json_encode($in));
$backup = (new ReflectionClass(\Z77\Shared\Backup\BackupService::class))->getFileName();
check('… and the full archive appends the same list under data/', str_contains((string) file_get_contents($backup), "\$rel = 'data/' . \$jobPath;"));

echo "(K) a real ranged answer through the built-in server\n";
$b  = $svc->open([1, 2, 3, 4, 5], 'Übersicht Plan');
$svc->prepare($all());
$id = $b['id'];
$setCtl('who', 'backend:1');

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port  = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env    = array_merge(getenv(), ['Z77_WORK' => $root]);
$server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $root . '/server-error.log', '-S', "127.0.0.1:{$port}", __FILE__], [0 => ['pipe', 'r'], 1 => ['file', $root . '/server.log', 'w'], 2 => ['file', $root . '/server.log', 'a']], $pipes, $root, $env);
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(100_000);
    $s = @fsockopen('127.0.0.1', $port);
    if ($s) { fclose($s); $up = true; }
}
check('built-in server started', $up);

/** @return array{0: int, 1: array<string, string>, 2: string} status, headers (lower-case), body */
$http = static function (array $headers = [], string $query = '', string $method = 'GET') use ($port): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30, 'method' => $method, 'header' => implode("\r\n", $headers)]]);
    $body = @file_get_contents("http://127.0.0.1:{$port}/{$query}", false, $ctx);
    $status = 0;
    $out = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; $out = []; continue; }
        if (str_contains($h, ':')) {
            [$k, $v] = explode(':', $h, 2);
            $out[strtolower(trim($k))] = trim($v);
        }
    }
    return [$status, $out, (string) $body];
};

if ($up) {
    $url = '?id=' . $id;

    // Exactly what chunk-download.js does: a first piece with an explicit end; the total, the
    // name and the ETag from it; then sequential explicit pieces, each one checked.
    $chunk = 1024 * 1024;
    [$st, $h, $body] = $http(['Range: bytes=0-' . ($chunk - 1)], $url);
    check('the first piece: 206', $st === 206, "{$st} " . substr($body, 0, 200));
    preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', $h['content-range'] ?? '', $cr);
    $total = (int) ($cr[3] ?? 0);
    $etag  = $h['etag'] ?? '';
    check('Content-Range starts where asked, the true total', (int) ($cr[1] ?? -1) === 0 && (int) ($cr[2] ?? -1) === $chunk - 1 && $total > 2_900_000, $h['content-range'] ?? '(none)');
    check('an ETag', $etag !== '' && $etag[0] === '"', $etag);
    check('through the DeliveryPolicy: application/zip, attachment, nosniff, the sandbox',
        ($h['content-type'] ?? '') === 'application/zip' && ($h['content-disposition'] ?? '') === 'attachment; filename="Übersicht Plan.zip"'
        && ($h['x-content-type-options'] ?? '') === 'nosniff' && ($h['content-security-policy'] ?? '') === 'sandbox', json_encode($h, JSON_UNESCAPED_UNICODE));
    check('Accept-Ranges, private', ($h['accept-ranges'] ?? '') === 'bytes' && str_contains($h['cache-control'] ?? '', 'private'), json_encode($h, JSON_UNESCAPED_UNICODE));

    $file = $root . '/assembled.zip';
    $w = fopen($file, 'wb');
    fwrite($w, $body);
    $pos = strlen($body);
    $pieces = 1;
    $sound = true;
    while ($pos < $total) {
        $end = min($pos + $chunk, $total) - 1;
        [$st, $h, $piece] = $http(["Range: bytes={$pos}-{$end}"], $url);
        preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', $h['content-range'] ?? '', $cr);
        $sound = $sound && $st === 206 && (int) $cr[1] === $pos && (int) $cr[2] <= $end && (int) $cr[3] === $total
            && strlen($piece) === (int) $cr[2] - (int) $cr[1] + 1 && ($h['etag'] ?? '') === $etag
            && ($h['content-length'] ?? '') === (string) strlen($piece);
        fwrite($w, $piece);
        $pos = (int) $cr[2] + 1;
        $pieces++;
    }
    fclose($w);
    check("{$pieces} pieces: 206, Content-Range, Content-Length, the same ETag and total on each", $sound && $pieces > 2);
    check('the assembled size is the total', filesize($file) === $total, (string) filesize($file));
    $zip = new ZipArchive();
    $opened = $zip->open($file, ZipArchive::CHECKCONS);
    check('libzip opens the assembled file with CHECKCONS', $opened === true, var_export($opened, true));
    if ($opened === true) {
        check('five entries', $zip->numFiles === 5, (string) $zip->numFiles);
        $wanted = [
            ['Plan Übersicht.pdf', $originals[1]],
            ['Bericht.pdf', $originals[2]],
            ['leer.txt', $originals[3]],
            ['bericht (2).PDF', $originals[4]],
            ['gross.bin', $originals[5]],
        ];
        foreach ($wanted as [$name, $bytes]) {
            $stat = $zip->statName($name);
            check("«{$name}»: the original bytes, its CRC", $stat !== false && $zip->getFromName($name) === $bytes && ($stat['crc'] & 0xFFFFFFFF) === (int) hexdec(hash('crc32b', $bytes)), json_encode($stat));
        }
        $zip->close();
    }

    // The client itself, where Node is around: the plan's «no client change» rests on this.
    $node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
    if ($node === '') {
        echo "  NOTE no Node on this machine, chunk-download.js itself not exercised\n";
    } else {
        file_put_contents($root . '/reader.js', <<<'JS'
const fs = require('fs');
const [, , lib, url, out, chunk] = process.argv;
globalThis.self = globalThis;
require(lib);
(async () => {
    const r = self.Z77ChunkDownload.reader(url, { chunkBytes: Number(chunk), backoff: () => 1 });
    const info = await r.open();
    const fd = fs.openSync(out, 'w');
    for (let p = await r.next(); p !== null; p = await r.next()) fs.writeSync(fd, p);
    fs.closeSync(fd);
    console.log(JSON.stringify({ ok: true, size: info.size, filename: info.filename, type: info.type, loaded: r.loaded() }));
})().catch((e) => console.log(JSON.stringify({ ok: false, message: e.message, final: !!e.final })));
JS);
        $reader = static function (string $lib) use ($root, $port, $url): array {
            $out = [];
            exec(implode(' ', array_map('escapeshellarg', ['node', $root . '/reader.js', str_replace('\\', '/', (string) realpath(__DIR__ . '/../packages/module-dms/res/assets/js/documents/' . $lib)), "http://127.0.0.1:{$port}/{$url}", $root . '/node.zip', (string) (1024 * 1024)])), $out);
            return json_decode(implode('', $out), true) ?? ['ok' => false, 'message' => 'no answer'];
        };
        foreach (['chunk-download.js', 'chunk-download.min.js'] as $lib) {
            $got = $reader($lib);
            check("{$lib}: its own reader takes every piece; total, type, the UTF-8 name",
                ($got['ok'] ?? false) === true && $got['size'] === $total && $got['loaded'] === $total && $got['type'] === 'application/zip' && $got['filename'] === 'Übersicht Plan.zip', json_encode($got, JSON_UNESCAPED_UNICODE));
            check("{$lib}: what it wrote is the archive", @file_get_contents($root . '/node.zip') === file_get_contents($file));
        }
        $setCtl('denied', [7, 2]);
        $got = $reader('chunk-download.js');
        check('… a right withdrawn: the client stops, final, with the server\'s words', ($got['ok'] ?? true) === false && ($got['final'] ?? false) === true && $got['message'] === 'Kein Zugriff mehr auf ein Dokument in diesem ZIP.', json_encode($got, JSON_UNESCAPED_UNICODE));
        $setCtl('denied', [7]);
        $setDoc(2, 'checksum', 'replaced');
        $got = $reader('chunk-download.js');
        check('… a document replaced: the client stops, final, with the server\'s reason', ($got['ok'] ?? true) === false && ($got['final'] ?? false) === true && $got['message'] === '«Bericht.pdf» wurde geändert. Das ZIP muss neu erstellt werden.', json_encode($got, JSON_UNESCAPED_UNICODE));
        $setDoc(2, 'checksum', hash('sha256', $originals[2]));
        $refused = static function (string $kind) use ($root, $port): array {
            $out = [];
            exec(implode(' ', array_map('escapeshellarg', ['node', $root . '/reader.js', str_replace('\\', '/', (string) realpath(__DIR__ . '/../packages/module-dms/res/assets/js/documents/chunk-download.js')), "http://127.0.0.1:{$port}/?refuse={$kind}", $root . '/node.zip', '1024'])), $out);
            return json_decode(implode('', $out), true) ?? [];
        };
        $got = $refused('text');
        check('a refusal that is no JSON: the client\'s own word', ($got['message'] ?? '') === 'Server-Fehler (409)', json_encode($got));
        $got = $refused('noerror');
        check('JSON without a reason: the client\'s own word', ($got['message'] ?? '') === 'Server-Fehler (409)', json_encode($got));
        $got = $refused('html');
        check('the reason stays text: markup untouched for textContent, no control character, cut at 300',
            str_starts_with((string) ($got['message'] ?? ''), '<b>fett</b> x') && !str_contains((string) $got['message'], "\x07") && strlen((string) $got['message']) === 300, json_encode($got));
    }

    [$st, $h, $body] = $http([], $url);
    check('no Range: 200, the whole, no Content-Range', $st === 200 && strlen($body) === $total && !isset($h['content-range']) && ($h['content-length'] ?? '') === (string) $total, "{$st} " . strlen($body));
    check('… the same bytes as the pieces', $body === file_get_contents($file));
    [$st, $h, $body] = $http([], $url, 'HEAD');
    check('HEAD: 200, the total, the ETag, no body', $st === 200 && ($h['content-length'] ?? '') === (string) $total && ($h['etag'] ?? '') === $etag && $body === '', "{$st} " . json_encode($h));
    [$st, $h, $body] = $http(['Range: bytes=0-99'], $url, 'HEAD');
    check('HEAD ignores Range', $st === 200 && ($h['content-length'] ?? '') === (string) $total, (string) $st);
    check('… the policy on the HEAD', ($h['content-security-policy'] ?? '') === 'sandbox' && ($h['x-content-type-options'] ?? '') === 'nosniff', json_encode($h));
    [$st, $h, $body] = $http(['If-None-Match: ' . $etag], $url);
    check('If-None-Match with its ETag: 304, no body', $st === 304 && $body === '', (string) $st);
    check('… the policy on the 304', ($h['content-security-policy'] ?? '') === 'sandbox' && ($h['x-content-type-options'] ?? '') === 'nosniff', json_encode($h));
    [$st] = $http(['If-None-Match: "other"', 'Range: bytes=0-9'], $url);
    check('another ETag: the piece', $st === 206, (string) $st);
    [$st, $h] = $http(['Range: bytes=' . $total . '-'], $url);
    check('an unsatisfiable range: 416 naming the total', $st === 416 && ($h['content-range'] ?? '') === "bytes */{$total}", $st . ' ' . ($h['content-range'] ?? ''));
    check('… the policy on the 416', ($h['content-security-policy'] ?? '') === 'sandbox' && ($h['content-type'] ?? '') === 'application/zip', json_encode($h));
    [$st, $h, $body] = $http(['Range: bytes=-100'], $url);
    check('a suffix range: the last bytes', $st === 206 && $body === substr((string) file_get_contents($file), -100) && str_starts_with($h['content-range'] ?? '', 'bytes ' . ($total - 100) . '-'), (string) $st);
    [$st, , $body] = $http(['Range: bytes=0-1,5-6'], $url);
    check('a multi-range: 200, the whole (as FileResponse)', $st === 200 && strlen($body) === $total, (string) $st);

    // A refused piece, as the client meets it mid-download.
    $mid = 'Range: bytes=350000-350999'; // inside the second document
    [$st] = $http([$mid], $url);
    check('a piece inside doc 2: 206', $st === 206, (string) $st);
    $setCtl('denied', [7, 2]);
    [$st, $h, $body] = $http([$mid], $url);
    check('right withdrawn: 403, JSON, no byte of the archive', $st === 403 && str_contains($body, 'Kein Zugriff') && !str_contains($body, 'PK'), "{$st} {$body}");
    [$st] = $http(['Range: bytes=2000000-2000999'], $url);
    check('… a piece inside another document still answers', $st === 206, (string) $st);
    [$st] = $http(['Range: bytes=0-999'], $url);
    check('… the first piece does not', $st === 403, (string) $st);
    $setCtl('denied', [7]);
    $setDoc(2, 'deleted', true);
    [$st, , $body] = $http([$mid], $url);
    check('document deleted: 409 with a reason', $st === 409 && str_contains($body, 'gelöscht'), "{$st} {$body}");
    $setDoc(2, 'deleted', false);
    $setDoc(2, 'checksum', 'replaced');
    [$st, , $body] = $http([$mid], $url);
    check('document replaced: 409 with a reason', $st === 409 && str_contains($body, 'geändert'), "{$st} {$body}");
    $setDoc(2, 'checksum', hash('sha256', $originals[2]));
    $setCtl('who', 'backend:2');
    [$st] = $http([$mid], $url);
    check('another person: 404', $st === 404, (string) $st);
    $setCtl('who', 'backend:1');
    [$st] = $http([$mid], '?id=' . str_repeat('f', 32));
    check('an unknown id: 404', $st === 404, (string) $st);

    // ByteSourceResponse itself.
    [$st, $h, $body] = $http(['Range: bytes=0-'], '?source=fails');
    check('a source failing while streaming: the status and the promise are out, the body is short',
        $st === 206 && (int) ($h['content-length'] ?? 0) === 3 * 1024 * 1024 && strlen($body) === 1024 * 1024, $st . ' ' . strlen($body));
    check('… nothing but its bytes in it (no error page to fill up the promised length)', $body === str_repeat('z', 1024 * 1024));
    check('… the failure is logged on the server', str_contains((string) @file_get_contents($root . '/server-error.log'), 'the disk went away'));

    // The cap: only the headers are read, the server stops with the closed connection.
    $s = fsockopen('127.0.0.1', $port);
    fwrite($s, "GET /?source=huge HTTP/1.1\r\nHost: 127.0.0.1\r\nRange: bytes=0-\r\nConnection: close\r\n\r\n");
    $headers = '';
    while (!str_contains($headers, "\r\n\r\n") && !feof($s)) {
        $headers .= fread($s, 1024);
    }
    fclose($s);
    check('the same cap as FileResponse: bytes=0- on 1 GiB answers 256 MiB, the true total',
        str_contains($headers, ' 206 ') && str_contains($headers, 'Content-Range: bytes 0-268435455/1073741824') && str_contains($headers, 'Content-Length: 268435456'), substr($headers, 0, 300));
}
proc_terminate($server);
proc_close($server);
check('one constant behind both answers', FileResponse::RANGE_MAX_BYTES === ByteRange::MAX_BYTES && ByteRange::MAX_BYTES === 256 * 1024 * 1024);
check('ByteRange::parse reads the cap by default', ByteRange::parse('bytes=0-', 1 << 30) === [0, ByteRange::MAX_BYTES - 1]);

echo "(L) the production wiring on a temp installation\n";
$site = $root; // ABS_BASE_PATH is this directory, the file drivers write under it
$repo = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
$write = static function (string $relative, string $content) use ($site): void {
    $path = $site . '/' . $relative;
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $content);
};
$write('config/vendor/fileFinder.inc.php', '<?php return ' . var_export([
    'resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'],
    'namespaces'  => [
        'Z77\\Module\\Dms\\' => ['sourcePaths' => [$repo . '/packages/module-dms']],
        'Z77\\Shared\\'      => ['sourcePaths' => [$repo . '/packages/kernel/shared']],
    ],
], true) . ';');
$write('config/vendor/moduleManager.inc.php', '<?php return ' . var_export([
    'modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'dms', 'modules' => ['dms' => []],
], true) . ';');
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => 'https://example.ch', 'baseCurrency' => 'CHF'];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$write('config/client/mail.inc.php', '<?php return ' . var_export([
    'enabled' => true, 'transport' => 'file', 'fromAddress' => 'no-reply@example.ch', 'fromName' => 'Beispiel', 'outbox' => $site . '/outbox',
], true) . ';');

$session = new AuthUser(['id' => 7, 'user_name' => 'max.muster', 'roles' => [\Z77\Core\Config\AuthRole::ADMIN], 'realm' => AuthUser::REALM_BACKEND]);
/** The DI wiring Bootstrap does, reduced to what this reads. Calling it again is the «fresh request». */
$wire = static function () use ($session, $site): void {
\Z77\Core\DI::getInstance(true)
    ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
    ->set('FileFinder', fn ($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
    ->set('ConfigManager', fn ($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
    ->set('ModuleManager', fn ($c) => new \Z77\Core\Services\ModuleManager($c->get('ConfigManager')), true)
    ->set('I18n', fn ($c) => new \Z77\Core\Services\I18n($c->get('ConfigManager')), true)
    ->set('DataSourceResolver', fn () => new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File']), true)
    ->set('UnifiedEntityManager', fn ($c) => new \Z77\Persistence\Resolver\UnifiedEntityManager($c->get('DataSourceResolver')), true)
    ->set('EmailService', fn () => new EmailService(), true)
    ->set('AuthService', fn () => new class ($session) {
        public function __construct(public AuthUser $user) {}
        public function getCurrentUser(): AuthUser { return $this->user; }
    }, true)
    ->set('CurrentUserService', fn () => new class {
        public function getBackendUser(): object { return new class { public function getEmail(): string { return 'max.muster@example.ch'; } }; }
    }, true);
\Z77\Core\DI::getCacheManager()->setCacheDir($site . '/var/cache');
};
$wire();

$wiring = static function () use ($site, $repo, $write, $wire, $blob): void {
    $em = \Z77\Core\DI::getUnifiedEntityManager();
    $docs = [];
    foreach ([['Vertrag Max Muster.pdf', 'real one'], ['Beilage.txt', 'real two']] as [$name, $bytes]) {
        $doc = new Document();
        $doc->setOriginalName($name);
        $doc->setDisplayName($name);
        $doc->setExt(pathinfo($name, PATHINFO_EXTENSION));
        $doc->setChecksum(hash('sha256', $bytes));
        $doc->setSizeBytes(strlen($bytes));
        $doc->setOwnerId(7);
        $doc->setFolderId(1);
        $em->persist($doc);
        $em->flush();
        $blob->put((int) $doc->getId(), BlobStorage::ORIGINAL, $doc->getExt(), $bytes);
        $docs[] = $doc;
    }
    $foreign = new Document();
    $foreign->setOriginalName('Fremd.pdf');
    $foreign->setExt('pdf');
    $foreign->setChecksum('f');
    $foreign->setOwnerId(8);
    $foreign->setFolderId(1);
    $em->persist($foreign);
    $em->flush();
    $blob->put((int) $foreign->getId(), BlobStorage::ORIGINAL, 'pdf', 'fremd');
    $ids = array_map(static fn (Document $d): int => (int) $d->getId(), $docs);

    $service = ZipBundleService::create();
    check('create() builds on the installation: the config reaches it', $service instanceof ZipBundleService);

    $jobs = \Z77\Core\DI::getModuleManager()->getJobs();
    check('the module registry offers both jobs', isset($jobs['dms-zip-prepare'], $jobs['dms-zip-cleanup']), implode(',', array_keys($jobs)));

    // zipInstantBytes from the module config: this selection is small, so it is instant.
    $instant = $service->open(array_merge($ids, [(int) $foreign->getId()]), 'Echt', true);
    check('the real Authz: the owner\'s documents go in, somebody else\'s is only counted', $instant['documents'] === 2 && $instant['denied'] === 1, json_encode($instant));
    check('below zipInstantBytes of the real config: ready at once', $instant['state'] === 'ready', $instant['state']);
    check('expiry from the real zipKeepDays', $instant['expires'] > time() + 6 * 86400 && $instant['expires'] <= time() + 7 * 86400 + 5);
    check('no mail for it, the checkbox or not: it is ready in the answer', $instant['mail']['state'] === 'none' && (glob($site . '/outbox/*.eml') ?: []) === [], json_encode($instant['mail']));
    check('the state through the same wiring', $service->state($instant['id'])['state'] === 'ready');
    check('serve through the same wiring', $service->serve($instant['id'], 'bytes=0-9') instanceof ByteSourceResponse);

    // Above the threshold: the real JobQueue through the real create(). The threshold comes
    // from a project's override of the module config, a fresh request reads it.
    $write('override/module/dms/src/App/Config/dmsConfig.inc.php', "<?php\n\$c = require " . var_export(dirname(__DIR__) . '/packages/module-dms/src/App/Config/dmsConfig.inc.php', true) . ";\n\$c['zipInstantBytes'] = 0;\nreturn \$c;\n");
    $write('config/vendor/fileFinder.inc.php', '<?php return ' . var_export([
        'resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'],
        'namespaces'  => [
            'Z77\\Module\\Dms\\' => ['sourcePaths' => [$site . '/override/module/dms', $repo . '/packages/module-dms']],
            'Z77\\Shared\\'      => ['sourcePaths' => [$repo . '/packages/kernel/shared']],
        ],
    ], true) . ';');
    rrmdir($site . '/var/cache');
    if (function_exists('apcu_clear_cache')) {
        apcu_clear_cache();
    }
    $wire();
    $em = \Z77\Core\DI::getUnifiedEntityManager();
    $direct = ZipBundleService::create();
    $queued = $direct->open($ids, 'Echt', true);
    check('a project\'s override of zipInstantBytes: the job takes it', $queued['state'] === 'preparing', $queued['state']);
    $direct->open($ids);
    $queue = new \Z77\Shared\Jobs\JobQueue($em);
    $open = array_values(array_filter($queue->all(), static fn ($r) => $r->getJobKey() === ZipBundleService::JOB_KEY));
    check('the real job queue: one waiting entry, however many bundles', count($open) === 1, (string) count($open));
    check('… the runner can resolve what it queued', isset($jobs[$open[0]->getJobKey()]) && $jobs[$open[0]->getJobKey()]['class'] === ZipPrepareJob::class);
    // A running entry may have scanned before the next bundle existed: it must not swallow it.
    $open[0]->setState(\Z77\Shared\Entities\JobRun::STATE_RUNNING);
    $queue->save($open[0]);
    $direct->open($ids);
    $after = array_values(array_filter($queue->all(), static fn ($r) => $r->getJobKey() === ZipBundleService::JOB_KEY && $r->getState() === \Z77\Shared\Entities\JobRun::STATE_QUEUED));
    check('… beside a RUNNING entry a new bundle queues its own', count($after) === 1, (string) count($after));

    $actor = new AuthUser(['id' => 'cron', 'user_name' => 'cron', 'realm' => AuthUser::REALM_CRON, 'roles' => [\Z77\Core\Config\AuthRole::CRON_JOB]]);
    $r = (new ZipPrepareJob())->run(new JobContext('dms-zip-prepare', [], null, 1, time() + 3600, $actor));
    check('new ZipPrepareJob() as the runner builds it: done, the bundle ready', $r->isDone() && $direct->state($queued['id'])['state'] === 'ready', $r->getNote());
    check('the mail went out through EmailService (file transport)', $direct->state($queued['id'])['mail']['state'] === 'sent', json_encode($direct->state($queued['id'])['mail']));
    $eml = glob($site . '/outbox/*.eml') ?: [];
    $decoded = quoted_printable_decode((string) @file_get_contents($eml[0] ?? $site . '/none'));
    check('… one mail, the template found through the file finder', count($eml) === 1 && str_contains($decoded, 'Zum ZIP-Download'), (string) count($eml));
    check('… its link, from the canonical base URL', str_contains($decoded, 'https://example.ch' . ZipBundleService::DRIVE_VIEW_ROUTE));
    check('… no document name in it', !str_contains($decoded, 'Vertrag') && !str_contains($decoded, 'Beilage') && !str_contains($decoded, 'Echt'));
    $r = (new ZipCleanupJob())->run(new JobContext('dms-zip-cleanup', [], null, 1, time() + 60, $actor));
    check('new ZipCleanupJob() as the runner builds it: done', $r->isDone(), $r->getNote());

    // ── The owner key, a member against a backend user of the same id ──────────────
    // The identity exactly as MemberAuthBridge → AuthService::establishMemberIdentity() builds it.
    check('the member identity is built as this harness builds it', str_contains((string) file_get_contents($repo . '/packages/kernel/shared/src/Services/AuthService.php'), "'realm'     => AuthUser::REALM_MEMBER,"));
    $member  = new AuthUser(['id' => '7', 'user_name' => 'Max Muster', 'roles' => ['customer'], 'realm' => AuthUser::REALM_MEMBER]);
    $backend = \Z77\Core\DI::getAuthService()->user;
    check('the owner key: realm:id, a different key for the same id', ZipBundleService::ownerKey($member) === 'member:7' && ZipBundleService::ownerKey($backend) === 'backend:7');
    check('nobody has no key', ZipBundleService::ownerKey(new AuthUser()) === '');
    // Both may read this document, so nothing but the key can keep them apart.
    $ace = new \Z77\Module\Dms\Entities\AccessControlEntry();
    $ace->setResourceType('document');
    $ace->setResourceId($ids[0]);
    $ace->setSubjectType('role');
    $ace->setSubjectId('customer');
    $ace->setRights('read');
    $em->persist($ace);
    $em->flush();
    $real = ZipBundleService::create();
    $ofBackend = $real->open([$ids[0]]);
    $real->prepare(static fn (): bool => true);
    $real->prepare(static fn (): bool => true);
    \Z77\Core\DI::getAuthService()->user = $member;
    $ofMember = $real->open([$ids[0]]);
    check('a member with a role ACE opens a bundle of its own', $ofMember['documents'] === 1 && $ofMember['denied'] === 0, json_encode($ofMember));
    $real->prepare(static fn (): bool => true);
    $hit = static fn (callable $fn): int => (static function () use ($fn): int {
        try { $fn(); return 0; } catch (ZipBundleException $e) { return (int) $e->getCode(); }
    })();
    check('member:7 cannot see, download or delete the bundle of backend:7',
        $hit(fn () => $real->state($ofBackend['id'])) === 404 && $hit(fn () => $real->serve($ofBackend['id'], 'bytes=0-9')) === 404
        && $hit(fn () => $real->delete($ofBackend['id'])) === 404 && is_dir($site . '/data/dms/zips/' . $ofBackend['id']));
    check('… nor list it, and it reads its own', array_column($real->list(), 'id') === [$ofMember['id']] && $real->serve($ofMember['id'], 'bytes=0-9') instanceof ByteSourceResponse, json_encode(array_column($real->list(), 'id')));
    \Z77\Core\DI::getAuthService()->user = $backend;
    check('backend:7 cannot see, download or delete the bundle of member:7',
        $hit(fn () => $real->state($ofMember['id'])) === 404 && $hit(fn () => $real->serve($ofMember['id'], 'bytes=0-9')) === 404
        && $hit(fn () => $real->delete($ofMember['id'])) === 404 && is_dir($site . '/data/dms/zips/' . $ofMember['id']));
    check('… nor list it', !in_array($ofMember['id'], array_column($real->list(), 'id'), true) && in_array($ofBackend['id'], array_column($real->list(), 'id'), true));
    check('deleteForOwner(member:7) takes the member\'s, the backend user\'s stays',
        $real->deleteForOwner(ZipBundleService::ownerKey($member)) === 1 && !is_dir($site . '/data/dms/zips/' . $ofMember['id']) && is_dir($site . '/data/dms/zips/' . $ofBackend['id']));
    $em->remove($ace);
    $em->flush();

    // ── A real purge leaves no trace ───────────────────────────────────────────────
    $extra = new Document();
    $extra->setOriginalName('Wegwerf.txt');
    $extra->setExt('txt');
    $extra->setChecksum(hash('sha256', 'weg'));
    $extra->setOwnerId(7);
    $extra->setFolderId(1);
    $em->persist($extra);
    $em->flush();
    $blob->put((int) $extra->getId(), BlobStorage::ORIGINAL, 'txt', 'weg');
    $doomed = $real->open([(int) $extra->getId()]);
    $service = \Z77\Module\Dms\Services\DocumentService::create();
    $service->delete((int) $extra->getId());
    check('a soft delete leaves the bundle, the gate answers for it', is_dir($site . '/data/dms/zips/' . $doomed['id']));
    $service->purge((int) $extra->getId());
    $em->flush();
    check('DocumentService::purge() takes the bundles holding the document', !is_dir($site . '/data/dms/zips/' . $doomed['id']));

    // ── The hooks and the mail from a real dmsConfig ───────────────────────────────
    $outboxBefore = glob($site . '/outbox/*.eml') ?: [];
    $write('override/module/dms/res/view/templates/emails/hostReady.tpl.php', "<p>Fertig bei der Gemeinde: <?= e(\$documents) ?></p>\n<p><a href=\"<?= e(\$link) ?>\">Abholen</a></p>\n");
    $write('override/module/dms/src/App/Config/dmsConfig.inc.php', "<?php\n\$c = require " . var_export(dirname(__DIR__) . '/packages/module-dms/src/App/Config/dmsConfig.inc.php', true) . ";\n"
        . "\$c['zipInstantBytes'] = 0;\n"
        . "\$c['zipMailAddressHook'] = 'ZipHarnessAddress';\n"
        . "\$c['zipDocumentGateHook'] = 'ZipHarnessGate';\n"
        . "\$c['zipDownloadStartedHook'] = 'ZipHarnessStarted';\n"
        . "\$c['zipMail'] = ['host' => ['url' => '/member/main/dokumente', 'ready' => ['subject' => 'Abholbereit', 'template' => 'emails/hostReady']]];\n"
        . "return \$c;\n");
    rrmdir($site . '/var/cache');
    if (function_exists('apcu_clear_cache')) {
        apcu_clear_cache();
    }
    $wire();
    $em   = \Z77\Core\DI::getUnifiedEntityManager();
    $real = ZipBundleService::create();
    ZipHarnessAddress::$address = 'erna.muster@example.ch';
    check('zipMailAddressHook replaces the backend lookup, with the session\'s AuthUser', $real->hasMailAddress() && ZipHarnessAddress::$seen === 'backend:7', (string) ZipHarnessAddress::$seen);
    ZipHarnessAddress::$address = '';
    check('… an empty answer: no address, no mail', !$real->hasMailAddress());
    check('… and a bundle with the checkbox sends none', $real->open([$ids[0]], '', true)['mail']['state'] === 'none');
    ZipHarnessAddress::$address = 'boom';
    check('… a hook that throws: no address, no crash', !$real->hasMailAddress());
    ZipHarnessAddress::$address = 'erna.muster@example.ch';

    ZipHarnessGate::$refused = [$ids[0]];
    check('zipDocumentGateHook, creation: a no ends like a missing right', (static function () use ($real, $ids): bool {
        try { $real->open([$ids[0]]); return false; } catch (ZipBundleException $e) { return $e->getCode() === 422; }
    })());
    check('… it is asked with the document id', in_array($ids[0], ZipHarnessGate::$asked, true));
    ZipHarnessGate::$refused = [];
    ZipHarnessStarted::$calls = [];
    $hosted = $real->open([$ids[0]], '', true, 'host');
    $real->prepare(static fn (): bool => true);
    $hosted = $real->state($hosted['id']);
    check('… yes: the bundle opens', $hosted['state'] === 'ready' && $hosted['documents'] === 1, json_encode($hosted));
    check('… a piece passes', $real->serve($hosted['id'], 'bytes=1-2') instanceof ByteSourceResponse);
    ZipHarnessGate::$refused = [$ids[0]];
    check('… a document refused later fails the piece, as a withdrawn right does (403)', $hit(fn () => $real->serve($hosted['id'], 'bytes=1-2')) === 403);
    ZipHarnessGate::$refused = [];
    ZipHarnessGate::$throw = true;
    check('… a gate that throws fails closed', $hit(fn () => $real->serve($hosted['id'], 'bytes=1-2')) === 403);
    ZipHarnessGate::$throw = false;

    $new = array_values(array_diff(glob($site . '/outbox/*.eml') ?: [], $outboxBefore));
    $text = quoted_printable_decode((string) @file_get_contents($new[0] ?? $site . '/none'));
    check('zipMail, a host\'s profile: its subject, its template, its target, to the hook\'s address',
        count($new) === 1 && str_contains($text, 'Abholbereit') && str_contains($text, 'Fertig bei der Gemeinde') && str_contains($text, 'https://example.ch/member/main/dokumente') && str_contains($text, 'erna.muster@example.ch'), substr($text, 0, 300));

    $real->serve($hosted['id'], 'bytes=0-9');
    $real->serve($hosted['id'], 'bytes=0-9');
    check('zipDownloadStartedHook: once, with the bundle id and document ids', ZipHarnessStarted::$calls === [[$hosted['id'], [$ids[0]]]], json_encode(ZipHarnessStarted::$calls));

    // ── The trait in a host controller: no Drive, no bulkDocs ──────────────────────
    $traitSource = (string) file_get_contents($repo . '/packages/module-dms/src/Ui/ZipBundleTrait.php');
    check('the trait reaches for nothing of the Drive', !preg_match('/bulkDocs|readableDoc|DriveControllerTrait::|\$this->(?!zip|json)\w+/', preg_replace('#/\*\*.*?\*/#s', '', $traitSource)));
    $request = new class {
        public array $get = [];
        public array $body = [];
        public bool $post = false;
        public bool $head = false;
        public string $range = '';
        public function isPost(): bool { return $this->post; }
        public function isHead(): bool { return $this->head; }
        public function getJsonBody(): array { return $this->body; }
        public function getPostParameter(string $k): mixed { return null; }
        public function getGetParameter(string $k): mixed { return $this->get[$k] ?? null; }
        public function getRange(): string { return $this->range; }
        public function getIfNoneMatch(): ?string { return null; }
    };
    \Z77\Core\DI::getInstance()->set('Request', fn () => $request, true);
    $host = new ZipHarnessHost();
    $request->post = true;
    $request->body = ['ids' => (string) $ids[0], 'name' => 'Vom Host', 'notify' => '1'];
    $host->call('zipCreateAction');
    check('zipCreate in a controller of its own: the answer, the profile of that host', $host->status === 200 && ($host->data['ok'] ?? false) === true && ($host->data['name'] ?? '') === 'Vom Host.zip', json_encode($host->data));
    $hid = (string) ($host->data['id'] ?? '');
    ZipBundleService::create()->prepare(static fn (): bool => true);
    check('… the host\'s profile reached the manifest', (manifestAt($site . '/data/dms/zips/' . $hid . '/manifest.json')['mailProfile'] ?? '') === 'host');
    $request->post = false;
    $request->body = [];
    $request->get = ['id' => $hid];
    $host->call('zipStateAction');
    check('zipState', $host->status === 200 && ($host->data['id'] ?? '') === $hid, json_encode($host->data));
    $request->get = [];
    $host->call('zipListAction');
    check('zipList: the session\'s own', $host->status === 200 && in_array($hid, array_column($host->data['bundles'] ?? [], 'id'), true));
    $request->get = ['id' => $hid];
    $request->range = 'bytes=0-9';
    $answer = $host->call('zipDownloadAction');
    check('zipDownload: the typed answer', $answer instanceof ByteSourceResponse);
    $request->get = ['id' => str_repeat('b', 32)];
    $host->call('zipDownloadAction');
    check('… a refusal as JSON with the status and the German reason', $host->status === 404 && ($host->data['error'] ?? '') === 'ZIP nicht gefunden.', json_encode($host->data));
    $request->post = true;
    $request->body = ['id' => $hid];
    $host->call('zipDeleteAction');
    check('zipDelete', $host->status === 200 && !is_dir($site . '/data/dms/zips/' . $hid), json_encode($host->data));
    $request->body = ['ids' => ''];
    $host->call('zipCreateAction');
    check('… a refused zipCreate: 400, German', $host->status === 400 && str_contains((string) ($host->data['error'] ?? ''), 'Keine Dokumente'), json_encode($host->data));

    // A purged trash hands its ids over per batch: a fatal halfway loses at most one batch.
    $batched = ZipBundleService::create()->open([$ids[0]]);
    $size    = (new ReflectionClassConstant(ZipHarnessHost::class, 'ZIP_PURGE_BATCH'))->getValue();
    check('the batch is bounded', $size >= 10 && $size <= 200, (string) $size);
    $host->call('zipNotePurged', $ids[0]);
    for ($i = 2; $i < $size; $i++) {
        $host->call('zipNotePurged', 90_000 + $i);
    }
    check('a batch not yet full leaves the bundle', is_dir($site . '/data/dms/zips/' . $batched['id']));
    $host->call('zipNotePurged', 90_001);
    check('the full batch takes it, inside the loop', !is_dir($site . '/data/dms/zips/' . $batched['id']));
    $batched = ZipBundleService::create()->open([$ids[0]]);
    $host->call('zipNotePurged', $ids[0]);
    $host->call('zipFlushPurged');
    check('the rest goes at the end of the loop', !is_dir($site . '/data/dms/zips/' . $batched['id']));
    \Z77\Core\DI::getInstance(true);
    $host->call('zipNotePurged', $ids[0]);
    $escaped = false;
    try {
        $host->call('zipFlushPurged');
    } catch (Throwable) {
        $escaped = true;
    }
    $wire();
    $em = \Z77\Core\DI::getUnifiedEntityManager();
    check('a failing clean-up does not escape: a purge stays a purge', !$escaped);
    check('… and is logged', str_contains((string) file_get_contents(ini_get('error_log')), 'zip bundles of purged documents'));

    // The daily clean-up reaches an existing installation through the one seed code there is.
    $schedules = new \Z77\Shared\Jobs\JobSchedules($em);
    $registry  = \Z77\Core\DI::getModuleManager()->getJobs();
    check('the module registry carries the schedule', ($registry['dms-zip-cleanup']['defaultSchedule'] ?? '') === 'daily@03:50', json_encode($registry['dms-zip-cleanup'] ?? null));
    check('… and it reads', \Z77\Shared\Jobs\ScheduleExpression::isValid('daily@03:50'));
    check('an installation that knows the job already has no record yet', $schedules->findByJobKey('dms-zip-cleanup') === null);
    $problems = [];
    $seeded   = $schedules->seed($registry, time(), $problems);
    $record   = $schedules->findByJobKey('dms-zip-cleanup');
    check('the next pass seeds it, switched on', $seeded >= 1 && $record !== null && $record->isEnabled() && $record->getExpression() === 'daily@03:50' && $problems === [], json_encode($problems));
    $record->setEnabled(false);
    $schedules->save($record);
    $schedules->seed($registry, time(), $problems);
    check('an operator who switched it off stays obeyed', $schedules->findByJobKey('dms-zip-cleanup')->isEnabled() === false);
    $queue   = new \Z77\Shared\Jobs\JobQueue($em);
    $record->setEnabled(true);
    $schedules->save($record);
    check('a due schedule queues the job', $schedules->enqueueDue($queue, $registry, time() + 2 * 86400, $problems) >= 1 && $queue->hasOpenEntry('dms-zip-cleanup'), json_encode($problems));

    // An account goes, its bundles with it: through the module registry, by the removed identity.
    $plant = static function (string $ownerKey, array $documents = [1]) use ($site): string {
        $id  = bin2hex(random_bytes(16));
        $dir = $site . '/data/dms/zips/' . $id;
        mkdir($dir, 0777, true);
        touch($dir . '/lock');
        file_put_contents($dir . '/manifest.json', (new ReflectionMethod(ZipBundleService::class, 'encode'))->invoke(null, [
            'version' => 1, 'id' => $id, 'owner' => hash('sha256', $ownerKey), 'name' => 'x.zip', 'created' => time(), 'expires' => time() + 86400,
            'state' => 'ready', 'lease' => null, 'entries' => array_map(static fn (int $d): array => ['document' => $d, 'ext' => 'pdf', 'name' => 'n.pdf', 'size' => 1, 'checksum' => 'c', 'mtime' => 1, 'crc' => 1], $documents),
            'skipped' => [], 'denied' => 0, 'cursor' => ['file' => 1, 'offset' => 0, 'hash' => null], 'mail' => ['state' => 'none'], 'mailProfile' => '', 'error' => null,
        ]));
        return $id;
    };
    $there = static fn (string $id): bool => is_dir($site . '/data/dms/zips/' . $id);
    $cfgJobs = \Z77\Core\DI::getModuleManager()->getModuleConfig('dms')?->get('accountRemovedListeners', []) ?? [];
    check('module-dms declares its listener, in the convention of the registries', $cfgJobs === [\Z77\Module\Dms\Services\ZipBundleAccountListener::class], json_encode($cfgJobs));
    // The admin who removes it stays logged in throughout.
    \Z77\Core\DI::getAuthService()->user = new AuthUser(['id' => 1, 'user_name' => 'chef', 'roles' => [\Z77\Core\Config\AuthRole::SUPER_USER], 'realm' => AuthUser::REALM_BACKEND]);
    $b7   = $plant('backend:7');
    $m7   = $plant('member:7');
    $b1   = $plant('backend:1');
    $o1   = $plant('backend:8');
    \Z77\Shared\Auth\AccountRemovedListeners::announce(new AuthUser(['id' => 7, 'realm' => AuthUser::REALM_BACKEND]));
    check('a removed backend account: its bundles go', !$there($b7));
    check('… nobody else\'s: not the member with the same id, not another user', $there($m7) && $there($o1));
    check('… and not the session of the admin who removed it', $there($b1));

    // The real member deletion, with the admin's session still around.
    $memberAccounts = new \Z77\Module\Member\Services\MemberAccounts(new \Z77\Persistence\Resolver\UnifiedEntityManager(new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File'])));
    $account = new \Z77\Module\Member\Entities\MemberAccount();
    (new ReflectionProperty(\Z77\Module\Member\Entities\MemberAccount::class, 'id'))->setValue($account, '7');
    $account->setEmail('max.muster@example.ch');
    $memberAccounts->save($account);
    $backendSeven = $plant('backend:7');
    \Z77\Module\Member\Services\AccountDeletion::create()->delete($account);
    check('the real member deletion: the account is gone', array_filter($memberAccounts->all(), static fn ($a) => (string) $a->getId() === '7') === []);
    check('… and its bundles', !$there($m7));
    check('… nobody else\'s: not the backend user with the same id, not the admin\'s', $there($backendSeven) && $there($o1) && $there($b1));
    check('the bundle service is told nothing by anyone\'s session: a nobody works too', ZipBundleService::ownerKey(new AuthUser(['id' => '7', 'realm' => AuthUser::REALM_MEMBER])) === 'member:7');

    // A listener that throws, one that does not hold: the others run, nothing escapes.
    ZipHarnessRemovedSpy::$seen = [];
    $write('override/module/dms/src/App/Config/accountRemovedListenersConfig.inc.php', "<?php\nreturn ['ZipHarnessRemovedThrows', 'NoSuchListener', 'stdClass', 'ZipHarnessRemovedSpy'];\n");
    $escaped = false;
    try {
        \Z77\Shared\Auth\AccountRemovedListeners::announce(new AuthUser(['id' => 8, 'realm' => AuthUser::REALM_BACKEND]));
    } catch (Throwable $e) {
        $escaped = true;
    }
    check('a listener that throws, one that is missing, one of the wrong type: nothing escapes', !$escaped);
    check('… the others still run, the DMS\'s too', ZipHarnessRemovedSpy::$seen === ['backend:8'] && !$there($o1), json_encode(ZipHarnessRemovedSpy::$seen));
    $log = (string) file_get_contents(ini_get('error_log'));
    check('… each failure is logged', str_contains($log, 'ZipHarnessRemovedThrows failed') && str_contains($log, 'NoSuchListener failed') && str_contains($log, 'stdClass failed'));
    @unlink($site . '/override/module/dms/src/App/Config/accountRemovedListenersConfig.inc.php');

    // Without the DMS: a registry that declares nothing, an announcement that does nothing.
    $write('config/vendor/moduleManager.inc.php', '<?php return ' . var_export(['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'other', 'modules' => []], true) . ';');
    rrmdir($site . '/var/cache');
    if (function_exists('apcu_clear_cache')) {
        apcu_clear_cache();
    }
    \Z77\Core\DI::getInstance(true);
    $wire();
    $stays = $plant('backend:9');
    $noDms = true;
    try {
        \Z77\Shared\Auth\AccountRemovedListeners::announce(new AuthUser(['id' => 9, 'realm' => AuthUser::REALM_BACKEND]));
    } catch (Throwable) {
        $noDms = false;
    }
    check('an installation without the DMS module: nothing runs, nothing fails', $noDms && $there($stays));
    $write('config/vendor/moduleManager.inc.php', '<?php return ' . var_export(['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'dms', 'modules' => ['dms' => []]], true) . ';');
    rrmdir($site . '/var/cache');
    if (function_exists('apcu_clear_cache')) {
        apcu_clear_cache();
    }
    \Z77\Core\DI::getInstance(true);
    $wire();
    $em = \Z77\Core\DI::getUnifiedEntityManager();
    $real = ZipBundleService::create();
    \Z77\Core\DI::getInstance()->set('Request', fn () => $request, true);
    \Z77\Core\DI::getAuthService()->user = new AuthUser(['id' => 7, 'user_name' => 'max.muster', 'roles' => [\Z77\Core\Config\AuthRole::ADMIN], 'realm' => AuthUser::REALM_BACKEND]);
};
try {
    $wiring();
} catch (Throwable $e) {
    check('the production wiring ran through', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

echo "(M) the whole stack: a real login, the Drive's endpoints through the Dispatcher\n";
$flow = static function () use ($root, $repo): void {
    require_once __DIR__ . '/support/real-flow-site.php';
    $site = $root . '/flow';
    $hash = password_hash('Altes-Passwort-123', PASSWORD_BCRYPT, ['cost' => 4]);
    realFlowSite($site, $repo, [
        ['id' => 7, 'username' => 'max', 'name' => 'Max Muster', 'email' => 'max.muster@example.ch', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 0],
        ['id' => 8, 'username' => 'erna', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 1],
        ['id' => 9, 'username' => 'chef', 'password_hash' => $hash, 'roles' => ['superUser'], 'sort_key' => 2],
        ['id' => 10, 'username' => 'otto', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 3],
        ['id' => 11, 'username' => 'lisa', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 4],
    ]);
    // The helper installs frontend + backend; this harness needs the DMS on top.
    $finder = (string) file_get_contents($site . '/config/fileFinder.inc.php');
    file_put_contents($site . '/config/fileFinder.inc.php', str_replace(
        "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),",
        "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),\n        'Z77\\\\Module\\\\Dms\\\\'      => \$entry('module-dms'),",
        $finder
    ));
    $modules = (string) file_get_contents($site . '/config/moduleManager.inc.php');
    file_put_contents($site . '/config/moduleManager.inc.php', (string) preg_replace("/('backend' =>\s*array \(\s*\),)/", "$1\n    'dms' => array (),", $modules));
    // The documents and blobs (L) wrote: real rows, owned by users 7 and 8.
    $copy = static function (string $from, string $to) use (&$copy): void {
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (is_dir($from . '/' . $entry)) {
                @mkdir($to . '/' . $entry, 0777, true);
                $copy($from . '/' . $entry, $to . '/' . $entry);
            } else {
                copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }
    };
    @mkdir($site . '/data/documents', 0777, true);
    @mkdir($site . '/data/blobs', 0777, true);
    $copy($root . '/data/documents', $site . '/data/documents');
    $copy($root . '/data/blobs', $site . '/data/blobs');
    mkdir($root . '/flow-sessions');

    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port  = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    $server = proc_open(
        [PHP_BINARY, '-d', 'apc.enabled=0', '-d', 'opcache.enable=0', '-d', 'session.save_path=' . $root . '/flow-sessions', '-S', "127.0.0.1:{$port}", '-t', $site . '/public', $site . '/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $root . '/flow.log', 'w'], 2 => ['file', $root . '/flow.log', 'a']],
        $pipes,
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

    try {
        /** One browser: a cookie jar, redirects not followed; a fetch() says so in Sec-Fetch-Mode. */
        $browser = static function () use ($port): Closure {
            $cookies = [];
            return static function (string $path, array|string|null $post = null, array $headers = [], string $method = '') use (&$cookies, $port): array {
                $head = $headers;
                if ($cookies !== []) {
                    $head[] = 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($cookies), $cookies));
                }
                $opts = ['ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0, 'max_redirects' => 0, 'method' => $method !== '' ? $method : ($post === null ? 'GET' : 'POST')];
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
        };
        $login = static function (string $user) use ($browser): array {
            $b = $browser();
            [, , $html] = $b('/login');
            preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
            $b('/backend/system/login/login', ['csrf_token' => $m[1] ?? '', 'username' => $user, 'password' => 'Altes-Passwort-123']);
            [$s, , $page] = $b('/backend/system/dashboard/overview');
            preg_match('/name="csrf-token" content="([^"]+)"/', $page, $t);
            return [$b, $t[1] ?? '', $s];
        };
        $fetch = ['Sec-Fetch-Mode: same-origin', 'Accept: application/json'];

        [$max, $token, $s] = $login('max');
        check('a real login', $s === 200 && $token !== '', "{$s} / {$token}");
        $base = '/backend/documents/drive/';

        [$s, , $body] = $max($base . 'zip-create', json_encode(['ids' => '1,2,3', 'name' => 'Echt']), $fetch);
        check('POST zip-create without the CSRF header: refused', $s === 403 || str_contains($body, 'CSRF'), "{$s} " . substr($body, 0, 200));

        $auth = array_merge($fetch, ['X-CSRF-Token: ' . $token]);
        [$s, , $body] = $max($base . 'zip-create', json_encode(['ids' => '1,2,3,9999', 'name' => 'Echt', 'notify' => '1']), $auth);
        $state = json_decode($body, true);
        check('POST zip-create: the service\'s state as JSON', $s === 200 && ($state['ok'] ?? false) === true && ($state['state'] ?? '') === 'ready', "{$s} " . substr($body, 0, 300));
        check('… two documents of Max, the third is Erna\'s and only counted, the unknown id too', ($state['documents'] ?? 0) === 2 && ($state['denied'] ?? 0) === 2, substr($body, 0, 300));
        check('… instant: the checkbox sends no mail, it is ready in the answer', ($state['mail']['state'] ?? '') === 'none' && (glob($site . '/data/framework/mail/outbox/*.eml') ?: []) === [], json_encode($state['mail'] ?? null));
        $id = (string) ($state['id'] ?? '');
        check('the bundle lies under data/dms/zips', is_file($site . '/data/dms/zips/' . $id . '/manifest.json'));

        [$s, , $body] = $max($base . 'zip-state?id=' . $id, null, $fetch);
        check('GET zip-state', $s === 200 && (json_decode($body, true)['state'] ?? '') === 'ready', "{$s} " . substr($body, 0, 200));
        [$s, , $body] = $max($base . 'zip-list', null, $fetch);
        check('GET zip-list', $s === 200 && array_column(json_decode($body, true)['bundles'] ?? [], 'id') === [$id], "{$s} " . substr($body, 0, 200));

        $url = $base . 'zip-download?id=' . $id;
        [$s, $h, $first] = $max($url, null, array_merge($fetch, ['Range: bytes=0-99']));
        check('GET zip-download, a piece, as fetch() asks it: 206 through the Dispatcher', $s === 206 && strlen($first) === 100 && str_starts_with($first, "PK\x03\x04"), "{$s} " . substr($first, 0, 200));
        preg_match('#/(\d+)$#', $h['content-range'] ?? '', $m);
        $total = (int) ($m[1] ?? 0);
        $etag  = $h['etag'] ?? '';
        check('… Content-Range with the true total, an ETag, application/zip', $total > 100 && $etag !== '' && ($h['content-type'] ?? '') === 'application/zip', json_encode($h));
        check('… the DeliveryPolicy through the whole stack', ($h['content-security-policy'] ?? '') === 'sandbox' && ($h['x-content-type-options'] ?? '') === 'nosniff' && str_starts_with($h['content-disposition'] ?? '', 'attachment;'), json_encode($h));
        $assembled = $first;
        $same = true;
        while (strlen($assembled) < $total) {
            $pos = strlen($assembled);
            $end = min($pos + 99, $total - 1);
            [$s, $h, $piece] = $max($url, null, array_merge($fetch, ["Range: bytes={$pos}-{$end}"]));
            $same = $same && $s === 206 && ($h['etag'] ?? '') === $etag && ($h['content-range'] ?? '') === "bytes {$pos}-{$end}/{$total}";
            $assembled .= $piece;
        }
        check('all pieces: 206, the same ETag', $same);
        file_put_contents($root . '/flow.zip', $assembled);
        $zip = new ZipArchive();
        $opened = $zip->open($root . '/flow.zip', ZipArchive::CHECKCONS);
        check('libzip opens what came through the whole stack', $opened === true, var_export($opened, true));
        if ($opened === true) {
            check('… with the real bytes', $zip->getFromName('Vertrag Max Muster.pdf') === 'real one' && $zip->getFromName('Beilage.txt') === 'real two');
            $zip->close();
        }
        [$s, $h, $body] = $max($url, null, $fetch, 'HEAD');
        check('HEAD zip-download: 200, the total, no body', $s === 200 && ($h['content-length'] ?? '') === (string) $total && $body === '', "{$s} " . json_encode($h));

        [$erna, $ernaToken] = $login('erna');
        $ernaAuth = array_merge($fetch, ['X-CSRF-Token: ' . $ernaToken]);
        [$s, , $body] = $erna($url, null, array_merge($fetch, ['Range: bytes=0-99']));
        check('another person through the whole stack: 404 JSON, no byte', $s === 404 && !str_contains($body, 'PK') && str_contains($body, 'nicht gefunden'), "{$s} " . substr($body, 0, 200));
        [$s] = $erna($base . 'zip-state?id=' . $id, null, $fetch);
        check('… zip-state: 404', $s === 404, (string) $s);
        [$s] = $erna($base . 'zip-delete', json_encode(['id' => $id]), $ernaAuth);
        check('… zip-delete: 404, the bundle stays', $s === 404 && is_dir($site . '/data/dms/zips/' . $id), (string) $s);

        $guest = $browser();
        [$s, , $body] = $guest($url, null, ['Range: bytes=0-99']);
        check('no session: no byte', $s !== 206 && !str_starts_with($body, 'PK'), (string) $s);

        // The right withdrawn for real: the document changes its owner in the store.
        $file = $site . '/data/documents/documents.json';
        $rows = (string) file_get_contents($file);
        file_put_contents($file, (string) preg_replace('/"owner_id":\s*7\b/', '"owner_id": 8', $rows));
        [$s, , $body] = $max($url, null, array_merge($fetch, ['Range: bytes=0-99']));
        check('the right withdrawn in the store: the next piece is refused (403)', $s === 403 && str_contains($body, 'Kein Zugriff'), "{$s} " . substr($body, 0, 200));
        file_put_contents($file, $rows);

        [$s] = $max($url, null, array_merge($fetch, ['Range: bytes=0-99']));
        check('… the right back: the piece again', $s === 206, (string) $s);

        [$s, , $body] = $max($base . 'zip-delete', json_encode(['id' => $id]), $auth);
        check('POST zip-delete by the owner', $s === 200 && !is_dir($site . '/data/dms/zips/' . $id), "{$s} " . substr($body, 0, 200));

        // A real removal of a backend user by another: the bundles of the removed one go, by her id.
        $flowPlant = static function (string $ownerKey) use ($site): string {
            $bundle = bin2hex(random_bytes(16));
            $dir    = $site . '/data/dms/zips/' . $bundle;
            mkdir($dir, 0777, true);
            touch($dir . '/lock');
            file_put_contents($dir . '/manifest.json', (new ReflectionMethod(ZipBundleService::class, 'encode'))->invoke(null, [
                'version' => 1, 'id' => $bundle, 'owner' => hash('sha256', $ownerKey), 'name' => 'x.zip', 'created' => time(), 'expires' => time() + 86400,
                'state' => 'ready', 'lease' => null, 'entries' => [['document' => 1, 'ext' => 'pdf', 'name' => 'n.pdf', 'size' => 1, 'checksum' => 'c', 'mtime' => 1, 'crc' => 1]],
                'skipped' => [], 'denied' => 0, 'cursor' => ['file' => 1, 'offset' => 0, 'hash' => null], 'mail' => ['state' => 'none'], 'mailProfile' => '', 'error' => null,
            ]));
            return $bundle;
        };
        $flowThere = static fn (string $bundle): bool => is_dir($site . '/data/dms/zips/' . $bundle);
        [$chef, $chefToken, $s] = $login('chef');
        check('a super user logs in', $s === 200 && $chefToken !== '', (string) $s);
        $removeUser = static function (int $userId) use ($chef, $chefToken, $fetch): array {
            [, , $dialog] = $chef('/backend/system/backend-user/confirm-delete?id=' . $userId, null, $fetch);
            $dialog = stripslashes(html_entity_decode($dialog));
            preg_match('/name="?entity_csrf"?\s+value="([^"]+)"/', $dialog, $m);
            return $chef('/backend/system/backend-user/remove', json_encode(['id' => $userId, 'entity_csrf' => $m[1] ?? '']), array_merge($fetch, ['X-CSRF-Token: ' . $chefToken]));
        };
        $usersFile = $site . '/data/framework/auth/backendUsers.json';
        $ofErna    = $flowPlant('backend:8');
        $ofMember  = $flowPlant('member:8');
        $ofChef    = $flowPlant('backend:9');
        [$s, , $body] = $removeUser(8);
        check('the real removal of a backend user answers', $s === 200 && !str_contains((string) file_get_contents($usersFile), '"erna"'), "{$s} " . substr($body, 0, 200));
        check('… her bundles go', !$flowThere($ofErna));
        check('… not the member with the same id, not the admin who removed her', $flowThere($ofMember) && $flowThere($ofChef));

        // A clean-up that throws, in the real stack: the user is removed all the same.
        $override = $site . '/override/dms/src/App/Config';
        mkdir($override, 0777, true);
        $finderFile = $site . '/config/fileFinder.inc.php';
        $finderWas  = (string) file_get_contents($finderFile);
        file_put_contents($finderFile, str_replace(
            "\$entry('module-dms')",
            "['sourcePaths' => [ABS_BASE_PATH . '/override/dms', \$packages . 'module-dms'], 'assetPaths' => []]",
            $finderWas
        ));
        rrmdir($site . '/var/cache');
        file_put_contents($override . '/accountRemovedListenersConfig.inc.php', <<<'PHP'
<?php
if (!class_exists('ZipFlowRemovedThrows')) {
    final class ZipFlowRemovedThrows implements \Z77\Shared\Auth\AccountRemovedListener
    {
        public function accountRemoved(\Z77\Shared\Auth\AuthUser $account): void
        {
            throw new RuntimeException('the flow clean-up is away');
        }
    }
}
return ['ZipFlowRemovedThrows'];
PHP);
        $ofOtto = $flowPlant('backend:10');
        [$s, , $body] = $removeUser(10);
        check('a clean-up that throws does not stop the removal', $s === 200 && !str_contains((string) file_get_contents($usersFile), '"otto"'), "{$s} " . substr($body, 0, 200));
        check('… the DMS\'s own listener still ran', !$flowThere($ofOtto));
        check('… the throwing one really was called, and logged', str_contains((string) @file_get_contents($root . '/flow.log') . (string) @file_get_contents($site . '/logs/php-error.log'), 'the flow clean-up is away'));
        unlink($override . '/accountRemovedListenersConfig.inc.php');
        file_put_contents($finderFile, $finderWas);
        rrmdir($site . '/var/cache');

        // The installation without the DMS: the same removal, nobody listens.
        $moduleFile = $site . '/config/moduleManager.inc.php';
        $modulesWas = (string) file_get_contents($moduleFile);
        file_put_contents($moduleFile, (string) preg_replace("/\s*'dms' => array \(\),/", '', $modulesWas));
        rrmdir($site . '/var/cache');
        $ofLisa = $flowPlant('backend:11');
        [$s, , $body] = $removeUser(11);
        check('without the DMS module a backend user is removed as ever', $s === 200 && !str_contains((string) file_get_contents($usersFile), '"lisa"'), "{$s} " . substr($body, 0, 200));
        check('… and nothing of the DMS ran', $flowThere($ofLisa));
        file_put_contents($moduleFile, $modulesWas);
        rrmdir($site . '/var/cache');

        // A real «Papierkorb leeren»: the document deleted in the store, the trash purged.
        [$s, , $body] = $max($base . 'zip-create', json_encode(['ids' => '2']), $auth);
        $trashBundle = (string) (json_decode($body, true)['id'] ?? '');
        check('a bundle for the trash test', $trashBundle !== '' && is_dir($site . '/data/dms/zips/' . $trashBundle), substr($body, 0, 200));
        $rows = (string) file_get_contents($file);
        $list = json_decode($rows, true);
        foreach ($list as $i => $row) {
            if ((int) ($row['id'] ?? 0) === 2) {
                $list[$i]['deleted_at'] = date(DATE_ATOM);
            }
        }
        file_put_contents($file, json_encode($list, JSON_PRETTY_PRINT));
        [$s, , $body] = $max($base . 'trash', json_encode(['op' => 'purgeAll']), $auth);
        check('POST trash purgeAll answers', $s === 200, "{$s} " . substr($body, 0, 200));
        check('… the document is gone', !str_contains((string) file_get_contents($file), 'Beilage'));
        check('… and the bundle holding it', !is_dir($site . '/data/dms/zips/' . $trashBundle));
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
};
try {
    $flow();
} catch (Throwable $e) {
    check('the whole stack ran through', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

echo "(N) source guards\n";
$src = static fn (string $rel): string => (string) file_get_contents(__DIR__ . '/../' . $rel);
$trait = $src('packages/module-dms/src/Ui/ZipBundleTrait.php');
check('the trait is mounted in the Drive', str_contains($src('packages/module-dms/src/Ui/DriveControllerTrait.php'), 'use ZipBundleTrait;'));
check('input through the Request only: no superglobal in the trait or the service',
    !preg_match('/\$_(POST|GET|FILES|SERVER)/', $trait) && !preg_match('/\$_(POST|GET|FILES|SERVER)/', $src('packages/module-dms/src/Services/ZipBundleService.php')));
check('the trait instantiates no response (Rule 3)', !preg_match('/new\s+\w*Response/', $trait));
check('the POST endpoints are #[Fetch] (CSRF), the download GET and HEAD',
    preg_match_all("/#\[Fetch, HttpMethod\('POST'\)\]/", $trait) === 2 && str_contains($trait, "#[HttpMethod('GET', 'HEAD')]"));
check('the trait renders no template', !preg_match('/->html\(|TemplateRenderer|\.tpl/', $trait));
check('the hash context only ever as a HashContext', str_contains($src('packages/module-dms/src/Services/ZipBundleService.php'), "['allowed_classes' => [\\HashContext::class]]")
    && substr_count($src('packages/module-dms/src/Services/ZipBundleService.php'), 'unserialize(') === 1);
foreach (['zipReady', 'zipFailed'] as $template) {
    $body = (string) preg_replace('/\/\*\*.*?\*\//s', '', $src("packages/module-dms/res/view/templates/emails/{$template}.tpl.php"));
    check("{$template}: names no document", !preg_match('/name|originalName/i', $body));
    check("{$template}: neutral German, neither du nor Sie", !preg_match('/\b(du|dein\w*|dir|dich|Sie|Ihr\w*|Ihnen)\b/', (string) preg_replace('/<\?.*?\?>/s', '', $body)));
}
check('the service\'s German is neutral too', !preg_match("/'[^']*\\b(Bitte (erstelle|warte)|Dein\\w*|Ihr\\w*|Sie)\\b[^']*'/", $src('packages/module-dms/src/Services/ZipBundleService.php')));
check('ByteSourceResponse sends no type, disposition or nosniff of its own', !preg_match('/header\(\'(Content-Type|Content-Disposition|X-Content-Type)/', $src('packages/kernel/core/src/Http/Response/ByteSourceResponse.php')) && str_contains($src('packages/kernel/core/src/Http/Response/ByteSourceResponse.php'), 'DeliveryPolicy::apply('));
check('the client shows the reason as text only', !preg_match('/innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(/', $src('packages/module-dms/res/assets/js/documents/chunk-download.js')));
check('the Drive hands it to a flash that takes text', str_contains($src('packages/module-dms/res/assets/js/documents/drive.js'), "flash('error', 'Download «' + name + '» fehlgeschlagen: ' + (err && err.message")
    && str_contains($src('packages/kernel/shared/res/assets/js/core.js'), 'textEl.textContent = text;'));
check('the Request offers the header', str_contains($src('packages/kernel/core/src/Http/Request.php'), 'public function getRange(): string'));

echo "(O) host pages\n";
$addDoc(101, 'Hook Eins.pdf', bytesOf(40_000, 'h1'));
$addDoc(102, 'Hook Zwei.pdf', bytesOf(50_000, 'h2'));
$addDoc(103, 'Hook Drei.pdf', bytesOf(60_000, 'h3'));
$setCtl('who', 'backend:50');
$setCtl('email', 'max.muster@example.ch');

// The extra gate, through the same seam create() binds the hook to.
$refusedDocs = [];
$gateAnswer  = null;
$gated = serviceOver($root, ['gate' => static function (int $id) use (&$refusedDocs, &$gateAnswer): mixed {
    if ($gateAnswer instanceof Throwable) {
        throw $gateAnswer;
    }
    return $gateAnswer ?? !in_array($id, $refusedDocs, true);
}] + $options);
$refusedDocs = [102];
$b = $gated->open([101, 102]);
check('gate at creation: a no is counted like a missing right, nothing of it stays', $b['documents'] === 1 && $b['denied'] === 1 && !str_contains((string) file_get_contents($dirOf($b['id']) . '/manifest.json'), 'Hook Zwei'), json_encode($b));
check('gate at creation: nothing left → the same 422 as without rights', refused(fn () => $gated->open([102]), 422));
$refusedDocs = [];
$b = $gated->open([101, 102]);
$gated->prepare($all());
$names = array_column($manifest($b['id'])['entries'], 'name');
$inTwo = 'bytes=' . (30 + strlen($names[0]) + 40_000 + 100) . '-' . (30 + strlen($names[0]) + 40_000 + 200);
check('gate on a piece: all yes, it passes', $gated->serve($b['id'], $inTwo) instanceof ByteSourceResponse);
$refusedDocs = [102];
check('a document refused later fails its piece: the same 403 and words as a withdrawn right',
    refused(fn () => $gated->serve($b['id'], $inTwo), 403) && refusal(fn () => $gated->serve($b['id'], $inTwo)) === '403 Kein Zugriff mehr auf ein Dokument in diesem ZIP.', refusal(fn () => $gated->serve($b['id'], $inTwo)));
check('… and the first piece', refused(fn () => $gated->serve($b['id'], 'bytes=0-9'), 403));
check('… a piece inside the other document passes', $gated->serve($b['id'], 'bytes=100-200') instanceof ByteSourceResponse);
$refusedDocs = [];
foreach ([[1, 'an answer of 1'], ['yes', 'a string']] as [$answer, $label]) {
    $gateAnswer = $answer;
    check("fails closed: {$label} is a no", refused(fn () => $gated->serve($b['id'], 'bytes=0-9'), 403));
}
$gateAnswer = new RuntimeException('the project\'s store is away');
check('fails closed: an exception is a no, not a 500', refused(fn () => $gated->serve($b['id'], 'bytes=0-9'), 403));
check('… and at creation', refused(fn () => $gated->open([101]), 422));
$gateAnswer = null;
$jobGate = serviceOver($root, ['gate' => static fn (): bool => false] + $options);
$setCtl('who', 'backend:51');
$p = $svc->open([101]);
$jobGate->prepare($all());
check('the job has no session and does not ask the gate: the bundle gets ready', $manifest($p['id'])['state'] === 'ready');
check('… the owner still meets it at the piece', refused(fn () => $jobGate->serve($p['id'], 'bytes=0-9'), 403));
$setCtl('who', 'backend:50');

// The started hook.
$started = [];
$startMode = 'ok';
$notified = serviceOver($root, ['started' => static function (string $id, array $docs) use (&$started, &$startMode): void {
    $started[] = [$id, $docs];
    if ($startMode === 'throw') {
        throw new RuntimeException('the project\'s counter is away');
    }
}] + $options);
$b = $notified->open([101, 102, 103]);
$notified->prepare($all());
$bid = $b['id'];
$etagOf = static function () use ($notified, $bid): string {
    return 'zip-' . substr(hash('sha256', $bid . '|' . implode(',', array_column(manifestAt(ABS_BASE_PATH . "/data/dms/zips/{$bid}/manifest.json")['entries'], 'checksum'))), 0, 32);
};
check('not started by opening or preparing', $started === []);
$notified->serve($bid, '', null, true);
check('not by a HEAD', $started === []);
$notified->serve($bid, 'bytes=999999999-');
check('not by an unsatisfiable range', $started === []);
$notified->serve($bid, 'bytes=0-9', '"' . $etagOf() . '"');
check('not by a 304', $started === []);
$setCtl('denied', [7, 102]);
check('not by a refused first piece', refused(fn () => $notified->serve($bid, 'bytes=0-9'), 403) && $started === []);
$setCtl('denied', [7]);
$notified->serve($bid, 'bytes=0-9');
check('the first piece: bundle id and document ids, ids only', $started === [[$bid, [101, 102, 103]]], json_encode($started));
$notified->serve($bid, 'bytes=0-9');
serviceOver($root, ['started' => static function () use (&$started): void { $started[] = 'again'; }] + $options)->serve($bid, 'bytes=0-9');
check('a retry, also in another request, does not call it again', count($started) === 1, json_encode($started));
$w = $notified->open([101]);
$notified->prepare($all());
$notified->serve($w['id']);
check('a whole answer without Range counts as the first piece', count($started) === 2 && $started[1][0] === $w['id']);
$startMode = 'throw';
$f1 = $notified->open([101]);
$notified->prepare($all());
check('a failing hook does not break the download', $notified->serve($f1['id'], 'bytes=0-9') instanceof ByteSourceResponse);
check('… and is logged', str_contains((string) file_get_contents($errorLog), 'zipDownloadStartedHook failed'));
check('… and not called a second time', (static function () use ($notified, $f1, &$started): bool {
    $n = count($started);
    $notified->serve($f1['id'], 'bytes=0-9');
    return count($started) === $n;
})());
$startMode = 'ok';

// A mail in flight must not lose the claim: its record is written after the piece.
$racing = null;
$raceStarted = 0;
$race = serviceOver($root, ['started' => static function () use (&$raceStarted): void { $raceStarted++; }, 'mail' => static function () use (&$racing, &$race): bool {
    $race->serve($racing, 'bytes=0-9');
    return true;
}] + array_diff_key($options, ['mail' => 1]));
$r1 = $race->open([101], '', true);
$racing = $r1['id'];
$race->prepare($all());
$race->serve($racing, 'bytes=0-9');
check('a first piece while the mail goes out: the claim survives the mail\'s record', $raceStarted === 1 && $manifest($racing)['mail']['state'] === 'sent', (string) $raceStarted);

// Real processes at the same moment.
$c = $svc->open([101, 102]);
$svc->prepare($all());
@unlink($root . '/started.log');
$barrier = microtime(true) + 2.0;
$children = [];
for ($i = 0; $i < 8; $i++) {
    $children[] = proc_open([PHP_BINARY, __FILE__, '--child-first-piece', $root, $c['id'], (string) $barrier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$answers = '';
foreach ($children as $i => $child) {
    $answers .= stream_get_contents($pipes[$i][1]);
    fclose($pipes[$i][1]);
    fclose($pipes[$i][2]);
    proc_close($child);
}
$lines = array_values(array_filter(explode("\n", (string) @file_get_contents($root . '/started.log'))));
check('8 concurrent first pieces in 8 processes all answer', $answers === str_repeat('ok', 8), $answers);
check('… and the hook ran once', count($lines) === 1 && $lines[0] === json_encode([$c['id'], [101, 102]]), json_encode($lines));

// The purges.
$namesLeft = static function (string $needle) use ($root): bool {
    foreach (glob($root . '/data/dms/zips/*/manifest.json') ?: [] as $file) {
        if (str_contains((string) file_get_contents($file), $needle)) {
            return true;
        }
    }
    return false;
};
$addDoc(110, 'Purge Vertrag.pdf', bytesOf(1000, 'p1'));
$addDoc(111, 'Purge Beilage.pdf', bytesOf(1000, 'p2'));
$ctl = ctl($root);
$ctl['docs'][112] = ['name' => 'Purge Ohne Original.pdf', 'ext' => 'pdf', 'checksum' => 'x', 'deleted' => false];
saveCtl($root, $ctl);
$setCtl('who', 'backend:60');
$pOne  = $svc->open([110, 111]);              // preparing
$pTwo  = $svc->open([110]);
$svc->prepare($all());                        // ready
$pSkip = $svc->open([111, 112]);              // 112 only in the skip list
$setCtl('who', 'member:61');
$pElse = $svc->open([110]);                   // another owner
$keep  = $svc->open([111]);
$m = $manifest($pTwo['id']);
$m['expires'] = 1;                            // expired, not yet swept: its names are still there
$saveManifest($pTwo['id'], $m);
$setCtl('who', '');
check('deleteForDocuments needs no session: bundles of every owner and state', $svc->deleteForDocuments([110]) === 3 && !is_dir($dirOf($pOne['id'])) && !is_dir($dirOf($pTwo['id'])) && !is_dir($dirOf($pElse['id'])), 'left: ' . (int) is_dir($dirOf($pOne['id'])));
check('… the others stay', is_dir($dirOf($pSkip['id'])) && is_dir($dirOf($keep['id'])));
check('… no manifest names the document any more', !$namesLeft('Purge Vertrag'));
check('a document only in the skip list counts: its name is in there', $svc->deleteForDocuments([112]) === 1 && !is_dir($dirOf($pSkip['id'])) && !$namesLeft('Purge Ohne Original'));
check('nothing to find: 0', $svc->deleteForDocuments([9999]) === 0 && $svc->deleteForDocuments([]) === 0);
$setCtl('who', 'backend:60');
$o1 = $svc->open([111]);
$setCtl('who', 'member:60');
$o2 = $svc->open([111]);
$setCtl('who', '');
$key = ZipBundleService::ownerKey(new AuthUser(['id' => 60, 'realm' => AuthUser::REALM_BACKEND]));
check('deleteForOwner with ownerKey(): that identity\'s, not the member with the same id', $key === 'backend:60' && $svc->deleteForOwner($key) === 1 && !is_dir($dirOf($o1['id'])) && is_dir($dirOf($o2['id'])));
check('an empty key removes nothing', $svc->deleteForOwner('') === 0);
$lockedPurge = $svc->deleteForOwner('member:60');
check('member:60 goes with its own key', $lockedPurge === 1 && !is_dir($dirOf($o2['id'])));

// list().
$setCtl('who', 'backend:70');
check('list: nothing yet', $svc->list() === []);
$clock0 = $clock;
$l1 = $svc->open([101], 'Erstes');
$clock += 5;
$l2 = $svc->open([102], 'Zweites');
$setCtl('who', 'backend:71');
$svc->open([103], 'Fremdes');
$setCtl('who', 'backend:70');
$list = $svc->list();
check('list: only the session\'s own, newest first', array_column($list, 'id') === [$l2['id'], $l1['id']], json_encode(array_column($list, 'name')));
check('… as the view: no owner, no address, no cursor', !isset($list[0]['owner'], $list[0]['cursor'], $list[0]['entries']) && !str_contains(json_encode($list), 'max.muster'));
$m = $manifest($l1['id']);
$m['expires'] = 1;
$saveManifest($l1['id'], $m);
check('… an expired one is gone from the list before any sweep', array_column($svc->list(), 'id') === [$l2['id']] && is_dir($dirOf($l1['id'])));
$setCtl('who', '');
check('… nobody logged in: an empty list', $svc->list() === []);
$setCtl('who', 'backend:70');
check('hasMailAddress follows the seam', $svc->hasMailAddress() === true);
$setCtl('email', '');
check('… no address', $svc->hasMailAddress() === false);
$setCtl('email', 'max.muster@example.ch');

// The failed mail.
$mails = [];
$mailMode = 'ok';
$realSum = hash('sha256', $originals[101]);
/** A bundle whose document changes after it was opened. */
$openFailing = static function (bool $notify, string $profile = '') use ($svc, $setDoc, $realSum): array {
    $setDoc(101, 'checksum', $realSum);
    $b = $svc->open([101], '', $notify, $profile);
    $setDoc(101, 'checksum', 'replaced');
    return $b;
};
$b = $openFailing(true, 'host');
$svc->prepare($all());
$st = $svc->state($b['id']);
check('a failed bundle with a mail requested sends one, kind failed, with the reason, no name',
    $st['state'] === 'failed' && count($mails) === 1 && $mails[0][1]['kind'] === 'failed' && str_contains($mails[0][1]['reason'], 'geändert') && !str_contains(json_encode($mails), 'Hook Eins') && $mails[0][1]['profile'] === 'host', json_encode($mails, JSON_UNESCAPED_UNICODE));
check('… recorded as sent, the address gone', $st['mail']['state'] === 'sent' && !str_contains((string) file_get_contents($dirOf($b['id']) . '/manifest.json'), 'max.muster'));
$svc->prepare($all());
check('… once', count($mails) === 1);
$mails = [];
$b2 = $openFailing(false);
$svc->prepare($all());
check('… without the checkbox none', $mails === [] && $svc->state($b2['id'])['state'] === 'failed');
$mailMode = 'false';
$b3 = $openFailing(true);
$svc->prepare($all());
check('… a failing failed mail is recorded, the job goes on', $svc->state($b3['id'])['mail']['state'] === 'failed' && $svc->state($b3['id'])['error'] !== null);
$mailMode = 'ok';
$mails = [];
$setCtl('email', 'max.muster@example.ch');
$setDoc(101, 'checksum', $realSum);
$failedMessage = ZipBundleService::mailMessage('a@example.ch', 'https://example.ch', ['kind' => 'failed', 'documents' => 1, 'bytes' => 5, 'expires' => 1, 'reason' => 'Ein Dokument wurde inzwischen geändert.']);
check('the failed message: its own neutral subject and template', $failedMessage->getSubject() === 'ZIP-Download fehlgeschlagen' && $failedMessage->getTemplate() === 'emails/zipFailed', $failedMessage->getSubject());
$context = $failedMessage->getContext();
extract($context);
ob_start();
include __DIR__ . '/../packages/module-dms/res/view/templates/emails/zipFailed.tpl.php';
$html = (string) ob_get_clean();
check('the failed template: count, reason, link', str_contains($html, 'konnte nicht erstellt werden') && str_contains($html, 'inzwischen geändert') && str_contains($html, 'href="' . $context['link'] . '"'), $html);

// Mail settings, field by field.
$cfgMail = ['default' => ['url' => '/konto', 'ready' => ['subject' => 'Installation']], 'host' => ['url' => 'https://www.example.ch/dokumente', 'failed' => ['template' => 'emails/hostFailed', 'namespace' => 'Z77\\Module\\Member']]];
$plain = ZipBundleService::mailSettings([]);
check('no zipMail: the framework\'s', $plain['url'] === ZipBundleService::DRIVE_VIEW_ROUTE && $plain['ready']['template'] === 'emails/zipReady' && $plain['failed']['template'] === 'emails/zipFailed');
$inst = ZipBundleService::mailSettings($cfgMail);
check('default: the installation\'s fields, the rest the framework\'s', $inst['url'] === '/konto' && $inst['ready']['subject'] === 'Installation' && $inst['ready']['template'] === 'emails/zipReady');
$hostCfg = ZipBundleService::mailSettings($cfgMail, 'host');
check('a host on top: its fields only', $hostCfg['url'] === 'https://www.example.ch/dokumente' && $hostCfg['ready']['subject'] === 'Installation' && $hostCfg['failed']['template'] === 'emails/hostFailed' && $hostCfg['failed']['subject'] === 'ZIP-Download fehlgeschlagen — {absender}');
check('an unknown profile: the installation\'s', ZipBundleService::mailSettings($cfgMail, 'nope') === $inst);
$msg = ZipBundleService::mailMessage('a@example.ch', '', ['kind' => 'failed', 'documents' => 1, 'bytes' => 1, 'expires' => 1], $hostCfg);
check('an absolute target needs no base URL', $msg->getContext()['link'] === 'https://www.example.ch/dokumente' && $msg->getTemplate() === 'emails/hostFailed' && $msg->getTemplateNameSpace() === 'Z77\\Module\\Member');
$msg = ZipBundleService::mailMessage('a@example.ch', 'https://example.ch/', ['documents' => 1, 'bytes' => 1, 'expires' => 1], $inst);
check('a path gets the canonical origin', $msg->getContext()['link'] === 'https://example.ch/konto' && $msg->getSubject() === 'Installation', $msg->getContext()['link']);
$setCtl('who', 'backend:80');
$bad = $svc->open([102], '', false, '../böse');
check('a profile that is no key is not stored', ($manifest($bad['id'])['mailProfile'] ?? 'x') === '');

echo "(P) concurrency and limits\n";
rrmdir($root . '/data/dms/zips');
$addDoc(201, 'Paar Eins.pdf', bytesOf(300_000, 'r1'));
$addDoc(202, 'Paar Zwei.pdf', bytesOf(300_000, 'r2'));
$addDoc(203, 'Paar Drei.pdf', bytesOf(1_000, 'r3'));
$setCtl('who', 'backend:300');
$setCtl('denied', [7]);
$setCtl('email', 'max.muster@example.ch');
$php     = PHP_BINARY;
$spawn   = static function (array $args) use ($php, $root): array {
    $out = $root . '/child-' . bin2hex(random_bytes(4)) . '.out';
    $h = proc_open(array_merge([$php, __FILE__], $args), [1 => ['file', $out, 'w'], 2 => ['file', $out . '.err', 'w']], $unused);
    return [$h, $out];
};
$finish  = static function (array $child): array {
    $code = proc_close($child[0]);
    return [$code, (string) @file_get_contents($child[1])];
};
$newest  = static function () use ($root): array {
    return array_values(array_filter(scandir($root . '/data/dms/zips') ?: [], static fn ($e) => preg_match('/^[a-f0-9]{32}$/', $e) === 1));
};
$shaOf   = static fn (string $id): string => (string) sha1_file("{$root}/data/dms/zips/{$id}/manifest.json");
$realMail = [];
$realStarted = 0;
$real = serviceOver($root, [
    'mail'    => static function (string $to, array $info) use (&$realMail): bool { $realMail[] = $info['kind']; return true; },
    'started' => static function () use (&$realStarted): void { $realStarted++; },
]);
foreach (['r.hashing', 'r.go', 'j.scanned', 'j.go', 'mail.log'] as $file) {
    @unlink($root . '/' . $file);
}

// S1: a request prepares an instant bundle, a job runs beside it.
$request = $spawn(['--child-instant', $root]);
check('S1 the request reached its count', waitFor($root . '/r.hashing'));
$rid = $newest()[0] ?? '';
$sha = $shaOf($rid);
$counts = $real->prepare($all());
check('S1 a job pass leaves what a request is preparing alone: nothing hashed, not a byte written', $shaOf($rid) === $sha && $manifest($rid)['state'] === 'preparing' && $manifest($rid)['cursor']['offset'] === 0 && ($manifest($rid)['entries'][0]['crc'] ?? null) === null, json_encode($counts));
check('S1 … and comes again while it waits', $counts['open'] === 1 && $counts['ready'] === 0, json_encode($counts));

// The review's sequence: the job has scanned, then the request finishes, a download begins.
$job = $spawn(['--child-job', $root, '1', (string) (64 * 1024)]);
check('S1 the job has made its scan', waitFor($root . '/j.scanned'));
touch($root . '/r.go');
[$code, $out] = $finish($request);
$rstate = (array) json_decode($out, true);
check('S1 the request finished: ready in its answer', $code === 0 && ($rstate['state'] ?? '') === 'ready', "{$code}: {$out}");
$real->serve($rid, 'bytes=0-99');
$settled = $manifest($rid);
$sha     = $shaOf($rid);
touch($root . '/j.go');
[$code, $out] = $finish($job);
$after = $manifest($rid);
check('S1 the job with its old scan does not rewrite a bundle that got ready', $shaOf($rid) === $sha, json_encode(['state' => $after['state'], 'cursor' => $after['cursor']['offset'] ?? null, 'expires' => $after['expires'] - $settled['expires'], 'out' => $out]));
check('S1 … it stays ready, a download goes on', $after['state'] === 'ready' && $real->serve($rid, 'bytes=100-199') instanceof ByteSourceResponse);
check('S1 … the expiry did not start again', $after['expires'] === $settled['expires']);
$real->serve($rid, 'bytes=0-99');
check('S1 … the started hook does not fire a second time', $realStarted === 1, (string) $realStarted);
check('S1 … no mail went out', !is_file($root . '/mail.log') && $realMail === []);

// S1: two job passes on one bundle: the lease, not the runner's lock alone.
foreach (['j.scanned', 'j.go'] as $file) {
    @unlink($root . '/' . $file);
}
$bigJob = serviceOver($root, ['mail' => $options['mail']])->open([201, 202], '', true);
$jobOne = $spawn(['--child-job', $root, '3', (string) (64 * 1024)]); // stops inside the first file
check('S1 a job stands mid-file', waitFor($root . '/j.scanned'));
$sha = $shaOf($bigJob['id']);
$second = $real->prepare($all());
check('S1 a second job pass does not take the bundle from it', $shaOf($bigJob['id']) === $sha && $second['ready'] === 0 && $second['open'] === 1, json_encode($second));
touch($root . '/j.go');
[$code, $out] = $finish($jobOne);
$done = $manifest($bigJob['id']);
check('S1 the first job finishes it, the CRC right', $code === 0 && $done['state'] === 'ready' && $done['entries'][0]['crc'] === (int) hexdec(hash('crc32b', $originals[201])) && $done['entries'][1]['crc'] === (int) hexdec(hash('crc32b', $originals[202])), "{$code}: {$out}");
check('S1 … its one mail', trim((string) @file_get_contents($root . '/mail.log')) === 'ready', (string) @file_get_contents($root . '/mail.log'));
check('S1 the comment no longer claims a single writer', !str_contains((string) file_get_contents(__DIR__ . '/../packages/module-dms/src/Services/ZipBundleService.php'), 'It is the only one that writes a manifest'));

// S1: whoever works on a bundle gives up the moment it is no longer the same bundle it took.
$holderCases = [
    'its lease went to somebody else' => static function (array $m): array { $m['lease']['token'] = 'taken-over'; return $m; },
    'the bundle got ready meanwhile'  => static function (array $m): array { $m['state'] = 'ready'; $m['lease'] = null; return $m; },
    'the progress is not its own'     => static function (array $m): array { $m['cursor']['offset'] += 1; return $m; },
];
foreach ($holderCases as $label => $interfere) {
    rrmdir($root . '/data/dms/zips');
    foreach (['j.scanned', 'j.go'] as $file) {
        @unlink($root . '/' . $file);
    }
    $held = $real->open([201, 202]);
    $job  = $spawn(['--child-job', $root, '3', (string) (64 * 1024)]); // stops mid-file, holding the lease
    waitFor($root . '/j.scanned');
    $saveManifest($held['id'], $interfere($manifest($held['id'])));
    $sha = $shaOf($held['id']);
    touch($root . '/j.go');
    [$code, $out] = $finish($job);
    check("S1 {$label}: the holder writes nothing more", $code === 0 && $shaOf($held['id']) === $sha, "{$code}: {$out}");
}

// M4: a request that dies inside its count.
rrmdir($root . '/data/dms/zips');
$now  = time();
$dead = $spawn(['--child-dies', $root, (string) $now]);
[$code] = $finish($dead);
$did = $newest()[0] ?? '';
$adoptClock = $now;
$adoptQueued = 0;
$adopt = serviceOver($root, ['now' => static function () use (&$adoptClock): int { return $adoptClock; }, 'enqueue' => static function () use (&$adoptQueued): void { $adoptQueued++; }]);
check('M4 the request died, its bundle stands in the middle', $code === 0 && $did !== '' && $manifest($did)['state'] === 'preparing', "{$code} {$did}");
$adopt->state($did);
$c = $adopt->prepare($all());
check('M4 while its claim is young nobody takes it', $manifest($did)['state'] === 'preparing' && $adoptQueued === 0 && $c['ready'] === 0, json_encode($c));
$adoptClock += 3600;
$adopt->state($did);
check('M4 the claim expired: the owner asking for its state queues the job', $adoptQueued === 1, (string) $adoptQueued);
$adoptQueued = 0;
$adopt->list();
check('M4 … a list does', $adoptQueued === 1, (string) $adoptQueued);
$adoptQueued = 0;
$adopt->open([203]);
check('M4 … and so does any new bundle', $adoptQueued >= 1, (string) $adoptQueued);
$c = $adopt->prepare($all());
$m = $manifest($did);
check('M4 the job adopts it, the CRC right, no slot held any more', $m['state'] === 'ready' && $m['entries'][1]['crc'] === (int) hexdec(hash('crc32b', $originals[202])) && $c['ready'] >= 1, json_encode($c));

// M1: the same moment, 8 processes, the limit of 3 unfinished and the cap.
rrmdir($root . '/data/dms/zips');
$race = static function (int $instant, int $perPerson, int $installation) use ($spawn, $finish): array {
    $barrier = (string) (microtime(true) + 2.0);
    $children = [];
    for ($i = 0; $i < 8; $i++) {
        $children[] = $spawn(['--child-open', $root ?? ABS_BASE_PATH, $barrier, (string) $instant, (string) $perPerson, (string) $installation]);
    }
    $answers = [];
    foreach ($children as $child) {
        $answers[] = $finish($child)[1];
    }
    return array_count_values($answers);
};
$r = $race(0, 1000, 1000);
check('M1 8 at once against 3 unfinished: exactly 3 open', ($r['opened'] ?? 0) === 3 && ($r['refused429'] ?? 0) === 5, json_encode($r));
rrmdir($root . '/data/dms/zips');
$r = $race(100 * 1024 * 1024, 2, 1000);
check('M1 8 instant at once against a cap of 2: exactly 2 open', ($r['opened'] ?? 0) === 2 && ($r['refused429'] ?? 0) === 6, json_encode($r));
rrmdir($root . '/data/dms/zips');
$r = $race(100 * 1024 * 1024, 1000, 3);
check('M1 8 at once against an installation cap of 3: exactly 3 open', ($r['opened'] ?? 0) === 3 && ($r['refused429'] ?? 0) === 5, json_encode($r));
rrmdir($root . '/data/dms/zips');

// S2: the caps.
$capped = serviceOver($root, ['instant' => 100 * 1024 * 1024, 'perPerson' => 3] + $options);
$b1 = $capped->open([203]);
$b2 = $capped->open([203]);
$b3 = serviceOver($root, ['instant' => 0, 'perPerson' => 3] + $options)->open([203]);
check('S2 the cap counts instant, ready and preparing bundles alike: a 4th is refused with the limit answer', refused(fn () => $capped->open([203]), 429), refusal(fn () => $capped->open([203])));
$setCtl('who', 'backend:301');
check('S2 … another person has its own', $capped->open([203])['state'] === 'ready');
$setCtl('who', 'backend:300');
$capped->delete($b1['id']);
check('S2 … a deleted bundle frees its place', $capped->open([203])['state'] === 'ready');
$m = $manifest($b2['id']);
$m['expires'] = 1;
$saveManifest($b2['id'], $m);
check('S2 … an expired one too, and the opening sweeps it', $capped->open([203])['state'] === 'ready' && !is_dir($dirOf($b2['id'])));
rrmdir($root . '/data/dms/zips');
$manyPeople = serviceOver($root, ['instant' => 100 * 1024 * 1024, 'installation' => 3] + $options);
foreach (['a', 'b', 'c'] as $who) {
    $setCtl('who', 'backend:' . $who);
    $manyPeople->open([203]);
}
$setCtl('who', 'backend:d');
check('S2 the installation cap holds across persons', refused(fn () => $manyPeople->open([203]), 429), refusal(fn () => $manyPeople->open([203])));
$setCtl('who', 'backend:300');
rrmdir($root . '/data/dms/zips');

// S2: the pile. Full manifests, 500 documents each: the size that was an uncatchable fatal.
$pileEntries = [];
for ($i = 0; $i < 500; $i++) {
    $pileEntries[] = ['document' => 100_000 + $i, 'ext' => 'pdf', 'name' => sprintf('Dokument Nummer %05d mit einem langen Namen.pdf', $i), 'size' => 1000, 'checksum' => hash('sha256', (string) $i), 'mtime' => time(), 'crc' => 5];
}
$pile = ['version' => 1, 'owner' => hash('sha256', 'backend:900'), 'name' => 'Stapel.zip', 'created' => time(), 'expires' => time() + 3650 * 86400, 'state' => 'ready', 'entries' => $pileEntries, 'skipped' => [], 'denied' => 0, 'cursor' => ['file' => 500, 'offset' => 0, 'hash' => null], 'mail' => ['state' => 'none'], 'mailProfile' => '', 'error' => null];
mkdir($root . '/data/dms/zips', 0777, true);
for ($i = 0; $i < 1500; $i++) {
    $pid = sprintf('%032x', $i + 1);
    mkdir($dirOf($pid));
    $pile['id'] = $pid;
    file_put_contents($dirOf($pid) . '/lock', '');
    file_put_contents($dirOf($pid) . '/manifest.json', $encodeManifest($pile));
}
$setCtl('who', 'backend:900');
$child = proc_open([$php, '-d', 'memory_limit=48M', __FILE__, '--child-pile', $root], [1 => ['file', $root . '/pile.out', 'w'], 2 => ['file', $root . '/pile.err', 'w']], $unused);
$code = proc_close($child);
$pileAnswer = (array) json_decode((string) @file_get_contents($root . '/pile.out'), true);
check('S2 1500 full manifests under memory_limit=48M: a pass, a list, a sweep and a purge run through', $code === 0 && ($pileAnswer['list'] ?? 0) === 1500 && ($pileAnswer['purged'] ?? -1) === 0, "{$code}: " . substr((string) @file_get_contents($root . '/pile.out') . (string) @file_get_contents($root . '/pile.err'), 0, 300));
echo '  ...  peak memory of that child: ' . round(($pileAnswer['peak'] ?? 0) / 1048576, 1) . " MB\n";
$setCtl('who', 'backend:901');
$t0 = microtime(true);
$pileOpen = serviceOver($root, ['instant' => 100 * 1024 * 1024] + $options)->open([203]);
echo sprintf("  ...  open() with 1500 manifests beside it: %.0f ms\n", (microtime(true) - $t0) * 1000);
$t0 = microtime(true);
$removed = $svc->deleteForDocuments(range(100_000, 100_499));
echo sprintf("  ...  deleteForDocuments(500 ids) over 1500 manifests, in one call: %.0f ms\n", (microtime(true) - $t0) * 1000);
check('S2 the batch takes the whole pile in one call', $removed === 1500, (string) $removed);
rrmdir($root . '/data/dms/zips');
$driveSource = str_replace("\r\n", "\n", $src('packages/module-dms/src/Ui/DriveControllerTrait.php'));
check('S2 a purged trash asks the bundles per batch, inside its loop, the rest after it', (bool) preg_match('/withBundles: false\);\s+\/\/[^\n]*\n\s+\$this->zipNotePurged\(/', $driveSource) && str_contains($driveSource, '$this->zipFlushPurged();'));
$setCtl('who', 'backend:300');

// S3.
$mails = [];
$mailMode = 'ok';
$instantMail = serviceOver($root, ['instant' => 100 * 1024 * 1024] + $options);
$b = $instantMail->open([203], '', true);
check('S3 an instant bundle sends no mail, the checkbox or not: it is ready in the answer', $mails === [] && $b['state'] === 'ready' && $b['mail']['state'] === 'none', json_encode($b['mail']));
check('S3 … and keeps no address', !str_contains((string) file_get_contents($dirOf($b['id']) . '/manifest.json'), 'max.muster'));
$instantMail->prepare($all());
check('S3 … the job has none to send', $mails === []);
$seenLookups = 0;
$failingInstant = serviceOver($root, ['instant' => 100 * 1024 * 1024, 'document' => static function (int $id) use ($root, &$seenLookups): ?Document {
    $d = lookup($root, $id);
    if (++$seenLookups > 1 && $d !== null) {
        $d->setChecksum('replaced');
    }
    return $d;
}] + $options);
$b = $failingInstant->open([203], '', true);
check('S3 a failed instant bundle answers in the request, no mail either', $b['state'] === 'failed' && $mails === [] && $b['error'] !== null, json_encode($b));
$jobFail = $svc->open([203], '', true);
$setDoc(203, 'checksum', 'replaced');
$svc->prepare($all());
check('S3 the job path keeps the decision: a failed bundle mails when asked', count($mails) === 1 && $mails[0][1]['kind'] === 'failed', json_encode($mails));
$setDoc(203, 'checksum', hash('sha256', $originals[203]));

// M2.
$fired = [];
$fromOne = serviceOver($root, ['started' => static function (string $id) use (&$fired): void { $fired[] = $id; }] + $options);
$b = $fromOne->open([201]);
$fromOne->prepare($all());
$fromOne->serve($b['id'], 'bytes=1-');
check('M2 the first piece answered counts, whatever its start', $fired === [$b['id']], json_encode($fired));
$fromOne->serve($b['id'], 'bytes=0-9');
$fromOne->serve($b['id'], 'bytes=5-9');
check('M2 … still once', count($fired) === 1, json_encode($fired));
$b = $fromOne->open([201]);
$fromOne->prepare($all());
$fromOne->serve($b['id'], 'bytes=99999999-');
$fromOne->serve($b['id'], '', null, true);
check('M2 … a 416 and a HEAD still answer no piece', count($fired) === 1, json_encode($fired));

// M3: atomic, and a failing write hurts only its own bundle.
$poison = static fn (string $id): bool => mkdir($dirOf($id) . '/manifest.tmp');
$cure   = static fn (string $id): bool => @rmdir($dirOf($id) . '/manifest.tmp');
$first  = $svc->open([201]);
$next   = $svc->open([202]);
$poison($first['id']);
$sha = $shaOf($first['id']);
$threw = false;
try {
    $c = $svc->prepare($all());
} catch (Throwable $e) {
    $threw = true;
    $c = [];
}
check('M3 the manifest is replaced through a temp file: a write that cannot leaves it as it was', $shaOf($first['id']) === $sha, json_encode($manifest($first['id'])['cursor'] ?? null));
check('M3 a failing write does not abort the pass', !$threw);
check('M3 … the next bundle gets ready', $manifest($next['id'])['state'] === 'ready' && ($c['ready'] ?? 0) >= 1, json_encode($c));
check('M3 … the job comes again for the first', ($c['open'] ?? 0) >= 1, json_encode($c));
$cure($first['id']);
$svc->prepare($all());
check('M3 … and gets it ready once it can write', $manifest($first['id'])['state'] === 'ready', $manifest($first['id'])['state']);
check('M3 no temp file stays behind', !is_file($dirOf($first['id']) . '/manifest.tmp'));

$broken = null;
$brokenMail = serviceOver($root, ['mail' => static function () use (&$broken, $poison): bool {
    $poison($broken);
    return true;
}] + array_diff_key($options, ['mail' => 1]));
$b = $brokenMail->open([201], '', true);
$broken = $b['id'];
$threw = false;
try {
    $c = $brokenMail->prepare($all());
} catch (Throwable $e) {
    $threw = true;
}
check('M3 a mail state that cannot be recorded does not mark a READY bundle failed', !$threw && $manifest($b['id'])['state'] === 'ready' && $manifest($b['id'])['error'] === null, json_encode(['threw' => $threw, 'state' => $manifest($b['id'])['state']]));
$cure($b['id']);
$brokenMail->prepare($all());
check('M3 … it stays usable, the interrupted mail is recorded and not repeated', $manifest($b['id'])['state'] === 'ready' && $manifest($b['id'])['mail']['state'] === 'failed', json_encode($manifest($b['id'])['mail']));

$failMethod = new ReflectionMethod(ZipBundleService::class, 'fail');
$answer = $failMethod->invoke($brokenMail, $b['id'], $manifest($b['id']), 'whoever', 'Das ZIP konnte nicht erstellt werden.');
check('M3 recording a failure cannot touch a READY bundle: it needs the lease of a bundle in preparation', $answer === 'lost' && $manifest($b['id'])['state'] === 'ready' && $manifest($b['id'])['error'] === null, json_encode([$answer, $manifest($b['id'])['state']]));

// A write that fails inside an instant count: the request answers, the bundle waits for the job.
$lookupsSeen = 0;
$dirsBefore  = glob($root . '/data/dms/zips/*', GLOB_ONLYDIR) ?: [];
$instantBroken = serviceOver($root, ['instant' => 100 * 1024 * 1024, 'document' => static function (int $id) use ($root, &$lookupsSeen, $dirsBefore): ?Document {
    if (++$lookupsSeen === 2) { // the count begins: this bundle can no longer be written
        $fresh = array_values(array_diff(glob($root . '/data/dms/zips/*', GLOB_ONLYDIR) ?: [], $dirsBefore));
        mkdir($fresh[0] . '/manifest.tmp');
    }
    return lookup($root, $id);
}]);
$answerOk = true;
try {
    $b = $instantBroken->open([203]);
} catch (Throwable $e) {
    $answerOk = false;
    $b = [];
}
check('M3 a write failing inside an instant count is no 500: the request answers, the bundle still preparing', $answerOk && ($b['state'] ?? '') === 'preparing', $answerOk ? json_encode($b) : 'it threw');
if ($answerOk) {
    @rmdir($dirOf($b['id']) . '/manifest.tmp');
}

// Readers beside a writer that replaces the file hundreds of times.
rrmdir($root . '/data/dms/zips');
$b = $real->open([201, 202]);
$writer = $spawn(['--child-prepare', $root, '100000', '1024']);
$reads = 0;
$bad = 0;
$lastDone = -1;
$deadline = microtime(true) + 60;
do {
    try {
        $state = $real->state($b['id']);
        $reads++;
        if ($state['done'] < $lastDone) {
            $bad++;
        }
        $lastDone = $state['done'];
    } catch (Throwable $e) {
        $bad++;
    }
} while ($state['state'] === 'preparing' && microtime(true) < $deadline);
[$code] = $finish($writer);
check('M3 the lock holds over a replaced file: every read of a state during ~600 writes is whole and in order', $bad === 0 && $reads > 10 && $state['state'] === 'ready', "reads {$reads}, bad {$bad}, state {$state['state']}");
check('M3 … the writer got through', $code === 0 && $manifest($b['id'])['entries'][1]['crc'] === (int) hexdec(hash('crc32b', $originals[202])));

// M7.
$addDoc(210, 'Geheim spaeter.pdf', '');
$blob->delete(210);
$b = $svc->open([201, 210]);
check('M7 readable, no original: named', ($b['skipped'][0]['name'] ?? '') === 'Geheim spaeter.pdf', json_encode($b['skipped']));
$setCtl('denied', [7, 210]);
$st = $svc->state($b['id']);
check('M7 `read` withdrawn: state() no longer names it, counts it', !str_contains(json_encode($st), 'Geheim') && $st['skipped'] === [] && $st['denied'] === $b['denied'] + 1, json_encode($st));
check('M7 … nor does list()', !str_contains(json_encode($svc->list()), 'Geheim'));
$setCtl('denied', [7]);
check('M7 … the right back: named again', ($svc->state($b['id'])['skipped'][0]['name'] ?? '') === 'Geheim spaeter.pdf');

// M10.
$idPattern = (new ReflectionClassConstant(ZipBundleService::class, 'ID'))->getValue();
check('M10 an id with a line break behind it is no id', preg_match($idPattern, str_repeat('a', 32) . "\n") !== 1);

echo "(Q) recovery and clean-up\n";
rrmdir($root . '/data/dms/zips');
$setCtl('who', 'backend:400');
$setCtl('denied', [7]);
foreach ([201, 202, 203] as $doc) {
    $setDoc($doc, 'checksum', hash('sha256', $originals[$doc]));
}
$qQueued = 0;
$qClock  = time();
$qOptions = ['now' => static function () use (&$qClock): int { return $qClock; }, 'enqueue' => static function () use (&$qQueued): void { $qQueued++; }, 'mail' => $options['mail']];
$q = serviceOver($root, $qOptions);
$leftovers = static function () use ($root, $dirOf, $encodeManifest, $manifest): array {
    $broken   = str_repeat('a', 32);
    $lockless = str_repeat('b', 32);
    $empty    = str_repeat('c', 32);
    mkdir($dirOf($broken), 0777, true);
    touch($dirOf($broken) . '/lock');
    file_put_contents($dirOf($broken) . '/manifest.json', '{broken');
    mkdir($dirOf($lockless));
    $m = ['version' => 1, 'id' => $lockless, 'owner' => hash('sha256', 'backend:401'), 'name' => 'x.zip', 'created' => time(), 'expires' => time() + 86400, 'state' => 'ready', 'entries' => [['document' => 203, 'ext' => 'pdf', 'name' => 'Ohne Schloss Name.pdf', 'size' => 1000, 'checksum' => 'c', 'mtime' => 1, 'crc' => 5]], 'skipped' => [], 'denied' => 0, 'cursor' => ['file' => 1, 'offset' => 0, 'hash' => null], 'mail' => ['state' => 'none'], 'mailProfile' => '', 'error' => null];
    file_put_contents($dirOf($lockless) . '/manifest.json', $encodeManifest($m));
    mkdir($dirOf($empty));
    return [$broken, $lockless, $empty];
};

// 1. Leftovers.
mkdir($root . '/data/dms/zips', 0777, true);
[$broken, $lockless, $empty] = $leftovers();
$capped = serviceOver($root, ['instant' => 100 * 1024 * 1024, 'installation' => 2] + $qOptions);
try { $opened = $capped->open([203]); } catch (ZipBundleException) { $opened = []; }
check('1 leftovers count towards no cap: with 3 of them and a cap of 2 a bundle opens', ($opened['state'] ?? '') === 'ready', refusal(fn () => $capped->open([203])));
check('1 … one that may still be in the making stays', is_dir($dirOf($broken)) && is_dir($dirOf($lockless)) && is_dir($dirOf($empty)));
$qClock = time() + 3600;
try { $capped->open([203]); } catch (ZipBundleException) {}
check('1 past a short age the bounded sweep of open() takes a broken manifest', !is_dir($dirOf($broken)));
check('1 … an empty directory', !is_dir($dirOf($empty)));
check('1 … and a manifest without a lock', !is_dir($dirOf($lockless)));
$qClock = time();
rrmdir($root . '/data/dms/zips');
mkdir($root . '/data/dms/zips', 0777, true);
[$broken, $lockless, $empty] = $leftovers();
check('1 deleteForDocuments takes a lock-less manifest that still parses, fresh or not', $q->deleteForDocuments([203]) === 1 && !is_dir($dirOf($lockless)), (string) is_dir($dirOf($lockless)));
rrmdir($dirOf($broken));
rrmdir($dirOf($empty));
[$broken, $lockless, $empty] = $leftovers();
check('1 deleteForOwner too', $q->deleteForOwner('backend:401') === 1 && !is_dir($dirOf($lockless)));
rrmdir($root . '/data/dms/zips');
$held = $q->open([203]);
$m = $manifest($held['id']);
$m['expires'] = 1;
$saveManifest($held['id'], $m);
@unlink($root . '/held');
$holder = $spawn(['--child-holds', $dirOf($held['id']) . '/manifest.json', $root . '/held']);
waitFor($root . '/held');
$swept = $q->sweep();
$finish($holder);
check('1 sweep() gets past a file still open elsewhere for a moment', $swept === 1 && !is_dir($dirOf($held['id'])), "swept {$swept}, there " . (int) is_dir($dirOf($held['id'])));

// 2 + 5. Preparing, no live holder, no entry in the queue.
rrmdir($root . '/data/dms/zips');
$first = $q->open([201]);
$q->open([202]);
$q->open([203]);
$qQueued = 0; // the entry they queued is gone (removed by hand, failed three times, a lost file)
$q->state($first['id']);
check('2 no lease and no entry: state() queues the job', $qQueued === 1, (string) $qQueued);
$qQueued = 0;
$q->list();
check('2 … list() does', $qQueued === 1, (string) $qQueued);
$qQueued = 0;
check('5 a refused open() does too: the person is told to wait and the wait now ends', refused(fn () => $q->open([201]), 429) && $qQueued === 1, (string) $qQueued);
$qQueued = 0;
$instantBeside = serviceOver($root, ['instant' => 100 * 1024 * 1024] + $qOptions)->open([203]);
check('2 … an instant open() beside them does', $qQueued === 1 && $instantBeside['state'] === 'ready', (string) $qQueued);
$q->prepare($all());
$qQueued = 0;
$q->state($first['id']);
$q->list();
check('2 a ready bundle queues nothing', $qQueued === 0, (string) $qQueued);
$jobComment = $src('packages/module-dms/src/Jobs/ZipPrepareJob.php');
check('2 the job\'s comment says what is true', !str_contains($jobComment, 'and the next new bundle queues it anew'));

// The real closure is idempotent: one waiting entry (section (L) runs it against the real queue).
check('2 create() queues only beside no waiting entry', str_contains($src('packages/module-dms/src/Services/ZipBundleService.php'), '$run->getState() === JobRun::STATE_QUEUED'));

// 3. The claim of the started hook.
rrmdir($root . '/data/dms/zips');
$hooked = 0;
$hookedSvc = serviceOver($root, ['started' => static function () use (&$hooked): void { $hooked++; }] + $qOptions);
$b = $hookedSvc->open([203]);
$hookedSvc->prepare($all());
mkdir($dirOf($b['id']) . '/manifest.tmp');
$answered = null;
try {
    $answered = $hookedSvc->serve($b['id'], 'bytes=0-9');
} catch (Throwable $e) {
    $answered = $e;
}
check('3 a claim that cannot be saved does not break the download', $answered instanceof ByteSourceResponse, $answered instanceof Throwable ? $answered->getMessage() : '');
check('3 … the hook does not fire: once-only could not be promised', $hooked === 0, (string) $hooked);
check('3 … it is logged', str_contains((string) file_get_contents($errorLog), 'started claim could not be saved'));
rmdir($dirOf($b['id']) . '/manifest.tmp');
$hookedSvc->serve($b['id'], 'bytes=0-9');
$hookedSvc->serve($b['id'], 'bytes=0-9');
check('3 once it can be saved, the next piece fires it, once', $hooked === 1, (string) $hooked);

// 6. What lies expired.
rrmdir($root . '/data/dms/zips');
$b = $q->open([203], 'Abgelaufen', true);
$m = $manifest($b['id']);
$m['expires'] = $qClock - 1;
$saveManifest($b['id'], $m);
$q->prepare($all());
check('6 a pass that sees it expired drops a pending address at once', !str_contains((string) file_get_contents($dirOf($b['id']) . '/manifest.json'), 'max.muster') && $manifest($b['id'])['mail']['state'] === 'none', (string) file_get_contents($dirOf($b['id']) . '/manifest.json'));
$jobRun = (new ZipCleanupJob($q))->run(new JobContext('dms-zip-cleanup', [], null, 1, time() + 60, $actor));
check('6 the daily job removes it, names and all', $jobRun->isDone() && !is_dir($dirOf($b['id'])), $jobRun->getNote());
$live = $q->open([203]);
(new ZipCleanupJob($q))->run(new JobContext('dms-zip-cleanup', [], null, 1, time() + 60, $actor));
check('6 … and nothing else', is_dir($dirOf($live['id'])));

// 7. A lease written while the clock was ahead.
rrmdir($root . '/data/dms/zips');
$b = $q->open([203]);
$m = $manifest($b['id']);
$m['lease'] = ['token' => 'written-ahead', 'until' => $qClock + 2 * 86400];
$saveManifest($b['id'], $m);
$qQueued = 0;
$q->state($b['id']);
check('7 a lease further ahead than a lease can be counts as run out: state() queues the job', $qQueued === 1, (string) $qQueued);
$c = $q->prepare($all());
check('7 … the job adopts it', $manifest($b['id'])['state'] === 'ready' && $c['ready'] === 1, json_encode($c));
$b = $q->open([203]);
$m = $manifest($b['id']);
$m['lease'] = ['token' => 'in-time', 'until' => $qClock + 299];
$saveManifest($b['id'], $m);
$c = $q->prepare($all());
check('7 a lease within its length still counts as busy', $manifest($b['id'])['state'] === 'preparing' && $c['open'] === 1, json_encode($c));

$src0 = $src('packages/module-dms/src/App/Config/dmsConfig.inc.php');
check('zipMaxBundles stands as a documented default', str_contains($src0, "'zipMaxBundles' => 2000,") && str_contains($src0, 'protects'));
rrmdir($root . '/data/dms/zips');

check('the config offers the caps', ($cfg['zipMaxBundlesPerPerson'] ?? 0) === 20 && ($cfg['zipMaxBundles'] ?? 0) === 2000, json_encode([$cfg['zipMaxBundlesPerPerson'] ?? null, $cfg['zipMaxBundles'] ?? null]));

// zipDownloadEachHook: every download from byte 0, for a project that records each one.
$eachCalls = [];
$eachMode  = 'record';
$recording = serviceOver($root, ['each' => static function (string $id, array $ids) use (&$eachCalls, &$eachMode): void {
    if ($eachMode === 'throw') {
        throw new RuntimeException('the project\'s record is away');
    }
    $eachCalls[] = [$id, $ids];
}] + $options);
$e1 = $recording->open([101, 102]);
$recording->prepare($all());
$eid = $e1['id'];
check('each: not by opening or preparing', $eachCalls === []);
$recording->serve($eid, '', null, true);
check('each: not by a HEAD', $eachCalls === []);
$recording->serve($eid, 'bytes=999999999-');
check('each: not by an unsatisfiable range', $eachCalls === []);
$eachEtag = 'zip-' . substr(hash('sha256', $eid . '|' . implode(',', array_column(manifestAt(ABS_BASE_PATH . "/data/dms/zips/{$eid}/manifest.json")['entries'], 'checksum'))), 0, 32);
$recording->serve($eid, 'bytes=0-9', '"' . $eachEtag . '"');
check('each: not by a 304', $eachCalls === []);
$setCtl('denied', [7, 102]);
check('each: not by a refused piece', refused(fn () => $recording->serve($eid, 'bytes=0-9'), 403) && $eachCalls === []);
$setCtl('denied', [7]);
$recording->serve($eid, 'bytes=0-9');
check('each: the first piece, bundle id and document ids', $eachCalls === [[$eid, [101, 102]]], json_encode($eachCalls));
$recording->serve($eid, 'bytes=10-19');
check('each: not by a later piece', count($eachCalls) === 1, json_encode($eachCalls));
$recording->serve($eid, 'bytes=0-9');
check('each: a second download is recorded again', count($eachCalls) === 2, json_encode($eachCalls));
$recording->serve($eid);
check('each: a whole answer without Range is one too', count($eachCalls) === 3, json_encode($eachCalls));
$eachMode = 'throw';
check('each: a failing hook does not break the download', $recording->serve($eid, 'bytes=0-9') instanceof ByteSourceResponse);
check('each: … and is logged', str_contains((string) file_get_contents($errorLog), 'zipDownloadEachHook failed'));

check('the config offers the hooks, all off', array_key_exists('zipDownloadEachHook', $cfg) && $cfg['zipDownloadEachHook'] === null && array_key_exists('zipMailAddressHook', $cfg) && $cfg['zipMailAddressHook'] === null && array_key_exists('zipDocumentGateHook', $cfg) && $cfg['zipDocumentGateHook'] === null
    && array_key_exists('zipDownloadStartedHook', $cfg) && $cfg['zipDownloadStartedHook'] === null && ($cfg['zipMail'] ?? 1) === []);

echo "\n{$pass} ok, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
