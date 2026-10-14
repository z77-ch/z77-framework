<?php
namespace Z77\Module\Order\Ui;

use Z77\Module\Order\Entities\OrderStatus;
use Z77\Module\Order\Repositories\OrderSearch;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\ListState;
use Z77\Shared\Listing\Parsers;

/**
 * Aufträge › Aufträge as a standard list (listing.md, plan §9.3; the owner's board on the
 * developer canvas): the VIEW tab (`?view=`) as an extra, the columns state icon · Nr. · Datum ·
 * Kunde · Betreff · Status · Betrag — and «Zahlung» in «Gebucht» —, five of them searched in the
 * database, sorted by number newest first, 50 per page. In «Zu fakturieren» the rows lead with
 * the selection checkbox of the booking print.
 *
 * **The tabs come from the status FLAGS** ({@see viewOf()}), never from a code: a new status
 * lands in its tab without code (ADR-043). Each status falls into exactly ONE tab, by precedence
 * `final` › `invoiceBooked` › `invoiceable` › `offer` › «In Arbeit» (everything else); «Alle»
 * shows every order, also one whose status is missing from the master data.
 */
final class OrderListing
{
    public const ID = 'order-find';

    public const LIMIT = 50;

    public const VIEW_OFFER       = 'offer';
    public const VIEW_ACTIVE      = 'active';
    public const VIEW_INVOICEABLE = 'invoiceable';
    public const VIEW_BOOKED      = 'booked';
    public const VIEW_FINAL       = 'final';
    public const VIEW_ALL         = 'all';

    /** View → tab label, in the order of the board (the first is the default). */
    public const VIEWS = [
        self::VIEW_OFFER       => 'Offerten',
        self::VIEW_ACTIVE      => 'In Arbeit',
        self::VIEW_INVOICEABLE => 'Zu fakturieren',
        self::VIEW_BOOKED      => 'Gebucht',
        self::VIEW_FINAL       => 'Erledigt',
        self::VIEW_ALL         => 'Alle',
    ];

    public static function definition(string $currency, string $view = self::VIEW_OFFER): ListDefinition
    {
        $columns = $view === self::VIEW_INVOICEABLE ? [Column::slot('Auswahl')] : [];
        $columns = array_merge($columns, [
            Column::slot('Öffnen'),
            Column::search('f_nr', 'Nr.', '5rem', 'number', Parsers::integer(9), numeric: true, descendingFirst: true),
            Column::search('f_date', 'Datum', '6.5rem', 'date', Parsers::dateRange(), priority: '3', descendingFirst: true),
            Column::search('f_name', 'Kunde', 'minmax(10rem, 2fr)', 'name', Parsers::text()),
            Column::search('f_subject', 'Betreff', 'minmax(8rem, 2fr)', null, Parsers::text(), priority: '2'),
            Column::plain('Status', '9rem', priority: '2'),
            Column::search('f_amount', 'Betrag', '8rem', 'amount', Parsers::amount($currency), numeric: true, descendingFirst: true, inputMode: 'decimal'),
        ]);
        if ($view === self::VIEW_BOOKED) {
            $columns[] = Column::plain('Zahlung', '9rem');
        }

        return new ListDefinition(self::ID, $columns, 'number', self::LIMIT, ['view' => array_keys(self::VIEWS)]);
    }

    /** The tab a status belongs to — by its FLAGS, one tab per status (precedence above). Pure. */
    public static function viewOf(OrderStatus $status): string
    {
        return match (true) {
            $status->isFinal()         => self::VIEW_FINAL,
            $status->isInvoiceBooked() => self::VIEW_BOOKED,
            $status->isInvoiceable()   => self::VIEW_INVOICEABLE,
            $status->isOffer()         => self::VIEW_OFFER,
            default                    => self::VIEW_ACTIVE,
        };
    }

    /**
     * The status codes of $view among $statuses (active or not — an order keeps a deactivated
     * status); null for «Alle» (no filter). Pure.
     *
     * @param list<OrderStatus> $statuses
     * @return list<string>|null
     */
    public static function codesOf(string $view, array $statuses): ?array
    {
        if ($view === self::VIEW_ALL) {
            return null;
        }

        return array_values(array_map(static fn(OrderStatus $s) => $s->getCode(), array_filter($statuses, static fn(OrderStatus $s) => self::viewOf($s) === $view)));
    }

    /**
     * The badge per tab: the orders per status code mapped to their tab; «Alle» counts every
     * order (a code missing from the master data included). Pure.
     *
     * @param list<OrderStatus>   $statuses
     * @param array<string, int>  $perStatus status code → orders
     * @return array<string, int> view → orders
     */
    public static function counts(array $statuses, array $perStatus): array
    {
        $counts = array_fill_keys(array_keys(self::VIEWS), 0);
        foreach ($statuses as $status) {
            $counts[self::viewOf($status)] += $perStatus[$status->getCode()] ?? 0;
        }
        $counts[self::VIEW_ALL] = array_sum($perStatus);

        return $counts;
    }

    /** What the repository is asked for. @param list<OrderStatus> $statuses */
    public static function search(ListState $state, array $statuses): OrderSearch
    {
        $date = $state->parsed('f_date');
        $text = static fn(mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return new OrderSearch(
            self::codesOf($state->extra('view'), $statuses),
            $state->parsed('f_nr'),
            $date[0] ?? null,
            $date[1] ?? null,
            $text($state->parsed('f_name')),
            $text($state->parsed('f_subject')),
            $state->parsed('f_amount'),
            $state->sort,
            $state->descending,
        );
    }
}
