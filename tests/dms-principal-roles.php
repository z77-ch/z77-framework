<?php

/**
 * DMS principal roles harness (CLI, offline, no DI) — F1, z77-rudaz.ch 2026-10-30.
 *
 * `dmsConfig['principalRolesHook']` lets a project name extra roles for a member, so a
 * role ACE opens a folder to SOME members instead of every `customer`. What is
 * load-bearing:
 *
 *   (A) `PrincipalRoles::sanitize()`: well-formed names pass in order, duplicates and
 *       non-strings drop, and NO framework role passes — not even in another case;
 *   (B) `PrincipalRoles::fromHook()`: no hook / unknown class → []; a hook's answer is
 *       sanitized; a hook that throws or returns a non-array grants nothing;
 *   (C) `Principal::fromAuthUser()`: a backend user never asks the hook (no DI touched)
 *       and keeps its id; the roles are the user's own.
 *
 * Run: php tests/dms-principal-roles.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "dms-principal-roles: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Z77\Core\Config\AuthRole;
use Z77\Module\Dms\Services\PrincipalRoles;
use Z77\Module\Dms\ValueObjects\Principal;
use Z77\Shared\Auth\AuthUser;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

final class HarnessRolesHook
{
    public function __invoke(AuthUser $user): array
    {
        return ['rudaz-p12-read', 'rudaz-p12-read', 'admin', 'SuperUser', 'Bad Name', 42, ' rudaz-p7-write '];
    }
}

final class HarnessThrowingHook
{
    public function __invoke(AuthUser $user): array
    {
        throw new \RuntimeException('store unreadable');
    }
}

final class HarnessGarbageHook
{
    public function __invoke(AuthUser $user): string
    {
        return 'rudaz-p12-read';
    }
}

$reserved = array_keys(AuthRole::getRoleHierarchy());
$member   = new AuthUser(['id' => 'm-abc123', 'user_name' => 'x@example.ch', 'roles' => ['customer'], 'realm' => AuthUser::REALM_MEMBER]);

echo "(A) sanitize\n";
$got = PrincipalRoles::sanitize(['p-1', 'p-1', 'p_2.x', '', '-lead', 'UPPER', str_repeat('a', 101), null, ['x']], $reserved);
check('well-formed names in order, duplicates and malformed dropped', $got === ['p-1', 'p_2.x'], json_encode($got));
$frameworkRoles = PrincipalRoles::sanitize($reserved, $reserved);
check('no framework role passes', $frameworkRoles === [], json_encode($frameworkRoles));
$caseVariants = PrincipalRoles::sanitize(array_map('strtolower', $reserved), $reserved);
check('not even in lower case', $caseVariants === [], json_encode($caseVariants));

echo "(B) fromHook\n";
check('empty FQCN → []', PrincipalRoles::fromHook('', $member) === []);
check('unknown class → []', PrincipalRoles::fromHook('No\\Such\\Hook', $member) === []);
$got = PrincipalRoles::fromHook(HarnessRolesHook::class, $member);
check('answer sanitized (admin/SuperUser/bad dropped, trimmed, unique)', $got === ['rudaz-p12-read', 'rudaz-p7-write'], json_encode($got));
$logged = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
check('throwing hook grants nothing', PrincipalRoles::fromHook(HarnessThrowingHook::class, $member) === []);
ini_set('error_log', (string)$logged);
check('non-array answer grants nothing', PrincipalRoles::fromHook(HarnessGarbageHook::class, $member) === []);

echo "(C) Principal::fromAuthUser — backend path never asks the hook\n";
$backend = new AuthUser(['id' => 7, 'user_name' => 'admin', 'roles' => [AuthRole::ADMIN], 'realm' => AuthUser::REALM_BACKEND]);
check('backend: no extra roles (no DI needed)', PrincipalRoles::for($backend) === []);
$p = Principal::fromAuthUser($backend);
check('backend: id kept, roles are its own', $p->userId === 7 && $p->roles === [AuthRole::ADMIN], json_encode([$p->userId, $p->roles]));

echo "\n{$pass} ok, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
