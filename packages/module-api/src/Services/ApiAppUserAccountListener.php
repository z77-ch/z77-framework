<?php

namespace Z77\Module\Api\Services;

use Z77\Shared\Auth\AccountRemovedListener,
    Z77\Shared\Auth\AuthUser
;

/**
 * A backend user was deleted: the app user linked to him is deactivated at once — its devices
 * revoked, its pending login codes spent (ADR-058 §4 «Lifecycle», review B6). Before ADR-058 a
 * device only died because the resolver found no owner any more; now the deletion says so
 * itself, and `resolve()` still refuses a person who is gone.
 *
 * Declared in module-api's config under `accountRemovedListeners`. The realm of the removed
 * account is the realm of the link, so a member's removal is covered once members can be linked
 * (A8); a realm nobody links (cron) finds no app user.
 */
final class ApiAppUserAccountListener implements AccountRemovedListener
{
    public function accountRemoved(AuthUser $account): void
    {
        (new ApiAppUsers())->deactivateLinked($account->getRealm(), (string) $account->getId());
    }
}
