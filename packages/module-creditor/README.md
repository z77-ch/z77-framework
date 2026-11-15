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
reference) is created and blocked until the owner decides. Next (not built yet): the owner sets
«bezahlt am» and posts the journal into the books (`z77/module-financial`).

Source of the design: `docs/03-development/creditor-module-bauplan.md`, owner answers §11 of
2026-11-15. Part of the [z77 framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-creditor`). Not yet a split target or on Packagist.

## Requirements

- PHP ≥ 8.4, MariaDB (Doctrine, ADR-039)
- `z77/kernel` (the shared `Z77\Shared\Payment\Iban` / `QrReference` / `ScorReference`),
  `z77/persistence-doctrine`
- `z77/module-contact` — the party and the role registry
- `z77/module-financial` — the chart the default account is checked against; later the ledger
- `z77/module-receipt` — the payment methods («Bezahlt mit») and the field rules
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

## Backend screen

| Menu | URL | What it does |
|---|---|---|
| Finanzen › Kreditorenjournal | `/backend/finance/creditor-journal/list` | «PDF hochladen» (several, drag and drop); tabs «Im Journal» · «Gesperrt» · «Verbuchte Journale»; the invoice window with the PDF, head and lines; «Kein Doppel — übernehmen», «Kreditor übernehmen …», «Löschen …» |
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
- `Services\CreditorInvoiceService` — `update()` (head + lines, defaults, VAT forcing for a
  mandator not liable to VAT, duplicate check, defaults once), `release()`, `delete()`,
  `adoptCreditor()`.

## Not built yet

- «Bezahlt am …», «Journal verbuchen …» and the posting strategy (direct now, collective later).
- Recognition (text layer of a QR-bill, then the AI through z77/kibot) and the folder job — the
  invoice carries the columns already; «Kreditor übernehmen …» is built for the proposals it will make.
- Invoices in a foreign currency (the columns exist; CHF only for now).

## Documentation

Topic doc: [`docs/topics/creditor.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/creditor.md)
— the single source of truth for this module. Harness: `tests/module-creditor.php`.

## License

MIT — see the repository's `LICENSE`.
