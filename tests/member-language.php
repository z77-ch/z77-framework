<?php

/**
 * Member language harness (CLI, offline, file stores in a temp dir) — ADR-056,
 * z77-rudaz.ch 2026-11-07.
 *
 * What is load-bearing:
 *
 *   (A) the dictionary layers: package < project override < runtime, default-language
 *       fallback, the key as the visible miss, placeholders inserted unescaped, the
 *       `<ns>.js.*` keys travel to the client;
 *   (B) the scope: Translator::inLanguage() sets the language of every t() inside,
 *       nested scopes stack, no request = the default;
 *   (C) German stays as it was: every member and DMS mail renders the same text as the
 *       committed template before the change (whitespace aside);
 *   (D) French and English render, with no key left showing;
 *   (E) the invitation carries the language: token, mail, subject, link prefix, and the
 *       account born from it; a known address keeps its own; an unoffered code is the
 *       default; no language = exactly the old mail;
 *   (F) MemberLanguage::url() and the account field's shape check.
 *
 * Run: php tests/member-language.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "member-language: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-member-language-' . bin2hex(random_bytes(4));
mkdir($base . '/data/framework/i18n', 0777, true);
define('ABS_BASE_PATH', $base);

require $autoload;
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';
require_once __DIR__ . '/support/TestTranslator.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Z77\\Module\\Member\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../packages/module-member/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Z77\Core\DI;
use Z77\Module\Member\Entities\MemberAccount;
use Z77\Module\Member\Services\InvitationFlow;
use Z77\Module\Member\Services\MemberAccounts;
use Z77\Module\Member\Services\MemberLanguage;
use Z77\Module\Member\Services\MemberThrottle;
use Z77\Module\Member\Services\TokenService;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Mail\EmailMessage;
use Z77\Tests\Support\TestTranslator;

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
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

// Registered from inside a shutdown function, so it runs LAST — after the real-stack
// servers (section G) were stopped by their own shutdown functions.
register_shutdown_function(static function () use ($base): void {
    register_shutdown_function(static fn() => removeTree($base));
});

/** Renders a template file with $context, inside the language scope a mail gets. */
function renderTpl(string $file, array $context, string $language): string
{
    return DI::getTranslator()->inLanguage($language, static function () use ($file, $context, $language): string {
        $context['mailLanguage'] = $language;
        return (static function (string $z77File, array $z77Ctx): string {
            extract($z77Ctx, EXTR_SKIP);
            ob_start();
            include $z77File;
            return (string)ob_get_clean();
        })($file, $context);
    });
}

/** Visible text: tags kept, whitespace collapsed — what a reader of the mail sees. */
function flat(string $html): string
{
    return trim((string)preg_replace(['/\s+/u', '/>\s+</u', '/\s+</u', '/>\s+/u'], [' ', '><', '<', '>'], $html));
}

DI::getInstance(true)->set('CacheManager', Z77\Core\Libraries\CacheManager::class, true);

// A project root with an override dictionary — the layer between package and runtime.
$project = $base . '/override/module/member';
mkdir($project . '/res/i18n', 0777, true);
mkdir($project . '/src', 0777, true);
file_put_contents($project . '/res/i18n/de.json', json_encode([
    'rudaz.cockpit.title'  => 'Projektraum',
    'rudaz.js.upload.fail' => 'Hochladen fehlgeschlagen',
    'member.mail.toLogin'  => 'Zur Anmeldung',          // the project rewords a package key
], JSON_UNESCAPED_UNICODE));
file_put_contents($project . '/res/i18n/fr.json', json_encode([
    'rudaz.cockpit.title' => 'Espace projet',
], JSON_UNESCAPED_UNICODE));

$memberRoot = TestTranslator::packages('module-member')[0];
$dmsRoot    = TestTranslator::packages('module-dms')[0];

echo "(A) dictionary layers\n";
TestTranslator::install([$memberRoot, $dmsRoot, $project]);
$tr = DI::getTranslator();
check('package key, default language', $tr->t('member.mail.invite.button') === 'Einladung annehmen', $tr->t('member.mail.invite.button'));
check('package key, French', $tr->t('member.mail.invite.button', [], 'fr') === 'Accepter l’invitation', $tr->t('member.mail.invite.button', [], 'fr'));
check('project key, French', $tr->t('rudaz.cockpit.title', [], 'fr') === 'Espace projet');
check('project key missing in English → German', $tr->t('rudaz.cockpit.title', [], 'en') === 'Projektraum');
check('unknown key → the key itself', $tr->t('rudaz.nope', [], 'fr') === 'rudaz.nope');
check('placeholders unescaped (the caller escapes once)', $tr->t('member.mail.greetingName', ['name' => 'Müller & Söhne <x>']) === 'Guten Tag Müller & Söhne <x>');
check('the project rewords a package key', $tr->t('member.mail.toLogin') === 'Zur Anmeldung', $tr->t('member.mail.toLogin'));
check('… only in its language: French stays the package\'s', $tr->t('member.mail.toLogin', [], 'fr') === 'Se connecter');

file_put_contents($base . '/data/framework/i18n/de.json', json_encode(['member.mail.toLogin' => 'Anmelden (Laufzeit)'], JSON_UNESCAPED_UNICODE));
TestTranslator::install([$memberRoot, $dmsRoot, $project]); // a fresh translator: dictionaries load lazily once
DI::getInstance(true)->set('CacheManager', Z77\Core\Libraries\CacheManager::class, true);
TestTranslator::install([$memberRoot, $dmsRoot, $project]);
$tr = DI::getTranslator();
check('the runtime layer has the last word', $tr->t('member.mail.toLogin') === 'Anmelden (Laufzeit)', $tr->t('member.mail.toLogin'));
unlink($base . '/data/framework/i18n/de.json');

$client = $tr->clientDictionary('fr');
check('a project `<ns>.js.*` key travels to the client (German fallback)', ($client['rudaz.js.upload.fail'] ?? null) === 'Hochladen fehlgeschlagen', json_encode($client));
check('… a non-js key does not', !array_key_exists('rudaz.cockpit.title', $client));

file_put_contents($project . '/res/i18n/en.json', '{ broken');
DI::getInstance(true)->set('CacheManager', Z77\Core\Libraries\CacheManager::class, true);
TestTranslator::install([$memberRoot, $dmsRoot, $project]);
$tr = DI::getTranslator();
$prev = ini_set('error_log', $base . '/php-error.log');
check('a broken project file is skipped, the package still answers', $tr->t('member.mail.invite.button', [], 'en') === 'Accept invitation');
ini_set('error_log', (string)$prev);
check('… and logged', str_contains((string)@file_get_contents($base . '/php-error.log'), 'is not a JSON object'));
unlink($project . '/res/i18n/en.json');

echo "(B) the scope\n";
DI::getInstance(true)->set('CacheManager', Z77\Core\Libraries\CacheManager::class, true);
TestTranslator::install([$memberRoot, $dmsRoot]);
$tr = DI::getTranslator();
check('no request → the default language', $tr->currentLanguage() === 'de');
$seen = $tr->inLanguage('fr', static fn() => [t('member.mail.greeting'), DI::getTranslator()->inLanguage('en', static fn() => t('member.mail.greeting')), t('member.mail.greeting')]);
check('inLanguage(): t() answers in it, nested scopes stack', $seen === ['Bonjour', 'Hello', 'Bonjour'], json_encode($seen));
check('… and the scope ends with the closure', $tr->currentLanguage() === 'de');
check('an unoffered code renders the default', $tr->inLanguage('it', static fn() => t('member.mail.greeting')) === 'Guten Tag');
try {
    $tr->inLanguage('fr', static function (): void { throw new RuntimeException('x'); });
} catch (RuntimeException) {
}
check('a throwing closure leaves no scope behind', $tr->currentLanguage() === 'de');

echo "(C) German stays as it was\n";
$account = new MemberAccount(['id' => 'a1', 'email' => 'max@example.ch', 'firstName' => 'Max', 'lastName' => 'Muster']);
$contexts = [
    'activated'        => ['account' => $account, 'loginUrl' => 'https://x.ch/member/main/login'],
    'confirm'          => ['account' => $account, 'confirmUrl' => 'https://x.ch/member/main/confirm?token=t'],
    'existing-account' => ['account' => $account],
    'join-activated'   => ['account' => $account, 'tenantName' => 'Muster & Co', 'loginUrl' => 'https://x.ch/member/main/login'],
    'login-link'       => ['account' => $account, 'loginUrl' => 'https://x.ch/l?token=t', 'digits' => '4711', 'device' => 'Firefox · Windows', 'when' => '07.11.2026, 14:05'],
    'no-account'       => ['registerUrl' => 'https://x.ch/member/main/register'],
    'invite'           => ['tenantName' => 'Muster & Co', 'inviter' => 'Erika <Beispiel>', 'inviteUrl' => 'https://x.ch/i?invite=t', 'validDays' => 7, 'known' => false],
    'invite#known'     => ['tenantName' => 'Muster & Co', 'inviter' => 'Erika', 'inviteUrl' => 'https://x.ch/i?invite=t', 'validDays' => 7, 'known' => true],
];
$repo = dirname(__DIR__);
foreach ($contexts as $name => $ctx) {
    $tpl  = explode('#', $name)[0];
    $rel  = "packages/module-member/res/view/templates/emails/{$tpl}.tpl.php";
    $old  = $base . "/old-{$tpl}.tpl.php";
    file_put_contents($old, (string)shell_exec('git -C ' . escapeshellarg($repo) . ' show HEAD:' . escapeshellarg($rel)));
    $before = flat(renderTpl($old, $ctx, 'de'));
    $after  = flat(renderTpl($repo . '/' . $rel, $ctx, 'de'));
    check("member {$name}: German unchanged", $before === $after && $before !== '', $before === '' ? 'old template empty' : "\n       old: {$before}\n       new: {$after}");
}
foreach (['zipReady', 'zipFailed'] as $tpl) {
    $rel = "packages/module-dms/res/view/templates/emails/{$tpl}.tpl.php";
    $old = $base . "/old-{$tpl}.tpl.php";
    file_put_contents($old, (string)shell_exec('git -C ' . escapeshellarg($repo) . ' show HEAD:' . escapeshellarg($rel)));
    foreach ([1, 3] as $n) {
        $ctx    = ['documents' => $n, 'size' => '1.2 MB', 'expires' => '24.12.2026', 'link' => 'https://x.ch/d', 'reason' => 'Zu gross.'];
        $before = flat(renderTpl($old, $ctx, 'de'));
        $after  = flat(renderTpl($repo . '/' . $rel, $ctx, 'de'));
        check("dms {$tpl} ({$n}): German unchanged", $before === $after, "\n       old: {$before}\n       new: {$after}");
    }
}

echo "(D) French and English\n";
foreach (['fr', 'en'] as $lang) {
    foreach ($contexts as $name => $ctx) {
        $tpl  = explode('#', $name)[0];
        $html = renderTpl($repo . "/packages/module-member/res/view/templates/emails/{$tpl}.tpl.php", $ctx, $lang);
        $leak = preg_match('/\b(member|dms)\.[a-z]+\.[a-zA-Z.]+/', $html, $m) === 1 ? $m[0] : '';
        check("member {$name} ({$lang}): no key showing, no German greeting", $leak === '' && !str_contains($html, 'Guten Tag'), $leak !== '' ? $leak : flat($html));
    }
    $html = renderTpl($repo . '/packages/module-dms/res/view/templates/emails/zipReady.tpl.php', ['documents' => 2, 'size' => '1 MB', 'expires' => '01.01.2027', 'link' => 'https://x.ch'], $lang);
    check("dms zipReady ({$lang}): translated", !str_contains($html, 'dms.mail') && !str_contains($html, 'Guten Tag'), flat($html));
}
$html = renderTpl($repo . '/packages/module-member/res/view/templates/emails/invite.tpl.php', $contexts['invite'], 'fr');
check('markup keys escape their placeholders', str_contains($html, 'Erika &lt;Beispiel&gt;') && str_contains($html, '<strong>Muster &amp; Co</strong>'), flat($html));

echo "(E) the invitation carries the language\n";
$uem      = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
$accounts = new MemberAccounts($uem);
$mails    = [];
$flow = new InvitationFlow(
    $accounts,
    new TokenService($uem),
    new MemberThrottle($base . '/throttle'),
    static function (EmailMessage $mail) use (&$mails): bool { $mails[] = $mail; return true; },
    static fn(MemberAccount $a, array $i): bool => true,
    'https://example.test/member/main/register/invite',
    static fn(string $ref): string => 'Projekt ' . $ref,
);
$tokenFrom = static function (EmailMessage $mail): string {
    preg_match('/invite=([^&]+)/', (string)($mail->getContext()['inviteUrl'] ?? ''), $m);
    return urldecode($m[1] ?? '');
};

check('SENT in French', $flow->inviteAsOperator('Adrian Muster', 7, 'p1', 'neu@example.ch', null, null, 'fr') === InvitationFlow::SENT);
$mail = $mails[0];
check('the mail renders in French', $mail->getLanguage() === 'fr');
check('the subject is French', $mail->getSubject() === 'Invitation pour « Projekt p1 »', $mail->getSubject());
check('the link opens the page in French', str_starts_with((string)$mail->getContext()['inviteUrl'], 'https://example.test/fr/member/main/register/invite?invite='), (string)$mail->getContext()['inviteUrl']);
$token = $flow->inspect($tokenFrom($mail));
check('the token keeps the language', $token?->getLanguage() === 'fr', (string)$token?->getLanguage());
$res = $flow->redeem($tokenFrom($mail), 'Neu', 'Person');
check('the account born from it takes the language', ($res['account'] ?? null)?->getLanguage() === 'fr', (string)($res['account'] ?? null)?->getLanguage());
check('… and keeps it in the store', $accounts->findByEmail('neu@example.ch')?->getLanguage() === 'fr');

$known = $accounts->findByEmail('neu@example.ch');
$known->setLanguage('en');
$accounts->save($known);
$mails = [];
$flow->inviteAsOperator('Adrian Muster', 7, 'p2', 'neu@example.ch', null, null, 'fr');
$flow->redeem($tokenFrom($mails[0]), null, null);
check('a known address keeps its own language', $accounts->findByEmail('neu@example.ch')?->getLanguage() === 'en');

// The language the person confirms on the redemption page (follow-up 2026-11-07).
$mails = [];
$flow->inviteAsOperator('Adrian Muster', 7, 'p6', 'bestaetigt@example.ch', null, null, 'fr');
$res = $flow->redeem($tokenFrom($mails[0]), 'Bea', 'Muster', null, 'en');
check('redeem with a confirmed language: it wins over the token\'s', ($res['account'] ?? null)?->getLanguage() === 'en' && $accounts->findByEmail('bestaetigt@example.ch')?->getLanguage() === 'en');
$mails = [];
$flow->inviteAsOperator('Adrian Muster', 7, 'p7', 'standard@example.ch', null, null, 'fr');
$res = $flow->redeem($tokenFrom($mails[0]), 'Sepp', 'Muster', null, 'de');
check('… the default confirmed is stored as «none»', $res['outcome'] === InvitationFlow::REDEEMED && $accounts->findByEmail('standard@example.ch')?->getLanguage() === null);
$mails = [];
$flow->inviteAsOperator('Adrian Muster', 7, 'p8', 'fremd@example.ch', null, null, 'fr');
$res = $flow->redeem($tokenFrom($mails[0]), 'Ida', 'Muster', null, 'it');
check('… an unoffered one is not stored', $res['outcome'] === InvitationFlow::REDEEMED && $accounts->findByEmail('fremd@example.ch')?->getLanguage() === null);
$mails = [];
$flow->inviteAsOperator('Adrian Muster', 7, 'p9', 'neu@example.ch', null, null, 'fr');
$res = $flow->redeem($tokenFrom($mails[0]), null, null, null, 'de');
check('the join path: a language given changes the existing account\'s nothing', $res['outcome'] === InvitationFlow::JOINED && $accounts->findByEmail('neu@example.ch')?->getLanguage() === 'en');

$mails = [];
$flow->inviteAsOperator('Adrian Muster', 7, 'p3', 'it@example.ch', null, null, 'it');
check('an unoffered language: the default mail, nothing stored', $mails[0]->getLanguage() === 'de' && $flow->inspect($tokenFrom($mails[0]))?->getLanguage() === null && !str_contains((string)$mails[0]->getContext()['inviteUrl'], '/it/'));

$mails = [];
$flow->inviteAsOperator('Adrian Muster', 7, 'p4', 'de@example.ch');
check('no language: the old German subject and a prefix-less link', $mails[0]->getSubject() === 'Einladung zu «Projekt p4»' && str_starts_with((string)$mails[0]->getContext()['inviteUrl'], 'https://example.test/member/'), $mails[0]->getSubject());
check('… the default is stored as «none»', $flow->inspect($tokenFrom($mails[0]))?->getLanguage() === null);

$member = $accounts->findByEmail('neu@example.ch');
$mails = [];
$flow->invite($member, 'p5', 'kollege@example.ch', null, 'en');
check('invite() takes the language too', $mails[0]->getLanguage() === 'en' && $mails[0]->getSubject() === 'Invitation to “Projekt p5”', $mails[0]->getSubject());

echo "(F) helpers\n";
check('url(): the prefix after the origin', MemberLanguage::url('https://x.ch/member/main/login?a=1', 'fr') === 'https://x.ch/fr/member/main/login?a=1');
check('url(): the default language has none', MemberLanguage::url('https://x.ch/member/main/login', 'de') === 'https://x.ch/member/main/login');
check('url(): a bare origin', MemberLanguage::url('https://x.ch', 'en') === 'https://x.ch/en');
$a = new MemberAccount();
$a->setLanguage(' FR ');
check('the account field: lowercased', $a->getLanguage() === 'fr');
$a->setLanguage('<script>');
check('the account field: anything but two letters is «none»', $a->getLanguage() === null);
check('of(): an account without a language gets the default', MemberLanguage::of(new MemberAccount()) === 'de');

echo "(G) real stack: the account's language after sign-in\n";
require_once __DIR__ . '/support/real-flow-site.php';
require_once __DIR__ . '/support/real-flow-server.php';
ini_set('display_errors', '0');
$root = dirname(__DIR__);

/** A site with three languages, the member module with $memberConfig, two accounts. */
$makeSite = static function (string $name, array $memberConfig) use ($base, $root): array {
    $site = $base . '/' . $name;
    realFlowSite($site, $root, []);
    file_put_contents($site . '/config/i18n.inc.php', "<?php\nreturn " . var_export(['defaultLanguage' => 'de', 'languages' => ['de', 'fr', 'en']], true) . ";\n");
    realFlowAddMember($site, $root, $memberConfig + ['loginRequestsPerHourPerIp' => 50, 'loginRequestsPerHour' => 50]);
    file_put_contents($site . '/data/framework/member/accounts.json', json_encode([
        ['id' => 'acc-fr', 'email' => 'fr@example.ch', 'first_name' => 'Claire', 'state' => 'active', 'roles' => ['customer'], 'language' => 'fr'],
        ['id' => 'acc-none', 'email' => 'none@example.ch', 'first_name' => 'Hans', 'state' => 'active', 'roles' => ['customer']],
    ], JSON_PRETTY_PRINT));
    return [$site, realFlowServer($site, $base . '/sessions-' . $name, $base . '/server-' . $name . '.log')];
};
$tokenId = 500;
/** Signs $accountId in on a fresh browser through a login link, «here». */
$signIn = static function (string $site, int $port, string $accountId) use (&$tokenId): RealFlowBrowser {
    $file   = $site . '/data/framework/member/tokens.json';
    $plain  = bin2hex(random_bytes(16));
    $onDisk = is_file($file) ? (array)json_decode((string)file_get_contents($file), true) : [];
    $onDisk[] = ['id' => ++$tokenId, 'token_hash' => hash('sha256', $plain), 'account_ref' => $accountId, 'purpose' => 'login', 'valid_until' => date(DATE_ATOM, time() + 600), 'used_at' => null];
    file_put_contents($file, json_encode(array_values($onDisk), JSON_PRETTY_PRINT));
    $b    = new RealFlowBrowser($port);
    $page = $b->go('/member/main/login/redeem?token=' . $plain);
    preg_match('/name="csrf_token" value="([^"]+)"/', $page[2], $m);
    $b->go('/member/main/login/redeem', ['csrf_token' => $m[1] ?? '', 'token' => $plain, 'decision' => 'here']);
    return $b;
};
$langOf = static fn(array $page): string => preg_match('/<html lang="([a-z]{2})"/', $page[2], $m) ? $m[1] : '?';

[$siteOn, $portOn] = $makeSite('on', ['languageFromAccount' => true, 'languages' => ['de', 'fr', 'en']]);
$guest = new RealFlowBrowser($portOn);
check('a guest: the URL decides (/fr/member/main/login)', $langOf($guest->go('/fr/member/main/login')) === 'fr');
check('a guest: no prefix = the default', $langOf($guest->go('/member/main/login')) === 'de');
$b = $signIn($siteOn, $portOn, 'acc-fr');
$profile = $b->go('/member/main/profile');
check('signed in: the account\'s language on a prefix-less URL', $profile[0] === 200 && $langOf($profile) === 'fr', $profile[0] . ' ' . $langOf($profile));
$r = $b->go('/en/member/main/profile?bereich=konto');
check('… a prefixed URL after sign-in moves to the same page without the prefix', $r[0] === 302 && ($r[1]['location'] ?? '') === '/member/main/profile?bereich=konto', $r[0] . ' ' . (string)($r[1]['location'] ?? ''));
check('… and no link of the signed-in page carries a prefix', !preg_match('#(href|action)="/(fr|en|de)/#', $profile[2]));
check('the profile names the language, in its own name', str_contains($profile[2], 'Français') && str_contains($profile[2], 'name="language"'));
$b2 = $signIn($siteOn, $portOn, 'acc-none');
check('an account without a language: the default', $langOf($b2->go('/member/main/profile')) === 'de');

// Change it on the profile (page POST, no script).
$tok = preg_match('/name="csrf_token" value="([^"]+)"/', $b2->go('/member/main/profile')[2], $m) ? $m[1] : '';
$r   = $b2->go('/member/main/profile/konto', ['csrf_token' => $tok, 'first_name' => 'Hans', 'last_name' => '', 'company' => '', 'language' => 'en']);
check('a new language on the profile: a redirect without a prefix …', in_array($r[0], [302, 303], true) && str_starts_with((string)($r[1]['location'] ?? ''), '/member/main/profile'), $r[0] . ' ' . (string)($r[1]['location'] ?? ''));
check('… and the next page is English', $langOf($b2->go('/member/main/profile')) === 'en');
$rows = array_column((array)json_decode((string)file_get_contents($siteOn . '/data/framework/member/accounts.json'), true), null, 'id');
check('… stored on the account', ($rows['acc-none']['language'] ?? null) === 'en', json_encode($rows['acc-none'] ?? null));
$r = $b2->go('/member/main/profile/konto', ['csrf_token' => $tok, 'first_name' => 'Hans', 'last_name' => '', 'company' => '', 'language' => 'it']);
$rows = array_column((array)json_decode((string)file_get_contents($siteOn . '/data/framework/member/accounts.json'), true), null, 'id');
check('an unoffered language is ignored', ($rows['acc-none']['language'] ?? null) === 'en');
$b2->go('/member/main/profile/konto', ['csrf_token' => $tok, 'first_name' => 'Hans', 'last_name' => '', 'company' => '', 'language' => 'de']);
$rows = array_column((array)json_decode((string)file_get_contents($siteOn . '/data/framework/member/accounts.json'), true), null, 'id');
check('back to the default: stored as «none»', !isset($rows['acc-none']['language']) || $rows['acc-none']['language'] === null, json_encode($rows['acc-none'] ?? null));

echo "(H) real stack: the pages speak the language (part 5)\n";
/** Visible page text without the scripts (the client dictionary island holds keys by design). */
$visible = static fn(array $page): string => (string)preg_replace('#<script\b[^>]*>.*?</script>#si', '', $page[2]);
$leak    = static fn(array $page): string => preg_match('/\b(member|dms|nav|form)\.[a-zA-Z]+\.[a-zA-Z.]+/', $visible($page), $m) === 1 ? $m[0] : '';
$guest   = new RealFlowBrowser($portOn);
$de = $guest->go('/member/main/login');
check('German login: the old sentence, word for word', str_contains($visible($de), 'Geben Sie Ihre E-Mail-Adresse ein — wir senden Ihnen einen Anmelde-Link. Ein Passwort gibt es nicht.') && $leak($de) === '', $leak($de));
$fr = $guest->go('/fr/member/main/login');
check('French login: French, no key showing, no German lead', str_contains($visible($fr), 'Saisissez votre adresse e-mail') && !str_contains($visible($fr), 'Geben Sie') && $leak($fr) === '', $leak($fr));
check('the guest switch: three languages, the active one marked', substr_count($fr[2], 'class="me-lang__link') === 3 && str_contains($fr[2], 'href="/en/member/main/login"') && preg_match('#me-lang__link--active"\s+aria-current="true">Français#', $fr[2]) === 1);
$withQuery = $guest->go('/fr/member/main/register?invite=abc');
check('… the switch keeps the query (an invitation\'s token)', str_contains($withQuery[2], 'href="/en/member/main/register?invite=abc"'));
$en = $guest->go('/en/member/main/register');
check('English register page: English, labels too, no key', str_contains($visible($en), 'Create your account') && str_contains($visible($en), 'First name') && $leak($en) === '', $leak($en));
foreach (['/fr/member/main/resend', '/fr/member/main/confirm?token=nope', '/en/member/main/login/warten'] as $path) {
    $p = $guest->go($path);
    check("{$path}: no key, no German title", $leak($p) === '' && !preg_match('#<h1[^>]*>(Link|Anmeldung|E-Mail)#', $p[2]), $leak($p) . ' ' . $p[0]);
}
$tok = preg_match('/name="csrf_token" value="([^"]+)"/', $fr[2], $m) ? $m[1] : '';
sleep(4); // the public form's time trap
$r = $guest->go('/fr/member/main/login', ['csrf_token' => $tok, 'email' => 'nobody@example.ch', 'website' => '', 'fax' => '']);
check('a French guest\'s submit stays French: the redirect carries the prefix', str_starts_with((string)($r[1]['location'] ?? ''), '/fr/member/main/login/warten'), $r[0] . ' ' . (string)($r[1]['location'] ?? ''));

$frProfile = $b->go('/member/main/profile?bereich=zweifa');
check('French account: the profile in French, no key, no German', str_contains($visible($frProfile), 'Authentification à deux facteurs') && !str_contains($visible($frProfile), 'Zwei-Faktor') && $leak($frProfile) === '', $leak($frProfile));
check('… and no URL switch for a signed-in member', !str_contains($frProfile[2], 'me-lang__link'));
$devices = $b->go('/member/main/profile?bereich=geraete');
check('French devices section: no key, no German', $leak($devices) === '' && !str_contains($visible($devices), 'Geräte'), $leak($devices));
// Signing out: a guest again, in the account's language through the URL.
$out = $signIn($siteOn, $portOn, 'acc-fr');
$tok = preg_match('/name="csrf_token" value="([^"]+)"/', $out->go('/member/main/logout')[2], $m) ? $m[1] : '';
$r   = $out->go('/member/main/logout', ['csrf_token' => $tok]);
check('signing out lands on the login in the account\'s language', in_array($r[0], [302, 303], true) && ($r[1]['location'] ?? '') === '/fr/member/main/login', $r[0] . ' ' . (string)($r[1]['location'] ?? ''));
$deAcc = $signIn($siteOn, $portOn, 'acc-none');
$deProfile = $deAcc->go('/member/main/profile');
check('German account: the old texts', str_contains($visible($deProfile), 'Ihre Angaben aus der Registrierung') && str_contains($visible($deProfile), 'Zwei-Faktor-Schutz') && $leak($deProfile) === '', $leak($deProfile));

echo "(I) real stack: the invited person confirms the language\n";
/** An open invitation on disk; returns the plain token. */
$inviteOnDisk = static function (string $site, string $email, ?string $language) use (&$tokenId): string {
    $file   = $site . '/data/framework/member/tokens.json';
    $plain  = bin2hex(random_bytes(16));
    $onDisk = is_file($file) ? (array)json_decode((string)file_get_contents($file), true) : [];
    $onDisk[] = ['id' => ++$tokenId, 'token_hash' => hash('sha256', $plain), 'account_ref' => null, 'purpose' => 'invite', 'valid_until' => date(DATE_ATOM, time() + 600), 'used_at' => null, 'tenant_ref' => 'p1', 'email' => $email, 'invited_by' => 'acc-fr', 'language' => $language];
    file_put_contents($file, json_encode(array_values($onDisk), JSON_PRETTY_PRINT));
    return $plain;
};
$checked = static fn(array $page): string => preg_match('#name="language" value="([a-z]{2})"[^>]*\bchecked#', $page[2], $m) ? $m[1] : '';

$plain = $inviteOnDisk($siteOn, 'eingeladen@example.ch', 'fr');
$guest = new RealFlowBrowser($portOn);
$page  = $guest->go('/fr/member/main/register?invite=' . $plain);
check('the redemption page offers the language, each in its own name', $page[0] === 200 && substr_count($page[2], 'name="language"') === 3 && str_contains($page[2], '<span>Français</span>') && str_contains($page[2], '<span>English</span>') && str_contains($page[2], '<span>Deutsch</span>'), (string)$page[0]);
check('… the token\'s language preselected', $checked($page) === 'fr', $checked($page));
check('… under the names', strpos($page[2], 'name="last_name"') < strpos($page[2], 'name="language"'));
$deGuest = new RealFlowBrowser($portOn);
$dePage  = $deGuest->go('/en/member/main/register?invite=' . $inviteOnDisk($siteOn, 'ohne@example.ch', null));
check('a token without a language: the page\'s preselected', $checked($dePage) === 'en', $checked($dePage));
$blur = $guest->go('/fr/member/main/register/check', ['field' => 'language', 'value' => 'it']);
check('the blur check knows the field: an unoffered value is refused', $blur[0] === 200 && str_contains($blur[2], '"valid":false'), $blur[0] . ' ' . $blur[2]);

$tok = preg_match('/name="csrf_token" value="([^"]+)"/', $page[2], $m) ? $m[1] : '';
sleep(4); // the public form's time trap
$r = $guest->go('/fr/member/main/register?invite=' . $plain, ['csrf_token' => $tok, 'first_name' => 'Eva', 'last_name' => 'Muster', 'language' => 'en', 'website' => '', 'fax' => '']);
check('the redemption goes through', in_array($r[0], [302, 303], true) && str_contains((string)($r[1]['location'] ?? ''), '/member/main/register/danke'), $r[0] . ' ' . (string)($r[1]['location'] ?? ''));
$rows = array_column((array)json_decode((string)file_get_contents($siteOn . '/data/framework/member/accounts.json'), true), null, 'email');
check('… the account has the language CONFIRMED, not the token\'s', ($rows['eingeladen@example.ch']['language'] ?? null) === 'en', json_encode($rows['eingeladen@example.ch'] ?? null));

// A browser with a signed-in member opens an invitation (forwarded, or the inviter's own
// browser): the link's language, not the signed-in account's — #[UrlLanguage].
$signedInvite = $b->go('/en/member/main/register?invite=' . $inviteOnDisk($siteOn, 'weitergeleitet@example.ch', 'en'));
check('signed in (French account) + /en invitation: 200 in English, no redirect', $signedInvite[0] === 200 && $langOf($signedInvite) === 'en', $signedInvite[0] . ' ' . $langOf($signedInvite) . ' ' . (string)($signedInvite[1]['location'] ?? ''));
check('… the guest switch is there again', str_contains($signedInvite[2], 'me-lang__link'));
$signedConfirm = $b->go('/en/member/main/confirm?token=nope');
check('… a confirmation link too', $signedConfirm[0] === 200 && $langOf($signedConfirm) === 'en', $signedConfirm[0] . ' ' . $langOf($signedConfirm));
$r = $b->go('/en/member/main/profile');
check('… while an account page still sheds the prefix', $r[0] === 302 && ($r[1]['location'] ?? '') === '/member/main/profile', $r[0] . ' ' . (string)($r[1]['location'] ?? ''));

// The join path (the address has an account): YES/NO, no language — the profile stays.
$join = $guest->go('/member/main/register?invite=' . $inviteOnDisk($siteOn, 'none@example.ch', 'fr'));
check('the join page asks no language', $join[0] === 200 && str_contains($join[2], 'name="decision"') && !str_contains($join[2], 'name="language"'));

[$siteOff, $portOff] = $makeSite('off', []);
$b3 = $signIn($siteOff, $portOff, 'acc-fr');
$page = $b3->go('/member/main/profile');
check('without the setting: the URL decides, also signed in', $page[0] === 200 && $langOf($page) === 'de' && $langOf($b3->go('/fr/member/main/profile')) === 'fr');
check('… and the profile offers no language', !str_contains($page[2], 'name="language"'));
$off = (new RealFlowBrowser($portOff))->go('/fr/member/main/register?invite=' . $inviteOnDisk($siteOff, 'aus@example.ch', 'fr'));
check('… and the redemption page asks none: the name form as before', $off[0] === 200 && str_contains($off[2], 'name="last_name"') && !str_contains($off[2], 'name="language"'));

echo "\n{$pass} ok, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
