<?php

namespace Z77\Module\Backend\Jobs;

use Z77\Module\Backend\PasswordReset\PasswordResetTokens;
use Z77\Shared\Jobs\Job;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\JobResult;

/**
 * Removes expired and used entries of «Passwort vergessen» from
 * `data/framework/auth/passwordResets.json` (login.md LOGIN-RESET-001). An open link stays.
 *
 * {@see \Z77\Module\Backend\PasswordReset\PasswordRecovery::request()} runs the same sweep on every
 * new request, so the file stays clean while this job is switched off. It ships no schedule
 * because it deletes (jobs.md). Single slice: one file of a few rows.
 */
final class PasswordResetCleanupJob implements Job
{
    public function run(JobContext $context): JobResult
    {
        return JobResult::done(sprintf('%d expired or used reset entr(y/ies) removed', (new PasswordResetTokens())->purge()));
    }
}
