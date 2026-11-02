<?php

/**
 * Backend 2FA harness (CLI, temp ABS_BASE_PATH, no database) — login.md BACKEND-2FA-001. What is
 * load-bearing:
 *
 *   (A) the moved classes: RFC vectors, the drift, verify() as before, acceptedStep() rejecting a
 *       spent step; the old class names still work; the backend's key file and guard directory are
 *       its own;
 *   (B) the new fields: a store without the keys loads and saves, a form body cannot set them;
 *   (C) the member's TOTP is unaffected: same key path and guard directory, a secret encrypted
 *       before the move still reads, setup / login / lock through the real LoginFlow, no replay
 *       check added;
 *   (D) concurrency across real processes: of 6 parallel uses of one code exactly one passes; of 12
 *       parallel wrong guesses no more than 5 reach the check before the lock;
 *   (E) the real flow through the framework in PHP's built-in server, with browser-like cookies:
 *       enrolment, login, lock, expiry, replay, epoch, the reset form, the admin reset, the switch
 *       off, form bodies, plaintext on disk; and on TWO servers over one site: real parallel saves
 *       against a switch-on, a reset and a spent code. Roles (who may grant and edit), id reuse,
 *       URL casing, a damaged key file, what a thief sees of an enrolment, and the fail-closed code
 *       lock on dead counters with its log line and alert.
 *
 * Run: php tests/backend-2fa.php
 */

use Z77\Core\Config\AuthRole;
use Z77\Core\Controller\ControllerHandler;
use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Session\SessionManager;
use Z77\Module\Backend\TwoFactor\BackendTwoFactor;
use Z77\Module\Backend\TwoFactor\TotpCheck;
use Z77\Module\Member\Entities\MemberAccount;
use Z77\Module\Member\Services\DeviceCookie;
use Z77\Module\Member\Services\DeviceKeys;
use Z77\Module\Member\Services\InvitationFlow;
use Z77\Module\Member\Services\LoginFlow;
use Z77\Module\Member\Services\MemberAccounts;
use Z77\Module\Member\Services\MemberSession;
use Z77\Module\Member\Services\MemberThrottle;
use Z77\Module\Member\Services\MemberTotp;
use Z77\Module\Member\Services\PendingLogins;
use Z77\Module\Member\Services\RegistrationFlow;
use Z77\Module\Member\Services\TokenService;
use Z77\Module\Member\Services\TotpSetup;
use Z77\Persistence\Cleaning\BodyCleaner;
use Z77\Persistence\File\Storage\FileStorage;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Auth\Totp;
use Z77\Shared\Auth\TotpGuard;
use Z77\Shared\Auth\TotpVault;
use Z77\Shared\Entities\BackendUser;
use Z77\Shared\Services\AuthService;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "backend-2fa: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}

// Worker for the concurrency checks: runs one code check, prints the outcome's name.
if (($argv[1] ?? '') === '--check-worker') {
    define('ABS_BASE_PATH', $argv[2]);
    require $autoload;
    DI::getInstance(true)->set('CacheManager', CacheManager::class, true);
    $uem = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
    DI::getInstance()->set('UnifiedEntityManager', $uem, true);
    $twoFactor = new BackendTwoFactor(
        $uem,
        new TotpVault($argv[2] . '/data/framework/auth/totp.key'),
        new TotpGuard($argv[2] . '/var/lib/throttle/backend-totp-guard'),
        $argv[2] . '/var/lib/throttle/backend-totp-guard',
        new SessionManager(),
        static fn(): bool => true,
    );
    while (microtime(true) < (float) $argv[5]) {
        usleep(200);
    }
    $user = $uem->getRepository(BackendUser::class)->find((int) $argv[3]);
    echo $twoFactor->check($user, $argv[4])->name;
    exit(0);
}

// Worker for the key file race: first use from several processes at once.
if (($argv[1] ?? '') === '--vault-worker') {
    define('ABS_BASE_PATH', sys_get_temp_dir());
    require $autoload;
    while (microtime(true) < (float) $argv[3]) {
        usleep(200);
    }
    echo (new TotpVault($argv[2]))->encrypt('RACE');
    exit(0);
}

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-backend-2fa-' . bin2hex(random_bytes(4));
mkdir($base . '/data', 0777, true);
define('ABS_BASE_PATH', $base);

require $autoload;
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';
require_once __DIR__ . '/support/real-flow-site.php';

// The monorepo autoloader does not map module-member — same loader as member-operator-invite.php.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Z77\\Module\\Member\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../packages/module-member/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

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

DI::getInstance(true)->set('CacheManager', CacheManager::class, true);
$uem = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
DI::getInstance()->set('UnifiedEntityManager', $uem, true);

// ── (A) the moved classes ───────────────────────────────────────────────────
$offlineLog = $base . '/offline-error.log';
ini_set('error_log', $offlineLog);
ini_set('log_errors', '1');

echo "(A) the moved classes\n";
$rfc = Totp::base32Encode('12345678901234567890');
check('RFC 6238 vector T=59', Totp::code($rfc, 59) === '287082');
check('RFC 6238 vector T=1111111109', Totp::code($rfc, 1111111109) === '081804');
check('RFC 6238 vector T=1234567890', Totp::code($rfc, 1234567890) === '005924');
$t = 1_000_000_020; // the start of a step
$step = intdiv($t, Totp::PERIOD_SECONDS);
check('one period of drift is tolerated, both ways', Totp::verify($rfc, Totp::code($rfc, $t - 30), $t) && Totp::verify($rfc, Totp::code($rfc, $t + 30), $t));
check('two periods are not', !Totp::verify($rfc, Totp::code($rfc, $t - 60), $t) && !Totp::verify($rfc, Totp::code($rfc, $t + 60), $t));
check('malformed codes are refused', !Totp::verify($rfc, '12345', 59) && !Totp::verify($rfc, 'abcdef', 59) && !Totp::verify($rfc, '', 59));
check('verify() takes the same code as often as asked (the member relies on nothing else)', Totp::verify($rfc, Totp::code($rfc, $t), $t) && Totp::verify($rfc, Totp::code($rfc, $t), $t));
check('acceptedStep: the step of the code', Totp::acceptedStep($rfc, Totp::code($rfc, $t), $t) === $step);
check('acceptedStep: the step of a code with drift', Totp::acceptedStep($rfc, Totp::code($rfc, $t - 30), $t) === $step - 1 && Totp::acceptedStep($rfc, Totp::code($rfc, $t + 30), $t) === $step + 1);
check('acceptedStep: a spent step is refused (RFC 6238 §5.2)', Totp::acceptedStep($rfc, Totp::code($rfc, $t), $t, $step) === null);
check('acceptedStep: so is an older one within the drift', Totp::acceptedStep($rfc, Totp::code($rfc, $t - 30), $t, $step) === null);
check('acceptedStep: the next one passes', Totp::acceptedStep($rfc, Totp::code($rfc, $t + 30), $t, $step) === $step + 1);
check('acceptedStep: wrong and malformed are null', Totp::acceptedStep($rfc, '000000', 59) === null && Totp::acceptedStep($rfc, 'x', 59) === null);

check('the old name Totp is the kernel class', (new ReflectionClass('Z77\\Module\\Member\\Services\\Totp'))->getName() === Totp::class);
check('the old name TotpGuard is the kernel class', (new ReflectionClass('Z77\\Module\\Member\\Services\\TotpGuard'))->getName() === TotpGuard::class);
check('the old name TotpVault is the kernel class', (new ReflectionClass('Z77\\Module\\Member\\Services\\TotpVault'))->getName() === TotpVault::class);
$oldVault = new \Z77\Module\Member\Services\TotpVault($base . '/old-name/totp.key');
check('…and works as a project harness uses it (new with a path)', $oldVault->decrypt($oldVault->encrypt('ABC')) === 'ABC');
$oldGuard = new \Z77\Module\Member\Services\TotpGuard($base . '/old-name/guard');
check('…with its constants', \Z77\Module\Member\Services\TotpGuard::LOCK_SECONDS === 900 && !$oldGuard->isLocked('x'));

$kernelFiles = '';
foreach (['Totp', 'TotpGuard', 'TotpVault'] as $class) {
    $kernelFiles .= (string) file_get_contents(__DIR__ . '/../packages/kernel/shared/src/Auth/' . $class . '.php');
}
check('the kernel classes name no path of a module', !str_contains($kernelFiles, "ABS_BASE_PATH") && !str_contains($kernelFiles, "use Z77\\Module"));
$sharedSrc = (string) file_get_contents(__DIR__ . '/../packages/module-backend/src/TwoFactor/BackendTwoFactor.php');
check('the backend does not depend on module-member', !str_contains($sharedSrc, 'Module\\Member'));
check('the backend has its own key file and guard directory', str_contains($sharedSrc, "'/data/framework/auth/totp.key'") && str_contains($sharedSrc, "'/var/lib/throttle/backend-totp-guard'"));

// A key file is never rewritten by a read. One trailing newline must stay repairable.
$keyDir = $base . '/key-tests';
mkdir($keyDir);
$keyPath = $keyDir . '/totp.key';
$keyVault = new TotpVault($keyPath);
check('a read creates no key file', $keyVault->decrypt(base64_encode(str_repeat('x', 40))) === null && !is_file($keyPath));
$cipher = $keyVault->encrypt('SECRETSECRET');
$intact = (string) file_get_contents($keyPath);
check('the first encrypt creates a 32-byte key', strlen($intact) === 32 && $keyVault->decrypt($cipher) === 'SECRETSECRET');
file_put_contents($keyPath, $intact . "\n");
check('a damaged key file: decrypt answers null', $keyVault->decrypt($cipher) === null);
check('…the file stays as it is, nothing overwritten', file_get_contents($keyPath) === $intact . "\n");
$thrown = false;
try {
    $keyVault->encrypt('NEU');
} catch (RuntimeException) {
    $thrown = true;
}
check('…encrypt refuses', $thrown && file_get_contents($keyPath) === $intact . "\n");
check('…and it says so in the log, naming the file', str_contains((string) file_get_contents($offlineLog), 'key file is damaged') && str_contains((string) file_get_contents($offlineLog), $keyPath));
file_put_contents($keyPath, $intact);
check('…repaired by hand, every secret reads again', $keyVault->decrypt($cipher) === 'SECRETSECRET');
$raceKey   = $keyDir . '/race.key';
$raceStart = microtime(true) + 0.8;
$raceProcs = [];
$racePipes = [];
for ($i = 0; $i < 6; $i++) {
    $raceProcs[$i] = proc_open([PHP_BINARY, __FILE__, '--vault-worker', $raceKey, (string) $raceStart], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $racePipes[$i]);
}
$raceOut = [];
foreach ($raceProcs as $i => $proc) {
    $raceOut[] = trim((string) stream_get_contents($racePipes[$i][1]));
    proc_close($proc);
}
$raceVault = new TotpVault($raceKey);
check('6 processes on first use: one key, all six secrets read', count(array_filter($raceOut, static fn(string $c): bool => $raceVault->decrypt($c) === 'RACE')) === 6, implode(',', array_map('strlen', $raceOut)));

// ── (B) the new fields ──────────────────────────────────────────────────────
echo "(B) the new fields\n";
$storage = new FileStorage();
$storage->save('framework/auth/backendUsers.json', [
    ['id' => 40, 'username' => 'alt', 'password_hash' => 'x', 'roles' => ['admin'], 'sort_key' => 3, 'password_weak' => true, 'auth_epoch' => 2],
]);
$repo = $uem->getRepository(BackendUser::class);
$old  = $repo->find(40);
check('a store without the keys loads, 2FA off', $old instanceof BackendUser && !$old->hasTotp() && $old->getTotpSecret() === '' && $old->getTotpActivatedAt() === '' && $old->getTotpLastStep() === 0);
$uem->persist($old);
$uem->flush();
$row = $storage->load('framework/auth/backendUsers.json')[0];
check('…and saves with all three keys, snake_case', ($row['totp_secret'] ?? null) === '' && ($row['totp_activated_at'] ?? null) === '' && ($row['totp_last_step'] ?? null) === 0);
check('…the other fields survive', $row['username'] === 'alt' && $row['sort_key'] === 3 && $row['password_weak'] === true && $row['auth_epoch'] === 2);

$withTotp = $repo->find(40);
$withTotp->enableTotp('cipher', '2026-11-01T10:00:00+01:00', 77);
$uem->persist($withTotp);
$uem->flush();
$back = $repo->find(40);
check('the fields round-trip through the file', $back->hasTotp() && $back->getTotpSecret() === 'cipher' && $back->getTotpActivatedAt() === '2026-11-01T10:00:00+01:00' && $back->getTotpLastStep() === 77);

$hostile = ['totp_secret' => 'BOESE', 'totp_activated_at' => '2020-01-01', 'totp_last_step' => 999999, 'totpSecret' => 'BOESE', 'totpActivatedAt' => 'x', 'totpLastStep' => 5, 'username' => 'neu'];
$form = new BackendUser();
$form->mapFromArray(BodyCleaner::cleanFor(BackendUser::class, $hostile));
check('a form body sets none of the fields (mapFromArray)', !$form->hasTotp() && $form->getTotpSecret() === '' && $form->getTotpLastStep() === 0 && $form->getUsername() === 'neu');
$form = new BackendUser($hostile);
check('…nor through the constructor', !$form->hasTotp() && $form->getTotpSecret() === '');
$back->mapFromArray(BodyCleaner::cleanFor(BackendUser::class, ['totp_secret' => '', 'totp_activated_at' => '', 'totp_last_step' => 0]));
check('…nor can a body clear them on a user who has 2FA', $back->hasTotp() && $back->getTotpSecret() === 'cipher' && $back->getTotpLastStep() === 77);
foreach (['setTotpSecret', 'setTotpActivatedAt', 'setTotpLastStep'] as $setter) {
    check("no {$setter}()", !method_exists(BackendUser::class, $setter));
}

// A stale read cannot undo what another request wrote: only the columns a save changed are written
// (persistence-file.md DATA-MERGE-001). The parallel version runs on two servers in (E).
$storage->save('framework/auth/backendUsers.json', [
    ['id' => 50, 'username' => 'alt', 'password_hash' => 'h1', 'roles' => ['superUser'], 'sort_key' => 3, 'auth_epoch' => 2],
]);
$row50 = static fn(): array => $storage->load('framework/auth/backendUsers.json')[0];
$stale = $repo->find(50);
$other = $repo->find(50);
$other->enableTotp('cipher', '2026-11-01T10:00:00+01:00', 77);
$uem->persist($other);
$uem->flush();
$stale->setName('Max Muster');
$uem->persist($stale);
$uem->flush();
check('stale save: its own column is written', $row50()['name'] === 'Max Muster');
check('stale save: a switch-on in between stays', $row50()['totp_secret'] === 'cipher' && $row50()['totp_last_step'] === 77);
$stale2 = $repo->find(50);
$other  = $repo->find(50);
$other->recordTotpStep(99);
$other->clearTotp();
$uem->persist($other);
$uem->flush();
$stale2->setName('Erna Beispiel');
$uem->persist($stale2);
$uem->flush();
check('stale save: a reset in between stays', $row50()['totp_secret'] === '' && $row50()['totp_activated_at'] === '');
$stale3 = $repo->find(50);
$other  = $repo->find(50);
$other->enableTotp('c2', 'at', 5);
$uem->persist($other);
$uem->flush();
$other = $repo->find(50);
$other->recordTotpStep(6);
$uem->persist($other);
$uem->flush();
$uem->persist($stale3);
$uem->flush();
check('stale save: a spent step does not come back', $row50()['totp_last_step'] === 6);
$stale4 = $repo->find(50);
$other  = $repo->find(50);
$other->bumpAuthEpoch();
$uem->persist($other);
$uem->flush();
$uem->persist($stale4);
$uem->flush();
check('stale save: an old auth_epoch does not come back', $row50()['auth_epoch'] === 3);
$one = $repo->find(50);
$two = $repo->find(50);
$one->bumpAuthEpoch();
$two->bumpAuthEpoch();
$uem->persist($one);
$uem->flush();
$uem->persist($two);
$uem->flush();
check('two raises from one read raise twice', $row50()['auth_epoch'] === 5);
check('…and the entity holds what was written', $two->getAuthEpoch() === 5);
$uem->persist($two);
$uem->flush();
check('…saving it again raises nothing', $row50()['auth_epoch'] === 5);
$stale5 = $repo->find(50);
$other  = $repo->find(50);
$other->setRoles(['editor']);
$other->setPasswordHash('h2');
$uem->persist($other);
$uem->flush();
$stale5->setPreferences(['palette' => 'salbei']);
$uem->persist($stale5);
$uem->flush();
check('stale save: a demotion and a new password in between stay', $row50()['roles'] === ['editor'] && $row50()['password_hash'] === 'h2' && $row50()['preferences'] === ['palette' => 'salbei']);
check('…and the object in hand knows them, a second save cannot roll back', $stale5->getRoles() === ['editor']);
$legacyRows = $storage->load('framework/auth/backendUsers.json');
unset($legacyRows[0]['totp_secret'], $legacyRows[0]['totp_activated_at'], $legacyRows[0]['totp_last_step']);
$storage->save('framework/auth/backendUsers.json', $legacyRows);
$stale6 = $repo->find(50);
$other  = $repo->find(50);
$other->enableTotp('c3', 'at', 8);
$uem->persist($other);
$uem->flush();
$uem->persist($stale6);
$uem->flush();
check('stale save read from a row without the keys: the switch-on stays', $row50()['totp_secret'] === 'c3');
$epochField = new BackendUser(['username' => 'neu']);
$epochField->seedAuthEpoch(1_800_000_000);
check('seedAuthEpoch is no setter a body could reach', $epochField->getAuthEpoch() === 1_800_000_000 && !method_exists(BackendUser::class, 'setAuthEpoch') && !method_exists(BackendUser::class, 'setSeedAuthEpoch'));
$storage->save('framework/auth/backendUsers.json', [
    ['id' => 40, 'username' => 'alt', 'password_hash' => 'x', 'roles' => ['admin'], 'sort_key' => 3],
]);

$half = new BackendUser();
$half->enableTotp('cipher', '', 0);
check('a secret without activation is no 2FA', !$half->hasTotp());
$back->clearTotp();
check('clearTotp() takes all three', !$back->hasTotp() && $back->getTotpSecret() === '' && $back->getTotpActivatedAt() === '' && $back->getTotpLastStep() === 0);

// A password alone never opens an account with 2FA, whoever calls login().
final class HarnessSession extends SessionManager
{
    public function regenerate(): void {}
}
$handler = (new ReflectionClass(ControllerHandler::class))->newInstanceWithoutConstructor();
$session = new HarnessSession();
$_SESSION = [];
$locked = new BackendUser(['username' => 'max']);
$locked->setPasswordHash(password_hash('ein-langes-passwort', PASSWORD_BCRYPT, ['cost' => 4]));
$locked->enableTotp('cipher', date(DATE_ATOM), 1);
$thrown = false;
try {
    (new AuthService($session, $handler))->canLogin($locked, 'ein-langes-passwort')->login();
} catch (LogicException) {
    $thrown = true;
}
check('canLogin() + login() on an account with 2FA throws, no identity', $thrown && !isset($_SESSION['auth_user']));
(new AuthService($session, $handler))->passedSecondFactor($locked)->login();
check('passedSecondFactor() + login() signs in', isset($_SESSION['auth_user']));
$_SESSION = [];
$locked->clearTotp();
(new AuthService($session, $handler))->canLogin($locked, 'ein-langes-passwort')->login();
check('an account without 2FA logs in with the password, as before', isset($_SESSION['auth_user']));
$_SESSION = [];

// ── (C) the member's TOTP is unaffected ─────────────────────────────────────
echo "(C) member TOTP\n";
$memberKey   = new ReflectionProperty(TotpVault::class, 'keyFile');
$memberGuard = new ReflectionProperty(TotpGuard::class, 'dir');
check('the key file path did not change', $memberKey->getValue(MemberTotp::vault()) === $base . '/data/framework/member/totp.key');
check('the guard directory did not change', $memberGuard->getValue(MemberTotp::guard()) === $base . '/var/lib/throttle/totp-guard');

// A secret from before the move: the same format, written by hand with the old recipe.
$keyFile = $base . '/data/framework/member/totp.key';
mkdir(dirname($keyFile), 0777, true);
$rawKey = random_bytes(32);
file_put_contents($keyFile, $rawKey);
$iv  = random_bytes(12);
$tag = '';
$ct  = openssl_encrypt('JBSWY3DPEHPK3PXP', 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);
check('a member secret encrypted before the move still reads', MemberTotp::vault()->decrypt(base64_encode($iv . $tag . $ct)) === 'JBSWY3DPEHPK3PXP');
check('the member key file is not overwritten', file_get_contents($keyFile) === $rawKey);

$accounts = new MemberAccounts($uem);
$account  = new MemberAccount();
$account->setEmail('max.muster@example.ch');
$accounts->save($account);
$setup  = new TotpSetup($accounts, MemberTotp::vault());
$now    = 1_900_000_020;
$secret = $setup->begin($account);
check('member setup: pending, not active', $account->hasPendingTotpSetup() && !$account->hasTotp());
check('member setup: a reload shows the same secret', $setup->begin($account) === $secret);
check('member setup: a wrong code stays off', !$setup->confirm($account, '000000', $now) && !$account->hasTotp());
check('member setup: a right code activates', $setup->confirm($account, Totp::code($secret, $now), $now) && $account->hasTotp());
$stored = $accounts->findById((string) $account->getId());
check('member secret: ciphertext in the store, under the member key', $stored->getTotpSecret() !== $secret && MemberTotp::vault()->decrypt((string) $stored->getTotpSecret()) === $secret);
check('member: the same code still confirms twice (no replay check, not changed)', Totp::verify($secret, Totp::code($secret, $now), $now));

$memberSession = new MemberSession($session);
$memberFlow = new LoginFlow(
    $accounts,
    new TokenService($uem),
    new MemberThrottle($base . '/member-throttle', 5, 3600),
    $memberSession,
    (new ReflectionClass(RegistrationFlow::class))->newInstanceWithoutConstructor(),
    MemberTotp::vault(),
    MemberTotp::guard(),
    new DeviceKeys($accounts, new DeviceCookie()),
    new PendingLogins($uem),
    static fn(): bool => true,
    'https://kunde.example.ch/member/main/login/redeem',
    'https://kunde.example.ch/member/main/register',
);
$accountId = (string) $account->getId();
$memberSession->startTotpPending($accountId, false, $now);
check('member login: a wrong code is counted', $memberFlow->confirmTotp('000000', $now + 1) === LoginFlow::TOTP_INVALID);
$memberSession->startTotpPending($accountId, false, $now);
check('member login: the right code starts the session', $memberFlow->confirmTotp(Totp::code($secret, $now + 2), $now + 2) === LoginFlow::SESSION);
$memberSession->startTotpPending($accountId, false, $now);
check('member login: the same code again still passes (reported, not changed)', $memberFlow->confirmTotp(Totp::code($secret, $now + 2), $now + 3) === LoginFlow::SESSION);
$memberSession->startTotpPending($accountId, false, $now);
for ($i = 0; $i < 4; $i++) {
    $memberFlow->confirmTotp('000000', $now + 4);
}
check('member login: the fifth failure locks', $memberFlow->confirmTotp('000000', $now + 4) === LoginFlow::TOTP_LOCKED);
check('member login: while locked the right code is refused', $memberFlow->confirmTotp(Totp::code($secret, $now + 5), $now + 5) === LoginFlow::TOTP_LOCKED);
check('member guard: its file is where it was', count(glob($base . '/var/lib/throttle/totp-guard/*.json') ?: []) === 1);
check('member: no backend guard or key involved', !is_dir($base . '/var/lib/throttle/backend-totp-guard') && !is_file($base . '/data/framework/auth/totp.key'));
$setup->resetByOperator($account);
check('member operator reset still works', !$account->hasTotp() && $account->getTotpSecret() === null);
$goodKey = (string) file_get_contents($keyFile);
file_put_contents($keyFile, $goodKey . "\n");
$thrown = false;
try {
    $setup->begin($account);
} catch (RuntimeException) {
    $thrown = true;
}
check('member setup with a damaged key: begin() refuses, the file stays', $thrown && file_get_contents($keyFile) === $goodKey . "\n");
file_put_contents($keyFile, $goodKey);
$profileSrc = (string) file_get_contents(__DIR__ . '/../packages/module-member/src/Ui/Controllers/Main/ProfileController.php');
check('…the profile catches it and answers with a message, not a 500', preg_match('/try \{\s*\$secret = \$setup->begin\(\$account\);\s*\} catch \(\\\\RuntimeException \$e\) \{.*?technischen Problems.*?return \$this->redirect\(/s', $profileSrc) === 1);
unset($_SESSION['member.totp_pending']);
$_SESSION = [];

// ── (D) concurrency, real processes ─────────────────────────────────────────
echo "(D) concurrency\n";
$vaultD = new TotpVault($base . '/data/framework/auth/totp.key');
$secretD = Totp::generateSecret();
$race = new BackendUser(['username' => 'rennen']);
$race->enableTotp($vaultD->encrypt($secretD), date(DATE_ATOM), 0);
$uem->persist($race);
$uem->flush();
$raceId = (int) $race->getId();

$runWorkers = static function (int $count, string $code) use ($base, $raceId): array {
    $start = microtime(true) + 0.8;
    $procs = [];
    $pipes = [];
    for ($i = 0; $i < $count; $i++) {
        $procs[$i] = proc_open([PHP_BINARY, __FILE__, '--check-worker', $base, (string) $raceId, $code, (string) $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $out = [];
    foreach ($procs as $i => $proc) {
        $out[] = trim((string) stream_get_contents($pipes[$i][1]));
        proc_close($proc);
    }
    return $out;
};
$results = $runWorkers(6, Totp::code($secretD, time()));
check('6 processes, one code: exactly one passes', count(array_keys($results, 'Passed', true)) === 1, implode(',', $results));
check('…the others are refused and counted: 4 wrong, the 5th locks', count(array_keys($results, 'Wrong', true)) === 4 && count(array_keys($results, 'Locked', true)) === 1, implode(',', $results));

$guardD = new TotpGuard($base . '/var/lib/throttle/backend-totp-guard');
$guardD->reset((string) $raceId);
$results = $runWorkers(12, '000000');
$reachedCheck = count(array_keys($results, 'Wrong', true));
check('12 parallel wrong guesses: 4 wrong, then locked, none past the lock', $reachedCheck === 4 && count(array_keys($results, 'Locked', true)) === 8, implode(',', $results));
check('…and the account is locked', $guardD->isLocked((string) $raceId));

// The code lock fails closed; the alert can neither flood nor change the answer.
final class CountingChannel implements Z77\Shared\Alert\AlertChannelInterface
{
    public int $sent = 0;
    public function send(Z77\Shared\Alert\AlertMessage $message): void { $this->sent++; }
}
final class ThrowingChannel implements Z77\Shared\Alert\AlertChannelInterface
{
    public function send(Z77\Shared\Alert\AlertMessage $message): void { throw new RuntimeException('channel down'); }
}
$guardD->reset((string) $raceId);
$blockedFile = $base . '/blocked-file';
file_put_contents($blockedFile, 'x');
$deadDir = $blockedFile . '/guard';
$goodDir = $base . '/var/lib/throttle/backend-totp-guard';
$raceUser = $uem->getRepository(BackendUser::class)->find($raceId);
$twoFactorWith = static function (string $guardPath, ?Z77\Shared\Alert\AlertService $alerts) use ($uem, $base, $session): BackendTwoFactor {
    return new BackendTwoFactor($uem, new TotpVault($base . '/data/framework/auth/totp.key'), new TotpGuard($guardPath), $guardPath, $session, static fn(): bool => true, $alerts);
};
$rightD = static fn(int $offset = 0): string => Totp::code($secretD, time() + $offset);
$counter = new CountingChannel();
$alertDir = $base . '/alert-state';
$dead = $twoFactorWith($deadDir, new Z77\Shared\Alert\AlertService($alertDir, [$counter]));
file_put_contents($offlineLog, '');
check('dead counters: the right code is refused', $dead->check($raceUser, $rightD()) === TotpCheck::Unavailable);
check('…a distinct message, no «falsch»', !str_contains(TotpCheck::Unavailable->refusal(), 'falsch') && str_contains(TotpCheck::Unavailable->refusal(), 'technischen Problems'));
$logged = (string) file_get_contents($offlineLog);
check('…the log line names the step and the directory', str_contains($logged, 'step=write-counter') && str_contains($logged, $deadDir));
check('…never a code or a secret', !str_contains($logged, $rightD()) && !str_contains($logged, $secretD));
for ($i = 0; $i < 20; $i++) {
    $dead->check($raceUser, $rightD());
}
check('21 refusals, one alert', $counter->sent === 1, (string) $counter->sent);
check('a wrong code is refused the same way, nothing counted', $dead->check($raceUser, '000000') === TotpCheck::Unavailable);
$working = $twoFactorWith($goodDir, new Z77\Shared\Alert\AlertService($alertDir, [$counter]));
check('counters back: the right code passes, no intervention', $working->check($raceUser, $rightD(30)) === TotpCheck::Passed);
check('…one recovery alert', $counter->sent === 2, (string) $counter->sent);

// The alert's own state is dead: both shapes. Its answer is no mail, not one mail per refusal.
$counter2 = new CountingChannel();
$deadState = $twoFactorWith($deadDir, new Z77\Shared\Alert\AlertService($blockedFile . '/alert', [$counter2]));
check('alert state directory cannot exist: refused, no throw', $deadState->check($raceUser, '123456') === TotpCheck::Unavailable);
check('…no mail', $counter2->sent === 0);
$unwritable = $base . '/alert-unwritable';
mkdir($unwritable);
mkdir($unwritable . '/' . hash('sha256', 'backend-2fa:code-lock') . '.json');
$counter3 = new CountingChannel();
$noWrite = $twoFactorWith($deadDir, new Z77\Shared\Alert\AlertService($unwritable, [$counter3]));
for ($i = 0; $i < 10; $i++) {
    $noWrite->check($raceUser, '123456');
}
check('alert state cannot be written: 10 refusals, no flood, no mail', $counter3->sent === 0, (string) $counter3->sent);
$throwing = $twoFactorWith($deadDir, new Z77\Shared\Alert\AlertService($base . '/alert-throw', [new ThrowingChannel()]));
check('a throwing channel does not change the refusal', $throwing->check($raceUser, $rightD()) === TotpCheck::Unavailable);
$throwingOk = $twoFactorWith($goodDir, new Z77\Shared\Alert\AlertService($base . '/alert-throw', [new ThrowingChannel()]));
$raceRows = $storage->load('framework/auth/backendUsers.json');
foreach ($raceRows as &$raceRow) {
    if ($raceRow['id'] === $raceId) {
        $raceRow['totp_last_step'] = 0;
    }
}
unset($raceRow);
$storage->save('framework/auth/backendUsers.json', $raceRows);
check('…nor a pass', $throwingOk->check($raceUser, $rightD()) === TotpCheck::Passed);

// A half-done reset: the open link cannot be ended, so nothing happens at all (the link would
// otherwise work without the code).
$resetsLock = $base . '/data/framework/auth/passwordResets.json.lock';
@unlink($resetsLock);
mkdir($resetsLock, 0777, true);
$secretBefore = $uem->getRepository(BackendUser::class)->find($raceId)->getTotpSecret();
$halfDone = @$working->resetByAdmin($raceUser, 1);
check('reset with an open link that cannot be ended: refused, 2FA stays', $halfDone === false && $uem->getRepository(BackendUser::class)->find($raceId)->getTotpSecret() === $secretBefore && $secretBefore !== '', var_export($halfDone, true));
rmdir($resetsLock);

// ── (E) the real flow, in PHP's built-in server ─────────────────────────────
echo "(E) real flow (built-in server)\n";
$root = dirname(__DIR__);
$site = $base . '/site';
$hash = password_hash('Passwort-Sehr-Lang-1', PASSWORD_BCRYPT, ['cost' => 4]);
realFlowSite($site, $root, [
    ['id' => 1, 'username' => 'max', 'name' => 'Max Muster', 'email' => 'max.muster@example.ch', 'password_hash' => $hash, 'roles' => ['superUser'], 'sort_key' => 0],
    ['id' => 2, 'username' => 'erna', 'name' => 'Erna Beispiel', 'email' => 'erna.beispiel@example.ch', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 1],
    ['id' => 3, 'username' => 'redaktor', 'email' => 'redaktor@example.ch', 'password_hash' => $hash, 'roles' => ['editor'], 'sort_key' => 2, 'password_weak' => true],
    ['id' => 4, 'username' => 'verwalter', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 3],
    ['id' => 5, 'username' => 'muster1', 'email' => 'muster1@example.ch', 'password_hash' => $hash, 'roles' => ['editor'], 'sort_key' => 4],
    ['id' => 6, 'username' => 'muster2', 'email' => 'muster2@example.ch', 'password_hash' => $hash, 'roles' => ['editor'], 'sort_key' => 5],
    ['id' => 7, 'username' => 'muster3', 'email' => 'muster3@example.ch', 'password_hash' => $hash, 'roles' => ['editor'], 'sort_key' => 6],
    ['id' => 8, 'username' => 'muster4', 'email' => 'muster4@example.ch', 'password_hash' => $hash, 'roles' => ['editor'], 'sort_key' => 7],
    ['id' => 9, 'username' => 'zweit', 'email' => 'zweit@example.ch', 'password_hash' => $hash, 'roles' => ['superUser'], 'sort_key' => 8],
    ['id' => 10, 'username' => 'kollegin', 'email' => 'kollegin@example.ch', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 9],
    ['id' => 11, 'username' => 'alt', 'email' => 'alt@example.ch', 'password_hash' => $hash, 'roles' => ['superUser'], 'sort_key' => 10],
    ['id' => 12, 'username' => 'muster5', 'email' => 'muster5@example.ch', 'password_hash' => $hash, 'roles' => ['editor'], 'sort_key' => 11],
    ['id' => 13, 'username' => 'muster6', 'email' => 'muster6@example.ch', 'password_hash' => $hash, 'roles' => ['editor'], 'sort_key' => 12],
]);
mkdir($base . '/sessions');
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port  = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$server = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $base . '/sessions', '-S', "127.0.0.1:{$port}", '-t', $site . '/public', $site . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $base . '/server.log', 'w'], 2 => ['file', $base . '/server.log', 'a']],
    $serverPipes,
    $site
);
// A second server over the same site: requests that really run side by side, as PHP-FPM workers do.
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port2 = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$server2 = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $base . '/sessions', '-S', "127.0.0.1:{$port2}", '-t', $site . '/public', $site . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $base . '/server2.log', 'w'], 2 => ['file', $base . '/server2.log', 'a']],
    $server2Pipes,
    $site
);
register_shutdown_function(static function () use ($server, $server2): void {
    foreach ([$server, $server2] as $proc) {
        if (is_resource($proc)) {
            proc_terminate($proc);
        }
    }
});
foreach ([$port, $port2] as $waitPort) {
    $deadline = microtime(true) + 10;
    do {
        usleep(100000);
        $sock = @fsockopen('127.0.0.1', $waitPort, $e1, $e2, 0.2);
    } while ($sock === false && microtime(true) < $deadline);
    if ($sock !== false) {
        fclose($sock);
    }
}

/** One browser: a cookie jar and raw requests, redirects NOT followed. */
final class Browser
{
    public array $cookies = [];

    public function __construct(private int $port) {}

    /** @return array{0: int, 1: array<string, string>, 2: string} */
    public function go(string $path, array|string|null $post = null, array $headers = []): array
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
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int) $m[1]; continue; }
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $name = strtolower(trim($name));
            $out[$name] = trim($value);
            if ($name === 'set-cookie' && preg_match('/^([^=]+)=([^;]*)/', trim($value), $c)) {
                $this->cookies[$c[1]] = $c[2];
            }
        }
        return [$status, $out, $body];
    }

    public function status(string $path): int
    {
        return $this->go($path)[0];
    }

    /** The token of this session from a form or from the shell's meta tag. */
    public function pageToken(string $path = '/login'): string
    {
        $html = $this->go($path)[2];
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) || preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    public function password(string $username, string $password = 'Passwort-Sehr-Lang-1'): array
    {
        return $this->go('/backend/system/login/login', ['csrf_token' => $this->pageToken(), 'username' => $username, 'password' => $password]);
    }

    public function code(string $code): array
    {
        return $this->go('/backend/system/login/code', ['csrf_token' => $this->pageToken('/backend/system/login/code'), 'code' => $code]);
    }

    /** The logout button: a POST with the session's token. */
    public function logout(): array
    {
        return $this->go('/backend/system/login/logout', ['csrf_token' => $this->pageToken()]);
    }

    /** A fetch POST as core.js sends it: JSON, the mode header, the CSRF header. */
    public function fetchPost(string $path, array $body, ?string $token = null): array
    {
        $token ??= $this->pageToken('/backend/system/dashboard/overview');
        return $this->go($path, (string) json_encode($body), ['Sec-Fetch-Mode: cors', 'X-CSRF-Token: ' . $token]);
    }

    public function fetchGet(string $path): array
    {
        return $this->go($path, null, ['Sec-Fetch-Mode: cors', 'X-Requested-With: XMLHttpRequest']);
    }

    /** A fetch POST that does not wait: the socket comes back while the server is still at it. */
    public function fetchPostAsync(string $path, array $body, string $token)
    {
        $socket = stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $error, 5);
        $json   = (string) json_encode($body);
        $pairs  = [];
        foreach ($this->cookies as $k => $v) {
            $pairs[] = "{$k}={$v}";
        }
        fwrite($socket, "POST {$path} HTTP/1.0\r\nHost: 127.0.0.1\r\nCookie: " . implode('; ', $pairs)
            . "\r\nContent-Type: application/json\r\nSec-Fetch-Mode: cors\r\nX-CSRF-Token: {$token}\r\nContent-Length: " . strlen($json) . "\r\n\r\n{$json}");

        return $socket;
    }
}

$browser = static fn(): Browser => new Browser($port);
$usersFile = $site . '/data/framework/auth/backendUsers.json';
$storedUser = static function (string $username) use ($usersFile): array {
    return array_column(json_decode((string) file_get_contents($usersFile), true), null, 'username')[$username];
};
// Test-side store edits go through PHP's JSON functions, on the temp site only.
$editUser = static function (string $username, array $changes) use ($usersFile): void {
    $rows = json_decode((string) file_get_contents($usersFile), true);
    foreach ($rows as &$row) {
        if ($row['username'] === $username) {
            $row = $changes + $row;
        }
    }
    unset($row);
    file_put_contents($usersFile, json_encode($rows, JSON_PRETTY_PRINT));
};
/** A code the server takes now: the last step is reset, otherwise a login a few seconds later would be a replay. */
$freshCode = static function (string $username, string $secret) use ($editUser): string {
    $editUser($username, ['totp_last_step' => 0]);
    return Totp::code($secret, time());
};
$sessionOf = static function (Browser $who) use ($base): string {
    $id = $who->cookies['PHPSESSID'] ?? '';
    return (string) @file_get_contents($base . '/sessions/sess_' . $id);
};
$outbox = static function () use ($site): array {
    $files = glob($site . '/data/framework/mail/outbox/*.eml') ?: [];
    sort($files);
    return $files;
};
$decoded = static fn(string $file): string => $file === '' ? '' : quoted_printable_decode((string) file_get_contents($file));
$secretFrom = static function (string $html): string {
    // The dialog's answer is HTML; the key stands grouped in its <code>.
    return preg_match('/be-form__secret[^>]*>([A-Z2-7 ]+)</', $html, $m) ? str_replace(' ', '', $m[1]) : '';
};
$guardDir = $site . '/var/lib/throttle/backend-totp-guard';
$guardFile = static fn(int $id): string => $guardDir . '/' . hash('sha256', (string) $id) . '.json';
$serverLog = static function () use ($base, $site): string {
    return (string) @file_get_contents($base . '/server.log') . (string) @file_get_contents($base . '/server2.log') . (string) @file_get_contents($site . '/logs/php-error.log');
};
$dashboard = '/backend/system/dashboard/overview';
$isSignedIn = static fn(Browser $who): bool => $who->status($dashboard) === 200;

// Without 2FA nothing changes.
$b = $browser();
$before = $b->cookies;
[$s, $h] = $b->password('verwalter');
check('no 2FA: the password leads straight on', in_array($s, [302, 303], true) && !str_contains($h['location'] ?? '', '/login/code'), "status {$s} " . ($h['location'] ?? ''));
check('no 2FA: signed in', $isSignedIn($b));
check('no 2FA: the code page has nothing to ask', str_contains($b->go('/backend/system/login/code')[1]['location'] ?? '', 'login'));
[, , $page] = $b->go('/backend/system/login/forgot');

// ── enrolment ───────────────────────────────────────────────────────────────
echo "  enrolment\n";
$max = $browser();
$max->password('max');
check('max signed in', $isSignedIn($max));
[$s, , $page] = $max->go($dashboard);
check('the avatar panel leads to the dialog', str_contains($page, 'data-fetch-get="/backend/system/backend-user/two-factor"') && str_contains($page, 'Zwei-Faktor-Schutz'));
[$s, , $form] = $max->fetchGet('/backend/system/backend-user/edit?id=1');
check('the own user form carries the section', $s === 200 && str_contains($form, 'Zwei-Faktor-Schutz') && str_contains($form, 'Einrichten'));
[$s, , $form] = $max->fetchGet('/backend/system/backend-user/edit?id=2');
check("another user's form does not", $s === 200 && !str_contains($form, 'two-factor'), "status {$s}");

[$s, , $dialog] = $max->fetchGet('/backend/system/backend-user/two-factor');
$secret = $secretFrom($dialog);
check('the dialog: QR, key, password, code', $s === 200 && str_contains($dialog, '<svg') && $secret !== '' && str_contains($dialog, 'name="tf_password"') && str_contains($dialog, 'name="tf_code"'), "status {$s}");
check('the QR is SVG from the kernel, no XML prolog in the HTML', !str_contains($dialog, '<?xml'));
$expectedQr = preg_replace('/^<\?xml[^>]*>\s*/', '', Z77\Shared\Qr\QrCode::svg(Totp::otpauthUri('kunde.example.ch', 'max', $secret), 220));
check('the QR encodes the otpauth address: installation, user, this secret', str_contains($dialog, $expectedQr));
[, , $again] = $max->fetchGet('/backend/system/backend-user/two-factor?id=2');
check('every open makes a new secret', $secretFrom($again) !== '' && $secretFrom($again) !== $secret);
check('…an id in the URL is ignored: it is for this user', str_contains($again, 'name="tf_password"') && !isset($storedUser('erna')['totp_secret']));
$secret = $secretFrom($again);
check('it stays off until confirmed', !isset($storedUser('max')['totp_secret']) || $storedUser('max')['totp_secret'] === '');
check('the session holds the pending secret as ciphertext only', !str_contains($sessionOf($max), $secret) && str_contains($sessionOf($max), 'auth.totp_enrolment'));
$otherDevice = $browser();
$otherDevice->password('max');
[, , $otherDialog] = $otherDevice->fetchGet('/backend/system/backend-user/two-factor');
check('another session of the same user gets its own secret', $secretFrom($otherDialog) !== '' && $secretFrom($otherDialog) !== $secret);

[$s, , $answer] = $max->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'falsch-falsch-falsch', 'tf_code' => Totp::code($secret, time())]);
check('wrong password: refused on the field, stays off', str_contains($answer, '"tf_password"') && str_contains($answer, '"error"') && !($storedUser('max')['totp_secret'] ?? ''), substr($answer, 0, 200));
[$s, , $answer] = $max->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => '000000']);
check('wrong code: refused on the field, stays off', str_contains($answer, '"tf_code"') && str_contains($answer, '"error"') && !($storedUser('max')['totp_secret'] ?? ''), substr($answer, 0, 200));
[$s, , $answer] = $max->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => '']);
check('no code: refused', str_contains($answer, '"tf_code"') && !($storedUser('max')['totp_secret'] ?? ''));
[$s, , $answer] = $max->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => ['x'], 'tf_code' => ['y']]);
check('arrays instead of text: refused, no 500', $s === 200 && str_contains($answer, '"tf_password"'), "status {$s}");
[$s] = $max->go('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($secret, time())]);
check('a page POST (no fetch mode) is refused', $s === 404 && !($storedUser('max')['totp_secret'] ?? ''), "status {$s}");
[$s, , $answer] = $max->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($secret, time())], 'falsch');
check('a fetch POST without the CSRF token is refused', str_contains($answer, 'CSRF token invalid') && !($storedUser('max')['totp_secret'] ?? ''), substr($answer, 0, 150));

$before = $storedUser('erna');
[$s, , $answer] = $max->fetchPost('/backend/system/backend-user/two-factor-enable', ['id' => 2, 'user_id' => 2, 'tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($secret, time())]);
check('right password and code: on', str_contains($answer, '"success"') && str_contains($answer, 'close-modal'), substr($answer, 0, 200));
$stored = $storedUser('max');
check('…stored encrypted with an activation time and the spent step', $stored['totp_secret'] !== '' && $stored['totp_secret'] !== $secret && $stored['totp_activated_at'] !== '' && $stored['totp_last_step'] > 0);
check('…an id in the body changed nobody else', $storedUser('erna') === $before);
check('…the session no longer holds the pending secret', !str_contains($sessionOf($max), 'auth.totp_enrolment'));
check('…this session stays', $isSignedIn($max));
check('the backend key file exists, the member one does not', is_file($site . '/data/framework/auth/totp.key') && !is_file($site . '/data/framework/member/totp.key'));

$plainOnDisk = [];
foreach ([$site . '/data', $site . '/var', $base . '/sessions', $site . '/logs'] as $dir) {
    if (!is_dir($dir)) continue;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), $secret)) {
            $plainOnDisk[] = $file->getPathname();
        }
    }
}
check('the secret is not in plaintext anywhere on disk', $plainOnDisk === [], implode(', ', $plainOnDisk));
check('…nor in the server log', !str_contains($serverLog(), $secret));
check('…nor in the stored ciphertext as base64', !str_contains($stored['totp_secret'], base64_encode($secret)));

check('switching on ends his other session', !$isSignedIn($otherDevice));
[$s, , $answer] = $otherDevice->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($secretFrom($otherDialog), time())], 'x');
check('…it cannot overwrite the secret with its own', $storedUser('max')['totp_secret'] === $stored['totp_secret']);
check('…the stamp is raised', $stored['auth_epoch'] === 1);
check('…the log line', str_contains($serverLog(), '2FA switched on for user id 1'));
$onMails = array_values(array_filter($outbox(), static fn(string $m): bool => str_contains(iconv_mime_decode((string) (preg_match('/^Subject: (.+)$/m', (string) file_get_contents($m), $sm) ? trim($sm[1]) : ''), 0, 'UTF-8'), '2FA wurde eingerichtet')));
check('…one mail, to the recovery address, without a link', count($onMails) === 1 && str_contains($decoded($onMails[0] ?? ''), 'max.muster@example.ch') && !str_contains($decoded($onMails[0] ?? ''), 'href=') && !str_contains($decoded($onMails[0] ?? ''), 'http://') && !str_contains($decoded($onMails[0] ?? ''), $secret));
[$s, , $dialog] = $max->fetchGet('/backend/system/backend-user/two-factor');
check('the dialog now offers the switch off, no QR, no key', str_contains($dialog, 'two-factor-disable') && !str_contains($dialog, '<svg') && !str_contains($dialog, 'be-form__secret'));
[, , $form] = $max->fetchGet('/backend/system/backend-user/edit?id=1');
check('the form section says it is on, without the secret', str_contains($form, 'Aktiv seit') && !str_contains($form, $stored['totp_secret']));

// A body of the user form cannot touch the fields.
$saveUser = static function (Browser $who, int $id, array $fields, array $roles = ['superUser']): array {
    [, , $form] = $who->fetchGet('/backend/system/backend-user/edit?id=' . $id);
    $entity = preg_match('/name=\\\\?"entity_csrf\\\\?" value=\\\\?"([a-f0-9]+)/', $form, $m) ? $m[1] : '';
    return $who->fetchPost('/backend/system/backend-user/edit?id=' . $id, $fields + ['entity_csrf' => $entity, 'roles' => $roles, 'identity_name' => [], 'identity_email' => []]);
};
$hostileFields = ['username' => 'max', 'name' => 'Max Muster', 'email' => 'max.muster@example.ch', 'password' => '', 'totp_secret' => '', 'totp_activated_at' => '', 'totp_last_step' => 0, 'totpSecret' => ''];
[, , $answer] = $saveUser($max, 1, $hostileFields);
check('user form: a body cannot switch 2FA off', str_contains($answer, 'success') && $storedUser('max')['totp_secret'] === $stored['totp_secret'] && $storedUser('max')['totp_activated_at'] === $stored['totp_activated_at'] && $storedUser('max')['totp_last_step'] === $stored['totp_last_step'], substr($answer, 0, 200));
check('…and its answer carries no field of it', !str_contains($answer, 'totp'));
[, , $answer] = $saveUser($max, 4, ['username' => 'verwalter', 'email' => '', 'password' => '', 'totp_secret' => 'BOESE', 'totp_activated_at' => '2020-01-01', 'totp_last_step' => 7], ['admin']);
check('user form: a body cannot switch 2FA on for another user', str_contains($answer, 'success') && !($storedUser('verwalter')['totp_secret'] ?? '') && !($storedUser('verwalter')['totp_activated_at'] ?? ''), substr($answer, 0, 200));
[, , $list] = $max->go('/backend/system/backend-user/list');
check('the list carries no secret', !str_contains($list, $stored['totp_secret']) && !str_contains($list, 'totp_'));

// An editor cannot open the user list but reaches his own 2FA.
$redaktor = $browser();
$redaktor->password('redaktor');
check('editor: signed in, with the avatar link', str_contains($redaktor->go($dashboard)[2], 'backend-user/two-factor'));
check('editor: the user list stays closed', $redaktor->status('/backend/system/backend-user/list') !== 200);
[$s, , $ediDialog] = $redaktor->fetchGet('/backend/system/backend-user/two-factor');
$ediSecret = $secretFrom($ediDialog);
check('editor: the dialog opens', $s === 200 && $ediSecret !== '', "status {$s}");
[, , $answer] = $redaktor->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($ediSecret, time())]);
check('editor: switches on his own', str_contains($answer, '"success"') && $storedUser('redaktor')['totp_secret'] !== '', substr($answer, 0, 200));
check('…and nobody else', $storedUser('max')['totp_secret'] === $stored['totp_secret'] && !($storedUser('verwalter')['totp_secret'] ?? ''));
[$s] = $redaktor->fetchGet('/backend/system/backend-user/confirm-two-factor-reset?id=1');
check('editor: the reset is closed', $s !== 200, "status {$s}");

// ── login ───────────────────────────────────────────────────────────────────
echo "  login\n";
$b = $browser();
$b->go('/backend/system/backend-user/list'); // the page he wanted, remembered by the guard
$token    = $b->pageToken();
$idBefore = $b->cookies['PHPSESSID'] ?? '';
[$s, $h] = $b->go('/backend/system/login/login', ['csrf_token' => $token, 'username' => 'max', 'password' => 'falsch-falsch-falsch']);
check('wrong password: as before, no code page', $s === 200);
[$s, $h] = $b->go('/backend/system/login/login', ['csrf_token' => $token, 'username' => 'max', 'password' => 'Passwort-Sehr-Lang-1']);
check('right password, 2FA on: to the code page', in_array($s, [302, 303], true) && ($h['location'] ?? '') === '/backend/system/login/code', "status {$s} " . ($h['location'] ?? ''));
check('the session id changed', ($b->cookies['PHPSESSID'] ?? '') !== $idBefore && ($b->cookies['PHPSESSID'] ?? '') !== '');
$sess = $sessionOf($b);
check('no identity in the session, only the marker', !str_contains($sess, 'auth_user') && str_contains($sess, 'auth.second_factor_pending'), $sess);
check('the marker holds id, time and stamp, no secret', str_contains($sess, 'auth_epoch') && !str_contains($sess, $secret));
check('while pending the dashboard stays refused', in_array($b->status($dashboard), [302, 303], true));
check('while pending every other page too', in_array($b->status('/backend/system/backend-user/list'), [302, 303], true));
[$s, , $answer] = $b->fetchGet('/backend/system/backend-user/two-factor');
check('while pending the own-account dialog too', $s !== 200 || !str_contains($answer, 'tf_'), "status {$s}");
[$s, , $html] = $b->go('/backend/system/login/code');
check('GET code page: 200, the form, the guest layout', $s === 200 && str_contains($html, 'name="code"') && str_contains($html, 'be-guest') && str_contains($html, 'one-time-code'), "status {$s}");

[$s, , $html] = $b->go('/backend/system/login/code', ['code' => Totp::code($secret, time())]);
check('POST without a CSRF token: refused, not signed in', str_contains($html, 'Sitzung abgelaufen') && !$isSignedIn($b));
[$s, , $html] = $b->go('/backend/system/login/code', ['csrf_token' => 'falsch', 'code' => Totp::code($secret, time())]);
check('POST with a wrong CSRF token: refused, no failure counted', str_contains($html, 'Sitzung abgelaufen') && !is_file($guardFile(1)));
[$s, , $html] = $b->code('000000');
check('wrong code: refused, not signed in', $s === 200 && str_contains($html, 'Der Code ist falsch') && !$isSignedIn($b));
check('…counted', is_file($guardFile(1)) && json_decode((string) file_get_contents($guardFile(1)), true)['failures'] === 1);
[$s, , $html] = $b->go('/backend/system/login/code', ['csrf_token' => $b->pageToken('/backend/system/login/code'), 'code' => ['x']]);
check('an array as code: refused, no 500', $s === 200 && str_contains($html, 'Der Code ist falsch'), "status {$s}");

// The guard remembers the last page refused; the checks above all asked for the dashboard.
$b->status('/backend/system/backend-user/list');
$code = $freshCode('max', $secret);
$idBefore = $b->cookies['PHPSESSID'];
[$s, $h] = $b->code($code);
check('right code: redirect to where he wanted to go', in_array($s, [302, 303], true) && str_contains($h['location'] ?? '', '/backend/system/backend-user/list'), "status {$s} " . ($h['location'] ?? ''));
check('the session id changed again', ($b->cookies['PHPSESSID'] ?? '') !== $idBefore);
$sess = $sessionOf($b);
check('the identity is there, the marker gone', str_contains($sess, 'auth_user') && !str_contains($sess, 'auth.second_factor_pending'));
[$s, , $html] = $b->go($dashboard);
check('signed in, with the greeting', $s === 200 && str_contains($html, 'Hallo max, du bist angemeldet'), "status {$s}");
check('a right code clears the failures', !is_file($guardFile(1)));
check('the step is spent', $storedUser('max')['totp_last_step'] === intdiv(time(), 30) || $storedUser('max')['totp_last_step'] === intdiv(time(), 30) - 1);

// Replay: the same code again, in a new session.
$replay = $browser();
$replay->password('max');
[$s, , $html] = $replay->code($code);
check('replay: the accepted code is refused', str_contains($html, 'bereits verwendet') && !$isSignedIn($replay));
check('…and counted', json_decode((string) @file_get_contents($guardFile(1)), true)['failures'] === 1);
[$s, $h] = $replay->code(Totp::code($secret, time() + 30));
check('…the next time step passes', in_array($s, [302, 303], true), "status {$s}");
(new TotpGuard($guardDir))->reset('1');

// The nag follows the same path.
$nag = $browser();
$nag->password('redaktor');
$nag->code($freshCode('redaktor', $ediSecret));
check('the weak-password nag comes after the code too', str_contains($nag->go($dashboard)[2], 'Dein Passwort ist unsicher'));

// The login throttle: the password's attempt stays counted until the code passed.
$thr = $browser();
$token = $thr->pageToken();
for ($i = 0; $i < 4; $i++) {
    $thr->go('/backend/system/login/login', ['csrf_token' => $token, 'username' => 'erna', 'password' => 'falsch-falsch-falsch']);
}
// erna has no 2FA yet: this proves the path without a code clears as before.
[$s, $h] = $thr->go('/backend/system/login/login', ['csrf_token' => $token, 'username' => 'erna', 'password' => 'Passwort-Sehr-Lang-1']);
check('no 2FA: the right password clears the counter at once', in_array($s, [302, 303], true));
$thr->logout();

$thr = $browser();
$token = $thr->pageToken();
for ($i = 0; $i < 4; $i++) {
    $thr->go('/backend/system/login/login', ['csrf_token' => $token, 'username' => 'max', 'password' => 'falsch-falsch-falsch']);
}
[$s, $h] = $thr->go('/backend/system/login/login', ['csrf_token' => $token, 'username' => 'max', 'password' => 'Passwort-Sehr-Lang-1']);
check('2FA: 4 failures + the right password → the code page', ($h['location'] ?? '') === '/backend/system/login/code', "status {$s}");
[, , $html] = $thr->go('/backend/system/login/login', ['csrf_token' => $thr->pageToken(), 'username' => 'max', 'password' => 'Passwort-Sehr-Lang-1']);
check('…without the code the throttle is not cleared: the 6th attempt is refused', str_contains($html, 'Zu viele Fehlversuche'));
$thr->code($freshCode('max', $secret));
check('…the code passes on the same pending login', $isSignedIn($thr));
$thr->logout();
[$s, $h] = $thr->password('max');
check('…and only that cleared the throttle', ($h['location'] ?? '') === '/backend/system/login/code', "status {$s}");
$thr->logout();

// The lock.
$lock = $browser();
$lock->password('max');
for ($i = 0; $i < 4; $i++) {
    [, , $html] = $lock->code('000000');
}
check('4 wrong codes: still «falsch»', str_contains($html, 'Der Code ist falsch'));
[, , $html] = $lock->code('000000');
check('the 5th: locked', str_contains($html, 'Zu viele Fehlversuche'));
[$s, , $html] = $lock->code($freshCode('max', $secret));
check('while locked even the right code is refused', str_contains($html, 'Zu viele Fehlversuche') && !$isSignedIn($lock));
$newTry = $browser();
$newTry->password('max');
[, , $html] = $newTry->code($freshCode('max', $secret));
check('…also from a new session and a new pending login', str_contains($html, 'Zu viele Fehlversuche') && !$isSignedIn($newTry));
$state = json_decode((string) file_get_contents($guardFile(1)), true);
check('the lock lasts 15 minutes', ($state['locked_until'] ?? 0) > time() + 890 && ($state['locked_until'] ?? 0) <= time() + 900, (string) ($state['locked_until'] ?? 'none'));
$state['locked_until'] = time() - 1;
file_put_contents($guardFile(1), json_encode($state));
[$s] = $newTry->code($freshCode('max', $secret));
check('after the window the right code passes', in_array($s, [302, 303], true) && $isSignedIn($newTry), "status {$s}");

// Expiry and absence.
$exp = $browser();
$exp->password('max');
$sessFile = $base . '/sessions/sess_' . $exp->cookies['PHPSESSID'];
$serial = (string) file_get_contents($sessFile);
$aged = preg_replace('/s:2:"at";i:\d+;/', 's:2:"at";i:' . (time() - 301) . ';', $serial, 1, $replaced);
file_put_contents($sessFile, $aged);
[$s, $h] = $exp->code($freshCode('max', $secret));
check('expired after 5 minutes: back to the login, not signed in', $replaced === 1 && in_array($s, [302, 303], true) && ($h['location'] ?? '') === '/login' && !$isSignedIn($exp), "status {$s} " . ($h['location'] ?? ''));
check('…with the neutral message', str_contains($exp->go('/login')[2], 'Die Anmeldung ist abgelaufen'));
check('…the marker is gone', !str_contains($sessionOf($exp), 'auth.second_factor_pending'));
$exp->password('max');
$serial = (string) file_get_contents($base . '/sessions/sess_' . $exp->cookies['PHPSESSID']);
file_put_contents($base . '/sessions/sess_' . $exp->cookies['PHPSESSID'], preg_replace('/s:2:"at";i:\d+;/', 's:2:"at";i:' . (time() - 290) . ';', $serial, 1));
[$s] = $exp->code($freshCode('max', $secret));
check('just inside the 5 minutes it still passes', in_array($s, [302, 303], true) && $isSignedIn($exp), "status {$s}");
$none = $browser();
[$s, $h] = $none->go('/backend/system/login/code');
check('no marker, GET: back to the login', in_array($s, [302, 303], true) && ($h['location'] ?? '') === '/login');
[$s, $h] = $none->go('/backend/system/login/code', ['csrf_token' => $none->pageToken(), 'code' => '123456']);
check('no marker, POST: back to the login', in_array($s, [302, 303], true) && ($h['location'] ?? '') === '/login');

// The user changed while pending.
$ep = $browser();
$ep->password('max');
$epochBefore = $storedUser('max')['auth_epoch'] ?? 0;
$editUser('max', ['auth_epoch' => $epochBefore + 1]);
[$s, $h] = $ep->code($freshCode('max', $secret));
check('epoch raised while pending: back to the login, not signed in, right code or not', ($h['location'] ?? '') === '/login' && !$isSignedIn($ep), "status {$s} " . ($h['location'] ?? ''));
$ep = $browser();
$ep->password('max');
$savedMax = $storedUser('max');
$rows = array_values(array_filter(json_decode((string) file_get_contents($usersFile), true), static fn(array $r): bool => $r['username'] !== 'max'));
file_put_contents($usersFile, json_encode($rows, JSON_PRETTY_PRINT));
[$s, $h] = $ep->code(Totp::code($secret, time()));
check('user deleted while pending: back to the login', ($h['location'] ?? '') === '/login', "status {$s}");
$rows[] = $savedMax;
usort($rows, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
file_put_contents($usersFile, json_encode($rows, JSON_PRETTY_PRINT));
$ep = $browser();
$ep->password('max');
$editUser('max', ['totp_secret' => '', 'totp_activated_at' => '', 'totp_last_step' => 0]);
[$s, $h] = $ep->code('000000');
check('2FA gone while pending: back to the login, no password-less pass', ($h['location'] ?? '') === '/login' && !$isSignedIn($ep), "status {$s}");
$editUser('max', ['totp_secret' => $savedMax['totp_secret'], 'totp_activated_at' => $savedMax['totp_activated_at'], 'totp_last_step' => 0]);

// A pending login signs out whoever was in this browser.
$sw = $browser();
$sw->password('verwalter');
check('switching user: verwalter is in', $isSignedIn($sw));
[$s, $h] = $sw->password('max');
check('…right password of a 2FA account: to the code page', ($h['location'] ?? '') === '/backend/system/login/code');
check('…verwalter is out, max is not in', !$isSignedIn($sw) && !str_contains($sessionOf($sw), 'auth_user'));

// ── the reset form ──────────────────────────────────────────────────────────
echo "  reset form\n";
$linkFor = static function (string $username) use ($browser, $outbox, $decoded): string {
    $mailsBefore = $outbox();
    $who = $browser();
    $who->go('/backend/system/login/forgot', ['csrf_token' => $who->pageToken('/backend/system/login/forgot'), 'identifier' => $username]);
    $new = array_diff($outbox(), $mailsBefore);
    preg_match('#reset\?token=([a-f0-9]{64})#', $decoded(current($new) ?: ''), $m);
    return $m[1] ?? '';
};
// Without 2FA: no field. erna has an address and no 2FA.
$token = $linkFor('erna');
$r = $browser();
[, , $html] = $r->go('/backend/system/login/reset?token=' . $token);
check('reset form, no 2FA: no code field', $token !== '' && str_contains($html, 'name="password_repeat"') && !str_contains($html, 'name="code"'));
[$s, $h] = $r->go('/backend/system/login/reset', ['csrf_token' => $r->pageToken('/backend/system/login/reset?token=' . $token), 'token' => $token, 'password' => 'Neues-Passwort-Lang-2', 'password_repeat' => 'Neues-Passwort-Lang-2']);
check('…the password changes as before', ($h['location'] ?? '') === '/login' && password_verify('Neues-Passwort-Lang-2', $storedUser('erna')['password_hash']), "status {$s}");

// With 2FA: redaktor has an address and 2FA.
$ediRow   = $storedUser('redaktor');
$ediToken = $linkFor('redaktor');
$r = $browser();
$resetPath = '/backend/system/login/reset?token=' . $ediToken;
[, , $html] = $r->go($resetPath);
check('reset form, 2FA: the code field', $ediToken !== '' && str_contains($html, 'name="code"') && str_contains($html, 'one-time-code'));
$csrfR = $r->pageToken($resetPath);
$resetPost = static fn(string $code): array => $r->go('/backend/system/login/reset', ['csrf_token' => $csrfR, 'token' => $ediToken, 'password' => 'Neues-Passwort-Lang-3', 'password_repeat' => 'Neues-Passwort-Lang-3', 'code' => $code]);
[$s, , $html] = $resetPost('000000');
check('wrong code: refused, the form again', $s === 200 && str_contains($html, 'Der Code ist falsch') && str_contains($html, 'name="password_repeat"'));
$now = $storedUser('redaktor');
check('…nothing changed', $now['password_hash'] === $ediRow['password_hash'] && $now['auth_epoch'] === $ediRow['auth_epoch'] && $now['totp_secret'] === $ediRow['totp_secret']);
check('…the link stays usable', str_contains($r->go($resetPath)[2], 'name="password_repeat"'));
check('…it counts towards the lock', json_decode((string) file_get_contents($guardFile(3)), true)['failures'] === 1);
[$s, , $html] = $r->go('/backend/system/login/reset', ['csrf_token' => $csrfR, 'token' => $ediToken, 'password' => 'Neues-Passwort-Lang-3', 'password_repeat' => 'Neues-Passwort-Lang-3']);
check('no code at all: refused the same way', str_contains($html, 'Der Code ist falsch') && $storedUser('redaktor')['password_hash'] === $ediRow['password_hash']);
[$s, , $html] = $r->go('/backend/system/login/reset', ['csrf_token' => $csrfR, 'token' => $ediToken, 'password' => 'kurz', 'password_repeat' => 'kurz', 'code' => '000000']);
check('a bad password is judged first: no failure counted for it', str_contains($html, 'mindestens 8 Zeichen') && json_decode((string) file_get_contents($guardFile(3)), true)['failures'] === 2);

// A session of the user and the login lock through the reset form.
$ediSession = $browser();
$ediSession->password('redaktor');
$ediSession->code($freshCode('redaktor', $ediSecret));
check('a session of that user', $isSignedIn($ediSession));
// The right code above cleared the two failures: five from zero.
for ($i = 0; $i < 4; $i++) {
    $resetPost('000000');
}
[, , $html] = $resetPost('000000');
check('the 5th failure through the reset form locks', str_contains($html, 'Zu viele Fehlversuche'));
[, , $html] = $resetPost($freshCode('redaktor', $ediSecret));
check('…while locked the right code is refused here too', str_contains($html, 'Zu viele Fehlversuche') && $storedUser('redaktor')['password_hash'] === $ediRow['password_hash']);
$lockedLogin = $browser();
$lockedLogin->password('redaktor');
[, , $html] = $lockedLogin->code($freshCode('redaktor', $ediSecret));
check('…and the login shares the lock', str_contains($html, 'Zu viele Fehlversuche'));
@unlink($guardFile(3));

$mailsBefore = $outbox();
$code = $freshCode('redaktor', $ediSecret);
[$s, $h] = $resetPost($code);
check('right code: the password changes, redirect to the login', ($h['location'] ?? '') === '/login' && password_verify('Neues-Passwort-Lang-3', $storedUser('redaktor')['password_hash']), "status {$s} " . ($h['location'] ?? ''));
$now = $storedUser('redaktor');
check('a reset does not switch 2FA off', $now['totp_secret'] === $ediRow['totp_secret'] && $now['totp_activated_at'] === $ediRow['totp_activated_at']);
check('…the code is spent', $now['totp_last_step'] > 0);
check('…it does not log in', !$isSignedIn($r));
check('…the stamp is raised, his other session ends', $now['auth_epoch'] === $ediRow['auth_epoch'] + 1 && !$isSignedIn($ediSession));
check('…the change notice goes out', count(array_diff($outbox(), $mailsBefore)) === 1);
check('…the link is used up', str_contains($r->go($resetPath)[2], 'Link nicht gültig'));
$after = $browser();
[$s, $h] = $after->password('redaktor', 'Neues-Passwort-Lang-3');
check('the new password still asks for the code', ($h['location'] ?? '') === '/backend/system/login/code' && !$isSignedIn($after), "status {$s}");
[$s] = $after->code($code);
check('…a replay of the reset code is refused', !$isSignedIn($after));
[$s] = $after->code($freshCode('redaktor', $ediSecret));
check('…a fresh code passes', $isSignedIn($after));
(new TotpGuard($guardDir))->reset('3');

// ── the admin reset ─────────────────────────────────────────────────────────
echo "  admin reset\n";
$super = $browser();
$super->password('max');
$super->code($freshCode('max', $secret));
check('the super-user is in', $isSignedIn($super));
$admin = $browser();
$admin->password('verwalter');
check('the admin is in', $isSignedIn($admin));

[, , $menu] = $super->fetchGet('/backend/system/backend-user/actions?id=3');
check('menu: super-user, target with 2FA: the item', str_contains($menu, '2FA zurücksetzen') && str_contains($menu, 'confirm-two-factor-reset?id=3'));
[, , $menu] = $super->fetchGet('/backend/system/backend-user/actions?id=1');
check('menu: own row: no item', !str_contains($menu, '2FA zurücksetzen'));
[, , $menu] = $super->fetchGet('/backend/system/backend-user/actions?id=4');
check('menu: target without 2FA: no item', !str_contains($menu, '2FA zurücksetzen'));
[, , $menu] = $admin->fetchGet('/backend/system/backend-user/actions?id=3');
check('menu: admin (not super-user): no item', !str_contains($menu, '2FA zurücksetzen') && str_contains($menu, 'Bearbeiten'));

$target = $storedUser('redaktor');
$tokenFromConfirm = static function (Browser $who, int $id): string {
    [, , $html] = $who->fetchGet('/backend/system/backend-user/confirm-two-factor-reset?id=' . $id);
    return preg_match('/name=\\\\?"entity_csrf\\\\?"\s+value=\\\\?"([a-f0-9]+)/', $html, $m) ? $m[1] : '';
};
// What an attacker in his own session can compute: the entity token is an HMAC over his CSRF token,
// so the rule behind it must hold on its own.
$forgedToken = static fn(Browser $who, int $id): string => hash_hmac('sha256', 'backendUserTotpReset:' . $id, $who->pageToken('/backend/system/dashboard/overview'));
$goodToken = $tokenFromConfirm($super, 3);
check('confirm dialog: names the user, carries the entity token', $goodToken !== '' && str_contains($super->fetchGet('/backend/system/backend-user/confirm-two-factor-reset?id=3')[2], 'redaktor'));

// Refusal 1: not a super-user, even with the token of his own session and a forged menu.
[$s, $h, $answer] = $admin->fetchPost('/backend/system/backend-user/two-factor-reset', ['id' => 3, 'entity_csrf' => $forgedToken($admin, 3)]);
check('refused: an admin is not a super-user', $s !== 200 && $storedUser('redaktor') === $target, "status {$s} " . substr($answer, 0, 150));
[$s] = $admin->fetchGet('/backend/system/backend-user/confirm-two-factor-reset?id=3');
check('…his confirm dialog is closed too', $s !== 200, "status {$s}");
// Refusal 2: the own account.
$self = $storedUser('max');
[$s, , $answer] = $super->fetchPost('/backend/system/backend-user/two-factor-reset', ['id' => 1, 'entity_csrf' => $forgedToken($super, 1)]);
check('refused: his own account', str_contains($answer, 'eigenes Konto') && str_contains($answer, '"error"') && $storedUser('max') === $self, substr($answer, 0, 200));
check('…the dialog says why', str_contains($super->fetchGet('/backend/system/backend-user/confirm-two-factor-reset?id=1')[2], 'eigenes Konto'));
// Refusal 3: a target without 2FA.
$noTwoFactor = $storedUser('verwalter');
[$s, , $answer] = $super->fetchPost('/backend/system/backend-user/two-factor-reset', ['id' => 4, 'entity_csrf' => $forgedToken($super, 4)]);
check('refused: a target without 2FA', str_contains($answer, 'kein Zwei-Faktor-Schutz') && $storedUser('verwalter') === $noTwoFactor, substr($answer, 0, 200));
// The token.
[$s, , $answer] = $super->fetchPost('/backend/system/backend-user/two-factor-reset', ['id' => 3, 'entity_csrf' => 'falsch']);
check('refused: a wrong entity token', str_contains($answer, 'Invalid token') && $storedUser('redaktor') === $target);
[$s, , $answer] = $super->fetchPost('/backend/system/backend-user/two-factor-reset', ['id' => 3]);
check('refused: no entity token', str_contains($answer, 'Invalid token') && $storedUser('redaktor') === $target);
[$s, , $answer] = $super->fetchPost('/backend/system/backend-user/two-factor-reset', ['id' => 3, 'entity_csrf' => $forgedToken($super, 4)]);
check('refused: the token of another row', str_contains($answer, 'Invalid token') && $storedUser('redaktor') === $target);
[, , $del] = $super->fetchGet('/backend/system/backend-user/confirm-delete?id=3');
$delToken = preg_match('/name=\\\\?"entity_csrf\\\\?"\s+value=\\\\?"([a-f0-9]+)/', $del, $m) ? $m[1] : '';
[$s, , $answer] = $super->fetchPost('/backend/system/backend-user/two-factor-reset', ['id' => 3, 'entity_csrf' => $delToken]);
check('refused: the delete token of the same row', $delToken !== '' && str_contains($answer, 'Invalid token') && $storedUser('redaktor') === $target);
[$s] = $super->go('/backend/system/backend-user/two-factor-reset', ['id' => 3, 'entity_csrf' => $goodToken]);
check('refused: a page POST', $s === 404 && $storedUser('redaktor') === $target, "status {$s}");
[$s] = $super->go('/backend/system/backend-user/two-factor-reset?id=3&entity_csrf=' . $goodToken);
check('refused: a GET', $s === 404 && $storedUser('redaktor') === $target, "status {$s}");

// The effects. Edi: a live session, a failure on the guard, an open reset link.
$ediSession = $browser();
$ediSession->password('redaktor', 'Neues-Passwort-Lang-3');
$ediSession->code($freshCode('redaktor', $ediSecret));
check('redaktor has a live session', $isSignedIn($ediSession));
$ediPending = $browser();
$ediPending->password('redaktor', 'Neues-Passwort-Lang-3');
$ediPending->code('000000');
check('…and a failure on the guard', is_file($guardFile(3)));
$openLink = $linkFor('redaktor');
check('…and an open reset link', $openLink !== '');
$target = $storedUser('redaktor');
$mailsBefore = $outbox();
[$s, , $answer] = $super->fetchPost('/backend/system/backend-user/two-factor-reset', ['id' => 3, 'entity_csrf' => $goodToken]);
check('the reset: success, the dialog closes', str_contains($answer, '"success"') && str_contains($answer, 'close-modal'), substr($answer, 0, 200));
$now = $storedUser('redaktor');
check('secret, activation and last step are cleared', $now['totp_secret'] === '' && $now['totp_activated_at'] === '' && $now['totp_last_step'] === 0);
check('the guard is reset', !is_file($guardFile(3)));
check('the stamp is raised', $now['auth_epoch'] === $target['auth_epoch'] + 1);
check('the password stays', $now['password_hash'] === $target['password_hash']);
check('his old session is dead', !$isSignedIn($ediSession));
[$s, $h] = $ediPending->code('123456');
check('his pending login is dead', ($h['location'] ?? '') === '/login', "status {$s}");
check('the super-user stays', $isSignedIn($super));
$newMails = array_values(array_diff($outbox(), $mailsBefore));
$mail = $decoded($newMails[0] ?? '');
preg_match('/^Subject: (.+)$/m', (string) @file_get_contents($newMails[0] ?? ''), $subject);
check('one mail, to the recovery address', count($newMails) === 1 && str_contains($mail, 'redaktor@example.ch'), (string) count($newMails));
check('…subject «2FA wurde zurückgesetzt»', iconv_mime_decode(trim($subject[1] ?? ''), 0, 'UTF-8') === '2FA wurde zurückgesetzt', trim($subject[1] ?? ''));
check('…without a link', !str_contains($mail, 'href=') && !str_contains($mail, 'http://') && !str_contains($mail, 'token='));
check('…with whom to contact', str_contains($mail, 'Administrator'));
check('a log line names target and actor', str_contains($serverLog(), '2FA of user id 3 reset by user id 1'));
check('the log holds no secret', !str_contains($serverLog(), $ediSecret));
check('the open reset link is dead', str_contains($browser()->go('/backend/system/login/reset?token=' . $openLink)[2], 'Link nicht gültig'));
$pw = $browser();
[$s, $h] = $pw->password('redaktor', 'Neues-Passwort-Lang-3');
check('the password alone logs in again', in_array($s, [302, 303], true) && ($h['location'] ?? '') !== '/backend/system/login/code' && $isSignedIn($pw), "status {$s} " . ($h['location'] ?? ''));
[$s, , $answer] = $super->fetchPost('/backend/system/backend-user/two-factor-reset', ['id' => 3, 'entity_csrf' => $goodToken]);
check('a second reset is refused: he has no 2FA now', str_contains($answer, 'kein Zwei-Faktor-Schutz'), substr($answer, 0, 200));
[, , $menu] = $super->fetchGet('/backend/system/backend-user/actions?id=3');
check('…and the menu no longer offers it', !str_contains($menu, '2FA zurücksetzen'));
[, , $reDialog] = $pw->fetchGet('/backend/system/backend-user/two-factor');
$reSecret = $secretFrom($reDialog);
check('he can set up anew, with a new secret', $reSecret !== '' && $reSecret !== $ediSecret);

// The last admin who lost his device: the hand procedure of login.md, word for word.
$pw->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Neues-Passwort-Lang-3', 'tf_code' => Totp::code($reSecret, time())]);
check('redaktor has 2FA again', $storedUser('redaktor')['totp_secret'] !== '');
$handLocked = $browser();
$handLocked->password('redaktor', 'Neues-Passwort-Lang-3');
$handLocked->code('000000');
check('…and a failure on the guard', is_file($guardFile(3)));
$handFile = $usersFile;
$r = json_decode(file_get_contents($handFile), true);
foreach ($r as &$u) {
    if ($u['username'] === 'redaktor') {
        unset($u['totp_secret'], $u['totp_activated_at'], $u['totp_last_step']);
        $u['auth_epoch'] = ($u['auth_epoch'] ?? 0) + 1;
    }
}
unset($u);
file_put_contents($handFile, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
@unlink($guardFile(3));
check('hand reset: his old session is dead', !$isSignedIn($pw));
$hand = $browser();
[$s, $h] = $hand->password('redaktor', 'Neues-Passwort-Lang-3');
check('hand reset: the password alone logs in', in_array($s, [302, 303], true) && ($h['location'] ?? '') !== '/backend/system/login/code' && $isSignedIn($hand), "status {$s}");
$saveUser($super, 3, ['username' => 'redaktor', 'email' => 'redaktor@example.ch', 'password' => ''], ['editor']);
check('hand reset: the next save writes the keys again, empty', ($storedUser('redaktor')['totp_secret'] ?? null) === '' && ($storedUser('redaktor')['totp_last_step'] ?? null) === 0);

// Without a recovery address: no mail, no failure.
$verwalter = $browser();
$verwalter->password('verwalter');
[, , $lenaDialog] = $verwalter->fetchGet('/backend/system/backend-user/two-factor');
$lenaSecret = $secretFrom($lenaDialog);
$verwalter->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($lenaSecret, time())]);
check('verwalter (no address) has 2FA', $storedUser('verwalter')['totp_secret'] !== '');
$mailsBefore = $outbox();
[, , $answer] = $super->fetchPost('/backend/system/backend-user/two-factor-reset', ['id' => 4, 'entity_csrf' => $tokenFromConfirm($super, 4)]);
check('a target without an address: reset, no mail', str_contains($answer, '"success"') && $storedUser('verwalter')['totp_secret'] === '' && array_diff($outbox(), $mailsBefore) === [], substr($answer, 0, 200));

// ── switching off ───────────────────────────────────────────────────────────
echo "  switching off\n";
$off = $browser();
$off->password('max');
$off->code($freshCode('max', $secret));
check('max is in, with 2FA', $isSignedIn($off) && $storedUser('max')['totp_secret'] !== '');
[$s, , $answer] = $off->fetchPost('/backend/system/backend-user/two-factor-disable', ['tf_code' => '000000', 'id' => 2]);
check('a wrong code: refused on the field, stays on', str_contains($answer, '"tf_code"') && $storedUser('max')['totp_secret'] !== '', substr($answer, 0, 200));
check('…counted', json_decode((string) file_get_contents($guardFile(1)), true)['failures'] === 1);
[$s, , $answer] = $off->fetchPost('/backend/system/backend-user/two-factor-disable', ['tf_code' => ['x']]);
check('an array as code: refused, no 500', $s === 200 && str_contains($answer, '"tf_code"'), "status {$s}");
[$s] = $off->go('/backend/system/backend-user/two-factor-disable', ['tf_code' => '000000']);
check('a page POST: refused', $s === 404, "status {$s}");
[$s, , $answer] = $off->fetchPost('/backend/system/backend-user/two-factor-disable', ['tf_code' => $storedUser('max')['totp_last_step'] ? Totp::code($secret, $storedUser('max')['totp_last_step'] * 30) : '']);
check('the code just used for the login: refused, a spent step', str_contains($answer, 'bereits verwendet') && $storedUser('max')['totp_secret'] !== '', substr($answer, 0, 200));
(new TotpGuard($guardDir))->reset('1');
$ernaBefore = $storedUser('erna');
[$s, , $answer] = $off->fetchPost('/backend/system/backend-user/two-factor-disable', ['tf_code' => $freshCode('max', $secret), 'id' => 2]);
check('the right code: off', str_contains($answer, '"success"') && $storedUser('max')['totp_secret'] === '' && $storedUser('max')['totp_activated_at'] === '' && $storedUser('max')['totp_last_step'] === 0, substr($answer, 0, 200));
check('…an id in the body changed nobody else', $storedUser('erna') === $ernaBefore);
check('…the session stays', $isSignedIn($off));
$pw = $browser();
[$s, $h] = $pw->password('max');
check('…the password alone logs in again', in_array($s, [302, 303], true) && ($h['location'] ?? '') !== '/backend/system/login/code' && $isSignedIn($pw), "status {$s}");
[$s, , $answer] = $off->fetchPost('/backend/system/backend-user/two-factor-disable', ['tf_code' => '123456']);
check('switching off what is off: refused', str_contains($answer, 'nicht aktiv'), substr($answer, 0, 200));

$guest = $browser();
[$s] = $guest->fetchGet('/backend/system/backend-user/two-factor');
check('a guest gets no dialog', $s !== 200, "status {$s}");

// ── the guest pages' scripts (reported as `_Z77 is not defined`) ────────────
echo "  script order\n";
$coreFirst = static function (string $html, string $script): bool {
    $core = strpos($html, '/js/core');
    $own  = strpos($html, '/js/' . $script);
    return $core !== false && $own !== false && $core < $own;
};
$js = $browser();
check('/login: core.js loads before password-toggle', $coreFirst($js->go('/login')[2], 'password-toggle'));
$linkForJs = static function (string $username) use ($browser, $outbox, $decoded): string {
    $mailsBefore = $outbox();
    $who = $browser();
    $who->go('/backend/system/login/forgot', ['csrf_token' => $who->pageToken('/backend/system/login/forgot'), 'identifier' => $username]);
    preg_match('#reset\?token=([a-f0-9]{64})#', $decoded(current(array_diff($outbox(), $mailsBefore)) ?: ''), $m);
    return $m[1] ?? '';
};
$jsReset = $js->go('/backend/system/login/reset?token=' . $linkForJs('kollegin'))[2];
check('reset form: core.js loads before password-toggle and password-meter', $coreFirst($jsReset, 'password-toggle') && $coreFirst($jsReset, 'password-meter'));

// ── roles: nobody grants or edits above his own level ───────────────────────
echo "  roles\n";
$sup = $browser();
$sup->password('zweit');
$adm = $browser();
$adm->password('verwalter');
check('a super-user and an admin are in', $isSignedIn($sup) && $isSignedIn($adm));
$forgedEdit = static fn(Browser $who, int $id): string => hash_hmac('sha256', 'backendUser:' . $id, $who->pageToken('/backend/system/dashboard/overview'));
$saveForged = static function (Browser $who, int $id, array $fields, array $roles) use ($forgedEdit): array {
    return $who->fetchPost('/backend/system/backend-user/edit?id=' . $id, $fields + ['entity_csrf' => $forgedEdit($who, $id), 'roles' => $roles, 'identity_name' => [], 'identity_email' => []]);
};

[, , $form] = $adm->fetchGet('/backend/system/backend-user/edit?id=5');
check('the admin\'s form offers no role above his own', str_contains($form, 'value="admin"') && !str_contains($form, 'value="superUser"'), substr($form, 0, 120));
[, , $form] = $sup->fetchGet('/backend/system/backend-user/edit?id=5');
check('the super-user\'s form offers all', str_contains($form, 'value="superUser"'));
$before = $storedUser('verwalter');
[, , $answer] = $saveForged($adm, 4, ['username' => 'verwalter', 'email' => '', 'password' => ''], ['superUser']);
check('self-promotion: refused', str_contains($answer, 'nicht vergeben') && $storedUser('verwalter')['roles'] === ['admin'], substr($answer, 0, 160));
check('…nothing changed', $storedUser('verwalter') === $before);
$before = $storedUser('muster1');
[, , $answer] = $saveForged($adm, 5, ['username' => 'muster1', 'email' => 'muster1@example.ch', 'password' => ''], ['superUser']);
check('granting above his level to someone else: refused', str_contains($answer, 'nicht vergeben') && $storedUser('muster1') === $before, substr($answer, 0, 160));
[, , $answer] = $adm->fetchPost('/backend/system/backend-user/add', ['username' => 'neuer', 'password' => 'Passwort-Sehr-Lang-1', 'roles' => ['superUser'], 'identity_name' => [], 'identity_email' => []]);
check('a new user above his level: refused, not created', str_contains($answer, 'nicht vergeben') && !array_key_exists('neuer', array_column(json_decode((string) file_get_contents($usersFile), true), null, 'username')), substr($answer, 0, 160));

[, , $menu] = $adm->fetchGet('/backend/system/backend-user/actions?id=9');
check('menu on an account above: no edit, no delete, says why', !str_contains($menu, 'Bearbeiten') && !str_contains($menu, 'confirm-delete') && str_contains($menu, 'höhere Rolle'));
[, , $menu] = $adm->fetchGet('/backend/system/backend-user/actions?id=5');
check('menu on an account below: edit and delete', str_contains($menu, 'Bearbeiten') && str_contains($menu, 'confirm-delete'));
[$s, , $answer] = $adm->fetchGet('/backend/system/backend-user/edit?id=9');
check('edit form of an account above: refused', str_contains($answer, 'höhere Rolle') && !str_contains($answer, 'name="username"'), substr($answer, 0, 160));
$before = $storedUser('zweit');
[, , $answer] = $saveForged($adm, 9, ['username' => 'zweit', 'email' => 'attacker@example.ch', 'password' => 'Uebernommen-Passwort-9'], ['superUser']);
check('saving an account above with a forged token: refused, password and address stay', str_contains($answer, 'höhere Rolle') && $storedUser('zweit') === $before, substr($answer, 0, 160));
[, , $answer] = $adm->fetchGet('/backend/system/backend-user/confirm-delete?id=9');
check('delete dialog of an account above: says why', str_contains($answer, 'höhere Rolle') && !str_contains($answer, 'backend-user/remove'));
[, , $answer] = $adm->fetchPost('/backend/system/backend-user/remove', ['id' => 9, 'entity_csrf' => $forgedEdit($adm, 9)]);
check('deleting an account above with a forged token: refused', str_contains($answer, 'höhere Rolle') && $storedUser('zweit') === $before, substr($answer, 0, 160));

[, , $answer] = $saveForged($adm, 10, ['username' => 'kollegin', 'email' => 'kollegin@example.ch', 'name' => 'Max Muster', 'password' => ''], ['admin']);
check('the same level stays editable', str_contains($answer, '"success"') && $storedUser('kollegin')['name'] === 'Max Muster', substr($answer, 0, 160));

// A role change ends the sessions, the store decides even a session that slipped through (finding A).
$dem = $browser();
$dem->password('alt');
check('alt (super-user) is in', $isSignedIn($dem));
$demToken = $dem->pageToken($dashboard);
[, , $answer] = $saveUser($sup, 11, ['username' => 'alt', 'email' => 'alt@example.ch', 'password' => ''], ['editor']);
check('demoted by another super-user: saved, stamp raised', str_contains($answer, '"success"') && $storedUser('alt')['roles'] === ['editor'] && $storedUser('alt')['auth_epoch'] === 1, substr($answer, 0, 160));
check('…his session has ended', !$isSignedIn($dem));
[$s, , $answer] = $dem->fetchPost('/backend/system/backend-user/two-factor-reset', ['id' => 3, 'entity_csrf' => 'x'], $demToken);
check('…the reset is closed to it', !str_contains($answer, '"success"'));
// The session of a demoted user with the old stamp put back by hand: only the store's roles count.
$redemote = $browser();
$redemote->password('alt');
check('alt signs in again, as an editor', $isSignedIn($redemote));
$forgedSession = (string) file_get_contents($base . '/sessions/sess_' . $redemote->cookies['PHPSESSID']);
$forgedSession = (string) preg_replace('/s:6:"editor"/', 's:9:"superUser"', $forgedSession, 1, $replacedRole);
file_put_contents($base . '/sessions/sess_' . $redemote->cookies['PHPSESSID'], $forgedSession);
$tkn = $redemote->pageToken($dashboard);
[$s, , $answer] = $redemote->fetchPost('/backend/system/backend-user/two-factor-reset', ['id' => 3, 'entity_csrf' => hash_hmac('sha256', 'backendUserTotpReset:3', $tkn)], $tkn);
check('a session still claiming super-user: the rule reads the store and refuses', $replacedRole === 1 && str_contains($answer, 'nur ein Super-User'), substr($answer, 0, 160));

// The last super-user. max and zweit are the two left.
$own = $browser();
$own->password('zweit');
$ownOther = $browser();
$ownOther->password('zweit');
[, , $answer] = $saveUser($own, 9, ['username' => 'zweit', 'email' => 'zweit@example.ch', 'password' => ''], ['admin']);
check('own demotion while another super-user exists: saved', str_contains($answer, '"success"') && $storedUser('zweit')['roles'] === ['admin'], substr($answer, 0, 160));
check('…this session stays, with the new role', $isSignedIn($own) && $own->status('/backend/service/backup/list') !== 200);
check('…his other session ended', !$isSignedIn($ownOther));
$supMax = $browser();
$supMax->password('max');
$supMax->code($freshCode('max', $secret));
if (!$isSignedIn($supMax)) {
    // max switched 2FA off in the section above: the password alone.
    $supMax = $browser();
    $supMax->password('max');
}
check('max is in, the last super-user', $isSignedIn($supMax));
$before = $storedUser('max');
[, , $answer] = $saveUser($supMax, 1, ['username' => 'max', 'email' => 'max.muster@example.ch', 'password' => ''], ['admin']);
check('the last super-user cannot demote himself', str_contains($answer, 'mindestens ein Super-User') && $storedUser('max') === $before, substr($answer, 0, 160));
[, , $answer] = $supMax->fetchPost('/backend/system/backend-user/remove', ['id' => 1, 'entity_csrf' => $forgedEdit($supMax, 1)]);
check('…nor delete himself', str_contains($answer, 'eigenes Konto') && $storedUser('max') === $before, substr($answer, 0, 160));
$before = $storedUser('zweit');
[, , $answer] = $saveForged($own, 1, ['username' => 'max', 'email' => 'max.muster@example.ch', 'password' => ''], ['admin']);
check('…and an admin cannot reach him', str_contains($answer, 'höhere Rolle') && $storedUser('max')['roles'] === ['superUser'], substr($answer, 0, 160));
[, , $answer] = $saveUser($supMax, 9, ['username' => 'zweit', 'email' => 'zweit@example.ch', 'password' => ''], ['superUser']);
check('a super-user grants the role back', str_contains($answer, '"success"') && $storedUser('zweit')['roles'] === ['superUser'], substr($answer, 0, 160));

// ── URL casing (the gate reads the declared method name) ────────────────────
echo "  URL casing\n";
$v = $browser();
$v->password('verwalter');
$vToken = $v->pageToken($dashboard);
foreach (['two-factor-reset', 'twoFactorReset', 'twofactorreset', 'TWOFACTORRESET', 'two-factor-RESET'] as $spelling) {
    [$s, $h, $answer] = $v->fetchPost('/backend/system/backend-user/' . $spelling, ['id' => 3, 'entity_csrf' => 'x'], $vToken);
    check("admin POST {$spelling}: stopped at the gate, the action is not reached", in_array($s, [302, 303], true) && !str_contains($answer, 'Invalid token') && !str_contains($answer, 'Super-User'), "status {$s} " . substr($answer, 0, 100));
}
foreach (['confirm-two-factor-reset', 'confirmTwoFactorReset', 'confirmtwofactorreset'] as $spelling) {
    [$s] = $v->fetchGet('/backend/system/backend-user/' . $spelling . '?id=3');
    check("admin GET {$spelling}: stopped at the gate", in_array($s, [302, 303], true), "status {$s}");
}
$ed = $browser();
$ed->password('muster3');
foreach (['confirm-delete', 'confirmDelete', 'confirmdelete'] as $spelling) {
    [$s] = $ed->fetchGet('/backend/content/content/' . $spelling . '?id=1');
    check("editor GET content/{$spelling} (config: ADMIN): stopped", in_array($s, [302, 303], true), "status {$s}");
}
foreach (['backup', 'Backup', 'BACKUP', 'back-up', 'Back-Up'] as $spelling) {
    [$s] = $v->go('/backend/service/' . $spelling . '/list');
    check("admin service/{$spelling}/list (config: SUPER_USER): not served", $s !== 200, "status {$s}");
}
[$s] = $supMax->go('/backend/service/backup/list');
check('the super-user still reaches it', $s === 200, "status {$s}");
[$s] = $supMax->fetchGet('/backend/system/backend-user/confirmTwoFactorReset?id=3');
check('…another casing of a route still works for who may use it', $s === 200, "status {$s}");

// ── what a thief sees of an enrolment ───────────────────────────────────────
echo "  enrolment and a stolen cookie\n";
$owner = $browser();
$owner->password('muster1');
$thief = $browser();
$thief->cookies = $owner->cookies;
[, , $d] = $thief->fetchGet('/backend/system/backend-user/two-factor');
$thiefSecret = $secretFrom($d);
check('a thief with the cookie can open the dialog', $thiefSecret !== '');
[, , $d] = $owner->fetchGet('/backend/system/backend-user/two-factor');
$ownerSecret = $secretFrom($d);
check('the owner\'s open makes another secret', $ownerSecret !== '' && $ownerSecret !== $thiefSecret);
[, , $answer] = $owner->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($thiefSecret, time())]);
check('the secret the thief read no longer confirms', str_contains($answer, '"tf_code"') && !($storedUser('muster1')['totp_secret'] ?? ''), substr($answer, 0, 160));
// What remains: the thief opens AFTER the owner. He replaces the secret, he does not learn the owner's.
[, , $d] = $thief->fetchGet('/backend/system/backend-user/two-factor');
$lateSecret = $secretFrom($d);
[, , $answer] = $owner->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($ownerSecret, time())]);
check('a thief opening after the owner: the owner\'s code is refused, stays off', str_contains($answer, 'zu alt') && !($storedUser('muster1')['totp_secret'] ?? ''), substr($answer, 0, 160));
[, , $answer] = $thief->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'falsch-falsch-falsch', 'tf_code' => Totp::code($lateSecret, time())]);
check('…and without the password the thief cannot switch on', !($storedUser('muster1')['totp_secret'] ?? ''), substr($answer, 0, 160));
[, , $d] = $owner->fetchGet('/backend/system/backend-user/two-factor');
$ownerSecret = $secretFrom($d);

// The TTL.
$sessPath = $base . '/sessions/sess_' . $owner->cookies['PHPSESSID'];
$serial = (string) file_get_contents($sessPath);
$aged = preg_replace('/(auth\.totp_enrolment\|a:3:\{.*?s:2:"at";i:)\d+;/s', '${1}' . (time() - 601) . ';', $serial, 1, $replaced);
file_put_contents($sessPath, $aged);
[, , $answer] = $owner->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($ownerSecret, time())]);
check('after 10 minutes the dialog is too old', $replaced === 1 && str_contains($answer, 'zu alt') && !($storedUser('muster1')['totp_secret'] ?? ''), substr($answer, 0, 160));

// Logout and login clear it.
[, , $d] = $owner->fetchGet('/backend/system/backend-user/two-factor');
check('a running enrolment is in the session', str_contains($sessionOf($owner), 'auth.totp_enrolment'));
$owner->logout();
check('logout clears it', !str_contains($sessionOf($owner), 'auth.totp_enrolment'));
[, , $d] = $thief->fetchGet('/backend/system/backend-user/two-factor');
$owner->password('muster1');
[, , $d] = $owner->fetchGet('/backend/system/backend-user/two-factor');
$owner2 = $browser();
$owner2->cookies = $owner->cookies;
$owner2->password('muster1');
check('login clears it', !str_contains($sessionOf($owner2), 'auth.totp_enrolment'));

// The same cookie, copied, is one of the sessions that end.
$s5 = $browser();
$s5->password('muster5');
$s5Copy = $browser();
$s5Copy->cookies = $s5->cookies;
check('the copied cookie works', $isSignedIn($s5Copy));
[, , $d] = $s5->fetchGet('/backend/system/backend-user/two-factor');
[, , $answer] = $s5->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($secretFrom($d), time())]);
check('switching on: the old cookie is refused', str_contains($answer, '"success"') && !$isSignedIn($s5Copy), substr($answer, 0, 160));
check('…the owner\'s browser stays signed in', $isSignedIn($s5));
check('…under a new session id', $s5->cookies['PHPSESSID'] !== $s5Copy->cookies['PHPSESSID']);
check('…with the CSRF token it had: his page keeps working', $s5->pageToken($dashboard) !== '');
$pwOwn = $browser();
$pwOwn->password('kollegin');
$pwCopy = $browser();
$pwCopy->cookies = $pwOwn->cookies;
[, , $answer] = $saveUser($pwOwn, 10, ['username' => 'kollegin', 'email' => '', 'name' => 'Max Muster', 'password' => 'Passwort-Sehr-Lang-1'], ['admin']);
check('own password change: the old cookie is refused, the browser stays', str_contains($answer, '"success"') && !$isSignedIn($pwCopy) && $isSignedIn($pwOwn), substr($answer, 0, 160));

// The mail and the log line of a switch-off. muster1: on, then off.
$own1 = $browser();
$own1->password('muster1');
[, , $d] = $own1->fetchGet('/backend/system/backend-user/two-factor');
$m1Secret = $secretFrom($d);
$mailsBefore = $outbox();
[, , $answer] = $own1->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($m1Secret, time())]);
check('muster1 on', str_contains($answer, '"success"'), substr($answer, 0, 160));
$subjectOf = static fn(string $file): string => iconv_mime_decode((string) (preg_match('/^Subject: (.+)$/m', (string) file_get_contents($file), $sm) ? trim($sm[1]) : ''), 0, 'UTF-8');
$newMails = array_values(array_diff($outbox(), $mailsBefore));
check('switch-on mail «2FA wurde eingerichtet»', count($newMails) === 1 && $subjectOf($newMails[0]) === '2FA wurde eingerichtet', (string) count($newMails));
$mailsBefore = $outbox();
[, , $answer] = $own1->fetchPost('/backend/system/backend-user/two-factor-disable', ['tf_code' => $freshCode('muster1', $m1Secret)]);
check('muster1 off', str_contains($answer, '"success"'), substr($answer, 0, 160));
$newMails = array_values(array_diff($outbox(), $mailsBefore));
$offMail  = $decoded($newMails[0] ?? '');
check('switch-off mail «2FA wurde entfernt», without a link', count($newMails) === 1 && $subjectOf($newMails[0]) === '2FA wurde entfernt' && str_contains($offMail, 'muster1@example.ch') && !str_contains($offMail, 'href=') && !str_contains($offMail, 'http://'));
check('…and its log line', str_contains($serverLog(), '2FA switched off for user id 5'));
$noMail = $browser();
$noMail->password('kollegin');
$rows = json_decode((string) file_get_contents($usersFile), true);
foreach ($rows as &$row) {
    if ($row['username'] === 'kollegin') {
        $row['email'] = '';
    }
}
unset($row);
file_put_contents($usersFile, json_encode($rows, JSON_PRETTY_PRINT));
[, , $d] = $noMail->fetchGet('/backend/system/backend-user/two-factor');
$mailsBefore = $outbox();
[, , $answer] = $noMail->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($secretFrom($d), time())]);
check('no recovery address: on, no mail', str_contains($answer, '"success"') && array_diff($outbox(), $mailsBefore) === [], substr($answer, 0, 160));

// ── real parallelism: two servers, a save in flight against the 2FA ─────────
echo "  parallel\n";
$serverB = static fn(): Browser => new Browser($port2);
$para = $browser();
$para->password('verwalter');
$overlaps = 0;
$finish = static function ($socket): string {
    $answer = (string) stream_get_contents($socket);
    fclose($socket);
    return $answer;
};
/** Starts an admin's save WITH a password (bcrypt keeps it in flight), then runs $action on the other server. */
$whileSaving = function (int $id, string $name, callable $action) use ($para, $dashboard, $finish, &$overlaps): string {
    [, , $form] = $para->fetchGet('/backend/system/backend-user/edit?id=' . $id);
    $entity = preg_match('/name=\\\\?"entity_csrf\\\\?" value=\\\\?"([a-f0-9]+)/', $form, $m) ? $m[1] : '';
    $socket = $para->fetchPostAsync('/backend/system/backend-user/edit?id=' . $id, [
        'username' => $name, 'email' => $name . '@example.ch', 'password' => 'Passwort-Sehr-Lang-1', 'entity_csrf' => $entity,
        'roles' => ['editor'], 'identity_name' => [], 'identity_email' => [],
    ], $para->pageToken($dashboard));
    usleep(80000);
    $action();
    stream_set_blocking($socket, false);
    if ((string) fread($socket, 16) === '' && !feof($socket)) {
        $overlaps++;   // the 2FA request came back while the save was still at work
    }
    stream_set_blocking($socket, true);
    return $finish($socket);
};

// R1: a switch-on must survive the save.
$r1 = $serverB();
$r1->password('muster2');
[, , $d] = $r1->fetchGet('/backend/system/backend-user/two-factor');
$r1Secret = $secretFrom($d);
$r1Token  = $r1->pageToken($dashboard);
$r1Answer = '';
$saved = $whileSaving(6, 'muster2', static function () use ($r1, $r1Secret, $r1Token, &$r1Answer): void {
    [, , $r1Answer] = $r1->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($r1Secret, time())], $r1Token);
});
check('switch-on answered success, the save too', str_contains($r1Answer, '"success"') && str_contains($saved, '"success"'), substr($r1Answer, 0, 120) . ' / ' . substr($saved, -120));
check('…the switch-on is still on disk afterwards', ($storedUser('muster2')['totp_secret'] ?? '') !== '' && ($storedUser('muster2')['totp_activated_at'] ?? '') !== '');
$check1 = $browser();
[, $h] = $check1->password('muster2');
check('…the password alone does not log in', ($h['location'] ?? '') === '/backend/system/login/code');

// R3: a spent step must stay spent.
$r3Code = Totp::code($r1Secret, time() + 30);
$r3 = $serverB();
$r3->password('muster2');
$r3Token = $r3->pageToken('/backend/system/login/code');
$r3Status = 0;
$whileSaving(6, 'muster2', static function () use ($r3, $r3Code, $r3Token, &$r3Status): void {
    [$r3Status] = $r3->go('/backend/system/login/code', ['csrf_token' => $r3Token, 'code' => $r3Code]);
});
check('a code accepted during a save', in_array($r3Status, [302, 303], true), "status {$r3Status}");
check('…its step is on disk afterwards', $storedUser('muster2')['totp_last_step'] >= intdiv(time(), 30));
$r3b = $browser();
$r3b->password('muster2');
[, , $html] = $r3b->code($r3Code);
check('…the SAME code is refused on a new login', str_contains($html, 'bereits verwendet') && !$isSignedIn($r3b));
(new TotpGuard($guardDir))->reset('6');

// R2: an admin reset must survive the save, the stamps of both add up.
$r2Sup = $serverB();
$r2Sup->password('max');
[, , $c] = $r2Sup->fetchGet('/backend/system/backend-user/confirm-two-factor-reset?id=6');
$r2Entity = preg_match('/name=\\\\?"entity_csrf\\\\?"\s+value=\\\\?"([a-f0-9]+)/', $c, $m) ? $m[1] : '';
$r2Token  = $r2Sup->pageToken($dashboard);
$epochBefore = $storedUser('muster2')['auth_epoch'];
$r2Answer = '';
$whileSaving(6, 'muster2', static function () use ($r2Sup, $r2Entity, $r2Token, &$r2Answer): void {
    [, , $r2Answer] = $r2Sup->fetchPost('/backend/system/backend-user/two-factor-reset', ['id' => 6, 'entity_csrf' => $r2Entity], $r2Token);
});
check('reset answered success', str_contains($r2Answer, '"success"'), substr($r2Answer, 0, 160));
check('…the secret does not come back', ($storedUser('muster2')['totp_secret'] ?? '') === '' && ($storedUser('muster2')['totp_last_step'] ?? 0) === 0);
check('…reset and password change each raised the stamp', $storedUser('muster2')['auth_epoch'] === $epochBefore + 2, $epochBefore . ' -> ' . $storedUser('muster2')['auth_epoch']);
check('the save really overlapped the 2FA request in at least one of the three', $overlaps >= 1, (string) $overlaps);
echo "  (overlapped in {$overlaps} of 3)\n";

// A save in flight while the user is deleted: he stays deleted, with the password that save carried.
$d1 = $serverB();
$d1->password('max');
[, , $c] = $d1->fetchGet('/backend/system/backend-user/confirm-delete?id=6');
$d1Entity = preg_match('/name=\\\\?"entity_csrf\\\\?"\s+value=\\\\?"([a-f0-9]+)/', $c, $m) ? $m[1] : '';
$d1Token  = $d1->pageToken($dashboard);
$d1Answer = '';
$savedBeforeDelete = $overlaps;
$saved = $whileSaving(6, 'muster2', static function () use ($d1, $d1Entity, $d1Token, &$d1Answer): void {
    [, , $d1Answer] = $d1->fetchPost('/backend/system/backend-user/remove', ['id' => 6, 'entity_csrf' => $d1Entity], $d1Token);
});
$usernames = array_column(json_decode((string) file_get_contents($usersFile), true), 'username');
check('a user deleted during a save: the delete answered success', str_contains($d1Answer, '"success"'), substr($d1Answer, 0, 160));
check('…the save overlapped it', $overlaps === $savedBeforeDelete + 1, (string) ($overlaps - $savedBeforeDelete));
check('…the row stays gone', !in_array('muster2', $usernames, true));
check('…and the log says the save was skipped', str_contains($serverLog(), 'id 6 skipped, the row was deleted'));
$d1Login = $browser();
$d1Login->password('muster2');
check('…the deleted user cannot log in', !$isSignedIn($d1Login));

// Two SUPER_USERs demote each other at once: real requests on two servers, each with bcrypt inside.
$supersLeft = static function () use ($usersFile): array {
    return array_values(array_map(static fn(array $u): string => $u['username'], array_filter(json_decode((string) file_get_contents($usersFile), true), static fn(array $u): bool => in_array('superUser', $u['roles'], true))));
};
$demoteBody = static fn(string $name, string $entity): array => ['username' => $name, 'email' => ($name === 'max' ? 'max.muster' : $name) . '@example.ch', 'password' => 'Passwort-Sehr-Lang-1', 'entity_csrf' => $entity, 'roles' => ['admin'], 'identity_name' => [], 'identity_email' => []];
$entityOf = static fn(string $form): string => preg_match('/name=\\\\?"entity_csrf\\\\?" value=\\\\?"([a-f0-9]+)/', $form, $m) ? $m[1] : '';
$mutualLeft = [];
for ($round = 0; $round < 3; $round++) {
    $m1 = new Browser($port);
    $m1->password('max');
    $m2 = new Browser($port2);
    $m2->password('zweit');
    $e1 = $entityOf($m1->fetchGet('/backend/system/backend-user/edit?id=9')[2]);
    $e2 = $entityOf($m2->fetchGet('/backend/system/backend-user/edit?id=1')[2]);
    $t1 = $m1->pageToken($dashboard);
    $t2 = $m2->pageToken($dashboard);
    $s1 = $m1->fetchPostAsync('/backend/system/backend-user/edit?id=9', $demoteBody('zweit', $e1), $t1);
    $s2 = $m2->fetchPostAsync('/backend/system/backend-user/edit?id=1', $demoteBody('max', $e2), $t2);
    $a1 = $finish($s1);
    $a2 = $finish($s2);
    $left = $supersLeft();
    $mutualLeft[] = count($left);
    if (count($left) === 1) {
        $survivor = new Browser($port);
        $survivor->password($left[0]);
        $missing = $left[0] === 'max' ? [9, 'zweit', 'zweit@example.ch'] : [1, 'max', 'max.muster@example.ch'];
        $saveUser($survivor, $missing[0], ['username' => $missing[1], 'email' => $missing[2], 'password' => ''], ['superUser']);
    }
}
check('two super-users demoting each other, 3 rounds: one is always left', !in_array(0, $mutualLeft, true), implode(',', $mutualLeft));
check('…the store is back at two for the rest of this run', count($supersLeft()) === 2, implode(',', $supersLeft()));
$supMax = $browser();
$supMax->password('max');

// ── id reuse after a delete ─────────────────────────────────────────────────
echo "  id reuse\n";
$rows = json_decode((string) file_get_contents($usersFile), true);
$rows[] = ['id' => 20, 'username' => 'geloescht', 'email' => 'geloescht@example.ch', 'password_hash' => $hash, 'roles' => ['superUser'], 'sort_key' => 19];
file_put_contents($usersFile, json_encode($rows, JSON_PRETTY_PRINT));
$gone = $browser();
$gone->password('geloescht');
check('a super-user who will be deleted is in', $isSignedIn($gone) && $gone->status('/backend/service/backup/list') === 200);
$goneLink = $linkForJs('geloescht');
check('…with an open reset link', $goneLink !== '');
file_put_contents($guardDir . '/' . hash('sha256', '20') . '.json', json_encode(['failures' => 3]));
[, , $c] = $supMax->fetchGet('/backend/system/backend-user/confirm-delete?id=20');
$del = preg_match('/name=\\\\?"entity_csrf\\\\?"\s+value=\\\\?"([a-f0-9]+)/', $c, $m) ? $m[1] : '';
[, , $answer] = $supMax->fetchPost('/backend/system/backend-user/remove', ['id' => 20, 'entity_csrf' => $del]);
check('deleted', str_contains($answer, '"success"'), substr($answer, 0, 160));
check('…his guard file is gone', !is_file($guardFile(20)));
[, , $answer] = $supMax->fetchPost('/backend/system/backend-user/add', ['username' => 'nachfolger', 'email' => 'nachfolger@example.ch', 'password' => 'Nachfolger-Passwort-1', 'roles' => ['editor'], 'identity_name' => [], 'identity_email' => []]);
$newcomer = $storedUser('nachfolger');
check('the new user does NOT get the deleted id', ($newcomer['id'] ?? 0) === 21, (string) ($newcomer['id'] ?? 0));
check('…and a stamp from the clock', abs(($newcomer['auth_epoch'] ?? 0) - time()) < 60);
check('the deleted user\'s old session does not fit him', !$isSignedIn($gone) && $gone->status('/backend/service/backup/list') !== 200);
$goneReset = $browser();
check('the old reset link is dead', str_contains($goneReset->go('/backend/system/login/reset?token=' . $goneLink)[2], 'Link nicht gültig'));
// A row removed by hand leaves no mark, so its id can come back: the seeded stamp still tells them apart.
$rows = json_decode((string) file_get_contents($usersFile), true);
$rows[] = ['id' => 22, 'username' => 'handweg', 'password_hash' => $hash, 'roles' => ['superUser'], 'sort_key' => 21];
file_put_contents($usersFile, json_encode($rows, JSON_PRETTY_PRINT));
$handGone = $browser();
$handGone->password('handweg');
check('a user who will be removed by hand is in', $isSignedIn($handGone));
$rows = array_values(array_filter(json_decode((string) file_get_contents($usersFile), true), static fn(array $r): bool => $r['username'] !== 'handweg'));
file_put_contents($usersFile, json_encode($rows, JSON_PRETTY_PRINT));
$supMax->fetchPost('/backend/system/backend-user/add', ['username' => 'nachher', 'email' => 'nachher@example.ch', 'password' => 'Nachfolger-Passwort-1', 'roles' => ['editor'], 'identity_name' => [], 'identity_email' => []]);
check('…the next user does get his id', ($storedUser('nachher')['id'] ?? 0) === 22);
check('…his old session does not fit that user', !$isSignedIn($handGone));

// ── the code lock on dead counters: three doors ─────────────────────────────
echo "  dead counters\n";
$dc = $browser();
$dc->password('muster3');
[, , $d] = $dc->fetchGet('/backend/system/backend-user/two-factor');
$dcSecret = $secretFrom($d);
$dc->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($dcSecret, time())]);
check('muster3 has 2FA', ($storedUser('muster3')['totp_secret'] ?? '') !== '');
$dcLogin = $browser();
$dcLogin->password('muster3');
$dcLink = $linkForJs('muster3');
$dcReset = $browser();
$dcResetPage = $dcReset->go('/backend/system/login/reset?token=' . $dcLink)[2];
$dcResetToken = preg_match('/name="csrf_token" value="([^"]+)"/', $dcResetPage, $m) ? $m[1] : '';
$technical = 'wegen eines technischen Problems gerade nicht möglich';
$kill = static function () use ($guardDir): void {
    rrmdir($guardDir);
    file_put_contents($guardDir, 'x');
};
$revive = static function () use ($guardDir): void {
    @unlink($guardDir);
};
$alertMails = static function () use ($outbox, $subjectOf): array {
    return array_values(array_filter($outbox(), static fn(string $m): bool => str_contains($subjectOf($m), 'backend-2fa:code-lock')));
};
// Who is told comes from Service → E-Mail. Nobody yet: refused as ever, no mail, no alert state.
$alertStateDir = $site . '/data/framework/auth/alert';
$kill();
[, , $html] = $dcLogin->code($freshCode('muster3', $dcSecret));
check('no recipient yet: refused as ever, no alert mail, no alert state', str_contains($html, $technical) && $alertMails() === [] && !is_dir($alertStateDir), (string) count($alertMails()));
$revive();

$mailAdmin = $browser();
$mailAdmin->password('verwalter');
[$s, , $list] = $mailAdmin->go('/backend/service/email-settings/list');
check('the entry stands in Service → E-Mail, it says what it is for', $s === 200 && str_contains($list, 'backendCodeLock') && str_contains($list, 'keine 2FA-Codes annimmt'), "status {$s}");
[, , $mask] = $mailAdmin->fetchGet('/backend/service/email-settings/edit?key=backendCodeLock');
check('its mask offers the recipients alone', str_contains($mask, 'id="es-to"') && !str_contains($mask, 'id="es-cc"') && !str_contains($mask, 'id="es-subject"') && !str_contains($mask, 'route_key'), substr($mask, 0, 200));
[, , $contactMask] = $mailAdmin->fetchGet('/backend/service/email-settings/edit?key=contactForm');
check('an ordinary form keeps CC, subject and routes', str_contains($contactMask, 'id="es-cc"') && str_contains($contactMask, 'id="es-subject"') && str_contains($contactMask, 'route_key'));
$maskToken = preg_match('/name="entity_csrf"\s+value="([a-f0-9]+)/', $mask, $m) ? $m[1] : '';
[, , $answer] = $mailAdmin->fetchPost('/backend/service/email-settings/edit?key=backendCodeLock', [
    'form_key' => 'backendCodeLock', 'entity_csrf' => $maskToken, 'to' => "betrieb@example.ch\nzweite@example.ch",
    'cc' => 'fremd@example.ch', 'subject' => 'Gefälscht', 'route_key' => ['x'], 'route_to' => ['fremd@example.ch'],
]);
$settingRows = json_decode((string) file_get_contents($site . '/data/framework/mail/emailFormSettings.json'), true);
$settingRow  = array_values(array_filter($settingRows, static fn(array $r): bool => ($r['form_key'] ?? '') === 'backendCodeLock'))[0] ?? [];
check('saved: the recipients, nothing else a forged body sends', str_contains($answer, '"success"') && ($settingRow['to'] ?? []) === ['betrieb@example.ch', 'zweite@example.ch'] && ($settingRow['cc'] ?? null) === [] && ($settingRow['subject'] ?? null) === '' && ($settingRow['routes'] ?? null) === [], substr($answer, 0, 200));

$kill();
$hashBefore = $storedUser('muster3')['password_hash'];
$right = $freshCode('muster3', $dcSecret);
[$s, , $html] = $dcLogin->code($right);
check('code page: the right code is refused with the technical message', $s === 200 && str_contains($html, $technical) && !str_contains($html, 'falsch') && !$isSignedIn($dcLogin), "status {$s}");
[, , $answer] = $dc->fetchPost('/backend/system/backend-user/two-factor-disable', ['tf_code' => $right]);
$answerText = (string) json_decode($answer, true)['fields']['tf_code']['message'];
check('switch off: refused with the technical message', str_contains($answerText, $technical) && ($storedUser('muster3')['totp_secret'] ?? '') !== '', substr($answer, 0, 200));
[$s, , $html] = $dcReset->go('/backend/system/login/reset', ['csrf_token' => $dcResetToken, 'token' => $dcLink, 'password' => 'Neues-Passwort-Lang-9', 'password_repeat' => 'Neues-Passwort-Lang-9', 'code' => $right]);
check('reset form: refused with the technical message, nothing changed, link live', str_contains($html, $technical) && $storedUser('muster3')['password_hash'] === $hashBefore && str_contains($html, 'name="password_repeat"'));
$log = $serverLog();
check('every refusal logs, with step and directory', substr_count($log, 'code refused, counters unavailable') >= 3 && str_contains($log, 'step=write-counter') && str_contains($log, 'backend-totp-guard'));
check('…never the code', !str_contains($log, 'code=' . $right));
check('…the secret is not in the log', !str_contains($log, $dcSecret));
$alerts = $alertMails();
check('one alert mail for three refusals, to both addresses of the mask', count($alerts) === 1 && str_contains($decoded($alerts[0] ?? ''), 'betrieb@example.ch') && str_contains($decoded($alerts[0] ?? ''), 'zweite@example.ch'), (string) count($alerts));
$dcOther = $browser();
$dcOther->password('muster4');
check('an account without 2FA still logs in', $isSignedIn($dcOther));
[, , $d] = $dcOther->fetchGet('/backend/system/backend-user/two-factor');
[, , $answer] = $dcOther->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($secretFrom($d), time())]);
check('switching on is refused meanwhile, it would lock him out', str_contains($answer, $technical) && !($storedUser('muster4')['totp_secret'] ?? ''), substr($answer, 0, 200));
$revive();
[$s] = $dcLogin->code($right);
check('counters back: the right code passes, no intervention', in_array($s, [302, 303], true) && $isSignedIn($dcLogin), "status {$s}");
check('…one recovery mail', count($alertMails()) === 2 && str_contains($subjectOf($alertMails()[1] ?? $alertMails()[0]), 'code-lock'), (string) count($alertMails()));

// A project with its own emailConfig (the whole file wins) that does not carry the entry: nobody is
// told, the log says why, the login's answer stays.
$fileFinderFile = $site . '/config/fileFinder.inc.php';
$fileFinderBody = (string) file_get_contents($fileFinderFile);
mkdir($site . '/project-override/src/Config', 0777, true);
file_put_contents($site . '/project-override/src/Config/emailConfig.inc.php', "<?php\nreturn ['forms' => ['contactForm' => ['to' => '', 'template' => ['emails/publicForm', 'Z77\\\\Shared']]]];\n");
file_put_contents($fileFinderFile, str_replace(
    "\$entry('kernel/shared')",
    "array_merge_recursive(['sourcePaths' => [" . var_export($site . '/project-override', true) . "]], \$entry('kernel/shared'))",
    $fileFinderBody
));
// A server of its own: a running one keeps the config in its APCu.
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port3 = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$server3 = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $base . '/sessions', '-S', "127.0.0.1:{$port3}", '-t', $site . '/public', $site . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $base . '/server.log', 'a'], 2 => ['file', $base . '/server.log', 'a']],
    $server3Pipes,
    $site
);
for ($i = 0; $i < 100 && !($up = @fsockopen('127.0.0.1', $port3)); $i++) {
    usleep(50000);
}
$kill();
$dcNoEntry = new Browser($port3);
$dcNoEntry->password('muster3');
[, , $html] = $dcNoEntry->code($freshCode('muster3', $dcSecret));
check('entry missing in a project\'s emailConfig: refused as ever, no alert mail', str_contains($html, $technical) && count($alertMails()) === 2, (string) count($alertMails()));
check('…the log says why', str_contains($serverLog(), 'alert recipients not readable') && str_contains($serverLog(), 'backendCodeLock'));
$revive();
proc_terminate($server3);
proc_close($server3);
file_put_contents($fileFinderFile, $fileFinderBody);

// The whole of var/lib is gone, as behind a dangling link: the alert's state is elsewhere, the rest holds.
rename($site . '/var/lib', $site . '/var/lib.saved');
file_put_contents($site . '/var/lib', 'x');
$dcNo2fa = $browser();
$dcNo2fa->password('verwalter');
check('var/lib dead: an account without 2FA still logs in (the throttle fails open)', $isSignedIn($dcNo2fa));
$dcLogin2 = $browser();
$dcLogin2->password('muster3');
[, , $html] = $dcLogin2->code($freshCode('muster3', $dcSecret));
check('…an account with 2FA gets the technical message', str_contains($html, $technical) && !$isSignedIn($dcLogin2));
check('…and an alert goes out, its state lives beside the key file', count($alertMails()) === 3, (string) count($alertMails()));
$supMaxReset = $browser();
$supMaxReset->password('max');
[, , $c] = $supMaxReset->fetchGet('/backend/system/backend-user/confirm-two-factor-reset?id=7');
$resetEntity = preg_match('/name=\\\\?"entity_csrf\\\\?"\s+value=\\\\?"([a-f0-9]+)/', $c, $m) ? $m[1] : '';
[, , $answer] = $supMaxReset->fetchPost('/backend/system/backend-user/two-factor-reset', ['id' => 7, 'entity_csrf' => $resetEntity]);
check('…the admin reset still works, it judges no code: the way out', str_contains($answer, '"success"') && ($storedUser('muster3')['totp_secret'] ?? 'x') === '', substr($answer, 0, 160));
unlink($site . '/var/lib');
rename($site . '/var/lib.saved', $site . '/var/lib');

// One account's counter file cannot be written, the directory can: guessing must not become free.
$ro = $browser();
$ro->password('muster6');
[, , $d] = $ro->fetchGet('/backend/system/backend-user/two-factor');
$roSecret = $secretFrom($d);
$ro->fetchPost('/backend/system/backend-user/two-factor-enable', ['tf_password' => 'Passwort-Sehr-Lang-1', 'tf_code' => Totp::code($roSecret, time())]);
check('muster6 has 2FA', ($storedUser('muster6')['totp_secret'] ?? '') !== '');
$roFile = $guardFile(13);
file_put_contents($roFile, json_encode(['failures' => 0]));
chmod($roFile, 0444);
if (is_writable($roFile)) {
    echo "  (running as a user that ignores file modes: the read-only counter is not tested here)\n";
} else {
    $roLogin = $browser();
    $roLogin->password('muster6');
    $seen = [];
    for ($i = 0; $i < 12; $i++) {
        [, , $html] = $roLogin->code('000000');
        $key = str_contains($html, $technical) ? 'technical' : (str_contains($html, 'Zu viele') ? 'locked' : 'wrong');
        $seen[$key] = ($seen[$key] ?? 0) + 1;
    }
    check('a read-only counter: 12 wrong codes all get the technical message', $seen === ['technical' => 12], json_encode($seen));
    [$s, , $html] = $roLogin->code($freshCode('muster6', $roSecret));
    check('…the right code is refused too', str_contains($html, $technical) && !$isSignedIn($roLogin), "status {$s}");
    [, , $answer] = $ro->fetchPost('/backend/system/backend-user/two-factor-disable', ['tf_code' => $freshCode('muster6', $roSecret)]);
    check('…at the switch off too', str_contains((string) (json_decode($answer, true)['fields']['tf_code']['message'] ?? ''), 'technischen Problems') && ($storedUser('muster6')['totp_secret'] ?? '') !== '', substr($answer, 0, 160));
    check('…the log says so', str_contains($serverLog(), 'step=write-counter dir=' . $guardDir . ' user id 13'));
    chmod($roFile, 0644);
    [$s] = $roLogin->code($freshCode('muster6', $roSecret));
    check('…writable again: the right code passes', in_array($s, [302, 303], true) && $isSignedIn($roLogin), "status {$s}");
}
@chmod($roFile, 0644);

check('no PHP error reached the server log', !preg_match('/PHP (Fatal|Parse) error|Uncaught/', $serverLog()), substr($serverLog(), -600));

proc_terminate($server);
proc_close($server);
proc_terminate($server2);
proc_close($server2);

rrmdir($base);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
