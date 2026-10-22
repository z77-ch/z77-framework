<?php

namespace Z77\Module\Mailing\Jobs;

use Z77\Core\DI;
use Z77\Module\Mailing\Attachments\AttachmentProviders;
use Z77\Module\Mailing\Entities\OutboxMail;
use Z77\Module\Mailing\Outbox\OutboxSender;
use Z77\Module\Mailing\Outbox\OutboxSettings;
use Z77\Module\Mailing\Repositories\OutboxMailRepository;
use Z77\Shared\Jobs\Job;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\JobResult;
use Z77\Shared\Mail\Mailer;

/**
 * The job that watches the e-mail dispatch (owner 2026-10-22: «wir brauchen
 * einen Job, der den E-Mail-Versand überwacht») — job key `mail-outbox`,
 * registered in `mailingConfig` → `jobs`, queued by `OutboxService` whenever
 * mails are added (no schedule, see below).
 *
 * One SLICE sends up to `batchSize` due mails (default 10) and then hands back
 * to the runner:
 *
 *   - nothing left waiting                → `done` (the queue entry finishes);
 *   - mails still waiting                 → `again(cursor, notBefore)` with
 *     `notBefore` = `pauseSeconds` (default 60) — the old system's «10 mails,
 *     one minute, the next 10» —, or longer when the only mails left wait for
 *     a retry (until the earliest `next_attempt_at`, at most
 *     {@see MAX_IDLE_WAIT} so a mail queued meanwhile is not held back long);
 *   - the mail config does not allow sending → `again(notBefore: 900)` and the
 *     mails stay untouched: an unconfigured installation must not burn their
 *     attempts (the screen says why nothing goes out).
 *
 * Before the batch, a mail still `sending` is set `failed` with «Versand
 * unterbrochen»: the runner holds this job's lock, so no live slice owns it —
 * the process that claimed it died, and whether the mail left is unknown. A
 * second unasked delivery is worse than a question to the operator.
 *
 * `JobContext::hasTimeLeft()` is asked before each mail; a slice that runs out
 * of budget pauses like a full batch. The cursor only carries running totals
 * for the job screen's note — any slice can run from scratch (the state lives
 * in `mail_outbox`, not in the cursor).
 *
 * Why no `defaultSchedule`: every pass that executes a slice prints a line,
 * and cron mails output — an «every 5 minutes» safety net would mail the
 * operator all day for nothing. The job is queued exactly when there is work
 * (`OutboxService::ensureJobQueued()`); the screen offers «Versand starten»
 * when mails wait and no entry exists.
 */
final class MailOutboxJob implements Job
{
    /** The longest wait while only retries are pending — a mail queued meanwhile goes out within this. */
    public const MAX_IDLE_WAIT = 300;

    /** The wait while sending is not configured. */
    public const UNCONFIGURED_WAIT = 900;

    public const INTERRUPTED = 'Versand unterbrochen — ob die E-Mail hinausging, ist unbekannt. Bitte prüfen und bei Bedarf erneut senden.';

    /** All optional: the runner constructs the job without arguments (ADR-031); a test hands in doubles. */
    public function __construct(
        private ?OutboxSender $sender = null,
        private ?OutboxMailRepository $mails = null,
        private ?OutboxSettings $settings = null,
        private ?\Closure $clock = null,
    ) {
    }

    public function run(JobContext $context): JobResult
    {
        $settings = $this->settings ?? OutboxSettings::fromModules(DI::getModuleManager());
        $mails    = $this->mails ?? DI::getUnifiedEntityManager()->getRepository(OutboxMail::class);
        $sender   = $this->sender ?? new OutboxSender($mails, AttachmentProviders::fromModules(DI::getModuleManager()), Mailer::create(), $settings);
        $totals   = ($context->getCursor() ?? []) + ['sent' => 0, 'retry' => 0, 'failed' => 0];

        $interrupted = $mails->failInterrupted(self::INTERRUPTED);
        $totals['failed'] += $interrupted;

        if (!$sender->isConfigured()) {
            $waiting = $mails->countWaiting();
            if ($waiting === 0) {
                return JobResult::done('nothing to send');
            }
            $context->log("mail is not configured — {$waiting} mail(s) wait");

            return JobResult::again($totals, self::UNCONFIGURED_WAIT, "mail not configured (config/client/mail.inc.php) — {$waiting} waiting");
        }

        $now   = $this->now();
        $slice = [OutboxSender::SENT => 0, OutboxSender::RETRY => 0, OutboxSender::FAILED => 0];
        foreach ($mails->dueIds($now, $settings->batchSize) as $id) {
            if (!$context->hasTimeLeft()) {
                break;
            }
            $outcome = $sender->send($id, $now);
            if (isset($slice[$outcome])) {
                $slice[$outcome]++;
            }
        }
        $totals['sent']   += $slice[OutboxSender::SENT];
        $totals['retry']  += $slice[OutboxSender::RETRY];
        $totals['failed'] += $slice[OutboxSender::FAILED];

        $note = sprintf(
            '%d sent, %d to retry, %d failed%s',
            $slice[OutboxSender::SENT],
            $slice[OutboxSender::RETRY],
            $slice[OutboxSender::FAILED] + $interrupted,
            $interrupted > 0 ? " ({$interrupted} interrupted)" : ''
        );

        $waiting = $mails->countWaiting();
        if ($waiting === 0) {
            return JobResult::done($note . sprintf(' — outbox empty (total %d sent, %d failed)', $totals['sent'], $totals['failed']));
        }

        return JobResult::again($totals, $this->wait($mails, $settings), $note . " — {$waiting} waiting");
    }

    /**
     * The pause before the next slice: `pauseSeconds` while mails are due (the
     * throttle), up to the earliest retry when only retries wait — never more
     * than {@see MAX_IDLE_WAIT}, never less than the throttle.
     */
    private function wait(OutboxMailRepository $mails, OutboxSettings $settings): int
    {
        $next = $mails->nextAttemptAt();
        if ($next === null) {
            return $settings->pauseSeconds;
        }
        $until = $next->getTimestamp() - $this->now()->getTimestamp();

        return max($settings->pauseSeconds, min($until, self::MAX_IDLE_WAIT));
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable();
    }
}
