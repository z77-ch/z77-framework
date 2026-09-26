<?php

namespace Z77\Shared\Stats;

use Z77\Core\DI;
use Z77\Shared\Jobs\Job;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\JobResult;

/**
 * Mails the link to last month's statistics report — step 2 of
 * docs/03-development/web-stats-bauplan.md, see docs/topics/stats.md.
 *
 * Registered by `backendConfig` as `stats-report-mail` WITHOUT a default
 * schedule: a job that writes outward is switched on by a human (the rule of
 * `form-log-cleanup`, ADR-031). Suggested `monthly@1,06:00` — after the
 * rollup of 04:40 has folded the month's last day.
 *
 * Which month: the payload's `month` (`YYYY-MM`) when given — the backend
 * report page queues it that way for «jetzt senden» —, otherwise the month
 * before the run. The mail carries a short text, the headline figures, and a
 * FRESH link valid {@see ReportToken::VALID_DAYS} days: every mail brings its
 * own link, an old mail stops working. No attachment, no table — the link is
 * the report.
 *
 * Recipients, cc and subject: the form key {@see FORM_KEY} through
 * `EmailService::sendForm()` — the project's `emailConfig` is the seed, a
 * record in Backend → Service → E-Mail overrides it without a deploy (mail.md).
 *
 * Fails loudly, never quietly: no key in `config/client/stats.inc.php`, no
 * `canonicalBaseUrl`, or a refused send FAIL the job and say why in the job
 * log. A month without an aggregate is `done` with a note and no mail — there
 * is nothing to report, and whether the rollup ran is its own job's line.
 */
final class StatsReportMailJob implements Job
{
    public const FORM_KEY = 'statsReport';

    public function run(JobContext $context): JobResult
    {
        $month = $this->month($context->getPayload()['month'] ?? null);
        if ($month === null) {
            return JobResult::failed("payload 'month' is not YYYY-MM");
        }

        $tokens = ReportToken::fromConfig();
        if ($tokens === null) {
            return JobResult::failed(
                'no report key in config/client/' . ReportToken::CONFIG_FILE . ' — link and mail are off, nothing sent'
            );
        }

        $base = defined('CANONICAL_BASE_URL') ? (string) CANONICAL_BASE_URL : '';
        if ($base === '') {
            return JobResult::failed('no canonicalBaseUrl in config/client/systemConfig.inc.php — a link would point nowhere, nothing sent');
        }

        $reports = StatsReport::fromProject();
        if (!in_array($month, $reports->months(), true)) {
            return JobResult::done("no aggregate for {$month} — nothing to report, no mail sent");
        }
        $report = $reports->build($month, StatsEvents::declared());

        $expiresAt = ReportToken::expiresAt();
        $url       = $base . ReportToken::LINK_PATH . '/' . $tokens->mint($month, $expiresAt);

        $mail = DI::getEmailService();
        $sent = $mail->sendForm(self::FORM_KEY, [
            'siteName'  => (string) (parse_url($base, PHP_URL_HOST) ?: $base),
            'report'    => $report,
            'headline'  => StatsReport::headline($report),
            'url'       => $url,
            'expiresAt' => $expiresAt,
        ]);

        if (!$sent) {
            return JobResult::failed("report {$month} not sent: " . implode('; ', $mail->getLastErrors()));
        }

        return JobResult::done(sprintf(
            'report %s sent (form key %s), link valid until %s',
            $month,
            self::FORM_KEY,
            date('d.m.Y', $expiresAt)
        ));
    }

    /** The payload month, or the month before today; null when the payload is malformed. */
    private function month(mixed $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return StatsReport::previousMonth(date('Y-m'));
        }

        return is_string($payload) && preg_match('~^\d{4}-(0[1-9]|1[0-2])$~', $payload) === 1 ? $payload : null;
    }
}
