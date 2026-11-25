<?php

namespace Z77\Module\Vat\Entities;

/**
 * What KIND of tax a {@see TaxCode} stands for (ADR-041 decision 2). The set
 * is the European model's, fixed by the model rather than by an installation:
 * a country pack maps a code of a given category (plus its rate) to the
 * authority's form field, so the categories are what the packs agree on.
 *
 * `zero` and `exempt` are two categories on purpose (Swiss terms): a
 * zero-rated supply (Art. 23 MWSTG — export) is taxable at 0 % with input tax
 * deduction; an exempt supply (Art. 21 MWSTG) carries no tax and no deduction.
 * A form reports them in different fields.
 *
 * The three input-tax corrections (ADR-041 addendum 2026-11-24, point 10;
 * addendum 2026-11-25, point 3): `input-subsequent` (Einlageentsteuerung,
 * raises the input tax), `input-correction` (mixed use, own use) and
 * `input-reduction` (subsidies) — both lower it. z77 computes nothing for
 * them: the bookkeeper posts the amount by a manual entry with the code
 * ({@see isManualOnly()}).
 *
 * The SIDE rules live here, the one place every writer and the VAT return
 * ask: {@see isDebitNatural()}, {@see carriesTax()}, {@see allowsTaxOnly()},
 * {@see isManualOnly()}. Which ACCOUNT a category posts to is financial's
 * (`VatAccountMap`), the form field the country pack's.
 *
 * Domain only — display labels belong to the UI layer
 * ({@see \Z77\Module\Vat\Ui\TaxCodeControllerTrait::CATEGORY_LABELS}).
 */
enum TaxCategory: string
{
    case Standard        = 'standard';
    case Reduced         = 'reduced';
    case Special         = 'special';
    case Zero            = 'zero';
    case Exempt          = 'exempt';
    case ReverseCharge   = 'reverse-charge';
    case InputMaterial   = 'input-material';
    case InputOther      = 'input-other';
    case InputSubsequent = 'input-subsequent';
    case InputCorrection = 'input-correction';
    case InputReduction  = 'input-reduction';

    /**
     * The code's NATURAL side is Soll (debit): base and tax of a coded line are positive when the
     * line stands there, negative on the opposite side (the sign convention of `financial.md`).
     * Input tax and its subsequent deduction raise an asset; reverse charge is posted on the
     * expense like input tax (ADR-041 addendum 2026-11-24, point 8). Every other category is
     * credit-natural — the output categories, and the two corrections that take input tax back.
     */
    public function isDebitNatural(): bool
    {
        return match ($this) {
            self::InputMaterial, self::InputOther, self::InputSubsequent, self::ReverseCharge => true,
            default                                                                         => false,
        };
    }

    /** False for a category that never carries tax (zero-rated, exempt): the code goes on the net line with tax 0.00 and no tax line. */
    public function carriesTax(): bool
    {
        return $this !== self::Zero && $this !== self::Exempt;
    }

    /**
     * True when a «tax only» line may carry a code of this category — base 0.00, its amount IS the
     * tax (ADR-041 addendum 2026-11-25, point 6): the two input categories and the three
     * corrections.
     */
    public function allowsTaxOnly(): bool
    {
        return match ($this) {
            self::InputMaterial, self::InputOther, self::InputSubsequent, self::InputCorrection, self::InputReduction => true,
            default                                                                                               => false,
        };
    }

    /**
     * True for the three input-tax corrections: posted only by a manual entry (the Sammelbuchung),
     * never computed on a document, never offered by a document's code select.
     */
    public function isManualOnly(): bool
    {
        return match ($this) {
            self::InputSubsequent, self::InputCorrection, self::InputReduction => true,
            default                                                         => false,
        };
    }
}
