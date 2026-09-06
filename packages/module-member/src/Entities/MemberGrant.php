<?php

namespace Z77\Module\Member\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * A grant (ADR-037): «this account may ADDITIONALLY work for that project
 * reference». One row per account × reference, kept BESIDE the account —
 * `accounts.json` is untouched, an account keeps exactly one HOME
 * (`MemberAccount::$tenantRef`), and the grants say where else it may go.
 *
 *   confirmed ──operator activates──▶ active
 *
 * Born `confirmed`: the invited person clicked the link in his own inbox and
 * confirmed on a page that he wants this reference added to his account —
 * the invitation was the verification, exactly like an invited account. It
 * waits for OUR activation like every other registration (B7 decision 4);
 * the difference is that nothing is created — no account, no tenant — it is
 * only attached. That is why activating a grant runs NO activation hook.
 *
 * `suspendedAt` is the master's pause switch with the SAME semantics as on
 * the account: access rests, the row stays, unpausing restores it. Not a
 * third `state` value, for the same reason the account has none.
 *
 * ⚠️ A grant grants no ownership: it never makes its account a master of the
 * reference, it confers no right to invite, and it is not what the profile
 * hook renames. Everything ownership-related reads the HOME, never the grant
 * (ADR-037 rule «ownership follows the home»).
 */
#[Entity('file', 'framework/member/grants.json')]
class MemberGrant
{
    use ArrayMappable;

    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_ACTIVE    = 'active';

    public const STATES = [self::STATE_CONFIRMED, self::STATE_ACTIVE];

    /**
     * Server-controlled — no setter; MemberGrants assigns a random string id
     * with its own prefix (`g-`), disjoint from the account's `m-` space, so a
     * handgrip that serves both (pause, remove) can never confuse the two.
     */
    private ?string $id = null;

    /** The account this grant belongs to (MemberAccount id). */
    #[Clean('text')]
    private string $accountId = '';

    /** The project reference the account may additionally work for. */
    #[Clean('text')]
    private string $tenantRef = '';

    /** The inviting account (id) — the master of `tenantRef` at the time. */
    #[Clean('nullable', 'text')]
    private ?string $invitedBy = null;

    #[Clean('ident')]
    private string $state = self::STATE_CONFIRMED;

    #[Clean('nullable', 'text')]
    private ?string $createdAt = null;

    #[Clean('nullable', 'text')]
    private ?string $confirmedAt = null;

    #[Clean('nullable', 'text')]
    private ?string $activatedAt = null;

    #[Clean('nullable', 'text')]
    private ?string $suspendedAt = null;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    public function getId(): ?string { return $this->id; }
    public function getAccountId(): string { return $this->accountId; }
    public function getTenantRef(): string { return $this->tenantRef; }
    public function getInvitedBy(): ?string { return $this->invitedBy; }
    public function getState(): string { return $this->state; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getConfirmedAt(): ?string { return $this->confirmedAt; }
    public function getActivatedAt(): ?string { return $this->activatedAt; }
    public function getSuspendedAt(): ?string { return $this->suspendedAt; }

    public function isConfirmed(): bool { return $this->state === self::STATE_CONFIRMED; }
    public function isActive(): bool { return $this->state === self::STATE_ACTIVE; }
    public function isSuspended(): bool { return $this->suspendedAt !== null; }

    /** Active AND not paused — the one question grantedTenantRefs() asks. */
    public function isUsable(): bool { return $this->isActive() && !$this->isSuspended(); }

    public function setAccountId(string $accountId): void { $this->accountId = $accountId; }
    public function setTenantRef(string $tenantRef): void { $this->tenantRef = trim($tenantRef); }
    public function setInvitedBy(?string $invitedBy): void { $this->invitedBy = $invitedBy; }
    public function setCreatedAt(?string $createdAt): void { $this->createdAt = $createdAt; }
    public function setConfirmedAt(?string $confirmedAt): void { $this->confirmedAt = $confirmedAt; }
    public function setActivatedAt(?string $activatedAt): void { $this->activatedAt = $activatedAt; }
    public function setSuspendedAt(?string $suspendedAt): void { $this->suspendedAt = $suspendedAt; }

    /** Hydration/state setter with the STATES guard (the transition goes through markActivated()). */
    public function setState(string $state): void
    {
        if (!in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException(
                "Invalid grant state '{$state}' — allowed: " . implode(', ', self::STATES)
            );
        }
        $this->state = $state;
    }

    /** confirmed → active. Anything else is a programming error — check isActive() first. */
    public function markActivated(string $nowIso): void
    {
        if ($this->state !== self::STATE_CONFIRMED) {
            throw new \LogicException("Cannot activate a grant in state '{$this->state}'");
        }
        $this->state       = self::STATE_ACTIVE;
        $this->activatedAt = $nowIso;
    }
}
