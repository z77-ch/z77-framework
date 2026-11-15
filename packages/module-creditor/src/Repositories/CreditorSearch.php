<?php

namespace Z77\Module\Creditor\Repositories;

/**
 * What the creditor list («Kreditoren») asks the database for: the creditor profiles and the
 * column criteria, AND-ed. Every value is bound; the sort key selects from a fixed map
 * ({@see CreditorProfileRepository::search()}), never from input. Built by the screen's listing
 * (`Ui\CreditorListing`), plain data.
 */
final class CreditorSearch
{
    public const SORTS = ['name'];

    public function __construct(
        public readonly ?string $name = null,   // part of company, last name, first name
        public readonly ?string $uid = null,    // part of the UID, as typed
        public readonly ?string $iban = null,   // part of one of its IBANs, spaces ignored
        public readonly string $sort = 'name',
        public readonly bool $descending = false,
    ) {
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException("Unknown creditor sort '{$sort}'");
        }
    }
}
