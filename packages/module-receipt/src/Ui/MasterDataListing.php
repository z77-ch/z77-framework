<?php
namespace Z77\Module\Receipt\Ui;

use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;

/**
 * The two master-data lists of the receipt module as standard lists (listing.md; the board
 * «Belegvorlagen», owner-approved 2026-11-12 — «Zahlungsarten» in the same look, E10). A handful of
 * file rows each: no search, one sort — the position, the order the phone shows them in; all rows on
 * one page (the order-status model).
 *
 *   - {@see templates()} — state icon · Symbol · Name · Code · Soll · MWST · Reihenfolge · Belege · Aktiv;
 *   - {@see paymentMethods()} — state icon · Name · Code · Haben · Reihenfolge · Belege · Aktiv.
 *
 * «Belege» counts the receipts that carry the code: a used row is deactivated, never deleted (there
 * is no delete at all — receipts keep the code, ADR-043 decision 19).
 */
final class MasterDataListing
{
    public const TEMPLATES_ID       = 'receipt-template';
    public const PAYMENT_METHODS_ID = 'payment-method';

    public static function templates(): ListDefinition
    {
        return new ListDefinition(self::TEMPLATES_ID, [
            Column::slot('Bearbeiten'),
            Column::plain('Symbol', '6rem', priority: '3'),
            Column::plain('Name', 'minmax(9rem, 1.5fr)'),
            Column::plain('Code', '7rem', priority: '3'),
            Column::plain('Soll', 'minmax(9rem, 1.5fr)'),
            Column::plain('MWST', '5rem', priority: '2'),
            Column::plain('Reihenfolge', '6.5rem', 'position', numeric: true, priority: '2'),
            Column::plain('Belege', '5rem', numeric: true, priority: '2'),
            Column::plain('Aktiv', '3.5rem'),
        ], 'position', 500);
    }

    public static function paymentMethods(): ListDefinition
    {
        return new ListDefinition(self::PAYMENT_METHODS_ID, [
            Column::slot('Bearbeiten'),
            Column::plain('Name', 'minmax(9rem, 1.5fr)'),
            Column::plain('Code', '7rem', priority: '3'),
            Column::plain('Haben', 'minmax(9rem, 1.5fr)'),
            Column::plain('Reihenfolge', '6.5rem', 'position', numeric: true, priority: '2'),
            Column::plain('Belege', '5rem', numeric: true, priority: '2'),
            Column::plain('Aktiv', '3.5rem'),
        ], 'position', 500);
    }
}
