<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI,
    Z77\Core\Http\RequestMode,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Debtor\Accounting\AccountingRefusedException,
    Z77\Module\Debtor\Entities\BankMessage,
    Z77\Module\Debtor\Entities\BankTransaction,
    Z77\Module\Debtor\Entities\TransactionState,
    Z77\Module\Debtor\Services\BankImportService,
    Z77\Module\Debtor\Services\DebtorException,
    Z77\Module\Debtor\Services\InvoicingService,
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
 *     state, and the UPLOAD form (one camt.054 file, `#[Csrf]`);
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
 *     as before.
 *
 * No JavaScript of its own (Rule 7). Every write goes through
 * {@see BankImportService}.
 */
trait BankImportControllerTrait
{
    /** Template namespace of this fragment. */
    private const BANK_NS = 'Z77\Module\Debtor';

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

    /**
     * What this screen lets in (the ONE place, read by the client's attributes AND by the
     * server's check — `UploadPolicy`): camt.054 messages are XML, a month is a few hundred
     * KB, several files in one go is the normal case (a bank hands out one per day).
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
            accept:     ['.xml'],
            maxBytes:   self::BANK_FILE_LIMIT,
            onConflict: UploadPolicy::CONFLICT_ERROR,
            // The action cell is narrow and the label stands next to an ↑: «camt.054
            // Upload» says it, «camt.054-Dateien hierher ziehen» overflows the cell
            // (owner 2026-10-10).
            label:      'camt.054 Upload',
            hint:       'XML · bis 5 MB · mehrere',
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
        return $this->bankImportPage('listAction', [
            'messages' => $this->bankImportService()->recent(50),
            'states'   => self::bankStateLabels(),
        ]);
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

        try {
            $message = $this->bankImportService()->import((string) $file->bytes(), $file->originalName);
            $counts  = $message->countPerState();
            $summary = 'Meldung ' . $message->getMessageId() . ' importiert: '
                . count($message->getTransactions()) . ' Transaktionen, '
                . $counts['matched'] . ' zugeordnet, ' . $counts['unmatched'] . ' offen, '
                . $counts['ignored'] . ' ignoriert.';

            if ($fetch) {
                // The list behind the component shows the new message — reloading is the
                // honest refresh here: the page is a plain list, not a fetch region.
                return $this->json([
                    'status'   => 'ok',
                    'name'     => $file->originalName,
                    'message'  => $counts['matched'] . ' zugeordnet, ' . $counts['unmatched'] . ' offen',
                    'commands' => [['action' => 'reload']],
                ]);
            }

            $this->messageService->pushFlashAfterRedirect('success', $summary);

            return $this->redirect($this->bankImportListBase() . '/detail?id=' . $message->getId(), 303);
        } catch (DebtorException $e) {
            return $this->bankUploadRefusal($fetch, $file->originalName, $e->getMessage());
        }
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
        }
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
