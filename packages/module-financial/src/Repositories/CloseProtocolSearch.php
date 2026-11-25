<?php

namespace Z77\Module\Financial\Repositories;

/**
 * What the protocol «Abschlüsse und MWST» searches for (Finanzen › Änderungsprotokoll, second tab;
 * financial.md «the protocol «Abschlüsse und MWST»») — the scope and one value per column magnifier,
 * each optional, all combined (AND). Built from the list state by
 * {@see \Z77\Module\Financial\Ui\CloseProtocolListing::search()}; read by
 * {@see FiscalYearCloseLogRepository::protocolPage()} / {@see FiscalYearCloseLogRepository::protocolCount()}.
 *
 * Every criterion is already PARSED, so the repository binds values and never interprets input.
 */
final class CloseProtocolSearch
{
    /** The kinds of a protocol row — the German labels are the screen's. */
    public const KIND_VAT_POSTED    = 'vat-posted';
    public const KIND_VAT_LIFTED    = 'vat-lifted';
    public const KIND_YEAR_CLOSED   = 'year-closed';
    public const KIND_YEAR_REOPENED = 'year-reopened';

    public const KINDS = [self::KIND_VAT_POSTED, self::KIND_VAT_LIFTED, self::KIND_YEAR_CLOSED, self::KIND_YEAR_REOPENED];

    /**
     * @param int|null           $fiscalYearId the scope: that year's close / reopen rows and the VAT rows of the periods ENDING in it; null = everything
     * @param string|null        $yearFrom     `Y-m-d`, the scoped year's first day (with $fiscalYearId)
     * @param string|null        $yearTo       `Y-m-d`, the scoped year's last day (with $fiscalYearId)
     * @param string|null        $atFrom       `Y-m-d`, inclusive — the day of the event
     * @param string|null        $atTo         `Y-m-d`, inclusive
     * @param string|null        $who          contained in the actor's name
     * @param list<string>|null  $kinds        a subset of {@see KINDS}; null = every kind
     * @param string|null        $subjectFrom  `Y-m-d` — the row's subject (the VAT period, the fiscal year) overlaps this range
     * @param string|null        $subjectTo    `Y-m-d`
     * @param bool               $descending   newest first (the default)
     */
    public function __construct(
        public readonly ?int $fiscalYearId = null,
        public readonly ?string $yearFrom = null,
        public readonly ?string $yearTo = null,
        public readonly ?string $atFrom = null,
        public readonly ?string $atTo = null,
        public readonly ?string $who = null,
        public readonly ?array $kinds = null,
        public readonly ?string $subjectFrom = null,
        public readonly ?string $subjectTo = null,
        public readonly bool $descending = true,
    ) {
        if ($fiscalYearId !== null && ($yearFrom === null || $yearTo === null)) {
            throw new \InvalidArgumentException('A year scope names the year\'s first and last day');
        }
        if ($kinds !== null && ($kinds === [] || array_diff($kinds, self::KINDS) !== [])) {
            throw new \InvalidArgumentException('Unknown or no protocol kind: ' . json_encode($kinds));
        }
    }
}
