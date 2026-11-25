# z77/module-vat

Swiss VAT (MWST) for z77 business modules (ADR-041): tax codes with dated rates as installation
master data, a rate lookup by service date, and the ONE place VAT is computed — `VatCalculator`,
in integer money (ADR-042), once per document, then carried as a snapshot.

Part of the [z77 framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-vat`). Published on Packagist since 2.0.0:
`composer require z77/module-vat`.

## Requirements

- PHP ≥ 8.2
- `z77/kernel` only — the codes and rates are file-based (`data/framework/vat/`), no database.

Used by `z77/module-debtor` (invoices, credit notes), `z77/module-order` (quotes, orders),
`z77/module-financial` (journal lines), `z77/module-receipt` (receipts), `z77/module-article`
(an article's tax code) and `z77/module-creditor` (supplier invoices).

## Enabling it in a project

Require the package and register it in the project `composer.json`'s `autoload.psr-4`:

```json
"Z77\\Module\\Vat\\": ["override/z77/module/vat/src/"]
```

`composer update` registers the module and deploys the Swiss seed **once**
(`tax_codes.default.json`, `tax_rates.default.json` → `data/framework/vat/`): an installation that
already has the files keeps them. The backend screen's menu entry «MWST-Codes» hangs under
module-financial's «Stammdaten › Finanzen» and appears once that module is installed.

## Model

- **Tax code** (`TaxCode`): `code`, `country`, `category`, `label`, `active`. The category says
  what kind of tax — standard, reduced, special, zero, exempt, reverse-charge, input-material,
  input-other and the three input-tax corrections input-subsequent (410), input-correction (415),
  input-reduction (420), which only a manual entry posts. Referenced everywhere by `code`, snapshotted at use, deactivated and never
  deleted; the code is immutable (ADR-043 decision 19).
- **Tax rate** (`TaxRate`): `code`, `validFrom`, `rate` in hundredths of a percent (`810` =
  8.1 %), `createdOn`. A rate change is a NEW row from a date; old rows stay, so a document with
  a 2023 service date still resolves 7.7 %. A rate in effect is never edited or removed.
- **Swiss seed**: `UN` 8.1 %, `UR` 2.6 %, `US` 3.8 % (7.7 / 2.5 / 3.7 % before 2024), `UE` zero,
  `UL` supplies abroad (field 221), `UA` exempt, `VM` / `VI` input tax at the standard rate,
  `BM` / `BI` reverse charge at the normal and `BM2` / `BI2` at the reduced rate, `VE` / `VK` / `VN`
  the input-tax corrections (rate 0). No reduced-rate input codes — an installation adds them when
  it needs them. An installation from before 2026-11-25 gets the codes the VAT return maps by name
  once (`Services\VatSeedUpgrade`, run by the tax-code list and the setup check); an existing code
  is never changed.

## Usage

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

- Tax is computed on the SUM per code and rounded once, half away from zero to 0.01; gross
  mode `tax = gross × rate / (10000 + rate)`. No float anywhere. The 0.05 rounding of a document
  total is the document's job (a rounding line in debtor).
- `VatRates::resolve()` throws `UnknownTaxCodeException` / `NoRateException` — never a silent 0 %.
- Writes go through `Services/VatMasterData` (`saveCode()`, `addRate()`, `removeRate()`), which
  enforces the rules above and the backdating rule.
- For editors: `TaxCodeRepository::selectable()` + `partials/taxCodeSelect` (active codes plus
  the ones a record carries), `VatRates::shownRates()` / `schedule()` for the rate shown per day.

## Backend screen

| Menu | URL | What it does |
|---|---|---|
| Stammdaten › Finanzen › MWST-Codes | `/backend/finance/tax-code/list` | codes with their rate history; add a code, «Neuer Satz gültig ab …», remove a rate not yet in effect, activate / deactivate |

Fragment `Ui/TaxCodeControllerTrait` + `Ui/TaxCodeLayout`, mounted by `z77/module-backend`
(ADR-018). The list carries its help (ADR-048); a base-knowledge help for the backend assistant,
«MWST: Codes, Sätze, was die Abrechnung (noch) kann», lives in
`res/view/templates/Backend/Guide/vatBasics.help.tpl.php`.

## Not built yet

- **Parts of the VAT return** (MWST-Abrechnung, plan P5 part 2) — the ESTV form mapping (country
  pack `CH`: code + rate → form field) is here; the return is read, posted and lifted in
  `z77/module-financial` (Finanzen › MWST-Abrechnungen) and computes reverse charge and the
  input-tax corrections; correction entries of earlier periods, reverse charge and the «nur
  Steuer» line on receipts and creditor invoices and the yearly overview are built there too
  (2026-11-25). No tax advice («Keine Steuerberatung»): the return is an aid to self-assessment —
  it is filed in the ESTV ePortal, and the taxable person stays responsible. Supported: the
  effective method, agreed consideration, by quarter, month or year; the details and for whom the
  first version is not suitable:
  [`docs/01-handbook/vat-return.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/vat-return.md).
  Not built, by decision: partial input-tax deduction (reverse charge is computed with full deduction;
  a cut is booked by hand with `VK`), the net tax rate and flat tax rate methods, received
  consideration.
- Import of other changed seed codes / rates into an existing installation (ADR-032) — beyond the
  upgrade step of the VAT return's codes, a new code is added in the backend.
- Other countries — the model is ready (a country pack is data plus a form mapping), none ships.

## Documentation

Topic doc: [`docs/topics/vat.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/vat.md)
— the single source of truth for this module. Binding model: ADR-041. Harness:
`tests/module-vat.php`.

## License

MIT — see [`LICENSE`](LICENSE). Author: Peter Ruepp, [z77.ch](https://z77.ch).
