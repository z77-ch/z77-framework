<?php

namespace Z77\Module\Member\Services;

use Z77\Core\Config\AuthRole;
use Z77\Core\DI;
use Z77\Module\Member\Entities\MemberAccount;
use Z77\Module\Member\Entities\MemberToken;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\GeoIp\CountryLookup;
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

    /** Registration attempts per origin (IPv4 address / IPv6 /64) and hour. */
    public const DEFAULT_PER_IP = 10;

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
            new MemberThrottle(MemberThrottle::defaultDir()),
            static fn(EmailMessage $mail): bool => DI::getEmailService()->send($mail),
            static function (MemberAccount $account): bool {
                try {
                    return DI::getEmailService()->sendForm(self::NOTIFY_FORM_KEY, [
                        'account'    => $account,
                        'notifyRows' => self::projectNotifyRows($account),
                    ]);
                } catch (\Throwable) {
                    return false; // form key not configured — the notification is opt-in
                }
            },
            $confirmUrl,
            $fqcn !== '' ? static fn(MemberAccount $a): ?string => (new $fqcn())($a) : null,
        );
    }

    /**
     * memberConfig `registrationsPerHourPerIp`, default
     * {@see self::DEFAULT_PER_IP}.
     *
     * ⚠️ Deliberately generous. One IPv4 address can be a whole office, a
     * co-working space or a mobile carrier's NAT — a tight limit here does not
     * stop a determined script (it rents another address for a franc) but it
     * does lock out a real customer whose neighbour registered first. This
     * catches floods, not people.
     */
    public static function registrationsPerHourPerIp(): int
    {
        $configured = (int) (DI::getConfigManager()
            ->getArrayConfig('App/Config/memberConfig', 'Z77\Module\Member')
            ->get('registrationsPerHourPerIp', self::DEFAULT_PER_IP));

        return $configured > 0 ? $configured : self::DEFAULT_PER_IP;
    }

    /**
     * memberConfig `blockedCountries` — ISO 3166-1 alpha-2 codes whose
     * registrations are refused. **Empty by default: the rule is off until an
     * installation switches it on**, and it is switched on from what the
     * registration log actually shows, never from a hunch.
     *
     * ⚠️ A BLOCKLIST, never a whitelist. A whitelist locks out the Swiss
     * customer sitting in a holiday WLAN, the one on a VPN and the one whose
     * carrier routes through Frankfurt — all of them real, none of them
     * visible to us as «CH». A blocklist can only ever be too small, which
     * costs an attempt we would have had anyway; a whitelist can be too small
     * in a way that costs a customer.
     *
     * @return list<string> upper-case, two letters, duplicates removed
     */
    public static function blockedCountries(): array
    {
        try {
            $configured = DI::getConfigManager()
                ->getArrayConfig('App/Config/memberConfig', 'Z77\Module\Member')
                ->get('blockedCountries', []);
        } catch (\Throwable) {
            // ⚠️ An unreadable config means the rule is OFF, not that everyone
            // is barred. Same stance as projectNotifyRows() above and as the
            // whole GeoIP layer: an optional extra must never be the reason a
            // customer cannot sign up. Failing open is the deliberate choice —
            // this rule limits abuse, it does not guard anything secret.
            return [];
        }

        if (!is_array($configured)) {
            return [];
        }

        $codes = [];
        foreach ($configured as $code) {
            $code = strtoupper(trim((string) $code));
            if (preg_match('/^[A-Z]{2}$/', $code)) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * True when this origin is barred by the country rule.
     *
     * ⚠️ An UNKNOWN country never blocks. No database installed, a private
     * address, an unassigned range, a broken file — {@see CountryLookup} says
     * null to all of them, and null must mean «carry on». Blocking on «we do
     * not know» would turn a missing optional file into a total registration
     * outage, which is exactly the failure mode the whole GeoIP layer was
     * built to avoid.
     */
    private function blockedByCountry(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        $blocked = self::blockedCountries();
        if ($blocked === []) {
            return false;   // the ordinary case: the rule is off, nothing is looked up
        }

        $country = CountryLookup::of($ip);

        return $country !== null && in_array($country, $blocked, true);
    }

    /**
     * The project's extra lines for the operator notification (memberConfig
     * `notifyRowsHook`). The module knows an account, not what a project hangs
     * on one — so it asks, and prints whatever comes back.
     *
     * ⚠️ Never lets the caller fail. This mail is a courtesy; a hook that
     * throws must not be the reason a confirmation or a redemption breaks.
     *
     * @return array<string,string>
     */
    public static function projectNotifyRows(MemberAccount $account): array
    {
        try {
            $fqcn = (string)DI::getConfigManager()
                ->getArrayConfig('App/Config/memberConfig', 'Z77\\Module\\Member')
                ->get('notifyRowsHook', '');
            if ($fqcn === '' || !class_exists($fqcn)) {
                return [];
            }
            $rows = (new $fqcn())($account);

            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
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
     *
     * $termsVersion is the version of the terms the form had someone tick
     * (MemberAccount::acceptTerms). ⚠️ Like the origin it rides on a NEW
     * account only, and for the same reason: the existing-account branch
     * must stay indistinguishable from the new one, and a write there would
     * be a side effect an outsider could measure.
     */
    public function register(
        string $email,
        ?string $company,
        ?string $firstName,
        ?string $lastName,
        ?int $now = null,
        ?string $origin = null,
        ?string $termsVersion = null
    ): bool {
        $now ??= time();

        // TWO throttles, because either alone has an obvious way around it.
        // The address counter holds someone hammering one address; the origin
        // counter holds someone inventing a fresh address every try, which
        // walks straight past a per-address count. ⚠️ The IP one runs FIRST:
        // it is the cheaper question and it does not need the address, so a
        // flood is stopped before it touches address-specific state.
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

        // The country rule sits ABOVE both throttles: it is a flat refusal
        // rather than a rate, it needs neither the address nor a counter, and
        // a barred origin should not consume a throttle slot on its way out.
        // Off unless an installation configured it.
        //
        // ⚠️ The refusal is VISIBLE (false → the form's generic send error),
        // not the silent fake-success the bot traps use. The difference is who
        // is on the other end: a honeypot only ever catches a script, and a
        // script deserves no signal — a country rule can hit a real customer,
        // and a real customer must be able to notice that something failed and
        // write to us. A silent drop would leave them waiting for a mail that
        // is never coming.
        if ($this->blockedByCountry($ip)) {
            RegistrationLog::note('blocked-country');

            return false;
        }

        if ($ip !== '' && !$this->throttle->allowIp($ip, self::registrationsPerHourPerIp(), $now)) {
            RegistrationLog::note('throttled-ip');

            return false;
        }

        if (!$this->throttle->allow($email, $now)) {
            RegistrationLog::note('throttled');

            return false;
        }

        $existing = $this->accounts->findByEmail($email);
        if ($existing !== null) {
            RegistrationLog::note('known');
            ($this->sendMail)($this->existingAccountMail($existing));

            return true;
        }

        $account = $this->accounts->register($email, $company, $firstName, $lastName, $now, $origin, $termsVersion);
        if ($account === null) {
            // Raced: the address got its account between lookup and insert —
            // same answer as the existing-account branch above.
            $existing = $this->accounts->findByEmail($email);
            if ($existing !== null) {
                RegistrationLog::note('known');
                ($this->sendMail)($this->existingAccountMail($existing));
            }

            return true;
        }
        RegistrationLog::note('new');
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
