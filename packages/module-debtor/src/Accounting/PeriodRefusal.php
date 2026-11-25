<?php

namespace Z77\Module\Debtor\Accounting;

/**
 * What the bookkeeping WOULD refuse for a date, asked before anything is written
 * ({@see AccountingGateway::periodRefusal()}, ADR-042 addendum 2026-11-24 point 3): its stable
 * `reason` (financial's `LedgerService::periodRefusal()` passed through — `no-fiscal-year`,
 * `period-closed`, `before-vat-start`, `vat-period-settled`) and the bookkeeping's ONE German
 * sentence for it, WITHOUT its final full stop («Die MWST-Periode 3. Quartal 2026 ist abgerechnet —
 * eine Buchung mit MWST-Code geht dort nicht mehr») — debtor names its document in front of it and
 * adds its way out behind it, it never writes its own version of the sentence.
 */
final class PeriodRefusal
{
    public function __construct(
        public readonly string $reason,
        public readonly string $sentence,
    ) {
    }

    /**
     * A refusal of a document with a tax line for its DATE — the ONE list debtor's issue path refuses
     * on: the settled VAT period (stage S2 slice 3) and a date before «Abrechnung ab» (slice 4).
     */
    public function isVat(): bool
    {
        return in_array($this->reason, [AccountingRefusedException::VAT_PERIOD_SETTLED, AccountingRefusedException::BEFORE_VAT_START], true);
    }
}
