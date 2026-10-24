<?php

namespace Z77\Module\Debtor\Close;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;
use Z77\Shared\Money\AmountFormat;

/**
 * Scope `ledger-report` (ADR-054 step 9): financial's report pages ask it for their range and show
 * the answer above the report — the ledger does not yet hold invoices that wait in the
 * Faktura-Journal, so a balance or an income statement of that range is incomplete. A WARNING,
 * never a block: the report is still right about what is posted. Financial stays module-free; it
 * only asks the scope.
 */
final class UnpostedJournalHint implements OpenWorkCheckInterface
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
        $summary  = $invoices->journalSummary($from, $to);
        if ($summary['count'] === 0) {
            return;
        }
        $totals = implode(', ', array_map(static fn($m) => $m->currency . ' ' . AmountFormat::of($m), $summary['gross']));
        $what   = $summary['count'] === 1 ? '1 Rechnung' : $summary['count'] . ' Rechnungen';

        yield Finding::warning("{$what} dieses Zeitraums im Faktura-Journal noch nicht verbucht (Total {$totals}) — der Bericht zeigt sie erst nach «Journal verbuchen».", 'invoice-journal');
    }
}
