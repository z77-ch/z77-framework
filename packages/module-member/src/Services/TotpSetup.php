<?php

namespace Z77\Module\Member\Services;

use Z77\Module\Member\Entities\MemberAccount;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * 2FA lifecycle on the profile (B8 spec): begin() stores the secret encrypted
 * but 2FA stays OFF until confirm() saw a valid app code (totpActivatedAt);
 * remove() equally demands a valid code — whoever holds a stolen session
 * cannot silently drop the second factor. resetByOperator() is the backend
 * handgrip for the lost-device case: no code, the customer sets up anew.
 */
final class TotpSetup
{
    public function __construct(
        private MemberAccounts $accounts,
        private TotpVault $vault,
    ) {
    }

    public static function create(): self
    {
        return new self(
            new MemberAccounts(new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']))),
            TotpVault::create(),
        );
    }

    /**
     * Starts the setup — or resumes a pending one (page reload shows the SAME
     * QR, no silent secret rotation). Returns the Base32 secret for the QR
     * and the manual-entry fallback. Active 2FA is the caller's guard.
     */
    public function begin(MemberAccount $account): string
    {
        if ($account->hasPendingTotpSetup()) {
            $existing = $this->vault->decrypt((string)$account->getTotpSecret());
            if ($existing !== null) {
                return $existing;
            }
            // Vault entry unreadable (key rotated?) — fall through to a fresh one.
        }

        $secret = Totp::generateSecret();
        $account->setTotpSecret($this->vault->encrypt($secret));
        $account->setTotpActivatedAt(null);
        $this->accounts->save($account);

        return $secret;
    }

    /** The confirming app code — only now does 2FA become active. */
    public function confirm(MemberAccount $account, string $code, ?int $now = null): bool
    {
        $secret = $account->getTotpSecret() !== null
            ? $this->vault->decrypt($account->getTotpSecret())
            : null;
        if ($secret === null || !Totp::verify($secret, $code, $now ?? time())) {
            return false;
        }

        $account->setTotpActivatedAt(date(DATE_ATOM, $now ?? time()));
        $this->accounts->save($account);

        return true;
    }

    /** Removing 2FA demands a valid code (spec) — then both fields fall. */
    public function remove(MemberAccount $account, string $code, ?int $now = null): bool
    {
        $secret = $account->getTotpSecret() !== null
            ? $this->vault->decrypt($account->getTotpSecret())
            : null;
        if ($secret === null || !Totp::verify($secret, $code, $now ?? time())) {
            return false;
        }

        $this->clear($account);

        return true;
    }

    /** Backend handgrip (lost device): no code — the customer re-runs the setup. */
    public function resetByOperator(MemberAccount $account): void
    {
        $this->clear($account);
    }

    private function clear(MemberAccount $account): void
    {
        $account->setTotpSecret(null);
        $account->setTotpActivatedAt(null);
        $this->accounts->save($account);
    }
}
