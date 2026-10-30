<?php

/**
 * Operator invitation harness (CLI, offline, file stores in a temp dir) — F2,
 * z77-rudaz.ch 2026-10-30.
 *
 * `InvitationFlow::inviteAsOperator()` lets a BACKEND user invite where no member
 * may. What is load-bearing:
 *
 *   (A) the operator's invitation: SENT, one mail to the address, the operator's
 *       NAME in the mail, the token carries `operator:<id>` as inviter;
 *   (B) the guards: no name / no id / bad address → INVALID, nothing sent;
 *   (C) redemption of that token: the joinHook receives `operator:<id>` verbatim,
 *       the new account is born `confirmed`;
 *   (D) the member path is unchanged: invite() still names the member and keeps
 *       the member id on the token.
 *
 * Run: php tests/member-operator-invite.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "member-operator-invite: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}

$base = sys_get_temp_dir() . '/z77-operator-invite-' . bin2hex(random_bytes(4));
mkdir($base . '/data', 0777, true);
define('ABS_BASE_PATH', str_replace('\\', '/', $base));

require $autoload;
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';

// The monorepo autoloader does not map module-member — same loader as
// api-contract-throttle.php.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Z77\\Module\\Member\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../packages/module-member/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Z77\Module\Member\Entities\MemberAccount;
use Z77\Module\Member\Services\InvitationFlow;
use Z77\Module\Member\Services\MemberAccounts;
use Z77\Module\Member\Services\MemberThrottle;
use Z77\Module\Member\Services\TokenService;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Mail\EmailMessage;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

// The file persistence asks DI for the CacheManager — the minimal container
// (same shape as entity-announcement.php).
Z77\Core\DI::getInstance(true)->set('CacheManager', Z77\Core\Libraries\CacheManager::class, true);

$uem      = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
$accounts = new MemberAccounts($uem);
$tokens   = new TokenService($uem);
$mails    = [];
$joins    = [];

$flow = new InvitationFlow(
    $accounts,
    $tokens,
    new MemberThrottle($base . '/throttle'),
    static function (EmailMessage $mail) use (&$mails): bool { $mails[] = $mail; return true; },
    static fn(MemberAccount $a, array $i): bool => true,
    'https://example.test/member/main/register',
    static fn(string $ref): string => 'Projekt ' . $ref,
    static fn(MemberAccount $a, string $ref): bool => false,
    static function (MemberAccount $a, string $ref, ?string $by) use (&$joins): void { $joins[] = [$a->getEmail(), $ref, $by]; },
);

/** The plaintext token is only in the mail — read it back out of the link. */
function tokenFrom(EmailMessage $mail): string
{
    $ctx = $mail->getContext();
    preg_match('/invite=([^&]+)/', (string)($ctx['inviteUrl'] ?? ''), $m);
    return urldecode($m[1] ?? '');
}

echo "(A) operator invitation\n";
$out = $flow->inviteAsOperator('Adrian Muster', 7, 'p12', 'Neu@Example.ch');
check('SENT', $out === InvitationFlow::SENT, $out);
check('one mail', count($mails) === 1, (string)count($mails));
$ctx = $mails ? ($mails[0]->getContext()) : [];
check('mail names the operator', ($ctx['inviter'] ?? null) === 'Adrian Muster', json_encode($ctx['inviter'] ?? null));
$token = $flow->inspect(tokenFrom($mails[0] ?? new EmailMessage()));
check('token carries operator:7', $token !== null && $token->getInvitedBy() === 'operator:7', (string)$token?->getInvitedBy());

echo "(B) guards\n";
$before = count($mails);
check('no name → INVALID', $flow->inviteAsOperator('  ', 7, 'p12', 'a@example.ch') === InvitationFlow::INVALID);
check('no id → INVALID', $flow->inviteAsOperator('Adrian', 0, 'p12', 'a@example.ch') === InvitationFlow::INVALID);
check('bad address → INVALID', $flow->inviteAsOperator('Adrian', 7, 'p12', 'not-an-address') === InvitationFlow::INVALID);
check('nothing sent', count($mails) === $before);

echo "(C) redemption\n";
$res = $flow->redeem(tokenFrom($mails[0]), 'Neu', 'Person');
check('account created confirmed', ($res['account'] ?? null) instanceof MemberAccount && $res['account']->isConfirmed(), json_encode($res['outcome'] ?? null));
check('joinHook got operator:7', $joins === [['neu@example.ch', 'p12', 'operator:7']], json_encode($joins));

echo "(D) member path unchanged\n";
$inviter = $res['account'];
$inviter->setFirstName('Erika');
$inviter->setLastName('Beispiel');
$mails = [];
check('SENT', $flow->invite($inviter, 'p12', 'kollege@example.ch') === InvitationFlow::SENT);
$ctx = $mails ? ($mails[0]->getContext()) : [];
check('mail names the member', ($ctx['inviter'] ?? null) === 'Erika Beispiel', json_encode($ctx['inviter'] ?? null));
$token = $flow->inspect(tokenFrom($mails[0] ?? new EmailMessage()));
check('token carries the member id', $token !== null && $token->getInvitedBy() === (string)$inviter->getId(), (string)$token?->getInvitedBy());

// clean up the temp installation
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($base);

echo "\n{$pass} ok, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
