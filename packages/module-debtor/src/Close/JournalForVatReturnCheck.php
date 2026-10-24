<?php

namespace Z77\Module\Debtor\Close;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;

/**
 * Scope `vat-return` (ADR-054 step 9, plan §7): a VAT return of $from … $to is BLOCKED while an
 * invoice dated in that period still waits in the Faktura-Journal — the tax claim of an issued
 * invoice belongs to the period, the ledger does not hold it yet. Registered now; financial asks the
 * scope once the VAT return is built (P5 part 2) — until then nobody asks it.
 */
final class JournalForVatReturnCheck implements OpenWorkCheckInterface
{
    public function check(string $scope, array $parameters): iterable
    {
        $from = $parameters['from'] ?? null;
        $to   = $parameters['to'] ?? null;
        if (!$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException("Open-work scope '{$scope}' passes 'from' and 'to' as DateTimeImmutable — got " . get_debug_type($from) . ' / ' . get_debug_type($to));
        }
        /** @var InvoiceRepository $invoices */
        $invoices = DI::getUnifiedEntityManager()->getRepository(Invoice::class);
        $count    = $invoices->journalSummary($from, $to)['count'];
        if ($count > 0) {
            yield Finding::blocking(($count === 1 ? '1 Rechnung' : "{$count} Rechnungen") . ' dieser Periode im Faktura-Journal noch nicht verbucht — zuerst «Journal verbuchen».', 'invoice-journal');
        }
    }
}
