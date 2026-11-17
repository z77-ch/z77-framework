<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Module\Financial\Ledger\EntryRef,
    Z77\Shared\Attributes\Entity
;

/**
 * A DIRECT POSTING of a bank movement — «Geschäftlich buchen …» (ADR-061 decisions 14–17, stage
 * C2): the record financial keeps as the OWNER of the movement's link (`posting:<id>`, the same
 * port every owning module uses — `BankLinkService`) and of the generated ledger entry (source
 * {@see SOURCE_TYPE}, idempotency key {@see idempotencyKey()}).
 *
 * It names the journal entry (fiscal year + number), the contra account and the tax code it was
 * booked with, and — when a file was attached — the voucher in the write-once archive
 * (`Bank\BankVoucherArchive`: path, SHA-256, the original name). Without a tax code the archived
 * camt.053 is the voucher (decision 16): the entry's origin names movement and statement file.
 *
 * «Buchung zurücknehmen …» (decision 19) does not delete it: the reversing entry and who / when
 * are written here, the link is released, and a later posting of the same movement is a NEW row —
 * the history of a movement stays readable, and each posting has its own idempotency key.
 *
 * Table `bank_posting` (`Version20261117170000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'bank_posting')]
#[ORM\Index(name: 'idx_bank_posting_movement', columns: ['movement_id'])]
class BankPosting
{
    /** The owner type of the link and the first part of its owner key. */
    public const OWNER_TYPE = 'posting';
    /** The source type of the generated journal entry (ADR-042 decision 7). */
    public const SOURCE_TYPE = 'bank';

    public const PATH_LENGTH = 255;
    public const NAME_LENGTH = 255;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankMovement::class)]
    #[ORM\JoinColumn(name: 'movement_id', nullable: false)]
    private BankMovement $movement;

    #[ORM\Column(name: 'contra_account', length: Account::NUMBER_LENGTH)]
    private string $contraAccount;

    #[ORM\Column(name: 'tax_code', length: JournalLine::TAX_CODE_LENGTH, nullable: true)]
    private ?string $taxCode;

    /** The journal entry: the fiscal year's code and the number within it — set right after the ledger answered. */
    #[ORM\Column(name: 'entry_year', length: FiscalYear::CODE_LENGTH, nullable: true)]
    private ?string $entryYear = null;

    #[ORM\Column(name: 'entry_number', nullable: true)]
    private ?int $entryNumber = null;

    #[ORM\Column(name: 'voucher_path', length: self::PATH_LENGTH, nullable: true)]
    private ?string $voucherPath = null;

    #[ORM\Column(name: 'voucher_sha256', length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $voucherSha256 = null;

    /** The file's name as uploaded — foreign input, escaped on output. */
    #[ORM\Column(name: 'voucher_name', length: self::NAME_LENGTH, nullable: true)]
    private ?string $voucherName = null;

    #[ORM\Column(name: 'posted_by', length: JournalEntry::ACTOR_LENGTH)]
    private string $postedBy;

    #[ORM\Column(name: 'posted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $postedAt;

    #[ORM\Column(name: 'reversal_year', length: FiscalYear::CODE_LENGTH, nullable: true)]
    private ?string $reversalYear = null;

    #[ORM\Column(name: 'reversal_number', nullable: true)]
    private ?int $reversalNumber = null;

    #[ORM\Column(name: 'taken_back_by', length: JournalEntry::ACTOR_LENGTH, nullable: true)]
    private ?string $takenBackBy = null;

    #[ORM\Column(name: 'taken_back_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $takenBackAt = null;

    public function __construct(BankMovement $movement, string $contraAccount, ?string $taxCode, string $postedBy, \DateTimeImmutable $postedAt)
    {
        $this->movement      = $movement;
        $this->contraAccount = $contraAccount;
        $this->taxCode       = $taxCode;
        $this->postedBy      = mb_substr($postedBy, 0, JournalEntry::ACTOR_LENGTH);
        $this->postedAt      = $postedAt;
    }

    /** The key of posting $id as the owner of a bank link. */
    public static function ownerKeyOf(int $id): string
    {
        return self::OWNER_TYPE . ':' . $id;
    }

    public function ownerKey(): string
    {
        return self::ownerKeyOf((int) $this->id);
    }

    /** The ledger's idempotency key of this posting — one per row, so a movement booked again after a take-back posts anew. */
    public function idempotencyKey(): string
    {
        return 'bank-posting:' . (int) $this->id;
    }

    public function booked(EntryRef $ref): void
    {
        $this->entryYear   = $ref->fiscalYear;
        $this->entryNumber = $ref->number;
    }

    public function attach(string $path, string $sha256, string $name): void
    {
        $this->voucherPath   = $path;
        $this->voucherSha256 = $sha256;
        $this->voucherName   = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name)), 0, self::NAME_LENGTH);
    }

    public function takeBack(EntryRef $reversal, string $by, \DateTimeImmutable $at): void
    {
        $this->reversalYear   = $reversal->fiscalYear;
        $this->reversalNumber = $reversal->number;
        $this->takenBackBy    = mb_substr($by, 0, JournalEntry::ACTOR_LENGTH);
        $this->takenBackAt    = $at;
    }

    public function entryRef(): ?EntryRef
    {
        return $this->entryYear !== null && $this->entryNumber !== null ? new EntryRef($this->entryYear, $this->entryNumber) : null;
    }

    public function isTakenBack(): bool
    {
        return $this->takenBackAt !== null;
    }

    public function hasVoucher(): bool
    {
        return $this->voucherPath !== null && $this->voucherSha256 !== null;
    }

    public function getId(): ?int { return $this->id; }
    public function getMovement(): BankMovement { return $this->movement; }
    public function getContraAccount(): string { return $this->contraAccount; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function getVoucherPath(): ?string { return $this->voucherPath; }
    public function getVoucherSha256(): ?string { return $this->voucherSha256; }
    public function getVoucherName(): ?string { return $this->voucherName; }
    public function getPostedBy(): string { return $this->postedBy; }
    public function getPostedAt(): \DateTimeImmutable { return $this->postedAt; }
    public function getReversalYear(): ?string { return $this->reversalYear; }
    public function getReversalNumber(): ?int { return $this->reversalNumber; }
    public function getTakenBackBy(): ?string { return $this->takenBackBy; }
    public function getTakenBackAt(): ?\DateTimeImmutable { return $this->takenBackAt; }
}
