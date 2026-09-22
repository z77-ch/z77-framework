<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * One line of a {@see JournalEntry} (ADR-042 decision 5): an {@see Account},
 * a debit OR a credit amount — exactly one of the two is positive, the other
 * is zero — and, when the line carries VAT, the tax data of ADR-041
 * decision 7 (net posting method).
 *
 * Tax data — `taxCode`, `taxRate`, `taxBase`, `taxAmount` — is all-or-none
 * and sits on the NET line (the revenue or expense line), never on the
 * tax-account line or the counter line: the VAT return is Σ `taxBase` and
 * Σ `taxAmount` per code and rate over these lines (plan §5.6), so exactly
 * one line per taxed amount may carry them. Base and amount are SIGNED as
 * the poster states them — a credit note or a reversal carries them
 * negated — so the return can sum them without asking which side the
 * account was on. The tax code is the file-based `TaxCode` of module-vat,
 * referenced BY CODE (ADR-043 decision 19 — no foreign key across the two
 * drivers), with the rate that applied SNAPSHOTTED (`taxRate`, hundredths
 * of a percent): the return groups by code + rate, and two rates of one
 * code can meet in one period (2023/2024).
 *
 * No currency (ADR-042 decision 4): every amount is in the base currency
 * `MoneyType` reads. `position` orders the lines of an entry; it is NOT
 * unique in the schema on purpose — Doctrine inserts before it deletes, and
 * an edit that replaces the lines would collide with itself.
 *
 * Immutable: a manual edit replaces the lines of its entry
 * (`JournalEntry::amend()`), a generated entry is never touched.
 *
 * Table `journal_line`.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'journal_line')]
#[ORM\Index(name: 'idx_journal_line_tax_code', columns: ['tax_code'])]
class JournalLine
{
    /** Longest tax code the column holds (`TaxCode`: `[A-Z0-9]{2,8}`). */
    public const TAX_CODE_LENGTH = 8;

    public const TEXT_LENGTH = 255;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: JournalEntry::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'entry_id', nullable: false)]
    private JournalEntry $entry;

    /** Order within the entry, from 1. */
    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', nullable: false)]
    private Account $account;

    #[ORM\Column(type: MoneyType::NAME)]
    private Money $debit;

    #[ORM\Column(type: MoneyType::NAME)]
    private Money $credit;

    #[ORM\Column(name: 'tax_code', length: self::TAX_CODE_LENGTH, nullable: true)]
    private ?string $taxCode;

    /** The rate that applied, in hundredths of a percent (8.1 % = 810) — snapshotted with the code. */
    #[ORM\Column(name: 'tax_rate', type: Types::INTEGER, nullable: true)]
    private ?int $taxRate;

    #[ORM\Column(name: 'tax_base', type: MoneyType::NAME, nullable: true)]
    private ?Money $taxBase;

    #[ORM\Column(name: 'tax_amount', type: MoneyType::NAME, nullable: true)]
    private ?Money $taxAmount;

    #[ORM\Column(length: self::TEXT_LENGTH, nullable: true)]
    private ?string $text;

    /**
     * @throws \LogicException the line is not one-sided or its tax data is incomplete — the
     *                         `PostingLine` DTO refuses these earlier with a message for the caller;
     *                         here they are the invariant, guarded once more
     */
    public function __construct(
        JournalEntry $entry,
        int $position,
        Account $account,
        Money $debit,
        Money $credit,
        ?string $taxCode = null,
        ?int $taxRate = null,
        ?Money $taxBase = null,
        ?Money $taxAmount = null,
        ?string $text = null,
    ) {
        if ($debit->isNegative() || $credit->isNegative() || $debit->isPositive() === $credit->isPositive()) {
            throw new \LogicException('A journal line carries exactly one positive amount, debit or credit');
        }
        $withTax = $taxCode !== null;
        if ($withTax !== ($taxRate !== null) || $withTax !== ($taxBase !== null) || $withTax !== ($taxAmount !== null)) {
            throw new \LogicException('Tax data on a journal line is all-or-none: code, rate, base and amount');
        }
        $this->entry     = $entry;
        $this->position  = $position;
        $this->account   = $account;
        $this->debit     = $debit;
        $this->credit    = $credit;
        $this->taxCode   = $taxCode;
        $this->taxRate   = $taxRate;
        $this->taxBase   = $taxBase;
        $this->taxAmount = $taxAmount;
        $this->text      = $text;
    }

    public function getId(): ?int { return $this->id; }
    public function getEntry(): JournalEntry { return $this->entry; }
    public function getAccount(): Account { return $this->account; }
    public function getDebit(): Money { return $this->debit; }
    public function getCredit(): Money { return $this->credit; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function getTaxRate(): ?int { return $this->taxRate; }
    public function getTaxBase(): ?Money { return $this->taxBase; }
    public function getTaxAmount(): ?Money { return $this->taxAmount; }
    public function getText(): ?string { return $this->text; }

    public function hasTax(): bool
    {
        return $this->taxCode !== null;
    }

    /**
     * What this line looked like — the shape an {@see EntryChange} stores
     * and the idempotency comparison reads. snake_case keys (Rule 6), amounts
     * as decimal strings, the account by NUMBER (its identity in every
     * configuration) with the name at that moment. `PostingLine::fingerprint()`
     * yields the same keys minus `position` and `account_name` — that is how
     * an UNCHANGED line of a manual edit is recognised.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'position'     => $this->position,
            'account'      => $this->account->getNumber(),
            'account_name' => $this->account->getName(),
            'debit'        => $this->debit->toDecimal(),
            'credit'       => $this->credit->toDecimal(),
            'tax_code'     => $this->taxCode,
            'tax_rate'     => $this->taxRate,
            'tax_base'     => $this->taxBase?->toDecimal(),
            'tax_amount'   => $this->taxAmount?->toDecimal(),
            'text'         => $this->text,
        ];
    }
}
