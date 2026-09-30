<?php

namespace Z77\Module\Financial\Services;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\CloseAction;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\FiscalYearCloseLog;
use Z77\Module\Financial\Entities\Period;
use Z77\Module\Financial\Entities\PeriodState;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWork;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks;
use Z77\Persistence\Interface\TransactionInterface;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * Closing and reopening a fiscal year (P5 part 1, owner decisions
 * 2026-09-30; plan §5.3, ADR-042 decisions 10–11 and its addendum
 * 2026-09-30):
 *
 *   - Only the whole YEAR is closed: every period of it becomes `closed`.
 *     There is no year state — `FiscalYear::isClosed()` = all periods closed.
 *   - IN ORDER: a year closes only when the year before it (ending the day
 *     before it starts) is closed, or it is the earliest year. Reopening
 *     goes in reverse: only a closed year whose successor is NOT closed.
 *   - The CLOSE CHECK asks every module through the open-work registry
 *     (`OpenWorkChecks`, persistence-doctrine — financial knows none of the
 *     checks) under the scope {@see SCOPE} with the parameters
 *     `fiscalYear` (the code), `from` and `to` (the year's first and last
 *     day, `DateTimeImmutable`). A BLOCKING finding refuses the close;
 *     WARNINGS need the caller's explicit confirmation. Financial adds one
 *     warning of its own: the year has not ended yet.
 *   - Reopen: an admin (the screen's access config decides who — the
 *     service does not know roles; a CLI script is trusted like the
 *     import) with a MANDATORY reason; closed → open. P5 part 2 introduces
 *     `vat-settled` and must decide what a reopen restores then.
 *   - Every close and every reopen writes a {@see FiscalYearCloseLog} row —
 *     who, when, why (reopen) or which warnings were confirmed (close).
 *
 * Each method OWNS its unit of work and refuses to run inside an open one
 * (the `FiscalYearService` model). Inside: every fiscal-year row locked
 * (`lockAll()` — against an opening, a delete or a second close/reopen),
 * then the periods of the year and its neighbour re-read under an
 * exclusive lock (`lockPeriodsOf()`), then EVERY rule again on that fresh
 * state — order, close check — and only then the periods move and the
 * protocol row is written. A posting share-locks the period's YEAR and
 * then the period after drawing its number
 * (`PostingRules::lockedPeriodState()`), and so does a manual edit or
 * delete: the same order the close takes exclusively (year → period), so
 * a close waits for a posting in flight, and a posting that waited for a
 * close sees `closed` and is refused — no deadlock (two-process harness
 * cases YC42/YC43). The ledger's own refusal stays the last line of
 * defence (ADR-042 decision 11).
 */
final class FiscalYearCloseService
{
    /** The open-work scope financial asks before closing a year (persistence-doctrine.md, «open-work check registry»). */
    public const SCOPE = 'period-close';

    private ?OpenWorkChecks $checks;

    /**
     * @param string|null         $actor  the name stamped into the protocol; null = the session identity ({@see Actor::current()})
     * @param OpenWorkChecks|null $checks the registry to ask; null = every registered module's (`OpenWorkChecks::fromModules()`)
     */
    public function __construct(private readonly UnifiedEntityManager $em, private readonly ?string $actor = null, ?OpenWorkChecks $checks = null)
    {
        $this->checks = $checks;
    }

    /**
     * Why $year cannot be closed now, or null — what the screen asks to show
     * «Jahr abschliessen …». Order only (already closed, predecessor open);
     * the close check is asked by {@see closeCheck()}. A READ without locks:
     * {@see close()} decides again under the lock.
     *
     * @return ?string a {@see FiscalYearCloseRefusedException} reason
     */
    public function closeRefusal(FiscalYear $year): ?string
    {
        if ($year->isClosed()) {
            return FiscalYearCloseRefusedException::ALREADY_CLOSED;
        }
        $predecessor = $this->years()->predecessorOf($year);

        return $predecessor !== null && !$predecessor->isClosed() ? FiscalYearCloseRefusedException::PREDECESSOR_OPEN : null;
    }

    /**
     * Why $year cannot be reopened now, or null — what the screen asks to
     * show «Wieder öffnen …» (together with the access check). A READ
     * without locks; {@see reopen()} decides again under the lock.
     *
     * @return ?string a {@see FiscalYearCloseRefusedException} reason
     */
    public function reopenRefusal(FiscalYear $year): ?string
    {
        if (!$year->isClosed()) {
            return FiscalYearCloseRefusedException::NOT_CLOSED;
        }
        $successor = $this->years()->successorOf($year);

        return $successor !== null && $successor->isClosed() ? FiscalYearCloseRefusedException::SUCCESSOR_CLOSED : null;
    }

    /**
     * «Anything open in this year?» — every registered close check for the
     * year's days, plus financial's own warning when the year has not ended
     * yet. What the confirmation modal lists; {@see close()} asks again
     * under the lock.
     */
    public function closeCheck(FiscalYear $year): OpenWork
    {
        $from = $year->getStartDate();
        $to   = $year->getEndDate();
        $own  = [];
        if ($to >= new \DateTimeImmutable('today')) {
            $own[] = Finding::warning(
                'Das Geschäftsjahr endet erst am ' . $to->format('d.m.Y') . ' — nach dem Abschluss wird darin nichts mehr gebucht.',
                'fiscal-year:' . $year->getCode()
            );
        }
        $asked = $this->registry()->ask(self::SCOPE, ['fiscalYear' => $year->getCode(), 'from' => $from, 'to' => $to]);

        return new OpenWork(array_merge($own, $asked->blocking(), $asked->warnings()));
    }

    /**
     * The fingerprint of the WARNINGS of a close check — what the modal
     * shows and the POST hands back to {@see close()}: the closer confirms
     * exactly the warnings he saw; when they differ under the lock the close
     * is refused (`warnings-changed`, review 2026-09-30). Message and
     * reference of every warning, in order.
     */
    public static function warningsFingerprint(OpenWork $open): string
    {
        return hash('sha256', json_encode(
            array_map(static fn(Finding $f) => [$f->message, $f->reference], $open->warnings()),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ));
    }

    /**
     * Close the fiscal year $yearId: every period → `closed`, one protocol
     * row. Refused (rolled back, nothing written) when the year is gone, has
     * no periods or is closed already, when its predecessor is not closed,
     * when a close check blocks, or when there are warnings and
     * $confirmedWarnings is not the {@see warningsFingerprint()} of exactly
     * those warnings (null = nothing confirmed).
     *
     * Order inside: every fiscal-year row locked (the rows ALSO give the
     * neighbours — a locking read), the periods of the year and its
     * predecessor locked, and only THEN the first plain read: the REPEATABLE
     * READ snapshot the close check reads in starts after every lock is held
     * (review 2026-09-30), so it sees every document finalised before the
     * locks, and a finalize after them waits at the period and is refused.
     *
     * @throws FiscalYearCloseRefusedException
     * @throws \LogicException a unit of work is open, or nobody is named as the actor
     */
    public function close(int $yearId, ?string $confirmedWarnings = null): void
    {
        $transaction = $this->ownUnitOfWork('close()');
        $actor       = $this->actorName();

        $transaction->run(function () use ($yearId, $confirmedWarnings, $actor): void {
            [$previousId, ] = $this->lockedNeighbours($yearId);
            $periods = $this->lockedPeriods([$yearId, $previousId]);
            $year    = $this->freshYear($yearId);   // the first plain read — after every lock

            $own = $periods[$yearId] ?? [];
            if ($own === []) {
                throw new FiscalYearCloseRefusedException(FiscalYearCloseRefusedException::NO_PERIODS, "Fiscal year {$year->getCode()} has no periods — nothing to close");
            }
            if (self::allClosed($own)) {
                throw new FiscalYearCloseRefusedException(FiscalYearCloseRefusedException::ALREADY_CLOSED, "Fiscal year {$year->getCode()} is closed already");
            }
            if ($previousId !== null && !self::allClosed($periods[$previousId] ?? [])) {
                throw new FiscalYearCloseRefusedException(FiscalYearCloseRefusedException::PREDECESSOR_OPEN,
                    "Fiscal year {$year->getCode()} cannot be closed before the year before it — years close in order");
            }
            $open = $this->closeCheck($year);
            if ($open->isBlocked()) {
                throw new FiscalYearCloseRefusedException(FiscalYearCloseRefusedException::BLOCKED,
                    "Fiscal year {$year->getCode()}: the close check reported " . count($open->blocking()) . ' blocking finding(s)', $open);
            }
            if ($open->warnings() !== []) {
                if ($confirmedWarnings === null) {
                    throw new FiscalYearCloseRefusedException(FiscalYearCloseRefusedException::WARNINGS_UNCONFIRMED,
                        "Fiscal year {$year->getCode()}: the close check reported warnings — confirm them to close", $open);
                }
                if (!hash_equals(self::warningsFingerprint($open), $confirmedWarnings)) {
                    throw new FiscalYearCloseRefusedException(FiscalYearCloseRefusedException::WARNINGS_CHANGED,
                        "Fiscal year {$year->getCode()}: the warnings changed since they were confirmed — check them again", $open);
                }
            }

            foreach ($own as $period) {
                $period->transitionTo(PeriodState::Closed);
            }
            $warnings = array_map(static fn(Finding $f) => $f->message, $open->warnings());
            $this->em->persist(new FiscalYearCloseLog($year, CloseAction::Close, $actor, new \DateTimeImmutable(), null, $warnings));
        });
    }

    /**
     * Reopen the closed fiscal year $yearId (owner 2026-09-30 — an admin,
     * with a reason): every period `closed` → `open`, one protocol row.
     * Refused when the reason is empty or longer than the protocol keeps
     * ({@see FiscalYearCloseLog::REASON_LENGTH}), the year is gone or not
     * closed, or the year after it is closed (reverse order: the latest
     * closed first).
     *
     * @throws FiscalYearCloseRefusedException
     * @throws \LogicException a unit of work is open, or nobody is named as the actor
     */
    public function reopen(int $yearId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new FiscalYearCloseRefusedException(FiscalYearCloseRefusedException::NO_REASON, 'A fiscal year is reopened with a reason only');
        }
        if (mb_strlen($reason) > FiscalYearCloseLog::REASON_LENGTH) {
            throw new FiscalYearCloseRefusedException(FiscalYearCloseRefusedException::REASON_TOO_LONG,
                'The reason is longer than ' . FiscalYearCloseLog::REASON_LENGTH . ' characters — the protocol keeps it whole or not at all');
        }
        $transaction = $this->ownUnitOfWork('reopen()');
        $actor       = $this->actorName();

        $transaction->run(function () use ($yearId, $reason, $actor): void {
            [, $nextId] = $this->lockedNeighbours($yearId);
            $periods = $this->lockedPeriods([$yearId, $nextId]);
            $year    = $this->freshYear($yearId);

            if (!self::allClosed($periods[$yearId] ?? [])) {
                throw new FiscalYearCloseRefusedException(FiscalYearCloseRefusedException::NOT_CLOSED, "Fiscal year {$year->getCode()} is not closed");
            }
            if ($nextId !== null && self::allClosed($periods[$nextId] ?? [])) {
                throw new FiscalYearCloseRefusedException(FiscalYearCloseRefusedException::SUCCESSOR_CLOSED,
                    "Fiscal year {$year->getCode()} cannot be reopened while the year after it is closed — reopen the latest closed year first");
            }

            foreach ($periods[$yearId] as $period) {
                $period->transitionTo(PeriodState::Open);
            }
            $this->em->persist(new FiscalYearCloseLog($year, CloseAction::Reopen, $actor, new \DateTimeImmutable(), $reason));
        });
    }

    /**
     * Every fiscal-year row locked FIRST (`lockAll()`); from the locked rows
     * (date order, contiguous years) the ids of the years right before and
     * right after $yearId — no plain read yet.
     *
     * @return array{0: ?int, 1: ?int} previous id, next id
     * @throws FiscalYearCloseRefusedException NOT_FOUND
     */
    private function lockedNeighbours(int $yearId): array
    {
        $rows  = $this->years()->lockAll();
        $index = array_search($yearId, array_column($rows, 'id'), true);
        if ($index === false) {
            throw new FiscalYearCloseRefusedException(FiscalYearCloseRefusedException::NOT_FOUND, "Fiscal year #{$yearId} does not exist");
        }

        return [$rows[$index - 1]['id'] ?? null, $rows[$index + 1]['id'] ?? null];
    }

    /** The year as an entity — by a query, not the identity map; called after the locks. */
    private function freshYear(int $yearId): FiscalYear
    {
        $year = $this->years()->findOneBy(['id' => $yearId]);
        if ($year === null) {
            throw new FiscalYearCloseRefusedException(FiscalYearCloseRefusedException::NOT_FOUND, "Fiscal year #{$yearId} does not exist");
        }

        return $year;
    }

    /**
     * The periods of the given years, locked and refreshed, by year id.
     *
     * @param list<?int> $yearIds
     * @return array<int, list<Period>>
     */
    private function lockedPeriods(array $yearIds): array
    {
        $byYear = [];
        foreach ($this->years()->lockPeriodsOf(array_values(array_filter($yearIds))) as $period) {
            $byYear[(int) $period->getFiscalYear()->getId()][] = $period;
        }

        return $byYear;
    }

    /** @param list<Period> $periods */
    private static function allClosed(array $periods): bool
    {
        if ($periods === []) {
            return false;
        }
        foreach ($periods as $period) {
            if ($period->getState() !== PeriodState::Closed->value) {
                return false;
            }
        }

        return true;
    }

    private function registry(): OpenWorkChecks
    {
        return $this->checks ??= OpenWorkChecks::fromModules(DI::getModuleManager());
    }

    /** @throws \LogicException a unit of work is already open */
    private function ownUnitOfWork(string $method): TransactionInterface
    {
        $transaction = $this->em->getTransaction(FiscalYear::class);
        if ($transaction->isOpen()) {
            throw new \LogicException("FiscalYearCloseService::{$method} owns its unit of work — call it outside getTransaction()->run(), so the locks it takes hold until its own commit");
        }

        return $transaction;
    }

    private function actorName(): string
    {
        return $this->actor !== null ? Actor::normalize($this->actor) : Actor::current();
    }

    private function years(): FiscalYearRepository
    {
        return $this->em->getRepository(FiscalYear::class);
    }
}
