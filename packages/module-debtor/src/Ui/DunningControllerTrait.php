<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\BytesResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Debtor\Accounting\AccountingRefusedException,
    Z77\Module\Debtor\Pdf\DunningPdf,
    Z77\Module\Debtor\Services\DebtorException,
    Z77\Module\Debtor\Services\DunningService,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Money\AmountFormat,
    Z77\Shared\Money\Money
;

/**
 * «Mahnungen» — the dunning screen (P4 part 3, plan §6.5), mounted by a
 * thin host controller in the backend (ADR-018 pattern): `use
 * DunningControllerTrait` + a one-line layout config delegating to
 * {@see DunningLayout::config()}. In the navigation under «Aufträge»
 * (ADR-050), URL group `finance`.
 *
 *   - `list` — the DUE LIST as of a day (`?as_of=`, today by default):
 *     every invoice a notice is due for, with its open amount, its current
 *     and its next level and the fee that level carries; a checkbox per
 *     row and «Mahnlauf starten» (the notice date = the as-of day); below,
 *     the runs so far with their notices and a «PDF» link each;
 *   - `run` — the POST (`#[Csrf]`): `DunningService::run()` — the notices
 *     and the fee documents in one unit of work; a refusal is the flash;
 *   - `notice-pdf` — one notice as PDF, inline (`?id=`).
 *
 * Page forms, no JavaScript (Rule 7). Every write goes through
 * {@see DunningService}.
 */
trait DunningControllerTrait
{
    /** Template namespace of this fragment. */
    private const DUNNING_NS = 'Z77\Module\Debtor';

    /** URL root of THIS mount — every link and form is built from it. */
    protected function dunningListBase(): string
    {
        return '/backend/finance/dunning';
    }

    /** The one write path; the session actor. A harness host overrides it with a named actor. */
    private function dunningService(): DunningService
    {
        return new DunningService($this->em());
    }

    protected function listAction(): HtmlResponse
    {
        $asOf = self::dunningDay((string) DI::getRequest()->getGetParameter('as_of'));
        $due  = [];
        $notice = null;
        try {
            $due = $this->dunningService()->dueList($asOf);
        } catch (DebtorException $e) {
            $notice = $e->getMessage();
        }

        return $this->html([
            'asOf'        => $asOf,
            'due'         => $due,
            'notice'      => $notice,
            'runs'        => $this->dunningService()->runs(20),
            'levelOf'     => fn($n) => $this->dunningService()->levelOf($n),
            'actionBase'  => $this->dunningListBase(),
            'invoiceBase' => '/backend/finance/invoice',
            'fmt'         => static fn(?Money $m) => AmountFormat::of($m),
        ]);
    }

    /** «Mahnlauf starten»: `as_of` + `doc[]` (invoice ids) → the run; back to the list. */
    #[Csrf]
    protected function runAction(): RedirectResponse
    {
        $request = DI::getRequest();
        if (!$request->isPost()) {
            return $this->redirect($this->dunningListBase() . '/list', 303);
        }
        $post = $request->getPostParameters();
        $asOf = self::dunningDay((string) ($post['as_of'] ?? ''));
        $ids  = array_values(array_filter(array_map('intval', (array) ($post['doc'] ?? []))));
        try {
            $run   = $this->dunningService()->run($asOf, $ids);
            $fees  = count(array_filter($run->getNotices(), static fn($n) => $n->getFeeInvoice() !== null));
            $this->messageService->pushFlashAfterRedirect('success', 'Mahnlauf vom ' . $run->getRunDate()->format('d.m.Y') . ': ' . count($run->getNotices()) . ' Mahnungen erstellt, ' . $fees . ' Gebühren verbucht.');
        } catch (DebtorException | AccountingRefusedException $e) {
            $this->messageService->pushFlashAfterRedirect('error', 'Kein Mahnlauf — ' . $e->getMessage());
        }

        return $this->redirect($this->dunningListBase() . '/list?as_of=' . $asOf->format('Y-m-d'), 303);
    }

    /** One notice as PDF, inline — rendered on request, never stored. */
    protected function noticePdfAction(): BytesResponse|RedirectResponse
    {
        $id     = (int) DI::getRequest()->getGetParameter('id');
        $notice = $id > 0 ? $this->dunningService()->notice($id) : null;
        if ($notice === null) {
            $this->messageService->pushFlashAfterRedirect('error', 'Mahnung nicht gefunden');

            return $this->redirect($this->dunningListBase() . '/list', 303);
        }
        $level = $this->dunningService()->levelOf($notice);

        return $this->bytes(DunningPdf::of($notice, $level, $this->em())->output(), DunningPdf::fileName($notice, $level), 'application/pdf');
    }

    /** `Y-m-d` from the request, today when absent or unreadable. */
    private static function dunningDay(string $raw): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($raw));

        return $day !== false && $day->format('Y-m-d') === trim($raw) ? $day : new \DateTimeImmutable('today');
    }
}
