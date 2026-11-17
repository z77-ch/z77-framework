<?php

namespace Z77\Module\Creditor\Entities;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Mandator\Services\Uid;
use Z77\Module\Receipt\Entities\PaymentMethod;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * The creditor-specific part of a contact (creditor plan §2.2, the debtor model): there is no
 * «supplier record» — there is one {@see Contact} (name, address, language, e-mail live there, a
 * supplier who is also a customer is ONE party, Rule 2), and this row records what PAYABLES need
 * to know about it. Keyed by the contact's id, in creditor's own table: module-contact knows no
 * module, so not one column of this lives on `contact`.
 *
 * **At most one profile per contact** — `uniq_creditor_profile_contact`. The validator asks first
 * (field error), the index decides under a race. No creditor number (plan §2.2: pain.001 needs
 * none).
 *
 * What is on it:
 *
 *   - `uid` — the supplier's UID (`CHE-123.456.789`, module-mandator's {@see Uid}), optional; NULL
 *     = none. Unique (`uniq_creditor_profile_uid`, several NULLs allowed): recognition (stage 2)
 *     takes an exact UID match over the IBAN, so one UID must name one creditor;
 *   - the DEFAULTS a new invoice line of this creditor starts with (plan §5): `default_account`
 *     (an account NUMBER of the chart), the VAT decision — `default_tax_code` (module-vat's code)
 *     OR `default_without_vat` —, and `default_payment_method_code` (module-receipt's
 *     {@see PaymentMethod} code: «Bezahlt mit», the credit side). All referenced by number / code
 *     (ADR-043 decision 19), no foreign key;
 *   - `defaults_set_at` / `defaults_set_by` — WHEN the defaults were decided and by whom; NULL = no
 *     defaults yet. The first invoice of the creditor saved complete sets them while NULL (built in
 *     stage 1c); afterwards only «als Standard übernehmen» or THIS profile's window changes them
 *     (`CreditorProfileService::update()` stamps on a change, clears when every default is
 *     emptied);
 *   - `bank_text` — «Banktext» (ADR-061 stage C1): how the BANK names this creditor on the
 *     statement when the entry carries no counterparty — the merchant text of a card or TWINT
 *     payment («Bezugsort: …»). A text key the bank match looks for in a movement's text; typed
 *     in the creditor window or learned from the first movement the owner assigns by hand
 *     (`CreditorInvoiceService::takeMovement()`); NULL = none;
 *   - `default_line_text` — «Kontierungstext» (owner 2026-11-17): the STANDARD line text, set by
 *     «als Standard übernehmen» in the invoice window or typed in the creditor window; the
 *     recognition ALWAYS puts it on an empty line (before the learned column header, the slip's
 *     message, the AI's text). Not one of the stamped defaults ({@see defaultsKey()});
 *   - `last_line_text` — server-controlled: the first line's text of the invoice the owner saved
 *     last — the recognition's fallback when nothing else gives a text;
 *   - `active` — false = no longer offered; existing invoices keep resolving;
 *   - `version` — the optimistic lock: the window posts the version it showed;
 *     `CreditorProfileService::update()` compares it under `FOR UPDATE` and BUMPS it on every
 *     write (also when only the IBANs changed, which leave this row untouched — so it is a plain
 *     column the service counts, not Doctrine's `ORM\Version`).
 *
 * The IBANs are {@see CreditorIban} rows (several per creditor, an IBAN may be shared between
 * creditors). A profile is DELETED only through the contact editor's role «Kreditor» off, while
 * nothing references it; otherwise DEACTIVATED.
 *
 * Table `creditor_profile` — the mapping IS the table definition; `Version20261115100000` creates
 * it identically, so `z77-db diff` sees no change.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'creditor_profile')]
#[ORM\UniqueConstraint(name: CreditorProfile::UNIQUE_CONTACT, columns: ['contact_id'])]
#[ORM\UniqueConstraint(name: CreditorProfile::UNIQUE_UID, columns: ['uid'])]
class CreditorProfile
{
    use ArrayMappable;

    /** The unique index on `contact_id` — `CreditorProfileService` recognises its violation by this name. */
    public const UNIQUE_CONTACT = 'uniq_creditor_profile_contact';

    /** The unique index on `uid` — one UID names one creditor. */
    public const UNIQUE_UID = 'uniq_creditor_profile_uid';

    /** `CHE-123.456.789` — the canonical UID ({@see Uid::normalize()}). */
    public const UID_LENGTH = 15;

    /** «Banktext»: long enough for a merchant line, short enough to stay a key. */
    public const BANK_TEXT_LENGTH = 80;
    /** Shorter than this a «Banktext» would be found in almost any bank text. */
    public const BANK_TEXT_MIN = 3;

    /** The line-text columns — as long as an invoice line's text (`CreditorInvoiceLine::TEXT_LENGTH`). */
    public const LINE_TEXT_LENGTH = CreditorInvoiceLine::TEXT_LENGTH;

    /** The payment-method code column — module-receipt's code length. */
    public const PAYMENT_METHOD_LENGTH = Receipt::TEMPLATE_LENGTH;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /** The party this profile belongs to — one profile per contact, never changed. */
    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'contact_id', nullable: false)]
    private ?Contact $contact = null;

    #[ORM\Column(length: self::UID_LENGTH, nullable: true)]
    #[Clean('text')]
    private ?string $uid = null;

    #[ORM\Column(name: 'default_account', length: Account::NUMBER_LENGTH, nullable: true)]
    #[Clean('text')]
    private ?string $defaultAccount = null;

    #[ORM\Column(name: 'default_tax_code', length: JournalLine::TAX_CODE_LENGTH, nullable: true)]
    #[Clean('text')]
    private ?string $defaultTaxCode = null;

    /** True = the creditor's lines are booked without VAT (and `default_tax_code` is NULL). */
    #[ORM\Column(name: 'default_without_vat', options: ['default' => false])]
    #[Clean('bool')]
    private bool $defaultWithoutVat = false;

    #[ORM\Column(name: 'default_payment_method_code', length: self::PAYMENT_METHOD_LENGTH, nullable: true)]
    #[Clean('ident')]
    private ?string $defaultPaymentMethodCode = null;

    /** «Banktext» — the merchant text the bank writes for this creditor; NULL = none. */
    #[ORM\Column(name: 'bank_text', length: self::BANK_TEXT_LENGTH, nullable: true)]
    #[Clean('text')]
    private ?string $bankText = null;

    /** «Kontierungstext» — the STANDARD line text (owner 2026-11-17); NULL = none. */
    #[ORM\Column(name: 'default_line_text', length: self::LINE_TEXT_LENGTH, nullable: true)]
    #[Clean('text')]
    private ?string $defaultLineText = null;

    /** Server-controlled — the first line's text of the invoice the owner saved last ({@see noteLastLineText()}). */
    #[ORM\Column(name: 'last_line_text', length: self::LINE_TEXT_LENGTH, nullable: true)]
    private ?string $lastLineText = null;

    /** Server-controlled — {@see stampDefaults()} / {@see clearDefaultsStamp()}. */
    #[ORM\Column(name: 'defaults_set_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $defaultsSetAt = null;

    /** Server-controlled — who decided the defaults. */
    #[ORM\Column(name: 'defaults_set_by', length: JournalEntry::ACTOR_LENGTH, nullable: true)]
    private ?string $defaultsSetBy = null;

    /** False = no longer offered for new invoices; existing ones keep resolving. */
    #[ORM\Column]
    #[Clean('bool')]
    private bool $active = true;

    /** Server-controlled — the service bumps it on every write ({@see bumpVersion()}). */
    #[ORM\Column(options: ['default' => 1])]
    private int $version = 1;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getContact(): ?Contact { return $this->contact; }
    public function getUid(): ?string { return $this->uid; }
    public function getDefaultAccount(): ?string { return $this->defaultAccount; }
    public function getDefaultTaxCode(): ?string { return $this->defaultTaxCode; }
    public function isDefaultWithoutVat(): bool { return $this->defaultWithoutVat; }
    public function getDefaultPaymentMethodCode(): ?string { return $this->defaultPaymentMethodCode; }
    public function getBankText(): ?string { return $this->bankText; }
    public function getDefaultLineText(): ?string { return $this->defaultLineText; }
    public function getLastLineText(): ?string { return $this->lastLineText; }
    public function getDefaultsSetAt(): ?\DateTimeImmutable { return $this->defaultsSetAt; }
    public function getDefaultsSetBy(): ?string { return $this->defaultsSetBy; }
    public function isActive(): bool { return $this->active; }
    public function getVersion(): int { return $this->version; }

    /** Whether any default is filled — an account, a VAT decision or a payment method. */
    public function hasDefaultValues(): bool
    {
        return $this->defaultAccount !== null || $this->defaultTaxCode !== null || $this->defaultWithoutVat || $this->defaultPaymentMethodCode !== null;
    }

    /** The four default values as one comparable tuple — the service stamps when it changes. */
    public function defaultsKey(): string
    {
        return implode('|', [(string) $this->defaultAccount, (string) $this->defaultTaxCode, $this->defaultWithoutVat ? '1' : '0', (string) $this->defaultPaymentMethodCode]);
    }

    public function setContact(?Contact $contact): void { $this->contact = $contact; }
    /** Normalised to `CHE-ddd.ddd.ddd` when it is one ({@see Uid::normalize()}); '' = none. */
    public function setUid(?string $uid): void { $uid = Uid::normalize((string) $uid); $this->uid = $uid === '' ? null : $uid; }
    public function setDefaultAccount(?string $number): void { $number = trim((string) $number); $this->defaultAccount = $number === '' ? null : $number; }
    public function setDefaultTaxCode(?string $code): void { $code = TaxCode::normalizeCode((string) $code); $this->defaultTaxCode = $code === '' ? null : $code; }
    public function setDefaultWithoutVat(bool $without): void { $this->defaultWithoutVat = $without; }
    public function setDefaultPaymentMethodCode(?string $code): void { $code = PaymentMethod::normalizeCode((string) $code); $this->defaultPaymentMethodCode = $code === '' ? null : $code; }
    /** Whitespace collapsed; '' = none. The length is the validator's (the window names it). */
    public function setBankText(?string $text): void { $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text)); $this->bankText = $text === '' ? null : $text; }
    public function setActive(bool $active): void { $this->active = $active; }
    /** Control characters and runs of whitespace to one space; '' = none. The length is the validator's. */
    public function setDefaultLineText(?string $text): void { $text = self::lineText($text); $this->defaultLineText = $text === '' ? null : $text; }

    /** The owner saved an invoice of this creditor with $text on its first line — '' leaves the last one (not a form field: no setter). */
    public function noteLastLineText(string $text): void
    {
        $text = mb_substr(self::lineText($text), 0, self::LINE_TEXT_LENGTH);
        if ($text !== '') {
            $this->lastLineText = $text;
        }
    }

    private static function lineText(?string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', (string) $text));
    }

    /** The defaults were decided now by $actor — {@see CreditorProfileService}, not a setter (no form body reaches it). */
    public function stampDefaults(string $actor, \DateTimeImmutable $at): void
    {
        $this->defaultsSetAt = $at;
        $this->defaultsSetBy = $actor;
    }

    /** No defaults any more — the next complete invoice decides them again. */
    public function clearDefaultsStamp(): void
    {
        $this->defaultsSetAt = null;
        $this->defaultsSetBy = null;
    }

    /** One more write — called by the service inside the unit of work that writes. */
    public function bumpVersion(): void
    {
        $this->version++;
    }
}
