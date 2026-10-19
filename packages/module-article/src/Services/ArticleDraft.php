<?php

namespace Z77\Module\Article\Services;

use Z77\Module\Vat\Entities\TaxCode;

/**
 * What ONE save of an article says (plan §4.1: «one form, one save») — plain data, no request,
 * so the backend form, a later import or an API call hand the same thing to
 * {@see ArticleService}. The values are the TYPED strings; {@see \Z77\Module\Article\Validators\ArticleValidator}
 * reads them strictly and the service converts them only after that.
 *
 *   - `number`         the article number of the (default) variant — free, unique (A2)
 *   - `name`           the product name
 *   - `description`    the product description — multi-line, '' = none (owner 2026-10-18)
 *   - `unit`           «Stk», «h» — '' = none (a lump sum)
 *   - `price`          the amount as typed («1'234.50», «120») — never negative
 *   - `priceMode`      `net` | `gross` (module-vat's `PriceMode`)
 *   - `validFrom`      `Y-m-d` — the day the price holds from («gültig ab», default today)
 *   - `taxCode`        module-vat's code (an ACTIVE one, or the one the article keeps)
 *   - `revenueAccount` the chart's number — type «Ertrag», «auf Belegen wählbar» (soft)
 *   - `stockRelevant`  «führt Bestand»
 *   - `productGroup`   the id of the ONE product group (A1, mandatory — 0 = none chosen)
 *
 * `mapToArray()` gives the validator its keys (the `EntityValidator` convention, snake_case).
 */
final class ArticleDraft
{
    public function __construct(
        public readonly string $number = '',
        public readonly string $name = '',
        public readonly string $unit = '',
        public readonly string $price = '',
        public readonly string $priceMode = 'net',
        public readonly string $validFrom = '',
        public readonly string $taxCode = '',
        public readonly string $revenueAccount = '',
        public readonly bool $stockRelevant = false,
        public readonly int $productGroup = 0,
        public readonly string $description = '',
    ) {}

    /**
     * From a form body (snake_case keys, as the article dialog posts them), trimmed. Unknown keys
     * are ignored; a missing key is ''. `stock_relevant` is on when it is truthy («1», «on»). The
     * description keeps its inner line breaks (normalized to «\n»), only its ends are trimmed.
     *
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $text = static fn(string $key): string => trim((string) (is_scalar($body[$key] ?? null) ? $body[$key] : ''));

        return new self(
            number:         $text('number'),
            name:           $text('name'),
            unit:           $text('unit'),
            price:          $text('price'),
            priceMode:      $text('price_mode'),
            validFrom:      $text('valid_from'),
            taxCode:        TaxCode::normalizeCode($text('tax_code')),
            revenueAccount: $text('revenue_account'),
            stockRelevant:  in_array(strtolower($text('stock_relevant')), ['1', 'on', 'true', 'yes'], true),
            productGroup:   ctype_digit($text('product_group')) ? (int) $text('product_group') : 0,
            description:    str_replace(["\r\n", "\r"], "\n", $text('description')),
        );
    }

    /**
     * The draft of a NEW article with the defaults of its group filled in where the field was
     * left EMPTY (owner 2026-10-14: «a group supplies defaults for new articles») — what a typed
     * value says always wins. `ArticleService::create()` asks this before it validates.
     */
    public function withDefaults(string $taxCode, string $revenueAccount): self
    {
        return new self(
            number:         $this->number,
            name:           $this->name,
            unit:           $this->unit,
            price:          $this->price,
            priceMode:      $this->priceMode,
            validFrom:      $this->validFrom,
            taxCode:        $this->taxCode !== '' ? $this->taxCode : $taxCode,
            revenueAccount: $this->revenueAccount !== '' ? $this->revenueAccount : $revenueAccount,
            stockRelevant:  $this->stockRelevant,
            productGroup:   $this->productGroup,
            description:    $this->description,
        );
    }

    /** @return array<string, string|bool|int> the validator's keys */
    public function mapToArray(): array
    {
        return [
            'number'          => $this->number,
            'name'            => $this->name,
            'unit'            => $this->unit,
            'price'           => $this->price,
            'price_mode'      => $this->priceMode,
            'valid_from'      => $this->validFrom,
            'tax_code'        => $this->taxCode,
            'revenue_account' => $this->revenueAccount,
            'stock_relevant'  => $this->stockRelevant,
            'product_group'   => $this->productGroup,
            'description'     => $this->description,
        ];
    }
}
