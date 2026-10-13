<?php

namespace Z77\Module\Financial\Ui;

/**
 * The German month names of the financial screens — ONE list (review 2026-10-13: the report
 * range form and the fiscal-year list each carried their own copy, the journal crumb a third).
 * Index 0 = January; a period's month is `(int) $date->format('n') - 1`.
 */
final class MonthNames
{
    /** «Jan» … «Dez» — the report's «Zeitraum» select, the fiscal-year list's month line. */
    public const SHORT = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

    /** «Januar» … «Dezember» — the journal's crumb line. */
    public const LONG = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
}
