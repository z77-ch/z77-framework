<?php

namespace Z77\Module\Debtor\Services;

use Doctrine\ORM\OptimisticLockException;
use Z77\Module\Debtor\Accounting\AccountingGateway;
use Z77\Module\Debtor\Accounting\AccountingGateways;
use Z77\Module\Debtor\Entities\AllocationKind;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\Payment;
use Z77\Module\Debtor\Entities\PaymentAllocation;
use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Module\Debtor\Payments\PaymentDraft;
use Z77\Module\Debtor\Payments\PaymentPostingBuilder;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Module\Debtor\Repositories\PaymentAllocationRepository;
use Z77\Module\Debtor\Repositories\PaymentTargetRepository;
use Z77\Module\Mandator\Services\LedgerAccountCheck;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The ONE write path for payments, discount and loss (plan §6.3, P4
 * part 1; ADR-040 decision 3 applied to settlements): a {@see PaymentDraft}
 * becomes a {@see Payment} with its allocations on ONE final invoice, each
 * allocation POSTED through the accounting port in the same unit of work.
 * A settlement is a fact of the books the moment it is recorded — there is
 * no «unbooked payment» state, so the period close has nothing of ours to
 * ask (the CAMT import of part 2 keeps its own unbooked transactions and
 * registers a check for them).
 *
 * And — owner 2026-10-06 («manuelle Buchungen sind tippfehleranfällig; der
 * Sachbearbeiter korrigiert, ohne Stornobuchungen») — a settlement is
 * CORRECTED or REMOVED here while the fiscal year is open, and its postings
 * with it: {@see update()} revises the header and reshapes the allocations
 * (an allocation whose kind stays is AMENDED in place under its idempotency
 * key — the journal number stays; one that goes is RETRACTED; a new one is
 * posted), {@see delete()} retracts every posting and removes the rows. The
 * bookkeeping logs each change like a manual edit (ADR-042 addendum) and
 * refuses what its period no longer allows; the journal screen never edits
 * a generated entry. Both take the VERSION the caller saw.
 *
 * The rules, where they hold no matter which screen or import writes:
 *
 *   - the invoice is FINAL and an invoice (a credit note is not a
 *     receivable; it reduces one);
 *   - the draft names at least one amount > 0.00, none negative, in the
 *     invoice's currency; Σ payment + discount + loss ≤ the OPEN amount of
 *     the invoice as of the locked row (on an update: the open amount
 *     WITHOUT this payment's own allocations);
 *   - money that moved (payment > 0.00) names the LEDGER ACCOUNT it went
 *     to — the draft's `account` (bank, cash register, a clearing account:
 *     the office chooses), or the account of the named payment target; it
 *     must be one the bookkeeping takes (soft without financial);
 *   - the value date is not before the invoice date.
 *
 * Everything is decided BEFORE the unit of work except what needs the
 * lock: inside `run()` the invoice row is locked (`lockForUpdate`, the
 * `finalize()` lock order: rows before any journal number), the open amount
 * is read under that lock, the payment and its allocations are inserted and
 * FLUSHED (their ids are the idempotency keys `allocation:{id}`), then each
 * allocation is posted — a refusal of the ledger (period closed, account
 * unknown) rolls the whole settlement back, nothing half-written, no number
 * consumed.
 */
final class PaymentService
{
    private readonly string $actor;

    private ?AccountingGateway $gateway = null;

    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null)
    {
        $this->actor = $actor === null ? Actor::current() : Actor::normalize($actor);
    }

    /**
     * @throws PaymentRefusedException the draft is refused — nothing was written
     * @throws \Z77\Module\Debtor\Accounting\AccountingRefusedException the ledger refused a posting — rolled back whole
     */
    public function record(PaymentDraft $draft): Payment
    {
        $invoice = $this->checkedInvoice($draft);
        [$receivable, $counter, $account, $targetCode] = $this->accountsFor($draft);
        $gateway = $this->gateway();
        $now     = new \DateTimeImmutable();

        return $this->em->getTransaction(Payment::class)->run(function () use ($draft, $receivable, $counter, $account, $targetCode, $gateway, $now): Payment {
            $invoice = $this->lockedInvoice($draft, exceptPayment: null);

            $payment = new Payment($draft->date, $draft->payment, $targetCode, $account, $draft->note, $this->actor, $now, $draft->sourceType, $draft->sourceRef);
            foreach (self::amountsByKind($draft) as $kind => $amount) {
                if ($amount->isPositive()) {
                    $payment->allocate($invoice, AllocationKind::from($kind), $amount);
                }
            }
            $this->em->persist($payment);
            $this->em->flush();

            foreach ($payment->getAllocations() as $allocation) {
                $allocation->markPosted($gateway->post(PaymentPostingBuilder::build($allocation, $receivable, $counter[$allocation->kind()->value])));
                $this->em->persist($allocation);
            }

            return $payment;
        });
    }

    /**
     * Correct the settlement $paymentId as the caller saw it at
     * $expectedVersion: the header takes the draft's date, amounts, account
     * and note; the allocations are reshaped kind by kind — kept and
     * amended, withdrawn and retracted, added and posted. The invoice stays
     * the one of the payment (the draft's `invoiceId` must match).
     *
     * @throws PaymentRefusedException  NOT_FOUND | CONFLICT (another version) | the draft's refusals
     * @throws \Z77\Module\Debtor\Accounting\AccountingRefusedException the ledger refused — rolled back whole
     */
    public function update(int $paymentId, int $expectedVersion, PaymentDraft $draft): Payment
    {
        $existing = $this->em->getRepository(Payment::class)->find($paymentId);
        if ($existing === null) {
            throw new PaymentRefusedException(PaymentRefusedException::NOT_FOUND, "Zahlung {$paymentId} gibt es nicht.");
        }
        $invoice = $this->checkedInvoice($draft);
        if ($existing->getAllocations() !== [] && $existing->getAllocations()[0]->getInvoice()->getId() !== $invoice->getId()) {
            throw new PaymentRefusedException(PaymentRefusedException::NOT_FOUND, "Zahlung {$paymentId} gehört nicht zu " . $invoice->documentName() . '.');
        }
        [$receivable, $counter, $account, $targetCode] = $this->accountsFor($draft);
        $gateway = $this->gateway();
        $now     = new \DateTimeImmutable();

        return $this->guarded($paymentId, $expectedVersion, function () use ($paymentId, $expectedVersion, $draft, $receivable, $counter, $account, $targetCode, $gateway, $now): Payment {
            $invoice = $this->lockedInvoice($draft, exceptPayment: $paymentId);
            $payment = $this->freshPayment($paymentId, $expectedVersion);

            $payment->revise($draft->date, $draft->payment, $targetCode, $account, $draft->note, $this->actor, $now);
            $byKind = [];
            foreach ($payment->getAllocations() as $allocation) {
                $byKind[$allocation->kind()->value] = $allocation;
            }
            $toPost  = [];
            $toAmend = [];
            foreach (self::amountsByKind($draft) as $kind => $amount) {
                $allocation = $byKind[$kind] ?? null;
                if ($allocation !== null && $amount->isPositive()) {
                    $allocation->reallocate($amount);
                    $toAmend[] = $allocation;
                } elseif ($allocation !== null) {
                    $payment->withdraw($allocation);
                    $gateway->retract('allocation:' . $allocation->getId(), 'payment');
                    $this->em->remove($allocation);
                } elseif ($amount->isPositive()) {
                    $toPost[] = $payment->allocate($invoice, AllocationKind::from($kind), $amount);
                }
            }
            $this->em->persist($payment);
            $this->em->flush();

            // The date, the account or an amount may have changed: every kept allocation is amended (a no-op when nothing did).
            foreach ($toAmend as $allocation) {
                $gateway->amend(PaymentPostingBuilder::build($allocation, $receivable, $counter[$allocation->kind()->value]));
            }
            foreach ($toPost as $allocation) {
                $allocation->markPosted($gateway->post(PaymentPostingBuilder::build($allocation, $receivable, $counter[$allocation->kind()->value])));
                $this->em->persist($allocation);
            }

            return $payment;
        });
    }

    /**
     * Remove the settlement $paymentId as the caller saw it at
     * $expectedVersion — every posting retracted (its number stays a
     * documented gap in the journal), the rows gone, the invoice open again
     * by that amount.
     *
     * @throws PaymentRefusedException  NOT_FOUND | CONFLICT
     * @throws \Z77\Module\Debtor\Accounting\AccountingRefusedException the ledger refused — rolled back whole
     */
    public function delete(int $paymentId, int $expectedVersion): void
    {
        $gateway = $this->gateway();

        $this->guarded($paymentId, $expectedVersion, function () use ($paymentId, $expectedVersion, $gateway): void {
            $payment = $this->freshPayment($paymentId, $expectedVersion);
            foreach ($payment->getAllocations() as $allocation) {
                $this->invoices()->lockForUpdate((int) $allocation->getInvoice()->getId());
                $gateway->retract('allocation:' . $allocation->getId(), 'payment');
                $this->em->remove($allocation);
            }
            $this->em->remove($payment);
        });
    }

    /**
     * The allocations on $invoice with their payments, oldest first.
     *
     * @return list<PaymentAllocation>
     */
    public function allocationsOf(Invoice $invoice): array
    {
        /** @var PaymentAllocationRepository $allocations */
        $allocations = $this->em->getRepository(PaymentAllocation::class);

        return $allocations->forInvoice($invoice);
    }

    /** One settlement by id, or null. */
    public function find(int $paymentId): ?Payment
    {
        return $this->em->getRepository(Payment::class)->find($paymentId);
    }

    // ── the rules, before the unit of work ──────────────────────────────

    /** @return array{0: string, 1: array<string, string>, 2: string, 3: string} receivable, counter account per kind, the money's account, the target code */
    private function accountsFor(PaymentDraft $draft): array
    {
        $accounts   = new DebtorAccounts($this->em);
        $receivable = $accounts->postableNumber('receivable');
        $counter    = [];
        $account    = '';
        $targetCode = PaymentTarget::normalizeCode($draft->paymentTargetCode);
        if ($draft->payment->isPositive()) {
            $account = $this->moneyAccount($draft);
            $counter[AllocationKind::Payment->value] = $account;
        }
        if ($draft->discount->isPositive()) {
            $counter[AllocationKind::Discount->value] = $accounts->postableNumber('discount');
        }
        if ($draft->loss->isPositive()) {
            $counter[AllocationKind::Loss->value] = $accounts->postableNumber('loss');
        }

        return [$receivable, $counter, $account, $targetCode];
    }

    /** @return array<string, Money> kind value → amount */
    private static function amountsByKind(PaymentDraft $draft): array
    {
        return [
            AllocationKind::Payment->value  => $draft->payment,
            AllocationKind::Discount->value => $draft->discount,
            AllocationKind::Loss->value     => $draft->loss,
        ];
    }

    /** @throws PaymentRefusedException the invoice or the amounts are refused */
    private function checkedInvoice(PaymentDraft $draft): Invoice
    {
        $invoice = $this->invoices()->withLines($draft->invoiceId);
        if ($invoice === null) {
            throw new PaymentRefusedException(PaymentRefusedException::NOT_FOUND, "Dokument {$draft->invoiceId} gibt es nicht.");
        }
        $this->assertSettleable($invoice);
        $this->assertAmounts($draft, $invoice);
        if ($draft->date < $invoice->getInvoiceDate()) {
            throw new PaymentRefusedException(PaymentRefusedException::DATE, 'Das Valutadatum liegt vor dem Rechnungsdatum (' . $invoice->getInvoiceDate()->format('d.m.Y') . ').');
        }

        return $invoice;
    }

    /**
     * The invoice row LOCKED, and the draft checked against the open amount
     * under that lock — without the allocations of $exceptPayment (the one
     * being corrected).
     *
     * @throws PaymentRefusedException NOT_FOUND | OVER_ALLOCATION
     */
    private function lockedInvoice(PaymentDraft $draft, ?int $exceptPayment): Invoice
    {
        $invoice = $this->invoices()->lockForUpdate($draft->invoiceId);
        if ($invoice === null) {
            throw new PaymentRefusedException(PaymentRefusedException::NOT_FOUND, "Dokument {$draft->invoiceId} gibt es nicht.");
        }
        $this->assertSettleable($invoice);
        $open = (new InvoicingService($this->em, $this->actor))->openAmount($invoice);
        if ($exceptPayment !== null) {
            /** @var PaymentAllocationRepository $allocations */
            $allocations = $this->em->getRepository(PaymentAllocation::class);
            $open = $open->add(Money::fromDecimal($allocations->sumAllocatedBy($exceptPayment), $invoice->getCurrency()));
        }
        if ($draft->total()->greaterThan($open)) {
            throw new PaymentRefusedException(
                PaymentRefusedException::OVER_ALLOCATION,
                'Zahlung, Skonto und Verlust (' . $draft->total()->toDecimal() . ') übersteigen den offenen Betrag von ' . $invoice->documentName() . ' (' . $open->toDecimal() . ').'
            );
        }

        return $invoice;
    }

    /** @throws PaymentRefusedException NOT_FOUND | CONFLICT */
    private function freshPayment(int $paymentId, int $expectedVersion): Payment
    {
        $payment = $this->em->getRepository(Payment::class)->find($paymentId);
        if ($payment === null) {
            throw new PaymentRefusedException(PaymentRefusedException::NOT_FOUND, "Zahlung {$paymentId} gibt es nicht mehr.");
        }
        if ($payment->getVersion() !== $expectedVersion) {
            throw new PaymentRefusedException(PaymentRefusedException::CONFLICT, 'Die Zahlung wurde inzwischen geändert — bitte neu laden.');
        }

        return $payment;
    }

    /** Runs the unit of work; Doctrine's optimistic-lock failure at flush becomes the same refusal the version check raises. */
    private function guarded(int $paymentId, int $expectedVersion, callable $unitOfWork): mixed
    {
        try {
            return $this->em->getTransaction(Payment::class)->run($unitOfWork);
        } catch (OptimisticLockException) {
            throw new PaymentRefusedException(PaymentRefusedException::CONFLICT, 'Die Zahlung wurde inzwischen geändert — bitte neu laden.');
        }
    }

    /** @throws PaymentRefusedException NOT_FINAL | CREDIT_NOTE */
    private function assertSettleable(Invoice $invoice): void
    {
        if ($invoice->isCreditNote()) {
            throw new PaymentRefusedException(PaymentRefusedException::CREDIT_NOTE, $invoice->documentName() . ' ist eine Gutschrift — sie wird nicht bezahlt, sie reduziert eine Rechnung.');
        }
        if (!$invoice->isFinal()) {
            throw new PaymentRefusedException(PaymentRefusedException::NOT_FINAL, $invoice->documentName() . ' ist noch in Fakturierung — erst definitiv stellen, dann Zahlungen erfassen.');
        }
    }

    /** @throws PaymentRefusedException CURRENCY | AMOUNT | NOTHING | TARGET_REQUIRED */
    private function assertAmounts(PaymentDraft $draft, Invoice $invoice): void
    {
        foreach (['Zahlung' => $draft->payment, 'Skonto' => $draft->discount, 'Verlust' => $draft->loss] as $label => $amount) {
            if ($amount->currency !== $invoice->getCurrency()) {
                throw new PaymentRefusedException(PaymentRefusedException::CURRENCY, "{$label} in {$amount->currency} auf ein Dokument in {$invoice->getCurrency()}.");
            }
            if ($amount->isNegative()) {
                throw new PaymentRefusedException(PaymentRefusedException::AMOUNT, "{$label}: kein negativer Betrag — eine Falschzahlung wird korrigiert oder gelöscht.");
            }
        }
        if ($draft->total()->isZero()) {
            throw new PaymentRefusedException(PaymentRefusedException::NOTHING, 'Nichts zu erfassen — Zahlung, Skonto oder Verlust muss grösser als 0.00 sein.');
        }
        if ($draft->payment->isPositive() && trim($draft->account) === '' && PaymentTarget::normalizeCode($draft->paymentTargetCode) === '') {
            throw new PaymentRefusedException(PaymentRefusedException::TARGET_REQUIRED, 'Eine Zahlung braucht ein Konto — Bank, Kasse oder das Konto des Zahlungsziels.');
        }
    }

    /**
     * The ledger account the money went to: the draft's own account when
     * given (bank, cash register, a clearing account — the office chooses),
     * otherwise the account of the named payment target (active, with an
     * account). Either must be one the bookkeeping takes (unverified
     * without module-financial, the soft check).
     *
     * @throws PaymentRefusedException TARGET_UNKNOWN | TARGET_INACTIVE | TARGET_ACCOUNT
     */
    private function moneyAccount(PaymentDraft $draft): string
    {
        $number = trim($draft->account);
        $label  = 'Konto';
        if ($number === '') {
            /** @var PaymentTargetRepository $targets */
            $targets = $this->em->getRepository(PaymentTarget::class);
            $target  = $targets->findByCode($draft->paymentTargetCode);
            if ($target === null) {
                throw new PaymentRefusedException(PaymentRefusedException::TARGET_UNKNOWN, 'Zahlungsziel «' . $draft->paymentTargetCode . '» gibt es nicht.');
            }
            if (!$target->isActive()) {
                throw new PaymentRefusedException(PaymentRefusedException::TARGET_INACTIVE, 'Zahlungsziel «' . $target->getLabel() . '» ist inaktiv — kein neuer Zahlungseingang darauf.');
            }
            $number = trim($target->getAccountNumber());
            $label  = 'Konto des Zahlungsziels «' . $target->getLabel() . '»';
            if ($number === '') {
                throw new PaymentRefusedException(PaymentRefusedException::TARGET_ACCOUNT, 'Zahlungsziel «' . $target->getLabel() . '» hat kein Buchungskonto — beim Zahlungsziel hinterlegen oder das Konto hier wählen.');
            }
        }
        if (!preg_match('/^\d{1,10}$/', $number)) {
            throw new PaymentRefusedException(PaymentRefusedException::TARGET_ACCOUNT, $label . ': nur Ziffern (z.B. 1020).');
        }
        if ((new LedgerAccountCheck($this->em))->isPostable($number) === false) {
            throw new PaymentRefusedException(PaymentRefusedException::TARGET_ACCOUNT, $label . ' ' . $number . ' ist nicht buchbar (unbekannt, Gruppe oder inaktiv).');
        }

        return $number;
    }

    private function gateway(): AccountingGateway
    {
        return $this->gateway ??= AccountingGateways::fromConfig($this->em, $this->actor);
    }

    private function invoices(): InvoiceRepository
    {
        return $this->em->getRepository(Invoice::class);
    }
}
