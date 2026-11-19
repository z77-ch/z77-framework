# z77/module-receipt

The receipt journal for z77 business modules: a phone captures a receipt — photo, gross amount,
text, optionally a **template** (what was bought: debit account and VAT code) and a **payment
method** («Bezahlt mit»: the credit account) — into a JOURNAL; the ledger sees nothing yet. In the
backend the owner reviews, corrects, splits a mixed-VAT receipt and posts every receipt up to a
cut-off date in one go («Journal verbuchen»): one generated journal entry per receipt, all or
nothing — the flow of debtor's Faktura-Journal (ADR-054).

Source of the design: `docs/03-development/gateway-app-kanal-bauplan.md`, owner decisions
E1–E16 and T2 / T4 of 2026-11-12.

Part of the [z77 framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-receipt`). Not yet a split target or on Packagist.

## Requirements

- PHP ≥ 8.4, MariaDB (Doctrine, ADR-039)
- `z77/kernel`, `z77/persistence-doctrine`
- `z77/module-financial` — accounts, `GrossEntryBuilder` (the VAT split of a gross amount), `LedgerService::post()`
- `z77/module-vat`, `z77/module-mandator` — tax codes, the VAT accounts
- `z77/module-dms` — the photo is stored in the document drive («Belege/<year>/<month>»)
- `z77/module-api` (installed by the project) — the phone's gateway and the app shell `/app`; this
  package only DECLARES its API targets and app screen in `receiptConfig`, module-api finds them.

## Enabling it in a project

1. Register the module in the project `composer.json`'s `autoload.psr-4`:

   ```json
   "Z77\\Module\\Receipt\\": ["override/z77/module/receipt/src/"]
   ```

2. `composer update`, then `php vendor/bin/z77-db migrate` (tables `receipt`,
   `receipt_journal_posting`).
3. In the backend: create the receipt templates and payment methods (not seeded — the accounts
   depend on the chart), then give an app user the function «Belege erfassen» (Stammdaten ›
   System › App-Benutzer; the linked backend person needs at least the role EDITOR).

## Backend screens

| Menu | URL | What it does |
|---|---|---|
| Finanzen › Belegjournal | `/backend/finance/receipt-journal/list` | the captured receipts with photo; correct, split («Aufteilen …»), delete; «Journal verbuchen …» up to a cut-off; tab «Verbuchte Journale» |
| (same screen, bank stage C5) | | tool «Zahlungen suchen …» (only while a bank account reads statements) proposes the bank movement of each unlinked receipt; in the receipt window «Zahlung suchen …» / «Zuordnung lösen» |
| Stammdaten › Finanzen › Belegvorlagen | `/backend/finance/receipt-template/list` | the tiles on the phone: name, icon, debit account, VAT code, order, active |
| Stammdaten › Finanzen › Zahlungsarten | `/backend/finance/payment-method/list` | «Bezahlt mit»: name, credit account, order, active |

Fragments in `Ui\` (ADR-018), mounted by `z77/module-backend`. Every list and window carries its
help (ADR-048); a base-knowledge help for the backend assistant, «Belege erfassen: App,
Belegjournal, Vorlagen, Zahlungsarten», lives in
`res/view/templates/Backend/Guide/receiptCapture.help.tpl.php`.

## Phone (via z77/module-api)

Three targets on the gateway, scope `receipt.capture`, a paired device only (a bearer key gets
403): `GET receipt-templates`, `GET payment-methods` (no account and no VAT code on the wire) and
`POST receipts` (multipart: `photo` + `amount`, `date`, `text`, `template`, `payment_method`;
the request's `Idempotency-Key` makes a resend harmless). The app screen
(`res/view/templates/App/receipt.tpl.php`, `res/assets/js/app/receipt.js`) shows the templates as
tiles, scales the photo on the phone to a JPEG and queues offline.

## Pieces

- `ReceiptTemplate` / `PaymentMethod` (file-based, `data/framework/receipt/`) — referenced by
  `code`, deactivated never deleted; written through `ReceiptTemplateService` /
  `PaymentMethodService`.
- `Receipt` / `ReceiptJournalPosting` (Doctrine) — the journal row and the protocol of one
  «Journal verbuchen».
- `Services\ReceiptCaptureService::capture()` — the one capture path, no session needed; the
  photo is sniffed (JPEG, PNG, PDF, ≤ 10 MiB) and stored through the DMS's module partition.
- `Services\ReceiptJournalService` — correction, split and deletion with optimistic locking.
- `Services\ReceiptJournalPostingService` — `preview($cutOff)` and `post($cutOff, $seen)`, all
  or nothing.
- `Services\ReceiptPostingBuilder` — one receipt as a generated entry; the lines come from
  module-financial's `GrossEntryBuilder` (the same VAT split as the one-line entry).
- `Services\ReceiptRetention` — after posting, the photo gets the legal deadline in the DMS: end
  of the fiscal year of the receipt date + 10 years (OR 958f); until then it cannot be purged.
- `Services\ReceiptBankMatchService` / `ReceiptBankMatch` (ADR-061 stage C5) — finds the bank movement
  of a receipt (amount, bank account, the days around the receipt date from `receiptConfig['bankMatch']`)
  and links it through module-financial's `BankLinkService`; while linked, amount and «Haben» are the
  bank's. There is no reference on a slip, so every link is the office's act.
  `Services\ReceiptBankEntryOwner` (registry `bankEntryOwners`) tells financial's bank check which
  posting belongs to which receipt. `receiptConfig['foreignCurrencies']` is the one list of foreign
  currencies (used by module-creditor).
- `Close\UnpostedReceiptsCheck` — a receipt still in the journal blocks the year close (and the
  future VAT return) of its date; the reports show a notice.

## Not built yet

- Correcting a POSTED receipt through the ledger as its source (`amend()` / `retract()` /
  `reverse()`).
- «Journal drucken» (the journal as PDF), zoom / rotate of the photo, a dashboard entry
  («n Belege im Belegjournal»).
- The receipts of one device or app user; a link from a journal entry to its receipt photo.
- The mandator's VAT liability is not applied to receipts (RECEIPT-VAT-001) — a non-liable
  company sets «ohne MWST».
- The Drive does not show a photo's retention deadline beforehand — only when a purge is refused.
- The VAT return does not exist yet; `UnpostedReceiptsCheck` already answers its open-work scope.

Out of scope: supplier invoices as PDF (with creditor and invoice number) go to the creditor
journal of `z77/module-creditor`, not here — this journal is for receipts captured
by phone and already paid. module-creditor reuses `PaymentMethod` and `ReceiptFields`.

## Documentation

Topic doc: [`docs/topics/receipt.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/receipt.md)
— the single source of truth for this module. Harness: `tests/module-receipt.php`.

## License

MIT — see [`LICENSE`](LICENSE). Author: Peter Ruepp, [z77.ch](https://z77.ch).
