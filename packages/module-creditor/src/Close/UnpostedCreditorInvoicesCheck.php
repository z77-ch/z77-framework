<?php

namespace Z77\Module\Creditor\Close;

use Z77\Core\DI;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Repositories\CreditorInvoiceRepository;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * The Kreditorenjournal's answer to financial's open-work questions (registry of
 * persistence-doctrine, `creditorConfig → openWorkChecks`; receipt's `UnpostedReceiptsCheck` model;
 * creditor plan §6). In the direct variant an invoice is booked on its PAYMENT date, so:
 *
 *   - a PAID invoice not yet posted whose «bezahlt am» lies inside the range BLOCKS `period-close`
 *     (one finding per invoice for the first {@see LIST_LIMIT}, then one that counts the rest — its
 *     entry belongs to that year) and `vat-return` (its input tax belongs to the period); for
 *     `ledger-report` it is a WARNING;
 *   - UNPAID invoices dated up to the end of the range are a WARNING for `period-close` and
 *     `ledger-report`: the cash principle (owner 2026-11-15) — they are no liability in the books
 *     and become an expense of the year they are paid in.
 *
 * The parameters are financial's: `from` and `to` (`DateTimeImmutable`). Financial knows no module.
 */
final class UnpostedCreditorInvoicesCheck implements OpenWorkCheckInterface
{
    /** How many invoices are named one by one before the rest is counted. */
    public const LIST_LIMIT = 10;

    /** The link target of a finding — the Kreditorenjournal. */
    private const AREA = 'creditor-journal';

    public function check(string $scope, array $parameters): iterable
    {
        $from = $parameters['from'] ?? null;
        $to   = $parameters['to'] ?? null;
        if (!$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException("Open-work scope '{$scope}' passes 'from' and 'to' as DateTimeImmutable — got " . get_debug_type($from) . ' / ' . get_debug_type($to));
        }
        /** @var CreditorInvoiceRepository $invoices */
        $invoices = DI::getUnifiedEntityManager()->getRepository(CreditorInvoice::class);
        $found    = $invoices->unpostedBetween($from, $to, self::LIST_LIMIT);
        $paid     = $found['paid'];
        $unpaid   = $found['unpaid'];
        $total    = static fn(string $gross): string => AmountFormat::of(Money::fromDecimal($gross, LedgerService::baseCurrency()));
        $what     = static fn(int $n): string => $n === 1 ? '1 Rechnung' : $n . ' Rechnungen';

        if ($paid['count'] > 0) {
            if ($scope === 'ledger-report') {
                yield Finding::warning($what($paid['count']) . ' dieses Zeitraums bezahlt, aber im Kreditorenjournal noch nicht verbucht (Total ' . $total($paid['gross']) . ') — der Bericht zeigt sie erst nach «Journal verbuchen».', self::AREA);
            } elseif ($scope === 'vat-return') {
                yield Finding::blocking($what($paid['count']) . ' dieser Periode bezahlt, aber im Kreditorenjournal noch nicht verbucht — zuerst «Journal verbuchen».', self::AREA);
            } else {
                foreach ($paid['rows'] as $row) {
                    $name = $row['number'] !== '' ? 'Rechnung ' . $row['number'] : 'Rechnung (ohne Nr.) ' . $row['id'];
                    yield Finding::blocking($name . ', bezahlt am ' . (new \DateTimeImmutable($row['paid_on']))->format('d.m.Y') . ', ist im Kreditorenjournal nicht verbucht — vervollständigen und «Journal verbuchen».', self::AREA);
                }
                $rest = $paid['count'] - count($paid['rows']);
                if ($rest > 0) {
                    yield Finding::blocking('… und ' . $rest . ' weitere bezahlte Rechnungen im Kreditorenjournal dieses Jahres.', self::AREA);
                }
            }
        }
        if ($unpaid['count'] > 0 && $scope !== 'vat-return') {
            yield Finding::warning($what($unpaid['count']) . ' im Kreditorenjournal noch unbezahlt (Total ' . $total($unpaid['gross']) . ') — im direkten Verfahren nicht als Verbindlichkeit gebucht; sie werden Aufwand des Jahres, in dem sie bezahlt werden.', self::AREA);
        }
    }
}
