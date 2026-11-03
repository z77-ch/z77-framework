<?php

/**
 * DMS activity report harness (CLI, temp installation, PHP's built-in server) — DMS-ACTIVITY-001.
 *
 * `dmsConfig['activityHook']` receives every file action, ids only, from inside the services. The
 * hook of this harness is written the way a project writes one; at the moment it is called it also
 * reads the store from the disk, so a report sent before the action was really done is caught. What
 * is load-bearing:
 *
 *   (A) `ActivityLog` alone: off means no call and no identity lookup; ids only; a throwing hook, a
 *       missing class, a wrong type and a class that cannot be built never reach the caller and are
 *       logged; one instance per process;
 *   (B) the Drive through the real Bootstrap, Dispatcher, login and CSRF: folders created, renamed,
 *       moved, deleted; a document uploaded, overwritten, renamed, moved (with its source), deleted,
 *       restored, purged; each once, with the right ids and the session's actor; duplicates, name
 *       conflicts, refused types, refused rights and a folder that is not empty report nothing;
 *   (C) a chunked upload and a chunked overwrite: one report, at the finish;
 *   (D) bulk move, bulk delete, «Papierkorb leeren»: one report per document, one hook instance, a
 *       document under retention reports nothing;
 *   (E) download and open: counted at the first piece; not a later piece, HEAD, 304, 416, a variant,
 *       a thumbnail, a refused request; a retried first piece counts again;
 *   (F) `/media`: public, static or through PHP, reports nothing; a gated original does, a gated
 *       variant does not;
 *   (G) a broken hook from a real config, over HTTP: the action goes on and the log has it; the
 *       framework's own config leaves it off;
 *   (H) real services on the installation without a request: no session and a job report an empty
 *       actor, a member-realm identity its key; `serveFor(download: true)` as a project's own
 *       controller calls it; `saveGenerated`, `ModuleDrive`, `rootFolder`; a blob that cannot be
 *       written reports nothing; a rename or a move to where it already is reports nothing.
 *
 * Run: php tests/dms-activity.php
 */

$repo = str_replace('\\', '/', dirname(__DIR__));
if (!is_file($repo . '/vendor/autoload.php')) {
    fwrite(STDERR, "dms-activity: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $repo . '/vendor/autoload.php';
require_once $repo . '/packages/kernel/core/src/autoload/prod/php/Helper.php';
require __DIR__ . '/support/real-flow-site.php';

use Z77\Core\DI;
use Z77\Core\Exception\NotFoundException;
use Z77\Core\Http\Response\FileResponse;
use Z77\Module\Dms\Blob\BlobStorage;
use Z77\Module\Dms\Images\GdImageProcessor;
use Z77\Module\Dms\Images\ImageProfileRegistry;
use Z77\Module\Dms\Services\ActivityLog;
use Z77\Module\Dms\Services\DocumentService;
use Z77\Module\Dms\Services\FolderService;
use Z77\Module\Dms\Services\SaveRequest;
use Z77\Module\Dms\Services\SaveService;
use Z77\Module\Dms\ValueObjects\ActivityEvent;
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

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-activity-' . getmypid() . '-' . bin2hex(random_bytes(3));
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

// ── the hooks, as a project writes them: an invokable, no constructor argument ────
$hooks = <<<'PHP'
<?php
final class ActivitySpyHook
{
    public static int $built = 0;

    public function __construct()
    {
        self::$built++;
    }

    public function __invoke(\Z77\Module\Dms\ValueObjects\ActivityEvent $event): void
    {
        $row = static function (string $file, ?int $id): ?array {
            $rows = json_decode((string) @file_get_contents(ABS_BASE_PATH . '/data/documents/' . $file), true) ?: [];
            foreach ($rows as $one) {
                if ((int) ($one['id'] ?? 0) === $id) {
                    return $one;
                }
            }
            return null;
        };
        $line = (array) $event + [
            'doc'    => $row('documents.json', $event->documentId),
            'folder' => $row('folders.json', $event->folderId),
            'blob'   => $event->documentId !== null && (glob(ABS_BASE_PATH . '/data/blobs/*/' . $event->documentId . '/orig.*') ?: []) !== [],
            'built'  => self::$built,
        ];
        file_put_contents(ABS_BASE_PATH . '/activity.jsonl', json_encode($line) . "\n", FILE_APPEND | LOCK_EX);
    }
}

final class ActivityThrowingHook
{
    public function __invoke(\Z77\Module\Dms\ValueObjects\ActivityEvent $event): void
    {
        throw new \RuntimeException('the project log is away');
    }
}

final class ActivityNotInvokable
{
}

final class ActivityNeedsArgument
{
    public function __construct(public string $required)
    {
    }

    public function __invoke(\Z77\Module\Dms\ValueObjects\ActivityEvent $event): void
    {
    }
}
PHP;

$hash = password_hash('Passwort-Sehr-Lang-1', PASSWORD_BCRYPT, ['cost' => 4]);
realFlowSite($site, $repo, [
    ['id' => 9, 'username' => 'chef', 'name' => 'Max Muster', 'email' => 'max.muster@example.ch', 'password_hash' => $hash, 'roles' => ['superUser'], 'sort_key' => 0],
    ['id' => 7, 'username' => 'erna', 'name' => 'Erna Muster', 'email' => 'erna.muster@example.ch', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 1],
]);
file_put_contents($site . '/activity-hook.php', $hooks);
$stub = (string) file_get_contents($site . '/vendor/autoload.php');
file_put_contents($site . '/vendor/autoload.php', str_replace('<?php', "<?php\nrequire_once " . var_export($site . '/activity-hook.php', true) . ';', $stub));

// The DMS on the installation, with a project's override of the module config.
$finder = (string) file_get_contents($site . '/config/fileFinder.inc.php');
file_put_contents($site . '/config/fileFinder.inc.php', str_replace(
    "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),",
    "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),\n        'Z77\\\\Module\\\\Dms\\\\' => array_merge_recursive(['sourcePaths' => [" . var_export($site . '/project-override', true) . "]], \$entry('module-dms')),",
    $finder
));
$modules = (string) file_get_contents($site . '/config/moduleManager.inc.php');
file_put_contents($site . '/config/moduleManager.inc.php', (string) preg_replace("/('backend' =>\s*array \(\s*\),)/", "$1\n    'dms' => array (),", $modules));
$assets = $repo . '/packages/module-dms/res/assets';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assets, FilesystemIterator::SKIP_DOTS)) as $asset) {
    $to = $site . '/public/assets/dms' . str_replace('\\', '/', substr($asset->getPathname(), strlen($assets)));
    if ($asset->isFile()) {
        @mkdir(dirname($to), 0777, true);
        copy($asset->getPathname(), $to);
    }
}

/** What a project's config says; a fresh request reads it. */
$setHook = static function (string|array|int|null $value) use ($site, $repo): void {
    @mkdir($site . '/project-override/src/App/Config', 0777, true);
    file_put_contents($site . '/project-override/src/App/Config/dmsConfig.inc.php', "<?php\n\$c = require " . var_export($repo . '/packages/module-dms/src/App/Config/dmsConfig.inc.php', true) . ";\n"
        . "\$c['activityHook'] = " . var_export($value, true) . ";\nreturn \$c;\n");
    removeTree($site . '/var/cache');
};
$setHook('ActivitySpyHook');

$pdf  = "%PDF-1.4\n" . str_repeat('x', 91); // 100 bytes
$jpeg = "\xFF\xD8\xFF\xE0 jpeg bytes \0\xff";
$rows = [];
$seed = static function (int $id, int $folder, string $slug, string $ext, string $mime, string $kind, string $bytes, array $extra = [], array $variants = []) use ($site, &$rows): void {
    mkdir("{$site}/data/blobs/0/{$id}", 0777, true);
    file_put_contents("{$site}/data/blobs/0/{$id}/orig.{$ext}", $bytes);
    foreach ($variants as $name => $meta) {
        file_put_contents("{$site}/data/blobs/0/{$id}/{$name}.{$meta['ext']}", $bytes);
    }
    $rows[] = $extra + ['id' => $id, 'folder_id' => $folder, 'display_name' => "{$slug}.{$ext}", 'original_name' => "{$slug}.{$ext}", 'ext' => $ext, 'mime_type' => $mime,
        'kind' => $kind, 'size_bytes' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'active' => true, 'slug' => $slug, 'sort_key' => $id, 'variants' => $variants];
};
$s = ['s' => ['ext' => 'jpg', 'mime' => 'image/jpeg', 'w' => 40, 'h' => 40, 'size' => 10]];
$seed(1, 2, 'bericht', 'pdf', 'application/pdf', 'pdf', $pdf);
$seed(2, 2, 'bild', 'jpg', 'image/jpeg', 'image', $jpeg, [], $s);
$seed(3, 2, 'punkte', 'laz', 'application/octet-stream', 'file', $pdf);
$seed(4, 4, 'foto', 'jpg', 'image/jpeg', 'image', $jpeg, [], $s);
$seed(5, 2, 'archiv', 'zip', 'application/zip', 'archive', $pdf);
$seed(6, 2, 'fest', 'pdf', 'application/pdf', 'pdf', $pdf, ['deleted_at' => gmdate('c'), 'retention_until' => gmdate('c', time() + 86400 * 365)]);
$seed(7, 5, 'mitglied', 'pdf', 'application/pdf', 'pdf', $pdf);
mkdir($site . '/data/documents', 0777, true);
file_put_contents($site . '/data/documents/documents.json', json_encode($rows, JSON_PRETTY_PRINT));
$folder = static fn (int $id, ?int $parent, string $name, ?string $mode = null, array $extra = []): array => $extra + ['id' => $id, 'name' => $name, 'active' => true, 'slug' => strtolower($name), 'delivery_mode' => $mode, 'sort_key' => $id, 'parent_id' => $parent];
file_put_contents($site . '/data/documents/folders.json', json_encode([
    $folder(1, null, 'Drive', null, ['key' => 'drive', 'system' => true]),
    $folder(2, 1, 'Arbeit'),
    $folder(3, 1, 'Ablage'),
    $folder(4, 1, 'Offen', 'public'),
    $folder(5, 1, 'Mitglieder'),
], JSON_PRETTY_PRINT));

$activityFile = $site . '/activity.jsonl';
$offset       = 0;
/** The reports since the last look. @return list<array<string, mixed>> */
$take = static function () use ($activityFile, &$offset): array {
    clearstatcache(true, $activityFile);
    $raw    = (string) @file_get_contents($activityFile);
    $new    = substr($raw, $offset);
    $offset = strlen($raw);

    return array_map(static fn (string $l): array => (array) json_decode($l, true), array_values(array_filter(explode("\n", $new), static fn (string $l): bool => $l !== '')));
};
/** One report with these fields, and only one. */
$one = static function (string $label, array $want, array $events): ?array {
    $hit = count($events) === 1 ? $events[0] : null;
    $ok  = $hit !== null;
    foreach ($want as $key => $value) {
        $ok = $ok && array_key_exists($key, $hit) && $hit[$key] === $value;
    }
    check($label, $ok, json_encode(array_map(static fn (array $e): array => array_diff_key($e, ['doc' => 1, 'folder' => 1]), $events)));

    return $hit;
};

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(A) ActivityLog alone\n";
require_once $site . '/activity-hook.php';
define('ABS_BASE_PATH', $site);
$cliLog = $base . '/cli.log';
ini_set('log_errors', '1');
ini_set('error_log', $cliLog);
$logged = static function () use ($cliLog): string {
    clearstatcache(true, $cliLog);
    return (string) @file_get_contents($cliLog);
};

final class ActivityMemoHook
{
    public static array $calls = [];
    public static int $built = 0;
    public function __construct() { self::$built++; }
    public function __invoke(ActivityEvent $event): void { self::$calls[] = $event; }
}

$actorCalls = 0;
$actor = static function () use (&$actorCalls): string { $actorCalls++; return 'backend:7'; };

ActivityLog::useHook(null, $actor);
ActivityLog::document(ActivityEvent::DOCUMENT_DELETED, 1, 2);
ActivityLog::folder(ActivityEvent::FOLDER_CREATED, 3, 2);
check('hook off: not enabled', !ActivityLog::enabled());
check('hook off: the identity is not even asked', $actorCalls === 0);
ActivityLog::useHook('', $actor);
check('an empty string is off as well, without a log line', !ActivityLog::enabled() && $logged() === '', $logged());

ActivityLog::useHook(ActivityMemoHook::class, $actor);
ActivityLog::document(ActivityEvent::DOCUMENT_MOVED, 11, 22, 33);
ActivityLog::document(ActivityEvent::DOCUMENT_DELETED, 11, 22);
ActivityLog::folder(ActivityEvent::FOLDER_MOVED, 44, 55, 66);
$e = ActivityMemoHook::$calls;
check('a document event', $e[0]->action === 'document.moved' && $e[0]->documentId === 11 && $e[0]->folderId === 22 && $e[0]->fromFolderId === 33 && $e[0]->parentFolderId === null && $e[0]->actorKey === 'backend:7', json_encode($e[0]));
check('a document event outside a move has no source', $e[1]->fromFolderId === null, json_encode($e[1]));
check('a folder event', $e[2]->action === 'folder.moved' && $e[2]->documentId === null && $e[2]->folderId === 44 && $e[2]->parentFolderId === 55 && $e[2]->fromFolderId === 66, json_encode($e[2]));
check('one instance per process', ActivityMemoHook::$built === 1, (string) ActivityMemoHook::$built);
$fields = array_map(static fn (ReflectionProperty $p): string => $p->getName() . ':' . $p->getType(), (new ReflectionClass(ActivityEvent::class))->getProperties());
check('the event holds ids, the action and the actor, nothing else', $fields === ['action:string', 'documentId:?int', 'folderId:?int', 'fromFolderId:?int', 'parentFolderId:?int', 'actorKey:string'], implode(',', $fields));

$before = strlen($logged());
ActivityLog::useHook('ActivityThrowingHook', $actor);
ActivityLog::document(ActivityEvent::DOCUMENT_PURGED, 77, 88);
$line = substr($logged(), $before);
check('a throwing hook does not reach the caller and the log says which record is lost', str_contains($line, 'activityHook failed for document.purged (document 77, folder 88)') && str_contains($line, 'the project log is away'), $line);

$broken = [
    ['a missing class', 'Nowhere\\ThisHook', 'not found'],
    ['a class that cannot be called', 'ActivityNotInvokable', 'not invokable'],
    ['a class with a constructor argument', 'ActivityNeedsArgument', 'cannot be built'],
    ['an array instead of a name', ['x'], 'must be a class name'],
    ['a number instead of a name', 12, 'must be a class name'],
];
foreach ($broken as [$label, $value, $word]) {
    $before = strlen($logged());
    ActivityLog::useHook($value, $actor);
    $calls = $actorCalls;
    ActivityLog::document(ActivityEvent::DOCUMENT_DELETED, 1, 2);
    ActivityLog::document(ActivityEvent::DOCUMENT_DELETED, 2, 2);
    $line = substr($logged(), $before);
    check("{$label}: no exception, logged", str_contains($line, 'activityHook') && str_contains($line, $word), $line);
    check("{$label}: one line in a loop, no identity lookup", substr_count($line, 'activityHook') === 1 && $actorCalls === $calls, $line);
}

final class ActivityWrongSignature
{
    public function __invoke(int $notAnEvent): void {}
}
$before = strlen($logged());
ActivityLog::useHook('ActivityWrongSignature', $actor);
ActivityLog::document(ActivityEvent::DOCUMENT_DELETED, 1, 2);
check('a hook with a wrong parameter type: no exception, logged', str_contains(substr($logged(), $before), 'activityHook failed'), substr($logged(), $before));

$before = strlen($logged());
ActivityLog::useHook(ActivityMemoHook::class, static function (): string { throw new RuntimeException('the session store is away'); });
ActivityMemoHook::$calls = [];
ActivityLog::document(ActivityEvent::DOCUMENT_DELETED, 5, 6);
check('an identity that fails: the record still goes, with an empty actor, and is logged', (ActivityMemoHook::$calls[0]->actorKey ?? 'x') === '' && str_contains(substr($logged(), $before), 'no actor'), substr($logged(), $before));

// A stateless route (the API) registers no AuthService by design: a normal case, not a failure.
check('this part runs without an AuthService', !DI::getInstance()->has('AuthService'));
$before = strlen($logged());
ActivityLog::useHook(ActivityMemoHook::class);
ActivityMemoHook::$calls = [];
ActivityLog::document(ActivityEvent::DOCUMENT_UPLOADED, 5, 6);
check('no AuthService: the record goes with an empty actor, not one log line', count(ActivityMemoHook::$calls) === 1 && ActivityMemoHook::$calls[0]->actorKey === '' && substr($logged(), $before) === '', substr($logged(), $before));

ActivityLog::reset();
$cfg = require $repo . '/packages/module-dms/src/App/Config/dmsConfig.inc.php';
check('the framework config offers the key, off', array_key_exists('activityHook', $cfg) && $cfg['activityHook'] === null);

// ═══════════════════════════════════════════════════════════════════════════════════
$phpLog = $site . '/logs/php-error.log'; // Bootstrap points the error log there
$probe  = stream_socket_server('tcp://127.0.0.1:0');
$port   = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
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

/** One browser: a cookie jar, redirects not followed. @return Closure(string, array|string|null, list<string>, string): array{0:int,1:array<string,string>,2:string} */
$browser = static function () use ($port): Closure {
    $cookies = [];
    return static function (string $path, array|string|null $post = null, array $headers = [], string $method = '') use (&$cookies, $port): array {
        $head = $headers;
        if ($cookies !== []) {
            $head[] = 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($cookies), $cookies));
        }
        $opts = ['ignore_errors' => true, 'timeout' => 60, 'follow_location' => 0, 'max_redirects' => 0, 'method' => $method !== '' ? $method : ($post === null ? 'GET' : 'POST')];
        if (is_string($post)) {
            if (preg_grep('/^Content-Type:/i', $head) === []) {
                $head[] = 'Content-Type: application/json';
            }
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
    $b('/backend/system/login/login', ['csrf_token' => $m[1] ?? '', 'username' => $user, 'password' => 'Passwort-Sehr-Lang-1']);
    [$s, , $page] = $b('/backend/system/dashboard/overview');
    preg_match('/name="csrf-token" content="([^"]+)"/', $page, $t);
    return [$b, $t[1] ?? '', $s];
};
[$chef, $token, $st] = $login('chef');
check('a real login', $st === 200 && $token !== '', "{$st} / {$token}");
$fetch = ['Sec-Fetch-Mode: same-origin', 'Accept: application/json', 'X-CSRF-Token: ' . $token];
$drive = '/backend/documents/drive/';
$post  = static fn (Closure $who, string $action, array $body, ?array $headers = null): array => $who($drive . $action, (string) json_encode($body), $headers ?? $fetch);
$multipart = static function (Closure $who, string $action, array $fields, array $files, ?array $headers = null) use ($drive, $fetch): array {
    $boundary = 'z77' . bin2hex(random_bytes(8));
    $body     = '';
    foreach ($fields as $name => $value) {
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
    }
    foreach ($files as $field => [$filename, $bytes]) {
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"; filename=\"{$filename}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$bytes}\r\n";
    }
    $body .= "--{$boundary}--\r\n";

    return $who($drive . $action, $body, array_merge($headers ?? $fetch, ["Content-Type: multipart/form-data; boundary={$boundary}"]));
};
/** The per-entity token out of a modal the Drive renders. */
$modalToken = static function (Closure $who, string $action) use ($drive, $fetch): string {
    [, , $html] = $who($drive . $action, null, $fetch);
    preg_match('/name="entity_csrf"\s+value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
};
$upload = static fn (Closure $who, string $name, string $bytes, int $folder, array $extra = []): array => $multipart($who, 'upload', ['folder_id' => (string) $folder] + $extra, ['files[]' => [$name, $bytes]]);
$uploadedId = static function (array $answer): int {
    $json = json_decode($answer[2], true);
    return (int) ($json['data']['id'] ?? $json['payload']['id'] ?? 0);
};

$actorKey = 'backend:9';

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(B) the Drive, through the real stack\n";

[$s, , $b] = $post($chef, 'folder-add?parent=2', ['name' => 'Protokolle']);
$hit = $one('folder-add: folder.created once, parent and actor', ['action' => 'folder.created', 'documentId' => null, 'parentFolderId' => 2, 'fromFolderId' => null, 'actorKey' => $actorKey], $take());
$fNew = (int) ($hit['folderId'] ?? 0);
check('… the folder is in the store when the hook is called', ($hit['folder']['name'] ?? '') === 'Protokolle', "{$s} " . substr($b, 0, 200));

$t = $modalToken($chef, "folder-edit?id={$fNew}");
$post($chef, "folder-edit?id={$fNew}", ['entity_csrf' => $t, 'name' => 'Sitzungen', 'mode' => '']);
$hit = $one('folder-edit: folder.renamed once', ['action' => 'folder.renamed', 'folderId' => $fNew, 'parentFolderId' => 2, 'actorKey' => $actorKey], $take());
check('… renamed in the store when the hook is called', ($hit['folder']['name'] ?? '') === 'Sitzungen');
$t = $modalToken($chef, "folder-edit?id={$fNew}");
$post($chef, "folder-edit?id={$fNew}", ['entity_csrf' => $t, 'name' => 'Sitzungen', 'mode' => '']);
check('saving the same name reports nothing', $take() === []);

$t = $modalToken($chef, "folder-move?id={$fNew}");
$post($chef, 'folder-move', ['id' => $fNew, 'entity_csrf' => $t, 'folder_id' => 3]);
$hit = $one('folder-move: folder.moved once, source and target', ['action' => 'folder.moved', 'folderId' => $fNew, 'fromFolderId' => 2, 'parentFolderId' => 3, 'actorKey' => $actorKey], $take());
check('… moved in the store when the hook is called', (int) ($hit['folder']['parent_id'] ?? 0) === 3);
$t = $modalToken($chef, "folder-move?id={$fNew}");
$post($chef, 'folder-move', ['id' => $fNew, 'entity_csrf' => $t, 'folder_id' => 3]);
check('a move to where it stands reports nothing', $take() === []);

// A document in it: a delete is refused, nothing is reported.
$answer = $upload($chef, 'Erster Text.txt', 'erster text', $fNew);
$dNew   = $uploadedId($answer);
$hit    = $one('upload: document.uploaded once, the document and its folder', ['action' => 'document.uploaded', 'documentId' => $dNew, 'folderId' => $fNew, 'fromFolderId' => null, 'parentFolderId' => null, 'actorKey' => $actorKey], $take());
check('… the record and the bytes exist when the hook is called', $dNew > 0 && ($hit['doc']['original_name'] ?? '') === 'Erster Text.txt' && ($hit['blob'] ?? false) === true, substr($answer[2], 0, 300));

$t = $modalToken($chef, "folder-confirm-delete?id={$fNew}");
$post($chef, 'folder-remove', ['id' => $fNew, 'entity_csrf' => $t]);
check('a folder that is not empty: refused, nothing reported', $take() === []);

$answer = $upload($chef, 'Erster Text.txt', 'erster text', $fNew);
check('the identical file again: a duplicate, nothing reported', $take() === [] && (json_decode($answer[2], true)['status'] ?? '') === 'duplicate', substr($answer[2], 0, 200));
$answer = $upload($chef, 'Erster Text.txt', 'zweiter text', $fNew);
check('the same name, other bytes, no consent: a conflict, nothing reported', $take() === [] && (json_decode($answer[2], true)['status'] ?? '') === 'conflict', substr($answer[2], 0, 200));
$answer = $upload($chef, 'Erster Text.txt', 'zweiter text', $fNew, ['overwrite' => '1']);
$events = $take();
$hit    = $one('overwrite: document.overwritten once, no document.uploaded', ['action' => 'document.overwritten', 'documentId' => $dNew, 'folderId' => $fNew, 'actorKey' => $actorKey], $events);
check('… the new bytes are recorded when the hook is called', ($hit['doc']['checksum'] ?? '') === hash('sha256', 'zweiter text'));
$answer = $upload($chef, 'boese.php', '<?php echo 1;', $fNew);
check('a refused type: nothing reported', $take() === [] && (json_decode($answer[2], true)['status'] ?? '') === 'error', substr($answer[2], 0, 200));

$t = $modalToken($chef, "edit?id={$dNew}");
$post($chef, "edit?id={$dNew}", ['entity_csrf' => $t, 'display_name' => 'Neuer Name']);
$hit = $one('edit: document.renamed once', ['action' => 'document.renamed', 'documentId' => $dNew, 'folderId' => $fNew, 'actorKey' => $actorKey], $take());
check('… renamed in the store when the hook is called', ($hit['doc']['display_name'] ?? '') === 'Neuer Name');

$t = $modalToken($chef, "move?id={$dNew}");
$post($chef, 'move', ['id' => $dNew, 'entity_csrf' => $t, 'folder_id' => 3]);
$hit = $one('move: document.moved once, carrying its source', ['action' => 'document.moved', 'documentId' => $dNew, 'folderId' => 3, 'fromFolderId' => $fNew, 'actorKey' => $actorKey], $take());
check('… moved in the store when the hook is called', (int) ($hit['doc']['folder_id'] ?? 0) === 3);

$t = $modalToken($chef, "confirm-delete?id={$dNew}");
$post($chef, 'remove', ['id' => $dNew, 'entity_csrf' => $t]);
$hit = $one('remove: document.deleted once (to the trash)', ['action' => 'document.deleted', 'documentId' => $dNew, 'folderId' => 3, 'actorKey' => $actorKey], $take());
check('… in the trash when the hook is called, the bytes kept', !empty($hit['doc']['deleted_at']) && ($hit['blob'] ?? false) === true);

$trashTokens = static function (Closure $who) use ($drive, $fetch): array {
    [, , $html] = $who($drive . 'trash', null, $fetch);
    preg_match_all('/name="entity_csrf"\s+value="([^"]+)">\s*<input[^>]*name="op"\s+value="(restore|purge)">\s*<input[^>]*name="id"\s+value="(\d+)"/', $html, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $row) {
        $out[(int) $row[3]][$row[2]] = $row[1];
    }
    return $out;
};
$tokens = $trashTokens($chef);
$post($chef, 'trash', ['op' => 'restore', 'id' => $dNew, 'entity_csrf' => $tokens[$dNew]['restore'] ?? '']);
$hit = $one('trash, restore: document.restored once', ['action' => 'document.restored', 'documentId' => $dNew, 'folderId' => 3, 'actorKey' => $actorKey], $take());
check('… live again when the hook is called', ($hit['doc'] ?? null) !== null && empty($hit['doc']['deleted_at']));
$t = $modalToken($chef, "confirm-delete?id={$dNew}");
$post($chef, 'remove', ['id' => $dNew, 'entity_csrf' => $t]);
$take();
$tokens = $trashTokens($chef);
$post($chef, 'trash', ['op' => 'purge', 'id' => $dNew, 'entity_csrf' => $tokens[$dNew]['purge'] ?? '']);
$hit = $one('trash, purge: document.purged once', ['action' => 'document.purged', 'documentId' => $dNew, 'folderId' => 3, 'actorKey' => $actorKey], $take());
check('… gone from the store and the disk when the hook is called', array_key_exists('doc', $hit ?? []) && $hit['doc'] === null && ($hit['blob'] ?? true) === false);

$post($chef, 'trash', ['op' => 'purge', 'id' => 6, 'entity_csrf' => $tokens[6]['purge'] ?? '']);
check('a purge under retention: refused, nothing reported', $take() === [] && isset($tokens[6]['purge']));

// The folder is empty now, a document of it having moved away; one in the trash does not hold it up.
$answer = $upload($chef, 'Papierkorb.txt', 'bald weg', $fNew);
$dTrash = $uploadedId($answer);
$take();
$t = $modalToken($chef, "confirm-delete?id={$dTrash}");
$post($chef, 'remove', ['id' => $dTrash, 'entity_csrf' => $t]);
$take();
$t = $modalToken($chef, "folder-confirm-delete?id={$fNew}");
$post($chef, 'folder-remove', ['id' => $fNew, 'entity_csrf' => $t]);
$hit = $one('folder-remove with a document in the trash: folder.deleted once, nothing about the document', ['action' => 'folder.deleted', 'folderId' => $fNew, 'documentId' => null, 'parentFolderId' => 3, 'actorKey' => $actorKey], $take());
check('… gone from the store when the hook is called', array_key_exists('folder', $hit ?? []) && $hit['folder'] === null);
$tokens = $trashTokens($chef);
$post($chef, 'trash', ['op' => 'restore', 'id' => $dTrash, 'entity_csrf' => $tokens[$dTrash]['restore'] ?? '']);
check('restoring into a folder that is gone: refused, nothing reported', $take() === [] && isset($tokens[$dTrash]['restore']));

// Somebody without the right: every door refuses, the log stays silent.
[$erna, $ernaToken] = $login('erna');
$ernaFetch = ['Sec-Fetch-Mode: same-origin', 'Accept: application/json', 'X-CSRF-Token: ' . $ernaToken];
$post($erna, 'remove', ['id' => 1, 'entity_csrf' => 'x'], $ernaFetch);
$post($erna, 'folder-add?parent=2', ['name' => 'Nein'], $ernaFetch);
$post($erna, 'bulk-remove', ['ids' => '1,2'], $ernaFetch);
$multipart($erna, 'upload', ['folder_id' => '2'], ['files[]' => ['nein.txt', 'nein']], $ernaFetch);
check('without the right: nothing happens, nothing is reported', $take() === []);
$rowsNow = json_decode((string) file_get_contents($site . '/data/documents/documents.json'), true);
check('… and the documents are all still there', count(array_filter($rowsNow, static fn (array $r): bool => in_array((int) $r['id'], [1, 2], true) && empty($r['deleted_at']))) === 2);

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(C) chunked upload\n";
$chunked = static function (string $name, string $bytes, int $folderId, array $extraBegin = []) use ($multipart, $chef): array {
    [, , $b] = $multipart($chef, 'chunk-begin', ['folder_id' => (string) $folderId, 'name' => $name, 'size' => (string) strlen($bytes)] + $extraBegin, []);
    $id = (string) (json_decode($b, true)['uploadId'] ?? '');
    $multipart($chef, 'chunk-append', ['upload_id' => $id, 'index' => '0', 'sha256' => hash('sha256', $bytes)], ['chunk' => ['blob', $bytes]]);

    return [$id, $b];
};
[$uid, $b] = $chunked('Gross.txt', str_repeat('gross ', 100), 3);
check('begin and append report nothing', $uid !== '' && $take() === [], $b);
[$s, , $b] = $multipart($chef, 'chunk-finish', ['upload_id' => $uid], []);
$dChunk = (int) (json_decode($b, true)['data']['id'] ?? 0);
$hit = $one('finish: document.uploaded once', ['action' => 'document.uploaded', 'documentId' => $dChunk, 'folderId' => 3, 'actorKey' => $actorKey], $take());
check('… with the bytes there when the hook is called', $dChunk > 0 && ($hit['blob'] ?? false) === true, "{$s} {$b}");

[$uid] = $chunked('Gross.txt', str_repeat('anders ', 100), 3);
[$s, , $b] = $multipart($chef, 'chunk-finish', ['upload_id' => $uid], []);
check('a chunked name conflict: 409, nothing reported', $s === 409 && $take() === [], "{$s} {$b}");
[$s, , $b] = $multipart($chef, 'chunk-finish', ['upload_id' => $uid, 'overwrite' => '1'], []);
$hit = $one('… finished with consent: document.overwritten once, no document.uploaded', ['action' => 'document.overwritten', 'documentId' => $dChunk, 'folderId' => 3, 'actorKey' => $actorKey], $take());
check('… the new checksum is recorded when the hook is called', ($hit['doc']['checksum'] ?? '') === hash('sha256', str_repeat('anders ', 100)), "{$s} {$b}");

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(D) bulk and «Papierkorb leeren»\n";
$bulkIds = [];
foreach (['a', 'b', 'c'] as $letter) {
    $bulkIds[] = $uploadedId($upload($chef, "Bulk {$letter}.txt", "bulk {$letter}", 3));
}
check('three uploads, three reports', count($take()) === 3);
$post($chef, 'bulk-move', ['ids' => implode(',', [$bulkIds[0], $bulkIds[1]]), 'folder_id' => 5]);
$events = $take();
check('bulk move: one document.moved per document, each with its source', array_column($events, 'documentId') === [$bulkIds[0], $bulkIds[1]] && array_unique(array_column($events, 'action')) === ['document.moved'] && array_unique(array_column($events, 'fromFolderId')) === [3] && array_unique(array_column($events, 'folderId')) === [5], json_encode(array_column($events, 'documentId')));

$post($chef, 'bulk-remove', ['ids' => implode(',', [...$bulkIds, 99999]), 'folder' => 3]);
$events = $take();
check('bulk delete: one document.deleted per document, an unknown id none', array_column($events, 'documentId') === $bulkIds && array_unique(array_column($events, 'action')) === ['document.deleted'] && array_column($events, 'folderId') === [5, 5, 3], json_encode($events));

$post($chef, 'trash', ['op' => 'purgeAll']);
$events = $take();
$purged = array_map('intval', array_column($events, 'documentId'));
sort($purged);
$expect = [...$bulkIds, $dTrash];
sort($expect);
check('Papierkorb leeren: one document.purged per document, the one under retention none', $purged === $expect && array_unique(array_column($events, 'action')) === ['document.purged'], json_encode($purged));
check('… one hook instance for the whole loop', array_unique(array_column($events, 'built')) === [1], json_encode(array_column($events, 'built')));
check('… document 6 stays in the store, it is under retention', in_array(6, array_map('intval', array_column(json_decode((string) file_get_contents($site . '/data/documents/documents.json'), true), 'id')), true));

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(E) download and open: counted at the first piece\n";
$dl = '/backend/documents/document/download?id=1';
[$s, $h, $b] = $chef($dl, null, ['Range: bytes=0-9']);
check('bytes=0-9: 206, ten bytes', $s === 206 && strlen($b) === 10, (string) $s);
$etag = $h['etag'] ?? '';
$one('… reported as document.downloaded, with folder and actor', ['action' => 'document.downloaded', 'documentId' => 1, 'folderId' => 2, 'actorKey' => $actorKey], $take());
[$s] = $chef($dl, null, ['Range: bytes=10-19']);
check('bytes=10-19: 206, not reported', $s === 206 && $take() === [], (string) $s);
[$s] = $chef($dl, null, []);
check('a second, plain download: 200', $s === 200);
$one('… reported again', ['action' => 'document.downloaded', 'documentId' => 1], $take());
[$s, $h2] = $chef($dl, null, [], 'HEAD');
check('HEAD: 200, not reported', $s === 200 && $take() === [], (string) $s);
[$s] = $chef($dl, null, ['If-None-Match: ' . $etag]);
check('304 by the ETag: not reported', $s === 304 && $etag !== '' && $take() === [], "{$s} {$etag}");
[$s] = $chef($dl, null, ['If-None-Match: ' . $etag, 'Range: bytes=0-9']);
check('304 beats a range from byte 0: not reported', $s === 304 && $take() === [], (string) $s);
[$s] = $chef($dl, null, ['Range: bytes=9999-']);
check('416: not reported', $s === 416 && $take() === [], (string) $s);
[$s] = $chef($dl, null, ['Range: bytes=-10']);
check('the last ten bytes: 206, not reported', $s === 206 && $take() === [], (string) $s);
[$s] = $chef($dl, null, ['Range: bytes=0-9']);
check('a retried first piece: 206', $s === 206);
$one('… is reported again (documented)', ['action' => 'document.downloaded', 'documentId' => 1], $take());
[$s] = $chef($dl, null, ['Range: bytes=0-49']);
$take();
[$s] = $chef($dl, null, ['Range: bytes=50-99']);
check('a chunked download of two pieces is one report: the second piece is silent', $s === 206 && $take() === []);

$pv = '/backend/documents/document/preview?id=';
[$s, $h] = $chef($pv . '1');
check('Öffnen, a PDF: 200, inline', $s === 200 && str_starts_with($h['content-disposition'] ?? '', 'inline'), (string) $s);
$one('… reported as document.opened', ['action' => 'document.opened', 'documentId' => 1, 'folderId' => 2, 'actorKey' => $actorKey], $take());
[$s] = $chef($pv . '1', null, ['Range: bytes=0-']);
check('a player asks from byte 0: 206', $s === 206);
$one('… document.opened', ['action' => 'document.opened', 'documentId' => 1], $take());
[$s] = $chef($pv . '1', null, ['Range: bytes=50-']);
check('… and on from there: not reported', $s === 206 && $take() === [], (string) $s);
[$s] = $chef($pv . '2&variant=s');
check('a thumbnail: 200, not reported', $s === 200 && $take() === [], (string) $s);
[$s] = $chef('/backend/documents/document/download?id=2&variant=s');
check('a variant as a download: 200, not reported', $s === 200 && $take() === [], (string) $s);
[$s] = $chef($pv . '2&variant=nope');
check('an unknown variant: 404, not reported', $s === 404 && $take() === [], (string) $s);
[$s] = $chef($pv . '2');
check('the original of an image: 200', $s === 200);
$one('… document.opened', ['action' => 'document.opened', 'documentId' => 2], $take());
[$s, $h] = $chef($pv . '3');
check('Öffnen on a type that is never rendered: an attachment', $s === 200 && str_starts_with($h['content-disposition'] ?? '', 'attachment'), json_encode($h));
$one('… so it is reported as what it is, document.downloaded', ['action' => 'document.downloaded', 'documentId' => 3], $take());
[$s, $h] = $chef($pv . '5');
check('Öffnen on a type the output policy forces to attachment', $s === 200 && str_starts_with($h['content-disposition'] ?? '', 'attachment'), json_encode($h));
$one('… document.downloaded too', ['action' => 'document.downloaded', 'documentId' => 5], $take());

[$s] = $erna($dl);
check('no right: 404, not reported', $s === 404 && $take() === [], (string) $s);
[$s] = $browser()($dl);
check('no session: refused, not reported', $s !== 200 && $s !== 206 && $take() === [], (string) $s);
[$s] = $chef('/backend/documents/document/download?id=6');
check('a document in the trash: 404, not reported', $s === 404 && $take() === [], (string) $s);

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(F) /media\n";
$anon = $browser();
[$s, $h] = $anon('/media/offen/foto.jpg');
check('public, the first hit through PHP: 200, not reported', $s === 200 && isset($h['content-disposition']) && $take() === [], json_encode($h));
[$s, $h] = $anon('/media/offen/foto.jpg');
check('public, then the static copy: PHP does not run, not reported', $s === 200 && !isset($h['content-disposition']) && $take() === [], json_encode($h));
unlink($site . '/public/media/offen/foto.jpg');
[$s] = $chef('/media/offen/foto.jpg');
check('public through PHP with a session too: not reported', $s === 200 && $take() === [], (string) $s);
[$s] = $anon('/media/arbeit/bericht.pdf');
check('gated, no session: 404, not reported', $s === 404 && $take() === [], (string) $s);
[$s] = $chef('/media/arbeit/bericht.pdf');
check('gated, the gate passes: 200', $s === 200, (string) $s);
$one('… a gated original went to somebody: document.opened', ['action' => 'document.opened', 'documentId' => 1, 'folderId' => 2, 'actorKey' => $actorKey], $take());
[$s] = $chef('/media/arbeit/bild.s.jpg');
check('gated, a variant: 200, not reported', $s === 200 && $take() === [], (string) $s);

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(G) a broken hook from a real config: the action goes on\n";
$logFrom = 0;
$newLog  = static function () use ($phpLog, &$logFrom): string {
    clearstatcache(true, $phpLog);
    $raw     = (string) @file_get_contents($phpLog);
    $new     = substr($raw, $logFrom);
    $logFrom = strlen($raw);
    return $new;
};
$newLog();
$live = static fn (int $id): ?array => array_values(array_filter(json_decode((string) file_get_contents($site . '/data/documents/documents.json'), true), static fn (array $r): bool => (int) $r['id'] === $id))[0] ?? null;

$cases = [
    ['a throwing hook', 'ActivityThrowingHook', 'the project log is away'],
    ['a missing class', 'Nowhere\\ActivityHook', 'not found'],
    ['a class that cannot be called', 'ActivityNotInvokable', 'not invokable'],
    ['a wrong type in the config', ['nope'], 'must be a class name'],
];
foreach ($cases as $i => [$label, $value, $word]) {
    $setHook($value);
    $name   = "Kaputt {$i}.txt";
    $answer = $upload($chef, $name, "kaputt {$i}", 3);
    $id     = $uploadedId($answer);
    check("{$label}: the upload succeeds", $id > 0 && $live($id) !== null, substr($answer[2], 0, 200));
    [$s, , $bytes] = $chef("/backend/documents/document/download?id={$id}");
    check("{$label}: the download succeeds", $s === 200 && $bytes === "kaputt {$i}", (string) $s);
    $t = $modalToken($chef, "confirm-delete?id={$id}");
    $post($chef, 'remove', ['id' => $id, 'entity_csrf' => $t]);
    check("{$label}: the delete succeeds", !empty($live($id)['deleted_at']));
    $line = $newLog();
    check("{$label}: logged", str_contains($line, 'activityHook') && str_contains($line, $word), substr($line, 0, 300));
    check("{$label}: nothing reached the spy", $take() === []);
}

$setHook(null);
$id = $uploadedId($upload($chef, 'Still.txt', 'still', 3));
[$s] = $chef("/backend/documents/document/download?id={$id}");
check('hook off in the config: the action happens, no call, no log line', $id > 0 && $s === 200 && $take() === [] && !str_contains($newLog(), 'activityHook'));

// A project that overrides the file and has not added the key: off, not broken.
file_put_contents($site . '/project-override/src/App/Config/dmsConfig.inc.php', "<?php\n\$c = require " . var_export($repo . '/packages/module-dms/src/App/Config/dmsConfig.inc.php', true) . ";\nunset(\$c['activityHook']);\nreturn \$c;\n");
removeTree($site . '/var/cache');
$id = $uploadedId($upload($chef, 'Ohne Schluessel.txt', 'ohne', 3));
check('an override without the key: the action happens, nothing is called', $id > 0 && $take() === [] && !str_contains($newLog(), 'activityHook'));

proc_terminate($server);

// ═══════════════════════════════════════════════════════════════════════════════════
echo "(H) real services on the installation, no request\n";
$setHook('ActivitySpyHook');
/** The session as AuthService gives it; the harness counts the questions. */
final class ActivityFakeAuth
{
    public int $asked = 0;
    public function __construct(public AuthUser $user) {}
    public function getCurrentUser(): AuthUser { $this->asked++; return $this->user; }
}
/** The three headers the count reads. */
final class ActivityFakeRequest
{
    public bool $head = false;
    public ?string $inm = null;
    public string $range = '';
    public function isHead(): bool { return $this->head; }
    public function getIfNoneMatch(): ?string { return $this->inm; }
    public function getRange(): string { return $this->range; }
}
$guest  = new AuthUser(['id' => 0, 'user_name' => 'guest', 'roles' => [\Z77\Core\Config\AuthRole::GUEST]]);
$auth   = new ActivityFakeAuth($guest);
$req    = new ActivityFakeRequest();
$wire   = static function () use ($auth, $req, $site): void {
    DI::getInstance(true)
        ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
        ->set('FileFinder', fn ($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn ($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn ($c) => new \Z77\Core\Services\ModuleManager($c->get('ConfigManager')), true)
        ->set('I18n', fn ($c) => new \Z77\Core\Services\I18n($c->get('ConfigManager')), true)
        ->set('DataSourceResolver', fn () => new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File']), true)
        ->set('UnifiedEntityManager', fn ($c) => new \Z77\Persistence\Resolver\UnifiedEntityManager($c->get('DataSourceResolver')), true)
        ->set('AuthService', fn () => $auth, true)
        ->set('Request', fn () => $req, true);
    DI::getCacheManager()->setCacheDir($site . '/var/cache');
    ActivityLog::reset();
};
$wire();
$take();

$docs = DocumentService::create();
$before = $auth->asked;
$member = new AuthUser(['id' => 'ab12cd', 'user_name' => 'Erna', 'roles' => ['customer'], 'realm' => AuthUser::REALM_MEMBER]);
$super  = new AuthUser(['id' => 9, 'user_name' => 'chef', 'roles' => ['superUser'], 'realm' => AuthUser::REALM_BACKEND]);
$cron   = new AuthUser(['id' => 'cron', 'user_name' => 'cron:x', 'roles' => [\Z77\Core\Config\AuthRole::CRON_JOB], 'realm' => AuthUser::REALM_CRON]);

$made = $docs->saveGenerated('%PDF-1.4 generiert', new SaveRequest(originalName: 'Rechnung.pdf', mimeType: 'application/pdf', folderId: 3));
$hit  = $one('saveGenerated without a session: document.generated once, an empty actor', ['action' => 'document.generated', 'documentId' => (int) $made->getId(), 'folderId' => 3, 'actorKey' => ''], $take());
check('… in the store with its bytes when the hook is called', ($hit['doc']['source'] ?? '') === 'generated' && ($hit['blob'] ?? false) === true);

$handle = $docs->forModule('rechnungen');
$events = $take();
$partition = (int) $handle->root()->getId();
$one('a module\'s new partition: folder.created once, under the drive root, an empty actor', ['action' => 'folder.created', 'folderId' => $partition, 'parentFolderId' => 1, 'actorKey' => ''], $events);
$sub = $handle->folder('2026');
$one('ModuleDrive::folder: folder.created once for the new folder', ['action' => 'folder.created', 'folderId' => (int) $sub->getId(), 'parentFolderId' => $partition, 'actorKey' => ''], $take());
$handle->folder('2026');
$docs->forModule('rechnungen');
check('… get-or-create that finds it: nothing', $take() === []);
$inModule = $handle->saveGenerated('%PDF-1.4 modul', new SaveRequest(originalName: 'Modul.pdf', mimeType: 'application/pdf', folderId: (int) $sub->getId()));
$one('ModuleDrive::saveGenerated: one report', ['action' => 'document.generated', 'documentId' => (int) $inModule->getId(), 'folderId' => (int) $sub->getId()], $take());

$auth->user = $cron;
$made2 = $docs->saveGenerated('%PDF-1.4 job', new SaveRequest(originalName: 'Job.pdf', mimeType: 'application/pdf', folderId: 3));
$one('a job\'s identity: an empty actor too', ['action' => 'document.generated', 'documentId' => (int) $made2->getId(), 'actorKey' => ''], $take());

// The service as a project's own controller calls it: read through a role ACE, a member session.
$auth->user = $super;
$docs->grant('folder', 5, 'role', 'customer', 'read');
$take();
$auth->user = $member;
$own = static fn () => DocumentService::create()->serveFor(7, null, true);
$req->range = 'bytes=0-9';
check('serveFor(download: true), a member session: answered', $own() instanceof FileResponse);
$one('… document.downloaded, with the member-realm actor', ['action' => 'document.downloaded', 'documentId' => 7, 'folderId' => 5, 'actorKey' => 'member:ab12cd'], $take());
$req->range = 'bytes=10-19';
$own();
check('… bytes=10-19: not reported', $take() === []);
$req->range = '';
$own();
$one('… a second download: reported', ['action' => 'document.downloaded', 'documentId' => 7, 'actorKey' => 'member:ab12cd'], $take());
$req->head = true;
$own();
check('… HEAD: not reported', $take() === []);
$req->head = false;
$req->inm  = '"' . hash('sha256', $pdf) . '-orig"';
$own();
check('… 304: not reported', $take() === []);
$req->inm   = null;
$req->range = 'bytes=9999-';
$own();
check('… 416: not reported', $take() === []);
$req->range = '';

$refused = static function (Closure $work): bool {
    try { $work(); return false; } catch (NotFoundException) { return true; }
};
check('a member without the right: refused before anything else', $refused(static fn () => DocumentService::create()->serveFor(1, null, true)));
check('… not reported', $take() === []);
check('… read only: a delete is refused', $refused(static fn () => DocumentService::create()->delete(7)));
check('… a move too', $refused(static fn () => DocumentService::create()->move(7, 3)));
check('… a purge too', $refused(static fn () => DocumentService::create()->purge(6)));
check('… a new folder too', $refused(static fn () => FolderService::create()->add(5, 'Nein')));
check('… not reported', $take() === []);

$auth->user = $super;
$anyDoc = $docs->get(1);
$docs->serve($anyDoc, null, true);
$one('serve() direct, a gate of the caller\'s own: reported', ['action' => 'document.downloaded', 'documentId' => 1, 'actorKey' => $actorKey], $take());
$docs->serve($anyDoc, null, true, null, report: false);
check('serve(report: false): not', $take() === []);
$docs->serve($docs->get(2), 's');
check('serve() of a variant: not', $take() === []);

$docs->rename(1, 'bericht.pdf');
check('a rename to the name it has: nothing', $take() === []);
$docs->rename(1, '   ');
check('a rename to nothing: nothing', $take() === []);
$docs->move(1, 2);
check('a move into its own folder: nothing', $take() === []);
check('a move to a folder that does not exist: refused', $refused(static fn () => DocumentService::create()->move(1, 4040)));
check('… not reported', $take() === []);
FolderService::create()->rename(3, 'Ablage');
check('a folder rename to the name it has: nothing', $take() === []);
$docs->delete(4040);
$docs->delete(6);
check('a delete of an unknown or already deleted document: nothing', $take() === []);

// A failure inside the save: the record is not sent.
$failing = new class implements BlobStorage {
    public function put(int $id, string $variant, string $ext, string $bytes): void { throw new RuntimeException('the disk is full'); }
    public function putFile(int $id, string $variant, string $ext, string $sourcePath, bool $isUpload = true): void { throw new RuntimeException('the disk is full'); }
    public function get(int $id, string $variant, string $ext): ?string { return null; }
    public function path(int $id, string $variant, string $ext): ?string { return null; }
    public function exists(int $id, string $variant, string $ext): bool { return false; }
    public function size(int $id, string $variant, string $ext): ?int { return null; }
    public function delete(int $id): void {}
};
$save = new SaveService($failing, DI::getUnifiedEntityManager(), ImageProfileRegistry::fromConfig(), new GdImageProcessor());
$thrown = false;
try {
    $save->save('%PDF-1.4 nie', new SaveRequest(originalName: 'Nie.pdf', mimeType: 'application/pdf', folderId: 3));
} catch (RuntimeException) {
    $thrown = true;
}
check('a blob that cannot be written: the save fails, nothing reported', $thrown && $take() === []);
$thrown = false;
try {
    $save->replace($docs->get(1), '%PDF-1.4 nie', new SaveRequest(originalName: 'bericht.pdf', mimeType: 'application/pdf', folderId: 2));
} catch (RuntimeException) {
    $thrown = true;
}
check('… an overwrite too', $thrown && $take() === []);
$live = SaveService::create();
$over = $live->replace($docs->get(1), '%PDF-1.4 neu', new SaveRequest(originalName: 'bericht.pdf', mimeType: 'application/pdf', folderId: 2));
$hit  = $one('SaveService::replace: document.overwritten once', ['action' => 'document.overwritten', 'documentId' => 1, 'folderId' => 2, 'actorKey' => $actorKey], $take());
check('… the new checksum is recorded when the hook is called', ($hit['doc']['checksum'] ?? '') === hash('sha256', '%PDF-1.4 neu'));

// Off: nothing is asked of the session.
$setHook(null);
$wire();
$asked = $auth->asked;
ActivityLog::document(ActivityEvent::DOCUMENT_DELETED, 1, 2);
$other = DocumentService::create();
$other->saveGenerated('%PDF-1.4 still', new SaveRequest(originalName: 'Still.pdf', mimeType: 'application/pdf', folderId: 3));
$other->serve($other->get(2), null, true);
check('hook off: no call', $take() === []);
check('hook off: the identity is not even asked', $auth->asked === $asked, $auth->asked . ' / ' . $asked);

echo "\n{$pass} ok, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
