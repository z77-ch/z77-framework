# Faktura-Journal — build plan

**Status:** `[IN PROGRESS]` — written 2026-10-23 from the owner decisions of the same day
([ADR-054](../02-decisions/adr-054-faktura-journal.md), accepted 2026-10-23). **Steps 1–2 built and
committed 2026-10-23** (`b58a4b6`); **step 3 built and committed 2026-10-23** (`63cbb4b`; «Step 3 as built»
below); **steps 4–8 built 2026-10-24, not committed** («Steps 4–8 as built» below; ADR-054 «Addendum
2026-10-24 — steps 4–8 as built»). The open questions (§14) are **decided by the
owner 2026-10-23** (ADR-054 «Owner decisions on the open questions», O1–O13) — they change parts of this
plan, listed in §0 below; where a later section still says otherwise, §0 and the ADR win. The board on
the developer canvas was waived by the owner for steps 3–8 (built on the known patterns). Next: step 9
(hints and future locks).
**Basis:** [ADR-054](../02-decisions/adr-054-faktura-journal.md),
[ADR-040](../02-decisions/adr-040-business-module-cut.md) (incl. addenda 2026-10-13 / 2026-10-14),
[ADR-041](../02-decisions/adr-041-vat-model.md), [ADR-042](../02-decisions/adr-042-ledger-and-money.md)
(incl. addendum 2026-10-06 — `amend()`), [ADR-043](../02-decisions/adr-043-order-status-and-stock-movements.md)
(addenda 2026-10-15, 2026-10-20), [ADR-053](../02-decisions/adr-053-mail-outbox-and-attachment-providers.md),
[`debtor.md`](../topics/debtor.md) (InvoicingService, lock order, booking print, number ranges,
payments, CAMT, dunning, harness checkpoints), [`order.md`](../topics/order.md) (the booking print,
editability), [`financial.md`](../topics/financial.md) (close, reports, VAT return P5 part 2),
[`mailing.md`](../topics/mailing.md), [`p7-order-plan.md`](p7-order-plan.md) (D7, D8).

---

## 0. The owner's decisions of 2026-10-23 — what they change in this plan

| Decision (ADR-054) | Changes |
|---|---|
| O6 — an issued invoice has NO debtor entry and CANNOT be paid | Payments, discount, loss only on `final` (step 2: `PaymentService` refuses `issued` with `not-posted`; open amount, settlement figures, open items, the due list and the fee placement read `final` only — `InvoiceRepository` unchanged). The CAMT import leaves a reference to an `issued` invoice `unmatched` («Rechnung noch nicht verbucht», step 2). **Obsolete:** the payment move on replacement (§5.1 «The replacement» — no `payment_allocation` update, no `amend()`), FJ4 («payment on an issued document posts at once»), FJ7's allocation part, FJ10's «replacement vs payment» race, and «open = new total − paid». The chain suggestion for a REPLACED reference (§5.7, step 7) stays. |
| O9 — ANY deviation blocks the journal | `classify()` (§5.1): every difference a source flags blocks until re-invoiced — not only the posting-relevant ones. |
| O10 — «Buchungsdruck» → «Fakturieren» | UI wording in **step 3** (buttons, titles, help, flashes, the refusal sentences); steps 1–2 rename nothing. |
| O11 — the replacement note | The successor's PDF prints, small and generated: «ersetzt Rechnung <A> (ID <stamp of A>)» — step 4 (manual), step 5 (order). |
| O12 — the document stamp | `invoice.document_stamp` and `invoice_revision.document_stamp` (unique) — **schema in step 1** (done). The stamp = the first 10 characters of a SHA-256 over a canonical serialisation of the version's CONTENT (number, date, recipient snapshot, lines, totals, tax rows, revision), never over the PDF; computed at issue / revision; printed small next to the number — **step 3** (serialisation fixed there, with a test that the same content gives the same stamp and any change a different one). |
| O13 — archive every version as a FILE | At «Fakturieren» and at every re-invoice the PDF is rendered ONCE and stored under `data/archive/debtor/<YYYY>/<number>-r<revision>-<stamp>.pdf` (non-public, the release's shared `data`, the «Daten» backup); a reprint / mail / download delivers that file. `invoice_revision` holds one row per issued version (stamp, `file_path`, `file_sha256`, `file_name`, who, when, why) — **schema in step 1** (done; no PDF blob in the database); writing and reading the archive — **step 3** (revision 1) and **step 4** (revisions). The mail attachment of a version (§9) reads its archived file. |
| O1, O2, O3, O4, O5, O7, O8 | As the plan recommended (§14). O5: the deleted order's stock effects are reversed / void. |

## 1. Goal and scope

Split the booking print into **issue** (number, PDF, mail, payable) and **post** (the ledger
entry), with the **Faktura-Journal** — the set of issued, unposted documents — in between. While a
document is in the journal it is corrected by **revision** (same number) or **replacement** (new
number); once the journal is posted it is immutable as today.

**In scope:** the states `issued` / `replaced` / `deleted` and the revision; the journal screen,
its list print and its posting; corrections of manual documents and of orders while in the
journal; deleting an order; payments on issued documents and their move on replacement; the CAMT
chain; the locks (year close, VAT quarter, dunning); the report hint; mails of issued, revised and
replaced documents; migration of existing data.

**Not in scope:** the VAT return itself (P5 part 2 — only the check it will ask is prepared); a PDF
store in the DMS Drive (debtor.md pending «Storage of the PDF» — the revision archive here is a DB
table); stock (P7b — the order's deletion goes through the existing one stock call).

## 2. Current state (2026-10-23)

| Area | What the code does | Where |
|---|---|---|
| Draft | `invoice()` / `reinvoice()` / `discard()` — no number, nothing posted | `packages/module-debtor/src/Services/InvoicingService.php` (151, 220, 266) |
| Booking print (manual) | `finalize()`: ranges → rows → payment part re-taken → numbers → `PostingBuilder` → `AccountingGateway::post()` → `final`, one unit of work | `InvoicingService::finalize()` (337–427) |
| Booking print (order) | `OrderService::book()` → `invoiceAndFinalize()` joined: compose, numbers, post, `final`; order linked, moved to the default of `closed` | `packages/module-order/src/Services/OrderService.php` (331–427), `InvoicingService::invoiceAndFinalize()` (606–688) |
| States | `invoicing` / `final` only; `Invoice::finalize()` the one state change | `src/Entities/InvoiceState.php`, `src/Entities/Invoice.php` (455–494) |
| Immutability | `Invoice::assertInvoicing()` refuses every write on `final`; `InvoicingService::editRefusal()` | `Invoice.php` 551, `InvoicingService.php` 794 |
| Numbering | `document.YYMM`, `YYMMnnnn`, gapless, by invoice date then id; `uniq_invoice_number` | `src/Invoicing/DocumentNumber.php`, `InvoicingService::drawNumber()` |
| QR reference | zeros · customer number (11–16) · document number (17–26) · check digit, drawn at the booking print (`PaymentSnapshot::numbered()`) | `src/Invoicing/QrReference.php`, `src/Entities/PaymentSnapshot.php` |
| Posting | `PostingBuilder::build()` — receivable / revenue per line / VAT per code / rounding; key `{kind}:{id}:final`; date = invoice date | `src/Invoicing/PostingBuilder.php` |
| Payments | `PaymentService::record()` refuses a non-final document (`NOT_FINAL`); one posting per allocation, key `allocation:{id}`, text «Zahlung Rechnung n · Name» | `src/Services/PaymentService.php` (325–335), `src/Payments/PaymentPostingBuilder.php` |
| CAMT | `matchByQrReference()` / `matchByMessage()` / `assign()` require `final`, else `unmatched` | `src/Services/BankImportService.php` (158, 393, 430) |
| Dunning | due list over `final` invoices | `src/Services/DunningService.php` 290 |
| Year close | `InvoicingInProgressCheck` blocks drafts dated in the year; `UnbookedTransactionsCheck`; `ReceivablesReconciliation` counts posted documents / allocations | `src/Close/*.php`, `debtorConfig` → `openWorkChecks['period-close']` |
| Open amount | gross − final credit notes − allocations; zero unless `final` | `InvoicingService::openAmount()` 732, `InvoiceRepository` (131, 171, 200, 433) |
| Mails | result list after the booking print; `InvoicePdfAttachment::accepts()` final only, PDF rendered at send time; «covered» per document | `src/Mail/InvoiceMails.php`, `src/Mail/InvoicePdfAttachment.php`, `InvoiceControllerTrait::bookingResultAction()` etc. |
| Order editability | editable while no invoice and phase not locked; with an invoice nothing by hand (`INVOICED`); no delete (D8) | `OrderService::assertEditable()` 551, `OrderStatuses`, `p7-order-plan.md` D7 / D8 |
| Screens | invoice list tabs «In Fakturierung» · «Verbucht» · «Gutschriften»; the one mask; the booking-print confirmation / result list; order window, «Buchungsdruck …» | `src/Ui/InvoiceControllerTrait.php`, `src/Ui/InvoiceListing.php`, `src/Repositories/InvoiceSearch.php`, `packages/module-order/src/Ui/OrderControllerTrait.php` |
| VAT return | not built (P5 part 2) | `financial.md` |

## 3. Target model

```text
             booking print                 «Journal verbuchen»
 invoicing ───────────────▶ issued ─────────────────────────▶ final («verbucht», immutable)
 (Entwurf)   number drawn    │  ▲ revision n+1 (same number,
 discard()   no posting      │  │  previous PDF archived)
                             │  └──────────┘
                             ├── replacement ──▶ replaced  (successor issued with a NEW number,
                             │                             payments moved)
                             └── deletion ────▶ deleted   (order deleted / manual delete)
```

**Predicates on `Invoice`** (replace the one `isFinal()` question):

| Predicate | True for | Used for |
|---|---|---|
| `isDraft()` | `invoicing` | editor as today, preview PDF |
| `isIssued()` | `issued`, `final` | number, real PDF with QR, mailable, payable, open amount, PaymentStates, open-item list |
| `isInJournal()` | `issued` | revision / replacement / deletion allowed, journal list, blockers, «nicht verbucht» hints |
| `isPosted()` (= today's `isFinal()`) | `final` | ledger link, credit-note target, dunning, discount / loss (open), reconciliation |
| `isVoid()` | `replaced`, `deleted` | hidden by default, PDF as issued, no payments, no mails |

**Audit of today's `isFinal()` / `InvoiceState::Final` readers** (step 2 sorts each):

| Place | New predicate |
|---|---|
| `Invoicing/QrBill.php` 136, 162, 166, 180 | `isIssued()` (a numbered document prints its bill) |
| `Mail/InvoiceMails.php` 110, `Mail/InvoicePdfAttachment.php` 37, 43 | `isIssued()` and not void |
| `Pdf/InvoicePdf.php` 105, `pdf/invoice.tpl.php` 48 | `isDraft()` → preview; else the real PDF (void ones as issued) |
| `Repositories/InvoiceRepository.php` 131, 171, 200, 226, 433 (credit notes, settlement figures, open items, fees) | `state IN ('issued','final')` |
| `InvoiceRepository.php` 505 (list views), 562 (`invoicingBetween`) | new views; the close check widened (§7) |
| `Services/BankImportService.php` 158, 393, 430 | `isIssued()`; `replaced` / `deleted` → §5.7 |
| `Services/DunningService.php` 290 | `isPosted()` (unchanged in effect) |
| `Services/InvoicingService.php` 734 (open amount), 808 (`assertInvoicing`), 892 (credit-note target), 1135 (`bookingBill`) | 734 `isIssued()`; 808 split into draft / in-journal; 892 `isPosted()`; 1135 `isIssued()` |
| `Services/PaymentService.php` 330 | `isIssued()`; discount / loss `isPosted()` (open) |
| `Ui/InvoiceControllerTrait.php` 461, 636, 715, 820, 853–858, 990, 1369, 1428, 1478 | per action (payment: issued; credit note: posted; discard: draft; booking-print idempotency: issued at version + 1) |
| `_facts.tpl.php` 44, 63, 91; `listAction.tpl.php` 74 | state badge of five states |
| module-order `Ui/BookingSelection.php` 59, `OrderController/edit.tpl.php` 618–619 | `isIssued()`; the «Belege» tab shows the five states |

**Step 2 as built (2026-10-23)** — where it differs from the table above:

- The print side asks `!isDraft()`, not `isIssued()`: `QrBill::of()` / `unavailable()`, `InvoicePdf::title()`,
  `pdf/invoice.tpl.php`, `InvoicingService::bookingBill()`, the bill address of the mask, the
  «E-Mail» history row, the order window's PDF link — a void document prints as issued (§3 «PDF as
  issued»), so «numbered» is the intent there.
- **O6: every receivables read stays `final`** — `InvoiceRepository` is unchanged (131, 171, 200,
  226, 433: credit notes, due list, settlement figures, fee placement, open items), and the readers
  of an open amount, the payment form and the mask's payments / dunning facts ask `isPosted()`
  (`InvoicingService::openAmount()`, `InvoiceControllerTrait` 461, 853–855). The table above said
  `issued` for them — superseded.
- Not touched in step 2 (they change behaviour once `issued` exists, so they belong to step 3):
  the list views (`searchWhere()`, 505 — the tabs) and `invoicingBetween()` (562 — the close check).
- `InvoicingService::assertInvoicing()` (808) refuses everything but a draft, with a sentence per
  state; the revision path of step 4 opens it for `issued`. The discard action asks `isDraft()`.
- `PaymentService::assertSettleable()`: payments, discount and loss `isPosted()` (O3, O6); an
  `issued` document is refused with the new reason `PaymentRefusedException::NOT_POSTED`.
  `BankImportService` (158, 393, 430): an `issued` document is not assignable / stays `unmatched`
  with «Rechnung noch nicht verbucht» (O6).
- The German sentences of the refusals for drafts («ist noch in Fakturierung …», «nicht verbucht»)
  are unchanged — the rename «Fakturieren» (O10) is step 3.
- `Invoice::isFinal()` is removed (no project override used it); the state badges (`_facts`, the list
  icon, the order window) read `Invoice::state()` and `InvoiceState::label()`.

## 4. Data model and migrations

All expand-only, **schema and data in separate files** (debtor's rule since 2026-10-13), generated
with `z77-db diff` and reviewed (`utf8mb4_unicode_ci`, `ENGINE = InnoDB`).

### module-debtor — `VersionYYYYMMDDhhmmss` (step 1)

| Table / column | Type | Meaning |
|---|---|---|
| `invoice.state` | unchanged `VARCHAR(12)` | new values `issued`, `replaced`, `deleted` |
| `invoice.revision` | `INT NOT NULL DEFAULT 1` | revision of an issued document (ADR-054 decision 11); not the ORM `version` |
| `invoice.issued_by` / `issued_at` | `VARCHAR` / `DATETIME NULL` | the booking print (today `changed_*` carries it) |
| `invoice.posted_by` / `posted_at` | `VARCHAR` / `DATETIME NULL` | the journal posting |
| `invoice.journal_posting_id` | `INT NULL`, FK `invoice_journal_posting` | which posting protocol posted it |
| `invoice.replaced_by_id` | `INT NULL`, self FK, index | the successor (chain A → B → C) |
| `invoice.voided_by` / `voided_at` / `void_reason` | `VARCHAR` / `DATETIME NULL` / `VARCHAR(255) NULL` | who / when / why replaced or deleted |
| `invoice.reissue_needed` | `VARCHAR(255) NULL` | set by a source (order) — the blocker text (ADR-054 decision 9); NULL = in step |
| `invoice.document_stamp` | `VARCHAR(10) NULL` | the stamp of the current issued version (O12); NULL on drafts and on documents issued before the journal |
| index `idx_invoice_state_date` | exists | serves the journal and the checks |
| table `invoice_revision` | `id`, `invoice_id` FK, `revision` INT, `document_stamp` VARCHAR(10) (unique `uniq_invoice_revision_stamp`), `file_path` VARCHAR(255), `file_sha256` CHAR(64), `file_name`, `reason` VARCHAR(255) NULL, `created_by`, `created_at`; unique (`invoice_id`, `revision`) | one row per ISSUED VERSION (revision 1 included): its stamp and the reference to its archived PDF file (O12, O13) — no blob |
| table `invoice_journal_posting` | `id`, `posted_by`, `posted_at`, `document_count`, `gross_total` (MoneyType), `first_number`, `last_number` | the protocol of one «Journal verbuchen» |
| `bank_transaction.suggested_invoice_id` | `INT NULL`, FK `invoice` | the pre-selected successor of a replaced reference (rule 13) |

Data step: none needed — every row keeps its state; `revision` = 1 by the column default. Existing
`final` documents stay `final` (ADR-054 decision 22).

### module-order — (step 6, only if the order keeps a «gelöscht» state, §14 Q6)

`sales_order.deleted_by` / `deleted_at` / `delete_reason` (NULL = not deleted), index for the list
filter. Alternatively nothing (a real delete).

### Entities

- `InvoiceState`: `Issued`, `Replaced`, `Deleted`; the class comment rewritten (no more «no
  deleted state»).
- `Invoice`: `issueNumbered()` (today `assignNumber()` + the state), `post($ref, $postingId, by,
  at)` (today `finalize()`), `revise(snapshot, lines, taxes, payment, by, at)` (only `issued`;
  keeps number and the numbered payment part's reference), `replaceBy(Invoice $successor, reason,
  by, at)`, `markDeleted(reason, by, at)`, `flagReissue(?string)`; the predicates of §3.
- New `InvoiceRevision`, `InvoiceJournalPosting`.

## 5. Services

### 5.1 `InvoicingService` (debtor)

| Today | Target |
|---|---|
| `finalize(list{id,version})` — number + post | **`issue(list{id,version})`** — the booking print: steps 0–4 of today (ranges, locks, payment part, numbers), state `issued`, NO post. `finalize()` stays for ONE caller: the dunning fee (issue and post at once, ADR-054 decision 2, open) |
| `invoiceAndFinalize(drafts)` | **`invoiceAndIssue(drafts)`** — the same chain without step 6's post |
| — | **`revise(int $id, int $version, InvoiceDraft $draft): Invoice`** — the decision rule (ADR-054 decision 8): compose, compare, then nothing / revision / replacement; returns the document that now stands (same or successor) |
| — | **`classify(Invoice $issued, InvoiceDraft $draft): Correction`** — the pure comparison (`none` · `revision` · `replacement` + why), for the confirmation BEFORE the save and for a source's flag |
| — | **`flagReissue(int $id, ?string $reason)`** — the source's seam (order); takes the row lock, writes only the flag |
| — | **`delete(int $id, int $version, string $reason)`** — `issued` → `deleted`; refused with allocations or when `final` |
| `editRefusal()` | answers for the five states (draft editable, issued editable by revision, posted / void read-only) |

**The comparison** (`Invoicing\Correction`, pure): replacement when gross, the tax rows
(`code, rate, base, tax`), `carriesVat()`, the month of the invoice date or the contact differ;
`posting-relevant revision` when a revenue account or the invoice date (same month) differ;
`revision` for anything else in the snapshot; `none` otherwise. «Posting-relevant» is what blocks
the journal when a SOURCE flags it (ADR-054 decision 9).

**The replacement** — one unit of work, lock order (extends debtor.md «Lock order of the module»):
the successor's `document.YYMM` (created ahead, `prepareDocumentRanges()`) → the profile row
SHARED → the predecessor row EXCLUSIVE (version compared) → the reads (compose, open amount) →
insert successor, draw its number, numbered payment part → UPDATE `payment_allocation.invoice_id`
→ per moved allocation its posting rebuilt by `PaymentPostingBuilder` (key `allocation:{id}`
unchanged) and handed to the existing port method `AccountingGateway::amend()` (P4 part 1 —
`LedgerService::amend()` behind it) with the text «Zahlung Rechnung B · Name» → predecessor `replaceBy()`. The predecessor's revision archive is not needed — its row is
frozen and renders itself.

**The revision** — lock: the row EXCLUSIVE → render the CURRENT PDF (`InvoicePdf::of()`) →
insert `invoice_revision` → `revise()` → `revision + 1`. No range, no journal.

### 5.2 `JournalPostingService` (debtor, new)

- `pending(): list<Invoice>` — the journal (`issued`, number order); `blockers(): list<{id, reason}>`
  — `reissue_needed` set, a VAT-liability change since issue (today's `vat-liability-changed`
  check moves here), a revenue account no longer postable (`assertStoredRevenueAccounts()` moves
  here), the receivable account.
- `preview(): JournalPreview` — per document `PostingBuilder::build()` without posting; totals per
  account and per VAT code (the list print).
- `post(list<{id, version}> $seen): InvoiceJournalPosting` — one unit of work: rows EXCLUSIVE by id
  → versions compared (a document revised since the screen → conflict) → blockers again → per
  document `PostingBuilder` → `AccountingGateway::post()` → `Invoice::post()` → the protocol row.
  A ledger refusal (period closed, no fiscal year) rolls back the whole posting. Lock order: rows
  → journal (as `finalize()` today; no ranges).

### 5.3 `OrderService` (order)

- `book()` → `invoiceAndIssue()`; the order moves to the default of `closed` as today.
- `assertEditable()`: editable when no invoice, OR the linked invoice `isInJournal()`
  (asked through a debtor read port — `PaymentStates` grows a `documentState`, or a small
  `InvoiceStates::of(ids)`; debtor never names order). The phase `closed` stays «locked» only when
  the invoice is posted.
- `update()` on an order with an `issued` invoice: after the rewrite, `preview()` of the order →
  `InvoicingService::classify()` → `flagReissue()` with the reason (or clear it when the order is
  back in step). Same unit of work, after the order's lock (order row → invoice row).
- **`reinvoice(id, version)`** («Neu fakturieren»): order row EXCLUSIVE → `OrderInvoiceDraft::of()`
  → `InvoicingService::revise()` joined → re-link to the successor when one was created → log row
  (trigger `reinvoice`). The ranges ahead with `prepareDocumentRanges()` as `book()` does.
- **`delete(id, version, reason)`**: refused when the invoice is posted; the invoice
  `InvoicingService::delete()` joined; the stock brought to zero (`syncStock()` with an empty
  target, `StockConfirmation` when consumed stock comes back); the order deleted or stamped
  (§14 Q6). Every write still through the one service.

### 5.4 `PaymentService`

`assertSettleable()` → `isIssued()` for payments; discount / loss → `isPosted()` (§14 Q3);
`replaced` / `deleted` → `PaymentRefusedException` `replaced` naming the successor. Lock order
unchanged (row → journal), so a payment racing a replacement waits and then sees `replaced`.

### 5.5 Open amount and its readers

`openAmount()`, `settlementFiguresOf()`, `openItems()`, `sumOfFinalCreditNotes()` (renamed
`sumOfIssuedCreditNotes()`) count `issued` and `final` credit notes and documents; `replaced` /
`deleted` never. `PaymentStates` answers `issued` documents too. The open-item list «Debitoren»
shows issued documents with a badge «nicht verbucht».

### 5.6 Dunning

Unchanged in effect (`isPosted()`); the dunning list says how many overdue documents are not
dunned because they are still in the journal (a hint line, one COUNT).

### 5.7 CAMT import (`BankImportService`)

- `matchByQrReference()` / `matchByMessage()`: the document found → `issued` or `final` matches as
  today; `replaced` → follow `replaced_by_id` to the end of the chain (bounded loop, a cycle is
  impossible by construction but guarded), state `unmatched`, `suggested_invoice_id` = the last
  successor, note «Referenz von A — ersetzt durch C»; `deleted` → `unmatched`, note «Rechnung A
  gelöscht».
- The detail screen: a transaction with a suggestion shows the successor pre-filled in «Zuordnen»
  and a one-click «Zuordnen zu C»; `assign()` unchanged (it already takes a document number).

## 6. UI (backend-screen.md recipe; board on the developer canvas BEFORE step 3)

**Navigation:** «Aufträge» › **«Faktura-Journal»** (`faktura-journal`, seed `module-debtor.json`,
sort right after «Rechnungen»; add-only merge, ADR-050 — an existing installation receives it
with `composer update`).

**Faktura-Journal** (`/backend/finance/invoice-journal/…`, fragment `Ui/InvoiceJournalControllerTrait`
+ host in module-backend):

| Region | Content |
|---|---|
| Action cell | «Journal verbuchen …» (the entry's most frequent action) — disabled with the reason while blocked |
| Toolbar | tabs «Im Journal» · «Verbuchte Journale» (the posting protocols); tool «Journalliste (PDF)» |
| Pending step at the top | «n Rechnungen im Journal · Total CHF … · ältestes Datum …»; when blocked: «Verbuchen gesperrt — 2 Rechnungen müssen neu fakturiert werden» with the links |
| Work area | a STANDARD LIST (`listing.md`): state icon (opens the document mask as a window) · Nr. · Datum · Kunde · Netto · MWST · Total · Rev. · Herkunft (order link text, opaque) · Hinweis («neu fakturieren: Betrag geändert», «bezahlt 100.00»); search per column in SQL; 50 per page |
| Confirm «Journal verbuchen …» | a page / window: count, totals per account and VAT code (the preview), the versions seen; POST `#[Csrf]` → `JournalPostingService::post()` → the protocol's detail with «Journalliste (PDF)» |
| «Verbuchte Journale» | the protocols newest first (date, who, count, total, number range), each reprints its journal list |

**Rechnungen** (existing list): tabs «Entwürfe» · «Fakturiert» · «Verbucht» · «Gutschriften» ·
«Ersetzt / Gelöscht» (the void ones are hidden from every other tab — rule 4). The mask:

- an `issued` document opens EDITABLE (the draft editor with number, revision and «fakturiert —
  im Faktura-Journal» on top); «Speichern» first asks `classify()` and answers a confirmation
  («Betrag geändert — neue Nummer, Rechnung 26100012 wird ersetzt» / «gleiche Nummer, Version 2 —
  Version 1 wird archiviert»); actions «PDF», «Zahlung erfassen …», «Rechnung löschen …» (§14 Q7);
- a `replaced` / `deleted` document read-only with the badge, «ersetzt durch B» / «ersetzt A»
  links both ways, who / when / why, the PDF as issued;
- the facts block lists the revisions with their archived PDFs.

**Order window:** with an `issued` invoice the order is editable (no disabled fieldset); the
«Belege» card shows «Rechnung 26100012 · fakturiert (nicht verbucht)» and, when flagged, a red
line «Rechnung weicht ab: Betrag geändert — Neu fakturieren …»; «Neu fakturieren …» (confirm
window naming revision / replacement) in the «Drucken ▾» menu or the action row; «Auftrag löschen
…» (confirm with mandatory reason) when no posted invoice exists.

**Wording** (§14 Q9): «Buchungsdruck …» no longer books — proposal «Fakturieren …»; drafts
«Entwurf» instead of «In Fakturierung». Help sections (ADR-048) for the journal, the revision /
replacement confirmation and the order's «Neu fakturieren».

## 7. Locks

| Lock | Mechanism | Step |
|---|---|---|
| Year close | `InvoicingInProgressCheck` (`period-close`): drafts AND `issued` dated in the year, first ten named («Rechnung 26100012 an Muster AG ist fakturiert, aber nicht verbucht — Faktura-Journal verbuchen.») | 3 |
| VAT quarter | new `Close\JournalForVatReturnCheck` under scope `vat-return` (`from` / `to`); financial asks it when P5 part 2 builds the return; registered now, asked by nobody yet | 9 |
| Ledger refusal | unchanged last line: a posting into a closed / VAT-settled period is refused by financial at «Journal verbuchen» | 3 |
| Dunning | `final` only | 2 |
| Credit note | against `final` only | 2 |

**Lock order of the module** (debtor.md, to be extended in the topic): ranges (by name) → profile
rows → message row (CAMT) → **sales_order rows (when an order is the caller)** → invoice rows
(EXCLUSIVE, by id; predecessor before its successor exists) → reads → writes, `payment_allocation`
updates, `journal-entry.*` (posts) / amends.

## 8. Reports hint and dashboard

- **Reports:** financial's report pages (trial balance, balance sheet, income statement, account
  statement, journal) ask `OpenWorkChecks->ask('ledger-report', ['from' => …, 'to' => …])` and
  show the warnings above the report; debtor registers `Close\UnpostedJournalHint` («3 Rechnungen
  im Faktura-Journal nicht verbucht — Total CHF 4'210.50», link to the journal). Financial stays
  module-free.
- **Dashboard** (`DebtorDashboard`, ADR-052): «Faktura-Journal: n nicht verbucht» (todo; urgent
  when blocked or older than N days — N **not** a setting before asked), target the journal.

## 9. Mails (ADR-053)

| Situation | Behaviour |
|---|---|
| Booking print → result list | as today; the documents are `issued` — `InvoicePdfAttachment::accepts()` takes `issued` and `final` |
| A revision after a mail was queued but not sent | the queued mail sends the revision it was queued with: attachment reference `invoice-pdf:<id>@<revision>`, `resolve()` renders the current revision or reads `invoice_revision` |
| A revision after the mail was sent | the sent mail stays history (the customer holds revision 1, archived); the document counts as UNSENT for its new revision — the mask offers «Version 2 senden …»; mail kind `invoice` with a sentence «ersetzt Version vom …» (open: own kind) |
| A replacement | waiting mails of A (`queued`) are CANCELLED (`OutboxService::cancel()`); B is offered for sending; proposal: own mail kind `invoice-replacement` («Diese Rechnung ersetzt Rechnung A vom …», §14 Q10), placeholder `{ersetzt}` |
| Deleted / replaced documents | never mailed (`accepts()` false) |
| History | `deliveriesOf('invoice:<id>')` unchanged; the line names the revision |

## 10. Numbering and QR reference

- The number is drawn at the booking print (issue) exactly as today — ranges, order by date then
  id, 9999 per month, `uniq_invoice_number`.
- A replacement draws a NEW number from the month of the SUCCESSOR's invoice date; the
  predecessor keeps its number — the series stays gapless (the void document fills its place).
- A revision keeps number, QR reference and payment-part reference; the due date in the message
  («zahlbar bis …») and the creditor block are taken again (`bookedPaymentPart()` minus the
  reference) — so the QR payload of revision 2 may differ in the message only.
- The QR reference of A stays valid paper at the customer: rule 13 routes it.
- `assignNumber()`'s «only once» stays; `revise()` never touches `number`.

## 11. Existing data and deploy

- Every `final` document stays `final`; drafts stay drafts; no `issued` rows after the migration.
- Deploy: the migration of step 1 is expand-only and safe under the running release. Step 3
  changes the booking print's meaning — deploy it with nothing booked in between (copied-database
  deploy or a quiet moment); before step 3 every installation has an empty journal.
- z77.ch (dev / test) and `z77ch_initial`: run the migrations with `z77-db migrate`; the symlinked
  packages fail with «Unknown column» until they run (as with every debtor migration).
- The wdv import (ADR-040 decision 9) writes `final` documents only — unaffected.

## 12. Test plan

**Harness facts:** `tests/module-debtor.php` takes ~27 min in full; checkpoints via
`tests/support/HarnessCheckpoint.php` (`--from=<SECTION>`, saved by a full run at the section's
boundary). Plan: a new block of sections **after BM**, with a checkpoint **`FJ`** saved at the
BM → FJ boundary, so every new section runs in ~1–2 minutes with `--from=FJ` during the build; one
full run closes each step. `tests/module-order.php` M (DB) for the order parts.

| Section | Content | Step |
|---|---|---|
| A (changed) | migration count, `diff` «No changes», new columns / tables | 1 |
| FJ1 | booking print → `issued`, number drawn, NO journal entry, payment part numbered, idempotent repeat at version + 1 | 3 |
| FJ2 | journal list / preview = what `post()` books (to the Rappen); `post()` all or nothing; ledger refusal rolls back; protocol row; posting key unchanged; reprint of a posted journal | 3 |
| FJ3 | year close blocked by an `issued` document of the year, not by one of the next year; dunning ignores `issued` | 3 |
| FJ4 | payment on an `issued` document posts at once (1020 / 1100), receivable in credit, reconciliation unchanged; discount / loss refused until posted (Q3) | 3 |
| FJ5 | `classify()` table: gross, VAT row, VAT / no VAT, month, contact → replacement; account, date in month → posting-relevant revision; text → revision; none | 4 |
| FJ6 | revision: same number, `revision` 2, PDF archived (sha256 equal to the rendered rev. 1), QR reference unchanged | 4 |
| FJ7 | replacement: new number in the successor's month, A `replaced` with link / who / why, allocations moved, payment entry text amended (same journal number, `EntryChange`), open = new total − paid, overpayment negative | 4 |
| FJ8 | CAMT: QRR of A → `unmatched`, suggestion C (chain A → B → C), note; NON «Rechnung A» the same; deleted → note; one-click assign | 7 |
| FJ9 | mails: queued mail of A cancelled on replacement; revision-bound attachment; covered per revision | 8 |
| FJ10 | lock races (second connection, the `O` worker pattern): replacement vs payment, posting vs revision | 4 |
| FJ11 | report hint `ledger-report`; VAT-return check answers (registry only) | 9 |
| order M (new) | order editable with an `issued` invoice; save flags `reissue_needed`; «Neu fakturieren» relinks; journal blocked until done; delete → invoice `deleted`, stock to zero, refused with a posted invoice | 5, 6 |
| L, P3E, Z, O, BM (rewritten) | expect `issued` after the booking print; a helper `$bookAndPost()` for the sections that need a POSTED document (Q, R, T, U, X, Y) | 3 |

Every step: `php -l` on the changed files, the new FJ sections from the checkpoint, the full
harness once at the step's end (and `tests/module-order.php` when order changed); the owner's
browser look per step; `npm run docs:check` green.

## 13. Build steps — each shippable and testable

| Step | Content | Ships as |
|---|---|---|
| 1 | Schema: the migrations of §4 (debtor), entity mappings, `InvoiceState` cases, `InvoiceRevision` / `InvoiceJournalPosting` entities — no behaviour change. **Built 2026-10-23**: `Version20261023150000` (schema only, one file), fields and getters only — the transition methods (`issueNumbered()`, `post()`, `revise()`, …) come with the steps that call them | invisible; harness A (A0, A5c, A11, A12h) |
| 2 | Predicates and readers: `isDraft` / `isIssued` / `isInJournal` / `isPosted` / `isVoid`, the audit table of §3 applied — still nothing produces `issued`, behaviour identical. **Built 2026-10-23** («Step 2 as built», §3) | refactor; full harness green unchanged; section FJ (FJ0–FJ8) + checkpoint `FJ` |
| 3 | **The journal core**: `issue()`, `invoiceAndIssue()`, `JournalPostingService`, the Faktura-Journal screen (list, preview, PDF list, «Journal verbuchen», protocols), navigation seed, payments on `issued`, open amount / open items / PaymentStates on `issued`, the year-close check widened, the rewritten sections (L, P3E, Z, O, BM). **Built 2026-10-23** («Step 3 as built» below) — payments, open amount, open items and PaymentStates stay on `final` (O6) | the booking print stops posting; the office posts the journal |
| 4 | **Corrections of manual documents**: `classify()`, `revise()`, revision archive, replacement (no payment move — O6), the mask (editable `issued`, confirmation, void read-only, revisions), list tabs «Ersetzt» / «Gelöscht». **Built 2026-10-24** | correct a printed invoice without a credit note |
| 5 | **Orders**: editable with an `issued` invoice (read port), `flagReissue()` on save, «Neu fakturieren», journal blocked by flags, the order window's «Belege» card. **Built 2026-10-24** | correct an order after the booking print |
| 6 | **Delete**: `OrderService::delete()` + `InvoicingService::delete()` (and the manual «Rechnung löschen …» if Q7 = yes), stock to zero, order migration if Q6 = state. **Built 2026-10-24** (order kept with state, O5; manual delete, Q7) | delete an order |
| 7 | **CAMT chain**: suggestion through the chain, note, one-click assign, `bank_transaction.suggested_invoice_id`. **Built 2026-10-24** | a payment of an old QR reference lands on the successor |
| 8 | **Mails**: revision-bound attachment, cancel on replacement, «Version n senden», replacement mail kind (Q10). **Built 2026-10-24** («Senden …» in the mask = the result list of the document) | correct mails after corrections |
| 9 | **Hints and future locks**: `ledger-report` hint in financial's reports, `vat-return` check registered, dashboard entry, dunning hint line | the office sees what is unposted |

Steps 1–3 are the minimum that delivers rule 1; 4–5 deliver the owner's goal (correct without a
credit note); 6–9 complete the rules. Each step updates `debtor.md` / `order.md` (`## pending`,
mental model), keeps `npm run docs:check` green and is committed only on the owner's word.


## Step 3 as built (2026-10-23)

The binding decisions are ADR-054 «Addendum 2026-10-23 — step 3 as built» (1–10). Where the code is:

| Part | Where |
|---|---|
| «Fakturieren» (issue, no post), the dunning fee's issue-and-post | `InvoicingService::issue()` / `finalize()` (both `issueBatch()`), `numberAndIssue()` (numbers → stamp → `markIssued()` → archive) |
| An order's «Fakturieren» | `InvoicingService::invoiceAndIssue()` (formerly `invoiceAndFinalize()`), `OrderService::book()` (+ `discardArchived()` on a failure after the join) |
| State changes on the entity | `Invoice::markIssued()` (invoicing → issued), `Invoice::post()` (issued → final); `Invoice::finalize()` removed |
| The stamp | `Invoicing\DocumentStamp` (`canonical()`, `of()`, `hash()`) |
| The archive | `Pdf\InvoiceArchive` (`archive()`, `pdf()`, `combined()`, `discardWritten()`), `Pdf\InvoiceArchiveException`; the kernel's `Z77\Shared\Pdf\PdfConcat` for «Alle als ein PDF» |
| The posting | `Services\JournalPostingService` (`pending()`, `totals()`, `blockers()`, `preview()`, `post()`, `protocols()`), `Services\JournalRefusedException` (`empty` · `blocked` · `changed`), `Repositories\InvoiceJournalPostingRepository` |
| The journal's SQL | `InvoiceRepository::journalDocuments()`, `journalVersions()`, `journalTotals()`, `unpostedBetween()` (replaces `invoicingBetween()`); `InvoiceSearch::VIEW_ISSUED` / `VIEW_JOURNAL` |
| The screen | `Ui\InvoiceJournalControllerTrait`, `Ui\InvoiceJournalListing`, `Ui\InvoiceJournalLayout`, templates `Backend/InvoiceJournalController/{listAction, act, toolbar, confirmPost, list.help}`, `Pdf\JournalPdf` + `pdf/invoiceJournal`; host `module-backend/…/Finance/InvoiceJournalController.php` + `invoiceJournalControllerConfig.inc.php`; seed entry `faktura-journal` |
| The year close | `Close\InvoicingInProgressCheck` (drafts and issued documents dated in the year) |
| Wording | O10 — every user-facing «Buchungsdruck» in debtor and order reads «Fakturieren»; routes unchanged |

**Deviations from this plan (step 3):** the order stays locked while its invoice is `issued` (§5.3's
«editable … OR in journal» moves to step 5 with `flagReissue()` — without the flag an edit would
silently diverge); the list's columns «Rev.» and «Herkunft» are left out (revision 1 everywhere until
step 4; the opaque origin is the document's business, the subject shows the order); the confirmation's
preview is summed per account, not listed per document; the VAT-liability and revenue-account checks
stay at «Fakturieren» (a document that could not be posted is not issued) — `post()` relies on the
ledger's own refusal for anything changed since. **Tests:** `tests/module-debtor.php` sections L, M7,
N1, P3C (P3C38–P3C41d, P3C57), P3E, Z (Z5–Z12b), O (O2, O5, O6), BM (BM2–BM10) rewritten for the new
model, FJ2 / FJ4 adapted, the new block FJ9–FJ34 (`--from=FJ`); `tests/pdf.php` K1–K3 (PdfConcat);
`tests/module-order.php` M16b, N6, H2, F17; `tests/navigation-seeds.php` NSEED-2g, NSEED-7e.

## Steps 4–8 as built (2026-10-24)

The binding decisions are ADR-054 «Addendum 2026-10-24 — steps 4–8 as built» (1–9). Where the code is:

| Step | Part | Where |
|---|---|---|
| 4 | The decision rule | `Invoicing\Correction` (pure), `InvoicingService::classify()` |
| 4 | Revision / replacement | `InvoicingService::revise()` (`writeRevision()`, `writeReplacement()`, `lockCorrectable()`, `assertCorrectable()`), `Invoice::revise()` / `restamp()` / `replaceBy()` / `markDeleted()` / `flagReissue()` |
| 4 | Archive of every version | `Pdf\InvoiceArchive::pdfOfRevision()`, `revisionsOf()`; the note «ersetzt Rechnung …» in `pdf/invoice` (`InvoicePdf` → `InvoiceRepository::predecessorOf()`) |
| 4 | The mask | `Ui\InvoiceControllerTrait::editAction()` → `invoiceCorrect()` (band + `op=confirm`), `pdfAction()` `&revision=`, `form.tpl.php`, `_facts.tpl.php`, `form.help`; `InvoiceForm::fromPost(partyFixed:)`, `withContact()` |
| 4 | Tabs | `InvoiceSearch::VIEW_REPLACED` / `VIEW_DELETED`, `InvoiceRepository::searchWhere()` |
| 5 | Orders editable, flag, «Neu fakturieren» | `OrderService::assertEditable()`, `flagInvoice()`, `reinvoice()`, `reinvoicePreview()`; `InvoicingService::flagReissue()`; `SalesOrder::relinkInvoice()`; `OrderControllerTrait::confirmReinvoiceAction()` / `reinvoiceAction()`, `confirmReinvoice.tpl.php`, the order window's band and button |
| 6 | Delete | `OrderService::delete()` / `deleteRefusal()`, `SalesOrder::markDeleted()`, migration `Version20261024100000` (order), `OrderSearch::$deleted`, tab «Gelöscht»; `InvoicingService::delete()`, debtor's `confirmDeleteAction()` + `confirmDelete.tpl.php`; order's `confirmDeleteAction()` + `confirmDelete.tpl.php`; log triggers `reinvoice` / `delete` |
| 7 | CAMT chain | `BankImportService::routeVoid()`, `assign()` refusal, `BankTransaction::suggest()`, `InvoiceRepository::chainEnd()`, `transactionRow.tpl.php` («Zuordnen zu …») |
| 8 | Mails | `InvoiceMails::attachmentOf()` / `mailKindFor()` / `cancelWaiting()` / `isCovered(…, $document)`, `InvoicePdfAttachment` (`<id>@<revision>`), kind `invoice-replacement` in `debtorConfig`, module-mailing `OutboxMailRepository::deliveriesOf()` (+ `attachments`) |

**Deviations from this plan:** no payment move (O6 made it obsolete); the confirmation of a manual
correction is a band in the same mask, not a separate window; «Neu fakturieren» keeps the issued invoice's
date (no date asked); the payment reference TYPE counts as a replacement reason; the board on the developer
canvas waived (owner). **Tests:** `tests/module-debtor.php` FJ40–FJ56 (step 4 + the manual deletion),
FJ60–FJ69 (steps 5–6), FJ70–FJ77 (steps 7–8), FJ6 / FJ18 / BM13 adapted; the FJ block migrates first (a
checkpoint saved before step 6's migration).

## 14. Open questions for the owner

**Decided by the owner 2026-10-23** — ADR-054 «Owner decisions on the open questions» (O1–O13; what
they change: §0). Q1 → O1, Q2 → O2, Q3 → O3, Q4 → O13 (archive every version as a file), Q5 → O4,
Q6 → O5, Q8 → O7, Q9 → O10 («Fakturieren», step 3), Q10 → O8, Q11 → O9 (ANY deviation blocks),
Q12 → O11 (the owner is the fiduciary; the replacement note). **Q7** stays decided provisionally by
the recommendation (owner may revise). The questions as they were asked:

1. **Year close «journal empty»** — only documents dated in the closing year (and earlier) block,
   or literally every document in the journal (also next year's)? Recommendation: dated in the
   year.
2. **Dunning fees** — issued and posted at once by the dunning run, outside the journal?
   Recommendation: yes.
3. **Discount and loss** on an unposted (`issued`) invoice — refuse until posted (they correct VAT
   by the document's tax rows, which a replacement may change)? Plain payments stay allowed.
   Recommendation: refuse.
4. **Revision archive** — archive the PDF on EVERY revision, or only after the document was sent
   by mail? Recommendation: every revision (paper leaves no trace in the system).
5. **Customer change** on an issued invoice — a replacement (new number, the QR reference carries
   the customer number)? Recommendation: yes.
6. **Order deletion** — keep the order as a row with state «gelöscht» (number kept, hidden), or
   delete it for real? Recommendation: keep with state (D8's reason: the order number was on the
   quote).
7. **Manual invoice in the journal** — also deletable («Rechnung löschen …» → «gelöscht»), not only
   through its order? Recommendation: yes, with a mandatory reason; refused while payments exist.
8. **«Journal verbuchen»** — always the whole journal, or also a selection / «bis Datum»?
   Recommendation: whole journal first.
9. **Wording** — «Buchungsdruck …» no longer books: rename to «Fakturieren …»? Drafts «Entwurf»
   instead of «In Fakturierung»?
10. **Replacement mail** — its own mail kind («Diese Rechnung ersetzt Rechnung A …») under Service ›
    E-Mail › Beleg-Mails? Recommendation: yes.
11. **Posting-relevant change without total / VAT change** (a revenue account, the date within the
    month) on an order — blocks the journal until «Neu fakturieren» (same number, new revision)?
    Recommendation: yes (else the ledger books what the order no longer says).
12. **Fiduciary** — the legal notes of ADR-054 (archiving, MWSTG Art. 40 timing of a replacement in
    a later quarter, «same number, new revision» after sending, the successor printing «ersetzt
    Rechnung A») to be confirmed before real books use it.
