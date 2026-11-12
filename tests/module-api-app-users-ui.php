<?php

/**
 * Harness of the backend screen «App-Benutzer» (Stammdaten › System › App-Benutzer, ADR-058 A9,
 * app-user plan step 5, board approved 2026-11-12; api.md «backend screen») — it replaced the
 * transitional «Geräte». CLI, temp ABS_BASE_PATH, file storage, the real TemplateRenderer and
 * FileFinder. The controller trait itself needs the backend shell; what it decides with is tested
 * here piece by piece:
 *
 *   (A) ApiDevices for the screen: a device issued for an app user, update, listAll /
 *       listForAppUser, revoke (final, E13);
 *   (B) ApiDeviceScopes: the modules' `apiDeviceScopes` collected, a held scope nobody declares
 *       still visible, a forged switch dropped, config errors fail fast; the minimum role per realm;
 *   (C) ApiDeviceAccess: only an admin governs, never for a person above him;
 *   (D) ApiAppUserListing: tabs, the two searches, the fixed sorts, «heute / gestern»;
 *   (E) the app's mails (ADR-058): code, new device, e-mail changed;
 *   (F) ApiAppUserWindow: the person picker (free first; taken / above the actor disabled with the
 *       reason), the function switches (disabled by the person's or the actor's role with
 *       «Braucht mindestens …»), keep() (a forged post neither adds nor drops a locked switch),
 *       the service's refusals worded on their field — taken from the REAL service —, device state
 *       and words;
 *   (G) the templates: list row (active / read-only / deactivated), ⋮ menu, action cell, tabs,
 *       list; the window new (person first, then e-mail proposed and switches), existing,
 *       read-only for a higher person, deactivated; field errors on the field; the confirms
 *       (deactivate, activate, block a device); help per field — escaping, no script (Rule 7);
 *   (H) the wiring: navigation seed (App-Benutzer replaces Geräte), role ADMIN (module role), host
 *       mount + role labels, layout pin, «Geräte» removed without a trace.
 *
 * Run: php tests/module-api-app-users-ui.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "module-api-app-users-ui: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}

date_default_timezone_set('Europe/Zurich');
$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-api-app-users-ui-' . bin2hex(random_bytes(4));
mkdir($base . '/data', 0777, true);
define('ABS_BASE_PATH', $base);
define('DEBUG', false);

require $autoload;
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';

// module-api is not in the monorepo's composer map — load it directly.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Z77\\Module\\Api\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../packages/module-api/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Z77\Core\Config\AuthRole;
use Z77\Core\Config\Config;
use Z77\Core\Config\ConfigManager;
use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Libraries\FileFinder;
use Z77\Core\Services\ModuleManager;
use Z77\Core\Services\TemplateRenderer;
use Z77\Module\Api\Entities\ApiAppUser;
use Z77\Module\Api\Entities\ApiDevice;
use Z77\Module\Api\Services\ApiAppUserDenied;
use Z77\Module\Api\Services\ApiAppUsers;
use Z77\Module\Api\Services\ApiDevices;
use Z77\Module\Api\Services\ApiDeviceScopes;
use Z77\Module\Api\Ui\ApiAppUserLayout;
use Z77\Module\Api\Ui\ApiAppUserListing;
use Z77\Module\Api\Ui\ApiAppUserWindow;
use Z77\Module\Api\Ui\ApiDeviceAccess;
use Z77\Module\Backend\Ui\Controllers\System\BackendUserController;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Auth\LoginCodeStore;
use Z77\Shared\Entities\BackendUser;
use Z77\Shared\Libraries\Convention\Naming;
use Z77\Shared\Services\AuthService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

function throws(callable $fn, string $class): bool
{
    try { $fn(); } catch (\Throwable $e) { return $e instanceof $class; }
    return false;
}

/** The refusal a service call throws (null = none). */
function refusal(callable $fn): ?\Throwable
{
    try { $fn(); } catch (\Throwable $e) { return $e; }
    return null;
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

// ── fixtures ─────────────────────────────────────────────────────────────────

$root = str_replace('\\', '/', dirname(__DIR__));
@mkdir($base . '/config/vendor', 0777, true);
@mkdir($base . '/var/cache', 0777, true);
file_put_contents($base . '/config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n"
    . "'Z77\\\\Shared\\\\' => ['sourcePaths' => ['{$root}/packages/kernel/shared']],\n"
    . "'Z77\\\\Module\\\\Api\\\\' => ['sourcePaths' => ['{$root}/packages/module-api']],\n]];");

DI::getInstance(true)
    ->set('CacheManager', CacheManager::class, true)
    ->set('FileFinder', fn($c) => new FileFinder($c->get('CacheManager')), true)
    ->set('ConfigManager', fn($c) => new ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true);
DI::getCacheManager()->setCacheDir($base . '/var/cache');
$uem = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
DI::getInstance()->set('UnifiedEntityManager', $uem, true);

$fakeModuleManager = function (array $configsByModule): ModuleManager {
    $mm = new class extends ModuleManager {
        public array $fakeConfigs = [];
        public function __construct() {} // parent boot deliberately skipped
        public function getModuleKeys(): array { return array_keys($this->fakeConfigs); }
        public function getModuleConfig(string $moduleKey): ?Config
        {
            return isset($this->fakeConfigs[$moduleKey]) ? new Config($this->fakeConfigs[$moduleKey]) : null;
        }
    };
    $mm->fakeConfigs = $configsByModule;
    return $mm;
};

$user = function (string $username, string $name, array $roles, string $email = '') use ($uem): BackendUser {
    $u = new BackendUser();
    $u->setUsername($username);
    $u->setName($name);
    $u->setRoles($roles);
    if ($email !== '') {
        $u->setEmail($email);
    }
    $uem->persist($u);
    $uem->flush();
    return $u;
};
$super  = $user('sup', 'Sabine Super', [AuthRole::SUPER_USER]);
$admin  = $user('adm', 'Anna Admin', [AuthRole::ADMIN]);
$editor = $user('edi', 'Eric Editor', [AuthRole::EDITOR]);
$plain  = $user('solo', '', [AuthRole::EDITOR]);
$nina   = $user('nhu', 'Nina Huber', [AuthRole::EDITOR], 'Nina.Huber@Example.ch');
$kim    = $user('kim', 'Kim Keller', [AuthRole::ADMIN]);

$declared = $fakeModuleManager(['receipt' => ['apiDeviceScopes' => [
    'receipt.capture' => ['label' => 'Belege erfassen', 'hint' => 'Foto und Betrag', 'realms' => ['backend' => AuthRole::EDITOR]],
    'order.capture'   => ['label' => 'Auftrag erfassen', 'realms' => ['backend' => AuthRole::ADMIN]],
]]]);
DI::getInstance()->set('ModuleManager', $declared, true);
$scopesSvc = new ApiDeviceScopes($declared);
$devices   = new ApiDevices($uem, $scopesSvc);
$appUsers  = new ApiAppUsers($uem, $scopesSvc, $devices, new LoginCodeStore($base . '/var/lib/api/login'), static fn(): bool => true);
$appOf     = fn(BackendUser $u, int $actor): ApiAppUser => $appUsers->create($actor, $u->getUsername() . '@example.ch', ApiAppUser::REALM_BACKEND, (string) $u->getId(), ['receipt.capture']);
$editorApp = $appOf($editor, (int) $admin->getId());
$adminApp  = $appOf($admin, (int) $admin->getId());
$superApp  = $appOf($super, (int) $super->getId());
$labels    = BackendUserController::roleLabels();
$tool      = new ApiAppUserWindow($scopesSvc, $labels);

// ── A. ApiDevices: what the screen needs ─────────────────────────────────────
echo "A. ApiDevices — issue, update, lists, revoke\n";

$now = time();
$dev = $devices->issue($editorApp, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Version/18.0 Mobile Safari/605', $now)['device'];
check('A1 a device of a sign-in: name = what the browser said, kept apart as `agent`; app user and owner (the linked backend user) on the row, no scopes of its own',
    $dev->getLabel() === 'Safari · iPhone' && $dev->getAgent() === 'Safari · iPhone' && $dev->getAppUserId() === (int) $editorApp->getId()
    && $dev->getOwnerId() === (int) $editor->getId() && $dev->getScopes() === []
    && str_contains((string) file_get_contents($base . '/data/framework/api/devices.json'), '"app_user_id"'));
check('A3 revoke: true once, false when already blocked (E13: final)',
    $devices->revoke((int) $dev->getId()) && !$devices->revoke((int) $dev->getId()) && $devices->find((int) $dev->getId())?->isRevoked() === true);

$adminDev = $devices->issue($adminApp, 'Mozilla/5.0 (Linux; Android 14) Chrome/131 Mobile', $now + 1)['device'];
$superDev = $devices->issue($superApp, null, $now + 2)['device'];
check('A4 listAll: every device, newest use first; listForAppUser: the app user\'s only',
    array_map(fn(ApiDevice $d) => (int) $d->getId(), $devices->listAll()) === [(int) $superDev->getId(), (int) $adminDev->getId(), (int) $dev->getId()]
    && array_map(fn(ApiDevice $d) => (int) $d->getId(), $devices->listForAppUser((int) $adminApp->getId())) === [(int) $adminDev->getId()]);

// ── B. ApiDeviceScopes ───────────────────────────────────────────────────────
echo "B. ApiDeviceScopes — the modules' declarations\n";

$scopes = new ApiDeviceScopes($fakeModuleManager([
    'api'     => ['apiServices' => []],
    'receipt' => ['apiDeviceScopes' => ['receipt.capture' => ['label' => 'Belege erfassen', 'hint' => 'Foto und Betrag']]],
    'content' => ['apiDeviceScopes' => ['content.edit' => ['label' => 'Inhalte bearbeiten']]],
]));
check('B1 collected across modules in declaration order, label + hint (no realms declared → none)',
    $scopes->all() === ['receipt.capture' => ['label' => 'Belege erfassen', 'hint' => 'Foto und Betrag', 'realms' => []], 'content.edit' => ['label' => 'Inhalte bearbeiten', 'hint' => '', 'realms' => []]]);
check('B2 label: the declared words, else the key itself', $scopes->label('receipt.capture') === 'Belege erfassen' && $scopes->label('mail.send') === 'mail.send');
check('B3 choices: a held scope nobody declares stays visible (to switch it off)',
    array_keys($scopes->choices(['mail.send'])) === ['receipt.capture', 'content.edit', 'mail.send']);
check('B4 pick: only offered scopes survive a post — a forged one, a non-string, a non-array dropped',
    $scopes->pick(['receipt.capture', 'admin.all', ['x'], 'receipt.capture']) === ['receipt.capture']
    && $scopes->pick('receipt.capture') === [] && $scopes->pick(['mail.send'], ['mail.send']) === ['mail.send'] && $scopes->pick(['mail.send']) === []);
check('B5 config errors fail fast: bad name, missing label, declared twice',
    throws(fn() => (new ApiDeviceScopes($fakeModuleManager(['a' => ['apiDeviceScopes' => ['Receipt' => ['label' => 'x']]]])))->all(), \LogicException::class)
    && throws(fn() => (new ApiDeviceScopes($fakeModuleManager(['a' => ['apiDeviceScopes' => ['a.b' => ['hint' => 'x']]]])))->all(), \LogicException::class)
    && throws(fn() => (new ApiDeviceScopes($fakeModuleManager(['a' => ['apiDeviceScopes' => ['a.b' => ['label' => 'x']]], 'b' => ['apiDeviceScopes' => ['a.b' => ['label' => 'y']]]])))->all(), \LogicException::class));
check('B6 no module declares anything → nothing offered', (new ApiDeviceScopes($fakeModuleManager(['api' => []])))->all() === []);
$ranked = new ApiDeviceScopes($fakeModuleManager(['receipt' => ['apiDeviceScopes' => [
    'receipt.capture' => ['label' => 'Belege erfassen', 'realms' => ['backend' => AuthRole::EDITOR]],
    'album.add'       => ['label' => 'Fotos', 'realms' => ['backend' => AuthRole::EDITOR, 'member' => AuthRole::MEMBER]],
]]]));
check('B7 the minimum role per realm: EDITOR and up hold receipt.capture, a VISITOR not, no roles nobody; no `member` key = never for members',
    $ranked->holdable('receipt.capture', 'backend', [AuthRole::EDITOR]) && $ranked->holdable('receipt.capture', 'backend', [AuthRole::SUPER_USER])
    && !$ranked->holdable('receipt.capture', 'backend', [AuthRole::VISITOR]) && !$ranked->holdable('receipt.capture', 'backend', [])
    && !$ranked->holdable('receipt.capture', 'member', [AuthRole::SUPER_USER]) && $ranked->holdable('album.add', 'member', [AuthRole::MEMBER]));
check('B8 filter keeps what the roles reach — a scope without `realms` is for nobody, an undeclared one too',
    $ranked->filter(['receipt.capture', 'album.add', 'mail.send'], 'backend', [AuthRole::EDITOR]) === ['receipt.capture', 'album.add']
    && $scopes->filter(['receipt.capture'], 'backend', [AuthRole::SUPER_USER]) === []);
check('B9 a malformed `realms` fails fast: unknown realm, unknown role, not a map',
    throws(fn() => (new ApiDeviceScopes($fakeModuleManager(['a' => ['apiDeviceScopes' => ['a.b' => ['label' => 'x', 'realms' => ['cron' => AuthRole::EDITOR]]]]])))->all(), \LogicException::class)
    && throws(fn() => (new ApiDeviceScopes($fakeModuleManager(['a' => ['apiDeviceScopes' => ['a.b' => ['label' => 'x', 'realms' => ['backend' => 'boss']]]]])))->all(), \LogicException::class)
    && throws(fn() => (new ApiDeviceScopes($fakeModuleManager(['a' => ['apiDeviceScopes' => ['a.b' => ['label' => 'x', 'realms' => 'backend']]]])))->all(), \LogicException::class));

// ── C. ApiDeviceAccess ───────────────────────────────────────────────────────
echo "C. ApiDeviceAccess — the actor rule\n";

$asEditor = new ApiDeviceAccess((int) $editor->getId(), [AuthRole::EDITOR]);
$asAdmin  = new ApiDeviceAccess((int) $admin->getId(), [AuthRole::ADMIN]);
$asSuper  = new ApiDeviceAccess((int) $super->getId(), [AuthRole::SUPER_USER]);
$nobody   = new ApiDeviceAccess(0, []);
check('C1 an editor does not govern (A9: only an admin manages app users)', !$asEditor->governs() && !$asEditor->mayActFor($plain));
check('C2 an admin governs: acts for himself and for persons not above him — not for a super user; a gone person is his',
    $asAdmin->governs() && $asAdmin->mayActFor($editor) && $asAdmin->mayActFor($admin) && !$asAdmin->mayActFor($super) && $asAdmin->mayActFor(null));
check('C3 a super user acts for everyone; nobody behind the session: nothing',
    $asSuper->mayActFor($super) && $asSuper->mayActFor($admin) && !$nobody->governs() && !$nobody->mayActFor($editor));
check('C4 person name: «Name (username)», the username alone, «(gelöscht)»; a member link is named as such (A8)',
    ApiDeviceAccess::ownerName($editor) === 'Eric Editor (edi)' && ApiDeviceAccess::ownerName($plain) === 'solo' && ApiDeviceAccess::ownerName(null) === '(gelöscht)'
    && ApiAppUserWindow::personName(new ApiAppUser(['linked_realm' => 'member', 'linked_id' => '42']), null) === 'Mitglied 42');
$accessSrc = (string) file_get_contents($root . '/packages/module-api/src/Ui/ApiDeviceAccess.php');
check('C5 the device screen\'s leftovers are gone: no sees(), no ownerChoices() (the pairing window\'s owner list)',
    !str_contains($accessSrc, 'function sees') && !str_contains($accessSrc, 'function ownerChoices'));

// ── D. ApiAppUserListing ─────────────────────────────────────────────────────
echo "D. ApiAppUserListing — tabs, search, sort\n";

$appUsers->recordLogin($editorApp, $now - 7200);
$appUsers->recordLogin($adminApp, $now - 3600);
$appUsers->deactivate((int) $admin->getId(), (int) $editorApp->getId());
$definition = ApiAppUserListing::definition();
$usersById  = [];
foreach ([$super, $admin, $editor, $plain, $nina, $kim] as $u) {
    $usersById[(int) $u->getId()] = $u;
}
$nameOf = fn(ApiAppUser $a): string => ApiAppUserWindow::personName($a, $usersById[(int) $a->getLinkedId()] ?? null);
$emails = fn(array $rows): array => array_map(fn(ApiAppUser $a) => $a->getEmail(), $rows);
$all    = $appUsers->all();
check('D1 the definition: id, region, view tabs (Aktiv default), default sort «zuletzt angemeldet» newest first',
    $definition->region() === 'api-app-user-find-list' && $definition->read([])->extra('view') === 'active'
    && $definition->read([])->sort === 'login' && $definition->read([])->descending && $definition->sorts() === ['person', 'email', 'login']);
check('D2 «Aktiv» hides the deactivated app user, «Alle» shows it; never signed in sorts last',
    $emails(ApiAppUserListing::apply($all, $definition->read([]), $nameOf)) === ['adm@example.ch', 'sup@example.ch']
    && $emails(ApiAppUserListing::apply($all, $definition->read(['view' => 'all']), $nameOf)) === ['adm@example.ch', 'edi@example.ch', 'sup@example.ch']);
check('D3 searches: a part of the person / of the e-mail, case-insensitive',
    $emails(ApiAppUserListing::apply($all, $definition->read(['view' => 'all', 'f_person' => 'ERIC']), $nameOf)) === ['edi@example.ch']
    && $emails(ApiAppUserListing::apply($all, $definition->read(['view' => 'all', 'f_email' => 'SUP@']), $nameOf)) === ['sup@example.ch']
    && ApiAppUserListing::apply($all, $definition->read(['f_person' => 'nobody']), $nameOf) === []);
check('D4 the fixed sorts: person A–Z, e-mail',
    $emails(ApiAppUserListing::apply($all, $definition->read(['view' => 'all', 'sort' => 'person']), $nameOf)) === ['adm@example.ch', 'edi@example.ch', 'sup@example.ch']
    && $emails(ApiAppUserListing::apply($all, $definition->read(['view' => 'all', 'sort' => 'email', 'dir' => 'desc']), $nameOf)) === ['sup@example.ch', 'edi@example.ch', 'adm@example.ch']);
$noon = mktime(12, 0, 0, 11, 12, 2026);
check('D5 «zuletzt»: heute HH:MM, gestern HH:MM, else the date; nothing → «—»',
    ApiAppUserListing::when(date(DATE_ATOM, $noon - 3600), $noon) === 'heute 11:00'
    && ApiAppUserListing::when(date(DATE_ATOM, $noon - 86400), $noon) === 'gestern 12:00'
    && ApiAppUserListing::when(date(DATE_ATOM, $noon - 3 * 86400), $noon) === '09.11.2026'
    && ApiAppUserListing::when('') === '—' && ApiAppUserListing::date('') === '—');

// ── E. the app's mails (ADR-058) ─────────────────────────────────────────────
echo "E. mails — code, new device, e-mail changed\n";

$mailRenderer = new TemplateRenderer('Z77\\Module\\Api');
$codeMail = $mailRenderer->partial('emails/appLoginCode', ['greetingName' => 'Eric <b>', 'code' => '123456', 'device' => 'Safari · iPhone', 'when' => '12.11.2026, 09:14', 'minutes' => 15]);
check('E1 the code mail: the code, device and time, 15 minutes, «niemandem weiter» and «ruft nie an», escaped, no link',
    str_contains($codeMail, '123456') && str_contains($codeMail, 'Safari · iPhone') && str_contains($codeMail, '12.11.2026, 09:14') && str_contains($codeMail, '15 Minuten')
    && str_contains($codeMail, 'niemandem weiter') && str_contains($codeMail, 'ruft nie an') && str_contains($codeMail, 'Eric &lt;b&gt;') && !str_contains($codeMail, '<a '));
$newMail = $mailRenderer->partial('emails/appNewDevice', ['greetingName' => 'Eric', 'device' => '<i>x</i>', 'when' => '12.11.2026, 09:15']);
check('E2 «Neues Gerät angemeldet»: device and time, what to do if it was not you, escaped, no link',
    str_contains($newMail, '&lt;i&gt;x&lt;/i&gt;') && str_contains($newMail, '12.11.2026, 09:15') && str_contains($newMail, 'Warst du es nicht?') && !str_contains($newMail, '<a '));
$changedMail = $mailRenderer->partial('emails/appEmailChanged', ['greetingName' => '', 'when' => '12.11.2026, 09:16']);
check('E3 e-mail changed (to the OLD address): when, devices stay signed in, no new address, no link',
    str_contains($changedMail, '12.11.2026, 09:16') && str_contains($changedMail, 'bleiben angemeldet') && !str_contains($changedMail, '@') && !str_contains($changedMail, '<a '));

// ── F. ApiAppUserWindow ──────────────────────────────────────────────────────
echo "F. ApiAppUserWindow — picker, switches, refusals\n";

$linked  = [(string) $editor->getId() => true, (string) $admin->getId() => true, (string) $super->getId() => true];
$choices = $tool->personChoices($usersById, $linked, $asAdmin);
$byId    = array_column($choices, null, 'id');
check('F1 the picker: free persons first (by name), each with his role; then the taken ones and those above the actor, disabled with the reason',
    array_column(array_slice($choices, 0, 3), 'id') === [(int) $kim->getId(), (int) $nina->getId(), (int) $plain->getId()]
    && $byId[(int) $nina->getId()] === ['id' => (int) $nina->getId(), 'label' => 'Nina Huber (nhu) · Redaktor', 'disabled' => false]
    && $byId[(int) $editor->getId()]['disabled'] && str_ends_with($byId[(int) $editor->getId()]['label'], '— hat schon einen App-Benutzer')
    && $byId[(int) $super->getId()]['disabled'] && str_contains($byId[(int) $super->getId()]['label'], 'Super-User')
    && count(array_filter($choices, fn($c) => !$c['disabled'])) === 3
    && $tool->personChoices([$super], [], $asAdmin)[0] === ['id' => (int) $super->getId(), 'label' => 'Sabine Super (sup) · Super-User — über deiner Rolle', 'disabled' => true]);
check('F2 choosable: a free person yes; linked, above the actor, unknown no',
    ApiAppUserWindow::choosable($nina, $linked, $asAdmin) && !ApiAppUserWindow::choosable($editor, $linked, $asAdmin)
    && !ApiAppUserWindow::choosable($super, [], $asAdmin) && !ApiAppUserWindow::choosable(null, [], $asAdmin));

$sw    = $tool->functions(['receipt.capture'], 'backend', $editor, 'Eric Editor (edi)', [AuthRole::ADMIN], true);
$swBy  = array_column($sw, null, 'scope');
check('F3 switches: every declared scope; one the person\'s role does not reach is DISABLED with «Braucht mindestens Admin — Eric Editor (edi) ist Redaktor.»',
    array_keys($swBy) === ['receipt.capture', 'order.capture']
    && $swBy['receipt.capture']['enabled'] && $swBy['receipt.capture']['checked'] && $swBy['receipt.capture']['reason'] === '' && $swBy['receipt.capture']['hint'] === 'Foto und Betrag'
    && !$swBy['order.capture']['enabled'] && !$swBy['order.capture']['checked']
    && $swBy['order.capture']['reason'] === 'Braucht mindestens Admin — Eric Editor (edi) ist Redaktor.');
$swActor = array_column($tool->functions([], 'backend', $super, 'Sabine Super (sup)', [AuthRole::EDITOR], true), null, 'scope');
check('F4 … and one the ACTOR could not hold himself: «Braucht mindestens Admin — du bist Redaktor.»',
    !$swActor['order.capture']['enabled'] && $swActor['order.capture']['reason'] === 'Braucht mindestens Admin — du bist Redaktor.' && $swActor['receipt.capture']['enabled']);
$swRo   = $tool->functions(['receipt.capture'], 'backend', $super, 'Sabine Super (sup)', [AuthRole::ADMIN], false);
$swGone = array_column($tool->functions(['receipt.capture', 'mail.send'], 'member', null, 'Mitglied 4', [AuthRole::ADMIN], true), null, 'scope');
check('F5 a read-only window: every switch disabled; a member link: «Für Mitglieder nicht angeboten.»; a held scope nobody declares: «Von keinem Modul mehr angeboten.»',
    array_filter($swRo, fn($s) => $s['enabled']) === [] && $swGone['receipt.capture']['reason'] === 'Für Mitglieder nicht angeboten.'
    && $swGone['mail.send']['reason'] === 'Von keinem Modul mehr angeboten.' && $swGone['mail.send']['checked'] && !$swGone['mail.send']['enabled']);
check('F6 keep(): the posted state of an enabled switch, the held state of a disabled one — a forged post neither adds a locked scope nor drops a locked held one',
    ApiAppUserWindow::keep(['receipt.capture', 'order.capture', 'admin.all'], $sw) === ['receipt.capture']
    && ApiAppUserWindow::keep([], $sw) === []
    && ApiAppUserWindow::keep([], array_values($swGone)) === ['receipt.capture', 'mail.send']
    && ApiAppUserWindow::keep('receipt.capture', $sw) === []);

// The refusals come from the REAL service — the words stay in step with its texts.
$adminId  = (int) $admin->getId();
$explain  = fn(?\Throwable $e): array => $e === null ? ['field' => 'none', 'message' => '', 'known' => false] : ApiAppUserWindow::explain($e);
$cases = [
    'bad address'     => [fn() => $appUsers->create($adminId, 'kein-at', 'backend', (string) $nina->getId(), []), 'email', 'Bitte eine gültige E-Mail-Adresse eingeben.'],
    'address taken'   => [fn() => $appUsers->create($adminId, 'ADM@example.ch', 'backend', (string) $nina->getId(), []), 'email', 'Diese E-Mail hat schon ein anderer App-Benutzer.'],
    'already linked'  => [fn() => $appUsers->create($adminId, 'neu@example.ch', 'backend', (string) $editor->getId(), []), 'linked_id', 'Diese Person hat schon einen App-Benutzer.'],
    'unknown person'  => [fn() => $appUsers->create($adminId, 'neu@example.ch', 'backend', '9999', []), 'linked_id', 'Diese Person gibt es nicht (mehr).'],
    'a member'        => [fn() => $appUsers->create($adminId, 'neu@example.ch', 'member', '4', []), 'linked_id', 'Nur Backend-Benutzer lassen sich verknüpfen — Mitglieder folgen später.'],
    'role too low'    => [fn() => $appUsers->create($adminId, 'neu@example.ch', 'backend', (string) $nina->getId(), ['order.capture']), 'scopes', 'Die Rolle der Person reicht für eine gewählte Funktion nicht.'],
    'undeclared'      => [fn() => $appUsers->create($adminId, 'neu@example.ch', 'backend', (string) $nina->getId(), ['mail.send']), 'scopes', 'Eine gewählte Funktion bietet kein Modul an.'],
    'above the actor' => [fn() => $appUsers->update($adminId, (int) $superApp->getId(), 'sup@example.ch', []), '', 'Diesen App-Benutzer darfst du nicht ändern — die verknüpfte Person hat eine höhere Rolle als du.'],
    'not an admin'    => [fn() => $appUsers->update((int) $editor->getId(), (int) $editorApp->getId(), 'edi@example.ch', []), '', 'Nur ein Admin verwaltet App-Benutzer.'],
];
$wrong = [];
foreach ($cases as $name => [$call, $field, $message]) {
    $got = $explain(refusal($call));
    if ($got !== ['field' => $field, 'message' => $message, 'known' => true]) {
        $wrong[] = $name . ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE);
    }
}
check('F7 every refusal of ApiAppUsers lands on its field in German (e-mail ×2, person ×3, functions ×2, the window ×2)', $wrong === [], implode(' | ', $wrong));
check('F8 the store\'s busy lock worded; an unknown text: a general sentence, flagged unknown (the trait logs it)',
    ApiAppUserWindow::explain(new \RuntimeException('boom')) === ['field' => '', 'message' => 'Speichern war nicht möglich — Details im Log.', 'known' => false]
    && ApiAppUserWindow::explain(new ApiAppUserDenied("The actor could not hold scope 'x.y' himself."))['field'] === 'scopes'
    && ApiAppUserWindow::explain(new \RuntimeException('The app users are being changed by someone else — please try again.'))
        === ['field' => '', 'message' => 'Gerade ändert jemand anders die App-Benutzer — bitte nochmals versuchen.', 'known' => true]);

$expired = new ApiDevice(['label' => 'Chrome · Android', 'agent' => 'Chrome · Android', 'last_used_at' => date(DATE_ATOM, $now - ApiDevices::IDLE_SECONDS - 60)]);
$bare    = new ApiDevice(['label' => 'Device', 'agent' => 'Device', 'last_used_at' => date(DATE_ATOM, $now)]);
check('F9 device state: live, expired (idle past 180 days), revoked; words: «Safari · iPhone» → Gerät iPhone, Browser Safari',
    ApiAppUserWindow::deviceState($adminDev, $now) === 'live' && ApiAppUserWindow::deviceState($expired, $now) === 'expired'
    && ApiAppUserWindow::deviceState($devices->find((int) $dev->getId()), $now) === 'revoked'
    && ApiAppUserWindow::deviceWords($dev) === ['device' => 'iPhone', 'browser' => 'Safari']
    && ApiAppUserWindow::deviceWords($bare) === ['device' => 'Device', 'browser' => '—']);
check('F10 roles: the highest by the hierarchy, labelled by the backend\'s one source',
    ApiAppUserWindow::highestRole([AuthRole::EDITOR, AuthRole::ADMIN]) === AuthRole::ADMIN && ApiAppUserWindow::highestRole([]) === ''
    && $tool->roleLabel(AuthRole::EDITOR) === 'Redaktor' && $tool->roleLabel('') === 'ohne Rolle');

// ── G. templates ─────────────────────────────────────────────────────────────
echo "G. templates — list, window, confirms, help (Rule 7)\n";

$renderer = new TemplateRenderer('Z77\\Module\\Api');
$tpl      = 'Backend/ApiAppUserController/';
$B        = ApiAppUserLayout::BASE;
// A handler ATTRIBUTE (quoted value) — the evil e-mail below carries «onerror=» as escaped text.
$clean    = fn(string $html): bool => !preg_match('/<script|\son[a-z]+\s*=\s*["\']|javascript:|\sstyle="(?!--be-list-cols)/i', $html);
// An app user with an id: the store assigns it, mapFromArray never does.
$withId   = function (ApiAppUser $a, int $id): ApiAppUser { (new \ReflectionProperty(ApiAppUser::class, 'id'))->setValue($a, $id); return $a; };
$evilApp  = $withId(new ApiAppUser(['email' => '"><img src=x onerror=alert(1)>@x.ch', 'linked_id' => (string) $nina->getId(), 'scopes' => ['receipt.capture'], 'active' => true]), 77);
$rowCtx   = ['appUser' => $evilApp, 'personName' => 'Nina <b>Huber</b>', 'functions' => [['label' => 'Belege erfassen', 'live' => true], ['label' => 'Auftrag erfassen', 'live' => false]],
    'deviceCount' => 2, 'canAct' => true, 'definition' => $definition, 'actionBase' => $B];

$row = $renderer->partial($tpl . '_row', $rowCtx);
check('G1 row: data-entity, the phone icon opens the window with the list region as origin, the ⋮ menu, labels of the searches, live devices, «aktiv»',
    str_contains($row, 'data-entity="apiAppUser:77"') && str_contains($row, '#icon-smartphone') && str_contains($row, 'be-list__state--editable')
    && str_contains($row, 'data-window-open="' . $B . '/edit?id=77"') && str_contains($row, 'data-origin="region:api-app-user-find-list"')
    && str_contains($row, 'data-menu-open="' . $B . '/actions?id=77"') && str_contains($row, 'for="api-app-user-find-f_person"') && str_contains($row, 'for="api-app-user-find-f_email"')
    && preg_match('/data-field="devices">2</', $row) === 1 && str_contains($row, '>aktiv<') && !str_contains($row, 'be-switch'));
check('G2 row: person and e-mail escaped, a function the role no longer reaches muted with the reason, no script',
    str_contains($row, 'Nina &lt;b&gt;Huber&lt;/b&gt;') && str_contains($row, '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;@x.ch')
    && preg_match('/badge--muted" title="gilt nicht[^"]*">Auftrag erfassen</', $row) === 1 && preg_match('/badge--info">Belege erfassen</', $row) === 1 && $clean($row));
$rowRo  = $renderer->partial($tpl . '_row', ['canAct' => false] + $rowCtx);
$offApp = $withId(new ApiAppUser(['email' => 'off@x.ch', 'linked_id' => '1', 'active' => false, 'changed_at' => date(DATE_ATOM, $noon)]), 78);
$rowOff = $renderer->partial($tpl . '_row', ['appUser' => $offApp] + $rowCtx);
check('G3 a row of a person above the actor: the eye (look, not change); a deactivated row: the lock, «deaktiviert» as a pill, greyed — no switch',
    str_contains($rowRo, '#icon-eye') && str_contains($rowRo, 'höhere Rolle als du') && str_contains($rowOff, '#icon-lock') && str_contains($rowOff, '>deaktiviert<')
    && str_contains($rowOff, 'be-list__row--inactive') && !str_contains($rowOff, 'be-switch'));

$menuCtx = ['appUser' => $evilApp, 'personName' => 'Nina', 'canDeactivate' => true, 'canActivate' => false, 'region' => 'api-app-user-find-list', 'actionBase' => $B];
$menu    = $renderer->partial($tpl . 'actions', $menuCtx);
$menuOn  = $renderer->partial($tpl . 'actions', ['canDeactivate' => false, 'canActivate' => true] + $menuCtx);
$menuRo  = $renderer->partial($tpl . 'actions', ['canDeactivate' => false] + $menuCtx);
check('G4 ⋮ menu: «Öffnen» and «Deaktivieren …» (danger) as window links; deactivated: «Aktivieren …»; read-only: only «Öffnen»; no header, no «Schliessen», no «Belege …» (module-api does not know the journal)',
    str_contains($menu, 'role="menu"') && str_contains($menu, 'Öffnen') && str_contains($menu, 'data-window-open="' . $B . '/confirm-deactivate?id=77"') && str_contains($menu, 'be-menu__item--danger')
    && str_contains($menuOn, 'confirm-activate?id=77') && str_contains($menuOn, 'Aktivieren …') && !str_contains($menuOn, 'Deaktivieren')
    && !str_contains($menuRo, 'aktivieren') && !str_contains($menu, 'Schliessen') && !str_contains($menu, 'Belege') && $clean($menu));

$act = $renderer->partial($tpl . 'act', ['actionBase' => $B]);
$toolbar = $renderer->partial($tpl . 'toolbar', ['state' => $definition->read(['view' => 'all']), 'actionBase' => $B]);
check('G5 action cell «+ App-Benutzer»: primary, opens the new window over the list region; toolbar: tabs «Aktiv» · «Alle», «Alle» current',
    preg_match('/class="be-btn be-btn--primary" href="[^"]*\/add" data-window-open="[^"]*\/add" data-origin="region:api-app-user-find-list"/', $act) === 1
    && str_contains($act, '#icon-plus') && str_contains($act, '>App-Benutzer<')
    && str_contains($toolbar, 'href="' . $B . '/list">Aktiv<') && preg_match('/is-active" href="[^"]*view=all" aria-current="page">Alle</', $toolbar) === 1);

$state = $definition->read(['view' => 'all']);
$list  = $renderer->partial($tpl . 'listAction', ['rows' => [$rowCtx], 'definition' => $definition, 'state' => $state, 'paging' => $state->paging(1), 'actionBase' => $B]);
check('G6 the list: a fetch region, the kernel\'s head and find form, the row, no script',
    str_contains($list, 'data-fetch-region="api-app-user-find-list"') && str_contains($list, '<form id="api-app-user-find"') && str_contains($list, 'Alle App-Benutzer')
    && str_contains($list, 'data-entity="apiAppUser:77"') && $clean($list));

// The window. A NEW one, no person yet.
$holder = new ApiAppUser(['linked_realm' => 'backend']);
$winBase = ['window' => true, 'origin' => 'region:api-app-user-find-list', 'mask' => 'api-app-user', 'csrfToken' => 'tok', 'actionBase' => $B,
    'personRole' => '', 'readOnly' => '', 'canDeactivate' => false, 'canActivate' => false, 'devices' => [], 'facts' => [], 'entityCsrf' => '', 'errors' => []];
$newWin = $renderer->partial($tpl . 'edit', $winBase + ['isNew' => true, 'appUser' => $holder, 'person' => null, 'personName' => '(gelöscht)', 'switches' => [],
    'personChoices' => $choices, 'canEdit' => true]);
check('G7 new window, no person yet: the mask without entity, the person picker as a GET form that re-renders the window (`data-window-form="change"`), disabled options; no save, no switches, no e-mail',
    str_contains($newWin, 'data-window="api-app-user"') && !str_contains($newWin, 'data-window-entity') && str_contains($newWin, 'data-window-title="Neuer App-Benutzer"')
    && preg_match('/<form method="get" action="[^"]*\/add" class="be-list__section" data-window-form="change">/', $newWin) === 1
    && str_contains($newWin, 'name="linked_id"') && str_contains($newWin, '>— Person wählen —<') && preg_match('/value="' . $super->getId() . '" disabled>/', $newWin) === 1
    && str_contains($newWin, 'Mitglieder folgen später') && !str_contains($newWin, '>Speichern<') && !str_contains($newWin, 'name="scopes[]"') && !str_contains($newWin, 'name="email"')
    && !str_contains($newWin, '>Wählen<') && $clean($newWin));
$newPage = $renderer->partial($tpl . 'edit', ['window' => false] + $winBase + ['isNew' => true, 'appUser' => $holder, 'person' => null, 'personName' => '', 'switches' => [], 'personChoices' => $choices, 'canEdit' => true]);
check('G8 … as a page (no script): the GET form has its own «Wählen» button', str_contains($newPage, '>Wählen<') && !str_contains($newPage, 'data-window='));

$holder->setLinkedId((string) $nina->getId());
$holder->setEmail((string) $nina->getEmail());
$ninaSw = $tool->functions([], 'backend', $nina, 'Nina Huber (nhu)', [AuthRole::ADMIN], true);
$newWin = $renderer->partial($tpl . 'edit', $winBase + ['isNew' => true, 'appUser' => $holder, 'person' => $nina, 'personName' => 'Nina Huber (nhu)', 'switches' => $ninaSw,
    'personChoices' => $choices, 'canEdit' => true]);
check('G9 new window, person chosen: the person selected, her backend e-mail proposed (editable), «Speichern», the linked id in the POST form, the fields joined to it by `form=`, the switches — «Auftrag erfassen» disabled with the reason',
    preg_match('/value="' . $nina->getId() . '" selected>/', $newWin) === 1 && str_contains($newWin, 'name="linked_id" value="' . $nina->getId() . '"')
    && preg_match('/name="email" form="api-app-user-form" value="Nina\.Huber@Example\.ch"[^>]* required/', $newWin) === 1
    && str_contains($newWin, '>Speichern<') && preg_match('/name="scopes\[\]" form="api-app-user-form" value="receipt\.capture" data-help-key="scopes">/', $newWin) === 1
    && preg_match('/value="order\.capture" disabled data-help-key="scopes">/', $newWin) === 1 && str_contains($newWin, 'Braucht mindestens Admin — Nina Huber (nhu) ist Redaktor.')
    && str_contains($newWin, 'id="api-app-user-form"') && strpos($newWin, 'z77-form-actions') < strpos($newWin, 'data-window-form') && $clean($newWin));

$errWin = $renderer->partial($tpl . 'edit', ['errors' => ['email' => 'Diese E-Mail hat schon ein anderer App-Benutzer.', '' => 'Allgemein <b>']] + $winBase
    + ['isNew' => true, 'appUser' => $holder, 'person' => $nina, 'personName' => 'Nina', 'switches' => $ninaSw, 'personChoices' => $choices, 'canEdit' => true]);
check('G10 field errors land on the field: the message under the e-mail, aria-invalid, «1 Fehler» pointing at it; a general one on top, escaped',
    preg_match('/id="api-app-user-email"[^>]*aria-invalid="true"/', $errWin) === 1 && str_contains($errWin, 'data-z77-field-error>Diese E-Mail hat schon ein anderer App-Benutzer.<')
    && str_contains($errWin, 'api-app-user-email') && str_contains($errWin, 'be-modal__alert--error">Allgemein &lt;b&gt;<'));

// An EXISTING one: editable, with devices.
$adminRow  = ApiAppUserWindow::deviceWords($adminDev);
$existing  = $appUsers->find((int) $adminApp->getId());
$exCtx = ['isNew' => false, 'appUser' => $existing, 'person' => $admin, 'personName' => 'Anna Admin (adm)', 'personRole' => 'Admin',
    'switches' => $tool->functions($existing->getScopes(), 'backend', $admin, 'Anna Admin (adm)', [AuthRole::ADMIN], true), 'personChoices' => [],
    'canEdit' => true, 'canDeactivate' => true, 'entityCsrf' => 'ent', 'facts' => ['angelegt 12.11.2026 09:00 von adm', 'zuletzt angemeldet heute 11:00'],
    'devices' => [
        ['device' => $adminDev, 'words' => $adminRow, 'state' => 'live', 'canRevoke' => true],
        ['device' => $devices->find((int) $dev->getId()), 'words' => ApiAppUserWindow::deviceWords($dev), 'state' => 'revoked', 'canRevoke' => false],
    ]] + $winBase;
$exWin = $renderer->partial($tpl . 'edit', $exCtx);
check('G11 existing window: mask + entity, title «Name · App-Benutzer», «Speichern» and «Deaktivieren …» (danger, loads the question INTO the window), the person shown fixed with the role, entity token, no person picker',
    str_contains($exWin, 'data-window-entity="apiAppUser:' . $existing->getId() . '"') && str_contains($exWin, 'data-window-title="Anna Admin (adm) · App-Benutzer"')
    && str_contains($exWin, '>Speichern<') && preg_match('/class="be-btn be-btn--danger" href="[^"]*confirm-deactivate\?id=' . $existing->getId() . '" data-window-link>Deaktivieren …</', $exWin) === 1
    && preg_match('/id="api-app-user-person" value="Anna Admin \(adm\) · Admin" readonly/', $exWin) === 1 && str_contains($exWin, 'name="entity_csrf" value="ent"')
    && !str_contains($exWin, 'data-window-form') && !str_contains($exWin, 'name="linked_id"') && str_contains($exWin, 'data-app-user-state="active"') && $clean($exWin));
check('G12 … the devices: Gerät / Browser split, «Sperren …» per live device into the window, a blocked one greyed «gesperrt» without a button; the facts line',
    str_contains($exWin, 'data-app-user-devices') && str_contains($exWin, 'data-entity="apiDevice:' . $adminDev->getId() . '"')
    && str_contains($exWin, '>' . $adminRow['device'] . '<') && str_contains($exWin, '>' . $adminRow['browser'] . '<')
    && preg_match('/href="[^"]*confirm-revoke\?id=' . $adminDev->getId() . '" data-window-link>Sperren …</', $exWin) === 1
    && !str_contains($exWin, 'confirm-revoke?id=' . $dev->getId()) && str_contains($exWin, '>gesperrt<') && str_contains($exWin, 'angelegt 12.11.2026 09:00 von adm'));

$roWin = $renderer->partial($tpl . 'edit', ['canEdit' => false, 'canDeactivate' => false, 'entityCsrf' => '',
    'readOnly' => 'Sabine Super (sup) hat eine höhere Rolle als du — du kannst diesen App-Benutzer ansehen, nicht ändern.',
    'switches' => $tool->functions(['receipt.capture'], 'backend', $super, 'Sabine Super (sup)', [AuthRole::ADMIN], false),
    'devices' => [['device' => $superDev, 'words' => ApiAppUserWindow::deviceWords($superDev), 'state' => 'live', 'canRevoke' => false]]] + $exCtx);
check('G13 read-only for a person above the actor: the reason on top, no save, no «Deaktivieren …», no «Sperren …», the e-mail read-only, every switch disabled, no entity token',
    str_contains($roWin, 'data-app-user-readonly>Sabine Super (sup) hat eine höhere Rolle als du') && !str_contains($roWin, '>Speichern<') && !str_contains($roWin, 'Deaktivieren')
    && !str_contains($roWin, 'Sperren …') && preg_match('/name="email"[^>]* readonly>/', $roWin) === 1 && preg_match_all('/name="scopes\[\]"(?![^>]*disabled)/', $roWin) === 0
    && !str_contains($roWin, 'entity_csrf'));
$offWin = $renderer->partial($tpl . 'edit', ['appUser' => $appUsers->find((int) $editorApp->getId()), 'canDeactivate' => false, 'canActivate' => true] + $exCtx);
check('G14 a deactivated app user: «deaktiviert», «Aktivieren …» (secondary, into the window), no «Deaktivieren …», the hint that phones sign in anew',
    str_contains($offWin, 'data-app-user-state="inactive"') && preg_match('/class="be-btn be-btn--ghost" href="[^"]*confirm-activate\?id=\d+" data-window-link>Aktivieren …</', $offWin) === 1
    && !str_contains($offWin, 'Deaktivieren') && str_contains($offWin, 'wieder per Code an'));

$confCtx = ['window' => true, 'origin' => 'region:api-app-user-find-list', 'mask' => 'api-app-user', 'appUser' => $existing, 'personName' => 'Anna <b>', 'deviceCount' => 2,
    'blockReason' => null, 'entityCsrf' => 'st', 'csrfToken' => 'tok', 'actionBase' => $B];
$confOff = $renderer->partial($tpl . 'confirmState', ['mode' => 'deactivate'] + $confCtx);
check('G15 «Deaktivieren …»: a question without input, the answers at the bottom (`end`), «Deaktivieren» danger, «Abbrechen» back to the app user, the device count, final for the devices, the token',
    str_contains($confOff, 'z77-form-actions--end') && preg_match('/be-btn--danger"[^>]*>Deaktivieren</', $confOff) === 1
    && str_contains($confOff, 'href="' . $B . '/edit?id=' . $existing->getId() . '" data-window-link>Abbrechen<') && str_contains($confOff, 'action="' . $B . '/deactivate"')
    && str_contains($confOff, 'Alle 2 angemeldeten Geräte sind sofort abgemeldet') && str_contains($confOff, 'endgültig') && str_contains($confOff, 'Anna &lt;b&gt;')
    && str_contains($confOff, 'data-window-entity="apiAppUser:' . $existing->getId() . '"') && str_contains($confOff, 'name="entity_csrf" value="st"')
    && !preg_match('/<input(?![^>]*type="hidden")/', $confOff) && $clean($confOff));
$confOn = $renderer->partial($tpl . 'confirmState', ['mode' => 'activate', 'deviceCount' => 0] + $confCtx);
$confNo = $renderer->partial($tpl . 'confirmState', ['mode' => 'activate', 'blockReason' => 'Der App-Benutzer ist bereits aktiv.'] + $confCtx);
check('G16 «Aktivieren …»: primary «Aktivieren», the old devices stay signed out, the code goes to the app e-mail; refused: the reason, no form',
    preg_match('/be-btn--primary"[^>]*>Aktivieren</', $confOn) === 1 && str_contains($confOn, 'action="' . $B . '/activate"') && str_contains($confOn, 'früheren Geräte bleiben abgemeldet')
    && str_contains($confOn, 'adm@example.ch') && str_contains($confNo, 'bereits aktiv') && !str_contains($confNo, '<form'));
$revoke = $renderer->partial($tpl . 'confirmRevoke', ['window' => true, 'origin' => 'page', 'mask' => 'api-app-user', 'appUser' => $existing, 'device' => $adminDev, 'deviceWords' => $adminRow,
    'personName' => 'Anna Admin (adm)', 'blockReason' => null, 'entityCsrf' => 'rev', 'csrfToken' => 'tok', 'actionBase' => $B]);
check('G17 «Sperren …» of a device: in the app user\'s window (same entity), danger «Sperren» at the bottom, «Abbrechen» back to the app user, final, the token',
    str_contains($revoke, 'data-window-entity="apiAppUser:' . $existing->getId() . '"') && str_contains($revoke, 'z77-form-actions--end') && preg_match('/be-btn--danger"[^>]*>Sperren</', $revoke) === 1
    && str_contains($revoke, 'href="' . $B . '/edit?id=' . $existing->getId() . '" data-window-link>Abbrechen<') && str_contains($revoke, 'action="' . $B . '/revoke"')
    && str_contains($revoke, 'name="id" value="' . $adminDev->getId() . '"') && str_contains($revoke, 'endgültig') && str_contains($revoke, $adminRow['device'] . ' von Anna Admin (adm) sperren?') && $clean($revoke));

$helps = '';
foreach (['list.help', 'edit.help'] as $h) {
    $helps .= $renderer->partial($tpl . $h, []);
}
check('G18 help: a general part, a section per field (person, e-mail, functions, deactivate, devices); the sign-in by code named; no pairing',
    str_contains($helps, 'data-help-field="linked_id"') && str_contains($helps, 'data-help-field="email"') && str_contains($helps, 'data-help-field="scopes"')
    && str_contains($helps, 'data-help-field="active"') && str_contains($helps, 'data-help-field="devices"') && str_contains($helps, 'Code per E-Mail')
    && !str_contains($helps, 'koppeln') && $clean($helps));

// ── H. wiring ────────────────────────────────────────────────────────────────
echo "H. wiring — navigation, role, mount, «Geräte» gone\n";

$seed = json_decode((string) file_get_contents($root . '/packages/module-api/data/framework/routing/navigation.d/module-api.json'), true);
check('H1 navigation seed: «App-Benutzer» (a new key) under Stammdaten › System, backend/system/api-app-user/list; «Geräte» gone',
    is_array($seed) && count($seed) === 1 && $seed[0]['key'] === 'app-benutzer' && $seed[0]['name'] === 'App-Benutzer' && $seed[0]['parent_key'] === 'stammdaten-system'
    && [$seed[0]['module'], $seed[0]['group'], $seed[0]['controller'], $seed[0]['action']] === ['backend', 'system', 'api-app-user', 'list']);
$backendConfig = require $root . '/packages/module-backend/src/App/Config/backendConfig.inc.php';
$required = fn(string $c, string $a): string => AuthService::requiredRole($backendConfig['moduleRole'], $backendConfig['controllers'], 'system', Naming::toCamelCase($c) . 'Controller', Naming::toActionMethod($a));
check('H2 role: ADMIN for every action (the module role, A9 — no own entry); the EDITOR rule of «Geräte» is gone',
    !isset($backendConfig['controllers']['system']['ApiDeviceController']) && !isset($backendConfig['controllers']['system']['ApiAppUserController'])
    && array_unique(array_map(fn($a) => $required('api-app-user', $a), ['list', 'add', 'edit', 'actions', 'confirm-deactivate', 'deactivate', 'activate', 'confirm-revoke', 'revoke'])) === [AuthRole::ADMIN]);
$host = (string) file_get_contents($root . '/packages/module-backend/src/Ui/Controllers/System/ApiAppUserController.php');
$pin  = require $root . '/packages/module-backend/src/Ui/Config/System/apiAppUserControllerConfig.inc.php';
check('H3 the host mounts the trait and hands in the backend\'s role labels; the layout pins the list template of module-api',
    str_contains($host, 'use ApiAppUserControllerTrait;') && str_contains($host, 'extends BackendAbstractController') && str_contains($host, 'BackendUserController::roleLabels()')
    && ($pin['levelElements']['body']['main'][0] ?? null) === ['nameSpace' => 'Z77\Module\Api', 'path' => 'Backend/ApiAppUserController', 'name' => 'listAction']
    && ($labels[AuthRole::SUPER_USER] ?? '') === 'Super-User');
$gone = array_filter([
    'packages/module-api/src/Ui/ApiDeviceControllerTrait.php', 'packages/module-api/src/Ui/ApiDeviceListing.php', 'packages/module-api/src/Ui/ApiDeviceLayout.php',
    'packages/module-api/res/view/templates/Backend/ApiDeviceController', 'packages/module-backend/src/Ui/Controllers/System/ApiDeviceController.php',
    'packages/module-backend/src/Ui/Config/System/apiDeviceControllerConfig.inc.php',
], fn(string $p) => file_exists($root . '/' . $p));
$refs = [];
foreach (['packages', 'tests'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $path = str_replace('\\', '/', $f->getPathname());
        if (str_contains($path, '/node_modules/') || str_contains($path, '/vendor/') || !preg_match('/\.(php|json|js)$/', $path) || str_ends_with($path, 'module-api-app-users-ui.php')) {
            continue;
        }
        if (preg_match('/ApiDeviceController|ApiDeviceListing|ApiDeviceLayout|api-device\/|"geraete"/', (string) file_get_contents($path)) === 1) {
            $refs[] = substr($path, strlen($root) + 1);
        }
    }
}
check('H4 «Geräte» removed without a trace: trait, listing, layout, templates, host, layout config — and nothing in packages/ or tests/ names them',
    $gone === [] && $refs === [], implode(', ', array_merge($gone, $refs)));
$trait = (string) file_get_contents($root . '/packages/module-api/src/Ui/ApiAppUserControllerTrait.php');
$actionsWanted = ['listAction', 'actionsAction', 'addAction', 'editAction', 'confirmDeactivateAction', 'deactivateAction', 'confirmActivateAction', 'activateAction', 'confirmRevokeAction', 'revokeAction'];
check('H5 the trait: every action of the screen, #[Csrf] on every writing one; writes only through ApiAppUsers / ApiDevices (no store file, no persist), no superglobal, no script of its own',
    array_filter($actionsWanted, fn(string $a) => !str_contains($trait, 'function ' . $a . '(')) === []
    && !preg_match('/\$_(GET|POST|SERVER|COOKIE|SESSION)\b/', $trait) && !str_contains($trait, 'app_users.json') && !str_contains($trait, 'devices.json')
    && !str_contains($trait, 'persist(') && !str_contains($trait, 'load-script')
    && preg_match_all('/#\[Csrf\]\s+protected function (\w+)/', $trait, $m) === 5 && $m[1] === ['addAction', 'editAction', 'deactivateAction', 'activateAction', 'revokeAction']);
check('H6 the sprite has the icons the rows use (phone, eye, lock)', (function () use ($root): bool {
    $sprite = (string) file_get_contents($root . '/packages/module-backend/res/view/templates/partials/icon-sprite.tpl.php');
    return str_contains($sprite, 'id="icon-smartphone"') && str_contains($sprite, 'id="icon-eye"') && str_contains($sprite, 'id="icon-lock"');
})());

// ── I. the real stack ────────────────────────────────────────────────────────
echo "I. the real stack — list, windows, writes, refusals\n";

require_once __DIR__ . '/support/real-flow-site.php';
require_once __DIR__ . '/support/real-flow-server.php';
$flow = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-app-users-flow-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($flow, 0777, true);
$site = $flow . '/site';
$hash = password_hash('Passwort-Sehr-Lang-1', PASSWORD_BCRYPT, ['cost' => 4]);
realFlowSite($site, $root, [
    ['id' => 1, 'username' => 'anna', 'name' => 'Anna Admin', 'email' => 'anna@firma.ch', 'password_hash' => $hash, 'roles' => ['admin'], 'sort_key' => 0],
    ['id' => 2, 'username' => 'sabine', 'name' => 'Sabine Super', 'email' => 'sabine@firma.ch', 'password_hash' => $hash, 'roles' => ['superUser'], 'sort_key' => 1],
    ['id' => 3, 'username' => 'eric', 'name' => 'Eric Editor', 'email' => 'Eric@Firma.ch', 'password_hash' => $hash, 'roles' => ['editor'], 'sort_key' => 2],
]);
// module-api and module-receipt (its `apiDeviceScopes`) on the site — as tests/app-shell.php does it.
$stub = (string) file_get_contents($site . '/vendor/autoload.php');
file_put_contents($site . '/vendor/autoload.php', "<?php\n"
    . 'spl_autoload_register(static function (string $class): void {' . "\n"
    . '    $prefix = ' . var_export('Z77\\Module\\Api\\', true) . ";\n"
    . '    if (str_starts_with($class, $prefix)) {' . "\n"
    . '        $file = ' . var_export($root . '/packages/module-api/src/', true) . ' . str_replace(\'\\\\\', \'/\', substr($class, strlen($prefix))) . \'.php\';' . "\n"
    . '        if (is_file($file)) { require $file; }' . "\n"
    . "    }\n"
    . "});\n"
    . preg_replace('/^<\?php\s*/', '', $stub));
file_put_contents($site . '/config/fileFinder.inc.php', str_replace(
    "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),",
    "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),\n        'Z77\\\\Module\\\\Api\\\\'      => \$entry('module-api'),\n        'Z77\\\\Module\\\\Receipt\\\\'  => \$entry('module-receipt'),",
    (string) file_get_contents($site . '/config/fileFinder.inc.php')
));
file_put_contents($site . '/config/moduleManager.inc.php', (string) preg_replace("/('backend' =>\s*array \(\s*\),)/", "$1\n    'api' => array (),\n    'receipt' => array (),",
    (string) file_get_contents($site . '/config/moduleManager.inc.php')));
// Test-side store rows (the site's own files): Sabine's app user (above the admin) with a phone.
$apiData = $site . '/data/framework/api';
mkdir($apiData, 0777, true);
$stamp = date(DATE_ATOM);
$deviceRow = static fn(int $id, int $appUserId, int $owner, string $agent): array => ['id' => $id, 'app_user_id' => $appUserId, 'owner_id' => $owner, 'label' => $agent,
    'agent' => $agent, 'scopes' => [], 'key_hash' => hash('sha256', 'k' . $id), 'created_at' => $stamp, 'last_used_at' => $stamp, 'revoked_at' => ''];
file_put_contents($apiData . '/app_users.json', json_encode([['id' => 1, 'email' => 'sabine@firma.ch', 'linked_realm' => 'backend', 'linked_id' => '2', 'scopes' => ['receipt.capture'],
    'active' => true, 'created_at' => $stamp, 'created_by' => 2, 'changed_at' => $stamp, 'changed_by' => 2, 'last_login_at' => $stamp]], JSON_PRETTY_PRINT));
file_put_contents($apiData . '/devices.json', json_encode([$deviceRow(1, 1, 2, 'Safari · iPhone')], JSON_PRETTY_PRINT));
$readRows = static fn(string $file): array => json_decode((string) file_get_contents($apiData . '/' . $file), true) ?: [];

$port = realFlowServer($site, $flow . '/sessions', $flow . '/server.log');
register_shutdown_function(static function () use ($flow): void { rrmdir($flow); });   // after the server's own shutdown

$jar = [];
/** @return array{0: int, 1: string} status, body */
$go = static function (string $path, ?array $post = null, array $headers = []) use (&$jar, $port): array {
    $head = $headers;
    if ($jar !== []) {
        $head[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($jar), $jar));
    }
    $opts = ['ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0, 'max_redirects' => 0, 'method' => $post === null ? 'GET' : 'POST'];
    if ($post !== null) {
        $head[] = 'Content-Type: application/x-www-form-urlencoded';
        $opts['content'] = http_build_query($post);
    }
    $opts['header'] = implode("\r\n", $head);
    $body   = (string) @file_get_contents("http://127.0.0.1:{$port}{$path}", false, stream_context_create(['http' => $opts]));
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int) $m[1]; continue; }
        if (preg_match('/^set-cookie:\s*([^=]+)=([^;]*)/i', $line, $c)) { $jar[$c[1]] = $c[2]; }
    }
    return [$status, $body];
};
$token = static function (string $html): string {
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) || preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m) ? $m[1] : '';
};
$fetchHead = static fn(string $csrf = ''): array => array_filter(['Sec-Fetch-Mode: cors', 'X-Requested-With: XMLHttpRequest', $csrf !== '' ? 'X-CSRF-Token: ' . $csrf : '']);
$entityToken = static fn(string $html): string => preg_match('/name="entity_csrf" value="([^"]+)"/', $html, $m) ? $m[1] : '';

$go('/backend/system/login/login', ['csrf_token' => $token($go('/login')[1]), 'username' => 'anna', 'password' => 'Passwort-Sehr-Lang-1']);
[$s, $page] = $go('/backend/system/api-app-user/list?view=all');
$csrf = $token($page);
check('I1 the list as a page (an admin): the region, Sabine\'s row read-only (eye — she is above the admin), the action cell «+ App-Benutzer», the tabs, the help',
    $s === 200 && str_contains($page, 'data-fetch-region="api-app-user-find-list"') && str_contains($page, 'Sabine Super (sabine)') && str_contains($page, '#icon-eye')
    && str_contains($page, 'data-window-open="/backend/system/api-app-user/add"') && str_contains($page, 'be-viewtabs') && $csrf !== '', "status {$s} " . substr(strip_tags($page), 0, 300));

[$s, $win] = $go('/backend/system/api-app-user/add', null, $fetchHead());
check('I2 «+ App-Benutzer» opens the new window: the person picker, Eric free, Sabine disabled (she has one), no save yet',
    $s === 200 && str_contains($win, 'data-window="api-app-user"') && preg_match('/value="3">Eric Editor \(eric\) · Redaktor</', $win) === 1
    && preg_match('/value="2" disabled>Sabine Super \(sabine\) · Super-User — hat schon einen App-Benutzer</', $win) === 1 && !str_contains($win, '>Speichern<'), "status {$s}");
[$s, $win] = $go('/backend/system/api-app-user/add?linked_id=3', null, $fetchHead());
check('I3 choosing Eric re-renders it: his backend e-mail proposed, «Belege erfassen» switchable (receipt\'s minimum role EDITOR), «Speichern»',
    $s === 200 && preg_match('/name="email" form="api-app-user-form" value="Eric@Firma\.ch"/', $win) === 1
    && preg_match('/value="receipt\.capture" data-help-key="scopes">/', $win) === 1 && str_contains($win, '>Speichern<'), "status {$s}");

[$s, $answer] = $go('/backend/system/api-app-user/add', ['csrf_token' => $csrf, '_origin' => 'region:api-app-user-find-list', 'linked_id' => '3', 'email' => 'Eric@Firma.ch', 'scopes' => ['receipt.capture', 'admin.all']], $fetchHead($csrf));
$eric = array_values(array_filter($readRows('app_users.json'), fn($r) => $r['linked_id'] === '3'))[0] ?? [];
check('I4 «Speichern» creates through the service: closes the window, reloads the list; stored lower case, the forged scope dropped, by the admin',
    $s === 200 && str_contains($answer, 'close-window') && str_contains($answer, 'refresh-region')
    && ($eric['email'] ?? '') === 'eric@firma.ch' && ($eric['scopes'] ?? null) === ['receipt.capture'] && ($eric['created_by'] ?? 0) === 1, "status {$s} " . substr($answer, 0, 300));
$ericId = (int) ($eric['id'] ?? 0);

[$s, $win] = $go('/backend/system/api-app-user/add', ['csrf_token' => $csrf, '_origin' => 'region:api-app-user-find-list', 'linked_id' => '1', 'email' => 'eric@firma.ch'], $fetchHead($csrf));
check('I5 a taken e-mail: the window comes back with the service\'s refusal on the e-mail field, nothing stored',
    $s === 200 && str_contains($win, 'data-z77-field-error>Diese E-Mail hat schon ein anderer App-Benutzer.<') && preg_match('/id="api-app-user-email"[^>]*aria-invalid="true"/', $win) === 1
    && count($readRows('app_users.json')) === 2, "status {$s} " . substr(strip_tags($win), 0, 300));

[$s, $win] = $go('/backend/system/api-app-user/edit?id=1', null, $fetchHead());
[$s2, $refused] = $go('/backend/system/api-app-user/edit?id=1', ['csrf_token' => $csrf, 'email' => 'x@firma.ch', 'scopes' => []], $fetchHead($csrf));
check('I6 Sabine\'s window is read-only for the admin (no save, no «Deaktivieren …», no «Sperren …»); a forged save is refused, nothing changes',
    $s === 200 && str_contains($win, 'data-app-user-readonly') && !str_contains($win, '>Speichern<') && !str_contains($win, '/confirm-deactivate') && !str_contains($win, '/confirm-revoke')
    && $s2 !== 0 && !str_contains($refused, 'close-window') && ($readRows('app_users.json')[0]['email'] ?? '') === 'sabine@firma.ch', "status {$s}/{$s2}");

file_put_contents($apiData . '/devices.json', json_encode([$deviceRow(1, 1, 2, 'Safari · iPhone'), $deviceRow(2, $ericId, 3, 'Chrome · Android'), $deviceRow(3, $ericId, 3, 'Safari · iPad')], JSON_PRETTY_PRINT));
[$s, $win] = $go('/backend/system/api-app-user/edit?id=' . $ericId, null, $fetchHead());
check('I7 Eric\'s window: «Speichern», «Deaktivieren …», his two devices with «Sperren …» each',
    $s === 200 && str_contains($win, '>Speichern<') && str_contains($win, 'confirm-deactivate?id=' . $ericId) && str_contains($win, 'confirm-revoke?id=2') && str_contains($win, 'confirm-revoke?id=3')
    && !str_contains($win, 'confirm-revoke?id=1'), "status {$s}");
[, $confirm] = $go('/backend/system/api-app-user/confirm-revoke?id=2', null, $fetchHead());
[$s, $answer] = $go('/backend/system/api-app-user/revoke', ['csrf_token' => $csrf, 'id' => '2', 'entity_csrf' => $entityToken($confirm)], $fetchHead($csrf));
$devs = array_column($readRows('devices.json'), null, 'id');
check('I8 «Sperren …» → «Sperren»: the device is blocked, the window shows Eric again (open-window replace), the other device untouched',
    $s === 200 && str_contains($answer, 'open-window') && str_contains($answer, 'edit?id=' . $ericId) && ($devs[2]['revoked_at'] ?? '') !== '' && ($devs[3]['revoked_at'] ?? 'x') === '', "status {$s} " . substr($answer, 0, 200));
[, $confirm] = $go('/backend/system/api-app-user/confirm-deactivate?id=' . $ericId, null, $fetchHead());
[$s, $answer] = $go('/backend/system/api-app-user/deactivate', ['csrf_token' => $csrf, 'id' => (string) $ericId, 'entity_csrf' => $entityToken($confirm)], $fetchHead($csrf));
$devs = array_column($readRows('devices.json'), null, 'id');
$eric = array_column($readRows('app_users.json'), null, 'id')[$ericId] ?? [];
check('I9 «Deaktivieren …» → «Deaktivieren»: inactive, every device signed out, Sabine\'s phone untouched; the question named «1» live device',
    $s === 200 && str_contains($confirm, 'Das angemeldete Gerät ist sofort abgemeldet') && ($eric['active'] ?? true) === false
    && ($devs[3]['revoked_at'] ?? '') !== '' && ($devs[1]['revoked_at'] ?? 'x') === '', "status {$s} " . substr($answer, 0, 200));
[, $confirm] = $go('/backend/system/api-app-user/confirm-activate?id=' . $ericId, null, $fetchHead());
[$s, $answer] = $go('/backend/system/api-app-user/activate', ['csrf_token' => $csrf, 'id' => (string) $ericId, 'entity_csrf' => $entityToken($confirm)], $fetchHead($csrf));
$eric = array_column($readRows('app_users.json'), null, 'id')[$ericId] ?? [];
[$sOld] = $go('/backend/system/api-app-user/deactivate', ['csrf_token' => $csrf, 'id' => (string) $ericId, 'entity_csrf' => 'forged'], $fetchHead($csrf));
check('I10 «Aktivieren …» → «Aktivieren»: active again, no device comes back; a forged entity token changes nothing',
    $s === 200 && ($eric['active'] ?? false) === true && count(array_filter($readRows('devices.json'), fn($d) => $d['app_user_id'] === $ericId && $d['revoked_at'] === '')) === 0
    && (array_column($readRows('app_users.json'), null, 'id')[$ericId]['active'] ?? false) === true, "status {$s}/{$sOld}");
[$s] = $go('/backend/system/api-device/list');
check('I11 «Geräte» is gone: /backend/system/api-device/list is no page any more', $s !== 200, (string) $s);

// ── result ───────────────────────────────────────────────────────────────────

if ($fail === 0) {
    rrmdir($base);
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
