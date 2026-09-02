<?php

/**
 * Stateless-API harness (CLI) — the framework pieces behind a `stateless`
 * reserved route (/api): bearer-token parsing, the stateless route flag,
 * the reserved-route `stateless` passthrough, the ApiKeyGuard, and the
 * ExceptionHandler's API error envelope.
 *
 * What is load-bearing here:
 *
 *   - getBearerToken(): only a well-formed `Bearer <token>` counts — scheme
 *     case-insensitive, Basic/naked headers are null; the Apache CGI fallback
 *     (REDIRECT_HTTP_AUTHORIZATION) is read, the direct key wins;
 *   - Request::isStateless() defaults to false — a stateful request can never
 *     accidentally take the API branch;
 *   - getReservedRoutes() passes `stateless` through and defaults it to false
 *     (the /media declaration predates the flag and must stay stateful);
 *   - ApiKeyGuard: missing header → 401, unknown key → 401 (same code — no
 *     oracle), matching key → pass + tenant; the envelope carries
 *     WWW-Authenticate and no-store; a missing, duplicate, or wrongly typed
 *     apiKeyResolver declaration THROWS (config error — fail fast, not open);
 *   - ExceptionHandler on a stateless request: JSON error envelope, never
 *     HTML — even for a dotted path (FileNotFoundException short-circuit).
 *
 * Run: php tests/api-stateless-guard.php
 */

// Minimal PSR-4 autoloader over the packages — the harnesses run without a
// composer install (see tests/country-blocklist.php).
spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Core\\'        => __DIR__ . '/../packages/kernel/core/src/',
        'Z77\\Shared\\'      => __DIR__ . '/../packages/kernel/shared/src/',
        'Z77\\Persistence\\' => __DIR__ . '/../packages/kernel/persistence/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use Z77\Core\Config\Config;
use Z77\Core\DI;
use Z77\Core\Exception\ExceptionHandler;
use Z77\Core\Exception\FileNotFoundException;
use Z77\Core\Exception\NotFoundException;
use Z77\Core\Http\Request;
use Z77\Core\Http\Response\JsonResponse;
use Z77\Core\Services\ApiKeyGuard;
use Z77\Core\Services\ModuleManager;
use Z77\Shared\Auth\ApiPrincipal;
use Z77\Shared\Auth\TenantKeyResolverInterface;

// ── child mode: ExceptionHandler calls exit, so it runs in a subprocess ──────
if (($argv[1] ?? '') === '--exception-child') {
    $request = (new ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    $prop = new ReflectionProperty(Request::class, 'statelessRoute');
    $prop->setValue($request, true);
    DI::getInstance(true)->set('Request', $request, true);

    $e = ($argv[2] ?? '') === 'file'
        ? new FileNotFoundException('404')
        : new NotFoundException('Action not Found: nope');
    ExceptionHandler::handle($e); // echoes the envelope and exits
    exit(99);                     // unreachable
}

$failures = 0;
$check = function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? 'OK  ' : 'FAIL') . "  $label\n";
    if (!$ok) {
        $failures++;
    }
};

// The guard's 401 path writes the api.log — give it a place to land.
define('ABS_BASE_PATH', sys_get_temp_dir() . '/z77-api-guard-' . getmypid());

/** Bare Request without booting routing/DI (constructor needs both); method
 *  and URI are seeded so ApiLog can render its line. */
$bareRequest = function (): Request {
    $r = (new ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Request::class, 'method'))->setValue($r, 'get');
    (new ReflectionProperty(Request::class, 'rawRequestUri'))->setValue($r, '/api/v1/test');
    return $r;
};

$readPrivate = function (object $obj, string $prop) {
    $p = new ReflectionProperty($obj::class, $prop);
    return $p->getValue($obj);
};

// ── A. getBearerToken ────────────────────────────────────────────────────────

unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
$r = $bareRequest();
$check('A1 no Authorization header → null', $r->getBearerToken() === null);

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc.DEF-123_456';
$check('A2 well-formed Bearer → token', $r->getBearerToken() === 'abc.DEF-123_456');

$_SERVER['HTTP_AUTHORIZATION'] = 'bearer lowercase-scheme';
$check('A3 scheme is case-insensitive', $r->getBearerToken() === 'lowercase-scheme');

$_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwdw==';
$check('A4 Basic scheme → null', $r->getBearerToken() === null);

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer';
$check('A5 Bearer without token → null', $r->getBearerToken() === null);

unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer via-redirect';
$check('A6 REDIRECT_ fallback (Apache CGI) is read', $r->getBearerToken() === 'via-redirect');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer direct-wins';
$check('A7 direct header wins over REDIRECT_', $r->getBearerToken() === 'direct-wins');
unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

// ── B. isStateless flag ──────────────────────────────────────────────────────

$check('B1 isStateless defaults to false', $bareRequest()->isStateless() === false);

$r = $bareRequest();
(new ReflectionProperty(Request::class, 'statelessRoute'))->setValue($r, true);
$check('B2 stateless flag reads back true', $r->isStateless() === true);

// ── C. reserved-route stateless passthrough ──────────────────────────────────

/** ModuleManager with injected module configs, no ConfigManager boot. */
$fakeModuleManager = function (array $configsByModule): ModuleManager {
    $mm = new class extends ModuleManager {
        public array $fakeConfigs = [];
        public function __construct() {} // parent boot deliberately skipped
        public function getModuleKeys(): array { return array_keys($this->fakeConfigs); }
        public function getModuleConfig(string $moduleKey): ?Config
        {
            return isset($this->fakeConfigs[$moduleKey])
                ? new Config($this->fakeConfigs[$moduleKey])
                : null;
        }
    };
    $mm->fakeConfigs = $configsByModule;
    return $mm;
};

$mm = $fakeModuleManager([
    'api' => ['reservedRoutes' => ['/api' => [
        'module' => 'api', 'group' => 'v1', 'controller' => 'units', 'action' => 'list',
        'stateless' => true,
    ]]],
    'dms' => ['reservedRoutes' => ['/media' => [
        'module' => 'dms', 'group' => 'media', 'controller' => 'output', 'action' => 'serve',
    ]]],
]);
$routes = $mm->getReservedRoutes();
$check('C1 stateless=true passes through', ($routes['/api']['stateless'] ?? null) === true);
$check('C2 undeclared stateless defaults to false', ($routes['/media']['stateless'] ?? null) === false);
$check('C3 tuple fields stay intact', ($routes['/api']['controller'] ?? '') === 'units');

// ── D. ApiKeyGuard ───────────────────────────────────────────────────────────

// Resolver implementations the fake module configs point at (declared as
// named classes because the guard instantiates them from FQCN strings).
final class HarnessKeyResolver implements TenantKeyResolverInterface
{
    public function resolve(string $plainKey): ?ApiPrincipal
    {
        // Contract: stored hash, compared with hash_equals. Two connections
        // of one tenant — the guard must surface WHICH one called.
        if (hash_equals(hash('sha256', 'good-key'), hash('sha256', $plainKey))) {
            return new ApiPrincipal('zihlundsee', 'pilot');
        }
        if (hash_equals(hash('sha256', 'tenant-only-key'), hash('sha256', $plainKey))) {
            return new ApiPrincipal('zihlundsee'); // no keyRef — legacy shape
        }
        return null;
    }
}
final class HarnessNotAResolver {}

$guardWith = function (array $configsByModule) use ($fakeModuleManager, $bareRequest): ApiKeyGuard {
    DI::getInstance(true)->set('Request', $bareRequest(), true);
    return new ApiKeyGuard($fakeModuleManager($configsByModule));
};
$declared = ['api' => ['apiKeyResolver' => HarnessKeyResolver::class]];

unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
$denied = $guardWith($declared)->enforce();
$check('D1 missing header → JsonResponse', $denied instanceof JsonResponse);
$check('D2 … with status 401', $readPrivate($denied, 'status') === 401);
$check('D3 … code unauthorized', ($readPrivate($denied, 'data')['error']['code'] ?? '') === 'unauthorized');
$headers = $readPrivate($denied, 'headers');
$check('D4 … WWW-Authenticate: Bearer', ($headers['WWW-Authenticate'] ?? '') === 'Bearer');
$check('D5 … Cache-Control: no-store', ($headers['Cache-Control'] ?? '') === 'no-store');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong-key';
$denied = $guardWith($declared)->enforce();
$check('D6 unknown key → 401, same code (no oracle)',
    $denied instanceof JsonResponse
    && $readPrivate($denied, 'status') === 401
    && ($readPrivate($denied, 'data')['error']['code'] ?? '') === 'unauthorized');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer good-key';
$guard = $guardWith($declared);
$check('D7 matching key → pass (null)', $guard->enforce() === null);
$check('D8 tenant resolved', $guard->getTenantId() === 'zihlundsee');
$check('D8b connection identity surfaced (keyRef)', $guard->getKeyRef() === 'pilot');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tenant-only-key';
$guard = $guardWith($declared);
$check('D8c tenant-only resolver shape still passes, keyRef null',
    $guard->enforce() === null && $guard->getKeyRef() === null);

$threw = false;
try { $guardWith([])->enforce(); } catch (LogicException) { $threw = true; }
$check('D9 no resolver declared → LogicException', $threw);

$threw = false;
try {
    $guardWith([
        'a' => ['apiKeyResolver' => HarnessKeyResolver::class],
        'b' => ['apiKeyResolver' => HarnessKeyResolver::class],
    ])->enforce();
} catch (LogicException) { $threw = true; }
$check('D10 duplicate resolver declaration → LogicException', $threw);

$threw = false;
try { $guardWith(['a' => ['apiKeyResolver' => HarnessNotAResolver::class]])->enforce(); }
catch (LogicException) { $threw = true; }
$check('D11 wrongly typed resolver → LogicException', $threw);

$check('D12 getTenantId before enforce → LogicException', (function () use ($guardWith, $declared) {
    try { $guardWith($declared)->getTenantId(); return false; }
    catch (LogicException) { return true; }
})());
unset($_SERVER['HTTP_AUTHORIZATION']);

$logFile = ABS_BASE_PATH . '/logs/api.log';
$check('D13 guard denials landed in api.log (tenant `-`, 401)',
    is_file($logFile) && str_contains((string) file_get_contents($logFile), ' - GET /api/v1/test 401'));

// ── F. unauthenticated flood → throttled BEFORE the log ─────────────────────

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$guard = $guardWith($declared); // no Authorization header set → every enforce fails

$all401 = true;
for ($i = 0; $i < 30; $i++) {
    $r = $guard->enforce();
    $all401 = $all401 && $r instanceof JsonResponse && $readPrivate($r, 'status') === 401;
}
$check('F1 failures under the limit answer 401', $all401);

$linesBefore = substr_count((string) file_get_contents($logFile), "\n");
$r = $guard->enforce();
$check('F2 past the limit → 429', $r instanceof JsonResponse && $readPrivate($r, 'status') === 429);
$check('F3 … with Retry-After', ($readPrivate($r, 'headers')['Retry-After'] ?? '') !== '');
$check('F4 … and NO api.log line (the flood is the disk risk)',
    substr_count((string) file_get_contents($logFile), "\n") === $linesBefore);

$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:aaaa::1';
$check('F5 other source (/64) unaffected → still 401',
    $readPrivate($guardWith($declared)->enforce(), 'status') === 401);

unset($_SERVER['REMOTE_ADDR']);
$check('F6 no usable address → 401 path, never blocked',
    $readPrivate($guardWith($declared)->enforce(), 'status') === 401);

// ── E. ExceptionHandler envelope on a stateless request (subprocess) ─────────

$run = fn(string $variant): string =>
    (string) shell_exec('php ' . escapeshellarg(__FILE__) . ' --exception-child ' . $variant);

$out  = $run('notfound');
$body = json_decode($out, true);
$check('E1 stateless 404 → JSON envelope, unknown_endpoint',
    is_array($body) && ($body['error']['code'] ?? '') === 'unknown_endpoint');
$check('E2 … no HTML in the body', stripos($out, '<h1>') === false);

$out  = $run('file');
$body = json_decode($out, true);
$check('E3 dotted path (FileNotFound) also answers JSON, not the HTML short-circuit',
    is_array($body) && ($body['error']['code'] ?? '') === 'unknown_endpoint');

// ── result ───────────────────────────────────────────────────────────────────

echo $failures === 0 ? "\nALL GREEN\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
