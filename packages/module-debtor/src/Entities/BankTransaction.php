<?php

namespace Z77\Module\Debtor\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * One credit of a CAMT.054 message (plan §6.4): the bank's transaction as
 * read from the file — its reference within the message (`AcctSvcrRef`,
 * else `TxId`, else `EndToEndId`, else its position; unique per message,
 * the second dedup key), value and booking date, amount, the structured
 * reference (QRR with the 27 digits, SCOR, or NON) and the unstructured
 * message, the debtor as the bank names it — and what the receivables
 * side did with it: the {@see TransactionState}, the invoice it was
 * matched to, the {@see Payment} that booked it, the REMAINDER the booking
 * could not place (an overpayment: the open amount was less than the
 * credit), and a note in German saying why it stands where it stands.
 *
 * The file's data never changes; the state, the invoice, the payment, the
 * remainder and the note are written by `BankImportService` only.
 * Table `bank_transaction` (`Version20261007100000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'bank_transaction')]
#[ORM\UniqueConstraint(name: BankTransaction::UNIQUE_REF, columns: ['message_id', 'tx_ref'])]
#[ORM\Index(name: 'idx_bank_transaction_state_date', columns: ['state', 'value_date'])]
class BankTransaction
{
    public const UNIQUE_REF = 'uniq_bank_transaction_ref';

    public const TX_REF_LENGTH    = 35;
    public const CURRENCY_LENGTH  = 3;
    public const REF_TYPE_LENGTH  = 4;
    public const REFERENCE_LENGTH = 35;
    public const MESSAGE_LENGTH   = 140;
    public const NAME_LENGTH      = 140;
    public const CITY_LENGTH      = 70;
    public const NOTE_LENGTH      = 255;

    public const REFERENCE_QRR  = 'QRR';
    public const REFERENCE_SCOR = 'SCOR';
    public const REFERENCE_NON  = 'NON';

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankMessage::class, inversedBy: 'transactions')]
    #[ORM\JoinColumn(name: 'message_id', nullable: false)]
    private BankMessage $message;

    /** Order within the message, from 1. */
    #[ORM\Column]
    private int $position;

    /** The bank's transaction reference — the dedup key within the message. */
    #[ORM\Column(name: 'tx_ref', length: self::TX_REF_LENGTH)]
    private string $txRef;

    #[ORM\Column(name: 'value_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $valueDate;

    #[ORM\Column(name: 'booking_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $bookingDate;

    /** The credit as the bank reports it (`MoneyType`, the file's currency in `currency`). */
    #[ORM\Column(type: MoneyType::NAME)]
    private Money $amount;

    #[ORM\Column(length: self::CURRENCY_LENGTH)]
    private string $currency;

    /** QRR | SCOR | NON | '' (no remittance information at all). */
    #[ORM\Column(name: 'reference_type', length: self::REF_TYPE_LENGTH)]
    private string $referenceType;

    /** The structured reference as sent (27 digits for QRR), '' without one. */
    #[ORM\Column(length: self::REFERENCE_LENGTH)]
    private string $reference;

    /** The unstructured message (`RmtInf/Ustrd`), cut to what the column holds. */
    #[ORM\Column(name: 'remittance', length: self::MESSAGE_LENGTH)]
    private string $remittance;

    #[ORM\Column(name: 'debtor_name', length: self::NAME_LENGTH)]
    private string $debtorName;

    #[ORM\Column(name: 'debtor_city', length: self::CITY_LENGTH)]
    private string $debtorCity;

    /** A {@see TransactionState} value, stored as its string. */
    #[ORM\Column(length: 12)]
    private string $state = TransactionState::Unmatched->value;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: true)]
    private ?Invoice $invoice = null;

    #[ORM\ManyToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(name: 'payment_id', nullable: true)]
    private ?Payment $payment = null;

    /** What the booking could not place on the invoice (credit − open amount); 0.00 otherwise. */
    #[ORM\Column(type: MoneyType::NAME)]
    private Money $remainder;

    /** Why it stands where it stands — German, for the screen. */
    #[ORM\Column(length: self::NOTE_LENGTH, nullable: true)]
    private ?string $note = null;

    public function __construct(BankMessage $message, int $position, string $txRef, \DateTimeImmutable $valueDate, ?\DateTimeImmutable $bookingDate, Money $amount, string $referenceType, string $reference, string $remittance, string $debtorName, string $debtorCity)
    {
        $this->message       = $message;
        $this->position      = $position;
        $this->txRef         = mb_substr(trim($txRef), 0, self::TX_REF_LENGTH);
        $this->valueDate     = $valueDate;
        $this->bookingDate   = $bookingDate;
        $this->amount        = $amount;
        $this->currency      = $amount->currency;
        $this->referenceType = mb_substr($referenceType, 0, self::REF_TYPE_LENGTH);
        $this->reference     = mb_substr(trim($reference), 0, self::REFERENCE_LENGTH);
        $this->remittance    = mb_substr(trim($remittance), 0, self::MESSAGE_LENGTH);
        $this->debtorName    = mb_substr(trim($debtorName), 0, self::NAME_LENGTH);
        $this->debtorCity    = mb_substr(trim($debtorCity), 0, self::CITY_LENGTH);
        $this->remainder     = Money::zero($amount->currency);
    }

    // ── the state machine, written by BankImportService only ────────────

    public function matchTo(Invoice $invoice, ?string $note = null): void
    {
        $this->assertNotBooked('matched');
        $this->invoice = $invoice;
        $this->state   = TransactionState::Matched->value;
        $this->note    = self::cut($note);
    }

    public function unmatch(?string $note): void
    {
        $this->assertNotBooked('unmatched');
        $this->invoice = null;
        $this->state   = TransactionState::Unmatched->value;
        $this->note    = self::cut($note);
    }

    public function ignore(?string $note): void
    {
        $this->assertNotBooked('ignored');
        $this->state = TransactionState::Ignored->value;
        $this->note  = self::cut($note);
    }

    /** Booked: the payment that placed it, and what could not be placed (an overpayment). */
    public function booked(Payment $payment, Money $remainder, ?string $note = null): void
    {
        $this->assertNotBooked('booked');
        if ($this->invoice === null) {
            throw new \LogicException('A transaction is booked against the invoice it was matched to');
        }
        $this->payment   = $payment;
        $this->remainder = $remainder;
        $this->state     = TransactionState::Booked->value;
        $this->note      = self::cut($note);
    }

    public function getId(): ?int { return $this->id; }
    public function getMessage(): BankMessage { return $this->message; }
    public function getPosition(): int { return $this->position; }
    public function getTxRef(): string { return $this->txRef; }
    public function getValueDate(): \DateTimeImmutable { return $this->valueDate; }
    public function getBookingDate(): ?\DateTimeImmutable { return $this->bookingDate; }
    public function getAmount(): Money { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getReferenceType(): string { return $this->referenceType; }
    public function getReference(): string { return $this->reference; }
    public function getRemittance(): string { return $this->remittance; }
    public function getDebtorName(): string { return $this->debtorName; }
    public function getDebtorCity(): string { return $this->debtorCity; }
    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function getPayment(): ?Payment { return $this->payment; }
    public function getRemainder(): Money { return $this->remainder; }
    public function getNote(): ?string { return $this->note; }

    public function state(): TransactionState
    {
        return TransactionState::from($this->state);
    }

    private function assertNotBooked(string $verb): void
    {
        if ($this->state === TransactionState::Booked->value) {
            throw new \LogicException("A booked transaction is not {$verb} again — correct the payment it booked");
        }
    }

    private static function cut(?string $note): ?string
    {
        return $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, self::NOTE_LENGTH);
    }
}
