<?php

namespace Z77\Module\Member\Services;

use Z77\Core\DI;
use Z77\Module\Member\Entities\MemberAccount;
use Z77\Module\Member\Entities\MemberGrant;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The grants of the member module (ADR-037): one account, several project
 * references — a HOME on the account, and grants beside it.
 *
 * Two questions are the whole public surface a project needs, and both live
 * here so nobody else has to know how a grant is stored:
 *
 *   grantedTenantRefs($account)  the set the account may work for — home
 *                                first, then every usable grant. ONE method,
 *                                because the set is what every check
 *                                validates against.
 *   activeTenantRef($account)    the reference the account is working for
 *                                RIGHT NOW: the session's choice when it is
 *                                still in the set, otherwise the home. Never
 *                                «nothing» while the account has a home — a
 *                                grant that fell away while chosen falls back
 *                                silently at the next read.
 *
 * The choice itself is a WRITE with a check (choose()): the value is measured
 * against the set BEFORE it lands in the session, and a working request never
 * reads the reference from its own parameters — «the acting tenant never
 * comes from the request of the working operation» (bauplan rule, ADR-037).
 *
 * ⚠️ What a grant does NOT decide: ownership. Inviting, pausing, removing and
 * renaming follow the HOME (InvitationFlow::tenantRefOf(), the profile hook).
 * A caller that needs «whose reference is this account the master of» reads
 * `MemberAccount::getTenantRef()` — not this service.
 */
final class MemberGrants
{
    /**
     * @param ?MemberSession $session the session the choice lives in — null in
     *        a context without one (cleanup job, backend list); then
     *        activeTenantRef() answers the home and choose() refuses.
     */
    public function __construct(
        private UnifiedEntityManager $uem,
        private ?MemberSession $session = null,
    ) {
    }

    /** Production wiring: file persistence + the kernel session. */
    public static function create(): self
    {
        return new self(
            new UnifiedEntityManager(new DataSourceResolver(['file' => 'File'])),
            new MemberSession(DI::getSessionManager()),
        );
    }

    // ── the two public entries ─────────────────────────────────────────────

    /**
     * Home first, then every grant that is active and not paused, in creation
     * order. An account without a home (a registration not yet activated)
     * gets its grants only — and normally none, because a grant is attached
     * to an existing account and that is an active one in practice.
     *
     * @return list<string>
     */
    public function grantedTenantRefs(MemberAccount $account): array
    {
        $refs = [];
        $home = trim((string)$account->getTenantRef());
        if ($home !== '') {
            $refs[] = $home;
        }

        foreach ($this->findByAccount((string)$account->getId()) as $grant) {
            $ref = $grant->getTenantRef();
            if ($grant->isUsable() && $ref !== '' && !in_array($ref, $refs, true)) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * The reference the account works for now — the validated session choice,
     * or the first of the granted set (the home). Null only when the account
     * has nothing at all (not activated, no grant).
     */
    public function activeTenantRef(MemberAccount $account): ?string
    {
        $granted = $this->grantedTenantRefs($account);
        if ($granted === []) {
            return null;
        }

        $chosen = $this->session?->activeTenantRef();

        return ($chosen !== null && in_array($chosen, $granted, true)) ? $chosen : $granted[0];
    }

    /**
     * The choice: checked against the granted set, THEN written. False when
     * the reference is not granted (or there is no session to write to) — and
     * then nothing changes, the previous choice stands.
     */
    public function choose(MemberAccount $account, string $tenantRef): bool
    {
        $tenantRef = trim($tenantRef);
        if ($this->session === null || !in_array($tenantRef, $this->grantedTenantRefs($account), true)) {
            return false;
        }

        $this->session->setActiveTenantRef($tenantRef);

        return true;
    }

    /**
     * Does this account already hang on the reference — as its home, or by a
     * grant in ANY state? The invitation asks this: a second way onto the same
     * reference is refused, and a paused grant counts too (the master resumes
     * it instead of inviting again).
     */
    public function hasTenant(MemberAccount $account, string $tenantRef): bool
    {
        $tenantRef = trim($tenantRef);
        if ($tenantRef === '') {
            return false;
        }

        return trim((string)$account->getTenantRef()) === $tenantRef
            || $this->findFor((string)$account->getId(), $tenantRef) !== null;
    }

    // ── the store ──────────────────────────────────────────────────────────

    public function findById(string $id): ?MemberGrant
    {
        if ($id === '') {
            return null;
        }
        $grant = $this->repository()->findOneBy(['id' => $id]);

        return $grant instanceof MemberGrant ? $grant : null;
    }

    /** @return list<MemberGrant> in creation order */
    public function findByAccount(string $accountId): array
    {
        return $accountId === '' ? [] : $this->sorted($this->repository()->findBy(['account_id' => $accountId]));
    }

    /** @return list<MemberGrant> every grant ON one reference — the master's list, the backend row */
    public function findByTenant(string $tenantRef): array
    {
        $tenantRef = trim($tenantRef);

        return $tenantRef === '' ? [] : $this->sorted($this->repository()->findBy(['tenant_ref' => $tenantRef]));
    }

    public function findFor(string $accountId, string $tenantRef): ?MemberGrant
    {
        foreach ($this->findByAccount($accountId) as $grant) {
            if ($grant->getTenantRef() === trim($tenantRef)) {
                return $grant;
            }
        }

        return null;
    }

    /** @return list<MemberGrant> */
    public function all(): array
    {
        return $this->sorted($this->repository()->findAll());
    }

    /**
     * A new grant, born `confirmed` (the click in the inbox plus the explicit
     * «add this reference» were the verification). Null when the account
     * already hangs on the reference — no second row, ever.
     */
    public function grant(MemberAccount $account, string $tenantRef, ?string $invitedBy, ?int $now = null): ?MemberGrant
    {
        $now       ??= time();
        $tenantRef = trim($tenantRef);
        if ($tenantRef === '' || $this->hasTenant($account, $tenantRef)) {
            return null;
        }

        $grant = new MemberGrant();
        $ref   = new \ReflectionProperty(MemberGrant::class, 'id');
        $ref->setValue($grant, 'g-' . bin2hex(random_bytes(6)));
        $grant->setAccountId((string)$account->getId());
        $grant->setTenantRef($tenantRef);
        $grant->setInvitedBy($invitedBy);
        $grant->setCreatedAt(date(DATE_ATOM, $now));
        $grant->setConfirmedAt(date(DATE_ATOM, $now));

        $this->save($grant);

        return $grant;
    }

    /**
     * confirmed → active — the operator's handgrip. NO project hook: nothing
     * is created, the reference exists and the account exists; the grant only
     * ties them together.
     */
    public function activate(MemberGrant $grant, ?int $now = null): void
    {
        $grant->markActivated(date(DATE_ATOM, $now ?? time()));
        $this->save($grant);
    }

    /** Paused / resumed by the master — same semantics as MemberAccounts::suspend(). */
    public function suspend(MemberGrant $grant, ?int $now = null): void
    {
        $grant->setSuspendedAt(date(DATE_ATOM, $now ?? time()));
        $this->save($grant);
    }

    public function unsuspend(MemberGrant $grant): void
    {
        $grant->setSuspendedAt(null);
        $this->save($grant);
    }

    /** The grant disappears. The account it belonged to is NOT touched — it has its own home. */
    public function delete(MemberGrant $grant): void
    {
        $this->uem->remove($grant);
        $this->uem->flush();
    }

    /** Every grant of one account — the account's deletion path. @return int deleted */
    public function deleteForAccount(string $accountId): int
    {
        return $this->deleteAll($this->findByAccount($accountId));
    }

    /**
     * Every grant ON one reference — the deletion path of a project reference
     * that disappears (AXO3: TenantPurge). The module knows no tenants, so a
     * project calls this from wherever it deletes one.
     *
     * @return int deleted
     */
    public function deleteForTenant(string $tenantRef): int
    {
        return $this->deleteAll($this->findByTenant($tenantRef));
    }

    /**
     * Cleanup companion (member-cleanup, last instance): drops every grant
     * whose account no longer exists. Account deletion cascades already
     * (MemberAccounts::delete()); this catches what a hand-edited store or an
     * older deletion path left behind, so the two stores cannot drift apart.
     *
     * ⚠️ Deliberately NOT an age test. A `confirmed` grant waits for the
     * operator like a `confirmed` account does, and the module's rule holds
     * for both: what waits for a human decision is never a cron's to delete.
     *
     * @param  list<string> $validAccountIds ids of the accounts that still exist
     * @return int deleted
     */
    public function purgeOrphans(array $validAccountIds): int
    {
        $valid    = array_flip($validAccountIds);
        $orphaned = array_values(array_filter(
            $this->all(),
            static fn(MemberGrant $g): bool => !isset($valid[$g->getAccountId()])
        ));

        return $this->deleteAll($orphaned);
    }

    public function save(MemberGrant $grant): void
    {
        $this->uem->persist($grant);
        $this->uem->flush();
    }

    // ── internals ──────────────────────────────────────────────────────────

    /** @param list<MemberGrant> $grants */
    private function deleteAll(array $grants): int
    {
        foreach ($grants as $grant) {
            $this->uem->remove($grant);
        }
        if ($grants !== []) {
            $this->uem->flush();
        }

        return count($grants);
    }

    /** @return list<MemberGrant> */
    private function sorted(array $rows): array
    {
        $grants = array_values(array_filter($rows, static fn($g): bool => $g instanceof MemberGrant));
        usort($grants, static fn(MemberGrant $a, MemberGrant $b): int =>
            (string)$a->getCreatedAt() <=> (string)$b->getCreatedAt());

        return $grants;
    }

    private function repository(): object
    {
        return $this->uem->getRepository(MemberGrant::class);
    }
}
