<?php
namespace Z77\Module\Backend\Dashboard;

use Z77\Core\DI;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Backup\BackupService;
use Z77\Shared\Dashboard\DashboardContributor;
use Z77\Shared\Dashboard\DashboardEntry;
use Z77\Shared\Dashboard\Severity;
use Z77\Shared\Entities\JobRun;
use Z77\Shared\Jobs\JobQueue;

/**
 * The service section's entries on the backend dashboard (ADR-052),
 * registered under `dashboard` in `backendConfig` — this module owns the
 * service screens (backup, jobs), the services themselves live in the kernel:
 *
 *   - the newest backup (any type) is older than `maxAgeDays` of the backup
 *     policy (`config/client/backup.inc.php`, default 1 — owner 2026-10-22:
 *     daily), or there is none — todo → Backup. The backup screen is
 *     SUPER_USER, so the dashboard's `canReach()` shows it to no one else;
 *   - job runs that FAILED in the last {@see FAILED_DAYS} days — URGENT →
 *     Jobs (SUPER_USER as well).
 *
 * Both are file reads (the backup directory, the job queue file), no
 * database.
 */
final class ServiceDashboard implements DashboardContributor
{
    /** How far back a failed job run is reported. */
    public const FAILED_DAYS = 7;

    public function entries(AuthUser $user): iterable
    {
        $now = new \DateTimeImmutable();

        return array_merge(
            self::backupEntries(BackupService::fromProjectRoot(ABS_BASE_PATH), $now),
            self::jobEntries((new JobQueue(DI::getUnifiedEntityManager()))->all(), $now)
        );
    }

    /** @return list<DashboardEntry> pure over the service — the harness passes its own root */
    public static function backupEntries(BackupService $backups, \DateTimeImmutable $now): array
    {
        $target = ['backend', 'service', 'backup', 'list'];
        $latest = $backups->latest();
        if ($latest === null) {
            return [DashboardEntry::pending(Severity::Todo, 'Noch kein Backup', $target)];
        }
        $age = $now->getTimestamp() - $latest->getCreatedAt()->getTimestamp();
        if ($age <= $backups->maxAgeDays() * 86400) {
            return [];
        }
        $days = intdiv($age, 86400);

        return [DashboardEntry::pending(Severity::Todo, 'Letztes Backup vor ' . $days . ($days === 1 ? ' Tag' : ' Tagen'), $target)];
    }

    /**
     * @param list<JobRun> $runs the queue
     * @return list<DashboardEntry>
     */
    public static function jobEntries(array $runs, \DateTimeImmutable $now): array
    {
        $since  = $now->modify('-' . self::FAILED_DAYS . ' days')->getTimestamp();
        $failed = 0;
        foreach ($runs as $run) {
            $finished = $run->getFinishedAt() !== null ? strtotime($run->getFinishedAt()) : false;
            if ($run->getState() === JobRun::STATE_FAILED && $finished !== false && $finished >= $since) {
                $failed++;
            }
        }

        return $failed === 0 ? [] : [DashboardEntry::pending(Severity::Urgent, 'Jobs fehlgeschlagen (' . self::FAILED_DAYS . ' Tage)', ['backend', 'service', 'job', 'list'], [], $failed)];
    }
}
