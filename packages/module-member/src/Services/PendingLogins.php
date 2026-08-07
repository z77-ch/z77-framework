<?php

namespace Z77\Module\Member\Services;

use Z77\Module\Member\Entities\MemberPendingLogin;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The store of waiting logins (B8 stage D). Small on purpose: open one per
 * request, find it again (by id from the requesting device's session, or by
 * token hash from the confirming device), approve it, drop it.
 *
 * Nothing here answers questions about accounts — the record is looked up by
 * a value the asker already holds, and the status answer above it says only
 * «waiting» or «approved».
 */
final class PendingLogins
{
    public function __construct(private UnifiedEntityManager $uem)
    {
    }

    /**
     * Opens a record for this request. $tokenHash is null when no login link
     * went out (unknown address, never-confirmed account, throttled) — the
     * record then simply never gets approved, and the waiting page stays
     * neutral (anti-oracle).
     */
    public function open(
        ?string $tokenHash,
        string $label,
        bool $remember,
        int $ttlSeconds,
        ?int $now = null
    ): MemberPendingLogin {
        $now ??= time();

        $pending = new MemberPendingLogin();
        $this->assignId($pending);
        $pending->setTokenHash($tokenHash);
        $pending->setCheckDigits(self::digits());
        $pending->setLabel($label);
        $pending->setRemember($remember);
        $pending->setRequestedAt(date(DATE_ATOM, $now));
        $pending->setValidUntil(date(DATE_ATOM, $now + $ttlSeconds));

        $this->save($pending);

        return $pending;
    }

    public function find(string $id): ?MemberPendingLogin
    {
        $pending = $this->repository()->findOneBy(['id' => $id]);

        return $pending instanceof MemberPendingLogin ? $pending : null;
    }

    /** The record a login link belongs to — the confirming device's way in. */
    public function findByTokenHash(string $tokenHash): ?MemberPendingLogin
    {
        $pending = $this->repository()->findOneBy(['token_hash' => $tokenHash]);

        return $pending instanceof MemberPendingLogin ? $pending : null;
    }

    /** The confirming device released it: the waiting device may sign in as this account. */
    public function approve(MemberPendingLogin $pending, string $accountRef): void
    {
        $pending->setState(MemberPendingLogin::STATE_APPROVED);
        $pending->setAccountRef($accountRef);
        $this->save($pending);
    }

    public function delete(MemberPendingLogin $pending): void
    {
        $this->uem->remove($pending);
        $this->uem->flush();
    }

    /** Drops every record past its window; returns how many. */
    public function purge(?int $now = null): int
    {
        $now     = $now ?? time();
        $deleted = 0;

        foreach ($this->repository()->findAll() as $pending) {
            if ($pending instanceof MemberPendingLogin && $pending->isExpired($now)) {
                $this->uem->remove($pending);
                $deleted++;
            }
        }
        if ($deleted > 0) {
            $this->uem->flush();
        }

        return $deleted;
    }

    public function save(MemberPendingLogin $pending): void
    {
        $this->uem->persist($pending);
        $this->uem->flush();
    }

    /** Four digits — the number the human compares, nothing to guess against. */
    private static function digits(): string
    {
        return str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    private function assignId(MemberPendingLogin $pending): void
    {
        $ref = new \ReflectionProperty(MemberPendingLogin::class, 'id');
        $ref->setValue($pending, 'p-' . bin2hex(random_bytes(8)));
    }

    private function repository(): object
    {
        return $this->uem->getRepository(MemberPendingLogin::class);
    }
}
