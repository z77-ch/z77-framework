# z77/module-debtor

Receivables for z77 business modules (ADR-040, plan §6): invoices and credit notes with the Swiss
QR-bill, the **Faktura-Journal** that posts them to the books in one step, open items, payments
(by hand or from the bank's camt.054 credit advices), dunning runs with fee documents, and the
document mails. The debtor is a role of a contact (`z77/module-contact`); the books are reached
only through an accounting port, so an installation may keep them elsewhere.

Part of the [z77 framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-debtor`). Not yet a split target or on Packagist — projects consume it through
a `path` repository until it is.

## Requirements

- PHP ≥ 8.4, MariaDB (Doctrine, ADR-039)
- `z77/kernel`, `z77/persistence-doctrine`
- `z77/module-contact` — the party; its address, language and e-mail are what a document snapshots
- `z77/module-vat` — tax codes, rates by service date, the per-code summary
- `z77/module-mandator` — the letterhead, the VAT liability and the five debtor accounts
  (receivable, discount, loss, rounding, dunning fee)

Suggested, optional:

- `z77/module-financial` — the books `LedgerAccountingGateway` posts into, the account checks, the
  year-close checks and the subledger reconciliation. Without it, name `NullAccountingGateway`
  under `accountingGateway` (books kept elsewhere).
- `z77/module-mailing` — documents and dunning notices by e-mail through the outbox. Without it the
  screens offer PDF and ZIP only.

## Enabling it in a project

1. Require the package (`composer require z77/module-debtor`, or the monorepo's `path`
   repository). `composer install` / `update` adds the navigation entries of
   `data/framework/routing/navigation.d/module-debtor.json` (ADR-050) and seeds the payment terms
   («30 Tage netto», «10 Tage 2 %, 30 Tage netto») and the dunning levels («Zahlungserinnerung»,
   «1. Mahnung», «2. Mahnung») once under `data/framework/debtor/`.
2. Apply the migrations (`res/migrations`, namespace `Z77\Module\Debtor\Migrations`):
   `php vendor/bin/z77-db migrate`. `data/archive/debtor/` must be writable by the web server — the
   issued PDFs are archived there (on a release server: the shared `data`).
3. Set the five debtor accounts on the mandator (Stammdaten › Firma › Mandant; KMU start values
   1100 / 3800 / 3805 / 3809 / 6950). Stammdaten › Auftragswesen › Debitoren shows whether they
   are postable.
4. Create at least one payment target with the company's IBAN and / or QR-IBAN (Stammdaten ›
   Auftragswesen › Zahlungsziele) — deliberately not seeded: an IBAN cannot be guessed.
5. Override `src/App/Config/debtorConfig.inc.php` only as a whole file (a project override
   replaces the module config, BOOT-CONFIG-001); customise the PDF layouts by copying
   `res/view/templates/pdf/*.tpl.php` under `override/` (CE principle — never edit `vendor/`).

## Backend screens

Fragments in `Ui\` (ADR-018), mounted by thin hosts in `z77/module-backend`
(`Ui/Controllers/Finance/*`), URL group `finance`.

| Menu | URL | What it does |
|---|---|---|
| Auftragswesen › Rechnungen | `/backend/finance/invoice/list` | tabs «In Fakturierung» · «Fakturiert» · «Verbucht» · «Gutschriften» · «Ersetzt» · «Gelöscht»; «+ Rechnung»; «Fakturieren …» for the ticked drafts → the result list (PDF, «Alle als ein PDF», ZIP, «Alle per E-Mail senden …»); one mask per document (draft editor, correction of an issued invoice, read-only once posted, payment form, credit note) |
| Auftragswesen › Faktura-Journal | `/backend/finance/invoice-journal/list` | the issued, not yet posted documents; «Journal drucken», «Journal verbuchen …» (always the whole journal); tab «Verbuchte Journale» |
| Auftragswesen › Debitoren | `/backend/finance/debtor/list` | open items: «Offen» · «Überfällig» · «Alle», OP-Total, «Zahlung» per row |
| Auftragswesen › Zahlungseingänge | `/backend/finance/bank-import/list` | camt.054 upload (XML or a ZIP of them), tabs «Offen» / «Erledigt», per message «Zuordnen» / «Ignorieren» / «Verbuchen» |
| Auftragswesen › Mahnungen | `/backend/finance/dunning/list` | the due list as of a day, «Mahnlauf starten», the runs; per notice «PDF öffnen» / «Per E-Mail senden …» |
| Stammdaten › Auftragswesen › Debitoren | `/backend/finance/debtor-profile/list` | the debtor profile of a contact: payment terms, price mode, subject label, dunning block, active; the account settings |
| Stammdaten › Auftragswesen › Zahlungskonditionen | `/backend/finance/payment-terms/list` | due days, up to two discount tiers, the printed text per language, «Standard» |
| Stammdaten › Auftragswesen › Zahlungsziele | `/backend/finance/payment-target/list` | the company's bank accounts: IBAN / QR-IBAN, ledger account, payee |
| Stammdaten › Auftragswesen › Mahnstufen | `/backend/finance/dunning-level/list` | level, days after due, days after the last notice, fee, notice text |

Every list and the important windows carry their help (ADR-048, `*.help.tpl.php`). Two
base-knowledge helps for the backend assistant (ADR-055) describe the workflows across screens:
`res/view/templates/Backend/Guide/invoiceWorkflow.help.tpl.php` («Ablauf: Rechnung →
Faktura-Journal → Verbuchen → Zahlung → Mahnung», including corrections) and
`Backend/Guide/bankImport.help.tpl.php` («Zahlungseingänge einlesen»).

## The workflow

1. **Draft** (`invoicing`) — no number, nothing posted, a watermarked preview; `InvoicingService::invoice()` / `reinvoice()` / `discard()`.
2. **«Fakturieren»** (`issued`) — `InvoicingService::issue()`: the gapless number `YYMMnnnn` (one
   series for every kind, by the month of the invoice date), the payment part, a content stamp
   («ID»), the PDF rendered once and archived (`Pdf\InvoiceArchive`). Nothing is posted.
3. **Faktura-Journal** — while issued, a manual invoice is corrected by a new version (same number)
   or a replacement (new number), or deleted with a reason; an invoice from an order only through
   the order. **«Journal verbuchen»** (`JournalPostingService::post()`) posts the whole journal in
   one unit of work → `final`, immutable; a correction is then a credit note.
4. **Payment** — `PaymentService` (payment, discount, loss; correctable while the fiscal year is
   open); `BankImportService` reads camt.054, matches by QR reference or the message, books on
   «Verbuchen».
5. **Dunning** — `DunningService::dueList()` / `run()`: a notice per invoice at the next active
   level, a fee as a document of its own kind (no VAT), the notice PDF with a QR-bill over open +
   fee.

The open amount is derived (gross − posted credit notes − allocations); there is no open-item
table.

## Extension points

| Config key (`debtorConfig`) | What plugs in |
|---|---|
| `accountingGateway` | `LedgerAccountingGateway` (default, module-financial) or `NullAccountingGateway` |
| `invoicePostedListeners` (any module) | called inside «Journal verbuchen» with the posted ids (module-order moves its orders to «Gebucht») |
| `openWorkChecks` | `period-close` (drafts / issued documents / unbooked bank credits block a year close), `ledger-report`, `vat-return` |
| `subledgerReconciliations` | `ReceivablesReconciliation` — open items against the receivable account at the year close |
| `contactRoles` | the role «Debitor» in the contact editor |
| `dashboard` | `DebtorDashboard` — overdue invoices, due notices, unbooked bank credits, drafts, the unposted (or blocked) journal, no payment target with an IBAN |
| `mailAttachments`, `documentMails` | `invoice-pdf`, `dunning-pdf`; the mail kinds «Rechnung», «Rechnung (ersetzt eine frühere)», «Gutschrift», «Mahnung» (texts editable under Service › E-Mail › Beleg-Mails) |

A source that is its own draft (module-order) uses `InvoicingService::preview()`,
`invoiceAndIssue()`, `classify()` / `revise()` / `flagReissue()` / `delete(…, fromSource: true)`
and the read port `Services\PaymentStates`; debtor names no source module.

## Not built yet

- **«Alle per E-Mail senden»** for a whole dunning run — notices are mailed one by one (⋮ per
  notice).
- **Dunning notice PDFs are not stored** — rendered on request; invoices and credit notes are
  archived.
- **Creditor module** (supplier invoices, payments out) — a next-stage project, not part of this
  package.
- **VAT return** from the posted documents (`vat-return` check is registered, asked by nobody yet).
- **Foreign-currency documents** — currency and rate columns exist, amounts are base currency only.
- **A cross-message list of unbooked bank credits** — the screen is per camt.054 message.
- **Prepayment invoices** (a payment before the invoice) and **the PDF in the DMS**.

## Tests

`tests/module-debtor.php` — the harness against MariaDB (throwaway schema; migrations through
`z77-db` with a clean `diff`, the services, the screens through host doubles, the helps). It prints
its check count; `--from=BM` / `--from=FJ` run a late section from a saved checkpoint.

## Documentation

- Topic doc: [`docs/topics/debtor.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/debtor.md)
- Faktura-Journal: [ADR-054](https://github.com/z77-ch/z77-framework/blob/main/docs/02-decisions/adr-054-faktura-journal.md)
- Plan: [`order-debtor-financial-bauplan.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/03-development/order-debtor-financial-bauplan.md) §6

## License

MIT — see the repository's `LICENSE`.
