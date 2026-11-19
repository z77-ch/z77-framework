<?php

namespace Z77\Module\Financial\Bank;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Z77\Core\DI;
use Z77\Module\Financial\Entities\BankLink;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Module\Financial\Entities\BankMovementMark;
use Z77\Module\Financial\Entities\BankPattern;
use Z77\Module\Financial\Repositories\BankLinkRepository;
use Z77\Module\Financial\Repositories\BankMovementMarkRepository;
use Z77\Module\Financial\Repositories\BankMovementRepository;
use Z77\Module\Financial\Repositories\BankPatternRepository;
use Z77\Module\Financial\Services\Actor;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The STATE of a bank movement and what the office decides about it (ADR-061 decisions 4–8, 18,
 * 19; stage C2). A movement is «geschäftlich», «privat» or «zu prüfen» — and NOTHING IS PRIVATE BY
 * SILENCE: without a link, a mark or a confirmed private pattern it is «zu prüfen».
 *
 * The state is DERIVED, never stored (`BankMovementRepository::STATE`, one SQL expression):
 *
 *   1. a LINK (an invoice, a payment, a receipt, a direct posting) → «geschäftlich» — a document
 *      beats a pattern (decision 7);
 *   2. else the office's mark BY HAND (`BankMovementMark`: private / review);
 *   3. else a PRIVATE pattern that takes it (`BankPattern`) → «privat»;
 *   4. else «zu prüfen».
 *
 * What the office does here:
 *
 *   - {@see markPrivate()} — «Privat»: confirms the movement's PATTERN as private, so every
 *     movement of it — the coming ones too — is «privat» (decision 5); with $onlyThis, without a
 *     key, or on a pattern that is «gemischt», only this one movement by hand. A pattern that so
 *     far carried only a booking proposal becomes private and keeps the proposal — what was booked
 *     of it stays business by its link, and decision 8 asks about it ({@see openQuestions()}).
 *     A movement another module CLAIMS ({@see BankMovementClaims} — debtor's collective credit of
 *     QR payments) is refused, pattern or by hand: it is that module's to link;
 *   - {@see setReview()} — this movement back to «zu prüfen»; {@see liftPattern()} — the private
 *     pattern taken back, all its movements «zu prüfen» again;
 *   - {@see answer()} — decision 8 for a movement that is LINKED although its pattern is private
 *     — a document found it, or it was booked before the pattern was confirmed
 *     ({@see openQuestions()}): «Ja» → the pattern is «gemischt», its movements always
 *     «zu prüfen»; «Nein» → this movement is an exception, the pattern stays private.
 *
 * And what «Geschäftlich buchen …» / «Buchung zurücknehmen …» call INSIDE their unit of work
 * (`BankPostingService`): {@see booked()} — the proposal learned and decision 8 asked —, and
 * {@see held()} — the movement held on «zu prüfen».
 *
 * Every write runs in a unit of work with the MOVEMENT ROW locked first; a refusal is a
 * {@see BankRefusedException} with a German sentence and nothing written. Financial names no
 * consumer here: what a movement is linked to is the link's label (Rule 9).
 */
final class BankMovementStates
{
    private ?string $actor;

    /** @param BankMovementClaims|null $claims the modules' claims on a movement; null = what the installed modules declare */
    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null, private ?BankMovementClaims $claims = null)
    {
        $this->actor = $actor !== null ? Actor::normalize($actor) : null;
    }

    // ── reads ────────────────────────────────────────────────────────────

    /**
     * The state of movement $id (`BankMovementRepository::STATES`), `BankMovementRepository::STATE_NONE`
     * ('') for a movement that is information only, or null when it is gone.
     */
    public function stateOf(int $movementId): ?string
    {
        return $this->movements()->statesOf([$movementId])[$movementId] ?? null;
    }

    /** The pattern of the movement's key — whatever it says, narrowed or not; null without one. */
    public function patternOf(BankMovement $movement): ?BankPattern
    {
        return $this->patterns()->findFor((int) $movement->getBankAccount()->getId(), $movement->getDirection(), $movement->getPatternKey());
    }

    /** The PRIVATE pattern that takes the movement (key, direction and its narrowing), or null. */
    public function privatePatternOf(BankMovement $movement): ?BankPattern
    {
        $pattern = $this->patternOf($movement);

        return $pattern !== null && $pattern->isPrivate() && $pattern->takes($movement->getAmount(), $movement->getBookingDate()) ? $pattern : null;
    }

    /** How many movements are «privat» because of $pattern. */
    public function privateBy(BankPattern $pattern): int
    {
        return $this->movements()->countPrivateBy((int) $pattern->getId());
    }

    /** The N of the question («N weitere Bewegungen sind dadurch privat»): the movements private by $pattern, $movement itself not counted. */
    public function othersOf(BankMovement $movement, BankPattern $pattern): int
    {
        $id      = (int) $movement->getId();
        $mark    = $this->markOf($movement)?->getState();
        $counted = $pattern->takes($movement->getAmount(), $movement->getBookingDate()) && ($mark === null || $mark === BankMovementMark::EXCEPTION) && $this->links()->findByMovements([$id]) === [];

        return max(0, $this->privateBy($pattern) - ($counted ? 1 : 0));
    }

    /** Whether decision 8 is still to be answered for $movement: linked, a private pattern takes it, no «Nein» stored. */
    public function isQuestionOpen(BankMovement $movement): bool
    {
        return $this->privatePatternOf($movement) !== null
            && $this->links()->findByMovements([(int) $movement->getId()]) !== []
            && $this->markOf($movement)?->getState() !== BankMovementMark::EXCEPTION;
    }

    /**
     * The questions of decision 8 that are open for a bank account: movements a document linked
     * although a private pattern takes them. Oldest first.
     *
     * @return list<array{movement: BankMovement, pattern: BankPattern, link: ?BankLink, others: int}>
     */
    public function openQuestions(int $bankAccountId): array
    {
        $out = [];
        foreach ($this->movements()->openPatternQuestions($bankAccountId) as $row) {
            $movement = $this->movements()->find($row['movement']);
            $pattern  = $this->patterns()->find($row['pattern']);
            if ($movement instanceof BankMovement && $pattern instanceof BankPattern) {
                $out[] = [
                    'movement' => $movement,
                    'pattern'  => $pattern,
                    'link'     => $this->links()->findByMovements([$row['movement']])[$row['movement']] ?? null,
                    'others'   => $this->othersOf($movement, $pattern),
                ];
            }
        }

        return $out;
    }

    /**
     * The question of decision 8 for $pattern with $others movements that are private by it —
     * reworded 2026-11-19 (owner: «es ist nicht klar, wozu man hier ja sagt»): it names what is
     * remembered and asks the fact; the answers say what they do ({@see ANSWER_YES} / {@see ANSWER_NO}).
     */
    public static function question(BankPattern $pattern, int $others): string
    {
        $name = '«' . $pattern->getLabel() . '»';

        return $name . ' ist als privat gemerkt'
            . ($others > 0 ? ' — ' . ($others === 1 ? '1 weitere Bewegung ist' : $others . ' weitere Bewegungen sind') . ' dadurch privat' : '')
            . '. Gibt es bei ' . $name . ' auch Geschäftliches?';
    }

    /** The answers of decision 8 as the screens write them — what each one DOES (owner 2026-11-19). */
    public const ANSWER_YES = 'Ja — jedes Mal prüfen';
    public const ANSWER_NO  = 'Nein — nur diese Bewegung';

    // ── the office decides ───────────────────────────────────────────────

    /**
     * «Privat» for movement $id — see the class docblock. $nearAmount / $nearDay narrow a pattern
     * confirmed NOW to the movement's approximate amount / its day of the month.
     *
     * @return array{pattern: ?BankPattern, others: int} the private pattern the movement now belongs to (null = by hand,
     *                                                   this movement only) and how many OTHER movements it makes private
     * @throws BankRefusedException LINK_GONE | STATE_BUSINESS | CLAIMED
     */
    public function markPrivate(int $movementId, bool $onlyThis = false, bool $nearAmount = false, bool $nearDay = false): array
    {
        try {
            return $this->markPrivateOnce($movementId, $onlyThis, $nearAmount, $nearDay);
        } catch (UniqueConstraintViolationException) {
            // Someone confirmed the same pattern on another movement at the same moment: a new unit of work finds it.
            return $this->markPrivateOnce($movementId, $onlyThis, $nearAmount, $nearDay);
        }
    }

    /** @return array{pattern: ?BankPattern, others: int} */
    private function markPrivateOnce(int $movementId, bool $onlyThis, bool $nearAmount, bool $nearDay): array
    {
        return $this->em->getTransaction(BankMovement::class)->run(function () use ($movementId, $onlyThis, $nearAmount, $nearDay): array {
            $movement = $this->lockedFree($movementId, 'privat');
            $claim    = $this->claimOn($movement);
            if ($claim !== null) {
                throw new BankRefusedException(BankRefusedException::CLAIMED, $claim);
            }
            $pattern  = $this->patternOf($movement);
            $now      = new \DateTimeImmutable();
            $byHand   = $onlyThis || $movement->getPatternKey() === '';
            if (!$byHand && $pattern === null) {
                $pattern = new BankPattern($movement->getBankAccount(), $movement->getDirection(), $movement->getPatternKey(), self::labelOf($movement), BankPattern::KIND_PRIVATE, $this->actor(), $now);
                $pattern->narrow($nearAmount ? $movement->getAmount() : null, $nearDay ? (int) $movement->getBookingDate()->format('j') : null);
                $this->em->persist($pattern);
            } elseif (!$byHand && $pattern->getKind() === BankPattern::KIND_BUSINESS) {
                // So far only a booking proposal: confirmed private now. What was booked stays business by its link.
                $pattern->decide(BankPattern::KIND_PRIVATE, $this->actor(), $now);
                $pattern->narrow($nearAmount ? $movement->getAmount() : null, $nearDay ? (int) $movement->getBookingDate()->format('j') : null);
                $this->em->persist($pattern);
            } elseif (!$byHand && (!$pattern->isPrivate() || !$pattern->takes($movement->getAmount(), $movement->getBookingDate()))) {
                $byHand = true;   // «gemischt», or a private pattern narrowed to other amounts / days
            }
            if ($byHand) {
                $this->mark($movement, BankMovementMark::PRIVATE, $now);

                return ['pattern' => null, 'others' => 0];
            }
            $this->unmark($movement);
            $this->em->flush();

            return ['pattern' => $pattern, 'others' => max(0, $this->privateBy($pattern) - 1)];
        });
    }

    /**
     * «Privat» for the ticked movements — each as {@see markPrivate()} without narrowing, in its
     * own unit of work, in id order; a movement that is business is left and counted, and so is one
     * another module claims (its sentence is kept, each once).
     *
     * @param list<int> $movementIds
     * @return array{private: int, refused: int, patterns: list<string>, claimed: int, claims: list<string>} how many are
     *         private now, how many were business, the labels of the patterns confirmed on the way, how many
     *         are claimed by a module and that module's sentences
     */
    public function markPrivateMany(array $movementIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $movementIds), static fn(int $id) => $id > 0)));
        sort($ids);
        $result = ['private' => 0, 'refused' => 0, 'patterns' => [], 'claimed' => 0, 'claims' => []];
        foreach ($ids as $id) {
            try {
                $done = $this->markPrivate($id);
                $result['private']++;
                if ($done['pattern'] !== null && !in_array($done['pattern']->getLabel(), $result['patterns'], true)) {
                    $result['patterns'][] = $done['pattern']->getLabel();
                }
            } catch (BankRefusedException $e) {
                if ($e->reason !== BankRefusedException::CLAIMED) {
                    $result['refused']++;
                    continue;
                }
                $result['claimed']++;
                if (!in_array($e->getMessage(), $result['claims'], true)) {
                    $result['claims'][] = $e->getMessage();
                }
            }
        }

        return $result;
    }

    /**
     * Movement $id back to «zu prüfen» — this one only: a mark by hand is removed, and where a
     * private pattern takes it, it is held.
     *
     * @throws BankRefusedException LINK_GONE | STATE_BUSINESS
     */
    public function setReview(int $movementId): void
    {
        $this->em->getTransaction(BankMovement::class)->run(function () use ($movementId): void {
            $movement = $this->lockedFree($movementId, 'auf «zu prüfen»');
            $this->held($movement);
        });
    }

    /**
     * The private pattern that takes movement $id is taken back: its movements are «zu prüfen»
     * again (marks by hand stay). A booking proposal it carries is kept.
     *
     * @return int how many movements went back to «zu prüfen»
     * @throws BankRefusedException LINK_GONE | NO_PATTERN
     */
    public function liftPattern(int $movementId): int
    {
        return $this->em->getTransaction(BankMovement::class)->run(function () use ($movementId): int {
            $movement = $this->locked($movementId);
            $pattern  = $this->privatePatternOf($movement);
            if ($pattern === null) {
                throw new BankRefusedException(BankRefusedException::NO_PATTERN, 'Für diese Bewegung gibt es kein privates Muster (mehr) — die Liste neu laden.');
            }
            $count = $this->privateBy($pattern);
            if ($pattern->getContraAccount() !== null) {
                $pattern->decide(BankPattern::KIND_BUSINESS, $this->actor(), new \DateTimeImmutable());
                $pattern->narrow(null, null);
                $this->em->persist($pattern);
            } else {
                $this->em->remove($pattern);
            }

            return $count;
        });
    }

    /**
     * The answer to the question of decision 8 (BankMovementStates::question()) for a LINKED movement a private
     * pattern takes (decision 8): $yes → the pattern is «gemischt»; no → the movement is an
     * exception, the pattern stays private.
     *
     * @throws BankRefusedException LINK_GONE | NO_PATTERN
     */
    public function answer(int $movementId, bool $yes): void
    {
        $this->em->getTransaction(BankMovement::class)->run(function () use ($movementId, $yes): void {
            $movement = $this->locked($movementId);
            $pattern  = $this->privatePatternOf($movement);
            if ($pattern === null || $this->links()->findByMovements([$movementId]) === []) {
                throw new BankRefusedException(BankRefusedException::NO_PATTERN, 'Die Frage ist bereits beantwortet — die Liste neu laden.');
            }
            $this->decideQuestion($movement, $pattern, $yes);
        });
    }

    // ── inside the unit of work of a posting ─────────────────────────────

    /**
     * The movement was booked as business with $contraAccount / $taxCode — inside the caller's
     * unit of work, the movement row locked: the proposal is learned on the pattern of its key,
     * and where a PRIVATE pattern takes the movement decision 8 must be answered ($answer true =
     * «Ja», false = «Nein»; null is refused with the question).
     *
     * @throws BankRefusedException PATTERN_QUESTION — nothing written, the caller's unit of work rolls back
     */
    public function booked(BankMovement $movement, string $contraAccount, ?string $taxCode, ?bool $answer): void
    {
        $this->assertUnitOfWork();
        $now     = new \DateTimeImmutable();
        $pattern = $this->patternOf($movement);
        $private = $pattern !== null && $pattern->isPrivate() && $pattern->takes($movement->getAmount(), $movement->getBookingDate());
        if ($private && $answer === null) {
            throw new BankRefusedException(BankRefusedException::PATTERN_QUESTION, self::question($pattern, $this->othersOf($movement, $pattern)));
        }
        if ($private) {
            $this->decideQuestion($movement, $pattern, $answer);
        }
        if (!$private || $answer) {
            $this->unmark($movement);   // booked: what was decided by hand before says nothing any more
        }
        if ($pattern === null && $movement->getPatternKey() !== '') {
            $pattern = new BankPattern($movement->getBankAccount(), $movement->getDirection(), $movement->getPatternKey(), self::labelOf($movement), BankPattern::KIND_BUSINESS, $this->actor(), $now);
        }
        if ($pattern !== null) {
            $pattern->propose($contraAccount, $taxCode);
            $this->em->persist($pattern);
        }
    }

    /**
     * The movement stands on «zu prüfen» from now on, whatever a pattern says — inside the
     * caller's unit of work (after «Buchung zurücknehmen …», decision 19).
     */
    public function held(BankMovement $movement): void
    {
        $this->assertUnitOfWork();
        if ($this->privatePatternOf($movement) !== null) {
            $this->mark($movement, BankMovementMark::REVIEW, new \DateTimeImmutable());
        } else {
            $this->unmark($movement);
        }
    }

    /** The sentence of the module that claims $movement — it cannot be set «privat» — or null. */
    public function claimOn(BankMovement $movement): ?string
    {
        $this->claims ??= BankMovementClaims::fromModules(DI::getModuleManager());

        return $this->claims->claimOn($movement, $this->em);
    }

    /** The words a pattern learned from $movement is named by: the counterparty, else the merchant, else the text. */
    public static function labelOf(BankMovement $movement): string
    {
        $merchant = BankPattern::merchantOf($movement->getText());

        return BankPattern::nameOf($movement->getCounterpartyName() !== '' ? $movement->getCounterpartyName() : ($merchant !== '' ? $merchant : $movement->getText()));
    }

    // ── internals ────────────────────────────────────────────────────────

    private function decideQuestion(BankMovement $movement, BankPattern $pattern, bool $yes): void
    {
        $now = new \DateTimeImmutable();
        if ($yes) {
            $pattern->decide(BankPattern::KIND_MIXED, $this->actor(), $now);
            $this->em->persist($pattern);
        } else {
            $this->mark($movement, BankMovementMark::EXCEPTION, $now);
        }
    }

    /** The movement, its row locked. @throws BankRefusedException LINK_GONE */
    private function locked(int $movementId): BankMovement
    {
        /** @var BankMovement|null $movement */
        $movement = $this->movements()->lockRow($movementId) ? $this->movements()->find($movementId) : null;
        if ($movement === null) {
            throw new BankRefusedException(BankRefusedException::LINK_GONE, 'Diese Bankbewegung gibt es nicht mehr — die Liste neu laden.');
        }

        return $movement;
    }

    /**
     * The movement, its row locked, bank-driven on its day (stage C4 — before «Abgleich ab», or with
     * the switch off, a movement is information only and nothing is decided about it) and nothing
     * linked to it.
     *
     * @throws BankRefusedException LINK_GONE | NOT_DRIVEN | STATE_BUSINESS
     */
    private function lockedFree(int $movementId, string $verb): BankMovement
    {
        $movement = $this->locked($movementId);
        if (!$movement->getBankAccount()->drives($movement->getBookingDate())) {
            throw BankRefusedException::notDriven($movement);
        }
        $link     = $this->links()->findByMovements([$movementId])[$movementId] ?? null;
        if ($link !== null) {
            throw new BankRefusedException(
                BankRefusedException::STATE_BUSINESS,
                'Die Bewegung vom ' . $movement->getBookingDate()->format('d.m.Y') . ' ist geschäftlich (' . $link->getOwnerLabel() . ') — sie lässt sich nicht ' . $verb
                . ' setzen. Zuerst die Buchung zurücknehmen oder die Zuordnung lösen.'
            );
        }

        return $movement;
    }

    private function markOf(BankMovement $movement): ?BankMovementMark
    {
        /** @var BankMovementMarkRepository $marks */
        $marks = $this->em->getRepository(BankMovementMark::class);

        return $marks->findByMovement((int) $movement->getId());
    }

    private function mark(BankMovement $movement, string $state, \DateTimeImmutable $now): void
    {
        $mark = $this->markOf($movement);
        if ($mark === null) {
            $mark = new BankMovementMark($movement, $state, $this->actor(), $now);
        } else {
            $mark->set($state, $this->actor(), $now);
        }
        $this->em->persist($mark);
    }

    private function unmark(BankMovement $movement): void
    {
        $mark = $this->markOf($movement);
        if ($mark !== null) {
            $this->em->remove($mark);
        }
    }

    private function assertUnitOfWork(): void
    {
        if (!$this->em->getTransaction(BankMovement::class)->isOpen()) {
            throw new \LogicException('BankMovementStates: booked() / held() run inside the unit of work of the posting');
        }
    }

    private function actor(): string
    {
        return $this->actor ??= Actor::current();
    }

    private function movements(): BankMovementRepository
    {
        return $this->em->getRepository(BankMovement::class);
    }

    private function patterns(): BankPatternRepository
    {
        return $this->em->getRepository(BankPattern::class);
    }

    private function links(): BankLinkRepository
    {
        return $this->em->getRepository(BankLink::class);
    }
}
