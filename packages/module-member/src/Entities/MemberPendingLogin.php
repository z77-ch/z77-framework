<?php

namespace Z77\Module\Member\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * A waiting login (B8 spec 1.1.0, decision 5): the device that TYPED the
 * address parks here while the mail is read somewhere else. The confirmation
 * button on the other device flips it to 'approved', and the waiting device
 * picks the session up from its own status poll.
 *
 * It is a PROCESS, not an account trait — it lives next to the account, dies
 * with the link (same 15 minutes) and is deleted the moment it has done its
 * job.
 *
 * Anti-oracle: a record is opened for EVERY request, also for an unknown
 * address or a throttled one — then simply without a token to approve it
 * (`tokenHash` null). The waiting page therefore looks the same in every case;
 * it just never turns green.
 *
 * `checkDigits` and `label` are what the human compares (spec: display, never
 * an input field) — they are the only thing that tells an own login apart from
 * one a stranger started on the same address.
 */
#[Entity('file', 'framework/member/pending-logins.json')]
class MemberPendingLogin
{
    use ArrayMappable;

    public const STATE_WAITING  = 'waiting';
    public const STATE_APPROVED = 'approved';

    /** Server-controlled — no setter; the store assigns it. */
    private ?string $id = null;

    /** sha256 of the login token allowed to approve this record; null = none was issued. */
    #[Clean('nullable', 'ident')]
    private ?string $tokenHash = null;

    #[Clean('ident')]
    private string $state = self::STATE_WAITING;

    /** Four digits, shown on both pages so the human can compare them. */
    #[Clean('ident')]
    private string $checkDigits = '';

    /** Browser/system of the REQUESTING device, for the confirmation page. */
    #[Clean('text')]
    private string $label = '';

    #[Clean('text')]
    private string $requestedAt = '';

    #[Clean('text')]
    private string $validUntil = '';

    /** Written at approval — the waiting device signs in as this account. */
    #[Clean('nullable', 'text')]
    private ?string $accountRef = null;

    /** The «angemeldet bleiben» tick of the REQUESTING device (spec: it gets the key). */
    private bool $remember = false;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    public function getId(): ?string { return $this->id; }
    public function getTokenHash(): ?string { return $this->tokenHash; }
    public function getState(): string { return $this->state; }
    public function getCheckDigits(): string { return $this->checkDigits; }
    public function getLabel(): string { return $this->label; }
    public function getRequestedAt(): string { return $this->requestedAt; }
    public function getValidUntil(): string { return $this->validUntil; }
    public function getAccountRef(): ?string { return $this->accountRef; }
    public function wantsRemember(): bool { return $this->remember; }

    public function setTokenHash(?string $tokenHash): void { $this->tokenHash = $tokenHash; }
    public function setCheckDigits(string $checkDigits): void { $this->checkDigits = $checkDigits; }
    public function setLabel(string $label): void { $this->label = $label; }
    public function setRequestedAt(string $requestedAt): void { $this->requestedAt = $requestedAt; }
    public function setValidUntil(string $validUntil): void { $this->validUntil = $validUntil; }
    public function setAccountRef(?string $accountRef): void { $this->accountRef = $accountRef; }
    public function setRemember(bool|int|string $remember): void { $this->remember = (bool)$remember; }

    public function setState(string $state): void
    {
        if (!in_array($state, [self::STATE_WAITING, self::STATE_APPROVED], true)) {
            throw new \InvalidArgumentException("Invalid pending-login state '{$state}'");
        }
        $this->state = $state;
    }

    public function isApproved(): bool { return $this->state === self::STATE_APPROVED; }

    public function isExpired(int $now): bool
    {
        $until = strtotime($this->validUntil);

        return $until === false || $now > $until;
    }
}
