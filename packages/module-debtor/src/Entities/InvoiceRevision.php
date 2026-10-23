<?php

namespace Z77\Module\Debtor\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Entity
;

/**
 * One ISSUED VERSION of a document and its archived PDF (ADR-054 decisions 3, 11 and the owner
 * decisions of 2026-10-23 «stamp» and «archive»): every time a document is invoiced
 * («Fakturieren», revision 1) or re-invoiced under its number (revision 2, 3, …), the PDF is
 * rendered ONCE and stored as a FILE; a reprint delivers that file, never a re-rendered one.
 * This row is the version's record:
 *
 *   - `revision` — the version (1, 2, …), unique per document;
 *   - `document_stamp` — the version's unique ID, printed small next to the number and cited by
 *     a successor's note «ersetzt Rechnung 2610003 (ID …)». Computed from the version's CONTENT
 *     (number, date, recipient, lines, totals, VAT, revision — the snapshot), never from the PDF
 *     file (a PDF cannot carry its own hash); unique across all versions;
 *   - `file_path` — where the archived PDF lies, RELATIVE to the installation (decided
 *     2026-10-23: `data/archive/debtor/<YYYY>/…` — non-public, in the release's shared `data`
 *     store and in the «Daten» backup); `file_sha256` the SHA-256 of the stored file, what a
 *     check compares the archive against; `file_name` the name it is offered under.
 *
 * Immutable: written once, never changed, never deleted (GeBüV, ADR-054 «Legal notes» 1).
 *
 * Table `invoice_revision` (`Version20261023150000`, step 1 of the Faktura-Journal build plan —
 * schema only; the archiving itself comes with steps 3 / 4).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'invoice_revision')]
#[ORM\UniqueConstraint(name: InvoiceRevision::UNIQUE_REVISION, columns: ['invoice_id', 'revision'])]
#[ORM\UniqueConstraint(name: InvoiceRevision::UNIQUE_STAMP, columns: ['document_stamp'])]
class InvoiceRevision
{
    public const UNIQUE_REVISION = 'uniq_invoice_revision';
    public const UNIQUE_STAMP    = 'uniq_invoice_revision_stamp';

    public const STAMP_LENGTH     = Invoice::STAMP_LENGTH;
    public const FILE_PATH_LENGTH = 255;
    public const FILE_NAME_LENGTH = 120;
    public const REASON_LENGTH    = 255;
    public const ACTOR_LENGTH     = 80;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false)]
    private Invoice $invoice;

    /** The version (1, 2, …). */
    #[ORM\Column]
    private int $revision;

    #[ORM\Column(name: 'document_stamp', length: self::STAMP_LENGTH)]
    private string $documentStamp;

    /** The archived PDF, relative to the installation (`data/archive/debtor/…`). */
    #[ORM\Column(name: 'file_path', length: self::FILE_PATH_LENGTH)]
    private string $filePath;

    #[ORM\Column(name: 'file_sha256', length: 64, options: ['fixed' => true])]
    private string $fileSha256;

    /** The file name the PDF is offered under («rechnung-26100012.pdf»). */
    #[ORM\Column(name: 'file_name', length: self::FILE_NAME_LENGTH)]
    private string $fileName;

    /** Why the version was issued again — German, as the user confirmed it; null for revision 1 and when none was given. */
    #[ORM\Column(length: self::REASON_LENGTH, nullable: true)]
    private ?string $reason;

    #[ORM\Column(name: 'created_by', length: self::ACTOR_LENGTH)]
    private string $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Invoice $invoice, int $revision, string $documentStamp, string $filePath, string $fileSha256, string $fileName, ?string $reason, string $createdBy, \DateTimeImmutable $createdAt)
    {
        if ($revision < 1) {
            throw new \LogicException('A revision starts at 1');
        }
        if (preg_match('/^[0-9a-z]{1,' . self::STAMP_LENGTH . '}$/', $documentStamp) !== 1) {
            throw new \LogicException('A document stamp is 1-' . self::STAMP_LENGTH . ' lower-case letters or digits');
        }
        $filePath = trim($filePath);
        if ($filePath === '' || mb_strlen($filePath) > self::FILE_PATH_LENGTH || str_starts_with($filePath, '/') || str_contains($filePath, '..')) {
            throw new \LogicException('An archived file path is relative to the installation, 1-' . self::FILE_PATH_LENGTH . ' characters, without «..»');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $fileSha256) !== 1) {
            throw new \LogicException('The archived file\'s SHA-256 is 64 lower-case hex digits');
        }
        $fileName = trim($fileName);
        if ($fileName === '' || mb_strlen($fileName) > self::FILE_NAME_LENGTH) {
            throw new \LogicException('An archived version has a file name of 1-' . self::FILE_NAME_LENGTH . ' characters');
        }
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        if ($reason !== null && mb_strlen($reason) > self::REASON_LENGTH) {
            throw new \LogicException('A revision reason has at most ' . self::REASON_LENGTH . ' characters');
        }
        $this->invoice       = $invoice;
        $this->revision      = $revision;
        $this->documentStamp = $documentStamp;
        $this->filePath      = $filePath;
        $this->fileSha256    = $fileSha256;
        $this->fileName      = $fileName;
        $this->reason        = $reason;
        $this->createdBy     = $createdBy;
        $this->createdAt     = $createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getRevision(): int { return $this->revision; }
    public function getDocumentStamp(): string { return $this->documentStamp; }
    public function getFilePath(): string { return $this->filePath; }
    public function getFileSha256(): string { return $this->fileSha256; }
    public function getFileName(): string { return $this->fileName; }
    public function getReason(): ?string { return $this->reason; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
