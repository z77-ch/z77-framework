# Setting up the business suite («Business-Suite einrichten»)

**Status:** `[CURRENT]`
**Last updated:** 2026-11-15

Cookbook for a project that uses the business modules — contacts, the company record,
bookkeeping, articles, orders, invoices, receipts, supplier invoices. It answers three
questions in order: which packages, how a project switches them on (and migrates them), and
what has to be entered in the backend before the first invoice or receipt can be booked.

Every step names the topic doc that owns the detail. The facts here are taken from those topic
docs and the package READMEs («Enabling it in a project»); when they disagree with this page,
the topic doc wins.

**Prerequisite:** a project installed and running with the web stack —
[`new-project-manual.md`](new-project-manual.md) stages 1–3 (path repositories, `composer
install`, database connected in `config/client/database.inc.php`). The business modules need
**PHP 8.4** and **MariaDB** (Doctrine driver, [ADR-039](../02-decisions/adr-039-doctrine-driver-behind-unified-entity-manager.md)).
Until the business-suite release they are consumed through `path` repositories, not Packagist.

---

## 1. The packages

| Package | What it brings | Requires (z77 modules) | Storage | Topic |
|---|---|---|---|---|
| `z77/module-vat` | VAT codes with dated rates, VAT calculation | — | files (`data/framework/vat/`) | [vat.md](../topics/vat.md) |
| `z77/module-contact` | Contacts with typed addresses, the contact-role registry | — | database | [contact.md](../topics/contact.md) |
| `z77/module-mandator` | The installation's own company: letterhead, UID, VAT liability, ledger accounts | — (suggests financial) | database | [mandator.md](../topics/mandator.md) |
| `z77/module-financial` | Chart of accounts, fiscal years, journal, year opening and close, reports | vat, mandator | database | [financial.md](../topics/financial.md) |
| `z77/module-article` | Product groups, articles and variants, price history | vat, mandator (suggests financial) | database | [article.md](../topics/article.md) |
| `z77/module-debtor` | Invoices and credit notes with QR-bill, Faktura-Journal, payments (camt.054), dunning | vat, contact, mandator (suggests financial, mailing) | database | [debtor.md](../topics/debtor.md) |
| `z77/module-order` | Quotes and orders, order status, order PDFs, the invoice drafted from the order | vat, contact, mandator, article, debtor | database | [order.md](../topics/order.md) |
| `z77/module-receipt` | The receipt journal: a phone captures a receipt, the backend corrects and posts it | vat, mandator, financial, dms | database | [receipt.md](../topics/receipt.md) |
| `z77/module-creditor` — **im Aufbau** | Suppliers as a contact role, the Kreditorenjournal for supplier PDF invoices | contact, vat, mandator, financial, receipt, dms | database | [creditor.md](../topics/creditor.md) |
| `z77/module-mailing` | The outbox «Postausgang»: queued mails sent in batches by a job | — | database | [mailing.md](../topics/mailing.md) |
| `z77/module-dms` | The Drive: documents, image variants, delivery under `/media` | — | files | [documents.md](../topics/documents.md) |

Supporting packages: `z77/persistence-doctrine` (the SQL driver and `z77-db`, required by every
module with database storage — [persistence-doctrine.md](../topics/persistence-doctrine.md)) and
`z77/module-api` (the phone app `/app` and the app users the receipt capture needs —
[api.md](../topics/api.md)). Every module above also requires `z77/kernel`.

`module-creditor` is **im Aufbau** (in progress): the supplier master data and the
Kreditorenjournal exist, posting into the books is being built — check the
[creditor.md](../topics/creditor.md) status before relying on it.

---

## 2. Switch the modules on

Per module, three things — all in the project, never in `vendor/` (CE principle):

1. **Require it** in the project `composer.json`, with a `path` repository for EVERY z77 package
   in the dependency graph, not only the ones in `require` — the table above is the graph
   ([`new-project-manual.md`](new-project-manual.md) §1 shows the repository entry).
2. **Register its namespace** in the project's `autoload.psr-4` — for the module itself AND for
   every module it requires. The installer reads the PROJECT's psr-4, not `vendor/`: a package
   that is only required is installed but not a module — no routes, no screens, and `z77-db`
   does not see its migrations ([`create-module.md`](create-module.md) → «How a module is
   discovered»; [installer.md](../topics/installer.md) → «adding a module»).
3. **`composer update`** (or `install`). The installer regenerates
   `config/vendor/moduleManager.inc.php` (generated — do not edit it), adds the template paths,
   seeds the module's default data once and merges its navigation seed (§5).

The full suite in `autoload.psr-4` (take only the lines you need, plus their requirements):

```json
"Z77\\Module\\Vat\\":       ["override/z77/module/vat/src/"],
"Z77\\Module\\Contact\\":   ["override/z77/module/contact/src/"],
"Z77\\Module\\Mandator\\":  ["override/z77/module/mandator/src/"],
"Z77\\Module\\Financial\\": ["override/z77/module/financial/src/"],
"Z77\\Module\\Article\\":   ["override/z77/module/article/src/"],
"Z77\\Module\\Debtor\\":    ["override/z77/module/debtor/src/"],
"Z77\\Module\\Order\\":     ["override/z77/module/order/src/"],
"Z77\\Module\\Receipt\\":   ["override/z77/module/receipt/src/"],
"Z77\\Module\\Creditor\\":  ["override/z77/module/creditor/src/"],
"Z77\\Module\\Mailing\\":   ["override/z77/module/mailing/src/"]
```

(`Z77\Module\Dms\` is already in the skeleton's psr-4 — it is part of the web stack.)

**What the first `composer update` seeds — once** (an installation that has the file keeps it;
a later change of the seed does not arrive):

| Module | Seeded | Not seeded — on purpose |
|---|---|---|
| vat | the Swiss tax codes and rates | — |
| contact | the address types | — |
| debtor | payment terms «30 Tage netto», «10 Tage 2 %, 30 Tage netto»; dunning levels «Zahlungserinnerung», «1. Mahnung», «2. Mahnung» | payment targets — an IBAN cannot be guessed |
| order | the order statuses | — |
| financial | — | the chart: adopted by a button (§4), so an installation that brings its own chart never gets a second one |
| receipt | — | receipt templates and payment methods — their accounts depend on the chart |
| mandator | — | the record itself: «Mandant anlegen» (§4) |

---

## 3. Migrate — one run, after all modules are registered

The database tables come from migrations only, run from the CLI
([persistence-doctrine.md](../topics/persistence-doctrine.md)):

```
php vendor/bin/z77-db status     # names project root and database — read them
php vendor/bin/z77-db migrate
php vendor/bin/z77-db status     # must show New 0
```

**You do not order the migrations by hand.** `z77-db` collects the migrations of
`persistence-doctrine`, of every REGISTERED module and of the project
(`override/z77/project/res/migrations`), and runs them in **timestamp order across all
modules** — not module by module (DOCTRINE-MIG-002). What you order is the **registration**:
a module's tables point at tables of the modules it requires, so those must be registered in
the same run or earlier.

| Module | Its tables (main) | Needs tables of |
|---|---|---|
| persistence-doctrine | `number_range` (the first migration of all) | — |
| contact | `contact`, `address`, `contact_address` | — |
| mandator | `mandator` | — |
| financial | `account`, `fiscal_year`, `fiscal_period`, `journal_entry`, `journal_line`, … | — |
| article | `article_product_group`, `article_product`, `article_variant`, `article_price` | — |
| debtor | `debtor_profile`, `invoice`, `invoice_line`, `payment`, `dunning_run`, `bank_message`, … | contact |
| order | `sales_order`, `sales_order_line`, `sales_order_status_change` | contact, article, debtor |
| receipt | `receipt`, `receipt_journal_posting` | — |
| creditor | `creditor_profile`, `creditor_iban`, `creditor_intake`, `creditor_invoice`, … | contact |
| mailing | `mail_outbox` | — |

vat and dms keep their data in files and have no migrations. A module that is required but
missing from psr-4 is invisible to `z77-db` — its tables are never created, and the first
module that points at them fails.

On a server the same commands run on the NEW release (`next`) before `current` is switched
([persistence-doctrine.md](../topics/persistence-doctrine.md) rules;
[`release-structure.md`](release-structure.md)). Server-specific: `data/archive/debtor/` must
be writable by the web server — the issued invoice PDFs are archived there.

---

## 4. First steps in the backend

In this order — each step needs the one before. Menu paths are the shipped navigation
(«Stammdaten › Finanzen › Kontenplan» = area Stammdaten, group Finanzen, entry Kontenplan).

1. **Chart of accounts** — Stammdaten › Finanzen › Kontenplan: «KMU-Kontenrahmen übernehmen …»
   (the Swiss SME chart; offered only while the chart is empty), or build your own.
   → [financial.md](../topics/financial.md)
2. **The Mandant** — Stammdaten › Firma › Mandant: «Mandant anlegen» once. Four tabs:
   - *Briefkopf* — name, address, logo: the letterhead of every document.
   - *UID und MWST* — the UID (check digit verified) and the **VAT liability** switch. Off:
     invoices, credit notes and order documents are computed and printed without VAT, with the
     «Hinweis ohne MWST» text. What it switches off in the bookkeeping beyond documents is
     still open (MANDATOR-VAT-001).
   - *Konten* — the ten ledger accounts (receivables, discount, loss, rounding, dunning fee,
     input VAT material / other, VAT owed, opening balance, annual result), pre-filled with the
     KMU numbers and checked against the chart.
   - *Belege* — the default «Betreffname» of new documents.
   → [mandator.md](../topics/mandator.md)
3. **Fiscal year** — Stammdaten › Finanzen › Geschäftsjahre: «+ Geschäftsjahr». Free start and
   end (at most 24 months), monthly periods derived; years are contiguous. The following year
   takes the balances through «Eröffnung erstellen …». A posting needs an open year covering
   its date — without one it is refused.
   → [financial.md](../topics/financial.md)
4. **VAT codes** — Stammdaten › Finanzen › MWST-Codes: check the seeded Swiss codes and their
   dated rates. The entry appears only with module-financial installed (its parent group lives
   there). → [vat.md](../topics/vat.md)
5. **Payment target with IBAN** (debtor) — Stammdaten › Auftragswesen › Zahlungsziele: at least
   one target with the company's IBAN and / or QR-IBAN — the QR-bill needs it. Check the seeded
   Zahlungskonditionen (one is the default for new debtors) and Mahnstufen alongside.
   Stammdaten › Auftragswesen › Debitoren shows whether the Mandant's debtor accounts are
   postable. → [debtor.md](../topics/debtor.md)
6. **Articles and order statuses** (order) — Stammdaten › Auftragswesen › Warengruppen and
   Artikel (a root group «Allgemein» exists after the migration); Auftragsstatus: check that a
   status in phase «Abgeschlossen» carries «Wird gesetzt beim Verbuchen».
   → [article.md](../topics/article.md), [order.md](../topics/order.md)
7. **Contacts and their roles** — Kontakte › Kontakte. «Debitor» and «Kreditor» are ROLES of a
   contact, switched on in the contact editor (no separate «new debtor» screen): «Debitor»
   opens the debtor profile with the default payment terms; «Kreditor» creates an empty
   supplier profile (UID, IBANs, posting defaults). A new debtor profile needs an active contact.
   → [contact.md](../topics/contact.md), [debtor.md](../topics/debtor.md), [creditor.md](../topics/creditor.md)
8. **Receipts and the phone app** (receipt) — Stammdaten › Finanzen › Zahlungsarten and
   Belegvorlagen: create them (not seeded). With module-api enabled: Stammdaten › System ›
   App-Benutzer — give an app user the function «Belege erfassen» (the linked backend person
   needs at least the role EDITOR); the phone captures at `/app`, the backend corrects and posts
   in Finanzen › Belegjournal. The photos land in the Drive («Belege/<year>/<month>»).
   → [receipt.md](../topics/receipt.md), [api.md](../topics/api.md)
9. **Supplier invoices** (creditor, **im Aufbau**) — needs step 8's Zahlungsarten and step 7's
   «Kreditor» role. Upload PDFs in Finanzen › Kreditorenjournal; PHP's `upload_max_filesize` /
   `post_max_size` must allow the module's PDF limit. «Journal verbuchen …» posts into the open
   fiscal year. → [creditor.md](../topics/creditor.md)
10. **Outbox** (mailing, optional) — the mail account in `config/client/mail.inc.php`; queued
    mails under Service › Postausgang. With it, debtor's booking print offers «Alle per E-Mail
    senden». → [mailing.md](../topics/mailing.md), [mail.md](../topics/mail.md)

---

## 5. Navigation seeds

Every module ships its menu entries in `data/framework/routing/navigation.d/<package>.json`;
`composer install` / `update` adds every entry the installation does not hold yet and never
changes an existing one (add-only, [ADR-050](../02-decisions/adr-050-module-navigation-seeds.md)).
An entry whose parent lives in a package that is not installed waits: MWST-Codes without
module-financial, Warengruppen / Artikel without module-debtor — the installer names them
([navigation.md](../topics/navigation.md) → «shipped navigation entries»).

> **Open — INST-NAV-SHARED-001.** The installer merges the seeds into the LOCAL
> `data/framework/routing/navigation.json` only. On a release layout whose `data/` is SHARED
> (`.releases/target.json` → `shared`), a module enabled later does NOT get its menu entries
> on the server. No bin step for this exists yet; whether the backend import (whose vendor
> source folds the seeds in, ADR-050 §4) is the manual route is unchecked. After deploying a
> new module, check the server's menu. → [installer.md](../topics/installer.md) → pending.

---

## See also

- [`new-project-manual.md`](new-project-manual.md) — empty folder → first deploy; this page continues it
- [`create-module.md`](create-module.md) — how a module is discovered (psr-4)
- [`installer.md`](installer.md) — what `composer install` does, step by step
- [`backend-screen.md`](backend-screen.md) — how the business screens are built
- each package's `README.md` → «Enabling it in a project»
