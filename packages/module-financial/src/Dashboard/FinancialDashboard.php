<?php

namespace Z77\Module\Financial\Dashboard;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Module\Financial\Services\YearOpeningService;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Dashboard\DashboardContributor;
use Z77\Shared\Dashboard\DashboardEntry;
use Z77\Shared\Dashboard\Severity;

/**
 * financial's entries on the backend dashboard (ADR-052), registered under
 * `dashboard` in `financialConfig` — both → Geschäftsjahre:
 *
 *   - no OPEN fiscal year covers today (none, or the covering one is closed)
 *     — URGENT: every posting dated today is refused;
 *   - today lies in the LAST month of its year and no year covers the first
 *     day of next month — todo: the next year is to be opened before the
 *     month turns (for a calendar year: in December, for 1 January);
 *   - the year covering today has a CLOSED predecessor and no opening yet
 *     (P5, 2026-10-22) — todo «Eröffnung {code} nicht erstellt»: neither the
 *     generated opening nor entries on the contra account (an imported or
 *     manual opening; `YearOpeningService::isOpened()`, no balances read).
 *
 * One `findByDate()` per question (the years never overlap).
 */
final class FinancialDashboard implements DashboardContributor
{
    private const TARGET = ['backend', 'finance', 'fiscal-year', 'list'];

    public function entries(AuthUser $user): iterable
    {
        return self::entriesOn(new \DateTimeImmutable('today'));
    }

    /** @return list<DashboardEntry> the entries as of $today — the harness asks for other days */
    public static function entriesOn(\DateTimeImmutable $today): array
    {
        /** @var FiscalYearRepository $years */
        $years   = DI::getUnifiedEntityManager()->getRepository(FiscalYear::class);
        $current = $years->findByDate($today);
        if ($current === null || $current->isClosed()) {
            return [DashboardEntry::pending(Severity::Urgent, 'Kein offenes Geschäftsjahr für heute', self::TARGET)];
        }
        $entries   = [];
        $nextMonth = $today->modify('first day of next month');
        if ($current->getEndDate() < $nextMonth && $years->findByDate($nextMonth) === null) {
            $entries[] = DashboardEntry::pending(Severity::Todo, 'Geschäftsjahr ab ' . $nextMonth->format('d.m.Y') . ' nicht eröffnet', self::TARGET);
        }
        $predecessor = $years->predecessorOf($current);
        if ($predecessor !== null && $predecessor->isClosed() && !(new YearOpeningService(DI::getUnifiedEntityManager()))->isOpened($current)) {
            $entries[] = DashboardEntry::pending(Severity::Todo, 'Eröffnung ' . $current->getCode() . ' nicht erstellt', self::TARGET);
        }

        return $entries;
    }
}
