<?php

namespace Z77\Module\Creditor\Entities;

use Doctrine\ORM\Mapping as ORM;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Ledger\GrossPartKind;
use Z77\Persistence\Doctrine\Type\MoneyType;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Money\Money;

/**
 * One LINE of a creditor invoice (creditor plan §2.3, owner answer 2): an invoice is split into
 * lines INSIDE the one invoice — not into two rows as the receipt journal does — because the
 * invoice is an identity (number, QR reference, later ONE open item and ONE payment). The intake
 * writes one line (on the creditor's defaults when the creditor is known); «+ Zeile» in the window
 * adds more, for a second expense account or a second VAT code. The lines must sum to the invoice
 * amount, or the invoice is incomplete ({@see CreditorInvoice::missing()}).
 *
 * What is on it: `position` (0…n-1, the window's order), `text` ('' = the invoice's label is used
 * when posted), `amount` (GROSS, base currency; NULL = not typed yet), `account` (an account NUMBER
 * of the chart, by number — ADR-043 decision 19), the VAT decision `tax_code` (module-vat's code)
 * OR `without_vat`. A mandator not liable to VAT forces «ohne MWST» on every line (owner answer 5,
 * `CreditorInvoiceService`).
 *
 * `line_kind` (VAT return stage S5, ADR-041 addendum 2026-11-25 point 6) — financial's
 * {@see GrossPartKind} value: `standard` (a gross amount split into net and tax) or `tax-only`
 * («nur Steuer»: the amount IS the input tax — the import VAT on a forwarder's or the customs'
 * invoice). A tax-only line carries an input code and NO account: z77 derives the input account
 * from the code when posting (the window locks the field, the save clears it).
 *
 * Written by the creditor services only (the whole set of lines is replaced on a save). Table
 * `creditor_invoice_line` (`Version20261115120000`, `line_kind` since `Version20261127120000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'creditor_invoice_line')]
class CreditorInvoiceLine
{
    public const TEXT_LENGTH = JournalEntry::TEXT_LENGTH;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CreditorInvoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false)]
    private CreditorInvoice $invoice;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(length: self::TEXT_LENGTH, options: ['default' => ''])]
    private string $text = '';

    /** The GROSS amount of the line, base currency, positive; NULL = not typed yet. */
    #[ORM\Column(type: MoneyType::NAME, nullable: true)]
    private ?Money $amount = null;

    #[ORM\Column(length: Account::NUMBER_LENGTH, nullable: true)]
    private ?string $account = null;

    #[ORM\Column(name: 'tax_code', length: JournalLine::TAX_CODE_LENGTH, nullable: true)]
    private ?string $taxCode = null;

    /** The explicit decision «ohne MWST» — never together with a tax code. */
    #[ORM\Column(name: 'without_vat', options: ['default' => false])]
    private bool $withoutVat = false;

    /** {@see GrossPartKind} value — `standard` or `tax-only` («nur Steuer»). */
    #[ORM\Column(name: 'line_kind', length: 12, options: ['default' => 'standard'])]
    private string $lineKind = 'standard';

    public function __construct(CreditorInvoice $invoice, int $position, string $text = '', ?Money $amount = null, ?string $account = null, ?string $taxCode = null, bool $withoutVat = false, GrossPartKind $kind = GrossPartKind::Standard)
    {
        if ($amount !== null && !$amount->isPositive()) {
            throw new \LogicException('A creditor invoice line amount is positive');
        }
        if ($taxCode !== null && $withoutVat) {
            throw new \LogicException('A creditor invoice line carries a tax code OR «without VAT», never both');
        }
        if ($kind === GrossPartKind::TaxOnly && ($taxCode === null || $account !== null)) {
            throw new \LogicException('A tax-only creditor invoice line carries an input tax code and no account — z77 derives the account from the code');
        }
        $this->invoice    = $invoice;
        $this->position   = $position;
        $this->text       = $text;
        $this->amount     = $amount;
        $this->account    = $account;
        $this->taxCode    = $taxCode;
        $this->withoutVat = $withoutVat;
        $this->lineKind   = $kind->value;
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoice(): CreditorInvoice { return $this->invoice; }
    public function getPosition(): int { return $this->position; }
    public function getText(): string { return $this->text; }
    public function getAmount(): ?Money { return $this->amount; }
    public function getAccount(): ?string { return $this->account; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function isWithoutVat(): bool { return $this->withoutVat; }
    public function getKind(): GrossPartKind { return GrossPartKind::tryFrom($this->lineKind) ?? GrossPartKind::Standard; }

    /** «nur Steuer» — the amount is the input tax, the account is derived from the code. */
    public function isTaxOnly(): bool
    {
        return $this->getKind() === GrossPartKind::TaxOnly;
    }

    /** A tax code or «ohne MWST» is chosen. */
    public function vatDecided(): bool
    {
        return $this->taxCode !== null || $this->withoutVat;
    }
}
