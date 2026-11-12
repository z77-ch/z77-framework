<?php

namespace Z77\Module\Receipt\Close;

use Z77\Core\DI;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Receipt\Repositories\ReceiptRepository;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * The receipt journal's answer to financial's open-work questions (registry of
 * persistence-doctrine, `receiptConfig → openWorkChecks`; the debtor model of ADR-054 step 9):
 * a receipt still `captured` and dated inside the range is
 *
 *   - `period-close` — BLOCKING, one finding per receipt for the first {@see LIST_LIMIT}, then one
 *     that counts the rest: its posting lands on the receipt date, which after the close lies in a
 *     closed year — the ledger would refuse it, and the books of the closed year would lack it;
 *   - `vat-return` — BLOCKING: the input tax of a captured receipt belongs to the period;
 *   - `ledger-report` — a WARNING: the report is right about what is posted, but incomplete.
 *
 * The parameters are financial's: `from` and `to` (`DateTimeImmutable`). Financial knows no module;
 * it only asks the scope.
 */
final class UnpostedReceiptsCheck implements OpenWorkCheckInterface
{
    /** How many receipts are named one by one before the rest is counted. */
    public const LIST_LIMIT = 10;

    public function check(string $scope, array $parameters): iterable
    {
        $from = $parameters['from'] ?? null;
        $to   = $parameters['to'] ?? null;
        if (!$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException("Open-work scope '{$scope}' passes 'from' and 'to' as DateTimeImmutable — got " . get_debug_type($from) . ' / ' . get_debug_type($to));
        }
        /** @var ReceiptRepository $receipts */
        $receipts = DI::getUnifiedEntityManager()->getRepository(Receipt::class);
        $found    = $receipts->unpostedBetween($from, $to, self::LIST_LIMIT);
        if ($found['count'] === 0) {
            return;
        }
        $what = $found['count'] === 1 ? '1 Beleg' : $found['count'] . ' Belege';

        if ($scope === 'ledger-report') {
            $total = AmountFormat::of(Money::fromDecimal($found['gross'], LedgerService::baseCurrency()));
            yield Finding::warning("{$what} dieses Zeitraums im Belegjournal noch nicht verbucht (Total {$total}) — der Bericht zeigt sie erst nach «Journal verbuchen».", 'receipt-journal');

            return;
        }
        if ($scope === 'vat-return') {
            yield Finding::blocking("{$what} dieser Periode im Belegjournal noch nicht verbucht — zuerst «Journal verbuchen».", 'receipt-journal');

            return;
        }
        foreach ($found['rows'] as $row) {
            $date = (new \DateTimeImmutable($row['receipt_date']))->format('d.m.Y');
            $text = $row['text'] !== '' ? ' «' . $row['text'] . '»' : '';
            yield Finding::blocking('Beleg ' . $row['id'] . ' vom ' . $date . $text . ' ist im Belegjournal erfasst, aber nicht verbucht — vervollständigen und «Journal verbuchen», oder löschen.', 'receipt-journal');
        }
        $rest = $found['count'] - count($found['rows']);
        if ($rest > 0) {
            yield Finding::blocking('… und ' . $rest . ' weitere Belege im Belegjournal dieses Jahres.', 'receipt-journal');
        }
    }
}
