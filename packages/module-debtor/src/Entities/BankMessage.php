<?php

namespace Z77\Module\Debtor\Entities;

use Doctrine\Common\Collections\ArrayCollection,
    Doctrine\Common\Collections\Collection,
    Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Entity
;

/**
 * One imported CAMT.054 message (plan §6.4, P4 part 2): the bank's
 * notification of credits on ONE account — identified by the bank's
 * message id (`MsgId`, unique: the same file twice is refused), carrying
 * the IBAN it reports on, the payment target that IBAN resolved to (by
 * code — the account the payments are posted to), the file name and who
 * imported it when. Its {@see BankTransaction}s are the single credits
 * with their state.
 *
 * Immutable: a message is a document the bank sent; what changes is the
 * STATE of its transactions. Table `bank_message`; the mapping is the
 * table definition (`Version20261007100000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'bank_message')]
#[ORM\UniqueConstraint(name: BankMessage::UNIQUE_MESSAGE_ID, columns: ['message_id'])]
class BankMessage
{
    public const UNIQUE_MESSAGE_ID = 'uniq_bank_message_id';

    public const MESSAGE_ID_LENGTH  = 35;
    public const IBAN_LENGTH        = 34;
    public const TARGET_CODE_LENGTH = 16;
    public const FILE_NAME_LENGTH   = 120;
    public const ACTOR_LENGTH       = 80;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /** The bank's `GrpHdr/MsgId` — the dedup key across imports. */
    #[ORM\Column(name: 'message_id', length: self::MESSAGE_ID_LENGTH)]
    private string $messageId;

    /** `GrpHdr/CreDtTm` — when the bank created the message. */
    #[ORM\Column(name: 'created_on', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdOn;

    /** The account the message reports on (`Ntfctn/Acct/Id/IBAN`), normalized. */
    #[ORM\Column(length: self::IBAN_LENGTH)]
    private string $iban;

    /** {@see PaymentTarget::$code} the IBAN resolved to at import — the account the payments post to. */
    #[ORM\Column(name: 'payment_target_code', length: self::TARGET_CODE_LENGTH)]
    private string $paymentTargetCode;

    #[ORM\Column(name: 'file_name', length: self::FILE_NAME_LENGTH)]
    private string $fileName;

    #[ORM\Column(name: 'imported_by', length: self::ACTOR_LENGTH)]
    private string $importedBy;

    #[ORM\Column(name: 'imported_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $importedAt;

    /** @var Collection<int, BankTransaction> in position order */
    #[ORM\OneToMany(targetEntity: BankTransaction::class, mappedBy: 'message', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $transactions;

    public function __construct(string $messageId, \DateTimeImmutable $createdOn, string $iban, string $paymentTargetCode, string $fileName, string $importedBy, \DateTimeImmutable $importedAt)
    {
        $this->messageId         = mb_substr(trim($messageId), 0, self::MESSAGE_ID_LENGTH);
        $this->createdOn         = $createdOn;
        $this->iban              = $iban;
        $this->paymentTargetCode = PaymentTarget::normalizeCode($paymentTargetCode);
        $this->fileName          = mb_substr(trim($fileName), 0, self::FILE_NAME_LENGTH);
        $this->importedBy        = $importedBy;
        $this->importedAt        = $importedAt;
        $this->transactions      = new ArrayCollection();
    }

    /** Adds a transaction — by the import service, before the message is persisted. */
    public function add(BankTransaction $transaction): void
    {
        $this->transactions->add($transaction);
    }

    public function getId(): ?int { return $this->id; }
    public function getMessageId(): string { return $this->messageId; }
    public function getCreatedOn(): \DateTimeImmutable { return $this->createdOn; }
    public function getIban(): string { return $this->iban; }
    public function getPaymentTargetCode(): string { return $this->paymentTargetCode; }
    public function getFileName(): string { return $this->fileName; }
    public function getImportedBy(): string { return $this->importedBy; }
    public function getImportedAt(): \DateTimeImmutable { return $this->importedAt; }

    /** @return list<BankTransaction> */
    public function getTransactions(): array
    {
        return array_values($this->transactions->toArray());
    }

    /** @return array<string, int> state value → count */
    public function countPerState(): array
    {
        $counts = [];
        foreach (TransactionState::cases() as $state) {
            $counts[$state->value] = 0;
        }
        foreach ($this->transactions as $transaction) {
            $counts[$transaction->state()->value]++;
        }

        return $counts;
    }
}
