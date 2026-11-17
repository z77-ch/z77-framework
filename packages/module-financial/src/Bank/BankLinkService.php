<?php

namespace Z77\Module\Financial\Bank;

use Z77\Module\Financial\Entities\BankLink;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Module\Financial\Repositories\BankLinkRepository;
use Z77\Module\Financial\Repositories\BankMovementRepository;
use Z77\Module\Financial\Services\Actor;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The port of the modules that link a bank movement to one of their records (ADR-061 decisions
 * 9–11, stage C1) — module-agnostic (Rule 9): the owner is a key (`creditor:17`), financial names
 * no consumer and never resolves it.
 *
 *   - {@see freeMovements()} — the movements an owner may still take: without a link, booked on an
 *     ACTIVE bank account with «Kontoauszug einlesen» on, no reversal (`RvslInd` is undecided,
 *     ADR-061 consequences). WHICH of them fits is the owner's judgement (its certainty levels);
 *     {@see freeMovementsByEntryRefs()} — the same, by the bank's ENTRY reference (debtor, C3);
 *   - {@see link()} — sets the link INSIDE THE OWNER'S unit of work: the movement row is locked
 *     first, what is linked is read after the lock. Rule of stage C1: one link per movement over
 *     its full amount, one movement per owner — refused otherwise with a German sentence;
 *   - {@see release()} — removes the owner's link, inside the owner's unit of work, together with
 *     the owner's own correction (decision 10);
 *   - {@see relabel()}, {@see ofOwner()}, {@see ofOwners()}, {@see linkedOwners()},
 *     {@see ofMovements()} — the label kept current and the reads of the lists.
 *
 * A link never posts and changes nothing on the movement (the file's data is immutable).
 */
final class BankLinkService
{
    private ?string $actor;

    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null)
    {
        $this->actor = $actor !== null ? Actor::normalize($actor) : null;
    }

    /**
     * The free movements that match — every given criterion must hold; newest booking first.
     *
     * @param list<string>|null $references structured references (QRR / SCOR, as the bank sent them, compared exactly)
     * @param ?Money $amount                the booked amount in the account's currency
     * @param ?string $originalAmount       the instructed foreign amount (a decimal string) — with $originalCurrency
     * @return list<BankMovement>
     */
    public function freeMovements(
        string $direction,
        ?array $references = null,
        ?Money $amount = null,
        ?string $originalAmount = null,
        ?string $originalCurrency = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        int $limit = 20,
    ): array {
        if ($references === []) {
            return [];
        }

        return $this->movements()->findFree(
            $direction === BankMovement::CREDIT ? BankMovement::CREDIT : BankMovement::DEBIT,
            $references,
            $amount?->toDecimal(),
            $originalAmount !== null && $originalCurrency !== null ? $originalAmount : null,
            $originalAmount !== null && $originalCurrency !== null ? $originalCurrency : null,
            $from?->format('Y-m-d'),
            $to?->format('Y-m-d'),
            max(1, $limit),
        );
    }

    /**
     * The free movements of $direction carrying one of $entryRefs as their ENTRY reference
     * (`Ntry/AcctSvcrRef`) — the identity of an owner that knows the bank's entry reference
     * (debtor's camt.054 entry, ADR-061 decision 12, stage C3). Oldest booking first; several
     * movements with one reference are all returned (the owner treats that as not unique).
     *
     * @param list<string> $entryRefs
     * @return list<BankMovement>
     */
    public function freeMovementsByEntryRefs(string $direction, array $entryRefs): array
    {
        return $entryRefs === [] ? [] : $this->movements()->findFreeByEntryRefs($direction === BankMovement::CREDIT ? BankMovement::CREDIT : BankMovement::DEBIT, $entryRefs);
    }

    /** The free movement $id, or null when it is gone, linked, a reversal or on an account that takes no statements. */
    public function freeMovement(int $id): ?BankMovement
    {
        return $this->movements()->findFreeById($id);
    }

    /**
     * Link movement $movementId to the owner — inside the owner's open unit of work (its own row
     * is locked first; the movement row second).
     *
     * @param ?Money $amount what the link takes; null = the movement's full amount (the only amount accepted today)
     * @throws BankRefusedException LINK_GONE, LINK_TAKEN, LINK_OWNER, LINK_AMOUNT — German, nothing written
     * @throws \LogicException outside an open unit of work
     */
    public function link(int $movementId, string $ownerType, string $ownerKey, string $ownerLabel, string $basis, ?Money $amount = null): BankLink
    {
        $linked = $this->links()->lockMovement($movementId);
        /** @var BankMovement|null $movement */
        $movement = $linked === null ? null : $this->movements()->find($movementId);
        if ($movement === null) {
            throw new BankRefusedException(BankRefusedException::LINK_GONE, 'Diese Bankbewegung gibt es nicht mehr — die Liste neu laden.');
        }
        if ($linked !== []) {
            $other = $this->links()->findByOwner($linked[0])?->getOwnerLabel() ?? '';
            throw new BankRefusedException(
                BankRefusedException::LINK_TAKEN,
                'Die Bankbewegung vom ' . $movement->getBookingDate()->format('d.m.Y') . ' über ' . $movement->getAmount()->toDecimal() . ' ' . $movement->getCurrency()
                . ' ist bereits zugeordnet' . ($other !== '' ? ' (' . $other . ')' : '') . ' — eine Bewegung bezahlt genau eine Sache.'
            );
        }
        if ($this->links()->ownerLinked($ownerKey)) {
            throw new BankRefusedException(BankRefusedException::LINK_OWNER, $ownerLabel . ' hat bereits eine Bankbewegung — zuerst die Zuordnung lösen.');
        }
        $amount ??= $movement->getAmount();
        if (!$amount->equals($movement->getAmount())) {
            throw new BankRefusedException(
                BankRefusedException::LINK_AMOUNT,
                'Die Bankbewegung lautet auf ' . $movement->getAmount()->toDecimal() . ' ' . $movement->getCurrency() . ', zugeordnet werden sollen ' . $amount->toDecimal()
                . ' — eine Bewegung wird nur als Ganzes zugeordnet.'
            );
        }
        $link = new BankLink($movement, $ownerType, $ownerKey, $ownerLabel, $amount, $basis, $this->actor(), new \DateTimeImmutable());
        $this->em->persist($link);
        $this->em->flush();   // the row exists for every later read of this unit of work; a lost race meets the unique index here

        return $link;
    }

    /**
     * Remove the owner's link — inside the owner's unit of work, with the owner's own correction
     * (ADR-061 decision 10). Answers whether there was one.
     */
    public function release(string $ownerKey): bool
    {
        return $this->links()->deleteByOwner($ownerKey) > 0;
    }

    /** The owner's words changed — the list «Bewegungen» shows the new ones. Inside the owner's unit of work. */
    public function relabel(string $ownerKey, string $ownerLabel): void
    {
        $link = $this->links()->findByOwner($ownerKey);
        if ($link !== null) {
            $link->relabel($ownerLabel);
            $this->em->persist($link);
        }
    }

    public function ofOwner(string $ownerKey): ?BankLink
    {
        return $this->links()->findByOwner($ownerKey);
    }

    /**
     * @param list<string> $ownerKeys
     * @return array<string, BankLink>
     */
    public function ofOwners(array $ownerKeys): array
    {
        return $ownerKeys === [] ? [] : $this->links()->findByOwners($ownerKeys);
    }

    /**
     * Which of $ownerKeys are linked — asked in the database (not the identity map).
     *
     * @param list<string> $ownerKeys
     * @return array<string, true>
     */
    public function linkedOwners(array $ownerKeys): array
    {
        return $ownerKeys === [] ? [] : $this->links()->linkedOwners($ownerKeys);
    }

    /**
     * @param list<int> $movementIds
     * @return array<int, BankLink>
     */
    public function ofMovements(array $movementIds): array
    {
        return $movementIds === [] ? [] : $this->links()->findByMovements($movementIds);
    }

    /**
     * The distinct structured references of the free movements of $direction — what an owner
     * compares its own references with in ONE read (the identity match of a job).
     *
     * @return list<string>
     */
    public function freeReferences(string $direction, int $limit = 2000): array
    {
        return $this->movements()->freeReferences($direction === BankMovement::CREDIT ? BankMovement::CREDIT : BankMovement::DEBIT, max(1, $limit));
    }

    /** The booking date of the OLDEST free movement of $direction, or null when none is free. */
    public function firstFreeBooking(string $direction): ?\DateTimeImmutable
    {
        return $this->movements()->firstFreeBooking($direction === BankMovement::CREDIT ? BankMovement::CREDIT : BankMovement::DEBIT);
    }

    /** Whether any active bank account takes statements — without one no owner needs to look for a movement. */
    public function anyAccountReads(): bool
    {
        return $this->movements()->anyAccountReads();
    }

    private function actor(): string
    {
        return $this->actor ??= Actor::current();
    }

    private function links(): BankLinkRepository
    {
        return $this->em->getRepository(BankLink::class);
    }

    private function movements(): BankMovementRepository
    {
        return $this->em->getRepository(BankMovement::class);
    }
}
