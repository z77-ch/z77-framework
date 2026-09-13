<?php

namespace Z77\Module\Member\Services;

use Z77\Core\DI;
use Z77\Module\Member\Entities\MemberAccount;
use Z77\Module\Member\Entities\MemberToken;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Mail\EmailMessage;

/**
 * The invitation as the module's craft: a token bound to a project reference
 * and an address, the mail that carries it, the page behind the link, and the
 * account that is born when an UNKNOWN address redeems it. What the
 * redemption ATTACHES — the membership — is the project's, through the
 * `joinHook` (ADR-038).
 *
 * Until 2026-09-13 this flow also decided WHO may invite (the master of the
 * home reference), listed the accounts of a reference, paused and removed
 * them, and kept «grants» for a second reference. None of that is a token's
 * business, and all of it needed a reference ON the account — the four fields
 * that made the account profile and tenant in one. The project answers those
 * questions from its own membership list now; this class only ever sees a
 * reference as an opaque string it writes into a token and reads back out.
 *
 * One method per station:
 *
 *   invite()   the inviter names a reference and an address: refuse an address
 *               that belongs to THAT reference already (asked of the project
 *               through the membership hook — a paused membership counts, it
 *               is resumed, not re-invited), throttle per reference and day,
 *               then a token bound to reference AND address, and the mail.
 *   redeem()   the invited person confirms. An UNKNOWN address gets an
 *               account, born `confirmed` (the invitation WAS the
 *               verification); a KNOWN address keeps the one it has. In both
 *               cases the project's joinHook is told «this account joined
 *               that reference, invited by X» — and then both wait for OUR
 *               activation.
 *   decline()  the known address says no: the link dies, nothing is attached.
 *   revoke()   the inviter withdraws an open invitation of the reference.
 *
 * ⚠️ **One e-mail = one account.** Unchanged since B7 v1.1.0: a known address
 * is never given a second account. What it gets is a second MEMBERSHIP, and
 * since ADR-038 that is the project's row, not this module's «grant».
 * `ALREADY_TAKEN` means exactly what the inviter reads: «gehört schon dazu» —
 * the address belongs to THIS reference already, in whatever state. About any
 * OTHER reference the inviter learns nothing.
 *
 * ⚠️ **No rights are checked here.** Whether the inviter may invite for the
 * reference is the project's question to its own list (owner, not agent);
 * the project asks it BEFORE calling invite(), and its controller refuses
 * without it. This flow takes the reference as given — the way TokenService
 * always did.
 */
final class InvitationFlow
{
    /**
     * Spec: as long as the confirmation link — ONE value, not two. Referenced,
     * not copied, so it cannot drift.
     */
    public const INVITE_TTL_SECONDS = RegistrationFlow::CONFIRM_TTL_SECONDS;

    /** Spec default; the module config may raise or lower it. */
    public const INVITES_PER_DAY = MemberThrottle::MAX_INVITES_PER_DAY;

    /**
     * emailConfig route of the operator notification: a project may give the
     * redeemed INVITATION its own subject under `forms.memberConfirmed.routes`
     * without a second form key. Undefined → the ordinary subject, and the mail
     * still goes out (EmailService falls back to the default recipients).
     */
    public const NOTIFY_ROUTE_KEY = 'invite';

    // Outcomes of invite() — deliberately not booleans: «refused because the
    // address already belongs here» is a MESSAGE the inviter reads, not an
    // error, and the UI has to tell it apart from a throttle or a lost mail.
    public const SENT          = 'sent';
    public const ALREADY_TAKEN = 'already-taken';
    public const THROTTLED     = 'throttled';
    public const INVALID       = 'invalid';

    // Outcomes of redeem(): an account was born, an existing one joined, or
    // nothing happened.
    public const REDEEMED = 'redeemed';
    public const JOINED   = 'joined';
    public const DEAD     = 'dead';

    /**
     * @param \Closure(EmailMessage): bool         $sendMail
     * @param \Closure(MemberAccount, array): bool $notifyUs operator notification —
     *        the redemption is a registration like any other, and someone has to
     *        activate it (B7 decision 8). The second argument carries what makes
     *        THIS case different: which reference the account joins, who
     *        invited, and (`existing`) whether the account was there already.
     *        Without it the operator reads «Firma —» and cannot tell an
     *        invitation from a fresh registration.
     * @param string $inviteUrl absolute URL of the redemption form; the token is appended
     * @param \Closure(string): string $tenantLabel resolves a project reference to
     *        a readable name for the mail. The module knows no tenants — the
     *        project hands this in (memberConfig `tenantLabelHook`).
     * @param ?\Closure(MemberAccount, string): bool $holds does this account belong
     *        to the reference already, in any state? (memberConfig
     *        `membershipHook`, wrapped). Null = a project without memberships;
     *        then nobody «already belongs» anywhere.
     * @param ?\Closure(MemberAccount, string, ?string): void $joined the project side
     *        of a redemption: «this account joined that reference, invited by X».
     *        Null = nothing is attached (module standalone).
     */
    public function __construct(
        private MemberAccounts $accounts,
        private TokenService $tokens,
        private MemberThrottle $throttle,
        private \Closure $sendMail,
        private \Closure $notifyUs,
        private string $inviteUrl,
        private \Closure $tenantLabel,
        private ?\Closure $holds = null,
        private ?\Closure $joined = null,
        private int $invitesPerDay = self::INVITES_PER_DAY,
    ) {
    }

    /** Production wiring — mirrors RegistrationFlow::create(). */
    public static function create(string $inviteUrl): self
    {
        $uem    = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
        $config = DI::getConfigManager()
            ->getArrayConfig('App/Config/memberConfig', 'Z77\\Module\\Member');

        $labelFqcn = (string)$config->get('tenantLabelHook', '');
        $joinFqcn  = (string)$config->get('joinHook', '');
        $choice    = new TenantChoice(null, TenantChoice::hookFromConfig());

        return new self(
            new MemberAccounts($uem),
            new TokenService($uem),
            new MemberThrottle(MemberThrottle::defaultDir()),
            static fn(EmailMessage $mail): bool => DI::getEmailService()->send($mail),
            static function (MemberAccount $account, array $invite): bool {
                try {
                    // Same form key as an ordinary registration — one place
                    // configures who gets told that something waits. The
                    // routeKey `invite` lets a project give this case its own
                    // subject; a project that defines none keeps the ordinary
                    // one, and the body says the rest.
                    return DI::getEmailService()->sendForm(
                        RegistrationFlow::NOTIFY_FORM_KEY,
                        [
                            'account' => $account,
                            'invite'  => $invite,
                            // Same project seam as the ordinary registration —
                            // an invited person can have brought something
                            // along too, and two cases of one mail must not
                            // drift apart.
                            'notifyRows' => RegistrationFlow::projectNotifyRows($account),
                        ],
                        null,
                        self::NOTIFY_ROUTE_KEY
                    );
                } catch (\Throwable) {
                    return false; // form key not configured — the notification is opt-in
                }
            },
            $inviteUrl,
            $labelFqcn !== ''
                ? static fn(string $ref): string => (string)(new $labelFqcn())($ref)
                : static fn(string $ref): string => $ref,
            static fn(MemberAccount $account, string $ref): bool => $choice->holds($account, $ref),
            $joinFqcn !== '' && class_exists($joinFqcn)
                ? static function (MemberAccount $account, string $ref, ?string $invitedBy): void {
                    (new $joinFqcn())($account, $ref, $invitedBy);
                }
                : null,
            (int)$config->get('invitesPerTenantPerDay', self::INVITES_PER_DAY),
        );
    }

    /** The readable name of a reference — the project's label hook, or the bare reference. */
    public function tenantLabelFor(string $tenantRef): string
    {
        return ($this->tenantLabel)($tenantRef);
    }

    // ── the inviter's handgrips ────────────────────────────────────────────

    /**
     * @param MemberAccount $inviter who invites — named in the mail and kept on
     *        the token; its RIGHT to invite for $tenantRef is the caller's to
     *        check, not this flow's
     * @return self::SENT|self::ALREADY_TAKEN|self::THROTTLED|self::INVALID
     */
    public function invite(MemberAccount $inviter, string $tenantRef, string $email, ?int $now = null): string
    {
        $now       = $now ?? time();
        $tenantRef = trim($tenantRef);
        if ($tenantRef === '') {
            return self::INVALID;
        }

        $email = MemberAccount::normalizeEmail($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return self::INVALID;
        }

        // Refused only when the address belongs to THIS reference already, in
        // any state (a paused membership is resumed, not re-invited). A known
        // address elsewhere gets the token like an unknown one; what differs
        // is the page behind the link, and that page only the mailbox owner
        // sees. Checked BEFORE the throttle counts: an inviter who typed a
        // colleague's address by mistake should not burn one of his daily
        // invitations on the message.
        $existing = $this->accounts->findByEmail($email);
        if ($existing !== null && $this->holds !== null && ($this->holds)($existing, $tenantRef)) {
            return self::ALREADY_TAKEN;
        }

        if (!$this->throttle->allowTenant($tenantRef, $this->invitesPerDay, $now)) {
            return self::THROTTLED;
        }

        $plain = $this->tokens->issueInvite(
            $tenantRef,
            $email,
            (string)$inviter->getId(),
            self::INVITE_TTL_SECONDS,
            $now
        );

        ($this->sendMail)($this->inviteMail($inviter, $tenantRef, $email, $plain, $existing !== null));

        return self::SENT;
    }

    /** True when the open invitation belonged to the reference and is now withdrawn. */
    public function revoke(string $tenantRef, int $tokenId, ?int $now = null): bool
    {
        $tenantRef = trim($tenantRef);

        return $tenantRef !== '' && $this->tokens->revokeInvite($tokenId, $tenantRef, $now);
    }

    /** @return MemberToken[] the open invitations of a reference */
    public function openInvites(string $tenantRef, ?int $now = null): array
    {
        $tenantRef = trim($tenantRef);

        return $tenantRef === '' ? [] : $this->tokens->openInvitesFor($tenantRef, $now);
    }

    // ── the invited person's side ──────────────────────────────────────────

    /**
     * The LIVE invitation behind a link, without consuming it — the form needs
     * the fixed address before anything is decided. Null for unknown, used,
     * revoked or expired: the recipient sees one page for all four, because
     * whether it was withdrawn or ran out is none of his business.
     */
    public function inspect(?string $plainToken, ?int $now = null): ?MemberToken
    {
        $plainToken = trim((string)$plainToken);

        return $plainToken === ''
            ? null
            : $this->tokens->inspect($plainToken, MemberToken::PURPOSE_INVITE, $now);
    }

    /**
     * The account behind an invitation's address, if there is one — this is
     * what decides the SHAPE of the redemption page: a name form for a new
     * account, a yes/no for joining with the existing one. Only the holder of
     * the link ever sees the answer, and the link went to that very mailbox.
     */
    public function existingAccountFor(MemberToken $token): ?MemberAccount
    {
        $email = (string)$token->getEmail();

        return $email === '' ? null : $this->accounts->findByEmail($email);
    }

    /**
     * The redemption submit. Decided by the store at THIS moment, not by what
     * the page showed: an unknown address gets an account, born `confirmed`; a
     * known address keeps its own. Then the project is told that the account
     * joined the reference — and both wait for OUR activation like every other
     * registration (decision 2 unchanged).
     *
     * ⚠️ The address is taken from the TOKEN, never from the form. The form
     * shows it fixed, but a fixed field is a display, not a guarantee; without
     * this the recipient redeems with any address he likes and the reference
     * gets somebody other than the one the inviter meant.
     *
     * @return array{outcome: string, account: ?MemberAccount}
     */
    public function redeem(
        ?string $plainToken,
        ?string $firstName,
        ?string $lastName,
        ?int $now = null
    ): array {
        $now   = $now ?? time();
        $token = $this->inspect($plainToken, $now);

        if ($token === null) {
            return $this->outcome(self::DEAD);
        }

        $email     = (string)$token->getEmail();
        $tenantRef = (string)$token->getTenantRef();
        if ($email === '' || $tenantRef === '') {
            return $this->outcome(self::DEAD); // malformed row — not redeemable
        }

        $existing = $this->accounts->findByEmail($email);
        if ($existing !== null) {
            return $this->redeemAsJoin($token, $existing, (string)$plainToken, $tenantRef, $now);
        }

        $account = $this->accounts->registerFromInvite($email, $firstName, $lastName, $now);
        if ($account === null) {
            // Raced: the address got its account between lookup and insert.
            // The link is NOT consumed — the next submit takes the join path.
            return $this->outcome(self::ALREADY_TAKEN);
        }

        // Consume only now: a failure above must leave the link usable.
        $this->tokens->redeemToken((string)$plainToken, MemberToken::PURPOSE_INVITE, $now);

        // The project attaches — AFTER the account exists, so the hook can
        // reference it, and after the token is consumed, so a hook that throws
        // cannot leave a redeemable link behind an account that is half there.
        $this->tellJoined($account, $tenantRef, $token->getInvitedBy());

        ($this->notifyUs)($account, $this->notifyContext($token, $tenantRef, false));

        return $this->outcome(self::REDEEMED, $account);
    }

    /**
     * The known address says NO. The link dies with it — nothing is attached,
     * and the inviter sees the invitation leave his open list the same way an
     * expired one does. A decline by mistake is a new invitation, deliberately:
     * a link that survives its own refusal is a link somebody can sit on.
     */
    public function decline(?string $plainToken, ?int $now = null): bool
    {
        $now = $now ?? time();
        if ($this->inspect($plainToken, $now) === null) {
            return false;
        }

        return $this->tokens->redeemToken((string)$plainToken, MemberToken::PURPOSE_INVITE, $now) !== null;
    }

    // ── what the project sends when it activates a join ────────────────────

    /**
     * «Ihr Zugang zu X ist freigeschaltet» — the mail for an EXISTING account
     * that may now work for an additional reference. The project activates the
     * membership (its row, its decision) and calls this for the sentence; the
     * module keeps the template because a mail is its craft. Same event from
     * the customer's chair as «Sie sind freigeschaltet», with one difference
     * worth a sentence: he signs in as always and CHOOSES the reference in the
     * header. Rejection sends NO automatic mail, as with accounts.
     */
    public function sendJoinActivated(MemberAccount $account, string $tenantRef, string $loginUrl): void
    {
        ($this->sendMail)(
            (new EmailMessage())
                ->to($account->getEmail())
                ->subject('Ihr Zugang zu «' . ($this->tenantLabel)($tenantRef) . '» ist freigeschaltet')
                ->template('emails/join-activated', 'Z77\\Module\\Member', [
                    'account'    => $account,
                    'tenantName' => ($this->tenantLabel)($tenantRef),
                    'loginUrl'   => $loginUrl,
                ])
        );
    }

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * The known-address half of redeem(): the existing account joins. «Already
     * here» — whatever state — consumes the link and answers ALREADY_TAKEN, so
     * a link cannot be sat on either.
     *
     * @return array{outcome: string, account: ?MemberAccount}
     */
    private function redeemAsJoin(
        MemberToken $token,
        MemberAccount $existing,
        string $plainToken,
        string $tenantRef,
        int $now
    ): array {
        if ($this->holds !== null && ($this->holds)($existing, $tenantRef)) {
            $this->tokens->redeemToken($plainToken, MemberToken::PURPOSE_INVITE, $now);

            return $this->outcome(self::ALREADY_TAKEN);
        }

        $this->tokens->redeemToken($plainToken, MemberToken::PURPOSE_INVITE, $now);
        $this->tellJoined($existing, $tenantRef, $token->getInvitedBy());

        ($this->notifyUs)($existing, $this->notifyContext($token, $tenantRef, true));

        return $this->outcome(self::JOINED, $existing);
    }

    private function tellJoined(MemberAccount $account, string $tenantRef, ?string $invitedBy): void
    {
        if ($this->joined !== null) {
            ($this->joined)($account, $tenantRef, $invitedBy !== null && $invitedBy !== '' ? $invitedBy : null);
        }
    }

    /** @return array{tenantRef:string, tenantName:string, inviter:string, existing:bool} */
    private function notifyContext(MemberToken $token, string $tenantRef, bool $existing): array
    {
        $inviter = $this->accounts->findById((string)$token->getInvitedBy());

        return [
            'tenantRef'  => $tenantRef,
            'tenantName' => ($this->tenantLabel)($tenantRef),
            'inviter'    => $inviter === null
                ? ''
                : (trim(($inviter->getFirstName() ?? '') . ' ' . ($inviter->getLastName() ?? ''))
                    ?: (string)$inviter->getEmail()),
            'existing'   => $existing,
        ];
    }

    /** @return array{outcome: string, account: ?MemberAccount} */
    private function outcome(string $outcome, ?MemberAccount $account = null): array
    {
        return ['outcome' => $outcome, 'account' => $account];
    }

    private function inviteMail(
        MemberAccount $inviter,
        string $tenantRef,
        string $email,
        string $plain,
        bool $known
    ): EmailMessage {
        $link = $this->inviteUrl . (str_contains($this->inviteUrl, '?') ? '&' : '?')
              . 'invite=' . urlencode($plain);

        $name = trim(($inviter->getFirstName() ?? '') . ' ' . ($inviter->getLastName() ?? ''));

        return (new EmailMessage())
            ->to($email)
            ->subject('Einladung zur Verwaltung')
            ->template('emails/invite', 'Z77\\Module\\Member', [
                // The recipient has to be able to place this mail: who invited
                // him, and for whom. An invitation without both reads like
                // spam, and a link in a mail that reads like spam is one
                // nobody clicks.
                'tenantName' => ($this->tenantLabel)($tenantRef),
                'inviter'    => $name !== '' ? $name : $inviter->getEmail(),
                'inviteUrl'  => $link,
                'validDays'  => (int)round(self::INVITE_TTL_SECONDS / 86400),
                // Whether the page behind the link will ask for a name (new
                // account) or for a yes (joining with the existing one). Told
                // to the RECIPIENT only — the inviter's outcome is the same
                // either way, and the mailbox owner knows about his own account.
                'known'      => $known,
            ]);
    }
}
