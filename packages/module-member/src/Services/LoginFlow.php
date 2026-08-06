<?php

namespace Z77\Module\Member\Services;

use Z77\Core\DI;
use Z77\Module\Member\Entities\MemberAccount;
use Z77\Module\Member\Entities\MemberToken;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Mail\EmailMessage;

/**
 * The login story of the member module (B8 spec) — magic link, no password:
 *
 *   request()  the e-mail form submit: throttle per address (shared window
 *              with registration), then ONE of three mails by account state
 *              (spec table) — login link (confirmed/active), a fresh B7
 *              confirmation link (registered), or the "no account here"
 *              hint with the registration link (unknown). The page answer
 *              is identical in every case (anti-oracle).
 *   redeem()   the link click: 'session' (signed in) | 'dead' (unknown,
 *              expired or used — one answer, back to the request page).
 *              The TOTP interstitial (stage B) hooks in between.
 *   logout()   ends the member session.
 *
 * Login tokens live in the SAME store as confirmation tokens with
 * purpose 'login' (B7 spec: one mechanism, two effects) — but short-lived:
 * 15 minutes instead of 7 days.
 */
final class LoginFlow
{
    public const LOGIN_TTL_SECONDS = 900; // spec default: 15 min

    public const SESSION       = 'session';
    public const TOTP_REQUIRED = 'totp-required';
    public const DEAD          = 'dead';

    public const TOTP_INVALID = 'invalid';
    public const TOTP_LOCKED  = 'locked';

    /**
     * @param \Closure(EmailMessage): bool $sendMail
     * @param string $redeemUrl   absolute URL of the redeem action; token appended
     * @param string $registerUrl absolute URL of the registration page (no-account mail)
     */
    public function __construct(
        private MemberAccounts $accounts,
        private TokenService $tokens,
        private MemberThrottle $throttle,
        private MemberSession $session,
        private RegistrationFlow $registration,
        private TotpVault $totpVault,
        private TotpGuard $totpGuard,
        private \Closure $sendMail,
        private string $redeemUrl,
        private string $registerUrl,
    ) {
    }

    /** Production wiring — $confirmUrl feeds the embedded RegistrationFlow (registered case). */
    public static function create(string $redeemUrl, string $registerUrl, string $confirmUrl): self
    {
        $uem = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));

        return new self(
            new MemberAccounts($uem),
            new TokenService($uem),
            new MemberThrottle(rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/data/framework/member/throttle'),
            new MemberSession(DI::getSessionManager()),
            RegistrationFlow::create($confirmUrl),
            TotpVault::create(),
            TotpGuard::create(),
            static fn(EmailMessage $mail): bool => DI::getEmailService()->send($mail),
            $redeemUrl,
            $registerUrl,
        );
    }

    /**
     * The request submit. False only on throttle; true otherwise — the
     * visitor-facing answer never reveals whether the address has an account
     * or in which state.
     */
    public function request(string $email, ?int $now = null): bool
    {
        $now ??= time();
        if (!$this->throttle->allow($email, $now)) {
            return false;
        }

        $account = $this->accounts->findByEmail($email);

        ($this->sendMail)(match (true) {
            $account === null          => $this->noAccountMail($email),
            $account->isRegistered()   => $this->registration->confirmMailFor($account, $now),
            default                    => $this->loginMail($account, $now), // confirmed or active
        });

        return true;
    }

    /**
     * The link click. 'session': the member session exists from this moment.
     * 'totp-required': the link was valid, but 2FA is active — the account
     * parks at the code prompt (5-minute window), NO session yet. 'dead':
     * unknown, expired, used, or the account vanished meanwhile — one
     * answer, the request page with a hint.
     */
    public function redeem(?string $plainToken, ?int $now = null): string
    {
        $now ??= time();
        $plainToken = trim((string)$plainToken);
        if ($plainToken === '') {
            return self::DEAD;
        }

        $accountRef = $this->tokens->redeem($plainToken, MemberToken::PURPOSE_LOGIN, $now);
        if ($accountRef === null) {
            return self::DEAD;
        }

        $account = $this->accounts->findById($accountRef);
        if ($account === null || $account->isRegistered()) {
            // Vanished, or never confirmed (a login token should not exist
            // for a registered account — defense in depth): no session.
            return self::DEAD;
        }

        if ($account->hasTotp()) {
            $this->session->startTotpPending((string)$account->getId(), $now);

            return self::TOTP_REQUIRED;
        }

        $this->session->start((string)$account->getId(), $now);

        return self::SESSION;
    }

    /**
     * The code prompt submit (B8: only a valid app code creates the session).
     * 'session' | 'invalid' (wrong code, counted) | 'locked' (five failures →
     * 15-minute window; the RIGHT code is refused too) | 'dead' (nothing
     * pending or the prompt window expired — back to the request page).
     */
    public function confirmTotp(string $code, ?int $now = null): string
    {
        $now ??= time();

        $accountId = $this->session->totpPendingAccountId($now);
        if ($accountId === null) {
            return self::DEAD;
        }
        if ($this->totpGuard->isLocked($accountId, $now)) {
            return self::TOTP_LOCKED;
        }

        $account = $this->accounts->findById($accountId);
        $secret  = $account?->getTotpSecret() !== null ? $this->totpVault->decrypt($account->getTotpSecret()) : null;
        if ($account === null || $secret === null) {
            $this->session->clearTotpPending();

            return self::DEAD; // account or vault entry gone — start over
        }

        if (!Totp::verify($secret, $code, $now)) {
            $this->totpGuard->recordFailure($accountId, $now);

            return $this->totpGuard->isLocked($accountId, $now) ? self::TOTP_LOCKED : self::TOTP_INVALID;
        }

        $this->totpGuard->reset($accountId);
        $this->session->clearTotpPending();
        $this->session->start($accountId, $now);

        return self::SESSION;
    }

    /** Ends the member session (the device key path is stage C). */
    public function logout(): void
    {
        $this->session->end();
    }

    // ── the mails ──────────────────────────────────────────────────────────

    private function loginMail(MemberAccount $account, int $now): EmailMessage
    {
        $plain = $this->tokens->issue(
            (string)$account->getId(),
            MemberToken::PURPOSE_LOGIN,
            self::LOGIN_TTL_SECONDS,
            $now
        );

        $link = $this->redeemUrl . (str_contains($this->redeemUrl, '?') ? '&' : '?')
              . 'token=' . urlencode($plain);

        return (new EmailMessage())
            ->to($account->getEmail())
            ->subject('Ihr Anmelde-Link')
            ->template('emails/login-link', 'Z77\\Module\\Member', [
                'account'  => $account,
                'loginUrl' => $link,
            ]);
    }

    private function noAccountMail(string $email): EmailMessage
    {
        return (new EmailMessage())
            ->to(MemberAccount::normalizeEmail($email))
            ->subject('Zu dieser Adresse besteht kein Konto')
            ->template('emails/no-account', 'Z77\\Module\\Member', [
                'registerUrl' => $this->registerUrl,
            ]);
    }
}
