<?php

namespace Z77\Module\Member\Services;

use Z77\Core\Session\SessionManager;

/**
 * The member session (B8): which account is signed in, carried by the kernel
 * session. Short-lived by design — it ends with the browser (cookie lifetime
 * 0, SessionManager) or after inactivity (spec default: 2 hours); the
 * long-lived path is the revocable device key («angemeldet bleiben», stage C),
 * never the session itself.
 *
 * The session id is regenerated on start and end (fixation defense); in CLI
 * (harness) the kernel session is a plain array and regeneration is skipped.
 */
final class MemberSession
{
    public const IDLE_SECONDS = 7200; // spec default: 2 h inactivity

    /** How long the TOTP interstitial may take between link click and code. */
    public const TOTP_PENDING_SECONDS = 300;

    private const KEY_ACCOUNT      = 'member.accountId';
    private const KEY_LAST_SEEN    = 'member.lastSeenAt';
    private const KEY_TOTP_PENDING = 'member.totpPendingId';
    private const KEY_TOTP_SINCE   = 'member.totpPendingAt';
    private const KEY_TOTP_REMEMBER = 'member.totpPendingRemember';

    public function __construct(private SessionManager $session)
    {
    }

    /** Signs the account in: fresh session id, idle clock starts now. */
    public function start(string $accountId, ?int $now = null): void
    {
        $this->regenerate();
        $this->session->set(self::KEY_ACCOUNT, $accountId);
        $this->session->set(self::KEY_LAST_SEEN, $now ?? time());
    }

    /**
     * The signed-in account id, or null — none, or idle-expired (the session
     * ends right there). A live read refreshes the idle clock (rolling).
     */
    public function currentAccountId(?int $now = null): ?string
    {
        $accountId = $this->session->get(self::KEY_ACCOUNT);
        if (!is_string($accountId) || $accountId === '') {
            return null;
        }

        $now      = $now ?? time();
        $lastSeen = (int)$this->session->get(self::KEY_LAST_SEEN, 0);
        if ($now - $lastSeen > self::IDLE_SECONDS) {
            $this->end();

            return null;
        }

        $this->session->set(self::KEY_LAST_SEEN, $now);

        return $accountId;
    }

    /** Signs out: member keys gone, fresh session id for whatever comes next. */
    public function end(): void
    {
        $this->session->remove(self::KEY_ACCOUNT);
        $this->session->remove(self::KEY_LAST_SEEN);
        $this->clearTotpPending();
        $this->regenerate();
    }

    // ── the TOTP interstitial (B8 stage B) ─────────────────────────────────

    /**
     * The redeemed link parks here when 2FA is active — no session yet. The
     * link's «angemeldet bleiben» wish waits here too: the device key may only
     * be issued once the second factor is in (stage C).
     */
    public function startTotpPending(string $accountId, bool $remember = false, ?int $now = null): void
    {
        $this->regenerate();
        $this->session->set(self::KEY_TOTP_PENDING, $accountId);
        $this->session->set(self::KEY_TOTP_SINCE, $now ?? time());
        $this->session->set(self::KEY_TOTP_REMEMBER, $remember);
    }

    /** Did the link that parks at the code prompt ask to stay signed in? */
    public function totpPendingRemember(): bool
    {
        return (bool)$this->session->get(self::KEY_TOTP_REMEMBER, false);
    }

    /** Account waiting at the code prompt, or null (none / took too long). */
    public function totpPendingAccountId(?int $now = null): ?string
    {
        $accountId = $this->session->get(self::KEY_TOTP_PENDING);
        if (!is_string($accountId) || $accountId === '') {
            return null;
        }
        if (($now ?? time()) - (int)$this->session->get(self::KEY_TOTP_SINCE, 0) > self::TOTP_PENDING_SECONDS) {
            $this->clearTotpPending();

            return null;
        }

        return $accountId;
    }

    public function clearTotpPending(): void
    {
        $this->session->remove(self::KEY_TOTP_PENDING);
        $this->session->remove(self::KEY_TOTP_SINCE);
        $this->session->remove(self::KEY_TOTP_REMEMBER);
    }

    private function regenerate(): void
    {
        if (PHP_SAPI !== 'cli') {
            $this->session->regenerate();
        }
    }
}
