<?php

namespace Z77\Module\Mandator\Entities;

use Doctrine\ORM\Mapping as ORM;
use Z77\Module\Mandator\Services\Uid;
use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Libraries\Convention\Naming;
use Z77\Shared\Traits\ArrayMappable;

/**
 * The installation's own company — the «Mandant» (owner decisions E1 / E2,
 * 2026-09-23). ONE record, read through {@see \Z77\Module\Mandator\Services\CurrentMandator}
 * and written only through {@see \Z77\Module\Mandator\Services\MandatorService}.
 *
 * What it is: the LETTERHEAD of the printed reports and letters — name,
 * address, contact data, logo —, the UID and the VAT liability (with the
 * note a document without VAT prints, `vatExemptNote`, 2026-10-22), and the
 * ledger accounts the business modules post with (E2: they moved here from
 * `financialConfig → vatAccounts` and `debtorConfig → debtorAccounts`), and — since 2026-10-21 —
 * the installation's default «Betreffname» of new documents (`defaultSubjectLabel`, read by
 * debtor's `SubjectLabelDefaults`; a debtor's own label wins).
 *
 * What it is NOT (owner, 2026-09-23): the bank connection. IBAN, bank name
 * and the creditor block of the QR-bill belong to debtor's `PaymentTarget`
 * — the account holder's name as registered with the bank often differs from
 * the company name, and a QR-bill naming another creditor than the account
 * holder is faulty. The mandator's address is merely the FALLBACK the
 * payment target's creditor block takes field by field when it is left
 * empty (`docs/topics/debtor.md`, pending). Rule of thumb: the mandator is
 * the letterhead, the payment target is the payee — the two may carry
 * different names, and that is not an error. Nor is the currency here:
 * `systemConfig → baseCurrency` is the one source (Rule 2).
 *
 * **The primary key IS the «one mandator» guard (review 2026-09-23):** the
 * id is FIXED at {@see ID}, not generated. Two first saves racing both try
 * to INSERT id 1, and the second fails on the primary key — in the
 * database, not in a PHP window between an existence check and the flush
 * (which the review measured as wide: 24 of 25 rounds ended with two rows).
 * A later multi-mandator build replaces the assigned id by a generated one
 * (one ALTER to AUTO_INCREMENT) and adds the column and the filter to every
 * table and query that belongs to a mandator; TODAY nothing filters by
 * mandator and no other table carries a `mandator_id`.
 *
 * Deliberately NOT taken over from wdv-6.2.2's `Mandator` (see the topic
 * doc for each reason): `lastInvoiceNo` / `invoiceNoPrefix` (`NumberRange`
 * numbers gaplessly under a row lock), `currentFy` / `firstDayOffFy`
 * (`FiscalYear` rows are the truth), `public`, `emailInvoicing`,
 * `deliveryAddress`, the creditor accounts (no reader yet; the closing
 * accounts of the year opening arrived with P5, 2026-10-22), and — owner,
 * review 2026-09-23 — `vatDefaultAcronym`: a default tax code has no reader
 * before articles or a shop exist, and it was the only reason this
 * letterhead module dragged `module-vat` along; it returns with an expand
 * migration when something reads it.
 *
 * Table `mandator` — the mapping IS the table definition; the module's
 * migration creates it identically, so `z77-db diff` sees no change.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'mandator')]
class Mandator
{
    use ArrayMappable;

    /** The one row's id — assigned, never generated (the «one mandator» guard lives in the primary key). */
    public const ID = 1;

    public const NAME_LENGTH     = 120;
    public const SUFFIX_LENGTH   = 120;
    public const STREET_LENGTH   = 120;
    public const HOUSE_NO_LENGTH = 16;
    public const ZIP_LENGTH      = 16;
    public const CITY_LENGTH     = 70;
    public const EMAIL_LENGTH    = 190;
    public const PHONE_LENGTH    = 40;
    public const WEBSITE_LENGTH  = 190;
    public const LOGO_LENGTH     = 255;

    /** The default «Betreffname» — debtor's `SubjectLabel::LENGTH`, repeated, not imported: the mandator knows no debtor. */
    public const SUBJECT_LABEL_LENGTH = 80;

    /** The note a document without VAT prints — one sentence, the column's width (debtor's `invoice.vat_note` has the same). */
    public const VAT_EXEMPT_NOTE_LENGTH = 255;

    /** `CHE-123.456.789` — the canonical form {@see Uid::normalize()} writes. */
    public const UID_LENGTH = 15;

    /** The longest account number financial's `Account::NUMBER_LENGTH` holds — repeated, not imported: financial may be absent. */
    public const ACCOUNT_NUMBER_LENGTH = 10;

    /**
     * The ledger accounts this record carries, key → property. The KEY is
     * what the two readers name (`LedgerService::vatAccountFor()` maps a tax
     * CATEGORY onto `vat-*`, debtor's `DebtorAccounts` maps its keys onto the
     * first five); the property is the column `account_{key}` in snake_case
     * ({@see accountField()}). A key is added here when a module gains a
     * READER for it — creditor accounts arrive with their module. The two
     * year-opening accounts (`opening-balance`, `annual-result`, P5 2026-10-22)
     * are read by financial's `YearOpeningService`; the two bank accounts
     * (`private`, `bank-transfer`, ADR-061 stage C4, 2026-11-17) by financial's
     * «Geschäftlich buchen …» and the bank check, through
     * `LedgerService::bankPostingAccount()`.
     */
    public const ACCOUNT_KEYS = [
        'receivable'         => 'accountReceivable',
        'discount'           => 'accountDiscount',
        'loss'               => 'accountLoss',
        'rounding'           => 'accountRounding',
        'dunning-fee'        => 'accountDunningFee',
        'vat-input-material' => 'accountVatInputMaterial',
        'vat-input-other'    => 'accountVatInputOther',
        'vat-owed'           => 'accountVatOwed',
        'opening-balance'    => 'accountOpeningBalance',
        'annual-result'      => 'accountAnnualResult',
        'private'            => 'accountPrivate',
        'bank-transfer'      => 'accountBankTransfer',
    ];

    /** Assigned ({@see ID}), never generated — see the class docblock. No setter. */
    #[ORM\Id, ORM\Column]
    private int $id = self::ID;

    // ── letterhead ──────────────────────────────────────────────────────

    /** The company name as printed. */
    #[ORM\Column(length: self::NAME_LENGTH)]
    #[Clean('text')]
    private string $name = '';

    /** Two free lines under the name (a department, a «c/o», a second name). */
    #[ORM\Column(name: 'address_suffix_one', length: self::SUFFIX_LENGTH)]
    #[Clean('text')]
    private string $addressSuffixOne = '';

    #[ORM\Column(name: 'address_suffix_two', length: self::SUFFIX_LENGTH)]
    #[Clean('text')]
    private string $addressSuffixTwo = '';

    #[ORM\Column(length: self::STREET_LENGTH)]
    #[Clean('text')]
    private string $street = '';

    #[ORM\Column(name: 'house_no', length: self::HOUSE_NO_LENGTH)]
    #[Clean('text')]
    private string $houseNo = '';

    #[ORM\Column(length: self::ZIP_LENGTH)]
    #[Clean('text')]
    private string $zip = '';

    #[ORM\Column(length: self::CITY_LENGTH)]
    #[Clean('text')]
    private string $city = '';

    /** ISO 3166-1 alpha-2, upper-case — the shape module-contact's `Address::$country` has. */
    #[ORM\Column(length: 2)]
    #[Clean('text')]
    private string $country = 'CH';

    #[ORM\Column(length: self::EMAIL_LENGTH)]
    #[Clean('email')]
    private string $email = '';

    #[ORM\Column(length: self::PHONE_LENGTH)]
    #[Clean('text')]
    private string $phone = '';

    #[ORM\Column(length: self::WEBSITE_LENGTH)]
    #[Clean('text')]
    private string $website = '';

    // ── the old logo path fields — UNUSED since 2026-11-20 (owner «ja los») ──
    //
    // `logo_path` (print) and `email_logo_path` (e-mail, login; 2026-10-23) named a project file. The
    // logo window's DMS documents (below) replaced them: no form field, no validator, no getter, no
    // setter, no reader any more — a posted `logo_path` does nothing (no `set{Key}()`). The columns
    // stay mapped so the schema tool proposes no DROP: ADR-039 expand/contract, the DROP is a later
    // release's CONTRACT step (mandator.md pending MANDATOR-LOGO-PATH-DROP-001).
    #[ORM\Column(name: 'logo_path', length: self::LOGO_LENGTH)]
    private string $logoPath = '';

    #[ORM\Column(name: 'email_logo_path', length: self::LOGO_LENGTH, options: ['default' => ''])]
    private string $emailLogoPath = '';

    // ── the logo set-up (2026-11-20, migration `Version20261120150000`) ──
    //
    // The DMS documents the logo window stored, one per use (`Logo\LogoUses`): the server derives each
    // variant from the upload (`Logo\LogoImage`) and stores it in the mandator's partitions — print,
    // e-mail and login PROTECTED («Firmenlogo», DMS key `mandator`), the website variant in the PUBLIC
    // «Website-Logo» (`mandator-web`). null = none — the only source of the logo (`Logo\LogoFiles`; the
    // login falls back to the e-mail document).
    //
    // SERVER-CONTROLLED: no `#[Clean]` and deliberately NO setter named after the property — the
    // screen's `BodyCleaner` hands EVERY property key to `mapFromArray()`, which calls `set{Key}()`;
    // without such a setter a posted `print_logo_document_id` does nothing. Written only through
    // `MandatorService::updateLogos()` ({@see setLogoDocument()}).

    #[ORM\Column(name: 'print_logo_document_id', nullable: true)]
    private ?int $printLogoDocumentId = null;

    #[ORM\Column(name: 'email_logo_document_id', nullable: true)]
    private ?int $emailLogoDocumentId = null;

    #[ORM\Column(name: 'login_logo_document_id', nullable: true)]
    private ?int $loginLogoDocumentId = null;

    #[ORM\Column(name: 'web_logo_document_id', nullable: true)]
    private ?int $webLogoDocumentId = null;

    /**
     * «Später / kein Logo» (2026-11-20): the setup step `mandator.logo` stays silent although a logo
     * is missing. Cleared again when a logo is saved through the window. Server-controlled like the
     * ids (no setter named after the property — {@see dismissLogoStep()}).
     */
    #[ORM\Column(name: 'logo_step_dismissed', options: ['default' => false])]
    private bool $logoStepDismissed = false;

    // ── UID and VAT ─────────────────────────────────────────────────────

    /** The Swiss Unternehmens-Identifikationsnummer, canonical `CHE-123.456.789`; empty allowed. */
    #[ORM\Column(length: self::UID_LENGTH)]
    #[Clean('text')]
    private string $uid = '';

    /**
     * Whether the company is liable to VAT (mehrwertsteuerpflichtig). BINDING
     * for documents since the owner decision of 2026-10-21: not liable → debtor
     * computes, books and prints its documents (and module-order its quotes and
     * confirmations) WITHOUT VAT — the rule is `InvoicingService::chargesVat()`
     * in module-debtor (this module knows no reader). The letterhead appends
     * ` MWST` to the UID only when set. Still open: the one-line entry's MwSt
     * row and the VAT return (`mandator.md` pending).
     */
    #[ORM\Column(name: 'liable_to_vat')]
    #[Clean('bool')]
    private bool $liableToVat = false;

    /**
     * The NOTE a document without VAT prints (owner decision 2026-10-22): while the company is not
     * liable (`$liableToVat` false) every invoice, credit note, quote and order confirmation says so
     * — usually «Keine MWST — der Rechnungssteller ist nicht mehrwertsteuerpflichtig.» Maintained
     * here, next to the switch; '' = no note. Read through {@see documentVatNote()}, which applies
     * the switch: debtor's `InvoicingService::compose()` snapshots it on the document
     * (`invoice.vat_note`), module-order's `OrderPdf` prints the CURRENT text on its transient
     * preview. Migration `Version20261022100000`.
     */
    #[ORM\Column(name: 'vat_exempt_note', length: self::VAT_EXEMPT_NOTE_LENGTH, options: ['default' => ''])]
    #[Clean('text')]
    private string $vatExemptNote = '';

    // ── ledger accounts (E2) — '' = not set, refused at the point of use ─

    #[ORM\Column(name: 'account_receivable', length: self::ACCOUNT_NUMBER_LENGTH)]
    #[Clean('text')]
    private string $accountReceivable = '';

    #[ORM\Column(name: 'account_discount', length: self::ACCOUNT_NUMBER_LENGTH)]
    #[Clean('text')]
    private string $accountDiscount = '';

    #[ORM\Column(name: 'account_loss', length: self::ACCOUNT_NUMBER_LENGTH)]
    #[Clean('text')]
    private string $accountLoss = '';

    #[ORM\Column(name: 'account_rounding', length: self::ACCOUNT_NUMBER_LENGTH)]
    #[Clean('text')]
    private string $accountRounding = '';

    #[ORM\Column(name: 'account_dunning_fee', length: self::ACCOUNT_NUMBER_LENGTH)]
    #[Clean('text')]
    private string $accountDunningFee = '';

    #[ORM\Column(name: 'account_vat_input_material', length: self::ACCOUNT_NUMBER_LENGTH)]
    #[Clean('text')]
    private string $accountVatInputMaterial = '';

    #[ORM\Column(name: 'account_vat_input_other', length: self::ACCOUNT_NUMBER_LENGTH)]
    #[Clean('text')]
    private string $accountVatInputOther = '';

    #[ORM\Column(name: 'account_vat_owed', length: self::ACCOUNT_NUMBER_LENGTH)]
    #[Clean('text')]
    private string $accountVatOwed = '';

    // The year opening (P5, owner 2026-10-22, migration `Version20261022140000`): the column
    // defaults give an EXISTING record the KMU numbers; a new one is pre-filled by `MandatorAccounts`.
    #[ORM\Column(name: 'account_opening_balance', length: self::ACCOUNT_NUMBER_LENGTH, options: ['default' => '9100'])]
    #[Clean('text')]
    private string $accountOpeningBalance = '';

    #[ORM\Column(name: 'account_annual_result', length: self::ACCOUNT_NUMBER_LENGTH, options: ['default' => '2979'])]
    #[Clean('text')]
    private string $accountAnnualResult = '';

    // Bank-driven bookkeeping (ADR-061 decision 17, stage C4, migration `Version20261117190000`):
    // «Privat» (Privatbezug / Privateinlage) and the transfer clearing account between two own
    // business bank accounts. NO default — a chart names them differently (or not at all); empty
    // = the choice is refused with a sentence naming this record.
    #[ORM\Column(name: 'account_private', length: self::ACCOUNT_NUMBER_LENGTH, options: ['default' => ''])]
    #[Clean('text')]
    private string $accountPrivate = '';

    #[ORM\Column(name: 'account_bank_transfer', length: self::ACCOUNT_NUMBER_LENGTH, options: ['default' => ''])]
    #[Clean('text')]
    private string $accountBankTransfer = '';

    // ── documents ───────────────────────────────────────────────────────

    /**
     * The default «Betreffname» of a NEW order or invoice (owner decision 2026-10-21) — printed in
     * front of the subject, «Projekt: …»; a debtor's own label (`debtor_profile.default_subject_label`)
     * wins. '' = none (the behaviour before). Read through debtor's `SubjectLabelDefaults`, never
     * printed from here. Migration `Version20261021100000`.
     */
    #[ORM\Column(name: 'default_subject_label', length: self::SUBJECT_LABEL_LENGTH, options: ['default' => ''])]
    #[Clean('text')]
    private string $defaultSubjectLabel = '';

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    /** The form field / column name of an account key: `dunning-fee` → `account_dunning_fee` (Rule 5: through `Naming`). */
    public static function accountField(string $key): string
    {
        self::assertAccountKey($key);

        return 'account_' . Naming::toSnakeCase($key);
    }

    /**
     * The account number stored for $key, trimmed — '' when none is set.
     * Trimmed here so a value written past the setter (SQL) never reaches a
     * reader as whitespace.
     *
     * @throws \InvalidArgumentException an unknown key (a programming error, not a configuration one)
     */
    public function account(string $key): string
    {
        self::assertAccountKey($key);

        return trim($this->{self::ACCOUNT_KEYS[$key]});
    }

    private static function assertAccountKey(string $key): void
    {
        if (!isset(self::ACCOUNT_KEYS[$key])) {
            throw new \InvalidArgumentException("Unknown mandator account key '{$key}' — one of " . implode(', ', array_keys(self::ACCOUNT_KEYS)));
        }
    }

    public function getId(): int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getAddressSuffixOne(): string { return $this->addressSuffixOne; }
    public function getAddressSuffixTwo(): string { return $this->addressSuffixTwo; }
    public function getStreet(): string { return $this->street; }
    public function getHouseNo(): string { return $this->houseNo; }
    public function getZip(): string { return $this->zip; }
    public function getCity(): string { return $this->city; }
    public function getCountry(): string { return $this->country; }
    public function getEmail(): string { return $this->email; }
    public function getPhone(): string { return $this->phone; }
    public function getWebsite(): string { return $this->website; }
    public function getUid(): string { return $this->uid; }
    public function isLiableToVat(): bool { return $this->liableToVat; }
    public function getVatExemptNote(): string { return $this->vatExemptNote; }
    public function getDefaultSubjectLabel(): string { return $this->defaultSubjectLabel; }
    public function isLogoStepDismissed(): bool { return $this->logoStepDismissed; }

    /** The uses a logo document is stored for — `Logo\LogoUses::ALL`, repeated here so the entity names no service. */
    public const LOGO_USES = ['print', 'email', 'login', 'web'];

    /**
     * The DMS document the logo window stored for `$use` (print, email, login, web), or null.
     *
     * @throws \InvalidArgumentException an unknown use (a programming error)
     */
    public function logoDocument(string $use): ?int
    {
        return $this->{self::logoProperty($use)};
    }

    /** Server-side only — through `MandatorService::updateLogos()`; never reached by a form post (see the column comment). */
    public function setLogoDocument(string $use, ?int $documentId): void
    {
        $this->{self::logoProperty($use)} = $documentId !== null && $documentId > 0 ? $documentId : null;
    }

    /** Server-side only — through `MandatorService::updateLogos()`. */
    public function dismissLogoStep(bool $dismissed): void
    {
        $this->logoStepDismissed = $dismissed;
    }

    private static function logoProperty(string $use): string
    {
        if (!in_array($use, self::LOGO_USES, true)) {
            throw new \InvalidArgumentException("Unknown logo use '{$use}' — one of " . implode(', ', self::LOGO_USES));
        }

        return $use . 'LogoDocumentId';
    }

    /**
     * The note a document WITHOUT VAT prints, or null — the ONE rule for both readers (debtor's
     * `compose()`, order's `OrderPdf`): the text while the company is NOT liable to VAT and a text
     * is stored; null while liable (the sentence would be false) or without a text. A document
     * computed with VAT never asks.
     */
    public function documentVatNote(): ?string
    {
        return !$this->liableToVat && $this->vatExemptNote !== '' ? $this->vatExemptNote : null;
    }

    public function setName(string $name): void { $this->name = $name; }
    public function setAddressSuffixOne(string $line): void { $this->addressSuffixOne = $line; }
    public function setAddressSuffixTwo(string $line): void { $this->addressSuffixTwo = $line; }
    public function setStreet(string $street): void { $this->street = $street; }
    public function setHouseNo(string $houseNo): void { $this->houseNo = $houseNo; }
    public function setZip(string $zip): void { $this->zip = $zip; }
    public function setCity(string $city): void { $this->city = $city; }
    public function setCountry(string $country): void { $this->country = mb_strtoupper(trim($country)); }
    public function setEmail(string $email): void { $this->email = mb_strtolower(trim($email)); }
    public function setPhone(string $phone): void { $this->phone = $phone; }
    public function setWebsite(string $website): void { $this->website = trim($website); }
    public function setUid(string $uid): void { $this->uid = Uid::normalize($uid); }
    public function setLiableToVat(bool $liable): void { $this->liableToVat = $liable; }
    /** Trimmed, whitespace collapsed to one line — the note is one printed sentence. */
    public function setVatExemptNote(?string $note): void
    {
        $this->vatExemptNote = trim((string) preg_replace('/\s+/u', ' ', (string) $note));
    }
    /** Trimmed, one line, trailing colons stripped — debtor's `SubjectLabel::normalize()` rule, repeated (the print adds «: »). */
    public function setDefaultSubjectLabel(?string $label): void
    {
        $this->defaultSubjectLabel = (string) preg_replace('/[\s:]+$/u', '', trim((string) preg_replace('/\s+/u', ' ', (string) $label)));
    }
    // The account setters exist for `mapFromArray()` (the form posts `account_{key}`); READ through account($key).
    public function setAccountReceivable(string $number): void { $this->accountReceivable = trim($number); }
    public function setAccountDiscount(string $number): void { $this->accountDiscount = trim($number); }
    public function setAccountLoss(string $number): void { $this->accountLoss = trim($number); }
    public function setAccountRounding(string $number): void { $this->accountRounding = trim($number); }
    public function setAccountDunningFee(string $number): void { $this->accountDunningFee = trim($number); }
    public function setAccountVatInputMaterial(string $number): void { $this->accountVatInputMaterial = trim($number); }
    public function setAccountVatInputOther(string $number): void { $this->accountVatInputOther = trim($number); }
    public function setAccountVatOwed(string $number): void { $this->accountVatOwed = trim($number); }
    public function setAccountOpeningBalance(string $number): void { $this->accountOpeningBalance = trim($number); }
    public function setAccountAnnualResult(string $number): void { $this->accountAnnualResult = trim($number); }
    public function setAccountPrivate(string $number): void { $this->accountPrivate = trim($number); }
    public function setAccountBankTransfer(string $number): void { $this->accountBankTransfer = trim($number); }
}
