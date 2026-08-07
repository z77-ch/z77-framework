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
        private DeviceKeys $deviceKeys,
    ) {
    }

    /** Production wiring: kernel session + file persistence. */
    public static function create(): self
    {
        return new self(
            new MemberSession(DI::getSessionManager()),
            new MemberAccounts(new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']))),
            DeviceKeys::create(),
        );
    }

    /**
     * The signed-in account, or null (nobody, idle-expired, or meanwhile
     * deleted). When the short session is gone, the «angemeldet bleiben»
     * device key gets its say: a valid key resumes the session right here —
     * that is the whole point of the key (B8 stage C), and it makes every
     * page guard inherit the behaviour without knowing about it.
     */
    public function current(?int $now = null): ?MemberAccount
    {
        $accountId = $this->session->currentAccountId($now);
        if ($accountId === null) {
            return $this->resume($now);
        }

        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            // Account rejected/cleaned up while the session lived — end it.
            $this->session->end();

            return null;
        }

        return $account;
    }

    /**
     * No session — is this a device that may stay signed in? A valid key
     * starts a fresh session; the key itself rolls forward in DeviceKeys.
     * The key is only ever issued after a COMPLETE login (link, plus the app
     * code when 2FA is on), so resuming is not a second-factor bypass.
     */
    private function resume(?int $now): ?MemberAccount
    {
        $account = $this->deviceKeys->restore($now);
        if ($account === null) {
            return null;
        }

        $this->session->start((string)$account->getId(), $now);

        return $account;
    }

    /** Roles of the signed-in account; empty when nobody is (or before activation). */
    public function roles(?int $now = null): array
    {
        return $this->current($now)?->getRoles() ?? [];
    }
}
