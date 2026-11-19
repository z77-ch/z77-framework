<?php

namespace Z77\Module\Financial\ProjectHealth;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 3 («Ersteinrichtung», owner 2026-11-19): NO fiscal year exists at all — a fresh
 * installation. Split from the dashboard's «Kein offenes Geschäftsjahr für heute»
 * (`FinancialDashboard`), which stays URGENT for a running installation (years exist, but none
 * open covers today — every posting dated today is refused) and is silent while no year exists,
 * so the two never show together. One query (`FiscalYearRepository::latest()`).
 */
final class FiscalYearCheck extends SetupCheck
{
    public function id(): string
    {
        return 'financial.fiscal-year';
    }

    public function order(): int
    {
        return 30;
    }

    public function target(): array
    {
        return ['backend', 'finance', 'fiscal-year', 'list'];
    }

    public function label(): string
    {
        return 'Geschäftsjahr eröffnen';
    }

    public function isOpen(): bool
    {
        /** @var FiscalYearRepository $years */
        $years = DI::getUnifiedEntityManager()->getRepository(FiscalYear::class);

        return $years->latest() === null;
    }
}
