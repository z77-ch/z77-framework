<?php

namespace Z77\Module\Debtor\Close;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceKind;
use Z77\Module\Debtor\Entities\InvoiceState;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;

/**
 * debtor's answer to financial's close check (plan §5.3, ADR-042 decision 11; P5 part 1;
 * widened by ADR-054 decision 17, owner decision O1 of 2026-10-23): every document NOT POSTED
 * dated inside the range being closed is BLOCKING —
 *
 *   - a draft (`invoicing`, no number): «Fakturieren …» or «Entwurf löschen …»;
 *   - an issued document in the Faktura-Journal (`issued`, numbered, the customer holds it, not
 *     posted): «Journal verbuchen». An issued document dated in the NEXT year is outside the
 *     range and does not block (O1 — «invoices concerning the closing year»).
 *
 * Its posting lands on the invoice date, and after the close that date lies in a closed year:
 * the ledger would refuse it, and the books of the closed year would lack the turnover.
 *
 * Registered under the open-work scope `period-close` in `debtorConfig` (the registry of
 * persistence-doctrine — financial knows no module). The scope's parameters are financial's
 * (`FiscalYearCloseService`): `from` and `to` (`DateTimeImmutable`, the year's first and last
 * day) and `fiscalYear` (the code, only for the message context — unused here).
 *
 * One finding per document for the first {@see LIST_LIMIT}, then one that counts the rest — the
 * modal stays readable with a hundred documents. Bank transactions not yet booked are the other
 * check of the same scope (`UnbookedTransactionsCheck`, P4 part 2).
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
        $found    = $invoices->unpostedBetween($from, $to, self::LIST_LIMIT);

        foreach ($found['rows'] as $row) {
            $label = InvoiceKind::tryFrom((string) $row['kind'])?->label() ?? (string) $row['kind'];
            $date  = (new \DateTimeImmutable((string) $row['invoice_date']))->format('d.m.Y');
            $party = trim((string) $row['addr_first_name'] . ' ' . (string) $row['addr_name']);
            $whom  = $party !== '' ? ' an ' . $party : '';
            yield Finding::blocking(
                (string) $row['state'] === InvoiceState::Issued->value
                    // Issued (ADR-054): numbered and with the customer, waiting in the Faktura-Journal.
                    ? $label . ' ' . $row['number'] . ' vom ' . $date . $whom . ' ist fakturiert, aber nicht verbucht — im Faktura-Journal «Journal verbuchen».'
                    // A draft has no number (owner 2026-10-10: it is drawn by «Fakturieren») — named by date and customer.
                    : $label . ' (Entwurf) vom ' . $date . $whom . ' ist noch in Fakturierung, also nicht verbucht — mit «Fakturieren …» fakturieren und das Faktura-Journal verbuchen oder, wenn nicht mehr gebraucht, «Entwurf löschen …».',
                'invoice:' . $row['id']
            );
        }
        $rest = $found['count'] - count($found['rows']);
        if ($rest > 0) {
            yield Finding::blocking("… und {$rest} weitere Dokumente in diesem Zeitraum, die noch nicht verbucht sind (Entwürfe oder im Faktura-Journal).", 'invoice:more');
        }
    }
}
