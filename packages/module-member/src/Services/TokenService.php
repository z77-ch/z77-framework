<?php

namespace Z77\Module\Member\Services;

use Z77\Module\Member\Entities\MemberAccount;
use Z77\Module\Member\Entities\MemberToken;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The one token mechanism of the member module (B7 spec, shared with B8):
 * issue() hands out a high-entropy plaintext exactly once — for the mail —
 * and stores only its SHA-256 hash, time-limited. redeem() is the single
 * consumer: right purpose, unused, unrevoked, unexpired, then marked used
 * atomically with the check's outcome.
 *
 * Issuing invalidates the account's previous open tokens of the same purpose
 * (spec: a resend devalues the old link) by deleting them — dead rows carry
 * no information worth keeping, and the used ones stay as redeem evidence.
 *
 * ⚠️ INVITATIONS (B7 v1.1.0) are the one kind with no account, so they need
 * their own issuing method: the "previous open tokens" they devalue are keyed
 * by project reference + address, not by account. That is also why issue()
 * did not simply grow a fourth parameter — the two identities have nothing in
 * common but the store.
 */
final class TokenService
{
    public function __construct(private UnifiedEntityManager $uem)
    {
    }

    /**
     * New token for the account: returns the PLAINTEXT — hand it to the mail
     * and forget it. Previous open tokens of the same account+purpose die now.
     *
     * $remember rides along on login tokens only (B8 «angemeldet bleiben»):
     * the wish belongs to the link, because the device that redeems it need
     * not be the one that asked.
     */
    public function issue(
        string $accountRef,
        string $purpose,
        int $ttlSeconds,
        ?int $now = null,
        bool $remember = false
    ): string {
        $now ??= time();

        foreach ($this->openTokens($accountRef, $purpose) as $old) {
            $this->uem->remove($old);
        }

        $plain = bin2hex(random_bytes(32));

        $token = new MemberToken();
        $token->setTokenHash(hash('sha256', $plain));
        $token->setAccountRef($accountRef);
        $token->setPurpose($purpose);
        $token->setValidUntil(date(DATE_ATOM, $now + $ttlSeconds));
        $token->setRemember($remember);

        $this->uem->persist($token);
        $this->uem->flush();

        return $plain;
    }

    /**
     * An invitation to a project reference (B7 v1.1.0): no account yet, so the
     * token carries the reference and the invited ADDRESS instead. Returns the
     * PLAINTEXT for the mail.
     *
     * A second invitation to the same address at the same reference devalues
     * the first — the master re-inviting someone means «this link, not that
     * one», exactly like a resend.
     */
    public function issueInvite(
        string $tenantRef,
        string $email,
        string $invitedBy,
        int $ttlSeconds,
        ?int $now = null
    ): string {
        $now ??= time();

        foreach ($this->openInvites($tenantRef, $email) as $old) {
            $this->uem->remove($old);
        }

        $plain = bin2hex(random_bytes(32));

        $token = new MemberToken();
        $token->setTokenHash(hash('sha256', $plain));
        $token->setAccountRef(null);
        $token->setPurpose(MemberToken::PURPOSE_INVITE);
        $token->setTenantRef($tenantRef);
        $token->setEmail($email);
        $token->setInvitedBy($invitedBy);
        $token->setValidUntil(date(DATE_ATOM, $now + $ttlSeconds));

        $this->uem->persist($token);
        $this->uem->flush();

        return $plain;
    }

    /**
     * The master withdraws an invitation. Revoking instead of deleting keeps
     * the row long enough for the recipient's click to land on the same page
     * an expired link produces — a deleted row would be «unknown», which is
     * the same page again, but then the daily purge is what removes it and
     * nothing here has to guess.
     *
     * Returns false when the id is not an open invitation of THIS reference —
     * the caller must not be able to revoke a foreign tenant's invitation by
     * guessing an id.
     */
    public function revokeInvite(int $tokenId, string $tenantRef, ?int $now = null): bool
    {
        $now ??= time();

        foreach ($this->openInvitesFor($tenantRef, $now) as $token) {
            if ($token->getId() === $tokenId) {
                $token->setRevokedAt(date(DATE_ATOM, $now));
                $this->uem->persist($token);
                $this->uem->flush();

                return true;
            }
        }

        return false;
    }

    /**
     * The open invitations of one project reference, for the master's list.
     *
     * @return MemberToken[]
     */
    public function openInvitesFor(string $tenantRef, ?int $now = null): array
    {
        $now ??= time();

        $tokens = $this->repository()->findBy([
            'purpose'    => MemberToken::PURPOSE_INVITE,
            'tenant_ref' => $tenantRef,
        ]);

        return array_values(array_filter(
            $tokens,
            static fn(MemberToken $t): bool => !$t->isDead($now)
        ));
    }

    /**
     * Every invitation of one project reference is gone — open, revoked,
     * expired or already used alike. This is the deletion side of a project
     * reference disappearing (B7 v1.1.0): an invite token carries the invited
     * ADDRESS, so leaving the dead ones behind would leave personal data in the
     * token store of a customer who asked to be removed.
     *
     * The counterpart of {@see openInvitesFor()}, which counts only what is
     * still open — a dialog names what a human would call an invitation, the
     * deletion takes the remains with it.
     *
     * @return int number of deleted tokens
     */
    public function deleteInvitesFor(string $tenantRef): int
    {
        $tokens = $this->repository()->findBy([
            'purpose'    => MemberToken::PURPOSE_INVITE,
            'tenant_ref' => $tenantRef,
        ]);

        $deleted = 0;
        foreach ($tokens as $token) {
            if (!$token instanceof MemberToken) {
                continue;
            }
            $this->uem->remove($token);
            $deleted++;
        }
        if ($deleted > 0) {
            $this->uem->flush();
        }

        return $deleted;
    }

    /**
     * Redeems a plaintext token: returns the account id, or null when the
     * token is unknown, wrong-purpose, already used, revoked or expired — the
     * caller shows the resend page in every null case (the spec's one answer
     * for dead links; "already confirmed" is an account-state question, not a
     * token question).
     */
    public function redeem(string $plain, string $purpose, ?int $now = null): ?string
    {
        return $this->redeemToken($plain, $purpose, $now)?->getAccountRef();
    }

    /**
     * Same redemption, but hands back the token itself — for callers that need
     * more than the account id (B8: the link's «angemeldet bleiben» flag).
     */
    public function redeemToken(string $plain, string $purpose, ?int $now = null): ?MemberToken
    {
        $now ??= time();
        $hash = hash('sha256', $plain);

        $token = $this->repository()->findOneBy(['token_hash' => $hash]);
        if (!$token instanceof MemberToken
            || $token->getPurpose() !== $purpose
            || $token->isDead($now)
        ) {
            return null;
        }

        $token->setUsedAt(date(DATE_ATOM, $now));
        $this->uem->persist($token);
        $this->uem->flush();

        return $token;
    }

    /**
     * The LIVE token behind a plaintext — right purpose, unused, unexpired —
     * WITHOUT redeeming it. The B8 confirmation page needs this: it shows the
     * context of a link the visitor has not decided about yet, and only the
     * button press may consume it.
     */
    public function inspect(string $plain, string $purpose, ?int $now = null): ?MemberToken
    {
        $now ??= time();

        $token = $this->repository()->findOneBy(['token_hash' => hash('sha256', $plain)]);

        return $token instanceof MemberToken
            && $token->getPurpose() === $purpose
            && !$token->isDead($now)
                ? $token
                : null;
    }

    /**
     * Account id a plaintext belongs to — regardless of used/expired, WITHOUT
     * redeeming. The confirm flow needs this to tell "link already used and
     * the account is confirmed" (→ «bereits bestätigt») apart from a dead
     * link (→ resend page); redeem() alone would answer null for both.
     */
    public function peek(string $plain, string $purpose): ?string
    {
        $token = $this->repository()->findOneBy(['token_hash' => hash('sha256', $plain)]);

        return $token instanceof MemberToken && $token->getPurpose() === $purpose
            ? $token->getAccountRef()
            : null;
    }

    /**
     * Cleanup companion (B7 daily run): drops every token that can never
     * redeem again — used, revoked, expired, or belonging to an account that
     * no longer exists (rejected / cleaned up). Returns the number of deleted
     * tokens.
     *
     * ⚠️ The orphan check applies ONLY to tokens that have an account. An
     * invitation deliberately has none (B7 v1.1.0) — measuring it against the
     * account list would delete every open invitation on the first run after
     * the feature ships, silently, in a job nobody watches. Invitations die of
     * their own three reasons and of nothing else here.
     *
     * @param string[] $validAccountIds ids of the accounts that still exist
     */
    public function purge(array $validAccountIds, ?int $now = null): int
    {
        $now     = $now ?? time();
        $valid   = array_flip($validAccountIds);
        $deleted = 0;

        foreach ($this->repository()->findAll() as $token) {
            if (!$token instanceof MemberToken) {
                continue;
            }
            $accountRef = $token->getAccountRef();
            $orphaned   = $accountRef !== null && !isset($valid[$accountRef]);

            if ($token->isDead($now) || $orphaned) {
                $this->uem->remove($token);
                $deleted++;
            }
        }
        if ($deleted > 0) {
            $this->uem->flush();
        }

        return $deleted;
    }

    /** @return MemberToken[] unused tokens of the account for this purpose */
    private function openTokens(string $accountRef, string $purpose): array
    {
        $tokens = $this->repository()->findBy(['account_ref' => $accountRef, 'purpose' => $purpose]);

        return array_values(array_filter($tokens, fn(MemberToken $t) => !$t->isUsed()));
    }

    /**
     * @return MemberToken[] unused invitations to this address at this
     *         reference — the ones a fresh invitation replaces
     */
    private function openInvites(string $tenantRef, string $email): array
    {
        $tokens = $this->repository()->findBy([
            'purpose'    => MemberToken::PURPOSE_INVITE,
            'tenant_ref' => $tenantRef,
            'email'      => MemberAccount::normalizeEmail($email),
        ]);

        return array_values(array_filter($tokens, fn(MemberToken $t) => !$t->isUsed()));
    }

    private function repository(): object
    {
        return $this->uem->getRepository(MemberToken::class);
    }
}
