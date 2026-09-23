# z77/module-debtor

Receivables for z77 business modules (ADR-040, plan §6): the debtor side of a contact and the
master data an invoice, a payment and a dunning notice are built from. Depends on `z77/kernel`,
`z77/persistence-doctrine` (ADR-039), `z77/module-vat` and `z77/module-contact`, and only
*suggests* `z77/module-financial` — an installation may keep its books elsewhere.
Developed in the [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-debtor`); not yet a split target or on Packagist — projects consume it through
a `path` repository until it is.

State: **P3 part 2 — master data and the documents.** Part 1 built the master data; part 2 adds
`InvoicingService` (invoices and credit notes, the states `invoicing` / `final`, the 0.05 rounding
line, the number ranges, the document snapshot) and the accounting port. PDF with QR-bill and the
document screens are part 3; payments, discount and loss, CAMT.054 and the dunning runs are P4.
Nothing here is built without a caller in this state.

Model:

- `DebtorProfile` (Doctrine, table `debtor_profile`) — the debtor-specific part of a
  `Contact`, keyed by its id, at most one per contact: default payment terms (by code), dunning
  block, active. **No language** (the contact carries it) and **no currency** (Q6 decided against
  foreign-currency invoicing; §6.2 puts currency and rate on the document). Deactivated, never
  deleted.
- `PaymentTerms` (file, `data/framework/debtor/payment_terms.json`) — code, label, due days, up
  to two discount tiers (days + percent as an integer in hundredths, 2 % = `200`) and the
  document text per language. Seeded with «30 Tage netto» and «10 Tage 2 %, 30 Tage netto».
- `PaymentTarget` (file, `payment_targets.json`) — code, label, IBAN or QR-IBAN (shape, MOD-97-10
  check digits and CH / LI origin validated; a QR-IBAN is recognised by its IID 30000–31999) and
  the ledger account that bank account is booked on. **Not seeded** — an IBAN cannot be guessed.
- `DunningLevel` (file, `dunning_levels.json`) — code, label, level, days after due, fee in minor
  units and the notice text per language. **No VAT on a dunning fee** (§6.5), so no tax code.
  Seeded with «Zahlungserinnerung», «1. Mahnung» and «2. Mahnung».

All three file entities follow the reference rule (ADR-043 decision 19): referenced by `code`,
code immutable, deactivated and never deleted, no foreign key across the two drivers.

Documents (part 2):

- `Invoice` (Doctrine, table `invoice`) — ONE entity for invoices and credit notes (`kind`), the
  bare number of its range (`invoice` / `credit-note`), the state (`invoicing` → `final`), the
  contact by id with the address as a snapshot (`AddressSnapshot`, ten flat `addr_*` columns), the
  language, invoice and service dates, currency and rate fields (base currency only, Q6), the
  payment terms as applied (due date, tiers, printed sentence), the totals, the opaque origin and
  the ledger reference `{year}/{number}` once posted. No setters; immutable when `final`.
- `InvoiceLine` (`invoice_line`) — `service` / `lump-sum` / `text` / the one system `rounding` line,
  one level of parent line (a package with its contents at 0.00), quantity as `DECIMAL(12,3)`,
  discount in hundredths, the stored amount, tax code with rate and label, revenue account by number.
- `InvoiceTax` (`invoice_tax`) — the tax summary per code, stored: category, label, rate, base, tax.

Pieces:

- `Services/InvoicingService` — the single entry for every source: `invoice($draft)` (number once,
  nothing posted), `reinvoice($id, $version, $draft)` (same number, new snapshot, only while
  `invoicing`), `finalize([['id' => …, 'version' => …], …])` (batch, one unit of work, posted
  through the port, a stale version or an already final document refuses the batch),
  `openAmount()` (derived: gross − final credit notes). Correction of a final document = a credit
  note, which follows the VAT rate of the original supply.
- `Accounting/AccountingGateway` — the port (`post(PostingRequest): ?string`) with debtor's own
  `PostingRequest` / `PostingLine` (a line names an account number or a VAT category);
  `LedgerAccountingGateway` (default → module-financial's `LedgerService`) and `NullAccountingGateway`
  (books kept elsewhere), selected by `debtorConfig → accountingGateway`.

- `Services/DebtorProfileService` — `save()`, `update($profile, $values)`, `setActive()`,
  `forContact()`. Validates a detached clone before touching a managed entity (ADR-039
  decision 9); the unique contact index becomes a field error under a race. No delete.
- `Services/DebtorMasterData` — the one write path of the three file entities: validates, refuses
  a changed code, deactivates instead of deleting.
- `Services/DebtorAccounts` — the ONE reader of the five debtor accounts, which live on the
  MANDATOR record since owner decision E2 (2026-09-23; `z77/module-mandator`, edited under
  `/backend/finance/mandator` — `receivable` 1100, `discount` 3800, `loss` 3805, `rounding` 3809,
  `dunningFee` 6950 as KMU start values; the chart gained `3809 «Rundungsdifferenzen»` for this).
  `number()` / `postableNumber()` refuse a missing mandator, an empty field or a non-postable
  account with a German message naming the key and the mandator, at the point of use; a leftover
  `debtorConfig → debtorAccounts` in a project override is refused loudly.
- The soft account check `LedgerAccountCheck` (three-valued, `null` = module-financial not usable
  here) moved to `module-mandator` with the account settings; debtor imports it. The ledger adapter
  `LedgerAccountingGateway` is now the ONE class in debtor that knows financial's name.
- `Services/Iban` — normalize, format, shape, MOD-97-10 check digits, CH / LI origin, IID and
  QR-IBAN detection.
- `Ui/*ControllerTrait` + `Ui/*Layout` — four backend fragments (ADR-018), mounted by
  `module-backend` under `/backend/finance/payment-terms`, `/payment-target`, `/dunning-level`
  and `/debtor`. German labels, no JavaScript.

Migrations: `res/migrations`, applied with `vendor/bin/z77-db migrate` (the second one also
creates the number ranges `invoice` and `credit-note`).
Harness: `tests/module-debtor.php` (MariaDB, throwaway schema, 272 checks).
Topic doc: [`docs/topics/debtor.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/debtor.md).
