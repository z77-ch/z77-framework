<?php
namespace Z77\Module\Order\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\BytesResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Article\Entities\Variant,
    Z77\Module\Article\Repositories\VariantRepository,
    Z77\Module\Article\Stock\StockConfirmation,
    Z77\Module\Article\Stock\StockReasonRequiredException,
    Z77\Module\Contact\Entities\Contact,
    Z77\Module\Contact\Entities\ContactAddress,
    Z77\Module\Contact\Services\AddressTypes,
    Z77\Module\Contact\Services\ContactService,
    Z77\Module\Contact\Ui\ContactForm,
    Z77\Module\Debtor\Entities\AddressSnapshot,
    Z77\Module\Debtor\Entities\DebtorProfile,
    Z77\Module\Debtor\Entities\Invoice,
    Z77\Module\Debtor\Entities\PaymentTarget,
    Z77\Module\Debtor\Entities\PaymentTerms,
    Z77\Module\Debtor\Pdf\InvoicePdf,
    Z77\Module\Debtor\Repositories\DebtorProfileRepository,
    Z77\Module\Debtor\Repositories\InvoiceRepository,
    Z77\Module\Debtor\Repositories\PaymentTermsRepository,
    Z77\Module\Debtor\Services\DebtorCurrency,
    Z77\Module\Debtor\Services\DebtorException,
    Z77\Module\Debtor\Services\InvoicingService,
    Z77\Module\Debtor\Services\LockConflict,
    Z77\Module\Debtor\Services\PaymentStates,
    Z77\Module\Debtor\Ui\CustomerPicker,
    Z77\Module\Debtor\Ui\CustomerPickerTrait,
    Z77\Module\Debtor\Ui\DebtorProfileLayout,
    Z77\Module\Order\Entities\OrderPhase,
    Z77\Module\Order\Entities\OrderStatus,
    Z77\Module\Order\Entities\SalesOrder,
    Z77\Module\Order\Entities\SalesOrderStatusChange,
    Z77\Module\Order\Pdf\OrderPdf,
    Z77\Module\Order\Repositories\SalesOrderRepository,
    Z77\Module\Order\Repositories\SalesOrderStatusChangeRepository,
    Z77\Module\Order\Services\InvalidOrderException,
    Z77\Module\Order\Services\OrderConflictException,
    Z77\Module\Order\Services\OrderException,
    Z77\Module\Order\Services\OrderRefusedException,
    Z77\Module\Order\Services\OrderService,
    Z77\Module\Vat\Entities\TaxCode,
    Z77\Module\Vat\Entities\TaxRate,
    Z77\Module\Vat\Services\VatException,
    Z77\Module\Vat\Services\VatRates,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Libraries\Convention\Naming,
    Z77\Shared\Listing\ListDefinition,
    Z77\Shared\Money\AmountFormat,
    Z77\Shared\Money\Money
;

/**
 * Aufträge › Aufträge (plan §9.3 / §9.4, steps 7–9 of P7 slim; the owner's board on the developer
 * canvas) — mounted by a thin host controller in the backend (ADR-018 pattern): `use
 * OrderControllerTrait` + a one-line layout config delegating to {@see OrderLayout::config()};
 * URL `/backend/order/order/…`.
 *
 *   - `list` — a STANDARD LIST ({@see OrderListing}): the view tabs are the PHASES in the
 *     toolbar (Eröffnet · Termin · Geliefert · Abgeschlossen · Storniert · Alle, each with its
 *     count), the state icon opens the order WINDOW, «Zahlung» in «Abgeschlossen» from debtor's
 *     `PaymentStates` (one query for the page); in «Termin» and «Geliefert» the selection bar
 *     «Buchungsdruck …». Action cell: «+ Neuer Auftrag»;
 *   - `add` / `edit` — the ORDER WINDOW (ADR-047, mask `order-edit`, entity `sales-order:<id>`):
 *     one form, one save, radio tabs Auftrag · Verlauf · Belege; the head (customer — debtors
 *     and contacts without a profile, «+ Kontakt» —, subject, dates, prices, payment target,
 *     delivery address, the starting status of a new order, an internal note) and the positions
 *     editor ({@see OrderForm}); the totals and what the booking print would refuse from debtor's
 *     preview (D11). Read-only when `OrderService::editRefusal()` says so (D7);
 *   - `new-contact` — «+ Kontakt» of the window: debtor's contact window reused (Rule 9), its save
 *     puts the customer field back into the order window; `customer-pick` — debtor's customer
 *     picker ({@see CustomerPickerTrait});
 *   - `status` — «Status ändern …» in the same window: the allowed targets
 *     (`OrderStatuses::targets()`), and — only when the stock seam demands it — a mandatory
 *     reason (`StockReasonRequiredException` → `StockConfirmation`);
 *   - `pdf` — «Offerte» / «Auftragsbestätigung» by the PHASE (`new` is a quote; no side effect);
 *     `invoice-preview` — the invoice the booking print would issue today, debtor's PREVIEW
 *     layout; `confirm-confirmation` + `confirmation` — the order confirmation of a quote: the POST
 *     moves it into `confirmed` first (`printConfirmation()`), the answer is the PDF in a new tab;
 *     `confirm-delivery-note` + `delivery-note` — the delivery note, the same way into `delivered`
 *     (`printDeliveryNote()`, D6);
 *   - `confirm-book` + `book` — the BOOKING PRINT from the window (one order) or the selection bar
 *     (several): a confirmation PAGE (the DEBTOR-WIN-002 pattern) → POST into a NEW TAB →
 *     `OrderService::book()` → the combined invoice PDF (`InvoicePdf::combined()`); a repeated
 *     submit of the same selection reprints and books nothing; a refusal is a message page naming
 *     the order.
 *
 *   - `article-defaults` — what a line takes from the chosen article, for core.js «fill» (reads only).
 *
 * Every write goes through {@see OrderService} with the logged-in user as actor. No JavaScript of
 * its own (Rule 7): plain forms; the positions use core.js «rows» (add, remove, drag & drop) and
 * «line sums» (the live total), whose adders and × are submits without the script; the article
 * picker is a `<datalist>` whose choice fills the row through core.js «fill» — without the script
 * the save fills it.
 * The using class MUST provide (via its host base): `html()`, `fetch()`, `fetchError()`,
 * `redirect()`, `bytes()`, `em()`, `$layoutManager`, `$messageService`, `$help`.
 */
trait OrderControllerTrait
{
    use CustomerPickerTrait;

    private const ORDER_NS = 'Z77\\Module\\Order';

    private const ORDER_CONFLICT_MESSAGE = 'Der Auftrag wurde inzwischen geändert — bitte neu laden und erneut versuchen.';

    /** The status-log trigger → German, for «Verlauf». */
    private const ORDER_TRIGGERS = [
        SalesOrderStatusChange::TRIGGER_CREATE        => 'erfasst',
        SalesOrderStatusChange::TRIGGER_STATUS        => 'Status geändert',
        SalesOrderStatusChange::TRIGGER_CONFIRMATION  => 'Auftragsbestätigung',
        SalesOrderStatusChange::TRIGGER_DELIVERY_NOTE => 'Lieferschein',
        SalesOrderStatusChange::TRIGGER_BOOKING_PRINT => 'Buchungsdruck',
    ];

    /** URL root of THIS mount — every link and form is built from it. */
    protected function orderListBase(): string
    {
        return OrderLayout::BASE;
    }

    /** debtor's document screens — the order window links its invoice there (order knows debtor, never the reverse). */
    protected function orderInvoiceBase(): string
    {
        return '/backend/finance/invoice';
    }

    private function orders(): SalesOrderRepository
    {
        return $this->em()->getRepository(SalesOrder::class);
    }

    /** The one write path, acting as the logged-in user (the screen is ADMIN — someone is logged in). */
    private function orderService(): OrderService
    {
        return new OrderService($this->em(), DI::getAuthService()->getCurrentUser()->getUserName());
    }

    private function orderIsFetch(): bool
    {
        return ListDefinition::isFetch(DI::getRequest());
    }

    /**
     * A page or a window's content other than the list: the layout pins the body to
     * `listAction`, so the section is swapped for the page's own template (the invoice model).
     *
     * @param array<string, mixed> $context
     */
    private function orderPage(string $template, array $context, string $path = 'Backend/OrderController', string $ns = self::ORDER_NS): HtmlResponse
    {
        $context += ['actionBase' => $this->orderListBase(), 'fmt' => static fn(?Money $m) => AmountFormat::of($m)];
        $response = $this->html($context);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials($template, $path, $ns);

        return $response;
    }

    /** A refusal: in a window the error flash (the window stays), as a page flash + the list. */
    private function orderRefuse(string $message): FetchResponse|RedirectResponse
    {
        if ($this->orderIsFetch()) {
            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($this->orderListBase() . '/list', 303);
    }

    /** The order of `?id=` with its lines, or null. */
    private function orderOfRequest(): ?SalesOrder
    {
        $id = (int) DI::getRequest()->getGetParameter('id');

        return $id > 0 ? $this->orders()->withLines($id) : null;
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $request = DI::getRequest();
        // The view decides the columns (the selection checkbox, the payment column) — read once ahead of the definition.
        $view = (string) $request->getGetParameter('view');
        if (!isset(OrderListing::VIEWS[$view])) {
            $view = array_key_first(OrderListing::VIEWS);
        }
        $definition = OrderListing::definition(DebtorCurrency::base(), $view);
        $state      = $definition->readRequest($request);
        $statuses   = $this->orderService()->statuses()->all();
        $search     = OrderListing::search($state, $statuses);
        $total      = $this->orders()->countSearch($search);
        $paging     = $state->paging($total);
        $orders     = $total === 0 ? [] : $this->orders()->search($search, $paging->offset(), $paging->pageSize);

        $response = $this->html([
            'orders'     => $orders,
            'payments'   => $view === OrderListing::VIEW_CLOSED ? $this->orderPaymentStates($orders) : [],
            'statuses'   => self::orderStatusesByCode($statuses),
            'definition' => $definition,
            'state'      => $state,
            'paging'     => $paging,
            'view'       => $view,
            'views'      => OrderListing::VIEWS,
            'actionBase' => $this->orderListBase(),
        ]);
        // The fragment owns its header slots (financial.md «fragment slots»): «+ Neuer Auftrag» is the
        // entry's most frequent action → the action cell (hc1); the view tabs → the toolbar (hc2).
        // «Buchungsdruck …» is bound to the row selection → the bar above the list. A FETCH of the
        // region (sort, page, search) wants the list alone.
        if (!$this->orderIsFetch()) {
            $this->layoutManager->addPartials('act', 'Backend/OrderController', self::ORDER_NS, 'hc1');
            $this->layoutManager->addPartials('toolbar', 'Backend/OrderController', self::ORDER_NS, 'hc2');
        }

        return $response;
    }

    // ── the order window ─────────────────────────────────────────────────

    #[Csrf]
    protected function addAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        return $this->orderWindow(null);
    }

    #[Csrf]
    protected function editAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $order = $this->orderOfRequest();
        if ($order === null) {
            return $this->orderRefuse('Auftrag nicht gefunden');
        }

        return $this->orderWindow($order);
    }

    /**
     * The order window (ADR-047): GET renders it, POST saves through {@see OrderService} —
     * `create()` for a new order, `update()` with the VERSION the window was rendered from. Fetched,
     * a save answers with commands: the saved order into the SAME window (`open-window`, replace)
     * and the list region it was opened from reloads (a new row, a changed total). A refusal
     * re-renders the window with the field errors and what was typed. Without the script it is a
     * page: a plain form (`#[Csrf]`), redirect to the saved order.
     */
    private function orderWindow(?SalesOrder $order): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $service = $this->orderService();
        $form    = new OrderForm();
        $version = $order?->getVersion();
        if (!$request->isPost()) {
            $contactId = ((int) $request->getGetParameter('contact')) ?: null;
            $order === null
                ? $form->startBlank(new \DateTimeImmutable('today'), $contactId, $this->orderDefaultTarget(), $service->statuses()->initial()?->getCode() ?? '', $this->orderStartTerms($contactId),
                    // The debtor carries the price mode (owner 2026-10-18) — a preset customer's starts the order.
                    $this->orderDebtorProfile((string) $contactId)?->priceMode()->value ?? 'net')
                : $form->startFrom($order);

            return $this->orderWindowPage($form, $order, $version);
        }

        $post = $request->getPostParameters();
        if ($order !== null && !DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'sales-order', (int) $order->getId())) {
            return $this->orderRefuse(self::ORDER_CONFLICT_MESSAGE);
        }
        $version = (int) ($post['version'] ?? 0);   // what THIS window was rendered from
        $form->fromPost($post);
        // The payment target is not a field of the window (owner 2026-10-18): a new order takes the
        // first active one, an existing order keeps its own — decided here, never posted.
        $form->withTarget($order !== null ? (string) $order->getPaymentTargetCode() : $this->orderDefaultTarget());
        // «+ Artikel», «+ Textzeile», × without the script (core.js «rows» does them in place).
        if ($form->applyOp(is_string($post['op'] ?? null) ? $post['op'] : '')) {
            return $this->orderWindowPage($form, $order, $version);
        }
        // «anpassen» on an address card: its fields, filled with the address it showed (owner 2026-10-18).
        if (in_array($post['op'] ?? '', ['adjust-ship', 'adjust-bill'], true)) {
            $field = $post['op'] === 'adjust-bill' ? 'bill' : 'ship';
            if ($form->header($field) !== OrderForm::ADDRESS_CUSTOM) {
                $form->adjust($field, $this->orderShownAddress($form, $order, $field));
            }

            return $this->orderWindowPage($form, $order, $version);
        }
        $reason       = trim((string) ($post['stock_reason'] ?? ''));
        $confirmation = $reason !== '' ? new StockConfirmation(mb_substr($reason, 0, StockConfirmation::REASON_LENGTH)) : null;
        $needReason   = false;
        $draft        = $form->toDraft($this->orderArticleNumbers($order), $this->orderShipChoices($form->header('contact'), $order),
            null, $this->orderBillChoices($form->header('contact'), $order));
        // No document for a contact without a debtor profile (owner 2026-10-18): a NEW customer
        // of this order must be a debtor — the picker asks «Debitor anlegen» first, this is the
        // re-check (Rule 7). An existing order keeps its customer (older drafts; the booking
        // print still opens a missing profile, D9).
        $notDebtor = $this->orderNotDebtor($form->header('contact'), $order);
        if ($notDebtor !== null) {
            $form->applyErrors(['contact_id' => $notDebtor]);
            $draft = null;
        }
        if ($draft !== null) {
            try {
                if ($order === null) {
                    $saved = $service->create($draft);
                    $text  = 'Auftrag ' . $saved->documentNumber() . ' erfasst';
                } else {
                    $saved = $service->update((int) $order->getId(), $version, $draft, $confirmation);
                    $text  = 'Auftrag ' . $saved->documentNumber() . ' gespeichert';
                }

                return $this->orderSaved((int) $saved->getId(), $text);
            } catch (InvalidOrderException $e) {
                $form->applyErrors($e->errors);
            } catch (OrderConflictException) {
                return $this->orderRefuse(self::ORDER_CONFLICT_MESSAGE);
            } catch (OrderRefusedException $e) {
                $form->addGeneralError($e->getMessage());
            } catch (StockReasonRequiredException) {
                $needReason = true;
                $form->addGeneralError('Diese Änderung bucht bereits ausgelieferten Bestand zurück — einen Grund angeben und nochmals speichern.');
            } catch (\Doctrine\DBAL\Exception $e) {
                if (!LockConflict::is($e)) {
                    throw $e;
                }
                $form->addGeneralError(LockConflict::MESSAGE);
            }
            // A refused unit of work may have replaced the EntityManager — the order is re-read, display only.
            $order = $order !== null ? ($this->orders()->withLines((int) $order->getId()) ?? $order) : null;
        }

        return $this->orderWindowPage($form, $order, $version, $needReason);
    }

    /**
     * The answer to a save from the window (ADR-047, the invoice's recipe): the saved order into
     * the window the save came from, and the list region it was opened from reloads. Without the
     * script: flash and the order as a page.
     */
    private function orderSaved(int $id, string $flash): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!$this->orderIsFetch()) {
            $this->messageService->pushFlashAfterRedirect('success', $flash);

            return $this->redirect($this->orderListBase() . '/edit?id=' . $id, 303);
        }
        $this->messageService->pushFlash('success', $flash);
        $response = $this->fetch()->setStatus('success')
            ->addCommand('open-window', ['url' => $this->orderListBase() . '/edit?id=' . $id, 'replace' => true]);
        $origin = WindowOrigin::of($request);
        if (str_starts_with($origin, 'region:')) {
            $response->addCommand('refresh-region', ['name' => substr($origin, strlen('region:'))]);
        }

        return $response;
    }

    /** The window with its pickers, the preview's totals, the status log and the invoice. */
    private function orderWindowPage(OrderForm $form, ?SalesOrder $order, ?int $version, bool $needReason = false): HtmlResponse
    {
        $service  = $this->orderService();
        $statuses = $service->statuses();
        $status   = $order !== null ? $statuses->byCode($order->getStatusCode()) : null;
        $refusal  = $order !== null ? $service->editRefusal($order) : null;

        // The totals and what the booking print would refuse — debtor's preview of the STORED order (D11).
        $preview = null;
        $previewProblems = [];
        if ($order !== null && $order->getInvoice() === null) {
            try {
                $preview = $service->preview($order);
                if ($preview->getPayment()->hasPaymentPart()) {
                    $previewProblems = (new InvoicingService($this->em()))->bookingBill($preview)->problems();
                }
            } catch (DebtorException | VatException $e) {
                $previewProblems = [$e->getMessage()];
            }
        }

        $invoice     = $order?->getInvoice();
        /** @var InvoiceRepository $invoices */
        $invoices    = $this->em()->getRepository(Invoice::class);
        $variants    = $this->orderVariants()->selectable();
        $this->help->attach('Backend/OrderController/edit.help', self::ORDER_NS, ['isNew' => $order === null], 'Hilfe: Auftrag');

        return $this->orderPage('edit', [
            'window'          => $this->orderIsFetch(),
            // Wide enough for the ten-column line row — the window must not scroll sideways.
            'windowWidth'     => '78rem',
            'origin'          => WindowOrigin::of(DI::getRequest()),
            'form'            => $form,
            // The hash of the state the window was LOADED with, handed back through a POST that
            // re-renders without saving (more rows, «anpassen», errors) — core.js «dirty forms»
            // compares with it, so a field set back is clean again (owner 2026-10-18).
            'dirtyBase'       => preg_match('/^[0-9a-f]{8}$/', (string) (DI::getRequest()->getPostParameters()['_dirty_base'] ?? '')) === 1 ? (string) DI::getRequest()->getPostParameters()['_dirty_base'] : '',
            'order'           => $order,
            'version'         => $version,
            'status'          => $status,
            'statusLabels'    => self::orderStatusLabels($statuses->all()),
            'startable'       => $order === null ? $statuses->startable() : [],
            'editRefusal'     => $refusal,
            'needReason'      => $needReason,
            'entityCsrf'      => $order === null ? '' : DI::getCsrfService()->generateEntityToken('sales-order', (int) $order->getId()),
            // The customer: debtor's field with «Kunde wählen …» — the searching picker (owner 2026-10-15).
            'customerField'   => CustomerPicker::fieldHtml($this->em(), $form->header('contact'), $this->orderListBase() . '/customer-pick',
                $this->orderListBase() . ($order === null ? '/add' : '/edit?id=' . $order->getId()), $form->error('contact') !== '',
                showNumber: $this->customerFieldShowsNumber()),
            // Block (1)'s title «Kunde 20417» + phone — the number sits here, not under the name.
            'customerTitle'   => $this->orderCustomerTitle($form->header('contact')),
            // Block (3): «Zahlungskonditionen» = debtor's PaymentTerms (owner 2026-10-18), '' = the customer's.
            'termsSelect'     => $this->orderTermsSelect($form->header('terms'), $form->header('contact'), $form->error('terms') !== ''),
            'debtorEdit'      => $this->orderDebtorEdit($form->header('contact')),
            'ships'          => $this->orderShipLabels($form->header('contact'), $order) + $this->orderCustomLabel($form, 'ship'),
            'bills'           => $this->orderBillLabels($form->header('contact'), $order) + $this->orderCustomLabel($form, 'bill'),
            // Badge «angepasst»: the stored snapshot is none of the customer's addresses as they read now.
            'shipAdjusted'    => $this->orderAddressAdjusted($order?->deliveryAddress(), $form->header('contact')),
            'billAdjusted'    => $this->orderAddressAdjusted($order?->billingAddress(), $form->header('contact')),
            // The cards show the address of the CURRENT choice, saved or not, and switch with it (owner 2026-10-18).
            'billCases'       => $this->orderAddressCases('bill', $form->header('contact'), $order, $form),
            'shipCases'       => $this->orderAddressCases('ship', $form->header('contact'), $order, $form),
            'taxCodes'        => $taxCodes = $this->em()->getRepository(TaxCode::class)->selectable($form->keptCodes()),
            // The window shows a line's VAT as its RATE («8.1 %», owner 2026-10-16), not the code.
            'taxRates'        => $this->orderTaxRates($taxCodes, $form->header('service_from'), $form->header('order_date')),
            // Every rate of the shown codes with its start — core.js «dated rates» re-labels the rows
            // when «Leistung von» / the order date change (owner 2026-10-18).
            'rateSchedule'    => $this->orderRateSchedule($taxCodes),
            'articles'        => $this->orderArticleInfo($variants, $order),
            'preview'         => $preview,
            // The totals' currency before there is a preview (a new order, a refused preview).
            'currency'        => $order?->getCurrency() ?? DebtorCurrency::base(),
            'previewProblems' => $previewProblems,
            'history'         => $order !== null ? $this->orderHistory()->historyOf($order) : [],
            'triggers'        => self::ORDER_TRIGGERS,
            'invoice'         => $invoice,
            'payment'         => $invoice !== null ? ((new PaymentStates($this->em()))->of([(int) $invoice->getId()])[(int) $invoice->getId()] ?? null) : null,
            'creditNotes'     => $invoice !== null ? $invoices->creditNotesOf($invoice) : [],
            'invoiceBase'     => $this->orderInvoiceBase(),
            'orderTitle'      => OrderPdf::orderTitle($status),
            'canBook'         => $order !== null && $invoice === null && $status !== null && $status->getPhase()->isBookable(),
            'phase'           => $status?->getPhase(),
        ]);
    }

    /**
     * Code → rate as shown («8.1 %») on the order date (today when the date is not valid yet);
     * a code without a rate in effect is left out — the line then shows the code.
     *
     * @param list<TaxCode> $codes
     * @return array<string, string>
     */
    private function orderTaxRates(array $codes, string $serviceFrom, string $orderDate): array
    {
        // The SERVICE date decides the rate (owner 2026-10-18, as the save and the booking print):
        // «Leistung von», else the order date.
        $date  = \DateTimeImmutable::createFromFormat('!Y-m-d', $serviceFrom)
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d', $orderDate)
            ?: new \DateTimeImmutable('today');
        $rates = VatRates::from($this->em());
        $shown = [];
        foreach ($codes as $code) {
            try {
                $shown[$code->getCode()] = TaxRate::formatPercent($rates->resolve($code->getCode(), $date)->rate) . ' %';
            } catch (VatException) {
                // no rate in effect on that day — the code stands in
            }
        }

        return $shown;
    }

    /**
     * The rate history of $codes for core.js «dated rates»: code → [[valid from 'Y-m-d', rate in
     * percent as shown («8.1»)], …], oldest first.
     *
     * @param list<TaxCode> $codes
     * @return array<string, list<array{0: string, 1: string}>>
     */
    private function orderRateSchedule(array $codes): array
    {
        $wanted = array_flip(array_map(static fn(TaxCode $c) => $c->getCode(), $codes));
        /** @var \Z77\Module\Vat\Repositories\TaxRateRepository $repo */
        $repo     = $this->em()->getRepository(TaxRate::class);
        $schedule = [];
        foreach ($repo->allGroupedByCode() as $code => $rows) {
            if (!isset($wanted[$code])) {
                continue;
            }
            foreach (array_reverse($rows) as $row) {
                $schedule[$code][] = [$row->getValidFrom(), TaxRate::formatPercent($row->getRate())];
            }
        }

        return $schedule;
    }

    // ── article chosen on a line ─────────────────────────────────────────

    /**
     * The article picker of a line row (`data-fill-url`, core.js «fill»): what the line takes from
     * the article — text, unit, the price at the order date in the order's price mode, tax code and
     * account (`OrderService::articleDefaults()`, the save's own rule) — so the row shows them as
     * soon as the article is chosen (owner 2026-10-15: «Einheit, Text, Preis, MWST werden nicht
     * übernommen»). Tax code and account are locked: on an article line they are the article's
     * (D3). Reads only; the save still decides — without the script the row fills at save as before.
     */
    protected function articleDefaultsAction(): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $number  = trim((string) $request->getGetParameter('value'));
        if ($number === '') {
            return $this->fetch()->setStatus('success')->setData(['lock' => ['tax_code' => false, 'account' => false]]);
        }
        $defaults = $this->orderService()->articleDefaults(
            $number,
            (string) $request->getGetParameter('order_date'),
            (string) $request->getGetParameter('service_from'),
            (string) $request->getGetParameter('price_mode'),
        );
        if ($defaults === null) {
            return $this->orderRefuse('Den Artikel «' . $number . '» gibt es nicht — Nummer aus der Liste wählen.');
        }
        // core.js «price mode» asks only for the article's price in both modes (`prices=1`, owner
        // 2026-10-18): a price-mode switch re-converts the row from them — no flash, nothing filled.
        if ((string) $request->getGetParameter('prices') === '1') {
            return $this->fetch()->setStatus('success')->setData(['prices' => $defaults['prices']]);
        }
        if ($defaults['problem'] !== '') {
            $this->messageService->pushFlash('error', $defaults['problem']);   // the rest still fills; the price is typed
        }
        // Every key is sent, an empty one too: a changed article replaces ALL of the old article's
        // values — without a price the field is emptied, never left at the old article's price
        // (owner's browser test 2026-10-16).
        $fill = ['text' => $defaults['text'], 'unit' => $defaults['unit'], 'price' => $defaults['price'], 'tax_code' => $defaults['taxCode'], 'account' => $defaults['account']];

        return $this->fetch()->setStatus('success')->setData(['fill' => $fill, 'lock' => ['tax_code' => true, 'account' => true]]);
    }

    // ── «+ Kontakt» ──────────────────────────────────────────────────────

    /**
     * «+ Kontakt» in the order window (plan §9.4, answer Q4): a NEW contact with its first
     * address, without leaving the half-filled order — debtor's contact window reused as it is
     * (template `Backend/InvoiceController/newContact`, module-contact's `ContactForm`; Rule 9),
     * posting here. The save answers like a choice in the customer picker
     * (`customerChosenAnswer()`: the customer field `#invoice-contact` and the delivery addresses
     * replaced IN THE ORDER WINDOW, scoped to the origin) and closes this window — also when it was
     * loaded into the picker («+ Neuer Kontakt»). Since 2026-10-18 (owner: no document for a
     * contact without a debtor profile) the save answers «Debitor anlegen» in this window first
     * (debtor's `customerDebtorPage()`); only the created debtor fills the order window. Without
     * the script: the same dialog as a page, then a new order for it.
     */
    #[Csrf]
    protected function newContactAction(): HtmlResponse|RedirectResponse|FetchResponse
    {
        $request = DI::getRequest();
        $form    = new ContactForm(addressRequired: true);
        if ($request->isPost()) {
            $form->fromPost($request->getPostParameters());
            if ($form->save(new ContactService($this->em()))) {
                // The new contact becomes the customer only as a DEBTOR (owner 2026-10-18): «Debitor anlegen» follows.
                $contact = $form->contact;
                $text    = 'Kontakt «' . $contact->displayName() . '» erfasst — jetzt als Debitor anlegen';
                if (!$this->orderIsFetch()) {
                    $this->messageService->pushFlashAfterRedirect('success', $text);

                    return $this->redirect($this->orderListBase() . '/customer-debtor?' . http_build_query(['contact' => $contact->getId(), 'back' => $this->orderListBase() . '/add']), 303);
                }
                $this->messageService->pushFlash('success', $text);

                return $this->customerDebtorPage($contact, WindowOrigin::of($request), $this->orderListBase() . '/add');
            }
        }

        return $this->orderPage('newContact', [
            'window' => $this->orderIsFetch(),
            'origin' => WindowOrigin::of($request),
            'failed' => $form->hasErrors(),
        ] + $form->vars(AddressTypes::from($this->em())), 'Backend/InvoiceController', 'Z77\\Module\\Debtor');
    }

    // ── «Kunde wählen …» (debtor's CustomerPickerTrait) ──────────────────

    /** The picker lives at the order window's root (`/backend/order/order/customer-pick`). */
    protected function customerPickBase(): string
    {
        return $this->orderListBase();
    }

    /**
     * After a choice the order window's delivery-address select shows THAT customer's addresses
     * (usability review 2026-10-15, C2 — before, the choices came only after a save); the choice
     * starts at «wie Rechnungsadresse».
     */
    protected function customerChosenCommands(FetchResponse $response, Contact $contact, string $origin): void
    {
        $renderer = new TemplateRenderer(self::ORDER_NS);
        $ships = $renderer->partial('Backend/OrderController/_ship', [
            'ships'    => $this->orderShipLabels((string) $contact->getId(), null),
            'selected' => '',
            'invalid'  => false,
        ]);
        $response->addCommand('replace-html', ['target' => '#order-ship', 'html' => $ships, 'origin' => $origin]);
        // The billing address starts at the customer's own as well (2026-10-17).
        $bills = $renderer->partial('Backend/OrderController/_ship', [
            'ships'    => $this->orderBillLabels((string) $contact->getId(), null),
            'selected' => '',
            'invalid'  => false,
            'field'    => 'bill',
        ]);
        $response->addCommand('replace-html', ['target' => '#order-bill', 'html' => $bills, 'origin' => $origin]);
        // … and the cards' addresses — THIS customer's, the select switches them (2026-10-18).
        foreach (['bill', 'ship'] as $field) {
            $cases = $renderer->partial('Backend/OrderController/_addressCases', [
                'field'    => $field,
                'cases'    => $this->orderAddressCases($field, (string) $contact->getId(), null, null),
                'selected' => '',
                'adjusted' => false,
                'fields'   => '',
            ]);
            $response->addCommand('replace-html', ['target' => '#order-' . $field . '-cases', 'html' => $cases, 'origin' => $origin]);
        }
        // The payment terms start at «wie beim Kunden» — the option names THIS customer's terms (2026-10-18).
        $response->addCommand('replace-html', ['target' => '[data-fetch-region="order-terms"]', 'html' => $this->orderTermsSelect('', (string) $contact->getId(), false), 'origin' => $origin]);
        $response->addCommand('replace-html', ['target' => '#order-debtor-edit', 'html' => $this->orderDebtorEdit((string) $contact->getId()), 'origin' => $origin]);
        // The block title carries the new customer's number and phone.
        $response->addCommand('replace-html', ['target' => '#order-customer-title', 'html' => $this->orderCustomerTitle((string) $contact->getId()), 'origin' => $origin]);
    }

    /** The order window shows the customer number in block (1)'s title, not under the name. */
    protected function customerFieldShowsNumber(): bool
    {
        return false;
    }

    /**
     * Block (1)'s title (`_customerTitle`): «Kunde», the debtor profile's customer number and the
     * contact's phone — just «Kunde» without a customer.
     */
    private function orderCustomerTitle(string $contactId): string
    {
        $contact = ctype_digit($contactId) && (int) $contactId > 0 ? $this->em()->getRepository(Contact::class)->find((int) $contactId) : null;
        /** @var DebtorProfileRepository $profiles */
        $profiles = $this->em()->getRepository(DebtorProfile::class);
        $profile  = $contact !== null ? $profiles->findByContact($contact) : null;

        return (new TemplateRenderer(self::ORDER_NS))->partial('Backend/OrderController/_customerTitle', [
            'number' => $profile !== null ? (string) $profile->getCustomerNumber() : '',
            'phone'  => $contact !== null ? (string) $contact->getPhone() : '',
        ]);
    }

    // ── «Status ändern …» ────────────────────────────────────────────────

    /**
     * «Status ändern …» (plan §6 / §9.4), loaded into the order window (`data-window-link`): the
     * targets `OrderStatuses::targets()` allows (forward / lateral; backward only without an
     * invoice; never the booked one; active only) as a select. POST → `changeStatus()` with the
     * version shown; the stock seam may demand a reason (consumed stock would come back) — then
     * the form comes back with a mandatory «Grund» and is sent again with the confirmation.
     * Success: the order into the same window, the list reloads.
     */
    #[Csrf]
    protected function statusAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $order   = $this->orderOfRequest();
        if ($order === null) {
            return $this->orderRefuse('Auftrag nicht gefunden');
        }
        $service    = $this->orderService();
        $statuses   = $service->statuses();
        $needReason = false;
        $error      = '';
        $selected   = '';
        $reason     = '';
        if ($request->isPost()) {
            $post = $request->getPostParameters();
            if (!DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'sales-order', (int) $order->getId())) {
                return $this->orderRefuse(self::ORDER_CONFLICT_MESSAGE);
            }
            $selected = trim((string) ($post['to'] ?? ''));
            $reason   = trim((string) ($post['stock_reason'] ?? ''));
            try {
                $moved = $service->changeStatus((int) $order->getId(), (int) ($post['version'] ?? 0), $selected,
                    $reason !== '' ? new StockConfirmation(mb_substr($reason, 0, StockConfirmation::REASON_LENGTH)) : null);
                $label = $statuses->byCode($moved->getStatusCode())?->getLabel() ?? $moved->getStatusCode();

                return $this->orderSaved((int) $moved->getId(), 'Auftrag ' . $moved->documentNumber() . ': Status «' . $label . '»');
            } catch (OrderConflictException) {
                return $this->orderRefuse(self::ORDER_CONFLICT_MESSAGE);
            } catch (StockReasonRequiredException) {
                $needReason = true;
                $error      = 'Dieser Wechsel bucht bereits ausgelieferten Bestand zurück — bitte den Grund angeben.';
            } catch (OrderException $e) {
                $error = $e->getMessage();
            } catch (\Doctrine\DBAL\Exception $e) {
                if (!LockConflict::is($e)) {
                    throw $e;
                }
                $error = LockConflict::MESSAGE;
            }
            $order = $this->orders()->withLines((int) $order->getId()) ?? $order;
        }
        $current = $statuses->byCode($order->getStatusCode());

        return $this->orderPage('status', [
            'window'     => $this->orderIsFetch(),
            'origin'     => WindowOrigin::of($request),
            'order'      => $order,
            'current'    => $current,
            'targets'    => $current !== null ? $statuses->targets($current, $order->getInvoice() !== null) : [],
            'selected'   => $selected,
            'reason'     => $reason,
            'needReason' => $needReason,
            'error'      => $error,
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('sales-order', (int) $order->getId()),
        ]);
    }

    // ── PDFs ─────────────────────────────────────────────────────────────

    /**
     * «Offerte» / «Auftragsbestätigung» (plan §10) — by the PHASE of the order's status (`new` = «Offerte»);
     * lines and totals from debtor's preview. Inline, a new tab, no side effect. When debtor's
     * preview refuses the order as it stands, a message page says what to fix instead.
     */
    protected function pdfAction(): BytesResponse|HtmlResponse|RedirectResponse
    {
        $order = $this->orderOfRequest();
        if ($order === null) {
            return $this->orderRefuse('Auftrag nicht gefunden');
        }
        $service = $this->orderService();
        $status  = $service->statuses()->byCode($order->getStatusCode());
        $title   = OrderPdf::orderTitle($status);
        try {
            $preview = $service->preview($order);
        } catch (DebtorException | VatException $e) {
            return $this->orderPrintRefused($title . ' ' . $order->documentNumber() . ' lässt sich noch nicht drucken: ' . $e->getMessage(), $order);
        }

        return $this->bytes(OrderPdf::order($order, $status, $preview, $this->em())->output(), OrderPdf::fileName($title . ' ' . $order->documentNumber()), 'application/pdf');
    }

    /**
     * «Rechnungsvorschau» (plan §10) — the invoice the booking print would issue TODAY, from debtor's
     * `preview()` drawn with debtor's PREVIEW layout («VORSCHAU», no number, no payment part). No
     * side effect.
     */
    protected function invoicePreviewAction(): BytesResponse|HtmlResponse|RedirectResponse
    {
        $order = $this->orderOfRequest();
        if ($order === null) {
            return $this->orderRefuse('Auftrag nicht gefunden');
        }
        try {
            $preview = $this->orderService()->preview($order, new \DateTimeImmutable('today'));
        } catch (DebtorException | VatException $e) {
            return $this->orderPrintRefused('Die Rechnung zu Auftrag ' . $order->documentNumber() . ' lässt sich noch nicht zeigen: ' . $e->getMessage(), $order);
        }

        return $this->bytes(InvoicePdf::of($preview, $this->em())->output(), Naming::toSlug('Vorschau Rechnung Auftrag ' . $order->documentNumber()) . '.pdf', 'application/pdf');
    }

    /**
     * «Lieferschein …» (D6), loaded into the order window: what the print will do — move the order
     * into `delivered` (its default status), or print again without a change — and one button that
     * POSTs into a NEW TAB. When the status moves, the form carries `data-submit-navigate` to the
     * list (the window would be stale; core.js, SUBMIT-NAV-001); a reprint leaves the window open.
     */
    protected function confirmDeliveryNoteAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        return $this->orderConfirmPrint('delivery-note', OrderPhase::Delivered);
    }

    /**
     * «Auftragsbestätigung …» (ADR-043 addendum 2026-10-15) — offered for a quote (phase `new`): the
     * print sets the default status of `confirmed`. A confirmed order prints its confirmation as a
     * plain PDF (`pdf`), no window needed.
     */
    protected function confirmConfirmationAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        return $this->orderConfirmPrint('confirmation', OrderPhase::Confirmed);
    }

    /** The confirm window of a print that moves the order into $phase — or says it is a reprint. */
    private function orderConfirmPrint(string $document, OrderPhase $phase): HtmlResponse|FetchResponse|RedirectResponse
    {
        $order = $this->orderOfRequest();
        if ($order === null) {
            return $this->orderRefuse('Auftrag nicht gefunden');
        }
        $statuses = $this->orderService()->statuses();
        $current  = $statuses->byCode($order->getStatusCode());
        if ($current !== null) {
            try {
                OrderService::assertPrintable($order, $current, $phase);
            } catch (OrderRefusedException $e) {
                return $this->orderRefuse($e->getMessage());
            }
        }

        return $this->orderPage('confirmPrint', [
            'window'   => $this->orderIsFetch(),
            'origin'   => WindowOrigin::of(DI::getRequest()),
            'order'    => $order,
            'document' => $document,
            'current'  => $current,
            'target'   => $current !== null ? $statuses->actionTarget($current, $phase) : null,
            'address'  => $document === 'delivery-note' ? $this->orderService()->deliveryAddressOf($order) : null,
        ]);
    }

    /**
     * The delivery note (POST, `#[Csrf]`, into a new tab): `printDeliveryNote()` FIRST — the
     * order moves into `delivered` when it stands before it, a reprint writes nothing — then the
     * PDF. A refusal (a stale version, a reason the stock demands) answers a message page in that tab.
     */
    #[Csrf]
    protected function deliveryNoteAction(): BytesResponse|HtmlResponse|RedirectResponse
    {
        $order = $this->orderMovingPrint('Lieferschein', fn(int $id, int $version) => $this->orderService()->printDeliveryNote($id, $version));
        if (!$order instanceof SalesOrder) {
            return $order;
        }

        return $this->bytes(OrderPdf::deliveryNote($order, $this->orderService()->deliveryAddressOf($order), $this->em())->output(), OrderPdf::fileName('Lieferschein ' . $order->documentNumber()), 'application/pdf');
    }

    /**
     * The order confirmation (POST, `#[Csrf]`, into a new tab): `printConfirmation()` FIRST — a
     * quote moves into `confirmed`, a reprint writes nothing — then the PDF «Auftragsbestätigung».
     */
    #[Csrf]
    protected function confirmationAction(): BytesResponse|HtmlResponse|RedirectResponse
    {
        $order = $this->orderMovingPrint('Auftragsbestätigung', fn(int $id, int $version) => $this->orderService()->printConfirmation($id, $version));
        if (!$order instanceof SalesOrder) {
            return $order;
        }
        $service = $this->orderService();
        $status  = $service->statuses()->byCode($order->getStatusCode());
        $title   = OrderPdf::orderTitle($status);
        try {
            $preview = $service->preview($order);
        } catch (DebtorException | VatException $e) {
            return $this->orderPrintRefused($title . ' ' . $order->documentNumber() . ' lässt sich noch nicht drucken: ' . $e->getMessage(), $order);
        }

        return $this->bytes(OrderPdf::order($order, $status, $preview, $this->em())->output(), OrderPdf::fileName($title . ' ' . $order->documentNumber()), 'application/pdf');
    }

    /**
     * The service side of a print that moves the order, from the confirm window's POST — the
     * order re-read with its lines, or the message page in the new tab.
     *
     * @param \Closure(int, int): SalesOrder $print
     */
    private function orderMovingPrint(string $document, \Closure $print): SalesOrder|HtmlResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $id      = (int) $request->getGetParameter('id');
        if ($id <= 0 || !$request->isPost()) {
            return $this->redirect($this->orderListBase() . '/list', 303);
        }
        $post = $request->getPostParameters();
        try {
            $order = $print($id, (int) ($post['version'] ?? 0));
        } catch (OrderConflictException) {
            return $this->orderPrintRefused('Der Auftrag wurde inzwischen geändert — ' . $document . ' nicht gedruckt, nichts gespeichert. Auftrag neu öffnen und nochmals drucken.', null);
        } catch (StockReasonRequiredException) {
            return $this->orderPrintRefused('Der Druck würde bereits ausgelieferten Bestand zurückbuchen — nichts gespeichert. Den Status über «Status ändern …» mit Grund setzen.', null);
        } catch (OrderException $e) {
            return $this->orderPrintRefused($e->getMessage(), null);
        } catch (\Doctrine\DBAL\Exception $e) {
            if (!LockConflict::is($e)) {
                throw $e;
            }

            return $this->orderPrintRefused(LockConflict::MESSAGE, null);
        }

        return $this->orders()->withLines((int) $order->getId()) ?? $order;
    }

    /** A print that cannot be made — a PAGE (in the new tab the link or form opened). */
    private function orderPrintRefused(string $message, ?SalesOrder $order): HtmlResponse
    {
        return $this->orderPage('printRefused', ['message' => $message, 'order' => $order]);
    }

    // ── the booking print ────────────────────────────────────────────────

    /**
     * «Buchungsdruck …» (plan §7 / §9.3): the selection of «Termin» / «Geliefert» or the one order of the
     * window (`order[]` = `{id}:{version}`, the version each SHOWED), listed for a last look with
     * the invoice date (default today) — a PAGE (the DEBTOR-WIN-002 pattern: the selection bar is
     * a GET form) whose button POSTs the same pairs into a NEW TAB ({@see bookAction()}). Orders
     * that changed since, are booked or not invoiceable are named and left out of the button.
     */
    protected function confirmBookAction(): HtmlResponse|RedirectResponse
    {
        $pairs = BookingSelection::pairs(DI::getRequest()->getGetParameter('order'));
        if ($pairs === []) {
            $this->messageService->pushFlashAfterRedirect('error', 'Kein Auftrag ausgewählt — in der Liste «Termin» oder «Geliefert» ankreuzen.');

            return $this->redirect($this->orderListBase() . '/list?view=' . OrderListing::VIEW_DELIVERED, 303);
        }
        $statuses = $this->orderService()->statuses();
        $orders   = [];
        $stale    = [];
        foreach ($pairs as $pair) {
            $order  = $this->orders()->find($pair['id']);
            $status = $order !== null ? $statuses->byCode($order->getStatusCode()) : null;
            if ($order === null || $order->getVersion() !== $pair['version'] || $order->getInvoice() !== null || $status === null || !$status->getPhase()->isBookable()) {
                $stale[] = $order !== null ? 'Auftrag ' . $order->documentNumber() : '#' . $pair['id'];
                continue;
            }
            $orders[] = $order;
        }
        $total = null;
        foreach ($orders as $order) {
            if ($order->getTotalGross() !== null) {
                $total = $total === null ? $order->getTotalGross() : $total->add($order->getTotalGross());
            }
        }

        return $this->orderPage('confirmBook', [
            'orders' => $orders,
            'stale'  => $stale,
            'total'  => $total,
            'today'  => new \DateTimeImmutable('today'),
            'booked' => $statuses->defaultOf(OrderPhase::Closed),
        ]);
    }

    /**
     * «Buchungsdruck» — the POST of the confirmation (`#[Csrf]`, into a NEW TAB): the pairs and the
     * invoice date to `OrderService::book()` — each order's invoice issued and booked, linked, the
     * order set to the default status of `closed`, ONE unit of work — and the answer IS the PDF of the
     * booked invoices with number and QR-bill (`InvoicePdf::combined()`). The success flash waits
     * for the next page of the original tab (`data-submit-navigate` sends it to «Abgeschlossen»).
     *
     * IDEMPOTENT for a repeated submit (plan §7): when every order of the selection is linked to a
     * FINAL invoice at exactly the version it showed + 1 — what this booking print left — the same
     * PDF is answered and nothing is booked. Every refusal answers a German message PAGE naming
     * the order; nothing was booked.
     */
    #[Csrf]
    protected function bookAction(): BytesResponse|HtmlResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $post    = $request->isPost() ? $request->getPostParameters() : [];
        $pairs   = BookingSelection::pairs($post['order'] ?? null);
        if ($pairs === []) {
            return $this->redirect($this->orderListBase() . '/list?view=' . OrderListing::VIEW_DELIVERED, 303);
        }
        $booked = BookingSelection::bookedBy($pairs, fn(int $id) => $this->orders()->find($id));
        if ($booked !== null) {
            return $this->orderBookingPdf($booked);   // the same selection again: reprint, nothing booked
        }
        $date = self::orderDate((string) ($post['invoice_date'] ?? ''));
        if ($date === null) {
            return $this->orderBookingRefused('Das Rechnungsdatum fehlt oder ist kein gültiges Datum — nichts wurde verbucht.', $pairs);
        }
        $reason = trim((string) ($post['stock_reason'] ?? ''));
        try {
            $invoices = $this->orderService()->book($pairs, $date, $reason !== '' ? new StockConfirmation(mb_substr($reason, 0, StockConfirmation::REASON_LENGTH)) : null);
        } catch (OrderConflictException $e) {
            $order = $this->orders()->find($e->orderId);
            return $this->orderBookingRefused(($order !== null ? 'Auftrag ' . $order->documentNumber() : 'Ein Auftrag der Auswahl') . ' wurde inzwischen geändert oder ist nicht mehr vorhanden — nichts wurde verbucht. Liste neu laden und die Auswahl prüfen.', $pairs);
        } catch (StockReasonRequiredException) {
            return $this->orderBookingRefused('Der Buchungsdruck würde bereits ausgelieferten Bestand zurückbuchen — nichts wurde verbucht. Den Status der Aufträge prüfen.', $pairs);
        } catch (OrderException | DebtorException $e) {
            return $this->orderBookingRefused('Nichts wurde verbucht: ' . $e->getMessage(), $pairs);
        } catch (\Doctrine\DBAL\Exception $e) {
            // The database refused the unit of work (a deadlock, a lock wait, a unique index at commit): everything
            // rolled back. Answered as the refusal page in the new tab, never a 500; the cause goes to the log.
            error_log('order booking print: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->orderBookingRefused(LockConflict::is($e)
                ? 'Nichts wurde verbucht: ' . LockConflict::MESSAGE
                : 'Nichts wurde verbucht: die Datenbank hat den Buchungsdruck abgelehnt. Liste neu laden und nochmals versuchen; bleibt es dabei, steht die Ursache im Fehlerprotokoll.', $pairs);
        }
        $names = implode(', ', array_map(static fn(Invoice $i) => $i->documentName(), $invoices));
        $this->messageService->pushFlashAfterRedirect('success', (count($pairs) === 1 ? 'Auftrag verbucht und gedruckt: ' : count($pairs) . ' Aufträge verbucht und gedruckt: ') . $names);

        return $this->orderBookingPdf(array_map(static fn(Invoice $i) => (int) $i->getId(), $invoices));
    }

    /** @param list<int> $invoiceIds */
    private function orderBookingPdf(array $invoiceIds): BytesResponse
    {
        /** @var InvoiceRepository $invoices */
        $invoices  = $this->em()->getRepository(Invoice::class);
        $documents = array_values(array_filter(array_map(static fn(int $id) => $invoices->withLines($id), $invoiceIds)));
        usort($documents, static fn(Invoice $a, Invoice $b) => $a->getNumber() <=> $b->getNumber());

        return $this->bytes(InvoicePdf::combined($documents, $this->em())->output(), InvoicePdf::combinedFileName($documents), 'application/pdf');
    }

    /**
     * The refusal of a booking print — a PAGE in the new tab: what happened (nothing was booked),
     * the orders of the selection that ARE booked by now, the way back to the lists.
     *
     * @param list<array{id: int, version: int}> $pairs
     */
    private function orderBookingRefused(string $message, array $pairs): HtmlResponse
    {
        $already = [];
        foreach ($pairs as $pair) {
            $order = $this->orders()->find($pair['id']);
            if ($order !== null && $order->getInvoice() !== null) {
                $already[] = 'Auftrag ' . $order->documentNumber() . ' (' . $order->getInvoice()->documentName() . ')';
            }
        }

        return $this->orderPage('bookingRefused', ['message' => $message, 'already' => $already]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function orderVariants(): VariantRepository
    {
        return $this->em()->getRepository(Variant::class);
    }

    private function orderHistory(): SalesOrderStatusChangeRepository
    {
        return $this->em()->getRepository(SalesOrderStatusChange::class);
    }

    /**
     * Article number → variant id: the articles a new line may name, plus the ones the order
     * already holds (kept valid although deactivated since — ADR-043 decision 19).
     *
     * @return array<string, int>
     */
    private function orderArticleNumbers(?SalesOrder $order): array
    {
        $map = [];
        foreach ($this->orderVariants()->selectable() as $variant) {
            $map[$variant->getNumber()] = (int) $variant->getId();
        }
        foreach ($order?->getLines() ?? [] as $line) {
            if ($line->getVariant() !== null) {
                $map[$line->getVariant()->getNumber()] = (int) $line->getVariant()->getId();
            }
        }

        return $map;
    }

    /**
     * What the window shows per article number: the picker's label and, on an article line, the
     * tax code and account that come from the article (D3).
     *
     * @param list<Variant> $variants
     * @return array<string, array{name: string, unit: string, taxCode: string, account: string, offered: bool}>
     */
    private function orderArticleInfo(array $variants, ?SalesOrder $order): array
    {
        $info = [];
        $add  = static function (Variant $v, bool $offered) use (&$info): void {
            $p = $v->getProduct();
            $info[$v->getNumber()] = [
                'name'    => $v->displayName(),
                'unit'    => $v->getUnit(),
                'taxCode' => $p->getTaxCode(),
                'account' => $p->getRevenueAccount(),
                'offered' => $offered,
            ];
        };
        foreach ($order?->getLines() ?? [] as $line) {
            if ($line->getVariant() !== null) {
                $add($line->getVariant(), false);
            }
        }
        foreach ($variants as $variant) {
            $add($variant, true);
        }

        return $info;
    }

    /**
     * The delivery-address choices of the customer $contactId: '' = the invoice address (no
     * separate delivery address), «keep» = the snapshot the order holds, `a{link id}` = one of
     * the contact's addresses, copied as a snapshot at save.
     *
     * @return array<string, AddressSnapshot|null>
     */
    private function orderShipChoices(string $contactId, ?SalesOrder $order): array
    {
        $choices = ['' => null];
        if ($order?->deliveryAddress() !== null) {
            $choices[OrderForm::SHIP_KEEP] = $order->deliveryAddress();
        }
        foreach ($this->orderContactAddresses($contactId) as $link) {
            $choices['a' . $link->getId()] = AddressSnapshot::of($link->getAddress());
        }

        return $choices;
    }

    /** @return array<string, string> the same choices as German labels for the select */
    private function orderShipLabels(string $contactId, ?SalesOrder $order): array
    {
        return $this->orderAddressLabels('wie Rechnungsadresse', $order?->deliveryAddress(), $contactId);
    }

    /**
     * The billing-address choices (2026-10-17, the twin of {@see orderShipChoices()}): '' = the
     * customer's invoice address as debtor chooses it at booking, «keep» = the snapshot the order
     * holds, `a{link id}` = one of the contact's addresses, copied as a snapshot at save.
     *
     * @return array<string, AddressSnapshot|null>
     */
    private function orderBillChoices(string $contactId, ?SalesOrder $order): array
    {
        $choices = ['' => null];
        if ($order?->billingAddress() !== null) {
            $choices[OrderForm::SHIP_KEEP] = $order->billingAddress();
        }
        foreach ($this->orderContactAddresses($contactId) as $link) {
            $choices['a' . $link->getId()] = AddressSnapshot::of($link->getAddress());
        }

        return $choices;
    }

    /** @return array<string, string> */
    private function orderBillLabels(string $contactId, ?SalesOrder $order): array
    {
        return $this->orderAddressLabels('Rechnungsadresse des Kunden', $order?->billingAddress(), $contactId);
    }

    /**
     * Short labels as in the owner's draft — «Hauptadresse · Seestrasse 14, Stäfa»: the type (or
     * «wie gespeichert») and street, city; the card below the select shows the whole address.
     *
     * @return array<string, string>
     */
    private function orderAddressLabels(string $none, ?AddressSnapshot $kept, string $contactId): array
    {
        $short = static fn(AddressSnapshot $a): string => trim($a->getStreet() . ' ' . $a->getHouseNo()) . ($a->getCity() !== '' ? ', ' . $a->getCity() : '');
        $labels = ['' => $none];
        if ($kept !== null) {
            $labels[OrderForm::SHIP_KEEP] = 'wie gespeichert · ' . $short($kept);
        }
        $types = AddressTypes::from($this->em());
        foreach ($this->orderContactAddresses($contactId) as $link) {
            $labels['a' . $link->getId()] = $types->labelOf($link->getTypeCode()) . ' · ' . $short(AddressSnapshot::of($link->getAddress()));
        }

        return $labels;
    }

    /**
     * The address a card shows for its current choice — where «anpassen» starts: the chosen one;
     * none chosen = the customer's invoice address (billing) or the billing card's (delivery,
     * «wie Rechnungsadresse»).
     */
    private function orderShownAddress(OrderForm $form, ?SalesOrder $order, string $field): ?AddressSnapshot
    {
        $contactId = $form->header('contact');
        if ($field === 'ship') {
            $ship = $this->orderShipChoices($contactId, $order)[$form->header('ship')] ?? null;

            return $ship ?? ($form->header('bill') === OrderForm::ADDRESS_CUSTOM ? $form->customAddress('bill') : $this->orderShownAddress($form, $order, 'bill'));
        }
        $bill    = $this->orderBillChoices($contactId, $order)[$form->header('bill')] ?? null;
        $contact = ctype_digit($contactId) ? $this->em()->getRepository(Contact::class)->find((int) $contactId) : null;

        return $bill ?? ($contact !== null ? (new InvoicingService($this->em()))->invoiceAddressOf($contact) : null);
    }

    /**
     * Every choice of an address card → the lines it shows (`_addressCases`): a chosen address its
     * own; none chosen = the customer's invoice / main address (billing) or nothing (delivery,
     * «wie Rechnungsadresse»); «angepasst» while the card's fields are open.
     *
     * @return array<string, list<string>>
     */
    private function orderAddressCases(string $field, string $contactId, ?SalesOrder $order, ?OrderForm $form): array
    {
        $choices = $field === 'bill' ? $this->orderBillChoices($contactId, $order) : $this->orderShipChoices($contactId, $order);
        $cases   = [];
        foreach ($choices as $value => $snapshot) {
            $cases[(string) $value] = $snapshot?->lines() ?? [];
        }
        if ($field === 'bill') {
            $contact  = ctype_digit($contactId) ? $this->em()->getRepository(Contact::class)->find((int) $contactId) : null;
            $cases[''] = $contact !== null ? ((new InvoicingService($this->em()))->invoiceAddressOf($contact)?->lines() ?? []) : [];
        }
        if ($form?->header($field) === OrderForm::ADDRESS_CUSTOM) {
            $cases[OrderForm::ADDRESS_CUSTOM] = [];
        }

        return $cases;
    }

    /** @return array<string, string> the «angepasst» entry of a card's select while its fields are open */
    private function orderCustomLabel(OrderForm $form, string $field): array
    {
        return $form->header($field) === OrderForm::ADDRESS_CUSTOM ? [OrderForm::ADDRESS_CUSTOM => 'angepasst'] : [];
    }

    /** Whether a stored snapshot differs from every address the customer has now. */
    private function orderAddressAdjusted(?AddressSnapshot $kept, string $contactId): bool
    {
        if ($kept === null) {
            return false;
        }
        foreach ($this->orderContactAddresses($contactId) as $link) {
            if (AddressSnapshot::of($link->getAddress())->lines() === $kept->lines()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<ContactAddress> */
    private function orderContactAddresses(string $contactId): array
    {
        $contact = ctype_digit($contactId) ? $this->em()->getRepository(Contact::class)->find((int) $contactId) : null;

        return $contact !== null ? $this->em()->getRepository(ContactAddress::class)->findByContact($contact) : [];
    }

    /**
     * The payment terms a new order starts with (owner 2026-10-18): a preset customer's debtor
     * terms — '' («wie beim Kunden») when they are inactive; without a debtor profile or without
     * a customer the terms a new debtor gets (`defaultForNewDebtor()` — debtor.md: never «the
     * first active row»); '' when none is marked.
     */
    private function orderStartTerms(?int $contactId): string
    {
        /** @var PaymentTermsRepository $repo */
        $repo    = $this->em()->getRepository(PaymentTerms::class);
        $profile = $this->orderDebtorProfile((string) $contactId);
        if ($profile !== null) {
            $terms = $repo->findByCode($profile->getPaymentTermsCode());

            return $terms !== null && $terms->isActive() ? $terms->getCode() : '';
        }

        return $repo->defaultForNewDebtor()?->getCode() ?? '';
    }

    /**
     * The «Zahlungskonditionen» select (`_terms`): the active terms plus the one the form holds
     * (kept valid, ADR-043 decision 19). Its first option «wie beim Kunden» names what it means for
     * $contactId — the debtor profile's terms, or for a contact without a profile the terms a new
     * debtor gets (the invoice editor's «neu als Debitor»).
     */
    private function orderTermsSelect(string $selected, string $contactId, bool $invalid): string
    {
        /** @var PaymentTermsRepository $repo */
        $repo     = $this->em()->getRepository(PaymentTerms::class);
        $profile  = $this->orderDebtorProfile($contactId);
        $customer = '';
        if ($profile !== null) {
            $customer = $repo->findByCode($profile->getPaymentTermsCode())?->getLabel() ?? '';
        } elseif (ctype_digit($contactId) && (int) $contactId > 0 && ($default = $repo->defaultForNewDebtor()) !== null) {
            $customer = 'neu als Debitor: ' . $default->getLabel();
        }

        return (new TemplateRenderer(self::ORDER_NS))->partial('Backend/OrderController/_terms', [
            'terms'    => array_values(array_filter($repo->allInOrder(), static fn(PaymentTerms $t) => $t->isActive() || $t->getCode() === $selected)),
            'selected' => $selected,
            'customer' => $customer,
            'invalid'  => $invalid,
            'src'      => $this->orderListBase() . '/terms?' . http_build_query(['contact' => $contactId]),
        ]);
    }

    /**
     * `terms` — block (3)'s «Zahlungskonditionen» region anew (reads only): core.js reloads it when
     * «Debitor anpassen» closes (`data-popup-refresh`), with the current choice as `terms`, so the
     * option «wie beim Kunden — …» names the terms the debtor dialog just saved (owner 2026-10-18).
     */
    protected function termsAction(): HtmlResponse
    {
        $request = DI::getRequest();

        return $this->orderPage('terms', [
            'termsSelect' => $this->orderTermsSelect(trim((string) $request->getGetParameter('terms')), trim((string) $request->getGetParameter('contact')), false),
        ]);
    }

    /**
     * «Debitor anpassen» (`_debtorEdit`): debtor's profile dialog of the customer — empty without a
     * customer or for a contact without a debtor profile (debtor opens one only in the contact
     * editor or with the first invoice / booking print).
     */
    private function orderDebtorEdit(string $contactId): string
    {
        $profile = $this->orderDebtorProfile($contactId);

        return (new TemplateRenderer(self::ORDER_NS))->partial('Backend/OrderController/_debtorEdit', [
            'url' => $profile !== null ? DebtorProfileLayout::BASE . '/edit?id=' . $profile->getId() : '',
        ]);
    }

    /**
     * Why the posted customer may not be saved as this order's customer, or null — debtor's rule
     * (`CustomerPicker::debtorRefusal()`, owner 2026-10-18) for a customer NEW to the order (a new
     * order, or «Kunde wechseln»); the customer the order already holds stays valid.
     */
    private function orderNotDebtor(string $contactId, ?SalesOrder $order): ?string
    {
        $contact = ctype_digit($contactId) && (int) $contactId > 0 ? $this->em()->getRepository(Contact::class)->find((int) $contactId) : null;
        if ($contact === null || ($order !== null && $order->getContact()->getId() === $contact->getId())) {
            return null;   // no customer: the service says «Kunde wählen.»
        }

        return CustomerPicker::debtorRefusal($contact, $this->orderDebtorProfile($contactId));
    }

    /** The debtor profile of a contact id as the form holds it (a string), null = none / no contact. */
    private function orderDebtorProfile(string $contactId): ?DebtorProfile
    {
        $contact = ctype_digit($contactId) && (int) $contactId > 0 ? $this->em()->getRepository(Contact::class)->find((int) $contactId) : null;
        /** @var DebtorProfileRepository $profiles */
        $profiles = $this->em()->getRepository(DebtorProfile::class);

        return $contact !== null ? $profiles->findByContact($contact) : null;
    }

    /** The target a new order starts with: the first ACTIVE one in list order — a preselection the user sees and may change. */
    private function orderDefaultTarget(): string
    {
        foreach ($this->em()->getRepository(PaymentTarget::class)->allInOrder() as $target) {
            if ($target->isActive()) {
                return $target->getCode();
            }
        }

        return '';
    }

    /**
     * The payment state per order of a page, asked from debtor in ONE query (`PaymentStates`,
     * ADR-040 decision 6 — debtor never calls order).
     *
     * @param list<SalesOrder> $orders
     * @return array<int, \Z77\Module\Debtor\Services\PaymentState> order id → state
     */
    private function orderPaymentStates(array $orders): array
    {
        $invoiceIds = [];
        foreach ($orders as $order) {
            if ($order->getInvoice() !== null) {
                $invoiceIds[(int) $order->getId()] = (int) $order->getInvoice()->getId();
            }
        }
        if ($invoiceIds === []) {
            return [];
        }
        $states = (new PaymentStates($this->em()))->of(array_values(array_unique($invoiceIds)));
        $out    = [];
        foreach ($invoiceIds as $orderId => $invoiceId) {
            if (isset($states[$invoiceId])) {
                $out[$orderId] = $states[$invoiceId];
            }
        }

        return $out;
    }

    /** @param list<OrderStatus> $statuses @return array<string, OrderStatus> */
    private static function orderStatusesByCode(array $statuses): array
    {
        $map = [];
        foreach ($statuses as $status) {
            $map[$status->getCode()] = $status;
        }

        return $map;
    }

    /** @param list<OrderStatus> $statuses @return array<string, string> code → label */
    private static function orderStatusLabels(array $statuses): array
    {
        return array_map(static fn(OrderStatus $s) => $s->getLabel(), self::orderStatusesByCode($statuses));
    }

    /** `Y-m-d` → the day; null when it is no date. */
    private static function orderDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new \DateTimeImmutable($value . ' 00:00:00');
    }
}
