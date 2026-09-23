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
 * address, contact data, logo —, the UID and the VAT liability, and the
 * ledger accounts the business modules post with (E2: they moved here from
 * `financialConfig → vatAccounts` and `debtorConfig → debtorAccounts`).
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
 * `deliveryAddress`, the creditor and closing accounts (no reader yet — the
 * chart does not even carry the class-9 accounts before P5), and — owner,
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
     * READER for it — creditor and closing accounts arrive with their
     * modules (P5, a creditor module).
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

    /**
     * Where the logo file lies, relative to the project root, forward
     * slashes — stored as given, resolved by whatever prints it (the PDF of
     * P3 part 3). Empty = no logo.
     */
    #[ORM\Column(name: 'logo_path', length: self::LOGO_LENGTH)]
    #[Clean('text')]
    private string $logoPath = '';

    // ── UID and VAT ─────────────────────────────────────────────────────

    /** The Swiss Unternehmens-Identifikationsnummer, canonical `CHE-123.456.789`; empty allowed. */
    #[ORM\Column(length: self::UID_LENGTH)]
    #[Clean('text')]
    private string $uid = '';

    /**
     * Whether the company is liable to VAT (mehrwertsteuerpflichtig). A
     * FLAG only in this state: what it switches off (the VAT lines of an
     * invoice, the MwSt row of the one-line entry) is an owner decision
     * still open — see the topic doc's pending.
     */
    #[ORM\Column(name: 'liable_to_vat')]
    #[Clean('bool')]
    private bool $liableToVat = false;

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
    public function getLogoPath(): string { return $this->logoPath; }
    public function getUid(): string { return $this->uid; }
    public function isLiableToVat(): bool { return $this->liableToVat; }

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
    public function setLogoPath(string $path): void { $this->logoPath = str_replace('\\', '/', trim($path)); }
    public function setUid(string $uid): void { $this->uid = Uid::normalize($uid); }
    public function setLiableToVat(bool $liable): void { $this->liableToVat = $liable; }
    // The account setters exist for `mapFromArray()` (the form posts `account_{key}`); READ through account($key).
    public function setAccountReceivable(string $number): void { $this->accountReceivable = trim($number); }
    public function setAccountDiscount(string $number): void { $this->accountDiscount = trim($number); }
    public function setAccountLoss(string $number): void { $this->accountLoss = trim($number); }
    public function setAccountRounding(string $number): void { $this->accountRounding = trim($number); }
    public function setAccountDunningFee(string $number): void { $this->accountDunningFee = trim($number); }
    public function setAccountVatInputMaterial(string $number): void { $this->accountVatInputMaterial = trim($number); }
    public function setAccountVatInputOther(string $number): void { $this->accountVatInputOther = trim($number); }
    public function setAccountVatOwed(string $number): void { $this->accountVatOwed = trim($number); }
}
