<?php

namespace Z77\Module\Article\Entities;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Persistence\Doctrine\Type\MoneyType;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Money\Money;

/**
 * One PRICE of a variant from a day on (plan §4b A4, owner Q3 2026-10-14: «history now, one
 * field in the mask») — the amount, declared net or gross (`priceMode`), valid from `validFrom`
 * until the next row of the same variant. A change of the price writes a NEW row; a second
 * change on the SAME day replaces that day's row (`uniq_article_price_day`). An order line takes
 * the price valid at the order date and keeps it as its snapshot — a later row never reaches a
 * line already written.
 *
 * Not extendable (no abstract stage): a price is history; a project's extra price (an offer
 * price) belongs on its `Variant` override (plan §4b example). Rows are never deleted while the
 * variant exists — the history explains an old line's price.
 *
 * Table `article_price`, the amount in the installation's base currency (`MoneyType`,
 * ADR-042 decision 3).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'article_price')]
#[ORM\UniqueConstraint(name: 'uniq_article_price_day', columns: ['variant_id', 'valid_from'])]
class Price
{
    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Variant::class)]
    #[ORM\JoinColumn(name: 'variant_id', nullable: false)]
    private Variant $variant;

    #[ORM\Column(name: 'valid_from', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $validFrom;

    #[ORM\Column(type: MoneyType::NAME)]
    private Money $amount;

    /** `net` | `gross` — module-vat's `PriceMode`. */
    #[ORM\Column(name: 'price_mode', length: 5)]
    private string $priceMode;

    public function __construct(Variant $variant, \DateTimeImmutable $validFrom, Money $amount, PriceMode $mode)
    {
        $this->variant   = $variant;
        $this->validFrom = $validFrom->setTime(0, 0);
        $this->amount    = $amount;
        $this->priceMode = $mode->value;
    }

    public function getId(): ?int { return $this->id; }
    public function getVariant(): Variant { return $this->variant; }
    public function getValidFrom(): \DateTimeImmutable { return $this->validFrom; }
    public function getAmount(): Money { return $this->amount; }
    public function priceMode(): PriceMode { return PriceMode::from($this->priceMode); }

    /** Whether this row already says $amount in $mode — then a save writes no new row. */
    public function says(Money $amount, PriceMode $mode): bool
    {
        return $this->amount->equals($amount) && $this->priceMode === $mode->value;
    }

    /** The same day's correction (owner Q3: «the same day replaces that day's row»). */
    public function replace(Money $amount, PriceMode $mode): void
    {
        $this->amount    = $amount;
        $this->priceMode = $mode->value;
    }
}
