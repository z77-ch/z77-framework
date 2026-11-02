<?php

/**
 * State-changing GET (CLI, real stack in PHP's built-in server) — security.md SEC-GET-001. For each
 * action that changes state: a GET changes nothing and answers as a wrong method does (404; the logout: its confirm page), a POST
 * without a token is refused, a POST with the token works. Backend logout, member logout and every
 * profile action. Also what replaced the links: a form with the session's token in the backend
 * topbar, the frontend overlay and the member's account panel, and no link left.
 *
 * What it cannot prove: how the buttons look (that took a browser, see security.md).
 *
 * Run: php tests/state-changing-get.php
 */

use Z77\Shared\Auth\Totp;
use Z77\Shared\Auth\TotpVault;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "state-changing-get: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;
require_once __DIR__ . '/support/real-flow-site.php';
require_once __DIR__ . '/support/real-flow-server.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n";
    }
}

$root = dirname(__DIR__);
$base = sys_get_temp_dir() . '/z77-state-get-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($base, 0777, true);
register_shutdown_function(static function () use ($base): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($base);
});

$site = $base . '/site';
$hash = password_hash('Passwort-Sehr-Lang-1', PASSWORD_BCRYPT, ['cost' => 4]);
realFlowSite($site, $root, [
    ['id' => 1, 'username' => 'max', 'name' => 'Max Muster', 'email' => 'max.muster@example.ch', 'password_hash' => $hash, 'roles' => ['superUser'], 'sort_key' => 0],
]);
realFlowAddMember(
    $site,
    $root,
    ['membershipHook' => 'StateGetMembershipHook'],
    <<<'PHP'
final class StateGetMembershipHook
{
    public function __invoke($account): array
    {
        return [['ref' => 'a', 'label' => 'Erste Verwaltung', 'usable' => true], ['ref' => 'b', 'label' => 'Zweite Verwaltung', 'usable' => true]];
    }
}
PHP
);

// The frontend's starter pages and assets: the overlay and the page cache need a real page.
mkdir($site . '/data/content', 0777, true);
foreach (glob($root . '/packages/kernel/core/data/content/*.default.json') as $seed) {
    copy($seed, $site . '/data/content/' . str_replace('.default.json', '.json', basename($seed)));
}
$source = $root . '/packages/module-frontend/res/assets';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
    $to = $site . '/public/assets/frontend' . str_replace('\\', '/', substr($file->getPathname(), strlen($source)));
    if ($file->isFile()) {
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0777, true);
        }
        copy($file->getPathname(), $to);
    }
}

$accountsFile = $site . '/data/framework/member/accounts.json';
$tokensFile   = $site . '/data/framework/member/tokens.json';
$secret       = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
$vault        = new TotpVault($site . '/data/framework/member/totp.key');
$until        = date(DATE_ATOM, time() + 86400);
$device       = static fn(string $id): array => ['id' => $id, 'key_hash' => hash('sha256', $id), 'label' => 'Test', 'created_at' => date(DATE_ATOM), 'last_used_at' => date(DATE_ATOM), 'valid_until' => $until];
$accounts = [
    ['id' => 'acc-plain', 'email' => 'erna.beispiel@example.ch', 'first_name' => 'Erna', 'state' => 'active', 'roles' => ['customer'], 'device_keys' => [$device('aaaa1111'), $device('bbbb2222'), $device('cccc3333')]],
    ['id' => 'acc-totp', 'email' => 'max.beispiel@example.ch', 'first_name' => 'Max', 'state' => 'active', 'roles' => ['customer'], 'totp_secret' => $vault->encrypt($secret), 'totp_activated_at' => date(DATE_ATOM)],
    ['id' => 'acc-device', 'email' => 'geraet@example.ch', 'first_name' => 'Geraet', 'state' => 'active', 'roles' => ['customer']],
    ['id' => 'acc-gone', 'email' => 'weg@example.ch', 'first_name' => 'Weg', 'state' => 'active', 'roles' => ['customer']],
];
file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT));

$tokenId = 0;
$tokens  = [];
$issueLink = static function (string $accountId, bool $remember = false) use (&$tokenId, &$tokens, $tokensFile): string {
    $plain = bin2hex(random_bytes(16));
    $tokens[] = ['id' => ++$tokenId, 'token_hash' => hash('sha256', $plain), 'account_ref' => $accountId, 'purpose' => 'login', 'valid_until' => date(DATE_ATOM, time() + 600), 'used_at' => null, 'remember' => $remember];
    // A read-modify-write through JSON on the temp site; the store's own rows stay as they are.
    $onDisk = is_file($tokensFile) ? (array) json_decode((string) file_get_contents($tokensFile), true) : [];
    $onDisk[] = end($tokens);
    file_put_contents($tokensFile, json_encode(array_values($onDisk), JSON_PRETTY_PRINT));

    return $plain;
};
$stored = static function (string $id) use ($accountsFile): ?array {
    foreach ((array) json_decode((string) file_get_contents($accountsFile), true) as $row) {
        if (($row['id'] ?? '') === $id) {
            return $row;
        }
    }

    return null;
};
$deviceIds = static fn(string $id): array => array_column($stored($id)['device_keys'] ?? [], 'id');

$port = realFlowServer($site, $base . '/sessions', $base . '/server.log');
$new  = static fn(): RealFlowBrowser => new RealFlowBrowser($port);

/** Signs a browser in through the confirmation page, the way a link opened elsewhere does. */
$memberLogin = static function (RealFlowBrowser $b, string $accountId, bool $remember = false) use ($issueLink): array {
    $plain = $issueLink($accountId, $remember);
    $page  = $b->go('/member/main/login/redeem?token=' . $plain);
    preg_match('/name="csrf_token" value="([^"]+)"/', $page[2], $m);

    return $b->go('/member/main/login/redeem', ['csrf_token' => $m[1] ?? '', 'token' => $plain, 'decision' => 'here']);
};
$isSignedIn = static fn(RealFlowBrowser $b): bool => $b->go('/member/main/profile')[0] === 200;

// ── (A) backend logout ──────────────────────────────────────────────────────
echo "(A) backend logout\n";
$sessionFile = static fn(RealFlowBrowser $x): string => $base . '/sessions/sess_' . ($x->cookies['PHPSESSID'] ?? '');
$formToken = static function (string $html, string $action): string {
    return preg_match('#<form method="post" action="' . preg_quote($action, '#') . '"[^>]*>\s*<input type="hidden" name="csrf_token" value="([^"]*)"#', $html, $m) ? $m[1] : '';
};
$backendLogin = static function (RealFlowBrowser $x): array {
    return $x->go('/backend/system/login/login', ['csrf_token' => $x->pageToken('/login'), 'username' => 'max', 'password' => 'Passwort-Sehr-Lang-1']);
};
$inBackend = static fn(RealFlowBrowser $x): bool => $x->go('/backend/system/dashboard/overview')[0] === 200;
$isBackendConfirm = static function (array $r, string $sentence) use ($formToken): bool {
    return $r[0] === 200 && str_contains($r[2], $sentence) && strlen($formToken($r[2], '/backend/system/login/logout')) >= 20;
};
$b = $new();
$r = $backendLogin($b);
check('the login works', in_array($r[0], [302, 303], true), (string) $r[0]);
$dash = $b->go('/backend/system/dashboard/overview');
check('signed in: the dashboard answers', $dash[0] === 200, (string) $dash[0]);
$html = $dash[2];
check('topbar: no logout link left', !preg_match('#<a [^>]*href="/backend/system/login/logout"#', $html));
check('topbar: a POST form to the logout URL, the token not empty', strlen($formToken($html, '/backend/system/login/logout')) >= 20 && str_contains($html, 'class="backend-service-panel__row backend-service-panel__row--danger"'));
$token = $formToken($html, '/backend/system/login/logout');

$r = $b->go('/backend/system/login/logout');
check('GET logout: the confirm page, no sign-out', $isBackendConfirm($r, 'Bitte Abmelden bestätigen'), $r[0] . ' ' . substr(strip_tags($r[2]), 0, 160));
check('GET logout: still signed in', $inBackend($b));
$r = $b->go('/backend/system/login/logout', ['x' => '1']);
check('POST without token: the page says so and offers the way out, no silence', $isBackendConfirm($r, 'Sitzung abgelaufen: bitte Abmelden bestätigen'), $r[0] . ' ' . ($r[1]['location'] ?? '') . ' ' . substr(strip_tags($r[2]), 0, 160));
check('POST without token: still signed in', $inBackend($b));
$r = $b->go('/backend/system/login/logout', ['csrf_token' => str_repeat('a', 64)]);
check('POST with a wrong token: the same page', $isBackendConfirm($r, 'Sitzung abgelaufen: bitte Abmelden bestätigen'));
check('POST with a wrong token: still signed in', $inBackend($b));
$r = $b->go('/backend/system/login/logout', ['csrf_token' => '']);
check('POST with an empty token: the same page', $isBackendConfirm($r, 'Sitzung abgelaufen: bitte Abmelden bestätigen'));
$page = $r;
$r = $b->fetchPost('/backend/system/login/logout', [], null);
check('a fetch POST without the header: refused by the guard', str_contains($r[2], 'CSRF token invalid'));
check('...and still signed in', $inBackend($b));
$r = $b->go('/backend/system/login/logout', ['csrf_token' => $formToken($page[2], '/backend/system/login/logout')]);
check('a click on the page: signed out, to /login', in_array($r[0], [302, 303], true) && ($r[1]['location'] ?? '') === '/login', $r[0] . ' ' . ($r[1]['location'] ?? ''));
$r = $b->go('/backend/system/dashboard/overview');
check('...the dashboard sends to the login', in_array($r[0], [302, 303], true) && ($r[1]['location'] ?? '') === '/login', (string) $r[0]);

// The form's own token signs out at once; a stale page ends on the same page, never in silence.
$b = $new();
$backendLogin($b);
$token = $formToken($b->go('/backend/system/dashboard/overview')[2], '/backend/system/login/logout');
$r = $b->go('/backend/system/login/logout', ['csrf_token' => $token]);
check('the topbar form signs out at once', in_array($r[0], [302, 303], true) && ($r[1]['location'] ?? '') === '/login' && !$inBackend($b), $r[0] . ' ' . ($r[1]['location'] ?? ''));
$b = $new();
$backendLogin($b);
$token = $formToken($b->go('/backend/system/dashboard/overview')[2], '/backend/system/login/logout');
@unlink($sessionFile($b));
unset($b->cookies['PHPSESSID']);
$r = $b->go('/backend/system/login/logout', ['csrf_token' => $token]);
check('the session gone: the page, not a silent 303', $isBackendConfirm($r, 'Sitzung abgelaufen: bitte Abmelden bestätigen'), $r[0] . ' ' . ($r[1]['location'] ?? ''));
$r = $b->go('/backend/system/login/logout', ['csrf_token' => $formToken($r[2], '/backend/system/login/logout')]);
check('...and its button leads to the login', in_array($r[0], [302, 303], true) && ($r[1]['location'] ?? '') === '/login', $r[0] . ' ' . ($r[1]['location'] ?? ''));

// A cross-site POST carries no cookie (SameSite=Lax): a page, nothing else. It cannot sign anyone out by itself.
$victim = $new();
$backendLogin($victim);
$attacker = $new();
$r = $attacker->go('/backend/system/login/logout', ['csrf_token' => 'x']);
check('a POST without any cookie: the page, nothing happens', $isBackendConfirm($r, 'Sitzung abgelaufen: bitte Abmelden bestätigen'));
check('...the signed-in browser stays signed in', $inBackend($victim));
check('the backend session cookie is SameSite=Lax', str_contains(strtolower($r[1]['set-cookie'] ?? ''), 'samesite=lax'), (string) ($r[1]['set-cookie'] ?? ''));
check('the page cannot be framed from elsewhere', ($r[1]['x-frame-options'] ?? '') === 'SAMEORIGIN' && ($r[1]['content-security-policy'] ?? '') === "frame-ancestors 'self'");
check('the page is never stored', ($r[1]['cache-control'] ?? '') === 'no-store' && !isset($r[1]['x-z77-pagecache']) || str_contains(strtolower($r[1]['x-z77-pagecache'] ?? ''), 'bypass'), json_encode($r[1]));

// The frontend overlay: the real page, signed in. And the page cache: a guest's cached page holds no such form.
$admin = $new();
$backendLogin($admin);
$front = $admin->go('/');
check('frontend: the home page renders for an admin', $front[0] === 200, $front[0] . ' ' . substr($front[2], 0, 160));
check('frontend overlay: no logout link left', !preg_match('#<a [^>]*href="/backend/system/login/logout"#', $front[2]));
$overlayToken = $formToken($front[2], '/backend/system/login/logout');
check('frontend overlay: a POST form, the token not empty', strlen($overlayToken) >= 20 && str_contains($front[2], 'z77-admin-overlay__logout'));
check('frontend overlay: this page is not served from the page cache', !str_contains(strtolower($front[1]['x-z77-pagecache'] ?? ''), 'hit') && ($front[1]['cache-control'] ?? '') === 'no-store', json_encode($front[1]['x-z77-pagecache'] ?? null));
$guestA = $new()->go('/');
$guestB = $new()->go('/');
check('a guest: the page cache serves the page', str_contains(strtolower($guestB[1]['x-z77-pagecache'] ?? ''), 'hit'), (string) ($guestB[1]['x-z77-pagecache'] ?? ''));
check('...with no logout form, no overlay and no token of anyone', !str_contains($guestB[2], '/login/logout') && !str_contains($guestB[2], 'z77-admin-overlay__logout') && !str_contains($guestA[2], 'z77-admin-overlay__logout'));
$r = $admin->go('/backend/system/login/logout', ['csrf_token' => $overlayToken]);
check('frontend overlay: its form signs out', in_array($r[0], [302, 303], true) && ($r[1]['location'] ?? '') === '/login' && !$inBackend($admin), $r[0] . ' ' . ($r[1]['location'] ?? ''));

// ── (B) member logout ───────────────────────────────────────────────────────
echo "(B) member logout\n";
$isMemberConfirm = static function (array $r, string $sentence) use ($formToken): bool {
    return $r[0] === 200 && str_contains($r[2], $sentence) && strlen($formToken($r[2], '/member/main/logout')) >= 20;
};
$deviceKeys = static fn(string $id): int => count($stored($id)['device_keys'] ?? []);
$b = $new();
$r = $memberLogin($b, 'acc-plain');
check('member signs in', in_array($r[0], [302, 303], true) && $isSignedIn($b), $r[0] . ' ' . ($r[1]['location'] ?? ''));

// Both shapes of the shell render the form, each with a token that works.
$html = $b->go('/member/main/profile')[2];
check('rail shape: no logout link left', !preg_match('#<a [^>]*href="/member/main/logout"#', $html));
check('rail shape: a POST form, the token not empty', strlen($formToken($html, '/member/main/logout')) >= 20 && str_contains($html, 'class="me-account__row me-account__row--out">Abmelden</button>'));
$token = $formToken($html, '/member/main/logout');
$plain = $b->go('/member/main/profile/totp');
check('the plain shape really is the plain one', str_contains($plain[2], 'me-shell__header'));
check('plain shape (no rail): a POST form, the token NOT empty', strlen($formToken($plain[2], '/member/main/logout')) >= 20, 'token: «' . $formToken($plain[2], '/member/main/logout') . '»');
check('plain shape: the same token as the rail shape', $formToken($plain[2], '/member/main/logout') === $token);

$r = $b->go('/member/main/logout');
check('GET logout: the confirm page, no sign-out', $isMemberConfirm($r, 'Bitte Abmelden bestätigen'), $r[0] . ' ' . substr(strip_tags($r[2]), 0, 160));
check('GET logout: still signed in', $isSignedIn($b));
$r = $b->go('/member/main/logout', ['x' => '1']);
check('POST without token: the page says so and offers the way out, no silence', $isMemberConfirm($r, 'Sitzung abgelaufen: bitte Abmelden bestätigen'), $r[0] . ' ' . ($r[1]['location'] ?? '') . ' ' . substr(strip_tags($r[2]), 0, 160));
check('POST without token: still signed in', $isSignedIn($b));

// A click from the plain shape's form, the case that failed silently.
$p = $new();
$memberLogin($p, 'acc-plain');
$tok = $formToken($p->go('/member/main/profile/totp')[2], '/member/main/logout');
$r = $p->go('/member/main/logout', ['csrf_token' => $tok]);
check('plain shape: Abmelden signs out', in_array($r[0], [302, 303], true) && ($r[1]['location'] ?? '') === '/member/main/login' && !$isSignedIn($p), $r[0] . ' ' . ($r[1]['location'] ?? ''));
// The rail shape's form too.
$p = $new();
$memberLogin($p, 'acc-plain');
$tok = $formToken($p->go('/member/main/profile')[2], '/member/main/logout');
$r = $p->go('/member/main/logout', ['csrf_token' => $tok]);
check('rail shape: Abmelden signs out', in_array($r[0], [302, 303], true) && !$isSignedIn($p), $r[0] . ' ' . ($r[1]['location'] ?? ''));

// The stale page, the PHP session gone as gc does it, without a device key.
$p = $new();
$memberLogin($p, 'acc-plain');
$tok = $formToken($p->go('/member/main/profile')[2], '/member/main/logout');
@unlink($sessionFile($p));
unset($p->cookies['PHPSESSID']);
$r = $p->go('/member/main/logout', ['csrf_token' => $tok]);
check('stale page, no device key: the page, not a silent 303', $isMemberConfirm($r, 'Sitzung abgelaufen: bitte Abmelden bestätigen'), $r[0] . ' ' . ($r[1]['location'] ?? ''));
$r = $p->go('/member/main/logout', ['csrf_token' => $formToken($r[2], '/member/main/logout')]);
check('...its button leads to the login', in_array($r[0], [302, 303], true) && ($r[1]['location'] ?? '') === '/member/main/login', $r[0] . ' ' . ($r[1]['location'] ?? ''));

// The stale page with «angemeldet bleiben»: the failure on a shared device.
$p = $new();
$memberLogin($p, 'acc-device', true);
check('device key: issued, in the store and in the browser', $deviceKeys('acc-device') === 1 && ($p->cookies['z77_member_device'] ?? '') !== '' && $isSignedIn($p), json_encode(array_keys($p->cookies)));
check('the device cookie is SameSite=Lax', str_contains(strtolower(implode("\n", $p->setCookies)), 'z77_member_device') && (bool) preg_match('/z77_member_device=[^;]*;[^\n]*samesite=lax/i', implode("\n", $p->setCookies)), implode(' | ', $p->setCookies));
$tok = $formToken($p->go('/member/main/profile')[2], '/member/main/logout');
@unlink($sessionFile($p));
unset($p->cookies['PHPSESSID']);
$r = $p->go('/member/main/logout', ['csrf_token' => $tok]);
check('stale page with a device key: the page, not a silent 303', $isMemberConfirm($r, 'Sitzung abgelaufen: bitte Abmelden bestätigen'), $r[0] . ' ' . ($r[1]['location'] ?? ''));
check('...the page alone signs nobody out: the key stays', $deviceKeys('acc-device') === 1);
$r = $p->go('/member/main/logout', ['csrf_token' => $formToken($r[2], '/member/main/logout')]);
check('...its button signs out', in_array($r[0], [302, 303], true) && ($r[1]['location'] ?? '') === '/member/main/login', $r[0] . ' ' . ($r[1]['location'] ?? ''));
check('...the key is gone from the store', $deviceKeys('acc-device') === 0, (string) $deviceKeys('acc-device'));
check('...the cookie is gone from the browser', ($p->cookies['z77_member_device'] ?? 'deleted') === 'deleted' || ($p->cookies['z77_member_device'] ?? '') === '', (string) ($p->cookies['z77_member_device'] ?? ''));
check('...the next visit is NOT signed in', !$isSignedIn($p));

// A cross-site POST: no session cookie and no device cookie reach us (Lax). And if one did: no token, no sign-out.
$p = $new();
$memberLogin($p, 'acc-device', true);
$x = $new();
$r = $x->go('/member/main/logout', ['csrf_token' => 'x']);
check('a POST without any cookie: the page, nothing happens', $isMemberConfirm($r, 'Sitzung abgelaufen: bitte Abmelden bestätigen'));
check('...the member stays signed in, the key stays', $isSignedIn($p) && $deviceKeys('acc-device') === 1);
$x = $new();
$x->cookies['z77_member_device'] = $p->cookies['z77_member_device'] ?? '';
$r = $x->go('/member/main/logout', ['csrf_token' => 'x']);
check('a POST with the device cookie and a wrong token: the page, the key stays', $isMemberConfirm($r, 'Sitzung abgelaufen: bitte Abmelden bestätigen') && $deviceKeys('acc-device') === 1, $r[0] . ' keys ' . $deviceKeys('acc-device'));
$r = $x->fetchPost('/member/main/logout', [], null);
check('a fetch POST without the header: refused by the guard', str_contains($r[2], 'CSRF token invalid') && $deviceKeys('acc-device') === 1);
check('the page is never stored', ($r = $new()->go('/member/main/logout'))[0] === 200 && ($r[1]['cache-control'] ?? '') === 'no-store', json_encode($r[1]['cache-control'] ?? null));

// The token comes from one place, also for a project copying the pattern.
foreach ([
    'module-member/res/view/templates/partials/shell/userMenu.tpl.php',
    'module-backend/res/view/templates/partials/shell/topbar.tpl.php',
    'module-frontend/res/view/templates/partials/adminOverlay.tpl.php',
] as $tpl) {
    check("{$tpl}: the logout form takes its token from partials/csrfField", (bool) preg_match('#/logout"[^>]*>\s*<\?= \$this->partial\(\'partials/csrfField\'#', (string) file_get_contents($root . '/packages/' . $tpl)));
}
$b = $new();
$memberLogin($b, 'acc-plain');
$html = $b->go('/member/main/profile')[2];
$token = $formToken($html, '/member/main/logout');

// ── (C) the profile's actions ───────────────────────────────────────────────
echo "(C) profile actions\n";
$before = (string) file_get_contents($accountsFile);
foreach (['device-remove', 'device-remove-all', 'totp-remove', 'konto', 'loeschen', 'mandant', 'theme'] as $action) {
    $r = $b->go('/member/main/profile/' . $action . '?device=aaaa1111&mandant=b');
    check("GET {$action}: 404", $r[0] === 404, (string) $r[0]);
    $r = $b->fetchGet('/member/main/profile/' . $action . '?device=aaaa1111');
    check("fetch GET {$action}: 404", $r[0] === 404, (string) $r[0]);
}
check('GET: nothing changed in the store', (string) file_get_contents($accountsFile) === $before);
check('GET: still signed in', $isSignedIn($b));

// konto
$r = $b->go('/member/main/profile/konto', ['first_name' => 'Geändert']);
check('konto POST without token: refused', in_array($r[0], [302, 303], true) && $stored('acc-plain')['first_name'] === 'Erna', (string) ($stored('acc-plain')['first_name'] ?? ''));
$r = $b->fetchPost('/member/main/profile/konto', ['first_name' => 'Geändert'], null);
check('konto fetch POST without the header: refused by the guard', str_contains($r[2], 'CSRF token invalid') && $stored('acc-plain')['first_name'] === 'Erna');
$r = $b->go('/member/main/profile/konto', ['csrf_token' => $token, 'first_name' => 'Geändert']);
check('konto POST with the token: saved', in_array($r[0], [302, 303], true) && $stored('acc-plain')['first_name'] === 'Geändert', (string) ($stored('acc-plain')['first_name'] ?? ''));
$r = $b->fetchPost('/member/main/profile/konto', ['first_name' => 'Per Fetch'], $token);
check('konto fetch POST with the header: saved', $r[0] === 200 && $stored('acc-plain')['first_name'] === 'Per Fetch', $r[0] . ' ' . substr($r[2], 0, 120));

// theme
$r = $b->fetchPost('/member/main/profile/theme', ['theme' => 'dark'], null);
check('theme fetch POST without the header: refused', str_contains($r[2], 'CSRF token invalid') && ($stored('acc-plain')['theme'] ?? null) === null);
$r = $b->fetchPost('/member/main/profile/theme', ['theme' => 'dark'], $token);
check('theme fetch POST with the header: saved', ($stored('acc-plain')['theme'] ?? null) === 'dark', substr($r[2], 0, 120));

// mandant: the session's choice, read back from the switcher in the header
$active = static function () use ($b): string {
    return preg_match('#<button type="submit"\s+class="me-tenant__row me-tenant__row--active"\s+aria-current="true">([^<]+)</button>#', $b->go('/member/main/profile')[2], $m) ? $m[1] : '';
};
check('mandant: the first one is active', $active() === 'Erste Verwaltung', $active());
$r = $b->go('/member/main/profile/mandant', ['mandant' => 'b', 'back' => '/member/main/profile']);
check('mandant POST without token: refused', in_array($r[0], [302, 303], true) && $active() === 'Erste Verwaltung', $active());
$r = $b->go('/member/main/profile/mandant', ['csrf_token' => $token, 'mandant' => 'b', 'back' => '/member/main/profile']);
check('mandant POST with the token: switched', in_array($r[0], [302, 303], true) && $active() === 'Zweite Verwaltung', $active());

// device-remove
$r = $b->go('/member/main/profile/device-remove', ['device' => 'aaaa1111']);
check('device-remove POST without token: refused', in_array($r[0], [302, 303], true) && in_array('aaaa1111', $deviceIds('acc-plain'), true));
$r = $b->go('/member/main/profile/device-remove', ['csrf_token' => $token, 'device' => 'aaaa1111']);
check('device-remove POST with the token: gone', !in_array('aaaa1111', $deviceIds('acc-plain'), true) && in_array('bbbb2222', $deviceIds('acc-plain'), true), implode(',', $deviceIds('acc-plain')));
$r = $b->fetchPost('/member/main/profile/device-remove', ['device' => 'bbbb2222'], null);
check('device-remove fetch POST without the header: refused', str_contains($r[2], 'CSRF token invalid') && in_array('bbbb2222', $deviceIds('acc-plain'), true));
$r = $b->fetchPost('/member/main/profile/device-remove', ['device' => 'bbbb2222'], $token);
check('device-remove fetch POST with the header: gone', !in_array('bbbb2222', $deviceIds('acc-plain'), true) && in_array('cccc3333', $deviceIds('acc-plain'), true), implode(',', $deviceIds('acc-plain')));

// device-remove-all
$r = $b->go('/member/main/profile/device-remove-all', ['x' => '1']);
check('device-remove-all POST without token: refused', in_array($r[0], [302, 303], true) && $deviceIds('acc-plain') !== []);
$r = $b->go('/member/main/profile/device-remove-all', ['csrf_token' => $token]);
check('device-remove-all POST with the token: all gone', $deviceIds('acc-plain') === [], implode(',', $deviceIds('acc-plain')));

// member logout with the token, after everything above kept the session
$r = $b->go('/member/main/logout', ['csrf_token' => $token]);
check('member logout POST with the token: to the login', in_array($r[0], [302, 303], true) && ($r[1]['location'] ?? '') === '/member/main/login', $r[0] . ' ' . ($r[1]['location'] ?? ''));
check('member logout POST with the token: signed out', !$isSignedIn($b));

// totp-remove: an account with 2FA. The login spends the code of this step, the removal takes the next.
$b     = $new();
$plain = $issueLink('acc-totp');
$page  = $b->go('/member/main/login/redeem?token=' . $plain);
preg_match('/name="csrf_token" value="([^"]+)"/', $page[2], $m);
$b->go('/member/main/login/redeem', ['csrf_token' => $m[1] ?? '', 'token' => $plain, 'decision' => 'here']);
$prompt = $b->go('/member/main/login/totp');
preg_match('/name="csrf_token" value="([^"]+)"/', $prompt[2], $m);
$token = $m[1] ?? '';
$r = $b->go('/member/main/login/totp', ['csrf_token' => $token, 'code' => Totp::code($secret, time())]);
check('2FA account signs in with a code', in_array($r[0], [302, 303], true) && $isSignedIn($b), $r[0] . ' ' . ($r[1]['location'] ?? ''));

$next = Totp::code($secret, time() + Totp::PERIOD_SECONDS);
$r = $b->go('/member/main/profile/totp-remove?code=' . $next);
check('GET totp-remove with a valid code: 404', $r[0] === 404, (string) $r[0]);
check('GET totp-remove: 2FA stays', ($stored('acc-totp')['totp_activated_at'] ?? null) !== null);
$r = $b->go('/member/main/profile/totp-remove', ['code' => $next]);
check('totp-remove POST without token: refused', in_array($r[0], [302, 303], true) && ($stored('acc-totp')['totp_activated_at'] ?? null) !== null);
$r = $b->fetchPost('/member/main/profile/totp-remove', ['code' => $next], null);
check('totp-remove fetch POST without the header: refused', str_contains($r[2], 'CSRF token invalid') && ($stored('acc-totp')['totp_activated_at'] ?? null) !== null);
$r = $b->go('/member/main/profile/totp-remove', ['csrf_token' => $token, 'code' => $next]);
check('totp-remove POST with the token and a valid code: 2FA gone', in_array($r[0], [302, 303], true) && ($stored('acc-totp')['totp_activated_at'] ?? null) === null, json_encode($stored('acc-totp')));

// The setup page keeps its GET view; the saving branch needs POST and the token.
$r = $b->go('/member/main/profile/totp?code=123456');
check('totp GET: the page, nothing activated', $r[0] === 200 && str_contains($r[2], 'Zwei-Faktor-Schutz einrichten') && ($stored('acc-totp')['totp_activated_at'] ?? null) === null);

// loeschen
$b = $new();
$memberLogin($b, 'acc-gone');
$html = $b->go('/member/main/profile')[2];
preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
$token = $m[1] ?? '';
$r = $b->go('/member/main/profile/loeschen?loeschen_bestaetigung=weg@example.ch&bestaetigt=1');
check('GET loeschen: 404', $r[0] === 404, (string) $r[0]);
check('GET loeschen: the account stays', $stored('acc-gone') !== null);
$r = $b->go('/member/main/profile/loeschen', ['loeschen_bestaetigung' => 'weg@example.ch', 'bestaetigt' => '1']);
check('loeschen POST without token: refused', in_array($r[0], [302, 303], true) && $stored('acc-gone') !== null);
$r = $b->go('/member/main/profile/loeschen', ['csrf_token' => $token, 'loeschen_bestaetigung' => 'weg@example.ch', 'bestaetigt' => '1']);
check('loeschen POST with the token: the account is gone', in_array($r[0], [302, 303], true) && $stored('acc-gone') === null, $r[0] . ' ' . ($r[1]['location'] ?? ''));

// ── (D) the look: the CSS the button rows rest on ───────────────────────────
echo "(D) the rows' CSS\n";
$memberCss = (string) file_get_contents($root . '/packages/module-member/res/assets/css/member.css');
check('member.css: a button row without a button look', (bool) preg_match('/button\.me-account__row\{[^}]*border:0[^}]*background:none[^}]*font:inherit[^}]*text-align:left[^}]*cursor:pointer/', $memberCss));
$overlayCss = (string) file_get_contents($root . '/packages/module-frontend/res/assets/css/admin-overlay.css');
check('admin-overlay.css: the logout button', (bool) preg_match('/\.z77-admin-overlay__logout\{[^}]*width:100%[^}]*border:none[^}]*font:inherit[^}]*cursor:pointer/', $overlayCss));
$backendScss = (string) file_get_contents($root . '/packages/module-backend/res/scss/components/_service-panel.scss');
check('backend panel: the row is already styled for a button in a form', str_contains($backendScss, '.backend-service-panel form') && str_contains($backendScss, 'border:        none'));

// A fetch that a redirect leads to the member's login (a second tab after signing out): the module
// has no fetch skeleton of its own, the kernel's stands in. It answered a 500 before.
$guestFetch = $new();
$r = $guestFetch->fetchGet('/member/main/login');
check('a fetch to the member login is answered, not a 500', $r[0] === 200, 'status ' . $r[0]);
check('...as the page\'s main only, no skeleton', !str_contains($r[2], '<html') && !str_contains($r[2], '<body'), substr($r[2], 0, 120));
$r = $guestFetch->go('/member/main/login');
check('...the full page still has its skeleton', $r[0] === 200 && str_contains($r[2], '<body'), 'status ' . $r[0]);

echo "\nstate-changing-get: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "--- server log tail ---\n" . substr((string) @file_get_contents($base . '/server.log'), -1500) . "\n";
}
exit($fail === 0 ? 0 : 1);
