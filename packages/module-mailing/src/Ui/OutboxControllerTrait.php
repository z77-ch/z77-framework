<?php
namespace Z77\Module\Mailing\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Mailing\Entities\OutboxMail,
    Z77\Module\Mailing\Outbox\OutboxService,
    Z77\Module\Mailing\Outbox\OutboxStateException,
    Z77\Module\Mailing\Repositories\OutboxMailRepository,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Jobs\JobRunner,
    Z77\Shared\Listing\ListDefinition,
    Z77\Shared\Mail\HtmlToText,
    Z77\Shared\Mail\Mailer
;

/**
 * Service › Postausgang — the outbox screen (owner 2026-10-22). What waits,
 * what went out, what failed; the job sends, this screen only looks and
 * corrects:
 *
 *   - `list` — a STANDARD LIST ({@see OutboxListing}): view tabs in the toolbar
 *     (Wartend · Gesendet · Fehlgeschlagen · Alle), column search and sort in
 *     the database, 50 per page, the list a fetch region. The action cell
 *     stays EMPTY — nothing is created here (ADR-033 rev. 2026-10-08). Above
 *     the list, inside the region, the BANDS that say why waiting mails do not
 *     move: sending is not configured; the job is not queued («Versand
 *     starten»); the cron has not run for a while (heartbeat);
 *   - `actions?id=` — the row's ⋮ as a CONTEXT MENU (`data-menu-open`, fetch.md
 *     MENU-001): «Anzeigen», «Erneut senden» (failed), «Abbrechen» (queued);
 *   - `detail?id=` — the mail as a WINDOW (ADR-047): headers, attachments, the
 *     text half as escaped text, the HTML in a SANDBOXED iframe (`srcdoc`,
 *     empty `sandbox` — no script, no form, no navigation, an opaque origin;
 *     ADR-053): the mail's markup never becomes part of the backend page;
 *   - `retry` / `cancel` / `start` — POST from the menu / the band, answer
 *     with a flash and a reload of the list region.
 *
 * Mounted by a thin host controller in the backend (ADR-018). The using class
 * MUST provide (via its host base): `html()`, `fetch()`, `redirect()`,
 * `fetchError()`, `em()`, `$layoutManager`, `$messageService`. Role: the
 * backend's module role (ADMIN) — no deviation.
 */
trait OutboxControllerTrait
{
    private const OUTBOX_NS = 'Z77\\Module\\Mailing';

    /** A heartbeat older than this means the cron line is not firing (the job screen's measure). */
    private const OUTBOX_HEARTBEAT_STALE = 900;

    /** German display labels — PRESENTATION ONLY. The set is {@see OutboxMail::STATES}. */
    private const OUTBOX_STATE_LABELS = [
        OutboxMail::STATE_QUEUED    => 'wartend',
        OutboxMail::STATE_SENDING   => 'wird gesendet',
        OutboxMail::STATE_SENT      => 'gesendet',
        OutboxMail::STATE_FAILED    => 'fehlgeschlagen',
        OutboxMail::STATE_CANCELLED => 'abgebrochen',
    ];

    /** The toolbar tabs, view => label (the keys are {@see \Z77\Module\Mailing\Repositories\OutboxSearch::VIEWS}). */
    private const OUTBOX_VIEWS = [
        'queued' => 'Wartend',
        'sent'   => 'Gesendet',
        'failed' => 'Fehlgeschlagen',
        'all'    => 'Alle',
    ];

    /** URL root of THIS mount — every link of the fragment is built from it. */
    protected function outboxBase(): string
    {
        return '/backend/service/outbox';
    }

    private function outboxMails(): OutboxMailRepository
    {
        return $this->em()->getRepository(OutboxMail::class);
    }

    private function outboxService(): OutboxService
    {
        return new OutboxService(DI::getUnifiedEntityManager());
    }

    protected function listAction(): HtmlResponse
    {
        $request    = DI::getRequest();
        $definition = OutboxListing::definition();
        $state      = $definition->readRequest($request);
        $search     = OutboxListing::search($state);
        $mails      = $this->outboxMails();
        $total      = $mails->countSearch($search);
        $paging     = $state->paging($total);
        $counts     = $mails->countByState();
        $waiting    = $counts[OutboxMail::STATE_QUEUED] + $counts[OutboxMail::STATE_SENDING];
        $heartbeat  = $waiting > 0 ? JobRunner::lastPass(ABS_BASE_PATH) : null;

        $response = $this->html([
            'mails'        => $total === 0 ? [] : $mails->search($search, $paging->offset(), $paging->pageSize),
            'definition'   => $definition,
            'state'        => $state,
            'paging'       => $paging,
            'counts'       => $counts,
            'waiting'      => $waiting,
            'labels'       => self::OUTBOX_STATE_LABELS,
            'views'        => self::OUTBOX_VIEWS,
            // The three reasons waiting mails do not move — asked only when something waits.
            'mailReady'    => $waiting === 0 || Mailer::create()->isConfigured(),
            'jobQueued'    => $waiting === 0 || $this->outboxService()->isJobQueued(),
            'heartbeat'    => $heartbeat,
            'cronStale'    => $waiting > 0 && !self::outboxHeartbeatFresh($heartbeat),
            'actionBase'   => $this->outboxBase(),
        ]);
        // The fragment owns its header slot: the view tabs in the toolbar. A FETCH of the
        // list region wants the list alone (listing.md).
        if (!ListDefinition::isFetch($request)) {
            $this->layoutManager->addPartials('toolbar', 'Backend/OutboxController', self::OUTBOX_NS, 'hc2');
        }

        return $response;
    }

    /** The row's ⋮ — only the `.be-menu` items its state allows. */
    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $mail = $this->outboxMails()->find((int) DI::getRequest()->getGetParameter('id'));
        if (!$mail instanceof OutboxMail) {
            return $this->fetchError('E-Mail nicht gefunden');
        }
        $response = $this->html([
            'mail'       => $mail,
            'actionBase' => $this->outboxBase(),
        ]);
        // Appended, not swapped: opened as a PAGE (no fetch header) the kernel answers 303 to the
        // list (MODAL-PAGE-001) — a menu is no page of its own.
        $this->layoutManager->addPartials('actions', 'Backend/OutboxController', self::OUTBOX_NS);

        return $response;
    }

    /**
     * One mail as it goes (went) out. Fetched (the state icon, «Anzeigen») the
     * same page as a WINDOW: the template marks its root.
     */
    protected function detailAction(): HtmlResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $mail    = $this->outboxMails()->find((int) $request->getGetParameter('id'));
        if (!$mail instanceof OutboxMail) {
            $this->messageService->pushFlashAfterRedirect('error', 'E-Mail nicht gefunden');

            return $this->redirect($this->outboxBase() . '/list', 303);
        }
        $text = $mail->getTextBody() !== '' ? $mail->getTextBody()
            : ($mail->getHtmlBody() !== '' ? (new HtmlToText())->convert($mail->getHtmlBody()) : '');

        $response = $this->html([
            'mail'        => $mail,
            'text'        => $text,
            'textDerived' => $mail->getTextBody() === '' && $mail->getHtmlBody() !== '',
            'labels'      => self::OUTBOX_STATE_LABELS,
            'window'      => ListDefinition::isFetch($request),
            'windowWidth' => '56rem',
            'actionBase'  => $this->outboxBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('detail', 'Backend/OutboxController', self::OUTBOX_NS);

        return $response;
    }

    /** «Erneut senden» (menu, failed mails). */
    #[Fetch, HttpMethod('POST')]
    protected function retryAction(): FetchResponse
    {
        try {
            $mail = $this->outboxService()->retry($this->outboxPostedId(), $this->outboxActor());
        } catch (OutboxStateException $e) {
            return $this->fetchError($e->getMessage());
        }

        return $this->outboxAnswer('Erneut eingereiht: ' . $mail->getRecipientEmail());
    }

    /** «Abbrechen» (menu, waiting mails). */
    #[Fetch, HttpMethod('POST')]
    protected function cancelAction(): FetchResponse
    {
        try {
            $mail = $this->outboxService()->cancel($this->outboxPostedId());
        } catch (OutboxStateException $e) {
            return $this->fetchError($e->getMessage());
        }

        return $this->outboxAnswer('Abgebrochen: ' . $mail->getRecipientEmail());
    }

    /** «Versand starten» (the band, when mails wait and the job is not queued). */
    #[Fetch, HttpMethod('POST')]
    protected function startAction(): FetchResponse
    {
        $queued = $this->outboxService()->ensureJobQueued($this->outboxActor());

        return $this->outboxAnswer($queued
            ? 'Versand eingereiht — er beginnt beim nächsten Cron-Durchlauf'
            : 'Der Versand ist bereits eingereiht');
    }

    private function outboxAnswer(string $flash): FetchResponse
    {
        $this->messageService->pushFlash('success', $flash);
        $response = $this->fetch()->setStatus('success');
        $response->addCommand('refresh-region', ['name' => OutboxListing::definition()->region()]);

        return $response;
    }

    private function outboxPostedId(): int
    {
        $request = DI::getRequest();

        return (int) ($request->getPostParameters()['id'] ?? $request->getGetParameter('id') ?? 0);
    }

    private function outboxActor(): string
    {
        $name = trim(DI::getAuthService()->getCurrentUser()->getUserName());

        return $name !== '' ? $name : 'backend';
    }

    /** @param array{at:string}|null $heartbeat */
    private static function outboxHeartbeatFresh(?array $heartbeat): bool
    {
        $at = $heartbeat === null ? false : strtotime($heartbeat['at']);

        return $at !== false && (time() - $at) <= self::OUTBOX_HEARTBEAT_STALE;
    }
}
