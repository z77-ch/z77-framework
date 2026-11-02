<?php

/**
 * The member's second factor up to the backend's strength (CLI, temp ABS_BASE_PATH, then the real
 * stack) — member.md MEMBER-2FA-001. What is load-bearing:
 *
 *   (A) what exists keeps working: a secret encrypted before this change, on an account row without
 *       `totp_last_step`, passes; the key path and guard directory did not move; a row whose key was
 *       removed by hand loads; an old pending secret in the store is never confirmed;
 *   (B) replay: a spent code and an older one within the drift are refused and counted;
 *   (C) removal: through the guard, counted, locked, spent codes refused;
 *   (D) the running setup: in the session as ciphertext, nothing in the store until the code, a new
 *       secret per open, too old refused, the confirming code spent;
 *   (E) dead counters fail closed at every door that judges a code, with the backend's message and
 *       log line form, and recover by themselves; an account without 2FA notices nothing;
 *   (F) the login mail: IP limit first, neutral answer, waiting record either way;
 *   (G) real processes: of 6 parallel uses of one code exactly one passes, 12 parallel wrong guesses
 *       stop at the lock;
 *   (H) the real stack: login with a code, replay, removal, dead counters in the page, the IP limit
 *       through the form, the issuer in the QR (default and configured).
 *
 * Run: php tests/member-2fa.php
 */

use Z77\Core\Controller\ControllerHandler;
use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Session\SessionManager;
use Z77\Module\Member\Entities\MemberAccount;
use Z77\Module\Member\Entities\MemberPendingLogin;
use Z77\Module\Member\Services\DeviceCookie;
use Z77\Module\Member\Services\DeviceKeys;
use Z77\Module\Member\Services\LoginFlow;
use Z77\Module\Member\Services\MemberAccounts;
use Z77\Module\Member\Services\MemberSession;
use Z77\Module\Member\Services\MemberThrottle;
use Z77\Module\Member\Services\MemberTotp;
use Z77\Module\Member\Services\PendingLogins;
use Z77\Module\Member\Services\RegistrationFlow;
use Z77\Module\Member\Services\TokenService;
use Z77\Module\Member\Services\TotpCodeCheck;
use Z77\Module\Member\Services\TotpSetup;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Auth\Totp;
use Z77\Shared\Auth\TotpGuard;
use Z77\Shared\Auth\TotpVault;
use Z77\Shared\Qr\QrCode;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "member-2fa: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}

$memberLoader = static function (string $class): void {
    $prefix = 'Z77\\Module\\Member\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../packages/module-member/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
};

// Worker for the concurrency checks: runs one code check, prints its outcome.
if (($argv[1] ?? '') === '--check-worker') {
    define('ABS_BASE_PATH', $argv[2]);
    require $autoload;
    spl_autoload_register($memberLoader);
    DI::getInstance(true)->set('CacheManager', CacheManager::class, true);
    $uem   = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
    $check = new TotpCodeCheck(new MemberAccounts($uem), MemberTotp::vault(), MemberTotp::guard());
    while (microtime(true) < (float) $argv[5]) {
        usleep(200);
    }
    echo $check->judge($argv[3], $argv[4], time(), static function (MemberAccount $fresh, int $step): void {
        $fresh->recordTotpStep($step);
    });
    exit(0);
}

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-member-2fa-' . bin2hex(random_bytes(4));
mkdir($base . '/data', 0777, true);
define('ABS_BASE_PATH', $base);

require $autoload;
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';
require_once __DIR__ . '/support/real-flow-site.php';
require_once __DIR__ . '/support/real-flow-server.php';
spl_autoload_register($memberLoader);

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

function removeTree(string $dir): void
{
    if (is_file($dir)) {
        @unlink($dir);
        return;
    }
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        removeTree($dir . '/' . $entry);
    }
    @rmdir($dir);
}
register_shutdown_function(static function () use ($base): void {
    removeTree($base);
});

DI::getInstance(true)->set('CacheManager', CacheManager::class, true);
$uem = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
DI::getInstance()->set('UnifiedEntityManager', $uem, true);

$_SESSION = [];
$session  = new SessionManager();
$accounts = new MemberAccounts($uem);
$vault    = MemberTotp::vault();
$guard    = MemberTotp::guard();
$msession = new MemberSession($session);
$setup    = new TotpSetup($accounts, $vault, $guard, $msession);
$mails    = [];
$flow     = new LoginFlow(
    $accounts,
    new TokenService($uem),
    new MemberThrottle($base . '/member-throttle', 5, 3600),
    $msession,
    (new ReflectionClass(RegistrationFlow::class))->newInstanceWithoutConstructor(),
    $vault,
    $guard,
    new DeviceKeys($accounts, new DeviceCookie()),
    new PendingLogins($uem),
    static function ($mail) use (&$mails): bool { $mails[] = $mail; return true; },
    'https://kunde.example.ch/member/main/login/redeem',
    'https://kunde.example.ch/member/main/register',
    3,
);
$accountsFile = $base . '/data/framework/member/accounts.json';
$row = static function (string $id) use ($accountsFile): ?array {
    foreach ((array) json_decode((string) file_get_contents($accountsFile), true) as $r) {
        if (($r['id'] ?? '') === $id) {
            return $r;
        }
    }
    return null;
};
$logFile = $base . '/php-error.log';
ini_set('error_log', $logFile);
ini_set('log_errors', '1');
$logTail = static function () use ($logFile): string {
    return (string) @file_get_contents($logFile);
};
$newAccount = static function (string $mail) use ($accounts): MemberAccount {
    $account = new MemberAccount();
    $account->setEmail($mail);
    $accounts->save($account);
    return $account;
};
/** A secret as an installation holds it today: AES-256-GCM under the member key, written by the old recipe. */
$keyFile = $base . '/data/framework/member/totp.key';
$rawKey  = random_bytes(32);
mkdir(dirname($keyFile), 0777, true);
file_put_contents($keyFile, $rawKey);
$oldCiphertext = static function (string $plain) use ($rawKey): string {
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ct);
};

$base32 = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
$t0     = 1_900_000_020;
$code   = static fn(int $at, string $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'): string => Totp::code($secret, $at);
$signIn = static function (string $id, int $at, string $submitted) use ($msession, $flow): string {
    $msession->startTotpPending($id, false, $at);
    return $flow->confirmTotp($submitted, $at);
};

// ── (A) what exists keeps working ───────────────────────────────────────────
echo "(A) existing secrets and rows\n";
check('the key path did not move', (new ReflectionProperty(TotpVault::class, 'keyFile'))->getValue($vault) === $base . '/data/framework/member/totp.key');
check('the guard directory did not move', $guard->directory() === $base . '/var/lib/throttle/totp-guard');

$old = $newAccount('alt@example.ch');
$oldId = (string) $old->getId();
// The row as it stands on a running installation: no totp_last_step key, a secret from before.
$rows = json_decode((string) file_get_contents($accountsFile), true);
foreach ($rows as &$r) {
    if ($r['id'] === $oldId) {
        $r['totp_secret']       = $oldCiphertext($base32);
        $r['totp_activated_at'] = date(DATE_ATOM, $t0 - 86400);
        unset($r['totp_last_step']);
    }
}
unset($r);
file_put_contents($accountsFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$loaded = $accounts->findById($oldId);
check('a row without the key loads: 2FA on, nothing spent', $loaded->hasTotp() && $loaded->getTotpLastStep() === 0);
check('a secret encrypted before this change decrypts', $vault->decrypt((string) $loaded->getTotpSecret()) === $base32);
check('the key file was not rewritten', file_get_contents($keyFile) === $rawKey);
check('login with that secret passes', $signIn($oldId, $t0, $code($t0)) === LoginFlow::SESSION);
check('...and spends the step, in the store', ($row($oldId)['totp_last_step'] ?? 0) === intdiv($t0, 30));

// The key removed by hand stays harmless: nothing spent any more, nothing broken.
$rows = json_decode((string) file_get_contents($accountsFile), true);
foreach ($rows as &$r) {
    if ($r['id'] === $oldId) {
        unset($r['totp_last_step']);
    }
}
unset($r);
file_put_contents($accountsFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
check('the key removed by hand: the account still signs in', $signIn($oldId, $t0 + 60, $code($t0 + 60)) === LoginFlow::SESSION);

// A secret an earlier version left pending in the store.
$stale = $newAccount('wartend@example.ch');
$staleId = (string) $stale->getId();
$rows = json_decode((string) file_get_contents($accountsFile), true);
foreach ($rows as &$r) {
    if ($r['id'] === $staleId) {
        $r['totp_secret'] = $oldCiphertext($base32);
    }
}
unset($r);
file_put_contents($accountsFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$stale = $accounts->findById($staleId);
check('an old pending secret: 2FA off', !$stale->hasTotp() && $stale->hasPendingTotpSetup());
check('...its code confirms nothing', !$setup->confirm($stale, $code($t0), $t0) && !$accounts->findById($staleId)->hasTotp());
check('...it opens no login', $signIn($staleId, $t0, $code($t0)) === LoginFlow::DEAD);
$setup->resetByOperator($stale);
check('...an operator reset clears it', $accounts->findById($staleId)->getTotpSecret() === null);

// ── (B) replay ──────────────────────────────────────────────────────────────
echo "(B) replay\n";
$rep = $newAccount('wiederholt@example.ch');
$repId = (string) $rep->getId();
$rows = json_decode((string) file_get_contents($accountsFile), true);
foreach ($rows as &$r) {
    if ($r['id'] === $repId) {
        $r['totp_secret']       = $vault->encrypt($base32);
        $r['totp_activated_at'] = date(DATE_ATOM, $t0);
    }
}
unset($r);
file_put_contents($accountsFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
check('a code passes once', $signIn($repId, $t0, $code($t0)) === LoginFlow::SESSION);
check('the same code again, in a new session: refused', $signIn($repId, $t0 + 1, $code($t0)) === LoginFlow::TOTP_INVALID);
check('an older code within the drift: refused', $signIn($repId, $t0 + 2, $code($t0 - 30)) === LoginFlow::TOTP_INVALID);
check('the next code passes (it clears the count)', $signIn($repId, $t0 + 30, $code($t0 + 30)) === LoginFlow::SESSION);
check('no failure left over', !$guard->isLocked($repId, $t0 + 30) && !is_file($base . '/var/lib/throttle/totp-guard/' . hash('sha256', $repId) . '.json'));
for ($i = 0; $i < 4; $i++) {
    $signIn($repId, $t0 + 31, $code($t0 + 30));
}
check('a spent code counts as a failure: the fifth locks', $signIn($repId, $t0 + 32, $code($t0 + 30)) === LoginFlow::TOTP_LOCKED);
check('while locked the right code is refused', $signIn($repId, $t0 + 60, $code($t0 + 60)) === LoginFlow::TOTP_LOCKED);
check('the lock ends', $signIn($repId, $t0 + 32 + TotpGuard::LOCK_SECONDS + 1, $code($t0 + 32 + TotpGuard::LOCK_SECONDS + 1)) === LoginFlow::SESSION);

// A form body cannot set the step.
$fromForm = new MemberAccount();
$fromForm->mapFromArray(['totp_last_step' => 99]);
check('mapFromArray cannot reach the step', $fromForm->getTotpLastStep() === 0);
// A stale save beside an accepted code leaves the step alone (the store writes only what changed).
$stale2 = $accounts->findById($repId);
$stepNow = $row($repId)['totp_last_step'];
$other  = $accounts->findById($repId);
$other->recordTotpStep($stepNow + 7);
$accounts->save($other);
$stale2->setTheme('dark');
$accounts->save($stale2);
check('a stale save does not undo a spent step', $row($repId)['totp_last_step'] === $stepNow + 7 && $row($repId)['theme'] === 'dark', json_encode($row($repId)));

// ── (C) removal ─────────────────────────────────────────────────────────────
echo "(C) removal\n";
$rem = $newAccount('entfernt@example.ch');
$remId = (string) $rem->getId();
$rows = json_decode((string) file_get_contents($accountsFile), true);
foreach ($rows as &$r) {
    if ($r['id'] === $remId) {
        $r['totp_secret']       = $vault->encrypt($base32);
        $r['totp_activated_at'] = date(DATE_ATOM, $t0);
    }
}
unset($r);
file_put_contents($accountsFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$rem = $accounts->findById($remId);
check('a wrong code: refused, counted', $setup->removeWithCode($rem, '000000', $t0) === TotpCodeCheck::WRONG && $accounts->findById($remId)->hasTotp());
check('...in the same counter as the login', is_file($base . '/var/lib/throttle/totp-guard/' . hash('sha256', $remId) . '.json'));
for ($i = 0; $i < 3; $i++) {
    $setup->removeWithCode($rem, '000000', $t0);
}
check('the fifth wrong code locks', $setup->removeWithCode($rem, '000000', $t0) === TotpCodeCheck::LOCKED);
check('while locked the right code is refused too', $setup->removeWithCode($rem, $code($t0), $t0) === TotpCodeCheck::LOCKED && $accounts->findById($remId)->hasTotp());
$after = $t0 + TotpGuard::LOCK_SECONDS + 1;
check('after the lock a login spends a code', $signIn($remId, $after, $code($after)) === LoginFlow::SESSION);
check('that spent code cannot remove', $setup->removeWithCode($rem, $code($after), $after) === TotpCodeCheck::WRONG && $accounts->findById($remId)->hasTotp());
check('the next code removes', $setup->removeWithCode($rem, $code($after + 30), $after + 30) === TotpCodeCheck::PASSED);
$gone = $row($remId);
check('removed: secret, date and step gone from the store', ($gone['totp_secret'] ?? null) === null && ($gone['totp_activated_at'] ?? null) === null && ($gone['totp_last_step'] ?? 0) === 0, json_encode($gone));
check('an account without 2FA has nothing to remove', $setup->removeWithCode($rem, $code($after + 60), $after + 60) === TotpCodeCheck::GONE);

// ── (D) the running setup ───────────────────────────────────────────────────
echo "(D) the running setup\n";
$enr = $newAccount('neu@example.ch');
$enrId = (string) $enr->getId();
$before = (string) file_get_contents($accountsFile);
$s1 = $setup->begin($enr, $t0);
check('an open writes nothing to the store', (string) file_get_contents($accountsFile) === $before);
check('in the session only ciphertext', !str_contains((string) json_encode($_SESSION), $s1) && str_contains((string) json_encode($_SESSION), 'totpEnrolment'));
$s2 = $setup->begin($enr, $t0);
check('a new secret per open', $s1 !== $s2);
check('the earlier one is gone', !$setup->confirm($enr, $code($t0, $s1), $t0));
check('a wrong code keeps the running one', !$setup->confirm($enr, '000000', $t0) && $setup->running($enr, $t0) === $s2);
check('another account cannot confirm it', !$setup->confirm($newAccount('fremd@example.ch'), $code($t0, $s2), $t0));
check('too old: refused and gone', !$setup->confirm($enr, $code($t0 + TotpSetup::ENROLMENT_SECONDS, $s2), $t0 + TotpSetup::ENROLMENT_SECONDS) && $setup->running($enr, $t0) === null);
$s3 = $setup->begin($enr, $t0);
check('the right code switches on', $setup->confirm($enr, $code($t0, $s3), $t0) && $accounts->findById($enrId)->hasTotp());
check('ciphertext in the store, under the member key', ($row($enrId)['totp_secret'] ?? '') !== $s3 && $vault->decrypt((string) $row($enrId)['totp_secret']) === $s3);
check('the session holds nothing any more', $setup->running($enr, $t0) === null && !str_contains((string) json_encode($_SESSION), 'totpEnrolment'));
check('the confirming code is spent', $signIn($enrId, $t0 + 1, $code($t0, $s3)) === LoginFlow::TOTP_INVALID);
$_SESSION = [];
$setup->begin($newAccount('abmeldend@example.ch'), $t0);
$msession->end();
check('logout clears a running setup', !isset($_SESSION['member.totpEnrolment']));
$setup->begin($enr, $t0);
$msession->start($enrId, $t0);
check('login clears a running setup', !isset($_SESSION['member.totpEnrolment']));
$_SESSION = [];
$setup->begin($enr, $t0);
check('with 2FA on, a second confirm changes nothing', !$setup->confirm($enr, $code($t0 + 30, $setup->running($enr, $t0)), $t0 + 30) && $vault->decrypt((string) $row($enrId)['totp_secret']) === $s3);

// ── (E) dead counters ───────────────────────────────────────────────────────
echo "(E) dead counters fail closed\n";
$guardDir = $base . '/var/lib/throttle/totp-guard';
$kill = static function () use ($guardDir): void {
    removeTree($guardDir);
    file_put_contents($guardDir, 'not a directory');
};
$heal = static function () use ($guardDir): void {
    @unlink($guardDir);
};
$tDead = $t0 + 100000;
$dead  = $newAccount('tot@example.ch');
$deadId = (string) $dead->getId();
$rows = json_decode((string) file_get_contents($accountsFile), true);
foreach ($rows as &$r) {
    if ($r['id'] === $deadId) {
        $r['totp_secret']       = $vault->encrypt($base32);
        $r['totp_activated_at'] = date(DATE_ATOM, $t0);
    }
}
unset($r);
file_put_contents($accountsFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$dead = $accounts->findById($deadId);
$kill();
$logLen = strlen($logTail());
check('login: the right code is refused', $signIn($deadId, $tDead, $code($tDead)) === LoginFlow::TOTP_UNAVAILABLE);
$logged = substr($logTail(), $logLen);
check('...log line in the backend\'s form', str_contains($logged, 'MemberTotp: code refused, counters unavailable — step=write-counter dir=' . $guardDir . ' account id ' . $deadId), $logged);
check('...no code and no secret in it', !str_contains($logged, $code($tDead)) && !str_contains($logged, $base32));
check('...nothing spent', ($row($deadId)['totp_last_step'] ?? 0) === 0);
check('...the person stays at the prompt', $msession->totpPendingAccountId($tDead) === $deadId);
check('removal: refused', $setup->removeWithCode($dead, $code($tDead), $tDead) === TotpCodeCheck::UNAVAILABLE && $accounts->findById($deadId)->hasTotp());
$fresh = $newAccount('einrichtend@example.ch');
$sF = $setup->begin($fresh, $tDead);
check('setup: canCheckCodes says no', !$setup->canCheckCodes($fresh));
check('setup: the right code does not switch on', !$setup->confirm($fresh, $code($tDead, $sF), $tDead) && !$accounts->findById((string) $fresh->getId())->hasTotp());
check('the message is the backend\'s', TotpCodeCheck::UNAVAILABLE_MESSAGE === Z77\Module\Backend\TwoFactor\TotpCheck::Unavailable->refusal());
// An account without 2FA has no code to pass: the link's redeem does not ask the guard.
$plainAcc = $newAccount('ohne@example.ch');
$plainAcc->markConfirmed(date(DATE_ATOM, $t0));
$accounts->save($plainAcc);
$tokenPlain = (new TokenService($uem))->issue((string) $plainAcc->getId(), 'login', 900, $tDead, false);
check('an account without 2FA notices nothing', $flow->redeem($tokenPlain, $tDead) === LoginFlow::SESSION);
$heal();
check('it recovers by itself: the next code is judged', $signIn($deadId, $tDead, $code($tDead)) === LoginFlow::SESSION);
$sF = $setup->begin($fresh, $tDead); // the sign-in above cleared the running setup, as a login does
check('...and the setup works again', $setup->confirm($fresh, $code($tDead, $sF), $tDead));
// A counter that cannot be written for this one account: its own file.
$ownFile = $guardDir . '/' . hash('sha256', $deadId) . '.json';
$signIn($deadId, $tDead, '000000');
if (is_file($ownFile) && @chmod($ownFile, 0444) && !is_writable($ownFile)) {
    check('one read-only counter: the right code is refused', $signIn($deadId, $tDead + 30, $code($tDead + 30)) === LoginFlow::TOTP_UNAVAILABLE);
    @chmod($ownFile, 0666);
}

// ── (F) the login mail ──────────────────────────────────────────────────────
echo "(F) the login mail's IP limit\n";
$tMail  = $t0 + 200000;
$mails  = [];
$known  = $newAccount('bekannt@example.ch');
$known->markConfirmed(date(DATE_ATOM, $t0));
$accounts->save($known);
for ($i = 0; $i < 3; $i++) {
    check('invented address ' . $i . ': neutral, mail out', $flow->request("erfunden{$i}@example.ch", false, $tMail, '203.0.113.7') === true && count($mails) === $i + 1);
}
$_SESSION = [];
check('over the limit: the same answer', $flow->request('erfunden9@example.ch', false, $tMail, '203.0.113.7') === true);
check('...no mail', count($mails) === 3);
check('...a waiting record all the same', $msession->pendingLoginId() !== null);
check('...the same for a known address', $flow->request('bekannt@example.ch', false, $tMail, '203.0.113.7') === true && count($mails) === 3);
$limitLine = $logTail();
check('...the operator is told in the log', str_contains($limitLine, 'LoginFlow: login mail held back, this origin is over its hourly limit'), substr($limitLine, -200));
check('...without the address of the visitor or the mailbox', !str_contains($limitLine, '203.0.113.7') && !str_contains($limitLine, 'erfunden9') && !str_contains($limitLine, 'bekannt@'));$throttleFiles = glob($base . '/member-throttle/*') ?: [];
check('another origin is not held', $flow->request('bekannt@example.ch', false, $tMail, '203.0.113.8') === true && count($mails) === 4);
// An origin over its limit must not use up the address's allowance: the known address got its
// first count only from the other origin, so four more go out from there.
$sent = 0;
for ($i = 0; $i < 4; $i++) {
    $before = count($mails);
    $flow->request('bekannt@example.ch', false, $tMail, '198.51.100.' . ($i + 1));
    $sent += count($mails) - $before;
}
check('the blocked origin did not spend the address\'s count', $sent === 4, (string) $sent);
$before = count($mails);
$flow->request('bekannt@example.ch', false, $tMail, '198.51.100.9');
check('the address limit still holds', count($mails) === $before);
$before = count($mails);
$flow->request('unbekannt-ohne-ip@example.ch', false, $tMail);
check('no usable address: the address counter alone decides', count($mails) === $before + 1);

// ── (G) real processes ──────────────────────────────────────────────────────
echo "(G) concurrency\n";
$race = $newAccount('rennen@example.ch');
$raceId = (string) $race->getId();
$rows = json_decode((string) file_get_contents($accountsFile), true);
foreach ($rows as &$r) {
    if ($r['id'] === $raceId) {
        $r['totp_secret']       = $vault->encrypt($base32);
        $r['totp_activated_at'] = date(DATE_ATOM);
    }
}
unset($r);
file_put_contents($accountsFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$runWorkers = static function (int $count, string $submitted) use ($base, $raceId): array {
    $start = microtime(true) + 0.8;
    $procs = [];
    $pipes = [];
    for ($i = 0; $i < $count; $i++) {
        $procs[$i] = proc_open([PHP_BINARY, __FILE__, '--check-worker', $base, $raceId, $submitted, (string) $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $out = [];
    foreach ($procs as $i => $proc) {
        $out[] = trim((string) stream_get_contents($pipes[$i][1]));
        proc_close($proc);
    }
    return $out;
};
$results = $runWorkers(6, Totp::code($base32, time()));
check('6 processes, one code: exactly one passes', count(array_keys($results, 'passed', true)) === 1, implode(',', $results));
check('...the others are refused and counted: 4 wrong, the 5th locks', count(array_keys($results, 'wrong', true)) === 4 && count(array_keys($results, 'locked', true)) === 1, implode(',', $results));
$guard->reset($raceId);
$results = $runWorkers(12, '000000');
check('12 parallel wrong guesses: 4 wrong, then locked', count(array_keys($results, 'wrong', true)) === 4 && count(array_keys($results, 'locked', true)) === 8, implode(',', $results));
check('...and the account is locked', $guard->isLocked($raceId));

// ── (H) the real stack ──────────────────────────────────────────────────────
echo "(H) real stack\n";
// A deprecation printed by the vendored QR renderer would land in its output buffer, inside the PNG.
ini_set('display_errors', '0');
$root = dirname(__DIR__);
$site = $base . '/site';
realFlowSite($site, $root, [
    ['id' => 1, 'username' => 'max', 'email' => 'max.muster@example.ch', 'password_hash' => password_hash('Passwort-Sehr-Lang-1', PASSWORD_BCRYPT, ['cost' => 4]), 'roles' => ['superUser'], 'sort_key' => 0],
]);
realFlowAddMember($site, $root, ['loginRequestsPerHourPerIp' => 2, 'loginRequestsPerHour' => 50]);
$siteKeyFile = $site . '/data/framework/member/totp.key';
file_put_contents($siteKeyFile, $rawKey);
$siteAccounts = $site . '/data/framework/member/accounts.json';
$siteTokens   = $site . '/data/framework/member/tokens.json';
file_put_contents($siteAccounts, json_encode([
    ['id' => 'acc-web', 'email' => 'web@example.ch', 'first_name' => 'Web', 'state' => 'active', 'roles' => ['customer'], 'totp_secret' => $oldCiphertext($base32), 'totp_activated_at' => date(DATE_ATOM)],
    ['id' => 'acc-set', 'email' => 'setup@example.ch', 'first_name' => 'Setup', 'state' => 'active', 'roles' => ['customer']],
], JSON_PRETTY_PRINT));
$siteRow = static function (string $id) use ($siteAccounts): ?array {
    foreach ((array) json_decode((string) file_get_contents($siteAccounts), true) as $r) {
        if (($r['id'] ?? '') === $id) {
            return $r;
        }
    }
    return null;
};
$tokenId = 100;
$issueLink = static function (string $accountId) use (&$tokenId, $siteTokens): string {
    $plain  = bin2hex(random_bytes(16));
    $onDisk = is_file($siteTokens) ? (array) json_decode((string) file_get_contents($siteTokens), true) : [];
    $onDisk[] = ['id' => ++$tokenId, 'token_hash' => hash('sha256', $plain), 'account_ref' => $accountId, 'purpose' => 'login', 'valid_until' => date(DATE_ATOM, time() + 600), 'used_at' => null];
    file_put_contents($siteTokens, json_encode(array_values($onDisk), JSON_PRETTY_PRINT));
    return $plain;
};
$serverLog = $base . '/server.log';
$port = realFlowServer($site, $base . '/sessions', $serverLog);
$toPrompt = static function (string $accountId) use ($port, $issueLink): array {
    $b     = new RealFlowBrowser($port);
    $plain = $issueLink($accountId);
    $page  = $b->go('/member/main/login/redeem?token=' . $plain);
    preg_match('/name="csrf_token" value="([^"]+)"/', $page[2], $m);
    $r = $b->go('/member/main/login/redeem', ['csrf_token' => $m[1] ?? '', 'token' => $plain, 'decision' => 'here']);
    return [$b, $r];
};
$tokenOf = static function (RealFlowBrowser $b, string $path): string {
    return preg_match('/name="csrf_token" value="([^"]+)"/', $b->go($path)[2], $m) ? $m[1] : '';
};
$now = time();

[$b, $r] = $toPrompt('acc-web');
check('a secret from before: the link parks at the prompt', ($r[1]['location'] ?? '') === '/member/main/login/totp', (string) ($r[1]['location'] ?? ''));
$tok = $tokenOf($b, '/member/main/login/totp');
$r = $b->go('/member/main/login/totp', ['csrf_token' => $tok, 'code' => $code($now)]);
check('...and the code signs in', in_array($r[0], [302, 303], true) && $b->go('/member/main/profile')[0] === 200, $r[0] . ' ' . ($r[1]['location'] ?? ''));
check('...the step is in the store', ($siteRow('acc-web')['totp_last_step'] ?? 0) === intdiv($now, 30), json_encode($siteRow('acc-web')));

[$b2] = $toPrompt('acc-web');
$tok2 = $tokenOf($b2, '/member/main/login/totp');
$r = $b2->go('/member/main/login/totp', ['csrf_token' => $tok2, 'code' => $code($now)]);
check('replay in a new browser: refused with the honest sentence', $r[0] === 200 && str_contains($r[2], 'wurde bereits verwendet') && $b2->go('/member/main/profile')[0] !== 200, $r[0] . ' ' . substr(strip_tags($r[2]), 0, 200));

// Removal through the page.
$html = $b->go('/member/main/profile?bereich=zweifa')[2];
$tok  = preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
$b->go('/member/main/profile/totp-remove', ['csrf_token' => $tok, 'code' => '000000']);
check('a wrong code removes nothing', ($siteRow('acc-web')['totp_activated_at'] ?? null) !== null);
check('...and is counted', is_file($site . '/var/lib/throttle/totp-guard/' . hash('sha256', 'acc-web') . '.json'));

// Dead counters, in the page.
$realGuard = $site . '/var/lib/throttle/totp-guard';
removeTree($realGuard);
file_put_contents($realGuard, 'not a directory');
[$b3] = $toPrompt('acc-web');
$tok3 = $tokenOf($b3, '/member/main/login/totp');
$r = $b3->go('/member/main/login/totp', ['csrf_token' => $tok3, 'code' => $code($now + 30)]);
check('dead counters: the page says so, the right code is refused', $r[0] === 200 && str_contains(html_entity_decode($r[2]), 'wegen eines technischen Problems') && $b3->go('/member/main/profile')[0] !== 200, $r[0] . ' ' . substr(strip_tags($r[2]), 0, 200));
check('...the log line reaches the server\'s log', str_contains((string) file_get_contents($serverLog), 'MemberTotp: code refused, counters unavailable — step=write-counter') || str_contains((string) @file_get_contents($site . '/logs/php-error.log'), 'MemberTotp: code refused, counters unavailable — step=write-counter'));
$r = $b->go('/member/main/profile/totp-remove', ['csrf_token' => $tok, 'code' => $code($now + 30)]);
$flash = $b->go('/member/main/profile?bereich=zweifa')[2];
check('dead counters: removal refused with the same sentence', str_contains(html_entity_decode($flash), 'wegen eines technischen Problems') && ($siteRow('acc-web')['totp_activated_at'] ?? null) !== null);
@unlink($realGuard);
$r = $b3->go('/member/main/login/totp', ['csrf_token' => $tok3, 'code' => $code($now + 30)]);
check('healed: the next code signs in', in_array($r[0], [302, 303], true) && $b3->go('/member/main/profile')[0] === 200, $r[0]);

// The setup page: nothing in the store on GET, issuer in the QR.
[$bs, $r] = $toPrompt('acc-set');
check('the setup account signs in without a code', in_array($r[0], [302, 303], true) && $bs->go('/member/main/profile')[0] === 200);
$storeBefore = (string) file_get_contents($siteAccounts);
$page = $bs->go('/member/main/profile/totp');
check('setup GET: the store does not change', (string) file_get_contents($siteAccounts) === $storeBefore);
$readSetup = static function (string $html): array {
    preg_match('#<code[^>]*>([A-Z2-7 ]+)</code>#', $html, $s);
    preg_match('#<img src="(data:image/png;base64,[^"]+)"#', $html, $q);
    return [str_replace(' ', '', $s[1] ?? ''), html_entity_decode($q[1] ?? '')];
};
[$secretA, $qrA] = $readSetup($page[2]);
check('the issuer by default: the installation\'s host', $qrA !== '' && $qrA === QrCode::pngDataUri(Totp::otpauthUri('kunde.example.ch', 'setup@example.ch', $secretA), 220));
$pageB = $bs->go('/member/main/profile/totp');
[$secretB] = $readSetup($pageB[2]);
check('a reload: a new secret', $secretA !== '' && $secretA !== $secretB);
$tok = preg_match('/name="csrf_token" value="([^"]+)"/', $pageB[2], $m) ? $m[1] : '';
$wrong = $bs->go('/member/main/profile/totp', ['csrf_token' => $tok, 'code' => '000000']);
[$secretC] = $readSetup($wrong[2]);
check('a wrong code: the same QR again, no second scan', $secretC === $secretB && str_contains($wrong[2], 'Der Code ist ungültig'));
$r = $bs->go('/member/main/profile/totp', ['csrf_token' => $tok, 'code' => $code($now, $secretB)]);
check('the right code switches on', in_array($r[0], [302, 303], true) && ($siteRow('acc-set')['totp_activated_at'] ?? null) !== null, $r[0] . ' ' . json_encode($siteRow('acc-set')));
check('...and is spent', ($siteRow('acc-set')['totp_last_step'] ?? 0) === intdiv($now, 30));
$src = (string) file_get_contents($root . '/packages/module-member/src/Ui/Controllers/Main/ProfileController.php');
check('no name is hard-coded as the issuer', !preg_match("/otpauthUri\('/", $src));

// The IP limit through the real form: three browsers armed, one time trap to wait out.
$outbox = $site . '/data/framework/mail/outbox';
$mailCount = static fn(): int => count(glob($outbox . '/*') ?: []);
$browsers = [];
for ($i = 0; $i < 3; $i++) {
    $w = new RealFlowBrowser($port);
    $browsers[] = [$w, $tokenOf($w, '/member/main/login')];
}
sleep(4);
$start  = $mailCount();
$status = [];
foreach ($browsers as $i => [$w, $t]) {
    $r = $w->go('/member/main/login', ['csrf_token' => $t, 'email' => "erfunden{$i}@example.ch", 'website' => '', 'fax' => '']);
    $status[] = $r[0] . ' ' . ($r[1]['location'] ?? '');
}
check('three requests from one address: the same answer each', count(array_unique($status)) === 1 && str_contains($status[0], '/member/main/login/warten'), implode(' | ', $status));
check('...two mails, the limit is 2', $mailCount() - $start === 2, (string) ($mailCount() - $start));

// A label the project sets. A server of its own: a running one keeps the config in its APCu.
$override = $site . '/project-override/src/App/Config/memberConfig.inc.php';
file_put_contents($override, str_replace("'loginRequestsPerHourPerIp' => 2,", "'loginRequestsPerHourPerIp' => 2, 'totpIssuer' => 'Beispiel Portal',", (string) file_get_contents($override)));
$port2 = realFlowServer($site, $base . '/sessions', $serverLog);
// A second account for the second server: acc-set has 2FA on now.
$rows = json_decode((string) file_get_contents($siteAccounts), true);
$rows[] = ['id' => 'acc-lab', 'email' => 'label@example.ch', 'first_name' => 'Label', 'state' => 'active', 'roles' => ['customer']];
file_put_contents($siteAccounts, json_encode($rows, JSON_PRETTY_PRINT));
$b4 = new RealFlowBrowser($port2);
$plain = $issueLink('acc-lab');
$page  = $b4->go('/member/main/login/redeem?token=' . $plain);
preg_match('/name="csrf_token" value="([^"]+)"/', $page[2], $m);
$b4->go('/member/main/login/redeem', ['csrf_token' => $m[1] ?? '', 'token' => $plain, 'decision' => 'here']);
$page = $b4->go('/member/main/profile/totp');
[$secretL, $qrL] = $readSetup($page[2]);
check('a project sets its label: memberConfig totpIssuer', $qrL !== '' && $qrL === QrCode::pngDataUri(Totp::otpauthUri('Beispiel Portal', 'label@example.ch', $secretL), 220));

$serverText = (string) @file_get_contents($serverLog);
check('no PHP error reached the server log', !preg_match('/PHP (Fatal|Parse|Warning)/', $serverText), (string) (preg_match('/.*PHP (Fatal|Parse|Warning).*/', $serverText, $m) ? $m[0] : ''));

if ($fail > 0) {
    echo "--- log tail ---\n" . substr((string) @file_get_contents($site . "/logs/php-error.log"), -1500) . "\n";
    echo substr(preg_replace("/^.*(Accepted|Closing).*\n/m", "", $serverText), -1500) . "\n";
}
echo "\nmember-2fa: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
