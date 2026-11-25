<?php

namespace Z77\Module\Financial\Repositories;

use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\Entities\VatReturnFigure;
use Z77\Module\Financial\Entities\VatReturnKind;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see VatReturn} — the posted VAT returns (stage S2). The queries
 * behind `VatReturn\VatSettlement`, the ONE reader of «settled»; nothing else asks these rows
 * whether a period is settled. Every «settled» query reads ORDINARY returns only — a correction
 * or a Jahresabstimmung (stage S3) never settles a period.
 *
 * Doctrine-only (DQL; the locking read is SQL).
 */
class VatReturnRepository extends DoctrineRepository
{
    /**
     * Every LIVE ordinary return, in period order — what the lock-free answers of
     * `VatSettlement` are computed from (one row per settled period).
     *
     * @return list<VatReturn>
     */
    public function liveOrdinary(): array
    {
        return $this->em()->createQueryBuilder()
            ->select('r')
            ->from(VatReturn::class, 'r')
            ->where('r.kind = :kind AND r.live = true')
            ->setParameter('kind', VatReturnKind::Ordinary->value)
            ->orderBy('r.periodFrom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The settled period covering $date, read under a SHARED row lock (`LOCK IN SHARE MODE`,
     * held until the caller's commit) — the committed state, not the snapshot of the caller's
     * unit of work (REPEATABLE READ: a plain read would answer from before a return that
     * committed meanwhile). Refused outside a unit of work.
     *
     * @return array{period_from: string, period_to: string, rhythm: string}|null
     * @throws \LogicException outside an open transaction
     */
    public function lockedLiveCovering(\DateTimeImmutable $date): ?array
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockedLiveCovering() needs an open unit of work — the row lock holds until commit');
        }
        $day = $date->format('Y-m-d');
        $row = $this->connection()->fetchAssociative(
            'SELECT period_from, period_to, rhythm FROM vat_return WHERE kind = ? AND live = 1 AND period_to >= ? AND period_from <= ? ORDER BY period_from LIMIT 1 LOCK IN SHARE MODE',
            [VatReturnKind::Ordinary->value, $day, $day]
        );

        return $row === false ? null : ['period_from' => (string) $row['period_from'], 'period_to' => (string) $row['period_to'], 'rhythm' => (string) $row['rhythm']];
    }

    /**
     * The LATEST live ordinary return locked EXCLUSIVELY (`FOR UPDATE`, until the caller's commit)
     * — step 5 of a return writer's lock order (`VatReturnPostingService`): the order check
     * «follows the last without a gap» and «only the latest» are decided on the committed rows. A
     * locking read — it does not start the unit of work's snapshot. Answers its id, null when no
     * return is live. Doctrine-only (SQL).
     *
     * @throws \LogicException outside an open transaction
     */
    public function lockLatestLive(): ?int
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockLatestLive() needs an open unit of work — the row lock holds until commit');
        }
        $id = $this->connection()->fetchOne(
            'SELECT id FROM vat_return WHERE kind = ? AND live = 1 ORDER BY period_to DESC LIMIT 1 FOR UPDATE',
            [VatReturnKind::Ordinary->value]
        );

        return $id === false ? null : (int) $id;
    }

    /**
     * The committed version of one return under an EXCLUSIVE row lock — what a change of a posted
     * return (setting «Eingereicht am») compares the screen's version with. Null when the row does
     * not exist. Doctrine-only (SQL).
     *
     * @throws \LogicException outside an open transaction
     */
    public function lockedVersion(int $id): ?int
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockedVersion() needs an open unit of work — the row lock holds until commit');
        }
        $version = $this->connection()->fetchOne('SELECT version FROM vat_return WHERE id = ? FOR UPDATE', [$id]);

        return $version === false ? null : (int) $version;
    }

    /** One return re-read from the database (not the identity map's copy) — after its row lock. Doctrine-only (DQL). */
    public function fresh(int $id): ?VatReturn
    {
        return $this->em()->createQueryBuilder()
            ->select('r')
            ->from(VatReturn::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * Every ordinary return — live or lifted — whose period ENDS between the two days (the list
     * of a fiscal year), by period, the latest revision first.
     *
     * @return list<VatReturn>
     */
    public function ordinaryEndingBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->em()->createQueryBuilder()
            ->select('r')
            ->from(VatReturn::class, 'r')
            ->where('r.kind = :kind AND r.periodTo BETWEEN :from AND :to')
            ->setParameter('kind', VatReturnKind::Ordinary->value)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('r.periodFrom', 'ASC')
            ->addOrderBy('r.revision', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every ordinary return of the period starting on $from — its revisions, the latest first.
     *
     * @return list<VatReturn>
     */
    public function ordinaryRevisions(\DateTimeImmutable $from): array
    {
        return $this->em()->createQueryBuilder()
            ->select('r')
            ->from(VatReturn::class, 'r')
            ->where('r.kind = :kind AND r.periodFrom = :from')
            ->setParameter('kind', VatReturnKind::Ordinary->value)
            ->setParameter('from', $from->format('Y-m-d'))
            ->orderBy('r.revision', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The declared figures of a return, in the order they were written (form order).
     *
     * @return list<VatReturnFigure>
     */
    public function figuresOf(VatReturn $return): array
    {
        return $this->em()->createQueryBuilder()
            ->select('f')
            ->from(VatReturnFigure::class, 'f')
            ->where('f.vatReturn = :r')
            ->setParameter('r', $return)
            ->orderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
