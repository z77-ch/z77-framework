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
 *   - `unit`           «Stk», «h» — '' = none (a lump sum)
 *   - `price`          the amount as typed («1'234.50», «120») — never negative
 *   - `priceMode`      `net` | `gross` (module-vat's `PriceMode`)
 *   - `validFrom`      `Y-m-d` — the day the price holds from («gültig ab», default today)
 *   - `taxCode`        module-vat's code (an ACTIVE one, or the one the article keeps)
 *   - `revenueAccount` the chart's number — type «Ertrag», «auf Belegen wählbar» (soft)
 *   - `stockRelevant`  «führt Bestand»
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
    ) {}

    /**
     * From a form body (snake_case keys, as the article dialog posts them), trimmed. Unknown keys
     * are ignored; a missing key is ''. `stock_relevant` is on when it is truthy («1», «on»).
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
        );
    }

    /** @return array<string, string|bool> the validator's keys */
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
        ];
    }
}
