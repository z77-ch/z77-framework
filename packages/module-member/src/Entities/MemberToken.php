<?php

namespace Z77\Module\Member\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * One-time token (B7 spec): ONE mechanism with a purpose field, shared with
 * the login module part — 'confirm' is used by registration, 'login' is
 * reserved for magic login (B8). The registration confirmation link is
 * technically already the first magic link.
 *
 * Only the SHA-256 hash is stored; the plaintext exists once, in the mail.
 * A token is dead when it is used (usedAt set) or past validUntil —
 * TokenService::redeem() is the only consumer and enforces both.
 */
#[Entity('file', 'framework/member/tokens.json')]
class MemberToken
{
    use ArrayMappable;

    public const PURPOSE_CONFIRM = 'confirm';
    public const PURPOSE_LOGIN   = 'login';

    public const PURPOSES = [self::PURPOSE_CONFIRM, self::PURPOSE_LOGIN];

    /** Server-controlled — no setter; the collection store assigns max+1. */
    private ?int $id = null;

    /** sha256 of the token value — plaintext is never stored, never logged. */
    #[Clean('ident')]
    private string $tokenHash = '';

    /** MemberAccount id this token belongs to. */
    #[Clean('text')]
    private string $accountRef = '';

    #[Clean('ident')]
    private string $purpose = self::PURPOSE_CONFIRM;

    /** ISO timestamp; past this moment the token is dead. */
    #[Clean('text')]
    private string $validUntil = '';

    /** Set exactly once, on redeem — a used token never redeems again. */
    #[Clean('nullable', 'text')]
    private ?string $usedAt = null;

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
    public function getAccountRef(): string { return $this->accountRef; }
    public function getPurpose(): string { return $this->purpose; }
    public function getValidUntil(): string { return $this->validUntil; }
    public function getUsedAt(): ?string { return $this->usedAt; }
    public function wantsRemember(): bool { return $this->remember; }

    public function setTokenHash(string $tokenHash): void { $this->tokenHash = $tokenHash; }
    public function setAccountRef(string $accountRef): void { $this->accountRef = $accountRef; }
    public function setValidUntil(string $validUntil): void { $this->validUntil = $validUntil; }
    public function setUsedAt(?string $usedAt): void { $this->usedAt = $usedAt; }
    public function setRemember(bool|int|string $remember): void { $this->remember = (bool)$remember; }

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

    public function isExpired(int $now): bool
    {
        $until = strtotime($this->validUntil);
        return $until === false || $now > $until;
    }
}
