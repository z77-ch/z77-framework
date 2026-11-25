<?php

namespace Z77\Module\Financial\Dashboard;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Dashboard\DashboardContributor;
use Z77\Shared\Dashboard\DashboardEntry;
use Z77\Shared\Dashboard\Severity;

/**
 * financial's entries on the backend dashboard (ADR-052), registered under
 * `dashboard` in `financialConfig`:
 *
 *   - no OPEN fiscal year covers today (none covers it, or the covering one
 *     is closed) while at least ONE year exists — URGENT: every posting dated
 *     today is refused. With no year at all the installation is fresh: the
 *     setup step «Geschäftsjahr eröffnen» says it instead
 *     (`ProjectHealth\FiscalYearCheck`, project checks 2026-11-19) → Geschäftsjahre;
 *   - today lies in the LAST month of its year and no year covers the first
 *     day of next month — todo: the next year is to be opened before the
 *     month turns (for a calendar year: in December, for 1 January) → Geschäftsjahre;
 *   - the VAT return that is due (owner decision S7-3 of 2026-11-25, one line):
 *     «MWST-Abrechnung 3. Quartal 2026 fällig bis 29.11.2026» — the first
 *     period that has ENDED and is not settled (`VatReturnService::nextReturn()`,
 *     the period «Neue Abrechnung» opens); todo up to and including the due
 *     day, URGENT after it → the return page of that period. Only for a
 *     mandator that settles its VAT in z77: whenever the return's own gate
 *     (`VatReturnService::settingsRefusal()`) refuses — not liable, a setting
 *     missing, a method z77 does not build, the record unreadable — there is
 *     no line. No yearly line (S7-3: the yearly overview is a link in
 *     «Jahr abschliessen»).
 *
 * «Eröffnung {code} nicht erstellt» (P5, 2026-10-22) moved to the project
 * checks (`ProjectHealth\OpeningBalanceCheck`, setup step 12), same condition.
 *
 * One `findByDate()` per question (the years never overlap); the VAT line
 * costs one read of the mandator record and one read of the live returns.
 */
final class FinancialDashboard implements DashboardContributor
{
    private const TARGET = ['backend', 'finance', 'fiscal-year', 'list'];

    /** The return page of one period (`VatReturnLayout::BASE` + `/form?from=`) — where «Neue Abrechnung» leads. */
    private const VAT_RETURN_TARGET = ['backend', 'finance', 'vat-return', 'form'];

    /**
     * The return is due 60 days after its period ends (Art. 71 para. 1 MWSTG: «innert 60 Tagen
     * nach Ablauf der Abrechnungsperiode») — Q3 ending 30.09. is due by 29.11.
     */
    private const VAT_RETURN_DUE_DAYS = 60;

    public function entries(AuthUser $user): iterable
    {
        return self::entriesOn(new \DateTimeImmutable('today'));
    }

    /**
     * @param VatReturnService|null $vat the VAT return's reader; null = the mandator's, through DI (a harness passes its own)
     * @return list<DashboardEntry> the entries as of $today — the harness asks for other days
     */
    public static function entriesOn(\DateTimeImmutable $today, ?VatReturnService $vat = null): array
    {
        $today   = $today->setTime(0, 0);
        $entries = self::fiscalYearEntries($today);
        $due     = self::vatReturnDue($vat ?? new VatReturnService(DI::getUnifiedEntityManager()), $today);
        if ($due !== null) {
            $entries[] = $due;
        }

        return $entries;
    }

    /** The last day the return of $period may be filed — {@see VAT_RETURN_DUE_DAYS} after its end. */
    private static function vatReturnDueOn(VatPeriod $period): \DateTimeImmutable
    {
        return $period->to->setTime(0, 0)->modify('+' . self::VAT_RETURN_DUE_DAYS . ' days');
    }

    /** @return list<DashboardEntry> the two fiscal-year questions */
    private static function fiscalYearEntries(\DateTimeImmutable $today): array
    {
        /** @var FiscalYearRepository $years */
        $years   = DI::getUnifiedEntityManager()->getRepository(FiscalYear::class);
        $current = $years->findByDate($today);
        if ($current === null || $current->isClosed()) {
            return $years->latest() === null
                ? []   // a fresh installation: the setup step «Geschäftsjahr eröffnen»
                : [DashboardEntry::pending(Severity::Urgent, 'Kein offenes Geschäftsjahr für heute', self::TARGET)];
        }
        $nextMonth = $today->modify('first day of next month');
        if ($current->getEndDate() < $nextMonth && $years->findByDate($nextMonth) === null) {
            return [DashboardEntry::pending(Severity::Todo, 'Geschäftsjahr ab ' . $nextMonth->format('d.m.Y') . ' nicht eröffnet', self::TARGET)];
        }

        return [];
    }

    /** The line for the VAT return that is due — null when the mandator does not settle in z77 or no ended period is open. */
    private static function vatReturnDue(VatReturnService $vat, \DateTimeImmutable $today): ?DashboardEntry
    {
        if ($vat->settingsRefusal() !== null) {
            return null;
        }
        $period = $vat->nextReturn($today);
        if ($period === null) {
            return null;
        }
        $dueOn = self::vatReturnDueOn($period);

        return DashboardEntry::pending(
            $today > $dueOn ? Severity::Urgent : Severity::Todo,
            'MWST-Abrechnung ' . $period->label() . ' fällig bis ' . $dueOn->format('d.m.Y'),
            self::VAT_RETURN_TARGET,
            ['from' => $period->from->format('Y-m-d')]
        );
    }
}
