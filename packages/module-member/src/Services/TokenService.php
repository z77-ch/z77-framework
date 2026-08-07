<?php

namespace Z77\Module\Member\Services;

use Z77\Module\Member\Entities\MemberToken;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The one token mechanism of the member module (B7 spec, shared with B8):
 * issue() hands out a high-entropy plaintext exactly once — for the mail —
 * and stores only its SHA-256 hash, time-limited. redeem() is the single
 * consumer: right purpose, unused, unexpired, then marked used atomically
 * with the check's outcome.
 *
 * Issuing invalidates the account's previous open tokens of the same purpose
 * (spec: a resend devalues the old link) by deleting them — dead rows carry
 * no information worth keeping, and the used ones stay as redeem evidence.
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
     * Redeems a plaintext token: returns the account id, or null when the
     * token is unknown, wrong-purpose, already used or expired — the caller
     * shows the resend page in every null case (the spec's one answer for
     * dead links; "already confirmed" is an account-state question, not a
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
            || $token->isUsed()
            || $token->isExpired($now)
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
            && !$token->isUsed()
            && !$token->isExpired($now)
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
     * redeem again — used, expired, or belonging to an account that no longer
     * exists (rejected / cleaned up). Returns the number of deleted tokens.
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
            if ($token->isUsed() || $token->isExpired($now) || !isset($valid[$token->getAccountRef()])) {
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

    private function repository(): object
    {
        return $this->uem->getRepository(MemberToken::class);
    }
}
