<?php

namespace Z77\Module\Debtor\Services;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\Invoice;

/**
 * Who issues and finalizes a document — the NAME stamped into
 * `invoice.created_by` / `changed_by` and handed to the accounting port
 * (financial stamps the same name on the journal entry).
 *
 * The same resolution as financial's `Actor` — the current `AuthUser`
 * (a backend user, or the job runner's `cron:{job}`), and a REFUSAL to
 * guess when nobody is logged in: a CLI script, an import and the harness
 * pass the name explicitly. Repeated here rather than imported because
 * module-financial is only `suggest`ed (a module that may be absent cannot
 * be where a present module asks); when a third module needs it, it moves
 * to the kernel's `shared` (pending in `debtor.md`).
 */
final class Actor
{
    /** @throws \LogicException nobody is logged in (or no AuthService is wired) — pass the actor explicitly */
    public static function current(): string
    {
        try {
            $user = DI::getAuthService()->getCurrentUser();
        } catch (\RuntimeException $e) {
            throw new \LogicException('No AuthService in this context — pass the actor explicitly to InvoicingService', 0, $e);
        }
        if (!$user->isLoggedIn()) {
            throw new \LogicException('Nobody is logged in — a document needs a named author; pass the actor explicitly');
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

        return mb_substr($actor, 0, Invoice::ACTOR_LENGTH);
    }
}
