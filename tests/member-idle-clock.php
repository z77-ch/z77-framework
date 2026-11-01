<?php

/**
 * The member idle clock and the live listener (CLI, temp ABS_BASE_PATH) — fetch.md
 * FETCH-LIVE-001. What is load-bearing: a live listener's question reads the account
 * without refreshing the clock, so a visible tab no longer keeps a member signed in; a
 * normal request still refreshes it, and the session still ends after IDLE_SECONDS.
 *
 * Run: php tests/member-idle-clock.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "member-idle-clock: vendor/autoload.php missing — run `composer install` in the monorepo root\n");
    exit(2);
}

$base = sys_get_temp_dir() . '/z77-idle-clock-' . bin2hex(random_bytes(4));
mkdir($base . '/data', 0777, true);
define('ABS_BASE_PATH', str_replace('\\', '/', $base));

require $autoload;
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';

// The monorepo autoloader does not map module-member — same loader as
// member-operator-invite.php.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Z77\\Module\\Member\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../packages/module-member/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Z77\Core\Session\SessionManager;
use Z77\Module\Member\Services\DeviceCookie;
use Z77\Module\Member\Services\DeviceKeys;
use Z77\Module\Member\Services\MemberAccounts;
use Z77\Module\Member\Services\MemberAuth;
use Z77\Module\Member\Services\MemberSession;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

// The file persistence asks DI for the CacheManager — the minimal container
// (same shape as member-operator-invite.php).
Z77\Core\DI::getInstance(true)->set('CacheManager', Z77\Core\Libraries\CacheManager::class, true);

$_SESSION = [];
$session  = new MemberSession(new SessionManager());
$accounts = new MemberAccounts(new UnifiedEntityManager(new DataSourceResolver(['file' => 'File'])));
$keys     = new DeviceKeys($accounts, new DeviceCookie());

$account = $accounts->register('max.muster@example.ch', 'Muster AG', 'Max', 'Muster');
check('an account to sign in with', $account !== null);
$id = (string) $account->getId();

$listener = new MemberAuth($session, $accounts, $keys, false);
$normal   = new MemberAuth($session, $accounts, $keys);

$start = 1000000;
$idle  = MemberSession::IDLE_SECONDS;

$session->start($id, $start);
check('a listener question sees the signed-in account', $listener->current($start + 10)?->getId() === $account->getId());
check('…and does not refresh the clock', $_SESSION['member.lastSeenAt'] === $start, (string) $_SESSION['member.lastSeenAt']);

$listener->current($start + $idle);
check('questions up to the limit: still signed in', $session->hasTraces());
check('past the limit a listener question gets nobody', $listener->current($start + $idle + 1) === null);
check('…and the session has ended', !$session->hasTraces());

$session->start($id, $start);
check('a normal request sees the account', $normal->current($start + 10)?->getId() === $account->getId());
check('…and refreshes the clock', $_SESSION['member.lastSeenAt'] === $start + 10, (string) $_SESSION['member.lastSeenAt']);

$listener->current($start + 20);
check('a question after it leaves that activity standing', $_SESSION['member.lastSeenAt'] === $start + 10);
check('the clock counts from the last real activity', $listener->current($start + 10 + $idle + 1) === null);

$session->start($id, $start);
check('MemberSession alone: the default still refreshes', $session->currentAccountId($start + 5) === $id && $_SESSION['member.lastSeenAt'] === $start + 5);

$auth = (string) file_get_contents(__DIR__ . '/../packages/module-member/src/Services/MemberAuth.php');
check('production wiring: the request decides', str_contains($auth, '!DI::getRequest()->isLiveRequest()'));

$request = new ReflectionClass(Z77\Core\Http\Request::class);
$probe   = $request->newInstanceWithoutConstructor();
unset($_SERVER['HTTP_X_Z77_LIVE']);
check('Request: no header → not a live request', $probe->isLiveRequest() === false);
$_SERVER['HTTP_X_Z77_LIVE'] = '1';
check('Request: the header → a live request', $probe->isLiveRequest() === true);
unset($_SERVER['HTTP_X_Z77_LIVE']);

$remove = static function (string $dir) use (&$remove): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
        if (basename($entry) === '.' || basename($entry) === '..') {
            continue;
        }
        is_dir($entry) ? $remove($entry) : unlink($entry);
    }
    rmdir($dir);
};
$remove($base);

echo "\n{$pass} ok, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
