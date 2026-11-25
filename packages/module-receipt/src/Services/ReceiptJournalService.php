<?php

namespace Z77\Module\Receipt\Services;

use Z77\Module\Financial\Bank\BankLinkService;
use Z77\Module\Financial\Bank\BankRefusedException;
use Z77\Module\Financial\Entities\BankLink;
use Z77\Module\Financial\Services\Actor;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Receipt\Entities\ReceiptState;
use Z77\Module\Receipt\Repositories\ReceiptRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The receipt journal as the owner works it (owner decision E1, 2026-11-12): read the open
 * journal, CORRECT a captured receipt (date, amount, text, accounts, VAT decision — a code, «ohne
 * MWST» or «nur Steuer», judged by `ReceiptFields::vatChoice()`; the accounts on the receipt's
 * date), SPLIT a
 * mixed-VAT receipt into two (E12), DELETE one — each write with optimistic locking: the caller
 * names the `version` it showed, the row is locked and compared inside the unit of work, a mismatch
 * refuses with {@see ReceiptRefusedException::CHANGED}. A posted receipt is read-only here (a
 * correction after posting goes through the ledger — pending in `receipt.md`).
 *
 * THE BANK MOVEMENT (ADR-061 stage C5): {@see takeMovement()} links a receipt — captured or posted
 * — to the bank movement that paid it, {@see releaseMovement()} takes the link back. While a
 * receipt is linked its amount and «Haben» are the bank's: a correction of either and a split are
 * refused («zuerst die Zuordnung lösen»); deleting the receipt releases the link in the same unit
 * of work (decision 10).
 *
 * Deleting a receipt does not touch the DMS inside its unit of work (a file write does not roll
 * back). {@see delete()} answers the photo's document id — ONLY when no other receipt still names
 * it (split parts share it, E12) — and the caller moves it to the Drive's trash AFTER the commit
 * through `ReceiptCaptureService::trashPhoto()` (owner 2026-11-20: the deleted record's document
 * goes to the trash, restorable; never a purge, never a photo another part still needs). Only a
 * CAPTURED receipt is deleted, so the photo has no retention deadline yet.
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
        // The accounts are judged on the RECEIPT's date (the lock of the VAT accounts holds from «Abrechnung ab» on);
        // «nur Steuer» has no Soll — a typed one is ignored and cleared, the posting derives it from the code (S56-2).
        $debit  = $correction->taxOnly ? null : $fields->account($correction->debitAccount, 'debit', $errors, $date);
        $credit = $fields->account($correction->creditAccount, 'credit', $errors, $date);
        $vat    = null;
        if ($debit !== null && $debit === $credit) {
            $errors['credit'] = 'Soll und Haben sind dasselbe Konto.';
        }
        if (trim($correction->taxCode) !== '' && $correction->withoutVat) {
            $errors['tax_code'] = 'Ein MWST-Code oder «ohne MWST» — nicht beides.';
        } else {
            // The ONE parser of the VAT decision (receipt, template, creditor line): a code, «ohne MWST», «nur Steuer».
            $vat = $fields->vatChoice(ReceiptFields::vatToken(trim($correction->taxCode), $correction->withoutVat, $correction->taxOnly), 'tax_code', $errors, $fields->liableToVat());
        }
        if ($errors !== [] || $date === null || $amount === null) {
            throw new InvalidReceiptException($errors);
        }
        $code       = $vat['code'] ?? null;
        $withoutVat = $vat['withoutVat'] ?? false;
        $taxOnly    = $vat['taxOnly'] ?? false;
        $actor      = $this->actor();

        return $this->em->getTransaction(Receipt::class)->run(function () use ($id, $version, $date, $amount, $text, $debit, $credit, $code, $withoutVat, $taxOnly, $actor, $now): Receipt {
            $receipt = $this->lockAt($id, $version);
            if (!$amount->equals($receipt->getAmount()) || $credit !== $receipt->getCreditAccount()) {
                $this->refuseLinked($receipt, 'Betrag und Haben kommen von der Bank');
            }
            $receipt->correct($date, $amount, $text, $debit, $credit, $code, $withoutVat, $actor, $now, $taxOnly);
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
            $this->refuseLinked($receipt, 'die Bewegung bezahlt den ganzen Beleg');
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
     * Delete a captured receipt from the journal. The DMS is not touched here (see class doc).
     *
     * @return int|null the DMS document id of its photo when NO other receipt names it any more (the
     *                  caller then moves it to the trash after the commit); null while a split part still uses it
     * @throws ReceiptRefusedException CHANGED — gone, posted, or another version than $version
     */
    public function delete(int $id, int $version): ?int
    {
        $this->actor();   // a delete is a write: who does it must be known, as for a correction

        return $this->em->getTransaction(Receipt::class)->run(function () use ($id, $version): ?int {
            $receipt    = $this->lockAt($id, $version);
            $documentId = $receipt->getDocumentId();
            $shared     = $this->receipts()->countSharingDocument($documentId, $id) > 0;
            // ADR-061 decision 10: the link goes with the receipt — the movement is «zu prüfen» again.
            (new BankLinkService($this->em, $this->actor))->release($receipt->bankOwnerKey());
            $this->em->remove($receipt);

            return $shared ? null : $documentId;
        });
    }

    /**
     * Take bank movement $movementId for the receipt (ADR-061 decisions 9, 11 — stage C5). ONE
     * unit of work, the receipt row locked first, the movement row second
     * (`BankLinkService::link()`): the link is stored with the movement's full amount. A POSTED
     * receipt is linked WITHOUT any change and without a posting (decision 2).
     *
     * The server judges the pair again, whatever the screen proposed: the movement must be free
     * (a debit, unlinked, on an account that takes statements) and booked on the bank account the
     * receipt was paid through (`ReceiptBankMatchService::bankAccountsOf()`); its amount must
     * equal the receipt's. The one exception: a CAPTURED receipt whose amount equals the
     * movement's ORIGINAL amount in a released foreign currency — the slip was in that currency;
     * the receipt's amount then becomes the CHF the bank debited (the effective rate; the
     * «Wechselkurs» of the entry text is never read).
     *
     * @param string $basis `BankLink::BASIS_PROPOSAL` / `BASIS_MANUAL` — how certain the caller is
     * @throws ReceiptRefusedException CHANGED (gone, another version) · BANK (German: why not)
     */
    public function takeMovement(int $id, int $version, int $movementId, string $basis): Receipt
    {
        $actor = $this->actor();
        $bank  = static fn(string $message): ReceiptRefusedException => new ReceiptRefusedException(ReceiptRefusedException::BANK, $message);
        try {
            return $this->em->getTransaction(Receipt::class)->run(function () use ($id, $version, $movementId, $basis, $actor, $bank): Receipt {
                $receipt  = $this->lockAny($id, $version);
                $name     = ReceiptPostingBuilder::name($receipt);
                $links    = new BankLinkService($this->em, $actor);
                $movement = $links->freeMovement($movementId);
                if ($movement === null || $movement->isCredit()) {
                    throw $bank('Diese Bankbewegung ist nicht mehr frei — sie ist inzwischen zugeordnet, oder ihr Bankkonto liest keine Kontoauszüge mehr ein. Bitte neu suchen.');
                }
                $chf  = $movement->getAmount();
                $date = $movement->getBookingDate()->format('d.m.Y');
                if (!in_array($movement->getBankAccount()->getCode(), (new ReceiptBankMatchService($this->em))->bankAccountsOf($receipt), true)) {
                    throw $bank($name . ' ist nicht über das Bankkonto «' . $movement->getBankAccount()->getLabel() . '» bezahlt — «Haben» des Belegs ist nicht das Konto dieses Bankkontos.');
                }
                $foreign = false;
                if (!$receipt->getAmount()->equals($chf)) {
                    $original = $movement->getOriginalAmount();
                    $currency = (string) $movement->getOriginalCurrency();
                    $foreign  = $receipt->isCaptured() && $original !== null && in_array($currency, ReceiptFields::foreignCurrencies(), true)
                        && Money::fromDecimal($original, $currency)->toDecimal() === $receipt->getAmount()->toDecimal();
                    if (!$foreign) {
                        throw $bank($name . ' lautet auf ' . $receipt->getAmount()->toDecimal() . ' ' . $chf->currency . ', die Bankbewegung vom ' . $date . ' auf ' . $chf->toDecimal()
                            . ($original !== null ? ' (' . $original . ' ' . $currency . ')' : '') . ' — die Beträge müssen gleich sein.');
                    }
                }
                $links->link($movementId, Receipt::SOURCE_TYPE, $receipt->bankOwnerKey(), $receipt->bankLabel(), in_array($basis, BankLink::BASES, true) ? $basis : BankLink::BASIS_MANUAL);
                if ($foreign) {
                    $receipt->amountFromBank($chf, $actor, new \DateTimeImmutable());
                    $this->em->persist($receipt);
                }

                return $receipt;
            });
        } catch (BankRefusedException $e) {
            throw $bank($e->getMessage());
        }
    }

    /**
     * «Zuordnung lösen» (ADR-061 decision 20, stage C5): the receipt's link to its bank movement
     * is removed — one unit of work; the movement is free («zu prüfen») again. The amount stays
     * as it is (a receipt that took the bank's CHF keeps it; it is an open field again). A POSTED
     * receipt is refused: its posting would have to be taken back first, and the correction of a
     * posted receipt is not built (receipt.md pending).
     *
     * @throws ReceiptRefusedException CHANGED (gone, another version) · BANK (no link, or posted)
     */
    public function releaseMovement(int $id, int $version): Receipt
    {
        $actor = $this->actor();

        return $this->em->getTransaction(Receipt::class)->run(function () use ($id, $version, $actor): Receipt {
            $receipt = $this->lockAny($id, $version);
            $name    = ReceiptPostingBuilder::name($receipt);
            $links   = new BankLinkService($this->em, $actor);
            if ($links->ofOwner($receipt->bankOwnerKey()) === null) {
                throw new ReceiptRefusedException(ReceiptRefusedException::BANK, $name . ' ist keiner Bankbewegung zugeordnet — nichts zu lösen.');
            }
            if ($receipt->isPosted()) {
                throw new ReceiptRefusedException(
                    ReceiptRefusedException::BANK,
                    $name . ' ist verbucht (Buchung ' . $receipt->getLedgerEntryRef() . ') — die Zuordnung zur Bankbewegung lässt sich erst lösen, wenn die Buchung zurückgenommen ist. Das Zurücknehmen ist noch nicht gebaut.'
                );
            }
            $links->release($receipt->bankOwnerKey());

            return $receipt;
        });
    }

    /** A receipt linked to a bank movement refuses what would contradict the movement — inside the unit of work, after the row lock. */
    private function refuseLinked(Receipt $receipt, string $why): void
    {
        $link = (new BankLinkService($this->em))->ofOwner($receipt->bankOwnerKey());
        if ($link !== null) {
            throw new ReceiptRefusedException(
                ReceiptRefusedException::BANK,
                ReceiptPostingBuilder::name($receipt) . ' ist der Bankbewegung vom ' . $link->getMovement()->getBookingDate()->format('d.m.Y') . ' zugeordnet — ' . $why . '. Zuerst im Beleg «Zuordnung lösen».'
            );
        }
    }

    /** The row locked and compared in ANY state (the bank link also concerns a posted receipt), then read fresh — inside the unit of work. */
    private function lockAny(int $id, int $version): Receipt
    {
        $row = $this->receipts()->lockIds([$id])[$id] ?? null;
        if ($row === null || $row['version'] !== $version) {
            throw new ReceiptRefusedException(ReceiptRefusedException::CHANGED, 'Der Beleg wurde inzwischen geändert oder gelöscht — nichts gespeichert. Das Belegjournal neu laden.');
        }

        return $this->receipts()->refreshed($id)
            ?? throw new ReceiptRefusedException(ReceiptRefusedException::CHANGED, 'Der Beleg ist nicht mehr da — das Belegjournal neu laden.');
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
