# money

2026-09-21

## entry

1. `packages/kernel/shared/src/Money/Money.php` — the value object; every amount in the business modules is one of these
2. `tests/money.php` — the harness; the wdv float bugs are its cases

## file map

SOURCE=/packages/kernel/shared/src/Money/Money.php
SOURCE=/tests/money.php
SOURCE=/docs/02-decisions/adr-042-ledger-and-money.md
SOURCE=/packages/persistence-doctrine/src/Type/MoneyType.php

## mental model

`Money` is an integer amount of minor units (Rappen, cents) plus an ISO 4217 code, immutable, with a fixed scale of two decimals. It is a domain-less primitive in `shared` (ADR-019 Rule 2) because order, debtor, financial, vat and article all compute with it. There is no float in its API: wdv-6.2.2's money bugs all came from floats, so a float handed to any entry point is a `TypeError`. Percentages are integer ratios — 8.1 % is `multiplyByRatio(810, 10000)` — and every rounding is half away from zero.

- The kernel runs without `strict_types`, which would coerce a float into an `int` parameter silently; the numeric entry points therefore take `mixed` and check the type themselves.
- `allocate()` splits by ratios with the largest remainder method: the parts always add up to the whole, for negative amounts too.
- `roundTo(5)` is the Swiss 0.05 rounding of a document total; it is a document concern (debtor), not a VAT one.
- `fromDecimal()` / `toDecimal()` are the bridge to `DECIMAL(15,2)` columns and forms; more than two decimals is refused, not rounded.
- Integer overflow raises `OverflowException` instead of turning the result into a float.
- Operations across currencies throw; `equals()` is simply false.

## rules

- When handling an amount of money anywhere in PHP → MUST use `Money`; MUST NOT use `float` or a bare `int` without currency.
- When computing a percentage of an amount → MUST use `multiplyByRatio()` with the rate in hundredths of a percent; MUST NOT multiply by a decimal rate string built from a float.
- When splitting an amount into parts (discount per tax code, instalments) → MUST use `allocate()`; MUST NOT divide and let the last part absorb the difference.
- When reading an amount from a `DECIMAL` column or a form → MUST go through `fromDecimal()`; MUST NOT cast the string to float.
- When storing an amount in a file-based entity → MUST store `minor` as integer plus the currency code.
- When storing an amount in a Doctrine entity → MUST map the `Money` property with `MoneyType` (`DECIMAL(15,2)`, see `persistence-doctrine.md`); MUST NOT map it as float or as a bare decimal string.

## known issues

- None documented.

## pending

- None documented.

## see also

- [`persistence-doctrine.md`](persistence-doctrine.md) — `MoneyType`: the Doctrine mapping `Money` ↔ `DECIMAL(15,2)`, read in the installation's base currency
- [`vat.md`](vat.md) — `VatCalculator`: the first consumer of `multiplyByRatio()`; tax on the sum per code, rounded once
