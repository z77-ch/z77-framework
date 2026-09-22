<?php

namespace Z77\Module\Financial\Services;

/**
 * The KMU chart of accounts is adopted only into an EMPTY chart (owner,
 * 2026-09-22): an installation that migrates its own chart (P5b) must not
 * get a second one mixed into it.
 */
final class ChartNotEmptyException extends FinancialException
{
    public function __construct()
    {
        parent::__construct('The KMU chart of accounts is adopted only into an empty chart — this chart already has accounts');
    }
}
