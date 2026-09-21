# z77/module-vat

VAT for z77 business modules (ADR-041): tax codes with dated rates as installation
master data, a rate lookup by service date, and the one place VAT is computed —
`VatCalculator`, in integer money (ADR-042), once per document, then carried as a
snapshot. Depends on `z77/kernel` only.
Developed in the [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-vat`); not yet a split target or on Packagist — projects consume it through
a `path` repository until it is.

Model: a **tax code** says what kind of tax (`UN` standard sales, `VM` input tax on
material — categories standard, reduced, special, zero, exempt, reverse-charge,
input-material, input-other); a **tax rate** says how much from when (`810` = 8.1 %,
valid from `2024-01-01`). A rate change is a new row, old rows stay, so a document
with a 2023 service date still resolves 7.7 %. Codes are referenced by `code`,
snapshotted at use, deactivated and never deleted (ADR-043 decision 19).

Pieces:

- `Entities/TaxCode`, `Entities/TaxRate`, `Entities/TaxCategory` — file-backed
  (`data/framework/vat/tax_codes.json`, `tax_rates.json`), seeded once on first
  install with the Swiss codes and rates since 2018 (country pack `CH`).
- `Services/VatRates` — `resolve(code, serviceDate): ResolvedRate`; throws
  `UnknownTaxCodeException` / `NoRateException`, never a silent 0 %.
- `Calculation/VatCalculator` — `calculate(currency, lines, serviceDate, priceMode)`:
  the resolved rate per line and a `TaxSummary` per code (base, rate, tax). Tax is
  computed on the sum per code, rounded half away from zero to 0.01; gross mode
  `tax = gross × rate / (10000 + rate)`. No float anywhere. The 0.05 rounding of a
  total is a document concern, not done here.
- `Services/VatMasterData` — the write rules: deactivate, never delete; `code`
  immutable after creation; a rate in effect is never removed.
- `Ui/TaxCodeControllerTrait` + `Ui/TaxCodeLayout` — the backend screen as a
  fragment; `module-backend` mounts it at `/backend/finance/tax-code/list`.

```php
$vat    = VatRates::from(DI::getUnifiedEntityManager());
$result = (new VatCalculator($vat))->calculate('CHF', [
    new VatLine('line-1', Money::fromDecimal('100.00', 'CHF'), 'UN'),
    new VatLine('line-2', Money::fromDecimal('12.35', 'CHF'),  'UR'),
], new \DateTimeImmutable('2024-03-01'), PriceMode::Net);

$result->lines[0]->rate->rate;          // 810 — the snapshot the invoice line keeps
$result->summary->byCode('UN')->tax;    // 8.10 CHF
$result->gross();                       // 120.77 CHF
```

Docs: `docs/topics/vat.md` in the framework repository.
