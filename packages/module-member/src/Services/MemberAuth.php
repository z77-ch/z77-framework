<?php

namespace Z77\Module\Member\Services;

use Z77\Core\DI;
use Z77\Module\Member\Entities\MemberAccount;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The one question other building blocks ask (B8 spec, Schnittstellen):
 * «wer ist angemeldet?» — account and roles of the active member session,
 * nothing more. B9/B10 build their permission checks on top of this;
 * the member controllers use it as their page guard.
 *
 * Distinct from the z77 admin login (AuthUser/loginUsers.json) by design —
 * B8 is the customer login, the backend login stays untouched.
 */
final class MemberAuth
{
    public function __construct(
        private MemberSession $session,
        private MemberAccounts $accounts,
    ) {
    }

    /** Production wiring: kernel session + file persistence. */
    public static function create(): self
    {
        return new self(
            new MemberSession(DI::getSessionManager()),
            new MemberAccounts(new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']))),
        );
    }

    /** The signed-in account, or null (nobody, idle-expired, or meanwhile deleted). */
    public function current(?int $now = null): ?MemberAccount
    {
        $accountId = $this->session->currentAccountId($now);
        if ($accountId === null) {
            return null;
        }

        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            // Account rejected/cleaned up while the session lived — end it.
            $this->session->end();

            return null;
        }

        return $account;
    }

    /** Roles of the signed-in account; empty when nobody is (or before activation). */
    public function roles(?int $now = null): array
    {
        return $this->current($now)?->getRoles() ?? [];
    }
}
