<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\VatReturn;
use Z77\Shared\Money\Money;

/**
 * One row of the list of VAT returns (screen decision 3 of the review, 2026-11-24: the periods
 * that END in the fiscal year chosen in the rail). Immutable.
 *
 *   - `period` — from / to, `short` when cut by «Abrechnung ab» / «bis»;
 *   - `state` / `stateLabel` — {@see VatReturnPreview::STATE_OPEN} «offen», or
 *     {@see VatReturnPreview::STATE_POSTED} «gebucht» while a live ordinary return covers the
 *     period (`VatSettlement`);
 *   - `methodLabel` — German, e.g. «Effektive Methode · Vereinbartes Entgelt»;
 *   - `elapsed` — the period has ended before the day asked for (a return can be filed);
 *   - `payable` — field 500 − 510, signed: the DECLARED amount of the posted return, else computed
 *     from the journal now (cached per request by the service); null when the period is refused —
 *     then `refusal` says why (reverse-charge lines, an unmapped code, no form edition);
 *   - `posted` — the live return (who / when posted, «Eingereicht am», revision), null while open;
 *   - `lifted` — for an OPEN period: its latest lifted return (the note «aufgehoben am … von …»),
 *     null when none was ever lifted.
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
        public readonly ?VatReturn $posted = null,
        public readonly ?VatReturn $lifted = null,
    ) {}
}
