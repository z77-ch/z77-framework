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
 * Stage A0 (foreign currency by hand, owner 2026-11-15) adds F1–F7: receipt's ONE currency list
 * (`ReceiptFields::foreignCurrencies()`), the derived rate, a USD invoice incomplete without CHF and
 * blocking the run, refusals (a currency outside the list, an original without currency), the CHF
 * typed in the window → posted in CHF through `GrossEntryBuilder` with «… · 9.00 USD», the list and
 * the search, the recognition filling original amount + currency (a currency outside the list a
 * warning).
 *
 * The owner's look of 2026-11-16 at the journal adds D1–D6: «alle» in the head and the selection
 * in «Im Journal» AND «Gesperrt»; no intro band, no count line; the bulk «Löschen …» window and
 * its POST (each invoice completely — blocked ones too —, the PDFs to the trash, the hashes free,
 * a changed and a posted row skipped and named, an empty selection refused); an invoice deleted
 * while the job recognises it (before the PDF read, with the PDF gone, during the AI call) — no
 * error, nothing counted, nothing written back.
 *
 * The owner's switch «KI-Erkennung» of 2026-11-16 adds K1–K6: never set = on; shown only where the
 * AI is released; the toggle by fetch and without the script, stored in
 * `data/framework/creditor/ai_switch.json`; refused when not released, for a GET and a non-boolean;
 * the job's recognition follows it per invoice (fake transport); an unreadable store is off.
 *
 * Learning per creditor (owner 2026-11-16) adds L1–L11 on synthetic foreign invoices (no QR slip,
 * no Swiss UID; invented names and `.example` domains — never a real supplier document): the job
 * keeps the text; the owner's save teaches VAT number, domain, sender line and the labels (nothing
 * of the own company, the amount in its original currency); the next invoice found and read by
 * them; the trust phase with the fake AI (two comparisons, then no request); a missing value back
 * to the AI and relearned, an owner's correction dropping a label; candidates and the conflict
 * with a UID; learned on «Kreditor übernehmen …»; the creditor window with «Gelerntes vergessen»;
 * the features leaving a creditor the owner corrected; the text deleted with its invoice.
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
use Z77\Module\Creditor\Recognition\InvoiceFacts;

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
check('O4 the config announces CreditorProfile, CreditorIban and (1b) CreditorIntake, CreditorInvoice, CreditorInvoiceLine, CreditorJournalPosting and (2026-11-16, learning per creditor) CreditorInvoiceText, CreditorLearned, the intake limit 20 MiB, registers the role «creditor» under contactRoles, no view area, module role ADMIN',
    $config['doctrineEntities'] === [CreditorProfile::class, CreditorIban::class, CreditorIntake::class, CreditorInvoice::class, CreditorInvoiceLine::class, CreditorJournalPosting::class, \Z77\Module\Creditor\Entities\CreditorInvoiceText::class, \Z77\Module\Creditor\Entities\CreditorLearned::class]
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
$inv->correct($profOff, null, '2026-117', null, null, CreditorInvoice::REFERENCE_NONE, null, '', $chf('100.00'), null, 'bank', '1020', 'Owner', new \DateTimeImmutable());
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
    && caught(fn() => $pInv->correct(null, null, null, null, null, 'NON', null, '', null, null, null, null, 'Owner', new \DateTimeImmutable()), \LogicException::class) !== null
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
check('S4 the fragment: list, edit, forget-learned («Gelerntes vergessen», owner 2026-11-16), toggle-active — no add (the action cell is empty, a creditor is made by the role) and no delete; edit and forget-learned #[Csrf], the switch #[Fetch] POST; every answer through a helper (html / fetch / fetchError / redirect), never a response instantiated',
    $actions === ['listAction', 'editAction', 'forgetLearnedAction', 'toggleActiveAction'] && $attrs('editAction', \Z77\Shared\Attributes\Csrf::class) && $attrs('forgetLearnedAction', \Z77\Shared\Attributes\Csrf::class)
    && $attrs('toggleActiveAction', \Z77\Shared\Attributes\Fetch::class) && $attrs('toggleActiveAction', \Z77\Shared\Attributes\HttpMethod::class)
    && preg_match('/new\s+\\\\?[A-Za-z\\\\]*Response\s*\(/', $creditorSrc) === 0 && !str_contains($trait, "'hc1'"), json_encode($actions));

$tplDir = $pkg . '/res/view/templates/Backend/CreditorProfileController';
$listHelp = (string) @file_get_contents($tplDir . '/list.help.tpl.php');
$editHelp = (string) @file_get_contents($tplDir . '/edit.help.tpl.php');
$firstP   = static fn(string $html): int => preg_match('~<p>(.*?)</p>~s', $html, $m) ? mb_strlen(html_entity_decode(strip_tags($m[1]))) : 999;
$sections = preg_match_all('/data-help-field="([a-z_]+)"/', $editHelp, $m) ? $m[1] : [];
check('S5 the helps: list.help (attached with toolbar: true) and edit.help with a section per field (bank_text — «Banktext», ADR-061 stage C1 —, uid, default_account, default_tax, default_payment_method_code, default_line_text — «Kontierungstext», 2026-11-17 —, iban, hint, iban_active, learned); each opens with an <h3> and a first <p> of ≤ 240 characters; Swiss spelling (no «ß»), «du»',
    str_contains($trait, "'Backend/CreditorProfileController/list.help'") && str_contains($trait, 'toolbar: true') && str_contains($trait, "'Backend/CreditorProfileController/edit.help'")
    && $sections === ['bank_text', 'uid', 'default_account', 'default_tax', 'default_payment_method_code', 'default_line_text', 'iban', 'hint', 'iban_active', 'learned']
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
check('S7 the route needs the module role ADMIN for list, edit, forget-learned and the switch (no own rule)',
    $roleOf('list') === \Z77\Core\Config\AuthRole::ADMIN && $roleOf('edit') === \Z77\Core\Config\AuthRole::ADMIN && $roleOf('forget-learned') === \Z77\Core\Config\AuthRole::ADMIN && $roleOf('toggle-active') === \Z77\Core\Config\AuthRole::ADMIN);

// ── S, stage 1b: the Kreditorenjournal ──
$jHost   = (string) @file_get_contents($root . '/packages/module-backend/src/Ui/Controllers/Finance/CreditorJournalController.php');
$jHostCf = (string) @file_get_contents($root . '/packages/module-backend/src/Ui/Config/Finance/creditorJournalControllerConfig.inc.php');
check('S8 a thin host for the Kreditorenjournal (ADR-018): Finance/CreditorJournalController uses the trait; its layout config pins the fragment\'s listAction',
    str_contains($jHost, 'use CreditorJournalControllerTrait;') && str_contains($jHost, 'extends BackendAbstractController') && str_contains($jHostCf, 'CreditorLayout::journal()')
    && (CreditorLayout::journal()['levelElements']['body']['main'][0] ?? []) === ['nameSpace' => 'Z77\Module\Creditor', 'path' => 'Backend/CreditorJournalController', 'name' => 'listAction']);
$jActions = array_values(array_filter(array_map(static fn(\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass(CreditorJournalControllerTrait::class))->getMethods()), static fn($m) => str_ends_with($m, 'Action')));
$jAttr    = static fn(string $method): bool => (new \ReflectionMethod(CreditorJournalControllerTrait::class, $method))->getAttributes(\Z77\Shared\Attributes\Csrf::class) !== [];
$jTrait   = (string) file_get_contents($pkg . '/src/Ui/CreditorJournalControllerTrait.php');
check('S9 the journal fragment: list, live (GET, no CSRF — the live listener only reads), upload, edit, creditor-fill, payment-fill, confirm-bank and release-movement (ADR-061 stage C1), release, recognize («Jetzt erkennen»), ai-switch (the switch «KI-Erkennung», owner 2026-11-16), intake-retry, intake-remove (stage 2), confirm-intake-remove (bulk «Entfernen …», owner 2026-11-16), confirm-delete, adopt, confirm-paid, confirm-delete-selected (bulk «Löschen …», owner 2026-11-16), confirm-post, post; every POST action #[Csrf]; «Journal verbuchen …» in the action cell, tabs and upload in the toolbar; answers only through the helpers (json for the upload envelope)',
    $jActions === ['listAction', 'liveAction', 'uploadAction', 'editAction', 'creditorFillAction', 'paymentFillAction', 'confirmBankAction', 'releaseMovementAction', 'releaseAction', 'recognizeAction', 'aiSwitchAction', 'intakeRetryAction', 'intakeRemoveAction', 'confirmIntakeRemoveAction', 'confirmDeleteAction', 'adoptAction', 'confirmPaidAction', 'confirmDeleteSelectedAction', 'confirmPostAction', 'postAction']
    && !$jAttr('liveAction') && $jAttr('confirmBankAction') && $jAttr('releaseMovementAction') && $jAttr('aiSwitchAction') && $jAttr('confirmDeleteSelectedAction') && $jAttr('confirmIntakeRemoveAction') && $jAttr('recognizeAction') && $jAttr('intakeRetryAction') && $jAttr('intakeRemoveAction') && $jAttr('uploadAction') && $jAttr('editAction') && $jAttr('releaseAction') && $jAttr('confirmDeleteAction') && $jAttr('adoptAction') && $jAttr('confirmPaidAction') && $jAttr('postAction')
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
    && $jListSec === ['bank', 'cutoff', 'f_creditor', 'f_number', 'f_date', 'f_amount']
    && $jSections === ['creditor', 'iban', 'invoice_number', 'invoice_date', 'due_date', 'reference', 'message', 'currency', 'original_amount', 'amount', 'payment_method_code', 'credit_account', 'paid_on', 'bank', 'line_text', 'line_account', 'line_tax', 'line_amount', 'split', 'take_default', 'release', 'discard', 'delete', 'recognize', 'adopt']
    && str_contains($jListHelp, '<h3>Kreditorenjournal</h3>') && str_contains($jEditHelp, '<h3>Rechnung im Kreditorenjournal</h3>') && str_contains($guide, '<h3>Lieferantenrechnungen')
    && $firstP($jListHelp) <= 240 && $firstP($jEditHelp) <= 240 && $firstP($guide) <= 240 && !str_contains($prose, 'ß')
    && preg_match('/\bdu\b|\bdeine?\b/u', $jListHelp) === 1 && preg_match('/\bdu\b/u', $jEditHelp) === 1 && preg_match('/\bdu\b/u', $guide) === 1
    && !str_contains($guide, 'Buchen: folgt') && str_contains($guide, 'Bezahlt am …') && str_contains($guide, 'Journal verbuchen') && str_contains($guide, 'Ist-Prinzip') && str_contains($guide, '10 Jahre')
    && str_contains($guide, 'neuen Jahres') && !str_contains($guide, 'Automatische Erkennung: folgt') && str_contains($guide, 'Automatische Erkennung:') && str_contains($guide, 'Eingangsordner') && str_contains($jListHelp, 'Gehört die IBAN <strong>mehreren Kreditoren</strong>') && str_contains($jListHelp, '<strong>Nicht eingelesen</strong>') && !str_contains($prose, 'Tab «Eingang»') && !str_contains($prose, '<strong>Eingang</strong>')
    && str_contains($jListHelp, 'Erkennung ausstehend') && str_contains($jListHelp, 'Jetzt erkennen') && str_contains($jEditHelp, 'Jetzt erkennen') && str_contains($guide, 'Erkennung ausstehend') && str_contains($guide, 'Jetzt erkennen')
    && str_contains($jListHelp, 'Rechnungsnummer') && str_contains($jEditHelp, 'unvollständig') && str_contains($jEditHelp, 'Erkannt: Text') && $noPhp($jListHelp) && $noPhp($jEditHelp) && $noPhp($guide)
    // owner 2026-11-16 («viel zu kompliziert»): everyday words — no internal term in the prose, the bulk «Löschen …», «alle» and the drop onto the button explained
    && preg_match('/\bJob\b|Slice|Hash|Partition|Text-?[Ll]ayer|Textlayer|foreignCurrencies|Swico|\bS1\b/u', (string) preg_replace('~<\?php.*?\?>~s', '', $prose)) === 0
    && str_contains($jListHelp, '<strong>Löschen …</strong>') && str_contains($jListHelp, 'auch gesperrte') && str_contains($jListHelp, 'kreuzt alle Rechnungen der Seite an')
    && str_contains($jListHelp, 'Zieh die PDF-Dateien auf <strong>PDF hochladen</strong>') && str_contains($guide, 'Löschen …'),
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
    array_unique(array_map($jRoleOf, ['list', 'upload', 'edit', 'creditor-fill', 'payment-fill', 'release', 'recognize', 'ai-switch', 'confirm-delete', 'adopt', 'confirm-paid', 'confirm-delete-selected', 'confirm-intake-remove', 'confirm-post', 'post'])) === [\Z77\Core\Config\AuthRole::ADMIN]);

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
$allTables = ['creditor_iban', 'creditor_intake', 'creditor_invoice', 'creditor_invoice_line', 'creditor_invoice_text', 'creditor_journal_posting', 'creditor_learned', 'creditor_profile'];
[$code, $out] = $run(['command' => 'migrate']);
check('M1 migrate exits 0 (vat, contact, mandator, financial, receipt, creditor in timestamp order); the module\'s namespace is found through its doctrineEntities', $code === 0 && $tables() === $allTables
    && isset(\Z77\Persistence\Doctrine\Console\MigrationDirectories::collect(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder())['Z77\\Module\\Creditor\\Migrations']), trim($out));
$migrations = glob($pkg . '/res/migrations/Version*.php');
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Creditor\\Migrations']);
check('M2 diff after migrate reports NO change — mapping and migration agree', $code !== 0 && str_contains($out, 'No changes detected') && glob($pkg . '/res/migrations/Version*.php') === $migrations, trim($out));
foreach (array_diff(glob($pkg . '/res/migrations/Version*.php'), $migrations) as $stray) {
    @unlink($stray);   // a failed M2 must not leave a generated file in the package
}
// Down to the 2026-11-15 recognition migration BY NAME — a newer migration of another module
// (financial's bank service, 2026-11-16 14:00) may follow it, so `prev` from the top would not hit
// the creditor's; then `prev` from there runs exactly Version20261115140000's down().
$pendingCol = static fn(): bool => $db->fetchOne("SHOW COLUMNS FROM creditor_invoice LIKE 'recognition_pending_at'") !== false;
[$codeDown, $outDown] = $run(['command' => 'migrate', 'version' => 'Z77\\Module\\Creditor\\Migrations\\Version20261115140000']);
$afterDown = $tables();
[$codeDown2, $outDown2] = $run(['command' => 'migrate', 'version' => 'prev']);
$colDown   = $pendingCol();
$current   = (string) $db->fetchOne("SELECT COUNT(*) FROM " . \Z77\Persistence\Doctrine\Console\MigrationsApplication::STORAGE_TABLE . " WHERE version LIKE '%Creditor%'");
[$codeUp, $outUp] = $run(['command' => 'migrate']);
check('M3 the newest migration (2026-11-16, learning per creditor) drops only creditor_learned and creditor_invoice_text; the one before it (2026-11-15, CREDITOR-RECOG-WEB-001) only creditor_invoice.recognition_pending_at; up() brings both back',
    $codeDown === 0 && $afterDown === array_values(array_diff($allTables, ['creditor_invoice_text', 'creditor_learned'])) && $codeDown2 === 0 && !$colDown && $current === '2'
    && $codeUp === 0 && $tables() === $allTables && $pendingCol()
    && (string) $db->fetchOne("SELECT COUNT(*) FROM " . \Z77\Persistence\Doctrine\Console\MigrationsApplication::STORAGE_TABLE . " WHERE version LIKE '%Creditor%Version20261115140000' OR version LIKE '%Creditor%Version20261116100000'") === '2',
    trim($outDown . $outDown2 . $outUp));

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
    use CreditorProfileControllerTrait { listAction as public; editAction as public; toggleActiveAction as public; forgetLearnedAction as public; }
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
        public function getJsonBody(): array { return $this->fetch ? ($this->post ?? []) : []; }
    }, true);
};
$journalHost = fn() => new class {
    use CreditorJournalControllerTrait { listAction as public; liveAction as public; uploadAction as public; editAction as public; creditorFillAction as public; paymentFillAction as public; releaseAction as public; recognizeAction as public; aiSwitchAction as public; confirmDeleteAction as public; adoptAction as public; confirmPaidAction as public; confirmBankAction as public; releaseMovementAction as public; confirmDeleteSelectedAction as public; confirmPostAction as public; postAction as public; intakeRetryAction as public; intakeRemoveAction as public; confirmIntakeRemoveAction as public; }
    public array $context = [];
    public array $trashed = [];
    public object $layoutManager;
    public object $messageService;
    public object $help;
    public ?string $redirectedTo = null;
    /** The AI released for the installation (kibot's file + key) — the seam the switch «KI-Erkennung» asks. */
    public bool $aiReleased = false;
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
    private function creditorAiReleased(): bool { return $this->aiReleased; }
    private function creditorInvoices(): CreditorInvoiceService { return new CreditorInvoiceService($this->em(), 'Owner'); }
    private function creditorIntake(): CreditorIntakeService { return new CreditorIntakeService($this->em(), null, 'Owner'); }
    private function creditorPosting(): CreditorJournalPostingService { return new CreditorJournalPostingService($this->em(), 'Owner'); }
    private function creditorBankMatch(): \Z77\Module\Creditor\Services\CreditorBankMatchService { return new \Z77\Module\Creditor\Services\CreditorBankMatchService($this->em(), 'Owner'); }
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
check('SM10 the journal (page): the open invoices, the help in the toolbar, «Journal verbuchen …» in the action cell (disabled: nothing paid), the upload policy (cell shape, PDF, 20 MB) and the tabs in the toolbar; rendered — no progress place in the page (the rows of a run are in a window, UPLOAD-DIALOG-001), a row per invoice opening its window with its selection checkbox (id:version), «Vorschlag»/creditor names; no intro band, no count line (owner 2026-11-16)',
    count($host->context['invoices']) === 5 && $host->help->has() && ($host->layoutManager->sections['hc1'] ?? []) === ['Backend/CreditorJournalController/act']
    && ($host->layoutManager->sections['hc2'] ?? []) === ['Backend/CreditorJournalController/toolbar']
    && ($policy['data-upload'] ?? '') === 'cell' && ($policy['action'] ?? '') === '/backend/finance/creditor-journal/upload' && ($policy['data-upload-accept'] ?? '') === 'application/pdf' && isset($policy['data-upload-multiple'])
    && str_contains($jTabs, 'Im Journal') && str_contains($jTabs, 'Gesperrt') && str_contains($jTabs, 'Verbuchte Journale')
    && !str_contains($jList, 'data-upload-progress') && str_contains($jList, 'data-fetch-region="creditor-journal-find-list"')
    && str_contains($jList, 'data-window-open="/backend/finance/creditor-journal/edit?id=' . $i1->getId() . '"') && str_contains($jList, 'Neulieferant GmbH')
    && !str_contains($jList, 'data-journal-pending') && !str_contains($jList, 'unbezahlt ·') && str_contains($jList, 'name="inv[]" form="creditor-selection" value="' . $i1->getId() . ':')
    && str_contains($jList, 'id="creditor-selection" method="get" action="/backend/finance/creditor-journal/confirm-paid" data-window-form')
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
check('C2 «Bezahlt am …» in bulk: the date and «Bezahlt mit» Kasse (Haben 1000) for every ticked row at its version; a BLOCKED one is refused and named «gesperrt» (owner 2026-11-16: decide first — the tab «Gesperrt» offers only «Löschen …»); a row with a stale version is NOT written and named; a future date, no selection, an unknown payment method refused before anything is written',
    count($bulk['paid']) === 2 && count($bulk['refused']) === 2 && str_contains($bulk['refused'][0], 'Rechnung ' . $p1)
    && str_contains($bulk['refused'][1], 'gesperrt') && str_contains($bulk['refused'][1], 'P-1')
    && ($rowOf($p2)['paid_on'] ?? null) === $ymd($today) && ($rowOf($p2)['payment_method_code'] ?? null) === 'kasse' && ($rowOf($p2)['credit_account'] ?? null) === '1000'
    && ($rowOf($p4)['state'] ?? null) === 'blocked' && ($rowOf($p4)['paid_on'] ?? null) === null
    && $rowOf($p1) == $p1Before && str_contains($bulkFuture['paid_on'] ?? '', 'Zukunft') && isset($bulkNone['selection']) && str_contains($bulkMethod['payment_method_code'] ?? '', 'gibt es nicht'),
    json_encode([$bulk['refused'], $bulkFuture, $bulkNone, $bulkMethod], JSON_UNESCAPED_UNICODE));
// a blocked invoice is not paid in its WINDOW either (owner 2026-11-16) — but one paid BEFORE it became blocked still reaches the run, and blocks it
$p4Lines   = [['text' => '', 'account' => '6510', 'tax' => '-', 'amount' => '']];
$p4Before  = $rowOf($p4);
$paidBlock = $refusedInvoice(fn() => $invoiceOf($wire())->update($p4, $vOf($p4), $head(['creditor' => (string) $telId, 'invoice_number' => 'P-1', 'amount' => '100.00', 'payment_method_code' => 'kasse', 'paid_on' => $ymd($today)]), $p4Lines));
$p4After   = $rowOf($p4);
$invoiceOf($wire())->update($p4, $vOf($p4), $head(['creditor' => (string) $telId, 'invoice_number' => 'P-4', 'amount' => '100.00', 'payment_method_code' => 'kasse', 'paid_on' => $ymd($today)]), $p4Lines);
$p4Open    = [$rowOf($p4)['state'] ?? null, $rowOf($p4)['paid_on'] ?? null];
$invoiceOf($wire())->update($p4, $vOf($p4), $head(['creditor' => (string) $telId, 'invoice_number' => 'P-1', 'amount' => '100.00', 'payment_method_code' => 'kasse']), $p4Lines);
check('C2b «Bezahlt am» in the window of a BLOCKED invoice is refused in German, nothing written (owner 2026-11-16); the same save with the suspicion cleared (other number) sets it; a later edit that blocks it again keeps the date',
    str_contains($paidBlock['paid_on'] ?? '', 'gesperrt') && $p4After == $p4Before && $p4Open === ['open', $ymd($today)]
    && ($rowOf($p4)['state'] ?? null) === 'blocked' && ($rowOf($p4)['paid_on'] ?? null) === $ymd($today),
    json_encode([$paidBlock, $p4Open, $rowOf($p4)['state'] ?? null], JSON_UNESCAPED_UNICODE));

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
check('W11 the job done: `live` answers `refresh-region`, the fresh list has no row in progress — its listener watches no row any more (`watch=` empty) but stays (owner 2026-11-16, W13); the busy pill is a shared badge modifier (`.badge--busy`, still under reduced motion) compiled into base.css',
    $fresh($w8Id)?->isRecognitionPending() === false && $commands($w8Done) === ['refresh-region']
    && str_contains($w8Rest, 'data-fetch-live="' . $liveBase . '&amp;t=') && !str_contains($w8Rest, 'badge--busy')
    && str_contains($badges, '.badge--busy') && str_contains($badges, 'prefers-reduced-motion') && str_contains($baseCss, '.badge--busy{animation:badge-busy'),
    json_encode($envelope($w8Done), JSON_UNESCAPED_UNICODE));

// The list asks ALWAYS, also when empty (owner 2026-11-16: «ja liste soll auch ohne neuladen
// nachfragen» — invoices from the intake folder appeared only after a reload)
$liveOf = static function (string $html): array {
    if (!preg_match('/data-creditor-live="list" data-fetch-live="([^"]+)"/', $html, $m)) {
        return [];
    }
    parse_str((string) parse_url(html_entity_decode($m[1], ENT_QUOTES), PHP_URL_QUERY), $q);

    return $q;
};
$useJournal(['f_number' => 'W13-KEINE-SOLCHE']);
$host = $journalHost();
$host->listAction();
$w13Empty = $render('Backend/CreditorJournalController/listAction', $host->context);
$w13Q     = $liveOf($w13Empty);
$w13Same  = $live($w13Q);
$w13Inv   = $intakeOf($wire())->receive(pdfBytes('w13-live'), 'live-w13.pdf');   // as the folder job does
$w13New   = $live($w13Q);
$w13Again = $liveOf($listNow());
$w13Quiet = $live($w13Again);
$w13Old   = $live(['t' => (string) (time() - 601)] + $w13Again);
$w13v     = $fresh((int) $w13Inv->getId());
$invoiceOf($wire())->delete((int) $w13Inv->getId(), $w13v->getVersion(), true);
$w13Gone  = $live($w13Again);
$w13Bad   = $live(['f' => 'nicht-hex', 't' => (string) time()]);
check('W13 the region of «Im Journal» carries the live listener also when it shows no row (`watch=` empty, `f` = the 16-hex fingerprint, `view=open`, every 15 s): unchanged → 204; a new invoice (the folder job\'s `receive()`) → `refresh-region` of the journal; the fresh list\'s fingerprint → 204 again; 10 minutes without a change → `stop-live`; the invoice deleted → `refresh-region`; nothing to compare (no `watch`, a broken `f`) → `stop-live`',
    str_contains($w13Empty, 'Keine Rechnung gefunden.') && ($w13Q['watch'] ?? null) === '' && preg_match('/^[0-9a-f]{16}$/', (string) ($w13Q['f'] ?? '')) === 1
    && ($w13Q['view'] ?? '') === 'open' && str_contains($w13Empty, 'data-fetch-live-every="15"')
    && $w13Same instanceof \Z77\Core\Http\Response\NoContentResponse
    && $w13New instanceof \Z77\Core\Http\Response\FetchResponse && $commands($w13New) === ['refresh-region'] && ($envelope($w13New)['commands'][0]['name'] ?? '') === 'creditor-journal-find-list'
    && ($w13Again['f'] ?? '') !== ($w13Q['f'] ?? '') && $w13Quiet instanceof \Z77\Core\Http\Response\NoContentResponse
    && $w13Old instanceof \Z77\Core\Http\Response\FetchResponse && $commands($w13Old) === ['stop-live']
    && $fresh((int) $w13Inv->getId()) === null && $w13Gone instanceof \Z77\Core\Http\Response\FetchResponse && $commands($w13Gone) === ['refresh-region']
    && $w13Bad instanceof \Z77\Core\Http\Response\FetchResponse && $commands($w13Bad) === ['stop-live'],
    json_encode([$w13Q, $w13Again, get_class($w13Same), get_class($w13New), get_class($w13Quiet)], JSON_UNESCAPED_UNICODE));

$tabOf = static function (string $view) use ($useJournal, $journalHost, $render): string {
    $useJournal(['view' => $view]);
    $host = $journalHost();
    $host->listAction();

    return $render('Backend/CreditorJournalController/listAction', $host->context);
};
$w14Q    = $liveOf($tabOf('intake'));
$w14B    = $liveOf($tabOf('blocked'));
$w14P    = $tabOf('posted');
$w14O    = $liveOf($tabOf('open'));
$w14Same = $live($w14Q);
$w14em   = $wire();
$w14Row  = \Z77\Module\Creditor\Entities\CreditorIntake::failed('w14-live.txt', 7, 'kein PDF', 'w14-live.txt', 'Owner', new \DateTimeImmutable());
$w14em->getTransaction(\Z77\Module\Creditor\Entities\CreditorIntake::class)->run(static function () use ($w14em, $w14Row): void { $w14em->persist($w14Row); });
$w14New  = $live($w14Q);
$w14Open = $live($w14O);   // the band «n Dateien nicht eingelesen» stands in «Im Journal» too
$db->executeStatement('DELETE FROM creditor_intake WHERE id = ?', [(int) $w14Row->getId()]);
$w14Back = $live($w14Q);
check('W14 «Nicht eingelesen» and «Gesperrt» carry the listener too (`view=intake` / `view=blocked`), «Verbuchte Journale» none; a new refused file → `refresh-region` of the intake list (its own region) and of «Im Journal» (the band); the file gone → the same fingerprint as before → 204',
    ($w14Q['view'] ?? '') === 'intake' && ($w14Q['watch'] ?? null) === '' && ($w14B['view'] ?? '') === 'blocked' && !str_contains($w14P, 'data-fetch-live')
    && $w14Same instanceof \Z77\Core\Http\Response\NoContentResponse
    && $w14New instanceof \Z77\Core\Http\Response\FetchResponse && $commands($w14New) === ['refresh-region'] && ($envelope($w14New)['commands'][0]['name'] ?? '') === 'creditor-journal-intake-list'
    && ($w14O['view'] ?? '') === 'open' && $w14Open instanceof \Z77\Core\Http\Response\FetchResponse && ($envelope($w14Open)['commands'][0]['name'] ?? '') === 'creditor-journal-find-list'
    && $w14Back instanceof \Z77\Core\Http\Response\NoContentResponse,
    json_encode([$w14Q, $w14B, get_class($w14Same), get_class($w14New), get_class($w14Back)], JSON_UNESCAPED_UNICODE));

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

// ── F, stage A0 (owner 2026-11-15): foreign currency by hand ──
echo "F. Stage A0 — foreign currency by hand: original amount + currency, CHF typed, posting in CHF\n";
$rf      = \Z77\Module\Receipt\Services\ReceiptFields::class;
$fxErr   = [];
$fxField = new $rf($wire());
$gbp     = $fxField->foreignCurrency('gbp', 'currency', $fxErr);
$kept    = $fxField->foreignCurrency('GBP', 'kept', $fxErr, 'GBP');
$noCur   = [];
$fxField->originalAmount('5.00', null, 'original_amount', $noCur);
check('F1 the ONE currency list is receipt\'s (`receiptConfig[\'foreignCurrencies\']` → EUR, USD); «chf» / \'\' = a CHF document (null); «usd» upper-cased; GBP refused in German («nicht freigegeben (erlaubt: CHF, EUR, USD)»), unless it is the document\'s own; an original amount without currency refused; the derived rate CHF per unit, 6 places, half away from zero, at least 4 places (8.12 / 9.00 = 0.902222, 95.00 / 100.00 = 0.9500, 2.00 / 3.00 = 0.666667, 1.00 / 0.01 = 100.0000)',
    $rf::foreignCurrencies() === ['EUR', 'USD'] && $fxField->foreignCurrency('chf', 'c', $fxErr) === null && $fxField->foreignCurrency('', 'c', $fxErr) === null
    && $fxField->foreignCurrency('usd', 'c', $fxErr) === 'USD' && $gbp === null && str_contains($fxErr['currency'] ?? '', 'Währung GBP ist nicht freigegeben (erlaubt: CHF, EUR, USD)')
    && $kept === 'GBP' && !isset($fxErr['kept']) && str_contains($noCur['original_amount'] ?? '', 'ohne Währung')
    && $rf::derivedRate(\Z77\Shared\Money\Money::fromDecimal('8.12', 'CHF'), \Z77\Shared\Money\Money::fromDecimal('9.00', 'USD')) === '0.902222'
    && $rf::derivedRate(\Z77\Shared\Money\Money::fromDecimal('95.00', 'CHF'), \Z77\Shared\Money\Money::fromDecimal('100.00', 'EUR')) === '0.9500'
    && $rf::derivedRate(\Z77\Shared\Money\Money::fromDecimal('2.00', 'CHF'), \Z77\Shared\Money\Money::fromDecimal('3.00', 'USD')) === '0.666667'
    && $rf::derivedRate(\Z77\Shared\Money\Money::fromDecimal('1.00', 'CHF'), \Z77\Shared\Money\Money::fromDecimal('0.01', 'USD')) === '100.0000',
    json_encode([$rf::foreignCurrencies(), $fxErr, $noCur], JSON_UNESCAPED_UNICODE));

// a USD invoice (Tinify, USD 9.00) saved WITHOUT the CHF amount
$fxHead  = ['creditor' => (string) $telId, 'invoice_number' => 'TINY-1', 'invoice_date' => $ymd($today), 'currency' => 'USD', 'original_amount' => '9.00', 'amount' => '', 'payment_method_code' => 'bank'];
$fxLines = [['text' => 'Tinify Pro', 'account' => '6500', 'tax' => '-', 'amount' => '']];
$fx      = (int) $intakeOf($wire())->receive(pdfBytes('fx-tinify'), 'tinify.pdf')->getId();
$invoiceOf($wire())->update($fx, $vOf($fx), $head($fxHead + ['paid_on' => $ymd($today)]), $fxLines);
$fxInv   = $fresh($fx);
$fxMiss  = $fxInv->missing($invoiceOf($wire())->linesOf($fxInv));
$fxPv    = $posting()->preview($today);
$fxStop  = array_column($fxPv['blocked'], 'reason', 'id')[$fx] ?? '';
$fxRow   = $rowOf($fx);
check('F2 a USD invoice keeps its original (9.00 USD), the CHF amount EMPTY: saved (marked paid), INCOMPLETE — missing() names «Betrag CHF» —, the run blocks it («es fehlt: Betrag CHF»), nothing posted; no rate stored (exchange_rate NULL), the derived rate none yet',
    $fxRow['original_amount'] === '9.00' && $fxRow['original_currency'] === 'USD' && $fxRow['amount'] === null && $fxRow['exchange_rate'] === null && $fxRow['paid_on'] === $ymd($today)
    && $fxInv->isForeign() && $fxInv->originalLabel() === '9.00 USD' && $fxInv->derivedRate() === null && in_array('Betrag CHF', $fxMiss, true) && !in_array('Betrag', $fxMiss, true)
    && str_contains($fxStop, 'es fehlt: Betrag CHF') && !$fxPv['postable'],
    json_encode([$fxRow, $fxMiss, $fxPv['blocked']], JSON_UNESCAPED_UNICODE));

// refusals: a currency outside the list, an original without currency, a foreign currency without its amount
$fxGbp  = $refusedInvoice(fn() => $invoiceOf($wire())->update($fx, $vOf($fx), $head(['currency' => 'GBP'] + $fxHead), $fxLines));
$fxNone = $refusedInvoice(fn() => $invoiceOf($wire())->update($fx, $vOf($fx), $head(['currency' => 'CHF'] + $fxHead), $fxLines));
$fxNoAm = $refusedInvoice(fn() => $invoiceOf($wire())->update($fx, $vOf($fx), $head(['original_amount' => ''] + $fxHead), $fxLines));
check('F3 refused in German, nothing written: GBP (not in the list) under `currency`; an original amount with currency CHF under `original_amount` («ohne Währung»); USD without its amount («Betrag in USD fehlt»)',
    str_contains($fxGbp['currency'] ?? '', 'nicht freigegeben') && str_contains($fxNone['original_amount'] ?? '', 'ohne Währung') && str_contains($fxNoAm['original_amount'] ?? '', 'Betrag in USD fehlt')
    && $rowOf($fx) == $fxRow, json_encode([$fxGbp, $fxNone, $fxNoAm], JSON_UNESCAPED_UNICODE));

// the window before CHF: currency select, the original, «Betrag CHF — fehlt»
$useJournal(['id' => (string) $fx, '_origin' => 'region:creditor-journal-find-list'], null, true);
$host = $journalHost();
$host->editAction();
$fxWin0 = $render('Backend/CreditorJournalController/edit', $host->context);
// the owner types the CHF the bank debited — through the window
$useJournal(['id' => (string) $fx], ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-find-list', 'version' => (string) $vOf($fx)]
    + $head(['amount' => '8.12', 'paid_on' => $ymd($today)] + $fxHead) + ['lines' => $fxLines], true);
$host  = $journalHost();
$fxOk  = $host->editAction();
$fxFlash = (string) ($host->messageService->flashes[0][1] ?? '');
$fxInv = $fresh($fx);
$useJournal(['id' => (string) $fx, '_origin' => 'region:creditor-journal-find-list'], null, true);
$host = $journalHost();
$host->editAction();
$fxWin = $render('Backend/CreditorJournalController/edit', $host->context);
check('F4 the window: «Währung» (CHF, EUR, USD — USD selected), «Betrag in Währung» 9.00, «Betrag CHF» marked «— fehlt» while empty; the owner types 8.12 → saved «vollständig», the one line takes 8.12, the rate is DERIVED and shown under the field («Kurs 0.902222»), not stored',
    str_contains($fxWin0, 'name="currency"') && str_contains($fxWin0, '<option value="USD" selected>USD</option>') && str_contains($fxWin0, '<option value="EUR">EUR</option>')
    && str_contains($fxWin0, 'name="original_amount" value="9.00"') && str_contains($fxWin0, 'Betrag CHF (inkl. MWST) <span class="be-form__missing">— fehlt</span>') && !str_contains($fxWin0, 'data-invoice-rate')
    && $fxOk instanceof \Z77\Core\Http\Response\FetchResponse && ($envelope($fxOk)['status'] ?? '') === 'success' && str_contains($fxFlash, 'vollständig')
    && $fxInv->getAmount()?->toDecimal() === '8.12' && $fxInv->getOriginal()?->toDecimal() === '9.00' && $fxInv->getExchangeRate() === null
    && ($invoiceOf($wire())->linesOf($fxInv)[0] ?? null)?->getAmount()?->toDecimal() === '8.12' && $fxInv->derivedRate() === '0.902222'
    && str_contains($fxWin, 'data-invoice-rate>Kurs 0.902222') && !str_contains($fxWin, '— fehlt'),
    json_encode([$fxOk instanceof \Z77\Core\Http\Response\FetchResponse ? $envelope($fxOk) : ($host->context['errors'] ?? null), $rowOf($fx)], JSON_UNESCAPED_UNICODE) . ' | ' . mb_substr(strip_tags($fxWin0), 0, 200));

// the list row and the search by the printed amount
$useJournal([], null, true);
$host = $journalHost();
$host->listAction();
$fxList = $render('Backend/CreditorJournalController/_journal', $host->context);
$repo   = $wire()->getRepository(CreditorInvoice::class);
$fxIds  = static fn(array $list): array => array_map(static fn(CreditorInvoice $i) => (int) $i->getId(), $list);
check('F5 the list shows CHF 8.12 with «9.00 USD» muted under it; «Betrag» finds the invoice by the printed amount (9.00) and by CHF (8.12)',
    str_contains($fxList, 'data-original-amount>9.00 USD') && in_array($fx, $fxIds($repo->search(new \Z77\Module\Creditor\Repositories\CreditorInvoiceSearch(amount: '9.00'), 0, 50)), true)
    && in_array($fx, $fxIds($repo->search(new \Z77\Module\Creditor\Repositories\CreditorInvoiceSearch(amount: '8.12'), 0, 50)), true));

// posted in CHF through GrossEntryBuilder, the text carries the original
$fxPv2   = $posting()->preview($today);
$fxProt  = $fxPv2['postable'] ? $posting()->post($today, $fxPv2['seen']) : null;
$fxEntry = $db->fetchAssociative('SELECT id, text, entry_date FROM journal_entry WHERE idempotency_key = ?', ['creditor:' . $fx]) ?: [];
$fxLn    = $fxEntry === [] ? [] : $linesOf((int) $fxEntry['id']);
check('F6 «Journal verbuchen» posts the USD invoice in CHF (base currency, ADR-042): 6500 debit 8.12 «ohne MWST» against 1020 credit 8.12 (GrossEntryBuilder), text «Rechnung TINY-1 · Telekom Nord AG · 9.00 USD»; the invoice posted, its original kept; the suffix survives a cut (≤ 255)',
    $fxProt !== null && ($fxEntry['text'] ?? '') === 'Rechnung TINY-1 · Telekom Nord AG · 9.00 USD' && count($fxLn) === 2
    && $fxLn[0]['number'] === '6500' && $fxLn[0]['debit'] === '8.12' && $fxLn[0]['tax_code'] === null && $fxLn[1]['number'] === '1020' && $fxLn[1]['credit'] === '8.12'
    && ($rowOf($fx)['state'] ?? '') === 'posted' && ($rowOf($fx)['original_amount'] ?? '') === '9.00',
    json_encode([$fxPv2['blocked'], $fxEntry, $fxLn], JSON_UNESCAPED_UNICODE));

// the recognition: a USD amount fills original + currency, CHF stays empty; GBP stays a warning
$usdFacts = static fn(string $cur, string $amt): \Z77\Module\Creditor\Recognition\InvoiceFacts => new \Z77\Module\Creditor\Recognition\InvoiceFacts(
    \Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_AI, null, false, $cur, \Z77\Shared\Money\Money::fromDecimal($amt, $cur), null, null, null, null, null, null, null, null, null, [], 'medium', false, []);
$fr1 = (int) $intakeOf($wire())->receive(pdfBytes('fx-recog-usd'), 'usd.pdf')->getId();
$fr2 = (int) $intakeOf($wire())->receive(pdfBytes('fx-recog-gbp'), 'gbp.pdf')->getId();
$invoiceOf($wire())->applyRecognition($fr1, \Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_AI, ['state' => 'recognized'], $usdFacts('USD', '24.50'));
$invoiceOf($wire())->applyRecognition($fr2, \Z77\Module\Creditor\Recognition\InvoiceFacts::STEP_AI, ['state' => 'recognized'], $usdFacts('GBP', '12.00'));
$fr1Inv = $fresh($fr1);
$fr2Inv = $fresh($fr2);
check('F7 recognition (stage A0): an amount in a listed foreign currency fills the ORIGINAL amount + currency (24.50 USD, field `original_amount` from the step), the CHF amount stays empty for the owner, the line without amount; a currency outside the list fills nothing and stays a warning («Betrag in GBP (12.00) — Währung nicht freigegeben …»)',
    $fr1Inv->getOriginal()?->toDecimal() === '24.50' && $fr1Inv->getOriginalCurrency() === 'USD' && $fr1Inv->getAmount() === null
    && ($fr1Inv->recognition()['fields']['original_amount'] ?? '') === 'ai' && ($invoiceOf($wire())->linesOf($fr1Inv)[0] ?? null)?->getAmount() === null
    && $fr2Inv->getOriginal() === null && $fr2Inv->getAmount() === null
    && str_contains(implode(' | ', (array) ($fr2Inv->recognition()['warnings'] ?? [])), 'Betrag in GBP (12.00) — Währung nicht freigegeben'),
    json_encode([$fr1Inv->recognition(), $fr2Inv->recognition()], JSON_UNESCAPED_UNICODE));

// ── D, owner 2026-11-16: «alle», the bulk «Löschen …», no intro band, no count line; a deleted invoice in the recognition ──
echo "D. Select all, bulk «Löschen …», the page without band and count line, a deleted invoice in the recognition (database)\n";
$recognizer($wire())->recognizePending(static fn(): bool => true);   // nothing of the sections above waits
$dId = [];
foreach (['d1', 'd2', 'd3'] as $tag) {
    $dId[$tag] = (int) $intakeOf($wire())->receive(pdfBytes('bulk-' . $tag), 'bulk-' . $tag . '.pdf')->getId();
}
$invoiceOf($wire())->update($dId['d1'], $vOf($dId['d1']), $head(['creditor' => (string) $neuId, 'invoice_number' => 'BULK-1', 'amount' => '10.00']), []);
$invoiceOf($wire())->update($dId['d2'], $vOf($dId['d2']), $head(['creditor' => (string) $neuId, 'invoice_number' => 'BULK-1', 'amount' => '10.00']), []);
$d2Blocked = $fresh($dId['d2'])?->isBlocked() ?? false;
$dPosted   = (int) $db->fetchOne("SELECT id FROM creditor_invoice WHERE state = 'posted' ORDER BY id LIMIT 1");
$dIntakes  = array_map(static fn(int $id): int => (int) $fresh($id)?->getIntake()?->getId(), [$dId['d1'], $dId['d2']]);
$dDocs     = array_map(static fn(int $id): int => (int) $fresh($id)?->getDocumentId(), [$dId['d1'], $dId['d2']]);

$useJournal([], null, false);
$host = $journalHost();
$host->listAction();
$dPage = $render('Backend/CreditorJournalController/listAction', $host->context);
$useJournal(['view' => 'blocked'], null, true);
$host = $journalHost();
$host->listAction();
$dBlocked = $render('Backend/CreditorJournalController/_journal', $host->context);
$dToolbar = $render('Backend/CreditorJournalController/toolbar', array_diff_key($host->context, ['uploadPolicy' => true]));
$bulkButton = 'formaction="/backend/finance/creditor-journal/confirm-delete-selected"';
check('D1 «Im Journal» AND «Gesperrt»: «alle» in the list head for the selection form (data-check-all, rendered hidden for core.js), every row a checkbox of that form (id:version), «Löschen …» (danger, its formaction) above the list, «Bezahlt am …» beside it in «Im Journal» — the blocked d2 tickable in «Gesperrt»',
    $d2Blocked && str_contains($dPage, 'data-check-all="creditor-selection"') && str_contains($dBlocked, 'data-check-all="creditor-selection"')
    && str_contains($dPage, 'value="' . $dId['d1'] . ':' . $vOf($dId['d1']) . '"') && str_contains($dBlocked, 'name="inv[]" form="creditor-selection" value="' . $dId['d2'] . ':' . $vOf($dId['d2']) . '"')
    && str_contains($dPage, $bulkButton) && str_contains($dBlocked, $bulkButton) && str_contains($dBlocked, '>Löschen …</button>') && str_contains($dPage, 'Bezahlt am …')
    && str_contains($dBlocked, 'be-btn be-btn--danger be-btn--sm'), mb_substr(strip_tags($dBlocked), 0, 300));
check('D2 the page without the intro band («… hierher ziehen …») and without the count line («n Rechnungen im Journal · … · Total») and its hints — only the buttons (their meaning in a title); the upload progress runs in a window, nothing of it in the page (UPLOAD-DIALOG-001); the button is the drop target (cell shape, no work-area marker needed)',
    !str_contains($dPage, 'hierher ziehen') && !str_contains($dPage, 'data-upload-area') && !str_contains($dPage, 'data-upload-progress')
    && !str_contains($dPage, 'data-journal-pending') && !str_contains($dPage, 'noch nicht verbucht') && !str_contains($dPage, 'Total ')
    && !str_contains($dPage, '<small class="be-list__cell--muted">die angekreuzten') && !str_contains($dPage, 'bezahlte ankreuzen und')
    && str_contains($dPage, 'title="Die angekreuzten Rechnungen als bezahlt markieren') && str_contains($dToolbar, 'Gesperrt (')
    && ($host->context['uploadPolicy']->withShape('cell')->attributes()['data-upload'] ?? '') === 'cell', mb_substr(strip_tags($dPage), 0, 300));

// the window: GET with the ticked pairs (junk dropped) names them
$dPairs = [$dId['d1'] . ':' . $vOf($dId['d1']), $dId['d2'] . ':' . $vOf($dId['d2']), $dId['d3'] . ':' . $vOf($dId['d3']), $dPosted . ':' . $vOf($dPosted), 'junk', '7:x'];
$useJournal(['inv' => $dPairs, '_origin' => 'region:creditor-journal-find-list'], null, true);
$host = $journalHost();
$host->confirmDeleteSelectedAction();
$dWin = $render('Backend/CreditorJournalController/confirmDeleteSelected', $host->context);
check('D3 «Löschen …» opens a window: «4 Rechnungen löschen?», the invoices compactly (d2 marked «Doppel?»), the pairs as hidden fields (junk dropped), «4 Rechnungen löschen» as the danger answer, the list help attached; nothing deleted by the GET',
    str_contains($dWin, 'data-window="creditor-invoice-delete-selected"') && str_contains($dWin, 'data-window-title="4 Rechnungen löschen?"')
    && substr_count($dWin, 'name="inv[]"') === 4 && str_contains($dWin, 'value="' . $dPairs[0] . '"') && !str_contains($dWin, 'junk')
    && str_contains($dWin, 'data-delete-invoice="' . $dId['d2'] . '"') && str_contains($dWin, 'Doppel?') && str_contains($dWin, '4 Rechnungen löschen')
    && str_contains($dWin, 'Papierkorb') && $host->help->has() && $fresh($dId['d1']) !== null, mb_substr(strip_tags($dWin), 0, 300));

// d3 changes after the list showed it; the POST carries the versions the list showed
$invoiceOf($wire())->update($dId['d3'], $vOf($dId['d3']), $head(['message' => 'inzwischen geändert']), []);
$d3Label = (string) $fresh($dId['d3'])?->label();
$dPostedLabel = (string) $fresh($dPosted)?->label();
$useJournal([], ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-find-list', 'inv' => $dPairs], true);
$host  = $journalHost();
$dDel  = $host->confirmDeleteSelectedAction();
$dFlash = json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE);
$dAgain1 = $intakeOf($wire())->receive(pdfBytes('bulk-d1'), 'bulk-d1.pdf');
$dAgain2 = $intakeOf($wire())->receive(pdfBytes('bulk-d2'), 'bulk-d2.pdf');
check('D4 the POST deletes each ticked invoice through the single delete, COMPLETELY: d1 (open) and d2 (blocked — owner: «gesperrte genau gleich») gone with their intake rows, the PDFs to the trash, the same files read again (hash free); d3 (changed since) and the POSTED one are skipped and named; close + refresh',
    $commands($dDel) === ['close-window', 'refresh-region'] && $fresh($dId['d1']) === null && $fresh($dId['d2']) === null
    && (int) $db->fetchOne('SELECT COUNT(*) FROM creditor_intake WHERE id IN (?, ?)', $dIntakes) === 0 && $host->trashed === $dDocs
    && $dAgain1->getId() !== null && $dAgain2->getId() !== null
    && $fresh($dId['d3']) !== null && ($rowOf($dPosted)['state'] ?? '') === 'posted'
    && str_contains($dFlash, '2 Rechnungen gelöscht') && str_contains($dFlash, 'Nicht gelöscht') && str_contains($dFlash, $d3Label) && str_contains($dFlash, $dPostedLabel)
    && str_contains($dFlash, 'Papierkorb'), $dFlash);
$useJournal([], ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-find-list'], true);
$host  = $journalHost();
$host->confirmDeleteSelectedAction();
$dNone = $render('Backend/CreditorJournalController/confirmDeleteSelected', $host->context);
check('D5 nothing ticked: the window says so (the server, not the script), nothing deleted; the service refuses an empty selection',
    str_contains($dNone, 'data-delete-empty') && str_contains($dNone, 'Keine Rechnung angekreuzt') && $fresh($dId['d3']) !== null
    && caught(fn() => $invoiceOf($wire())->deleteSelected([]), \Z77\Module\Creditor\Services\InvalidCreditorInvoiceException::class) !== null);

// a deleted invoice in the recognition: the job meets it gone — no error, nothing counted, nothing written
$recognizer($wire())->recognizePending(static fn(): bool => true);   // only the invoices below wait
$gone = static function (int $id) use ($db): void {   // the owner's delete in ANOTHER request
    $db->executeStatement('DELETE FROM creditor_invoice_line WHERE invoice_id = ?', [$id]);
    $db->executeStatement('DELETE FROM creditor_invoice WHERE id = ?', [$id]);
};
$g1   = (int) $intakeOf($wire())->receive(pdfBytes('bulk-g1'), 'bulk-g1.pdf')->getId();
$resG1 = $recognizer($wire())->recognizePending(static fn(): bool => true, static function (int $documentId) use ($g1, $gone): ?string { $gone($g1); return pdfBytes('bulk-g1'); });
$g2   = (int) $intakeOf($wire())->receive(pdfBytes('bulk-g2'), 'bulk-g2.pdf')->getId();
$resG2 = $recognizer($wire())->recognizePending(static fn(): bool => true, static function (int $documentId) use ($g2, $gone): ?string { $gone($g2); return null; });   // its PDF went to the trash too
$g3   = (int) $intakeOf($wire())->receive(scanPdf(230), 'bulk-g3.pdf')->getId();
$g3Calls = 0;
$g3Ai = new \Z77\Module\Creditor\Recognition\Ai\AiInvoiceReader(\Z77\Module\Creditor\Recognition\Ai\AiSettings::fromOverrides([
    'paths' => ['keyFile' => 'keys/anthropic.key', 'logDir' => 'logs/kibot'], 'creditorAi' => ['enabled' => true],
], $base), new class ($g3, $gone, $aiAnswer(['invoice_number' => 'AI-GONE']), $g3Calls) implements \Z77\KiBot\Transport {
    public function __construct(private int $id, private \Closure $gone, private \Z77\KiBot\HttpResult $answer, private int &$calls) {}
    public function send(string $url, array $headers, string $body, ?callable $onChunk = null): \Z77\KiBot\HttpResult
    {
        $this->calls++;
        ($this->gone)($this->id);   // deleted while the AI reads it
        return $this->answer;
    }
});
$resG3 = $recognizer($wire(), $g3Ai)->recognizePending(static fn(): bool => true);
$recogSrc = (string) file_get_contents($pkg . '/src/Services/CreditorRecognitionService.php');
$none = ['recognized' => 0, 'failed' => 0, 'more' => false, 'notes' => []];
check('D6 a deleted invoice stops its recognition (owner 2026-11-16): deleted before its PDF is read, or with its PDF gone, or WHILE the AI reads it — no error, nothing counted or noted, no row written back; the job asks the database before an AI step (no AI cost for a deleted invoice)',
    $resG1 === $none && $resG2 === $none && $resG3 === $none && $g3Calls === 1
    && (int) $db->fetchOne('SELECT COUNT(*) FROM creditor_invoice WHERE id IN (?, ?, ?)', [$g1, $g2, $g3]) === 0
    && (int) $db->fetchOne('SELECT COUNT(*) FROM creditor_invoice_line WHERE invoice_id IN (?, ?, ?)', [$g1, $g2, $g3]) === 0
    && ($p = strpos($recogSrc, '->isInJournal($id)')) !== false && $p < strpos($recogSrc, '$reader->read('),
    json_encode([$resG1, $resG2, $resG3, $g3Calls], JSON_UNESCAPED_UNICODE));


// ── B, owner 2026-11-16: «Löschen …» in the window of a blocked invoice; only «Löschen …» in «Gesperrt»; «Entfernen …» for several files of «Nicht eingelesen» ──
echo "B. «Löschen …» of a blocked invoice in its window, «Gesperrt» without «Bezahlt am …», bulk «Entfernen …» of «Nicht eingelesen» (database + temp folder)\n";
$bId = [];
foreach (['b1', 'b2'] as $tag) {
    $bId[$tag] = (int) $intakeOf($wire())->receive(pdfBytes('window-' . $tag), 'window-' . $tag . '.pdf')->getId();
    $invoiceOf($wire())->update($bId[$tag], $vOf($bId[$tag]), $head(['creditor' => (string) $neuId, 'invoice_number' => 'WIN-1', 'amount' => '20.00']), []);
}
$b2       = $fresh($bId['b2']);
$b2Intake = (int) $b2?->getIntake()?->getId();
$b2Doc    = (int) $b2?->getDocumentId();
$useJournal(['id' => (string) $bId['b2'], '_origin' => 'region:creditor-journal-find-list'], null, true);
$host = $journalHost();
$host->editAction();
$bWin = $render('Backend/CreditorJournalController/edit', $host->context);
$useJournal(['id' => (string) $bId['b1'], '_origin' => 'region:creditor-journal-find-list'], null, true);
$host = $journalHost();
$host->editAction();
$bOpenWin = $render('Backend/CreditorJournalController/edit', $host->context);
$useJournal(['id' => (string) $bId['b2']], null, true);
$host = $journalHost();
$host->confirmDeleteAction();
$bDiscard = $render('Backend/CreditorJournalController/confirmDelete', $host->context);
$useJournal(['id' => (string) $bId['b2'], 'completely' => '1'], null, true);
$host = $journalHost();
$host->confirmDeleteAction();
$bDelete = $render('Backend/CreditorJournalController/confirmDelete', $host->context);
check('B1 the window of a BLOCKED invoice offers «Verwerfen …» AND «Löschen …» (owner 2026-11-16: «ja löschen auch im fenster anbieten»), each with its tooltip and help section; «Verwerfen …» keeps its confirmation (the file stays refused), «Löschen …» (completely=1) confirms «ganz gelöscht» and posts completely=1 with the version shown; an open invoice shows only «Löschen …»',
    ($b2?->isBlocked() ?? false) && str_contains($bWin, 'href="/backend/finance/creditor-journal/confirm-delete?id=' . $bId['b2'] . '" data-window-link data-help-key="discard"')
    && str_contains($bWin, 'href="/backend/finance/creditor-journal/confirm-delete?id=' . $bId['b2'] . '&amp;completely=1" data-window-link data-help-key="delete"')
    && str_contains($bWin, '>Verwerfen …</a>') && str_contains($bWin, '>Löschen …</a>') && str_contains($bWin, 'title="Die Rechnung ganz löschen')
    && str_contains($bOpenWin, '>Löschen …</a>') && !str_contains($bOpenWin, '>Verwerfen …</a>') && !str_contains($bOpenWin, 'completely=1')
    && str_contains($bDiscard, 'Verwerfen heisst') && !str_contains($bDiscard, 'name="completely"') && str_contains($bDiscard, 'Rechnung verwerfen')
    && str_contains($bDelete, 'ganz gelöscht') && str_contains($bDelete, '<input type="hidden" name="completely" value="1">') && str_contains($bDelete, 'Rechnung löschen')
    && str_contains($bDelete, 'name="version" value="' . $vOf($bId['b2']) . '"')
    && str_contains((string) file_get_contents($jDir . '/edit.help.tpl.php'), 'data-help-field="delete"'), mb_substr(strip_tags($bDelete), 0, 300));

// «Gesperrt»: only «Löschen …» — the selection form goes straight to the delete window
$useJournal(['view' => 'blocked'], null, true);
$host = $journalHost();
$host->listAction();
$bBlockedTab = $render('Backend/CreditorJournalController/_journal', $host->context);
$useJournal([], null, true);
$host = $journalHost();
$host->listAction();
$bOpenTab = $render('Backend/CreditorJournalController/_journal', $host->context);
$bPaidRefused = $invoiceOf($wire())->markPaid([['id' => $bId['b2'], 'version' => $vOf($bId['b2'])]], (new \DateTimeImmutable('today'))->format('Y-m-d'));
check('B2 «Gesperrt» offers ONLY «Löschen …» (owner 2026-11-16: «ja nur löschen … sonst wäre er ja nicht gesperrt»): no «Bezahlt am …», the selection form opens confirm-delete-selected; «Im Journal» keeps both; the server refuses «Bezahlt am …» for a ticked blocked invoice (named «gesperrt»), nothing written',
    str_contains($bBlockedTab, 'name="inv[]" form="creditor-selection" value="' . $bId['b2'] . ':') && str_contains($bBlockedTab, '>Löschen …</button>') && !str_contains($bBlockedTab, 'Bezahlt am …')
    && str_contains($bBlockedTab, 'id="creditor-selection" method="get" action="/backend/finance/creditor-journal/confirm-delete-selected" data-window-form')
    && str_contains($bOpenTab, 'Bezahlt am …') && str_contains($bOpenTab, '>Löschen …</button>') && str_contains($bOpenTab, 'action="/backend/finance/creditor-journal/confirm-paid" data-window-form')
    && $bPaidRefused['paid'] === [] && count($bPaidRefused['refused']) === 1 && str_contains($bPaidRefused['refused'][0], 'gesperrt')
    && ($fresh($bId['b2'])?->getPaidOn() === null), json_encode($bPaidRefused['refused'], JSON_UNESCAPED_UNICODE));

$useJournal(['id' => (string) $bId['b2']], ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-find-list', 'version' => (string) $vOf($bId['b2']), 'completely' => '1'], true);
$host   = $journalHost();
$bDel   = $host->confirmDeleteAction();
$bFlash = json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE);
$bAgain = $intakeOf($wire())->receive(pdfBytes('window-b2'), 'window-b2.pdf');
check('B3 the window POST deletes the blocked invoice COMPLETELY through the same delete(…, completely: true) the bulk path runs: invoice and intake row gone (no «discarded» row), the PDF to the trash, the same file read again (hash free); close + refresh; the flash says so',
    $commands($bDel) === ['close-window', 'refresh-region'] && $fresh($bId['b2']) === null
    && (int) $db->fetchOne('SELECT COUNT(*) FROM creditor_intake WHERE id = ?', [$b2Intake]) === 0 && $host->trashed === [$b2Doc]
    && $bAgain->getId() !== null && str_contains($bFlash, 'gelöscht') && str_contains($bFlash, 'Papierkorb') && !str_contains($bFlash, 'verworfen'), $bFlash);

// «Nicht eingelesen»: four refused files, then the selection
$bNow = time();
foreach (['b-eins.txt', 'b-zwei.txt', 'b-drei.txt', 'b-vier.txt'] as $name) {
    file_put_contents($dir . '/' . $name, 'no pdf ' . $name);
    touch($dir . '/' . $name, $bNow - 7200);
}
$folderOf($wire())->pass(static fn(): bool => true, $bNow);
$bRow  = static fn(string $name): int => (int) $db->fetchOne("SELECT id FROM creditor_intake WHERE state = 'failed' AND original_name = ? ORDER BY id DESC LIMIT 1", [$name]);
$fEins = $bRow('b-eins.txt');
$fZwei = $bRow('b-zwei.txt');
$fDrei = $bRow('b-drei.txt');
$fVier = $bRow('b-vier.txt');
$useJournal(['view' => 'intake'], null, true);
$host = $journalHost();
$host->listAction();
$bTab = $render('Backend/CreditorJournalController/_journal', $host->context);
check('B4 tab «Nicht eingelesen»: the same selection pattern as the journal tabs — «alle» in the head for its own GET form (data-check-all), a checkbox per row (intake[] = the row id), «Entfernen …» (danger) above the list opening the window confirm-intake-remove; the single «Erneut versuchen» / «Entfernen» stay',
    $fEins > 0 && $fZwei > 0 && $fDrei > 0 && $fVier > 0 && str_contains($bTab, 'data-check-all="creditor-intake-selection"')
    && str_contains($bTab, 'name="intake[]" form="creditor-intake-selection" value="' . $fEins . '"')
    && str_contains($bTab, 'id="creditor-intake-selection" method="get" action="/backend/finance/creditor-journal/confirm-intake-remove" data-window-form')
    && str_contains($bTab, 'form="creditor-intake-selection" class="be-btn be-btn--danger be-btn--sm"') && str_contains($bTab, '>Entfernen …</button>')
    && str_contains($bTab, 'data-fetch-post="/backend/finance/creditor-journal/intake-retry"') && str_contains($bTab, 'data-fetch-post="/backend/finance/creditor-journal/intake-remove"'),
    mb_substr(strip_tags($bTab), 0, 300));

$useJournal(['intake' => [(string) $fEins, (string) $fZwei, (string) $fDrei, 'junk', '-4'], '_origin' => 'region:creditor-journal-intake-list'], null, true);
$host = $journalHost();
$host->confirmIntakeRemoveAction();
$bAsk = $render('Backend/CreditorJournalController/confirmIntakeRemove', $host->context);
check('B5 «Entfernen …» opens a window: «3 Dateien entfernen?», the files with their reason, their ids as hidden fields (junk dropped), «3 Dateien entfernen» as the danger answer, the list help attached; nothing removed by the GET',
    str_contains($bAsk, 'data-window="creditor-intake-remove"') && str_contains($bAsk, 'data-window-title="3 Dateien entfernen?"')
    && substr_count($bAsk, 'name="intake[]"') === 3 && str_contains($bAsk, 'data-remove-intake="' . $fZwei . '"') && str_contains($bAsk, 'b-drei.txt') && str_contains($bAsk, 'kein PDF')
    && !str_contains($bAsk, 'junk') && str_contains($bAsk, '3 Dateien entfernen') && $host->help->has()
    && is_file($dir . '/failed/b-eins.txt') && (int) $db->fetchOne('SELECT COUNT(*) FROM creditor_intake WHERE id IN (?, ?, ?)', [$fEins, $fZwei, $fDrei]) === 3,
    mb_substr(strip_tags($bAsk), 0, 300));

// meanwhile: b-zwei's file vanished from failed/ (row still there), b-drei was removed in another request
@unlink($dir . '/failed/b-zwei.txt');
$folderOf($wire())->remove($fDrei);
$useJournal([], ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-intake-list', 'intake' => [(string) $fEins, (string) $fZwei, (string) $fDrei]], true);
$host      = $journalHost();
$bRem      = $host->confirmIntakeRemoveAction();
$bRemFlash = json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE);
check('B6 the POST removes each ticked file through the single «Entfernen»: b-eins (file and row gone), b-zwei (its file already missing — the row cleaned up); b-drei, gone since, is skipped and named, not an error; b-vier untouched; close + refresh',
    $commands($bRem) === ['close-window', 'refresh-region'] && !is_file($dir . '/failed/b-eins.txt')
    && (int) $db->fetchOne('SELECT COUNT(*) FROM creditor_intake WHERE id IN (?, ?, ?)', [$fEins, $fZwei, $fDrei]) === 0
    && is_file($dir . '/failed/b-vier.txt') && (int) $db->fetchOne('SELECT COUNT(*) FROM creditor_intake WHERE id = ?', [$fVier]) === 1
    && str_contains($bRemFlash, '2 Dateien entfernt') && str_contains($bRemFlash, 'Nicht entfernt: Eintrag ' . $fDrei . ': schon weg') && str_contains($bRemFlash, '"info"'),
    $bRemFlash);

$useJournal([], ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-intake-list'], true);
$host = $journalHost();
$host->confirmIntakeRemoveAction();
$bNone = $render('Backend/CreditorJournalController/confirmIntakeRemove', $host->context);
$useJournal([], ['csrf_token' => 'tok-session', 'intake' => [(string) $fVier]], false);
$host  = $journalHost();
$bPage = $host->confirmIntakeRemoveAction();
check('B7 nothing ticked: the window says so (the server, not the script), nothing removed; without the script the POST answers with the flash and back to the tab «Nicht eingelesen»',
    str_contains($bNone, 'data-intake-remove-empty') && str_contains($bNone, 'Keine Datei angekreuzt') && !str_contains($bNone, 'name="intake[]"')
    && $bPage instanceof \Z77\Core\Http\Response\RedirectResponse && $host->redirectedTo === '/backend/finance/creditor-journal/list?view=intake'
    && !is_file($dir . '/failed/b-vier.txt') && str_contains(json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE), '1 Datei entfernt'));

// ── K, owner 2026-11-16: the switch «KI-Erkennung» next to «PDF hochladen» ──
echo "K. The switch «KI-Erkennung» — default on, released only, toggle (fetch / page), refusals, the job follows it (database + fake AI)\n";
$kFile   = $base . '/data/framework/creditor/ai_switch.json';
$kSwitch = static fn(): \Z77\Module\Creditor\Recognition\Ai\AiSwitch => new \Z77\Module\Creditor\Recognition\Ai\AiSwitch($wire());
$kTools  = static fn(array $context): string => $render('Backend/CreditorJournalController/toolbar', array_diff_key($context, ['uploadPolicy' => true]));
$kList   = static function (bool $released) use ($useJournal, $journalHost): object {
    $useJournal([]);
    $host = $journalHost();
    $host->aiReleased = $released;
    $host->listAction();
    return $host;
};
$kNever    = !is_file($kFile) && $kSwitch()->isOn();
$kHidden   = $kList(false);
$kShown    = $kList(true);
$kToolsOff = $kTools($kHidden->context);
$kToolsOn  = $kTools($kShown->context);
check('K1 never set → ON (the default keeps z77.ch running the AI); not released → no switch (context aiSwitch null, nothing rendered); released → the .be-switch after the tabs: checked, the core data-fetch-toggle to ai-switch, a POST form with csrf_token and a <noscript> «Übernehmen» (no script needed), aria-label «KI-Erkennung», the label shortening on a phone, the tooltip in plain words (costs per invoice)',
    $kNever && array_key_exists('aiSwitch', $kHidden->context) && $kHidden->context['aiSwitch'] === null && !str_contains($kToolsOff, 'ai-switch') && !str_contains($kToolsOff, 'KI-Erkennung')
    && $kShown->context['aiSwitch'] === true
    && str_contains($kToolsOn, '<form method="post" action="/backend/finance/creditor-journal/ai-switch">') && str_contains($kToolsOn, '<input type="hidden" name="csrf_token" value="tok-session">')
    && str_contains($kToolsOn, 'class="be-switch be-switch--sm"') && str_contains($kToolsOn, 'name="value" value="1" aria-label="KI-Erkennung"')
    && str_contains($kToolsOn, 'data-fetch-toggle="/backend/finance/creditor-journal/ai-switch" checked') && str_contains($kToolsOn, '<span class="be-switch__label">KI<span class="be-switch__label-more">-Erkennung</span></span>')
    && str_contains($kToolsOn, '<noscript><button type="submit"') && str_contains($kToolsOn, 'Rappen')
    && strpos($kToolsOn, 'be-viewtabs') < strpos($kToolsOn, 'ai-switch')
    && str_contains((string) file_get_contents($root . '/packages/module-backend/res/scss/components/_switch.scss'), '.be-switch__label-more { display: none; }'),
    mb_substr($kToolsOn, -900));

$useJournal([], ['value' => false], true);
$host     = $journalHost();
$host->aiReleased = true;
$kOff     = $host->aiSwitchAction();
$kOffMsg  = json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE);
$kStored  = json_decode((string) @file_get_contents($kFile), true);
$kAfter   = $kTools($kList(true)->context);
check('K2 the switch flipped off (fetch, {value: false}): success with the flash «KI-Erkennung ausgeschaltet …», stored in data/framework/creditor/ai_switch.json (one record, on=false, changed_at), the next page renders it unchecked',
    $kOff instanceof \Z77\Core\Http\Response\FetchResponse && ($envelope($kOff)['status'] ?? '') === 'success' && str_contains($kOffMsg, 'KI-Erkennung ausgeschaltet')
    && !$kSwitch()->isOn() && is_array($kStored) && count($kStored) === 1 && ($kStored[0]['on'] ?? null) === false && is_string($kStored[0]['changed_at'] ?? null)
    && str_contains($kAfter, 'data-fetch-toggle="/backend/finance/creditor-journal/ai-switch">') && !str_contains($kAfter, 'ai-switch" checked'),
    $kOffMsg . ' ' . json_encode($kStored));

$kRefused = [];
foreach ([[false, ['value' => true], 'nicht freigegeben'], [true, null, 'POST erwartet'], [true, ['value' => 'yes'], 'Ungültiger Wert']] as [$released, $post, $why]) {
    $useJournal([], $post, true);
    $host = $journalHost();
    $host->aiReleased = $released;
    $answer = $host->aiSwitchAction();
    $kRefused[] = ($envelope($answer)['status'] ?? '') === 'error' && str_contains(json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE), $why);
}
check('K3 the server re-checks: AI not released → refused («nicht freigegeben») though the client sent on; a GET → refused; a value that is no boolean → refused; the switch stays off each time',
    $kRefused === [true, true, true] && !$kSwitch()->isOn(), json_encode($kRefused));

$useJournal([], ['csrf_token' => 'tok-session', 'value' => '1'], false);
$host    = $journalHost();
$host->aiReleased = true;
$kPageOn = $host->aiSwitchAction();
$kOnMsg  = json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE);
$kWasOn  = $kSwitch()->isOn();
$useJournal([], ['csrf_token' => 'tok-session'], false);
$host     = $journalHost();
$host->aiReleased = true;
$kPageOff = $host->aiSwitchAction();
check('K4 without the script (page POST): the ticked checkbox (value=1) switches on, an unticked one (no value) off — each answers with the flash after the redirect back to the list',
    $kPageOn instanceof \Z77\Core\Http\Response\RedirectResponse && $kWasOn && str_contains($kOnMsg, 'KI-Erkennung eingeschaltet')
    && $kPageOff instanceof \Z77\Core\Http\Response\RedirectResponse && $host->redirectedTo === '/backend/finance/creditor-journal/list' && !$kSwitch()->isOn(), $kOnMsg);

$aiCalls   = [];
$kScanOff  = $received(scanPdf(160), 'scan-k-aus.pdf', $aiReader(true, [$aiAnswer(['invoice_number' => 'AI-K1', 'gross_total' => '91.00'])]));
$kOffCalls = count($aiCalls);
$kSwitch()->set(true);
$kScanOn   = $received(scanPdf(170), 'scan-k-an.pdf', $aiReader(true, [$aiAnswer(['invoice_number' => 'AI-K2', 'gross_total' => '92.00'])]));
check('K5 the job follows the switch at every invoice: released but switched OFF → no request leaves the server, the note «nicht erkannt, KI-Erkennung ausgeschaltet — von Hand erfassen», source «von Hand»; switched on again → the next invoice goes to the AI (one request, source «KI»)',
    $kOffCalls === 0 && $kScanOff->recognitionSource() === 'von Hand' && !isset($kScanOff->recognition()['steps']['ai'])
    && in_array(\Z77\Module\Creditor\Services\CreditorRecognitionService::NOTE_AI_OFF, (array) ($kScanOff->recognition()['notes'] ?? []), true)
    && count($aiCalls) === 1 && $kScanOn->recognitionSource() === 'KI' && $kScanOn->getInvoiceNumber() === 'AI-K2',
    json_encode([$kOffCalls, count($aiCalls), $kScanOff->recognition(), $kScanOn->recognitionSource()], JSON_UNESCAPED_UNICODE));

file_put_contents($kFile, '{ not json');
$kCorrupt = $kSwitch()->isOn();
@unlink($kFile);
check('K6 a store that cannot be read is OFF (no paid call on an unreadable choice, logged); the file gone again → the default ON',
    $kCorrupt === false && $kSwitch()->isOn());

// ── L, owner 2026-11-16: learning per creditor — recognised without IBAN / UID, labels learned, the AI only the first times ──
echo "L. Learning per creditor — features, labels, trust, adopt, the creditor window (database + pdftotext + fake AI)\n";

/**
 * A supplier invoice WITHOUT a QR slip and without a Swiss UID — the foreign case of the owner's live
 * test (a USD invoice, an invoice from the Netherlands). Synthetic: invented names, `.example`
 * domains, a made-up VAT number; never a real supplier document. Label and value are drawn as ONE
 * string, so pdftotext keeps them on one line whatever its column detection does.
 *
 * @param array{name: string, street: string, mail: string, web: string, vat: string} $sender
 */
function foreignPdf(array $sender, string $number, string $issued, string $due, string $amount, string $amountLabel = 'Amount due'): string
{
    $pdf = \Z77\Shared\Pdf\PdfDocument::create('creditor-harness-foreign');
    $pdf->addPage();
    $rows = [
        [20, 20, $sender['name']], [120, 20, 'INVOICE'],
        [20, 26, $sender['street']], [120, 26, 'Invoice number: ' . $number],
        [20, 32, $sender['mail']], [120, 32, 'Date of issue: ' . $issued],
        [20, 38, $sender['web']], [120, 38, 'Date due: ' . $due],
        [20, 55, 'Bill to'], [20, 61, 'Harness AG'], [20, 67, 'Bahnhofstrasse 1'], [20, 73, '8000 Zürich'], [20, 79, 'buchhaltung@harness-ag.ch'],
        [20, 100, 'Service subscription: ' . $amount],
        [120, 120, 'Subtotal: ' . $amount],
        [120, 126, $amountLabel . ': ' . $amount],
    ];
    if ($sender['vat'] !== '') {
        $rows[] = [20, 150, $sender['name'] . ' · VAT ' . $sender['vat'] . ' · Chamber of Commerce 12345678'];
    }
    foreach ($rows as [$x, $y, $t]) {
        $pdf->font('', 10)->text($x, $y, $t);
    }

    return $pdf->output();
}

$em = $wire();
(new \Z77\Module\Mandator\Services\MandatorService($em))->update((new \Z77\Module\Mandator\Services\CurrentMandator($em))->find(), ['email' => 'buchhaltung@harness-ag.ch', 'website' => 'www.harness-ag.ch', 'street' => 'Bahnhofstrasse', 'house_no' => '1']);
$cs = new ContactService($wire());
$lc = [];
foreach (['pixel' => 'Pixelwerk B.V.', 'bild' => 'Bildfabrik GmbH'] as $k => $company) {
    $c = new Contact(['kind' => 'organisation', 'company' => $company, 'language' => 'de']);
    $cs->save($c);
    $lc[$k] = (int) $c->getId();
}
$pPixel = $openProfile($lc['pixel'], [], []);
$pBild  = $openProfile($lc['bild'], [], []);
$pixel  = ['name' => 'Pixelwerk B.V.', 'street' => 'Musterkade 10, 1000 AA Amsterdam', 'mail' => 'billing@pixelwerk.example', 'web' => 'www.pixelwerk.example', 'vat' => 'NL123456789B01'];
$learnedOf = static fn(int $profileId): array => $db->fetchAllAssociative('SELECT kind, field, value, currency, agreed FROM creditor_learned WHERE creditor_profile_id = ? ORDER BY kind, field, value', [$profileId]);
$labelsOf  = static fn(int $profileId): array => array_column(array_filter($learnedOf($profileId), static fn(array $r): bool => $r['kind'] === 'label'), null, 'field');
$kindsOf   = static fn(int $profileId, string $kind): array => array_column(array_filter($learnedOf($profileId), static fn(array $r): bool => $r['kind'] === $kind), 'value');
$aiFor     = static fn(string $number, string $date, string $due, string $gross = '9.00', string $currency = 'USD'): \Z77\KiBot\HttpResult => $aiAnswer([
    'creditor_name' => 'Pixelwerk B.V.', 'creditor_address_lines' => ['Musterkade 10'], 'creditor_postal_code' => '1000 AA', 'creditor_town' => 'Amsterdam',
    'creditor_country' => 'NL', 'iban' => null, 'currency' => $currency, 'gross_total' => $gross, 'invoice_number' => $number,
    'invoice_date' => $date, 'due_date' => $due, 'line_text' => 'Service subscription', 'expense_category' => 'Software',
]);

if (!$canRead) {
    echo "  note L1–L9: pdftotext not found here ({$tools->reason}) — the learning checks on PDFs are skipped\n";
} else {
    // L1 — the first invoice of a foreign creditor: no IBAN, no UID, nothing learned yet → not found; the text is kept
    $pw1 = $received(foreignPdf($pixel, 'PW-0001', 'October 1, 2026', 'October 15, 2026', '$9.00 USD'), 'pw1.pdf', $aiReader(false, []));
    $txt = (string) $db->fetchOne('SELECT text FROM creditor_invoice_text WHERE invoice_id = ?', [(int) $pw1->getId()]);
    check('L1 a foreign invoice (no QR slip, no Swiss UID) of a creditor that learned nothing yet: not found («none»), no guess; the job KEPT its text layer (creditor_invoice_text) for the learning in a web request',
        $pw1->getCreditor() === null && ($pw1->recognition()['match'] ?? '') === 'none' && str_contains($txt, 'Pixelwerk B.V.') && str_contains($txt, 'Amount due'),
        json_encode([$pw1->recognition(), mb_substr($txt, 0, 120)], JSON_UNESCAPED_UNICODE));

    // L2 — the owner settles it: saved with the creditor and the values → the creditor learns
    $em  = $wire();
    $pw1 = $invoiceOf($em)->update((int) $pw1->getId(), $pw1->getVersion(), $head(['creditor' => (string) $pPixel, 'invoice_number' => 'PW-0001', 'invoice_date' => '2026-10-01', 'due_date' => '2026-10-15', 'currency' => 'USD', 'original_amount' => '9.00']), [['text' => 'Pixelwerk', 'account' => '', 'tax' => '', 'amount' => '']]);
    $l2  = $labelsOf($pPixel);
    $all = json_encode($learnedOf($pPixel), JSON_UNESCAPED_UNICODE);
    check('L2 the owner saves it with the creditor: it learns the foreign VAT number, its domain and the sender line with its name, and the labels «Invoice number», «Date of issue», «Date due», «Amount due» (USD — the original, never the CHF); nothing of the own company (Harness AG, Bahnhofstrasse 1, harness-ag.ch)',
        $kindsOf($pPixel, 'vat') === ['NL123456789B01'] && $kindsOf($pPixel, 'domain') === ['pixelwerk.example'] && in_array('pixelwerk b.v.', $kindsOf($pPixel, 'sender'), true)
        && ($l2['invoice_number']['value'] ?? '') === 'Invoice number' && ($l2['invoice_date']['value'] ?? '') === 'Date of issue' && ($l2['due_date']['value'] ?? '') === 'Date due'
        && ($l2['amount']['value'] ?? '') === 'Amount due' && ($l2['amount']['currency'] ?? '') === 'USD' && (int) ($l2['amount']['agreed'] ?? -1) === 0
        && !str_contains(mb_strtolower($all), 'harness') && !str_contains($all, 'bahnhofstrasse'), $all);

    // L3 — the next invoice: matched by the learned features, the values read at the learned labels (AI not released)
    $pw2  = $received(foreignPdf($pixel, 'PW-0002', 'November 1, 2026', 'November 15, 2026', '$9.00 USD'), 'pw2.pdf', $aiReader(false, []));
    $rec2 = $pw2->recognition();
    $pixelNoVat = ['vat' => ''] + $pixel;
    $pw2b = $received(foreignPdf($pixelNoVat, 'PW-0002B', 'November 2, 2026', 'November 16, 2026', '$9.00 USD'), 'pw2b.pdf', $aiReader(false, []));
    check('L3 the next invoice is recognised by what the creditor learned («learned», the VAT number named) and read at its labels: date and due date, the original 9.00 USD (CHF stays empty — A0), each «text-learned», source «Text»; without the VAT number the domain and the sender line together still decide',
        $pw2->getCreditor()?->getId() === $pPixel && ($rec2['match'] ?? '') === 'learned' && in_array('vat:NL123456789B01', (array) ($rec2['steps']['text']['learned_by'] ?? []), true)
        && $pw2->getInvoiceNumber() === 'PW-0002' && $pw2->getInvoiceDate()?->format('Y-m-d') === '2026-11-01' && $pw2->getDueDate()?->format('Y-m-d') === '2026-11-15'
        && $pw2->getOriginal()?->toDecimal() === '9.00' && $pw2->getOriginalCurrency() === 'USD' && $pw2->getAmount() === null
        && ($rec2['fields']['invoice_date'] ?? '') === 'text-learned' && ($rec2['fields']['original_amount'] ?? '') === 'text-learned' && ($rec2['steps']['text']['learned'] ?? '') === 'used'
        && $pw2->recognitionSource() === 'Text'
        && $pw2b->getCreditor()?->getId() === $pPixel && ($pw2b->recognition()['match'] ?? '') === 'learned' && !in_array('vat:NL123456789B01', (array) ($pw2b->recognition()['steps']['text']['learned_by'] ?? []), true),
        json_encode([$rec2, $pw2b->recognition()], JSON_UNESCAPED_UNICODE));

    // L4 — the trust phase: AI released and on → the AI reads the first invoices, PHP is compared; after two agreements PHP reads alone
    $aiCalls = [];
    $pw3 = $received(foreignPdf($pixel, 'PW-0003', 'December 1, 2026', 'December 15, 2026', '$9.00 USD'), 'pw3.pdf', $aiReader(true, [$aiFor('PW-0003', '2026-12-01', '2026-12-15')]));
    $agreed3 = array_map(static fn(array $r): int => (int) $r['agreed'], $labelsOf($pPixel));
    $pw4 = $received(foreignPdf($pixel, 'PW-0004', 'January 1, 2027', 'January 15, 2027', '$9.00 USD'), 'pw4.pdf', $aiReader(true, [$aiFor('PW-0004', '2027-01-01', '2027-01-15')]));
    $calls4 = count($aiCalls);
    $pw5 = $received(foreignPdf($pixel, 'PW-0005', 'February 1, 2027', 'February 15, 2027', '$9.00 USD'), 'pw5.pdf', $aiReader(true, [$aiFor('PW-0005', '2027-02-01', '2027-02-15')]));
    $rec5 = $pw5->recognition();
    $over = (new \Z77\Module\Creditor\Services\CreditorLearningService($wire()))->overview($wire()->getRepository(CreditorProfile::class)->find($pPixel));
    check('L4 the trust phase (AI released and switched on): the first two invoices still go to the AI (one request each, step «compare»), every label agrees with it (1, then 2); the third is read by PHP ALONE — no request, step «trusted», no AI step, number / dates / 9.00 USD from the labels',
        $agreed3 === ['amount' => 1, 'due_date' => 1, 'invoice_date' => 1, 'invoice_number' => 1] && $calls4 === 2 && ($pw3->recognition()['steps']['text']['learned'] ?? '') === 'compare'
        && isset($pw4->recognition()['steps']['ai']) && $over['trusted'] === true
        && count($aiCalls) === 2 && !isset($rec5['steps']['ai']) && ($rec5['steps']['text']['learned'] ?? '') === 'trusted'
        && $pw5->getCreditor()?->getId() === $pPixel && $pw5->getInvoiceNumber() === 'PW-0005' && $pw5->getInvoiceDate()?->format('Y-m-d') === '2027-02-01'
        && $pw5->getOriginal()?->toDecimal() === '9.00' && $pw5->getOriginalCurrency() === 'USD' && $pw5->recognitionSource() === 'Text',
        json_encode([$agreed3, $calls4, count($aiCalls), $rec5, $over], JSON_UNESCAPED_UNICODE));

    // L5 — a missing value falls back to the AI and relearns; an owner's correction relearns too
    $pw6 = $received(foreignPdf($pixel, 'PW-0006', 'March 1, 2027', 'March 15, 2027', '$9.00 USD', 'Balance'), 'pw6.pdf', $aiReader(true, [$aiFor('PW-0006', '2027-03-01', '2027-03-15')]));
    $l5  = $labelsOf($pPixel);
    $em  = $wire();
    $pw6 = $invoiceOf($em)->find((int) $pw6->getId());
    $invoiceOf($em)->update((int) $pw6->getId(), $pw6->getVersion(), $head(['creditor' => (string) $pPixel, 'invoice_number' => 'PW-0006', 'invoice_date' => '2027-03-01', 'due_date' => '2027-03-20']), [['text' => 'Pixelwerk', 'account' => '', 'tax' => '', 'amount' => '']]);
    $l5b = $labelsOf($pPixel);
    check('L5 the label no longer reads the amount («Balance» instead of «Amount due»): not trusted → the AI reads it (a third request), the amount label is learned again («Balance», trust 0) — the others keep theirs; the owner corrects a due date the text does not print → that label is dropped (learned anew later)',
        count($aiCalls) === 3 && isset($pw6->recognition()['steps']['ai']) && ($l5['amount']['value'] ?? '') === 'Balance' && (int) ($l5['amount']['agreed'] ?? -1) === 0
        && (int) ($l5['invoice_number']['agreed'] ?? 0) === 3 && !isset($l5b['due_date']) && isset($l5b['invoice_date']),
        json_encode([count($aiCalls), $l5, $l5b], JSON_UNESCAPED_UNICODE));

    // L6 — never a guess: features two creditors hold decide nothing; several creditors → candidates; IBAN / UID against the learned → candidates
    $em = $wire();
    $em->getTransaction(\Z77\Module\Creditor\Entities\CreditorLearned::class)->run(static function () use ($em, $pBild): void {
        $p = $em->getRepository(CreditorProfile::class)->find($pBild);
        $em->persist(new \Z77\Module\Creditor\Entities\CreditorLearned($p, 'vat', '', 'DE123456789', null, 'Owner', new \DateTimeImmutable()));
        $em->persist(new \Z77\Module\Creditor\Entities\CreditorLearned($p, 'domain', '', 'pixelwerk.example', null, 'Owner', new \DateTimeImmutable()));
    });
    $learning = new \Z77\Module\Creditor\Services\CreditorLearningService($wire(), 'Owner');
    $both     = $learning->match("Pixelwerk B.V.\nVAT NL123456789B01\nBildfabrik GmbH · USt-IdNr. DE123456789\n");
    $weak     = $learning->match("Pixelwerk B.V.\nbilling@pixelwerk.example\n");
    $conflict = $recognizer($wire())->match(\Z77\Module\Creditor\Recognition\InvoiceFacts::fromTextOnly('UID ' . $uidKnown . " MWST\nVAT NL123456789B01\nPixelwerk B.V.\n"), 'UID ' . $uidKnown . " MWST\nVAT NL123456789B01\nPixelwerk B.V.\n");
    check('L6 never a guess: two creditors each decided by their own VAT number → «learned-choose» with both; the shared domain counts for nobody and the sender line alone is weak → «learned-choose» with Pixelwerk as the only candidate (the owner picks); the UID names one creditor and the learned VAT another → «learned-conflict», both candidates, no pick',
        $both['creditor'] === null && $both['by'] === 'learned-choose' && array_column($both['candidates'], 'id') === [$pPixel, $pBild]
        && $weak['creditor'] === null && $weak['by'] === 'learned-choose' && array_column($weak['candidates'], 'id') === [$pPixel]
        && $conflict['creditor'] === null && $conflict['by'] === 'learned-conflict' && array_column($conflict['candidates'], 'id') === [$pUid, $pPixel],
        json_encode([$both, $weak, $conflict['by'], $conflict['candidates']], JSON_UNESCAPED_UNICODE));

    // L7 — learned on «Kreditor übernehmen …»: an unknown foreign creditor, the AI's proposal, adopted → the next one is found
    $neu   = ['name' => 'Neuland Studio Ltd', 'street' => '1 Example Road, London', 'mail' => 'accounts@neuland.example', 'web' => 'www.neuland.example', 'vat' => 'GB123456789'];
    $nAi   = static fn(string $n, string $d): \Z77\KiBot\HttpResult => $aiAnswer(['creditor_name' => 'Neuland Studio Ltd', 'creditor_address_lines' => ['1 Example Road'], 'creditor_postal_code' => 'SW1A 1AA', 'creditor_town' => 'London', 'creditor_country' => 'GB', 'iban' => null, 'currency' => 'EUR', 'gross_total' => '49.00', 'invoice_number' => $n, 'invoice_date' => $d, 'due_date' => null, 'line_text' => 'Design', 'expense_category' => 'Grafik']);
    $nw1   = $received(foreignPdf($neu, 'NS-17', '3 March 2027', '17 March 2027', '€49.00'), 'nw1.pdf', $aiReader(true, [$nAi('NS-17', '2027-03-03')]));
    $cN    = new Contact(['kind' => 'organisation', 'company' => 'Neuland Studio Ltd', 'language' => 'de']);
    (new ContactService($wire()))->save($cN);
    $em    = $wire();
    $nw1   = $invoiceOf($em)->find((int) $nw1->getId());
    $nw1   = $invoiceOf($em)->adoptCreditor((int) $nw1->getId(), $nw1->getVersion(), $em->getRepository(Contact::class)->find((int) $cN->getId()));
    $pNeu  = (int) $nw1->getCreditor()?->getId();
    $nl    = $labelsOf($pNeu);
    $nw2   = $received(foreignPdf($neu, 'NS-18', '3 April 2027', '17 April 2027', '€49.00'), 'nw2.pdf', $aiReader(false, []));
    check('L7 «Kreditor übernehmen …» settles an unknown foreign creditor from the AI\'s proposal: the new creditor learns its VAT number (GB…), its domain and the labels of the AI\'s values (49.00 EUR at «Amount due»); its next invoice is found by them («learned») and read at them',
        $pNeu > 0 && $kindsOf($pNeu, 'vat') === ['GB123456789'] && $kindsOf($pNeu, 'domain') === ['neuland.example'] && ($nl['amount']['currency'] ?? '') === 'EUR' && ($nl['amount']['value'] ?? '') === 'Amount due'
        && $nw2->getCreditor()?->getId() === $pNeu && ($nw2->recognition()['match'] ?? '') === 'learned' && $nw2->getInvoiceNumber() === 'NS-18' && $nw2->getOriginal()?->toDecimal() === '49.00' && $nw2->getOriginalCurrency() === 'EUR',
        json_encode([$learnedOf($pNeu), $nw2->recognition()], JSON_UNESCAPED_UNICODE));

    // L8 — the creditor window shows what was learned (read-only) and offers «Gelerntes vergessen»
    $useRequest(['id' => (string) $pPixel, '_origin' => 'region:creditor-find-list'], null, true);
    $host = $creditorHost();
    $host->editAction();
    $lWin = $render('Backend/CreditorProfileController/edit', $host->context + ['csrfToken' => 'tok-session']);
    check('L8 the creditor window: «Gelernt aus Rechnungen» names the VAT number, the domain and the sender, the labels with the amount\'s currency, the trust in plain words, and offers «Gelerntes vergessen» (a submit of the window form to forget-learned)',
        str_contains($lWin, 'Gelernt aus Rechnungen') && str_contains($lWin, 'MWST-Nr. NL123456789B01') && str_contains($lWin, 'Domain pixelwerk.example') && str_contains($lWin, 'Absender «pixelwerk b.v.»')
        && str_contains($lWin, 'Betrag in USD bei «Balance»') && str_contains($lWin, 'Noch in Prüfung') && str_contains($lWin, 'formaction="/backend/finance/creditor-profile/forget-learned?id=' . $pPixel . '"')
        && str_contains($lWin, 'Gelerntes vergessen'), mb_substr(strip_tags($lWin), -700));

    // L9 — the owner corrects a creditor the learned features chose: they leave the wrong one
    $pw7 = $received(foreignPdf($pixel, 'PW-0007', 'April 1, 2027', 'April 15, 2027', '$9.00 USD'), 'pw7.pdf', $aiReader(false, []));
    $by7 = (array) ($pw7->recognition()['steps']['text']['learned_by'] ?? []);
    $em  = $wire();
    $invoiceOf($em)->update((int) $pw7->getId(), $pw7->getVersion(), $head(['creditor' => (string) $pBild, 'invoice_number' => 'PW-0007']), [['text' => 'x', 'account' => '', 'tax' => '', 'amount' => '']]);
    check('L9 the learned features chose Pixelwerk, the owner saves the invoice with Bildfabrik: the features that chose (VAT number, sender line) leave Pixelwerk, Bildfabrik learns them',
        $pw7->getCreditor()?->getId() === $pPixel && in_array('vat:NL123456789B01', $by7, true)
        && $kindsOf($pPixel, 'vat') === [] && in_array('NL123456789B01', $kindsOf($pBild, 'vat'), true), json_encode([$by7, $learnedOf($pPixel), $learnedOf($pBild)], JSON_UNESCAPED_UNICODE));

    // L10 — «Gelerntes vergessen»: POST only, the window's token, then everything the creditor learned is gone
    $useRequest(['id' => (string) $pPixel], null, true);
    $host  = $creditorHost();
    $lGet  = $host->forgetLearnedAction();
    $useRequest(['id' => (string) $pPixel], ['csrf_token' => 'tok-session', 'entity_csrf' => 'tok-wrong'], true);
    $host  = $creditorHost();
    $lBad  = $host->forgetLearnedAction();
    $kept  = count($learnedOf($pPixel));
    $useRequest(['id' => (string) $pPixel], ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-find-list', 'entity_csrf' => 'tok-creditorProfile-' . $pPixel], true);
    $host  = $creditorHost();
    $lDone = $host->forgetLearnedAction();
    check('L10 «Gelerntes vergessen»: a GET and a wrong window token are refused (nothing deleted); the POST deletes everything Pixelwerk learned, closes the window, reloads the list and says so; the other creditors keep theirs',
        ($envelope($lGet)['status'] ?? '') === 'error' && ($envelope($lBad)['status'] ?? '') === 'error' && $kept > 0
        && $commands($lDone) === ['close-window', 'refresh-region'] && $learnedOf($pPixel) === [] && $learnedOf($pNeu) !== []
        && str_contains(json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE), 'vergessen'), json_encode([$kept, $envelope($lDone)], JSON_UNESCAPED_UNICODE));

    // L11 — deleting an invoice deletes its kept text
    $em    = $wire();
    $pw2   = $invoiceOf($em)->find((int) $pw2->getId());
    $invoiceOf($em)->delete((int) $pw2->getId(), $pw2->getVersion());
    check('L11 an invoice deleted («Löschen …») takes its kept text with it', (int) $db->fetchOne('SELECT COUNT(*) FROM creditor_invoice_text WHERE invoice_id = ?', [(int) $pw2->getId()]) === 0);
}

// ── N, owner 2026-11-17: the line text («Kontierungstext») per creditor ──
echo "N. The line text per creditor — standard text, learned column header with continuation lines, last saved text; the header optional for the trust phase (database)\n";
// Synthetic layout text (pdftotext -layout shape) — invented supplier, `.example` domain; the job's text step is driven directly.
$nLayout = static fn(string $number, string $item, string $period, string $amount): string =>
    "Studio Beispiel Inc.                                Invoice number   {$number}\n"
    . "billing@studio-beispiel.example                     Date of issue    November 1, 2026\n"
    . "\n"
    . "Description                      Qty     Unit price     Tax       Amount\n"
    . "\n"
    . str_pad($item, 33) . "1        CHF {$amount}      0%       CHF {$amount}\n"
    . ($period !== '' ? $period . "\n" : '')
    . "\n"
    . "                                                    Amount due     CHF {$amount}\n";
$nPlain  = static fn(string $number, string $amount): string => "Studio Beispiel Inc.\nInvoice number   {$number}\nDate of issue    November 1, 2026\nAmount due     CHF {$amount}\n";
$ncN     = $newContact('Studio Beispiel Inc.');
$pN      = $openProfile((int) $ncN->getId(), [], []);
$nProf   = static fn(): CreditorProfile => $wire()->getRepository(CreditorProfile::class)->find($pN);
$nNew    = static fn(string $tag): int => (int) $intakeOf($wire())->receive(pdfBytes($tag), $tag . '.pdf')->getId();
$nLine   = static fn(int $id): string => (string) $db->fetchOne('SELECT text FROM creditor_invoice_line WHERE invoice_id = ? ORDER BY position LIMIT 1', [$id]);
$nSource = static fn(int $id): string => (string) ($fresh($id)?->recognition()['fields']['line_text'] ?? '');
$nApply  = static function (int $id, ?string $text, bool $last = true, string $step = InvoiceFacts::STEP_TEXT, ?InvoiceFacts $facts = null) use ($wire, $invoiceOf, $pN): ?CreditorInvoice {
    $em = $wire();
    $p  = $em->getRepository(CreditorProfile::class)->find($pN);
    $facts ??= $text !== null ? InvoiceFacts::fromTextOnly($text) : null;
    return $invoiceOf($em)->applyRecognition($id, $step, ['state' => 'text-only'], $facts, $step === InvoiceFacts::STEP_TEXT ? $p : null, 'learned', [], [], $last, $step === InvoiceFacts::STEP_TEXT ? $text : null);
};
$nHeaderRow = static fn(): array => $db->fetchAssociative("SELECT value, agreed FROM creditor_learned WHERE creditor_profile_id = ? AND kind = 'label' AND field = 'line_text'", [$pN]) ?: [];

// N1 — the first invoice: the creditor known, nothing learned yet → the line text stays empty; the owner keeps «Studio Max · Nov 14–Dec 14, 2026» → the header is learned
$n1 = $nNew('n-first');
$nApply($n1, $nLayout('EX-0041', 'Studio Max', 'Nov 14–Dec 14, 2026', '200.00'));
$n1Empty = $nLine($n1);
$invoiceOf($wire())->update($n1, $vOf($n1), $head(['creditor' => (string) $pN, 'invoice_number' => 'EX-0041', 'amount' => '200.00']), [['text' => 'Studio Max · Nov 14–Dec 14, 2026', 'account' => '', 'tax' => '', 'amount' => '']]);
check('N1 the first invoice of a creditor that learned nothing: the line text stays empty (no standard, no header, no last text); the owner saves it with «Studio Max · Nov 14–Dec 14, 2026» → the column header ABOVE that text is learned («Description», a label row of its own field `line_text`), and the creditor keeps it as its last saved line text',
    $n1Empty === '' && ($nHeaderRow()['value'] ?? '') === 'Description' && $nProf()->getLastLineText() === 'Studio Max · Nov 14–Dec 14, 2026' && $nProf()->getDefaultLineText() === null,
    json_encode([$n1Empty, $nHeaderRow(), $nProf()->getLastLineText()], JSON_UNESCAPED_UNICODE));

// N2 — the next invoice: read at the header, the continuation line appended
$n2 = $nNew('n-second');
$nApply($n2, $nLayout('EX-0042', 'Studio Ultra', 'Dec 14, 2026–Jan 14, 2027', '220.00'));
$n2Lines = $invoiceOf($wire())->linesOf($fresh($n2));
check('N2 the next invoice (AI skipped): its line text is read under the learned header — the first row left of the amount, the period below appended («Studio Ultra · Dec 14, 2026–Jan 14, 2027»), source «text-learned»; still ONE line (the period never becomes a position)',
    $nLine($n2) === 'Studio Ultra · Dec 14, 2026–Jan 14, 2027' && $nSource($n2) === 'text-learned' && count($n2Lines) === 1,
    json_encode([$nLine($n2), $nSource($n2), count($n2Lines)], JSON_UNESCAPED_UNICODE));

// N3 — no header in the text: the last saved text, but only on the run's LAST step (an AI step that follows may give one)
$n3 = $nNew('n-third');
$nApply($n3, $nPlain('EX-0043', '50.00'), false);
$n3Before = $nLine($n3);
$nApply($n3, null, true, InvoiceFacts::STEP_AI);
check('N3 an invoice without the column table: the text step with an AI step to follow leaves the line empty; the run\'s last step (the AI gave nothing) takes the creditor\'s last saved line text, source «creditor-last»',
    $n3Before === '' && $nLine($n3) === 'Studio Max · Nov 14–Dec 14, 2026' && $nSource($n3) === 'creditor-last', json_encode([$n3Before, $nLine($n3), $nSource($n3)], JSON_UNESCAPED_UNICODE));

// N4 — «als Standard übernehmen» with the text: the standard text wins ALWAYS — over the header and over the step's own text
$invoiceOf($wire())->update($n2, $vOf($n2), $head(['creditor' => (string) $pN, 'invoice_number' => 'EX-0042', 'amount' => '220.00', 'payment_method_code' => 'bank']), [['text' => 'KI-Entgelte', 'account' => '6500', 'tax' => '-', 'amount' => '']], true);
$n4 = $nNew('n-fourth');
$nQrText = new InvoiceFacts(step: InvoiceFacts::STEP_TEXT, iban: null, isQrIban: false, currency: null, amount: null, referenceType: null, reference: null, message: null, invoiceNumber: 'EX-0044',
    invoiceDate: null, dueDate: null, uid: null, lineText: 'Rechnung EX-0044', category: null, proposal: [], confidence: null, notForPayment: false, warnings: []);
$nApply($n4, $nLayout('EX-0044', 'Studio Ultra', 'Jan 14–Feb 14, 2027', '220.00'), true, InvoiceFacts::STEP_TEXT, $nQrText);
$n5 = $nNew('n-fifth');
$invoiceOf($wire())->update($n5, $vOf($n5), $head(['creditor' => (string) $pN, 'invoice_number' => 'EX-0045']), [['text' => 'Eigener Text', 'account' => '', 'tax' => '', 'amount' => '']]);
$nApply($n5, $nLayout('EX-0045', 'Studio Ultra', '', '220.00'));
check('N4 «als Standard übernehmen» on a complete invoice takes its line text as the creditor\'s STANDARD («KI-Entgelte»); the next invoice gets it although the header reads «Studio Ultra …» and the step read «Rechnung EX-0044» — source «creditor-standard»; a line the owner already wrote is never overwritten (only empty fields)',
    $nProf()->getDefaultLineText() === 'KI-Entgelte' && $nLine($n4) === 'KI-Entgelte' && $nSource($n4) === 'creditor-standard' && $nLine($n5) === 'Eigener Text',
    json_encode([$nProf()->getDefaultLineText(), $nLine($n4), $nSource($n4), $nLine($n5)], JSON_UNESCAPED_UNICODE));

// N5 — the header is optional for the trust phase
$db->executeStatement("DELETE FROM creditor_learned WHERE creditor_profile_id = ? AND kind = 'label' AND field IN ('invoice_number', 'invoice_date', 'due_date', 'amount')", [$pN]);
foreach ([['invoice_number', 'Invoice number', null], ['invoice_date', 'Date of issue', null], ['amount', 'Amount due', 'CHF']] as [$nf, $nv, $nc]) {
    $db->executeStatement("INSERT INTO creditor_learned (kind, field, value, currency, date_order, agreed, invoice_id, learned_by, learned_at, creditor_profile_id) VALUES ('label', ?, ?, ?, NULL, 2, NULL, 'Owner', NOW(), ?)", [$nf, $nv, $nc, $pN]);
}
$nLearning = new \Z77\Module\Creditor\Services\CreditorLearningService($wire(), 'Owner');
$nRead     = $nLearning->read($nProf(), $nPlain('EX-0046', '230.00'));
$nReadT    = $nLearning->read($nProf(), $nLayout('EX-0046', 'Studio Ultra', '', '230.00'));
$nOver     = $nLearning->overview($nProf());
check('N5 the column header is OPTIONAL for the trust phase: with the required labels agreed twice, an invoice WITHOUT the column table is trusted (the AI is left out) and one with it alike; the header is never a read value, it does not count in REQUIRED / TRUST_AFTER; the creditor window lists it (after the value labels)',
    $nRead['trusted'] === true && $nReadT['trusted'] === true && !isset($nRead['values']['line_text']) && !isset($nReadT['values']['line_text'])
    && $nOver['trusted'] === true && array_key_last($nOver['labels']) === 'line_text' && ($nOver['labels']['line_text']['label'] ?? '') === 'Description',
    json_encode([$nRead, $nOver['labels']], JSON_UNESCAPED_UNICODE));

// N6 — the creditor window: the field «Kontierungstext»
(new CreditorProfileService($wire(), 'Owner'))->update($nProf(), $nProf()->getVersion(), ['default_line_text' => "  KI-Entgelte \n  Studio "], []);
$nTyped = $nProf()->getDefaultLineText();
$nLong  = caught(fn() => (new CreditorProfileService($wire(), 'Owner'))->update($nProf(), $nProf()->getVersion(), ['default_line_text' => str_repeat('x', 256)], []), \Z77\Module\Creditor\Services\InvalidCreditorProfileException::class);
(new CreditorProfileService($wire(), 'Owner'))->update($nProf(), $nProf()->getVersion(), ['default_line_text' => ''], []);
$nTpl  = (string) file_get_contents($pkg . '/res/view/templates/Backend/CreditorProfileController/edit.tpl.php');
$nHelp = (string) file_get_contents($pkg . '/res/view/templates/Backend/CreditorProfileController/edit.help.tpl.php');
check('N6 the creditor window carries «Kontierungstext» (default_line_text): typed (whitespace flattened), longer than a line text refused, emptied = none (the order header → step → last applies again); the help explains it; the last saved text is no form field',
    $nTyped === 'KI-Entgelte Studio' && $nLong !== null && $nProf()->getDefaultLineText() === null && str_contains($nTpl, 'name="default_line_text"') && str_contains($nTpl, '>Kontierungstext</label>')
    && str_contains($nHelp, 'data-help-field="default_line_text"') && !str_contains($nTpl, 'last_line_text'), json_encode([$nTyped, $nProf()->getDefaultLineText()], JSON_UNESCAPED_UNICODE));

// N7 — the invoice window offers «als Standard übernehmen» when the text differs from the standard (also while none is set)
$useJournal(['id' => (string) $n5, '_origin' => 'region:creditor-journal-find-list'], null, true);
$host = $journalHost();
$host->editAction();
$nWin = $render('Backend/CreditorJournalController/edit', $host->context);
check('N7 the invoice window of a creditor without a standard text whose line carries «Eigener Text»: «als Standard übernehmen» is offered (the way to set the standard text from an invoice)',
    str_contains($nWin, 'name="take_default"') && str_contains($nWin, 'als Standard übernehmen'), mb_substr(strip_tags($nWin), -400));

// ── V, owner 2026-11-17: lines that do not add up to «Betrag CHF» are refused ──
echo "V. Lines = amount — refused on save with the difference, incomplete still saveable (database)\n";
$v1 = $nNew('v-first');
$vRefused = $refusedInvoice(fn() => $invoiceOf($wire())->update($v1, $vOf($v1), $head(['creditor' => (string) $pN, 'invoice_number' => 'V-1', 'amount' => '20.00']),
    [['text' => 'A', 'account' => '6500', 'tax' => '-', 'amount' => '15.00'], ['text' => 'B', 'account' => '6500', 'tax' => '-', 'amount' => '10.00']]));
$vVersion = $vOf($v1);
check('V1 «Betrag CHF» 20.00 and every line with an amount (15.00 + 10.00): refused under «Kontierung» — «Zeilen ergeben CHF 25.00, Rechnungstotal CHF 20.00 — Differenz CHF 5.00» — nothing written',
    ($vRefused['lines'] ?? '') === 'Zeilen ergeben CHF 25.00, Rechnungstotal CHF 20.00 — Differenz CHF 5.00' && $rowOf($v1)['amount'] === null && $vVersion === 1,
    json_encode([$vRefused, $vVersion], JSON_UNESCAPED_UNICODE));
$invoiceOf($wire())->update($v1, $vOf($v1), $head(['creditor' => (string) $pN, 'invoice_number' => 'V-1', 'amount' => '20.00']),
    [['text' => 'A', 'account' => '6500', 'tax' => '-', 'amount' => '15.00'], ['text' => 'B', 'account' => '6500', 'tax' => '-', 'amount' => '']]);
$v1Missing = $fresh($v1)->missing($invoiceOf($wire())->linesOf($fresh($v1)));
$v2 = $nNew('v-second');
$invoiceOf($wire())->update($v2, $vOf($v2), $head(['creditor' => (string) $pN, 'invoice_number' => 'V-2', 'amount' => '']),
    [['text' => 'A', 'account' => '6500', 'tax' => '-', 'amount' => '15.00'], ['text' => 'B', 'account' => '6500', 'tax' => '-', 'amount' => '10.00']]);
$v3 = $nNew('v-third');
$invoiceOf($wire())->update($v3, $vOf($v3), $head(['creditor' => (string) $pN, 'invoice_number' => 'V-3', 'amount' => '20.00']),
    [['text' => 'A', 'account' => '6500', 'tax' => '-', 'amount' => '12.50'], ['text' => 'B', 'account' => '6500', 'tax' => '-', 'amount' => '7.50']]);
check('V2 still saveable as INCOMPLETE: a line without an amount (the soft «Zeilen = Betrag» stays in missing()), an empty «Betrag CHF» (lines 15.00 + 10.00); lines that add up (12.50 + 7.50 = 20.00) save as before',
    $rowOf($v1)['amount'] === '20.00' && in_array('Zeilen = Betrag', $v1Missing, true) && $rowOf($v2)['amount'] === null && count($invoiceOf($wire())->linesOf($fresh($v2))) === 2
    && $rowOf($v3)['amount'] === '20.00' && !in_array('Zeilen = Betrag', $fresh($v3)->missing($invoiceOf($wire())->linesOf($fresh($v3))), true),
    json_encode([$v1Missing, $rowOf($v2)['amount']], JSON_UNESCAPED_UNICODE));
$vBody = ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-find-list', 'version' => (string) $vOf($v3), 'creditor' => (string) $pN, 'iban' => '', 'invoice_number' => 'V-3', 'invoice_date' => '',
    'due_date' => '', 'reference' => '', 'message' => '', 'currency' => 'CHF', 'original_amount' => '', 'amount' => '20.00', 'payment_method_code' => '', 'credit_account' => '', 'paid_on' => '',
    'lines' => [['text' => 'A', 'account' => '6500', 'tax' => '-', 'amount' => '15.00'], ['text' => 'B', 'account' => '6500', 'tax' => '-', 'amount' => '10.00']]];
$useJournal(['id' => (string) $v3], $vBody, true);
$host  = $journalHost();
$vResp = $host->editAction();
$vWin  = $render('Backend/CreditorJournalController/edit', $host->context);
check('V3 the window (fetched POST): the mismatch keeps the window OPEN (no close-window) and shows the sentence under «Kontierung» with the typed lines; nothing saved',
    !($vResp instanceof \Z77\Core\Http\Response\FetchResponse && in_array('close-window', $commands($vResp), true))
    && str_contains($vWin, 'Zeilen ergeben CHF 25.00, Rechnungstotal CHF 20.00 — Differenz CHF 5.00') && str_contains($vWin, 'value="15.00"') && (string) $vOf($v3) === $vBody['version'],
    mb_substr(strip_tags($vWin), 0, 300));
$vLow = CreditorInvoiceService::lineSumMismatch(\Z77\Shared\Money\Money::fromDecimal('20.00', 'CHF'), [\Z77\Shared\Money\Money::fromDecimal('18.50', 'CHF')]);
check('V4 the sentence also for a sum BELOW the total («Zeilen ergeben CHF 18.50, Rechnungstotal CHF 20.00 — Differenz CHF 1.50»); no sentence without a total or with a line without amount',
    $vLow === 'Zeilen ergeben CHF 18.50, Rechnungstotal CHF 20.00 — Differenz CHF 1.50'
    && CreditorInvoiceService::lineSumMismatch(null, [\Z77\Shared\Money\Money::fromDecimal('1.00', 'CHF')]) === null
    && CreditorInvoiceService::lineSumMismatch(\Z77\Shared\Money\Money::fromDecimal('2.00', 'CHF'), [\Z77\Shared\Money\Money::fromDecimal('1.00', 'CHF'), null]) === null, (string) $vLow);
// the N / V invoices leave the journal, so the bank section below sees only its own
foreach ([$n1, $n2, $n3, $n4, $n5, $v1, $v2, $v3] as $nGone) {
    $invoiceOf($wire())->delete($nGone, $vOf($nGone));
}

// ── Z, ADR-061 stage C1 (owner 2026-11-17): the creditor invoice finds its bank movement ──
echo "Z. Bank-driven «Bezahlt am» — the invoice finds its movement: reference, proposal, candidates, foreign currency, retro-link, release (ADR-061 stage C1)\n";
// Every IBAN, name, reference and amount below is synthetic.
$zMatch  = static fn(): \Z77\Module\Creditor\Services\CreditorBankMatchService => new \Z77\Module\Creditor\Services\CreditorBankMatchService($wire(), 'Owner');
$zKind   = \Z77\Module\Creditor\Services\CreditorBankMatch::class;
$zLinks  = static fn(): \Z77\Module\Financial\Bank\BankLinkService => new \Z77\Module\Financial\Bank\BankLinkService($wire(), 'Owner');
$zDay    = static fn(int $ago): string => $today->modify('-' . $ago . ' days')->format('Y-m-d');
$zMv     = static fn(string $ref): int => (int) $db->fetchOne('SELECT id FROM bank_movement WHERE entry_ref = ?', [$ref]);
$zLink   = static fn(int $invoice): array => $db->fetchAssociative('SELECT * FROM bank_link WHERE owner_key = ?', ['creditor:' . $invoice]) ?: [];
$zRefs   = static fn(\Z77\Module\Creditor\Services\CreditorBankMatch $m): array => array_map(static fn($mv) => (string) $mv->getEntryRef(), $m->movements);
$zFind   = static fn(int $id) => $zMatch()->find($fresh($id));
$zBankRefused = static function (callable $fn): string {
    try { $fn(); } catch (CreditorRefusedException $e) { return $e->reason . ': ' . $e->getMessage(); }
    return '';
};
$zOne    = [['text' => '', 'account' => '6500', 'tax' => '-', 'amount' => '']];

$zMigration = (string) file_get_contents($pkg . '/res/migrations/Version20261117110000.php');
$zCfg       = (array) (\Z77\Core\DI::getModuleManager()->getModuleConfig('creditor')?->get('bankMatch', []) ?? []);
check('Z1 its own migration adds creditor_profile.bank_text (nullable) and drops it again; the link table is financial\'s — creditor owns a link by the key creditor:{id}; the window of days is config, not code',
    str_contains($zMigration, 'ALTER TABLE creditor_profile ADD bank_text VARCHAR(80) DEFAULT NULL') && str_contains($zMigration, 'ALTER TABLE creditor_profile DROP bank_text')
    && $db->fetchOne("SHOW COLUMNS FROM creditor_profile LIKE 'bank_text'") !== false && $db->fetchOne("SHOW TABLES LIKE 'bank_link'") !== false
    && CreditorInvoice::bankOwnerKeyOf(17) === 'creditor:17' && \Z77\Module\Financial\Entities\BankLink::isOwnerKey(CreditorInvoice::SOURCE_TYPE, CreditorInvoice::bankOwnerKeyOf(17))
    && $zCfg === ['daysBefore' => 3, 'daysAfter' => 45, 'daysAroundPaid' => 5], json_encode($zCfg));

// the creditors: one paid by QR-bill and transfer, one paid by card in USD
$zIban   = chIban('30808', '001234567890');
$zcZett  = $newContact('Zett Lieferant AG');
$zcPixel = $newContact('Pixel Tools Inc');
$zZett   = $openProfile((int) $zcZett->getId(), ['default_account' => '6500', 'default_without_vat' => true], [['iban' => $zIban]]);
$zPixel  = $openProfile((int) $zcPixel->getId(), ['default_account' => '6500', 'default_without_vat' => true], []);
$zProfile = static fn(int $id): CreditorProfile => $wire()->getRepository(CreditorProfile::class)->find($id);
$zTooShort = caught(fn() => (new CreditorProfileService($wire(), 'Owner'))->update($zProfile($zPixel), $zProfile($zPixel)->getVersion(), ['bank_text' => 'ab'], []), \Z77\Module\Creditor\Services\InvalidCreditorProfileException::class);
(new CreditorProfileService($wire(), 'Owner'))->update($zProfile($zPixel), $zProfile($zPixel)->getVersion(), ['bank_text' => "  Some   Shop\n Online "], []);
$zTyped = $zProfile($zPixel)->getBankText();
(new CreditorProfileService($wire(), 'Owner'))->update($zProfile($zPixel), $zProfile($zPixel)->getVersion(), ['bank_text' => ''], []);
$zProfileTpl  = (string) file_get_contents($pkg . '/res/view/templates/Backend/CreditorProfileController/edit.tpl.php');
$zProfileHelp = (string) file_get_contents($pkg . '/res/view/templates/Backend/CreditorProfileController/edit.help.tpl.php');
check('Z2 the creditor field «Banktext»: typed in the creditor window (whitespace flattened), shorter than 3 characters refused, emptied = none; the window carries the field as a label only, the help explains it (section bank_text)',
    $zTooShort !== null && $zTyped === 'Some Shop Online' && $zProfile($zPixel)->getBankText() === null
    && str_contains($zProfileTpl, 'name="bank_text"') && str_contains($zProfileTpl, '>Banktext</label>') && str_contains($zProfileHelp, 'data-help-field="bank_text"') && str_contains($zProfileHelp, 'Bezugsort'));

// the bank account «Hausbank» reads statements; ONE payment method hangs on it
$zOwn = chIban('00762', '011623852957');
$em   = $wire();
(new \Z77\Module\Financial\Bank\BankAccountService($em))->save(new \Z77\Module\Financial\Entities\BankAccount(['code' => 'haus', 'label' => 'Hausbank', 'iban' => $zOwn, 'currency' => 'CHF', 'ledger_account' => '1020']));
check('Z3 no bank account reads statements yet: the bank match is not available — the journal offers nothing', !$zMatch()->available() && $zMatch()->pending() === ['rows' => [], 'more' => false]);
$em = $wire();
(new \Z77\Module\Financial\Bank\BankAccountService($em))->update((new \Z77\Module\Financial\Bank\BankAccountService($em))->find('haus'), ['statement_import' => true]);
(new PaymentMethodService($wire()))->save(new PaymentMethod(['code' => 'ebank', 'label' => 'E-Banking', 'bank_account_code' => 'haus', 'position' => 30]));

// an already POSTED invoice of the sections above gets its movement afterwards (ADR-061 decision 2)
$zPosted     = $db->fetchAssociative("SELECT id, amount, paid_on, version FROM creditor_invoice WHERE state = 'posted' AND original_amount IS NULL AND creditor_profile_id IS NOT NULL ORDER BY id DESC LIMIT 1") ?: [];
$zPostedId   = (int) ($zPosted['id'] ?? 0);
$zPostedName = (string) $fresh($zPostedId)?->getCreditor()?->getContact()?->displayName();

$zRef1 = \Z77\Shared\Payment\QrReference::withCheckDigit('21000000000313947143000911');
$zRef2 = \Z77\Shared\Payment\QrReference::withCheckDigit('21000000000313947143000922');
$zRef3 = \Z77\Shared\Payment\QrReference::withCheckDigit('21000000000313947143000933');
$zRef4 = \Z77\Shared\Payment\QrReference::withCheckDigit('21000000000313947143000944');
$zXml  = static function (string $msg, string $opening, string $from, string $to, array $entries): string {
    $balance = Money::fromDecimal($opening, 'CHF');
    $xml     = '';
    foreach ($entries as $e) {
        $credit  = (bool) ($e['credit'] ?? false);
        $amount  = Money::fromDecimal($e['amount'], 'CHF');
        $balance = $credit ? $balance->add($amount) : $balance->subtract($amount);
        $party   = isset($e['party']) ? '<RltdPties><' . ($credit ? 'Dbtr' : 'Cdtr') . '><Pty><Nm>' . $e['party'] . '</Nm></Pty></' . ($credit ? 'Dbtr' : 'Cdtr') . '>'
            . (isset($e['iban']) ? '<' . ($credit ? 'DbtrAcct' : 'CdtrAcct') . '><Id><IBAN>' . $e['iban'] . '</IBAN></Id></' . ($credit ? 'DbtrAcct' : 'CdtrAcct') . '>' : '') . '</RltdPties>' : '';
        $fx      = isset($e['fx']) ? '<AmtDtls><InstdAmt><Amt Ccy="' . $e['fx'][1] . '">' . $e['fx'][0] . '</Amt><CcyXchg><SrcCcy>' . $e['fx'][1] . '</SrcCcy><TrgtCcy>CHF</TrgtCcy><UnitCcy>' . $e['fx'][1]
            . '</UnitCcy><XchgRate>' . $e['fx'][2] . '</XchgRate></CcyXchg></InstdAmt></AmtDtls>' : '';
        $ref     = isset($e['qrr']) ? '<RmtInf><Strd><CdtrRefInf><Tp><CdOrPrtry><Prtry>QRR</Prtry></CdOrPrtry></Tp><Ref>' . $e['qrr'] . '</Ref></CdtrRefInf></Strd></RmtInf>' : '';
        $code    = explode('/', $e['code'] ?? 'PMNT/ICDT/DMCT');
        $xml    .= '<Ntry><Amt Ccy="CHF">' . $e['amount'] . '</Amt><CdtDbtInd>' . ($credit ? 'CRDT' : 'DBIT') . '</CdtDbtInd><RvslInd>false</RvslInd><Sts><Cd>BOOK</Cd></Sts>'
            . '<BookgDt><Dt>' . $e['date'] . '</Dt></BookgDt><ValDt><Dt>' . $e['date'] . '</Dt></ValDt><AcctSvcrRef>' . $e['ref'] . '</AcctSvcrRef>'
            . '<BkTxCd><Domn><Cd>' . $code[0] . '</Cd><Fmly><Cd>' . $code[1] . '</Cd><SubFmlyCd>' . $code[2] . '</SubFmlyCd></Fmly></Domn></BkTxCd>'
            . '<NtryDtls><TxDtls><Amt Ccy="CHF">' . $e['amount'] . '</Amt>' . $fx . $party . $ref . '</TxDtls></NtryDtls>'
            . (isset($e['info']) ? '<AddtlNtryInf>' . $e['info'] . '</AddtlNtryInf>' : '') . '</Ntry>';
    }
    $bal = static fn(string $type, Money $m, string $date) => '<Bal><Tp><CdOrPrtry><Cd>' . $type . '</Cd></CdOrPrtry></Tp><Amt Ccy="CHF">' . ltrim($m->toDecimal(), '-') . '</Amt><CdtDbtInd>'
        . ($m->isNegative() ? 'DBIT' : 'CRDT') . '</CdtDbtInd><Dt><Dt>' . $date . '</Dt></Dt></Bal>';

    return '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"><BkToCstmrStmt><GrpHdr><MsgId>' . $msg . '</MsgId><CreDtTm>' . $to . 'T23:00:00</CreDtTm></GrpHdr>'
        . '<Stmt><Id>S-' . $msg . '</Id><ElctrncSeqNb>1</ElctrncSeqNb><FrToDt><FrDtTm>' . $from . 'T00:00:00</FrDtTm><ToDtTm>' . $to . 'T23:59:59</ToDtTm></FrToDt>'
        . '<Acct><Id><IBAN>' . $GLOBALS['zOwnIban'] . '</IBAN></Id><Ccy>CHF</Ccy></Acct>' . $bal('OPBD', Money::fromDecimal($opening, 'CHF'), $from) . $bal('CLBD', $balance, $to) . $xml . '</Stmt></BkToCstmrStmt></Document>';
};
$GLOBALS['zOwnIban'] = $zOwn;
$zPaidOn = (string) ($zPosted['paid_on'] ?? $zDay(0));
$zEntries = [
    ['ref' => 'Z-0013', 'date' => $zDay(30), 'amount' => '41.41', 'party' => 'Zett Lieferant AG', 'iban' => $zIban],
    ['ref' => 'Z-0001', 'date' => $zDay(20), 'amount' => '250.00', 'party' => 'Zett Lieferant AG', 'iban' => $zIban, 'qrr' => $zRef1, 'code' => 'PMNT/ICDT/VCOM'],
    ['ref' => 'Z-0002', 'date' => $zDay(18), 'amount' => '99.00', 'party' => 'Zett Lieferant AG', 'iban' => $zIban, 'qrr' => $zRef2],
    ['ref' => 'Z-0003', 'date' => $zDay(15), 'amount' => '300.00', 'party' => 'Zett Lieferant AG', 'iban' => $zIban, 'qrr' => $zRef3],
    ['ref' => 'Z-0004', 'date' => $zDay(12), 'amount' => '120.00', 'party' => 'Zett Lieferant AG', 'iban' => $zIban],
    ['ref' => 'Z-0005', 'date' => $zDay(11), 'amount' => '64.50', 'party' => 'ZETT LIEFERANT'],
    ['ref' => 'Z-0006', 'date' => $zDay(10), 'amount' => '77.00', 'party' => 'Zett Lieferant AG', 'iban' => $zIban],
    ['ref' => 'Z-0007', 'date' => $zDay(9), 'amount' => '77.00', 'party' => 'Zett Lieferant AG', 'iban' => $zIban],
    ['ref' => 'Z-0008', 'date' => $zDay(8), 'amount' => '55.55', 'code' => 'PMNT/CCRD/POSD', 'info' => 'Bezugsort: IRGENDWO BERN'],
    ['ref' => 'Z-0009', 'date' => $zDay(7), 'amount' => '8.12', 'code' => 'PMNT/CCRD/POSD', 'fx' => ['9.00', 'USD', '0.902222'], 'info' => 'Bezugsort: TINYPIX AMSTERDAM, Wechselkurs 0.8800 &lt;b&gt;'],
    ['ref' => 'Z-0010', 'date' => $zDay(6), 'amount' => '10.95', 'code' => 'PMNT/CCRD/POSD', 'fx' => ['12.00', 'USD', '0.9125'], 'info' => 'Bezugsort: TINYPIX AMSTERDAM, Wechselkurs 0.9000'],
    ['ref' => 'Z-0012', 'date' => $zDay(5), 'amount' => '500.00', 'credit' => true, 'party' => 'Zett Lieferant AG', 'iban' => $zIban],
    ['ref' => 'Z-0014', 'date' => $zDay(4), 'amount' => '33.30', 'party' => 'Zett Lieferant AG', 'iban' => $zIban, 'qrr' => $zRef4],
];
usort($zEntries, static fn(array $a, array $b) => $a['date'] <=> $b['date']);
$zPostedEntry = ['ref' => 'Z-0011', 'date' => $zPaidOn, 'amount' => (string) ($zPosted['amount'] ?? '75.00'), 'party' => $zPostedName !== '' ? htmlspecialchars($zPostedName, ENT_XML1) : 'Unbekannt'];
$zEntries[]   = $zPostedEntry;
usort($zEntries, static fn(array $a, array $b) => $a['date'] <=> $b['date']);
$zFrom = min($zDay(40), $zPaidOn);
$zImported = (new \Z77\Module\Financial\Bank\BankStatementService($wire(), 'Owner'))->import($zXml('Z-MSG-1', '5000.00', $zFrom, $zDay(0), $zEntries), 'z-1.xml');
check('Z4 the statement of the bank account is read (14 movements) — nothing is linked by the import itself (a movement never links or posts by itself in the web request)',
    count($zImported) === 1 && $count('bank_movement') === 14 && $count('bank_link') === 0 && $zMatch()->available(), (string) ($zImported[0] ?? null)?->message());

// the invoices
$zHead = static fn(array $over) => $over + ['creditor' => (string) $GLOBALS['zZettId'], 'invoice_date' => $GLOBALS['zInvoiceDate'], 'due_date' => $GLOBALS['zDueDate']];
$GLOBALS['zZettId']      = $zZett;
$GLOBALS['zInvoiceDate'] = $zDay(25);
$GLOBALS['zDueDate']     = $zDay(5);
$zi1  = $newInvoice('z-ref', $zHead(['invoice_number' => 'Z-1', 'iban' => $zIban, 'reference' => $zRef1, 'amount' => '250.00', 'payment_method_code' => 'kasse']), $zOne);
$zi2  = $newInvoice('z-ref-amount', $zHead(['invoice_number' => 'Z-2', 'iban' => $zIban, 'reference' => $zRef2, 'amount' => '90.00']), $zOne);
$zi3a = $newInvoice('z-rival-a', $zHead(['invoice_number' => 'Z-3a', 'iban' => $zIban, 'reference' => $zRef3, 'amount' => '300.00']), $zOne);
$zi3b = $newInvoice('z-rival-b', $zHead(['invoice_number' => 'Z-3b', 'iban' => $zIban, 'reference' => $zRef3, 'amount' => '300.00']), $zOne);
$zBlockedFind = $zFind($zi3b);
$zBlockedTake = $zBankRefused(fn() => $zMatch()->take($zi3b, $vOf($zi3b), $zMv('Z-0003')));
$invoiceOf($wire())->release($zi3b, $vOf($zi3b));
$zi4  = $newInvoice('z-iban', $zHead(['invoice_number' => 'Z-4', 'amount' => '120.00']), $zOne);
$zi5  = $newInvoice('z-name', $zHead(['invoice_number' => 'Z-5', 'amount' => '64.50']), $zOne);
$zi6  = $newInvoice('z-two', $zHead(['invoice_number' => 'Z-6', 'amount' => '77.00']), $zOne);
$zi7  = $newInvoice('z-card', $zHead(['invoice_number' => 'Z-7', 'amount' => '55.55']), $zOne);
$zi8  = $newInvoice('z-window', $zHead(['invoice_number' => 'Z-8', 'amount' => '41.41', 'invoice_date' => $zDay(3), 'due_date' => $zDay(0)]), $zOne);
$zf1  = $newInvoice('z-usd-1', ['creditor' => (string) $zPixel, 'invoice_number' => 'PX-1', 'invoice_date' => $zDay(9), 'currency' => 'USD', 'original_amount' => '9.00'], $zOne);
$zf2  = $newInvoice('z-usd-2', ['creditor' => (string) $zPixel, 'invoice_number' => 'PX-2', 'invoice_date' => $zDay(8), 'currency' => 'USD', 'original_amount' => '12.00'], $zOne);

// level 1 — the reference
$m1 = $zFind($zi1);
$m2 = $zFind($zi2);
$m3 = $zFind($zi3a);
check('Z5 level 1 — the QR reference identical and the amount equal, exactly one movement, no other invoice claiming it: an IDENTITY; the same reference with ANOTHER amount is only a candidate; two invoices with the same reference and amount for one movement are rivals — candidates, never a guess; a BLOCKED invoice finds nothing and takes nothing',
    $m1->kind === $zKind::IDENTITY && $m1->level === 1 && $zRefs($m1) === ['Z-0001'] && $m1->proposed()?->getEntryRef() === 'Z-0001'
    && $m2->kind === $zKind::CANDIDATES && $zRefs($m2) === ['Z-0002'] && $m2->proposed() === null
    && $m3->kind === $zKind::CANDIDATES && $zRefs($m3) === ['Z-0003'] && $zFind($zi3b)->kind === $zKind::CANDIDATES
    && $zBlockedFind->kind === $zKind::NONE && str_starts_with($zBlockedTake, 'bank: ') && str_contains($zBlockedTake, 'gesperrt'),
    json_encode([$m1->kind, $m2->kind, $m3->kind, $zBlockedFind->kind, $zBlockedTake], JSON_UNESCAPED_UNICODE));

$zJob = $zMatch()->linkIdentities();
$r1   = $rowOf($zi1);
$l1   = $zLink($zi1);
check('Z6 the job links every identity by itself — and only those: «Bezahlt am» = the bank\'s BOOKING date, «Bezahlt mit» = the one payment method that hangs on the bank account (Haben = its ledger account), the link stores the movement\'s amount, basis `reference`, the invoice\'s label; the differing amount and the rivals stay unlinked; a second pass links nothing',
    $zJob['linked'] === 1 && count($zJob['notes']) === 1 && str_contains($zJob['notes'][0], 'Rechnung Z-1') && str_contains($zJob['notes'][0], '(Referenz)')
    && $r1['paid_on'] === $zDay(20) && $r1['payment_state'] === 'paid' && $r1['payment_method_code'] === 'ebank' && $r1['credit_account'] === '1020' && $r1['state'] === 'open' && $r1['amount'] === '250.00'
    && (int) ($l1['movement_id'] ?? 0) === $zMv('Z-0001') && ($l1['amount'] ?? '') === '250.00' && ($l1['basis'] ?? '') === 'reference' && ($l1['owner_type'] ?? '') === 'creditor'
    && ($l1['owner_label'] ?? '') === 'Rechnung Z-1 · Zett Lieferant AG' && ($l1['linked_by'] ?? '') === 'Owner'
    && $count('bank_link') === 1 && $rowOf($zi2)['paid_on'] === null && $rowOf($zi3a)['paid_on'] === null && $zMatch()->linkIdentities()['linked'] === 0 && $count('bank_link') === 1,
    json_encode([$zJob, $r1['paid_on'], $r1['payment_method_code'], $l1], JSON_UNESCAPED_UNICODE));
$zAmountDiffers = $zBankRefused(fn() => $zMatch()->take($zi2, $vOf($zi2), $zMv('Z-0002')));
check('Z7 taking the movement with the other amount by hand is refused in German (amount differences are not decided yet, ADR-061 consequences) — nothing written',
    str_starts_with($zAmountDiffers, 'bank: ') && str_contains($zAmountDiffers, '90.00') && str_contains($zAmountDiffers, '99.00') && str_contains($zAmountDiffers, 'Beträge müssen gleich sein')
    && $count('bank_link') === 1 && $rowOf($zi2)['paid_on'] === null, $zAmountDiffers);

// level 2 — CHF without a reference
$m4 = $zFind($zi4);
$m5 = $zFind($zi5);
$m6 = $zFind($zi6);
$m7 = $zFind($zi7);
$m8 = $zFind($zi8);
check('Z8 level 2 — CHF without reference: amount + the creditor\'s IBAN in the entry + the date window → a PROPOSAL; the creditor\'s NAME in the entry (without legal form, any case) does the same; two movements that fit → candidates, the one nearest to the due date first; a movement that fits by amount and date but does not name the creditor → a candidate only; outside the window → nothing; a credit is never offered',
    $m4->kind === $zKind::PROPOSAL && $m4->level === 2 && $zRefs($m4) === ['Z-0004']
    && $m5->kind === $zKind::PROPOSAL && $zRefs($m5) === ['Z-0005']
    && $m6->kind === $zKind::CANDIDATES && $m6->proposed() === null && $zRefs($m6) === ['Z-0007', 'Z-0006']
    && $m7->kind === $zKind::CANDIDATES && $zRefs($m7) === ['Z-0008']
    && $m8->kind === $zKind::NONE
    && $zLinks()->freeMovements(\Z77\Module\Financial\Entities\BankMovement::DEBIT, amount: Money::fromDecimal('500.00', 'CHF')) === [],
    json_encode([$m4->kind, $zRefs($m4), $m5->kind, $m6->kind, $zRefs($m6), $m7->kind, $zRefs($m7), $m8->kind]));

// level 3 — foreign currency, the «Banktext»
$f1 = $zFind($zf1);
check('Z9 level 3 — a USD invoice without CHF amount: the movement is found by ORIGINAL amount + currency + date; the card entry names no counterparty and the creditor has no «Banktext» yet → a candidate, not a proposal',
    $f1->kind === $zKind::CANDIDATES && $f1->level === 3 && $zRefs($f1) === ['Z-0009'] && $rowOf($zf1)['amount'] === null, json_encode([$f1->kind, $zRefs($f1)]));
$zMatch()->take($zf1, $vOf($zf1), $zMv('Z-0009'));
$rf1   = $rowOf($zf1);
$lf1   = $zLink($zf1);
$fInv  = $fresh($zf1);
$fLine = $db->fetchAssociative('SELECT amount FROM creditor_invoice_line WHERE invoice_id = ?', [$zf1]) ?: [];
check('Z10 picked by hand: the CHF amount comes FROM THE MOVEMENT (8.12 — what the bank debited), the single line follows, «Bezahlt am» = the booking date; the rate is the effective one (8.12 / 9.00 = 0.902222), never the «Wechselkurs 0.8800» of the entry text, and none is stored; basis `manual`; the creditor LEARNS its «Banktext» from «Bezugsort: …»',
    $rf1['amount'] === '8.12' && $rf1['original_amount'] === '9.00' && $rf1['original_currency'] === 'USD' && $rf1['exchange_rate'] === null && $rf1['paid_on'] === $zDay(7) && $rf1['payment_method_code'] === 'ebank'
    && $fInv->derivedRate() === '0.902222' && ($fLine['amount'] ?? null) === '8.12' && ($lf1['basis'] ?? '') === 'manual' && ($lf1['amount'] ?? '') === '8.12'
    && $zProfile($zPixel)->getBankText() === 'TINYPIX AMSTERDAM' && $fInv->missing($invoiceOf($wire())->linesOf($fInv)) === [],
    json_encode([$rf1['amount'], $rf1['paid_on'], $fInv->derivedRate(), $fLine, $lf1['basis'] ?? null, $zProfile($zPixel)->getBankText(), $fInv->missing($invoiceOf($wire())->linesOf($fInv))], JSON_UNESCAPED_UNICODE));
$f2 = $zFind($zf2);
$zWrongCurrency = $zBankRefused(fn() => $zMatch()->take($zf2, $vOf($zf2), $zMv('Z-0008')));
$zOtherOriginal = $newInvoice('z-usd-3', ['creditor' => (string) $zPixel, 'invoice_number' => 'PX-3', 'invoice_date' => $zDay(8), 'currency' => 'USD', 'original_amount' => '13.00'], $zOne);
$zNotSame       = $zBankRefused(fn() => $zMatch()->take($zOtherOriginal, $vOf($zOtherOriginal), $zMv('Z-0010')));
check('Z11 with the learned «Banktext» the next USD invoice of the creditor is a PROPOSAL (original amount + currency + merchant text + date); a movement with another original amount is refused («nicht dieselbe Zahlung»); a CHF card payment without an original amount may be picked by hand only when the office does so — here the server takes the bank\'s CHF',
    $f2->kind === $zKind::PROPOSAL && $f2->level === 3 && $zRefs($f2) === ['Z-0010']
    && str_contains($zNotSame, 'nicht dieselbe Zahlung') && $rowOf($zOtherOriginal)['paid_on'] === null && $zWrongCurrency === '' && $rowOf($zf2)['amount'] === '55.55',
    json_encode([$f2->kind, $zNotSame, $zWrongCurrency, $rowOf($zf2)['amount']], JSON_UNESCAPED_UNICODE));
// zf2 took the plain card payment Z-0008 by the office's own pick — release it again for the screens below
$invoiceOf($wire())->releaseMovement($zf2, $vOf($zf2));
// V5 (owner 2026-11-17) — a foreign invoice SPLIT into lines takes a movement whose CHF the lines do not add up to: refused, nothing linked
$zSplit = $newInvoice('z-usd-split', ['creditor' => (string) $zPixel, 'invoice_number' => 'PX-S', 'invoice_date' => $zDay(8), 'currency' => 'USD', 'original_amount' => '60.00'],
    [['text' => 'Teil A', 'account' => '6500', 'tax' => '-', 'amount' => '30.00'], ['text' => 'Teil B', 'account' => '6500', 'tax' => '-', 'amount' => '20.00']]);
$zSplitRefused = $zBankRefused(fn() => $invoiceOf($wire())->takeMovement($zSplit, $vOf($zSplit), $zMv('Z-0008'), \Z77\Module\Financial\Entities\BankLink::BASIS_MANUAL));
$zSplitRow     = $rowOf($zSplit);
$zSplitLink    = $zLink($zSplit);
$invoiceOf($wire())->update($zSplit, $vOf($zSplit), $head(['creditor' => (string) $zPixel, 'invoice_number' => 'PX-S', 'invoice_date' => $zDay(8), 'currency' => 'USD', 'original_amount' => '60.00']),
    [['text' => 'Teil A', 'account' => '6500', 'tax' => '-', 'amount' => '30.00'], ['text' => 'Teil B', 'account' => '6500', 'tax' => '-', 'amount' => '']]);
$invoiceOf($wire())->takeMovement($zSplit, $vOf($zSplit), $zMv('Z-0008'), \Z77\Module\Financial\Entities\BankLink::BASIS_MANUAL);
$zSplitTaken = $rowOf($zSplit);
check('V5 a USD invoice split into two lines (30.00 + 20.00 CHF) takes a card movement of CHF 55.55: refused with the sentence («… belastet; Zeilen ergeben CHF 50.00, Rechnungstotal CHF 55.55 — Differenz CHF 5.55. Zuerst die Zeilen … anpassen»), nothing linked, no CHF amount; with a line still without amount it is taken (incomplete, never a mismatch saved)',
    str_starts_with($zSplitRefused, 'bank: ') && str_contains($zSplitRefused, 'Zeilen ergeben CHF 50.00, Rechnungstotal CHF 55.55 — Differenz CHF 5.55') && str_contains($zSplitRefused, 'Zuerst die Zeilen auf CHF 55.55 anpassen')
    && $zSplitRow['amount'] === null && $zSplitLink === [] && $zSplitTaken['amount'] === '55.55' && $zLink($zSplit) !== [],
    $zSplitRefused);
$invoiceOf($wire())->releaseMovement($zSplit, $vOf($zSplit));
$invoiceOf($wire())->delete($zSplit, $vOf($zSplit));
$invoiceOf($wire())->delete($zOtherOriginal, $vOf($zOtherOriginal));

// release, the locks of a linked invoice, delete
$zPaidTyped = $refusedInvoice(fn() => $invoiceOf($wire())->update($zi1, $vOf($zi1), $head($zHead(['invoice_number' => 'Z-1', 'iban' => $zIban, 'reference' => $zRef1, 'amount' => '250.00', 'payment_method_code' => 'ebank', 'paid_on' => $zDay(1)])), $zOne));
$zAmountTyped = $refusedInvoice(fn() => $invoiceOf($wire())->update($zi1, $vOf($zi1), $head($zHead(['invoice_number' => 'Z-1', 'iban' => $zIban, 'reference' => $zRef1, 'amount' => '251.00', 'payment_method_code' => 'ebank'])), $zOne));
$invoiceOf($wire())->update($zi1, $vOf($zi1), $head($zHead(['invoice_number' => 'Z-1b', 'iban' => $zIban, 'reference' => $zRef1, 'amount' => '', 'payment_method_code' => 'ebank', 'paid_on' => $zDay(20)])), $zOne);
$zBulk = $invoiceOf($wire())->markPaid([['id' => $zi1, 'version' => $vOf($zi1)]], $zDay(0));
check('Z12 a LINKED invoice: «Bezahlt am» and the amount are the bank\'s — a typed date or amount is refused on its field («zuerst «Zuordnung lösen»»), the bulk «Bezahlt am …» names and skips it; other fields save (an empty amount keeps the bank\'s) and the list «Bewegungen» shows the new invoice number',
    str_contains($zPaidTyped['paid_on'] ?? '', 'Zuordnung lösen') && str_contains($zAmountTyped['amount'] ?? '', 'Zuordnung lösen')
    && $rowOf($zi1)['invoice_number'] === 'Z-1b' && $rowOf($zi1)['amount'] === '250.00' && $rowOf($zi1)['paid_on'] === $zDay(20)
    && $zLink($zi1)['owner_label'] === 'Rechnung Z-1b · Zett Lieferant AG'
    && $zBulk['paid'] === [] && count($zBulk['refused']) === 1 && str_contains($zBulk['refused'][0], 'kommt von der Bankbewegung'),
    json_encode([$zPaidTyped, $zAmountTyped, $zBulk['refused']], JSON_UNESCAPED_UNICODE));
$invoiceOf($wire())->releaseMovement($zi1, $vOf($zi1));
$zNoLink = $zBankRefused(fn() => $invoiceOf($wire())->releaseMovement($zi1, $vOf($zi1)));
check('Z13 «Zuordnung lösen» of an unposted invoice: the link is gone, the invoice UNPAID again (the amount stays), the movement free — the next pass finds it again; an invoice without a link has nothing to release',
    $zLink($zi1) === [] && $rowOf($zi1)['paid_on'] === null && $rowOf($zi1)['payment_state'] === 'unpaid' && $rowOf($zi1)['amount'] === '250.00'
    && $zFind($zi1)->kind === $zKind::IDENTITY && str_contains($zNoLink, 'nichts zu lösen'), $zNoLink);
$zMatch()->take($zi4, $vOf($zi4), $zMv('Z-0004'));
$zProposalBasis = $zLink($zi4)['basis'] ?? '';
$invoiceOf($wire())->delete($zi4, $vOf($zi4));
check('Z14 a confirmed proposal is stored with basis `proposal`; deleting the invoice releases its movement in the same unit of work (ADR-061 decision 10)',
    $zProposalBasis === 'proposal' && $zLink($zi4) === [] && $zLinks()->freeMovement($zMv('Z-0004')) !== null && $rowOf($zi4) === []);
$zTwice = $zBankRefused(function () use ($zMatch, $zi5, $zi6, $vOf, $zMv) {
    $zMatch()->take($zi5, $vOf($zi5), $zMv('Z-0005'));
    $zMatch()->take($zi5, $vOf($zi5), $zMv('Z-0006'));
});
$zTaken = $zBankRefused(fn() => $zMatch()->take($zi6, $vOf($zi6), $zMv('Z-0005')));
check('Z15 one movement per invoice and one invoice per movement (stage C1): another movement for a linked invoice and a linked movement for another invoice are refused in German, nothing written',
    str_starts_with($zTwice, 'bank: ') && str_starts_with($zTaken, 'bank: ') && str_contains($zTaken, 'nicht mehr frei') && $zLink($zi6) === [] && (int) $zLink($zi5)['movement_id'] === $zMv('Z-0005'),
    json_encode([$zTwice, $zTaken], JSON_UNESCAPED_UNICODE));

// the retro-link of a POSTED invoice
$zEntriesBefore = $count('journal_entry');
$zPostedBefore  = $rowOf($zPostedId);
$zp             = $zPostedId > 0 ? $zFind($zPostedId) : $zKind::none();
$zMatch()->take($zPostedId, (int) ($zPosted['version'] ?? 0), $zMv('Z-0011'));
$zPostedAfter   = $rowOf($zPostedId);
$zPostedRelease = $zBankRefused(fn() => $invoiceOf($wire())->releaseMovement($zPostedId, $vOf($zPostedId)));
check('Z16 an already POSTED invoice finds its movement (the window lies around «Bezahlt am») and is linked WITHOUT a new posting and without any change of the invoice (ADR-061 decision 2); releasing it is REFUSED with a clear sentence (the reversing entry comes with stage C2) — the link stays',
    $zPostedId > 0 && in_array('Z-0011', $zRefs($zp), true) && $count('journal_entry') === $zEntriesBefore && $zPostedAfter === $zPostedBefore && $zPostedAfter['state'] === 'posted'
    && (int) ($zLink($zPostedId)['movement_id'] ?? 0) === $zMv('Z-0011') && str_starts_with($zPostedRelease, 'bank: ') && str_contains($zPostedRelease, 'ist verbucht') && str_contains($zPostedRelease, 'zurückgenommen'),
    json_encode([$zPostedId, $zp->kind, $zRefs($zp), $zPostedRelease], JSON_UNESCAPED_UNICODE));

// the screens
$useJournal(['f_creditor' => 'Zett Lieferant'], null, false);
$host = $journalHost();
$host->listAction();
$zToolbar = $render('Backend/CreditorJournalController/toolbar', array_diff_key($host->context, ['uploadPolicy' => true]) + ['csrfToken' => 'tok-session']);
$zList    = $render('Backend/CreditorJournalController/listAction', $host->context);
check('SM-Z1 the journal: the tool «Zahlungen suchen …» opens the window (only while a bank account reads statements); a row paid from the bank carries «Bank» beside its date, the others do not',
    $host->context['bankAvailable'] === true && str_contains($zToolbar, 'data-bank-search') && str_contains($zToolbar, 'data-window-open="/backend/finance/creditor-journal/confirm-bank"') && str_contains($zToolbar, 'Zahlungen suchen …')
    && array_keys($host->context['bankLinks']) !== [] && isset($host->context['bankLinks'][$zi5]) && !isset($host->context['bankLinks'][$zi6])
    && substr_count($zList, 'data-bank-paid') === count($host->context['bankLinks']), json_encode(array_keys($host->context['bankLinks'])));

$useJournal(['_origin' => 'region:creditor-journal-find-list'], null, true);
$host = $journalHost();
$host->confirmBankAction();
$zWin     = $render('Backend/CreditorJournalController/confirmBank', $host->context + ['csrfToken' => 'tok-session']);
$zRowsOf  = static fn(array $rows): array => array_column(array_map(static fn(array $r) => ['id' => (int) $r['invoice']->getId(), 'kind' => $r['match']->kind], $rows), 'kind', 'id');
$zShown   = $zRowsOf($host->context['rows']);
$zChecked = static fn(string $html, int $invoice, int $movement, int $version): bool => preg_match('~name="take\[' . $invoice . '\]" value="' . $version . ':' . $movement . '" checked~', $html) === 1;
check('SM-Z2 the window «Zahlungen suchen …»: every unlinked invoice the bank holds something for — identities and proposals FIRST and TICKED IN ADVANCE, candidates not ticked («nicht zuordnen» is); linked invoices and invoices without a movement are not listed; labels and badges only, bank text escaped',
    str_contains($zWin, 'data-window="creditor-invoice-bank"') && str_contains($zWin, '>Übernehmen<')
    && ($zShown[$zi1] ?? '') === $zKind::IDENTITY && ($zShown[$zf2] ?? '') === $zKind::PROPOSAL && ($zShown[$zi2] ?? '') === $zKind::CANDIDATES && ($zShown[$zi6] ?? '') === $zKind::CANDIDATES && ($zShown[$zi7] ?? '') === $zKind::CANDIDATES
    && !isset($zShown[$zi5]) && !isset($zShown[$zi8]) && !isset($zShown[$zf1]) && !isset($zShown[$zPostedId])
    && array_search($zi1, array_keys($zShown), true) < array_search($zi6, array_keys($zShown), true)
    && $zChecked($zWin, $zi1, $zMv('Z-0001'), $vOf($zi1)) && $zChecked($zWin, $zf2, $zMv('Z-0010'), $vOf($zf2))
    && !$zChecked($zWin, $zi6, $zMv('Z-0006'), $vOf($zi6)) && !$zChecked($zWin, $zi6, $zMv('Z-0007'), $vOf($zi6)) && preg_match('~id="ci-bank-' . $zi6 . '-none" name="take\[' . $zi6 . '\]" value="" checked~', $zWin) === 1
    && str_contains($zWin, '>Referenz<') && str_contains($zWin, '>Vorschlag<') && str_contains($zWin, '>zur Auswahl<') && !str_contains($zWin, 'be-form__hint')
    && !str_contains($zWin, '<script') && preg_match('/<\?=\s*\$(text|party)\b/', (string) file_get_contents($pkg . '/res/view/templates/Backend/CreditorJournalController/confirmBank.tpl.php')) === 0,
    json_encode($zShown));

// POST: the two ticked ones, one pick among candidates, one «nicht zuordnen», one stale version
$useJournal([], ['csrf_token' => 'tok-session', '_origin' => 'region:creditor-journal-find-list', 'take' => [
    $zi1 => $vOf($zi1) . ':' . $zMv('Z-0001'), $zf2 => $vOf($zf2) . ':' . $zMv('Z-0010'), $zi6 => $vOf($zi6) . ':' . $zMv('Z-0007'), $zi7 => '', $zi2 => '0:' . $zMv('Z-0002'), 'x' => 'junk',
]], true);
$host    = $journalHost();
$zAnswer = $host->confirmBankAction();
$zFlash  = json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE);
check('SM-Z3 «Übernehmen»: each ticked pair is taken in its own unit of work — the reference (basis reference), the foreign proposal (CHF 10.95 from the bank, basis proposal), the pick among candidates (basis manual); «nicht zuordnen» and junk are dropped; a stale version is named and skipped; the window closes and the list reloads',
    $zAnswer instanceof \Z77\Core\Http\Response\FetchResponse && $commands($zAnswer) === ['close-window', 'refresh-region']
    && ($zLink($zi1)['basis'] ?? '') === 'reference' && ($zLink($zf2)['basis'] ?? '') === 'proposal' && $rowOf($zf2)['amount'] === '10.95' && $rowOf($zf2)['paid_on'] === $zDay(6)
    && ($zLink($zi6)['basis'] ?? '') === 'manual' && (int) $zLink($zi6)['movement_id'] === $zMv('Z-0007') && $rowOf($zi6)['paid_on'] === $zDay(9)
    && $zLink($zi7) === [] && $zLink($zi2) === [] && str_contains($zFlash, '3 Bankzahlungen zugeordnet') && str_contains($zFlash, 'Nicht zugeordnet: Rechnung ' . $zi2), $zFlash);
$useJournal([], ['csrf_token' => 'tok-session', 'take' => [$zi7 => '']], true);
$host     = $journalHost();
$zNothing = $host->confirmBankAction();
check('SM-Z4 nothing ticked: refused with one sentence, nothing written', ($envelope($zNothing)['status'] ?? '') === 'error' && str_contains(json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE), 'Keine Zahlung angekreuzt') && $zLink($zi7) === []);

// one invoice: «Zahlung suchen …» from its window; the linked window; «Zuordnung lösen»
$useJournal(['id' => (string) $zi7, '_origin' => 'window:creditor-invoice-edit'], null, true);
$host = $journalHost();
$host->confirmBankAction();
$zSingle = $render('Backend/CreditorJournalController/confirmBank', $host->context + ['csrfToken' => 'tok-session']);
$useJournal(['id' => (string) $zi8], null, true);
$host = $journalHost();
$host->confirmBankAction();
$zEmpty = $render('Backend/CreditorJournalController/confirmBank', $host->context + ['csrfToken' => 'tok-session']);
$zEmptyRows = $host->context['rows'];
$useJournal(['id' => (string) $zi7], null, true);
$host = $journalHost();
$host->editAction();
$zEditFree = $render('Backend/CreditorJournalController/edit', $host->context + ['csrfToken' => 'tok-session']);
$useJournal(['id' => (string) $zi6], null, true);
$host = $journalHost();
$host->editAction();
$zEditLinked = $render('Backend/CreditorJournalController/edit', $host->context + ['csrfToken' => 'tok-session']);
check('SM-Z5 one invoice: «Zahlung suchen …» in its window opens the candidates of THIS invoice (nothing ticked), an invoice without any says so in one sentence; a LINKED invoice shows «Bank · date · amount» under «Bezahlt am», the date and the amount read-only, and «Zuordnung lösen» instead of «Zahlung suchen …»',
    $zEmptyRows === [] && str_contains($zSingle, 'data-bank-invoice="' . $zi7 . '"') && str_contains($zSingle, 'data-bank-movement="' . $zMv('Z-0008') . '"') && substr_count($zSingle, 'data-bank-invoice=') === 1
    && str_contains($zSingle, 'Bezugsort: IRGENDWO BERN') && str_contains($zEmpty, 'data-bank-empty') && str_contains($zEmpty, 'keine freie Bewegung zu dieser Rechnung')
    && str_contains($zEditFree, 'confirm-bank?id=' . $zi7) && str_contains($zEditFree, 'Zahlung suchen …') && !str_contains($zEditFree, 'data-bank-link') && !str_contains($zEditFree, 'release-movement')
    && str_contains($zEditLinked, 'data-bank-link="' . $zMv('Z-0007') . '"') && str_contains($zEditLinked, 'Bank · ' . $today->modify('-9 days')->format('d.m.Y') . ' · 77.00')
    && str_contains($zEditLinked, 'release-movement?id=' . $zi6) && str_contains($zEditLinked, '>Zuordnung lösen<') && !str_contains($zEditLinked, 'Zahlung suchen …')
    && preg_match('~id="ci-paid-on"[^>]* readonly>~', $zEditLinked) === 1 && preg_match('~id="ci-amount"[^>]* readonly>~', $zEditLinked) === 1 && preg_match('~id="ci-paid-on"[^>]* readonly>~', $zEditFree) === 0,
    mb_substr(strip_tags($zSingle), 0, 200));
$useJournal(['id' => (string) $zi6, '_origin' => 'region:creditor-journal-find-list'], ['csrf_token' => 'tok-session', 'version' => (string) $vOf($zi6)], true);
$host     = $journalHost();
$zRelease = $host->releaseMovementAction();
$zRelFlash = json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE);
$useJournal(['id' => (string) $zPostedId], ['csrf_token' => 'tok-session', 'version' => (string) $vOf($zPostedId)], true);
$host        = $journalHost();
$zRelPosted  = $host->releaseMovementAction();
$zRelPostedF = json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE);
$useJournal(['id' => (string) $zi6], null, true);
$host   = $journalHost();
$zRelGet = $host->releaseMovementAction();
check('SM-Z6 «Zuordnung lösen» (POST with the version shown): the link is gone, the invoice unpaid again, the window closes and the list reloads; for a POSTED invoice the answer is the refusal sentence and the link stays; a GET changes nothing',
    $commands($zRelease) === ['close-window', 'refresh-region'] && $zLink($zi6) === [] && $rowOf($zi6)['paid_on'] === null && str_contains($zRelFlash, 'Zuordnung zur Bankbewegung gelöst')
    && ($envelope($zRelPosted)['status'] ?? '') === 'error' && str_contains($zRelPostedF, 'ist verbucht') && $zLink($zPostedId) !== [] && ($envelope($zRelGet)['status'] ?? '') === 'error', $zRelFlash . $zRelPostedF);

// saving an invoice whose reference stands on a free movement links it at once (level 1 in the request — one invoice, cheap)
$zi9 = (int) $intakeOf($wire())->receive(pdfBytes('z-save-ref'), 'z-save-ref.pdf')->getId();
$useJournal(['id' => (string) $zi9, '_origin' => 'region:creditor-journal-find-list'], ['csrf_token' => 'tok-session', 'version' => (string) $vOf($zi9), 'creditor' => (string) $zZett, 'iban' => $zIban, 'invoice_number' => 'Z-9',
    'invoice_date' => $zDay(25), 'due_date' => $zDay(5), 'reference' => $zRef4, 'message' => '', 'currency' => 'CHF', 'original_amount' => '', 'amount' => '33.30', 'payment_method_code' => '', 'credit_account' => '', 'paid_on' => '',
    'lines' => [['text' => '', 'account' => '6500', 'tax' => '-', 'amount' => '']]], true);
$host   = $journalHost();
$zSaved = $host->editAction();
$zSaveFlash = json_encode($host->messageService->flashes, JSON_UNESCAPED_UNICODE);
check('SM-Z7 saving an invoice whose QR reference stands on a free movement links it in the same request (level 1 only): «Bezahlt am» from the bank, the flash says so',
    $zSaved instanceof \Z77\Core\Http\Response\FetchResponse && $commands($zSaved) === ['close-window', 'refresh-region'] && $rowOf($zi9)['paid_on'] === $zDay(4)
    && ($zLink($zi9)['basis'] ?? '') === 'reference' && str_contains($zSaveFlash, 'Bankzahlung vom ' . $today->modify('-4 days')->format('d.m.Y') . ' zugeordnet (Referenz)'), $zSaveFlash);

// the job: its pass links what waits; the help explains what the screens do not say
$invoiceOf($wire())->releaseMovement($zi9, $vOf($zi9));
$zJobRun = (new \Z77\Module\Creditor\Jobs\CreditorIntakeJob($folderOf($wire()), $recognizer($wire()), $zMatch()))->run(new \Z77\Shared\Jobs\JobContext('creditor-intake', [], null, 1, time() + 50, $cronW));
$zJobNote = $zJobRun->getNote();
$zListHelp = (string) file_get_contents($pkg . '/res/view/templates/Backend/CreditorJournalController/list.help.tpl.php');
$zEditHelp = (string) file_get_contents($pkg . '/res/view/templates/Backend/CreditorJournalController/edit.help.tpl.php');
check('Z17 the job `creditor-intake` links the identities at the end of its pass (the statement upload never waits for it) and names the count; the helps explain «Zahlungen suchen …», «Zahlung suchen … / Zuordnung lösen» and «Bank» under «Bezahlt am» in everyday words',
    ($zLink($zi9)['basis'] ?? '') === 'reference' && ($zLink($zi9)['linked_by'] ?? '') === 'Owner' && str_contains((string) $zJobNote, '1 Bankzahlungen zugeordnet')
    && str_contains($zListHelp, 'data-help-field="bank"') && str_contains($zListHelp, 'Geraten wird nie') && str_contains($zEditHelp, 'data-help-field="bank"') && str_contains($zEditHelp, 'Zuordnung lösen')
    && preg_match('/owner key|BankLink|basis|Level|Identit/i', strip_tags($zListHelp . $zEditHelp)) === 0, (string) $zJobNote);

// the screen «Bewegungen» of financial names the invoice
$zLabels = $db->fetchFirstColumn('SELECT owner_label FROM bank_link ORDER BY owner_label');
check('Z18 the list «Bewegungen» gets its words from the link: «Rechnung … · creditor» for every linked invoice', count($zLabels) >= 5 && in_array('Rechnung Z-1b · Zett Lieferant AG', $zLabels, true) && in_array('Rechnung PX-1 · Pixel Tools Inc', $zLabels, true), json_encode($zLabels, JSON_UNESCAPED_UNICODE));

// ── stage C4: on a BANK-DRIVEN bank account «Bezahlt am» comes only from the bank (ADR-061 decisions 1, 2, 13) ──
$zcDay  = static fn(int $ago): string => $today->modify('-' . $ago . ' days')->format('Y-m-d');
$zcNew  = static fn(string $no, string $method): int => $newInvoice('zc4-' . strtolower($no), ['creditor' => (string) $zZett, 'invoice_number' => $no, 'amount' => '12.00', 'payment_method_code' => $method], $zOne);
$zcSave = static fn(int $id, string $no, string $method, array $more): array => $refusedInvoice(
    static fn() => $invoiceOf($wire())->update($id, $vOf($id), $head(['creditor' => (string) $zZett, 'invoice_number' => $no, 'amount' => '12.00', 'payment_method_code' => $method] + $more), $zOne)
);
$zcBank = static function (array $values) use ($wire): void {
    $em = $wire();
    (new \Z77\Module\Financial\Bank\BankAccountService($em))->update((new \Z77\Module\Financial\Bank\BankAccountService($em))->find('haus'), $values);
};
$zSaved = ['none' => 'saved'];
// typed while the account has no «Abgleich ab»: as before — and it stays when the account is switched on afterwards (decision 2)
$zcOld      = $zcNew('ZC-0', 'ebank');
$zcOldTyped = $zcSave($zcOld, 'ZC-0', 'ebank', ['paid_on' => $zcDay(2)]);
$zcBank(['reconcile_from' => $zcDay(10)]);
$zc1        = $zcNew('ZC-1', 'ebank');
$zcTyped    = $zcSave($zc1, 'ZC-1', 'ebank', ['paid_on' => $zcDay(2)]);
$zcTypedRow = $rowOf($zc1)['paid_on'] ?? null;
$zcBefore   = $zcSave($zc1, 'ZC-1', 'ebank', ['paid_on' => $zcDay(20)]);
$zcBeforeRow = $rowOf($zc1)['paid_on'] ?? null;
$zcCleared  = $zcSave($zc1, 'ZC-1', 'ebank', ['paid_on' => '']);
$zc2        = $zcNew('ZC-2', 'kasse');
$zcCash     = $zcSave($zc2, 'ZC-2', 'kasse', ['paid_on' => $zcDay(2)]);
$zcOwn      = $zcSave($zc2, 'ZC-2', 'bank', ['paid_on' => $zcDay(3)]);
$zcSwitch   = $zcSave($zc2, 'ZC-2', 'ebank', ['paid_on' => $zcDay(1)]);
$zcSwitchRow = [$rowOf($zc2)['paid_on'] ?? null, $rowOf($zc2)['payment_method_code'] ?? null];
$zcKeep     = $zcSave($zcOld, 'ZC-0', 'ebank', ['paid_on' => $zcDay(2), 'message' => 'unverändert bezahlt']);
$zcMove     = $zcSave($zcOld, 'ZC-0', 'ebank', ['paid_on' => $zcDay(3)]);
$zcOldRow   = $rowOf($zcOld)['paid_on'] ?? null;
check('Z20 «Bezahlt am» on a bank-driven account (the payment method pays through a bank account with «Kontoauszug einlesen» and «Abgleich ab»): typing a date from «Abgleich ab» on is REFUSED on the field with a German sentence naming the bank account and the date — nothing written; a date BEFORE «Abgleich ab» and emptying stay possible; other payment methods (cash, an account without statements) are unchanged; changing «Bezahlt mit» to the bank-driven account in the same save is judged with the new method; a date typed BEFORE the account was switched on stays and can be saved again unchanged (retro) — only a new date is refused',
    $zcOldTyped === $zSaved && array_keys($zcTyped) === ['paid_on'] && str_contains($zcTyped['paid_on'], 'Bankkonto «Hausbank»') && str_contains($zcTyped['paid_on'], 'seit dem ' . $today->modify('-10 days')->format('d.m.Y'))
    && str_contains($zcTyped['paid_on'], 'nur von der Bankbewegung') && str_contains($zcTyped['paid_on'], 'Zahlung suchen') && $zcTypedRow === null
    && $zcBefore === $zSaved && $zcBeforeRow === $zcDay(20) && $zcCleared === $zSaved && ($rowOf($zc1)['paid_on'] ?? null) === null
    && $zcCash === $zSaved && $zcOwn === $zSaved && array_keys($zcSwitch) === ['paid_on'] && $zcSwitchRow === [$zcDay(3), 'bank']
    && $zcKeep === $zSaved && array_keys($zcMove) === ['paid_on'] && $zcOldRow === $zcDay(2) && ($rowOf($zcOld)['message'] ?? '') === 'unverändert bezahlt',
    json_encode([$zcOldTyped, $zcTyped, $zcBefore, $zcCleared, $zcCash, $zcOwn, $zcSwitch, $zcSwitchRow, $zcKeep, $zcMove], JSON_UNESCAPED_UNICODE));

$zc3 = $zcNew('ZC-3', 'ebank');
$zc4 = $zcNew('ZC-4', 'kasse');
$zc5 = $zcNew('ZC-5', 'kasse');
$zcBulk       = $invoiceOf($wire())->markPaid([['id' => $zc3, 'version' => $vOf($zc3)], ['id' => $zc4, 'version' => $vOf($zc4)]], $zcDay(1));
$zcBulkMethod = $invoiceOf($wire())->markPaid([['id' => $zc5, 'version' => $vOf($zc5)]], $zcDay(1), 'ebank');
$zcBulkRows   = [$rowOf($zc3)['paid_on'] ?? null, $rowOf($zc4)['paid_on'] ?? null, $rowOf($zc5)['paid_on'] ?? null, $rowOf($zc5)['payment_method_code'] ?? null];
$zcBulkEarly  = $invoiceOf($wire())->markPaid([['id' => $zc3, 'version' => $vOf($zc3)]], $zcDay(20));
$zcBank(['statement_import' => false]);
$zcOff = $zcSave($zc1, 'ZC-1', 'ebank', ['paid_on' => $zcDay(2)]);
$zcBank(['statement_import' => true]);
check('Z21 the bulk «Bezahlt am …» on a bank-driven account: an invoice paid through it is NOT set and named with the sentence, the others are set; choosing the bank-driven payment method in the bulk window is refused the same way (the row keeps its own); a date before «Abgleich ab» is set; with «Kontoauszug einlesen» switched off the date can be typed again (forward only — decision 3)',
    count($zcBulk['paid']) === 1 && count($zcBulk['refused']) === 1 && str_contains($zcBulk['refused'][0], 'Rechnung ZC-3') && str_contains($zcBulk['refused'][0], 'Bankkonto «Hausbank»') && str_contains($zcBulk['refused'][0], 'Nicht gesetzt')
    && $zcBulkMethod['paid'] === [] && count($zcBulkMethod['refused']) === 1 && $zcBulkRows === [null, $zcDay(1), null, 'kasse']
    && count($zcBulkEarly['paid']) === 1 && ($rowOf($zc3)['paid_on'] ?? null) === $zcDay(20) && $zcOff === $zSaved && ($rowOf($zc1)['paid_on'] ?? null) === $zcDay(2),
    json_encode([$zcBulk['refused'], $zcBulkMethod['refused'], $zcBulkRows, $zcOff], JSON_UNESCAPED_UNICODE));

// financial's bank check asks creditor which posting belongs to which bank link (`bankEntryOwners`)
$zcOwner    = new \Z77\Module\Creditor\Services\CreditorBankEntryOwner();
$zcKeys     = $zcOwner->ownerKeys([['id' => 5, 'sourceType' => 'creditor', 'sourceRef' => '17'], ['id' => 6, 'sourceType' => 'bank', 'sourceRef' => 'haus/1 · x.xml'], ['id' => 7, 'sourceType' => 'creditor', 'sourceRef' => 'abc']]);
$zcRecon    = static fn() => new \Z77\Module\Financial\Bank\BankReconciliation($wire(), 'Owner');
$zcHaus     = static fn() => (new \Z77\Module\Financial\Bank\BankAccountService($wire()))->find('haus');
$zcRefsOn   = static function (string $from, ?string $to = null) use ($zcRecon, $zcHaus): array {
    $check = $zcRecon()->check($zcHaus(), new \DateTimeImmutable(min($from, $to ?? $from)), new \DateTimeImmutable(max($from, $to ?? $from)));

    return $check === null ? ['(no check)'] : array_column($check->entries, 'ref');
};
$zcBank(['reconcile_from' => '2000-01-01']);
$zcLinked   = $db->fetchAssociative("SELECT i.ledger_entry_ref AS ref, i.paid_on, i.credit_account FROM creditor_invoice i WHERE i.id = ?", [$zPostedId]) ?: [];
$zcUnlinked = $db->fetchAssociative("SELECT i.ledger_entry_ref AS ref, i.paid_on FROM creditor_invoice i WHERE i.state = 'posted' AND i.credit_account = '1020' AND i.ledger_entry_ref IS NOT NULL"
    . " AND NOT EXISTS (SELECT 1 FROM bank_link l WHERE l.owner_key = CONCAT('creditor:', i.id)) ORDER BY i.id LIMIT 1") ?: [];
// the invoice was posted with a TYPED date; its movement may be booked some days off — the check covers both days
$zcBooked      = (string) $db->fetchOne("SELECT m.booking_date FROM bank_link l JOIN bank_movement m ON m.id = l.movement_id WHERE l.owner_key = ?", ['creditor:' . $zPostedId]);
$zcLinkedDay   = $zcRefsOn((string) ($zcLinked['paid_on'] ?? '2000-01-01'), $zcBooked !== '' ? $zcBooked : null);
$zcUnlinkedDay = $zcRefsOn((string) ($zcUnlinked['paid_on'] ?? '2000-01-01'));
$zcBank(['reconcile_from' => '']);
check('Z22 the bank check of financial (stage C4) joins a posting with its movement through creditor: an entry of origin `creditor` / invoice id belongs to the link `creditor:{id}` (declared under `bankEntryOwners`, no read); the POSTED invoice that found its movement afterwards is no «Buchung ohne Bewegung», a posted invoice paid by hand without a movement IS one — nothing is posted anew for either',
    $zcKeys === [5 => 'creditor:17'] && \Z77\Core\DI::getModuleManager()->getModuleConfig('creditor')?->get('bankEntryOwners') === [\Z77\Module\Creditor\Services\CreditorBankEntryOwner::class]
    && ($zcLinked['ref'] ?? '') !== '' && $zLink($zPostedId) !== [] && !in_array($zcLinked['ref'], $zcLinkedDay, true) && $zcLinkedDay !== ['(no check)']
    && ($zcUnlinked['ref'] ?? '') !== '' && in_array($zcUnlinked['ref'], $zcUnlinkedDay, true),
    json_encode([$zcKeys, $zcLinked, $zcLinkedDay, $zcUnlinked, $zcUnlinkedDay], JSON_UNESCAPED_UNICODE));

// migrations down and up: by NAME to the creditor migration before this stage — that runs the down() of
// creditor_profile.bank_text AND of financial's bank_link (both newer); `migrate` brings both back.
[$zDownCode, $zDownOut] = $run(['command' => 'migrate', 'version' => 'Z77\\Module\\Creditor\\Migrations\\Version20261116100000']);
$zColDown   = $db->fetchOne("SHOW COLUMNS FROM creditor_profile LIKE 'bank_text'");
$zTableDown = $db->fetchOne("SHOW TABLES LIKE 'bank_link'");
[$zUpCode, $zUpOut] = $run(['command' => 'migrate']);
check('Z19 down(): creditor_profile.bank_text and bank_link are gone, invoices and profiles stay; up() brings both back',
    $zDownCode === 0 && $zColDown === false && $zTableDown === false && $count('creditor_invoice') > 0 && $count('creditor_profile') > 0
    && $zUpCode === 0 && $db->fetchOne("SHOW COLUMNS FROM creditor_profile LIKE 'bank_text'") !== false && $db->fetchOne("SHOW TABLES LIKE 'bank_link'") !== false, trim($zDownOut . $zUpOut));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
