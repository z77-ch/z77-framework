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
 *     takes its proportional share without tax data.
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
     * @param string $counterAccount    the bank account of the target (payment) or the discount / loss account (resolved by the caller)
     */
    public static function build(PaymentAllocation $allocation, string $receivableAccount, string $counterAccount): PostingRequest
    {
        $invoice = $allocation->getInvoice();
        $kind    = $allocation->kind();
        $amount  = $allocation->getAmount();
        $lines   = [];

        if (!$kind->reducesTurnover()) {
            $lines[] = PostingLine::debit($counterAccount, $amount);
            $lines[] = PostingLine::credit($receivableAccount, $amount);
        } else {
            foreach (self::sharesPerCode($invoice, $amount) as $share) {
                if (!$share['net']->isZero()) {
                    $lines[] = $share['code'] === null
                        ? PostingLine::debit($counterAccount, $share['net'], $kind->label() . ' ohne MWST')
                        : PostingLine::debit($counterAccount, $share['net'], $kind->label() . ' ' . $share['code'], $share['code'], $share['rate'], $share['net']->negate(), $share['tax']->negate());
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
            $ratios[] = (int) str_replace('.', '', $gross->toDecimal());
        }
        $uncoded = $invoice->getGrossTotal()->subtract($coded);
        if (!$uncoded->isZero() || $parts === []) {
            $parts[]  = ['code' => null, 'category' => null, 'rate' => 0];
            $ratios[] = $parts === [['code' => null, 'category' => null, 'rate' => 0]] ? 1 : abs((int) str_replace('.', '', $uncoded->toDecimal()));
        }
        // A ratio of 0 (a code whose gross is 0.00) takes no share; all zero cannot happen (the invoice has a gross).
        if (array_sum($ratios) === 0) {
            $ratios = array_map(static fn() => 1, $ratios);
        }

        $shares = [];
        foreach ($amount->allocate($ratios) as $i => $share) {
            $part = $parts[$i];
            if ($part['rate'] > 0 && !$share->isZero()) {
                [$net, $tax] = $share->allocate([10000, $part['rate']]);
            } else {
                [$net, $tax] = [$share, Money::zero($currency)];
            }
            $shares[] = $part + ['net' => $net, 'tax' => $tax];
        }

        return $shares;
    }
}
