# ADR-041 — VAT model: tax codes with dated rates, computed once, carried

**Status:** `[APPROVED]` — approved by the owner 2026-09-21 (P0 of [`order-debtor-financial-bauplan.md`](../03-development/order-debtor-financial-bauplan.md)); addendum to decision 7 approved by the owner 2026-09-22
**Date:** 2026-09-21 · addendum 2026-09-22 (rate snapshot on the journal line)

---

## Context

In wdv-6.2.2 VAT is a set of float rates in code and configuration. Tax is computed per line in
floats, recomputed where a document is shown or posted, and a rounding difference is smeared onto the
last rate. A rate change (CH 2018, 2024) is a code change, and old documents recompute against the new
rate unless someone remembers otherwise. The findings are in
[`order-financial-review-2026-09-18.md`](../03-development/order-financial-review-2026-09-18.md).

The owner decided (plan §1, D4/D5): an own `module-vat`; rates are data managed in the backend with a
«valid from»; the effective and the agreed method are built, net tax rate and received consideration
are not built but must fit the model unchanged; discount and bad-debt loss correct VAT from day one;
the European model from day one, with only the country pack `CH` built.

## Decision

1. **`module-vat`, file-based.** A few dozen rows, managed in the backend, seeded once on first install
   with the Swiss codes and rates since 2018 (ADR-024), so historical documents resolve.
2. **Tax code and rate are separate.** A `TaxCode` (`code`, `country`, `category`, `label`, `active`)
   says *what kind* of tax; a `TaxRate` (`code`, `validFrom`, `rate`) says *how much from when*. A rate
   change is a **new row**; old rows stay. Categories: standard, reduced, special, zero, exempt,
   reverse-charge, input-material, input-other.
3. **Rates are integers in hundredths of a percent** (8.1 % = `810`); amounts are `Money` (integer
   minor units, ADR-042). No float anywhere in the calculation.
4. **The rate is resolved by the service date** — legally correct, and what wdv already does.
5. **`VatCalculator` computes once per document.** Input: lines (net or gross amount, tax code),
   service date, price mode. Output: the resolved rate per line and a **tax summary per code**
   (base, rate, tax).
   - Tax is computed on the **sum per code**, rounded half away from zero to 0.01 (commercial
     rounding; a negative tie −0.005 → −0.01, so a credit note mirrors its invoice — clarified
     2026-09-21, owner; the earlier wording said «half-up») — not per line and summed.
   - Gross mode: tax = gross × rate / (10000 + rate).
   - Rounding the document total to 0.05 is **not** a VAT concern; debtor adds it as a separate
     rounding line (plan §6.2).
6. **Computed once, then carried.** The invoice stores its lines with rate and the tax summary as a
   snapshot. Nothing downstream — PDF, posting, VAT return, discount, loss — recomputes VAT from the
   code table; each reads the snapshot. A later rate change or a corrected tax code can never alter an
   issued document or its postings.
7. **Net posting method.** Every journal line with VAT carries `taxCode`, `taxBase` and `taxAmount`;
   revenue and expense are posted net, the tax to its own account per code. The VAT return is the sum
   of these lines per code and rate (plan §5.6) — it reads the ledger, not the invoices.
8. **Discount and loss correct VAT proportionally** per code of the invoice's tax summary
   (`Money::allocate`, no lost Rappen), posted with the allocation (plan §6.3).
9. **Country packs map codes to a tax authority's form.** Pack `CH` maps code (+ rate) to the ESTV form
   fields and is used only by financial's VAT return. Another country is a new pack, not a model
   change.
10. **Methods built: effective and agreed.** Net tax rate (Saldosteuersatz) and received consideration
    (vereinnahmt) are not built; the model carries them without change — a net-tax-rate installation
    is a different pack and different settlement posting on the same lines, received consideration a
    different selection date on the same lines.

## Reasoning

- **Rates as dated data** make a rate change a backend entry on the day, not a release — and keep every
  earlier document resolvable against the rate that applied then.
- **Sum per code, then round** is what the ESTV form adds up; rounding per line and summing produces
  the Rappen differences wdv smears onto the last rate.
- **Computing once** is the only way an issued document stays immutable in substance, not just in
  its PDF. Recomputing at posting or return time would let a table edit reach into filed periods.
- **Net posting with tax data on the line** makes the VAT return a query on the ledger. The same lines
  answer the effective return today and a net-tax-rate or received-consideration return later.
- **A separate module** keeps VAT out of financial's and debtor's code: financial sums what it is
  given, debtor asks the calculator, order uses it for display only.

## Consequences

- VAT is computed in exactly one class. order shows VAT on quotes and orders by calling it, for
  display only.
- Documents and journal lines reference a tax code by `code`, snapshotted at use; a code is deactivated,
  never deleted (the reference rule for file-based master data: ADR-043, decision 19).
- The wdv float bugs — strict float compares, unrounded VAT, rounding smeared onto the last rate —
  become test cases (plan §3).
- A rounding difference between the VAT return and the ledger is posted to the VAT-return rounding
  account and shown, never absorbed (plan §5.6). It is a different account from the invoice-rounding
  account of the 0.05 line.
- The migration imports wdv's journal lines with their tax data as booked (P5b); the per-quarter VAT
  returns must match wdv's (acceptance, plan §8).

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Rates in code or config (wdv) | A rate change becomes a release; old documents recompute against the new rate |
| One row per code with the rate on it | A rate change overwrites history |
| Tax per line, rounded, then summed | Rappen differences against the form; what wdv smears onto the last rate |
| Recompute VAT at posting or return time | A table edit could reach into issued documents and filed periods |
| Gross posting with period-end separation | The VAT return could no longer be read line by line from the ledger |
| Build net tax rate and received consideration now | No installation uses them today; the model carries them, building them is waste until one does |
| Rate resolution by invoice date | Legally wrong for a service rendered before a rate change |

## Addendum 2026-09-22 — the journal line carries the rate (approved by the owner)

Decision 7 names `taxCode`, `taxBase` and `taxAmount` on a journal line. The line carries a
**`taxRate` snapshot** as well (hundredths of a percent, like decision 3): the VAT return groups by
code **and rate** (plan §5.6), and around a rate change (CH 2023/2024) two rates of one code meet in
one period — code, base and amount alone could not tell them apart, and resolving the rate again from
the table at return time is exactly the recomputation decision 6 rules out. An addition to decision 7,
not a contradiction: the rate comes from the document's snapshot like the other three fields.

The **label is not snapshotted on a journal line**: label snapshots belong to documents (an invoice
prints the label as it read then), a ledger line reports by code + rate. Consistent with the
reference rule in [`vat.md`](../topics/vat.md) for a Doctrine reference to a tax code. Built in
`module-financial` P2 part 2 (`journal_line.tax_rate`, [`financial.md`](../topics/financial.md)).
