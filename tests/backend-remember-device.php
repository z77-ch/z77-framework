<?php

/**
 * Backend session clocks + «Auf diesem Gerät angemeldet bleiben» (CLI, temp ABS_BASE_PATH, no
 * database) — login.md LOGIN-SESSION-001 and LOGIN-REMEMBER-001. What is load-bearing:
 *
 *   A. the policy: defaults, the config keys, a nonsense value throws, the two clocks, the
 *      collector's floor;
 *   B. AuthService enforces the clocks from the session itself: idle and absolute end the
 *      identity and leave the reason, a live request rolls «last seen», the member realm and a
 *      slot from before the clocks are left alone;
 *   C. the device store: only a hash on disk, rotation on every use, the grace for a second
 *      tab, a replaced token presented again removes the device, expiry, logout, «all devices»,
 *      the cap;
 *   D. the real flow through the framework in PHP's built-in server: where the session files
 *      live, the notice after an idle sign-out and the way back, the cookie's flags, «browser
 *      closed → still in», rotation, a stolen copy, logout, the password change that ends every
 *      device, the throttle, 2FA with and without `rememberSkipsSecondFactor`, and the switch
 *      that turns the whole feature off.
 *
 * Run: php tests/backend-remember-device.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "backend-remember-device: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}

$root = str_replace('\\', '/', dirname(__DIR__));
$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-remember-device-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($base . '/data', 0777, true);
define('ABS_BASE_PATH', $base);

require $autoload;
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';

use Z77\Core\Controller\ControllerHandler;
use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Session\SessionManager;
use Z77\Module\Backend\Remember\DeviceRedeem;
use Z77\Module\Backend\Remember\RememberedDevices;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Auth\SessionPolicy;
use Z77\Shared\Auth\Totp;
use Z77\Shared\Auth\TotpVault;
use Z77\Shared\Entities\BackendUser;
use Z77\Shared\Services\AuthService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? " — {$got}" : '') . "\n"; }
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

function throws(callable $fn): bool
{
    try { $fn(); } catch (\ValueError) { return true; }
    return false;
}

$logFile = $base . '/php-error.log';
ini_set('error_log', $logFile);

// ── A. the policy ───────────────────────────────────────────────────────────
echo "A. Session policy\n";
$default = new SessionPolicy();
check('defaults: 60 min idle, 12 h absolute, remember on for 30 days, a remembered device skips the code',
    $default->idleSeconds === 3600 && $default->lifetimeSeconds === 43200 && $default->rememberDevice
    && $default->rememberDeviceSeconds === 30 * 86400 && $default->rememberSkipsSecondFactor);
$authDefault = require $root . '/packages/kernel/core/src/Config/auth.default.inc.php';
check('auth.default.inc.php carries the five keys, bound to the class',
    ($authDefault['sessionIdleMinutes'] ?? null) === SessionPolicy::IDLE_MINUTES
    && ($authDefault['sessionLifetimeHours'] ?? null) === SessionPolicy::LIFETIME_HOURS
    && ($authDefault['rememberDevice'] ?? null) === SessionPolicy::REMEMBER_DEVICE
    && ($authDefault['rememberDeviceDays'] ?? null) === SessionPolicy::REMEMBER_DEVICE_DAYS
    && ($authDefault['rememberSkipsSecondFactor'] ?? null) === SessionPolicy::REMEMBER_SKIPS_SECOND_FACTOR);
check('the shipped default reads as the default policy', SessionPolicy::fromValues($authDefault) == $default);
check('a seed-once file without the keys reads as the default', SessionPolicy::fromValues(['passwordTier' => 'strong']) == $default);
$custom = SessionPolicy::fromValues(['sessionIdleMinutes' => 15, 'sessionLifetimeHours' => 8, 'rememberDevice' => false, 'rememberDeviceDays' => 7, 'rememberSkipsSecondFactor' => false]);
check('configured values in their units', $custom->idleSeconds === 900 && $custom->lifetimeSeconds === 28800 && !$custom->rememberDevice && $custom->rememberDeviceSeconds === 604800 && !$custom->rememberSkipsSecondFactor);
check('a nonsense number throws (0, negative, text, a float)',
    throws(fn() => SessionPolicy::fromValues(['sessionIdleMinutes' => 0]))
    && throws(fn() => SessionPolicy::fromValues(['sessionLifetimeHours' => -1]))
    && throws(fn() => SessionPolicy::fromValues(['rememberDeviceDays' => '30']))
    && throws(fn() => SessionPolicy::fromValues(['sessionIdleMinutes' => 1.5])));
check('a switch that is not a boolean throws', throws(fn() => SessionPolicy::fromValues(['rememberDevice' => 'false'])) && throws(fn() => SessionPolicy::fromValues(['rememberSkipsSecondFactor' => 0])));
$t = 1800000000;
check('clocks: fresh login is live', $default->expiryOf(['login_at' => $t, 'seen_at' => $t], $t) === null);
check('clocks: one second before the idle limit is live', $default->expiryOf(['login_at' => $t, 'seen_at' => $t], $t + 3599) === null);
check('clocks: at the idle limit → idle', $default->expiryOf(['login_at' => $t, 'seen_at' => $t], $t + 3600) === SessionPolicy::EXPIRED_IDLE);
check('clocks: active all day, at 12 h → lifetime', $default->expiryOf(['login_at' => $t, 'seen_at' => $t + 43190], $t + 43200) === SessionPolicy::EXPIRED_LIFETIME);
check('clocks: a slot without timestamps is not expired', $default->expiryOf(['id' => 1], $t) === null);
check('collector: never below the longer clock', $default->gcMaxLifetime() === 43200);
check('collector: a floor of 4 h under short clocks (other doors keep state in the session)', SessionPolicy::fromValues(['sessionIdleMinutes' => 5, 'sessionLifetimeHours' => 1])->gcMaxLifetime() === 14400);
check('session files under the SHARED branch of var/', SessionPolicy::saveDir() === $base . '/var/lib/sessions');

// ── B. AuthService enforces the clocks ──────────────────────────────────────
echo "B. AuthService clocks\n";
DI::getInstance(true)->set('CacheManager', CacheManager::class, true);
$uem = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
DI::getInstance()->set('UnifiedEntityManager', $uem, true);
$max = new BackendUser(['username' => 'max', 'roles' => ['admin']]);
$max->setPasswordHash(password_hash('old-passwort-lang', PASSWORD_BCRYPT, ['cost' => 4]));
$uem->persist($max);
$uem->flush();

final class HarnessSession extends SessionManager
{
    public int $regenerated = 0;
    public function regenerate(): void { $this->regenerated++; }
}
$handler = (new ReflectionClass(ControllerHandler::class))->newInstanceWithoutConstructor();
$session = new HarnessSession();
$auth    = fn(?SessionPolicy $policy = null): AuthService => new AuthService($session, $handler, $policy ?? new SessionPolicy());

$_SESSION = [];
$auth()->canLogin($max, 'old-passwort-lang')->login();
$slot = $_SESSION['auth_user'];
check('login stamps both clocks', abs($slot['login_at'] - time()) <= 2 && $slot['seen_at'] === $slot['login_at']);
check('login regenerates the session id', $session->regenerated === 1);

$_SESSION['auth_user']['seen_at'] = time() - 600;
check('a request inside the limit: still signed in', $auth()->getCurrentUser()->isLoggedIn());
check('…and it is the new «last seen»', abs($_SESSION['auth_user']['seen_at'] - time()) <= 2);

$_SESSION['auth_user']['seen_at'] = time() - 3601;
check('idle for longer than the limit: guest', !$auth()->getCurrentUser()->isLoggedIn());
check('…the identity is gone from the session', !isset($_SESSION['auth_user']));
check('…and the reason waits for the login page', ($_SESSION[AuthService::EXPIRED_KEY] ?? null) === SessionPolicy::EXPIRED_IDLE);

$auth()->canLogin($max, 'old-passwort-lang')->login();
check('a new login clears the reason', !isset($_SESSION[AuthService::EXPIRED_KEY]));
$_SESSION['auth_user']['login_at'] = time() - 43201;
$_SESSION['auth_user']['seen_at']  = time() - 5;
check('active, but signed in for longer than the absolute limit: guest', !$auth()->getCurrentUser()->isLoggedIn() && ($_SESSION[AuthService::EXPIRED_KEY] ?? null) === SessionPolicy::EXPIRED_LIFETIME);

$_SESSION = [];
$auth()->canLogin($max, 'old-passwort-lang')->login();
$_SESSION['auth_user']['seen_at'] = time() - 400;
check('the limit comes from the policy (5 minutes configured)', !$auth(SessionPolicy::fromValues(['sessionIdleMinutes' => 5]))->getCurrentUser()->isLoggedIn());

$_SESSION = ['auth_user' => ['id' => $max->getId(), 'user_name' => 'max', 'roles' => ['admin'], 'realm' => AuthUser::REALM_BACKEND, 'auth_epoch' => $max->getAuthEpoch()]];
check('a slot from before the clocks stays signed in', $auth()->getCurrentUser()->isLoggedIn());
check('…and is stamped now', abs(($_SESSION['auth_user']['login_at'] ?? 0) - time()) <= 2 && abs(($_SESSION['auth_user']['seen_at'] ?? 0) - time()) <= 2);

$_SESSION = ['auth_user' => ['id' => 'acc-1', 'user_name' => 'kunde@example.ch', 'roles' => ['customer'], 'realm' => AuthUser::REALM_MEMBER, 'seen_at' => 1, 'login_at' => 1]];
check('the member realm is not touched by the backend clocks', $auth()->getCurrentUser()->isLoggedIn() && $_SESSION['auth_user']['seen_at'] === 1 && !isset($_SESSION[AuthService::EXPIRED_KEY]));

$_SESSION = [];
$totpUser = new BackendUser(['username' => 'tina', 'roles' => ['admin']]);
$totpUser->enableTotp('cipher', '2026-11-20T10:00:00+01:00', 0);
check('a device that does not cover the code cannot sign an account with 2FA in', (static function () use ($auth, $totpUser): bool {
    try { $auth()->restoredFromDevice($totpUser, false)->login(); } catch (\LogicException) { return true; }
    return false;
})());
check('…nothing was written', !isset($_SESSION['auth_user']));

// ── C. the device store ─────────────────────────────────────────────────────
echo "C. Device store\n";
$devices  = new RememberedDevices();
$storeFile = $base . '/data/' . RememberedDevices::PATH;
$rows = static fn(): array => is_file($storeFile) ? json_decode((string) file_get_contents($storeFile), true) : [];
$now  = 1800000000;
$ttl  = 30 * 86400;

$cookie = $devices->issue($max, 'Chrome auf Windows', $ttl, $now);
check('cookie value: selector.validator, 16 and 32 random bytes', preg_match('/^[a-f0-9]{32}\.[a-f0-9]{64}$/', $cookie) === 1);
[$selector, $validator] = explode('.', $cookie);
$raw = (string) file_get_contents($storeFile);
check('the validator is not on disk, its SHA-256 is', !str_contains($raw, $validator) && str_contains($raw, hash('sha256', $validator)));
check('the row: user, stamp, label, fixed expiry', ($rows()[0]['user_id'] ?? 0) === $max->getId() && $rows()[0]['auth_epoch'] === $max->getAuthEpoch() && $rows()[0]['label'] === 'Chrome auf Windows' && $rows()[0]['expires_at'] === $now + $ttl);
check('two tokens never repeat', $devices->issue($max, 'x', $ttl, $now) !== $devices->issue($max, 'x', $ttl, $now));
$devices->revokeAllFor((int) $max->getId());
check('revokeAllFor removes every row of the user', $rows() === []);

$cookie = $devices->issue($max, 'Chrome auf Windows', $ttl, $now);
$first  = $devices->redeem($cookie, $now + 100);
check('redeem: the current token passes, with user and stamp', $first->outcome === DeviceRedeem::ROTATED && $first->userId === $max->getId() && $first->authEpoch === $max->getAuthEpoch());
check('…and is ROTATED: a new value with the same selector', $first->cookieValue !== null && $first->cookieValue !== $cookie && str_starts_with($first->cookieValue, explode('.', $cookie)[0] . '.'));
check('…the expiry does not roll', $first->expiresAt === $now + $ttl && $rows()[0]['expires_at'] === $now + $ttl);
$second = $devices->redeem($cookie, $now + 100 + RememberedDevices::GRACE_SECONDS);
check('the replaced token within the grace: accepted, nothing rotated, no cookie', $second->outcome === DeviceRedeem::RECENT && $second->cookieValue === null && $second->accepted());
check('…the new token is still the device\'s', $devices->redeem($first->cookieValue, $now + 170)->outcome === DeviceRedeem::ROTATED);

$cookie  = $devices->issue($max, 'Firefox auf Linux', $ttl, $now);
$rotated = $devices->redeem($cookie, $now + 10);
$stolen  = $devices->redeem($cookie, $now + 10 + RememberedDevices::GRACE_SECONDS + 1);
check('the replaced token after the grace: REUSED, with the user to end', $stolen->outcome === DeviceRedeem::REUSED && $stolen->userId === $max->getId() && !$stolen->accepted());
check('…the device is gone: its real token is dead too', $devices->redeem((string) $rotated->cookieValue, $now + 200)->outcome === DeviceRedeem::UNKNOWN);

$cookie = $devices->issue($max, 'x', $ttl, $now);
$forged = explode('.', $cookie)[0] . '.' . str_repeat('a', 64);
check('a known selector with an invented validator: REUSED, device removed', $devices->redeem($forged, $now + 1)->outcome === DeviceRedeem::REUSED && $devices->redeem($cookie, $now + 2)->outcome === DeviceRedeem::UNKNOWN);

check('unknown selector: UNKNOWN', $devices->redeem(str_repeat('b', 32) . '.' . str_repeat('c', 64), $now)->outcome === DeviceRedeem::UNKNOWN);
$malformed = ['', 'x', str_repeat('b', 32), str_repeat('b', 32) . '.', str_repeat('B', 32) . '.' . str_repeat('c', 64), str_repeat('b', 32) . '.' . str_repeat('c', 64) . "\n", '1.2.3'];
check('malformed values: UNKNOWN, no exception', array_filter($malformed, fn(string $v): bool => $devices->redeem($v, $now)->outcome !== DeviceRedeem::UNKNOWN) === []);

$devices->revokeAllFor((int) $max->getId());
$cookie = $devices->issue($max, 'x', 3600, $now);
check('expired: UNKNOWN and the row is dropped', $devices->redeem($cookie, $now + 3600)->outcome === DeviceRedeem::UNKNOWN && $rows() === []);

$cookie = $devices->issue($max, 'x', $ttl, $now);
$devices->forget(explode('.', $cookie)[0] . '.' . str_repeat('a', 64));
check('forget with the selector alone removes nothing', count($rows()) === 1);
$devices->forget($cookie);
check('forget with the device\'s cookie removes the row', $rows() === []);

$erna = new BackendUser(['username' => 'erna', 'roles' => ['admin']]);
$erna->setPasswordHash('x');
$uem->persist($erna);
$uem->flush();
$ernaCookie = $devices->issue($erna, 'x', $ttl, $now);
for ($i = 0; $i < RememberedDevices::MAX_PER_USER + 3; $i++) {
    $last = $devices->issue($max, 'device ' . $i, $ttl, $now + $i);
}
$own = array_filter($rows(), fn(array $r): bool => $r['user_id'] === $max->getId());
check('at most ' . RememberedDevices::MAX_PER_USER . ' devices per user, the longest unused dropped', count($own) === RememberedDevices::MAX_PER_USER && !in_array('device 0', array_column($own, 'label'), true) && $devices->redeem($last, $now + 50)->outcome === DeviceRedeem::ROTATED);
check('…another user\'s device is untouched', $devices->redeem($ernaCookie, $now + 50)->outcome === DeviceRedeem::ROTATED);
$devices->revokeAllFor((int) $max->getId());
check('revokeAllFor leaves the other user\'s row', count($rows()) === 1 && $rows()[0]['user_id'] === $erna->getId());
$devices->issue($erna, 'short', 10, $now);
$devices->issue($erna, 'later', $ttl, $now + 11);
check('issue sweeps expired rows', !in_array('short', array_column($rows(), 'label'), true));

// ── D. the real flow, in PHP's built-in server ──────────────────────────────
echo "D. Real flow (built-in server)\n";
$site = $base . '/site';
require __DIR__ . '/support/real-flow-site.php';
$hash = password_hash('Altes-Passwort-123', PASSWORD_BCRYPT, ['cost' => 4]);
$secret   = Totp::generateSecret();
// The vault creates its key with the first encrypt(); realFlowSite() creates the site tree itself.
$vaultDir = $base . '/vault';
mkdir($vaultDir);
$cipher = (new TotpVault($vaultDir . '/totp.key'))->encrypt($secret);
realFlowSite($site, $root, [
    ['id' => 1, 'username' => 'max', 'name' => 'Max Muster', 'email' => 'max.muster@example.ch', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 0],
    ['id' => 2, 'username' => 'erna', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 1],
    ['id' => 3, 'username' => 'tina', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 2,
        'totp_secret' => $cipher, 'totp_activated_at' => '2026-11-20T10:00:00+01:00', 'totp_last_step' => 0],
]);
copy($vaultDir . '/totp.key', $site . '/data/framework/auth/totp.key');

$authConfig = static function (array $values) use ($site): void {
    file_put_contents($site . '/config/auth.inc.php', "<?php\nreturn " . var_export($values + ['passwordTier' => 'strong'], true) . ";\n");
    // The server runs without APCu and without opcache: the next request reads the file.
};

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port  = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$server = proc_open(
    [PHP_BINARY, '-d', 'opcache.enable=0', '-d', 'apc.enabled=0', '-S', "127.0.0.1:{$port}", '-t', $site . '/public', $site . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $base . '/server.log', 'w'], 2 => ['file', $base . '/server.log', 'a']],
    $serverPipes,
    $site
);
register_shutdown_function(static function () use ($server, $base): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    ini_restore('error_log');
    rrmdir($base);
});
$deadline = microtime(true) + 10;
do {
    usleep(100000);
    $sock = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
} while ($sock === false && microtime(true) < $deadline);
if ($sock === false) {
    fwrite(STDERR, "backend-remember-device: the built-in server did not start.\n");
    exit(2);
}
fclose($sock);

/**
 * One browser: a cookie jar (public, the checks edit it) and raw requests, redirects NOT followed.
 * request() answers [status, headers, body, list of Set-Cookie lines].
 */
final class Browser
{
    /** @var array<string, string> */
    public array $cookies = [];

    public function __construct(private int $port)
    {
    }

    public function request(string $path, array|string|null $post = null, array $headers = []): array
    {
        $head = $headers;
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = "{$k}={$v}";
            }
            $head[] = 'Cookie: ' . implode('; ', $pairs);
        }
        $opts = ['ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0, 'max_redirects' => 0, 'method' => $post === null ? 'GET' : 'POST'];
        if (is_string($post)) {
            $head[] = 'Content-Type: application/json';
            $opts['content'] = $post;
        } elseif ($post !== null) {
            $head[] = 'Content-Type: application/x-www-form-urlencoded';
            $opts['content'] = http_build_query($post);
        }
        $opts['header'] = implode("\r\n", $head);
        $body   = (string) @file_get_contents("http://127.0.0.1:{$this->port}{$path}", false, stream_context_create(['http' => $opts]));
        $status = 0;
        $out    = [];
        $set    = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int) $m[1]; continue; }
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $name = strtolower(trim($name));
            $out[$name] = trim($value);
            if ($name === 'set-cookie' && preg_match('/^([^=]+)=([^;]*)/', trim($value), $c)) {
                $set[] = trim($value);
                if ($c[2] === 'deleted' || $c[2] === '' || stripos($value, 'Max-Age=0') !== false) {
                    unset($this->cookies[$c[1]]);
                } else {
                    $this->cookies[$c[1]] = $c[2];
                }
            }
        }
        return [$status, $out, $body, $set];
    }

    /** «Browser schliessen»: the session cookie has no lifetime and goes, the device cookie stays. */
    public function close(): void
    {
        unset($this->cookies['PHPSESSID']);
    }
}

const DEVICE    = RememberedDevices::COOKIE;
const DASHBOARD = '/backend/system/dashboard/overview';
$csrf = static fn(string $html): string => preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
$login = static function (Browser $b, string $username, bool $remember, string $password = 'Altes-Passwort-123') use ($csrf): array {
    [, , $html] = $b->request('/login');
    return $b->request('/backend/system/login/login', ['csrf_token' => $csrf($html), 'username' => $username, 'password' => $password] + ($remember ? ['remember' => '1'] : []));
};
$deviceLine = static function (array $set): string {
    foreach ($set as $line) {
        if (str_starts_with($line, DEVICE . '=')) {
            return $line;
        }
    }
    return '';
};
$siteStore = $site . '/data/' . RememberedDevices::PATH;
$siteRows  = static fn(): array => is_file($siteStore) ? json_decode((string) file_get_contents($siteStore), true) : [];
$siteUser  = static fn(string $username): array => array_column(json_decode((string) file_get_contents($site . '/data/framework/auth/backendUsers.json'), true), null, 'username')[$username];
$sessionFile = static fn(Browser $b): string => $site . '/var/lib/sessions/sess_' . ($b->cookies['PHPSESSID'] ?? 'none');
/** Moves a timestamp inside the session file back, as if that much time had passed. */
$age = static function (Browser $b, string $key, int $seconds) use ($sessionFile): bool {
    $file = $sessionFile($b);
    $raw  = (string) @file_get_contents($file);
    $new  = preg_replace_callback('/(s:\d+:"' . $key . '";i:)(\d+);/', static fn(array $m): string => $m[1] . ((int) $m[2] - $seconds) . ';', $raw, 1, $count);
    return $count === 1 && file_put_contents($file, $new) !== false;
};
$clearThrottle = static function () use ($site): void {
    rrmdir($site . '/var/lib/throttle/login');
};

// D1 — where the session lives.
$a = new Browser($port);
[$s, , $html] = $a->request('/login');
check('GET /login: 200 with the checkbox «Auf diesem Gerät angemeldet bleiben»', $s === 200 && str_contains($html, 'name="remember" value="1"') && str_contains($html, 'Auf diesem Gerät angemeldet bleiben'), "status {$s}");
check('the checkbox is not ticked by default', !preg_match('/name="remember"[^>]*checked/', $html));
check('the session file is in the installation\'s own var/lib/sessions', is_file($sessionFile($a)), $sessionFile($a));
check('…with the deny-all .htaccess beside it', is_file($site . '/var/lib/sessions/.htaccess') && str_contains((string) file_get_contents($site . '/var/lib/sessions/.htaccess'), 'Require all denied'));

// D2 — without the tick: a session, no device.
[$s, $h, , $set] = $login($a, 'max', false);
check('login without the tick: redirect, no device cookie, no row', ($s === 302 || $s === 303) && $deviceLine($set) === '' && $siteRows() === [], "status {$s}");
check('…the backend answers', $a->request(DASHBOARD)[0] === 200);
$a->close();
[$s, $h] = $a->request(DASHBOARD);
check('browser closed without the tick: back to the login', ($s === 302 || $s === 303) && ($h['location'] ?? '') === '/login', "status {$s}");

// D3 — the idle clock, through the stack.
$a = new Browser($port);
$login($a, 'max', false);
check('signed in again', $a->request(DASHBOARD)[0] === 200);
check('(the session file carries the clocks)', $age($a, 'seen_at', 3540));
check('59 minutes idle: still in', $a->request(DASHBOARD)[0] === 200);
$age($a, 'seen_at', 3601);
[$s, $h] = $a->request('/backend/system/backend-user/list');
check('more than 60 minutes idle: back to the login', ($s === 302 || $s === 303) && ($h['location'] ?? '') === '/login', "status {$s}");
[$s, , $html] = $a->request('/login');
check('the login says why, once', str_contains($html, 'Aus Sicherheitsgründen abgemeldet — bitte neu anmelden.'));
check('…in the notice, not as an error', (bool) preg_match('/login__notice[^>]*>Aus Sicherheitsgründen/', $html));
[, , $again] = $a->request('/login');
check('…and not a second time', !str_contains($again, 'Aus Sicherheitsgründen'));
[$s, $h] = $a->request('/backend/system/login/login', ['csrf_token' => $csrf($again), 'username' => 'max', 'password' => 'Altes-Passwort-123']);
check('after the login: back to where he was', ($h['location'] ?? '') === '/backend/system/backend-user/list', $h['location'] ?? '');
$age($a, 'login_at', 43201);
[$s, $h] = $a->request(DASHBOARD);
check('signed in for more than 12 hours: back to the login, however active', ($s === 302 || $s === 303) && ($h['location'] ?? '') === '/login', "status {$s}");
[, , $html] = $a->request('/login');
check('…with the same sentence', str_contains($html, 'Aus Sicherheitsgründen abgemeldet'));

// D4 — with the tick.
$a = new Browser($port);
[$s, , , $set] = $login($a, 'max', true);
$line = $deviceLine($set);
check('login with the tick: the device cookie is set', $line !== '' && preg_match('/^' . DEVICE . '=[a-f0-9]{32}\.[a-f0-9]{64};/', $line) === 1, $line === '' ? 'no cookie' : 'shape');
check('…HttpOnly, SameSite=Lax, path=/', stripos($line, 'HttpOnly') !== false && stripos($line, 'SameSite=Lax') !== false && (bool) preg_match('#path=/(;|$)#i', $line));
check('…not Secure on plain HTTP (it is on HTTPS, like the session cookie)', stripos($line, 'secure') === false);
check('…for 30 days', (bool) preg_match('/Max-Age=(\d+)/i', $line, $m) && abs((int) $m[1] - 30 * 86400) <= 5, $line === '' ? '' : substr($line, 100));
$first = $a->cookies[DEVICE];
check('the store: one row, the validator only as a hash', count($siteRows()) === 1 && !str_contains((string) file_get_contents($siteStore), explode('.', $first)[1]) && $siteRows()[0]['user_id'] === 1);
check('the row carries a label for a later device list', $siteRows()[0]['label'] === 'Unbekanntes Gerät');

// D5 — browser closed → still in.
$oldSession = $a->cookies['PHPSESSID'];
$a->close();
[$s, , $html, $set] = $a->request(DASHBOARD);
check('browser closed with the tick: the backend answers, no login page', $s === 200 && !str_contains($html, 'name="password"'), "status {$s}");
check('…in a NEW session', ($a->cookies['PHPSESSID'] ?? '') !== '' && $a->cookies['PHPSESSID'] !== $oldSession);
check('…and the token was rotated', $a->cookies[DEVICE] !== $first && explode('.', $a->cookies[DEVICE])[0] === explode('.', $first)[0]);
check('…the rotated cookie keeps its end date (no rolling)', (bool) preg_match('/Max-Age=(\d+)/i', $deviceLine($set), $m) && (int) $m[1] <= 30 * 86400 && (int) $m[1] > 30 * 86400 - 60);
check('a request inside the session does not touch the token', $deviceLine($a->request(DASHBOARD)[3]) === '');

// D6 — the idle clock on a remembered device.
$current = $a->cookies[DEVICE];
$age($a, 'seen_at', 3601);
[$s] = $a->request(DASHBOARD);
check('idle on a remembered device: signed in again without a word', $s === 200, "status {$s}");
check('…through the device (rotated again)', $a->cookies[DEVICE] !== $current);

// D7 — a second tab of the same browser start.
$tab = new Browser($port);
$tab->cookies[DEVICE] = $current; // the value both tabs sent before the first answer arrived
[$s, , , $set] = $tab->request(DASHBOARD);
check('a second tab with the value just replaced: in', $s === 200, "status {$s}");
check('…no device cookie from that answer (the first tab\'s is the valid one)', $deviceLine($set) === '');
check('…the device is still there', count($siteRows()) === 1);

// D8 — a copy of the cookie is used later.
$rowsNow = $siteRows();
$rowsNow[0]['rotated_at'] -= RememberedDevices::GRACE_SECONDS + 60;
file_put_contents($siteStore, json_encode($rowsNow, JSON_PRETTY_PRINT));
$epochBefore = $siteUser('max')['auth_epoch'] ?? 0;
$thief = new Browser($port);
$thief->cookies[DEVICE] = $current;
[$s, $h, , $set] = $thief->request(DASHBOARD);
check('a replaced token presented later: no entry', ($s === 302 || $s === 303) && ($h['location'] ?? '') === '/login', "status {$s}");
check('…its cookie is deleted', $deviceLine($set) !== '' && !isset($thief->cookies[DEVICE]));
check('…the device is removed', $siteRows() === []);
check('…the user\'s stamp is raised', ($siteUser('max')['auth_epoch'] ?? 0) === $epochBefore + 1);
check('…so the running session of that device ends too', in_array($a->request(DASHBOARD)[0], [302, 303], true));
check('…and its real token restores nothing', (static function () use ($a): bool { $a->close(); return in_array($a->request(DASHBOARD)[0], [302, 303], true); })());
$siteLog = (string) @file_get_contents($site . '/logs/php-error.log');
check('the log line names the user id, never a token', str_contains($siteLog, 'DeviceBridge: a replaced device token was presented again') && !str_contains($siteLog, explode('.', $current)[1]) && !str_contains($siteLog, explode('.', $current)[0]));
$clearThrottle();

// D9 — logout.
$a = new Browser($port);
$login($a, 'max', true);
$kept = $a->cookies[DEVICE];
[, , $page] = $a->request(DASHBOARD);
[$s, $h, , $set] = $a->request('/backend/system/login/logout', ['csrf_token' => $csrf($page)]);
check('Abmelden: redirect to the login', ($s === 302 || $s === 303) && ($h['location'] ?? '') === '/login', "status {$s}");
check('…the device cookie is deleted', $deviceLine($set) !== '' && !isset($a->cookies[DEVICE]));
check('…and its row', $siteRows() === []);
$copy = new Browser($port);
$copy->cookies[DEVICE] = $kept;
check('a kept copy of the cookie opens nothing', in_array($copy->request(DASHBOARD)[0], [302, 303], true));
$epochAfterLogout = $siteUser('max')['auth_epoch'];
check('…and is no «theft»: the stamp stays', $epochAfterLogout === $epochBefore + 1);
[, , $page] = $a->request('/backend/system/login/logout');
check('a GET on the logout deletes nothing (SEC-GET-001)', (static function () use ($login, $port, $siteRows): bool {
    $g = new Browser($port);
    $login($g, 'erna', true);
    $g->request('/backend/system/login/logout');
    return count($siteRows()) === 1 && isset($g->cookies[DEVICE]) && $g->request(DASHBOARD)[0] === 200;
})());
$clearThrottle();

// D10 — a login without the tick on a remembered device forgets it.
$a = new Browser($port);
$login($a, 'max', true);
$rowsBefore = count($siteRows());
[, , , $set] = $login($a, 'max', false);
check('a new login without the tick: this device is forgotten', !isset($a->cookies[DEVICE]) && count($siteRows()) === $rowsBefore - 1);

// D11 — the password change ends every device.
$pc = new Browser($port);
$login($pc, 'max', true);
$phone = new Browser($port);
$login($phone, 'max', true);
check('two remembered devices of one user', count(array_filter($siteRows(), fn(array $r): bool => $r['user_id'] === 1)) === 2);
$fetch = ['Sec-Fetch-Mode: cors'];
[, , $list] = $pc->request('/backend/system/backend-user/list');
$appToken = preg_match('/name="csrf-token" content="([^"]+)"/', $list, $m) ? $m[1] : '';
[, , $form] = $pc->request('/backend/system/backend-user/edit?id=1', null, $fetch);
$entity = preg_match('/name=\\\\?"entity_csrf\\\\?" value=\\\\?"([a-f0-9]+)/', $form, $m) ? $m[1] : '';
[$s, , $answer] = $pc->request('/backend/system/backend-user/edit?id=1', json_encode(['username' => 'max', 'email' => 'max.muster@example.ch', 'password' => 'Neues-Passwort-456', 'entity_csrf' => $entity, 'roles' => ['admin'], 'identity_name' => [], 'identity_email' => []]), array_merge($fetch, ['X-CSRF-Token: ' . $appToken]));
check('the user changes his own password', password_verify('Neues-Passwort-456', $siteUser('max')['password_hash']), substr($answer, 0, 200));
check('…this session stays', $pc->request(DASHBOARD)[0] === 200);
check('…every device row of the user is gone', array_filter($siteRows(), fn(array $r): bool => $r['user_id'] === 1) === []);
check('…the other device\'s session ends', in_array($phone->request(DASHBOARD)[0], [302, 303], true));
$phone->close();
check('…and its cookie opens nothing', in_array($phone->request(DASHBOARD)[0], [302, 303], true));
$pc->close();
check('…nor does this device\'s own after the browser is closed', in_array($pc->request(DASHBOARD)[0], [302, 303], true));
check('…another user\'s device is untouched', count(array_filter($siteRows(), fn(array $r): bool => $r['user_id'] === 2)) === 1);
$clearThrottle();

// D12 — a device whose user changed since (the stamp), without the tidy-up of the rows.
$a = new Browser($port);
$login($a, 'erna', true);
$users = json_decode((string) file_get_contents($site . '/data/framework/auth/backendUsers.json'), true);
foreach ($users as &$u) {
    if ($u['username'] === 'erna') {
        $u['auth_epoch'] = ($u['auth_epoch'] ?? 0) + 1; // what a role change, an admin's 2FA reset or the hand procedure do
    }
}
unset($u);
file_put_contents($site . '/data/framework/auth/backendUsers.json', json_encode($users, JSON_PRETTY_PRINT));
$selectorOfA = explode('.', $a->cookies[DEVICE])[0];
$a->close();
[$s, , , $set] = $a->request(DASHBOARD);
check('a raised stamp alone ends a remembered device', in_array($s, [302, 303], true) && !isset($a->cookies[DEVICE]), "status {$s}");
check('…and its row is removed when it shows up', !in_array($selectorOfA, array_column($siteRows(), 'selector'), true));
$clearThrottle();

// D13 — the throttle.
$a = new Browser($port);
$login($a, 'erna', true);
$valid = $a->cookies[DEVICE];
for ($i = 0; $i < 5; $i++) {
    $g = new Browser($port);
    $g->cookies[DEVICE] = bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(32));
    [$s, , , $set] = $g->request(DASHBOARD);
}
check('an invented cookie: no entry, and the cookie is deleted', in_array($s, [302, 303], true) && $deviceLine($set) !== '');
$a->close();
[$s, , , $set] = $a->request(DASHBOARD);
check('after five failed device attempts from one address: a valid device is not restored either', in_array($s, [302, 303], true), "status {$s}");
check('…its cookie stays for later', isset($a->cookies[DEVICE]) && $a->cookies[DEVICE] === $valid && $deviceLine($set) === '');
$clearThrottle();
check('…and works once the window is over', $a->request(DASHBOARD)[0] === 200);

// D14 — 2FA.
$code = static fn(int $offset = 0): string => Totp::code($secret, time() + $offset);
$t = new Browser($port);
[$s, $h, , $set] = $login($t, 'tina', true);
check('2FA account, password with the tick: to the code page, NO device cookie yet', ($h['location'] ?? '') === '/backend/system/login/code' && $deviceLine($set) === '' && array_filter($siteRows(), fn(array $r): bool => $r['user_id'] === 3) === []);
[, , $html] = $t->request('/backend/system/login/code');
[$s, $h, , $set] = $t->request('/backend/system/login/code', ['csrf_token' => $csrf($html), 'code' => $code()]);
check('…after the code: signed in and the device remembered', in_array($s, [302, 303], true) && $deviceLine($set) !== '' && $t->request(DASHBOARD)[0] === 200, "status {$s} " . ($h['location'] ?? ''));
$t->close();
[$s] = $t->request(DASHBOARD);
check('rememberSkipsSecondFactor (default): browser closed → in, without password and without code', $s === 200, "status {$s}");

$authConfig(['rememberSkipsSecondFactor' => false]);
$t->close();
[$s, $h] = $t->request(DASHBOARD);
check('rememberSkipsSecondFactor false: the device alone does not open the backend', in_array($s, [302, 303], true) && ($h['location'] ?? '') === '/login', "status {$s}");
[$s, $h] = $t->request('/login');
check('…the login sends on to the code page, no password asked', in_array($s, [302, 303], true) && ($h['location'] ?? '') === '/backend/system/login/code', "status {$s} " . ($h['location'] ?? ''));
[$s, , $html] = $t->request('/backend/system/login/code');
check('…which is there', $s === 200 && str_contains($html, 'name="code"'), "status {$s}");
check('…and the backend stays shut until the code', in_array($t->request(DASHBOARD)[0], [302, 303], true));
[, , $html] = $t->request('/backend/system/login/code');
[$s, $h, , $set] = $t->request('/backend/system/login/code', ['csrf_token' => $csrf($html), 'code' => $code(30)]);
check('…the code signs in', in_array($s, [302, 303], true) && $t->request(DASHBOARD)[0] === 200, "status {$s}");
check('…and the device stays remembered', isset($t->cookies[DEVICE]) && count(array_filter($siteRows(), fn(array $r): bool => $r['user_id'] === 3)) === 1);
$n = new Browser($port);
$login($n, 'erna', true);
$n->close();
check('…an account without 2FA is restored as before', $n->request(DASHBOARD)[0] === 200);
$clearThrottle();

// D15 — the feature switched off.
$authConfig(['rememberDevice' => false]);
$off = new Browser($port);
[$s, , $html] = $off->request('/login');
check('rememberDevice false: no checkbox', $s === 200 && !str_contains($html, 'name="remember"'), "status {$s}");
[, , , $set] = $off->request('/backend/system/login/login', ['csrf_token' => $csrf($html), 'username' => 'erna', 'password' => 'Altes-Passwort-123', 'remember' => '1']);
check('…a posted tick is ignored: no device cookie', $deviceLine($set) === '' && $off->request(DASHBOARD)[0] === 200);
$n->close();
[$s, , , $set] = $n->request(DASHBOARD);
check('…a cookie from before is not accepted', in_array($s, [302, 303], true), "status {$s}");
check('…and is deleted', !isset($n->cookies[DEVICE]) && $deviceLine($set) !== '');

// D16 — a nonsense value stops the installation instead of weakening it.
$authConfig(['sessionIdleMinutes' => 'sechzig']);
[$s, , $body] = (new Browser($port))->request('/login');
check('a nonsense sessionIdleMinutes: no page, not a silent default', $s >= 500, "status {$s}");
$authConfig([]);
check('…and back to normal with a valid file', (new Browser($port))->request('/login')[0] === 200);

// ── E. wiring ───────────────────────────────────────────────────────────────
echo "E. Wiring\n";
$src = static fn(string $rel): string => (string) file_get_contents($root . '/' . $rel);
$bootstrap = $src('packages/kernel/core/src/Bootstrap.php');
check('Bootstrap hands the session its own directory and lifetime', str_contains($bootstrap, 'new SessionManager(SessionPolicy::saveDir(), SessionPolicy::fromConfig()->gcMaxLifetime())'));
$sm = $src('packages/kernel/core/src/Session/SessionManager.php');
check('SessionManager switches the collector on for its own path, only for the files handler', str_contains($sm, "ini_set('session.gc_probability', '1')") && str_contains($sm, "ini_get('session.save_handler') !== 'files'"));
check('…and keeps the cookie hardening', str_contains($sm, "'httponly' => true") && str_contains($sm, "'samesite' => 'Lax'") && str_contains($sm, "session.use_strict_mode"));
$config = $src('packages/module-backend/src/App/Config/backendConfig.inc.php');
check('backendConfig registers the device door as an auth bridge', str_contains($config, "'authBridges'   => [DeviceBridge::class]"));
$bridge = $src('packages/module-backend/src/Remember/DeviceBridge.php');
check('the device door: throttle before the store, login only through AuthService', strpos($bridge, '->tryAttempt(') < strpos($bridge, '->redeem(') && str_contains($bridge, '->restoredFromDevice($user, true)->login()') && !str_contains($bridge, "set('auth_user'"));
check('…nothing of the cookie reaches a log', !preg_match('/error_log\([^;]*\$value/', $bridge) && !preg_match('/error_log\([^;]*cookieValue/', $bridge));
$loginCtl = $src('packages/module-backend/src/Ui/Controllers/System/LoginController.php');
check('the device is remembered only after login(), in completeLogin()', strpos($loginCtl, '$auth->login();') < strpos($loginCtl, '$this->rememberThisDevice($user, $remember);'));
check('the reset ends the user\'s devices', str_contains(substr($loginCtl, strpos($loginCtl, 'private function handleResetPost')), 'RememberedDevices::revokeQuietly('));
$userCtl = $src('packages/module-backend/src/Ui/Controllers/System/BackendUserController.php');
check('user form (password or roles) and delete end the user\'s devices', substr_count($userCtl, 'RememberedDevices::revokeQuietly(') === 2);
$member = $src('packages/module-member/src/Services/DeviceCookie.php');
check('the member door uses the same cookie carrier (one implementation)', str_contains($member, 'new \\Z77\\Shared\\Auth\\DeviceCookie(self::NAME)') && !str_contains($member, 'setcookie('));

proc_terminate($server);
proc_close($server);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
