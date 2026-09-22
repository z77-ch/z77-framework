<?php

namespace Z77\Module\Financial\Ledger;

use Z77\Module\Financial\Entities\JournalLine;
use Z77\Shared\Money\Money;

/**
 * One line of a {@see PostingRequest} — what a posting source hands the
 * ledger for a `JournalLine` (ADR-042 decision 5, ADR-041 decision 7).
 * Immutable, validated on construction, so `LedgerService::post()` works on
 * a request that is already well-formed and can spend its checks on what
 * only the database knows (accounts, periods, tax codes).
 *
 * The account is named by NUMBER — the identity every configuration uses
 * (plan §5.1); the ledger resolves it. Exactly one of debit / credit is
 * positive, the other zero. Tax data is all-or-none and SIGNED as the
 * poster means it (a credit note or a reversal negates base and amount);
 * the ledger stores, the VAT return sums (plan §5.6).
 */
final class PostingLine
{
    public function __construct(
        public readonly string $account,
        public readonly Money $debit,
        public readonly Money $credit,
        public readonly ?string $taxCode = null,
        public readonly ?int $taxRate = null,
        public readonly ?Money $taxBase = null,
        public readonly ?Money $taxAmount = null,
        public readonly ?string $text = null,
    ) {
        if (!preg_match('/^[0-9]{1,10}$/', $account)) {
            throw new \InvalidArgumentException("Posting line: account must be an account number (digits), got '{$account}'");
        }
        if ($debit->isNegative() || $credit->isNegative()) {
            throw new \InvalidArgumentException("Posting line {$account}: amounts are never negative — post the other side instead");
        }
        if ($debit->isPositive() === $credit->isPositive()) {
            throw new \InvalidArgumentException("Posting line {$account}: exactly one of debit and credit is positive");
        }
        if ($debit->currency !== $credit->currency) {
            throw new \InvalidArgumentException("Posting line {$account}: debit and credit in different currencies");
        }
        $withTax = $taxCode !== null;
        if ($withTax) {
            if (trim($taxCode) === '' || mb_strlen($taxCode) > JournalLine::TAX_CODE_LENGTH) {
                throw new \InvalidArgumentException("Posting line {$account}: tax code must be 1-" . JournalLine::TAX_CODE_LENGTH . ' characters');
            }
            if ($taxRate === null || $taxRate < 0) {
                throw new \InvalidArgumentException("Posting line {$account}: a tax code needs the rate that applied (hundredths of a percent, >= 0)");
            }
            if ($taxBase === null || $taxAmount === null) {
                throw new \InvalidArgumentException("Posting line {$account}: a tax code needs tax base and tax amount");
            }
            if ($taxBase->currency !== $debit->currency || $taxAmount->currency !== $debit->currency) {
                throw new \InvalidArgumentException("Posting line {$account}: tax base and amount in another currency than the line");
            }
        } elseif ($taxRate !== null || $taxBase !== null || $taxAmount !== null) {
            throw new \InvalidArgumentException("Posting line {$account}: tax rate, base and amount need a tax code");
        }
        if ($text !== null && mb_strlen($text) > JournalLine::TEXT_LENGTH) {
            throw new \InvalidArgumentException("Posting line {$account}: text longer than " . JournalLine::TEXT_LENGTH . ' characters');
        }
    }

    /** A debit line — the usual way a caller builds one. */
    public static function debit(string $account, Money $amount, ?string $text = null, ?string $taxCode = null, ?int $taxRate = null, ?Money $taxBase = null, ?Money $taxAmount = null): self
    {
        return new self($account, $amount, Money::zero($amount->currency), $taxCode, $taxRate, $taxBase, $taxAmount, $text);
    }

    /** A credit line. */
    public static function credit(string $account, Money $amount, ?string $text = null, ?string $taxCode = null, ?int $taxRate = null, ?Money $taxBase = null, ?Money $taxAmount = null): self
    {
        return new self($account, Money::zero($amount->currency), $amount, $taxCode, $taxRate, $taxBase, $taxAmount, $text);
    }

    public function hasTax(): bool
    {
        return $this->taxCode !== null;
    }

    /** The same line with debit and credit swapped and the tax data negated — a reversal. */
    public function swapped(): self
    {
        return new self(
            $this->account,
            $this->credit,
            $this->debit,
            $this->taxCode,
            $this->taxRate,
            $this->taxBase?->negate(),
            $this->taxAmount?->negate(),
            $this->text,
        );
    }

    /**
     * The line as the idempotency comparison sees it — the same shape
     * `JournalLine::snapshot()` yields, minus what only the stored line has
     * (position, account name).
     *
     * @return array<string, mixed>
     */
    public function fingerprint(): array
    {
        return [
            'account'    => $this->account,
            'debit'      => $this->debit->toDecimal(),
            'credit'     => $this->credit->toDecimal(),
            'tax_code'   => $this->taxCode,
            'tax_rate'   => $this->taxRate,
            'tax_base'   => $this->taxBase?->toDecimal(),
            'tax_amount' => $this->taxAmount?->toDecimal(),
            'text'       => $this->text,
        ];
    }
}
