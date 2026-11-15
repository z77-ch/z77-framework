# Build plan — module «creditor» (Kreditoren) with PDF invoice intake

**Status:** `[PLAN]` approved by the owner 2026-11-15 with the answers in §11 (they OVERRIDE §1–§10 where they differ: QR image decoding dropped → three stages; no `processed/` folder; retention on posting) — nothing built yet.
**Date:** 2026-11-15
**Input:** the owner's handoff from the z77.ch session (owner decisions
2026-11-15, binding), `docs/03-development/ideas/invoice-intake-automation.md` (2026-11-13, partly
replaced).
**Binds to:** CLAUDE.md rules 1–10, ADR-003 (typed responses), ADR-018 (fragments + thin hosts),
ADR-031 (jobs), ADR-039 decision 9, ADR-040 (module cut), ADR-042 (ledger, base currency),
ADR-043 decision 19 (reference rule), ADR-048 / ADR-055 (help, KI-Assistent), ADR-050 (navigation
seeds), ADR-054 (journal run pattern), `docs/01-handbook/backend-screen.md`.

---

## 1. Summary

- New package **`z77/module-creditor`**: a creditor is a **contact with a creditor profile**
  (role «Kreditor» through module-contact's `contactRoles`, the debtor model), with **several IBANs**.
- A supplier's PDF becomes ONE **creditor invoice** (header + lines; one line on the creditor's
  defaults) in the **Kreditorenjournal**. Nothing reaches the ledger until the owner sets
  «bezahlt am» and runs «Journal verbuchen …».
- Posting sits behind a **`CreditorPostingStrategy`**; only `direct` is built (expense + input VAT
  to the payment method's account, dated with the payment date). The variant is chosen **per
  installation** and **snapshotted on each invoice**, so `collective` (2000) can be added later.
- One intake path, `CreditorIntakeService::receive()`, fed by the backend upload and by the job
  `creditor-intake` reading `*.pdf` from a shared-data folder; SHA-256 idempotency, duplicate
  checks on QR reference and creditor + invoice number.
- Recognition cheapest first: pdftotext text layer → QR image (library to be evaluated) → Claude
  (opt-in, only the PDF of an unclear invoice). Each step degrades to the next when a host lacks it.
- Reuse: module-receipt's Zahlungsarten, field rules, journal-run pattern; financial gains the
  public gross-entry line builder that RECEIPT-BUILD-001 deferred «until a second caller»; IBAN /
  QR reference helpers move from debtor to kernel shared.
- Four deployable stages, re-cut: (1) record + journal + upload + posting, (2) text layer +
  matching + folder job, (3) QR image, (4) AI. Roughly 95–110 h in total.

---

## 2. Architecture

### 2.1 Package and dependencies

`packages/module-creditor` (namespace `Z77\Module\Creditor`), Doctrine entities announced in
`creditorConfig.inc.php` → `doctrineEntities`, migrations in `res/migrations/`.

```text
module-creditor ──→ module-financial (LedgerService, chart, periods, open-work registry)
        ├──→ module-receipt   (PaymentMethod «Bezahlt mit», ReceiptFields rules, later the FX rate fetcher)
        ├──→ module-contact   (the party; role registry)
        ├──→ module-dms       (the PDF is the record)
        ├──→ module-vat, module-mandator
        └··→ z77/kibot        (suggest only — stage 4, AnthropicClient + KeyStore)
```

financial, receipt, contact and debtor learn nothing about creditor (same direction as receipt →
financial; ADR-040 decision 2: financial knows no module). An **ADR-059 «creditor module»** records
the record model, the strategy, the per-installation switch and the extended module graph.

The dependency on module-receipt is the cheapest honest reuse — see question Q1 for the
alternative (moving Zahlungsarten down to financial).

### 2.2 Creditor record — contact + creditor profile (recommended)

Recommendation: **contact + `CreditorProfile`**, exactly the debtor model
(`packages/module-debtor/src/Entities/DebtorProfile.php`, `Roles/DebtorContactRole`), not an own entity.

Reasons:
- Name, address, language, e-mail already live on the contact (`docs/topics/contact.md`); a
  supplier who is also a customer is ONE party (Rule 2).
- The extension point exists and names this case: contact.md rule «When another module needs a
  contact to carry its role («Debitor», later «Kreditor» …) → implement `ContactRoleInterface` in
  THAT module and register it under `contactRoles`».
- The later collective variant and pain.001 need the creditor's address — the contact carries it.

Tables:

| table | columns (essentials) |
|---|---|
| `creditor_profile` | `contact_id` (FK, unique), `default_account`, `default_tax_code` / `default_without_vat`, `default_payment_method_code` (receipt's `PaymentMethod` code, may be ''), `defaults_set_at` / `defaults_set_by` (null = no defaults yet), `uid` (CHE-…, checked with module-mandator's `Uid`), `active`, `version` |
| `creditor_iban` | `id`, `creditor_profile_id` (FK), `iban` (normalised), `is_qr_iban`, `hint` (optional distinguishing text, see shared IBAN), `active`; index on `iban`, **not unique** |

Role «Kreditor» (`Roles\CreditorContactRole`): on = profile exists (no number range — a creditor
number is not needed now; pain.001 does not require one); off = delete the profile only while no
invoice references it, else refused with «… nur deaktivieren» (the debtor rule of 2026-10-12).

**Several IBANs per creditor:** the `creditor_iban` rows; added in the creditor window, or taken
over from an invoice («IBAN beim Kreditor speichern» on the invoice window).

**Shared IBAN** (a billing or collection company for several suppliers): `creditor_iban.iban` is
not unique. Matching by IBAN yields 0, 1 or n creditors:
- 1 → that creditor;
- n → the optional `hint` of each link is searched in the recognised text (e.g. the supplier's UID
  or name as printed on the invoice body); exactly one hit → that creditor; otherwise the row
  shows «IBAN gehört zu mehreren Kreditoren — wählen» with the candidates. Never a guess.
- The UID (if recognised) is a second key: `creditor_profile.uid` exact match beats the IBAN.

### 2.3 The invoice — fields the collective variant will need

`creditor_invoice` (Doctrine, `ORM\Version`):

| group | columns |
|---|---|
| identity | `id`, `creditor_profile_id` (null while only a proposal), `creditor_proposal` JSON (name, street, house no, zip, city, country, IBAN, UID, proposed account + tax code — stage 2/4), `intake_id`, `document_id` (DMS, no FK, indexed) |
| supplier facts | `invoice_number`, `invoice_date`, `due_date`, `iban` (the IBAN the invoice names — snapshot), `reference_type` (`QRR` / `SCOR` / `NON`), `reference`, `message` (Ustrd / billing info) |
| amounts | `amount` (CHF gross, `MoneyType`, NULL only while a foreign amount waits for CHF), `original_amount`, `original_currency`, `exchange_rate` — **the column convention of `receipt-foreign-currency-bauplan.md` T1** (and debtor's `invoice.currency` / `exchange_rate`) |
| payment | `payment_state` (`unpaid` / `paid`; later `scheduled`, `part-paid`), `paid_on`, `payment_method_code`, `credit_account` (resolved from the payment method, correctable) |
| posting | `posting_variant` (`direct` now — snapshot, never re-read from config), `state` (`open` → `posted`), `ledger_entry_ref`, `journal_posting_id`, `posted_by/at` |
| control | `duplicate_of` (id, blocks posting until resolved), `recognition` JSON (which step found which value, notes), `received_by/at`, `changed_by/at`, `version` |

`creditor_invoice_line`: `invoice_id`, `position`, `text`, `amount` (gross, CHF), `account`,
`tax_code` / `without_vat`. One line at intake (owner decision 1); «Aufteilen …» adds lines; lines
must sum to the invoice gross or the invoice is incomplete.

Why lines inside one invoice instead of receipt's «split = two receipts» (E12): the invoice is an
identity — number, QR reference, later ONE open item and ONE payment. Two rows would break
duplicate detection and the later open item. Ledger: still one generated entry per invoice.
(Question Q2.)

`Invoice::missing()` (German, the receipt model): Kreditor, Betrag CHF, Zahlungsart / Haben,
per line Konto + MWST decision, lines = total, Text, «bezahlt am» (only for the run), not a
suspected duplicate.

Also `creditor_intake` (§3) and `creditor_journal_posting` (protocol row, the
`receipt_journal_posting` shape).

### 2.4 Posting strategy — the switch

```php
interface CreditorPostingStrategy
{
    public function key(): string;                                   // 'direct' | 'collective'
    /** The entry when the invoice is booked as a liability — direct: null. */
    public function invoicePosting(CreditorInvoice $invoice): ?PostingRequest;
    /** The entry when it is paid — direct: expense (+ input VAT) to the credit account at paid_on. */
    public function paymentPosting(CreditorInvoice $invoice): ?PostingRequest;
}
```

- `DirectPosting` (built): `paymentPosting()` = `PostingRequest::generated(paid_on, text,
  'creditor', <id>, 'creditor:<id>', lines)`; lines from financial's gross-entry builder (§2.5):
  one credit (the payment method's account, gross total), per invoice line the net on its account
  and the tax line on the mandator's input-VAT account.
- `CollectivePosting` (later): `invoicePosting()` expense → payable (2000) at `invoice_date`,
  key `creditor:<id>:invoice`; `paymentPosting()` 2000 → bank, key `creditor:<id>:payment`; plus a
  `subledgerReconciliations` entry for 2000 and the mandator account key `payable`
  (`Mandator::ACCOUNT_KEYS` already says «creditor accounts arrive with their module»).
- **Where the switch lives:** `creditorConfig['postingVariant']` (`'direct'` default; any other value
  refused loudly until built), read in ONE place, `CreditorPostingVariant::current()`; a project
  overrides it under `override/` (CE-first). Each invoice stores its variant at creation; the run
  resolves the strategy from the INVOICE, so a later switch never re-interprets an existing one.
- **Per installation, not per creditor** (recommended): one accounting method per company; the
  2000 reconciliation and the year close stay readable; the VAT method (input tax at invoice vs.
  payment) is a company choice too. A switch is allowed only at a fiscal-year start with no open
  invoice — checked when `collective` is built.

### 2.5 Reuse vs. duplication with module-receipt

| need | reused as is | extracted (shared building block) | new in creditor |
|---|---|---|---|
| «Bezahlt mit» / credit account | `PaymentMethod`, `PaymentMethodRepository` (receipt) | — | default per creditor |
| date / amount / text / account / tax-code checks | `ReceiptFields` (receipt) | — | invoice-only fields |
| posting lines with gross VAT split | — | **financial: public `GrossEntryBuilder`** (one credit, n gross debit parts, VAT at the date's rate) — resolves RECEIPT-BUILD-001; `ReceiptPostingBuilder` switches to it | `DirectPosting` |
| IBAN check, QR reference check | — | **`Iban`, `QrReference` from `module-debtor/src/Services/Iban.php` / `Invoicing/QrReference.php` → `kernel/shared/src/Payment/`** (+ ISO 11649 `RF` check); debtor uses them from there | QR text parser |
| «Journal verbuchen …» window | `partials/postConfirm` (kernel shared), core.js window/fetch | — | `CreditorJournalPostingService` on the receipt pattern (preview/seen/post, lock order, protocol) |
| list | `Z77\Shared\Listing` | — | `CreditorJournalListing` |
| PDF beside the fields | `.be-docview`, module-dms `MediaField::previewUrl()` | — | — |
| upload | `partials/upload` + `UploadPolicy` (kernel) | — | `creditorUploadPolicy()` (PDF, 20 MiB, several) |
| selection bar | debtor's «In Fakturierung» pattern (`doc[]` = `id:version`) | — | «Bezahlt am …» |
| open-work check | `openWorkChecks` registry | — | `Close\UnpostedCreditorInvoicesCheck` |
| FX rate suggestion | receipt's ESTV fetcher once built (F13) | — | — |

The journal-run service is the third instance of the pattern (debtor, receipt, creditor). Not
extracted now: both live modules would change for no feature gain. Recorded as a pending
extraction candidate in `financial.md` once creditor stage 1 runs (Rule 9 consciously deferred —
named here so it is not silent).

---

## 3. Intake pipeline

**One path:** `CreditorIntakeService::receive(bytes, originalName, channel, actor)` — the
`ReceiptCaptureService::capture()` model: no session needed, actor as data.

1. **Sniff** the BYTES: `%PDF-` + `finfo_buffer` = `application/pdf`, ≤ 20 MiB
   (`creditorConfig['intake']['maxBytes']`). Anything else → refused (upload: error row; folder:
   `failed/`).
2. **Idempotency:** SHA-256 of the bytes; `creditor_intake.sha256` UNIQUE. A known hash stores
   nothing and answers «bereits eingelesen am … (Rechnung …)» (intake row state `duplicate`).
3. **Store** unaltered in the DMS: `DocumentService::forModule('creditor', 'Kreditorenrechnungen')`
   → `ModuleDrive::folder(<fiscal-year code>, <month of receipt>)` → `saveGenerated()` (trusted
   path, so the service sniffs — RECEIPT-DMS-001 analog). The month is the RECEIPT month: the
   invoice date is not known before recognition.
4. **Write** `creditor_intake` (channel `upload` / `folder`, name, size, sha256, state, document id,
   reason) and the `creditor_invoice` row (state `open`, `unpaid`, one empty line) in ONE unit of
   work. Stage 1 ends here — the owner fills the row.
5. **Recognise** (stage 2+): `CreditorRecognitionService::recognise(invoiceId)` fills only what is
   still empty and records the step per value in `recognition`.

**Folder channel** — job `creditor-intake` (module config `jobs`, ADR-031):
- Folder: `creditorConfig['intake']['folder']`, default `data/framework/creditor/intake` — inside
  `shared/data` in the release layout, outside `public/`, stable across releases, the one config
  home. Subfolders `processed/<yyyy-mm>/`, `failed/`, `.work/`.
- Per pass: list `*.pdf` only (case-insensitive); ignore `*.part`, dotfiles and files changed less
  than 60 s ago; `rename()` each into `.work/` (atomic, claims it), then `receive()`; on success move
  to `processed/<yyyy-mm>/`, on refusal to `failed/` with the reason in the intake row. A name
  collision gets a `-2` suffix. Any other file type older than 1 h → `failed/` («kein PDF»).
- Idempotent: a crash after the commit but before the move leaves the file in `.work/`; the next
  pass sees its hash already stored and moves it to `processed/`. The job lock prevents two runs.
- `JobContext::hasTimeLeft()` between files → `JobResult::again()`; recognition steps 2–3 run in
  the same job for invoices still `pending` (also those that came by upload).
- `defaultSchedule` `every:10m` — the job deletes no installation data (it moves files), so the
  JOBS-SCHED rule does not apply. Also «Jetzt starten» on the job screen.

**Upload channel:** the Kreditorenjournal takes drops anywhere on the work area plus a toolbar
tool «PDF hochladen» (`partials/upload`, `UploadPolicy`, several files, one request each). The
request runs `receive()` and the text-layer step inline (fast); the slower steps are queued.

**What the owner sees for a failed file:** tab «Eingang» of the Kreditorenjournal lists the intake
rows (date, channel, file, state, reason, link to the invoice); failed ones red with «Erneut
versuchen» (moves the file back from `failed/`) and «Entfernen». The journal's band says «2 Dateien
nicht eingelesen — Eingang» while any failure is open (backend-screen.md §4 «a pending step must be
visible»).

**Duplicate invoices** (other bytes, same invoice — a re-sent PDF or a REMINDER carrying the same
QR reference) — checked after recognition and on every save:
- same `iban` + `reference` (QRR / SCOR), or
- same creditor + `invoice_number`,
on an invoice not deleted → `duplicate_of` set, row marked «mögliches Doppel von …», **blocked from
posting** until the owner deletes it or clears the mark («kein Doppel»). (Question Q4.)

**Deleting an invoice row** keeps the PDF in the DMS; «PDF auch löschen» as in receipt (off by
default).

---

## 4. Recognition

Each step is a class behind `RecognitionStep` (`supports(host)`, `apply(pdfBytes, invoice)`);
`RecognitionTools` probes once per run: `exec`/`proc_open` not disabled, binary found
(`creditorConfig['tools']['pdftotext']`, default `pdftotext`), ext `imagick` / `gd`, kibot present
+ key readable. A missing tool skips its step and notes it on the row («Texterkennung auf diesem
Server nicht verfügbar»); the row stays usable by hand. Commands: fixed binary, `escapeshellarg`,
temp files in the system temp dir removed in `finally`, a timeout.

1. **Text layer (stage 2):** `pdftotext -layout` (poppler — present on cyon). Parser
   `SwissQrBillText` finds the payment part by its headings in DE/FR/IT/EN («Konto / Zahlbar an»,
   «Compte / Payable à», «Conto / Pagabile a», «Account / Payable to», «Referenz», «Währung Betrag»,
   «Zusätzliche Informationen»): IBAN (check digits via `Iban`), creditor name + address, reference
   (`QrReference::isValid()` / RF check), currency + amount (may be blank on the slip), message and
   Swico **S1** billing info if printed (`//S1/10/<invoice no>/11/<date>/30/<UID>/32/<VAT>/40/<terms>`
   → invoice number, date, UID, due date). Only validated values are taken. «Usable» = a valid IBAN
   and either an amount or a known creditor.
2. **QR image (stage 3):** only when step 1 is not usable. Render the last page(s) at ~300 dpi
   (`pdftoppm` or Imagick), crop the payment part (bottom 105 mm), decode, parse the SPC payload
   (the inverse of debtor's `QrBill` element order — the structure is already documented there).
   **No QR decoder exists in the repo** (`vendor/` has doctrine/symfony/psr only;
   `kernel/vendored/bacon-qr-code` is an ENCODER). Candidates, both MIT, pure PHP, GD or Imagick:
   - `chillerlan/php-qrcode` v5 — encoder + a ZXing-port reader (`readFromFile/readFromBlob`);
     actively maintained;
   - `khanamiryan/qrcode-detector-decoder` — ZXing port, decoder only, older code base.
   - Not available: `zbarimg` (absent on cyon), zxing-cpp (needs FFI / a binary).
   **Evaluation before deciding** (the handoff asks for it): 30 real invoices of z77.ch (scans and
   generated PDFs), on cyon PHP 8.5 CLI: hit rate (target ≥ 95 % where a QR exists), time per
   invoice (target < 3 s), peak memory (< 128 MB), behaviour with the Swiss cross. The result and the
   form of inclusion — a vendored snapshot under `kernel/vendored/` like bacon-qr-code / FPDF, or a
   Composer dependency — are the owner's decision (Q6). Nothing is added before that.
3. **AI (stage 4, opt-in):** only when the invoice is still unclear (no usable QR data, a scan
   without text layer, a foreign invoice, an unknown creditor without account proposal) AND
   `creditorConfig['ai']['enabled']` (default **false**).
   - Client: kibot's `AnthropicClient::create()` + `KeyStore` (`packages/kibot/src/`); the key path
     is the one in `config/client/kibot.inc.php` (`paths.keyFile`) — one key home (Rule 2). Without
     kibot or key the step is skipped.
   - Request: the PDF as a base64 `document` block + an instruction + a JSON schema through
     structured outputs (`output_config.format`; forced `tool_choice` is refused by current models).
     Context sent: the chart's expense accounts (number + label) and the active tax codes, so a
     proposal names valid values. **Nothing else** — no contacts, no ledger data, no other invoices.
   - Answer: supplier, address, IBAN, UID, invoice no / date / due date, currency, gross, VAT
     amounts, proposed account + tax code. Every value re-checked by the server (Iban, amount,
     account exists and postable, tax code active); a failing value is dropped, not stored.
   - Model: `creditorConfig['ai']['model']`, default `claude-opus-5-5` (≈ 3 Rappen per 2-page
     invoice at $4 / $20 per MTok); a daily cap `ai.maxCallsPerDay` (default 50); a log line per call
     (intake id, tokens, outcome — no content) in `logs/creditor-ai.log`.
   - **Privacy:** the PDFs of unclear invoices leave the server to Anthropic (USA) — a cross-border
     disclosure under the revDSG. Stated in the help, in `creditor.md`, and as a sentence the
     installation must add to its privacy notes before switching `ai.enabled` on.

---

## 5. Creditor defaults and the unknown creditor

- **Known creditor** (matched by IBAN / UID): the line gets `default_account` + tax code, the row
  gets `default_payment_method_code` → credit account. No AI call (owner decision 4).
- **Defaults are set ONCE:** by the first invoice of a creditor that the owner saves complete in
  the window (or by «Kreditor übernehmen», below) while `defaults_set_at` is null. Later invoices
  booked differently never overwrite them: the window shows «weicht vom Standard ab (6500 · VI)»
  and a switch **«als Standard übernehmen»** (off by default); only that switch, or the creditor
  window, changes the defaults.
- **Unknown creditor:** the row carries `creditor_proposal` (name, address, IBAN, UID, proposed
  account / tax code) and shows «Neuer Kreditor (Vorschlag)». Nothing is created by the job. In the
  window, **«Kreditor übernehmen …»** opens the proposal: «bestehenden Kontakt verwenden» (search by
  name / UID — debtor's customer-picker pattern) or «neuen Kontakt anlegen» (`ContactForm` building
  block, prefilled); then `CreditorProfileService::open()` + the IBAN + the defaults from this row,
  one unit of work per module as the role registry does. An invoice without creditor is incomplete.

---

## 6. Payment date

- A row waits `unpaid`; the run posts only rows with `paid_on` (owner decision 2: booking date =
  payment date).
- **«Bezahlt am …»** in the selection bar above the list (ticked rows, `id:version` each — debtor's
  invoicing selection): a window with the date (default today, never in the future) and optionally
  «Bezahlt mit» for all of them; one POST, refused rows named. Also per row in the window; removable
  while not posted.
- **«Journal verbuchen …»** (the Belegjournal window, E16): cut-off proposed today; the run = every
  open invoice with `paid_on` ≤ cut-off; unpaid rows are not part of it and block nothing; an
  incomplete or duplicate-suspect row in the run blocks the run (errors only, links to the windows);
  all or nothing, protocol row, one generated entry per invoice. (Question Q3.)
- **Year end — for the help:** an invoice of December paid in January is booked in January (the
  new year); at 31.12 unpaid invoices are not in the books as liabilities. `UnpostedCreditorInvoicesCheck`:
  `period-close` / `vat-return` BLOCK while a PAID unposted invoice is dated in the range;
  `ledger-report` and the year close WARN with count and total of the UNPAID ones («im direkten
  Verfahren nicht als Verbindlichkeit gebucht»).
- Later: the camt import (debtor's `BankImportService`) sets `paid_on` by matching IBAN + reference /
  amount — the bridge to the collective variant; the fields exist from stage 1.

---

## 7. VAT and foreign currency

- **Mandator liable** (`liable_to_vat`): the line carries an input-tax code (`VM` / `VI` …); the
  builder posts net + tax line on the mandator's `vat-input-*` account (financial's rule, not
  computed in this module).
- **Not liable** (z77.ch today): every line is «ohne MWST»; the defaults, proposals and the AI never
  set a code, and the server refuses a tax code with a German sentence — unlike receipt
  (RECEIPT-VAT-001), which posts input tax regardless. (Question Q5.)
- Known consequence for a liable installation on «vereinbarte Entgelte»: input tax is claimed at
  payment, not at invoice receipt — a later deduction, legally fine, but the VAT return of a quarter
  differs from the invoices received in it. The collective variant fixes that. Stated in the help.
- **Foreign currency (EUR):** the receipt FX plan (`docs/03-development/receipt-foreign-currency-bauplan.md`,
  F1–F14, `[CONCEPT]`, not built) is followed, not re-invented: original amount + currency on the
  invoice, the posting stays CHF (ADR-042 decision 4); CHF may stay empty until known (F3/F4 —
  incomplete until filled); CHF is free (F12, the bank's debit incl. fees); the posting text carries
  «… · 245.00 EUR». Allowed currencies: receipt's `foreignCurrencies` list (one place, Rule 2). The
  rate suggestion arrives with receipt's fetcher (F13) — creditor uses it once built. A QR-bill in
  EUR delivers currency + amount from the slip.

---

## 8. Screens, navigation, help, docs, tests

Boards on the developer canvas first (backend-screen.md checklist 1) — owner's look before building.

**Finanzen › Kreditorenjournal** (`CreditorJournalControllerTrait`, host
`module-backend/src/Ui/Controllers/Finance/CreditorJournalController.php`, `/backend/finance/creditor-journal`)
- Action cell: **«Journal verbuchen …»** (green, the window).
- Toolbar: tabs «Im Journal» · «Eingang» · «Verbuchte Journale»; tools «PDF hochladen», help.
- Band: count, total, «n unbezahlt · n bezahlt, nicht verbucht», failures of the intake (red).
- Standard list: PDF thumb · Kreditor (or «Vorschlag: …») · Rechnungs-Nr. · Datum · fällig · Betrag
  (+ «EUR …») · Konto · MWST · bezahlt am · Zustand («vollständig» / what is missing / «Doppel?»);
  checkbox column → selection bar «Bezahlt am …».
- Window: the PDF large beside the fields (`.be-docview`); creditor + IBAN; invoice facts; lines with
  «Aufteilen …» / «+ Zeile»; «Bezahlt mit», «bezahlt am»; «als Standard übernehmen»; «Kreditor
  übernehmen …» for a proposal; «erkannt aus: Textschicht · QR · KI» per value; «Löschen …».

**Stammdaten › Finanzen › Kreditoren** (`CreditorProfileControllerTrait`): the creditors (contact,
UID, defaults, IBANs, invoices count, «Aktiv»). Action cell empty — a creditor is made by the role in
the contact editor or by «Kreditor übernehmen» (the debtor decision of 2026-10-12).

**Kontakte › Kontakt › Rollen:** switch «Kreditor» (`contactRoles` → `CreditorContactRole`).

**Navigation seed** `packages/module-creditor/data/framework/routing/navigation.d/module-creditor.json`
(ADR-050): `kreditorenjournal` under `finanzen` after `belegjournal`; `kreditoren` under
`stammdaten-finanzen` after `zahlungsarten`.

**Help (ADR-048 / ADR-055):** `CreditorJournalController/list.help` (toolbar), `edit.help`
(field sections), `CreditorProfileController/list.help`, and the guide
`Backend/Guide/creditorInvoices.help` (set-up incl. the folder path and the job, the flow, defaults,
duplicates, December/January, VAT, the AI privacy sentence). i18n only where the shared JSONs carry
UI strings (labels stay in the templates like receipt).

**Docs:** new topic `docs/topics/creditor.md` (structure per `docs-lint/STANDARD.md`), trigger-map
row in `docs/README.md`; updates to `financial.md` (line «creditors — no module planned»;
RECEIPT-BUILD-001 resolved by `GrossEntryBuilder`; the journal-run extraction candidate), `receipt.md`
(the idea line → creditor), `contact.md` (second role consumer), `debtor.md` (Iban / QrReference
moved), `css-backend.md` (action-cell rows), `jobs.md` (the job), `ideas/invoice-intake-automation.md`
(status: superseded in part), ADR-059. `npm run docs:check` green.

**Tests:** `tests/module-creditor.php`, the receipt harness shape:
- **O** offline — entity rules (missing, lines sum, duplicate block), strategy selection by the
  invoice's variant, `SwissQrBillText` against fixture texts, Iban / QrReference / RF, SPC payload
  parser, tool probing with fakes, AI answer validation.
- **S** screens offline — wiring, templates, help present, PDF addresses only via `MediaField`.
- **M** MariaDB throwaway schema — migration, `receive()` idempotency, duplicate detection,
  «Kreditor übernehmen», defaults-once rule, «Bezahlt am» bulk with versions, preview / post /
  rollback, open-work answers.
- **J** job against a temp folder — `.part` ignored, young file skipped, processed / failed, crash
  re-run from `.work/`.
- **SM** screens through host doubles.
Fixtures: QR-bill PDFs generated by the framework itself (debtor's `QrBill` + `PdfDocument`) and
their `pdftotext` output — **no real supplier invoice in the public repo**. The QR evaluation corpus
(stage 3) stays outside the repo.

---

## 9. Stages — each deployable

The handoff's four stages, re-cut: the folder job moves into stage 2 (small, and it is the owner's
main channel), the QR image becomes its own stage because it waits for a library decision.

| # | scope | migrations | effort | risks |
|---|---|---|---|---|
| **1** | Package skeleton; **extractions** (`GrossEntryBuilder` in financial + receipt switched to it; `Iban` / `QrReference` to kernel shared + debtor switched); creditor profile + IBANs + role «Kreditor»; invoice + lines + intake + protocol entities; `receive()` (upload only); Kreditorenjournal (list, window, split, «Bezahlt am …», «Journal verbuchen …»); `DirectPosting`; open-work check; Kreditoren master data; nav seed, help, guide, topic doc, ADR-059, harness O/S/M/SM | ONE: all six tables (incl. the columns later stages fill, so stages 2–4 need none) | 50–55 h | the extractions touch receipt + debtor (their harnesses must stay green); FX columns before receipt's FX is built (validator refuses a foreign currency until then, or uses receipt's list once present) |
| **2** | Text-layer recognition (`SwissQrBillText`, S1), matching (IBAN / UID / shared IBAN), proposals, «Kreditor übernehmen …», duplicate checks on reference / number, folder job `creditor-intake` (processed / failed / `.work`), tab «Eingang», tool probing; harness J | none expected | 25–30 h | layout variety of real PDFs (text order with `-layout`); host `exec` restrictions; folder permissions of the SFTP user vs. the PHP user (owner of `shared/data`) |
| **3** | QR image fallback: evaluation on cyon (30 invoices) → owner decision → include the decoder (vendored or Composer) → `QrImageStep` | none | 10–12 h (4 h evaluation) | hit rate on scans; memory / time on shared hosting; a new third-party code base to maintain |
| **4** | AI step: kibot client, structured output, validation, cap, log, privacy texts, switch | none | 12–15 h | cost control; wrong proposals (mitigated: everything validated, nothing posted without the owner); data protection wording |

Total ≈ 97–112 h. Deploy each to z77.ch through the z77.ch session (migration with DB backup first);
module-creditor must be added to the project's migration namespaces (the receipt «not registered»
lesson, receipt.md pending).

---

## 10. Open questions for the owner

1. **Dependency on module-receipt.** creditor requires module-receipt to reuse «Zahlungsarten»,
   its field rules and later the FX rate fetcher — or Zahlungsarten move down to module-financial
   (cleaner, +6–8 h, touches receipt's data file and app API). *Recommendation: require receipt now.*
2. **Split = lines inside one invoice** (one invoice, one entry, one later open item), not two rows
   like the Belegjournal. *Recommendation: lines.*
3. **Posting as a run** «Journal verbuchen …» up to a cut-off on the payment date (Belegjournal
   window; unpaid rows stay and block nothing) — or «Buchen» per selected row. *Recommendation: the run.*
4. **Suspected duplicate** (same IBAN + QR reference, or same creditor + invoice number — catches
   reminders): row created but blocked until you decide — or dropped silently. *Recommendation: created + blocked.*
5. **Not liable to VAT:** the server forces «ohne MWST» on every creditor line (receipt does not —
   RECEIPT-VAT-001). *Recommendation: force it; receipt could follow later.*
6. **QR decoder:** after the evaluation, include the winner as a vendored snapshot in
   `kernel/vendored/` (like bacon-qr-code / FPDF) or as a Composer dependency.
   *Recommendation: evaluate chillerlan/php-qrcode v5 and khanamiryan/qrcode-detector-decoder; vendor the winner.*
7. **Retention of `processed/`** in the intake folder (the DMS holds the record): keep forever, or
   an operator-switched cleanup after 90 days (no shipped schedule). *Recommendation: 90 days, operator-switched.*
8. **AI model and switch:** opt-in per installation, default off; model `claude-opus-5-5` (≈ 3 Rappen
   per invoice) or a cheaper one. *Recommendation: Opus, switched on for z77.ch after the privacy
   sentence is in its privacy notes; decide on the model after a trial with 20 invoices.*
9. **Stage cut:** folder job in stage 2 together with the text layer, QR image as stage 3.
   *Recommendation: yes.*

## 11. Owner answers (2026-11-15)

1. creditor REQUIRES module-receipt (payment methods, field rules, rate proposal) — yes, as recommended.
2. Split = LINES inside one invoice (one entry, later one open item) — yes, as recommended.
- Year-end: the owner keeps the cash principle (Ist-Prinzip); direct variant is enough for now. No accrual helper; the help states that an invoice paid in the new year is an expense of the new year. The collective-account variant stays the proper accrual path for later.
3. Posting as a RUN «Journal verbuchen …» up to a cut-off on the payment date — yes, as recommended.
4. Suspected duplicate (QR reference / creditor + invoice number): created and BLOCKED until the owner decides; the identical file (hash) is never read twice — yes, as recommended.
5. Mandator not liable to VAT: the server FORCES «ohne MWST» on every creditor line — yes, as recommended (receipt may follow later, RECEIPT-VAT-001).
6. QR IMAGE decoding DROPPED (stage 3 struck): digital QR-bills are read from the text layer (stage 2); scans without a text layer go to the AI (stage 4). No QR decoder dependency. Revisit only if many scans arrive and the AI is too costly. (Owner also asked whether the PC upload script could decode QR — rejected: it would cover only the folder channel, not the backend upload, and split the logic.)
7. Intake folder: NO processed/ folder — after the DMS store with a verified checksum the file is DELETED from the intake folder at once; failed files go to failed/ and stay until the owner acts (shown in the creditor journal with the reason).
8. Retention: on posting, the creditor PDF in the DMS gets retentionUntil = end of fiscal year + 10 years (OR 958f, MWSTG Art. 70) — no delete, no overwrite. The owner wants the same for the receipt journal and the debtor documents, built at the same time (separate commit).
   Checked 2026-11-15: debtor PDFs are NOT in the DMS but in its own archive data/archive/debtor/<YYYY>/ (SHA-256 checked, no delete path — only discardWritten() after a rolled-back unit of work) → already protected, nothing to change. receipt: the photo lies in the DMS without retentionUntil and can be trashed/purged in the Drive → gets retentionUntil on posting (separate commit, with the creditor work).
9. AI (stage 3): opt-in per installation, default OFF. The MODEL is a setting (changeable, not hard-coded); chosen after a trial with 20 real invoices. Switching the AI OFF must work cleanly with all consequences: an invoice the text layer cannot resolve (scan, foreign, unknown creditor) still arrives as a row with its PDF, marked «nicht erkannt — von Hand erfassen», and is completed by hand; nothing is lost or blocked. For z77.ch on once the privacy sentence is in place.
   Owner clarification: the AI of the creditor intake is switched like the backend KI-Assistent (kibot): same package/client, same key file (config/client/kibot.inc.php → paths.keyFile), its own on/off and model in that same config file (no second key store, no second switch place). Off, or no key → manual handling as above.
