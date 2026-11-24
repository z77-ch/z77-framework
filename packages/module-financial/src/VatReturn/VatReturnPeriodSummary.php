<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Shared\Money\Money;

/**
 * One row of the list of VAT returns (screen decision 3 of the review, 2026-11-24: the periods
 * that END in the fiscal year chosen in the rail). Immutable.
 *
 *   - `period` — from / to, `short` when cut by «Abrechnung ab» / «bis»;
 *   - `state` / `stateLabel` — {@see VatReturnPreview::STATE_OPEN} / «offen» in S1;
 *   - `methodLabel` — German, e.g. «Effektive Methode · Vereinbartes Entgelt»;
 *   - `elapsed` — the period has ended before the day asked for (a return can be filed);
 *   - `payable` — field 500 − 510, signed, computed from the journal now (cached per request by
 *     the service, never stored); null when the period is refused — then `refusal` says why
 *     (reverse-charge lines, an unmapped code, no form edition).
 */
final class VatReturnPeriodSummary
{
    public function __construct(
        public readonly VatPeriod $period,
        public readonly string $state,
        public readonly string $stateLabel,
        public readonly string $methodLabel,
        public readonly bool $elapsed,
        public readonly ?Money $payable,
        public readonly ?VatReturnRefusal $refusal,
    ) {}
}
