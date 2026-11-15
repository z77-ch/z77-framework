# z77/module-creditor

Payables for z77 business modules. A **creditor** is a contact (`z77/module-contact`) with a
creditor profile: the supplier's UID, **several IBANs** (an IBAN may be shared by several
creditors — a billing or collection company) and the **defaults** a new invoice line of that
supplier starts with (expense account, VAT code or «ohne MWST», «Bezahlt mit»). The role
«Kreditor» is switched on in the contact editor; the master data are edited under
Stammdaten › Finanzen › Kreditoren.

The supplier's **PDF invoice** is uploaded into the **Kreditorenjournal** (Finanzen): stored
unaltered in the DMS, read once only (SHA-256), checked and split into lines in a window with the
PDF beside the fields; a suspected duplicate (same creditor + invoice number, or same IBAN +
reference) is created and blocked until the owner decides. The owner sets **«bezahlt am»** (per
invoice or for ticked rows) and posts the paid invoices with **«Journal verbuchen …»** up to a
cut-off on the payment date — the direct variant: ONE entry per invoice, expense (+ input VAT) to
the payment method's account, dated with the payment date (cash principle, ADR-059). A posted
invoice is immutable; its PDF is kept 10 years in the DMS.

Source of the design: `docs/03-development/creditor-module-bauplan.md`, owner answers §11 of
2026-11-15. Part of the [z77 framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-creditor`). Not yet a split target or on Packagist.

## Requirements

- PHP ≥ 8.4, MariaDB (Doctrine, ADR-039)
- `z77/kernel` (the shared `Z77\Shared\Payment\Iban` / `QrReference` / `ScorReference`),
  `z77/persistence-doctrine`
- `z77/module-contact` — the party and the role registry
- `z77/module-financial` — the chart, the ledger (`LedgerService::post()`), `GrossEntryBuilder`,
  the open-work registry of the period close
- `z77/module-receipt` — the payment methods («Bezahlt mit», their usage count through the
  registry `paymentMethodUsage`), the field rules, the retention rule
- `z77/module-vat`, `z77/module-mandator` — tax codes, the UID check, the VAT liability
- `z77/module-dms` — holds the supplier PDFs (partition «Kreditorenrechnungen»)

## Enabling it in a project

1. Register the module in the project's `config/moduleManager` modules (`'creditor' => []`) and
   in the project `composer.json`'s `autoload.psr-4`:

   ```json
   "Z77\\Module\\Creditor\\": ["override/z77/module/creditor/src/"]
   ```

2. `composer update`, then `php vendor/bin/z77-db migrate` (tables `creditor_profile`,
   `creditor_iban` — migration `Version20261115100000`; `creditor_intake`, `creditor_invoice`,
   `creditor_invoice_line`, `creditor_journal_posting` — migration `Version20261115120000`).
3. In the backend: create the payment methods first (Stammdaten › Finanzen › Zahlungsarten),
   then switch on «Kreditor» on a contact and fill UID, IBANs and defaults (or leave the defaults
   empty — the first complete invoice sets them). Upload PDFs under Finanzen › Kreditorenjournal.
   The largest PDF is `creditorConfig['intake']['maxBytes']` (20 MiB); PHP's `upload_max_filesize`
   / `post_max_size` must allow it.
4. Open the fiscal year (Finanzen) — «Journal verbuchen …» posts into it. `creditorConfig['postingVariant']`
   is `direct` (the only variant built; another value is refused).

## Backend screen

| Menu | URL | What it does |
|---|---|---|
| Finanzen › Kreditorenjournal | `/backend/finance/creditor-journal/list` | «Journal verbuchen …» (action cell, the posting window with the cut-off); «PDF hochladen» in the toolbar (several, drag and drop); tabs «Im Journal» · «Gesperrt» · «Verbuchte Journale»; «Bezahlt am …» for ticked rows; the invoice window with the PDF, head, «Bezahlt am» and lines; «Kein Doppel — übernehmen», «Kreditor übernehmen …», «Löschen …» / «Verwerfen …» |
| Stammdaten › Finanzen › Kreditoren | `/backend/finance/creditor-profile/list` | the creditors: search by name, UID, IBAN; the window for UID, defaults and IBANs; invoice count; «Aktiv» |
| Kontakte › Kontakt › Rollen | (module-contact's dialog) | the switch «Kreditor»: on = empty profile, off = delete it (refused while invoices name it — deactivate instead) |

Fragments in `Ui\` (ADR-018), mounted by `z77/module-backend`. The lists and the windows carry
their help (ADR-048); `Backend/Guide/creditorWorkflow.help` is the KI-Assistent's overview.

## Pieces

- `CreditorProfile` / `CreditorIban` (Doctrine) — the profile per contact and its IBANs.
- `Services\CreditorProfileService` — `open()`, `update()` with optimistic locking and the IBAN
  sync, `setActive()`, `delete()`; the defaults stamp.
- `Validators\CreditorProfileValidator` — UID, defaults (through module-receipt's `ReceiptFields`),
  IBAN rows.
- `Roles\CreditorContactRole` — the role «Kreditor» in the contact editor.
- `CreditorIntake` / `CreditorInvoice` / `CreditorInvoiceLine` / `CreditorJournalPosting`
  (Doctrine) — the file that came in, the supplier invoice with its lines, the posting protocol (1c).
- `Services\CreditorIntakeService::receive()` — the one intake path: sniff, SHA-256, DMS store,
  the invoice with one line on the creditor's defaults.
- `Services\CreditorInvoiceService` — `update()` (head + lines, «bezahlt am», defaults, VAT forcing
  for a mandator not liable to VAT, duplicate check, defaults once), `markPaid()` (bulk), `release()`,
  `delete()` (unposted: completely, the hash freed; a discarded duplicate keeps it), `adoptCreditor()`.
- `Posting\CreditorPostingStrategy` / `DirectPosting` / `CreditorPostingVariant` — how an invoice
  reaches the ledger; the variant per installation, snapshotted on the invoice (ADR-059).
- `Services\CreditorJournalPostingService` — «Journal verbuchen»: `preview()` / `post()` up to a
  cut-off on the payment date, all or nothing, the protocol; `Services\CreditorRetention` — the
  10-year deadline of a posted PDF.
- `Close\UnpostedCreditorInvoicesCheck` — the open-work answer (period close, VAT return, report).
- `Services\CreditorPaymentMethodUsage` — the invoices' share of the payment methods' usage count.

## Not built yet

- The collective-account variant (2000 payables) — the strategy is the place; the invoice carries
  the columns.
- Recognition (text layer of a QR-bill, then the AI through z77/kibot) and the folder job — the

  invoice carries the columns already; «Kreditor übernehmen …» is built for the proposals it will make.
- Invoices in a foreign currency (the columns exist; CHF only for now).

## Documentation

Topic doc: [`docs/topics/creditor.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/creditor.md)
— the single source of truth for this module. Harness: `tests/module-creditor.php`.

## License

MIT — see the repository's `LICENSE`.
