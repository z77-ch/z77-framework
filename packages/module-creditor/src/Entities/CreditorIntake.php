<?php

namespace Z77\Module\Creditor\Entities;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Shared\Attributes\Entity;

/**
 * One FILE that arrived at the creditor intake (creditor plan §3) — through the backend upload
 * (stage 1b) or the folder job (stage 2). It records what came in (channel, the client's name,
 * size, the SHA-256 of the bytes), what became of it (`state`, `reason`) and where the PDF lies
 * (`document_id` in the DMS). The invoice it produced names it (`creditor_invoice.intake_id`).
 *
 * **Idempotency:** `sha256` is UNIQUE — the identical file is never read twice (owner answer 4).
 * A second upload of the same bytes stores nothing and answers «bereits eingelesen am …». The row
 * outlives its invoice: a deleted invoice leaves its intake behind, so the same PDF is not read
 * again either (creditor.md). NULL = no hash (a folder file that failed before it could be read,
 * stage 2 — several NULLs are allowed).
 *
 * States: `received` (stored in the DMS, the invoice created); `failed` (folder channel, stage 2:
 * refused — the file waits in `failed/` under `failed_name` with the `reason` until the owner
 * acts). Written by `CreditorIntakeService` only; no setter reaches it from a form.
 *
 * Table `creditor_intake` (`Version20261115120000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'creditor_intake')]
#[ORM\UniqueConstraint(name: CreditorIntake::UNIQUE_SHA256, columns: ['sha256'])]
class CreditorIntake
{
    /** The unique index on `sha256` — `CreditorIntakeService` recognises a race on it by this name. */
    public const UNIQUE_SHA256 = 'uniq_creditor_intake_sha256';

    public const CHANNEL_UPLOAD = 'upload';
    public const CHANNEL_FOLDER = 'folder';
    public const CHANNELS       = [self::CHANNEL_UPLOAD, self::CHANNEL_FOLDER];

    public const STATE_RECEIVED = 'received';
    public const STATE_FAILED   = 'failed';

    public const NAME_LENGTH   = 255;
    public const REASON_LENGTH = 255;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(length: 8)]
    private string $channel;

    /** The file's name as the client (or the folder) gave it — shown, never used as a path. */
    #[ORM\Column(name: 'original_name', length: self::NAME_LENGTH)]
    private string $originalName;

    #[ORM\Column(name: 'size_bytes')]
    private int $sizeBytes;

    /** Hex SHA-256 of the bytes; NULL = not read (stage 2). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sha256;

    #[ORM\Column(length: 12)]
    private string $state = self::STATE_RECEIVED;

    /** Why a file was refused (German, stage 2); '' = nothing to say. */
    #[ORM\Column(length: self::REASON_LENGTH, options: ['default' => ''])]
    private string $reason = '';

    /** The PDF in the DMS — no foreign key (file store); NULL while not stored. */
    #[ORM\Column(name: 'document_id', nullable: true)]
    private ?int $documentId = null;

    /** The file's name inside the intake folder's `failed/` (folder channel, stage 2); NULL = none. */
    #[ORM\Column(name: 'failed_name', length: self::NAME_LENGTH, nullable: true)]
    private ?string $failedName = null;

    #[ORM\Column(name: 'received_by', length: JournalEntry::ACTOR_LENGTH)]
    private string $receivedBy;

    #[ORM\Column(name: 'received_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    /** A file stored in the DMS — built by `CreditorIntakeService::receive()` after it checked the bytes. */
    public function __construct(string $channel, string $originalName, int $sizeBytes, ?string $sha256, ?int $documentId, string $receivedBy, \DateTimeImmutable $receivedAt)
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new \LogicException("Unknown creditor intake channel '{$channel}'");
        }
        $this->channel      = $channel;
        $this->originalName = mb_substr($originalName, 0, self::NAME_LENGTH);
        $this->sizeBytes    = $sizeBytes;
        $this->sha256       = $sha256;
        $this->documentId   = $documentId;
        $this->receivedBy   = $receivedBy;
        $this->receivedAt   = $receivedAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getChannel(): string { return $this->channel; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getSizeBytes(): int { return $this->sizeBytes; }
    public function getSha256(): ?string { return $this->sha256; }
    public function getState(): string { return $this->state; }
    public function getReason(): string { return $this->reason; }
    public function getDocumentId(): ?int { return $this->documentId; }
    public function getFailedName(): ?string { return $this->failedName; }
    public function getReceivedBy(): string { return $this->receivedBy; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
}
