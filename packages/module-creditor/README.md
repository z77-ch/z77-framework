# z77/module-creditor

Payables for z77 business modules. A **creditor** is a contact (`z77/module-contact`) with a
creditor profile: the supplier's UID, **several IBANs** (an IBAN may be shared by several
creditors — a billing or collection company) and the **defaults** a new invoice line of that
supplier starts with (expense account, VAT code or «ohne MWST», «Bezahlt mit»). The role
«Kreditor» is switched on in the contact editor; the master data are edited under
Stammdaten › Finanzen › Kreditoren.

Next (not built yet): the supplier's PDF invoice becomes a row of a **Kreditorenjournal**, the
owner sets «bezahlt am» and posts the journal into the books (`z77/module-financial`).

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
- `z77/module-dms` — will hold the supplier PDFs (from the Kreditorenjournal on)

## Enabling it in a project

1. Register the module in the project's `config/moduleManager` modules (`'creditor' => []`) and
   in the project `composer.json`'s `autoload.psr-4`:

   ```json
   "Z77\\Module\\Creditor\\": ["override/z77/module/creditor/src/"]
   ```

2. `composer update`, then `php vendor/bin/z77-db migrate` (tables `creditor_profile`,
   `creditor_iban`, migration `Version20261115100000`).
3. In the backend: create the payment methods first (Stammdaten › Finanzen › Zahlungsarten),
   then switch on «Kreditor» on a contact and fill UID, IBANs and defaults.

## Backend screen

| Menu | URL | What it does |
|---|---|---|
| Stammdaten › Finanzen › Kreditoren | `/backend/finance/creditor-profile/list` | the creditors: search by name, UID, IBAN; the window for UID, defaults and IBANs; «Aktiv» |
| Kontakte › Kontakt › Rollen | (module-contact's dialog) | the switch «Kreditor»: on = empty profile, off = delete it |

A fragment in `Ui\` (ADR-018), mounted by `z77/module-backend`. The list and the window carry
their help (ADR-048).

## Pieces

- `CreditorProfile` / `CreditorIban` (Doctrine) — the profile per contact and its IBANs.
- `Services\CreditorProfileService` — `open()`, `update()` with optimistic locking and the IBAN
  sync, `setActive()`, `delete()`; the defaults stamp.
- `Validators\CreditorProfileValidator` — UID, defaults (through module-receipt's `ReceiptFields`),
  IBAN rows.
- `Roles\CreditorContactRole` — the role «Kreditor» in the contact editor.

## Not built yet

- The creditor invoice, the Kreditorenjournal, the PDF upload and the folder job.
- «Bezahlt am …», «Journal verbuchen …» and the posting strategy (direct now, collective later).
- Recognition (text layer of a QR-bill, then the AI through z77/kibot), duplicate checks,
  «Kreditor übernehmen …».
- The invoice count in the creditor list; the delete guard «only while no invoice references
  the creditor».

## Documentation

Topic doc: [`docs/topics/creditor.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/creditor.md)
— the single source of truth for this module. Harness: `tests/module-creditor.php`.

## License

MIT — see the repository's `LICENSE`.
