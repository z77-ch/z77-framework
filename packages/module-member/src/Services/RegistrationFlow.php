<?php

namespace Z77\Module\Member\Services;

use Z77\Core\Config\AuthRole;
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
     * @param ?\Closure(MemberAccount): ?string $activationHook the project side of
     *     activate() — creates whatever the project attaches to an account (AXO3:
     *     the tenant) and returns the reference for tenantRef. Null = no project
     *     attachment (module standalone).
     */
    public function __construct(
        private MemberAccounts $accounts,
        private TokenService $tokens,
        private MemberThrottle $throttle,
        private \Closure $sendMail,
        private \Closure $notifyUs,
        private string $confirmUrl,
        private ?\Closure $activationHook = null,
    ) {
    }

    /**
     * Production wiring: file persistence, EmailService transports, activation
     * hook from the member App config (`activationHook` — FQCN of an invokable
     * class `__invoke(MemberAccount): ?string`; a project sets it by overriding
     * the config file whole).
     */
    public static function create(string $confirmUrl): self
    {
        $uem  = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
        $fqcn = (string)DI::getConfigManager()
            ->getArrayConfig('App/Config/memberConfig', 'Z77\\Module\\Member')
            ->get('activationHook', '');

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
            $fqcn !== '' ? static fn(MemberAccount $a): ?string => (new $fqcn())($a) : null,
        );
    }

    /**
     * The validated register submit. False only when the address is throttled
     * (the form shows the generic send error); true otherwise — for a new
     * account AND for an existing one, so the page cannot be used to probe
     * which addresses have accounts.
     *
     * $origin records WHICH offer was clicked (the register link's `?via=`).
     * It rides only on a NEW account: an address that already has one keeps
     * the origin it was born with — the second click is not a second
     * registration, and overwriting it would rewrite history to make the
     * anti-oracle answer look real.
     */
    public function register(
        string $email,
        ?string $company,
        ?string $firstName,
        ?string $lastName,
        ?int $now = null,
        ?string $origin = null
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

        $account = $this->accounts->register($email, $company, $firstName, $lastName, $now, $origin);
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

    /**
     * The operator's activation handgrip (B7 spec: backend action): confirmed
     * → active with the CUSTOMER role (level 15 — deliberately NOT `member`:
     * that level carries meaning elsewhere, e.g. DMS role-ACEs, and stays with
     * backend-assigned Mitglieder), the project hook runs INSIDE the account
     * transition (MemberAccounts::activate — a failing hook leaves the account
     * 'confirmed' and this method rethrows; the caller reports). On success
     * the «Sie sind freigeschaltet» mail goes out; a mail failure does not
     * undo the activation (the account IS active — the operator sees the
     * state, writing again is a human decision).
     */
    public function activate(MemberAccount $account, string $loginUrl, ?int $now = null): void
    {
        $this->accounts->activate($account, [AuthRole::CUSTOMER], $this->activationHook, $now);

        ($this->sendMail)(
            (new EmailMessage())
                ->to($account->getEmail())
                ->subject('Ihr Zugang ist freigeschaltet')
                ->template('emails/activated', 'Z77\\Module\\Member', [
                    'account'  => $account,
                    'loginUrl' => $loginUrl,
                ])
        );
    }

    /**
     * The operator's rejection handgrip: the account disappears, NO automatic
     * mail (B7 spec: what we write, we write ourselves).
     */
    public function reject(MemberAccount $account): void
    {
        $this->accounts->delete($account);
    }

    // ── the mails ──────────────────────────────────────────────────────────

    /**
     * The confirmation mail as a buildable piece (B8 needs it: a login
     * request for a never-confirmed account answers with a fresh
     * confirmation link, not a login link — spec table «Kontolage»).
     * Issues a fresh confirm token (devaluing open ones) like every path.
     */
    public function confirmMailFor(MemberAccount $account, ?int $now = null): EmailMessage
    {
        return $this->confirmMail($account, $now ?? time());
    }

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
