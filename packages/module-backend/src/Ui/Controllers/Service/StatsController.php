<?php
namespace Z77\Module\Backend\Ui\Controllers\Service;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Jobs\JobSchedules,
    Z77\Shared\Stats\ReportToken,
    Z77\Shared\Stats\StatsEvents,
    Z77\Shared\Stats\StatsReport,
    Z77\Shared\Stats\StatsReportMailer
;

/**
 * The statistics report — step 1.3 of docs/03-development/web-stats-bauplan.md,
 * see docs/topics/stats.md. One template, two doors:
 *
 *   /backend/service/stats              the backend page (ADMIN by the module
 *                                       baseline), month by month, with the
 *                                       link of the month and «jetzt senden»
 *                                       (sent in the request, not queued)
 *   /stats/report/{token}               the link page for the client — a
 *                                       reserved route (backendConfig), GUEST,
 *                                       no shell, one month, read-only, 410
 *                                       after expiry (ReportToken)
 *
 * Both render `Service/StatsController/_report` from the same
 * {@see StatsReport} data, so what the client sees is what we see.
 *
 * The link door lives in the BACKEND module on purpose: the backend is not
 * `public`, so the statistic never counts its own report, and the backend is
 * never page-cached — a report cached under one token would be served for
 * another.
 */
class StatsController extends BackendAbstractController
{
    public const MAIL_JOB = 'stats-report-mail';

    protected function listAction(): HtmlResponse
    {
        $reports = StatsReport::fromProject();
        $months  = $reports->months();

        $requested = DI::getRequest()->getGetParameter('month');
        $month     = is_string($requested) && in_array($requested, $months, true)
            ? $requested
            : ($months[0] ?? null);

        // A malformed event declaration or month file THROWS by design. This
        // page is where you would go to find out why, so it says it instead of
        // answering 500.
        $report = null;
        $error  = null;
        if ($month !== null) {
            try {
                $report = $reports->build($month, StatsEvents::declared());
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        [$link, $linkError] = $this->linkFor($month);

        $schedule = (new JobSchedules(DI::getInstance()->get('UnifiedEntityManager')))->findByJobKey(self::MAIL_JOB);

        // Who a send reaches — resolved like the send itself (backend record
        // first), so the page cannot promise one address and mail another.
        $recipients = null;
        try {
            $recipients = DI::getEmailService()->formRecipients(StatsReportMailer::FORM_KEY);
        } catch (\Throwable $e) {
            $recipients = ['to' => [], 'cc' => [], 'error' => $e->getMessage()];
        }

        return $this->html([
            'title'      => 'Statistik',
            'months'     => $months,
            'month'      => $month,
            'report'     => $report,
            'error'      => $error,
            'link'       => $link,
            'linkError'  => $linkError,
            'schedule'   => $schedule,
            'mailJob'    => self::MAIL_JOB,
            'formKey'    => StatsReportMailer::FORM_KEY,
            'recipients' => $recipients,
        ]);
    }

    /**
     * «jetzt senden»: sends the month on screen NOW, in this request, and says
     * how it went (owner decision 2026-09-27 — the queued version depended on
     * the cron and reported elsewhere; one mail is well under a second). The
     * monthly schedule stays the job's (`stats-report-mail`). Both go through
     * StatsReportMailer, so a hand send and a scheduled one are the same mail.
     */
    #[Fetch, HttpMethod('POST')]
    protected function sendAction(): FetchResponse
    {
        $month = trim((string) (DI::getRequest()->getJsonBody()['month'] ?? ''));
        if (!in_array($month, StatsReport::fromProject()->months(), true)) {
            return $this->fetchError('Für diesen Monat gibt es keine Zahlen');
        }

        $result = (new StatsReportMailer())->send($month);
        if ($result['status'] !== 'sent') {
            return $this->fetchError('Nicht gesendet: ' . $result['note']);
        }

        $to = DI::getEmailService()->formRecipients(StatsReportMailer::FORM_KEY);
        $this->messageService->pushFlashAfterRedirect(
            'success',
            'Bericht ' . StatsReport::monthLabel($month) . ' gesendet an ' . implode(', ', $to['to'])
            . ($to['cc'] !== [] ? ' (Kopie ' . implode(', ', $to['cc']) . ')' : '')
            . ' — Link gültig bis ' . date('j.n.Y', (int) $result['expiresAt'])
        );

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }

    /**
     * The link door. The token is the one content slug of the reserved route.
     * No key configured, or a token that is not ours → 404 (nothing to tell a
     * stranger); a real link past its date → 410 with a sentence to the
     * client, who then asks for a fresh one.
     */
    protected function reportAction(): HtmlResponse
    {
        // Never indexed, and the token never leaves in a Referer header.
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');

        // Exactly one segment: `/stats/report/{token}/anything` is not a report.
        $slugs  = DI::getRequest()->getSlugs();
        $token  = count($slugs) === 1 ? (string) $slugs[0] : '';
        $tokens = null;
        try {
            $tokens = ReportToken::fromConfig();
        } catch (\Throwable $e) {
            error_log('StatsController: ' . $e->getMessage());
        }

        $check  = $tokens?->verify($token) ?? ['status' => ReportToken::INVALID, 'month' => null, 'expiresAt' => null];
        $report = null;
        if ($check['status'] === ReportToken::OK) {
            $report = StatsReport::fromProject()->build((string) $check['month'], StatsEvents::declared());
        }

        $state = match (true) {
            $check['status'] === ReportToken::EXPIRED => 'expired',
            $report !== null                          => 'ok',
            default                                   => 'missing',
        };
        http_response_code(match ($state) {
            'ok'      => 200,
            'expired' => 410,
            default   => 404,
        });

        $response = $this->html([
            'state'     => $state,
            'report'    => $report,
            'expiresAt' => $check['expiresAt'],
            'siteName'  => $this->siteName(),
        ]);

        // No shell: the client gets the report and nothing of the backend.
        $this->layoutManager->setSkeletonTemplate('html-report-skeleton', self::NAMESPACE);
        foreach (['iconSprite', 'noindexBanner', 'systemBanner', 'shellTopbar', 'subnav', 'flash', 'messages'] as $section) {
            $this->layoutManager->removeSection($section);
        }
        foreach (['meta', 'seo'] as $section) {
            $this->layoutManager->removeSection($section, 'head');
        }
        $this->layoutManager->removeCss('base');
        $this->layoutManager->removeJs(['core', 'panel-toggle', 'split', 'appearance', 'system/cache', 'shell']);

        return $response;
    }

    /**
     * The month's link for the backend page — minted fresh on every view, valid
     * {@see ReportToken::VALID_DAYS} days, so it can be copied into a mail by
     * hand. [url|null, why not|null]
     */
    private function linkFor(?string $month): array
    {
        if ($month === null) {
            return [null, null];
        }
        try {
            $tokens = ReportToken::fromConfig();
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        }
        if ($tokens === null) {
            return [null, 'Kein Schlüssel in config/client/' . ReportToken::CONFIG_FILE . ' — Link und Monatsmail sind aus.'];
        }
        if (!defined('CANONICAL_BASE_URL') || CANONICAL_BASE_URL === '') {
            return [null, 'Keine canonicalBaseUrl in config/client/systemConfig.inc.php — ein Link wüsste nicht, wohin.'];
        }

        $expiresAt = ReportToken::expiresAt();

        return [[
            'url'       => CANONICAL_BASE_URL . ReportToken::LINK_PATH . '/' . $tokens->mint($month, $expiresAt),
            'expiresAt' => $expiresAt,
        ], null];
    }

    private function siteName(): string
    {
        $base = defined('CANONICAL_BASE_URL') ? (string) CANONICAL_BASE_URL : '';

        return (string) (parse_url($base, PHP_URL_HOST) ?: '');
    }
}
