# z77/module-mandator

The installation's own company — the **Mandant** (owner decisions E1 / E2 of 2026-09-23). ONE
record: the letterhead of the printed reports and letters (name, address, contact data, logo), the
UID and the VAT liability, and the ledger accounts the business modules post with. Depends on
`z77/kernel`, `z77/persistence-doctrine` (ADR-039) and `z77/module-vat`, and only *suggests*
`z77/module-financial` — an installation without bookkeeping still has a letterhead. Developed in
the [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-mandator`); not yet a split target or on Packagist.

What it is, and what it deliberately is not:

- **The letterhead** — `Mandator` (Doctrine, table `mandator`): name, two address lines, street,
  house number, zip, city, country, e-mail, phone, website, logo path; the UID (`CHE-123.456.789`,
  check digit verified); `liableToVat` (a flag only in this state) and a default tax code (by code
  into module-vat).
- **The account settings** (E2): receivable, discount, loss, rounding, dunning fee, input VAT
  (material / other), owed VAT — the numbers that were `financialConfig → vatAccounts` and
  `debtorConfig → debtorAccounts`. Edited in the backend, pre-filled from the KMU chart on the first
  save, checked softly against module-financial (`LedgerAccountCheck`, three-valued). Read ONLY
  through the two access points that existed before: `LedgerService::vatAccountFor()` and debtor's
  `DebtorAccounts` — no module asks the record for an account directly.
- **Not the bank connection.** IBAN, bank name and the creditor block of the QR-bill belong to
  debtor's `PaymentTarget`: the account holder as registered with the bank often differs from the
  company name. The mandator is the letterhead, the payment target is the payee. The mandator's
  address is the fallback that creditor block takes field by field when left empty (P3 part 3).
- **One record.** `CurrentMandator::find()` answers it or `null` — never creates one; a missing
  mandator prints an empty letterhead and makes an account resolver refuse with a message, nothing
  fatals. The table carries an id, but nothing filters by mandator and no other table prepares a
  column for it — several mandators are a build of their own.

Backend: one page, `/backend/finance/mandator/edit` (fragment `Ui/MandatorControllerTrait`, host in
module-backend). Harness: `tests/module-mandator.php`. Topic: `docs/topics/mandator.md`.
