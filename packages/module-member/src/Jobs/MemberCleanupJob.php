<?php

namespace Z77\Module\Member\Jobs;

use Z77\Core\DI;
use Z77\Module\Member\Services\MemberAccounts;
use Z77\Module\Member\Services\PendingLogins;
use Z77\Module\Member\Services\RegistrationLog;
use Z77\Module\Member\Services\TokenService;
use Z77\Shared\Jobs\Job;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\JobResult;

/**
 * The B7 cleanup as a job (ADR-031). Deletes accounts that were never confirmed
 * within the grace period (memberConfig `cleanupAfterDays`, default 30), every
 * token that can no longer redeem (used, expired, orphaned), every waiting
 * login past its window, and every registration-log month past its own
 * retention ({@see RegistrationLog::RETENTION_DAYS}).
 *
 * 'confirmed' accounts are NEVER touched — they wait for the operator's
 * activate/reject decision, however long that takes.
 *
 * Order matters: accounts go first, then tokens. `TokenService::purge()` is
 * given the ids that SURVIVED, so the tokens of a just-deleted account are
 * recognised as orphaned in the same run.
 *
 * ⚠️ Invitations (B7 v1.1.0) have no account by construction, and `purge()`
 * knows it — the orphan test applies only to tokens that carry one. An OPEN
 * invitation survives this run; an expired or withdrawn one disappears in it,
 * which is the spec's «abgelaufene Einladungen verfallen im selben Lauf».
 *
 * Runs in a single slice. The three sweeps are proportional to the number of
 * member records, which stays in the hundreds — there is nothing here worth
 * carrying a cursor for. Should an installation ever outgrow that, the natural
 * cut is one sweep per slice.
 *
 * Payload keys (both optional):
 *   days   — override the configured grace period
 *   dryRun — report what would be deleted, delete nothing
 */
final class MemberCleanupJob implements Job
{
    private const DEFAULT_GRACE_DAYS = 30;

    public function run(JobContext $context): JobResult
    {
        $payload = $context->getPayload();
        $days    = isset($payload['days']) ? max(0, (int) $payload['days']) : $this->configuredGraceDays();
        $dryRun  = (bool) ($payload['dryRun'] ?? false);

        $uem      = DI::getInstance()->get('UnifiedEntityManager');
        $accounts = new MemberAccounts($uem);

        if ($dryRun) {
            return $this->report($accounts, $days, $context);
        }

        $deletedAccounts = $accounts->cleanup($days);

        $survivingIds  = array_map(static fn($account) => (string) $account->getId(), $accounts->all());
        $deletedTokens = (new TokenService($uem))->purge($survivingIds);

        $deletedPending = (new PendingLogins($uem))->purge();

        // The registration log expires on its OWN clock (90 days), not on the
        // grace period above: it is a different purpose with a different
        // retention, and the privacy policy names the two separately. It rides
        // in this job because this is the module's broom — a sweep that
        // deletes belongs beside the other sweeps that delete, under the same
        // operator-switched schedule.
        $droppedLogs = RegistrationLog::sweep();

        return JobResult::done(sprintf(
            '%d account(s) removed (never confirmed within %d days), %d dead token(s) purged, '
            . '%d expired waiting login(s) dropped, %d expired log file(s) deleted',
            $deletedAccounts,
            $days,
            $deletedTokens,
            $deletedPending,
            $droppedLogs
        ));
    }

    /** Counts what a real run would delete, and names each account in the log. */
    private function report(MemberAccounts $accounts, int $days, JobContext $context): JobResult
    {
        $cutoff = time() - $days * 86400;
        $due    = 0;

        foreach ($accounts->all() as $account) {
            $created = strtotime((string) $account->getCreatedAt());
            if ($account->isRegistered() && $created !== false && $created < $cutoff) {
                $due++;
                $context->log("would delete: {$account->getEmail()} (registered {$account->getCreatedAt()})");
            }
        }

        return JobResult::done("dry run — {$due} unconfirmed account(s) older than {$days} days, nothing deleted");
    }

    private function configuredGraceDays(): int
    {
        $configured = DI::getModuleManager()
            ->getModuleConfig('member')
            ?->get('cleanupAfterDays', self::DEFAULT_GRACE_DAYS);

        return is_numeric($configured) ? (int) $configured : self::DEFAULT_GRACE_DAYS;
    }
}
