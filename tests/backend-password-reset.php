<?php

/**
 * «Passwort vergessen» + login brute-force harness (CLI, temp ABS_BASE_PATH, no database) —
 * login.md LOGIN-RESET-001, security.md SEC-004. What is load-bearing:
 *
 *   (A) the token store: only the SHA-256 on disk, a new request ends the old link, GET does not
 *       consume, expired / used / unknown / malformed are refused, one consume wins — also across
 *       real concurrent processes; purge takes dead entries only; the job does the same;
 *   (B) the request flow: no outcome to give away (unknown user, no address, over a limit → no
 *       mail and no token, and no exception), both throttles, the sweep on every request, a failed
 *       send logs without the token; the link's origin never comes from the Host header, and
 *       without a configured origin nothing is issued;
 *   (C) the new fields: a store without the keys loads and saves; a form body cannot set the stamp;
 *       the address is cleaned, optional, deliverable and unique; both lookups;
 *   (D) the session stamp: stale → guest, missing → valid, member realm untouched, deleted user →
 *       guest, a failed lookup → no fatal, own change keeps the session;
 *   (E) the login throttle: 5 per username+IP, 30 per IP, reservation before the password check,
 *       success clears, a reset forgives, no-disk fails open;
 *   (F) the wiring: order in the POST flow, config, templates, the admin's password change;
 *   (G) the real flow through the framework in PHP's built-in server: GET/POST forgot, the captured
 *       mail, GET/POST reset, sessions, login — with a forged Host header.
 *
 * Run: php tests/backend-password-reset.php
 */

// Worker for the concurrency check: consumes one token, prints the user id or «null».
if (($argv[1] ?? '') === '--consume-worker') {
    define('ABS_BASE_PATH', $argv[2]);
    require __DIR__ . '/../vendor/autoload.php';
    while (microtime(true) < (float) $argv[4]) {
        usleep(200);
    }
    echo json_encode((new Z77\Module\Backend\PasswordReset\PasswordResetTokens())->consume($argv[3]));
    exit(0);
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "backend-password-reset: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-password-reset-' . bin2hex(random_bytes(4));
mkdir($base . '/data', 0777, true);
define('ABS_BASE_PATH', $base);

require $autoload;
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';

use Z77\Core\Config\AuthRole;
use Z77\Core\Controller\ControllerHandler;
use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Session\SessionManager;
use Z77\Module\Backend\Jobs\PasswordResetCleanupJob;
use Z77\Module\Backend\PasswordReset\PasswordRecovery;
use Z77\Module\Backend\PasswordReset\PasswordResetTokens;
use Z77\Persistence\Cleaning\BodyCleaner;
use Z77\Persistence\File\Storage\FileStorage;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Auth\LoginThrottle;
use Z77\Shared\Auth\PasswordTier;
use Z77\Shared\Entities\BackendUser;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Mail\EmailMessage;
use Z77\Shared\Services\AuthService;
use Z77\Shared\Throttle\FileThrottle;
use Z77\Shared\Validators\BackendUserValidator;

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

/** @return list<array<string, mixed>> the raw rows on disk */
function storedRows(): array
{
    $file = ABS_BASE_PATH . '/data/' . PasswordResetTokens::PATH;
    return is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
}

DI::getInstance(true)->set('CacheManager', CacheManager::class, true);
$uem = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
DI::getInstance()->set('UnifiedEntityManager', $uem, true);

function addUser(UnifiedEntityManager $uem, string $username, string $email, array $roles = [AuthRole::ADMIN]): BackendUser
{
    $user = new BackendUser(['username' => $username, 'email' => $email, 'roles' => $roles]);
    $user->setPasswordHash(password_hash('old-passwort-lang', PASSWORD_BCRYPT, ['cost' => 4]));
    $uem->persist($user);
    $uem->flush();
    return $user;
}

$max  = addUser($uem, 'max', 'max.muster@example.ch');
$erna = addUser($uem, 'erna', '');
$repo = $uem->getRepository(BackendUser::class);

// ── (A) token store ─────────────────────────────────────────────────────────
echo "(A) token store\n";
$tokens = new PasswordResetTokens();
$t0     = 1_800_000_000;

check('nothing on disk before the first request (a fresh install needs no step)', !is_file(ABS_BASE_PATH . '/data/' . PasswordResetTokens::PATH));
check('a missing store refuses, does not fail', $tokens->peek(str_repeat('a', 64), $t0) === null);

$plain = $tokens->issue($max->getId(), $t0);
$rows  = storedRows();
$raw   = (string) file_get_contents(ABS_BASE_PATH . '/data/' . PasswordResetTokens::PATH);
check('the store directory is created on the first write', count($rows) === 1);
check('token is ≥ 32 random bytes (64 hex)', preg_match('/^[a-f0-9]{64}$/', $plain) === 1);
check('the plaintext is not on disk', !str_contains($raw, $plain));
check('only its SHA-256 is', $rows[0]['token_hash'] === hash('sha256', $plain));
check('with user id, created and expiry', $rows[0]['user_id'] === $max->getId() && $rows[0]['created_at'] === $t0 && $rows[0]['expires_at'] === $t0 + 1800);

check('valid for 30 minutes: 1 s before the end', $tokens->peek($plain, $t0 + 1799) === $max->getId());
check('expired at the end', $tokens->peek($plain, $t0 + 1800) === null);
check('peek (GET) does not consume', $tokens->peek($plain, $t0) === $max->getId() && storedRows()[0]['used_at'] === null);
check('expired cannot be consumed', $tokens->consume($plain, $t0 + 1800) === null);
check('unknown refused', $tokens->consume(str_repeat('b', 64), $t0) === null);
check('malformed refused', $tokens->peek('../../etc/passwd', $t0) === null && $tokens->peek('', $t0) === null);
check('the stored hash itself is no token', $tokens->peek($rows[0]['token_hash'], $t0) === null);

$second = $tokens->issue($max->getId(), $t0 + 10);
check('a new request ends the previous link', $tokens->peek($plain, $t0 + 11) === null);
check('…and the new one works', $tokens->peek($second, $t0 + 11) === $max->getId());
check('one entry per user', count(storedRows()) === 1);
$other = $tokens->issue($erna->getId(), $t0 + 10);
check("another user's link is not touched", $tokens->peek($second, $t0 + 11) === $max->getId() && $tokens->peek($other, $t0 + 11) === $erna->getId());

check('first consume gets the user', $tokens->consume($second, $t0 + 12) === $max->getId());
check('second consume gets nothing', $tokens->consume($second, $t0 + 12) === null);
check('used is refused on GET too', $tokens->peek($second, $t0 + 12) === null);

// Real concurrency: a file lock across processes, not a sequence in one.
$race  = $tokens->issue($max->getId(), time());
$start = microtime(true) + 0.6;
$procs = [];
for ($i = 0; $i < 6; $i++) {
    $procs[$i] = proc_open([PHP_BINARY, __FILE__, '--consume-worker', ABS_BASE_PATH, $race, (string) $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$results = [];
foreach ($procs as $i => $proc) {
    $results[] = trim((string) stream_get_contents($pipes[$i][1]));
    proc_close($proc);
}
$winners = array_filter($results, static fn(string $r): bool => $r === (string) $max->getId());
check('6 concurrent processes: exactly one consume succeeds', count($winners) === 1 && count(array_filter($results, static fn(string $r): bool => $r === 'null')) === 5, implode(',', $results));

// Purge: fresh state with one of each.
(new FileStorage())->save(PasswordResetTokens::PATH, []);
$open    = $tokens->issue(1, $t0);
$used    = $tokens->issue(2, $t0);
$tokens->consume($used, $t0 + 1);
$expired = $tokens->issue(3, $t0 - 1800);
check('purge removes expired and used', $tokens->purge($t0 + 2) === 2);
check('…and leaves the open link', $tokens->peek($open, $t0 + 2) === 1 && count(storedRows()) === 1);
check('a purge with nothing to do removes nothing', $tokens->purge($t0 + 2) === 0);
$tokens->revokeFor(1);
check('revokeFor ends a user\'s open link', $tokens->peek($open, $t0 + 2) === null);

// The job works against now(): an entry long expired, one used, one open.
(new FileStorage())->save(PasswordResetTokens::PATH, []);
$tokens->issue(1, time() - 7200);
$jobUsed = $tokens->issue(2);
$tokens->consume($jobUsed);
$jobOpen = $tokens->issue(3);
$actor   = new AuthUser(['id' => 0, 'user_name' => 'cron', 'roles' => [AuthRole::CRON_JOB], 'realm' => AuthUser::REALM_CRON]);
$result  = (new PasswordResetCleanupJob())->run(new JobContext('password-reset-cleanup', [], null, 1, time() + 50, $actor));
check('job removes expired + used, leaves the open link', $tokens->peek($jobOpen) === 3 && count(storedRows()) === 1);
check('job finishes in one slice', $result->getOutcome() === Z77\Shared\Jobs\JobOutcome::Done, json_encode($result));

// ── (B) request flow ────────────────────────────────────────────────────────
echo "(B) request flow\n";
(new FileStorage())->save(PasswordResetTokens::PATH, []);
$logFile = $base . '/error.log';
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mails = [];
$sendOk = true;
$flowFor = function (?Closure $baseUrl = null, ?string $dir = null) use (&$mails, &$sendOk, $repo, $tokens, $base): PasswordRecovery {
    return new PasswordRecovery(
        $repo,
        $tokens,
        new FileThrottle($dir ?? $base . '/throttle-' . bin2hex(random_bytes(3))),
        static function (EmailMessage $mail) use (&$mails, &$sendOk): bool { $mails[] = $mail; return $sendOk; },
        $baseUrl ?? static fn(): string => 'https://kunde.example.ch',
    );
};

// A forged Host must reach nothing. The only origin the flow knows is the closure.
$_SERVER['HTTP_HOST'] = 'evil.example.com';

$flow = $flowFor();
$flow->request('unbekannt', '192.0.2.1');
check('unknown user: no mail', $mails === []);
check('unknown user: no token', storedRows() === []);
$flow->request('nobody@example.ch', '192.0.2.1');
check('unknown address: no mail, no token', $mails === [] && storedRows() === []);
$flow->request('erna', '192.0.2.1');
check('user without an address: no mail, no token', $mails === [] && storedRows() === []);
$flow->request('', '192.0.2.1');
check('empty identifier: no mail', $mails === []);

$flow->request('max', '192.0.2.1');
check('known username: one mail', count($mails) === 1);
check('to the recovery address', $mails[0]->getTo() === 'max.muster@example.ch');
check('one token stored', count(storedRows()) === 1);
$url = $mails[0]->getContext()['resetUrl'];
check('link origin is the configured one', str_starts_with($url, 'https://kunde.example.ch/backend/system/login/reset?token='));
check('link origin is not the Host header', !str_contains($url, 'evil.example.com'));
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
check("the link's token matches the stored hash", hash('sha256', $query['token']) === storedRows()[0]['token_hash']);
check('default system sender (no From on the message)', $mails[0]->getFrom() === '');
check('template from module-backend, HTML with derived text', $mails[0]->getTemplate() === 'emails/passwordReset' && $mails[0]->getTemplateNameSpace() === 'Z77\\Module\\Backend');

$mails = [];
$flow->request('MAX.MUSTER@EXAMPLE.CH', '192.0.2.2');
check('address lookup is case-insensitive', count($mails) === 1);
$mails = [];
$flow->request('MAX', '192.0.2.3');
check('username lookup is case-insensitive', count($mails) === 1);

$mails = [];
$flow->request('max', '192.0.2.4');
check('account limit: the 4th request of the hour sends nothing (username and address share it)', $mails === []);
$mails = [];
$flow->request('max.muster@example.ch', '192.0.2.5');
check('…under either spelling', $mails === []);

$flow  = $flowFor();
$mails = [];
for ($i = 1; $i <= 10; $i++) {
    $flow->request('unbekannt' . $i, '192.0.2.77');
}
$flow->request('max', '192.0.2.77');
check('IP limit: the 11th request from one address sends nothing', $mails === []);
$flow->request('max', '192.0.2.78');
check('…another address still gets through', count($mails) === 1);
$flow  = $flowFor();
$mails = [];
for ($i = 1; $i <= 10; $i++) {
    $flow->request('unbekannt' . $i, '2001:db8:1:2::' . dechex($i));
}
$flow->request('max', '2001:db8:1:2::ff');
check('IP limit: one IPv6 /64 is one address', $mails === []);

// The sweep runs on every request, also for an unknown user.
(new FileStorage())->save(PasswordResetTokens::PATH, []);
$tokens->issue($erna->getId(), time() - 7200);
$flowFor()->request('unbekannt', '192.0.2.9');
check('a request for an unknown user also purges expired entries', storedRows() === []);

// Failure paths: logged, without the token, and silent to the caller.
(new FileStorage())->save(PasswordResetTokens::PATH, []);
@unlink($logFile);
$sendOk = false;
$mails  = [];
$threw  = false;
try {
    $flowFor()->request('max', '192.0.2.10');
} catch (Throwable) {
    $threw = true;
}
$sendOk = true;
$log    = (string) @file_get_contents($logFile);
parse_str((string) parse_url($mails[0]->getContext()['resetUrl'], PHP_URL_QUERY), $query);
check('a failed send: no exception', !$threw);
check('a failed send is logged', str_contains($log, 'reset mail not sent'));
check('the log does not carry the token', !str_contains($log, $query['token']));

(new FileStorage())->save(PasswordResetTokens::PATH, []);
@unlink($logFile);
$mails = [];
$threw = false;
try {
    $flowFor(static function (): string { throw new RuntimeException('no canonicalBaseUrl'); })->request('max', '192.0.2.11');
} catch (Throwable) {
    $threw = true;
}
check('no configured origin: no exception, no mail, no token', !$threw && $mails === [] && storedRows() === []);
check('…and the cause is in the log', str_contains((string) @file_get_contents($logFile), 'no canonicalBaseUrl'));

// Without canonicalBaseUrl the real source throws rather than reading the Host header.
$request = (new ReflectionClass(Z77\Core\Http\Request::class))->newInstanceWithoutConstructor();
$thrown  = null;
try {
    $request->getBaseUrl();
} catch (RuntimeException $e) {
    $thrown = $e;
}
check('Request::getBaseUrl() without config throws, Host set (SEC-006)', $thrown !== null);

$mails = [];
$flowFor()->notifyPasswordChanged($max);
check('the change notice goes to the address', count($mails) === 1 && $mails[0]->getTo() === 'max.muster@example.ch');
check('…subject «Dein Passwort wurde geändert»', $mails[0]->getSubject() === 'Dein Passwort wurde geändert');
$mails = [];
$flowFor()->notifyPasswordChanged($erna);
check('no address, no notice', $mails === []);

$render = static function (string $tpl, array $context): string {
    extract($context, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/../packages/module-backend/res/view/templates/emails/' . $tpl . '.tpl.php';
    return (string) ob_get_clean();
};
$resetBody = $render('passwordReset', ['greetingName' => 'Max <Muster>', 'resetUrl' => 'https://kunde.example.ch/x?token=abc', 'validMinutes' => 30]);
check('reset mail: the link, the validity, the name escaped', str_contains($resetBody, 'https://kunde.example.ch/x?token=abc') && str_contains($resetBody, '30 Minuten') && str_contains($resetBody, 'Max &lt;Muster&gt;'));
$changedBody = $render('passwordChanged', ['greetingName' => 'Max Muster']);
check('change mail: carries no link, names whom to contact', !str_contains($changedBody, 'href') && str_contains($changedBody, 'Administrator'));

// ── (C) fields ──────────────────────────────────────────────────────────────
echo "(C) fields\n";
$legacy = [['id' => 40, 'username' => 'alt', 'password_hash' => 'x', 'roles' => ['admin'], 'sort_key' => 3, 'password_weak' => true]];
$storage = new FileStorage();
$keep    = $storage->load('framework/auth/backendUsers.json');
$storage->save('framework/auth/backendUsers.json', array_merge($keep, $legacy));
$old = $repo->find(40);
check('a store without the keys loads', $old instanceof BackendUser && $old->getEmail() === '' && $old->getAuthEpoch() === 0);
$uem->persist($old);
$uem->flush();
$row = array_values(array_filter($storage->load('framework/auth/backendUsers.json'), static fn(array $r): bool => $r['id'] === 40))[0];
check('…and saves with both keys, snake_case', array_key_exists('email', $row) && $row['email'] === '' && array_key_exists('auth_epoch', $row) && $row['auth_epoch'] === 0);
check('…the other fields survive', $row['username'] === 'alt' && $row['sort_key'] === 3 && $row['password_weak'] === true);

$bumped = $repo->find(40);
$bumped->bumpAuthEpoch();
$uem->persist($bumped);
$uem->flush();
check('the stamp round-trips through the file', $repo->find(40)->getAuthEpoch() === 1);

$body = BodyCleaner::cleanFor(BackendUser::class, ['auth_epoch' => 99, 'email' => "  MAX.Muster@Example.CH \n"]);
$fromForm = new BackendUser();
$fromForm->mapFromArray($body);
check('a form body cannot set the stamp', $fromForm->getAuthEpoch() === 0);
check('#[Clean] lower-cases and strips the address', $fromForm->getEmail() === 'max.muster@example.ch');
check('an array as address cleans to empty', BodyCleaner::cleanFor(BackendUser::class, ['email' => ['x']])['email'] === '');

$validate = function (string $email, ?int $id = null) use ($repo): BackendUserValidator {
    $user = new BackendUser(['username' => 'neu', 'email' => $email, 'roles' => [AuthRole::ADMIN]]);
    $validator = new BackendUserValidator($user, $repo, '', false, $id);
    $validator->isValid(['email']);
    return $validator;
};
check('the address is optional', !$validate('')->hasFieldError('email'));
check('a malformed address is refused', $validate('kein-format')->hasFieldError('email'));
check('a reserved TLD is refused', $validate('max@host.invalid')->hasFieldError('email'));
check('an over-long address is refused', $validate(str_repeat('a', 250) . '@example.ch')->hasFieldError('email'));
check('an address taken by another user is refused (any casing)', $validate('MAX.MUSTER@example.ch', 999)->hasFieldError('email'));
check('…but a user may keep his own', !$validate('max.muster@example.ch', $max->getId())->hasFieldError('email'));
$blur = new BackendUserValidator(new BackendUser(['email' => 'max.muster@example.ch']));
check('the blur check is format-only (no repo, no false alarm)', $blur->isValid(['email']));

check('findByEmail: case-insensitive', $repo->findByEmail('Max.Muster@EXAMPLE.ch')?->getId() === $max->getId());
check("findByEmail(''): nobody, although users have no address", $repo->findByEmail('') === null && $repo->findByEmail('  ') === null);
check('findByRecoveryIdentifier: username or address', $repo->findByRecoveryIdentifier('ERNA')?->getId() === $erna->getId() && $repo->findByRecoveryIdentifier('max.muster@example.ch')?->getId() === $max->getId());
check("findByRecoveryIdentifier(''): nobody", $repo->findByRecoveryIdentifier('') === null);

$resetValidator = fn(string $password, PasswordTier $tier) => (new BackendUserValidator($max, null, $password, true, $max->getId(), $tier))->isValid(['password']);
check('the reset uses the user form\'s rule: floor of 8', !$resetValidator('kurz', PasswordTier::Strong));
check('…a weak one passes under `strong` (nag only)', $resetValidator('passwort1', PasswordTier::Strong));
check('…and is refused under `veryStrong`', !$resetValidator('passwort1', PasswordTier::VeryStrong));

// ── (D) session stamp ───────────────────────────────────────────────────────
echo "(D) session stamp\n";
final class HarnessSession extends SessionManager
{
    public function regenerate(): void {}
}
$handler = (new ReflectionClass(ControllerHandler::class))->newInstanceWithoutConstructor();
$session = new HarnessSession();
$auth    = fn(): AuthService => new AuthService($session, $handler);
$stored  = function (string $username) use ($repo): BackendUser { return $repo->findByUsername($username); };

$_SESSION = [];
check('no session: guest, without touching the store', !$auth()->getCurrentUser()->isLoggedIn());

$service = $auth();
$service->canLogin($stored('max'), 'old-passwort-lang');
$service->login();
check('login writes the stamp into the session', ($_SESSION['auth_user']['auth_epoch'] ?? null) === 0);
check('…the session is valid on the next request', $auth()->getCurrentUser()->isLoggedIn());

unset($_SESSION['auth_user']['auth_epoch']);
check('a session from before the deploy (no stamp) stays valid', $auth()->getCurrentUser()->isLoggedIn());

$live = $stored('max');
$live->bumpAuthEpoch();
$uem->persist($live);
$uem->flush();
check('…until the password changes: a missing stamp reads 0', !$auth()->getCurrentUser()->isLoggedIn());
check('a stale session is removed, i.e. logged out', !isset($_SESSION['auth_user']));

$_SESSION['auth_user'] = ['id' => $max->getId(), 'user_name' => 'max', 'roles' => [AuthRole::ADMIN], 'realm' => AuthUser::REALM_BACKEND, 'auth_epoch' => 0];
$guest = $auth()->getCurrentUser();
check('stale stamp: guest identity (id 0), never null', $guest->getId() === 0 && !$guest->isLoggedIn());

$_SESSION['auth_user'] = ['id' => $max->getId(), 'user_name' => 'max', 'roles' => [AuthRole::ADMIN], 'realm' => AuthUser::REALM_BACKEND, 'auth_epoch' => 5];
check('a stamp ahead of the store is stale too', !$auth()->getCurrentUser()->isLoggedIn());

$_SESSION['auth_user'] = ['id' => 'acc-7', 'user_name' => 'Kundin', 'roles' => [AuthRole::CUSTOMER], 'realm' => AuthUser::REALM_MEMBER];
check('member realm: untouched by the stamp', $auth()->getCurrentUser()->getId() === 'acc-7' && isset($_SESSION['auth_user']));

$_SESSION['auth_user'] = ['id' => 4711, 'user_name' => 'weg', 'roles' => [AuthRole::ADMIN], 'realm' => AuthUser::REALM_BACKEND];
check('a user deleted from the store: guest', !$auth()->getCurrentUser()->isLoggedIn());

// A failed lookup: a container without the entity manager, as in an offline harness.
$_SESSION['auth_user'] = ['id' => $max->getId(), 'user_name' => 'max', 'roles' => [AuthRole::ADMIN], 'realm' => AuthUser::REALM_BACKEND];
DI::getInstance(true);
$threw = false;
$user  = null;
try {
    $user = (new AuthService($session, $handler))->getCurrentUser();
} catch (Throwable) {
    $threw = true;
}
check('a failed lookup does not fatal, the session stands', !$threw && $user?->isLoggedIn() === true);
DI::getInstance(true)->set('CacheManager', CacheManager::class, true);
DI::getInstance()->set('UnifiedEntityManager', $uem, true);

// A corrupt store throws on load: same answer.
$usersFile = ABS_BASE_PATH . '/data/framework/auth/backendUsers.json';
$goodUsers = (string) file_get_contents($usersFile);
file_put_contents($usersFile, '{corrupt');
$threw = false;
try {
    $user = $auth()->getCurrentUser();
} catch (Throwable) {
    $threw = true;
}
file_put_contents($usersFile, $goodUsers);
check('a corrupt user store does not fatal', !$threw && $user?->isLoggedIn() === true);

// The user changing his own password keeps THIS session, a second one ends.
$live = $stored('max');
$_SESSION['auth_user'] = ['id' => $max->getId(), 'user_name' => 'max', 'roles' => [AuthRole::ADMIN], 'realm' => AuthUser::REALM_BACKEND, 'auth_epoch' => $live->getAuthEpoch()];
$otherDevice = $_SESSION['auth_user'];
$live->bumpAuthEpoch();
$uem->persist($live);
$uem->flush();
$auth()->renewSessionStamp($live);
check('own change: this session carries the new stamp', $_SESSION['auth_user']['auth_epoch'] === $live->getAuthEpoch());
check('…and stays valid', $auth()->getCurrentUser()->isLoggedIn());
$thisDevice = $_SESSION['auth_user'];
$_SESSION['auth_user'] = $otherDevice;
check('…the other device is out', !$auth()->getCurrentUser()->isLoggedIn());

$_SESSION['auth_user'] = ['id' => $erna->getId(), 'user_name' => 'erna', 'roles' => [AuthRole::ADMIN], 'realm' => AuthUser::REALM_BACKEND, 'auth_epoch' => 0];
$auth()->renewSessionStamp($live);
check("an admin changing another user's password keeps his own stamp", $_SESSION['auth_user']['auth_epoch'] === 0 && $_SESSION['auth_user']['id'] === $erna->getId());
$_SESSION['auth_user'] = ['id' => (string) $live->getId(), 'user_name' => 'Kundin', 'roles' => [AuthRole::CUSTOMER], 'realm' => AuthUser::REALM_MEMBER];
$auth()->renewSessionStamp($live);
check('…a member session with a look-alike id is not stamped', !isset($_SESSION['auth_user']['auth_epoch']));
$_SESSION = [];
check('the rule is pure: no stamp = 0', AuthService::stampMatches([], new BackendUser()));

// ── (E) login throttle ──────────────────────────────────────────────────────
echo "(E) login throttle\n";
$dir = $base . '/throttle-login';
$t   = new LoginThrottle($dir);
$now = 1_800_000_000;
$ok  = 0;
for ($i = 0; $i < 7; $i++) {
    $ok += $t->tryAttempt('max', '203.0.113.1', $now) ? 1 : 0;
}
check('5 failures per username+IP, then refused', $ok === 5);
check('the casing of the username is the same counter', !$t->tryAttempt('MAX', '203.0.113.1', $now));
check('another address is not locked (nobody can lock a user out)', $t->tryAttempt('max', '203.0.113.2', $now));
check('another username from this address is not locked', $t->tryAttempt('erna', '203.0.113.1', $now));
check('the window ends after 15 minutes', $t->tryAttempt('max', '203.0.113.1', $now + 900));
check('the configured window is 15 minutes', LoginThrottle::WINDOW_SECONDS === 900);

$t  = new LoginThrottle($base . '/throttle-login-ip');
$ok = 0;
for ($i = 0; $i < 35; $i++) {
    $ok += $t->tryAttempt('user' . $i, '203.0.113.9', $now) ? 1 : 0;
}
check('30 failures per IP across usernames, then refused', $ok === 30);
check('…another address is free', $t->tryAttempt('user0', '203.0.113.10', $now));
$t  = new LoginThrottle($base . '/throttle-login-v6');
$ok = 0;
for ($i = 0; $i < 7; $i++) {
    $ok += $t->tryAttempt('max', '2001:db8:1:2::' . dechex($i + 1), $now) ? 1 : 0;
}
check('one IPv6 /64 is one address', $ok === 5);

$t  = new LoginThrottle($base . '/throttle-login-limits', 2, 3);
$ok = 0;
for ($i = 0; $i < 4; $i++) {
    $ok += $t->tryAttempt('max', '203.0.113.20', $now) ? 1 : 0;
}
check('the limits come from the constructor (config)', $ok === 2);

$t = new LoginThrottle($base . '/throttle-login-success');
for ($i = 0; $i < 4; $i++) {
    $t->tryAttempt('max', '203.0.113.3', $now);
}
$t->succeeded('max', '203.0.113.3', $now);
$ok = 0;
for ($i = 0; $i < 6; $i++) {
    $ok += $t->tryAttempt('max', '203.0.113.3', $now) ? 1 : 0;
}
check('success clears the username+IP counter', $ok === 5);

$t = new LoginThrottle($base . '/throttle-login-refund', 5, 3);
for ($i = 0; $i < 10; $i++) {
    if ($t->tryAttempt('max', '203.0.113.4', $now)) {
        $t->succeeded('max', '203.0.113.4', $now);
    }
}
check('successful logins do not count as failures of the address', $t->tryAttempt('erna', '203.0.113.4', $now));

$t = new LoginThrottle($base . '/throttle-login-usercap', 2, 4);
$t->tryAttempt('max', '203.0.113.6', $now);
$t->tryAttempt('max', '203.0.113.6', $now);
for ($i = 0; $i < 20; $i++) {
    $t->tryAttempt('max', '203.0.113.6', $now);
}
check('attempts refused by the username limit are no failures of the address', $t->tryAttempt('erna', '203.0.113.6', $now));

$t = new LoginThrottle($base . '/throttle-login-forgive');
for ($i = 0; $i < 30; $i++) {
    $t->tryAttempt('u' . ($i % 7), '203.0.113.5', $now);
}
check('before the reset: locked out', !$t->tryAttempt('max', '203.0.113.5', $now));
$t->forgive('max', '203.0.113.5');
check('a successful reset may log in right away from that address', $t->tryAttempt('max', '203.0.113.5', $now));

$counter = new FileThrottle($base . '/throttle-release');
$counter->allow('k', 2, 60, $now);
$counter->allow('k', 2, 60, $now);
check('FileThrottle: at the limit', !$counter->allow('k', 2, 60, $now));
$counter->release('k', 60, $now);
check('FileThrottle::release gives one attempt back', $counter->allow('k', 2, 60, $now));
$counter->release('nie-gezaehlt', 60, $now);
check('…releasing an unknown key is a no-op', !is_file($base . '/throttle-release/' . hash('sha256', 'nie-gezaehlt') . '-' . intdiv($now, 60)));
for ($i = 0; $i < 5; $i++) {
    $counter->release('k', 60, $now);
}
check('…and never goes below zero', $counter->allow('k', 2, 60, $now) && $counter->allow('k', 2, 60, $now) && !$counter->allow('k', 2, 60, $now));

// A file where the counter directory should be: mkdir fails, as behind a dangling symlink.
@unlink($logFile);
file_put_contents($base . '/blocked', 'x');
$t = new LoginThrottle($base . '/blocked/login');
check('no writable counters: login stays possible (fails open)', @$t->tryAttempt('max', '203.0.113.7', $now));
check('…and says so in the log', str_contains((string) @file_get_contents($logFile), 'counters unavailable'));

// ── (F) wiring ──────────────────────────────────────────────────────────────
echo "(F) wiring\n";
$root      = dirname(__DIR__);
$login     = (string) file_get_contents($root . '/packages/module-backend/src/Ui/Controllers/System/LoginController.php');
$userCtl   = (string) file_get_contents($root . '/packages/module-backend/src/Ui/Controllers/System/BackendUserController.php');
$recovery  = (string) file_get_contents($root . '/packages/module-backend/src/PasswordReset/PasswordRecovery.php');
$config    = require $root . '/packages/module-backend/src/App/Config/backendConfig.inc.php';
$authDef   = require $root . '/packages/kernel/core/src/Config/auth.default.inc.php';
$tplDir    = $root . '/packages/module-backend/res/view/templates/System/';

$job = $config['jobs']['password-reset-cleanup'] ?? [];
check('job registered, label «Passwort-Reset-Reste»', ($job['class'] ?? '') === PasswordResetCleanupJob::class && ($job['label'] ?? '') === 'Passwort-Reset-Reste');
check('job deletes, so it ships no schedule (jobs.md)', !array_key_exists('defaultSchedule', $job));
check('runs as cron identity', ($job['runAs'] ?? '') === AuthRole::CRON_JOB);
check('LoginController is GUEST, no action deviates, so forgot/reset are public', AuthService::requiredRole($config['moduleRole'], $config['controllers'], 'system', 'LoginController', 'forgotAction') === AuthRole::GUEST
    && AuthService::requiredRole($config['moduleRole'], $config['controllers'], 'system', 'LoginController', 'resetAction') === AuthRole::GUEST);

check('auth defaults carry the login limits (5 / 30)', $authDef['loginFailuresPerUsernameIp'] === 5 && $authDef['loginFailuresPerIp'] === 30);
check('the password tier default is untouched', $authDef['passwordTier'] === PasswordTier::Strong->value);

$order = [
    strpos($login, "isValid(['password'])"),
    strpos($login, '$this->passesSecondFactor($user)'),
    strpos($login, '$tokens->consume($token)'),
    strpos($login, '$user->setPasswordHash($hash)'),
    strpos($login, '$user->bumpAuthEpoch()'),
    strpos($login, 'notifyPasswordChanged($user)'),
];
$sorted = $order;
sort($sorted);
check('reset POST order: validate → second factor → consume → write → notify', !in_array(false, $order, true) && $order === $sorted);
check('exactly one call of the second-factor seam', substr_count($login, 'passesSecondFactor(') === 2);
check('…which returns true today', preg_match('/passesSecondFactor\(BackendUser \$user\): bool\s*\{\s*return true;/', $login) === 1);
check('no automatic login after the reset', strpos($login, '$auth->login()') < strpos($login, 'private function resolvePostLoginRedirect') && !str_contains(substr($login, strpos($login, 'private function handleResetPost'), strpos($login, 'private function passesSecondFactor') - strpos($login, 'private function handleResetPost')), 'login()'));
check('the reset page sends Referrer-Policy: no-referrer', str_contains($login, "header('Referrer-Policy: no-referrer')"));
check('login: throttle before the password check', strpos($login, '$throttle->tryAttempt') < strpos($login, 'canLogin($user, $password)'));
check('login: the neutral throttle message, verbatim', str_contains($login, 'Zu viele Fehlversuche, bitte in 15 Minuten erneut versuchen.'));
check('login: success clears the counter before the session starts', strpos($login, '$throttle->succeeded') < strpos($login, '$auth->login()'));
check('forgot: the neutral answer, verbatim, once', substr_count($login, 'Falls das Konto existiert, ist eine E-Mail unterwegs.') === 1);
check('forgot: request() returns nothing the controller could show', str_contains($recovery, 'public function request(string $identifier, ?string $clientIp, ?int $now = null): void'));
check('recovery reads no Host header', !str_contains($recovery, 'HTTP_HOST') && !str_contains($recovery, 'getHost') && str_contains($recovery, 'getBaseUrl()'));
check('an empty CSRF token is refused (no stored token compares equal)', str_contains($login, "\$token !== '' && DI::getCsrfService()->validate(\$token)"));
check('no superglobal in the controller (Rule 4)', !preg_match('/\$_(GET|POST|SERVER)\b/', $login));

check('login page links «Passwort vergessen?»', str_contains((string) file_get_contents($tplDir . 'LoginController/loginAction.tpl.php'), 'href="/backend/system/login/forgot">Passwort vergessen?'));
check('reset form: both password fields, the token hidden, no token in the action', (static function (string $tpl): bool {
    return str_contains($tpl, 'name="password_repeat"') && str_contains($tpl, 'name="token"') && str_contains($tpl, 'action="/backend/system/login/reset"') && str_contains($tpl, 'data-z77-password-min');
})((string) file_get_contents($tplDir . 'LoginController/resetAction.tpl.php')));
$edit = (string) file_get_contents($tplDir . 'BackendUserController/edit.tpl.php');
check('user form: the address field with its label and error', str_contains($edit, 'E-Mail für Passwort-Reset') && str_contains($edit, 'name="email"') && str_contains($edit, "fieldError('email')"));
check('user form: «n Fehler» knows the field', str_contains($edit, "'email' => 'bu-email'"));

check('admin password change: bumps the stamp, not for a new user', preg_match('/elseif \(\$password !== \'\'\) \{\s*\$user->bumpAuthEpoch\(\);/', $userCtl) === 1);
check('…renews the session of the user changing his own', str_contains($userCtl, 'DI::getAuthService()->renewSessionStamp($user)'));
check('…and ends an open reset link', str_contains($userCtl, '->revokeFor('));

// ── (G) the real flow, in PHP's built-in server ─────────────────────────────
echo "(G) real flow (built-in server)\n";
$site = $base . '/site';
realFlowSite($site, $root);
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port  = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$server = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $base . '/sessions', '-S', "127.0.0.1:{$port}", '-t', $site . '/public', $site . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $base . '/server.log', 'w'], 2 => ['file', $base . '/server.log', 'a']],
    $serverPipes,
    $site
);
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
    }
});
mkdir($base . '/sessions');
$deadline = microtime(true) + 10;
do {
    usleep(100000);
    $sock = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
} while ($sock === false && microtime(true) < $deadline);
if ($sock !== false) {
    fclose($sock);
}

/** One browser: a cookie jar and raw requests, redirects NOT followed. */
$browser = function () use ($port): Closure {
    $cookies = [];
    return function (string $path, array|string|null $post = null, array $headers = []) use (&$cookies, $port): array {
        $head = $headers;
        if ($cookies !== []) {
            $pairs = [];
            foreach ($cookies as $k => $v) {
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
$csrf = static function (string $html): string {
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
};
$outbox = static function () use ($site): array {
    $files = glob($site . '/data/framework/mail/outbox/*.eml') ?: [];
    sort($files);
    return $files;
};
$decoded = static function (string $file): string {
    return $file === '' ? '' : quoted_printable_decode((string) file_get_contents($file));
};

$b = $browser();
[$s, , $html] = $b('/login');
check('GET /login (alias): 200, the link is there', $s === 200 && str_contains($html, 'href="/backend/system/login/forgot"'), "status {$s}");

[$s, , $html] = $b('/backend/system/login/forgot');
check('GET forgot: 200 for a guest, in the guest layout', $s === 200 && str_contains($html, 'be-guest') && str_contains($html, 'name="identifier"'), "status {$s}");
$token = $csrf($html);

[$s, , $html] = $b('/backend/system/login/forgot', ['identifier' => 'max']);
check('POST without CSRF token: no answer, no mail', str_contains($html, 'Sitzung abgelaufen') && !str_contains($html, 'unterwegs') && $outbox() === []);

$answers = [];
foreach (['niemand', 'erna', 'max'] as $identifier) {
    // With a forged Host: the mail must not care.
    [$s, , $html] = $b('/backend/system/login/forgot', ['csrf_token' => $token, 'identifier' => $identifier], ['Host: evil.example.com']);
    preg_match('/login__notice[^>]*>([^<]*)</', $html, $m);
    $answers[$identifier] = [$s, $m[1] ?? ''];
}
check('POST forgot: the same neutral answer for unknown, no address, existing',
    $answers['niemand'] === $answers['erna'] && $answers['erna'] === $answers['max'] && $answers['max'] === [200, 'Falls das Konto existiert, ist eine E-Mail unterwegs.'], json_encode($answers, JSON_UNESCAPED_UNICODE));
check('exactly one mail, for the account with an address', count($outbox()) === 1);

$mail = $decoded($outbox()[0] ?? '');
preg_match('#https?://[^\s"<>]+/backend/system/login/reset\?token=([a-f0-9]{64})#', $mail, $link);
check('the mail carries the link, origin = canonicalBaseUrl', ($link[0] ?? '') !== '' && str_starts_with($link[0], 'https://kunde.example.ch/'), substr($mail, 0, 200));
check('the forged Host is nowhere in the mail', !str_contains($mail, 'evil.example.com'));
check('recipient and default sender', str_contains($mail, 'max.muster@example.ch') && str_contains($mail, 'no-reply@example.ch'));
check('text part and HTML part', str_contains($mail, 'text/plain') && str_contains($mail, 'text/html'));
$plainToken = $link[1] ?? '';
$resetPath  = '/backend/system/login/reset?token=' . $plainToken;
check('only the hash is stored', !str_contains((string) file_get_contents($site . '/data/framework/auth/passwordResets.json'), $plainToken));

// Session A of the user, to be ended by the reset; session C of another user, to survive.
$a = $browser();
[, , $html] = $a('/login');
[$s, $h] = $a('/backend/system/login/login', ['csrf_token' => $csrf($html), 'username' => 'max', 'password' => 'Altes-Passwort-123']);
check('login with the old password', $s === 302 || $s === 303, "status {$s}");
[$s] = $a('/backend/system/dashboard/overview');
check('session A: the backend answers', $s === 200, "status {$s}");
$c = $browser();
[, , $html] = $c('/login');
$c('/backend/system/login/login', ['csrf_token' => $csrf($html), 'username' => 'erna', 'password' => 'Altes-Passwort-123']);
[$s] = $c('/backend/system/dashboard/overview');
check('session C of another user: the backend answers', $s === 200, "status {$s}");

// Locks himself out from the browser's address before resetting.
[, , $html] = $b('/login');
$loginToken = $csrf($html);
for ($i = 0; $i < 5; $i++) {
    [, , $html] = $b('/backend/system/login/login', ['csrf_token' => $loginToken, 'username' => 'max', 'password' => 'falsch-falsch']);
}
check('5 wrong passwords: the generic error', str_contains($html, 'Ungültige Anmeldedaten.'));
[, , $html] = $b('/backend/system/login/login', ['csrf_token' => $loginToken, 'username' => 'max', 'password' => 'Altes-Passwort-123']);
check('6th attempt, right password: the neutral throttle message', str_contains($html, 'Zu viele Fehlversuche, bitte in 15 Minuten erneut versuchen.'));

// Unused token form in a fresh browser: the scanner case.
$scanner = $browser();
[$s, $h, $html] = $scanner($resetPath);
check('GET reset: 200, the form', $s === 200 && str_contains($html, 'name="password_repeat"') && str_contains($html, 'be-guest'), "status {$s}");
check('GET reset: Referrer-Policy: no-referrer', ($h['referrer-policy'] ?? '') === 'no-referrer');
check('GET reset: the show-password toggle is loaded', str_contains($html, 'password-toggle'));
[$s, , $html] = $b($resetPath);
check('GET again (mail scanner went first): still the form', str_contains($html, 'name="password_repeat"'));
$resetCsrf = $csrf($html);

[, , $html] = $b('/backend/system/login/reset', ['csrf_token' => 'falsch', 'token' => $plainToken, 'password' => 'Neues-Passwort-456', 'password_repeat' => 'Neues-Passwort-456']);
check('POST with a wrong CSRF token: form again, nothing consumed', str_contains($html, 'Sitzung abgelaufen') && str_contains($html, 'name="password_repeat"'));
[, , $html] = $b('/backend/system/login/reset', ['csrf_token' => $resetCsrf, 'token' => $plainToken, 'password' => 'Neues-Passwort-456', 'password_repeat' => 'Anderes-Passwort-789']);
check('two different passwords: refused, nothing consumed', str_contains($html, 'stimmen nicht überein') && str_contains($html, 'name="password_repeat"'));
[, , $html] = $b('/backend/system/login/reset', ['csrf_token' => $resetCsrf, 'token' => $plainToken, 'password' => 'kurz', 'password_repeat' => 'kurz']);
check('below the floor: refused with the policy message, nothing consumed', str_contains($html, 'mindestens 8 Zeichen') && str_contains($html, 'name="password_repeat"'));
$before = count($outbox());
[, , $html] = $b('/backend/system/login/reset', ['csrf_token' => $resetCsrf, 'token' => str_repeat('c', 64), 'password' => 'Neues-Passwort-456', 'password_repeat' => 'Neues-Passwort-456']);
check('an unknown token: the one neutral page with a link to request a new one', str_contains($html, 'Link nicht gültig') && str_contains($html, 'href="/backend/system/login/forgot"') && count($outbox()) === $before);

$mailsBeforeReset = $outbox();
[$s, $h] = $b('/backend/system/login/reset', ['csrf_token' => $resetCsrf, 'token' => $plainToken, 'password' => 'Neues-Passwort-456', 'password_repeat' => 'Neues-Passwort-456']);
check('POST reset: redirect to the login', ($s === 302 || $s === 303) && ($h['location'] ?? '') === '/login', "status {$s} " . ($h['location'] ?? ''));
[$s, , $html] = $b('/login');
check('the login page shows the success flash', str_contains($html, 'Dein Passwort wurde geändert'));
check('no automatic login: the browser is still a guest', $b('/backend/system/dashboard/overview')[0] !== 200);

$mails = $outbox();
// By name two mails of one second sort in any order (FileTransport: a clock fragment), so not end().
$changedFile = current(array_diff($mails, $mailsBeforeReset)) ?: '';
$changed = $decoded($changedFile);
preg_match('/^Subject: (\S+)/m', (string) @file_get_contents($changedFile), $subject);
check('second mail: «Dein Passwort wurde geändert», without a link', count($mails) === 2
    && iconv_mime_decode(trim($subject[1] ?? ''), 0, 'UTF-8') === 'Dein Passwort wurde geändert'
    && str_contains($changed, 'soeben') && !str_contains($changed, 'href=') && !str_contains($changed, 'token='), trim($subject[1] ?? ''));

[, , $html] = $b($resetPath);
check('the used link: the neutral page', str_contains($html, 'Link nicht gültig'));
[, , $html] = $b('/backend/system/login/reset', ['csrf_token' => $resetCsrf, 'token' => $plainToken, 'password' => 'Drittes-Passwort-789', 'password_repeat' => 'Drittes-Passwort-789']);
check('POST a second time: the neutral page, the password stays', str_contains($html, 'Link nicht gültig'));

check('the token never entered the application log', !str_contains((string) @file_get_contents($site . '/logs/php-error.log'), $plainToken));

[$s] = $a('/backend/system/dashboard/overview');
check('session A of that user is over', $s !== 200, "status {$s}");
[$s] = $c('/backend/system/dashboard/overview');
check('session C of another user continues', $s === 200, "status {$s}");

[, , $html] = $b('/login');
[$s] = $b('/backend/system/login/login', ['csrf_token' => $csrf($html), 'username' => 'max', 'password' => 'Altes-Passwort-123']);
check('the old password is refused', $s === 200);
[, , $html] = $b('/login');
[$s] = $b('/backend/system/login/login', ['csrf_token' => $csrf($html), 'username' => 'max', 'password' => 'Neues-Passwort-456']);
check('login with the new password, right away from the locked address', $s === 302 || $s === 303, "status {$s}");
[$s] = $b('/backend/system/dashboard/overview');
check('the backend answers', $s === 200, "status {$s}");

$users = json_decode((string) file_get_contents($site . '/data/framework/auth/backendUsers.json'), true);
$byName = array_column($users, null, 'username');
check('stored: new bcrypt hash (cost 12), stamp raised, weak flag computed', str_starts_with($byName['max']['password_hash'], '$2y$12$') && $byName['max']['auth_epoch'] === 1 && $byName['max']['password_weak'] === false);
check('the other user is untouched', ($byName['erna']['auth_epoch'] ?? 0) === 0);

// The fatal-free answer on a stale session reaches the framework's redirect, not a 500.
[$s, $h] = $a('/backend/system/dashboard/overview');
check('a stale session is sent to the login, no 500', in_array($s, [302, 303], true) && str_contains($h['location'] ?? '', 'login'), "status {$s} " . ($h['location'] ?? ''));

// The admin path in the user form: own change keeps the session, the others end.
$phone = $browser();
[, , $html] = $phone('/login');
$phone('/backend/system/login/login', ['csrf_token' => $csrf($html), 'username' => 'max', 'password' => 'Neues-Passwort-456']);
check('a second session of the same user', $phone('/backend/system/dashboard/overview')[0] === 200);
$fetch = ['Sec-Fetch-Mode: cors'];
$appToken = static function (Closure $who): string {
    [, , $page] = $who('/backend/system/backend-user/list');
    return preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m) ? $m[1] : '';
};
$saveUser = static function (Closure $who, int $id, array $fields) use ($fetch, $appToken): array {
    $token = $appToken($who);
    [, , $form] = $who('/backend/system/backend-user/edit?id=' . $id, null, $fetch);
    $entity = preg_match('/name=\\\\?"entity_csrf\\\\?" value=\\\\?"([a-f0-9]+)/', $form, $m) ? $m[1] : '';
    return $who('/backend/system/backend-user/edit?id=' . $id, json_encode($fields + ['entity_csrf' => $entity, 'roles' => ['admin'], 'identity_name' => [], 'identity_email' => []]), array_merge($fetch, ['X-CSRF-Token: ' . $token]));
};
$storedUser = static function (string $username) use ($site): array {
    return array_column(json_decode((string) file_get_contents($site . '/data/framework/auth/backendUsers.json'), true), null, 'username')[$username];
};

[$s, , $form] = $b('/backend/system/backend-user/edit?id=1', null, $fetch);
check('user form (fetch): the new field with its label and value', $s === 200 && str_contains($form, 'E-Mail für Passwort-Reset') && str_contains($form, 'max.muster@example.ch'), "status {$s}");

[, , $answer] = $saveUser($c, 2, ['username' => 'erna', 'email' => 'MAX.MUSTER@example.ch', 'password' => '']);
check('user form: an address another user has is refused', str_contains($answer, 'bereits vergeben') && ($storedUser('erna')['email'] ?? '') !== 'max.muster@example.ch', substr($answer, 0, 200));
[, , $answer] = $saveUser($c, 2, ['username' => 'erna', 'email' => 'Erna.Beispiel@Example.ch', 'password' => '', 'auth_epoch' => 40]);
check('user form: an address is stored cleaned', $storedUser('erna')['email'] === 'erna.beispiel@example.ch', substr($answer, 0, 200));
check('user form: the body cannot set the stamp', $storedUser('erna')['auth_epoch'] === 0);
check('user form: saving without a password keeps the session', $c('/backend/system/dashboard/overview')[0] === 200);

$phone = $browser();
[, , $html] = $phone('/login');
$phone('/backend/system/login/login', ['csrf_token' => $csrf($html), 'username' => 'max', 'password' => 'Neues-Passwort-456']);
check('a second session of the same user', $phone('/backend/system/dashboard/overview')[0] === 200);

// An open link dies with the admin's change; used to prove it below.
$mailsBefore = $outbox();
$linkBrowser = $browser();
[, , $html] = $linkBrowser('/backend/system/login/forgot');
$linkBrowser('/backend/system/login/forgot', ['csrf_token' => $csrf($html), 'identifier' => 'max']);
$all = $outbox();
preg_match('#reset\?token=([a-f0-9]{64})#', $decoded(current(array_diff($all, $mailsBefore)) ?: ''), $open);
check('a new link for the admin path', count($all) === count($mailsBefore) + 1 && ($open[1] ?? '') !== '');

$epoch = $storedUser('max')['auth_epoch'];
[, , $answer] = $saveUser($b, 1, ['username' => 'max', 'name' => 'Max Muster', 'email' => 'max.muster@example.ch', 'password' => 'Viertes-Passwort-012']);
check('own password change in the user form: saved, stamp raised', $storedUser('max')['auth_epoch'] === $epoch + 1 && str_contains($answer, 'success'), substr($answer, 0, 200));
check('…this session stays', $b('/backend/system/dashboard/overview')[0] === 200);
check('…the other session of that user ends', $phone('/backend/system/dashboard/overview')[0] !== 200);
[, , $html] = $linkBrowser('/backend/system/login/reset?token=' . ($open[1] ?? ''));
check('…and the open reset link is dead', str_contains($html, 'Link nicht gültig'));

[, , $answer] = $saveUser($c, 1, ['username' => 'max', 'name' => 'Max Muster', 'email' => 'max.muster@example.ch', 'password' => 'Fuenftes-Passwort-345']);
check("an admin changing another user's password: that user's session ends", $b('/backend/system/dashboard/overview')[0] !== 200, substr($answer, 0, 200));
check('…the admin stays', $c('/backend/system/dashboard/overview')[0] === 200);

proc_terminate($server);
proc_close($server);

ini_restore('error_log');
rrmdir($base);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

/**
 * A minimal installation under $site: the kernel's seed data, flat config, 'file' mail transport.
 * The packages are read in place through fileFinder and a stub autoloader. Deliberately no symlink
 * or junction out of the temp tree: the clean-up at the end must not be able to reach the repo.
 */
function realFlowSite(string $site, string $root): void
{
    foreach (['public', 'config', 'vendor', 'data/framework/auth', 'var/state', 'var/lib', 'logs'] as $dir) {
        mkdir($site . '/' . $dir, 0777, true);
    }
    file_put_contents($site . '/vendor/autoload.php', "<?php\nreturn require " . var_export($root . '/vendor/autoload.php', true) . ";\n");

    $seed = $root . '/packages/kernel/core/data/framework';
    $it   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($seed, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.default.json')) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($seed)));
        $to  = $site . '/data/framework' . substr($rel, 0, -strlen('.default.json')) . '.json';
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0777, true);
        }
        copy($file->getPathname(), $to);
    }

    $config = static function (string $name, array $values) use ($site): void {
        file_put_contents($site . '/config/' . $name . '.inc.php', "<?php\nreturn " . var_export($values, true) . ";\n");
    };
    $config('bootstrap', ['cacheDebug' => false, 'htmlRoot' => 'public', 'apcuCachePrefix' => 'z77test' . getmypid(), 'overrideDir' => 'override',
        'moduleDir' => 'module', 'assetDir' => 'assets', 'tplDir' => 'res/view/templates', 'timeZone' => 'Europe/Zurich']);
    $config('systemConfig', ['canonicalBaseUrl' => 'https://kunde.example.ch']);
    $config('auth', ['passwordTier' => 'strong']);
    $config('i18n', ['defaultLanguage' => 'de', 'languages' => ['de']]);
    $config('mail', ['enabled' => true, 'transport' => 'file', 'outbox' => $site . '/data/framework/mail/outbox', 'fromAddress' => 'no-reply@example.ch', 'fromName' => 'Beispiel']);
    $config('moduleManager', ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'frontend', 'currentModule' => null, 'modules' => ['frontend' => [], 'backend' => []]]);
    $packages = var_export(str_replace('\\', '/', $root) . '/packages/', true);
    file_put_contents($site . '/config/fileFinder.inc.php', <<<PHP
<?php
\$packages = {$packages};
\$entry = fn(string \$package) => [
    'sourcePaths' => [\$packages . \$package],
    'assetPaths'  => [ABS_BASE_PATH . '/public/assets/' . str_replace('module-', '', basename(\$package))],
];
return [
    'resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'],
    'namespaces' => [
        'Z77\\\\Core\\\\'             => \$entry('kernel/core'),
        'Z77\\\\Shared\\\\'           => \$entry('kernel/shared'),
        'Z77\\\\Persistence\\\\'      => \$entry('kernel/persistence'),
        'Z77\\\\Module\\\\Frontend\\\\' => \$entry('module-frontend'),
        'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),
    ],
];
PHP);

    $hash  = password_hash('Altes-Passwort-123', PASSWORD_BCRYPT, ['cost' => 4]);
    $users = [
        ['id' => 1, 'username' => 'max', 'name' => 'Max Muster', 'email' => 'max.muster@example.ch', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 0],
        ['id' => 2, 'username' => 'erna', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 1],
    ];
    file_put_contents($site . '/data/framework/auth/backendUsers.json', json_encode($users, JSON_PRETTY_PRINT));

    // The installer copies assets into public/ too; the JS manager writes versioned copies beside
    // them, so reading them from the packages would litter the repository.
    foreach (['kernel/shared' => 'shared', 'module-backend' => 'backend'] as $package => $asset) {
        $source = $root . '/packages/' . $package . '/res/assets';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
            $to = $site . '/public/assets/' . $asset . str_replace('\\', '/', substr($file->getPathname(), strlen($source)));
            if ($file->isFile()) {
                if (!is_dir(dirname($to))) {
                    mkdir(dirname($to), 0777, true);
                }
                copy($file->getPathname(), $to);
            }
        }
    }

    file_put_contents($site . '/public/index.php', file_get_contents($root . '/packages/kernel/core/public/index.php'));
    file_put_contents($site . '/router.php', <<<'PHP'
<?php
// The built-in server's DOCUMENT_ROOT is what index.php derives the project from.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) {
    return false;
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
PHP);
}
