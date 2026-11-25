<?php
namespace Z77\Module\Creditor\Ui;

use Doctrine\DBAL\Exception\RetryableException,
    Z77\Core\DI,
    Z77\Core\Http\RequestMode,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\NoContentResponse,
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
    Z77\Module\Creditor\Posting\ReverseChargeCodes,
    Z77\Module\Creditor\Repositories\CreditorInvoiceLineRepository,
    Z77\Module\Creditor\Repositories\CreditorInvoiceRepository,
    Z77\Module\Creditor\Repositories\CreditorProfileRepository,
    Z77\Module\Creditor\Repositories\CreditorSearch,
    Z77\Module\Creditor\Services\CreditorBankMatch,
    Z77\Module\Creditor\Services\CreditorBankMatchService,
    Z77\Module\Creditor\Services\CreditorException,
    Z77\Module\Creditor\Services\CreditorIntakeFolder,
    Z77\Module\Creditor\Services\CreditorIntakeService,
    Z77\Module\Creditor\Services\CreditorInvoiceService,
    Z77\Module\Creditor\Services\CreditorJournalPostingService,
    Z77\Module\Creditor\Jobs\CreditorIntakeJob,
    Z77\Module\Creditor\Recognition\Ai\AiSettings,
    Z77\Module\Creditor\Recognition\Ai\AiSwitch,
    Z77\Module\Creditor\Services\CreditorRefusedException,
    Z77\Module\Creditor\Services\CreditorRetention,
    Z77\Module\Creditor\Services\InvalidCreditorInvoiceException,
    Z77\Module\Financial\Services\Actor,
    Z77\Module\Financial\Ui\ManualEntryForm,
    Z77\Module\Mandator\Services\Uid,
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
 *     button itself and the whole work area are the drop target — no band, owner 2026-11-16),
 *     beside it the switch «KI-Erkennung» (owner 2026-11-16) — only where the AI is released for
 *     the installation; «Hilfe» at its right end (HELP-003);
 *   - at the top only what needs a decision: the red band while invoices are blocked as
 *     suspected duplicates (the count line and its hints went, owner 2026-11-16 — the help
 *     explains the buttons);
 *   - the selection bar above the list: «Bezahlt am …» and «Löschen …» in «Im Journal», only
 *     «Löschen …» in «Gesperrt» (owner 2026-11-16 — a blocked invoice is decided first, not paid)
 *     for the ticked rows (`inv[]` = `{id}:{version}`, debtor's invoicing selection), «alle» in
 *     the list head; in «Nicht eingelesen» «Entfernen …» for the ticked files (`intake[]`);
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
 *   - `ai-switch` (POST, `#[Csrf]`) — the toolbar switch «KI-Erkennung»: the owner's on/off of the
 *     AI step ({@see AiSwitch}); refused while the AI is not released;
 *   - `live` (GET, the live listener of the list region and of an invoice window, fetch.md) — the
 *     list ALWAYS on «Im Journal», «Gesperrt» and «Nicht eingelesen» (owner 2026-11-16: an invoice
 *     from the intake folder must appear without a reload), a window while its invoice is «in
 *     Arbeit …» / «KI liest …»: 204 while nothing changed, else the region / the window reloads
 *     (owner 2026-11-15: the list updates itself while the job recognises);
 *   - `edit` — the invoice WINDOW: the PDF large beside the fields (`.be-docview`, the PDF through
 *     module-dms's gated preview — `ReceiptPhoto`, never an address built here); creditor, IBAN,
 *     invoice number, dates, reference, message, amount, «Bezahlt mit» / Haben, and the LINES
 *     (core.js «rows»: «+ Zeile» and × — submits without the script, `op` = `add` / `remove:<i>`);
 *     «als Standard übernehmen» when the invoice deviates from the creditor's defaults. The save
 *     is `CreditorInvoiceService::update()` with the version shown;
 *   - `creditor-fill` / `payment-fill` / `tax-fill` — the window's fill helpers (core.js «fill»: the
 *     server answers the values, no script of its own — Rule 7); `tax-fill` locks a line's «Konto»
 *     while its VAT is «nur Steuer» (VAT return stage S5, S56-2);
 *   - `confirm-bank` (ADR-061 stage C1) — «Zahlungen suchen …» (toolbar, only while a bank account
 *     reads statements) and «Zahlung suchen …» (`?id=`, one invoice's window): a WINDOW with the
 *     bank movements that fit each unlinked invoice — identities and proposals ticked in advance,
 *     candidates not; its POST (`#[Csrf]`, `take[{id}]` = `{version}:{movement}`) hands each pair
 *     to `CreditorBankMatchService::take()`. `release-movement` (POST from the window, `#[Csrf]`)
 *     — «Zuordnung lösen»: the link gone, the invoice unpaid again; refused for a posted invoice.
 *     A linked invoice shows «Bezahlt am» and its amounts read-only (they are the bank's);
 *   - `release` (POST from the window, `#[Csrf]`) — «Kein Doppel — übernehmen» of a blocked invoice;
 *   - `confirm-delete` — «Löschen …» (the invoice goes completely, its file hash is free, the PDF
 *     moves to the DMS trash) / «Verwerfen …» of a suspected duplicate (the file stays refused);
 *     a blocked invoice's window offers both (`completely=1` = Löschen, owner 2026-11-16);
 *   - `adopt` — «Kreditor übernehmen …» for an invoice with a PROPOSED creditor (stage 2 fills the
 *     proposal): an existing contact, or a new one through module-contact's `ContactForm`;
 *   - `confirm-paid` — «Bezahlt am …» for the ticked rows: a WINDOW (GET, the selection) with the
 *     date (today) and optionally «Bezahlt mit» for all; its POST is `CreditorInvoiceService::markPaid()`;
 *   - `confirm-delete-selected` — «Löschen …» for the ticked rows (owner 2026-11-16): a WINDOW
 *     (GET, the selection) naming them; its POST is `CreditorInvoiceService::deleteSelected()` —
 *     each invoice completely, open and blocked alike, the PDFs to the DMS trash;
 *   - `confirm-post` / `post` — «Journal verbuchen …»: the posting WINDOW for the run up to a
 *     cut-off on the payment date (`CreditorJournalPostingService::preview()`), its POST the run;
 *   - `intake-retry` / `intake-remove` (POST, `#[Csrf]`, tab «Nicht eingelesen», stage 2) — a file of the
 *     intake folder's `failed/` back into the folder, or deleted (`CreditorIntakeFolder`);
 *   - `confirm-intake-remove` — «Entfernen …» for the files ticked in «Nicht eingelesen» (owner
 *     2026-11-16): a WINDOW (GET, the selection) naming them; its POST is
 *     `CreditorIntakeFolder::removeSelected()` — each file through the single «Entfernen».
 *
 * The using class MUST provide (via its host base): `html()`, `fetch()`, `fetchError()`, `json()`,
 * `redirect()`, `em()`, `$layoutManager`, `$messageService`, `$help`.
 */
trait CreditorJournalControllerTrait
{
    private const CREDITOR_JOURNAL_NS = 'Z77\\Module\\Creditor';
    /** Seconds after the AI's timeout a started AI step still counts as running (CreditorInvoice::recognitionProgress()). */
    private const CREDITOR_AI_MARGIN = 60;
    /** Seconds the live listener asks without any change before it stops — a mark no job clears (no cron) is not asked about for ever. */
    private const CREDITOR_LIVE_SECONDS = 600;

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

    /** The bank match (ADR-061 stage C1); the session actor. A harness host overrides it. */
    private function creditorBankMatch(): CreditorBankMatchService
    {
        return new CreditorBankMatchService($this->em());
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

    /**
     * Moves a deleted invoice's PDF to the DMS trash through the module's own handle
     * (`CreditorIntakeService::trashPdf()` → `ModuleDrive::trash()`, owner 2026-11-20) — the
     * invoice's delete is the gate, not the session's DMS right (before, `DocumentService::delete()`
     * with the session left the PDF in place for anyone without `manage` on the partition). Throws
     * when the PDF was not moved (outside «Kreditorenrechnungen», unknown). A harness host overrides it.
     */
    private function creditorDeletePdf(int $documentId): void
    {
        if (!$this->creditorIntake()->trashPdf($documentId)) {
            throw new \RuntimeException("PDF {$documentId} is not in the module's partition (or unknown) — left in place");
        }
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

    /**
     * The AI is released for this installation (`creditorAi.enabled` in kibot's file and a readable
     * key — {@see AiSettings::enabled()}): only then the switch «KI-Erkennung» is shown and may be
     * switched. A harness host overrides it.
     */
    private function creditorAiReleased(): bool
    {
        return AiSettings::forInstallation()->enabled();
    }

    /** The owner's switch «KI-Erkennung» ({@see AiSwitch}). */
    private function creditorAiSwitch(): AiSwitch
    {
        return new AiSwitch($this->em());
    }

    /** How long a started AI step counts as running on the screen: the AI's timeout plus a margin. */
    private function creditorAiSeconds(): int
    {
        return AiSettings::forInstallation()->timeoutSeconds() + self::CREDITOR_AI_MARGIN;
    }

    /**
     * The address the live listener of an invoice WINDOW asks while the invoice is in recognition
     * (fetch.md «live listener»): `watch` = what the window shows (`id.state`), `t` = when it was
     * rendered (the bound {@see CREDITOR_LIVE_SECONDS}), `in=window`.
     */
    private function creditorWindowLiveUrl(int $id, string $progress): string
    {
        return $this->creditorJournalBase() . '/live?' . http_build_query(['watch' => self::creditorWatch([$id => $progress]), 't' => time(), 'in' => 'window']);
    }

    /**
     * The address the live listener of the LIST REGION asks — ALWAYS on the tabs «Im Journal»,
     * «Gesperrt» and «Nicht eingelesen», also on an empty list (owner 2026-11-16: «ja liste soll auch
     * ohne neuladen nachfragen» — an invoice from the intake folder appeared only after a reload).
     * `watch` = the rows shown in recognition (`id.state`, may be empty), `t` = when it was rendered,
     * `f` = {@see creditorListFingerprint()} of what the tab shows — taken BEFORE its rows are read,
     * so a write between the two makes the next question reload instead of being missed —, `view` =
     * the tab.
     *
     * @param array<int, string> $progress invoice id → CreditorInvoice::PROGRESS_*
     */
    private function creditorListLiveUrl(string $view, string $print, array $progress = []): string
    {
        return $this->creditorJournalBase() . '/live?' . http_build_query(['watch' => self::creditorWatch($progress), 't' => time(), 'f' => $print, 'view' => $view]);
    }

    /** @param array<int, string> $progress invoice id → CreditorInvoice::PROGRESS_* */
    private static function creditorWatch(array $progress): string
    {
        return implode(',', array_map(static fn(int $id, string $state): string => $id . '.' . $state, array_keys($progress), $progress));
    }

    /**
     * A short fingerprint of what a tab of the list shows: the refused files (the band «n Dateien
     * nicht eingelesen» / the tab «Nicht eingelesen») and, outside «Nicht eingelesen», every invoice
     * ({@see CreditorInvoiceRepository::liveFingerprint()} — a new one, a deleted one, any write). Two
     * cheap aggregates; equal → nothing the tab shows changed.
     */
    private function creditorListFingerprint(string $view): string
    {
        $parts = [$this->em()->getRepository(CreditorIntake::class)->failedFingerprint()];
        if ($view !== CreditorJournalListing::VIEW_INTAKE) {
            $parts[] = $this->creditorInvoiceRows()->liveFingerprint();
        }

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    /** The fetch region of a tab — «Nicht eingelesen» has its own list definition. */
    private static function creditorRegionOf(string $view): string
    {
        return $view === CreditorJournalListing::VIEW_INTAKE
            ? CreditorJournalListing::intakeDefinition()->region()
            : CreditorJournalListing::definition(ReceiptFields::currency())->region();
    }

    /**
     * `watch` = «id.state,…» → [id => state]; anything else is dropped, at most a page of rows.
     *
     * @return array<int, string>
     */
    private static function creditorWatched(mixed $raw): array
    {
        $out = [];
        foreach (explode(',', is_string($raw) ? $raw : '') as $pair) {
            if (count($out) < CreditorJournalListing::LIMIT && preg_match('/^(\d{1,10})\.(' . CreditorInvoice::PROGRESS_AI . '|' . CreditorInvoice::PROGRESS_PENDING . ')$/', $pair, $m)) {
                $out[(int) $m[1]] = $m[2];
            }
        }

        return $out;
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

    /**
     * The live listener of the list region and of an invoice window (core.js, fetch.md «live
     * listener»; owner 2026-11-15: the list did not show that the job was still recognising; owner
     * 2026-11-16: the list asks ALWAYS, also when empty — {@see creditorListLiveUrl()}). A window
     * asks only while its invoice is «in Arbeit …» / «KI liest …» ({@see creditorWindowLiveUrl()}).
     * Compares what the screen shows with the database — the rows in recognition (`watch`) and, for
     * the list, the tab's fingerprint (`f`): unchanged → 204; changed (a new invoice, a recognition
     * started or finished, a state changed, a row deleted or posted, a refused file more or less) →
     * `refresh-region` (the tab's region) / `refresh-window` (`in=window`) — core.js holds both while
     * someone is at work (a ticked row, a typed search, a changed field, an open dialog); the fresh
     * content brings its own listener with a fresh `t`, so a change restarts the bound. Unchanged
     * for {@see CREDITOR_LIVE_SECONDS} since the screen was rendered (`t`) → `stop-live`; so is an
     * address with nothing to compare (a broken `watch` and no `f`). A list address without `f`
     * (rendered before 2026-11-16) compares `watch` only. Reads only.
     */
    protected function liveAction(): FetchResponse|NoContentResponse
    {
        $request = DI::getRequest();
        $window  = $request->getGetParameter('in') === 'window';
        $watch   = self::creditorWatched($request->getGetParameter('watch'));
        $stop    = ['target' => $window ? '[data-creditor-live="invoice:' . (int) array_key_first($watch) . '"]' : '[data-creditor-live="list"]'];
        $print   = $window ? null : $request->getGetParameter('f');
        $print   = is_string($print) && preg_match('/^[0-9a-f]{16}$/', $print) ? $print : null;
        $view    = $request->getGetParameter('view');
        $view    = in_array($view, [CreditorJournalListing::VIEW_BLOCKED, CreditorJournalListing::VIEW_INTAKE], true) ? $view : CreditorJournalListing::VIEW_OPEN;
        if ($window ? count($watch) !== 1 : ($watch === [] && $print === null)) {
            return $this->fetch()->setStatus('success')->addCommand('stop-live', $stop);
        }
        $refresh = $window
            ? fn(): FetchResponse => $this->fetch()->setStatus('success')->addCommand('refresh-window')
            : fn(): FetchResponse => $this->fetch()->setStatus('success')->addCommand('refresh-region', ['name' => self::creditorRegionOf($view)]);
        $now       = new \DateTimeImmutable();
        $aiSeconds = $this->creditorAiSeconds();
        $current   = [];
        foreach ($watch === [] ? [] : $this->creditorInvoiceRows()->findBy(['id' => array_keys($watch)]) as $invoice) {
            $current[(int) $invoice->getId()] = $invoice->recognitionProgress($now, $aiSeconds);
        }
        foreach ($watch as $id => $shown) {
            if (($current[$id] ?? null) !== $shown) {
                return $refresh();
            }
        }
        if ($print !== null && !hash_equals($this->creditorListFingerprint($view), $print)) {
            return $refresh();
        }
        if ($now->getTimestamp() - (int) $request->getGetParameter('t') > self::CREDITOR_LIVE_SECONDS) {
            return $this->fetch()->setStatus('success')->addCommand('stop-live', $stop);
        }

        return $this->noContent();
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
                    $learned    = [];
                    $saved      = $this->creditorInvoices()->update((int) $invoice->getId(), (int) ($post['version'] ?? 0), $values, $rows, ($post['take_default'] ?? '') === '1', $learned);
                    $savedLines = $this->creditorLineRows()->forInvoice($saved);
                    $rcCodes    = (new ReverseChargeCodes($this->em()))->codes();
                    $flash      = $saved->label() . ' gespeichert'
                        . ($saved->isBlocked() ? ' — GESPERRT: ' . $saved->getDuplicateReason() . '.'
                            : ($saved->isComplete($savedLines, $rcCodes) ? ' — vollständig.' : ' — noch unvollständig, es fehlt: ' . implode(', ', $saved->missing($savedLines, $rcCodes)) . '.'))
                        . $this->creditorLearnedNote($learned, (string) $saved->getCreditor()?->getContact()?->displayName());
                    // ADR-061 level 1: the saved reference stands on a free bank movement → linked now.
                    $movement = $this->creditorBankMatch()->linkIdentity((int) $invoice->getId());

                    return $this->creditorSaved($flash . ($movement !== null ? ' Bankzahlung vom ' . $movement->getBookingDate()->format('d.m.Y') . ' zugeordnet (Referenz).' : ''));
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
        // «in Arbeit …» / «KI liest …»; a window (GET) then asks the live listener and reloads
        // itself when the job is done — never after a refused save (that would drop what was typed).
        $progress = $invoice->isEditable() ? $invoice->recognitionProgress(new \DateTimeImmutable(), $this->creditorAiSeconds()) : null;
        $live     = $progress !== null && !$request->isPost() && ListDefinition::isFetch($request) ? $this->creditorWindowLiveUrl((int) $invoice->getId(), $progress) : null;

        return $this->creditorWindow('edit', $invoice, [
            'values'     => $values,
            'rows'       => $rows,
            'lines'      => $lines,
            'errors'     => $errors,
            'error'      => $error,
            'creditors'  => $this->creditorChoices($creditor?->getId()),
            'methods'    => array_values(array_filter((new PaymentMethodService($this->em()))->all(), static fn(PaymentMethod $m) => $m->isActive() || $m->getCode() === $invoice->getPaymentMethodCode())),
            'choices'    => new ReceiptChoices($this->em()),
            // the accounts offered: those the lock of the VAT accounts leaves for the day the invoice is booked on
            'lockDate'   => CreditorInvoiceService::lockDate(ManualEntryForm::parseDate((string) ($values['paid_on'] ?? '')), ManualEntryForm::parseDate((string) ($values['invoice_date'] ?? ''))),
            // the currency select: CHF, receipt's list (ONE place, Rule 2) and the invoice's own
            'currencies' => array_values(array_unique([ReceiptFields::currency(), ...ReceiptFields::foreignCurrencies(), ...array_filter([$invoice->getOriginalCurrency()])])),
            'liable'     => $service->liableToVat(),
            // VAT return stage S6: the reverse-charge codes — «Rechnungsdatum» / «ESTV-Kurs» named as missing
            'reverseChargeCodes' => (new ReverseChargeCodes($this->em()))->codes(),
            'deviation'  => $this->creditorDeviation($invoice, $lines),
            'duplicate'  => $invoice->getDuplicateOf() !== null ? $this->creditorInvoiceRows()->find($invoice->getDuplicateOf()) : null,
            'proposal'   => $creditor === null ? $invoice->proposal() : [],
            'recognized' => $invoice->recognition(),
            'progress'   => $progress,
            'live'       => $live,
            // ADR-061 stage C1: the bank movement this invoice is linked to; «Zahlung suchen …» while none.
            'bankLink'      => $this->creditorBankMatch()->linkOf($invoice),
            'bankAvailable' => $this->creditorBankMatch()->available(),
        ]);
    }

    /**
     * The creditor's values for the window (core.js «fill» on the creditor select): its single
     * active IBAN, «Bezahlt mit» with its Haben, and the FIRST line's account and VAT decision —
     * «nur Steuer» locks the line's account (z77 derives it) — reads only; the save decides (and
     * fills what is still empty itself). The window's «Bezahlt am» and «Rechnungsdatum» ride along
     * (`data-fill-with`): a learned account the lock of the VAT accounts refuses on that day is
     * left out.
     */
    protected function creditorFillAction(): FetchResponse
    {
        $request = DI::getRequest();
        $profile = $this->em()->getRepository(CreditorProfile::class)->find((int) $request->getGetParameter('value'));
        if (!$profile instanceof CreditorProfile) {
            return $this->fetch()->setStatus('success')->setData(['fill' => []]);
        }
        $service  = $this->creditorInvoices();
        $payment  = $service->paymentDefaults($profile);
        $day      = static fn(string $key): ?\DateTimeImmutable => ManualEntryForm::parseDate(trim((string) $request->getGetParameter($key)));
        $defaults = $service->lineDefaults($profile, CreditorInvoiceService::lockDate($day('paid_on'), $day('invoice_date')));
        $ibans    = array_values(array_filter($this->em()->getRepository(CreditorIban::class)->forProfile($profile), static fn(CreditorIban $i) => $i->isActive()));
        $fill     = [
            'payment_method_code' => (string) $payment['code'],
            'credit_account'      => (string) $payment['credit'],
            'line_account'        => (string) $defaults['account'],
            'line_tax'            => ReceiptFields::vatToken($defaults['taxCode'], $defaults['withoutVat'], $defaults['taxOnly']),
        ];
        if (count($ibans) === 1) {
            $fill['iban'] = Iban::format($ibans[0]->getIban());
        }

        return $this->fetch()->setStatus('success')->setData(['fill' => $fill, 'lock' => ['line_account' => $defaults['taxOnly']]]);
    }

    /**
     * A line's VAT select changed (core.js «fill», the line row is the scope): «nur Steuer» empties
     * and LOCKS the line's «Konto» — z77 sets the input account of the code (S56-2); any other
     * choice frees it. Display only: the save clears and derives the account itself (Rule 7). The
     * first line's account answers to `line_account` (the creditor fill's key), the others to
     * `row_account`.
     */
    protected function taxFillAction(): FetchResponse
    {
        $taxOnly = str_ends_with(strtolower(trim((string) DI::getRequest()->getGetParameter('value'))), ReceiptFields::TAX_ONLY_SUFFIX);
        $data    = ['lock' => ['line_account' => $taxOnly, 'row_account' => $taxOnly]];
        if ($taxOnly) {
            $data['fill'] = ['line_account' => '', 'row_account' => ''];
        }

        return $this->fetch()->setStatus('success')->setData($data);
    }

    /** The payment method's Haben for the window — reads only; the save decides. */
    protected function paymentFillAction(): FetchResponse
    {
        $method = (new PaymentMethodService($this->em()))->find(trim((string) DI::getRequest()->getGetParameter('value')));

        return $this->fetch()->setStatus('success')->setData(['fill' => $method === null ? [] : ['credit_account' => $method->getCreditAccount()]]);
    }

    /**
     * «Zahlungen suchen …» / «Zahlung suchen …» (ADR-061 decision 11, stage C1) — a WINDOW: GET
     * renders, per unlinked invoice the bank holds something for (`?id=` = only that invoice), the
     * movements that fit — an identity or a proposal ticked in advance, candidates not; POST
     * (`#[Csrf]`, `take[{invoice id}]` = `{version}:{movement id}`, empty = «nicht zuordnen») takes
     * each ticked pair through `CreditorBankMatchService::take()` — each in its own unit of work;
     * a refused one is named in the flash, the others stand. The server judges every pair again.
     */
    #[Csrf]
    protected function confirmBankAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $bank    = $this->creditorBankMatch();
        if ($request->isPost()) {
            $taken   = 0;
            $refused = [];
            foreach (self::creditorBankTakes($request->getPostParameters()['take'] ?? null) as $take) {
                try {
                    $bank->take($take['id'], $take['version'], $take['movement']);
                    $taken++;
                } catch (CreditorRefusedException $e) {
                    $refused[] = $e->reason === CreditorRefusedException::CHANGED ? 'Rechnung ' . $take['id'] . ': inzwischen geändert oder gelöscht — nicht zugeordnet' : rtrim($e->getMessage(), '.');
                } catch (\Doctrine\DBAL\Exception $e) {
                    if (!self::creditorLockConflict($e)) {
                        throw $e;
                    }
                    $refused[] = 'Rechnung ' . $take['id'] . ': gleichzeitig von jemand anderem bearbeitet — nicht zugeordnet';
                }
            }
            if ($taken === 0 && $refused === []) {
                return $this->creditorRefuse('Keine Zahlung angekreuzt — nichts zugeordnet.');
            }

            return $this->creditorSaved(
                ($taken === 1 ? '1 Bankzahlung' : $taken . ' Bankzahlungen') . ' zugeordnet.' . ($refused !== [] ? ' Nicht zugeordnet: ' . implode('; ', $refused) . '.' : ''),
                $refused === [] ? 'success' : ($taken === 0 ? 'error' : 'info')
            );
        }
        $single = null;
        $id     = (int) $request->getGetParameter('id');
        if ($id > 0) {
            $single = $this->creditorInvoices()->find($id);
            if ($single === null) {
                return $this->creditorRefuse('Diese Rechnung gibt es nicht mehr — das Kreditorenjournal neu laden.');
            }
            if ($bank->linkOf($single) !== null) {
                return $this->creditorRefuse($single->label() . ' ist bereits einer Bankbewegung zugeordnet.');
            }
            $match   = $bank->find($single);
            $pending = ['rows' => $match->kind === CreditorBankMatch::NONE ? [] : [['invoice' => $single, 'match' => $match]], 'more' => false];
        } else {
            $pending = $bank->pending();
        }
        $this->help->attach('Backend/CreditorJournalController/list.help', self::CREDITOR_JOURNAL_NS, [], 'Hilfe: Kreditorenjournal');
        $response = $this->html([
            'rows'       => $pending['rows'],
            'more'       => $pending['more'],
            'single'     => $single,
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'actionBase' => $this->creditorJournalBase(),
            'fmt'        => static fn(?Money $m) => AmountFormat::of($m),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmBank', 'Backend/CreditorJournalController', self::CREDITOR_JOURNAL_NS);

        return $response;
    }

    /**
     * The ticked pairs of «Zahlungen suchen …»: `take[{invoice id}]` = `{version}:{movement id}` —
     * anything else (also the empty «nicht zuordnen») dropped.
     *
     * @return list<array{id: int, version: int, movement: int}>
     */
    private static function creditorBankTakes(mixed $values): array
    {
        $takes = [];
        foreach (is_array($values) ? $values : [] as $id => $value) {
            if ((int) $id > 0 && is_string($value) && preg_match('/^(\d{1,9}):(\d{1,9})$/', $value, $m)) {
                $takes[] = ['id' => (int) $id, 'version' => (int) $m[1], 'movement' => (int) $m[2]];
            }
        }

        return $takes;
    }

    /**
     * «Zuordnung lösen» (ADR-061 decision 20): a submit of the window's form (`formaction`), so it
     * carries the version shown. The link is gone and the invoice unpaid again; a posted invoice
     * is refused with the service's sentence.
     */
    #[Csrf]
    protected function releaseMovementAction(): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $id      = (int) $request->getGetParameter('id');
        if (!$request->isPost() || $id <= 0) {
            return $this->creditorRefuse('Diese Rechnung gibt es nicht mehr — das Kreditorenjournal neu laden.');
        }
        try {
            $released = $this->creditorInvoices()->releaseMovement($id, (int) ($request->getPostParameters()['version'] ?? 0));
        } catch (CreditorRefusedException $e) {
            return $this->creditorRefuse($e->getMessage());
        }

        return $this->creditorSaved($released->label() . ': Zuordnung zur Bankbewegung gelöst — wieder unbezahlt.');
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

    /**
     * The switch «KI-Erkennung» next to «PDF hochladen» (owner 2026-11-16) — the owner's on/off of
     * the AI step ({@see AiSwitch}, persisted in `data/framework/creditor/ai_switch.json`). With the
     * script the `.be-switch` POSTs `{value: bool}` through the core `data-fetch-toggle` contract
     * (CSRF in the header; the state is SET, not flipped, so a stale page cannot invert it; an
     * error reverts the switch); without it its form posts the checkbox (`value=1` or nothing = off)
     * with the `csrf_token` field and the page comes back with a flash.
     *
     * The server re-checks: the host's route gate (the module role), POST, the release
     * ({@see creditorAiReleased()} — the switch is not even shown otherwise) and the value's type.
     * Only FUTURE AI steps follow the switch: the job reads it at every invoice; one already waiting
     * on the AI finishes; the text recognition runs either way.
     */
    #[Csrf]
    protected function aiSwitchAction(): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $fetch   = ListDefinition::isFetch($request);
        if (!$request->isPost()) {
            return $this->creditorAiSwitchAnswer($fetch, 'error', 'POST erwartet.');
        }
        if (!$this->creditorAiReleased()) {
            return $this->creditorAiSwitchAnswer($fetch, 'error', 'Die KI-Erkennung ist für diese Installation nicht freigegeben.');
        }
        $on = $fetch
            ? ($request->getJsonBody()['value'] ?? null)
            : (($request->getPostParameters()['value'] ?? '') === '1');
        if (!is_bool($on)) {
            return $this->creditorAiSwitchAnswer($fetch, 'error', 'Ungültiger Wert für die KI-Erkennung.');
        }
        $this->creditorAiSwitch()->set($on);

        return $this->creditorAiSwitchAnswer($fetch, 'success', $on
            ? 'KI-Erkennung eingeschaltet — die KI liest Rechnungen, die das System allein nicht lesen kann.'
            : 'KI-Erkennung ausgeschaltet — neue Rechnungen liest nur das System, den Rest erfasst du von Hand.');
    }

    /** Fetched: the flash (an error reverts the switch); as a page: the flash and back to the list. */
    private function creditorAiSwitchAnswer(bool $fetch, string $type, string $flash): FetchResponse|RedirectResponse
    {
        if ($fetch) {
            if ($type === 'error') {
                return $this->fetchError($flash);
            }
            $this->messageService->pushFlash($type, $flash);

            return $this->fetch()->setStatus('success');
        }
        $this->messageService->pushFlashAfterRedirect($type, $flash);

        return $this->redirect($this->creditorJournalBase() . '/list', 303);
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

    /**
     * «Entfernen …» for the files ticked in «Nicht eingelesen» (owner 2026-11-16: «nicht eingelesen
     * sollten auch alle gelöscht werden können») — a WINDOW: GET with the selection (`intake[]` =
     * the row ids) names the files and asks; its POST (`#[Csrf]`, the same ids as hidden fields) is
     * `CreditorIntakeFolder::removeSelected()` — each file through the single «Entfernen»; a row
     * gone since is skipped and named in the flash. Nothing ticked → the window says so.
     */
    #[Csrf]
    protected function confirmIntakeRemoveAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $post    = $request->isPost() ? $request->getPostParameters() : null;
        $ids     = self::creditorIntakeIds($post !== null ? ($post['intake'] ?? null) : $request->getGetParameter('intake'));
        if ($post !== null && $ids !== []) {
            $result = $this->creditorIntakeFolder()->removeSelected($ids);
            $count  = count($result['removed']);
            $flash  = ($count === 1 ? '1 Datei' : $count . ' Dateien') . ' entfernt.'
                . ($result['refused'] !== [] ? ' Nicht entfernt: ' . implode('; ', $result['refused']) . '.' : '');
            $type   = $result['refused'] === [] ? 'success' : 'info';

            return ListDefinition::isFetch($request) ? $this->creditorSaved($flash, $type) : $this->creditorIntakeAnswer($type, $flash);
        }
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->em()->getRepository(CreditorIntake::class)->find($id);
            if ($row instanceof CreditorIntake && $row->isFailed()) {
                $rows[] = $row;
            }
        }
        $this->help->attach('Backend/CreditorJournalController/list.help', self::CREDITOR_JOURNAL_NS, [], 'Hilfe: Kreditorenjournal');
        $response = $this->html([
            'ids'        => $ids,
            'rows'       => $rows,
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'actionBase' => $this->creditorJournalBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmIntakeRemove', 'Backend/CreditorJournalController', self::CREDITOR_JOURNAL_NS);

        return $response;
    }

    /**
     * The intake row ids of a selection (`intake[]`) — digits only, anything else dropped, an id once.
     *
     * @return list<int>
     */
    private static function creditorIntakeIds(mixed $values): array
    {
        $ids = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value) && preg_match('/^\d{1,9}$/', $value) && (int) $value > 0) {
                $ids[(int) $value] = (int) $value;
            }
        }

        return array_values($ids);
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
     * «Löschen …» / «Verwerfen …» in the window: the last look, then the invoice goes with the
     * version shown (owner 2026-11-15). Löschen: COMPLETELY — lines and intake row (the same PDF
     * may be uploaded again), the PDF into the DMS trash (restorable there). Verwerfen (a blocked
     * invoice, a confirmed duplicate): the file stays refused, the PDF stays in the DMS. A blocked
     * invoice's window offers BOTH (owner 2026-11-16: «ja löschen auch im fenster anbieten») —
     * `completely=1` (in the link, then posted by the confirmation) deletes it like an open one,
     * the same `delete(…, completely: true)` the bulk «Löschen …» runs. The confirmation says
     * which. A posted invoice cannot be deleted.
     */
    #[Csrf]
    protected function confirmDeleteAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $invoice = $this->creditorInvoices()->find((int) $request->getGetParameter('id'));
        if ($invoice === null || !$invoice->isEditable()) {
            return $this->creditorRefuse('Diese Rechnung ist nicht mehr im Kreditorenjournal — neu laden.');
        }
        $completely = ($request->isPost() ? ($request->getPostParameters()['completely'] ?? null) : $request->getGetParameter('completely')) === '1';
        if ($request->isPost()) {
            $label = $invoice->label();
            try {
                $deleted = $this->creditorInvoices()->delete((int) $invoice->getId(), (int) ($request->getPostParameters()['version'] ?? 0), $completely);
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

        return $this->creditorWindow('confirmDelete', $invoice, ['completely' => $completely]);
    }

    /**
     * «Kreditor übernehmen …» (plan §5) for an invoice whose creditor is only PROPOSED: «bestehenden
     * Kontakt verwenden» (`mode=existing`, a contact found by name — `?q=` searches again inside
     * the window) or «neuen Kontakt anlegen» (`mode=new`, module-contact's `ContactForm`, prefilled
     * from the proposal). Then `CreditorInvoiceService::adoptCreditor()` — which also gives the
     * creditor to the other invoices of the same party (the flash counts them).
     *
     * NO DUPLICATE CONTACT (owner 2026-11-16, CREDITOR-ADOPT-DUP-001): when a contact of exactly
     * the same name — or the creditor carrying the proposal's UID — exists, the window opens on
     * «Bestehenden Kontakt verwenden» with it pre-selected and named, and `mode=new` creates NOTHING
     * unless the explicit confirmation `confirm_new` is posted (the server decides, Rule 7).
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
        $gaps    = self::creditorProposalGaps($proposal);
        $post    = $request->isPost() ? $request->getPostParameters() : [];
        $newMode = $request->isPost() && ($post['mode'] ?? '') === 'new';
        // The contact this proposal (or the name typed for a new one) would duplicate.
        $same    = $this->creditorSameContacts($proposal['uid'] ?? '', $proposal['name'] ?? '');
        $created = null;
        $held    = false;   // «Neuen Kontakt anlegen» held back: the same contact exists, not confirmed
        $error   = '';
        if ($gaps !== [] && !$request->isPost() && $same === []) {
            // An incomplete proposal (a QR-bill without street — the standard allows it —, an AI
            // answer) never fails silently: the form opens on «Neuen Kontakt anlegen» with the gaps marked.
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
        if ($request->isPost()) {
            $contact = null;
            if ($newMode) {
                $form = (new ContactForm(addressRequired: false))->fromPost($post);
                $same = $this->creditorSameContacts($proposal['uid'] ?? '', self::creditorContactName($form->contact));
                if ($same !== [] && ($post['confirm_new'] ?? '') !== '1') {
                    $held = true;
                } elseif ($form->save(new ContactService($this->em()))) {
                    $contact = $created = $form->contact;
                    $same    = [];
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
                    $carried = [];
                    $adopted = $this->creditorInvoices()->adoptCreditor((int) $invoice->getId(), (int) ($post['version'] ?? 0), $contact, $carried);
                    $more    = count($carried);

                    return $this->creditorSaved('Kreditor «' . $contact->displayName() . '» übernommen — ' . $adopted->label()
                        . ($more > 0 ? ' und ' . $more . ' weitere Rechnung' . ($more === 1 ? '' : 'en') . ' nennen' : ' nennt') . ' ihn jetzt.');
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
        $query   = trim((string) ($request->getGetParameter('q') ?? ($created?->displayName() ?? ($proposal['name'] ?? ''))));
        $matches = $query === '' ? [] : $this->em()->getRepository(Contact::class)->search($query, 20);
        $listed  = array_map(static fn(Contact $c): int => (int) $c->getId(), $matches);
        foreach (array_reverse($same) as $contact) {
            if (!in_array((int) $contact->getId(), $listed, true)) {
                array_unshift($matches, $contact);   // the duplicate is always offered, even when the search did not find it
            }
        }
        $mode = $same !== [] ? 'existing'
            : (($newMode && $created === null) || ($gaps !== [] && !$request->isPost()) ? 'new' : 'existing');

        return $this->creditorWindow('adopt', $invoice, [
            'proposal' => $proposal,
            'gaps'     => $gaps,
            'query'    => $query,
            'matches'  => $matches,
            'selected' => $created !== null ? (int) $created->getId() : ($same !== [] ? (int) $same[0]->getId() : 0),
            'same'     => $same,
            'held'     => $held,
            'error'    => $error,
            'failed'   => $form->hasErrors() && $request->isPost(),
            'mode'     => $mode,
        ] + $form->vars(AddressTypes::from($this->em())));
    }

    /**
     * The existing contacts a NEW contact for this proposal would duplicate (owner 2026-11-16,
     * CREDITOR-ADOPT-DUP-001): the creditor carrying the proposal's UID first (a UID names one
     * creditor), then the ACTIVE contacts of exactly the same name (module-contact's
     * `ContactRepository::findSameName()` — case and whitespace normalised, never «similar»).
     *
     * @return list<Contact>
     */
    private function creditorSameContacts(string $uid, string $name): array
    {
        $same = [];
        $uid  = Uid::normalize($uid);
        if ($uid !== '') {
            $contact = $this->em()->getRepository(CreditorProfile::class)->findByUid($uid)?->getContact();
            if ($contact instanceof Contact && $contact->isActive()) {
                $same[(int) $contact->getId()] = $contact;
            }
        }
        foreach ($this->em()->getRepository(Contact::class)->findSameName($name) as $contact) {
            $same[(int) $contact->getId()] ??= $contact;
        }

        return array_values($same);
    }

    /** The name a new contact is compared by: the company of an organisation, else «first last». */
    private static function creditorContactName(Contact $contact): string
    {
        return $contact->isOrganisation() ? $contact->getCompany() : trim($contact->getFirstName() . ' ' . $contact->getLastName());
    }

    /**
     * What the creditor learned from a save (owner 2026-11-16) as one sentence for the flash —
     * « Konto 6500 als Standard für Digitec Galaxus AG gespeichert.»; '' when nothing.
     *
     * @param array{account?: string, taxCode?: ?string, withoutVat?: bool, taxOnly?: bool, paymentMethod?: string} $learned
     */
    private function creditorLearnedNote(array $learned, string $creditor): string
    {
        $parts = [];
        if (isset($learned['account'])) {
            $parts[] = 'Konto ' . $learned['account'];
        }
        if (!empty($learned['taxOnly'])) {
            // «nur Steuer» learned with its code (S56-4, board S5-2 part 3)
            $parts[] = 'Konto automatisch (nur Steuer)';
            $parts[] = 'MWST «' . $learned['taxCode'] . ' — nur Steuer»';
        } elseif (array_key_exists('taxCode', $learned)) {
            $parts[] = $learned['taxCode'] !== null ? 'MWST ' . $learned['taxCode'] : 'ohne MWST';
        }
        if (isset($learned['paymentMethod'])) {
            $method  = (new PaymentMethodService($this->em()))->find($learned['paymentMethod']);
            $parts[] = '«Bezahlt mit» ' . ($method instanceof PaymentMethod ? $method->getLabel() : $learned['paymentMethod']);
        }
        if ($parts === []) {
            return '';
        }
        $last = array_pop($parts);

        return ' ' . ($parts === [] ? $last : implode(', ', $parts) . ' und ' . $last) . ' als Standard für ' . $creditor . ' gespeichert.';
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
     * «Löschen …» for the ticked rows of «Im Journal» or «Gesperrt» (owner 2026-11-16: «alle
     * auswählen und löschen»; «gesperrte müsste man löschen können genau gleich») — a WINDOW: GET
     * with the selection (`inv[]` = `{id}:{version}`) names the invoices and asks; its POST
     * (`#[Csrf]`, the same pairs as hidden fields) is `CreditorInvoiceService::deleteSelected()`:
     * each invoice through the single delete, COMPLETELY (lines and intake row — the same file may
     * be uploaded again), each at the version its row showed; a row changed, posted or deleted
     * since is skipped and named in the flash. The PDFs move to the DMS trash. The server re-checks
     * everything; a deleted invoice's recognition stops by itself (the job finds no row).
     */
    #[Csrf]
    protected function confirmDeleteSelectedAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $post    = $request->isPost() ? $request->getPostParameters() : null;
        $pairs   = self::creditorPairs($post !== null ? ($post['inv'] ?? null) : $request->getGetParameter('inv'));
        $errors  = [];
        if ($post !== null) {
            try {
                $result = $this->creditorInvoices()->deleteSelected($pairs);
            } catch (InvalidCreditorInvoiceException $e) {
                $result = null;
                $errors = $e->errors;
            }
            if ($result !== null) {
                $kept = 0;
                foreach ($result['deleted'] as $gone) {
                    try {
                        $this->creditorDeletePdf($gone['documentId']);
                    } catch (\Throwable $e) {
                        $kept++;
                        error_log('creditor bulk delete: PDF ' . $gone['documentId'] . ' not moved to the trash: ' . get_class($e) . ': ' . $e->getMessage());
                    }
                }
                $count = count($result['deleted']);
                $flash = ($count === 1 ? '1 Rechnung' : $count . ' Rechnungen') . ' gelöscht.'
                    . ($count > 0 && $kept === 0 ? ' Die PDFs liegen im Papierkorb der Dokumente; dieselben Dateien können wieder hochgeladen werden.' : '')
                    . ($kept > 0 ? ' ' . ($kept === 1 ? '1 PDF bleibt' : $kept . ' PDFs bleiben') . ' unter «Kreditorenrechnungen» in den Dokumenten (dort löschen).' : '')
                    . ($result['refused'] !== [] ? ' Nicht gelöscht: ' . implode('; ', $result['refused']) . '.' : '');

                return $this->creditorSaved($flash, $result['refused'] === [] && $kept === 0 ? 'success' : 'info');
            }
        }
        $ids      = array_column($pairs, 'id');
        $invoices = array_values(array_filter(array_map(fn(int $id) => $this->creditorInvoices()->find($id), $ids)));
        $this->help->attach('Backend/CreditorJournalController/list.help', self::CREDITOR_JOURNAL_NS, [], 'Hilfe: Kreditorenjournal');
        $response = $this->html([
            'pairs'      => $pairs,
            'invoices'   => $invoices,
            'errors'     => $errors,
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'actionBase' => $this->creditorJournalBase(),
            'fmt'        => static fn(?Money $m) => AmountFormat::of($m),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmDeleteSelected', 'Backend/CreditorJournalController', self::CREDITOR_JOURNAL_NS);

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
        // VAT return stage S6: an unpaid invoice whose reverse-charge tax entry the run posts is no «bezahlte Rechnung»
        $unpaidBlocked = count(array_filter($preview['blocked'], static fn(array $b): bool => in_array($b['id'], array_column($preview['taxSeen'], 'id'), true)));
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
            'unpaidBlocked' => $unpaidBlocked,
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
        $taxPairs = self::creditorPairs($post['tax'] ?? null);   // reverse-charge tax entries of unpaid invoices (stage S6)
        try {
            $protocol = $this->creditorPosting()->post($cutOff, self::creditorPairs($post['inv'] ?? null), $taxPairs);
        } catch (CreditorException $e) {
            return $this->creditorPostRefused($e instanceof CreditorRefusedException ? $e->getMessage() : 'Nichts wurde verbucht: ' . $e->getMessage(), $back);
        } catch (\Doctrine\DBAL\Exception $e) {
            error_log('creditor journal posting: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->creditorPostRefused('Nichts wurde verbucht: ' . (self::creditorLockConflict($e)
                ? 'jemand anderes hat gleichzeitig an denselben Rechnungen gearbeitet — neu laden und nochmals versuchen.'
                : 'die Datenbank hat das Verbuchen abgelehnt — nochmals versuchen; bleibt es dabei, steht die Ursache im Fehlerprotokoll.'), $back);
        }
        $taxes = count($taxPairs);
        $flash = 'Kreditorenjournal verbucht bis ' . $cutOff->format('d.m.Y') . ': '
            . ($protocol !== null ? ($protocol->getInvoiceCount() === 1 ? '1 Rechnung' : $protocol->getInvoiceCount() . ' Rechnungen') . ', Total ' . AmountFormat::of($protocol->getGrossTotal()) : '')
            . ($taxes > 0 ? ($protocol !== null ? '; ' : '') . 'Bezugsteuer am Rechnungsdatum für ' . ($taxes === 1 ? '1 unbezahlte Rechnung' : $taxes . ' unbezahlte Rechnungen') : '') . '.';
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
        $definition = CreditorJournalListing::definition($currency, in_array($view, [CreditorJournalListing::VIEW_OPEN, CreditorJournalListing::VIEW_BLOCKED], true));
        $state      = $definition->readRequest($request);
        // The live listener's fingerprint FIRST — what is read below is at least this new.
        $print      = $view === CreditorJournalListing::VIEW_POSTED ? null : $this->creditorListFingerprint($view);
        $totals     = $this->creditorInvoiceRows()->totalsPerState();
        $payments   = $this->creditorInvoiceRows()->paymentTotals();
        $intakes    = $this->em()->getRepository(CreditorIntake::class);
        $context    = [
            'view'         => $view,
            'totals'       => [
                'open'    => $totals[CreditorInvoice::STATE_OPEN]['count'] ?? 0,
                'blocked' => $totals[CreditorInvoice::STATE_BLOCKED]['count'] ?? 0,
                'paid'    => $payments['paid']['count'],
                'failed'  => $intakes->countFailed(),
            ],
            'uploadPolicy' => $this->creditorUploadPolicy(),
            // The switch «KI-Erkennung»: null = the AI is not released here (no switch rendered).
            'aiSwitch'     => $this->creditorAiReleased() ? $this->creditorAiSwitch()->isOn() : null,
            // «Zahlungen suchen …» only while a bank account reads statements (ADR-061 stage C1).
            'bankAvailable' => $this->creditorBankMatch()->available(),
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
                'live'       => $this->creditorListLiveUrl($view, (string) $print),
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
        // The rows the job is still recognising («in Arbeit …» / «KI liest …») — watched by the
        // region's live listener, which it carries always (owner 2026-11-16).
        $now       = new \DateTimeImmutable();
        $aiSeconds = $this->creditorAiSeconds();
        $progress  = [];
        foreach ($invoices as $invoice) {
            $shown = $invoice->recognitionProgress($now, $aiSeconds);
            if ($shown !== null) {
                $progress[(int) $invoice->getId()] = $shown;
            }
        }

        return $context + [
            'progress'   => $progress,
            'live'       => $this->creditorListLiveUrl($view, (string) $print, $progress),
            'definition' => $definition,
            'state'      => $state,
            'paging'     => $paging,
            'protocols'  => [],
            'invoices'   => $invoices,
            'lines'      => $this->creditorLineRows()->forInvoices(array_map(static fn(CreditorInvoice $i) => (int) $i->getId(), $invoices)),
            'pdfs'       => $this->creditorPdfs(array_map(static fn(CreditorInvoice $i) => $i->getDocumentId(), $invoices)),
            // Which rows carry «Bezahlt am» from the bank (one read).
            'bankLinks'  => $invoices === [] ? [] : $this->creditorBankMatch()->linksOf($invoices),
            // VAT return stage S6: a reverse-charge line needs «Rechnungsdatum» / «ESTV-Kurs» (the row's «es fehlt»)
            'reverseChargeCodes' => $invoices === [] ? [] : (new ReverseChargeCodes($this->em()))->codes(),
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
     * invoice's first line or «Bezahlt mit» differ — or (owner 2026-11-17) when the first line
     * carries a text other than the creditor's standard line text («Kontierungstext», also while
     * none is set: the switch is how the owner sets it from an invoice); null otherwise (the window
     * then offers no «als Standard übernehmen»).
     *
     * @param list<CreditorInvoiceLine> $lines
     */
    private function creditorDeviation(CreditorInvoice $invoice, array $lines): ?string
    {
        $creditor = $invoice->getCreditor();
        $first    = $lines[0] ?? null;
        if ($creditor === null || $first === null) {
            return null;
        }
        $standard = (string) $creditor->getDefaultLineText();
        $textOff  = $first->getText() !== '' && $first->getText() !== $standard;
        $decided  = $creditor->getDefaultsSetAt() !== null;
        $defaultTaxOnly = $creditor->isDefaultTaxOnly() && $creditor->getDefaultTaxCode() !== null;
        $same = $first->getAccount() === $creditor->getDefaultAccount()
            && $first->getTaxCode() === $creditor->getDefaultTaxCode()
            && $first->isWithoutVat() === ($creditor->getDefaultTaxCode() === null && $creditor->isDefaultWithoutVat())
            && $first->isTaxOnly() === $defaultTaxOnly
            && $invoice->getPaymentMethodCode() === $creditor->getDefaultPaymentMethodCode();
        if (($same || !$decided) && !$textOff) {
            return null;
        }
        $parts = array_filter([
            $creditor->getDefaultAccount(),
            $defaultTaxOnly ? $creditor->getDefaultTaxCode() . ' nur Steuer' : ($creditor->getDefaultTaxCode() ?? ($creditor->isDefaultWithoutVat() ? 'ohne MWST' : null)),
            $creditor->getDefaultPaymentMethodCode(),
            $standard !== '' ? '«' . $standard . '»' : null,
        ]);

        return implode(' · ', $parts);   // '' = no standard yet (the switch then names none)
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
            'currency'            => $invoice->getOriginalCurrency() ?? ReceiptFields::currency(),
            'original_amount'     => $invoice->getOriginal()?->toDecimal() ?? '',
            'amount'              => $invoice->getAmount()?->toDecimal() ?? '',
            'exchange_rate'       => $invoice->getExchangeRate() !== null ? ReceiptFields::formatRate($invoice->getExchangeRate()) : '',
            'estv_rate'           => $invoice->getEstvRate() !== null ? ReceiptFields::formatRate($invoice->getEstvRate()) : '',
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
            // module-receipt's VAT token: «-», a code, or a code + «:nur-steuer»
            'tax'     => ReceiptFields::vatToken($l->getTaxCode(), $l->isWithoutVat(), $l->isTaxOnly()),
            'amount'  => $l->getAmount()?->toDecimal() ?? '',
        ], $lines);
    }

    /** The same shape out of a POST — strings only, anything else empty (the service judges each one). */
    private static function creditorInvoicePosted(array $post): array
    {
        $values = [];
        foreach (['creditor', 'iban', 'invoice_number', 'invoice_date', 'due_date', 'reference', 'message', 'currency', 'original_amount', 'amount', 'exchange_rate', 'payment_method_code', 'credit_account', 'paid_on'] as $key) {

            $values[$key] = is_string($post[$key] ?? null) ? $post[$key] : '';
        }
        // «ESTV-Kurs» (VAT return stage S6): rendered for a foreign invoice only — ABSENT = unchanged.
        if (array_key_exists('estv_rate', $post)) {
            $values['estv_rate'] = is_string($post['estv_rate']) ? $post['estv_rate'] : '';
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
