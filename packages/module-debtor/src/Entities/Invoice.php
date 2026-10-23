<?php

namespace Z77\Module\Debtor\Entities;

use Doctrine\Common\Collections\ArrayCollection,
    Doctrine\Common\Collections\Collection,
    Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Module\Contact\Entities\Contact,
    Z77\Module\Debtor\Invoicing\DocumentSnapshot,
    Z77\Module\Debtor\Invoicing\SubjectLabel,
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
 * States ({@see InvoiceState}): `invoicing` — a DRAFT without a number
 * (owner 2026-10-10: «a printed invoice with a number IS a document»), the
 * content may be replaced as often as needed ({@see reissue()}), nothing is
 * posted, its PDF is a preview; `final` — numbered and posted in ONE step,
 * the booking print ({@see assignNumber()} then {@see finalize()}, both in
 * `InvoicingService::finalize()`), immutable, corrected only by a credit
 * note.
 *
 * What is on the header, and why:
 *
 *   - `kind` + `number`: ONE number series for every kind (owner
 *     2026-10-10) — `YYMMnnnn` by the month of the invoice date, the
 *     running `nnnn` from the month's range `document.YYMM`
 *     ({@see \Z77\Module\Debtor\Invoicing\DocumentNumber}), drawn when the
 *     document is BOOKED (the booking print), NULL while `invoicing`;
 *     unique across kinds (`uniq_invoice_number` — any number of NULLs),
 *     so a number names exactly one document. Numbers issued before
 *     2026-10-10 (the former per-kind ranges, 1, 2, …) stay as they were
 *     printed and booked. The former per-kind index
 *     `uniq_invoice_kind_number` is implied by the new one and stays until
 *     a later release drops it (expand/contract, ADR-039 decision 14 —
 *     `debtor.md` pending);
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
 *   - `payment` (`pay_*`, P3 part 3): the PAYMENT PART as printed — the
 *     payment target by code, the account, the reference type and the
 *     reference, the message and the creditor block as it resolved
 *     ({@see PaymentSnapshot}); empty on a credit note. The QR-bill is
 *     rendered from it (`Invoicing\QrBill::of()`), never from the target;
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
 * {@see reissue()}, the number and the payment reference by
 * {@see assignNumber()}, the state by {@see finalize()} — and all refuse a
 * `final` document themselves, not only in the service (ADR-042 decision 7
 * applied to the document).
 *
 * Table `invoice`; the mapping IS the table definition (migration
 * `Version20260923…`, `z77-db diff` sees no change).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'invoice')]
#[ORM\UniqueConstraint(name: Invoice::UNIQUE_NUMBER, columns: ['number'])]
#[ORM\UniqueConstraint(name: Invoice::UNIQUE_KIND_NUMBER, columns: ['kind', 'number'])]
#[ORM\Index(name: 'idx_invoice_state_date', columns: ['state', 'invoice_date'])]
class Invoice
{
    public const UNIQUE_NUMBER = 'uniq_invoice_number';
    /** The former per-kind index — implied by {@see UNIQUE_NUMBER}, kept until the contract step drops it (ADR-039 decision 14). */
    public const UNIQUE_KIND_NUMBER = 'uniq_invoice_kind_number';

    public const LANGUAGE_LENGTH    = 5;
    public const CURRENCY_LENGTH    = 3;
    public const TERMS_CODE_LENGTH  = 16;
    public const SOURCE_TYPE_LENGTH = 64;
    public const SOURCE_REF_LENGTH  = 128;
    public const LEDGER_REF_LENGTH  = 32;
    public const ACTOR_LENGTH       = 80;
    public const SUBJECT_LENGTH     = 200;
    /** The «Betreffname» — {@see SubjectLabel::LENGTH}, the one rule. */
    public const SUBJECT_LABEL_LENGTH = SubjectLabel::LENGTH;
    /** The no-VAT note — the mandator's `Mandator::VAT_EXEMPT_NOTE_LENGTH`, repeated (the column is this module's). */
    public const VAT_NOTE_LENGTH = 255;
    /** A void reason and a source's re-issue blocker text (ADR-054 decisions 9, 10). */
    public const REASON_LENGTH = 255;
    /** The document stamp — the unique ID of an issued version (owner decision 2026-10-23, ADR-054). */
    public const STAMP_LENGTH = 10;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /** An {@see InvoiceKind} value, stored as its string. */
    #[ORM\Column(length: 12)]
    private string $kind;

    /**
     * `YYMMnnnn` (`DocumentNumber`) — one series for every kind, drawn at the
     * booking print; NULL while `invoicing` (owner 2026-10-10). Before
     * 2026-10-10 the bare integer of the kind's range.
     */
    #[ORM\Column(nullable: true)]
    private ?int $number = null;

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

    /** The payment part as printed (P3 part 3) — set whole by issue() / reissue(). */
    #[ORM\Embedded(class: PaymentSnapshot::class, columnPrefix: 'pay_')]
    private PaymentSnapshot $payment;

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

    /**
     * The optional «Betreff» printed under the title (P7 slim, plan D10, 2026-10-14): an order
     * passes «Auftrag {number} · {Betreff}», the manual editor a free line. Part of the snapshot —
     * written whole by issue() / reissue(); NULL = none. Migration `Version20261014120000`.
     */
    #[ORM\Column(length: self::SUBJECT_LENGTH, nullable: true)]
    private ?string $subject = null;

    /**
     * The optional «Betreffname» printed in front of the subject — «Projekt: …» (owner decision
     * 2026-10-21, {@see SubjectLabel}). Part of the snapshot like the subject — written whole by
     * issue() / reissue(), immutable once final; NULL = none. Migration `Version20261021100000`.
     */
    #[ORM\Column(name: 'subject_label', length: self::SUBJECT_LABEL_LENGTH, nullable: true)]
    private ?string $subjectLabel = null;

    /**
     * The note a document WITHOUT VAT prints under its totals (owner decision 2026-10-22): the
     * mandator's «Hinweis ohne MWST» as it read when the document was COMPOSED — a reprint shows
     * what was issued, whatever the mandator says today (ADR-041 decision 6). NULL = nothing
     * printed: computed with VAT, or no text on the mandator then. Part of the snapshot, written
     * whole by issue() / reissue(). Migration `Version20261022100000`.
     */
    #[ORM\Column(name: 'vat_note', length: self::VAT_NOTE_LENGTH, nullable: true)]
    private ?string $vatNote = null;

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

    // ── the Faktura-Journal (ADR-054; step 1 of the build plan: the columns — nothing writes them before step 3) ──

    /**
     * The REVISION of an issued document (ADR-054 decisions 3, 11): 1 when issued, +1 per revision
     * under the same number (the previous PDF archived in `invoice_revision`). Not the ORM `version`
     * (the optimistic lock moves on every write). Column default 1 — every existing row reads 1.
     */
    #[ORM\Column(options: ['default' => 1])]
    private int $revision = 1;

    /** Who / when the booking print ISSUED it (the number drawn); null before and on documents issued before the journal. */
    #[ORM\Column(name: 'issued_by', length: self::ACTOR_LENGTH, nullable: true)]
    private ?string $issuedBy = null;

    #[ORM\Column(name: 'issued_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    /** Who / when the journal posting POSTED it; null before and on documents posted before the journal. */
    #[ORM\Column(name: 'posted_by', length: self::ACTOR_LENGTH, nullable: true)]
    private ?string $postedBy = null;

    #[ORM\Column(name: 'posted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $postedAt = null;

    /** The «Journal verbuchen» protocol that posted it (ADR-054 decision 6); null before and on documents posted before the journal. */
    #[ORM\ManyToOne(targetEntity: InvoiceJournalPosting::class)]
    #[ORM\JoinColumn(name: 'journal_posting_id', nullable: true)]
    private ?InvoiceJournalPosting $journalPosting = null;

    /** The successor that REPLACED it (ADR-054 decision 10) — the chain A → B → C; null unless `replaced`. */
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'replaced_by_id', nullable: true)]
    private ?Invoice $replacedBy = null;

    /** Who / when / why it was replaced or deleted; null unless void. */
    #[ORM\Column(name: 'voided_by', length: self::ACTOR_LENGTH, nullable: true)]
    private ?string $voidedBy = null;

    #[ORM\Column(name: 'voided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $voidedAt = null;

    #[ORM\Column(name: 'void_reason', length: self::REASON_LENGTH, nullable: true)]
    private ?string $voidReason = null;

    /**
     * Set by a SOURCE (an order) whose content no longer matches the issued document — the blocker
     * text the Faktura-Journal shows (ADR-054 decision 9); null = in step with its source. Debtor
     * never interprets who set it.
     */
    #[ORM\Column(name: 'reissue_needed', length: self::REASON_LENGTH, nullable: true)]
    private ?string $reissueNeeded = null;

    /**
     * The STAMP of the current issued version (owner decision 2026-10-23, ADR-054): a short ID
     * from the SHA-256 of the version's CONTENT (number, date, recipient, lines, totals, VAT,
     * revision) — printed small next to the number, cited by a successor's «ersetzt Rechnung …
     * (ID …)», recorded per version in `invoice_revision`. NULL on drafts and on documents issued
     * before the journal. Computed from step 3 on.
     */
    #[ORM\Column(name: 'document_stamp', length: self::STAMP_LENGTH, nullable: true)]
    private ?string $documentStamp = null;

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
     * A DRAFT with its identity — kind, party, the invoice it corrects — and
     * no content and no number yet: {@see issue()} follows in the same unit
     * of work, the number comes with the booking print
     * ({@see assignNumber()}). Built by `InvoicingService` only.
     */
    public function __construct(InvoiceKind $kind, Contact $contact, ?Invoice $creditNoteOf, string $createdBy, \DateTimeImmutable $createdAt)
    {
        if (($kind === InvoiceKind::CreditNote) !== ($creditNoteOf !== null)) {
            throw new \LogicException('A credit note references the invoice it corrects; an invoice references none');
        }
        $this->lines        = new ArrayCollection();
        $this->taxes        = new ArrayCollection();
        $this->kind         = $kind->value;
        $this->contact      = $contact;
        $this->creditNoteOf = $creditNoteOf;
        $this->createdBy    = $createdBy;
        $this->createdAt    = $createdAt;
    }

    public function getId(): ?int { return $this->id; }
    /** The document number — NULL while the document is a draft (`invoicing`). */
    public function getNumber(): ?int { return $this->number; }
    public function getContact(): Contact { return $this->contact; }
    public function getCreditNoteOf(): ?Invoice { return $this->creditNoteOf; }
    public function getAddress(): AddressSnapshot { return $this->address; }
    public function getPayment(): PaymentSnapshot { return $this->payment; }
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
    /** The «Betreff» under the title — null when the document has none. */
    public function getSubject(): ?string { return $this->subject; }
    /** The «Betreffname» in front of the subject — null when the document has none. */
    public function getSubjectLabel(): ?string { return $this->subjectLabel; }
    /** The note printed under the totals of a document without VAT, as snapshotted; null = none (owner 2026-10-22). */
    public function getVatNote(): ?string { return $this->vatNote; }
    /** The subject line as printed: «<label>: <subject>», the subject alone, or null ({@see SubjectLabel::line()}). */
    public function subjectLine(): ?string { return SubjectLabel::line($this->subjectLabel, $this->subject); }
    public function getSourceType(): ?string { return $this->sourceType; }
    public function getSourceRef(): ?string { return $this->sourceRef; }
    public function getLedgerEntryRef(): ?string { return $this->ledgerEntryRef; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getChangedBy(): ?string { return $this->changedBy; }
    public function getChangedAt(): ?\DateTimeImmutable { return $this->changedAt; }
    public function getVersion(): int { return $this->version; }
    /** The revision of the document — 1 unless revised while in the Faktura-Journal (ADR-054 decision 11). */
    public function getRevision(): int { return $this->revision; }
    public function getIssuedBy(): ?string { return $this->issuedBy; }
    public function getIssuedAt(): ?\DateTimeImmutable { return $this->issuedAt; }
    public function getPostedBy(): ?string { return $this->postedBy; }
    public function getPostedAt(): ?\DateTimeImmutable { return $this->postedAt; }
    public function getJournalPosting(): ?InvoiceJournalPosting { return $this->journalPosting; }
    /** The successor of a `replaced` document; null otherwise. */
    public function getReplacedBy(): ?Invoice { return $this->replacedBy; }
    public function getVoidedBy(): ?string { return $this->voidedBy; }
    public function getVoidedAt(): ?\DateTimeImmutable { return $this->voidedAt; }
    public function getVoidReason(): ?string { return $this->voidReason; }
    /** The blocker text a source set (ADR-054 decision 9); null = in step with its source. */
    public function getReissueNeeded(): ?string { return $this->reissueNeeded; }
    /** The stamp of the current issued version; null on drafts and documents issued before the journal. */
    public function getDocumentStamp(): ?string { return $this->documentStamp; }

    public function kind(): InvoiceKind
    {
        return InvoiceKind::from($this->kind);
    }

    public function state(): InvoiceState
    {
        return InvoiceState::from($this->state);
    }

    // ── the state predicates (ADR-054, build plan §3) — readers ask these, never a state value ──
    // They replace the one former question `isFinal()`. Until step 3 of the build plan nothing
    // produces `issued`, `replaced` or `deleted`: every document is a draft or posted, so
    // `isIssued()` and `isPosted()` answer alike — the split is the reader's INTENT.

    /** A draft (`invoicing`): no number, editable, its PDF is a preview. */
    public function isDraft(): bool
    {
        return $this->state === InvoiceState::Invoicing->value;
    }

    /**
     * Issued to the customer and standing (`issued` or `final`): numbered, the real PDF with its
     * QR-bill, mailable. NOT payable before it is posted (ADR-054, owner decision 6). A void document is NOT issued in this sense.
     */
    public function isIssued(): bool
    {
        return $this->state === InvoiceState::Issued->value || $this->state === InvoiceState::Final->value;
    }

    /** In the Faktura-Journal (`issued`): issued, not posted yet — correctable by revision or replacement. */
    public function isInJournal(): bool
    {
        return $this->state === InvoiceState::Issued->value;
    }

    /** Posted into the ledger (`final`, «verbucht»): immutable; payable (open amount, payments, discount, loss), credit-note target, dunned, reconciled. */
    public function isPosted(): bool
    {
        return $this->state === InvoiceState::Final->value;
    }

    /** Void (`replaced` or `deleted`): kept with its number, nothing posted, no payments, no mails. */
    public function isVoid(): bool
    {
        return $this->state === InvoiceState::Replaced->value || $this->state === InvoiceState::Deleted->value;
    }

    public function isCreditNote(): bool
    {
        return $this->kind === InvoiceKind::CreditNote->value;
    }

    /**
     * «Rechnung 26100012» / «Gutschrift 26100003» — how a screen, a journal
     * text or a document names it. A draft has no number yet (owner
     * 2026-10-10): «Rechnung (Entwurf vom 10.10.2026)».
     */
    public function documentName(): string
    {
        return $this->number !== null
            ? $this->kind()->label() . ' ' . $this->number
            : $this->kind()->label() . ' (Entwurf' . (isset($this->invoiceDate) ? ' vom ' . $this->invoiceDate->format('d.m.Y') : '') . ')';
    }

    /** Whether the document carries its number — true from the booking print on (and for every document of the former ranges). */
    public function isNumbered(): bool
    {
        return $this->number !== null;
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
     * Whether the document was COMPUTED with VAT — read from the snapshot, never from today's
     * mandator (ADR-041 decision 6): a line carries a tax code. A document of a mandator not
     * liable to VAT (owner 2026-10-21, `InvoicingService::chargesVat()`) and a fee carry none.
     * The print shows the VAT column, the VAT rows and «zzgl. / inkl. MWST» by it; a credit
     * note follows its invoice by it.
     */
    public function carriesVat(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->getTaxCode() !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The FIRST content of a new document. Lines and tax rows are built for
     * THIS document (`InvoiceLine::__construct()` takes it) and attached
     * here; `cascade: persist` writes them with the header.
     *
     * @param list<InvoiceLine> $lines in position order, the rounding line last when there is one
     * @param list<InvoiceTax>  $taxes one per tax code
     */
    public function issue(DocumentSnapshot $snapshot, array $lines, array $taxes, PaymentSnapshot $payment): void
    {
        if ($this->id !== null || !$this->lines->isEmpty()) {
            throw new \LogicException('issue() writes the first content of a new document — an existing one is re-issued through reissue()');
        }
        $this->replaceContent($snapshot, $lines, $taxes, $payment);
    }

    /**
     * A NEW snapshot of the DRAFT (plan §6.2: «can be re-invoiced as often as
     * needed — snapshot and PDF are replaced»; since owner 2026-10-10 a draft
     * has no number yet, so there is none that «stays»). Only while
     * `invoicing`: nothing was posted, so there is nothing to reverse. The old lines and tax rows leave their collections
     * and orphan removal deletes them at flush.
     *
     * @param list<InvoiceLine> $lines
     * @param list<InvoiceTax>  $taxes
     */
    public function reissue(DocumentSnapshot $snapshot, array $lines, array $taxes, PaymentSnapshot $payment, string $changedBy, \DateTimeImmutable $changedAt): void
    {
        $this->assertInvoicing('re-issued');
        $this->replaceContent($snapshot, $lines, $taxes, $payment);
        $this->changedBy = $changedBy;
        $this->changedAt = $changedAt;
    }

    /**
     * The booking print's first step (owner 2026-10-10): the document gets
     * its number and, with it, the numbered payment part — the QR reference
     * and the message are derived from the number
     * ({@see PaymentSnapshot::numbered()}). Only once, only while
     * `invoicing`; {@see finalize()} follows in the same unit of work
     * (`InvoicingService::finalize()` draws the number from the month's
     * range).
     */
    public function assignNumber(int $number, PaymentSnapshot $payment): void
    {
        $this->assertInvoicing('numbered');
        if ($this->number !== null) {
            throw new \LogicException($this->documentName() . ' already carries its number');
        }
        if ($number < 1) {
            throw new \LogicException('A document number starts at 1');
        }
        if ($this->isCreditNote() && $payment->hasPaymentPart()) {
            throw new \LogicException('A credit note has no payment part');
        }
        $this->number  = $number;
        $this->payment = $payment;
    }

    /**
     * `invoicing` → `final`: the one state change, one way (plan §6.2). From
     * here on the document is immutable; a correction is a credit note. The
     * number is assigned first ({@see assignNumber()}) — a booked document
     * always carries one.
     *
     * @param string|null $ledgerEntryRef what the accounting port answered — `{year}/{number}`, or null
     *                                    when the installation keeps its books elsewhere (`NullAccountingGateway`)
     */
    public function finalize(?string $ledgerEntryRef, string $by, \DateTimeImmutable $at): void
    {
        $this->assertInvoicing('finalized');
        if ($this->number === null) {
            throw new \LogicException('A document is numbered before it is finalized (assignNumber(), the booking print)');
        }
        if ($ledgerEntryRef !== null && (trim($ledgerEntryRef) === '' || mb_strlen($ledgerEntryRef) > self::LEDGER_REF_LENGTH)) {
            throw new \LogicException('A ledger entry reference is 1-' . self::LEDGER_REF_LENGTH . ' characters or null');
        }
        $this->state          = InvoiceState::Final->value;
        $this->ledgerEntryRef = $ledgerEntryRef;
        $this->changedBy      = $by;
        $this->changedAt      = $at;
    }

    /** @param list<InvoiceLine> $lines @param list<InvoiceTax> $taxes */
    private function replaceContent(DocumentSnapshot $snapshot, array $lines, array $taxes, PaymentSnapshot $payment): void
    {
        if ($this->isCreditNote() && $payment->hasPaymentPart()) {
            throw new \LogicException('A credit note has no payment part');
        }
        $subjectLabel = SubjectLabel::normalize($snapshot->subjectLabel);
        if (SubjectLabel::isTooLong($subjectLabel)) {
            throw new \LogicException('Document snapshot: a subject label has at most ' . self::SUBJECT_LABEL_LENGTH . ' characters');
        }
        $this->payment          = $payment;
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
        $this->subject          = $snapshot->subject !== null && trim($snapshot->subject) !== '' ? $snapshot->subject : null;
        $this->subjectLabel     = $subjectLabel;
        $vatNote                = trim((string) $snapshot->vatNote);
        if (mb_strlen($vatNote) > self::VAT_NOTE_LENGTH) {
            throw new \LogicException('Document snapshot: the VAT note has at most ' . self::VAT_NOTE_LENGTH . ' characters');
        }
        $this->vatNote          = $vatNote !== '' ? $vatNote : null;

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
        if (!$this->isDraft()) {
            throw new \LogicException($this->documentName() . " is no longer a draft ({$this->state}) and cannot be {$what} — a posted document is corrected by a credit note (plan §1, §6.2)");
        }
    }
}
