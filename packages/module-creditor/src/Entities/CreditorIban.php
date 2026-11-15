<?php

namespace Z77\Module\Creditor\Entities;

use Doctrine\ORM\Mapping as ORM;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Payment\Iban;

/**
 * One IBAN a creditor is paid to (creditor plan §2.2) — a creditor has SEVERAL (a second bank, a
 * QR-IBAN beside the plain one), entered in the creditor window (later also taken over from an
 * invoice, «IBAN beim Kreditor speichern», stage 1c).
 *
 * **Shared IBAN:** `iban` is NOT unique across creditors — a billing or collection company may
 * collect for several suppliers. Matching an invoice by IBAN (stage 2) therefore yields 0, 1 or n
 * creditors; for n the optional `hint` of each link (a distinguishing text as printed on the
 * invoice body — the supplier's UID or name) is searched in the recognised text; exactly one hit
 * decides, otherwise the owner chooses. Never a guess. Within ONE creditor an IBAN is listed once
 * (`uniq_creditor_iban_profile`).
 *
 * Stored normalised ({@see Iban::normalize()}); `is_qr_iban` is derived from it on every set (a
 * QR-IBAN carries a QR reference). Any country — a supplier abroad has a foreign IBAN; the check
 * digits are what the validator insists on. Nothing references a row (an invoice SNAPSHOTS the
 * IBAN it names), so a row is removed when the window drops it; `active` = false keeps it listed
 * but out of matching.
 *
 * Table `creditor_iban` (`Version20261115100000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'creditor_iban')]
#[ORM\UniqueConstraint(name: CreditorIban::UNIQUE_PROFILE_IBAN, columns: ['creditor_profile_id', 'iban'])]
#[ORM\Index(name: 'idx_creditor_iban_iban', columns: ['iban'])]
class CreditorIban
{
    /** One creditor lists an IBAN once. */
    public const UNIQUE_PROFILE_IBAN = 'uniq_creditor_iban_profile';

    /** The longest distinguishing text. */
    public const HINT_LENGTH = 80;

    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CreditorProfile::class)]
    #[ORM\JoinColumn(name: 'creditor_profile_id', nullable: false)]
    private CreditorProfile $profile;

    #[ORM\Column(length: Iban::MAX_LENGTH)]
    private string $iban = '';

    #[ORM\Column(name: 'is_qr_iban')]
    private bool $qrIban = false;

    /** Optional — what tells this creditor apart when its IBAN is shared ('' = none). */
    #[ORM\Column(length: self::HINT_LENGTH, options: ['default' => ''])]
    private string $hint = '';

    #[ORM\Column]
    private bool $active = true;

    public function __construct(CreditorProfile $profile, string $iban, string $hint = '', bool $active = true)
    {
        $this->profile = $profile;
        $this->setIban($iban);
        $this->setHint($hint);
        $this->active = $active;
    }

    public function getId(): ?int { return $this->id; }
    public function getProfile(): CreditorProfile { return $this->profile; }
    /** Normalised: upper case, no spaces. */
    public function getIban(): string { return $this->iban; }
    public function isQrIban(): bool { return $this->qrIban; }
    public function getHint(): string { return $this->hint; }
    public function isActive(): bool { return $this->active; }

    public function setIban(string $iban): void
    {
        $this->iban   = Iban::normalize($iban);
        $this->qrIban = Iban::isQrIban($this->iban);
    }

    /** Trimmed, control characters replaced by a space. Too long: the validator refuses, never cut. */
    public function setHint(string $hint): void
    {
        $this->hint = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $hint));
    }

    public function setActive(bool $active): void { $this->active = $active; }
}
