<?php

namespace Z77\Module\Order\Services;

use Z77\Module\Article\Entities\Price;
use Z77\Module\Article\Entities\Variant;
use Z77\Module\Article\Repositories\PriceRepository;
use Z77\Module\Article\Stock\StockConfirmation;
use Z77\Module\Article\Stock\StockLedger;
use Z77\Module\Article\Stock\StockLedgers;
use Z77\Module\Article\Stock\StockRequest;
use Z77\Module\Contact\Entities\Address;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Validators\AddressValidator;
use Z77\Module\Debtor\Entities\AddressSnapshot;
use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Entities\LineType;
use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Module\Debtor\Entities\PaymentTerms;
use Z77\Module\Debtor\Invoicing\SubjectLabel;
use Z77\Module\Debtor\Services\DebtorCurrency;
use Z77\Module\Debtor\Services\DebtorException;
use Z77\Module\Debtor\Services\DebtorProfileService;
use Z77\Module\Debtor\Services\InvoicingService;
use Z77\Module\Order\Entities\OrderPhase;
use Z77\Module\Order\Entities\OrderStatus;
use Z77\Module\Order\Entities\SalesOrder;
use Z77\Module\Order\Entities\SalesOrderLine;
use Z77\Module\Order\Entities\SalesOrderStatusChange;
use Z77\Module\Order\Invoicing\OrderInvoiceDraft;
use Z77\Module\Order\Repositories\OrderStatusRepository;
use Z77\Module\Order\Repositories\SalesOrderRepository;
use Z77\Module\Order\Stock\OrderStockTargets;
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Module\Vat\Calculation\VatCalculator;
use Z77\Module\Vat\Services\VatException;
use Z77\Module\Vat\Services\VatRates;
use Z77\Persistence\Doctrine\Entities\NumberRange;
use Z77\Persistence\Doctrine\Repositories\NumberRangeRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The ONE write path of an order (plan §5, owner O1–O5): {@see create()}, {@see update()},
 * {@see changeStatus()}, {@see printConfirmation()}, {@see printDeliveryNote()}, the booking print
 * {@see book()} (step 9). The behaviour comes from the PHASE of the status (ADR-043 addendum 2026-10-15).
 * A DTO in ({@see OrderDraft}), no `Request`, so the backend window, a later API or a subscription
 * run write the same way (§12). Every method that changes an existing order takes the VERSION its
 * caller saw; a stale one is {@see OrderConflictException}; a state that does not allow the write
 * is {@see OrderRefusedException} with a stable `reason`; a draft that breaks its rules is
 * {@see InvalidOrderException} with a German message per field.
 *
 * **Lock order** (plan §5, consistent with debtor's): `order` range (create only) → `sales_order`
 * rows EXCLUSIVE by id ({@see SalesOrderRepository::lockIds()}, the version compared there) →
 * [booking print only: debtor's chain] → the stock call → the order's own writes. No unit of work
 * reads anything plainly before its last row lock: what the draft needs (contact, articles,
 * prices, tax codes, statuses) is read and judged BEFORE the unit of work, and the order is
 * re-read REFRESHED after its lock. The line ids are the stock seam's line references, so a create
 * or an update flushes its rows (new rows — nothing another unit of work waits for) before the
 * stock call. A lock conflict that still happens propagates (debtor's `LockConflict::is()` tells
 * it apart for the screen: «nichts gespeichert, neu laden und nochmals versuchen»); no retry.
 *
 * **Stock** (plan §8.3): every write ends in ONE private {@see syncStock()} that sends the
 * COMPLETE target state of the order — every article line with reserved / consumed from the PHASE
 * of the status it is in NOW — through `StockLedger::bringTo()`, in the same unit of work. No
 * controller, repository or template calls stock. A `StockReasonRequiredException` (consumed
 * stock would come back) propagates and rolls everything back; the screen re-asks with a
 * mandatory reason and passes it as {@see StockConfirmation} — the reason lands in the status log.
 *
 * **Totals** (D11): computed ONCE, by debtor — after the lines are written, the order is mapped
 * ({@see OrderInvoiceDraft}) and `InvoicingService::preview()` answers net, tax and gross; stored
 * for the list only. A preview that refuses the order as it stands leaves the totals null.
 *
 * **Editable** (D7, read with ADR-054 — the Faktura-Journal) while the order has no invoice and its phase is not
 * locked (closed, cancelled) — OR while its invoice is ISSUED (in the Faktura-Journal, not posted): then a save
 * that makes the order deviate from its invoice FLAGS the invoice through debtor's seam (`flagReissue()`, owner
 * decision O9: ANY deviation) and the journal is blocked until {@see reinvoice()} («Neu fakturieren») re-issues it
 * — a revision under the same number or a replacement with a new one, debtor decides (ADR-054 decision 8). With a
 * POSTED invoice nothing by hand; a correction is a credit note.
 *
 * **Delete** ({@see delete()}, ADR-054 decision 14, owner decision O5 — replacing D8's «no delete»): an order
 * without a posted invoice is DELETED — kept as a row stamped «gelöscht» with who / when / why, its number kept;
 * its issued invoice goes `deleted` in debtor, its stock effects are brought to zero, all in one unit of work.
 */
final class OrderService
{
    /** Why an order totalling 0 is not booked, and what to do instead (owner 2026-10-20) — the service, the confirmation and the window say the same. */
    public const ZERO_TOTAL_HINT = 'Total 0 — keine Rechnung. Den Auftrag auf «Erledigt» setzen.';

    private readonly string $actor;

    public function __construct(
        private readonly UnifiedEntityManager $em,
        string $actor,
        private ?StockLedger $stock = null,
        private ?InvoicingService $invoicing = null,
    ) {
        $actor = trim($actor);
        if ($actor === '') {
            throw new \LogicException('An order is written by a named actor — pass the user name');
        }
        $this->actor = mb_substr($actor, 0, SalesOrder::ACTOR_LENGTH);
    }

    // ── writes ───────────────────────────────────────────────────────────

    /**
     * A new order (quote or order — the status says which): judged before the unit of work, then
     * the number drawn as the FIRST write, the order with its lines and the log row `create`
     * inserted, the stock call, the totals.
     *
     * @throws InvalidOrderException  the draft breaks its rules
     * @throws OrderRefusedException  NO_STATUS | STATUS_UNKNOWN | NOT_STARTABLE
     */
    public function create(OrderDraft $draft): SalesOrder
    {
        $statuses = $this->statuses();
        $start    = $this->startStatus($statuses, $draft->statusCode);
        $resolved = $this->resolve($draft, null);
        $now      = new \DateTimeImmutable();

        return $this->em->getTransaction(SalesOrder::class)->run(function () use ($draft, $start, $resolved, $now): SalesOrder {
            /** @var NumberRangeRepository $ranges */
            $ranges = $this->em->getRepository(NumberRange::class);
            $number = $ranges->next(SalesOrder::NUMBER_RANGE);   // the first write — the first lock

            $order = new SalesOrder($number, $resolved['contact'], $start->getCode(), $draft->sourceType, $draft->sourceRef, $this->actor, $now);
            $this->apply($order, $resolved);
            $this->writeTotals($order);
            $this->em->persist($order);
            $this->em->persist(new SalesOrderStatusChange($order, null, $start->getCode(), SalesOrderStatusChange::TRIGGER_CREATE, null, $this->actor, $now));
            $this->em->flush();   // the line ids — the stock seam's line references

            $this->syncStock($order, $start, 'create', null);
            $this->em->flush();   // written NOW — a caller that joins this unit of work reads the state and the version

            return $order;
        });
    }

    /**
     * Rewrites head and lines of an editable order (D7). Lines carrying an `id` are rewritten IN
     * PLACE (their identity is the stock reference), new ones inserted, the ones left out removed.
     *
     * $moveTo (owner 2026-10-20: the status is a field of the order window, saved with «Speichern»):
     * a status code other than the current one moves the order IN THE SAME unit of work, after the
     * rewrite — the rule of `changeStatus()` (refusal, log row `status`, the stock brought to the
     * new phase in ONE call). Refused or a missing reason → nothing is saved. null / the current
     * code = the status stays.
     *
     * @throws OrderConflictException the order is gone, its version moved on, or a line id is not the order's
     * @throws OrderRefusedException  NOT_EDITABLE | STATUS_UNKNOWN, with $moveTo also the refusals of `changeStatus()`
     * @throws InvalidOrderException
     * @throws \Z77\Module\Article\Stock\StockReasonRequiredException consumed stock would come back without $confirmation
     */
    public function update(int $id, int $version, OrderDraft $draft, ?StockConfirmation $confirmation = null, ?string $moveTo = null): SalesOrder
    {
        $statuses = $this->statuses();
        $current  = $this->orders()->find($id);   // the early judgement — judged again under the lock
        if ($current === null || $current->getVersion() !== $version) {
            throw new OrderConflictException($id, $version);
        }
        $this->assertEditable($current, $this->statusOf($current, $statuses));
        $to = $moveTo === null || $moveTo === $current->getStatusCode() ? null : ($statuses->byCode($moveTo)
            ?? throw new OrderRefusedException(OrderRefusedException::STATUS_UNKNOWN, 'Den Status «' . $moveTo . '» gibt es nicht.'));
        $resolved = $this->resolve($draft, $current);
        $now      = new \DateTimeImmutable();

        return $this->em->getTransaction(SalesOrder::class)->run(function () use ($id, $version, $statuses, $resolved, $confirmation, $now, $to): SalesOrder {
            $order  = $this->lockAt($id, $version);
            $status = $this->statusOf($order, $statuses);
            $this->assertEditable($order, $status);
            $refusal = $to !== null ? $statuses->refusal($status, $to, $order->getInvoice() !== null) : null;
            if ($refusal !== null) {
                throw new OrderRefusedException($refusal[0], $refusal[1]);
            }

            $this->apply($order, $resolved);
            $this->writeTotals($order);
            $order->touch($this->actor, $now);
            $this->em->flush();   // new lines get their ids

            if ($to !== null) {
                // The move brings the stock to the NEW phase — the rewritten lines included.
                $this->move($order, $status, $to, SalesOrderStatusChange::TRIGGER_STATUS, $confirmation, $now);
            } else {
                $this->syncStock($order, $status, 'lines', $confirmation);
                $this->em->flush();
            }
            // An order whose invoice is in the Faktura-Journal (ADR-054 decision 9): the deviation flagged on it.
            $this->flagInvoice($order);

            return $order;
        });
    }

    /**
     * «NEU FAKTURIEREN» (ADR-054 decision 12, owner decisions O9 / O11): the order's ISSUED invoice is issued
     * again from the order as it stands — debtor classifies (`InvoicingService::revise()`, decision 8): a
     * REVISION keeps the number (version + 1, a new archived PDF), a REPLACEMENT issues a successor with a new
     * number (its PDF prints «ersetzt Rechnung …») and the order is RE-LINKED to it; nothing to change clears
     * the invoice's flag only. The invoice date stays the issued invoice's (the month of the number). One unit
     * of work: the order row first, then debtor's chain (joined — the successor's month range prepared ahead,
     * DOCTRINE-NR-003), the log row `reinvoice` (an order in another closed status takes the default of `closed`, «Fakturiert», owner 2026-10-24). A refusal after debtor's chain rolls back
     * and removes the archive files written.
     *
     * @return \Z77\Module\Debtor\Entities\Invoice the invoice that stands now (the same or the successor)
     * @throws OrderConflictException the order or its invoice changed since the screen
     * @throws OrderRefusedException  DELETED | NOT_REINVOICEABLE | INVOICE_REFUSED
     */
    public function reinvoice(int $id, int $version): \Z77\Module\Debtor\Entities\Invoice
    {
        $order = $this->orders()->withLines($id);
        if ($order === null || $order->getVersion() !== $version) {
            throw new OrderConflictException($id, $version);
        }
        $invoice        = $this->reinvoiceable($order);
        $invoiceId      = (int) $invoice->getId();
        $invoiceVersion = $invoice->getVersion();
        $invoiceDate    = $invoice->getInvoiceDate();
        $before         = [$invoice->getRevision(), $invoice->getReissueNeeded()];
        $invoicing      = $this->invoicing();
        $invoicing->prepareDocumentRanges([$invoiceDate]);
        $invoiced = $this->statuses()->defaultOf(OrderPhase::Closed);   // «Fakturiert» — what a re-issue sets (owner 2026-10-24)
        $now      = new \DateTimeImmutable();

        try {
            return $this->em->getTransaction(SalesOrder::class)->run(function () use ($id, $version, $invoiceId, $invoiceVersion, $invoiceDate, $before, $invoicing, $invoiced, $now): \Z77\Module\Debtor\Entities\Invoice {
                $order   = $this->lockAt($id, $version);
                $invoice = $this->reinvoiceable($order);
                if ((int) $invoice->getId() !== $invoiceId) {
                    throw new OrderConflictException($id, $version);
                }
                $previous = $invoice->documentName();
                try {
                    $standing = $invoicing->revise($invoiceId, $invoiceVersion, OrderInvoiceDraft::of($order, $invoiceDate), null, 'Neu fakturieren aus Auftrag ' . $order->documentNumber(), fromSource: true);
                } catch (\Z77\Module\Debtor\Services\InvoiceConflictException) {
                    throw new OrderConflictException($id, $version);
                } catch (DebtorException | VatException $e) {
                    throw new OrderRefusedException(OrderRefusedException::INVOICE_REFUSED, 'Auftrag ' . $order->documentNumber() . ': ' . $e->getMessage(), $e);
                }
                $replaced = (int) $standing->getId() !== $invoiceId;
                if (!$replaced && $standing->getRevision() === $before[0] && $before[1] === null) {
                    return $standing;   // nothing to re-issue and no flag to clear — nothing written
                }
                if ($replaced) {
                    $order->relinkInvoice($standing);
                }
                // A re-issued invoice is «fakturiert»: an order that still stands in another closed status (invoiced
                // before 2026-10-24, when «Fakturieren» set «Gebucht») takes the default of `closed`. Same phase — no
                // stock moves.
                $fromCode = $order->getStatusCode();
                if ($invoiced !== null && $invoiced->getCode() !== $fromCode) {
                    $order->moveTo($invoiced->getCode());
                }
                $order->touch($this->actor, $now);
                $note = $replaced
                    ? $previous . ' ersetzt durch ' . $standing->documentName()
                    : ($standing->getRevision() !== $before[0] ? $standing->documentName() . ', Version ' . $standing->getRevision() : $standing->documentName() . ' unverändert');
                $this->em->persist(new SalesOrderStatusChange($order, $fromCode, $order->getStatusCode(), SalesOrderStatusChange::TRIGGER_REINVOICE, $note, $this->actor, $now));
                $this->em->flush();

                return $standing;
            });
        } catch (\Throwable $e) {
            $invoicing->discardArchived();   // nothing committed — no archive file may stay without its row
            throw $e;
        }
    }

    /**
     * What «Neu fakturieren» WOULD do with $order now — debtor's classification (none / revision /
     * replacement and why), for the confirmation window. Writes nothing.
     *
     * @throws OrderRefusedException DELETED | NOT_REINVOICEABLE | INVOICE_REFUSED (the order as it stands cannot be invoiced)
     */
    public function reinvoicePreview(SalesOrder $order): \Z77\Module\Debtor\Invoicing\Correction
    {
        $invoice = $this->reinvoiceable($order);
        try {
            return $this->invoicing()->classify($invoice, OrderInvoiceDraft::of($order, $invoice->getInvoiceDate()), fromSource: true);
        } catch (DebtorException | VatException $e) {
            throw new OrderRefusedException(OrderRefusedException::INVOICE_REFUSED, 'Auftrag ' . $order->documentNumber() . ': ' . $e->getMessage(), $e);
        }
    }

    /**
     * «AUFTRAG LÖSCHEN» (ADR-054 decision 14, owner decision O5): allowed while the order has no POSTED
     * invoice — none, or one still in the Faktura-Journal. ONE unit of work: the order row locked and judged
     * again → its issued invoice `deleted` in debtor (joined, the reason protocolled; refused there if it was
     * posted in between) → the order stamped deleted (kept, its number kept), the log row `delete` → the stock
     * brought to ZERO through the one stock call (consumed stock that comes back carries the deletion's reason
     * as its confirmation, ADR-043 decision 14). The reason is mandatory.
     *
     * @throws OrderConflictException the order is gone or its version moved on
     * @throws OrderRefusedException  REASON_REQUIRED | DELETED | NOT_DELETABLE | INVOICE_REFUSED
     */
    public function delete(int $id, int $version, string $reason): SalesOrder
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new OrderRefusedException(OrderRefusedException::REASON_REQUIRED, 'Ein Grund ist nötig — der gelöschte Auftrag bleibt mit seiner Nummer und dem Grund aufbewahrt.');
        }
        $order = $this->orders()->find($id);
        if ($order === null || $order->getVersion() !== $version) {
            throw new OrderConflictException($id, $version);
        }
        $this->assertDeletable($order);
        $statuses = $this->statuses();   // before the unit of work (the status file's upgrade writes a file)
        $now      = new \DateTimeImmutable();

        return $this->em->getTransaction(SalesOrder::class)->run(function () use ($id, $version, $reason, $statuses, $now): SalesOrder {
            $order = $this->lockAt($id, $version);
            $this->assertDeletable($order);
            $invoice = $order->getInvoice();
            if ($invoice !== null) {
                try {
                    $this->invoicing()->delete((int) $invoice->getId(), null, 'Auftrag ' . $order->documentNumber() . ' gelöscht: ' . $reason, fromSource: true);
                } catch (DebtorException $e) {
                    throw new OrderRefusedException(OrderRefusedException::NOT_DELETABLE, 'Auftrag ' . $order->documentNumber() . ': ' . $e->getMessage(), $e);
                }
            }
            $order->markDeleted($reason, $this->actor, $now);
            $order->touch($this->actor, $now);
            $this->em->persist(new SalesOrderStatusChange($order, $order->getStatusCode(), $order->getStatusCode(), SalesOrderStatusChange::TRIGGER_DELETE, $reason, $this->actor, $now));
            $this->em->flush();
            // The stock to ZERO (O5): the targets of a phase that neither reserves nor consumes. Consumed stock coming back
            // needs a reason (ADR-043 decision 14): the deletion's.
            $this->syncStock($order, $this->statusOf($order, $statuses), 'delete',
                new StockConfirmation(mb_substr('Auftrag ' . $order->documentNumber() . ' gelöscht: ' . $reason, 0, StockConfirmation::REASON_LENGTH)), OrderPhase::Cancelled);
            $this->em->flush();

            return $order;
        });
    }

    /** Why $order may not be deleted — null = it may (the order window offers «Auftrag löschen …» by it). */
    public function deleteRefusal(SalesOrder $order): ?string
    {
        try {
            $this->assertDeletable($order);
        } catch (OrderRefusedException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** @throws OrderRefusedException DELETED | NOT_DELETABLE */
    private function assertDeletable(SalesOrder $order): void
    {
        if ($order->isDeleted()) {
            throw new OrderRefusedException(OrderRefusedException::DELETED, 'Auftrag ' . $order->documentNumber() . ' ist bereits gelöscht.');
        }
        $invoice = $order->getInvoice();
        if ($invoice !== null && !$invoice->isInJournal()) {
            throw new OrderRefusedException(OrderRefusedException::NOT_DELETABLE, 'Zum Auftrag ' . $order->documentNumber() . ' ist ' . $invoice->documentName() . ' ' . $invoice->state()->label() . ' — der Auftrag wird nicht gelöscht' . ($invoice->isPosted() ? '; eine Korrektur ist eine Gutschrift auf der Rechnung.' : '.'));
        }
    }

    /**
     * The order's invoice when «Neu fakturieren» applies — issued, in the Faktura-Journal.
     *
     * @throws OrderRefusedException DELETED | NOT_REINVOICEABLE
     */
    private function reinvoiceable(SalesOrder $order): \Z77\Module\Debtor\Entities\Invoice
    {
        if ($order->isDeleted()) {
            throw new OrderRefusedException(OrderRefusedException::DELETED, 'Auftrag ' . $order->documentNumber() . ' ist gelöscht.');
        }
        $invoice = $order->getInvoice();
        if ($invoice === null || !$invoice->isInJournal()) {
            throw new OrderRefusedException(OrderRefusedException::NOT_REINVOICEABLE, $invoice === null
                ? 'Auftrag ' . $order->documentNumber() . ' ist noch nicht fakturiert — «Fakturieren …».'
                : 'Zum Auftrag ' . $order->documentNumber() . ' ist ' . $invoice->documentName() . ' ' . $invoice->state()->label() . ' — neu fakturiert wird nur eine Rechnung im Faktura-Journal (noch nicht verbucht).');
        }

        return $invoice;
    }

    /**
     * ADR-054 decision 9 / owner decision O9: after a save of an order whose invoice is ISSUED, the invoice is
     * flagged with the deviation (`InvoicingService::flagReissue()`) — or cleared when the order is back in
     * step. ANY deviation counts (debtor's classification of the order as it now stands against the issued
     * invoice: a revision or a replacement); an order that debtor would refuse as it stands is a deviation
     * too. Inside the caller's unit of work, after the order row (lock order order → invoice); an invoice
     * posted in between refuses the save.
     *
     * @throws OrderRefusedException NOT_EDITABLE — the invoice was posted since
     */
    private function flagInvoice(SalesOrder $order): void
    {
        $invoice = $order->getInvoice();
        if ($invoice === null || !$invoice->isInJournal()) {
            return;
        }
        try {
            $correction = $this->invoicing()->classify($invoice, OrderInvoiceDraft::of($order, $invoice->getInvoiceDate()), fromSource: true);
            $reason     = $correction->isNone() ? null : 'Auftrag ' . $order->documentNumber() . ' geändert — ' . $correction->summary() . ' (' . InvoicingService::correctionWord($correction) . ')';
        } catch (DebtorException | VatException $e) {
            $reason = 'Auftrag ' . $order->documentNumber() . ' geändert — so nicht fakturierbar: ' . $e->getMessage();
        }
        try {
            $this->invoicing()->flagReissue((int) $invoice->getId(), $reason);
        } catch (DebtorException $e) {
            throw new OrderRefusedException(OrderRefusedException::NOT_EDITABLE, 'Auftrag ' . $order->documentNumber() . ': ' . $e->getMessage() . ' Nichts gespeichert.', $e);
        }
    }

    /**
     * Moves an order to the status $toCode by hand (ADR-043 addendum 2026-10-15): a phase at or
     * after the current one always, backward too — all only while the order has NO invoice (owner
     * 2026-10-20: with a booked invoice the status stays; a correction is a credit note); never to
     * the default of `closed` (the booking print's alone); only to an ACTIVE status. Writes the log
     * row `status` (with the reversal reason, if any) and brings the stock to the new status'
     * targets. «Erledigt» is set this way, by hand, for an order that is never invoiced (owner Q6).
     *
     * @throws OrderConflictException
     * @throws OrderRefusedException  STATUS_UNKNOWN | SAME_STATUS | INACTIVE_TARGET | BOOKED_TARGET | INVOICED
     * @throws \Z77\Module\Article\Stock\StockReasonRequiredException consumed stock would come back without $confirmation
     */
    public function changeStatus(int $id, int $version, string $toCode, ?StockConfirmation $confirmation = null): SalesOrder
    {
        $statuses = $this->statuses();
        $to       = $statuses->byCode($toCode)
            ?? throw new OrderRefusedException(OrderRefusedException::STATUS_UNKNOWN, 'Den Status «' . $toCode . '» gibt es nicht.');
        $now = new \DateTimeImmutable();

        return $this->em->getTransaction(SalesOrder::class)->run(function () use ($id, $version, $statuses, $to, $confirmation, $now): SalesOrder {
            $order = $this->lockAt($id, $version);
            if ($order->isDeleted()) {
                throw new OrderRefusedException(OrderRefusedException::DELETED, 'Auftrag ' . $order->documentNumber() . ' ist gelöscht — der Status bleibt.');
            }
            $from  = $this->statusOf($order, $statuses);
            $refusal = $statuses->refusal($from, $to, $order->getInvoice() !== null);
            if ($refusal !== null) {
                throw new OrderRefusedException($refusal[0], $refusal[1]);
            }
            $this->move($order, $from, $to, SalesOrderStatusChange::TRIGGER_STATUS, $confirmation, $now);

            return $order;
        });
    }

    /**
     * The status side of the ORDER CONFIRMATION print (ADR-043 addendum 2026-10-15): an order in
     * phase `new` moves to the default of `confirmed` — log row `confirmation`, stock reserved. An
     * order already confirmed or further is answered as it is (a reprint). Refused for a cancelled
     * order.
     *
     * @throws OrderConflictException the order is gone, or it must move and its version moved on
     * @throws OrderRefusedException  STATUS_UNKNOWN | NOT_PRINTABLE
     * @throws \Z77\Module\Article\Stock\StockReasonRequiredException
     */
    public function printConfirmation(int $id, int $version, ?StockConfirmation $confirmation = null): SalesOrder
    {
        return $this->printMoving($id, $version, OrderPhase::Confirmed, SalesOrderStatusChange::TRIGGER_CONFIRMATION, $confirmation);
    }

    /**
     * The status side of the delivery-note print (plan D6, ADR-043 addendum 2026-10-15): a confirmed
     * order moves to the default of `delivered` — log row `delivery-note`, stock consumed. An order
     * already delivered or further is answered as it is (a reprint). Refused for a quote (phase
     * `new` — the order confirmation comes first) and for a cancelled order.
     *
     * @throws OrderConflictException the order is gone, or it must move and its version moved on
     * @throws OrderRefusedException  STATUS_UNKNOWN | NOT_PRINTABLE
     * @throws \Z77\Module\Article\Stock\StockReasonRequiredException
     */
    public function printDeliveryNote(int $id, int $version, ?StockConfirmation $confirmation = null): SalesOrder
    {
        return $this->printMoving($id, $version, OrderPhase::Delivered, SalesOrderStatusChange::TRIGGER_DELIVERY_NOTE, $confirmation);
    }

    /**
     * Whether the document that moves an order into $phase may be printed for it: never for a
     * cancelled order, the delivery note not for a quote. Pure — the screens ask it before they
     * offer the button.
     *
     * @throws OrderRefusedException NOT_PRINTABLE
     */
    public static function assertPrintable(SalesOrder $order, OrderStatus $status, OrderPhase $phase): void
    {
        $current = $status->getPhase();
        if ($order->isDeleted()) {
            throw new OrderRefusedException(OrderRefusedException::NOT_PRINTABLE, 'Auftrag ' . $order->documentNumber() . ' ist gelöscht — es wird nichts mehr gedruckt.');
        }
        if ($current === OrderPhase::Cancelled) {
            throw new OrderRefusedException(OrderRefusedException::NOT_PRINTABLE, 'Auftrag ' . $order->documentNumber() . ' ist storniert («' . $status->getLabel() . '») — es wird nichts mehr gedruckt.');
        }
        if ($phase === OrderPhase::Delivered && $current === OrderPhase::New) {
            throw new OrderRefusedException(OrderRefusedException::NOT_PRINTABLE, 'Auftrag ' . $order->documentNumber() . ' ist noch eine Offerte («' . $status->getLabel() . '») — zuerst die Auftragsbestätigung drucken oder den Status ändern.');
        }
    }

    /**
     * A print that moves the order FORWARD into $phase (its default status) — or nothing, when the
     * order is in or past it: then nothing is written and no version is needed (a double click
     * prints twice and moves once).
     */
    private function printMoving(int $id, int $version, OrderPhase $phase, string $trigger, ?StockConfirmation $confirmation): SalesOrder
    {
        $statuses = $this->statuses();
        $now      = new \DateTimeImmutable();

        return $this->em->getTransaction(SalesOrder::class)->run(function () use ($id, $version, $statuses, $phase, $trigger, $confirmation, $now): SalesOrder {
            $versions = $this->orders()->lockIds([$id]);
            if (!isset($versions[$id])) {
                throw new OrderConflictException($id, $version);
            }
            $order = $this->orders()->withLines($id) ?? throw new OrderConflictException($id, $version);
            $from  = $this->statusOf($order, $statuses);
            self::assertPrintable($order, $from, $phase);
            $target = $statuses->actionTarget($from, $phase);
            if ($target === null) {
                return $order;   // in or past the phase — a reprint, nothing to move
            }
            if ($versions[$id] !== $version) {
                throw new OrderConflictException($id, $version);
            }
            if ($phase === OrderPhase::Delivered && $order->getServiceFrom() === null) {
                $order->deliveredOn($now);   // the delivery date = the service date (owner 2026-10-16)
                $this->writeTotals($order);  // the VAT rate follows the service date
            }
            $this->move($order, $from, $target, $trigger, $confirmation, $now);

            return $order;
        });
    }

    /**
     * «FAKTURIEREN» of one or several orders (plan §7, D4, step 9; the booking print, «Buchungsdruck»,
     * until 2026-10-23 — ADR-054): each order's invoice is issued by debtor
     * (`InvoicingService::invoiceAndIssue()` — numbered, stamped, its PDF archived, NOT posted: it
     * enters the Faktura-Journal), linked to the order (D5), and the order moves to the default
     * status of phase `closed` — ONE unit of work, all or nothing. The posting follows with
     * «Journal verbuchen» in debtor.
     *
     *   1. Before the unit of work, at the versions the screen showed: every order exists, has no
     *      invoice and sits in a bookable phase (`confirmed`, `delivered`); the debtor profile of each customer is
     *      opened if it has none (D9 — its own unit of work, `openDebtorProfile()`); each order's
     *      invoice is previewed (`preview()`) so a refusal names THE ORDER («Auftrag 12: …»), and
     *      an order totalling 0 is refused (`zero-total` — no invoice, it is closed by «Erledigt»,
     *      owner 2026-10-20) and a payment part that could not be printed is refused the same way; the month range of
     *      the invoice date is created ahead (`prepareDocumentRanges()`, DOCTRINE-NR-003).
     *   2. The unit of work, in the lock order of plan §5: the `sales_order` rows locked by id
     *      ({@see SalesOrderRepository::lockIds()}), the versions compared there, the orders re-read
     *      refreshed and judged again → debtor's chain (`invoiceAndIssue()` joins: month range,
     *      profile rows, compose, numbers, stamps, archive) → per order `linkInvoice()`, the status,
     *      the log row `booking-print`, the stock call. A refusal after debtor's chain (the stock
     *      call) rolls everything back; the archive files debtor wrote are removed
     *      (`InvoicingService::discardArchived()`).
     *
     * Idempotency is the SCREEN's (plan §7): this method refuses an order that already has an
     * invoice (`already-invoiced`); a repeated submit of the same selection is recognised by the
     * screen and answered with the same PDF without calling this.
     *
     * @param list<array{id: int, version: int}> $orders the orders as the screen showed them
     * @return list<\Z77\Module\Debtor\Entities\Invoice> the issued invoices, in number order
     * @throws OrderConflictException an order is gone or its version moved on
     * @throws OrderRefusedException  NOTHING_SELECTED | NO_BOOKED_STATUS | STATUS_UNKNOWN | ALREADY_INVOICED | NOT_INVOICEABLE | INVOICE_REFUSED | ZERO_TOTAL
     * @throws \Z77\Module\Article\Stock\StockReasonRequiredException
     */
    public function book(array $orders, \DateTimeImmutable $invoiceDate, ?StockConfirmation $confirmation = null): array
    {
        $versions = [];
        foreach ($orders as $pair) {
            $versions[(int) $pair['id']] = (int) $pair['version'];
        }
        if ($versions === []) {
            throw new OrderRefusedException(OrderRefusedException::NOTHING_SELECTED, 'Kein Auftrag ausgewählt — nichts wurde fakturiert.');
        }
        ksort($versions);
        $statuses = $this->statuses();
        $booked   = $statuses->defaultOf(OrderPhase::Closed)
            ?? throw new OrderRefusedException(OrderRefusedException::NO_BOOKED_STATUS, 'Die Phase «Abgeschlossen» hat keinen aktiven Auftragsstatus — unter Stammdaten › Aufträge › Auftragsstatus einen anlegen (z.B. «Fakturiert») und «Wird gesetzt beim Fakturieren» einschalten. Nichts wurde fakturiert.');
        $invoiceDate = $invoiceDate->setTime(0, 0);
        $invoicing   = $this->invoicing();

        // 1. Before the unit of work — judged as the screen showed the orders.
        $contacts = [];
        foreach ($versions as $id => $version) {
            $order = $this->orders()->find($id);
            if ($order === null || $order->getVersion() !== $version) {
                throw new OrderConflictException($id, $version);
            }
            $this->assertBookable($order, $this->statusOf($order, $statuses));
            $contacts[(int) $order->getContact()->getId()] = $order;
        }
        foreach ($contacts as $contactId => $order) {
            try {
                $invoicing->openDebtorProfile($contactId);   // D9: its own unit of work, BEFORE the booking
            } catch (DebtorException $e) {
                throw new OrderRefusedException(OrderRefusedException::INVOICE_REFUSED, 'Auftrag ' . $order->documentNumber() . ': ' . $e->getMessage(), $e);
            }
        }
        foreach ($versions as $id => $version) {
            $order = $this->orders()->withLines($id) ?? throw new OrderConflictException($id, $version);
            try {
                $preview = $invoicing->preview(OrderInvoiceDraft::of($order, $invoiceDate));
            } catch (DebtorException | VatException $e) {
                throw new OrderRefusedException(OrderRefusedException::INVOICE_REFUSED, 'Auftrag ' . $order->documentNumber() . ': ' . $e->getMessage(), $e);
            }
            // A total of 0 is no invoice (owner 2026-10-20) — refused BEFORE the payment part, whose
            // «amount below 0.01» would point at the payment target instead of the cause.
            if ($preview->getGrossTotal()->isZero()) {
                throw new OrderRefusedException(OrderRefusedException::ZERO_TOTAL, 'Auftrag ' . $order->documentNumber() . ': ' . self::ZERO_TOTAL_HINT);
            }
            $problems = $preview->getPayment()->hasPaymentPart() ? $invoicing->bookingBill($preview)->problems() : [];
            if ($problems !== []) {
                throw new OrderRefusedException(OrderRefusedException::INVOICE_REFUSED, 'Auftrag ' . $order->documentNumber() . ': der Zahlteil (QR-Rechnung) liesse sich nicht drucken — ' . implode(' ', $problems) . ' Beim Zahlungsziel oder beim Mandanten beheben, oder im Auftrag «kein Zahlteil» wählen.');
            }
        }
        $invoicing->prepareDocumentRanges([$invoiceDate]);
        $now = new \DateTimeImmutable();

        // 2. The unit of work — order rows first, then debtor's chain, then the order's own writes.
        try {
            return $this->em->getTransaction(SalesOrder::class)->run(function () use ($versions, $statuses, $booked, $invoiceDate, $invoicing, $confirmation, $now): array {
                $locked = $this->orders()->lockIds(array_keys($versions));
                foreach ($versions as $id => $version) {
                    if (($locked[$id] ?? null) !== $version) {
                        throw new OrderConflictException($id, $version);
                    }
                }
                $byNumber = [];
                $drafts   = [];
                foreach (array_keys($versions) as $id) {
                    $order = $this->orders()->withLines($id) ?? throw new OrderConflictException($id, $versions[$id]);
                    $from  = $this->statusOf($order, $statuses);
                    $this->assertBookable($order, $from);
                    $byNumber[$order->documentNumber()] = [$order, $from];
                    $drafts[] = OrderInvoiceDraft::of($order, $invoiceDate);
                }
                try {
                    $invoices = $invoicing->invoiceAndIssue($drafts);
                } catch (DebtorException | VatException $e) {
                    $names = array_keys($byNumber);
                    throw new OrderRefusedException(OrderRefusedException::INVOICE_REFUSED, (count($names) === 1 ? 'Auftrag ' : 'Aufträge ') . implode(', ', $names) . ': ' . $e->getMessage(), $e);
                }
                foreach ($invoices as $invoice) {
                    [$order, $from] = $byNumber[(string) $invoice->getSourceRef()]
                        ?? throw new \LogicException('The issued invoice ' . $invoice->getId() . ' names no order of this «Fakturieren»');
                    $order->linkInvoice($invoice);
                    $this->move($order, $from, $booked, SalesOrderStatusChange::TRIGGER_BOOKING_PRINT, $confirmation, $now);
                }

                return $invoices;
            });
        } catch (\Throwable $e) {
            $invoicing->discardArchived();   // nothing committed — no archive file may stay without its row
            throw $e;
        }
    }

    // ── reads the screens need ───────────────────────────────────────────

    /**
     * The order as debtor would invoice it on $invoiceDate (default: the order date — the date the
     * stored totals use): a TRANSIENT invoice from `InvoicingService::preview()` with lines,
     * amounts, VAT per code, the rounding and the payment part (D11 — the one computation). A
     * customer without a debtor profile is composed against the default terms (D9). Writes
     * nothing; never persist the result.
     *
     * @throws DebtorException|VatException the order as it stands would be refused at the booking print
     */
    public function preview(SalesOrder $order, ?\DateTimeImmutable $invoiceDate = null): \Z77\Module\Debtor\Entities\Invoice
    {
        return $this->invoicing()->preview(OrderInvoiceDraft::of($order, $invoiceDate ?? $order->getOrderDate()), openProfile: true);
    }

    /**
     * Where the delivery note goes: the order's delivery address, else its billing address, else
     * the customer's invoice address as debtor chooses it (`InvoicingService::invoiceAddressOf()` —
     * one rule, Rule 2); null when the customer has no address at all.
     */
    public function deliveryAddressOf(SalesOrder $order): ?\Z77\Module\Debtor\Entities\AddressSnapshot
    {
        return $order->deliveryAddress() ?? $order->billingAddress() ?? $this->invoicing()->invoiceAddressOf($order->getContact());
    }

    /**
     * The status rules over the master data as it is now — after the one-time data upgrade of the
     * status file ({@see OrderStatusUpgrade}, owner 2026-10-24). Never called inside a unit of work.
     */
    public function statuses(): OrderStatuses
    {
        OrderStatusUpgrade::apply($this->em);
        /** @var OrderStatusRepository $repo */
        $repo = $this->em->getRepository(OrderStatus::class);

        return new OrderStatuses($repo->allInOrder());
    }

    /**
     * Whether the order's documents carry VAT — debtor's ONE rule (`InvoicingService::chargesVat()`,
     * owner 2026-10-21: the mandator's «mehrwertsteuerpflichtig»). The window shows or hides the
     * MWST column and the VAT rows by it; the preview, the totals, the PDFs and the booking print
     * follow it through debtor's computation (D11). Not liable: the price modes are equal too —
     * an article price is taken as it is, never converted with a rate ({@see convertByRate()}).
     */
    public function chargesVat(): bool
    {
        return $this->invoicing()->chargesVat();
    }

    /** Whether the order may still be edited (D7) — null = yes, else the German reason. */
    public function editRefusal(SalesOrder $order): ?string
    {
        try {
            $this->assertEditable($order, $this->statusOf($order, $this->statuses()));
        } catch (OrderRefusedException $e) {
            return $e->getMessage();
        }

        return null;
    }

    // ── the steps ────────────────────────────────────────────────────────

    /** Status + log row + stock — one transition inside the caller's unit of work. */
    private function move(SalesOrder $order, OrderStatus $from, OrderStatus $to, string $trigger, ?StockConfirmation $confirmation, \DateTimeImmutable $now): void
    {
        $order->moveTo($to->getCode());
        $order->touch($this->actor, $now);
        $this->em->persist(new SalesOrderStatusChange($order, $from->getCode(), $to->getCode(), $trigger, $confirmation?->reason, $this->actor, $now));
        $this->syncStock($order, $to, 'status:' . $from->getCode() . '>' . $to->getCode(), $confirmation);
        $this->em->flush();
    }

    /**
     * THE stock call (plan §8.3) — the only one: the COMPLETE target state of the order in the
     * PHASE of $status (ADR-043 addendum 2026-10-15), in the caller's unit of work.
     */
    private function syncStock(SalesOrder $order, OrderStatus $status, string $trigger, ?StockConfirmation $confirmation, ?OrderPhase $targets = null): void
    {
        // $targets: the phase whose targets apply instead of the status' — a deleted order's are those of
        // `cancelled` (nothing reserved, nothing consumed — ADR-054 decision 14).
        $this->stock ??= StockLedgers::default($this->em, $this->actor);
        $this->stock->bringTo(new StockRequest(
            'order',
            (string) $order->getId(),
            $trigger,
            $this->actor,
            OrderStockTargets::of($order->getLines(), $targets ?? $status->getPhase()),
            $confirmation,
        ));
    }

    /** D11: the totals from debtor's preview — the one computation; null when it refuses. */
    private function writeTotals(SalesOrder $order): void
    {
        try {
            $preview = $this->preview($order);
            $order->setTotals($preview->getNetTotal(), $preview->getTaxTotal(), $preview->getGrossTotal());
        } catch (DebtorException | VatException) {
            $order->setTotals(null, null, null);
        }
    }

    private function invoicing(): InvoicingService
    {
        return $this->invoicing ??= new InvoicingService($this->em, $this->actor);
    }

    /**
     * The booking print's judgement of one order (plan §7): no invoice yet, a bookable phase
     * (`confirmed`, `delivered`) — before the unit of work and again under the lock.
     *
     * @throws OrderRefusedException ALREADY_INVOICED | NOT_INVOICEABLE
     */
    private function assertBookable(SalesOrder $order, OrderStatus $status): void
    {
        if ($order->isDeleted()) {
            throw new OrderRefusedException(OrderRefusedException::DELETED, 'Auftrag ' . $order->documentNumber() . ' ist gelöscht — nichts wurde fakturiert.');
        }
        if ($order->getInvoice() !== null) {
            $number = $order->getInvoice()->getNumber();
            throw new OrderRefusedException(OrderRefusedException::ALREADY_INVOICED, 'Zu Auftrag ' . $order->documentNumber() . ' ist die Rechnung' . ($number !== null ? ' ' . $number : '') . ' bereits fakturiert — nichts wurde nochmals fakturiert.');
        }
        if (!$status->getPhase()->isBookable()) {
            throw new OrderRefusedException(OrderRefusedException::NOT_INVOICEABLE, 'Auftrag ' . $order->documentNumber() . ' steht in «' . $status->getLabel() . '» (Phase ' . $status->getPhase()->label() . ') — fakturiert wird ein bestätigter oder gelieferter Auftrag.');
        }
    }

    /** Locks the row, compares the version under the lock, re-reads the order refreshed. */
    private function lockAt(int $id, int $version): SalesOrder
    {
        $versions = $this->orders()->lockIds([$id]);
        if (($versions[$id] ?? null) !== $version) {
            throw new OrderConflictException($id, $version);
        }

        return $this->orders()->withLines($id) ?? throw new OrderConflictException($id, $version);
    }

    /**
     * D7, read with ADR-054 (Faktura-Journal, step 5 of its build plan): an order whose invoice is
     * POSTED stays as it was invoiced — a correction is a credit note. An order whose invoice is ISSUED
     * (in the Faktura-Journal, not posted) is EDITABLE again, whatever the phase (it stands in `closed`
     * since «Fakturieren»): its save flags the invoice ({@see flagInvoice()}), «Neu fakturieren» re-issues
     * it ({@see reinvoice()}). A deleted order is never written again.
     *
     * @throws OrderRefusedException NOT_EDITABLE (D7)
     */
    private function assertEditable(SalesOrder $order, OrderStatus $status): void
    {
        if ($order->isDeleted()) {
            throw new OrderRefusedException(OrderRefusedException::NOT_EDITABLE, 'Auftrag ' . $order->documentNumber() . ' ist gelöscht'
                . ($order->getDeletedAt() !== null ? ' (' . $order->getDeletedAt()->format('d.m.Y') . ', ' . $order->getDeletedBy() . ': ' . $order->getDeleteReason() . ')' : '') . ' — er bleibt, wie er war.');
        }
        $invoice = $order->getInvoice();
        if ($invoice !== null && $invoice->isInJournal()) {
            return;   // ADR-054 decision 12: editable while the invoice is in the Faktura-Journal
        }
        if ($invoice !== null) {
            $number = $invoice->getNumber();
            throw new OrderRefusedException(OrderRefusedException::NOT_EDITABLE, 'Zum Auftrag ' . $order->documentNumber() . ' ist die Rechnung' . ($number !== null ? ' ' . $number : '') . ' ' . ($invoice->isPosted() ? 'verbucht' : $invoice->state()->label()) . ' — er bleibt, wie er fakturiert wurde. Eine Korrektur ist eine Gutschrift auf der Rechnung.');
        }
        if ($status->getPhase()->isLocked()) {
            throw new OrderRefusedException(OrderRefusedException::NOT_EDITABLE, 'Auftrag ' . $order->documentNumber() . ' ist ' . ($status->getPhase() === OrderPhase::Cancelled ? 'storniert' : 'abgeschlossen') . ' («' . $status->getLabel() . '») — zum Ändern zuerst den Status zurücksetzen.');
        }
    }

    /** @throws OrderRefusedException STATUS_UNKNOWN */
    private function statusOf(SalesOrder $order, OrderStatuses $statuses): OrderStatus
    {
        return $statuses->byCode($order->getStatusCode())
            ?? throw new OrderRefusedException(OrderRefusedException::STATUS_UNKNOWN, 'Der Status «' . $order->getStatusCode() . '» von Auftrag ' . $order->documentNumber() . ' fehlt in den Stammdaten (Auftragsstatus) — dort wiederherstellen.');
    }

    /** @throws OrderRefusedException NO_STATUS | STATUS_UNKNOWN | NOT_STARTABLE */
    private function startStatus(OrderStatuses $statuses, ?string $code): OrderStatus
    {
        if ($code === null || trim($code) === '') {
            return $statuses->initial()
                ?? throw new OrderRefusedException(OrderRefusedException::NO_STATUS, 'Kein Auftragsstatus ist aktiv — unter Stammdaten › Aufträge › Auftragsstatus einen aktivieren.');
        }
        $status = $statuses->byCode($code)
            ?? throw new OrderRefusedException(OrderRefusedException::STATUS_UNKNOWN, 'Den Status «' . $code . '» gibt es nicht.');
        if (!in_array($status, $statuses->startable(), true)) {
            throw new OrderRefusedException(OrderRefusedException::NOT_STARTABLE, 'Ein neuer Auftrag beginnt nicht in «' . $status->getLabel() . '» — nur in einem aktiven Status der Phasen Neu, Bestätigt oder Geliefert.');
        }

        return $status;
    }

    // ── the draft ────────────────────────────────────────────────────────

    /**
     * Writes the judged draft into $order: the head, then the lines in document order — an
     * existing line (by id) rewritten in place, a new one added, the ones left out removed.
     *
     * @param array<string, mixed> $resolved {@see resolve()}
     * @throws OrderConflictException a line id the order does not hold (the screen was stale)
     */
    private function apply(SalesOrder $order, array $resolved): void
    {
        $order->describe(
            $resolved['contact'],
            $resolved['subject'],
            $resolved['orderDate'],
            $resolved['serviceFrom'],
            $resolved['serviceTo'],
            $resolved['priceMode'],
            $resolved['currency'],
            $resolved['paymentTargetCode'],
            $resolved['deliveryAddress'],
            $resolved['notes'],
            $resolved['billingAddress'],
            $resolved['paymentTermsCode'],
            $resolved['subjectLabel'],
        );

        $existing = [];
        foreach ($order->getLines() as $line) {
            $existing[$line->getId()] = $line;
        }
        $kept     = [];
        $position = 0;
        $write    = function (array $spec, ?SalesOrderLine $parent) use ($order, &$existing, &$kept, &$position, &$write): void {
            if ($spec['id'] !== null) {
                $line = $existing[$spec['id']] ?? throw new OrderConflictException((int) $order->getId(), $order->getVersion());
                $kept[$spec['id']] = true;
            } else {
                $line = new SalesOrderLine($order);
                $order->addLine($line);
            }
            $line->write(++$position, $parent, $spec['type'], $spec['variant'], $spec['articleNumber'], $spec['text'], $spec['quantity'], $spec['unit'], $spec['unitPrice'], $spec['discount'], $spec['taxCode'], $spec['account'], $spec['code']);
            foreach ($spec['children'] as $child) {
                $write($child, $line);
            }
        };
        foreach ($resolved['lines'] as $spec) {
            $write($spec, null);
        }
        foreach ($existing as $id => $line) {
            if (!isset($kept[$id])) {
                $order->removeLine($line);
            }
        }
    }

    /**
     * Judges the draft and resolves what it names — the contact, the articles with their values
     * at save (D3: tax code and account FROM the article; text / unit / price when empty), the
     * dates, the payment target and terms — BEFORE any unit of work. $existing = the order being
     * rewritten (its contact, payment target and terms and variants stay valid although
     * deactivated since — the reference rule, ADR-043 decision 19; its free priced lines stay
     * while unchanged — ADR-043 addendum 2026-10-18).
     *
     * @return array<string, mixed>
     * @throws InvalidOrderException
     * @throws OrderConflictException a line id that is not $existing's, or one named twice
     */
    private function resolve(OrderDraft $draft, ?SalesOrder $existing): array
    {
        $errors   = [];
        $currency = DebtorCurrency::base();

        $contact = $draft->contactId > 0 ? $this->em->getRepository(Contact::class)->find($draft->contactId) : null;
        if ($contact === null) {
            $errors['contact_id'] = $draft->contactId > 0 ? 'Diesen Kontakt gibt es nicht (mehr).' : 'Kunde wählen.';
        } elseif (!$contact->isActive() && $existing?->getContact()->getId() !== $contact->getId()) {
            $errors['contact_id'] = '«' . $contact->displayName() . '» ist inaktiv — für einen inaktiven Kontakt wird kein Auftrag erfasst.';
        } elseif ($existing?->getContact()->getId() !== $contact->getId()
            && $this->em->getRepository(DebtorProfile::class)->findByContact($contact) === null) {
            // No document for a contact without a debtor profile (owner 2026-10-18, in the service
            // since 2026-10-19 — an import, a shop, a script are bound like the window): a NEW
            // customer — a new order, a customer change — must be a debtor. An order keeps the
            // customer it already holds (older drafts; its booking print opens the missing profile).
            $errors['contact_id'] = DebtorProfileService::notDebtor($contact);
        }

        $subject = trim($draft->subject);
        if (mb_strlen($subject) > SalesOrder::SUBJECT_LENGTH) {
            $errors['subject'] = 'Betreff: höchstens ' . SalesOrder::SUBJECT_LENGTH . ' Zeichen.';
        }
        // The «Betreffname» (owner 2026-10-21) — debtor's SubjectLabel is the one rule: normalised, refused when too long.
        $subjectLabel = SubjectLabel::normalize($draft->subjectLabel);
        if (SubjectLabel::isTooLong($subjectLabel)) {
            $errors['subject_label'] = 'Betreffname: höchstens ' . SubjectLabel::LENGTH . ' Zeichen.';
        }

        $orderDate = self::date($draft->orderDate);
        if ($orderDate === null) {
            $errors['order_date'] = trim($draft->orderDate) === '' ? 'Auftragsdatum fehlt.' : 'Auftragsdatum ist kein gültiges Datum.';
        }
        $serviceFrom = trim($draft->serviceFrom) === '' ? null : self::date($draft->serviceFrom);
        $serviceTo   = trim($draft->serviceTo) === '' ? null : self::date($draft->serviceTo);
        if (trim($draft->serviceFrom) !== '' && $serviceFrom === null) {
            $errors['service_from'] = '«Leistung von» ist kein gültiges Datum.';
        }
        if (trim($draft->serviceTo) !== '' && $serviceTo === null) {
            $errors['service_to'] = '«Leistung bis» ist kein gültiges Datum.';
        } elseif ($serviceTo !== null && $serviceFrom === null && !isset($errors['service_from'])) {
            $errors['service_from'] = '«Leistung bis» braucht ein «Leistung von».';
        } elseif ($serviceTo !== null && $serviceFrom !== null && $serviceTo < $serviceFrom) {
            $errors['service_to'] = '«Leistung bis» liegt vor «Leistung von».';
        }

        $priceMode = PriceMode::tryFrom($draft->priceMode);
        if ($priceMode === null) {
            $errors['price_mode'] = 'Preise netto oder brutto wählen.';
        }
        // The mode the posted PRICES are in (owner 2026-10-18): the window converts them when
        // «Preise» changes and says so in `prices_mode`; when it still names the other mode (no
        // script, a stale client) the prices are converted HERE — never stored under the other
        // meaning. Not given = the prices are in `priceMode`.
        $pricesMode = trim((string) $draft->pricesMode) === '' ? $priceMode : PriceMode::tryFrom(trim((string) $draft->pricesMode));
        if ($pricesMode === null && $priceMode !== null) {
            $errors['price_mode'] = 'Die Preise lassen sich keinem Preismodus zuordnen — das Fenster neu laden.';
        }

        $targetCode = PaymentTarget::normalizeCode((string) $draft->paymentTargetCode);
        if ($targetCode !== '') {
            $target = $this->em->getRepository(PaymentTarget::class)->findByCode($targetCode);
            if ($target === null) {
                $errors['payment_target_code'] = 'Das Zahlungsziel «' . $targetCode . '» gibt es nicht.';
            } elseif (!$target->isActive() && $existing?->getPaymentTargetCode() !== $targetCode) {
                $errors['payment_target_code'] = 'Das Zahlungsziel «' . $target->getLabel() . '» ist inaktiv.';
            }
        }

        // The payment terms (owner 2026-10-18) — '' = the customer's debtor terms at the booking print.
        $termsCode = PaymentTerms::normalizeCode((string) $draft->paymentTermsCode);
        if ($termsCode !== '') {
            $terms = $this->em->getRepository(PaymentTerms::class)->findByCode($termsCode);
            if ($terms === null) {
                $errors['payment_terms_code'] = 'Die Zahlungskonditionen «' . $termsCode . '» gibt es nicht.';
            } elseif (!$terms->isActive() && $existing?->getPaymentTermsCode() !== $termsCode) {
                $errors['payment_terms_code'] = 'Die Zahlungskonditionen «' . $terms->getLabel() . '» sind inaktiv.';
            }
        }

        $ship = $draft->deliveryAddress;
        if ($ship !== null && $ship->lines() === [] && $ship->getCountry() === '') {
            $ship = null;
        }
        if ($ship !== null && !$ship->isComplete()) {
            $errors['ship'] = 'Die Lieferadresse braucht mindestens Name, PLZ, Ort und Land.';
        } elseif ($ship !== null && ($problem = self::contactProblem($ship)) !== '') {
            $errors['ship'] = 'Lieferadresse: ' . $problem;
        }
        $bill = $draft->billingAddress;
        if ($bill !== null && $bill->lines() === [] && $bill->getCountry() === '') {
            $bill = null;
        }
        if ($bill !== null && !$bill->isComplete()) {
            $errors['bill'] = 'Die Rechnungsadresse braucht mindestens Name, PLZ, Ort und Land.';
        } elseif ($bill !== null && ($problem = self::contactProblem($bill)) !== '') {
            $errors['bill'] = 'Rechnungsadresse: ' . $problem;
        }

        $notes = trim($draft->notes);
        if (mb_strlen($notes) > SalesOrder::NOTES_LENGTH) {
            $errors['notes'] = 'Notiz: höchstens ' . SalesOrder::NOTES_LENGTH . ' Zeichen.';
        }

        // What the order being rewritten already holds — kept references stay valid (decision 19).
        // `free`: the order's priced lines WITHOUT an article (made before 2026-10-18) — kept while unchanged.
        $context = ['lines' => [], 'variants' => [], 'seen' => [], 'free' => []];
        foreach ($existing?->getLines() ?? [] as $line) {
            $context['lines'][$line->getId()] = true;
            if ($line->getVariant() !== null) {
                $context['variants'][$line->getVariant()->getId()] = true;
            } elseif ($line->type() !== LineType::Text) {
                $context['free'][$line->getId()] = $line;
            }
        }
        $context += [
            'orderId'     => $existing?->getId() ?? 0,
            'version'     => $existing?->getVersion() ?? 0,
            'orderDate'   => $orderDate,
            'serviceDate' => $serviceFrom ?? $orderDate,
            'priceMode'   => $priceMode,
            'pricesMode'  => $pricesMode,
            // What the stored lines' prices mean — a kept free line compares in the new mode.
            'storedMode'  => $existing?->priceMode(),
            'currency'    => $currency,
        ];

        $lines = [];
        foreach (array_values($draft->lines) as $i => $lineDraft) {
            $spec = $this->resolveLine($lineDraft, (string) $i, false, $context, $errors);
            if ($spec !== null) {
                $lines[] = $spec;
            }
        }

        if ($errors !== []) {
            throw new InvalidOrderException($errors, $draft);
        }

        return [
            'contact'           => $contact,
            'subject'           => $subject,
            'subjectLabel'      => $subjectLabel,
            'orderDate'         => $orderDate,
            'serviceFrom'       => $serviceFrom,
            'serviceTo'         => $serviceTo,
            'priceMode'         => $priceMode,
            'currency'          => $currency,
            'paymentTargetCode' => $targetCode === '' ? null : $targetCode,
            'paymentTermsCode'  => $termsCode === '' ? null : $termsCode,
            'deliveryAddress'   => $ship,
            'billingAddress'    => $bill,
            'notes'             => $notes === '' ? null : $notes,
            'lines'             => $lines,
        ];
    }

    /**
     * One line of the draft judged and resolved; null when it is unusable (its errors recorded).
     *
     * @param array<string, mixed>  $context
     * @param array<string, string> $errors
     * @return array<string, mixed>|null
     */
    private function resolveLine(OrderLineDraft $d, string $path, bool $isChild, array &$context, array &$errors): ?array
    {
        $field = static fn(string $name): string => 'lines.' . $path . '.' . $name;
        $count = count($errors);

        if ($d->id !== null) {
            if (!isset($context['lines'][$d->id]) || isset($context['seen'][$d->id])) {
                throw new OrderConflictException((int) $context['orderId'], (int) $context['version']);
            }
            $context['seen'][$d->id] = true;
        }

        // An order knows «Leistung» and «Text» only (owner 2026-10-18, ADR-043 addendum): a lump sum
        // is an article with quantity 1. A stored lump-sum line still reads and invoices as one.
        $type = LineType::tryFrom($d->type);
        if ($type === null || $type === LineType::Rounding || $type === LineType::LumpSum) {
            $errors[$field('type')] = 'Art der Position: Leistung oder Text — eine Pauschale ist ein Artikel mit Menge 1.';
            return null;
        }
        if ($isChild && $d->children !== []) {
            $errors[$field('children')] = 'Nur eine Ebene: unter einer untergeordneten Position stehen keine weiteren.';
        }

        $text = trim($d->text);
        if (mb_strlen($text) > SalesOrderLine::TEXT_LENGTH) {
            $errors[$field('text')] = 'Text: höchstens ' . SalesOrderLine::TEXT_LENGTH . ' Zeichen.';
        }
        // The line's short code (owner 2026-10-22): any line type, trimmed, '' = none, never cut.
        $code = trim((string) $d->code);
        if (mb_strlen($code) > SalesOrderLine::CODE_LENGTH) {
            $errors[$field('code')] = 'Code: höchstens ' . SalesOrderLine::CODE_LENGTH . ' Zeichen.';
        }
        $code = $code === '' ? null : $code;

        if ($type === LineType::Text) {
            if ($d->variantId !== null) {
                $errors[$field('variant')] = 'Eine Textzeile verweist auf keinen Artikel.';
            }
            if ($text === '') {
                $errors[$field('text')] = 'Text fehlt.';
            }
            if ($d->children !== []) {
                $errors[$field('children')] = 'Unter einer Textzeile stehen keine Positionen — nur unter einer Position mit Preis.';
            }

            return count($errors) > $count ? null : self::spec($d->id, $type, null, null, $text, null, null, null, 0, null, null, [], $code);
        }

        // ── a priced line: an article (D3) — or a free line (D2) the order already holds, unchanged ──
        $variant = null;
        $keptFree = null;
        $articleNumber = null;
        $unit      = trim($d->unit);
        $unitPrice = null;
        $priceText = trim($d->unitPrice);
        if ($d->variantId !== null) {
            /** @var Variant|null $variant */
            $variant = $this->em->getRepository(Variant::class)->find($d->variantId);
            if ($variant === null) {
                $errors[$field('variant')] = 'Diesen Artikel gibt es nicht (mehr).';
            } elseif (!$variant->isActive() && !isset($context['variants'][$variant->getId()])) {
                $errors[$field('variant')] = 'Artikel ' . $variant->getNumber() . ' ist inaktiv und wird für neue Positionen nicht angeboten.';
            }
            if ($variant !== null) {
                $product       = $variant->getProduct();
                $articleNumber = $variant->getNumber();
                $taxCode       = $product->getTaxCode();
                $account       = $product->getRevenueAccount();
                $text          = $text !== '' ? $text : $variant->lineText();   // name, then the description (owner 2026-10-18)
                $unit          = $unit !== '' ? $unit : $variant->getUnit();
                if ($priceText === '' && $context['orderDate'] instanceof \DateTimeImmutable && $context['priceMode'] instanceof PriceMode) {
                    $unitPrice = $this->articlePrice($variant, $taxCode, $context, $field('unit_price'), $errors);
                }
            } else {
                $taxCode = $account = '';
            }
        } else {
            // Every priced line is an article (owner 2026-10-16 / -18, ADR-043 addendum 2026-10-18):
            // no NEW free priced line. A free line the order already holds stays readable and
            // savable while it is UNCHANGED (judged below) — with its stored tax code and account.
            $keptFree = $d->id !== null ? ($context['free'][$d->id] ?? null) : null;
            if ($keptFree === null) {
                $errors[$field('variant')] = 'Artikel wählen — jede Position mit Preis ist ein Artikel.';
                return null;
            }
            $taxCode = (string) $keptFree->getTaxCode();
            $account = (string) $keptFree->getRevenueAccount();
        }

        if ($priceText !== '') {
            $decimal = str_replace(["'", '’', ' '], '', str_replace(',', '.', $priceText));
            try {
                $unitPrice = Money::fromDecimal($decimal, $context['currency']);
            } catch (\InvalidArgumentException) {
                $errors[$field('unit_price')] = 'Preis als Betrag mit höchstens zwei Dezimalen, z.B. 120 oder 120.50.';
            }
            // Posted in the other mode (owner 2026-10-18 — the server's safety net, Rule 7).
            if ($unitPrice !== null && ($variant !== null || $keptFree !== null)) {
                $unitPrice = $this->repriced($unitPrice, $variant, $taxCode, $context, $field('unit_price'), $errors);
            }
        } elseif ($d->variantId === null) {
            $errors[$field('unit_price')] = 'Preis fehlt.';
        }

        $quantity = null;
        $quantityText = str_replace(["'", '’', ' '], '', str_replace(',', '.', trim($d->quantity)));
        if ($quantityText === '') {
            $errors[$field('quantity')] = 'Menge fehlt.';
        } elseif (!preg_match(SalesOrderLine::QUANTITY_PATTERN, $quantityText)) {
            $errors[$field('quantity')] = 'Menge als Zahl mit höchstens drei Dezimalen, z.B. 2, 0.25 oder -1 für eine Rücknahme.';
        } else {
            $quantity = $quantityText;
        }

        $discount = 0;
        if (trim($d->discount) !== '') {
            try {
                $discount = PaymentTerms::percentToHundredths($d->discount);
                if ($discount > 10000) {
                    $errors[$field('discount')] = 'Rabatt: höchstens 100 %.';
                }
            } catch (\InvalidArgumentException) {
                $errors[$field('discount')] = 'Rabatt in Prozent mit höchstens zwei Dezimalen, z.B. 10 oder 2.5.';
            }
        }

        if (mb_strlen($unit) > SalesOrderLine::UNIT_LENGTH) {
            $errors[$field('unit')] = 'Einheit: höchstens ' . SalesOrderLine::UNIT_LENGTH . ' Zeichen.';
        }

        if ($keptFree !== null && count($errors) === $count && !self::freeLineUnchanged($keptFree, $text, $quantity, $unit, $unitPrice, $discount, $this->storedPriceIn($keptFree, $context))) {
            $errors[$field('variant')] = 'Eine Position ohne Artikel lässt sich nur unverändert speichern — für eine Änderung einen Artikel wählen.';
        }

        $children = [];
        foreach (array_values($d->children) as $j => $child) {
            $spec = $this->resolveLine($child, $path . '.' . $j, true, $context, $errors);
            if ($spec !== null) {
                $children[] = $spec;
            }
        }

        if (count($errors) > $count) {
            return null;
        }

        return self::spec($d->id, $type, $variant, $articleNumber, $text, $quantity, $unit === '' ? null : $unit, $unitPrice, $discount, $taxCode, $account, $children, $code);
    }

    /**
     * What an ARTICLE line takes from the article — the values {@see resolveLine()} fills in at
     * save when text / unit / price are left empty (the text = `Variant::lineText()`: the name on
     * the first line, the product's description from the second — owner 2026-10-18), plus the tax
     * code and account it always takes (D3) — for the window to show them as soon as the article is chosen (owner 2026-10-15). Reads,
     * writes nothing. Null when the number names no variant; `price` is '' and `problem` names why
     * when the article has no price on the order date or the price cannot be converted. `prices` =
     * the price in BOTH modes (net, gross; '' = none) — the window re-converts its rows from them
     * when «Preise» switches (owner 2026-10-18).
     *
     * @return array{text: string, unit: string, price: string, prices: array{net: string, gross: string}, taxCode: string, account: string, problem: string}|null
     */
    public function articleDefaults(string $number, string $orderDate, string $serviceFrom, string $priceMode): ?array
    {
        /** @var Variant|null $variant */
        $variant = $this->em->getRepository(Variant::class)->findByNumber(trim($number));
        if ($variant === null) {
            return null;
        }
        $product = $variant->getProduct();
        $date    = self::date($orderDate);
        $mode    = PriceMode::tryFrom($priceMode);
        $price   = null;
        $errors  = [];
        $prices  = ['net' => '', 'gross' => ''];
        if ($date !== null) {
            $context = ['orderDate' => $date, 'serviceDate' => (trim($serviceFrom) === '' ? null : self::date($serviceFrom)) ?? $date];
            // Both modes — the window converts its rows on a price-mode switch from these (owner 2026-10-18).
            foreach (PriceMode::cases() as $case) {
                $ignored = [];
                $prices[$case->value] = $this->articlePrice($variant, $product->getTaxCode(), ['priceMode' => $case] + $context, 'price', $ignored)?->toDecimal() ?? '';
            }
            if ($mode !== null) {
                $price = $this->articlePrice($variant, $product->getTaxCode(), ['priceMode' => $mode] + $context, 'price', $errors);
            }
        }

        return [
            'text'    => $variant->lineText(),
            'unit'    => $variant->getUnit(),
            'price'   => $price?->toDecimal() ?? '',
            'prices'  => $prices,
            'taxCode' => $product->getTaxCode(),
            'account' => $product->getRevenueAccount(),
            'problem' => $errors['price'] ?? '',
        ];
    }

    /**
     * The price a line would take from $variant — {@see articleDefaults()}'s price, for a whole
     * list at once (the article picker's «Preis» column, owner 2026-10-19): valid at the ORDER
     * date, in the order's price mode, converted with the rate on the SERVICE date. Null when the
     * dates or the mode are no valid input, the article has no price that day or it cannot be
     * converted. Reads only.
     */
    public function articlePriceAt(Variant $variant, string $orderDate, string $serviceFrom, string $priceMode): ?Money
    {
        $date = self::date($orderDate);
        $mode = PriceMode::tryFrom($priceMode);
        if ($date === null || $mode === null) {
            return null;
        }
        $ignored = [];

        return $this->articlePrice($variant, $variant->getProduct()->getTaxCode(), [
            'priceMode'   => $mode,
            'orderDate'   => $date,
            'serviceDate' => (trim($serviceFrom) === '' ? null : self::date($serviceFrom)) ?? $date,
        ], 'price', $ignored);
    }

    /**
     * The article's price valid at the ORDER date, converted to the order's price mode with the
     * rate of its tax code on the SERVICE date when the article's mode differs — then a snapshot.
     *
     * @param array<string, mixed>  $context
     * @param array<string, string> $errors
     */
    private function articlePrice(Variant $variant, string $taxCode, array $context, string $field, array &$errors): ?Money
    {
        /** @var PriceRepository $prices */
        $prices = $this->em->getRepository(Price::class);
        $price  = $prices->validAt($variant, $context['orderDate']);
        if ($price === null) {
            $errors[$field] = 'Artikel ' . $variant->getNumber() . ' hat am ' . $context['orderDate']->format('d.m.Y') . ' keinen Preis — Preis eingeben.';
            return null;
        }
        $converted = $this->convertByRate($price->getAmount(), $taxCode, $price->priceMode(), $context['priceMode'], $context['serviceDate']);
        if ($converted === null) {
            $errors[$field] = 'Der Preis von Artikel ' . $variant->getNumber() . ' lässt sich nicht umrechnen: MWST-Code «' . $taxCode . '» hat am Leistungsdatum keinen Satz — Preis eingeben.';
        }

        return $converted;
    }

    /**
     * A posted price in the mode the draft says it is typed in (`pricesMode`), as the order's
     * mode (`priceMode`) — the server's half of the price-mode switch (owner 2026-10-18; core.js
     * «price mode» is the window's half and follows the same rule):
     *
     *   - the same mode → the price as posted;
     *   - an ARTICLE line whose price EQUALS the article's price in the posted mode → the article's
     *     price in the order's mode, exactly (16.65 gross — not 15.40 × 1.081);
     *   - any other price (typed by hand; a kept free line) → converted with the line's VAT rate on
     *     the service date ({@see convertByRate()}).
     *
     * @param array<string, mixed>  $context
     * @param array<string, string> $errors
     */
    private function repriced(Money $price, ?Variant $variant, string $taxCode, array $context, string $field, array &$errors): ?Money
    {
        $from = $context['pricesMode'] ?? null;
        $to   = $context['priceMode'] ?? null;
        if (!$from instanceof PriceMode || !$to instanceof PriceMode || $from === $to
            || !$context['orderDate'] instanceof \DateTimeImmutable || !$context['serviceDate'] instanceof \DateTimeImmutable) {
            return $price;
        }
        if ($variant !== null) {
            $ignored = [];
            $before  = $this->articlePrice($variant, $taxCode, ['priceMode' => $from] + $context, $field, $ignored);
            if ($before !== null && $before->toDecimal() === $price->toDecimal()) {
                $exact = $this->articlePrice($variant, $taxCode, $context, $field, $ignored);
                if ($exact !== null) {
                    return $exact;
                }
            }
        }
        $converted = $this->convertByRate($price, $taxCode, $from, $to, $context['serviceDate']);
        if ($converted === null) {
            $errors[$field] = 'Der Preis lässt sich nicht nach ' . ($to === PriceMode::Gross ? 'brutto' : 'netto') . ' umrechnen: MWST-Code «' . $taxCode . '» hat am Leistungsdatum keinen Satz.';
        }

        return $converted;
    }

    /**
     * $price from one price mode into the other with the rate of $taxCode on $date — module-vat's
     * `VatCalculator::convertPrice()` (cents, half away from zero; the invoice editor converts with
     * the same rule). Null when the code has no rate on that day. Without VAT ({@see chargesVat()})
     * net and gross are the same price: no conversion (owner 2026-10-21).
     */
    private function convertByRate(Money $price, string $taxCode, PriceMode $from, PriceMode $to, \DateTimeImmutable $date): ?Money
    {
        if ($from === $to || !$this->chargesVat()) {
            return $price;
        }
        try {
            $rate = VatRates::from($this->em)->resolve($taxCode, $date)->rate;
        } catch (VatException) {
            return null;
        }

        return VatCalculator::convertPrice($price, $rate, $from, $to);   // module-vat's ONE rule
    }

    /**
     * A kept free line's stored price as it reads in the order's NEW mode — the window converts it
     * with the mode, so an untouched line still counts as unchanged after «Preise» switched.
     *
     * @param array<string, mixed> $context
     */
    private function storedPriceIn(SalesOrderLine $stored, array $context): ?Money
    {
        $price = $stored->getUnitPrice();
        $from  = $context['storedMode'] ?? null;
        $to    = $context['priceMode'] ?? null;
        if ($price === null || !$from instanceof PriceMode || !$to instanceof PriceMode || $from === $to || !$context['serviceDate'] instanceof \DateTimeImmutable) {
            return $price;
        }

        return $this->convertByRate($price, (string) $stored->getTaxCode(), $from, $to, $context['serviceDate']) ?? $price;
    }

    /**
     * Whether a posted free line equals the stored one it names (ADR-043 addendum 2026-10-18): text,
     * quantity, unit, unit price and discount. A stored LUMP SUM reads as quantity 1 — the window
     * shows it as «Leistung» × 1 (the same amount), so an untouched one still counts as unchanged.
     */
    private static function freeLineUnchanged(SalesOrderLine $stored, string $text, ?string $quantity, string $unit, ?Money $unitPrice, int $discount, ?Money $storedPrice = null): bool
    {
        $storedPrice ??= $stored->getUnitPrice();   // the stored price in the order's NEW mode ({@see storedPriceIn()})
        $storedQuantity = $stored->type() === LineType::LumpSum ? '1' : $stored->getQuantity();
        $sameQuantity   = static fn(?string $a, ?string $b): bool => $a === null || $b === null
            ? $a === $b
            : number_format((float) $a, 3, '.', '') === number_format((float) $b, 3, '.', '');

        $eol = static fn(string $t): string => str_replace("\r\n", "\n", $t);   // a textarea posts CRLF

        return $eol($text) === $eol($stored->getText())
            && $sameQuantity($quantity, $storedQuantity)
            && $unit === (string) $stored->getUnit()
            && $unitPrice?->toDecimal() === $storedPrice?->toDecimal()
            && $discount === $stored->getDiscountPercent();
    }

    /** @param list<array<string, mixed>> $children @return array<string, mixed> */
    private static function spec(?int $id, LineType $type, ?Variant $variant, ?string $articleNumber, string $text, ?string $quantity, ?string $unit, ?Money $unitPrice, int $discount, ?string $taxCode, ?string $account, array $children, ?string $code = null): array
    {
        return [
            'id' => $id, 'type' => $type, 'variant' => $variant, 'articleNumber' => $articleNumber, 'text' => $text,
            'quantity' => $quantity, 'unit' => $unit, 'unitPrice' => $unitPrice, 'discount' => $discount,
            'taxCode' => $taxCode, 'account' => $account, 'children' => $children, 'code' => $code,
        ];
    }

    /** `Y-m-d` → the day; null when it is no date. */
    /**
     * What is wrong with a snapshot's e-mail / phone ('' = nothing) — module-contact's
     * `AddressValidator` rules (2026-10-22), asked field by field (the snapshot's other fields have
     * the document rule `isComplete()`): the e-mail empty or deliverable, the phone at most 40
     * characters. A custom («angepasst») or imported address is bound like a contact's.
     */
    private static function contactProblem(AddressSnapshot $address): string
    {
        $validator = new AddressValidator(new Address());
        $validator->validateEmail($address->getEmail());
        $validator->validatePhone($address->getPhone());
        foreach (['email', 'phone'] as $field) {
            if ($validator->hasFieldError($field)) {
                return $validator->getFieldError($field);
            }
        }

        return '';
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new \DateTimeImmutable($value . ' 00:00:00');
    }

    private function orders(): SalesOrderRepository
    {
        return $this->em->getRepository(SalesOrder::class);
    }
}
