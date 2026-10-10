<?php
namespace Z77\Module\Financial\Ui;

use Z77\Shared\Money\AmountFormat;

use Z77\Core\DI,
    Z77\Core\Http\Response\BytesResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Financial\Pdf\ReportPdf,
    Z77\Module\Financial\Entities\Account,
    Z77\Module\Financial\Entities\FiscalYear,
    Z77\Module\Financial\Reports\ReportRange,
    Z77\Module\Financial\Reports\StatementComparison,
    Z77\Module\Financial\Repositories\AccountRepository,
    Z77\Module\Financial\Repositories\FiscalYearRepository,
    Z77\Module\Financial\Services\LedgerReports,
    Z77\Module\Mandator\Entities\Mandator,
    Z77\Module\Mandator\Services\CurrentMandator,
    Z77\Module\Mandator\Services\MandatorUnavailableException,
    Z77\Shared\Money\Money
;

/**
 * The ledger reports (plan §5.5, P2 part 3) — mounted by a thin host
 * controller in the backend (ADR-018 pattern, like the journal). Host side:
 * `use ReportControllerTrait` + a one-line layout config delegating to
 * {@see ReportLayout::config()}, and the controller's `defaultAction`
 * `trial-balance` in the host module's config.
 *
 * One PAGE per report, switched by the tabs in the toolbar (hc2): trial balance, balance
 * sheet, income statement, account statement, journal. Read-only — nothing
 * here writes, so there is no form post, no CSRF token and no JavaScript
 * (Rule 7): the parameters are a GET form (fiscal year, from, to; the
 * account on the account statement), the months of the year are links that
 * set from–to, paging is `?page=` links. Printed from the browser: the
 * shell's print rules drop the chrome, `.be-noprint` drops the form and the
 * pager.
 *
 * Parameters: `?year=` a fiscal-year code — the SELECTION the journal shares
 * ({@see FiscalYearSelection}, owner 2026-09-29): an explicit year is
 * remembered for the session, without `?year=` the remembered year applies,
 * else the year containing today, else the latest. The year is picked at the
 * top of the rail (section `railSelect`, owner 2026-10-08; `Backend/partials/fiscalYearSelect`),
 * `?from=` / `?to=` `YYYY-MM-DD` inside that year (default: its first and
 * last day). A value that is not a date, or lies outside the year, falls
 * back to the default and says so above the report — never a 500, never a
 * silent clamp into a range nobody asked for.
 *
 * The numbers come from {@see LedgerReports} (SQL aggregates, no float);
 * amounts are written through {@see AmountFormat}. The using class MUST
 * provide (via its host base): `html()`, `em()`, `$layoutManager`.
 */
trait ReportControllerTrait
{
    /** The report tabs (hc2): URL action → German label, in reading order. */
    private const REPORT_TABS = [
        'trial-balance'     => 'Saldobilanz',
        'balance-sheet'     => 'Bilanz',
        'income-statement'  => 'Erfolgsrechnung',
        'account-statement' => 'Kontoblatt',
        'journal'           => 'Journal',
    ];

    /** The PDF takes every line in one page (not PHP_INT_MAX: Paging adds to it and would overflow). */
    private const PDF_ALL_ROWS = 1_000_000;

    /** Balance sheet and income statement: the shown year and the two before it (owner 2026-10-10). */
    private const COMPARE_YEARS = 3;

    /** The reports with the «Vorjahre» checkbox. */
    private const REPORT_COMPARE = ['balance-sheet', 'income-statement'];

    /** The reports that have a PDF (FIN-PDF-001). */
    private const REPORT_PDF = ['trial-balance', 'balance-sheet', 'income-statement', 'account-statement', 'journal'];

    private const REPORT_MONTHS =['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

    /** URL root of THIS mount — every report link and form is built from it. */
    protected function reportBase(): string
    {
        return '/backend/finance/report';
    }

    /** URL root of the journal mount — the account statement and the journal report link to an entry's detail page there. */
    protected function reportJournalBase(): string
    {
        return '/backend/finance/journal';
    }

    private function ledgerReports(): LedgerReports
    {
        return new LedgerReports($this->em());
    }

    private function reportYears(): FiscalYearRepository
    {
        return $this->em()->getRepository(FiscalYear::class);
    }

    private function reportYearSelection(): FiscalYearSelection
    {
        return FiscalYearSelection::of($this->reportYears());
    }

    /**
     * The mandator for the printed letterhead, or null. A report never fails
     * over it: `CurrentMandator` answers three-valued, and «cannot be read»
     * means the module is not registered or its table is missing — an upgrade
     * half done, which must not take a read-only report down. The letterhead
     * then prints empty, which is visible and fixable.
     */
    private function reportMandator(): ?Mandator
    {
        try {
            return (new CurrentMandator($this->em()))->find();
        } catch (MandatorUnavailableException) {
            return null;
        }
    }

    private function reportAccounts(): AccountRepository
    {
        return $this->em()->getRepository(Account::class);
    }

    // ── the reports ──────────────────────────────────────────────────────

    protected function trialBalanceAction(): HtmlResponse
    {
        return $this->reportPage('trialBalance', 'trial-balance', fn(ReportRange $range) => [
            'report' => $this->ledgerReports()->trialBalance($range),
        ]);
    }

    /** The balance sheet at the «to» day — from the year's first day, whatever «from» says (it is a statement at a day). */
    protected function balanceSheetAction(): HtmlResponse
    {
        return $this->reportPage('balanceSheet', 'balance-sheet', fn(ReportRange $range) => [
            'report'     => $this->ledgerReports()->balanceSheet($range),
            'comparison' => $this->balanceComparison($range),
            'atDay'      => true,
        ]);
    }

    protected function incomeStatementAction(): HtmlResponse
    {
        return $this->reportPage('incomeStatement', 'income-statement', fn(ReportRange $range) => [
            'report'     => $this->ledgerReports()->incomeStatement($range),
            'comparison' => $this->incomeComparison($range),
        ]);
    }

    // ── the comparison over the last years (owner 2026-10-10) ────────────

    /** «Vorjahre» on (the default) unless the form sent `compare=0` — an absent parameter is the first load. */
    private function reportCompares(): bool
    {
        return $this->reportParameter('compare') !== '0';
    }

    /**
     * The shown range and the same range in the older fiscal years — up to COMPARE_YEARS in all,
     * newest first. Each older range is the shown one moved back by whole years and clamped into
     * that year (a 29 February, a shorter first year); a year that does not exist is left out.
     *
     * @return list<ReportRange>
     */
    private function comparisonRanges(ReportRange $range): array
    {
        if (!$this->reportCompares()) {
            return [$range];
        }
        $older = array_values(array_filter(
            $this->reportYearSelection()->all(),
            static fn(FiscalYear $y) => $y->getStartDate() < $range->year->getStartDate(),
        ));
        usort($older, static fn(FiscalYear $a, FiscalYear $b) => $b->getStartDate() <=> $a->getStartDate());

        $ranges = [$range];
        foreach (array_slice($older, 0, self::COMPARE_YEARS - 1) as $n => $year) {
            $clamp    = static fn(\DateTimeImmutable $d) => max($year->getStartDate(), min($year->getEndDate(), $d));
            $back     = '-' . ($n + 1) . ' year';
            $ranges[] = new ReportRange($year, $clamp($range->from->modify($back)), $clamp($range->to->modify($back)));
        }

        return $ranges;
    }

    private function balanceComparison(ReportRange $range): StatementComparison
    {
        $sheets = array_map(fn(ReportRange $r) => $this->ledgerReports()->balanceSheet($r), $this->comparisonRanges($range));
        $col    = static fn(callable $f) => array_map($f, $sheets);

        return new StatementComparison($col(static fn($s) => $s->range->year->getCode()), [
            StatementComparison::block('Aktiven', $col(static fn($s) => $s->assets), 'Total Aktiven', $col(static fn($s) => $s->assets->total)),
            StatementComparison::block('Fremdkapital', $col(static fn($s) => $s->liabilities), 'Total Fremdkapital', $col(static fn($s) => $s->liabilities->total)),
            StatementComparison::block('Eigenkapital', $col(static fn($s) => $s->equity), 'Total Eigenkapital', $col(static fn($s) => $s->totalEquity()), [[
                // One period: the sign names it («Jahresgewinn 2026», FIN-UI-010); several: both.
                'label'   => count($sheets) === 1
                    ? ($sheets[0]->result->isNegative() ? 'Jahresverlust ' : 'Jahresgewinn ') . $range->year->getCode()
                    : 'Jahresgewinn / -verlust',
                'amounts' => $col(static fn($s) => $s->result),
            ]]),
            ['title' => '', 'rows' => [], 'extra' => [], 'totalLabel' => 'Total Passiven', 'totals' => $col(static fn($s) => $s->totalLiabilitiesAndEquity())],
        ]);
    }

    private function incomeComparison(ReportRange $range): StatementComparison
    {
        $ranges     = $this->comparisonRanges($range);
        $statements = array_map(fn(ReportRange $r) => $this->ledgerReports()->incomeStatement($r), $ranges);
        $col        = static fn(callable $f) => array_map($f, $statements);

        return new StatementComparison(array_map(static fn(ReportRange $r) => $r->year->getCode(), $ranges), [
            StatementComparison::block('Ertrag', $col(static fn($s) => $s->revenue), 'Total Ertrag', $col(static fn($s) => $s->revenue->total)),
            StatementComparison::block('Aufwand', $col(static fn($s) => $s->expense), 'Total Aufwand', $col(static fn($s) => $s->expense->total)),
            ['title' => '', 'rows' => [], 'extra' => [], 'totals' => $col(static fn($s) => $s->result()), 'totalLabel' => count($statements) === 1
                ? ($statements[0]->result()->isNegative() ? 'Verlust' : 'Gewinn') . ' (Ertrag − Aufwand)'
                : 'Gewinn / Verlust (Ertrag − Aufwand)'],
        ]);
    }

    /** `?account=` an account NUMBER (any account of the chart; the select offers the postable ones), `?page=`. */
    protected function accountStatementAction(): HtmlResponse
    {
        $number = $this->reportParameter('account');
        $page   = (int) $this->reportParameter('page');

        return $this->reportPage('accountStatement', 'account-statement', function (ReportRange $range) use ($number, $page) {
            $account = $number === '' ? null : $this->reportAccounts()->findOneBy(['number' => $number]);

            return [
                'keep'          => ['account' => $number],
                'accounts'      => array_values(array_filter($this->reportAccounts()->allInOrder(), fn(Account $a) => $a->isPostable())),
                'accountNumber' => $number,
                'accountMissing' => $number !== '' && $account === null,
                'report'        => $account === null ? null : $this->ledgerReports()->accountStatement($account, $range, $page),
            ];
        });
    }

    /**
     * The report as a PDF (FIN-PDF-001): `?report=<tab>` plus the same range parameters as the
     * page (`year`, `from`, `to`, `period`), answered inline through the kernel's `pdf/report`
     * layout. A report without a PDF yet, or no fiscal year → back to the report page.
     */
    protected function pdfAction(): BytesResponse|RedirectResponse
    {
        $tab     = $this->reportParameter('report');
        $notices = [];
        $range   = $this->reportRange($notices);
        if ($range === null || !in_array($tab, self::REPORT_PDF, true)) {
            return $this->redirect($this->reportBase() . '/' . (isset(self::REPORT_TABS[$tab]) ? $tab : 'trial-balance'));
        }

        $issuer    = $this->reportMandator()?->getName() ?? '';
        $printedAt = (new \DateTimeImmutable())->format('d.m.Y H:i');
        $pdf       = match ($tab) {
            // The same comparison as the screen — `compare` travels with the form (FIN-UI-011).
            'balance-sheet'    => ReportPdf::balanceSheet($this->ledgerReports()->balanceSheet($range), $this->balanceComparison($range), $issuer, $printedAt),
            'income-statement' => ReportPdf::incomeStatement($this->incomeComparison($range), $range, $issuer, $printedAt),
            'trial-balance'    => ReportPdf::trialBalance($this->ledgerReports()->trialBalance($range), $range, $issuer, $printedAt),
            // The PDF takes every line on one «page» of the report — paging is the screen's.
            'journal'          => ReportPdf::journal($this->ledgerReports()->journal($range, 1, self::PDF_ALL_ROWS), $range, $issuer, $printedAt),
            'account-statement' => null,
        };
        if ($tab === 'account-statement') {
            $number  = $this->reportParameter('account');
            $account = $number === '' ? null : $this->reportAccounts()->findOneBy(['number' => $number]);
            if ($account === null) {
                return $this->redirect($this->reportBase() . '/account-statement');
            }
            $pdf = ReportPdf::accountStatement($this->ledgerReports()->accountStatement($account, $range, 1, self::PDF_ALL_ROWS), $range, $issuer, $printedAt);
        }

        $from = $tab === 'balance-sheet' ? null : $range->fromDay();

        return $this->bytes($pdf->output(), ReportPdf::fileName(self::REPORT_TABS[$tab], $range->year->getCode(), $range->toDay(), $from), 'application/pdf');
    }

    /** `?page=` — the journal of the range, oldest first, one page of entries at a time. */
    protected function journalAction(): HtmlResponse
    {
        $page = (int) $this->reportParameter('page');

        return $this->reportPage('journal', 'journal', fn(ReportRange $range) => [
            'report' => $this->ledgerReports()->journal($range, $page),
        ]);
    }

    // ── parameters and page frame ────────────────────────────────────────

    /** A GET parameter as a trimmed string — '' when absent or not a scalar (`?from[]=` is not a date). */
    private function reportParameter(string $name): string
    {
        $value = DI::getRequest()->getGetParameter($name);

        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    /**
     * The range the request asks for (see the class doc for the defaults);
     * null when no fiscal year is open at all. What was not usable is
     * reported in $notices (German, shown above the report).
     *
     * @param list<string> $notices
     */
    private function reportRange(array &$notices): ?ReportRange
    {
        $code = $this->reportParameter('year');
        $year = $this->reportYearSelection()->resolve($code);
        if ($code !== '' && $year?->getCode() !== $code) {
            $notices[] = "Geschäftsjahr «{$code}» gibt es nicht — das gewählte wird gezeigt.";
        }
        if ($year === null) {
            return null;
        }

        // The «Zeitraum» dropdown (owner 2026-10-10: «Ganzes Jahr» and the months in ONE select
        // between the date and «Anzeigen», no row of buttons). It wins only when the dates were
        // NOT edited: the form carries the range it was rendered with (`shown_from` /
        // `shown_to`), so a changed date is never overruled by the still-selected period. No
        // JavaScript — the select and the dates are one GET form.
        $period = $this->reportParameter('period');
        if ($period !== ''
            && $this->reportParameter('from') === $this->reportParameter('shown_from')
            && $this->reportParameter('to') === $this->reportParameter('shown_to')) {
            if ($period === 'year') {
                return ReportRange::wholeYear($year);
            }
            foreach ($year->getPeriods() as $p) {
                if ($p->getStartDate()->format('Y-m') === $period) {
                    return new ReportRange($year, $p->getStartDate(), $p->getEndDate());
                }
            }
            $notices[] = "Zeitraum «{$period}» gibt es im Geschäftsjahr {$year->getCode()} nicht — es gelten die Daten.";
        }

        $day = function (string $name, \DateTimeImmutable $default, string $label) use ($year, &$notices): \DateTimeImmutable {
            $value = $this->reportParameter($name);
            if ($value === '') {
                return $default;
            }
            $date = ManualEntryForm::parseDate($value);
            if ($date === null || !$year->covers($date)) {
                $notices[] = "«{$label}» {$value} liegt nicht im Geschäftsjahr {$year->getCode()} — es gilt " . $default->format('d.m.Y') . '.';

                return $default;
            }

            return $date;
        };
        $from = $day('from', $year->getStartDate(), 'Von');
        $to   = $day('to', $year->getEndDate(), 'Bis');
        if ($from > $to) {
            $notices[] = '«Von» liegt nach «Bis» — das ganze Geschäftsjahr wird gezeigt.';

            return ReportRange::wholeYear($year);
        }

        return new ReportRange($year, $from, $to);
    }

    /**
     * One report page: the parameters, the tabs (hc2), the report's template in
     * the body. $build turns the range into the report's own context; it is
     * not called without a fiscal year.
     *
     * @param callable(ReportRange): array<string, mixed> $build
     */
    private function reportPage(string $template, string $tab, callable $build): HtmlResponse
    {
        $notices = [];
        $range   = $this->reportRange($notices);
        $base    = $this->reportBase();
        /** A report URL with the current range; $params override or add (`account`, `page`, `from`, `to`). */
        $link = static function (string $report, array $params = []) use ($range, $base): string {
            $query = $params + ($range === null ? [] : ['year' => $range->year->getCode(), 'from' => $range->fromDay(), 'to' => $range->toDay()]);
            $query = array_filter($query, static fn($v) => $v !== null && $v !== '');

            return $base . '/' . $report . ($query === [] ? '' : '?' . http_build_query($query));
        };

        $own = $range === null ? [] : $build($range);
        // The year dropdown (hc1): a link carries the YEAR only — from and to fall back to the
        // new year's bounds — plus what the page keeps (the account of the account statement).
        $keep  = $own['keep'] ?? [];
        $years = $this->reportYearSelection()->all();
        $fySelection = $range === null ? null : [
            'years'   => $years,
            'current' => $range->year,
            'href'    => static fn(string $code): string => $link($tab, ['year' => $code, 'from' => null, 'to' => null] + $keep),
        ];
        // A page past the last shows the last — and says so, like the date fallbacks.
        $paging = isset($own['report']) && property_exists($own['report'], 'paging') ? $own['report']->paging : null;
        if ($paging !== null && $paging->isBeyondLast()) {
            $notices[] = "Seite {$paging->requested} gibt es nicht — die letzte Seite ({$paging->pageCount}) wird gezeigt.";
        }

        // The report's own context first: it may override a default (`keep`).
        $response = $this->html($own + [
            'years'       => $years,
            'keep'        => [],
            'range'       => $range,
            'fySelection' => $fySelection,
            'notices'     => $notices,
            'tab'         => $tab,
            'reportTabs'  => self::REPORT_TABS,
            'months'      => self::REPORT_MONTHS,
            'pdfTabs'     => self::REPORT_PDF,
            'compareTabs' => self::REPORT_COMPARE,
            'compare'     => $this->reportCompares(),
            'link'        => $link,
            'reportBase'  => $base,
            'journalBase' => $this->reportJournalBase(),
            'fmt'         => static fn(?Money $m) => AmountFormat::of($m),
            // `$own` wins: the balance sheet is a statement AT a day.
            'atDay'       => false,
        ]);
        $this->layoutManager->removeSection('main');
        if ($range === null) {
            $this->layoutManager->addPartials('noYear', 'Backend/ReportController', ReportLayout::NS);

            return $response;
        }
        // The report alone — paper is the PDF's job (FIN-PDF-001; the print-only header and
        // footer of FIN-PRINT-001 are gone).
        $this->layoutManager->addPartials($template, 'Backend/ReportController', ReportLayout::NS);
        // The report tabs stand in the toolbar (hc2, owner 2026-09-29) — the tab row stays empty.
        $this->layoutManager->addPartials('tabs', 'Backend/ReportController', ReportLayout::NS, 'hc2');
        // The year holds for the whole area: the top of the rail (owner 2026-10-08, ADR-033 rev.), the dropdown the journal shares.
        $this->layoutManager->addPartials('fiscalYearSelect', 'Backend/partials', ReportLayout::NS, 'railSelect');

        return $response;
    }
}
