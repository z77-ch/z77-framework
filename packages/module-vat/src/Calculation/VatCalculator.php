<?php

namespace Z77\Module\Vat\Calculation;

use Z77\Module\Vat\Services\VatRates;
use Z77\Shared\Money\Money;

/**
 * VAT is computed here and nowhere else (ADR-041 decision 5). Once per
 * document: the caller hands in its lines, the service date and the price
 * mode, gets back the resolved rate per line and a tax summary per code, and
 * stores both — computed once, then carried (decision 6).
 *
 * The arithmetic (ADR-042): rates are integers in hundredths of a percent,
 * amounts are `Money`, and tax is computed on the SUM per code, rounded half
 * away from zero to minor units — not per line and summed. Net mode:
 * tax = base × rate / 10000. Gross mode: tax = gross × rate / (10000 + rate),
 * base = gross − tax. There is no float anywhere: `Money` refuses one, and
 * so do the rate parameters below.
 *
 * Negative amounts (a credit note) flow through unchanged — a negative base
 * yields a negative tax, rounded half away from zero like the positive case.
 */
final class VatCalculator
{
    public function __construct(private readonly VatRates $rates) {}

    /**
     * @param list<VatLine> $lines in document order; may be empty (a text-only document)
     *
     * @throws \Z77\Module\Vat\Services\UnknownTaxCodeException a line names a code that does not exist
     * @throws \Z77\Module\Vat\Services\NoRateException        a code has no rate on $serviceDate
     * @throws \InvalidArgumentException                       a line is not in $currency
     */
    public function calculate(string $currency, array $lines, \DateTimeImmutable $serviceDate, PriceMode $priceMode): VatResult
    {
        $resolved = [];
        $rateOf   = [];   // code → ResolvedRate (one rate per code: one service date)
        $sumOf    = [];   // code → Money, the sum of the line amounts per code

        foreach ($lines as $line) {
            if (!$line instanceof VatLine) {
                throw new \InvalidArgumentException('VatCalculator: every line must be a VatLine, ' . get_debug_type($line) . ' given');
            }
            if ($line->amount->currency !== $currency) {
                throw new \InvalidArgumentException("VatCalculator: line '{$line->ref}' is in {$line->amount->currency}, the document in {$currency}");
            }

            $code = $line->taxCode;
            $rateOf[$code] ??= $this->rates->resolve($code, $serviceDate);
            $sumOf[$code]    = isset($sumOf[$code]) ? $sumOf[$code]->add($line->amount) : $line->amount;
            $resolved[]      = new ResolvedLine($line, $rateOf[$code]);
        }

        $entries = [];
        foreach ($sumOf as $code => $sum) {
            $rate = $rateOf[$code]->rate;
            if ($priceMode === PriceMode::Gross) {
                $tax  = self::taxIn($sum, $rate);
                $base = $sum->subtract($tax);
            } else {
                $tax  = self::taxOf($sum, $rate);
                $base = $sum;
            }
            $entries[] = new TaxSummaryEntry($code, $rate, $base, $tax);
        }

        return new VatResult($currency, $serviceDate, $priceMode, $resolved, new TaxSummary($currency, $entries));
    }

    /**
     * Tax ON a net base: base × rate / 10000, rounded half away from zero.
     * $rate in hundredths of a percent, int only.
     */
    public static function taxOf(Money $base, mixed $rate): Money
    {
        return $base->multiplyByRatio(self::requireRate($rate), 10000);
    }

    /**
     * Tax CONTAINED in a gross amount: gross × rate / (10000 + rate), rounded
     * half away from zero. $rate in hundredths of a percent, int only.
     */
    public static function taxIn(Money $gross, mixed $rate): Money
    {
        $rate = self::requireRate($rate);

        return $gross->multiplyByRatio($rate, 10000 + $rate);
    }

    private static function requireRate(mixed $rate): int
    {
        if (!is_int($rate)) {
            throw new \TypeError('VatCalculator: rate must be int (hundredths of a percent), ' . get_debug_type($rate) . ' given');
        }
        if ($rate < 0) {
            throw new \InvalidArgumentException('VatCalculator: rate must not be negative');
        }

        return $rate;
    }
}
