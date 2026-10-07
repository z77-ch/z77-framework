<?php

namespace Z77\Module\Debtor\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * One dunning notice (plan §6.5): invoice X was dunned at level L on the
 * run's date, with the open amount as it stood then and — when the level
 * carries a fee — the FEE DOCUMENT issued with it (an {@see Invoice} of
 * kind `fee`, owner 2026-10-06: «eigene Belegart»). The notice is the
 * invoice's dunning history: its highest level is the invoice's current
 * level, the next run offers the level after it.
 *
 * The level is kept BY CODE and by its number as it was: a ladder edited
 * later does not rewrite history. The printed notice (PDF) is rendered on
 * request from this row, the invoice's snapshot and the level's current
 * text — like the invoice PDF, nothing stored. Table `dunning_notice`
 * (`Version20261007150000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'dunning_notice')]
#[ORM\Index(name: 'idx_dunning_notice_invoice', columns: ['invoice_id'])]
class DunningNotice
{
    public const CODE_LENGTH = 16;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DunningRun::class, inversedBy: 'notices')]
    #[ORM\JoinColumn(name: 'run_id', nullable: false)]
    private DunningRun $run;

    /** Order within the run, from 1. */
    #[ORM\Column]
    private int $position;

    /** The invoice dunned. */
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false)]
    private Invoice $invoice;

    /** {@see DunningLevel::$code} — by code, no foreign key (ADR-043 decision 19). */
    #[ORM\Column(name: 'level_code', length: self::CODE_LENGTH)]
    private string $levelCode;

    /** {@see DunningLevel::$level} as it was — the ladder step, for the «next level» rule. */
    #[ORM\Column(name: 'level_number')]
    private int $levelNumber;

    /** What was open on the invoice when the notice was issued (before the fee). */
    #[ORM\Column(name: 'open_amount', type: MoneyType::NAME)]
    private Money $openAmount;

    /** The fee document issued with this notice — null when the level carries no fee. */
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'fee_invoice_id', nullable: true)]
    private ?Invoice $feeInvoice = null;

    public function __construct(DunningRun $run, int $position, Invoice $invoice, DunningLevel $level, Money $openAmount)
    {
        $this->run         = $run;
        $this->position    = $position;
        $this->invoice     = $invoice;
        $this->levelCode   = $level->getCode();
        $this->levelNumber = $level->getLevel();
        $this->openAmount  = $openAmount;
    }

    /** The fee document, once issued — by the dunning service inside its unit of work, once. */
    public function withFee(Invoice $fee): void
    {
        if ($this->feeInvoice !== null) {
            throw new \LogicException('A notice carries one fee document');
        }
        if ($fee->kind() !== InvoiceKind::Fee) {
            throw new \LogicException('The fee of a notice is a document of kind fee');
        }
        $this->feeInvoice = $fee;
    }

    public function getId(): ?int { return $this->id; }
    public function getRun(): DunningRun { return $this->run; }
    public function getPosition(): int { return $this->position; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getLevelCode(): string { return $this->levelCode; }
    public function getLevelNumber(): int { return $this->levelNumber; }
    public function getOpenAmount(): Money { return $this->openAmount; }
    public function getFeeInvoice(): ?Invoice { return $this->feeInvoice; }
}
