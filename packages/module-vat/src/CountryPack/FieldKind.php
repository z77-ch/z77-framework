<?php

namespace Z77\Module\Vat\CountryPack;

use Z77\Module\Vat\Entities\TaxCategory;

/**
 * How a field of a VAT return form is computed — declared per field in the
 * country pack's rule file (`res/country-packs/{country}.json`), so the
 * consumer that computes the return finds a field by its role and never
 * hard-codes a form number.
 *
 * Fed by journal lines (through a rule): `rate` (taxable turnover at the
 * field's rate — base, tax = base × rate), `deduction` (zero-rated, exempt,
 * abroad — turnover that is in field `total` and deducted again),
 * `acquisition` (reverse charge — base, tax = base × rate), `input` (input
 * tax as posted) and `input-correction` (input tax taken back, as posted).
 *
 * Structural, exactly one per edition: `total`, `reduction` (filled by the
 * ORIGIN of a line, not by a rule), `deductions-total`, `taxable-total`,
 * `tax-total`, `input-total`, `payable`, `credit`.
 */
enum FieldKind: string
{
    case Total           = 'total';
    case Deduction       = 'deduction';
    case Reduction       = 'reduction';
    case DeductionsTotal = 'deductions-total';
    case TaxableTotal    = 'taxable-total';
    case Rate            = 'rate';
    case Acquisition     = 'acquisition';
    case TaxTotal        = 'tax-total';
    case Input           = 'input';
    case InputCorrection = 'input-correction';
    case InputTotal      = 'input-total';
    case Payable         = 'payable';
    case Credit          = 'credit';

    /**
     * Tax categories the return maps that `TaxCategory` does not carry yet
     * (ADR-041 addendum 2026-11-24, point 10: input-tax correction,
     * reduction and subsequent deduction, posted by a coded manual entry). They are NOT in the enum
     * on purpose: an enum case would offer them in the tax-code editor and
     * in every code select before the ledger has a sign rule for them (build
     * stage S4). The ONE place they are named; move them into `TaxCategory`
     * with that stage.
     */
    public const CATEGORIES_NOT_IN_MODEL_YET = [self::INPUT_CORRECTION, self::INPUT_REDUCTION, self::INPUT_SUBSEQUENT];

    /** Input-tax correction: mixed use, own use (form field 415 in CH). */
    public const INPUT_CORRECTION = 'input-correction';

    /** Input-tax reduction: subsidies and other non-consideration (form field 420 in CH). */
    public const INPUT_REDUCTION = 'input-reduction';

    /** Subsequent input-tax deduction of a business that becomes liable with assets on hand (form field 410 in CH). */
    public const INPUT_SUBSEQUENT = 'input-subsequent';

    /** The kinds an edition must declare exactly once. */
    public const STRUCTURAL = [
        self::Total, self::Reduction, self::DeductionsTotal, self::TaxableTotal,
        self::TaxTotal, self::InputTotal, self::Payable, self::Credit,
    ];

    /**
     * The kind of field a line of this tax category must land in — the one
     * place the categories are tied to the form's roles. Null for a category
     * the model does not know (a rule naming it is refused at load).
     */
    public static function forCategory(string $category): ?self
    {
        return match ($category) {
            TaxCategory::Standard->value, TaxCategory::Reduced->value, TaxCategory::Special->value => self::Rate,
            TaxCategory::Zero->value, TaxCategory::Exempt->value                                    => self::Deduction,
            TaxCategory::ReverseCharge->value                                                       => self::Acquisition,
            TaxCategory::InputMaterial->value, TaxCategory::InputOther->value, self::INPUT_SUBSEQUENT => self::Input,
            self::INPUT_CORRECTION, self::INPUT_REDUCTION                                          => self::InputCorrection,
            default                                                                                 => null,
        };
    }
}
