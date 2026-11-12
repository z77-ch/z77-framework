# z77/module-receipt

The receipt journal for z77 business modules (gateway plan `gateway-app-kanal-bauplan.md`, owner
decisions E1–E12 and T2 / T4 of 2026-11-12): a phone captures a receipt — photo, amount, text,
optionally a template (debit account, VAT code) and a payment method («Bezahlt mit», the credit
account) — into a JOURNAL; at month end the owner reviews, corrects, splits a mixed-VAT receipt
and posts the journal up to a cut-off date in one go («Journal verbuchen»), the flow of debtor's
Faktura-Journal (ADR-054). Depends on `z77/kernel`, `z77/persistence-doctrine` (ADR-039),
`z77/module-vat`, `z77/module-mandator`, `z77/module-financial` (accounts, VAT, posting) and
`z77/module-dms` (the photo). Developed in the
[z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-receipt`); not yet a split target or on Packagist.

State: **wave 1 (2026-11-12)** — entities, migration and services; no backend screen yet (the
board comes first, Rule 8) and no API target yet (wave 2, module-api).

- `ReceiptTemplate` / `PaymentMethod` (file, `data/framework/receipt/receipt_templates.json` /
  `payment_methods.json`, not seeded) — code, label, (icon,) debit account and VAT code ('' =
  without VAT) / credit account, position, active; written through
  `Services\ReceiptTemplateService` / `Services\PaymentMethodService`.
- `Receipt` / `ReceiptJournalPosting` (Doctrine) — the journal row and the protocol of one
  «Journal verbuchen».
- `Services\ReceiptCaptureService::capture()` — the one capture path, no session needed (the API
  target calls it); the photo goes to the DMS partition «Belege», `<fiscal year>/<month>`.
- `Services\ReceiptJournalService` — correction, split and deletion with optimistic locking.
- `Services\ReceiptJournalPostingService` — `preview($cutOff)` and `post($cutOff, $seen)`, all or
  nothing.

Topic doc: [`docs/topics/receipt.md`](../../docs/topics/receipt.md).
