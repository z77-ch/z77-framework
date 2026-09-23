<?php

namespace Z77\Module\Debtor\Accounting;

use Z77\Shared\Money\Money;

/**
 * One line of a {@see PostingRequest} — debtor's OWN shape of a journal
 * line, the mirror of financial's `PostingLine` without depending on it
 * (ADR-040 decision 5: the adapter is the only class that knows financial,
 * and `NullAccountingGateway` runs where financial is not installed at all).
 *
 * Two ways to name the account:
 *
 *   - by NUMBER (`account`) — the receivable, a revenue account, the
 *     rounding account: debtor knows these (`debtorAccounts`, the line's
 *     `revenueAccount`);
 *   - by TAX CATEGORY (`vatCategory`) — the VAT line: which account the VAT
 *     of a category goes to is the bookkeeping's setting (financial's
 *     `vatAccounts`, Rule 2 — the one place), so debtor names the category
 *     and the ADAPTER resolves the number.
 *
 * Exactly one of debit / credit is positive. Tax data is all-or-none and
 * SIGNED as the document means it (a credit note negative), on the NET line
 * only (ADR-041 decision 7).
 */
final class PostingLine
{
    public function __construct(
        public readonly ?string $account,
        public readonly ?string $vatCategory,
        public readonly Money $debit,
        public readonly Money $credit,
        public readonly ?string $taxCode = null,
        public readonly ?int $taxRate = null,
        public readonly ?Money $taxBase = null,
        public readonly ?Money $taxAmount = null,
        public readonly ?string $text = null,
    ) {
        if (($account === null) === ($vatCategory === null)) {
            throw new \InvalidArgumentException('Posting line: exactly one of account number and VAT category names the account');
        }
        if ($account !== null && !preg_match('/^[0-9]{1,10}$/', $account)) {
            throw new \InvalidArgumentException("Posting line: account must be an account number (digits), got '{$account}'");
        }
        if ($vatCategory !== null && trim($vatCategory) === '') {
            throw new \InvalidArgumentException('Posting line: the VAT category must not be empty');
        }
        if ($debit->isNegative() || $credit->isNegative() || $debit->isPositive() === $credit->isPositive()) {
            throw new \InvalidArgumentException('Posting line: exactly one of debit and credit is positive, neither negative');
        }
        if ($debit->currency !== $credit->currency) {
            throw new \InvalidArgumentException('Posting line: debit and credit in different currencies');
        }
        $withTax = $taxCode !== null;
        if ($withTax !== ($taxRate !== null) || $withTax !== ($taxBase !== null) || $withTax !== ($taxAmount !== null)) {
            throw new \InvalidArgumentException('Posting line: tax data is all-or-none — code, rate, base and amount');
        }
        if ($withTax && ($taxBase->currency !== $debit->currency || $taxAmount->currency !== $debit->currency)) {
            throw new \InvalidArgumentException('Posting line: tax base and amount in another currency than the line');
        }
    }

    public static function debit(string $account, Money $amount, ?string $text = null, ?string $taxCode = null, ?int $taxRate = null, ?Money $taxBase = null, ?Money $taxAmount = null): self
    {
        return new self($account, null, $amount, Money::zero($amount->currency), $taxCode, $taxRate, $taxBase, $taxAmount, $text);
    }

    public static function credit(string $account, Money $amount, ?string $text = null, ?string $taxCode = null, ?int $taxRate = null, ?Money $taxBase = null, ?Money $taxAmount = null): self
    {
        return new self($account, null, Money::zero($amount->currency), $amount, $taxCode, $taxRate, $taxBase, $taxAmount, $text);
    }

    /** The VAT line of a category, on the debit side (a credit note). */
    public static function vatDebit(string $category, Money $amount, ?string $text = null): self
    {
        return new self(null, $category, $amount, Money::zero($amount->currency), text: $text);
    }

    /** The VAT line of a category, on the credit side (an invoice). */
    public static function vatCredit(string $category, Money $amount, ?string $text = null): self
    {
        return new self(null, $category, Money::zero($amount->currency), $amount, text: $text);
    }
}
