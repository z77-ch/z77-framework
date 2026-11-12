<?php

/**
 * API device-channel harness (CLI, temp ABS_BASE_PATH, file storage) — ADR-057, ADR-058.
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
 *   (B) the code sign-in (ADR-058; pairing removed): `devices/pair` is gone; `devices/login`
 *       needs X-Z77-App and a same-host Origin, answers an unknown and a known address with the
 *       same 202, the same nonce cookie and one record each (anti-oracle); `devices/verify` gives
 *       one generic 403 for no nonce, a wrong code, a nobody's record, a spent code; the right
 *       code sets the device cookie (HttpOnly, SameSite=Strict, Path=/api), only its hash and the
 *       app user land in devices.json, nothing in the idempotency store; 20 verifies per /64;
 *   (C) a device: `me`, X-Z77-App and Origin enforced (403), GET without Origin allowed, scope
 *       enforced (403), plain services closed to devices, POST needs an Idempotency-Key, a
 *       resend replays without executing twice, the same key with another body is a 409, a
 *       tampered/garbage cookie a 401, the hourly last_used_at write, idle expiry, the LIVE
 *       scope filter (the person's role drops → the scope stops), an ADR-057 row without
 *       app_user_id → 401, re-login revokes the old row, sign-out, the per-app-user cap;
 *   (D) invalid_fields and the receipt targets through the gateway;
 *   (E) the login flow itself (ApiAppLogin): mails (code, device, time, German; new device),
 *       lockout immunity, 5/h + 15/day per address silently, the daily-cap alert and its
 *       recovery, 10 per /64 → 429, a failing mail changes nothing, requests never replace
 *       each other;
 *   (F) app users (ApiAppUsers): A9 admin only, the actor rule, uniqueness, minimum roles per
 *       scope, the e-mail change (codes spent, old address told), deactivate (devices revoked,
 *       codes spent), reactivate (no device back), the accountRemovedListeners entry.
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

use Z77\Core\Config\AuthRole;
use Z77\Core\Config\Config;
use Z77\Core\DI;
use Z77\Core\Http\Request;
use Z77\Core\Http\Response\JsonResponse;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Services\ApiKeyGuard;
use Z77\Core\Services\ModuleManager;
use Z77\Module\Api\Entities\ApiAppUser;
use Z77\Module\Api\Entities\ApiDevice;
use Z77\Module\Api\Services\ApiAppLogin;
use Z77\Module\Api\Services\ApiAppUserAccountListener;
use Z77\Module\Api\Services\ApiAppUserDenied;
use Z77\Module\Api\Services\ApiAppUsers;
use Z77\Module\Api\Services\ApiDeviceScopes;
use Z77\Module\Api\Services\ApiDevices;
use Z77\Module\Api\Services\DevicesService;
use Z77\Module\Api\Ui\Controllers\Main\GatewayController;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Api\ApiRequest;
use Z77\Shared\Api\ApiResult;
use Z77\Shared\Api\ApiServiceInterface;
use Z77\Shared\Api\ScopedApiServiceInterface;
use Z77\Shared\Auth\ApiPrincipal;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Auth\LoginCodeStore;
use Z77\Shared\Auth\TenantKeyResolverInterface;
use Z77\Shared\Entities\BackendUser;
use Z77\Shared\Mail\EmailMessage;
use Z77\Shared\Throttle\FileThrottle;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

function throws(callable $fn, string $class): bool
{
    try { $fn(); } catch (\Throwable $e) { return $e instanceof $class; }
    return false;
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

/** Refuses an empty amount per field — the shape of the receipt target's 422 (envelope addition). */
final class HarnessCheckedService implements ScopedApiServiceInterface
{
    public static int $executed = 0;

    public function scopeFor(string $method, array $slugs): ?string
    {
        return $method === 'post' ? 'receipt.capture' : null;
    }

    public function handle(ApiRequest $request): ApiResult
    {
        self::$executed++;
        return ($request->body['amount'] ?? '') === ''
            ? ApiResult::invalidFields(['amount' => 'Der Betrag fehlt.'])
            : ApiResult::payload(['ok' => true]);
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
    // The minimum role per realm (ADR-058): receipt.capture as module-receipt declares it.
    'apiDeviceScopes'   => [
        'receipt.capture' => ['label' => 'Belege erfassen', 'realms' => ['backend' => AuthRole::EDITOR]],
        'admin.manage'    => ['label' => 'Verwalten', 'realms' => ['backend' => AuthRole::SUPER_USER]],
    ],
];
$channelOn  = $fakeModuleManager(['api' => $apiConfig]);
$channelOff = $fakeModuleManager(['api' => array_diff_key($apiConfig, ['apiDeviceResolver' => 1])]);

DI::getInstance(true)->set('CacheManager', CacheManager::class, true);
$uem = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
DI::getInstance()->set('UnifiedEntityManager', $uem, true);

$root = str_replace('\\', '/', dirname(__DIR__));
$user = function (string $username, string $name, array $roles) use ($uem): BackendUser {
    $u = new BackendUser();
    $u->setUsername($username);
    $u->setName($name);
    $u->setRoles($roles);
    $uem->persist($u);
    $uem->flush();
    return $u;
};
$owner   = $user('peter', 'Peter Muster', [AuthRole::EDITOR]);
$ownerId = (int) $owner->getId();
$chef    = $user('chef', 'Chef Admin', [AuthRole::ADMIN]);
$chefId  = (int) $chef->getId();

$readPrivate = fn(object $obj, string $prop) => (new ReflectionProperty($obj::class, $prop))->getValue($obj);

/**
 * One request through guard + gateway. $h: headers as `Name => value`
 * (Authorization, X-Z77-App, Origin, Idempotency-Key, Remote-Addr). $cookie: the device
 * cookie's value, or a map name => value (the login nonce beside it).
 *
 * @return array{status:int, data:array, headers:array}
 */
$call = function (string $method, string $path, array $h = [], string|array|null $cookie = null, array $post = [], array $files = [], ?ModuleManager $mm = null)
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
    $_COOKIE = is_array($cookie) ? $cookie : ($cookie === null ? [] : [ApiKeyGuard::DEVICE_COOKIE => $cookie]);
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

$codes    = new LoginCodeStore($base . '/var/lib/api/login');
$scopes   = new ApiDeviceScopes($channelOn);
$devices  = new ApiDevices($uem, $scopes);
$appUsers = new ApiAppUsers($uem, $scopes, $devices, $codes, function (EmailMessage $m) use (&$mails): bool { $mails[] = $m; return true; });
$mails    = [];
DI::getInstance()->set('ModuleManager', $channelOn, true);
$peterApp = $appUsers->create($chefId, 'peter@example.ch', ApiAppUser::REALM_BACKEND, (string) $ownerId, ['receipt.capture']);

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
$r = $call('post', '/api/v1/units', $bearer + ['Origin' => 'https://example.ch']);
check('A12 a 202 exists only where a service answers it — a bearer POST elsewhere stays 405', $r['status'] === 405);

// ── B. the code sign-in through the gateway (ADR-058) ────────────────────────
echo "B. sign-in\n";

$loginDir   = $base . '/var/lib/api/login';
$records    = fn(): array => array_merge(glob($loginDir . '/*.json') ?: [], glob($loginDir . '/nobody/*.json') ?: []);
/** The record file of a nonce, wherever its quota put it (a subject's or nobody's, review S2). */
$recordOf   = fn(string $nonce): array => (array) json_decode((string) (@file_get_contents($loginDir . '/' . hash('sha256', $nonce) . '.json')
    ?: @file_get_contents($loginDir . '/nobody/' . hash('sha256', $nonce) . '.json')), true);
$nonceOf    = function (array $r): string {
    preg_match('/^' . ApiAppLogin::NONCE_COOKIE . '=([0-9a-f]{64});/', (string) ($r['headers']['Set-Cookie'] ?? ''), $m);
    return $m[1] ?? '';
};
/** A code the harness knows: a record planted for the app user (the real one only travels by mail). */
$plant      = fn(int $appUserId, ?int $now = null): array => $codes->issue((string) $appUserId, $now ?? time());

$r = $call('post', '/api/v1/devices/login', ['Origin' => 'https://example.ch'], null, ['email' => 'peter@example.ch']);
check('B1 login without X-Z77-App → 403', $r['status'] === 403 && $code($r) === 'forbidden');
$r = $call('post', '/api/v1/devices/login', ['X-Z77-App' => '1', 'Origin' => 'https://evil.example'], null, ['email' => 'peter@example.ch']);
check('B2 login from a foreign Origin → 403', $r['status'] === 403);
$r = $call('post', '/api/v1/devices/login', ['X-Z77-App' => '1'], null, ['email' => 'peter@example.ch']);
check('B3 login POST without Origin → 403', $r['status'] === 403);
$r = $call('post', '/api/v1/devices/login', $app, null, ['email' => 'peter@example.ch'], [], $channelOff);
check('B4 channel off (no apiDeviceResolver) → login is a keyless 401', $r['status'] === 401);
$r = $call('post', '/api/v1/devices/login', $app, null, []);
check('B5 login without an address → 400 bad_request', $r['status'] === 400 && $code($r) === 'bad_request');
$r = $call('post', '/api/v1/devices/pair', $app, null, ['code' => 'ABCDE-FGHJK']);
check('B6 the pairing call is gone: POST devices/pair → 401 (no anonymous door any more)', $r['status'] === 401);

$before  = count($records());
$unknown = $call('post', '/api/v1/devices/login', $app + ['Remote-Addr' => '198.51.100.1'], null, ['email' => 'nobody@example.ch']);
$afterU  = $records();
$known   = $call('post', '/api/v1/devices/login', $app + ['Remote-Addr' => '198.51.100.1'], null, ['email' => ' Peter@Example.CH ']);
$afterK  = $records();
$setU    = (string) ($unknown['headers']['Set-Cookie'] ?? '');
$shape   = fn(string $c): string => (string) preg_replace(['/=[0-9a-f]{64};/', '/Expires=[^;]+;/'], ['=N;', 'Expires=T;'], $c);
check('B7 an unknown and a known address get the same 202 and the same body',
    $unknown['status'] === 202 && $known['status'] === 202 && $unknown['data'] === $known['data'] && $unknown['data'] === ['status' => 'accepted'], json_encode([$unknown, $known]));
check('B8 … and the same nonce cookie: z77_api_login, HttpOnly, SameSite=Strict, Path=/api, 15 minutes',
    str_starts_with($setU, ApiAppLogin::NONCE_COOKIE . '=') && str_contains($setU, '; HttpOnly') && str_contains($setU, 'SameSite=Strict')
    && str_contains($setU, 'Path=/api') && str_contains($setU, 'Max-Age=900') && $shape($setU) === $shape((string) ($known['headers']['Set-Cookie'] ?? '')), $setU);
$recU = $recordOf($nonceOf($unknown));
$recK = $recordOf($nonceOf($known));
check('B9 … and one record each: the unknown one for nobody, the known one for the app user (address normalised)',
    count($afterU) === $before + 1 && count($afterK) === $before + 2 && array_key_exists('subject', (array) $recU) && $recU['subject'] === null && ($recK['subject'] ?? null) === (string) $peterApp->getId()
    && array_keys($recU) === array_keys($recK), json_encode([$recU, $recK]));

$r = $call('post', '/api/v1/devices/verify', $app, null, ['code' => '123456']);
check('B10 verify without the nonce cookie → 403, the generic text', $r['status'] === 403 && ($r['data']['error']['message'] ?? '') === DevicesService::VERIFY_FAILED);
$generic = $r['data'];
$p1      = $plant((int) $peterApp->getId());
$wrong   = $p1['code'] === '000000' ? '000001' : '000000';
$r = $call('post', '/api/v1/devices/verify', $app, [ApiAppLogin::NONCE_COOKIE => $p1['nonce']], ['code' => $wrong]);
check('B11 a wrong code → the SAME 403 (no tries left, no difference)', $r['status'] === 403 && $r['data'] === $generic);
$r = $call('post', '/api/v1/devices/verify', $app, [ApiAppLogin::NONCE_COOKIE => $nonceOf($unknown)], ['code' => '123456']);
check('B12 the nonce of an unknown address → the same 403', $r['status'] === 403 && $r['data'] === $generic);
$r = $call('post', '/api/v1/devices/verify', $app, null, []);
check('B13 verify without a code → 400', $r['status'] === 400);

$r = $call('post', '/api/v1/devices/verify', $app + ['User-Agent' => 'Mozilla/5.0 (iPhone) Safari/605'],
    [ApiAppLogin::NONCE_COOKIE => $p1['nonce'], ApiKeyGuard::DEVICE_COOKIE => 'stale.cookie'], ['code' => $p1['code']]);
check('B14 the right code (a stale device cookie present: still the anonymous door) → 200', $r['status'] === 200, json_encode($r));
$setCookie = (string) ($r['headers']['Set-Cookie'] ?? '');
check('B15 Set-Cookie: the device cookie, HttpOnly, SameSite=Strict, Path=/api',
    str_starts_with($setCookie, ApiKeyGuard::DEVICE_COOKIE . '=')
    && str_contains($setCookie, '; HttpOnly') && str_contains($setCookie, 'SameSite=Strict') && str_contains($setCookie, 'Path=/api'), $setCookie);
check('B16 the answer names the device, the linked person, the app user (its id — the app stamps its queue with it, S1) and the app user\'s scopes',
    ($r['data']['device']['label'] ?? '') === 'Safari · iPhone'
    && ($r['data']['device']['owner'] ?? '') === 'Peter Muster'
    && ($r['data']['device']['appUser'] ?? null) === (string) $peterApp->getId()
    && ($r['data']['device']['scopes'] ?? null) === ['receipt.capture'], json_encode($r['data']));
preg_match('/^' . ApiKeyGuard::DEVICE_COOKIE . '=([^;]+)/', $setCookie, $m);
$cookie = $m[1] ?? '';
[$deviceId, $secret] = array_pad(explode('.', $cookie, 2), 2, '');
$json = (string) @file_get_contents($base . '/data/framework/api/devices.json');
check('B17 devices.json holds sha256(secret) and the app user, never the secret',
    $secret !== '' && str_contains($json, hash('sha256', $secret)) && !str_contains($json, $secret)
    && $devices->find((int) $deviceId)?->getAppUserId() === (int) $peterApp->getId()
    && $devices->find((int) $deviceId)?->getOwnerId() === $ownerId);
check('B18 nothing of the answer was kept by the idempotency store (the door stays anonymous)',
    !str_contains(implode('', array_map('file_get_contents', glob($base . '/var/lib/api/idempotency/*') ?: [])), $secret));
check('B19 the app user\'s last sign-in is recorded', $appUsers->find((int) $peterApp->getId())?->getLastLoginAt() !== '');
$r = $call('post', '/api/v1/devices/verify', $app, [ApiAppLogin::NONCE_COOKIE => $p1['nonce']], ['code' => $p1['code']]);
check('B20 the same code again → 403 (spent)', $r['status'] === 403 && $r['data'] === $generic);

$statuses = [];
for ($i = 0; $i < ApiAppLogin::VERIFIES_PER_HOUR_PER_NET + 1; $i++) {
    $statuses[] = $call('post', '/api/v1/devices/verify', $app + ['Remote-Addr' => '203.0.113.9'], [ApiAppLogin::NONCE_COOKIE => str_repeat('a', 64)], ['code' => '123456'])['status'];
}
check('B21 twenty verifies per /64 → 403 each, the 21st 429',
    array_slice($statuses, 0, 20) === array_fill(0, 20, 403) && $statuses[20] === 429, implode(',', $statuses));

// ── C. a signed-in device ────────────────────────────────────────────────────
echo "C. device requests\n";

$r = $call('get', '/api/v1/devices/me', $app, $cookie);
check('C1 GET devices/me → label, owner name, app user id, scopes',
    $r['status'] === 200 && $r['data']['device'] === ['id' => $deviceId, 'label' => 'Safari · iPhone', 'owner' => 'Peter Muster', 'appUser' => (string) $peterApp->getId(), 'scopes' => ['receipt.capture']],
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

$row = fn(): ?ApiDevice => (new ApiDevices(new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']))))->find((int) $deviceId);
DI::getInstance()->set('ModuleManager', $channelOn, true);
$before = $row()?->getLastUsedAt();
$devices->resolve($cookie, time() + 60);
check('C22 a use within the hour does not rewrite last_used_at', $row()?->getLastUsedAt() === $before);
$devices->resolve($cookie, time() + ApiDevices::TOUCH_SECONDS + 5);
check('C23 a use after the hour does', $row()?->getLastUsedAt() !== $before);
check('C24 idle past IDLE_SECONDS → dead',
    $devices->resolve($cookie, time() + ApiDevices::TOUCH_SECONDS + ApiDevices::IDLE_SECONDS + 10) === null);

// The live scope filter (review B7): rights can shrink between two requests.
$owner->setRoles([AuthRole::MEMBER]);
$uem->persist($owner);
$uem->flush();
$r  = $call('get', '/api/v1/devices/me', $app, $cookie);
$r2 = $call('get', '/api/v1/receipts', $app, $cookie);
check('C25 the linked person drops below EDITOR → the scope stops applying at once (me shows none, the target 403)',
    $r['status'] === 200 && $r['data']['device']['scopes'] === [] && $r2['status'] === 403, json_encode($r['data']));
$owner->setRoles([AuthRole::EDITOR]);
$uem->persist($owner);
$uem->flush();
check('C26 … and applies again once the role is back', $call('get', '/api/v1/receipts', $app, $cookie)['status'] === 200);

// A row of the ADR-057 pairing (owner_id 1, no app_user_id) resolves to nothing.
$legacySecret = bin2hex(random_bytes(32));
$legacy = new ApiDevice(['owner_id' => $ownerId, 'label' => 'old phone', 'scopes' => ['receipt.capture'], 'key_hash' => hash('sha256', $legacySecret),
    'created_at' => date(DATE_ATOM), 'last_used_at' => date(DATE_ATOM)]);
$uem->persist($legacy);
$uem->flush();
$r = $call('get', '/api/v1/devices/me', $app, $legacy->getId() . '.' . $legacySecret);
check('C27 a device row without app_user_id (paired under ADR-057, owner 1) → 401 — owner_id is never read as an app user',
    $legacy->getAppUserId() === 0 && $r['status'] === 401);

// Re-login on a signed-in device as the same app user revokes the old row.
$p2 = $plant((int) $peterApp->getId());
$r  = $call('post', '/api/v1/devices/verify', $app, [ApiAppLogin::NONCE_COOKIE => $p2['nonce'], ApiKeyGuard::DEVICE_COOKIE => $cookie], ['code' => $p2['code']]);
preg_match('/^' . ApiKeyGuard::DEVICE_COOKIE . '=([^;]+)/', (string) ($r['headers']['Set-Cookie'] ?? ''), $m);
$cookie2 = $m[1] ?? '';
check('C28 signing in again on this device: a new device, the old row revoked',
    $r['status'] === 200 && $cookie2 !== '' && $cookie2 !== $cookie && $row()?->isRevoked() === true
    && $call('get', '/api/v1/devices/me', $app, $cookie)['status'] === 401 && $call('get', '/api/v1/devices/me', $app, $cookie2)['status'] === 200);

// Sign-out.
$r = $call('post', '/api/v1/devices/logout', $app, $cookie2);
check('C29 sign-out is a device POST: without Idempotency-Key → 400', $r['status'] === 400);
$r = $call('post', '/api/v1/devices/logout', $app + ['Idempotency-Key' => 'logout-0001'], $cookie2);
$clear = (string) ($r['headers']['Set-Cookie'] ?? '');
check('C30 sign-out → 200, the device cookie cleared (Max-Age=0, Path=/api, HttpOnly)',
    $r['status'] === 200 && str_starts_with($clear, ApiKeyGuard::DEVICE_COOKIE . '=;') && str_contains($clear, 'Max-Age=0') && str_contains($clear, 'Path=/api') && str_contains($clear, 'HttpOnly'), $clear);
check('C31 … the device is revoked: its next request 401', $call('get', '/api/v1/devices/me', $app, $cookie2)['status'] === 401);
check('C32 revoking twice → false, the row kept for the list', !$devices->revoke((int) explode('.', $cookie2)[0]) && $devices->find((int) explode('.', $cookie2)[0]) !== null);

// Cap: the least recently used live device of an app user is revoked past MAX.
$t0    = time();
$first = $devices->issue($peterApp, null, $t0);
for ($i = 1; $i <= ApiDevices::MAX_DEVICES_PER_APP_USER; $i++) {
    $devices->issue($peterApp, null, $t0 + $i);
}
$live = array_filter($devices->listForAppUser((int) $peterApp->getId()), fn(ApiDevice $d) => !$d->isRevoked());
check('C33 cap: at most MAX live devices per app user', count($live) === ApiDevices::MAX_DEVICES_PER_APP_USER);
check('C34 … the oldest-used one went', $devices->resolve($first['cookie']) === null);
check('C35 UA label', ApiDevices::label('Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile Safari/537') === 'Chrome · Android');

// ── D. field errors in the envelope; the receipt targets through the gateway (wave 2) ────────
echo "D. invalid_fields, receipt targets\n";

$withReceipt = $fakeModuleManager(['api' => $apiConfig + ['apiServices' => []], 'receipt' => ['apiServices' => [
    'receipt-templates' => \Z77\Module\Receipt\Api\ReceiptTemplatesService::class,
    'payment-methods'   => \Z77\Module\Receipt\Api\PaymentMethodsService::class,
    'receipts'          => \Z77\Module\Receipt\Api\ReceiptsService::class,
    'checked'           => HarnessCheckedService::class,
]]]);
$withReceipt->fakeConfigs['api']['apiServices'] = ['units' => HarnessUnitsService::class];

$clerk    = $user('anna', 'Anna Beispiel', [AuthRole::EDITOR]);
$clerkApp = $appUsers->create($chefId, 'anna@example.ch', ApiAppUser::REALM_BACKEND, (string) $clerk->getId(), ['receipt.capture']);
$bareUser = $user('bert', 'Bert Ohne', [AuthRole::EDITOR]);
$bareApp  = $appUsers->create($chefId, 'bert@example.ch', ApiAppUser::REALM_BACKEND, (string) $bareUser->getId(), []);
$capture  = $devices->issue($clerkApp, 'Mozilla/5.0 (iPhone) Safari/605');
$bare     = $devices->issue($bareApp, null);
check('D1 the device principal carries the device label (E8: «Handy <Benutzer> · <Gerätename>») and the app user\'s link',
    ($p = $devices->resolve($capture['cookie'])) !== null && $p->deviceLabel === 'Safari · iPhone' && $p->appUserId === (string) $clerkApp->getId()
    && $p->linkedRealm === 'backend' && $p->linkedId === (string) $clerk->getId() && $p->ownerName === 'Anna Beispiel');

$k = 'a1b2c3d4-0000-4000-8000-00000000000a';
$r1 = $call('post', '/api/v1/checked', $app + ['Idempotency-Key' => $k], $capture['cookie'], ['amount' => ''], [], $withReceipt);
check('D2 ApiResult::invalidFields → 422 invalid_fields, the field messages in error.fields',
    $r1['status'] === 422 && $code($r1) === 'invalid_fields' && ($r1['data']['error']['fields'] ?? null) === ['amount' => 'Der Betrag fehlt.'], json_encode($r1['data']));
$r2 = $call('post', '/api/v1/checked', $app + ['Idempotency-Key' => $k], $capture['cookie'], ['amount' => ''], [], $withReceipt);
check('D3 a resend replays the 422 WITH its fields, the service ran once', $r2['status'] === 422 && $r2['data'] === $r1['data'] && HarnessCheckedService::$executed === 1);
$r = $call('get', '/api/v1/nope', $bearer, null, [], [], $withReceipt);
check('D4 every other error body keeps its two keys', array_keys($r['data']['error'] ?? []) === ['code', 'message']);

$seed = static function (object $row) use ($uem): void { $uem->persist($row); $uem->flush(); };
$seed(new \Z77\Module\Receipt\Entities\ReceiptTemplate(['code' => 'tanken', 'label' => 'Tanken', 'icon' => 'fuel', 'debit_account' => '6200', 'tax_code' => 'VI', 'position' => 20]));
$seed(new \Z77\Module\Receipt\Entities\ReceiptTemplate(['code' => 'porti', 'label' => 'Porti', 'icon' => 'mail', 'debit_account' => '6500', 'tax_code' => '', 'position' => 10]));
$seed(new \Z77\Module\Receipt\Entities\ReceiptTemplate(['code' => 'alt', 'label' => 'Alt', 'debit_account' => '6500', 'tax_code' => '', 'position' => 1, 'active' => false]));
$seed(new \Z77\Module\Receipt\Entities\PaymentMethod(['code' => 'kasse', 'label' => 'Kasse', 'credit_account' => '1000', 'position' => 10]));

$r = $call('get', '/api/v1/receipt-templates', $app, $capture['cookie'], [], [], $withReceipt);
check('D5 GET receipt-templates (device with receipt.capture): the ACTIVE templates in tile order — code, label, icon, position; no account, no VAT (E2)',
    $r['status'] === 200 && $r['data'] === ['templates' => [
        ['code' => 'porti', 'label' => 'Porti', 'icon' => 'mail', 'position' => 10],
        ['code' => 'tanken', 'label' => 'Tanken', 'icon' => 'fuel', 'position' => 20],
    ]] && isset($r['headers']['ETag']), json_encode($r));
$etag = (string) ($r['headers']['ETag'] ?? '');
$_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
$r = $call('get', '/api/v1/receipt-templates', $app, $capture['cookie'], [], [], $withReceipt);
unset($_SERVER['HTTP_IF_NONE_MATCH']);
check('D6 … with the ETag again → 304', $r['status'] === 304);
$r = $call('get', '/api/v1/payment-methods', $app, $capture['cookie'], [], [], $withReceipt);
check('D7 GET payment-methods: code and label, no credit account (E10)', $r['status'] === 200 && $r['data'] === ['payment_methods' => [['code' => 'kasse', 'label' => 'Kasse']]], json_encode($r['data']));
$r = $call('get', '/api/v1/receipt-templates', $app, $bare['cookie'], [], [], $withReceipt);
check('D8 a device whose app user lacks receipt.capture → 403', $r['status'] === 403 && $code($r) === 'forbidden');
$r = $call('get', '/api/v1/receipt-templates', $bearer, null, [], [], $withReceipt);
$r2 = $call('post', '/api/v1/receipts', $bearer + ['Origin' => 'https://example.ch'], null, ['amount' => '1.00'], $upload, $withReceipt);
check('D9 a machine (bearer) caller passes the gateway but the targets refuse it → 403 (GET and POST)',
    $r['status'] === 403 && $code($r) === 'forbidden' && $r2['status'] === 403 && $code($r2) === 'forbidden', json_encode([$r['data'], $r2['data']]));
$r = $call('get', '/api/v1/receipts', $app, $capture['cookie'], [], [], $withReceipt);
$r2 = $call('post', '/api/v1/receipt-templates', $app + ['Idempotency-Key' => 'b1b2c3d4-0000'], $capture['cookie'], [], [], $withReceipt);
check('D10 receipts is POST only, the lists GET only → 405', $r['status'] === 405 && $r2['status'] === 405);
$r = $call('get', '/api/v1/receipt-templates/x', $app, $capture['cookie'], [], [], $withReceipt);
check('D11 a sub-path → 404 unknown_endpoint', $r['status'] === 404 && $code($r) === 'unknown_endpoint');
$r = $call('post', '/api/v1/receipts', $app, $capture['cookie'], ['amount' => '1.00'], $upload, $withReceipt);
check('D12 POST receipts without Idempotency-Key → 400 (the capture key is the request\'s key, E7)', $r['status'] === 400);

// ── E. the login flow (ApiAppLogin), mails and limits — the service itself ──────────────────
echo "E. login flow\n";
DI::getInstance()->set('ModuleManager', $channelOn, true);

$mails  = [];
$alerts = [];
$sendOk = true;
$flow = new ApiAppLogin(
    $appUsers, $devices, $codes, new FileThrottle($base . '/var/lib/throttle/flow'), $base . '/var/lib/api/login-cap',
    function (EmailMessage $m) use (&$mails, &$sendOk): bool { $mails[] = $m; if ($sendOk === 'throw') { throw new RuntimeException('smtp down'); } return $sendOk; },
    static function (Closure $job): void { $job(); },
    function (string $source, bool $failing, string $email) use (&$alerts): void { $alerts[] = [$source, $failing, $email]; },
);
$now = time();
$n0  = count($records());
$u   = $flow->request('stranger@example.ch', 'net-1', null, $now);
$k   = $flow->request('peter@example.ch', 'net-1', 'Mozilla/5.0 (iPhone) Safari/605', $now);
check('E1 unknown and known: the same 202 and shape, one record each; a mail only for the app user',
    $u['status'] === 202 && $k['status'] === 202 && array_keys($u) === array_keys($k) && count($records()) === $n0 + 2 && count($mails) === 1);
$mail = $mails[0];
check('E2 the code mail: to the app e-mail, German, code first in the subject, the template with device and time',
    $mail->getTo() === 'peter@example.ch' && $mail->getLanguage() === 'de' && preg_match('/^\d{6} ist dein Code für die z77 App$/', $mail->getSubject()) === 1
    && $mail->getTemplate() === 'emails/appLoginCode' && ($mail->getContext()['device'] ?? '') === 'Safari · iPhone'
    && ($mail->getContext()['code'] ?? '') === substr($mail->getSubject(), 0, 6) && ($mail->getContext()['greetingName'] ?? '') === 'Peter Muster' && ($mail->getContext()['minutes'] ?? 0) === 15);
$mailedCode = substr($mail->getSubject(), 0, 6);

// Lockout immunity (review B4): an attacker floods the victim's address and guesses with his own nonces.
for ($i = 0; $i < 30; $i++) {
    $flood = $flow->request('peter@example.ch', 'net-attacker-' . ($i % 5), null, $now);
    $flow->verify($flood['nonce'] ?? '', '000000', 'net-attacker-' . ($i % 5), null, null, $now);
}
$mailsAfterFlood = count($mails);
$ok = $flow->verify($k['nonce'], $mailedCode, 'net-1', null, 'Mozilla/5.0 (iPhone) Safari/605', $now);
check('E3 lockout immunity: 30 foreign requests and guesses later the victim\'s own code still signs in',
    $ok['status'] === 'ok' && $ok['ownerName'] === 'Peter Muster' && $ok['scopes'] === ['receipt.capture'], json_encode(array_diff_key($ok, ['device' => 1, 'appUser' => 1])));
check('E4 … the flood got 4 more code mails at most (5 per hour per address, silent past it)', $mailsAfterFlood - 1 <= ApiAppLogin::REQUESTS_PER_HOUR_PER_EMAIL - 1, (string) ($mailsAfterFlood - 1));
check('E5 a successful sign-in sends «Neues Gerät angemeldet» to the app e-mail', end($mails)->getTemplate() === 'emails/appNewDevice' && end($mails)->getTo() === 'peter@example.ch'
    && str_starts_with(end($mails)->getSubject(), 'Neues Gerät angemeldet'));

// Per address: 5 per hour, the 6th silent (record for nobody, no mail).
$mails = [];
$t = (intdiv($now, 86400) + 7) * 86400 + 3600; // a fresh day, at the start of an hour
for ($i = 0; $i < 6; $i++) {
    $last = $flow->request('anna@example.ch', 'net-' . $i, null, $t);
}
$recLast = $recordOf($last['nonce']);
check('E6 per address: five codes in an hour, the sixth request the same 202 — a record for nobody, no mail',
    $last['status'] === 202 && count($mails) === 5 && array_key_exists('subject', (array) $recLast) && $recLast['subject'] === null);

// Per address: 15 per day → the daily cap raises the alert; the next day ends it.
$mails  = [];
$alerts = [];
$sent   = 0;
for ($h = 0; $h < 4; $h++) {
    for ($i = 0; $i < 5; $i++) {
        $flow->request('bert@example.ch', 'net-d' . $h . $i, null, $t + $h * 3600);
    }
}
check('E7 per address: fifteen codes a day; the cap raises the operator alert once per request past it',
    count($mails) === ApiAppLogin::REQUESTS_PER_DAY_PER_EMAIL && $alerts !== [] && $alerts[0][1] === true && $alerts[0][2] === 'bert@example.ch', count($mails) . ' ' . json_encode($alerts));
$alerts = [];
$flow->request('bert@example.ch', 'net-next', null, $t + 86400 + 60);
check('E8 the next day the address may again, and the incident ends (recovery)', count($mails) === 16 && $alerts === [[$alerts[0][0] ?? '', false, 'bert@example.ch']], json_encode($alerts));

// Per /64: ten requests per hour, then 429.
$st = [];
for ($i = 0; $i < 11; $i++) {
    $st[] = $flow->request('x' . $i . '@example.ch', 'net-one', null, $t)['status'];
}
check('E9 per /64: ten requests an hour, the eleventh 429', array_slice($st, 0, 10) === array_fill(0, 10, 202) && $st[10] === 429, implode(',', $st));

// A send failure is no oracle: the same 202.
$sendOk = false;
$f1 = $flow->request('peter@example.ch', 'net-fail', null, $t + 30 * 86400);
$sendOk = 'throw';
$f2 = $flow->request('peter@example.ch', 'net-fail', null, $t + 30 * 86400);
$sendOk = true;
check('E10 a mail that fails (false or an exception) changes nothing in the answer', $f1['status'] === 202 && $f2['status'] === 202 && isset($f1['nonce'], $f2['nonce']));

// Requests never replace each other. Real time: the store sweeps by file time.
$mails = [];
$tq = time();
$q1 = $flow->request('anna@example.ch', 'net-q', null, $tq);
$q2 = $flow->request('anna@example.ch', 'net-q', null, $tq);
check('E11 two requests of one address: both codes stay valid',
    count($mails) === 2 && $flow->verify($q1['nonce'], substr($mails[0]->getSubject(), 0, 6), 'net-q', null, null, $tq)['status'] === 'ok'
    && $flow->verify($q2['nonce'], substr($mails[1]->getSubject(), 0, 6), 'net-q', null, null, $tq)['status'] === 'ok');

// Review M1: the operator alert is no anonymous mail with the caller's text.
$mails   = [];
$alerts  = [];
$tm      = $t + 60 * 86400;
$hostile = "Ihr Konto wird gesperrt\nBitte sofort 0800 123 456 anrufen <evil@example.ch>";
$bad     = ['"ab"@example.ch', '"a,b"@example.ch', '"a;b"@example.ch', "x@example.ch\nBcc: y@example.ch", $hostile];
$answers = [];
foreach (array_merge($bad, ['nobody-at-all@example.ch']) as $j => $addr) {
    for ($i = 0; $i < ApiAppLogin::REQUESTS_PER_DAY_PER_EMAIL + 3; $i++) {
        $answers[] = $flow->request($addr, 'net-m1-' . $j . '-' . $i, null, $tm + $i * 3600);
    }
}
$counterFiles = function (string $addr) use ($base): array {
    $key = hash('sha256', ApiAppUser::normalizeEmail($addr));
    return array_merge(
        glob($base . '/var/lib/throttle/flow/' . hash('sha256', 'app-login-mail-h:' . $key) . '-*') ?: [],
        glob($base . '/var/lib/throttle/flow/' . hash('sha256', 'app-login-mail-d:' . $key) . '-*') ?: [],
        glob($base . '/var/lib/api/login-cap/' . $key) ?: []
    );
};
check('E12 M1: a malformed or hostile address, eighteen times a day, never reaches a mail — no operator alert, no code mail',
    $alerts === [] && $mails === [], json_encode([$alerts, count($mails)]));
check('E13 M1: an address of nobody (well-formed) past the daily cap raises no alert either', $alerts === []);
check('E14 M1: neither leaves a counter or cap-marker file of its own on disk (bounded: only app users\' addresses are counted)',
    array_merge(...array_map($counterFiles, array_merge($bad, ['nobody-at-all@example.ch']))) === []);
check('E15 M1: every answer the same — 202 with a nonce, the same keys',
    array_unique(array_map(fn(array $a): string => $a['status'] . ':' . implode(',', array_keys($a)) . ':' . strlen((string) ($a['nonce'] ?? '')), $answers)) === ['202:status,nonce:64']);
check('E16 M1: … and an app user\'s address IS counted (the cap still holds for it)', $counterFiles('peter@example.ch') !== []);

// Review M1 (3): the alert and the code mail go out AFTER the response — nothing inline.
$late   = [];
$mails  = [];
$alerts = [];
$flowLate = new ApiAppLogin(
    $appUsers, $devices, $codes, new FileThrottle($base . '/var/lib/throttle/flow-late'), $base . '/var/lib/api/login-cap-late',
    function (EmailMessage $m) use (&$mails): bool { $mails[] = $m; return true; },
    function (Closure $job) use (&$late): void { $late[] = $job; },
    function (string $source, bool $failing, string $email) use (&$alerts): void { $alerts[] = [$source, $failing, $email]; },
);
for ($i = 0; $i < ApiAppLogin::REQUESTS_PER_DAY_PER_EMAIL + 1; $i++) {
    $flowLate->request('peter@example.ch', 'net-late-' . $i, null, $tm + intdiv($i, 5) * 3600);
}
check('E17 while the answer is being made nothing is mailed — neither the code mails nor the daily-cap alert (no SMTP time in the answer)',
    $mails === [] && $alerts === [] && count($late) === ApiAppLogin::REQUESTS_PER_DAY_PER_EMAIL + 1, count($mails) . ' ' . count($alerts) . ' ' . count($late));
array_map(fn(Closure $job) => $job(), $late);
check('E18 … they go out after it: fifteen codes, one alert naming the app user\'s address',
    count($mails) === ApiAppLogin::REQUESTS_PER_DAY_PER_EMAIL && count($alerts) === 1 && $alerts[0][1] === true && $alerts[0][2] === 'peter@example.ch', json_encode($alerts));

// The floor (no early finish): every request takes at least as long; a slower send is logged.
$floorLog = $base . '/floor.log';
ini_set('error_log', $floorLog);
$slow  = 0.0;
$flowFloor = new ApiAppLogin(
    $appUsers, $devices, $codes, new FileThrottle($base . '/var/lib/throttle/flow-floor'), $base . '/var/lib/api/login-cap-floor',
    function (EmailMessage $m) use (&$slow): bool { usleep((int) ($slow * 1_000_000)); return true; },
    static function (Closure $job): void { $job(); },
    null,
    0.25,
);
$t0 = microtime(true); $flowFloor->request('nobody-floor@example.ch', 'net-f1', null); $tu = microtime(true) - $t0;
$t0 = microtime(true); $flowFloor->request('peter@example.ch', 'net-f2', null);       $tk = microtime(true) - $t0;
check('E19 the floor: an unknown and a known address both take at least FLOOR (here 0.25 s)', $tu >= 0.25 && $tk >= 0.25, sprintf('%.3f %.3f', $tu, $tk));
check('E20 … within the floor nothing is logged', !str_contains((string) @file_get_contents($floorLog), 'a login request took'));
$slow = 0.4;
$flowFloor->request('peter@example.ch', 'net-f3', null);
check('E21 a mail send slower than the floor is logged (its time is measurable)',
    str_contains((string) @file_get_contents($floorLog), 'ApiAppLogin: a login request took'), (string) @file_get_contents($floorLog));
ini_restore('error_log');

// Review S1 (answer 4): a sign-in on a browser holding ANOTHER app user's live cookie ends that row.
$bertDev = $devices->issue($bareApp, null);
$p3      = $plant((int) $peterApp->getId());
$ok3     = $flow->verify($p3['nonce'], $p3['code'], 'net-s1', $bertDev['cookie'], null);
check('E22 S1: signing in as Peter on a browser with Bert\'s live cookie revokes Bert\'s row (the browser proved its secret)',
    $ok3['status'] === 'ok' && $devices->resolve($bertDev['cookie']) === null
    && $devices->find((int) explode('.', $bertDev['cookie'])[0])?->isRevoked() === true);

// ── F. app users: administration (A9), the actor rule, the lifecycle, the listener ─────────
echo "F. app users\n";
$mails = [];
check('F1 an EDITOR manages no app user (A9) — not even his own',
    throws(fn() => $appUsers->create((int) $owner->getId(), 'p2@example.ch', 'backend', (string) $owner->getId(), []), ApiAppUserDenied::class));
$boss = $user('boss', 'Boss Super', [AuthRole::SUPER_USER]);
check('F2 an ADMIN never links a person above him (a super user)',
    throws(fn() => $appUsers->create($chefId, 'boss@example.ch', 'backend', (string) $boss->getId(), []), ApiAppUserDenied::class));
check('F3 a person already linked, a taken address, a bad address, an unknown person, a member → refused',
    throws(fn() => $appUsers->create($chefId, 'other@example.ch', 'backend', (string) $owner->getId(), []), InvalidArgumentException::class)
    && throws(fn() => $appUsers->create($chefId, 'PETER@example.ch', 'backend', (string) $user('c0', 'C Null', [AuthRole::EDITOR])->getId(), []), InvalidArgumentException::class)
    && throws(fn() => $appUsers->create($chefId, 'not-an-address', 'backend', (string) $user('c1', 'C Eins', [AuthRole::EDITOR])->getId(), []), InvalidArgumentException::class)
    && throws(fn() => $appUsers->create($chefId, 'u@example.ch', 'backend', '999', []), InvalidArgumentException::class)
    && throws(fn() => $appUsers->create($chefId, 'm@example.ch', 'member', 'abc', []), InvalidArgumentException::class));
$guest = $user('gast', 'Gast Person', [AuthRole::VISITOR]);
check('F4 a scope the person\'s roles do not reach (VISITOR < EDITOR) is refused; an undeclared one too',
    throws(fn() => $appUsers->create($chefId, 'gast@example.ch', 'backend', (string) $guest->getId(), ['receipt.capture']), InvalidArgumentException::class)
    && throws(fn() => $appUsers->create($chefId, 'gast@example.ch', 'backend', (string) $guest->getId(), ['nope.thing']), InvalidArgumentException::class));
$su  = $user('su2', 'Super Zwei', [AuthRole::SUPER_USER]);
$ed2 = $user('ed2', 'Edi Zwei', [AuthRole::EDITOR]);
check('F5 a scope above the person (admin.manage needs SUPER_USER) is refused even for a super user actor; a super user may hold it',
    throws(fn() => $appUsers->create((int) $su->getId(), 'ed2@example.ch', 'backend', (string) $ed2->getId(), ['admin.manage']), InvalidArgumentException::class)
    && $appUsers->create((int) $su->getId(), 'self@example.ch', 'backend', (string) $su->getId(), ['admin.manage'])->getScopes() === ['admin.manage']);

// E-mail change: devices stay, pending codes die, the old address is told.
$dev  = $devices->issue($clerkApp, null);
$pend = $plant((int) $clerkApp->getId());
$mails = [];
$appUsers->update($chefId, (int) $clerkApp->getId(), 'Anna.Neu@example.ch', ['receipt.capture']);
check('F6 e-mail change: stored lower case, devices stay valid, pending codes spent, the OLD address told (without the new one)',
    $appUsers->find((int) $clerkApp->getId())?->getEmail() === 'anna.neu@example.ch' && $devices->resolve($dev['cookie']) !== null
    && $codes->verify($pend['nonce'], $pend['code']) === null && count($mails) === 1 && $mails[0]->getTo() === 'anna@example.ch'
    && $mails[0]->getTemplate() === 'emails/appEmailChanged' && !str_contains(json_encode($mails[0]->getContext()), 'anna.neu'));

// Deactivate: every device revoked, pending codes spent; reactivate: no device comes back.
$pend = $plant((int) $clerkApp->getId());
$appUsers->deactivate($chefId, (int) $clerkApp->getId());
check('F7 deactivate: every device revoked at once, pending codes spent, the app user inactive',
    $devices->resolve($dev['cookie']) === null && $devices->resolve($capture['cookie']) === null && $codes->verify($pend['nonce'], $pend['code']) === null
    && array_filter($devices->listForAppUser((int) $clerkApp->getId()), fn(ApiDevice $d) => !$d->isRevoked()) === []);
$pend = $plant((int) $clerkApp->getId());
check('F8 an inactive app user cannot sign in (verify asks again)', $flow->verify($pend['nonce'], $pend['code'], 'net-z', null, null)['status'] === 'failed');
$n = count($mails);
$flow->request('anna.neu@example.ch', 'net-z2', null, $t + 50 * 86400);
check('F9 … and gets no code mail', count($mails) === $n);
// Review S3: a verify that asked «active?» just before the deactivation issues its device (and a
// code is still live) just after the revoke — reactivate must not bring either back.
$raced     = $devices->issue($appUsers->find((int) $clerkApp->getId()), null);
$racedCode = $plant((int) $clerkApp->getId());
$appUsers->reactivate($chefId, (int) $clerkApp->getId());
check('F10 reactivate: active, but no device comes back (a fresh code sign-in)', $appUsers->find((int) $clerkApp->getId())?->isActive() === true && $devices->resolve($dev['cookie']) === null);
check('F10a S3: a device issued in the race after the deactivation is revoked by reactivate, a code of before is spent',
    $devices->resolve($raced['cookie']) === null && $codes->verify($racedCode['nonce'], $racedCode['code']) === null);

// Review S4: the actor rule also holds for update (e-mail redirect), deactivate and reactivate.
$suApp = $appUsers->findLinked('backend', (string) $su->getId());
check('F10b S4: an ADMIN may not redirect the app e-mail of a super user\'s app user (the code would go to him)',
    throws(fn() => $appUsers->update($chefId, (int) $suApp->getId(), 'chef-inbox@example.ch', ['admin.manage']), ApiAppUserDenied::class)
    && $appUsers->find((int) $suApp->getId())?->getEmail() === 'self@example.ch');
check('F10c S4: … nor deactivate it', throws(fn() => $appUsers->deactivate($chefId, (int) $suApp->getId()), ApiAppUserDenied::class)
    && $appUsers->find((int) $suApp->getId())?->isActive() === true);
$appUsers->deactivate((int) $su->getId(), (int) $suApp->getId());
check('F10d S4: … nor reactivate it', throws(fn() => $appUsers->reactivate($chefId, (int) $suApp->getId()), ApiAppUserDenied::class)
    && $appUsers->find((int) $suApp->getId())?->isActive() === false);

// Minor: a plain address only — no quoted local part, no list or header separators.
$plain = $user('plain', 'Plain Adresse', [AuthRole::EDITOR]);
check('F10e the app e-mail refuses `"`, `,`, `;` and white space (FILTER_VALIDATE_EMAIL alone takes "ab"@x, "a,b"@x, "a;b"@x)',
    throws(fn() => $appUsers->create($chefId, '"ab"@example.ch', 'backend', (string) $plain->getId(), []), InvalidArgumentException::class)
    && throws(fn() => $appUsers->create($chefId, '"a,b"@example.ch', 'backend', (string) $plain->getId(), []), InvalidArgumentException::class)
    && throws(fn() => $appUsers->create($chefId, '"a;b"@example.ch', 'backend', (string) $plain->getId(), []), InvalidArgumentException::class)
    && throws(fn() => $appUsers->create($chefId, '"a b"@example.ch', 'backend', (string) $plain->getId(), []), InvalidArgumentException::class)
    && throws(fn() => $appUsers->update($chefId, (int) $peterApp->getId(), '"p;q"@example.ch', ['receipt.capture']), InvalidArgumentException::class));

// Minor: uniqueness is checked and written under a lock — while another holds it, create waits, then gives up.
@mkdir(dirname(ApiAppUsers::lockFile()), 0755, true);
$held = fopen(ApiAppUsers::lockFile(), 'c');
flock($held, LOCK_EX);
check('F10f create() checks uniqueness under the app users\' lock: while it is held, create gives up (RuntimeException), nothing written',
    throws(fn() => $appUsers->create($chefId, 'plain@example.ch', 'backend', (string) $plain->getId(), []), RuntimeException::class)
    && $appUsers->findByEmail('plain@example.ch') === null);
flock($held, LOCK_UN);
fclose($held);
check('F10g … and once it is free, create goes through', $appUsers->create($chefId, 'plain@example.ch', 'backend', (string) $plain->getId(), [])->getEmail() === 'plain@example.ch');

// The listener: a deleted backend user's app user is deactivated.
$doomed    = $user('weg', 'Wird Gelöscht', [AuthRole::EDITOR]);
$doomedApp = $appUsers->create($chefId, 'weg@example.ch', 'backend', (string) $doomed->getId(), ['receipt.capture']);
$doomedDev = $devices->issue($doomedApp, null);
$uem->remove($doomed);
$uem->flush();
check('F11 the linked person deleted: resolve refuses at once (person gone)', $devices->resolve($doomedDev['cookie']) === null);
(new ApiAppUserAccountListener())->accountRemoved(new AuthUser(['id' => (int) $doomed->getId(), 'realm' => AuthUser::REALM_BACKEND]));
check('F12 the accountRemovedListeners entry deactivates the app user and revokes its devices',
    $appUsers->find((int) $doomedApp->getId())?->isActive() === false && $devices->find((int) explode('.', $doomedDev['cookie'])[0])?->isRevoked() === true);
check('F13 module-api declares the listener', in_array(ApiAppUserAccountListener::class, (require $root . '/packages/module-api/src/App/Config/apiConfig.inc.php')['accountRemovedListeners'] ?? [], true));

// ── result ───────────────────────────────────────────────────────────────────

$_COOKIE = $_POST = $_FILES = [];
if ($fail === 0) {
    rrmdir($base);
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
