<?php

namespace Z77\Module\Order\Services;

use Z77\Module\Order\Entities\OrderPhase;
use Z77\Module\Order\Entities\OrderStatus;
use Z77\Module\Order\Repositories\OrderStatusRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The data upgrade of the seed-once status file for the two statuses after invoicing (owner
 * 2026-10-24): «Fakturiert» (`invoiced`, set by «Fakturieren») and «Gebucht» (`booked`, set by
 * «Journal verbuchen»). `order_statuses.json` is seeded ONCE (ADR-024) — an installation from
 * before 2026-10-24 has `booked` as the default of `closed` and no `invoiced`. There is no generic
 * record merge for file master data (ADR-032: import is not sync; ADR-050 covers navigation only),
 * so this module carries the smallest one it needs, idempotent:
 *
 *   - a row with the code {@see CODE} exists → nothing (already upgraded, or the owner made it);
 *   - else that row is ADDED in phase `closed`, label «Fakturiert», sorted just before the current
 *     default of `closed`, and TAKES the switch «Wird gesetzt beim Fakturieren»; the row that held it
 *     gets «Wird gesetzt beim Verbuchen» — unless some row already has that switch.
 *
 * The code here is a creation key (the row it writes), not a selection of behaviour — the rules
 * still select by flag (harness N1). Labels the owner edited are never touched: an installation
 * that renamed `booked` to «Fakturiert» by hand shows two «Fakturiert» after the upgrade and renames
 * the posting status back to «Gebucht» (order.md).
 *
 * Run by {@see OrderService::statuses()} and the status master-data list — the first screen that
 * reads the statuses after an update writes the file once; afterwards it is a read of a few rows.
 * Never inside a Doctrine unit of work that could roll back (a file write is not part of it).
 */
final class OrderStatusUpgrade
{
    public const CODE  = 'invoiced';
    public const LABEL = 'Fakturiert';

    /** @return bool true = the file was written */
    public static function apply(UnifiedEntityManager $em): bool
    {
        /** @var OrderStatusRepository $repo */
        $repo = $em->getRepository(OrderStatus::class);
        $rows = $repo->allInOrder();
        if ($rows === []) {
            return false;   // no file (yet) — the installer seeds the current shape
        }
        foreach ($rows as $row) {
            if ($row->getCode() === self::CODE) {
                return false;
            }
        }

        $rules   = new OrderStatuses($rows);
        $former  = $rules->defaultOf(OrderPhase::Closed);
        $posting = array_filter($rows, static fn(OrderStatus $s) => $s->isSetOnPosting()) !== [];

        $new = new OrderStatus([
            'code'          => self::CODE,
            'label'         => self::LABEL,
            'phase'         => OrderPhase::Closed->value,
            'position'      => $former !== null ? max(0, $former->getPosition() - 5) : 0,
            'start'         => false,
            'phase_default' => true,
            'active'        => true,
        ]);
        foreach ($rows as $row) {
            if ($row->isPhaseDefault() && $row->getPhase() === OrderPhase::Closed) {
                $row->setPhaseDefault(false);
                $em->persist($row);
            }
        }
        if (!$posting && $former !== null) {
            $former->setSetOnPosting(true);
            $em->persist($former);
        }
        $em->persist($new);
        $em->flush();

        return true;
    }
}
