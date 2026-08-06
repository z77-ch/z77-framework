<?php

namespace Z77\Module\Member\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * A member account (B7 spec): created by self-registration, walked through
 *
 *   registered ──confirm──▶ confirmed ──activate──▶ active
 *
 * strictly forward by the transition methods below — invalid transitions
 * throw, "already there" is the caller's check (isConfirmed()/isActive()),
 * because a second confirmation click is a UI case, not a state change.
 *
 * The account carries the waiting states; the project tenant (AXO3 B1) is
 * created only at activation, by the project's hook handler — this entity
 * only stores the resulting reference (`tenantRef`).
 *
 * The e-mail is the unique key, normalized (trimmed, lowercased) at the
 * setter so every path — registration, lookup, hydration — compares equal.
 */
#[Entity('file', 'framework/member/accounts.json')]
class MemberAccount
{
    use ArrayMappable;

    public const STATE_REGISTERED = 'registered';
    public const STATE_CONFIRMED  = 'confirmed';
    public const STATE_ACTIVE     = 'active';

    public const STATES = [self::STATE_REGISTERED, self::STATE_CONFIRMED, self::STATE_ACTIVE];

    /** Server-controlled — no setter; MemberAccounts assigns a random string id. */
    private ?string $id = null;

    /** Unique key, normalized. */
    #[Clean('text')]
    private string $email = '';

    /** Firma/Verwaltung — the only business field at registration (spec: minimal). */
    #[Clean('nullable', 'text')]
    private ?string $company = null;

    #[Clean('nullable', 'text')]
    private ?string $firstName = null;

    #[Clean('nullable', 'text')]
    private ?string $lastName = null;

    #[Clean('ident')]
    private string $state = self::STATE_REGISTERED;

    /** Roles granted at activation (AuthRole values). @var string[] */
    private array $roles = [];

    /** Project reference written back by the activation hook (AXO3: tenant id). */
    #[Clean('nullable', 'text')]
    private ?string $tenantRef = null;

    #[Clean('nullable', 'text')]
    private ?string $createdAt = null;

    #[Clean('nullable', 'text')]
    private ?string $confirmedAt = null;

    #[Clean('nullable', 'text')]
    private ?string $activatedAt = null;

    /**
     * B8: TOTP secret, ENCRYPTED by TotpVault — never plaintext in the store.
     * Set at setup start; 2FA is ACTIVE only once totpActivatedAt is set too
     * (the customer confirmed the setup with a valid app code). null = off.
     */
    #[Clean('nullable', 'text')]
    private ?string $totpSecret = null;

    #[Clean('nullable', 'text')]
    private ?string $totpActivatedAt = null;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    /** Normalization every e-mail comparison relies on: trimmed, lowercased. */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function getId(): ?string { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function getCompany(): ?string { return $this->company; }
    public function getFirstName(): ?string { return $this->firstName; }
    public function getLastName(): ?string { return $this->lastName; }
    public function getState(): string { return $this->state; }
    /** @return string[] */
    public function getRoles(): array { return $this->roles; }
    public function getTenantRef(): ?string { return $this->tenantRef; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getConfirmedAt(): ?string { return $this->confirmedAt; }
    public function getActivatedAt(): ?string { return $this->activatedAt; }

    public function isRegistered(): bool { return $this->state === self::STATE_REGISTERED; }
    public function isConfirmed(): bool { return $this->state === self::STATE_CONFIRMED; }
    public function isActive(): bool { return $this->state === self::STATE_ACTIVE; }

    public function getTotpSecret(): ?string { return $this->totpSecret; }
    public function getTotpActivatedAt(): ?string { return $this->totpActivatedAt; }
    /** 2FA fully active: secret stored AND the setup was confirmed with a valid code. */
    public function hasTotp(): bool { return $this->totpSecret !== null && $this->totpActivatedAt !== null; }
    /** Setup started (QR shown) but not yet confirmed. */
    public function hasPendingTotpSetup(): bool { return $this->totpSecret !== null && $this->totpActivatedAt === null; }

    public function setTotpSecret(?string $totpSecret): void { $this->totpSecret = $totpSecret; }
    public function setTotpActivatedAt(?string $totpActivatedAt): void { $this->totpActivatedAt = $totpActivatedAt; }

    public function setEmail(string $email): void { $this->email = self::normalizeEmail($email); }
    public function setCompany(?string $company): void { $this->company = $company; }
    public function setFirstName(?string $firstName): void { $this->firstName = $firstName; }
    public function setLastName(?string $lastName): void { $this->lastName = $lastName; }
    /** @param string[] $roles */
    public function setRoles(array $roles): void { $this->roles = array_values($roles); }
    public function setTenantRef(?string $tenantRef): void { $this->tenantRef = $tenantRef; }
    public function setCreatedAt(?string $createdAt): void { $this->createdAt = $createdAt; }
    public function setConfirmedAt(?string $confirmedAt): void { $this->confirmedAt = $confirmedAt; }
    public function setActivatedAt(?string $activatedAt): void { $this->activatedAt = $activatedAt; }

    /** Hydration/state setter with the STATES guard (transitions go through mark*). */
    public function setState(string $state): void
    {
        if (!in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException(
                "Invalid account state '{$state}' — allowed: " . implode(', ', self::STATES)
            );
        }
        $this->state = $state;
    }

    /** registered → confirmed. Anything else is a programming error — check isConfirmed() first. */
    public function markConfirmed(string $nowIso): void
    {
        if ($this->state !== self::STATE_REGISTERED) {
            throw new \LogicException("Cannot confirm an account in state '{$this->state}'");
        }
        $this->state       = self::STATE_CONFIRMED;
        $this->confirmedAt = $nowIso;
    }

    /**
     * confirmed → active. Roles are granted here (spec: roles exist from
     * activation on); the project hook runs around this call and writes
     * tenantRef back — see MemberAccounts::activate().
     *
     * @param string[] $roles
     */
    public function markActivated(string $nowIso, array $roles): void
    {
        if ($this->state !== self::STATE_CONFIRMED) {
            throw new \LogicException("Cannot activate an account in state '{$this->state}'");
        }
        $this->state       = self::STATE_ACTIVE;
        $this->activatedAt = $nowIso;
        $this->roles       = array_values($roles);
    }
}
