<?php

namespace Z77\Module\Debtor\Close;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceKind;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;

/**
 * debtor's answer to financial's close check (plan §5.3, ADR-042 decision
 * 11; P5 part 1): a document still in `invoicing` — issued, not final, so
 * not posted — dated inside the range being closed is BLOCKING. Its
 * `finalize()` posts on the invoice date, and after the close that date
 * lies in a closed year: the ledger would refuse it, and the books of the
 * closed year would lack the turnover.
 *
 * Registered under the open-work scope `period-close` in `debtorConfig`
 * (the registry of persistence-doctrine — financial knows no module). The
 * scope's parameters are financial's (`FiscalYearCloseService`): `from` and
 * `to` (`DateTimeImmutable`, the year's first and last day) and
 * `fiscalYear` (the code, only for the message context — unused here).
 *
 * One finding per document for the first {@see LIST_LIMIT}, then one that
 * counts the rest — the modal stays readable with a hundred drafts.
 *
 * NOT checked yet (P4 — payments and CAMT.054 do not exist): payments or
 * bank transactions not yet booked; plan §5.3 names them blocking as well.
 */
final class InvoicingInProgressCheck implements OpenWorkCheckInterface
{
    /** How many documents are named one by one before the rest is counted. */
    public const LIST_LIMIT = 10;

    public function check(string $scope, array $parameters): iterable
    {
        $from = $parameters['from'] ?? null;
        $to   = $parameters['to'] ?? null;
        if (!$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException("Open-work scope '{$scope}' passes 'from' and 'to' as DateTimeImmutable — got " . get_debug_type($from) . ' / ' . get_debug_type($to));
        }
        /** @var InvoiceRepository $invoices */
        $invoices = DI::getUnifiedEntityManager()->getRepository(Invoice::class);
        $found    = $invoices->invoicingBetween($from, $to, self::LIST_LIMIT);

        foreach ($found['rows'] as $row) {
            $name = (InvoiceKind::tryFrom((string) $row['kind'])?->label() ?? (string) $row['kind']) . ' ' . $row['number'];
            yield Finding::blocking(
                $name . ' vom ' . (new \DateTimeImmutable((string) $row['invoice_date']))->format('d.m.Y') . ' ist noch in Fakturierung, also nicht verbucht — erst definitiv stellen (verbuchen).',
                'invoice:' . $row['id']
            );
        }
        $rest = $found['count'] - count($found['rows']);
        if ($rest > 0) {
            yield Finding::blocking("… und {$rest} weitere Dokumente in Fakturierung in diesem Zeitraum.", 'invoice:more');
        }
    }
}
