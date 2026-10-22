<?php

namespace Z77\Module\Mailing\Dashboard;

use Z77\Core\DI;
use Z77\Module\Mailing\Entities\OutboxMail;
use Z77\Module\Mailing\Repositories\OutboxMailRepository;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Dashboard\DashboardContributor;
use Z77\Shared\Dashboard\DashboardEntry;
use Z77\Shared\Dashboard\Severity;

/**
 * mailing's entries on the backend dashboard (ADR-052), registered under
 * `dashboard` in `mailingConfig` — both → Service › Postausgang:
 *
 *   - «E-Mails fehlgeschlagen» (urgent, view «Fehlgeschlagen»): a mail that
 *     gave up after its last attempt reaches nobody until someone acts;
 *   - «E-Mails warten seit über einer Stunde» (todo, view «Wartend»): queued
 *     mails older than {@see WAITING_TOO_LONG}. A fresh batch of 500 takes
 *     ~50 minutes at 10 per minute and is no work for anyone — a mail still
 *     waiting after an hour means the cron or the transport is stuck.
 *
 * One COUNT per entry; nothing when nothing is to be done.
 */
final class MailingDashboard implements DashboardContributor
{
    public const TARGET = ['backend', 'service', 'outbox', 'list'];

    public const WAITING_TOO_LONG = 3600;

    public function entries(AuthUser $user): iterable
    {
        /** @var OutboxMailRepository $mails */
        $mails   = DI::getUnifiedEntityManager()->getRepository(OutboxMail::class);
        $entries = [];

        $failed = $mails->countByState()[OutboxMail::STATE_FAILED];
        if ($failed > 0) {
            $entries[] = DashboardEntry::pending(Severity::Urgent, 'E-Mails fehlgeschlagen', self::TARGET, ['view' => 'failed'], $failed);
        }
        $stale = $mails->countQueuedSince(new \DateTimeImmutable('-' . self::WAITING_TOO_LONG . ' seconds'));
        if ($stale > 0) {
            $entries[] = DashboardEntry::pending(Severity::Todo, 'E-Mails warten seit über einer Stunde', self::TARGET, [], $stale);
        }

        return $entries;
    }
}
