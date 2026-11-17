<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * One imported account statement — a `Stmt` of a camt.053 file (ADR-060): the bank account it
 * reports on, the bank's message and statement id, the period, the opening and closing booked
 * balance with their dates, and the FILE it came from (name, SHA-256, the path in the archive
 * `data/archive/financial/bank/<YYYY>/`).
 *
 * `movement_count` is how many entries of the statement became NEW movements, `duplicate_count`
 * how many the account already held (the overlap of an export — nothing is stored twice).
 *
 * A statement is only ever stored when it joins the account's chain SEAMLESSLY (ADR-060
 * decision 18): the statements of a bank account, in id order, cover one period without a gap,
 * and the newest one's `to_date` / `closing_balance` are «eingelesen bis … · Saldo …».
 *
 * Immutable: a statement is a document the bank sent. Never deleted (a booking record, kept ten
 * years). Table `bank_statement` (`Version20261116140000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'bank_statement')]
#[ORM\UniqueConstraint(name: BankStatement::UNIQUE_MESSAGE, columns: ['bank_account_id', 'message_id', 'statement_id'])]
#[ORM\Index(name: 'idx_bank_statement_sha256', columns: ['file_sha256'])]
class BankStatement
{
    public const UNIQUE_MESSAGE = 'uniq_bank_statement_message';

    public const ID_LENGTH        = 35;
    public const FILE_NAME_LENGTH = 120;
    public const PATH_LENGTH      = 255;
    public const ACTOR_LENGTH     = 80;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(name: 'bank_account_id', nullable: false)]
    private BankAccount $bankAccount;

    /** The bank's `GrpHdr/MsgId`. */
    #[ORM\Column(name: 'message_id', length: self::ID_LENGTH)]
    private string $messageId;

    /** The bank's `Stmt/Id`. */
    #[ORM\Column(name: 'statement_id', length: self::ID_LENGTH)]
    private string $statementId;

    /** `Stmt/ElctrncSeqNb` — stored as sent; an ad-hoc export always carries 1, so it is not checked. */
    #[ORM\Column(name: 'electronic_sequence', nullable: true)]
    private ?int $electronicSequence;

    #[ORM\Column(name: 'legal_sequence', nullable: true)]
    private ?int $legalSequence;

    /** `Stmt/FrToDt` — the period the statement covers; the dates of the balances when the bank sends none. */
    #[ORM\Column(name: 'from_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $fromDate;

    #[ORM\Column(name: 'to_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $toDate;

    /** The opening booked balance (`OPBD`), signed — negative on an overdrawn account. */
    #[ORM\Column(name: 'opening_balance', type: MoneyType::NAME)]
    private Money $openingBalance;

    #[ORM\Column(name: 'opening_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $openingDate;

    /** The closing booked balance (`CLBD`), signed. */
    #[ORM\Column(name: 'closing_balance', type: MoneyType::NAME)]
    private Money $closingBalance;

    #[ORM\Column(name: 'closing_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $closingDate;

    /** `GrpHdr/CreDtTm` — when the bank created the message. */
    #[ORM\Column(name: 'created_on', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdOn;

    #[ORM\Column(name: 'file_name', length: self::FILE_NAME_LENGTH)]
    private string $fileName;

    /** SHA-256 of the file's bytes — the same file is never read twice. */
    #[ORM\Column(name: 'file_sha256', length: 64)]
    private string $fileSha256;

    /** The archived file, relative to the installation (`data/archive/financial/bank/…`). */
    #[ORM\Column(name: 'archive_path', length: self::PATH_LENGTH)]
    private string $archivePath;

    #[ORM\Column(name: 'movement_count')]
    private int $movementCount = 0;

    #[ORM\Column(name: 'duplicate_count')]
    private int $duplicateCount = 0;

    #[ORM\Column(name: 'imported_by', length: self::ACTOR_LENGTH)]
    private string $importedBy;

    #[ORM\Column(name: 'imported_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $importedAt;

    public function __construct(
        BankAccount $bankAccount,
        string $messageId,
        string $statementId,
        ?int $electronicSequence,
        ?int $legalSequence,
        \DateTimeImmutable $fromDate,
        \DateTimeImmutable $toDate,
        Money $openingBalance,
        \DateTimeImmutable $openingDate,
        Money $closingBalance,
        \DateTimeImmutable $closingDate,
        \DateTimeImmutable $createdOn,
        string $fileName,
        string $fileSha256,
        string $archivePath,
        string $importedBy,
        \DateTimeImmutable $importedAt,
    ) {
        $this->bankAccount        = $bankAccount;
        $this->messageId          = mb_substr(trim($messageId), 0, self::ID_LENGTH);
        $this->statementId        = mb_substr(trim($statementId), 0, self::ID_LENGTH);
        $this->electronicSequence = self::sequence($electronicSequence);
        $this->legalSequence      = self::sequence($legalSequence);
        $this->fromDate           = $fromDate;
        $this->toDate             = $toDate;
        $this->openingBalance     = $openingBalance;
        $this->openingDate        = $openingDate;
        $this->closingBalance     = $closingBalance;
        $this->closingDate        = $closingDate;
        $this->createdOn          = $createdOn;
        $this->fileName           = mb_substr(trim($fileName), 0, self::FILE_NAME_LENGTH);
        $this->fileSha256         = $fileSha256;
        $this->archivePath        = $archivePath;
        $this->importedBy         = $importedBy;
        $this->importedAt         = $importedAt;
    }

    /** What the import found — set once by `BankStatementService`, before the statement is persisted. */
    public function record(int $movementCount, int $duplicateCount): void
    {
        $this->movementCount  = $movementCount;
        $this->duplicateCount = $duplicateCount;
    }

    /** A sequence number the INT column holds; anything else is not a sequence number and is dropped. */
    private static function sequence(?int $number): ?int
    {
        return $number !== null && $number >= 0 && $number <= 2147483647 ? $number : null;
    }

    public function getId(): ?int { return $this->id; }
    public function getBankAccount(): BankAccount { return $this->bankAccount; }
    public function getMessageId(): string { return $this->messageId; }
    public function getStatementId(): string { return $this->statementId; }
    public function getElectronicSequence(): ?int { return $this->electronicSequence; }
    public function getLegalSequence(): ?int { return $this->legalSequence; }
    public function getFromDate(): \DateTimeImmutable { return $this->fromDate; }
    public function getToDate(): \DateTimeImmutable { return $this->toDate; }
    public function getOpeningBalance(): Money { return $this->openingBalance; }
    public function getOpeningDate(): \DateTimeImmutable { return $this->openingDate; }
    public function getClosingBalance(): Money { return $this->closingBalance; }
    public function getClosingDate(): \DateTimeImmutable { return $this->closingDate; }
    public function getCreatedOn(): \DateTimeImmutable { return $this->createdOn; }
    public function getFileName(): string { return $this->fileName; }
    public function getFileSha256(): string { return $this->fileSha256; }
    public function getArchivePath(): string { return $this->archivePath; }
    public function getMovementCount(): int { return $this->movementCount; }
    public function getDuplicateCount(): int { return $this->duplicateCount; }
    public function getImportedBy(): string { return $this->importedBy; }
    public function getImportedAt(): \DateTimeImmutable { return $this->importedAt; }
}
