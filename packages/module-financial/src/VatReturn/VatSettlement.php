<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\Repositories\VatReturnRepository;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * THE one reader of «settled» (ADR-042 addendum 2026-11-24, point 2): a period is settled while a
 * LIVE ORDINARY return covers it — read from the posted returns (`vat_return`), never from the
 * period state and never stored a second time. Every question «is this date settled?», «which
 * return is live?», «which is the latest?» is answered here, so the correction path (stage S3)
 * changes one place.
 *
 * Two kinds of answer:
 *
 *   - LOCK-FREE ({@see periodCovering()}, {@see isSettled()}, {@see returnCovering()},
 *     {@see liveReturns()}, {@see latest()}): computed from ONE read of the live ordinary returns
 *     per instance, kept for the instance's life — one instance per request or per unit of work,
 *     like `VatReturnService`. A screen, a pre-check, the list.
 *   - LOCKING ({@see lockedPeriodCovering()}): a shared row lock on the committed state, inside an
 *     open unit of work — what a posting must ask after its own locks (stage S2 slice 2): under
 *     REPEATABLE READ a plain read answers from the snapshot taken at the unit of work's first read,
 *     i.e. possibly from before a return that committed meanwhile.
 *
 * Read only: no production code writes a return yet (stage S2 slice 1).
 */
final class VatSettlement
{
    /** @var list<VatReturn>|null */
    private ?array $live = null;

    public function __construct(private readonly UnifiedEntityManager $em)
    {
    }

    /** The settled period covering $date — null when the date is not settled. Lock-free. */
    public function periodCovering(\DateTimeImmutable $date): ?VatPeriod
    {
        return $this->returnCovering($date)?->period();
    }

    /** Whether a live ordinary return covers $date. Lock-free. */
    public function isSettled(\DateTimeImmutable $date): bool
    {
        return $this->returnCovering($date) !== null;
    }

    /** The live ordinary return covering $date — null when none does. Lock-free. */
    public function returnCovering(\DateTimeImmutable $date): ?VatReturn
    {
        foreach ($this->liveReturns() as $return) {
            if ($return->period()->covers($date)) {
                return $return;
            }
        }

        return null;
    }

    /**
     * The settled period covering $date under a SHARED row lock (until the caller's commit) — the
     * committed state, never the snapshot of the caller's unit of work. Needs an open unit of work.
     *
     * @throws \LogicException outside an open transaction
     */
    public function lockedPeriodCovering(\DateTimeImmutable $date): ?VatPeriod
    {
        $row = $this->returns()->lockedLiveCovering($date);
        if ($row === null) {
            return null;
        }
        return VatPeriod::stored(new \DateTimeImmutable($row['period_from']), new \DateTimeImmutable($row['period_to']), VatRhythm::from($row['rhythm']));
    }

    /**
     * Every live ordinary return, in period order — one per settled period. Lock-free, read once
     * per instance.
     *
     * @return list<VatReturn>
     */
    public function liveReturns(): array
    {
        return $this->live ??= $this->returns()->liveOrdinary();
    }

    /** The latest live ordinary return — the one a lift starts from and the next return follows. Lock-free. */
    public function latest(): ?VatReturn
    {
        $live = $this->liveReturns();

        return $live === [] ? null : $live[array_key_last($live)];
    }

    /**
     * Every ordinary return of the period starting on $period's first day — its revisions, the
     * latest first; at most one of them live. Not cached.
     *
     * @return list<VatReturn>
     */
    public function revisionsOf(VatPeriod $period): array
    {
        return $this->returns()->ordinaryRevisions($period->from);
    }

    /**
     * Every ordinary return — live or lifted — whose period ends between the two days, by period,
     * the latest revision first: the list of a fiscal year in one read. Not cached.
     *
     * @return list<VatReturn>
     */
    public function returnsEndingBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->returns()->ordinaryEndingBetween($from, $to);
    }

    private function returns(): VatReturnRepository
    {
        return $this->em->getRepository(VatReturn::class);
    }
}
