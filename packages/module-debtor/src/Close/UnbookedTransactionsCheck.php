<?php

namespace Z77\Module\Debtor\Close;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\BankTransaction;
use Z77\Module\Debtor\Repositories\BankTransactionRepository;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;

/**
 * debtor's second answer to financial's close check (plan §5.3, P4
 * part 2): a bank transaction of an imported CAMT.054 message that is
 * still `matched` or `unmatched` — received, not booked — with a value
 * date inside the range being closed is BLOCKING: its payment would be
 * dated in the closed year and the ledger would refuse it; the books of
 * the year would lack the receipt. An `ignored` or `booked` transaction
 * is settled work.
 *
 * Registered next to `InvoicingInProgressCheck` under the scope
 * `period-close` in `debtorConfig`; the same parameters (`from`, `to`).
 */
final class UnbookedTransactionsCheck implements OpenWorkCheckInterface
{
    /** How many transactions are named one by one before the rest is counted. */
    public const LIST_LIMIT = 10;

    public function check(string $scope, array $parameters): iterable
    {
        $from = $parameters['from'] ?? null;
        $to   = $parameters['to'] ?? null;
        if (!$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException("Open-work scope '{$scope}' passes 'from' and 'to' as DateTimeImmutable — got " . get_debug_type($from) . ' / ' . get_debug_type($to));
        }
        /** @var BankTransactionRepository $transactions */
        $transactions = DI::getUnifiedEntityManager()->getRepository(BankTransaction::class);
        $found        = $transactions->unbookedBetween($from, $to, self::LIST_LIMIT);
        foreach ($found['rows'] as $row) {
            yield Finding::blocking(
                'Zahlungseingang vom ' . (new \DateTimeImmutable((string) $row['value_date']))->format('d.m.Y') . ' über ' . $row['amount'] . ' ' . $row['currency']
                . ' (Meldung ' . $row['message_ref'] . ') ist noch nicht verbucht — zuordnen und verbuchen, oder ignorieren.',
                'bank-transaction:' . $row['id']
            );
        }
        $rest = $found['count'] - count($found['rows']);
        if ($rest > 0) {
            yield Finding::blocking("… und {$rest} weitere nicht verbuchte Zahlungseingänge in diesem Zeitraum.", 'bank-transaction:more');
        }
    }
}
