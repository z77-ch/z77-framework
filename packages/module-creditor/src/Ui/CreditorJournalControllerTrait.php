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
    Z77\Module\Creditor\Services\CreditorException,
    Z77\Module\Creditor\Services\CreditorIntakeFolder,
    Z77\Module\Creditor\Services\CreditorIntakeService,
    Z77\Module\Creditor\Services\CreditorInvoiceService,
    Z77\Module\Creditor\Services\CreditorJournalPostingService,
    Z77\Module\Creditor\Jobs\CreditorIntakeJob,
    Z77\Module\Creditor\Services\CreditorRefusedException,
    Z77\Module\Creditor\Services\CreditorRetention,
    Z77\Module\Creditor\Services\InvalidCreditorInvoiceException,
    Z77\Module\Dms\Services\DocumentService,
    Z77\Module\Financial\Services\Actor,
    Z77\Module\Financial\Ui\ManualEntryForm,
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
 * Finanzen › «Kreditorenjournal» (creditor plan §8, stages 1b and 1c) — the supplier invoices from
 * their PDF to «vollständig», «bezahlt am» and into the books. Mounted by a thin host controller in
 * the backend (ADR-018 pattern): `/backend/finance/creditor-journal/…`, navigation right after
 * «Belegjournal».
 *
 * The recipe (backend-screen.md):
 *
 *   - action cell (hc1): «Journal verbuchen …» (green, the posting WINDOW — stage 1c, plan §8);
 *     disabled with its reason while no invoice is paid;
 *   - toolbar (hc2): the tabs «Im Journal» · «Gesperrt» · «Verbuchte Journale» · «Nicht eingelesen», then the tool
 *     «PDF hochladen» — the kernel upload in its `cell` shape (several PDFs, one request each; the
 *     whole work area is the drop target); «Hilfe» at its right end (HELP-003);
 *   - the pending step at the top: «n Rechnungen im Journal · n unbezahlt · n bezahlt, nicht
 *     verbucht · Total …», red when invoices are blocked as suspected duplicates;
 *   - the selection bar above the list («Im Journal»): «Bezahlt am …» for the ticked rows
 *     (`inv[]` = `{id}:{version}`, debtor's invoicing selection);
 *   - the work area: a STANDARD LIST ({@see CreditorJournalListing}) — the state icon opens the
 *     invoice WINDOW (ADR-047), the PDF as a thumbnail.
 *
 * Actions:
 *
 *   - `upload` (POST, `#[Csrf]`, one PDF per request) — `CreditorIntakeService::receive()` (the
 *     invoice is written «Erkennung ausstehend»), then the job `creditor-intake` is QUEUED: the
 *     recognition (text layer, matching, the opt-in AI) runs in the job only, never in this
 *     request (CREDITOR-RECOG-WEB-001); the per-file answer of the upload component: ok («Erkennung
 *     ausstehend») + the list region replaced, `duplicate` for a file read before («identisch,
 *     schon vorhanden»), error for anything refused;
 *   - `recognize` (POST from the window, `#[Csrf]`) — «Jetzt erkennen»: marks the invoice pending
 *     and queues the job (re-run on a failed / unavailable recognition; only empty fields are filled);
 *   - `edit` — the invoice WINDOW: the PDF large beside the fields (`.be-docview`, the PDF through
 *     module-dms's gated preview — `ReceiptPhoto`, never an address built here); creditor, IBAN,
 *     invoice number, dates, reference, message, amount, «Bezahlt mit» / Haben, and the LINES
 *     (core.js «rows»: «+ Zeile» and × — submits without the script, `op` = `add` / `remove:<i>`);
 *     «als Standard übernehmen» when the invoice deviates from the creditor's defaults. The save
 *     is `CreditorInvoiceService::update()` with the version shown;
 *   - `creditor-fill` / `payment-fill` — the window's fill helpers (core.js «fill»: the server
 *     answers the values, no script of its own — Rule 7);
 *   - `release` (POST from the window, `#[Csrf]`) — «Kein Doppel — übernehmen» of a blocked invoice;
 *   - `confirm-delete` — «Löschen …» (the invoice goes completely, its file hash is free, the PDF
 *     moves to the DMS trash) / «Verwerfen …» of a suspected duplicate (the file stays refused);
 *   - `adopt` — «Kreditor übernehmen …» for an invoice with a PROPOSED creditor (stage 2 fills the
 *     proposal): an existing contact, or a new one through module-contact's `ContactForm`;
 *   - `confirm-paid` — «Bezahlt am …» for the ticked rows: a WINDOW (GET, the selection) with the
 *     date (today) and optionally «Bezahlt mit» for all; its POST is `CreditorInvoiceService::markPaid()`;
 *   - `confirm-post` / `post` — «Journal verbuchen …»: the posting WINDOW for the run up to a
 *     cut-off on the payment date (`CreditorJournalPostingService::preview()`), its POST the run;
 *   - `intake-retry` / `intake-remove` (POST, `#[Csrf]`, tab «Nicht eingelesen», stage 2) — a file of the
 *     intake folder's `failed/` back into the folder, or deleted (`CreditorIntakeFolder`).
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

    private function creditorPosting(): CreditorJournalPostingService
    {
        return new CreditorJournalPostingService($this->em());
    }

    /**
     * Queue the job `creditor-intake` that recognises the pending invoices — the request never
     * recognises itself (CREDITOR-RECOG-WEB-001). A failure is logged: the invoice stays pending and
     * the job's schedule picks it up. A harness host overrides it.
     */
    private function creditorQueueRecognition(): void
    {
        try {
            CreditorIntakeJob::queue($this->em(), Actor::current());
        } catch (\Throwable $e) {
            error_log('creditor journal: could not queue ' . CreditorIntakeJob::KEY . ' — ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /** The intake folder of the job `creditor-intake` (tab «Nicht eingelesen»), as the session user. A harness host overrides it. */
    private function creditorIntakeFolder(): CreditorIntakeFolder
    {
        return CreditorIntakeFolder::forInstallation($this->em(), Actor::current());
    }

    /** Moves a deleted invoice's PDF to the DMS trash with the session's right. A harness host overrides it. */
    private function creditorDeletePdf(int $documentId): void
    {
        DocumentService::create()->delete($documentId);
    }

    /** The posted invoices' PDFs kept 10 years — the backfill of «Verbuchte Journale». A harness host overrides it. */
    private function creditorRetention(): void
    {
        (new CreditorRetention($this->em()))->apply();
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
        $response = $this->html($context);
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
        $bytes = (string) $file->bytes();
        try {
            $invoice = $this->creditorIntake()->receive($bytes, $file->originalName, CreditorIntake::CHANNEL_UPLOAD);
        } catch (CreditorRefusedException $e) {
            $duplicate = $e->reason === CreditorRefusedException::DUPLICATE_FILE;

            return $this->creditorUploadAnswer($fetch, $duplicate ? 'duplicate' : 'error', $file->originalName, $duplicate ? $e->getMessage() : $file->originalName . ': ' . $e->getMessage());
        }
        // The recognition runs in the job only (CREDITOR-RECOG-WEB-001): the invoice was written
        // «Erkennung ausstehend», the job is queued — no process starts, nothing waits on the AI.
        $this->creditorQueueRecognition();

        return $this->creditorUploadAnswer($fetch, 'ok', $file->originalName, self::creditorReceivedLine($invoice));
    }

    /** «im Journal — Erkennung ausstehend (Nr. 7)» / «im Journal — erkannt (Text): Muster AG (Nr. 7)» — what a row says about an invoice. */
    private static function creditorReceivedLine(CreditorInvoice $invoice): string
    {
        $source = $invoice->recognitionSource();
        $name   = $invoice->getCreditor()?->getContact()?->displayName();
        $what   = match (true) {
            $invoice->isRecognitionPending()                      => 'im Journal — Erkennung ausstehend',
            $source === 'von Hand'                                => 'im Journal — nicht erkannt, von Hand erfassen',
            $name !== null                                        => 'im Journal — erkannt (' . $source . '): ' . $name,
            ($invoice->recognition()['candidates'] ?? []) !== [] => 'im Journal — erkannt (' . $source . '), gemeinsame IBAN: Kreditor wählen',
            default                                               => 'im Journal — erkannt (' . $source . '), neuer Kreditor (Vorschlag)',
        };

        return $what . ($invoice->isBlocked() ? ' — GESPERRT: mögliches Doppel' : '') . ' (Nr. ' . $invoice->getId() . ')';
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
            'recognized' => $invoice->recognition(),
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
     * «Jetzt erkennen» (owner 2026-11-15): a submit of the window's form (`formaction`, POST,
     * `#[Csrf]`). The server re-checks: the host's route gate (the module role) and the state — an
     * open or blocked invoice only (`CreditorInvoiceService::queueRecognition()`). It only MARKS the
     * invoice «Erkennung ausstehend» and queues the job `creditor-intake`; the job recognises, filling
     * only empty fields. Nothing typed in the window is saved.
     */
    #[Csrf]
    protected function recognizeAction(): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!$request->isPost()) {
            return $this->creditorRefuse('POST erwartet.');
        }
        try {
            $invoice = $this->creditorInvoices()->queueRecognition((int) $request->getGetParameter('id'));
        } catch (CreditorRefusedException $e) {
            return $this->creditorRefuse($e->getMessage());
        }
        $this->creditorQueueRecognition();

        return $this->creditorSaved($invoice->label() . ': Erkennung ausstehend — der Job «Kreditoren-Eingang» füllt beim nächsten Lauf die leeren Felder.', 'info');
    }

    // ── tab «Nicht eingelesen»: the folder files the intake refused ──────

    /**
     * «Erneut versuchen» of a file in `failed/` (tab «Nicht eingelesen», a `data-fetch-post` form — the CSRF
     * in the header): back into the intake folder, its row gone; the next pass of `creditor-intake`
     * reads it again. Fetched: the flash and the region reloaded; as a page: back to the tab.
     */
    #[Csrf]
    protected function intakeRetryAction(): FetchResponse|RedirectResponse
    {
        if (!DI::getRequest()->isPost()) {
            return $this->creditorIntakeAnswer('error', 'POST erwartet.');
        }
        try {
            $name = $this->creditorIntakeFolder()->retry(self::creditorPostedId());
        } catch (CreditorRefusedException $e) {
            return $this->creditorIntakeAnswer('error', $e->getMessage());
        }

        return $this->creditorIntakeAnswer('success', '«' . $name . '» liegt wieder im Eingangsordner — der nächste Lauf von «Kreditoren-Eingang» liest sie ein.');
    }

    /** «Entfernen» of a file in `failed/` (tab «Nicht eingelesen»): the file deleted, its row gone. */
    #[Csrf]
    protected function intakeRemoveAction(): FetchResponse|RedirectResponse
    {
        if (!DI::getRequest()->isPost()) {
            return $this->creditorIntakeAnswer('error', 'POST erwartet.');
        }
        try {
            $this->creditorIntakeFolder()->remove(self::creditorPostedId());
        } catch (CreditorRefusedException $e) {
            return $this->creditorIntakeAnswer('error', $e->getMessage());
        }

        return $this->creditorIntakeAnswer('success', 'Datei entfernt — sie liegt nicht mehr im Ordner «failed».');
    }

    /** Fetched: the flash and the region «Nicht eingelesen» reloaded; as a page: the flash and back to the tab. */
    private function creditorIntakeAnswer(string $type, string $flash): FetchResponse|RedirectResponse
    {
        if (ListDefinition::isFetch(DI::getRequest())) {
            if ($type === 'error') {
                return $this->fetchError($flash);
            }
            $this->messageService->pushFlash($type, $flash);

            return $this->fetch()->setStatus('success')->addCommand('refresh-region', ['name' => CreditorJournalListing::intakeDefinition()->region()]);
        }
        $this->messageService->pushFlashAfterRedirect($type, $flash);

        return $this->redirect($this->creditorJournalBase() . '/list?view=' . CreditorJournalListing::VIEW_INTAKE, 303);
    }

    private static function creditorPostedId(): int
    {
        $request = DI::getRequest();

        return (int) ($request->getPostParameters()['id'] ?? $request->getGetParameter('id') ?? 0);
    }

    /**
     * «Löschen …» (for a blocked invoice «Verwerfen …»), in the window: the last look, then the
     * invoice goes with the version shown (owner 2026-11-15). Löschen: COMPLETELY — lines and intake
     * row (the same PDF may be uploaded again), the PDF into the DMS trash (restorable there).
     * Verwerfen (a confirmed duplicate): the file stays refused, the PDF stays in the DMS. The
     * confirmation says which. A posted invoice cannot be deleted.
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
                $deleted = $this->creditorInvoices()->delete((int) $invoice->getId(), (int) ($request->getPostParameters()['version'] ?? 0));
            } catch (CreditorRefusedException $e) {
                return $this->creditorRefuse($e->getMessage());
            }
            if (!$deleted['freed']) {
                return $this->creditorSaved($label . ' als Doppel verworfen. Das PDF bleibt unter «Kreditorenrechnungen» in den Dokumenten; dieselbe Datei wird nicht noch einmal eingelesen.');
            }
            try {
                $this->creditorDeletePdf($deleted['documentId']);
                $pdf = 'Das PDF liegt im Papierkorb der Dokumente.';
            } catch (\Throwable $e) {
                error_log('creditor delete: PDF ' . $deleted['documentId'] . ' not moved to the trash: ' . get_class($e) . ': ' . $e->getMessage());
                $pdf = 'Das PDF bleibt unter «Kreditorenrechnungen» in den Dokumenten (dort löschen).';
            }

            return $this->creditorSaved($label . ' gelöscht. ' . $pdf . ' Dieselbe Datei kann wieder hochgeladen werden.');
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
            'address_name' => $proposal['name'] ?? '',
            'address_street' => $proposal['street'] ?? '', 'address_house_no' => $proposal['house_no'] ?? '',
            'address_zip' => $proposal['zip'] ?? '', 'address_city' => $proposal['city'] ?? '', 'address_country' => $proposal['country'] ?? 'CH',
        ]);
        // An incomplete proposal (a QR-bill without street — the standard allows it —, an AI answer)
        // never fails silently: the form opens on «Neuen Kontakt anlegen» with the gaps marked.
        $gaps    = self::creditorProposalGaps($proposal);
        $created = null;
        if ($gaps !== [] && !$request->isPost()) {
            $form->addressValidator = new \Z77\Module\Contact\Validators\AddressValidator($form->address);
            foreach (array_keys($gaps) as $field) {
                match ($field) {
                    'street' => $form->addressValidator->validateStreet(''),
                    'zip'    => $form->addressValidator->validateZip(''),
                    'city'   => $form->addressValidator->validateCity(''),
                    default  => null,
                };
            }
        }
        $error = '';
        if ($request->isPost()) {
            $post    = $request->getPostParameters();
            $contact = null;
            if (($post['mode'] ?? '') === 'new') {
                $form = (new ContactForm(addressRequired: false))->fromPost($post);
                if ($form->save(new ContactService($this->em()))) {
                    $contact = $created = $form->contact;
                } else {
                    $error = 'Kontakt nicht angelegt — ' . self::creditorFormErrors($form);
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
                if ($created !== null) {
                    // The contact exists now — offered above as an existing one, never created twice.
                    $error = 'Kontakt «' . $created->displayName() . '» ist angelegt, aber nicht als Kreditor übernommen: ' . $error
                        . ' Korrigiere das unter Stammdaten › Finanzen › Kreditoren oder wähle den Kontakt oben unter «Bestehenden Kontakt verwenden».';
                }
            }
        }
        $query = trim((string) ($request->getGetParameter('q') ?? ($created?->displayName() ?? ($proposal['name'] ?? ''))));
        $mode  = ($request->isPost() && ($request->getPostParameters()['mode'] ?? '') === 'new' && $created === null) || ($gaps !== [] && !$request->isPost()) ? 'new' : 'existing';

        return $this->creditorWindow('adopt', $invoice, [
            'proposal' => $proposal,
            'gaps'     => $gaps,
            'query'    => $query,
            'matches'  => $query === '' ? [] : $this->em()->getRepository(Contact::class)->search($query, 20),
            'error'    => $error,
            'failed'   => $form->hasErrors() && $request->isPost(),
            'mode'     => $mode,
        ] + $form->vars(AddressTypes::from($this->em())));
    }

    /**
     * What a NEW contact needs that the proposal does not carry — field → German label (the
     * address's mandatory street, postcode and town; the name is always there, a proposal has one).
     *
     * @param array<string, string> $proposal
     * @return array<string, string>
     */
    private static function creditorProposalGaps(array $proposal): array
    {
        $labels = ['street' => 'Strasse', 'zip' => 'PLZ', 'city' => 'Ort'];

        return array_filter($labels, static fn(string $label, string $field): bool => trim($proposal[$field] ?? '') === '', ARRAY_FILTER_USE_BOTH);
    }

    /** Every refusal of a new contact in one line, each with its field («Strasse: …») — never just «markierte Eingaben». */
    private static function creditorFormErrors(ContactForm $form): string
    {
        $labels   = ['company' => 'Firma', 'name' => 'Name', 'first_name' => 'Vorname', 'street' => 'Strasse', 'house_no' => 'Hausnummer', 'zip' => 'PLZ', 'city' => 'Ort', 'country' => 'Land', 'email' => 'E-Mail', 'phone' => 'Telefon', 'type_code' => 'Adresstyp'];
        $messages = [];
        foreach ([$form->validator, $form->linkValidator, $form->addressValidator] as $validator) {
            if ($validator === null) {
                continue;
            }
            foreach ($validator->getErrors() as $message) {
                $messages[] = (string) $message;
            }
            foreach ($validator->getFieldErrors() as $field => $message) {
                $label      = $labels[$field] ?? (string) $field;
                $messages[] = str_starts_with((string) $message, $label) ? (string) $message : $label . ': ' . $message;
            }
        }

        return $messages === [] ? 'die Angaben prüfen.' : implode(' · ', array_values(array_unique($messages))) . '.';
    }

    // ── «Bezahlt am …» and «Journal verbuchen …» ─────────────────────────

    /**
     * «Bezahlt am …» for the ticked rows (plan §6) — a WINDOW: GET with the selection (`inv[]` =
     * `{id}:{version}`, the version each row showed) renders the invoices, the date (proposed today)
     * and «Bezahlt mit» (empty = unchanged); its POST (`#[Csrf]`, the same pairs as hidden fields) is
     * `CreditorInvoiceService::markPaid()`. A field refusal re-renders the window; rows changed since
     * are named in the flash, the others are written.
     */
    #[Csrf]
    protected function confirmPaidAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $post    = $request->isPost() ? $request->getPostParameters() : null;
        $pairs   = self::creditorPairs($post !== null ? ($post['inv'] ?? null) : $request->getGetParameter('inv'));
        $values  = ['paid_on' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'payment_method_code' => ''];
        $errors  = [];
        if ($post !== null) {
            $values = ['paid_on' => is_string($post['paid_on'] ?? null) ? trim($post['paid_on']) : '', 'payment_method_code' => is_string($post['payment_method_code'] ?? null) ? trim($post['payment_method_code']) : ''];
            try {
                $result = $this->creditorInvoices()->markPaid($pairs, $values['paid_on'], $values['payment_method_code']);
                $count  = count($result['paid']);
                $date   = (string) ManualEntryForm::parseDate($values['paid_on'])?->format('d.m.Y');
                $flash  = ($count === 1 ? '1 Rechnung' : $count . ' Rechnungen') . ' bezahlt am ' . $date . '.'
                    . ($result['refused'] !== [] ? ' Nicht gesetzt: ' . implode('; ', $result['refused']) . '.' : '');

                return $this->creditorSaved($flash, $result['refused'] === [] ? 'success' : 'info');
            } catch (InvalidCreditorInvoiceException $e) {
                $errors = $e->errors;
            }
        }
        $ids      = array_column($pairs, 'id');
        $invoices = array_values(array_filter(array_map(fn(int $id) => $this->creditorInvoices()->find($id), $ids)));
        $this->help->attach('Backend/CreditorJournalController/list.help', self::CREDITOR_JOURNAL_NS, [], 'Hilfe: Kreditorenjournal');
        $response = $this->html([
            'pairs'      => $pairs,
            'invoices'   => $invoices,
            'values'     => $values,
            'errors'     => $errors,
            'methods'    => (new PaymentMethodService($this->em()))->active(),
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'actionBase' => $this->creditorJournalBase(),
            'fmt'        => static fn(?Money $m) => AmountFormat::of($m),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmPaid', 'Backend/CreditorJournalController', self::CREDITOR_JOURNAL_NS);

        return $response;
    }

    /**
     * «Journal verbuchen …» — the posting WINDOW (the Belegjournal's, E16; without the script a page)
     * for the run up to the CUT-OFF on the PAYMENT date (`?cutoff=YYYY-MM-DD`, proposed today). Everything
     * from `CreditorJournalPostingService::preview()`, the strategy the posting calls: an invoice of
     * the run that cannot be posted → only the errors, each a link to its window; else count, total,
     * VAT per code and «Jetzt buchen» with the cut-off and every invoice's `{id}:{version}`.
     */
    protected function confirmPostAction(): HtmlResponse
    {
        $request = DI::getRequest();
        $raw     = trim((string) $request->getGetParameter('cutoff'));
        $cutOff  = $raw === '' ? null : ManualEntryForm::parseDate($raw);
        $note    = $raw !== '' && $cutOff === null ? 'Der Stichtag «' . $raw . '» ist kein Datum — es gilt heute.' : '';
        $cutOff ??= CreditorJournalPostingService::defaultCutOff();
        $preview = $this->creditorPosting()->preview($cutOff);
        $labels  = [];
        foreach ($preview['blocked'] as $b) {
            $labels[$b['id']] = $this->creditorInvoices()->find($b['id'])?->label() ?? 'Rechnung ' . $b['id'];
        }
        $choices = new ReceiptChoices($this->em());
        $taxes   = [];
        foreach ($preview['vat'] as $row) {
            $taxes[$row['code']] ??= $choices->taxLabel($row['code']);
        }

        $this->help->attach('Backend/CreditorJournalController/list.help', self::CREDITOR_JOURNAL_NS, [], 'Hilfe: Kreditorenjournal');
        $response = $this->html([
            'preview'    => $preview,
            'cutOff'     => $cutOff,
            'note'       => $note,
            'labels'     => $labels,
            'taxLabels'  => $taxes,
            'actionBase' => $this->creditorJournalBase(),
            'fmt'        => static fn(?Money $m) => AmountFormat::of($m),
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmPost', 'Backend/CreditorJournalController', self::CREDITOR_JOURNAL_NS);

        return $response;
    }

    /**
     * The POST of «Journal verbuchen» (`#[Csrf]`): the cut-off and the pairs the window carried to
     * `CreditorJournalPostingService::post()` — ONE unit of work, all or nothing. Fetched: the flash,
     * the window closes, the list region reloads; a refusal re-renders the window for THAT cut-off
     * with the German sentence. As a page: a 303 to «Verbuchte Journale», a refusal back to the
     * confirmation.
     */
    #[Csrf]
    protected function postAction(): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $post    = $request->isPost() ? $request->getPostParameters() : [];
        $cutOff  = ManualEntryForm::parseDate(trim(is_string($post['cutoff'] ?? null) ? $post['cutoff'] : ''));
        $back    = $this->creditorJournalBase() . '/confirm-post' . ($cutOff !== null ? '?cutoff=' . $cutOff->format('Y-m-d') : '');
        if ($cutOff === null) {
            return $this->creditorPostRefused('Nichts wurde verbucht: ohne gültigen Stichtag.', $back);
        }
        try {
            $protocol = $this->creditorPosting()->post($cutOff, self::creditorPairs($post['inv'] ?? null));
        } catch (CreditorException $e) {
            return $this->creditorPostRefused($e instanceof CreditorRefusedException ? $e->getMessage() : 'Nichts wurde verbucht: ' . $e->getMessage(), $back);
        } catch (\Doctrine\DBAL\Exception $e) {
            error_log('creditor journal posting: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->creditorPostRefused('Nichts wurde verbucht: ' . (self::creditorLockConflict($e)
                ? 'jemand anderes hat gleichzeitig an denselben Rechnungen gearbeitet — neu laden und nochmals versuchen.'
                : 'die Datenbank hat das Verbuchen abgelehnt — nochmals versuchen; bleibt es dabei, steht die Ursache im Fehlerprotokoll.'), $back);
        }
        $count = $protocol->getInvoiceCount();
        $flash = 'Kreditorenjournal verbucht bis ' . $cutOff->format('d.m.Y') . ': ' . ($count === 1 ? '1 Rechnung' : $count . ' Rechnungen') . ', Total ' . AmountFormat::of($protocol->getGrossTotal()) . '.';
        if (ListDefinition::isFetch($request)) {
            return $this->creditorSaved($flash);
        }
        $this->messageService->pushFlashAfterRedirect('success', $flash);

        return $this->redirect($this->creditorJournalBase() . '/list?view=' . CreditorJournalListing::VIEW_POSTED, 303);
    }

    /** Nothing was posted: fetched, the window again for that cut-off with the sentence; as a page, back to the confirmation. */
    private function creditorPostRefused(string $message, string $back): FetchResponse|RedirectResponse
    {
        if (ListDefinition::isFetch(DI::getRequest())) {
            return $this->fetchError($message)->addCommand('open-window', ['url' => $back, 'replace' => true]);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($back, 303);
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
        $view       = (string) (CreditorJournalListing::definition($currency)->readRequest($request)->extra('view'));
        $view       = in_array($view, CreditorJournalListing::VIEWS, true) ? $view : CreditorJournalListing::VIEW_OPEN;
        $definition = CreditorJournalListing::definition($currency, $view === CreditorJournalListing::VIEW_OPEN);
        $state      = $definition->readRequest($request);
        $totals     = $this->creditorInvoiceRows()->totalsPerState();
        $payments   = $this->creditorInvoiceRows()->paymentTotals();
        $intakes    = $this->em()->getRepository(CreditorIntake::class);
        $context    = [
            'view'         => $view,
            'totals'       => [
                'open'    => $totals[CreditorInvoice::STATE_OPEN]['count'] ?? 0,
                'gross'   => Money::fromDecimal((string) ($totals[CreditorInvoice::STATE_OPEN]['gross'] ?? '0'), $currency)->add(Money::fromDecimal((string) ($totals[CreditorInvoice::STATE_BLOCKED]['gross'] ?? '0'), $currency)),
                'blocked' => $totals[CreditorInvoice::STATE_BLOCKED]['count'] ?? 0,
                'unpaid'  => $payments['unpaid']['count'],
                'paid'    => $payments['paid']['count'],
                'failed'  => $intakes->countFailed(),
            ],
            'uploadPolicy' => $this->creditorUploadPolicy(),
            'actionBase'   => $this->creditorJournalBase(),
            'fmt'          => static fn(?Money $m) => AmountFormat::of($m),
        ];
        if ($view === CreditorJournalListing::VIEW_INTAKE) {
            // The tab «Nicht eingelesen»: the folder (created here too — the upload script never creates it)
            // and the files the intake refused, oldest first.
            $folder  = $this->creditorIntakeFolder();
            $problem = $folder->ensure();
            $iDef    = CreditorJournalListing::intakeDefinition();
            $iState  = $iDef->readRequest($request);
            $failed  = $intakes->failed();
            $failed  = $iState->descending ? array_reverse($failed) : $failed;
            $paging  = $iState->paging(count($failed));

            return $context + [
                'definition' => $iDef,
                'state'      => $iState,
                'paging'     => $paging,
                'protocols'  => [],
                'invoices'   => [],
                'lines'      => [],
                'pdfs'       => [],
                'failedRows' => array_slice($failed, $paging->offset(), $paging->pageSize),
                'fileThere'  => static fn(CreditorIntake $i): bool => $folder->failedFileExists($i),
                'folderNote' => $problem,
            ];
        }
        if ($view === CreditorJournalListing::VIEW_POSTED) {
            // The retention backfill: a posted invoice's PDF without its 10-year deadline (its write
            // failed after a commit) gets it here.
            try {
                $this->creditorRetention();
            } catch (\Throwable $e) {
                error_log('creditor retention backfill: ' . get_class($e) . ': ' . $e->getMessage());
            }
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
    private function creditorSaved(string $flash, string $type = 'success'): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!ListDefinition::isFetch($request)) {
            $this->messageService->pushFlashAfterRedirect($type, $flash);

            return $this->redirect($this->creditorJournalBase() . '/list', 303);
        }
        $this->messageService->pushFlash($type, $flash);
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
            'paid_on'             => $invoice->getPaidOn()?->format('Y-m-d') ?? '',
        ];
    }

    /**
     * The `{id}:{version}` pairs of a selection (`inv[]`) — anything else dropped, an id once.
     *
     * @return list<array{id: int, version: int}>
     */
    private static function creditorPairs(mixed $values): array
    {
        $pairs = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value) && preg_match('/^(\d{1,9}):(\d{1,9})$/', $value, $m)) {
                $pairs[(int) $m[1]] = ['id' => (int) $m[1], 'version' => (int) $m[2]];
            }
        }

        return array_values($pairs);
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
        foreach (['creditor', 'iban', 'invoice_number', 'invoice_date', 'due_date', 'reference', 'message', 'amount', 'payment_method_code', 'credit_account', 'paid_on'] as $key) {

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
