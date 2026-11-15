<?php

/**
 * module-creditor harness (CLI) — stage 1a of the creditor plan (`docs/03-development/
 * creditor-module-bauplan.md`, owner answers §11 of 2026-11-15): the package wiring, the payment
 * helpers moved to the kernel's shared layer, the creditor profile on a contact with several IBANs
 * (shared IBAN allowed), its defaults, the role «Kreditor» and the master-data screen «Kreditoren».
 *
 * What is load-bearing here:
 *
 *   - (O, offline) the package wiring (composer, root require, autoload, config); module direction
 *     (contact, financial, receipt, debtor never name the creditor module); no superglobal; the
 *     shared `Iban` / `QrReference` / `ScorReference` / `Mod97` and the debtor's switch to them
 *     (no copy left behind, debtor's references unchanged); the entity normalisation; the
 *     format-only validator (UID, IBAN rows, tax code vs. «ohne MWST»); the IBAN rows a form hands
 *     over;
 *   - (S, offline) the navigation seed, the thin host and its layout pin, the fragment's actions and
 *     their attributes, typed responses only through the helpers, the helps (field sections, the
 *     first paragraph ≤ 240 characters, Swiss spelling), the module role on the route;
 *   - (M, database) migrate + `diff` clean + down / up; the role «Kreditor» through module-contact's
 *     registry (on, refused twice, inactive contact, off deletes); `update()` with the version, the
 *     defaults stamp, several IBANs (QR flag, foreign), the refusals per field with nothing written,
 *     a stale version, the shared IBAN (0 / 1 / n, the hint, active only), the UID unique, the IBAN
 *     sync (kept / changed / added / removed), the reference rule for a deactivated payment method,
 *     a mandator not liable to VAT, the list search;
 *   - (SM, database) the screen through a host double — list (help in the toolbar, rows, search),
 *     window (GET, POST saved / refused / invalid / stale) and the «Aktiv» switch in place.
 *
 * Run: php tests/module-creditor.php
 * O and S need the monorepo's vendor/ — `composer install` in the root. M needs a reachable MariaDB
 * and the `z77test` credentials in `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*; without them M
 * and SM are reported SKIPPED and the exit code reflects the offline sections only.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "module-creditor: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(1);
}
require $autoload;

use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Roles\ContactRoleRefusedException;
use Z77\Module\Contact\Roles\ContactRoles;
use Z77\Module\Contact\Services\ContactService;
use Z77\Module\Creditor\Entities\CreditorIban;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Module\Creditor\Repositories\CreditorSearch;
use Z77\Module\Creditor\Roles\CreditorContactRole;
use Z77\Module\Creditor\Services\CreditorProfileService;
use Z77\Module\Creditor\Services\CreditorRefusedException;
use Z77\Module\Creditor\Services\InvalidCreditorProfileException;
use Z77\Module\Creditor\Ui\CreditorLayout;
use Z77\Module\Creditor\Ui\CreditorListing;
use Z77\Module\Creditor\Ui\CreditorProfileControllerTrait;
use Z77\Module\Creditor\Validators\CreditorProfileValidator;
use Z77\Module\Debtor\Invoicing\QrReferenceLayout;
use Z77\Module\Receipt\Entities\PaymentMethod;
use Z77\Module\Receipt\Services\PaymentMethodService;
use Z77\Shared\Payment\Iban;
use Z77\Shared\Payment\Mod97;
use Z77\Shared\Payment\QrReference;
use Z77\Shared\Payment\ScorReference;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

function caught(callable $fn, string $class): ?\Throwable
{
    try { $fn(); } catch (\Throwable $e) { return $e instanceof $class ? $e : null; }
    return null;
}

/** A valid UID (eCH-0097 modulo 11) from eight digits — null when that stem has no valid check digit. */
function uid(string $eight): ?string
{
    $sum = 0;
    foreach ([5, 4, 3, 2, 7, 6, 5, 4] as $i => $w) { $sum += (int) $eight[$i] * $w; }
    $check = 11 - ($sum % 11);
    if ($check === 10) { return null; }
    $digits = $eight . ($check === 11 ? 0 : $check);
    return 'CHE-' . substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3);
}

/** A valid CH IBAN with the given 5-digit IID and 12-character account — the check digits computed. */
function chIban(string $iid, string $account): string
{
    for ($cd = 2; $cd <= 98; $cd++) {
        $iban = 'CH' . str_pad((string) $cd, 2, '0', STR_PAD_LEFT) . $iid . $account;
        if (Mod97::holds($iban)) { return $iban; }
    }
    throw new \RuntimeException('no check digits');
}

$sourceOf = static function (string $dir): string {
    $out = '';
    if (!is_dir($dir)) { return $out; }
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $out .= file_get_contents($file->getPathname()) . "\n";
        }
    }
    return $out;
};

$pkg = $root . '/packages/module-creditor';

// ── O. offline ───────────────────────────────────────────────────────────

echo "O. Package wiring, module direction, shared payment helpers, entity and validator (offline)\n";

$composer     = json_decode((string) file_get_contents($pkg . '/composer.json'), true);
$rootComposer = json_decode((string) file_get_contents($root . '/composer.json'), true);
check('O1 the package: z77/module-creditor, PSR-4 Z77\\Module\\Creditor\\ → src/, requires kernel, persistence-doctrine, contact, vat, mandator, financial, receipt (owner answer 1), dms; the root requires it; the autoloader finds its classes',
    ($composer['name'] ?? '') === 'z77/module-creditor' && ($composer['autoload']['psr-4']['Z77\\Module\\Creditor\\'] ?? '') === 'src/'
    && array_diff(['z77/kernel', 'z77/persistence-doctrine', 'z77/module-contact', 'z77/module-vat', 'z77/module-mandator', 'z77/module-financial', 'z77/module-receipt', 'z77/module-dms'], array_keys($composer['require'] ?? [])) === []
    && isset($rootComposer['require']['z77/module-creditor']) && class_exists(CreditorProfile::class) && class_exists(CreditorContactRole::class));

$creditorSrc = $sourceOf($pkg . '/src') . $sourceOf($pkg . '/res');
$foreign     = '';
foreach (['module-contact', 'module-financial', 'module-receipt', 'module-debtor', 'module-vat', 'module-mandator', 'kernel'] as $other) {
    $foreign .= $sourceOf($root . '/packages/' . $other . '/src') . $sourceOf($root . '/packages/' . $other . '/shared/src') . $sourceOf($root . '/packages/' . $other . '/core/src');
}
check('O2 module direction: contact, financial, receipt, debtor, vat, mandator and the kernel never name the creditor module; creditor never names debtor',
    !str_contains($foreign, 'Module\\Creditor') && !str_contains($creditorSrc, 'Module\\Debtor'));
check('O3 no superglobal in the package (Rule 4)', preg_match('/\$_(SERVER|POST|GET|FILES|COOKIE|REQUEST)\b/', $creditorSrc) === 0);

$config = require $pkg . '/src/App/Config/creditorConfig.inc.php';
check('O4 the config announces CreditorProfile and CreditorIban, registers the role «creditor» under contactRoles, no view area, module role ADMIN',
    $config['doctrineEntities'] === [CreditorProfile::class, CreditorIban::class] && $config['contactRoles'] === ['creditor' => CreditorContactRole::class]
    && $config['viewArea'] === false && $config['moduleRole'] === \Z77\Core\Config\AuthRole::ADMIN);

$debtorSrc = $sourceOf($root . '/packages/module-debtor/src') . $sourceOf($root . '/packages/module-debtor/res');
check('O5 Iban and QrReference live in the kernel (Z77\\Shared\\Payment); debtor keeps no copy (no Services/Iban.php, no Invoicing/QrReference.php) and imports the shared classes; its own payload layout is QrReferenceLayout',
    class_exists(Iban::class) && class_exists(QrReference::class) && class_exists(ScorReference::class)
    && !is_file($root . '/packages/module-debtor/src/Services/Iban.php') && !is_file($root . '/packages/module-debtor/src/Invoicing/QrReference.php')
    && !str_contains($debtorSrc, 'Debtor\\Services\\Iban') && !preg_match('/Debtor\\\\Invoicing\\\\QrReference\b(?!Layout)/', $debtorSrc)
    && substr_count($debtorSrc, 'use Z77\\Shared\\Payment\\Iban;') >= 6 && str_contains($debtorSrc, 'use Z77\\Shared\\Payment\\QrReference;')
    && !str_contains($debtorSrc, 'function checkDigit(') && !str_contains($debtorSrc, 'function hasValidCheckDigits('));

$qrIban = 'CH4431999123000889012';
check('O6 Iban: normalise, MOD-97 (CH, DE, LI; a transposed pair fails), CH exactly 21, QR-IBAN by IID 30000–31999, format in fours — any country well-formed',
    Iban::normalize(' ch93 0076-2011 6238 5295 7 ') === 'CH9300762011623852957' && Iban::hasValidCheckDigits('CH9300762011623852957')
    && !Iban::hasValidCheckDigits('CH9300762011623852975') && Iban::hasValidCheckDigits('DE89370400440532013000') && Iban::hasValidCheckDigits('LI21088100002324013AA')
    && !Iban::isWellFormed('CH930076201162385295') && Iban::isWellFormed('DE89370400440532013000')
    && Iban::isQrIban($qrIban) && Iban::hasValidCheckDigits($qrIban) && !Iban::isQrIban('CH9300762011623852957') && !Iban::isQrIban('DE89370400440532013000')
    && Iban::format('CH9300762011623852957') === 'CH93 0076 2011 6238 5295 7' && Iban::MAX_LENGTH === 34);
check('O7 QrReference: the modulo-10-recursive check digit, valid / invalid, all zeros invalid, withCheckDigit() for 26 digits only, blocks of five; debtor\'s layout builds exactly what it built before',
    QrReference::checkDigit('21000000000313947143000901') === 7 && QrReference::isValid('210000000003139471430009017')
    && !QrReference::isValid('210000000003139471430009018') && !QrReference::isValid(str_repeat('0', 27))
    && QrReference::withCheckDigit('21000000000313947143000901') === '210000000003139471430009017'
    && caught(fn() => QrReference::withCheckDigit('123'), \InvalidArgumentException::class) !== null
    && QrReference::format('210000000003139471430009017') === '21 00000 00003 13947 14300 09017' && QrReference::normalize(' 21 00000 00003 ') === '210000000003'
    && QrReferenceLayout::forDocument(1001, 12) === '0000000000' . '001001' . '0000000012' . QrReference::checkDigit('00000000000010010000000012')
    && caught(fn() => QrReferenceLayout::forDocument(0, 12), \InvalidArgumentException::class) !== null
    && caught(fn() => QrReferenceLayout::forDocument(1000000, 12), \InvalidArgumentException::class) !== null);
check('O8 ScorReference (ISO 11649 «RF»): the ISO example valid in any spelling, a wrong check pair, a non-RF and an overlong one refused, formatted in fours',
    ScorReference::isValid('RF18539007547034') && ScorReference::isValid('rf18 5390 0754 7034') && !ScorReference::isValid('RF19539007547034')
    && !ScorReference::isValid('XX18539007547034') && !ScorReference::isValid('RF18' . str_repeat('1', 22)) && !ScorReference::isValid('RF18')
    && ScorReference::format('rf18539007547034') === 'RF18 5390 0754 7034' && ScorReference::normalize(' rf18 5390 ') === 'RF185390');

$p = new CreditorProfile(['uid' => 'che 109322551 MWST', 'default_account' => ' 6500 ', 'default_tax_code' => 'vm', 'default_payment_method_code' => ' Bank ']);
$empty = new CreditorProfile(['uid' => '', 'default_account' => '', 'default_tax_code' => '', 'default_payment_method_code' => '']);
$keyBefore = $p->defaultsKey();
$p->setDefaultAccount('6510');
$p->stampDefaults('Owner', new \DateTimeImmutable('2026-11-15 10:00'));
$p->bumpVersion();
check('O9 CreditorProfile: the UID normalised to CHE-109.322.551, account trimmed, tax code and payment method normalised; \'\' becomes NULL; defaultsKey() follows a change; the stamp and the version are no setters',
    $p->getUid() === 'CHE-109.322.551' && $p->getDefaultTaxCode() === 'VM' && $p->getDefaultPaymentMethodCode() === 'bank'
    && $empty->getUid() === null && $empty->getDefaultAccount() === null && $empty->getDefaultTaxCode() === null && $empty->getDefaultPaymentMethodCode() === null
    && !$empty->hasDefaultValues() && $p->hasDefaultValues() && $p->defaultsKey() !== $keyBefore && $p->getDefaultsSetBy() === 'Owner' && $p->getVersion() === 2
    && !method_exists($p, 'setVersion') && !method_exists($p, 'setDefaultsSetAt') && !method_exists($p, 'setDefaultsSetBy'));
$link = new CreditorIban($p, ' ch44 3199 9123 0008 8901 2 ', "  Inkasso\tAG  ");
check('O10 CreditorIban: normalised, the QR flag derived from the IBAN, the hint trimmed with control characters replaced',
    $link->getIban() === $qrIban && $link->isQrIban() && $link->getHint() === 'Inkasso AG' && $link->isActive());

$fieldErrors = static function (CreditorProfile $profile, ?array $rows = null): array {
    $v = new CreditorProfileValidator($profile, null, null, $rows);
    $v->isValid();
    return $v->getFieldErrors();
};
$withContact = static function (array $data): CreditorProfile {
    $c = new Contact(['kind' => 'organisation', 'company' => 'Lieferant AG', 'language' => 'de']);
    (new \ReflectionProperty(Contact::class, 'id'))->setValue($c, 99);
    $profile = new CreditorProfile($data);
    $profile->setContact($c);
    return $profile;
};
$e1 = $fieldErrors($withContact(['uid' => 'CHE-109.322.552']));
$e2 = $fieldErrors($withContact(['uid' => 'CHE 12']));
$e3 = $fieldErrors($withContact(['default_tax_code' => 'VM', 'default_without_vat' => true]));
$e4 = $fieldErrors($withContact([]), CreditorProfileService::ibanRows([
    ['iban' => 'CH9300762011623852957', 'hint' => ''],
    ['iban' => 'ch93 0076 2011 6238 5295 7', 'hint' => ''],
    ['iban' => 'CH9300762011623852975', 'hint' => ''],
    ['iban' => 'XX12', 'hint' => ''],
    ['iban' => 'DE89370400440532013000', 'hint' => str_repeat('x', 81)],
]));
$e5 = $fieldErrors($withContact(['uid' => 'CHE-109.322.551']), CreditorProfileService::ibanRows([['iban' => 'DE89370400440532013000'], ['iban' => $qrIban, 'hint' => 'Inkasso']]));
check('O11 the validator (format-only): UID check digit and shape, tax code AND «ohne MWST» together, IBAN rows — the same IBAN twice, a wrong check digit, no IBAN, an overlong hint — each under its own field; a foreign IBAN and a QR-IBAN pass',
    str_contains($e1['uid'] ?? '', 'Prüfziffer') && str_contains($e2['uid'] ?? '', 'CHE-123.456.789') && str_contains($e3['default_tax'] ?? '', 'nicht beides')
    && !isset($e4['iban_0']) && str_contains($e4['iban_1'] ?? '', 'Zeile 1') && str_contains($e4['iban_2'] ?? '', 'Prüfziffer') && str_contains($e4['iban_3'] ?? '', 'Keine IBAN')
    && str_contains($e4['hint_4'] ?? '', '80') && !isset($e4['iban_4']) && $e5 === [],
    json_encode([$e1, $e2, $e3, $e4, $e5], JSON_UNESCAPED_UNICODE));
$rows = CreditorProfileService::ibanRows([
    ['iban' => ' ch93 0076 2011 6238 5295 7 ', 'hint' => " a\nb ", 'active' => '1'],
    ['iban' => '', 'hint' => 'dropped'],
    ['iban' => 'DE89370400440532013000', 'active' => '0'],
    ['iban' => 'LI21088100002324013AA'],
    'not a row',
]);
check('O12 ibanRows(): a row without IBAN is dropped (the empty «Neue IBAN» row, an emptied one), the IBAN normalised, the hint cleaned, active from the form (hidden 0 + checkbox 1; missing = active)',
    $rows === [
        ['iban' => 'CH9300762011623852957', 'hint' => 'a b', 'active' => true],
        ['iban' => 'DE89370400440532013000', 'hint' => '', 'active' => false],
        ['iban' => 'LI21088100002324013AA', 'hint' => '', 'active' => true],
    ], json_encode($rows));

// ── S. the backend screen, offline ───────────────────────────────────────

echo "S. Backend screen — navigation, mount, fragment, templates, help (offline)\n";

$seed = json_decode((string) file_get_contents($pkg . '/data/framework/routing/navigation.d/module-creditor.json'), true);
check('S1 the seed ships ONE leaf «Kreditoren» under Stammdaten › Finanzen → backend/finance/creditor-profile/list (the Kreditorenjournal arrives with 1b)',
    count($seed) === 1 && $seed[0]['key'] === 'kreditoren' && $seed[0]['name'] === 'Kreditoren' && $seed[0]['parent_key'] === 'stammdaten-finanzen'
    && $seed[0]['module'] === 'backend' && $seed[0]['group'] === 'finance' && $seed[0]['controller'] === 'creditor-profile' && $seed[0]['action'] === 'list');
$navRoots  = array_map(static fn(string $p) => $root . '/packages/' . $p . '/data', ['kernel/core', 'module-financial', 'module-vat', 'module-mandator', 'module-receipt', 'module-creditor']);
$navMerged = \Z77\Shared\Import\NavigationSeeds::merge(\Z77\Shared\Import\NavigationSeeds::read(\Z77\Shared\Import\NavigationSeeds::discover($navRoots)), [])['records'];
$navByKey  = array_column(array_filter($navMerged, static fn($r) => $r['key'] !== null), null, 'key');
$navKids   = array_values(array_filter($navMerged, static fn($r) => $r['parent_id'] === ($navByKey['stammdaten-finanzen']['id'] ?? -1)));
usort($navKids, static fn($a, $b) => $a['sort_key'] <=> $b['sort_key']);
check('S2 fresh install: Stammdaten › Finanzen ends with Belegvorlagen, Zahlungsarten, Kreditoren (plan §8: after «Zahlungsarten»)',
    array_slice(array_column($navKids, 'name'), -3) === ['Belegvorlagen', 'Zahlungsarten', 'Kreditoren'], json_encode(array_column($navKids, 'name'), JSON_UNESCAPED_UNICODE));

$host   = (string) @file_get_contents($root . '/packages/module-backend/src/Ui/Controllers/Finance/CreditorProfileController.php');
$hostCf = (string) @file_get_contents($root . '/packages/module-backend/src/Ui/Config/Finance/creditorProfileControllerConfig.inc.php');
check('S3 a thin host in module-backend (ADR-018): Finance/CreditorProfileController uses the trait; its layout config pins the fragment\'s listAction in module-creditor; the URL root lives once, in CreditorLayout',
    str_contains($host, 'use CreditorProfileControllerTrait;') && str_contains($host, 'extends BackendAbstractController') && str_contains($hostCf, 'CreditorLayout::profiles()')
    && (CreditorLayout::profiles()['levelElements']['body']['main'][0] ?? []) === ['nameSpace' => 'Z77\Module\Creditor', 'path' => 'Backend/CreditorProfileController', 'name' => 'listAction']
    && CreditorLayout::BASE === '/backend/finance/creditor-profile' && substr_count($sourceOf($pkg . '/src'), "'/backend/finance/") === 1);

$actions = array_values(array_filter(array_map(static fn(\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass(CreditorProfileControllerTrait::class))->getMethods()), static fn($m) => str_ends_with($m, 'Action')));
$attrs   = static fn(string $method, string $attr): bool => (new \ReflectionMethod(CreditorProfileControllerTrait::class, $method))->getAttributes($attr) !== [];
$trait   = (string) file_get_contents($pkg . '/src/Ui/CreditorProfileControllerTrait.php');
check('S4 the fragment: list, edit, toggle-active — no add (the action cell is empty, a creditor is made by the role) and no delete; edit #[Csrf], the switch #[Fetch] POST; every answer through a helper (html / fetch / fetchError / redirect), never a response instantiated',
    $actions === ['listAction', 'editAction', 'toggleActiveAction'] && $attrs('editAction', \Z77\Shared\Attributes\Csrf::class)
    && $attrs('toggleActiveAction', \Z77\Shared\Attributes\Fetch::class) && $attrs('toggleActiveAction', \Z77\Shared\Attributes\HttpMethod::class)
    && preg_match('/new\s+\\\\?[A-Za-z\\\\]*Response\s*\(/', $creditorSrc) === 0 && !str_contains($trait, "'hc1'"), json_encode($actions));

$tplDir = $pkg . '/res/view/templates/Backend/CreditorProfileController';
$listHelp = (string) @file_get_contents($tplDir . '/list.help.tpl.php');
$editHelp = (string) @file_get_contents($tplDir . '/edit.help.tpl.php');
$firstP   = static fn(string $html): int => preg_match('~<p>(.*?)</p>~s', $html, $m) ? mb_strlen(html_entity_decode(strip_tags($m[1]))) : 999;
$sections = preg_match_all('/data-help-field="([a-z_]+)"/', $editHelp, $m) ? $m[1] : [];
check('S5 the helps: list.help (attached with toolbar: true) and edit.help with a section per field (uid, default_account, default_tax, default_payment_method_code, iban, hint, iban_active); each opens with an <h3> and a first <p> of ≤ 240 characters; Swiss spelling (no «ß»), «du»',
    str_contains($trait, "'Backend/CreditorProfileController/list.help'") && str_contains($trait, 'toolbar: true') && str_contains($trait, "'Backend/CreditorProfileController/edit.help'")
    && $sections === ['uid', 'default_account', 'default_tax', 'default_payment_method_code', 'iban', 'hint', 'iban_active']
    && str_contains($listHelp, '<h3>Kreditoren</h3>') && str_contains($editHelp, '<h3>Kreditor bearbeiten</h3>')
    && $firstP($listHelp) <= 240 && $firstP($editHelp) <= 240 && !str_contains($listHelp . $editHelp, 'ß')
    && preg_match('/\bdu\b|\bdeine?\b/u', $listHelp) === 1 && preg_match('/\bdu\b/u', $editHelp) === 1,
    json_encode([$sections, $firstP($listHelp), $firstP($editHelp)]));
$editTpl = (string) @file_get_contents($tplDir . '/edit.tpl.php');
check('S6 the window names its fields as the help does (name / data-help-key), posts the version and the entity token, and carries no script of its own (Rule 7)',
    str_contains($editTpl, 'name="uid"') && str_contains($editTpl, 'name="default_account"') && str_contains($editTpl, 'name="default_tax"') && str_contains($editTpl, 'name="default_payment_method_code"')
    && str_contains($editTpl, 'data-help-key="iban"') && str_contains($editTpl, 'data-help-key="hint"') && str_contains($editTpl, 'data-help-key="iban_active"')
    && str_contains($editTpl, 'name="version"') && str_contains($editTpl, 'name="entity_csrf"') && !str_contains($editTpl, '<script') && !str_contains($editTpl, 'onclick'));

$backendConfig = require $root . '/packages/module-backend/src/App/Config/backendConfig.inc.php';
$roleOf = static fn(string $action): string => \Z77\Shared\Services\AuthService::requiredRole(
    $backendConfig['moduleRole'], $backendConfig['controllers'], 'finance', \Z77\Shared\Libraries\Convention\Naming::toCamelCase('creditor-profile') . 'Controller', \Z77\Shared\Libraries\Convention\Naming::toActionMethod($action));
check('S7 the route needs the module role ADMIN for list, edit and the switch (no own rule)',
    $roleOf('list') === \Z77\Core\Config\AuthRole::ADMIN && $roleOf('edit') === \Z77\Core\Config\AuthRole::ADMIN && $roleOf('toggle-active') === \Z77\Core\Config\AuthRole::ADMIN);

// ── M. database ──────────────────────────────────────────────────────────

$credentials = [
    'host'     => getenv('Z77_TEST_DB_HOST') ?: 'localhost',
    'user'     => getenv('Z77_TEST_DB_USER') ?: 'z77test',
    'password' => getenv('Z77_TEST_DB_PASSWORD') ?: '',
];
if ($credentials['password'] === '') {
    $file = rtrim((string) (getenv('USERPROFILE') ?: getenv('HOME')), '/\\') . '/.z77/mariadb.txt';
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $credLine) {
        if (str_starts_with($credLine, $credentials['user'] . '=')) {
            $credentials['password'] = substr($credLine, strlen($credentials['user']) + 1);
        }
    }
}
if ($credentials['password'] === '' || !class_exists(\Doctrine\DBAL\DriverManager::class)) {
    echo "M. Database — SKIPPED (no z77test password: %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD)\n";
    echo "\n{$pass} passed, {$fail} failed (M skipped)\n";
    exit($fail === 0 ? 0 : 1);
}

echo "M. Database (throwaway schema)\n";

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-module-creditor-' . getmypid());
define('DEBUG', false);
$base = ABS_BASE_PATH;

$write = function (string $rel, string $content) use ($base): void {
    $path = $base . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
};
$rm = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (basename($f) === '.' || basename($f) === '..') { continue; }
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($dir);
};
register_shutdown_function(static function () use ($admin, $dbName, $rm, $base): void {
    try { $admin->executeStatement("DROP DATABASE IF EXISTS `{$dbName}`"); } catch (\Throwable) {}
    $rm($base);
});

// The REAL packages: their configs announce the entities, their res/migrations hold the migrations.
$packages = [
    'Vat'       => $root . '/packages/module-vat',
    'Contact'   => $root . '/packages/module-contact',
    'Mandator'  => $root . '/packages/module-mandator',
    'Financial' => $root . '/packages/module-financial',
    'Dms'       => $root . '/packages/module-dms',
    'Receipt'   => $root . '/packages/module-receipt',
    'Creditor'  => $pkg,
];
$namespaces = "'Z77\\\\Shared\\\\' => ['sourcePaths' => ['{$root}/packages/kernel/shared']],\n";
foreach ($packages as $name => $path) {
    $namespaces .= "'Z77\\\\Module\\\\{$name}\\\\' => ['sourcePaths' => ['{$path}']],\n";
}
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'creditor', 'modules' => ['vat' => [], 'contact' => [], 'mandator' => [], 'financial' => [], 'dms' => [], 'receipt' => [], 'creditor' => []]];");
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
@mkdir($base . '/data/framework/vat', 0777, true);
foreach (['tax_codes', 'tax_rates'] as $seedName) {
    copy($packages['Vat'] . '/data/framework/vat/' . $seedName . '.default.json', $base . '/data/framework/vat/' . $seedName . '.json');
}

/** A fresh request: new DI, new UnifiedEntityManager, empty identity map. */
$wire = static function () use ($base): \Z77\Persistence\Resolver\UnifiedEntityManager {
    gc_collect_cycles();
    static $previous = null;
    if ($previous !== null) {
        foreach ((new \ReflectionProperty($previous, 'managerCache'))->getValue($previous) as $manager) {
            if ($manager instanceof \Z77\Persistence\Doctrine\DoctrineEntityManager) {
                (new \ReflectionProperty($manager, 'holder'))->getValue($manager)->current()->getConnection()->close();
            }
        }
    }
    \Z77\Core\DI::getInstance(true)
        ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
        ->set('FileFinder', fn($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn($c) => new \Z77\Core\Services\ModuleManager($c->get('ConfigManager')), true)
        ->set('I18n', fn($c) => new \Z77\Core\Services\I18n($c->get('ConfigManager')), true)
        ->set('DataSourceResolver', fn() => new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new \Z77\Persistence\Resolver\UnifiedEntityManager($c->get('DataSourceResolver')), true);
    \Z77\Core\DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return $previous = \Z77\Core\DI::getUnifiedEntityManager();
};
$em = $wire();

$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$count  = fn(string $table) => (int) $db->fetchOne("SELECT COUNT(*) FROM {$table}");
$tables = fn() => array_map('strval', $db->fetchFirstColumn("SHOW TABLES LIKE 'creditor%'"));

$run = static function (array $input): array {
    $app = \Z77\Persistence\Doctrine\Console\MigrationsApplication::create(
        \Z77\Persistence\Doctrine\Bootstrap::buildEntityManager(),
        \Z77\Persistence\Doctrine\Console\MigrationDirectories::collect(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder()),
        \Z77\Core\DI::getCacheManager()->generatedPhp(),
        \Z77\Persistence\Doctrine\Console\MigrationDirectories::missing(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder())
    );
    $app->setAutoExit(false);
    $out  = new \Symfony\Component\Console\Output\BufferedOutput();
    $code = $app->run(new \Symfony\Component\Console\Input\ArrayInput($input + ['--no-interaction' => true]), $out);

    return [$code, $out->fetch()];
};

$migration = 'Z77\\Module\\Creditor\\Migrations\\Version20261115100000';
[$code, $out] = $run(['command' => 'migrate']);
check('M1 migrate exits 0 (vat, contact, mandator, financial, receipt, creditor in timestamp order); the module\'s namespace is found through its doctrineEntities', $code === 0 && $tables() === ['creditor_iban', 'creditor_profile']
    && isset(\Z77\Persistence\Doctrine\Console\MigrationDirectories::collect(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder())['Z77\\Module\\Creditor\\Migrations']), trim($out));
$migrations = glob($pkg . '/res/migrations/Version*.php');
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Creditor\\Migrations']);
check('M2 diff after migrate reports NO change — mapping and migration agree', $code !== 0 && str_contains($out, 'No changes detected') && glob($pkg . '/res/migrations/Version*.php') === $migrations, trim($out));
foreach (array_diff(glob($pkg . '/res/migrations/Version*.php'), $migrations) as $stray) {
    @unlink($stray);   // a failed M2 must not leave a generated file in the package
}
// The creditor migration is the newest of the set — `migrate prev` runs exactly its down().
[$codeDown, $outDown] = $run(['command' => 'migrate', 'version' => 'prev']);
$afterDown = $tables();
$current   = (string) $db->fetchOne("SELECT COUNT(*) FROM " . \Z77\Persistence\Doctrine\Console\MigrationsApplication::STORAGE_TABLE . " WHERE version LIKE '%Creditor%'");
[$codeUp, $outUp] = $run(['command' => 'migrate']);
check('M3 down() drops both tables (and only them), up() creates them again', $codeDown === 0 && $afterDown === [] && $current === '0'
    && $codeUp === 0 && $tables() === ['creditor_iban', 'creditor_profile'] && str_contains($outUp, $migration), trim($outDown . $outUp));

// The books: the KMU chart, the mandator (liable to VAT), two payment methods.
$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->adoptKmuChart();
$mandator = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$mandator->mapFromArray(['name' => 'Harness AG', 'country' => 'CH', 'zip' => '8000', 'city' => 'Zürich', 'liable_to_vat' => true]);
(new \Z77\Module\Mandator\Services\MandatorService($em))->save($mandator);
$pms = new PaymentMethodService($em);
$pms->save(new PaymentMethod(['code' => 'bank', 'label' => 'Bank', 'credit_account' => '1020', 'position' => 10]));
$pms->save(new PaymentMethod(['code' => 'kasse', 'label' => 'Kasse', 'credit_account' => '1000', 'position' => 20]));
$contacts = new ContactService($em);
$newContact = static function (string $company) use ($contacts): Contact {
    $c = new Contact(['kind' => 'organisation', 'company' => $company, 'language' => 'de']);
    $contacts->save($c);
    return $c;
};
$cSwiss   = $newContact('Swisscom Schweiz AG');
$cPapier  = $newContact('Papeterie Muster GmbH');
$cInkasso = $newContact('Strom Nord AG');
$cOld     = $newContact('Alt AG');
$contacts->setActive($cOld, false);
$ids = ['swiss' => (int) $cSwiss->getId(), 'papier' => (int) $cPapier->getId(), 'inkasso' => (int) $cInkasso->getId(), 'old' => (int) $cOld->getId()];

// ── the role «Kreditor» ──
$em    = $wire();
$roles = ContactRoles::fromModules(\Z77\Core\DI::getModuleManager());
$role  = $roles->role('creditor');
$cRepo = $em->getRepository(Contact::class);
$swiss = $cRepo->find($ids['swiss']);
check('M4 module-contact\'s registry finds the role «Kreditor» through creditorConfig; a contact without a profile: off, switchable, the line says what follows; «Kontakt anlegen» offers it too',
    array_keys($roles->all()) === ['creditor'] && $role instanceof CreditorContactRole && $role->label() === 'Kreditor'
    && !$role->state($swiss)->on && $role->state($swiss)->switchable && str_contains($role->state($swiss)->summary, 'IBAN')
    && $role->stateForNew()->switchable);
$role->switchOn($swiss);
$em      = $wire();
$profile = $em->getRepository(CreditorProfile::class)->findByContact($ids['swiss']);
$state   = $roles->role('creditor')->state($em->getRepository(Contact::class)->find($ids['swiss']));
check('M5 «Kreditor» on: an EMPTY profile (no UID, no defaults, no stamp, version 1); the state is on, «keine IBAN · ohne Standardkonto», links to the window and asks before off',
    $profile instanceof CreditorProfile && $profile->getUid() === null && !$profile->hasDefaultValues() && $profile->getDefaultsSetAt() === null && $profile->getVersion() === 1
    && $state->on && str_contains($state->summary, 'keine IBAN') && str_contains($state->summary, 'ohne Standardkonto')
    && $state->linkUrl === '/backend/finance/creditor-profile/edit?id=' . $profile->getId() && $state->linkLabel === 'Kreditor bearbeiten …' && str_contains($state->offConfirm, 'gelöscht'),
    json_encode($state));
$twice = caught(fn() => ContactRoles::fromModules(\Z77\Core\DI::getModuleManager())->role('creditor')->switchOn($wire()->getRepository(Contact::class)->find($ids['swiss'])), ContactRoleRefusedException::class);
$em    = $wire();
$old   = $em->getRepository(Contact::class)->find($ids['old']);
$inact = caught(fn() => (new CreditorContactRole())->switchOn($old), ContactRoleRefusedException::class);
check('M6 refused in German: a second «Kreditor» on the same contact, and an inactive contact (the state says so before the click); nothing written',
    $twice !== null && str_contains($twice->getMessage(), 'bereits Kreditor') && $inact !== null && str_contains($inact->getMessage(), 'inaktiv')
    && !(new CreditorContactRole())->state($old)->switchable && $count('creditor_profile') === 1);

// ── update: UID, defaults, several IBANs ──
$em      = $wire();
$service = new CreditorProfileService($em, 'Owner');
$profile = $em->getRepository(CreditorProfile::class)->findByContact($ids['swiss']);
$uidSwiss = 'CHE-109.322.551';
$service->update($profile, 1, ['uid' => 'che109322551', 'default_account' => '6500', 'default_tax_code' => 'VM', 'default_without_vat' => false, 'default_payment_method_code' => 'bank'],
    CreditorProfileService::ibanRows([['iban' => $qrIban, 'hint' => '', 'active' => '1'], ['iban' => 'DE89 3704 0044 0532 0130 00', 'hint' => 'Konto in Deutschland', 'active' => '1']]));
$em      = $wire();
$profile = $em->getRepository(CreditorProfile::class)->findByContact($ids['swiss']);
$ibans   = $em->getRepository(CreditorIban::class)->forProfile($profile);
check('M7 update(): UID normalised, the defaults (6500 · VM · Bank) stored and STAMPED by the actor, two IBANs (a QR-IBAN and a German one) in input order with the QR flag; version 2',
    $profile->getUid() === $uidSwiss && $profile->getDefaultAccount() === '6500' && $profile->getDefaultTaxCode() === 'VM' && $profile->getDefaultPaymentMethodCode() === 'bank'
    && $profile->getDefaultsSetBy() === 'Owner' && $profile->getDefaultsSetAt() !== null && $profile->getVersion() === 2
    && array_map(static fn(CreditorIban $i) => [$i->getIban(), $i->isQrIban(), $i->getHint()], $ibans) === [[$qrIban, true, ''], ['DE89370400440532013000', false, 'Konto in Deutschland']]);

$em      = $wire();
$service = new CreditorProfileService($em, 'Owner');
$profile = $em->getRepository(CreditorProfile::class)->findByContact($ids['swiss']);
$stale   = caught(fn() => $service->update($profile, 1, ['default_account' => '6510'], []), CreditorRefusedException::class);
check('M8 a stale version is refused (CHANGED, German), nothing written — the IBANs stay', $stale !== null && $stale->reason === CreditorRefusedException::CHANGED
    && str_contains($stale->getMessage(), 'inzwischen geändert') && $count('creditor_iban') === 2
    && (int) $db->fetchOne('SELECT version FROM creditor_profile WHERE id = ?', [$profile->getId()]) === 2);

$refusedFields = static function (callable $fn): array {
    $e = caught($fn, InvalidCreditorProfileException::class);
    return $e instanceof InvalidCreditorProfileException ? $e->validator->getFieldErrors() : ['none' => 'saved'];
};
$em      = $wire();
$service = new CreditorProfileService($em, 'Owner');
$profile = $em->getRepository(CreditorProfile::class)->findByContact($ids['swiss']);
$bad     = $refusedFields(fn() => $service->update($profile, 2, ['uid' => 'CHE-109.322.552', 'default_account' => '62', 'default_tax_code' => 'XX', 'default_payment_method_code' => 'nope'],
    CreditorProfileService::ibanRows([['iban' => 'CH9300762011623852975']])));
check('M9 refused per field, in German, nothing written: the UID check digit, a GROUP account, an unknown tax code, an unknown payment method, an IBAN with a wrong check digit',
    str_contains($bad['uid'] ?? '', 'Prüfziffer') && str_contains($bad['default_account'] ?? '', 'Gruppe') && str_contains($bad['default_tax'] ?? '', 'gibt es nicht')
    && str_contains($bad['default_payment_method_code'] ?? '', 'gibt es nicht') && str_contains($bad['iban_0'] ?? '', 'Prüfziffer')
    && (int) $db->fetchOne('SELECT version FROM creditor_profile WHERE id = ?', [$profile->getId()]) === 2 && $count('creditor_iban') === 2,
    json_encode($bad, JSON_UNESCAPED_UNICODE));

// ── shared IBAN, UID unique ──
$em = $wire();
(new CreditorProfileService($em))->open($em->getRepository(Contact::class)->find($ids['inkasso']));
$em       = $wire();
$service  = new CreditorProfileService($em, 'Owner');
$inkasso  = $em->getRepository(CreditorProfile::class)->findByContact($ids['inkasso']);
$sameUid  = $refusedFields(fn() => $service->update($inkasso, 1, ['uid' => $uidSwiss], []));
check('M10 one UID names one creditor: a second creditor with the UID of the first is refused on «uid», naming the other', str_contains($sameUid['uid'] ?? '', 'Swisscom'), json_encode($sameUid, JSON_UNESCAPED_UNICODE));
$em      = $wire();
$service = new CreditorProfileService($em, 'Owner');
$inkasso = $em->getRepository(CreditorProfile::class)->findByContact($ids['inkasso']);
$service->update($inkasso, 1, [], CreditorProfileService::ibanRows([['iban' => $qrIban, 'hint' => 'Strom Nord', 'active' => '1']]));
$em     = $wire();
$byIban = $em->getRepository(CreditorIban::class)->findProfilesByIban(Iban::format($qrIban));
check('M11 a SHARED IBAN is allowed: the QR-IBAN of the first creditor saved on a second one (with a hint); matching by IBAN yields both (n), a normal IBAN one (1), an unknown none (0)',
    array_map(static fn(CreditorProfile $p) => (string) $p->getContact()->displayName(), $byIban) === ['Swisscom Schweiz AG', 'Strom Nord AG']
    && count($em->getRepository(CreditorIban::class)->findProfilesByIban('DE89370400440532013000')) === 1
    && $em->getRepository(CreditorIban::class)->findProfilesByIban('CH9300762011623852957') === []);
$em      = $wire();
$service = new CreditorProfileService($em, 'Owner');
$inkasso = $em->getRepository(CreditorProfile::class)->findByContact($ids['inkasso']);
$service->update($inkasso, 2, [], CreditorProfileService::ibanRows([['iban' => $qrIban, 'hint' => 'Strom Nord', 'active' => '0']]));
$em = $wire();
check('M12 a deactivated link leaves matching (active only) but stays listed; the defaults stamp is untouched by an IBAN-only change, the version still counts',
    count($em->getRepository(CreditorIban::class)->findProfilesByIban($qrIban)) === 1 && count($em->getRepository(CreditorIban::class)->findProfilesByIban($qrIban, activeOnly: false)) === 2
    && $em->getRepository(CreditorProfile::class)->findByContact($ids['inkasso'])->getDefaultsSetAt() === null
    && $em->getRepository(CreditorProfile::class)->findByContact($ids['inkasso'])->getVersion() === 3);

// ── IBAN sync and the defaults stamp ──
$em      = $wire();
$service = new CreditorProfileService($em, 'Owner');
$profile = $em->getRepository(CreditorProfile::class)->findByContact($ids['swiss']);
$keptId  = (int) $db->fetchOne('SELECT id FROM creditor_iban WHERE creditor_profile_id = ? AND iban = ?', [$profile->getId(), 'DE89370400440532013000']);
$stampAt = $profile->getDefaultsSetAt();
$service->update($profile, 2, ['uid' => $uidSwiss, 'default_account' => '6500', 'default_tax_code' => 'VM', 'default_without_vat' => false, 'default_payment_method_code' => 'bank'],
    CreditorProfileService::ibanRows([['iban' => 'DE89370400440532013000', 'hint' => 'neu', 'active' => '1'], ['iban' => 'LI21 0881 0000 2324 013A A', 'hint' => '', 'active' => '1']]));
$em      = $wire();
$profile = $em->getRepository(CreditorProfile::class)->findByContact($ids['swiss']);
$rowsNow = $db->fetchAllAssociative('SELECT id, iban, hint FROM creditor_iban WHERE creditor_profile_id = ? ORDER BY id', [$profile->getId()]);
check('M13 the IBAN sync: a row with the same IBAN is KEPT (same id, new hint), a dropped IBAN is removed, a new one added; unchanged defaults keep their stamp',
    count($rowsNow) === 2 && (int) $rowsNow[0]['id'] === $keptId && $rowsNow[0]['hint'] === 'neu' && $rowsNow[1]['iban'] === 'LI21088100002324013AA'
    && $profile->getDefaultsSetAt() == $stampAt && $profile->getVersion() === 3, json_encode($rowsNow));
$em      = $wire();
$service = new CreditorProfileService($em, 'Owner');
$profile = $em->getRepository(CreditorProfile::class)->findByContact($ids['swiss']);
$service->update($profile, 3, ['default_account' => '', 'default_tax_code' => '', 'default_without_vat' => false, 'default_payment_method_code' => ''], CreditorProfileService::ibanRows([['iban' => 'DE89370400440532013000']]));
$em      = $wire();
$cleared = $em->getRepository(CreditorProfile::class)->findByContact($ids['swiss']);
check('M14 emptying every default CLEARS the stamp (the next complete invoice decides them again, plan §5)', !$cleared->hasDefaultValues() && $cleared->getDefaultsSetAt() === null && $cleared->getDefaultsSetBy() === null);

// ── the reference rule: a payment method deactivated since ──
$em      = $wire();
$service = new CreditorProfileService($em, 'Owner');
$service->update($em->getRepository(CreditorProfile::class)->findByContact($ids['swiss']), 4, ['default_account' => '6500', 'default_without_vat' => true, 'default_payment_method_code' => 'kasse'], CreditorProfileService::ibanRows([['iban' => 'DE89370400440532013000']]));
$em = $wire();
(new PaymentMethodService($em))->setActive((new PaymentMethodService($em))->find('kasse'), false);
$em      = $wire();
$service = new CreditorProfileService($em, 'Owner');
$keep    = $refusedFields(fn() => $service->update($em->getRepository(CreditorProfile::class)->findByContact($ids['swiss']), 5, ['default_account' => '6510', 'default_without_vat' => true, 'default_payment_method_code' => 'kasse'], CreditorProfileService::ibanRows([['iban' => 'DE89370400440532013000']])));
$em      = $wire();
$service = new CreditorProfileService($em, 'Owner');
$newRef  = $refusedFields(fn() => $service->update($em->getRepository(CreditorProfile::class)->findByContact($ids['inkasso']), 3, ['default_payment_method_code' => 'kasse'], CreditorProfileService::ibanRows([['iban' => $qrIban, 'hint' => 'Strom Nord', 'active' => '0']])));
$em      = $wire();
check('M15 ADR-043/19: an UNCHANGED payment method deactivated since is kept (the other change saves, «ohne MWST» counts as a VAT decision); a NEW reference to it is refused',
    $keep === ['none' => 'saved'] && $em->getRepository(CreditorProfile::class)->findByContact($ids['swiss'])->getDefaultAccount() === '6510'
    && $em->getRepository(CreditorProfile::class)->findByContact($ids['swiss'])->isDefaultWithoutVat()
    && str_contains($newRef['default_payment_method_code'] ?? '', 'inaktiv'), json_encode([$keep, $newRef], JSON_UNESCAPED_UNICODE));

// ── a mandator not liable to VAT (owner answer 5) ──
$em = $wire();
(new \Z77\Module\Mandator\Services\MandatorService($em))->update((new \Z77\Module\Mandator\Services\CurrentMandator($em))->find(), ['liable_to_vat' => false]);
$em      = $wire();
$service = new CreditorProfileService($em, 'Owner');
(new CreditorProfileService($em))->open($em->getRepository(Contact::class)->find($ids['papier']));
$em      = $wire();
$service = new CreditorProfileService($em, 'Owner');
$papier  = $em->getRepository(CreditorProfile::class)->findByContact($ids['papier']);
$noVat   = $refusedFields(fn() => $service->update($papier, 1, ['default_account' => '6500', 'default_tax_code' => 'VM'], []));
$em      = $wire();
$service = new CreditorProfileService($em, 'Owner');
$papier  = $em->getRepository(CreditorProfile::class)->findByContact($ids['papier']);
$service->update($papier, 1, ['default_account' => '6500', 'default_without_vat' => true], []);
$em = $wire();
check('M16 the mandator NOT liable to VAT: a tax code as default is refused («ohne MWST», owner answer 5), «ohne MWST» saves',
    str_contains($noVat['default_tax'] ?? '', 'nicht MWST-pflichtig') && $em->getRepository(CreditorProfile::class)->findByContact($ids['papier'])->isDefaultWithoutVat()
    && !(new CreditorProfileService($em))->liableToVat(), json_encode($noVat, JSON_UNESCAPED_UNICODE));
(new \Z77\Module\Mandator\Services\MandatorService($em))->update((new \Z77\Module\Mandator\Services\CurrentMandator($em))->find(), ['liable_to_vat' => true]);

// ── the list search, the switch ──
$em     = $wire();
$repo   = $em->getRepository(CreditorProfile::class);
$names  = static fn(array $profiles): array => array_map(static fn(CreditorProfile $p) => (string) $p->getContact()->displayName(), $profiles);
check('M17 the list search in SQL: all by name, a name part, a UID part, an IBAN part typed with spaces, descending; counts agree',
    $names($repo->search(new CreditorSearch(), 0, 50)) === ['Papeterie Muster GmbH', 'Strom Nord AG', 'Swisscom Schweiz AG'] && $repo->countSearch(new CreditorSearch()) === 3
    && $names($repo->search(new CreditorSearch(name: 'swiss'), 0, 50)) === ['Swisscom Schweiz AG']
    && $names($repo->search(new CreditorSearch(uid: '322.551'), 0, 50)) === ['Swisscom Schweiz AG']
    && $names($repo->search(new CreditorSearch(iban: 'de89 3704'), 0, 50)) === ['Swisscom Schweiz AG']
    && $names($repo->search(new CreditorSearch(iban: '3199 9123'), 0, 50)) === ['Strom Nord AG']
    && $names($repo->search(new CreditorSearch(descending: true), 0, 2)) === ['Swisscom Schweiz AG', 'Strom Nord AG'] && $repo->countSearch(new CreditorSearch(name: 'zz_none')) === 0);
$em = $wire();
(new CreditorProfileService($em))->setActive($em->getRepository(CreditorProfile::class)->findByContact($ids['papier']), false);
$em = $wire();
check('M18 setActive(): the switch writes and bumps the version', !$em->getRepository(CreditorProfile::class)->findByContact($ids['papier'])->isActive()
    && $em->getRepository(CreditorProfile::class)->findByContact($ids['papier'])->getVersion() === 3);
// The QR-IBAN of «Strom Nord» shared again — by the (inactive) Papeterie, which the window names below.
(new CreditorProfileService($em, 'Owner'))->update($em->getRepository(CreditorProfile::class)->findByContact($ids['papier']), 3, [], CreditorProfileService::ibanRows([['iban' => $qrIban, 'hint' => 'Papeterie']]));

// ── the screens through a host double ──
echo "SM. Backend screen — list, window, switch (database)\n";
require_once $root . '/packages/kernel/core/src/autoload/prod/php/Helper.php';

$useRequest = function (array $get, ?array $post = null, bool $fetch = false, array $json = []) use ($wire): \Z77\Persistence\Resolver\UnifiedEntityManager {
    $em = $wire();
    \Z77\Core\DI::getInstance()->set('Request', fn() => new class($get, $post, $fetch, $json) {
        public function __construct(private array $get, private ?array $post, private bool $fetch, private array $json) {}
        public function getGetParameter(string $p): mixed { return $this->get[$p] ?? null; }
        public function isPost(): bool { return $this->post !== null; }
        public function getPostParameters(): array { return $this->post ?? []; }
        public function getMode(): \Z77\Core\Http\RequestMode { return $this->fetch ? \Z77\Core\Http\RequestMode::Fetch : \Z77\Core\Http\RequestMode::Page; }
        public function getJsonBody(): array { return $this->json; }
    }, true);
    \Z77\Core\DI::getInstance()->set('CsrfService', fn() => new class {
        public function getToken(): string { return 'tok-session'; }
        public function generateEntityToken(string $context, int $id): string { return "tok-{$context}-{$id}"; }
        public function validateEntityToken(string $token, string $context, int $id): bool { return $token === "tok-{$context}-{$id}"; }
    }, true);
    return $em;
};
$creditorHost = fn() => new class {
    use CreditorProfileControllerTrait { listAction as public; editAction as public; toggleActiveAction as public; }
    public array $context = [];
    public object $layoutManager;
    public object $messageService;
    public object $help;
    public ?string $redirectedTo = null;
    public function __construct()
    {
        $this->help          = new \Z77\Core\Services\HelpService();
        $this->layoutManager = new class {
            public array $sections = [];
            public function removeSection(string $s): void { unset($this->sections[$s]); }
            public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
        };
        $this->messageService = new class {
            public array $flashes = [];
            public function pushFlashAfterRedirect(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
            public function pushFlash(string $type, string $message): void { $this->flashes[] = [$type, $message, 'in-place']; }
        };
    }
    private function creditorProfiles(): CreditorProfileService { return new CreditorProfileService($this->em(), 'Owner'); }
    protected function em() { return \Z77\Core\DI::getUnifiedEntityManager(); }
    protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
    protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
    protected function fetch(): \Z77\Core\Http\Response\FetchResponse { return new \Z77\Core\Http\Response\FetchResponse(); }
    protected function fetchError(string $text): \Z77\Core\Http\Response\FetchResponse { $this->messageService->pushFlash('error', $text); return $this->fetch()->setStatus('error'); }
};
$render   = static fn(string $path, array $context): string => (new \Z77\Core\Services\TemplateRenderer('Z77\\Module\\Creditor'))->partial($path, $context);
$envelope = static fn(\Z77\Core\Http\Response\FetchResponse $r): array => (fn() => $this->build())->call($r);
$commands = static fn(\Z77\Core\Http\Response\FetchResponse $r): array => array_column($envelope($r)['commands'] ?? [], 'action');
$swissId  = (int) $wire()->getRepository(CreditorProfile::class)->findByContact($ids['swiss'])->getId();
$inkId    = (int) $wire()->getRepository(CreditorProfile::class)->findByContact($ids['inkasso'])->getId();

$useRequest([]);
$host = $creditorHost();
$host->listAction();
$listHtml = $render('Backend/CreditorProfileController/listAction', $host->context);
check('SM1 the list (page): three creditors by name, the help in the toolbar, NOTHING in the action cell; rendered — a fetch region, a row per creditor opening its window, the IBAN in fours with the QR mark, «ohne» for «ohne MWST», the payment method\'s label, the «Aktiv» switch',
    $names($host->context['profiles']) === ['Papeterie Muster GmbH', 'Strom Nord AG', 'Swisscom Schweiz AG'] && $host->help->has() && !isset($host->layoutManager->sections['hc1'])
    && str_contains($listHtml, 'data-fetch-region="creditor-find-list"') && str_contains($listHtml, 'data-entity="creditorProfile:' . $swissId . '"')
    && str_contains($listHtml, 'data-window-open="/backend/finance/creditor-profile/edit?id=' . $swissId . '"')
    && str_contains($listHtml, 'DE89 3704 0044 0532 0130 00') && str_contains($listHtml, 'CH44 3199 9123 0008 8901 2 <span class="badge badge--success">QR</span>')
    && str_contains($listHtml, '>ohne<') && str_contains($listHtml, '>Kasse<') && str_contains($listHtml, 'data-fetch-toggle="/backend/finance/creditor-profile/toggle-active?id=' . $swissId . '"'),
    mb_substr(strip_tags($listHtml), 0, 400));
$useRequest(['f_iban' => '3199'], null, true);
$host = $creditorHost();
$host->listAction();
check('SM2 the magnifier searches the database (a part of the SHARED QR-IBAN → both creditors behind it); a fetch of the region carries no help', $names($host->context['profiles']) === ['Papeterie Muster GmbH', 'Strom Nord AG'] && !$host->help->has());

$useRequest(['id' => (string) $inkId, '_origin' => 'region:creditor-find-list'], null, true);
$host = $creditorHost();
$host->editAction();
$winHtml = $render('Backend/CreditorProfileController/edit', $host->context + ['csrfToken' => 'tok-session']);
check('SM3 the window (GET, fetched): the contact fixed, the version and the entity token, the IBAN row plus an empty «Neue IBAN», the shared IBAN names the other creditor, the help attached, a window',
    $host->context['window'] === true && $host->context['version'] === 3 && ($host->layoutManager->sections['main'] ?? []) === ['Backend/CreditorProfileController/edit'] && $host->help->has()
    && str_contains($winHtml, 'data-window="creditor-edit"') && str_contains($winHtml, 'name="version" value="3"') && str_contains($winHtml, 'value="tok-creditorProfile-' . $inkId . '"')
    && str_contains($winHtml, 'name="ibans[0][iban]" value="CH44 3199 9123 0008 8901 2"') && str_contains($winHtml, 'name="ibans[1][iban]" value=""')
    && str_contains($winHtml, 'Auch bei: Papeterie Muster GmbH') && str_contains($winHtml, 'value="Strom Nord AG" readonly'),
    mb_substr(strip_tags($winHtml), 0, 400));

$post = ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-find-list', 'entity_csrf' => 'tok-creditorProfile-' . $inkId, 'version' => '3',
    'uid' => '', 'default_account' => '6200', 'default_tax' => 'VI', 'default_payment_method_code' => 'bank',
    'ibans' => [['iban' => $qrIban, 'hint' => 'Strom Nord', 'active' => '1'], ['iban' => '', 'hint' => '', 'active' => '1']]];
$useRequest(['id' => (string) $inkId], ['entity_csrf' => 'tok-wrong'] + $post, true);
$host = $creditorHost();
$wrong = $host->editAction();
check('SM4 a POST with a wrong entity token is refused, nothing saved', $wrong instanceof \Z77\Core\Http\Response\FetchResponse && ($envelope($wrong)['status'] ?? '') === 'error'
    && $wire()->getRepository(CreditorProfile::class)->find($inkId)->getDefaultAccount() === null);
$useRequest(['id' => (string) $inkId], $post, true);
$host  = $creditorHost();
$saved = $host->editAction();
$em    = $wire();
$ink   = $em->getRepository(CreditorProfile::class)->find($inkId);
check('SM5 the save (fetched): closes the window and reloads the list region; 6200 · VI · Bank stored and stamped, the IBAN active again, the empty row ignored',
    $saved instanceof \Z77\Core\Http\Response\FetchResponse && $commands($saved) === ['close-window', 'refresh-region']
    && $ink->getDefaultAccount() === '6200' && $ink->getDefaultTaxCode() === 'VI' && !$ink->isDefaultWithoutVat() && $ink->getDefaultPaymentMethodCode() === 'bank' && $ink->getDefaultsSetBy() === 'Owner'
    && count($em->getRepository(CreditorIban::class)->forProfile($ink)) === 1 && $em->getRepository(CreditorIban::class)->forProfile($ink)[0]->isActive(), json_encode($envelope($saved)));
$useRequest(['id' => (string) $inkId], ['version' => '4', 'entity_csrf' => 'tok-creditorProfile-' . $inkId, 'default_tax' => '-', 'ibans' => [['iban' => 'CH00 bad', 'hint' => 'x', 'active' => '1']]] + $post, true);
$host    = $creditorHost();
$invalid = $host->editAction();
$errHtml = $render('Backend/CreditorProfileController/edit', $host->context + ['csrfToken' => 'tok-session']);
check('SM6 an invalid IBAN re-renders the window with the field error and what was typed («ohne MWST» selected), nothing saved',
    $invalid instanceof \Z77\Core\Http\Response\HtmlResponse && str_contains($errHtml, 'data-z77-field-error') && str_contains($errHtml, 'Keine IBAN')
    && str_contains($errHtml, 'value="CH00 BAD"') && str_contains($errHtml, '<option value="-" selected>') && $wire()->getRepository(CreditorProfile::class)->find($inkId)->getDefaultTaxCode() === 'VI');
$useRequest(['id' => (string) $inkId], ['version' => '3'] + $post, true);
$host  = $creditorHost();
$stale = $host->editAction();
check('SM7 a stale version from the window is refused with the German sentence, nothing saved', $stale instanceof \Z77\Core\Http\Response\FetchResponse && ($envelope($stale)['status'] ?? '') === 'error'
    && str_contains(json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE), 'inzwischen geändert') && $wire()->getRepository(CreditorProfile::class)->find($inkId)->getVersion() === 4);
$useRequest(['id' => (string) $inkId], null, true, ['value' => false]);
$host   = $creditorHost();
$toggle = $host->toggleActiveAction();
check('SM8 the «Aktiv» switch answers with its row in place (replaceRow creditorProfile:<id>) and writes', $toggle instanceof \Z77\Core\Http\Response\FetchResponse
    && str_contains(json_encode($envelope($toggle)), 'creditorProfile') && str_contains(json_encode($envelope($toggle)), 'be-list__row--inactive')
    && !$wire()->getRepository(CreditorProfile::class)->find($inkId)->isActive(), json_encode($commands($toggle)));
$useRequest(['id' => '999999'], null, true);
$host = $creditorHost();
check('SM9 an unknown id answers with the refusal, not a 500', ($envelope($host->editAction())['status'] ?? '') === 'error');

// ── «Kreditor» off ──
$em = $wire();
(new CreditorContactRole())->switchOff($em->getRepository(Contact::class)->find($ids['inkasso']));
$em = $wire();
check('M19 «Kreditor» off DELETES the profile with its IBANs; the contact stays; the other creditor\'s link to the shared IBAN stays',
    $em->getRepository(CreditorProfile::class)->findByContact($ids['inkasso']) === null && $em->getRepository(Contact::class)->find($ids['inkasso']) !== null
    && (int) $db->fetchOne('SELECT COUNT(*) FROM creditor_iban WHERE creditor_profile_id = ?', [$inkId]) === 0 && $count('creditor_profile') === 2);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
