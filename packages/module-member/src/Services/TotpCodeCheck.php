<?php

namespace Z77\Module\Member\Services;

use Z77\Module\Member\Entities\MemberAccount;
use Z77\Shared\Auth\Totp;
use Z77\Shared\Auth\TotpGuard;
use Z77\Shared\Auth\TotpVault;

/**
 * The one way the member door judges a TOTP code (member.md MEMBER-2FA-001), the counterpart of the
 * backend's BackendTwoFactor: guard, verify, spend the step and save, inside one lock per account,
 * on the account read fresh under it. So parallel guesses cannot pass the guard before the first
 * failure is counted, and one code cannot pass twice (RFC 6238 §5.2).
 *
 * ⚠️ Fails closed: without a counter there is no limit on guesses, so when the guard cannot write or
 * cannot take its lock every code is refused, the right one too. The disk is asked FIRST: a right
 * code clears the counter and would never find out that a wrong one could not be counted. An
 * account without 2FA never comes here.
 */
final class TotpCodeCheck
{
    public const PASSED      = 'passed';
    public const WRONG       = 'wrong';
    public const LOCKED      = 'locked';
    public const UNAVAILABLE = 'unavailable';
    /** The account is gone or holds no readable secret: there is nothing to judge against. */
    public const GONE        = 'gone';

    /** What the person is told on {@see UNAVAILABLE}, seen only after a valid link or in a session. */
    public const UNAVAILABLE_MESSAGE = 'Die Anmeldung mit Code ist wegen eines technischen Problems gerade nicht möglich. Bitte den Betreiber informieren.';

    public function __construct(
        private MemberAccounts $accounts,
        private TotpVault $vault,
        private TotpGuard $guard,
    ) {
    }

    /**
     * Judges $code for the account. Right: $onPass gets the fresh account and the accepted step, and
     * the account is saved. A wrong code and a spent one count towards the lock; while locked the
     * right code is refused too.
     *
     * @param callable(MemberAccount, int): void $onPass
     * @return self::PASSED|self::WRONG|self::LOCKED|self::UNAVAILABLE|self::GONE
     */
    public function judge(string $accountId, string $code, int $now, callable $onPass): string
    {
        if (!$this->guard->canCount($accountId)) {
            return $this->unavailable($accountId, 'write-counter');
        }
        $lock = $this->guard->acquire($accountId);
        if ($lock === null) {
            return $this->unavailable($accountId, 'lock');
        }

        try {
            if ($this->guard->isLocked($accountId, $now)) {
                return self::LOCKED;
            }

            $account = $this->accounts->findById($accountId);
            $secret  = $account?->hasTotp() ? $this->vault->decrypt((string) $account->getTotpSecret()) : null;
            if ($account === null || $secret === null) {
                return self::GONE;
            }

            $step = Totp::acceptedStep($secret, $code, $now, $account->getTotpLastStep());
            if ($step === null) {
                if (!$this->guard->recordFailure($accountId, $now)) {
                    return $this->unavailable($accountId, 'record-failure');
                }

                return $this->guard->isLocked($accountId, $now) ? self::LOCKED : self::WRONG;
            }

            if (!$this->guard->reset($accountId)) {
                return $this->unavailable($accountId, 'clear-counter');
            }
            $onPass($account, $step);
            $this->accounts->save($account);

            return self::PASSED;
        } finally {
            $this->guard->release($lock);
        }
    }

    /** Can a code be judged right now? For the setup, where a right code needs no counter yet. */
    public function canCheckCodes(string $accountId): bool
    {
        if ($this->guard->canCount($accountId)) {
            return true;
        }
        $this->unavailable($accountId, 'write-counter');

        return false;
    }

    /**
     * Every refusal for a dead guard: one log line with the step and the directory, in the form of
     * the backend's (login.md «code refused»), never a code or a secret.
     */
    public function unavailable(string $accountId, string $step): string
    {
        error_log('MemberTotp: code refused, counters unavailable — step=' . $step . ' dir=' . $this->guard->directory() . ' account id ' . $accountId);

        return self::UNAVAILABLE;
    }
}
