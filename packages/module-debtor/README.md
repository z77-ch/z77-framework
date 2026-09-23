# z77/module-debtor

Receivables for z77 business modules (ADR-040, plan §6): the debtor side of a contact and the
master data an invoice, a payment and a dunning notice are built from. Depends on `z77/kernel`,
`z77/persistence-doctrine` (ADR-039), `z77/module-vat` and `z77/module-contact`, and only
*suggests* `z77/module-financial` — an installation may keep its books elsewhere.
Developed in the [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-debtor`); not yet a split target or on Packagist — projects consume it through
a `path` repository until it is.

State: **P3 part 1 — master data only.** `InvoicingService`, invoices, credit notes, PDF with
QR-bill and the accounting gateway are parts 2 and 3; payments, discount and loss, CAMT.054 and
the dunning runs are P4. Nothing here is built without a caller in this state.

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

Pieces:

- `Services/DebtorProfileService` — `save()`, `update($profile, $values)`, `setActive()`,
  `forContact()`. Validates a detached clone before touching a managed entity (ADR-039
  decision 9); the unique contact index becomes a field error under a race. No delete.
- `Services/DebtorMasterData` — the one write path of the three file entities: validates, refuses
  a changed code, deactivates instead of deleting.
- `Services/DebtorAccounts` — the ONE reader of `debtorConfig → debtorAccounts`
  (`receivable` 1100, `discount` 3800, `loss` 3805, `rounding` 3809, `dunningFee` 6950 — from the
  KMU chart, which gained `3809 «Rundungsdifferenzen»` for this).
  `postableNumber()` refuses a missing or non-postable account with a German message naming the
  key, at the point of use.
- `Services/LedgerAccountCheck` — asks module-financial whether an account may be posted to, and
  answers `null` when that module is not usable here (the class must autoload AND `financial` must
  be a registered module). The read half of the `AccountingGateway`
  boundary (part 2) and the only class in debtor that knows financial's name.
- `Services/Iban` — normalize, format, shape, MOD-97-10 check digits, CH / LI origin, IID and
  QR-IBAN detection.
- `Ui/*ControllerTrait` + `Ui/*Layout` — four backend fragments (ADR-018), mounted by
  `module-backend` under `/backend/finance/payment-terms`, `/payment-target`, `/dunning-level`
  and `/debtor`. German labels, no JavaScript.

Migration: `res/migrations`, applied with `vendor/bin/z77-db migrate`.
Harness: `tests/module-debtor.php` (MariaDB, throwaway schema, 173 checks).
Topic doc: [`docs/topics/debtor.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/debtor.md).
