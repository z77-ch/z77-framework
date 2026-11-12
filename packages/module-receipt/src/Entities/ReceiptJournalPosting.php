<?php

namespace Z77\Module\Receipt\Entities;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Z77\Persistence\Doctrine\Type\MoneyType;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Money\Money;

/**
 * The protocol of one «Journal verbuchen» of the receipt journal (the pattern of debtor's
 * `InvoiceJournalPosting`, ADR-054 decision 6): who posted, when, how many receipts and their gross
 * total. Each receipt the posting moved `captured` → `posted` names it
 * (`receipt.journal_posting_id`). A PROTOCOL, not a booking record — the journal entries live in
 * module-financial. Immutable: written once by `ReceiptJournalPostingService::post()`.
 *
 * Table `receipt_journal_posting` (`Version20261112100000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'receipt_journal_posting')]
class ReceiptJournalPosting
{
    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(name: 'posted_by', length: Receipt::ACTOR_LENGTH)]
    private string $postedBy;

    #[ORM\Column(name: 'posted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $postedAt;

    #[ORM\Column(name: 'receipt_count')]
    private int $receiptCount;

    #[ORM\Column(name: 'gross_total', type: MoneyType::NAME)]
    private Money $grossTotal;

    public function __construct(string $postedBy, \DateTimeImmutable $postedAt, int $receiptCount, Money $grossTotal)
    {
        if ($receiptCount < 1) {
            throw new \LogicException('A journal posting posts at least one receipt');
        }
        $this->postedBy     = $postedBy;
        $this->postedAt     = $postedAt;
        $this->receiptCount = $receiptCount;
        $this->grossTotal   = $grossTotal;
    }

    public function getId(): ?int { return $this->id; }
    public function getPostedBy(): string { return $this->postedBy; }
    public function getPostedAt(): \DateTimeImmutable { return $this->postedAt; }
    public function getReceiptCount(): int { return $this->receiptCount; }
    public function getGrossTotal(): Money { return $this->grossTotal; }
}
