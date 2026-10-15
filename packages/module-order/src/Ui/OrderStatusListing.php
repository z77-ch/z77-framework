<?php
namespace Z77\Module\Order\Ui;

use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;

/**
 * The order-status list as a standard list (listing.md, plan §9.5; ADR-043 addendum 2026-10-15):
 * state icon · Code · Bezeichnung · Phase · Sortierung · Wird gesetzt durch · Aktiv. A short fixed
 * list of file rows: no search, one sort — along the ladder (phase, then the position within it),
 * the order every choice offers. All rows on one page. «Wird gesetzt durch» names the ACTION that
 * sets the status when it moves an order into the phase (the default — owner 2026-10-15: «Vorgabe»
 * was understood by no one).
 */
final class OrderStatusListing
{
    public const ID = 'order-status';

    public static function definition(): ListDefinition
    {
        return new ListDefinition(self::ID, [
            Column::slot('Bearbeiten'),
            Column::plain('Code', '8rem'),
            Column::plain('Bezeichnung', 'minmax(10rem, 1.5fr)'),
            Column::plain('Phase', 'minmax(8rem, 1fr)', 'phase'),
            Column::plain('Sortierung', '6rem', numeric: true, priority: '2'),
            Column::plain('Wird gesetzt durch', 'minmax(9rem, 1fr)', priority: '2'),
            Column::plain('Aktiv', '3.5rem'),
        ], 'phase', 500);
    }
}
