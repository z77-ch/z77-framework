<?php

namespace Z77\Module\Member\Services;

use Z77\Core\DI;
use Z77\Module\Member\Entities\MemberAccount;
use Z77\Module\Member\Entities\MemberGrant;
use Z77\Module\Member\Entities\MemberToken;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Mail\EmailMessage;

/**
 * Several accounts on one project reference (B7 v1.1.0, ADR
 * `konto-einladung`): the way in is always an INVITATION, and it always comes
 * from the MASTER — the first account of the reference, the one from the
 * registration an operator activated.
 *
 * One method per station, so the controllers stay thin:
 *
 *   invite()   the master types an address: throttle per reference and day,
 *              refuse an address that already hangs on THIS reference (see
 *              below), otherwise a token bound to reference AND address, and
 *              the mail.
 *   redeem()   the invited person confirms. TWO outcomes, decided by the store
 *              at that moment: an UNKNOWN address gets an account, born
 *              `confirmed` (the invitation WAS the verification) with the
 *              reference already set; a KNOWN address gets a GRANT instead —
 *              the existing account may additionally work for the reference
 *              (ADR-037). Both wait for our activation.
 *   decline()  the known address says no: the link dies, nothing is attached.
 *   revoke()   the master withdraws an open invitation.
 *   pause() / unpause() / remove()   the master manages who may work — the
 *              invited accounts of his reference AND the grants on it, through
 *              the same handgrips.
 *
 * ⚠️ **One e-mail = one account.** That rule stands, and it is what a grant
 * exists for: since 2026-09-06 a known address is no longer refused outright —
 * the refusal was the SIGNAL that one human works for two references, the
 * signal fired (2026-09-01), and the apparatus is this. What remains
 * forbidden is a second account to the same address, under whatever pretext.
 * `ALREADY_TAKEN` now means exactly what the master reads: «gehört schon
 * dazu» — the address hangs on HIS reference already, as its home or by a
 * grant. About any OTHER reference he learns nothing (the anti-oracle
 * exception of the ADR is narrower than before, not wider).
 *
 * ⚠️ **A grant confers no ownership.** Inviting, pausing and removing follow
 * the HOME (`tenantRefOf()` reads the account, never the session's choice) —
 * otherwise a guest fetches somebody the master cannot get rid of, and
 * ownership of the reference dilutes exactly where it counts. Every method
 * here therefore checks the master itself — a controller guard alone would be
 * one forgotten route away from nothing.
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

    // Outcomes — deliberately not booleans: «refused because the address
    // already belongs here» is a MESSAGE the master reads, not an error, and
    // the UI has to be able to tell it apart from a throttle or a lost mail.
    public const SENT          = 'sent';
    public const ALREADY_TAKEN = 'already-taken';
    public const THROTTLED     = 'throttled';
    public const INVALID       = 'invalid';
    public const DENIED        = 'denied';

    // Outcomes of redeem(): an account was born, a grant was attached, or nothing.
    public const REDEEMED = 'redeemed';
    public const GRANTED  = 'granted';
    public const DEAD     = 'dead';

    // What remove() removed — the flash has to say which, because one deletes
    // a person and the other only a permission.
    public const REMOVED_ACCOUNT = 'account';
    public const REMOVED_GRANT   = 'grant';

    /**
     * @param \Closure(EmailMessage): bool         $sendMail
     * @param \Closure(MemberAccount, array): bool $notifyUs operator notification —
     *        the redemption is a registration like any other, and someone has to
     *        activate it (B7 decision 8). The second argument carries what makes
     *        THIS case different: which reference the account attaches to, who
     *        invited, and (`grant`) whether it is a grant on an existing account
     *        rather than a new one. Without it the operator reads «Firma —» and
     *        cannot tell an invitation from a fresh registration.
     * @param string $inviteUrl absolute URL of the redemption form; the token is appended
     * @param \Closure(string): string $tenantLabel resolves a project reference to
     *        a readable name for the mail. The module knows no tenants — the
     *        project hands this in (memberConfig `tenantLabelHook`).
     */
    public function __construct(
        private MemberAccounts $accounts,
        private TokenService $tokens,
        private MemberGrants $grants,
        private MemberThrottle $throttle,
        private \Closure $sendMail,
        private \Closure $notifyUs,
        private string $inviteUrl,
        private \Closure $tenantLabel,
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

        return new self(
            new MemberAccounts($uem),
            new TokenService($uem),
            new MemberGrants($uem),
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
            (int)$config->get('invitesPerTenantPerDay', self::INVITES_PER_DAY),
        );
    }

    /**
     * May this account invite, pause and remove? The one question the surface
     * asks — the section «Zugänge» exists for it, and the routes answer 404
     * without it («not present, not forbidden», B10 v1.6.0).
     *
     * It is deliberately the same predicate every method below uses, so a
     * surface that forgets to ask cannot grant anything the flow refuses.
     */
    public function mayManage(MemberAccount $account): bool
    {
        return $this->tenantRefOf($account) !== null;
    }

    /** The readable name of a reference — the project's label hook, or the bare reference. */
    public function tenantLabelFor(string $tenantRef): string
    {
        return ($this->tenantLabel)($tenantRef);
    }

    // ── the master's handgrips ─────────────────────────────────────────────

    /**
     * @return self::SENT|self::ALREADY_TAKEN|self::THROTTLED|self::INVALID|self::DENIED
     */
    public function invite(MemberAccount $master, string $email, ?int $now = null): string
    {
        $now       = $now ?? time();
        $tenantRef = $this->tenantRefOf($master);

        if ($tenantRef === null) {
            return self::DENIED;
        }

        $email = MemberAccount::normalizeEmail($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return self::INVALID;
        }

        // Refused only when the address hangs on THIS reference already —
        // its home, or a grant in any state (a paused one is resumed, not
        // re-invited). A known address elsewhere gets the token like an
        // unknown one; what differs is the page behind the link, and that
        // page only the mailbox owner sees. Checked BEFORE the throttle
        // counts: a master who typed a colleague's address by mistake should
        // not burn one of his daily invitations on the message.
        $existing = $this->accounts->findByEmail($email);
        if ($existing !== null && $this->grants->hasTenant($existing, $tenantRef)) {
            return self::ALREADY_TAKEN;
        }

        if (!$this->throttle->allowTenant($tenantRef, $this->invitesPerDay, $now)) {
            return self::THROTTLED;
        }

        $plain = $this->tokens->issueInvite(
            $tenantRef,
            $email,
            (string)$master->getId(),
            self::INVITE_TTL_SECONDS,
            $now
        );

        ($this->sendMail)($this->inviteMail($master, $tenantRef, $email, $plain, $existing !== null));

        return self::SENT;
    }

    /** True when the open invitation belonged to this master's reference and is now withdrawn. */
    public function revoke(MemberAccount $master, int $tokenId, ?int $now = null): bool
    {
        $tenantRef = $this->tenantRefOf($master);

        return $tenantRef !== null && $this->tokens->revokeInvite($tokenId, $tenantRef, $now);
    }

    /**
     * Pause / resume an invited account OR a grant on the master's reference —
     * the id says which (the two id spaces are disjoint by construction,
     * `m-…` and `g-…`). The master is deliberately NOT pausable — not even by
     * himself: a reference without a usable account would only be reachable
     * through us (B7 spec, and the ADR leaves the hand-over to the backend
     * until it is a real case).
     */
    public function pause(MemberAccount $master, string $id, bool $paused, ?int $now = null): bool
    {
        $target = $this->manageable($master, $id);
        if ($target === null) {
            return false;
        }

        if ($target instanceof MemberGrant) {
            $paused ? $this->grants->suspend($target, $now) : $this->grants->unsuspend($target);
        } else {
            $paused ? $this->accounts->suspend($target, $now) : $this->accounts->unsuspend($target);
        }

        return true;
    }

    /**
     * Delete an invited account, or remove a grant. Final — the quiet path is
     * pause(). Returns WHAT was removed, or null when nothing was.
     *
     * ⚠️ A removed grant never deletes the account: the account belongs to
     * another reference and stays there untouched. A removed ACCOUNT takes its
     * own grants with it (MemberAccounts::delete()).
     *
     * @return self::REMOVED_ACCOUNT|self::REMOVED_GRANT|null
     */
    public function remove(MemberAccount $master, string $id): ?string
    {
        $target = $this->manageable($master, $id);
        if ($target === null) {
            return null;
        }

        if ($target instanceof MemberGrant) {
            $this->grants->delete($target);

            return self::REMOVED_GRANT;
        }

        $this->accounts->delete($target);

        return self::REMOVED_ACCOUNT;
    }

    // ── what the section shows ─────────────────────────────────────────────

    /** @return MemberAccount[] every account whose HOME is the master's reference, master first */
    public function accountsOf(MemberAccount $master): array
    {
        $tenantRef = $this->tenantRefOf($master);

        return $tenantRef === null ? [] : $this->accounts->findByTenant($tenantRef);
    }

    /**
     * The grants ON the master's reference, each with the account behind it —
     * the second half of «wer für meine Verwaltung arbeiten darf». Kept apart
     * from accountsOf(): the two are different things (a person vs. a
     * permission of a person who lives elsewhere), and a mixed list would make
     * every consumer sort them out again.
     *
     * @return list<array{grant: MemberGrant, account: MemberAccount}>
     */
    public function grantsOf(MemberAccount $master): array
    {
        $tenantRef = $this->tenantRefOf($master);
        if ($tenantRef === null) {
            return [];
        }

        $rows = [];
        foreach ($this->grants->findByTenant($tenantRef) as $grant) {
            $account = $this->accounts->findById($grant->getAccountId());
            if ($account !== null) {
                $rows[] = ['grant' => $grant, 'account' => $account];
            }
        }

        return $rows;
    }

    /** @return MemberToken[] the open invitations of the master's reference */
    public function openInvites(MemberAccount $master, ?int $now = null): array
    {
        $tenantRef = $this->tenantRefOf($master);

        return $tenantRef === null ? [] : $this->tokens->openInvitesFor($tenantRef, $now);
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
     * account, a yes/no for a grant. Only the holder of the link ever sees the
     * answer, and the link went to that very mailbox.
     */
    public function existingAccountFor(MemberToken $token): ?MemberAccount
    {
        $email = (string)$token->getEmail();

        return $email === '' ? null : $this->accounts->findByEmail($email);
    }

    /**
     * The redemption submit. Decided by the store at THIS moment, not by what
     * the page showed: an unknown address gets an account, born `confirmed`
     * with its reference already set; a known address gets a grant on its
     * existing account. Both wait for OUR activation like every other
     * registration (decision 2 unchanged).
     *
     * ⚠️ The address is taken from the TOKEN, never from the form. The form
     * shows it fixed, but a fixed field is a display, not a guarantee; without
     * this the recipient redeems with any address he likes and the reference
     * gets somebody other than the one the master meant.
     *
     * @return array{outcome: string, account: ?MemberAccount, grant: ?MemberGrant}
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
            return $this->redeemAsGrant($token, $existing, (string)$plainToken, $tenantRef, $now);
        }

        $account = $this->accounts->registerFromInvite($email, $firstName, $lastName, $tenantRef, $now);
        if ($account === null) {
            // Raced: the address got its account between lookup and insert.
            // The link is NOT consumed — the next submit takes the grant path.
            return $this->outcome(self::ALREADY_TAKEN);
        }

        // Consume only now: a failure above must leave the link usable.
        $this->tokens->redeemToken((string)$plainToken, MemberToken::PURPOSE_INVITE, $now);

        ($this->notifyUs)($account, $this->notifyContext($token, $tenantRef, null));

        return $this->outcome(self::REDEEMED, $account);
    }

    /**
     * The known address says NO. The link dies with it — nothing is attached,
     * and the master sees the invitation leave his open list the same way an
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

    // ── the operator's handgrips on a grant ────────────────────────────────

    /**
     * confirmed → active, and the person is told — the same sentence the
     * account activation sends, because from the customer's chair it is the
     * same event: «you may work there now». NO activation hook runs (nothing is
     * created), which is exactly why this is not RegistrationFlow::activate().
     */
    public function activateGrant(MemberGrant $grant, string $loginUrl, ?int $now = null): void
    {
        $this->grants->activate($grant, $now);

        $account = $this->accounts->findById($grant->getAccountId());
        if ($account === null) {
            return; // an orphan — the cleanup will take it; nobody to write to
        }

        ($this->sendMail)(
            (new EmailMessage())
                ->to($account->getEmail())
                ->subject('Ihr Zugang zu «' . ($this->tenantLabel)($grant->getTenantRef()) . '» ist freigeschaltet')
                ->template('emails/grant-activated', 'Z77\\Module\\Member', [
                    'account'    => $account,
                    'tenantName' => ($this->tenantLabel)($grant->getTenantRef()),
                    'loginUrl'   => $loginUrl,
                ])
        );
    }

    /** The operator's rejection: the grant disappears, NO automatic mail (B7 spec: what we write, we write ourselves). */
    public function rejectGrant(MemberGrant $grant): void
    {
        $this->grants->delete($grant);
    }

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * The reference a master may act on, or null when this account may not act
     * at all. ⚠️ The HOME, never the session's choice (ADR-037): a grant
     * confers no right to invite, pause or remove.
     */
    private function tenantRefOf(MemberAccount $master): ?string
    {
        $ref = trim((string)$master->getTenantRef());

        return ($master->isMaster() && $master->isActive() && $ref !== '') ? $ref : null;
    }

    /**
     * The target of pause/remove: an account whose HOME is the SAME reference
     * and that is not the master, or a grant ON that reference. Everything
     * else — a foreign reference, an unknown id, the master himself — answers
     * null, and the caller turns that into a 404.
     */
    private function manageable(MemberAccount $master, string $id): MemberAccount|MemberGrant|null
    {
        $tenantRef = $this->tenantRefOf($master);
        if ($tenantRef === null || $id === '' || $id === (string)$master->getId()) {
            return null;
        }

        $account = $this->accounts->findById($id);
        if ($account !== null) {
            return ((string)$account->getTenantRef() === $tenantRef && !$account->isMaster()) ? $account : null;
        }

        $grant = $this->grants->findById($id);

        return ($grant !== null && $grant->getTenantRef() === $tenantRef) ? $grant : null;
    }

    /**
     * The known-address half of redeem(): a grant on the existing account.
     * «Already here» — home or grant, whatever state — consumes the link and
     * answers ALREADY_TAKEN, so a link cannot be sat on either.
     *
     * @return array{outcome: string, account: ?MemberAccount, grant: ?MemberGrant}
     */
    private function redeemAsGrant(
        MemberToken $token,
        MemberAccount $existing,
        string $plainToken,
        string $tenantRef,
        int $now
    ): array {
        if ($this->grants->hasTenant($existing, $tenantRef)) {
            $this->tokens->redeemToken($plainToken, MemberToken::PURPOSE_INVITE, $now);

            return $this->outcome(self::ALREADY_TAKEN);
        }

        $grant = $this->grants->grant($existing, $tenantRef, $token->getInvitedBy(), $now);
        if ($grant === null) {
            $this->tokens->redeemToken($plainToken, MemberToken::PURPOSE_INVITE, $now);

            return $this->outcome(self::ALREADY_TAKEN);
        }

        $this->tokens->redeemToken($plainToken, MemberToken::PURPOSE_INVITE, $now);

        ($this->notifyUs)($existing, $this->notifyContext($token, $tenantRef, $existing));

        return $this->outcome(self::GRANTED, $existing, $grant);
    }

    /**
     * What the operator notification needs beyond the account. Read the
     * inviter off the TOKEN, before it is purged: it is the only place that
     * records who sent this one. Falls back to nothing rather than to a guess
     * — «der Master» would be a claim, not a record.
     *
     * For a grant ($existing given) the mail also names the account's HOME:
     * the operator is about to let a person of one customer work for another,
     * and that is the sentence he has to read before he does.
     *
     * @return array{tenantRef: string, tenantName: string, inviter: string, grant: bool, homeName: string}
     */
    private function notifyContext(MemberToken $token, string $tenantRef, ?MemberAccount $existing): array
    {
        $inviter = $this->accounts->findById((string)$token->getInvitedBy());
        $home    = trim((string)$existing?->getTenantRef());

        return [
            'tenantRef'  => $tenantRef,
            'tenantName' => ($this->tenantLabel)($tenantRef),
            'inviter'    => $inviter === null
                ? ''
                : (trim(($inviter->getFirstName() ?? '') . ' ' . ($inviter->getLastName() ?? ''))
                    ?: (string)$inviter->getEmail()),
            'grant'      => $existing !== null,
            'homeName'   => $home !== '' ? ($this->tenantLabel)($home) : '',
        ];
    }

    /** @return array{outcome: string, account: ?MemberAccount, grant: ?MemberGrant} */
    private function outcome(string $outcome, ?MemberAccount $account = null, ?MemberGrant $grant = null): array
    {
        return ['outcome' => $outcome, 'account' => $account, 'grant' => $grant];
    }

    private function inviteMail(
        MemberAccount $master,
        string $tenantRef,
        string $email,
        string $plain,
        bool $known
    ): EmailMessage {
        $link = $this->inviteUrl . (str_contains($this->inviteUrl, '?') ? '&' : '?')
              . 'invite=' . urlencode($plain);

        $inviter = trim(($master->getFirstName() ?? '') . ' ' . ($master->getLastName() ?? ''));

        return (new EmailMessage())
            ->to($email)
            ->subject('Einladung zur Verwaltung')
            ->template('emails/invite', 'Z77\\Module\\Member', [
                // The recipient has to be able to place this mail: who invited
                // him, and for whom. An invitation without both reads like
                // spam, and a link in a mail that reads like spam is one
                // nobody clicks.
                'tenantName' => ($this->tenantLabel)($tenantRef),
                'inviter'    => $inviter !== '' ? $inviter : $master->getEmail(),
                'inviteUrl'  => $link,
                'validDays'  => (int)round(self::INVITE_TTL_SECONDS / 86400),
                // Whether the page behind the link will ask for a name (new
                // account) or for a yes (grant on the existing one). Told to
                // the RECIPIENT only — the master's outcome is the same either
                // way, and the mailbox owner knows about his own account.
                'known'      => $known,
            ]);
    }
}
