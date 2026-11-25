<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Shared\Money\Money;

/**
 * One named item of the turnover reconciliation of a tax year (Art. 128 para. 2 MWSTV, letters a–j;
 * {@see VatYearTurnover}). Immutable.
 *
 *   - `letter` — the letter of para. 2 the item stands for, null for an item of z77's own (open
 *     periods, correction entries, coded turnover on another account type);
 *   - `kind` — one of the constants;
 *   - `label` — the German line;
 *   - `accountNumber` / `accountName` — the account the item is about, '' for a sum or a line
 *     «von Hand prüfen»; `lines` — the journal lines behind it;
 *   - `amount` — signed like {@see ReconciliationLine}: the revenue side positive; null for a line
 *     «von Hand prüfen» (small rule 4: no amount);
 *   - `counted` — whether it explains the difference between the books and the declaration
 *     (enters {@see VatYearTurnover::remainder()}); an item shown for information is not (field
 *     235 is already in the declaration; an uncoded credit on a fixed-asset account is in neither).
 */
final class VatYearTurnoverItem
{
    /** The unposted periods of the year, their turnover as computed now (field 200 − 235) — the year is «vorläufig». */
    public const OPEN_PERIODS = 'open-periods';
    /** j — revenue lines WITHOUT a tax code (closing entries, default interest, invoice rounding …), per revenue account. */
    public const UNCODED = 'uncoded';
    /** b — output-coded lines on expense accounts (income on expense accounts), per account: the NEGATED base. */
    public const CODED_EXPENSE = 'coded-expense';
    /** d — output-coded lines on fixed-asset accounts (a sale of an operating asset), per account: the NEGATED base. */
    public const FIXED_ASSET = 'fixed-asset';
    /** d — credit lines WITHOUT a tax code on a fixed-asset account: «nicht deklariert — prüfen», not counted. */
    public const FIXED_ASSET_UNCODED = 'fixed-asset-uncoded';
    /** Output-coded lines on an account of another type (neither revenue, expense nor fixed asset): the NEGATED base. */
    public const CODED_OTHER_TYPE = 'coded-other-type';
    /** h / i — field 235 as declared (reductions of consideration, debtor losses): already in the declaration, not counted. */
    public const REDUCTION = 'reduction';
    /** Correction entries dated in the year that correct a period OF the year — their coded revenue (in the blocks, not in the ordinary forms). */
    public const CORRECTION_SAME_YEAR = 'correction-same-year';
    /** Correction entries dated in the year that correct an EARLIER tax year — their coded revenue, «nicht im Formular {Jahr}». */
    public const CORRECTION_EARLIER_YEAR = 'correction-earlier-year';
    /** The lines WITHOUT a tax code of the correction entries dated in the year (the other side of their coded revenue). */
    public const CORRECTION_UNCODED = 'correction-uncoded';
    /** c, e, f, g — what z77 cannot know: «von Hand prüfen», no amount, not counted. */
    public const BY_HAND = 'by-hand';

    public function __construct(
        public readonly ?string $letter,
        public readonly string $kind,
        public readonly string $label,
        public readonly string $accountNumber,
        public readonly string $accountName,
        public readonly int $lines,
        public readonly ?Money $amount,
        public readonly bool $counted,
    ) {}
}
