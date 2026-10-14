<?php
namespace Z77\Module\Order\Ui;

use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;

/**
 * The order-status list as a standard list (listing.md, plan §9.5): state icon · Code ·
 * Bezeichnung · Stufe · Verhalten (the flags as badges) · Aktiv. A short fixed list of file rows:
 * no search, one sort — the level, the order the transition rule reads. All rows on one page.
 */
final class OrderStatusListing
{
    public const ID = 'order-status';

    /** The flags in display order with their badge text — the edit window's switches use the same words. */
    public const FLAG_LABELS = [
        'offer'                => 'Offerte',
        'reserves_stock'       => 'reserviert Bestand',
        'consumes_stock'       => 'verbraucht Bestand',
        'invoiceable'          => 'fakturierbar',
        'invoice_booked'       => 'gebucht',
        'delivery_note_target' => 'Ziel des Lieferscheins',
        'final'                => 'abschliessend',
    ];

    public static function definition(): ListDefinition
    {
        return new ListDefinition(self::ID, [
            Column::slot('Bearbeiten'),
            Column::plain('Code', '8rem'),
            Column::plain('Bezeichnung', 'minmax(10rem, 1.5fr)'),
            Column::plain('Stufe', '4.5rem', 'level', numeric: true),
            Column::plain('Verhalten', 'minmax(10rem, 2fr)', priority: '2'),
            Column::plain('Aktiv', '3.5rem'),
        ], 'level', 500);
    }
}
