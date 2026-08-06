<?php

namespace Z77\Module\Member\Services;

use Z77\Module\Member\Entities\MemberAccount;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * Account lifecycle of the member module (B7 spec). Registration creates the
 * account only — what a project attaches to activation (AXO3: creating the
 * tenant) runs through the activation hook passed to activate(): the handler
 * receives the account, returns the project reference (or null), and only
 * when it SUCCEEDS does the account become active. A failing handler leaves
 * the account 'confirmed' — no active account without its project side
 * (spec: "kein aktives Konto ohne Mandant").
 *
 * The e-mail is the unique key (normalized in the entity); register() returns
 * null on a duplicate instead of a second account — the caller sends the
 * "you already have an account" mail and shows the same neutral page
 * (anti-oracle, B8 principle).
 */
final class MemberAccounts
{
    public function __construct(private UnifiedEntityManager $uem)
    {
    }

    public function findByEmail(string $email): ?MemberAccount
    {
        $account = $this->repository()->findOneBy(['email' => MemberAccount::normalizeEmail($email)]);

        return $account instanceof MemberAccount ? $account : null;
    }

    public function findById(string $id): ?MemberAccount
    {
        $account = $this->repository()->findOneBy(['id' => $id]);

        return $account instanceof MemberAccount ? $account : null;
    }

    /** @return MemberAccount[] */
    public function all(): array
    {
        return $this->repository()->findAll();
    }

    /**
     * New account in state 'registered', or null when the e-mail already has
     * one (no second account, ever). Fields beyond the spec whitelist are the
     * caller's problem — this service takes exactly the four.
     */
    public function register(
        string $email,
        ?string $company,
        ?string $firstName,
        ?string $lastName,
        ?int $now = null
    ): ?MemberAccount {
        if ($this->findByEmail($email) !== null) {
            return null;
        }

        $account = new MemberAccount();
        $this->assignId($account);
        $account->setEmail($email);
        $account->setCompany($company);
        $account->setFirstName($firstName);
        $account->setLastName($lastName);
        $account->setCreatedAt(date(DATE_ATOM, $now ?? time()));

        $this->save($account);

        return $account;
    }

    /** registered → confirmed (caller has checked isConfirmed() for the "already" case). */
    public function confirm(MemberAccount $account, ?int $now = null): void
    {
        $account->markConfirmed(date(DATE_ATOM, $now ?? time()));
        $this->save($account);
    }

    /**
     * confirmed → active, wrapped around the project hook: $onActivated
     * receives the account and returns the project reference (?string) that
     * lands in tenantRef. If it throws, nothing is persisted — the account
     * stays 'confirmed' and the caller reports the failure.
     *
     * @param string[] $roles roles the account holds from now on
     * @param ?callable(MemberAccount): ?string $onActivated
     */
    public function activate(
        MemberAccount $account,
        array $roles,
        ?callable $onActivated = null,
        ?int $now = null
    ): void {
        $account->markActivated(date(DATE_ATOM, $now ?? time()), $roles);

        if ($onActivated !== null) {
            try {
                $account->setTenantRef($onActivated($account));
            } catch (\Throwable $e) {
                // Roll the in-memory transition back — the entity was never saved.
                $account->setState(MemberAccount::STATE_CONFIRMED);
                $account->setActivatedAt(null);
                $account->setRoles([]);
                throw $e;
            }
        }

        $this->save($account);
    }

    /** Reject / cleanup: the account disappears; mails are the caller's decision. */
    public function delete(MemberAccount $account): void
    {
        $this->uem->remove($account);
        $this->uem->flush();
    }

    /**
     * Daily cleanup (B7 spec): deletes accounts that were never confirmed
     * within the grace period. 'confirmed' accounts stay untouched until an
     * operator activates or rejects — that is an active decision, never a
     * cron's. Returns the number of deleted accounts.
     */
    public function cleanup(int $graceDays, ?int $now = null): int
    {
        $now     = $now ?? time();
        $cutoff  = $now - $graceDays * 86400;
        $deleted = 0;

        foreach ($this->all() as $account) {
            if (!$account->isRegistered()) {
                continue;
            }
            $created = strtotime((string)$account->getCreatedAt());
            if ($created !== false && $created < $cutoff) {
                $this->delete($account);
                $deleted++;
            }
        }

        return $deleted;
    }

    public function save(MemberAccount $account): void
    {
        $this->uem->persist($account);
        $this->uem->flush();
    }

    /** Random, stable string id (spec: self-assigned) — set before first persist. */
    private function assignId(MemberAccount $account): void
    {
        $ref = new \ReflectionProperty(MemberAccount::class, 'id');
        $ref->setValue($account, 'm-' . bin2hex(random_bytes(6)));
    }

    private function repository(): object
    {
        return $this->uem->getRepository(MemberAccount::class);
    }
}
