<?php

namespace Z77\Module\Financial\ProjectHealth;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Module\Financial\Services\YearOpeningService;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 12 («Ersteinrichtung», owner 2026-11-19): the open year covering today has a CLOSED
 * predecessor and no opening yet — neither the generated one nor entries on the contra account
 * (an imported or manual opening; `YearOpeningService::isOpened()`, no balances read). The
 * condition is the one decided for the dashboard in P5 (2026-10-22); the line «Eröffnung {code}
 * nicht erstellt» moved here from `FinancialDashboard`, so it is shown once. While the
 * predecessor is still OPEN nothing is said: an opening then is only provisional (P5).
 *
 * Three small queries (the year of today, its predecessor, the opening probe).
 */
final class OpeningBalanceCheck extends SetupCheck
{
    private string $code = '';

    /** @param \DateTimeImmutable|null $today null = today — the harness passes another day */
    public function __construct(private readonly ?\DateTimeImmutable $today = null) {}

    public function id(): string
    {
        return 'financial.opening';
    }

    public function order(): int
    {
        return 120;
    }

    public function target(): array
    {
        return ['backend', 'finance', 'fiscal-year', 'list'];
    }

    public function label(): string
    {
        return 'Eröffnung ' . $this->code . ' nicht erstellt';
    }

    public function isOpen(): bool
    {
        $em = DI::getUnifiedEntityManager();
        /** @var FiscalYearRepository $years */
        $years   = $em->getRepository(FiscalYear::class);
        $current = $years->findByDate($this->today ?? new \DateTimeImmutable('today'));
        if ($current === null || $current->isClosed()) {
            return false;   // FinancialDashboard reports it (urgent) — or FiscalYearCheck on an empty table
        }
        $predecessor = $years->predecessorOf($current);
        if ($predecessor === null || !$predecessor->isClosed() || (new YearOpeningService($em))->isOpened($current)) {
            return false;
        }
        $this->code = $current->getCode();

        return true;
    }
}
