<?php

namespace Z77\Module\Debtor\Entities;

use Doctrine\Common\Collections\ArrayCollection,
    Doctrine\Common\Collections\Collection,
    Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Module\Contact\Entities\Contact,
    Z77\Module\Debtor\Invoicing\DocumentSnapshot,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * An issued document of the receivables side — an invoice OR a credit note
 * ({@see InvoiceKind}; plan §6.1, §6.2). Immutable data once `final`: what
 * it shows is a SNAPSHOT (address, terms, rates, tax summary, totals), so a
 * later change of master data, contact or tax table never alters it
 * (plan §4a, ADR-041 decision 6, ADR-043 decision 19).
 *
 * States ({@see InvoiceState}): `invoicing` — the number exists, the content
 * may be replaced as often as needed under the same number ({@see reissue()}),
 * nothing is posted; `final` — posted through the accounting port
 * ({@see finalize()}), immutable, corrected only by a credit note.
 *
 * What is on the header, and why:
 *
 *   - `kind` + `number`: the bare integer of the range `invoice` /
 *     `credit-note` (one range per kind, gapless each); unique per kind
 *     (`uniq_invoice_kind_number`). No prefix, no year segment — a document
 *     number is what the range gives (`persistence-doctrine.md`);
 *   - `contact`: the party BY ID (plan §4a) — the reference; the address is
 *     copied into `addr_*` ({@see AddressSnapshot}), the language into
 *     `language` (a contact's language change must not re-language an
 *     issued document);
 *   - `creditNoteOf`: the FINAL invoice a credit note corrects (plan §6.2);
 *     null on an invoice;
 *   - `serviceFrom` / `serviceTo`: the date (or period) of the supply — the
 *     VAT rate resolves by `serviceFrom` (ADR-041 decision 4);
 *   - `currency` / `exchangeRate`: the document's currency and, for a
 *     foreign one, its rate (plan §6.2). Q6 decided against foreign-currency
 *     invoicing, so the currency is the base currency and the rate stays
 *     NULL — the columns exist so issued documents need no migration when
 *     that changes; no logic reads them. Every `Money` column is base
 *     currency (`MoneyType`, ADR-042 decision 4);
 *   - `paymentTermsCode` BY CODE (ADR-043 decision 19) with the terms AS
 *     APPLIED: `dueDate`, `discountTiers` (JSON, each tier with the last day
 *     it applies) and `termsText` (the printed sentence in the document's
 *     language) — the terms row may change later, this document does not;
 *   - the totals: `netTotal` (Σ tax bases), `taxTotal`, `rounding` (the 0.05
 *     line, may be 0.00 or negative) and `grossTotal` (= net + tax +
 *     rounding, what is payable) — stored, never recomputed;
 *   - `sourceType` / `sourceRef`: the OPAQUE origin of the document (an
 *     order, a contract, a manual entry); debtor does not interpret it;
 *   - `ledgerEntryRef`: what the accounting port answered on `finalize()` —
 *     `{fiscal-year}/{number}` as ONE string (the port's return value, the
 *     `EntryRef` shape of financial written as it reads on a document),
 *     NULL while `invoicing` or when the installation keeps its books
 *     elsewhere (`NullAccountingGateway`);
 *   - created / changed by and at: the actor's NAME (a backend user can be
 *     deleted, the document keeps the name); `version` is the optimistic
 *     lock a re-issue names (the `journal_entry.version` model).
 *
 * No setters. The header snapshot is written whole by {@see issue()} /
 * {@see reissue()}, the state by {@see finalize()} — and both refuse a
 * `final` document themselves, not only in the service (ADR-042 decision 7
 * applied to the document).
 *
 * Table `invoice`; the mapping IS the table definition (migration
 * `Version20260923…`, `z77-db diff` sees no change).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'invoice')]
#[ORM\UniqueConstraint(name: Invoice::UNIQUE_KIND_NUMBER, columns: ['kind', 'number'])]
#[ORM\Index(name: 'idx_invoice_state_date', columns: ['state', 'invoice_date'])]
class Invoice
{
    public const UNIQUE_KIND_NUMBER = 'uniq_invoice_kind_number';

    public const LANGUAGE_LENGTH    = 5;
    public const CURRENCY_LENGTH    = 3;
    public const TERMS_CODE_LENGTH  = 16;
    public const SOURCE_TYPE_LENGTH = 64;
    public const SOURCE_REF_LENGTH  = 128;
    public const LEDGER_REF_LENGTH  = 32;
    public const ACTOR_LENGTH       = 80;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /** An {@see InvoiceKind} value, stored as its string. */
    #[ORM\Column(length: 12)]
    private string $kind;

    /** Bare integer from the kind's `NumberRange`, from 1. */
    #[ORM\Column]
    private int $number;

    /** An {@see InvoiceState} value, stored as its string. */
    #[ORM\Column(length: 12)]
    private string $state = InvoiceState::Invoicing->value;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'contact_id', nullable: false)]
    private Contact $contact;

    /** The FINAL invoice this credit note corrects — null on an invoice. */
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'credit_note_of_id', nullable: true)]
    private ?Invoice $creditNoteOf;

    #[ORM\Embedded(class: AddressSnapshot::class, columnPrefix: 'addr_')]
    private AddressSnapshot $address;

    #[ORM\Column(length: self::LANGUAGE_LENGTH)]
    private string $language = '';

    #[ORM\Column(name: 'invoice_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $invoiceDate;

    #[ORM\Column(name: 'service_from', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $serviceFrom;

    #[ORM\Column(name: 'service_to', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $serviceTo = null;

    #[ORM\Column(length: self::CURRENCY_LENGTH)]
    private string $currency = '';

    /**
     * Rate document currency → base currency for a FOREIGN document; NULL in
     * the base currency — which, since Q6 (no foreign-currency invoicing),
     * is every document today. `currency` and this column are carried
     * WITHOUT logic and without a reader ON PURPOSE (plan §6.2, Q6, review
     * 2026-09-23): the document is the place that owns them (ADR-042
     * decision 4 — the ledger never does), and an issued document is
     * immutable data. Adding the columns later would be a migration on
     * issued documents; carrying them now costs two nullable columns. A
     * decimal STRING, never a float. Not a candidate for removal.
     */
    #[ORM\Column(name: 'exchange_rate', type: Types::DECIMAL, precision: 12, scale: 6, nullable: true)]
    private ?string $exchangeRate = null;

    /** A `PriceMode` value: whether the line amounts are net or gross. */
    #[ORM\Column(name: 'price_mode', length: 5)]
    private string $priceMode = '';

    /** {@see PaymentTerms::$code} — by code, no foreign key (ADR-043 decision 19). */
    #[ORM\Column(name: 'payment_terms_code', length: self::TERMS_CODE_LENGTH)]
    private string $paymentTermsCode = '';

    #[ORM\Column(name: 'due_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dueDate;

    /** JSON: `[{"days": 10, "percent": 200, "until": "2026-03-11"}, …]` — the tiers as applied; `[]` when none. */
    #[ORM\Column(name: 'discount_tiers', type: Types::TEXT)]
    private string $discountTiers = '[]';

    /** The payment-terms sentence as printed, in the document's language (resolved with the default-language fallback). */
    #[ORM\Column(name: 'terms_text', type: Types::TEXT, nullable: true)]
    private ?string $termsText = null;

    #[ORM\Column(name: 'net_total', type: MoneyType::NAME)]
    private Money $netTotal;

    #[ORM\Column(name: 'tax_total', type: MoneyType::NAME)]
    private Money $taxTotal;

    #[ORM\Column(type: MoneyType::NAME)]
    private Money $rounding;

    #[ORM\Column(name: 'gross_total', type: MoneyType::NAME)]
    private Money $grossTotal;

    #[ORM\Column(name: 'source_type', length: self::SOURCE_TYPE_LENGTH, nullable: true)]
    private ?string $sourceType = null;

    #[ORM\Column(name: 'source_ref', length: self::SOURCE_REF_LENGTH, nullable: true)]
    private ?string $sourceRef = null;

    /** `{fiscal-year}/{number}` of the journal entry, once posted; null before and without a ledger. */
    #[ORM\Column(name: 'ledger_entry_ref', length: self::LEDGER_REF_LENGTH, nullable: true)]
    private ?string $ledgerEntryRef = null;

    #[ORM\Column(name: 'created_by', length: self::ACTOR_LENGTH)]
    private string $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'changed_by', length: self::ACTOR_LENGTH, nullable: true)]
    private ?string $changedBy = null;

    #[ORM\Column(name: 'changed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $changedAt = null;

    /** Optimistic lock: a re-issue names the version it saw (`InvoicingService::reinvoice()`). */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    /**
     * The lines in position order. `orphanRemoval`: a re-issue replaces them
     * whole and the old rows go at flush.
     *
     * @var Collection<int, InvoiceLine>
     */
    #[ORM\OneToMany(targetEntity: InvoiceLine::class, mappedBy: 'invoice', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    /**
     * The tax summary, one row per code, in position order.
     *
     * @var Collection<int, InvoiceTax>
     */
    #[ORM\OneToMany(targetEntity: InvoiceTax::class, mappedBy: 'invoice', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $taxes;

    /**
     * A document with its identity — kind, number, party, the invoice it
     * corrects — and no content yet: {@see issue()} follows in the same unit
     * of work. Built by `InvoicingService` only.
     */
    public function __construct(InvoiceKind $kind, int $number, Contact $contact, ?Invoice $creditNoteOf, string $createdBy, \DateTimeImmutable $createdAt)
    {
        if ($number < 1) {
            throw new \LogicException('A document number starts at 1');
        }
        if (($kind === InvoiceKind::CreditNote) !== ($creditNoteOf !== null)) {
            throw new \LogicException('A credit note references the invoice it corrects; an invoice references none');
        }
        $this->lines        = new ArrayCollection();
        $this->taxes        = new ArrayCollection();
        $this->kind         = $kind->value;
        $this->number       = $number;
        $this->contact      = $contact;
        $this->creditNoteOf = $creditNoteOf;
        $this->createdBy    = $createdBy;
        $this->createdAt    = $createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getNumber(): int { return $this->number; }
    public function getContact(): Contact { return $this->contact; }
    public function getCreditNoteOf(): ?Invoice { return $this->creditNoteOf; }
    public function getAddress(): AddressSnapshot { return $this->address; }
    public function getLanguage(): string { return $this->language; }
    public function getInvoiceDate(): \DateTimeImmutable { return $this->invoiceDate; }
    public function getServiceFrom(): \DateTimeImmutable { return $this->serviceFrom; }
    public function getServiceTo(): ?\DateTimeImmutable { return $this->serviceTo; }
    public function getCurrency(): string { return $this->currency; }
    public function getPriceMode(): string { return $this->priceMode; }
    public function getPaymentTermsCode(): string { return $this->paymentTermsCode; }
    public function getDueDate(): \DateTimeImmutable { return $this->dueDate; }
    public function getTermsText(): ?string { return $this->termsText; }
    public function getNetTotal(): Money { return $this->netTotal; }
    public function getTaxTotal(): Money { return $this->taxTotal; }
    public function getRounding(): Money { return $this->rounding; }
    public function getGrossTotal(): Money { return $this->grossTotal; }
    public function getSourceType(): ?string { return $this->sourceType; }
    public function getSourceRef(): ?string { return $this->sourceRef; }
    public function getLedgerEntryRef(): ?string { return $this->ledgerEntryRef; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getChangedBy(): ?string { return $this->changedBy; }
    public function getChangedAt(): ?\DateTimeImmutable { return $this->changedAt; }
    public function getVersion(): int { return $this->version; }

    public function kind(): InvoiceKind
    {
        return InvoiceKind::from($this->kind);
    }

    public function isFinal(): bool
    {
        return $this->state === InvoiceState::Final->value;
    }

    public function isCreditNote(): bool
    {
        return $this->kind === InvoiceKind::CreditNote->value;
    }

    /** «Rechnung 12» / «Gutschrift 3» — how a screen, a journal text or a document names it. */
    public function documentName(): string
    {
        return $this->kind()->label() . ' ' . $this->number;
    }

    /** @return list<array{days: int, percent: int, until: string}> */
    public function getDiscountTiers(): array
    {
        $tiers = json_decode($this->discountTiers, true);

        return is_array($tiers) ? array_values($tiers) : [];
    }

    /** @return list<InvoiceLine> in position order */
    public function getLines(): array
    {
        return $this->lines->getValues();
    }

    /** @return list<InvoiceTax> one per tax code, in position order */
    public function getTaxes(): array
    {
        return $this->taxes->getValues();
    }

    /**
     * The FIRST content of a new document. Lines and tax rows are built for
     * THIS document (`InvoiceLine::__construct()` takes it) and attached
     * here; `cascade: persist` writes them with the header.
     *
     * @param list<InvoiceLine> $lines in position order, the rounding line last when there is one
     * @param list<InvoiceTax>  $taxes one per tax code
     */
    public function issue(DocumentSnapshot $snapshot, array $lines, array $taxes): void
    {
        if ($this->id !== null || !$this->lines->isEmpty()) {
            throw new \LogicException('issue() writes the first content of a new document — an existing one is re-issued through reissue()');
        }
        $this->replaceContent($snapshot, $lines, $taxes);
    }

    /**
     * A NEW snapshot under the SAME number (plan §6.2: «can be re-invoiced as
     * often as needed — the number stays the same, snapshot and PDF are
     * replaced»). Only while `invoicing`: nothing was posted, so there is
     * nothing to reverse. The old lines and tax rows leave their collections
     * and orphan removal deletes them at flush.
     *
     * @param list<InvoiceLine> $lines
     * @param list<InvoiceTax>  $taxes
     */
    public function reissue(DocumentSnapshot $snapshot, array $lines, array $taxes, string $changedBy, \DateTimeImmutable $changedAt): void
    {
        $this->assertInvoicing('re-issued');
        $this->replaceContent($snapshot, $lines, $taxes);
        $this->changedBy = $changedBy;
        $this->changedAt = $changedAt;
    }

    /**
     * `invoicing` → `final`: the one state change, one way (plan §6.2). From
     * here on the document is immutable; a correction is a credit note.
     *
     * @param string|null $ledgerEntryRef what the accounting port answered — `{year}/{number}`, or null
     *                                    when the installation keeps its books elsewhere (`NullAccountingGateway`)
     */
    public function finalize(?string $ledgerEntryRef, string $by, \DateTimeImmutable $at): void
    {
        $this->assertInvoicing('finalized');
        if ($ledgerEntryRef !== null && (trim($ledgerEntryRef) === '' || mb_strlen($ledgerEntryRef) > self::LEDGER_REF_LENGTH)) {
            throw new \LogicException('A ledger entry reference is 1-' . self::LEDGER_REF_LENGTH . ' characters or null');
        }
        $this->state          = InvoiceState::Final->value;
        $this->ledgerEntryRef = $ledgerEntryRef;
        $this->changedBy      = $by;
        $this->changedAt      = $at;
    }

    /** @param list<InvoiceLine> $lines @param list<InvoiceTax> $taxes */
    private function replaceContent(DocumentSnapshot $snapshot, array $lines, array $taxes): void
    {
        if (mb_strlen($snapshot->language) > self::LANGUAGE_LENGTH || strlen($snapshot->currency) !== self::CURRENCY_LENGTH) {
            throw new \LogicException('Document snapshot: language up to ' . self::LANGUAGE_LENGTH . ' characters, currency exactly ' . self::CURRENCY_LENGTH);
        }
        $this->address          = $snapshot->address;
        $this->language         = $snapshot->language;
        $this->invoiceDate      = $snapshot->invoiceDate;
        $this->serviceFrom      = $snapshot->serviceFrom;
        $this->serviceTo        = $snapshot->serviceTo;
        $this->currency         = $snapshot->currency;
        $this->exchangeRate     = null;   // Q6: base currency only; the column waits for a foreign document
        $this->priceMode        = $snapshot->priceMode->value;
        $this->paymentTermsCode = $snapshot->paymentTermsCode;
        $this->dueDate          = $snapshot->dueDate;
        $this->discountTiers    = json_encode(array_values($snapshot->discountTiers), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->termsText        = $snapshot->termsText !== '' ? $snapshot->termsText : null;
        $this->netTotal         = $snapshot->netTotal;
        $this->taxTotal         = $snapshot->taxTotal;
        $this->rounding         = $snapshot->rounding;
        $this->grossTotal       = $snapshot->grossTotal;
        $this->sourceType       = $snapshot->sourceType;
        $this->sourceRef        = $snapshot->sourceRef;

        $this->lines->clear();
        foreach ($lines as $line) {
            if ($line->getInvoice() !== $this) {
                throw new \LogicException('Invoice line belongs to another document');
            }
            $this->lines->add($line);
        }
        $this->taxes->clear();
        foreach ($taxes as $tax) {
            if ($tax->getInvoice() !== $this) {
                throw new \LogicException('Tax row belongs to another document');
            }
            $this->taxes->add($tax);
        }
    }

    private function assertInvoicing(string $what): void
    {
        if ($this->isFinal()) {
            throw new \LogicException($this->documentName() . " is final and cannot be {$what} — a correction is a credit note (plan §1, §6.2)");
        }
    }
}
