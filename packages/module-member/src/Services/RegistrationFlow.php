<?php

namespace Z77\Module\Member\Services;

use Z77\Core\DI;
use Z77\Module\Member\Entities\MemberAccount;
use Z77\Module\Member\Entities\MemberToken;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Mail\EmailMessage;

/**
 * The registration story of the member module (B7 spec), one method per
 * station — the controllers stay thin:
 *
 *   register()  the validated form submit: throttle per address, then either
 *               the confirmation mail (new account) or the "you already have
 *               an account" mail (existing one) — SAME return value, the
 *               visitor-facing answer never reveals which (anti-oracle).
 *   confirm()   the link click: 'confirmed' | 'already' | 'dead'. Dead means
 *               unknown, expired or used-while-unconfirmed — one answer, the
 *               resend page. On success the operator notification goes out.
 *   resend()    new link for a registered account; existing-account mail for
 *               a confirmed/active one; nothing for an unknown address —
 *               always the same neutral answer.
 *
 * Mail failures never fail the flow (spec: the account exists anyway, resend
 * covers it) — EmailService already logs the cause. The operator notification
 * uses the emailConfig form key 'memberConfirmed'; a project that wants the
 * mail configures that key (recipient/subject), one that does not simply
 * leaves it absent (the throw is swallowed here, deliberately).
 *
 * Mail transports are injected as closures so the harness can capture intents
 * (and assert the confirm link) without a configured mailer.
 */
final class RegistrationFlow
{
    public const CONFIRM_TTL_SECONDS = 7 * 86400; // spec default: 7 days
    public const NOTIFY_FORM_KEY     = 'memberConfirmed';

    public const CONFIRMED = 'confirmed';
    public const ALREADY   = 'already';
    public const DEAD      = 'dead';

    /**
     * @param \Closure(EmailMessage): bool      $sendMail mail to the registrant
     * @param \Closure(MemberAccount): bool     $notifyUs operator notification (config-keyed)
     * @param string $confirmUrl absolute URL of the confirm action; the token is appended
     */
    public function __construct(
        private MemberAccounts $accounts,
        private TokenService $tokens,
        private MemberThrottle $throttle,
        private \Closure $sendMail,
        private \Closure $notifyUs,
        private string $confirmUrl,
    ) {
    }

    /** Production wiring: file persistence, EmailService transports. */
    public static function create(string $confirmUrl): self
    {
        $uem = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));

        return new self(
            new MemberAccounts($uem),
            new TokenService($uem),
            new MemberThrottle(rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/data/framework/member/throttle'),
            static fn(EmailMessage $mail): bool => DI::getEmailService()->send($mail),
            static function (MemberAccount $account): bool {
                try {
                    return DI::getEmailService()->sendForm(self::NOTIFY_FORM_KEY, ['account' => $account]);
                } catch (\Throwable) {
                    return false; // form key not configured — the notification is opt-in
                }
            },
            $confirmUrl,
        );
    }

    /**
     * The validated register submit. False only when the address is throttled
     * (the form shows the generic send error); true otherwise — for a new
     * account AND for an existing one, so the page cannot be used to probe
     * which addresses have accounts.
     */
    public function register(
        string $email,
        ?string $company,
        ?string $firstName,
        ?string $lastName,
        ?int $now = null
    ): bool {
        $now ??= time();
        if (!$this->throttle->allow($email, $now)) {
            return false;
        }

        $existing = $this->accounts->findByEmail($email);
        if ($existing !== null) {
            ($this->sendMail)($this->existingAccountMail($existing));

            return true;
        }

        $account = $this->accounts->register($email, $company, $firstName, $lastName, $now);
        if ($account === null) {
            // Raced: the address got its account between lookup and insert —
            // same answer as the existing-account branch above.
            $existing = $this->accounts->findByEmail($email);
            if ($existing !== null) {
                ($this->sendMail)($this->existingAccountMail($existing));
            }

            return true;
        }
        ($this->sendMail)($this->confirmMail($account, $now));

        return true;
    }

    /** @return self::CONFIRMED|self::ALREADY|self::DEAD */
    public function confirm(?string $plainToken, ?int $now = null): string
    {
        $now ??= time();
        $plainToken = trim((string)$plainToken);
        if ($plainToken === '') {
            return self::DEAD;
        }

        // A used link on an already-confirmed account is the double click of
        // the spec («bereits bestätigt») — answered from the ACCOUNT state,
        // before redeem() would call the token dead.
        $accountRef = $this->tokens->peek($plainToken, MemberToken::PURPOSE_CONFIRM);
        if ($accountRef !== null) {
            $account = $this->accounts->findById($accountRef);
            if ($account !== null && !$account->isRegistered()) {
                return self::ALREADY;
            }
        }

        $accountRef = $this->tokens->redeem($plainToken, MemberToken::PURPOSE_CONFIRM, $now);
        if ($accountRef === null) {
            return self::DEAD;
        }
        $account = $this->accounts->findById($accountRef);
        if ($account === null) {
            return self::DEAD; // account meanwhile rejected/cleaned up
        }

        $this->accounts->confirm($account, $now);
        ($this->notifyUs)($account);

        return self::CONFIRMED;
    }

    /**
     * New confirmation link on request. Neutral by construction: the return
     * value is false only on throttle — unknown address, registered and
     * confirmed accounts all answer true and differ only in what (if any)
     * mail goes out.
     */
    public function resend(string $email, ?int $now = null): bool
    {
        $now ??= time();
        if (!$this->throttle->allow($email, $now)) {
            return false;
        }

        $account = $this->accounts->findByEmail($email);
        if ($account === null) {
            return true; // no account, no mail — same answer
        }

        ($this->sendMail)(
            $account->isRegistered()
                ? $this->confirmMail($account, $now)
                : $this->existingAccountMail($account)
        );

        return true;
    }

    // ── the mails ──────────────────────────────────────────────────────────

    private function confirmMail(MemberAccount $account, int $now): EmailMessage
    {
        $plain = $this->tokens->issue(
            (string)$account->getId(),
            MemberToken::PURPOSE_CONFIRM,
            self::CONFIRM_TTL_SECONDS,
            $now
        );

        $link = $this->confirmUrl . (str_contains($this->confirmUrl, '?') ? '&' : '?')
              . 'token=' . urlencode($plain);

        return (new EmailMessage())
            ->to($account->getEmail())
            ->subject('Bitte bestätigen Sie Ihre E-Mail-Adresse')
            ->template('emails/confirm', 'Z77\\Module\\Member', [
                'account'    => $account,
                'confirmUrl' => $link,
            ]);
    }

    private function existingAccountMail(MemberAccount $account): EmailMessage
    {
        return (new EmailMessage())
            ->to($account->getEmail())
            ->subject('Sie haben bereits ein Konto')
            ->template('emails/existing-account', 'Z77\\Module\\Member', [
                'account' => $account,
            ]);
    }
}
