<?php

namespace Z77\Module\Debtor\Repositories;

/**
 * What the customer picker searches for (owner 2026-10-15: «bei x tausend Kontakten — die Suche
 * serverseitig, wie im Journal»): one value per column magnifier, each optional, all combined
 * (AND). Built by {@see \Z77\Module\Debtor\Ui\CustomerPicker::search()}; read by
 * {@see DebtorProfileRepository::searchCustomers()}. The values are already parsed — the
 * repository binds them and never interprets input.
 */
final class CustomerSearch
{
    /** Columns the picker can sort by — the key is the URL value, the order the repository's. */
    public const SORTS = ['name', 'number'];

    /**
     * @param string|null $name     contained in the company, the last or the first name (either order)
     * @param string|null $city     contained in the city of any of the contact's addresses
     * @param int|null    $number   the exact customer number
     * @param string|null $email    contained in the e-mail address
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $city = null,
        public readonly ?int $number = null,
        public readonly ?string $email = null,
        public readonly string $sort = 'name',
        public readonly bool $descending = false,
    ) {
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException("Unknown customer sort '{$sort}'");
        }
    }
}
