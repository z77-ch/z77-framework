<?php

namespace Z77\Module\Debtor\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * WHEN an invoice is due, and what a customer gets for paying early (plan
 * §6.1): a due day count and up to {@see MAX_TIERS} discount tiers — «10
 * Tage 2 %, 30 Tage netto» is one tier plus `dueDays`, three-step terms
 * («3 % / 10 Tage, 2 % / 20 Tage, 30 Tage netto») are two.
 *
 * Installation master data, file-based (a handful of rows), seeded once on
 * first install (`data/framework/debtor/payment_terms.default.json`,
 * ADR-024 seed-once) and maintained in the backend.
 *
 * The reference rule (ADR-043 decision 19): a {@see DebtorProfile} and,
 * from P3 part 2, every invoice reference these terms BY `code`; a row is
 * DEACTIVATED, never deleted — nothing in this module removes one — and the
 * code is immutable once created. `code` is normalized at the setter
 * (trimmed, lower-cased) so every path — form, seed, lookup — compares equal.
 *
 * Two texts, two audiences (decided 2026-09-22): `label` is the German name
 * the BACKEND list shows, like `TaxCode::$label` and `AddressType::$label`;
 * `documentText` is what prints on the customer's invoice and is therefore
 * kept PER LANGUAGE (`i18n.md`: a language code of `I18n::getLanguages()`,
 * the document falls back to `defaultLanguage` — the fallback resolver
 * arrives with the document in part 2, nothing reads it yet).
 *
 * A discount percent is an INTEGER IN HUNDREDTHS OF A PERCENT, exactly as
 * the VAT rates are (plan §3, `TaxRate::$rate`): 2 % = `200`. No float
 * anywhere near an amount.
 */
#[Entity('file', 'framework/debtor/payment_terms.json')]
class PaymentTerms
{
    use ArrayMappable;
    use HasDocumentText;

    /** How many discount tiers a row may carry — the validator refuses more. */
    public const MAX_TIERS = 2;

    /** Server-controlled — no setter; the collection store assigns it. */
    private ?int $id = null;

    /** Unique key, `[a-z][a-z0-9-]{1,15}`, immutable after creation. */
    #[Clean('ident')]
    private string $code = '';

    /** German, for the backend list — NOT what prints on a document. */
    #[Clean('text')]
    private string $label = '';

    /** Days from the invoice date until the whole amount is due. */
    #[Clean('int')]
    private int $dueDays = 0;

    /**
     * The discount tiers, ascending by `days`, at most {@see MAX_TIERS}.
     * Each entry is `['days' => int, 'percent' => int]` with the percent in
     * hundredths of a percent. Written from the form field by field, never
     * mapped raw from a request body — hence no `#[Clean]`.
     *
     * @var list<array{days: int, percent: int}>
     */
    private array $discounts = [];

    /** False = not offered for a NEW reference; existing ones still resolve. */
    #[Clean('bool')]
    private bool $active = true;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    /** The one normalization every code comparison relies on. */
    public static function normalizeCode(string $code): string
    {
        return mb_strtolower(trim($code));
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getLabel(): string { return $this->label; }
    public function getDueDays(): int { return $this->dueDays; }
    public function isActive(): bool { return $this->active; }

    /** @return list<array{days: int, percent: int}> ascending by days */
    public function getDiscounts(): array { return $this->discounts; }

    public function setCode(string $code): void { $this->code = self::normalizeCode($code); }
    public function setLabel(string $label): void { $this->label = $label; }
    public function setDueDays(mixed $days): void { $this->dueDays = (int) $days; }
    public function setActive(bool $active): void { $this->active = $active; }

    /**
     * Coerces the tiers to integers, drops entries that carry neither a day
     * count nor a percent (an empty form row) and sorts ascending by days.
     * Everything else — a negative percent, more than {@see MAX_TIERS}, a
     * tier beyond `dueDays` — is the validator's to refuse, so a wrong entry
     * comes back as a field error instead of being silently swallowed here.
     *
     * @param array<int, array{days?: mixed, percent?: mixed}> $discounts
     */
    public function setDiscounts(array $discounts): void
    {
        $tiers = [];
        foreach ($discounts as $tier) {
            if (!is_array($tier)) {
                continue;
            }
            $days    = (int) ($tier['days'] ?? 0);
            $percent = (int) ($tier['percent'] ?? 0);
            if ($days === 0 && $percent === 0) {
                continue;
            }
            $tiers[] = ['days' => $days, 'percent' => $percent];
        }
        usort($tiers, static fn(array $a, array $b) => $a['days'] <=> $b['days']);

        $this->discounts = $tiers;
    }

    /** «2.00» — a discount percent for a screen; string work on the integer, no float (plan §3). */
    public static function formatPercent(int $hundredths): string
    {
        $sign  = $hundredths < 0 ? '-' : '';
        $value = abs($hundredths);

        return $sign . intdiv($value, 100) . '.' . str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * «2.5» / «2» from a typed percent — the inverse of {@see formatPercent()},
     * the `TaxRate::percentToHundredths()` model.
     *
     * @throws \InvalidArgumentException not a percent with at most two decimals
     */
    public static function percentToHundredths(string $percent): int
    {
        $percent = str_replace(',', '.', trim($percent));
        if (!preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $percent, $m)) {
            throw new \InvalidArgumentException('Percent with at most two decimals expected, got: ' . $percent);
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}
