<?php

namespace Z77\Module\Financial\Ui;

use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;

/**
 * «Stammdaten › Finanzen › Bankkonten» as a standard list (listing.md; board «Bankkonten», slim
 * form, owner-approved 2026-11-17): state icon · Bezeichnung · IBAN · Konto · Kontoauszug · Aktiv.
 * A handful of rows: no search, one sort (the label), all rows on one page — the model of
 * «Zahlungsarten».
 */
final class BankAccountListing
{
    public const ID = 'bank-account';

    public static function definition(): ListDefinition
    {
        return new ListDefinition(self::ID, [
            Column::slot('Bearbeiten'),
            Column::plain('Bezeichnung', 'minmax(9rem, 1.3fr)', 'label'),
            Column::plain('IBAN', '15rem', priority: '2'),
            Column::plain('Konto', 'minmax(8rem, 1fr)'),
            Column::plain('Kontoauszug', '11rem', priority: '2'),
            Column::plain('Aktiv', '3.5rem'),
        ], 'label', 500);
    }
}
