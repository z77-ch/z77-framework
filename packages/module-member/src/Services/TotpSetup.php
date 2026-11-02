<?php

namespace Z77\Module\Member\Services;

use Z77\Core\DI;
use Z77\Module\Member\Entities\MemberAccount;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Auth\Totp;
use Z77\Shared\Auth\TotpGuard;
use Z77\Shared\Auth\TotpVault;

/**
 * 2FA lifecycle on the profile (B8 spec). A running setup waits in the session, as ciphertext, not
 * in the store, and every open of the page makes a new secret: what a thief read with a stolen
 * cookie is gone at the owner's next open, at the latest at login or logout and after
 * {@see ENROLMENT_SECONDS}; the owner cannot confirm a secret someone else planted in the store.
 * confirm() is the first thing that reaches the store, and it spends the confirming code.
 *
 * Removal goes through the code check with its lock and its counter, so a stolen session cannot
 * guess the code without limit to drop the factor. resetByOperator() is the backend handgrip for
 * the lost-device case: no code, the customer sets up anew.
 *
 * A secret an earlier version left pending in the store (`hasPendingTotpSetup()`) is never confirmed
 * any more: it is overwritten by the next switch-on and cleared by an operator reset.
 */
final class TotpSetup
{
    public const ENROLMENT_SECONDS = 600;

    private TotpCodeCheck $check;

    public function __construct(
        private MemberAccounts $accounts,
        private TotpVault $vault,
        private TotpGuard $guard,
        private MemberSession $session,
    ) {
        $this->check = new TotpCodeCheck($accounts, $vault, $guard);
    }

    public static function create(): self
    {
        return new self(
            new MemberAccounts(new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']))),
            MemberTotp::vault(),
            MemberTotp::guard(),
            new MemberSession(DI::getSessionManager()),
        );
    }

    /**
     * A new secret for this open of the page, replacing any earlier one of this session. Returns the
     * Base32 secret for the QR and the manual-entry fallback. Writes nothing to the store. Active
     * 2FA is the caller's guard.
     *
     * @throws \RuntimeException when the key file is damaged
     */
    public function begin(MemberAccount $account, ?int $now = null): string
    {
        $secret = Totp::generateSecret();
        $this->session->setTotpEnrolment((string) $account->getId(), $now ?? time(), $this->vault->encrypt($secret));

        return $secret;
    }

    /**
     * The secret of the running setup, or null: a wrong code re-renders the page with the same QR,
     * so the person does not have to scan again.
     */
    public function running(MemberAccount $account, ?int $now = null): ?string
    {
        $pending = $this->livePending($account, $now ?? time());

        return $pending !== null ? $this->vault->decrypt((string) ($pending['secret'] ?? '')) : null;
    }

    /** Can a code be judged right now? Switching on with dead counters would lock the person out. */
    public function canCheckCodes(MemberAccount $account): bool
    {
        return $this->check->canCheckCodes((string) $account->getId());
    }

    /**
     * The confirming app code: only now does anything reach the store, and the code is spent with
     * it. False = wrong code, nothing pending in this session, the page too old, 2FA already on or
     * dead counters; 2FA stays as it was.
     */
    public function confirm(MemberAccount $account, string $code, ?int $now = null): bool
    {
        $now     ??= time();
        $accountId = (string) $account->getId();
        $pending   = $this->livePending($account, $now);
        if ($pending === null || !$this->check->canCheckCodes($accountId)) {
            return false;
        }

        $ciphertext = (string) ($pending['secret'] ?? '');
        $secret     = $this->vault->decrypt($ciphertext);
        $step       = $secret !== null ? Totp::acceptedStep($secret, $code, $now) : null;
        if ($step === null) {
            return false;
        }

        $lock = $this->guard->acquire($accountId);
        if ($lock === null) {
            $this->check->unavailable($accountId, 'lock');

            return false;
        }
        try {
            $fresh = $this->accounts->findById($accountId);
            if ($fresh === null || $fresh->hasTotp()) {
                return false; // another session of this person got there first
            }
            $this->enable($fresh, $ciphertext, $now, $step);
            $this->accounts->save($fresh);
        } finally {
            $this->guard->release($lock);
        }

        $this->enable($account, $ciphertext, $now, $step);
        $this->session->clearTotpEnrolment();
        $this->guard->reset($accountId);
        MemberLog::write('totp.on', $accountId);

        return true;
    }

    /**
     * Removing 2FA demands a valid, unspent code, judged like a login's: a wrong one counts towards
     * the lock.
     *
     * @return TotpCodeCheck::PASSED|TotpCodeCheck::WRONG|TotpCodeCheck::LOCKED|TotpCodeCheck::UNAVAILABLE|TotpCodeCheck::GONE
     */
    public function removeWithCode(MemberAccount $account, string $code, ?int $now = null): string
    {
        $outcome = $this->check->judge((string) $account->getId(), $code, $now ?? time(), static function (MemberAccount $fresh): void {
            $fresh->clearTotp();
        });
        if ($outcome === TotpCodeCheck::PASSED) {
            $account->clearTotp();
            MemberLog::write('totp.off', (string) $account->getId());
        }

        return $outcome;
    }

    /**
     * Backend handgrip (lost device): no code — the customer re-runs the setup. On the account read
     * fresh under the lock; without the lock it runs anyway, with a log line: it judges no guess, and
     * it is the way out of dead counters.
     */
    public function resetByOperator(MemberAccount $account): void
    {
        $accountId = (string) $account->getId();
        $lock      = $this->guard->acquire($accountId);
        if ($lock === null) {
            error_log('MemberTotp: lock unavailable, reset not serialized for account id ' . $accountId . ' dir=' . $this->guard->directory());
        }
        try {
            $fresh = $this->accounts->findById($accountId);
            $had   = $fresh?->hasTotp() ?? false;
            if ($fresh !== null) {
                $fresh->clearTotp();
                $this->accounts->save($fresh);
            }
        } finally {
            if ($lock !== null) {
                $this->guard->release($lock);
            }
        }

        $account->clearTotp();
        $this->guard->reset($accountId);
        if ($had) {
            // Who cleared it comes from the request's actor (`operator`).
            MemberLog::write('totp.off', $accountId);
        }
    }

    private function enable(MemberAccount $account, string $ciphertext, int $now, int $step): void
    {
        $account->setTotpSecret($ciphertext);
        $account->setTotpActivatedAt(date(DATE_ATOM, $now));
        $account->recordTotpStep($step);
    }

    /** @return array{account_id:string, at:int, secret:string}|null the running setup of THIS account */
    private function livePending(MemberAccount $account, int $now): ?array
    {
        $pending = $this->session->totpEnrolment();
        if ($pending === null || $pending['account_id'] !== (string) $account->getId()) {
            return null;
        }
        if ($now - $pending['at'] >= self::ENROLMENT_SECONDS) {
            $this->session->clearTotpEnrolment();

            return null;
        }

        return $pending;
    }
}
