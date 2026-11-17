<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * The ANCHOR of a bank account's ledger account (ADR-061 decisions 22–24, stage C4): the
 * difference between the bank's balance and the ledger account's balance at the OPENING of one
 * day, confirmed by the office — «Bank X · Fibu Y · Differenz Z — bestätigen?».
 *
 * Private movements are never booked, so on a mixed account the two balances differ by the
 * private history; the anchor names that difference once. From there on the difference moves only
 * by the private movements: bank balance − anchor − private movements = ledger balance — which is
 * what the breakdown of the check compares. A purely business account has the anchor 0.00.
 *
 * `as_of` is the day whose OPENING the three figures describe: «Abgleich ab» for the first
 * anchor, the first day of a fiscal year for one confirmed again after a mismatch at the year
 * change (decision 24 — the anchor of a new year is COMPUTED, old + private net; only a mismatch
 * asks). One row per bank account and day; a row is written once and never changed
 * (`Bank\BankReconciliation::confirm()`). Table `bank_anchor` (`Version20261117200000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'bank_anchor')]
#[ORM\UniqueConstraint(name: BankAnchor::UNIQUE_DAY, columns: ['bank_account_id', 'as_of'])]
class BankAnchor
{
    public const UNIQUE_DAY = 'uniq_bank_anchor_day';

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(name: 'bank_account_id', nullable: false)]
    private BankAccount $bankAccount;

    /** The day whose opening the figures describe. */
    #[ORM\Column(name: 'as_of', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $asOf;

    /** The booked balance of the bank at the opening of {@see $asOf} (signed). */
    #[ORM\Column(name: 'bank_balance', type: MoneyType::NAME)]
    private Money $bankBalance;

    /** The balance of the ledger account (debit − credit) at the opening of {@see $asOf} (signed). */
    #[ORM\Column(name: 'ledger_balance', type: MoneyType::NAME)]
    private Money $ledgerBalance;

    /** Bank − ledger — the anchor. */
    #[ORM\Column(type: MoneyType::NAME)]
    private Money $difference;

    #[ORM\Column(name: 'confirmed_by', length: JournalEntry::ACTOR_LENGTH)]
    private string $confirmedBy;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $confirmedAt;

    public function __construct(BankAccount $bankAccount, \DateTimeImmutable $asOf, Money $bankBalance, Money $ledgerBalance, string $confirmedBy, \DateTimeImmutable $confirmedAt)
    {
        $this->bankAccount   = $bankAccount;
        $this->asOf          = $asOf->setTime(0, 0);
        $this->bankBalance   = $bankBalance;
        $this->ledgerBalance = $ledgerBalance;
        $this->difference    = $bankBalance->subtract($ledgerBalance);
        $this->confirmedBy   = mb_substr($confirmedBy, 0, JournalEntry::ACTOR_LENGTH);
        $this->confirmedAt   = $confirmedAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getBankAccount(): BankAccount { return $this->bankAccount; }
    public function getAsOf(): \DateTimeImmutable { return $this->asOf; }
    public function getBankBalance(): Money { return $this->bankBalance; }
    public function getLedgerBalance(): Money { return $this->ledgerBalance; }
    public function getDifference(): Money { return $this->difference; }
    public function getConfirmedBy(): string { return $this->confirmedBy; }
    public function getConfirmedAt(): \DateTimeImmutable { return $this->confirmedAt; }
}
