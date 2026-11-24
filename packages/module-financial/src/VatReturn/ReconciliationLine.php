<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Shared\Money\Money;

/**
 * One line of the turnover reconciliation of the tab «Abstimmung» ({@see VatReturnReconciliation}):
 * a revenue account's MOVEMENT, or one of the NAMED differences between the revenue movements and
 * field 200 − 235 (ADR-041 addendum 2026-11-24, point 6; review C8). Immutable.
 *
 *   - `kind` — {@see MOVEMENT} (credit − debit of a revenue account in the period),
 *     {@see UNCODED} (the lines WITHOUT a tax code on a revenue account: invoice rounding, default
 *     interest …; credit − debit), {@see CODED_OTHER_TYPE} (output-coded lines on an account of
 *     another type — a sale of a fixed asset credited to the asset: the NEGATED base, it is in the
 *     declared turnover but not in the revenue movements), {@see CORRECTION} (correction entries of
 *     the period — none before stage S3);
 *   - `accountNumber` / `accountName`, `lines` (journal lines), `amount` (signed: the revenue side
 *     positive).
 */
final class ReconciliationLine
{
    public const MOVEMENT         = 'movement';
    public const UNCODED          = 'uncoded';
    public const CODED_OTHER_TYPE = 'coded-other-type';
    public const CORRECTION       = 'correction';

    public function __construct(
        public readonly string $kind,
        public readonly string $accountNumber,
        public readonly string $accountName,
        public readonly int $lines,
        public readonly Money $amount,
    ) {}

    /** German, for the screen and the PDF. */
    public function label(): string
    {
        return match ($this->kind) {
            self::MOVEMENT         => 'Bewegung',
            self::UNCODED          => 'Ohne MWST-Code',
            self::CODED_OTHER_TYPE => 'Umsatz auf anderem Kontotyp',
            self::CORRECTION       => 'Korrekturbuchung',
        };
    }
}
