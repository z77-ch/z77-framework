<?php

namespace Z77\Module\Order\Repositories;

/**
 * What the order list asks the database for (plan §9.3): the status CODES of the chosen view tab
 * (derived from the PHASES and a searched status label by the screen — `Ui\OrderListing::codesOf()`, so a new status
 * lands in a tab without code; null = every order, «Alle») and the column criteria, AND-ed. Every
 * value is bound; the sort key selects from a fixed map ({@see SalesOrderRepository::search()}),
 * never from input. Plain data.
 */
final class OrderSearch
{
    public const SORTS = ['number', 'date', 'name', 'amount'];

    /** @param list<string>|null $statusCodes null = no status filter; [] = a view no status falls into (nothing) */
    public function __construct(
        public readonly ?array $statusCodes = null,
        public readonly ?int $number = null,
        public readonly ?string $dateFrom = null,   // Y-m-d, inclusive
        public readonly ?string $dateTo = null,     // Y-m-d, inclusive
        public readonly ?string $name = null,       // part of the customer's name (company, last or first name)
        public readonly ?string $subject = null,    // part of the subject
        public readonly ?string $amount = null,     // the gross total, a decimal string
        public readonly string $sort = 'number',
        public readonly bool $descending = true,
    ) {
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException("Unknown order sort '{$sort}'");
        }
    }
}
