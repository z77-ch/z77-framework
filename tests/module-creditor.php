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
 * Stage 1c (payment date, posting run) adds: O18–O19 (the strategy resolved from the invoice's
 * snapshot, a variant not built refused, markPaid / post / immutable after posting, the config
 * registrations), C1–C14 on MariaDB («Bezahlt am» in the window and in bulk with versions and
 * refusals, the run up to a cut-off on the payment date — only paid, complete, unblocked invoices,
 * the entries with and without VAT, the not-liable strict refusal, idempotency and stale versions,
 * immutable after posting, retention 10 years, the open-work answers, delete frees the hash /
 * «Verwerfen» keeps it, the payment-method usage registry) and SM21–SM24 (the selection window, the
 * posting window and its POST, the posted tab with the retention backfill).
 *
 * Stage 2 (recognition, folder job) adds: R1–R13 on MariaDB (real QR-bill PDFs drawn by
 * module-debtor's own payment-part partial and read by pdftotext — a known creditor by its QR-IBAN
 * with its defaults, only EMPTY fields filled, the duplicate by IBAN + reference, a SHARED IBAN
 * (candidates, the hint decides), the letterhead UID, the proposal of an unknown creditor, no slip,
 * no pdftotext on the host, the configured tool; the AI step through a fake transport — off / on /
 * the duplicate by number / a failure; the privacy sentence in the help), J1–J8 (the job
 * `creditor-intake` in a temp folder: registration, folders created, settled PDF read and deleted
 * after the checksum check, ignored files, failed/ with its row, the hash duplicate, crash recovery
 * from .work/, the time budget, a name clash) and SM25–SM26 (the tab «Nicht eingelesen», «Erneut
 * versuchen» / «Entfernen»). Without pdftotext R1–R7 are skipped with a note.
 *
 * The fix of 2026-11-15 (CREDITOR-RECOG-WEB-001 — the recognition runs in the job only) adds W1–W7:
 * the upload stores «Erkennung ausstehend», starts no process and queues the job once; the job
 * recognises it with the PDF read back from the DMS (plus the header labels and the QR message as
 * line text); «Jetzt erkennen» (offered, queues, refused for GET and a posted invoice); the re-run;
 * a PDF that cannot be read back; the AI bound (one AI step per slice, fake transport); «Kreditor
 * übernehmen …» with a proposal without street.
 *
 * The owner's decisions of 2026-11-16 (CREDITOR-ADOPT-DUP-001) add X1–X4: «Kreditor übernehmen …»
 * offers an existing contact of the same name pre-selected and creates a new one only with the
 * explicit confirmation; the adoption carries the creditor over to the same party's invoices (UID,
 * an IBAN of this creditor only, the same name — never a different UID); a creditor without a
 * default account learns it (with its VAT and «Bezahlt mit») from a saved invoice.
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
use Z77\Module\Creditor\Posting\CreditorPostingVariant;
use Z77\Module\Creditor\Posting\DirectPosting;
use Z77\Module\Creditor\Services\CreditorJournalPostingService;
use Z77\Module\Creditor\Close\UnpostedCreditorInvoicesCheck;
use Z77\Module\Creditor\Services\CreditorPaymentMethodUsage;
use Z77\Module\Receipt\Services\PaymentMethodUsage;

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

// ── O, stage 1c: the posting strategy, paid / posted / immutable ──
$pInv = new CreditorInvoice(null, 8, 'Owner', new \DateTimeImmutable('2026-11-15 09:00'));
$cInv = new CreditorInvoice(null, 9, 'Owner', new \DateTimeImmutable('2026-11-15 09:00'), null, 'collective');
$proto = new CreditorJournalPosting('Owner', new \DateTimeImmutable(), 1, $chf('10.00'));
$unpaidPost = caught(fn() => $pInv->post('2026/1', $proto, 'Owner', new \DateTimeImmutable()), \LogicException::class);
$pInv->markPaid(new \DateTimeImmutable('2026-11-10'), 'Owner', new \DateTimeImmutable());
$paidState = [$pInv->isPaid(), $pInv->getPaymentState(), $pInv->getPaidOn()?->format('Y-m-d')];
$pInv->markPaid(null, 'Owner', new \DateTimeImmutable());
$unpaidAgain = [$pInv->isPaid(), $pInv->getPaymentState()];
$pInv->markPaid(new \DateTimeImmutable('2026-11-10'), 'Owner', new \DateTimeImmutable());
$pInv->post('2026/17', $proto, 'Owner', new \DateTimeImmutable());
$noEm = (new \ReflectionClass(\Z77\Persistence\Resolver\UnifiedEntityManager::class))->newInstanceWithoutConstructor();
check('O18 the strategy is resolved from the INVOICE\'s snapshot: direct → DirectPosting (key direct, no invoice posting); a variant not built is refused loudly; without a config the current one is direct; markPaid() sets / clears «bezahlt am» with the payment state; post() refuses an unpaid invoice, then posts — immutable afterwards (correct, markPaid, mark refused)',
    CreditorPostingVariant::strategyFor($pInv, $noEm) instanceof DirectPosting && (new DirectPosting($noEm))->key() === 'direct' && (new DirectPosting($noEm))->invoicePosting($pInv, []) === null
    && caught(fn() => CreditorPostingVariant::strategyFor($cInv, $noEm), \LogicException::class) !== null
    && CreditorPostingVariant::current() === 'direct'
    && $unpaidPost !== null && $paidState === [true, 'paid', '2026-11-10'] && $unpaidAgain === [false, 'unpaid']
    && $pInv->isPosted() && !$pInv->isEditable() && $pInv->getLedgerEntryRef() === '2026/17' && $pInv->getJournalPosting() === $proto
    && caught(fn() => $pInv->markPaid(null, 'Owner', new \DateTimeImmutable()), \LogicException::class) !== null
    && caught(fn() => $pInv->correct(null, null, null, null, null, 'NON', null, '', null, null, null, 'Owner', new \DateTimeImmutable()), \LogicException::class) !== null
    && caught(fn() => $pInv->markDuplicate(1, 'x'), \LogicException::class) !== null,
    json_encode([$paidState, $unpaidAgain]));
check('O19 the config: postingVariant «direct» (read in one place), the open-work check for period-close / ledger-report / vat-return, the payment-method usage registered with module-receipt\'s registry (receipt names no creditor class)',
    ($config['postingVariant'] ?? '') === 'direct' && ($config['openWorkChecks'] ?? []) === ['period-close' => [UnpostedCreditorInvoicesCheck::class], 'ledger-report' => [UnpostedCreditorInvoicesCheck::class], 'vat-return' => [UnpostedCreditorInvoicesCheck::class]]
    && ($config['paymentMethodUsage'] ?? []) === [CreditorPaymentMethodUsage::class] && is_subclass_of(CreditorPaymentMethodUsage::class, \Z77\Module\Receipt\Services\PaymentMethodUsageInterface::class)
    && CreditorPostingVariant::CONFIG_KEY === 'postingVariant');

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
check('S9 the journal fragment: list, live (GET, no CSRF — the live listener only reads), upload, edit, creditor-fill, payment-fill, release, recognize («Jetzt erkennen»), intake-retry, intake-remove (stage 2), confirm-delete, adopt, confirm-paid, confirm-post, post; every POST action #[Csrf]; «Journal verbuchen …» in the action cell, tabs and upload in the toolbar; answers only through the helpers (json for the upload envelope)',
    $jActions === ['listAction', 'liveAction', 'uploadAction', 'editAction', 'creditorFillAction', 'paymentFillAction', 'releaseAction', 'recognizeAction', 'intakeRetryAction', 'intakeRemoveAction', 'confirmDeleteAction', 'adoptAction', 'confirmPaidAction', 'confirmPostAction', 'postAction']
    && !$jAttr('liveAction') && $jAttr('recognizeAction') && $jAttr('intakeRetryAction') && $jAttr('intakeRemoveAction') && $jAttr('uploadAction') && $jAttr('editAction') && $jAttr('releaseAction') && $jAttr('confirmDeleteAction') && $jAttr('adoptAction') && $jAttr('confirmPaidAction') && $jAttr('postAction')
    && str_contains((string) file_get_contents($pkg . '/res/view/templates/Backend/CreditorJournalController/act.tpl.php'), 'Journal verbuchen …')
    && str_contains((string) file_get_contents($pkg . '/res/view/templates/Backend/CreditorJournalController/toolbar.tpl.php'), "partials/upload")
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
check('S10 the helps: list.help (toolbar: true) with the cut-off section and a section per search field, edit.help with a section per field of the window, the guide creditorWorkflow (Bezahlt am, Journal verbuchen, Ist-Prinzip, 10 Jahre, the automatic recognition, the intake folder and the AI with the privacy sentence — stage 2); each opens with an <h3> and a first <p> of ≤ 240 characters; no «ß», «du», no PHP condition in the prose',
    str_contains($jTrait, "'Backend/CreditorJournalController/list.help'") && str_contains($jTrait, 'toolbar: true') && str_contains($jTrait, "'Backend/CreditorJournalController/edit.help'")
    && $jListSec === ['cutoff', 'f_creditor', 'f_number', 'f_date', 'f_amount']
    && $jSections === ['creditor', 'iban', 'invoice_number', 'invoice_date', 'due_date', 'reference', 'message', 'amount', 'payment_method_code', 'credit_account', 'paid_on', 'line_text', 'line_account', 'line_tax', 'line_amount', 'split', 'take_default', 'release', 'recognize', 'adopt']
    && str_contains($jListHelp, '<h3>Kreditorenjournal</h3>') && str_contains($jEditHelp, '<h3>Rechnung im Kreditorenjournal</h3>') && str_contains($guide, '<h3>Lieferantenrechnungen')
    && $firstP($jListHelp) <= 240 && $firstP($jEditHelp) <= 240 && $firstP($guide) <= 240 && !str_contains($prose, 'ß')
    && preg_match('/\bdu\b|\bdeine?\b/u', $jListHelp) === 1 && preg_match('/\bdu\b/u', $jEditHelp) === 1 && preg_match('/\bdu\b/u', $guide) === 1
    && !str_contains($guide, 'Buchen: folgt') && str_contains($guide, 'Bezahlt am …') && str_contains($guide, 'Journal verbuchen') && str_contains($guide, 'Ist-Prinzip') && str_contains($guide, '10 Jahre')
    && str_contains($guide, 'neuen Jahres') && !str_contains($guide, 'Automatische Erkennung: folgt') && str_contains($guide, 'Automatische Erkennung:') && str_contains($guide, 'Eingangsordner') && str_contains($jListHelp, 'Gehört die IBAN <strong>mehreren Kreditoren</strong>') && str_contains($jListHelp, '<strong>Nicht eingelesen</strong>') && !str_contains($prose, 'Tab «Eingang»') && !str_contains($prose, '<strong>Eingang</strong>')
    && str_contains($jListHelp, 'Erkennung ausstehend') && str_contains($jListHelp, 'Jetzt erkennen') && str_contains($jEditHelp, 'Jetzt erkennen') && str_contains($guide, 'Erkennung ausstehend') && str_contains($guide, 'Jetzt erkennen')
    && str_contains($jListHelp, 'Rechnungsnummer') && str_contains($jEditHelp, 'unvollständig') && str_contains($jEditHelp, 'Erkannt: Text') && $noPhp($jListHelp) && $noPhp($jEditHelp) && $noPhp($guide),
    json_encode([$jSections, $jListSec, $firstP($jListHelp), $firstP($jEditHelp), $firstP($guide)]));
$jEdit = (string) @file_get_contents($jDir . '/edit.tpl.php');
$keys  = [];
preg_match_all('/(?:name|data-help-key)="([a-z_]+)"/', $jEdit, $m);
check('S11 the window names its fields as the help does (name / data-help-key), posts the version, edits the lines with core.js «rows» (template, + Zeile and × as submits without the script), the PDF through ReceiptPhoto (MediaField) in a .be-docview — no address of its own, no script',
    array_diff(['creditor', 'iban', 'invoice_number', 'invoice_date', 'due_date', 'reference', 'message', 'amount', 'payment_method_code', 'credit_account', 'paid_on', 'line_text', 'line_account', 'line_tax', 'line_amount', 'split', 'take_default', 'release', 'version'], $m[1]) === []
    && str_contains($jEdit, 'data-rows="lines"') && str_contains($jEdit, 'data-row-template="line"') && str_contains($jEdit, 'name="op" value="add"') && str_contains($jEdit, 'value="remove:')
    && str_contains($jEdit, 'class="be-docview"') && str_contains($jEdit, "\$pdf['previewUrl']") && !str_contains($jEdit, '/backend/documents') && str_contains($jTrait, 'ReceiptPhoto::of(')
    && !str_contains($jEdit, '<script') && !str_contains($jEdit, 'onclick'), json_encode(array_values(array_unique($m[1]))));
$jRoleOf = static fn(string $action): string => \Z77\Shared\Services\AuthService::requiredRole(
    $backendConfig['moduleRole'], $backendConfig['controllers'], 'finance', \Z77\Shared\Libraries\Convention\Naming::toCamelCase('creditor-journal') . 'Controller', \Z77\Shared\Libraries\Convention\Naming::toActionMethod($action));
check('S12 the journal route needs the module role ADMIN for every action (no own rule)',
    array_unique(array_map($jRoleOf, ['list', 'upload', 'edit', 'creditor-fill', 'payment-fill', 'release', 'recognize', 'confirm-delete', 'adopt', 'confirm-paid', 'confirm-post', 'post'])) === [\Z77\Core\Config\AuthRole::ADMIN]);

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
// The creditor migrations are the newest of the set — `migrate prev` runs exactly the newest down().
$pendingCol = static fn(): bool => $db->fetchOne("SHOW COLUMNS FROM creditor_invoice LIKE 'recognition_pending_at'") !== false;
[$codeDown, $outDown] = $run(['command' => 'migrate', 'version' => 'prev']);
$colDown   = $pendingCol();
[$codeDown2, $outDown2] = $run(['command' => 'migrate', 'version' => 'prev']);
$afterDown = $tables();
$current   = (string) $db->fetchOne("SELECT COUNT(*) FROM " . \Z77\Persistence\Doctrine\Console\MigrationsApplication::STORAGE_TABLE . " WHERE version LIKE '%Creditor%'");
[$codeUp, $outUp] = $run(['command' => 'migrate']);
check('M3 the newest migration (2026-11-15, CREDITOR-RECOG-WEB-001) drops only creditor_invoice.recognition_pending_at; the 1b one before it drops its four tables (the 1a tables stay); up() brings both back',
    $codeDown === 0 && !$colDown && $codeDown2 === 0 && $afterDown === ['creditor_iban', 'creditor_profile'] && $current === '1'
    && $codeUp === 0 && $tables() === $allTables && $pendingCol() && str_contains($outUp, '2 migrations executed') && str_contains($outUp, 'Version20261115140000'), trim($outDown . $outDown2 . $outUp));

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
check('M32 «Verwerfen …» of a suspected duplicate (owner 2026-11-15) deletes the invoice with its lines; the PDF stays in the DMS and the intake row stays as «discarded» — the same file uploaded again says «bereits eingelesen … als Doppel verworfen»',
    $deletedDoc === ['documentId' => $docId4, 'freed' => false] && $invoiceOf($em)->find((int) $i4->getId()) === null && (int) $db->fetchOne('SELECT COUNT(*) FROM creditor_invoice_line WHERE invoice_id = ?', [$i4->getId()]) === 0
    && array_filter($docs(), static fn($d) => (int) $d['id'] === $docId4) !== [] && $count('creditor_intake') === 4
    && $db->fetchOne('SELECT state FROM creditor_intake WHERE id = ?', [$b4->getIntake()->getId()]) === 'discarded' && str_contains($redo, 'als Doppel verworfen'), $redo);

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
    use CreditorJournalControllerTrait { listAction as public; liveAction as public; uploadAction as public; editAction as public; creditorFillAction as public; paymentFillAction as public; releaseAction as public; recognizeAction as public; confirmDeleteAction as public; adoptAction as public; confirmPaidAction as public; confirmPostAction as public; postAction as public; intakeRetryAction as public; intakeRemoveAction as public; }
    public array $context = [];
    public array $trashed = [];
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
    private function creditorPosting(): CreditorJournalPostingService { return new CreditorJournalPostingService($this->em(), 'Owner'); }
    private function creditorDeletePdf(int $documentId): void { $this->trashed[] = $documentId; }
    private function creditorQueueRecognition(): void { \Z77\Module\Creditor\Jobs\CreditorIntakeJob::queue($this->em(), 'Owner'); }
    private function creditorIntakeFolder(): \Z77\Module\Creditor\Services\CreditorIntakeFolder
    {
        return new \Z77\Module\Creditor\Services\CreditorIntakeFolder($this->em(), $GLOBALS['creditorIntakeDir'] ?? (ABS_BASE_PATH . '/data/framework/creditor/intake'), 'Owner');
    }

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
    protected function noContent(): \Z77\Core\Http\Response\NoContentResponse { return new \Z77\Core\Http\Response\NoContentResponse(); }
};

$useJournal([]);
$host = $journalHost();
$host->listAction();
$jList = $render('Backend/CreditorJournalController/listAction', $host->context);
$policy = $host->context['uploadPolicy']->withShape('cell')->attributes();
$jTabs = $render('Backend/CreditorJournalController/toolbar', array_diff_key($host->context, ['uploadPolicy' => true]));
$jAct  = $render('Backend/CreditorJournalController/act', $host->context);
check('SM10 the journal (page): the open invoices, the help in the toolbar, «Journal verbuchen …» in the action cell (disabled: nothing paid), the upload policy (cell shape, PDF, 20 MB) and the tabs in the toolbar; rendered — the drop area outside the region, a row per invoice opening its window with its «Bezahlt am» checkbox (id:version), «Vorschlag»/creditor names, the band with count, unpaid and total',
    count($host->context['invoices']) === 5 && $host->help->has() && ($host->layoutManager->sections['hc1'] ?? []) === ['Backend/CreditorJournalController/act']
    && ($host->layoutManager->sections['hc2'] ?? []) === ['Backend/CreditorJournalController/toolbar']
    && ($policy['data-upload'] ?? '') === 'cell' && ($policy['action'] ?? '') === '/backend/finance/creditor-journal/upload' && ($policy['data-upload-accept'] ?? '') === 'application/pdf' && isset($policy['data-upload-multiple'])
    && str_contains($jTabs, 'Im Journal') && str_contains($jTabs, 'Gesperrt') && str_contains($jTabs, 'Verbuchte Journale')
    && str_contains($jList, 'data-upload-progress') && str_contains($jList, 'data-fetch-region="creditor-journal-find-list"')
    && str_contains($jList, 'data-window-open="/backend/finance/creditor-journal/edit?id=' . $i1->getId() . '"') && str_contains($jList, 'Neulieferant GmbH')
    && str_contains($jList, 'data-journal-pending="5"') && str_contains($jList, '5 unbezahlt') && str_contains($jList, 'name="inv[]" form="creditor-paid" value="' . $i1->getId() . ':')
    && str_contains($jList, 'id="creditor-paid" method="get" action="/backend/finance/creditor-journal/confirm-paid" data-window-form')
    && str_contains($jAct, 'Journal verbuchen …') && str_contains($jAct, 'disabled'), mb_substr(strip_tags($jList), 0, 400));

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
$v7 = (int) $db->fetchOne('SELECT version FROM creditor_invoice WHERE id = ?', [$i7]);   // the upload's recognition (stage 2) wrote its record
$useJournal(['id' => (string) $i7, '_origin' => 'region:creditor-journal-find-list'], null, true);
$host = $journalHost();
$host->editAction();
$win = $render('Backend/CreditorJournalController/edit', $host->context);
check('SM12 the window (GET, fetched): a window with the PDF beside the fields (.be-docview, «PDF öffnen» through the DMS preview), the version, the creditor select with its fill, one line row plus the row template, «— fehlt» marks, «Löschen …», the help attached',
    $host->context['window'] === true && $host->help->has() && ($host->layoutManager->sections['main'] ?? []) === ['Backend/CreditorJournalController/edit']
    && str_contains($win, 'data-window="creditor-invoice-edit"') && str_contains($win, 'class="be-docview"') && str_contains($win, '/backend/documents/document/preview?id=')
    && str_contains($win, 'name="version" value="' . $v7 . '"') && str_contains($win, 'data-fill-url="/backend/finance/creditor-journal/creditor-fill"')
    && substr_count($win, 'name="lines[0][account]"') === 1 && str_contains($win, 'name="lines[__i__][account]"') && str_contains($win, '— fehlt') && str_contains($win, 'Löschen …'),
    mb_substr(strip_tags($win), 0, 300));
$base7 = ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-find-list', 'version' => (string) $v7, 'creditor' => (string) $telId, 'iban' => '', 'invoice_number' => 'T-7', 'invoice_date' => '2026-11-12',
    'due_date' => '', 'reference' => '', 'message' => '', 'amount' => '80.00', 'payment_method_code' => '', 'credit_account' => '', 'lines' => [['text' => 'Abo', 'account' => '', 'tax' => '', 'amount' => '']]];
$useJournal(['id' => (string) $i7], $base7 + ['op' => 'add'], true);
$host = $journalHost();
$host->editAction();
$added = $host->context['rows'] ?? [];
check('SM13 «+ Zeile» without the script (op=add): the window again with one more line, what was typed kept, NOTHING saved; op=remove:0 drops that row',
    count($added) === 2 && $added[0]['text'] === 'Abo' && (int) $db->fetchOne('SELECT version FROM creditor_invoice WHERE id = ?', [$i7]) === $v7
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
$useJournal(['id' => (string) $i7], ['version' => (string) ($v7 + 1), 'invoice_date' => 'gestern'] + $base7, true);
$host = $journalHost();
$host->editAction();
$errW = $render('Backend/CreditorJournalController/edit', $host->context);
check('SM15 a field refusal re-renders the window with the field error and what was typed («gestern»), nothing saved',
    str_contains($errW, 'data-z77-field-error') && str_contains($errW, 'Rechnungsdatum: ein gültiges Datum') && str_contains($errW, 'value="gestern"')
    && (int) $db->fetchOne('SELECT version FROM creditor_invoice WHERE id = ?', [$i7]) === $v7 + 1);
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
$again8 = $intakeOf($wire())->receive(pdfBytes('eight'), 'doppel.pdf');
check('SM18 «Löschen …» of an unposted invoice (owner 2026-11-15): the confirmation says it goes completely and the file may come again; the POST deletes with the version shown, moves the PDF to the trash, answers close + refresh; the intake row is gone — the SAME file is read again',
    str_contains($cd, 'ganz gelöscht') && str_contains($cd, 'wieder hochgeladen') && str_contains($cd, 'name="version" value="3"') && $commands($del) === ['close-window', 'refresh-region']
    && $invoiceOf($wire())->find((int) $i8->getId()) === null && $host->trashed === [$i8->getDocumentId()]
    && (int) $db->fetchOne('SELECT COUNT(*) FROM creditor_intake WHERE id = ?', [$i8->getIntake()->getId()]) === 0
    && str_contains(json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE), 'Papierkorb') && $again8->getId() !== null && $again8->getId() !== $i8->getId());
$useJournal(['id' => '999999'], null, true);
$host = $journalHost();
check('SM19 an unknown id answers with the refusal, not a 500', ($envelope($host->editAction())['status'] ?? '') === 'error');

// the Kreditoren list counts the invoices
$useRequest([]);
$host = $creditorHost();
$host->listAction();
check('SM20 «Kreditoren» counts the invoices per creditor (Rechnungen column)', ($host->context['invoiceCounts'][$telId] ?? 0) === 3 && ($host->context['invoiceCounts'][$neuId] ?? 0) === 2,
    json_encode($host->context['invoiceCounts'] ?? null));

// ── M, stage 1c: «Bezahlt am …», «Journal verbuchen …», retention, open work, delete, usage ──
echo "M. Stage 1c — bezahlt am, Journal verbuchen, retention, open work, delete, usage (database)\n";
$em   = $wire();
$year = $now->format('Y');
(new \Z77\Module\Financial\Services\FiscalYearService($em))->open(new \Z77\Module\Financial\Entities\FiscalYear($year, new \DateTimeImmutable($year . '-01-01'), new \DateTimeImmutable($year . '-12-31')));
$kasse = (new PaymentMethodService($em))->find('kasse');   // deactivated by M15 — active again for the bulk «Bezahlt mit»
(new PaymentMethodService($em))->setActive($kasse, true);
$today    = new \DateTimeImmutable('today');
$tomorrow = $today->modify('+1 day');
$earlier  = max(new \DateTimeImmutable($year . '-01-01'), $today->modify('-3 days'));
$ymd      = static fn(\DateTimeImmutable $d): string => $d->format('Y-m-d');
$vOf      = static fn(int $id): int => (int) $db->fetchOne('SELECT version FROM creditor_invoice WHERE id = ?', [$id]);
$rowOf    = static fn(int $id): array => $db->fetchAssociative('SELECT * FROM creditor_invoice WHERE id = ?', [$id]) ?: [];
$posting  = static fn(): CreditorJournalPostingService => new CreditorJournalPostingService($wire(), 'Owner');
$docById  = fn(int $id): array => array_values(array_filter($docs(), static fn($d) => (int) $d['id'] === $id))[0] ?? [];
$newInvoice = function (string $tag, array $values, array $lines) use ($wire, $intakeOf, $invoiceOf, $head): int {
    $received = $intakeOf($wire())->receive(pdfBytes($tag), $tag . '.pdf');
    $invoiceOf($wire())->update((int) $received->getId(), 1, $head($values), $lines);
    return (int) $received->getId();
};
$p1Head  = ['creditor' => (string) $telId, 'invoice_number' => 'P-1', 'invoice_date' => $ymd($earlier), 'amount' => '100.00', 'payment_method_code' => 'bank'];
$p1Lines = [['text' => 'Abo', 'account' => '6510', 'tax' => 'VM', 'amount' => '80.00'], ['text' => 'Porto', 'account' => '6500', 'tax' => '-', 'amount' => '20.00']];
$p1 = $newInvoice('c1-one', $p1Head, $p1Lines);
$p2 = $newInvoice('c1-two', ['creditor' => (string) $neuId, 'invoice_number' => 'P-2', 'amount' => '53.85', 'payment_method_code' => 'bank'], [['text' => '', 'account' => '6500', 'tax' => 'VI', 'amount' => '']]);
$p3 = $newInvoice('c1-three', ['amount' => '40.00', 'payment_method_code' => 'bank'], [['text' => 'ohne Kreditor', 'account' => '6500', 'tax' => '-', 'amount' => '']]);
$p4 = $newInvoice('c1-four', ['creditor' => (string) $telId, 'invoice_number' => 'P-1', 'amount' => '100.00', 'payment_method_code' => 'bank'], [['text' => '', 'account' => '6510', 'tax' => '-', 'amount' => '']]);

// «Bezahlt am» in the window (update(): `paid_on`, '' = unpaid, the key absent = unchanged)
$future = $refusedInvoice(fn() => $invoiceOf($wire())->update($p1, $vOf($p1), $head($p1Head + ['paid_on' => $ymd($tomorrow)]), $p1Lines));
$invoiceOf($wire())->update($p1, $vOf($p1), $head($p1Head + ['paid_on' => $ymd($earlier)]), $p1Lines);
$set1 = [$rowOf($p1)['paid_on'] ?? null, $rowOf($p1)['payment_state'] ?? null];
$invoiceOf($wire())->update($p1, $vOf($p1), $head($p1Head + ['paid_on' => '']), $p1Lines);
$cleared = [$rowOf($p1)['paid_on'] ?? null, $rowOf($p1)['payment_state'] ?? null];
$invoiceOf($wire())->update($p1, $vOf($p1), $head($p1Head + ['paid_on' => $ymd($earlier)]), $p1Lines);
$invoiceOf($wire())->update($p1, $vOf($p1), $head($p1Head + ['message' => 'ohne paid_on']), $p1Lines);
check('C1 «Bezahlt am» per invoice (window → update()): a date in the future refused in German, nothing written; a past date sets it (state paid); emptied = unpaid again (removable while not posted); a save without the key leaves it',
    str_contains($future['paid_on'] ?? '', 'Zukunft') && $set1 === [$ymd($earlier), 'paid'] && $cleared === [null, 'unpaid']
    && ($rowOf($p1)['paid_on'] ?? null) === $ymd($earlier) && ($rowOf($p1)['message'] ?? '') === 'ohne paid_on', json_encode([$future, $set1, $cleared], JSON_UNESCAPED_UNICODE));

// «Bezahlt am …» for several ticked rows (markPaid(): id:version each, optionally «Bezahlt mit»)
$bulkFuture = $refusedInvoice(fn() => $invoiceOf($wire())->markPaid([['id' => $p2, 'version' => $vOf($p2)]], $ymd($tomorrow)));
$bulkNone   = $refusedInvoice(fn() => $invoiceOf($wire())->markPaid([], $ymd($today)));
$bulkMethod = $refusedInvoice(fn() => $invoiceOf($wire())->markPaid([['id' => $p2, 'version' => $vOf($p2)]], $ymd($today), 'nix'));
$p1Before   = $rowOf($p1);
$bulk       = $invoiceOf($wire())->markPaid([['id' => $p2, 'version' => $vOf($p2)], ['id' => $p3, 'version' => $vOf($p3)], ['id' => $p4, 'version' => $vOf($p4)], ['id' => $p1, 'version' => 1]], $ymd($today), 'kasse');
check('C2 «Bezahlt am …» in bulk: the date and «Bezahlt mit» Kasse (Haben 1000) for every ticked row at its version, a blocked one too; a row with a stale version is NOT written and named; a future date, no selection, an unknown payment method refused before anything is written',
    count($bulk['paid']) === 3 && count($bulk['refused']) === 1 && str_contains($bulk['refused'][0], 'Rechnung ' . $p1)
    && ($rowOf($p2)['paid_on'] ?? null) === $ymd($today) && ($rowOf($p2)['payment_method_code'] ?? null) === 'kasse' && ($rowOf($p2)['credit_account'] ?? null) === '1000'
    && ($rowOf($p4)['state'] ?? null) === 'blocked' && ($rowOf($p4)['paid_on'] ?? null) === $ymd($today)
    && $rowOf($p1) == $p1Before && str_contains($bulkFuture['paid_on'] ?? '', 'Zukunft') && isset($bulkNone['selection']) && str_contains($bulkMethod['payment_method_code'] ?? '', 'gibt es nicht'),
    json_encode([$bulk['refused'], $bulkFuture, $bulkNone, $bulkMethod], JSON_UNESCAPED_UNICODE));

// The run blocks: incomplete (no creditor) and blocked (suspected duplicate) invoices in it
$pv       = $posting()->preview($today);
$reasons  = array_column($pv['blocked'], 'reason', 'id');
$refusedRun = $reason(fn() => $posting()->post($today, $pv['seen']));
check('C3 the run up to today = every PAID invoice still in the journal (by payment date); an incomplete one («es fehlt: Kreditor») and a suspected duplicate («gesperrt») block it, named in German; unpaid ones are not in it; post() refuses the whole run (incomplete), nothing in the ledger',
    array_column($pv['seen'], 'id') === [$p1, $p2, $p3, $p4] && !$pv['postable'] && str_contains($reasons[$p3] ?? '', 'es fehlt: Kreditor') && str_contains($reasons[$p4] ?? '', 'gesperrt')
    && $pv['unpaid'] >= 5 && str_starts_with($refusedRun, 'incomplete') && str_contains($refusedRun, 'nichts wurde verbucht') && $count('journal_entry') === 0 && ($rowOf($p1)['state'] ?? '') === 'open',
    json_encode([$pv['blocked'], $refusedRun], JSON_UNESCAPED_UNICODE));

// The cut-off on the payment date: only P-1 (paid earlier) is in the run up to then
$pe       = $posting()->preview($earlier);
$protocol = $posting()->post($earlier, $pe['seen']);
$entries  = $db->fetchAllAssociative("SELECT e.id, CONCAT(y.code, '/', e.number) AS ref, e.entry_date, e.text, e.source_type, e.source_ref, e.idempotency_key FROM journal_entry e JOIN fiscal_year y ON y.id = e.fiscal_year_id ORDER BY e.number");
$linesOf  = fn(int $entryId) => $db->fetchAllAssociative('SELECT a.number, l.debit, l.credit, l.tax_code, l.tax_rate, l.tax_amount FROM journal_line l JOIN account a ON a.id = l.account_id WHERE l.entry_id = ? ORDER BY l.position', [$entryId]);
$e1       = $entries[0] ?? [];
$l1       = $e1 === [] ? [] : $linesOf((int) $e1['id']);
check('C4 a cut-off on the PAYMENT date: up to ' . $ymd($earlier) . ' the run is P-1 alone (the others paid later stay, the blocked ones block nothing there); its preview sums VAT (VM base 74.01, tax 5.99) and «ohne MWST» 20.00 off the same lines',
    array_column($pe['seen'], 'id') === [$p1] && $pe['postable'] && $pe['gross']->toDecimal() === '100.00' && $pe['withoutVat']->toDecimal() === '20.00'
    && count($pe['vat']) === 1 && $pe['vat'][0]['code'] === 'VM' && $pe['vat'][0]['base']->toDecimal() === '74.01' && $pe['vat'][0]['tax']->toDecimal() === '5.99'
    && ($earlier == $today || $pe['later'] === 3), json_encode([$pe['seen'], $pe['blocked'], $pe['later']]));
check('C5 the direct posting: ONE generated entry for the invoice, dated «bezahlt am», source creditor, ref = id, key creditor:{id}, text «Rechnung P-1 · Telekom Nord AG»; per line the net + input VAT (6510 74.01 VM 810 + 5.99 on the input-tax account) and «ohne MWST» 20.00 on 6500, against 1020 100.00 (Bezahlt mit Bank)',
    count($entries) === 1 && $e1['entry_date'] === $ymd($earlier) && $e1['source_type'] === 'creditor' && $e1['source_ref'] === (string) $p1 && $e1['idempotency_key'] === 'creditor:' . $p1
    && $e1['text'] === 'Rechnung P-1 · Telekom Nord AG' && count($l1) === 4
    && $l1[0]['number'] === '6510' && $l1[0]['debit'] === '74.01' && $l1[0]['tax_code'] === 'VM' && (int) $l1[0]['tax_rate'] === 810 && $l1[0]['tax_amount'] === '5.99'
    && !in_array($l1[1]['number'], ['6510', '6500', '1020'], true) && $l1[1]['debit'] === '5.99'
    && $l1[2]['number'] === '6500' && $l1[2]['debit'] === '20.00' && $l1[2]['tax_code'] === null && $l1[3]['number'] === '1020' && $l1[3]['credit'] === '100.00',
    json_encode([$entries, $l1]));
$posted1 = $rowOf($p1);
check('C6 P-1 is posted: state, entry ref, protocol (1 invoice, 100.00, Owner), who / when; the run is idempotent — the same confirmation again is refused (changed), nothing doubled',
    $posted1['state'] === 'posted' && $posted1['ledger_entry_ref'] === $e1['ref'] && (int) $posted1['journal_posting_id'] === $protocol->getId() && $posted1['posted_by'] === 'Owner'
    && $protocol->getInvoiceCount() === 1 && $protocol->getGrossTotal()->toDecimal() === '100.00'
    && str_starts_with($reason(fn() => $posting()->post($earlier, $pe['seen'])), 'changed') && $count('journal_entry') === 1 && $count('creditor_journal_posting') === 1);

// Immutable after posting
$immUpdate = $reason(fn() => $invoiceOf($wire())->update($p1, $vOf($p1), $head($p1Head), $p1Lines));
$immDelete = $reason(fn() => $invoiceOf($wire())->delete($p1, $vOf($p1)));
$immPaid   = $invoiceOf($wire())->markPaid([['id' => $p1, 'version' => $vOf($p1)]], $ymd($today));
check('C7 a posted invoice is immutable: update() and delete() refused (changed), «Bezahlt am …» skips it (named), the row unchanged',
    str_starts_with($immUpdate, 'changed') && str_starts_with($immDelete, 'changed') && $immPaid['paid'] === [] && count($immPaid['refused']) === 1
    && $rowOf($p1) == $posted1);
check('C8 retention (owner answer 8): the posted invoice\'s PDF keeps retention_until = end of the fiscal year of the payment + 10 years; the PDFs of unposted invoices have none',
    ($docById((int) $posted1['document_id'])['retention_until'] ?? null) === ((int) $year + 10) . '-12-31' && ($docById((int) $rowOf($p2)['document_id'])['retention_until'] ?? null) === null,
    json_encode($docById((int) $posted1['document_id'])));

// The open-work answers (financial asks the registry; creditor answers)
$checks  = \Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks::fromModules(\Z77\Core\DI::getModuleManager());
$range   = ['from' => new \DateTimeImmutable($year . '-01-01'), 'to' => new \DateTimeImmutable($year . '-12-31')];
$mine    = static fn(array $findings): array => array_values(array_map(static fn($f) => $f->message, array_filter($findings, static fn($f) => $f->reference === 'creditor-journal')));
$close   = $checks->ask('period-close', $range);
$vatRet  = $checks->ask('vat-return', $range);
$report  = $checks->ask('ledger-report', $range);
check('C9 open work: the period close is BLOCKED per paid, unposted invoice of the year (P-2 named, three in all) and WARNED about the unpaid ones (no liability in the direct variant); the VAT return blocked once; the ledger report warned',
    count($mine($close->blocking())) === 3 && str_contains(implode(' ', $mine($close->blocking())), 'Rechnung P-2, bezahlt am ' . $today->format('d.m.Y'))
    && count($mine($close->warnings())) === 1 && str_contains($mine($close->warnings())[0], 'nicht als Verbindlichkeit')
    && count($mine($vatRet->blocking())) === 1 && str_contains($mine($vatRet->blocking())[0], '3 Rechnungen') && $mine($vatRet->warnings()) === []
    && $mine($report->blocking()) === [] && count($mine($report->warnings())) === 2,
    json_encode([$mine($close->blocking()), $mine($close->warnings()), $mine($vatRet->blocking()), $mine($report->warnings())], JSON_UNESCAPED_UNICODE));

// «Verwerfen …» keeps the hash; the incomplete one completed (the paid date stays — the key is absent)
$discard4 = $invoiceOf($wire())->delete($p4, $vOf($p4));
$again4   = $reason(fn() => $intakeOf($wire())->receive(pdfBytes('c1-four'), 'c1-four.pdf'));
$invoiceOf($wire())->update($p3, $vOf($p3), $head(['creditor' => (string) $telId, 'invoice_number' => 'P-3', 'amount' => '40.00', 'payment_method_code' => 'kasse']), [['text' => 'ohne Kreditor', 'account' => '6500', 'tax' => '-', 'amount' => '']]);
check('C10 «Verwerfen …» of the paid duplicate: gone, its file stays refused («als Doppel verworfen»); P-3 completed keeps «bezahlt am»',
    $discard4['freed'] === false && $rowOf($p4) === [] && str_starts_with($again4, 'duplicate-file') && str_contains($again4, 'als Doppel verworfen')
    && ($rowOf($p3)['paid_on'] ?? null) === $ymd($today), $again4);

// A mandator NOT liable to VAT: the strict mode refuses the run while a line carries a tax code
$em = $wire();
(new \Z77\Module\Mandator\Services\MandatorService($em))->update((new \Z77\Module\Mandator\Services\CurrentMandator($em))->find(), ['liable_to_vat' => false]);
$strict = $posting()->preview($today);
$strictReasons = array_column($strict['blocked'], 'reason', 'id');
$strictPost = $reason(fn() => $posting()->post($today, $strict['seen']));
$em = $wire();
(new \Z77\Module\Mandator\Services\MandatorService($em))->update((new \Z77\Module\Mandator\Services\CurrentMandator($em))->find(), ['liable_to_vat' => true]);
check('C11 a mandator NOT liable to VAT (owner answer 5, strict mode): P-2 with its VI line is refused («nicht MWST-pflichtig — nur «ohne MWST»»), the «ohne MWST» invoice is not; the run posts nothing',
    !$strict['postable'] && array_keys($strictReasons) === [$p2] && str_contains($strictReasons[$p2], 'nicht MWST-pflichtig') && str_contains($strictReasons[$p2], 'MWST')
    && str_starts_with($strictPost, 'not-postable') && $count('journal_entry') === 1, json_encode([$strict['blocked'], $strictPost], JSON_UNESCAPED_UNICODE));

// The second run: P-2 and P-3 (paid today) — then the close is no longer blocked by the creditor journal
$pr2   = $posting()->preview($today);
$prot2 = $posting()->post($today, $pr2['seen']);
$close2 = \Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks::fromModules(\Z77\Core\DI::getModuleManager())->ask('period-close', $range);
$e2     = $db->fetchAssociative('SELECT id, entry_date FROM journal_entry WHERE source_ref = ? AND source_type = ?', [(string) $p2, 'creditor']) ?: [];
$l2     = $e2 === [] ? [] : $linesOf((int) $e2['id']);
check('C12 the second run posts P-2 and P-3 (one entry each, dated today; P-2 at Kasse 1000 with VI); the period close then has no blocking creditor finding, only the unpaid warning',
    array_column($pr2['seen'], 'id') === [$p2, $p3] && $pr2['postable'] && $prot2->getInvoiceCount() === 2 && $prot2->getGrossTotal()->toDecimal() === '93.85'
    && $count('journal_entry') === 3 && ($e2['entry_date'] ?? '') === $ymd($today) && end($l2)['number'] === '1000' && end($l2)['credit'] === '53.85' && $l2[0]['tax_code'] === 'VI'
    && ($rowOf($p3)['state'] ?? '') === 'posted' && $mine($close2->blocking()) === [] && count($mine($close2->warnings())) === 1,
    json_encode([$pr2['seen'], $l2, $mine($close2->blocking())], JSON_UNESCAPED_UNICODE));

// Delete of an unposted invoice frees its hash (owner 2026-11-15)
$p5     = $newInvoice('c1-five', ['amount' => '12.00'], []);
$intake5 = (int) $rowOf($p5)['intake_id'];
$del5   = $invoiceOf($wire())->delete($p5, $vOf($p5));
$again5 = $intakeOf($wire())->receive(pdfBytes('c1-five'), 'c1-five.pdf');
check('C13 «Löschen …» of an unposted invoice removes it COMPLETELY: invoice, lines and intake row — its hash is free, the same PDF is read again as a new invoice',
    $del5['freed'] === true && $rowOf($p5) === [] && (int) $db->fetchOne('SELECT COUNT(*) FROM creditor_intake WHERE id = ?', [$intake5]) === 0
    && (int) $db->fetchOne('SELECT COUNT(*) FROM creditor_invoice_line WHERE invoice_id = ?', [$p5]) === 0 && $again5->getId() !== null && (int) $again5->getId() !== $p5);

// RECEIPT-PM-COUNT-001: the payment methods' usage counts the creditor invoices through the registry
$em    = $wire();
$usage = PaymentMethodUsage::fromModules(\Z77\Core\DI::getModuleManager())->count($em);
$byDb  = array_map('intval', $db->fetchAllKeyValue('SELECT payment_method_code, COUNT(*) FROM creditor_invoice WHERE payment_method_code IS NOT NULL GROUP BY payment_method_code'));
check('C14 RECEIPT-PM-COUNT-001: module-receipt\'s usage count of a payment method includes the creditor invoices (Kasse, Bank) through the paymentMethodUsage registry — without the registry they are not counted',
    ($usage['kasse'] ?? 0) === ($byDb['kasse'] ?? -1) && ($usage['kasse'] ?? 0) >= 2 && ($usage['bank'] ?? 0) === ($byDb['bank'] ?? -1)
    && (new PaymentMethodUsage())->count($em) === array_map('intval', $db->fetchAllKeyValue('SELECT payment_method_code, COUNT(*) FROM receipt WHERE payment_method_code IS NOT NULL GROUP BY payment_method_code')),
    json_encode([$usage, $byDb]));

// ── SM, stage 1c: the selection window, the posting window, the posted tab ──
echo "SM. Kreditorenjournal — Bezahlt am …, Journal verbuchen …, Verbuchte Journale (database)\n";
$p6 = $newInvoice('c1-six', ['creditor' => (string) $telId, 'invoice_number' => 'P-6', 'amount' => '30.00', 'payment_method_code' => 'bank'], [['text' => '', 'account' => '6510', 'tax' => '-', 'amount' => '']]);
$pair6 = $p6 . ':' . $vOf($p6);
$useJournal(['inv' => [$pair6, 'junk'], '_origin' => 'region:creditor-journal-find-list'], null, true);
$host = $journalHost();
$host->confirmPaidAction();
$cp = $render('Backend/CreditorJournalController/confirmPaid', $host->context);
$useJournal([], null, true);
$host = $journalHost();
$host->confirmPaidAction();
$cpEmpty = $render('Backend/CreditorJournalController/confirmPaid', $host->context);
$useJournal([], ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-find-list', 'inv' => [$pair6], 'paid_on' => $ymd($tomorrow), 'payment_method_code' => ''], true);
$host = $journalHost();
$host->confirmPaidAction();
$cpErr = $render('Backend/CreditorJournalController/confirmPaid', $host->context);
$useJournal([], ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-find-list', 'inv' => [$pair6], 'paid_on' => $ymd($today), 'payment_method_code' => ''], true);
$host = $journalHost();
$paidAns = $host->confirmPaidAction();
check('SM21 «Bezahlt am …» window: GET with the ticked pairs (junk dropped) lists the invoice, proposes today, carries the pairs as hidden inv[] and «Als bezahlt markieren»; nothing ticked says so; a future date re-renders with the field error; the POST closes the window, reloads the list region and sets the date',
    str_contains($cp, 'data-window="creditor-invoice-paid"') && str_contains($cp, 'name="inv[]" value="' . $pair6 . '"')
    && str_contains($cp, 'value="' . $ymd($today) . '"') && str_contains($cp, 'Als bezahlt markieren') && str_contains($cp, 'data-paid-invoice="' . $p6 . '"')
    && str_contains($cpEmpty, 'data-paid-empty') && str_contains($cpErr, 'data-z77-field-error') && str_contains($cpErr, 'Zukunft')
    && $paidAns instanceof \Z77\Core\Http\Response\FetchResponse && $commands($paidAns) === ['close-window', 'refresh-region'] && ($rowOf($p6)['paid_on'] ?? null) === $ymd($today)
    && str_contains(json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE), '1 Rechnung bezahlt am'), mb_substr(strip_tags($cpErr), 0, 300));

$useJournal([], null, false);
$host = $journalHost();
$host->listAction();
$actPaid = $render('Backend/CreditorJournalController/act', $host->context);
$useJournal(['_origin' => 'region:creditor-journal-find-list'], null, true);
$host = $journalHost();
$host->confirmPostAction();
$cpost = $render('Backend/CreditorJournalController/confirmPost', $host->context);
$seen6 = $host->context['preview']['seen'] ?? [];
check('SM22 «Journal verbuchen …» in the action cell is a window link once an invoice is paid; the posting window (fetched) shows the run up to today — P-6 with its id:version, the facts, «Jetzt buchen», the cut-off filter',
    str_contains($actPaid, 'data-window-open="/backend/finance/creditor-journal/confirm-post"') && !str_contains($actPaid, 'disabled')
    && array_column($seen6, 'id') === [$p6] && str_contains($cpost, 'data-window="creditor-journal-post"') && str_contains($cpost, 'Kreditorenjournal verbuchen')
    && str_contains($cpost, $p6 . ':' . $vOf($p6)) && str_contains($cpost, 'name="cutoff"') && str_contains($cpost, 'Jetzt buchen'), mb_substr(strip_tags($cpost), 0, 400));
$postBody = ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-find-list', 'cutoff' => $ymd($today), 'inv' => array_map(static fn($p) => $p['id'] . ':' . $p['version'], $seen6)];
$useJournal([], $postBody, true);
$host = $journalHost();
$postAns = $host->postAction();
$postFlash = json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE);
$useJournal([], $postBody, true);
$host = $journalHost();
$postAgain = $host->postAction();
check('SM23 the POST of «Journal verbuchen» (fetched): closes the window, reloads the list region, P-6 posted with the flash; the same POST again is refused with the German sentence and re-opens the window for that cut-off',
    $commands($postAns) === ['close-window', 'refresh-region'] && ($rowOf($p6)['state'] ?? '') === 'posted' && str_contains($postFlash, 'Kreditorenjournal verbucht bis ' . $today->format('d.m.Y') . ': 1 Rechnung')
    && ($envelope($postAgain)['status'] ?? '') === 'error' && $commands($postAgain) === ['open-window'] && str_contains(json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE), 'nichts wurde verbucht'),
    json_encode([$envelope($postAns), $envelope($postAgain)], JSON_UNESCAPED_UNICODE));

// «Verbuchte Journale» — the protocols, and the retention backfill on opening it
$em   = $wire();
$doc1 = $em->getRepository(\Z77\Module\Dms\Entities\Document::class)->find((int) $posted1['document_id']);
$doc1->setRetentionUntil(null);
$em->persist($doc1);
$em->flush();
$useJournal(['view' => 'posted'], null, true);
$host = $journalHost();
$host->listAction();
check('SM24 «Verbuchte Journale»: the three protocols, newest first; opening it heals a posted PDF without its deadline (the retention backfill)',
    count($host->context['protocols']) === 3 && (int) $host->context['protocols'][0]->getId() === (int) $db->fetchOne('SELECT MAX(id) FROM creditor_journal_posting')
    && ($docById((int) $posted1['document_id'])['retention_until'] ?? null) === ((int) $year + 10) . '-12-31');

// ── R, stage 2: recognition after the intake, matching, duplicates, the AI step ──
echo "R. Stage 2 — recognition, matching, proposals, duplicates, AI (database)\n";

/**
 * A supplier's QR-bill PDF whose payment part is drawn by module-debtor's OWN partial
 * (`pdf/qrBill.tpl.php` — stand-ins for its invoice and bill, as creditor-recognition.php does):
 * the text layer the recognition reads. $body lines go on the invoice page above (a letterhead UID,
 * a brand name); they also make the bytes unique.
 *
 * @param array{name: string, street: string, house_no: string, zip: string, city: string} $creditor
 * @param list<string> $body
 */
function qrBillPdf(string $root, string $qrIban, string $qrr, string $amount, array $creditor, array $body): string
{
    $pdf = \Z77\Shared\Pdf\PdfDocument::create('creditor-harness');
    $pdf->addPage();
    $pdf->font('B', 14)->text(20, 30, 'Rechnung');
    $y = 40;
    foreach ($body as $line) {
        $pdf->font('', 10)->text(20, $y, $line);
        $y += 6;
    }
    $bill = new class ($qrIban, $qrr, $creditor) {
        public function __construct(private string $iban, private string $qrr, private array $creditor) {}
        public function creditor(): array { return $this->creditor + ['country' => 'CH']; }
        public function debtor(): ?array { return ['name' => 'Harness AG', 'street' => 'Bahnhofstrasse', 'house_no' => '1', 'zip' => '8000', 'city' => 'Zürich', 'country' => 'CH']; }
        public function formattedAccount(): string { return Iban::format($this->iban); }
        public function formattedReference(): string { return QrReference::format($this->qrr); }
        public function payload(): string { return "SPC\n0200\n1"; }
    };
    $payment = new class {
        public function getReferenceType(): string { return 'QRR'; }
        public function getMessage(): string { return 'Rechnung November'; }
    };
    $document = new class ($payment, $amount) {
        public function __construct(private object $payment, private string $amount) {}
        public function getPayment(): object { return $this->payment; }
        public function getGrossTotal(): Money { return Money::fromDecimal($this->amount, 'CHF'); }
        public function getCurrency(): string { return 'CHF'; }
    };
    (static function (\Z77\Shared\Pdf\PdfDocument $pdf, object $bill, object $document) use ($root): void {
        $top    = null;
        $amount = null;
        include $root . '/packages/module-debtor/res/view/templates/pdf/qrBill.tpl.php';
    })($pdf, $bill, $document);

    return $pdf->output();
}

/** A «scan»: a page without a text layer; $gray makes the bytes unique. */
function scanPdf(int $gray): string
{
    $pdf = \Z77\Shared\Pdf\PdfDocument::create('scan');
    $pdf->addPage();
    $pdf->fillColor($gray)->rect(20, 20, 170, 250, true, false);

    return $pdf->output();
}

$tools = \Z77\Module\Creditor\Recognition\RecognitionTools::probe();
$aiOff = new \Z77\Module\Creditor\Recognition\Ai\AiInvoiceReader(\Z77\Module\Creditor\Recognition\Ai\AiSettings::fromOverrides(null, $base));
$recognizer = static fn(\Z77\Persistence\Resolver\UnifiedEntityManager $em, ?\Z77\Module\Creditor\Recognition\Ai\AiInvoiceReader $ai = null): \Z77\Module\Creditor\Services\CreditorRecognitionService
    => new \Z77\Module\Creditor\Services\CreditorRecognitionService($em, new \Z77\Module\Creditor\Recognition\TextLayerRecognizer($tools), $ai ?? $aiOff, 'Owner');
$received = static function (string $bytes, string $name, ?\Z77\Module\Creditor\Recognition\Ai\AiInvoiceReader $ai = null) use ($wire, $intakeOf, $recognizer): CreditorInvoice {
    $em      = $wire();
    $invoice = $intakeOf($em)->receive($bytes, $name);
    $em      = $wire();

    return $recognizer($em, $ai)->recognize((int) $invoice->getId(), $bytes) ?? $invoice;
};
$fresh = static fn(int $id): ?CreditorInvoice => (new CreditorInvoiceService($wire(), 'Owner'))->find($id);
$lineOf = static fn(CreditorInvoice $i): ?CreditorInvoiceLine => (new CreditorInvoiceService($wire(), 'Owner'))->linesOf($i)[0] ?? null;
$qrr = static fn(int $n): string => QrReference::withCheckDigit(str_pad((string) $n, 26, '0', STR_PAD_LEFT));
$validUid = static function (int $from): string { for ($n = $from; ; $n++) { $u = uid((string) $n); if ($u !== null) { return $u; } } };

// The creditors of stage 2: one with a QR-IBAN and defaults, two sharing a group QR-IBAN (hints
// «Marke Blau» / «Marke Rot»), one known only by its UID.
$ibanHost  = chIban('30808', '000000001001');
$ibanGroup = chIban('30808', '000000002002');
$ibanUid   = chIban('30808', '000000003003');
$ibanNew   = chIban('30808', '000000004004');
$uidKnown  = $validUid(41000001);
$em = $wire();
$cs = new ContactService($em);
$rc = [];
foreach (['host' => 'Hosting Nord AG', 'blau' => 'Telco Blau AG', 'rot' => 'Telco Rot AG', 'uidc' => 'Treuhand Uid AG'] as $k => $company) {
    $c = new Contact(['kind' => 'organisation', 'company' => $company, 'language' => 'de']);
    $cs->save($c);
    $rc[$k] = (int) $c->getId();
}
$openProfile = static function (int $contactId, array $values, array $ibans) use ($wire): int {
    $em = $wire();
    $p  = (new CreditorProfileService($em))->open($em->getRepository(Contact::class)->find($contactId));
    (new CreditorProfileService($em, 'Owner'))->update($p, 1, $values, CreditorProfileService::ibanRows($ibans));
    return (int) $p->getId();
};
$pHost = $openProfile($rc['host'], ['default_account' => '6570', 'default_tax_code' => 'VM', 'default_payment_method_code' => 'bank'], [['iban' => $ibanHost]]);
$pBlau = $openProfile($rc['blau'], [], [['iban' => $ibanGroup, 'hint' => 'Marke Blau']]);
$pRot  = $openProfile($rc['rot'], [], [['iban' => $ibanGroup, 'hint' => 'Marke Rot']]);
$pUid  = $openProfile($rc['uidc'], ['uid' => $uidKnown], []);
$liable = (new CreditorInvoiceService($wire()))->liableToVat();
$muster = ['name' => 'Hosting Nord AG', 'street' => 'Serverweg', 'house_no' => '5', 'zip' => '3000', 'city' => 'Bern'];

$canRead = $tools->canReadText();
if (!$canRead) {
    echo "  note R1–R8: pdftotext not found here ({$tools->reason}) — the text-layer checks are skipped\n";
} else {
    // R1 — a known creditor by its QR-IBAN: everything from the slip, the line on its defaults
    $pdfA = qrBillPdf($root, $ibanHost, $qrr(1001), '1234.50', $muster, ['Hosting November · R1']);
    $r1   = $received($pdfA, 'hosting-nov.pdf');
    $l1   = $lineOf($r1);
    $rec1 = $r1->recognition();
    check('R1 upload of a real QR-bill (module-debtor\'s partial): IBAN, amount, QRR reference and message read off the text layer; the creditor matched by its QR-IBAN; the single line takes the amount and the creditor\'s defaults (6570 · VM), «Bezahlt mit» Bank / 1020; each value recorded as «text», the source «Text», confidence high',
        $r1->getCreditor()?->getId() === $pHost && $r1->getIban() === $ibanHost && $r1->getAmount()?->toDecimal() === '1234.50'
        && $r1->getReferenceType() === 'QRR' && $r1->getReference() === $qrr(1001) && str_contains($r1->getMessage(), 'Rechnung November')
        && $r1->getPaymentMethodCode() === 'bank' && $r1->getCreditAccount() === '1020'
        && $l1?->getAmount()?->toDecimal() === '1234.50' && $l1->getAccount() === '6570' && ($liable ? $l1->getTaxCode() === 'VM' : $l1->isWithoutVat())
        && ($rec1['fields']['iban'] ?? '') === 'text' && ($rec1['fields']['amount'] ?? '') === 'text' && ($rec1['fields']['creditor'] ?? '') === 'text'
        && ($rec1['match'] ?? '') === 'iban' && ($rec1['steps']['text']['state'] ?? '') === 'recognized' && ($rec1['steps']['text']['confidence'] ?? '') === 'high'
        && $r1->recognitionSource() === 'Text' && $r1->isOpen() && $r1->isComplete((new CreditorInvoiceService($wire()))->linesOf($r1)),
        json_encode([$rec1, $r1->getCreditor()?->getId(), $l1?->getAccount()], JSON_UNESCAPED_UNICODE));

    // R2 — only EMPTY fields: the owner typed amount and number first, the recognition runs after
    $pdfB = qrBillPdf($root, $ibanHost, $qrr(1002), '500.00', $muster, ['Hosting Dezember · R2']);
    $em   = $wire();
    $b0   = $intakeOf($em)->receive($pdfB, 'hosting-dez.pdf');
    $em   = $wire();
    $b1   = $invoiceOf($em)->update((int) $b0->getId(), $b0->getVersion(), $head(['amount' => '480.00', 'invoice_number' => 'H-480', 'message' => 'von Hand']), [['text' => 'Hosting', 'account' => '', 'tax' => '', 'amount' => '']]);
    $em   = $wire();
    $r2   = $recognizer($em)->recognize((int) $b1->getId(), $pdfB);
    check('R2 only empty fields are filled: the amount, number and message the owner typed stay (480.00, H-480, «von Hand»); IBAN, reference and the creditor are filled; the owner\'s values are not marked as recognised',
        $r2?->getAmount()?->toDecimal() === '480.00' && $r2->getInvoiceNumber() === 'H-480' && $r2->getMessage() === 'von Hand'
        && $r2->getIban() === $ibanHost && $r2->getReference() === $qrr(1002) && $r2->getCreditor()?->getId() === $pHost
        && !isset($r2->recognition()['fields']['amount']) && !isset($r2->recognition()['fields']['message']) && $lineOf($r2)?->getText() === 'Hosting',
        json_encode($r2?->recognition(), JSON_UNESCAPED_UNICODE));

    // R3 — the duplicate by IBAN + reference (a reminder with the same slip) is created and BLOCKED
    $pdfA2 = qrBillPdf($root, $ibanHost, $qrr(1001), '1234.50', $muster, ['Hosting November · MAHNUNG']);
    $r3    = $received($pdfA2, 'mahnung.pdf');
    check('R3 a reminder carrying the same IBAN and QR reference: created and BLOCKED as a suspected duplicate of R1 (owner answer 4)',
        $r3->isBlocked() && $r3->getDuplicateOf() === (int) $r1->getId() && str_contains($r3->getDuplicateReason(), 'gleiche IBAN und gleiche Referenz'), $r3->getDuplicateReason());

    // R4 — a SHARED IBAN: no pick, the candidates; a hint printed on the invoice decides
    $group = ['name' => 'Telco Gruppe AG', 'street' => 'Antennenweg', 'house_no' => '2', 'zip' => '8050', 'city' => 'Zürich'];
    $r4    = $received(qrBillPdf($root, $ibanGroup, $qrr(2001), '89.90', $group, ['Ihre Rechnung · R4']), 'telco.pdf');
    $cand4 = array_column((array) ($r4->recognition()['candidates'] ?? []), 'id');
    $r4b   = $received(qrBillPdf($root, $ibanGroup, $qrr(2002), '59.90', $group, ['Ihre Rechnung von Marke Rot · R4b']), 'telco-rot.pdf');
    check('R4 a SHARED IBAN (a group collecting for two brands): no creditor is picked, the row lists both candidates («shared»); the same IBAN with the brand «Marke Rot» printed → exactly one link\'s hint found → that creditor («hint»)',
        $r4->getCreditor() === null && ($r4->recognition()['match'] ?? '') === 'shared' && $cand4 === [$pBlau, $pRot] && $r4->getAmount()?->toDecimal() === '89.90'
        && $r4b->getCreditor()?->getId() === $pRot && ($r4b->recognition()['match'] ?? '') === 'hint',
        json_encode([$r4->recognition(), $r4b->recognition()], JSON_UNESCAPED_UNICODE));

    // R5 — no IBAN hit, but the letterhead UID names a creditor
    $r5 = $received(qrBillPdf($root, $ibanUid, $qrr(3001), '300.00', ['name' => 'Treuhand Uid AG', 'street' => 'Zahlenweg', 'house_no' => '9', 'zip' => '6000', 'city' => 'Luzern'], ['UID ' . $uidKnown . ' MWST · R5']), 'treuhand.pdf');
    check('R5 the IBAN is linked to nobody, the UID printed in the letterhead names a creditor → matched by UID',
        $r5->getCreditor()?->getId() === $pUid && ($r5->recognition()['match'] ?? '') === 'uid', json_encode($r5->recognition(), JSON_UNESCAPED_UNICODE));

    // R6 — an unknown creditor: the proposal, «Kreditor übernehmen …» takes it over
    $r6 = $received(qrBillPdf($root, $ibanNew, $qrr(4001), '75.00', ['name' => 'Neu Druck GmbH', 'street' => 'Papierstrasse', 'house_no' => '12a', 'zip' => '4000', 'city' => 'Basel'], ['R6']), 'neu.pdf');
    $prop6 = $r6->proposal();
    check('R6 an unknown creditor: no creditor, the PROPOSAL from the slip (name, street + house no split, postcode, town, CH, IBAN), «Vorschlag» in the list',
        $r6->getCreditor() === null && ($prop6['name'] ?? '') === 'Neu Druck GmbH' && ($prop6['street'] ?? '') === 'Papierstrasse' && ($prop6['house_no'] ?? '') === '12a'
        && ($prop6['zip'] ?? '') === '4000' && ($prop6['city'] ?? '') === 'Basel' && ($prop6['country'] ?? '') === 'CH' && ($prop6['iban'] ?? '') === $ibanNew
        && ($r6->recognition()['match'] ?? '') === 'none' && ($r6->recognition()['fields']['proposal'] ?? '') === 'text', json_encode($prop6, JSON_UNESCAPED_UNICODE));

    // R7 — a plain PDF (text, no slip) and the AI switched off: «nicht erkannt — von Hand erfassen», nothing blocked
    $r7 = $received(pdfBytes('plain-r7'), 'quittung.pdf');
    check('R7 a PDF without a QR payment part, AI off: nothing filled, the note «nicht erkannt — von Hand erfassen», source «von Hand», the row open',
        $r7->getIban() === null && $r7->getAmount() === null && $r7->recognitionSource() === 'von Hand' && $r7->isOpen()
        && in_array('nicht erkannt — von Hand erfassen', (array) ($r7->recognition()['notes'] ?? []), true), json_encode($r7->recognition(), JSON_UNESCAPED_UNICODE));
}

// R8 — a host WITHOUT pdftotext: the note, manual entry, nothing blocked (runs everywhere)
$em   = $wire();
$i8   = $intakeOf($em)->receive(pdfBytes('no-tool-r8'), 'ohne-tool.pdf');
$em   = $wire();
$r8   = (new \Z77\Module\Creditor\Services\CreditorRecognitionService($em, new \Z77\Module\Creditor\Recognition\TextLayerRecognizer(\Z77\Module\Creditor\Recognition\RecognitionTools::detect('z77-no-such-pdftotext')), $aiOff, 'Owner'))->recognize((int) $i8->getId(), pdfBytes('no-tool-r8'));
check('R8 no pdftotext on the host: the row says «Texterkennung auf diesem Server nicht verfügbar», state «unavailable», nothing filled, the invoice stays open for manual entry',
    $r8 !== null && $r8->isOpen() && in_array('Texterkennung auf diesem Server nicht verfügbar', (array) ($r8->recognition()['notes'] ?? []), true)
    && ($r8->recognition()['steps']['text']['state'] ?? '') === 'unavailable' && $r8->recognitionSource() === 'von Hand', json_encode($r8?->recognition(), JSON_UNESCAPED_UNICODE));
check('R9 the configured pdftotext is read in ONE place: absent / «auto» = pdftotext from PATH',
    \Z77\Module\Creditor\Services\CreditorRecognitionService::pdftotext() === 'pdftotext'
    && (require $pkg . '/src/App/Config/creditorConfig.inc.php')['tools']['pdftotext'] === 'auto');

// R10–R12 — the AI step through a fake transport (no network)
@mkdir($base . '/keys', 0775, true);
file_put_contents($base . '/keys/anthropic.key', "sk-ant-TEST-ONLY-not-a-real-key\n");
$aiCalls = [];
$aiReader = static function (bool $on, array $answers) use ($base, &$aiCalls): \Z77\Module\Creditor\Recognition\Ai\AiInvoiceReader {
    $transport = new class ($answers, $aiCalls) implements \Z77\KiBot\Transport {
        public function __construct(private array $queue, private array &$calls) {}
        public function send(string $url, array $headers, string $body, ?callable $onChunk = null): \Z77\KiBot\HttpResult
        {
            $this->calls[] = $url;
            return array_shift($this->queue) ?? new \Z77\KiBot\HttpResult(500, '{"type":"error","error":{"type":"api_error","message":"queue empty"}}');
        }
    };
    return new \Z77\Module\Creditor\Recognition\Ai\AiInvoiceReader(\Z77\Module\Creditor\Recognition\Ai\AiSettings::fromOverrides([
        'paths' => ['keyFile' => 'keys/anthropic.key', 'logDir' => 'logs/kibot'], 'creditorAi' => ['enabled' => $on],
    ], $base), $transport);
};
$aiAnswer = static fn(array $over = []): \Z77\KiBot\HttpResult => new \Z77\KiBot\HttpResult(200, json_encode([
    'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn',
    'content' => [['type' => 'text', 'text' => json_encode($over + [
        'creditor_name' => 'Hosting Nord AG', 'creditor_address_lines' => ['Serverweg 5'], 'creditor_postal_code' => '3000', 'creditor_town' => 'Bern',
        'creditor_country' => 'CH', 'creditor_uid' => null, 'iban' => Iban::format($ibanHost), 'currency' => 'CHF', 'gross_total' => '250.00',
        'vat_lines' => [], 'invoice_number' => 'AI-77', 'invoice_date' => '2026-11-01', 'due_date' => '2026-11-30',
        'reference_type' => 'NON', 'reference' => null, 'line_text' => 'Serverwartung November', 'expense_category' => 'Informatik',
    ])]],
    'usage' => ['input_tokens' => 1000, 'output_tokens' => 100],
]));
$r10 = $received(scanPdf(120), 'scan-aus.pdf', $aiReader(false, [$aiAnswer()]));
check('R10 a scan (no text layer) with the AI switched OFF: no request leaves the server, the note «nicht erkannt — von Hand erfassen», source «von Hand»',
    $aiCalls === [] && ($r10->recognition()['steps']['text']['state'] ?? '') === ($canRead ? 'no_text' : 'unavailable') && $r10->recognitionSource() === 'von Hand'
    && in_array('nicht erkannt — von Hand erfassen', (array) ($r10->recognition()['notes'] ?? []), true), json_encode($r10->recognition(), JSON_UNESCAPED_UNICODE));
$r11 = $received(scanPdf(130), 'scan-ki.pdf', $aiReader(true, [$aiAnswer()]));
$l11 = $lineOf($r11);
check('R11 the same scan with the AI ON (fake transport): ONE request; IBAN, amount, number, dates and line text filled and marked «ai», the creditor matched by the IBAN with its defaults (6570); the category stays words (a note, no account guessed); source «KI»',
    count($aiCalls) === 1 && $r11->getCreditor()?->getId() === $pHost && $r11->getIban() === $ibanHost && $r11->getAmount()?->toDecimal() === '250.00'
    && $r11->getInvoiceNumber() === 'AI-77' && $r11->getInvoiceDate()?->format('Y-m-d') === '2026-11-01' && $r11->getDueDate()?->format('Y-m-d') === '2026-11-30'
    && $l11?->getText() === 'Serverwartung November' && $l11->getAccount() === '6570'
    && ($r11->recognition()['fields']['invoice_number'] ?? '') === 'ai' && ($r11->recognition()['steps']['ai']['state'] ?? '') === 'recognized'
    && $r11->recognitionSource() === 'KI' && str_contains(implode(' ', (array) ($r11->recognition()['notes'] ?? [])), 'Informatik'),
    json_encode([$r11->recognition(), $l11?->getAccount()], JSON_UNESCAPED_UNICODE));
$r12 = $received(scanPdf(140), 'scan-ki-2.pdf', $aiReader(true, [$aiAnswer(['gross_total' => '251.00'])]));
$r13 = $received(scanPdf(150), 'scan-ki-3.pdf', $aiReader(true, [new \Z77\KiBot\HttpResult(500, '{"type":"error","error":{"type":"api_error","message":"boom"}}')]));
check('R12 the AI reads the same creditor + invoice number again (AI-77) → created and BLOCKED as a duplicate of R11 (by number); an AI failure leaves the note «KI-Erkennung fehlgeschlagen — von Hand erfassen», the row open',
    $r12->isBlocked() && $r12->getDuplicateOf() === (int) $r11->getId() && str_contains($r12->getDuplicateReason(), 'Rechnungs-Nr.')
    && $r13->isOpen() && ($r13->recognition()['steps']['ai']['state'] ?? '') === 'failed' && str_contains(implode(' ', (array) ($r13->recognition()['notes'] ?? [])), 'KI-Erkennung fehlgeschlagen'),
    json_encode([$r12->getDuplicateReason(), $r13->recognition()], JSON_UNESCAPED_UNICODE));
check('R13 the help names the AI privacy sentence where the AI is explained (AiInvoiceReader::PRIVACY_NOTE)',
    str_contains((string) file_get_contents($pkg . '/res/view/templates/Backend/CreditorJournalController/list.help.tpl.php'), '<p>' . \Z77\Module\Creditor\Recognition\Ai\AiInvoiceReader::PRIVACY_NOTE . '</p>'));

// ── J, stage 2: the folder job `creditor-intake` end to end in a temp folder ──
echo "J. Stage 2 — the folder job creditor-intake (database + temp folder)\n";
$dir = $base . '/data/framework/creditor/intake';
$GLOBALS['creditorIntakeDir'] = $dir;
$folderOf = static fn(\Z77\Persistence\Resolver\UnifiedEntityManager $em): \Z77\Module\Creditor\Services\CreditorIntakeFolder
    => new \Z77\Module\Creditor\Services\CreditorIntakeFolder($em, $dir, 'cron:creditor-intake');
$jobCfg = (require $pkg . '/src/App/Config/creditorConfig.inc.php')['jobs']['creditor-intake'] ?? [];
check('J1 the job is registered in the module config (ADR-031): key creditor-intake, class, run as cron, schedule every:1m (owner 2026-11-15); the folder in ONE config key (data/framework/creditor/intake)',
    ($jobCfg['class'] ?? '') === \Z77\Module\Creditor\Jobs\CreditorIntakeJob::class && ($jobCfg['defaultSchedule'] ?? '') === 'every:1m'
    && ($jobCfg['runAs'] ?? '') === \Z77\Core\Config\AuthRole::CRON_JOB
    && (require $pkg . '/src/App/Config/creditorConfig.inc.php')['intake']['folder'] === 'data/framework/creditor/intake'
    && \Z77\Module\Creditor\Services\CreditorIntakeFolder::configuredDir() === $base . '/data/framework/creditor/intake');

$rmTree = function (string $d) use (&$rmTree): void { foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) { if (in_array(basename($f), ['.', '..'], true)) { continue; } is_dir($f) && !is_link($f) ? $rmTree($f) : @unlink($f); } @rmdir($d); };
$rmTree($dir);
$now     = time();
$job     = new \Z77\Module\Creditor\Jobs\CreditorIntakeJob($folderOf($wire()), $recognizer($wire()));
$cronUsr = new \Z77\Shared\Auth\AuthUser(['id' => 0, 'user_name' => 'cron:creditor-intake', 'roles' => [\Z77\Core\Config\AuthRole::CRON_JOB], 'realm' => \Z77\Shared\Auth\AuthUser::REALM_CRON]);
$res0    = $job->run(new \Z77\Shared\Jobs\JobContext('creditor-intake', [], null, 1, time() + 50, $cronUsr));
check('J2 the first run CREATES the folder with .work/ and failed/ (the upload script never does) and is done with nothing to read',
    is_dir($dir) && is_dir($dir . '/.work') && is_dir($dir . '/failed') && $res0->isDone() && str_contains($res0->getNote(), '0 eingelesen'), $res0->getNote());

$put = static function (string $name, string $bytes, int $age) use ($dir, $now): void { file_put_contents($dir . '/' . $name, $bytes); touch($dir . '/' . $name, $now - $age); };
$folderPdf = $canRead ? qrBillPdf($root, $ibanHost, $qrr(5001), '42.00', $muster, ['Ordner · J3']) : pdfBytes('folder-j3');
$put('Rechnung Ordner.pdf', $folderPdf, 120);
$put('upload.pdf.part', pdfBytes('part'), 7200);
$put('young.PDF', pdfBytes('young-j3'), 10);
$put('.hidden.pdf', pdfBytes('hidden-j3'), 7200);
$put('notiz.txt', 'hello', 7200);
$put('neu.txt', 'fresh', 120);
$put('kaputt.pdf', 'GIF89a not a pdf at all', 120);
$em = $wire();
(new CreditorIntakeService($em, null, 'Owner'))->receive(pdfBytes('dup-j6'), 'schon-da.pdf');
$put('nochmals.pdf', pdfBytes('dup-j6'), 120);    // the same bytes were read already (hash)
$linked = @symlink($dir . '/notiz.txt', $dir . '/link.pdf') && is_link($dir . '/link.pdf');
$intakesBefore = $count('creditor_intake');
$pass1 = $folderOf($wire())->pass(static fn(): bool => true, $now);
$byName = static fn(string $name) => $db->fetchAssociative('SELECT * FROM creditor_intake WHERE original_name = ? ORDER BY id DESC LIMIT 1', [$name]) ?: [];
$jRow   = $byName('rechnung-ordner.pdf');
$jInv   = $jRow !== [] ? $fresh((int) $db->fetchOne('SELECT id FROM creditor_invoice WHERE intake_id = ?', [$jRow['id']])) : null;
$jDoc   = $jRow !== [] ? $docById((int) $jRow['document_id']) : [];
$jPendingAfterPass = $jInv !== null && $jInv->isRecognitionPending() && $jInv->recognition() === [];
$jRec   = $jInv !== null ? $recognizer($wire())->recognizePending(static fn(): bool => true) : [];
$jInv   = $jInv !== null ? $fresh((int) $jInv->getId()) : null;
check('J3 a settled PDF is claimed, received (channel folder) «Erkennung ausstehend», and DELETED once the DMS holds the same bytes (checksum = SHA-256); the folder pass itself recognises nothing — the same pending step as an upload, recognised from the PDF read back from the DMS',
    !file_exists($dir . '/Rechnung Ordner.pdf') && !file_exists($dir . '/.work/Rechnung Ordner.pdf') && ($jRow['channel'] ?? '') === 'folder'
    && ($jDoc['checksum'] ?? '') === hash('sha256', $folderPdf) && $jPendingAfterPass && $jInv !== null && $jInv->recognition() !== [] && !$jInv->isRecognitionPending()
    && (!$canRead || ($jInv->getCreditor()?->getId() === $pHost && $jInv->getAmount()?->toDecimal() === '42.00')) && $pass1['received'] === 1,
    json_encode([$pass1, $jRow, $jRec], JSON_UNESCAPED_UNICODE));
check('J4 ignored: *.part, a file younger than 60 s, a dotfile' . ($linked ? ', a symlink' : '') . ', a non-PDF younger than 1 h — all still in place, no row',
    file_exists($dir . '/upload.pdf.part') && file_exists($dir . '/young.PDF') && file_exists($dir . '/.hidden.pdf') && file_exists($dir . '/neu.txt')
    && (!$linked || is_link($dir . '/link.pdf')) && $pass1['waiting'] >= 2, json_encode($pass1, JSON_UNESCAPED_UNICODE));
$failedRows = $db->fetchAllAssociative("SELECT original_name, reason, failed_name, sha256, document_id FROM creditor_intake WHERE state = 'failed' ORDER BY id");
check('J5 refused files go to failed/ with an intake row «failed» carrying the reason (no hash, no document): a non-PDF older than 1 h («kein PDF»), bytes that are no PDF (the intake\'s sniff)',
    is_file($dir . '/failed/notiz.txt') && is_file($dir . '/failed/kaputt.pdf') && !file_exists($dir . '/notiz.txt') && !file_exists($dir . '/kaputt.pdf')
    && array_column($failedRows, 'reason', 'original_name') == ['notiz.txt' => 'kein PDF', 'kaputt.pdf' => 'Kein PDF — nur PDF-Rechnungen werden eingelesen.']
    && array_column($failedRows, 'sha256') === [null, null] && array_column($failedRows, 'document_id') === [null, null] && $pass1['failed'] === 2,
    json_encode($failedRows, JSON_UNESCAPED_UNICODE));
check('J6 the identical file read before (hash): deleted from the folder, noted «bereits eingelesen», NO new row',
    !file_exists($dir . '/nochmals.pdf') && $pass1['duplicates'] === 1 && str_contains(implode(' ', $pass1['notes']), 'bereits eingelesen')
    && $count('creditor_intake') === $intakesBefore + 3, json_encode($pass1['notes'], JSON_UNESCAPED_UNICODE));

// crash recovery: a file left in .work/ before receive(), and one whose receive() committed but whose delete did not happen
file_put_contents($dir . '/.work/abgebrochen.pdf', pdfBytes('crash-before-j7'));
$crashBytes = pdfBytes('crash-after-j7');
$em = $wire();
$crashInv = (new CreditorIntakeService($em, null, 'cron:creditor-intake'))->receive($crashBytes, 'nach-commit.pdf', CreditorIntake::CHANNEL_FOLDER);
file_put_contents($dir . '/.work/nach-commit.pdf', $crashBytes);
$pass2 = $folderOf($wire())->pass(static fn(): bool => true, $now);
check('J7 crash recovery from .work/: a file claimed but never read is read now; a file whose intake committed before the crash is verified against the DMS and deleted — its invoice\'s pending mark was committed with it, so no recognition is lost — .work/ is empty',
    glob($dir . '/.work/*') === [] && $byName('abgebrochen.pdf') !== [] && $pass2['received'] === 1 && $pass2['duplicates'] === 1
    && ($fresh((int) $crashInv->getId())?->isRecognitionPending() ?? false), json_encode($pass2, JSON_UNESCAPED_UNICODE));
$put('spaet.pdf', pdfBytes('late-j8'), 120);
$pass3 = $folderOf($wire())->pass(static fn(): bool => false, $now);
$untouched = file_exists($dir . '/spaet.pdf') && $byName('spaet.pdf') === [];
$put('notiz.txt', 'again', 7200);
$pass4 = $folderOf($wire())->pass(static fn(): bool => true, $now);
check('J8 no time left: the pass stops before claiming (more = true, the file untouched); the next pass reads it; a name already in failed/ gets « (2)»',
    $pass3['more'] && $untouched && !file_exists($dir . '/spaet.pdf') && $byName('spaet.pdf') !== [] && is_file($dir . '/failed/notiz (2).txt'),
    json_encode([$pass3, $pass4], JSON_UNESCAPED_UNICODE));

// ── SM, stage 2: the tab «Eingang» and its actions ──
$useJournal(['view' => 'intake'], null, true);
$host = $journalHost();
$host->listAction();
$inTab  = $render('Backend/CreditorJournalController/_journal', $host->context);
$inBar  = $render('Backend/CreditorJournalController/toolbar', array_diff_key($host->context, ['uploadPolicy' => true]));
$failedIds = array_map(static fn($i) => (int) $i->getId(), $host->context['failedRows'] ?? []);
$useJournal([], null, true);
$host = $journalHost();
$host->listAction();
$openTab = $render('Backend/CreditorJournalController/_journal', $host->context);
check('SM25 tab «Nicht eingelesen»: the failed files with their reason and «Erneut versuchen» / «Entfernen» (data-fetch-post); the toolbar names «Nicht eingelesen (3)» (owner 2026-11-15, was «Eingang»); the other tabs show the band «3 Dateien nicht eingelesen»; the column «Erkannt» shows the source',
    count($failedIds) === 3 && str_contains($inTab, 'data-intake-row="' . $failedIds[0] . '"') && str_contains($inTab, 'kein PDF')
    && str_contains($inTab, 'data-fetch-post="/backend/finance/creditor-journal/intake-retry"') && str_contains($inTab, 'data-fetch-post="/backend/finance/creditor-journal/intake-remove"')
    && str_contains($inBar, 'Nicht eingelesen (3)') && !str_contains($inBar, '>Eingang') && str_contains($openTab, 'data-intake-failed="3"') && str_contains($openTab, '3 Dateien nicht eingelesen')
    && str_contains($openTab, 'data-recognition='), mb_substr(strip_tags($inTab), 0, 400));
$useJournal([], ['id' => (string) $failedIds[0], 'csrf_token' => 'tok-session'], true);
$host  = $journalHost();
$retry = $host->intakeRetryAction();
$useJournal([], ['id' => (string) $failedIds[1], 'csrf_token' => 'tok-session'], true);
$host2  = $journalHost();
$remove = $host2->intakeRemoveAction();
$useJournal([], ['id' => (string) $failedIds[1], 'csrf_token' => 'tok-session'], true);
$again = $journalHost()->intakeRemoveAction();
check('SM26 «Erneut versuchen» moves the file back into the folder and removes its row; «Entfernen» deletes the file in failed/ and its row; both reload the region «Nicht eingelesen»; a second «Entfernen» of the same row is refused',
    is_file($dir . '/kaputt.pdf') && !is_file($dir . '/failed/kaputt.pdf') && !is_file($dir . '/failed/notiz.txt')
    && (int) $db->fetchOne('SELECT COUNT(*) FROM creditor_intake WHERE id IN (?, ?)', [$failedIds[0], $failedIds[1]]) === 0
    && $commands($retry) === ['refresh-region'] && $commands($remove) === ['refresh-region'] && ($envelope($again)['status'] ?? '') === 'error',
    json_encode([$envelope($retry), $envelope($remove), $envelope($again)], JSON_UNESCAPED_UNICODE));
// ── W, the fix of 2026-11-15: the recognition runs in the job only (CREDITOR-RECOG-WEB-001) ──
echo "W. Recognition in the job only — upload pending, the job, «Jetzt erkennen», the AI bound, an incomplete proposal (database)\n";
$jobs   = static fn() => new \Z77\Shared\Jobs\JobQueue($wire());
$queued = static fn(): int => count(array_filter($jobs()->all(), static fn($r) => $r->getJobKey() === \Z77\Module\Creditor\Jobs\CreditorIntakeJob::KEY && $r->getState() === \Z77\Shared\Entities\JobRun::STATE_QUEUED));
$clearQueue = static function () use ($jobs): void {
    $queue = $jobs();
    foreach ($queue->all() as $run) {
        if ($run->getJobKey() === \Z77\Module\Creditor\Jobs\CreditorIntakeJob::KEY) {
            $queue->delete($run);
        }
    }
};
$recognizer($wire())->recognizePending(static fn(): bool => true);   // nothing of the sections above waits
$clearQueue();
$upload = static function (string $bytes, string $name) use ($useJournal, $journalHost): array {
    $tmp = tempnam(sys_get_temp_dir(), 'z77cr');
    file_put_contents($tmp, $bytes);
    $useJournal([], ['csrf_token' => 'tok-session'], true, new \Z77\Shared\ValueObjects\UploadedFile($name, $tmp, filesize($tmp), 'application/pdf'));
    $host = $journalHost();
    $host->uploadAction();
    @unlink($tmp);

    return $host->context;
};
$idOfIntake = static fn(string $slug): int => (int) $db->fetchOne('SELECT i.id FROM creditor_invoice i JOIN creditor_intake k ON k.id = i.intake_id WHERE k.original_name = ?', [$slug]);
// Every process the recognition starts goes through RecognitionTools::probe() first (pdftotext -v,
// cached per process) — an empty cache after the uploads proves that no process was started.
$probed = new \ReflectionProperty(\Z77\Module\Creditor\Recognition\RecognitionTools::class, 'probed');
$probed->setValue(null, []);
$w1Pdf = $canRead
    ? qrBillPdf($root, $ibanHost, $qrr(7001), '25.55', $muster, ['Rechnungsnummer 416070524', 'Rechnungsdatum 31.01.2026', 'Zahlbar bis 28.02.2026', 'Kundennummer 99887766'])
    : pdfBytes('w1-upload');
$a1 = $upload($w1Pdf, 'Hosting W1.pdf');
$a2 = $upload(pdfBytes('w1-second'), 'Zweite W1.pdf');
$w1 = $fresh($idOfIntake('hosting-w1.pdf'));
$jTraitNow = (string) file_get_contents($pkg . '/src/Ui/CreditorJournalControllerTrait.php');
check('W1 the UPLOAD stores the invoice and its PDF but does NOT recognise: «im Journal — Erkennung ausstehend», the row pending with no record; no process started (the probe cache stayed empty) and the screen names no recognition class; two uploads queue the job `creditor-intake` ONCE (an entry waits)',
    ($a1['status'] ?? '') === 'ok' && str_contains((string) ($a1['message'] ?? ''), 'Erkennung ausstehend') && ($a2['status'] ?? '') === 'ok'
    && $w1 !== null && $w1->isRecognitionPending() && $w1->recognition() === [] && $probed->getValue() === []
    && !str_contains($jTraitNow, '->recognize(') && !str_contains($jTraitNow, 'RecognitionTools') && !str_contains($jTraitNow, 'ToolRunner') && !str_contains($jTraitNow, 'CreditorRecognitionService')
    && $queued() === 1 && str_contains((string) ($a1['commands'][0]['html'] ?? ''), 'data-recognition="pending"'),
    json_encode([$a1['message'] ?? null, $w1?->isRecognitionPending(), $queued(), array_keys($probed->getValue())], JSON_UNESCAPED_UNICODE));

$cronW = new \Z77\Shared\Auth\AuthUser(['id' => 0, 'user_name' => 'cron:creditor-intake', 'roles' => [\Z77\Core\Config\AuthRole::CRON_JOB], 'realm' => \Z77\Shared\Auth\AuthUser::REALM_CRON]);
$wJob  = (new \Z77\Module\Creditor\Jobs\CreditorIntakeJob($folderOf($wire()), $recognizer($wire())))->run(new \Z77\Shared\Jobs\JobContext('creditor-intake', [], null, 1, time() + 50, $cronW));
$w1    = $fresh((int) $w1->getId());
$w1Rec = $w1?->recognition() ?? [];
$w1Pdf2 = (new CreditorIntakeService($wire(), null, 'Owner'))->storedPdf((int) $w1?->getDocumentId());
check('W2 the JOB recognises the pending upload with the PDF read back from the DMS (ModuleDrive::read — the same bytes): the mark is cleared, the note counts it' . ($canRead ? '; the slip fills creditor and amount, the header LABELS give number 416070524 (not the Kundennummer), invoice date 31.01.2026 and due date 28.02.2026 marked «text-label», the QR message «Rechnung November» becomes the line text' : ' (no pdftotext here: «unavailable», named with the probing PHP)'),
    $wJob->isDone() && str_contains($wJob->getNote(), 'erkannt') && $w1 !== null && !$w1->isRecognitionPending() && isset($w1Rec['steps']['text'])
    && $w1Pdf2 !== null && hash('sha256', $w1Pdf2) === hash('sha256', $w1Pdf)
    && (!$canRead || ($w1->getCreditor()?->getId() === $pHost && $w1->getAmount()?->toDecimal() === '25.55' && $w1->getInvoiceNumber() === '416070524'
        && $w1->getInvoiceDate()?->format('Y-m-d') === '2026-01-31' && $w1->getDueDate()?->format('Y-m-d') === '2026-02-28'
        && ($w1Rec['fields']['invoice_number'] ?? '') === 'text-label' && ($w1Rec['fields']['due_date'] ?? '') === 'text-label' && $w1->recognitionSource() === 'Text'
        && $lineOf($w1)?->getText() === 'Rechnung November' && ($w1Rec['fields']['line_text'] ?? '') === 'text'))
    && ($canRead || str_contains((string) ($w1Rec['steps']['text']['reason'] ?? ''), 'geprüft mit PHP-CLI')),
    json_encode([$wJob->getNote(), $w1Rec, $w1?->getInvoiceNumber(), $lineOf($w1)?->getText()], JSON_UNESCAPED_UNICODE));

// «Jetzt erkennen» on a row whose recognition could not run (no tool — the live case)
$em  = $wire();
$w3  = $intakeOf($em)->receive(pdfBytes('w3-no-tool'), 'ohne-tool-w3.pdf');
(new \Z77\Module\Creditor\Services\CreditorRecognitionService($wire(), new \Z77\Module\Creditor\Recognition\TextLayerRecognizer(\Z77\Module\Creditor\Recognition\RecognitionTools::detect('z77-no-such-pdftotext')), $aiOff, 'Owner'))->recognize((int) $w3->getId(), pdfBytes('w3-no-tool'));
$w3  = $fresh((int) $w3->getId());
$useJournal(['id' => (string) $w3->getId(), '_origin' => 'region:creditor-journal-find-list'], null, true);
$host = $journalHost();
$host->editAction();
$w3Win = $render('Backend/CreditorJournalController/edit', $host->context);
$clearQueue();
$useJournal(['id' => (string) $w3->getId(), '_origin' => 'region:creditor-journal-find-list'], ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-find-list'], true);
$w3Ans = $journalHost()->recognizeAction();
$w3Pending = $fresh((int) $w3->getId())?->isRecognitionPending() ?? false;
$w3Queued  = $queued();
$useJournal(['id' => (string) $w3->getId()], null, true);
$w3Get = $journalHost()->recognizeAction();
$postedId = (int) $db->fetchOne("SELECT id FROM creditor_invoice WHERE state = 'posted' ORDER BY id LIMIT 1");
$useJournal(['id' => (string) $postedId], ['csrf_token' => 'tok-session'], true);
$w3Posted = $journalHost()->recognizeAction();
check('W3 «Jetzt erkennen»: offered in the window of a row whose text step was «unavailable»; its POST marks the row pending again and queues the job (closes the window, reloads the list); a GET and a POSTED invoice are refused by the server',
    $w3->wantsRecognition() && str_contains($w3Win, '/recognize?id=' . $w3->getId()) && str_contains($w3Win, 'Jetzt erkennen')
    && $commands($w3Ans) === ['close-window', 'refresh-region'] && $w3Pending && $w3Queued === 1
    && ($envelope($w3Get)['status'] ?? '') === 'error' && $postedId > 0 && ($envelope($w3Posted)['status'] ?? '') === 'error' && $fresh($postedId)?->isRecognitionPending() === false,
    json_encode([$envelope($w3Ans), $w3Pending, $w3Queued, $envelope($w3Posted)], JSON_UNESCAPED_UNICODE));
$recognizer($wire())->recognizePending(static fn(): bool => true);
$w3 = $fresh((int) $w3->getId());
check('W4 the job re-runs it: the mark is cleared and the previous run\'s notes go (re-running only fills empty fields)' . ($canRead ? ' — the «nicht verfügbar» note of the web run is gone' : ''),
    $w3 !== null && !$w3->isRecognitionPending() && (!$canRead || !in_array('Texterkennung auf diesem Server nicht verfügbar', (array) ($w3->recognition()['notes'] ?? []), true)),
    json_encode($w3?->recognition(), JSON_UNESCAPED_UNICODE));
$invoiceOf($wire())->queueRecognition((int) $w3->getId());
$noPdf = $recognizer($wire())->recognizePending(static fn(): bool => true, static fn(int $documentId): ?string => null);
$w3    = $fresh((int) $w3->getId());
check('W5 a PDF the job cannot read back ends the run with a note «PDF in den Dokumenten nicht lesbar …» (step run = failed): the mark goes, so it never loops; «Jetzt erkennen» stays offered',
    $noPdf['failed'] === 1 && $noPdf['recognized'] === 0 && $w3 !== null && !$w3->isRecognitionPending() && $w3->wantsRecognition()
    && in_array(\Z77\Module\Creditor\Services\CreditorRecognitionService::NOTE_NO_PDF, (array) ($w3->recognition()['notes'] ?? []), true)
    && ($w3->recognition()['steps']['run']['state'] ?? '') === 'failed', json_encode([$noPdf, $w3?->recognition()], JSON_UNESCAPED_UNICODE));

// the AI bound: at most one AI step per slice (it may take 120 s)
$em = $wire();
$s1 = $intakeOf($em)->receive(scanPdf(160), 'scan-w6a.pdf');
$s2 = $intakeOf($wire())->receive(scanPdf(170), 'scan-w6b.pdf');
$aiCalls = [];
$aiTwo   = $aiReader(true, [$aiAnswer(['invoice_number' => 'AI-W6A', 'gross_total' => '61.00']), $aiAnswer(['invoice_number' => 'AI-W6B', 'gross_total' => '62.00'])]);
$slice1  = $recognizer($wire(), $aiTwo)->recognizePending(static fn(): bool => true);
$calls1  = count($aiCalls);
$slice2  = $recognizer($wire(), $aiTwo)->recognizePending(static fn(): bool => true);
check('W6 the AI is bounded: one slice runs at most ONE AI step and answers «more» while another invoice waits; the next slice recognises the second (no real API call — the fake transport)',
    $slice1['recognized'] === 1 && $slice1['more'] === true && $calls1 === 1 && $slice2['recognized'] === 1 && $slice2['more'] === false && count($aiCalls) === 2
    && $fresh((int) $s1->getId())?->getInvoiceNumber() === 'AI-W6A' && $fresh((int) $s2->getId())?->getInvoiceNumber() === 'AI-W6B',
    json_encode([$slice1, $slice2, $aiCalls], JSON_UNESCAPED_UNICODE));
$noTime = $recognizer($wire())->recognizePending(static fn(): bool => false);
check('W6 … no time left: nothing waits now, so nothing to do — and the job is done', $noTime === ['recognized' => 0, 'failed' => 0, 'more' => false, 'notes' => []], json_encode($noTime));

// owner 2026-11-15 («solange 30 s übrig sind»): a further AI step starts while ≥ 30 s of the slice are left
$aiFour  = $aiReader(true, [$aiAnswer(['invoice_number' => 'AI-W6C', 'gross_total' => '63.00']), $aiAnswer(['invoice_number' => 'AI-W6D', 'gross_total' => '64.00']),
    $aiAnswer(['invoice_number' => 'AI-W6E', 'gross_total' => '65.00']), $aiAnswer(['invoice_number' => 'AI-W6F', 'gross_total' => '66.00'])]);
$s3 = $intakeOf($wire())->receive(scanPdf(180), 'scan-w6c.pdf');
$s4 = $intakeOf($wire())->receive(scanPdf(190), 'scan-w6d.pdf');
$aiCalls = [];
$plenty  = $recognizer($wire(), $aiFour)->recognizePending(static fn(): bool => true, null, static fn(): int => \Z77\Module\Creditor\Services\CreditorRecognitionService::AI_MIN_SECONDS + 15);
$callsP  = count($aiCalls);
$s5 = $intakeOf($wire())->receive(scanPdf(200), 'scan-w6e.pdf');
$s6 = $intakeOf($wire())->receive(scanPdf(210), 'scan-w6f.pdf');
$scarce  = $recognizer($wire(), $aiFour)->recognizePending(static fn(): bool => true, null, static fn(): int => \Z77\Module\Creditor\Services\CreditorRecognitionService::AI_MIN_SECONDS - 1);
$callsS  = count($aiCalls);
$s6Wait  = $fresh((int) $s6->getId());
$after   = $recognizer($wire(), $aiFour)->recognizePending(static fn(): bool => true, null, static fn(): int => 0);
$jobSrc  = (string) file_get_contents($pkg . '/src/Jobs/CreditorIntakeJob.php');
check('W6b a further AI step starts while ≥ 30 s of the slice are left (`AI_MIN_SECONDS`, `JobContext::getSecondsLeft()` from the job): 45 s left → two AI invoices in ONE slice; 29 s left → the first only, the second untouched (still pending, nothing written) and «more»; the next slice reads it',
    \Z77\Module\Creditor\Services\CreditorRecognitionService::AI_MIN_SECONDS === 30
    && $plenty['recognized'] === 2 && $plenty['more'] === false && $callsP === 2
    && $fresh((int) $s3->getId())?->getInvoiceNumber() === 'AI-W6C' && $fresh((int) $s4->getId())?->getInvoiceNumber() === 'AI-W6D'
    && $scarce['recognized'] === 1 && $scarce['more'] === true && $callsS === 3 && $fresh((int) $s5->getId())?->getInvoiceNumber() === 'AI-W6E'
    && $s6Wait !== null && $s6Wait->isRecognitionPending() && $s6Wait->recognition() === []
    && $after['recognized'] === 1 && count($aiCalls) === 4 && $fresh((int) $s6->getId())?->getInvoiceNumber() === 'AI-W6F'
    && str_contains($jobSrc, '$context->getSecondsLeft()'),
    json_encode([$plenty, $scarce, $after, count($aiCalls)], JSON_UNESCAPED_UNICODE));

// «in Arbeit …» / «KI liest …» and the list that updates itself (owner 2026-11-15, live test: the
// list did not show that the job was still recognising)
$w8   = $intakeOf($wire())->receive(pdfBytes('w8-progress'), 'progress-w8.pdf');
$w8Id = (int) $w8->getId();
$liveBase = \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE . '/live?watch=';
$listNow = static function () use ($useJournal, $journalHost, $render): string {
    $useJournal([]);
    $host = $journalHost();
    $host->listAction();

    return $render('Backend/CreditorJournalController/listAction', $host->context);
};
$live = static function (array $get) use ($useJournal, $journalHost) {
    $useJournal($get, null, true);

    return $journalHost()->liveAction();
};
$cellOf = static fn(string $html, int $id): string => preg_match('/data-creditor-invoice="' . $id . '".*?<\/div>\s*<\/div>/s', $html, $m) ? $m[0] : '';
$w8List = $listNow();
$w8Cell = $cellOf($w8List, $w8Id);
$w8Same = $live(['watch' => $w8Id . '.pending', 't' => (string) time()]);
check('W8 a pending row shows the busy pill «in Arbeit …» (muted, `badge--busy`) in «Erkannt»; the region header carries the LIVE LISTENER (`data-fetch-live` = …/live?watch=<id>.pending, every 15 s); asked while nothing changed, `live` answers 204',
    str_contains($w8Cell, 'data-progress="pending"') && str_contains($w8Cell, 'badge badge--muted badge--busy') && str_contains($w8Cell, 'in Arbeit …') && !str_contains($w8Cell, '>ausstehend<')
    && str_contains($w8List, 'data-creditor-live="list" data-fetch-live="' . $liveBase . $w8Id . '.pending&amp;t=') && str_contains($w8List, 'data-fetch-live-every="15"')
    && $w8Same instanceof \Z77\Core\Http\Response\NoContentResponse,
    mb_substr(strip_tags($w8Cell), 0, 200) . ' | ' . get_class($w8Same));

// the job's AI step under way: the text step written, the mark kept (last: false), no `ai` step yet
$invoiceOf($wire())->applyRecognition($w8Id, \Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_TEXT, ['state' => 'unrecognized'], null, null, 'none', [], [], false);
$w8   = $fresh($w8Id);
$now8 = new \DateTimeImmutable();
$w8Changed = $live(['watch' => $w8Id . '.pending', 't' => (string) time()]);
$w8Window  = $live(['watch' => $w8Id . '.pending', 't' => (string) time(), 'in' => 'window']);
$w8Ai   = $listNow();
$w8Stop = $live(['watch' => $w8Id . '.ai', 't' => (string) (time() - 601)]);
$w8Junk = $live(['watch' => '1;drop', 't' => (string) time()]);
check('W9 «KI liest …» is read off the record, no state of its own: the text step of THIS run without an `ai` step = the AI is reading (only for the AI\'s timeout + margin, then «in Arbeit …» again); the change makes `live` answer `refresh-region` (the list) / `refresh-window` (a window); unchanged for 10 minutes or a broken `watch` → `stop-live`',
    $w8 !== null && $w8->isRecognitionPending() && $w8->recognitionProgress($now8, 180) === CreditorInvoice::PROGRESS_AI
    && $w8->recognitionProgress($now8->modify('+181 seconds'), 180) === CreditorInvoice::PROGRESS_PENDING
    && $commands($w8Changed) === ['refresh-region'] && ($envelope($w8Changed)['commands'][0]['name'] ?? '') === 'creditor-journal-find-list'
    && $commands($w8Window) === ['refresh-window']
    && str_contains($cellOf($w8Ai, $w8Id), 'badge badge--info badge--busy') && str_contains($cellOf($w8Ai, $w8Id), 'KI liest …') && str_contains($w8Ai, $liveBase . $w8Id . '.ai&amp;')
    && $commands($w8Stop) === ['stop-live'] && ($envelope($w8Stop)['commands'][0]['target'] ?? '') === '[data-creditor-live="list"]'
    && $commands($w8Junk) === ['stop-live'],
    json_encode([$w8?->recognition(), $envelope($w8Changed), $envelope($w8Stop)], JSON_UNESCAPED_UNICODE));

$useJournal(['id' => (string) $w8Id, '_origin' => 'region:creditor-journal-find-list'], null, true);
$host = $journalHost();
$host->editAction();
$w8Win = $render('Backend/CreditorJournalController/edit', $host->context);
$useJournal(['id' => (string) $w8Id], ['csrf_token' => 'tok-session', 'version' => (string) $w8->getVersion(), 'op' => 'add'], true);
$host = $journalHost();
$host->editAction();
$w8Post = $render('Backend/CreditorJournalController/edit', $host->context);
check('W10 the window of an invoice the AI is reading: «KI liest …» on top and its own live listener (`in=window`, reloads itself when the job is done); a POST re-render (a refused save, «+ Zeile») carries none — it would drop what was typed',
    str_contains($w8Win, 'data-invoice-recognition-pending="ai"') && str_contains($w8Win, 'KI liest …') && str_contains($w8Win, 'data-creditor-live="invoice:' . $w8Id . '"')
    && str_contains($w8Win, $liveBase . $w8Id . '.ai&amp;t=') && str_contains($w8Win, 'in=window')
    && str_contains($w8Post, 'data-invoice-recognition-pending') && !str_contains($w8Post, 'data-fetch-live'),
    mb_substr(strip_tags($w8Win), 0, 300));

// the AI step done: the mark goes — the next question reloads, and the fresh list asks no more
$invoiceOf($wire())->applyRecognition($w8Id, \Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_AI, ['state' => 'skipped', 'reason' => 'test'], null, null, 'none', [], ['test']);
$w8Done = $live(['watch' => $w8Id . '.ai', 't' => (string) time()]);
$w8Rest = $listNow();
$badges = (string) file_get_contents($root . '/packages/module-backend/res/scss/components/_badges.scss');
$baseCss = (string) file_get_contents($root . '/packages/module-backend/res/assets/css/base.css');
check('W11 the job done: `live` answers `refresh-region`, the fresh list has no row in progress and NO live listener (no attribute, no request); the busy pill is a shared badge modifier (`.badge--busy`, still under reduced motion) compiled into base.css',
    $fresh($w8Id)?->isRecognitionPending() === false && $commands($w8Done) === ['refresh-region']
    && !str_contains($w8Rest, 'data-fetch-live') && !str_contains($w8Rest, 'badge--busy')
    && str_contains($badges, '.badge--busy') && str_contains($badges, 'prefers-reduced-motion') && str_contains($baseCss, '.badge--busy{animation:badge-busy'),
    json_encode($envelope($w8Done), JSON_UNESCAPED_UNICODE));

// «Kreditor übernehmen …» with an incomplete proposal (the Swisscom QR-bill without street)
// A new contact's address link needs the address types (module-contact's seed, as an installation has it).
@mkdir($base . '/data/framework/contact', 0777, true);
copy($packages['Contact'] . '/data/framework/contact/address_types.default.json', $base . '/data/framework/contact/address_types.json');
$em  = $wire();
$w7  = $intakeOf($em)->receive(pdfBytes('w7-swisscom'), 'swisscom.pdf');
$invoiceOf($wire())->applyRecognition((int) $w7->getId(), \Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_TEXT, ['state' => 'recognized'], new \Z77\Module\Creditor\Recognition\InvoiceFacts(
    \Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_TEXT, null, false, null, null, null, null, null, null, null, null, null, null, null,
    ['name' => 'Swisscom (Schweiz) AG', 'zip' => '3050', 'city' => 'Bern', 'country' => 'CH'], 'medium', false, []));
$w7  = $fresh((int) $w7->getId());
$useJournal(['id' => (string) $w7->getId()], null, true);
$host = $journalHost();
$host->adoptAction();
$w7Ctx = $host->context;
$w7Win = $render('Backend/CreditorJournalController/adopt', $w7Ctx);
$newBody = ['csrf_token' => 'tok-session', 'mode' => 'new', 'version' => (string) $w7->getVersion(), 'kind' => 'organisation', 'company' => 'Swisscom (Schweiz) AG', 'language' => 'de',
    'type_code' => 'main', 'address_name' => 'Swisscom (Schweiz) AG', 'address_zip' => '3050', 'address_city' => 'Bern', 'address_country' => 'CH'];
$useJournal(['id' => (string) $w7->getId()], $newBody, true);
$host = $journalHost();
$host->adoptAction();
$w7Err = (string) ($host->context['error'] ?? '');
$useJournal(['id' => (string) $w7->getId()], $newBody + ['address_street' => 'Alte Tiefenaustrasse', 'address_house_no' => '6'], true);
$w7Host = $journalHost();
$w7Ok   = $w7Host->adoptAction();
$w7   = $fresh((int) $w7->getId());
check('W7 «Kreditor übernehmen …» with a proposal WITHOUT street: the window opens on «Neuen Kontakt anlegen», prefilled (company and the address name), the gap named («es fehlt: Strasse») and the field marked; saved without a street the refusal NAMES «Strasse» (not just «markierte Eingaben»); completed, the contact is created and the invoice names the creditor',
    ($w7Ctx['gaps'] ?? null) === ['street' => 'Strasse'] && ($w7Ctx['mode'] ?? '') === 'new' && str_contains($w7Win, 'data-adopt-gaps="street"') && str_contains($w7Win, 'es fehlt: Strasse')
    && str_contains($w7Win, 'name="address_name" value="Swisscom (Schweiz) AG"') && preg_match('/name="address_street"[^>]*aria-invalid="true"|aria-invalid="true"[^>]*name="address_street"/', $w7Win) === 1
    && str_contains($w7Err, 'Strasse') && $w7Ok instanceof \Z77\Core\Http\Response\FetchResponse && ($envelope($w7Ok)['status'] ?? '') === 'success'
    && $w7?->getCreditor()?->getContact()?->displayName() === 'Swisscom (Schweiz) AG',
    json_encode([$w7Ctx['gaps'] ?? null, $w7Ctx['mode'] ?? null, $w7Err, $w7Ok instanceof \Z77\Core\Http\Response\FetchResponse ? $envelope($w7Ok) : ($w7Host->context['error'] ?? get_class($w7Ok))], JSON_UNESCAPED_UNICODE) . ' | ' . mb_substr(strip_tags($w7Win), 0, 300));

// The AI completes the text step's proposal (owner 2026-11-15, cyon: QR slip without street).
$facts = static fn(string $step, array $proposal): \Z77\Module\Creditor\Recognition\InvoiceFacts => new \Z77\Module\Creditor\Recognition\InvoiceFacts(
    $step, null, false, null, null, null, null, null, null, null, null, null, null, null, $proposal, 'medium', false, []);
$w12 = $intakeOf($wire())->receive(pdfBytes('w12-cyon'), 'cyon.pdf');
$invoiceOf($wire())->applyRecognition((int) $w12->getId(), \Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_TEXT, ['state' => 'recognized'],
    $facts(\Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_TEXT, ['name' => 'cyon AG', 'zip' => '4052', 'city' => 'Basel', 'country' => 'CH']), null, 'none', [], [], false);
$invoiceOf($wire())->applyRecognition((int) $w12->getId(), \Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_AI, ['state' => 'recognized'],
    $facts(\Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_AI, ['name' => 'cyon AG', 'street' => 'Brunngässlein', 'house_no' => '12', 'zip' => '4052', 'city' => 'Basel', 'country' => 'CH']));
$w12 = $fresh((int) $w12->getId());
$w13 = $intakeOf($wire())->receive(pdfBytes('w13-other'), 'other.pdf');
$invoiceOf($wire())->applyRecognition((int) $w13->getId(), \Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_TEXT, ['state' => 'recognized'],
    $facts(\Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_TEXT, ['name' => 'cyon AG', 'zip' => '4052', 'city' => 'Basel']), null, 'none', [], [], false);
$invoiceOf($wire())->applyRecognition((int) $w13->getId(), \Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_AI, ['state' => 'recognized'],
    $facts(\Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_AI, ['name' => 'Andere GmbH', 'street' => 'Weg', 'house_no' => '1', 'zip' => '8000', 'city' => 'Zürich']));
$w13 = $fresh((int) $w13->getId());
check('W12 the AI COMPLETES the text step\'s proposal of the same party (cyon: slip without street → the AI\'s street + house no taken, source ai; name, zip, city stay); another party\'s answer changes nothing',
    ($w12?->proposal()['street'] ?? '') === 'Brunngässlein' && ($w12->proposal()['house_no'] ?? '') === '12' && ($w12->proposal()['name'] ?? '') === 'cyon AG'
    && (($w12->recognition()['fields']['proposal_street'] ?? '') === \Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_AI)
    && !isset($w13?->proposal()['street']) && ($w13->proposal()['name'] ?? '') === 'cyon AG',
    json_encode([$w12?->proposal(), $w12?->recognition()['fields'] ?? null, $w13?->proposal()], JSON_UNESCAPED_UNICODE));

// ── X, owner 2026-11-16: «Kreditor übernehmen …» carries over, no duplicate contact, the creditor learns its defaults ──
echo "X. Kreditor übernehmen — carry-over, no duplicate contact; learned defaults (owner 2026-11-16)\n";
for ($stem = 30000000, $dgUid = null; $dgUid === null; $stem++) { $dgUid = uid((string) $stem); }
for ($stem++, $dgOther = null; $dgOther === null; $stem++) { $dgOther = uid((string) $stem); }
$dgIban    = chIban('00762', '011623852999');
$dgPropose = function (string $tag, array $proposal) use ($intakeOf, $wire): int {
    $id = (int) $intakeOf($wire())->receive(pdfBytes($tag), $tag . '.pdf')->getId();
    $em = $wire();
    $em->getTransaction(CreditorInvoice::class)->run(function () use ($em, $id, $proposal): void {
        $inv = $em->getRepository(CreditorInvoice::class)->find($id);
        $inv->proposeCreditor($proposal);
        $em->persist($inv);
    });

    return $id;
};
$dgParty = ['name' => 'Digitec Galaxus AG', 'zip' => '8005', 'city' => 'Zürich', 'country' => 'CH', 'iban' => $dgIban, 'uid' => $dgUid];
$x1 = $dgPropose('x1-digitec', $dgParty + ['account' => '6500', 'tax_code' => 'VM']);   // no street → gaps
$x2 = $dgPropose('x2-digitec', $dgParty);                                                 // same UID + IBAN
$x3 = $dgPropose('x3-digitec-other-uid', ['name' => 'Digitec Galaxus AG', 'zip' => '8005', 'city' => 'Zürich', 'uid' => $dgOther]);   // conflicting UID
$x4 = $dgPropose('x4-other', ['name' => 'Galaxus Fremd AG', 'zip' => '8005', 'city' => 'Zürich']);   // another party
$x5 = $dgPropose('x5-digitec-name', ['name' => '  digitec   GALAXUS ag ', 'zip' => '8005', 'city' => 'Zürich']);   // the same name only
// «Bezahlt mit» on the first invoice — the creditor learns it with the adoption
$invoiceOf($wire())->update($x1, (int) $fresh($x1)->getVersion(), $head(['payment_method_code' => 'bank']), [['text' => '', 'account' => '', 'tax' => '', 'amount' => '']]);
$em = $wire();
$cDg = new Contact(['kind' => 'organisation', 'company' => 'Digitec Galaxus AG', 'language' => 'de']);
(new ContactService($em))->save($cDg);
$dgId = (int) $cDg->getId();

// the window on an incomplete proposal whose contact exists: «existing», pre-selected, named
$useJournal(['id' => (string) $x1], null, true);
$host = $journalHost();
$host->adoptAction();
$x1Ctx = $host->context;
$x1Win = $render('Backend/CreditorJournalController/adopt', $x1Ctx);
// mode=new without the confirmation: nothing created, back on «existing»
$dgNew = ['csrf_token' => 'tok-session', 'mode' => 'new', 'version' => (string) $fresh($x1)->getVersion(), 'kind' => 'organisation', 'company' => 'Digitec Galaxus AG', 'language' => 'de',
    'type_code' => 'main', 'address_name' => 'Digitec Galaxus AG', 'address_street' => 'Pfingstweidstrasse', 'address_house_no' => '60', 'address_zip' => '8005', 'address_city' => 'Zürich', 'address_country' => 'CH'];
$useJournal(['id' => (string) $x1], $dgNew, true);
$host = $journalHost();
$x1Held = $host->adoptAction();
$x1HeldCtx = $host->context;
$x1HeldWin = $render('Backend/CreditorJournalController/adopt', $x1HeldCtx);
$dgCount = static fn(): int => count($wire()->getRepository(Contact::class)->findSameName('Digitec Galaxus AG'));
check('X1 «Kreditor übernehmen …» while a contact of the same name exists: the window opens on «Bestehenden Kontakt verwenden» with it PRE-SELECTED and the message «Digitec Galaxus AG gibt es schon — diesen verwenden?» (even with gaps, no field marked); «Neuen Kontakt anlegen» without the confirmation creates NOTHING and comes back the same way, the switch «Trotzdem einen neuen Kontakt anlegen» offered',
    ($x1Ctx['mode'] ?? '') === 'existing' && ($x1Ctx['selected'] ?? 0) === $dgId && str_contains($x1Win, 'Digitec Galaxus AG gibt es schon — diesen verwenden?')
    && str_contains($x1Win, 'data-adopt-existing="' . $dgId . '"') && str_contains($x1Win, 'value="' . $dgId . '" selected') && !str_contains($x1Win, 'aria-invalid="true"')
    && $x1Held instanceof \Z77\Core\Http\Response\HtmlResponse && ($x1HeldCtx['held'] ?? false) === true && ($x1HeldCtx['mode'] ?? '') === 'existing' && ($x1HeldCtx['selected'] ?? 0) === $dgId
    && str_contains($x1HeldWin, 'Es wurde kein neuer Kontakt angelegt') && str_contains($x1HeldWin, 'name="confirm_new"') && $dgCount() === 1 && $fresh($x1)->getCreditor() === null,
    json_encode([$x1Ctx['mode'] ?? null, $x1Ctx['selected'] ?? null, $x1HeldCtx['held'] ?? null, $dgCount()], JSON_UNESCAPED_UNICODE) . ' | ' . mb_substr(strip_tags($x1Win), 0, 300));

// adopt the existing contact on x1 → x2 (same UID + IBAN) and x5 (same name) get it too; x3 (other UID) and x4 (other party) do not
$useJournal(['id' => (string) $x1], ['csrf_token' => 'tok-session', 'mode' => 'existing', 'contact_id' => (string) $dgId, 'version' => (string) $fresh($x1)->getVersion()], true);
$host = $journalHost();
$x1Ok = $host->adoptAction();
$x1Flash = (string) ($host->messageService->flashes[0][1] ?? '');
$em = $wire();
$dgProfile = $em->getRepository(CreditorProfile::class)->findByContact($dgId);
$x2Inv = $fresh($x2);
$x2Lines = $invoiceOf($wire())->linesOf($x2Inv);
check('X2 adopting on ONE invoice carries the creditor over to every other invoice of the same party: same UID + IBAN (x2) and exactly the same name, case and spaces aside (x5) — with its defaults (account 6500 · VM learned from x1, «Bezahlt mit» Bank + Haben 1020); a conflicting UID (x3) and another party (x4) stay without; the flash counts them («und 2 weitere Rechnungen»); each records match = adopted (the carried ones with adopted_from and adopted_by)',
    $x1Ok instanceof \Z77\Core\Http\Response\FetchResponse && ($envelope($x1Ok)['status'] ?? '') === 'success' && str_contains($x1Flash, 'und 2 weitere Rechnungen nennen ihn jetzt')
    && $dgProfile !== null && $dgProfile->getUid() === $dgUid && $dgProfile->getDefaultAccount() === '6500' && $dgProfile->getDefaultTaxCode() === 'VM' && $dgProfile->getDefaultPaymentMethodCode() === 'bank'
    && $fresh($x1)->getCreditor()?->getId() === $dgProfile->getId() && ($fresh($x1)->recognition()['match'] ?? '') === 'adopted'
    && $x2Inv->getCreditor()?->getId() === $dgProfile->getId() && $x2Inv->getPaymentMethodCode() === 'bank' && $x2Inv->getCreditAccount() === '1020' && $x2Inv->getIban() === Iban::normalize($dgIban)
    && $x2Lines[0]->getAccount() === '6500' && $x2Lines[0]->getTaxCode() === 'VM'
    && ($x2Inv->recognition()['match'] ?? '') === 'adopted' && ($x2Inv->recognition()['adopted_from'] ?? 0) === $x1 && ($x2Inv->recognition()['adopted_by'] ?? '') === 'uid'
    && $fresh($x5)->getCreditor()?->getId() === $dgProfile->getId() && ($fresh($x5)->recognition()['adopted_by'] ?? '') === 'name'
    && $fresh($x3)->getCreditor() === null && $fresh($x4)->getCreditor() === null,
    $x1Flash . ' | ' . json_encode([$dgProfile?->getDefaultAccount(), $x2Inv->getCreditor()?->getId(), $x2Inv->recognition(), $fresh($x5)->getCreditor()?->getId(), $fresh($x3)->getCreditor()?->getId(), $fresh($x4)->getCreditor()?->getId()], JSON_UNESCAPED_UNICODE));

// x3 (another UID, same name): the window still names the existing contact; WITH the confirmation a second contact is created on purpose
$useJournal(['id' => (string) $x3], ['confirm_new' => '1', 'version' => (string) $fresh($x3)->getVersion()] + $dgNew, true);
$host = $journalHost();
$x3Ok = $host->adoptAction();
$x3Inv = $fresh($x3);
check('X3 with the explicit confirmation («Trotzdem einen neuen Kontakt anlegen») the new contact IS created — a second «Digitec Galaxus AG», the creditor of x3 with its own UID; x4 still without',
    $x3Ok instanceof \Z77\Core\Http\Response\FetchResponse && ($envelope($x3Ok)['status'] ?? '') === 'success' && $dgCount() === 2
    && $x3Inv->getCreditor() !== null && $x3Inv->getCreditor()->getContact()?->getId() !== $dgId && $x3Inv->getCreditor()->getUid() === $dgOther && $fresh($x4)->getCreditor() === null,
    json_encode([$dgCount(), $x3Inv->getCreditor()?->getUid(), $x3Ok instanceof \Z77\Core\Http\Response\FetchResponse ? $envelope($x3Ok) : ($host->context['error'] ?? null)], JSON_UNESCAPED_UNICODE));

// the creditor LEARNS its defaults from a saved invoice (owner 2026-11-16)
$em = $wire();
$cLearn = new Contact(['kind' => 'organisation', 'company' => 'Lernlieferant AG', 'language' => 'de']);
(new ContactService($em))->save($cLearn);
$em = $wire();
$learnId = (int) (new CreditorProfileService($em, 'Owner'))->open($em->getRepository(Contact::class)->find((int) $cLearn->getId()))->getId();
$xl1 = (int) $intakeOf($wire())->receive(pdfBytes('xl1-learn'), 'learn1.pdf')->getId();
$xl2 = (int) $intakeOf($wire())->receive(pdfBytes('xl2-learn'), 'learn2.pdf')->getId();
$useJournal(['id' => (string) $xl1], ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-find-list', 'version' => (string) $fresh($xl1)->getVersion()]
    + $head(['creditor' => (string) $learnId, 'payment_method_code' => 'bank']) + ['lines' => [['text' => 'Ware', 'account' => '6500', 'tax' => 'VM', 'amount' => '']]], true);
$host = $journalHost();
$xl1Ok = $host->editAction();
$xl1Flash = (string) ($host->messageService->flashes[0][1] ?? '');
$learnP = $wire()->getRepository(CreditorProfile::class)->find($learnId);
$learned2 = null;
$invoiceOf($wire())->update($xl2, (int) $fresh($xl2)->getVersion(), $head(['creditor' => (string) $learnId, 'payment_method_code' => 'bank']), [['text' => '', 'account' => '6400', 'tax' => 'VI', 'amount' => '']], false, $learned2);
$learnP2 = $wire()->getRepository(CreditorProfile::class)->find($learnId);
check('X4 a creditor WITHOUT a default account takes the first line\'s account with its VAT and «Bezahlt mit» from the save of an (incomplete) invoice — stamped, the flash says it («Konto 6500, MWST VM und «Bezahlt mit» Bank als Standard für Lernlieferant AG gespeichert»); a creditor WITH a default keeps it — another account later learns nothing',
    $xl1Ok instanceof \Z77\Core\Http\Response\FetchResponse && ($envelope($xl1Ok)['status'] ?? '') === 'success'
    && $learnP->getDefaultAccount() === '6500' && $learnP->getDefaultTaxCode() === 'VM' && $learnP->getDefaultPaymentMethodCode() === 'bank' && $learnP->getDefaultsSetBy() === 'Owner'
    && str_contains($xl1Flash, 'Konto 6500, MWST VM und «Bezahlt mit» Bank als Standard für Lernlieferant AG gespeichert.')
    && $learned2 === [] && $learnP2->getDefaultAccount() === '6500' && $learnP2->getDefaultTaxCode() === 'VM',
    $xl1Flash . ' | ' . json_encode([$learnP->getDefaultAccount(), $learnP->getDefaultTaxCode(), $learnP->getDefaultPaymentMethodCode(), $learned2, $learnP2->getDefaultAccount()], JSON_UNESCAPED_UNICODE));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
