<?php

namespace Z77\Module\Debtor\Invoicing;

use Z77\Module\Debtor\Accounting\PostingLine;
use Z77\Module\Debtor\Accounting\PostingRequest;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceLine;
use Z77\Module\Debtor\Entities\InvoiceTax;
use Z77\Module\Debtor\Entities\LineType;
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Shared\Money\Money;

/**
 * Turns a document into the posting `finalize()` hands the accounting port
 * (plan §6.2: «receivable / revenue per line + VAT per code + rounding»;
 * ADR-041 decision 7, net method). Reads the document's SNAPSHOT only —
 * lines, tax rows, totals — never the tax tables (decision 6).
 *
 * The shape, for an invoice (a credit note is the same with every side
 * swapped and every tax figure negated — {@see InvoiceKind::sign()}):
 *
 *   - `receivable` DEBIT gross total (the one line a payment later clears);
 *   - per priced line with an amount: CREDIT its revenue account with the
 *     line's NET amount, carrying `taxCode`, `taxRate`, `taxBase` (= that net)
 *     and `taxAmount` (this line's share of its code's tax) — the net line
 *     of the net method, one per taxed amount;
 *   - per tax code: CREDIT the VAT account of the code's CATEGORY with the
 *     tax row's amount — the number is the bookkeeping's setting, resolved
 *     by the adapter ({@see PostingLine::vatCredit()});
 *   - the rounding line: CREDIT (rounded up) or DEBIT (rounded down) the
 *     rounding account, no tax.
 *
 * Σ debit = Σ credit by construction: gross = net + tax + rounding.
 *
 * The per-line tax share: tax is computed on the SUM per code, rounded once
 * ({@see InvoiceTax}) — a per-line tax would be the wdv Rappen smear. The
 * ledger still wants the tax on each NET line, so each code's tax is
 * DISTRIBUTED over its lines by {@see TaxShares::distribute()} (allocate,
 * mixed signs as two groups, Rappen lines in gross mode; Σ per code = the
 * tax row exactly). In gross price mode a line's net is its gross minus its
 * share — positive by construction, `compose()` refused the draft otherwise.
 *
 * A negative line (a deduction on an invoice) posts on the OPPOSITE side
 * with negative tax data — the sign convention of financial's manual forms:
 * positive on the code's natural side (credit for output VAT), negative
 * opposite. A line at 0.00 and a text line post nothing. A document whose
 * posting would have fewer than two lines posts nothing at all
 * ({@see build()} returns null).
 */
final class PostingBuilder
{
    /**
     * @param string $receivableAccount `debtorAccounts → receivable`, resolved by the caller
     */
    public static function build(Invoice $invoice, string $receivableAccount): ?PostingRequest
    {
        $currency = $invoice->getCurrency();
        $sign     = $invoice->kind()->sign();
        $zero     = Money::zero($currency);
        $lines    = [];

        // 1. The receivable — gross, on the kind's side.
        self::add($lines, $receivableAccount, null, $invoice->getGrossTotal()->multiply($sign), natural: 'debit');

        // 2. Revenue per line, net, with the tax data of the net method.
        $taxRows = [];
        foreach ($invoice->getTaxes() as $tax) {
            $taxRows[$tax->getTaxCode()] = $tax;
        }
        $mode   = PriceMode::from($invoice->getPriceMode());
        $priced = array_values(array_filter($invoice->getLines(), static fn(InvoiceLine $l) => $l->type()->isPriced() && $l->posts()));
        $shares = self::taxShares($priced, $taxRows, $mode, $zero);
        foreach ($priced as $i => $line) {
            $share = $shares[$i];
            $net   = $mode === PriceMode::Gross ? $line->getAmount()->subtract($share) : $line->getAmount();
            if ($net->isZero()) {
                continue;
            }
            self::add(
                $lines,
                (string) $line->getRevenueAccount(),
                null,
                $net->multiply($sign),
                natural: 'credit',
                text: self::lineText($line->getText()),
                taxCode: $line->getTaxCode(),
                taxRate: $line->getTaxRate(),
                taxBase: $net->multiply($sign),
                taxAmount: $share->multiply($sign),
            );
        }

        // 3. VAT per code — by category; the adapter names the account.
        foreach ($invoice->getTaxes() as $tax) {
            if ($tax->getTax()->isZero()) {
                continue;
            }
            self::add($lines, null, $tax->getTaxCategory(), $tax->getTax()->multiply($sign), natural: 'credit', text: 'MWST ' . $tax->getTaxCode());
        }

        // 4. The rounding line.
        foreach ($invoice->getLines() as $line) {
            if ($line->type() === LineType::Rounding && $line->posts()) {
                self::add($lines, (string) $line->getRevenueAccount(), null, $line->getAmount()->multiply($sign), natural: 'credit', text: self::lineText($line->getText()));
            }
        }

        if (count($lines) < 2) {
            return null;
        }

        return new PostingRequest(
            $invoice->getInvoiceDate(),
            mb_substr($invoice->documentName() . ' · ' . $invoice->getAddress()->getName(), 0, PostingRequest::TEXT_LENGTH),
            $invoice->kind()->value,
            (string) $invoice->getNumber(),
            $invoice->kind()->value . ':' . $invoice->getId() . ':final',
            $lines,
        );
    }

    /**
     * Each priced line's share of its code's tax, by line index
     * ({@see TaxShares::distribute()} per code).
     *
     * @param list<InvoiceLine> $lines
     * @param array<string, InvoiceTax> $taxRows by code
     * @return array<int, Money>
     */
    private static function taxShares(array $lines, array $taxRows, PriceMode $mode, Money $zero): array
    {
        $byCode = [];
        foreach ($lines as $i => $line) {
            $byCode[(string) $line->getTaxCode()][] = $i;
        }

        $shares = [];
        foreach ($byCode as $code => $indexes) {
            $tax   = isset($taxRows[$code]) ? $taxRows[$code]->getTax() : $zero;
            $rate  = isset($taxRows[$code]) ? $taxRows[$code]->getTaxRate() : 0;
            $parts = TaxShares::distribute(array_map(static fn(int $i) => $lines[$i]->getAmount(), $indexes), $tax, $rate, $mode);
            foreach ($indexes as $k => $i) {
                $shares[$i] = $parts[$k];
            }
        }

        return $shares;
    }

    /**
     * Adds one line: a positive $signed amount goes on its $natural side, a
     * negative one on the opposite side, always as a positive figure. Tax
     * data is passed through signed.
     *
     * @param list<PostingLine> $lines
     */
    private static function add(array &$lines, ?string $account, ?string $vatCategory, Money $signed, string $natural, ?string $text = null, ?string $taxCode = null, ?int $taxRate = null, ?Money $taxBase = null, ?Money $taxAmount = null): void
    {
        if ($signed->isZero()) {
            return;
        }
        $amount = $signed->isNegative() ? $signed->negate() : $signed;
        $debit  = ($natural === 'debit') === $signed->isPositive();
        if ($vatCategory !== null) {
            $lines[] = $debit ? PostingLine::vatDebit($vatCategory, $amount, $text) : PostingLine::vatCredit($vatCategory, $amount, $text);

            return;
        }
        $lines[] = $debit
            ? PostingLine::debit((string) $account, $amount, $text, $taxCode, $taxRate, $taxBase, $taxAmount)
            : PostingLine::credit((string) $account, $amount, $text, $taxCode, $taxRate, $taxBase, $taxAmount);
    }

    /** The first line of a document line's text, cut to what a journal line holds. */
    private static function lineText(string $text): ?string
    {
        $first = trim((string) strtok($text, "\r\n"));

        return $first === '' ? null : mb_substr($first, 0, PostingRequest::TEXT_LENGTH);
    }
}
