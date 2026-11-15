<?php

namespace Z77\Module\Creditor\Entities;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Persistence\Doctrine\Type\MoneyType;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Money\Money;

/**
 * The protocol of one «Journal verbuchen» of the Kreditorenjournal (creditor plan §2.3, the shape
 * of receipt's `ReceiptJournalPosting` / debtor's `InvoiceJournalPosting`, ADR-054 decision 6):
 * who posted, when, how many invoices and their gross total. Each invoice the run posted names it
 * (`creditor_invoice.journal_posting_id`). A PROTOCOL, not a booking record.
 *
 * The table exists from stage 1b (one migration for the invoice side, plan §9); the run that
 * writes it is stage 1c. Immutable once written.
 *
 * Table `creditor_journal_posting` (`Version20261115120000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'creditor_journal_posting')]
class CreditorJournalPosting
{
    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(name: 'posted_by', length: JournalEntry::ACTOR_LENGTH)]
    private string $postedBy;

    #[ORM\Column(name: 'posted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $postedAt;

    #[ORM\Column(name: 'invoice_count')]
    private int $invoiceCount;

    #[ORM\Column(name: 'gross_total', type: MoneyType::NAME)]
    private Money $grossTotal;

    public function __construct(string $postedBy, \DateTimeImmutable $postedAt, int $invoiceCount, Money $grossTotal)
    {
        if ($invoiceCount < 1) {
            throw new \LogicException('A creditor journal posting posts at least one invoice');
        }
        $this->postedBy     = $postedBy;
        $this->postedAt     = $postedAt;
        $this->invoiceCount = $invoiceCount;
        $this->grossTotal   = $grossTotal;
    }

    public function getId(): ?int { return $this->id; }
    public function getPostedBy(): string { return $this->postedBy; }
    public function getPostedAt(): \DateTimeImmutable { return $this->postedAt; }
    public function getInvoiceCount(): int { return $this->invoiceCount; }
    public function getGrossTotal(): Money { return $this->grossTotal; }
}
