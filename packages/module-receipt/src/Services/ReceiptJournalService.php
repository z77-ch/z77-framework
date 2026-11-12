<?php

namespace Z77\Module\Receipt\Services;

use Z77\Module\Financial\Services\Actor;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Receipt\Entities\ReceiptState;
use Z77\Module\Receipt\Repositories\ReceiptRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The receipt journal as the owner works it (owner decision E1, 2026-11-12): read the open
 * journal, CORRECT a captured receipt (date, amount, text, accounts, VAT decision), SPLIT a
 * mixed-VAT receipt into two (E12), DELETE one — each write with optimistic locking: the caller
 * names the `version` it showed, the row is locked and compared inside the unit of work, a mismatch
 * refuses with {@see ReceiptRefusedException::CHANGED}. A posted receipt is read-only here (a
 * correction after posting goes through the ledger — pending in `receipt.md`).
 *
 * Deleting a receipt KEEPS its photo in the DMS (decision of this build, open for the owner): the
 * DMS has no session-free delete, a receipt photo may be a document the books must keep, and the
 * photo of a wrong capture is cleaned up in the Drive. {@see delete()} answers the document id —
 * ONLY when no other receipt still names that photo (split parts share it, E12) — so a screen can
 * offer «Foto auch löschen» through `DocumentService::delete()` with the session, and never for a
 * photo another part still needs.
 *
 * Who: the actor's name (`changed_by`) is the caller's, or the session's ({@see Actor::current()},
 * resolved at the first write — reading needs no session).
 */
final class ReceiptJournalService
{
    private ?string $actor;

    /** @param string|null $actor the author's name; null = the session identity at the first write */
    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null)
    {
        $this->actor = $actor !== null ? Actor::normalize($actor) : null;
    }

    /**
     * The open journal — every captured receipt by date, then id; $until = dated on or before.
     *
     * @return list<Receipt>
     */
    public function pending(?\DateTimeImmutable $until = null): array
    {
        return $this->receipts()->pending($until);
    }

    public function find(int $id): ?Receipt
    {
        return $id > 0 ? $this->receipts()->find($id) : null;
    }

    /**
     * Correct a captured receipt.
     *
     * @throws InvalidReceiptException field → German message; nothing written
     * @throws ReceiptRefusedException CHANGED — gone, posted, or another version than $version
     */
    public function update(int $id, int $version, ReceiptCorrection $correction): Receipt
    {
        $errors = [];
        $fields = new ReceiptFields($this->em);
        $now    = new \DateTimeImmutable();
        $date   = $fields->date($correction->date, 'date', $errors, $now);
        $amount = $fields->amount($correction->amount, 'amount', $errors);
        $text   = $fields->text($correction->text, 'text', $errors);
        $debit  = $fields->account($correction->debitAccount, 'debit', $errors);
        $credit = $fields->account($correction->creditAccount, 'credit', $errors);
        $code   = $fields->taxCode($correction->taxCode, 'tax_code', $errors);
        if ($debit !== null && $debit === $credit) {
            $errors['credit'] = 'Soll und Haben sind dasselbe Konto.';
        }
        if (trim($correction->taxCode) !== '' && $correction->withoutVat) {
            $errors['tax_code'] = 'Ein MWST-Code oder «ohne MWST» — nicht beides.';
        }
        if ($errors !== [] || $date === null || $amount === null) {
            throw new InvalidReceiptException($errors);
        }
        $actor = $this->actor();

        return $this->em->getTransaction(Receipt::class)->run(function () use ($id, $version, $date, $amount, $text, $debit, $credit, $code, $correction, $actor, $now): Receipt {
            $receipt = $this->lockAt($id, $version);
            $receipt->correct($date, $amount, $text, $debit, $credit, $code, $correction->withoutVat, $actor, $now);
            $this->em->persist($receipt);

            return $receipt;
        });
    }

    /**
     * SPLIT a captured receipt (owner decision E12 — the phone captures ONE VAT code per receipt; a
     * rare mixed-VAT receipt is split here): the receipt keeps its amount minus $amountOfNewPart, a
     * NEW captured receipt carries $amountOfNewPart and shares the photo, date, text, payment
     * method (with its credit account) and capture data; its debit account and VAT decision are left
     * for the owner ({@see Receipt::splitOff()}). The two amounts sum to the original by
     * construction; the part must be positive and smaller than the receipt.
     *
     * @return array{0: Receipt, 1: Receipt} the original (new version) and the new part
     * @throws InvalidReceiptException amount — not an amount, not positive, not smaller than the receipt
     * @throws ReceiptRefusedException CHANGED — gone, posted, or another version than $version
     */
    public function split(int $id, int $version, string $amountOfNewPart): array
    {
        $errors = [];
        $part   = (new ReceiptFields($this->em))->amount($amountOfNewPart, 'amount', $errors);
        if ($part === null) {
            throw new InvalidReceiptException($errors);
        }
        $actor = $this->actor();

        return $this->em->getTransaction(Receipt::class)->run(function () use ($id, $version, $part, $actor): array {
            $receipt = $this->lockAt($id, $version);
            if (!$part->lessThan($receipt->getAmount())) {
                throw new InvalidReceiptException(['amount' => 'Der abgeteilte Betrag muss kleiner als der Belegbetrag (' . $receipt->getAmount()->toDecimal() . ') sein.']);
            }
            $new = $receipt->splitOff($part, $actor, new \DateTimeImmutable());
            $this->em->persist($receipt);
            $this->em->persist($new);

            return [$receipt, $new];
        });
    }

    /**
     * Delete a captured receipt from the journal. The photo stays in the DMS (see class doc).
     *
     * @return int|null the DMS document id of its photo when NO other receipt names it any more (a
     *                  screen may then offer «Foto auch löschen»); null while a split part still uses it
     * @throws ReceiptRefusedException CHANGED — gone, posted, or another version than $version
     */
    public function delete(int $id, int $version): ?int
    {
        $this->actor();   // a delete is a write: who does it must be known, as for a correction

        return $this->em->getTransaction(Receipt::class)->run(function () use ($id, $version): ?int {
            $receipt    = $this->lockAt($id, $version);
            $documentId = $receipt->getDocumentId();
            $shared     = $this->receipts()->countSharingDocument($documentId, $id) > 0;
            $this->em->remove($receipt);

            return $shared ? null : $documentId;
        });
    }

    /** The row locked, compared, then read fresh — inside the unit of work. */
    private function lockAt(int $id, int $version): Receipt
    {
        $row = $this->receipts()->lockIds([$id])[$id] ?? null;
        if ($row === null || $row['state'] !== ReceiptState::Captured->value || $row['version'] !== $version) {
            throw new ReceiptRefusedException(ReceiptRefusedException::CHANGED, 'Der Beleg wurde inzwischen geändert, verbucht oder gelöscht — nichts gespeichert. Das Belegjournal neu laden und die Änderung nochmals anbringen.');
        }

        return $this->receipts()->refreshed($id)
            ?? throw new ReceiptRefusedException(ReceiptRefusedException::CHANGED, 'Der Beleg ist nicht mehr da — das Belegjournal neu laden.');
    }

    private function actor(): string
    {
        return $this->actor ??= Actor::current();
    }

    private function receipts(): ReceiptRepository
    {
        return $this->em->getRepository(Receipt::class);
    }
}
