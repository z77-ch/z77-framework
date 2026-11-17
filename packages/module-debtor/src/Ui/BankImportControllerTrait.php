<?php
namespace Z77\Module\Debtor\Ui;

use Doctrine\DBAL\Exception as DBALException,
    Z77\Core\DI,
    Z77\Core\Http\RequestMode,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Debtor\Accounting\AccountingRefusedException,
    Z77\Module\Debtor\Entities\BankMessage,
    Z77\Module\Debtor\Entities\BankTransaction,
    Z77\Module\Debtor\Entities\TransactionState,
    Z77\Core\Http\WindowOrigin,
    Z77\Module\Debtor\Services\BankEntryLinkService,
    Z77\Module\Debtor\Services\BankImportArchive,
    Z77\Module\Debtor\Services\BankImportRefusedException,
    Z77\Module\Debtor\Services\BankImportService,
    Z77\Module\Debtor\Services\DebtorException,
    Z77\Module\Debtor\Services\InvoicingService,
    Z77\Module\Debtor\Services\LockConflict,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Money\AmountFormat,
    Z77\Shared\Money\Money,
    Z77\Shared\Upload\UploadPolicy,
    Z77\Core\Services\TemplateRenderer
;

/**
 * «Zahlungseingänge» — the CAMT.054 import screens (P4 part 2, plan §6.4),
 * mounted by a thin host controller in the backend (ADR-018 pattern, the
 * invoice model): `use BankImportControllerTrait` + a one-line layout
 * config delegating to {@see BankImportLayout::config()}. In the
 * navigation under «Aufträge» (ADR-050), URL group `finance`.
 *
 *   - `list` — the imported messages, newest first, with the counts per
 *     state, in two toolbar tabs (owner 2026-11-15): «Offen» (the default —
 *     a message with a transaction `unmatched` or `matched`) and «Erledigt»
 *     (`?view=done`, every transaction booked or ignored); the UPLOAD in the
 *     action cell (`#[Csrf]`) on both;
 *   - `upload` — the POST: `BankImportService::import()` → the detail of
 *     the new message; a refusal (not a camt.054, imported already, the
 *     IBAN no payment target) is the flash;
 *   - `detail` — one message with its transactions: state, the matched
 *     invoice (a link to its detail), what is open on it, the note; per
 *     transaction the small forms «Zuordnen» (a document number),
 *     «Ignorieren» / «Zurücknehmen»; «Verbuchen» for every matched one;
 *   - `book` — the POST of «Verbuchen», back to the detail with the flash
 *     (every row changes — a page is right here);
 *   - `assign` / `ignore` — ONE row changes (ADR-047 addendum 2026-10-10):
 *     fetched (`data-fetch-post`), the answer replaces that row
 *     (`replaceRow('bank-transaction', id)`) and the unbooked bar, with the
 *     flash; a page POST (no script) goes back to the detail with the flash
 *     as before;
 *   - `bank-match` / `bank-match-release` — «Bankabgleich …» (ADR-061
 *     stage C3b): the camt.054 entries and their camt.053 collective
 *     credits, a window ({@see bankMatchAction()}).
 *
 * No JavaScript of its own (Rule 7). Every write goes through
 * {@see BankImportService}.
 */
trait BankImportControllerTrait
{
    /** Template namespace of this fragment. */
    private const BANK_NS = 'Z77\Module\Debtor';

    /** The tabs of the list (owner 2026-11-15): view → label; the first is the default, the plain `/list`. */
    private const BANK_VIEWS = ['open' => 'Offen', 'done' => 'Erledigt'];

    /** The largest file the upload takes — a camt.054 of a month is a few hundred KB. */
    private const BANK_FILE_LIMIT = 5 * 1024 * 1024;

    /** URL root of THIS mount — every link and form is built from it. */
    protected function bankImportListBase(): string
    {
        return '/backend/finance/bank-import';
    }

    /** The one write path; the session actor. A harness host overrides it with a named actor. */
    private function bankImportService(): BankImportService
    {
        return new BankImportService($this->em());
    }

    private function bankInvoicingService(): InvoicingService
    {
        return new InvoicingService($this->em());
    }

    /** The link to the camt.053 (ADR-061 stage C3b); the session actor. A harness host overrides it. */
    private function bankEntryLinks(): BankEntryLinkService
    {
        return new BankEntryLinkService($this->em());
    }

    /**
     * What this screen lets in (the ONE place, read by the client's attributes AND by the
     * server's check — `UploadPolicy`): camt.054 messages are XML, a month is a few hundred
     * KB, several files in one go is the normal case (a bank hands out one per day). A ZIP of
     * such XML files too (owner 2026-11-13 — a bank delivers one): each XML inside is
     * imported on its own ({@see bankImportZip()}).
     *
     * `onConflict: error` — a message already imported is not something to overwrite: the
     * transactions behind it may be booked. The import service refuses it by message id and
     * the row says so.
     */
    private function bankUploadPolicy(): UploadPolicy
    {
        return new UploadPolicy(
            endpoint:   $this->bankImportListBase() . '/upload',
            field:      'file',
            multiple:   true,
            accept:     ['.xml', '.zip'],
            maxBytes:   self::BANK_FILE_LIMIT,
            onConflict: UploadPolicy::CONFLICT_ERROR,
            // The action cell is narrow and the label stands next to an ↑: «camt.054
            // Upload» says it, «camt.054-Dateien hierher ziehen» overflows the cell
            // (owner 2026-10-10).
            label:      'camt.054 Upload',
            hint:       'XML oder ZIP · bis 5 MB · mehrere',
        );
    }

    /** @param array<string, mixed> $context */
    private function bankImportPage(string $template, array $context): HtmlResponse
    {
        $context += [
            'actionBase'   => $this->bankImportListBase(),
            'uploadPolicy' => $this->bankUploadPolicy(),
            'fmt'          => static fn(?Money $m) => AmountFormat::of($m),
        ];
        $response = $this->html($context);
        // The fragment owns its header slot (financial.md, «fragment slots»): «+ camt.054 einlesen»
        // is the entry's most frequent action → action cell (ADR-033 rev. 2026-10-08).
        $this->layoutManager->addPartials('act', 'Backend/BankImportController', self::BANK_NS, 'hc1');
        if ($template !== 'listAction') {
            $this->layoutManager->removeSection('main');
            $this->layoutManager->addPartials($template, 'Backend/BankImportController', self::BANK_NS);
        }

        return $response;
    }

    private function bankMessageNotFound(): RedirectResponse
    {
        $this->messageService->pushFlashAfterRedirect('error', 'Meldung nicht gefunden');

        return $this->redirect($this->bankImportListBase() . '/list', 303);
    }

    // ── list + upload ────────────────────────────────────────────────────

    #[Csrf]
    protected function listAction(): HtmlResponse
    {
        // The list's help (HELP-003): «Hilfe» at the right end of the toolbar (the message
        // detail has its own, `detail.help`).
        $this->help->attach('Backend/BankImportController/list.help', self::BANK_NS, [], 'Hilfe: Zahlungseingänge', toolbar: true);
        $view = (string) DI::getRequest()->getGetParameter('view') === 'done' ? 'done' : 'open';

        $response = $this->bankImportPage('listAction', [
            'messages' => $this->bankImportService()->recent(50, $view === 'done'),
            'view'      => $view,
            'views'     => self::BANK_VIEWS,
            'states'    => self::bankStateLabels(),
            'bankMatch' => $this->bankEntryLinks()->available(),
        ]);
        // The tabs «Offen» · «Erledigt» → toolbar (hc2), like the other lists of the area.
        $this->layoutManager->addPartials('toolbar', 'Backend/BankImportController', self::BANK_NS, 'hc2');

        return $response;
    }

    /**
     * The upload (POST, ONE file per request in `file`) — two answers for one body:
     *
     *  - **fetch mode** (the upload component, one request per file): the per-file envelope
     *    `{status, name, message, commands}`. The component shows the message AT the file
     *    and runs the commands once the whole queue is through, so ten files give one
     *    reload and ten rows, not ten redirects.
     *  - **page mode** (no JavaScript, the form posted itself): the flash + redirect as
     *    before — one file, straight to its detail.
     *
     * The gate is {@see UploadPolicy::check()} in both cases: the client's own check spares
     * a round trip, it does not replace this one.
     */
    #[Csrf]
    protected function uploadAction(): RedirectResponse|JsonResponse
    {
        $request = DI::getRequest();
        $fetch   = $request->getMode() === RequestMode::Fetch;
        $policy  = $this->bankUploadPolicy();
        $list    = $this->bankImportListBase() . '/list';

        if (!$request->isPost()) {
            return $fetch
                ? $this->json(['status' => 'error', 'name' => '', 'message' => 'POST erwartet'], 405)
                : $this->redirect($list, 303);
        }

        $file = $request->getUploadedFile('file');
        if ($file === null) {
            return $this->bankUploadRefusal($fetch, '', 'Keine Datei empfangen — eine camt.054-Datei (XML) wählen.');
        }

        $refusal = $policy->check($file);
        if ($refusal !== null) {
            return $this->bankUploadRefusal($fetch, $file->originalName, $file->originalName . ': ' . $refusal);
        }
        if (strtolower(pathinfo($file->originalName, PATHINFO_EXTENSION)) === 'zip') {
            return $this->bankImportZip($fetch, $file->originalName, (string) $file->bytes());
        }

        try {
            $message = $this->bankImportService()->import((string) $file->bytes(), $file->originalName);
            $counts  = $message->countPerState();
            $summary = 'Meldung ' . $message->getMessageId() . ' importiert: '
                . count($message->getTransactions()) . ' Transaktionen, '
                . $counts['matched'] . ' zugeordnet, ' . $counts['unmatched'] . ' offen, '
                . $counts['ignored'] . ' ignoriert.';

            if ($fetch) {
                // The list behind the component shows the new message — its region replaced,
                // not the page reloaded: the rows of files refused in the same run stay
                // readable (owner 2026-11-13, upload.js `leavesRows()`).
                return $this->json([
                    'status'   => 'ok',
                    'name'     => $file->originalName,
                    'message'  => $counts['matched'] . ' zugeordnet, ' . $counts['unmatched'] . ' offen',
                    'commands' => $this->bankListCommands(),
                ]);
            }

            $this->messageService->pushFlashAfterRedirect('success', $summary);

            return $this->redirect($this->bankImportListBase() . '/detail?id=' . $message->getId(), 303);
        } catch (DebtorException $e) {
            return $this->bankUploadRefusal($fetch, $file->originalName, $e->getMessage());
        }
    }

    /**
     * A ZIP of camt.054 files (owner 2026-11-13): every XML inside imported on its own, each in
     * its own unit of work — a message imported before (`DUPLICATE_MESSAGE`, typically the XML
     * uploaded alone the same day) is skipped quietly, not a failure. ONE answer for the ZIP:
     * ok when nothing failed («2 Meldungen importiert, 1 bereits vorhanden»), else an error that
     * names each refused file — the ones imported stay imported. Page mode: the flash and the list.
     */
    private function bankImportZip(bool $fetch, string $zipName, string $bytes): RedirectResponse|JsonResponse
    {
        try {
            $files = BankImportArchive::xmlFiles($bytes);
        } catch (DebtorException $e) {
            return $this->bankUploadRefusal($fetch, $zipName, $zipName . ': ' . $e->getMessage());
        }

        $imported = 0;
        $skipped  = 0;
        $failed  = [];
        foreach ($files as $entry) {
            try {
                $this->bankImportService()->import($entry['xml'], $entry['name']);
                $imported++;
            } catch (BankImportRefusedException $e) {
                if ($e->reason === BankImportRefusedException::DUPLICATE_MESSAGE) {
                    $skipped++;
                } else {
                    $failed[] = $entry['name'] . ': ' . $e->getMessage();
                }
            } catch (DebtorException $e) {
                $failed[] = $entry['name'] . ': ' . $e->getMessage();
            }
        }

        // Owner 2026-11-14: a ZIP of messages read before must SAY so — «keine neue Meldung».
        $summary = $imported === 0
            ? 'keine neue Meldung — ' . ($skipped === 1 ? 'sie war bereits eingelesen' : 'alle ' . $skipped . ' waren bereits eingelesen')
            : ($imported === 1 ? '1 Meldung importiert' : $imported . ' Meldungen importiert')
                . ($skipped > 0 ? ', ' . $skipped . ' bereits vorhanden' : '');
        if ($failed !== []) {
            $message = $zipName . ': ' . $summary . ' — nicht übernommen: ' . implode(' · ', $failed);
            if ($fetch && $imported > 0) {
                // The imported ones show up in the list; the row keeps saying what was refused.
                return $this->json(['status' => 'error', 'name' => $zipName, 'message' => $message, 'commands' => $this->bankListCommands()]);
            }

            return $this->bankUploadRefusal($fetch, $zipName, $message);
        }
        if ($fetch) {
            return $this->json([
                'status'   => 'ok',
                'name'     => $zipName,
                'message'  => $summary,
                'commands' => $this->bankListCommands(),
            ]);
        }
        $this->messageService->pushFlashAfterRedirect('success', $zipName . ': ' . $summary . '.');

        return $this->redirect($this->bankImportListBase() . '/list', 303);
    }

    /** One refusal, two shapes — the row's message in fetch mode, the flash on a page. */
    private function bankUploadRefusal(bool $fetch, string $name, string $message): RedirectResponse|JsonResponse
    {
        if ($fetch) {
            return $this->json(['status' => 'error', 'name' => $name, 'message' => $message]);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($this->bankImportListBase() . '/list', 303);
    }

    // ── detail and the actions on it ─────────────────────────────────────

    protected function detailAction(): HtmlResponse|RedirectResponse
    {
        $message = $this->bankMessageOf();
        if ($message === null) {
            return $this->bankMessageNotFound();
        }
        $open = [];
        foreach ($message->getTransactions() as $transaction) {
            $invoice = $transaction->getInvoice();
            if ($invoice !== null && !isset($open[$invoice->getId()])) {
                $open[$invoice->getId()] = $this->bankInvoicingService()->openAmount($invoice);
            }
        }
        // The message's own help (ADR-048): «Hilfe» in the top bar — the states, «Zuordnen», «Verbuchen».
        $this->help->attach('Backend/BankImportController/detail.help', self::BANK_NS, [], 'Hilfe: Meldung');

        return $this->bankImportPage('detail', [
            'message'     => $message,
            'open'        => $open,
            'counts'      => $message->countPerState(),
            'states'      => self::bankStateLabels(),
            'invoiceBase' => '/backend/finance/invoice',
        ]);
    }

    /** «Verbuchen»: every matched transaction of the message, one unit of work. */
    #[Csrf]
    protected function bookAction(): RedirectResponse
    {
        $message = $this->bankMessageOf();
        if ($message === null || !DI::getRequest()->isPost()) {
            return $this->bankMessageNotFound();
        }
        try {
            $booked = $this->bankImportService()->book((int) $message->getId());
            $counts = $booked->countPerState();
            $this->messageService->pushFlashAfterRedirect('success', 'Verbucht: ' . $counts['booked'] . ' Zahlungseingänge, ' . $counts['unmatched'] . ' offen geblieben.');
        } catch (DebtorException | AccountingRefusedException $e) {
            $this->messageService->pushFlashAfterRedirect('error', 'Nichts verbucht — ' . $e->getMessage());
        } catch (DBALException $e) {
            // A deadlock or lock wait timeout: nothing was written — say so instead of a 500 (review 2026-10-13 H4).
            if (!LockConflict::is($e)) {
                throw $e;
            }
            $this->messageService->pushFlashAfterRedirect('error', 'Nichts verbucht — ' . LockConflict::MESSAGE);
        }

        return $this->redirect($this->bankImportListBase() . '/detail?id=' . $message->getId(), 303);
    }

    /** «Zuordnen»: `transaction` + `number` (the document number) → matched. */
    #[Csrf]
    protected function assignAction(): RedirectResponse|FetchResponse
    {
        $message = $this->bankMessageOf();
        if ($message === null || !DI::getRequest()->isPost()) {
            return $this->bankMessageNotFound();
        }
        $post = $this->bankRowPost();
        try {
            $transaction = $this->bankImportService()->assign((int) ($post['transaction'] ?? 0), (int) ($post['number'] ?? 0));

            return $this->bankRowAnswer($message, $transaction, 'Transaktion ' . $transaction->getPosition() . ' zugeordnet: ' . $transaction->getInvoice()?->documentName());
        } catch (DebtorException $e) {
            return $this->bankRowRefusal($message, $e->getMessage());
        } catch (DBALException $e) {
            if (!LockConflict::is($e)) {
                throw $e;
            }

            return $this->bankRowRefusal($message, LockConflict::MESSAGE);
        }
    }

    /** «Ignorieren» (`value` 1) / «Zurücknehmen» (`value` 0) for `transaction`. */
    #[Csrf]
    protected function ignoreAction(): RedirectResponse|FetchResponse
    {
        $message = $this->bankMessageOf();
        if ($message === null || !DI::getRequest()->isPost()) {
            return $this->bankMessageNotFound();
        }
        $post = $this->bankRowPost();
        try {
            $ignore      = (string) ($post['value'] ?? '1') !== '0';
            $transaction = $this->bankImportService()->ignore((int) ($post['transaction'] ?? 0), $ignore, $ignore ? null : 'Zurückgenommen — bitte zuordnen.');

            return $this->bankRowAnswer($message, $transaction, 'Transaktion ' . $transaction->getPosition() . ($ignore ? ' ignoriert.' : ' wieder offen.'));
        } catch (DebtorException $e) {
            return $this->bankRowRefusal($message, $e->getMessage());
        } catch (DBALException $e) {
            if (!LockConflict::is($e)) {
                throw $e;
            }

            return $this->bankRowRefusal($message, LockConflict::MESSAGE);
        }
    }

    // ── «Bankabgleich …» (ADR-061 stage C3b) ─────────────────────────────

    /**
     * «Bankabgleich …» (toolbar, only while a bank account reads camt.053) — a WINDOW (without the
     * script a page). GET renders `BankEntryLinkService::overview()`: per camt.054 entry or group
     * of old payments the camt.053 credits that fit — an identity (the bank's entry reference) or
     * the unique proposal ticked in advance, candidates not, an «Abweichung» (sum or count differ)
     * shown without a choice; below, the confirmed proposals with «Zuordnung lösen». POST
     * (`#[Csrf]`, `take[n]` = `e|{movement}|{entry ref}` or `d|{movement}|{message id}|{Y-m-d}`)
     * takes each ticked pair, each in its own unit of work; the service judges every pair again.
     */
    #[Csrf]
    protected function bankMatchAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $links   = $this->bankEntryLinks();
        if ($request->isPost()) {
            $taken   = 0;
            $refused = [];
            foreach (self::bankMatchTakes($request->getPostParameters()['take'] ?? null) as $take) {
                try {
                    $take['kind'] === 'e'
                        ? $links->takeEntry($take['ref'], $take['movement'])
                        : $links->takeDay($take['message'], $take['day'], $take['movement']);
                    $taken++;
                } catch (DebtorException $e) {
                    $refused[] = rtrim($e->getMessage(), '.');
                } catch (DBALException $e) {
                    if (!LockConflict::is($e)) {
                        throw $e;
                    }
                    $refused[] = LockConflict::MESSAGE;
                }
            }
            if ($taken === 0 && $refused === []) {
                return $this->bankMatchAnswer('Nichts angekreuzt — nichts zugeordnet.', 'error');
            }

            return $this->bankMatchAnswer(
                ($taken === 1 ? '1 Zahlungseingang' : $taken . ' Zahlungseingänge') . ' der Bankbewegung zugeordnet.' . ($refused !== [] ? ' Nicht zugeordnet: ' . implode('; ', $refused) . '.' : ''),
                $refused === [] ? 'success' : ($taken === 0 ? 'error' : 'info')
            );
        }
        $this->help->attach('Backend/BankImportController/list.help', self::BANK_NS, [], 'Hilfe: Zahlungseingänge');
        $overview = $links->overview();
        $response = $this->html([
            'rows'       => $overview['rows'],
            'confirmed'  => $overview['confirmed'],
            'more'       => $overview['more'],
            'window'     => $this->bankIsFetch(),
            'origin'     => WindowOrigin::of($request),
            'actionBase' => $this->bankImportListBase(),
            'fmt'        => static fn(?Money $m) => AmountFormat::of($m),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('bankMatch', 'Backend/BankImportController', self::BANK_NS);

        return $response;
    }

    /** «Zuordnung lösen» of a confirmed proposal (`key` = `debtor:day:…`) — a submit of the window's form. */
    #[Csrf]
    protected function bankMatchReleaseAction(): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!$request->isPost()) {
            return $this->redirect($this->bankImportListBase() . '/list', 303);
        }
        try {
            $this->bankEntryLinks()->release((string) ($request->getPostParameters()['key'] ?? ''));
        } catch (DebtorException $e) {
            return $this->bankMatchAnswer($e->getMessage(), 'error');
        }

        return $this->bankMatchAnswer('Zuordnung zur Bankbewegung gelöst.', 'success');
    }

    /**
     * The ticked pairs of «Bankabgleich …» — anything else (also the empty «nicht zuordnen») dropped.
     *
     * @return list<array{kind: string, movement: int, ref: string, message: int, day: string}>
     */
    private static function bankMatchTakes(mixed $values): array
    {
        $takes = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (!is_string($value)) {
                continue;
            }
            if (preg_match('/^e\|(\d{1,9})\|([^\r\n]{1,35})$/', $value, $m)) {
                $takes[] = ['kind' => 'e', 'movement' => (int) $m[1], 'ref' => $m[2], 'message' => 0, 'day' => ''];
            } elseif (preg_match('/^d\|(\d{1,9})\|(\d{1,9})\|(\d{4}-\d{2}-\d{2})$/', $value, $m)) {
                $takes[] = ['kind' => 'd', 'movement' => (int) $m[1], 'ref' => '', 'message' => (int) $m[2], 'day' => $m[3]];
            }
        }

        return $takes;
    }

    /** The window's answer: fetched, the flash and the window closed; a page, the flash and the list. */
    private function bankMatchAnswer(string $flash, string $type): FetchResponse|RedirectResponse
    {
        if (!$this->bankIsFetch()) {
            $this->messageService->pushFlashAfterRedirect($type, $flash);

            return $this->redirect($this->bankImportListBase() . '/list', 303);
        }
        $this->messageService->pushFlash($type, $flash);

        return $type === 'error' ? $this->fetch()->setStatus('error') : $this->fetch()->setStatus('success')->addCommand('close-window');
    }

    private function bankIsFetch(): bool
    {
        $request = DI::getRequest();

        return method_exists($request, 'getMode') && $request->getMode() === RequestMode::Fetch;
    }

    /** The body of a row action: JSON from `data-fetch-post`, the form fields of a page POST. @return array<string, mixed> */
    private function bankRowPost(): array
    {
        return $this->bankIsFetch() ? DI::getRequest()->getJsonBody() : DI::getRequest()->getPostParameters();
    }

    /**
     * A row action went through. Fetched: the row of THIS transaction re-rendered in place
     * (`replaceRow`, the `data-entity="bank-transaction:<id>"` of `transactionRow`) and the
     * unbooked bar with its new count — the flash pushed BEFORE `fetch()`, which takes it
     * along. A page POST: the flash and back to the detail.
     */
    private function bankRowAnswer(BankMessage $message, BankTransaction $transaction, string $flash): RedirectResponse|FetchResponse
    {
        if (!$this->bankIsFetch()) {
            $this->messageService->pushFlashAfterRedirect('success', $flash);

            return $this->redirect($this->bankImportListBase() . '/detail?id=' . $message->getId(), 303);
        }
        $invoice = $transaction->getInvoice();
        $open    = $invoice === null ? [] : [$invoice->getId() => $this->bankInvoicingService()->openAmount($invoice)];
        $csrf    = DI::getCsrfService()->getToken();
        $row     = $this->bankRenderPartial('transactionRow', [
            't'           => $transaction,
            'messageId'   => (int) $message->getId(),
            'open'        => $open,
            'states'      => self::bankStateLabels(),
            'invoiceBase' => '/backend/finance/invoice',
            'fmt'         => static fn(?Money $m) => AmountFormat::of($m),
            'csrfToken'   => $csrf,
            'actionBase'  => $this->bankImportListBase(),
        ]);
        $bar = $this->bankRenderPartial('unbookedBar', [
            'matched'    => (int) $message->countPerState()['matched'],
            'messageId'  => (int) $message->getId(),
            'csrfToken'  => $csrf,
            'actionBase' => $this->bankImportListBase(),
        ]);
        $this->messageService->pushFlash('success', $flash);

        return $this->fetch()->setStatus('success')
            ->replaceRow('bank-transaction', (int) $transaction->getId(), $row)
            ->addCommand('replace-html', ['target' => '[data-bank-unbooked-slot]', 'html' => $bar]);
    }

    /** A row action refused (unknown number, already booked …): the error flash — fetched, the page stays as it is. */
    private function bankRowRefusal(BankMessage $message, string $text): RedirectResponse|FetchResponse
    {
        if ($this->bankIsFetch()) {
            $this->messageService->pushFlash('error', $text);

            return $this->fetch()->setStatus('error');
        }
        $this->messageService->pushFlashAfterRedirect('error', $text);

        return $this->redirect($this->bankImportListBase() . '/detail?id=' . $message->getId(), 303);
    }

    /**
     * The list's messages region and its tabs as `replace-html` commands — the answer of an
     * upload that imported something (the same partials the page renders). Always the tab
     * «Offen»: a new message is open work; one with nothing to do (every credit ignored on
     * reading) is under «Erledigt» — the file's row says what came in.
     *
     * @return list<array{action: string, target: string, html: string}>
     */
    private function bankListCommands(): array
    {
        $actionBase = $this->bankImportListBase();

        return [
            [
                'action' => 'replace-html',
                'target' => '[data-bank-messages]',
                'html'   => $this->bankRenderPartial('messages', [
                    'messages'   => $this->bankImportService()->recent(50, false),
                    'view'       => 'open',
                    'actionBase' => $actionBase,
                ]),
            ],
            [
                'action' => 'replace-html',
                'target' => '[data-bank-tabs]',
                'html'   => $this->bankRenderPartial('toolbar', ['view' => 'open', 'views' => self::BANK_VIEWS, 'actionBase' => $actionBase]),   // the nav only — «Bankabgleich …» stands outside it and stays
            ],
        ];
    }

    /** A partial of this fragment as a string — the HTML a fetch answer carries (the same template the page renders). */
    private function bankRenderPartial(string $name, array $context): string
    {
        return (new TemplateRenderer(self::BANK_NS))->partial('Backend/BankImportController/' . $name, $context);
    }

    private function bankMessageOf(): ?BankMessage
    {
        $id = (int) DI::getRequest()->getGetParameter('id');

        return $id > 0 ? $this->bankImportService()->find($id) : null;
    }

    /** @return array<string, string> state value → German label */
    private static function bankStateLabels(): array
    {
        $labels = [];
        foreach (TransactionState::cases() as $state) {
            $labels[$state->value] = $state->label();
        }

        return $labels;
    }
}
