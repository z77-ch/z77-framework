<?php

namespace Z77\Module\Financial\Repositories;

use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\Entities\VatReturnCorrection;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see VatReturnCorrection} — the stored block «Korrekturen früherer
 * Perioden» of the posted VAT returns (ADR-042 addendum 2 of 2026-11-25, points 1 and 2). Written
 * only by `VatReturn\VatReturnPostingService` with its return; read by the return (a posted return's
 * block, «frühere Korrekturen» of a later one) and the yearly overview. Doctrine-only (DQL).
 */
class VatReturnCorrectionRepository extends DoctrineRepository
{
    /**
     * The block rows a return carries, in block order (corrected period, then form order) — also
     * of a lifted return: it keeps its rows.
     *
     * @return list<VatReturnCorrection>
     */
    public function ofReturn(VatReturn $return): array
    {
        return $this->em()->createQueryBuilder()
            ->select('c')
            ->from(VatReturnCorrection::class, 'c')
            ->where('c.vatReturn = :r')
            ->setParameter('r', $return)
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The block rows carried by LIVE returns whose CORRECTED period ends between the two days (both
     * inclusive) — what has been declared as a correction of those periods so far: «frühere
     * Korrekturen» of a later block, the corrections of a tax year. A lifted carrying return's rows
     * are not counted (its correction entries return to the block of its next revision). In order
     * of the corrected period, the carrying return's period, the block position.
     *
     * @return list<VatReturnCorrection>
     */
    public function correctingBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->em()->createQueryBuilder()
            ->select('c', 'r')
            ->from(VatReturnCorrection::class, 'c')
            ->join('c.vatReturn', 'r')
            ->where('r.live = true')
            ->andWhere('c.correctedTo BETWEEN :from AND :to')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('c.correctedFrom', 'ASC')
            ->addOrderBy('r.periodFrom', 'ASC')
            ->addOrderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
