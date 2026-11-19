<?php

namespace Z77\Module\Financial\Ui;

use Z77\Module\Financial\Repositories\BankMovementRepository;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\Parsers;

/**
 * The ONE list «Bewegungen» of a bank account as a standard list (listing.md; ADR-060 decision
 * 17; board «Liste Bewegungen», owner-approved 2026-11-17): selection · state icon (opens the
 * movement's window) · Datum · Text · Betrag · FW · Stand · Zuordnung · Saldo (selection and «Stand» only on a
 * «gemischt geführt» account, owner 2026-11-19). Searched in
 * the database — the date (a day, a month, a year), the text (the bank's text, the counterparty,
 * the reference) and the amount (the booked amount OR the original amount, without sign); newest
 * first, 50 per page. The extra `account` carries the selected bank account (its code) through
 * sort, search and paging; the extra `state` is the view (ADR-061 stage C2): `review` «Zu prüfen»
 * · `business` «Geschäftlich» · `private` «Privat» — absent = «Alle». The selection column is the
 * rows' checkbox for «Privat …» ({@see SELECTION}, «alle» in the head).
 */
final class BankMovementListing
{
    public const ID    = 'bank-movement';
    public const LIMIT = 50;

    /** The id of the GET form the ticked rows belong to (`form=`). */
    public const SELECTION = 'bank-movement-selection';

    /** The words of a state — the column «Stand», the views, the window. */
    public const STATE_LABELS = [
        BankMovementRepository::STATE_REVIEW   => 'zu prüfen',
        BankMovementRepository::STATE_BUSINESS => 'geschäftlich',
        BankMovementRepository::STATE_PRIVATE  => 'privat',
    ];

    /**
     * @param bool $mixed the bank account is «gemischt geführt» (owner 2026-11-19): only then the
     *                    selection for «Privat …» and the column «Stand» — a purely business
     *                    account shows what is open by its empty «Zuordnung» and in «Zu prüfen»
     */
    public static function definition(string $currency, bool $mixed = true): ListDefinition
    {
        $columns = array_merge($mixed ? [Column::checkAll(self::SELECTION)] : [], [
            Column::slot('Stand'),
            Column::search('f_date', 'Datum', '7rem', 'date', Parsers::dateRange(), descendingFirst: true),
            Column::search('f_text', 'Text', 'minmax(12rem, 3fr)', null, Parsers::text()),
            Column::search('f_amount', 'Betrag', '8rem', 'amount', Parsers::amount($currency), numeric: true, descendingFirst: true, inputMode: 'decimal'),
            // «FW» = Fremdwährung (owner 2026-11-19, was «Original»): the instructed amount of a foreign purchase.
            Column::plain('FW', '8rem', numeric: true, priority: '2'),
        ], $mixed ? [Column::plain('Stand', '7rem')] : [], [
            Column::plain('Zuordnung', 'minmax(8rem, 1.5fr)', priority: '2'),
            Column::plain('Saldo', '8rem', numeric: true, priority: '3'),
        ]);

        return new ListDefinition(self::ID, $columns, 'date', self::LIMIT, ['account' => '/^[a-z][a-z0-9-]{1,15}$/', 'state' => '/^(' . implode('|', BankMovementRepository::STATES) . ')$/']);
    }
}
