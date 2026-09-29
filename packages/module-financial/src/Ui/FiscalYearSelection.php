<?php
namespace Z77\Module\Financial\Ui;

use Z77\Core\DI,
    Z77\Core\Session\SessionManager,
    Z77\Module\Financial\Entities\FiscalYear,
    Z77\Module\Financial\Repositories\FiscalYearRepository
;

/**
 * The fiscal year a finance work screen shows — ONE rule for the journal and
 * the reports (owner 2026-09-29, financial.md FIN-JOURNAL-CAPTURE-001):
 *
 *   1. an explicit `?year=<code>` of a known year wins — and is remembered
 *      for the session; picking the DEFAULT year clears the remembered
 *      choice (back to «follow today»);
 *   2. else the year remembered in the session, while that year still exists;
 *   3. else the default: the year containing today, else the latest one
 *      (`FiscalYearRepository::currentOrLatest()`); null without any year.
 *
 * Session-sticky the way the backend's content editing language is (ADR-013,
 * content.md CONTENT-LANG-001): the URL parameter is only the switch trigger,
 * the choice lives in the session (`SessionManager`, key {@see SESSION_KEY}).
 * The session holds the year's CODE — a deleted year is simply no longer found.
 */
final class FiscalYearSelection
{
    /** Session key of the remembered year code — distinct from every other screen's state. */
    public const SESSION_KEY = 'financialFiscalYear';

    public function __construct(
        private FiscalYearRepository $years,
        private SessionManager $session,
    ) {}

    /** The selection of the running request: the ledger's years, the request's session. */
    public static function of(FiscalYearRepository $years): self
    {
        return new self($years, DI::getSessionManager());
    }

    /**
     * The selected year (rules 1–3 of the class doc). An unknown or empty
     * $requested code is ignored — the caller says so where it matters.
     */
    public function resolve(mixed $requested): ?FiscalYear
    {
        $code = is_string($requested) || is_int($requested) ? trim((string) $requested) : '';
        $year = $code === '' ? null : $this->years->findOneBy(['code' => $code]);
        if ($year !== null) {
            $this->remember($year);

            return $year;
        }

        return $this->remembered() ?? $this->defaultYear();
    }

    /**
     * Makes $year the session's choice — or clears the choice when $year is
     * the default, so the screen follows today again. Also what a posting
     * into another year does (the journal shows the year it posted into).
     */
    public function remember(FiscalYear $year): void
    {
        if ($year->getCode() === $this->defaultYear()?->getCode()) {
            $this->session->remove(self::SESSION_KEY);

            return;
        }
        $this->session->set(self::SESSION_KEY, $year->getCode());
    }

    /** The default year: the one containing today, else the latest; null without any year. */
    public function defaultYear(): ?FiscalYear
    {
        return $this->years->currentOrLatest();
    }

    /** Every year, newest first — the dropdown's options. @return list<FiscalYear> */
    public function all(): array
    {
        return $this->years->allWithPeriods();
    }

    /** The remembered year while it still exists; a stale code is dropped. */
    private function remembered(): ?FiscalYear
    {
        $code = $this->session->get(self::SESSION_KEY);
        if (!is_string($code) || $code === '') {
            return null;
        }
        $year = $this->years->findOneBy(['code' => $code]);
        if ($year === null) {
            $this->session->remove(self::SESSION_KEY);
        }

        return $year;
    }
}
