<?php

namespace Z77\Module\Order\Services;

/**
 * One position of an {@see OrderDraft} (plan §5, D2 / D3) — plain data, typed as it comes:
 *
 *   - `id` — the line's id when the caller rewrites an EXISTING line (`update()` keeps its identity —
 *     the stock seam's line reference), null for a new one;
 *   - `type` — `service` | `lump-sum` | `text` (debtor's `LineType`; `rounding` is refused);
 *   - `variantId` — the ARTICLE (variant) or null for a free line; a text line never names one.
 *     On an article line an EMPTY text / unit / unit price takes the article's value AT SAVE (the
 *     price valid at the order date, converted to the order's price mode — then a snapshot);
 *     tax code and revenue account ALWAYS come from the article (D3) — what is given here is ignored;
 *   - `quantity` — a decimal string, three places, NEGATIVE allowed (a return); required on a
 *     service line, empty on a lump sum and a text line;
 *   - `unitPrice` — a decimal string, two places, sign allowed; `discount` — percent («10», «2.5»);
 *   - `taxCode` / `revenueAccount` — a FREE line's (required there);
 *   - `children` — lines printed beneath this one; only a priced top-level line carries them.
 */
final class OrderLineDraft
{
    /** @param list<OrderLineDraft> $children */
    public function __construct(
        public readonly string $type = 'service',
        public readonly ?int $variantId = null,
        public readonly string $text = '',
        public readonly string $quantity = '',
        public readonly string $unit = '',
        public readonly string $unitPrice = '',
        public readonly string $discount = '',
        public readonly string $taxCode = '',
        public readonly string $revenueAccount = '',
        public readonly array $children = [],
        public readonly ?int $id = null,
    ) {}

    /** An article line: quantity × the article's price unless one is typed. */
    public static function article(int $variantId, string $quantity, string $text = '', string $unitPrice = '', string $discount = '', ?int $id = null): self
    {
        return new self('service', $variantId, $text, $quantity, '', $unitPrice, $discount, id: $id);
    }

    /** A free service line (D2): text, quantity × price, its own tax code and account. */
    public static function free(string $text, string $quantity, string $unitPrice, string $taxCode, string $revenueAccount, string $unit = '', string $discount = '', ?int $id = null): self
    {
        return new self('service', null, $text, $quantity, $unit, $unitPrice, $discount, $taxCode, $revenueAccount, id: $id);
    }

    /** A text line — prints only. */
    public static function text(string $text, ?int $id = null): self
    {
        return new self('text', null, $text, id: $id);
    }

    /** The same line with $children printed beneath it. */
    public function beneath(OrderLineDraft ...$children): self
    {
        return new self($this->type, $this->variantId, $this->text, $this->quantity, $this->unit, $this->unitPrice, $this->discount, $this->taxCode, $this->revenueAccount, array_values($children), $this->id);
    }
}
