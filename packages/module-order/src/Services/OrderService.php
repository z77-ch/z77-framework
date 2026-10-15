<?php

namespace Z77\Module\Order\Services;

use Z77\Module\Article\Entities\Price;
use Z77\Module\Article\Entities\Variant;
use Z77\Module\Article\Repositories\PriceRepository;
use Z77\Module\Article\Services\ArticleService;
use Z77\Module\Article\Stock\StockConfirmation;
use Z77\Module\Article\Stock\StockLedger;
use Z77\Module\Article\Stock\StockLedgers;
use Z77\Module\Article\Stock\StockRequest;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Debtor\Entities\LineType;
use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Module\Debtor\Entities\PaymentTerms;
use Z77\Module\Debtor\Services\DebtorCurrency;
use Z77\Module\Debtor\Services\DebtorException;
use Z77\Module\Debtor\Services\InvoicingService;
use Z77\Module\Mandator\Services\LedgerAccountCheck;
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
use Z77\Module\Vat\Entities\TaxCode;
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
 * **Editable** (D7) while the order has no invoice and its phase is not locked (closed, cancelled); with an invoice
 * only a forward status change remains. No delete (D8).
 */
final class OrderService
{
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
     * The status does not move here.
     *
     * @throws OrderConflictException the order is gone, its version moved on, or a line id is not the order's
     * @throws OrderRefusedException  NOT_EDITABLE | STATUS_UNKNOWN
     * @throws InvalidOrderException
     * @throws \Z77\Module\Article\Stock\StockReasonRequiredException consumed stock would come back without $confirmation
     */
    public function update(int $id, int $version, OrderDraft $draft, ?StockConfirmation $confirmation = null): SalesOrder
    {
        $statuses = $this->statuses();
        $current  = $this->orders()->find($id);   // the early judgement — judged again under the lock
        if ($current === null || $current->getVersion() !== $version) {
            throw new OrderConflictException($id, $version);
        }
        $this->assertEditable($current, $this->statusOf($current, $statuses));
        $resolved = $this->resolve($draft, $current);
        $now      = new \DateTimeImmutable();

        return $this->em->getTransaction(SalesOrder::class)->run(function () use ($id, $version, $statuses, $resolved, $confirmation, $now): SalesOrder {
            $order  = $this->lockAt($id, $version);
            $status = $this->statusOf($order, $statuses);
            $this->assertEditable($order, $status);

            $this->apply($order, $resolved);
            $this->writeTotals($order);
            $order->touch($this->actor, $now);
            $this->em->flush();   // new lines get their ids

            $this->syncStock($order, $status, 'lines', $confirmation);
            $this->em->flush();

            return $order;
        });
    }

    /**
     * Moves an order to the status $toCode by hand (ADR-043 addendum 2026-10-15): a phase at or
     * after the current one always, backward only while the order has no invoice; never to the
     * default of `closed` (the booking print's alone); `cancelled` only without an invoice; only to
     * an ACTIVE status. Writes the log row `status` (with the reversal reason, if any) and
     * brings the stock to the new status' targets. «Erledigt» is set this way, by hand (owner Q6).
     *
     * @throws OrderConflictException
     * @throws OrderRefusedException  STATUS_UNKNOWN | SAME_STATUS | INACTIVE_TARGET | BOOKED_TARGET | BACKWARD_BARRED | CANCEL_BARRED
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
            $this->move($order, $from, $target, $trigger, $confirmation, $now);

            return $order;
        });
    }

    /**
     * The BOOKING PRINT of one or several orders (plan §7, D4, step 9): each order's invoice is
     * issued AND booked by debtor (`InvoicingService::invoiceAndFinalize()`), linked to the order
     * (D5), and the order moves to the default status of phase `closed` — ONE unit of work, all or
     * nothing.
     *
     *   1. Before the unit of work, at the versions the screen showed: every order exists, has no
     *      invoice and sits in a bookable phase (`confirmed`, `delivered`); the debtor profile of each customer is
     *      opened if it has none (D9 — its own unit of work, `openDebtorProfile()`); each order's
     *      invoice is previewed (`preview()`) so a refusal names THE ORDER («Auftrag 12: …»), and
     *      a payment part that could not be printed is refused the same way; the month range of
     *      the invoice date is created ahead (`prepareDocumentRanges()`, DOCTRINE-NR-003).
     *   2. The unit of work, in the lock order of plan §5: the `sales_order` rows locked by id
     *      ({@see SalesOrderRepository::lockIds()}), the versions compared there, the orders re-read
     *      refreshed and judged again → debtor's chain (`invoiceAndFinalize()` joins: month range,
     *      profile rows, compose, numbers, journal) → per order `linkInvoice()`, the status, the log
     *      row `booking-print`, the stock call.
     *
     * Idempotency is the SCREEN's (plan §7): this method refuses an order that already has an
     * invoice (`already-invoiced`); a repeated submit of the same selection is recognised by the
     * screen and answered with the same PDF without calling this.
     *
     * @param list<array{id: int, version: int}> $orders the orders as the screen showed them
     * @return list<\Z77\Module\Debtor\Entities\Invoice> the booked invoices, in number order
     * @throws OrderConflictException an order is gone or its version moved on
     * @throws OrderRefusedException  NOTHING_SELECTED | NO_BOOKED_STATUS | STATUS_UNKNOWN | ALREADY_INVOICED | NOT_INVOICEABLE | INVOICE_REFUSED
     * @throws \Z77\Module\Article\Stock\StockReasonRequiredException
     */
    public function book(array $orders, \DateTimeImmutable $invoiceDate, ?StockConfirmation $confirmation = null): array
    {
        $versions = [];
        foreach ($orders as $pair) {
            $versions[(int) $pair['id']] = (int) $pair['version'];
        }
        if ($versions === []) {
            throw new OrderRefusedException(OrderRefusedException::NOTHING_SELECTED, 'Kein Auftrag ausgewählt — nichts wurde verbucht.');
        }
        ksort($versions);
        $statuses = $this->statuses();
        $booked   = $statuses->defaultOf(OrderPhase::Closed)
            ?? throw new OrderRefusedException(OrderRefusedException::NO_BOOKED_STATUS, 'Die Phase «Abgeschlossen» hat keinen aktiven Auftragsstatus — unter Stammdaten › Aufträge › Auftragsstatus einen anlegen (z.B. «Gebucht») und «Wird gesetzt beim Buchungsdruck» einschalten. Nichts wurde verbucht.');
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
            $problems = $preview->getPayment()->hasPaymentPart() ? $invoicing->bookingBill($preview)->problems() : [];
            if ($problems !== []) {
                throw new OrderRefusedException(OrderRefusedException::INVOICE_REFUSED, 'Auftrag ' . $order->documentNumber() . ': der Zahlteil (QR-Rechnung) liesse sich nicht drucken — ' . implode(' ', $problems) . ' Beim Zahlungsziel oder beim Mandanten beheben, oder im Auftrag «kein Zahlteil» wählen.');
            }
        }
        $invoicing->prepareDocumentRanges([$invoiceDate]);
        $now = new \DateTimeImmutable();

        // 2. The unit of work — order rows first, then debtor's chain, then the order's own writes.
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
                $invoices = $invoicing->invoiceAndFinalize($drafts);
            } catch (DebtorException | VatException $e) {
                $names = array_keys($byNumber);
                throw new OrderRefusedException(OrderRefusedException::INVOICE_REFUSED, (count($names) === 1 ? 'Auftrag ' : 'Aufträge ') . implode(', ', $names) . ': ' . $e->getMessage(), $e);
            }
            foreach ($invoices as $invoice) {
                [$order, $from] = $byNumber[(string) $invoice->getSourceRef()]
                    ?? throw new \LogicException('The booked invoice ' . $invoice->getId() . ' names no order of this booking print');
                $order->linkInvoice($invoice);
                $this->move($order, $from, $booked, SalesOrderStatusChange::TRIGGER_BOOKING_PRINT, $confirmation, $now);
            }

            return $invoices;
        });
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
     * Where the delivery note goes: the order's delivery address, else the customer's invoice
     * address as debtor chooses it (`InvoicingService::invoiceAddressOf()` — one rule, Rule 2);
     * null when the customer has no address at all.
     */
    public function deliveryAddressOf(SalesOrder $order): ?\Z77\Module\Debtor\Entities\AddressSnapshot
    {
        return $order->deliveryAddress() ?? $this->invoicing()->invoiceAddressOf($order->getContact());
    }

    /** The status rules over the master data as it is now. */
    public function statuses(): OrderStatuses
    {
        /** @var OrderStatusRepository $repo */
        $repo = $this->em->getRepository(OrderStatus::class);

        return new OrderStatuses($repo->allInOrder());
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
    private function syncStock(SalesOrder $order, OrderStatus $status, string $trigger, ?StockConfirmation $confirmation): void
    {
        $this->stock ??= StockLedgers::default($this->em, $this->actor);
        $this->stock->bringTo(new StockRequest(
            'order',
            (string) $order->getId(),
            $trigger,
            $this->actor,
            OrderStockTargets::of($order->getLines(), $status->getPhase()),
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
        if ($order->getInvoice() !== null) {
            $number = $order->getInvoice()->getNumber();
            throw new OrderRefusedException(OrderRefusedException::ALREADY_INVOICED, 'Zu Auftrag ' . $order->documentNumber() . ' ist die Rechnung' . ($number !== null ? ' ' . $number : '') . ' bereits verbucht — nichts wurde nochmals verbucht.');
        }
        if (!$status->getPhase()->isBookable()) {
            throw new OrderRefusedException(OrderRefusedException::NOT_INVOICEABLE, 'Auftrag ' . $order->documentNumber() . ' steht in «' . $status->getLabel() . '» (Phase ' . $status->getPhase()->label() . ') — verbucht wird ein bestätigter oder gelieferter Auftrag.');
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

    /** @throws OrderRefusedException NOT_EDITABLE (D7) */
    private function assertEditable(SalesOrder $order, OrderStatus $status): void
    {
        if ($order->getInvoice() !== null) {
            $number = $order->getInvoice()->getNumber();
            throw new OrderRefusedException(OrderRefusedException::NOT_EDITABLE, 'Zum Auftrag ' . $order->documentNumber() . ' ist die Rechnung' . ($number !== null ? ' ' . $number : '') . ' verbucht — er bleibt, wie er verbucht wurde. Eine Korrektur ist eine Gutschrift auf der Rechnung.');
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
            $line->write(++$position, $parent, $spec['type'], $spec['variant'], $spec['articleNumber'], $spec['text'], $spec['quantity'], $spec['unit'], $spec['unitPrice'], $spec['discount'], $spec['taxCode'], $spec['account']);
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
     * dates, the payment target — BEFORE any unit of work. $existing = the order being rewritten
     * (its contact, payment target, variants and tax codes stay valid although deactivated since —
     * the reference rule, ADR-043 decision 19).
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
        }

        $subject = trim($draft->subject);
        if (mb_strlen($subject) > SalesOrder::SUBJECT_LENGTH) {
            $errors['subject'] = 'Betreff: höchstens ' . SalesOrder::SUBJECT_LENGTH . ' Zeichen.';
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

        $targetCode = PaymentTarget::normalizeCode((string) $draft->paymentTargetCode);
        if ($targetCode !== '') {
            $target = $this->em->getRepository(PaymentTarget::class)->findByCode($targetCode);
            if ($target === null) {
                $errors['payment_target_code'] = 'Das Zahlungsziel «' . $targetCode . '» gibt es nicht.';
            } elseif (!$target->isActive() && $existing?->getPaymentTargetCode() !== $targetCode) {
                $errors['payment_target_code'] = 'Das Zahlungsziel «' . $target->getLabel() . '» ist inaktiv.';
            }
        }

        $ship = $draft->deliveryAddress;
        if ($ship !== null && $ship->lines() === [] && $ship->getCountry() === '') {
            $ship = null;
        }
        if ($ship !== null && !$ship->isComplete()) {
            $errors['ship'] = 'Die Lieferadresse braucht mindestens Name, PLZ, Ort und Land.';
        }

        $notes = trim($draft->notes);
        if (mb_strlen($notes) > SalesOrder::NOTES_LENGTH) {
            $errors['notes'] = 'Notiz: höchstens ' . SalesOrder::NOTES_LENGTH . ' Zeichen.';
        }

        // What the order being rewritten already holds — kept references stay valid (decision 19).
        $context = ['lines' => [], 'variants' => [], 'taxCodes' => [], 'seen' => []];
        foreach ($existing?->getLines() ?? [] as $line) {
            $context['lines'][$line->getId()] = true;
            if ($line->getVariant() !== null) {
                $context['variants'][$line->getVariant()->getId()] = true;
            }
            if ($line->getTaxCode() !== null) {
                $context['taxCodes'][$line->getTaxCode()] = true;
            }
        }
        $context += [
            'orderId'     => $existing?->getId() ?? 0,
            'version'     => $existing?->getVersion() ?? 0,
            'orderDate'   => $orderDate,
            'serviceDate' => $serviceFrom ?? $orderDate,
            'priceMode'   => $priceMode,
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
            'orderDate'         => $orderDate,
            'serviceFrom'       => $serviceFrom,
            'serviceTo'         => $serviceTo,
            'priceMode'         => $priceMode,
            'currency'          => $currency,
            'paymentTargetCode' => $targetCode === '' ? null : $targetCode,
            'deliveryAddress'   => $ship,
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

        $type = LineType::tryFrom($d->type);
        if ($type === null || $type === LineType::Rounding) {
            $errors[$field('type')] = 'Art der Position: Leistung, Pauschale oder Text.';
            return null;
        }
        if ($isChild && $d->children !== []) {
            $errors[$field('children')] = 'Nur eine Ebene: unter einer untergeordneten Position stehen keine weiteren.';
        }

        $text = trim($d->text);
        if (mb_strlen($text) > SalesOrderLine::TEXT_LENGTH) {
            $errors[$field('text')] = 'Text: höchstens ' . SalesOrderLine::TEXT_LENGTH . ' Zeichen.';
        }

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

            return count($errors) > $count ? null : self::spec($d->id, $type, null, null, $text, null, null, null, 0, null, null, []);
        }

        // ── a priced line: article (D3) or free (D2) ──
        $variant = null;
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
                $text          = $text !== '' ? $text : trim($product->getName() . ($variant->getLabel() !== '' ? ' ' . $variant->getLabel() : ''));
                $unit          = $unit !== '' ? $unit : $variant->getUnit();
                if ($priceText === '' && $context['orderDate'] instanceof \DateTimeImmutable && $context['priceMode'] instanceof PriceMode) {
                    $unitPrice = $this->articlePrice($variant, $taxCode, $context, $field('unit_price'), $errors);
                }
            } else {
                $taxCode = $account = '';
            }
        } else {
            $taxCode = TaxCode::normalizeCode($d->taxCode);
            $account = trim($d->revenueAccount);
            if ($text === '') {
                $errors[$field('text')] = 'Text fehlt.';
            }
            $this->judgeFreeLine($taxCode, $account, $context, $field, $errors);
        }

        if ($priceText !== '') {
            $decimal = str_replace(["'", '’', ' '], '', str_replace(',', '.', $priceText));
            try {
                $unitPrice = Money::fromDecimal($decimal, $context['currency']);
            } catch (\InvalidArgumentException) {
                $errors[$field('unit_price')] = 'Preis als Betrag mit höchstens zwei Dezimalen, z.B. 120 oder 120.50.';
            }
        } elseif ($d->variantId === null) {
            $errors[$field('unit_price')] = 'Preis fehlt.';
        }

        $quantity = null;
        $quantityText = str_replace(["'", '’', ' '], '', str_replace(',', '.', trim($d->quantity)));
        if ($type === LineType::Service) {
            if ($quantityText === '') {
                $errors[$field('quantity')] = 'Menge fehlt.';
            } elseif (!preg_match(SalesOrderLine::QUANTITY_PATTERN, $quantityText)) {
                $errors[$field('quantity')] = 'Menge als Zahl mit höchstens drei Dezimalen, z.B. 2, 0.25 oder -1 für eine Rücknahme.';
            } else {
                $quantity = $quantityText;
            }
        } elseif ($quantityText !== '') {
            $errors[$field('quantity')] = 'Eine Pauschale hat keine Menge — für Menge × Preis die Art «Leistung» wählen.';
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

        return self::spec($d->id, $type, $variant, $articleNumber, $text, $quantity, $unit === '' ? null : $unit, $unitPrice, $discount, $taxCode, $account, $children);
    }

    /**
     * What an ARTICLE line takes from the article — the values {@see resolveLine()} fills in at
     * save when text / unit / price are left empty, plus the tax code and account it always takes
     * (D3) — for the window to show them as soon as the article is chosen (owner 2026-10-15). Reads,
     * writes nothing. Null when the number names no variant; `price` is '' and `problem` names why
     * when the article has no price on the order date or the price cannot be converted.
     *
     * @return array{text: string, unit: string, price: string, taxCode: string, account: string, problem: string}|null
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
        if ($date !== null && $mode !== null) {
            $context = ['orderDate' => $date, 'serviceDate' => (trim($serviceFrom) === '' ? null : self::date($serviceFrom)) ?? $date, 'priceMode' => $mode];
            $price   = $this->articlePrice($variant, $product->getTaxCode(), $context, 'price', $errors);
        }

        return [
            'text'    => trim($product->getName() . ($variant->getLabel() !== '' ? ' ' . $variant->getLabel() : '')),
            'unit'    => $variant->getUnit(),
            'price'   => $price?->toDecimal() ?? '',
            'taxCode' => $product->getTaxCode(),
            'account' => $product->getRevenueAccount(),
            'problem' => $errors['price'] ?? '',
        ];
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
        $amount = $price->getAmount();
        if ($price->priceMode() === $context['priceMode']) {
            return $amount;
        }
        try {
            $rate = VatRates::from($this->em)->resolve($taxCode, $context['serviceDate'])->rate;
        } catch (VatException) {
            $errors[$field] = 'Der Preis von Artikel ' . $variant->getNumber() . ' lässt sich nicht umrechnen: MWST-Code «' . $taxCode . '» hat am Leistungsdatum keinen Satz — Preis eingeben.';
            return null;
        }

        return $context['priceMode'] === PriceMode::Gross
            ? $amount->multiplyByRatio(10000 + $rate, 10000)
            : $amount->multiplyByRatio(10000, 10000 + $rate);
    }

    /**
     * A free line's tax code (known, active — or kept by the order) and revenue account (digits;
     * the soft check of the article and the invoice: postable, «Ertrag», «auf Belegen wählbar»).
     * The BINDING check stays debtor's at the booking print (no second rule set — Rule 2).
     *
     * @param array<string, mixed>  $context
     * @param array<string, string> $errors
     */
    private function judgeFreeLine(string $taxCode, string $account, array $context, \Closure $field, array &$errors): void
    {
        if ($taxCode === '') {
            $errors[$field('tax_code')] = 'MWST-Code wählen.';
        } else {
            $code = $this->em->getRepository(TaxCode::class)->findByCode($taxCode);
            if ($code === null) {
                $errors[$field('tax_code')] = 'Den MWST-Code «' . $taxCode . '» gibt es nicht.';
            } elseif (!$code->isActive() && !isset($context['taxCodes'][$taxCode])) {
                $errors[$field('tax_code')] = 'Der MWST-Code «' . $taxCode . '» ist inaktiv.';
            }
        }
        if ($account === '') {
            $errors[$field('revenue_account')] = 'Ertragskonto fehlt.';
        } elseif (!preg_match('/^[0-9]{1,' . SalesOrderLine::ACCOUNT_LENGTH . '}$/', $account)) {
            $errors[$field('revenue_account')] = 'Konto: nur Ziffern, höchstens ' . SalesOrderLine::ACCOUNT_LENGTH . '.';
        } elseif (($problem = ArticleService::accountProblem(new LedgerAccountCheck($this->em), $account)) !== null) {
            $errors[$field('revenue_account')] = $problem;
        }
    }

    /** @param list<array<string, mixed>> $children @return array<string, mixed> */
    private static function spec(?int $id, LineType $type, ?Variant $variant, ?string $articleNumber, string $text, ?string $quantity, ?string $unit, ?Money $unitPrice, int $discount, ?string $taxCode, ?string $account, array $children): array
    {
        return [
            'id' => $id, 'type' => $type, 'variant' => $variant, 'articleNumber' => $articleNumber, 'text' => $text,
            'quantity' => $quantity, 'unit' => $unit, 'unitPrice' => $unitPrice, 'discount' => $discount,
            'taxCode' => $taxCode, 'account' => $account, 'children' => $children,
        ];
    }

    /** `Y-m-d` → the day; null when it is no date. */
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
