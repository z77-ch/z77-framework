<?php
namespace Z77\Module\Order\Ui;

use Z77\Module\Order\Entities\OrderPhase;
use Z77\Module\Order\Entities\OrderStatus;
use Z77\Module\Order\Repositories\OrderSearch;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\ListState;
use Z77\Shared\Listing\Parsers;

/**
 * Aufträge › Aufträge as a standard list (listing.md, plan §9.3; the owner's board on the
 * developer canvas): the VIEW tab (`?view=`) as an extra, the columns state icon · Nr. · Datum ·
 * Kunde · Betreff · Status · Betrag — and «Zahlung» in «Abgeschlossen» —, six of them searched in
 * the database, sorted by number newest first, 50 per page. In the BOOKABLE tabs («Termin»,
 * «Geliefert») the rows lead with the selection checkbox of the booking print.
 *
 * **The tabs are the PHASES** (ADR-043 addendum 2026-10-15, {@see viewOf()}): each status falls
 * into the tab of its phase, so a new status lands in its tab without code; «Alle» shows every
 * order, also one whose status is missing from the master data. **Status** searches the LABELS
 * («Terminauftrag», «geliefert» — owner: «finde alle Aufträge mit Status …»): the matching
 * statuses' codes narrow the tab's codes; the status master data is a handful of rows, read anyway.
 */
final class OrderListing
{
    public const ID = 'order-find';

    public const LIMIT = 50;

    public const VIEW_OFFER     = 'new';
    public const VIEW_ACTIVE    = 'confirmed';
    public const VIEW_DELIVERED = 'delivered';
    public const VIEW_CLOSED    = 'closed';
    public const VIEW_CANCELLED = 'cancelled';
    public const VIEW_ALL       = 'all';

    /** View → tab label, along the ladder (the first is the default). */
    public const VIEWS = [
        self::VIEW_OFFER     => 'Eröffnet',   // owner 2026-10-16 (was «Offerten»)
        self::VIEW_ACTIVE    => 'Termin',     // owner 2026-10-16 (was «Termin»)
        self::VIEW_DELIVERED => 'Geliefert',
        self::VIEW_CLOSED    => 'Abgeschlossen',
        self::VIEW_CANCELLED => 'Storniert',
        self::VIEW_ALL       => 'Alle',
    ];

    public static function definition(string $currency, string $view = self::VIEW_OFFER): ListDefinition
    {
        $columns = self::isSelectable($view) ? [Column::slot('Auswahl')] : [];
        $columns = array_merge($columns, [
            Column::slot('Öffnen'),
            Column::search('f_nr', 'Nr.', '5rem', 'number', Parsers::integer(9), numeric: true, descendingFirst: true),
            Column::search('f_date', 'Datum', '6.5rem', 'date', Parsers::dateRange(), priority: '3', descendingFirst: true),
            // «Leistung von» IS the delivery date of goods (owner 2026-10-16) — «alle Terminaufträge mit Leistung 11.2026».
            Column::search('f_service', 'Leistung', '7.5rem', 'service', Parsers::dateRange(), priority: '2'),
            Column::search('f_name', 'Kunde', 'minmax(10rem, 2fr)', 'name', Parsers::text()),
            Column::search('f_subject', 'Betreff', 'minmax(8rem, 2fr)', null, Parsers::text(), priority: '2'),
            Column::search('f_status', 'Status', '9rem', null, Parsers::text(), priority: '2'),
            Column::search('f_amount', 'Betrag', '8rem', 'amount', Parsers::amount($currency), numeric: true, descendingFirst: true, inputMode: 'decimal'),
        ]);
        if ($view === self::VIEW_CLOSED) {
            $columns[] = Column::plain('Zahlung', '9rem');
        }

        return new ListDefinition(self::ID, $columns, 'number', self::LIMIT, ['view' => array_keys(self::VIEWS)]);
    }

    /** Whether the rows of $view carry the booking print's selection — the bookable phases. */
    public static function isSelectable(string $view): bool
    {
        return OrderPhase::tryFrom($view)?->isBookable() ?? false;
    }

    /** The tab a status belongs to — the tab of its PHASE. Pure. */
    public static function viewOf(OrderStatus $status): string
    {
        return $status->getPhase()->value;
    }

    /**
     * The status codes of $view among $statuses (active or not — an order keeps a deactivated
     * status) whose label contains $label (case-insensitive; null = any); null for «Alle» without
     * a label (no filter). Pure.
     *
     * @param list<OrderStatus> $statuses
     * @return list<string>|null
     */
    public static function codesOf(string $view, array $statuses, ?string $label = null): ?array
    {
        if ($view === self::VIEW_ALL && $label === null) {
            return null;
        }
        $needle = $label !== null ? mb_strtolower($label) : null;

        return array_values(array_map(static fn(OrderStatus $s) => $s->getCode(), array_filter(
            $statuses,
            static fn(OrderStatus $s) => ($view === self::VIEW_ALL || self::viewOf($s) === $view)
                && ($needle === null || str_contains(mb_strtolower($s->getLabel()), $needle))
        )));
    }


    /** What the repository is asked for. @param list<OrderStatus> $statuses */
    public static function search(ListState $state, array $statuses): OrderSearch
    {
        $date    = $state->parsed('f_date');
        $service = $state->parsed('f_service');
        $text = static fn(mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return new OrderSearch(
            self::codesOf($state->extra('view'), $statuses, $text($state->parsed('f_status'))),
            $state->parsed('f_nr'),
            $date[0] ?? null,
            $date[1] ?? null,
            $text($state->parsed('f_name')),
            $text($state->parsed('f_subject')),
            $state->parsed('f_amount'),
            $service[0] ?? null,
            $service[1] ?? null,
            $state->sort,
            $state->descending,
        );
    }
}
