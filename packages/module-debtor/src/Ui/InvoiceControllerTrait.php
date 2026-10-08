<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\BytesResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Debtor\Entities\DebtorProfile,
    Z77\Module\Debtor\Entities\Invoice,
    Z77\Module\Debtor\Entities\InvoiceKind,
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
    Z77\Module\Debtor\Services\InvoiceRefusedException,
    Z77\Module\Debtor\Services\InvoicingService,
    Z77\Module\Debtor\Services\PaymentService,
    Z77\Shared\Listing\ListDefinition,
    Z77\Module\Mandator\Services\LedgerAccountCheck,
    Z77\Module\Vat\Entities\TaxCode,
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
 *   - `list` — the documents per VIEW (`?view=`): invoices in `invoicing`,
 *     final invoices, credit notes; searchable per column in the database,
 *     sortable, paged ({@see InvoiceListing}, a standard list — listing.md: a
 *     fetch region, a state icon per row that opens the document as a window,
 *     ADR-047). In the `invoicing` view every row carries a checkbox with its
 *     `{id}:{version}` — the FINALIZE batch: «Definitiv stellen …» (toolbar)
 *     → `confirm-finalize` lists the selection → `finalize` POSTs the pairs
 *     to `InvoicingService::finalize()`; a document re-issued in between is
 *     refused by its version (DEBTOR-FINAL-001);
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
 * NOT here: the PDF with the QR-bill — no PDF library in the framework yet;
 * the owner decides the dependency (`debtor.md` pending). No delete, cancel
 * or edit of a final document anywhere (plan §1: the correction is a credit
 * note).
 *
 * No JavaScript of its own (Rule 7): pages with plain forms (`#[Csrf]`,
 * the `csrf_token` field), «Weitere Zeilen» is a submit. The using class MUST
 * provide (via its host base): `html()`, `redirect()`, `em()`,
 * `$layoutManager`, `$messageService`, `$help`.
 */
trait InvoiceControllerTrait
{
    private const INVOICE_NS = 'Z77\\Module\\Debtor';

    /** View → German tab label. */
    private const INVOICE_VIEWS = [
        InvoiceSearch::VIEW_INVOICING => 'In Fakturierung',
        InvoiceSearch::VIEW_FINAL     => 'Definitiv',
        InvoiceSearch::VIEW_CREDIT    => 'Gutschriften',
    ];

    /** The state icon of a row — German title per state. */
    private const INVOICE_STATE_LABELS = [
        'invoicing' => 'in Fakturierung — kann neu fakturiert werden, nichts verbucht',
        'final'     => 'definitiv — verbucht, unveränderlich; Korrektur mit Gutschrift',
    ];

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

        $response = $this->html([
            'documents'  => $documents,
            'definition' => $definition,
            'state'      => $state,
            'paging'     => $paging,
            'counts'    => $this->invoices()->countPerView(),
            'views'     => self::INVOICE_VIEWS,
            'states'    => self::INVOICE_STATE_LABELS,
            'fmt'       => static fn(?Money $m) => AmountFormat::of($m),
            'actionBase' => $this->invoiceListBase(),
        ]);
        // The fragment owns its header slots (financial.md, «fragment slots»), ADR-033 rev.
        // 2026-10-08: «+ Rechnung» is the entry's most frequent action → action cell (hc1); the
        // view tabs → toolbar (hc2). «Definitiv stellen …» is bound to the row selection and stands
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
            'document'     => $document,
            'bill'         => QrBill::of($document),
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
     * The same document yields the same file; a document still in
     * `invoicing` prints as it stands now and changes with a re-issue.
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
     * the detail. A document that is not settleable, or a payment that is
     * not the document's, goes back to the detail with the refusal.
     */
    #[Csrf]
    protected function paymentAction(): HtmlResponse|RedirectResponse
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
                    $this->messageService->pushFlashAfterRedirect('success', 'Zahlung erfasst und verbucht: ' . AmountFormat::of($recorded->allocated()) . ' auf ' . $document->documentName());
                } else {
                    $recorded = $this->paymentService()->update((int) $payment->getId(), (int) ($post['version'] ?? 0), $draft);
                    $this->messageService->pushFlashAfterRedirect('success', 'Zahlung geändert und umgebucht: ' . AmountFormat::of($recorded->allocated()) . ' auf ' . $document->documentName());
                }

                return $this->redirect($this->invoiceListBase() . '/detail?id=' . $document->getId(), 303);
            } catch (DebtorException | \Z77\Module\Debtor\Accounting\AccountingRefusedException $e) {
                $form->addGeneralError($e->getMessage());
            }
        }

        return $this->invoicePaymentPage($form, $document, $payment, $open);
    }

    /** «Zahlung löschen …» (`?id=&payment=`): the confirmation page naming the postings that go. */
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
            'document'   => $document,
            'payment'    => $payment,
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('payment', (int) $payment->getId()),
        ]);
    }

    /** The delete itself (POST, `#[Csrf]`, entity token + version): `PaymentService::delete()`, then back to the detail. */
    #[Csrf]
    protected function paymentDeleteAction(): RedirectResponse
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
            $this->messageService->pushFlashAfterRedirect('success', 'Zahlung gelöscht, Buchungen entfernt: ' . AmountFormat::of($amount) . ' wieder offen auf ' . $document->documentName());
        } catch (DebtorException | \Z77\Module\Debtor\Accounting\AccountingRefusedException $e) {
            $this->messageService->pushFlashAfterRedirect('error', $e->getMessage());
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
            $this->messageService->pushFlashAfterRedirect('error', $document->isCreditNote() ? 'Eine Gutschrift wird nicht bezahlt — sie reduziert eine Rechnung.' : $document->documentName() . ' ist noch in Fakturierung — erst definitiv stellen.');

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
            'form'       => $form,
            'document'   => $document,
            'payment'    => $payment,
            'openAmount' => $open,
            'accounts'   => (new LedgerAccountCheck($this->em()))->postableAccounts(),
            'entityCsrf' => $payment === null ? '' : DI::getCsrfService()->generateEntityToken('payment', (int) $payment->getId()),
        ]);
    }

    // ── add / edit / credit note ─────────────────────────────────────────

    /** A new invoice: `?contact=` preselects the party (the link from the debtor list). */
    #[Csrf]
    protected function addAction(): HtmlResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $form    = new InvoiceForm(DebtorCurrency::base());
        if (!$request->isPost()) {
            $contact = (int) $request->getGetParameter('contact');
            $form->startBlank(new \DateTimeImmutable('today'), $contact > 0 ? $contact : null, $this->invoiceDefaultTarget());

            return $this->invoiceFormPage($form, null);
        }
        $post = $request->getPostParameters();
        $form->fromPost($post);
        if (($post['op'] ?? '') === 'more') {
            $form->addRows(InvoiceForm::MORE_ROWS);

            return $this->invoiceFormPage($form, null);
        }
        $draft = $form->toDraft();
        if ($draft !== null) {
            try {
                $document = $this->invoicingService()->invoice($draft);
                $this->messageService->pushFlashAfterRedirect('success', $document->documentName() . ' erstellt (in Fakturierung, nichts verbucht)');

                return $this->redirect($this->invoiceListBase() . '/detail?id=' . $document->getId(), 303);
            } catch (DebtorException $e) {
                $form->addGeneralError($e->getMessage());
            }
        }

        return $this->invoiceFormPage($form, null);
    }

    /**
     * «Neu fakturieren» (`?id=`): a document still in `invoicing`, re-issued
     * under its number with the VERSION the form was rendered from. A final
     * document goes back to its detail with the refusal.
     */
    #[Csrf]
    protected function editAction(): HtmlResponse|RedirectResponse
    {
        $request  = DI::getRequest();
        $id       = (int) $request->getGetParameter('id');
        $document = $id ? $this->invoices()->withLines($id) : null;
        if ($document === null) {
            return $this->invoiceNotFound();
        }
        if ($document->isFinal()) {
            $this->messageService->pushFlashAfterRedirect('error', $document->documentName() . ' ist definitiv und bleibt unverändert — eine Korrektur ist eine Gutschrift.');

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
        $draft = $form->toDraft();
        if ($draft !== null) {
            try {
                $reissued = $this->invoicingService()->reinvoice($id, $version, $draft);
                $this->messageService->pushFlashAfterRedirect('success', $reissued->documentName() . ' neu fakturiert (gleiche Nummer, nichts verbucht)');

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

    /** «Gutschrift erstellen» (`?of=`): against a FINAL invoice only (plan §6.2). */
    #[Csrf]
    protected function creditNoteAction(): HtmlResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $ofId    = (int) $request->getGetParameter('of');
        $invoice = $ofId ? $this->invoices()->withLines($ofId) : null;
        if ($invoice === null || $invoice->isCreditNote()) {
            return $this->invoiceNotFound();
        }
        if (!$invoice->isFinal()) {
            $this->messageService->pushFlashAfterRedirect('error', $invoice->documentName() . ' ist noch in Fakturierung — sie wird neu fakturiert, nicht gutgeschrieben.');

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
        $draft = $form->toDraft();
        if ($draft !== null) {
            try {
                $note = $this->invoicingService()->invoice($draft);
                $this->messageService->pushFlashAfterRedirect('success', $note->documentName() . ' zu ' . $invoice->documentName() . ' erstellt (in Fakturierung, nichts verbucht)');

                return $this->redirect($this->invoiceListBase() . '/detail?id=' . $note->getId(), 303);
            } catch (DebtorException $e) {
                $form->addGeneralError($e->getMessage());
            }
        }

        return $this->invoiceFormPage($form, null);
    }

    /**
     * The editor page: the form with its pickers — the active debtors (a new
     * invoice), the active payment terms and targets (+ the ones the document
     * carries), the tax codes (active + kept, module-vat's shared list) and
     * the postable accounts (module-mandator's shared datalist). The help
     * (ADR-048) carries the rules the form does not spell out.
     */
    private function invoiceFormPage(InvoiceForm $form, ?Invoice $document, ?int $version = null): HtmlResponse
    {
        $terms   = array_values(array_filter($this->em()->getRepository(PaymentTerms::class)->allInOrder(), fn(PaymentTerms $t) => $t->isActive() || $t->getCode() === $form->header('terms')));
        $targets = array_values(array_filter($this->em()->getRepository(PaymentTarget::class)->allInOrder(), fn(PaymentTarget $t) => $t->isActive() || $t->getCode() === $form->header('target')));
        $this->help->attach('Backend/InvoiceController/form.help', self::INVOICE_NS, ['form' => $form, 'document' => $document], 'Hilfe: ' . ($form->kind === InvoiceKind::CreditNote ? 'Gutschrift' : 'Rechnung'));

        return $this->invoicePage('form', [
            'form'       => $form,
            'document'   => $document,
            'version'    => $version,
            'entityCsrf' => $document === null ? '' : DI::getCsrfService()->generateEntityToken('invoice', (int) $document->getId()),
            'debtors'    => $document === null && $form->creditNoteOf === null ? $this->em()->getRepository(DebtorProfile::class)->activeWithContacts() : [],
            'terms'      => $terms,
            'targets'    => $targets,
            'taxCodes'   => $this->em()->getRepository(TaxCode::class)->selectable($form->keptCodes()),
            'accounts'   => (new LedgerAccountCheck($this->em()))->postableAccounts(),
        ]);
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

    // ── finalize ─────────────────────────────────────────────────────────

    /**
     * «Definitiv stellen …»: the selection of the list (`doc[]` = `{id}:{version}`,
     * the version each row SHOWED) listed for a last look — number, party,
     * date, amount — with one button that posts the same pairs.
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

    /** POST: the pairs to `InvoicingService::finalize()` — one unit of work, all or nothing. */
    #[Csrf]
    protected function finalizeAction(): RedirectResponse
    {
        $request = DI::getRequest();
        $pairs   = $request->isPost() ? self::invoicePairs($request->getPostParameters()['doc'] ?? null) : [];
        if ($pairs === []) {
            return $this->redirect($this->invoiceListBase() . '/list', 303);
        }
        try {
            $done = $this->invoicingService()->finalize($pairs);
        } catch (InvoiceConflictException | InvoiceRefusedException $e) {
            // A second click on the same batch (review 2026-09-30): the first one booked, so the rows
            // are final now at a new version — say THAT, not «nothing was booked».
            $already = $this->invoiceAlreadyFinal($pairs);
            if ($already !== []) {
                $this->messageService->pushFlashAfterRedirect('error', 'Bereits definitiv und verbucht: ' . implode(', ', $already) . ' — wohl doppelt abgeschickt; in diesem Durchgang wurde nichts weiter verbucht.');

                return $this->redirect($this->invoiceListBase() . '/list?view=' . InvoiceSearch::VIEW_FINAL, 303);
            }
            $this->messageService->pushFlashAfterRedirect('error', $e instanceof InvoiceConflictException
                ? 'Eine Rechnung wurde inzwischen neu fakturiert oder gelöscht — nichts wurde verbucht. Liste neu laden und die Auswahl prüfen.'
                : 'Nichts wurde verbucht: ' . $e->getMessage());

            return $this->redirect($this->invoiceListBase() . '/list', 303);
        } catch (DebtorException $e) {
            $this->messageService->pushFlashAfterRedirect('error', 'Nichts wurde verbucht: ' . $e->getMessage());

            return $this->redirect($this->invoiceListBase() . '/list', 303);
        }
        $names = implode(', ', array_map(fn(Invoice $d) => $d->documentName(), $done));
        $this->messageService->pushFlashAfterRedirect('success', count($done) === 1 ? $names . ' definitiv gestellt und verbucht' : count($done) . ' Dokumente definitiv gestellt und verbucht: ' . $names);

        return $this->redirect($this->invoiceListBase() . '/list?view=' . InvoiceSearch::VIEW_FINAL, 303);
    }

    /**
     * The names of the documents of $pairs that are FINAL now — what a refused
     * batch reports when it was a repeated submit.
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
     * `{id}:{version}` strings → the pairs `finalize()` takes; anything else
     * is dropped (the value only selects documents, the service re-checks
     * every one of them under a lock).
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

        return array_values($pairs);
    }
}
