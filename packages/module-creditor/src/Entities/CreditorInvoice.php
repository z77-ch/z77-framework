<?php

namespace Z77\Module\Creditor\Entities;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Persistence\Doctrine\Type\MoneyType;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Money\Money;
use Z77\Shared\Payment\Iban;

/**
 * One SUPPLIER INVOICE in the Kreditorenjournal (creditor plan §2.3, owner answers §11 of
 * 2026-11-15): the PDF a supplier sent (in the DMS, `document_id`), what the owner (or, from stage
 * 2, the recognition) read off it, how it is split into {@see CreditorInvoiceLine}s, and later how
 * and when it was paid and posted. Nothing reaches the ledger before «Journal verbuchen» on the
 * payment date (stage 1c, owner answer 3).
 *
 * The entity carries from the start what the COLLECTIVE-ACCOUNT variant (2000 payables) will need,
 * so a later variant needs no migration (plan §2.4): invoice date, due date, invoice number, the
 * IBAN the invoice names (a snapshot), the reference (`reference_type` QRR / SCOR / NON +
 * `reference`), the payment state, the currency columns (the receipt FX convention T1 — `amount`
 * CHF, `original_amount` / `original_currency` / `exchange_rate`; stage 1 takes CHF only), and the
 * `posting_variant` SNAPSHOT (`direct` — never re-read from config for an existing invoice).
 *
 * Groups of columns:
 *
 *   - identity: `creditor_profile_id` (NULL while the creditor is not chosen, or only PROPOSED —
 *     `creditor_proposal`, a JSON object filled by the recognition from stage 2: name, street,
 *     house_no, zip, city, country, iban, uid, account, tax_code), `intake_id`, `document_id`;
 *   - supplier facts: `invoice_number`, `invoice_date`, `due_date`, `iban`, `reference_type`,
 *     `reference`, `message`;
 *   - amounts: `amount` (gross CHF; NULL = not typed yet, or a foreign amount waiting for CHF),
 *     `original_amount`, `original_currency`, `exchange_rate`;
 *   - payment: `payment_state` (`unpaid` / `paid`), `paid_on` (set in stage 1c — the posting date),
 *     `payment_method_code` (module-receipt's «Bezahlt mit»), `credit_account` (resolved from it,
 *     correctable);
 *   - posting: `posting_variant`, `state` (`open` → `posted`; `blocked` = a SUSPECTED DUPLICATE),
 *     `ledger_entry_ref`, `journal_posting_id`, `posted_by` / `posted_at`;
 *   - control: `duplicate_of` (the invoice it may duplicate — no foreign key, the other may be
 *     deleted) with `duplicate_reason`; `duplicate_cleared_of` / `_by` / `_at` (the owner said «kein
 *     Doppel» for exactly that other invoice — the check skips the pair afterwards); `recognition`
 *     (JSON, stage 2: which step found which value); `received_by/at`, `changed_by/at`, `version`.
 *
 * **Suspected duplicate (owner answer 4):** created, then BLOCKED (`state` = `blocked`,
 * `duplicate_of` set) until the owner releases it («Kein Doppel — übernehmen») or deletes it.
 * `blocked` ⇔ `duplicate_of` is set — only {@see markDuplicate()} / {@see clearDuplicateMark()} /
 * {@see releaseDuplicate()} change the pair.
 *
 * Optimistic lock: every write names the `version` the caller saw (`ORM\Version`). Table
 * `creditor_invoice` (`Version20261115120000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'creditor_invoice')]
#[ORM\Index(name: 'idx_creditor_invoice_state_date', columns: ['state', 'invoice_date'])]
#[ORM\Index(name: 'idx_creditor_invoice_document', columns: ['document_id'])]
#[ORM\Index(name: 'idx_creditor_invoice_number', columns: ['creditor_profile_id', 'invoice_number'])]
#[ORM\Index(name: 'idx_creditor_invoice_reference', columns: ['iban', 'reference'])]
class CreditorInvoice
{
    public const STATE_OPEN    = 'open';
    public const STATE_BLOCKED = 'blocked';
    public const STATE_POSTED  = 'posted';

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PAID   = 'paid';

    /** The reference types of a Swiss QR-bill: QR reference, ISO 11649 creditor reference, none. */
    public const REFERENCE_QRR  = 'QRR';
    public const REFERENCE_SCOR = 'SCOR';
    public const REFERENCE_NONE = 'NON';

    public const VARIANT_DIRECT = 'direct';

    public const NUMBER_LENGTH    = 40;
    public const REFERENCE_LENGTH = 27;
    /** Unstructured message + billing information of a QR-bill (140 characters each). */
    public const MESSAGE_LENGTH   = 280;
    public const REASON_LENGTH    = 255;
    public const ACTOR_LENGTH     = JournalEntry::ACTOR_LENGTH;
    public const ENTRY_REF_LENGTH = 40;
    /** The origin the ledger will record for a creditor posting (`journal_entry.source_type`, stage 1c). */
    public const SOURCE_TYPE      = 'creditor';

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(length: 12)]
    private string $state = self::STATE_OPEN;

    #[ORM\ManyToOne(targetEntity: CreditorProfile::class)]
    #[ORM\JoinColumn(name: 'creditor_profile_id', nullable: true)]
    private ?CreditorProfile $creditor = null;

    /** JSON object — the unknown creditor as recognised (stage 2); NULL = none. */
    #[ORM\Column(name: 'creditor_proposal', type: Types::TEXT, nullable: true)]
    private ?string $creditorProposal = null;

    #[ORM\ManyToOne(targetEntity: CreditorIntake::class)]
    #[ORM\JoinColumn(name: 'intake_id', nullable: true)]
    private ?CreditorIntake $intake = null;

    /** The PDF — a DMS document id (file store, no foreign key). */
    #[ORM\Column(name: 'document_id')]
    private int $documentId;

    #[ORM\Column(name: 'invoice_number', length: self::NUMBER_LENGTH, nullable: true)]
    private ?string $invoiceNumber = null;

    #[ORM\Column(name: 'invoice_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $invoiceDate = null;

    #[ORM\Column(name: 'due_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    /** The IBAN the invoice names, normalised — a SNAPSHOT (the creditor's IBAN rows may change). */
    #[ORM\Column(length: Iban::MAX_LENGTH, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column(name: 'reference_type', length: 4, options: ['default' => 'NON'])]
    private string $referenceType = self::REFERENCE_NONE;

    #[ORM\Column(length: self::REFERENCE_LENGTH, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(length: self::MESSAGE_LENGTH, options: ['default' => ''])]
    private string $message = '';

    /** The GROSS amount in the base currency; NULL = not typed yet (or a foreign amount waiting for CHF). */
    #[ORM\Column(type: MoneyType::NAME, nullable: true)]
    private ?Money $amount = null;

    /** The amount on the invoice in `original_currency` (receipt FX plan T1) — a decimal string. */
    #[ORM\Column(name: 'original_amount', type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $originalAmount = null;

    #[ORM\Column(name: 'original_currency', length: 3, nullable: true)]
    private ?string $originalCurrency = null;

    #[ORM\Column(name: 'exchange_rate', type: Types::DECIMAL, precision: 12, scale: 6, nullable: true)]
    private ?string $exchangeRate = null;

    #[ORM\Column(name: 'payment_state', length: 12, options: ['default' => 'unpaid'])]
    private string $paymentState = self::PAYMENT_UNPAID;

    /** The payment date — the posting date of the direct variant (stage 1c). */
    #[ORM\Column(name: 'paid_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidOn = null;

    #[ORM\Column(name: 'payment_method_code', length: CreditorProfile::PAYMENT_METHOD_LENGTH, nullable: true)]
    private ?string $paymentMethodCode = null;

    #[ORM\Column(name: 'credit_account', length: Account::NUMBER_LENGTH, nullable: true)]
    private ?string $creditAccount = null;

    /** The posting strategy, SNAPSHOT at creation (plan §2.4) — never re-read from config. */
    #[ORM\Column(name: 'posting_variant', length: 12, options: ['default' => 'direct'])]
    private string $postingVariant = self::VARIANT_DIRECT;

    #[ORM\Column(name: 'ledger_entry_ref', length: self::ENTRY_REF_LENGTH, nullable: true)]
    private ?string $ledgerEntryRef = null;

    #[ORM\ManyToOne(targetEntity: CreditorJournalPosting::class)]
    #[ORM\JoinColumn(name: 'journal_posting_id', referencedColumnName: 'id', nullable: true)]
    private ?CreditorJournalPosting $journalPosting = null;

    #[ORM\Column(name: 'posted_by', length: self::ACTOR_LENGTH, nullable: true)]
    private ?string $postedBy = null;

    #[ORM\Column(name: 'posted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $postedAt = null;

    /** The invoice this one may duplicate (owner answer 4) — set ⇔ `state` = blocked. */
    #[ORM\Column(name: 'duplicate_of', nullable: true)]
    private ?int $duplicateOf = null;

    #[ORM\Column(name: 'duplicate_reason', length: self::REASON_LENGTH, options: ['default' => ''])]
    private string $duplicateReason = '';

    /** The invoice the owner said this one is NOT a duplicate of — the check skips that pair. */
    #[ORM\Column(name: 'duplicate_cleared_of', nullable: true)]
    private ?int $duplicateClearedOf = null;

    #[ORM\Column(name: 'duplicate_cleared_by', length: self::ACTOR_LENGTH, nullable: true)]
    private ?string $duplicateClearedBy = null;

    #[ORM\Column(name: 'duplicate_cleared_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $duplicateClearedAt = null;

    /** JSON object — which recognition step found which value (stage 2); NULL = none ran. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $recognition = null;

    #[ORM\Column(name: 'received_by', length: self::ACTOR_LENGTH)]
    private string $receivedBy;

    #[ORM\Column(name: 'received_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'changed_by', length: self::ACTOR_LENGTH, nullable: true)]
    private ?string $changedBy = null;

    #[ORM\Column(name: 'changed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $changedAt = null;

    /** Optimistic lock: every write names the version its caller saw. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    /** A new OPEN, UNPAID invoice for a PDF the intake stored — `CreditorIntakeService` only. */
    public function __construct(?CreditorIntake $intake, int $documentId, string $receivedBy, \DateTimeImmutable $receivedAt, ?CreditorProfile $creditor = null, string $postingVariant = self::VARIANT_DIRECT)
    {
        if (trim($receivedBy) === '') {
            throw new \LogicException('A creditor invoice names who received it');
        }
        $this->intake         = $intake;
        $this->creditor       = $creditor;
        $this->documentId     = $documentId;
        $this->receivedBy     = $receivedBy;
        $this->receivedAt     = $receivedAt;
        $this->postingVariant = $postingVariant;
    }

    public function getId(): ?int { return $this->id; }
    public function getState(): string { return $this->state; }
    public function isOpen(): bool { return $this->state === self::STATE_OPEN; }
    public function isBlocked(): bool { return $this->state === self::STATE_BLOCKED; }
    public function isPosted(): bool { return $this->state === self::STATE_POSTED; }
    /** Open or blocked — still in the journal, still editable. */
    public function isEditable(): bool { return $this->state === self::STATE_OPEN || $this->state === self::STATE_BLOCKED; }
    public function getCreditor(): ?CreditorProfile { return $this->creditor; }
    public function getIntake(): ?CreditorIntake { return $this->intake; }
    public function getDocumentId(): int { return $this->documentId; }
    public function getInvoiceNumber(): ?string { return $this->invoiceNumber; }
    public function getInvoiceDate(): ?\DateTimeImmutable { return $this->invoiceDate; }
    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
    public function getIban(): ?string { return $this->iban; }
    public function getReferenceType(): string { return $this->referenceType; }
    public function getReference(): ?string { return $this->reference; }
    public function getMessage(): string { return $this->message; }
    public function getAmount(): ?Money { return $this->amount; }
    public function getOriginalAmount(): ?string { return $this->originalAmount; }
    public function getOriginalCurrency(): ?string { return $this->originalCurrency; }
    public function getExchangeRate(): ?string { return $this->exchangeRate; }
    public function getPaymentState(): string { return $this->paymentState; }
    public function getPaidOn(): ?\DateTimeImmutable { return $this->paidOn; }
    public function getPaymentMethodCode(): ?string { return $this->paymentMethodCode; }
    public function getCreditAccount(): ?string { return $this->creditAccount; }
    public function getPostingVariant(): string { return $this->postingVariant; }
    public function getLedgerEntryRef(): ?string { return $this->ledgerEntryRef; }
    public function getJournalPosting(): ?CreditorJournalPosting { return $this->journalPosting; }
    public function getPostedBy(): ?string { return $this->postedBy; }
    public function getPostedAt(): ?\DateTimeImmutable { return $this->postedAt; }
    public function getDuplicateOf(): ?int { return $this->duplicateOf; }
    public function getDuplicateReason(): string { return $this->duplicateReason; }
    public function getDuplicateClearedOf(): ?int { return $this->duplicateClearedOf; }
    public function getDuplicateClearedBy(): ?string { return $this->duplicateClearedBy; }
    public function getDuplicateClearedAt(): ?\DateTimeImmutable { return $this->duplicateClearedAt; }
    public function getReceivedBy(): string { return $this->receivedBy; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function getChangedBy(): ?string { return $this->changedBy; }
    public function getChangedAt(): ?\DateTimeImmutable { return $this->changedAt; }
    public function getVersion(): int { return $this->version; }

    /**
     * The unknown creditor as recognised (stage 2) — name, street, house_no, zip, city, country,
     * iban, uid, account, tax_code (strings); [] = no proposal.
     *
     * @return array<string, string>
     */
    public function proposal(): array
    {
        $data = $this->creditorProposal === null ? null : json_decode($this->creditorProposal, true);

        return is_array($data) ? array_map('strval', array_filter($data, 'is_scalar')) : [];
    }

    /** What the recognition noted per value (stage 2); [] = none ran. */
    public function recognition(): array
    {
        $data = $this->recognition === null ? null : json_decode($this->recognition, true);

        return is_array($data) ? $data : [];
    }

    /** «Rechnung 2026-117» / «Rechnung (ohne Nr.) · 15.11.2026» — how a screen names it. */
    public function label(): string
    {
        return $this->invoiceNumber !== null
            ? 'Rechnung ' . $this->invoiceNumber
            : 'Rechnung (ohne Nr.) vom ' . ($this->invoiceDate ?? $this->receivedAt)->format('d.m.Y');
    }

    /**
     * What keeps the invoice from being posted, as German labels in field order — empty =
     * complete. The duplicate block and «bezahlt am» are the run's business (stage 1c) and are not
     * listed here.
     *
     * @param list<CreditorInvoiceLine> $lines its lines, in position order
     * @return list<string>
     */
    public function missing(array $lines): array
    {
        $missing = [];
        if ($this->creditor === null) {
            $missing[] = 'Kreditor';
        }
        if ($this->amount === null) {
            $missing[] = 'Betrag';
        }
        if ($this->creditAccount === null) {
            $missing[] = 'Bezahlt mit';
        }
        $sum      = null;
        $complete = $lines !== [];
        foreach ($lines as $line) {
            if ($line->getAccount() === null && !in_array('Konto', $missing, true)) {
                $missing[] = 'Konto';
            }
            if (!$line->vatDecided() && !in_array('MWST', $missing, true)) {
                $missing[] = 'MWST';
            }
            if ($line->getAmount() === null) {
                $complete = false;
                continue;
            }
            $sum = $sum === null ? $line->getAmount() : $sum->add($line->getAmount());
        }
        if ($lines === []) {
            $missing[] = 'Konto';
            $missing[] = 'MWST';
        }
        if ($this->amount !== null && (!$complete || $sum === null || !$sum->equals($this->amount))) {
            $missing[] = 'Zeilen = Betrag';
        }

        return $missing;
    }

    /** @param list<CreditorInvoiceLine> $lines */
    public function isComplete(array $lines): bool
    {
        return $this->missing($lines) === [];
    }

    // ── writes — the services check the values; the entity guards its state ───────────────────

    /**
     * The invoice's head as the owner (or later the recognition) set it — CreditorInvoiceService
     * checked every value. The reference type follows the reference.
     */
    public function correct(
        ?CreditorProfile $creditor,
        ?string $iban,
        ?string $invoiceNumber,
        ?\DateTimeImmutable $invoiceDate,
        ?\DateTimeImmutable $dueDate,
        string $referenceType,
        ?string $reference,
        string $message,
        ?Money $amount,
        ?string $paymentMethodCode,
        ?string $creditAccount,
        string $changedBy,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->assertEditable('correct');
        if ($amount !== null && !$amount->isPositive()) {
            throw new \LogicException('A creditor invoice amount is positive');
        }
        if (!in_array($referenceType, [self::REFERENCE_QRR, self::REFERENCE_SCOR, self::REFERENCE_NONE], true) || ($referenceType === self::REFERENCE_NONE) !== ($reference === null)) {
            throw new \LogicException('A reference type QRR / SCOR carries a reference, NON none');
        }
        $this->creditor          = $creditor;
        $this->iban              = $iban;
        $this->invoiceNumber     = $invoiceNumber;
        $this->invoiceDate       = $invoiceDate;
        $this->dueDate           = $dueDate;
        $this->referenceType     = $referenceType;
        $this->reference         = $reference;
        $this->message           = $message;
        $this->amount            = $amount;
        $this->paymentMethodCode = $paymentMethodCode;
        $this->creditAccount     = $creditAccount;
        $this->touch($changedBy, $changedAt);
    }

    /** The creditor chosen or taken over («Kreditor übernehmen …»). */
    public function assignCreditor(CreditorProfile $creditor, string $changedBy, \DateTimeImmutable $changedAt): void
    {
        $this->assertEditable('assign a creditor to');
        $this->creditor = $creditor;
        $this->touch($changedBy, $changedAt);
    }

    /** «Bezahlt mit» and its credit account — the creditor's default at intake. */
    public function applyPayment(?string $paymentMethodCode, ?string $creditAccount): void
    {
        $this->assertEditable('apply a payment method to');
        $this->paymentMethodCode = $paymentMethodCode;
        $this->creditAccount     = $creditAccount;
    }

    /**
     * The unknown creditor as recognised — server-controlled (stage 2's recognition; no form body
     * reaches it). Only scalar values are kept.
     *
     * @param array<string, string> $proposal
     */
    public function proposeCreditor(array $proposal): void
    {
        $this->assertEditable('propose a creditor for');
        $proposal = array_map('strval', array_filter($proposal, 'is_scalar'));
        $this->creditorProposal = $proposal === [] ? null : (string) json_encode($proposal, JSON_UNESCAPED_UNICODE);
    }

    /** Suspected duplicate of invoice $of (owner answer 4): BLOCKED until the owner decides. */
    public function markDuplicate(int $of, string $reason): void
    {
        $this->assertEditable('mark');
        $this->state           = self::STATE_BLOCKED;
        $this->duplicateOf     = $of;
        $this->duplicateReason = mb_substr($reason, 0, self::REASON_LENGTH);
    }

    /** The check found no duplicate any more (a typo corrected) — open again. */
    public function clearDuplicateMark(): void
    {
        $this->assertEditable('clear');
        $this->state           = self::STATE_OPEN;
        $this->duplicateOf     = null;
        $this->duplicateReason = '';
    }

    /** «Kein Doppel — übernehmen»: the owner decided it is NOT a duplicate of the marked invoice. */
    public function releaseDuplicate(string $by, \DateTimeImmutable $at): void
    {
        if (!$this->isBlocked()) {
            throw new \LogicException("Creditor invoice {$this->id} is {$this->state} — only a blocked invoice is released");
        }
        $this->duplicateClearedOf = $this->duplicateOf;
        $this->duplicateClearedBy = $by;
        $this->duplicateClearedAt = $at;
        $this->state              = self::STATE_OPEN;
        $this->duplicateOf        = null;
        $this->duplicateReason    = '';
        $this->touch($by, $at);
    }

    private function touch(string $by, \DateTimeImmutable $at): void
    {
        $this->changedBy = $by;
        $this->changedAt = $at;
    }

    private function assertEditable(string $verb): void
    {
        if (!$this->isEditable()) {
            throw new \LogicException("Creditor invoice {$this->id} is {$this->state} — only an open or blocked invoice can be asked to {$verb}");
        }
    }
}
