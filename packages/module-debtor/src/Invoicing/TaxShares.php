<?php

namespace Z77\Module\Debtor\Invoicing;

use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Module\Vat\Calculation\VatCalculator;
use Z77\Shared\Money\Money;

/**
 * Distributes ONE tax code's tax (the tax row — computed on the sum, rounded
 * once, ADR-041 decision 5) over the code's lines, so the ledger can carry
 * the tax data on each NET line (decision 7) while Σ of the shares equals the
 * row exactly. Pure arithmetic on amounts; `PostingBuilder` calls it at
 * `finalize()`, `InvoicingService::compose()` at issue for the check below.
 *
 * The rules:
 *
 *   - `Money::allocate()` by the lines' absolute amounts — largest
 *     remainder, nothing lost (33.33 / 33.33 / 33.34 at 8.1 % → 2.70 each);
 *   - lines with MIXED signs are two groups: the positive group takes the
 *     tax on ITS sum, the negative group the remainder, so a deduction
 *     carries a negative share and the code's total still equals the row;
 *   - **gross mode, Rappen lines** (review 2026-09-23): a share that is not
 *     smaller than its line's gross would leave that line with a net of 0.00
 *     or less — the posting drops a zero line and the VAT return would then
 *     sum less tax than the VAT line carries. Such a share is set to 0 and
 *     the difference moves to the largest line of the same group, which must
 *     still keep a positive net afterwards; when no line can absorb it (a
 *     whole document of 0.01 lines) there is no honest per-line split, and
 *     {@see NoTaxShareException} says so — `compose()` refuses the draft
 *     BEFORE a number is drawn, so `finalize()` never meets the case.
 */
final class TaxShares
{
    /**
     * @param list<Money> $amounts the code's line amounts (net or gross per $mode), none zero
     * @param Money       $tax     the code's tax row
     * @param int         $rate    hundredths of a percent
     * @return list<Money> a share per amount, same order, Σ = $tax
     * @throws NoTaxShareException gross mode: a line is too small for its share and no line can take it over
     */
    public static function distribute(array $amounts, Money $tax, int $rate, PriceMode $mode): array
    {
        if ($amounts === []) {
            return [];
        }
        $zero     = Money::zero($tax->currency);
        $positive = array_keys(array_filter($amounts, static fn(Money $a) => $a->isPositive()));
        $negative = array_keys(array_filter($amounts, static fn(Money $a) => $a->isNegative()));

        if ($positive !== [] && $negative !== []) {
            $sumPositive = array_reduce($positive, static fn(Money $s, int $i) => $s->add($amounts[$i]), $zero);
            $taxPositive = $mode === PriceMode::Gross ? VatCalculator::taxIn($sumPositive, $rate) : VatCalculator::taxOf($sumPositive, $rate);
            $taxNegative = $tax->subtract($taxPositive);
        } else {
            $taxPositive = $positive !== [] ? $tax : $zero;
            $taxNegative = $negative !== [] ? $tax : $zero;
        }

        $shares = [];
        foreach ([[$positive, $taxPositive], [$negative, $taxNegative]] as [$group, $groupTax]) {
            if ($group === []) {
                continue;
            }
            $parts = $groupTax->allocate(array_map(static fn(int $i) => abs($amounts[$i]->minor), $group));
            foreach ($group as $k => $i) {
                $shares[$i] = $parts[$k];
            }
            if ($mode === PriceMode::Gross) {
                self::keepNetPositive($amounts, $shares, $group, $zero);
            }
        }
        ksort($shares);

        return array_values($shares);
    }

    /**
     * Gross mode: a share ≥ its line's gross is moved to the largest line of
     * the group. Compared on absolute values, so a negative group works the
     * same way with its negative shares.
     *
     * @param list<Money>      $amounts
     * @param array<int,Money> $shares by line index (modified)
     * @param list<int>        $group  the indexes of one sign group
     * @throws NoTaxShareException
     */
    private static function keepNetPositive(array $amounts, array &$shares, array $group, Money $zero): void
    {
        $moved = $zero;
        foreach ($group as $i) {
            if (abs($shares[$i]->minor) >= abs($amounts[$i]->minor)) {
                $moved      = $moved->add($shares[$i]);
                $shares[$i] = $zero;
            }
        }
        if ($moved->isZero()) {
            return;
        }
        usort($group, static fn(int $a, int $b) => abs($amounts[$b]->minor) <=> abs($amounts[$a]->minor) ?: $a <=> $b);
        $largest = $group[0];
        $share   = $shares[$largest]->add($moved);
        if (abs($share->minor) >= abs($amounts[$largest]->minor)) {
            throw new NoTaxShareException($amounts[$largest], $share);
        }
        $shares[$largest] = $share;
    }
}
