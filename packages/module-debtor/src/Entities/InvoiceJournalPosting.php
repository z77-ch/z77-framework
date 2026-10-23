<?php

namespace Z77\Module\Debtor\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * The protocol of one «Journal verbuchen» (ADR-054 decision 6): who posted the Faktura-Journal,
 * when, how many documents, their gross total and the number range they span. Each document the
 * posting moved `issued` → `final` names it (`invoice.journal_posting_id`), so a posted batch's
 * journal list can be reprinted.
 *
 * A PROTOCOL, not a booking record — the ledger entries live in module-financial, built by
 * `PostingBuilder` from each document at the posting (owner 2026-10-23: «keine Buchungssätze»).
 * Immutable: written once by the journal posting, never changed.
 *
 * Table `invoice_journal_posting` (`Version20261023150000`, step 1 of the Faktura-Journal build
 * plan — schema only, nothing writes it before step 3).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'invoice_journal_posting')]
class InvoiceJournalPosting
{
    public const ACTOR_LENGTH = 80;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(name: 'posted_by', length: self::ACTOR_LENGTH)]
    private string $postedBy;

    #[ORM\Column(name: 'posted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $postedAt;

    #[ORM\Column(name: 'document_count')]
    private int $documentCount;

    /** The gross total of the batch as its journal list states it (the sign rule of credit notes is the journal posting's, step 3). */
    #[ORM\Column(name: 'gross_total', type: MoneyType::NAME)]
    private Money $grossTotal;

    /** The lowest and the highest document number of the batch (the batch is posted in number order). */
    #[ORM\Column(name: 'first_number')]
    private int $firstNumber;

    #[ORM\Column(name: 'last_number')]
    private int $lastNumber;

    public function __construct(string $postedBy, \DateTimeImmutable $postedAt, int $documentCount, Money $grossTotal, int $firstNumber, int $lastNumber)
    {
        if ($documentCount < 1) {
            throw new \LogicException('A journal posting posts at least one document');
        }
        if ($firstNumber < 1 || $lastNumber < $firstNumber) {
            throw new \LogicException('A journal posting spans a number range first ≤ last, from 1');
        }
        $this->postedBy      = $postedBy;
        $this->postedAt      = $postedAt;
        $this->documentCount = $documentCount;
        $this->grossTotal    = $grossTotal;
        $this->firstNumber   = $firstNumber;
        $this->lastNumber    = $lastNumber;
    }

    public function getId(): ?int { return $this->id; }
    public function getPostedBy(): string { return $this->postedBy; }
    public function getPostedAt(): \DateTimeImmutable { return $this->postedAt; }
    public function getDocumentCount(): int { return $this->documentCount; }
    public function getGrossTotal(): Money { return $this->grossTotal; }
    public function getFirstNumber(): int { return $this->firstNumber; }
    public function getLastNumber(): int { return $this->lastNumber; }
}
