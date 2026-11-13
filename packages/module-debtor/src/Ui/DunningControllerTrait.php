<?php
namespace Z77\Module\Debtor\Ui;

use Doctrine\DBAL\Exception as DBALException,
    Z77\Core\DI,
    Z77\Core\Http\Response\BytesResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Debtor\Accounting\AccountingRefusedException,
    Z77\Module\Debtor\Entities\DunningNotice,
    Z77\Module\Debtor\Mail\DocumentMailer,
    Z77\Module\Debtor\Mail\DunningMails,
    Z77\Module\Debtor\Pdf\DunningPdf,
    Z77\Module\Debtor\Services\DebtorException,
    Z77\Module\Debtor\Services\DunningService,
    Z77\Module\Debtor\Services\LockConflict,
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
 *   - `notice-pdf` — one notice as PDF, inline (`?id=`);
 *   - `notice-actions` — a notice row's ⋮ (owner 2026-11-13, the result
 *     list's row menu): «PDF öffnen», «Per E-Mail senden …» / «Erneut
 *     senden …»;
 *   - `confirm-notice-mail` / `notice-mail` — ONE notice by e-mail (owner
 *     2026-11-13, «the way invoices are sent»): the shared confirmation
 *     page (`Backend/DocumentMail/confirm`) and its POST (`#[Csrf]`) — one
 *     outbox mail through `Mail\DunningMails`, signed by the identity
 *     chosen under «Senden als», the notice PDF rendered at send time.
 *
 * Page forms (Rule 7); the row ⋮ needs core.js (MENU-001) — without the
 * script the notice PDF stays reachable from the invoice's mask
 * («Mahnungen»). Every write goes through {@see DunningService}; a mail
 * through the outbox.
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
        // The list's help (HELP-003): «Hilfe» at the right end of the toolbar.
        $this->help->attach('Backend/DunningController/list.help', self::DUNNING_NS, [], 'Hilfe: Mahnungen', toolbar: true);

        return $this->html([
            'asOf'        => $asOf,
            'due'         => $due,
            'notice'      => $notice,
            'runs'        => $runs = $this->dunningService()->runs(20),
            'levelOf'     => fn($n) => $this->dunningService()->levelOf($n),
            // What went out per notice (owner 2026-11-13): the «Versand» column, from the outbox.
            'deliveries'  => $this->dunningMails()->deliveries(self::dunningNoticeIds($runs)),
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
        } catch (DBALException $e) {
            // A deadlock or lock wait timeout: nothing was written — say so instead of a 500 (review 2026-10-13 H4).
            if (!LockConflict::is($e)) {
                throw $e;
            }
            $this->messageService->pushFlashAfterRedirect('error', 'Kein Mahnlauf — ' . LockConflict::MESSAGE);
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

    // ── the notice by e-mail (owner 2026-11-13) ─────────────────────────

    /** A notice row's ⋮ — «PDF öffnen» and «Per E-Mail senden …» (or «Erneut senden …» once one waits or went out). */
    protected function noticeActionsAction(): HtmlResponse|FetchResponse
    {
        $notice = $this->dunningNoticeOfRequest(DI::getRequest()->getGetParameter('id'));
        if ($notice === null) {
            return $this->fetchError('Mahnung nicht gefunden');
        }
        $mails    = $this->dunningMails();
        $response = $this->html([
            'notice'     => $notice,
            'actionBase' => $this->dunningListBase(),
            'mailable'   => $mails->mailer()->available(),
            'again'      => DocumentMailer::isCovered($mails->deliveries([(int) $notice->getId()])[(int) $notice->getId()] ?? []),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('noticeActions', 'Backend/DunningController', self::DUNNING_NS);

        return $response;
    }

    /**
     * «Per E-Mail senden …» of one notice — the shared confirmation PAGE: the billing e-mail ON the
     * invoice, the subject of the kind `dunning` in the invoice's language, «Senden als», what went out
     * before (sending again is allowed). No address → «keine E-Mail-Adresse», no button.
     */
    protected function confirmNoticeMailAction(): HtmlResponse|RedirectResponse
    {
        $notice = $this->dunningNoticeOfRequest(DI::getRequest()->getGetParameter('id'));
        if ($notice === null) {
            return $this->dunningNoticeMissing();
        }
        $mails    = $this->dunningMails();
        $senders  = $this->dunningMailSenders();
        $reason   = DunningMails::skipReason($notice);
        $id       = (int) $notice->getId();
        $response = $this->html([
            'documentName' => $this->dunningNoticeName($notice),
            'mail'         => $reason === null ? $mails->compose($notice, $senders->default()) : null,
            'reason'       => $reason,
            'addressNote'  => 'Die Adresse ist die Rechnungsadresse auf ' . $notice->getInvoice()->documentName() . '; ein fakturierter Beleg ändert sie nicht mehr.',
            'before'       => $mails->deliveries([$id])[$id] ?? [],
            'senders'      => $senders->viewModel(),
            'mailable'     => $mails->mailer()->available(),
            'mailReady'    => DocumentMailer::mailReady(),
            'action'       => $this->dunningListBase() . '/notice-mail',
            'hidden'       => ['id' => (string) $id],
            'cancelHref'   => $this->dunningListBase() . '/list',
            'window'       => false,
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirm', 'Backend/DocumentMail', self::DUNNING_NS);

        return $response;
    }

    /**
     * The POST (`#[Csrf]`): the notice re-read, the identity re-checked against the CURRENT user
     * (Rule 7), no address → refused with the reason; then ONE outbox mail (`DunningMails::queue()`,
     * source `dunning:<id>`, attachment `dunning-pdf:<id>`). Back to the list with the flash.
     */
    #[Csrf]
    protected function noticeMailAction(): RedirectResponse
    {
        $request = DI::getRequest();
        $post    = $request->isPost() ? $request->getPostParameters() : [];
        $notice  = $this->dunningNoticeOfRequest($post['id'] ?? null);
        if ($notice === null) {
            return $this->dunningNoticeMissing();
        }
        $list    = $this->dunningListBase() . '/list';
        $name    = $this->dunningNoticeName($notice);
        $senders = $this->dunningMailSenders();
        $sender  = $senders->choose($post[\Z77\Shared\Mail\SenderIdentities::FIELD] ?? null);
        if ($sender === null) {
            $this->messageService->pushFlashAfterRedirect('error', 'Nichts wurde eingereiht: ' . ($senders->isEmpty() ? \Z77\Shared\Mail\SenderIdentities::MISSING : 'Der gewählte Absender gehört nicht zu deinem User.'));

            return $this->redirect($list, 303);
        }
        if (($reason = DunningMails::skipReason($notice)) !== null) {
            $this->messageService->pushFlashAfterRedirect('error', $name . ': ' . $reason . ' — keine E-Mail eingereiht.');

            return $this->redirect($list, 303);
        }
        $mails = $this->dunningMails();
        $id    = (int) $notice->getId();
        $again = DocumentMailer::isCovered($mails->deliveries([$id])[$id] ?? []);
        try {
            $mails->queue($notice, $this->dunningMailActor(), $sender, $senders->userId());
        } catch (\InvalidArgumentException | \RuntimeException $e) {   // a draft the outbox refused, no outbox
            $this->messageService->pushFlashAfterRedirect('error', 'Nichts wurde eingereiht: ' . $e->getMessage());

            return $this->redirect($list, 303);
        }
        $this->messageService->pushFlashAfterRedirect('success', $mails->mailer()->queuedMessage($name, $again));

        return $this->redirect($list, 303);
    }

    /** The notice a request value names, or null. */
    private function dunningNoticeOfRequest(mixed $value): ?DunningNotice
    {
        $id = is_scalar($value) && preg_match('/^\s*([1-9]\d{0,9})\s*$/', (string) $value, $m) === 1 ? (int) $m[1] : 0;

        return $id > 0 ? $this->dunningService()->notice($id) : null;
    }

    private function dunningNoticeMissing(): RedirectResponse
    {
        $this->messageService->pushFlashAfterRedirect('error', 'Mahnung nicht gefunden');

        return $this->redirect($this->dunningListBase() . '/list', 303);
    }

    /** «Zahlungserinnerung zu Rechnung 26100012» — the level's label (its code when the ladder lost it) and the invoice. */
    private function dunningNoticeName(DunningNotice $notice): string
    {
        return ($this->dunningService()->levelOf($notice)?->getLabel() ?? $notice->getLevelCode()) . ' zu ' . $notice->getInvoice()->documentName();
    }

    /**
     * @param list<\Z77\Module\Debtor\Entities\DunningRun> $runs
     * @return list<int>
     */
    private static function dunningNoticeIds(array $runs): array
    {
        $ids = [];
        foreach ($runs as $run) {
            foreach ($run->getNotices() as $notice) {
                $ids[] = (int) $notice->getId();
            }
        }

        return $ids;
    }

    /** The mail side of the notice; a harness host replaces it. */
    private function dunningMails(): DunningMails
    {
        return new DunningMails($this->em());
    }

    /** The logged-in user's sender identities («Senden als»); a harness host hands in a user. */
    private function dunningMailSenders(): \Z77\Shared\Mail\SenderIdentities
    {
        return \Z77\Shared\Mail\SenderIdentities::ofCurrentUser();
    }

    /** Who queued the mail (the outbox row's `created_by`); a harness host names one. */
    private function dunningMailActor(): string
    {
        return \Z77\Module\Debtor\Services\Actor::current();
    }

    /** `Y-m-d` from the request, today when absent or unreadable. */
    private static function dunningDay(string $raw): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($raw));

        return $day !== false && $day->format('Y-m-d') === trim($raw) ? $day : new \DateTimeImmutable('today');
    }
}
