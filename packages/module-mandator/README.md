# z77/module-mandator

The installation's own company — the **Mandant** (owner decisions E1 / E2 of 2026-09-23). ONE
record: the letterhead of every printed document, report and e-mail (name, address, contact
data, logos), the UID and the VAT liability, and the ledger accounts the business modules post
with.

Part of the [z77 framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-mandator`). Published on Packagist since 2.0.0:
`composer require z77/module-mandator`.

## Requirements

- PHP ≥ 8.4, MariaDB (Doctrine, ADR-039)
- `z77/kernel`, `z77/persistence-doctrine`
- *suggests* `z77/module-financial` — without it the account numbers are stored unverified; an
  installation without bookkeeping still has a letterhead. No dependency on `z77/module-vat`.

Required by `z77/module-financial`, `z77/module-debtor`, `z77/module-order`, `z77/module-article`,
`z77/module-receipt` and `z77/module-creditor`.

## Enabling it in a project

1. Register the module in the PROJECT `composer.json`'s `autoload.psr-4` (the installer reads the
   project's psr-4, not the package's — `composer update` alone does not register it):

   ```json
   "Z77\\Module\\Mandator\\": ["override/z77/module/mandator/src/"]
   ```

2. `composer update`, then `php vendor/bin/z77-db migrate` (table `mandator`).
3. In the backend, Stammdaten › Firma › Mandant: «Mandant anlegen» once — the first save creates
   the record, the account fields come pre-filled with the KMU chart's numbers.

Until the module is registered and migrated, the readers (journal, invoices) show a German
sentence naming exactly these steps instead of failing.

## What the record holds

- **Letterhead** — name, two address lines, street, house number, zip, city, country, e-mail,
  phone, website, the print logo (`logo_path`) and a small e-mail logo (`email_logo_path`).
  Printed by debtor and order PDFs (`pdf/letterhead`), named in the report header, and the
  company foot of every template mail (`Mail\MandatorMailCompany`, `Mail\EmailLogo`).
- **UID and VAT** — the UID (`CHE-123.456.789`, check digit verified), `liable_to_vat` (binding
  for invoices, credit notes, quotes and confirmations) and the «Hinweis ohne MWST» printed on a
  document without VAT (`Mandator::documentVatNote()`).
- **Accounts** — twelve keys (`Mandator::ACCOUNT_KEYS`): receivable, discount, loss, rounding,
  dunning fee, input VAT (material / other), owed VAT, opening balance, annual result, private, bank transfer. Checked
  softly against the chart (`LedgerAccountCheck`, three-valued) and read ONLY through the
  modules' access points — `LedgerService::vatAccountFor()` / `yearOpeningAccount()` / `bankPostingAccount()` in
  financial, `DebtorAccounts` in debtor.
- **Document default** — the «Betreffname» every new order and invoice starts with.

Deliberately NOT here: the bank connection, IBAN and the QR-bill's creditor block (debtor's
`PaymentTarget` — the account holder often differs from the company name; the mandator's address
is only the field-by-field fallback), the base currency (`systemConfig`), a default tax code.

`CurrentMandator::find()` answers the record or `null` (none saved yet) — it never creates one;
`MandatorUnavailableException` means the module is not registered or the table is missing.
Writes go through `MandatorService::save()` / `update()`.

## Backend screen

| Menu | URL | What it does |
|---|---|---|
| Stammdaten › Firma › Mandant | `/backend/finance/mandator/edit` | one page, four tabs: Briefkopf, UID und MWST, Konten (with an ok / prüfen badge per account), Belege |

Fragment `Ui/MandatorControllerTrait` + `Ui/MandatorLayout`, mounted by `z77/module-backend`
(ADR-018). The page carries its help with a section per field (ADR-048); a base-knowledge help for
the backend assistant, «Mandant: Firmendaten, Logo, UID/MWST», lives in
`res/view/templates/Backend/Guide/companyData.help.tpl.php`. Dashboard: «Mandant MWST-pflichtig
ohne UID»; first-setup steps (`projectChecks`): «Mandant erfassen» / «Mandant: Konten zuweisen» and
«Firmenlogo für Belege / E-Mails hinterlegen».

## Not built yet

- What «nicht mehrwertsteuerpflichtig» switches off beyond documents (the journal's MwSt row, the
  VAT return) — owner decision pending.
- A default tax code — returns with a module that reads it.
- Creditor accounts — `z77/module-creditor` exists; its key (`payable`) is not in `ACCOUNT_KEYS` yet and would come with its
  reader (creditor-module-bauplan.md).
- Several mandators — not planned (one record, id 1; see the topic doc for what a multi-mandator
  build would have to change).

## Documentation

Topic doc: [`docs/topics/mandator.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/mandator.md)
— the single source of truth for this module. Harness: `tests/module-mandator.php`.

## License

MIT — see [`LICENSE`](LICENSE). Author: Peter Ruepp, [z77.ch](https://z77.ch).
