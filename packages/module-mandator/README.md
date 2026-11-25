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
  phone, website. Printed by debtor and order PDFs (`pdf/letterhead`), named in the report
  header, and the company foot of every template mail (`Mail\MandatorMailCompany`).
- **Company logo** — the window «Firmenlogo» (`Logo\*`): one upload (or one per use) for the PDF
  letterhead, the mails, the login and the website; the server derives the variants with gd and
  stores them in the DMS (module-dms, suggested; the website variant in a public partition). The
  DMS documents are the only source since 2026-11-20 — the old path fields `logo_path` /
  `email_logo_path` are no longer read (columns kept until a later release drops them).
- **UID and VAT** — the UID (`CHE-123.456.789`, check digit verified, required while liable; printed
  in the PDF letterhead with « MWST» only while liable), `liable_to_vat` (binding
  for invoices, credit notes, quotes and confirmations) and the «Hinweis ohne MWST» printed on a
  document without VAT (`Mandator::documentVatNote()`).
- **VAT return settings** (ADR-041 addendum 2026-11-24) — method (effective | net tax rate),
  consideration (agreed | received), rhythm (quarter | month | year), «Rhythmus gilt ab» (since
  2026-11-25: a rhythm change from a 1 January, the earlier years keep their rhythm — allowed only
  once every VAT period before it is posted), «Abrechnung ab» / «bis»; null = not set. Read as one
  value object, `CurrentMandator::vatSettings()` → `Services\VatSettings` (the rhythms as
  `rhythmsByYear()`). Its VAT accounts are the ones financial LOCKS from «Abrechnung ab» on.
- **Accounts** — sixteen keys (`Mandator::ACCOUNT_KEYS`): receivable, discount, loss, rounding,
  dunning fee, input VAT (material / other), owed VAT, opening balance, annual result, private, bank transfer,
  VAT settlement, VAT-return rounding, «Vorsteuer, noch nicht abgerechnet» (KMU 1179, since
  2026-11-25), payment charges. Checked
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
«Firmenlogo hinterlegen» (opens the logo window; «Später / kein Logo» silences it).

## Not built yet

- What «nicht mehrwertsteuerpflichtig» switches off beyond documents (the journal's MwSt row, the
  VAT return) — owner decision pending.
- The account 2209 «MWST-Korrektur, noch nicht abgerechnet» (the year-end correction of the VAT
  return) — decided 2026-11-25, comes with the correction entries.
- Nothing posts on 1179 yet — «Vorsteuer nachholen» on receipts and creditor invoices is decided,
  not built (module-financial).
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
