<?php

namespace Z77\Module\Debtor\Services;

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
 * The rules, where they hold no matter which screen or import writes:
 *
 *   - the invoice is FINAL and an invoice (a credit note is not a
 *     receivable; it reduces one — a credit note is «applied» by being
 *     final, nothing is allocated to it);
 *   - the draft names at least one amount > 0.00, none negative, in the
 *     invoice's currency; Σ payment + discount + loss ≤ the OPEN amount of
 *     the invoice as of the locked row (no overpayment in part 1 — a bank
 *     receipt above the open amount is part 2's unmatched remainder);
 *   - money that moved (payment > 0.00) names an ACTIVE payment target
 *     whose ledger account the bookkeeping will take; a pure write-off
 *     names none;
 *   - the value date is not before the invoice date.
 *
 * Everything is decided BEFORE the unit of work except what needs the
 * lock: inside `run()` the invoice row is locked (`lockForUpdate`, the
 * `finalize()` lock order: rows before any journal number), the open amount
 * is read under that lock, the payment and its allocations are inserted and
 * FLUSHED (their ids are the idempotency keys), then each allocation is
 * posted — a refusal of the ledger (period closed, account unknown) rolls
 * the whole settlement back, nothing half-written, no number consumed.
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
        $invoice = $this->invoices()->withLines($draft->invoiceId);
        if ($invoice === null) {
            throw new PaymentRefusedException(PaymentRefusedException::NOT_FOUND, "Dokument {$draft->invoiceId} gibt es nicht.");
        }
        $this->assertSettleable($invoice);
        $this->assertAmounts($draft, $invoice);
        if ($draft->date < $invoice->getInvoiceDate()) {
            throw new PaymentRefusedException(PaymentRefusedException::DATE, 'Das Valutadatum liegt vor dem Rechnungsdatum (' . $invoice->getInvoiceDate()->format('d.m.Y') . ').');
        }

        $accounts   = new DebtorAccounts($this->em);
        $receivable = $accounts->postableNumber('receivable');
        $counter    = [];
        if ($draft->payment->isPositive()) {
            $counter[AllocationKind::Payment->value] = $this->bankAccountOf($draft->paymentTargetCode);
        }
        if ($draft->discount->isPositive()) {
            $counter[AllocationKind::Discount->value] = $accounts->postableNumber('discount');
        }
        if ($draft->loss->isPositive()) {
            $counter[AllocationKind::Loss->value] = $accounts->postableNumber('loss');
        }
        $gateway = $this->gateway();
        $now     = new \DateTimeImmutable();

        return $this->em->getTransaction(Payment::class)->run(function () use ($draft, $receivable, $counter, $gateway, $now): Payment {
            // 1. The invoice row, locked — the open amount is read under this lock, before any journal number.
            $invoice = $this->invoices()->lockForUpdate($draft->invoiceId);
            if ($invoice === null) {
                throw new PaymentRefusedException(PaymentRefusedException::NOT_FOUND, "Dokument {$draft->invoiceId} gibt es nicht.");
            }
            $this->assertSettleable($invoice);
            $open = (new InvoicingService($this->em, $this->actor))->openAmount($invoice);
            if ($draft->total()->greaterThan($open)) {
                throw new PaymentRefusedException(
                    PaymentRefusedException::OVER_ALLOCATION,
                    'Zahlung, Skonto und Verlust (' . $draft->total()->toDecimal() . ') übersteigen den offenen Betrag von ' . $invoice->documentName() . ' (' . $open->toDecimal() . ').'
                );
            }

            // 2. The payment and its allocations, inserted — their ids are the idempotency keys.
            $payment = new Payment($draft->date, $draft->payment, $draft->paymentTargetCode, $draft->note, $this->actor, $now, $draft->sourceType, $draft->sourceRef);
            foreach ([AllocationKind::Payment, AllocationKind::Discount, AllocationKind::Loss] as $kind) {
                $amount = match ($kind) {
                    AllocationKind::Payment  => $draft->payment,
                    AllocationKind::Discount => $draft->discount,
                    AllocationKind::Loss     => $draft->loss,
                };
                if ($amount->isPositive()) {
                    $payment->allocate($invoice, $kind, $amount);
                }
            }
            $this->em->persist($payment);
            $this->em->flush();

            // 3. One posting per allocation; a refusal ends the unit of work.
            foreach ($payment->getAllocations() as $allocation) {
                $request = PaymentPostingBuilder::build($allocation, $receivable, $counter[$allocation->kind()->value]);
                $allocation->markPosted($gateway->post($request));
                $this->em->persist($allocation);
            }

            return $payment;
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
                throw new PaymentRefusedException(PaymentRefusedException::AMOUNT, "{$label}: kein negativer Betrag — eine Rückbuchung ist eine Gegenbuchung.");
            }
        }
        if ($draft->total()->isZero()) {
            throw new PaymentRefusedException(PaymentRefusedException::NOTHING, 'Nichts zu erfassen — Zahlung, Skonto oder Verlust muss grösser als 0.00 sein.');
        }
        if ($draft->payment->isPositive() && PaymentTarget::normalizeCode($draft->paymentTargetCode) === '') {
            throw new PaymentRefusedException(PaymentRefusedException::TARGET_REQUIRED, 'Eine Zahlung braucht ein Zahlungsziel — das Bankkonto, auf dem das Geld eingegangen ist.');
        }
    }

    /**
     * The ledger account of the payment target the money arrived on —
     * active, with an account the bookkeeping takes (unverified without
     * module-financial, the soft check).
     *
     * @throws PaymentRefusedException TARGET_UNKNOWN | TARGET_INACTIVE | TARGET_ACCOUNT
     */
    private function bankAccountOf(string $code): string
    {
        /** @var PaymentTargetRepository $targets */
        $targets = $this->em->getRepository(PaymentTarget::class);
        $target  = $targets->findByCode($code);
        if ($target === null) {
            throw new PaymentRefusedException(PaymentRefusedException::TARGET_UNKNOWN, 'Zahlungsziel «' . $code . '» gibt es nicht.');
        }
        if (!$target->isActive()) {
            throw new PaymentRefusedException(PaymentRefusedException::TARGET_INACTIVE, 'Zahlungsziel «' . $target->getLabel() . '» ist inaktiv — kein neuer Zahlungseingang darauf.');
        }
        $number = trim($target->getAccountNumber());
        if ($number === '') {
            throw new PaymentRefusedException(PaymentRefusedException::TARGET_ACCOUNT, 'Zahlungsziel «' . $target->getLabel() . '» hat kein Buchungskonto — beim Zahlungsziel hinterlegen.');
        }
        if ((new LedgerAccountCheck($this->em))->isPostable($number) === false) {
            throw new PaymentRefusedException(PaymentRefusedException::TARGET_ACCOUNT, 'Konto ' . $number . ' des Zahlungsziels «' . $target->getLabel() . '» ist nicht buchbar (unbekannt, Gruppe oder inaktiv).');
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
