<?php

namespace Z77\Module\Financial\VatReturn;

/**
 * Where the coded journal lines of an aggregate row come from — what field
 * 235 is picked by (ADR-041 addendum 2026-11-24, point 7; review C6: by
 * ORIGIN, not by the sign of the base).
 *
 * - `ordinary`   — an invoice, a fee, a receipt, a creditor invoice, a bank
 *                  or manual entry: turnover (field 200) or input tax.
 * - `reduction`  — a credit note, a discount, a loss: a reduction of the
 *                  consideration (field 235 for the taxable categories).
 * - `reversal`   — a reversal entry AND its original: netted into field 200
 *                  per code and rate, never a reduction.
 * - `correction` — a line of a correction entry (ADR-042 addendum 2 of
 *                  2026-11-25, point 1), computed in the block of the return
 *                  that carries it, never in its form: netted into field
 *                  200 per code and rate, never a reduction.
 */
enum RowOrigin: string
{
    case Ordinary   = 'ordinary';
    case Reduction  = 'reduction';
    case Reversal   = 'reversal';
    case Correction = 'correction';
}
