<?php

namespace Z77\Module\Debtor\Services;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Z77\Module\Debtor\Entities\BankMessage;
use Z77\Module\Debtor\Entities\BankTransaction;
use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceKind;
use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Module\Debtor\Entities\TransactionState;
use Z77\Module\Debtor\Invoicing\QrReference;
use Z77\Module\Debtor\Payments\CamtEntry;
use Z77\Module\Debtor\Payments\CamtReader;
use Z77\Module\Debtor\Payments\PaymentDraft;
use Z77\Module\Debtor\Repositories\BankMessageRepository;
use Z77\Module\Debtor\Repositories\DebtorProfileRepository;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Module\Debtor\Repositories\PaymentTargetRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The CAMT.054 import (plan §6.4, P4 part 2): a bank's credit notification
 * becomes a {@see BankMessage} with its {@see BankTransaction}s, each
 * MATCHED to a final invoice where the file says which one, and later
 * BOOKED — a {@see \Z77\Module\Debtor\Entities\Payment} per transaction
 * through `PaymentService::record()`, the one write path for settlements,
 * all of a message in ONE unit of work.
 *
 * Steps, and the rules that hold no matter which screen or script calls:
 *
 *   - **import** — the file is read ({@see CamtReader}), the message id is
 *     the dedup key (the same message twice is refused and names the
 *     earlier import), the IBAN must be an ACTIVE payment target's (plain
 *     or QR-IBAN — that target's ledger account takes the money; an
 *     account the office does not keep here is refused), every entry is
 *     stored: a debit, a reversal, a credit in another currency goes in
 *     as `ignored` with the reason, so the file is complete on the screen;
 *     the bank's transaction reference is unique within the message (the
 *     second dedup key — a bank that lists one credit twice is caught);
 *   - **match** — a QRR reference names the debtor and the document:
 *     positions 11–16 the customer number, 17–26 the document number
 *     (`QrReference`, the wdv-630 layout); the invoice must exist, be
 *     FINAL, and belong to the debtor with that customer number — else the
 *     transaction stays `unmatched` with the reason. A NON / SCOR credit is
 *     matched by the document named in the message («Rechnung 12», the
 *     text the payment part prints) — the office confirms by booking;
 *   - **assign / ignore** — the office names the invoice for an unmatched
 *     credit (by document number, final invoices only), or sets a credit
 *     aside with a note; a booked transaction is never touched again (its
 *     payment is corrected instead);
 *   - **book** — every `matched` transaction of a message, in file order,
 *     becomes a payment on its invoice dated on the value date, on the
 *     message's payment target, `source_type` `camt` with
 *     `camt:{message id}:{tx ref}` as the reference: several credits for
 *     ONE invoice in one file are placed one after the other (the second
 *     sees the open amount the first left — what wdv missed); a credit
 *     ABOVE the open amount books the open amount and keeps the rest as
 *     the transaction's REMAINDER (an overpayment the office settles by
 *     hand — a refund, a transfer to another invoice); a credit on an
 *     invoice already settled books nothing and goes back to `unmatched`
 *     with the reason. One unit of work: a refusal of the ledger (period
 *     closed) rolls the whole booking back.
 *
 * Open work: `matched` and `unmatched` transactions dated in a fiscal year
 * BLOCK its close (`Close/UnbookedTransactionsCheck`, plan §5.3).
 */
final class BankImportService
{
    private readonly string $actor;

    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null)
    {
        $this->actor = $actor === null ? Actor::current() : Actor::normalize($actor);
    }

    /**
     * @throws BankImportRefusedException NOT_XML | NOT_CAMT054 | DUPLICATE_MESSAGE | TARGET_UNKNOWN
     */
    public function import(string $xml, string $fileName): BankMessage
    {
        $file = CamtReader::read($xml);
        $existing = $this->messages()->findByMessageId($file->messageId);
        if ($existing !== null) {
            throw new BankImportRefusedException(
                BankImportRefusedException::DUPLICATE_MESSAGE,
                'Die Meldung «' . $file->messageId . '» wurde bereits am ' . $existing->getImportedAt()->format('d.m.Y H:i') . ' importiert (' . $existing->getFileName() . ').'
            );
        }
        $target = $this->targetFor($file->iban);
        $now    = new \DateTimeImmutable();

        try {
            return $this->em->getTransaction(BankMessage::class)->run(function () use ($file, $target, $fileName, $now): BankMessage {
                $message = new BankMessage($file->messageId, $file->createdOn, $file->iban, $target->getCode(), $fileName, $this->actor, $now);
                $seen    = [];
                foreach ($file->entries as $i => $entry) {
                    $txRef = $entry->txRef;
                    if (isset($seen[$txRef])) {
                        $txRef .= '#' . ($i + 1);   // the bank listed one reference twice — kept apart, named below
                    }
                    $seen[$txRef] = true;
                    $transaction = new BankTransaction(
                        $message,
                        $i + 1,
                        $txRef,
                        $entry->valueDate,
                        $entry->bookingDate,
                        Money::fromDecimal($entry->amount, $entry->currency),
                        $entry->referenceType,
                        $entry->reference,
                        $entry->remittance,
                        $entry->debtorName,
                        $entry->debtorCity,
                    );
                    $message->add($transaction);
                    $this->classify($transaction, $entry, $txRef !== $entry->txRef);
                }
                $this->em->persist($message);

                return $message;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), BankMessage::UNIQUE_MESSAGE_ID)) {
                throw new BankImportRefusedException(BankImportRefusedException::DUPLICATE_MESSAGE, 'Die Meldung «' . $file->messageId . '» wurde soeben von jemand anderem importiert.', $e);
            }
            throw $e;
        }
    }

    /**
     * The office names the invoice (by document number) for a transaction
     * that is not booked.
     *
     * @throws BankImportRefusedException NOT_FOUND | STATE | INVOICE_UNKNOWN | INVOICE_NOT_FINAL
     */
    public function assign(int $transactionId, int $documentNumber): BankTransaction
    {
        $invoice = $this->invoices()->findByNumber(InvoiceKind::Invoice, $documentNumber);
        if ($invoice === null) {
            throw new BankImportRefusedException(BankImportRefusedException::INVOICE_UNKNOWN, "Rechnung {$documentNumber} gibt es nicht.");
        }
        if (!$invoice->isFinal()) {
            throw new BankImportRefusedException(BankImportRefusedException::INVOICE_NOT_FINAL, $invoice->documentName() . ' ist noch in Fakturierung — erst definitiv stellen.');
        }

        return $this->em->getTransaction(BankMessage::class)->run(function () use ($transactionId, $invoice): BankTransaction {
            $transaction = $this->openTransaction($transactionId);
            $transaction->matchTo($invoice, 'Manuell zugeordnet.');
            $this->em->persist($transaction);

            return $transaction;
        });
    }

    /**
     * Sets a transaction aside ($ignore) or takes it back into the open
     * work ($ignore false → unmatched).
     *
     * @throws BankImportRefusedException NOT_FOUND | STATE
     */
    public function ignore(int $transactionId, bool $ignore, ?string $note = null): BankTransaction
    {
        return $this->em->getTransaction(BankMessage::class)->run(function () use ($transactionId, $ignore, $note): BankTransaction {
            $transaction = $this->openTransaction($transactionId);
            $ignore ? $transaction->ignore($note ?? 'Vom Sachbearbeiter ignoriert.') : $transaction->unmatch($note);
            $this->em->persist($transaction);

            return $transaction;
        });
    }

    /**
     * Books every `matched` transaction of $messageId — a payment each,
     * all in ONE unit of work. Answers the message afresh.
     *
     * @throws BankImportRefusedException NOT_FOUND | NOTHING_TO_BOOK
     * @throws \Z77\Module\Debtor\Accounting\AccountingRefusedException the ledger refused — nothing booked
     * @throws PaymentRefusedException a settlement rule refused (a target without account …) — nothing booked
     */
    public function book(int $messageId): BankMessage
    {
        $message = $this->messages()->find($messageId);
        if ($message === null) {
            throw new BankImportRefusedException(BankImportRefusedException::NOT_FOUND, "Meldung {$messageId} gibt es nicht.");
        }
        $matched = array_values(array_filter($message->getTransactions(), static fn(BankTransaction $t) => $t->state() === TransactionState::Matched));
        if ($matched === []) {
            throw new BankImportRefusedException(BankImportRefusedException::NOTHING_TO_BOOK, 'Nichts zu verbuchen — keine zugeordnete Transaktion in dieser Meldung.');
        }
        $payments  = new PaymentService($this->em, $this->actor);
        $invoicing = new InvoicingService($this->em, $this->actor);

        return $this->em->getTransaction(BankMessage::class)->run(function () use ($message, $matched, $payments, $invoicing): BankMessage {
            foreach ($matched as $transaction) {
                $invoice = $transaction->getInvoice();
                $open    = $invoicing->openAmount($this->invoices()->lockForUpdate((int) $invoice->getId()));
                $credit  = $transaction->getAmount();
                if (!$open->isPositive()) {
                    $transaction->unmatch($invoice->documentName() . ' ist bereits beglichen — nichts gebucht, Betrag ' . $credit->toDecimal() . ' manuell behandeln.');
                    $this->em->persist($transaction);
                    continue;
                }
                if ($transaction->getValueDate() < $invoice->getInvoiceDate()) {
                    $transaction->unmatch('Valuta ' . $transaction->getValueDate()->format('d.m.Y') . ' liegt vor dem Rechnungsdatum von ' . $invoice->documentName() . ' — nicht gebucht.');
                    $this->em->persist($transaction);
                    continue;
                }
                $placed    = $credit->greaterThan($open) ? $open : $credit;
                $remainder = $credit->subtract($placed);
                $payment   = $payments->record(new PaymentDraft(
                    (int) $invoice->getId(),
                    $transaction->getValueDate(),
                    $placed,
                    Money::zero($credit->currency),
                    Money::zero($credit->currency),
                    $message->getPaymentTargetCode(),
                    mb_substr('CAMT ' . $message->getMessageId() . ($transaction->getDebtorName() !== '' ? ' · ' . $transaction->getDebtorName() : ''), 0, 140),
                    '',
                    'camt',
                    'camt:' . $message->getMessageId() . ':' . $transaction->getTxRef(),
                ));
                $transaction->booked(
                    $payment,
                    $remainder,
                    $remainder->isPositive() ? 'Überzahlung: ' . $remainder->toDecimal() . ' über dem offenen Betrag — manuell behandeln (Rückzahlung oder andere Rechnung).' : null
                );
                $this->em->persist($transaction);
            }

            return $message;
        });
    }

    public function find(int $messageId): ?BankMessage
    {
        return $this->messages()->find($messageId);
    }

    /** @return list<BankMessage> newest import first */
    public function recent(int $limit = 50): array
    {
        return $this->messages()->newestFirst($limit);
    }

    // ── matching ────────────────────────────────────────────────────────

    /** Sets the first state of a freshly read transaction: ignored with a reason, matched, or unmatched with a reason. */
    private function classify(BankTransaction $transaction, CamtEntry $entry, bool $duplicateRef): void
    {
        if (!$entry->isCredit) {
            $transaction->ignore('Belastung (DBIT) — kein Zahlungseingang.');

            return;
        }
        if ($entry->isReversal) {
            $transaction->ignore('Rückbuchung (RvslInd) — kein Zahlungseingang.');

            return;
        }
        if (!$transaction->getAmount()->isPositive()) {
            $transaction->ignore('Betrag 0.00 — nichts zu verbuchen.');

            return;
        }
        if ($duplicateRef) {
            $transaction->unmatch('Die Bank führt diese Transaktionsreferenz zweimal in der Meldung — prüfen, bevor sie zugeordnet wird.');

            return;
        }
        if ($transaction->getReferenceType() === BankTransaction::REFERENCE_QRR) {
            $this->matchByQrReference($transaction);

            return;
        }
        $this->matchByMessage($transaction);
    }

    private function matchByQrReference(BankTransaction $transaction): void
    {
        $reference = $transaction->getReference();
        if (!QrReference::isValid($reference)) {
            $transaction->unmatch('QR-Referenz ' . $reference . ' ist ungültig (Prüfziffer) — manuell zuordnen.');

            return;
        }
        $customer = (int) substr($reference, QrReference::BANK_DIGITS, QrReference::CUSTOMER_DIGITS);
        $number   = (int) substr($reference, QrReference::BANK_DIGITS + QrReference::CUSTOMER_DIGITS, QrReference::DOCUMENT_DIGITS);
        $invoice  = $this->invoices()->findByNumber(InvoiceKind::Invoice, $number);
        if ($invoice === null) {
            $transaction->unmatch("QR-Referenz nennt Rechnung {$number}, die es nicht gibt — manuell zuordnen.");

            return;
        }
        /** @var DebtorProfileRepository $profiles */
        $profiles = $this->em->getRepository(DebtorProfile::class);
        $profile  = $profiles->findByContact($invoice->getContact());
        if ($profile === null || $profile->getCustomerNumber() !== $customer) {
            $transaction->unmatch("QR-Referenz nennt Kundennummer {$customer}, Rechnung {$number} gehört aber zu " . ($profile?->getCustomerNumber() ?? '?') . ' — manuell zuordnen.');

            return;
        }
        if (!$invoice->isFinal()) {
            $transaction->unmatch($invoice->documentName() . ' ist noch in Fakturierung — erst definitiv stellen, dann zuordnen.');

            return;
        }
        $transaction->matchTo($invoice);
    }

    private function matchByMessage(BankTransaction $transaction): void
    {
        if (!preg_match('/Rechnung\s*(\d{1,10})\b/iu', $transaction->getRemittance(), $m)) {
            $transaction->unmatch('Keine QR-Referenz und keine Rechnungsnummer in der Mitteilung — manuell zuordnen.');

            return;
        }
        $number  = (int) $m[1];
        $invoice = $this->invoices()->findByNumber(InvoiceKind::Invoice, $number);
        if ($invoice === null) {
            $transaction->unmatch("Die Mitteilung nennt Rechnung {$number}, die es nicht gibt — manuell zuordnen.");

            return;
        }
        if (!$invoice->isFinal()) {
            $transaction->unmatch($invoice->documentName() . ' ist noch in Fakturierung — erst definitiv stellen, dann zuordnen.');

            return;
        }
        $transaction->matchTo($invoice, 'Zugeordnet nach der Mitteilung («Rechnung ' . $number . '») — vor dem Verbuchen prüfen.');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @throws BankImportRefusedException TARGET_UNKNOWN */
    private function targetFor(string $iban): PaymentTarget
    {
        /** @var PaymentTargetRepository $targets */
        $targets = $this->em->getRepository(PaymentTarget::class);
        foreach ($targets->allInOrder() as $target) {
            if ($target->isActive() && in_array($iban, [Iban::normalize($target->getIban()), Iban::normalize($target->getQrIban())], true) && $iban !== '') {
                return $target;
            }
        }
        throw new BankImportRefusedException(
            BankImportRefusedException::TARGET_UNKNOWN,
            'Das Konto ' . ($iban !== '' ? Iban::format($iban) : '(ohne IBAN)') . ' der Meldung ist kein aktives Zahlungsziel — zuerst unter Stammdaten › Aufträge › Zahlungsziele anlegen.'
        );
    }

    /** @throws BankImportRefusedException NOT_FOUND | STATE */
    private function openTransaction(int $transactionId): BankTransaction
    {
        $transaction = $this->em->getRepository(BankTransaction::class)->find($transactionId);
        if ($transaction === null) {
            throw new BankImportRefusedException(BankImportRefusedException::NOT_FOUND, "Transaktion {$transactionId} gibt es nicht.");
        }
        if ($transaction->state() === TransactionState::Booked) {
            throw new BankImportRefusedException(BankImportRefusedException::STATE, 'Die Transaktion ist verbucht — die Zahlung auf der Rechnung ändern oder löschen.');
        }

        return $transaction;
    }

    private function messages(): BankMessageRepository
    {
        return $this->em->getRepository(BankMessage::class);
    }

    private function invoices(): InvoiceRepository
    {
        return $this->em->getRepository(Invoice::class);
    }
}
