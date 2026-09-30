<?php

namespace Z77\Module\Financial\Entities;

/**
 * What a {@see FiscalYearCloseLog} row records (owner decisions 2026-09-30):
 * a fiscal year was closed — every period set `closed` — or an admin
 * reopened it, with a mandatory reason.
 */
enum CloseAction: string
{
    case Close  = 'close';
    case Reopen = 'reopen';
}
