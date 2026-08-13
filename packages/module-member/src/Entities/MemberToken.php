<?php

namespace Z77\Module\Member\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * One-time token (B7 spec): ONE mechanism with a purpose field, shared with
 * the login module part — 'confirm' is used by registration, 'login' is
 * reserved for magic login (B8), and 'invite' carries an invitation to an
 * existing project reference (B7 v1.1.0, ADR `konto-einladung`). The
 * registration confirmation link is technically already the first magic link.
 *
 * Only the SHA-256 hash is stored; the plaintext exists once, in the mail.
 * A token is dead when it is used (usedAt set), revoked or past validUntil —
 * TokenService::redeem() is the only consumer and enforces all three.
 *
 * ⚠️ An INVITE token has no account: it is issued before the invited person
 * has one, and its identity is `tenantRef` + `email` instead. Every consumer
 * that reasons about `accountRef` has to tolerate null — see
 * TokenService::purge(), where forgetting it would delete every open
 * invitation on the next cleanup run.
 */
#[Entity('file', 'framework/member/tokens.json')]
class MemberToken
{
    use ArrayMappable;

    public const PURPOSE_CONFIRM = 'confirm';
    public const PURPOSE_LOGIN   = 'login';
    public const PURPOSE_INVITE  = 'invite';

    public const PURPOSES = [self::PURPOSE_CONFIRM, self::PURPOSE_LOGIN, self::PURPOSE_INVITE];

    /** Server-controlled — no setter; the collection store assigns max+1. */
    private ?int $id = null;

    /** sha256 of the token value — plaintext is never stored, never logged. */
    #[Clean('ident')]
    private string $tokenHash = '';

    /** MemberAccount id this token belongs to — null for an invitation. */
    #[Clean('nullable', 'text')]
    private ?string $accountRef = null;

    #[Clean('ident')]
    private string $purpose = self::PURPOSE_CONFIRM;

    /** ISO timestamp; past this moment the token is dead. */
    #[Clean('text')]
    private string $validUntil = '';

    /** Set exactly once, on redeem — a used token never redeems again. */
    #[Clean('nullable', 'text')]
    private ?string $usedAt = null;

    /**
     * B7 v1.1.0, invitations only: the project reference (AXO3: the tenant)
     * the invitation binds to. The invited account gets it at redemption —
     * that is what makes it a second account of an EXISTING tenant instead of
     * a registration that creates one.
     */
    #[Clean('nullable', 'text')]
    private ?string $tenantRef = null;

    /**
     * B7 v1.1.0, invitations only: the invited address, normalized.
     *
     * ⚠️ Load-bearing. The redemption form shows this address fixed, and the
     * server checks the submitted one against it — without that binding the
     * recipient redeems with any address he likes, and the tenant gets
     * somebody other than the one the master meant.
     */
    #[Clean('nullable', 'text')]
    private ?string $email = null;

    /** B7 v1.1.0, invitations only: the issuing account (whose list shows it). */
    #[Clean('nullable', 'text')]
    private ?string $invitedBy = null;

    /**
     * B7 v1.1.0: withdrawn by the master. Acts exactly like an expiry — the
     * recipient sees the same page either way, because whether it was revoked
     * or simply ran out is none of his business.
     */
    #[Clean('nullable', 'text')]
    private ?string $revokedAt = null;

    /**
     * B8, login tokens only: the visitor ticked «angemeldet bleiben» on the
     * request form. The wish must travel WITH the link, not with the browser
     * that asked — the mail may well be opened on another device, and the
     * device key belongs to the one that redeems. Meaningless for 'confirm'.
     */
    private bool $remember = false;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getTokenHash(): string { return $this->tokenHash; }
    public function getAccountRef(): ?string { return $this->accountRef; }
    public function getPurpose(): string { return $this->purpose; }
    public function getValidUntil(): string { return $this->validUntil; }
    public function getUsedAt(): ?string { return $this->usedAt; }
    public function wantsRemember(): bool { return $this->remember; }
    public function getTenantRef(): ?string { return $this->tenantRef; }
    public function getEmail(): ?string { return $this->email; }
    public function getInvitedBy(): ?string { return $this->invitedBy; }
    public function getRevokedAt(): ?string { return $this->revokedAt; }

    public function setTokenHash(string $tokenHash): void { $this->tokenHash = $tokenHash; }
    public function setValidUntil(string $validUntil): void { $this->validUntil = $validUntil; }
    public function setUsedAt(?string $usedAt): void { $this->usedAt = $usedAt; }
    public function setRemember(bool|int|string $remember): void { $this->remember = (bool)$remember; }
    public function setTenantRef(?string $tenantRef): void { $this->tenantRef = $tenantRef; }
    public function setInvitedBy(?string $invitedBy): void { $this->invitedBy = $invitedBy; }
    public function setRevokedAt(?string $revokedAt): void { $this->revokedAt = $revokedAt; }

    /** Empty string means «no account» — hydration of old rows wrote it that way. */
    public function setAccountRef(?string $accountRef): void
    {
        $this->accountRef = ($accountRef === null || $accountRef === '') ? null : $accountRef;
    }

    /** Normalized like the account's, so the redemption check compares equal. */
    public function setEmail(?string $email): void
    {
        $this->email = ($email === null || trim($email) === '')
            ? null
            : MemberAccount::normalizeEmail($email);
    }

    public function setPurpose(string $purpose): void
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException(
                "Invalid token purpose '{$purpose}' — allowed: " . implode(', ', self::PURPOSES)
            );
        }
        $this->purpose = $purpose;
    }

    public function isUsed(): bool { return $this->usedAt !== null; }
    public function isRevoked(): bool { return $this->revokedAt !== null; }
    public function isInvite(): bool { return $this->purpose === self::PURPOSE_INVITE; }

    public function isExpired(int $now): bool
    {
        $until = strtotime($this->validUntil);
        return $until === false || $now > $until;
    }

    /** The one question every consumer actually asks: can this still redeem? */
    public function isDead(int $now): bool
    {
        return $this->isUsed() || $this->isRevoked() || $this->isExpired($now);
    }
}
