<?php

/**
 * State-changing GET (CLI, real stack in PHP's built-in server) — security.md SEC-GET-001. For each
 * action that changes state: a GET changes nothing and answers as a wrong method does (404), a POST
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

$accountsFile = $site . '/data/framework/member/accounts.json';
$tokensFile   = $site . '/data/framework/member/tokens.json';
$secret       = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
$vault        = new TotpVault($site . '/data/framework/member/totp.key');
$until        = date(DATE_ATOM, time() + 86400);
$device       = static fn(string $id): array => ['id' => $id, 'key_hash' => hash('sha256', $id), 'label' => 'Test', 'created_at' => date(DATE_ATOM), 'last_used_at' => date(DATE_ATOM), 'valid_until' => $until];
$accounts = [
    ['id' => 'acc-plain', 'email' => 'erna.beispiel@example.ch', 'first_name' => 'Erna', 'state' => 'active', 'roles' => ['customer'], 'device_keys' => [$device('aaaa1111'), $device('bbbb2222'), $device('cccc3333')]],
    ['id' => 'acc-totp', 'email' => 'max.beispiel@example.ch', 'first_name' => 'Max', 'state' => 'active', 'roles' => ['customer'], 'totp_secret' => $vault->encrypt($secret), 'totp_activated_at' => date(DATE_ATOM)],
    ['id' => 'acc-gone', 'email' => 'weg@example.ch', 'first_name' => 'Weg', 'state' => 'active', 'roles' => ['customer']],
];
file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT));

$tokenId = 0;
$tokens  = [];
$issueLink = static function (string $accountId) use (&$tokenId, &$tokens, $tokensFile): string {
    $plain = bin2hex(random_bytes(16));
    $tokens[] = ['id' => ++$tokenId, 'token_hash' => hash('sha256', $plain), 'account_ref' => $accountId, 'purpose' => 'login', 'valid_until' => date(DATE_ATOM, time() + 600), 'used_at' => null];
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
$memberLogin = static function (RealFlowBrowser $b, string $accountId) use ($issueLink): array {
    $plain = $issueLink($accountId);
    $page  = $b->go('/member/main/login/redeem?token=' . $plain);
    preg_match('/name="csrf_token" value="([^"]+)"/', $page[2], $m);

    return $b->go('/member/main/login/redeem', ['csrf_token' => $m[1] ?? '', 'token' => $plain, 'decision' => 'here']);
};
$isSignedIn = static fn(RealFlowBrowser $b): bool => $b->go('/member/main/profile')[0] === 200;

// ── (A) backend logout ──────────────────────────────────────────────────────
echo "(A) backend logout\n";
$b     = $new();
$login = $b->pageToken('/login');
$r     = $b->go('/backend/system/login/login', ['csrf_token' => $login, 'username' => 'max', 'password' => 'Passwort-Sehr-Lang-1']);
check('the login works', in_array($r[0], [302, 303], true), (string) $r[0]);
$dash = $b->go('/backend/system/dashboard/overview');
check('signed in: the dashboard answers', $dash[0] === 200, (string) $dash[0]);
$html = $dash[2];
check('topbar: no logout link left', !preg_match('#<a [^>]*href="/backend/system/login/logout"#', $html));
check('topbar: a POST form to the logout URL', (bool) preg_match('#<form method="post" action="/backend/system/login/logout">\s*<input type="hidden" name="csrf_token" value="[0-9a-f]{20,}">\s*<button type="submit" class="backend-service-panel__row backend-service-panel__row--danger"#', $html));
preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m);
$token = $m[1] ?? '';

$r = $b->go('/backend/system/login/logout');
check('GET logout: 404, as a wrong method answers', $r[0] === 404, (string) $r[0]);
check('GET logout: still signed in', $b->go('/backend/system/dashboard/overview')[0] === 200);
$r = $b->go('/backend/system/login/logout', ['x' => '1']);
check('POST without token: refused (303 to /)', $r[0] === 303 && ($r[1]['location'] ?? '') === '/', $r[0] . ' ' . ($r[1]['location'] ?? ''));
check('POST without token: still signed in', $b->go('/backend/system/dashboard/overview')[0] === 200);
$r = $b->go('/backend/system/login/logout', ['csrf_token' => str_repeat('a', 64)]);
check('POST with a wrong token: refused', $r[0] === 303 && ($r[1]['location'] ?? '') === '/');
check('POST with a wrong token: still signed in', $b->go('/backend/system/dashboard/overview')[0] === 200);
$r = $b->fetchPost('/backend/system/login/logout', [], null);
check('a fetch POST without the header: refused by the guard', str_contains($r[2], 'CSRF token invalid'));
check('...and still signed in', $b->go('/backend/system/dashboard/overview')[0] === 200);

// The frontend overlay signs out through the same door. The seed has no frontend content, so the
// real partial is rendered with what the controller hands it.
$renderOverlay = static function () use ($root): string {
    require_once $root . '/packages/kernel/core/src/autoload/prod/php/Helper.php';
    $overlayUser = ['initials' => 'MA', 'name' => 'max', 'role' => 'superUser', 'rail' => 'admin'];
    $overlayForm = ['returnPath' => '/', 'csrfToken' => 'abcdef0123456789abcdef'];
    ob_start();
    include $root . '/packages/module-frontend/res/view/templates/partials/adminOverlay.tpl.php';

    return (string) ob_get_clean();
};
$overlay = $renderOverlay();
check('frontend overlay: no logout link left', !preg_match('#<a [^>]*href="/backend/system/login/logout"#', $overlay));
check('frontend overlay: a POST form with the token', (bool) preg_match('#<form method="post" action="/backend/system/login/logout" class="z77-admin-overlay__form">\s*<input type="hidden" name="csrf_token" value="abcdef0123456789abcdef">\s*<button type="submit" class="z77-admin-overlay__logout">#', $overlay));

$r = $b->go('/backend/system/login/logout', ['csrf_token' => $token]);
check('POST with the token: signed out, to /login', in_array($r[0], [302, 303], true) && ($r[1]['location'] ?? '') === '/login', $r[0] . ' ' . ($r[1]['location'] ?? ''));
$r = $b->go('/backend/system/dashboard/overview');
check('POST with the token: the dashboard sends to the login', in_array($r[0], [302, 303], true) && ($r[1]['location'] ?? '') === '/login', (string) $r[0]);

// ── (B) member logout ───────────────────────────────────────────────────────
echo "(B) member logout\n";
$b = $new();
$r = $memberLogin($b, 'acc-plain');
check('member signs in', in_array($r[0], [302, 303], true) && $isSignedIn($b), $r[0] . ' ' . ($r[1]['location'] ?? ''));
$html = $b->go('/member/main/profile')[2];
check('account panel: no logout link left', !preg_match('#<a [^>]*href="/member/main/logout"#', $html));
check('account panel: a POST form with the token', (bool) preg_match('#<form method="post" action="/member/main/logout" class="me-account__form">\s*<input type="hidden" name="csrf_token" value="[0-9a-f]{20,}">\s*<button type="submit" class="me-account__row me-account__row--out">Abmelden</button>#', $html));
preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
$token = $m[1] ?? '';

$r = $b->go('/member/main/logout');
check('GET logout: 404', $r[0] === 404, (string) $r[0]);
check('GET logout: still signed in', $isSignedIn($b));
$r = $b->go('/member/main/logout', ['x' => '1']);
check('POST without token: refused', $r[0] === 303 && ($r[1]['location'] ?? '') === '/', $r[0] . ' ' . ($r[1]['location'] ?? ''));
check('POST without token: still signed in', $isSignedIn($b));

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

echo "\nstate-changing-get: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "--- server log tail ---\n" . substr((string) @file_get_contents($base . '/server.log'), -1500) . "\n";
}
exit($fail === 0 ? 0 : 1);
