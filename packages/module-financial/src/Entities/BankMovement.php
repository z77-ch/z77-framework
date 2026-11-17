<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money,
    Z77\Shared\Payment\Iban
;

/**
 * One MOVEMENT on a bank account — a booked entry (`Ntry`) of a camt.053 statement, as the bank
 * reports it (ADR-060). A movement is INFORMATION: where the creditor journal looks up when an
 * invoice was paid and how many CHF the bank debited for it. It never posts (decision 1).
 *
 * What is kept: the statement that delivered it and its position there; the bank's entry
 * reference (`Ntry/AcctSvcrRef`) — or none, then the `dedup_key` is a fingerprint and
 * {@see isFingerprinted()} says so; the direction (the amount is always positive), the reversal
 * flag, booking and value date, the amount in the account's currency and the account's BALANCE
 * after the movement (the chain is seamless, so it is exact); for a foreign purchase the
 * instructed amount, its currency and the bank's rate (`rate_source` `structured` — a rate read
 * from free text would say `text`); the bank transaction code (`PMNT/CCRD/POSD`); the
 * counterparty (the creditor of a debit, the debtor of a credit) with its IBAN; the structured
 * reference (QRR / SCOR) and the text (remittance message and `AddtlNtryInf` — the only place a
 * card purchase names its merchant). `tx_count` is the number of transactions inside a
 * collective entry (1 for a plain booking); counterparty and reference are only filled for 1.
 *
 * Immutable: the file's data never changes, and there is no manual state (owner decision 19 of
 * 2026-11-16 — «Erledigt» was dropped). Which invoice took a movement is recorded from stage B2.
 *
 * `dedup_key` is unique per bank account: nothing is stored twice (decision 6).
 * Table `bank_movement` (`Version20261116140000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'bank_movement')]
#[ORM\UniqueConstraint(name: BankMovement::UNIQUE_DEDUP, columns: ['bank_account_id', 'dedup_key'])]
#[ORM\Index(name: 'idx_bank_movement_booking', columns: ['bank_account_id', 'booking_date'])]
#[ORM\Index(name: 'idx_bank_movement_reference', columns: ['reference'])]
class BankMovement
{
    public const UNIQUE_DEDUP = 'uniq_bank_movement_dedup';

    public const DEBIT  = 'debit';
    public const CREDIT = 'credit';

    public const RATE_STRUCTURED = 'structured';
    public const RATE_TEXT       = 'text';

    /** The prefix of a `dedup_key` that is a fingerprint (the bank sent no entry reference). */
    public const FINGERPRINT_PREFIX = 'fp:';

    public const ENTRY_REF_LENGTH = 35;
    public const DEDUP_KEY_LENGTH = 70;
    public const TX_CODE_LENGTH   = 20;
    public const NAME_LENGTH      = 140;
    public const REF_TYPE_LENGTH  = 4;
    public const REFERENCE_LENGTH = 35;
    public const TEXT_LENGTH      = 1000;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(name: 'bank_account_id', nullable: false)]
    private BankAccount $bankAccount;

    /** The statement that delivered the movement FIRST; a later overlapping statement does not take it over. */
    #[ORM\ManyToOne(targetEntity: BankStatement::class)]
    #[ORM\JoinColumn(name: 'statement_id', nullable: false)]
    private BankStatement $statement;

    /** Order within that statement, from 1. */
    #[ORM\Column]
    private int $position;

    /** The bank's `Ntry/AcctSvcrRef`; null when the bank sent none. */
    #[ORM\Column(name: 'entry_ref', length: self::ENTRY_REF_LENGTH, nullable: true)]
    private ?string $entryRef;

    /** The entry reference, or `fp:` + a fingerprint — unique per bank account. */
    #[ORM\Column(name: 'dedup_key', length: self::DEDUP_KEY_LENGTH)]
    private string $dedupKey;

    /** {@see self::DEBIT} (money left the account) or {@see self::CREDIT}. */
    #[ORM\Column(length: 6)]
    private string $direction;

    /** `RvslInd` — the entry reverses an earlier one. */
    #[ORM\Column]
    private bool $reversal;

    /** The bank's booking date — the date of the statement's balance, and «Bezahlt am» (decision 5). */
    #[ORM\Column(name: 'booking_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $bookingDate;

    #[ORM\Column(name: 'value_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $valueDate;

    /** Always positive, in the account's currency (stage 1: the base currency). */
    #[ORM\Column(type: MoneyType::NAME)]
    private Money $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    /** The account's booked balance AFTER this movement, signed — negative on an overdrawn account. */
    #[ORM\Column(name: 'balance', type: MoneyType::NAME)]
    private Money $balance;

    /** The instructed amount of a foreign-currency transaction, two decimals; null for a plain one. */
    #[ORM\Column(name: 'original_amount', type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $originalAmount;

    #[ORM\Column(name: 'original_currency', length: 3, nullable: true)]
    private ?string $originalCurrency;

    /** The bank's rate as sent; stored only with a foreign amount. */
    #[ORM\Column(name: 'exchange_rate', type: Types::DECIMAL, precision: 18, scale: 8, nullable: true)]
    private ?string $exchangeRate;

    /** {@see self::RATE_STRUCTURED} | {@see self::RATE_TEXT} | null (no rate). */
    #[ORM\Column(name: 'rate_source', length: 10, nullable: true)]
    private ?string $rateSource;

    /** ISO bank transaction code as `Domain/Family/SubFamily`, '' without one. */
    #[ORM\Column(name: 'bank_tx_code', length: self::TX_CODE_LENGTH)]
    private string $bankTxCode;

    #[ORM\Column(name: 'counterparty_name', length: self::NAME_LENGTH)]
    private string $counterpartyName;

    #[ORM\Column(name: 'counterparty_iban', length: Iban::MAX_LENGTH)]
    private string $counterpartyIban;

    /** QRR | SCOR | NON | ''. */
    #[ORM\Column(name: 'reference_type', length: self::REF_TYPE_LENGTH)]
    private string $referenceType;

    #[ORM\Column(length: self::REFERENCE_LENGTH)]
    private string $reference;

    /** The remittance message and the bank's additional entry information, as sent — foreign input, escaped on output. */
    #[ORM\Column(length: self::TEXT_LENGTH)]
    private string $text;

    /** Transactions inside the entry: 1 for a plain booking, more for a collective one. */
    #[ORM\Column(name: 'tx_count')]
    private int $txCount;

    /**
     * @param string|null $entryRef the bank's entry reference; null → $fingerprint is the dedup key
     * @param string      $fingerprint 64 hex characters (SHA-256), used only without an entry reference
     */
    public function __construct(
        BankAccount $bankAccount,
        BankStatement $statement,
        int $position,
        ?string $entryRef,
        string $fingerprint,
        bool $isCredit,
        bool $reversal,
        \DateTimeImmutable $bookingDate,
        ?\DateTimeImmutable $valueDate,
        Money $amount,
        Money $balance,
        ?string $originalAmount,
        ?string $originalCurrency,
        ?string $exchangeRate,
        ?string $rateSource,
        string $bankTxCode,
        string $counterpartyName,
        string $counterpartyIban,
        string $referenceType,
        string $reference,
        string $text,
        int $txCount,
    ) {
        $this->bankAccount      = $bankAccount;
        $this->statement        = $statement;
        $this->position         = $position;
        $this->entryRef         = $entryRef;
        $this->dedupKey         = self::dedupKey($entryRef, $fingerprint);
        $this->direction        = $isCredit ? self::CREDIT : self::DEBIT;
        $this->reversal         = $reversal;
        $this->bookingDate      = $bookingDate;
        $this->valueDate        = $valueDate;
        $this->amount           = $amount;
        $this->currency         = $amount->currency;
        $this->balance          = $balance;
        $this->originalAmount   = $originalAmount;
        $this->originalCurrency = $originalCurrency;
        $this->exchangeRate     = $exchangeRate;
        $this->rateSource       = $rateSource;
        $this->bankTxCode       = mb_substr($bankTxCode, 0, self::TX_CODE_LENGTH);
        $this->counterpartyName = mb_substr(trim($counterpartyName), 0, self::NAME_LENGTH);
        $this->counterpartyIban = mb_substr($counterpartyIban, 0, Iban::MAX_LENGTH);
        $this->referenceType    = mb_substr($referenceType, 0, self::REF_TYPE_LENGTH);
        $this->reference        = mb_substr(trim($reference), 0, self::REFERENCE_LENGTH);
        $this->text             = mb_substr(trim($text), 0, self::TEXT_LENGTH);
        $this->txCount          = max(1, $txCount);
    }

    /** The key a movement is unique by within its bank account: the bank's entry reference, else the fingerprint. */
    public static function dedupKey(?string $entryRef, string $fingerprint): string
    {
        return $entryRef !== null && $entryRef !== '' ? $entryRef : self::FINGERPRINT_PREFIX . $fingerprint;
    }

    /** True when the bank sent no entry reference and the movement is told apart by a fingerprint. */
    public function isFingerprinted(): bool
    {
        return $this->entryRef === null;
    }

    /** The amount with its sign on the account: positive for a credit, negative for a debit. */
    public function signedAmount(): Money
    {
        return $this->direction === self::CREDIT ? $this->amount : $this->amount->negate();
    }

    public function getId(): ?int { return $this->id; }
    public function getBankAccount(): BankAccount { return $this->bankAccount; }
    public function getStatement(): BankStatement { return $this->statement; }
    public function getPosition(): int { return $this->position; }
    public function getEntryRef(): ?string { return $this->entryRef; }
    public function getDedupKey(): string { return $this->dedupKey; }
    public function getDirection(): string { return $this->direction; }
    public function isCredit(): bool { return $this->direction === self::CREDIT; }
    public function isReversal(): bool { return $this->reversal; }
    public function getBookingDate(): \DateTimeImmutable { return $this->bookingDate; }
    public function getValueDate(): ?\DateTimeImmutable { return $this->valueDate; }
    public function getAmount(): Money { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getBalance(): Money { return $this->balance; }
    public function getOriginalAmount(): ?string { return $this->originalAmount; }
    public function getOriginalCurrency(): ?string { return $this->originalCurrency; }
    public function getExchangeRate(): ?string { return $this->exchangeRate; }
    public function getRateSource(): ?string { return $this->rateSource; }
    public function getBankTxCode(): string { return $this->bankTxCode; }
    public function getCounterpartyName(): string { return $this->counterpartyName; }
    public function getCounterpartyIban(): string { return $this->counterpartyIban; }
    public function getReferenceType(): string { return $this->referenceType; }
    public function getReference(): string { return $this->reference; }
    public function getText(): string { return $this->text; }
    public function getTxCount(): int { return $this->txCount; }
}
