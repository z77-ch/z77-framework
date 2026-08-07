<?php

namespace Z77\Module\Member\Services;

use Z77\Core\DI;
use Z77\Shared\Auth\AuthBridgeInterface;

/**
 * Projects the member session into the framework ACL identity (`auth_user`,
 * realm `member`) — once per request, before AccessGuard resolves roles
 * (registered in memberConfig under `authBridges`).
 *
 * The member session stays the single source of truth: this bridge DERIVES
 * the ACL identity instead of having login/logout write it a second time, so
 * the two can never drift. Going through MemberAuth::current() also means the
 * «angemeldet bleiben» device key resumes HERE, before the guard — a returning
 * device passes role-gated routes on its first request.
 *
 * Cheap by design: without a member session key or device cookie it only
 * clears a (normally absent) stale member-realm identity and returns — no
 * store read on anonymous traffic (frontend, widget).
 */
final class MemberAuthBridge implements AuthBridgeInterface
{
    public function sync(): void
    {
        $auth    = DI::getAuthService();
        $session = new MemberSession(DI::getSessionManager());

        if (!$session->hasTraces() && ($_COOKIE[DeviceCookie::NAME] ?? '') === '') {
            $auth->clearMemberIdentity();
            return;
        }

        $account = MemberAuth::create()->current();
        if ($account !== null) {
            $auth->establishMemberIdentity(
                (string)$account->getId(),
                $account->getEmail(),
                $account->getRoles(),
            );
        } else {
            $auth->clearMemberIdentity();
        }
    }
}
