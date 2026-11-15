<?php

/**
 * module-creditor harness (CLI) — stages 1a and 1b of the creditor plan (`docs/03-development/
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
 * Stage 1b (the creditor invoice, the Kreditorenjournal) adds: O13–O17 (missing(), the duplicate
 * mark, the intake's byte sniff, the line rows, no script in the package), S2b / S8–S12 (seed order,
 * the journal host, actions + #[Csrf], helps + guide, the window's fields, the route role),
 * M20–M36 (intake + DMS + hash idempotency, defaults at intake, update / lines / defaults once /
 * «als Standard übernehmen», refusals, stale version, duplicates by number and by IBAN + reference
 * with release and discard, VAT forcing for a mandator not liable, the role guard, «Kreditor
 * übernehmen …», the list search) and SM10–SM20 (the journal through a host double: list, upload
 * envelope, window, «+ Zeile» without the script, save, refusal, fill helpers, release, delete).
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
use Z77\Module\Creditor\Entities\CreditorIntake;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorInvoiceLine;
use Z77\Module\Creditor\Entities\CreditorJournalPosting;
use Z77\Module\Creditor\Services\CreditorIntakeService;
use Z77\Module\Creditor\Services\CreditorInvoiceService;
use Z77\Module\Creditor\Services\InvalidCreditorInvoiceException;
use Z77\Module\Creditor\Ui\CreditorJournalControllerTrait;
use Z77\Module\Creditor\Ui\CreditorJournalListing;
use Z77\Shared\Money\Money;

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
check('O4 the config announces CreditorProfile, CreditorIban and (1b) CreditorIntake, CreditorInvoice, CreditorInvoiceLine, CreditorJournalPosting, the intake limit 20 MiB, registers the role «creditor» under contactRoles, no view area, module role ADMIN',
    $config['doctrineEntities'] === [CreditorProfile::class, CreditorIban::class, CreditorIntake::class, CreditorInvoice::class, CreditorInvoiceLine::class, CreditorJournalPosting::class]
    && ($config['intake']['maxBytes'] ?? 0) === 20 * 1024 * 1024 && CreditorIntakeService::maxBytes() === 20 * 1024 * 1024 && $config['contactRoles'] === ['creditor' => CreditorContactRole::class]
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

// ── O, stage 1b: the creditor invoice, the intake's byte check ──

/** A small valid PDF; $tag makes the bytes (and the hash) unique. */
function pdfBytes(string $tag): string
{
    return "%PDF-1.4\n% {$tag}\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

$chf    = static fn(string $d): Money => Money::fromDecimal($d, 'CHF');
$inv    = new CreditorInvoice(null, 7, 'Owner', new \DateTimeImmutable('2026-11-15 09:00'));
$empty1 = [new CreditorInvoiceLine($inv, 0)];
$miss0  = $inv->missing($empty1);
$profOff = new CreditorProfile();
(new \ReflectionProperty(CreditorInvoice::class, 'creditor'))->setValue($inv, $profOff);
$inv->correct($profOff, null, '2026-117', null, null, CreditorInvoice::REFERENCE_NONE, null, '', $chf('100.00'), 'bank', '1020', 'Owner', new \DateTimeImmutable());
$split  = [new CreditorInvoiceLine($inv, 0, 'A', $chf('60.00'), '6500', 'VM'), new CreditorInvoiceLine($inv, 1, 'B', $chf('30.00'), '6510', null, true)];
$miss1  = $inv->missing($split);
$whole  = [new CreditorInvoiceLine($inv, 0, 'A', $chf('70.00'), '6500', 'VM'), new CreditorInvoiceLine($inv, 1, 'B', $chf('30.00'), '6510', null, true)];
check('O13 CreditorInvoice::missing(): a fresh invoice lacks Kreditor, Betrag, «Bezahlt mit», Konto and MWST; with head and two lines that do not add up only «Zeilen = Betrag»; adding up = complete; label() names the number',
    $miss0 === ['Kreditor', 'Betrag', 'Bezahlt mit', 'Konto', 'MWST'] && $miss1 === ['Zeilen = Betrag'] && $inv->isComplete($whole) && $inv->label() === 'Rechnung 2026-117'
    && $inv->isOpen() && $inv->getPaymentState() === CreditorInvoice::PAYMENT_UNPAID && $inv->getPostingVariant() === CreditorInvoice::VARIANT_DIRECT,
    json_encode([$miss0, $miss1]));
$inv->markDuplicate(3, 'mögliches Doppel von Rechnung 9');
$blockedNow = $inv->isBlocked() && $inv->getDuplicateOf() === 3 && $inv->isEditable();
$inv->releaseDuplicate('Owner', new \DateTimeImmutable());
check('O14 the duplicate mark: markDuplicate() blocks (still editable), releaseDuplicate() opens it again and remembers the pair (duplicate_cleared_of); a line refuses a tax code together with «ohne MWST» and a non-positive amount; the proposal is JSON of scalars only',
    $blockedNow && $inv->isOpen() && $inv->getDuplicateOf() === null && $inv->getDuplicateClearedOf() === 3 && $inv->getDuplicateClearedBy() === 'Owner'
    && caught(fn() => new CreditorInvoiceLine($inv, 0, '', null, null, 'VM', true), \LogicException::class) !== null
    && caught(fn() => new CreditorInvoiceLine($inv, 0, '', $chf('0.00')), \LogicException::class) !== null
    && caught(fn() => $inv->releaseDuplicate('Owner', new \DateTimeImmutable()), \LogicException::class) !== null
    && (static function () use ($inv): bool { $inv->proposeCreditor(['name' => 'Neu AG', 'nested' => ['x'], 'iban' => 'CH44']); return $inv->proposal() === ['name' => 'Neu AG', 'iban' => 'CH44']; })());

$sniffed = static function (string $bytes): string {
    try { CreditorIntakeService::sniff($bytes); return 'ok'; } catch (\Z77\Module\Creditor\Services\CreditorRefusedException $e) { return $e->reason; }
};
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
check('O15 the intake sniffs the BYTES: a PDF passes; empty, a PNG named anything, text that only mentions %PDF- later and a file above 20 MiB are refused with their reason; the stored name is a slug with .pdf',
    $sniffed(pdfBytes('a')) === 'ok' && $sniffed('') === 'empty-file' && $sniffed($png) === 'not-pdf'
    && $sniffed(str_repeat('x', 2000) . '%PDF-1.4') === 'not-pdf' && $sniffed('%PDF-' . str_repeat('0', 20 * 1024 * 1024)) === 'too-large'
    && CreditorIntakeService::baseName('C:\\Scans\\Rechnung März 2026.PDF') === 'rechnung-maerz-2026.pdf' && CreditorIntakeService::baseName('...') === 'rechnung.pdf',
    json_encode([$sniffed(pdfBytes('a')), CreditorIntakeService::baseName('C:\\Scans\\Rechnung März 2026.PDF')]));
check('O16 lineRows(): strings only, a row without text, account and amount dropped (the empty «+ Zeile» row), the VAT choice kept as posted',
    CreditorInvoiceService::lineRows([['text' => ' a ', 'account' => '6500', 'tax' => 'VM', 'amount' => '10'], ['text' => '', 'account' => '', 'tax' => '-', 'amount' => ''], 'x', ['amount' => ['no']]])
        === [['text' => 'a', 'account' => '6500', 'tax' => 'VM', 'amount' => '10']]);
$jsSrc = '';
foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pkg, \FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile() && preg_match('/\.(js|php)$/', $file->getFilename())) { $jsSrc .= file_get_contents($file->getPathname()); }
}
check('O17 Rule 7: the package ships no script of its own — no .js file, no <script, no innerHTML / eval anywhere in it',
    glob($pkg . '/res/assets/js/*') === [] && !str_contains($jsSrc, '<script') && !str_contains($jsSrc, 'innerHTML') && !preg_match('/\beval\s*\(/', $jsSrc));

// ── S. the backend screen, offline ───────────────────────────────────────

echo "S. Backend screen — navigation, mount, fragment, templates, help (offline)\n";

$seed = json_decode((string) file_get_contents($pkg . '/data/framework/routing/navigation.d/module-creditor.json'), true);
$seedBy = array_column($seed, null, 'key');
check('S1 the seed ships TWO leaves: «Kreditorenjournal» under Finanzen → backend/finance/creditor-journal/list (1b) and «Kreditoren» under Stammdaten › Finanzen → backend/finance/creditor-profile/list',
    count($seed) === 2 && ($seedBy['kreditoren']['name'] ?? '') === 'Kreditoren' && $seedBy['kreditoren']['parent_key'] === 'stammdaten-finanzen'
    && $seedBy['kreditoren']['module'] === 'backend' && $seedBy['kreditoren']['group'] === 'finance' && $seedBy['kreditoren']['controller'] === 'creditor-profile' && $seedBy['kreditoren']['action'] === 'list'
    && ($seedBy['kreditorenjournal']['name'] ?? '') === 'Kreditorenjournal' && $seedBy['kreditorenjournal']['parent_key'] === 'finanzen'
    && $seedBy['kreditorenjournal']['controller'] === 'creditor-journal' && $seedBy['kreditorenjournal']['action'] === 'list' && $seedBy['kreditorenjournal']['group'] === 'finance');
$navRoots  = array_map(static fn(string $p) => $root . '/packages/' . $p . '/data', ['kernel/core', 'module-financial', 'module-vat', 'module-mandator', 'module-receipt', 'module-creditor']);
$navMerged = \Z77\Shared\Import\NavigationSeeds::merge(\Z77\Shared\Import\NavigationSeeds::read(\Z77\Shared\Import\NavigationSeeds::discover($navRoots)), [])['records'];
$navByKey  = array_column(array_filter($navMerged, static fn($r) => $r['key'] !== null), null, 'key');
$navKids   = array_values(array_filter($navMerged, static fn($r) => $r['parent_id'] === ($navByKey['stammdaten-finanzen']['id'] ?? -1)));
usort($navKids, static fn($a, $b) => $a['sort_key'] <=> $b['sort_key']);
check('S2 fresh install: Stammdaten › Finanzen ends with Belegvorlagen, Zahlungsarten, Kreditoren (plan §8: after «Zahlungsarten»)',
    array_slice(array_column($navKids, 'name'), -3) === ['Belegvorlagen', 'Zahlungsarten', 'Kreditoren'], json_encode(array_column($navKids, 'name'), JSON_UNESCAPED_UNICODE));
$finKids = array_values(array_filter($navMerged, static fn($r) => $r['parent_id'] === ($navByKey['finanzen']['id'] ?? -1)));
usort($finKids, static fn($a, $b) => $a['sort_key'] <=> $b['sort_key']);
check('S2b fresh install: Finanzen starts with Journal, Belegjournal, Kreditorenjournal (plan §8: after «Belegjournal»)',
    array_slice(array_column($finKids, 'name'), 0, 3) === ['Journal', 'Belegjournal', 'Kreditorenjournal'], json_encode(array_column($finKids, 'name'), JSON_UNESCAPED_UNICODE));

$host   = (string) @file_get_contents($root . '/packages/module-backend/src/Ui/Controllers/Finance/CreditorProfileController.php');
$hostCf = (string) @file_get_contents($root . '/packages/module-backend/src/Ui/Config/Finance/creditorProfileControllerConfig.inc.php');
check('S3 a thin host in module-backend (ADR-018): Finance/CreditorProfileController uses the trait; its layout config pins the fragment\'s listAction in module-creditor; the URL root lives once, in CreditorLayout',
    str_contains($host, 'use CreditorProfileControllerTrait;') && str_contains($host, 'extends BackendAbstractController') && str_contains($hostCf, 'CreditorLayout::profiles()')
    && (CreditorLayout::profiles()['levelElements']['body']['main'][0] ?? []) === ['nameSpace' => 'Z77\Module\Creditor', 'path' => 'Backend/CreditorProfileController', 'name' => 'listAction']
    && CreditorLayout::BASE === '/backend/finance/creditor-profile' && CreditorLayout::JOURNAL_BASE === '/backend/finance/creditor-journal'
    && substr_count($sourceOf($pkg . '/src'), "'/backend/finance/") === 2 && substr_count((string) file_get_contents($pkg . '/src/Ui/CreditorLayout.php'), "'/backend/finance/") === 2);

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

// ── S, stage 1b: the Kreditorenjournal ──
$jHost   = (string) @file_get_contents($root . '/packages/module-backend/src/Ui/Controllers/Finance/CreditorJournalController.php');
$jHostCf = (string) @file_get_contents($root . '/packages/module-backend/src/Ui/Config/Finance/creditorJournalControllerConfig.inc.php');
check('S8 a thin host for the Kreditorenjournal (ADR-018): Finance/CreditorJournalController uses the trait; its layout config pins the fragment\'s listAction',
    str_contains($jHost, 'use CreditorJournalControllerTrait;') && str_contains($jHost, 'extends BackendAbstractController') && str_contains($jHostCf, 'CreditorLayout::journal()')
    && (CreditorLayout::journal()['levelElements']['body']['main'][0] ?? []) === ['nameSpace' => 'Z77\Module\Creditor', 'path' => 'Backend/CreditorJournalController', 'name' => 'listAction']);
$jActions = array_values(array_filter(array_map(static fn(\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass(CreditorJournalControllerTrait::class))->getMethods()), static fn($m) => str_ends_with($m, 'Action')));
$jAttr    = static fn(string $method): bool => (new \ReflectionMethod(CreditorJournalControllerTrait::class, $method))->getAttributes(\Z77\Shared\Attributes\Csrf::class) !== [];
$jTrait   = (string) file_get_contents($pkg . '/src/Ui/CreditorJournalControllerTrait.php');
check('S9 the journal fragment: list, upload, edit, creditor-fill, payment-fill, release, confirm-delete, adopt; every POST action #[Csrf]; the upload in the action cell, the tabs in the toolbar; answers only through the helpers (json for the upload envelope)',
    $jActions === ['listAction', 'uploadAction', 'editAction', 'creditorFillAction', 'paymentFillAction', 'releaseAction', 'confirmDeleteAction', 'adoptAction']
    && $jAttr('uploadAction') && $jAttr('editAction') && $jAttr('releaseAction') && $jAttr('confirmDeleteAction') && $jAttr('adoptAction')
    && str_contains($jTrait, "addPartials('act', 'Backend/CreditorJournalController', self::CREDITOR_JOURNAL_NS, 'hc1')")
    && str_contains($jTrait, "addPartials('toolbar', 'Backend/CreditorJournalController', self::CREDITOR_JOURNAL_NS, 'hc2')")
    && str_contains($jTrait, '$this->json(') && preg_match('/new\s+\\\\?[A-Za-z\\\\]*Response\s*\(/', $jTrait) === 0, json_encode($jActions));

$jDir      = $pkg . '/res/view/templates/Backend/CreditorJournalController';
$jListHelp = (string) @file_get_contents($jDir . '/list.help.tpl.php');
$jEditHelp = (string) @file_get_contents($jDir . '/edit.help.tpl.php');
$guide     = (string) @file_get_contents($pkg . '/res/view/templates/Backend/Guide/creditorWorkflow.help.tpl.php');
$jSections = preg_match_all('/data-help-field="([a-z_]+)"/', $jEditHelp, $m) ? $m[1] : [];
$jListSec  = preg_match_all('/data-help-field="([a-z_]+)"/', $jListHelp, $m) ? $m[1] : [];
$prose     = $jListHelp . $jEditHelp . $guide;
$noPhp     = static fn(string $html): bool => !str_contains((string) preg_replace('~^<\?php.*?\?>~s', '', $html), '<?');
check('S10 the helps: list.help (toolbar: true) with a section per search field, edit.help with a section per field of the window, the guide creditorWorkflow (Buchen: folgt, automatische Erkennung: folgt); each opens with an <h3> and a first <p> of ≤ 240 characters; no «ß», «du», no PHP condition in the prose',
    str_contains($jTrait, "'Backend/CreditorJournalController/list.help'") && str_contains($jTrait, 'toolbar: true') && str_contains($jTrait, "'Backend/CreditorJournalController/edit.help'")
    && $jListSec === ['f_creditor', 'f_number', 'f_date', 'f_amount']
    && $jSections === ['creditor', 'iban', 'invoice_number', 'invoice_date', 'due_date', 'reference', 'message', 'amount', 'payment_method_code', 'credit_account', 'line_text', 'line_account', 'line_tax', 'line_amount', 'split', 'take_default', 'release', 'adopt']
    && str_contains($jListHelp, '<h3>Kreditorenjournal</h3>') && str_contains($jEditHelp, '<h3>Rechnung im Kreditorenjournal</h3>') && str_contains($guide, '<h3>Lieferantenrechnungen')
    && $firstP($jListHelp) <= 240 && $firstP($jEditHelp) <= 240 && $firstP($guide) <= 240 && !str_contains($prose, 'ß')
    && preg_match('/\bdu\b|\bdeine?\b/u', $jListHelp) === 1 && preg_match('/\bdu\b/u', $jEditHelp) === 1 && preg_match('/\bdu\b/u', $guide) === 1
    && str_contains($guide, 'Buchen: folgt') && str_contains($guide, 'Automatische Erkennung: folgt') && $noPhp($jListHelp) && $noPhp($jEditHelp) && $noPhp($guide),
    json_encode([$jSections, $jListSec, $firstP($jListHelp), $firstP($jEditHelp), $firstP($guide)]));
$jEdit = (string) @file_get_contents($jDir . '/edit.tpl.php');
$keys  = [];
preg_match_all('/(?:name|data-help-key)="([a-z_]+)"/', $jEdit, $m);
check('S11 the window names its fields as the help does (name / data-help-key), posts the version, edits the lines with core.js «rows» (template, + Zeile and × as submits without the script), the PDF through ReceiptPhoto (MediaField) in a .be-docview — no address of its own, no script',
    array_diff(['creditor', 'iban', 'invoice_number', 'invoice_date', 'due_date', 'reference', 'message', 'amount', 'payment_method_code', 'credit_account', 'line_text', 'line_account', 'line_tax', 'line_amount', 'split', 'take_default', 'release', 'version'], $m[1]) === []
    && str_contains($jEdit, 'data-rows="lines"') && str_contains($jEdit, 'data-row-template="line"') && str_contains($jEdit, 'name="op" value="add"') && str_contains($jEdit, 'value="remove:')
    && str_contains($jEdit, 'class="be-docview"') && str_contains($jEdit, "\$pdf['previewUrl']") && !str_contains($jEdit, '/backend/documents') && str_contains($jTrait, 'ReceiptPhoto::of(')
    && !str_contains($jEdit, '<script') && !str_contains($jEdit, 'onclick'), json_encode(array_values(array_unique($m[1]))));
$jRoleOf = static fn(string $action): string => \Z77\Shared\Services\AuthService::requiredRole(
    $backendConfig['moduleRole'], $backendConfig['controllers'], 'finance', \Z77\Shared\Libraries\Convention\Naming::toCamelCase('creditor-journal') . 'Controller', \Z77\Shared\Libraries\Convention\Naming::toActionMethod($action));
check('S12 the journal route needs the module role ADMIN for every action (no own rule)',
    array_unique(array_map($jRoleOf, ['list', 'upload', 'edit', 'creditor-fill', 'payment-fill', 'release', 'confirm-delete', 'adopt'])) === [\Z77\Core\Config\AuthRole::ADMIN]);

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

$migration = 'Z77\Module\Creditor\Migrations\Version20261115120000';
$allTables = ['creditor_iban', 'creditor_intake', 'creditor_invoice', 'creditor_invoice_line', 'creditor_journal_posting', 'creditor_profile'];
[$code, $out] = $run(['command' => 'migrate']);
check('M1 migrate exits 0 (vat, contact, mandator, financial, receipt, creditor in timestamp order); the module\'s namespace is found through its doctrineEntities', $code === 0 && $tables() === $allTables
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
check('M3 the 1b migration is the newest: down() drops its four tables (and only them — the 1a tables stay), up() creates them again', $codeDown === 0 && $afterDown === ['creditor_iban', 'creditor_profile'] && $current === '1'
    && $codeUp === 0 && $tables() === $allTables && str_contains($outUp, $migration), trim($outDown . $outUp));

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

// ── M, stage 1b: intake, the invoice, duplicates, VAT, the role guard ──
echo "M. Stage 1b — intake, invoice, duplicates, VAT, role guard (database)\n";
$docs     = fn() => json_decode((string) @file_get_contents($base . '/data/documents/documents.json'), true) ?: [];
$folders  = fn() => array_column(json_decode((string) @file_get_contents($base . '/data/documents/folders.json'), true) ?: [], null, 'id');
$pathOf   = function (int $folderId) use ($folders): string {
    $all = $folders();
    $names = [];
    for ($id = $folderId; isset($all[$id]) && $all[$id]['parent_id'] !== null; $id = (int) $all[$id]['parent_id']) { array_unshift($names, $all[$id]['name']); }
    return implode('/', $names);
};
$intakeOf  = static fn(\Z77\Persistence\Resolver\UnifiedEntityManager $em): CreditorIntakeService => new CreditorIntakeService($em, null, 'Owner');
$invoiceOf = static fn(\Z77\Persistence\Resolver\UnifiedEntityManager $em): CreditorInvoiceService => new CreditorInvoiceService($em, 'Owner');
$refusedInvoice = static function (callable $fn): array {
    $e = caught($fn, InvalidCreditorInvoiceException::class);
    return $e instanceof InvalidCreditorInvoiceException ? $e->errors : ['none' => 'saved'];
};
$reason = static function (callable $fn): string {
    $e = caught($fn, \Z77\Module\Creditor\Services\CreditorRefusedException::class);
    return $e instanceof \Z77\Module\Creditor\Services\CreditorRefusedException ? $e->reason . ': ' . $e->getMessage() : 'none';
};
$now = new \DateTimeImmutable();

// A creditor with decided defaults (6510 · VM · Bank) and one without any.
$em = $wire();
$cs = new ContactService($em);
$cTel = new Contact(['kind' => 'organisation', 'company' => 'Telekom Nord AG', 'language' => 'de']);
$cs->save($cTel);
$cNew = new Contact(['kind' => 'organisation', 'company' => 'Neulieferant GmbH', 'language' => 'de']);
$cs->save($cNew);
$ids += ['tel' => (int) $cTel->getId(), 'new' => (int) $cNew->getId()];
$em  = $wire();
$tel = (new CreditorProfileService($em))->open($em->getRepository(Contact::class)->find($ids['tel']));
(new CreditorProfileService($em, 'Owner'))->update($tel, 1, ['default_account' => '6510', 'default_tax_code' => 'VM', 'default_payment_method_code' => 'bank'], CreditorProfileService::ibanRows([['iban' => $qrIban]]));
$em  = $wire();
$neu = (new CreditorProfileService($em))->open($em->getRepository(Contact::class)->find($ids['new']));
$telId = (int) $tel->getId();
$neuId = (int) $neu->getId();

// ── the intake ──
$em = $wire();
$i1 = $intakeOf($em)->receive(pdfBytes('one'), 'Rechnung Okt.pdf');
$em = $wire();
$row1  = $db->fetchAssociative('SELECT * FROM creditor_intake WHERE id = ?', [$i1->getIntake()->getId()]);
$doc1  = array_values(array_filter($docs(), static fn($d) => (int) $d['id'] === $i1->getDocumentId()))[0] ?? [];
$lines1 = $invoiceOf($em)->linesOf($invoiceOf($em)->find((int) $i1->getId()));
check('M20 receive(): the PDF stored UNALTERED in the DMS under Kreditorenrechnungen/<year>/<month of receipt> (source generated, checksum = the hash), the intake row (upload, name, size, sha256), the invoice OPEN · unpaid · direct with ONE empty line, no creditor',
    $i1->getId() !== null && $i1->isOpen() && $i1->getPaymentState() === 'unpaid' && $i1->getPostingVariant() === 'direct' && $i1->getCreditor() === null
    && ($row1['channel'] ?? '') === 'upload' && ($row1['sha256'] ?? '') === hash('sha256', pdfBytes('one')) && (int) $row1['size_bytes'] === strlen(pdfBytes('one'))
    && ($row1['original_name'] ?? '') === 'rechnung-okt.pdf' && (int) $row1['document_id'] === $i1->getDocumentId()
    && ($doc1['checksum'] ?? '') === hash('sha256', pdfBytes('one')) && ($doc1['mime_type'] ?? $doc1['mimeType'] ?? '') === 'application/pdf'
    && $pathOf((int) ($doc1['folder_id'] ?? $doc1['folderId'] ?? 0)) === 'Kreditorenrechnungen/' . $now->format('Y') . '/' . $now->format('m')
    && count($lines1) === 1 && $lines1[0]->getAccount() === null && !$lines1[0]->vatDecided(), json_encode([$row1, $doc1]));
$docsBefore = count($docs());
$em   = $wire();
$dup  = $reason(fn() => $intakeOf($em)->receive(pdfBytes('one'), 'nochmals.pdf'));
$png  = $reason(fn() => $intakeOf($wire())->receive($png, 'rechnung.pdf'));
$none = $reason(fn() => $intakeOf($wire())->receive('', 'leer.pdf'));
check('M21 the identical file is never read twice: «bereits eingelesen am … (Rechnung …)», nothing stored (no document, no row); a PNG named .pdf and an empty file refused',
    str_starts_with($dup, 'duplicate-file: bereits eingelesen am ' . $now->format('d.m.Y')) && str_contains($dup, 'Rechnung')
    && str_starts_with($png, 'not-pdf') && str_starts_with($none, 'empty-file') && count($docs()) === $docsBefore
    && $count('creditor_intake') === 1 && $count('creditor_invoice') === 1, $dup);
$em = $wire();
$i2 = $intakeOf($em)->receive(pdfBytes('two'), 'tel.pdf', 'upload', $em->getRepository(CreditorProfile::class)->find($telId));
$em = $wire();
$l2 = $invoiceOf($em)->linesOf($invoiceOf($em)->find((int) $i2->getId()));
check('M22 a KNOWN creditor at intake: the line starts on its defaults (6510 · VM), the invoice on «Bezahlt mit» Bank with its Haben 1020',
    $i2->getCreditor()?->getId() === $telId && $i2->getPaymentMethodCode() === 'bank' && $i2->getCreditAccount() === '1020'
    && count($l2) === 1 && $l2[0]->getAccount() === '6510' && $l2[0]->getTaxCode() === 'VM' && !$l2[0]->isWithoutVat());

// ── update: head, lines, defaults once ──
$qrRef = '210000000003139471430009017';
$head  = static fn(array $over = []): array => $over + ['creditor' => '', 'iban' => '', 'invoice_number' => '', 'invoice_date' => '', 'due_date' => '', 'reference' => '', 'message' => '', 'amount' => '', 'payment_method_code' => '', 'credit_account' => ''];
$em = $wire();
$s1 = $invoiceOf($em)->update((int) $i1->getId(), 1, $head(['creditor' => (string) $neuId, 'iban' => 'CH44 3199 9123 0008 8901 2', 'invoice_number' => 'A-1', 'invoice_date' => '2026-11-01', 'due_date' => '2026-11-30',
    'reference' => '21 00000 00003 13947 14300 09017', 'message' => 'Okt', 'amount' => '107.70', 'payment_method_code' => 'bank']), [['text' => 'Strom', 'account' => '6400', 'tax' => 'VM', 'amount' => '']]);
$em  = $wire();
$s1  = $invoiceOf($em)->find((int) $i1->getId());
$sl1 = $invoiceOf($em)->linesOf($s1);
$neuNow = $em->getRepository(CreditorProfile::class)->find($neuId);
check('M23 update(): head stored (IBAN normalised, QRR derived from the reference, dates), «Bezahlt mit» resolves Haben 1020, the ONE line without amount takes 107.70 → complete; the creditor without defaults takes them from this first complete invoice (6400 · VM · Bank, stamped) — defaults once',
    $s1->getCreditor()?->getId() === $neuId && $s1->getIban() === $qrIban && $s1->getReferenceType() === 'QRR' && $s1->getReference() === $qrRef
    && $s1->getInvoiceDate()?->format('Y-m-d') === '2026-11-01' && $s1->getCreditAccount() === '1020' && $s1->getAmount()?->toDecimal() === '107.70'
    && count($sl1) === 1 && $sl1[0]->getAmount()?->toDecimal() === '107.70' && $s1->isComplete($sl1) && $s1->isOpen() && $s1->getVersion() === 2
    && $neuNow->getDefaultAccount() === '6400' && $neuNow->getDefaultTaxCode() === 'VM' && $neuNow->getDefaultPaymentMethodCode() === 'bank' && $neuNow->getDefaultsSetBy() === 'Owner');
$stamp = $neuNow->getDefaultsSetAt();
$em = $wire();
$invoiceOf($em)->update((int) $i1->getId(), 2, $head(['creditor' => (string) $neuId, 'invoice_number' => 'A-1', 'amount' => '107.70', 'payment_method_code' => 'bank']),
    [['text' => '', 'account' => '6500', 'tax' => 'VI', 'amount' => '100.00'], ['text' => 'Porto', 'account' => '6500', 'tax' => '-', 'amount' => '7.70']]);
$em = $wire();
$neuNow = $em->getRepository(CreditorProfile::class)->find($neuId);
$sl1    = $invoiceOf($em)->linesOf($invoiceOf($em)->find((int) $i1->getId()));
check('M24 two lines inside ONE invoice (owner answer 2), positions kept; another account later NEVER overwrites the decided defaults silently',
    count($sl1) === 2 && $sl1[1]->getText() === 'Porto' && $sl1[1]->isWithoutVat() && $sl1[0]->getTaxCode() === 'VI'
    && $neuNow->getDefaultAccount() === '6400' && $neuNow->getDefaultsSetAt() == $stamp);
$em = $wire();
$invoiceOf($em)->update((int) $i1->getId(), 3, $head(['creditor' => (string) $neuId, 'invoice_number' => 'A-1', 'amount' => '107.70', 'payment_method_code' => 'bank']),
    [['text' => '', 'account' => '6500', 'tax' => 'VI', 'amount' => '107.70']], true);
$em = $wire();
check('M25 «als Standard übernehmen» overwrites them on purpose (6500 · VI), stamped again', $em->getRepository(CreditorProfile::class)->find($neuId)->getDefaultAccount() === '6500'
    && $em->getRepository(CreditorProfile::class)->find($neuId)->getDefaultTaxCode() === 'VI');
$em  = $wire();
$bad = $refusedInvoice(fn() => $invoiceOf($em)->update((int) $i1->getId(), 4, $head(['iban' => 'CH93 0076 2011 6238 5295 7', 'reference' => $qrRef, 'invoice_date' => '2026-11-10', 'due_date' => '2026-11-01', 'amount' => '-5', 'payment_method_code' => 'bank']),
    [['text' => '', 'account' => '1020', 'tax' => 'XX', 'amount' => 'abc']]));
$bad2 = $refusedInvoice(fn() => $invoiceOf($wire())->update((int) $i1->getId(), 4, $head(['iban' => 'CH93 0076 2011 6238 5295 8', 'reference' => 'RF00 1234']), []));
check('M26 refused per field, in German, nothing written: a QR reference without QR-IBAN, «Fällig am» before the invoice date, a negative amount, a line account equal to Haben, an unknown tax code, a line amount that is none; a wrong IBAN check digit and an invalid RF',
    str_contains($bad['reference'] ?? '', 'QR-IBAN') && str_contains($bad['due_date'] ?? '', 'vor dem Rechnungsdatum') && str_contains($bad['amount'] ?? '', 'grösser als 0')
    && str_contains($bad['line_0_account'] ?? '', 'dasselbe Konto') && str_contains($bad['line_0_tax'] ?? '', 'gibt es nicht') && isset($bad['line_0_amount'])
    && str_contains($bad2['iban'] ?? '', 'Prüfziffer') && str_contains($bad2['reference'] ?? '', 'Keine gültige Referenz')
    && (int) $db->fetchOne('SELECT version FROM creditor_invoice WHERE id = ?', [$i1->getId()]) === 4, json_encode([$bad, $bad2], JSON_UNESCAPED_UNICODE));
$stale = $reason(fn() => $invoiceOf($wire())->update((int) $i1->getId(), 1, $head(), []));
check('M27 a stale version is refused (CHANGED), nothing written', str_starts_with($stale, 'changed: Die Rechnung wurde inzwischen geändert'));

// ── duplicates (owner answer 4) ──
$em = $wire();
$i3 = $intakeOf($em)->receive(pdfBytes('three'), 'mahnung.pdf');
$em = $wire();
$b3 = $invoiceOf($em)->update((int) $i3->getId(), 1, $head(['creditor' => (string) $neuId, 'invoice_number' => 'A-1', 'amount' => '107.70']), []);
$em = $wire();
$b3 = $invoiceOf($em)->find((int) $i3->getId());
check('M28 a suspected duplicate (same creditor + invoice number) is CREATED and BLOCKED with «mögliches Doppel von Rechnung A-1 (Neulieferant GmbH) — gleicher Kreditor …»; the original stays open',
    $b3->isBlocked() && $b3->getDuplicateOf() === (int) $i1->getId() && str_contains($b3->getDuplicateReason(), 'mögliches Doppel von Rechnung A-1 (Neulieferant GmbH)')
    && str_contains($b3->getDuplicateReason(), 'gleiche Rechnungs-Nr.') && $invoiceOf($em)->find((int) $i1->getId())->isOpen(), $b3->getDuplicateReason());
$em = $wire();
$invoiceOf($em)->update((int) $i1->getId(), 4, $head(['creditor' => (string) $neuId, 'invoice_number' => 'A-1', 'amount' => '107.70', 'payment_method_code' => 'bank', 'iban' => $qrIban, 'reference' => $qrRef]), [['text' => '', 'account' => '6500', 'tax' => 'VI', 'amount' => '']]);
$em = $wire();
check('M29 saving the ORIGINAL again does not block it (the blocked one is its duplicate, not the other way round)', $invoiceOf($em)->find((int) $i1->getId())->isOpen());
$em = $wire();
$invoiceOf($em)->update((int) $i3->getId(), 2, $head(['creditor' => (string) $neuId, 'invoice_number' => 'A-2', 'amount' => '107.70']), []);
$em = $wire();
$fixedInv = $invoiceOf($em)->find((int) $i3->getId());
$fixed = ["open" => $fixedInv->isOpen(), "dup" => $fixedInv->getDuplicateOf()];
$invoiceOf($em)->update((int) $i3->getId(), 3, $head(['creditor' => (string) $neuId, 'invoice_number' => 'A-1', 'amount' => '107.70']), []);
$em = $wire();
$again = $invoiceOf($em)->find((int) $i3->getId())->isBlocked();
$invoiceOf($em)->release((int) $i3->getId(), 4);
$em = $wire();
$releasedInv = $invoiceOf($em)->find((int) $i3->getId());
$released = ["open" => $releasedInv->isOpen(), "cleared" => $releasedInv->getDuplicateClearedOf(), "by" => $releasedInv->getDuplicateClearedBy()];
$invoiceOf($em)->update((int) $i3->getId(), 5, $head(['creditor' => (string) $neuId, 'invoice_number' => 'A-1', 'amount' => '107.70', 'message' => 'neu']), []);
$em = $wire();
check('M30 a corrected typo lifts the mark by itself; matched again it is blocked again; «Kein Doppel — übernehmen» opens it and remembers the pair — a later save of the same pair stays open',
    $fixed === ["open" => true, "dup" => null] && $again && $released === ["open" => true, "cleared" => (int) $i1->getId(), "by" => 'Owner'] && $invoiceOf($em)->find((int) $i3->getId())->isOpen()
    && str_starts_with($reason(fn() => $invoiceOf($wire())->release((int) $i3->getId(), 6)), 'changed'), json_encode([$fixed, $again, $released]));
$em = $wire();
$i4 = $intakeOf($em)->receive(pdfBytes('four'), 'mahnung2.pdf');
$em = $wire();
$invoiceOf($em)->update((int) $i4->getId(), 1, $head(['iban' => $qrIban, 'reference' => $qrRef]), []);
$em = $wire();
$b4 = $invoiceOf($em)->find((int) $i4->getId());
check('M31 the second rule: the same IBAN + QR reference (a reminder without creditor) is blocked as «gleiche IBAN und gleiche Referenz»',
    $b4->isBlocked() && $b4->getDuplicateOf() === (int) $i1->getId() && str_contains($b4->getDuplicateReason(), 'gleiche IBAN und gleiche Referenz'));
$docId4 = $b4->getDocumentId();
$em = $wire();
$deletedDoc = $invoiceOf($em)->delete((int) $i4->getId(), 2);
$em = $wire();
$redo = $reason(fn() => $intakeOf($wire())->receive(pdfBytes('four'), 'mahnung2.pdf'));
check('M32 «Verwerfen …» deletes the invoice with its lines; the PDF stays in the DMS and the intake row stays — the same file uploaded again says «bereits eingelesen … die Rechnung dazu wurde gelöscht»',
    $deletedDoc === $docId4 && $invoiceOf($em)->find((int) $i4->getId()) === null && (int) $db->fetchOne('SELECT COUNT(*) FROM creditor_invoice_line WHERE invoice_id = ?', [$i4->getId()]) === 0
    && array_filter($docs(), static fn($d) => (int) $d['id'] === $docId4) !== [] && $count('creditor_intake') === 4 && str_contains($redo, 'gelöscht'), $redo);

// ── a mandator not liable to VAT (owner answer 5) ──
$em = $wire();
(new \Z77\Module\Mandator\Services\MandatorService($em))->update((new \Z77\Module\Mandator\Services\CurrentMandator($em))->find(), ['liable_to_vat' => false]);
$em   = $wire();
$i5   = $intakeOf($em)->receive(pdfBytes('five'), 'x.pdf', 'upload', $em->getRepository(CreditorProfile::class)->find($telId));
$em   = $wire();
$l5   = $invoiceOf($em)->linesOf($invoiceOf($em)->find((int) $i5->getId()));
$noVat = $refusedInvoice(fn() => $invoiceOf($wire())->update((int) $i5->getId(), 1, $head(['creditor' => (string) $telId]), [['text' => '', 'account' => '6510', 'tax' => 'VM', 'amount' => '']]));
$em = $wire();
$invoiceOf($em)->update((int) $i5->getId(), 1, $head(['creditor' => (string) $telId, 'amount' => '50.00']), [['text' => '', 'account' => '6510', 'tax' => '', 'amount' => '']]);
$em = $wire();
$l5b = $invoiceOf($em)->linesOf($invoiceOf($em)->find((int) $i5->getId()));
check('M33 the mandator NOT liable to VAT FORCES «ohne MWST»: at intake the creditor\'s VM default becomes «ohne MWST», a tax code typed on a line is refused in German, an undecided line is saved «ohne MWST»',
    $l5[0]->isWithoutVat() && $l5[0]->getTaxCode() === null && str_contains($noVat['line_0_tax'] ?? '', 'nicht MWST-pflichtig')
    && $l5b[0]->isWithoutVat() && $l5b[0]->getTaxCode() === null, json_encode($noVat, JSON_UNESCAPED_UNICODE));
(new \Z77\Module\Mandator\Services\MandatorService($em))->update((new \Z77\Module\Mandator\Services\CurrentMandator($em))->find(), ['liable_to_vat' => true]);

// ── the role guard (1a's open item) ──
$em    = $wire();
$state = (new CreditorContactRole())->state($em->getRepository(Contact::class)->find($ids['tel']));
$off   = caught(fn() => (new CreditorContactRole())->switchOff($wire()->getRepository(Contact::class)->find($ids['tel'])), ContactRoleRefusedException::class);
$del   = $reason(fn() => (new CreditorProfileService($wire()))->delete($wire()->getRepository(CreditorProfile::class)->find($telId)));
$em    = $wire();
check('M34 a creditor WITH invoices: the role «Kreditor» is not switchable (reason «… nur deaktiviert»), the summary counts them; off and delete() are refused (IN_USE), nothing deleted',
    $state->on && !$state->switchable && str_contains($state->reason, 'nur deaktiviert') && str_contains($state->summary, '2 Rechnungen')
    && $off !== null && str_contains($off->getMessage(), '2 Rechnungen') && str_starts_with($del, 'in-use') && $em->getRepository(CreditorProfile::class)->find($telId) !== null,
    json_encode([$state, $del], JSON_UNESCAPED_UNICODE));

// ── «Kreditor übernehmen …» ──
for ($stem = 20000000, $waterUid = null; $waterUid === null; $stem++) { $waterUid = uid((string) $stem); }
$em = $wire();
$i6 = $intakeOf($em)->receive(pdfBytes('six'), 'unbekannt.pdf');
$em = $wire();
$em->getTransaction(CreditorInvoice::class)->run(function () use ($em, $i6, $waterUid): void {
    $inv6 = $em->getRepository(CreditorInvoice::class)->find((int) $i6->getId());
    $inv6->proposeCreditor(['name' => 'Wasserwerk Süd AG', 'city' => 'Bern', 'iban' => 'CH93 0076 2011 6238 5295 7', 'uid' => $waterUid, 'account' => '6400', 'tax_code' => 'VM']);
    $em->persist($inv6);
});
$em = $wire();
$cWater = new Contact(['kind' => 'organisation', 'company' => 'Wasserwerk Süd AG', 'language' => 'de']);
(new ContactService($em))->save($cWater);
$em = $wire();
$v6 = (int) $db->fetchOne('SELECT version FROM creditor_invoice WHERE id = ?', [$i6->getId()]);
$a6 = $invoiceOf($em)->adoptCreditor((int) $i6->getId(), $v6, $em->getRepository(Contact::class)->find((int) $cWater->getId()));
$em = $wire();
$water  = $em->getRepository(CreditorProfile::class)->findByContact((int) $cWater->getId());
$wIbans = $water === null ? [] : $em->getRepository(CreditorIban::class)->forProfile($water);
$l6     = $invoiceOf($em)->linesOf($invoiceOf($em)->find((int) $i6->getId()));
check('M35 «Kreditor übernehmen …»: the contact becomes a creditor (profile opened), the proposed IBAN and UID are added, the invoice names it and takes the IBAN; the empty line takes the proposed account and tax code (judged by the server); the new creditor\'s defaults come from this invoice (plan §5)',
    $water !== null && $water->getUid() === $waterUid && array_map(static fn($i) => $i->getIban(), $wIbans) === ['CH9300762011623852957']
    && $invoiceOf($em)->find((int) $i6->getId())->getCreditor()?->getId() === $water->getId() && $invoiceOf($em)->find((int) $i6->getId())->getIban() === 'CH9300762011623852957'
    && $l6[0]->getAccount() === '6400' && $l6[0]->getTaxCode() === 'VM' && $water->getDefaultAccount() === '6400' && $water->getDefaultsSetAt() !== null);

// ── the list ──
$em   = $wire();
$repo = $em->getRepository(CreditorInvoice::class);
$open = static fn(array $list): array => array_map(static fn(CreditorInvoice $i) => (int) $i->getId(), $list);
$tot  = $repo->totalsPerState();
check('M36 the list search in SQL: the open tab by invoice date (unread first), by creditor name, by number part, by amount; the blocked tab is empty; counts per creditor and per state',
    $repo->countSearch(new \Z77\Module\Creditor\Repositories\CreditorInvoiceSearch()) === 5
    && $open($repo->search(new \Z77\Module\Creditor\Repositories\CreditorInvoiceSearch(creditor: 'neuliefer'), 0, 50)) === [(int) $i1->getId(), (int) $i3->getId()]
    && $open($repo->search(new \Z77\Module\Creditor\Repositories\CreditorInvoiceSearch(number: 'A-'), 0, 50)) !== []
    && $repo->countSearch(new \Z77\Module\Creditor\Repositories\CreditorInvoiceSearch(amount: '50.00')) === 1
    && $repo->countSearch(new \Z77\Module\Creditor\Repositories\CreditorInvoiceSearch(state: CreditorInvoice::STATE_BLOCKED)) === 0
    && $repo->countByProfiles([$telId, $neuId]) === [$telId => 2, $neuId => 2] && ($tot['open']['count'] ?? 0) === 5,
    json_encode([$tot, $repo->countByProfiles([$telId, $neuId])]));

// ── SM, stage 1b: the Kreditorenjournal through a host double ──
echo "SM. Kreditorenjournal — list, upload, window, release, delete (database)\n";
$useJournal = function (array $get, ?array $post = null, bool $fetch = false, ?\Z77\Shared\ValueObjects\UploadedFile $file = null) use ($wire): void {
    $wire();
    \Z77\Core\DI::getInstance()->set('Request', fn() => new class($get, $post, $fetch, $file) {
        public function __construct(private array $get, private ?array $post, private bool $fetch, private ?\Z77\Shared\ValueObjects\UploadedFile $file) {}
        public function getGetParameter(string $p): mixed { return $this->get[$p] ?? null; }
        public function getQueryParameters(): array { return $this->get; }
        public function isPost(): bool { return $this->post !== null; }
        public function getPostParameters(): array { return $this->post ?? []; }
        public function getMode(): \Z77\Core\Http\RequestMode { return $this->fetch ? \Z77\Core\Http\RequestMode::Fetch : \Z77\Core\Http\RequestMode::Page; }
        public function getUploadedFile(string $field): ?\Z77\Shared\ValueObjects\UploadedFile { return $field === 'file' ? $this->file : null; }
    }, true);
};
$journalHost = fn() => new class {
    use CreditorJournalControllerTrait { listAction as public; uploadAction as public; editAction as public; creditorFillAction as public; paymentFillAction as public; releaseAction as public; confirmDeleteAction as public; adoptAction as public; }
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
    private function creditorInvoices(): CreditorInvoiceService { return new CreditorInvoiceService($this->em(), 'Owner'); }
    private function creditorIntake(): CreditorIntakeService { return new CreditorIntakeService($this->em(), null, 'Owner'); }
    private function creditorPdfs(array $documentIds): array
    {
        $out = [];
        foreach ($documentIds as $id) {
            $out[$id] = ['state' => 'doc', 'id' => $id, 'name' => 'rechnung-' . $id . '.pdf', 'mime' => 'application/pdf', 'size' => '1 KB', 'isImage' => false,
                'previewUrl' => '/backend/documents/document/preview?id=' . $id, 'thumbUrl' => null, 'canDelete' => true];
        }
        return $out;
    }
    protected function em() { return \Z77\Core\DI::getUnifiedEntityManager(); }
    protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context + ['csrfToken' => 'tok-session']; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
    protected function json(array $data, int $status = 200): \Z77\Core\Http\Response\JsonResponse { $this->context = $data; return new \Z77\Core\Http\Response\JsonResponse($data, $status); }
    protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
    protected function fetch(): \Z77\Core\Http\Response\FetchResponse { return new \Z77\Core\Http\Response\FetchResponse(); }
    protected function fetchError(string $text): \Z77\Core\Http\Response\FetchResponse { $this->messageService->pushFlash('error', $text); return $this->fetch()->setStatus('error'); }
};

$useJournal([]);
$host = $journalHost();
$host->listAction();
$jList = $render('Backend/CreditorJournalController/listAction', $host->context);
$policy = $host->context['uploadPolicy']->withShape('cell')->attributes();
$jTabs = $render('Backend/CreditorJournalController/toolbar', $host->context);
check('SM10 the journal (page): the open invoices, the help in the toolbar, the upload in the action cell (cell shape, PDF, 20 MB), the tabs in the toolbar; rendered — the drop area outside the region, a row per invoice opening its window, «Vorschlag»/creditor names, the band with count and total',
    count($host->context['invoices']) === 5 && $host->help->has() && ($host->layoutManager->sections['hc1'] ?? []) === ['Backend/CreditorJournalController/act']
    && ($host->layoutManager->sections['hc2'] ?? []) === ['Backend/CreditorJournalController/toolbar']
    && ($policy['data-upload'] ?? '') === 'cell' && ($policy['action'] ?? '') === '/backend/finance/creditor-journal/upload' && ($policy['data-upload-accept'] ?? '') === 'application/pdf' && isset($policy['data-upload-multiple'])
    && str_contains($jTabs, 'Im Journal') && str_contains($jTabs, 'Gesperrt') && str_contains($jTabs, 'Verbuchte Journale')
    && str_contains($jList, 'data-upload-progress') && str_contains($jList, 'data-fetch-region="creditor-journal-find-list"')
    && str_contains($jList, 'data-window-open="/backend/finance/creditor-journal/edit?id=' . $i1->getId() . '"') && str_contains($jList, 'Neulieferant GmbH')
    && str_contains($jList, 'data-journal-pending="5"'), mb_substr(strip_tags($jList), 0, 400));

$tmp = tempnam(sys_get_temp_dir(), 'z77cr');
file_put_contents($tmp, pdfBytes('upload-seven'));
$useJournal([], ['csrf_token' => 'tok-session'], true, new \Z77\Shared\ValueObjects\UploadedFile('Lieferant Nov.pdf', $tmp, filesize($tmp), 'application/pdf'));
$host = $journalHost();
$up   = $host->uploadAction();
$ok   = $host->context;
$useJournal([], ['csrf_token' => 'tok-session'], true, new \Z77\Shared\ValueObjects\UploadedFile('Lieferant Nov.pdf', $tmp, filesize($tmp), 'application/pdf'));
$host = $journalHost();
$host->uploadAction();
$again = $host->context;
$tmpPng = tempnam(sys_get_temp_dir(), 'z77cr');
file_put_contents($tmpPng, $png);
$useJournal([], ['csrf_token' => 'tok-session'], true, new \Z77\Shared\ValueObjects\UploadedFile('rechnung.pdf', $tmpPng, filesize($tmpPng), 'application/pdf'));
$host = $journalHost();
$host->uploadAction();
$notPdf = $host->context;
$useJournal([], ['csrf_token' => 'tok-session'], false, new \Z77\Shared\ValueObjects\UploadedFile('Lieferant Nov.pdf', $tmp, filesize($tmp), 'application/pdf'));
$host = $journalHost();
$page = $host->uploadAction();
check('SM11 the upload (one PDF per request): ok with the list region replaced ([data-creditor-journal]); the same file again → «duplicate» («bereits eingelesen …», shown as identical, not as an error); a PNG named .pdf → error (the upload policy gate on the sniffed type, before the intake sniffs again); as a page: flash + the list',
    $up instanceof \Z77\Core\Http\Response\JsonResponse && ($ok['status'] ?? '') === 'ok' && ($ok['commands'][0]['action'] ?? '') === 'replace-html'
    && ($ok['commands'][0]['target'] ?? '') === '[data-creditor-journal]' && str_contains($ok['commands'][0]['html'] ?? '', 'data-creditor-journal')
    && ($again['status'] ?? '') === 'duplicate' && str_contains($again['message'] ?? '', 'bereits eingelesen') && ($notPdf['status'] ?? '') === 'error'
    && str_contains($notPdf['message'] ?? '', 'nicht erlaubt') && $page instanceof \Z77\Core\Http\Response\RedirectResponse && $host->redirectedTo === '/backend/finance/creditor-journal/list'
    && $count('creditor_invoice') === 6, json_encode([$ok['status'] ?? null, $again, $notPdf], JSON_UNESCAPED_UNICODE));
@unlink($tmp);
@unlink($tmpPng);

$i7 = (int) $db->fetchOne('SELECT MAX(id) FROM creditor_invoice');
$useJournal(['id' => (string) $i7, '_origin' => 'region:creditor-journal-find-list'], null, true);
$host = $journalHost();
$host->editAction();
$win = $render('Backend/CreditorJournalController/edit', $host->context);
check('SM12 the window (GET, fetched): a window with the PDF beside the fields (.be-docview, «PDF öffnen» through the DMS preview), the version, the creditor select with its fill, one line row plus the row template, «— fehlt» marks, «Löschen …», the help attached',
    $host->context['window'] === true && $host->help->has() && ($host->layoutManager->sections['main'] ?? []) === ['Backend/CreditorJournalController/edit']
    && str_contains($win, 'data-window="creditor-invoice-edit"') && str_contains($win, 'class="be-docview"') && str_contains($win, '/backend/documents/document/preview?id=')
    && str_contains($win, 'name="version" value="1"') && str_contains($win, 'data-fill-url="/backend/finance/creditor-journal/creditor-fill"')
    && substr_count($win, 'name="lines[0][account]"') === 1 && str_contains($win, 'name="lines[__i__][account]"') && str_contains($win, '— fehlt') && str_contains($win, 'Löschen …'),
    mb_substr(strip_tags($win), 0, 300));
$base7 = ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-find-list', 'version' => '1', 'creditor' => (string) $telId, 'iban' => '', 'invoice_number' => 'T-7', 'invoice_date' => '2026-11-12',
    'due_date' => '', 'reference' => '', 'message' => '', 'amount' => '80.00', 'payment_method_code' => '', 'credit_account' => '', 'lines' => [['text' => 'Abo', 'account' => '', 'tax' => '', 'amount' => '']]];
$useJournal(['id' => (string) $i7], $base7 + ['op' => 'add'], true);
$host = $journalHost();
$host->editAction();
$added = $host->context['rows'] ?? [];
check('SM13 «+ Zeile» without the script (op=add): the window again with one more line, what was typed kept, NOTHING saved; op=remove:0 drops that row',
    count($added) === 2 && $added[0]['text'] === 'Abo' && (int) $db->fetchOne('SELECT version FROM creditor_invoice WHERE id = ?', [$i7]) === 1
    && (static function () use ($useJournal, $journalHost, $i7, $base7): bool {
        $useJournal(['id' => (string) $i7], ['op' => 'remove:0'] + $base7, true);
        $h = $journalHost();
        $h->editAction();
        return count($h->context['rows'] ?? []) === 1 && ($h->context['rows'][0]['text'] ?? 'x') === '';
    })());
$useJournal(['id' => (string) $i7], $base7, true);
$host  = $journalHost();
$saved = $host->editAction();
$em    = $wire();
$s7    = $invoiceOf($em)->find($i7);
$s7l   = $invoiceOf($em)->linesOf($s7);
check('SM14 the save (fetched): closes the window and reloads the list region; the creditor\'s defaults filled the empty line (6510 · VM) and «Bezahlt mit» (Bank → 1020); the line took the amount → complete',
    $saved instanceof \Z77\Core\Http\Response\FetchResponse && $commands($saved) === ['close-window', 'refresh-region']
    && $s7->getCreditor()?->getId() === $telId && $s7->getCreditAccount() === '1020' && $s7l[0]->getAccount() === '6510' && $s7l[0]->getTaxCode() === 'VM'
    && $s7l[0]->getAmount()?->toDecimal() === '80.00' && $s7->isComplete($s7l) && str_contains(json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE), 'vollständig'),
    json_encode($envelope($saved)));
$useJournal(['id' => (string) $i7], ['version' => '2', 'invoice_date' => 'gestern'] + $base7, true);
$host = $journalHost();
$host->editAction();
$errW = $render('Backend/CreditorJournalController/edit', $host->context);
check('SM15 a field refusal re-renders the window with the field error and what was typed («gestern»), nothing saved',
    str_contains($errW, 'data-z77-field-error') && str_contains($errW, 'Rechnungsdatum: ein gültiges Datum') && str_contains($errW, 'value="gestern"')
    && (int) $db->fetchOne('SELECT version FROM creditor_invoice WHERE id = ?', [$i7]) === 2);
$useJournal(['value' => (string) $telId], null, true);
$host = $journalHost();
$fill = $envelope($host->creditorFillAction())['data']['fill'] ?? [];
$useJournal(['value' => 'bank'], null, true);
$host  = $journalHost();
$pfill = $envelope($host->paymentFillAction())['data']['fill'] ?? [];
check('SM16 the fill helpers answer values only: the creditor\'s single IBAN (in fours), «Bezahlt mit», Haben, the first line\'s account and VAT; the payment method\'s Haben',
    ($fill['iban'] ?? '') === Iban::format($qrIban) && ($fill['payment_method_code'] ?? '') === 'bank' && ($fill['credit_account'] ?? '') === '1020'
    && ($fill['line_account'] ?? '') === '6510' && ($fill['line_tax'] ?? '') === 'VM' && $pfill === ['credit_account' => '1020'], json_encode([$fill, $pfill]));

// a blocked invoice through the window: release, then delete
$em = $wire();
$invoiceOf($em)->update((int) $i3->getId(), 6, $head(['creditor' => (string) $neuId, 'invoice_number' => 'A-9', 'amount' => '107.70']), []);
$em = $wire();
$i8 = $intakeOf($em)->receive(pdfBytes('eight'), 'doppel.pdf');
$em = $wire();
$invoiceOf($em)->update((int) $i8->getId(), 1, $head(['creditor' => (string) $neuId, 'invoice_number' => 'A-9']), []);
$useJournal(['id' => (string) $i8->getId(), '_origin' => 'region:creditor-journal-find-list'], null, true);
$host = $journalHost();
$host->editAction();
$bw = $render('Backend/CreditorJournalController/edit', $host->context);
$useJournal(['view' => 'blocked'], null, true);
$host = $journalHost();
$host->listAction();
$blockedList = $host->context['invoices'];
$useJournal(['id' => (string) $i8->getId()], ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-find-list', 'version' => '2'], true);
$host = $journalHost();
$rel  = $host->releaseAction();
$em   = $wire();
check('SM17 a blocked invoice: the tab «Gesperrt» lists it; its window says why with a link to the other invoice, offers «Kein Doppel — übernehmen» (a submit of the window form to release) and «Verwerfen …»; release answers close + refresh, the invoice is open',
    array_map(static fn($i) => (int) $i->getId(), $blockedList) === [(int) $i8->getId()] && str_contains($bw, 'data-invoice-blocked') && str_contains($bw, 'formaction="/backend/finance/creditor-journal/release?id=' . $i8->getId() . '"')
    && str_contains($bw, 'Verwerfen …') && str_contains($bw, 'data-window-open="/backend/finance/creditor-journal/edit?id=' . $i3->getId() . '"')
    && $commands($rel) === ['close-window', 'refresh-region'] && $invoiceOf($em)->find((int) $i8->getId())->isOpen());
$useJournal(['id' => (string) $i8->getId()], null, true);
$host = $journalHost();
$host->confirmDeleteAction();
$cd = $render('Backend/CreditorJournalController/confirmDelete', $host->context);
$useJournal(['id' => (string) $i8->getId()], ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-find-list', 'version' => '3'], true);
$host = $journalHost();
$del  = $host->confirmDeleteAction();
check('SM18 «Löschen …»: the confirmation says the PDF stays and the file is not read again; the POST deletes with the version shown and answers close + refresh',
    str_contains($cd, 'PDF bleibt') && str_contains($cd, 'name="version" value="3"') && $commands($del) === ['close-window', 'refresh-region']
    && $invoiceOf($wire())->find((int) $i8->getId()) === null && str_contains(json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE), 'Kreditorenrechnungen'));
$useJournal(['id' => '999999'], null, true);
$host = $journalHost();
check('SM19 an unknown id answers with the refusal, not a 500', ($envelope($host->editAction())['status'] ?? '') === 'error');

// the Kreditoren list counts the invoices
$useRequest([]);
$host = $creditorHost();
$host->listAction();
check('SM20 «Kreditoren» counts the invoices per creditor (Rechnungen column)', ($host->context['invoiceCounts'][$telId] ?? 0) === 3 && ($host->context['invoiceCounts'][$neuId] ?? 0) === 2,
    json_encode($host->context['invoiceCounts'] ?? null));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
