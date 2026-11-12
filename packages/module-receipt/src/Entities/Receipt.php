<?php

namespace Z77\Module\Receipt\Entities;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Persistence\Doctrine\Type\MoneyType;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Money\Money;

/**
 * One row of the RECEIPT JOURNAL (owner decision E1, 2026-11-12; plan T4): what a phone (or later
 * the backend) captured — the receipt date, a text, the GROSS amount, the photo in the DMS — and
 * what the template (debit, VAT), the payment method (credit, owner decision E10) or the owner
 * added. Nothing reaches the ledger
 * before «Journal verbuchen» (`ReceiptJournalPostingService::post()`), which posts the receipt as
 * ONE generated journal entry (source `receipt`, ref = the id, key `receipt:{id}`) and moves it
 * `captured` → `posted`.
 *
 * COMPLETE (postable) = text, debit, credit AND the VAT decision: a tax code OR «without VAT»
 * ({@see missing()}). A receipt carries the accounts as NUMBERS (the reference shape of the
 * mandator's account settings and the payment target), the tax code as module-vat's CODE
 * (ADR-043 decision 19, no foreign key), the template and the payment method as their `code` —
 * every one checked when it is set and again by the ledger when posted. The payment method's
 * credit account is RESOLVED into `credit_account` at capture; a correction changes the account,
 * `payment_method_code` stays the record of what the phone chose.
 *
 * The link receipt ↔ booking lives HERE (`document_id` + `ledger_entry_ref`); `JournalEntry` stays
 * unchanged (plan T4, replaces B5). `ledger_entry_ref` is `{fiscal-year}/{number}` — how a journal
 * names an entry, the shape debtor's `invoice.ledger_entry_ref` has.
 *
 * Who captured it is a SNAPSHOT (E8): `captured_by` (the backend user the device belongs to),
 * `device_id` / `device_name` (the gateway's device, null = not through a device). `capture_key`
 * is the client's idempotency key (the phone's offline queue may send twice, E7): unique, a repeat
 * answers with the existing row.
 *
 * Optimistic lock: every correction, deletion and the posting name the `version` the caller saw.
 *
 * Table `receipt` (`Version20261112100000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'receipt')]
#[ORM\UniqueConstraint(name: Receipt::UNIQUE_CAPTURE_KEY, columns: ['capture_key'])]
#[ORM\Index(name: 'idx_receipt_state_date', columns: ['state', 'receipt_date'])]
#[ORM\Index(name: 'idx_receipt_document', columns: ['document_id'])]
class Receipt
{
    public const UNIQUE_CAPTURE_KEY  = 'uniq_receipt_capture_key';
    public const TEXT_LENGTH         = JournalEntry::TEXT_LENGTH;
    public const ACTOR_LENGTH        = JournalEntry::ACTOR_LENGTH;
    public const TEMPLATE_LENGTH     = 16;
    public const DEVICE_ID_LENGTH    = 64;
    public const DEVICE_NAME_LENGTH  = 80;
    public const CAPTURE_KEY_LENGTH  = 64;
    public const ENTRY_REF_LENGTH    = 40;
    /** The origin the ledger records for every receipt posting (`journal_entry.source_type`). */
    public const SOURCE_TYPE         = 'receipt';

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(length: 12)]
    private string $state = 'captured';

    #[ORM\Column(name: 'receipt_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $receiptDate;

    /** The posting text; '' = not written yet (incomplete). */
    #[ORM\Column(length: self::TEXT_LENGTH)]
    private string $text = '';

    /** The GROSS amount of the receipt, in the base currency, positive. */
    #[ORM\Column(type: MoneyType::NAME)]
    private Money $amount;

    #[ORM\Column(name: 'template_code', length: self::TEMPLATE_LENGTH, nullable: true)]
    private ?string $templateCode = null;

    /** The payment method («Bezahlt mit», E10) the credit account came from; null = none chosen. */
    #[ORM\Column(name: 'payment_method_code', length: self::TEMPLATE_LENGTH, nullable: true)]
    private ?string $paymentMethodCode = null;

    #[ORM\Column(name: 'debit_account', length: Account::NUMBER_LENGTH, nullable: true)]
    private ?string $debitAccount = null;

    #[ORM\Column(name: 'credit_account', length: Account::NUMBER_LENGTH, nullable: true)]
    private ?string $creditAccount = null;

    /** A module-vat code; null = none (see {@see $withoutVat}). */
    #[ORM\Column(name: 'tax_code', length: JournalLine::TAX_CODE_LENGTH, nullable: true)]
    private ?string $taxCode = null;

    /** The explicit decision «without VAT» — never together with a tax code. */
    #[ORM\Column(name: 'without_vat')]
    private bool $withoutVat = false;

    /** The photo — a DMS document id (file store, no foreign key); the parts of a split receipt share it (E12). */
    #[ORM\Column(name: 'document_id')]
    private int $documentId;

    #[ORM\Column(name: 'device_id', length: self::DEVICE_ID_LENGTH, nullable: true)]
    private ?string $deviceId = null;

    #[ORM\Column(name: 'device_name', length: self::DEVICE_NAME_LENGTH, nullable: true)]
    private ?string $deviceName = null;

    #[ORM\Column(name: 'captured_by', length: self::ACTOR_LENGTH)]
    private string $capturedBy;

    #[ORM\Column(name: 'captured_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $capturedAt;

    #[ORM\Column(name: 'capture_key', length: self::CAPTURE_KEY_LENGTH, nullable: true)]
    private ?string $captureKey = null;

    #[ORM\Column(name: 'changed_by', length: self::ACTOR_LENGTH, nullable: true)]
    private ?string $changedBy = null;

    #[ORM\Column(name: 'changed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $changedAt = null;

    /** `{fiscal-year}/{number}` of the journal entry the posting wrote; null while captured. */
    #[ORM\Column(name: 'ledger_entry_ref', length: self::ENTRY_REF_LENGTH, nullable: true)]
    private ?string $ledgerEntryRef = null;

    #[ORM\ManyToOne(targetEntity: ReceiptJournalPosting::class)]
    #[ORM\JoinColumn(name: 'journal_posting_id', referencedColumnName: 'id', nullable: true)]
    private ?ReceiptJournalPosting $journalPosting = null;

    #[ORM\Column(name: 'posted_by', length: self::ACTOR_LENGTH, nullable: true)]
    private ?string $postedBy = null;

    #[ORM\Column(name: 'posted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $postedAt = null;

    /** Optimistic lock: every write names the version its caller saw. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    /**
     * A captured receipt — built by `ReceiptCaptureService` only, after it checked every value.
     */
    public function __construct(
        \DateTimeImmutable $receiptDate,
        Money $amount,
        string $text,
        int $documentId,
        string $capturedBy,
        \DateTimeImmutable $capturedAt,
        ?string $deviceId = null,
        ?string $deviceName = null,
        ?string $captureKey = null,
    ) {
        if (!$amount->isPositive()) {
            throw new \LogicException('A receipt amount is positive');
        }
        if (trim($capturedBy) === '') {
            throw new \LogicException('A receipt names who captured it');
        }
        $this->receiptDate = $receiptDate;
        $this->amount      = $amount;
        $this->text        = $text;
        $this->documentId  = $documentId;
        $this->capturedBy  = $capturedBy;
        $this->capturedAt  = $capturedAt;
        $this->deviceId    = $deviceId;
        $this->deviceName  = $deviceName;
        $this->captureKey  = $captureKey;
    }

    public function getId(): ?int { return $this->id; }
    public function getState(): ReceiptState { return ReceiptState::from($this->state); }
    public function isCaptured(): bool { return $this->state === ReceiptState::Captured->value; }
    public function isPosted(): bool { return $this->state === ReceiptState::Posted->value; }
    public function getReceiptDate(): \DateTimeImmutable { return $this->receiptDate; }
    public function getText(): string { return $this->text; }
    public function getAmount(): Money { return $this->amount; }
    public function getTemplateCode(): ?string { return $this->templateCode; }
    public function getPaymentMethodCode(): ?string { return $this->paymentMethodCode; }
    public function getDebitAccount(): ?string { return $this->debitAccount; }
    public function getCreditAccount(): ?string { return $this->creditAccount; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function isWithoutVat(): bool { return $this->withoutVat; }
    public function getDocumentId(): int { return $this->documentId; }
    public function getDeviceId(): ?string { return $this->deviceId; }
    public function getDeviceName(): ?string { return $this->deviceName; }
    public function getCapturedBy(): string { return $this->capturedBy; }
    public function getCapturedAt(): \DateTimeImmutable { return $this->capturedAt; }
    public function getCaptureKey(): ?string { return $this->captureKey; }
    public function getChangedBy(): ?string { return $this->changedBy; }
    public function getChangedAt(): ?\DateTimeImmutable { return $this->changedAt; }
    public function getLedgerEntryRef(): ?string { return $this->ledgerEntryRef; }
    public function getJournalPosting(): ?ReceiptJournalPosting { return $this->journalPosting; }
    public function getPostedBy(): ?string { return $this->postedBy; }
    public function getPostedAt(): ?\DateTimeImmutable { return $this->postedAt; }
    public function getVersion(): int { return $this->version; }

    /** The idempotency key of its posting — derived from the source, stable across retries. */
    public function idempotencyKey(): string
    {
        return self::SOURCE_TYPE . ':' . $this->id;
    }

    /**
     * What keeps the receipt from being posted, as German labels in field order — empty = complete.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = [];
        if (trim($this->text) === '') {
            $missing[] = 'Text';
        }
        if ($this->debitAccount === null) {
            $missing[] = 'Soll';
        }
        if ($this->creditAccount === null) {
            $missing[] = 'Haben';
        }
        if ($this->taxCode === null && !$this->withoutVat) {
            $missing[] = 'MWST';
        }

        return $missing;
    }

    public function isComplete(): bool
    {
        return $this->missing() === [];
    }

    /** «Handy Peter · iPhone» / «Peter» — who captured it, for the journal (E8). */
    public function capturedByLabel(): string
    {
        if ($this->deviceId === null) {
            return $this->capturedBy;
        }

        return 'Handy ' . $this->capturedBy . ($this->deviceName !== null && $this->deviceName !== '' ? ' · ' . $this->deviceName : '');
    }

    // ── writes — the services check the values; the entity guards its state ────────────────────

    /** The template's debit account and VAT decision — at capture (ReceiptCaptureService). */
    public function applyTemplate(ReceiptTemplate $template): void
    {
        $this->assertCaptured('apply a template to');
        $this->templateCode = $template->getCode();
        $this->debitAccount = $template->getDebitAccount() !== '' ? $template->getDebitAccount() : null;
        $this->setVat($template->isWithoutVat() ? null : $template->getTaxCode(), $template->isWithoutVat());
    }

    /** The payment method's credit account, resolved — at capture (E10). */
    public function applyPaymentMethod(PaymentMethod $method): void
    {
        $this->assertCaptured('apply a payment method to');
        $this->paymentMethodCode = $method->getCode();
        $this->creditAccount     = $method->getCreditAccount() !== '' ? $method->getCreditAccount() : null;
    }

    /**
     * SPLIT a mixed-VAT receipt (owner decision E12): this receipt keeps its amount minus $part; the
     * returned NEW captured receipt carries $part and shares the photo (`document_id`), the date,
     * the text, the payment method with its credit account and the capture data. Its debit account,
     * VAT decision and template are left open for the owner (the reason to split is another VAT
     * code); it has no capture key (the key names the phone's one send).
     */
    public function splitOff(Money $part, string $changedBy, \DateTimeImmutable $changedAt): self
    {
        $this->assertCaptured('split');
        if (!$part->isPositive() || !$part->lessThan($this->amount)) {
            throw new \LogicException('A split part is positive and smaller than the receipt amount');
        }
        $new = new self($this->receiptDate, $part, $this->text, $this->documentId, $this->capturedBy, $this->capturedAt, $this->deviceId, $this->deviceName);
        $new->paymentMethodCode = $this->paymentMethodCode;
        $new->creditAccount     = $this->creditAccount;
        $new->changedBy         = $changedBy;
        $new->changedAt         = $changedAt;
        $this->amount    = $this->amount->subtract($part);
        $this->changedBy = $changedBy;
        $this->changedAt = $changedAt;

        return $new;
    }

    /** A correction in the journal (ReceiptJournalService::update()). */
    public function correct(
        \DateTimeImmutable $receiptDate,
        Money $amount,
        string $text,
        ?string $debitAccount,
        ?string $creditAccount,
        ?string $taxCode,
        bool $withoutVat,
        string $changedBy,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->assertCaptured('correct');
        if (!$amount->isPositive()) {
            throw new \LogicException('A receipt amount is positive');
        }
        $this->receiptDate   = $receiptDate;
        $this->amount        = $amount;
        $this->text          = $text;
        $this->debitAccount  = $debitAccount;
        $this->creditAccount = $creditAccount;
        $this->setVat($taxCode, $withoutVat);
        $this->changedBy     = $changedBy;
        $this->changedAt     = $changedAt;
    }

    /** «Journal verbuchen»: posted, with its entry and the protocol of the batch. */
    public function post(string $ledgerEntryRef, ReceiptJournalPosting $protocol, string $postedBy, \DateTimeImmutable $postedAt): void
    {
        $this->assertCaptured('post');
        if (!$this->isComplete()) {
            throw new \LogicException("Receipt {$this->id} is incomplete — missing " . implode(', ', $this->missing()));
        }
        $this->state          = ReceiptState::Posted->value;
        $this->ledgerEntryRef = $ledgerEntryRef;
        $this->journalPosting = $protocol;
        $this->postedBy       = $postedBy;
        $this->postedAt       = $postedAt;
    }

    private function setVat(?string $taxCode, bool $withoutVat): void
    {
        if ($taxCode !== null && $withoutVat) {
            throw new \LogicException('A receipt carries a tax code OR «without VAT», never both');
        }
        $this->taxCode    = $taxCode;
        $this->withoutVat = $withoutVat;
    }

    private function assertCaptured(string $verb): void
    {
        if (!$this->isCaptured()) {
            throw new \LogicException("Receipt {$this->id} is {$this->state} — only a captured receipt can be asked to {$verb}");
        }
    }
}
