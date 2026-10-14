<?php

namespace Z77\Module\Article\Services;

use Z77\Module\Vat\Entities\TaxCode;

/**
 * What ONE save of a product group says — plain data, no request, so the backend window, a later
 * import (wdv `group1..group4` → a path, P8) or an API call hand the same thing to
 * {@see ProductGroupService}:
 *
 *   - `name`                  the group's name
 *   - `parentId`              the group it hangs under, 0 = a root
 *   - `defaultTaxCode`        module-vat's code a NEW article takes, '' = inherited / none
 *   - `defaultRevenueAccount` the chart's number a NEW article takes, '' = inherited / none
 *
 * `mapToArray()` gives the validator its keys (the `EntityValidator` convention, snake_case).
 */
final class ProductGroupDraft
{
    public function __construct(
        public readonly string $name = '',
        public readonly int $parentId = 0,
        public readonly string $defaultTaxCode = '',
        public readonly string $defaultRevenueAccount = '',
    ) {}

    /**
     * From a form body (snake_case keys, as the group window posts them), trimmed; a missing key
     * is '' / 0, a parent that is no number is 0 (a root).
     *
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $text = static fn(string $key): string => trim((string) (is_scalar($body[$key] ?? null) ? $body[$key] : ''));

        return new self(
            name:                  $text('name'),
            parentId:              ctype_digit($text('parent_id')) ? (int) $text('parent_id') : 0,
            defaultTaxCode:        TaxCode::normalizeCode($text('default_tax_code')),
            defaultRevenueAccount: $text('default_revenue_account'),
        );
    }

    /** @return array<string, string|int> the validator's keys */
    public function mapToArray(): array
    {
        return [
            'name'                    => $this->name,
            'parent_id'               => $this->parentId,
            'default_tax_code'        => $this->defaultTaxCode,
            'default_revenue_account' => $this->defaultRevenueAccount,
        ];
    }
}
