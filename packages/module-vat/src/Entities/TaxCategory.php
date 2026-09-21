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
 * Domain only — display labels belong to the UI layer
 * ({@see \Z77\Module\Vat\Ui\TaxCodeControllerTrait::CATEGORY_LABELS}).
 */
enum TaxCategory: string
{
    case Standard      = 'standard';
    case Reduced       = 'reduced';
    case Special       = 'special';
    case Zero          = 'zero';
    case Exempt        = 'exempt';
    case ReverseCharge = 'reverse-charge';
    case InputMaterial = 'input-material';
    case InputOther    = 'input-other';
}
