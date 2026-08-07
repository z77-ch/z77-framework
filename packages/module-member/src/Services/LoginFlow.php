<?php

namespace Z77\Module\Member\Services;

use Z77\Core\DI;
use Z77\Module\Member\Entities\MemberAccount;
use Z77\Module\Member\Entities\MemberPendingLogin;
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
 *              is identical in every case (anti-oracle), and a waiting
 *              record is opened for EVERY request (stage D).
 *   redeem()   the link click, «here»: session on the READING device.
 *   approve()  the link click, «confirm»: releases the waiting record so the
 *              REQUESTING device can sign in (spec 1.1.0, decision 5).
 *   poll()     what the requesting device asks every few seconds until its
 *              record is released — this is where its session is born.
 *   logout()   ends the member session.
 *
 * Which device gets the session decides everything downstream: the TOTP
 * prompt and the «angemeldet bleiben» key always follow the session, never
 * the click.
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

    /** poll(): the record is still waiting for the other device. */
    public const WAITING  = 'waiting';
    /** approve(): released — the requesting device takes it from here. */
    public const APPROVED = 'approved';

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
        private DeviceKeys $deviceKeys,
        private PendingLogins $pending,
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
            DeviceKeys::create(),
            new PendingLogins($uem),
            static fn(EmailMessage $mail): bool => DI::getEmailService()->send($mail),
            $redeemUrl,
            $registerUrl,
        );
    }

    /**
     * The request submit. Always true — the visitor-facing answer never reveals
     * whether the address has an account, in which state, or whether the
     * throttle just swallowed the mail. Only the mail differs.
     *
     * A waiting record is opened in every case (stage D) and remembered in this
     * device's session: the waiting page must look identical whether a link
     * went out or not. Without a link it simply never turns green — which is
     * also what a throttled visitor sees, deliberately. The throttle keeps
     * doing its job; it just stops being visible.
     */
    public function request(string $email, bool $remember = false, ?int $now = null): bool
    {
        $now     = $now ?? time();
        $allowed = $this->throttle->allow($email, $now);
        $account = $allowed ? $this->accounts->findByEmail($email) : null;
        $plain   = null;

        // Order matters: the token first (the waiting record binds to its
        // hash), then the record (the mail names its check digits), then the
        // mail. A login token exists only for confirmed/active accounts.
        if ($account !== null && !$account->isRegistered()) {
            $plain = $this->tokens->issue(
                (string)$account->getId(),
                MemberToken::PURPOSE_LOGIN,
                self::LOGIN_TTL_SECONDS,
                $now,
                $remember
            );
        }

        $record = $this->pending->open(
            $plain !== null ? hash('sha256', $plain) : null,
            DeviceKeys::label((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
            $remember,
            self::LOGIN_TTL_SECONDS,
            $now
        );
        $this->session->setPendingLoginId((string)$record->getId());

        if ($allowed) {
            ($this->sendMail)(match (true) {
                $plain !== null   => $this->loginMail($account, $plain, $record),
                $account === null => $this->noAccountMail($email),
                default           => $this->registration->confirmMailFor($account, $now),
            });
        }

        return true;
    }

    /** The waiting record of THIS device, or null (none, or past its window). */
    public function currentPending(?int $now = null): ?MemberPendingLogin
    {
        $id = $this->session->pendingLoginId();
        if ($id === null) {
            return null;
        }

        $record = $this->pending->find($id);

        return $record !== null && !$record->isExpired($now ?? time()) ? $record : null;
    }

    /**
     * What the confirmation page shows BEFORE anything is consumed: the
     * context of the request behind this link (digits, device, time), or null
     * for a dead link. Returns the record too — a link whose waiting record is
     * gone can still be used to sign in on the reading device, so the page
     * then offers only that button.
     *
     * @return ?array{token: \Z77\Module\Member\Entities\MemberToken, pending: ?MemberPendingLogin}
     */
    public function linkContext(?string $plainToken, ?int $now = null): ?array
    {
        $now        = $now ?? time();
        $plainToken = trim((string)$plainToken);
        if ($plainToken === '') {
            return null;
        }

        $token = $this->tokens->inspect($plainToken, MemberToken::PURPOSE_LOGIN, $now);
        if ($token === null) {
            return null;
        }

        $record = $this->pending->findByTokenHash($token->getTokenHash());

        return [
            'token'   => $token,
            'pending' => $record !== null && !$record->isExpired($now) && !$record->isApproved() ? $record : null,
        ];
    }

    /**
     * «Anmeldung auf Gerät xy bestätigen» — consumes the link and releases the
     * waiting record. NO session is created here: the reading device stays
     * signed out, the requesting one picks it up in poll().
     */
    public function approve(?string $plainToken, ?int $now = null): string
    {
        $now        = $now ?? time();
        $plainToken = trim((string)$plainToken);
        if ($plainToken === '') {
            return self::DEAD;
        }

        $token = $this->tokens->redeemToken($plainToken, MemberToken::PURPOSE_LOGIN, $now);
        if ($token === null) {
            return self::DEAD;
        }

        $account = $this->accounts->findById($token->getAccountRef());
        if ($account === null || $account->isRegistered()) {
            return self::DEAD;
        }

        $record = $this->pending->findByTokenHash($token->getTokenHash());
        if ($record === null || $record->isExpired($now)) {
            // The request behind this link is gone (window passed, or it was
            // already used up elsewhere) — nothing left to release.
            return self::DEAD;
        }

        $this->pending->approve($record, (string)$account->getId());

        return self::APPROVED;
    }

    /**
     * The status poll of the WAITING device. 'waiting' until the other device
     * confirms; then the session is born right here — with the TOTP prompt
     * first when 2FA is on, and the device key going to THIS device, because
     * this is where the tick was set (spec 1.1.0).
     */
    public function poll(?int $now = null): string
    {
        $now = $now ?? time();

        $id = $this->session->pendingLoginId();
        if ($id === null) {
            return self::DEAD;
        }

        $record = $this->pending->find($id);
        if ($record === null || $record->isExpired($now)) {
            $this->session->clearPendingLogin();

            return self::DEAD;
        }
        if (!$record->isApproved()) {
            return self::WAITING;
        }

        $account = $this->accounts->findById((string)$record->getAccountRef());
        if ($account === null) {
            $this->session->clearPendingLogin();
            $this->pending->delete($record);

            return self::DEAD;
        }

        $remember = $record->wantsRemember();
        $this->session->clearPendingLogin();
        $this->pending->delete($record); // the process is done, one way or another

        if ($account->hasTotp()) {
            $this->session->startTotpPending((string)$account->getId(), $remember, $now);

            return self::TOTP_REQUIRED;
        }

        $this->session->start((string)$account->getId(), $now);
        if ($remember) {
            $this->deviceKeys->issueFor($account, null, $now);
        }

        return self::SESSION;
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

        $token = $this->tokens->redeemToken($plainToken, MemberToken::PURPOSE_LOGIN, $now);
        if ($token === null) {
            return self::DEAD;
        }

        $account = $this->accounts->findById($token->getAccountRef());
        if ($account === null || $account->isRegistered()) {
            // Vanished, or never confirmed (a login token should not exist
            // for a registered account — defense in depth): no session.
            return self::DEAD;
        }

        // «Hier anmelden» ends the other device's wait: its record dies with
        // the link, so its poll answers 'dead' instead of hanging until the
        // window closes.
        $waiting = $this->pending->findByTokenHash($token->getTokenHash());
        if ($waiting !== null) {
            $this->pending->delete($waiting);
        }

        if ($account->hasTotp()) {
            // The device key waits for the second factor (stage C).
            $this->session->startTotpPending((string)$account->getId(), $token->wantsRemember(), $now);

            return self::TOTP_REQUIRED;
        }

        $this->session->start((string)$account->getId(), $now);
        if ($token->wantsRemember()) {
            $this->deviceKeys->issueFor($account, null, $now);
        }

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

        $remember = $this->session->totpPendingRemember();

        $this->totpGuard->reset($accountId);
        $this->session->clearTotpPending();
        $this->session->start($accountId, $now);
        if ($remember) {
            $this->deviceKeys->issueFor($account, null, $now);
        }

        return self::SESSION;
    }

    /**
     * Ends the member session AND this device's key — otherwise the next visit
     * would silently resume and «abmelden» would be a lie.
     */
    public function logout(?int $now = null): void
    {
        // The ACL projection (auth_user, realm member) is NOT touched here:
        // it is derived per request by MemberAuthBridge, and the logout
        // response is a redirect — the next request's bridge run finds no
        // member session and clears it before AccessGuard reads it.
        $this->deviceKeys->forgetCurrent($now);
        $this->session->end();
    }

    /**
     * «Alle Geräte abmelden» (profile): every device key of the account dies,
     * this one included. The current session stays — the customer is standing
     * on the profile page and asked for the OTHER devices to be locked out.
     */
    public function logoutAllDevices(MemberAccount $account): void
    {
        $this->deviceKeys->revokeAll($account);
    }

    // ── the mails ──────────────────────────────────────────────────────────

    /**
     * The login mail. Subject and body carry the check digits and the context
     * of the request — so the customer can tell an own login from one someone
     * else started BEFORE clicking anything (spec 1.1.0).
     */
    private function loginMail(MemberAccount $account, string $plain, MemberPendingLogin $record): EmailMessage
    {
        $link = $this->redeemUrl . (str_contains($this->redeemUrl, '?') ? '&' : '?')
              . 'token=' . urlencode($plain);

        return (new EmailMessage())
            ->to($account->getEmail())
            ->subject('Anmeldung bestätigen — Prüfzahl ' . $record->getCheckDigits())
            ->template('emails/login-link', 'Z77\\Module\\Member', [
                'account'  => $account,
                'loginUrl' => $link,
                'digits'   => $record->getCheckDigits(),
                'device'   => $record->getLabel(),
                'when'     => date('d.m.Y, H:i', (int)strtotime($record->getRequestedAt())),
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
