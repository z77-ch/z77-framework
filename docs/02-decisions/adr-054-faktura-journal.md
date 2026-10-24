# ADR-054 — The Faktura-Journal: invoices are issued into a staging journal, posted later

**Status:** `[APPROVED]` — accepted by the owner 2026-10-23 (rules 1–13 below, «Owner decisions»; rule 12
CHANGED by the owner the same day). The open questions of the build plan (§14) are **decided by the
owner 2026-10-23** («Owner decisions on the open questions» below — they replace the provisional
answers recorded earlier that day); only Q7 (deleting a manual document) stays decided provisionally
by the plan's recommendation. Build: steps 1–2 of
[`faktura-journal-bauplan.md`](../03-development/faktura-journal-bauplan.md) built and committed
2026-10-23 (schema, predicates — no behaviour change); **step 3 (the journal core) built 2026-10-23 and
committed (`63cbb4b`)** — «Addendum 2026-10-23 — step 3 as built»; **steps 4–8 built 2026-10-24, not committed** — «Addendum 2026-10-24 — steps 4–8 as built» at the end; step 9 not built.
**Date:** 2026-10-23
**Amends:** [ADR-040](adr-040-business-module-cut.md) (decisions 3, 7, consequence «a payment
arriving for it stays unmatched», addendum 2026-10-14 point 1),
[ADR-041](adr-041-vat-model.md) (decision 6, as to WHEN a snapshot is final),
[ADR-042](adr-042-ledger-and-money.md) (decision 11, what debtor blocks on),
[ADR-043](adr-043-order-status-and-stock-movements.md) (addendum 2026-10-15 point 7, addendum
2026-10-20 second decision, and the P7 plan's D7 / D8),
[ADR-053](adr-053-mail-outbox-and-attachment-providers.md) (addendum 2026-10-22 point 1, «a FINAL
document only»). Supersedes none of them as a whole.
**Builds on:** the open-work registry (ADR-039 decision 15), the source-correction path
`LedgerService::amend()` (ADR-042 addendum 2026-10-06), the booking print (debtor.md «The booking
print»), `invoiceAndFinalize()` (ADR-040 addendum 2026-10-14).

---

## Context

Since 2026-10-10 the booking print («Buchungsdruck …», `InvoicingService::finalize()`; for an order
`OrderService::book()` → `InvoicingService::invoiceAndFinalize()`) does three things in ONE unit of
work: it draws the document number `YYMMnnnn`, it prints the document, and it POSTS the journal
entry into module-financial. From that moment the document is `final` — immutable, every
correction is a credit note plus a new invoice, and the order behind it is locked
(`OrderStatuses::INVOICED`, ADR-043 addendum 2026-10-20).

In practice errors are noticed AFTER printing — the customer calls, a line is missing, a price is
wrong. The office then issues a credit note, a new invoice and handles two extra documents and two
extra journal entries for a typo. That is the «delete-and-repost noise» ADR-040 set out to avoid,
only now in the form of credit notes.

The owner decided on 2026-10-23 to split the booking print: the document is ISSUED (numbered,
printed, sent, payable) at once, but POSTED into the ledger only when the office has reviewed a
batch — the **Faktura-Journal**.

## Owner decisions 2026-10-23 (Peter)

1. The booking print does **not** post into the financial ledger. It puts the invoice into the
   **Faktura-Journal**, a staging batch. The journal holds the **invoices themselves** (state
   «fakturiert, nicht verbucht») — **no stored booking records**; the ledger entry is created only
   when the journal is posted («keine Buchungssätze»).
2. An order (and its invoice) can be changed as long as the invoice is in the Faktura-Journal.
3. If the invoice **total** or the **VAT** changes, the order must be **re-invoiced**; the journal
   cannot be posted until that is done (blocked).
4. A re-print **replaces** the invoice: the old one gets the state «ersetzt» (kept, with its PDF, a
   link to its successor, who / when) and the new invoice is created. Replaced and deleted invoices
   stay in the database with their state — easy to hide and filter (a state, not a log file).
5. Deleting an order (new feature) moves its invoice to the state «gelöscht» (kept, protocolled).
6. The Faktura-Journal shows all invoices of the batch; a journal list can be printed.
7. After review the journal is posted (creates the ledger entries) → state «verbucht» → immutable as
   today; later corrections = credit note as today.
8. A new invoice with a changed total or VAT gets a **new number**; the old number stays on the
   replaced invoice (protocolled — it explains the «gap»).
9. A new invoice with a changed date gets a new number if the **month** differs (the numbering is
   per month).
10. The year close only when the Faktura-Journal is empty. Accepted in the discussion: the VAT
    settlement of a quarter only when no invoice of that quarter is still in the journal; reports
    show a hint «n Rechnungen im Faktura-Journal nicht verbucht»; unposted invoices are not dunned.
11. Same total and same VAT but other changes (text) after sending: **same number, new version** of
    the document; the sent version's PDF is archived (the customer holds that document).
12. ~~Payments do **not** lock an invoice. A payment is posted to the ledger at once (bank /
    debtors) even while its invoice is still unposted (the debtors account may be in credit
    temporarily — correct). When an invoice with payments is replaced, its payments **move** to the
    successor (open = new total − paid).~~ **Changed by the owner 2026-10-23 (O6 below):** an issued,
    unposted invoice has NO debtor ledger entry yet and CANNOT be paid — payments attach to POSTED
    invoices only. A payment arriving for an issued invoice is not booked by the import; it waits
    in the manual payments («Rechnung noch nicht verbucht») until the journal is posted. So nothing
    is ever attached to a document while it can still be replaced — the «move to the successor»
    is obsolete.
13. Payment import (QR / camt): a reference pointing to a **replaced** invoice is NOT booked
    automatically; it lands in the manual payments with the successor **pre-selected** and the hint
    «Referenz von A — ersetzt durch B» (the chain A → B → C is followed); one click confirms.

## Owner decisions on the open questions (2026-10-23)

The owner (Peter) answered the build plan's open questions (§14) on 2026-10-23, after a first round
the same day had recorded the plan's recommendations as provisional. These are binding; the
«Decision» section reads with them, and where a decision there says otherwise, this list wins.

| # | Plan Q | Owner decision |
|---|---|---|
| O1 | Q1 | The year close is blocked while invoices CONCERNING the closing year (invoice date in that year) are not posted. An `issued` document dated in the next year does not block (decision 17). |
| O2 | Q2 | Dunning fees are issued and posted at once by the dunning run, as today — outside the journal (decision 2). |
| O3 | Q3 | Discount (Skonto) and loss only on POSTED invoices (decision 15). |
| O4 | Q5 | A customer change on an issued invoice is a replacement with a NEW number (decision 8). |
| O5 | Q6 | A deleted order is kept with the status «gelöscht»; its stock effects are reversed / void (decision 14). |
| O6 | — (rule 12) | **Rule 12 changed:** an issued (unposted) invoice has no debtor ledger entry and cannot be paid; payments attach only to posted invoices. A payment for an issued invoice waits in the manual payments («Rechnung noch nicht verbucht») until the journal is posted. The payment move on replacement is obsolete. The chain suggestion for a REPLACED invoice's reference (rule 13) stays (decisions 10, 15, 16). |
| O7 | Q8 | «Journal verbuchen» always posts the whole journal (decision 6). |
| O8 | Q10 | Own mail kind for a replacement («ersetzt Rechnung A») — yes (decision 21). |
| O9 | Q11 | ANY deviation of a source from its issued invoice keeps the journal blocked until it is re-invoiced (re-printed) — not only total / VAT (decision 9). |
| O10 | Q9 | «Buchungsdruck» is renamed «Fakturieren» (UI wording) — done in step 3, not before. |
| O11 | Q12 | The owner IS the fiduciary. A re-issued invoice with changed content MUST refer to the invoice it replaces — printed small on the PDF, generated automatically, no free text: «ersetzt Rechnung 2610003 (ID 7557577x)» (decision 23). The other legal notes below are the owner's to judge. |
| O12 | — (new) | Every generated invoice version gets a unique STAMP (a short hash ID), printed small under / next to the invoice number; it is the version's unique ID and the replacement note cites it. Computed over the document CONTENT of that version (number, date, recipient, lines, totals, VAT, revision), NOT over the PDF file — a PDF cannot contain its own file hash (decision 24). |
| O13 | Q4 (replaced) | **Archive:** every issued invoice version stores its PDF as a FILE at the moment it is invoiced (Fakturieren / re-invoice); a reprint delivers that stored file, never a re-rendered one (decision 25). |
| — | Q7 | Still decided provisionally by the plan's recommendation (owner may revise): a manual document in the journal is deletable with a mandatory reason. |

## Decision

### States of a document

1. **Five states** of `invoice.state` (`InvoiceState`; stored value → screen word):

   | State | Screen | Number | Ledger | Editable | Open amount / payments |
   |---|---|---|---|---|---|
   | `invoicing` | «Entwurf» (today «In Fakturierung»; the drafts' word is not part of O10) | none | nothing | yes (`reinvoice()`, `discard()`) | none |
   | `issued` | «Fakturiert» — in the Faktura-Journal | drawn | nothing | yes, by REVISION (below) | none yet — not payable before the posting (O6) |
   | `final` | «Verbucht» | kept | posted once | no — credit note | exists |
   | `replaced` | «Ersetzt» | kept (documents the gap) | nothing, ever | no | none — moved to the successor |
   | `deleted` | «Gelöscht» | kept | nothing, ever | no | none |

   Transitions, one way each: `invoicing` → `issued` (the booking print), `issued` → `final` (the
   journal posting), `issued` → `replaced` (a replacement), `issued` → `deleted` (an order deleted,
   or a manual document deleted — the latter decided provisionally 2026-10-23, Q7). `final`, `replaced` and `deleted` are end
   states. The stored value `final` keeps its name (a rename of DB values buys nothing — the
   precedent of 2026-10-10). The word `issued` is chosen over `invoiced` so it cannot be misread
   next to `invoicing`.
2. **Every kind takes this path** — invoice and credit note. **Exception (owner decision O2):** the dunning
   fee document is issued AND posted in one step by the dunning run as today
   (`invoice()` + `finalize()` joined), because it derives from a POSTED invoice and has no editing
   use case; it never enters the journal.
3. **A revision is not a state.** An `issued` document carries `revision` (1, 2, …). A revision
   re-snapshots the SAME row under the SAME number; the PDF of the revision it replaces is archived
   (decision 11).

### The booking print and the journal (rules 1, 6, 7)

4. **The booking print issues, it does not post.** «Buchungsdruck …» (the name is decided O10: «Fakturieren …» from step 3; it no
   longer books) draws the numbers exactly as today (one series `document.YYMM`, invoice date then
   id, gapless, the payment part taken again and checked, the QR reference from customer number and
   document number) and sets `issued`. **No `AccountingGateway::post()` call.** The order's booking
   print calls `invoiceAndIssue()` (today `invoiceAndFinalize()`), which issues without posting;
   the order is linked and moved to its booked status as today.
5. **The Faktura-Journal is the set of `issued` documents** — a query, not a table of booking
   records. What WILL be posted is computed on demand by the existing `PostingBuilder` from the
   current revision (the journal list's preview, the posting itself); nothing of it is stored
   before the posting.
6. **«Journal verbuchen»** posts the journal in ONE unit of work, all or nothing: every `issued`
   document locked by id, the blockers judged under the lock (decision 9), then per document, in
   number order, `PostingBuilder` → `AccountingGateway::post()` → `final`. The posting date is the
   INVOICE date (as today), the idempotency key `{kind}:{id}:final` unchanged. A light protocol row
   `invoice_journal_posting` (who, when, count, totals) is written and each posted document names
   it — so a posted batch's journal list can be reprinted. This row is a protocol, not a booking
   record. Whole journal at once; a partial posting (a selection, «bis Datum») is not offered (owner decision O7).
7. **The journal list** (PDF, «Journalliste»): every document of the journal (or of one posting
   protocol) with number, date, customer, net, VAT per code, gross, state hints (blocked,
   revision n), and the totals per revenue account and per VAT code as `PostingBuilder` would post
   them.

### Corrections while in the journal (rules 2, 3, 4, 8, 9, 11)

8. **One decision rule — replacement or revision** (the server decides, the screen says which
   before the user confirms). Comparing the new composition with the `issued` document:

   | Changed | Result |
   |---|---|
   | gross total, OR the VAT (the set of `{tax code, rate, base, tax}` of `invoice_tax`, or VAT / no VAT) | **replacement** — new number (rule 3, 8) |
   | the MONTH of the invoice date | **replacement** — new number (rule 9) |
   | the party (contact) — the customer number is part of the QR reference | **replacement** (owner decision O4) |
   | anything else: texts, subject, date within the month, terms / due date, payment target, address, revenue account of a line, line split at equal totals | **revision** — same number, `revision + 1` (rule 11) |
   | nothing | nothing written |

9. **Blocked journal (rule 3).** The journal cannot be posted while a document in it is
   **out of date with its source**: its source (an order) changed in a way the decision-rule table
   above would act on, and «Neu fakturieren» has not been done. The SOURCE flags it through a debtor
   seam (`invoice.reissue_needed`, the reason as text) — debtor never names the source (ADR-040
   decision 1). **Owner decision O9:** ANY deviation blocks — also one that leaves total and VAT equal (a
   revenue account, the invoice date, a text): the journal stays blocked until the document is
   re-invoiced (re-printed), so what is posted and what the customer holds always match the order.
10. **The replacement (rule 4, 8).** In ONE unit of work: the successor is created `issued`
    with a NEW number from the month of ITS invoice date; the predecessor goes `replaced` with
    `replaced_by` (successor), who, when, the reason. No payment moves (O6 — an issued document
    carries none). The successor prints the replacement note (decision 23). The predecessor's row,
    lines, tax rows and payment part are never touched again; its archived PDF (decision 25) is
    what was issued.
11. **The revision (rule 11).** A revision keeps the number, `revision + 1`, gets a new stamp
    (decision 24) and its PDF is archived as a new file (decision 25) — EVERY version is archived
    at the moment it is issued, so the paper the customer may hold always exists as a file.
12. **Who triggers it.** A manual document: its mask stays editable while `issued`; «Speichern»
    answers with the decision (revision / replacement, the new number named) and a confirmation.
    An order: the order stays editable while its invoice is `issued`; its save flags the invoice
    (decision 9); «Neu fakturieren» in the order window (and as the blocker's link in the journal)
    builds the draft from the order (`OrderInvoiceDraft`) and hands it to debtor, which applies
    decision 8 and answers the document — the order re-links to a successor itself.
13. **A credit note** is issued only against a `final` invoice (as today against `final`): a
    document still in the journal is corrected by revision or replacement, not by a credit note.

### Deletion (rule 5)

14. **Deleting an order** whose invoice is `issued` sets the invoice `deleted` (who, when, reason;
    number kept), in the same unit of work as the order's deletion; the order's stock effects are reversed / void (O5) — brought
    to zero through the one stock call (a reversal of consumed stock asks for its reason, ADR-043
    decision 14). An order whose invoice is `final` is NOT deletable (credit note). (An issued document carries no payment allocations — O6.) The ORDER itself is kept with a
    state «gelöscht» (owner decision O5 — D8's reason: the order number was
    printed on quotes; same principle as rule 4).

### Payments (rules 12, 13)

15. **Payments only on POSTED documents** (O3, O6): payments, discount and loss are recorded on
    `final` documents only. An `issued` document has no debtor ledger entry yet and no open amount;
    a payment for it is refused (`PaymentRefusedException::NOT_POSTED`, built in step 2) and waits
    in the manual payments until the journal is posted.
16. **The import follows the chain.** A QRR reference (or a NON message) naming a `replaced`
    document is never matched automatically: the transaction stays `unmatched`, carries the LAST
    successor of the chain A → B → C as its pre-selected document and the note «Referenz von A —
    ersetzt durch C», and one click («Zuordnen») confirms. A reference naming a `deleted`
    document stays `unmatched` with «Rechnung A gelöscht». A reference naming an `issued`
    document is NOT booked (O6): the transaction stays `unmatched` with «Rechnung noch nicht
    verbucht» and is assigned by hand once the journal is posted.

### Locks and hints (rule 10)

17. **Year close:** `Close\InvoicingInProgressCheck` blocks on every draft AND every `issued`
    document dated in the year — the reading of «only when the journal is empty» for that year;
    an `issued` document dated in the NEXT year does not block (owner decision O1 — «invoices
    concerning the closing year»).
18. **VAT settlement of a quarter:** a check registered under a new open-work scope (`vat-return`,
    its parameters `from` / `to` — financial owns the scope when it builds the VAT return, P5
    part 2) blocks while an `issued` document with an invoice date in the quarter exists. Until
    the VAT return exists the rule is documented, not enforced.
19. **Reports:** financial's report pages ask the open-work scope `ledger-report` (`from` / `to`);
    debtor answers a WARNING «n Rechnungen im Faktura-Journal nicht verbucht (Total …)» for the
    `issued` documents dated in the range. Financial knows no module (ADR-040 decision 2).
20. **Dunning:** only `final` documents are dunned (the due list reads `final` only — already
    today).

### Mails (ADR-053)

21. An `issued` document is mailable (`InvoicePdfAttachment::accepts()` takes `issued` and
    `final`). The attachment reference names the REVISION (`invoice-pdf:<id>@<revision>`), so a
    mail queued before a revision sends what it was queued with (from the archive). A replacement
    CANCELS the predecessor's waiting mails (`queued`; never `sent`) and offers the successor for
    sending; «covered» (already sent) is judged per revision and per document — a new revision or a
    successor counts as unsent.

### Existing data

### Replacement note, stamp and archive (owner decisions O11–O13)

23. **The replacement note** (O11): a successor prints, small and generated (no free text),
    «ersetzt Rechnung <number of A> (ID <stamp of A's last version>)». Its mail uses the own kind of
    O8.
24. **The document stamp** (O12): every issued version gets a stamp — the first characters of the
    SHA-256 over a canonical serialisation of the version's CONTENT (number, invoice date,
    recipient snapshot, lines, totals, tax rows, revision), never over the PDF bytes. Stored on the
    document (`invoice.document_stamp`, the current version) and per version
    (`invoice_revision.document_stamp`, unique); printed small next to the number; cited by the
    replacement note. The serialisation and the length (column 10 characters) are fixed in step 3.
25. **The archive** (O13): the PDF of every issued version is rendered ONCE when it is issued and
    stored as a FILE; reprints, mails and downloads deliver that file. Where: **under the
    installation's `data/` — `data/archive/debtor/<YYYY>/…`** (decided by the agent 2026-10-23 per
    the existing conventions, owner may revise): non-public, in the release's shared `data` store
    (`.releases/target.json` → `shared`) and in the «Daten» backup (`backup.md`: the whole `data/`
    tree); the module stays independent of module-dms (no seam needed for one writer).
    `invoice_revision` records each version: stamp, `file_path` (relative to the installation),
    `file_sha256`, `file_name`, who, when, why. Built in steps 3 / 4.

22. Every document already `final` stays `final` («verbucht») — nothing is re-opened. Drafts stay
    `invoicing`. No document becomes `issued` by migration; `revision` starts at 1 for every row.

## Reasoning

- **Correct while it is cheap.** Before the posting nothing in the ledger has to be reversed; a
  revision or a replacement costs one confirmation instead of a credit note, a new invoice and two
  journal entries.
- **The invoice IS the journal entry-to-be.** Storing booking records in a staging table would be a
  second truth about one amount (ADR-040 «asking instead of pushing keeps one truth»); `PostingBuilder`
  already derives the posting deterministically from the snapshot.
- **A state, not a log** (owner): the replaced and the deleted document stay rows with their number,
  their snapshot and their PDF — the number series has no hole, the reason for every number is one
  query away, and a list filter hides them.
- **New number on total / VAT / month**: the QR reference carries the number, the VAT return groups
  by period, and a customer holding two papers with one number and two amounts is the one case an
  auditor and a payer cannot resolve. Equal amount and VAT: the number may stay — the customer pays
  the same amount under the same reference.
- **Payments wait for the posting** (O6, changed 2026-10-23): an issued document has no debtor
  entry, so nothing can be attached to it; an early payer's money waits in the manual payments,
  and a replacement never has to move a payment.
- **Locks before the VAT return and the close** keep an issued-but-unposted turnover out of no
  return (see «Legal notes»).

## Consequences

- `InvoiceState` gets three cases; every reader of `isFinal()` / `InvoiceState::Final` (about 45
  places in debtor, order and their templates, SQL included — list in the build plan §3) is sorted into «issued or final» (customer-facing:
  payable, mailable, open amount, PDF with number) and «final» (posted: ledger reference, credit
  note target, dunning, reconciliation).
- `InvoicingService::finalize()` splits: `issue()` (the booking print) and the journal posting
  (`Services\JournalPostingService`); the dunning run keeps issue-and-post for the fee.
- module-order: the order stays editable while its invoice is `issued`, gains «Neu fakturieren» and
  «Auftrag löschen»; debtor still never names order.
- New schema in debtor (columns on `invoice`, tables `invoice_revision`, `invoice_journal_posting`,
  a column on `bank_transaction`) and in order (the deletion stamp, if decision 14 keeps a state).
- The ledger sees an invoice later than the customer does; a payment for it waits in the manual
  payments until the posting (O6) — the receivable account never stands in credit for it. `Close\ReceivablesReconciliation` is unaffected (it counts posted documents
  and posted allocations on both sides).
- The harness sections that expect a posting at the booking print (L, P3E, Z, O, BM in
  `tests/module-debtor.php`; I, M in `tests/module-order.php`) are rewritten.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Status quo — post at the booking print, correct by credit note and re-invoice | The owner's finding: two extra documents and two journal entries per typo after printing |
| Store booking records in the journal (a staging journal of postings) | A second truth next to the snapshot; must be rebuilt on every revision; the owner: «keine Buchungssätze» |
| Edit the issued document in place under the same number, any change | A changed amount under an unchanged number and QR reference — the customer's paper and the books disagree (rules 3, 8) |
| Delete a replaced invoice (and its number) | A hole in a gapless series with no explanation; GeBüV traceability (below) |
| A log file of replacements instead of states | Owner: a state is filterable and is the record itself |
| Lock an invoice once a payment exists | Every early payer would force a credit note again (rule 12) |
| Post a payment on an issued document at once and move it on replacement (the first rule 12) | Owner 2026-10-23 (O6): no debtor entry before the posting; payments wait for it |
| Re-render the PDF of a version on reprint | Owner 2026-10-23 (O13): the stored file is the record |

## Legal notes — assumptions to verify with the fiduciary

None of these is legal advice; each is the agent's reading. **The owner is the fiduciary (O11,
2026-10-23)** — he decided note 4 (the successor prints «ersetzt Rechnung A (ID …)», decision 23);
the others are his to confirm before the build is used for real books.

1. **Archiving (OR 957 ff., OR 958f, GeBüV).** Assumed: an issued document — also a replaced or
   deleted one — is a business record to be kept 10 years, unaltered and traceable (GeBüV Art. 3,
   integrity; Art. 4, documentation of the procedure). The states `replaced` / `deleted` with
   who / when / why / successor, the frozen snapshot and the archived PDF of every revision are
   meant to satisfy this. This ADR and the build plan are part of the procedure documentation.
2. **Timely recording (OR 957a).** Assumed: a document recorded in the debtor subledger with its
   number and posted to the general ledger after a review batch is still «recorded»; the journal
   should be posted at least before each VAT return and each month-end report the books are used
   for. To verify: the acceptable delay.
3. **VAT — agreed consideration (MWSTG Art. 40).** Assumed: with «vereinbartes Entgelt» the tax
   claim arises with the invoice (Rechnungsstellung), i.e. at `issued`, not at the posting — hence
   decision 18. To verify: whether a replacement issued in a LATER quarter with a later date moves
   the turnover to that quarter, or whether the original's quarter stays decisive.
4. **Invoice correction (MWSTG Art. 26 / Art. 27, ESTV MWST-Info 16 «Buchführung und
   Rechnungsstellung»).** Assumed: replacing an invoice that the customer may already hold is a
   correction that must be traceable to the original (successor link, «ersetzt Rechnung A» on the
   new document and in its mail). To verify: whether the successor must print a reference to the
   replaced number, and whether the customer must be informed of the cancellation of A.
5. **Same number, new revision (rule 11).** Risk: two different papers under one number at the
   customer. Assumed acceptable while total and VAT are equal (tax-neutral). To verify with the
   fiduciary before it is used.
6. **Gap documentation.** Assumed: the replaced / deleted document with its kept number IS the gap
   documentation; no number is ever re-used (DOCTRINE-NR).

## Interaction with existing ADRs — explicit

| ADR | What changes |
|---|---|
| ADR-040 decision 3 | «An invoice is posted on `finalize()`» → posted when the Faktura-Journal is posted; issued (numbered, printed, payable) before. «Nothing is posted before the booking print» holds a fortiori. |
| ADR-040 decision 7 | «invoice, open item and posting on `finalize()` commit together» → the issue commits number, document and open item; the posting commits with the journal posting. One use case, one transaction still holds for each. |
| ADR-040 consequence | «a payment arriving for it stays unmatched until `finalize()`» → holds, read «until the journal posting» (rule 12 as changed by O6): a payment for an `issued` document waits in the manual payments. |
| ADR-040 addendum 2026-10-14 point 1 | «issue AND book in one unit of work» → issue in one unit of work (`invoiceAndIssue()`); the booking follows with the journal. |
| ADR-041 decision 6 | «can never alter an issued document» → «a POSTED document»; an `issued` document is re-snapshotted only by a revision (archived PDF) or replaced by a successor — never recomputed silently. |
| ADR-042 decision 8 | Unchanged in substance: `replaced` / `deleted` apply only to documents NOTHING was posted for; a posted document is still corrected by its counterpart. |
| ADR-042 decision 11 | «debtor blocks on invoices still `invoicing`» → on drafts AND `issued` documents (year close; VAT return per quarter). |
| ADR-043 add. 2026-10-15 point 7, add. 2026-10-20, P7 plan D7 | «editable while it has no invoice» → while it has no invoice OR its invoice is `issued`; «with a booked invoice nothing by hand» reads «with a POSTED (`final`) invoice». |
| P7 plan D8 | «No delete of an order» → an order without a posted invoice can be deleted (decision 14). |
| ADR-053 addendum 2026-10-22 point 1 | «`accepts()` a FINAL document only» → `issued` or `final`; the attachment names the revision (decision 21). |

## Addendum 2026-10-23 — step 3 as built (the journal core)

Built by the agent on the owner's go of 2026-10-23 («build step 3 directly on the known patterns, no
canvas board»). What was decided while building — each binding until the owner revises it:

1. **«Fakturieren» = `InvoicingService::issue()`** (the manual drafts) and **`invoiceAndIssue()`** (an
   order's, formerly `invoiceAndFinalize()` — renamed, no alias): ranges → rows → numbers → per
   document its STAMP, `Invoice::markIssued()` (`issued`, `issued_by` / `issued_at`), its PDF rendered
   ONCE and archived with its `invoice_revision` row (revision 1) — one unit of work, NO
   `AccountingGateway::post()`. `finalize()` stays for ONE caller, the dunning fee (decision 2): the same
   issue, then the post in the same unit of work (`Invoice::post()` with no protocol). The entity's
   former `finalize()` is gone: `markIssued()` (draft → issued) and `post()` (issued → final) are the
   two state changes.
2. **The stamp (decision 24, O12) — fixed:** the first **10 hex digits** (lower case) of
   **SHA-256 over the canonical JSON** of the version (`Invoicing\DocumentStamp`):
   `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, no whitespace, keys in this order — `v` (format
   «1»), `kind`, `number`, `revision`, `invoice_date`, `service_from`, `service_to`, `due_date`,
   `currency`, `price_mode`, `recipient` (contact id, customer number, salutation, title, first name,
   name, address row, street, house number, zip, city, country), `subject_label`, `subject`,
   `credit_note_of` (its number), `lines` (per line: position, parent position, type, code, text,
   quantity, unit, unit price, discount, amount, tax code, tax rate, revenue account), `totals` (net,
   tax, rounding, gross), `taxes` (per row: code, rate, base, tax), `payment` (account, reference
   type, reference); money as decimal strings, dates `Y-m-d`, absent `null`. Collision-checked against
   every archived version (and the batch) before the unique index `uniq_invoice_revision_stamp` is the
   final word: on a collision `,"salt":n` (n = 1, 2, …) is appended to the JSON and hashed again.
   Printed small at the right edge of the title line: «ID 1235101ab7» (from revision 2 on «· Version n»).
   A document posted before the journal has no stamp (`NULL`) and no archive.
3. **The archive (decision 25, O13) — as built:** `Pdf\InvoiceArchive`, file
   `data/archive/debtor/<YYYY>/<number>-r<revision>-<stamp>.pdf` (`<YYYY>` = the invoice date's year),
   written INSIDE the unit of work before the commit; a failure to write refuses the whole batch
   (nothing issued). A rollback after the write removes the files the call wrote when the call owns the
   unit of work; `OrderService::book()` (which joins) removes them through
   `InvoicingService::discardArchived()`. Reading: `InvoiceArchive::pdf()` — a draft renders its
   preview live; an issued version delivers its stored file, the SHA-256 checked against the row; a
   missing or altered file is an `InvoiceArchiveException` with a German sentence (logged), never a
   re-render; a document posted before the journal (no revision row) still renders from its snapshot.
   The single PDF, the ZIP, the mail attachment `invoice-pdf` and «Alle als ein PDF» read it — the
   latter JOINS the archived files page for page with the kernel's new `Z77\Shared\Pdf\PdfConcat`
   (FPDF files only; content streams untouched, objects renumbered, a page keeps its own MediaBox).
4. **«Journal verbuchen» = `Services\JournalPostingService::post(list<{id, version}>)`:** the rows
   locked by id, every document still `issued` at the version the confirmation showed, NO other
   document in the journal since (`changed` — always the whole journal, O7), no `reissue_needed`
   (`blocked`), an empty journal refused (`empty`); then ONE protocol row (`document_count`,
   `first_number` / `last_number`, `gross_total` SIGNED — a credit note counts negative, `posted_by` /
   `posted_at`) and per document in number order `PostingBuilder` → `post()` → `final` with
   `posted_by` / `posted_at` / `journal_posting_id`. A refusal of the bookkeeping rolls back the whole
   posting (proven with a document dated in a year without fiscal year). The confirmation's preview
   («Was gebucht wird», summed per account) is computed by the same `PostingBuilder`.
5. **The screen:** «Auftragswesen › Faktura-Journal», `/backend/finance/invoice-journal/list` (host
   `Finance/InvoiceJournalController`, fragment `Ui\InvoiceJournalControllerTrait`, module role ADMIN
   like every debtor screen — no own access rule). Action cell «Journal verbuchen …» (green confirm;
   disabled with its reason while empty or blocked); toolbar tabs «Im Journal» · «Verbuchte Journale»,
   tool «Journal drucken»; the pending band (count, total, oldest date) or the red blocker band; a
   standard list (state icon → the document's mask as a window, Nr. · Datum · Kunde · Betreff · Netto ·
   MWST · Total · ID · Fakturiert · Hinweis «Neu fakturieren nötig»), under it the totals per VAT code
   and in all; the confirmation a PAGE (`confirm-post`, `post` `#[Csrf]`); the journal list PDF
   (`Pdf\JournalPdf`, layout `pdf/invoiceJournal`) for the open journal and for each posted batch
   (`pdf?posting=`). Seed entry `faktura-journal` with sort 2, right after «Rechnungen» (a fresh
   installation; an existing one gets it appended, add-only — ADR-050).
6. **The document list** gains the tab «Fakturiert» (issued invoices; no selection). A credit note is
   refused against an issued invoice («… erst nach «Journal verbuchen»», decision 13).
7. **Wording (O10):** every text the user reads says «Fakturieren» where it said «Buchungsdruck»
   (buttons, titles, help, flashes, refusals, the PDF preview note, the order phase / status labels,
   the year-close finding). **The routes keep their names** (`confirm-finalize`, `finalize`,
   `booking-result`, module-order's `confirm-book` / `book`, the status-log trigger `booking-print`):
   bookmarks, the order screens' links and the harness name them, the user never reads them — a rename
   would buy nothing and break links.
8. **The order stays LOCKED while its invoice is `issued`** — a deviation from build plan §5.3 for
   step 3: the order lock now NAMES the invoice's state (posted: «verbucht … Gutschrift»; issued:
   «fakturiert (im Faktura-Journal, noch nicht verbucht) — er bleibt, wie er fakturiert wurde»), but
   editing while in the journal opens only with step 5 — without its `reissue_needed` flag an edited
   order and its unposted invoice would disagree silently and the journal would post the old content.
   The order's list shows «nicht verbucht» in «Zahlung» while the invoice is in the journal.
9. **The year close (decision 17, O1)** is built: `InvoicingInProgressCheck` blocks on drafts AND issued
   documents dated in the closing year («Rechnung … ist fakturiert, aber nicht verbucht — im
   Faktura-Journal «Journal verbuchen».»); `InvoiceRepository::unpostedBetween()` replaces
   `invoicingBetween()`.
10. **A repeated «Fakturieren» after the journal was posted** is no longer recognised as the repeat (the
    posting moved the version): it answers the refusal page «Bereits fakturiert: …» — nothing written.

## Addendum 2026-10-24 — steps 4–8 as built (corrections, orders, deletion, the CAMT chain, mails)

Built by the agent on the owner's go of 2026-10-24 («build steps 4–8 directly on the known patterns, no
canvas board»). What was decided while building — each binding until the owner revises it:

1. **The decision rule is `Invoicing\Correction::classify()`** (pure; `InvoicingService::classify()` composes
   the draft into a TRANSIENT candidate and compares). REPLACEMENT on: gross, the tax rows (code / rate /
   base / tax) or with / without VAT, the MONTH of the invoice date, the party (O4) — **and the payment
   reference TYPE** (QR reference ↔ none; added: the reference of a revision must not change, so a target
   switch from QR-IBAN to IBAN is a replacement). REVISION on any other snapshot content (lines incl. the
   revenue account, dates within the month, terms / due date, address, subject, payment target / account,
   price mode, language, the no-VAT note). NOT compared: number, revision, stamp, state, who / when, the
   opaque origin, the QR reference / message, the creditor block. Quantities compare numerically
   («2» = «2.000»). The reasons are German phrases («Betrag geändert (600.00 → 640.00)», «Positionen»).
2. **`InvoicingService::revise(id, version, draft, ?expect, ?reason, fromSource)`** writes it: NONE → nothing
   (a source's flag cleared); REVISION → same row, `revision + 1`, the payment part taken again under the same
   reference (message and due date anew), checked by `QrBill`, a new stamp (`Invoice::revise()` +
   `restamp()`), the PDF archived as `<number>-r<n>-<stamp>.pdf` with the reason on its `invoice_revision`
   row; REPLACEMENT → the successor inserted, the predecessor `replaced` (flushed, so the successor's PDF
   finds it), the successor numbered from the month of ITS date, stamped, archived, the predecessor's
   waiting mails cancelled. Lock order: the successor's `document.YYMM` → its profile row SHARED → the
   issued row EXCLUSIVE. `$expect` (the kind the user confirmed) is re-judged: another kind is refused
   `correction-changed`, nothing written (Rule 7). Void reasons: «Ersetzt — <summary>» / the caller's.
3. **The replacement note** (decision 23) prints right-aligned under the stamp, 7.5 pt: «ersetzt Rechnung
   <A> (ID <stamp of A's current version>)» — `InvoicePdf` reads the predecessor through
   `InvoiceRepository::predecessorOf()`. It is NOT part of the stamp's canonical serialisation (format `v`
   stays «1»; the successor's own number and content make its stamp unique).
4. **A document issued from a SOURCE** (`source_type` set — an order) is corrected and deleted only by that
   source (`fromSource: true`); debtor's mask shows it read-only «… ist aus «<subject>» fakturiert — sie
   wird dort geändert …» (`sourced`). A MANUAL issued document's mask is editable: «Speichern» answers the
   same mask with a band naming the classification and one button «Neue Version bestätigen» / «Ersetzen
   bestätigen» (`op=confirm`, `expect`; the posted fields travel along — the form is the confirmation). An
   issued invoice (not a credit note) may change its customer there (the picker; always a replacement).
   Actions of the issued mask: «PDF», «Rechnung löschen …», «Senden …» (the result list of that document).
   The facts block shows «Ersetzt durch» / «Ersetzt» (links both ways), who / when / why, «Neu fakturieren
   nötig», and «Versionen» (each archived revision's PDF: `pdf?id=&revision=n`, file `…-version-n.pdf`).
5. **Tabs «Ersetzt» and «Gelöscht»** (every kind in that state) close the document list; «Gutschriften» no
   longer shows void credit notes. The list's pencil marks what the mask edits (a draft, a manual issued one).
6. **Orders (step 5):** `OrderService::assertEditable()` lets an order whose invoice is `issued` be edited
   whatever its phase; after the save `flagInvoice()` classifies the order against the issued invoice
   (`classify(fromSource)`) and calls `InvoicingService::flagReissue()` (order row → invoice row; posted in
   between → the save is refused) with «Auftrag N geändert — <summary> (<neue Version …|Ersatz …>)», or
   clears it; an order debtor would refuse as it stands is flagged «so nicht fakturierbar: …». The status
   select is not offered while an invoice exists (the status stays). **«Neu fakturieren»** =
   `OrderService::reinvoice(id, version)`: the invoice date stays the issued invoice's; debtor's `revise()`
   joined; a successor re-links the order (`SalesOrder::relinkInvoice()`); the log row `reinvoice` (from =
   to, the reason names what happened); nothing to change and no flag → nothing written. The confirmation
   is a PAGE (`confirm-reinvoice`, like «Fakturieren …»), the answer debtor's result list of the standing
   invoice.
7. **Deleting (step 6):** `OrderService::delete(id, version, reason)` — refused while the invoice is posted;
   its issued invoice `deleted` through `InvoicingService::delete(id, null, reason, fromSource)` (no version
   compared: the order holds its own row lock), the order stamped `deleted_by / deleted_at / delete_reason`
   (migration `Z77\Module\Order\Migrations\Version20261024100000`), log row `delete`, the stock brought to
   ZERO with ONE stock call whose confirmation is the deletion's reason (no second question). Deleted orders
   are hidden from every list (also the year-close warning) and shown in the tab «Gelöscht»; nothing is
   written to them again. A manual issued document is deleted in debtor's mask («Rechnung löschen …»,
   mandatory reason, refused with allocations — Q7 still provisional).
8. **The CAMT chain (step 7):** a QRR or message naming a `replaced` document → `BankTransaction::suggest()`
   — unmatched, `suggested_invoice_id` = `InvoiceRepository::chainEnd()`, note «Referenz von Rechnung A —
   ersetzt durch Rechnung C» (+ «noch nicht verbucht — nach «Journal verbuchen» … bestätigen» while C is
   issued); a `deleted` one (or a chain ending in one) → unmatched «… ist gelöscht — manuell zuordnen». The
   row offers «Zuordnen zu Rechnung C» (one click) and pre-fills the number; `assign()` refuses a void
   document naming the chain's end. `matchTo()` / `unmatch()` clear the suggestion.
9. **Mails (step 8):** the attachment reference is `invoice-pdf:<id>@<revision>` (`InvoiceMails::
   attachmentOf()`); `InvoicePdfAttachment` accepts `<id>` and `<id>@<n>` (n ≤ the current revision) and
   resolves the named revision's archived file — also for a mail already claimed of a document replaced
   since. «Covered» is judged per version (module-mailing's `deliveriesOf()` now answers each row's
   `attachments`). A successor is mailed with the kind `invoice-replacement` (de / en, placeholders
   `{ersetzt}`, `{ersetzt_id}`, `{ersetzt_datum}`; editable under Service › E-Mail › Beleg-Mails). A
   replacement and a deletion cancel the document's `queued` outbox mails in their unit of work
   (`InvoiceMails::cancelWaiting()`); sending / sent mails stay history.
