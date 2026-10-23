<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\BytesResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Debtor\Entities\Invoice,
    Z77\Module\Debtor\Pdf\JournalPdf,
    Z77\Module\Debtor\Repositories\InvoiceRepository,
    Z77\Module\Debtor\Services\AccountNotConfiguredException,
    Z77\Module\Debtor\Services\DebtorCurrency,
    Z77\Module\Debtor\Services\DebtorException,
    Z77\Module\Debtor\Services\JournalPostingService,
    Z77\Module\Debtor\Services\JournalRefusedException,
    Z77\Module\Debtor\Services\LockConflict,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Listing\ListDefinition,
    Z77\Shared\Money\AmountFormat,
    Z77\Shared\Money\Money
;

/**
 * The FAKTURA-JOURNAL screen (ADR-054 decisions 5–7, step 3 of the build plan, owner 2026-10-23:
 * no canvas board — built on the known patterns) — mounted by a thin host controller in the
 * backend (ADR-018 pattern): `/backend/finance/invoice-journal/…`, navigation «Auftragswesen ›
 * Faktura-Journal» (module-debtor's seed, right after «Rechnungen»).
 *
 * The recipe (backend-screen.md):
 *
 *   - action cell (hc1): «Journal verbuchen …» — the entry's most frequent action, a green
 *     confirm; disabled with its reason while the journal is empty or blocked;
 *   - toolbar (hc2): the tabs «Im Journal» · «Verbuchte Journale», then the tool «Journal
 *     drucken» (the journal list as PDF); «Hilfe» at its right end (HELP-003);
 *   - the pending step at the top: «n Dokumente im Journal · Total … · ältestes Datum …», or the
 *     red «Verbuchen gesperrt — n Dokumente müssen neu fakturiert werden» with the documents;
 *   - the work area: a STANDARD LIST of the issued documents ({@see InvoiceJournalListing}) —
 *     the state icon opens the document's mask as a window (the invoice screen's `edit`); under
 *     it the totals per VAT code and in all (credit notes negative). Tab «Verbuchte Journale»: the
 *     posting protocols, each with its journal list (PDF) — one row action, a helper icon.
 *
 * Actions: `list`, `pdf` (`?posting=` for a posted batch), `confirm-post` (a PAGE: count, totals,
 * the entries the posting books per account, the versions seen), `post` (POST, `#[Csrf]` →
 * {@see JournalPostingService::post()} — always the whole journal, owner decision O7).
 *
 * No JavaScript of its own (Rule 7): links and plain forms; the server re-judges everything
 * (`post()` compares every version under its locks and refuses a blocked or changed journal).
 * The using class MUST provide (via its host base): `html()`, `redirect()`, `bytes()`, `em()`,
 * `$layoutManager`, `$messageService`, `$help`.
 */
trait InvoiceJournalControllerTrait
{
    private const JOURNAL_NS = 'Z77\\Module\\Debtor';

    /** URL root of THIS mount — every link and form is built from it. */
    protected function journalListBase(): string
    {
        return '/backend/finance/invoice-journal';
    }

    /** Where a document's mask lives (the invoice screens). */
    protected function journalInvoiceBase(): string
    {
        return '/backend/finance/invoice';
    }

    /** The one write path of the posting; the session actor. A harness host overrides it with a named actor. */
    private function journalService(): JournalPostingService
    {
        return new JournalPostingService($this->em());
    }

    private function journalInvoices(): InvoiceRepository
    {
        return $this->em()->getRepository(Invoice::class);
    }

    protected function listAction(): HtmlResponse
    {
        $request    = DI::getRequest();
        $fetch      = ListDefinition::isFetch($request);
        $currency   = DebtorCurrency::base();
        $definition = InvoiceJournalListing::definition($currency);
        $state      = $definition->readRequest($request);
        $view       = $state->extra('view');
        $totals     = $this->journalService()->totals();
        $context    = [
            'view'         => $view,
            'totals'       => $totals,
            'blockers'     => $totals['blocked'] > 0 ? $this->journalService()->blockers($this->journalService()->pending()) : [],
            'currency'     => $currency,
            'actionBase'   => $this->journalListBase(),
            'invoiceBase'  => $this->journalInvoiceBase(),
            'fmt'          => static fn(?Money $m) => AmountFormat::of($m),
        ];
        if ($view === InvoiceJournalListing::VIEW_POSTED) {
            $posted       = InvoiceJournalListing::postedDefinition();
            $postedState  = $posted->readRequest($request);
            $total        = $this->journalService()->countProtocols();
            $paging       = $postedState->paging($total);
            $context     += [
                'definition' => $posted,
                'state'      => $postedState,
                'paging'     => $paging,
                'protocols'  => $total === 0 ? [] : $this->journalService()->protocols($paging->offset(), $paging->pageSize, $postedState->descending),
                'documents'  => [],
            ];
        } else {
            $search  = InvoiceJournalListing::search($state);
            $total   = $this->journalInvoices()->countSearch($search);
            $paging  = $state->paging($total);
            $context += [
                'definition' => $definition,
                'state'      => $state,
                'paging'     => $paging,
                'documents'  => $total === 0 ? [] : $this->journalInvoices()->search($search, $paging->offset(), $paging->pageSize),
                'protocols'  => [],
            ];
        }
        if (!$fetch) {
            $this->help->attach('Backend/InvoiceJournalController/list.help', self::JOURNAL_NS, [], 'Hilfe: Faktura-Journal', toolbar: true);
        }
        $response = $this->html($context);
        if (!$fetch) {
            $this->layoutManager->addPartials('act', 'Backend/InvoiceJournalController', self::JOURNAL_NS, 'hc1');
            $this->layoutManager->addPartials('toolbar', 'Backend/InvoiceJournalController', self::JOURNAL_NS, 'hc2');
        }

        return $response;
    }

    /** «Journal drucken» — the journal list as PDF: the open journal, or `?posting=` one posted batch. */
    protected function pdfAction(): BytesResponse|RedirectResponse
    {
        $postingId = (int) DI::getRequest()->getGetParameter('posting');
        $posting   = $postingId > 0 ? $this->journalService()->protocol($postingId) : null;
        if ($postingId > 0 && $posting === null) {
            $this->messageService->pushFlashAfterRedirect('error', 'Dieses verbuchte Journal gibt es nicht.');

            return $this->redirect($this->journalListBase() . '/list?view=posted', 303);
        }

        return $this->bytes(JournalPdf::of($posting, $this->em(), $this->journalService(), DebtorCurrency::base())->output(), JournalPdf::fileName($posting), 'application/pdf');
    }

    /**
     * «Journal verbuchen …» — the last look (a PAGE, the booking print's confirmation model): how
     * many documents, the totals per VAT code and in all, the journal entries the posting books
     * summed per account (`preview()` — the same `PostingBuilder` the posting calls), the blockers.
     * The form carries every document's `{id}:{version}` — the journal as THIS page shows it; the
     * posting refuses when it changed since.
     */
    protected function confirmPostAction(): HtmlResponse
    {
        $documents = $this->journalService()->pending();
        $preview   = null;
        $problem   = null;
        try {
            $preview = $this->journalService()->preview($documents);
        } catch (AccountNotConfiguredException $e) {
            $problem = $e->getMessage();
        }
        $response = $this->html([
            'documents'  => $documents,
            'totals'     => $this->journalService()->totals(),
            'blockers'   => $this->journalService()->blockers($documents),
            'preview'    => $preview,
            'problem'    => $problem,
            'currency'   => DebtorCurrency::base(),
            'actionBase' => $this->journalListBase(),
            'fmt'        => static fn(?Money $m) => AmountFormat::of($m),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmPost', 'Backend/InvoiceJournalController', self::JOURNAL_NS);

        return $response;
    }

    /**
     * The POST of «Journal verbuchen» (`#[Csrf]`): the pairs the confirmation carried to
     * `JournalPostingService::post()` — ONE unit of work, all or nothing — and a 303 to the tab
     * «Verbuchte Journale» with the flash; a refusal (empty, blocked, changed, the bookkeeping's)
     * answers the journal with the German sentence — nothing was posted.
     */
    #[Csrf]
    protected function postAction(): RedirectResponse
    {
        $request = DI::getRequest();
        $pairs   = $request->isPost() ? self::journalPairs($request->getPostParameters()['doc'] ?? null) : [];
        $back    = $this->journalListBase() . '/list';
        try {
            $protocol = $this->journalService()->post($pairs);
        } catch (DebtorException $e) {
            $this->messageService->pushFlashAfterRedirect('error', $e instanceof JournalRefusedException ? $e->getMessage() : 'Nichts wurde verbucht: ' . $e->getMessage());

            return $this->redirect($back, 303);
        } catch (\Doctrine\DBAL\Exception $e) {
            error_log('debtor journal posting: ' . get_class($e) . ': ' . $e->getMessage());
            $this->messageService->pushFlashAfterRedirect('error', 'Nichts wurde verbucht: ' . (LockConflict::is($e) ? LockConflict::MESSAGE : 'die Datenbank hat das Verbuchen abgelehnt — nochmals versuchen; bleibt es dabei, steht die Ursache im Fehlerprotokoll.'));

            return $this->redirect($back, 303);
        }
        $count = $protocol->getDocumentCount();
        $this->messageService->pushFlashAfterRedirect('success', 'Journal verbucht: ' . ($count === 1 ? '1 Dokument' : $count . ' Dokumente') . ' (Nr. ' . $protocol->getFirstNumber() . ' – ' . $protocol->getLastNumber() . '), Total ' . AmountFormat::of($protocol->getGrossTotal()) . '.');

        return $this->redirect($back . '?view=posted', 303);
    }

    /**
     * `{id}:{version}` strings → the pairs `post()` takes; anything else is dropped (the value
     * only names documents, the service re-checks every one of them under its lock).
     *
     * @return list<array{id: int, version: int}>
     */
    private static function journalPairs(mixed $values): array
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
