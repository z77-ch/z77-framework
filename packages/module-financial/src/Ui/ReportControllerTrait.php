<?php
namespace Z77\Module\Financial\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Financial\Entities\Account,
    Z77\Module\Financial\Entities\FiscalYear,
    Z77\Module\Financial\Reports\ReportRange,
    Z77\Module\Financial\Repositories\AccountRepository,
    Z77\Module\Financial\Repositories\FiscalYearRepository,
    Z77\Module\Financial\Services\LedgerReports,
    Z77\Shared\Money\Money
;

/**
 * The ledger reports (plan §5.5, P2 part 3) — mounted by a thin host
 * controller in the backend (ADR-018 pattern, like the journal). Host side:
 * `use ReportControllerTrait` + a one-line layout config delegating to
 * {@see ReportLayout::config()}, and the controller's `defaultAction`
 * `trial-balance` in the host module's config.
 *
 * One PAGE per report, switched by the tab row: trial balance, balance
 * sheet, income statement, account statement, journal. Read-only — nothing
 * here writes, so there is no form post, no CSRF token and no JavaScript
 * (Rule 7): the parameters are a GET form (fiscal year, from, to; the
 * account on the account statement), the months of the year are links that
 * set from–to, paging is `?page=` links. Printed from the browser: the
 * shell's print rules drop the chrome, `.be-noprint` drops the form and the
 * pager.
 *
 * Parameters: `?year=` a fiscal-year code (default: the year containing
 * today, else the latest — `FiscalYearRepository::currentOrLatest()`, the
 * journal's rule),
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
    /** The tab row: URL action → German label, in reading order. */
    private const REPORT_TABS = [
        'trial-balance'     => 'Saldobilanz',
        'balance-sheet'     => 'Bilanz',
        'income-statement'  => 'Erfolgsrechnung',
        'account-statement' => 'Kontoblatt',
        'journal'           => 'Journal',
    ];

    private const REPORT_MONTHS = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

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
            'report' => $this->ledgerReports()->balanceSheet($range),
        ]);
    }

    protected function incomeStatementAction(): HtmlResponse
    {
        return $this->reportPage('incomeStatement', 'income-statement', fn(ReportRange $range) => [
            'report' => $this->ledgerReports()->incomeStatement($range),
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
        $year = $code === '' ? null : $this->reportYears()->findOneBy(['code' => $code]);
        if ($code !== '' && $year === null) {
            $notices[] = "Geschäftsjahr «{$code}» gibt es nicht — das aktuelle wird gezeigt.";
        }
        $year ??= $this->reportYears()->currentOrLatest();
        if ($year === null) {
            return null;
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
     * One report page: the parameters, the tab row, the report's template in
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
        // A page past the last shows the last — and says so, like the date fallbacks.
        $paging = isset($own['report']) && property_exists($own['report'], 'paging') ? $own['report']->paging : null;
        if ($paging !== null && $paging->isBeyondLast()) {
            $notices[] = "Seite {$paging->requested} gibt es nicht — die letzte Seite ({$paging->pageCount}) wird gezeigt.";
        }

        // The report's own context first: it may override a default (`keep`).
        $response = $this->html($own + [
            'years'       => $this->reportYears()->allWithPeriods(),
            'keep'        => [],
            'range'       => $range,
            'notices'     => $notices,
            'tab'         => $tab,
            'reportTabs'  => self::REPORT_TABS,
            'months'      => self::REPORT_MONTHS,
            'link'        => $link,
            'reportBase'  => $base,
            'journalBase' => $this->reportJournalBase(),
            'fmt'         => static fn(?Money $m) => AmountFormat::of($m),
        ]);
        $this->layoutManager->removeSection('main');
        if ($range === null) {
            $this->layoutManager->addPartials('noYear', 'Backend/ReportController', ReportLayout::NS);

            return $response;
        }
        $this->layoutManager->addPartials($template, 'Backend/ReportController', ReportLayout::NS);
        $this->layoutManager->addPartials('tabs', 'Backend/ReportController', ReportLayout::NS, 'tabs');
        $this->layoutManager->addPartials('yearSwitch', 'Backend/ReportController', ReportLayout::NS, 'hc2');

        return $response;
    }
}
