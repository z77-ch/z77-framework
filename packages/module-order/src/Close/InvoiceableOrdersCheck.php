<?php

namespace Z77\Module\Order\Close;

use Z77\Core\DI;
use Z77\Module\Order\Entities\OrderStatus;
use Z77\Module\Order\Entities\SalesOrder;
use Z77\Module\Order\Repositories\OrderStatusRepository;
use Z77\Module\Order\Repositories\SalesOrderRepository;
use Z77\Module\Order\Services\OrderStatuses;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;

/**
 * order's answer to financial's year close (plan §12 / §13 step 10, plan §5.3 of the bauplan):
 * an order dated inside the range being closed that sits in a BOOKABLE phase (`confirmed`,
 * `delivered` — ADR-043 addendum 2026-10-15: every confirmed order, a service order may never see a
 * delivery note) — its turnover is not invoiced, so not in the books of that year. A WARNING, not a
 * block: the order is not a booking, the books of the year are complete without it; whether its
 * invoice belongs in the closed year (the service date decides the VAT period) is the office's call.
 *
 * Which statuses count is read from their PHASE (`OrderPhase::isBookable()`, ADR-043 — no code is
 * named). Registered under the open-work scope `period-close` in `orderConfig` (the registry of
 * persistence-doctrine — financial knows no module). The scope's parameters are financial's:
 * `from` and `to` (`DateTimeImmutable`, the year's first and last day).
 *
 * One finding per order for the first {@see LIST_LIMIT}, then one that counts the rest.
 */
final class InvoiceableOrdersCheck implements OpenWorkCheckInterface
{
    /** How many orders are named one by one before the rest is counted. */
    public const LIST_LIMIT = 10;

    public function check(string $scope, array $parameters): iterable
    {
        $from = $parameters['from'] ?? null;
        $to   = $parameters['to'] ?? null;
        if (!$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException("Open-work scope '{$scope}' passes 'from' and 'to' as DateTimeImmutable — got " . get_debug_type($from) . ' / ' . get_debug_type($to));
        }
        $em = DI::getUnifiedEntityManager();
        /** @var OrderStatusRepository $statusRepo */
        $statusRepo = $em->getRepository(OrderStatus::class);
        $statuses   = (new OrderStatuses($statusRepo->allInOrder()))->all();
        $labels     = [];
        foreach ($statuses as $status) {
            if ($status->getPhase()->isBookable()) {
                $labels[$status->getCode()] = $status->getLabel();
            }
        }
        /** @var SalesOrderRepository $orders */
        $orders = $em->getRepository(SalesOrder::class);
        $found  = $orders->inStatusBetween(array_keys($labels), $from, $to, self::LIST_LIMIT);

        foreach ($found['rows'] as $row) {
            yield Finding::warning(self::message($row, $labels), 'sales-order:' . $row['id']);
        }
        $rest = $found['count'] - count($found['rows']);
        if ($rest > 0) {
            yield Finding::warning("… und {$rest} weitere noch nicht fakturierte Aufträge in diesem Zeitraum.", 'sales-order:more');
        }
    }

    /**
     * «Auftrag 12 vom 14.10.2026 an Test-Firma xy steht in «Zu fakturieren» …» — pure, the harness
     * checks it offline.
     *
     * @param array<string, mixed>  $row    a row of `SalesOrderRepository::inStatusBetween()`
     * @param array<string, string> $labels status code → label
     */
    public static function message(array $row, array $labels): string
    {
        $date  = (new \DateTimeImmutable((string) $row['order_date']))->format('d.m.Y');
        $party = (string) $row['kind'] === 'organisation'
            ? (string) $row['company']
            : trim((string) $row['last_name'] . ' ' . (string) $row['first_name']);
        $label = $labels[(string) $row['status_code']] ?? (string) $row['status_code'];

        return 'Auftrag ' . $row['number'] . ' vom ' . $date . ($party !== '' ? ' an ' . $party : '')
            . ' steht in «' . $label . '» — noch nicht fakturiert, sein Umsatz fehlt in diesem Jahr. Mit «Buchungsdruck …» verbuchen oder bewusst ins nächste Jahr nehmen.';
    }
}
