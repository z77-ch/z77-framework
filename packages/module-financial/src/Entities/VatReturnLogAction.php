<?php

namespace Z77\Module\Financial\Entities;

/**
 * What a {@see VatReturnLog} row records (ADR-042 addendum 2026-11-24, point 5): a return was
 * posted, or lifted (with a mandatory reason). There is no filed event — posted means filed
 * (owner 2026-11-25: the return is filed in the ePortal first, the posting IS the filing).
 */
enum VatReturnLogAction: string
{
    case Post = 'post';
    case Lift = 'lift';
}
