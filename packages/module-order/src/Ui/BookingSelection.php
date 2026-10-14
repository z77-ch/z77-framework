<?php
namespace Z77\Module\Order\Ui;

use Z77\Module\Order\Entities\SalesOrder;

/**
 * The selection of a booking print from the order screens (plan §7 «idempotent on the screen») —
 * pure, the harness checks it offline:
 *
 *   - {@see pairs()} reads the posted `{id}:{version}` strings (the version each row SHOWED);
 *   - {@see bookedBy()} decides whether a submit is the REPEAT of a booking print that went
 *     through: every order of the selection is linked to a FINAL invoice and stands at exactly
 *     the version it showed + 1 — what `OrderService::book()` left (link, status and stamp in ONE
 *     flush). Then the screen answers the same PDF again and books nothing. Anything else (an
 *     order edited since, booked by another print at another version, not booked) is not a
 *     repeat — the service judges it and refuses what it must.
 */
final class BookingSelection
{
    /** How many orders one booking print takes — a batch screen's size, not a limit of the writer. */
    public const MAX = 200;

    /**
     * `{id}:{version}` strings → the pairs `book()` takes, unique by id, at most {@see MAX};
     * anything else is dropped (the value only selects orders, the service re-checks each one
     * under its lock).
     *
     * @return list<array{id: int, version: int}>
     */
    public static function pairs(mixed $values): array
    {
        $pairs = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value) && preg_match('/^(\d{1,9}):(\d{1,9})$/', $value, $m)) {
                $pairs[(int) $m[1]] = ['id' => (int) $m[1], 'version' => (int) $m[2]];
            }
        }

        return array_slice(array_values($pairs), 0, self::MAX);
    }

    /**
     * The invoice ids to reprint when $pairs is the repeat of a booking print that went through,
     * else null.
     *
     * @param list<array{id: int, version: int}>  $pairs
     * @param callable(int): ?SalesOrder          $find  the order by id as it is now
     * @return list<int>|null unique invoice ids
     */
    public static function bookedBy(array $pairs, callable $find): ?array
    {
        if ($pairs === []) {
            return null;
        }
        $ids = [];
        foreach ($pairs as $pair) {
            $order   = $find($pair['id']);
            $invoice = $order?->getInvoice();
            if ($order === null || $invoice === null || !$invoice->isFinal() || $order->getVersion() !== $pair['version'] + 1) {
                return null;
            }
            $ids[(int) $invoice->getId()] = (int) $invoice->getId();
        }

        return array_values($ids);
    }
}
