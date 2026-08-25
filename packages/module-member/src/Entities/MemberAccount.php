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

    public const THEME_LIGHT = 'light';
    public const THEME_DARK  = 'dark';

    /** The two explicit choices. Absent (null) = follow the system. */
    public const THEMES = [self::THEME_LIGHT, self::THEME_DARK];

    /** Server-controlled — no setter; MemberAccounts assigns a random string id. */
    private ?string $id = null;

    /** Unique key, normalized. */
    #[Clean('text')]
    private string $email = '';

    /** Firma/Verwaltung — the only business field at registration (spec: minimal). */
    #[Clean('nullable', 'text')]
    private ?string $company = null;

    /**
     * WHERE this registration came from — the register link's `?via=` value,
     * e.g. a project's demo button. It changes nothing about the account; it
     * tells the person who activates WHICH offer was clicked, and that decides
     * what happens next (AXO3: a demo account gets a demo source deposited).
     *
     * A slug, never free text: the value arrives from a URL, so it is
     * normalized to [a-z0-9-] and capped — it ends up in our backend list and
     * in a mail, and nobody gets to write prose into either.
     */
    #[Clean('nullable', 'ident')]
    private ?string $origin = null;

    #[Clean('nullable', 'text')]
    private ?string $firstName = null;

    #[Clean('nullable', 'text')]
    private ?string $lastName = null;

    #[Clean('ident')]
    private string $state = self::STATE_REGISTERED;

    /** Roles granted at activation (AuthRole values). @var string[] */
    private array $roles = [];

    /**
     * Project reference (AXO3: tenant id). Written back by the activation hook
     * for a self-registration; set ALREADY AT REGISTRATION when the account
     * came from an invitation — then we have known the reference since the
     * invitation was sent (B7 v1.1.0).
     */
    #[Clean('nullable', 'text')]
    private ?string $tenantRef = null;

    /**
     * B7 v1.1.0 (ADR `konto-einladung`): several accounts may share one project
     * reference, and exactly one of them owns it — the MASTER, the account from
     * the registration we activated. Only it may invite, pause and remove;
     * professionally both levels can do exactly the same.
     *
     * ⚠️ The default is `master`, and that is deliberate: every account that
     * exists today IS the registrar of its reference, so introducing the field
     * needs no data migration. `member` is written at exactly one place — the
     * redemption of an invitation (B7 v1.1.1; v1.1.0 left it to the activation
     * hook, which is one step too late: between redemption and activation the
     * account already appears in the master's list).
     */
    public const ROLE_MASTER = 'master';
    public const ROLE_MEMBER = 'member';

    public const TENANT_ROLES = [self::ROLE_MASTER, self::ROLE_MEMBER];

    #[Clean('ident')]
    private string $tenantRole = self::ROLE_MASTER;

    /**
     * B7 v1.1.0: paused by the master — the quiet path between «leave it» and
     * «delete a person». Access rests, the account keeps its 2FA and its
     * devices; unpausing restores it.
     *
     * Deliberately NOT a fourth `state` value: `state` describes the
     * REGISTRATION path (registered → confirmed → active) and would lose that
     * meaning if a reversible access flag joined it — and an account can be
     * paused in any of the three.
     */
    #[Clean('nullable', 'text')]
    private ?string $suspendedAt = null;

    /**
     * Which version of the terms this account agreed to, and when.
     *
     * A project that puts a «I accept the terms» box into its registration
     * form (RegisterFormDefinition is the override point) gets the agreement
     * recorded here. No box, no version configured — both stay null and
     * nothing changes.
     *
     * ⚠️ TWO fields, not one, and not `createdAt` reused: terms change, and
     * then a signed-in customer agrees again to a NEW version. From that
     * moment the date of the agreement and the date of the account are two
     * different things, and the one that matters legally is this one.
     *
     * The version is an opaque label decided by the project (AXO3 uses the
     * date of the wording, «2026-08-25»). The module never interprets it — it
     * only has to come back out unchanged.
     */
    #[Clean('nullable', 'ident')]
    private ?string $termsVersion = null;

    #[Clean('nullable', 'text')]
    private ?string $termsAcceptedAt = null;

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

    /**
     * Appearance: 'light', 'dark' — or null, which is not a third look but the
     * absence of a decision, and then the system's `prefers-color-scheme`
     * decides (the stylesheet is written that way round).
     *
     * It lives at the ACCOUNT, not in the browser: the same person meeting a
     * dark tool at the desk and a light one on the phone would read that as two
     * different products. The price is that the choice needs a session — a
     * guest on the login card gets the system's answer, which is the honest
     * default for someone the installation does not know yet.
     */
    #[Clean('nullable', 'ident')]
    private ?string $theme = null;

    /**
     * B8 «angemeldet bleiben»: one entry per device that may resume a session
     * without a new magic link. The plaintext key exists only in the device's
     * cookie — here lives its SHA-256 hash. Entries are managed exclusively by
     * the DeviceKeys service (issue, roll, revoke).
     *
     * @var array<int,array{id:string,key_hash:string,label:string,created_at:string,last_used_at:string,valid_until:string}>
     */
    private array $deviceKeys = [];

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

    /**
     * The `?via=` value, made harmless: lowercase, only letters, digits and
     * hyphens, at most 24 characters. Anything left empty answers null — an
     * unknown origin is «none», not a stored oddity. The caller does not
     * whitelist: a value nobody knows shows up as itself in the list, which is
     * more honest than dropping it silently, and it cannot carry markup.
     */
    public static function normalizeOrigin(?string $origin): ?string
    {
        $slug = mb_substr(preg_replace('/[^a-z0-9-]/', '', mb_strtolower(trim((string)$origin))) ?? '', 0, 24);

        return $slug === '' ? null : $slug;
    }

    public function getId(): ?string { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function getCompany(): ?string { return $this->company; }
    public function getOrigin(): ?string { return $this->origin; }

    public function getTermsVersion(): ?string { return $this->termsVersion; }
    public function getTermsAcceptedAt(): ?string { return $this->termsAcceptedAt; }

    /**
     * Record an agreement. ⚠️ ONE method rather than two setters: a version
     * without a date, or a date without a version, is worth nothing as a
     * record — and two setters make exactly that state reachable.
     *
     * An empty version records nothing; that is the «no box configured» case
     * and not an error.
     */
    public function acceptTerms(?string $version, ?int $now = null): void
    {
        $version = trim((string)$version);
        if ($version === '') {
            return;
        }

        $this->termsVersion    = mb_substr($version, 0, 40);
        $this->termsAcceptedAt = date(DATE_ATOM, $now ?? time());
    }
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

    public function getTenantRole(): string { return $this->tenantRole; }
    public function isMaster(): bool { return $this->tenantRole === self::ROLE_MASTER; }
    public function getSuspendedAt(): ?string { return $this->suspendedAt; }
    public function isSuspended(): bool { return $this->suspendedAt !== null; }

    public function setSuspendedAt(?string $suspendedAt): void { $this->suspendedAt = $suspendedAt; }

    /** Hydration/setter with a guard — an unknown value would silently grant or remove ownership. */
    public function setTenantRole(string $tenantRole): void
    {
        if (!in_array($tenantRole, self::TENANT_ROLES, true)) {
            throw new \InvalidArgumentException(
                "Invalid tenant role '{$tenantRole}' — allowed: " . implode(', ', self::TENANT_ROLES)
            );
        }
        $this->tenantRole = $tenantRole;
    }

    public function getTotpSecret(): ?string { return $this->totpSecret; }
    public function getTotpActivatedAt(): ?string { return $this->totpActivatedAt; }
    /** 2FA fully active: secret stored AND the setup was confirmed with a valid code. */
    public function hasTotp(): bool { return $this->totpSecret !== null && $this->totpActivatedAt !== null; }
    /** Setup started (QR shown) but not yet confirmed. */
    public function hasPendingTotpSetup(): bool { return $this->totpSecret !== null && $this->totpActivatedAt === null; }

    public function setTotpSecret(?string $totpSecret): void { $this->totpSecret = $totpSecret; }
    public function setTotpActivatedAt(?string $totpActivatedAt): void { $this->totpActivatedAt = $totpActivatedAt; }

    public function getTheme(): ?string { return $this->theme; }

    /**
     * Anything that is not one of the two choices means «no decision» — so a
     * hand-edited store, an old value or a forged request all land on the
     * system default instead of on an exception. There is nothing to protect
     * here: the field decides a colour, and the guard keeps the caller simple.
     */
    public function setTheme(?string $theme): void
    {
        $this->theme = in_array($theme, self::THEMES, true) ? $theme : null;
    }

    /** @return array<int,array<string,string>> */
    public function getDeviceKeys(): array { return $this->deviceKeys; }
    /** @param array<int,array<string,string>> $deviceKeys */
    public function setDeviceKeys(array $deviceKeys): void { $this->deviceKeys = array_values($deviceKeys); }

    public function setEmail(string $email): void { $this->email = self::normalizeEmail($email); }
    public function setCompany(?string $company): void { $this->company = $company; }
    public function setOrigin(?string $origin): void { $this->origin = self::normalizeOrigin($origin); }
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
