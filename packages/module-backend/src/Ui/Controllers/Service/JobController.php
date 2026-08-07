<?php
namespace Z77\Module\Backend\Ui\Controllers\Service;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Entities\JobRun,
    Z77\Shared\Jobs\JobQueue,
    Z77\Shared\Jobs\JobRunner,
    Z77\Shared\Jobs\JobSchedules,
    Z77\Shared\Jobs\ScheduleExpression
;

/**
 * Backend surface for the job queue (ADR-031): what a module offers, when it is
 * scheduled, what is waiting, and what went wrong. All logic lives in the
 * kernel job classes (HTTP-free, shared with `vendor/bin/z77-run`); this is
 * thin glue.
 *
 * This screen NEVER executes a job — it only queues one. A job may run for
 * minutes, which is exactly what an HTTP request must not do, and keeping the
 * runner as the single execution path means retry, locking, actor and logging
 * exist once instead of twice.
 *
 * SUPER_USER on every action: a job runs with whatever role its module declares
 * and can delete data, so deciding WHEN it runs is installation governance.
 *
 * URL: /backend/service/job/{action}. Mutations are Fetch POSTs (CSRF-gated).
 */
class JobController extends BackendAbstractController
{
    /** A heartbeat older than this means the cron line is not firing. */
    private const HEARTBEAT_STALE_SECONDS = 900;

    private function queue(): JobQueue
    {
        return new JobQueue(DI::getInstance()->get('UnifiedEntityManager'));
    }

    private function schedules(): JobSchedules
    {
        return new JobSchedules(DI::getInstance()->get('UnifiedEntityManager'));
    }

    protected function listAction(): HtmlResponse
    {
        $registry  = DI::getModuleManager()->getJobs();
        $schedules = $this->schedules();
        $queue     = $this->queue();

        $open    = [];
        $history = [];
        foreach ($queue->all() as $entry) {
            if (in_array($entry->getState(), [JobRun::STATE_QUEUED, JobRun::STATE_RUNNING], true)) {
                $open[] = $entry;
            } else {
                $history[] = $entry;
            }
        }

        usort($history, static fn(JobRun $a, JobRun $b) => strcmp((string) $b->getFinishedAt(), (string) $a->getFinishedAt()));

        $jobs = [];
        foreach ($registry as $jobKey => $definition) {
            $schedule = $schedules->findByJobKey($jobKey);
            $jobs[]   = [
                'key'        => $jobKey,
                'label'      => $definition['label'],
                'module'     => $definition['module'],
                'runAs'      => $definition['runAs'],
                'schedule'   => $schedule,
                'openCount'  => count(array_filter($open, static fn(JobRun $r) => $r->getJobKey() === $jobKey)),
                'lastRun'    => $this->lastRunOf($history, $jobKey),
            ];
        }

        return $this->html([
            'jobs'         => $jobs,
            'open'         => $open,
            'history'      => array_slice($history, 0, 25),
            'heartbeat'    => JobRunner::lastPass(ABS_BASE_PATH),
            'heartbeatOk'  => $this->heartbeatIsFresh(),
            'scheduleHelp' => 'every:15m · every:2h · hourly@:20 · daily@03:15 · weekly@mon,03:15',
        ]);
    }

    /**
     * Queues one entry. It is picked up by the next runner pass — within a
     * minute on an installation whose cron line is in place, never at all on
     * one where it is missing, which is what the heartbeat on the list screen
     * is there to reveal.
     */
    #[Fetch, HttpMethod('POST')]
    protected function runAction(): FetchResponse
    {
        $jobKey = trim((string) (DI::getRequest()->getJsonBody()['job'] ?? ''));
        if (!isset(DI::getModuleManager()->getJobs()[$jobKey])) {
            return $this->fetchError('Unbekannter Job');
        }

        $queue = $this->queue();
        if ($queue->hasOpenEntry($jobKey)) {
            return $this->fetchError('Dieser Job wartet bereits oder läuft gerade');
        }

        $queue->enqueue($jobKey, [], $this->actorName());

        $this->messageService->pushFlashAfterRedirect(
            'success',
            'Job «' . $jobKey . '» eingereiht — er läuft beim nächsten Durchlauf'
        );

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }

    /** Switches a schedule on or off. No record yet → nothing to switch. */
    #[Fetch, HttpMethod('POST')]
    protected function toggleAction(): FetchResponse
    {
        $jobKey   = trim((string) (DI::getRequest()->getJsonBody()['job'] ?? ''));
        $schedules = $this->schedules();
        $schedule  = $schedules->findByJobKey($jobKey);

        if ($schedule === null) {
            return $this->fetchError('Für diesen Job ist kein Zeitplan hinterlegt');
        }

        $schedule->setEnabled(!$schedule->isEnabled());
        if ($schedule->isEnabled()) {
            $schedule->setNextRunAt(date(
                DATE_ATOM,
                ScheduleExpression::parse($schedule->getExpression())->nextAfter(time())
            ));
        }
        $schedules->save($schedule);

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }

    /**
     * Sets or replaces a schedule. An empty expression removes the record —
     * that is how a job goes back to "runs only when someone queues it".
     */
    #[Fetch, HttpMethod('POST')]
    protected function scheduleAction(): FetchResponse
    {
        $body       = DI::getRequest()->getJsonBody();
        $jobKey     = trim((string) ($body['job'] ?? ''));
        $expression = strtolower(trim((string) ($body['expression'] ?? '')));

        if (!isset(DI::getModuleManager()->getJobs()[$jobKey])) {
            return $this->fetchError('Unbekannter Job');
        }

        $schedules = $this->schedules();
        $schedule  = $schedules->findByJobKey($jobKey);

        if ($expression === '') {
            if ($schedule !== null) {
                $schedules->delete($schedule);
            }
            return $this->fetch()->setStatus('success')->addCommand('reload');
        }

        if (!ScheduleExpression::isValid($expression)) {
            return $this->fetchError('Zeitplan nicht lesbar. Erlaubt: every:15m, every:2h, hourly@:20, daily@03:15, weekly@mon,03:15');
        }

        if ($schedule === null) {
            $schedule = $schedules->create($jobKey, $expression);
        } else {
            $schedule->setExpression($expression);
        }
        $schedule->setNextRunAt(date(DATE_ATOM, ScheduleExpression::parse($expression)->nextAfter(time())));
        $schedules->save($schedule);

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }

    /** Puts a failed entry back into the queue, attempt counter reset. */
    #[Fetch, HttpMethod('POST')]
    protected function retryAction(): FetchResponse
    {
        $entry = $this->findEntry(trim((string) (DI::getRequest()->getJsonBody()['id'] ?? '')));
        if ($entry === null) {
            return $this->fetchError('Eintrag nicht gefunden');
        }

        $entry->setState(JobRun::STATE_QUEUED);
        $entry->setAttempts(0);
        $entry->setStartedAt(null);
        $entry->setFinishedAt(null);
        $entry->setAvailableAt(date(DATE_ATOM));
        $entry->setNote('erneut eingereiht');
        $this->queue()->save($entry);

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }

    #[Fetch, HttpMethod('POST')]
    protected function removeAction(): FetchResponse
    {
        $entry = $this->findEntry(trim((string) (DI::getRequest()->getJsonBody()['id'] ?? '')));
        if ($entry === null) {
            return $this->fetchError('Eintrag nicht gefunden');
        }
        if ($entry->getState() === JobRun::STATE_RUNNING) {
            return $this->fetchError('Dieser Eintrag läuft gerade — er kann nicht entfernt werden');
        }

        $this->queue()->delete($entry);

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }

    private function findEntry(string $id): ?JobRun
    {
        if ($id === '') {
            return null;
        }
        foreach ($this->queue()->all() as $entry) {
            if ($entry->getId() === $id) {
                return $entry;
            }
        }

        return null;
    }

    /** @param list<JobRun> $history */
    private function lastRunOf(array $history, string $jobKey): ?JobRun
    {
        foreach ($history as $entry) {
            if ($entry->getJobKey() === $jobKey) {
                return $entry;
            }
        }

        return null;
    }

    private function heartbeatIsFresh(): bool
    {
        $last = JobRunner::lastPass(ABS_BASE_PATH);
        if ($last === null) {
            return false;
        }
        $at = strtotime((string) $last['at']);

        return $at !== false && (time() - $at) <= self::HEARTBEAT_STALE_SECONDS;
    }

    /** Who queued it, for the entry's `createdBy`. */
    private function actorName(): string
    {
        $name = trim(DI::getAuthService()->getCurrentUser()->getUserName());

        return $name !== '' ? $name : 'backend';
    }
}
