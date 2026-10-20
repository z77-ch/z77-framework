<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI,
    Z77\Core\Services\TemplateRenderer,
    Z77\Core\Http\WindowOrigin,
    Z77\Core\Http\Response\BytesResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Contact\Entities\Contact,
    Z77\Module\Contact\Services\AddressTypes,
    Z77\Module\Contact\Services\ContactService,
    Z77\Module\Contact\Ui\ContactForm,
    Z77\Module\Debtor\Entities\DebtorProfile,
    Z77\Module\Debtor\Entities\Invoice,
    Z77\Module\Debtor\Entities\InvoiceKind,
    Z77\Module\Debtor\Entities\LineType,
    Z77\Module\Debtor\Entities\Payment,
    Z77\Module\Debtor\Entities\PaymentTarget,
    Z77\Module\Debtor\Entities\PaymentTerms,
    Z77\Module\Debtor\Invoicing\QrBill,
    Z77\Module\Debtor\Pdf\InvoicePdf,
    Z77\Module\Debtor\Repositories\InvoiceRepository,
    Z77\Module\Debtor\Repositories\InvoiceSearch,
    Z77\Module\Debtor\Services\DebtorCurrency,
    Z77\Module\Debtor\Services\DebtorException,
    Z77\Module\Debtor\Services\DunningService,
    Z77\Module\Debtor\Services\InvoiceConflictException,
    Z77\Module\Debtor\Services\InvoicingService,
    Z77\Module\Debtor\Services\LockConflict,
    Z77\Module\Debtor\Services\PaymentService,
    Z77\Shared\Listing\ListDefinition,
    Z77\Module\Mandator\Services\LedgerAccountCheck,
    Z77\Module\Vat\Entities\TaxCode,
    Z77\Module\Vat\Services\VatRates,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Money\AmountFormat,
    Z77\Shared\Money\Money
;

/**
 * The document screens of the receivables side (P3 part 3, plan §6.2) —
 * mounted by a thin host controller in the backend (ADR-018 pattern, the
 * journal model). Host side: `use InvoiceControllerTrait` + a one-line layout
 * config delegating to {@see InvoiceLayout::config()}. In the navigation the
 * screen lives in the area «Aufträge» (ADR-050, owner 2026-09-30: order
 * processing is apart from the books); the URL group stays `finance`, like
 * the other debtor screens.
 *
 * What the screens can do, and deliberately cannot:
 *
 *   - `list` — the documents per VIEW (`?view=`): drafts in `invoicing` (no
 *     number yet — «Entwurf»), booked («Verbucht») invoices, credit notes;
 *     searchable per column in the database, sortable, paged
 *     ({@see InvoiceListing}, a standard list — listing.md: a fetch region,
 *     a state icon per row that opens the document as a window, ADR-047).
 *     In the `invoicing` view every row carries a checkbox with its
 *     `{id}:{version}` — the BOOKING PRINT (owner 2026-10-10):
 *     «Buchungsdruck …» (selection bar; on the detail of a draft for that
 *     one) → `confirm-finalize` lists the selection → `finalize` POSTs the
 *     pairs into a NEW TAB: `InvoicingService::finalize()` draws the
 *     numbers and posts, the answer is the PDF of exactly those documents
 *     with number and QR-bill; a document re-issued in between is refused
 *     by its version (DEBTOR-FINAL-001), a repeated submit reprints;
 *   - `pdf` — a draft's PREVIEW (watermarked, no number, no payment part,
 *     no side effect), a booked document's PDF (the same file any time);
 *   - `detail` — the document from its SNAPSHOT: address block, lines, tax
 *     summary, totals, the payment part and whether its QR-bill is printable
 *     ({@see QrBill}), the ledger reference linked to the journal entry (a
 *     window, when the bookkeeping is here), credit notes and open amount;
 *   - `add` / `edit` — the draft editor ({@see InvoiceForm}): a new invoice
 *     → `invoice()`; a document in `invoicing` → `reinvoice()` with the
 *     VERSION the form was rendered from (hidden field). Active tax codes and
 *     active accounts only (the shared pickers of module-vat and
 *     module-mandator), the code a document already carries stays;
 *   - `credit-note` (`?of=`) — the credit note against a FINAL invoice,
 *     service dates and lines prefilled from it.
 *
 *   - `confirm-discard` / `discard` (`?id=`) — «Entwurf löschen …» of a DRAFT
 *     (owner 2026-10-13): the confirmation with the entity token, then
 *     `InvoicingService::discard()` — the draft is deleted, it had no number.
 *
 * NOT here: no delete, cancel
 * or edit of a final document anywhere (plan §1: the correction is a credit
 * note).
 *
 * Windows (ADR-047, addendum 2026-10-10): fetched, the detail, the editor
 * (add / edit / credit note), the payment form and the payment-delete
 * confirmation are WINDOW contents — the root declares mask + entity, the
 * form carries `_origin` back, the links inside stay in the window
 * (`data-window-link`). A save answers {@see invoiceWindowSaved()}: the
 * detail into the same window + `refresh-region` of the list it was opened
 * from. Without the script each of them is the page it always was (plain
 * form, `#[Csrf]`, redirect to the detail) — the fallback.
 *
 * No JavaScript of its own (Rule 7): plain forms (`#[Csrf]`, the
 * `csrf_token` field), «Weitere Zeilen» is a submit. The using class MUST
 * provide (via its host base): `html()`, `redirect()`, `fetch()`, `em()`,
 * `$layoutManager`, `$messageService`, `$help`.
 */
trait InvoiceControllerTrait
{
    use CustomerPickerTrait;

    private const INVOICE_NS = 'Z77\\Module\\Debtor';

    /**
     * View → German tab label. The workflow reads «In Fakturierung» (a draft, preview only) →
     * «Buchungsdruck …» → «Verbucht» (owner 2026-10-10 — «Fakturiert» fell away: a booked
     * document was printed at that moment).
     */
    private const INVOICE_VIEWS = [
        InvoiceSearch::VIEW_INVOICING => 'In Fakturierung',
        InvoiceSearch::VIEW_FINAL     => 'Verbucht',
        InvoiceSearch::VIEW_CREDIT    => 'Gutschriften',
    ];

    /** The state icon of a row — German title per state (the UI word for `final` is «verbucht», owner 2026-10-10). */
    private const INVOICE_STATE_LABELS = [
        'invoicing' => 'in Fakturierung — Entwurf ohne Nummer, Vorschau möglich, nichts verbucht',
        'final'     => 'verbucht — mit Nummer gedruckt, unveränderlich; Korrektur mit Gutschrift',
    ];

    /** How many documents one booking print takes — a batch screen's size, not a limit of the writer. */
    private const INVOICE_BATCH_MAX = 200;

    private const INVOICE_CONFLICT_MESSAGE = 'Das Dokument wurde inzwischen geändert — bitte neu laden und erneut prüfen.';

    /** URL root of THIS mount — every link and form is built from it. */
    protected function invoiceListBase(): string
    {
        return '/backend/finance/invoice';
    }

    private function invoices(): InvoiceRepository
    {
        return $this->em()->getRepository(Invoice::class);
    }

    /** The one write path (ADR-040 decision 3); the session actor. A harness host overrides it with a named actor. */
    private function invoicingService(): InvoicingService
    {
        return new InvoicingService($this->em());
    }

    private function invoiceIsFetch(): bool
    {
        return ListDefinition::isFetch(DI::getRequest());
    }

    /**
     * The answer to a save inside a window (ADR-047, the journal's recipe): the document's
     * read view into the window the save came from, and — when the window was opened from a
     * fetch region (the document list `invoice-find-list`, the open-item list
     * `debtor-find-list`) — that region reloads, because a row in it changed. Other origins
     * get the window only. The flash is pushed BEFORE `fetch()`, which takes it along.
     */
    private function invoiceWindowSaved(int $id, string $origin, string $flash): FetchResponse
    {
        $this->messageService->pushFlash('success', $flash);
        $response = $this->fetch()->setStatus('success')
            ->addCommand('open-window', ['url' => $this->invoiceListBase() . '/detail?id=' . $id, 'replace' => true]);
        if (str_starts_with($origin, 'region:')) {
            $response->addCommand('refresh-region', ['name' => substr($origin, strlen('region:'))]);
        }

        return $response;
    }

    /**
     * A full page other than the list: the layout pins the body to
     * `listAction`, so the section is swapped for the page's own template.
     *
     * @param array<string, mixed> $context
     */
    private function invoicePage(string $template, array $context): HtmlResponse
    {
        $context += ['actionBase' => $this->invoiceListBase(), 'fmt' => static fn(?Money $m) => AmountFormat::of($m)];
        $response = $this->html($context);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials($template, 'Backend/InvoiceController', self::INVOICE_NS);

        return $response;
    }

    private function invoiceNotFound(): RedirectResponse
    {
        $this->messageService->pushFlashAfterRedirect('error', 'Dokument nicht gefunden');

        return $this->redirect($this->invoiceListBase() . '/list', 303);
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $request = DI::getRequest();
        // The invoicing view leads with the selection checkbox — the view is read once ahead of the definition.
        $selectable = !in_array($request->getGetParameter('view'), [InvoiceSearch::VIEW_FINAL, InvoiceSearch::VIEW_CREDIT], true);
        $definition = InvoiceListing::definition(DebtorCurrency::base(), $selectable);
        $state      = $definition->readRequest($request);
        $search     = InvoiceListing::search($state);
        $total      = $this->invoices()->countSearch($search);
        $paging     = $state->paging($total);
        $documents  = $total === 0 ? [] : $this->invoices()->search($search, $paging->offset(), $paging->pageSize);
        // The list's help (HELP-003): «Hilfe» at the right end of the toolbar — the page only; a
        // FETCH of the region carries none (it would land inside the region).
        if (!$this->invoiceIsFetch()) {
            $this->help->attach('Backend/InvoiceController/list.help', self::INVOICE_NS, [], 'Hilfe: Rechnungen', toolbar: true);
        }

        $response = $this->html([
            'documents'  => $documents,
            'definition' => $definition,
            'state'      => $state,
            'paging'     => $paging,
            'views'     => self::INVOICE_VIEWS,
            'states'    => self::INVOICE_STATE_LABELS,
            'fmt'       => static fn(?Money $m) => AmountFormat::of($m),
            'actionBase' => $this->invoiceListBase(),
        ]);
        // The fragment owns its header slots (financial.md, «fragment slots»), ADR-033 rev.
        // 2026-10-08: «+ Rechnung» is the entry's most frequent action → action cell (hc1); the
        // view tabs → toolbar (hc2). «Buchungsdruck …» is bound to the row selection and stands
        // in the list's selection bar. A FETCH (sort, page, search — core.js «fetch regions»)
        // wants the list alone.
        if (!$this->invoiceIsFetch()) {
            $this->layoutManager->addPartials('act', 'Backend/InvoiceController', self::INVOICE_NS, 'hc1');
            $this->layoutManager->addPartials('toolbar', 'Backend/InvoiceController', self::INVOICE_NS, 'hc2');
        }

        return $response;
    }

    // ── detail ───────────────────────────────────────────────────────────

    protected function detailAction(): HtmlResponse|RedirectResponse
    {
        $id       = (int) DI::getRequest()->getGetParameter('id');
        $document = $id ? $this->invoices()->withLines($id) : null;
        if ($document === null) {
            return $this->invoiceNotFound();
        }
        $isInvoice = !$document->isCreditNote();

        return $this->invoicePage('detail', [
            'window'       => $this->invoiceIsFetch(),
            'windowWidth'  => '60rem',
            'origin'       => WindowOrigin::of(DI::getRequest()),
            'document'     => $document,
            // What the booking print WILL print (owner 2026-10-13, review H5) — for a draft the payment part taken again.
            'bill'         => $this->invoicingService()->bookingBill($document),
            'creditNotes'  => $isInvoice ? $this->invoices()->creditNotesOf($document) : [],
            'openAmount'   => $isInvoice && $document->isFinal() ? $this->invoicingService()->openAmount($document) : null,
            'allocations'  => $isInvoice && $document->isFinal() ? $this->paymentService()->allocationsOf($document) : [],
            'notices'      => $isInvoice && $document->isFinal() ? $this->invoiceDunningService()->noticesOf($document) : [],
            'ledgerKnown'  => (new LedgerAccountCheck($this->em()))->available(),
            'states'       => self::INVOICE_STATE_LABELS,
        ]);
    }

    /**
     * The document as PDF, inline — rendered on request from the snapshot
     * through {@see InvoicePdf} (the layout `pdf/invoice`), never stored.
     * A DRAFT renders as a PREVIEW (owner 2026-10-10): watermarked
     * «VORSCHAU», without a number and without the payment part — any number
     * of times, without a side effect; it changes with a re-issue. A BOOKED
     * document renders the real PDF with number and QR-bill — the reprint of
     * what the booking print produced, identical each time.
     */
    protected function pdfAction(): BytesResponse|RedirectResponse
    {
        $id       = (int) DI::getRequest()->getGetParameter('id');
        $document = $id ? $this->invoices()->withLines($id) : null;
        if ($document === null) {
            return $this->invoiceNotFound();
        }

        return $this->bytes(InvoicePdf::of($document, $this->em())->output(), InvoicePdf::fileName($document), 'application/pdf');
    }

    // ── payment (P4 part 1) ──────────────────────────────────────────────

    /** The one write path for settlements; the session actor. A harness host overrides it with a named actor. */
    private function paymentService(): PaymentService
    {
        return new PaymentService($this->em());
    }

    /** The dunning history the detail shows; the session actor. A harness host overrides it with a named actor. */
    private function invoiceDunningService(): DunningService
    {
        return new DunningService($this->em());
    }

    /**
     * «Zahlung erfassen» (`?id=`) and «Zahlung ändern» (`?id=&payment=`) on
     * a FINAL invoice: a page form for the value date, the money and the
     * ledger account it went to, Skonto, Verlust («Rest als Verlust»), a
     * note. The POST goes to `PaymentService::record()` or — with the
     * entity token and the VERSION the form was rendered from —
     * `update()`: posted or amended at once, all or nothing — and back to
     * the detail (fetched: a window — the detail replaces it and the list it
     * came from reloads, {@see invoiceWindowSaved()}). A document that is
     * not settleable, or a payment that is not the document's, goes back to
     * the detail with the refusal.
     */
    #[Csrf]
    protected function paymentAction(): HtmlResponse|RedirectResponse|FetchResponse
    {
        $request  = DI::getRequest();
        $document = $this->settleableDocument();
        if (!$document instanceof Invoice) {
            return $document;
        }
        $payment = $this->paymentOf($document);
        if ($payment === false) {
            return $this->invoiceNotFound();
        }
        $form = new PaymentForm($document->getCurrency());
        $open = $this->invoicingService()->openAmount($document);
        if ($payment !== null) {
            $open = $open->add($payment->allocated());
        }
        if (!$request->isPost()) {
            $payment === null
                ? $form->startBlank(new \DateTimeImmutable('today'), $this->invoiceDefaultAccount($document))
                : $form->startFrom($payment);

            return $this->invoicePaymentPage($form, $document, $payment, $open);
        }
        $post = $request->getPostParameters();
        if ($payment !== null && !DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'payment', (int) $payment->getId())) {
            $this->messageService->pushFlashAfterRedirect('error', self::INVOICE_CONFLICT_MESSAGE);

            return $this->redirect($this->invoiceListBase() . '/detail?id=' . $document->getId(), 303);
        }
        $form->fromPost($post);
        $draft = $form->toDraft((int) $document->getId(), $open, $document->getPayment()->getTargetCode());
        if ($draft !== null) {
            try {
                if ($payment === null) {
                    $recorded = $this->paymentService()->record($draft);
                    $text     = 'Zahlung erfasst und verbucht: ' . AmountFormat::of($recorded->allocated()) . ' auf ' . $document->documentName();
                } else {
                    $recorded = $this->paymentService()->update((int) $payment->getId(), (int) ($post['version'] ?? 0), $draft);
                    $text     = 'Zahlung geändert und umgebucht: ' . AmountFormat::of($recorded->allocated()) . ' auf ' . $document->documentName();
                }
                if ($this->invoiceIsFetch()) {
                    return $this->invoiceWindowSaved((int) $document->getId(), WindowOrigin::of($request), $text);
                }
                $this->messageService->pushFlashAfterRedirect('success', $text);

                return $this->redirect($this->invoiceListBase() . '/detail?id=' . $document->getId(), 303);
            } catch (DebtorException | \Z77\Module\Debtor\Accounting\AccountingRefusedException $e) {
                $form->addGeneralError($e->getMessage());
            } catch (\Doctrine\DBAL\Exception $e) {
                // A deadlock or lock wait (review 2026-10-13 H4): nothing was written — say so, never a 500.
                if (!LockConflict::is($e)) {
                    throw $e;
                }
                $form->addGeneralError(LockConflict::MESSAGE);
            }
        }

        return $this->invoicePaymentPage($form, $document, $payment, $open);
    }

    /**
     * «Zahlung löschen …» (`?id=&payment=`): the confirmation naming the postings that go —
     * fetched, the content of the payment window it is linked from (`data-window-link`), so
     * the delete answers into that window; else a page.
     */
    protected function confirmPaymentDeleteAction(): HtmlResponse|RedirectResponse
    {
        $document = $this->settleableDocument();
        if (!$document instanceof Invoice) {
            return $document;
        }
        $payment = $this->paymentOf($document);
        if ($payment === null || $payment === false) {
            return $this->invoiceNotFound();
        }

        return $this->invoicePage('confirmPaymentDelete', [
            'window'     => $this->invoiceIsFetch(),
            'origin'     => WindowOrigin::of(DI::getRequest()),
            'document'   => $document,
            'payment'    => $payment,
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('payment', (int) $payment->getId()),
        ]);
    }

    /**
     * The delete itself (POST, `#[Csrf]`, entity token + version): `PaymentService::delete()`,
     * then back to the detail — fetched (from the window), the detail replaces the window and
     * the list it came from reloads; a refusal is the error flash, the window stays.
     */
    #[Csrf]
    protected function paymentDeleteAction(): RedirectResponse|FetchResponse
    {
        $request  = DI::getRequest();
        $document = $this->settleableDocument();
        if (!$document instanceof Invoice) {
            return $document;
        }
        $payment = $this->paymentOf($document);
        if ($payment === null || $payment === false) {
            return $this->invoiceNotFound();
        }
        $post = $request->getPostParameters();
        if (!$request->isPost() || !DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'payment', (int) $payment->getId())) {
            $this->messageService->pushFlashAfterRedirect('error', self::INVOICE_CONFLICT_MESSAGE);

            return $this->redirect($this->invoiceListBase() . '/detail?id=' . $document->getId(), 303);
        }
        try {
            $amount = $payment->allocated();
            $this->paymentService()->delete((int) $payment->getId(), (int) ($post['version'] ?? 0));
            $text = 'Zahlung gelöscht, Buchungen entfernt: ' . AmountFormat::of($amount) . ' wieder offen auf ' . $document->documentName();
            if ($this->invoiceIsFetch()) {
                return $this->invoiceWindowSaved((int) $document->getId(), WindowOrigin::of($request), $text);
            }
            $this->messageService->pushFlashAfterRedirect('success', $text);
        } catch (DebtorException | \Z77\Module\Debtor\Accounting\AccountingRefusedException | \Doctrine\DBAL\Exception $e) {
            // A lock conflict (deadlock, lock wait — review 2026-10-13 H4) is answered like a refusal; any other DBAL failure is rethrown.
            if ($e instanceof \Doctrine\DBAL\Exception && !LockConflict::is($e)) {
                throw $e;
            }
            $message = $e instanceof \Doctrine\DBAL\Exception ? LockConflict::MESSAGE : $e->getMessage();
            if ($this->invoiceIsFetch()) {
                $this->messageService->pushFlash('error', $message);

                return $this->fetch()->setStatus('error');
            }
            $this->messageService->pushFlashAfterRedirect('error', $message);
        }

        return $this->redirect($this->invoiceListBase() . '/detail?id=' . $document->getId(), 303);
    }

    /** The document of `?id=` when it can be settled — else the redirect to send. */
    private function settleableDocument(): Invoice|RedirectResponse
    {
        $id       = (int) DI::getRequest()->getGetParameter('id');
        $document = $id ? $this->invoices()->withLines($id) : null;
        if ($document === null) {
            return $this->invoiceNotFound();
        }
        if (!$document->isFinal() || $document->isCreditNote()) {
            $this->messageService->pushFlashAfterRedirect('error', $document->isCreditNote() ? 'Eine Gutschrift wird nicht bezahlt — sie reduziert eine Rechnung.' : $document->documentName() . ' ist noch in Fakturierung — erst mit «Buchungsdruck …» verbuchen.');

            return $this->redirect($this->invoiceListBase() . '/detail?id=' . $document->getId(), 303);
        }

        return $document;
    }

    /** The payment of `?payment=` on $document: null without the parameter, false when unknown or another document's. */
    private function paymentOf(Invoice $document): Payment|null|false
    {
        $paymentId = (int) DI::getRequest()->getGetParameter('payment');
        if ($paymentId <= 0) {
            return null;
        }
        $payment = $this->paymentService()->find($paymentId);
        if ($payment === null || $payment->getAllocations() === [] || $payment->getAllocations()[0]->getInvoice()->getId() !== $document->getId()) {
            return false;
        }

        return $payment;
    }

    /** The account a new payment proposes: the document's payment target's, else the first active target's. */
    private function invoiceDefaultAccount(Invoice $document): string
    {
        $code = $document->getPayment()->getTargetCode() !== '' ? $document->getPayment()->getTargetCode() : $this->invoiceDefaultTarget();
        $target = $code !== '' ? $this->em()->getRepository(PaymentTarget::class)->findByCode($code) : null;

        return $target !== null ? trim($target->getAccountNumber()) : '';
    }

    private function invoicePaymentPage(PaymentForm $form, Invoice $document, ?Payment $payment, Money $open): HtmlResponse
    {
        return $this->invoicePage('payment', [
            'window'      => $this->invoiceIsFetch(),
            'windowWidth' => '52rem',
            'origin'      => WindowOrigin::of(DI::getRequest()),
            'form'       => $form,
            'document'   => $document,
            'payment'    => $payment,
            'openAmount' => $open,
            'accounts'   => (new LedgerAccountCheck($this->em()))->postableAccounts(),
            'entityCsrf' => $payment === null ? '' : DI::getCsrfService()->generateEntityToken('payment', (int) $payment->getId()),
        ]);
    }

    // ── add / edit / credit note ─────────────────────────────────────────

    /**
     * A new invoice: `?contact=` preselects the party (the link from the debtor list).
     * Fetched (the action cell's «+ Rechnung», `data-window-open`): a window; the save
     * answers {@see invoiceWindowSaved()} — the new document's detail in the same window.
     */
    #[Csrf]
    protected function addAction(): HtmlResponse|RedirectResponse|FetchResponse
    {
        $request = DI::getRequest();
        $form    = new InvoiceForm(DebtorCurrency::base());
        if (!$request->isPost()) {
            $contact = (int) $request->getGetParameter('contact');
            // The debtor carries the price mode (owner 2026-10-18) — a preset debtor's starts the invoice.
            $profile = $contact > 0 ? $this->em()->getRepository(DebtorProfile::class)->findByContact($contact) : null;
            $form->startBlank(new \DateTimeImmutable('today'), $contact > 0 ? $contact : null, $this->invoiceDefaultTarget(), $this->invoiceNewDebtorTerms($contact),
                $profile?->priceMode()->value ?? 'net');

            return $this->invoiceFormPage($form, null);
        }
        $post = $request->getPostParameters();
        $form->fromPost($post);
        if (($post['op'] ?? '') === 'more') {
            $form->addRows(InvoiceForm::MORE_ROWS);

            return $this->invoiceFormPage($form, null);
        }
        $draft = $form->toDraft(VatRates::from($this->em()));
        // No document for a contact without a debtor profile (owner 2026-10-18): the picker asks
        // «Debitor anlegen» first; the re-check is InvoicingService's own (`no-debtor-profile`,
        // since 2026-10-19 the only one — it binds an import too), never opened silently.
        if ($draft !== null) {
            try {
                $document = $this->invoicingService()->invoice($draft);
                $text     = $document->documentName() . ' erstellt (in Fakturierung, noch ohne Nummer, nichts verbucht)';
                if ($this->invoiceIsFetch()) {
                    return $this->invoiceWindowSaved((int) $document->getId(), WindowOrigin::of($request), $text);
                }
                $this->messageService->pushFlashAfterRedirect('success', $text);

                return $this->redirect($this->invoiceListBase() . '/detail?id=' . $document->getId(), 303);
            } catch (DebtorException $e) {
                $form->addGeneralError($e->getMessage());
            }
        }

        return $this->invoiceFormPage($form, null);
    }

    /**
     * «Bearbeiten …» (`?id=`, until 2026-10-13 «Neu fakturieren»): a document still in
     * `invoicing` (a draft, no number yet), saved again with the VERSION the form was rendered from. A final
     * document goes back to its detail with the refusal. Fetched (from the
     * detail window, `data-window-link`): the window's content; the save
     * answers {@see invoiceWindowSaved()}.
     */
    #[Csrf]
    protected function editAction(): HtmlResponse|RedirectResponse|FetchResponse
    {
        $request  = DI::getRequest();
        $id       = (int) $request->getGetParameter('id');
        $document = $id ? $this->invoices()->withLines($id) : null;
        if ($document === null) {
            return $this->invoiceNotFound();
        }
        if ($document->isFinal()) {
            $this->messageService->pushFlashAfterRedirect('error', $document->documentName() . ' ist verbucht und bleibt unverändert — eine Korrektur ist eine Gutschrift.');

            return $this->redirect($this->invoiceListBase() . '/detail?id=' . $id, 303);
        }
        $kind = $document->kind();
        $form = new InvoiceForm(DebtorCurrency::base(), $kind, $document->getCreditNoteOf());
        if (!$request->isPost()) {
            return $this->invoiceFormPage($form->startFrom($document), $document, $document->getVersion());
        }
        $post = $request->getPostParameters();
        if (!DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'invoice', $id)) {
            $this->messageService->pushFlashAfterRedirect('error', 'Ungültiges Formular-Token — bitte erneut versuchen.');

            return $this->redirect($this->invoiceListBase() . '/edit?id=' . $id, 303);
        }
        $version = (int) ($post['version'] ?? 0);   // what THIS form was rendered from, not what is stored now
        $form->fromPost($post, $document);
        if (($post['op'] ?? '') === 'more') {
            $form->addRows(InvoiceForm::MORE_ROWS);

            return $this->invoiceFormPage($form, $document, $version);
        }
        $draft = $form->toDraft(VatRates::from($this->em()));
        if ($draft !== null) {
            try {
                $reissued = $this->invoicingService()->reinvoice($id, $version, $draft);
                $text     = $reissued->documentName() . ' gespeichert (noch ohne Nummer, nichts verbucht)';
                if ($this->invoiceIsFetch()) {
                    return $this->invoiceWindowSaved($id, WindowOrigin::of($request), $text);
                }
                $this->messageService->pushFlashAfterRedirect('success', $text);

                return $this->redirect($this->invoiceListBase() . '/detail?id=' . $id, 303);
            } catch (InvoiceConflictException) {
                $this->messageService->pushFlashAfterRedirect('error', self::INVOICE_CONFLICT_MESSAGE);

                return $this->redirect($this->invoiceListBase() . '/detail?id=' . $id, 303);
            } catch (DebtorException $e) {
                $form->addGeneralError($e->getMessage());
                // A refused unit of work may have replaced the EntityManager — the document is re-read, display only.
                $document = $this->invoices()->withLines($id) ?? $document;
            }
        }

        return $this->invoiceFormPage($form, $document, $version);
    }

    // ── discard a draft ──────────────────────────────────────────────────

    /**
     * «Entwurf löschen …» (`?id=`, owner 2026-10-13, review H3): the last look before a DRAFT
     * is deleted — party, date, amount; the entity token (`invoice:<id>`, fetch.md confirm-delete
     * pattern) and the VERSION shown travel with the one button. Fetched, the content of the
     * detail window it is linked from (`data-window-link`); else a page. A booked document goes
     * back to its detail with the refusal — it is corrected by a credit note, never deleted.
     */
    protected function confirmDiscardAction(): HtmlResponse|RedirectResponse
    {
        $id       = (int) DI::getRequest()->getGetParameter('id');
        $document = $id ? $this->invoices()->withLines($id) : null;
        if ($document === null) {
            return $this->invoiceNotFound();
        }
        if ($document->isFinal()) {
            $this->messageService->pushFlashAfterRedirect('error', $document->documentName() . ' ist verbucht und bleibt — eine Korrektur ist eine Gutschrift.');

            return $this->redirect($this->invoiceListBase() . '/detail?id=' . $id, 303);
        }

        return $this->invoicePage('confirmDiscard', [
            'window'     => $this->invoiceIsFetch(),
            'origin'     => WindowOrigin::of(DI::getRequest()),
            'document'   => $document,
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('invoice', $id),
        ]);
    }

    /**
     * The delete itself (POST, `#[Csrf]`, entity token + version): `InvoicingService::discard()`.
     * Fetched (from the window): the window closes and the list it was opened from reloads; a
     * refusal or a conflict is the error flash and the window stays. As a page: back to the
     * list «In Fakturierung» (or to the detail on a refusal).
     */
    #[Csrf]
    protected function discardAction(): RedirectResponse|FetchResponse
    {
        $request = DI::getRequest();
        $id      = (int) $request->getGetParameter('id');
        $post    = $request->getPostParameters();
        $fetch   = $this->invoiceIsFetch();
        $refuse  = function (string $message) use ($fetch, $id): RedirectResponse|FetchResponse {
            if ($fetch) {
                $this->messageService->pushFlash('error', $message);

                return $this->fetch()->setStatus('error');
            }
            $this->messageService->pushFlashAfterRedirect('error', $message);

            return $this->redirect($this->invoiceListBase() . '/detail?id=' . $id, 303);
        };
        if ($id <= 0 || !$request->isPost() || !DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'invoice', $id)) {
            return $refuse(self::INVOICE_CONFLICT_MESSAGE);
        }
        try {
            $name = $this->invoicingService()->discard($id, (int) ($post['version'] ?? 0));
        } catch (InvoiceConflictException) {
            return $refuse(self::INVOICE_CONFLICT_MESSAGE);
        } catch (DebtorException $e) {
            return $refuse($e->getMessage());
        } catch (\Doctrine\DBAL\Exception $e) {
            if (!LockConflict::is($e)) {
                throw $e;
            }

            return $refuse(LockConflict::MESSAGE);
        }
        $text = $name . ' gelöscht — er hatte keine Nummer, nichts war verbucht';
        if ($fetch) {
            $this->messageService->pushFlash('success', $text);
            $response = $this->fetch()->setStatus('success')->addCommand('close-window');
            $origin   = WindowOrigin::of($request);
            if (str_starts_with($origin, 'region:')) {
                $response->addCommand('refresh-region', ['name' => substr($origin, strlen('region:'))]);
            }

            return $response;
        }
        $this->messageService->pushFlashAfterRedirect('success', $text);

        return $this->redirect($this->invoiceListBase() . '/list', 303);
    }

    /**
     * «Gutschrift erstellen» (`?of=`): against a FINAL invoice only (plan §6.2). Fetched
     * (from the invoice's detail window): the window's content; the save answers with the
     * NEW credit note's detail in that window ({@see invoiceWindowSaved()}).
     */
    #[Csrf]
    protected function creditNoteAction(): HtmlResponse|RedirectResponse|FetchResponse
    {
        $request = DI::getRequest();
        $ofId    = (int) $request->getGetParameter('of');
        $invoice = $ofId ? $this->invoices()->withLines($ofId) : null;
        if ($invoice === null || $invoice->isCreditNote()) {
            return $this->invoiceNotFound();
        }
        if (!$invoice->isFinal()) {
            $this->messageService->pushFlashAfterRedirect('error', $invoice->documentName() . ' ist noch ein Entwurf — sie wird bearbeitet, nicht gutgeschrieben.');

            return $this->redirect($this->invoiceListBase() . '/detail?id=' . $ofId, 303);
        }
        $form = new InvoiceForm(DebtorCurrency::base(), InvoiceKind::CreditNote, $invoice);
        if (!$request->isPost()) {
            return $this->invoiceFormPage($form->startCreditNote(new \DateTimeImmutable('today')), null);
        }
        $post = $request->getPostParameters();
        $form->fromPost($post);
        if (($post['op'] ?? '') === 'more') {
            $form->addRows(InvoiceForm::MORE_ROWS);

            return $this->invoiceFormPage($form, null);
        }
        $draft = $form->toDraft(VatRates::from($this->em()));
        if ($draft !== null) {
            try {
                $note = $this->invoicingService()->invoice($draft);
                $text = $note->documentName() . ' zu ' . $invoice->documentName() . ' erstellt (in Fakturierung, noch ohne Nummer, nichts verbucht)';
                if ($this->invoiceIsFetch()) {
                    return $this->invoiceWindowSaved((int) $note->getId(), WindowOrigin::of($request), $text);
                }
                $this->messageService->pushFlashAfterRedirect('success', $text);

                return $this->redirect($this->invoiceListBase() . '/detail?id=' . $note->getId(), 303);
            } catch (DebtorException $e) {
                $form->addGeneralError($e->getMessage());
            }
        }

        return $this->invoiceFormPage($form, null);
    }

    /**
     * «+ Neuer Kontakt» of the customer picker (until 2026-10-19 also «+ Kontakt» beside the editor's customer; owner 2026-10-10, DEBTOR-CONTACT-001): a NEW contact
     * with its first address, without leaving the half-filled draft. The editor's link opens
     * it as a window of its own (`data-window-open`; origin = the editor's window). Fields and
     * save are module-contact's building block ({@see ContactForm}: the partials
     * `_contactFields` + `_addressFields`, `ContactService::save()` — no contact rule lives
     * here), the address REQUIRED (a document needs one). The save answers with two commands:
     * `replace-html` of the customer field IN THE EDITOR (`#invoice-contact`, scoped to the
     * origin — {@see CustomerPickerTrait::customerChosenAnswer()}, the picker's own answer) with the
     * new contact chosen — and `close-modal` for this window (the picker too, when it was opened there);
     * nothing else of the draft is touched. Since 2026-10-18 (owner: no document for a contact
     * without a debtor profile) the save answers «Debitor anlegen» in this window first
     * ({@see CustomerPickerTrait::customerDebtorPage()}) — only the created debtor fills the
     * editor. Without the script: the same dialog as a page, then a new invoice with the
     * contact preselected.
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
                if (!$this->invoiceIsFetch()) {
                    $this->messageService->pushFlashAfterRedirect('success', $text);

                    return $this->redirect($this->invoiceListBase() . '/customer-debtor?' . http_build_query(['contact' => $contact->getId(), 'back' => $this->invoiceListBase() . '/add']), 303);
                }
                $this->messageService->pushFlash('success', $text);

                return $this->customerDebtorPage($contact, WindowOrigin::of($request), $this->invoiceListBase() . '/add');
            }
        }

        return $this->invoicePage('newContact', [
            'window' => $this->invoiceIsFetch(),
            'origin' => WindowOrigin::of($request),
            'failed' => $form->hasErrors(),
        ] + $form->vars(AddressTypes::from($this->em())));
    }

    /** The picker lives at the editor's root ({@see CustomerPickerTrait}). */
    protected function customerPickBase(): string
    {
        return $this->invoiceListBase();
    }

    /** The head's ids: `#invoice-customer-title`, `#invoice-terms`, `#invoice-debtor-edit` (the shared head, {@see CustomerPickerTrait}). */
    protected function customerHeadPrefix(): string
    {
        return 'invoice';
    }

    /** After a choice the card «Rechnung» shows THAT customer's invoice address (owner 2026-10-19). */
    protected function customerChosenCommands(FetchResponse $response, Contact $contact, string $origin): void
    {
        $response->addCommand('replace-html', ['target' => '#invoice-bill-address', 'html' => $this->invoiceBillAddress((string) $contact->getId()), 'origin' => $origin]);
    }

    /**
     * The card «Rechnung» (`_billAddress`): the address the save takes — `InvoicingService::
     * invoiceAddressOf()`, the contact's invoice / main / first address AS IT IS NOW (a draft has
     * no address choice of its own; every save takes it again). Read-only.
     */
    private function invoiceBillAddress(string $contactId): string
    {
        $contact = ctype_digit($contactId) && (int) $contactId > 0 ? $this->em()->getRepository(Contact::class)->find((int) $contactId) : null;

        return (new TemplateRenderer(self::INVOICE_NS))->partial('Backend/InvoiceController/_billAddress', [
            'chosen' => $contact !== null,
            'lines'  => $contact !== null ? ($this->invoicingService()->invoiceAddressOf($contact)?->lines() ?? []) : [],
        ]);
    }

    /**
     * The editor page: the form with its pickers — the customer field (a new
     * invoice, {@see CustomerPicker}), the active payment terms and targets (+ the ones the document
     * carries), the tax codes (active + kept, module-vat's shared list) and
     * the postable accounts (module-mandator's shared datalist). The help
     * (ADR-048) carries the rules the form does not spell out.
     */
    private function invoiceFormPage(InvoiceForm $form, ?Invoice $document, ?int $version = null): HtmlResponse
    {
        $targets = array_values(array_filter($this->em()->getRepository(PaymentTarget::class)->allInOrder(), fn(PaymentTarget $t) => $t->isActive() || $t->getCode() === $form->header('target')));
        $this->help->attach('Backend/InvoiceController/form.help', self::INVOICE_NS, ['form' => $form, 'document' => $document], 'Hilfe: ' . ($form->kind === InvoiceKind::CreditNote ? 'Gutschrift' : 'Rechnung'));

        return $this->invoicePage('form', [
            'window'      => $this->invoiceIsFetch(),
            // The order window's width (owner 2026-10-19: the editor takes its head) — wide enough for
            // the nine-column line row, the window must not scroll sideways.
            'windowWidth' => '78rem',
            'origin'      => WindowOrigin::of(DI::getRequest()),
            'form'       => $form,
            'document'   => $document,
            'version'    => $version,
            'entityCsrf' => $document === null ? '' : DI::getCsrfService()->generateEntityToken('invoice', (int) $document->getId()),
            // The hash of the state the window was LOADED with, handed back through a POST that
            // re-renders without saving («Weitere Zeilen», errors) — core.js «dirty forms».
            'dirtyBase'  => preg_match('/^[0-9a-f]{8}$/', (string) (DI::getRequest()->getPostParameters()['_dirty_base'] ?? '')) === 1 ? (string) DI::getRequest()->getPostParameters()['_dirty_base'] : '',
            // The customer of a NEW invoice: the field with «Kunde wählen …» (CustomerPicker, owner 2026-10-15) — a contact
            // without a profile is made a debtor first («Debitor anlegen», owner 2026-10-18). The number is in the title.
            'customerField' => $document === null && $form->creditNoteOf === null ? CustomerPicker::fieldHtml($this->em(), $form->header('contact'), $this->invoiceListBase() . '/customer-pick', $this->invoiceListBase() . '/add', $form->error('contact') !== '', showNumber: false) : '',
            // The head shared with the order window (CustomerPickerTrait, owner 2026-10-19): the block title
            // «Kunde 20417» + phone, the terms select («wie beim Debitor — …»), «Debitor anpassen».
            'customerTitle' => $this->customerTitleHtml($form->header('contact')),
            'termsSelect'   => $this->customerTermsHtml($form->header('terms'), $form->header('contact'), $form->error('terms') !== ''),
            'debtorEdit'    => $this->customerDebtorEditHtml($form->header('contact')),
            'billAddress'   => $this->invoiceBillAddress($form->header('contact')),
            'targets'    => $targets,
            'taxCodes'   => $taxCodes = $this->em()->getRepository(TaxCode::class)->selectable($form->keptCodes()),
            // Each code's RATE on the service date (else the invoice date) as `data-rate` and its history for
            // core.js «dated rates» — «Preise» converts the typed prices with them (core.js «price mode», owner 2026-10-19).
            'taxRates'     => VatRates::from($this->em())->shownRates($taxCodes, $this->invoiceRateDate($form)),
            'rateSchedule' => VatRates::from($this->em())->schedule($taxCodes),
            'accounts'   => $this->invoiceLineAccounts($form),
        ]);
    }

    /** The day the rows' VAT rates are shown for: the service date, else the invoice date, else today (ADR-041 decision 4). */
    private function invoiceRateDate(InvoiceForm $form): \DateTimeImmutable
    {
        return InvoiceForm::parseDate($form->header('service_from'))
            ?? InvoiceForm::parseDate($form->header('invoice_date'))
            ?? new \DateTimeImmutable('today');
    }

    /**
     * The accounts the line datalist offers. An invoice line: the revenue accounts
     * flagged «auf Belegen wählbar» (owner 2026-10-10 / 2026-10-12) — the service
     * refuses any other. A credit note mirrors its invoice (owner 2026-10-13), the
     * service checks its lines for the type only: the same list PLUS the revenue
     * accounts the corrected invoice's lines carry (not its rounding line), though
     * switched off since — in chart order. Not every revenue account: the flag
     * still steers the choice.
     *
     * @return list<array{number: string, label: string}>
     */
    private function invoiceLineAccounts(InvoiceForm $form): array
    {
        $check    = new LedgerAccountCheck($this->em());
        $accounts = $check->documentAccounts();
        if ($form->creditNoteOf === null) {
            return $accounts;
        }
        $offered  = array_fill_keys(array_column($accounts, 'number'), true);
        $mirrored = [];
        foreach ($form->creditNoteOf->getLines() as $line) {
            $number = $line->type() === LineType::Rounding ? null : $line->getRevenueAccount();
            if ($number !== null && !isset($offered[$number]) && $check->hasType($number, LedgerAccountCheck::TYPE_REVENUE) === true) {
                $mirrored[$number] = true;
            }
        }
        if ($mirrored === []) {
            return $accounts;
        }

        return array_values(array_filter($check->postableAccounts(), static fn(array $row): bool => isset($offered[$row['number']]) || isset($mirrored[$row['number']])));
    }

    /** The target a new invoice starts with: the first ACTIVE one in list order — a preselection the user sees and may change, never an implicit default of the service. */
    private function invoiceDefaultTarget(): string
    {
        foreach ($this->em()->getRepository(PaymentTarget::class)->allInOrder() as $target) {
            if ($target->isActive()) {
                return $target->getCode();
            }
        }

        return '';
    }

    /**
     * The terms preselected for `?contact=` when that contact is NOT a debtor yet: the default
     * terms (owner 2026-10-12) — what the profile opened with the draft gets as well. '' = «wie
     * beim Debitor»: the contact is a debtor (its profile's terms apply), none is named, or no
     * active default is marked (then terms must be chosen, the service says so).
     */
    private function invoiceNewDebtorTerms(int $contactId): string
    {
        if ($contactId < 1 || $this->em()->getRepository(DebtorProfile::class)->findByContact($contactId) !== null) {
            return '';
        }

        return $this->em()->getRepository(PaymentTerms::class)->defaultForNewDebtor()?->getCode() ?? '';
    }

    // ── finalize ─────────────────────────────────────────────────────────

    /**
     * «Buchungsdruck …» (owner 2026-10-10 — before that day «Verbuchen …», until then
     * «Definitiv stellen …»; the code keeps `finalize` and the state `final`): the selection
     * of the list or the one draft of the detail (`doc[]` = `{id}:{version}`, the version each
     * row SHOWED) listed for a last look — party, date, amount; no number yet — with one
     * button that posts the same pairs into a NEW TAB ({@see finalizeAction()}).
     */
    protected function confirmFinalizeAction(): HtmlResponse|RedirectResponse
    {
        $pairs = self::invoicePairs(DI::getRequest()->getGetParameter('doc'));
        if ($pairs === []) {
            $this->messageService->pushFlashAfterRedirect('error', 'Keine Rechnung ausgewählt — in der Liste «In Fakturierung» ankreuzen.');

            return $this->redirect($this->invoiceListBase() . '/list', 303);
        }
        $documents = [];
        $stale     = [];
        foreach ($pairs as $pair) {
            $document = $this->invoices()->find($pair['id']);
            if ($document === null || $document->isFinal() || $document->getVersion() !== $pair['version']) {
                $stale[] = $pair['id'];
                continue;
            }
            $documents[] = $document;
        }

        return $this->invoicePage('confirmFinalize', [
            'documents' => $documents,
            'pairs'     => $pairs,
            'stale'     => $stale,
            'total'     => array_reduce($documents, fn(?Money $sum, Invoice $d) => $sum === null ? $d->getGrossTotal() : $sum->add($d->getGrossTotal()), null),
        ]);
    }

    /**
     * «Buchungsdruck» — the POST of the confirmation (`#[Csrf]`, sent into a NEW TAB,
     * `target="_blank"`; owner 2026-10-10: «a printed invoice with a number IS a document» —
     * there is no booking without the print): the pairs to `InvoicingService::finalize()` —
     * the numbers drawn and everything posted in ONE unit of work, all or nothing — and the
     * answer IS the PDF of exactly these documents with number and QR-bill
     * ({@see InvoicePdf::combined()}: one section per document — its own pages, its own
     * «Seite x von n», its own payment part). The success flash waits for the next page of
     * the original tab (core.js `data-submit-navigate` sends it to the «Verbucht» view).
     *
     * IDEMPOTENT for a repeated submit (a double click, a reload of the PDF tab): when every
     * document of the selection is FINAL at exactly the version it showed + 1 — the version
     * this booking left — the same PDF is answered again and nothing is posted. Anything
     * else stale (edited since, discarded, booked by another batch) and every refusal — the
     * service's, the bookkeeping's, or the database's (a deadlock, a lock timeout: a DBAL
     * exception, review 2026-10-13) — answers a German message PAGE in that tab; nothing
     * was booked.
     */
    #[Csrf]
    protected function finalizeAction(): BytesResponse|HtmlResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $pairs   = $request->isPost() ? self::invoicePairs($request->getPostParameters()['doc'] ?? null) : [];
        if ($pairs === []) {
            return $this->redirect($this->invoiceListBase() . '/list', 303);
        }
        $booked = $this->invoiceBookedBy($pairs);
        if ($booked !== null) {
            return $this->invoiceBookingPdf($booked);   // the same selection again: reprint, nothing posted
        }
        try {
            $done = $this->invoicingService()->finalize($pairs);
        } catch (InvoiceConflictException) {
            return $this->invoiceBookingRefused('Ein Entwurf der Auswahl wurde inzwischen bearbeitet oder gelöscht — nichts wurde verbucht. Liste neu laden und die Auswahl prüfen.', $pairs);
        } catch (DebtorException $e) {
            return $this->invoiceBookingRefused('Nichts wurde verbucht: ' . $e->getMessage(), $pairs);
        } catch (\Doctrine\DBAL\Exception $e) {
            // The database refused the unit of work (a deadlock against a payment, a lock wait timeout, a unique
            // index at commit): everything rolled back. Answered as the refusal page in the new tab, never a 500
            // (review 2026-10-13); the cause goes to the log, the user may simply try again.
            error_log('debtor booking print: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->invoiceBookingRefused(LockConflict::is($e)
                ? 'Nichts wurde verbucht: ' . LockConflict::MESSAGE
                : 'Nichts wurde verbucht: die Datenbank hat den Buchungsdruck abgelehnt. Liste neu laden und nochmals versuchen; bleibt es dabei, steht die Ursache im Fehlerprotokoll.', $pairs);
        }
        $names = implode(', ', array_map(fn(Invoice $d) => $d->documentName(), $done));
        $this->messageService->pushFlashAfterRedirect('success', count($done) === 1 ? $names . ' verbucht und gedruckt' : count($done) . ' Dokumente verbucht und gedruckt: ' . $names);

        return $this->invoiceBookingPdf(array_map(fn(Invoice $d) => (int) $d->getId(), $done));
    }

    /**
     * The ids of $pairs when EVERY one of them is final at the version it showed + 1 — what
     * the booking print of exactly this selection left behind (one flush: the number, the
     * payment part and the state in one UPDATE) — else null. The repeated submit's test.
     *
     * @param list<array{id: int, version: int}> $pairs
     * @return list<int>|null
     */
    private function invoiceBookedBy(array $pairs): ?array
    {
        $ids = [];
        foreach ($pairs as $pair) {
            $document = $this->invoices()->find($pair['id']);
            if ($document === null || !$document->isFinal() || $document->getVersion() !== $pair['version'] + 1) {
                return null;
            }
            $ids[] = $pair['id'];
        }

        return $ids;
    }

    /**
     * The booking print's PDF of the FINAL documents $ids, in number order — ONE file, a
     * section per document, named «rechnung-26100012.pdf» or «dokumente-…-bis-….pdf».
     *
     * @param list<int> $ids
     */
    private function invoiceBookingPdf(array $ids): BytesResponse
    {
        $documents = array_values(array_filter(array_map(fn(int $id) => $this->invoices()->withLines($id), $ids)));
        usort($documents, static fn(Invoice $a, Invoice $b) => $a->getNumber() <=> $b->getNumber());

        return $this->bytes(InvoicePdf::combined($documents, $this->em())->output(), InvoicePdf::combinedFileName($documents), 'application/pdf');
    }

    /**
     * The refusal of a booking print — a PAGE in the new tab the confirmation opened: what
     * happened (nothing was booked), the documents of the selection that ARE booked by now
     * (another batch, a stale double submit), and the way back to the lists.
     *
     * @param list<array{id: int, version: int}> $pairs
     */
    private function invoiceBookingRefused(string $message, array $pairs): HtmlResponse
    {
        return $this->invoicePage('bookingRefused', [
            'message' => $message,
            'already' => $this->invoiceAlreadyFinal($pairs),
        ]);
    }

    /**
     * The names of the documents of $pairs that are FINAL now — what a refused
     * booking print reports (booked by another batch, or a stale repeat).
     *
     * @param list<array{id: int, version: int}> $pairs
     * @return list<string>
     */
    private function invoiceAlreadyFinal(array $pairs): array
    {
        $names = [];
        foreach ($pairs as $pair) {
            $document = $this->invoices()->find($pair['id']);
            if ($document !== null && $document->isFinal()) {
                $names[] = $document->documentName();
            }
        }

        return $names;
    }

    /**
     * `{id}:{version}` strings → the pairs `finalize()` takes, at most
     * {@see INVOICE_BATCH_MAX}; anything else is dropped (the value only
     * selects documents, the service re-checks every one of them under a lock).
     *
     * @return list<array{id: int, version: int}>
     */
    private static function invoicePairs(mixed $values): array
    {
        $pairs = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value) && preg_match('/^(\d{1,9}):(\d{1,9})$/', $value, $m)) {
                $pairs[(int) $m[1]] = ['id' => (int) $m[1], 'version' => (int) $m[2]];
            }
        }

        return array_slice(array_values($pairs), 0, self::INVOICE_BATCH_MAX);
    }
}
