<?php

namespace Z77\Module\Creditor\Jobs;

use Z77\Core\DI;
use Z77\Module\Creditor\Services\CreditorIntakeFolder;
use Z77\Shared\Jobs\Job;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\JobResult;

/**
 * «Kreditoren-Eingang» — reads the supplier PDFs the owner's upload script put into the intake
 * folder into the Kreditorenjournal (creditor plan §3 «Folder channel», owner answer §11.7; ADR-031).
 * All the work is {@see CreditorIntakeFolder::pass()}: creates the folder and its `.work/` /
 * `failed/` when missing, claims each settled PDF, `receive()` + recognition, deletes the file once
 * the DMS holds the same bytes, moves a refused one to `failed/`. Asks `hasTimeLeft()` between
 * files and comes again in the same pass budget's next round (`again()`).
 *
 * Ships `defaultSchedule` `every:10m` (creditorConfig): it deletes no installation record — a file
 * leaves the folder only after the DMS holds its verified copy (a move), a refused one stays in
 * `failed/` — so the jobs.md delete rule does not apply. The operator may switch the schedule off;
 * «Jetzt starten» on the job screen runs it at once.
 *
 * The folder argument is a harness seam; the runner calls `new self()`.
 */
final class CreditorIntakeJob implements Job
{
    public const KEY = 'creditor-intake';

    public function __construct(private ?CreditorIntakeFolder $folder = null) {}

    public function run(JobContext $context): JobResult
    {
        $actor  = trim($context->getActor()->getUserName());
        $folder = $this->folder ?? CreditorIntakeFolder::forInstallation(DI::getUnifiedEntityManager(), $actor !== '' ? $actor : 'cron:' . self::KEY);
        $pass   = $folder->pass(static fn(): bool => $context->hasTimeLeft());
        foreach ($pass['notes'] as $line) {
            $context->log($line);
        }
        $note = sprintf('%d eingelesen, %d bereits eingelesen, %d nach failed, %d warten', $pass['received'], $pass['duplicates'], $pass['failed'], $pass['waiting'])
            . ($pass['kept'] > 0 ? sprintf(', %d bleiben in .work', $pass['kept']) : '');

        return $pass['more'] ? JobResult::again(null, 0, $note) : JobResult::done($note);
    }
}
