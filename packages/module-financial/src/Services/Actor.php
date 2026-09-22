<?php

namespace Z77\Module\Financial\Services;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\JournalEntry;

/**
 * Who is writing the books — the name stamped into `created_by`,
 * `changed_by` and the change log (ADR-042 decision 7).
 *
 * The framework knows the current identity as `AuthUser`
 * (`AuthService::getCurrentUser()`): a logged-in backend user (realm
 * `backend`, the username), or — in the CLI — the job runner's actor (realm
 * `cron`, `cron:{jobKey}`, ADR-031). Both carry a NAME, which is what the
 * books keep: not an id (a backend user can be deleted; the entry stays),
 * not the realm. A member (realm `member`) never posts — the ledger sits
 * behind ADMIN screens and module services.
 *
 * A caller without a session identity — a CLI script, the import of P5b,
 * the test harness — passes the actor EXPLICITLY to `LedgerService` /
 * `ManualEntryService`; this resolver refuses to guess (no «system», no
 * «unknown»): a posting without a named author is exactly what the change
 * log exists to prevent.
 */
final class Actor
{
    /**
     * @throws \LogicException nobody is logged in (or no AuthService is wired) — pass the actor explicitly
     */
    public static function current(): string
    {
        try {
            $user = DI::getAuthService()->getCurrentUser();
        } catch (\RuntimeException $e) {
            throw new \LogicException('No AuthService in this context — pass the actor explicitly to the ledger services', 0, $e);
        }
        if (!$user->isLoggedIn()) {
            throw new \LogicException('Nobody is logged in — a journal entry needs a named author; pass the actor explicitly');
        }

        return self::normalize($user->getUserName());
    }

    /** Trimmed and cut to the column — the actor column is a name, not a message. */
    public static function normalize(string $actor): string
    {
        $actor = trim($actor);
        if ($actor === '') {
            throw new \LogicException('An actor name must not be empty');
        }

        return mb_substr($actor, 0, JournalEntry::ACTOR_LENGTH);
    }
}
