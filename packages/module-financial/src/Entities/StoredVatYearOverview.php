<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Entity
;

/**
 * One STORED yearly overview of the VAT return — «MWST-Jahresübersicht {Jahr}» put into the archive
 * with «Ablegen …» (stage S7 slice 7.5; owner S7-1 option b, B-4, B-5, C-D of 2026-11-25). The read
 * model itself is `VatReturn\VatYearOverview` (computed, never stored); this row only names the PDF
 * that was frozen from it, and what the overview's fingerprint was at that moment.
 *
 *   - `taxYear` — the calendar year (Art. 34 MWSTG); deliberately NO unique key: the overview is
 *     stored again whenever it changed since the newest storing (B-5), and every stored PDF stays;
 *   - `standAt` — «Stand», the moment the overview was read for the PDF (printed on it);
 *   - `fingerprint` — `VatYearService::fingerprint()` of what the PDF shows: the screen compares it
 *     with the current one («Seit der Ablage geändert …») and offers «Ablegen …» again only when
 *     they differ;
 *   - the archived PDF — path, SHA-256 and the name it is offered under, in financial's write-once
 *     archive (`Pdf\VatYearArchive`), like the voucher of a posted return;
 *   - `detail` — what the protocol «Abschlüsse und MWST» shows beside it: the number of correction
 *     blocks for the year and the payable difference («1 Korrektur · Differenz Zahllast 40.46»);
 *   - `createdBy` / `createdAt` — who stored it and when («Abgelegt {Zeit} von {wer}»).
 *
 * Posts nothing, locks nothing of the books. Immutable: constructor and getters only. Written only
 * by `VatReturn\YearOverviewStore`; read by the yearly overview's screen and the protocol.
 *
 * Table `vat_year_overview`.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'vat_year_overview')]
#[ORM\Index(name: StoredVatYearOverview::INDEX_YEAR, columns: ['tax_year'])]
class StoredVatYearOverview
{
    public const INDEX_YEAR = 'idx_vat_year_overview_year';

    /** Longest archive path the column holds (as the voucher of a posted return). */
    public const PATH_LENGTH = 255;

    /** Longest file name the PDF is offered under. */
    public const NAME_LENGTH = 120;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(name: 'tax_year')]
    private int $taxYear;

    #[ORM\Column(name: 'stand_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $standAt;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $fingerprint;

    #[ORM\Column(name: 'pdf_path', length: self::PATH_LENGTH)]
    private string $pdfPath;

    #[ORM\Column(name: 'pdf_sha256', length: 64, options: ['fixed' => true])]
    private string $pdfSha256;

    #[ORM\Column(name: 'pdf_name', length: self::NAME_LENGTH)]
    private string $pdfName;

    /** JSON: `corrections` (int), `payable_difference` (decimal string) — the protocol's line. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $detail;

    #[ORM\Column(name: 'created_by', length: JournalEntry::ACTOR_LENGTH)]
    private string $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array{corrections?: int, payable_difference?: string} $detail
     */
    public function __construct(
        int $taxYear,
        \DateTimeImmutable $standAt,
        string $fingerprint,
        string $pdfPath,
        string $pdfSha256,
        string $pdfName,
        string $createdBy,
        \DateTimeImmutable $createdAt,
        array $detail = [],
    ) {
        if ($taxYear < 1900 || $taxYear > 9999) {
            throw new \LogicException("A stored yearly overview names a calendar year, not {$taxYear}");
        }
        foreach (['fingerprint' => $fingerprint, 'PDF hash' => $pdfSha256] as $what => $hash) {
            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw new \LogicException("The {$what} of a stored yearly overview is a SHA-256 in lower-case hex");
            }
        }
        if ($pdfPath === '' || mb_strlen($pdfPath) > self::PATH_LENGTH) {
            throw new \LogicException('A stored yearly overview names its archived PDF (at most ' . self::PATH_LENGTH . ' characters)');
        }
        $pdfName = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $pdfName)), 0, self::NAME_LENGTH);
        if ($pdfName === '') {
            throw new \LogicException('A stored yearly overview names its PDF file');
        }
        $this->taxYear     = $taxYear;
        $this->standAt     = $standAt;
        $this->fingerprint = $fingerprint;
        $this->pdfPath     = $pdfPath;
        $this->pdfSha256   = $pdfSha256;
        $this->pdfName     = $pdfName;
        $this->detail      = $detail === [] ? null : json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->createdBy   = mb_substr($createdBy, 0, JournalEntry::ACTOR_LENGTH);
        $this->createdAt   = $createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getTaxYear(): int { return $this->taxYear; }
    public function getStandAt(): \DateTimeImmutable { return $this->standAt; }
    public function getFingerprint(): string { return $this->fingerprint; }
    public function getPdfPath(): string { return $this->pdfPath; }
    public function getPdfSha256(): string { return $this->pdfSha256; }
    public function getPdfName(): string { return $this->pdfName; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return array{corrections?: int, payable_difference?: string} */
    public function getDetail(): array
    {
        if ($this->detail === null || $this->detail === '') {
            return [];
        }
        $decoded = json_decode($this->detail, true);

        return is_array($decoded) ? $decoded : [];
    }
}
