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
 * 11; P5 part 1): a document still in `invoicing` — a draft, no number
 * (owner 2026-10-10), so not posted — dated inside the range being closed is BLOCKING. Its
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
 * Bank transactions not yet booked are the other check of the same scope
 * (`UnbookedTransactionsCheck`, P4 part 2). A draft that is not wanted any
 * more is deleted (`InvoicingService::discard()`, owner 2026-10-13) — before
 * that, a forgotten draft blocked the close forever.
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
            // A draft has no number (owner 2026-10-10: it is drawn at the booking print) — named by date and customer.
            $label = InvoiceKind::tryFrom((string) $row['kind'])?->label() ?? (string) $row['kind'];
            $date  = (new \DateTimeImmutable((string) $row['invoice_date']))->format('d.m.Y');
            $party = trim((string) $row['addr_first_name'] . ' ' . (string) $row['addr_name']);
            yield Finding::blocking(
                $label . ' (Entwurf) vom ' . $date . ($party !== '' ? ' an ' . $party : '') . ' ist noch in Fakturierung, also nicht verbucht — mit «Buchungsdruck …» verbuchen oder, wenn nicht mehr gebraucht, «Entwurf löschen …».',
                'invoice:' . $row['id']
            );
        }
        $rest = $found['count'] - count($found['rows']);
        if ($rest > 0) {
            yield Finding::blocking("… und {$rest} weitere Dokumente in Fakturierung in diesem Zeitraum.", 'invoice:more');
        }
    }
}
