<?php

/**
 * Harness of the backend screen «Geräte» (Stammdaten › System › Geräte, ADR-057, api.md
 * «backend screen», board 2026-11-12) — CLI, temp ABS_BASE_PATH, file storage, the real
 * TemplateRenderer and FileFinder. The controller trait itself needs the backend shell; what it
 * decides with is tested here piece by piece:
 *
 *   (A) ApiDevices' additions for the screen: pairingState (waiting → paired with the device id,
 *       withdrawn / expired → gone), cancelPairingCode, update (name + scopes, a blocked device
 *       stays as it is — E13), the browser label kept apart from the name, listAll / listFor;
 *   (B) ApiDeviceScopes: the modules' `apiDeviceScopes` collected, a held scope nobody declares
 *       still visible, a forged switch dropped, config errors fail fast;
 *   (C) ApiDeviceAccess: own devices for everyone, all for the user governor, never for an owner
 *       above him, the actor first among the owners;
 *   (D) ApiDeviceListing: tabs, the two searches, the fixed sorts, «heute / gestern»;
 *   (E) ApiDevicePairing: the app URL, the canonical origin and its development fallback, the QR;
 *   (F) the templates: list row, ⋮ menu, pairing window with the live status box, device window,
 *       confirm — escaping, no script, no inline handler (Rule 7);
 *   (G) the wiring: navigation seed, backend mount, role, layout pin, icon.
 *
 * Run: php tests/module-api-devices-ui.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "module-api-devices-ui: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}

date_default_timezone_set('Europe/Zurich');
$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-api-devices-ui-' . bin2hex(random_bytes(4));
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
use Z77\Module\Api\Entities\ApiDevice;
use Z77\Module\Api\Services\ApiDevices;
use Z77\Module\Api\Services\ApiDeviceScopes;
use Z77\Module\Api\Ui\ApiDeviceAccess;
use Z77\Module\Api\Ui\ApiDeviceLayout;
use Z77\Module\Api\Ui\ApiDeviceListing;
use Z77\Module\Api\Ui\ApiDevicePairing;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Entities\BackendUser;

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

$user = function (string $username, string $name, array $roles) use ($uem): BackendUser {
    $u = new BackendUser();
    $u->setUsername($username);
    $u->setName($name);
    $u->setRoles($roles);
    $uem->persist($u);
    $uem->flush();
    return $u;
};
$super  = $user('sup', 'Sabine Super', [AuthRole::SUPER_USER]);
$admin  = $user('adm', 'Anna Admin', [AuthRole::ADMIN]);
$editor = $user('edi', 'Eric Editor', [AuthRole::EDITOR]);
$plain  = $user('solo', '', [AuthRole::EDITOR]);

$pairingDir = $base . '/var/lib/api/pairing';
$devices    = new ApiDevices($uem, $pairingDir);

// ── A. ApiDevices: what the screen needs ─────────────────────────────────────
echo "A. ApiDevices — pairing state, withdraw, update, lists\n";

$now  = time();
$code = $devices->createPairingCode((int) $editor->getId(), '', ['receipt.capture'], $now);
check('A1 a fresh code waits; spaces, dashes and case forgiven; a non-code is gone',
    $devices->pairingState($code, $now)['state'] === 'waiting'
    && $devices->pairingState(strtolower(str_replace('-', ' ', $code)), $now)['state'] === 'waiting'
    && $devices->pairingState('nope', $now) === ['state' => 'gone', 'deviceId' => null]);
$paired = $devices->redeemPairingCode($code, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Version/18.0 Mobile Safari/605', $now);
$state  = $devices->pairingState($code, $now);
check('A2 redeemed → paired, with the new device id', $paired !== null && $state['state'] === 'paired' && $state['deviceId'] === (int) $paired['device']->getId(), json_encode($state));
$dev = $paired['device'];
check('A3 no name given: name = what the browser said, and that stays apart as `agent`',
    $dev->getLabel() === 'Safari · iPhone' && $dev->getAgent() === 'Safari · iPhone'
    && str_contains((string) file_get_contents($base . '/data/framework/api/devices.json'), '"agent"'));

$withdrawn = $devices->createPairingCode((int) $editor->getId(), 'x', [], $now);
check('A4 «Neuer Code»: a withdrawn code is gone and can no longer be redeemed',
    $devices->cancelPairingCode($withdrawn) && $devices->pairingState($withdrawn, $now)['state'] === 'gone'
    && $devices->redeemPairingCode($withdrawn, null, $now) === null && !$devices->cancelPairingCode($withdrawn));
$expired = $devices->createPairingCode((int) $editor->getId(), 'x', [], $now - ApiDevices::PAIRING_TTL_SECONDS - 5);
check('A5 an expired code is gone', $devices->pairingState($expired, $now)['state'] === 'gone');

$u = $devices->update((int) $dev->getId(), '  iPhone Eric  ', ['receipt.capture', 'content.edit', 'receipt.capture']);
check('A6 update: name trimmed, scopes deduplicated', $u !== null && $u->getLabel() === 'iPhone Eric' && $u->getScopes() === ['receipt.capture', 'content.edit']);
$u = $devices->update((int) $dev->getId(), '   ', []);
check('A7 an empty name falls back to the browser label', $u !== null && $u->getLabel() === 'Safari · iPhone' && $u->getScopes() === []);
check('A8 a malformed scope is refused, an unknown device is null',
    throws(fn() => $devices->update((int) $dev->getId(), 'x', ['Not A Scope']), \InvalidArgumentException::class)
    && $devices->update(99999, 'x', []) === null);
$devices->update((int) $dev->getId(), 'iPhone Eric', ['receipt.capture']);
$devices->revoke((int) $dev->getId());
check('A9 a blocked device cannot be changed (E13: final)', $devices->update((int) $dev->getId(), 'again', ['receipt.capture']) === null
    && $devices->find((int) $dev->getId())->getLabel() === 'iPhone Eric');

$adminDev = $devices->redeemPairingCode($devices->createPairingCode((int) $admin->getId(), 'Pixel Anna', ['receipt.capture'], $now + 1), 'Mozilla/5.0 (Linux; Android 14) Chrome/131 Mobile', $now + 1)['device'];
$superDev = $devices->redeemPairingCode($devices->createPairingCode((int) $super->getId(), 'iPad Büro', [], $now + 2), null, $now + 2)['device'];
check('A10 listAll: every device, newest use first; listFor: the owner\'s only',
    array_map(fn(ApiDevice $d) => $d->getLabel(), $devices->listAll()) === ['iPad Büro', 'Pixel Anna', 'iPhone Eric']
    && array_map(fn(ApiDevice $d) => $d->getLabel(), $devices->listFor((int) $editor->getId())) === ['iPhone Eric']);

touch($pairingDir . '/' . hash('sha256', str_replace('-', '', $code)) . '.paired', $now - ApiDevices::PAIRING_TTL_SECONDS - 10);
$devices->createPairingCode((int) $editor->getId(), 'sweep', [], $now);
check('A11 the paired marker is swept like an old code', !is_file($pairingDir . '/' . hash('sha256', str_replace('-', '', $code)) . '.paired'));

// ── B. ApiDeviceScopes ───────────────────────────────────────────────────────
echo "B. ApiDeviceScopes — the modules' declarations\n";

$scopes = new ApiDeviceScopes($fakeModuleManager([
    'api'     => ['apiServices' => []],
    'receipt' => ['apiDeviceScopes' => ['receipt.capture' => ['label' => 'Belege erfassen', 'hint' => 'Foto und Betrag']]],
    'content' => ['apiDeviceScopes' => ['content.edit' => ['label' => 'Inhalte bearbeiten']]],
]));
check('B1 collected across modules in declaration order, label + hint',
    $scopes->all() === ['receipt.capture' => ['label' => 'Belege erfassen', 'hint' => 'Foto und Betrag'], 'content.edit' => ['label' => 'Inhalte bearbeiten', 'hint' => '']]);
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

// ── C. ApiDeviceAccess ───────────────────────────────────────────────────────
echo "C. ApiDeviceAccess — the existing permission model\n";

$asEditor = new ApiDeviceAccess((int) $editor->getId(), [AuthRole::EDITOR]);
$asAdmin  = new ApiDeviceAccess((int) $admin->getId(), [AuthRole::ADMIN]);
$asSuper  = new ApiDeviceAccess((int) $super->getId(), [AuthRole::SUPER_USER]);
$nobody   = new ApiDeviceAccess(0, []);
$allUsers = [$super, $admin, $editor, $plain];
$editorDev = $devices->find((int) $dev->getId());

check('C1 an editor: his own devices, nobody else\'s; he does not govern',
    !$asEditor->governs() && $asEditor->mayActFor($editor) && !$asEditor->mayActFor($plain) && !$asEditor->mayActFor(null)
    && $asEditor->sees($editorDev) && !$asEditor->sees($adminDev));
check('C2 an admin governs: sees all, acts for himself and for users not above him — not for a super user, a gone owner is his',
    $asAdmin->governs() && $asAdmin->sees($superDev) && $asAdmin->mayActFor($editor) && $asAdmin->mayActFor($admin)
    && !$asAdmin->mayActFor($super) && $asAdmin->mayActFor(null));
check('C3 a super user acts for everyone', $asSuper->mayActFor($super) && $asSuper->mayActFor($admin) && $asSuper->mayActFor($editor));
check('C4 no backend user behind the session: nothing', !$nobody->governs() && !$nobody->mayActFor($editor) && !$nobody->sees($editorDev) && $nobody->ownerChoices($allUsers) === []);
check('C5 owner choices: the actor first, then those he may act for, by name; an editor only himself',
    $asAdmin->ownerChoices($allUsers) === [(int) $admin->getId() => 'Anna Admin (adm)', (int) $editor->getId() => 'Eric Editor (edi)', (int) $plain->getId() => 'solo']
    && $asEditor->ownerChoices($allUsers) === [(int) $editor->getId() => 'Eric Editor (edi)']);
check('C6 owner name: «Name (username)», the username alone, «(gelöscht)»',
    ApiDeviceAccess::ownerName($editor) === 'Eric Editor (edi)' && ApiDeviceAccess::ownerName($plain) === 'solo' && ApiDeviceAccess::ownerName(null) === '(gelöscht)');

// ── D. ApiDeviceListing ──────────────────────────────────────────────────────
echo "D. ApiDeviceListing — tabs, search, sort\n";

$definition = ApiDeviceListing::definition();
$users      = [(int) $super->getId() => $super, (int) $admin->getId() => $admin, (int) $editor->getId() => $editor];
$ownerOf    = fn(ApiDevice $d): string => ApiDeviceAccess::ownerName($users[$d->getOwnerId()] ?? null);
$labels     = fn(array $rows): array => array_map(fn(ApiDevice $d) => $d->getLabel(), $rows);
$all        = $devices->listAll();

check('D1 the definition: id, region, view tabs (Aktiv default), default sort «zuletzt benutzt» newest first',
    $definition->region() === 'api-device-find-list' && $definition->read([])->extra('view') === 'active'
    && $definition->read([])->sort === 'used' && $definition->read([])->descending && $definition->sorts() === ['label', 'owner', 'created', 'used']);
check('D2 «Aktiv» hides the blocked device, «Alle» shows it',
    $labels(ApiDeviceListing::apply($all, $definition->read([]), $ownerOf)) === ['iPad Büro', 'Pixel Anna']
    && $labels(ApiDeviceListing::apply($all, $definition->read(['view' => 'all']), $ownerOf)) === ['iPad Büro', 'Pixel Anna', 'iPhone Eric']);
check('D3 searches: a part of the name / of the owner, case-insensitive',
    $labels(ApiDeviceListing::apply($all, $definition->read(['view' => 'all', 'f_label' => 'PIXEL']), $ownerOf)) === ['Pixel Anna']
    && $labels(ApiDeviceListing::apply($all, $definition->read(['view' => 'all', 'f_owner' => 'edi']), $ownerOf)) === ['iPhone Eric']
    && ApiDeviceListing::apply($all, $definition->read(['f_owner' => 'nobody']), $ownerOf) === []);
check('D4 the fixed sorts: name A–Z, owner, created',
    $labels(ApiDeviceListing::apply($all, $definition->read(['view' => 'all', 'sort' => 'label']), $ownerOf)) === ['iPad Büro', 'iPhone Eric', 'Pixel Anna']
    && $labels(ApiDeviceListing::apply($all, $definition->read(['view' => 'all', 'sort' => 'owner']), $ownerOf)) === ['Pixel Anna', 'iPhone Eric', 'iPad Büro']
    && $labels(ApiDeviceListing::apply($all, $definition->read(['view' => 'all', 'sort' => 'created', 'dir' => 'asc']), $ownerOf))[2] === 'iPad Büro');
$noon = mktime(12, 0, 0, 11, 12, 2026);
check('D5 «zuletzt benutzt»: heute HH:MM, gestern HH:MM, else the date; nothing → «—»',
    ApiDeviceListing::when(date(DATE_ATOM, $noon - 3600), $noon) === 'heute 11:00'
    && ApiDeviceListing::when(date(DATE_ATOM, $noon - 86400), $noon) === 'gestern 12:00'
    && ApiDeviceListing::when(date(DATE_ATOM, $noon - 3 * 86400), $noon) === '09.11.2026'
    && ApiDeviceListing::when('') === '—' && ApiDeviceListing::date('') === '—');

// ── E. ApiDevicePairing ──────────────────────────────────────────────────────
echo "E. ApiDevicePairing — URL, origin, QR\n";

$canonical = new class { public function getBaseUrl(): string { return 'https://kunde.ch/'; } public function isHttps(): bool { return false; } public function getHost(): string { return 'evil.example'; } };
$dev8077   = new class { public function getBaseUrl(): string { throw new \RuntimeException('no canonicalBaseUrl'); } public function isHttps(): bool { return false; } public function getHost(): string { return 'localhost:8077'; } };
check('E1 the app shell\'s pairing URL: <origin>/app/pair?code=…, the typed form «host/app»',
    ApiDevicePairing::appUrl('https://kunde.ch/', 'ABCDEFGHJK') === 'https://kunde.ch/app/pair?code=ABCDEFGHJK'
    && ApiDevicePairing::shortAppUrl('https://kunde.ch') === 'kunde.ch/app');
check('E2 origin: the canonical one wins over the Host header; without one, the address in use',
    ApiDevicePairing::origin($canonical) === 'https://kunde.ch' && ApiDevicePairing::origin($dev8077) === 'http://localhost:8077');
$svg = ApiDevicePairing::qrSvg('https://kunde.ch/app/pair?code=ABCDEFGHJK');
check('E3 the QR: inline SVG, no XML declaration, no script', str_starts_with($svg, '<svg') && !str_contains($svg, '<?xml') && !preg_match('/<script|\son[a-z]+\s*=/i', $svg));

// ── F. templates ─────────────────────────────────────────────────────────────
echo "F. templates — escaping, no script (Rule 7)\n";

$renderer = new TemplateRenderer('Z77\\Module\\Api');
$tpl      = 'Backend/ApiDeviceController/';
// A handler ATTRIBUTE (quoted value) — the evil label below carries «onerror=» as escaped text.
$clean    = fn(string $html): bool => !preg_match('/<script|\son[a-z]+\s*=\s*["\']|javascript:/i', $html);
$evil     = $devices->redeemPairingCode($devices->createPairingCode((int) $editor->getId(), '<img src=x onerror=alert(1)>', ['receipt.capture'], $now + 3), null, $now + 3)['device'];

$row = $renderer->partial($tpl . '_row', [
    'device' => $evil, 'ownerName' => 'Eric <b>Editor</b>', 'scopeLabels' => ['Belege erfassen'],
    'definition' => $definition, 'actionBase' => ApiDeviceLayout::BASE,
]);
check('F1 row: data-entity, the phone icon opens the window with the list region as origin, the ⋮ menu, labels of the searches',
    str_contains($row, 'data-entity="apiDevice:' . $evil->getId() . '"') && str_contains($row, '#icon-smartphone')
    && str_contains($row, 'data-window-open="/backend/system/api-device/edit?id=' . $evil->getId() . '"') && str_contains($row, 'data-origin="region:api-device-find-list"')
    && str_contains($row, 'data-menu-open="/backend/system/api-device/actions?id=' . $evil->getId() . '"')
    && str_contains($row, 'for="api-device-find-f_label"') && str_contains($row, 'for="api-device-find-f_owner"') && str_contains($row, '>aktiv<'));
check('F2 row: name and owner escaped, no script', str_contains($row, '&lt;img src=x onerror=alert(1)&gt;') && str_contains($row, 'Eric &lt;b&gt;Editor&lt;/b&gt;') && $clean($row));
$blocked = $renderer->partial($tpl . '_row', ['device' => $devices->find((int) $dev->getId()), 'ownerName' => 'Eric', 'scopeLabels' => ['Belege erfassen'], 'definition' => $definition, 'actionBase' => ApiDeviceLayout::BASE]);
check('F3 a blocked row: the lock, «gesperrt» as a pill with its date — no switch', str_contains($blocked, '#icon-lock') && str_contains($blocked, '>gesperrt<') && !str_contains($blocked, 'be-switch') && str_contains($blocked, 'be-list__row--inactive'));

$menu = $renderer->partial($tpl . 'actions', ['device' => $evil, 'canRevoke' => true, 'region' => 'api-device-find-list', 'actionBase' => ApiDeviceLayout::BASE]);
$menuNo = $renderer->partial($tpl . 'actions', ['device' => $evil, 'canRevoke' => false, 'region' => 'api-device-find-list', 'actionBase' => ApiDeviceLayout::BASE]);
check('F4 ⋮ menu: «Öffnen» and «Sperren …» as window links; without the right only «Öffnen»; no header, no «Schliessen»',
    str_contains($menu, 'role="menu"') && str_contains($menu, 'Öffnen') && str_contains($menu, 'confirm-revoke?id=' . $evil->getId()) && str_contains($menu, 'Sperren …')
    && !str_contains($menuNo, 'Sperren') && !str_contains($menu, 'Schliessen') && $clean($menu));

$pairCtx = [
    'window' => true, 'origin' => 'region:api-device-find-list', 'owners' => [(int) $admin->getId() => 'Anna Admin (adm)', (int) $editor->getId() => 'Eric Editor (edi)'],
    'ownerId' => (int) $admin->getId(), 'label' => '', 'scopes' => $scopes->all(), 'picked' => ['receipt.capture'], 'errors' => [],
    'pairing' => [
        'code' => 'ABCDE-FGHJK', 'ticket' => str_repeat('ab', 16), 'expiresAt' => $now + 600, 'now' => $now,
        'url' => 'https://kunde.ch/app/pair?code=ABCDEFGHJK', 'appUrl' => 'kunde.ch/app', 'qrSvg' => $svg,
        'statusUrl' => '/backend/system/api-device/pair-status?ticket=' . str_repeat('ab', 16), 'every' => 5,
    ],
    'csrfToken' => 'tok', 'actionBase' => ApiDeviceLayout::BASE,
];
$pair = $renderer->partial($tpl . 'pair', $pairCtx);
check('F5 pairing window: window mask, owner select (governor), name, one switch per scope (picked on), «Neuer Code» submits the form, ticket + origin carried',
    str_contains($pair, 'data-window="api-device-pair"') && str_contains($pair, 'name="owner_id"') && str_contains($pair, '<select')
    && str_contains($pair, 'name="label"') && substr_count($pair, 'name="scopes[]"') === 2 && preg_match('/value="receipt\.capture" checked/', $pair) === 1 && preg_match('/value="content\.edit" data-help-key/', $pair) === 1
    && str_contains($pair, 'form="api-device-pair-form"') && str_contains($pair, '>Neuer Code<') && str_contains($pair, 'name="ticket" value="' . str_repeat('ab', 16) . '"')
    && str_contains($pair, 'name="_origin" value="region:api-device-find-list"'));
check('F6 pairing window: the QR (real SVG), the code as text, the typed address, «Wartet auf das Handy …» in a LIVE box asking pair-status with the ticket — never with the code',
    str_contains($pair, '<svg') && str_contains($pair, 'ABCDE-FGHJK') && str_contains($pair, 'kunde.ch/app') && str_contains($pair, 'Wartet auf das Handy')
    && str_contains($pair, 'data-fetch-live="/backend/system/api-device/pair-status?ticket=' . str_repeat('ab', 16) . '"') && str_contains($pair, 'data-fetch-live-every="5"')
    && !preg_match('/pair-status\?[^"]*ABCDE/', $pair) && str_contains($pair, 'noch 10:00') && $clean(preg_replace('/<svg.*<\/svg>/s', '', $pair)));
$single = $renderer->partial($tpl . 'pair', ['owners' => [(int) $editor->getId() => 'Eric Editor (edi)'], 'ownerId' => (int) $editor->getId(), 'scopes' => []] + $pairCtx);
check('F7 an editor: the owner is himself, read-only (no select); no scope offered → the hint',
    !str_contains($single, '<select') && str_contains($single, 'name="owner_id" value="' . $editor->getId() . '"') && str_contains($single, 'Kein Modul bietet Geräten etwas an'));
$expiredBox = $renderer->partial($tpl . '_pairStatus', ['ticket' => str_repeat('cd', 16), 'waiting' => false, 'expiresAt' => $now, 'now' => $now, 'statusUrl' => '/x', 'every' => 5]);
check('F8 expired: the box without the live attribute (the listening ends), «Neuer Code» named',
    !str_contains($expiredBox, 'data-fetch-live') && str_contains($expiredBox, 'data-api-device-pair="' . str_repeat('cd', 16) . '"') && str_contains($expiredBox, 'Neuer Code'));

$editCtx = ['window' => true, 'origin' => 'region:api-device-find-list', 'device' => $evil, 'ownerName' => 'Eric Editor (edi)', 'canEdit' => true,
    'scopes' => $scopes->choices($evil->getScopes()), 'entityCsrf' => 'ent', 'csrfToken' => 'tok', 'actionBase' => ApiDeviceLayout::BASE];
$edit = $renderer->partial($tpl . 'edit', $editCtx);
check('F9 device window: mask + entity, «Speichern», «Sperren …» loads the question INTO the window, name and switches, entity token, no «aktiv» switch',
    str_contains($edit, 'data-window="api-device"') && str_contains($edit, 'data-window-entity="apiDevice:' . $evil->getId() . '"')
    && str_contains($edit, '>Speichern<') && preg_match('/class="be-btn be-btn--danger" href="[^"]*confirm-revoke\?id=' . $evil->getId() . '" data-window-link>Sperren …</', $edit) === 1
    && str_contains($edit, 'name="entity_csrf" value="ent"') && substr_count($edit, 'name="scopes[]"') === 2 && !preg_match('/name="active"/', $edit) && $clean($edit));
$editBlocked = $renderer->partial($tpl . 'edit', ['device' => $devices->find((int) $dev->getId()), 'canEdit' => false, 'entityCsrf' => ''] + $editCtx);
check('F10 a blocked device: read-only — no save, no «Sperren …», switches disabled, «gesperrt», «neu koppeln» explained',
    !str_contains($editBlocked, '>Speichern<') && !str_contains($editBlocked, 'Sperren …') && str_contains($editBlocked, ' disabled') && str_contains($editBlocked, '>gesperrt<') && str_contains($editBlocked, 'neu koppeln'));

$confirm = $renderer->partial($tpl . 'confirmRevoke', ['window' => true, 'origin' => 'page', 'device' => $evil, 'ownerName' => 'Eric', 'blockReason' => null, 'entityCsrf' => 'rev', 'csrfToken' => 'tok', 'actionBase' => ApiDeviceLayout::BASE]);
check('F11 confirm: a question without input, the answers at the bottom (`end`), «Sperren» danger, «Abbrechen» back to the device, token',
    str_contains($confirm, 'z77-form-actions--end') && str_contains($confirm, 'be-btn--danger') && str_contains($confirm, '>Sperren<')
    && str_contains($confirm, 'href="/backend/system/api-device/edit?id=' . $evil->getId() . '" data-window-link>Abbrechen<')
    && str_contains($confirm, 'action="/backend/system/api-device/revoke"') && str_contains($confirm, 'name="entity_csrf" value="rev"')
    && !preg_match('/<input(?![^>]*type="hidden")/', $confirm) && $clean($confirm));
$refused = $renderer->partial($tpl . 'confirmRevoke', ['window' => true, 'origin' => 'page', 'device' => $evil, 'ownerName' => 'Eric', 'blockReason' => 'Das Gerät ist bereits gesperrt.', 'entityCsrf' => '', 'csrfToken' => 'tok', 'actionBase' => ApiDeviceLayout::BASE]);
check('F12 refused: the reason, no form', str_contains($refused, 'bereits gesperrt') && !str_contains($refused, '<form'));

$act     = $renderer->partial($tpl . 'act', ['actionBase' => ApiDeviceLayout::BASE]);
$toolbar = $renderer->partial($tpl . 'toolbar', ['state' => $definition->read(['view' => 'all']), 'actionBase' => ApiDeviceLayout::BASE]);
check('F13 action cell «+ Gerät koppeln» opens the pairing window; toolbar: tabs «Aktiv» · «Alle», «Alle» current',
    str_contains($act, 'be-btn--primary') && str_contains($act, 'data-window-open="/backend/system/api-device/pair"') && str_contains($act, 'Gerät koppeln') && substr_count($act, '<a ') === 1
    && str_contains($toolbar, 'href="/backend/system/api-device/list">Aktiv<') && preg_match('/is-active" href="[^"]*view=all" aria-current="page">Alle</', $toolbar) === 1);

$state = $definition->read(['view' => 'all']);
$list  = $renderer->partial($tpl . 'listAction', [
    'rows' => [['device' => $adminDev, 'ownerName' => 'Anna Admin (adm)', 'scopeLabels' => ['Belege erfassen']]],
    'definition' => $definition, 'state' => $state, 'paging' => $state->paging(1), 'governs' => true, 'actionBase' => ApiDeviceLayout::BASE,
]);
check('F14 the list: a fetch region, the kernel\'s head and find form, the row, no script',
    str_contains($list, 'data-fetch-region="api-device-find-list"') && str_contains($list, '<form id="api-device-find"') && str_contains($list, 'Pixel Anna') && $clean($list));
$helps = '';
foreach (['list.help', 'pair.help', 'edit.help'] as $h) {
    $helps .= $renderer->partial($tpl . $h, []);
}
check('F15 help: a section per field (owner_id, label, scopes, code)',
    str_contains($helps, 'data-help-field="owner_id"') && str_contains($helps, 'data-help-field="label"') && str_contains($helps, 'data-help-field="scopes"') && str_contains($helps, 'data-help-field="code"') && $clean($helps));

// ── G. wiring ────────────────────────────────────────────────────────────────
echo "G. wiring — navigation, mount, role, layout, icon\n";

$seed = json_decode((string) file_get_contents($root . '/packages/module-api/data/framework/routing/navigation.d/module-api.json'), true);
check('G1 navigation seed: «Geräte» under Stammdaten › System, next to «Benutzer», backend/system/api-device/list',
    is_array($seed) && count($seed) === 1 && $seed[0]['name'] === 'Geräte' && $seed[0]['parent_key'] === 'stammdaten-system'
    && [$seed[0]['module'], $seed[0]['group'], $seed[0]['controller'], $seed[0]['action']] === ['backend', 'system', 'api-device', 'list'] && $seed[0]['sort_key'] === 1);
$backendConfig = require $root . '/packages/module-backend/src/App/Config/backendConfig.inc.php';
check('G2 role: the controller is EDITOR (every backend user his own devices)', ($backendConfig['controllers']['system']['ApiDeviceController']['controllerRole'] ?? null) === AuthRole::EDITOR);
$host = (string) file_get_contents($root . '/packages/module-backend/src/Ui/Controllers/System/ApiDeviceController.php');
$pin  = require $root . '/packages/module-backend/src/Ui/Config/System/apiDeviceControllerConfig.inc.php';
check('G3 the host mounts the trait; the layout pins the list template of module-api',
    str_contains($host, 'use ApiDeviceControllerTrait;') && str_contains($host, 'extends BackendAbstractController')
    && ($pin['levelElements']['body']['main'][0] ?? null) === ['nameSpace' => 'Z77\Module\Api', 'path' => 'Backend/ApiDeviceController', 'name' => 'listAction']);
check('G4 the sprite has the phone icon', str_contains((string) file_get_contents($root . '/packages/module-backend/res/view/templates/partials/icon-sprite.tpl.php'), 'id="icon-smartphone"'));
$trait = (string) file_get_contents($root . '/packages/module-api/src/Ui/ApiDeviceControllerTrait.php');
check('G5 the trait writes through ApiDevices only, reads no superglobal, has no script of its own',
    !preg_match('/\$_(GET|POST|SERVER|COOKIE|SESSION)\b/', $trait) && !str_contains($trait, 'devices.json') && !str_contains($trait, 'load-script'));

// ── result ───────────────────────────────────────────────────────────────────

if ($fail === 0) {
    rrmdir($base);
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
