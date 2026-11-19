<?php

namespace Z77\Module\Financial\ProjectHealth;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 1 («Ersteinrichtung», owner 2026-11-19): the chart of accounts is EMPTY — nothing can
 * be posted, the mandator's accounts cannot be checked. The KMU chart is adopted by a button on
 * the empty chart (never seeded, owner 2026-09-22), or a project imports its own. One
 * `SELECT 1 … LIMIT 1` (`AccountRepository::isEmpty()`).
 */
final class ChartOfAccountsCheck extends SetupCheck
{
    public function id(): string
    {
        return 'financial.chart';
    }

    public function order(): int
    {
        return 10;
    }

    public function target(): array
    {
        return ['backend', 'finance', 'account', 'list'];
    }

    public function label(): string
    {
        return 'Kontenplan übernehmen';
    }

    public function isOpen(): bool
    {
        /** @var AccountRepository $accounts */
        $accounts = DI::getUnifiedEntityManager()->getRepository(Account::class);

        return $accounts->isEmpty();
    }
}
