<?php

namespace Z77\Module\Financial\Entities;

/**
 * What a {@see VatReturnLog} row records (ADR-042 addendum 2026-11-24, point 5): a return was
 * posted, lifted (with a mandatory reason), or its «Eingereicht am» was set or cleared.
 */
enum VatReturnLogAction: string
{
    case Post    = 'post';
    case Lift    = 'lift';
    case Filed   = 'filed';
    case Unfiled = 'unfiled';
}
