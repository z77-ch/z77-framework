<?php

namespace Z77\Module\Creditor\Jobs;

use Z77\Core\DI;
use Z77\Module\Creditor\Services\CreditorIntakeFolder;
use Z77\Module\Creditor\Services\CreditorRecognitionService;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Entities\JobRun;
use Z77\Shared\Jobs\Job;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\JobQueue;
use Z77\Shared\Jobs\JobResult;

/**
 * «Kreditoren-Eingang» — the intake folder and the recognition of the Kreditorenjournal (creditor
 * plan §3 «Folder channel», owner answer §11.7; ADR-031). One slice:
 *
 *   1. {@see CreditorIntakeFolder::pass()}: creates the folder and its `.work/` / `failed/` when
 *      missing, claims each settled PDF, `receive()` (the invoice is written «Erkennung
 *      ausstehend»), deletes the file once the DMS holds the same bytes, moves a refused one to
 *      `failed/`;
 *   2. {@see CreditorRecognitionService::recognizePending()}: recognises every invoice marked
 *      «Erkennung ausstehend» — from the folder AND from the backend upload, which only marks and
 *      queues (owner 2026-11-15, creditor.md CREDITOR-RECOG-WEB-001: the recognition runs in the
 *      job only, never in a web request). The PDF is read back from the DMS. A further AI step
 *      starts only while `CreditorRecognitionService::AI_MIN_SECONDS` (30 s) of the slice are left
 *      (owner 2026-11-15); an AI call may take 120 s and overrun the slice — that only delays the
 *      next pass, the runner's per-job lock prevents an overlap.
 *
 * Asks `hasTimeLeft()` between files and invoices and comes again (`again()`) when work is left.
 *
 * Ships `defaultSchedule` `every:1m` (creditorConfig): it deletes no installation record — a file
 * leaves the folder only after the DMS holds its verified copy (a move), a refused one stays in
 * `failed/` — so the jobs.md delete rule does not apply. The upload and «Jetzt erkennen» queue it
 * ({@see queue()}), so a pending recognition normally runs on the next cron pass; the schedule is the
 * fallback. The operator may switch the schedule off; «Jetzt starten» on the job screen runs it.
 *
 * The constructor arguments are harness seams; the runner calls `new self()`.
 */
final class CreditorIntakeJob implements Job
{
    public const KEY = 'creditor-intake';

    public function __construct(private ?CreditorIntakeFolder $folder = null, private ?CreditorRecognitionService $recognition = null) {}

    /**
     * Queue this job unless an entry already WAITS for the runner. Only a waiting entry is sure to
     * see the invoice just marked; a running one may have looked before the mark existed and
     * finish without it (the DMS-ZIP-001 lesson) — `hasOpenEntry()` would strand it until the
     * schedule comes. Returns whether an entry was added. Never runs the job (ADR-031).
     */
    public static function queue(UnifiedEntityManager $em, string $createdBy): bool
    {
        $jobs = new JobQueue($em);
        foreach ($jobs->all() as $run) {
            if ($run->getJobKey() === self::KEY && $run->getState() === JobRun::STATE_QUEUED) {
                return false;
            }
        }
        $jobs->enqueue(self::KEY, [], trim($createdBy) !== '' ? $createdBy : 'system');

        return true;
    }

    public function run(JobContext $context): JobResult
    {
        $actor = trim($context->getActor()->getUserName());
        $actor = $actor !== '' ? $actor : 'cron:' . self::KEY;
        $em    = DI::getUnifiedEntityManager();
        $time  = static fn(): bool => $context->hasTimeLeft();

        $pass = ($this->folder ?? CreditorIntakeFolder::forInstallation($em, $actor))->pass($time);
        foreach ($pass['notes'] as $line) {
            $context->log($line);
        }
        $recog = ['recognized' => 0, 'failed' => 0, 'more' => false, 'notes' => []];
        if (!$pass['more']) {
            $recog = ($this->recognition ?? new CreditorRecognitionService($em, null, null, $actor))->recognizePending($time, null, static fn(): int => $context->getSecondsLeft());
            foreach ($recog['notes'] as $line) {
                $context->log($line);
            }
        }
        $note = sprintf('%d eingelesen, %d bereits eingelesen, %d nach failed, %d warten', $pass['received'], $pass['duplicates'], $pass['failed'], $pass['waiting'])
            . ($pass['kept'] > 0 ? sprintf(', %d bleiben in .work', $pass['kept']) : '')
            . sprintf('; %d erkannt', $recog['recognized'])
            . ($recog['failed'] > 0 ? sprintf(', %d Erkennung abgebrochen', $recog['failed']) : '');

        return $pass['more'] || $recog['more'] ? JobResult::again(null, 0, $note) : JobResult::done($note);
    }
}
