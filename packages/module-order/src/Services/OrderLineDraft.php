<?php

namespace Z77\Module\Order\Services;

/**
 * One position of an {@see OrderDraft} (plan §5, D2 / D3; ADR-043 addendum 2026-10-18) — plain
 * data, typed as it comes:
 *
 *   - `id` — the line's id when the caller rewrites an EXISTING line (`update()` keeps its identity —
 *     the stock seam's line reference), null for a new one;
 *   - `type` — `service` | `text` (debtor's `LineType`; `lump-sum` and `rounding` are refused — a
 *     lump sum is an article with quantity 1, owner 2026-10-18);
 *   - `variantId` — the ARTICLE (variant). Every priced line names one; a text line never does.
 *     Null on a priced line only for a FREE line the order already holds (made before 2026-10-18),
 *     sent back with its `id` and UNCHANGED — it keeps its stored tax code and account. On an
 *     article line an EMPTY text / unit / unit price takes the article's value AT SAVE (the price
 *     valid at the order date, converted to the order's price mode — then a snapshot); tax code and
 *     revenue account ALWAYS come from the article (D3);
 *   - `quantity` — a decimal string, three places, NEGATIVE allowed (a return); required on a
 *     priced line, empty on a text line;
 *   - `unitPrice` — a decimal string, two places, sign allowed; `discount` — percent («10», «2.5»);
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
        public readonly array $children = [],
        public readonly ?int $id = null,
    ) {}

    /** An article line: quantity × the article's price unless one is typed. */
    public static function article(int $variantId, string $quantity, string $text = '', string $unitPrice = '', string $discount = '', ?int $id = null): self
    {
        return new self('service', $variantId, $text, $quantity, '', $unitPrice, $discount, id: $id);
    }

    /** A text line — prints only. */
    public static function text(string $text, ?int $id = null): self
    {
        return new self('text', null, $text, id: $id);
    }

    /** The same line with $children printed beneath it. */
    public function beneath(OrderLineDraft ...$children): self
    {
        return new self($this->type, $this->variantId, $this->text, $this->quantity, $this->unit, $this->unitPrice, $this->discount, array_values($children), $this->id);
    }
}
