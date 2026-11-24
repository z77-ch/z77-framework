<?php

namespace Z77\Module\Debtor\Payments;

use Z77\Module\Debtor\Accounting\PostingLine;
use Z77\Module\Debtor\Accounting\PostingRequest;
use Z77\Module\Debtor\Entities\AllocationKind;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\PaymentAllocation;
use Z77\Shared\Money\Money;

/**
 * Turns one {@see PaymentAllocation} into the posting `PaymentService`
 * hands the accounting port (plan §6.3). Reads the invoice's SNAPSHOT —
 * its tax summary — never the tax tables (ADR-041 decision 6). One journal
 * entry per allocation, so a bank receipt covering three invoices is three
 * entries that each name their document.
 *
 * The shapes:
 *
 *   - **payment**: DEBIT the bank account of the payment target, CREDIT
 *     the receivable — money moved, nothing about turnover or VAT;
 *   - **charges** («Gebühren», DEBTOR-FEE-KIND-001): DEBIT the payment
 *     charges account, CREDIT the receivable — the same shape, no tax code,
 *     no tax data: a commission kept back by a card terminal, a payment
 *     provider or a bank is an expense, not a reduction of the consideration
 *     (MWST-Info 07, 1.1), so revenue and the VAT account stay untouched;
 *   - **discount / loss** («Skonto», «Verlust»): CREDIT the receivable
 *     with the amount, and DEBIT the discount (or loss) account per TAX
 *     CODE of the invoice with the NET share, carrying the tax data of the
 *     net method with NEGATIVE base and tax (the credit note's convention:
 *     a reduction of turnover posts on the opposite side with negated
 *     figures, so the VAT return sees less turnover under the rate of the
 *     supply), plus a DEBIT of the VAT account of the code's category
 *     with the tax share. The amount is split over the codes in proportion
 *     to their GROSS in the tax summary (base + tax), each share into net
 *     and tax by the code's rate (`Money::allocate`, no lost Rappen); the
 *     part of the invoice gross that carries no tax code (the rounding line)
 *     takes the REST without tax data, with its sign — a CREDIT of the
 *     counter account when the invoice was rounded down.
 *
 * Σ debit = Σ credit by construction. The text is built from the document
 * deterministically (FIN-IDEM-002); the idempotency key names the
 * allocation, so a repeated post of the same allocation returns the same
 * entry.
 */
final class PaymentPostingBuilder
{
    /**
     * @param string $receivableAccount `DebtorAccounts::postableNumber('receivable')`
     * @param string $counterAccount    the money's account (payment) or the discount / loss / charges account (resolved by the caller)
     */
    public static function build(PaymentAllocation $allocation, string $receivableAccount, string $counterAccount): PostingRequest
    {
        $invoice = $allocation->getInvoice();
        $kind    = $allocation->kind();
        $amount  = $allocation->getAmount();
        $lines   = [];

        if (!$kind->reducesTurnover()) {
            // payment and charges: one debit, one credit, no tax data.
            $lines[] = PostingLine::debit($counterAccount, $amount);
            $lines[] = PostingLine::credit($receivableAccount, $amount);
        } else {
            foreach (self::sharesPerCode($invoice, $amount) as $share) {
                if (!$share['net']->isZero()) {
                    // The uncoded share keeps its sign (DEBTOR-LOSS-SPLIT-001): the share of a NEGATIVE rounding
                    // line (an invoice rounded down) is a CREDIT of the counter account.
                    $lines[] = match (true) {
                        $share['code'] === null && $share['net']->isNegative() => PostingLine::credit($counterAccount, $share['net']->negate(), $kind->label() . ' ohne MWST'),
                        $share['code'] === null => PostingLine::debit($counterAccount, $share['net'], $kind->label() . ' ohne MWST'),
                        default => PostingLine::debit($counterAccount, $share['net'], $kind->label() . ' ' . $share['code'], $share['code'], $share['rate'], $share['net']->negate(), $share['tax']->negate()),
                    };
                }
                if (!$share['tax']->isZero()) {
                    $lines[] = PostingLine::vatDebit((string) $share['category'], $share['tax'], 'MWST ' . $share['code']);
                }
            }
            $lines[] = PostingLine::credit($receivableAccount, $amount);
        }

        return new PostingRequest(
            $allocation->getPayment()->getDate(),
            mb_substr($kind->label() . ' ' . $invoice->documentName() . ' · ' . $invoice->getAddress()->getName(), 0, PostingRequest::TEXT_LENGTH),
            'payment',
            (string) $allocation->getPayment()->getId(),
            'allocation:' . $allocation->getId(),
            $lines,
        );
    }

    /**
     * The amount split over the invoice's tax codes in proportion to their
     * gross, each share into net and tax by the code's rate; a share for the
     * gross without a code (code null) when the invoice has one.
     *
     * The coded part first (DEBTOR-LOSS-SPLIT-001, fixed 2026-11-24): amount ×
     * Σ coded gross / gross total — the whole amount when nothing is uncoded,
     * nothing when the invoice has no code. The uncoded REST keeps its sign: on
     * an invoice rounded DOWN (a negative rounding line) the coded gross exceeds
     * the total, so the coded part exceeds the amount and the rest is negative
     * (until then `abs()` of it was a weight, which posted 0.04 of base too little
     * on a full write-off of 92.53 + 7.49 − 0.02). The coded part is then
     * allocated over the codes by their gross.
     *
     * @return list<array{code: ?string, category: ?string, rate: int, net: Money, tax: Money}>
     */
    private static function sharesPerCode(Invoice $invoice, Money $amount): array
    {
        $currency = $invoice->getCurrency();
        $parts    = [];
        $ratios   = [];
        $coded    = Money::zero($currency);
        foreach ($invoice->getTaxes() as $tax) {
            $gross    = $tax->getBase()->add($tax->getTax());
            $coded    = $coded->add($gross);
            $parts[]  = ['code' => $tax->getTaxCode(), 'category' => $tax->getTaxCategory(), 'rate' => $tax->getTaxRate()];
            $ratios[] = $gross->minor;
        }
        $total = $invoice->getGrossTotal();
        $codedShare = match (true) {
            $parts === []                                   => Money::zero($currency),
            $coded->equals($total) || $total->isZero()      => $amount,
            default                                         => $amount->multiplyByRatio($coded->minor, $total->minor),
        };
        $uncodedShare = $amount->subtract($codedShare);

        $shares = [];
        if ($parts !== []) {
            // A ratio of 0 (a code whose gross is 0.00) takes no share; all zero → equal parts.
            if (array_sum($ratios) === 0) {
                $ratios = array_map(static fn() => 1, $ratios);
            }
            foreach ($codedShare->allocate($ratios) as $i => $share) {
                $part = $parts[$i];
                if ($part['rate'] > 0 && !$share->isZero()) {
                    [$net, $tax] = $share->allocate([10000, $part['rate']]);
                } else {
                    [$net, $tax] = [$share, Money::zero($currency)];
                }
                $shares[] = $part + ['net' => $net, 'tax' => $tax];
            }
        }
        if (!$uncodedShare->isZero() || $parts === []) {
            $shares[] = ['code' => null, 'category' => null, 'rate' => 0, 'net' => $uncodedShare, 'tax' => Money::zero($currency)];
        }

        return $shares;
    }
}
