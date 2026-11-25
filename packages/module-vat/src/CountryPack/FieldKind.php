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

    /** The kinds an edition must declare exactly once. */
    public const STRUCTURAL = [
        self::Total, self::Reduction, self::DeductionsTotal, self::TaxableTotal,
        self::TaxTotal, self::InputTotal, self::Payable, self::Credit,
    ];

    /**
     * The kind of field a line of this tax category must land in — the one
     * place the categories are tied to the form's roles. Null for a category
     * the model does not know (a rule naming it is refused at load).
     *
     * The subsequent deduction (410 in CH) is an INPUT field: it raises the
     * input tax. Correction and reduction (415 / 420) are input-correction
     * fields: their posted tax — positive on the credit side, the side that
     * takes input tax back (`TaxCategory::isDebitNatural()`) — is subtracted
     * in the input total.
     */
    public static function forCategory(string $category): ?self
    {
        return match (TaxCategory::tryFrom($category)) {
            TaxCategory::Standard, TaxCategory::Reduced, TaxCategory::Special                => self::Rate,
            TaxCategory::Zero, TaxCategory::Exempt                                           => self::Deduction,
            TaxCategory::ReverseCharge                                                       => self::Acquisition,
            TaxCategory::InputMaterial, TaxCategory::InputOther, TaxCategory::InputSubsequent => self::Input,
            TaxCategory::InputCorrection, TaxCategory::InputReduction                        => self::InputCorrection,
            null                                                                             => null,
        };
    }
}
