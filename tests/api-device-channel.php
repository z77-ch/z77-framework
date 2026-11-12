<?php

/**
 * API device-channel harness (CLI, temp ABS_BASE_PATH, file storage) — ADR-057.
 * The guard chain, the gateway's method/scope/idempotency rules, and module-api's
 * device management, driven through the real ApiKeyGuard + GatewayController with
 * a bare Request (no routing boot) and a fake ModuleManager.
 *
 * What is load-bearing here:
 *
 *   (A) the machine channel is unchanged: bearer GET as before, every POST that
 *       does not reach a scoped service still 405 (also unknown version/endpoint),
 *       other methods 405, a scoped service open to a bearer key, a keyless call
 *       401, the cookie ignored while no device resolver is declared, the bearer
 *       winning over a cookie, positional ApiRequest/ApiPrincipal callers intact;
 *   (B) pairing: the code is stored hashed, works once, expires, forgives case
 *       and dashes, is throttled per /64; the call needs X-Z77-App and a same-host
 *       Origin; the cookie is HttpOnly, SameSite=Strict, Path=/api, and only its
 *       hash lands in devices.json;
 *   (C) a device: `me`, X-Z77-App and Origin enforced (403), GET without Origin
 *       allowed, scope enforced (403), plain services closed to devices, POST
 *       needs an Idempotency-Key, a resend replays without executing twice, the
 *       same key with another body is a 409, a tampered/garbage cookie a 401,
 *       revocation → the next request 401, idle expiry, owner gone, the hourly
 *       last_used_at write, the per-owner cap, the api.log principal.
 *
 * Run: php tests/api-device-channel.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "api-device-channel: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-api-device-' . bin2hex(random_bytes(4));
mkdir($base . '/data', 0777, true);
define('ABS_BASE_PATH', $base);

require $autoload;

// module-api is not in the monorepo's composer map — load it directly.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Z77\\Module\\Api\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../packages/module-api/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Z77\Core\Config\Config;
use Z77\Core\DI;
use Z77\Core\Http\Request;
use Z77\Core\Http\Response\JsonResponse;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Services\ApiKeyGuard;
use Z77\Core\Services\ModuleManager;
use Z77\Module\Api\Entities\ApiDevice;
use Z77\Module\Api\Services\ApiDevices;
use Z77\Module\Api\Ui\Controllers\Main\GatewayController;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Api\ApiRequest;
use Z77\Shared\Api\ApiResult;
use Z77\Shared\Api\ApiServiceInterface;
use Z77\Shared\Api\ScopedApiServiceInterface;
use Z77\Shared\Auth\ApiPrincipal;
use Z77\Shared\Auth\TenantKeyResolverInterface;
use Z77\Shared\Entities\BackendUser;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
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

// ── fixtures ─────────────────────────────────────────────────────────────────

final class HarnessKeyResolver implements TenantKeyResolverInterface
{
    public function resolve(string $plainKey): ?ApiPrincipal
    {
        return hash_equals(hash('sha256', 'machine-key'), hash('sha256', $plainKey))
            ? new ApiPrincipal('zihlundsee', 'pilot')
            : null;
    }
}

/** A machine service as projects have them today: GET only, no scope. */
final class HarnessUnitsService implements ApiServiceInterface
{
    public function handle(ApiRequest $request): ApiResult
    {
        return ApiResult::payload(['tenant' => $request->tenantId, 'key' => $request->keyRef]);
    }
}

/** The receipt module's shape: GET and POST under `receipt.capture`. */
final class HarnessReceiptsService implements ScopedApiServiceInterface
{
    public static int $executed = 0;

    public function scopeFor(string $method, array $slugs): ?string
    {
        return in_array($method, ['get', 'post'], true) ? 'receipt.capture' : null;
    }

    public function handle(ApiRequest $request): ApiResult
    {
        if ($request->method !== 'post') {
            return ApiResult::payload(['templates' => [], 'by' => $request->device()?->ownerName]);
        }
        self::$executed++;
        $file = $request->file('photo');
        return ApiResult::payload([
            'position' => self::$executed,
            'amount'   => $request->body['amount'] ?? null,
            'photo'    => $file?->originalName,
            'device'   => $request->device()?->deviceId,
            'owner'    => $request->device()?->ownerName,
        ]);
    }
}

final class HarnessAdminService implements ScopedApiServiceInterface
{
    public function scopeFor(string $method, array $slugs): ?string
    {
        return $method === 'get' ? 'admin.manage' : null;
    }

    public function handle(ApiRequest $request): ApiResult
    {
        return ApiResult::payload(['secret' => true]);
    }
}

$fakeModuleManager = function (array $configsByModule): ModuleManager {
    $mm = new class extends ModuleManager {
        public array $fakeConfigs = [];
        public function __construct() {} // parent boot deliberately skipped
        public function getModuleKeys(): array { return array_keys($this->fakeConfigs); }
        public function getModuleConfig(string $moduleKey): ?Config
        {
            return isset($this->fakeConfigs[$moduleKey]) ? new Config($this->fakeConfigs[$moduleKey]) : null;
        }
    };
    $mm->fakeConfigs = $configsByModule;
    return $mm;
};

$apiConfig = [
    'apiKeyResolver'    => HarnessKeyResolver::class,
    'apiDeviceResolver' => ApiDevices::class,
    'rateLimitPerHour'  => 10000,
    'apiServices'       => [
        'units'    => HarnessUnitsService::class,
        'receipts' => HarnessReceiptsService::class,
        'admin'    => HarnessAdminService::class,
    ],
];
$channelOn  = $fakeModuleManager(['api' => $apiConfig]);
$channelOff = $fakeModuleManager(['api' => array_diff_key($apiConfig, ['apiDeviceResolver' => 1])]);

DI::getInstance(true)->set('CacheManager', CacheManager::class, true);
$uem = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
DI::getInstance()->set('UnifiedEntityManager', $uem, true);

$owner = new BackendUser();
$owner->setUsername('peter');
$owner->setName('Peter Muster');
$uem->persist($owner);
$uem->flush();
$ownerId = (int) $owner->getId();

$readPrivate = fn(object $obj, string $prop) => (new ReflectionProperty($obj::class, $prop))->getValue($obj);

/**
 * One request through guard + gateway. $h: headers as `Name => value`
 * (Authorization, X-Z77-App, Origin, Idempotency-Key, Remote-Addr).
 *
 * @return array{status:int, data:array, headers:array}
 */
$call = function (string $method, string $path, array $h = [], ?string $cookie = null, array $post = [], array $files = [], ?ModuleManager $mm = null)
    use ($channelOn, $readPrivate): array {
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_Z77_APP', 'HTTP_ORIGIN', 'HTTP_IDEMPOTENCY_KEY', 'REMOTE_ADDR', 'HTTPS'] as $k) {
        unset($_SERVER[$k]);
    }
    $_SERVER['HTTP_HOST'] = 'example.ch';
    $map = ['Authorization' => 'HTTP_AUTHORIZATION', 'X-Z77-App' => 'HTTP_X_Z77_APP', 'Origin' => 'HTTP_ORIGIN',
            'Idempotency-Key' => 'HTTP_IDEMPOTENCY_KEY', 'Remote-Addr' => 'REMOTE_ADDR', 'User-Agent' => 'HTTP_USER_AGENT'];
    foreach ($h as $name => $value) {
        $_SERVER[$map[$name]] = $value;
    }
    $_COOKIE = $cookie === null ? [] : [ApiKeyGuard::DEVICE_COOKIE => $cookie];
    $_POST   = $post;
    $_FILES  = $files;
    $_GET    = [];

    $r = (new ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Request::class, 'method'))->setValue($r, $method);
    (new ReflectionProperty(Request::class, 'rawRequestUri'))->setValue($r, $path);
    (new ReflectionProperty(Request::class, 'slugs'))->setValue($r, array_values(array_filter(explode('/', substr($path, strlen('/api/'))), 'strlen')));

    // DI::set() never replaces a registration — start from a fresh container per request.
    global $uem;
    $mm = $mm ?? $channelOn;
    DI::getInstance(true)->set('CacheManager', CacheManager::class, true);
    DI::getInstance()->set('UnifiedEntityManager', $uem, true);
    DI::getInstance()->set('Request', $r, true);
    DI::getInstance()->set('ModuleManager', $mm, true);
    $guard = new ApiKeyGuard($mm);
    DI::getInstance()->set('ApiKeyGuard', $guard, true);

    $response = $guard->enforce() ?? (new GatewayController('handleAction'))->run();
    assert($response instanceof JsonResponse);

    return [
        'status'  => $response->getStatus(),
        'data'    => $readPrivate($response, 'data'),
        'headers' => $readPrivate($response, 'headers'),
    ];
};

$app    = ['X-Z77-App' => '1', 'Origin' => 'https://example.ch'];
$bearer = ['Authorization' => 'Bearer machine-key'];
$code   = fn(array $r): string => (string) ($r['data']['error']['code'] ?? '');

$devices = new ApiDevices($uem, $base . '/var/lib/api/pairing');

// ── A. machine channel unchanged ─────────────────────────────────────────────
echo "A. machine channel\n";

$r = $call('get', '/api/v1/units', $bearer);
check('A1 bearer GET → 200, tenant + keyRef', $r['status'] === 200 && $r['data'] === ['tenant' => 'zihlundsee', 'key' => 'pilot']);
$r = $call('post', '/api/v1/units', $bearer);
check('A2 bearer POST on a plain service → 405', $r['status'] === 405 && $code($r) === 'method_not_allowed');
$r = $call('post', '/api/v1/nope', $bearer);
check('A3 POST on an unknown endpoint → 405 (as before)', $r['status'] === 405);
$r = $call('post', '/api/v2/units', $bearer);
check('A4 POST on an unknown version → 405 (as before)', $r['status'] === 405);
$r = $call('get', '/api/v2/units', $bearer);
check('A5 GET on an unknown version → 404 unknown_version', $r['status'] === 404 && $code($r) === 'unknown_version');
$r = $call('put', '/api/v1/units', $bearer);
check('A6 PUT → 405', $r['status'] === 405);
$r = $call('get', '/api/v1/receipts', $bearer);
check('A7 a scoped service stays open to a bearer key (service decides by tenant)', $r['status'] === 200);
$r = $call('get', '/api/v1/units');
check('A8 no credential → 401 + WWW-Authenticate', $r['status'] === 401 && ($r['headers']['WWW-Authenticate'] ?? '') === 'Bearer');
$r = $call('get', '/api/v1/health', $bearer);
check('A9 health still built in', $r['status'] === 200 && $r['data'] === ['status' => 'ok']);

$legacy = new ApiRequest('t', ['x'], ['lang' => 'fr'], '"e"', 'ref');
check('A10 positional ApiRequest keeps working, neutral new fields',
    $legacy->keyRef === 'ref' && $legacy->method === 'get' && $legacy->body === [] && $legacy->principal === null && $legacy->device() === null);
$p = new ApiPrincipal('8', 'widget-a');
check('A11 positional ApiPrincipal: label as before, no device, no scope',
    $p->label() === '8:widget-a' && !$p->isDevice() && !$p->hasScope('receipt.capture'));

// ── B. pairing ───────────────────────────────────────────────────────────────
echo "B. pairing\n";

$plain = $devices->createPairingCode($ownerId, 'iPhone Peter', ['receipt.capture']);
check('B1 code shape XXXXX-XXXXX', preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $plain) === 1, $plain);
$stored = glob($base . '/var/lib/api/pairing/*.json') ?: [];
check('B2 stored hashed: file named by sha256, content without the code',
    count($stored) === 1
    && basename($stored[0]) === hash('sha256', str_replace('-', '', $plain)) . '.json'
    && !str_contains((string) file_get_contents($stored[0]), str_replace('-', '', $plain)));

$r = $call('post', '/api/v1/devices/pair', ['Origin' => 'https://example.ch'], null, ['code' => $plain]);
check('B3 pairing without X-Z77-App → 403', $r['status'] === 403 && $code($r) === 'forbidden');
$r = $call('post', '/api/v1/devices/pair', ['X-Z77-App' => '1', 'Origin' => 'https://evil.example'], null, ['code' => $plain]);
check('B4 pairing from a foreign Origin → 403', $r['status'] === 403);
$r = $call('post', '/api/v1/devices/pair', ['X-Z77-App' => '1'], null, ['code' => $plain]);
check('B5 pairing POST without Origin → 403', $r['status'] === 403);
$r = $call('post', '/api/v1/devices/pair', $app, null, ['code' => $plain], [], $channelOff);
check('B6 channel off (no apiDeviceResolver) → pairing is a keyless 401', $r['status'] === 401);
$r = $call('post', '/api/v1/devices/pair', $app, null, []);
check('B7 pairing without a code → 400 bad_request', $r['status'] === 400 && $code($r) === 'bad_request');

$r = $call('post', '/api/v1/devices/pair', $app + ['User-Agent' => 'Mozilla/5.0 (iPhone) Safari/605'], 'stale.cookie', ['code' => strtolower($plain)]);
check('B8 pairing (lower case, stale cookie present) → 200', $r['status'] === 200, json_encode($r));
$setCookie = (string) ($r['headers']['Set-Cookie'] ?? '');
check('B9 Set-Cookie: HttpOnly, SameSite=Strict, Path=/api',
    str_starts_with($setCookie, ApiKeyGuard::DEVICE_COOKIE . '=')
    && str_contains($setCookie, '; HttpOnly') && str_contains($setCookie, 'SameSite=Strict') && str_contains($setCookie, 'Path=/api'), $setCookie);
check('B10 answer names the device, its owner and scopes',
    ($r['data']['device']['label'] ?? '') === 'iPhone Peter'
    && ($r['data']['device']['owner'] ?? '') === 'Peter Muster'
    && ($r['data']['device']['scopes'] ?? null) === ['receipt.capture']);
preg_match('/^' . ApiKeyGuard::DEVICE_COOKIE . '=([^;]+)/', $setCookie, $m);
$cookie = $m[1] ?? '';
[$deviceId, $secret] = array_pad(explode('.', $cookie, 2), 2, '');
$json = (string) @file_get_contents($base . '/data/framework/api/devices.json');
check('B11 devices.json holds sha256(secret), never the secret',
    $secret !== '' && str_contains($json, hash('sha256', $secret)) && !str_contains($json, $secret));
check('B12 the code file is gone', (glob($base . '/var/lib/api/pairing/*') ?: []) === []);

$r = $call('post', '/api/v1/devices/pair', $app, null, ['code' => $plain]);
check('B13 the same code again → 403 (single use)', $r['status'] === 403 && $code($r) === 'forbidden');

$old = $devices->createPairingCode($ownerId, 'late', ['receipt.capture'], time() - ApiDevices::PAIRING_TTL_SECONDS - 1);
$r = $call('post', '/api/v1/devices/pair', $app, null, ['code' => $old]);
check('B14 an expired code → 403', $r['status'] === 403);

$threw = false;
try { $devices->createPairingCode($ownerId, 'x', ['Receipt Capture']); } catch (InvalidArgumentException) { $threw = true; }
check('B15 a malformed scope is refused at creation', $threw);
$threw = false;
try { $devices->createPairingCode(999, 'x', []); } catch (InvalidArgumentException) { $threw = true; }
check('B16 an unknown owner is refused at creation', $threw);

$statuses = [];
for ($i = 0; $i < 11; $i++) {
    $statuses[] = $call('post', '/api/v1/devices/pair', $app + ['Remote-Addr' => '203.0.113.9'], null, ['code' => 'WRONG-CODE' . $i])['status'];
}
check('B17 ten wrong codes per /64 → 403 each, the eleventh 429',
    array_slice($statuses, 0, 10) === array_fill(0, 10, 403) && $statuses[10] === 429, implode(',', $statuses));

// ── C. a paired device ───────────────────────────────────────────────────────
echo "C. device requests\n";

$r = $call('get', '/api/v1/devices/me', $app, $cookie);
check('C1 GET devices/me → label, owner name, scopes',
    $r['status'] === 200 && $r['data']['device'] === ['id' => $deviceId, 'label' => 'iPhone Peter', 'owner' => 'Peter Muster', 'scopes' => ['receipt.capture']],
    json_encode($r['data']));
$r = $call('get', '/api/v1/devices/me', ['Origin' => 'https://example.ch'], $cookie);
check('C2 cookie without X-Z77-App → 403', $r['status'] === 403);
$r = $call('get', '/api/v1/devices/me', ['X-Z77-App' => '1', 'Origin' => 'https://evil.example'], $cookie);
check('C3 cookie from a foreign Origin → 403', $r['status'] === 403);
$r = $call('get', '/api/v1/devices/me', ['X-Z77-App' => '1', 'Origin' => 'https://example.ch:8443'], $cookie);
check('C4 same host, other port is foreign → 403', $r['status'] === 403);
$r = $call('get', '/api/v1/receipts', ['X-Z77-App' => '1'], $cookie);
check('C5 same-origin GET without Origin passes (browsers send none)', $r['status'] === 200 && ($r['data']['by'] ?? '') === 'Peter Muster');
$r = $call('get', '/api/v1/units', $app, $cookie);
check('C6 a plain (machine) service is closed to devices → 403', $r['status'] === 403 && $code($r) === 'forbidden');
$r = $call('get', '/api/v1/admin', $app, $cookie);
check('C7 scope missing → 403', $r['status'] === 403);
$r = $call('post', '/api/v1/admin', $app, $cookie);
check('C8 POST where the service offers none → 405', $r['status'] === 405);
$r = $call('get', '/api/v1/health', $app, $cookie);
check('C9 health (plain) is closed to devices → 403', $r['status'] === 403);

$photo = $base . '/photo.jpg';
file_put_contents($photo, "\xFF\xD8\xFF fake jpeg");
$upload = ['photo' => ['name' => 'beleg.jpg', 'tmp_name' => $photo, 'size' => filesize($photo), 'type' => 'image/jpeg', 'error' => UPLOAD_ERR_OK]];

$r = $call('post', '/api/v1/receipts', $app, $cookie, ['amount' => '12.50'], $upload);
check('C10 device POST without Idempotency-Key → 400', $r['status'] === 400 && $code($r) === 'bad_request');
$r = $call('post', '/api/v1/receipts', $app + ['Idempotency-Key' => 'no spaces!'], $cookie, ['amount' => '12.50'], $upload);
check('C11 malformed Idempotency-Key → 400', $r['status'] === 400);
$r = $call('post', '/api/v1/receipts', ['X-Z77-App' => '1', 'Idempotency-Key' => 'k-0000-0001'], $cookie, ['amount' => '12.50'], $upload);
check('C12 device POST without Origin → 403', $r['status'] === 403);

$key   = '6f1c2a9e-0b7d-4c55-9e3a-1d2f3a4b5c6d';
$first = $call('post', '/api/v1/receipts', $app + ['Idempotency-Key' => $key], $cookie, ['amount' => '12.50'], $upload);
check('C13 device POST: body, file and device reach the service',
    $first['status'] === 200 && $first['data']['amount'] === '12.50' && $first['data']['photo'] === 'beleg.jpg'
    && $first['data']['device'] === $deviceId && $first['data']['owner'] === 'Peter Muster', json_encode($first));
$again = $call('post', '/api/v1/receipts', $app + ['Idempotency-Key' => $key], $cookie, ['amount' => '12.50'], $upload);
check('C14 resend with the same key → same answer, executed once',
    $again['status'] === 200 && $again['data'] === $first['data'] && HarnessReceiptsService::$executed === 1);
$other = $call('post', '/api/v1/receipts', $app + ['Idempotency-Key' => $key], $cookie, ['amount' => '99.00'], $upload);
check('C15 same key, different body → 409 idempotency_conflict', $other['status'] === 409 && $code($other) === 'idempotency_conflict');
$next = $call('post', '/api/v1/receipts', $app + ['Idempotency-Key' => $key . '-2'], $cookie, ['amount' => '99.00'], $upload);
check('C16 a new key runs again', $next['status'] === 200 && HarnessReceiptsService::$executed === 2);

$r = $call('get', '/api/v1/devices/me', $bearer + $app, $cookie);
check('C17 bearer + cookie → the bearer wins (machine principal, no `me`)', $r['status'] === 403);

$r = $call('get', '/api/v1/devices/me', $app, $deviceId . '.' . str_repeat('0', 64));
check('C18 tampered secret → 401', $r['status'] === 401 && $code($r) === 'unauthorized');
$r = $call('get', '/api/v1/devices/me', $app, 'garbage');
check('C19 garbage cookie → 401', $r['status'] === 401);
$r = $call('get', '/api/v1/devices/me', $app, $cookie, [], [], $channelOff);
check('C20 channel off → the cookie is ignored → 401', $r['status'] === 401);

$log = (string) @file_get_contents($base . '/logs/api.log');
check('C21 api.log names the device', str_contains($log, ' device:' . $deviceId . ' GET /api/v1/devices/me 200'));

// last_used_at: written at most once per hour
$row = fn(): ?ApiDevice => (new ApiDevices(new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']))))->find((int) $deviceId);
$before = $row()?->getLastUsedAt();
$devices->resolve($cookie, time() + 60);
check('C22 a use within the hour does not rewrite last_used_at', $row()?->getLastUsedAt() === $before);
$devices->resolve($cookie, time() + ApiDevices::TOUCH_SECONDS + 5);
check('C23 a use after the hour does', $row()?->getLastUsedAt() !== $before);

check('C24 idle past IDLE_SECONDS → dead',
    $devices->resolve($cookie, time() + ApiDevices::TOUCH_SECONDS + ApiDevices::IDLE_SECONDS + 10) === null);

check('C25 revoke → true', $devices->revoke((int) $deviceId));
$r = $call('get', '/api/v1/devices/me', $app, $cookie);
check('C26 revoked → the next request 401', $r['status'] === 401);
check('C27 revoking twice → false, row kept for the list',
    !$devices->revoke((int) $deviceId) && $row()?->isRevoked() === true);

// owner gone → the device dies with it
$code2 = $devices->createPairingCode($ownerId, 'PC', ['receipt.capture']);
$paired = $devices->redeemPairingCode($code2);
check('C28 a second device resolves', $paired !== null && $devices->resolve($paired['cookie']) !== null);
$temp = new BackendUser();
$temp->setUsername('temp');
$uem->persist($temp);
$uem->flush();
$tempPaired = $devices->redeemPairingCode($devices->createPairingCode((int) $temp->getId(), 'temp phone', []));
$uem->remove($temp);
$uem->flush();
check('C29 owner deleted → their device is dead', $tempPaired !== null && $devices->resolve($tempPaired['cookie']) === null);

// cap: the least recently used live device of an owner is revoked past MAX
$t0 = time();
for ($i = 0; $i < ApiDevices::MAX_DEVICES_PER_OWNER; $i++) {
    $devices->redeemPairingCode($devices->createPairingCode($ownerId, "cap {$i}", [], $t0 + $i + 1), null, $t0 + $i + 1);
}
$live = array_filter($devices->listFor($ownerId), fn(ApiDevice $d) => !$d->isRevoked());
check('C30 cap: at most MAX live devices per owner', count($live) === ApiDevices::MAX_DEVICES_PER_OWNER);
check('C31 … the oldest-used one (PC) went', $devices->resolve($paired['cookie']) === null);

check('C32 UA label fallback', ApiDevices::label('Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile Safari/537') === 'Chrome · Android');

// ── result ───────────────────────────────────────────────────────────────────

$_COOKIE = $_POST = $_FILES = [];
if ($fail === 0) {
    rrmdir($base);
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
