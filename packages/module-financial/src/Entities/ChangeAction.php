<?php

namespace Z77\Module\Financial\Entities;

/**
 * What an {@see EntryChange} records (ADR-042 decision 7): a manual entry
 * was changed (`update` — before AND after snapshot) or deleted (`delete` —
 * before snapshot only; the number it carried stays a gap, decision 9).
 * Creation is not logged here: the entry itself carries created by/at.
 */
enum ChangeAction: string
{
    case Update = 'update';
    case Delete = 'delete';
}
