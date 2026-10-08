<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Debtor\Accounting\AccountingRefusedException,
    Z77\Module\Debtor\Entities\BankMessage,
    Z77\Module\Debtor\Entities\TransactionState,
    Z77\Module\Debtor\Services\BankImportService,
    Z77\Module\Debtor\Services\DebtorException,
    Z77\Module\Debtor\Services\InvoicingService,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Money\AmountFormat,
    Z77\Shared\Money\Money
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
 *   - `book` / `assign` / `ignore` — the POSTs, back to the detail with
 *     the flash.
 *
 * Page forms, no JavaScript (Rule 7). Every write goes through
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

    /** @param array<string, mixed> $context */
    private function bankImportPage(string $template, array $context): HtmlResponse
    {
        $context += ['actionBase' => $this->bankImportListBase(), 'fmt' => static fn(?Money $m) => AmountFormat::of($m)];
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

    /** The upload (POST, one file in `file`): import → the new message's detail. */
    #[Csrf]
    protected function uploadAction(): RedirectResponse
    {
        $request = DI::getRequest();
        if (!$request->isPost()) {
            return $this->redirect($this->bankImportListBase() . '/list', 303);
        }
        $file = $request->getUploadedFile('file');
        if ($file === null || !$file->isOk()) {
            $this->messageService->pushFlashAfterRedirect('error', 'Keine Datei empfangen — eine camt.054-Datei (XML) wählen.');

            return $this->redirect($this->bankImportListBase() . '/list', 303);
        }
        if ($file->size > self::BANK_FILE_LIMIT) {
            $this->messageService->pushFlashAfterRedirect('error', 'Die Datei ist grösser als 5 MB — keine camt.054-Meldung.');

            return $this->redirect($this->bankImportListBase() . '/list', 303);
        }
        try {
            $message = $this->bankImportService()->import((string) $file->bytes(), $file->originalName);
            $counts  = $message->countPerState();
            $this->messageService->pushFlashAfterRedirect('success', 'Meldung ' . $message->getMessageId() . ' importiert: ' . count($message->getTransactions()) . ' Transaktionen, ' . $counts['matched'] . ' zugeordnet, ' . $counts['unmatched'] . ' offen, ' . $counts['ignored'] . ' ignoriert.');

            return $this->redirect($this->bankImportListBase() . '/detail?id=' . $message->getId(), 303);
        } catch (DebtorException $e) {
            $this->messageService->pushFlashAfterRedirect('error', $e->getMessage());

            return $this->redirect($this->bankImportListBase() . '/list', 303);
        }
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
    protected function assignAction(): RedirectResponse
    {
        $message = $this->bankMessageOf();
        if ($message === null || !DI::getRequest()->isPost()) {
            return $this->bankMessageNotFound();
        }
        $post = DI::getRequest()->getPostParameters();
        try {
            $transaction = $this->bankImportService()->assign((int) ($post['transaction'] ?? 0), (int) ($post['number'] ?? 0));
            $this->messageService->pushFlashAfterRedirect('success', 'Transaktion ' . $transaction->getPosition() . ' zugeordnet: ' . $transaction->getInvoice()?->documentName());
        } catch (DebtorException $e) {
            $this->messageService->pushFlashAfterRedirect('error', $e->getMessage());
        }

        return $this->redirect($this->bankImportListBase() . '/detail?id=' . $message->getId(), 303);
    }

    /** «Ignorieren» (`value` 1) / «Zurücknehmen» (`value` 0) for `transaction`. */
    #[Csrf]
    protected function ignoreAction(): RedirectResponse
    {
        $message = $this->bankMessageOf();
        if ($message === null || !DI::getRequest()->isPost()) {
            return $this->bankMessageNotFound();
        }
        $post = DI::getRequest()->getPostParameters();
        try {
            $ignore      = (string) ($post['value'] ?? '1') !== '0';
            $transaction = $this->bankImportService()->ignore((int) ($post['transaction'] ?? 0), $ignore, $ignore ? null : 'Zurückgenommen — bitte zuordnen.');
            $this->messageService->pushFlashAfterRedirect('success', 'Transaktion ' . $transaction->getPosition() . ($ignore ? ' ignoriert.' : ' wieder offen.'));
        } catch (DebtorException $e) {
            $this->messageService->pushFlashAfterRedirect('error', $e->getMessage());
        }

        return $this->redirect($this->bankImportListBase() . '/detail?id=' . $message->getId(), 303);
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
