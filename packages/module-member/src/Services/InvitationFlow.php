<?php

namespace Z77\Module\Member\Services;

use Z77\Core\DI;
use Z77\Module\Member\Entities\MemberAccount;
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
 *              refuse a known address (see below), otherwise a token bound to
 *              reference AND address, and the mail.
 *   redeem()   the invited person fills in name: the account is born
 *              `confirmed` (the invitation WAS the verification) with the
 *              reference already set — activation still ours, it just creates
 *              no second tenant.
 *   revoke()   the master withdraws an open invitation.
 *   pause() / unpause() / remove()   the master manages who may work.
 *
 * ⚠️ **A known address is REFUSED, and that is the point of the whole ADR.**
 * It is a deliberate exception to the anti-oracle principle (B8): the only one
 * who learns anything is a SIGNED-IN MASTER, about an address he typed
 * himself, inside his own reference. The reason is not convenience — the
 * refusal is the SIGNAL that one human is to work for a second tenant, and
 * that is the trigger for the agency apparatus. A silent workaround (a second
 * account, a plus-address) would make the first such case invisible and we
 * would collect accounts instead of building the thing.
 *
 * ⚠️ **An invited account must never invite.** Otherwise it fetches somebody
 * the master cannot get rid of, and ownership of the reference dilutes exactly
 * where it counts. Every method here therefore checks the master itself —
 * a controller guard alone would be one forgotten route away from nothing.
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

    // Outcomes — deliberately not booleans: «refused because the address is
    // known» is a MESSAGE the master reads, not an error, and the UI has to be
    // able to tell it apart from a throttle or a lost mail.
    public const SENT          = 'sent';
    public const ALREADY_TAKEN = 'already-taken';
    public const THROTTLED     = 'throttled';
    public const INVALID       = 'invalid';
    public const DENIED        = 'denied';

    // Outcomes of redeem()
    public const REDEEMED = 'redeemed';
    public const DEAD     = 'dead';

    /**
     * @param \Closure(EmailMessage): bool  $sendMail
     * @param \Closure(MemberAccount): bool $notifyUs operator notification — the
     *        redemption is a registration like any other, and someone has to
     *        activate it (B7 decision 8)
     * @param string $inviteUrl absolute URL of the redemption form; the token is appended
     * @param \Closure(string): string $tenantLabel resolves a project reference to
     *        a readable name for the mail. The module knows no tenants — the
     *        project hands this in (memberConfig `tenantLabelHook`).
     */
    public function __construct(
        private MemberAccounts $accounts,
        private TokenService $tokens,
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
            new MemberThrottle(
                rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/data/framework/member/throttle'
            ),
            static fn(EmailMessage $mail): bool => DI::getEmailService()->send($mail),
            static function (MemberAccount $account): bool {
                try {
                    return DI::getEmailService()
                        ->sendForm(RegistrationFlow::NOTIFY_FORM_KEY, ['account' => $account]);
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
     * asks — the section «Zugänge» exists for it, and the four routes answer
     * 404 without it («not present, not forbidden», B10 v1.6.0).
     *
     * It is deliberately the same predicate every method below uses, so a
     * surface that forgets to ask cannot grant anything the flow refuses.
     */
    public function mayManage(MemberAccount $account): bool
    {
        return $this->tenantRefOf($account) !== null;
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

        // One e-mail = one account = one reference. Checked BEFORE the throttle
        // counts: a master who typed a colleague's address by mistake should
        // not burn one of his ten daily invitations on the message.
        if ($this->accounts->findByEmail($email) !== null) {
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

        ($this->sendMail)($this->inviteMail($master, $tenantRef, $email, $plain));

        return self::SENT;
    }

    /** True when the open invitation belonged to this master's reference and is now withdrawn. */
    public function revoke(MemberAccount $master, int $tokenId, ?int $now = null): bool
    {
        $tenantRef = $this->tenantRefOf($master);

        return $tenantRef !== null && $this->tokens->revokeInvite($tokenId, $tenantRef, $now);
    }

    /**
     * Pause / resume an invited account. The master is deliberately NOT
     * pausable — not even by himself: a reference without a usable account
     * would only be reachable through us (B7 spec, and the ADR leaves the
     * hand-over to the backend until it is a real case).
     */
    public function pause(MemberAccount $master, string $accountId, bool $paused, ?int $now = null): bool
    {
        $target = $this->manageable($master, $accountId);
        if ($target === null) {
            return false;
        }

        $paused ? $this->accounts->suspend($target, $now) : $this->accounts->unsuspend($target);

        return true;
    }

    /** Delete an invited account. Final — the quiet path is pause(). */
    public function remove(MemberAccount $master, string $accountId): bool
    {
        $target = $this->manageable($master, $accountId);
        if ($target === null) {
            return false;
        }

        $this->accounts->delete($target);

        return true;
    }

    // ── what the section shows ─────────────────────────────────────────────

    /** @return MemberAccount[] every account on the master's reference, master first */
    public function accountsOf(MemberAccount $master): array
    {
        $tenantRef = $this->tenantRefOf($master);

        return $tenantRef === null ? [] : $this->accounts->findByTenant($tenantRef);
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
     * The redemption submit. The account is born `confirmed` with its
     * reference already set — and it waits for OUR activation like every other
     * registration (decision 2 unchanged).
     *
     * ⚠️ The address is taken from the TOKEN, never from the form. The form
     * shows it fixed, but a fixed field is a display, not a guarantee; without
     * this the recipient redeems with any address he likes and the reference
     * gets somebody other than the one the master meant.
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
            return ['outcome' => self::DEAD, 'account' => null];
        }

        $email     = (string)$token->getEmail();
        $tenantRef = (string)$token->getTenantRef();
        if ($email === '' || $tenantRef === '') {
            return ['outcome' => self::DEAD, 'account' => null]; // malformed row — not redeemable
        }

        // The address got an account between invitation and click. No second
        // account, ever — and the token dies with the attempt so the link
        // cannot be sat on.
        if ($this->accounts->findByEmail($email) !== null) {
            $this->tokens->redeemToken((string)$plainToken, MemberToken::PURPOSE_INVITE, $now);

            return ['outcome' => self::ALREADY_TAKEN, 'account' => null];
        }

        $account = $this->accounts->registerFromInvite($email, $firstName, $lastName, $tenantRef, $now);
        if ($account === null) {
            return ['outcome' => self::ALREADY_TAKEN, 'account' => null];
        }

        // Consume only now: a failure above must leave the link usable.
        $this->tokens->redeemToken((string)$plainToken, MemberToken::PURPOSE_INVITE, $now);

        ($this->notifyUs)($account);

        return ['outcome' => self::REDEEMED, 'account' => $account];
    }

    // ── internals ──────────────────────────────────────────────────────────

    /** The reference a master may act on, or null when this account may not act at all. */
    private function tenantRefOf(MemberAccount $master): ?string
    {
        $ref = trim((string)$master->getTenantRef());

        return ($master->isMaster() && $master->isActive() && $ref !== '') ? $ref : null;
    }

    /**
     * The target of pause/remove: an account of the SAME reference that is not
     * the master. Everything else — a foreign reference, an unknown id, the
     * master himself — answers null, and the caller turns that into a 404.
     */
    private function manageable(MemberAccount $master, string $accountId): ?MemberAccount
    {
        $tenantRef = $this->tenantRefOf($master);
        if ($tenantRef === null || $accountId === '' || $accountId === (string)$master->getId()) {
            return null;
        }

        $target = $this->accounts->findById($accountId);
        if ($target === null
            || (string)$target->getTenantRef() !== $tenantRef
            || $target->isMaster()
        ) {
            return null;
        }

        return $target;
    }

    private function inviteMail(
        MemberAccount $master,
        string $tenantRef,
        string $email,
        string $plain
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
            ]);
    }
}
