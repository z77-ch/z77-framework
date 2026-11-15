<?php
namespace Z77\Module\Creditor\Ui;

use Doctrine\DBAL\Exception\RetryableException,
    Z77\Core\DI,
    Z77\Core\Http\RequestMode,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Contact\Entities\Contact,
    Z77\Module\Contact\Services\AddressTypes,
    Z77\Module\Contact\Services\ContactService,
    Z77\Module\Contact\Ui\ContactForm,
    Z77\Module\Creditor\Entities\CreditorIban,
    Z77\Module\Creditor\Entities\CreditorIntake,
    Z77\Module\Creditor\Entities\CreditorInvoice,
    Z77\Module\Creditor\Entities\CreditorInvoiceLine,
    Z77\Module\Creditor\Entities\CreditorJournalPosting,
    Z77\Module\Creditor\Entities\CreditorProfile,
    Z77\Module\Creditor\Repositories\CreditorInvoiceLineRepository,
    Z77\Module\Creditor\Repositories\CreditorInvoiceRepository,
    Z77\Module\Creditor\Repositories\CreditorProfileRepository,
    Z77\Module\Creditor\Repositories\CreditorSearch,
    Z77\Module\Creditor\Services\CreditorIntakeService,
    Z77\Module\Creditor\Services\CreditorInvoiceService,
    Z77\Module\Creditor\Services\CreditorRefusedException,
    Z77\Module\Creditor\Services\InvalidCreditorInvoiceException,
    Z77\Module\Receipt\Entities\PaymentMethod,
    Z77\Module\Receipt\Services\PaymentMethodService,
    Z77\Module\Receipt\Services\ReceiptFields,
    Z77\Module\Receipt\Ui\ReceiptChoices,
    Z77\Module\Receipt\Ui\ReceiptPhoto,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Listing\ListDefinition,
    Z77\Shared\Money\AmountFormat,
    Z77\Shared\Money\Money,
    Z77\Shared\Payment\Iban,
    Z77\Shared\Payment\QrReference,
    Z77\Shared\Payment\ScorReference,
    Z77\Shared\Upload\UploadPolicy
;

/**
 * Finanzen › «Kreditorenjournal» (creditor plan §8, stage 1b) — the supplier invoices from their
 * PDF to «vollständig». Mounted by a thin host controller in the backend (ADR-018 pattern):
 * `/backend/finance/creditor-journal/…`, navigation right after «Belegjournal».
 *
 * The recipe (backend-screen.md):
 *
 *   - action cell (hc1): «↑ PDF hochladen» — the kernel upload in its `cell` shape (several PDFs,
 *     one request each; the whole work area is the drop target). In stage 1c «Journal verbuchen …»
 *     takes the cell (plan §8) and the upload moves to the toolbar;
 *   - toolbar (hc2): the tabs «Im Journal» · «Gesperrt» · «Verbuchte Journale»; «Hilfe» at its
 *     right end (HELP-003);
 *   - the pending step at the top: «n Rechnungen im Journal · Total …», red when invoices are
 *     blocked as suspected duplicates;
 *   - the work area: a STANDARD LIST ({@see CreditorJournalListing}) — the state icon opens the
 *     invoice WINDOW (ADR-047), the PDF as a thumbnail.
 *
 * Actions:
 *
 *   - `upload` (POST, `#[Csrf]`, one PDF per request) — `CreditorIntakeService::receive()`; the
 *     per-file answer of the upload component: ok + the list region replaced, `duplicate` for a
 *     file read before («identisch, schon vorhanden»), error for anything refused;
 *   - `edit` — the invoice WINDOW: the PDF large beside the fields (`.be-docview`, the PDF through
 *     module-dms's gated preview — `ReceiptPhoto`, never an address built here); creditor, IBAN,
 *     invoice number, dates, reference, message, amount, «Bezahlt mit» / Haben, and the LINES
 *     (core.js «rows»: «+ Zeile» and × — submits without the script, `op` = `add` / `remove:<i>`);
 *     «als Standard übernehmen» when the invoice deviates from the creditor's defaults. The save
 *     is `CreditorInvoiceService::update()` with the version shown;
 *   - `creditor-fill` / `payment-fill` — the window's fill helpers (core.js «fill»: the server
 *     answers the values, no script of its own — Rule 7);
 *   - `release` (POST from the window, `#[Csrf]`) — «Kein Doppel — übernehmen» of a blocked invoice;
 *   - `confirm-delete` — «Löschen …» / «Verwerfen …»: the invoice goes, its PDF stays in the DMS;
 *   - `adopt` — «Kreditor übernehmen …» for an invoice with a PROPOSED creditor (stage 2 fills the
 *     proposal): an existing contact, or a new one through module-contact's `ContactForm`.
 *
 * The using class MUST provide (via its host base): `html()`, `fetch()`, `fetchError()`, `json()`,
 * `redirect()`, `em()`, `$layoutManager`, `$messageService`, `$help`.
 */
trait CreditorJournalControllerTrait
{
    private const CREDITOR_JOURNAL_NS = 'Z77\\Module\\Creditor';

    /** URL root of THIS mount — every link and form is built from it. */
    protected function creditorJournalBase(): string
    {
        return CreditorLayout::JOURNAL_BASE;
    }

    /** The write paths; the session actor. A harness host overrides them with a named actor. */
    private function creditorInvoices(): CreditorInvoiceService
    {
        return new CreditorInvoiceService($this->em());
    }

    private function creditorIntake(): CreditorIntakeService
    {
        return new CreditorIntakeService($this->em());
    }

    /** The PDFs of the shown invoices (DMS, read-gated). A harness host overrides it — the DMS gate needs a session. */
    private function creditorPdfs(array $documentIds): array
    {
        return ReceiptPhoto::of($documentIds);
    }

    private function creditorInvoiceRows(): CreditorInvoiceRepository
    {
        return $this->em()->getRepository(CreditorInvoice::class);
    }

    private function creditorLineRows(): CreditorInvoiceLineRepository
    {
        return $this->em()->getRepository(CreditorInvoiceLine::class);
    }

    /**
     * What the upload lets in — ONE object for the client's attributes and the server's first
     * gate (`UploadPolicy`): PDFs, several, the intake's byte limit. The intake sniffs the bytes
     * again (a renamed file is no PDF).
     */
    private function creditorUploadPolicy(): UploadPolicy
    {
        $max = CreditorIntakeService::maxBytes();

        return new UploadPolicy(
            endpoint:   $this->creditorJournalBase() . '/upload',
            field:      'file',
            multiple:   true,
            accept:     ['application/pdf'],
            maxBytes:   $max,
            onConflict: UploadPolicy::CONFLICT_ERROR,
            label:      'PDF hochladen',
            hint:       'PDF · bis ' . UploadPolicy::formatBytes($max) . ' · mehrere',
        );
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $request = DI::getRequest();
        $fetch   = ListDefinition::isFetch($request);
        $context = $this->creditorJournalContext();
        if (!$fetch) {
            $this->help->attach('Backend/CreditorJournalController/list.help', self::CREDITOR_JOURNAL_NS, [], 'Hilfe: Kreditorenjournal', toolbar: true);
        }
        $response = $this->html($context + ['uploadPolicy' => $this->creditorUploadPolicy()]);
        if (!$fetch) {
            $this->layoutManager->addPartials('act', 'Backend/CreditorJournalController', self::CREDITOR_JOURNAL_NS, 'hc1');
            $this->layoutManager->addPartials('toolbar', 'Backend/CreditorJournalController', self::CREDITOR_JOURNAL_NS, 'hc2');
        }

        return $response;
    }

    // ── upload ───────────────────────────────────────────────────────────

    /**
     * The upload (POST, ONE file per request in `file`) — two answers for one body: the upload
     * component's per-file envelope in fetch mode (`{status, name, message, commands}` — `ok` with
     * the list region replaced, `duplicate` for a file read before, `error` for a refusal), the
     * flash + the list as a page. The gate is {@see UploadPolicy::check()}, then the intake's own
     * sniff.
     */
    #[Csrf]
    protected function uploadAction(): RedirectResponse|JsonResponse
    {
        $request = DI::getRequest();
        $fetch   = $request->getMode() === RequestMode::Fetch;
        if (!$request->isPost()) {
            return $fetch ? $this->json(['status' => 'error', 'name' => '', 'message' => 'POST erwartet'], 405) : $this->redirect($this->creditorJournalBase() . '/list', 303);
        }
        $file = $request->getUploadedFile('file');
        if ($file === null) {
            return $this->creditorUploadAnswer($fetch, 'error', '', 'Keine Datei empfangen — eine PDF-Rechnung wählen.');
        }
        $refusal = $this->creditorUploadPolicy()->check($file);
        if ($refusal !== null) {
            return $this->creditorUploadAnswer($fetch, 'error', $file->originalName, $file->originalName . ': ' . $refusal);
        }
        try {
            $invoice = $this->creditorIntake()->receive((string) $file->bytes(), $file->originalName, CreditorIntake::CHANNEL_UPLOAD);
        } catch (CreditorRefusedException $e) {
            $duplicate = $e->reason === CreditorRefusedException::DUPLICATE_FILE;

            return $this->creditorUploadAnswer($fetch, $duplicate ? 'duplicate' : 'error', $file->originalName, $duplicate ? $e->getMessage() : $file->originalName . ': ' . $e->getMessage());
        }

        return $this->creditorUploadAnswer($fetch, 'ok', $file->originalName, 'im Journal — zu prüfen (Nr. ' . $invoice->getId() . ')');
    }

    /** One answer, two shapes — the row's message (+ the list region on success) in fetch mode, the flash on a page. */
    private function creditorUploadAnswer(bool $fetch, string $status, string $name, string $message): RedirectResponse|JsonResponse
    {
        if ($fetch) {
            $answer = ['status' => $status, 'name' => $name, 'message' => $message];
            if ($status === 'ok') {
                $answer['commands'] = [[
                    'action' => 'replace-html',
                    'target' => '[data-creditor-journal]',
                    'html'   => (new TemplateRenderer(self::CREDITOR_JOURNAL_NS))->partial('Backend/CreditorJournalController/_journal', $this->creditorJournalContext()),
                ]];
            }

            return $this->json($answer);
        }
        $this->messageService->pushFlashAfterRedirect($status === 'ok' ? 'success' : ($status === 'duplicate' ? 'info' : 'error'), $status === 'ok' ? $name . ': ' . $message : $message);

        return $this->redirect($this->creditorJournalBase() . '/list', 303);
    }

    // ── the invoice window ───────────────────────────────────────────────

    /**
     * The invoice WINDOW (ADR-047): GET renders it, POST saves head and lines with the version the
     * window showed — or, with `op` = `add` / `remove:<i>` (the «+ Zeile» / × buttons without the
     * script), re-renders it with one line more / less, nothing saved. Fetched, the save closes the
     * window and reloads the list region; a field refusal re-renders the window with the messages
     * and what was typed. A posted invoice is read-only.
     */
    #[Csrf]
    protected function editAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $invoice = $this->creditorInvoices()->find((int) $request->getGetParameter('id'));
        if ($invoice === null) {
            return $this->creditorRefuse('Diese Rechnung gibt es nicht mehr — das Kreditorenjournal neu laden.');
        }
        $lines  = $this->creditorLineRows()->forInvoice($invoice);
        $values = self::creditorInvoiceValues($invoice);
        $rows   = self::creditorLineValues($lines);
        $errors = [];
        $error  = '';
        if ($request->isPost() && $invoice->isEditable()) {
            $post   = $request->getPostParameters();
            $values = self::creditorInvoicePosted($post);
            $rows   = self::creditorLinesPosted($post['lines'] ?? null);
            $op     = is_string($post['op'] ?? null) ? $post['op'] : '';
            if ($op === 'add') {
                $rows[] = ['text' => '', 'account' => '', 'tax' => '', 'amount' => ''];
            } elseif (preg_match('/^remove:(\d{1,3})$/', $op, $m)) {
                unset($rows[(int) $m[1]]);
                $rows = array_values($rows);
            } else {
                try {
                    $saved = $this->creditorInvoices()->update((int) $invoice->getId(), (int) ($post['version'] ?? 0), $values, $rows, ($post['take_default'] ?? '') === '1');
                    $savedLines = $this->creditorLineRows()->forInvoice($saved);

                    return $this->creditorSaved($saved->label() . ' gespeichert'
                        . ($saved->isBlocked() ? ' — GESPERRT: ' . $saved->getDuplicateReason() . '.'
                            : ($saved->isComplete($savedLines) ? ' — vollständig.' : ' — noch unvollständig, es fehlt: ' . implode(', ', $saved->missing($savedLines)) . '.')));
                } catch (InvalidCreditorInvoiceException $e) {
                    $errors = $e->errors;
                } catch (CreditorRefusedException $e) {
                    return $this->creditorRefuse($e->getMessage());
                } catch (\Doctrine\DBAL\Exception $e) {
                    if (!self::creditorLockConflict($e)) {
                        throw $e;
                    }
                    $error = 'Jemand anderes hat gleichzeitig an dieser Rechnung gearbeitet — nichts gespeichert. Bitte neu laden und nochmals versuchen.';
                }
            }
        }
        if ($rows === []) {
            $rows[] = ['text' => '', 'account' => '', 'tax' => '', 'amount' => ''];
        }

        $creditor = $invoice->getCreditor();
        $service  = $this->creditorInvoices();

        return $this->creditorWindow('edit', $invoice, [
            'values'     => $values,
            'rows'       => $rows,
            'lines'      => $lines,
            'errors'     => $errors,
            'error'      => $error,
            'creditors'  => $this->creditorChoices($creditor?->getId()),
            'methods'    => array_values(array_filter((new PaymentMethodService($this->em()))->all(), static fn(PaymentMethod $m) => $m->isActive() || $m->getCode() === $invoice->getPaymentMethodCode())),
            'choices'    => new ReceiptChoices($this->em()),
            'liable'     => $service->liableToVat(),
            'deviation'  => $this->creditorDeviation($invoice, $lines),
            'duplicate'  => $invoice->getDuplicateOf() !== null ? $this->creditorInvoiceRows()->find($invoice->getDuplicateOf()) : null,
            'proposal'   => $creditor === null ? $invoice->proposal() : [],
        ]);
    }

    /**
     * The creditor's values for the window (core.js «fill» on the creditor select): its single
     * active IBAN, «Bezahlt mit» with its Haben, and the FIRST line's account and VAT decision —
     * reads only; the save decides (and fills what is still empty itself).
     */
    protected function creditorFillAction(): FetchResponse
    {
        $profile = $this->em()->getRepository(CreditorProfile::class)->find((int) DI::getRequest()->getGetParameter('value'));
        if (!$profile instanceof CreditorProfile) {
            return $this->fetch()->setStatus('success')->setData(['fill' => []]);
        }
        $service  = $this->creditorInvoices();
        $payment  = $service->paymentDefaults($profile);
        $defaults = $service->lineDefaults($profile);
        $ibans    = array_values(array_filter($this->em()->getRepository(CreditorIban::class)->forProfile($profile), static fn(CreditorIban $i) => $i->isActive()));
        $fill     = [
            'payment_method_code' => (string) $payment['code'],
            'credit_account'      => (string) $payment['credit'],
            'line_account'        => (string) $defaults['account'],
            'line_tax'            => $defaults['withoutVat'] ? CreditorInvoiceService::WITHOUT_VAT : (string) $defaults['taxCode'],
        ];
        if (count($ibans) === 1) {
            $fill['iban'] = Iban::format($ibans[0]->getIban());
        }

        return $this->fetch()->setStatus('success')->setData(['fill' => $fill]);
    }

    /** The payment method's Haben for the window — reads only; the save decides. */
    protected function paymentFillAction(): FetchResponse
    {
        $method = (new PaymentMethodService($this->em()))->find(trim((string) DI::getRequest()->getGetParameter('value')));

        return $this->fetch()->setStatus('success')->setData(['fill' => $method === null ? [] : ['credit_account' => $method->getCreditAccount()]]);
    }

    /**
     * «Kein Doppel — übernehmen» (owner answer 4): a submit of the window's form (`formaction`), so
     * it carries the version shown. The invoice is open again; the pair is not checked any more.
     */
    #[Csrf]
    protected function releaseAction(): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $invoice = $this->creditorInvoices()->find((int) $request->getGetParameter('id'));
        if (!$request->isPost() || $invoice === null || !$invoice->isBlocked()) {
            return $this->creditorRefuse('Diese Rechnung ist nicht (mehr) gesperrt — das Kreditorenjournal neu laden.');
        }
        try {
            $released = $this->creditorInvoices()->release((int) $invoice->getId(), (int) ($request->getPostParameters()['version'] ?? 0));
        } catch (CreditorRefusedException $e) {
            return $this->creditorRefuse($e->getMessage());
        }

        return $this->creditorSaved($released->label() . ': kein Doppel — wieder im Journal.');
    }

    /**
     * «Löschen …» (for a blocked invoice «Verwerfen …»), in the window: the last look, then the
     * invoice and its lines go with the version shown. The PDF stays in the DMS and the file is
     * not read again (owner answer 4) — the confirmation says both.
     */
    #[Csrf]
    protected function confirmDeleteAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $invoice = $this->creditorInvoices()->find((int) $request->getGetParameter('id'));
        if ($invoice === null || !$invoice->isEditable()) {
            return $this->creditorRefuse('Diese Rechnung ist nicht mehr im Kreditorenjournal — neu laden.');
        }
        if ($request->isPost()) {
            $label = $invoice->label();
            try {
                $this->creditorInvoices()->delete((int) $invoice->getId(), (int) ($request->getPostParameters()['version'] ?? 0));
            } catch (CreditorRefusedException $e) {
                return $this->creditorRefuse($e->getMessage());
            }

            return $this->creditorSaved($label . ' gelöscht. Das PDF bleibt unter «Kreditorenrechnungen» in den Dokumenten.');
        }

        return $this->creditorWindow('confirmDelete', $invoice, []);
    }

    /**
     * «Kreditor übernehmen …» (plan §5) for an invoice whose creditor is only PROPOSED: «bestehenden
     * Kontakt verwenden» (`mode=existing`, a contact found by name — `?q=` searches again inside
     * the window) or «neuen Kontakt anlegen» (`mode=new`, module-contact's `ContactForm`, prefilled
     * from the proposal). Then `CreditorInvoiceService::adoptCreditor()`.
     */
    #[Csrf]
    protected function adoptAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $invoice = $this->creditorInvoices()->find((int) $request->getGetParameter('id'));
        if ($invoice === null || !$invoice->isEditable() || $invoice->getCreditor() !== null) {
            return $this->creditorRefuse('Diese Rechnung hat schon einen Kreditor oder ist nicht mehr im Journal — neu laden.');
        }
        $proposal = $invoice->proposal();
        $form     = new ContactForm(addressRequired: false);
        $form->fromPost([
            'kind' => 'organisation', 'company' => $proposal['name'] ?? '',
            'address_street' => $proposal['street'] ?? '', 'address_house_no' => $proposal['house_no'] ?? '',
            'address_zip' => $proposal['zip'] ?? '', 'address_city' => $proposal['city'] ?? '', 'address_country' => $proposal['country'] ?? 'CH',
        ]);
        $error = '';
        if ($request->isPost()) {
            $post    = $request->getPostParameters();
            $contact = null;
            if (($post['mode'] ?? '') === 'new') {
                $form = (new ContactForm(addressRequired: false))->fromPost($post);
                if ($form->save(new ContactService($this->em()))) {
                    $contact = $form->contact;
                }
            } else {
                $contact = $this->em()->getRepository(Contact::class)->find((int) ($post['contact_id'] ?? 0));
                if (!$contact instanceof Contact) {
                    $error = 'Einen Kontakt wählen — oder «neuen Kontakt anlegen».';
                    $contact = null;
                }
            }
            if ($contact !== null) {
                try {
                    $adopted = $this->creditorInvoices()->adoptCreditor((int) $invoice->getId(), (int) ($post['version'] ?? 0), $contact);

                    return $this->creditorSaved('Kreditor «' . $contact->displayName() . '» übernommen — ' . $adopted->label() . ' nennt ihn jetzt.');
                } catch (InvalidCreditorInvoiceException $e) {
                    $error = implode(' ', $e->errors);
                } catch (CreditorRefusedException $e) {
                    return $this->creditorRefuse($e->getMessage());
                }
            }
        }
        $query = trim((string) ($request->getGetParameter('q') ?? ($proposal['name'] ?? '')));

        return $this->creditorWindow('adopt', $invoice, [
            'proposal' => $proposal,
            'query'    => $query,
            'matches'  => $query === '' ? [] : $this->em()->getRepository(Contact::class)->search($query, 20),
            'error'    => $error,
            'failed'   => $form->hasErrors(),
            'mode'     => $request->isPost() && ($request->getPostParameters()['mode'] ?? '') === 'new' ? 'new' : 'existing',
        ] + $form->vars(AddressTypes::from($this->em())));
    }

    // ── context, answers and helpers ─────────────────────────────────────

    /**
     * The list region's context for the current request — the page, a region fetch and the upload
     * answer render the same partial (`_journal`) from it.
     *
     * @return array<string, mixed>
     */
    private function creditorJournalContext(): array
    {
        $request    = DI::getRequest();
        $currency   = ReceiptFields::currency();
        $definition = CreditorJournalListing::definition($currency);
        $state      = $definition->readRequest($request);
        $view       = (string) $state->extra('view');
        $view       = in_array($view, CreditorJournalListing::VIEWS, true) ? $view : CreditorJournalListing::VIEW_OPEN;
        $totals     = $this->creditorInvoiceRows()->totalsPerState();
        $context    = [
            'view'       => $view,
            'totals'     => [
                'open'    => $totals[CreditorInvoice::STATE_OPEN]['count'] ?? 0,
                'gross'   => Money::fromDecimal((string) ($totals[CreditorInvoice::STATE_OPEN]['gross'] ?? '0'), $currency)->add(Money::fromDecimal((string) ($totals[CreditorInvoice::STATE_BLOCKED]['gross'] ?? '0'), $currency)),
                'blocked' => $totals[CreditorInvoice::STATE_BLOCKED]['count'] ?? 0,
            ],
            'actionBase' => $this->creditorJournalBase(),
            'fmt'        => static fn(?Money $m) => AmountFormat::of($m),
        ];
        if ($view === CreditorJournalListing::VIEW_POSTED) {
            $posted = CreditorJournalListing::postedDefinition();
            $pState = $posted->readRequest($request);
            $repo   = $this->em()->getRepository(CreditorJournalPosting::class);
            $total  = $repo->countAll();
            $paging = $pState->paging($total);

            return $context + [
                'definition' => $posted,
                'state'      => $pState,
                'paging'     => $paging,
                'protocols'  => $total === 0 ? [] : $repo->page($paging->offset(), $paging->pageSize, $pState->descending),
                'invoices'   => [],
                'lines'      => [],
                'pdfs'       => [],
            ];
        }
        $search   = CreditorJournalListing::search($state, $view);
        $total    = $this->creditorInvoiceRows()->countSearch($search);
        $paging   = $state->paging($total);
        $invoices = $total === 0 ? [] : $this->creditorInvoiceRows()->search($search, $paging->offset(), $paging->pageSize);

        return $context + [
            'definition' => $definition,
            'state'      => $state,
            'paging'     => $paging,
            'protocols'  => [],
            'invoices'   => $invoices,
            'lines'      => $this->creditorLineRows()->forInvoices(array_map(static fn(CreditorInvoice $i) => (int) $i->getId(), $invoices)),
            'pdfs'       => $this->creditorPdfs(array_map(static fn(CreditorInvoice $i) => $i->getDocumentId(), $invoices)),
        ];
    }

    /** A window of $template for $invoice (edit, confirmDelete, adopt) — the invoice help (a section per field) attached before html(). */
    private function creditorWindow(string $template, CreditorInvoice $invoice, array $context): HtmlResponse
    {
        $request = DI::getRequest();
        $this->help->attach('Backend/CreditorJournalController/edit.help', self::CREDITOR_JOURNAL_NS, [], 'Hilfe: Kreditorenrechnung');
        $response = $this->html($context + [
            'invoice'    => $invoice,
            'pdf'        => $this->creditorPdfs([$invoice->getDocumentId()])[$invoice->getDocumentId()] ?? ReceiptPhoto::missing($invoice->getDocumentId()),
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'actionBase' => $this->creditorJournalBase(),
            'fmt'        => static fn(?Money $m) => AmountFormat::of($m),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials($template, 'Backend/CreditorJournalController', self::CREDITOR_JOURNAL_NS);

        return $response;
    }

    /**
     * The creditors the window offers: the ACTIVE ones by name, plus the invoice's own when it was
     * deactivated since (kept, never newly chosen).
     *
     * @return array<int, string> profile id → display name
     */
    private function creditorChoices(?int $current): array
    {
        /** @var CreditorProfileRepository $repo */
        $repo = $this->em()->getRepository(CreditorProfile::class);
        $out  = [];
        foreach ($repo->search(new CreditorSearch(), 0, 1000) as $profile) {
            if ($profile->isActive() || $profile->getId() === $current) {
                $out[(int) $profile->getId()] = (string) $profile->getContact()?->displayName() . ($profile->isActive() ? '' : ' (inaktiv)');
            }
        }

        return $out;
    }

    /**
     * «weicht vom Standard ab (6500 · VI · Bank)» — when the creditor HAS decided defaults and the
     * invoice's first line or «Bezahlt mit» differ; null otherwise (the window then offers no
     * «als Standard übernehmen»).
     *
     * @param list<CreditorInvoiceLine> $lines
     */
    private function creditorDeviation(CreditorInvoice $invoice, array $lines): ?string
    {
        $creditor = $invoice->getCreditor();
        $first    = $lines[0] ?? null;
        if ($creditor === null || $creditor->getDefaultsSetAt() === null || $first === null) {
            return null;
        }
        $same = $first->getAccount() === $creditor->getDefaultAccount()
            && $first->getTaxCode() === $creditor->getDefaultTaxCode()
            && $first->isWithoutVat() === ($creditor->getDefaultTaxCode() === null && $creditor->isDefaultWithoutVat())
            && $invoice->getPaymentMethodCode() === $creditor->getDefaultPaymentMethodCode();
        if ($same) {
            return null;
        }

        return implode(' · ', array_filter([
            $creditor->getDefaultAccount(),
            $creditor->getDefaultTaxCode() ?? ($creditor->isDefaultWithoutVat() ? 'ohne MWST' : null),
            $creditor->getDefaultPaymentMethodCode(),
        ]));
    }

    /**
     * Close the window and reload what it came from (the list region); without the script: flash +
     * the list.
     */
    private function creditorSaved(string $flash): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!ListDefinition::isFetch($request)) {
            $this->messageService->pushFlashAfterRedirect('success', $flash);

            return $this->redirect($this->creditorJournalBase() . '/list', 303);
        }
        $this->messageService->pushFlash('success', $flash);
        $response = $this->fetch()->setStatus('success')->addCommand('close-window');
        $origin   = WindowOrigin::of($request);
        if (str_starts_with($origin, 'region:')) {
            $response->addCommand('refresh-region', ['name' => substr($origin, strlen('region:'))]);
        } elseif (str_starts_with($origin, 'window:')) {
            $response->addCommand('refresh-window', ['window' => substr($origin, strlen('window:'))]);
        }

        return $response;
    }

    private function creditorRefuse(string $message): FetchResponse|RedirectResponse
    {
        if (ListDefinition::isFetch(DI::getRequest())) {
            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($this->creditorJournalBase() . '/list', 303);
    }

    /**
     * The values the window shows for $invoice — strings as the fields hold them.
     *
     * @return array<string, string>
     */
    private static function creditorInvoiceValues(CreditorInvoice $invoice): array
    {
        $reference = (string) $invoice->getReference();

        return [
            'creditor'            => (string) $invoice->getCreditor()?->getId(),
            'iban'                => $invoice->getIban() !== null ? Iban::format($invoice->getIban()) : '',
            'invoice_number'      => (string) $invoice->getInvoiceNumber(),
            'invoice_date'        => $invoice->getInvoiceDate()?->format('Y-m-d') ?? '',
            'due_date'            => $invoice->getDueDate()?->format('Y-m-d') ?? '',
            'reference'           => match ($invoice->getReferenceType()) {
                CreditorInvoice::REFERENCE_QRR  => QrReference::format($reference),
                CreditorInvoice::REFERENCE_SCOR => ScorReference::format($reference),
                default                         => '',
            },
            'message'             => $invoice->getMessage(),
            'amount'              => $invoice->getAmount()?->toDecimal() ?? '',
            'payment_method_code' => (string) $invoice->getPaymentMethodCode(),
            'credit_account'      => (string) $invoice->getCreditAccount(),
        ];
    }

    /**
     * @param list<CreditorInvoiceLine> $lines
     * @return list<array{text: string, account: string, tax: string, amount: string}>
     */
    private static function creditorLineValues(array $lines): array
    {
        return array_map(static fn(CreditorInvoiceLine $l) => [
            'text'    => $l->getText(),
            'account' => (string) $l->getAccount(),
            'tax'     => $l->isWithoutVat() ? CreditorInvoiceService::WITHOUT_VAT : (string) $l->getTaxCode(),
            'amount'  => $l->getAmount()?->toDecimal() ?? '',
        ], $lines);
    }

    /** The same shape out of a POST — strings only, anything else empty (the service judges each one). */
    private static function creditorInvoicePosted(array $post): array
    {
        $values = [];
        foreach (['creditor', 'iban', 'invoice_number', 'invoice_date', 'due_date', 'reference', 'message', 'amount', 'payment_method_code', 'credit_account'] as $key) {
            $values[$key] = is_string($post[$key] ?? null) ? $post[$key] : '';
        }

        return $values;
    }

    /**
     * The posted line rows — every row kept as typed (an empty one too, so the window re-renders
     * what the user sees); the service drops the empty ones on save.
     *
     * @return list<array{text: string, account: string, tax: string, amount: string}>
     */
    private static function creditorLinesPosted(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = [];
            foreach (['text', 'account', 'tax', 'amount'] as $key) {
                $clean[$key] = is_string($row[$key] ?? null) ? $row[$key] : '';
            }
            $out[] = $clean;
        }

        return $out;
    }

    /** A deadlock or lock wait timeout anywhere in the chain (DOCTRINE-TX-005) — nothing was written. */
    private static function creditorLockConflict(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RetryableException) {
                return true;
            }
        }

        return false;
    }
}
